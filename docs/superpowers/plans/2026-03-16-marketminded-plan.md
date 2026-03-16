# MarketMinded Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a Content Waterfall Engine that takes client context and produces a blog post + social posts through a staged AI pipeline.

**Architecture:** Single Go binary, templ + Alpine.js frontend, SQLite storage. Agents are Go functions calling OpenRouter. Pipeline is a state machine with user-gated transitions. SSE for streaming agent output.

**Tech Stack:** Go, templ, Alpine.js, SQLite (modernc.org/sqlite), OpenRouter API, Brave Search API, rod (HTML-to-PNG), goose (migrations)

---

## File Map

```
cmd/server/main.go                    — entry point, config loading, server setup
internal/config/config.go             — env var parsing, Config struct
internal/types/types.go               — shared types: Message, StreamFunc, SearchResult (used by ai, agents, search)
internal/store/db.go                  — DB connection, migration runner
internal/store/projects.go            — project CRUD queries
internal/store/knowledge.go           — knowledge item queries
internal/store/templates.go           — template queries
internal/store/pipeline.go            — pipeline run queries
internal/store/content.go             — content piece queries
internal/store/agent_runs.go          — agent run queries
internal/store/brainstorm.go          — brainstorm chat + message queries
internal/ai/client.go                 — OpenRouter HTTP client (streaming + sync)
internal/search/brave.go              — Brave Search API client
internal/agents/voice.go              — voice profile builder agent
internal/agents/tone.go               — tone profile builder agent
internal/agents/idea.go               — ideation agent (uses Brave)
internal/agents/content.go            — pillar + waterfall content agent
internal/project/service.go           — project service layer (combines store + agent calls)
internal/pipeline/pipeline.go         — state machine orchestration
internal/render/png.go                — HTML template → PNG via rod
web/handlers/dashboard.go             — dashboard routes
web/handlers/project.go               — project overview + CRUD routes
web/handlers/knowledge.go             — knowledge manager routes
web/handlers/pipeline.go              — pipeline run routes + SSE
web/handlers/content.go               — content piece routes
web/handlers/templates.go             — template manager routes
web/handlers/brainstorm.go            — brainstorm chat routes + SSE
web/templates/layout.templ            — base layout
web/templates/dashboard.templ         — project list
web/templates/project.templ           — project overview
web/templates/knowledge.templ         — knowledge manager
web/templates/pipeline.templ          — pipeline run wizard
web/templates/content.templ           — content piece editor
web/templates/templates_mgr.templ     — template manager
web/templates/brainstorm.templ        — brainstorm chat
web/static/app.js                     — Alpine.js components
web/static/style.css                  — styles
migrations/001_initial.sql            — full schema
```

---

## Chunk 1: Foundation (Go module, DB, Config, Store)

### Task 1: Initialize Go module and dependencies

**Files:**
- Create: `go.mod`
- Create: `cmd/server/main.go`

- [ ] **Step 1: Init Go module**

```bash
cd /Users/zanfridau/CODE/AI/marketminded
go mod init github.com/zanfridau/marketminded
```

- [ ] **Step 2: Add core dependencies**

```bash
go get modernc.org/sqlite
go get github.com/a-h/templ
go get github.com/pressly/goose/v3
```

- [ ] **Step 3: Create minimal main.go**

```go
// cmd/server/main.go
package main

import (
	"fmt"
	"log"
	"net/http"
	"os"
)

func main() {
	port := os.Getenv("MARKETMINDED_PORT")
	if port == "" {
		port = "8080"
	}

	mux := http.NewServeMux()
	mux.HandleFunc("/", func(w http.ResponseWriter, r *http.Request) {
		fmt.Fprintf(w, "MarketMinded running")
	})

	log.Printf("Starting on :%s", port)
	log.Fatal(http.ListenAndServe(":"+port, mux))
}
```

- [ ] **Step 4: Verify it compiles and runs**

```bash
go run cmd/server/main.go &
curl http://localhost:8080
# Expected: "MarketMinded running"
kill %1
```

- [ ] **Step 5: Commit**

```bash
git add go.mod go.sum cmd/
git commit -m "feat: init Go module with minimal server"
```

---

### Task 2: Config loading

**Files:**
- Create: `internal/config/config.go`
- Create: `internal/config/config_test.go`
- Modify: `cmd/server/main.go`

- [ ] **Step 1: Write failing test**

```go
// internal/config/config_test.go
package config

import (
	"os"
	"testing"
)

func TestLoad_Defaults(t *testing.T) {
	os.Setenv("OPENROUTER_API_KEY", "test-key")
	os.Setenv("BRAVE_API_KEY", "brave-key")
	defer os.Unsetenv("OPENROUTER_API_KEY")
	defer os.Unsetenv("BRAVE_API_KEY")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if cfg.Port != "8080" {
		t.Errorf("expected port 8080, got %s", cfg.Port)
	}
	if cfg.DBPath != "./marketminded.db" {
		t.Errorf("expected default db path, got %s", cfg.DBPath)
	}
	if cfg.OpenRouterAPIKey != "test-key" {
		t.Errorf("expected test-key, got %s", cfg.OpenRouterAPIKey)
	}
}

func TestLoad_MissingRequiredKeys(t *testing.T) {
	os.Unsetenv("OPENROUTER_API_KEY")
	os.Unsetenv("BRAVE_API_KEY")

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for missing API keys")
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
go test ./internal/config/ -v
# Expected: FAIL — package doesn't exist yet
```

- [ ] **Step 3: Implement config**

```go
// internal/config/config.go
package config

import (
	"fmt"
	"os"
)

type Config struct {
	Port             string
	DBPath           string
	OpenRouterAPIKey string
	BraveAPIKey      string
	ModelContent     string
	ModelIdeation    string
}

func Load() (*Config, error) {
	orKey := os.Getenv("OPENROUTER_API_KEY")
	braveKey := os.Getenv("BRAVE_API_KEY")

	if orKey == "" || braveKey == "" {
		return nil, fmt.Errorf("OPENROUTER_API_KEY and BRAVE_API_KEY are required")
	}

	return &Config{
		Port:             envOr("MARKETMINDED_PORT", "8080"),
		DBPath:           envOr("MARKETMINDED_DB_PATH", "./marketminded.db"),
		OpenRouterAPIKey: orKey,
		BraveAPIKey:      braveKey,
		ModelContent:     envOr("MARKETMINDED_MODEL_CONTENT", "anthropic/claude-sonnet-4-20250514"),
		ModelIdeation:    envOr("MARKETMINDED_MODEL_IDEATION", "anthropic/claude-sonnet-4-20250514"),
	}, nil
}

func envOr(key, fallback string) string {
	if v := os.Getenv(key); v != "" {
		return v
	}
	return fallback
}
```

- [ ] **Step 4: Run tests**

```bash
go test ./internal/config/ -v
# Expected: PASS
```

- [ ] **Step 5: Commit**

```bash
git add internal/config/
git commit -m "feat: add config loading from env vars"
```

---

### Task 3: Shared types package

**Files:**
- Create: `internal/types/types.go`

This package defines types shared across `internal/ai`, `internal/agents`, and `internal/search` to avoid duplication and adapters.

- [ ] **Step 1: Create shared types**

```go
// internal/types/types.go
package types

// Message represents a chat message for LLM calls.
type Message struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

// StreamFunc is called for each chunk during streaming LLM responses.
type StreamFunc func(chunk string) error

// AIClient abstracts the LLM client for agent testability.
type AIClient interface {
	Complete(ctx context.Context, model string, messages []Message) (string, error)
	Stream(ctx context.Context, model string, messages []Message, fn StreamFunc) (string, error)
}

// SearchResult represents a single web search result.
type SearchResult struct {
	Title       string
	URL         string
	Description string
}

// Searcher abstracts web search for agent testability.
type Searcher interface {
	Search(ctx context.Context, query string, count int) ([]SearchResult, error)
}
```

Note: Add `"context"` import. Both `internal/ai.Client` and `internal/search.BraveClient` must satisfy these interfaces using these types. All agents import from `internal/types` rather than defining their own.

- [ ] **Step 2: Commit**

```bash
git add internal/types/
git commit -m "feat: add shared types package (Message, AIClient, Searcher)"
```

---

### Task 4: Database setup and migrations

**Files:**
- Create: `internal/store/db.go`
- Create: `internal/store/db_test.go`
- Create: `migrations/001_initial.sql`

- [ ] **Step 1: Create migration file**

```sql
-- migrations/001_initial.sql
-- +goose Up
CREATE TABLE projects (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT,
    voice_profile TEXT,
    tone_profile TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE knowledge_items (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    type TEXT NOT NULL CHECK(type IN ('voice_sample','tone_guide','brand_doc','reference')),
    title TEXT,
    content TEXT NOT NULL,
    source_url TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE templates (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    platform TEXT NOT NULL CHECK(platform IN ('instagram','facebook','linkedin')),
    html_content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pipeline_runs (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    status TEXT NOT NULL DEFAULT 'ideating'
        CHECK(status IN ('ideating','creating_pillar','waterfalling','complete','abandoned')),
    selected_topic TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE content_pieces (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    pipeline_run_id INTEGER REFERENCES pipeline_runs(id),
    type TEXT NOT NULL CHECK(type IN ('blog','social_instagram','social_facebook','social_linkedin')),
    title TEXT,
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','approved','published')),
    parent_id INTEGER REFERENCES content_pieces(id),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE agent_runs (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    pipeline_run_id INTEGER REFERENCES pipeline_runs(id),
    agent_type TEXT NOT NULL CHECK(agent_type IN ('voice','tone','idea','content')),
    prompt_summary TEXT,
    response TEXT NOT NULL,
    content_piece_id INTEGER REFERENCES content_pieces(id),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE brainstorm_chats (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE brainstorm_messages (
    id INTEGER PRIMARY KEY,
    chat_id INTEGER NOT NULL REFERENCES brainstorm_chats(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK(role IN ('user','assistant')),
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- +goose Down
DROP TABLE brainstorm_messages;
DROP TABLE brainstorm_chats;
DROP TABLE agent_runs;
DROP TABLE content_pieces;
DROP TABLE pipeline_runs;
DROP TABLE templates;
DROP TABLE knowledge_items;
DROP TABLE projects;
```

