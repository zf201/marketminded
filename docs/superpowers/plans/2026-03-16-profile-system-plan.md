# Profile System Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the flat knowledge base with a structured 10-section client profile system with AI-generated proposals, approval workflow, and refinement chat.

**Architecture:** New migration rewrites the schema (clean break). New `profile` store layer, `ProfileAgent`, profile handler + templ page. Existing agents and pipeline handler updated to consume the new profile format. Old voice/tone agent code removed.

**Tech Stack:** Go, SQLite, templ, vanilla JS (SSE streaming), OpenRouter

---

## File Map

```
Changes:
  migrations/001_initial.sql              — rewrite: drop knowledge_items, voice/tone columns, add profile tables
  internal/store/projects.go              — remove VoiceProfile/ToneProfile fields and related methods
  internal/store/profile.go               — NEW: CRUD for profile_sections, section_inputs, section_proposals, project_references
  internal/store/profile_test.go          — NEW: tests
  internal/agents/profile.go              — NEW: ProfileAgent (replaces voice.go + tone.go)
  internal/agents/profile_test.go         — NEW: tests
  internal/agents/content.go              — update PillarInput/SocialInput: replace VoiceProfile+ToneProfile with Profile
  internal/agents/content_test.go         — update tests
  internal/agents/idea.go                 — update IdeaInput: replace VoiceProfile with Profile
  internal/agents/idea_test.go            — update tests
  web/handlers/profile.go                 — NEW: profile page, inputs, analyze, approve/reject, refinement chat SSE
  web/templates/profile.templ             — NEW: section tabs, inputs, proposals, refinement UI
  web/templates/project.templ             — update: link to /profile instead of /knowledge
  web/handlers/pipeline.go                — update: build profile string from profile_sections
  web/handlers/brainstorm.go              — update: build profile string from profile_sections
  internal/store/brainstorm.go            — add section column to brainstorm_chats
  cmd/server/main.go                      — wire ProfileAgent + profile handler, remove voice/tone agents

Delete:
  internal/agents/voice.go
  internal/agents/voice_test.go
  internal/agents/tone.go
  internal/agents/tone_test.go
  internal/store/knowledge.go
  internal/store/knowledge_test.go
  web/handlers/knowledge.go
  web/templates/knowledge.templ
```

---

## Chunk 1: Schema + Store Layer

### Task 1: Rewrite migration and clean up projects store

**Files:**
- Modify: `migrations/001_initial.sql`
- Modify: `internal/store/projects.go`
- Modify: `internal/store/db_test.go`

- [ ] **Step 1: Rewrite migrations/001_initial.sql**

Remove `knowledge_items` table. Remove `voice_profile` and `tone_profile` columns from `projects`. Add new profile tables. Update `agent_runs.agent_type` CHECK to include `'profile'`. Add `section` column to `brainstorm_chats`.

```sql
-- +goose Up
CREATE TABLE projects (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE profile_sections (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT NOT NULL CHECK(section IN (
        'business','audience','voice','tone','strategy',
        'pillars','guidelines','competitors','inspiration','offers'
    )),
    content TEXT NOT NULL DEFAULT '{}',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, section)
);

CREATE TABLE section_inputs (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT,
    title TEXT,
    content TEXT NOT NULL,
    source_url TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_section_inputs_project ON section_inputs(project_id, section);

CREATE TABLE section_proposals (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT NOT NULL,
    proposed_content TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
    rejection_reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_section_proposals_project ON section_proposals(project_id, section);

CREATE TABLE project_references (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title TEXT,
    content TEXT NOT NULL,
    source_url TEXT,
    saved_by TEXT NOT NULL DEFAULT 'user' CHECK(saved_by IN ('user','agent')),
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
    agent_type TEXT NOT NULL CHECK(agent_type IN ('profile','idea','content')),
    prompt_summary TEXT,
    response TEXT NOT NULL,
    content_piece_id INTEGER REFERENCES content_pieces(id),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE brainstorm_chats (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title TEXT,
    section TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE brainstorm_messages (
    id INTEGER PRIMARY KEY,
    chat_id INTEGER NOT NULL REFERENCES brainstorm_chats(id) ON DELETE CASCADE,
    role TEXT NOT NULL CHECK(role IN ('user','assistant')),
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE settings (
    key TEXT PRIMARY KEY,
    value TEXT NOT NULL
);

INSERT INTO settings (key, value) VALUES ('model_content', '');
INSERT INTO settings (key, value) VALUES ('model_ideation', '');

-- +goose Down
DROP TABLE settings;
DROP TABLE brainstorm_messages;
DROP TABLE brainstorm_chats;
DROP TABLE agent_runs;
DROP TABLE content_pieces;
DROP TABLE pipeline_runs;
DROP TABLE templates;
DROP TABLE project_references;
DROP TABLE section_proposals;
DROP TABLE section_inputs;
DROP TABLE profile_sections;
DROP TABLE projects;
```

- [ ] **Step 2: Update Project struct and queries in projects.go**

Remove `VoiceProfile`, `ToneProfile` fields from `Project` struct. Remove `UpdateVoiceProfile`, `UpdateToneProfile` methods. Update `GetProject` and `ListProjects` SQL to not select those columns.

