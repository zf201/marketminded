package store

import "time"

type KnowledgeItem struct {
	ID        int64
	ProjectID int64
	Type      string
	Title     string
	Content   string
	SourceURL string
	CreatedAt time.Time
}

func (q *Queries) CreateKnowledgeItem(projectID int64, itemType, title, content, sourceURL string) (*KnowledgeItem, error) {
	res, err := q.db.Exec(
		"INSERT INTO knowledge_items (project_id, type, title, content, source_url) VALUES (?, ?, ?, ?, ?)",
		projectID, itemType, title, content, sourceURL,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetKnowledgeItem(id)
}

func (q *Queries) GetKnowledgeItem(id int64) (*KnowledgeItem, error) {
	k := &KnowledgeItem{}
	err := q.db.QueryRow(
		"SELECT id, project_id, type, COALESCE(title,''), content, COALESCE(source_url,''), created_at FROM knowledge_items WHERE id = ?", id,
	).Scan(&k.ID, &k.ProjectID, &k.Type, &k.Title, &k.Content, &k.SourceURL, &k.CreatedAt)
	return k, err
}

func (q *Queries) ListKnowledgeItems(projectID int64, itemType string) ([]KnowledgeItem, error) {
	query := "SELECT id, project_id, type, COALESCE(title,''), content, COALESCE(source_url,''), created_at FROM knowledge_items WHERE project_id = ?"
	args := []any{projectID}
	if itemType != "" {
		query += " AND type = ?"
		args = append(args, itemType)
	}
	query += " ORDER BY created_at DESC"

	rows, err := q.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []KnowledgeItem
	for rows.Next() {
		var k KnowledgeItem
		if err := rows.Scan(&k.ID, &k.ProjectID, &k.Type, &k.Title, &k.Content, &k.SourceURL, &k.CreatedAt); err != nil {
			return nil, err
		}
		items = append(items, k)
	}
	return items, rows.Err()
}

func (q *Queries) DeleteKnowledgeItem(id int64) error {
	_, err := q.db.Exec("DELETE FROM knowledge_items WHERE id = ?", id)
	return err
}
