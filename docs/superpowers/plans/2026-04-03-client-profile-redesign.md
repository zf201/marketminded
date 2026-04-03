# Client Profile Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Consolidate company URLs into profile sections, replace chat-based profile editing with direct editing + one-shot AI generation, remove unused sections, add version history.

**Architecture:** New migration adds `source_urls` column to `profile_sections` and creates `profile_section_versions` table. Profile handler is rewritten to serve an edit page with textarea + magic generation SSE endpoint. Brand enricher reads URLs from profile instead of settings.

**Tech Stack:** Go, SQLite (Goose migrations), templ templates, Alpine.js, DaisyUI/Tailwind, SSE streaming via OpenRouter API.

---

### Task 1: Database Migration

**Files:**
- Create: `migrations/011_profile_redesign.sql`

- [ ] **Step 1: Write the migration SQL**

```sql
-- +goose Up

-- Add source_urls column (JSON array of {url, notes} objects)
ALTER TABLE profile_sections ADD COLUMN source_urls TEXT NOT NULL DEFAULT '[]';

-- Version history table (capped at 5 per section in application code)
CREATE TABLE profile_section_versions (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT NOT NULL,
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Remove the CHECK constraint by recreating the table without it.
-- SQLite does not support ALTER TABLE DROP CONSTRAINT.
CREATE TABLE profile_sections_new (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT NOT NULL,
    content TEXT NOT NULL DEFAULT '',
    source_urls TEXT NOT NULL DEFAULT '[]',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, section)
);

INSERT INTO profile_sections_new (id, project_id, section, content, updated_at)
SELECT id, project_id, section, content, updated_at FROM profile_sections;

DROP TABLE profile_sections;
ALTER TABLE profile_sections_new RENAME TO profile_sections;

-- Delete content_strategy and guidelines sections
DELETE FROM profile_sections WHERE section IN ('content_strategy', 'guidelines');

-- Delete brainstorm chats for profile sections (they're being removed)
DELETE FROM brainstorm_messages WHERE chat_id IN (
    SELECT id FROM brainstorm_chats WHERE section IN (
        'product_and_positioning', 'audience', 'voice_and_tone',
        'content_strategy', 'guidelines'
    )
);
DELETE FROM brainstorm_chats WHERE section IN (
    'product_and_positioning', 'audience', 'voice_and_tone',
    'content_strategy', 'guidelines'
);

-- +goose Down
DROP TABLE profile_section_versions;

CREATE TABLE profile_sections_old (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT NOT NULL CHECK(section IN (
        'product_and_positioning','audience','voice_and_tone',
        'content_strategy','guidelines'
    )),
    content TEXT NOT NULL DEFAULT '',
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, section)
);

INSERT INTO profile_sections_old (id, project_id, section, content, updated_at)
SELECT id, project_id, section, content, updated_at FROM profile_sections
WHERE section IN ('product_and_positioning','audience','voice_and_tone','content_strategy','guidelines');

DROP TABLE profile_sections;
ALTER TABLE profile_sections_old RENAME TO profile_sections;
```

- [ ] **Step 2: Run migration to verify it applies**

Run: `make restart`
Expected: Server starts without migration errors.

- [ ] **Step 3: Commit**

```bash
git add migrations/011_profile_redesign.sql
git commit -m "feat: add profile redesign migration — source_urls, versions, drop CHECK"
```

---

### Task 2: Settings-to-Profile Data Migration (Go)

**Files:**
- Create: `internal/store/migrate_profile.go`
- Test: `internal/store/migrate_profile_test.go`

- [ ] **Step 1: Write the failing test**

```go
// internal/store/migrate_profile_test.go
package store

import (
	"encoding/json"
	"testing"
)

func TestMigrateSettingsToSourceURLs(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	// Set up old-style settings
	q.SetProjectSetting(p.ID, "company_website", "https://example.com, https://example.com/about")
	q.SetProjectSetting(p.ID, "website_notes", "Use for value prop")
	q.SetProjectSetting(p.ID, "company_pricing", "https://example.com/pricing")
	q.SetProjectSetting(p.ID, "pricing_notes", "Reference tiers")

	// Run migration
	err := q.MigrateSettingsToSourceURLs()
	if err != nil {
		t.Fatalf("migrate: %v", err)
	}

	// Verify source_urls on product_and_positioning
	section, err := q.GetProfileSection(p.ID, "product_and_positioning")
	if err != nil {
		t.Fatalf("get section: %v", err)
	}

	var urls []SourceURL
	if err := json.Unmarshal([]byte(section.SourceURLs), &urls); err != nil {
		t.Fatalf("unmarshal: %v", err)
	}

	if len(urls) != 3 {
		t.Fatalf("expected 3 URLs, got %d", len(urls))
	}
	if urls[0].URL != "https://example.com" || urls[0].Notes != "Use for value prop" {
		t.Errorf("unexpected first URL: %+v", urls[0])
	}
	if urls[2].URL != "https://example.com/pricing" || urls[2].Notes != "Reference tiers" {
		t.Errorf("unexpected pricing URL: %+v", urls[2])
	}

	// Verify settings were cleaned up
	settings, _ := q.AllProjectSettings(p.ID)
	if _, ok := settings["company_website"]; ok {
		t.Error("company_website setting should have been deleted")
	}
	if _, ok := settings["company_pricing"]; ok {
		t.Error("company_pricing setting should have been deleted")
	}
}

func TestMigrateSettingsToSourceURLs_NoSettings(t *testing.T) {
	q := testDB(t)

	// Should not error when no projects have these settings
	err := q.MigrateSettingsToSourceURLs()
	if err != nil {
		t.Fatalf("migrate: %v", err)
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/store/ -run TestMigrateSettings -v`
Expected: FAIL — `MigrateSettingsToSourceURLs` undefined, `SourceURL` undefined, `SourceURLs` field not found on `ProfileSection`.

