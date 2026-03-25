# Agent Pipeline Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Separate the content pipeline into cornerstone (researcher -> fact-checker -> writer) and waterfall (planner -> parallel generation) phases, each on their own page.

**Architecture:** New `pipeline_steps` table tracks each agent execution. Cornerstone agents auto-chain sequentially. Waterfall gets its own page with parallel piece generation. The existing `pipeline_runs` table gains a `phase` column. No changes to `content_pieces` schema.

**Tech Stack:** Go, SQLite (goose migrations), Templ templates, Alpine.js, SSE streaming, OpenRouter API

**Spec:** `docs/superpowers/specs/2026-03-25-agent-pipeline-redesign-design.md`

---

### Task 1: Migration — pipeline_steps table and pipeline_runs.phase column

**Files:**
- Create: `migrations/006_pipeline_steps.sql`
- Create: `internal/store/steps.go`
- Modify: `internal/store/pipeline.go`
- Modify: `internal/store/pipeline_test.go`

- [ ] **Step 1: Write the failing test**

Add a test to `internal/store/pipeline_test.go` that creates a pipeline step and verifies it round-trips:

```go
func TestPipelineStepCRUD(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	step, err := q.CreatePipelineStep(run.ID, "research", 0)
	if err != nil {
		t.Fatalf("create step: %v", err)
	}
	if step.StepType != "research" {
		t.Errorf("expected research, got %s", step.StepType)
	}
	if step.Status != "pending" {
		t.Errorf("expected pending, got %s", step.Status)
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `go test ./internal/store/ -run TestPipelineStepCRUD -v`
Expected: FAIL — `CreatePipelineStep` method doesn't exist yet

- [ ] **Step 3: Write the migration**

Create `migrations/006_pipeline_steps.sql`:

```sql
-- +goose Up

