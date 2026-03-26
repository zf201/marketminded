package handlers

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/search"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/internal/types"
	"github.com/zanfridau/marketminded/web/templates"
)

const antiAIRules = `

## Anti-AI writing rules (CRITICAL)

NEVER use em dashes (—). They are the #1 marker of AI writing. Use commas, colons, or parentheses instead.
No emoji in blog posts or scripts.

Banned verbs: delve, leverage, optimize, utilize, facilitate, foster, bolster, underscore, unveil, navigate, streamline, enhance, endeavour, ascertain, elucidate
Banned adjectives: robust, comprehensive, pivotal, crucial, vital, transformative, cutting-edge, groundbreaking, innovative, seamless, intricate, nuanced, multifaceted, holistic
Banned transitions: furthermore, moreover, notwithstanding, "that being said", "at its core", "it is worth noting", "in the realm of", "in today's [anything]"
Banned openings: "In today's fast-paced world", "In today's digital age", "In an era of", "In the ever-evolving landscape", "Let's delve into", "Imagine a world where"
Banned conclusions: "In conclusion", "To sum up", "At the end of the day", "All things considered", "In the final analysis"
Banned patterns: "Whether you're a X, Y, or Z", "It's not just X, it's also Y", starting sentences with "By" + gerund ("By understanding X, you can Y")
Banned filler: absolutely, basically, certainly, clearly, definitely, essentially, extremely, fundamentally, incredibly, interestingly, naturally, obviously, quite, really, significantly, simply, surely, truly, ultimately, undoubtedly, very

Use natural transitions instead: "Here's the thing", "But", "So", "Also", "Plus", "On top of that", "That said", "However"
Vary sentence length. Read it aloud. If it sounds like a press release, rewrite it.`

type PipelineHandler struct {
	queries     *store.Queries
	aiClient    *ai.Client
	braveClient *search.BraveClient
	model       func() string
}

func NewPipelineHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, model func() string) *PipelineHandler {
	return &PipelineHandler{queries: q, aiClient: aiClient, braveClient: braveClient, model: model}
}

