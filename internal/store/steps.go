package store

import "time"

type PipelineStep struct {
	ID            int64
	PipelineRunID int64
	StepType      string
	Status        string
	Input         string
	Output        string
	Thinking      string
	ToolCalls     string
	SortOrder     int
	CreatedAt     time.Time
	UpdatedAt     time.Time
}

func (q *Queries) CreatePipelineStep(pipelineRunID int64, stepType string, sortOrder int) (*PipelineStep, error) {
	res, err := q.db.Exec(
		"INSERT INTO pipeline_steps (pipeline_run_id, step_type, sort_order) VALUES (?, ?, ?)",
		pipelineRunID, stepType, sortOrder,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetPipelineStep(id)
}

func (q *Queries) GetPipelineStep(id int64) (*PipelineStep, error) {
	s := &PipelineStep{}
	err := q.db.QueryRow(
		`SELECT id, pipeline_run_id, step_type, status, input, output, thinking, tool_calls, sort_order, created_at, updated_at
		 FROM pipeline_steps WHERE id = ?`, id,
	).Scan(&s.ID, &s.PipelineRunID, &s.StepType, &s.Status, &s.Input, &s.Output, &s.Thinking, &s.ToolCalls, &s.SortOrder, &s.CreatedAt, &s.UpdatedAt)
	return s, err
}

func (q *Queries) ListPipelineSteps(pipelineRunID int64) ([]PipelineStep, error) {
	rows, err := q.db.Query(
		`SELECT id, pipeline_run_id, step_type, status, input, output, thinking, tool_calls, sort_order, created_at, updated_at
		 FROM pipeline_steps WHERE pipeline_run_id = ? ORDER BY sort_order ASC`, pipelineRunID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var steps []PipelineStep
	for rows.Next() {
		var s PipelineStep
		if err := rows.Scan(&s.ID, &s.PipelineRunID, &s.StepType, &s.Status, &s.Input, &s.Output, &s.Thinking, &s.ToolCalls, &s.SortOrder, &s.CreatedAt, &s.UpdatedAt); err != nil {
			return nil, err
		}
		steps = append(steps, s)
	}
	return steps, rows.Err()
}

func (q *Queries) UpdatePipelineStepStatus(id int64, status string) error {
	_, err := q.db.Exec("UPDATE pipeline_steps SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", status, id)
	return err
}

func (q *Queries) UpdatePipelineStepOutput(id int64, output, thinking string) error {
	_, err := q.db.Exec("UPDATE pipeline_steps SET output = ?, thinking = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", output, thinking, id)
	return err
}

func (q *Queries) UpdatePipelineStepInput(id int64, input string) error {
	_, err := q.db.Exec("UPDATE pipeline_steps SET input = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", input, id)
	return err
}

func (q *Queries) UpdatePipelineStepToolCalls(id int64, toolCalls string) error {
	_, err := q.db.Exec("UPDATE pipeline_steps SET tool_calls = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", toolCalls, id)
	return err
}

func (q *Queries) ResetPipelineSteps(pipelineRunID int64) error {
	_, err := q.db.Exec("UPDATE pipeline_steps SET status = 'pending', output = '', thinking = '', tool_calls = '', updated_at = CURRENT_TIMESTAMP WHERE pipeline_run_id = ?", pipelineRunID)
	return err
}
