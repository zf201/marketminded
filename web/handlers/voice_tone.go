package handlers

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/applog"
	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/sse"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type VoiceToneHandler struct {
	queries  *store.Queries
	aiClient *ai.Client
	model    func() string
}

func NewVoiceToneHandler(q *store.Queries, aiClient *ai.Client, model func() string) *VoiceToneHandler {
	return &VoiceToneHandler{queries: q, aiClient: aiClient, model: model}
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
	json.NewEncoder(w).Encode(map[string]any{
		"voice_analysis":          vt.VoiceAnalysis,
		"content_types":           vt.ContentTypes,
		"should_avoid":            vt.ShouldAvoid,
		"should_use":              vt.ShouldUse,
		"style_inspiration":       vt.StyleInspiration,
		"storytelling_frameworks": json.RawMessage(vt.StorytellingFrameworks),
		"preferred_length":        vt.PreferredLength,
	})
}

func (h *VoiceToneHandler) saveProfile(w http.ResponseWriter, r *http.Request, projectID int64) {
	var body struct {
		VoiceAnalysis          string          `json:"voice_analysis"`
		ContentTypes           string          `json:"content_types"`
		ShouldAvoid            string          `json:"should_avoid"`
		ShouldUse              string          `json:"should_use"`
		StyleInspiration       string          `json:"style_inspiration"`
		StorytellingFrameworks json.RawMessage `json:"storytelling_frameworks"`
		PreferredLength        int             `json:"preferred_length"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}

	frameworksJSON := "[]"
	if len(body.StorytellingFrameworks) > 0 {
		frameworksJSON = string(body.StorytellingFrameworks)
	}

	preferredLength := body.PreferredLength
	if preferredLength == 0 {
		preferredLength = 1500
	}

	err := h.queries.UpsertVoiceToneProfile(projectID, store.VoiceToneProfile{
		VoiceAnalysis:          body.VoiceAnalysis,
		ContentTypes:           body.ContentTypes,
		ShouldAvoid:            body.ShouldAvoid,
		ShouldUse:              body.ShouldUse,
		StyleInspiration:       body.StyleInspiration,
		StorytellingFrameworks: frameworksJSON,
		PreferredLength:        preferredLength,
	})
	if err != nil {
		http.Error(w, "Failed to save profile", http.StatusInternalServerError)
		return
	}

	w.WriteHeader(http.StatusOK)
}

func (h *VoiceToneHandler) streamGenerate(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, _ := h.queries.GetProject(projectID)

	stream, err := sse.New(w)
	if err != nil {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}

	stream.SendData(map[string]string{"type": "status", "status": "Gathering context..."})

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
			stream.SendData(map[string]string{"type": "status", "status": fmt.Sprintf("Fetching %s...", u.URL)})
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

	stream.SendData(map[string]string{"type": "status", "status": "Analyzing voice & tone..."})

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
		fmt.Fprintf(&systemPrompt, "### Storytelling Frameworks\n%s\n\n", existingVT.StorytellingFrameworks)
		fmt.Fprintf(&systemPrompt, "### Preferred Length\n%d words\n\n", existingVT.PreferredLength)
	}

	if contextNotes != "" {
		fmt.Fprintf(&systemPrompt, "## Additional Context Notes\n%s\n\n", contextNotes)
	}
	if memorySetting != "" {
		fmt.Fprintf(&systemPrompt, "## Important Rules and Facts\n%s\n\n", memorySetting)
	}

	// Build framework reference for the prompt
	var frameworkRef strings.Builder
	frameworkRef.WriteString("## Available Storytelling Frameworks\n")
	for _, fw := range content.Frameworks {
		fmt.Fprintf(&frameworkRef, "\n**%s** (%s)\n", fw.Name, fw.Attribution)
		fmt.Fprintf(&frameworkRef, "Best for: %s\n", fw.BestFor)
		fmt.Fprintf(&frameworkRef, "%s\n", fw.ShortDescription)
		fmt.Fprintf(&frameworkRef, "Key: `%s`\n", fw.Key)
	}
	frameworkRef.WriteString("\n")
	systemPrompt.WriteString(frameworkRef.String())

	systemPrompt.WriteString(`## Your Task

### Step 1: Discover and fetch blog posts
The blog URLs above are listing/index pages. Use fetch_url to find links to 3-5 recent individual blog posts on each listing page, then fetch each individual post to read the full content. Do the same for any liked articles and inspiration URLs that point to listing pages.

### Step 2: Analyze writing patterns
Analyze the writing patterns across ALL fetched posts. Focus on STYLE, not content. Look at:
- Voice and personality (formal/informal, warm/cold, peer/authority)
- Sentence structure, length, and rhythm
- Vocabulary level and recurring phrases
- How they address the reader
- Formatting patterns (headings, lists, CTAs)
- What makes their good posts (liked articles) different from average ones
- What style patterns the inspiration sources share

### Step 3: Select storytelling frameworks
Review the available frameworks listed above. Pick 1-3 that best fit this brand's voice, audience, and content style. For each, write a short adaptation note explaining why it fits and how the editor/writer should apply it to this brand specifically.

### Step 4: Determine preferred content length
If you can infer a typical article length from the analyzed blog posts (estimate their word counts and average them), use that as the preferred length. If you cannot infer, default to 1500.

### Step 5: Produce structured output
Call submit_voice_tone with 7 fields:
1. **Voice Analysis** - Brand personality, formality level, warmth, how they relate to the reader
2. **Content Types** - What content approaches the brand uses (educational, promotional, storytelling, opinion, how-to, case study, etc.)
3. **Should Avoid** - Words, phrases, patterns, and tones to never use
4. **Should Use** - Characteristic vocabulary, phrases, sentence patterns, formatting conventions
5. **Style Inspiration** - Writing style patterns observed from the inspiration sources
6. **Storytelling Frameworks** - 1-3 framework selections from the available frameworks, each with key and adaptation note
7. **Preferred Length** - Target word count as an integer

## Rules
- ALWAYS write in English.
- Analyze STYLE, not content. Focus on HOW they write, not WHAT they write about.
- Be specific to THIS brand. Generic voice guidelines are useless.
- Include concrete examples and direct quotes from the source material where possible.
- NEVER use em dashes. Use commas, periods, or restructure.
- Write like a human. Short, direct sentences. Vary length.
- You MUST fetch individual blog posts — do not analyze only the listing page.
`)

	// Build tools
	submitVoiceToneTool := ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_voice_tone",
			Description: "Submit the structured voice & tone analysis.",
			Parameters: json.RawMessage(`{
				"type":"object",
				"properties":{
					"voice_analysis":{"type":"string","description":"Brand personality, formality level, warmth, how they relate to the reader"},
					"content_types":{"type":"string","description":"What content approaches the brand uses"},
					"should_avoid":{"type":"string","description":"Words, phrases, patterns, and tones to never use"},
					"should_use":{"type":"string","description":"Characteristic vocabulary, phrases, sentence patterns"},
					"style_inspiration":{"type":"string","description":"Writing style patterns observed from the inspiration sources"},
					"storytelling_frameworks":{"type":"array","items":{"type":"object","properties":{"key":{"type":"string","enum":["pixar","golden_circle","storybrand","heros_journey","three_act","abt"]},"note":{"type":"string","description":"Brand-specific adaptation: why this framework fits and how to apply it"}},"required":["key","note"]},"description":"1-3 storytelling frameworks best suited for this brand"},
					"preferred_length":{"type":"integer","description":"Target word count. Infer from analyzed blog posts if possible, default 1500"}
				},
				"required":["voice_analysis","content_types","should_avoid","should_use","style_inspiration","storytelling_frameworks","preferred_length"]
			}`),
		},
	}

	toolList := []ai.Tool{
		tools.NewFetchTool(),
		submitVoiceToneTool,
	}

	var submittedResult string

	executor := func(ctx context.Context, name, args string) (string, error) {
		switch name {
		case "fetch_url":
			return tools.ExecuteFetch(r.Context(), args)
		case "submit_voice_tone":
			submittedResult = args
			return "Voice & tone profile submitted successfully.", ai.ErrToolDone
		default:
			return "", fmt.Errorf("unknown tool: %s", name)
		}
	}

	onToolEvent := func(event ai.ToolEvent) {
		if event.Type == "tool_start" {
			summary := ""
			switch event.Tool {
			case "fetch_url":
				summary = tools.FetchSummary(event.Args)
			case "submit_voice_tone":
				summary = "Submitting voice & tone profile..."
			}
			if summary != "" {
				stream.SendData(map[string]string{"type": "status", "status": summary})
			}
		}
	}

	onChunk := func(chunk string) error {
		return nil // no-op, we only care about the final tool call
	}

	onReasoning := func(chunk string) error {
		return nil
	}

	maxIter := 15
	systemPrompt.WriteString(fmt.Sprintf("\n\nIMPORTANT: You have a MAXIMUM of %d tool calls. Plan efficiently and call submit_voice_tone when ready. Do NOT keep fetching endlessly.", maxIter))

	aiMsgs := []ai.Message{
		{Role: "system", Content: systemPrompt.String()},
		{Role: "user", Content: "Analyze the provided sources and build a structured voice & tone profile for this brand. Use fetch_url to read individual blog posts from the listing pages, then submit your analysis."},
	}

	temp := 0.3
	model := h.model()
	start := time.Now()
	applog.Info("voice_tone generate: project=%d model=%s starting", projectID, model)

	_, err = h.aiClient.StreamWithTools(r.Context(), model, aiMsgs, toolList, executor, onToolEvent, onChunk, onReasoning, &temp, "", maxIter)

	duration := time.Since(start)
	if err != nil && submittedResult == "" {
		applog.Error("voice_tone generate: project=%d model=%s failed after %s: %s", projectID, model, duration, err.Error())
		stream.SendData(map[string]string{"type": "error", "error": err.Error()})
		return
	}

	if submittedResult != "" {
		applog.Info("voice_tone generate: project=%d model=%s completed in %s, result=%d bytes", projectID, model, duration, len(submittedResult))
		stream.SendData(map[string]any{"type": "result", "data": json.RawMessage(submittedResult)})
	} else {
		applog.Error("voice_tone generate: project=%d model=%s completed in %s but no result submitted", projectID, model, duration)
	}

	stream.SendData(map[string]string{"type": "done"})
}