- [ ] **Step 3: Update ProfileSection struct and add SourceURL type**

In `internal/store/profile.go`, update the struct and add the new type:

```go
// Add at top of file, after imports:

type SourceURL struct {
	URL   string `json:"url"`
	Notes string `json:"notes"`
}

// Update ProfileSection struct:
type ProfileSection struct {
	ID         int64
	ProjectID  int64
	Section    string
	Content    string
	SourceURLs string // JSON array of SourceURL
	UpdatedAt  time.Time
}
```

Update all `Scan` calls in `profile.go` to include the new `source_urls` column:

In `UpsertProfileSection` — keep as-is, `source_urls` has a DEFAULT so INSERT works without specifying it.

In `GetProfileSection`:
```go
func (q *Queries) GetProfileSection(projectID int64, section string) (*ProfileSection, error) {
	s := &ProfileSection{}
	err := q.db.QueryRow(
		"SELECT id, project_id, section, content, source_urls, updated_at FROM profile_sections WHERE project_id = ? AND section = ?",
		projectID, section,
	).Scan(&s.ID, &s.ProjectID, &s.Section, &s.Content, &s.SourceURLs, &s.UpdatedAt)
	return s, err
}
```

In `ListProfileSections`:
```go
func (q *Queries) ListProfileSections(projectID int64) ([]ProfileSection, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, section, content, source_urls, updated_at FROM profile_sections WHERE project_id = ? ORDER BY section",
		projectID,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var sections []ProfileSection
	for rows.Next() {
		var s ProfileSection
		if err := rows.Scan(&s.ID, &s.ProjectID, &s.Section, &s.Content, &s.SourceURLs, &s.UpdatedAt); err != nil {
			return nil, err
		}
		sections = append(sections, s)
	}
	return sections, rows.Err()
}
```

- [ ] **Step 4: Write the migration function**

```go
// internal/store/migrate_profile.go
package store

import (
	"encoding/json"
	"strings"
)

// MigrateSettingsToSourceURLs is a one-time migration that moves company_website
// and company_pricing from project_settings into the source_urls JSON on the
// product_and_positioning profile section.
func (q *Queries) MigrateSettingsToSourceURLs() error {
	// Find all projects
	projects, err := q.ListProjects()
	if err != nil {
		return err
	}

	for _, p := range projects {
		settings, err := q.AllProjectSettings(p.ID)
		if err != nil {
			continue
		}

		var urls []SourceURL

		// Migrate company_website
		if website := settings["company_website"]; website != "" {
			notes := settings["website_notes"]
			for _, u := range splitCommaURLs(website) {
				urls = append(urls, SourceURL{URL: u, Notes: notes})
			}
		}

		// Migrate company_pricing
		if pricing := settings["company_pricing"]; pricing != "" {
			notes := settings["pricing_notes"]
			for _, u := range splitCommaURLs(pricing) {
				urls = append(urls, SourceURL{URL: u, Notes: notes})
			}
		}

		if len(urls) == 0 {
			continue
		}

		urlsJSON, err := json.Marshal(urls)
		if err != nil {
			continue
		}

		// Ensure profile section row exists
		q.UpsertProfileSection(p.ID, "product_and_positioning", "")
		// Only set source_urls if current value is empty/default
		q.db.Exec(
			`UPDATE profile_sections SET source_urls = ? WHERE project_id = ? AND section = 'product_and_positioning' AND source_urls = '[]'`,
			string(urlsJSON), p.ID,
		)

		// Clean up migrated settings
		q.db.Exec("DELETE FROM project_settings WHERE project_id = ? AND key IN ('company_website', 'website_notes', 'company_pricing', 'pricing_notes')", p.ID)
	}

	return nil
}

func splitCommaURLs(s string) []string {
	parts := strings.Split(s, ",")
	var urls []string
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p != "" {
			urls = append(urls, p)
		}
	}
	return urls
}
```

- [ ] **Step 5: Run the tests**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/store/ -run TestMigrateSettings -v`
Expected: PASS

- [ ] **Step 6: Run all store tests to check nothing broke**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/store/ -v`
Expected: All tests PASS (profile tests may need scan column updates — fix if needed)

- [ ] **Step 7: Wire migration into server startup**

In `cmd/server/main.go`, after `queries := store.NewQueries(db)`, add:

```go
queries.MigrateSettingsToSourceURLs()
```

This is idempotent — it only migrates projects that still have the old settings keys.

- [ ] **Step 8: Commit**

```bash
git add internal/store/profile.go internal/store/migrate_profile.go internal/store/migrate_profile_test.go cmd/server/main.go
git commit -m "feat: add source_urls to profile sections, migrate settings data"
```

---

### Task 3: Version History Store Methods

**Files:**
- Modify: `internal/store/profile.go`
- Modify: `internal/store/interfaces.go:37-43`
- Test: `internal/store/profile_test.go`

- [ ] **Step 1: Write the failing tests**

Add to `internal/store/profile_test.go`:

```go
func TestSaveProfileVersion(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	// Save a version
	err := q.SaveProfileVersion(p.ID, "product_and_positioning", "Version 1 content")
	if err != nil {
		t.Fatalf("save version: %v", err)
	}

	versions, err := q.ListProfileVersions(p.ID, "product_and_positioning")
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(versions) != 1 {
		t.Fatalf("expected 1, got %d", len(versions))
	}
	if versions[0].Content != "Version 1 content" {
		t.Errorf("unexpected content: %s", versions[0].Content)
	}
}

func TestProfileVersionCappedAt5(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	for i := 1; i <= 7; i++ {
		q.SaveProfileVersion(p.ID, "audience", fmt.Sprintf("Version %d", i))
	}

	versions, _ := q.ListProfileVersions(p.ID, "audience")
	if len(versions) != 5 {
		t.Fatalf("expected 5 (capped), got %d", len(versions))
	}
	// Most recent first
	if versions[0].Content != "Version 7" {
		t.Errorf("expected newest first, got: %s", versions[0].Content)
	}
	if versions[4].Content != "Version 3" {
		t.Errorf("expected oldest kept to be Version 3, got: %s", versions[4].Content)
	}
}
```

