# Topic Generator Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone topic generation feature with two AI agents (explorer + reviewer) that ping-pong in a loop to discover and validate blog topics, presented in a pipeline-style UI with a persistent topic backlog.

**Architecture:** New handler, store, templates, and JS for a self-contained topic generator. Reuses the existing `runWithTools` helper, `sse.Stream`, tool registry, and prompt builder patterns. The ping-pong loop is orchestrated server-side in a single SSE connection. Two new prompt files define agent behavior.

**Tech Stack:** Go, templ, Alpine.js, SQLite, SSE, Brave Search API

**Spec:** `docs/superpowers/specs/2026-04-06-topic-generator-design.md`

---

### Task 1: Database Migration

**Files:**
- Create: `migrations/015_topic_generator.sql`

- [ ] **Step 1: Create the migration file**

```sql
-- +goose Up

CREATE TABLE topic_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    status TEXT NOT NULL DEFAULT 'running' CHECK(status IN ('running','completed','failed')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE topic_steps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_run_id INTEGER NOT NULL REFERENCES topic_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL CHECK(step_type IN ('topic_explore','topic_review')),
    round INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','running','completed','failed')),
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    tool_calls TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE topic_backlog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    topic_run_id INTEGER REFERENCES topic_runs(id),
    title TEXT NOT NULL,
    angle TEXT NOT NULL,
    sources TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'available' CHECK(status IN ('available','used','deleted')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- +goose Down
DROP TABLE IF EXISTS topic_backlog;
DROP TABLE IF EXISTS topic_steps;
DROP TABLE IF EXISTS topic_runs;
```

- [ ] **Step 2: Verify migration applies**

Run: `go run ./cmd/server/`
Expected: Server starts without migration errors, then Ctrl+C.

- [ ] **Step 3: Commit**

```bash
git add migrations/015_topic_generator.sql
git commit -m "feat(topics): add database migration for topic generator tables"
```

---

### Task 2: Store Layer

**Files:**
- Create: `internal/store/topic.go`
- Modify: `internal/store/interfaces.go`

- [ ] **Step 1: Create the store file with all CRUD methods**

Create `internal/store/topic.go`:

```go
package store

import "time"

type TopicRun struct {
	ID        int64
	ProjectID int64
	Status    string
	CreatedAt time.Time
	UpdatedAt time.Time
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

func (q *Queries) CreateTopicRun(projectID int64) (*TopicRun, error) {
	res, err := q.db.Exec("INSERT INTO topic_runs (project_id) VALUES (?)", projectID)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetTopicRun(id)
}

func (q *Queries) GetTopicRun(id int64) (*TopicRun, error) {
	r := &TopicRun{}
	err := q.db.QueryRow(
		"SELECT id, project_id, status, created_at, updated_at FROM topic_runs WHERE id = ?", id,
	).Scan(&r.ID, &r.ProjectID, &r.Status, &r.CreatedAt, &r.UpdatedAt)
	return r, err
}

func (q *Queries) ListTopicRuns(projectID int64) ([]TopicRun, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, status, created_at, updated_at FROM topic_runs WHERE project_id = ? ORDER BY created_at DESC", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var runs []TopicRun
	for rows.Next() {
		var r TopicRun
		if err := rows.Scan(&r.ID, &r.ProjectID, &r.Status, &r.CreatedAt, &r.UpdatedAt); err != nil {
			return nil, err
		}
		runs = append(runs, r)
	}
	return runs, rows.Err()
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
		`SELECT id, topic_run_id, step_type, round, status, output, thinking, tool_calls, sort_order, created_at, updated_at
		 FROM topic_steps WHERE id = ?`, id,
	).Scan(&s.ID, &s.TopicRunID, &s.StepType, &s.Round, &s.Status, &s.Output, &s.Thinking, &s.ToolCalls, &s.SortOrder, &s.CreatedAt, &s.UpdatedAt)
	return s, err
}

func (q *Queries) ListTopicSteps(topicRunID int64) ([]TopicStep, error) {
	rows, err := q.db.Query(
		`SELECT id, topic_run_id, step_type, round, status, output, thinking, tool_calls, sort_order, created_at, updated_at
		 FROM topic_steps WHERE topic_run_id = ? ORDER BY sort_order ASC`, topicRunID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var steps []TopicStep
	for rows.Next() {
		var s TopicStep
		if err := rows.Scan(&s.ID, &s.TopicRunID, &s.StepType, &s.Round, &s.Status, &s.Output, &s.Thinking, &s.ToolCalls, &s.SortOrder, &s.CreatedAt, &s.UpdatedAt); err != nil {
			return nil, err
		}
		steps = append(steps, s)
	}
	return steps, rows.Err()
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

// CountTopicRunTopics returns the number of approved topics produced by a run.
func (q *Queries) CountTopicRunTopics(topicRunID int64) (int, error) {
	var count int
	err := q.db.QueryRow(
		"SELECT COUNT(*) FROM topic_backlog WHERE topic_run_id = ? AND status != 'deleted'", topicRunID,
	).Scan(&count)
	return count, err
}
```

- [ ] **Step 2: Add TopicStore interface to interfaces.go**

Add after the existing `ProjectSettingsStore` interface in `internal/store/interfaces.go`:

```go
type TopicStore interface {
	CreateTopicRun(projectID int64) (*TopicRun, error)
	GetTopicRun(id int64) (*TopicRun, error)
	ListTopicRuns(projectID int64) ([]TopicRun, error)
	UpdateTopicRunStatus(id int64, status string) error
	CreateTopicStep(topicRunID int64, stepType string, round, sortOrder int) (*TopicStep, error)
	GetTopicStep(id int64) (*TopicStep, error)
	ListTopicSteps(topicRunID int64) ([]TopicStep, error)
	UpdateTopicStepStatus(id int64, status string) error
	UpdateTopicStepOutput(id int64, output, thinking string) error
	UpdateTopicStepToolCalls(id int64, toolCalls string) error
	CreateTopicBacklogItem(projectID, topicRunID int64, title, angle, sources string) (*TopicBacklogItem, error)
	GetTopicBacklogItem(id int64) (*TopicBacklogItem, error)
	ListTopicBacklog(projectID int64) ([]TopicBacklogItem, error)
	UpdateTopicBacklogStatus(id int64, status string) error
	CountTopicRunTopics(topicRunID int64) (int, error)
}
```

