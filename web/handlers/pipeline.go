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
	case strings.HasSuffix(rest, "/approve-plan") && r.Method == "POST":
		h.approvePlan(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/reject-plan") && r.Method == "POST":
		h.rejectPlan(w, r, projectID, rest)
	case strings.Contains(rest, "/stream/plan"):
		h.streamPlan(w, r, projectID, rest)
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
	topic := r.FormValue("topic")
	if topic == "" {
		http.Error(w, "Topic required", http.StatusBadRequest)
		return
	}
	run, err := h.queries.CreatePipelineRun(projectID, topic)
	if err != nil {
		http.Error(w, "Failed to create run", http.StatusInternalServerError)
		return
	}
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

	templates.ProductionBoardPage(templates.ProductionBoardData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		RunID:       runID,
		Topic:       run.Topic,
		Plan:        run.Plan,
		Status:      run.Status,
		Pieces:      contentViews,
		NextPieceID: nextPieceID,
	}).Render(r.Context(), w)
}

func (h *PipelineHandler) abandon(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	h.queries.UpdatePipelineStatus(runID, "abandoned")
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, runID), http.StatusSeeOther)
}

// --- Plan generation ---

func (h *PipelineHandler) streamPlan(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	run, err := h.queries.GetPipelineRun(runID)
	if err != nil {
		http.Error(w, "Run not found", http.StatusNotFound)
		return
	}

	profile, _ := h.queries.BuildProfileString(projectID)

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are a content production planner. Given a topic and the client's content strategy, create a production plan.

Client profile:
%s

Topic: %s

Based on the content strategy and waterfall flows defined in the profile, propose:
1. The cornerstone content type (blog post, video script, etc.)
2. The waterfall pieces that will be produced from it, with platform and format for each

Be specific. Reference the waterfall patterns from the content strategy section. If the strategy doesn't define waterfalls, propose reasonable defaults based on the platforms listed.

You MUST respond with ONLY a JSON object in this exact format, no other text:
{
  "cornerstone": {"platform": "blog", "format": "post", "title": "Working title here"},
  "waterfall": [
    {"platform": "instagram", "format": "post", "count": 2},
    {"platform": "linkedin", "format": "post", "count": 1}
  ]
}

Valid platforms: blog, linkedin, instagram, x, youtube, facebook, tiktok
Valid formats: post, thread, reel, carousel, script, short, video

WRITING RULES:
- Write like a human. Never sound AI-generated.
- Never use em dashes. Use commas, periods, or restructure.
- Avoid: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness".`, time.Now().Format("January 2, 2006"), profile, run.Topic)

	// If there's already a plan (re-plan after rejection), include rejection context
	var userMsg string
	if run.Plan != "" {
		userMsg = fmt.Sprintf("The previous plan was rejected. Here was the previous plan:\n%s\n\nPlease create a better plan.", run.Plan)
	} else {
		userMsg = "Create the production plan for this topic."
	}

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: userMsg},
	}

	flusher, sendEvent, sendChunk, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList, executor := h.buildTools(0)
	onToolEvent := h.buildToolEventCallback(sendEvent, 0)

	temp := 0.3
	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, &temp)
	if err != nil {
		sendError(err.Error())
		return
	}

	// Save plan
	h.queries.UpdatePipelinePlan(runID, fullResponse)
	sendDone()
}

func (h *PipelineHandler) approvePlan(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	run, err := h.queries.GetPipelineRun(runID)
	if err != nil {
		http.Error(w, "Run not found", http.StatusNotFound)
		return
	}

	// Parse JSON plan
	var plan struct {
		Cornerstone struct {
			Platform string `json:"platform"`
			Format   string `json:"format"`
			Title    string `json:"title"`
		} `json:"cornerstone"`
		Waterfall []struct {
			Platform string `json:"platform"`
			Format   string `json:"format"`
			Count    int    `json:"count"`
		} `json:"waterfall"`
	}

	// Try to extract JSON from the plan text (it might have markdown code fences)
	planText := run.Plan
	planText = strings.TrimSpace(planText)
	if idx := strings.Index(planText, "{"); idx >= 0 {
		planText = planText[idx:]
	}
	if idx := strings.LastIndex(planText, "}"); idx >= 0 {
		planText = planText[:idx+1]
	}

	if err := json.Unmarshal([]byte(planText), &plan); err != nil {
		http.Error(w, "Failed to parse plan JSON: "+err.Error(), http.StatusBadRequest)
		return
	}

	// Create cornerstone piece (sort_order 0)
	cornerstone, err := h.queries.CreateContentPiece(projectID, runID, plan.Cornerstone.Platform, plan.Cornerstone.Format, plan.Cornerstone.Title, 0, nil)
	if err != nil {
		http.Error(w, "Failed to create cornerstone", http.StatusInternalServerError)
		return
	}

	// Create waterfall pieces, expanding count
	sortOrder := 1
	for _, w := range plan.Waterfall {
		count := w.Count
		if count < 1 {
			count = 1
		}
		for i := 0; i < count; i++ {
			title := fmt.Sprintf("%s %s", w.Platform, w.Format)
			if count > 1 {
				title = fmt.Sprintf("%s %s #%d", w.Platform, w.Format, i+1)
			}
			h.queries.CreateContentPiece(projectID, runID, w.Platform, w.Format, title, sortOrder, &cornerstone.ID)
			sortOrder++
		}
	}

	// Update run status
	h.queries.UpdatePipelineStatus(runID, "producing")

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, runID), http.StatusSeeOther)
}

