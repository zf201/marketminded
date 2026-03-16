package handlers

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"

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
	case strings.HasSuffix(rest, "/push") && r.Method == "POST":
		h.pushToPipeline(w, r, projectID)
	default:
		h.showChat(w, r, projectID, rest)
	}
}

func (h *BrainstormHandler) list(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, _ := h.queries.GetProject(projectID)
	chats, _ := h.queries.ListBrainstormChats(projectID)

	views := make([]templates.BrainstormChatView, len(chats))
	for i, c := range chats {
		views[i] = templates.BrainstormChatView{ID: c.ID, Title: c.Title}
	}

	templates.BrainstormListPage(templates.BrainstormListData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Chats:       views,
	}).Render(r.Context(), w)
}

func (h *BrainstormHandler) createChat(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	title := r.FormValue("title")
	if title == "" {
		title = "Untitled Chat"
	}
	chat, err := h.queries.CreateBrainstormChat(projectID, title, "")
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

	systemPrompt := fmt.Sprintf(`You are a content brainstorming assistant for the project "%s".

Client Profile:
%s

Help the user brainstorm content ideas, angles, and strategies. Be creative, specific, and actionable. Reference the brand's voice and tone when making suggestions.

You have access to web search and URL fetching tools. Use them when the user asks you to research something or shares a URL.`, project.Name, profile)

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

	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk)
	if err != nil {
		sendEvent(map[string]string{"type": "error", "error": err.Error()})
		return
	}

	// Save assistant message to DB
	h.queries.AddBrainstormMessage(chatID, "assistant", fullResponse)

	// Signal done
	sendEvent(map[string]string{"type": "done"})
}

func (h *BrainstormHandler) pushToPipeline(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	topic := r.FormValue("topic")
	if topic == "" {
		http.Error(w, "Topic required", http.StatusBadRequest)
		return
	}

	run, err := h.queries.CreatePipelineRun(projectID)
	if err != nil {
		http.Error(w, "Failed to create run", http.StatusInternalServerError)
		return
	}
	h.queries.SetPipelineTopic(run.ID, topic)
	h.queries.AdvancePipelineRun(run.ID, "creating_pillar")

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
