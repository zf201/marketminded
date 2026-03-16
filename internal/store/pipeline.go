package store

import "time"

type PipelineRun struct {
	ID            int64
	ProjectID     int64
	Status        string
	SelectedTopic *string
	CreatedAt     time.Time
	UpdatedAt     time.Time
}

func (q *Queries) CreatePipelineRun(projectID int64) (*PipelineRun, error) {
	res, err := q.db.Exec("INSERT INTO pipeline_runs (project_id) VALUES (?)", projectID)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetPipelineRun(id)
}

func (q *Queries) GetPipelineRun(id int64) (*PipelineRun, error) {
	r := &PipelineRun{}
	err := q.db.QueryRow(
		"SELECT id, project_id, status, selected_topic, created_at, updated_at FROM pipeline_runs WHERE id = ?", id,
	).Scan(&r.ID, &r.ProjectID, &r.Status, &r.SelectedTopic, &r.CreatedAt, &r.UpdatedAt)
	return r, err
}

func (q *Queries) AdvancePipelineRun(id int64, newStatus string) error {
	_, err := q.db.Exec(
		"UPDATE pipeline_runs SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
		newStatus, id,
	)
	return err
}

func (q *Queries) SetPipelineTopic(id int64, topic string) error {
	_, err := q.db.Exec(
		"UPDATE pipeline_runs SET selected_topic = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
		topic, id,
	)
	return err
}

func (q *Queries) ListPipelineRuns(projectID int64) ([]PipelineRun, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, status, selected_topic, created_at, updated_at FROM pipeline_runs WHERE project_id = ? ORDER BY created_at DESC", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var runs []PipelineRun
	for rows.Next() {
		var r PipelineRun
		if err := rows.Scan(&r.ID, &r.ProjectID, &r.Status, &r.SelectedTopic, &r.CreatedAt, &r.UpdatedAt); err != nil {
			return nil, err
		}
		runs = append(runs, r)
	}
	return runs, rows.Err()
}
