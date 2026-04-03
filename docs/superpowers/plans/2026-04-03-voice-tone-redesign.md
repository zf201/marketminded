# Voice & Tone Redesign Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the single-text-blob voice & tone section with structured output (5 sections), absorb the tone analyzer pipeline step, add rich context inputs (blog URLs, liked articles, inspiration URLs), and remove the tone analyzer from the pipeline.

**Architecture:** New `voice_tone_profiles` table with 5 text columns. Dedicated `VoiceToneHandler` with `fetch_url` + `web_search` + `submit_voice_tone` tools via `StreamWithTools`. Profile page extended with voice & tone specific card, context modal (3 URL lists), build modal, and edit modal. Pipeline cleanup: remove tone analyzer step, update editor/writer to drop separate toneGuide param, remove blog settings from project settings page.

**Tech Stack:** Go, SQLite (Goose), templ, vanilla JS, DaisyUI/Tailwind, SSE streaming with tool calls via OpenRouter API, Brave Search API.

---

### Task 1: Database Migration

**Files:**
- Create: `migrations/013_voice_tone_profiles.sql`

- [ ] **Step 1: Write the migration**

```sql
-- +goose Up
CREATE TABLE voice_tone_profiles (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    voice_analysis TEXT NOT NULL DEFAULT '',
    content_types TEXT NOT NULL DEFAULT '',
    should_avoid TEXT NOT NULL DEFAULT '',
    should_use TEXT NOT NULL DEFAULT '',
    style_inspiration TEXT NOT NULL DEFAULT '',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id)
);

-- +goose Down
DROP TABLE voice_tone_profiles;
```

- [ ] **Step 2: Run migration**

Run: `make restart`
Expected: Server starts without errors.

- [ ] **Step 3: Commit**

```bash
git add migrations/013_voice_tone_profiles.sql
git commit -m "feat: add voice_tone_profiles table"
```

---

### Task 2: Voice Tone Store Methods

**Files:**
- Create: `internal/store/voice_tone.go`
- Create: `internal/store/voice_tone_test.go`
- Modify: `internal/store/interfaces.go`
- Modify: `internal/store/profile.go` (BuildProfileString)

- [ ] **Step 1: Write the failing tests**

```go
// internal/store/voice_tone_test.go
package store

import (
	"strings"
	"testing"
)

func TestUpsertVoiceToneProfile(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	vt := VoiceToneProfile{
		VoiceAnalysis:    "Conversational and direct.",
		ContentTypes:     "Educational, how-to guides.",
		ShouldAvoid:      "Jargon, em dashes.",
		ShouldUse:        "Short sentences, active voice.",
		StyleInspiration: "Punchy, newsletter-style writing.",
	}

	err := q.UpsertVoiceToneProfile(p.ID, vt)
	if err != nil {
		t.Fatalf("upsert: %v", err)
	}

	got, err := q.GetVoiceToneProfile(p.ID)
	if err != nil {
		t.Fatalf("get: %v", err)
	}
	if got.VoiceAnalysis != "Conversational and direct." {
		t.Errorf("unexpected voice_analysis: %s", got.VoiceAnalysis)
	}

	// Update
	vt.VoiceAnalysis = "Formal and authoritative."
	q.UpsertVoiceToneProfile(p.ID, vt)
	got, _ = q.GetVoiceToneProfile(p.ID)
	if got.VoiceAnalysis != "Formal and authoritative." {
		t.Errorf("expected updated value, got: %s", got.VoiceAnalysis)
	}
}

func TestGetVoiceToneProfile_NotFound(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	_, err := q.GetVoiceToneProfile(p.ID)
	if err == nil {
		t.Fatal("expected error for non-existent profile")
	}
}

func TestBuildVoiceToneString(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	q.UpsertVoiceToneProfile(p.ID, VoiceToneProfile{
		VoiceAnalysis:    "Direct and warm.",
		ContentTypes:     "Educational.",
		ShouldAvoid:      "Buzzwords.",
		ShouldUse:        "Simple words.",
		StyleInspiration: "Newsletter style.",
	})

	s, err := q.BuildVoiceToneString(p.ID)
	if err != nil {
		t.Fatalf("build: %v", err)
	}
	if !strings.Contains(s, "Direct and warm.") {
		t.Errorf("expected voice analysis in string")
	}
	if !strings.Contains(s, "Buzzwords.") {
		t.Errorf("expected should_avoid in string")
	}
}

func TestBuildVoiceToneString_Empty(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")

	s, err := q.BuildVoiceToneString(p.ID)
	if err != nil {
		t.Fatalf("build: %v", err)
	}
	if s != "" {
		t.Errorf("expected empty string, got: %s", s)
	}
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/store/ -run TestUpsertVoiceTone -v`
Expected: FAIL — undefined types.

