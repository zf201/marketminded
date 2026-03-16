package handlers

import (
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type TemplateHandler struct {
	queries *store.Queries
}

func NewTemplateHandler(q *store.Queries) *TemplateHandler {
	return &TemplateHandler{queries: q}
}

func (h *TemplateHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "templates" && r.Method == "GET":
		h.list(w, r, projectID)
	case rest == "templates" && r.Method == "POST":
		h.create(w, r, projectID)
	case strings.HasSuffix(rest, "/delete") && r.Method == "POST":
		h.delete(w, r, projectID, rest)
	default:
		http.NotFound(w, r)
	}
}

func (h *TemplateHandler) list(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	tmpls, _ := h.queries.ListTemplates(projectID)
	views := make([]templates.TemplateMgrView, len(tmpls))
	for i, t := range tmpls {
		views[i] = templates.TemplateMgrView{
			ID:          t.ID,
			Name:        t.Name,
			Platform:    t.Platform,
			HTMLContent: t.HTMLContent,
		}
	}

	templates.TemplatesMgrPage(templates.TemplatesMgrData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Templates:   views,
	}).Render(r.Context(), w)
}

func (h *TemplateHandler) create(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	name := r.FormValue("name")
	platform := r.FormValue("platform")
	htmlContent := r.FormValue("html_content")

	_, err := h.queries.CreateTemplate(projectID, name, platform, htmlContent)
	if err != nil {
		http.Error(w, err.Error(), http.StatusBadRequest)
		return
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/templates", projectID), http.StatusSeeOther)
}

func (h *TemplateHandler) delete(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "templates/123/delete"
	parts := strings.Split(rest, "/")
	if len(parts) < 3 {
		http.NotFound(w, r)
		return
	}
	id, err := strconv.ParseInt(parts[1], 10, 64)
	if err != nil {
		http.NotFound(w, r)
		return
	}
	h.queries.DeleteTemplate(id)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/templates", projectID), http.StatusSeeOther)
}
