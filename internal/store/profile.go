package store

import (
	"fmt"
	"strings"
	"time"
)

type ProfileSection struct {
	ID        int64
	ProjectID int64
	Section   string
	Content   string
	UpdatedAt time.Time
}

type SectionInput struct {
	ID        int64
	ProjectID int64
	Section   string
	Title     string
	Content   string
	SourceURL string
	CreatedAt time.Time
}

type SectionProposal struct {
	ID              int64
	ProjectID       int64
	Section         string
	ProposedContent string
	Status          string
	RejectionReason string
	CreatedAt       time.Time
}

type ProjectReference struct {
	ID        int64
	ProjectID int64
	Title     string
	Content   string
	SourceURL string
	SavedBy   string
	CreatedAt time.Time
}

// Profile sections

func (q *Queries) UpsertProfileSection(projectID int64, section, content string) error {
	_, err := q.db.Exec(
		`INSERT INTO profile_sections (project_id, section, content) VALUES (?, ?, ?)
		 ON CONFLICT(project_id, section) DO UPDATE SET content = ?, updated_at = CURRENT_TIMESTAMP`,
		projectID, section, content, content,
	)
	return err
}

func (q *Queries) GetProfileSection(projectID int64, section string) (*ProfileSection, error) {
	s := &ProfileSection{}
	err := q.db.QueryRow(
		"SELECT id, project_id, section, content, updated_at FROM profile_sections WHERE project_id = ? AND section = ?",
		projectID, section,
	).Scan(&s.ID, &s.ProjectID, &s.Section, &s.Content, &s.UpdatedAt)
	return s, err
}

func (q *Queries) ListProfileSections(projectID int64) ([]ProfileSection, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, section, content, updated_at FROM profile_sections WHERE project_id = ? ORDER BY section",
		projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var sections []ProfileSection
	for rows.Next() {
		var s ProfileSection
		if err := rows.Scan(&s.ID, &s.ProjectID, &s.Section, &s.Content, &s.UpdatedAt); err != nil {
			return nil, err
		}
		sections = append(sections, s)
	}
	return sections, rows.Err()
}

// BuildProfileString serializes all sections into a single string for prompt injection.
func (q *Queries) BuildProfileString(projectID int64) (string, error) {
	sections, err := q.ListProfileSections(projectID)
	if err != nil {
		return "", err
	}
	var b strings.Builder
	for _, s := range sections {
		if s.Content == "{}" {
			continue
		}
		fmt.Fprintf(&b, "## %s\n%s\n\n", sectionTitle(s.Section), s.Content)
	}
	return b.String(), nil
}

func sectionTitle(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}

// Section inputs

