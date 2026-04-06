package handlers

import (
	"net/http"

	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type SettingsHandler struct {
	queries *store.Queries
}

func NewSettingsHandler(q *store.Queries) *SettingsHandler {
	return &SettingsHandler{queries: q}
}

func (h *SettingsHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.Method == "POST" {
		h.save(w, r)
		return
	}
	h.show(w, r, false)
}

func (h *SettingsHandler) show(w http.ResponseWriter, r *http.Request, saved bool) {
	settings, _ := h.queries.AllSettings()

	templates.SettingsPage(templates.SettingsData{
		ModelContent:     settings["model_content"],
		ModelCopywriting: settings["model_copywriting"],
		ModelIdeation:    settings["model_ideation"],
		ModelProofread:   settings["model_proofread"],
		Temperature:        settings["temperature"],
		DataForSEOLogin:    settings["dataforseo_login"],
		DataForSEOPassword: settings["dataforseo_password"],
		Saved:              saved,
	}).Render(r.Context(), w)
}

func (h *SettingsHandler) save(w http.ResponseWriter, r *http.Request) {
	r.ParseForm()
	h.queries.SetSetting("model_content", r.FormValue("model_content"))
	h.queries.SetSetting("model_copywriting", r.FormValue("model_copywriting"))
	h.queries.SetSetting("model_ideation", r.FormValue("model_ideation"))
	h.queries.SetSetting("model_proofread", r.FormValue("model_proofread"))
	h.queries.SetSetting("temperature", r.FormValue("temperature"))
	h.queries.SetSetting("dataforseo_login", r.FormValue("dataforseo_login"))
	h.queries.SetSetting("dataforseo_password", r.FormValue("dataforseo_password"))
	h.show(w, r, true)
}
