# Content Pipeline Production Board Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the stage-based pipeline wizard with a production board where AI generates a plan, then cornerstone + waterfall pieces sequentially with approve/reject/improve on each.

**Architecture:** Rewrite pipeline_runs and content_pieces schema. Delete the state machine package. New pipeline handler with plan generation (JSON), piece generation (SSE via StreamWithTools), approve/reject/improve endpoints. Production board template. JS for streaming into cards and mini chat expansion.

**Tech Stack:** Go, templ, vanilla JS (SSE), SQLite, OpenRouter (StreamWithTools)

---

## File Map

```
Delete:
  internal/pipeline/pipeline.go           — state machine (replaced by simpler status updates)
  internal/pipeline/pipeline_test.go

Rewrite:
  migrations/001_initial.sql              — new pipeline_runs + content_pieces schema
  internal/store/pipeline.go              — new methods for topic, plan, status
  internal/store/content.go               — new fields, new methods, ORDER BY sort_order
  web/handlers/pipeline.go                — completely new: plan/generate/approve/reject/improve
  web/templates/pipeline.templ            — production board layout
  cmd/server/main.go                      — remove pipeline state machine adapter

Modify:
  web/static/app.js                       — pipeline card streaming + mini chat
  web/static/style.css                    — production board styles
  internal/store/brainstorm.go            — add content_piece_id to brainstorm_chats
```

---

## Chunk 1: Schema + Store

### Task 1: Migration + pipeline store rewrite

**Files:**
- Modify: `migrations/001_initial.sql`
- Rewrite: `internal/store/pipeline.go`
- Rewrite: `internal/store/pipeline_test.go`
- Delete: `internal/pipeline/pipeline.go`, `internal/pipeline/pipeline_test.go`

- [ ] **Step 1: Update migrations/001_initial.sql**