```go
type Project struct {
	ID          int64
	Name        string
	Description string
	CreatedAt   time.Time
	UpdatedAt   time.Time
}

func (q *Queries) GetProject(id int64) (*Project, error) {
	p := &Project{}
	err := q.db.QueryRow(
		"SELECT id, name, COALESCE(description,''), created_at, updated_at FROM projects WHERE id = ?", id,
	).Scan(&p.ID, &p.Name, &p.Description, &p.CreatedAt, &p.UpdatedAt)
	if err != nil {
		return nil, err
	}
	return p, nil
}

func (q *Queries) ListProjects() ([]Project, error) {
	rows, err := q.db.Query("SELECT id, name, COALESCE(description,''), created_at, updated_at FROM projects ORDER BY created_at DESC")
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var projects []Project
	for rows.Next() {
		var p Project
		if err := rows.Scan(&p.ID, &p.Name, &p.Description, &p.CreatedAt, &p.UpdatedAt); err != nil {
			return nil, err
		}
		projects = append(projects, p)
	}
	return projects, rows.Err()
}
```

- [ ] **Step 3: Delete old files**

```bash
rm internal/store/knowledge.go internal/store/knowledge_test.go
rm internal/agents/voice.go internal/agents/voice_test.go
rm internal/agents/tone.go internal/agents/tone_test.go
rm web/handlers/knowledge.go web/templates/knowledge.templ
```

- [ ] **Step 4: Delete the SQLite DB so it gets recreated with new schema**

```bash
rm -f marketminded.db
```

- [ ] **Step 5: Verify tests compile (some will fail — that's expected, we'll fix in subsequent tasks)**

```bash
go test ./internal/store/ -v
# Expected: PASS (knowledge tests deleted, projects tests updated)
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "refactor: rewrite schema for profile system, remove knowledge/voice/tone"
```

---

### Task 2: Profile store layer

**Files:**
- Create: `internal/store/profile.go`
- Create: `internal/store/profile_test.go`

- [ ] **Step 1: Write tests**

```go
// internal/store/profile_test.go
package store

import "testing"

func TestProfileSectionUpsert(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	err := q.UpsertProfileSection(p.ID, "voice", `{"personality":"bold"}`)
	if err != nil {
		t.Fatalf("upsert: %v", err)
	}

	section, err := q.GetProfileSection(p.ID, "voice")
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	if section.Content != `{"personality":"bold"}` {
		t.Errorf("unexpected content: %s", section.Content)
	}

	// Update existing
	q.UpsertProfileSection(p.ID, "voice", `{"personality":"confident"}`)
	section, _ = q.GetProfileSection(p.ID, "voice")
	if section.Content != `{"personality":"confident"}` {
		t.Errorf("expected updated content, got: %s", section.Content)
	}
}

func TestListProfileSections(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.UpsertProfileSection(p.ID, "voice", `{"personality":"bold"}`)
	q.UpsertProfileSection(p.ID, "tone", `{"formality":"casual"}`)

	sections, err := q.ListProfileSections(p.ID)
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(sections) != 2 {
		t.Errorf("expected 2, got %d", len(sections))
	}
}

func TestSectionInputsCRUD(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	input, err := q.CreateSectionInput(p.ID, "voice", "Blog sample", "We build great things.", "")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if input.ID == 0 {
		t.Fatal("expected non-zero ID")
	}

	inputs, _ := q.ListSectionInputs(p.ID, "voice")
	if len(inputs) != 1 {
		t.Errorf("expected 1, got %d", len(inputs))
	}

	// General inputs (nil section)
	q.CreateSectionInput(p.ID, "", "General note", "Some general content", "")
	allInputs, _ := q.ListSectionInputs(p.ID, "")
	if len(allInputs) != 2 {
		t.Errorf("expected 2 total, got %d", len(allInputs))
	}
}

func TestProposalWorkflow(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	prop, err := q.CreateProposal(p.ID, "voice", `{"personality":"bold"}`)
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if prop.Status != "pending" {
		t.Errorf("expected pending, got %s", prop.Status)
	}

	err = q.ApproveProposal(prop.ID)
	if err != nil {
		t.Fatalf("approve: %v", err)
	}

	got, _ := q.GetProposal(prop.ID)
	if got.Status != "approved" {
		t.Errorf("expected approved, got %s", got.Status)
	}

	// Verify section was updated
	section, _ := q.GetProfileSection(p.ID, "voice")
	if section.Content != `{"personality":"bold"}` {
		t.Errorf("section not updated: %s", section.Content)
	}
}

func TestRejectProposal(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	prop, _ := q.CreateProposal(p.ID, "voice", `{"personality":"boring"}`)
	err := q.RejectProposal(prop.ID, "Too generic, we're more edgy")
	if err != nil {
		t.Fatalf("reject: %v", err)
	}

	got, _ := q.GetProposal(prop.ID)
	if got.Status != "rejected" {
		t.Errorf("expected rejected, got %s", got.Status)
	}
	if got.RejectionReason != "Too generic, we're more edgy" {
		t.Errorf("unexpected reason: %s", got.RejectionReason)
	}
}

func TestProjectReferences(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	ref, err := q.CreateReference(p.ID, "Good blog", "Content here", "https://example.com", "user")
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if ref.ID == 0 {
		t.Fatal("expected non-zero ID")
	}

	refs, _ := q.ListReferences(p.ID)
	if len(refs) != 1 {
		t.Errorf("expected 1, got %d", len(refs))
	}
}
```

