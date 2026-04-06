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
	"github.com/zanfridau/marketminded/internal/search"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/internal/types"
	"github.com/zanfridau/marketminded/web/templates"
)

var allSections = []string{
	"product_and_positioning", "audience", "voice_and_tone",
}

var sectionDescriptions = map[string]string{
	"product_and_positioning": `What the company does, who they serve, industry, business model. Their unique value proposition, what makes them different from alternatives. Core problems they solve, why existing solutions fail. Key products/services, primary CTA (book a call, sign up, buy), and how aggressively content should sell vs. educate. Key competitors and how they differentiate.`,
	"audience": `Ideal customer profile: demographics, roles, company type/size (if B2B). Their top pain points in their own language. Where they spend time online, what content they consume. Behavioral insights:
- Push: frustrations driving them to seek a solution
- Pull: what attracts them to this specific solution
- Anxiety: concerns that might stop them from acting
- Habit: what keeps them stuck with the status quo`,
	"voice_and_tone": `How the brand communicates: personality traits, vocabulary level, sentence style, formality, humor, warmth. Characteristic phrases to use. How they relate to the audience (peer, mentor, authority). Words/phrases to always use and to never use. Ask for examples of writing they like, use THEIR words, not marketing theory. Include content role models: creators, brands, or accounts they admire and why.`,
}

type ProfileHandler struct {
	queries          *store.Queries
	aiClient         *ai.Client
	braveClient      *search.BraveClient
	model            func() string
	audienceHandler  *AudienceHandler
	voiceToneHandler *VoiceToneHandler
}

func NewProfileHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, model func() string) *ProfileHandler {
	return &ProfileHandler{
		queries:          q,
		aiClient:         aiClient,
		braveClient:      braveClient,
		model:            model,
		audienceHandler:  NewAudienceHandler(q, aiClient, braveClient, model),
		voiceToneHandler: NewVoiceToneHandler(q, aiClient, model),
	}
}