Replace `pipeline_runs` table:
```sql
CREATE TABLE pipeline_runs (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    topic TEXT NOT NULL,
    plan TEXT,
    status TEXT NOT NULL DEFAULT 'planning'
        CHECK(status IN ('planning','producing','complete','abandoned')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

Replace `content_pieces` table:
```sql
CREATE TABLE content_pieces (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    platform TEXT NOT NULL DEFAULT '',
    format TEXT NOT NULL DEFAULT '',
    title TEXT,
    body TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','generating','draft','approved','rejected')),
    parent_id INTEGER REFERENCES content_pieces(id),
    sort_order INTEGER NOT NULL DEFAULT 0,
    rejection_reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

Add `content_piece_id` to `brainstorm_chats`:
```sql
CREATE TABLE brainstorm_chats (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title TEXT,
    section TEXT,
    content_piece_id INTEGER REFERENCES content_pieces(id),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

Drop the old `type` column from content_pieces (no longer needed — `platform` + `format` replace it).

- [ ] **Step 2: Delete old pipeline state machine**

```bash
rm internal/pipeline/pipeline.go internal/pipeline/pipeline_test.go
rmdir internal/pipeline
```

- [ ] **Step 3: Rewrite internal/store/pipeline.go**

```go
package store

import "time"

type PipelineRun struct {
	ID        int64
	ProjectID int64
	Topic     string
	Plan      string
	Status    string
	CreatedAt time.Time
	UpdatedAt time.Time
}

func (q *Queries) CreatePipelineRun(projectID int64, topic string) (*PipelineRun, error) {
	res, err := q.db.Exec("INSERT INTO pipeline_runs (project_id, topic) VALUES (?, ?)", projectID, topic)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetPipelineRun(id)
}

func (q *Queries) GetPipelineRun(id int64) (*PipelineRun, error) {
	r := &PipelineRun{}
	err := q.db.QueryRow(
		"SELECT id, project_id, topic, COALESCE(plan,''), status, created_at, updated_at FROM pipeline_runs WHERE id = ?", id,
	).Scan(&r.ID, &r.ProjectID, &r.Topic, &r.Plan, &r.Status, &r.CreatedAt, &r.UpdatedAt)
	return r, err
}

func (q *Queries) UpdatePipelinePlan(id int64, plan string) error {
	_, err := q.db.Exec("UPDATE pipeline_runs SET plan = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", plan, id)
	return err
}

func (q *Queries) UpdatePipelineStatus(id int64, status string) error {
	_, err := q.db.Exec("UPDATE pipeline_runs SET status = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?", status, id)
	return err
}

func (q *Queries) ListPipelineRuns(projectID int64) ([]PipelineRun, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, topic, COALESCE(plan,''), status, created_at, updated_at FROM pipeline_runs WHERE project_id = ? ORDER BY created_at DESC", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var runs []PipelineRun
	for rows.Next() {
		var r PipelineRun
		if err := rows.Scan(&r.ID, &r.ProjectID, &r.Topic, &r.Plan, &r.Status, &r.CreatedAt, &r.UpdatedAt); err != nil {
			return nil, err
		}
		runs = append(runs, r)
	}
	return runs, rows.Err()
}
```

- [ ] **Step 4: Rewrite internal/store/pipeline_test.go**

```go
package store

import "testing"

func TestPipelineRunCRUD(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	run, err := q.CreatePipelineRun(p.ID, "5 pricing mistakes")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if run.Topic != "5 pricing mistakes" {
		t.Errorf("expected topic, got %s", run.Topic)
	}
	if run.Status != "planning" {
		t.Errorf("expected planning, got %s", run.Status)
	}

	q.UpdatePipelinePlan(run.ID, `{"cornerstone":{"platform":"blog"}}`)
	q.UpdatePipelineStatus(run.ID, "producing")

	got, _ := q.GetPipelineRun(run.ID)
	if got.Status != "producing" {
		t.Errorf("expected producing, got %s", got.Status)
	}
	if got.Plan == "" {
		t.Error("expected plan to be set")
	}
}
```

- [ ] **Step 5: Run tests, fix compile errors**

```bash
rm -f marketminded.db
go test ./internal/store/ -v
```

Fix any references to the deleted `pipeline` package in other files (main.go imports it).

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor: rewrite pipeline schema, delete state machine, new pipeline store"
```

---

### Task 2: Content store rewrite

**Files:**
- Rewrite: `internal/store/content.go`
- Rewrite: `internal/store/content_test.go`
- Modify: `internal/store/brainstorm.go` (add content_piece_id)

- [ ] **Step 1: Rewrite internal/store/content.go**

```go
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
```

- [ ] **Step 2: Rewrite internal/store/content_test.go**

```go
package store

import "testing"

func TestContentPieceCRUD(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	piece, err := q.CreateContentPiece(p.ID, run.ID, "blog", "post", "My Blog Post", 0, nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if piece.Status != "pending" {
		t.Errorf("expected pending, got %s", piece.Status)
	}
	if piece.Platform != "blog" {
		t.Errorf("expected blog, got %s", piece.Platform)
	}
}

func TestTrySetGenerating(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")
	piece, _ := q.CreateContentPiece(p.ID, run.ID, "blog", "post", "", 0, nil)

	ok, _ := q.TrySetGenerating(piece.ID)
	if !ok {
		t.Error("expected first TrySetGenerating to succeed")
	}

	ok2, _ := q.TrySetGenerating(piece.ID)
	if ok2 {
		t.Error("expected second TrySetGenerating to fail (already generating)")
	}
}

func TestListContentByPipelineRunOrder(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	q.CreateContentPiece(p.ID, run.ID, "blog", "post", "Cornerstone", 0, nil)
	q.CreateContentPiece(p.ID, run.ID, "linkedin", "post", "LinkedIn", 2, nil)
	q.CreateContentPiece(p.ID, run.ID, "instagram", "post", "Insta", 1, nil)

	pieces, _ := q.ListContentByPipelineRun(run.ID)
	if len(pieces) != 3 {
		t.Fatalf("expected 3, got %d", len(pieces))
	}
	if pieces[0].Title != "Cornerstone" {
		t.Errorf("expected Cornerstone first, got %s", pieces[0].Title)
	}
	if pieces[1].Title != "Insta" {
		t.Errorf("expected Insta second, got %s", pieces[1].Title)
	}
}

func TestAllPiecesApproved(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	piece, _ := q.CreateContentPiece(p.ID, run.ID, "blog", "post", "", 0, nil)

	done, _ := q.AllPiecesApproved(run.ID)
	if done {
		t.Error("should not be done with pending pieces")
	}

	q.SetContentPieceStatus(piece.ID, "approved")
	done, _ = q.AllPiecesApproved(run.ID)
	if !done {
		t.Error("should be done when all approved")
	}
}
```

- [ ] **Step 3: Update brainstorm.go — add content_piece_id to BrainstormChat**

Add `ContentPieceID *int64` to the struct. Update `CreateBrainstormChat` to accept it. Update all queries to include the column.

```go
type BrainstormChat struct {
	ID             int64
	ProjectID      int64
	Title          string
	Section        string
	ContentPieceID *int64
	CreatedAt      time.Time
}
```

Update `CreateBrainstormChat`:
```go
func (q *Queries) CreateBrainstormChat(projectID int64, title, section string, contentPieceID *int64) (*BrainstormChat, error) {
```

Update all callers (brainstorm handler `createChat`, `GetOrCreateProfileChat`, and brainstorm handler `pushToPipeline` if it creates chats).

- [ ] **Step 4: Run all tests**

```bash
rm -f marketminded.db
go test ./internal/store/ -v
```

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: rewrite content store with platform/format/sort_order, add TrySetGenerating"
```

---

## Chunk 2: Pipeline Handler

### Task 3: Pipeline handler rewrite

**Files:**
- Rewrite: `web/handlers/pipeline.go`
- Modify: `cmd/server/main.go`

This is the largest task. The handler needs to handle: list runs, create run, show board, stream plan, approve/reject plan, stream piece, approve/reject piece, improve piece, abandon.

- [ ] **Step 1: Rewrite web/handlers/pipeline.go**

Key structure:

```go
type PipelineHandler struct {
	queries     *store.Queries
	aiClient    *ai.Client
	braveClient *search.BraveClient
	model       func() string
}

func NewPipelineHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, model func() string) *PipelineHandler
```

Route handling — parse the URL path to dispatch:
- `pipeline` GET → list
- `pipeline` POST → create
- `pipeline/{runId}` GET → show board
- `pipeline/{runId}/stream/plan` GET → SSE plan generation
- `pipeline/{runId}/approve-plan` POST → parse plan JSON, create pieces
- `pipeline/{runId}/reject-plan` POST → re-plan
- `pipeline/{runId}/stream/piece/{pieceId}` GET → SSE piece generation
- `pipeline/{runId}/piece/{pieceId}/approve` POST → approve, check completion
- `pipeline/{runId}/piece/{pieceId}/reject` POST → reject with reason
- `pipeline/{runId}/piece/{pieceId}/improve` POST → save improvement message
- `pipeline/{runId}/piece/{pieceId}/improve/stream` GET → SSE improvement rewrite
- `pipeline/{runId}/abandon` POST → abandon

**Plan generation** (`streamPlan`):
- Build system prompt from spec (plan generation prompt + JSON format requirement)
- Stream via `StreamWithTools` (tools available: fetch, search)
- On complete: save full response to `pipeline_runs.plan` via `UpdatePipelinePlan`
- Send `{"type":"done"}`
- Temperature: 0.3

**Approve plan** (`approvePlan`):
- Parse the JSON from `pipeline_runs.plan`
- Create cornerstone `content_pieces` row (sort_order 0)
- Create waterfall `content_pieces` rows (expanding count, incrementing sort_order)
- Set `parent_id` on waterfall pieces to cornerstone ID
- Update run status to `producing`
- Redirect to board

**Piece generation** (`streamPiece`):
- Call `TrySetGenerating` — return error if false
- Determine if cornerstone (parent_id nil) or waterfall
- Build appropriate prompt (cornerstone vs waterfall with platform guidance)
- Inject rejection_reason if piece was previously rejected
- Stream via `StreamWithTools`
- On complete: save body, set status to `draft`
- Send `{"type":"done"}`

**Approve piece** (`approvePiece`):
- Set status to `approved`
- Check `AllPiecesApproved` — if true, set run status to `complete`
- Return JSON with `next_piece_id` (from `NextPendingPiece`) so frontend knows what to auto-generate next

**Reject piece** (`rejectPiece`):
- Save rejection reason via `SetContentPieceRejection`
- Return 200

**Improve** (`saveImproveMessage` + `streamImprove`):
- POST saves user message to a brainstorm chat scoped to the piece (create if doesn't exist)
- GET streams AI rewrite using improvement prompt from spec
- On complete: save new body to content_piece, reset status to `draft`

The platform-specific guidance map should be a Go map in the handler file:

```go
var platformGuidance = map[string]map[string]string{
	"linkedin": {"post": "Professional but personal. Hook in first line. ..."},
	"instagram": {"post": "Visual-first caption. Hook in first line. ...", "reel": "Script for 30-60 second video. ..."},
	"x": {"post": "Single tweet, under 280 chars. ...", "thread": "5-8 tweets. First is the hook. ..."},
	"blog": {"post": "Long-form markdown. 1200-2000 words. ..."},
	"youtube": {"script": "Video script with timestamps. ...", "short": "Under 60 seconds. ..."},
	"facebook": {"post": "Conversational. Hook first line. ..."},
}
```

- [ ] **Step 2: Update cmd/server/main.go**

Remove: `pipeline` package import, `pipelineStoreAdapter`, `pip` variable, old `NewPipelineHandler` call.

New:
```go
pipelineHandler := handlers.NewPipelineHandler(queries, aiClient, braveClient, contentModel)
```

Also remove `ideaAgent` and `contentAgent` if they're no longer used anywhere else. Check if brainstorm or other handlers still need them — if not, remove.

Actually: `ideaAgent` and `contentAgent` were used in the OLD pipeline handler only. The new handler uses `StreamWithTools` directly. Remove them from main.go if nothing else uses them.

Check: `agents` package — `IdeaAgent` and `ContentAgent` may still be useful for the brainstorm handler? No — brainstorm handler uses `StreamWithTools` directly too. So the `agents` package functions are currently unused. Leave them for now (they're still tested) but remove from main.go wiring.

- [ ] **Step 3: Build to verify**

```bash
go build ./...
```

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: rewrite pipeline handler with production board flow"
```