- [ ] **Step 2: Implement profile.go**

```go
// internal/store/profile.go
package store

import "time"

type ProfileSection struct {
	ID        int64
	ProjectID int64
	Section   string
	Content   string
	UpdatedAt time.Time
}

type SectionInput struct {
	ID        int64
	ProjectID int64
	Section   string
	Title     string
	Content   string
	SourceURL string
	CreatedAt time.Time
}

type SectionProposal struct {
	ID              int64
	ProjectID       int64
	Section         string
	ProposedContent string
	Status          string
	RejectionReason string
	CreatedAt       time.Time
}

type ProjectReference struct {
	ID        int64
	ProjectID int64
	Title     string
	Content   string
	SourceURL string
	SavedBy   string
	CreatedAt time.Time
}

// Profile sections

func (q *Queries) UpsertProfileSection(projectID int64, section, content string) error {
	_, err := q.db.Exec(
		`INSERT INTO profile_sections (project_id, section, content) VALUES (?, ?, ?)
		 ON CONFLICT(project_id, section) DO UPDATE SET content = ?, updated_at = CURRENT_TIMESTAMP`,
		projectID, section, content, content,
	)
	return err
}

func (q *Queries) GetProfileSection(projectID int64, section string) (*ProfileSection, error) {
	s := &ProfileSection{}
	err := q.db.QueryRow(
		"SELECT id, project_id, section, content, updated_at FROM profile_sections WHERE project_id = ? AND section = ?",
		projectID, section,
	).Scan(&s.ID, &s.ProjectID, &s.Section, &s.Content, &s.UpdatedAt)
	return s, err
}

func (q *Queries) ListProfileSections(projectID int64) ([]ProfileSection, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, section, content, updated_at FROM profile_sections WHERE project_id = ? ORDER BY section",
		projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var sections []ProfileSection
	for rows.Next() {
		var s ProfileSection
		if err := rows.Scan(&s.ID, &s.ProjectID, &s.Section, &s.Content, &s.UpdatedAt); err != nil {
			return nil, err
		}
		sections = append(sections, s)
	}
	return sections, rows.Err()
}

// BuildProfileString serializes all sections into a single string for prompt injection.
func (q *Queries) BuildProfileString(projectID int64) (string, error) {
	sections, err := q.ListProfileSections(projectID)
	if err != nil {
		return "", err
	}
	var b strings.Builder
	for _, s := range sections {
		if s.Content == "{}" {
			continue
		}
		fmt.Fprintf(&b, "## %s\n%s\n\n", strings.Title(s.Section), s.Content)
	}
	return b.String(), nil
}

// Section inputs

func (q *Queries) CreateSectionInput(projectID int64, section, title, content, sourceURL string) (*SectionInput, error) {
	var sectionVal any
	if section != "" {
		sectionVal = section
	}
	res, err := q.db.Exec(
		"INSERT INTO section_inputs (project_id, section, title, content, source_url) VALUES (?, ?, ?, ?, ?)",
		projectID, sectionVal, title, content, sourceURL,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetSectionInput(id)
}

func (q *Queries) GetSectionInput(id int64) (*SectionInput, error) {
	i := &SectionInput{}
	err := q.db.QueryRow(
		"SELECT id, project_id, COALESCE(section,''), COALESCE(title,''), content, COALESCE(source_url,''), created_at FROM section_inputs WHERE id = ?", id,
	).Scan(&i.ID, &i.ProjectID, &i.Section, &i.Title, &i.Content, &i.SourceURL, &i.CreatedAt)
	return i, err
}

func (q *Queries) ListSectionInputs(projectID int64, section string) ([]SectionInput, error) {
	query := "SELECT id, project_id, COALESCE(section,''), COALESCE(title,''), content, COALESCE(source_url,''), created_at FROM section_inputs WHERE project_id = ?"
	args := []any{projectID}
	if section != "" {
		query += " AND section = ?"
		args = append(args, section)
	}
	query += " ORDER BY created_at DESC"

	rows, err := q.db.Query(query, args...)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var inputs []SectionInput
	for rows.Next() {
		var i SectionInput
		if err := rows.Scan(&i.ID, &i.ProjectID, &i.Section, &i.Title, &i.Content, &i.SourceURL, &i.CreatedAt); err != nil {
			return nil, err
		}
		inputs = append(inputs, i)
	}
	return inputs, rows.Err()
}

func (q *Queries) DeleteSectionInput(id int64) error {
	_, err := q.db.Exec("DELETE FROM section_inputs WHERE id = ?", id)
	return err
}

// Section proposals

func (q *Queries) CreateProposal(projectID int64, section, proposedContent string) (*SectionProposal, error) {
	res, err := q.db.Exec(
		"INSERT INTO section_proposals (project_id, section, proposed_content) VALUES (?, ?, ?)",
		projectID, section, proposedContent,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	return q.GetProposal(id)
}

func (q *Queries) GetProposal(id int64) (*SectionProposal, error) {
	p := &SectionProposal{}
	err := q.db.QueryRow(
		"SELECT id, project_id, section, proposed_content, status, COALESCE(rejection_reason,''), created_at FROM section_proposals WHERE id = ?", id,
	).Scan(&p.ID, &p.ProjectID, &p.Section, &p.ProposedContent, &p.Status, &p.RejectionReason, &p.CreatedAt)
	return p, err
}

func (q *Queries) ListPendingProposals(projectID int64) ([]SectionProposal, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, section, proposed_content, status, COALESCE(rejection_reason,''), created_at FROM section_proposals WHERE project_id = ? AND status = 'pending' ORDER BY created_at ASC",
		projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var proposals []SectionProposal
	for rows.Next() {
		var p SectionProposal
		if err := rows.Scan(&p.ID, &p.ProjectID, &p.Section, &p.ProposedContent, &p.Status, &p.RejectionReason, &p.CreatedAt); err != nil {
			return nil, err
		}
		proposals = append(proposals, p)
	}
	return proposals, rows.Err()
}

func (q *Queries) ListRejectedProposals(projectID int64) ([]SectionProposal, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, section, proposed_content, status, COALESCE(rejection_reason,''), created_at FROM section_proposals WHERE project_id = ? AND status = 'rejected' ORDER BY created_at DESC LIMIT 20",
		projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var proposals []SectionProposal
	for rows.Next() {
		var p SectionProposal
		if err := rows.Scan(&p.ID, &p.ProjectID, &p.Section, &p.ProposedContent, &p.Status, &p.RejectionReason, &p.CreatedAt); err != nil {
			return nil, err
		}
		proposals = append(proposals, p)
	}
	return proposals, rows.Err()
}

func (q *Queries) ApproveProposal(id int64) error {
	prop, err := q.GetProposal(id)
	if err != nil {
		return err
	}
	// Replace section content with proposed content
	if err := q.UpsertProfileSection(prop.ProjectID, prop.Section, prop.ProposedContent); err != nil {
		return err
	}
	_, err = q.db.Exec("UPDATE section_proposals SET status = 'approved' WHERE id = ?", id)
	return err
}

func (q *Queries) ApproveProposalWithEdit(id int64, editedContent string) error {
	prop, err := q.GetProposal(id)
	if err != nil {
		return err
	}
	if err := q.UpsertProfileSection(prop.ProjectID, prop.Section, editedContent); err != nil {
		return err
	}
	_, err = q.db.Exec("UPDATE section_proposals SET status = 'approved', proposed_content = ? WHERE id = ?", editedContent, id)
	return err
}

func (q *Queries) RejectProposal(id int64, reason string) error {
	_, err := q.db.Exec("UPDATE section_proposals SET status = 'rejected', rejection_reason = ? WHERE id = ?", reason, id)
	return err
}

// References

func (q *Queries) CreateReference(projectID int64, title, content, sourceURL, savedBy string) (*ProjectReference, error) {
	res, err := q.db.Exec(
		"INSERT INTO project_references (project_id, title, content, source_url, saved_by) VALUES (?, ?, ?, ?, ?)",
		projectID, title, content, sourceURL, savedBy,
	)
	if err != nil {
		return nil, err
	}
	id, _ := res.LastInsertId()
	r := &ProjectReference{}
	err = q.db.QueryRow(
		"SELECT id, project_id, COALESCE(title,''), content, COALESCE(source_url,''), saved_by, created_at FROM project_references WHERE id = ?", id,
	).Scan(&r.ID, &r.ProjectID, &r.Title, &r.Content, &r.SourceURL, &r.SavedBy, &r.CreatedAt)
	return r, err
}

func (q *Queries) ListReferences(projectID int64) ([]ProjectReference, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, COALESCE(title,''), content, COALESCE(source_url,''), saved_by, created_at FROM project_references WHERE project_id = ? ORDER BY created_at DESC",
		projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var refs []ProjectReference
	for rows.Next() {
		var r ProjectReference
		if err := rows.Scan(&r.ID, &r.ProjectID, &r.Title, &r.Content, &r.SourceURL, &r.SavedBy, &r.CreatedAt); err != nil {
			return nil, err
		}
		refs = append(refs, r)
	}
	return refs, rows.Err()
}

func (q *Queries) DeleteReference(id int64) error {
	_, err := q.db.Exec("DELETE FROM project_references WHERE id = ?", id)
	return err
}
```

