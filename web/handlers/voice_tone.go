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
)

type VoiceToneHandler struct {
	queries     *store.Queries
	aiClient    *ai.Client
	braveClient *search.BraveClient
	model       func() string
}

func NewVoiceToneHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, model func() string) *VoiceToneHandler {
	return &VoiceToneHandler{queries: q, aiClient: aiClient, braveClient: braveClient, model: model}
}

func (h *VoiceToneHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "context" && r.Method == "GET":
		h.getContext(w, r, projectID)
	case rest == "save-context" && r.Method == "POST":
		h.saveContext(w, r, projectID)
	case rest == "profile" && r.Method == "GET":
		h.getProfile(w, r, projectID)
	case rest == "profile" && r.Method == "POST":
		h.saveProfile(w, r, projectID)
	case rest == "generate" && r.Method == "GET":
		h.streamGenerate(w, r, projectID)
	default:
		http.NotFound(w, r)
	}
}

func (h *VoiceToneHandler) getContext(w http.ResponseWriter, r *http.Request, projectID int64) {
	var blogURLs, likedArticles, inspiration []store.SourceURL

	if raw, err := h.queries.GetProjectSetting(projectID, "voice_tone_blog_urls"); err == nil && raw != "" {
		json.Unmarshal([]byte(raw), &blogURLs)
	}
	if raw, err := h.queries.GetProjectSetting(projectID, "voice_tone_liked_articles"); err == nil && raw != "" {
		json.Unmarshal([]byte(raw), &likedArticles)
	}
	if raw, err := h.queries.GetProjectSetting(projectID, "voice_tone_inspiration"); err == nil && raw != "" {
		json.Unmarshal([]byte(raw), &inspiration)
	}

	notes, _ := h.queries.GetProjectSetting(projectID, "profile_context_voice_and_tone")

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{
		"blog_urls":       blogURLs,
		"liked_articles":  likedArticles,
		"inspiration":     inspiration,
		"notes":           notes,
	})
}

func (h *VoiceToneHandler) saveContext(w http.ResponseWriter, r *http.Request, projectID int64) {
	var body struct {
		BlogURLs      []store.SourceURL `json:"blog_urls"`
		LikedArticles []store.SourceURL `json:"liked_articles"`
		Inspiration   []store.SourceURL `json:"inspiration"`
		Notes         string            `json:"notes"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}

	marshalAndSet := func(key string, urls []store.SourceURL) {
		if urls == nil {
			urls = []store.SourceURL{}
		}
		data, _ := json.Marshal(urls)
		h.queries.SetProjectSetting(projectID, key, string(data))
	}

	marshalAndSet("voice_tone_blog_urls", body.BlogURLs)
	marshalAndSet("voice_tone_liked_articles", body.LikedArticles)
	marshalAndSet("voice_tone_inspiration", body.Inspiration)
	h.queries.SetProjectSetting(projectID, "profile_context_voice_and_tone", body.Notes)

	w.WriteHeader(http.StatusOK)
}

func (h *VoiceToneHandler) getProfile(w http.ResponseWriter, r *http.Request, projectID int64) {
	vt, err := h.queries.GetVoiceToneProfile(projectID)
	if err != nil {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte("{}"))
		return
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{
		"voice_analysis":    vt.VoiceAnalysis,
		"content_types":     vt.ContentTypes,
		"should_avoid":      vt.ShouldAvoid,
		"should_use":        vt.ShouldUse,
		"style_inspiration": vt.StyleInspiration,
	})
}

func (h *VoiceToneHandler) saveProfile(w http.ResponseWriter, r *http.Request, projectID int64) {
	var body struct {
		VoiceAnalysis    string `json:"voice_analysis"`
		ContentTypes     string `json:"content_types"`
		ShouldAvoid      string `json:"should_avoid"`
		ShouldUse        string `json:"should_use"`
		StyleInspiration string `json:"style_inspiration"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}

	err := h.queries.UpsertVoiceToneProfile(projectID, store.VoiceToneProfile{
		VoiceAnalysis:    body.VoiceAnalysis,
		ContentTypes:     body.ContentTypes,
		ShouldAvoid:      body.ShouldAvoid,
		ShouldUse:        body.ShouldUse,
		StyleInspiration: body.StyleInspiration,
	})
	if err != nil {
		http.Error(w, "Failed to save profile", http.StatusInternalServerError)
		return
	}

	w.WriteHeader(http.StatusOK)
}