---

## Chunk 3: Template + Frontend

### Task 4: Pipeline templates

**Files:**
- Rewrite: `web/templates/pipeline.templ`

Two templates: pipeline list page and pipeline run (production board) page.

**Pipeline list**: simple table/card list of runs with topic, status badge, date. Form with topic input + Start button.

**Production board**:
- Header with topic, status badge, abandon button
- Plan card (if plan exists): shows parsed plan as human-readable list, Approve/Reject buttons (if status is `planning`)
- Content cards in sort_order: each shows platform/format badge, title, body preview (expandable), status badge, action buttons based on status
- "Generate" button on pending cards (only shown for the next piece in sequence)
- Improve expansion area below each card

Template types needed:
```go
type PipelineListData struct {
    ProjectID   int64
    ProjectName string
    Runs        []PipelineRunView
}

type PipelineRunView struct {
    ID     int64
    Topic  string
    Status string
}

type ProductionBoardData struct {
    ProjectID   int64
    ProjectName string
    RunID       int64
    Topic       string
    Plan        string
    Status      string
    Pieces      []ContentPieceView
    NextPieceID int64
}

type ContentPieceView struct {
    ID              int64
    Platform        string
    Format          string
    Title           string
    Body            string
    Status          string
    SortOrder       int
    RejectionReason string
    IsCornerstone   bool
}
```