- [ ] **Step 2: Write failing test for DB open + migrate**

```go
// internal/store/db_test.go
package store

import (
	"testing"
)

func TestOpenAndMigrate(t *testing.T) {
	db, err := Open(":memory:")
	if err != nil {
		t.Fatalf("failed to open: %v", err)
	}
	defer db.Close()

	// Verify projects table exists
	var count int
	err = db.QueryRow("SELECT count(*) FROM projects").Scan(&count)
	if err != nil {
		t.Fatalf("projects table not created: %v", err)
	}
}
```

- [ ] **Step 3: Run test to verify it fails**

```bash
go test ./internal/store/ -v
# Expected: FAIL
```

- [ ] **Step 4: Implement db.go**

Go's `embed` directive cannot reference paths above the package directory. So we embed the migrations in `cmd/server/main.go` and pass the FS into `Open`.

```go
// internal/store/db.go
package store

import (
	"database/sql"
	"io/fs"

	"github.com/pressly/goose/v3"
	_ "modernc.org/sqlite"
)

func Open(dsn string, migrationsFS fs.FS) (*sql.DB, error) {
	db, err := sql.Open("sqlite", dsn)
	if err != nil {
		return nil, err
	}

	// Enable WAL mode and foreign keys
	if _, err := db.Exec("PRAGMA journal_mode=WAL; PRAGMA foreign_keys=ON;"); err != nil {
		db.Close()
		return nil, err
	}

	goose.SetBaseFS(migrationsFS)
	if err := goose.SetDialect("sqlite3"); err != nil {
		db.Close()
		return nil, err
	}
	if err := goose.Up(db, "."); err != nil {
		db.Close()
		return nil, err
	}

	return db, nil
}
```

In `cmd/server/main.go`, add the embed:

```go
//go:embed ../../migrations
var migrationsFS embed.FS
// Then call: store.Open(cfg.DBPath, migrationsFS)
```

Actually, since `cmd/server/` is also nested, use `os.DirFS` instead for simplicity:

```go
// In main.go:
migrationsDir := os.DirFS("migrations")
db, err := store.Open(cfg.DBPath, migrationsDir)
```

For tests, create the SQL inline or use `os.DirFS` with a relative path. Update the test helper:

```go
func testDB(t *testing.T) *Queries {
	t.Helper()
	db, err := Open(":memory:", os.DirFS("../../migrations"))
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return NewQueries(db)
}
```

- [ ] **Step 5: Run test**

```bash
go test ./internal/store/ -v
# Expected: PASS
```

- [ ] **Step 6: Commit**

```bash
git add internal/store/db.go internal/store/db_test.go migrations/
git commit -m "feat: add SQLite setup with goose migrations"
```

---

### Task 4: Project store (CRUD)

**Files:**
- Create: `internal/store/projects.go`
- Create: `internal/store/projects_test.go`

- [ ] **Step 1: Write failing tests**

```go
// internal/store/projects_test.go
package store

import (
	"testing"
)

func testDB(t *testing.T) *Queries {
	t.Helper()
	db, err := Open(":memory:")
	if err != nil {
		t.Fatalf("open db: %v", err)
	}
	t.Cleanup(func() { db.Close() })
	return NewQueries(db)
}

func TestCreateAndGetProject(t *testing.T) {
	q := testDB(t)

	p, err := q.CreateProject("Test Client", "A test project")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if p.ID == 0 {
		t.Fatal("expected non-zero ID")
	}
	if p.Name != "Test Client" {
		t.Errorf("expected 'Test Client', got %q", p.Name)
	}

	got, err := q.GetProject(p.ID)
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	if got.Name != "Test Client" {
		t.Errorf("expected 'Test Client', got %q", got.Name)
	}
}

func TestListProjects(t *testing.T) {
	q := testDB(t)

	q.CreateProject("A", "first")
	q.CreateProject("B", "second")

	projects, err := q.ListProjects()
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(projects) != 2 {
		t.Errorf("expected 2 projects, got %d", len(projects))
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
go test ./internal/store/ -run TestCreate -v
# Expected: FAIL
```

- [ ] **Step 3: Implement**

```go
// internal/store/projects.go
package store

import (
	"database/sql"
	"time"
)

type Queries struct {
	db *sql.DB
}

func NewQueries(db *sql.DB) *Queries {
	return &Queries{db: db}
}

type Project struct {
	ID           int64
	Name         string
	Description  string
	VoiceProfile *string
	ToneProfile  *string
	CreatedAt    time.Time
	UpdatedAt    time.Time
}

func (q *Queries) CreateProject(name, description string) (*Project, error) {
	res, err := q.db.Exec(
		"INSERT INTO projects (name, description) VALUES (?, ?)",
		name, description,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetProject(id)
}

func (q *Queries) GetProject(id int64) (*Project, error) {
	p := &Project{}
	err := q.db.QueryRow(
		"SELECT id, name, COALESCE(description,''), voice_profile, tone_profile, created_at, updated_at FROM projects WHERE id = ?", id,
	).Scan(&p.ID, &p.Name, &p.Description, &p.VoiceProfile, &p.ToneProfile, &p.CreatedAt, &p.UpdatedAt)
	if err != nil {
		return nil, err
	}
	return p, nil
}

func (q *Queries) ListProjects() ([]Project, error) {
	rows, err := q.db.Query("SELECT id, name, COALESCE(description,''), voice_profile, tone_profile, created_at, updated_at FROM projects ORDER BY created_at DESC")
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var projects []Project
	for rows.Next() {
		var p Project
		if err := rows.Scan(&p.ID, &p.Name, &p.Description, &p.VoiceProfile, &p.ToneProfile, &p.CreatedAt, &p.UpdatedAt); err != nil {
			return nil, err
		}
		projects = append(projects, p)
	}
	return projects, rows.Err()
}

func (q *Queries) UpdateVoiceProfile(id int64, voiceProfile string) error {
	_, err := q.db.Exec(
		"UPDATE projects SET voice_profile = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
		voiceProfile, id,
	)
	return err
}

func (q *Queries) UpdateToneProfile(id int64, toneProfile string) error {
	_, err := q.db.Exec(
		"UPDATE projects SET tone_profile = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?",
		toneProfile, id,
	)
	return err
}
```

- [ ] **Step 4: Run tests**

```bash
go test ./internal/store/ -v
# Expected: PASS
```

- [ ] **Step 5: Commit**

```bash
git add internal/store/projects.go internal/store/projects_test.go
git commit -m "feat: add project CRUD store layer"
```

---

### Task 5: Knowledge items store

**Files:**
- Create: `internal/store/knowledge.go`
- Create: `internal/store/knowledge_test.go`

- [ ] **Step 1: Write failing tests**

```go
// internal/store/knowledge_test.go
package store

import "testing"

func TestCreateAndListKnowledge(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	item, err := q.CreateKnowledgeItem(p.ID, "voice_sample", "Sample 1", "This is how we talk.", "")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if item.ID == 0 {
		t.Fatal("expected non-zero ID")
	}

	items, err := q.ListKnowledgeItems(p.ID, "")
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(items) != 1 {
		t.Errorf("expected 1 item, got %d", len(items))
	}
}

func TestListKnowledgeByType(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.CreateKnowledgeItem(p.ID, "voice_sample", "V1", "voice content", "")
	q.CreateKnowledgeItem(p.ID, "brand_doc", "B1", "brand content", "")

	items, err := q.ListKnowledgeItems(p.ID, "voice_sample")
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(items) != 1 {
		t.Errorf("expected 1, got %d", len(items))
	}
}
```

- [ ] **Step 2: Run to verify fail**

```bash
go test ./internal/store/ -run TestCreateAndListKnowledge -v
# Expected: FAIL
```

- [ ] **Step 3: Implement**

```go
// internal/store/knowledge.go
package store

import "time"

type KnowledgeItem struct {
	ID        int64
	ProjectID int64
	Type      string
	Title     string
	Content   string
	SourceURL string
	CreatedAt time.Time
}

func (q *Queries) CreateKnowledgeItem(projectID int64, itemType, title, content, sourceURL string) (*KnowledgeItem, error) {
	res, err := q.db.Exec(
		"INSERT INTO knowledge_items (project_id, type, title, content, source_url) VALUES (?, ?, ?, ?, ?)",
		projectID, itemType, title, content, sourceURL,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetKnowledgeItem(id)
}

func (q *Queries) GetKnowledgeItem(id int64) (*KnowledgeItem, error) {
	k := &KnowledgeItem{}
	err := q.db.QueryRow(
		"SELECT id, project_id, type, COALESCE(title,''), content, COALESCE(source_url,''), created_at FROM knowledge_items WHERE id = ?", id,
	).Scan(&k.ID, &k.ProjectID, &k.Type, &k.Title, &k.Content, &k.SourceURL, &k.CreatedAt)
	return k, err
}

func (q *Queries) ListKnowledgeItems(projectID int64, itemType string) ([]KnowledgeItem, error) {
	query := "SELECT id, project_id, type, COALESCE(title,''), content, COALESCE(source_url,''), created_at FROM knowledge_items WHERE project_id = ?"
	args := []any{projectID}
	if itemType != "" {
		query += " AND type = ?"
		args = append(args, itemType)
	}
	query += " ORDER BY created_at DESC"

	rows, err := q.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var items []KnowledgeItem
	for rows.Next() {
		var k KnowledgeItem
		if err := rows.Scan(&k.ID, &k.ProjectID, &k.Type, &k.Title, &k.Content, &k.SourceURL, &k.CreatedAt); err != nil {
			return nil, err
		}
		items = append(items, k)
	}
	return items, rows.Err()
}

func (q *Queries) DeleteKnowledgeItem(id int64) error {
	_, err := q.db.Exec("DELETE FROM knowledge_items WHERE id = ?", id)
	return err
}
```

- [ ] **Step 4: Run tests**

```bash
go test ./internal/store/ -v
# Expected: PASS
```

- [ ] **Step 5: Commit**

```bash
git add internal/store/knowledge.go internal/store/knowledge_test.go
git commit -m "feat: add knowledge items store"
```

---

### Task 6: Remaining store layers (pipeline, content, templates, brainstorm)

