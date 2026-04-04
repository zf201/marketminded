package handlers

import (
	"encoding/json"
	"net/http"

	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type DashboardHandler struct {
	queries *store.Queries
}

func NewDashboardHandler(q *store.Queries) *DashboardHandler {
	return &DashboardHandler{queries: q}
}

func (h *DashboardHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	if r.URL.Path == "/projects.json" {
		h.listJSON(w, r)
		return
	}
	if r.URL.Path != "/" {
		http.NotFound(w, r)
		return
	}
	projects, err := h.queries.ListProjects()
	if err != nil {
		http.Error(w, "Failed to load projects", http.StatusInternalServerError)
		return
	}

	items := make([]templates.DashboardProject, len(projects))
	for i, p := range projects {
		items[i] = templates.DashboardProject{
			ID:          p.ID,
			Name:        p.Name,
			Description: p.Description,
		}
	}

	templates.Dashboard(items).Render(r.Context(), w)
}

func (h *DashboardHandler) listJSON(w http.ResponseWriter, r *http.Request) {
	projects, err := h.queries.ListProjects()
	if err != nil {
		http.Error(w, "Failed to load projects", http.StatusInternalServerError)
		return
	}
	type item struct {
		ID   int64  `json:"id"`
		Name string `json:"name"`
	}
	items := make([]item, len(projects))
	for i, p := range projects {
		items[i] = item{ID: p.ID, Name: p.Name}
	}
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(items)
}
