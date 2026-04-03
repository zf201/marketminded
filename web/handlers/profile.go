package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/zanfridau/marketminded/internal/ai"
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
	queries  *store.Queries
	aiClient *ai.Client
	model    func() string
}

func NewProfileHandler(q *store.Queries, aiClient *ai.Client, model func() string) *ProfileHandler {
	return &ProfileHandler{queries: q, aiClient: aiClient, model: model}
}

func (h *ProfileHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
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

	var body struct {
		Content string `json:"content"`
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

	systemPrompt.WriteString(`## Rules
- NEVER fabricate or assume details. Base everything on the source material and existing profile.
- Write specific prose about THIS client. If it could apply to any company, it's too generic.
- Be thorough and comprehensive. Cover all aspects described above.

## Writing style
- Write like a human. NEVER sound like AI-generated content.
- NEVER use em dashes. Use commas, periods, or restructure.
- Zero emojis.
- Avoid: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness", "at the end of the day", "it's worth noting".
- Short, direct sentences. Vary length. Sound like a person, not a press release.

Write the section content now. Output ONLY the section content, no headers or meta-commentary.`)

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt.String()},
		{Role: "user", Content: "Write the " + sectionTitle(sectionName) + " section."},
	}

	_, err = h.aiClient.Stream(r.Context(), h.model(), aiMsgs, func(chunk string) error {
		sendEvent(map[string]string{"type": "chunk", "chunk": chunk})
		return nil
	})
	if err != nil {
		sendEvent(map[string]string{"type": "error", "error": err.Error()})
		return
	}

	sendEvent(map[string]string{"type": "done"})
}

func (h *ProfileHandler) listVersions(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	section := h.parseSectionFromRest(rest)
	if !isValidSection(section) {
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