Note: `BuildProfileString` uses `strings` and `fmt` — add those imports. Also `strings.Title` is deprecated; use a simple manual title case or just uppercase the first letter:

```go
func sectionTitle(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
```

Use `sectionTitle(s.Section)` instead of `strings.Title(s.Section)`.

- [ ] **Step 3: Run tests**

```bash
go test ./internal/store/ -v
# Expected: PASS
```

- [ ] **Step 4: Commit**

```bash
git add internal/store/profile.go internal/store/profile_test.go
git commit -m "feat: add profile store layer (sections, inputs, proposals, references)"
```

---

## Chunk 2: Profile Agent + Agent Interface Updates

### Task 3: Profile Analysis Agent

**Files:**
- Create: `internal/agents/profile.go`
- Create: `internal/agents/profile_test.go`

- [ ] **Step 1: Write test**

```go
// internal/agents/profile_test.go
package agents

import (
	"context"
	"testing"
)

func TestProfileAgent_Analyze(t *testing.T) {
	mockResponse := `[
		{"section":"voice","content":{"personality":"bold","vocabulary":"technical"}},
		{"section":"audience","content":{"demographics":"developers","pain_points":["scaling","hiring"]}}
	]`
	agent := NewProfileAgent(&mockAI{response: mockResponse}, testModel)

	proposals, err := agent.Analyze(context.Background(), ProfileAnalysisInput{
		Inputs:           []string{"We build scalable web apps. Our clients are CTOs."},
		ExistingSections: map[string]string{},
		Rejections:       []string{},
	})
	if err != nil {
		t.Fatalf("analyze: %v", err)
	}
	if len(proposals) == 0 {
		t.Fatal("expected proposals")
	}
}
```

