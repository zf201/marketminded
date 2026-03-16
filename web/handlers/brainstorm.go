package handlers

import (
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/types"
	"github.com/zanfridau/marketminded/web/templates"
)

type BrainstormHandler struct {
	queries *store.Queries
	ai      types.AIClient
	model   string
}

func NewBrainstormHandler(q *store.Queries, ai types.AIClient, model string) *BrainstormHandler {
	return &BrainstormHandler{queries: q, ai: ai, model: model}
}

func (h *BrainstormHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "brainstorm" && r.Method == "GET":
		h.list(w, r, projectID)
	case rest == "brainstorm" && r.Method == "POST":
		h.createChat(w, r, projectID)
	case strings.HasSuffix(rest, "/message") && r.Method == "POST":
		h.sendMessage(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/push") && r.Method == "POST":
		h.pushToPipeline(w, r, projectID, rest)
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
	chat, err := h.queries.CreateBrainstormChat(projectID, title)
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

func (h *BrainstormHandler) sendMessage(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	chatID := h.parseChatID(rest)
	r.ParseForm()
	content := r.FormValue("content")
	if content == "" {
		http.Error(w, "Content required", http.StatusBadRequest)
		return
	}

	// Save user message
	h.queries.AddBrainstormMessage(chatID, "user", content)

	// Build context and get AI response
	project, _ := h.queries.GetProject(projectID)
	msgs, _ := h.queries.ListBrainstormMessages(chatID)

	voiceProfile := ""
	if project.VoiceProfile != nil {
		voiceProfile = *project.VoiceProfile
	}

	systemPrompt := fmt.Sprintf(`You are a content brainstorming assistant for the project "%s". %s

Help the user brainstorm content ideas, angles, and strategies. Be creative and specific.`, project.Name, voiceProfile)

	aiMsgs := []types.Message{{Role: "system", Content: systemPrompt}}
	for _, m := range msgs {
		aiMsgs = append(aiMsgs, types.Message{Role: m.Role, Content: m.Content})
	}

	response, err := h.ai.Complete(r.Context(), h.model, aiMsgs)
	if err != nil {
		response = "Error: " + err.Error()
	}

	h.queries.AddBrainstormMessage(chatID, "assistant", response)

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/brainstorm/%d", projectID, chatID), http.StatusSeeOther)
}

func (h *BrainstormHandler) pushToPipeline(w http.ResponseWriter, r *http.Request, projectID int64, _ string) {
	r.ParseForm()
	topic := r.FormValue("topic")
	if topic == "" {
		http.Error(w, "Topic required", http.StatusBadRequest)
		return
	}

	// Create a pipeline run with the topic pre-set, skip ideation
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
	// rest = "brainstorm/123" or "brainstorm/123/message" etc
	parts := strings.Split(strings.TrimPrefix(rest, "brainstorm/"), "/")
	id, err := strconv.ParseInt(parts[0], 10, 64)
	if err != nil {
		return 0
	}
	return id
}