**Files:**
- Create: `internal/store/pipeline.go`
- Create: `internal/store/pipeline_test.go`
- Create: `internal/store/content.go`
- Create: `internal/store/content_test.go`
- Create: `internal/store/templates.go`
- Create: `internal/store/templates_test.go`
- Create: `internal/store/brainstorm.go`
- Create: `internal/store/brainstorm_test.go`
- Create: `internal/store/agent_runs.go`
- Create: `internal/store/agent_runs_test.go`

- [ ] **Step 1: Write failing test for pipeline store**

```go
// internal/store/pipeline_test.go
package store

import "testing"

func TestPipelineRunLifecycle(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	run, err := q.CreatePipelineRun(p.ID)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if run.Status != "ideating" {
		t.Errorf("expected ideating, got %s", run.Status)
	}

	err = q.AdvancePipelineRun(run.ID, "creating_pillar")
	if err != nil {
		t.Fatalf("advance: %v", err)
	}

	got, _ := q.GetPipelineRun(run.ID)
	if got.Status != "creating_pillar" {
		t.Errorf("expected creating_pillar, got %s", got.Status)
	}
}
```

- [ ] **Step 2: Implement pipeline.go**

```go
// internal/store/pipeline.go
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
```

- [ ] **Step 3: Run pipeline tests**

```bash
go test ./internal/store/ -run TestPipeline -v
# Expected: PASS
```

- [ ] **Step 4: Write content store + test**

```go
// internal/store/content.go
package store

import "time"

type ContentPiece struct {
	ID            int64
	ProjectID     int64
	PipelineRunID *int64
	Type          string
	Title         string
	Body          string
	Status        string
	ParentID      *int64
	CreatedAt     time.Time
}

func (q *Queries) CreateContentPiece(projectID int64, pipelineRunID *int64, contentType, title, body string, parentID *int64) (*ContentPiece, error) {
	res, err := q.db.Exec(
		"INSERT INTO content_pieces (project_id, pipeline_run_id, type, title, body, parent_id) VALUES (?, ?, ?, ?, ?, ?)",
		projectID, pipelineRunID, contentType, title, body, parentID,
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
		"SELECT id, project_id, pipeline_run_id, type, COALESCE(title,''), body, status, parent_id, created_at FROM content_pieces WHERE id = ?", id,
	).Scan(&c.ID, &c.ProjectID, &c.PipelineRunID, &c.Type, &c.Title, &c.Body, &c.Status, &c.ParentID, &c.CreatedAt)
	return c, err
}

func (q *Queries) UpdateContentPiece(id int64, title, body string) error {
	_, err := q.db.Exec("UPDATE content_pieces SET title = ?, body = ? WHERE id = ?", title, body, id)
	return err
}

func (q *Queries) ApproveContentPiece(id int64) error {
	_, err := q.db.Exec("UPDATE content_pieces SET status = 'approved' WHERE id = ?", id)
	return err
}

func (q *Queries) ListContentPieces(projectID int64) ([]ContentPiece, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, pipeline_run_id, type, COALESCE(title,''), body, status, parent_id, created_at FROM content_pieces WHERE project_id = ? ORDER BY created_at DESC", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var pieces []ContentPiece
	for rows.Next() {
		var c ContentPiece
		if err := rows.Scan(&c.ID, &c.ProjectID, &c.PipelineRunID, &c.Type, &c.Title, &c.Body, &c.Status, &c.ParentID, &c.CreatedAt); err != nil {
			return nil, err
		}
		pieces = append(pieces, c)
	}
	return pieces, rows.Err()
}

// ContentLogSummaries returns titles + truncated bodies for prompt injection
func (q *Queries) ContentLogSummaries(projectID int64, limit int) ([]ContentPiece, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, pipeline_run_id, type, COALESCE(title,''), substr(body, 1, 200), status, parent_id, created_at FROM content_pieces WHERE project_id = ? AND status = 'approved' ORDER BY created_at DESC LIMIT ?",
		projectID, limit,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var pieces []ContentPiece
	for rows.Next() {
		var c ContentPiece
		if err := rows.Scan(&c.ID, &c.ProjectID, &c.PipelineRunID, &c.Type, &c.Title, &c.Body, &c.Status, &c.ParentID, &c.CreatedAt); err != nil {
			return nil, err
		}
		pieces = append(pieces, c)
	}
	return pieces, rows.Err()
}
```

```go
// internal/store/content_test.go
package store

import "testing"

func TestContentPieceLifecycle(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	piece, err := q.CreateContentPiece(p.ID, nil, "blog", "My Post", "Post body here", nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if piece.Status != "draft" {
		t.Errorf("expected draft, got %s", piece.Status)
	}

	err = q.ApproveContentPiece(piece.ID)
	if err != nil {
		t.Fatalf("approve: %v", err)
	}

	got, _ := q.GetContentPiece(piece.ID)
	if got.Status != "approved" {
		t.Errorf("expected approved, got %s", got.Status)
	}
}

func TestContentLogSummaries(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.CreateContentPiece(p.ID, nil, "blog", "Draft Post", "not approved", nil)
	piece, _ := q.CreateContentPiece(p.ID, nil, "blog", "Good Post", "approved body", nil)
	q.ApproveContentPiece(piece.ID)

	summaries, err := q.ContentLogSummaries(p.ID, 10)
	if err != nil {
		t.Fatalf("summaries: %v", err)
	}
	if len(summaries) != 1 {
		t.Errorf("expected 1 approved, got %d", len(summaries))
	}
}
```

- [ ] **Step 5: Run content tests**

```bash
go test ./internal/store/ -run TestContent -v
# Expected: PASS
```

- [ ] **Step 6: Write templates store + test**

```go
// internal/store/templates.go
package store

import "time"

type Template struct {
	ID          int64
	ProjectID   int64
	Name        string
	Platform    string
	HTMLContent string
	CreatedAt   time.Time
}

func (q *Queries) CreateTemplate(projectID int64, name, platform, htmlContent string) (*Template, error) {
	res, err := q.db.Exec(
		"INSERT INTO templates (project_id, name, platform, html_content) VALUES (?, ?, ?, ?)",
		projectID, name, platform, htmlContent,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetTemplate(id)
}

func (q *Queries) GetTemplate(id int64) (*Template, error) {
	t := &Template{}
	err := q.db.QueryRow(
		"SELECT id, project_id, name, platform, html_content, created_at FROM templates WHERE id = ?", id,
	).Scan(&t.ID, &t.ProjectID, &t.Name, &t.Platform, &t.HTMLContent, &t.CreatedAt)
	return t, err
}

func (q *Queries) ListTemplates(projectID int64) ([]Template, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, name, platform, html_content, created_at FROM templates WHERE project_id = ? ORDER BY platform, name", projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var templates []Template
	for rows.Next() {
		var t Template
		if err := rows.Scan(&t.ID, &t.ProjectID, &t.Name, &t.Platform, &t.HTMLContent, &t.CreatedAt); err != nil {
			return nil, err
		}
		templates = append(templates, t)
	}
	return templates, rows.Err()
}

func (q *Queries) UpdateTemplate(id int64, name, htmlContent string) error {
	_, err := q.db.Exec("UPDATE templates SET name = ?, html_content = ? WHERE id = ?", name, htmlContent, id)
	return err
}

func (q *Queries) DeleteTemplate(id int64) error {
	_, err := q.db.Exec("DELETE FROM templates WHERE id = ?", id)
	return err
}

func (q *Queries) ListTemplatesByPlatform(projectID int64, platform string) ([]Template, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, name, platform, html_content, created_at FROM templates WHERE project_id = ? AND platform = ?", projectID, platform,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var templates []Template
	for rows.Next() {
		var t Template
		if err := rows.Scan(&t.ID, &t.ProjectID, &t.Name, &t.Platform, &t.HTMLContent, &t.CreatedAt); err != nil {
			return nil, err
		}
		templates = append(templates, t)
	}
	return templates, rows.Err()
}

// ValidateTemplate checks that required Go template slots are present.
func ValidateTemplate(htmlContent string) error {
	required := []string{"{{.Title}}", "{{.Body}}"}
	for _, slot := range required {
		if !strings.Contains(htmlContent, slot) {
			return fmt.Errorf("template missing required slot: %s", slot)
		}
	}
	return nil
}
```

```go
// internal/store/templates_test.go
package store

import "testing"

func TestTemplateCRUD(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	tmpl, err := q.CreateTemplate(p.ID, "Insta Post", "instagram", "<div>{{.Title}}</div>")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if tmpl.Platform != "instagram" {
		t.Errorf("expected instagram, got %s", tmpl.Platform)
	}

	list, _ := q.ListTemplates(p.ID)
	if len(list) != 1 {
		t.Errorf("expected 1, got %d", len(list))
	}
}
```

- [ ] **Step 7: Write brainstorm + agent_runs stores + tests**

```go
// internal/store/brainstorm.go
package store

import "time"

type BrainstormChat struct {
	ID        int64
	ProjectID int64
	Title     string
	CreatedAt time.Time
}

type BrainstormMessage struct {
	ID        int64
	ChatID    int64
	Role      string
	Content   string
	CreatedAt time.Time
}

func (q *Queries) CreateBrainstormChat(projectID int64, title string) (*BrainstormChat, error) {
	res, err := q.db.Exec("INSERT INTO brainstorm_chats (project_id, title) VALUES (?, ?)", projectID, title)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	c := &BrainstormChat{}
	err = q.db.QueryRow("SELECT id, project_id, COALESCE(title,''), created_at FROM brainstorm_chats WHERE id = ?", id).
		Scan(&c.ID, &c.ProjectID, &c.Title, &c.CreatedAt)
	return c, err
}

func (q *Queries) ListBrainstormChats(projectID int64) ([]BrainstormChat, error) {
	rows, err := q.db.Query("SELECT id, project_id, COALESCE(title,''), created_at FROM brainstorm_chats WHERE project_id = ? ORDER BY created_at DESC", projectID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var chats []BrainstormChat
	for rows.Next() {
		var c BrainstormChat
		if err := rows.Scan(&c.ID, &c.ProjectID, &c.Title, &c.CreatedAt); err != nil {
			return nil, err
		}
		chats = append(chats, c)
	}
	return chats, rows.Err()
}

func (q *Queries) AddBrainstormMessage(chatID int64, role, content string) (*BrainstormMessage, error) {
	res, err := q.db.Exec("INSERT INTO brainstorm_messages (chat_id, role, content) VALUES (?, ?, ?)", chatID, role, content)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	m := &BrainstormMessage{}
	err = q.db.QueryRow("SELECT id, chat_id, role, content, created_at FROM brainstorm_messages WHERE id = ?", id).
		Scan(&m.ID, &m.ChatID, &m.Role, &m.Content, &m.CreatedAt)
	return m, err
}

func (q *Queries) ListBrainstormMessages(chatID int64) ([]BrainstormMessage, error) {
	rows, err := q.db.Query("SELECT id, chat_id, role, content, created_at FROM brainstorm_messages WHERE chat_id = ? ORDER BY created_at ASC", chatID)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var msgs []BrainstormMessage
	for rows.Next() {
		var m BrainstormMessage
		if err := rows.Scan(&m.ID, &m.ChatID, &m.Role, &m.Content, &m.CreatedAt); err != nil {
			return nil, err
		}
		msgs = append(msgs, m)
	}
	return msgs, rows.Err()
}
```

