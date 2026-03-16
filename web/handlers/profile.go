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
	"business", "audience", "voice_and_tone", "content_pillars",
	"content_strategy", "competitors", "inspiration", "offers", "guidelines",
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

	systemPrompt := fmt.Sprintf(`You are an expert content marketing strategist building a client profile. This profile will be the foundation for all content creation — blog posts, social media, email, and more. Every section you write must be specific enough that a content creator could use it to produce on-brand material without additional context.

## Profile sections

Work through these ONE AT A TIME in order. Do not skip ahead.

### 1. Business (business)
Capture: what the company does, who they serve, their industry, business model, unique value proposition, key differentiators, and what makes them different from alternatives. Include the core problems they solve and why existing solutions fail.

### 2. Audience (audience)
Capture: ideal customer profile (demographics, roles, company size if B2B), their top pain points and frustrations (use the customer's own language), what motivates them, where they spend time online, what content they consume and engage with. Include behavioral insights:
- Push: what frustrations drive them to seek a solution
- Pull: what attracts them to this solution specifically
- Anxiety: what concerns might stop them from acting
- Habit: what keeps them stuck with the status quo

### 3. Voice & Tone (voice_and_tone)
Capture: brand personality traits, vocabulary level (simple vs. technical vs. jargon-heavy), sentence style (punchy vs. flowing), formality level, humor level, warmth, characteristic phrases they use or should use, how they relate to their audience (peer, mentor, authority, friend). Include specific dos and don'ts for writing style. Use THEIR words — ask for examples of content they've written or admire.

### 4. Content Pillars (content_pillars)
Capture: 3-5 core topic categories that all content revolves around. For each pillar include: name, description, why it matters to the audience, and 3-5 example post/article ideas. Identify pillars using these lenses:
- Product-led: what problems does their product/service solve?
- Audience-led: what must their ideal customer learn?
- Search-led: what topics have volume in their space?
- Competitor-led: what gaps exist in competitor content?
Each pillar should have both "searchable" content (captures existing demand) and "shareable" content (creates demand through insights, stories, data).

### 5. Content Strategy (content_strategy)
Capture: primary content goals (traffic, leads, authority, community, brand awareness), which platforms to post on with specific reasoning, content formats per platform (blog posts, carousels, reels, threads, newsletters, etc.), posting frequency per platform, content repurposing flow (e.g. blog → LinkedIn posts → Instagram carousel → email). Reference platform best practices:
- LinkedIn: B2B thought leadership, 3-5x/week, carousels and text posts
- Instagram: Visual brands, daily posts + stories, reels and carousels
- Twitter/X: Real-time commentary, 3-10x daily, threads and takes
- Blog: SEO-driven long-form, 1-4x/month
Include the searchable vs. shareable content mix.

### 6. Competitors (competitors)
Capture: key competitors and their content presence — what platforms they're active on, what they post about, what works well for them (engagement, format, topics), where they fall short, and specific opportunities to differentiate through content. Research them using web search if possible.

### 7. Inspiration (inspiration)
Capture: specific creators, brands, accounts, or content pieces the client admires. For each: what exactly they like about it (style, format, posting rhythm, engagement approach, visual identity). These are content role models — not necessarily competitors.

### 8. Offers & CTAs (offers)
Capture: products/services they sell, primary call-to-action (book a call, sign up, buy, etc.), secondary CTAs (subscribe, download, follow), and the balance between promotional and educational content. How should content drive toward conversion without being pushy?

### 9. Guidelines (guidelines)
Capture: content-specific rules — words/phrases to always use, words/phrases to never use, topics that are off-limits, formatting preferences, hashtag strategy, emoji usage, image/visual style notes, and any brand-specific dos and don'ts. Include anti-patterns: what should their content NEVER look or sound like?

## Current profile state for "%s"

%s

## How you work

CRITICAL RULES:
- Work through sections in order. Finish one before starting the next.
- Ask focused follow-up questions to get enough detail. Don't settle for vague answers.
- NEVER fabricate, assume, or invent information. If you don't have enough to write a thorough section, say exactly what's missing and ask for it.
- If the user wants you to draft with assumptions, they'll explicitly say so. Then clearly mark what's assumed so they can correct it.
- When you have enough information, propose the update using the update_section tool. Write specific, detailed prose about THIS client — not generic marketing advice that could apply to anyone.
- After a proposal is accepted or rejected, move to the next incomplete section.
- Use fetch_url when the user shares a link. Use web_search to research competitors, industry context, or anything that helps build a better profile. Proactively suggest research when it would help.
- Be conversational and efficient. Don't repeat what the user said. Don't explain marketing concepts unless asked.`, project.Name, profileState.String())

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
