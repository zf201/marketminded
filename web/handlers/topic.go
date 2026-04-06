package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/applog"
	"github.com/zanfridau/marketminded/internal/pipeline/steps"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/search"
	"github.com/zanfridau/marketminded/internal/sse"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/web/templates"
)

type TopicHandler struct {
	queries       *store.Queries
	aiClient      *ai.Client
	braveClient   *search.BraveClient
	toolRegistry  *tools.Registry
	promptBuilder *prompt.Builder
	model         func() string
}

func NewTopicHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, toolRegistry *tools.Registry, promptBuilder *prompt.Builder, model func() string) *TopicHandler {
	return &TopicHandler{queries: q, aiClient: aiClient, braveClient: braveClient, toolRegistry: toolRegistry, promptBuilder: promptBuilder, model: model}
}

func (h *TopicHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "topics" && r.Method == "GET":
		h.list(w, r, projectID)
	case rest == "topics/generate" && r.Method == "POST":
		h.generate(w, r, projectID)
	case strings.HasSuffix(rest, "/stream"):
		h.stream(w, r, projectID, rest)
	case strings.Contains(rest, "backlog/") && strings.HasSuffix(rest, "/start") && r.Method == "POST":
		h.startPipeline(w, r, projectID, rest)
	case strings.Contains(rest, "backlog/") && strings.HasSuffix(rest, "/delete") && r.Method == "POST":
		h.deleteBacklogItem(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/retry") && r.Method == "POST":
		h.retry(w, r, projectID, rest)
	default:
		h.showRun(w, r, projectID, rest)
	}
}

func (h *TopicHandler) list(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, _ := h.queries.GetProject(projectID)
	runs, _ := h.queries.ListTopicRuns(projectID)
	backlog, _ := h.queries.ListTopicBacklog(projectID)

	runViews := make([]templates.TopicRunView, len(runs))
	for i, run := range runs {
		topicCount, _ := h.queries.CountTopicRunTopics(run.ID)
		runViews[i] = templates.TopicRunView{
			ID:         run.ID,
			Status:     run.Status,
			TopicCount: topicCount,
			CreatedAt:  run.CreatedAt,
		}
	}

	backlogViews := make([]templates.TopicBacklogView, len(backlog))
	for i, item := range backlog {
		backlogViews[i] = templates.TopicBacklogView{
			ID:     item.ID,
			Title:  item.Title,
			Angle:  item.Angle,
			Status: item.Status,
		}
	}

	templates.TopicListPage(templates.TopicListData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Runs:        runViews,
		Backlog:     backlogViews,
	}).Render(r.Context(), w)
}

