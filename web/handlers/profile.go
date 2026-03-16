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
	"product_and_positioning", "audience", "voice_and_tone",
	"content_strategy", "guidelines",
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

	systemPrompt := fmt.Sprintf(`You are an expert content marketing strategist building a client profile. This profile is the foundation for ALL content creation — blog posts, social media, email, newsletters. Every section must be specific enough that a writer could produce on-brand content without asking further questions.

## Profile sections (5 total)

Work through these ONE AT A TIME, in order. Do NOT move to the next section until the current one is ACCEPTED.

### 1. Product & Positioning (product_and_positioning)
What the company does, who they serve, industry, business model. Their unique value proposition — what makes them different from alternatives. Core problems they solve, why existing solutions fail. Key products/services, primary CTA (book a call, sign up, buy), and how aggressively content should sell vs. educate. Key competitors and how they differentiate.

### 2. Audience (audience)
Ideal customer profile: demographics, roles, company type/size (if B2B). Their top pain points in their own language. Where they spend time online, what content they consume. Behavioral insights:
- Push: frustrations driving them to seek a solution
- Pull: what attracts them to this specific solution
- Anxiety: concerns that might stop them from acting
- Habit: what keeps them stuck with the status quo

### 3. Voice & Tone (voice_and_tone)
How the brand communicates: personality traits, vocabulary level, sentence style, formality, humor, warmth. Characteristic phrases to use. How they relate to the audience (peer, mentor, authority). Words/phrases to always use and to never use. Ask for examples of writing they like — use THEIR words, not marketing theory. Include content role models: creators, brands, or accounts they admire and why.

### 4. Content Strategy (content_strategy)
Content goals (traffic, leads, authority, community). Which platforms to post on and why. Content formats per platform (blog, carousel, reel, thread, newsletter). Posting frequency per platform. 3-5 content pillars: recurring topic categories with example post ideas for each. For each pillar, include both "searchable" content (captures existing demand via SEO) and "shareable" content (creates demand through insights, stories, original takes).

IMPORTANT: The core of this strategy is the "content waterfall" approach. One cornerstone piece of content (like a blog post or video) gets repurposed into many smaller pieces across platforms. Define the client's waterfall flows clearly. For example: "Each blog post becomes 2 Instagram posts, 2 reels, 1 LinkedIn post, 1 X post, and 1 X thread." Or: "Each YouTube video becomes a blog post, 3 shorts, 2 reels, and a LinkedIn post." There may be multiple waterfall patterns depending on the type of cornerstone content. Be specific about what goes where and how many.

### 5. Guidelines (guidelines)
Content-specific rules: topics that are off-limits, formatting preferences, hashtag strategy, emoji usage, visual style. Anti-patterns: what should content NEVER look or sound like. Any brand-specific dos and don'ts not covered elsewhere.

## Current profile state for "%s"

%s

## Your workflow — STRICT

For each section, follow this exact loop:
1. **Gather** — Ask questions to understand the client. Be specific. Don't accept vague answers — dig deeper.
2. **Propose** — When you have enough, call update_section with your writeup. Be thorough and specific to THIS client.
3. **Wait** — The user will accept or reject. Do NOT continue to the next section.
4. **If rejected** — Ask what needs changing, revise, and propose again. Stay on this section until it's accepted.
5. **If accepted** — THEN and only then, move to the next section.

CRITICAL RULES:
- NEVER propose a section you haven't gathered enough information for. If you're unsure, ask first.
- NEVER fabricate or assume details. If you need to guess, say so explicitly: "I'd need to make some assumptions about X — should I draft it or can you tell me more?"
- NEVER move to the next section before the current one is accepted. If a section is rejected, stay on it.
- Write specific prose about THIS client. If your writeup could apply to any random company, it's too generic — rewrite it.
- Use fetch_url when the user shares a link. Use web_search to research competitors or industry context.
- Be conversational and efficient. Don't repeat what the user said. Don't explain marketing theory.

WRITING STYLE — THIS APPLIES TO EVERYTHING YOU WRITE INCLUDING SECTION PROPOSALS:
- Write like a human. NEVER sound like AI-generated content.
- NEVER use em dashes (—). Use commas, periods, or restructure the sentence instead.
- NEVER overuse emojis. Zero emojis in profile sections. In chat, one max per message if it fits naturally.
- Avoid AI clichés: "dive into", "leverage", "elevate", "streamline", "at the end of the day", "it's worth noting", "game-changer", "unlock", "harness the power of".
- Use short, direct sentences. Vary sentence length. Sound like a person talking, not a press release.`, project.Name, profileState.String())

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
	fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, &temp)
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