func (h *PipelineHandler) rejectPlan(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	r.ParseForm()
	reason := r.FormValue("reason")
	if reason != "" {
		// Append rejection reason to plan so it's available for re-generation
		run, _ := h.queries.GetPipelineRun(runID)
		h.queries.UpdatePipelinePlan(runID, run.Plan+"\n\nREJECTED: "+reason)
	}
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, runID), http.StatusSeeOther)
}

// --- Piece generation ---

func (h *PipelineHandler) buildPiecePrompt(piece *store.ContentPiece, run *store.PipelineRun, profile string) string {
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
		// Cornerstone
		prompt += fmt.Sprintf("\n## Topic\n%s\n", run.Topic)
	} else {
		// Waterfall — inject cornerstone
		cornerstone, _ := h.queries.GetContentPiece(*piece.ParentID)
		prompt += fmt.Sprintf("\n## Cornerstone content (your source material)\n%s\n", cornerstone.Body)
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

	systemPrompt := h.buildPiecePrompt(piece, run, profile)

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: fmt.Sprintf("Write the %s %s now.", piece.Platform, piece.Format)},
	}

	flusher, sendEvent, sendChunk, sendDone, sendError := h.setupSSE(w)
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
	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, &temp)
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

	h.queries.SetContentPieceStatus(pieceID, "approved")

	// Check if all done
	allDone, _ := h.queries.AllPiecesApproved(runID)
	if allDone {
		h.queries.UpdatePipelineStatus(runID, "complete")
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]any{"complete": true})
		return
	}

	// Find next pending piece
	next, err := h.queries.NextPendingPiece(runID)
	if err == nil {
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]any{"next_piece_id": next.ID})
		return
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{"complete": false})
}

func (h *PipelineHandler) rejectPiece(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	pieceID := h.parsePieceID(rest)
	r.ParseForm()
	reason := r.FormValue("reason")
	h.queries.SetContentPieceRejection(pieceID, reason)
	w.WriteHeader(http.StatusOK)
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
	h.queries.AddBrainstormMessage(chat.ID, "user", msgContent)
	w.WriteHeader(http.StatusOK)
}

func (h *PipelineHandler) streamImprove(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	pieceID := h.parsePieceID(rest)
	piece, err := h.queries.GetContentPiece(pieceID)
	if err != nil {
		http.Error(w, "Piece not found", http.StatusNotFound)
		return
	}

	profile, _ := h.queries.BuildProfileString(projectID)
	chat, _ := h.queries.GetOrCreatePieceChat(projectID, pieceID)
	msgs, _ := h.queries.ListBrainstormMessages(chat.ID)

	ct, ctOk := content.LookupType(piece.Platform, piece.Format)

	var promptText string
	if ctOk {
		promptText, _ = content.LoadPrompt(ct.PromptFile)
	}
	if promptText == "" {
		promptText = fmt.Sprintf("You are improving a %s %s.", piece.Platform, piece.Format)
	}

	systemPrompt := fmt.Sprintf(`Today's date: %s

%s

## Current version
%s

## Client profile
%s

The user wants to improve this piece. Respond to their feedback and provide a complete rewritten version by calling the write tool. Don't explain what you changed.
%s`, time.Now().Format("January 2, 2006"), promptText, piece.Body, profile, antiAIRules)

	aiMsgs := []types.Message{{Role: "system", Content: systemPrompt}}
	for _, m := range msgs {
		aiMsgs = append(aiMsgs, types.Message{Role: m.Role, Content: m.Content})
	}

	flusher, sendEvent, sendChunk, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList, executor := h.buildTools(pieceID)
	onToolEvent := h.buildToolEventCallback(sendEvent, pieceID)

	// Add the content type's write tool
	if ctOk {
		toolList = append(toolList, ct.Tool)
	}

	temp := 0.3
	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, &temp)
	if err != nil {
		sendError(err.Error())
		return
	}

	// Save assistant message
	h.queries.AddBrainstormMessage(chat.ID, "assistant", fullResponse)

	// If the AI used a write tool, the body was already saved by the executor.
	// If not (fallback), save the raw text response.
	currentPiece, _ := h.queries.GetContentPiece(pieceID)
	if currentPiece.Body == piece.Body {
		// Body didn't change via tool, save the text response
		h.queries.UpdateContentPieceBody(pieceID, piece.Title, fullResponse)
	}
	h.queries.SetContentPieceStatus(pieceID, "draft")

	sendDone()
}

// --- Helpers ---

func (h *PipelineHandler) setupSSE(w http.ResponseWriter) (http.Flusher, func(any), func(string) error, func(), func(string)) {
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return nil, nil, nil, nil, nil
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

	sendDone := func() {
		sendEvent(map[string]string{"type": "done"})
	}

	sendError := func(errMsg string) {
		sendEvent(map[string]string{"type": "error", "error": errMsg})
	}

	return flusher, sendEvent, sendChunk, sendDone, sendError
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
			if content.IsWriteTool(event.Tool) {
				// Don't show a tool indicator for write tools
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
