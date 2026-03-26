package store

import "time"

type PipelineRun struct {
	ID        int64
	ProjectID int64
	Topic     string
	Brief     string
	Plan      string
	Phase     string
	Status    string
	CreatedAt time.Time
	UpdatedAt time.Time
}

func (q *Queries) CreatePipelineRun(projectID int64, brief string) (*PipelineRun, error) {
	topic := time.Now().Format("Jan 2, 2006 3:04 PM")
	res, err := q.db.Exec("INSERT INTO pipeline_runs (project_id, topic, brief) VALUES (?, ?, ?)", projectID, topic, brief)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetPipelineRun(id)
}

func (q *Queries) GetPipelineRun(id int64) (*PipelineRun, error) {
	r := &PipelineRun{}
	err := q.db.QueryRow(
		"SELECT id, project_id, topic, COALESCE(brief,''), COALESCE(plan,''), phase, status, created_at, updated_at FROM pipeline_runs WHERE id = ?", id,
	).Scan(&r.ID, &r.ProjectID, &r.Topic, &r.Brief, &r.Plan, &r.Phase, &r.Status, &r.CreatedAt, &r.UpdatedAt)
	return r, err
}

func (q *Queries) UpdatePipelineTopic(id int64, topic string) error {
	_, err := q.db.Exec("UPDATE pipeline_runs SET topic = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", topic, id)
	return err
}

func (q *Queries) UpdatePipelinePlan(id int64, plan string) error {
	_, err := q.db.Exec("UPDATE pipeline_runs SET plan = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", plan, id)
	return err
}

func (q *Queries) UpdatePipelineStatus(id int64, status string) error {
	_, err := q.db.Exec("UPDATE pipeline_runs SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", status, id)
	return err
}

func (q *Queries) DeletePipelineRun(id int64) error {
	_, err := q.db.Exec("DELETE FROM pipeline_runs WHERE id = ?", id)
	return err
}

func (q *Queries) UpdatePipelinePhase(id int64, phase string) error {
	_, err := q.db.Exec("UPDATE pipeline_runs SET phase = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", phase, id)
	return err
}

func (q *Queries) ListPipelineRuns(projectID int64) ([]PipelineRun, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, topic, COALESCE(brief,''), COALESCE(plan,''), phase, status, created_at, updated_at FROM pipeline_runs WHERE project_id = ? ORDER BY created_at DESC", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var runs []PipelineRun
	for rows.Next() {
		var r PipelineRun
		if err := rows.Scan(&r.ID, &r.ProjectID, &r.Topic, &r.Brief, &r.Plan, &r.Phase, &r.Status, &r.CreatedAt, &r.UpdatedAt); err != nil {
			return nil, err
		}
		runs = append(runs, r)
	}
	return runs, rows.Err()
}
