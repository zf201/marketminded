package store

import "time"

type TopicRun struct {
	ID           int64
	ProjectID    int64
	Instructions string
	Status       string
	CreatedAt    time.Time
	UpdatedAt    time.Time
}

type TopicStep struct {
	ID         int64
	TopicRunID int64
	StepType   string
	Round      int
	Status     string
	Output     string
	Thinking   string
	ToolCalls  string
	Usage      string
	SortOrder  int
	CreatedAt  time.Time
	UpdatedAt  time.Time
}

type TopicBacklogItem struct {
	ID         int64
	ProjectID  int64
	TopicRunID int64
	Title      string
	Angle      string
	Sources    string
	Status     string
	CreatedAt  time.Time
}

// --- Topic Runs ---

func (q *Queries) CreateTopicRun(projectID int64, instructions string) (*TopicRun, error) {
	res, err := q.db.Exec("INSERT INTO topic_runs (project_id, instructions) VALUES (?, ?)", projectID, instructions)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetTopicRun(id)
}

func (q *Queries) GetTopicRun(id int64) (*TopicRun, error) {
	r := &TopicRun{}
	err := q.db.QueryRow(
		"SELECT id, project_id, COALESCE(instructions,''), status, created_at, updated_at FROM topic_runs WHERE id = ?", id,
	).Scan(&r.ID, &r.ProjectID, &r.Instructions, &r.Status, &r.CreatedAt, &r.UpdatedAt)
	return r, err
}

func (q *Queries) ListTopicRuns(projectID int64) ([]TopicRun, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, COALESCE(instructions,''), status, created_at, updated_at FROM topic_runs WHERE project_id = ? ORDER BY created_at DESC", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var runs []TopicRun
	for rows.Next() {
		var r TopicRun
		if err := rows.Scan(&r.ID, &r.ProjectID, &r.Instructions, &r.Status, &r.CreatedAt, &r.UpdatedAt); err != nil {
			return nil, err
		}
		runs = append(runs, r)
	}
	return runs, rows.Err()
}

func (q *Queries) DeleteTopicRun(id int64) error {
	_, err := q.db.Exec("DELETE FROM topic_runs WHERE id = ?", id)
	return err
}

func (q *Queries) NullifyTopicBacklogRunID(topicRunID int64) error {
	_, err := q.db.Exec("UPDATE topic_backlog SET topic_run_id = NULL WHERE topic_run_id = ?", topicRunID)
	return err
}

func (q *Queries) UpdateTopicRunStatus(id int64, status string) error {
	_, err := q.db.Exec("UPDATE topic_runs SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", status, id)
	return err
}

// --- Topic Steps ---

func (q *Queries) CreateTopicStep(topicRunID int64, stepType string, round, sortOrder int) (*TopicStep, error) {
	res, err := q.db.Exec(
		"INSERT INTO topic_steps (topic_run_id, step_type, round, sort_order) VALUES (?, ?, ?, ?)",
		topicRunID, stepType, round, sortOrder,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetTopicStep(id)
}

func (q *Queries) GetTopicStep(id int64) (*TopicStep, error) {
	s := &TopicStep{}
	err := q.db.QueryRow(
		`SELECT id, topic_run_id, step_type, round, status, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
		 FROM topic_steps WHERE id = ?`, id,
	).Scan(&s.ID, &s.TopicRunID, &s.StepType, &s.Round, &s.Status, &s.Output, &s.Thinking, &s.ToolCalls, &s.Usage, &s.SortOrder, &s.CreatedAt, &s.UpdatedAt)
	return s, err
}

func (q *Queries) ListTopicSteps(topicRunID int64) ([]TopicStep, error) {
	rows, err := q.db.Query(
		`SELECT id, topic_run_id, step_type, round, status, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
		 FROM topic_steps WHERE topic_run_id = ? ORDER BY sort_order ASC`, topicRunID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var steps []TopicStep
	for rows.Next() {
		var s TopicStep
		if err := rows.Scan(&s.ID, &s.TopicRunID, &s.StepType, &s.Round, &s.Status, &s.Output, &s.Thinking, &s.ToolCalls, &s.Usage, &s.SortOrder, &s.CreatedAt, &s.UpdatedAt); err != nil {
			return nil, err
		}
		steps = append(steps, s)
	}
	return steps, rows.Err()
}

func (q *Queries) DeleteTopicSteps(topicRunID int64) error {
	_, err := q.db.Exec("DELETE FROM topic_steps WHERE topic_run_id = ?", topicRunID)
	return err
}

func (q *Queries) UpdateTopicStepStatus(id int64, status string) error {
	_, err := q.db.Exec("UPDATE topic_steps SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", status, id)
	return err
}

func (q *Queries) UpdateTopicStepOutput(id int64, output, thinking string) error {
	_, err := q.db.Exec("UPDATE topic_steps SET output = ?, thinking = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", output, thinking, id)
	return err
}

func (q *Queries) UpdateTopicStepToolCalls(id int64, toolCalls string) error {
	_, err := q.db.Exec("UPDATE topic_steps SET tool_calls = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", toolCalls, id)
	return err
}

func (q *Queries) UpdateTopicStepUsage(id int64, usage string) error {
	_, err := q.db.Exec("UPDATE topic_steps SET usage = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", usage, id)
	return err
}

// --- Topic Backlog ---

func (q *Queries) CreateTopicBacklogItem(projectID, topicRunID int64, title, angle, sources string) (*TopicBacklogItem, error) {
	res, err := q.db.Exec(
		"INSERT INTO topic_backlog (project_id, topic_run_id, title, angle, sources) VALUES (?, ?, ?, ?, ?)",
		projectID, topicRunID, title, angle, sources,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetTopicBacklogItem(id)
}

func (q *Queries) GetTopicBacklogItem(id int64) (*TopicBacklogItem, error) {
	item := &TopicBacklogItem{}
	err := q.db.QueryRow(
		"SELECT id, project_id, topic_run_id, title, angle, sources, status, created_at FROM topic_backlog WHERE id = ?", id,
	).Scan(&item.ID, &item.ProjectID, &item.TopicRunID, &item.Title, &item.Angle, &item.Sources, &item.Status, &item.CreatedAt)
	return item, err
}

func (q *Queries) ListTopicBacklog(projectID int64) ([]TopicBacklogItem, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, topic_run_id, title, angle, sources, status, created_at FROM topic_backlog WHERE project_id = ? AND status != 'deleted' ORDER BY created_at DESC", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []TopicBacklogItem
	for rows.Next() {
		var item TopicBacklogItem
		if err := rows.Scan(&item.ID, &item.ProjectID, &item.TopicRunID, &item.Title, &item.Angle, &item.Sources, &item.Status, &item.CreatedAt); err != nil {
			return nil, err
		}
		items = append(items, item)
	}
	return items, rows.Err()
}

func (q *Queries) UpdateTopicBacklogStatus(id int64, status string) error {
	_, err := q.db.Exec("UPDATE topic_backlog SET status = ? WHERE id = ?", status, id)
	return err
}

func (q *Queries) CountTopicRunTopics(topicRunID int64) (int, error) {
	var count int
	err := q.db.QueryRow(
		"SELECT COUNT(*) FROM topic_backlog WHERE topic_run_id = ? AND status != 'deleted'", topicRunID,
	).Scan(&count)
	return count, err
}