-- Rebuild pipeline_runs to add phase column and update status CHECK
CREATE TABLE pipeline_runs_new (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    topic TEXT NOT NULL,
    brief TEXT,
    plan TEXT,
    phase TEXT NOT NULL DEFAULT 'cornerstone'
        CHECK(phase IN ('cornerstone','waterfall')),
    status TEXT NOT NULL DEFAULT 'producing'
        CHECK(status IN ('producing','complete','abandoned')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO pipeline_runs_new (id, project_id, topic, brief, plan, phase, status, created_at, updated_at)
    SELECT id, project_id, topic, brief, plan, 'cornerstone',
        CASE WHEN status = 'planning' THEN 'producing' ELSE status END,
        created_at, updated_at
    FROM pipeline_runs;

DROP TABLE pipeline_runs;
ALTER TABLE pipeline_runs_new RENAME TO pipeline_runs;

-- Pipeline steps table
CREATE TABLE pipeline_steps (
    id INTEGER PRIMARY KEY,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL CHECK(step_type IN ('research','factcheck','write','plan_waterfall')),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','running','completed','failed')),
    input TEXT NOT NULL DEFAULT '',
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- +goose Down
DROP TABLE pipeline_steps;

CREATE TABLE pipeline_runs_new (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    topic TEXT NOT NULL,
    plan TEXT,
    status TEXT NOT NULL DEFAULT 'planning'
        CHECK(status IN ('planning','producing','complete','abandoned')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO pipeline_runs_new (id, project_id, topic, plan, status, created_at, updated_at)
    SELECT id, project_id, topic, plan, status, created_at, updated_at
    FROM pipeline_runs;

DROP TABLE pipeline_runs;
ALTER TABLE pipeline_runs_new RENAME TO pipeline_runs;
```

- [ ] **Step 4: Write the store layer**

Create `internal/store/steps.go`:

```go
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
		`SELECT id, pipeline_run_id, step_type, status, input, output, thinking, sort_order, created_at, updated_at
		 FROM pipeline_steps WHERE id = ?`, id,
	).Scan(&s.ID, &s.PipelineRunID, &s.StepType, &s.Status, &s.Input, &s.Output, &s.Thinking, &s.SortOrder, &s.CreatedAt, &s.UpdatedAt)
	return s, err
}

func (q *Queries) ListPipelineSteps(pipelineRunID int64) ([]PipelineStep, error) {
	rows, err := q.db.Query(
		`SELECT id, pipeline_run_id, step_type, status, input, output, thinking, sort_order, created_at, updated_at
		 FROM pipeline_steps WHERE pipeline_run_id = ? ORDER BY sort_order ASC`, pipelineRunID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var steps []PipelineStep
	for rows.Next() {
		var s PipelineStep
		if err := rows.Scan(&s.ID, &s.PipelineRunID, &s.StepType, &s.Status, &s.Input, &s.Output, &s.Thinking, &s.SortOrder, &s.CreatedAt, &s.UpdatedAt); err != nil {
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

// TrySetStepRunning atomically sets status to running if currently pending or failed.
func (q *Queries) TrySetStepRunning(id int64) (bool, error) {
	res, err := q.db.Exec(
		"UPDATE pipeline_steps SET status = 'running', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('pending', 'failed')", id,
	)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}
```

- [ ] **Step 5: Update PipelineRun struct and queries**

In `internal/store/pipeline.go`, add `Phase` field and update all read queries to include it:

```go
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
```

Update `GetPipelineRun`:
```go
func (q *Queries) GetPipelineRun(id int64) (*PipelineRun, error) {
	r := &PipelineRun{}
	err := q.db.QueryRow(
		"SELECT id, project_id, topic, COALESCE(brief,''), COALESCE(plan,''), phase, status, created_at, updated_at FROM pipeline_runs WHERE id = ?", id,
	).Scan(&r.ID, &r.ProjectID, &r.Topic, &r.Brief, &r.Plan, &r.Phase, &r.Status, &r.CreatedAt, &r.UpdatedAt)
	return r, err
}
```

Update `ListPipelineRuns` to scan `phase` similarly.

Add `UpdatePipelinePhase`:
```go
func (q *Queries) UpdatePipelinePhase(id int64, phase string) error {
	_, err := q.db.Exec("UPDATE pipeline_runs SET phase = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", phase, id)
	return err
}
```

- [ ] **Step 6: Fix existing pipeline_test.go**

The existing `TestPipelineRunCRUD` expects `status == "planning"`. Update it to expect `"producing"` (new default). Update the Scan calls to include `phase`.

- [ ] **Step 7: Run all store tests to verify**

Run: `go test ./internal/store/ -v`
Expected: All pass, including the new `TestPipelineStepCRUD`

- [ ] **Step 8: Commit**

```bash
git add migrations/006_pipeline_steps.sql internal/store/steps.go internal/store/pipeline.go internal/store/pipeline_test.go
git commit -m "feat: add pipeline_steps table and phase column to pipeline_runs"
```

---

### Task 2: Store tests for pipeline steps

**Files:**
- Create: `internal/store/steps_test.go`

- [ ] **Step 1: Write comprehensive tests**

```go
package store

import "testing"

func TestPipelineStepCRUD(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	step, err := q.CreatePipelineStep(run.ID, "research", 0)
	if err != nil {
		t.Fatalf("create step: %v", err)
	}
	if step.StepType != "research" {
		t.Errorf("expected research, got %s", step.StepType)
	}
	if step.Status != "pending" {
		t.Errorf("expected pending, got %s", step.Status)
	}
	if step.SortOrder != 0 {
		t.Errorf("expected sort_order 0, got %d", step.SortOrder)
	}
}

func TestListPipelineStepsOrder(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	q.CreatePipelineStep(run.ID, "research", 0)
	q.CreatePipelineStep(run.ID, "factcheck", 1)
	q.CreatePipelineStep(run.ID, "write", 2)

	steps, err := q.ListPipelineSteps(run.ID)
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(steps) != 3 {
		t.Fatalf("expected 3 steps, got %d", len(steps))
	}
	if steps[0].StepType != "research" {
		t.Errorf("expected research first, got %s", steps[0].StepType)
	}
	if steps[2].StepType != "write" {
		t.Errorf("expected write last, got %s", steps[2].StepType)
	}
}

func TestTrySetStepRunning(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")
	step, _ := q.CreatePipelineStep(run.ID, "research", 0)

	ok, _ := q.TrySetStepRunning(step.ID)
	if !ok {
		t.Error("expected first TrySetStepRunning to succeed")
	}

	ok2, _ := q.TrySetStepRunning(step.ID)
	if ok2 {
		t.Error("expected second TrySetStepRunning to fail (already running)")
	}
}

func TestUpdatePipelineStepOutput(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")
	step, _ := q.CreatePipelineStep(run.ID, "research", 0)

	q.UpdatePipelineStepOutput(step.ID, `{"brief":"test"}`, "thinking chain")
	got, _ := q.GetPipelineStep(step.ID)
	if got.Output != `{"brief":"test"}` {
		t.Errorf("expected output to be set, got %s", got.Output)
	}
	if got.Thinking != "thinking chain" {
		t.Errorf("expected thinking to be set, got %s", got.Thinking)
	}
}
```

- [ ] **Step 2: Run tests**

Run: `go test ./internal/store/ -v`
Expected: All pass

- [ ] **Step 3: Commit**

```bash
git add internal/store/steps_test.go
git commit -m "test: add pipeline steps store tests"
```

---

### Task 3: Researcher agent handler

**Files:**
- Modify: `web/handlers/pipeline.go`

Implement `streamResearch` — the researcher agent that gathers sources and writes a narrative brief.

- [ ] **Step 1: Define the submit_research tool**

Add to `web/handlers/pipeline.go`:

```go
func (h *PipelineHandler) researchTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_research",
			Description: "Submit your research findings including sources and a narrative brief.",
			Parameters: json.RawMessage(`{
				"type": "object",
				"properties": {
					"sources": {
						"type": "array",
						"items": {
							"type": "object",
							"properties": {
								"url": {"type": "string"},
								"title": {"type": "string"},
								"summary": {"type": "string"},
								"date": {"type": "string"}
							},
							"required": ["url", "title", "summary"]
						}
					},
					"brief": {"type": "string", "description": "Narrative synthesis of your research findings"}
				},
				"required": ["sources", "brief"]
			}`),
		},
	}
}
```

- [ ] **Step 2: Implement streamResearch handler**

```go
func (h *PipelineHandler) streamResearch(w http.ResponseWriter, r *http.Request, projectID int64, stepID int64, run *store.PipelineRun) {
	ok, err := h.queries.TrySetStepRunning(stepID)
	if err != nil || !ok {
		http.Error(w, "Step already running or done", http.StatusConflict)
		return
	}

	profile, _ := h.queries.BuildProfileString(projectID)

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are a content researcher. Your job is to research a topic thoroughly so that a content writer can produce accurate, well-informed content.

Client profile:
%s

Topic brief:
%s

Research this topic:
1. Search for recent, authoritative sources
2. Gather key facts, statistics, expert opinions, and trends
3. Write a narrative brief that synthesizes your findings into a coherent research summary
4. Include all sources with URLs, titles, summaries, and dates where available

Focus on accuracy and recency. Flag anything that seems outdated or uncertain.

You MUST call the submit_research tool with your findings. Do not return research as text.`,
		time.Now().Format("January 2, 2006"), profile, run.Brief)

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Research this topic thoroughly."},
	}

	flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList, baseExecutor := h.buildTools(0)
	toolList = append(toolList, h.researchTool())

	var savedOutput string
	var thinkingBuf strings.Builder
	executor := func(ctx context.Context, name, args string) (string, error) {
		if name == "submit_research" {
			savedOutput = args
			h.queries.UpdatePipelineStepOutput(stepID, args, thinkingBuf.String())
			h.queries.UpdatePipelineStepStatus(stepID, "completed")
			return "Research saved successfully.", nil
		}
		return baseExecutor(ctx, name, args)
	}
	onToolEvent := h.buildToolEventCallback(sendEvent, 0)

	captureThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return sendThinking(chunk)
	}

	temp := 0.3
	_, err = h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, captureThinking, &temp)
	if err != nil {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError(err.Error())
		return
	}

	if savedOutput == "" {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError("Model did not submit research via tool call. Try again.")
		return
	}
	sendDone()
}
```

- [ ] **Step 3: Add parseStepID helper**

```go
func (h *PipelineHandler) parseStepID(rest string) int64 {
	parts := strings.Split(rest, "/")
	for i, p := range parts {
		if p == "step" && i+1 < len(parts) {
			id, _ := strconv.ParseInt(parts[i+1], 10, 64)
			return id
		}
	}
	return 0
}
```

- [ ] **Step 4: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: add researcher agent handler with submit_research tool"
```

---

### Task 4: Fact-checker agent handler

**Files:**
- Modify: `web/handlers/pipeline.go`

- [ ] **Step 1: Define the submit_factcheck tool**

```go
func (h *PipelineHandler) factcheckTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_factcheck",
			Description: "Submit your fact-check results with any issues found and the enriched/corrected research brief.",
			Parameters: json.RawMessage(`{
				"type": "object",
				"properties": {
					"issues_found": {
						"type": "array",
						"items": {
							"type": "object",
							"properties": {
								"claim": {"type": "string"},
								"problem": {"type": "string"},
								"resolution": {"type": "string"}
							},
							"required": ["claim", "problem", "resolution"]
						}
					},
					"enriched_brief": {"type": "string", "description": "Corrected and enriched version of the research brief"},
					"sources": {
						"type": "array",
						"items": {
							"type": "object",
							"properties": {
								"url": {"type": "string"},
								"title": {"type": "string"},
								"summary": {"type": "string"},
								"date": {"type": "string"}
							},
							"required": ["url", "title", "summary"]
						}
					}
				},
				"required": ["issues_found", "enriched_brief", "sources"]
			}`),
		},
	}
}
```

- [ ] **Step 2: Implement streamFactcheck handler**

```go
func (h *PipelineHandler) streamFactcheck(w http.ResponseWriter, r *http.Request, projectID int64, stepID int64, run *store.PipelineRun, researchOutput string) {
	ok, err := h.queries.TrySetStepRunning(stepID)
	if err != nil || !ok {
		http.Error(w, "Step already running or done", http.StatusConflict)
		return
	}

	profile, _ := h.queries.BuildProfileString(projectID)

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are a fact-checker and research enricher. You receive research from a researcher and your job is to:

1. Verify that claims are accurate and properly supported by the cited sources
2. Check that information is current and up-to-date as of today's date
3. Do independent web searches to cross-reference key claims
4. Fix any inaccuracies, outdated information, or unsupported claims
5. Enrich the brief with any important missing context or nuance
6. Return the corrected and enriched version

Client profile:
%s

Research to verify:
%s

Be thorough but practical. Focus on factual accuracy and currency. The enriched brief you produce will be handed directly to a content writer.

You MUST call the submit_factcheck tool with your findings. Do not return results as text.`,
		time.Now().Format("January 2, 2006"), profile, researchOutput)

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Verify and enrich this research."},
	}

	flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList, baseExecutor := h.buildTools(0)
	toolList = append(toolList, h.factcheckTool())

	var savedOutput string
	var thinkingBuf strings.Builder
	executor := func(ctx context.Context, name, args string) (string, error) {
		if name == "submit_factcheck" {
			savedOutput = args
			h.queries.UpdatePipelineStepOutput(stepID, args, thinkingBuf.String())
			h.queries.UpdatePipelineStepStatus(stepID, "completed")
			return "Fact-check saved successfully.", nil
		}
		return baseExecutor(ctx, name, args)
	}
	onToolEvent := h.buildToolEventCallback(sendEvent, 0)

	captureThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return sendThinking(chunk)
	}

	temp := 0.2
	_, err = h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, captureThinking, &temp)
	if err != nil {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError(err.Error())
		return
	}

	if savedOutput == "" {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError("Model did not submit fact-check via tool call. Try again.")
		return
	}
	sendDone()
}
```

- [ ] **Step 3: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: add fact-checker agent handler"
```

---

### Task 5: Writer agent handler and step dispatcher

**Files:**
- Modify: `web/handlers/pipeline.go`

The writer agent takes the enriched brief from the fact-checker and produces the cornerstone content piece.

- [ ] **Step 1: Implement streamWrite handler**

```go
func (h *PipelineHandler) streamWrite(w http.ResponseWriter, r *http.Request, projectID int64, stepID int64, run *store.PipelineRun, factcheckOutput string) {
	ok, err := h.queries.TrySetStepRunning(stepID)
	if err != nil || !ok {
		http.Error(w, "Step already running or done", http.StatusConflict)
		return
	}

	profile, _ := h.queries.BuildProfileString(projectID)

	// Parse factcheck output to get enriched brief and sources
	var fcResult struct {
		EnrichedBrief string `json:"enriched_brief"`
		Sources       []struct {
			URL     string `json:"url"`
			Title   string `json:"title"`
			Summary string `json:"summary"`
		} `json:"sources"`
	}
	json.Unmarshal([]byte(factcheckOutput), &fcResult)

	// Build sources text
	var sourcesList strings.Builder
	for _, s := range fcResult.Sources {
		fmt.Fprintf(&sourcesList, "- %s (%s): %s\n", s.Title, s.URL, s.Summary)
	}

	// Cornerstone defaults to blog post
	platform := "blog"
	format := "post"
	ct, ctOk := content.LookupType(platform, format)

	var promptText string
	if ctOk {
		promptText, _ = content.LoadPrompt(ct.PromptFile)
	}
	if promptText == "" {
		promptText = fmt.Sprintf("You are writing a %s %s.", platform, format)
	}

	systemPrompt := fmt.Sprintf(`Today's date: %s

%s

## Client profile
%s
`, time.Now().Format("January 2, 2006"), promptText, profile)

	// Add storytelling framework if set
	if fwKey, err := h.queries.GetProjectSetting(projectID, "storytelling_framework"); err == nil && fwKey != "" {
		if fw := content.FrameworkByKey(fwKey); fw != nil {
			systemPrompt += fmt.Sprintf("\n## Storytelling framework\nFramework: %s (%s)\n%s\n", fw.Name, fw.Attribution, fw.PromptInstruction)
		}
	}

	systemPrompt += fmt.Sprintf(`
## Research brief (verified and enriched)
%s

## Sources
%s

## Topic
%s
`, fcResult.EnrichedBrief, sourcesList.String(), run.Brief)

	systemPrompt += antiAIRules

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: fmt.Sprintf("Write the %s %s now.", platform, format)},
	}

	flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	// No fetch/search tools — writer works from the enriched brief only
	var toolList []ai.Tool
	if ctOk {
		toolList = append(toolList, ct.Tool)
	}

	var thinkingBuf strings.Builder
	executor := func(ctx context.Context, name, args string) (string, error) {
		if content.IsWriteTool(name) {
			// Create the cornerstone content piece
			piece, err := h.queries.CreateContentPiece(projectID, run.ID, platform, format, "", 0, nil)
			if err != nil {
				return "", fmt.Errorf("failed to create cornerstone piece: %w", err)
			}

			// Parse title from args if available
			var parsed struct {
				Title string `json:"title"`
			}
			json.Unmarshal([]byte(args), &parsed)
			title := parsed.Title
			if title == "" {
				title = run.Topic
			}

			h.queries.UpdateContentPieceBody(piece.ID, title, args)
			h.queries.SetContentPieceStatus(piece.ID, "draft")
			h.queries.UpdatePipelineTopic(run.ID, title)
			h.queries.UpdatePipelineStepOutput(stepID, args, thinkingBuf.String())
			h.queries.UpdatePipelineStepStatus(stepID, "completed")

			return "Content saved successfully. The user will review it.", nil
		}
		return "", fmt.Errorf("unknown tool: %s", name)
	}
	onToolEvent := h.buildToolEventCallback(sendEvent, 0)

	captureThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return sendThinking(chunk)
	}

	temp := 0.3
	_, err = h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, captureThinking, &temp)
	if err != nil {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError(err.Error())
		return
	}

	sendDone()
}
```

- [ ] **Step 2: Implement the streamStep dispatcher**

This routes to the correct agent based on step type and handles auto-chaining:

```go
func (h *PipelineHandler) streamStep(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	stepID := h.parseStepID(rest)

	step, err := h.queries.GetPipelineStep(stepID)
	if err != nil {
		http.Error(w, "Step not found", http.StatusNotFound)
		return
	}

	run, _ := h.queries.GetPipelineRun(runID)

	switch step.StepType {
	case "research":
		h.streamResearch(w, r, projectID, stepID, run)
	case "factcheck":
		steps, _ := h.queries.ListPipelineSteps(runID)
		var researchOutput string
		for _, s := range steps {
			if s.StepType == "research" && s.Status == "completed" {
				researchOutput = s.Output
				break
			}
		}
		if researchOutput == "" {
			http.Error(w, "Research step not completed yet", http.StatusConflict)
			return
		}
		h.streamFactcheck(w, r, projectID, stepID, run, researchOutput)
	case "write":
		steps, _ := h.queries.ListPipelineSteps(runID)
		var factcheckOutput string
		for _, s := range steps {
			if s.StepType == "factcheck" && s.Status == "completed" {
				factcheckOutput = s.Output
				break
			}
		}
		if factcheckOutput == "" {
			http.Error(w, "Fact-check step not completed yet", http.StatusConflict)
			return
		}
		h.streamWrite(w, r, projectID, stepID, run, factcheckOutput)
	case "plan_waterfall":
		h.streamWaterfallPlan(w, r, projectID, stepID, run)
	default:
		http.Error(w, "Unknown step type", http.StatusBadRequest)
	}
}
```

- [ ] **Step 3: Add route to Handle method**

Add this case in the `Handle` switch, before the `strings.Contains(rest, "/stream/piece/")` case:

```go
case strings.Contains(rest, "/stream/step/"):
	h.streamStep(w, r, projectID, rest)
