package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/types"
	"github.com/zanfridau/marketminded/web/templates"
)

type ContextHandler struct {
	queries  *store.Queries
	aiClient *ai.Client
	model    func() string
}

func NewContextHandler(q *store.Queries, aiClient *ai.Client, model func() string) *ContextHandler {
	return &ContextHandler{queries: q, aiClient: aiClient, model: model}
}

func (h *ContextHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "context/new" && r.Method == "GET":
		h.newItem(w, r, projectID)
	case rest == "context/new" && r.Method == "POST":
		h.createItem(w, r, projectID)
	case strings.HasSuffix(rest, "/message") && r.Method == "POST":
		h.saveMessage(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/stream") && r.Method == "GET":
		h.stream(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/save") && r.Method == "POST":
		h.saveContent(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/delete") && r.Method == "POST":
		h.deleteItem(w, r, projectID, rest)
	case strings.HasPrefix(rest, "context/"):
		h.showItem(w, r, projectID, rest)
	}
}

func (h *ContextHandler) newItem(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, _ := h.queries.GetProject(projectID)
	templates.ContextNewPage(templates.ContextNewData{
		ProjectID:   projectID,
		ProjectName: project.Name,
	}).Render(r.Context(), w)
}

func (h *ContextHandler) createItem(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	title := r.FormValue("title")
	if title == "" {
		title = time.Now().Format("Jan 2, 2006")
	}
	item, err := h.queries.CreateContextItem(projectID, title)
	if err != nil {
		http.Error(w, "Failed to create", http.StatusInternalServerError)
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/context/%d", projectID, item.ID), http.StatusSeeOther)
}

func (h *ContextHandler) showItem(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	itemID := h.parseItemID(rest)
	if itemID == 0 {
		http.NotFound(w, r)
		return
	}
	project, _ := h.queries.GetProject(projectID)
	item, err := h.queries.GetContextItem(itemID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	// Get chat for this context item
	chat, _ := h.queries.GetOrCreateContextChat(projectID, itemID)
	msgs, _ := h.queries.ListBrainstormMessages(chat.ID)

	msgViews := make([]templates.ContextMsgView, len(msgs))
	for i, m := range msgs {
		msgViews[i] = templates.ContextMsgView{Role: m.Role, Content: m.Content}
	}

	templates.ContextItemPage(templates.ContextItemData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		ItemID:      itemID,
		Title:       item.Title,
		Content:     item.Content,
		Messages:    msgViews,
	}).Render(r.Context(), w)
}

func (h *ContextHandler) saveMessage(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	itemID := h.parseItemID(rest)
	r.ParseForm()
	content := r.FormValue("content")
	if content == "" {
		http.Error(w, "Content required", http.StatusBadRequest)
		return
	}
	chat, _ := h.queries.GetOrCreateContextChat(projectID, itemID)
	h.queries.AddBrainstormMessage(chat.ID, "user", content)
	w.WriteHeader(http.StatusOK)
}

func (h *ContextHandler) stream(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	itemID := h.parseItemID(rest)
	item, _ := h.queries.GetContextItem(itemID)
	chat, _ := h.queries.GetOrCreateContextChat(projectID, itemID)
	msgs, _ := h.queries.ListBrainstormMessages(chat.ID)

	profile, _ := h.queries.BuildProfileString(projectID)

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are helping qualify a piece of context for a content marketing project.

The user will share raw information (a link, a note, an announcement, competitive intel, etc.). Your job is to:
1. Understand what it is
2. Ask clarifying questions if needed
3. Help refine it into a clear, useful context item that content creators can reference

Client profile (for context):
%s

Current item title: "%s"
Current saved content: %s

When the information is clear and refined, tell the user it's ready to save. They'll click the Save button.

Be concise. Don't lecture. Help them turn raw info into something useful.`, time.Now().Format("January 2, 2006"), profile, item.Title, func() string {
		if item.Content == "" {
			return "(empty)"
		}
		return item.Content
	}())

	aiMsgs := []types.Message{{Role: "system", Content: systemPrompt}}
	for _, m := range msgs {
		aiMsgs = append(aiMsgs, types.Message{Role: m.Role, Content: m.Content})
	}

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

	sendChunk := func(chunk string) error {
		sendEvent(map[string]string{"type": "chunk", "chunk": chunk})
		return nil
	}

	fullResponse, err := h.aiClient.Stream(r.Context(), h.model(), aiMsgs, sendChunk)
	if err != nil {
		sendEvent(map[string]string{"type": "error", "error": err.Error()})
		return
	}

	h.queries.AddBrainstormMessage(chat.ID, "assistant", fullResponse)
	sendEvent(map[string]string{"type": "done"})
}

func (h *ContextHandler) saveContent(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	itemID := h.parseItemID(rest)
	r.ParseForm()
	title := r.FormValue("title")
	content := r.FormValue("content")
	h.queries.UpdateContextItem(itemID, title, content)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d", projectID), http.StatusSeeOther)
}

func (h *ContextHandler) deleteItem(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	itemID := h.parseItemID(rest)
	h.queries.DeleteContextItem(itemID)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d", projectID), http.StatusSeeOther)
}

func (h *ContextHandler) parseItemID(rest string) int64 {
	// rest = "context/123" or "context/123/message" etc
	parts := strings.Split(strings.TrimPrefix(rest, "context/"), "/")
	id, err := strconv.ParseInt(parts[0], 10, 64)
	if err != nil {
		return 0
	}
	return id
}