- [ ] **Step 3: Verify build**

Run: `go build ./...`
Expected: Clean build, no errors.

- [ ] **Step 4: Commit**

```bash
git add internal/store/topic.go internal/store/interfaces.go
git commit -m "feat(topics): add store layer for topic runs, steps, and backlog"
```

---

### Task 3: Tool Registry — Topic Tools

**Files:**
- Modify: `internal/tools/registry.go`

- [ ] **Step 1: Add topic_explore and topic_review tool sets to the registry constructor**

In `internal/tools/registry.go`, add after the existing `r.stepTools["editor"]` block (before `r.stepTools["write"]`):

```go
	r.stepTools["topic_explore"] = []ai.Tool{fetchTool, searchTool, submitTool(
		"submit_topics",
		"Submit your discovered topic candidates. Call this when you have 3-5 well-researched topics ready.",
		`{"type":"object","properties":{"topics":{"type":"array","description":"3-5 topic candidates","items":{"type":"object","properties":{"title":{"type":"string","description":"Topic title — specific and compelling"},"angle":{"type":"string","description":"Why this topic fits the brand and what angle to take, 1-2 sentences"},"evidence":{"type":"string","description":"What research supports this topic — trends, gaps, audience interest"}},"required":["title","angle","evidence"]}}},"required":["topics"]}`,
	)}

	r.stepTools["topic_review"] = []ai.Tool{submitTool(
		"submit_review",
		"Submit your review of the proposed topics. Approve or reject each one with clear reasoning.",
		`{"type":"object","properties":{"reviews":{"type":"array","description":"One review per proposed topic","items":{"type":"object","properties":{"title":{"type":"string","description":"Echo back the topic title exactly"},"verdict":{"type":"string","enum":["approved","rejected"],"description":"Whether this topic passes the common sense check"},"reasoning":{"type":"string","description":"Why this topic was approved or rejected"}},"required":["title","verdict","reasoning"]}}},"required":["reviews"]}`,
	)}
```

- [ ] **Step 2: Verify build**

Run: `go build ./...`
Expected: Clean build.

- [ ] **Step 3: Commit**

```bash
git add internal/tools/registry.go
git commit -m "feat(topics): add topic_explore and topic_review tool sets to registry"
```

---

### Task 4: Prompt Builder — Topic Prompts

**Files:**
- Modify: `internal/prompt/builder.go`

- [ ] **Step 1: Add ForTopicExplore and ForTopicReview methods**

Add these methods to the `Builder` type in `internal/prompt/builder.go`:

```go
func (b *Builder) ForTopicExplore(profile, blogContent, homepageContent, rejectedTopics, approvedTopics string) string {
	var sb strings.Builder
	sb.WriteString(b.DateHeader())
	sb.WriteString(`

You are a topic researcher. Your job is to find 3-5 compelling blog topics for this brand.

## Your process
1. Review the brand profile to understand who they are, their audience, and their niche
2. Search the web for trending topics, news, and discussions in the brand's space
3. If a blog URL was provided, check what they've recently published to avoid duplicates
4. Identify content gaps and opportunities based on audience pain points
5. Propose 3-5 specific, actionable blog topics with clear angles

## Rules
- Each topic must have a specific angle, not just a broad subject
- Topics should be timely and relevant to the brand's audience
- Avoid generic topics that any brand could write about — find angles unique to this brand
- Do NOT propose topics that duplicate recent blog posts

## Client profile
`)
	sb.WriteString(profile)

	if blogContent != "" {
		sb.WriteString("\n\n## Recent blog posts (avoid duplicating these)\n")
		sb.WriteString(blogContent)
	}

	if homepageContent != "" {
		sb.WriteString("\n\n## Homepage content (for brand context)\n")
		sb.WriteString(homepageContent)
	}

	if approvedTopics != "" {
		sb.WriteString("\n\n## Already approved topics (do NOT re-propose these)\n")
		sb.WriteString(approvedTopics)
	}

	if rejectedTopics != "" {
		sb.WriteString("\n\n## Previously rejected topics (explore different angles)\n")
		sb.WriteString(rejectedTopics)
	}

	return sb.String()
}

func (b *Builder) ForTopicReview(profile, topicsJSON string) string {
	var sb strings.Builder
	sb.WriteString(b.DateHeader())
	sb.WriteString(`

You are a common-sense editorial reviewer. You receive proposed blog topics and evaluate whether each one can be logically angled into a coherent story for this brand's blog.

## Evaluation criteria
For each topic, ask yourself:
1. **Brand fit:** Is the connection between this topic and the brand natural, or does it feel forced?
2. **Angle clarity:** Is the proposed angle specific enough to write a focused, interesting article?
3. **Scope:** Is the topic too broad (unfocused) or too narrow (not enough to say)?
4. **Reader logic:** Would a reader of this brand's blog understand why the brand is writing about this? Would they find it valuable?
5. **Story potential:** Can this be turned into a compelling narrative, not just an informational dump?

## Rules
- Approve topics where the brand connection is natural and the angle is clear
- Reject topics where the angle is forced, too vague, or doesn't serve the audience
- Be specific in your reasoning — say exactly what works or what doesn't
- You are a filter, not a perfectionist. If a topic is good enough with minor adjustments, approve it.

## Client profile
`)
	sb.WriteString(profile)
	sb.WriteString("\n\n## Proposed topics to review\n")
	sb.WriteString(topicsJSON)

	return sb.String()
}
```

- [ ] **Step 2: Verify build**

Run: `go build ./...`
Expected: Clean build.

- [ ] **Step 3: Commit**

```bash
git add internal/prompt/builder.go
git commit -m "feat(topics): add prompt builder methods for topic explore and review agents"
```

---

### Task 5: Topic Handler — Routes and Orchestration

**Files:**
- Create: `web/handlers/topic.go`
- Modify: `internal/pipeline/steps/common.go` (export `RunWithTools`)
- Modify: `internal/pipeline/steps/research.go`, `brand_enricher.go`, `factcheck.go`, `editor.go` (update callers)

- [ ] **Step 1: Export RunWithTools from the steps package**