```

- [ ] **Step 4: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: add writer agent and step dispatcher for auto-chaining"
```

---

### Task 6: Pipeline creation — auto-create cornerstone steps

**Files:**
- Modify: `web/handlers/pipeline.go`
- Modify: `web/handlers/brainstorm.go` (if it creates pipeline runs)

When a pipeline run is created, immediately create the three cornerstone steps.

- [ ] **Step 1: Update the create handler**

Replace the current `create` method in `pipeline.go`:

```go
func (h *PipelineHandler) create(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	brief := r.FormValue("topic")
	if brief == "" {
		http.Error(w, "Topic required", http.StatusBadRequest)
		return
	}
	run, err := h.queries.CreatePipelineRun(projectID, brief)
	if err != nil {
		http.Error(w, "Failed to create run", http.StatusInternalServerError)
		return
	}

	// Create the three cornerstone agent steps
	h.queries.CreatePipelineStep(run.ID, "research", 0)
	h.queries.CreatePipelineStep(run.ID, "factcheck", 1)
	h.queries.CreatePipelineStep(run.ID, "write", 2)

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, run.ID), http.StatusSeeOther)
}
```

- [ ] **Step 2: Update brainstorm push-to-pipeline**

Search `web/handlers/brainstorm.go` for `CreatePipelineRun` and add the same three step creation calls after the run is created.

