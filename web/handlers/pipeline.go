package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/agents"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type PipelineHandler struct {
	queries      *store.Queries
	pipeline     *pipeline.Pipeline
	ideaAgent    *agents.IdeaAgent
	contentAgent *agents.ContentAgent
}

func NewPipelineHandler(q *store.Queries, p *pipeline.Pipeline, ia *agents.IdeaAgent, ca *agents.ContentAgent) *PipelineHandler {
	return &PipelineHandler{queries: q, pipeline: p, ideaAgent: ia, contentAgent: ca}
}

func (h *PipelineHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "pipeline" && r.Method == "GET":
		h.list(w, r, projectID)
	case rest == "pipeline" && r.Method == "POST":
		h.create(w, r, projectID)
	case strings.HasSuffix(rest, "/abandon") && r.Method == "POST":
		h.abandon(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/advance") && r.Method == "POST":
		h.advance(w, r, projectID, rest)
	case strings.Contains(rest, "/stream/"):
		h.stream(w, r, projectID, rest)
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
		topic := ""
		if run.SelectedTopic != nil {
			topic = *run.SelectedTopic
		}
		views[i] = templates.PipelineRunView{
			ID:     run.ID,
			Status: run.Status,
			Topic:  topic,
		}
	}

	templates.PipelineListPage(templates.PipelineListData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Runs:        views,
	}).Render(r.Context(), w)
}

func (h *PipelineHandler) create(w http.ResponseWriter, r *http.Request, projectID int64) {
	run, err := h.queries.CreatePipelineRun(projectID)
	if err != nil {
		http.Error(w, "Failed to create run", http.StatusInternalServerError)
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, run.ID), http.StatusSeeOther)
}

func (h *PipelineHandler) show(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runIDStr := strings.TrimPrefix(rest, "pipeline/")
	runID, err := strconv.ParseInt(runIDStr, 10, 64)
	if err != nil {
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
			ID:     p.ID,
			Type:   p.Type,
			Title:  p.Title,
			Body:   p.Body,
			Status: p.Status,
		}
	}

	topic := ""
	if run.SelectedTopic != nil {
		topic = *run.SelectedTopic
	}

	templates.PipelineRunPage(templates.PipelineRunData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		RunID:       runID,
		Status:      run.Status,
		Topic:       topic,
		Content:     contentViews,
	}).Render(r.Context(), w)
}

func (h *PipelineHandler) advance(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "pipeline/123/advance"
	parts := strings.Split(rest, "/")
	if len(parts) < 3 {
		http.NotFound(w, r)
		return
	}
	runID, _ := strconv.ParseInt(parts[1], 10, 64)

	r.ParseForm()
	nextStatus := r.FormValue("next_status")
	topic := r.FormValue("topic")

	if topic != "" {
		h.pipeline.SetTopic(r.Context(), runID, topic)
	}

	if err := h.pipeline.Advance(r.Context(), runID, nextStatus); err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, runID), http.StatusSeeOther)
}

func (h *PipelineHandler) abandon(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	parts := strings.Split(rest, "/")
	if len(parts) < 3 {
		http.NotFound(w, r)
		return
	}
	runID, _ := strconv.ParseInt(parts[1], 10, 64)

	h.pipeline.Advance(r.Context(), runID, "abandoned")
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, runID), http.StatusSeeOther)
}

