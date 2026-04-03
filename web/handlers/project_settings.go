package handlers

import (
	"fmt"
	"net/http"

	"github.com/zanfridau/marketminded/internal/content"
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

	fwOptions := make([]templates.FrameworkOption, len(content.Frameworks))
	for i, fw := range content.Frameworks {
		fwOptions[i] = templates.FrameworkOption{Key: fw.Key, Name: fw.Name, Description: fw.ShortDescription}
	}

	templates.ProjectSettingsPage(templates.ProjectSettingsData{
		ProjectID:             projectID,
		ProjectName:           project.Name,
		Language:              settings["language"],
		CompanyBlog:           settings["company_blog"],
		BlogNotes:             settings["blog_notes"],
		StorytellingFramework: settings["storytelling_framework"],
		Frameworks:            fwOptions,
		Saved:                 r.URL.Query().Get("saved") == "1",
	}).Render(r.Context(), w)
}

func (h *ProjectSettingsHandler) save(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	h.queries.SetProjectSetting(projectID, "language", r.FormValue("language"))
	h.queries.SetProjectSetting(projectID, "company_blog", r.FormValue("company_blog"))
	h.queries.SetProjectSetting(projectID, "blog_notes", r.FormValue("blog_notes"))
	h.queries.SetProjectSetting(projectID, "storytelling_framework", r.FormValue("storytelling_framework"))
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/settings?saved=1", projectID), http.StatusSeeOther)
}