func (h *PipelineHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "pipeline" && r.Method == "GET":
		h.list(w, r, projectID)
	case rest == "pipeline" && r.Method == "POST":
		h.create(w, r, projectID)
	case strings.HasSuffix(rest, "/abandon") && r.Method == "POST":
		h.abandon(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/delete") && r.Method == "POST":
		h.deleteRun(w, r, projectID, rest)
	case strings.Contains(rest, "/step/") && strings.HasSuffix(rest, "/abort") && r.Method == "POST":
		h.abortStep(w, r, projectID, rest)
	case strings.Contains(rest, "/stream/step/"):
		h.streamStep(w, r, projectID, rest)
	case strings.Contains(rest, "/stream/piece/"):
		h.streamPiece(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/approve") && r.Method == "POST":
		h.approvePiece(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/reject") && r.Method == "POST":
		h.rejectPiece(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/abort") && r.Method == "POST":
		h.abortPiece(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/improve/stream"):
		h.streamImprove(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/improve") && r.Method == "POST":
		h.saveImproveMessage(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/proofread") && r.Method == "GET":
		h.proofread(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/save-proofread") && r.Method == "POST":
		h.saveProofread(w, r, projectID, rest)
	default:
		// pipeline/{id}
		h.show(w, r, projectID, rest)
	}
}

func (h *PipelineHandler) list(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	runs, _ := h.queries.ListPipelineRuns(projectID)
	views := make([]templates.PipelineRunView, len(runs))
	for i, run := range runs {
		views[i] = templates.PipelineRunView{
			ID:     run.ID,
			Status: run.Status,
			Phase:  run.Phase,
			Topic:  run.Topic,
		}
	}

	templates.PipelineListPage(templates.PipelineListData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Runs:        views,
	}).Render(r.Context(), w)
}

func (h *PipelineHandler) create(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	brief := r.FormValue("topic")
	if brief == "" {
		http.Error(w, "Topic required", http.StatusBadRequest)
		return
	}
	run, err := h.queries.CreatePipelineRun(projectID, brief)
	if err != nil {
		http.Error(w, "Failed to create run", http.StatusInternalServerError)
		return
	}

	// Create the three cornerstone agent steps
	h.queries.CreatePipelineStep(run.ID, "research", 0)
	h.queries.CreatePipelineStep(run.ID, "brand_enricher", 1)
	h.queries.CreatePipelineStep(run.ID, "factcheck", 2)
	h.queries.CreatePipelineStep(run.ID, "tone_analyzer", 3)
	h.queries.CreatePipelineStep(run.ID, "write", 4)

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, run.ID), http.StatusSeeOther)
}

func (h *PipelineHandler) show(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	if runID == 0 {
		http.NotFound(w, r)
		return
	}

	project, _ := h.queries.GetProject(projectID)
	run, err := h.queries.GetPipelineRun(runID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	pieces, _ := h.queries.ListContentByPipelineRun(runID)
	contentViews := make([]templates.ContentPieceView, len(pieces))
	for i, p := range pieces {
		contentViews[i] = templates.ContentPieceView{
			ID:              p.ID,
			Platform:        p.Platform,
			Format:          p.Format,
			Title:           p.Title,
			Body:            p.Body,
			Status:          p.Status,
			SortOrder:       p.SortOrder,
			RejectionReason: p.RejectionReason,
			IsCornerstone:   p.ParentID == nil,
		}
	}

	// Find next pending piece
	var nextPieceID int64
	next, err := h.queries.NextPendingPiece(runID)
	if err == nil {
		nextPieceID = next.ID
	}

	steps, _ := h.queries.ListPipelineSteps(runID)
	stepViews := make([]templates.PipelineStepView, len(steps))
	for i, s := range steps {
		stepViews[i] = templates.PipelineStepView{
			ID:       s.ID,
			StepType: s.StepType,
			Status:   s.Status,
			Output:   s.Output,
			Thinking: s.Thinking,
		}
	}

	templates.ProductionBoardPage(templates.ProductionBoardData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		RunID:       runID,
		Topic:       run.Topic,
		Brief:       run.Brief,
		Plan:        run.Plan,
		Phase:       run.Phase,
		Status:      run.Status,
		Steps:       stepViews,
		Pieces:      contentViews,
		NextPieceID: nextPieceID,
	}).Render(r.Context(), w)
}

func (h *PipelineHandler) abandon(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	h.queries.UpdatePipelineStatus(runID, "abandoned")
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, runID), http.StatusSeeOther)
}

func (h *PipelineHandler) deleteRun(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	h.queries.DeletePipelineRun(runID)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline", projectID), http.StatusSeeOther)
}

// --- Piece generation ---

func (h *PipelineHandler) buildPiecePrompt(projectID int64, piece *store.ContentPiece, run *store.PipelineRun, profile string) string {
	ct, ok := content.LookupType(piece.Platform, piece.Format)

	var promptText string
	if ok {
		promptText, _ = content.LoadPrompt(ct.PromptFile)
	}
	if promptText == "" {
		// Fallback if prompt file not found
		promptText = fmt.Sprintf("You are writing a %s %s.", piece.Platform, piece.Format)
	}

	prompt := fmt.Sprintf("Today's date: %s\n\n%s\n\n## Client profile\n%s\n",
		time.Now().Format("January 2, 2006"), promptText, profile)

	if piece.ParentID == nil {
		// Cornerstone — inject storytelling framework if set
		if fwKey, err := h.queries.GetProjectSetting(projectID, "storytelling_framework"); err == nil && fwKey != "" {
			if fw := content.FrameworkByKey(fwKey); fw != nil {
				prompt += fmt.Sprintf("\n## Storytelling framework\nFramework: %s (%s)\n%s\n", fw.Name, fw.Attribution, fw.PromptInstruction)
			}
		}
		prompt += fmt.Sprintf("\n## Topic brief\n%s\n", run.Brief)
	} else {
		// Waterfall — inject cornerstone
		cornerstone, _ := h.queries.GetContentPiece(*piece.ParentID)
		prompt += fmt.Sprintf("\n## Cornerstone content (your source material)\n%s\n", cornerstone.Body)
		prompt += "\nIMPORTANT: This content exists to funnel audience to the cornerstone piece. Stay faithful to the cornerstone's message and facts. Do not introduce new claims or information that isn't in the cornerstone.\n"
	}

	if piece.RejectionReason != "" {
		prompt += fmt.Sprintf("\nPrevious version was rejected. Feedback: %s. Address this.\n", piece.RejectionReason)
	}

	prompt += antiAIRules

	return prompt
}

func (h *PipelineHandler) streamPiece(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	pieceID := h.parsePieceID(rest)

	// Guard
	ok, err := h.queries.TrySetGenerating(pieceID)
	if err != nil || !ok {
		http.Error(w, "Piece already generating or done", http.StatusConflict)
		return
	}

	piece, _ := h.queries.GetContentPiece(pieceID)
	run, _ := h.queries.GetPipelineRun(runID)
	profile, _ := h.queries.BuildProfileString(projectID)

	systemPrompt := h.buildPiecePrompt(projectID, piece, run, profile)

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: fmt.Sprintf("Write the %s %s now.", piece.Platform, piece.Format)},
	}

	flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList, executor := h.buildTools(pieceID)
	onToolEvent := h.buildToolEventCallback(sendEvent, pieceID)

	// Add the content type's write tool
	ct, ctOk := content.LookupType(piece.Platform, piece.Format)
	if ctOk {
		toolList = append(toolList, ct.Tool)
	}

	temp := 0.3
	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, sendThinking, &temp)
	if err != nil {
		sendError(err.Error())
		return
	}

	// If the AI used a write tool, the body was already saved by the executor.
	// If not (fallback), save the raw text response.
	currentPiece, _ := h.queries.GetContentPiece(pieceID)
	if currentPiece.Status != "draft" {
		h.queries.UpdateContentPieceBody(pieceID, piece.Title, fullResponse)
		h.queries.SetContentPieceStatus(pieceID, "draft")
	}
	sendDone()
}

func (h *PipelineHandler) approvePiece(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	pieceID := h.parsePieceID(rest)

	piece, _ := h.queries.GetContentPiece(pieceID)
	h.queries.SetContentPieceStatus(pieceID, "approved")

	// Cornerstone approved = run complete
	if piece.ParentID == nil {
		h.queries.UpdatePipelineStatus(runID, "complete")
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{"complete": piece.ParentID == nil})
}

func (h *PipelineHandler) rejectPiece(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	pieceID := h.parsePieceID(rest)
	runID := h.parseRunID(rest)
	r.ParseForm()
	reason := r.FormValue("reason")
	h.queries.SetContentPieceRejection(pieceID, reason)

	piece, _ := h.queries.GetContentPiece(pieceID)
	if piece.ParentID == nil {
		// Cornerstone rejected — reset writer step to pending
		steps, _ := h.queries.ListPipelineSteps(runID)
		for _, s := range steps {
			if s.StepType == "write" {
				h.queries.UpdatePipelineStepStatus(s.ID, "pending")
				break
			}
		}
	}

	w.WriteHeader(http.StatusOK)
}

