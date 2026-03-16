package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"

	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/types"
	"github.com/zanfridau/marketminded/web/templates"
)

var allSections = []string{
	"business", "audience", "voice", "tone", "strategy",
	"pillars", "guidelines", "competitors", "inspiration", "offers",
}

type ProfileHandler struct {
	queries *store.Queries
	ai      types.AIClient
	model   func() string
}

func NewProfileHandler(q *store.Queries, ai types.AIClient, model func() string) *ProfileHandler {
	return &ProfileHandler{queries: q, ai: ai, model: model}
}

func (h *ProfileHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "profile" && r.Method == "GET":
		h.show(w, r, projectID)
	case rest == "profile/message" && r.Method == "POST":
		h.saveMessage(w, r, projectID)
	case rest == "profile/stream" && r.Method == "GET":
		h.stream(w, r, projectID)
	case strings.HasPrefix(rest, "profile/sections/") && r.Method == "POST":
		h.saveSection(w, r, projectID, rest)
	default:
		http.NotFound(w, r)
	}
}

func (h *ProfileHandler) show(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	chat, _ := h.queries.GetOrCreateProfileChat(projectID)
	msgs, _ := h.queries.ListBrainstormMessages(chat.ID)
	sections, _ := h.queries.ListProfileSections(projectID)

	sectionMap := make(map[string]string)
	for _, s := range sections {
		sectionMap[s.Section] = s.Content
	}

	cardViews := make([]templates.ProfileCardView, len(allSections))
	for i, name := range allSections {
		cardViews[i] = templates.ProfileCardView{
			Section: name,
			Title:   sectionTitle(name),
			Content: sectionMap[name],
		}
	}

	msgViews := make([]templates.ProfileMsgView, len(msgs))
	for i, m := range msgs {
		msgViews[i] = templates.ProfileMsgView{Role: m.Role, Content: m.Content}
	}

	templates.ProfilePage(templates.ProfilePageData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Cards:       cardViews,
		Messages:    msgViews,
	}).Render(r.Context(), w)
}

func (h *ProfileHandler) saveMessage(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	content := r.FormValue("content")
	if content == "" {
		http.Error(w, "Content required", http.StatusBadRequest)
		return
	}

	chat, _ := h.queries.GetOrCreateProfileChat(projectID)
	h.queries.AddBrainstormMessage(chat.ID, "user", content)
	w.WriteHeader(http.StatusOK)
}

func (h *ProfileHandler) stream(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, _ := h.queries.GetProject(projectID)
	chat, _ := h.queries.GetOrCreateProfileChat(projectID)
	msgs, _ := h.queries.ListBrainstormMessages(chat.ID)

	// Build current profile state for system prompt
	var profileState strings.Builder
	for _, name := range allSections {
		section, err := h.queries.GetProfileSection(projectID, name)
		if err != nil || section.Content == "" {
			fmt.Fprintf(&profileState, "- **%s**: (empty)\n", sectionTitle(name))
		} else {
			fmt.Fprintf(&profileState, "- **%s**: %s\n", sectionTitle(name), section.Content)
		}
	}

	systemPrompt := fmt.Sprintf(`You are a brand profile builder. Your job is to learn about this client through natural conversation and build out their content marketing profile.

You have 10 profile sections to fill:

1. **Business** — What the company does, who they serve, their industry, and what makes them different
2. **Audience** — Who they're trying to reach: demographics, roles, pain points, aspirations, and what content they consume
3. **Voice** — How the brand sounds: personality traits, vocabulary style, sentence patterns, characteristic phrases
4. **Tone** — The emotional register: formality level, humor, warmth, persuasion approach, how they relate to the audience
5. **Strategy** — Content goals (awareness, leads, authority), which platforms to publish on, posting frequency per platform
6. **Pillars** — The 3-5 core topic categories all content revolves around
7. **Guidelines** — Specific rules: words/phrases to always use or avoid, formatting preferences, brand-specific dos and don'ts
8. **Competitors** — Key competitors, what they do well in content, where they fall short, opportunities to differentiate
9. **Inspiration** — Creators, brands, or specific content the client admires and wants to emulate (not necessarily competitors)
10. **Offers** — Products/services they sell, primary call-to-action, secondary CTAs, what content should ultimately drive people toward

## Current profile state for "%s"

%s

## How to propose updates

When you learn something relevant to a section, propose an update using this exact format:

[UPDATE:section_name]
Write the full updated content for this section here.
Use clear, natural prose. Not JSON. Not raw bullet lists unless they genuinely fit.
If the section already has content, rewrite it to incorporate both old and new information.
[/UPDATE]

## Rules

- Propose one section update at a time. If you have updates for multiple sections from a single message, include them all in your response but each as a separate [UPDATE] block.
- Always rewrite the full section content when updating — do not write diffs or "add this to existing."
- After proposing updates, continue the conversation. Ask follow-up questions to fill gaps in other sections.
- Do not make up information. Only propose updates based on what the user has actually told you.
- Be conversational and concise. Don't lecture. Don't repeat back everything the user said.
- If the user gives you a large dump of info (like a website paste), process it methodically — propose the most important sections first.
- If a proposal is rejected, acknowledge it briefly and move on. You'll see rejected proposals in the chat history.`, project.Name, profileState.String())

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

	sendChunk := func(chunk string) error {
		data, _ := json.Marshal(map[string]string{"chunk": chunk})
		fmt.Fprintf(w, "data: %s\n\n", data)
		flusher.Flush()
		return nil
	}

	fullResponse, err := h.ai.Stream(r.Context(), h.model(), aiMsgs, sendChunk)
	if err != nil {
		errData, _ := json.Marshal(map[string]string{"error": err.Error()})
		fmt.Fprintf(w, "data: %s\n\n", errData)
		flusher.Flush()
		return
	}

	h.queries.AddBrainstormMessage(chat.ID, "assistant", fullResponse)

	doneData, _ := json.Marshal(map[string]bool{"done": true})
	fmt.Fprintf(w, "data: %s\n\n", doneData)
	flusher.Flush()
}

func (h *ProfileHandler) saveSection(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "profile/sections/voice"
	section := strings.TrimPrefix(rest, "profile/sections/")
	r.ParseForm()
	content := r.FormValue("content")
	h.queries.UpsertProfileSection(projectID, section, content)
	w.WriteHeader(http.StatusOK)
}

func sectionTitle(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
