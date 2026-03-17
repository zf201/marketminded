package store

import "time"

type ContentPiece struct {
	ID              int64
	ProjectID       int64
	PipelineRunID   int64
	Platform        string
	Format          string
	Title           string
	Body            string
	Status          string
	ParentID        *int64
	SortOrder       int
	RejectionReason string
	CreatedAt       time.Time
	UpdatedAt       time.Time
}

func (q *Queries) CreateContentPiece(projectID, pipelineRunID int64, platform, format, title string, sortOrder int, parentID *int64) (*ContentPiece, error) {
	res, err := q.db.Exec(
		"INSERT INTO content_pieces (project_id, pipeline_run_id, platform, format, title, sort_order, parent_id) VALUES (?, ?, ?, ?, ?, ?, ?)",
		projectID, pipelineRunID, platform, format, title, sortOrder, parentID,
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
		`SELECT id, project_id, pipeline_run_id, platform, format, COALESCE(title,''), body, status,
		 parent_id, sort_order, COALESCE(rejection_reason,''), created_at, updated_at
		 FROM content_pieces WHERE id = ?`, id,
	).Scan(&c.ID, &c.ProjectID, &c.PipelineRunID, &c.Platform, &c.Format, &c.Title, &c.Body,
		&c.Status, &c.ParentID, &c.SortOrder, &c.RejectionReason, &c.CreatedAt, &c.UpdatedAt)
	return c, err
}

func (q *Queries) UpdateContentPieceBody(id int64, title, body string) error {
	_, err := q.db.Exec("UPDATE content_pieces SET title = ?, body = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", title, body, id)
	return err
}

func (q *Queries) SetContentPieceStatus(id int64, status string) error {
	_, err := q.db.Exec("UPDATE content_pieces SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", status, id)
	return err
}

// TrySetGenerating atomically sets status to generating if currently pending or rejected.
// Returns true if the update happened (safe to proceed with generation).
func (q *Queries) TrySetGenerating(id int64) (bool, error) {
	res, err := q.db.Exec(
		"UPDATE content_pieces SET status = 'generating', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('pending', 'rejected')", id,
	)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}

func (q *Queries) SetContentPieceRejection(id int64, reason string) error {
	_, err := q.db.Exec("UPDATE content_pieces SET status = 'rejected', rejection_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", reason, id)
	return err
}

func (q *Queries) ListContentByPipelineRun(runID int64) ([]ContentPiece, error) {
	rows, err := q.db.Query(
		`SELECT id, project_id, pipeline_run_id, platform, format, COALESCE(title,''), body, status,
		 parent_id, sort_order, COALESCE(rejection_reason,''), created_at, updated_at
		 FROM content_pieces WHERE pipeline_run_id = ? ORDER BY sort_order ASC`, runID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var pieces []ContentPiece
	for rows.Next() {
		var c ContentPiece
		if err := rows.Scan(&c.ID, &c.ProjectID, &c.PipelineRunID, &c.Platform, &c.Format, &c.Title, &c.Body,
			&c.Status, &c.ParentID, &c.SortOrder, &c.RejectionReason, &c.CreatedAt, &c.UpdatedAt); err != nil {
			return nil, err
		}
		pieces = append(pieces, c)
	}
	return pieces, rows.Err()
}

func (q *Queries) NextPendingPiece(runID int64) (*ContentPiece, error) {
	c := &ContentPiece{}
	err := q.db.QueryRow(
		`SELECT id, project_id, pipeline_run_id, platform, format, COALESCE(title,''), body, status,
		 parent_id, sort_order, COALESCE(rejection_reason,''), created_at, updated_at
		 FROM content_pieces WHERE pipeline_run_id = ? AND status = 'pending' ORDER BY sort_order ASC LIMIT 1`, runID,
	).Scan(&c.ID, &c.ProjectID, &c.PipelineRunID, &c.Platform, &c.Format, &c.Title, &c.Body,
		&c.Status, &c.ParentID, &c.SortOrder, &c.RejectionReason, &c.CreatedAt, &c.UpdatedAt)
	if err != nil {
		return nil, err
	}
	return c, nil
}

func (q *Queries) AllPiecesApproved(runID int64) (bool, error) {
	var count int
	err := q.db.QueryRow("SELECT COUNT(*) FROM content_pieces WHERE pipeline_run_id = ? AND status != 'approved'", runID).Scan(&count)
	return count == 0, err
}
