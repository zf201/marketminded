# Audience Persona Cards Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single-text-blob audience section with structured persona cards — AI-generated from product context and web research, with per-card accept/reject, individual editing, and pipeline integration.

**Architecture:** New `audience_personas` table with dedicated store methods. Audience handler split into its own file (`audience.go`) to keep profile handler focused. Build modal uses `StreamWithTools` with `web_search` + `submit_personas` tools. Profile page template extended with audience-specific card rendering. `BuildProfileString` updated to format personas as structured markdown.

**Tech Stack:** Go, SQLite (Goose), templ, vanilla JS, DaisyUI/Tailwind, SSE streaming with tool calls via OpenRouter API, Brave Search API.

---

### Task 1: Database Migration

**Files:**
- Create: `migrations/012_audience_personas.sql`

- [ ] **Step 1: Write the migration**

```sql
-- +goose Up
CREATE TABLE audience_personas (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    description TEXT NOT NULL,
    pain_points TEXT NOT NULL,
    push TEXT NOT NULL,
    pull TEXT NOT NULL,
    anxiety TEXT NOT NULL,
    habit TEXT NOT NULL,
    role TEXT NOT NULL DEFAULT '',
    demographics TEXT NOT NULL DEFAULT '',
    company_info TEXT NOT NULL DEFAULT '',
    content_habits TEXT NOT NULL DEFAULT '',
    buying_triggers TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- +goose Down
DROP TABLE audience_personas;
```

- [ ] **Step 2: Run migration**

Run: `make restart`
Expected: Server starts without errors.

- [ ] **Step 3: Commit**

```bash
git add migrations/012_audience_personas.sql
git commit -m "feat: add audience_personas table"
```

---

### Task 2: Audience Store Methods

**Files:**
- Create: `internal/store/audience.go`
- Create: `internal/store/audience_test.go`
- Modify: `internal/store/interfaces.go`
- Modify: `internal/store/profile.go` (BuildProfileString)

- [ ] **Step 1: Write the failing tests**

```go
// internal/store/audience_test.go
package store

import (
	"testing"
)

func TestCreateAudiencePersona(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	persona, err := q.CreateAudiencePersona(p.ID, AudiencePersona{
		Label:       "Startup CTO",
		Description: "Technical leader at an early-stage SaaS company.",
		PainPoints:  "Can't find engineers.",
		Push:        "Frustrated with current process.",
		Pull:        "Wants automation.",
		Anxiety:     "Worried about cost.",
		Habit:       "Using spreadsheets.",
		Role:        "CTO",
		SortOrder:   0,
	})
	if err != nil {
		t.Fatalf("create: %v", err)
	}
	if persona.ID == 0 {
		t.Fatal("expected non-zero ID")
	}
	if persona.Label != "Startup CTO" {
		t.Errorf("expected 'Startup CTO', got %q", persona.Label)
	}
}

func TestListAudiencePersonas(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.CreateAudiencePersona(p.ID, AudiencePersona{Label: "CTO", Description: "d", PainPoints: "p", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h", SortOrder: 1})
	q.CreateAudiencePersona(p.ID, AudiencePersona{Label: "Developer", Description: "d", PainPoints: "p", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h", SortOrder: 0})

	personas, err := q.ListAudiencePersonas(p.ID)
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(personas) != 2 {
		t.Fatalf("expected 2, got %d", len(personas))
	}
	// Should be ordered by sort_order
	if personas[0].Label != "Developer" {
		t.Errorf("expected Developer first (sort_order 0), got %q", personas[0].Label)
	}
}

func TestUpdateAudiencePersona(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	persona, _ := q.CreateAudiencePersona(p.ID, AudiencePersona{Label: "CTO", Description: "d", PainPoints: "p", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h"})

	err := q.UpdateAudiencePersona(persona.ID, AudiencePersona{Label: "VP Engineering", Description: "updated", PainPoints: "p", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h"})
	if err != nil {
		t.Fatalf("update: %v", err)
	}

	updated, _ := q.GetAudiencePersona(persona.ID)
	if updated.Label != "VP Engineering" {
		t.Errorf("expected 'VP Engineering', got %q", updated.Label)
	}
}

func TestDeleteAudiencePersona(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	persona, _ := q.CreateAudiencePersona(p.ID, AudiencePersona{Label: "CTO", Description: "d", PainPoints: "p", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h"})

	err := q.DeleteAudiencePersona(persona.ID)
	if err != nil {
		t.Fatalf("delete: %v", err)
	}

	personas, _ := q.ListAudiencePersonas(p.ID)
	if len(personas) != 0 {
		t.Errorf("expected 0 after delete, got %d", len(personas))
	}
}

func TestBuildAudienceString(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.CreateAudiencePersona(p.ID, AudiencePersona{
		Label: "Startup CTO", Description: "Technical leader.", PainPoints: "Hiring.",
		Push: "Frustrated.", Pull: "Wants speed.", Anxiety: "Cost.", Habit: "Spreadsheets.",
		Role: "CTO", SortOrder: 0,
	})

	s, err := q.BuildAudienceString(p.ID)
	if err != nil {
		t.Fatalf("build: %v", err)
	}
	if s == "" {
		t.Fatal("expected non-empty audience string")
	}
	if !contains(s, "Startup CTO") || !contains(s, "Hiring") || !contains(s, "CTO") {
		t.Errorf("expected persona content in string, got: %s", s)
	}
}

func contains(s, sub string) bool {
	return len(s) >= len(sub) && (s == sub || len(s) > 0 && containsHelper(s, sub))
}

func containsHelper(s, sub string) bool {
	for i := 0; i <= len(s)-len(sub); i++ {
		if s[i:i+len(sub)] == sub {
			return true
		}
	}
	return false
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/store/ -run TestCreateAudience -v`
Expected: FAIL — undefined types and methods.

