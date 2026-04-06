package handlers

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/sse"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/types"
	"github.com/zanfridau/marketminded/web/templates"
)

type PipelineHandler struct {
	queries       *store.Queries
	orchestrator  *pipeline.Orchestrator
	aiClient      *ai.Client
	writerModel   func() string
	promptBuilder *prompt.Builder
}

func NewPipelineHandler(q *store.Queries, orchestrator *pipeline.Orchestrator, aiClient *ai.Client, writerModel func() string, promptBuilder *prompt.Builder) *PipelineHandler {
	return &PipelineHandler{queries: q, orchestrator: orchestrator, aiClient: aiClient, writerModel: writerModel, promptBuilder: promptBuilder}
}

func (h *PipelineHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "pipeline" && r.Method == "GET":
		h.list(w, r, projectID)
	case rest == "pipeline" && r.Method == "POST":
		h.create(w, r, projectID)
	case strings.HasSuffix(rest, "/restart") && r.Method == "POST":
		h.restart(w, r, projectID, rest)
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

	// Create cornerstone agent steps
	h.queries.CreatePipelineStep(run.ID, "research", 0)
	h.queries.CreatePipelineStep(run.ID, "brand_enricher", 1)
	h.queries.CreatePipelineStep(run.ID, "factcheck", 2)
	h.queries.CreatePipelineStep(run.ID, "editor", 3)
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
			ID:        s.ID,
			StepType:  s.StepType,
			Status:    s.Status,
			Output:    s.Output,
			Thinking:  s.Thinking,
			ToolCalls: s.ToolCalls,
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

func (h *PipelineHandler) restart(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	h.queries.ResetPipelineSteps(runID)
	h.queries.UpdatePipelineStatus(runID, "pending")
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, runID), http.StatusSeeOther)
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

// --- Step streaming (delegates to orchestrator) ---

type httpStepStream struct{ sse *sse.Stream }

func (s *httpStepStream) SendChunk(chunk string) error {
	s.sse.SendData(map[string]string{"type": "chunk", "chunk": chunk})
	return nil
}
func (s *httpStepStream) SendThinking(chunk string) error {
	s.sse.SendData(map[string]string{"type": "thinking", "chunk": chunk})
	return nil
}
func (s *httpStepStream) SendEvent(v any) { s.sse.SendData(v) }
func (s *httpStepStream) SendError(msg string) {
	s.sse.SendData(map[string]string{"type": "error", "error": msg})
}
func (s *httpStepStream) SendDone() {
	s.sse.SendData(map[string]string{"type": "done"})
}

func (h *PipelineHandler) streamStep(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	stepID := h.parseStepID(rest)

	run, err := h.queries.GetPipelineRun(runID)
	if err != nil {
		http.Error(w, "Run not found", http.StatusNotFound)
		return
	}

	profile, _ := h.queries.BuildProfileStringExcluding(projectID, []string{"content_strategy"})

	sseStream, err := sse.New(w)
	if err != nil {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}

	stream := &httpStepStream{sse: sseStream}

	if err := h.orchestrator.RunStep(r.Context(), stepID, run, profile, stream); err != nil {
		stream.SendError(err.Error())
		return
	}

	stream.SendDone()
}

// --- Piece generation ---

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
	profile, _ := h.queries.BuildProfileStringExcluding(projectID, []string{"content_strategy"})

	ct, ctOk := content.LookupType(piece.Platform, piece.Format)

	var promptFile string
	if ctOk {
		promptFile = ct.PromptFile
	}

	var frameworkBlock string
	if vt, err := h.queries.GetVoiceToneProfile(projectID); err == nil {
		frameworkBlock = vt.BuildFrameworkBlock()
	}

	systemPrompt := h.promptBuilder.ForPiece(promptFile, profile, run.Brief, frameworkBlock, piece.RejectionReason)

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: fmt.Sprintf("Write the %s %s now.", piece.Platform, piece.Format)},
	}

	sseStream, err := sse.New(w)
	if err != nil {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}

	sendEvent := func(v any) { sseStream.SendData(v) }
	sendChunk := func(chunk string) error {
		sseStream.SendData(map[string]string{"type": "chunk", "chunk": chunk})
		return nil
	}
	sendThinking := func(chunk string) error {
		sseStream.SendData(map[string]string{"type": "thinking", "chunk": chunk})
		return nil
	}
	sendDone := func() { sseStream.SendData(map[string]string{"type": "done"}) }
	sendError := func(msg string) { sseStream.SendData(map[string]string{"type": "error", "error": msg}) }

	// Write tool — saves content piece body directly
	var toolList []ai.Tool
	if ctOk {
		toolList = append(toolList, ct.Tool)
	}

	executor := func(ctx context.Context, name, args string) (string, error) {
		if content.IsWriteTool(name) && pieceID > 0 {
			h.queries.UpdateContentPieceBody(pieceID, "", args)
			h.queries.SetContentPieceStatus(pieceID, "draft")
			return "Content saved successfully. The user will review it.", ai.ErrToolDone
		}
		return "", fmt.Errorf("unknown tool: %s", name)
	}

	onToolEvent := func(event ai.ToolEvent) {
		if event.Type == "tool_result" && content.IsWriteTool(event.Tool) && pieceID > 0 {
			p, err := h.queries.GetContentPiece(pieceID)
			if err == nil {
				sendEvent(map[string]any{
					"type":     "content_written",
					"platform": p.Platform,
					"format":   p.Format,
					"data":     json.RawMessage(p.Body),
				})
			}
			sendDone()
		}
	}

	temp := 0.3
	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.writerModel(), aiMsgs, toolList, executor, onToolEvent, sendChunk, sendThinking, &temp, "write_content")
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

	sseStream, err := sse.New(w)
	if err != nil {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}

	sendEvent := func(v any) { sseStream.SendData(v) }
	sendChunk := func(chunk string) error {
		sseStream.SendData(map[string]string{"type": "chunk", "chunk": chunk})
		return nil
	}
	sendDone := func() { sseStream.SendData(map[string]string{"type": "done"}) }
	sendError := func(msg string) { sseStream.SendData(map[string]string{"type": "error", "error": msg}) }

	// Only the write tool — no fetch/search for improve (minimal context)
	var toolList []ai.Tool
	if ctOk {
		toolList = []ai.Tool{ct.Tool}
	}
	executor := func(ctx context.Context, name, args string) (string, error) {
		if content.IsWriteTool(name) && pieceID > 0 {
			h.queries.UpdateContentPieceBody(pieceID, "", args)
			h.queries.SetContentPieceStatus(pieceID, "draft")
			return "Content updated.", ai.ErrToolDone
		}
		return "", fmt.Errorf("unknown tool: %s", name)
	}

	onToolEvent := func(event ai.ToolEvent) {
		if event.Type == "tool_result" && content.IsWriteTool(event.Tool) && pieceID > 0 {
			p, err := h.queries.GetContentPiece(pieceID)
			if err == nil {
				sendEvent(map[string]any{
					"type":     "content_written",
					"platform": p.Platform,
					"format":   p.Format,
					"data":     json.RawMessage(p.Body),
				})
			}
			sendDone()
		}
	}

	temp := 0.3
	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.writerModel(), aiMsgs, toolList, executor, onToolEvent, sendChunk, func(string) error { return nil }, &temp, "write_content")
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

// --- Proofread ---

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

// --- URL parsing helpers ---

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