The `runWithTools` function in `internal/pipeline/steps/common.go` is lowercase (unexported). The topic handler needs to call it. Rename it to `RunWithTools` on the function definition line.

Then update all callers in the steps package. In each of `research.go`, `brand_enricher.go`, `factcheck.go`, `editor.go`, replace `runWithTools(` with `RunWithTools(`.

Do NOT change `writer.go` — the writer step does not call `runWithTools`.

- [ ] **Step 2: Create the handler file**

Create `web/handlers/topic.go`:

```go
package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/applog"
	"github.com/zanfridau/marketminded/internal/pipeline/steps"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/search"
	"github.com/zanfridau/marketminded/internal/sse"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/web/templates"
)

type TopicHandler struct {
	queries       *store.Queries
	aiClient      *ai.Client
	braveClient   *search.BraveClient
	toolRegistry  *tools.Registry
	promptBuilder *prompt.Builder
	model         func() string
}

func NewTopicHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, toolRegistry *tools.Registry, promptBuilder *prompt.Builder, model func() string) *TopicHandler {
	return &TopicHandler{queries: q, aiClient: aiClient, braveClient: braveClient, toolRegistry: toolRegistry, promptBuilder: promptBuilder, model: model}
}

func (h *TopicHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "topics" && r.Method == "GET":
		h.list(w, r, projectID)
	case rest == "topics/generate" && r.Method == "POST":
		h.generate(w, r, projectID)
	case strings.HasSuffix(rest, "/stream"):
		h.stream(w, r, projectID, rest)
	case strings.Contains(rest, "backlog/") && strings.HasSuffix(rest, "/start") && r.Method == "POST":
		h.startPipeline(w, r, projectID, rest)
	case strings.Contains(rest, "backlog/") && strings.HasSuffix(rest, "/delete") && r.Method == "POST":
		h.deleteBacklogItem(w, r, projectID, rest)
	default:
		h.showRun(w, r, projectID, rest)
	}
}

func (h *TopicHandler) list(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, _ := h.queries.GetProject(projectID)
	runs, _ := h.queries.ListTopicRuns(projectID)
	backlog, _ := h.queries.ListTopicBacklog(projectID)

	runViews := make([]templates.TopicRunView, len(runs))
	for i, run := range runs {
		topicCount, _ := h.queries.CountTopicRunTopics(run.ID)
		runViews[i] = templates.TopicRunView{
			ID:         run.ID,
			Status:     run.Status,
			TopicCount: topicCount,
			CreatedAt:  run.CreatedAt,
		}
	}

	backlogViews := make([]templates.TopicBacklogView, len(backlog))
	for i, item := range backlog {
		backlogViews[i] = templates.TopicBacklogView{
			ID:     item.ID,
			Title:  item.Title,
			Angle:  item.Angle,
			Status: item.Status,
		}
	}

	templates.TopicListPage(templates.TopicListData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Runs:        runViews,
		Backlog:     backlogViews,
	}).Render(r.Context(), w)
}

func (h *TopicHandler) generate(w http.ResponseWriter, r *http.Request, projectID int64) {
	run, err := h.queries.CreateTopicRun(projectID)
	if err != nil {
		http.Error(w, "Failed to create topic run", http.StatusInternalServerError)
		return
	}
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/topics/%d", projectID, run.ID), http.StatusSeeOther)
}

func (h *TopicHandler) showRun(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	if runID == 0 {
		http.NotFound(w, r)
		return
	}

	project, _ := h.queries.GetProject(projectID)
	run, err := h.queries.GetTopicRun(runID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	topicSteps, _ := h.queries.ListTopicSteps(runID)
	stepViews := make([]templates.TopicStepView, len(topicSteps))
	for i, s := range topicSteps {
		stepViews[i] = templates.TopicStepView{
			ID:        s.ID,
			StepType:  s.StepType,
			Round:     s.Round,
			Status:    s.Status,
			Output:    s.Output,
			Thinking:  s.Thinking,
			ToolCalls: s.ToolCalls,
		}
	}

	backlog, _ := h.queries.ListTopicBacklog(projectID)
	var approvedTopics []templates.TopicBacklogView
	for _, item := range backlog {
		if item.TopicRunID == runID && item.Status != "deleted" {
			approvedTopics = append(approvedTopics, templates.TopicBacklogView{
				ID:     item.ID,
				Title:  item.Title,
				Angle:  item.Angle,
				Status: item.Status,
			})
		}
	}

	templates.TopicRunPage(templates.TopicRunData{
		ProjectID:      projectID,
		ProjectName:    project.Name,
		RunID:          runID,
		Status:         run.Status,
		Steps:          stepViews,
		ApprovedTopics: approvedTopics,
	}).Render(r.Context(), w)
}

func (h *TopicHandler) stream(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	if runID == 0 {
		http.Error(w, "Invalid run ID", http.StatusBadRequest)
		return
	}

	run, err := h.queries.GetTopicRun(runID)
	if err != nil || run.Status != "running" {
		http.Error(w, "Run not found or not running", http.StatusBadRequest)
		return
	}

	sseStream, err := sse.New(w)
	if err != nil {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}
	stream := &httpStepStream{sse: sseStream}

	// Build context
	profile, _ := h.queries.BuildProfileStringExcluding(projectID, []string{"content_strategy"})

	blogURL, _ := h.queries.GetProjectSetting(projectID, "blog_url")
	homepageURL, _ := h.queries.GetProjectSetting(projectID, "homepage_url")

	var blogContent, homepageContent string
	if blogURL != "" {
		blogContent, _ = tools.ExecuteFetch(r.Context(), fmt.Sprintf(`{"url":"%s"}`, blogURL))
	}
	if homepageURL != "" {
		homepageContent, _ = tools.ExecuteFetch(r.Context(), fmt.Sprintf(`{"url":"%s"}`, homepageURL))
	}

	// Run the ping-pong loop
	var allApproved []topicCandidate
	var allRejected []rejectedTopic
	sortOrder := 0

	for round := 1; round <= 3; round++ {
		// --- Explorer step ---
		exploreStep, err := h.queries.CreateTopicStep(runID, "topic_explore", round, sortOrder)
		sortOrder++
		if err != nil {
			stream.SendError("Failed to create explore step")
			break
		}

		sseStream.SendData(map[string]any{"type": "step_start", "round": round, "step_type": "topic_explore", "step_id": exploreStep.ID})
		h.queries.UpdateTopicStepStatus(exploreStep.ID, "running")

		// Build rejected/approved context for rounds 2+
		rejectedText := ""
		approvedText := ""
		if len(allRejected) > 0 {
			rj, _ := json.MarshalIndent(allRejected, "", "  ")
			rejectedText = string(rj)
		}
		if len(allApproved) > 0 {
			ap, _ := json.MarshalIndent(allApproved, "", "  ")
			approvedText = string(ap)
		}

		systemPrompt := h.promptBuilder.ForTopicExplore(profile, blogContent, homepageContent, rejectedText, approvedText)
		toolList := h.toolRegistry.ForStep("topic_explore")

		exploreResult, exploreErr := steps.RunWithTools(r.Context(), h.aiClient, h.model(), systemPrompt, "Search for topics now.", toolList, h.toolRegistry, "submit_topics", stream, 0.7, 15)

		h.queries.UpdateTopicStepOutput(exploreStep.ID, exploreResult.Output, exploreResult.Thinking)
		if exploreResult.ToolCalls != "" {
			h.queries.UpdateTopicStepToolCalls(exploreStep.ID, exploreResult.ToolCalls)
		}

		if exploreErr != nil {
			h.queries.UpdateTopicStepStatus(exploreStep.ID, "failed")
			sseStream.SendData(map[string]any{"type": "step_done", "round": round, "step_type": "topic_explore", "status": "failed"})
			stream.SendError(fmt.Sprintf("Explorer failed: %v", exploreErr))
			h.queries.UpdateTopicRunStatus(runID, "failed")
			stream.SendDone()
			return
		}
		h.queries.UpdateTopicStepStatus(exploreStep.ID, "completed")
		sseStream.SendData(map[string]any{"type": "step_done", "round": round, "step_type": "topic_explore", "status": "completed"})

		// Parse explorer output
		var exploreOutput struct {
			Topics []topicCandidate `json:"topics"`
		}
		if err := json.Unmarshal([]byte(exploreResult.Output), &exploreOutput); err != nil {
			applog.Error("topics: failed to parse explorer output: %v", err)
			stream.SendError("Explorer output was not valid JSON")
			h.queries.UpdateTopicRunStatus(runID, "failed")
			stream.SendDone()
			return
		}

		// --- Reviewer step ---
		reviewStep, err := h.queries.CreateTopicStep(runID, "topic_review", round, sortOrder)
		sortOrder++
		if err != nil {
			stream.SendError("Failed to create review step")
			break
		}

		sseStream.SendData(map[string]any{"type": "step_start", "round": round, "step_type": "topic_review", "step_id": reviewStep.ID})
		h.queries.UpdateTopicStepStatus(reviewStep.ID, "running")

		topicsJSON, _ := json.MarshalIndent(exploreOutput.Topics, "", "  ")
		reviewSystemPrompt := h.promptBuilder.ForTopicReview(profile, string(topicsJSON))
		reviewToolList := h.toolRegistry.ForStep("topic_review")

		reviewResult, reviewErr := steps.RunWithTools(r.Context(), h.aiClient, h.model(), reviewSystemPrompt, "Review these topics now.", reviewToolList, h.toolRegistry, "submit_review", stream, 0.3, 5)

		h.queries.UpdateTopicStepOutput(reviewStep.ID, reviewResult.Output, reviewResult.Thinking)
		if reviewResult.ToolCalls != "" {
			h.queries.UpdateTopicStepToolCalls(reviewStep.ID, reviewResult.ToolCalls)
		}

		if reviewErr != nil {
			h.queries.UpdateTopicStepStatus(reviewStep.ID, "failed")
			sseStream.SendData(map[string]any{"type": "step_done", "round": round, "step_type": "topic_review", "status": "failed"})
			stream.SendError(fmt.Sprintf("Reviewer failed: %v", reviewErr))
			h.queries.UpdateTopicRunStatus(runID, "failed")
			stream.SendDone()
			return
		}
		h.queries.UpdateTopicStepStatus(reviewStep.ID, "completed")
		sseStream.SendData(map[string]any{"type": "step_done", "round": round, "step_type": "topic_review", "status": "completed"})

		// Parse reviewer output
		var reviewOutput struct {
			Reviews []struct {
				Title     string `json:"title"`
				Verdict   string `json:"verdict"`
				Reasoning string `json:"reasoning"`
			} `json:"reviews"`
		}
		if err := json.Unmarshal([]byte(reviewResult.Output), &reviewOutput); err != nil {
			applog.Error("topics: failed to parse reviewer output: %v", err)
			stream.SendError("Reviewer output was not valid JSON")
			h.queries.UpdateTopicRunStatus(runID, "failed")
			stream.SendDone()
			return
		}

		// Collect approved and rejected
		topicsByTitle := make(map[string]topicCandidate)
		for _, t := range exploreOutput.Topics {
			topicsByTitle[t.Title] = t
		}

		for _, review := range reviewOutput.Reviews {
			if review.Verdict == "approved" {
				if tc, ok := topicsByTitle[review.Title]; ok {
					allApproved = append(allApproved, tc)
				}
			} else {
				allRejected = append(allRejected, rejectedTopic{
					Title:     review.Title,
					Reasoning: review.Reasoning,
				})
			}
		}

		sseStream.SendData(map[string]any{
			"type":           "round_complete",
			"round":          round,
			"approved_count": len(allApproved),
		})

		if len(allApproved) >= 2 {
			break
		}
	}

	// Save approved topics to backlog
	for _, topic := range allApproved {
		sourcesJSON, _ := json.Marshal(topic.Evidence)
		h.queries.CreateTopicBacklogItem(projectID, runID, topic.Title, topic.Angle, string(sourcesJSON))
	}

	h.queries.UpdateTopicRunStatus(runID, "completed")

	// Send final topics
	sseStream.SendData(map[string]any{
		"type":   "done",
		"topics": allApproved,
	})
}

func (h *TopicHandler) startPipeline(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	topicID := h.parseBacklogID(rest)
	if topicID == 0 {
		http.Error(w, "Invalid topic ID", http.StatusBadRequest)
		return
	}

	item, err := h.queries.GetTopicBacklogItem(topicID)
	if err != nil {
		http.Error(w, "Topic not found", http.StatusNotFound)
		return
	}

	brief := fmt.Sprintf("%s\n\nAngle: %s", item.Title, item.Angle)
	run, err := h.queries.CreatePipelineRun(projectID, brief)
	if err != nil {
		http.Error(w, "Failed to create pipeline run", http.StatusInternalServerError)
		return
	}

	h.queries.CreatePipelineStep(run.ID, "research", 0)
	h.queries.CreatePipelineStep(run.ID, "brand_enricher", 1)
	h.queries.CreatePipelineStep(run.ID, "factcheck", 2)
	h.queries.CreatePipelineStep(run.ID, "editor", 3)
	h.queries.CreatePipelineStep(run.ID, "write", 4)

	h.queries.UpdateTopicBacklogStatus(topicID, "used")

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/pipeline/%d", projectID, run.ID), http.StatusSeeOther)
}

func (h *TopicHandler) deleteBacklogItem(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	topicID := h.parseBacklogID(rest)
	if topicID == 0 {
		http.Error(w, "Invalid topic ID", http.StatusBadRequest)
		return
	}
	h.queries.UpdateTopicBacklogStatus(topicID, "deleted")
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/topics", projectID), http.StatusSeeOther)
}

// --- Helpers ---

type topicCandidate struct {
	Title    string `json:"title"`
	Angle    string `json:"angle"`
	Evidence string `json:"evidence"`
}

type rejectedTopic struct {
	Title     string `json:"title"`
	Reasoning string `json:"reasoning"`
}

func (h *TopicHandler) parseRunID(rest string) int64 {
	// rest = "topics/123" or "topics/123/stream"
	parts := strings.Split(strings.TrimPrefix(rest, "topics/"), "/")
	id, err := strconv.ParseInt(parts[0], 10, 64)
	if err != nil {
		return 0
	}
	return id
}

func (h *TopicHandler) parseBacklogID(rest string) int64 {
	// rest = "topics/backlog/123/start" or "topics/backlog/123/delete"
	parts := strings.Split(rest, "/")
	for i, p := range parts {
		if p == "backlog" && i+1 < len(parts) {
			id, err := strconv.ParseInt(parts[i+1], 10, 64)
			if err != nil {
				return 0
			}
			return id
		}
	}
	return 0
}
```