```go
// internal/store/brainstorm_test.go
package store

import "testing"

func TestBrainstormChatFlow(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	chat, err := q.CreateBrainstormChat(p.ID, "Ideas for blog")
	if err != nil {
		t.Fatalf("create chat: %v", err)
	}

	_, err = q.AddBrainstormMessage(chat.ID, "user", "What about AI trends?")
	if err != nil {
		t.Fatalf("add message: %v", err)
	}
	_, err = q.AddBrainstormMessage(chat.ID, "assistant", "Great idea! Here are some angles...")
	if err != nil {
		t.Fatalf("add message: %v", err)
	}

	msgs, _ := q.ListBrainstormMessages(chat.ID)
	if len(msgs) != 2 {
		t.Errorf("expected 2 messages, got %d", len(msgs))
	}
	if msgs[0].Role != "user" {
		t.Errorf("expected user first, got %s", msgs[0].Role)
	}
}
```

```go
// internal/store/agent_runs.go
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
```

```go
// internal/store/agent_runs_test.go
package store

import "testing"

func TestAgentRunCreate(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	run, err := q.CreateAgentRun(p.ID, nil, "voice", "Analyze voice samples", "Voice profile: formal, technical", nil)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if run.AgentType != "voice" {
		t.Errorf("expected voice, got %s", run.AgentType)
	}
}
```

- [ ] **Step 8: Run all store tests**

```bash
go test ./internal/store/ -v
# Expected: all PASS
```

- [ ] **Step 9: Commit**

```bash
git add internal/store/
git commit -m "feat: add all store layers (pipeline, content, templates, brainstorm, agent_runs)"
```

---

## Chunk 2: External API Clients (OpenRouter, Brave Search)

### Task 7: OpenRouter client

**Files:**
- Create: `internal/ai/client.go`
- Create: `internal/ai/client_test.go`

- [ ] **Step 1: Write test with mock HTTP server**

```go
// internal/ai/client_test.go
package ai

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestComplete(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("Authorization") != "Bearer test-key" {
			t.Errorf("missing auth header")
		}

		resp := ChatResponse{
			Choices: []Choice{
				{Message: Message{Role: "assistant", Content: "Hello back!"}},
			},
		}
		json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	c := NewClient("test-key", WithBaseURL(server.URL))
	resp, err := c.Complete(context.Background(), "test-model", []Message{
		{Role: "user", Content: "Hello"},
	})
	if err != nil {
		t.Fatalf("complete: %v", err)
	}
	if resp != "Hello back!" {
		t.Errorf("expected 'Hello back!', got %q", resp)
	}
}
```

- [ ] **Step 2: Run to verify fail**

```bash
go test ./internal/ai/ -v
# Expected: FAIL
```

- [ ] **Step 3: Implement**

```go
// internal/ai/client.go
package ai

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
)

const defaultBaseURL = "https://openrouter.ai/api/v1"

type Message struct {
	Role    string `json:"role"`
	Content string `json:"content"`
}

type ChatRequest struct {
	Model    string    `json:"model"`
	Messages []Message `json:"messages"`
	Stream   bool      `json:"stream,omitempty"`
}

type ChatResponse struct {
	Choices []Choice `json:"choices"`
}

type Choice struct {
	Message Message `json:"message"`
	Delta   Message `json:"delta"`
}

type Client struct {
	apiKey  string
	baseURL string
	http    *http.Client
}

type Option func(*Client)

func WithBaseURL(url string) Option {
	return func(c *Client) { c.baseURL = url }
}

func NewClient(apiKey string, opts ...Option) *Client {
	c := &Client{
		apiKey:  apiKey,
		baseURL: defaultBaseURL,
		http:    &http.Client{},
	}
	for _, opt := range opts {
		opt(c)
	}
	return c
}

func (c *Client) Complete(ctx context.Context, model string, messages []Message) (string, error) {
	body, _ := json.Marshal(ChatRequest{
		Model:    model,
		Messages: messages,
	})

	req, err := http.NewRequestWithContext(ctx, "POST", c.baseURL+"/chat/completions", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Authorization", "Bearer "+c.apiKey)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(resp.Body)
		return "", fmt.Errorf("openrouter: %d: %s", resp.StatusCode, string(b))
	}

	var chatResp ChatResponse
	if err := json.NewDecoder(resp.Body).Decode(&chatResp); err != nil {
		return "", err
	}
	if len(chatResp.Choices) == 0 {
		return "", fmt.Errorf("openrouter: no choices returned")
	}
	return chatResp.Choices[0].Message.Content, nil
}

// StreamFunc is called for each chunk of streamed content
type StreamFunc func(chunk string) error

func (c *Client) Stream(ctx context.Context, model string, messages []Message, fn StreamFunc) (string, error) {
	body, _ := json.Marshal(ChatRequest{
		Model:    model,
		Messages: messages,
		Stream:   true,
	})

	req, err := http.NewRequestWithContext(ctx, "POST", c.baseURL+"/chat/completions", bytes.NewReader(body))
	if err != nil {
		return "", err
	}
	req.Header.Set("Authorization", "Bearer "+c.apiKey)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return "", err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(resp.Body)
		return "", fmt.Errorf("openrouter: %d: %s", resp.StatusCode, string(b))
	}

	var full strings.Builder
	scanner := bufio.NewScanner(resp.Body)
	for scanner.Scan() {
		line := scanner.Text()
		if !strings.HasPrefix(line, "data: ") {
			continue
		}
		data := strings.TrimPrefix(line, "data: ")
		if data == "[DONE]" {
			break
		}

		var chunk ChatResponse
		if err := json.Unmarshal([]byte(data), &chunk); err != nil {
			continue
		}
		if len(chunk.Choices) > 0 && chunk.Choices[0].Delta.Content != "" {
			content := chunk.Choices[0].Delta.Content
			full.WriteString(content)
			if err := fn(content); err != nil {
				return full.String(), err
			}
		}
	}
	return full.String(), scanner.Err()
}
```

- [ ] **Step 4: Run tests**

```bash
go test ./internal/ai/ -v
# Expected: PASS
```

- [ ] **Step 5: Commit**

```bash
git add internal/ai/
git commit -m "feat: add OpenRouter client with sync and streaming support"
```

---

### Task 8: Brave Search client

**Files:**
- Create: `internal/search/brave.go`
- Create: `internal/search/brave_test.go`

- [ ] **Step 1: Write test with mock**

```go
// internal/search/brave_test.go
package search

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
)

func TestSearch(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.URL.Query().Get("q") != "AI content marketing" {
			t.Errorf("unexpected query: %s", r.URL.Query().Get("q"))
		}
		resp := braveResponse{
			Web: webResults{
				Results: []webResult{
					{Title: "AI Marketing Guide", URL: "https://example.com", Description: "A guide to AI marketing"},
				},
			},
		}
		json.NewEncoder(w).Encode(resp)
	}))
	defer server.Close()

	c := NewBraveClient("test-key", WithBraveBaseURL(server.URL))
	results, err := c.Search(context.Background(), "AI content marketing", 5)
	if err != nil {
		t.Fatalf("search: %v", err)
	}
	if len(results) != 1 {
		t.Fatalf("expected 1 result, got %d", len(results))
	}
	if results[0].Title != "AI Marketing Guide" {
		t.Errorf("unexpected title: %s", results[0].Title)
	}
}
```

- [ ] **Step 2: Run to verify fail**

```bash
go test ./internal/search/ -v
# Expected: FAIL
```

- [ ] **Step 3: Implement**

```go
// internal/search/brave.go
package search

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strconv"
)

const defaultBraveURL = "https://api.search.brave.com/res/v1/web/search"

type SearchResult struct {
	Title       string
	URL         string
	Description string
}

type BraveClient struct {
	apiKey  string
	baseURL string
	http    *http.Client
}

type BraveOption func(*BraveClient)

func WithBraveBaseURL(u string) BraveOption {
	return func(c *BraveClient) { c.baseURL = u }
}

func NewBraveClient(apiKey string, opts ...BraveOption) *BraveClient {
	c := &BraveClient{
		apiKey:  apiKey,
		baseURL: defaultBraveURL,
		http:    &http.Client{},
	}
	for _, opt := range opts {
		opt(c)
	}
	return c
}

type braveResponse struct {
	Web webResults `json:"web"`
}

type webResults struct {
	Results []webResult `json:"results"`
}

type webResult struct {
	Title       string `json:"title"`
	URL         string `json:"url"`
	Description string `json:"description"`
}

func (c *BraveClient) Search(ctx context.Context, query string, count int) ([]SearchResult, error) {
	params := url.Values{}
	params.Set("q", query)
	params.Set("count", strconv.Itoa(count))

	req, err := http.NewRequestWithContext(ctx, "GET", c.baseURL+"?"+params.Encode(), nil)
	if err != nil {
		return nil, err
	}
	req.Header.Set("X-Subscription-Token", c.apiKey)
	req.Header.Set("Accept", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(resp.Body)
		return nil, fmt.Errorf("brave: %d: %s", resp.StatusCode, string(b))
	}

	var braveResp braveResponse
	if err := json.NewDecoder(resp.Body).Decode(&braveResp); err != nil {
		return nil, err
	}

	results := make([]SearchResult, len(braveResp.Web.Results))
	for i, r := range braveResp.Web.Results {
		results[i] = SearchResult{
			Title:       r.Title,
			URL:         r.URL,
			Description: r.Description,
		}
	}
	return results, nil
}
```

