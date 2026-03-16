package handlers

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/search"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/internal/types"
	"github.com/zanfridau/marketminded/web/templates"
)

var allSections = []string{
	"business", "audience", "voice", "tone", "strategy",
	"pillars", "guidelines", "competitors", "inspiration", "offers",
}

type ProfileHandler struct {
	queries     *store.Queries
	aiClient    *ai.Client
	braveClient *search.BraveClient
	model       func() string
}

func NewProfileHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, model func() string) *ProfileHandler {
	return &ProfileHandler{queries: q, aiClient: aiClient, braveClient: braveClient, model: model}
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

When you learn something relevant to a section, use the update_section tool to propose an update. The user will see the proposal and can accept or reject it.

## Rules

- Propose one section update at a time. If you have updates for multiple sections from a single message, call update_section multiple times.
- Always rewrite the full section content when updating — do not write diffs or "add this to existing."
- After proposing updates, continue the conversation. Ask follow-up questions to fill gaps in other sections.
- Do not make up information. Only propose updates based on what the user has actually told you.
- Be conversational and concise. Don't lecture. Don't repeat back everything the user said.
- If the user gives you a large dump of info (like a website paste), process it methodically — propose the most important sections first.
- If a proposal is rejected, acknowledge it briefly and move on. You'll see rejected proposals in the chat history.
- You have access to web search and URL fetching tools. Use them when the user shares a URL or asks you to research something.`, project.Name, profileState.String())

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

	// Build tools
	toolList := []ai.Tool{
		tools.NewFetchTool(),
		tools.NewSearchTool(),
		tools.NewUpdateSectionTool(),
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
		case "update_section":
			return tools.ExecuteUpdateSection(ctx, args)
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
			case "update_section":
				summary = "Proposing update..."
			}
			sendEvent(map[string]string{"type": "tool_start", "tool": event.Tool, "summary": summary})
		case "tool_result":
			summary := event.Summary
			if len(summary) > 200 {
				summary = summary[:200] + "..."
			}
			sendEvent(map[string]string{"type": "tool_result", "tool": event.Tool, "summary": summary})
		}

		// Special handling for update_section: emit proposal event
		if event.Tool == "update_section" && event.Type == "tool_result" {
			args, err := tools.ParseUpdateArgs(event.Args)
			if err == nil {
				sendEvent(map[string]string{"type": "proposal", "section": args.Section, "content": args.Content})
			}
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

	h.queries.AddBrainstormMessage(chat.ID, "assistant", fullResponse)

	sendEvent(map[string]string{"type": "done"})
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
