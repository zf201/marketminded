package handlers

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/search"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/internal/types"
	"github.com/zanfridau/marketminded/web/templates"
)

var allSections = []string{
	"product_and_positioning", "audience", "voice_and_tone",
	"guidelines", "content_strategy",
}

var sectionDescriptions = map[string]string{
	"product_and_positioning": `What the company does, who they serve, industry, business model. Their unique value proposition, what makes them different from alternatives. Core problems they solve, why existing solutions fail. Key products/services, primary CTA (book a call, sign up, buy), and how aggressively content should sell vs. educate. Key competitors and how they differentiate.`,
	"audience": `Ideal customer profile: demographics, roles, company type/size (if B2B). Their top pain points in their own language. Where they spend time online, what content they consume. Behavioral insights:
- Push: frustrations driving them to seek a solution
- Pull: what attracts them to this specific solution
- Anxiety: concerns that might stop them from acting
- Habit: what keeps them stuck with the status quo`,
	"voice_and_tone": `How the brand communicates: personality traits, vocabulary level, sentence style, formality, humor, warmth. Characteristic phrases to use. How they relate to the audience (peer, mentor, authority). Words/phrases to always use and to never use. Ask for examples of writing they like, use THEIR words, not marketing theory. Include content role models: creators, brands, or accounts they admire and why.`,
	"content_strategy": `Define how the client's cornerstone content gets distributed across social platforms — what goes where and how many pieces per platform.`,
	"guidelines": `Content-specific rules: topics that are off-limits, formatting preferences, hashtag strategy, emoji usage, visual style. Anti-patterns: what should content NEVER look or sound like. Any brand-specific dos and don'ts not covered elsewhere.`,
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
	case strings.HasPrefix(rest, "profile/sections/") && r.Method == "POST":
		h.saveSection(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/message") && r.Method == "POST":
		h.saveSectionMessage(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/stream") && r.Method == "GET":
		h.streamSection(w, r, projectID, rest)
	case strings.HasPrefix(rest, "profile/") && r.Method == "GET":
		h.showSectionChat(w, r, projectID, rest)
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

	sections, _ := h.queries.ListProfileSections(projectID)
	sectionMap := make(map[string]string)
	for _, s := range sections {
		sectionMap[s.Section] = s.Content
	}

	// Determine which sections are locked (previous ones must be filled)
	cardViews := make([]templates.ProfileCardView, len(allSections))
	for i, name := range allSections {
		locked := false
		if i > 0 {
			// Check if all previous sections have content
			for j := 0; j < i; j++ {
				if sectionMap[allSections[j]] == "" {
					locked = true
					break
				}
			}
		}
		cardViews[i] = templates.ProfileCardView{
			Section: name,
			Title:   sectionTitle(name),
			Content: sectionMap[name],
			Locked:  locked,
			Index:   i,
		}
	}

	templates.ProfilePage(templates.ProfilePageData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Cards:       cardViews,
	}).Render(r.Context(), w)
}

func (h *ProfileHandler) showSectionChat(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "profile/product_and_positioning" or "profile/audience/message" etc
	section := strings.TrimPrefix(rest, "profile/")
	// Remove any trailing sub-paths
	if idx := strings.Index(section, "/"); idx != -1 {
		section = section[:idx]
	}

	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	// Get or create a chat for this section
	chat, _ := h.queries.GetOrCreateSectionChat(projectID, section)
	msgs, _ := h.queries.ListBrainstormMessages(chat.ID)

	msgViews := make([]templates.ProfileMsgView, len(msgs))
	for i, m := range msgs {
		msgViews[i] = templates.ProfileMsgView{Role: m.Role, Content: m.Content}
	}

	templates.ProfileSectionChatPage(templates.ProfileSectionChatData{
		ProjectID:    projectID,
		ProjectName:  project.Name,
		Section:      section,
		SectionTitle: sectionTitle(section),
		Messages:     msgViews,
	}).Render(r.Context(), w)
}

func (h *ProfileHandler) saveSectionMessage(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	section := h.parseSectionFromRest(rest)
	r.ParseForm()
	content := r.FormValue("content")
	if content == "" {
		http.Error(w, "Content required", http.StatusBadRequest)
		return
	}

	chat, _ := h.queries.GetOrCreateSectionChat(projectID, section)
	h.queries.AddBrainstormMessage(chat.ID, "user", content, "")
	w.WriteHeader(http.StatusOK)
}

func (h *ProfileHandler) parseSectionFromRest(rest string) string {
	// rest = "profile/product_and_positioning/message" or "profile/audience/stream"
	section := strings.TrimPrefix(rest, "profile/")
	if idx := strings.Index(section, "/"); idx != -1 {
		section = section[:idx]
	}
	return section
}

func (h *ProfileHandler) streamSection(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	sectionName := h.parseSectionFromRest(rest)
	project, _ := h.queries.GetProject(projectID)
	chat, _ := h.queries.GetOrCreateSectionChat(projectID, sectionName)
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

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are an expert content marketing strategist. You are helping build the **%s** section of a client profile for "%s".

## What this section needs
%s

## Current profile state (for context)
%s

## Your workflow
1. Ask questions to understand the client well enough to write a thorough section. Be specific. Dig deeper on vague answers.
2. When you have enough, call update_section with section "%s" and your writeup.
3. If the user rejects, ask what needs changing, revise, and propose again.
4. Focus ONLY on this section. Don't propose updates to other sections.

## Rules
- NEVER fabricate or assume details. If you need to guess, ask: "I'd need to make some assumptions about X — should I draft it or can you tell me more?"
- Write specific prose about THIS client. If it could apply to any company, it's too generic.
- Use fetch_url when the user shares a link. Use web_search to research competitors or context.
- Be conversational and efficient. Don't repeat what the user said.

## Writing style
- Write like a human. NEVER sound like AI-generated content.
- NEVER use em dashes. Use commas, periods, or restructure.
- Zero emojis in section proposals. One max per chat message.
- Avoid: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness", "at the end of the day", "it's worth noting".
- Short, direct sentences. Vary length. Sound like a person, not a press release.`,
		time.Now().Format("January 2, 2006"),
		sectionTitle(sectionName), project.Name,
		sectionDescriptions[sectionName],
		profileState.String(),
		sectionName)

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

	temp := 0.3
	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, func(string) error { return nil }, &temp)
	if err != nil {
		sendEvent(map[string]string{"type": "error", "error": err.Error()})
		return
	}

	h.queries.AddBrainstormMessage(chat.ID, "assistant", fullResponse, "")

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

var sectionDisplayTitles = map[string]string{
	"content_strategy": "Social Content Strategy",
}

func sectionTitle(s string) string {
	if t, ok := sectionDisplayTitles[s]; ok {
		return t
	}
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