- [ ] **Step 4: Run tests**

```bash
go test ./internal/search/ -v
# Expected: PASS
```

- [ ] **Step 5: Commit**

```bash
git add internal/search/
git commit -m "feat: add Brave Search API client"
```

---

## Chunk 3: Agents

### Task 9: Voice agent

**Files:**
- Create: `internal/agents/voice.go`
- Create: `internal/agents/voice_test.go`

- [ ] **Step 1: Write test**

```go
// internal/agents/voice_test.go
package agents

import (
	"context"
	"testing"

	"github.com/zanfridau/marketminded/internal/types"
)

type mockAI struct {
	response string
}

func (m *mockAI) Complete(ctx context.Context, model string, msgs []types.Message) (string, error) {
	return m.response, nil
}

func (m *mockAI) Stream(ctx context.Context, model string, msgs []types.Message, fn types.StreamFunc) (string, error) {
	fn(m.response)
	return m.response, nil
}

func TestVoiceAgent_BuildProfile(t *testing.T) {
	agent := NewVoiceAgent(&mockAI{response: `{"tone":"professional","vocabulary":"technical","sentence_style":"concise"}`}, "test-model")

	samples := []string{
		"We build scalable web applications using modern frameworks.",
		"Our team focuses on clean code and test-driven development.",
	}

	profile, err := agent.BuildProfile(context.Background(), samples)
	if err != nil {
		t.Fatalf("build profile: %v", err)
	}
	if profile == "" {
		t.Fatal("expected non-empty profile")
	}
}
```

- [ ] **Step 2: Run to verify fail**

```bash
go test ./internal/agents/ -run TestVoice -v
# Expected: FAIL
```

- [ ] **Step 3: Implement**

```go
// internal/agents/voice.go
package agents

import (
	"context"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/types"
)

// All agents use types.AIClient, types.Message, types.StreamFunc from the shared types package.
// No duplicate type definitions here.

type VoiceAgent struct {
	ai    types.AIClient
	model string
}

func NewVoiceAgent(ai types.AIClient, model string) *VoiceAgent {
	return &VoiceAgent{ai: ai, model: model}
}

func (a *VoiceAgent) BuildProfile(ctx context.Context, samples []string) (string, error) {
	samplesText := strings.Join(samples, "\n\n---\n\n")

	messages := []types.Message{
		{
			Role: "system",
			Content: `You are a voice analysis expert. Analyze the writing samples and produce a JSON voice profile with these fields:
- tone: overall tone (e.g., professional, casual, authoritative)
- vocabulary: vocabulary level and style (e.g., technical, simple, jargon-heavy)
- sentence_style: sentence structure patterns (e.g., concise, flowing, varied)
- personality_traits: list of personality traits that come through
- phrases: recurring phrases or expressions
- dos: list of things to do when writing in this voice
- donts: list of things to avoid

Return ONLY valid JSON.`,
		},
		{
			Role:    "user",
			Content: fmt.Sprintf("Analyze these writing samples and build a voice profile:\n\n%s", samplesText),
		},
	}

	return a.ai.Complete(ctx, a.model, messages)
}
```

- [ ] **Step 4: Run tests**

```bash
go test ./internal/agents/ -run TestVoice -v
# Expected: PASS
```

- [ ] **Step 5: Commit**

```bash
git add internal/agents/voice.go internal/agents/voice_test.go
git commit -m "feat: add voice agent for building voice profiles"
```

---

### Task 10: Tone agent

**Files:**
- Create: `internal/agents/tone.go`
- Create: `internal/agents/tone_test.go`

- [ ] **Step 1: Write test**

```go
// internal/agents/tone_test.go
package agents

import (
	"context"
	"testing"
)

func TestToneAgent_BuildProfile(t *testing.T) {
	agent := NewToneAgent(&mockAI{response: `{"formality":"high","humor":"low","emotion":"moderate"}`}, "test-model")

	samples := []string{"Professional content sample."}
	brandDocs := []string{"Brand guidelines: formal, no slang."}

	profile, err := agent.BuildProfile(context.Background(), samples, brandDocs)
	if err != nil {
		t.Fatalf("build profile: %v", err)
	}
	if profile == "" {
		t.Fatal("expected non-empty profile")
	}
}
```

- [ ] **Step 2: Implement**

```go
// internal/agents/tone.go
package agents

import (
	"context"
	"fmt"
	"strings"
)

type ToneAgent struct {
	ai    AIClient
	model string
}

func NewToneAgent(ai AIClient, model string) *ToneAgent {
	return &ToneAgent{ai: ai, model: model}
}

func (a *ToneAgent) BuildProfile(ctx context.Context, samples []string, brandDocs []string) (string, error) {
	samplesText := strings.Join(samples, "\n\n---\n\n")
	docsText := strings.Join(brandDocs, "\n\n---\n\n")

	userContent := fmt.Sprintf("Writing samples:\n\n%s", samplesText)
	if docsText != "" {
		userContent += fmt.Sprintf("\n\nBrand documents:\n\n%s", docsText)
	}

	messages := []types.Message{
		{
			Role: "system",
			Content: `You are a tone analysis expert. Analyze the writing samples and brand documents to produce a JSON tone profile with these fields:
- formality: level from "very_casual" to "very_formal"
- humor: level from "none" to "heavy"
- emotion: level from "detached" to "highly_emotional"
- persuasion_style: how the brand persuades (e.g., data-driven, storytelling, authority)
- audience_relationship: how the brand relates to readers (e.g., peer, mentor, expert)
- guidelines: list of specific tone rules to follow
- avoid: list of tonal qualities to avoid

Return ONLY valid JSON.`,
		},
		{
			Role:    "user",
			Content: userContent,
		},
	}

	return a.ai.Complete(ctx, a.model, messages)
}
```

- [ ] **Step 3: Run tests**

```bash
go test ./internal/agents/ -run TestTone -v
# Expected: PASS
```

- [ ] **Step 4: Commit**

```bash
git add internal/agents/tone.go internal/agents/tone_test.go
git commit -m "feat: add tone agent for building tone profiles"
```

---

### Task 11: Idea agent (with Brave Search)

**Files:**
- Create: `internal/agents/idea.go`
- Create: `internal/agents/idea_test.go`

- [ ] **Step 1: Write test**

```go
// internal/agents/idea_test.go
package agents

import (
	"context"
	"testing"
)

type mockSearcher struct {
	results []SearchResult
}

func (m *mockSearcher) Search(ctx context.Context, query string, count int) ([]SearchResult, error) {
	return m.results, nil
}

func TestIdeaAgent_Generate(t *testing.T) {
	ai := &mockAI{response: "1. How to scale your web agency\n2. 5 tips for client retention"}
	searcher := &mockSearcher{results: []SearchResult{
		{Title: "Web Agency Growth", URL: "https://example.com", Description: "Guide to growing"},
	}}

	agent := NewIdeaAgent(ai, searcher, "test-model")

	ideas, err := agent.Generate(context.Background(), IdeaInput{
		Niche:        "web development agency",
		ContentLog:   []string{"Previous: How we onboard clients"},
		VoiceProfile: "professional, technical",
	})
	if err != nil {
		t.Fatalf("generate: %v", err)
	}
	if ideas == "" {
		t.Fatal("expected non-empty ideas")
	}
}
```

- [ ] **Step 2: Implement**

```go
// internal/agents/idea.go
package agents

import (
	"context"
	"fmt"
	"strings"
)

type Searcher interface {
	Search(ctx context.Context, query string, count int) ([]SearchResult, error)
}

type SearchResult struct {
	Title       string
	URL         string
	Description string
}

type IdeaInput struct {
	Niche        string
	ContentLog   []string // titles + summaries of past content
	VoiceProfile string
}

type IdeaAgent struct {
	ai       AIClient
	searcher Searcher
	model    string
}

func NewIdeaAgent(ai AIClient, searcher Searcher, model string) *IdeaAgent {
	return &IdeaAgent{ai: ai, searcher: searcher, model: model}
}

func (a *IdeaAgent) Generate(ctx context.Context, input IdeaInput) (string, error) {
	// Search for trending content in the niche
	results, err := a.searcher.Search(ctx, input.Niche+" content ideas trending", 7)
	if err != nil {
		return "", fmt.Errorf("search: %w", err)
	}

	var searchContext strings.Builder
	for _, r := range results {
		fmt.Fprintf(&searchContext, "- %s: %s (%s)\n", r.Title, r.Description, r.URL)
	}

	contentLog := "None yet."
	if len(input.ContentLog) > 0 {
		contentLog = strings.Join(input.ContentLog, "\n")
	}

	messages := []types.Message{
		{
			Role: "system",
			Content: `You are a content strategist. Generate 10 pillar content ideas (blog post topics) based on the research, niche, and brand voice provided. Each idea should:
- Be specific and actionable
- Have a compelling working title
- Include a one-line angle/hook
- NOT repeat topics from the content log

Return as a numbered list with title and angle on each line.`,
		},
		{
			Role: "user",
			Content: fmt.Sprintf("Niche: %s\n\nVoice: %s\n\nRecent web research:\n%s\nPrevious content (avoid repeating):\n%s\n\nGenerate 10 pillar blog post ideas.",
				input.Niche, input.VoiceProfile, searchContext.String(), contentLog),
		},
	}

	return a.ai.Complete(ctx, a.model, messages)
}
```

- [ ] **Step 3: Run tests**

```bash
go test ./internal/agents/ -run TestIdea -v
# Expected: PASS
```

- [ ] **Step 4: Commit**

```bash
git add internal/agents/idea.go internal/agents/idea_test.go
git commit -m "feat: add idea agent with Brave Search integration"
```

---

### Task 12: Content agent (pillar + waterfall)

**Files:**
- Create: `internal/agents/content.go`
- Create: `internal/agents/content_test.go`

- [ ] **Step 1: Write tests**