- [ ] **Step 3: Commit**

```bash
git add web/handlers/pipeline.go web/handlers/brainstorm.go
git commit -m "feat: auto-create cornerstone steps on pipeline run creation"
```

---

### Task 7: Waterfall planner agent

**Files:**
- Modify: `web/handlers/pipeline.go`

- [ ] **Step 1: Define submit_waterfall_plan tool**

```go
func (h *PipelineHandler) waterfallPlanTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_waterfall_plan",
			Description: "Submit the waterfall content plan. These are derivative pieces that funnel audience to the cornerstone.",
			Parameters: json.RawMessage(`{
				"type": "object",
				"properties": {
					"waterfall": {
						"type": "array",
						"items": {
							"type": "object",
							"properties": {
								"platform": {"type": "string", "description": "Platform: blog, linkedin, instagram, x, youtube, facebook, tiktok"},
								"format": {"type": "string", "description": "Format: post, thread, reel, carousel, script, short, video"},
								"title": {"type": "string", "description": "Working title"},
								"count": {"type": "integer", "description": "Number of pieces of this type"},
								"notes": {"type": "string", "description": "Production notes for the writer"}
							},
							"required": ["platform", "format", "title", "count"]
						}
					}
				},
				"required": ["waterfall"]
			}`),
		},
	}
}
```