- [ ] **Step 2: Implement**

```go
// internal/agents/profile.go
package agents

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/types"
)

type ProfileAgent struct {
	ai    types.AIClient
	model func() string
}

func NewProfileAgent(ai types.AIClient, model func() string) *ProfileAgent {
	return &ProfileAgent{ai: ai, model: model}
}

type ProfileAnalysisInput struct {
	Inputs           []string          // raw inputs (content from section_inputs)
	ExistingSections map[string]string // section name → current JSON content
	Rejections       []string          // "section: reason" strings from past rejections
}

type ProfileProposal struct {
	Section string          `json:"section"`
	Content json.RawMessage `json:"content"`
}

func (a *ProfileAgent) Analyze(ctx context.Context, input ProfileAnalysisInput) ([]ProfileProposal, error) {
	inputsText := strings.Join(input.Inputs, "\n\n---\n\n")

	var existing strings.Builder
	for section, content := range input.ExistingSections {
		if content != "{}" {
			fmt.Fprintf(&existing, "## %s\n%s\n\n", section, content)
		}
	}

	rejectionsText := "None."
	if len(input.Rejections) > 0 {
		rejectionsText = strings.Join(input.Rejections, "\n")
	}

	messages := []types.Message{
		{
			Role: "system",
			Content: `You are a brand profile analyst. Given raw content inputs about a business, analyze them and propose structured profile sections.

Available sections: business, audience, voice, tone, strategy, pillars, guidelines, competitors, inspiration, offers.

Rules:
- Only propose sections where you have enough signal from the inputs
- If a section already has content, incorporate it into your proposal (don't lose existing data)
- Account for past rejections — if the user rejected something, adjust accordingly
- Each section's content should be a JSON object with descriptive fields appropriate to that section

Return a JSON array of objects with "section" and "content" fields. Return ONLY valid JSON, no markdown.`,
		},
		{
			Role: "user",
			Content: fmt.Sprintf("Raw inputs:\n\n%s\n\nExisting profile:\n%s\nPast rejections:\n%s\n\nAnalyze and propose profile sections.",
				inputsText, existing.String(), rejectionsText),
		},
	}

	response, err := a.ai.Complete(ctx, a.model(), messages)
	if err != nil {
		return nil, err
	}

	// Strip markdown code fences if present
	response = strings.TrimSpace(response)
	response = strings.TrimPrefix(response, "```json")
	response = strings.TrimPrefix(response, "```")
	response = strings.TrimSuffix(response, "```")
	response = strings.TrimSpace(response)

	var proposals []ProfileProposal
	if err := json.Unmarshal([]byte(response), &proposals); err != nil {
		return nil, fmt.Errorf("failed to parse agent response: %w\nResponse: %s", err, response)
	}

	return proposals, nil
}
```

- [ ] **Step 3: Run tests**

```bash
go test ./internal/agents/ -v
# Expected: PASS
```

- [ ] **Step 4: Commit**

```bash
git add internal/agents/profile.go internal/agents/profile_test.go
git commit -m "feat: add ProfileAgent for analyzing inputs into profile proposals"
```

---

### Task 4: Update Content and Idea agents to use Profile string

**Files:**
- Modify: `internal/agents/content.go`
- Modify: `internal/agents/content_test.go`
- Modify: `internal/agents/idea.go`
- Modify: `internal/agents/idea_test.go`

- [ ] **Step 1: Update content.go**

Replace `VoiceProfile` and `ToneProfile` fields with single `Profile` field on `PillarInput` and `SocialInput`:

```go
type PillarInput struct {
	Topic      string
	Profile    string   // serialized profile sections
	ContentLog []string
}