```go
// internal/agents/content_test.go
package agents

import (
	"context"
	"testing"
)

func TestContentAgent_WritePillar(t *testing.T) {
	ai := &mockAI{response: "# How to Scale Your Agency\n\nGreat blog post content here..."}
	agent := NewContentAgent(ai, "test-model")

	result, err := agent.WritePillar(context.Background(), PillarInput{
		Topic:        "How to scale your web development agency",
		VoiceProfile: `{"tone":"professional"}`,
		ToneProfile:  `{"formality":"high"}`,
		ContentLog:   []string{},
	})
	if err != nil {
		t.Fatalf("write pillar: %v", err)
	}
	if result == "" {
		t.Fatal("expected non-empty result")
	}
}

func TestContentAgent_WriteSocialPost(t *testing.T) {
	ai := &mockAI{response: "Scaling your agency? Here are 3 lessons we learned..."}
	agent := NewContentAgent(ai, "test-model")

	result, err := agent.WriteSocialPost(context.Background(), SocialInput{
		PillarContent: "# How to Scale\n\nFull blog post...",
		Platform:      "linkedin",
		VoiceProfile:  `{"tone":"professional"}`,
		ToneProfile:   `{"formality":"high"}`,
	})
	if err != nil {
		t.Fatalf("write social: %v", err)
	}
	if result == "" {
		t.Fatal("expected non-empty result")
	}
}
```

- [ ] **Step 2: Implement**

```go
// internal/agents/content.go
package agents

import (
	"context"
	"fmt"
	"strings"
)

type ContentAgent struct {
	ai    AIClient
	model string
}

func NewContentAgent(ai AIClient, model string) *ContentAgent {
	return &ContentAgent{ai: ai, model: model}
}

type PillarInput struct {
	Topic        string
	VoiceProfile string
	ToneProfile  string
	ContentLog   []string
}

func (a *ContentAgent) WritePillar(ctx context.Context, input PillarInput) (string, error) {
	contentLog := "No previous content."
	if len(input.ContentLog) > 0 {
		contentLog = strings.Join(input.ContentLog, "\n")
	}

	messages := []types.Message{
		{
			Role: "system",
			Content: fmt.Sprintf(`You are an expert blog writer. Write a comprehensive, engaging blog post on the given topic.

Voice profile: %s

Tone profile: %s

Guidelines:
- Write in the brand's voice and tone
- Use markdown formatting
- Include a compelling introduction with a hook
- Break into clear sections with headers
- Include actionable takeaways
- End with a strong conclusion
- Aim for 1200-1800 words
- Do NOT repeat themes from the content log below`, input.VoiceProfile, input.ToneProfile),
		},
		{
			Role: "user",
			Content: fmt.Sprintf("Topic: %s\n\nPrevious content (for continuity, don't repeat):\n%s\n\nWrite the blog post.",
				input.Topic, contentLog),
		},
	}

	return a.ai.Complete(ctx, a.model, messages)
}

// WritePillarStream is the streaming variant for the UI
func (a *ContentAgent) WritePillarStream(ctx context.Context, input PillarInput, fn StreamFunc) (string, error) {
	contentLog := "No previous content."
	if len(input.ContentLog) > 0 {
		contentLog = strings.Join(input.ContentLog, "\n")
	}

	messages := []types.Message{
		{
			Role: "system",
			Content: fmt.Sprintf(`You are an expert blog writer. Write a comprehensive, engaging blog post on the given topic.

Voice profile: %s

Tone profile: %s

Guidelines:
- Write in the brand's voice and tone
- Use markdown formatting
- Include a compelling introduction with a hook
- Break into clear sections with headers
- Include actionable takeaways
- End with a strong conclusion
- Aim for 1200-1800 words
- Do NOT repeat themes from the content log below`, input.VoiceProfile, input.ToneProfile),
		},
		{
			Role: "user",
			Content: fmt.Sprintf("Topic: %s\n\nPrevious content (for continuity, don't repeat):\n%s\n\nWrite the blog post.",
				input.Topic, contentLog),
		},
	}

	return a.ai.Stream(ctx, a.model, messages, fn)
}

type SocialInput struct {
	PillarContent string
	Platform      string
	VoiceProfile  string
	ToneProfile   string
	TemplateSlots string // optional: describes available template slots
}

func (a *ContentAgent) WriteSocialPost(ctx context.Context, input SocialInput) (string, error) {
	platformGuide := platformGuidelines(input.Platform)

	messages := []types.Message{
		{
			Role: "system",
			Content: fmt.Sprintf(`You are a social media content expert. Repurpose the pillar blog post into a %s post.

Voice profile: %s
Tone profile: %s

Platform guidelines: %s

If template slots are provided, output JSON with the slot values. Otherwise output the post text directly.`, input.Platform, input.VoiceProfile, input.ToneProfile, platformGuide),
		},
		{
			Role:    "user",
			Content: fmt.Sprintf("Pillar content:\n\n%s\n\nTemplate slots: %s\n\nWrite the %s post.", input.PillarContent, input.TemplateSlots, input.Platform),
		},
	}

	return a.ai.Complete(ctx, a.model, messages)
}

func platformGuidelines(platform string) string {
	switch platform {
	case "linkedin":
		return "Professional tone. Hook in first line. Use line breaks for readability. 1300 char max. Include a CTA."
	case "instagram":
		return "Engaging, visual language. Hook in first line. Use emojis sparingly. Include relevant hashtags. 2200 char max."
	case "facebook":
		return "Conversational. Hook in first line. Encourage engagement/comments. 500 char ideal."
	default:
		return "Write an engaging post appropriate for the platform."
	}
}
```

- [ ] **Step 3: Run tests**

```bash
go test ./internal/agents/ -v
# Expected: PASS
```

- [ ] **Step 4: Commit**

```bash
git add internal/agents/content.go internal/agents/content_test.go
git commit -m "feat: add content agent for pillar writing and social post waterfall"
```

---

## Chunk 4: Pipeline Orchestration

### Task 13: Pipeline state machine

**Files:**
- Create: `internal/pipeline/pipeline.go`
- Create: `internal/pipeline/pipeline_test.go`

- [ ] **Step 1: Write test**

```go
// internal/pipeline/pipeline_test.go
package pipeline

import (
	"context"
	"testing"
)

type mockStore struct {
	run    *PipelineRunState
	pieces []ContentPieceSummary
}

func (m *mockStore) GetPipelineRun(id int64) (*PipelineRunState, error)          { return m.run, nil }
func (m *mockStore) AdvancePipelineRun(id int64, status string) error             { m.run.Status = status; return nil }
func (m *mockStore) SetPipelineTopic(id int64, topic string) error                { return nil }
func (m *mockStore) ContentLogSummaries(projectID int64, limit int) ([]ContentPieceSummary, error) {
	return m.pieces, nil
}

func TestValidTransitions(t *testing.T) {
	p := New(nil, nil, nil)

	tests := []struct {
		from, to string
		valid    bool
	}{
		{"ideating", "creating_pillar", true},
		{"creating_pillar", "waterfalling", true},
		{"waterfalling", "complete", true},
		{"ideating", "abandoned", true},
		{"creating_pillar", "abandoned", true},
		{"ideating", "complete", false},
		{"complete", "ideating", false},
		{"waterfalling", "ideating", false},
	}

	for _, tt := range tests {
		err := p.ValidateTransition(tt.from, tt.to)
		if tt.valid && err != nil {
			t.Errorf("%s → %s should be valid, got: %v", tt.from, tt.to, err)
		}
		if !tt.valid && err == nil {
			t.Errorf("%s → %s should be invalid", tt.from, tt.to)
		}
	}
}
```

- [ ] **Step 2: Implement**

```go
// internal/pipeline/pipeline.go
package pipeline

import (
	"context"
	"fmt"
)

type PipelineRunState struct {
	ID            int64
	ProjectID     int64
	Status        string
	SelectedTopic *string
}

type ContentPieceSummary struct {
	Title string
	Body  string // truncated
	Type  string
}

type Store interface {
	GetPipelineRun(id int64) (*PipelineRunState, error)
	AdvancePipelineRun(id int64, status string) error
	SetPipelineTopic(id int64, topic string) error
	ContentLogSummaries(projectID int64, limit int) ([]ContentPieceSummary, error)
}

var validTransitions = map[string][]string{
	"ideating":        {"creating_pillar", "abandoned"},
	"creating_pillar": {"waterfalling", "abandoned"},
	"waterfalling":    {"complete", "abandoned"},
}

type Pipeline struct {
	store  Store
	agents interface{} // will be typed later when wiring up
	ai     interface{}
}

func New(store Store, agents interface{}, ai interface{}) *Pipeline {
	return &Pipeline{store: store, agents: agents, ai: ai}
}

func (p *Pipeline) ValidateTransition(from, to string) error {
	allowed, ok := validTransitions[from]
	if !ok {
		return fmt.Errorf("no transitions from state %q", from)
	}
	for _, a := range allowed {
		if a == to {
			return nil
		}
	}
	return fmt.Errorf("invalid transition: %s → %s", from, to)
}

func (p *Pipeline) Advance(ctx context.Context, runID int64, newStatus string) error {
	run, err := p.store.GetPipelineRun(runID)
	if err != nil {
		return fmt.Errorf("get run: %w", err)
	}
	if err := p.ValidateTransition(run.Status, newStatus); err != nil {
		return err
	}
	return p.store.AdvancePipelineRun(runID, newStatus)
}
```

- [ ] **Step 3: Run tests**

```bash
go test ./internal/pipeline/ -v
# Expected: PASS
```

- [ ] **Step 4: Commit**

```bash
git add internal/pipeline/
git commit -m "feat: add pipeline state machine with transition validation"
```

---

## Chunk 5: Web Layer (templ + handlers)

### Task 14: Install templ and create base layout

**Files:**
- Create: `web/templates/layout.templ`
- Create: `web/static/style.css`
- Create: `web/static/app.js`

- [ ] **Step 1: Install templ CLI**

```bash
go install github.com/a-h/templ/cmd/templ@latest
```

- [ ] **Step 2: Create base layout**