func (h *VoiceToneHandler) streamGenerate(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, _ := h.queries.GetProject(projectID)

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

	sendEvent(map[string]string{"type": "status", "status": "Gathering context..."})

	// Gather product & positioning content
	var productContent string
	if ps, err := h.queries.GetProfileSection(projectID, "product_and_positioning"); err == nil {
		productContent = ps.Content
	}

	// Gather audience personas
	audienceStr, _ := h.queries.BuildAudienceString(projectID)

	// Load URL lists
	var blogURLs, likedArticles, inspirationURLs []store.SourceURL
	if raw, err := h.queries.GetProjectSetting(projectID, "voice_tone_blog_urls"); err == nil && raw != "" {
		json.Unmarshal([]byte(raw), &blogURLs)
	}
	if raw, err := h.queries.GetProjectSetting(projectID, "voice_tone_liked_articles"); err == nil && raw != "" {
		json.Unmarshal([]byte(raw), &likedArticles)
	}
	if raw, err := h.queries.GetProjectSetting(projectID, "voice_tone_inspiration"); err == nil && raw != "" {
		json.Unmarshal([]byte(raw), &inspirationURLs)
	}

	// Context notes and memory
	contextNotes, _ := h.queries.GetProjectSetting(projectID, "profile_context_voice_and_tone")
	memorySetting, _ := h.queries.GetProjectSetting(projectID, "memory")

	// Existing voice tone profile
	existingVT, _ := h.queries.GetVoiceToneProfile(projectID)

	// Pre-fetch all URLs
	type fetchedURL struct {
		label   string
		url     string
		notes   string
		content string
	}
	var fetchedBlogs, fetchedLiked, fetchedInspiration []fetchedURL

	fetchURLs := func(urls []store.SourceURL, label string) []fetchedURL {
		var results []fetchedURL
		for _, u := range urls {
			sendEvent(map[string]string{"type": "status", "status": fmt.Sprintf("Fetching %s...", u.URL)})
			fetchArgs, _ := json.Marshal(map[string]string{"url": u.URL})
			result, err := tools.ExecuteFetch(r.Context(), string(fetchArgs))
			content := ""
			if err != nil {
				content = fmt.Sprintf("(fetch failed: %s)", err.Error())
			} else {
				content = result
			}
			results = append(results, fetchedURL{label: label, url: u.URL, notes: u.Notes, content: content})
		}
		return results
	}

	fetchedBlogs = fetchURLs(blogURLs, "Company Blog Posts")
	fetchedLiked = fetchURLs(likedArticles, "Liked Articles")
	fetchedInspiration = fetchURLs(inspirationURLs, "Inspiration Sources")

	sendEvent(map[string]string{"type": "status", "status": "Analyzing voice & tone..."})

	// Build system prompt
	var systemPrompt strings.Builder
	fmt.Fprintf(&systemPrompt, "Today's date: %s\n\n", time.Now().Format("January 2, 2006"))
	fmt.Fprintf(&systemPrompt, "You are an expert brand voice analyst building a structured voice & tone profile for \"%s\".\n\n", project.Name)

	if productContent != "" {
		fmt.Fprintf(&systemPrompt, "## Product & Positioning\n%s\n\n", productContent)
	}
	if audienceStr != "" {
		fmt.Fprintf(&systemPrompt, "## Audience Personas\n%s\n\n", audienceStr)
	}

	writeFetchedSection := func(title string, fetched []fetchedURL) {
		if len(fetched) == 0 {
			return
		}
		fmt.Fprintf(&systemPrompt, "## %s\n", title)
		for _, f := range fetched {
			fmt.Fprintf(&systemPrompt, "\n### Source: %s\n", f.url)
			if f.notes != "" {
				fmt.Fprintf(&systemPrompt, "Notes: %s\n", f.notes)
			}
			fmt.Fprintf(&systemPrompt, "%s\n", f.content)
		}
		systemPrompt.WriteString("\n")
	}

	writeFetchedSection("Company Blog Posts", fetchedBlogs)
	writeFetchedSection("Liked Articles - posts the brand considers good examples", fetchedLiked)
	writeFetchedSection("Inspiration Sources - external writing styles the brand admires", fetchedInspiration)

	if existingVT != nil {
		systemPrompt.WriteString("## Existing Voice & Tone Profile (review and improve)\n")
		fmt.Fprintf(&systemPrompt, "### Voice Analysis\n%s\n\n", existingVT.VoiceAnalysis)
		fmt.Fprintf(&systemPrompt, "### Content Types\n%s\n\n", existingVT.ContentTypes)
		fmt.Fprintf(&systemPrompt, "### Should Avoid\n%s\n\n", existingVT.ShouldAvoid)
		fmt.Fprintf(&systemPrompt, "### Should Use\n%s\n\n", existingVT.ShouldUse)
		fmt.Fprintf(&systemPrompt, "### Style Inspiration\n%s\n\n", existingVT.StyleInspiration)
	}

	if contextNotes != "" {
		fmt.Fprintf(&systemPrompt, "## Additional Context Notes\n%s\n\n", contextNotes)
	}
	if memorySetting != "" {
		fmt.Fprintf(&systemPrompt, "## Important Rules and Facts\n%s\n\n", memorySetting)
	}

	systemPrompt.WriteString(`## Your Task
Analyze all the provided sources and produce a structured voice & tone profile with 5 sections:

1. **Voice Analysis** - Brand personality, formality level, warmth, how they relate to the reader
2. **Content Types** - What content approaches the brand uses
3. **Should Avoid** - Words, phrases, patterns, and tones to never use
4. **Should Use** - Characteristic vocabulary, phrases, sentence patterns
5. **Style Inspiration** - Writing style patterns observed from the inspiration sources

Use web_search to research the brand's online presence if needed. When done, call submit_voice_tone with all 5 sections.

## Rules
- ALWAYS write in English.
- Analyze STYLE, not content. Focus on HOW they write, not WHAT they write about.
- Be specific to THIS brand. Generic voice guidelines are useless.
- Include concrete examples from the source material where possible.
- NEVER use em dashes. Use commas, periods, or restructure.
- Write like a human. Short, direct sentences. Vary length.
`)

	// Build tools
	submitVoiceToneTool := ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_voice_tone",
			Description: "Submit the structured voice & tone analysis.",
			Parameters:  json.RawMessage(`{"type":"object","properties":{"voice_analysis":{"type":"string","description":"Brand personality, formality level, warmth, how they relate to the reader"},"content_types":{"type":"string","description":"What content approaches the brand uses"},"should_avoid":{"type":"string","description":"Words, phrases, patterns, and tones to never use"},"should_use":{"type":"string","description":"Characteristic vocabulary, phrases, sentence patterns"},"style_inspiration":{"type":"string","description":"Writing style patterns observed from the inspiration sources"}},"required":["voice_analysis","content_types","should_avoid","should_use","style_inspiration"]}`),
		},
	}

	toolList := []ai.Tool{
		tools.NewSearchTool(),
		submitVoiceToneTool,
	}

	searchExec := tools.NewSearchExecutor(h.braveClient)

	var submittedResult string

	executor := func(ctx context.Context, name, args string) (string, error) {
		switch name {
		case "web_search":
			return searchExec(ctx, args)
		case "submit_voice_tone":
			submittedResult = args
			return "Voice & tone profile submitted successfully.", ai.ErrToolDone
		default:
			return "", fmt.Errorf("unknown tool: %s", name)
		}
	}

	onToolEvent := func(event ai.ToolEvent) {
		switch event.Type {
		case "tool_start":
			summary := ""
			if event.Tool == "web_search" {
				summary = tools.SearchSummary(event.Args)
			} else if event.Tool == "submit_voice_tone" {
				summary = "Submitting voice & tone profile..."
			}
			sendEvent(map[string]string{"type": "status", "status": summary})
		case "tool_result":
			// no-op
		}
	}

	onChunk := func(chunk string) error {
		return nil // no-op, we only care about the final tool call
	}

	onReasoning := func(chunk string) error {
		return nil
	}

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt.String()},
		{Role: "user", Content: "Analyze the provided sources and build a structured voice & tone profile for this brand. Use web_search if you need more information, then submit your analysis."},
	}

	temp := 0.3
	_, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, onChunk, onReasoning, &temp, 15)
	if err != nil && submittedResult == "" {
		sendEvent(map[string]string{"type": "error", "error": err.Error()})
		return
	}

	if submittedResult != "" {
		sendEvent(map[string]any{"type": "result", "data": json.RawMessage(submittedResult)})
	}

	sendEvent(map[string]string{"type": "done"})
}