func (h *PipelineHandler) abortStep(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	stepID := h.parseStepID(rest)
	h.queries.UpdatePipelineStepStatus(stepID, "pending")
	runID := h.parseRunID(rest)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, runID), http.StatusSeeOther)
}

func (h *PipelineHandler) abortPiece(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	pieceID := h.parsePieceID(rest)
	// Reset from generating back to pending so it can be re-triggered
	h.queries.SetContentPieceStatus(pieceID, "pending")
	runID := h.parseRunID(rest)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, runID), http.StatusSeeOther)
}

// --- Improve ---

func (h *PipelineHandler) saveImproveMessage(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	pieceID := h.parsePieceID(rest)
	r.ParseForm()
	msgContent := r.FormValue("content")
	if msgContent == "" {
		http.Error(w, "Content required", http.StatusBadRequest)
		return
	}

	chat, _ := h.queries.GetOrCreatePieceChat(projectID, pieceID)
	h.queries.AddBrainstormMessage(chat.ID, "user", msgContent, "")
	w.WriteHeader(http.StatusOK)
}

func (h *PipelineHandler) streamImprove(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	pieceID := h.parsePieceID(rest)
	piece, err := h.queries.GetContentPiece(pieceID)
	if err != nil {
		http.Error(w, "Piece not found", http.StatusNotFound)
		return
	}

	chat, _ := h.queries.GetOrCreatePieceChat(projectID, pieceID)
	msgs, _ := h.queries.ListBrainstormMessages(chat.ID)

	ct, ctOk := content.LookupType(piece.Platform, piece.Format)

	// Minimal prompt — just the content + format info. No profile, no prompt file bloat.
	systemPrompt := fmt.Sprintf(`You are editing a %s %s. Here is the current version:

%s

Apply the user's feedback. Return the complete rewritten version by calling the write tool. Do not explain changes, just provide the improved content.`, piece.Platform, piece.Format, piece.Body)

	aiMsgs := []types.Message{{Role: "system", Content: systemPrompt}}
	for _, m := range msgs {
		aiMsgs = append(aiMsgs, types.Message{Role: m.Role, Content: m.Content})
	}

	flusher, sendEvent, sendChunk, _, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	// Only the write tool — no fetch/search for improve (minimal context)
	var toolList []ai.Tool
	if ctOk {
		toolList = []ai.Tool{ct.Tool}
	}
	executor := func(ctx context.Context, name, args string) (string, error) {
		if content.IsWriteTool(name) && pieceID > 0 {
			h.queries.UpdateContentPieceBody(pieceID, "", args)
			h.queries.SetContentPieceStatus(pieceID, "draft")
			return "Content updated.", nil
		}
		return "", fmt.Errorf("unknown tool: %s", name)
	}
	onToolEvent := h.buildToolEventCallback(sendEvent, pieceID)

	temp := 0.3
	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, func(string) error { return nil }, &temp)
	if err != nil {
		sendError(err.Error())
		return
	}

	// Save assistant message
	h.queries.AddBrainstormMessage(chat.ID, "assistant", fullResponse, "")

	// If the AI used a write tool, the body was already saved and content_written was sent.
	// If not (fallback), save the text response and send content_written.
	currentPiece, _ := h.queries.GetContentPiece(pieceID)
	if currentPiece.Body == piece.Body && fullResponse != "" {
		// Body didn't change via tool — save text response as new body
		h.queries.UpdateContentPieceBody(pieceID, piece.Title, fullResponse)
		h.queries.SetContentPieceStatus(pieceID, "draft")
		dataJSON, _ := json.Marshal(fullResponse)
		sendEvent(map[string]any{
			"type":     "content_written",
			"platform": piece.Platform,
			"format":   piece.Format,
			"data":     json.RawMessage(dataJSON),
		})
	}

	sendDone()
}

// --- Helpers ---

func (h *PipelineHandler) setupSSE(w http.ResponseWriter) (http.Flusher, func(any), func(string) error, func(string) error, func(), func(string)) {
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return nil, nil, nil, nil, nil, nil
	}

	sendEvent := func(v any) {
		data, _ := json.Marshal(v)
		fmt.Fprintf(w, "data: %s\n\n", data)
		flusher.Flush()
	}

	sendChunk := func(chunk string) error {
		sendEvent(map[string]string{"type": "chunk", "chunk": chunk})
		return nil
	}

	sendThinking := func(chunk string) error {
		sendEvent(map[string]string{"type": "thinking", "chunk": chunk})
		return nil
	}

	sendDone := func() {
		sendEvent(map[string]string{"type": "done"})
	}

	sendError := func(errMsg string) {
		sendEvent(map[string]string{"type": "error", "error": errMsg})
	}

	return flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError
}

func (h *PipelineHandler) buildTools(pieceID int64) ([]ai.Tool, ai.ToolExecutor) {
	toolList := []ai.Tool{
		tools.NewFetchTool(),
		tools.NewSearchTool(),
	}

	searchExec := tools.NewSearchExecutor(h.braveClient)

	executor := func(ctx context.Context, name, args string) (string, error) {
		switch name {
		case "fetch_url":
			return tools.ExecuteFetch(ctx, args)
		case "web_search":
			return searchExec(ctx, args)
		default:
			if content.IsWriteTool(name) && pieceID > 0 {
				// Save structured content to piece body
				h.queries.UpdateContentPieceBody(pieceID, "", args)
				h.queries.SetContentPieceStatus(pieceID, "draft")
				return "Content saved successfully. The user will review it.", nil
			}
			return "", fmt.Errorf("unknown tool: %s", name)
		}
	}

	return toolList, executor
}