Add `"fmt"` to the import block in the test file if not already there.

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/store/ -run TestSaveProfileVersion -v && go test ./internal/store/ -run TestProfileVersionCapped -v`
Expected: FAIL — `SaveProfileVersion`, `ListProfileVersions`, `ProfileVersion` undefined.

- [ ] **Step 3: Implement version history methods**

Add to `internal/store/profile.go`:

```go
type ProfileVersion struct {
	ID        int64
	ProjectID int64
	Section   string
	Content   string
	CreatedAt time.Time
}

func (q *Queries) SaveProfileVersion(projectID int64, section, content string) error {
	_, err := q.db.Exec(
		"INSERT INTO profile_section_versions (project_id, section, content) VALUES (?, ?, ?)",
		projectID, section, content,
	)
	if err != nil {
		return err
	}

	// Cap at 5: delete oldest beyond 5
	_, err = q.db.Exec(`
		DELETE FROM profile_section_versions
		WHERE project_id = ? AND section = ? AND id NOT IN (
			SELECT id FROM profile_section_versions
			WHERE project_id = ? AND section = ?
			ORDER BY created_at DESC LIMIT 5
		)`, projectID, section, projectID, section)
	return err
}

func (q *Queries) ListProfileVersions(projectID int64, section string) ([]ProfileVersion, error) {
	rows, err := q.db.Query(
		"SELECT id, project_id, section, content, created_at FROM profile_section_versions WHERE project_id = ? AND section = ? ORDER BY created_at DESC",
		projectID, section,
	)
	if err != nil {
		return nil, err
	}
	defer rows.Close()

	var versions []ProfileVersion
	for rows.Next() {
		var v ProfileVersion
		if err := rows.Scan(&v.ID, &v.ProjectID, &v.Section, &v.Content, &v.CreatedAt); err != nil {
			return nil, err
		}
		versions = append(versions, v)
	}
	return versions, rows.Err()
}
```

- [ ] **Step 4: Add new method to UpsertProfileSection to also save source URLs**

Add a new method to `internal/store/profile.go`:

```go
func (q *Queries) UpsertProfileSectionFull(projectID int64, section, content, sourceURLs string) error {
	_, err := q.db.Exec(
		`INSERT INTO profile_sections (project_id, section, content, source_urls) VALUES (?, ?, ?, ?)
		 ON CONFLICT(project_id, section) DO UPDATE SET content = ?, source_urls = ?, updated_at = CURRENT_TIMESTAMP`,
		projectID, section, content, sourceURLs, content, sourceURLs,
	)
	return err
}
```

- [ ] **Step 5: Add BuildSourceURLList method**

Add to `internal/store/profile.go`:

```go
// BuildSourceURLList returns a formatted string of source URLs from the
// product_and_positioning section, for use by the brand enricher pipeline step.
func (q *Queries) BuildSourceURLList(projectID int64) (string, error) {
	section, err := q.GetProfileSection(projectID, "product_and_positioning")
	if err != nil {
		return "", nil // no section = no URLs, not an error
	}

	var urls []SourceURL
	if err := json.Unmarshal([]byte(section.SourceURLs), &urls); err != nil || len(urls) == 0 {
		return "", nil
	}

	var b strings.Builder
	b.WriteString("## Must-Use URLs (fetch these for latest data)\n")
	for _, u := range urls {
		fmt.Fprintf(&b, "- %s", u.URL)
		if u.Notes != "" {
			fmt.Fprintf(&b, " (Usage notes: %s)", u.Notes)
		}
		b.WriteString("\n")
	}
	return b.String(), nil
}
```

Add `"encoding/json"` to the imports in `profile.go`.

- [ ] **Step 6: Update ProfileStore interface**

In `internal/store/interfaces.go`, update ProfileStore:

```go
type ProfileStore interface {
	UpsertProfileSection(projectID int64, section, content string) error
	UpsertProfileSectionFull(projectID int64, section, content, sourceURLs string) error
	GetProfileSection(projectID int64, section string) (*ProfileSection, error)
	ListProfileSections(projectID int64) ([]ProfileSection, error)
	BuildProfileString(projectID int64) (string, error)
	BuildProfileStringExcluding(projectID int64, exclude []string) (string, error)
	BuildSourceURLList(projectID int64) (string, error)
	SaveProfileVersion(projectID int64, section, content string) error
	ListProfileVersions(projectID int64, section string) ([]ProfileVersion, error)
}
```

- [ ] **Step 7: Run all store tests**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/store/ -v`
Expected: All PASS.

- [ ] **Step 8: Commit**

```bash
git add internal/store/profile.go internal/store/interfaces.go internal/store/profile_test.go
git commit -m "feat: add profile version history and source URL list builder"
```

---

### Task 4: Profile Handler Rewrite

**Files:**
- Modify: `web/handlers/profile.go`

This task rewrites the profile handler to serve the edit page instead of chats, handle section saves with versioning, save source URLs, and stream magic generation.

- [ ] **Step 1: Rewrite profile handler**

Replace the full contents of `web/handlers/profile.go`:

```go
package handlers

import (
	"context"
	"encoding/json"
	"fmt"
	"net/http"
	"strings"
	"time"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/internal/types"
	"github.com/zanfridau/marketminded/web/templates"
)

var profileSections = []string{
	"product_and_positioning", "audience", "voice_and_tone",
}

var sectionDescriptions = map[string]string{
	"product_and_positioning": `What the company does, who they serve, industry, business model. Their unique value proposition, what makes them different from alternatives. Core problems they solve, why existing solutions fail. Key products/services, primary CTA (book a call, sign up, buy), and how aggressively content should sell vs. educate. Key competitors and how they differentiate.`,
	"audience":                `Ideal customer profile: demographics, roles, company type/size (if B2B). Their top pain points in their own language. Where they spend time online, what content they consume. Behavioral insights: push (frustrations driving them to seek a solution), pull (what attracts them to this specific solution), anxiety (concerns that might stop them from acting), habit (what keeps them stuck with the status quo).`,
	"voice_and_tone":          `How the brand communicates: personality traits, vocabulary level, sentence style, formality, humor, warmth. Characteristic phrases to use. How they relate to the audience (peer, mentor, authority). Words/phrases to always use and to never use. Ask for examples of writing they like, use THEIR words, not marketing theory. Include content role models: creators, brands, or accounts they admire and why.`,
}

type ProfileHandler struct {
	queries  *store.Queries
	aiClient *ai.Client
	model    func() string
}

func NewProfileHandler(q *store.Queries, aiClient *ai.Client, model func() string) *ProfileHandler {
	return &ProfileHandler{queries: q, aiClient: aiClient, model: model}
}

func (h *ProfileHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	switch {
	case rest == "profile" && r.Method == "GET":
		h.show(w, r, projectID)
	case strings.HasSuffix(rest, "/edit") && r.Method == "GET":
		h.showEdit(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/save") && r.Method == "POST":
		h.saveSection(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/generate") && r.Method == "GET":
		h.streamGenerate(w, r, projectID, rest)
	case strings.HasSuffix(rest, "/versions") && r.Method == "GET":
		h.listVersions(w, r, projectID, rest)
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

	sections, _ := h.queries.ListProfileSections(projectID)
	sectionMap := make(map[string]string)
	for _, s := range sections {
		sectionMap[s.Section] = s.Content
	}

	cardViews := make([]templates.ProfileCardView, len(profileSections))
	for i, name := range profileSections {
		cardViews[i] = templates.ProfileCardView{
			Section: name,
			Title:   profileSectionTitle(name),
			Content: sectionMap[name],
			Index:   i,
		}
	}

	templates.ProfilePage(templates.ProfilePageData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Cards:       cardViews,
	}).Render(r.Context(), w)
}

func (h *ProfileHandler) showEdit(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	section := parseSectionName(rest)

	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	ps, _ := h.queries.GetProfileSection(projectID, section)
	content := ""
	sourceURLs := "[]"
	if ps != nil {
		content = ps.Content
		sourceURLs = ps.SourceURLs
	}

	var urls []store.SourceURL
	json.Unmarshal([]byte(sourceURLs), &urls)

	hasSourceURLs := section == "product_and_positioning"

	templates.ProfileEditPage(templates.ProfileEditData{
		ProjectID:     projectID,
		ProjectName:   project.Name,
		Section:       section,
		SectionTitle:  profileSectionTitle(section),
		Content:       content,
		SourceURLs:    urls,
		HasSourceURLs: hasSourceURLs,
		Saved:         r.URL.Query().Get("saved") == "1",
	}).Render(r.Context(), w)
}

func (h *ProfileHandler) saveSection(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	section := parseSectionName(rest)
	r.ParseForm()
	content := r.FormValue("content")

	// Save version of previous content (if any exists and differs)
	existing, err := h.queries.GetProfileSection(projectID, section)
	if err == nil && existing.Content != "" && existing.Content != content {
		h.queries.SaveProfileVersion(projectID, section, existing.Content)
	}

	if section == "product_and_positioning" {
		urlValues := r.Form["source_url"]
		noteValues := r.Form["source_notes"]
		var urls []store.SourceURL
		for i, u := range urlValues {
			u = strings.TrimSpace(u)
			if u == "" {
				continue
			}
			notes := ""
			if i < len(noteValues) {
				notes = strings.TrimSpace(noteValues[i])
			}
			urls = append(urls, store.SourceURL{URL: u, Notes: notes})
		}
		urlsJSON, _ := json.Marshal(urls)
		if urls == nil {
			urlsJSON = []byte("[]")
		}
		h.queries.UpsertProfileSectionFull(projectID, section, content, string(urlsJSON))
	} else {
		h.queries.UpsertProfileSection(projectID, section, content)
	}

	http.Redirect(w, r, fmt.Sprintf("/projects/%d/profile/%s/edit?saved=1", projectID, section), http.StatusSeeOther)
}

func (h *ProfileHandler) streamGenerate(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	section := parseSectionName(rest)

	ps, _ := h.queries.GetProfileSection(projectID, section)
	existingContent := ""
	sourceURLs := "[]"
	if ps != nil {
		existingContent = ps.Content
		sourceURLs = ps.SourceURLs
	}

	memory, _ := h.queries.GetProjectSetting(projectID, "memory")

	// Pre-fetch all source URLs
	var urls []store.SourceURL
	json.Unmarshal([]byte(sourceURLs), &urls)

	var fetchedContent strings.Builder
	for _, u := range urls {
		result, err := tools.ExecuteFetch(r.Context(), fmt.Sprintf(`{"url":%q}`, u.URL))
		if err != nil {
			fmt.Fprintf(&fetchedContent, "## %s (fetch failed: %s)\n\n", u.URL, err.Error())
			continue
		}
		fmt.Fprintf(&fetchedContent, "## %s", u.URL)
		if u.Notes != "" {
			fmt.Fprintf(&fetchedContent, " (Usage notes: %s)", u.Notes)
		}
		fmt.Fprintf(&fetchedContent, "\n%s\n\n", result)
	}

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are an expert content marketing strategist. Write the **%s** section of a client profile.

## What this section covers
%s

## Instructions
%s

## Writing style
- Write like a human. NEVER sound like AI-generated content.
- NEVER use em dashes. Use commas, periods, or restructure.
- Zero emojis.
- Avoid: "dive into", "leverage", "elevate", "streamline", "game-changer", "unlock", "harness", "at the end of the day", "it's worth noting".
- Short, direct sentences. Vary length. Sound like a person, not a press release.
- Be specific to THIS client. If it could apply to any company, it's too generic.
- NEVER fabricate details. If information is not available from the sources, say so.`,
		time.Now().Format("January 2, 2006"),
		profileSectionTitle(section),
		sectionDescriptions[section],
		buildGenerateInstructions(existingContent, fetchedContent.String(), memory),
	)

	messages := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Generate the profile section now."},
	}

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

	if len(urls) > 0 {
		sendEvent(map[string]string{"type": "status", "message": fmt.Sprintf("Fetched %d source URLs, generating...", len(urls))})
	}

	_, err := h.aiClient.Stream(r.Context(), h.model(), messages, func(chunk string) error {
		sendEvent(map[string]string{"type": "chunk", "chunk": chunk})
		return nil
	})

	if err != nil {
		sendEvent(map[string]string{"type": "error", "error": err.Error()})
		return
	}

	sendEvent(map[string]string{"type": "done"})
}