- [ ] **Step 1: Write the template**
- [ ] **Step 2: Generate templ**

```bash
templ generate ./web/templates/
```

- [ ] **Step 3: Build**

```bash
go build ./...
```

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: add production board template with content cards"
```

---

### Task 5: Frontend JS + CSS

**Files:**
- Modify: `web/static/app.js`
- Modify: `web/static/style.css`

- [ ] **Step 1: Add production board CSS**

```css
/* Production board */
.board-header { margin-bottom: 1.5rem; }
.board-card { border: 1px solid #e5e5e5; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
.board-card-cornerstone { border-left: 4px solid #3b82f6; }
.board-card-pending { opacity: 0.5; border-style: dashed; }
.board-card-generating { border-color: #f59e0b; animation: pulse 2s infinite; }
.board-card-draft { border-color: #3b82f6; }
.board-card-approved { border-color: #059669; }
.board-card-rejected { border-color: #dc2626; }
@keyframes pulse { 0%,100% { opacity: 1; } 50% { opacity: 0.7; } }
.board-card-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 0.5rem; }
.board-card-body { white-space: pre-wrap; font-size: 0.85rem; max-height: 300px; overflow-y: auto; margin-bottom: 0.75rem; }
.board-card-body.collapsed { max-height: 100px; overflow: hidden; }
.board-card-actions { display: flex; gap: 0.5rem; flex-wrap: wrap; }
.board-plan-list { font-size: 0.9rem; line-height: 1.6; }
.improve-chat { background: #f9fafb; border-radius: 8px; padding: 0.75rem; margin-top: 0.75rem; }
.improve-chat-messages { max-height: 200px; overflow-y: auto; margin-bottom: 0.5rem; }
```

- [ ] **Step 2: Add pipeline board JS to app.js**

Add `initProductionBoard(projectID, runID)` function:

- Auto-init via `DOMContentLoaded` checking for `#production-board` element
- For plan streaming: connect to `stream/plan`, accumulate content, display in plan card
- For piece streaming: connect to `stream/piece/{id}`, stream into the specific card's body
- On approve piece: POST, get response with `next_piece_id`, auto-connect to stream that piece
- On reject piece: POST with reason from a prompt/input, show re-generate button
- Improve button: toggles the improve chat area, POST message, GET stream, on complete replace card body
- Expand/collapse card body on click

- [ ] **Step 3: Commit**

```bash
git add web/static/
git commit -m "feat: add production board CSS and JS with card streaming"
```

---

## Chunk 4: Integration

### Task 6: Wire up and test

- [ ] **Step 1: go mod tidy**

```bash
go mod tidy
```

- [ ] **Step 2: Run all tests**

```bash
rm -f marketminded.db
go test ./... -v
```

- [ ] **Step 3: Generate templ, build, manual smoke test**

```bash
templ generate ./web/templates/
go build -o server ./cmd/server/
OPENROUTER_API_KEY=... BRAVE_API_KEY=... ./server
```

Test flow:
1. Create project, fill profile
2. Go to pipeline, enter topic, click Start
3. Plan generates, approve it
4. Cornerstone generates, approve
5. Waterfall pieces generate one by one
6. Try reject on one, verify re-generation with reason
7. Try improve on one, verify mini chat works
8. All approved → run shows complete

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "feat: complete content pipeline production board"
```