- [ ] **Step 3: Implement the store**

Create `internal/store/audience.go`:

```go
package store

import (
	"fmt"
	"strings"
	"time"
)

type AudiencePersona struct {
	ID             int64
	ProjectID      int64
	Label          string
	Description    string
	PainPoints     string
	Push           string
	Pull           string
	Anxiety        string
	Habit          string
	Role           string
	Demographics   string
	CompanyInfo    string
	ContentHabits  string
	BuyingTriggers string
	SortOrder      int
	CreatedAt      time.Time
}

func (q *Queries) CreateAudiencePersona(projectID int64, p AudiencePersona) (*AudiencePersona, error) {
	result, err := q.db.Exec(
		`INSERT INTO audience_personas (project_id, label, description, pain_points, push, pull, anxiety, habit, role, demographics, company_info, content_habits, buying_triggers, sort_order)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)`,
		projectID, p.Label, p.Description, p.PainPoints, p.Push, p.Pull, p.Anxiety, p.Habit,
		p.Role, p.Demographics, p.CompanyInfo, p.ContentHabits, p.BuyingTriggers, p.SortOrder,
	)
	if err != nil {
		return nil, err
	}
	id, _ := result.LastInsertId()
	p.ID = id
	p.ProjectID = projectID
	return &p, nil
}

func (q *Queries) GetAudiencePersona(id int64) (*AudiencePersona, error) {
	p := &AudiencePersona{}
	err := q.db.QueryRow(
		`SELECT id, project_id, label, description, pain_points, push, pull, anxiety, habit,
		        role, demographics, company_info, content_habits, buying_triggers, sort_order, created_at
		 FROM audience_personas WHERE id = ?`, id,
	).Scan(&p.ID, &p.ProjectID, &p.Label, &p.Description, &p.PainPoints, &p.Push, &p.Pull, &p.Anxiety, &p.Habit,
		&p.Role, &p.Demographics, &p.CompanyInfo, &p.ContentHabits, &p.BuyingTriggers, &p.SortOrder, &p.CreatedAt)
	return p, err
}

func (q *Queries) ListAudiencePersonas(projectID int64) ([]AudiencePersona, error) {
	rows, err := q.db.Query(
		`SELECT id, project_id, label, description, pain_points, push, pull, anxiety, habit,
		        role, demographics, company_info, content_habits, buying_triggers, sort_order, created_at
		 FROM audience_personas WHERE project_id = ? ORDER BY sort_order, id`, projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var personas []AudiencePersona
	for rows.Next() {
		var p AudiencePersona
		if err := rows.Scan(&p.ID, &p.ProjectID, &p.Label, &p.Description, &p.PainPoints, &p.Push, &p.Pull, &p.Anxiety, &p.Habit,
			&p.Role, &p.Demographics, &p.CompanyInfo, &p.ContentHabits, &p.BuyingTriggers, &p.SortOrder, &p.CreatedAt); err != nil {
			return nil, err
		}
		personas = append(personas, p)
	}
	return personas, rows.Err()
}

func (q *Queries) UpdateAudiencePersona(id int64, p AudiencePersona) error {
	_, err := q.db.Exec(
		`UPDATE audience_personas SET label=?, description=?, pain_points=?, push=?, pull=?, anxiety=?, habit=?,
		        role=?, demographics=?, company_info=?, content_habits=?, buying_triggers=?, sort_order=?
		 WHERE id=?`,
		p.Label, p.Description, p.PainPoints, p.Push, p.Pull, p.Anxiety, p.Habit,
		p.Role, p.Demographics, p.CompanyInfo, p.ContentHabits, p.BuyingTriggers, p.SortOrder, id,
	)
	return err
}

func (q *Queries) DeleteAudiencePersona(id int64) error {
	_, err := q.db.Exec("DELETE FROM audience_personas WHERE id = ?", id)
	return err
}

func (q *Queries) DeleteAllAudiencePersonas(projectID int64) error {
	_, err := q.db.Exec("DELETE FROM audience_personas WHERE project_id = ?", projectID)
	return err
}

// BuildAudienceString formats all personas as structured markdown for use in AI prompts.
func (q *Queries) BuildAudienceString(projectID int64) (string, error) {
	personas, err := q.ListAudiencePersonas(projectID)
	if err != nil {
		return "", err
	}
	if len(personas) == 0 {
		return "", nil
	}

	var b strings.Builder
	for i, p := range personas {
		fmt.Fprintf(&b, "### Persona %d: %s\n", i+1, p.Label)
		fmt.Fprintf(&b, "**Description:** %s\n", p.Description)
		fmt.Fprintf(&b, "**Pain points:** %s\n", p.PainPoints)
		fmt.Fprintf(&b, "**Push:** %s\n", p.Push)
		fmt.Fprintf(&b, "**Pull:** %s\n", p.Pull)
		fmt.Fprintf(&b, "**Anxiety:** %s\n", p.Anxiety)
		fmt.Fprintf(&b, "**Habit:** %s\n", p.Habit)
		if p.Role != "" {
			fmt.Fprintf(&b, "**Role:** %s\n", p.Role)
		}
		if p.Demographics != "" {
			fmt.Fprintf(&b, "**Demographics:** %s\n", p.Demographics)
		}
		if p.CompanyInfo != "" {
			fmt.Fprintf(&b, "**Company:** %s\n", p.CompanyInfo)
		}
		if p.ContentHabits != "" {
			fmt.Fprintf(&b, "**Content habits:** %s\n", p.ContentHabits)
		}
		if p.BuyingTriggers != "" {
			fmt.Fprintf(&b, "**Buying triggers:** %s\n", p.BuyingTriggers)
		}
		b.WriteString("\n")
	}
	return b.String(), nil
}
```