func (h *ProfileHandler) listVersions(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	section := parseSectionName(rest)
	versions, _ := h.queries.ListProfileVersions(projectID, section)

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(versions)
}

func parseSectionName(rest string) string {
	section := strings.TrimPrefix(rest, "profile/")
	if idx := strings.Index(section, "/"); idx != -1 {
		section = section[:idx]
	}
	return section
}

func buildGenerateInstructions(existingContent, fetchedContent, memory string) string {
	var b strings.Builder

	if existingContent != "" {
		fmt.Fprintf(&b, "## Current section content (improve and expand this)\n%s\n\n", existingContent)
		b.WriteString("Improve the existing content with any new information from the sources below. Keep what's already good, fix what's wrong, add what's missing. Don't start from scratch.\n\n")
	} else {
		b.WriteString("Write this section from scratch based on the sources below.\n\n")
	}

	if fetchedContent != "" {
		fmt.Fprintf(&b, "## Source URLs (fetched content)\n%s\n", fetchedContent)
	}

	if memory != "" {
		fmt.Fprintf(&b, "## Important rules and facts\n%s\n", memory)
	}

	return b.String()
}

var profileDisplayTitles = map[string]string{
	"product_and_positioning": "Product & Positioning",
	"voice_and_tone":          "Voice & Tone",
	"audience":                "Audience",
}