- [ ] **Step 3: Verify build**

Run: `go build ./...`
Expected: Clean build.

- [ ] **Step 4: Commit**

```bash
git add web/handlers/topic.go internal/pipeline/steps/common.go internal/pipeline/steps/research.go internal/pipeline/steps/brand_enricher.go internal/pipeline/steps/factcheck.go internal/pipeline/steps/editor.go
git commit -m "feat(topics): add topic handler with ping-pong orchestration loop"
```

---

### Task 6: Templates — Topic Pages

**Files:**
- Create: `web/templates/topic.templ`

- [ ] **Step 1: Create the template file with list and run detail pages**

Create `web/templates/topic.templ`:

```templ
package templates

import (
	"fmt"
	"time"
	"github.com/zanfridau/marketminded/web/templates/components"
)

type TopicListData struct {
	ProjectID   int64
	ProjectName string
	Runs        []TopicRunView
	Backlog     []TopicBacklogView
}

type TopicRunView struct {
	ID         int64
	Status     string
	TopicCount int
	CreatedAt  time.Time
}

type TopicBacklogView struct {
	ID     int64
	Title  string
	Angle  string
	Status string
}

type TopicRunData struct {
	ProjectID      int64
	ProjectName    string
	RunID          int64
	Status         string
	Steps          []TopicStepView
	ApprovedTopics []TopicBacklogView
}

type TopicStepView struct {
	ID        int64
	StepType  string
	Round     int
	Status    string
	Output    string
	Thinking  string
	ToolCalls string
}

templ topicStepTypeLabel(stepType string) {
	switch stepType {
	case "topic_explore":
		Explorer
	case "topic_review":
		Reviewer
	default:
		{ stepType }
	}
}

templ TopicListPage(data TopicListData) {
	@components.ProjectPageShell(data.ProjectName+" - Topics", []components.Breadcrumb{
		{Label: "Projects", URL: "/projects"},
		{Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
		{Label: "Topics"},
	}, data.ProjectID, data.ProjectName, "topics") {
		<div class="flex items-center justify-between mb-6">
			<h1 class="text-2xl font-bold">Topics</h1>
			<form method="POST" action={ templ.SafeURL(fmt.Sprintf("/projects/%d/topics/generate", data.ProjectID)) } class="inline">
				<button type="submit" class="btn btn-primary">Generate</button>
			</form>
		</div>

		if len(data.Backlog) > 0 {
			<h2 class="text-lg font-semibold text-zinc-300 mb-3">Topic Backlog</h2>
			<div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-8">
				for _, item := range data.Backlog {
					<div class={ "card p-4", templ.KV("opacity-50", item.Status == "used") }>
						<div class="flex items-start justify-between gap-2">
							<div class="min-w-0">
								<h3 class="font-semibold text-zinc-100 text-sm">{ item.Title }</h3>
								<p class="text-zinc-400 text-xs mt-1 line-clamp-3">{ item.Angle }</p>
							</div>
							if item.Status == "used" {
								<span class="badge badge-sm badge-ghost shrink-0">Used</span>
							}
						</div>
						if item.Status == "available" {
							<div class="flex gap-2 mt-3">
								<form method="POST" action={ templ.SafeURL(fmt.Sprintf("/projects/%d/topics/backlog/%d/start", data.ProjectID, item.ID)) } class="inline">
									<button type="submit" class="btn btn-primary btn-xs">Start Pipeline</button>
								</form>
								<form method="POST" action={ templ.SafeURL(fmt.Sprintf("/projects/%d/topics/backlog/%d/delete", data.ProjectID, item.ID)) } class="inline" onsubmit="return confirm('Delete this topic?')">
									<button type="submit" class="btn btn-ghost btn-xs text-red-400">Delete</button>
								</form>
							</div>
						}
					</div>
				}
			</div>
		}

		<h2 class="text-lg font-semibold text-zinc-300 mb-3">Generation Runs</h2>
		<div class="flex flex-col gap-3">
			if len(data.Runs) == 0 {
				<p class="text-zinc-500">No topic generation runs yet. Click Generate to start.</p>
			}
			for _, run := range data.Runs {
				<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/topics/%d", data.ProjectID, run.ID)) } class="card block hover:border-zinc-700 transition-colors">
					<div class="p-4 flex items-center justify-between">
						<div class="flex items-center gap-3">
							<span class="text-sm text-zinc-400">{ run.CreatedAt.Format("Jan 2, 2006 3:04 PM") }</span>
							if run.TopicCount > 0 {
								<span class="text-xs text-zinc-500">{ fmt.Sprintf("%d topics", run.TopicCount) }</span>
							}
						</div>
						@components.StatusBadge(run.Status)
					</div>
				</a>
			}
		</div>
	}
}

templ TopicRunPage(data TopicRunData) {
	@components.ProjectPageShell("Topic Generation", []components.Breadcrumb{
		{Label: "Projects", URL: "/projects"},
		{Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
		{Label: "Topics", URL: fmt.Sprintf("/projects/%d/topics", data.ProjectID)},
		{Label: "Run"},
	}, data.ProjectID, data.ProjectName, "topics") {
		<div id="topic-run-page" data-project-id={ fmt.Sprintf("%d", data.ProjectID) } data-run-id={ fmt.Sprintf("%d", data.RunID) } data-status={ data.Status }>
			<div class="flex items-center justify-between mb-4">
				<div>
					@components.StatusBadge(data.Status)
					<h1 class="text-2xl font-bold mt-1">Topic Generation</h1>
				</div>
				<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/topics", data.ProjectID)) } class="btn btn-ghost btn-sm">Back</a>
			</div>

			if len(data.ApprovedTopics) > 0 {
				<h2 class="text-lg font-semibold text-zinc-300 mb-3">Approved Topics</h2>
				<div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-6">
					for _, topic := range data.ApprovedTopics {
						<div class="card p-4 border-green-500/30">
							<h3 class="font-semibold text-zinc-100 text-sm">{ topic.Title }</h3>
							<p class="text-zinc-400 text-xs mt-1">{ topic.Angle }</p>
						</div>
					}
				</div>
			}

			<div class="flex flex-col gap-3" id="topic-steps">
				for _, step := range data.Steps {
					<div
						class="step-card card"
						data-step-id={ fmt.Sprintf("%d", step.ID) }
						data-status={ step.Status }
						data-round={ fmt.Sprintf("%d", step.Round) }
						data-tool-calls={ step.ToolCalls }
					>
						<div class="p-3">
							<div class="board-card-header flex items-center gap-2">
								<span class="text-xs text-zinc-500">Round { fmt.Sprintf("%d", step.Round) }</span>
								<strong class="text-sm">@topicStepTypeLabel(step.StepType)</strong>
								<span class="ml-auto">@components.StatusBadge(step.Status)</span>
							</div>
							<div class="step-tool-pills flex flex-wrap gap-1 mt-2 empty:hidden"></div>
							<div class="step-thinking-ticker font-mono text-xs max-h-24 overflow-y-auto mt-1 empty:hidden"></div>
							if step.Status == "completed" && step.Output != "" {
								<div class="step-output mt-2">{ step.Output }</div>
							} else if step.Status == "failed" && step.Output != "" {
								<details class="mt-2">
									<summary class="cursor-pointer text-sm text-red-400">View failed output</summary>
									<div class="step-stream whitespace-pre-wrap text-xs mt-2 max-h-72 overflow-y-auto p-2 bg-red-500/5 border border-red-500/20 rounded">{ step.Output }</div>
								</details>
							} else {
								<div class="step-stream whitespace-pre-wrap text-sm mt-2 max-h-72 overflow-y-auto empty:hidden"></div>
								<div class="step-output mt-2 empty:hidden"></div>
							}
						</div>
					</div>
				}
			</div>

			if data.Status == "running" && len(data.Steps) == 0 {
				<div class="flex items-center gap-2 mt-4">
					<button class="btn btn-primary" id="run-topics-btn">Run Topic Generator</button>
				</div>
			}
		</div>

		<script>
			(function() {
				var page = document.getElementById('topic-run-page');
				if (!page) return;

				var projectID = page.dataset.projectId;
				var runID = page.dataset.runId;
				var status = page.dataset.status;
				var stepsContainer = document.getElementById('topic-steps');
				var runBtn = document.getElementById('run-topics-btn');

				function addToolPill(card, type, value) {
					var pillsEl = card.querySelector('.step-tool-pills');
					if (!pillsEl) return;
					if (type === 'search') {
						var pill = document.createElement('span');
						pill.className = 'badge badge-sm badge-secondary gap-1';
						pill.textContent = '\uD83D\uDD0D ' + (value.length > 30 ? value.substring(0, 30) + '\u2026' : value);
						pill.title = value;
						pillsEl.appendChild(pill);
					} else if (type === 'fetch') {
						var a = document.createElement('a');
						a.className = 'badge badge-sm badge-accent gap-1';
						a.href = value;
						a.target = '_blank';
						var host = value;
						try { host = new URL(value).hostname; } catch(e) { host = value.substring(0, 25); }
						a.textContent = '\uD83C\uDF10 ' + host;
						a.title = value;
						pillsEl.appendChild(a);
					}
				}

				function setBadge(card, text, cls) {
					var badges = card.querySelectorAll('.badge');
					var badge = badges[badges.length - 1];
					if (!badge) return;
					badge.textContent = text;
					badge.className = 'badge ' + (cls || '');
				}

				function createStepCard(stepId, round, stepType) {
					var card = document.createElement('div');
					card.className = 'step-card card';
					card.dataset.stepId = stepId;
					card.dataset.status = 'running';
					card.dataset.round = round;

					var typeLabel = stepType === 'topic_explore' ? 'Explorer' : 'Reviewer';

					var inner = document.createElement('div');
					inner.className = 'p-3';

					var header = document.createElement('div');
					header.className = 'board-card-header flex items-center gap-2';

					var roundLabel = document.createElement('span');
					roundLabel.className = 'text-xs text-zinc-500';
					roundLabel.textContent = 'Round ' + round;

					var nameEl = document.createElement('strong');
					nameEl.className = 'text-sm';
					nameEl.textContent = typeLabel;

					var badge = document.createElement('span');
					badge.className = 'ml-auto badge badge-running';
					badge.textContent = 'running';

					header.appendChild(roundLabel);
					header.appendChild(nameEl);
					header.appendChild(badge);

					var pills = document.createElement('div');
					pills.className = 'step-tool-pills flex flex-wrap gap-1 mt-2 empty:hidden';

					var ticker = document.createElement('div');
					ticker.className = 'step-thinking-ticker font-mono text-xs max-h-24 overflow-y-auto mt-1 empty:hidden';

					var streamEl = document.createElement('div');
					streamEl.className = 'step-stream whitespace-pre-wrap text-sm mt-2 max-h-72 overflow-y-auto empty:hidden';

					var outputEl = document.createElement('div');
					outputEl.className = 'step-output mt-2 empty:hidden';

					inner.appendChild(header);
					inner.appendChild(pills);
					inner.appendChild(ticker);
					inner.appendChild(streamEl);
					inner.appendChild(outputEl);
					card.appendChild(inner);

					stepsContainer.appendChild(card);
					return card;
				}

				function renderStepOutput(card) {
					var outputEl = card.querySelector('.step-output');
					if (!outputEl) return;
					var raw = outputEl.textContent.trim();
					if (!raw) return;

					var stepType = card.querySelector('strong');
					var typeName = stepType ? stepType.textContent.trim() : '';

					try {
						var data = JSON.parse(raw);
						outputEl.textContent = '';

						if (typeName === 'Explorer' && data.topics) {
							var container = document.createElement('div');
							container.className = 'space-y-2';
							data.topics.forEach(function(t) {
								var item = document.createElement('div');
								item.className = 'bg-zinc-800/50 rounded p-2';
								var title = document.createElement('p');
								title.className = 'text-sm font-medium text-zinc-200';
								title.textContent = t.title;
								var angle = document.createElement('p');
								angle.className = 'text-xs text-zinc-400 mt-1';
								angle.textContent = t.angle;
								item.appendChild(title);
								item.appendChild(angle);
								container.appendChild(item);
							});
							outputEl.appendChild(container);
						} else if (typeName === 'Reviewer' && data.reviews) {
							var container = document.createElement('div');
							container.className = 'space-y-2';
							data.reviews.forEach(function(r) {
								var item = document.createElement('div');
								item.className = 'bg-zinc-800/50 rounded p-2 border-l-2';
								item.style.borderColor = r.verdict === 'approved' ? 'rgb(34 197 94 / 0.5)' : 'rgb(239 68 68 / 0.5)';
								var header = document.createElement('div');
								header.className = 'flex items-center gap-2';
								var badge = document.createElement('span');
								badge.className = 'badge badge-sm ' + (r.verdict === 'approved' ? 'badge-success' : 'badge-error');
								badge.textContent = r.verdict;
								var title = document.createElement('span');
								title.className = 'text-sm font-medium text-zinc-200';
								title.textContent = r.title;
								header.appendChild(badge);
								header.appendChild(title);
								var reasoning = document.createElement('p');
								reasoning.className = 'text-xs text-zinc-400 mt-1';
								reasoning.textContent = r.reasoning;
								item.appendChild(header);
								item.appendChild(reasoning);
								container.appendChild(item);
							});
							outputEl.appendChild(container);
						}
					} catch(e) {
						// Leave as text
					}
				}

				function startStream() {
					if (runBtn) {
						runBtn.disabled = true;
						runBtn.textContent = 'Running...';
					}

					var currentCard = null;
					var source = new EventSource('/projects/' + projectID + '/topics/' + runID + '/stream');

					source.onmessage = function(event) {
						var d = JSON.parse(event.data);

						if (d.type === 'step_start') {
							currentCard = createStepCard(d.step_id, d.round, d.step_type);
						} else if (d.type === 'thinking' && currentCard) {
							var ticker = currentCard.querySelector('.step-thinking-ticker');
							if (ticker) {
								ticker.textContent += d.chunk;
								ticker.scrollTop = ticker.scrollHeight;
							}
						} else if (d.type === 'chunk' && currentCard) {
							var streamEl = currentCard.querySelector('.step-stream');
							if (streamEl) {
								streamEl.textContent += d.chunk;
								streamEl.scrollTop = streamEl.scrollHeight;
							}
						} else if (d.type === 'tool_start' && currentCard) {
							if (d.tool === 'web_search' && d.query) {
								addToolPill(currentCard, 'search', d.query);
							} else if (d.tool === 'fetch_url' && d.url) {
								addToolPill(currentCard, 'fetch', d.url);
							}
						} else if (d.type === 'step_done' && currentCard) {
							var ticker = currentCard.querySelector('.step-thinking-ticker');
							if (ticker) ticker.classList.add('done');
							var badgeText = d.status === 'completed' ? 'completed' : 'failed';
							var badgeClass = d.status === 'completed' ? 'badge-completed' : 'badge-failed';
							setBadge(currentCard, badgeText, badgeClass);
							currentCard.dataset.status = d.status;
							currentCard = null;
						} else if (d.type === 'done') {
							source.close();
							window.location.reload();
						} else if (d.type === 'error') {
							source.close();
							if (currentCard) {
								var streamEl = currentCard.querySelector('.step-stream');
								if (streamEl) streamEl.textContent += '\nError: ' + d.error;
								setBadge(currentCard, 'failed', 'badge-failed');
							}
						}
					};

					source.onerror = function() {
						source.close();
					};
				}

				// Auto-start if run is in running state with no steps yet
				if (status === 'running') {
					var existingSteps = stepsContainer.querySelectorAll('.step-card');
					if (existingSteps.length === 0) {
						startStream();
					}
				}

				if (runBtn) {
					runBtn.addEventListener('click', startStream);
				}

				// Render tool pills from data attributes on page load
				document.querySelectorAll('.step-card[data-tool-calls]').forEach(function(card) {
					var raw = card.dataset.toolCalls;
					if (!raw) return;
					try {
						var calls = JSON.parse(raw);
						calls.forEach(function(tc) {
							addToolPill(card, tc.type, tc.value);
						});
					} catch(e) {}
				});

				// Render step outputs for completed steps
				document.querySelectorAll('.step-card[data-status="completed"]').forEach(function(card) {
					renderStepOutput(card);
				});
			})();
		</script>
	}
}
```