```go
// web/templates/layout.templ
package templates

templ Layout(title string) {
	<!DOCTYPE html>
	<html lang="en">
	<head>
		<meta charset="UTF-8"/>
		<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
		<title>{ title } - MarketMinded</title>
		<link rel="stylesheet" href="/static/style.css"/>
		<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
		<script src="/static/app.js" defer></script>
	</head>
	<body>
		<nav>
			<a href="/">MarketMinded</a>
		</nav>
		<main>
			{ children... }
		</main>
	</body>
	</html>
}
```

- [ ] **Step 3: Create minimal CSS**

```css
/* web/static/style.css */
* { margin: 0; padding: 0; box-sizing: border-box; }
body { font-family: system-ui, sans-serif; max-width: 1200px; margin: 0 auto; padding: 1rem; color: #1a1a1a; }
nav { padding: 1rem 0; border-bottom: 1px solid #e5e5e5; margin-bottom: 2rem; }
nav a { text-decoration: none; font-weight: 700; font-size: 1.25rem; color: #1a1a1a; }
main { padding: 1rem 0; }
h1 { margin-bottom: 1rem; }
h2 { margin-bottom: 0.75rem; }
.card { border: 1px solid #e5e5e5; border-radius: 8px; padding: 1rem; margin-bottom: 1rem; }
.btn { display: inline-block; padding: 0.5rem 1rem; background: #1a1a1a; color: #fff; border: none; border-radius: 4px; cursor: pointer; text-decoration: none; font-size: 0.875rem; }
.btn:hover { background: #333; }
.btn-secondary { background: #fff; color: #1a1a1a; border: 1px solid #ccc; }
textarea, input[type="text"], input[type="url"], select { width: 100%; padding: 0.5rem; border: 1px solid #ccc; border-radius: 4px; font-size: 0.875rem; margin-bottom: 0.5rem; }
textarea { min-height: 120px; resize: vertical; }
label { display: block; font-weight: 600; margin-bottom: 0.25rem; font-size: 0.875rem; }
.form-group { margin-bottom: 1rem; }
.grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1rem; }
.stream-output { background: #f9f9f9; border: 1px solid #e5e5e5; border-radius: 4px; padding: 1rem; white-space: pre-wrap; font-family: system-ui; min-height: 200px; }
.badge { display: inline-block; padding: 0.125rem 0.5rem; border-radius: 9999px; font-size: 0.75rem; font-weight: 600; }
.badge-draft { background: #fef3c7; color: #92400e; }
.badge-approved { background: #d1fae5; color: #065f46; }
.badge-published { background: #dbeafe; color: #1e40af; }
```

- [ ] **Step 4: Create minimal Alpine.js app**

```js
// web/static/app.js
// Alpine.js components for MarketMinded

document.addEventListener('alpine:init', () => {
    Alpine.data('streamOutput', () => ({
        content: '',
        loading: false,
        error: '',

        async startStream(url) {
            this.content = '';
            this.loading = true;
            this.error = '';

            try {
                const source = new EventSource(url);
                source.onmessage = (event) => {
                    const data = JSON.parse(event.data);
                    if (data.done) {
                        source.close();
                        this.loading = false;
                        return;
                    }
                    if (data.error) {
                        this.error = data.error;
                        source.close();
                        this.loading = false;
                        return;
                    }
                    this.content += data.chunk;
                };
                source.onerror = () => {
                    source.close();
                    this.loading = false;
                    this.error = 'Connection lost. Try again.';
                };
            } catch (e) {
                this.error = e.message;
                this.loading = false;
            }
        }
    }));
});
```

- [ ] **Step 5: Generate templ and verify compile**

```bash
templ generate ./web/templates/
go build ./...
# Expected: compiles without errors
```

- [ ] **Step 6: Commit**

```bash
git add web/
git commit -m "feat: add base layout, CSS, and Alpine.js SSE streaming component"
```

---

### Task 15: Dashboard handler + template

**Files:**
- Create: `web/templates/dashboard.templ`
- Create: `web/handlers/dashboard.go`
- Modify: `cmd/server/main.go`

- [ ] **Step 1: Create dashboard template**

```go
// web/templates/dashboard.templ
package templates

type ProjectListItem struct {
	ID          int64
	Name        string
	Description string
}

templ Dashboard(projects []ProjectListItem) {
	@Layout("Dashboard") {
		<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
			<h1>Projects</h1>
			<a href="/projects/new" class="btn">New Project</a>
		</div>
		if len(projects) == 0 {
			<p>No projects yet. Create one to get started.</p>
		}
		<div class="grid">
			for _, p := range projects {
				<a href={ templ.SafeURL("/projects/" + fmt.Sprintf("%d", p.ID)) } class="card" style="text-decoration:none;color:inherit">
					<h3>{ p.Name }</h3>
					<p style="color:#666;font-size:0.875rem">{ p.Description }</p>
				</a>
			}
		</div>
	}
}
```

**Note:** You'll need `"fmt"` imported in the templ file for `fmt.Sprintf`. Templ handles imports — add `import "fmt"` at the top of the package block if needed by the templ compiler.

- [ ] **Step 2: Create dashboard handler**

```go
// web/handlers/dashboard.go
package handlers

import (
	"net/http"

	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type DashboardHandler struct {
	queries *store.Queries
}

func NewDashboardHandler(q *store.Queries) *DashboardHandler {
	return &DashboardHandler{queries: q}
}

func (h *DashboardHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	projects, err := h.queries.ListProjects()
	if err != nil {
		http.Error(w, "Failed to load projects", http.StatusInternalServerError)
		return
	}

	items := make([]templates.ProjectListItem, len(projects))
	for i, p := range projects {
		items[i] = templates.ProjectListItem{
			ID:          p.ID,
			Name:        p.Name,
			Description: p.Description,
		}
	}

	templates.Dashboard(items).Render(r.Context(), w)
}
```

- [ ] **Step 3: Wire up main.go**

```go
// cmd/server/main.go
package main

import (
	"log"
	"net/http"

	"github.com/zanfridau/marketminded/internal/config"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/handlers"
)

func main() {
	cfg, err := config.Load()
	if err != nil {
		log.Fatalf("config: %v", err)
	}

	db, err := store.Open(cfg.DBPath)
	if err != nil {
		log.Fatalf("db: %v", err)
	}
	defer db.Close()

	queries := store.NewQueries(db)

	mux := http.NewServeMux()
	mux.Handle("/", handlers.NewDashboardHandler(queries))
	mux.Handle("/static/", http.StripPrefix("/static/", http.FileServer(http.Dir("web/static"))))

	log.Printf("Starting on :%s", cfg.Port)
	log.Fatal(http.ListenAndServe(":"+cfg.Port, mux))
}
```

- [ ] **Step 4: Generate templ, build, and test manually**

```bash
templ generate ./web/templates/ && go build ./cmd/server/
# Expected: compiles
```

- [ ] **Step 5: Commit**

```bash
git add cmd/server/main.go web/handlers/dashboard.go web/templates/dashboard.templ
git commit -m "feat: add dashboard page with project listing"
```

---

### Task 16: Project CRUD handlers + templates

**Files:**
- Create: `web/templates/project.templ`
- Create: `web/templates/project_new.templ`
- Create: `web/handlers/project.go`
- Modify: `cmd/server/main.go` (add routes)

- [ ] **Step 1: Create project new template**

```go
// web/templates/project_new.templ
package templates

templ ProjectNew() {
	@Layout("New Project") {
		<h1>New Project</h1>
		<form method="POST" action="/projects">
			<div class="form-group">
				<label for="name">Project Name</label>
				<input type="text" id="name" name="name" required placeholder="e.g. Acme Corp"/>
			</div>
			<div class="form-group">
				<label for="description">Description</label>
				<textarea id="description" name="description" placeholder="Brief description of the client/project"></textarea>
			</div>
			<button type="submit" class="btn">Create Project</button>
		</form>
	}
}
```

- [ ] **Step 2: Create project overview template**

```go
// web/templates/project.templ
package templates

import "fmt"

type ProjectDetail struct {
	ID           int64
	Name         string
	Description  string
	HasVoice     bool
	HasTone      bool
	ContentCount int
}

templ ProjectOverview(p ProjectDetail) {
	@Layout(p.Name) {
		<h1>{ p.Name }</h1>
		<p style="color:#666;margin-bottom:2rem">{ p.Description }</p>

		<div class="grid">
			<div class="card">
				<h3>Setup</h3>
				<p>Voice: if p.HasVoice { <span class="badge badge-approved">Ready</span> } else { <span class="badge badge-draft">Not set</span> }</p>
				<p>Tone: if p.HasTone { <span class="badge badge-approved">Ready</span> } else { <span class="badge badge-draft">Not set</span> }</p>
				<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/knowledge", p.ID)) } class="btn btn-secondary" style="margin-top:0.5rem">Manage Knowledge</a>
			</div>

			<div class="card">
				<h3>Content</h3>
				<p>{ fmt.Sprintf("%d pieces", p.ContentCount) }</p>
				<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/pipeline/new", p.ID)) } class="btn" style="margin-top:0.5rem">New Pipeline Run</a>
			</div>

			<div class="card">
				<h3>Brainstorm</h3>
				<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/brainstorm", p.ID)) } class="btn btn-secondary" style="margin-top:0.5rem">Open Chat</a>
			</div>

			<div class="card">
				<h3>Templates</h3>
				<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/templates", p.ID)) } class="btn btn-secondary" style="margin-top:0.5rem">Manage Templates</a>
			</div>
		</div>
	}
}
```

- [ ] **Step 3: Create project handler**