func (h *PipelineHandler) stream(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "pipeline/123/stream/ideate" or "pipeline/123/stream/pillar" or "pipeline/123/stream/waterfall"
	parts := strings.Split(rest, "/")
	if len(parts) < 4 {
		http.NotFound(w, r)
		return
	}
	runID, _ := strconv.ParseInt(parts[1], 10, 64)
	stage := parts[3]

	run, err := h.queries.GetPipelineRun(runID)
	if err != nil {
		http.Error(w, "Run not found", http.StatusNotFound)
		return
	}

	project, _ := h.queries.GetProject(projectID)

	// Set SSE headers
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}

	sendChunk := func(chunk string) error {
		data, _ := json.Marshal(map[string]string{"chunk": chunk})
		fmt.Fprintf(w, "data: %s\n\n", data)
		flusher.Flush()
		return nil
	}

	sendDone := func() {
		data, _ := json.Marshal(map[string]bool{"done": true})
		fmt.Fprintf(w, "data: %s\n\n", data)
		flusher.Flush()
	}

	sendError := func(errMsg string) {
		data, _ := json.Marshal(map[string]string{"error": errMsg})
		fmt.Fprintf(w, "data: %s\n\n", data)
		flusher.Flush()
	}

	// Build context
	voiceProfile := ""
	if project.VoiceProfile != nil {
		voiceProfile = *project.VoiceProfile
	}
	toneProfile := ""
	if project.ToneProfile != nil {
		toneProfile = *project.ToneProfile
	}

	summaries, _ := h.queries.ContentLogSummaries(projectID, 20)
	var contentLog []string
	for _, s := range summaries {
		contentLog = append(contentLog, fmt.Sprintf("%s: %s", s.Title, s.Body))
	}

	ctx := r.Context()

	switch stage {
	case "ideate":
		result, err := h.ideaAgent.GenerateStream(ctx, agents.IdeaInput{
			Niche:        project.Description,
			ContentLog:   contentLog,
			VoiceProfile: voiceProfile,
		}, sendChunk)
		if err != nil {
			sendError(err.Error())
			return
		}
		// Save agent run
		h.queries.CreateAgentRun(projectID, &run.ID, "idea", "Generate pillar ideas", result, nil)
		sendDone()

	case "pillar":
		topic := ""
		if run.SelectedTopic != nil {
			topic = *run.SelectedTopic
		}
		result, err := h.contentAgent.WritePillarStream(ctx, agents.PillarInput{
			Topic:        topic,
			VoiceProfile: voiceProfile,
			ToneProfile:  toneProfile,
			ContentLog:   contentLog,
		}, sendChunk)
		if err != nil {
			sendError(err.Error())
			return
		}
		// Save content piece
		piece, _ := h.queries.CreateContentPiece(projectID, &run.ID, "blog", topic, result, nil)
		h.queries.CreateAgentRun(projectID, &run.ID, "content", "Write pillar blog post", result, &piece.ID)
		sendDone()

	case "waterfall":
		// Find the pillar blog post from this run
		pieces, _ := h.queries.ListContentByPipelineRun(runID)
		var pillarBody string
		var pillarID int64
		for _, p := range pieces {
			if p.Type == "blog" {
				pillarBody = p.Body
				pillarID = p.ID
				break
			}
		}

		// Generate LinkedIn post
		result, err := h.contentAgent.WriteSocialPostStream(ctx, agents.SocialInput{
			PillarContent: pillarBody,
			Platform:      "linkedin",
			VoiceProfile:  voiceProfile,
			ToneProfile:   toneProfile,
		}, sendChunk)
		if err != nil {
			sendError(err.Error())
			return
		}
		piece, _ := h.queries.CreateContentPiece(projectID, &run.ID, "social_linkedin", "LinkedIn Post", result, &pillarID)
		h.queries.CreateAgentRun(projectID, &run.ID, "content", "Write LinkedIn post", result, &piece.ID)

		// Separator
		sendChunk("\n\n---\n\n")

		// Generate Instagram post
		result2, err := h.contentAgent.WriteSocialPostStream(ctx, agents.SocialInput{
			PillarContent: pillarBody,
			Platform:      "instagram",
			VoiceProfile:  voiceProfile,
			ToneProfile:   toneProfile,
		}, sendChunk)
		if err != nil {
			sendError(err.Error())
			return
		}
		piece2, _ := h.queries.CreateContentPiece(projectID, &run.ID, "social_instagram", "Instagram Post", result2, &pillarID)
		h.queries.CreateAgentRun(projectID, &run.ID, "content", "Write Instagram post", result2, &piece2.ID)

		sendDone()

	default:
		sendError("Unknown stage: " + stage)
	}
}