func (h *PipelineHandler) buildToolEventCallback(sendEvent func(any), pieceID int64) ai.ToolEventFn {
	return func(event ai.ToolEvent) {
		switch event.Type {
		case "tool_start":
			if content.IsWriteTool(event.Tool) || event.Tool == "submit_production_plan" {
				// Don't show a tool indicator for write/plan tools
				return
			}
			summary := ""
			switch event.Tool {
			case "fetch_url":
				summary = tools.FetchSummary(event.Args)
			case "web_search":
				summary = tools.SearchSummary(event.Args)
			}
			sendEvent(map[string]string{"type": "tool_start", "tool": event.Tool, "summary": summary})
		case "tool_result":
			if content.IsWriteTool(event.Tool) && pieceID > 0 {
				// Send content_written event so the frontend can render immediately
				piece, err := h.queries.GetContentPiece(pieceID)
				if err == nil {
					sendEvent(map[string]any{
						"type":     "content_written",
						"platform": piece.Platform,
						"format":   piece.Format,
						"data":     json.RawMessage(piece.Body),
					})
				}
				// Send done immediately — don't let AI continue after writing
				sendEvent(map[string]string{"type": "done"})
				return
			}
			summary := event.Summary
			if len(summary) > 200 {
				summary = summary[:200] + "..."
			}
			sendEvent(map[string]string{"type": "tool_result", "tool": event.Tool, "summary": summary})
		}
	}
}

func (h *PipelineHandler) proofread(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	pieceID := h.parsePieceID(rest)
	piece, err := h.queries.GetContentPiece(pieceID)
	if err != nil {
		http.Error(w, "Piece not found", http.StatusNotFound)
		return
	}

	language, _ := h.queries.GetProjectSetting(projectID, "language")
	if language == "" {
		language = "English"
	}

	correctPrompt := fmt.Sprintf(`Language: %s

Fix grammar, spelling, and punctuation. Lightly improve sentence flow where it reads awkwardly, but do not rewrite or change the meaning, voice, or style. Keep the exact same structure and formatting. If the content is JSON, fix text values only, do not touch keys or structure. Return ONLY the corrected content, nothing else.

%s`, language, piece.Body)

	// Use a fast model for proofreading — configurable in settings
	proofModel, _ := h.queries.GetSetting("model_proofread")
	if proofModel == "" {
		proofModel = "openai/gpt-4o-mini"
	}
	corrected, err := h.aiClient.Complete(r.Context(), proofModel, []types.Message{
		{Role: "user", Content: correctPrompt},
	})
	if err != nil {
		http.Error(w, "Proofread failed: "+err.Error(), http.StatusInternalServerError)
		return
	}

	corrected = strings.TrimSpace(corrected)
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{"corrected": corrected, "piece_id": pieceID})
}

func (h *PipelineHandler) saveProofread(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	pieceID := h.parsePieceID(rest)
	piece, _ := h.queries.GetContentPiece(pieceID)
	r.ParseForm()
	corrected := r.FormValue("corrected")
	if corrected != "" {
		h.queries.UpdateContentPieceBody(pieceID, piece.Title, corrected)
	}
	runID := h.parseRunID(rest)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, runID), http.StatusSeeOther)
}

func (h *PipelineHandler) parseRunID(rest string) int64 {
	// rest = "pipeline/123" or "pipeline/123/..."
	parts := strings.Split(strings.TrimPrefix(rest, "pipeline/"), "/")
	id, _ := strconv.ParseInt(parts[0], 10, 64)
	return id
}

func (h *PipelineHandler) parsePieceID(rest string) int64 {
	// Look for "piece/123" in the rest string
	parts := strings.Split(rest, "/")
	for i, p := range parts {
		if p == "piece" && i+1 < len(parts) {
			id, _ := strconv.ParseInt(parts[i+1], 10, 64)
			return id
		}
	}
	return 0
}

func (h *PipelineHandler) parseStepID(rest string) int64 {
	// Look for "step/123" in the rest string
	parts := strings.Split(rest, "/")
	for i, p := range parts {
		if p == "step" && i+1 < len(parts) {
			id, _ := strconv.ParseInt(parts[i+1], 10, 64)
			return id
		}
	}
	return 0
}

// --- Researcher agent ---

func (h *PipelineHandler) researchTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_research",
			Description: "Submit your research findings. Call this when you have gathered sufficient sources and are ready to write the research brief.",
			Parameters: json.RawMessage(`{
				"type": "object",
				"properties": {
					"sources": {
						"type": "array",
						"description": "List of sources found during research",
						"items": {
							"type": "object",
							"properties": {
								"url": {"type": "string", "description": "Source URL"},
								"title": {"type": "string", "description": "Source title"},
								"summary": {"type": "string", "description": "What this source contributes"},
								"date": {"type": "string", "description": "Publication date if known"}
							},
							"required": ["url", "title", "summary"]
						}
					},
					"brief": {
						"type": "string",
						"description": "A comprehensive research brief synthesizing all findings. Include key facts, angles, statistics, and anything the writer needs to produce an authoritative piece."
					}
				},
				"required": ["sources", "brief"]
			}`),
		},
	}
}