type SocialInput struct {
	PillarContent string
	Platform      string
	Profile       string // serialized profile sections
	TemplateSlots string
}
```

Update `pillarMessages` to use `input.Profile` instead of separate voice/tone:

```go
func (a *ContentAgent) pillarMessages(input PillarInput) []types.Message {
	contentLog := "No previous content."
	if len(input.ContentLog) > 0 {
		contentLog = strings.Join(input.ContentLog, "\n")
	}

	return []types.Message{
		{
			Role: "system",
			Content: fmt.Sprintf(`You are an expert blog writer. Write a comprehensive, engaging blog post on the given topic.

Client Profile:
%s

Guidelines:
- Write in the brand's voice and tone as described in the profile
- Use markdown formatting
- Include a compelling introduction with a hook
- Break into clear sections with headers
- Include actionable takeaways
- End with a strong conclusion
- Aim for 1200-1800 words
- Do NOT repeat themes from the content log below`, input.Profile),
		},
		{
			Role: "user",
			Content: fmt.Sprintf("Topic: %s\n\nPrevious content (for continuity, don't repeat):\n%s\n\nWrite the blog post.",
				input.Topic, contentLog),
		},
	}
}
```

Update `socialMessages` similarly — replace `input.VoiceProfile` and `input.ToneProfile` with `input.Profile`.

- [ ] **Step 2: Update idea.go**

Replace `VoiceProfile` with `Profile` on `IdeaInput`:

```go
type IdeaInput struct {
	Niche      string
	ContentLog []string
	Profile    string
}
```

Update `ideaMessages` to use `input.Profile` instead of `input.VoiceProfile`.

- [ ] **Step 3: Update tests**

Update all test calls to use `Profile: "test profile"` instead of `VoiceProfile`/`ToneProfile`.

- [ ] **Step 4: Run tests**

```bash
go test ./internal/agents/ -v
# Expected: PASS
```

- [ ] **Step 5: Commit**

```bash
git add internal/agents/content.go internal/agents/content_test.go internal/agents/idea.go internal/agents/idea_test.go
git commit -m "refactor: update content and idea agents to use unified Profile string"
```

---

## Chunk 3: Update Handlers (Pipeline, Brainstorm, Main)

### Task 5: Update pipeline handler to use profile

**Files:**
- Modify: `web/handlers/pipeline.go`

- [ ] **Step 1: Update pipeline handler**

Replace the voice/tone profile reading:

```go
// Old:
voiceProfile := ""
if project.VoiceProfile != nil {
    voiceProfile = *project.VoiceProfile
}
toneProfile := ""
if project.ToneProfile != nil {
    toneProfile = *project.ToneProfile
}
```

With:

```go
profile, _ := h.queries.BuildProfileString(projectID)
```

Then update all agent calls to use `Profile: profile` instead of `VoiceProfile`/`ToneProfile`.

The `PipelineHandler` struct needs access to `*store.Queries` (it already has it via `h.queries`).

- [ ] **Step 2: Build to verify**

```bash
go build ./...
# Expected: compiles
```

- [ ] **Step 3: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "refactor: pipeline handler uses profile string from profile_sections"
```

---

### Task 6: Update brainstorm handler to use profile

**Files:**
- Modify: `web/handlers/brainstorm.go`
- Modify: `internal/store/brainstorm.go`

- [ ] **Step 1: Update brainstorm store for section column**

Add `Section` field to `BrainstormChat` struct. Update `CreateBrainstormChat` to accept section parameter. Update queries to include section column.

```go
type BrainstormChat struct {
	ID        int64
	ProjectID int64
	Title     string
	Section   string  // empty for regular brainstorm, set for refinement chats
	CreatedAt time.Time
}

func (q *Queries) CreateBrainstormChat(projectID int64, title string, section string) (*BrainstormChat, error) {
	var sectionVal any
	if section != "" {
		sectionVal = section
	}
	res, err := q.db.Exec("INSERT INTO brainstorm_chats (project_id, title, section) VALUES (?, ?, ?)", projectID, title, sectionVal)
	// ...
}
```

- [ ] **Step 2: Update brainstorm handler**

Replace voice/tone profile injection with `BuildProfileString`:

```go
profile, _ := h.queries.BuildProfileString(projectID)

systemPrompt := fmt.Sprintf(`You are a content brainstorming assistant for the project "%s".

Client Profile:
%s

Help the user brainstorm content ideas, angles, and strategies.`, project.Name, profile)
```

Update `createChat` call to pass empty section for regular brainstorm chats.

- [ ] **Step 3: Build to verify**

```bash
go build ./...
```

- [ ] **Step 4: Commit**

```bash
git add web/handlers/brainstorm.go internal/store/brainstorm.go
git commit -m "refactor: brainstorm handler uses profile string, add section to chats"
```

---

### Task 7: Update main.go — wire ProfileAgent, remove voice/tone agents

**Files:**
- Modify: `cmd/server/main.go`
- Modify: `web/templates/project.templ`

- [ ] **Step 1: Update main.go**

Remove `voiceAgent`, `toneAgent` creation. Add `profileAgent`. Remove `knowledgeHandler`. Add `profileHandler`. Update route switch to use `"profile"` instead of `"knowledge"`.

```go
// Remove:
voiceAgent := agents.NewVoiceAgent(aiClient, contentModel)
toneAgent := agents.NewToneAgent(aiClient, contentModel)
knowledgeHandler := handlers.NewKnowledgeHandler(queries, voiceAgent, toneAgent)

// Add:
profileAgent := agents.NewProfileAgent(aiClient, contentModel)
profileHandler := handlers.NewProfileHandler(queries, profileAgent)

// Update route switch:
case strings.HasPrefix(rest, "profile"):
    profileHandler.Handle(w, r, projectID, rest)
```