- [ ] **Step 2: Implement streamWaterfallPlan**

```go
func (h *PipelineHandler) streamWaterfallPlan(w http.ResponseWriter, r *http.Request, projectID int64, stepID int64, run *store.PipelineRun) {
	ok, err := h.queries.TrySetStepRunning(stepID)
	if err != nil || !ok {
		http.Error(w, "Step already running or done", http.StatusConflict)
		return
	}

	profile, _ := h.queries.BuildProfileString(projectID)

	// Get the approved cornerstone piece
	pieces, _ := h.queries.ListContentByPipelineRun(run.ID)
	var cornerstoneBody, cornerstoneTitle string
	var cornerstoneID int64
	for _, p := range pieces {
		if p.ParentID == nil && p.Status == "approved" {
			cornerstoneBody = p.Body
			cornerstoneTitle = p.Title
			cornerstoneID = p.ID
			break
		}
	}

	if cornerstoneBody == "" {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		http.Error(w, "No approved cornerstone piece found", http.StatusConflict)
		return
	}

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are a content waterfall planner. Given an approved cornerstone piece, plan derivative content pieces that funnel the audience back to the cornerstone.

Client profile:
%s

Cornerstone piece title: %s

Cornerstone content:
%s

Plan waterfall pieces that:
- Repurpose and repackage the cornerstone's content for different platforms
- Drive the audience toward the cornerstone piece
- Do NOT introduce new information or claims
- Reference the waterfall patterns from the content strategy section if available

You MUST call the submit_waterfall_plan tool with your plan.

Valid platforms: blog, linkedin, instagram, x, youtube, facebook, tiktok
Valid formats: post, thread, reel, carousel, script, short, video`,
		time.Now().Format("January 2, 2006"), profile, cornerstoneTitle, cornerstoneBody)

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Plan the waterfall content pieces."},
	}

	flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList := []ai.Tool{h.waterfallPlanTool()}

	var savedPlan string
	var thinkingBuf strings.Builder
	executor := func(ctx context.Context, name, args string) (string, error) {
		if name == "submit_waterfall_plan" {
			savedPlan = args
			h.queries.UpdatePipelineStepOutput(stepID, args, thinkingBuf.String())
			h.queries.UpdatePipelineStepStatus(stepID, "completed")

			// Parse and create waterfall content pieces
			var plan struct {
				Waterfall []struct {
					Platform string `json:"platform"`
					Format   string `json:"format"`
					Title    string `json:"title"`
					Count    int    `json:"count"`
				} `json:"waterfall"`
			}
			if err := json.Unmarshal([]byte(args), &plan); err != nil {
				return "", fmt.Errorf("failed to parse plan: %w", err)
			}

			sortOrder := 1
			for _, wf := range plan.Waterfall {
				count := wf.Count
				if count < 1 {
					count = 1
				}
				for i := 0; i < count; i++ {
					title := wf.Title
					if count > 1 {
						title = fmt.Sprintf("%s #%d", wf.Title, i+1)
					}
					h.queries.CreateContentPiece(projectID, run.ID, wf.Platform, wf.Format, title, sortOrder, &cornerstoneID)
					sortOrder++
				}
			}

			h.queries.UpdatePipelinePlan(run.ID, args)

			return "Waterfall plan saved and pieces created.", nil
		}
		return "", fmt.Errorf("unknown tool: %s", name)
	}
	onToolEvent := h.buildToolEventCallback(sendEvent, 0)

	captureThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return sendThinking(chunk)
	}

	temp := 0.3
	_, err = h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, captureThinking, &temp)
	if err != nil {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError(err.Error())
		return
	}

	if savedPlan == "" {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError("Model did not submit waterfall plan via tool call. Try again.")
		return
	}
	sendDone()
}
```

- [ ] **Step 3: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: add waterfall planner agent handler"
```