```go
// web/handlers/project.go
package handlers

import (
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type ProjectHandler struct {
	queries *store.Queries
}

func NewProjectHandler(q *store.Queries) *ProjectHandler {
	return &ProjectHandler{queries: q}
}

func (h *ProjectHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	path := strings.TrimPrefix(r.URL.Path, "/projects")

	switch {
	case path == "/new" && r.Method == "GET":
		h.newForm(w, r)
	case path == "" && r.Method == "POST":
		h.create(w, r)
	case path != "" && r.Method == "GET":
		h.show(w, r, path)
	default:
		http.NotFound(w, r)
	}
}

func (h *ProjectHandler) newForm(w http.ResponseWriter, r *http.Request) {
	templates.ProjectNew().Render(r.Context(), w)
}

func (h *ProjectHandler) create(w http.ResponseWriter, r *http.Request) {
	r.ParseForm()
	name := r.FormValue("name")
	desc := r.FormValue("description")

	if name == "" {
		http.Error(w, "Name is required", http.StatusBadRequest)
		return
	}

	p, err := h.queries.CreateProject(name, desc)
	if err != nil {
		http.Error(w, "Failed to create project", http.StatusInternalServerError)
		return
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d", p.ID), http.StatusSeeOther)
}

func (h *ProjectHandler) show(w http.ResponseWriter, r *http.Request, path string) {
	idStr := strings.TrimPrefix(path, "/")
	// Strip any sub-paths (handled by other handlers)
	if idx := strings.Index(idStr, "/"); idx != -1 {
		http.NotFound(w, r)
		return
	}

	id, err := strconv.ParseInt(idStr, 10, 64)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	project, err := h.queries.GetProject(id)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	pieces, _ := h.queries.ListContentPieces(id)

	detail := templates.ProjectDetail{
		ID:           project.ID,
		Name:         project.Name,
		Description:  project.Description,
		HasVoice:     project.VoiceProfile != nil,
		HasTone:      project.ToneProfile != nil,
		ContentCount: len(pieces),
	}

	templates.ProjectOverview(detail).Render(r.Context(), w)
}
```

- [ ] **Step 4: Add routes to main.go**

Add to the mux setup in `cmd/server/main.go`:

```go
mux.Handle("/projects/", handlers.NewProjectHandler(queries))
mux.Handle("/projects", handlers.NewProjectHandler(queries))
```

- [ ] **Step 5: Generate, build, verify**

```bash
templ generate ./web/templates/ && go build ./cmd/server/
# Expected: compiles
```

- [ ] **Step 6: Commit**

```bash
git add web/templates/project.templ web/templates/project_new.templ web/handlers/project.go cmd/server/main.go
git commit -m "feat: add project CRUD pages (new, create, overview)"
```

---

### Task 17: Knowledge manager handlers + templates

> **Note to implementer:** Tasks 17-20 are outlined at a higher level because the full templ/handler code is lengthy. Follow the patterns established in Tasks 15-16 (dashboard + project handlers). Each task should follow TDD: write a handler test with httptest, then implement the handler and template. Reference the spec for exact feature requirements.

This task follows the same pattern as Task 16. Create:
- `web/templates/knowledge.templ` — list knowledge items, add form
- `web/handlers/knowledge.go` — CRUD for knowledge items + trigger voice/tone agents
- Routes: `GET /projects/:id/knowledge`, `POST /projects/:id/knowledge`

Implementation should allow adding knowledge items (voice samples, brand docs, etc.) and triggering the voice/tone agent setup via a "Build Profile" button that calls the agents and stores results on the project. Use `internal/project/service.go` to orchestrate: fetch knowledge items → pass to voice/tone agents → call `store.UpdateVoiceProfile` / `store.UpdateToneProfile`.

- [ ] **Step 1: Create knowledge template**
- [ ] **Step 2: Create knowledge handler**
- [ ] **Step 3: Wire routes in main.go**
- [ ] **Step 4: Generate, build, verify**
- [ ] **Step 5: Commit**

```bash
git commit -m "feat: add knowledge manager page"
```

---

### Task 18: Pipeline run handlers + templates (SSE streaming)

The core page. Create:
- `web/templates/pipeline.templ` — wizard UI with stages, stream output area, approve/advance buttons
- `web/handlers/pipeline.go` — create run, SSE endpoint for agent streaming, advance stage

Routes:
- `POST /projects/:id/pipeline/new` — create a new run, redirect to it
- `GET /projects/:id/pipeline/:runId` — show pipeline wizard
- `GET /projects/:id/pipeline/:runId/stream/:stage` — SSE endpoint for agent streaming
- `POST /projects/:id/pipeline/:runId/advance` — advance to next stage

The SSE handler should:
1. Look up the pipeline run and determine the current stage
2. Build agent context (voice/tone profiles, content log summaries, selected topic)
3. Call the appropriate agent with a `StreamFunc` that writes SSE `data:` lines
4. After completion, save the agent run and content piece to the DB
5. Send a final `data: {"done": true}` event

- [ ] **Step 1: Create pipeline template with Alpine.js streaming**
- [ ] **Step 2: Create pipeline handler with SSE**
- [ ] **Step 3: Wire routes**
- [ ] **Step 4: Generate, build, verify**
- [ ] **Step 5: Commit**

```bash
git commit -m "feat: add pipeline run wizard with SSE streaming"
```

---

### Task 19: Content piece editor + template manager

Create:
- `web/templates/content.templ` — edit content piece, approve button
- `web/handlers/content.go` — view, edit, approve content
- `web/templates/templates_mgr.templ` — list/add/edit HTML templates
- `web/handlers/templates.go` — template CRUD

- [ ] **Step 1: Create content piece template + handler**
- [ ] **Step 2: Create template manager template + handler**
- [ ] **Step 3: Wire routes**
- [ ] **Step 4: Generate, build, verify**
- [ ] **Step 5: Commit**

```bash
git commit -m "feat: add content editor and template manager pages"
```

---

### Task 20: Brainstorm chat page (SSE)

Create:
- `web/templates/brainstorm.templ` — chat UI with message list, input, streaming response
- `web/handlers/brainstorm.go` — list chats, create chat, send message, SSE stream response

Key features:
- Chat sends user message via POST, then opens SSE connection for the AI response
- "Push to Pipeline" button: creates a new pipeline run with the chat message as `selected_topic`, redirects to the pipeline page at the `creating_pillar` stage (skipping ideation since the topic is already chosen)

- [ ] **Step 1: Create brainstorm template**
- [ ] **Step 2: Create brainstorm handler with SSE**
- [ ] **Step 3: Wire routes**
- [ ] **Step 4: Generate, build, verify**
- [ ] **Step 5: Commit**

```bash
git commit -m "feat: add brainstorm chat with SSE streaming"
```

---

## Chunk 6: HTML-to-PNG Rendering

### Task 21: Template rendering with rod

**Files:**
- Create: `internal/render/png.go`
- Create: `internal/render/png_test.go`

- [ ] **Step 1: Add rod dependency**

```bash
go get github.com/go-rod/rod
```

- [ ] **Step 2: Write test**

```go
// internal/render/png_test.go
package render

import (
	"testing"
)

func TestRenderTemplate(t *testing.T) {
	html := `<div style="width:1080px;height:1080px;background:#fff;padding:40px;font-family:sans-serif">
		<h1>{{.Title}}</h1>
		<p>{{.Body}}</p>
	</div>`

	data := map[string]string{
		"Title": "Test Post",
		"Body":  "This is a test social post.",
	}

	png, err := RenderToPNG(html, data)
	if err != nil {
		t.Fatalf("render: %v", err)
	}
	if len(png) == 0 {
		t.Fatal("expected non-empty PNG data")
	}
	// Check PNG magic bytes
	if png[0] != 0x89 || png[1] != 0x50 {
		t.Fatal("output is not a valid PNG")
	}
}
```

- [ ] **Step 3: Implement**

```go
// internal/render/png.go
package render

import (
	"bytes"
	"fmt"
	"html/template"
	"time"

	"github.com/go-rod/rod"
	"github.com/go-rod/rod/lib/proto"
)

func RenderToPNG(htmlTemplate string, data map[string]string) ([]byte, error) {
	tmpl, err := template.New("social").Parse(htmlTemplate)
	if err != nil {
		return nil, fmt.Errorf("parse template: %w", err)
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, data); err != nil {
		return nil, fmt.Errorf("execute template: %w", err)
	}

	browser := rod.New().MustConnect()
	defer browser.MustClose()

	page := browser.MustPage()
	defer page.MustClose()

	page.MustSetDocumentContent(buf.String())
	page.MustWaitStable()

	// Wait briefly for fonts to load
	time.Sleep(100 * time.Millisecond)

	img, err := page.Screenshot(true, &proto.PageCaptureScreenshot{
		Format: proto.PageCaptureScreenshotFormatPng,
	})
	if err != nil {
		return nil, fmt.Errorf("screenshot: %w", err)
	}

	return img, nil
}
```

- [ ] **Step 4: Run test (requires Chrome/Chromium installed)**

```bash
go test ./internal/render/ -v -timeout 30s
# Expected: PASS (if Chrome is available)
```

- [ ] **Step 5: Commit**

```bash
git add internal/render/
git commit -m "feat: add HTML-to-PNG rendering via rod"
```

---

## Chunk 7: Integration & Polish

### Task 22: Wire everything together in main.go

- [ ] **Step 1: Update main.go to initialize all clients and pass to handlers**

Wire up: config → db → queries → ai client → brave client → agents → pipeline → all handlers. Register all routes.

- [ ] **Step 2: Full build and manual smoke test**

```bash
templ generate ./web/templates/ && go build ./cmd/server/
OPENROUTER_API_KEY=test BRAVE_API_KEY=test ./server
# Visit http://localhost:8080, create a project, verify pages load
```

- [ ] **Step 3: Commit**

```bash
git commit -m "feat: wire all components together in main.go"
```

---

### Task 23: End-to-end smoke test

- [ ] **Step 1: Write an integration test that creates a project, adds knowledge, runs the pipeline**

Create `cmd/server/integration_test.go` that starts the server with a test DB, creates a project via HTTP, adds a knowledge item, and verifies the pipeline page loads.

- [ ] **Step 2: Run it**

```bash
go test ./cmd/server/ -v -tags integration
# Expected: PASS
```

- [ ] **Step 3: Commit**

```bash
git commit -m "test: add integration smoke test"
```

---

### Task 24: Add .gitignore and Makefile

- [ ] **Step 1: Create .gitignore**

```
*.db
*_templ.go
/server
/marketminded
```

- [ ] **Step 2: Create Makefile**

```makefile
.PHONY: generate build run test

generate:
	templ generate ./web/templates/

build: generate
	go build -o server ./cmd/server/

run: build
	./server

test:
	go test ./...
```

- [ ] **Step 3: Commit**

```bash
git add .gitignore Makefile
git commit -m "chore: add .gitignore and Makefile"
```
