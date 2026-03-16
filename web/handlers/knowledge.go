package handlers

import (
	"context"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/agents"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type KnowledgeHandler struct {
	queries    *store.Queries
	voiceAgent *agents.VoiceAgent
	toneAgent  *agents.ToneAgent
}

func NewKnowledgeHandler(q *store.Queries, va *agents.VoiceAgent, ta *agents.ToneAgent) *KnowledgeHandler {
	return &KnowledgeHandler{queries: q, voiceAgent: va, toneAgent: ta}
}

func (h *KnowledgeHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "knowledge" && r.Method == "GET":
		h.list(w, r, projectID)
	case rest == "knowledge" && r.Method == "POST":
		h.create(w, r, projectID)
	case rest == "knowledge/build-profiles" && r.Method == "POST":
		h.buildProfiles(w, r, projectID)
	case strings.HasSuffix(rest, "/delete") && r.Method == "POST":
		h.delete(w, r, projectID, rest)
	default:
		http.NotFound(w, r)
	}
}

func (h *KnowledgeHandler) list(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	items, _ := h.queries.ListKnowledgeItems(projectID, "")

	viewItems := make([]templates.KnowledgeItemView, len(items))
	for i, item := range items {
		viewItems[i] = templates.KnowledgeItemView{
			ID:        item.ID,
			Type:      item.Type,
			Title:     item.Title,
			Content:   item.Content,
			SourceURL: item.SourceURL,
		}
	}

	data := templates.KnowledgePageData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Items:       viewItems,
		HasVoice:    project.VoiceProfile != nil,
		HasTone:     project.ToneProfile != nil,
	}

	templates.KnowledgePage(data).Render(r.Context(), w)
}

func (h *KnowledgeHandler) create(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	itemType := r.FormValue("type")
	title := r.FormValue("title")
	content := r.FormValue("content")
	sourceURL := r.FormValue("source_url")

	if content == "" {
		http.Error(w, "Content is required", http.StatusBadRequest)
		return
	}

	_, err := h.queries.CreateKnowledgeItem(projectID, itemType, title, content, sourceURL)
	if err != nil {
		http.Error(w, "Failed to create item", http.StatusInternalServerError)
		return
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/knowledge", projectID), http.StatusSeeOther)
}

func (h *KnowledgeHandler) buildProfiles(w http.ResponseWriter, r *http.Request, projectID int64) {
	ctx := context.Background()

	// Gather voice samples
	voiceSamples, _ := h.queries.ListKnowledgeItems(projectID, "voice_sample")
	var samples []string
	for _, s := range voiceSamples {
		samples = append(samples, s.Content)
	}

	// Gather brand docs for tone
	brandDocs, _ := h.queries.ListKnowledgeItems(projectID, "brand_doc")
	toneGuides, _ := h.queries.ListKnowledgeItems(projectID, "tone_guide")
	var docs []string
	for _, d := range brandDocs {
		docs = append(docs, d.Content)
	}
	for _, d := range toneGuides {
		docs = append(docs, d.Content)
	}

	// Build voice profile if we have samples
	if len(samples) > 0 {
		voiceProfile, err := h.voiceAgent.BuildProfile(ctx, samples)
		if err == nil {
			h.queries.UpdateVoiceProfile(projectID, voiceProfile)
		}
	}

	// Build tone profile
	allContent := append(samples, docs...)
	if len(allContent) > 0 {
		toneProfile, err := h.toneAgent.BuildProfile(ctx, samples, docs)
		if err == nil {
			h.queries.UpdateToneProfile(projectID, toneProfile)
		}
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/knowledge", projectID), http.StatusSeeOther)
}

func (h *KnowledgeHandler) delete(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "knowledge/123/delete"
	parts := strings.Split(rest, "/")
	if len(parts) < 3 {
		http.NotFound(w, r)
		return
	}
	itemID, err := strconv.ParseInt(parts[1], 10, 64)
	if err != nil {
		http.NotFound(w, r)
		return
	}
	h.queries.DeleteKnowledgeItem(itemID)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/knowledge", projectID), http.StatusSeeOther)
}
