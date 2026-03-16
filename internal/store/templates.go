package store

import (
	"fmt"
	"strings"
	"time"
)

type Template struct {
	ID          int64
	ProjectID   int64
	Name        string
	Platform    string
	HTMLContent string
	CreatedAt   time.Time
}

func (q *Queries) CreateTemplate(projectID int64, name, platform, htmlContent string) (*Template, error) {
	if err := ValidateTemplate(htmlContent); err != nil {
		return nil, err
	}
	res, err := q.db.Exec(
		"INSERT INTO templates (project_id, name, platform, html_content) VALUES (?, ?, ?, ?)",
		projectID, name, platform, htmlContent,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetTemplate(id)
}

func (q *Queries) GetTemplate(id int64) (*Template, error) {
	t := &Template{}
	err := q.db.QueryRow(
		"SELECT id, project_id, name, platform, html_content, created_at FROM templates WHERE id = ?", id,
	).Scan(&t.ID, &t.ProjectID, &t.Name, &t.Platform, &t.HTMLContent, &t.CreatedAt)
	return t, err
}

func (q *Queries) ListTemplates(projectID int64) ([]Template, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, name, platform, html_content, created_at FROM templates WHERE project_id = ? ORDER BY platform, name", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var templates []Template
	for rows.Next() {
		var t Template
		if err := rows.Scan(&t.ID, &t.ProjectID, &t.Name, &t.Platform, &t.HTMLContent, &t.CreatedAt); err != nil {
			return nil, err
		}
		templates = append(templates, t)
	}
	return templates, rows.Err()
}

func (q *Queries) ListTemplatesByPlatform(projectID int64, platform string) ([]Template, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, name, platform, html_content, created_at FROM templates WHERE project_id = ? AND platform = ?", projectID, platform,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var templates []Template
	for rows.Next() {
		var t Template
		if err := rows.Scan(&t.ID, &t.ProjectID, &t.Name, &t.Platform, &t.HTMLContent, &t.CreatedAt); err != nil {
			return nil, err
		}
		templates = append(templates, t)
	}
	return templates, rows.Err()
}

func (q *Queries) UpdateTemplate(id int64, name, htmlContent string) error {
	if err := ValidateTemplate(htmlContent); err != nil {
		return err
	}
	_, err := q.db.Exec("UPDATE templates SET name = ?, html_content = ? WHERE id = ?", name, htmlContent, id)
	return err
}

func (q *Queries) DeleteTemplate(id int64) error {
	_, err := q.db.Exec("DELETE FROM templates WHERE id = ?", id)
	return err
}

func ValidateTemplate(htmlContent string) error {
	required := []string{"{{.Title}}", "{{.Body}}"}
	for _, slot := range required {
		if !strings.Contains(htmlContent, slot) {
			return fmt.Errorf("template missing required slot: %s", slot)
		}
	}
	return nil
}