func (h *PipelineHandler) streamResearch(w http.ResponseWriter, r *http.Request, projectID int64, stepID int64, run *store.PipelineRun) {
	ok, err := h.queries.TrySetStepRunning(stepID)
	if err != nil || !ok {
		http.Error(w, "Step already running or completed", http.StatusConflict)
		return
	}

	profile, _ := h.queries.BuildProfileString(projectID)

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are a research specialist. Your job is to gather reliable, up-to-date information on a topic so a writer can produce an authoritative piece.

Client profile:
%s

Topic brief:
%s

Search the web thoroughly. Look for:
- Key facts, data, and statistics
- Recent developments (last 12 months preferred)
- Expert opinions and quotes if available
- Relevant angles and sub-topics
- Anything that makes this topic interesting or surprising

Fetch pages when search snippets are insufficient. Aim for at least 3-5 solid sources.

When you have gathered enough material, call submit_research with your sources and a comprehensive brief.`, time.Now().Format("January 2, 2006"), profile, run.Brief)

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Begin researching this topic now."},
	}

	flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList, baseExecutor := h.buildTools(0)
	toolList = append(toolList, h.researchTool())

	var thinkingBuf strings.Builder
	var savedOutput string

	executor := func(ctx context.Context, name, args string) (string, error) {
		if name == "submit_research" {
			savedOutput = args
			h.queries.UpdatePipelineStepOutput(stepID, args, thinkingBuf.String())
			return "Research saved successfully.", nil
		}
		return baseExecutor(ctx, name, args)
	}

	origSendThinking := sendThinking
	capturingSendThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return origSendThinking(chunk)
	}

	onToolEvent := h.buildToolEventCallback(sendEvent, 0)

	temp := 0.3
	_, err = h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, capturingSendThinking, &temp)
	if err != nil {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError(err.Error())
		return
	}

	if savedOutput == "" {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError("Researcher did not submit findings via tool call. Try again.")
		return
	}

	h.queries.UpdatePipelineStepStatus(stepID, "completed")
	sendDone()
}

// --- Fact-checker agent ---

func (h *PipelineHandler) factcheckTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_factcheck",
			Description: "Submit your fact-check results. Call this when you have verified the research and are ready to provide the enriched brief.",
			Parameters: json.RawMessage(`{
				"type": "object",
				"properties": {
					"issues_found": {
						"type": "array",
						"description": "List of issues found during fact-checking (may be empty if everything checks out)",
						"items": {
							"type": "object",
							"properties": {
								"claim": {"type": "string", "description": "The claim that was checked"},
								"problem": {"type": "string", "description": "What is wrong or uncertain"},
								"resolution": {"type": "string", "description": "How to address this in the final content"}
							},
							"required": ["claim", "problem", "resolution"]
						}
					},
					"enriched_brief": {
						"type": "string",
						"description": "The research brief, corrected and enriched with any additional context from fact-checking. This is what the writer will use."
					},
					"sources": {
						"type": "array",
						"description": "Verified sources to cite in the final piece",
						"items": {
							"type": "object",
							"properties": {
								"url": {"type": "string"},
								"title": {"type": "string"},
								"summary": {"type": "string"},
								"date": {"type": "string"}
							},
							"required": ["url", "title", "summary"]
						}
					}
				},
				"required": ["issues_found", "enriched_brief", "sources"]
			}`),
		},
	}
}

func (h *PipelineHandler) streamFactcheck(w http.ResponseWriter, r *http.Request, projectID int64, stepID int64, run *store.PipelineRun, researchOutput string) {
	ok, err := h.queries.TrySetStepRunning(stepID)
	if err != nil || !ok {
		http.Error(w, "Step already running or completed", http.StatusConflict)
		return
	}

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are a fact-checker. You will verify the research brief below, check key claims against reliable sources, and produce an enriched brief for the writer.

Research output to verify:
%s

Your job:
1. Identify any claims that seem uncertain, outdated, or potentially wrong
2. Search/fetch to verify them
3. Correct anything that's wrong
4. Add any important context or caveats
5. Confirm or update the source list

IMPORTANT: Your sources list MUST include ALL sources from the input above, plus any new sources you found during verification. Never drop existing sources — always carry them forward.

When done, call submit_factcheck with your findings and the enriched brief.`, time.Now().Format("January 2, 2006"), researchOutput)

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Begin fact-checking now."},
	}

	flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList, baseExecutor := h.buildTools(0)
	toolList = append(toolList, h.factcheckTool())

	var savedOutput string

	executor := func(ctx context.Context, name, args string) (string, error) {
		if name == "submit_factcheck" {
			savedOutput = args
			h.queries.UpdatePipelineStepOutput(stepID, args, "")
			return "Fact-check saved successfully.", nil
		}
		return baseExecutor(ctx, name, args)
	}

	onToolEvent := h.buildToolEventCallback(sendEvent, 0)

	temp := 0.2
	_, err = h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, sendThinking, &temp)
	if err != nil {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError(err.Error())
		return
	}

	if savedOutput == "" {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError("Fact-checker did not submit results via tool call. Try again.")
		return
	}

	h.queries.UpdatePipelineStepStatus(stepID, "completed")
	sendDone()
}

// --- Writer agent ---