---

### Task 8: Update cornerstone approve to flip phase

**Files:**
- Modify: `web/handlers/pipeline.go`

- [ ] **Step 1: Update approvePiece handler**

Replace the existing `approvePiece`:

```go
func (h *PipelineHandler) approvePiece(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	pieceID := h.parsePieceID(rest)

	piece, _ := h.queries.GetContentPiece(pieceID)
	h.queries.SetContentPieceStatus(pieceID, "approved")

	run, _ := h.queries.GetPipelineRun(runID)

	if piece.ParentID == nil && run.Phase == "cornerstone" {
		// Cornerstone approved — flip to waterfall phase
		h.queries.UpdatePipelinePhase(runID, "waterfall")
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]any{"phase_change": "waterfall"})
		return
	}

	// Waterfall phase — check if all done
	allDone, _ := h.queries.AllPiecesApproved(runID)
	if allDone {
		h.queries.UpdatePipelineStatus(runID, "complete")
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]any{"complete": true})
		return
	}

	next, err := h.queries.NextPendingPiece(runID)
	if err == nil {
		w.Header().Set("Content-Type", "application/json")
		json.NewEncoder(w).Encode(map[string]any{"next_piece_id": next.ID})
		return
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{"complete": false})
}
```

- [ ] **Step 2: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: flip pipeline phase to waterfall on cornerstone approval"
```

---

### Task 9: Cornerstone page template

**Files:**
- Modify: `web/templates/pipeline.templ`
- Modify: `web/handlers/pipeline.go`

Redesign the production board page to show the three agent steps vertically with the cornerstone piece appearing after writer completes.

- [ ] **Step 1: Add PipelineStepView and update ProductionBoardData**

Add to template types:

```go
type PipelineStepView struct {
	ID       int64
	StepType string
	Status   string
	Output   string
	Thinking string
}
```

Update `ProductionBoardData` to include `Phase string` and `Steps []PipelineStepView`.

- [ ] **Step 2: Rewrite ProductionBoardPage template**

Replace the current template with the cornerstone phase UI:
- Brief at top
- Step cards (Researcher, Fact-Checker, Writer) stacked vertically, each with: label, status badge, output (collapsible details), thinking (collapsible details)
- "Run Pipeline" button triggers auto-chaining from JS
- After writer completes, cornerstone piece card appears with approve/reject/improve actions
- After cornerstone approval, show "Go to Waterfall" link

Step type labels: `research` -> "Researcher", `factcheck` -> "Fact-Checker", `write` -> "Writer"

- [ ] **Step 3: Update the show handler to pass steps and phase**

In `pipeline.go`, update the `show` handler:

```go
steps, _ := h.queries.ListPipelineSteps(runID)
stepViews := make([]templates.PipelineStepView, len(steps))
for i, s := range steps {
	stepViews[i] = templates.PipelineStepView{
		ID:       s.ID,
		StepType: s.StepType,
		Status:   s.Status,
		Output:   s.Output,
		Thinking: s.Thinking,
	}
}
```

Pass `Phase: run.Phase` and `Steps: stepViews` to the template data.

- [ ] **Step 4: Run templ generate**

Run: `templ generate`
Expected: Generates Go code without errors

- [ ] **Step 5: Commit**

```bash
git add web/templates/pipeline.templ web/templates/pipeline_templ.go web/handlers/pipeline.go
git commit -m "feat: cornerstone page template with agent step cards"
```

---

### Task 10: Waterfall page template and handler

**Files:**
- Modify: `web/templates/pipeline.templ`
- Modify: `web/handlers/pipeline.go`

- [ ] **Step 1: Add WaterfallPageData and template**

```go
type WaterfallPageData struct {
	ProjectID        int64
	ProjectName      string
	RunID            int64
	CornerstoneTitle string
	PlanStep         *PipelineStepView
	Pieces           []ContentPieceView
	Status           string
}
```

Template shows:
- Cornerstone title as read-only header
- "Create Waterfall" button (triggers plan_waterfall step)
- After plan completes: waterfall piece cards with "Generate All" button
- Each piece card has same action buttons as current

- [ ] **Step 2: Add showWaterfall handler**

```go
func (h *PipelineHandler) showWaterfall(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	project, _ := h.queries.GetProject(projectID)
	run, err := h.queries.GetPipelineRun(runID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	pieces, _ := h.queries.ListContentByPipelineRun(runID)
	var cornerstoneTitle string
	var waterfallPieces []templates.ContentPieceView
	for _, p := range pieces {
		if p.ParentID == nil {
			cornerstoneTitle = p.Title
		} else {
			waterfallPieces = append(waterfallPieces, templates.ContentPieceView{
				ID:              p.ID,
				Platform:        p.Platform,
				Format:          p.Format,
				Title:           p.Title,
				Body:            p.Body,
				Status:          p.Status,
				SortOrder:       p.SortOrder,
				RejectionReason: p.RejectionReason,
				IsCornerstone:   false,
			})
		}
	}

	steps, _ := h.queries.ListPipelineSteps(runID)
	var planStep *templates.PipelineStepView
	for _, s := range steps {
		if s.StepType == "plan_waterfall" {
			planStep = &templates.PipelineStepView{
				ID:       s.ID,
				StepType: s.StepType,
				Status:   s.Status,
				Output:   s.Output,
				Thinking: s.Thinking,
			}
			break
		}
	}

	templates.WaterfallPage(templates.WaterfallPageData{
		ProjectID:        projectID,
		ProjectName:      project.Name,
		RunID:            runID,
		CornerstoneTitle: cornerstoneTitle,
		PlanStep:         planStep,
		Pieces:           waterfallPieces,
		Status:           run.Status,
	}).Render(r.Context(), w)
}
```

- [ ] **Step 3: Add routes**

In `Handle` method:

```go
case strings.HasSuffix(rest, "/waterfall") && r.Method == "GET":
	h.showWaterfall(w, r, projectID, rest)
case strings.HasSuffix(rest, "/waterfall/create-plan") && r.Method == "POST":
	h.createWaterfallPlan(w, r, projectID, rest)
```

```go
func (h *PipelineHandler) createWaterfallPlan(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	step, err := h.queries.CreatePipelineStep(runID, "plan_waterfall", 3)
	if err != nil {
		http.Error(w, "Failed to create plan step", http.StatusInternalServerError)
		return
	}
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{"step_id": step.ID})
}
```

- [ ] **Step 4: Run templ generate and verify build**

Run: `templ generate && go build ./...`

- [ ] **Step 5: Commit**

```bash
git add web/templates/pipeline.templ web/templates/pipeline_templ.go web/handlers/pipeline.go
git commit -m "feat: add waterfall page with planner and parallel generation"
```

---

### Task 11: JavaScript — cornerstone auto-chaining

**Files:**
- Modify: `web/static/app.js`

- [ ] **Step 1: Add initCornerstonePipeline function**

Add a function that auto-chains through research -> factcheck -> write steps. The "Run Pipeline" button starts the chain. Each step streams via SSE, and on `done` event the next step auto-starts.

Key logic:
- Find step cards by `[data-step-id]` attribute
- Stream each step to its output container
- On `done`, update status badge to "completed" and start next pending step
- On `error`, update badge to "failed" and stop chaining
- When all steps complete, reload to show the cornerstone piece

- [ ] **Step 2: Update piece approval JS to handle phase_change**

In the existing piece approval fetch response handling, check for `phase_change`:

```javascript
if (data.phase_change === 'waterfall') {
    window.location.href = `/projects/${projectID}/pipeline/${runID}/waterfall`;
    return;
}
```

- [ ] **Step 3: Commit**

```bash
git add web/static/app.js
git commit -m "feat: cornerstone auto-chaining JS with step streaming"
```

---

### Task 12: JavaScript — waterfall parallel generation

**Files:**
- Modify: `web/static/app.js`

- [ ] **Step 1: Add initWaterfallPage function**

Handles:
- "Create Waterfall" button: POSTs to create-plan, gets step_id, opens SSE stream for planning, reloads on done
- "Generate All" button: finds all pending piece cards, opens parallel SSE connections for each, streams content into each card independently

- [ ] **Step 2: Update waterfall piece prompts**

In `pipeline.go`, update `buildPiecePrompt` for waterfall pieces (where `piece.ParentID != nil`) to add funnel-focused priming:

```go
prompt += "\nIMPORTANT: This content exists to funnel audience to the cornerstone piece. Stay faithful to the cornerstone's message and facts. Do not introduce new claims or information that isn't in the cornerstone.\n"
```

- [ ] **Step 3: Commit**

```bash
git add web/static/app.js web/handlers/pipeline.go
git commit -m "feat: waterfall parallel generation JS and funnel-focused prompts"
```

---

### Task 13: Pipeline list — show phase progress

**Files:**
- Modify: `web/templates/pipeline.templ`
- Modify: `web/handlers/pipeline.go`

- [ ] **Step 1: Add Phase to PipelineRunView and update list handler**

- [ ] **Step 2: Update list template to show phase badge**

Show both phase and status badges per run.

- [ ] **Step 3: Run templ generate and verify**

Run: `templ generate && go build ./...`

- [ ] **Step 4: Commit**

```bash
git add web/templates/pipeline.templ web/templates/pipeline_templ.go web/handlers/pipeline.go
git commit -m "feat: show phase progress on pipeline list page"
```

---

### Task 14: Clean up old plan-first flow

**Files:**
- Modify: `web/handlers/pipeline.go`
- Modify: `web/templates/pipeline.templ`

- [ ] **Step 1: Remove old handlers**

Delete `streamPlan`, `approvePlan`, `rejectPlan` functions and their routes from `Handle`. Keep `streamPiece` — it's still used for waterfall piece generation.

- [ ] **Step 2: Remove planning UI from template**

Remove the `if data.Status == "planning"` block and the plan display block. These are replaced by step cards.

- [ ] **Step 3: Verify build**

Run: `templ generate && go build ./...`

- [ ] **Step 4: Commit**

```bash
git add web/handlers/pipeline.go web/templates/pipeline.templ web/templates/pipeline_templ.go
git commit -m "refactor: remove old plan-first pipeline flow"
```

---

### Task 15: Cornerstone rejection — re-run writer only

**Files:**
- Modify: `web/handlers/pipeline.go`

- [ ] **Step 1: Update rejectPiece for cornerstone**

When a cornerstone piece (ParentID == nil) is rejected, reset the writer step to pending so it can re-run with the rejection reason:

```go
func (h *PipelineHandler) rejectPiece(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	pieceID := h.parsePieceID(rest)
	runID := h.parseRunID(rest)
	r.ParseForm()
	reason := r.FormValue("reason")
	h.queries.SetContentPieceRejection(pieceID, reason)

	piece, _ := h.queries.GetContentPiece(pieceID)
	if piece.ParentID == nil {
		// Cornerstone rejected — reset writer step to pending
		steps, _ := h.queries.ListPipelineSteps(runID)
		for _, s := range steps {
			if s.StepType == "write" {
				h.queries.UpdatePipelineStepStatus(s.ID, "pending")
				break
			}
		}
	}

	w.WriteHeader(http.StatusOK)
}
```

- [ ] **Step 2: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: cornerstone rejection re-runs writer step only"
```