func (h *ProfileHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case strings.HasPrefix(rest, "profile/audience/"):
		audienceRest := strings.TrimPrefix(rest, "profile/audience/")
		h.audienceHandler.Handle(w, r, projectID, audienceRest)
		return
	case strings.HasPrefix(rest, "profile/voice_and_tone/"):
		vtRest := strings.TrimPrefix(rest, "profile/voice_and_tone/")
		h.voiceToneHandler.Handle(w, r, projectID, vtRest)
		return
	case rest == "profile" && r.Method == "GET":
		h.show(w, r, projectID)
	case strings.HasSuffix(rest, "/save") && r.Method == "POST":
		h.saveSection(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/save-context") && r.Method == "POST":
		h.saveContext(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/generate") && r.Method == "GET":
		h.streamGenerate(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/versions") && r.Method == "GET":
		h.listVersions(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/context") && r.Method == "GET":
		h.getContext(w, r, projectID, rest)
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
	sectionMap := make(map[string]*store.ProfileSection)
	for i := range sections {
		sectionMap[sections[i].Section] = &sections[i]
	}

	cardViews := make([]templates.ProfileCardView, len(allSections))
	for i, name := range allSections {
		card := templates.ProfileCardView{
			Section:       name,
			Title:         sectionTitle(name),
			HasSourceURLs: name == "product_and_positioning",
			Index:         i,
			ProjectID:     projectID,
		}
		if ps, ok := sectionMap[name]; ok {
			card.Content = ps.Content
			if card.HasSourceURLs && ps.SourceURLs != "" {
				json.Unmarshal([]byte(ps.SourceURLs), &card.SourceURLs)
			}
		}
		if card.HasSourceURLs {
			card.ContextNotes, _ = h.queries.GetProjectSetting(projectID, "profile_context_"+name)
			card.URLGuide, _ = h.queries.GetProjectSetting(projectID, "profile_url_guide")
		}
		if name == "audience" {
			card.IsAudience = true
			card.Personas, _ = h.queries.ListAudiencePersonas(projectID)
			card.AudienceLocation, _ = h.queries.GetProjectSetting(projectID, "audience_location")
			card.ContextNotes, _ = h.queries.GetProjectSetting(projectID, "audience_notes")
		}
		if name == "voice_and_tone" {
			card.IsVoiceTone = true
			vt, err := h.queries.GetVoiceToneProfile(projectID)
			if err == nil {
				card.VoiceToneProfile = vt
			}
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
			card.VTBlogURLs = blogURLs
			card.VTLikedArticles = likedArticles
			card.VTInspiration = inspirationURLs
			card.ContextNotes, _ = h.queries.GetProjectSetting(projectID, "profile_context_voice_and_tone")
		}
		cardViews[i] = card
	}

	templates.ProfilePage(templates.ProfilePageData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Cards:       cardViews,
	}).Render(r.Context(), w)
}

func (h *ProfileHandler) saveSection(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	section := h.parseSectionFromRest(rest)
	if !isValidSection(section) {
		http.NotFound(w, r)
		return
	}
	if section == "audience" || section == "voice_and_tone" {
		http.NotFound(w, r)
		return
	}

	var body struct {
		Content  string `json:"content"`
		URLGuide string `json:"url_guide,omitempty"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}

	// Save previous version if content changed
	existing, err := h.queries.GetProfileSection(projectID, section)
	if err == nil && existing.Content != "" && existing.Content != body.Content {
		h.queries.SaveProfileVersion(projectID, section, existing.Content)
	}

	h.queries.UpsertProfileSection(projectID, section, body.Content)

	// Save URL guide if provided (from P&P build)
	if body.URLGuide != "" {
		h.queries.SetProjectSetting(projectID, "profile_url_guide", body.URLGuide)
	}

	w.WriteHeader(http.StatusOK)
}

func (h *ProfileHandler) saveContext(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	section := h.parseSectionFromRest(rest)
	if !isValidSection(section) || section != "product_and_positioning" {
		http.NotFound(w, r)
		return
	}

	var body struct {
		URLs  []store.SourceURL `json:"urls"`
		Notes string            `json:"notes"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}

	urlsJSON, _ := json.Marshal(body.URLs)
	if body.URLs == nil {
		urlsJSON = []byte("[]")
	}

	// Get existing content so we don't overwrite it
	existing, err := h.queries.GetProfileSection(projectID, section)
	content := ""
	if err == nil {
		content = existing.Content
	}

	h.queries.UpsertProfileSectionFull(projectID, section, content, string(urlsJSON))
	h.queries.SetProjectSetting(projectID, "profile_context_"+section, body.Notes)
	w.WriteHeader(http.StatusOK)
}

func (h *ProfileHandler) getContext(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	section := h.parseSectionFromRest(rest)
	if !isValidSection(section) {
		http.NotFound(w, r)
		return
	}

	ps, err := h.queries.GetProfileSection(projectID, section)
	var urls []store.SourceURL
	if err == nil && ps.SourceURLs != "" {
		json.Unmarshal([]byte(ps.SourceURLs), &urls)
	}

	notes, _ := h.queries.GetProjectSetting(projectID, "profile_context_"+section)

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{
		"content": func() string { if err == nil { return ps.Content } else { return "" } }(),
		"urls":    urls,
		"notes":   notes,
	})
}

func (h *ProfileHandler) streamGenerate(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	sectionName := h.parseSectionFromRest(rest)
	if !isValidSection(sectionName) {
		http.NotFound(w, r)
		return
	}
	if sectionName == "audience" || sectionName == "voice_and_tone" {
		http.NotFound(w, r)
		return
	}
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

	sendEvent(map[string]string{"type": "status", "status": "Building profile..."})

	// Pre-fetch source URLs for product_and_positioning
	var fetchedContent strings.Builder
	if sectionName == "product_and_positioning" {
		ps, err := h.queries.GetProfileSection(projectID, sectionName)
		if err == nil && ps.SourceURLs != "" {
			var urls []store.SourceURL
			if json.Unmarshal([]byte(ps.SourceURLs), &urls) == nil {
				for _, u := range urls {
					sendEvent(map[string]string{"type": "status", "status": fmt.Sprintf("Fetching %s...", u.URL)})
					fetchArgs, _ := json.Marshal(map[string]string{"url": u.URL})
					result, err := tools.ExecuteFetch(r.Context(), string(fetchArgs))
					if err != nil {
						fmt.Fprintf(&fetchedContent, "\n## Source: %s\n(fetch failed: %s)\n", u.URL, err.Error())
						continue
					}
					fmt.Fprintf(&fetchedContent, "\n## Source: %s\n", u.URL)
					if u.Notes != "" {
						fmt.Fprintf(&fetchedContent, "Notes: %s\n", u.Notes)
					}
					fmt.Fprintf(&fetchedContent, "%s\n", result)
				}
			}
		}
	}

	// Get existing content for this section
	var existingContent string
	ps, err := h.queries.GetProfileSection(projectID, sectionName)
	if err == nil {
		existingContent = ps.Content
	}

	// Get memory setting
	var memorySetting string
	if mem, err := h.queries.GetProjectSetting(projectID, "memory"); err == nil && mem != "" {
		memorySetting = mem
	}

	// Get context notes
	contextNotes, _ := h.queries.GetProjectSetting(projectID, "profile_context_"+sectionName)

	// Build other profile sections for context
	var profileContext strings.Builder
	for _, name := range allSections {
		if name == sectionName {
			continue
		}
		s, err := h.queries.GetProfileSection(projectID, name)
		if err != nil || s.Content == "" {
			continue
		}
		fmt.Fprintf(&profileContext, "## %s\n%s\n\n", sectionTitle(name), s.Content)
	}

	sendEvent(map[string]string{"type": "status", "status": "Generating..."})

	// Build system prompt
	var systemPrompt strings.Builder
	fmt.Fprintf(&systemPrompt, "Today's date: %s\n\n", time.Now().Format("January 2, 2006"))
	fmt.Fprintf(&systemPrompt, "You are an expert content marketing strategist. Write the **%s** section of a client profile for \"%s\".\n\n", sectionTitle(sectionName), project.Name)
	fmt.Fprintf(&systemPrompt, "## What this section needs\n%s\n\n", sectionDescriptions[sectionName])

	if fetchedContent.Len() > 0 {
		fmt.Fprintf(&systemPrompt, "## Source material (fetched from client URLs)\n%s\n\n", fetchedContent.String())
	}

	if profileContext.Len() > 0 {
		fmt.Fprintf(&systemPrompt, "## Other profile sections (for context)\n%s\n", profileContext.String())
	}

	if existingContent != "" {
		fmt.Fprintf(&systemPrompt, "## Current content for this section (improve upon this)\n%s\n\n", existingContent)
	}

	if contextNotes != "" {
		fmt.Fprintf(&systemPrompt, "## Additional context notes\n%s\n\n", contextNotes)
	}

	if memorySetting != "" {
		fmt.Fprintf(&systemPrompt, "## Important rules and facts\n%s\n\n", memorySetting)
	}

	if sectionName == "product_and_positioning" && fetchedContent.Len() > 0 {
		// List the URLs so the AI can write a guide for each
		var urlListForGuide strings.Builder
		ps2, _ := h.queries.GetProfileSection(projectID, sectionName)
		if ps2 != nil && ps2.SourceURLs != "" {
			var urls []store.SourceURL
			if json.Unmarshal([]byte(ps2.SourceURLs), &urls) == nil {
				for _, u := range urls {
					fmt.Fprintf(&urlListForGuide, "- %s", u.URL)
					if u.Notes != "" {
						fmt.Fprintf(&urlListForGuide, " (user notes: %s)", u.Notes)
					}
					urlListForGuide.WriteString("\n")
				}
			}
		}
		systemPrompt.WriteString(fmt.Sprintf(`## URL Guide
You MUST also produce a url_guide: for each source URL listed below, write a one-line instruction explaining what data a pipeline agent should extract from that URL and when it's relevant. Be specific — e.g. "Fetch for pricing tiers and plan names when writing about costs" not "Company website".

Source URLs:
%s
`, urlListForGuide.String()))
	}

	systemPrompt.WriteString(`## Rules
- NEVER fabricate or assume details. Base everything on the source material and existing profile.
- Write specific prose about THIS client. If it could apply to any company, it's too generic.
- Be thorough and comprehensive. Cover all aspects described above.
- ALWAYS write in English.

## Writing style
- Write like a human. NEVER sound like AI-generated content.
- NEVER use em dashes. Use commas, periods, or restructure.
- Zero emojis.
- Avoid: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness", "at the end of the day", "it's worth noting".
- Short, direct sentences. Vary length. Sound like a person, not a press release.
`)

	if sectionName == "product_and_positioning" && fetchedContent.Len() > 0 {
		systemPrompt.WriteString("\nCall submit_profile with both the content and url_guide fields.")

		submitTool := ai.Tool{
			Type: "function",
			Function: ai.ToolFunction{
				Name:        "submit_profile",
				Description: "Submit the profile section content and URL guide for pipeline agents.",
				Parameters:  json.RawMessage(`{"type":"object","properties":{"content":{"type":"string","description":"The full profile section content"},"url_guide":{"type":"string","description":"One-line instruction per source URL explaining what data to extract and when it's relevant. Format: one line per URL, starting with the URL."}},"required":["content","url_guide"]}`),
			},
		}

		toolList := []ai.Tool{submitTool}
		var submittedResult string

		executor := func(ctx context.Context, name, args string) (string, error) {
			if name == "submit_profile" {
				submittedResult = args
				return "Profile submitted.", ai.ErrToolDone
			}
			return "", fmt.Errorf("unknown tool: %s", name)
		}

		onToolEvent := func(event ai.ToolEvent) {
			if event.Type == "tool_start" && event.Tool == "submit_profile" {
				sendEvent(map[string]string{"type": "status", "status": "Finalizing..."})
			}
		}

		maxIter := 5
		systemPrompt.WriteString(fmt.Sprintf("\n\nIMPORTANT: You have a MAXIMUM of %d tool calls. Call submit_profile with your results.", maxIter))

		aiMsgs := []types.Message{
			{Role: "system", Content: systemPrompt.String()},
			{Role: "user", Content: "Write the " + sectionTitle(sectionName) + " section and produce the URL guide."},
		}

		temp := 0.3
		model := h.model()
		start := time.Now()
		applog.Info("profile generate (tool): section=%s project=%d model=%s starting", sectionName, projectID, model)

		_, err = h.aiClient.StreamWithTools(r.Context(), model, aiMsgs, toolList, executor, onToolEvent,
			func(string) error { return nil },
			func(string) error { return nil }, &temp, "", maxIter)

		duration := time.Since(start)
		if err != nil && submittedResult == "" {
			applog.Error("profile generate (tool): section=%s project=%d model=%s failed after %s: %s", sectionName, projectID, model, duration, err.Error())
			sendEvent(map[string]string{"type": "error", "error": err.Error()})
			return
		}

		if submittedResult != "" {
			applog.Info("profile generate (tool): section=%s project=%d model=%s completed in %s, result=%d bytes", sectionName, projectID, model, duration, len(submittedResult))
			sendEvent(map[string]any{"type": "result", "data": json.RawMessage(submittedResult)})
		} else {
			applog.Error("profile generate (tool): section=%s project=%d model=%s completed in %s but no result submitted", sectionName, projectID, model, duration)
		}

		sendEvent(map[string]string{"type": "done"})
	} else {
		// Simple stream for non-P&P sections (or P&P without URLs)
		systemPrompt.WriteString("\nWrite the section content now. Output ONLY the section content, no headers or meta-commentary.")

		aiMsgs := []types.Message{
			{Role: "system", Content: systemPrompt.String()},
			{Role: "user", Content: "Write the " + sectionTitle(sectionName) + " section."},
		}

		model := h.model()
		start := time.Now()
		applog.Info("profile generate (stream): section=%s project=%d model=%s starting", sectionName, projectID, model)

		_, err = h.aiClient.Stream(r.Context(), model, aiMsgs, func(chunk string) error {
			sendEvent(map[string]string{"type": "chunk", "chunk": chunk})
			return nil
		})

		duration := time.Since(start)
		if err != nil {
			applog.Error("profile generate (stream): section=%s project=%d model=%s failed after %s: %s", sectionName, projectID, model, duration, err.Error())
			sendEvent(map[string]string{"type": "error", "error": err.Error()})
			return
		}

		applog.Info("profile generate (stream): section=%s project=%d model=%s completed in %s", sectionName, projectID, model, duration)
		sendEvent(map[string]string{"type": "done"})
	}
}

func (h *ProfileHandler) listVersions(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	section := h.parseSectionFromRest(rest)
	if !isValidSection(section) {
		http.NotFound(w, r)
		return
	}
	if section == "audience" || section == "voice_and_tone" {
		http.NotFound(w, r)
		return
	}
	versions, err := h.queries.ListProfileVersions(projectID, section)
	if err != nil {
		http.Error(w, "Failed to load versions", http.StatusInternalServerError)
		return
	}

	type versionJSON struct {
		ID        int64  `json:"id"`
		Content   string `json:"content"`
		CreatedAt string `json:"created_at"`
	}

	out := make([]versionJSON, len(versions))
	for i, v := range versions {
		out[i] = versionJSON{
			ID:        v.ID,
			Content:   v.Content,
			CreatedAt: v.CreatedAt.Format("Jan 2, 2006 3:04 PM"),
		}
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(out)
}

func (h *ProfileHandler) parseSectionFromRest(rest string) string {
	// rest = "profile/product_and_positioning/edit" or "profile/audience/save" etc.
	section := strings.TrimPrefix(rest, "profile/")
	if idx := strings.Index(section, "/"); idx != -1 {
		section = section[:idx]
	}
	return section
}

func isValidSection(section string) bool {
	for _, s := range allSections {
		if s == section {
			return true
		}
	}
	return false
}

var sectionDisplayTitles = map[string]string{
	"product_and_positioning": "Product & Positioning",
	"voice_and_tone":          "Voice & Tone",
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