- [ ] **Step 4: Add AudienceStore interface**

In `internal/store/interfaces.go`, add after the ProfileStore interface:

```go
// AudienceStore handles audience persona cards.
type AudienceStore interface {
	CreateAudiencePersona(projectID int64, p AudiencePersona) (*AudiencePersona, error)
	GetAudiencePersona(id int64) (*AudiencePersona, error)
	ListAudiencePersonas(projectID int64) ([]AudiencePersona, error)
	UpdateAudiencePersona(id int64, p AudiencePersona) error
	DeleteAudiencePersona(id int64) error
	DeleteAllAudiencePersonas(projectID int64) error
	BuildAudienceString(projectID int64) (string, error)
}
```

Add compile-time check at the bottom:
```go
var _ AudienceStore = (*Queries)(nil)
```

- [ ] **Step 5: Update BuildProfileString to include audience personas**

In `internal/store/profile.go`, modify `BuildProfileString` to skip the `audience` section from `profile_sections` and instead query `audience_personas`:

```go
func (q *Queries) BuildProfileString(projectID int64) (string, error) {
	sections, err := q.ListProfileSections(projectID)
	if err != nil {
		return "", err
	}
	var b strings.Builder
	q.prependMemory(projectID, &b)
	for _, s := range sections {
		if s.Content == "" || s.Section == "audience" {
			continue
		}
		fmt.Fprintf(&b, "## %s\n%s\n\n", sectionTitle(s.Section), s.Content)
	}

	// Add audience personas
	audienceStr, _ := q.BuildAudienceString(projectID)
	if audienceStr != "" {
		fmt.Fprintf(&b, "## Audience\n%s\n", audienceStr)
	}

	return b.String(), nil
}
```

Apply the same change to `BuildProfileStringExcluding` — skip `audience` from profile_sections, add personas unless `audience` is in the exclude list.

- [ ] **Step 6: Run all store tests**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/store/ -v`
Expected: All PASS.

- [ ] **Step 7: Commit**

```bash
git add internal/store/audience.go internal/store/audience_test.go internal/store/interfaces.go internal/store/profile.go
git commit -m "feat: add audience persona store with CRUD and profile string integration"
```

---

### Task 3: Audience Handler

**Files:**
- Create: `web/handlers/audience.go`
- Modify: `web/handlers/profile.go` (add braveClient, wire audience routes)
- Modify: `cmd/server/main.go` (pass braveClient to ProfileHandler)

- [ ] **Step 1: Create audience handler**

Create `web/handlers/audience.go`:

```go
package handlers

