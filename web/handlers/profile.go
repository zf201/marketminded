package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/agents"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

var allSections = []string{"business", "audience", "voice", "tone", "strategy", "pillars", "guidelines", "competitors", "inspiration", "offers"}

type ProfileHandler struct {
	queries      *store.Queries
	profileAgent *agents.ProfileAgent
}

func NewProfileHandler(q *store.Queries, pa *agents.ProfileAgent) *ProfileHandler {
	return &ProfileHandler{queries: q, profileAgent: pa}
}

func (h *ProfileHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "profile" && r.Method == "GET":
		h.showProfile(w, r, projectID)
	case rest == "profile/inputs" && r.Method == "POST":
		h.addInput(w, r, projectID)
	case rest == "profile/analyze" && r.Method == "POST":
		h.analyze(w, r, projectID)
	case rest == "profile/references" && r.Method == "POST":
		h.addReference(w, r, projectID)
	case strings.HasSuffix(rest, "/approve") && strings.Contains(rest, "profile/proposals/"):
		h.approveProposal(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/reject") && strings.Contains(rest, "profile/proposals/"):
		h.rejectProposal(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/delete-input") && strings.Contains(rest, "profile/inputs/"):
		h.deleteInput(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/delete-ref") && strings.Contains(rest, "profile/references/"):
		h.deleteReference(w, r, projectID, rest)
	default:
		http.NotFound(w, r)
	}
}

func (h *ProfileHandler) showProfile(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	activeTab := r.URL.Query().Get("tab")
	if activeTab == "" {
		activeTab = "business"
	}

	// Load sections
	existingSections, _ := h.queries.ListProfileSections(projectID)
	sectionMap := make(map[string]string)
	for _, s := range existingSections {
		sectionMap[s.Section] = s.Content
	}

	// Load pending proposals
	pendingProposals, _ := h.queries.ListPendingProposals(projectID)
	proposalsBySection := make(map[string][]templates.ProposalView)
	for _, p := range pendingProposals {
		proposalsBySection[p.Section] = append(proposalsBySection[p.Section], templates.ProposalView{
			ID:      p.ID,
			Section: p.Section,
			Content: p.ProposedContent,
		})
	}

	// Build section views
	var sectionViews []templates.ProfileSectionView
	for _, name := range allSections {
		inputs, _ := h.queries.ListSectionInputs(projectID, name)
		inputViews := make([]templates.SectionInputView, len(inputs))
		for i, inp := range inputs {
			inputViews[i] = templates.SectionInputView{
				ID:      inp.ID,
				Title:   inp.Title,
				Content: inp.Content,
				Section: inp.Section,
			}
		}

		content := sectionMap[name]
		sectionViews = append(sectionViews, templates.ProfileSectionView{
			Name:      name,
			Content:   content,
			HasData:   content != "" && content != "{}",
			Inputs:    inputViews,
			Proposals: proposalsBySection[name],
		})
	}

	// Load references
	refs, _ := h.queries.ListReferences(projectID)
	refViews := make([]templates.ReferenceView, len(refs))
	for i, ref := range refs {
		refViews[i] = templates.ReferenceView{
			ID:        ref.ID,
			Title:     ref.Title,
			Content:   ref.Content,
			SourceURL: ref.SourceURL,
			SavedBy:   ref.SavedBy,
		}
	}

	data := templates.ProfilePageData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Sections:    sectionViews,
		References:  refViews,
		ActiveTab:   activeTab,
		AllSections: allSections,
	}

	templates.ProfilePage(data).Render(r.Context(), w)
}

func (h *ProfileHandler) addInput(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	section := r.FormValue("section")
	title := r.FormValue("title")
	content := r.FormValue("content")
	sourceURL := r.FormValue("source_url")

	if content == "" {
		http.Error(w, "Content required", http.StatusBadRequest)
		return
	}

	_, err := h.queries.CreateSectionInput(projectID, section, title, content, sourceURL)
	if err != nil {
		http.Error(w, "Failed to add input", http.StatusInternalServerError)
		return
	}

	tab := section
	if tab == "" {
		tab = "business"
	}
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile?tab=%s", projectID, tab), http.StatusSeeOther)
}

func (h *ProfileHandler) analyze(w http.ResponseWriter, r *http.Request, projectID int64) {
	// Load all inputs
	inputs, _ := h.queries.ListSectionInputs(projectID, "")
	var inputTexts []string
	for _, inp := range inputs {
		text := inp.Content
		if inp.Title != "" {
			text = inp.Title + ": " + text
		}
		inputTexts = append(inputTexts, text)
	}

	if len(inputTexts) == 0 {
		http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile?error=no_inputs", projectID), http.StatusSeeOther)
		return
	}

	// Load existing sections
	existingSections, _ := h.queries.ListProfileSections(projectID)
	existingMap := make(map[string]string)
	for _, s := range existingSections {
		existingMap[s.Section] = s.Content
	}

	// Load rejected proposals for context
	rejected, _ := h.queries.ListRejectedProposals(projectID)
	var rejections []string
	for _, rj := range rejected {
		if rj.RejectionReason != "" {
			rejections = append(rejections, fmt.Sprintf("%s: %s", rj.Section, rj.RejectionReason))
		}
	}

	proposals, err := h.profileAgent.Analyze(r.Context(), agents.ProfileAnalysisInput{
		Inputs:           inputTexts,
		ExistingSections: existingMap,
		Rejections:       rejections,
	})
	if err != nil {
		http.Error(w, "Analysis failed: "+err.Error(), http.StatusInternalServerError)
		return
	}

	// Create proposals in DB
	for _, p := range proposals {
		contentBytes, _ := json.Marshal(p.Content)
		h.queries.CreateProposal(projectID, p.Section, string(contentBytes))
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile", projectID), http.StatusSeeOther)
}

func (h *ProfileHandler) approveProposal(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "profile/proposals/123/approve"
	id := extractID(rest, "profile/proposals/", "/approve")
	if id == 0 {
		http.NotFound(w, r)
		return
	}

	r.ParseForm()
	editedContent := r.FormValue("edited_content")

	var err error
	if editedContent != "" {
		err = h.queries.ApproveProposalWithEdit(id, editedContent)
	} else {
		err = h.queries.ApproveProposal(id)
	}
	if err != nil {
		http.Error(w, "Failed to approve", http.StatusInternalServerError)
		return
	}

	// Get section for redirect tab
	prop, _ := h.queries.GetProposal(id)
	tab := "business"
	if prop != nil {
		tab = prop.Section
	}
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile?tab=%s", projectID, tab), http.StatusSeeOther)
}

func (h *ProfileHandler) rejectProposal(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "profile/proposals/123/reject"
	id := extractID(rest, "profile/proposals/", "/reject")
	if id == 0 {
		http.NotFound(w, r)
		return
	}

	r.ParseForm()
	reason := r.FormValue("reason")

	prop, _ := h.queries.GetProposal(id)
	tab := "business"
	if prop != nil {
		tab = prop.Section
	}

	if err := h.queries.RejectProposal(id, reason); err != nil {
		http.Error(w, "Failed to reject", http.StatusInternalServerError)
		return
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile?tab=%s", projectID, tab), http.StatusSeeOther)
}

func (h *ProfileHandler) addReference(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	title := r.FormValue("title")
	content := r.FormValue("content")
	sourceURL := r.FormValue("source_url")

	if content == "" {
		http.Error(w, "Content required", http.StatusBadRequest)
		return
	}

	_, err := h.queries.CreateReference(projectID, title, content, sourceURL, "user")
	if err != nil {
		http.Error(w, "Failed to add reference", http.StatusInternalServerError)
		return
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile?tab=references", projectID), http.StatusSeeOther)
}

func (h *ProfileHandler) deleteInput(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "profile/inputs/123/delete-input"
	id := extractID(rest, "profile/inputs/", "/delete-input")
	if id == 0 {
		http.NotFound(w, r)
		return
	}

	inp, _ := h.queries.GetSectionInput(id)
	tab := "business"
	if inp != nil && inp.Section != "" {
		tab = inp.Section
	}

	h.queries.DeleteSectionInput(id)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile?tab=%s", projectID, tab), http.StatusSeeOther)
}

func (h *ProfileHandler) deleteReference(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "profile/references/123/delete-ref"
	id := extractID(rest, "profile/references/", "/delete-ref")
	if id == 0 {
		http.NotFound(w, r)
		return
	}

	h.queries.DeleteReference(id)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile?tab=references", projectID), http.StatusSeeOther)
}

// extractID parses an int64 ID from a path like "profile/proposals/123/approve"
// given prefix "profile/proposals/" and suffix "/approve"
func extractID(rest, prefix, suffix string) int64 {
	trimmed := strings.TrimPrefix(rest, prefix)
	trimmed = strings.TrimSuffix(trimmed, suffix)
	id, err := strconv.ParseInt(trimmed, 10, 64)
	if err != nil {
		return 0
	}
	return id
}