- [ ] **Step 2: Generate templ**

Run: `templ generate`
Expected: Generates code for topic.templ with 0 errors.

- [ ] **Step 3: Verify build**

Run: `go build ./...`
Expected: Clean build.

- [ ] **Step 4: Commit**

```bash
git add web/templates/topic.templ web/templates/topic_templ.go
git commit -m "feat(topics): add topic list and run detail page templates"
```

---

### Task 7: Sidebar Nav + Route Registration

**Files:**
- Modify: `web/templates/components/layout.templ`
- Modify: `cmd/server/main.go`

- [ ] **Step 1: Add Topics nav link to sidebar**

In `web/templates/components/layout.templ`, add after the Pipeline nav link (after the `</a>` that closes the pipeline link, before the Context & Memory link):

```templ
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/topics", projectID)) } class={ sidebarLinkClass(activePage, "topics") }>
						<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18v-5.25m0 0a6.01 6.01 0 001.5-.189m-1.5.189a6.01 6.01 0 01-1.5-.189m3.75 7.478a12.06 12.06 0 01-4.5 0m3.75 2.383a14.406 14.406 0 01-3 0M14.25 18v-.192c0-.983.658-1.823 1.508-2.316a7.5 7.5 0 10-7.517 0c.85.493 1.509 1.333 1.509 2.316V18"></path></svg>
						Topics
					</a>
```