func (q *Queries) CreateSectionInput(projectID int64, section, title, content, sourceURL string) (*SectionInput, error) {
	var sectionVal any
	if section != "" {
		sectionVal = section
	}
	res, err := q.db.Exec(
		"INSERT INTO section_inputs (project_id, section, title, content, source_url) VALUES (?, ?, ?, ?, ?)",
		projectID, sectionVal, title, content, sourceURL,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetSectionInput(id)
}

func (q *Queries) GetSectionInput(id int64) (*SectionInput, error) {
	i := &SectionInput{}
	err := q.db.QueryRow(
		"SELECT id, project_id, COALESCE(section,''), COALESCE(title,''), content, COALESCE(source_url,''), created_at FROM section_inputs WHERE id = ?", id,
	).Scan(&i.ID, &i.ProjectID, &i.Section, &i.Title, &i.Content, &i.SourceURL, &i.CreatedAt)
	return i, err
}

func (q *Queries) ListSectionInputs(projectID int64, section string) ([]SectionInput, error) {
	query := "SELECT id, project_id, COALESCE(section,''), COALESCE(title,''), content, COALESCE(source_url,''), created_at FROM section_inputs WHERE project_id = ?"
	args := []any{projectID}
	if section != "" {
		query += " AND section = ?"
		args = append(args, section)
	}
	query += " ORDER BY created_at DESC"

	rows, err := q.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var inputs []SectionInput
	for rows.Next() {
		var i SectionInput
		if err := rows.Scan(&i.ID, &i.ProjectID, &i.Section, &i.Title, &i.Content, &i.SourceURL, &i.CreatedAt); err != nil {
			return nil, err
		}
		inputs = append(inputs, i)
	}
	return inputs, rows.Err()
}

func (q *Queries) DeleteSectionInput(id int64) error {
	_, err := q.db.Exec("DELETE FROM section_inputs WHERE id = ?", id)
	return err
}

// Section proposals

func (q *Queries) CreateProposal(projectID int64, section, proposedContent string) (*SectionProposal, error) {
	res, err := q.db.Exec(
		"INSERT INTO section_proposals (project_id, section, proposed_content) VALUES (?, ?, ?)",
		projectID, section, proposedContent,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetProposal(id)
}

func (q *Queries) GetProposal(id int64) (*SectionProposal, error) {
	p := &SectionProposal{}
	err := q.db.QueryRow(
		"SELECT id, project_id, section, proposed_content, status, COALESCE(rejection_reason,''), created_at FROM section_proposals WHERE id = ?", id,
	).Scan(&p.ID, &p.ProjectID, &p.Section, &p.ProposedContent, &p.Status, &p.RejectionReason, &p.CreatedAt)
	return p, err
}

func (q *Queries) ListPendingProposals(projectID int64) ([]SectionProposal, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, section, proposed_content, status, COALESCE(rejection_reason,''), created_at FROM section_proposals WHERE project_id = ? AND status = 'pending' ORDER BY created_at ASC",
		projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var proposals []SectionProposal
	for rows.Next() {
		var p SectionProposal
		if err := rows.Scan(&p.ID, &p.ProjectID, &p.Section, &p.ProposedContent, &p.Status, &p.RejectionReason, &p.CreatedAt); err != nil {
			return nil, err
		}
		proposals = append(proposals, p)
	}
	return proposals, rows.Err()
}

func (q *Queries) ListRejectedProposals(projectID int64) ([]SectionProposal, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, section, proposed_content, status, COALESCE(rejection_reason,''), created_at FROM section_proposals WHERE project_id = ? AND status = 'rejected' ORDER BY created_at DESC LIMIT 20",
		projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var proposals []SectionProposal
	for rows.Next() {
		var p SectionProposal
		if err := rows.Scan(&p.ID, &p.ProjectID, &p.Section, &p.ProposedContent, &p.Status, &p.RejectionReason, &p.CreatedAt); err != nil {
			return nil, err
		}
		proposals = append(proposals, p)
	}
	return proposals, rows.Err()
}

func (q *Queries) ApproveProposal(id int64) error {
	prop, err := q.GetProposal(id)
	if err != nil {
		return err
	}
	// Replace section content with proposed content
	if err := q.UpsertProfileSection(prop.ProjectID, prop.Section, prop.ProposedContent); err != nil {
		return err
	}
	_, err = q.db.Exec("UPDATE section_proposals SET status = 'approved' WHERE id = ?", id)
	return err
}

func (q *Queries) ApproveProposalWithEdit(id int64, editedContent string) error {
	prop, err := q.GetProposal(id)
	if err != nil {
		return err
	}
	if err := q.UpsertProfileSection(prop.ProjectID, prop.Section, editedContent); err != nil {
		return err
	}
	_, err = q.db.Exec("UPDATE section_proposals SET status = 'approved', proposed_content = ? WHERE id = ?", editedContent, id)
	return err
}

func (q *Queries) RejectProposal(id int64, reason string) error {
	_, err := q.db.Exec("UPDATE section_proposals SET status = 'rejected', rejection_reason = ? WHERE id = ?", reason, id)
	return err
}

// References

func (q *Queries) CreateReference(projectID int64, title, content, sourceURL, savedBy string) (*ProjectReference, error) {
	res, err := q.db.Exec(
		"INSERT INTO project_references (project_id, title, content, source_url, saved_by) VALUES (?, ?, ?, ?, ?)",
		projectID, title, content, sourceURL, savedBy,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	r := &ProjectReference{}
	err = q.db.QueryRow(
		"SELECT id, project_id, COALESCE(title,''), content, COALESCE(source_url,''), saved_by, created_at FROM project_references WHERE id = ?", id,
	).Scan(&r.ID, &r.ProjectID, &r.Title, &r.Content, &r.SourceURL, &r.SavedBy, &r.CreatedAt)
	return r, err
}

func (q *Queries) ListReferences(projectID int64) ([]ProjectReference, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, COALESCE(title,''), content, COALESCE(source_url,''), saved_by, created_at FROM project_references WHERE project_id = ? ORDER BY created_at DESC",
		projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var refs []ProjectReference
	for rows.Next() {
		var r ProjectReference
		if err := rows.Scan(&r.ID, &r.ProjectID, &r.Title, &r.Content, &r.SourceURL, &r.SavedBy, &r.CreatedAt); err != nil {
			return nil, err
		}
		refs = append(refs, r)
	}
	return refs, rows.Err()
}

func (q *Queries) DeleteReference(id int64) error {
	_, err := q.db.Exec("DELETE FROM project_references WHERE id = ?", id)
	return err
}