func (h *PipelineHandler) streamWrite(w http.ResponseWriter, r *http.Request, projectID int64, stepID int64, run *store.PipelineRun, factcheckOutput string) {
	ok, err := h.queries.TrySetStepRunning(stepID)
	if err != nil || !ok {
		http.Error(w, "Step already running or completed", http.StatusConflict)
		return
	}

	// Parse factcheck output to get enriched_brief
	var factcheck struct {
		EnrichedBrief string `json:"enriched_brief"`
		Sources       []struct {
			URL     string `json:"url"`
			Title   string `json:"title"`
			Summary string `json:"summary"`
			Date    string `json:"date"`
		} `json:"sources"`
	}
	_ = json.Unmarshal([]byte(factcheckOutput), &factcheck)

	// Collect sources from ALL pipeline steps (researcher, brand enricher, fact-checker)
	type source struct {
		URL, Title, Summary, Date string
	}
	seen := map[string]bool{}
	var allSources []source
	steps, _ := h.queries.ListPipelineSteps(run.ID)
	for _, s := range steps {
		if s.Output == "" {
			continue
		}
		var parsed struct {
			Sources []struct {
				URL     string `json:"url"`
				Title   string `json:"title"`
				Summary string `json:"summary"`
				Date    string `json:"date"`
			} `json:"sources"`
		}
		if json.Unmarshal([]byte(s.Output), &parsed) == nil {
			for _, src := range parsed.Sources {
				if src.URL != "" && !seen[src.URL] {
					seen[src.URL] = true
					allSources = append(allSources, source{src.URL, src.Title, src.Summary, src.Date})
				}
			}
		}
	}

	var sourcesText strings.Builder
	if len(allSources) > 0 {
		sourcesText.WriteString("\n## Sources (from research, brand analysis, and fact-checking)\n")
		for _, s := range allSources {
			line := fmt.Sprintf("- [%s](%s): %s", s.Title, s.URL, s.Summary)
			if s.Date != "" {
				line += fmt.Sprintf(" (%s)", s.Date)
			}
			sourcesText.WriteString(line + "\n")
		}
	}

	// Defaults for cornerstone
	platform := "blog"
	format := "post"

	ct, ctOk := content.LookupType(platform, format)
	var promptText string
	if ctOk {
		promptText, _ = content.LoadPrompt(ct.PromptFile)
	}
	if promptText == "" {
		promptText = fmt.Sprintf("You are writing a %s %s.", platform, format)
	}

	profile, _ := h.queries.BuildProfileString(projectID)

	systemPrompt := fmt.Sprintf("Today's date: %s\n\n%s\n\n## Client profile\n%s\n",
		time.Now().Format("January 2, 2006"), promptText, profile)

	// Storytelling framework
	if fwKey, err := h.queries.GetProjectSetting(projectID, "storytelling_framework"); err == nil && fwKey != "" {
		if fw := content.FrameworkByKey(fwKey); fw != nil {
			systemPrompt += fmt.Sprintf("\n## Storytelling framework\nFramework: %s (%s)\n%s\n", fw.Name, fw.Attribution, fw.PromptInstruction)
		}
	}

	// Use enriched brief if available, fall back to run brief
	brief := factcheck.EnrichedBrief
	if brief == "" {
		brief = run.Brief
	}
	systemPrompt += fmt.Sprintf("\n## Topic brief\n%s\n", brief)
	systemPrompt += sourcesText.String()

	// Check for rejected cornerstone piece — include rejection reason for re-runs
	pieces, _ := h.queries.ListContentByPipelineRun(run.ID)
	for _, p := range pieces {
		if p.ParentID == nil && p.Status == "rejected" && p.RejectionReason != "" {
			systemPrompt += fmt.Sprintf("\nPrevious version was rejected. Feedback: %s. Address this.\n", p.RejectionReason)
			break
		}
	}

	// Inject tone reference from tone_analyzer step (if it ran)
	for _, s := range steps {
		if s.StepType == "tone_analyzer" && s.Status == "completed" && s.Output != "" {
			var toneResult struct {
				ToneGuide string `json:"tone_guide"`
				Posts     []struct {
					Title string `json:"title"`
					URL   string `json:"url"`
				} `json:"posts"`
			}
			if json.Unmarshal([]byte(s.Output), &toneResult) == nil && toneResult.ToneGuide != "" {
				systemPrompt += "\n## Tone & style reference (from company blog)\nUse this ONLY to match the writing tone, voice, and style. Do NOT use any factual information from the blog posts — all facts must come from the research brief and sources above.\n\n"
				systemPrompt += toneResult.ToneGuide + "\n"
			}
			break
		}
	}

	systemPrompt += antiAIRules

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Write the cornerstone blog post now."},
	}

	flusher, sendEvent, sendChunk, sendThinking, _, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	// Writer only gets the write tool — no fetch/search
	var toolList []ai.Tool
	if ctOk {
		toolList = []ai.Tool{ct.Tool}
	}

	var savedPieceID int64

	executor := func(ctx context.Context, name, args string) (string, error) {
		if content.IsWriteTool(name) {
			// Parse title from args
			var writeArgs struct {
				Title string `json:"title"`
			}
			_ = json.Unmarshal([]byte(args), &writeArgs)
			title := writeArgs.Title
			if title == "" {
				title = run.Topic
			}

			// Create cornerstone ContentPiece (sort_order=0, nil parent)
			piece, err := h.queries.CreateContentPiece(projectID, run.ID, platform, format, title, 0, nil)
			if err != nil {
				return "", fmt.Errorf("failed to create content piece: %w", err)
			}
			savedPieceID = piece.ID

			// Save body and set status
			h.queries.UpdateContentPieceBody(piece.ID, title, args)
			h.queries.SetContentPieceStatus(piece.ID, "draft")

			// Update step output and status
			h.queries.UpdatePipelineStepOutput(stepID, args, "")
			h.queries.UpdatePipelineStepStatus(stepID, "completed")

			return "Content piece created successfully.", nil
		}
		return "", fmt.Errorf("unknown tool: %s", name)
	}

	onToolEvent := func(event ai.ToolEvent) {
		if event.Type == "tool_result" && content.IsWriteTool(event.Tool) && savedPieceID > 0 {
			piece, err := h.queries.GetContentPiece(savedPieceID)
			if err == nil {
				sendEvent(map[string]any{
					"type":     "content_written",
					"platform": piece.Platform,
					"format":   piece.Format,
					"data":     json.RawMessage(piece.Body),
				})
			}
			sendEvent(map[string]string{"type": "done"})
		}
	}

	temp := 0.3
	_, err = h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, sendThinking, &temp)
	if err != nil && savedPieceID == 0 {
		// Only mark failed if the write tool wasn't called
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError(err.Error())
		return
	}

	if savedPieceID == 0 {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError("Writer did not submit content via tool call. Try again.")
		return
	}

	// Step was already marked completed and done sent by the tool event callback
}

