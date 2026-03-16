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
		ModelContent:  settings["model_content"],
		ModelIdeation: settings["model_ideation"],
		Temperature:   settings["temperature"],
		Saved:         saved,
	}).Render(r.Context(), w)
}

func (h *SettingsHandler) save(w http.ResponseWriter, r *http.Request) {
	r.ParseForm()
	h.queries.SetSetting("model_content", r.FormValue("model_content"))
	h.queries.SetSetting("model_ideation", r.FormValue("model_ideation"))
	h.queries.SetSetting("temperature", r.FormValue("temperature"))
	h.show(w, r, true)
}