- [ ] **Step 3: Implement the store**

Create `internal/store/voice_tone.go`:

```go
package store

import (
	"fmt"
	"strings"
	"time"
)

type VoiceToneProfile struct {
	ID               int64
	ProjectID        int64
	VoiceAnalysis    string
	ContentTypes     string
	ShouldAvoid      string
	ShouldUse        string
	StyleInspiration string
	CreatedAt        time.Time
}

func (q *Queries) UpsertVoiceToneProfile(projectID int64, vt VoiceToneProfile) error {
	_, err := q.db.Exec(
		`INSERT INTO voice_tone_profiles (project_id, voice_analysis, content_types, should_avoid, should_use, style_inspiration)
		 VALUES (?, ?, ?, ?, ?, ?)
		 ON CONFLICT(project_id) DO UPDATE SET
		   voice_analysis = ?, content_types = ?, should_avoid = ?, should_use = ?, style_inspiration = ?`,
		projectID, vt.VoiceAnalysis, vt.ContentTypes, vt.ShouldAvoid, vt.ShouldUse, vt.StyleInspiration,
		vt.VoiceAnalysis, vt.ContentTypes, vt.ShouldAvoid, vt.ShouldUse, vt.StyleInspiration,
	)
	return err
}

func (q *Queries) GetVoiceToneProfile(projectID int64) (*VoiceToneProfile, error) {
	vt := &VoiceToneProfile{}
	err := q.db.QueryRow(
		`SELECT id, project_id, voice_analysis, content_types, should_avoid, should_use, style_inspiration, created_at
		 FROM voice_tone_profiles WHERE project_id = ?`, projectID,
	).Scan(&vt.ID, &vt.ProjectID, &vt.VoiceAnalysis, &vt.ContentTypes, &vt.ShouldAvoid, &vt.ShouldUse, &vt.StyleInspiration, &vt.CreatedAt)
	return vt, err
}

func (q *Queries) DeleteVoiceToneProfile(projectID int64) error {
	_, err := q.db.Exec("DELETE FROM voice_tone_profiles WHERE project_id = ?", projectID)
	return err
}

// BuildVoiceToneString formats the voice & tone profile as structured markdown.
func (q *Queries) BuildVoiceToneString(projectID int64) (string, error) {
	vt, err := q.GetVoiceToneProfile(projectID)
	if err != nil {
		return "", nil // no profile = empty, not an error
	}

	var b strings.Builder
	sections := []struct{ title, content string }{
		{"Voice Analysis", vt.VoiceAnalysis},
		{"Content Types", vt.ContentTypes},
		{"Should Avoid", vt.ShouldAvoid},
		{"Should Use", vt.ShouldUse},
		{"Style Inspiration", vt.StyleInspiration},
	}
	for _, s := range sections {
		if s.content != "" {
			fmt.Fprintf(&b, "### %s\n%s\n\n", s.title, s.content)
		}
	}
	return b.String(), nil
}
```

- [ ] **Step 4: Add VoiceToneStore interface**

In `internal/store/interfaces.go`, add after AudienceStore:

```go
// VoiceToneStore handles structured voice & tone profiles.
type VoiceToneStore interface {
	UpsertVoiceToneProfile(projectID int64, vt VoiceToneProfile) error
	GetVoiceToneProfile(projectID int64) (*VoiceToneProfile, error)
	DeleteVoiceToneProfile(projectID int64) error
	BuildVoiceToneString(projectID int64) (string, error)
}
```

Add compile-time check:
```go
var _ VoiceToneStore = (*Queries)(nil)
```

- [ ] **Step 5: Update BuildProfileString to use voice tone profiles**

In `internal/store/profile.go`, modify `BuildProfileString` — skip `voice_and_tone` from profile_sections and add voice tone profile instead:

Change the loop condition from:
```go
if s.Content == "" || s.Section == "audience" {
```
to:
```go
if s.Content == "" || s.Section == "audience" || s.Section == "voice_and_tone" {
```

After the audience block, add:
```go
	// Add voice & tone profile
	vtStr, _ := q.BuildVoiceToneString(projectID)
	if vtStr != "" {
		fmt.Fprintf(&b, "## Voice & Tone\n%s", vtStr)
	}
```

Apply the same changes to `BuildProfileStringExcluding` — skip `voice_and_tone` from profile_sections, add voice tone unless excluded.

- [ ] **Step 6: Run all store tests**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/store/ -v`
Expected: All PASS.

- [ ] **Step 7: Commit**

```bash
git add internal/store/voice_tone.go internal/store/voice_tone_test.go internal/store/interfaces.go internal/store/profile.go
git commit -m "feat: add voice tone profile store with CRUD and profile string integration"
```

---

### Task 3: Voice Tone Handler + Template + JS

**Files:**
- Create: `web/handlers/voice_tone.go`
- Modify: `web/handlers/profile.go` (delegate voice_and_tone routes, update show())
- Modify: `web/templates/profile.templ` (voice & tone card rendering + modals)
- Modify: `web/static/app.js` (voice & tone modal handlers)

This task combines handler, template, and JS because they're tightly coupled.

- [ ] **Step 1: Create voice tone handler**

Create `web/handlers/voice_tone.go` following the same pattern as `audience.go`:

**Handler struct:** `queries`, `aiClient`, `braveClient`, `model`

**Routes:**
- `GET context` — returns JSON with 3 URL arrays + notes
- `POST save-context` — saves 3 URL arrays + notes to project settings
- `GET profile` — returns voice tone profile as JSON
- `POST profile` — upsert voice tone profile from JSON body
- `GET generate` — SSE stream using `StreamWithTools`

**Context settings keys:**
- `voice_tone_blog_urls` — JSON `[{url, notes}]`
- `voice_tone_liked_articles` — JSON `[{url, notes}]`
- `voice_tone_inspiration` — JSON `[{url, notes}]`
- `profile_context_voice_and_tone` — free text notes

**getContext** returns:
```json
{"blog_urls": [...], "liked_articles": [...], "inspiration": [...], "notes": "..."}
```

**saveContext** accepts the same shape and saves each to its project setting key.

**getProfile** returns the VoiceToneProfile fields as JSON.

**saveProfile** accepts JSON with the 5 fields and calls `UpsertVoiceToneProfile`.

**streamGenerate:**
1. SSE headers + flusher
2. Gather context: product & positioning content, audience personas string, location, all 3 URL lists, notes, memory, existing voice tone profile
3. Pre-fetch all URLs (blog, liked, inspiration) using `tools.ExecuteFetch` — send status events for each
4. Build system prompt with:
   - Product & Positioning (business context)
   - Audience personas (who the writing is for)
   - All fetched content organized by source type
   - Existing voice tone profile (if rebuilding)
   - Context notes
   - Memory setting
   - Instructions to produce 5 structured sections
   - Rules: always English, analyze style not content, be specific
5. Tools: `web_search` + `submit_voice_tone`
6. The `submit_voice_tone` tool has the 5 required string fields: voice_analysis, content_types, should_avoid, should_use, style_inspiration
7. Executor returns `ai.ErrToolDone` on `submit_voice_tone`
8. SSE events: `status`, `result` (the submitted JSON), `done`, `error`
9. Temperature: 0.3, maxIterations: 15 (may need many fetches)

**submit_voice_tone tool schema** (same as spec):
```json
{"type":"object","properties":{"voice_analysis":{"type":"string","description":"Brand personality, formality level, warmth, how they relate to the reader"},"content_types":{"type":"string","description":"What content approaches the brand uses — educational, promotional, storytelling, etc."},"should_avoid":{"type":"string","description":"Words, phrases, patterns, and tones to never use"},"should_use":{"type":"string","description":"Characteristic vocabulary, phrases, sentence patterns, formatting conventions"},"style_inspiration":{"type":"string","description":"Writing style patterns observed from the inspiration sources"}},"required":["voice_analysis","content_types","should_avoid","should_use","style_inspiration"]}
```

- [ ] **Step 2: Update profile handler to delegate voice_and_tone routes**

In `web/handlers/profile.go`, add a `voiceToneHandler` field to `ProfileHandler` struct, initialize it in the constructor.

In `Handle()`, add before the generic suffix routes:
```go
case strings.HasPrefix(rest, "profile/voice_and_tone/"):
    vtRest := strings.TrimPrefix(rest, "profile/voice_and_tone/")
    h.voiceToneHandler.Handle(w, r, projectID, vtRest)
