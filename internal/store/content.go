package store

import "time"

type ContentPiece struct {
	ID            int64
	ProjectID     int64
	PipelineRunID *int64
	Type          string
	Title         string
	Body          string
	Status        string
	ParentID      *int64
	CreatedAt     time.Time
}

func (q *Queries) CreateContentPiece(projectID int64, pipelineRunID *int64, contentType, title, body string, parentID *int64) (*ContentPiece, error) {
	res, err := q.db.Exec(
		"INSERT INTO content_pieces (project_id, pipeline_run_id, type, title, body, parent_id) VALUES (?, ?, ?, ?, ?, ?)",
		projectID, pipelineRunID, contentType, title, body, parentID,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetContentPiece(id)
}

func (q *Queries) GetContentPiece(id int64) (*ContentPiece, error) {
	c := &ContentPiece{}
	err := q.db.QueryRow(
		"SELECT id, project_id, pipeline_run_id, type, COALESCE(title,''), body, status, parent_id, created_at FROM content_pieces WHERE id = ?", id,
	).Scan(&c.ID, &c.ProjectID, &c.PipelineRunID, &c.Type, &c.Title, &c.Body, &c.Status, &c.ParentID, &c.CreatedAt)
	return c, err
}

func (q *Queries) UpdateContentPiece(id int64, title, body string) error {
	_, err := q.db.Exec("UPDATE content_pieces SET title = ?, body = ? WHERE id = ?", title, body, id)
	return err
}

func (q *Queries) ApproveContentPiece(id int64) error {
	_, err := q.db.Exec("UPDATE content_pieces SET status = 'approved' WHERE id = ?", id)
	return err
}

func (q *Queries) ListContentPieces(projectID int64) ([]ContentPiece, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, pipeline_run_id, type, COALESCE(title,''), body, status, parent_id, created_at FROM content_pieces WHERE project_id = ? ORDER BY created_at DESC", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var pieces []ContentPiece
	for rows.Next() {
		var c ContentPiece
		if err := rows.Scan(&c.ID, &c.ProjectID, &c.PipelineRunID, &c.Type, &c.Title, &c.Body, &c.Status, &c.ParentID, &c.CreatedAt); err != nil {
			return nil, err
		}
		pieces = append(pieces, c)
	}
	return pieces, rows.Err()
}

func (q *Queries) ListContentByPipelineRun(runID int64) ([]ContentPiece, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, pipeline_run_id, type, COALESCE(title,''), body, status, parent_id, created_at FROM content_pieces WHERE pipeline_run_id = ? ORDER BY created_at ASC", runID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var pieces []ContentPiece
	for rows.Next() {
		var c ContentPiece
		if err := rows.Scan(&c.ID, &c.ProjectID, &c.PipelineRunID, &c.Type, &c.Title, &c.Body, &c.Status, &c.ParentID, &c.CreatedAt); err != nil {
			return nil, err
		}
		pieces = append(pieces, c)
	}
	return pieces, rows.Err()
}

func (q *Queries) ContentLogSummaries(projectID int64, limit int) ([]ContentPiece, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, pipeline_run_id, type, COALESCE(title,''), substr(body, 1, 200), status, parent_id, created_at FROM content_pieces WHERE project_id = ? AND status = 'approved' ORDER BY created_at DESC LIMIT ?",
		projectID, limit,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var pieces []ContentPiece
	for rows.Next() {
		var c ContentPiece
		if err := rows.Scan(&c.ID, &c.ProjectID, &c.PipelineRunID, &c.Type, &c.Title, &c.Body, &c.Status, &c.ParentID, &c.CreatedAt); err != nil {
			return nil, err
		}
		pieces = append(pieces, c)
	}
	return pieces, rows.Err()
}
