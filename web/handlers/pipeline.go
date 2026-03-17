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
	"github.com/zanfridau/marketminded/internal/search"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/internal/types"
	"github.com/zanfridau/marketminded/web/templates"
)

var platformGuidance = map[string]map[string]string{
	"linkedin": {
		"post": "Professional but personal. Hook in the first line. Use line breaks for readability. 1300 char max. End with a question or CTA. No hashtags in body, 3-5 at the end if guidelines allow.",
	},
	"instagram": {
		"post": "Visual-first caption. Hook in first line. Short paragraphs. Hashtags at the end (up to 15 relevant ones). Under 2200 chars. Engage with a question.",
		"reel": "Script for a 30-60 second video. Hook in first 3 seconds. One clear point. End with CTA. Conversational, not scripted-sounding.",
	},
	"x": {
		"post":   "Single tweet, under 280 chars. Punchy, opinionated, or surprising. No filler words.",
		"thread": "5-8 tweets. First tweet is the hook. Each tweet stands alone but builds on the previous. Last tweet is CTA. Number them.",
	},
	"blog": {
		"post": "Long-form markdown. 1200-2000 words. SEO-friendly headers. Intro with hook, clear sections, actionable takeaways, strong conclusion.",
	},
	"youtube": {
		"script": "Video script with timestamps. Hook in first 15 seconds. Clear sections. Conversational delivery notes in [brackets].",
		"short":  "Script for under 60 seconds. One point. Hook immediately. Fast-paced. End with follow CTA.",
	},
	"facebook": {
		"post": "Conversational. Hook first line. Encourage comments. 500 chars ideal. One CTA.",
	},
}

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

Valid platforms: blog, linkedin, instagram, x, youtube, facebook
Valid formats: post, thread, reel, script, short

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

	toolList, executor := h.buildTools()
	onToolEvent := h.buildToolEventCallback(sendEvent)

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

	var systemPrompt string
	if piece.ParentID == nil {
		// Cornerstone
		systemPrompt = h.cornerstonePrompt(run.Topic, piece.Platform, piece.Format, profile, piece.RejectionReason)
	} else {
		// Waterfall - get cornerstone body
		cornerstone, _ := h.queries.GetContentPiece(*piece.ParentID)
		systemPrompt = h.waterfallPrompt(piece.Platform, piece.Format, profile, cornerstone.Body, piece.RejectionReason)
	}

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: fmt.Sprintf("Write the %s %s now.", piece.Platform, piece.Format)},
	}

	flusher, sendEvent, sendChunk, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList, executor := h.buildTools()
	onToolEvent := h.buildToolEventCallback(sendEvent)

	temp := 0.3
	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, &temp)
	if err != nil {
		sendError(err.Error())
		return
	}

	// Save body and set to draft
	h.queries.UpdateContentPieceBody(pieceID, piece.Title, fullResponse)
	h.queries.SetContentPieceStatus(pieceID, "draft")
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
	content := r.FormValue("content")
	if content == "" {
		http.Error(w, "Content required", http.StatusBadRequest)
		return
	}

	chat, _ := h.queries.GetOrCreatePieceChat(projectID, pieceID)
	h.queries.AddBrainstormMessage(chat.ID, "user", content)
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

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are improving a content piece. Here is the current version:

%s

Platform: %s, Format: %s

Client profile:
%s

The user wants to improve this piece. Respond to their feedback and provide a complete rewritten version. Don't explain what you changed, just provide the improved content.

WRITING RULES:
- Write like a human. Never sound AI-generated.
- Never use em dashes. Use commas, periods, or restructure.
- No emoji in blog posts or scripts.
- Avoid: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness".
- Short, direct sentences. Vary length. Sound like a real person.`, time.Now().Format("January 2, 2006"), piece.Body, piece.Platform, piece.Format, profile)

	aiMsgs := []types.Message{{Role: "system", Content: systemPrompt}}
	for _, m := range msgs {
		aiMsgs = append(aiMsgs, types.Message{Role: m.Role, Content: m.Content})
	}

	flusher, sendEvent, sendChunk, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList, executor := h.buildTools()
	onToolEvent := h.buildToolEventCallback(sendEvent)

	temp := 0.3
	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, &temp)
	if err != nil {
		sendError(err.Error())
		return
	}

	// Save assistant message
	h.queries.AddBrainstormMessage(chat.ID, "assistant", fullResponse)

	// Update piece body and reset to draft
	h.queries.UpdateContentPieceBody(pieceID, piece.Title, fullResponse)
	h.queries.SetContentPieceStatus(pieceID, "draft")

	sendDone()
}

// --- Helpers ---

func (h *PipelineHandler) cornerstonePrompt(topic, platform, format, profile, rejectionReason string) string {
	prompt := fmt.Sprintf(`Today's date: %s

You are an expert conversion copywriter and content creator. Your goal is to write a %s %s that is clear, compelling, and drives action.

## Client profile
%s

## Assignment
Topic: %s

## Copywriting principles (follow these exactly)

### Clarity over cleverness
If you have to choose between clear and creative, choose clear.

### Benefits over features
Features: what it does. Benefits: what that means for the customer. Always lead with benefits.