// --- Step dispatcher ---

func (h *PipelineHandler) streamStep(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	stepID := h.parseStepID(rest)

	step, err := h.queries.GetPipelineStep(stepID)
	if err != nil {
		http.Error(w, "Step not found", http.StatusNotFound)
		return
	}

	run, err := h.queries.GetPipelineRun(runID)
	if err != nil {
		http.Error(w, "Run not found", http.StatusNotFound)
		return
	}

	steps, _ := h.queries.ListPipelineSteps(runID)

	// Helper to find completed step output
	findOutput := func(stepType string) (string, bool) {
		for _, s := range steps {
			if s.StepType == stepType {
				if s.Status != "completed" {
					return "", false
				}
				return s.Output, true
			}
		}
		return "", false
	}

	switch step.StepType {
	case "research":
		h.streamResearch(w, r, projectID, stepID, run)

	case "brand_enricher":
		researchOutput, ok := findOutput("research")
		if !ok {
			http.Error(w, "Research step not completed yet", http.StatusConflict)
			return
		}
		h.streamBrandEnricher(w, r, projectID, stepID, run, researchOutput)

	case "factcheck":
		// Fact-checker receives the brand-enriched research
		enricherOutput, ok := findOutput("brand_enricher")
		if !ok {
			http.Error(w, "Brand enricher step not completed yet", http.StatusConflict)
			return
		}
		h.streamFactcheck(w, r, projectID, stepID, run, enricherOutput)

	case "tone_analyzer":
		h.streamToneAnalyzer(w, r, projectID, stepID, run)

	case "write":
		factcheckOutput, ok := findOutput("factcheck")
		if !ok {
			http.Error(w, "Factcheck step not completed yet", http.StatusConflict)
			return
		}
		h.streamWrite(w, r, projectID, stepID, run, factcheckOutput)

	default:
		http.Error(w, "Unknown step type: "+step.StepType, http.StatusBadRequest)
	}
}

// --- Brand Enricher agent ---

func (h *PipelineHandler) brandEnricherTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_brand_enrichment",
			Description: "Submit the research brief enriched with brand-specific context from the company URLs.",
			Parameters: json.RawMessage(`{
				"type": "object",
				"properties": {
					"enriched_brief": {"type": "string", "description": "The research brief enriched with relevant brand context, products, pricing, and messaging"},
					"brand_context": {"type": "string", "description": "Summary of relevant brand information found on the company pages"},
					"sources": {
						"type": "array",
						"items": {
							"type": "object",
							"properties": {
								"url": {"type": "string"},
								"title": {"type": "string"},
								"summary": {"type": "string"},
								"date": {"type": "string"}
							},
							"required": ["url", "title", "summary"]
						}
					}
				},
				"required": ["enriched_brief", "brand_context", "sources"]
			}`),
		},
	}
}

