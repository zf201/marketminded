package handlers

import (
	"fmt"
	"net/http"

	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type StorytellingHandler struct {
	queries *store.Queries
}

func NewStorytellingHandler(q *store.Queries) *StorytellingHandler {
	return &StorytellingHandler{queries: q}
}

func (h *StorytellingHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	if r.Method == "POST" {
		h.save(w, r, projectID)
		return
	}
	h.show(w, r, projectID)
}

func (h *StorytellingHandler) show(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	selectedKey, _ := h.queries.GetProjectSetting(projectID, "storytelling_framework")

	templates.StorytellingPage(templates.StorytellingData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Frameworks:  content.Frameworks,
		SelectedKey: selectedKey,
		Saved:       r.URL.Query().Get("saved") == "1",
	}).Render(r.Context(), w)
}

func (h *StorytellingHandler) save(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	value := r.FormValue("storytelling_framework")
	// Only save known keys or empty string (clear)
	if value != "" && content.FrameworkByKey(value) == nil {
		value = ""
	}
	h.queries.SetProjectSetting(projectID, "storytelling_framework", value)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/storytelling?saved=1", projectID), http.StatusSeeOther)
}
