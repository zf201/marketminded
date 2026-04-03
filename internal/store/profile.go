package store

import (
	"encoding/json"
	"fmt"
	"strings"
	"time"
)

type SourceURL struct {
	URL   string `json:"url"`
	Notes string `json:"notes"`
}

type ProfileSection struct {
	ID         int64
	ProjectID  int64
	Section    string
	Content    string
	SourceURLs string // JSON array of SourceURL
	UpdatedAt  time.Time
}

type ProfileVersion struct {
	ID        int64
	ProjectID int64
	Section   string
	Content   string
	CreatedAt time.Time
}

func (q *Queries) UpsertProfileSection(projectID int64, section, content string) error {
	_, err := q.db.Exec(
		`INSERT INTO profile_sections (project_id, section, content) VALUES (?, ?, ?)
		 ON CONFLICT(project_id, section) DO UPDATE SET content = ?, updated_at = CURRENT_TIMESTAMP`,
		projectID, section, content, content,
	)
	return err
}

func (q *Queries) UpsertProfileSectionFull(projectID int64, section, content, sourceURLs string) error {
	_, err := q.db.Exec(
		`INSERT INTO profile_sections (project_id, section, content, source_urls) VALUES (?, ?, ?, ?)
		 ON CONFLICT(project_id, section) DO UPDATE SET content = ?, source_urls = ?, updated_at = CURRENT_TIMESTAMP`,
		projectID, section, content, sourceURLs, content, sourceURLs,
	)
	return err
}

func (q *Queries) GetProfileSection(projectID int64, section string) (*ProfileSection, error) {
	s := &ProfileSection{}
	err := q.db.QueryRow(
		"SELECT id, project_id, section, content, source_urls, updated_at FROM profile_sections WHERE project_id = ? AND section = ?",
		projectID, section,
	).Scan(&s.ID, &s.ProjectID, &s.Section, &s.Content, &s.SourceURLs, &s.UpdatedAt)
	return s, err
}

func (q *Queries) ListProfileSections(projectID int64) ([]ProfileSection, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, section, content, source_urls, updated_at FROM profile_sections WHERE project_id = ? ORDER BY section",
		projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var sections []ProfileSection
	for rows.Next() {
		var s ProfileSection
		if err := rows.Scan(&s.ID, &s.ProjectID, &s.Section, &s.Content, &s.SourceURLs, &s.UpdatedAt); err != nil {
			return nil, err
		}
		sections = append(sections, s)
	}
	return sections, rows.Err()
}

func (q *Queries) SaveProfileVersion(projectID int64, section, content string) error {
	_, err := q.db.Exec(
		"INSERT INTO profile_section_versions (project_id, section, content) VALUES (?, ?, ?)",
		projectID, section, content,
	)
	if err != nil {
		return err
	}

	_, err = q.db.Exec(`
		DELETE FROM profile_section_versions
		WHERE project_id = ? AND section = ? AND id NOT IN (
			SELECT id FROM profile_section_versions
			WHERE project_id = ? AND section = ?
			ORDER BY id DESC LIMIT 5
		)`, projectID, section, projectID, section)
	return err
}

func (q *Queries) ListProfileVersions(projectID int64, section string) ([]ProfileVersion, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, section, content, created_at FROM profile_section_versions WHERE project_id = ? AND section = ? ORDER BY id DESC",
		projectID, section,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var versions []ProfileVersion
	for rows.Next() {
		var v ProfileVersion
		if err := rows.Scan(&v.ID, &v.ProjectID, &v.Section, &v.Content, &v.CreatedAt); err != nil {
			return nil, err
		}
		versions = append(versions, v)
	}
	return versions, rows.Err()
}

// BuildSourceURLList returns a formatted string of source URLs from the
// product_and_positioning section, for use by the brand enricher pipeline step.
func (q *Queries) BuildSourceURLList(projectID int64) (string, error) {
	section, err := q.GetProfileSection(projectID, "product_and_positioning")
	if err != nil {
		return "", nil
	}

	var urls []SourceURL
	if err := json.Unmarshal([]byte(section.SourceURLs), &urls); err != nil || len(urls) == 0 {
		return "", nil
	}

	var b strings.Builder
	b.WriteString("## Must-Use URLs (fetch these for latest data)\n")
	for _, u := range urls {
		fmt.Fprintf(&b, "- %s", u.URL)
		if u.Notes != "" {
			fmt.Fprintf(&b, " (Usage notes: %s)", u.Notes)
		}
		b.WriteString("\n")
	}
	return b.String(), nil
}

func (q *Queries) prependMemory(projectID int64, b *strings.Builder) {
	if mem, err := q.GetProjectSetting(projectID, "memory"); err == nil && mem != "" {
		fmt.Fprintf(b, "## Important rules and facts\n%s\n\n", mem)
	}
}

func (q *Queries) BuildProfileString(projectID int64) (string, error) {
	sections, err := q.ListProfileSections(projectID)
	if err != nil {
		return "", err
	}
	var b strings.Builder
	q.prependMemory(projectID, &b)
	for _, s := range sections {
		if s.Content == "" {
			continue
		}
		fmt.Fprintf(&b, "## %s\n%s\n\n", sectionTitle(s.Section), s.Content)
	}
	return b.String(), nil
}

func (q *Queries) BuildProfileStringExcluding(projectID int64, exclude []string) (string, error) {
	sections, err := q.ListProfileSections(projectID)
	if err != nil {
		return "", err
	}
	excludeMap := make(map[string]bool, len(exclude))
	for _, e := range exclude {
		excludeMap[e] = true
	}
	var b strings.Builder
	q.prependMemory(projectID, &b)
	for _, s := range sections {
		if s.Content == "" || excludeMap[s.Section] {
			continue
		}
		fmt.Fprintf(&b, "## %s\n%s\n\n", sectionTitle(s.Section), s.Content)
	}
	return b.String(), nil
}

var sectionDisplayTitles = map[string]string{
	"content_strategy": "Social Content Strategy",
}

func sectionTitle(s string) string {
	if t, ok := sectionDisplayTitles[s]; ok {
		return t
	}
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