- [ ] **Step 2: Register TopicHandler in main.go**

In `cmd/server/main.go`, restore the ideation model (add back after `copywritingModel`):

```go
	ideationModel := func() string {
		if v, err := queries.GetSetting("model_ideation"); err == nil && v != "" {
			return v
		}
		return cfg.ModelIdeation
	}
```

Add the handler initialization (after the existing handler declarations, before `mux := http.NewServeMux()`):

```go
	topicHandler := handlers.NewTopicHandler(queries, aiClient, braveClient, toolRegistry, promptBuilder, ideationModel)
```

Add the route case in the `switch` block (after the pipeline case, before the profile case):

```go
		case strings.HasPrefix(rest, "topics"):
			topicHandler.Handle(w, r, projectID, rest)
```

- [ ] **Step 3: Regenerate templ and verify build**

Run: `templ generate && go build ./...`
Expected: Clean build.

- [ ] **Step 4: Commit**

```bash
git add web/templates/components/layout.templ web/templates/components/layout_templ.go cmd/server/main.go
git commit -m "feat(topics): add sidebar nav link and register topic handler routes"
```

---

### Task 8: Project Settings — Blog URL and Homepage URL

**Files:**
- Modify: `web/templates/project_settings.templ`
- Modify: `web/handlers/project_settings.go`