import (
	"encoding/json"
	"fmt"
	"net/http"
	"strconv"
	"strings"
	"time"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/search"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/internal/types"
)

type AudienceHandler struct {
	queries     *store.Queries
	aiClient    *ai.Client
	braveClient *search.BraveClient
	model       func() string
}

func NewAudienceHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, model func() string) *AudienceHandler {
	return &AudienceHandler{queries: q, aiClient: aiClient, braveClient: braveClient, model: model}
}

// Handle routes audience-specific requests from the profile handler.
// rest is the path after "profile/audience/" — e.g. "personas", "generate", "save-generated", "personas/123"
func (h *AudienceHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "personas" && r.Method == "GET":
		h.listPersonas(w, r, projectID)
	case rest == "personas" && r.Method == "POST":
		h.savePersona(w, r, projectID)
	case strings.HasPrefix(rest, "personas/") && r.Method == "DELETE":
		h.deletePersona(w, r, projectID, rest)
	case rest == "generate" && r.Method == "GET":
		h.streamGenerate(w, r, projectID)
	case rest == "save-generated" && r.Method == "POST":
		h.saveGenerated(w, r, projectID)
	case rest == "context" && r.Method == "GET":
		h.getContext(w, r, projectID)
	case rest == "save-context" && r.Method == "POST":
		h.saveContext(w, r, projectID)
	default:
		http.NotFound(w, r)
	}
}

func (h *AudienceHandler) listPersonas(w http.ResponseWriter, r *http.Request, projectID int64) {
	personas, _ := h.queries.ListAudiencePersonas(projectID)
	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(personas)
}

func (h *AudienceHandler) savePersona(w http.ResponseWriter, r *http.Request, projectID int64) {
	var p store.AudiencePersona
	if err := json.NewDecoder(r.Body).Decode(&p); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}

	if p.ID > 0 {
		// Update existing
		if err := h.queries.UpdateAudiencePersona(p.ID, p); err != nil {
			http.Error(w, "Update failed", http.StatusInternalServerError)
			return
		}
	} else {
		// Create new
		created, err := h.queries.CreateAudiencePersona(projectID, p)
		if err != nil {
			http.Error(w, "Create failed", http.StatusInternalServerError)
			return
		}
		p.ID = created.ID
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(p)
}

func (h *AudienceHandler) deletePersona(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	// rest = "personas/123"
	idStr := strings.TrimPrefix(rest, "personas/")
	id, err := strconv.ParseInt(idStr, 10, 64)
	if err != nil {
		http.NotFound(w, r)
		return
	}
	h.queries.DeleteAudiencePersona(id)
	w.WriteHeader(http.StatusOK)
}

func (h *AudienceHandler) getContext(w http.ResponseWriter, r *http.Request, projectID int64) {
	location, _ := h.queries.GetProjectSetting(projectID, "profile_location_audience")
	notes, _ := h.queries.GetProjectSetting(projectID, "profile_context_audience")

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]string{
		"location": location,
		"notes":    notes,
	})
}

func (h *AudienceHandler) saveContext(w http.ResponseWriter, r *http.Request, projectID int64) {
	var body struct {
		Location string `json:"location"`
		Notes    string `json:"notes"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}
	h.queries.SetProjectSetting(projectID, "profile_location_audience", body.Location)
	h.queries.SetProjectSetting(projectID, "profile_context_audience", body.Notes)
	w.WriteHeader(http.StatusOK)
}

func (h *AudienceHandler) saveGenerated(w http.ResponseWriter, r *http.Request, projectID int64) {
	var body struct {
		Personas []struct {
			ID     *int64 `json:"id"`
			Status string `json:"status"` // "new", "updated", "removed"
			store.AudiencePersona
		} `json:"personas"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}

	for i, p := range body.Personas {
		switch p.Status {
		case "new":
			p.AudiencePersona.SortOrder = i
			h.queries.CreateAudiencePersona(projectID, p.AudiencePersona)
		case "updated":
			if p.ID != nil {
				p.AudiencePersona.SortOrder = i
				h.queries.UpdateAudiencePersona(*p.ID, p.AudiencePersona)
			}
		case "removed":
			if p.ID != nil {
				h.queries.DeleteAudiencePersona(*p.ID)
			}
		}
	}

	w.WriteHeader(http.StatusOK)
}

