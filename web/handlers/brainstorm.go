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

type BrainstormHandler struct {
	queries     *store.Queries
	aiClient    *ai.Client
	braveClient *search.BraveClient
	model       func() string
}

func NewBrainstormHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, model func() string) *BrainstormHandler {
	return &BrainstormHandler{queries: q, aiClient: aiClient, braveClient: braveClient, model: model}
}

func (h *BrainstormHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "brainstorm" && r.Method == "GET":
		h.list(w, r, projectID)
	case rest == "brainstorm" && r.Method == "POST":
		h.createChat(w, r, projectID)
	case strings.HasSuffix(rest, "/message") && r.Method == "POST":
		h.saveMessage(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/stream"):
		h.streamResponse(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/generate-topic") && r.Method == "GET":
		h.generateTopic(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/push") && r.Method == "POST":
		h.pushToPipeline(w, r, projectID)
	default:
		h.showChat(w, r, projectID, rest)
	}
}

func (h *BrainstormHandler) list(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, _ := h.queries.GetProject(projectID)
	chats, _ := h.queries.ListBrainstormChats(projectID)

	views := make([]templates.BrainstormChatView, 0, len(chats))
	for _, c := range chats {
		// Skip profile chats — those show on the profile page
		if c.Section == "profile" {
			continue
		}
		preview := ""
		msgs, _ := h.queries.ListBrainstormMessages(c.ID)
		for _, m := range msgs {
			if m.Role == "user" {
				preview = m.Content
				if len(preview) > 120 {
					preview = preview[:120] + "..."
				}
				break
			}
		}
		views = append(views, templates.BrainstormChatView{ID: c.ID, Title: c.Title, Preview: preview})
	}

	templates.BrainstormListPage(templates.BrainstormListData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Chats:       views,
	}).Render(r.Context(), w)
}

func (h *BrainstormHandler) createChat(w http.ResponseWriter, r *http.Request, projectID int64) {
	title := time.Now().Format("Jan 2, 2006 3:04 PM")
	chat, err := h.queries.CreateBrainstormChat(projectID, title, "", nil)
	if err != nil {
		http.Error(w, "Failed to create chat", http.StatusInternalServerError)
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/brainstorm/%d", projectID, chat.ID), http.StatusSeeOther)
}

func (h *BrainstormHandler) showChat(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	chatID := h.parseChatID(rest)
	if chatID == 0 {
		http.NotFound(w, r)
		return
	}

	project, _ := h.queries.GetProject(projectID)
	chat, err := h.queries.GetBrainstormChat(chatID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	msgs, _ := h.queries.ListBrainstormMessages(chatID)
	views := make([]templates.BrainstormMsgView, len(msgs))
	for i, m := range msgs {
		views[i] = templates.BrainstormMsgView{Role: m.Role, Content: m.Content}
	}

	templates.BrainstormChatPage(templates.BrainstormChatData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		ChatID:      chatID,
		ChatTitle:   chat.Title,
		Messages:    views,
	}).Render(r.Context(), w)
}

// saveMessage saves the user message and returns immediately (no AI call).
func (h *BrainstormHandler) saveMessage(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	chatID := h.parseChatID(rest)
	r.ParseForm()
	content := r.FormValue("content")
	if content == "" {
		http.Error(w, "Content required", http.StatusBadRequest)
		return
	}

	_, err := h.queries.AddBrainstormMessage(chatID, "user", content)
	if err != nil {
		http.Error(w, "Failed to save message", http.StatusInternalServerError)
		return
	}

	w.WriteHeader(http.StatusOK)
}

// streamResponse streams the AI response via SSE, then saves it to the DB.
func (h *BrainstormHandler) streamResponse(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	chatID := h.parseChatID(rest)

	project, _ := h.queries.GetProject(projectID)
	msgs, _ := h.queries.ListBrainstormMessages(chatID)

	profile, _ := h.queries.BuildProfileString(projectID)

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are a content brainstorming partner for "%s". Your job is to help generate specific, ready-to-execute content ideas based on the client's profile.

## Client Profile
%s

## What brainstorming IS

This is ideation only. You're helping the user come up with topic areas, angles, and general directions for content. NOT writing full posts, scripts, outlines, or detailed content plans. Think whiteboard session, not production.

Good brainstorm output: "What about a series on common pricing mistakes SaaS founders make? You could tie it to your consulting offer."
Bad brainstorm output: "Here's a full LinkedIn post: [500 words of copy]"

## How you work

- Suggest topic areas and angles, not finished content
- Riff off what the user says. Build on their ideas, push them further, challenge weak ones
- Reference their content pillars and waterfall flows from the profile when relevant
- Use web search to find trending topics, competitor gaps, or timely angles when it would help
- Use fetch_url when the user shares something to analyze
- Ask which pillar or area they want to explore if it's unclear
- Keep ideas grounded in their actual business, audience, and goals
- If the profile is incomplete, work with what's there

WRITING STYLE:
- Write like a human. Never sound like AI-generated content.
- NEVER use em dashes. Use commas, periods, or restructure instead.
- NEVER overuse emojis. One max per message if it fits naturally. Zero in most cases.
- Avoid: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness", "it's worth noting".
- Short, direct sentences. Sound like a sharp colleague, not a marketing textbook.`, time.Now().Format("January 2, 2006"), project.Name, profile)

	aiMsgs := []types.Message{{Role: "system", Content: systemPrompt}}
	for _, m := range msgs {
		aiMsgs = append(aiMsgs, types.Message{Role: m.Role, Content: m.Content})
	}

	// SSE headers
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}

	sendEvent := func(v any) {
		data, _ := json.Marshal(v)
		fmt.Fprintf(w, "data: %s\n\n", data)
		flusher.Flush()
	}

	// Build tools (fetch + search only, no update_section)
	toolList := []ai.Tool{
		tools.NewFetchTool(),
		tools.NewSearchTool(),
	}

	// Create search executor
	searchExec := tools.NewSearchExecutor(h.braveClient)

	// Executor switch
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

	// Tool event callback
	onToolEvent := func(event ai.ToolEvent) {
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

	// Chunk callback
	sendChunk := func(chunk string) error {
		sendEvent(map[string]string{"type": "chunk", "chunk": chunk})
		return nil
	}

	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, nil)
	if err != nil {
		sendEvent(map[string]string{"type": "error", "error": err.Error()})
		return
	}

	// Save assistant message to DB
	h.queries.AddBrainstormMessage(chatID, "assistant", fullResponse)

	// Signal done
	sendEvent(map[string]string{"type": "done"})
}

func (h *BrainstormHandler) generateTopic(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	chatID := h.parseChatID(rest)
	msgs, _ := h.queries.ListBrainstormMessages(chatID)

	var conversation strings.Builder
	for _, m := range msgs {
		fmt.Fprintf(&conversation, "%s: %s\n\n", m.Role, m.Content)
	}

	aiMsgs := []types.Message{
		{
			Role: "system",
			Content: `You are distilling a brainstorming conversation into a single, specific content topic for a production pipeline.

Read the conversation and propose ONE specific topic/title that would make a great cornerstone content piece (blog post, video, etc).

Rules:
- Be specific. Not "content marketing tips" but "5 SEO mistakes that cost e-commerce brands $10K/month"
- The topic should be the strongest idea from the conversation
- Return ONLY the topic text, nothing else. No quotes, no explanation.`,
		},
		{
			Role:    "user",
			Content: fmt.Sprintf("Here is the brainstorming conversation:\n\n%s\n\nWhat's the best single topic for a content pipeline from this conversation?", conversation.String()),
		},
	}

	topic, err := h.aiClient.Complete(r.Context(), h.model(), aiMsgs)
	if err != nil {
		http.Error(w, err.Error(), http.StatusInternalServerError)
		return
	}

	topic = strings.TrimSpace(topic)
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{"topic": topic})
}

func (h *BrainstormHandler) pushToPipeline(w http.ResponseWriter, r *http.Request, projectID int64) {
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

func (h *BrainstormHandler) parseChatID(rest string) int64 {
	parts := strings.Split(strings.TrimPrefix(rest, "brainstorm/"), "/")
	id, err := strconv.ParseInt(parts[0], 10, 64)
	if err != nil {
		return 0
	}
	return id
}