- [ ] **Step 2: Update project.templ**

Change "Manage Knowledge" link to point to `/projects/{id}/profile` and update labels.

- [ ] **Step 3: Build (will fail until profile handler exists — that's Task 8)**

- [ ] **Step 4: Commit**

```bash
git add cmd/server/main.go web/templates/project.templ
git commit -m "refactor: wire ProfileAgent and profile routes, remove voice/tone agents"
```

---

## Chunk 4: Profile UI (Handler + Template)

### Task 8: Profile handler and template

**Files:**
- Create: `web/handlers/profile.go`
- Create: `web/templates/profile.templ`

This is the biggest task. The profile handler needs to:
- List sections with their content and inputs
- Accept new inputs (with section tagging)
- Trigger analysis (call ProfileAgent, create proposals)
- Show pending proposals with approve/reject/edit actions
- Handle approval, rejection, and edit+approve
- Start refinement chats (link to brainstorm with section scope)
- Manage references

- [ ] **Step 1: Create profile handler**

```go
// web/handlers/profile.go
package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"

	"github.com/zanfridau/marketminded/internal/agents"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

var allSections = []string{
	"business", "audience", "voice", "tone", "strategy",
	"pillars", "guidelines", "competitors", "inspiration", "offers",
}

type ProfileHandler struct {
	queries      *store.Queries
	profileAgent *agents.ProfileAgent
}

func NewProfileHandler(q *store.Queries, pa *agents.ProfileAgent) *ProfileHandler {
	return &ProfileHandler{queries: q, profileAgent: pa}
}

func (h *ProfileHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "profile" && r.Method == "GET":
		h.show(w, r, projectID)
	case rest == "profile/inputs" && r.Method == "POST":
		h.addInput(w, r, projectID)
	case rest == "profile/analyze" && r.Method == "POST":
		h.analyze(w, r, projectID)
	case strings.HasSuffix(rest, "/approve") && r.Method == "POST":
		h.approveProposal(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/reject") && r.Method == "POST":
		h.rejectProposal(w, r, projectID, rest)
	case rest == "profile/references" && r.Method == "POST":
		h.addReference(w, r, projectID)
	case strings.HasSuffix(rest, "/delete-input") && r.Method == "POST":
		h.deleteInput(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/delete-ref") && r.Method == "POST":
		h.deleteRef(w, r, projectID, rest)
	default:
		http.NotFound(w, r)
	}
}

func (h *ProfileHandler) show(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	activeTab := r.URL.Query().Get("tab")
	if activeTab == "" {
		activeTab = "business"
	}

	sections, _ := h.queries.ListProfileSections(projectID)
	sectionMap := make(map[string]string)
	for _, s := range sections {
		sectionMap[s.Section] = s.Content
	}

	inputs, _ := h.queries.ListSectionInputs(projectID, "")
	proposals, _ := h.queries.ListPendingProposals(projectID)
	refs, _ := h.queries.ListReferences(projectID)

	sectionViews := make([]templates.ProfileSectionView, len(allSections))
	for i, name := range allSections {
		content := sectionMap[name]
		if content == "" {
			content = "{}"
		}

		var sectionInputs []templates.SectionInputView
		for _, inp := range inputs {
			if inp.Section == name || inp.Section == "" {
				sectionInputs = append(sectionInputs, templates.SectionInputView{
					ID: inp.ID, Title: inp.Title, Content: inp.Content, Section: inp.Section,
				})
			}
		}

		var sectionProposals []templates.ProposalView
		for _, p := range proposals {
			if p.Section == name {
				sectionProposals = append(sectionProposals, templates.ProposalView{
					ID: p.ID, Section: p.Section, Content: p.ProposedContent,
				})
			}
		}

		sectionViews[i] = templates.ProfileSectionView{
			Name:      name,
			Content:   content,
			HasData:   content != "{}",
			Inputs:    sectionInputs,
			Proposals: sectionProposals,
		}
	}

	refViews := make([]templates.ReferenceView, len(refs))
	for i, r := range refs {
		refViews[i] = templates.ReferenceView{ID: r.ID, Title: r.Title, Content: r.Content, SourceURL: r.SourceURL, SavedBy: r.SavedBy}
	}

	templates.ProfilePage(templates.ProfilePageData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Sections:    sectionViews,
		References:  refViews,
		ActiveTab:   activeTab,
		AllSections: allSections,
	}).Render(r.Context(), w)
}

func (h *ProfileHandler) addInput(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	section := r.FormValue("section")
	title := r.FormValue("title")
	content := r.FormValue("content")
	sourceURL := r.FormValue("source_url")

	if content == "" {
		http.Error(w, "Content is required", http.StatusBadRequest)
		return
	}

	h.queries.CreateSectionInput(projectID, section, title, content, sourceURL)
	tab := section
	if tab == "" {
		tab = "business"
	}
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile?tab=%s", projectID, tab), http.StatusSeeOther)
}

func (h *ProfileHandler) analyze(w http.ResponseWriter, r *http.Request, projectID int64) {
	allInputs, _ := h.queries.ListSectionInputs(projectID, "")
	var inputTexts []string
	for _, inp := range allInputs {
		label := inp.Title
		if label == "" {
			label = "Input"
		}
		if inp.Section != "" {
			label += " [" + inp.Section + "]"
		}
		inputTexts = append(inputTexts, fmt.Sprintf("%s:\n%s", label, inp.Content))
	}

	sections, _ := h.queries.ListProfileSections(projectID)
	existing := make(map[string]string)
	for _, s := range sections {
		existing[s.Section] = s.Content
	}

	rejections, _ := h.queries.ListRejectedProposals(projectID)
	var rejectionTexts []string
	for _, r := range rejections {
		rejectionTexts = append(rejectionTexts, fmt.Sprintf("%s: %s", r.Section, r.RejectionReason))
	}

	proposals, err := h.profileAgent.Analyze(r.Context(), agents.ProfileAnalysisInput{
		Inputs:           inputTexts,
		ExistingSections: existing,
		Rejections:       rejectionTexts,
	})
	if err != nil {
		http.Error(w, "Analysis failed: "+err.Error(), http.StatusInternalServerError)
		return
	}

	for _, p := range proposals {
		contentJSON, _ := json.Marshal(p.Content)
		h.queries.CreateProposal(projectID, p.Section, string(contentJSON))
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile", projectID), http.StatusSeeOther)
}

func (h *ProfileHandler) approveProposal(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	id := extractID(rest, "approve")
	r.ParseForm()
	editedContent := r.FormValue("edited_content")
	if editedContent != "" {
		h.queries.ApproveProposalWithEdit(id, editedContent)
	} else {
		h.queries.ApproveProposal(id)
	}
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile", projectID), http.StatusSeeOther)
}

func (h *ProfileHandler) rejectProposal(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	id := extractID(rest, "reject")
	r.ParseForm()
	reason := r.FormValue("reason")
	h.queries.RejectProposal(id, reason)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile", projectID), http.StatusSeeOther)
}

func (h *ProfileHandler) addReference(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	h.queries.CreateReference(projectID, r.FormValue("title"), r.FormValue("content"), r.FormValue("source_url"), "user")
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile?tab=references", projectID), http.StatusSeeOther)
}

func (h *ProfileHandler) deleteInput(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	id := extractID(rest, "delete-input")
	h.queries.DeleteSectionInput(id)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile", projectID), http.StatusSeeOther)
}

func (h *ProfileHandler) deleteRef(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	id := extractID(rest, "delete-ref")
	h.queries.DeleteReference(id)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile?tab=references", projectID), http.StatusSeeOther)
}

func extractID(rest, suffix string) int64 {
	// rest = "profile/proposals/123/approve"
	parts := strings.Split(rest, "/")
	for i, p := range parts {
		if p == suffix && i > 0 {
			id, _ := strconv.ParseInt(parts[i-1], 10, 64)
			return id
		}
	}
	return 0
}
```

- [ ] **Step 2: Create profile.templ**

Create the template with section tabs, content display, input form, proposal cards, and references tab. This should follow the existing patterns (vanilla HTML + forms, no Alpine for critical paths).

The template needs these types:

```go
type ProfilePageData struct {
    ProjectID   int64
    ProjectName string
    Sections    []ProfileSectionView
    References  []ReferenceView
    ActiveTab   string
    AllSections []string
}

type ProfileSectionView struct {
    Name      string
    Content   string
    HasData   bool
    Inputs    []SectionInputView
    Proposals []ProposalView
}

type SectionInputView struct {
    ID      int64
    Title   string
    Content string
    Section string
}

type ProposalView struct {
    ID      int64
    Section string
    Content string
}

type ReferenceView struct {
    ID        int64
    Title     string
    Content   string
    SourceURL string
    SavedBy   string
}
```

Each section tab shows:
- Current approved content (formatted JSON in a pre block)
- Pending proposals with Approve / Reject (with reason input) / Edit & Approve buttons
- Inputs tagged to this section with delete buttons
- "Refine" link that creates a brainstorm chat with `section` set
- Add input form at the bottom

References tab shows the reference list with add/delete.

Header has the "Analyze" button.

- [ ] **Step 3: Generate templ, build, verify**

```bash
templ generate ./web/templates/
go build ./...
```

- [ ] **Step 4: Delete old DB file and test manually**

```bash
rm -f marketminded.db
```

- [ ] **Step 5: Commit**

```bash
git add web/handlers/profile.go web/templates/profile.templ
git commit -m "feat: add profile page with section tabs, proposals, and analysis"
```

---

### Task 9: Full build verification and cleanup

- [ ] **Step 1: Run all tests**

```bash
go test ./... -v
# Expected: all PASS
```

- [ ] **Step 2: Build and manual smoke test**

```bash
templ generate ./web/templates/
go build -o server ./cmd/server/
rm -f marketminded.db
OPENROUTER_API_KEY=... BRAVE_API_KEY=... ./server
```

Visit http://localhost:8080. Create a project. Go to Profile. Add inputs. Click Analyze. Approve/reject proposals.

- [ ] **Step 3: Fix any remaining issues**

- [ ] **Step 4: Final commit**

```bash
git add -A
git commit -m "feat: complete profile system — analysis, proposals, approval workflow"
```