```

Update `show()` to populate voice & tone card data:
- Set `card.IsVoiceTone = true` for the voice_and_tone section
- Load the voice tone profile via `h.queries.GetVoiceToneProfile(projectID)`
- Load context settings (3 URL lists + notes)
- Pass to the card view

- [ ] **Step 3: Update profile template**

Add fields to `ProfileCardView`:
```go
IsVoiceTone      bool
VoiceToneProfile *store.VoiceToneProfile
VTBlogURLs       []store.SourceURL
VTLikedArticles  []store.SourceURL
VTInspiration    []store.SourceURL
```

Add voice & tone specific card rendering in the template (similar to audience but different structure):
- Header: title + badge (Approved if profile exists, Empty otherwise) + Build + Edit buttons
- Context subcard: shows blog URL count, liked article count, inspiration count, notes. "Edit context" button.
- Content: render all 5 sections with headers as markdown, expand/collapse

Add 3 modals:

**Voice & Tone Context modal** (`vt-context-modal`):
- 3 URL sections, each with header label, dynamic URL rows, add button:
  - "Blog URLs" — company blog listing pages
  - "Liked Articles" — specific posts they think are good
  - "Inspiration" — external blogs/articles
- Additional notes textarea
- Save/Cancel

**Voice & Tone Build modal** (`vt-build-modal`):
- Context summary
- Generate button
- Result textarea (hidden until generation completes)
- Save/Discard buttons

**Voice & Tone Edit modal** (`vt-edit-modal`):
- 5 labeled textareas: Voice Analysis, Content Types, Should Avoid, Should Use, Style Inspiration
- Save/Cancel

- [ ] **Step 4: Update JavaScript**

Add voice & tone handlers to `initProfilePage` in `web/static/app.js`:

**Context modal handlers (`.vt-context-btn`):**
- Fetch `/profile/voice_and_tone/context`
- Populate 3 URL sections + notes
- Save: POST to `/profile/voice_and_tone/save-context`
- Use `addContextURLRow` helper (already exists) for each URL section — need 3 separate containers

**Build modal handlers (`.vt-build-btn`):**
- Open modal, fetch context summary
- Generate: EventSource to `/profile/voice_and_tone/generate`
- Handle events: `status` (update button text), `result` (parse JSON, format into textarea with section headers), `done` (show save/discard), `error`
- Save: parse textarea back? No — store the raw JSON from the `result` event, POST to `/profile/voice_and_tone/profile` with the 5 fields
- Also show the formatted text in the textarea for review (read-only until save)

**Edit modal handlers (`.vt-edit-btn`):**
- Fetch `/profile/voice_and_tone/profile`
- Build 5 labeled textareas
- Save: POST to `/profile/voice_and_tone/profile` with the 5 fields

- [ ] **Step 5: Generate templ, build, test**

Run: `templ generate && go build ./... && make restart`

- [ ] **Step 6: Commit**

```bash
git add web/handlers/voice_tone.go web/handlers/profile.go web/templates/profile.templ web/static/app.js
git commit -m "feat: add voice & tone handler, template, and JS with structured output"
```

---

### Task 4: Pipeline Cleanup

**Files:**
- Delete: `internal/pipeline/steps/tone_analyzer.go`
- Modify: `internal/pipeline/orchestrator.go` (remove tone_analyzer from dependencies)
- Modify: `internal/pipeline/steps/editor.go` (remove toneGuide parsing)
- Modify: `internal/pipeline/steps/writer.go` (remove toneGuide parsing)
- Modify: `internal/prompt/builder.go` (remove toneGuide param from ForEditor/ForWriter, remove ForToneAnalyzer)
- Modify: `internal/tools/registry.go` (remove tone_analyzer tools)
- Modify: `cmd/server/main.go` (remove ToneAnalyzerStep initialization)

- [ ] **Step 1: Delete tone_analyzer.go**

```bash
rm internal/pipeline/steps/tone_analyzer.go
```

- [ ] **Step 2: Remove from orchestrator**

In `internal/pipeline/orchestrator.go`, remove this line from `StepDependencies()`:
```go
"tone_analyzer":  {},
```

- [ ] **Step 3: Update editor step**

In `internal/pipeline/steps/editor.go`, remove lines 50-56 (the toneGuide parsing):
```go
	var toneGuide string
	if toneOutput, ok := input.PriorOutputs["tone_analyzer"]; ok {
		var toneResult struct{ ToneGuide string `json:"tone_guide"` }
		if json.Unmarshal([]byte(toneOutput), &toneResult) == nil {
			toneGuide = toneResult.ToneGuide
		}
	}