func profileSectionTitle(s string) string {
	if t, ok := profileDisplayTitles[s]; ok {
		return t
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
```

Note: The `Stream` method does not accept a temperature parameter. For profile generation, the default temperature is acceptable. If temperature control is needed later, use `StreamWithTools` with an empty tool list.

Also note: The `context` import is no longer needed (removed tool executor). The `search` import is no longer needed (no braveClient). The `tools` import is needed only for `ExecuteFetch`. Check that `types.Message` is the correct type — if it uses `ai.Message`, adjust accordingly.

- [ ] **Step 2: Update main.go — remove braveClient from ProfileHandler constructor**

In `cmd/server/main.go`, change:
```go
profileHandler := handlers.NewProfileHandler(queries, aiClient, braveClient, contentModel)
```
to:
```go
profileHandler := handlers.NewProfileHandler(queries, aiClient, contentModel)
```

- [ ] **Step 3: Verify it compiles**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go build ./...`
Expected: Compiles without errors. Fix any import or type mismatches.

- [ ] **Step 4: Commit**

```bash
git add web/handlers/profile.go cmd/server/main.go
git commit -m "feat: rewrite profile handler — edit page, magic generation, version history"
```

---

### Task 5: Profile Edit Template

**Files:**
- Modify: `web/templates/profile.templ`

- [ ] **Step 1: Rewrite the profile template**

Replace the full contents of `web/templates/profile.templ`:

```
package templates

import (
	"fmt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates/components"
)

type ProfilePageData struct {
	ProjectID   int64
	ProjectName string
	Cards       []ProfileCardView
}

type ProfileCardView struct {
	Section string
	Title   string
	Content string
	Index   int
}

templ ProfilePage(data ProfilePageData) {
	@components.ProjectPageShell(data.ProjectName+" - Profile", []components.Breadcrumb{
		{Label: "Projects", URL: "/"},
		{Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
		{Label: "Profile"},
	}, data.ProjectID) {
		<div class="flex items-center justify-between mb-4">
			<h1 class="text-2xl font-bold">{ data.ProjectName } Profile</h1>
		</div>

		for _, card := range data.Cards {
			<div class="card bg-base-100 shadow-sm border border-base-300 mb-3" id={ "card-" + card.Section }>
				<div class="card-body">
					<div class="flex items-center justify-between mb-1">
						<div class="flex items-center gap-2">
							<strong>{ card.Title }</strong>
							if card.Content != "" {
								@components.StatusBadge("approved")
							} else {
								<span class="badge badge-ghost">Empty</span>
							}
						</div>
						<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/profile/%s/edit", data.ProjectID, card.Section)) } class="btn btn-ghost btn-sm">
							if card.Content != "" {
								Edit
							} else {
								Start
							}
						</a>
					</div>
					if card.Content != "" {
						<div class="whitespace-pre-wrap text-sm leading-relaxed text-base-content/80 line-clamp-4">{ card.Content }</div>
					} else {
						<p class="text-base-content/60 italic text-sm">Not yet filled</p>
					}
				</div>
			</div>
		}
	}
}

type ProfileEditData struct {
	ProjectID     int64
	ProjectName   string
	Section       string
	SectionTitle  string
	Content       string
	SourceURLs    []store.SourceURL
	HasSourceURLs bool
	Saved         bool
}

templ ProfileEditPage(data ProfileEditData) {
	@components.ProjectPageShell(data.SectionTitle, []components.Breadcrumb{
		{Label: "Projects", URL: "/"},
		{Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
		{Label: "Profile", URL: fmt.Sprintf("/projects/%d/profile", data.ProjectID)},
		{Label: data.SectionTitle},
	}, data.ProjectID) {
		<div class="flex items-center justify-between mb-4">
			<h1 class="text-2xl font-bold">{ data.SectionTitle }</h1>
		</div>

		if data.Saved {
			<div class="alert alert-success mb-4">
				<p>Section saved.</p>
			</div>
		}

		<form method="POST" action={ templ.SafeURL(fmt.Sprintf("/projects/%d/profile/%s/save", data.ProjectID, data.Section)) } id="profile-edit-form">
			if data.HasSourceURLs {
				<div class="card bg-base-200 shadow-sm border border-base-300 mb-4">
					<div class="card-body p-4">
						<h3 class="font-semibold text-base mb-2">Source URLs</h3>
						<p class="text-base-content/60 text-xs mb-3">URLs fetched during profile generation. Add as many as needed.</p>
						<div id="source-urls-container">
							if len(data.SourceURLs) > 0 {
								for _, u := range data.SourceURLs {
									@sourceURLRow(u.URL, u.Notes)
								}
							} else {
								@sourceURLRow("", "")
							}
						</div>
						<button type="button" class="btn btn-ghost btn-sm mt-2" id="add-url-btn">+ Add URL</button>
					</div>
				</div>
			}

			<div class="flex items-center gap-2 mb-3">
				<button type="button" class="btn btn-secondary btn-sm" id="generate-btn"
					data-project-id={ fmt.Sprintf("%d", data.ProjectID) }
					data-section={ data.Section }>
					if data.Content != "" {
						Rebuild
					} else {
						Build Profile
					}
				</button>
				<button type="button" class="btn btn-ghost btn-sm" id="history-btn"
					data-project-id={ fmt.Sprintf("%d", data.ProjectID) }
					data-section={ data.Section }>
					History
				</button>
			</div>

			<div class="form-control mb-4">
				<textarea name="content" id="profile-content" class="textarea textarea-bordered w-full font-mono text-sm" rows="20" placeholder="Section content...">{ data.Content }</textarea>
			</div>

			<div class="flex gap-2">
				<button type="submit" class="btn btn-primary">Save</button>
				<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/profile", data.ProjectID)) } class="btn btn-ghost">Cancel</a>
			</div>
		</form>

		@components.Modal("history-modal") {
			<h3 class="font-bold text-lg mb-4">Version History</h3>
			<div id="history-content">
				<p class="text-base-content/60">Loading...</p>
			</div>
		}
	}
}

templ sourceURLRow(url string, notes string) {
	<div class="flex gap-2 mb-2 source-url-row">
		<input type="text" name="source_url" value={ url } placeholder="https://example.com" class="input input-bordered input-sm flex-1"/>
		<input type="text" name="source_notes" value={ notes } placeholder="Usage notes..." class="input input-bordered input-sm flex-1"/>
		<button type="button" class="btn btn-ghost btn-sm btn-square remove-url-btn">x</button>
	</div>
}
```

- [ ] **Step 2: Generate templ output**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && templ generate`
Expected: No errors.

- [ ] **Step 3: Verify it compiles**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go build ./...`
Expected: Compiles.

- [ ] **Step 4: Commit**

```bash
git add web/templates/profile.templ web/templates/profile_templ.go
git commit -m "feat: add profile edit page template with source URLs and history modal"
```

---

### Task 6: Frontend JavaScript

**Files:**
- Modify: `web/static/app.js`

- [ ] **Step 1: Replace profile section chat JS with new profile edit JS**

Find the `initProfileSectionChat` function in `web/static/app.js` and the auto-init block for `profile-section-page`. Remove both. Replace with new code.

Remove from the `DOMContentLoaded` listener:
```js
    var sectionPage = document.getElementById('profile-section-page');
    if (sectionPage) {
        initProfileSectionChat(sectionPage.dataset.projectId, sectionPage.dataset.section);
    }
```

Remove the entire `initProfileSectionChat` function (from `function initProfileSectionChat(projectID, sectionName) {` to its closing `}`).

Add to the `DOMContentLoaded` listener:
```js
    var profileEdit = document.getElementById('profile-edit-form');
    if (profileEdit) {
        initProfileEdit();
    }
```

Add a new function after the `DOMContentLoaded` block:
```js
function initProfileEdit() {
    // Add URL row
    var addBtn = document.getElementById('add-url-btn');
    if (addBtn) {
        addBtn.addEventListener('click', function() {
            var container = document.getElementById('source-urls-container');
            var row = document.createElement('div');
            row.className = 'flex gap-2 mb-2 source-url-row';

            var urlInput = document.createElement('input');
            urlInput.type = 'text';
            urlInput.name = 'source_url';
            urlInput.placeholder = 'https://example.com';
            urlInput.className = 'input input-bordered input-sm flex-1';

            var notesInput = document.createElement('input');
            notesInput.type = 'text';
            notesInput.name = 'source_notes';
            notesInput.placeholder = 'Usage notes...';
            notesInput.className = 'input input-bordered input-sm flex-1';

            var removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'btn btn-ghost btn-sm btn-square remove-url-btn';
            removeBtn.textContent = 'x';
            removeBtn.addEventListener('click', function() { removeURLRow(row); });

            row.appendChild(urlInput);
            row.appendChild(notesInput);
            row.appendChild(removeBtn);
            container.appendChild(row);
        });
    }

    // Remove URL row handlers
    document.querySelectorAll('.remove-url-btn').forEach(function(btn) {
        btn.addEventListener('click', function() {
            removeURLRow(btn.closest('.source-url-row'));
        });
    });

    // Generate button
    var genBtn = document.getElementById('generate-btn');
    if (genBtn) {
        genBtn.addEventListener('click', function() {
            var projectID = genBtn.dataset.projectId;
            var section = genBtn.dataset.section;
            var textarea = document.getElementById('profile-content');
            var originalText = genBtn.textContent;

            genBtn.disabled = true;
            genBtn.textContent = 'Generating...';
            textarea.value = '';
            textarea.disabled = true;

            var source = new EventSource('/projects/' + projectID + '/profile/' + section + '/generate');
            source.onmessage = function(event) {
                var d = JSON.parse(event.data);
                switch (d.type) {
                case 'status':
                    genBtn.textContent = d.message;
                    break;
                case 'chunk':
                    textarea.value += d.chunk;
                    textarea.scrollTop = textarea.scrollHeight;
                    break;
                case 'error':
                    source.close();
                    genBtn.disabled = false;
                    genBtn.textContent = originalText;
                    textarea.disabled = false;
                    break;
                case 'done':
                    source.close();
                    genBtn.disabled = false;
                    genBtn.textContent = 'Rebuild';
                    textarea.disabled = false;
                    break;
                }
            };
            source.onerror = function() {
                source.close();
                genBtn.disabled = false;
                genBtn.textContent = originalText;
                textarea.disabled = false;
            };
        });
    }

    // History button
    var histBtn = document.getElementById('history-btn');
    if (histBtn) {
        histBtn.addEventListener('click', function() {
            var projectID = histBtn.dataset.projectId;
            var section = histBtn.dataset.section;
            var modal = document.getElementById('history-modal');
            var content = document.getElementById('history-content');

            content.textContent = 'Loading...';
            modal.showModal();

            fetch('/projects/' + projectID + '/profile/' + section + '/versions')
                .then(function(r) { return r.json(); })
                .then(function(versions) {
                    // Clear loading text
                    while (content.firstChild) content.removeChild(content.firstChild);

                    if (!versions || versions.length === 0) {
                        var empty = document.createElement('p');
                        empty.className = 'text-base-content/60';
                        empty.textContent = 'No previous versions.';
                        content.appendChild(empty);
                        return;
                    }

                    versions.forEach(function(v, i) {
                        var date = new Date(v.CreatedAt).toLocaleString();

                        var wrapper = document.createElement('div');
                        wrapper.className = 'collapse collapse-arrow bg-base-200 mb-2';

                        var radio = document.createElement('input');
                        radio.type = 'radio';
                        radio.name = 'version-accordion';
                        if (i === 0) radio.checked = true;

                        var title = document.createElement('div');
                        title.className = 'collapse-title font-medium text-sm';
                        title.textContent = date;

                        var body = document.createElement('div');
                        body.className = 'collapse-content';

                        var pre = document.createElement('pre');
                        pre.className = 'whitespace-pre-wrap text-xs mb-2 max-h-60 overflow-y-auto';
                        pre.textContent = v.Content;

                        var restoreBtn = document.createElement('button');
                        restoreBtn.type = 'button';
                        restoreBtn.className = 'btn btn-ghost btn-xs';
                        restoreBtn.textContent = 'Restore';
                        restoreBtn.addEventListener('click', function() {
                            document.getElementById('profile-content').value = v.Content;
                            modal.close();
                        });

                        body.appendChild(pre);
                        body.appendChild(restoreBtn);
                        wrapper.appendChild(radio);
                        wrapper.appendChild(title);
                        wrapper.appendChild(body);
                        content.appendChild(wrapper);
                    });
                })
                .catch(function() {
                    content.textContent = 'Failed to load versions.';
                });
        });
    }
}

function removeURLRow(row) {
    if (document.querySelectorAll('.source-url-row').length > 1) {
        row.remove();
    } else {
        row.querySelectorAll('input').forEach(function(inp) { inp.value = ''; });
    }
}
```

- [ ] **Step 2: Verify the app loads**

Run: `make restart`
Navigate to a project's profile page. Verify cards render and link to edit pages.

- [ ] **Step 3: Commit**

```bash
git add web/static/app.js
git commit -m "feat: add profile edit page JS — generate, history, dynamic URL rows"
```

---

### Task 7: Update Brand Enricher to Read from Profile

**Files:**
- Modify: `internal/pipeline/steps/brand_enricher.go`

- [ ] **Step 1: Update BrandEnricherStep to use BuildSourceURLList**

Replace the contents of `internal/pipeline/steps/brand_enricher.go`:

```go
package steps

import (
	"context"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type BrandEnricherStep struct {
	AI      *ai.Client
	Tools   *tools.Registry
	Prompt  *prompt.Builder
	Profile store.ProfileStore
	Model   func() string
}

func (s *BrandEnricherStep) Type() string { return "brand_enricher" }

func (s *BrandEnricherStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	researchOutput := input.PriorOutputs["research"]

	urlList, _ := s.Profile.BuildSourceURLList(input.ProjectID)

	if urlList == "" {
		stream.SendDone()
		return pipeline.StepResult{Output: researchOutput}, nil
	}

	systemPrompt := s.Prompt.ForBrandEnricher(input.Profile, researchOutput, urlList)
	toolList := s.Tools.ForStep("brand_enricher")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Fetch the brand URLs and enrich the research with brand context.", toolList, s.Tools, "submit_brand_enrichment", stream, 0.3, 12)
}
```

- [ ] **Step 2: Update main.go — change BrandEnricherStep field name**

In `cmd/server/main.go`, change:
```go
&steps.BrandEnricherStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, ProjectSettings: queries, Model: contentModel},
```
to:
```go
&steps.BrandEnricherStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Profile: queries, Model: contentModel},
```

- [ ] **Step 3: Check if splitURLs is used elsewhere**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && grep -r "splitURLs" --include="*.go" .`

If only `brand_enricher.go` used it, it's already removed with the rewrite. The `splitCommaURLs` in `migrate_profile.go` is a separate function.

- [ ] **Step 4: Verify it compiles**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go build ./...`
Expected: Compiles.

- [ ] **Step 5: Commit**

```bash
git add internal/pipeline/steps/brand_enricher.go cmd/server/main.go
git commit -m "feat: brand enricher reads source URLs from profile instead of settings"
```

---

### Task 8: Clean Up Settings Page

**Files:**
- Modify: `web/handlers/project_settings.go`
- Modify: `web/templates/project_settings.templ`

- [ ] **Step 1: Remove company URL fields from settings template**

In `web/templates/project_settings.templ`, replace the "Company URLs" card (lines 50-74) with a smaller card containing only blog fields:

```
			<div class="mb-4">
				@components.Card("Blog URL") {
					<p class="text-base-content/60 text-xs mt-1 mb-3">Blog URL used by the Tone Analyzer to match writing style. Use commas to separate multiple URLs.</p>

					@components.FormGroup("Blog URL") {
						<input type="text" name="company_blog" value={ data.CompanyBlog } placeholder="https://example.com/blog" class="input input-bordered w-full"/>
					}
					@components.FormGroup("Blog Usage Notes") {
						<textarea name="blog_notes" class="textarea textarea-bordered w-full" placeholder="e.g. Link to blog from social posts, reference existing posts for internal linking...">{ data.BlogNotes }</textarea>
					}
				}
			</div>
```

- [ ] **Step 2: Remove fields from ProjectSettingsData struct**

In `web/templates/project_settings.templ`, update `ProjectSettingsData` to remove `CompanyWebsite`, `WebsiteNotes`, `CompanyPricing`, `PricingNotes`:

```go
type ProjectSettingsData struct {
	ProjectID             int64
	ProjectName           string
	Language              string
	CompanyBlog           string
	BlogNotes             string
	StorytellingFramework string
	Frameworks            []FrameworkOption
	Saved                 bool
}
```

- [ ] **Step 3: Update handler to remove settings**

In `web/handlers/project_settings.go`, update `show()`:
```go
	templates.ProjectSettingsPage(templates.ProjectSettingsData{
		ProjectID:             projectID,
		ProjectName:           project.Name,
		Language:              settings["language"],
		CompanyBlog:           settings["company_blog"],
		BlogNotes:             settings["blog_notes"],
		StorytellingFramework: settings["storytelling_framework"],
		Frameworks:            fwOptions,
		Saved:                 r.URL.Query().Get("saved") == "1",
	}).Render(r.Context(), w)
```

Update `save()`:
```go
func (h *ProjectSettingsHandler) save(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	h.queries.SetProjectSetting(projectID, "language", r.FormValue("language"))
	h.queries.SetProjectSetting(projectID, "company_blog", r.FormValue("company_blog"))
	h.queries.SetProjectSetting(projectID, "blog_notes", r.FormValue("blog_notes"))
	h.queries.SetProjectSetting(projectID, "storytelling_framework", r.FormValue("storytelling_framework"))
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/settings?saved=1", projectID), http.StatusSeeOther)
}
```

- [ ] **Step 4: Generate templ and verify compilation**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && templ generate && go build ./...`
Expected: Compiles.

- [ ] **Step 5: Commit**

```bash
git add web/handlers/project_settings.go web/templates/project_settings.templ web/templates/project_settings_templ.go
git commit -m "feat: remove company URL fields from settings, keep blog URL"
```

---

### Task 9: Clean Up Removed Code

**Files:**
- Delete: `internal/tools/update.go`
- Modify: `internal/store/profile.go`

- [ ] **Step 1: Delete update_section tool**

```bash
rm internal/tools/update.go
```

Verify nothing else imports it:
Run: `cd /Users/zanfridau/CODE/AI/marketminded && grep -r "UpdateSection\|update_section\|NewUpdateSectionTool\|ParseUpdateArgs" --include="*.go" . | grep -v "_test.go"`

The profile handler was the only consumer and has been rewritten. If anything else references it, fix those references.

- [ ] **Step 2: Clean up store/profile.go section titles**

The `sectionDisplayTitles` map in `internal/store/profile.go` only has `content_strategy`. Since that section is removed, update it:

```go
var sectionDisplayTitles = map[string]string{
	"product_and_positioning": "Product & Positioning",
	"voice_and_tone":          "Voice & Tone",
}
```

- [ ] **Step 3: Verify compilation**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go build ./...`
Expected: Compiles.

- [ ] **Step 4: Verify the app runs end-to-end**

Run: `make restart`

Test manually:
1. Navigate to a project's profile page — should show 3 section cards
2. Click "Edit" or "Start" on Product & Positioning — should show edit page with Source URLs subcard
3. Add/remove URL rows — should work dynamically
4. Click "Build Profile" or "Rebuild" — should stream content into textarea
5. Click "Save" — should redirect with success message
6. Click "History" — should show modal with versions (empty at first)
7. Edit and save again — should create a version
8. Check History — should show the previous version with Restore button
9. Visit Settings — should no longer show company URL fields, only Blog URL
10. Run a pipeline — Brand Enricher should still work using URLs from profile

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "chore: remove update_section tool and clean up old profile code"
```