---

### Task 16: Step abort handler

**Files:**
- Modify: `web/handlers/pipeline.go`

- [ ] **Step 1: Add abort step route and handler**

Route (add to Handle):
```go
case strings.Contains(rest, "/step/") && strings.HasSuffix(rest, "/abort") && r.Method == "POST":
	h.abortStep(w, r, projectID, rest)
```

Handler:
```go
func (h *PipelineHandler) abortStep(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	stepID := h.parseStepID(rest)
	h.queries.UpdatePipelineStepStatus(stepID, "pending")
	runID := h.parseRunID(rest)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, runID), http.StatusSeeOther)
}
```

- [ ] **Step 2: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: add abort handler for pipeline steps"
```

---

### Task 17: End-to-end smoke test

**Files:** None (manual testing)

- [ ] **Step 1: Build and start**

Run: `make restart`

- [ ] **Step 2: Test cornerstone flow**

1. Go to a project's pipeline page
2. Enter a topic and start a new run
3. Verify three step cards appear (Researcher, Fact-Checker, Writer)
4. Click "Run Pipeline" — verify researcher streams, then fact-checker auto-starts, then writer auto-starts
5. Verify cornerstone piece appears after writer completes
6. Approve the cornerstone piece
7. Verify redirect to waterfall page

- [ ] **Step 3: Test waterfall flow**

1. On waterfall page, click "Create Waterfall"
2. Verify planner streams and creates waterfall pieces
3. Click "Generate All" — verify pieces generate in parallel
4. Approve all pieces
5. Verify pipeline status changes to "complete"

- [ ] **Step 4: Test error cases**

1. Abort a running step — verify it resets to pending
2. Reject a cornerstone piece — verify only writer step resets
3. Failed step — verify retry button works

- [ ] **Step 5: Commit any fixes**

```bash
git add -A
git commit -m "fix: smoke test fixes for agent pipeline"
```
