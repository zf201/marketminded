package handlers

import (
	"fmt"
	"net/http"

	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type ProjectSettingsHandler struct {
	queries *store.Queries
}

func NewProjectSettingsHandler(q *store.Queries) *ProjectSettingsHandler {
	return &ProjectSettingsHandler{queries: q}
}

func (h *ProjectSettingsHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	if r.Method == "POST" {
		h.save(w, r, projectID)
		return
	}
	h.show(w, r, projectID)
}

func (h *ProjectSettingsHandler) show(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}
	settings, _ := h.queries.AllProjectSettings(projectID)
	blogURL, _ := h.queries.GetProjectSetting(projectID, "blog_url")
	homepageURL, _ := h.queries.GetProjectSetting(projectID, "homepage_url")
	templates.ProjectSettingsPage(templates.ProjectSettingsData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Language:    settings["language"],
		BlogURL:     blogURL,
		HomepageURL: homepageURL,
		Saved:       r.URL.Query().Get("saved") == "1",
	}).Render(r.Context(), w)
}

func (h *ProjectSettingsHandler) save(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	h.queries.SetProjectSetting(projectID, "language", r.FormValue("language"))
	h.queries.SetProjectSetting(projectID, "blog_url", r.FormValue("blog_url"))
	h.queries.SetProjectSetting(projectID, "homepage_url", r.FormValue("homepage_url"))
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/settings?saved=1", projectID), http.StatusSeeOther)
}