```

Update the ForEditor call to remove toneGuide parameter:
```go
systemPrompt := s.Prompt.ForEditor(input.Profile, brief, sourcesText, frameworkBlock)
```

Remove the `"encoding/json"` import if no longer used.

- [ ] **Step 4: Update writer step**

In `internal/pipeline/steps/writer.go`, remove lines 34-40 (the toneGuide parsing):
```go
	var toneGuide string
	if toneOutput, ok := input.PriorOutputs["tone_analyzer"]; ok {
		var toneResult struct{ ToneGuide string `json:"tone_guide"` }
		if json.Unmarshal([]byte(toneOutput), &toneResult) == nil {
			toneGuide = toneResult.ToneGuide
		}
	}
```

Update the ForWriter call to remove toneGuide parameter:
```go
systemPrompt := s.Prompt.ForWriter(promptFile, input.Profile, editorOutput, rejectionReason)
```

Remove `"encoding/json"` import if no longer used.

- [ ] **Step 5: Update prompt builder**

In `internal/prompt/builder.go`:

Remove the `ForToneAnalyzer` method entirely (lines 185-211).

Update `ForEditor` signature from:
```go
func (b *Builder) ForEditor(profile, brief, sourcesText, frameworkBlock, toneGuide string) string
```
to:
```go
func (b *Builder) ForEditor(profile, brief, sourcesText, frameworkBlock string) string
```

Remove the toneGuide block (lines 244-248):
```go
	if toneGuide != "" {
		sb.WriteString("\n## Tone & style reference\nKeep this voice in mind when choosing the angle and editorial notes.\n\n")
		sb.WriteString(toneGuide)
		sb.WriteString("\n")
	}
```

Update `ForWriter` signature from:
```go
func (b *Builder) ForWriter(promptFile, profile, editorOutput, rejectionReason, toneGuide string) string
```
to:
```go
func (b *Builder) ForWriter(promptFile, profile, editorOutput, rejectionReason string) string
```

Remove the toneGuide block (lines 273-277):
```go
	if toneGuide != "" {
		sb.WriteString("\n## Tone & style reference (from company blog)\nUse this ONLY to match the writing tone, voice, and style. Do NOT use any factual information from the blog posts — all facts must come from the editorial outline above.\n\n")
		sb.WriteString(toneGuide)
		sb.WriteString("\n")
	}