func (h *TopicHandler) generate(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	instructions := r.FormValue("instructions")
	run, err := h.queries.CreateTopicRun(projectID, instructions)
	if err != nil {
		http.Error(w, "Failed to create topic run", http.StatusInternalServerError)
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/topics/%d", projectID, run.ID), http.StatusSeeOther)
}

func (h *TopicHandler) showRun(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	if runID == 0 {
		http.NotFound(w, r)
		return
	}

	project, _ := h.queries.GetProject(projectID)
	run, err := h.queries.GetTopicRun(runID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	topicSteps, _ := h.queries.ListTopicSteps(runID)
	stepViews := make([]templates.TopicStepView, len(topicSteps))
	for i, s := range topicSteps {
		stepViews[i] = templates.TopicStepView{
			ID:        s.ID,
			StepType:  s.StepType,
			Round:     s.Round,
			Status:    s.Status,
			Output:    s.Output,
			Thinking:  s.Thinking,
			ToolCalls: s.ToolCalls,
		}
	}

	backlog, _ := h.queries.ListTopicBacklog(projectID)
	var approvedTopics []templates.TopicBacklogView
	for _, item := range backlog {
		if item.TopicRunID == runID && item.Status != "deleted" {
			approvedTopics = append(approvedTopics, templates.TopicBacklogView{
				ID:     item.ID,
				Title:  item.Title,
				Angle:  item.Angle,
				Status: item.Status,
			})
		}
	}

	templates.TopicRunPage(templates.TopicRunData{
		ProjectID:      projectID,
		ProjectName:    project.Name,
		RunID:          runID,
		Status:         run.Status,
		Instructions:   run.Instructions,
		Steps:          stepViews,
		ApprovedTopics: approvedTopics,
	}).Render(r.Context(), w)
}

func (h *TopicHandler) stream(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	if runID == 0 {
		http.Error(w, "Invalid run ID", http.StatusBadRequest)
		return
	}

	run, err := h.queries.GetTopicRun(runID)
	if err != nil || run.Status != "pending" {
		http.Error(w, "Run not found or not pending", http.StatusBadRequest)
		return
	}

	sseStream, err := sse.New(w)
	if err != nil {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}
	stream := &httpStepStream{sse: sseStream}

	// Build context
	profile, _ := h.queries.BuildProfileStringExcluding(projectID, []string{"content_strategy"})

	blogURL, _ := h.queries.GetProjectSetting(projectID, "blog_url")
	homepageURL, _ := h.queries.GetProjectSetting(projectID, "homepage_url")

	var blogContent, homepageContent string
	if blogURL != "" {
		blogContent, _ = tools.ExecuteFetch(r.Context(), fmt.Sprintf(`{"url":"%s"}`, blogURL))
	}
	if homepageURL != "" {
		homepageContent, _ = tools.ExecuteFetch(r.Context(), fmt.Sprintf(`{"url":"%s"}`, homepageURL))
	}

	// Run the ping-pong loop
	var allApproved []topicCandidate
	var allRejected []rejectedTopic
	sortOrder := 0

	for round := 1; round <= 3; round++ {
		// --- Explorer step ---
		exploreStep, err := h.queries.CreateTopicStep(runID, "topic_explore", round, sortOrder)
		sortOrder++
		if err != nil {
			stream.SendError("Failed to create explore step")
			break
		}

		sseStream.SendData(map[string]any{"type": "step_start", "round": round, "step_type": "topic_explore", "step_id": exploreStep.ID})

		// Build rejected/approved context for rounds 2+
		rejectedText := ""
		approvedText := ""
		if len(allRejected) > 0 {
			rj, _ := json.MarshalIndent(allRejected, "", "  ")
			rejectedText = string(rj)
		}
		if len(allApproved) > 0 {
			ap, _ := json.MarshalIndent(allApproved, "", "  ")
			approvedText = string(ap)
		}

		systemPrompt := h.promptBuilder.ForTopicExplore(profile, blogContent, homepageContent, rejectedText, approvedText, run.Instructions)
		toolList := h.toolRegistry.ForStep("topic_explore")

		explorePrefix := fmt.Sprintf("topics run=%d step=%d round=%d type=topic_explore", runID, exploreStep.ID, round)
		exploreResult, exploreErr := steps.RunWithTools(r.Context(), h.aiClient, h.model(), systemPrompt, "Search for topics now.", toolList, h.toolRegistry, "submit_topics", stream, 0.7, 20, explorePrefix)

		h.queries.UpdateTopicStepOutput(exploreStep.ID, exploreResult.Output, exploreResult.Thinking)
		if exploreResult.ToolCalls != "" {
			h.queries.UpdateTopicStepToolCalls(exploreStep.ID, exploreResult.ToolCalls)
		}

		if exploreErr != nil {
			h.queries.UpdateTopicStepStatus(exploreStep.ID, "failed")
			sseStream.SendData(map[string]any{"type": "step_done", "round": round, "step_type": "topic_explore", "status": "failed"})
			stream.SendError(fmt.Sprintf("Explorer failed: %v", exploreErr))
			h.queries.UpdateTopicRunStatus(runID, "failed")
			stream.SendDone()
			return
		}
		h.queries.UpdateTopicStepStatus(exploreStep.ID, "completed")
		sseStream.SendData(map[string]any{"type": "step_done", "round": round, "step_type": "topic_explore", "status": "completed"})

		// Parse explorer output
		var exploreOutput struct {
			Topics []topicCandidate `json:"topics"`
		}
		if err := json.Unmarshal([]byte(exploreResult.Output), &exploreOutput); err != nil {
			applog.Error("topics: failed to parse explorer output: %v", err)
			stream.SendError("Explorer output was not valid JSON")
			h.queries.UpdateTopicRunStatus(runID, "failed")
			stream.SendDone()
			return
		}

		// --- Reviewer step ---
		reviewStep, err := h.queries.CreateTopicStep(runID, "topic_review", round, sortOrder)
		sortOrder++
		if err != nil {
			stream.SendError("Failed to create review step")
			break
		}

		sseStream.SendData(map[string]any{"type": "step_start", "round": round, "step_type": "topic_review", "step_id": reviewStep.ID})

		topicsJSON, _ := json.MarshalIndent(exploreOutput.Topics, "", "  ")
		reviewSystemPrompt := h.promptBuilder.ForTopicReview(profile, string(topicsJSON))
		reviewToolList := h.toolRegistry.ForStep("topic_review")

		reviewPrefix := fmt.Sprintf("topics run=%d step=%d round=%d type=topic_review", runID, reviewStep.ID, round)
		reviewResult, reviewErr := steps.RunWithTools(r.Context(), h.aiClient, h.model(), reviewSystemPrompt, "Review these topics now.", reviewToolList, h.toolRegistry, "submit_review", stream, 0.3, 5, reviewPrefix)

		h.queries.UpdateTopicStepOutput(reviewStep.ID, reviewResult.Output, reviewResult.Thinking)
		if reviewResult.ToolCalls != "" {
			h.queries.UpdateTopicStepToolCalls(reviewStep.ID, reviewResult.ToolCalls)
		}

		if reviewErr != nil {
			h.queries.UpdateTopicStepStatus(reviewStep.ID, "failed")
			sseStream.SendData(map[string]any{"type": "step_done", "round": round, "step_type": "topic_review", "status": "failed"})
			stream.SendError(fmt.Sprintf("Reviewer failed: %v", reviewErr))
			h.queries.UpdateTopicRunStatus(runID, "failed")
			stream.SendDone()
			return
		}
		h.queries.UpdateTopicStepStatus(reviewStep.ID, "completed")
		sseStream.SendData(map[string]any{"type": "step_done", "round": round, "step_type": "topic_review", "status": "completed"})

		// Parse reviewer output
		var reviewOutput struct {
			Reviews []struct {
				Title     string `json:"title"`
				Verdict   string `json:"verdict"`
				Reasoning string `json:"reasoning"`
			} `json:"reviews"`
		}
		if err := json.Unmarshal([]byte(reviewResult.Output), &reviewOutput); err != nil {
			applog.Error("topics: failed to parse reviewer output: %v", err)
			stream.SendError("Reviewer output was not valid JSON")
			h.queries.UpdateTopicRunStatus(runID, "failed")
			stream.SendDone()
			return
		}

		// Collect approved and rejected
		topicsByTitle := make(map[string]topicCandidate)
		for _, t := range exploreOutput.Topics {
			topicsByTitle[t.Title] = t
		}

		for _, review := range reviewOutput.Reviews {
			if review.Verdict == "approved" {
				if tc, ok := topicsByTitle[review.Title]; ok {
					allApproved = append(allApproved, tc)
				}
			} else {
				allRejected = append(allRejected, rejectedTopic{
					Title:     review.Title,
					Reasoning: review.Reasoning,
				})
			}
		}

		sseStream.SendData(map[string]any{
			"type":           "round_complete",
			"round":          round,
			"approved_count": len(allApproved),
		})

		if len(allApproved) >= 2 {
			break
		}
	}

	// Save approved topics to backlog
	for _, topic := range allApproved {
		sourcesJSON, _ := json.Marshal(topic.Evidence)
		h.queries.CreateTopicBacklogItem(projectID, runID, topic.Title, topic.Angle, string(sourcesJSON))
	}

	h.queries.UpdateTopicRunStatus(runID, "completed")

	// Send final topics
	sseStream.SendData(map[string]any{
		"type":   "done",
		"topics": allApproved,
	})
}

func (h *TopicHandler) retry(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	if runID == 0 {
		http.Error(w, "Invalid run ID", http.StatusBadRequest)
		return
	}
	// Delete old steps and reset run status
	h.queries.DeleteTopicSteps(runID)
	h.queries.UpdateTopicRunStatus(runID, "pending")
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/topics/%d", projectID, runID), http.StatusSeeOther)
}

func (h *TopicHandler) startPipeline(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	topicID := h.parseBacklogID(rest)
	if topicID == 0 {
		http.Error(w, "Invalid topic ID", http.StatusBadRequest)
		return
	}

	item, err := h.queries.GetTopicBacklogItem(topicID)
	if err != nil {
		http.Error(w, "Topic not found", http.StatusNotFound)
		return
	}

	brief := fmt.Sprintf("%s\n\nAngle: %s", item.Title, item.Angle)
	run, err := h.queries.CreatePipelineRun(projectID, brief)
	if err != nil {
		http.Error(w, "Failed to create pipeline run", http.StatusInternalServerError)
		return
	}

	h.queries.CreatePipelineStep(run.ID, "research", 0)
	h.queries.CreatePipelineStep(run.ID, "brand_enricher", 1)
	h.queries.CreatePipelineStep(run.ID, "factcheck", 2)
	h.queries.CreatePipelineStep(run.ID, "editor", 3)
	h.queries.CreatePipelineStep(run.ID, "write", 4)

	h.queries.UpdateTopicBacklogStatus(topicID, "used")

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, run.ID), http.StatusSeeOther)
}

