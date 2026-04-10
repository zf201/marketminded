package store

import (
	"fmt"
	"time"
)

type PipelineStep struct {
	ID            int64
	PipelineRunID int64
	StepType      string
	Status        string
	Input         string
	Output        string
	Thinking      string
	ToolCalls     string
	Usage         string
	SortOrder     int
	CreatedAt     time.Time
	UpdatedAt     time.Time
}

// CreateDefaultPipelineSteps creates the standard pipeline steps for a run.
// The step list is dynamic per-run:
//   - claim_verifier only if the global setting claim_verifier_enabled == "true"
//   - audience_picker only if the project has at least one persona
//   - style_reference only if the project has a non-empty blog_url setting
func (q *Queries) CreateDefaultPipelineSteps(runID int64) error {
	run, err := q.GetPipelineRun(runID)
	if err != nil {
		return fmt.Errorf("lookup pipeline run %d: %w", runID, err)
	}
	projectID := run.ProjectID

	stepTypes := []string{"research"}

	personas, _ := q.ListAudiencePersonas(projectID)
	if len(personas) > 0 {
		stepTypes = append(stepTypes, "audience_picker")
	}

	stepTypes = append(stepTypes, "brand_enricher")

	if v, _ := q.GetSetting("claim_verifier_enabled"); v == "true" {
		stepTypes = append(stepTypes, "claim_verifier")
	}

	stepTypes = append(stepTypes, "editor")

	if url, _ := q.GetProjectSetting(projectID, "blog_url"); url != "" {
		stepTypes = append(stepTypes, "style_reference")
	}

	stepTypes = append(stepTypes, "write")

	for i, stepType := range stepTypes {
		if _, err := q.CreatePipelineStep(runID, stepType, i); err != nil {
			return err
		}
	}
	return nil
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
		`SELECT id, pipeline_run_id, step_type, status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
		 FROM pipeline_steps WHERE id = ?`, id,
	).Scan(&s.ID, &s.PipelineRunID, &s.StepType, &s.Status, &s.Input, &s.Output, &s.Thinking, &s.ToolCalls, &s.Usage, &s.SortOrder, &s.CreatedAt, &s.UpdatedAt)
	return s, err
}

func (q *Queries) ListPipelineSteps(pipelineRunID int64) ([]PipelineStep, error) {
	rows, err := q.db.Query(
		`SELECT id, pipeline_run_id, step_type, status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
		 FROM pipeline_steps WHERE pipeline_run_id = ? ORDER BY sort_order ASC`, pipelineRunID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var steps []PipelineStep
	for rows.Next() {
		var s PipelineStep
		if err := rows.Scan(&s.ID, &s.PipelineRunID, &s.StepType, &s.Status, &s.Input, &s.Output, &s.Thinking, &s.ToolCalls, &s.Usage, &s.SortOrder, &s.CreatedAt, &s.UpdatedAt); err != nil {
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

func (q *Queries) UpdatePipelineStepToolCalls(id int64, toolCalls string) error {
	_, err := q.db.Exec("UPDATE pipeline_steps SET tool_calls = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", toolCalls, id)
	return err
}

func (q *Queries) UpdatePipelineStepUsage(id int64, usage string) error {
	_, err := q.db.Exec("UPDATE pipeline_steps SET usage = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", usage, id)
	return err
}

func (q *Queries) ResetPipelineSteps(pipelineRunID int64) error {
	_, err := q.db.Exec("UPDATE pipeline_steps SET status = 'pending', output = '', thinking = '', tool_calls = '', updated_at = CURRENT_TIMESTAMP WHERE pipeline_run_id = ?", pipelineRunID)
	return err
}
