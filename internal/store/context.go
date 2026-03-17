package store

import (
	"fmt"
	"strings"
	"time"
)

type ContextItem struct {
	ID        int64
	ProjectID int64
	Title     string
	Content   string
	CreatedAt time.Time
	UpdatedAt time.Time
}

func (q *Queries) CreateContextItem(projectID int64, title string) (*ContextItem, error) {
	res, err := q.db.Exec("INSERT INTO context_items (project_id, title) VALUES (?, ?)", projectID, title)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetContextItem(id)
}

func (q *Queries) GetContextItem(id int64) (*ContextItem, error) {
	c := &ContextItem{}
	err := q.db.QueryRow(
		"SELECT id, project_id, title, content, created_at, updated_at FROM context_items WHERE id = ?", id,
	).Scan(&c.ID, &c.ProjectID, &c.Title, &c.Content, &c.CreatedAt, &c.UpdatedAt)
	return c, err
}

func (q *Queries) UpdateContextItem(id int64, title, content string) error {
	_, err := q.db.Exec("UPDATE context_items SET title = ?, content = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", title, content, id)
	return err
}

func (q *Queries) DeleteContextItem(id int64) error {
	_, err := q.db.Exec("DELETE FROM context_items WHERE id = ?", id)
	return err
}

func (q *Queries) ListContextItems(projectID int64) ([]ContextItem, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, title, content, created_at, updated_at FROM context_items WHERE project_id = ? ORDER BY created_at DESC", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []ContextItem
	for rows.Next() {
		var c ContextItem
		if err := rows.Scan(&c.ID, &c.ProjectID, &c.Title, &c.Content, &c.CreatedAt, &c.UpdatedAt); err != nil {
			return nil, err
		}
		items = append(items, c)
	}
	return items, rows.Err()
}

// BuildContextString serializes all context items for prompt injection.
func (q *Queries) BuildContextString(projectID int64) (string, error) {
	items, err := q.ListContextItems(projectID)
	if err != nil {
		return "", err
	}
	if len(items) == 0 {
		return "", nil
	}
	var b strings.Builder
	for _, item := range items {
		if item.Content == "" {
			continue
		}
		fmt.Fprintf(&b, "### %s\n%s\n\n", item.Title, item.Content)
	}
	return b.String(), nil
}