- [ ] **Step 1: Add fields to the settings template**

In `web/templates/project_settings.templ`, add `BlogURL` and `HomepageURL` fields to the `ProjectSettingsData` struct:

```go
type ProjectSettingsData struct {
	ProjectID   int64
	ProjectName string
	Language    string
	BlogURL     string
	HomepageURL string
	Saved       bool
}
```

Add a new card inside the form, after the Language card's closing `</div>`:

```templ
			<div class="mb-4">
				@components.Card("URLs") {
					@components.FormGroup("Blog URL") {
						<input type="text" name="blog_url" value={ data.BlogURL } placeholder="https://example.com/blog" class="input"/>
						<p class="text-zinc-500 text-xs mt-1">The topic generator fetches this to see recent posts and avoid duplicates.</p>
					}
					@components.FormGroup("Homepage URL") {
						<input type="text" name="homepage_url" value={ data.HomepageURL } placeholder="https://example.com" class="input"/>
						<p class="text-zinc-500 text-xs mt-1">Fetched for brand context during topic generation.</p>
					}
				}
			</div>
```

- [ ] **Step 2: Update the handler to load and save the new fields**

In `web/handlers/project_settings.go`, update the `show` method to load the new fields:

Add after `language` is loaded:
```go
	blogURL, _ := h.queries.GetProjectSetting(projectID, "blog_url")
	homepageURL, _ := h.queries.GetProjectSetting(projectID, "homepage_url")
```