func (h *TopicHandler) deleteBacklogItem(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	topicID := h.parseBacklogID(rest)
	if topicID == 0 {
		http.Error(w, "Invalid topic ID", http.StatusBadRequest)
		return
	}
	h.queries.UpdateTopicBacklogStatus(topicID, "deleted")
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/topics", projectID), http.StatusSeeOther)
}

// --- Helpers ---

type topicCandidate struct {
	Title    string `json:"title"`
	Angle    string `json:"angle"`
	Evidence string `json:"evidence"`
}

type rejectedTopic struct {
	Title     string `json:"title"`
	Reasoning string `json:"reasoning"`
}

func (h *TopicHandler) parseRunID(rest string) int64 {
	// rest = "topics/123" or "topics/123/stream"
	parts := strings.Split(strings.TrimPrefix(rest, "topics/"), "/")
	id, err := strconv.ParseInt(parts[0], 10, 64)
	if err != nil {
		return 0
	}
	return id
}

func (h *TopicHandler) parseBacklogID(rest string) int64 {
	// rest = "topics/backlog/123/start" or "topics/backlog/123/delete"
	parts := strings.Split(rest, "/")
	for i, p := range parts {
		if p == "backlog" && i+1 < len(parts) {
			id, err := strconv.ParseInt(parts[i+1], 10, 64)
			if err != nil {
				return 0
			}
			return id
		}
	}
	return 0
}