```

- [ ] **Step 6: Remove tone_analyzer tools from registry**

In `internal/tools/registry.go`, remove lines 47-51:
```go
	r.stepTools["tone_analyzer"] = []ai.Tool{fetchTool, submitTool(
		"submit_tone_analysis",
		"Submit the tone and style guide based on the company's existing blog posts.",
		`{...}`,
	)}
```

- [ ] **Step 7: Remove ToneAnalyzerStep from main.go**

In `cmd/server/main.go`, remove the ToneAnalyzerStep line from the orchestrator initialization:
```go
&steps.ToneAnalyzerStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, ProjectSettings: queries, Model: contentModel},
```

- [ ] **Step 8: Check for splitURLs usage**

The `splitURLs` function in `common.go` was used by `tone_analyzer.go`. Verify it's still used by other steps. If not, remove it.

Run: `grep -r "splitURLs" --include="*.go" internal/pipeline/steps/`

- [ ] **Step 9: Verify build and tests**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go build ./... && go test ./...`
Expected: All pass, no compilation errors.

- [ ] **Step 10: Commit**

```bash
git add -A
git commit -m "feat: remove tone analyzer pipeline step — voice & tone now lives in profile"
```

---

### Task 5: Settings Page Cleanup

**Files:**
- Modify: `web/templates/project_settings.templ`
- Modify: `web/handlers/project_settings.go`

- [ ] **Step 1: Remove blog fields from settings template**

In `web/templates/project_settings.templ`, remove the "Blog URL" card (lines 46-57):
```
			<div class="mb-4">
				@components.Card("Blog URL") {
					...
				}
			</div>
```

Remove `CompanyBlog` and `BlogNotes` from `ProjectSettingsData` struct.

- [ ] **Step 2: Update settings handler**

In `web/handlers/project_settings.go`:

Remove from `show()`:
```go
CompanyBlog:           settings["company_blog"],
BlogNotes:             settings["blog_notes"],
```

Remove from `save()`:
```go
h.queries.SetProjectSetting(projectID, "company_blog", r.FormValue("company_blog"))
h.queries.SetProjectSetting(projectID, "blog_notes", r.FormValue("blog_notes"))
```

- [ ] **Step 3: Generate templ and verify**

Run: `templ generate && go build ./...`

- [ ] **Step 4: Commit**

```bash
git add web/templates/project_settings.templ web/handlers/project_settings.go
git commit -m "feat: remove blog URL from settings — now managed in Voice & Tone context"
```

---

### Task 6: Final Integration and Verification

**Files:**
- Modify: `web/handlers/profile.go` (skip voice_and_tone from generic section ops)

- [ ] **Step 1: Guard generic section handlers**

In `saveSection` and `streamGenerate` and `listVersions`, after the `isValidSection` check, add:
```go
if section == "audience" || section == "voice_and_tone" {
    http.NotFound(w, r)
    return
}
```

This ensures the audience and voice_and_tone sections are only handled by their dedicated handlers.

- [ ] **Step 2: Run all tests**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./...`
Expected: All pass.

- [ ] **Step 3: Full end-to-end verification**

Run: `make restart`

Verify:
1. Profile page: Voice & Tone card shows structured content or "empty" state
2. Edit context: 3 URL sections (blog, liked, inspiration) + notes
3. Build: fetches URLs, generates 5 structured sections, review in textarea, save/discard
4. Edit: 5 textareas, save updates
5. Pipeline runs work without tone analyzer step
6. Settings page no longer shows blog URL fields
7. Other sections (Product & Positioning, Audience) still work
8. `BuildProfileString` includes structured voice & tone in pipeline prompts

- [ ] **Step 4: Commit**

```bash
git add -A
git commit -m "feat: complete voice & tone integration — guards, verification"
```
