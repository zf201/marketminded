package store

import "time"

type AgentRun struct {
	ID             int64
	ProjectID      int64
	PipelineRunID  *int64
	AgentType      string
	PromptSummary  string
	Response       string
	ContentPieceID *int64
	CreatedAt      time.Time
}

func (q *Queries) CreateAgentRun(projectID int64, pipelineRunID *int64, agentType, promptSummary, response string, contentPieceID *int64) (*AgentRun, error) {
	res, err := q.db.Exec(
		"INSERT INTO agent_runs (project_id, pipeline_run_id, agent_type, prompt_summary, response, content_piece_id) VALUES (?, ?, ?, ?, ?, ?)",
		projectID, pipelineRunID, agentType, promptSummary, response, contentPieceID,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	a := &AgentRun{}
	err = q.db.QueryRow(
		"SELECT id, project_id, pipeline_run_id, agent_type, COALESCE(prompt_summary,''), response, content_piece_id, created_at FROM agent_runs WHERE id = ?", id,
	).Scan(&a.ID, &a.ProjectID, &a.PipelineRunID, &a.AgentType, &a.PromptSummary, &a.Response, &a.ContentPieceID, &a.CreatedAt)
	return a, err
}

func (q *Queries) ListAgentRuns(projectID int64) ([]AgentRun, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, pipeline_run_id, agent_type, COALESCE(prompt_summary,''), response, content_piece_id, created_at FROM agent_runs WHERE project_id = ? ORDER BY created_at DESC", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var runs []AgentRun
	for rows.Next() {
		var a AgentRun
		if err := rows.Scan(&a.ID, &a.ProjectID, &a.PipelineRunID, &a.AgentType, &a.PromptSummary, &a.Response, &a.ContentPieceID, &a.CreatedAt); err != nil {
			return nil, err
		}
		runs = append(runs, a)
	}
	return runs, rows.Err()
}