### Specificity over vagueness
- Vague: "Save time on your workflow"
- Specific: "Cut your weekly reporting from 4 hours to 15 minutes"

### Customer language over company language
Use words the audience uses. Mirror their voice from the client profile. Not corporate speak.

### One idea per section
Each section advances one argument. Build logical flow down the page.

## Writing style rules
1. Simple over complex: "use" not "utilize", "help" not "facilitate"
2. Specific over vague: avoid "streamline", "optimize", "innovative"
3. Active over passive: "We generate reports" not "Reports are generated"
4. Confident over qualified: remove "almost", "very", "really", "basically"
5. Show over tell: describe the outcome instead of using adverbs
6. Honest over sensational: fabricated statistics or testimonials erode trust. NEVER invent numbers, quotes, case studies, customer names, or revenue figures that aren't in the client profile.

## Structure
- Headline: your single most important message. Communicate core value. Specific > generic.
- Subheadline/intro: expand on headline, add specificity, hook the reader with a relatable problem or surprising insight.
- Body sections: each with a clear header, one key point, actionable content.
- Conclusion: recap value, clear CTA or next step.

## Quality check (apply before finishing)
- Any jargon that could confuse outsiders? Remove it.
- Any sentences trying to do too much? Split them.
- Any passive voice? Rewrite to active.
- Any exclamation points? Remove them.
- Any marketing buzzwords without substance? Cut them.
- Any fabricated claims or statistics? Delete them. Use web_search tool if you need real data.`,
		time.Now().Format("January 2, 2006"), platform, format, profile, topic)

	if rejectionReason != "" {
		prompt += fmt.Sprintf("\n\nPrevious version was rejected. Feedback: %s. Address this in your rewrite.", rejectionReason)
	}

	prompt += `

## Absolute rules
- ONLY use information from the client profile. If the profile lacks data, write around it with frameworks and principles instead of making things up.
- Use web_search if you need real, current facts or statistics. This is always better than fabricating.
- Never use em dashes. Use commas, periods, or restructure.
- No emoji in blog posts or scripts.
- Never use: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness", "revolutionize", "cutting-edge", "innovative", "seamlessly", "at the end of the day", "it's worth noting".
- Every sentence must earn its place. No filler.`

	return prompt
}

func (h *PipelineHandler) waterfallPrompt(platform, format, profile, cornerstoneBody, rejectionReason string) string {
	guidance := ""
	if pg, ok := platformGuidance[platform]; ok {
		if g, ok := pg[format]; ok {
			guidance = g
		}
	}

	prompt := fmt.Sprintf(`Today's date: %s

You are an expert social media strategist repurposing cornerstone content into a %s %s.

## Client profile
%s

## Cornerstone content (your source material)
%s

## Target: %s %s
%s

## How to repurpose (don't just summarize)
1. Extract the most compelling angle from the cornerstone for THIS specific platform and audience
2. Reshape it to feel native to the platform, not like a truncated blog post
3. The first line is everything. Use a strong hook:
   - Curiosity: "I was wrong about [common belief]" or "[Result] in [surprisingly short time]"
   - Story: "Last week [unexpected thing happened]" or "I almost [big mistake]"
   - Value: "How to [outcome] without [pain]" or "Stop [mistake]. Do this instead:"
   - Contrarian: "Unpopular opinion: [bold take]" or "[Common advice] is wrong. Here's why:"
4. Write in the client's voice and tone. Use their audience's language.
5. End with a clear CTA or engagement prompt appropriate to the platform.

## Rules
- ONLY use information from the cornerstone and client profile. Never invent statistics, quotes, or claims.
- Benefits over features. Specificity over vagueness. Customer language over corporate speak.
- Simple words. Active voice. Confident tone. No hedging.`,
		time.Now().Format("January 2, 2006"), platform, format, profile, cornerstoneBody, platform, format, guidance)

	if rejectionReason != "" {
		prompt += fmt.Sprintf("\n\nPrevious version was rejected. Feedback: %s. Address this in your rewrite.", rejectionReason)
	}

	prompt += `

## Style
- Write like a human. Never sound AI-generated.
- Never use em dashes. Use commas, periods, or restructure.
- Never use: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness", "revolutionize", "cutting-edge", "innovative", "seamlessly".
- Adapt emoji/hashtag usage to platform norms only where the client's guidelines allow it.
- Every word must earn its place.`

	return prompt
}

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

func (h *PipelineHandler) buildTools() ([]ai.Tool, ai.ToolExecutor) {
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
			return "", fmt.Errorf("unknown tool: %s", name)
		}
	}

	return toolList, executor
}

func (h *PipelineHandler) buildToolEventCallback(sendEvent func(any)) ai.ToolEventFn {
	return func(event ai.ToolEvent) {
		switch event.Type {
		case "tool_start":
			summary := ""
			switch event.Tool {
			case "fetch_url":
				summary = tools.FetchSummary(event.Args)
			case "web_search":
				summary = tools.SearchSummary(event.Args)
			}
			sendEvent(map[string]string{"type": "tool_start", "tool": event.Tool, "summary": summary})
		case "tool_result":
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

