package handlers

import (
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
