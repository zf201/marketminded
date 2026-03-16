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

func (q *Queries) BuildProfileString(projectID int64) (string, error) {
	sections, err := q.ListProfileSections(projectID)
	if err != nil {
		return "", err
	}
	var b strings.Builder
	for _, s := range sections {
		if s.Content == "" {
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