func (h *AudienceHandler) streamGenerate(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, _ := h.queries.GetProject(projectID)

	// SSE headers
	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	flusher, ok := w.(http.Flusher)
	if !ok {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}

	sendEvent := func(v any) {
		data, _ := json.Marshal(v)
		fmt.Fprintf(w, "data: %s\n\n", data)
		flusher.Flush()
	}

	sendEvent(map[string]string{"type": "status", "status": "Preparing..."})

	// Gather context
	productSection, _ := h.queries.GetProfileSection(projectID, "product_and_positioning")
	productContent := ""
	if productSection != nil {
		productContent = productSection.Content
	}

	location, _ := h.queries.GetProjectSetting(projectID, "profile_location_audience")
	contextNotes, _ := h.queries.GetProjectSetting(projectID, "profile_context_audience")
	memorySetting, _ := h.queries.GetProjectSetting(projectID, "memory")

	// Get existing personas
	existingPersonas, _ := h.queries.ListAudiencePersonas(projectID)
	var existingJSON []byte
	if len(existingPersonas) > 0 {
		existingJSON, _ = json.Marshal(existingPersonas)
	}

	sendEvent(map[string]string{"type": "status", "status": "Researching audience..."})

	// Build system prompt
	var systemPrompt strings.Builder
	fmt.Fprintf(&systemPrompt, "Today's date: %s\n\n", time.Now().Format("January 2, 2006"))
	fmt.Fprintf(&systemPrompt, "You are an expert audience researcher. Your job is to identify 3-5 distinct customer personas for \"%s\".\n\n", project.Name)

	if productContent != "" {
		fmt.Fprintf(&systemPrompt, "## Product & Positioning\n%s\n\n", productContent)
	}

	if location != "" {
		fmt.Fprintf(&systemPrompt, "## Customer Location\n%s\n\n", location)
	}

	if contextNotes != "" {
		fmt.Fprintf(&systemPrompt, "## Additional Context\n%s\n\n", contextNotes)
	}

	if len(existingPersonas) > 0 {
		fmt.Fprintf(&systemPrompt, "## Existing Personas (review and improve these)\n```json\n%s\n```\n\n", string(existingJSON))
		systemPrompt.WriteString("Review the existing personas. You can:\n- Keep unchanged ones as-is (status: \"unchanged\", include their id)\n- Update ones that need improvement (status: \"updated\", include their id)\n- Remove ones that no longer fit (status: \"removed\", include their id)\n- Add new ones you think are missing (status: \"new\", no id)\n\n")
	} else {
		systemPrompt.WriteString("No existing personas yet. Create 3-5 new ones.\n\n")
	}

	if memorySetting != "" {
		fmt.Fprintf(&systemPrompt, "## Important rules and facts\n%s\n\n", memorySetting)
	}

	systemPrompt.WriteString(`## Workflow
1. Use web_search to research the target market, demographics, and customer segments for this type of product/business
2. Consider the customer location when researching
3. Identify 3-5 distinct personas that would buy this product/service
4. For each persona, fill in ALL mandatory fields with specific, actionable detail
5. Fill optional fields (role, demographics, company_info, content_habits, buying_triggers) ONLY when relevant — skip for B2C personas where they don't apply
6. Call submit_personas with your results

## Rules
- Each persona must be DISTINCT — different motivations, different pain points, different behaviors
- Write in the persona's own language, not marketing jargon
- Be specific to THIS business. Generic personas are useless.
- NEVER use em dashes. Use commas, periods, or restructure.
- Pain points, push, pull, anxiety, and habit should each be 1-3 sentences, not bullet lists.`)

	// Build tools
	searchTool := tools.NewSearchTool()
	submitTool := ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_personas",
			Description: "Submit the audience personas you've identified. Call this when you're done researching.",
			Parameters: json.RawMessage(`{"type":"object","properties":{"personas":{"type":"array","items":{"type":"object","properties":{"id":{"type":"integer","description":"Existing persona ID if updating/unchanged/removing, omit if new"},"status":{"type":"string","enum":["new","updated","unchanged","removed"]},"label":{"type":"string"},"description":{"type":"string"},"pain_points":{"type":"string"},"push":{"type":"string"},"pull":{"type":"string"},"anxiety":{"type":"string"},"habit":{"type":"string"},"role":{"type":"string"},"demographics":{"type":"string"},"company_info":{"type":"string"},"content_habits":{"type":"string"},"buying_triggers":{"type":"string"}},"required":["status","label","description","pain_points","push","pull","anxiety","habit"]}},"reasoning":{"type":"string","description":"Brief explanation of why these personas were chosen"}},"required":["personas","reasoning"]}`),
		},
	}

	toolList := []ai.Tool{searchTool, submitTool}
	searchExec := tools.NewSearchExecutor(h.braveClient)

	var submittedResult string

	executor := func(ctx context.Context, name, args string) (string, error) {
		switch name {
		case "web_search":
			return searchExec(ctx, args)
		case "submit_personas":
			submittedResult = args
			return "Personas submitted successfully.", ai.ErrToolDone
		default:
			return "", fmt.Errorf("unknown tool: %s", name)
		}
	}

	onToolEvent := func(event ai.ToolEvent) {
		switch event.Type {
		case "tool_start":
			if event.Tool == "web_search" {
				sendEvent(map[string]string{"type": "status", "status": "Searching: " + tools.SearchSummary(event.Args)})
			} else if event.Tool == "submit_personas" {
				sendEvent(map[string]string{"type": "status", "status": "Finalizing personas..."})
			}
		case "tool_result":
			// No need to emit tool results to the client for audience
		}
	}

	onChunk := func(chunk string) error {
		// We don't stream text chunks for audience — only the final JSON matters
		return nil
	}

	temp := 0.3
	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt.String()},
		{Role: "user", Content: "Research and identify the audience personas."},
	}

	_, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, onChunk, func(string) error { return nil }, &temp, 10)
	if err != nil {
		sendEvent(map[string]string{"type": "error", "error": err.Error()})
		return
	}

	if submittedResult != "" {
		sendEvent(map[string]any{"type": "personas", "data": json.RawMessage(submittedResult)})
	}

	sendEvent(map[string]string{"type": "done"})
}
```

- [ ] **Step 2: Update ProfileHandler to delegate audience routes**

In `web/handlers/profile.go`, add a field for the audience handler and update routing:

Add `audienceHandler` field to `ProfileHandler`:
```go
type ProfileHandler struct {
	queries         *store.Queries
	aiClient        *ai.Client
	braveClient     *search.BraveClient
	model           func() string
	audienceHandler *AudienceHandler
}

