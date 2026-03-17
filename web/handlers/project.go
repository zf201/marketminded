package handlers

import (
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type ProjectHandler struct {
	queries *store.Queries
}

func NewProjectHandler(q *store.Queries) *ProjectHandler {
	return &ProjectHandler{queries: q}
}

func (h *ProjectHandler) Register(mux *http.ServeMux) {
	mux.HandleFunc("/projects/new", h.newForm)
	mux.HandleFunc("/projects", h.handleProjects)
}

func (h *ProjectHandler) handleProjects(w http.ResponseWriter, r *http.Request) {
	path := strings.TrimPrefix(r.URL.Path, "/projects")
	if path == "" {
		if r.Method == "POST" {
			h.create(w, r)
			return
		}
		http.Redirect(w, r, "/", http.StatusSeeOther)
		return
	}

	// /projects/{id}
	idStr := strings.TrimPrefix(path, "/")
	if idx := strings.Index(idStr, "/"); idx != -1 {
		// sub-paths handled by other handlers
		http.NotFound(w, r)
		return
	}

	id, err := strconv.ParseInt(idStr, 10, 64)
	if err != nil {
		http.NotFound(w, r)
		return
	}
	h.ShowProject(w, r, id)
}

func (h *ProjectHandler) newForm(w http.ResponseWriter, r *http.Request) {
	templates.ProjectNew().Render(r.Context(), w)
}

func (h *ProjectHandler) create(w http.ResponseWriter, r *http.Request) {
	r.ParseForm()
	name := r.FormValue("name")
	desc := r.FormValue("description")

	if name == "" {
		http.Error(w, "Name is required", http.StatusBadRequest)
		return
	}

	p, err := h.queries.CreateProject(name, desc)
	if err != nil {
		http.Error(w, "Failed to create project", http.StatusInternalServerError)
		return
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d", p.ID), http.StatusSeeOther)
}

// ShowProject renders the project overview page for a given project ID.
func (h *ProjectHandler) ShowProject(w http.ResponseWriter, r *http.Request, id int64) {
	project, err := h.queries.GetProject(id)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	runs, _ := h.queries.ListPipelineRuns(id)

	sections, _ := h.queries.ListProfileSections(id)
	hasProfile := len(sections) > 0

	contextItems, _ := h.queries.ListContextItems(id)
	ctxViews := make([]templates.ContextItemView, len(contextItems))
	for i, c := range contextItems {
		preview := c.Content
		if len(preview) > 80 {
			preview = preview[:80] + "..."
		}
		ctxViews[i] = templates.ContextItemView{
			ID:      c.ID,
			Title:   c.Title,
			Preview: preview,
		}
	}

	detail := templates.ProjectDetail{
		ID:           project.ID,
		Name:         project.Name,
		Description:  project.Description,
		HasProfile:   hasProfile,
		RunCount:     len(runs),
		ContextItems: ctxViews,
	}

	templates.ProjectOverview(detail).Render(r.Context(), w)
}

// ParseProjectID extracts the project ID from a URL path like /projects/123/...
func ParseProjectID(path string) (int64, string, error) {
	trimmed := strings.TrimPrefix(path, "/projects/")
	parts := strings.SplitN(trimmed, "/", 2)
	id, err := strconv.ParseInt(parts[0], 10, 64)
	if err != nil {
		return 0, "", err
	}
	rest := ""
	if len(parts) > 1 {
		rest = parts[1]
	}
	return id, rest, nil
}