And pass them to the template data:
```go
	BlogURL:     blogURL,
	HomepageURL: homepageURL,
```

In the `save` method, add after the existing `SetProjectSetting` call:
```go
	h.queries.SetProjectSetting(projectID, "blog_url", r.FormValue("blog_url"))
	h.queries.SetProjectSetting(projectID, "homepage_url", r.FormValue("homepage_url"))
```

- [ ] **Step 3: Regenerate templ and verify build**

Run: `templ generate && go build ./...`
Expected: Clean build.

- [ ] **Step 4: Commit**

```bash
git add web/templates/project_settings.templ web/templates/project_settings_templ.go web/handlers/project_settings.go
git commit -m "feat(topics): add blog URL and homepage URL to project settings"
```

---

### Task 9: Smoke Test — End to End

- [ ] **Step 1: Start the server**

Run: `make restart`
Expected: Server starts on :8080.

- [ ] **Step 2: Verify navigation**

Open a project page in the browser. Verify:
- "Topics" appears in the sidebar between Pipeline and Context & Memory
- Chat bubble is gone from bottom-right
- Clicking "Topics" navigates to `/projects/{id}/topics`
- The topics page shows "Generate" button, empty backlog, empty runs list

- [ ] **Step 3: Verify project settings**

Navigate to project settings. Verify:
- Blog URL and Homepage URL fields are present
- Saving persists the values

- [ ] **Step 4: Test topic generation**

Click "Generate" on the topics page. Verify:
- Redirects to the run detail page
- SSE stream starts automatically
- Explorer step card appears with tool pills (search, fetch)
- Reviewer step card appears after explorer completes
- Approved topics appear at the top when done
- Backlog items appear on the topics list page

- [ ] **Step 5: Test backlog actions**

On the topics list page, verify:
- "Start Pipeline" creates a pipeline run and redirects
- "Delete" removes the topic card

- [ ] **Step 6: Commit any fixes**

If any fixes were needed, commit them:
```bash
git add -A
git commit -m "fix(topics): address smoke test issues"
```