func (h *PipelineHandler) streamBrandEnricher(w http.ResponseWriter, r *http.Request, projectID int64, stepID int64, run *store.PipelineRun, researchOutput string) {
	ok, err := h.queries.TrySetStepRunning(stepID)
	if err != nil || !ok {
		http.Error(w, "Step already running or completed", http.StatusConflict)
		return
	}

	// Collect brand URLs and their usage notes
	settings, _ := h.queries.AllProjectSettings(projectID)
	type brandURL struct {
		URL   string
		Notes string
		Label string
	}
	var urls []brandURL
	if v := settings["company_website"]; v != "" {
		for _, u := range splitURLs(v) {
			urls = append(urls, brandURL{URL: u, Notes: settings["website_notes"], Label: "Company Website"})
		}
	}
	if v := settings["company_pricing"]; v != "" {
		for _, u := range splitURLs(v) {
			urls = append(urls, brandURL{URL: u, Notes: settings["pricing_notes"], Label: "Pricing Page"})
		}
	}
	if len(urls) == 0 {
		// No brand URLs configured — pass research through unchanged
		h.queries.UpdatePipelineStepOutput(stepID, researchOutput, "")
		h.queries.UpdatePipelineStepStatus(stepID, "completed")
		flusher, _, _, _, sendDone, _ := h.setupSSE(w)
		if flusher == nil {
			return
		}
		sendDone()
		return
	}

	// Build the URL list for the prompt
	var urlList strings.Builder
	for _, u := range urls {
		fmt.Fprintf(&urlList, "- %s: %s", u.Label, u.URL)
		if u.Notes != "" {
			fmt.Fprintf(&urlList, " (Usage notes: %s)", u.Notes)
		}
		urlList.WriteString("\n")
	}

	profile, _ := h.queries.BuildProfileString(projectID)

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are a brand enricher. You receive market research about a topic and company brand URLs. Your job is to:

1. Fetch each company URL to understand the brand's products, services, pricing, and messaging
2. Identify which brand offerings are relevant to the research topic
3. Enrich the research brief with specific brand context — product names, pricing, features, value propositions
4. Make sure the enriched brief gives the content writer everything they need to naturally weave brand references into the content

Client profile:
%s

Research to enrich:
%s

Company URLs to fetch:
%s

Fetch ALL the URLs above. For each URL, read the page content and extract what's relevant to the research topic. Then produce an enriched version of the research brief that weaves in the brand context.

IMPORTANT: Your sources list MUST include ALL sources from the original research above, plus any new brand URLs you fetched. Never drop sources from the researcher — always carry them forward.

You MUST call the submit_brand_enrichment tool with your findings. Do not return results as text.`,
		time.Now().Format("January 2, 2006"), profile, researchOutput, urlList.String())

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Fetch the brand URLs and enrich the research with brand context."},
	}

	flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	// Brand enricher gets fetch_url tool + the submit tool
	toolList := []ai.Tool{
		tools.NewFetchTool(),
		h.brandEnricherTool(),
	}

	searchExec := tools.NewSearchExecutor(h.braveClient)
	var savedOutput string
	var thinkingBuf strings.Builder
	executor := func(ctx context.Context, name, args string) (string, error) {
		if name == "submit_brand_enrichment" {
			savedOutput = args
			h.queries.UpdatePipelineStepOutput(stepID, args, thinkingBuf.String())
			h.queries.UpdatePipelineStepStatus(stepID, "completed")
			return "Brand enrichment saved successfully.", nil
		}
		if name == "fetch_url" {
			return tools.ExecuteFetch(ctx, args)
		}
		if name == "web_search" {
			return searchExec(ctx, args)
		}
		return "", fmt.Errorf("unknown tool: %s", name)
	}
	onToolEvent := h.buildToolEventCallback(sendEvent, 0)

	captureThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return sendThinking(chunk)
	}

	temp := 0.3
	_, err = h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, captureThinking, &temp)
	if err != nil {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError(err.Error())
		return
	}

	if savedOutput == "" {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError("Brand enricher did not submit results via tool call. Try again.")
		return
	}
	sendDone()
}

// splitURLs splits a comma-separated URL string into trimmed individual URLs.
func splitURLs(s string) []string {
	parts := strings.Split(s, ",")
	var urls []string
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p != "" {
			urls = append(urls, p)
		}
	}
	return urls
}

// --- Tone Analyzer agent ---

func (h *PipelineHandler) toneAnalyzerTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_tone_analysis",
			Description: "Submit the tone and style guide based on the company's existing blog posts.",
			Parameters: json.RawMessage(`{
				"type": "object",
				"properties": {
					"tone_guide": {"type": "string", "description": "A concise guide describing the writing tone, voice, style patterns, sentence structure, vocabulary level, and formatting conventions observed in the blog posts. The writer will use this to match the brand's voice."},
					"posts": {
						"type": "array",
						"description": "The blog posts that were analyzed",
						"items": {
							"type": "object",
							"properties": {
								"title": {"type": "string"},
								"url": {"type": "string"},
								"excerpt": {"type": "string", "description": "A short excerpt showing the post's typical writing style"}
							},
							"required": ["title", "url"]
						}
					}
				},
				"required": ["tone_guide", "posts"]
			}`),
		},
	}
}

func (h *PipelineHandler) streamToneAnalyzer(w http.ResponseWriter, r *http.Request, projectID int64, stepID int64, run *store.PipelineRun) {
	ok, err := h.queries.TrySetStepRunning(stepID)
	if err != nil || !ok {
		http.Error(w, "Step already running or completed", http.StatusConflict)
		return
	}

	// Check if blog URL is configured
	settings, _ := h.queries.AllProjectSettings(projectID)
	blogURL := settings["company_blog"]
	if blogURL == "" {
		// No blog URL — skip this step
		h.queries.UpdatePipelineStepOutput(stepID, `{"tone_guide":"","posts":[]}`, "")
		h.queries.UpdatePipelineStepStatus(stepID, "completed")
		flusher, _, _, _, sendDone, _ := h.setupSSE(w)
		if flusher == nil {
			return
		}
		sendDone()
		return
	}

	blogURLs := splitURLs(blogURL)

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are a tone analyzer. Your job is to read 3-5 recent blog posts from the company's blog and create a tone/style guide for the content writer.

Blog URL(s) to start from:
%s

Steps:
1. Fetch the blog listing page(s) above
2. Find links to 3-5 recent individual blog posts
3. Fetch each post and read the full content
4. Analyze the writing patterns across all posts

Create a tone guide covering:
- Voice and tone (formal/informal, authoritative/conversational, etc.)
- Typical sentence structure and length
- Vocabulary level and any recurring phrases or expressions
- How they address the reader (you/vi, formal/informal)
- Formatting patterns (headings, lists, CTAs, etc.)
- Language (what language the posts are written in)

IMPORTANT: You are analyzing STYLE only, not content. The writer will use your guide to match the brand's voice, not to copy facts.

You MUST call submit_tone_analysis with your findings.`, time.Now().Format("January 2, 2006"), strings.Join(blogURLs, "\n"))

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Fetch the blog posts and analyze the writing tone."},
	}

	flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList := []ai.Tool{
		tools.NewFetchTool(),
		h.toneAnalyzerTool(),
	}

	var savedOutput string
	var thinkingBuf strings.Builder
	executor := func(ctx context.Context, name, args string) (string, error) {
		if name == "submit_tone_analysis" {
			savedOutput = args
			h.queries.UpdatePipelineStepOutput(stepID, args, thinkingBuf.String())
			h.queries.UpdatePipelineStepStatus(stepID, "completed")
			return "Tone analysis saved.", nil
		}
		if name == "fetch_url" {
			return tools.ExecuteFetch(ctx, args)
		}
		return "", fmt.Errorf("unknown tool: %s", name)
	}
	onToolEvent := h.buildToolEventCallback(sendEvent, 0)

	captureThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return sendThinking(chunk)
	}

	temp := 0.3
	_, err = h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, captureThinking, &temp)
	if err != nil {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError(err.Error())
		return
	}

	if savedOutput == "" {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError("Tone analyzer did not submit results via tool call. Try again.")
		return
	}
	sendDone()
}