func NewProfileHandler(q *store.Queries, aiClient *ai.Client, braveClient *search.BraveClient, model func() string) *ProfileHandler {
	return &ProfileHandler{
		queries:         q,
		aiClient:        aiClient,
		braveClient:     braveClient,
		model:           model,
		audienceHandler: NewAudienceHandler(q, aiClient, braveClient, model),
	}
}
```

Add `"github.com/zanfridau/marketminded/internal/search"` to imports.

Update the `Handle` method to delegate audience sub-routes:
```go
func (h *ProfileHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "profile" && r.Method == "GET":
		h.show(w, r, projectID)
	case strings.HasPrefix(rest, "profile/audience/"):
		audienceRest := strings.TrimPrefix(rest, "profile/audience/")
		h.audienceHandler.Handle(w, r, projectID, audienceRest)
	case strings.HasSuffix(rest, "/save") && r.Method == "POST":
		h.saveSection(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/save-context") && r.Method == "POST":
		h.saveContext(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/generate") && r.Method == "GET":
		h.streamGenerate(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/versions") && r.Method == "GET":
		h.listVersions(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/context") && r.Method == "GET":
		h.getContext(w, r, projectID, rest)
	default:
		http.NotFound(w, r)
	}
}
```

Note: the audience route check MUST come before the generic suffix-based routes.

- [ ] **Step 3: Update main.go**

Change:
```go
profileHandler := handlers.NewProfileHandler(queries, aiClient, contentModel)
```
to:
```go
profileHandler := handlers.NewProfileHandler(queries, aiClient, braveClient, contentModel)
```

- [ ] **Step 4: Update show() to pass personas to the audience card**

In the `show()` method, after building card views, load personas for the audience card:

```go
	// Load audience personas
	personas, _ := h.queries.ListAudiencePersonas(projectID)

	// Load audience context
	audienceLocation, _ := h.queries.GetProjectSetting(projectID, "profile_location_audience")
	audienceNotes, _ := h.queries.GetProjectSetting(projectID, "profile_context_audience")
```

And update the audience card view to include personas. This requires updating the `ProfileCardView` struct and card rendering (done in Task 4).

- [ ] **Step 5: Verify compilation**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go build ./...`
Expected: Compiles (template changes in Task 4 may cause temporary errors — that's OK, verify handler code compiles in isolation).

- [ ] **Step 6: Commit**

```bash
git add web/handlers/audience.go web/handlers/profile.go cmd/server/main.go
git commit -m "feat: add audience handler with persona CRUD, generation, and context"
```

---

### Task 4: Profile Template — Audience Card Rendering

**Files:**
- Modify: `web/templates/profile.templ`

- [ ] **Step 1: Update data types**

Add audience-specific fields to `ProfileCardView` and add a persona view type:

```go
type ProfileCardView struct {
	Section        string
	Title          string
	Content        string
	SourceURLs     []store.SourceURL
	ContextNotes   string
	HasSourceURLs  bool
	IsAudience     bool
	Personas       []store.AudiencePersona
	AudienceLocation string
	Index          int
	ProjectID      int64
}
```

- [ ] **Step 2: Update the audience card rendering**

In the profile page template, after the existing card body (before the closing `</div>` of card-body), add audience-specific rendering:

The audience card should NOT render the standard content blob. Instead it renders:
- A context subcard (location + notes) with "Edit context" button
- Persona subcards, each with Edit and Delete buttons
- No "Edit" button in the parent card header for audience (only Build)

Replace the card rendering loop to conditionally handle audience vs. other sections. The audience card:
- Shows "Build" button (no "Edit" at parent level)
- Shows context subcard with location + notes
- Shows persona subcards with per-card Edit/Delete
- Each persona subcard shows label as title, description as markdown body, and mandatory fields on expand

- [ ] **Step 3: Add audience modals**

Add to the modals section:

```html
<!-- Audience Context modal -->
<dialog id="audience-context-modal" class="modal">
    <div class="modal-box w-11/12 max-w-2xl">
        <h3 class="font-bold text-lg mb-4">Audience — Edit Context</h3>
        <label class="label"><span class="label-text font-semibold">Customer Location</span></label>
        <input type="text" id="audience-location" class="input input-bordered w-full mb-4" placeholder="e.g. US, Western Europe, Global..."/>
        <label class="label"><span class="label-text font-semibold">Additional Notes</span></label>
        <textarea id="audience-context-notes" class="textarea textarea-bordered w-full" rows="3" placeholder="e.g. Focus on SMB segment..."></textarea>
        <div class="modal-action">
            <button type="button" id="audience-context-save-btn" class="btn btn-primary">Save</button>
            <button type="button" id="audience-context-cancel-btn" class="btn btn-ghost">Cancel</button>
        </div>
    </div>
    <form method="dialog" class="modal-backdrop"><button>close</button></form>
</dialog>

<!-- Audience Build modal -->
<dialog id="audience-build-modal" class="modal">
    <div class="modal-box w-11/12 max-w-4xl max-h-[90vh]">
        <h3 class="font-bold text-lg mb-4">Audience — Build Personas</h3>
        <div id="audience-build-summary" class="mb-4"></div>
        <div id="audience-build-actions" class="mb-4">
            <button type="button" id="audience-generate-btn" class="btn btn-secondary">Generate</button>
        </div>
        <div id="audience-build-results" class="hidden space-y-3 overflow-y-auto max-h-[60vh]"></div>
        <div id="audience-build-save-actions" class="modal-action hidden">
            <button type="button" id="audience-save-generated-btn" class="btn btn-primary">Save accepted</button>
            <button type="button" id="audience-discard-btn" class="btn btn-ghost">Discard all</button>
        </div>
    </div>
    <form method="dialog" class="modal-backdrop"><button>close</button></form>
</dialog>

<!-- Audience Edit Persona modal -->
<dialog id="audience-edit-modal" class="modal">
    <div class="modal-box w-11/12 max-w-2xl max-h-[90vh] overflow-y-auto">
        <h3 class="font-bold text-lg mb-4" id="audience-edit-title">Edit Persona</h3>
        <div id="audience-edit-fields"></div>
        <div class="modal-action">
            <button type="button" id="audience-edit-save-btn" class="btn btn-primary">Save</button>
            <button type="button" id="audience-edit-cancel-btn" class="btn btn-ghost">Cancel</button>
        </div>
    </div>
    <form method="dialog" class="modal-backdrop"><button>close</button></form>
</dialog>
```

- [ ] **Step 4: Update show() in profile handler**

Update the handler to populate audience card data:

```go
	cardViews := make([]templates.ProfileCardView, len(allSections))
	for i, name := range allSections {
		card := templates.ProfileCardView{
			Section:       name,
			Title:         sectionTitle(name),
			HasSourceURLs: name == "product_and_positioning",
			IsAudience:    name == "audience",
			Index:         i,
			ProjectID:     projectID,
		}
		if ps, ok := sectionMap[name]; ok {
			card.Content = ps.Content
			if card.HasSourceURLs && ps.SourceURLs != "" {
				json.Unmarshal([]byte(ps.SourceURLs), &card.SourceURLs)
			}
		}
		if card.HasSourceURLs {
			card.ContextNotes, _ = h.queries.GetProjectSetting(projectID, "profile_context_"+name)
		}
		if card.IsAudience {
			card.Personas, _ = h.queries.ListAudiencePersonas(projectID)
			card.AudienceLocation, _ = h.queries.GetProjectSetting(projectID, "profile_location_audience")
			card.ContextNotes, _ = h.queries.GetProjectSetting(projectID, "profile_context_audience")
		}
		cardViews[i] = card
	}
```

- [ ] **Step 5: Generate templ and verify**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && templ generate && go build ./...`
Expected: Compiles.

- [ ] **Step 6: Commit**

```bash
git add web/templates/profile.templ web/handlers/profile.go
git commit -m "feat: render audience persona cards and modals on profile page"
```

---

### Task 5: Audience JavaScript

**Files:**
- Modify: `web/static/app.js`

Add audience-specific modal handlers to `initProfilePage`. This includes:

- [ ] **Step 1: Add audience context modal handlers**

In `initProfilePage`, after the existing context modal code, add:

- Audience context button handler: opens `audience-context-modal`, fetches `/profile/audience/context`, populates location input and notes textarea
- Save button: POSTs to `/profile/audience/save-context`, reloads page
- Cancel button: closes modal

- [ ] **Step 2: Add audience build modal handlers**

- Build button click (for audience section): opens `audience-build-modal`, fetches context summary, shows Generate button
- Generate button: opens EventSource to `/profile/audience/generate`, handles `status` and `personas` events
- When `personas` event arrives: parse JSON, render each persona as a card with status badge and Accept/Reject buttons
- Each persona card shows: label, description preview, status badge (new/updated/unchanged/removed), and action buttons
- Accepted personas tracked in a JS array
- "Save accepted" button: POSTs accepted personas to `/profile/audience/save-generated`, reloads page
- "Discard all" button: closes modal

- [ ] **Step 3: Add audience edit persona modal handlers**

- Per-persona Edit button: opens `audience-edit-modal`, builds form fields dynamically
- Mandatory fields: label (input), description (textarea), pain_points (textarea), push (textarea), pull (textarea), anxiety (textarea), habit (textarea)
- Optional fields: shown if filled, plus "+ Add field" button that shows a dropdown of empty optional fields
- Save: POSTs to `/profile/audience/personas`, reloads page
- Cancel: closes modal

- [ ] **Step 4: Add per-persona delete handler**

- Delete button (x) on persona subcard: confirm dialog, DELETEs `/profile/audience/personas/{id}`, reloads page

- [ ] **Step 5: Test end-to-end**

Run: `make restart`
Test:
1. Profile page shows audience card with context subcard
2. "Edit context" opens modal with location + notes
3. "Build" opens build modal, shows context summary, Generate triggers SSE
4. After generation, persona cards appear with accept/reject
5. "Save accepted" saves and reloads
6. Each persona card shows on the profile page
7. Edit persona opens modal with all fields
8. Delete persona works with confirmation

- [ ] **Step 6: Commit**

```bash
git add web/static/app.js
git commit -m "feat: audience persona JS — build, edit, delete, context modals"
```

---

### Task 6: Final Integration and Cleanup

**Files:**
- Modify: `web/handlers/profile.go` (skip audience from generic section operations)
- Verify: pipeline integration

- [ ] **Step 1: Skip audience from generic section save/generate/edit**

In the profile handler, the `saveSection`, `streamGenerate`, `getContext`, `saveContext`, and `listVersions` methods should skip the audience section since it's now handled by the audience handler:

In `saveSection`, `streamGenerate`, and `listVersions`, add after the `isValidSection` check:
```go
	if section == "audience" {
		http.NotFound(w, r)
		return
	}
```

In `saveContext` and `getContext`, the audience section routes are already handled by the audience handler delegation in `Handle()` (because `profile/audience/context` matches `profile/audience/` prefix before it matches the generic `/context` suffix).

Verify the routing order in `Handle()` ensures `profile/audience/` prefix routes are checked FIRST.

- [ ] **Step 2: Verify pipeline uses audience personas**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/store/ -run TestBuildProfileString -v`
Expected: PASS — `BuildProfileString` now skips the `audience` section from `profile_sections` and includes `audience_personas` instead.

- [ ] **Step 3: Run all tests**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./... 2>&1`
Expected: All pass.

- [ ] **Step 4: Full end-to-end verification**

Run: `make restart`
Verify:
1. Profile page: audience card shows personas or "empty" state
2. Build: generates personas from product context + web search
3. Per-card accept/reject works
4. Edit individual persona works
5. Delete persona works
6. Context (location + notes) saves correctly
7. Pipeline run uses formatted personas in the profile string
8. Other sections (product & positioning, voice & tone) still work normally

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "feat: complete audience persona integration — routing, pipeline, cleanup"
```
