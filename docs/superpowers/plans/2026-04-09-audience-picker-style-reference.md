# Audience Picker and Style Reference Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add two conditional pipeline steps — an `audience_picker` that selects a target persona (or educational/commentary off-mode) before `brand_enricher`, and a `style_reference` step that fetches 2-3 high-quality verbatim blog examples before `write`.

**Architecture:** Both new steps follow the existing `StepRunner` shape. Each has a single mandatory submission tool, a dedicated prompt builder method, and a small parser/formatter module in `internal/pipeline/` for downstream steps to consume. The scheduler (`CreateDefaultPipelineSteps`) conditionally inserts each step only when its input exists (personas for audience picker, `blog_url` project setting for style reference). The orchestrator applies the existing dynamic-dep trick so `brand_enricher` waits for `audience_picker` when it is present, and `write` waits for `style_reference` when it is present. Three downstream prompt builders (`ForBrandEnricher`, `ForEditor`, `ForWriter`) gain a trailing optional `audienceBlock` argument; `ForWriter` additionally gains a trailing `styleReferenceBlock` argument. Empty string means "skipped."

**Tech Stack:** Go (standard library plus `encoding/json`), SQLite (migrations with CHECK-constraint rebuild), existing `ai.Client` + `tools.Registry` + `prompt.Builder` + `pipeline.StepRunner` abstractions, existing SSE streaming, existing JS step-card renderer.

**Spec:** `docs/superpowers/specs/2026-04-09-audience-picker-style-reference-design.md`

---

## File Structure

**New files:**

- `migrations/021_audience_and_style_steps.sql` — CHECK constraint rebuild to allow new step types
- `internal/pipeline/audience.go` — `AudienceSelection` struct, `ParseAudienceSelection`, `FormatAudienceBlock`
- `internal/pipeline/audience_test.go`
- `internal/pipeline/style_reference.go` — `StyleReference` struct, `ParseStyleReference`, `FormatStyleReferenceBlock`
- `internal/pipeline/style_reference_test.go`
- `internal/pipeline/steps/audience_picker.go` — `AudiencePickerStep` runner
- `internal/pipeline/steps/audience_picker_test.go`
- `internal/pipeline/steps/style_reference.go` — `StyleReferenceStep` runner
- `internal/pipeline/steps/style_reference_test.go`

**Modified files:**

- `internal/tools/registry.go` — register tools for `audience_picker` and `style_reference` step keys; add `StyleReferenceMaxFetches` constant
- `internal/prompt/builder.go` — `ForAudiencePicker`, `ForStyleReference`; extend `ForBrandEnricher`, `ForEditor`, `ForWriter` signatures
- `internal/prompt/builder_test.go` — smoke tests for all of the above
- `internal/pipeline/orchestrator.go` — `StepDependencies()` entries and dynamic-dep checks
- `internal/pipeline/orchestrator_test.go` — scheduling/dep tests
- `internal/pipeline/steps/brand_enricher.go` — parse audience from prior outputs, pass block
- `internal/pipeline/steps/editor.go` — parse audience from prior outputs, pass block
- `internal/pipeline/steps/writer.go` — parse audience + style_reference from prior outputs, pass blocks
- `internal/store/steps.go` — `CreateDefaultPipelineSteps` becomes project-aware and conditionally inserts new steps
- `internal/store/steps_test.go` — new scheduling tests
- `cmd/server/main.go` — register `AudiencePickerStep` and `StyleReferenceStep` with the orchestrator
- `web/static/js/step-cards.js` — step labels for the two new step types
- `web/static/js/renderers/step-output.js` — `renderStepOutput` branches for `Audience Picker` and `Style Reference`
- `web/templates/pipeline.templ` — muted "skipped" row treatment for the two new step types

---

## Build Sequence Rationale

Tasks are ordered so the tree compiles and tests pass after every task:

1. Migration first (enables inserting new step types in tests).
2. Pure helpers (`audience.go`, `style_reference.go`) — no dependencies.
3. Tools and prompts — still no dependencies on runners.
4. Step runners — depend on tools and prompts.
5. Downstream step consumers — depend on helpers.
6. Orchestrator + scheduler — depend on all of the above.
7. `cmd/server/main.go` wiring — picks up the new runners.
8. Frontend — additive, last.
9. Manual smoke test.

No task breaks an earlier one.

---

## Task 1: Add step types to CHECK constraint (migration 021)

**Files:**
- Create: `migrations/021_audience_and_style_steps.sql`

### Context

SQLite enforces `step_type` via a CHECK constraint. The current constraint (see `migrations/020_claim_verifier_rename.sql`) lists `'research','brand_enricher','claim_verifier','tone_analyzer','editor','write','plan_waterfall'`. SQLite cannot alter a CHECK constraint in place — the pattern used in every prior step-adding migration is the rebuild dance: create a new table, copy rows, drop old, rename new.

All prior migrations in this repo include both an "up" and a "down" section in the same `.sql` file, separated by the marker `-- DOWN`. Confirm this by opening `migrations/020_claim_verifier_rename.sql` before you write yours so your file matches the existing pattern exactly. If the marker differs (e.g. `-- +migrate Down`), use whatever marker that file uses.

- [ ] **Step 1: Read migration 020 to confirm the marker and rebuild pattern**

Run: read `migrations/020_claim_verifier_rename.sql` end to end. Note the exact up/down marker string and the `CREATE TABLE pipeline_steps_new ... INSERT INTO pipeline_steps_new SELECT ... DROP TABLE pipeline_steps; ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;` structure.

- [ ] **Step 2: Create `migrations/021_audience_and_style_steps.sql`**

Matching the exact pattern of migration 020 (including whatever marker it uses — placeholder `-- DOWN` below), write:

```sql
-- Add audience_picker and style_reference to pipeline_steps.step_type CHECK.
-- SQLite does not support altering CHECK constraints in place, so we rebuild.

CREATE TABLE pipeline_steps_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pipeline_run_id INTEGER NOT NULL,
    step_type TEXT NOT NULL CHECK(step_type IN ('research','brand_enricher','claim_verifier','tone_analyzer','audience_picker','editor','style_reference','write','plan_waterfall')),
    status TEXT NOT NULL DEFAULT 'pending',
    input TEXT NOT NULL DEFAULT '',
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    tool_calls TEXT NOT NULL DEFAULT '',
    usage TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pipeline_run_id) REFERENCES pipeline_runs(id) ON DELETE CASCADE
);

INSERT INTO pipeline_steps_new (
    id, pipeline_run_id, step_type, status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
)
SELECT
    id, pipeline_run_id, step_type, status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
FROM pipeline_steps;

DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;

-- DOWN

CREATE TABLE pipeline_steps_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pipeline_run_id INTEGER NOT NULL,
    step_type TEXT NOT NULL CHECK(step_type IN ('research','brand_enricher','claim_verifier','tone_analyzer','editor','write','plan_waterfall')),
    status TEXT NOT NULL DEFAULT 'pending',
    input TEXT NOT NULL DEFAULT '',
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    tool_calls TEXT NOT NULL DEFAULT '',
    usage TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (pipeline_run_id) REFERENCES pipeline_runs(id) ON DELETE CASCADE
);

INSERT INTO pipeline_steps_new (
    id, pipeline_run_id, step_type, status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
)
SELECT
    id, pipeline_run_id, step_type, status, input, output, thinking, tool_calls, usage, sort_order, created_at, updated_at
FROM pipeline_steps
WHERE step_type NOT IN ('audience_picker','style_reference');

DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;
```

**Important:** If migration 020 does NOT have a `-- DOWN` marker (i.e. migrations in this repo are up-only), omit the DOWN section entirely. Copy the exact structure.

**Verify you copied every column** from the current `pipeline_steps` schema. The column list above is from reading migration 020; if that migration added or removed columns after the list above, match what's actually there.

- [ ] **Step 3: Run the migration via the existing migration runner and confirm the app boots**

Run: `make start` (per project convention, always use make start / make restart — see `CLAUDE.md`). Watch the startup log. Expected: no migration errors, server comes up on its usual port.

If you don't want to keep the dev server running, `make stop` afterwards.

- [ ] **Step 4: Verify the new step types are accepted**

Run: `sqlite3 marketminded.db "INSERT INTO pipeline_runs (project_id, brief) VALUES (1, 'migration test'); SELECT last_insert_rowid();"` — note the run id (call it `$RUN`).

Then: `sqlite3 marketminded.db "INSERT INTO pipeline_steps (pipeline_run_id, step_type, sort_order) VALUES ($RUN, 'audience_picker', 99); INSERT INTO pipeline_steps (pipeline_run_id, step_type, sort_order) VALUES ($RUN, 'style_reference', 100);"`

Expected: no CHECK constraint violation.

Clean up: `sqlite3 marketminded.db "DELETE FROM pipeline_runs WHERE id = $RUN;"`

If project id 1 doesn't exist in your dev DB, pick any existing project id first with `sqlite3 marketminded.db "SELECT id FROM projects LIMIT 1;"`.

- [ ] **Step 5: Commit**

```bash
git add migrations/021_audience_and_style_steps.sql
git commit -m "feat: allow audience_picker and style_reference step types"
```

---

## Task 2: `pipeline/audience.go` — types, parser, formatter

**Files:**
- Create: `internal/pipeline/audience.go`
- Create: `internal/pipeline/audience_test.go`

### Context

This is a pure-Go module, no external deps, no DB. It defines the on-wire shape of the audience picker's step output, parses it, and formats it into a prompt block consumed by downstream steps. The formatter must return `""` for a nil input — downstream prompt builders use that to detect "step was skipped" and omit the section. Rules are in the spec (`## Audience Picker Step → Step output` and `## Mode semantics`).

- [ ] **Step 1: Write the failing tests**

Create `internal/pipeline/audience_test.go`:

```go
package pipeline

import (
	"strings"
	"testing"
)

func TestParseAudienceSelection_Persona(t *testing.T) {
	raw := `{
		"mode": "persona",
		"persona_id": 7,
		"persona_label": "Professional chef",
		"persona_summary": "A working chef who buys knives for the job.",
		"reasoning": "Topic is about mid-tier chef knives.",
		"guidance_for_writer": "Recommend mid-tier, avoid the cheapest SKU."
	}`
	sel, err := ParseAudienceSelection(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if sel.Mode != "persona" {
		t.Errorf("mode: want persona, got %q", sel.Mode)
	}
	if sel.PersonaID == nil || *sel.PersonaID != 7 {
		t.Errorf("persona_id: want 7, got %v", sel.PersonaID)
	}
	if sel.PersonaLabel != "Professional chef" {
		t.Errorf("persona_label mismatch: %q", sel.PersonaLabel)
	}
}

func TestParseAudienceSelection_Educational(t *testing.T) {
	raw := `{
		"mode": "educational",
		"persona_id": null,
		"reasoning": "Topic is a how-X-works piece.",
		"guidance_for_writer": "Speak to a curious learner, no sales tone."
	}`
	sel, err := ParseAudienceSelection(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if sel.Mode != "educational" {
		t.Errorf("mode: %q", sel.Mode)
	}
	if sel.PersonaID != nil {
		t.Errorf("expected nil persona_id, got %v", *sel.PersonaID)
	}
}

func TestParseAudienceSelection_Commentary(t *testing.T) {
	raw := `{
		"mode": "commentary",
		"persona_id": null,
		"reasoning": "Industry reaction piece.",
		"guidance_for_writer": "Informed reader, commentary register."
	}`
	sel, err := ParseAudienceSelection(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if sel.Mode != "commentary" {
		t.Errorf("mode: %q", sel.Mode)
	}
}

func TestParseAudienceSelection_MissingMode(t *testing.T) {
	_, err := ParseAudienceSelection(`{"reasoning":"x","guidance_for_writer":"y"}`)
	if err == nil {
		t.Fatal("expected error for missing mode")
	}
}

func TestParseAudienceSelection_InvalidMode(t *testing.T) {
	_, err := ParseAudienceSelection(`{"mode":"marketing","reasoning":"x","guidance_for_writer":"y"}`)
	if err == nil {
		t.Fatal("expected error for invalid mode")
	}
}

func TestParseAudienceSelection_PersonaMissingID(t *testing.T) {
	_, err := ParseAudienceSelection(`{"mode":"persona","persona_id":null,"reasoning":"x","guidance_for_writer":"y"}`)
	if err == nil {
		t.Fatal("expected error: mode=persona requires persona_id")
	}
}

func TestParseAudienceSelection_EducationalWithPersonaID(t *testing.T) {
	_, err := ParseAudienceSelection(`{"mode":"educational","persona_id":3,"reasoning":"x","guidance_for_writer":"y"}`)
	if err == nil {
		t.Fatal("expected error: off-mode must not have persona_id")
	}
}

func TestParseAudienceSelection_EmptyGuidance(t *testing.T) {
	_, err := ParseAudienceSelection(`{"mode":"educational","persona_id":null,"reasoning":"x","guidance_for_writer":""}`)
	if err == nil {
		t.Fatal("expected error for empty guidance_for_writer")
	}
}

func TestFormatAudienceBlock_Nil(t *testing.T) {
	if got := FormatAudienceBlock(nil); got != "" {
		t.Errorf("nil selection should format to empty, got %q", got)
	}
}

func TestFormatAudienceBlock_Persona(t *testing.T) {
	pid := int64(7)
	sel := &AudienceSelection{
		Mode:            "persona",
		PersonaID:       &pid,
		PersonaLabel:    "Professional chef",
		PersonaSummary:  "A working chef who buys knives for the job.",
		Reasoning:       "Topic is about mid-tier chef knives.",
		GuidanceForWriter: "Recommend mid-tier, avoid the cheapest SKU.",
	}
	got := FormatAudienceBlock(sel)
	if !strings.Contains(got, "## Audience target") {
		t.Errorf("missing header")
	}
	if !strings.Contains(got, "Professional chef") {
		t.Errorf("missing label")
	}
	if !strings.Contains(got, "A working chef who buys knives for the job.") {
		t.Errorf("missing summary")
	}
	if !strings.Contains(got, "Recommend mid-tier, avoid the cheapest SKU.") {
		t.Errorf("missing guidance")
	}
}

func TestFormatAudienceBlock_Educational(t *testing.T) {
	sel := &AudienceSelection{
		Mode:            "educational",
		Reasoning:       "How-it-works piece.",
		GuidanceForWriter: "Speak to a curious learner.",
	}
	got := FormatAudienceBlock(sel)
	if !strings.Contains(got, "## Audience target") {
		t.Errorf("missing header")
	}
	if !strings.Contains(got, "educational") {
		t.Errorf("missing mode label")
	}
	if strings.Contains(got, "Persona:") {
		t.Errorf("should not render persona field in off-mode")
	}
	if !strings.Contains(got, "Speak to a curious learner.") {
		t.Errorf("missing guidance")
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `go test ./internal/pipeline -run TestParseAudienceSelection -v`
Expected: FAIL with "undefined: ParseAudienceSelection" / "undefined: AudienceSelection" / "undefined: FormatAudienceBlock".

- [ ] **Step 3: Create `internal/pipeline/audience.go`**

```go
package pipeline

import (
	"encoding/json"
	"fmt"
	"strings"
)

// AudienceSelection is the step output of the audience_picker step. It tells
// downstream steps who the post addresses and how to tailor the piece.
type AudienceSelection struct {
	Mode              string `json:"mode"` // persona | educational | commentary
	PersonaID         *int64 `json:"persona_id,omitempty"`
	PersonaLabel      string `json:"persona_label,omitempty"`
	PersonaSummary    string `json:"persona_summary,omitempty"`
	Reasoning         string `json:"reasoning"`
	GuidanceForWriter string `json:"guidance_for_writer"`
}

// ParseAudienceSelection parses the audience_picker step output JSON into a
// struct and enforces the cross-field rules the tool executor should have
// caught. Returns a descriptive error on any violation.
func ParseAudienceSelection(raw string) (*AudienceSelection, error) {
	if strings.TrimSpace(raw) == "" {
		return nil, fmt.Errorf("empty audience selection")
	}
	var sel AudienceSelection
	if err := json.Unmarshal([]byte(raw), &sel); err != nil {
		return nil, fmt.Errorf("parse audience selection: %w", err)
	}
	switch sel.Mode {
	case "persona":
		if sel.PersonaID == nil {
			return nil, fmt.Errorf("mode=persona requires non-null persona_id")
		}
	case "educational", "commentary":
		if sel.PersonaID != nil {
			return nil, fmt.Errorf("mode=%s must not have persona_id", sel.Mode)
		}
	default:
		return nil, fmt.Errorf("invalid mode %q (want persona | educational | commentary)", sel.Mode)
	}
	if strings.TrimSpace(sel.GuidanceForWriter) == "" {
		return nil, fmt.Errorf("guidance_for_writer must not be empty")
	}
	return &sel, nil
}

// FormatAudienceBlock renders an audience selection as a prompt block. Returns
// an empty string for a nil selection, which downstream prompt builders use to
// omit the section entirely when the audience_picker step was skipped.
func FormatAudienceBlock(sel *AudienceSelection) string {
	if sel == nil {
		return ""
	}
	var b strings.Builder
	b.WriteString("\n## Audience target\n")
	switch sel.Mode {
	case "persona":
		fmt.Fprintf(&b, "Mode: persona\n")
		if sel.PersonaLabel != "" {
			fmt.Fprintf(&b, "Persona: %s\n", sel.PersonaLabel)
		}
		if sel.PersonaSummary != "" {
			fmt.Fprintf(&b, "Summary: %s\n", sel.PersonaSummary)
		}
	case "educational":
		b.WriteString("Mode: educational (no persona — speak to a curious learner of the topic)\n")
	case "commentary":
		b.WriteString("Mode: commentary (no persona — speak to an informed reader of this space)\n")
	}
	if sel.Reasoning != "" {
		fmt.Fprintf(&b, "Reasoning: %s\n", sel.Reasoning)
	}
	fmt.Fprintf(&b, "Writer guidance: %s\n", sel.GuidanceForWriter)
	return b.String()
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `go test ./internal/pipeline -run "TestParseAudienceSelection|TestFormatAudienceBlock" -v`
Expected: PASS.

- [ ] **Step 5: Run the full package to check no regression**

Run: `go test ./internal/pipeline/...`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add internal/pipeline/audience.go internal/pipeline/audience_test.go
git commit -m "feat: audience selection type, parser, and prompt block formatter"
```

---

## Task 3: `pipeline/style_reference.go` — types, parser, formatter

**Files:**
- Create: `internal/pipeline/style_reference.go`
- Create: `internal/pipeline/style_reference_test.go`

### Context

Same shape as Task 2 but for the style reference step. Parser enforces 2-3 examples and a minimum body length of 400 characters (protects against the model handing back a truncated summary). Formatter emits the full "do not copy" warning block with verbatim post bodies.

- [ ] **Step 1: Write the failing tests**

Create `internal/pipeline/style_reference_test.go`:

```go
package pipeline

import (
	"strings"
	"testing"
)

func bodyOfLen(n int) string {
	b := strings.Builder{}
	for b.Len() < n {
		b.WriteString("lorem ipsum dolor sit amet, consectetur adipiscing elit. ")
	}
	return b.String()[:n]
}

func TestParseStyleReference_TwoExamples(t *testing.T) {
	raw := `{
		"examples": [
			{"url":"https://brand.example/a","title":"A","body":"` + bodyOfLen(500) + `","why_chosen":"sharp voice"},
			{"url":"https://brand.example/b","title":"B","body":"` + bodyOfLen(500) + `","why_chosen":"tight structure"}
		],
		"reasoning": "Two best-written posts on the blog."
	}`
	ref, err := ParseStyleReference(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if len(ref.Examples) != 2 {
		t.Errorf("want 2 examples, got %d", len(ref.Examples))
	}
}

func TestParseStyleReference_ThreeExamples(t *testing.T) {
	raw := `{
		"examples": [
			{"url":"https://brand.example/a","title":"A","body":"` + bodyOfLen(500) + `","why_chosen":"a"},
			{"url":"https://brand.example/b","title":"B","body":"` + bodyOfLen(500) + `","why_chosen":"b"},
			{"url":"https://brand.example/c","title":"C","body":"` + bodyOfLen(500) + `","why_chosen":"c"}
		],
		"reasoning": "top three"
	}`
	ref, err := ParseStyleReference(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if len(ref.Examples) != 3 {
		t.Errorf("want 3 examples, got %d", len(ref.Examples))
	}
}

func TestParseStyleReference_RejectsOne(t *testing.T) {
	raw := `{"examples":[{"url":"u","title":"t","body":"` + bodyOfLen(500) + `","why_chosen":"w"}],"reasoning":"x"}`
	if _, err := ParseStyleReference(raw); err == nil {
		t.Fatal("expected error: only 1 example")
	}
}

func TestParseStyleReference_RejectsFour(t *testing.T) {
	raw := `{
		"examples": [
			{"url":"a","title":"A","body":"` + bodyOfLen(500) + `","why_chosen":"a"},
			{"url":"b","title":"B","body":"` + bodyOfLen(500) + `","why_chosen":"b"},
			{"url":"c","title":"C","body":"` + bodyOfLen(500) + `","why_chosen":"c"},
			{"url":"d","title":"D","body":"` + bodyOfLen(500) + `","why_chosen":"d"}
		],
		"reasoning": "too many"
	}`
	if _, err := ParseStyleReference(raw); err == nil {
		t.Fatal("expected error: 4 examples")
	}
}

func TestParseStyleReference_ShortBodyRejected(t *testing.T) {
	raw := `{
		"examples": [
			{"url":"a","title":"A","body":"` + bodyOfLen(500) + `","why_chosen":"a"},
			{"url":"b","title":"B","body":"too short","why_chosen":"b"}
		],
		"reasoning": "x"
	}`
	if _, err := ParseStyleReference(raw); err == nil {
		t.Fatal("expected error: short body")
	}
}

func TestParseStyleReference_MissingField(t *testing.T) {
	raw := `{
		"examples": [
			{"url":"a","body":"` + bodyOfLen(500) + `","why_chosen":"a"},
			{"url":"b","title":"B","body":"` + bodyOfLen(500) + `","why_chosen":"b"}
		],
		"reasoning": "x"
	}`
	if _, err := ParseStyleReference(raw); err == nil {
		t.Fatal("expected error: missing title on example[0]")
	}
}

func TestFormatStyleReferenceBlock_Nil(t *testing.T) {
	if got := FormatStyleReferenceBlock(nil); got != "" {
		t.Errorf("nil ref should format to empty, got %q", got)
	}
}

func TestFormatStyleReferenceBlock_FullRender(t *testing.T) {
	body1 := bodyOfLen(500)
	body2 := bodyOfLen(500)
	ref := &StyleReference{
		Examples: []StyleReferenceExample{
			{URL: "https://brand.example/a", Title: "Alpha", Body: body1, WhyChosen: "sharp"},
			{URL: "https://brand.example/b", Title: "Beta", Body: body2, WhyChosen: "tight"},
		},
		Reasoning: "top two",
	}
	got := FormatStyleReferenceBlock(ref)
	if !strings.Contains(got, "## Style reference") {
		t.Errorf("missing header")
	}
	if !strings.Contains(got, "Do NOT copy sentences, facts, or structure") {
		t.Errorf("missing do-not-copy instruction")
	}
	if !strings.Contains(got, "### Example 1: Alpha") {
		t.Errorf("missing example 1 header")
	}
	if !strings.Contains(got, "### Example 2: Beta") {
		t.Errorf("missing example 2 header")
	}
	if !strings.Contains(got, body1) {
		t.Errorf("body 1 not embedded verbatim")
	}
	if !strings.Contains(got, body2) {
		t.Errorf("body 2 not embedded verbatim")
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `go test ./internal/pipeline -run "TestParseStyleReference|TestFormatStyleReferenceBlock" -v`
Expected: FAIL with undefined symbols.

- [ ] **Step 3: Create `internal/pipeline/style_reference.go`**

```go
package pipeline

import (
	"encoding/json"
	"fmt"
	"strings"
)

const styleReferenceMinBodyChars = 400

// StyleReferenceExample is one verbatim post the style scout picked.
type StyleReferenceExample struct {
	URL       string `json:"url"`
	Title     string `json:"title"`
	Body      string `json:"body"`
	WhyChosen string `json:"why_chosen"`
}

// StyleReference is the step output of the style_reference step.
type StyleReference struct {
	Examples  []StyleReferenceExample `json:"examples"`
	Reasoning string                  `json:"reasoning"`
}

// ParseStyleReference parses the style_reference step output JSON and enforces
// the invariants the tool executor should already have caught (2-3 examples,
// min body length, all required fields present).
func ParseStyleReference(raw string) (*StyleReference, error) {
	if strings.TrimSpace(raw) == "" {
		return nil, fmt.Errorf("empty style reference")
	}
	var ref StyleReference
	if err := json.Unmarshal([]byte(raw), &ref); err != nil {
		return nil, fmt.Errorf("parse style reference: %w", err)
	}
	if n := len(ref.Examples); n < 2 || n > 3 {
		return nil, fmt.Errorf("style reference must have 2-3 examples, got %d", n)
	}
	for i, ex := range ref.Examples {
		if strings.TrimSpace(ex.URL) == "" {
			return nil, fmt.Errorf("example[%d] missing url", i)
		}
		if strings.TrimSpace(ex.Title) == "" {
			return nil, fmt.Errorf("example[%d] missing title", i)
		}
		if strings.TrimSpace(ex.WhyChosen) == "" {
			return nil, fmt.Errorf("example[%d] missing why_chosen", i)
		}
		if len(ex.Body) < styleReferenceMinBodyChars {
			return nil, fmt.Errorf("example[%d] body is %d chars, minimum is %d (submit full post verbatim)", i, len(ex.Body), styleReferenceMinBodyChars)
		}
	}
	return &ref, nil
}

// FormatStyleReferenceBlock renders the style reference as a prompt block for
// the writer. Returns empty string for a nil ref (step was skipped). Each
// example body is included verbatim — this block is the whole value of the
// step.
func FormatStyleReferenceBlock(ref *StyleReference) string {
	if ref == nil {
		return ""
	}
	var b strings.Builder
	b.WriteString("\n## Style reference — match this voice\n")
	b.WriteString("The following posts are real, previously published pieces from this brand's blog. They are the ground truth for how this brand sounds. When writing the new post below, match their rhythm, sentence length, opener patterns, register, and overall feel. The reader should not be able to tell which post was written by AI.\n\n")
	b.WriteString("Do NOT copy sentences, facts, or structure from these examples. They are voice reference only. The new post's content comes from the claims block above.\n\n")
	for i, ex := range ref.Examples {
		fmt.Fprintf(&b, "### Example %d: %s\n", i+1, ex.Title)
		fmt.Fprintf(&b, "%s\n\n", ex.Body)
	}
	return b.String()
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `go test ./internal/pipeline -run "TestParseStyleReference|TestFormatStyleReferenceBlock" -v`
Expected: PASS.

- [ ] **Step 5: Run the full package**

Run: `go test ./internal/pipeline/...`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add internal/pipeline/style_reference.go internal/pipeline/style_reference_test.go
git commit -m "feat: style reference type, parser, and prompt block formatter"
```

---

## Task 4: Register `submit_audience_selection` tool

**Files:**
- Modify: `internal/tools/registry.go`

### Context

Tools are declared in `NewRegistry()` keyed by step type. Step runners fetch their tool list via `registry.ForStep("audience_picker")`. Follow the existing `submitTool` helper — see how `submit_editorial_outline` is declared on line 57-61 of `internal/tools/registry.go`. The audience picker uses the submission tool only (no fetch, no search), matching the editor.

- [ ] **Step 1: Edit `internal/tools/registry.go`**

Inside `NewRegistry()`, after the existing `r.stepTools["editor"] = ...` block and before `r.stepTools["topic_explore"] = ...`, add:

```go
	r.stepTools["audience_picker"] = []ai.Tool{submitTool(
		"submit_audience_selection",
		"Submit the audience selection. Call on your first response.",
		`{"type":"object","properties":{"mode":{"type":"string","enum":["persona","educational","commentary"],"description":"Pick persona when a real persona fits; off-modes only when nothing fits."},"persona_id":{"type":["integer","null"],"description":"Required when mode=persona, must match an existing persona id. Null otherwise."},"reasoning":{"type":"string","description":"1-3 sentences: why this target, and which competing options were rejected and why."},"guidance_for_writer":{"type":"string","description":"2-4 sentences of concrete guidance downstream steps must honor. When the topic involves a product recommendation, include at least one explicit 'do not recommend X to Y' constraint."}},"required":["mode","reasoning","guidance_for_writer"]}`,
	)}
```

- [ ] **Step 2: Build to verify compilation**

Run: `go build ./...`
Expected: no errors.

- [ ] **Step 3: Run the tools tests to catch any registry regression**

Run: `go test ./internal/tools/...`
Expected: PASS.

- [ ] **Step 4: Commit**

```bash
git add internal/tools/registry.go
git commit -m "feat: register submit_audience_selection tool"
```

---

## Task 5: Register `submit_style_reference` tool and fetch cap

**Files:**
- Modify: `internal/tools/registry.go`

### Context

Style reference uses `fetch_url` + a submission tool. No web search. A new constant `StyleReferenceMaxFetches` enforces a budget the step runner can check; the registry file is where the other `*SearchCap` constants live (line 15-19), so the new constant goes there too for discoverability, even though it's a fetch cap rather than a search cap.

- [ ] **Step 1: Edit the constants block in `internal/tools/registry.go`**

Change:

```go
const (
	ResearchSearchCap      = 30
	ClaimVerifierSearchCap = 15
	TopicExploreSearchCap  = 25
)
```

to:

```go
const (
	ResearchSearchCap      = 30
	ClaimVerifierSearchCap = 15
	TopicExploreSearchCap  = 25

	// StyleReferenceMaxFetches caps how many fetch_url calls the
	// style_reference step can make in total (index + candidate posts).
	StyleReferenceMaxFetches = 6
)
```

- [ ] **Step 2: Add the tool registration**

Inside `NewRegistry()`, after the `r.stepTools["editor"] = ...` block (and the `audience_picker` block from Task 4 if adjacent), add:

```go
	r.stepTools["style_reference"] = []ai.Tool{fetchTool, submitTool(
		"submit_style_reference",
		"Submit 2-3 verbatim blog posts as voice reference for the writer.",
		`{"type":"object","properties":{"examples":{"type":"array","minItems":2,"maxItems":3,"description":"2-3 high-quality posts from the brand's blog. Include the full body verbatim — no summarization, no editing.","items":{"type":"object","properties":{"url":{"type":"string"},"title":{"type":"string"},"body":{"type":"string","description":"Full post body verbatim, no summarization, no edits."},"why_chosen":{"type":"string","description":"One sentence on what makes this post a strong style exemplar."}},"required":["url","title","body","why_chosen"]}},"reasoning":{"type":"string","description":"Brief note on how the candidates were narrowed down."}},"required":["examples","reasoning"]}`,
	)}
```

- [ ] **Step 3: Build to verify compilation**

Run: `go build ./...`
Expected: no errors.

- [ ] **Step 4: Run the tools tests**

Run: `go test ./internal/tools/...`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add internal/tools/registry.go
git commit -m "feat: register submit_style_reference tool and fetch cap"
```

---

## Task 6: `ForAudiencePicker` prompt builder method

**Files:**
- Modify: `internal/prompt/builder.go`
- Modify: `internal/prompt/builder_test.go`

### Context

New method in the prompt builder that produces the audience picker's system prompt. The prompt must contain: date header, role, topic+brief, client profile, research output, a numbered list of personas (with all populated fields), decision rules with concrete anti-examples, the "do not recommend X to Y" constraint rule, and the mandatory-tool-call rule. The step runner passes personas as a pre-formatted string (built in the runner from `AudiencePersona` records) — this keeps the prompt builder a pure string function without DB access.

Pattern reference: `ForClaimVerifier` at line ~196 of `builder.go` — concise, one `fmt.Sprintf`. The new method can be similar but a bit longer because of the decision rules.

- [ ] **Step 1: Write the failing test**

Append to `internal/prompt/builder_test.go`:

```go
func TestForAudiencePicker_IncludesRequiredSections(t *testing.T) {
	b := &Builder{contentPrompts: map[string]string{}}
	personas := `1. [id=5] Professional chef
   description: a working chef who cooks every day
   pain_points: dull knives slow down the line
   push: wants tools that hold an edge
2. [id=7] Home cook
   description: cooks on weekends
   pain_points: wants something that looks nice`
	out := b.ForAudiencePicker(
		"chef knives under $100",
		"roundup of mid-tier chef knives",
		"Acme Cutlery — sells knives in three tiers",
		`{"claims":[],"sources":[]}`,
		personas,
	)
	checks := []string{
		"Today's date:",
		"audience strategist",
		"chef knives under $100",
		"roundup of mid-tier chef knives",
		"Acme Cutlery",
		"Professional chef",
		"Home cook",
		"do not recommend",
		"submit_audience_selection",
	}
	for _, want := range checks {
		if !strings.Contains(out, want) {
			t.Errorf("ForAudiencePicker output missing %q", want)
		}
	}
}
```

(If `strings` isn't already imported in the test file, add it to the imports.)

- [ ] **Step 2: Run the test to verify it fails**

Run: `go test ./internal/prompt -run TestForAudiencePicker -v`
Expected: FAIL with "b.ForAudiencePicker undefined".

- [ ] **Step 3: Add `ForAudiencePicker` to `internal/prompt/builder.go`**

Add this method below `ForClaimVerifier` (around line 227):

```go
// ForAudiencePicker builds the system prompt for the audience_picker step.
// personasBlock is a pre-formatted, numbered list of the project's personas
// (built by the step runner from AudiencePersona records).
func (b *Builder) ForAudiencePicker(topic, brief, profile, researchOutput, personasBlock string) string {
	return fmt.Sprintf(`%s

You are an audience strategist. Your job is to decide which reader this post is for so downstream steps can tailor product recommendations, framing, and voice to that reader. A bad audience pick causes the writer to recommend the wrong product to the wrong person, which is the single biggest failure mode in this pipeline.

## Topic
%s

## Brief
%s

## Client profile
%s

## Research output (for context — what the topic is really about)
%s

## Available personas
%s

## Decision rules
- Prefer `+"`mode: persona`"+`. Pick a real persona whenever one genuinely fits the topic.
- Use `+"`mode: educational`"+` only when the post is a reference/how-it-works piece that teaches the category rather than selling. Writer addresses "someone learning the topic."
- Use `+"`mode: commentary`"+` only when the post is an industry reaction, news commentary, or trend piece. Writer addresses "an informed reader of this space."
- Do NOT force a persona when none fits. A forced match is worse than an off-mode.

## Concrete anti-examples (the failures this step exists to prevent)
- If the post is about the CHEAPEST knife in the lineup, do not target a persona like "Professional chef." Pick a value-buyer persona if one exists, otherwise pick `+"`educational`"+`.
- If the post is a 50-seat TEAM plan, do not target "Freelancer." Pick the relevant team-size persona, otherwise pick `+"`commentary`"+`.
- If the post is about SMALL city cars and your only buyer persona is "Construction company," do not force that match. Pick `+"`educational`"+` or `+"`commentary`"+`.

## Guidance-for-writer rule (CRITICAL)
When the topic involves a product recommendation of any kind, `+"`guidance_for_writer`"+` MUST include at least one explicit "do not recommend X to Y" constraint. This is the instruction the writer and brand_enricher will honor literally. Without it, this step provides no value.

## CRITICAL: You MUST use tool calls
Every response MUST include a call to `+"`submit_audience_selection`"+`. A response with only text is treated as a failure. Reason briefly, then call the tool on your first response.`,
		b.DateHeader(), topic, brief, profile, researchOutput, personasBlock)
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `go test ./internal/prompt -run TestForAudiencePicker -v`
Expected: PASS.

- [ ] **Step 5: Run the full prompt package**

Run: `go test ./internal/prompt/...`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add internal/prompt/builder.go internal/prompt/builder_test.go
git commit -m "feat: prompt builder ForAudiencePicker"
```

---

## Task 7: `ForStyleReference` prompt builder method

**Files:**
- Modify: `internal/prompt/builder.go`
- Modify: `internal/prompt/builder_test.go`

### Context

Mirror of Task 6 but for the style reference step. The prompt includes: date header, role, blog URL, topic (context only), workflow, hard rules (including "do not summarize"), and mandatory-tool-call rule.

- [ ] **Step 1: Write the failing test**

Append to `internal/prompt/builder_test.go`:

```go
func TestForStyleReference_IncludesRequiredSections(t *testing.T) {
	b := &Builder{contentPrompts: map[string]string{}}
	out := b.ForStyleReference("https://brand.example/blog", "chef knives under $100")
	checks := []string{
		"Today's date:",
		"style scout",
		"https://brand.example/blog",
		"chef knives under $100",
		"verbatim",
		"Do not summarize",
		"submit_style_reference",
	}
	for _, want := range checks {
		if !strings.Contains(out, want) {
			t.Errorf("ForStyleReference output missing %q", want)
		}
	}
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `go test ./internal/prompt -run TestForStyleReference -v`
Expected: FAIL with "b.ForStyleReference undefined".

- [ ] **Step 3: Add `ForStyleReference` to `internal/prompt/builder.go`**

Add below `ForAudiencePicker`:

```go
// ForStyleReference builds the system prompt for the style_reference step.
func (b *Builder) ForStyleReference(blogURL, topic string) string {
	return fmt.Sprintf(`%s

You are a style scout. Your job is to pick the 2-3 highest-quality posts from this brand's blog and return them verbatim so a writer can imitate the house voice. Voice, not topic.

## Blog URL
%s

## Topic of the post being written (for context only — NOT a selection criterion)
%s

## Workflow
1. Fetch the blog URL above once with `+"`fetch_url`"+`. That's your index.
2. Extract post URLs from the index. Cap your candidate set at ~10.
3. Pick 3-5 that look most promising from title or preview alone. Fetch each with `+"`fetch_url`"+`.
4. Read the fetched posts. Pick the best 2-3 on writing quality: voice, rhythm, structure, specificity, a distinctive point of view. Ignore topic match — this is about HOW the brand writes, not WHAT it writes about.
5. Call `+"`submit_style_reference`"+` with those 2-3 posts' full body text verbatim.

## Hard rules
- **Do not summarize, rewrite, shorten, or "clean up" post bodies.** Whatever text came back from `+"`fetch_url`"+` for the chosen post is what goes into the `+"`body`"+` field. This step's value depends entirely on the writer seeing real house-voice sentences. Paraphrasing destroys the signal.
- Do not invent URLs. Only posts you actually fetched in this step are eligible.
- If the index has fewer than 2 viable posts, fetch what exists and submit 2 if possible. If fewer than 2 exist, fail explicitly rather than padding with low-quality posts.
- You have a total fetch budget of %d across this step (1 index + up to 5 candidates).

## CRITICAL: You MUST use tool calls
Every response MUST include a tool call (`+"`fetch_url`"+` or `+"`submit_style_reference`"+`). A response with only text is treated as a failure.`,
		b.DateHeader(), blogURL, topic, tools.StyleReferenceMaxFetches)
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `go test ./internal/prompt -run TestForStyleReference -v`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add internal/prompt/builder.go internal/prompt/builder_test.go
git commit -m "feat: prompt builder ForStyleReference"
```

---

## Task 8: `AudiencePickerStep` step runner

**Files:**
- Create: `internal/pipeline/steps/audience_picker.go`
- Create: `internal/pipeline/steps/audience_picker_test.go`

### Context

Step runner structure mirrors `EditorStep` in `internal/pipeline/steps/editor.go` — a thin wrapper around `RunWithTools` with pre/post logic. The runner needs to:
1. Read personas from the store (`AudienceStore.ListAudiencePersonas`).
2. Skip-safety net: if no personas (shouldn't happen because scheduler skips, but guard anyway), return an error.
3. Format personas into a numbered list string.
4. Call `ForAudiencePicker` to build the system prompt.
5. Invoke `RunWithTools` with `submit_audience_selection`.
6. Validate the output via `ParseAudienceSelection`, plus confirm the returned `persona_id` exists in the personas list.
7. Hydrate the final stored output with `persona_label` and `persona_summary` copied from the matching persona (the model only returned `persona_id`, we fill in the rest).

Step 7 is important: downstream consumers read `persona_label` and `persona_summary` without any DB access. The step output JSON must include them.

The test uses a fake `ai.Client` — check how existing step runner tests fake the client, if any. If no pattern exists, the simplest option is to test the hydration/validation logic in a helper function and leave the `Run` method lightly covered by the orchestrator integration tests in Task 13. For now we'll test the hydration helper directly.

- [ ] **Step 1: Check whether existing step runner tests fake the AI client**

Run: `ls internal/pipeline/steps/ && grep -l "ai\\.Client" internal/pipeline/steps/*_test.go 2>/dev/null`
Expected: likely only `common.go` and no step-level `_test.go` files that fake the full client. That's fine — we'll test the validation/hydration helper directly rather than the full `Run()`.

- [ ] **Step 2: Write the failing test**

Create `internal/pipeline/steps/audience_picker_test.go`:

```go
package steps

import (
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/store"
)

func TestHydrateAudienceSelection_Persona(t *testing.T) {
	personas := []store.AudiencePersona{
		{ID: 5, Label: "Professional chef", Description: "Works the line daily", PainPoints: "dull knives slow the line", Push: "wants edge retention", Pull: "premium steel", Anxiety: "cost", Habit: "reuses old tools"},
		{ID: 7, Label: "Home cook", Description: "weekend cooking"},
	}
	raw := `{"mode":"persona","persona_id":5,"reasoning":"mid-tier fits the pros","guidance_for_writer":"do not recommend the cheapest knife to pros"}`
	out, err := hydrateAudienceSelection(raw, personas)
	if err != nil {
		t.Fatalf("hydrate: %v", err)
	}
	if !strings.Contains(out, `"persona_label":"Professional chef"`) {
		t.Errorf("expected persona_label in output, got %s", out)
	}
	if !strings.Contains(out, "Works the line daily") {
		t.Errorf("expected persona_summary in output, got %s", out)
	}
}

func TestHydrateAudienceSelection_PersonaIDNotFound(t *testing.T) {
	personas := []store.AudiencePersona{{ID: 5, Label: "Professional chef"}}
	raw := `{"mode":"persona","persona_id":99,"reasoning":"x","guidance_for_writer":"y"}`
	if _, err := hydrateAudienceSelection(raw, personas); err == nil {
		t.Fatal("expected error: persona_id 99 not in list")
	}
}

func TestHydrateAudienceSelection_Educational(t *testing.T) {
	personas := []store.AudiencePersona{{ID: 5, Label: "Professional chef"}}
	raw := `{"mode":"educational","persona_id":null,"reasoning":"how-it-works","guidance_for_writer":"teach the topic"}`
	out, err := hydrateAudienceSelection(raw, personas)
	if err != nil {
		t.Fatalf("hydrate: %v", err)
	}
	if strings.Contains(out, "persona_label") {
		t.Errorf("off-mode should not hydrate persona_label, got %s", out)
	}
	if !strings.Contains(out, `"mode":"educational"`) {
		t.Errorf("mode not preserved, got %s", out)
	}
}

func TestFormatPersonasBlock(t *testing.T) {
	personas := []store.AudiencePersona{
		{ID: 5, Label: "Chef", Description: "works the line", PainPoints: "dull knives", Push: "edge retention", Pull: "premium steel", Anxiety: "cost", Habit: "reuses tools"},
		{ID: 7, Label: "Home cook", Description: "cooks weekends", PainPoints: "aesthetics", Push: "gift shopping", Pull: "looks nice", Anxiety: "complexity", Habit: "uses one knife"},
	}
	got := formatPersonasBlock(personas)
	if !strings.Contains(got, "[id=5] Chef") {
		t.Errorf("missing chef id tag, got %s", got)
	}
	if !strings.Contains(got, "[id=7] Home cook") {
		t.Errorf("missing home cook id tag, got %s", got)
	}
	if !strings.Contains(got, "dull knives") {
		t.Errorf("missing pain points, got %s", got)
	}
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `go test ./internal/pipeline/steps -run "TestHydrateAudienceSelection|TestFormatPersonasBlock" -v`
Expected: FAIL with "undefined: hydrateAudienceSelection" / "undefined: formatPersonasBlock".

- [ ] **Step 4: Create `internal/pipeline/steps/audience_picker.go`**

```go
package steps

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type AudiencePickerStep struct {
	AI       *ai.Client
	Tools    *tools.Registry
	Prompt   *prompt.Builder
	Audience store.AudienceStore
	Model    func() string
}

func (s *AudiencePickerStep) Type() string { return "audience_picker" }

func (s *AudiencePickerStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	personas, err := s.Audience.ListAudiencePersonas(input.ProjectID)
	if err != nil {
		return pipeline.StepResult{}, fmt.Errorf("audience_picker: list personas: %w", err)
	}
	if len(personas) == 0 {
		// Scheduler should have skipped this step; guard regardless.
		return pipeline.StepResult{}, fmt.Errorf("audience_picker: no personas for project %d (this step should have been skipped)", input.ProjectID)
	}

	personasBlock := formatPersonasBlock(personas)
	researchOutput := input.PriorOutputs["research"]
	systemPrompt := s.Prompt.ForAudiencePicker(input.Topic, input.Brief, input.Profile, researchOutput, personasBlock)
	toolList := s.Tools.ForStep("audience_picker")
	prefix := fmt.Sprintf("pipeline run=%d step=%d type=audience_picker", input.RunID, input.StepID)

	result, runErr := RunWithTools(ctx, s.AI, s.Model(), systemPrompt, "Submit the audience selection now via submit_audience_selection.", toolList, s.Tools, "submit_audience_selection", stream, 0.2, 3, prefix)
	if runErr != nil {
		return result, runErr
	}

	hydrated, herr := hydrateAudienceSelection(result.Output, personas)
	if herr != nil {
		return result, fmt.Errorf("audience_picker: %w", herr)
	}
	result.Output = hydrated
	return result, nil
}

// formatPersonasBlock renders the project's personas as a numbered list the
// audience picker prompt embeds. Each persona is tagged with `[id=N]` so the
// model returns a valid persona_id.
func formatPersonasBlock(personas []store.AudiencePersona) string {
	var b strings.Builder
	for i, p := range personas {
		fmt.Fprintf(&b, "%d. [id=%d] %s\n", i+1, p.ID, p.Label)
		if p.Description != "" {
			fmt.Fprintf(&b, "   description: %s\n", p.Description)
		}
		if p.PainPoints != "" {
			fmt.Fprintf(&b, "   pain_points: %s\n", p.PainPoints)
		}
		if p.Push != "" {
			fmt.Fprintf(&b, "   push: %s\n", p.Push)
		}
		if p.Pull != "" {
			fmt.Fprintf(&b, "   pull: %s\n", p.Pull)
		}
		if p.Anxiety != "" {
			fmt.Fprintf(&b, "   anxiety: %s\n", p.Anxiety)
		}
		if p.Habit != "" {
			fmt.Fprintf(&b, "   habit: %s\n", p.Habit)
		}
		if p.Role != "" {
			fmt.Fprintf(&b, "   role: %s\n", p.Role)
		}
		if p.Demographics != "" {
			fmt.Fprintf(&b, "   demographics: %s\n", p.Demographics)
		}
		if p.CompanyInfo != "" {
			fmt.Fprintf(&b, "   company: %s\n", p.CompanyInfo)
		}
		if p.ContentHabits != "" {
			fmt.Fprintf(&b, "   content_habits: %s\n", p.ContentHabits)
		}
		if p.BuyingTriggers != "" {
			fmt.Fprintf(&b, "   buying_triggers: %s\n", p.BuyingTriggers)
		}
		b.WriteString("\n")
	}
	return b.String()
}

// hydrateAudienceSelection parses the raw tool output, validates that a
// persona_id (when present) exists in the project's personas, and rewrites the
// JSON to include persona_label and persona_summary copied from the matching
// persona. Downstream steps read the hydrated JSON without any DB access.
func hydrateAudienceSelection(raw string, personas []store.AudiencePersona) (string, error) {
	sel, err := pipeline.ParseAudienceSelection(raw)
	if err != nil {
		return "", err
	}
	if sel.Mode == "persona" {
		var match *store.AudiencePersona
		for i := range personas {
			if personas[i].ID == *sel.PersonaID {
				match = &personas[i]
				break
			}
		}
		if match == nil {
			return "", fmt.Errorf("persona_id %d not found in project personas", *sel.PersonaID)
		}
		sel.PersonaLabel = match.Label
		sel.PersonaSummary = personaSummary(match)
	}
	out, merr := json.Marshal(sel)
	if merr != nil {
		return "", fmt.Errorf("marshal hydrated audience selection: %w", merr)
	}
	return string(out), nil
}

// personaSummary builds a compact multi-line summary from a persona row. It's
// the same content as the numbered list in formatPersonasBlock but flatter,
// for embedding in the writer/editor/brand_enricher prompts via the audience
// block.
func personaSummary(p *store.AudiencePersona) string {
	var b strings.Builder
	if p.Description != "" {
		fmt.Fprintf(&b, "%s ", p.Description)
	}
	if p.PainPoints != "" {
		fmt.Fprintf(&b, "Pain points: %s. ", p.PainPoints)
	}
	if p.Push != "" {
		fmt.Fprintf(&b, "Push: %s. ", p.Push)
	}
	if p.Pull != "" {
		fmt.Fprintf(&b, "Pull: %s. ", p.Pull)
	}
	if p.Anxiety != "" {
		fmt.Fprintf(&b, "Anxiety: %s. ", p.Anxiety)
	}
	if p.Habit != "" {
		fmt.Fprintf(&b, "Habit: %s.", p.Habit)
	}
	return strings.TrimSpace(b.String())
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `go test ./internal/pipeline/steps -run "TestHydrateAudienceSelection|TestFormatPersonasBlock" -v`
Expected: PASS.

- [ ] **Step 6: Build the whole repo**

Run: `go build ./...`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add internal/pipeline/steps/audience_picker.go internal/pipeline/steps/audience_picker_test.go
git commit -m "feat: audience_picker step runner with persona hydration"
```

---

## Task 9: `StyleReferenceStep` step runner

**Files:**
- Create: `internal/pipeline/steps/style_reference.go`
- Create: `internal/pipeline/steps/style_reference_test.go`

### Context

Shape mirrors `ResearchStep` — thin wrapper around `RunWithTools`, post-validates the output with `ParseStyleReference`. The runner needs the project's `blog_url`, which it reads from `ProjectSettingsStore.GetProjectSetting`. The step type registered in the registry is `"style_reference"`, and the submission tool is `submit_style_reference`.

For testing we cover the validation / parse path directly since `Run()` requires a live `ai.Client`.

- [ ] **Step 1: Write the failing test**

Create `internal/pipeline/steps/style_reference_test.go`:

```go
package steps

import (
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/pipeline"
)

func TestStyleReferenceValidate_HappyPath(t *testing.T) {
	body := strings.Repeat("lorem ipsum dolor sit amet, ", 30) // well over 400 chars
	raw := `{
		"examples": [
			{"url":"https://brand.example/a","title":"A","body":"` + body + `","why_chosen":"sharp"},
			{"url":"https://brand.example/b","title":"B","body":"` + body + `","why_chosen":"tight"}
		],
		"reasoning":"top two"
	}`
	ref, err := pipeline.ParseStyleReference(raw)
	if err != nil {
		t.Fatalf("parse: %v", err)
	}
	if len(ref.Examples) != 2 {
		t.Fatalf("want 2, got %d", len(ref.Examples))
	}
}

func TestStyleReferenceValidate_ShortBodyRejected(t *testing.T) {
	body := strings.Repeat("lorem ", 30)
	raw := `{
		"examples":[
			{"url":"a","title":"A","body":"` + body + `","why_chosen":"a"},
			{"url":"b","title":"B","body":"short","why_chosen":"b"}
		],
		"reasoning":"x"
	}`
	if _, err := pipeline.ParseStyleReference(raw); err == nil {
		t.Fatal("expected short-body rejection")
	}
}
```

(This test re-exercises `ParseStyleReference` through the `steps` package to ensure the import wiring is live. Full runner behavior is exercised in Task 13 orchestrator tests.)

- [ ] **Step 2: Run to verify it fails before the runner exists**

Actually, the test only touches `pipeline.ParseStyleReference` which already exists after Task 3. So this test will pass on its own. The value here is that the file sets up the test package import for the runner module, and the runner file is what makes the step exist. Run:

`go test ./internal/pipeline/steps -run TestStyleReferenceValidate -v`
Expected: PASS (even before the runner file exists, because the test only uses the `pipeline` package).

- [ ] **Step 3: Create `internal/pipeline/steps/style_reference.go`**

```go
package steps

import (
	"context"
	"fmt"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type StyleReferenceStep struct {
	AI              *ai.Client
	Tools           *tools.Registry
	Prompt          *prompt.Builder
	ProjectSettings store.ProjectSettingsStore
	Model           func() string
}

func (s *StyleReferenceStep) Type() string { return "style_reference" }

func (s *StyleReferenceStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	blogURL, _ := s.ProjectSettings.GetProjectSetting(input.ProjectID, "blog_url")
	if blogURL == "" {
		// Scheduler should have skipped this step; guard regardless.
		return pipeline.StepResult{}, fmt.Errorf("style_reference: blog_url not set for project %d (this step should have been skipped)", input.ProjectID)
	}

	systemPrompt := s.Prompt.ForStyleReference(blogURL, input.Topic)
	toolList := s.Tools.ForStep("style_reference")
	prefix := fmt.Sprintf("pipeline run=%d step=%d type=style_reference", input.RunID, input.StepID)

	// maxIter = StyleReferenceMaxFetches + 2 gives headroom for the submission
	// call plus one retry cycle. The prompt instructs the model to stay inside
	// StyleReferenceMaxFetches; this is the safety net.
	result, runErr := RunWithTools(ctx, s.AI, s.Model(), systemPrompt, "Begin finding style exemplars now.", toolList, s.Tools, "submit_style_reference", stream, 0.2, tools.StyleReferenceMaxFetches+2, prefix)
	if runErr != nil {
		return result, runErr
	}

	if _, verr := pipeline.ParseStyleReference(result.Output); verr != nil {
		return result, fmt.Errorf("style_reference: %w", verr)
	}
	return result, nil
}
```

- [ ] **Step 4: Run tests**

Run: `go test ./internal/pipeline/steps/...`
Expected: PASS.

- [ ] **Step 5: Build the whole repo**

Run: `go build ./...`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add internal/pipeline/steps/style_reference.go internal/pipeline/steps/style_reference_test.go
git commit -m "feat: style_reference step runner"
```

---

## Task 10: `brand_enricher` consumes audience (prompt + runner)

**Files:**
- Modify: `internal/prompt/builder.go`
- Modify: `internal/prompt/builder_test.go`
- Modify: `internal/pipeline/steps/brand_enricher.go`

### Context

`ForBrandEnricher` currently has signature `ForBrandEnricher(profile, researchOutput, urlList string)`. We add a trailing `audienceBlock string` argument. When empty, the function emits the current prompt unchanged. When non-empty, it inserts the block after the profile and before the research output, with the instruction line from the spec.

The `brand_enricher.go` runner then parses `PriorOutputs["audience_picker"]` (when present), builds the block via `pipeline.FormatAudienceBlock`, and passes it in. Missing prior output → empty block → unchanged prompt.

- [ ] **Step 1: Read the current `ForBrandEnricher` method to ensure your edit preserves existing content**

Run: read lines 160-193 of `internal/prompt/builder.go`.

- [ ] **Step 2: Write the failing test**

Append to `internal/prompt/builder_test.go`:

```go
func TestForBrandEnricher_NoAudienceBlock(t *testing.T) {
	b := &Builder{contentPrompts: map[string]string{}}
	out := b.ForBrandEnricher("profile", "research output", "urls", "")
	if strings.Contains(out, "## Audience target") {
		t.Error("empty audienceBlock should not produce audience section")
	}
}

func TestForBrandEnricher_WithAudienceBlock(t *testing.T) {
	b := &Builder{contentPrompts: map[string]string{}}
	audience := "\n## Audience target\nPersona: Professional chef\nWriter guidance: do not recommend the cheapest knife\n"
	out := b.ForBrandEnricher("profile", "research output", "urls", audience)
	if !strings.Contains(out, "## Audience target") {
		t.Error("expected audience section in output")
	}
	if !strings.Contains(out, "Professional chef") {
		t.Error("expected persona label in output")
	}
	if !strings.Contains(out, "do not recommend the cheapest knife") {
		t.Error("expected writer guidance in output")
	}
	if !strings.Contains(out, "prefer products, plans, and SKUs appropriate for this audience") {
		t.Error("expected audience instruction line in output")
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `go test ./internal/prompt -run TestForBrandEnricher -v`
Expected: FAIL — the function currently takes 3 args, not 4, so the call `ForBrandEnricher("profile", "research output", "urls", "")` is a compile error.

- [ ] **Step 4: Edit `ForBrandEnricher` in `internal/prompt/builder.go`**

Change the signature and body. Replace the existing function with:

```go
// ForBrandEnricher builds the system prompt for the brand enricher step.
// audienceBlock is optional — pass "" when the audience_picker step was skipped.
func (b *Builder) ForBrandEnricher(profile, researchOutput, urlList, audienceBlock string) string {
	audienceSection := ""
	if audienceBlock != "" {
		audienceSection = audienceBlock + "\nWhen enriching with brand claims, prefer products, plans, and SKUs appropriate for this audience. Do not add claims about offerings that clearly mismatch the target.\n"
	}
	return fmt.Sprintf(`%s

You are a brand enricher. You receive a structured claims array from the researcher and brand URLs to fetch. Your job is to **append brand-specific claims** that connect the topic to the brand's actual offerings.

## Client profile
%s
%s
## Research output (claims, sources, brief — do NOT modify, only append to)
%s

## Company URLs to fetch
%s

## Append-only contract
- Do NOT modify, delete, or renumber existing claims. They are immutable.
- Do NOT modify or delete existing sources.
- Append new claims with IDs continuing the sequence (if the last research claim was `+"`c12`"+`, your first claim is `+"`c13`"+`).
- Append new sources with IDs continuing the source sequence (if the last research source was `+"`s8`"+`, your first brand source is `+"`s9`"+`).
- Brand claims look like: "AcmeCorp's Premium plan is priced at $49/month." or "AcmeCorp offers a 30-day free trial on all plans."
- Each new claim must cite at least one source_id (typically a brand URL you just fetched).
- The `+"`enriched_brief`"+` field updates the narrative direction to weave the brand into the story. Still short, still narrative — facts live in claims.

## Workflow & limits
1. Read the research claims and brief carefully — understand the topic.
2. Fetch each company URL above using `+"`fetch_url`"+`. You have no web search here.
3. Extract only details directly relevant to the topic. A page may have 20 products but only 2 matter. Ignore the rest.
4. Append your brand claims to the merged claims array, keeping every original research claim intact with its original id and text.
5. Call `+"`submit_brand_enrichment`"+` immediately after the last fetch.

## CRITICAL: You MUST use tool calls
Every response MUST include a tool call (`+"`fetch_url`"+` or `+"`submit_brand_enrichment`"+`). A response with only text is treated as a failure. The moment all URLs are fetched, call `+"`submit_brand_enrichment`"+` immediately.`, b.DateHeader(), profile, audienceSection, researchOutput, urlList)
}
```

Key change: added `audienceBlock` parameter, added `audienceSection` computation, inserted `%s` placeholder between `%s` (profile) and the `## Research output` header.

- [ ] **Step 5: Update the brand_enricher runner to pass the new argument**

Edit `internal/pipeline/steps/brand_enricher.go`. Find the `s.Prompt.ForBrandEnricher(...)` call and change it to pass the audience block.

First read the file to locate the call: `grep -n ForBrandEnricher internal/pipeline/steps/brand_enricher.go`.

Then near the top of the `Run` method (after inputs are set up but before the ForBrandEnricher call), parse the audience selection:

```go
	var audienceBlock string
	if raw, ok := input.PriorOutputs["audience_picker"]; ok && raw != "" {
		sel, perr := pipeline.ParseAudienceSelection(raw)
		if perr != nil {
			return pipeline.StepResult{}, fmt.Errorf("brand_enricher: parse audience selection: %w", perr)
		}
		audienceBlock = pipeline.FormatAudienceBlock(sel)
	}
```

Then update the `ForBrandEnricher` call to pass `audienceBlock` as the fourth argument. Leave all other arguments unchanged.

Ensure `pipeline` is already imported (it should be, since the file uses it). If not, add the import.

- [ ] **Step 6: Run the tests**

Run: `go test ./internal/prompt -run TestForBrandEnricher -v && go test ./internal/pipeline/...`
Expected: PASS for both.

- [ ] **Step 7: Build the whole repo**

Run: `go build ./...`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add internal/prompt/builder.go internal/prompt/builder_test.go internal/pipeline/steps/brand_enricher.go
git commit -m "feat: brand_enricher consumes audience selection"
```

---

## Task 11: `editor` consumes audience (prompt + runner)

**Files:**
- Modify: `internal/prompt/builder.go`
- Modify: `internal/prompt/builder_test.go`
- Modify: `internal/pipeline/steps/editor.go`

### Context

`ForEditor` currently has signature `ForEditor(profile, brief, claimsBlock, frameworkBlock string)`. Add trailing `audienceBlock string`. When non-empty, insert after the narrative brief with the instruction line from the spec ("The angle and claim selection must serve this audience.").

The editor runner then parses `PriorOutputs["audience_picker"]` and passes the block.

- [ ] **Step 1: Read the current `ForEditor` method** (around line 230-270 of `internal/prompt/builder.go`).

- [ ] **Step 2: Write the failing test**

Append to `internal/prompt/builder_test.go`:

```go
func TestForEditor_NoAudienceBlock(t *testing.T) {
	b := &Builder{contentPrompts: map[string]string{}}
	out := b.ForEditor("profile", "brief", "claims", "framework", "")
	if strings.Contains(out, "## Audience target") {
		t.Error("empty audienceBlock should not produce audience section")
	}
}

func TestForEditor_WithAudienceBlock(t *testing.T) {
	b := &Builder{contentPrompts: map[string]string{}}
	audience := "\n## Audience target\nPersona: Professional chef\nWriter guidance: do not recommend the cheapest knife\n"
	out := b.ForEditor("profile", "brief", "claims", "framework", audience)
	if !strings.Contains(out, "Professional chef") {
		t.Error("expected persona label")
	}
	if !strings.Contains(out, "The angle and claim selection must serve this audience") {
		t.Error("expected audience instruction")
	}
}
```

- [ ] **Step 3: Run the test to verify it fails (compile error)**

Run: `go test ./internal/prompt -run TestForEditor -v`
Expected: FAIL — signature mismatch.

- [ ] **Step 4: Update `ForEditor` in `internal/prompt/builder.go`**

Change the function to accept `audienceBlock string` as the last argument and insert it after the narrative brief:

```go
// ForEditor builds the system prompt for the editor step.
// audienceBlock is optional — pass "" when the audience_picker step was skipped.
func (b *Builder) ForEditor(profile, brief, claimsBlock, frameworkBlock, audienceBlock string) string {
	var sb strings.Builder
	sb.WriteString(b.DateHeader())
	sb.WriteString(`

You are an editorial director. You receive a structured claims array, a narrative brief, and brand context. Your job is to craft a structured outline that a copywriter will execute.

Your job is narrative reasoning:
- Analyse the claims and brief and determine the strongest angle/hook
- Decide which claims to include, which to cut, and how to order them for maximum impact
- Build a logical throughline so the conclusion feels inevitable, not forced
- Specify which claim ids back which section
- Produce a tight outline the writer can execute without re-reading the raw research

Do NOT write the article. Produce only the structural outline.

## Output contract: claim ids per section
Every section's ` + "`claim_ids[]`" + ` lists the specific claims it leans on. The writer will read ONLY those claims for that section, plus your editorial guidance. Pick claims deliberately — a section with weak claim coverage is a section that will be weak prose. Every id you list must exist in the claims block below.

## Workflow & limits
- You have **exactly one tool**: ` + "`submit_editorial_outline`" + `. There is no search and no fetch — everything you need is in the brief and claims below.
- Plan the outline in your head, then call ` + "`submit_editorial_outline`" + ` on your **first response**. Do not respond with prose first.

## CRITICAL: You MUST use tool calls
Every response MUST include a call to ` + "`submit_editorial_outline`" + `. A response with only text is treated as a failure. Put the angle, sections (with claim_ids), and conclusion strategy directly into the tool arguments on your very first turn.

## Client profile
`)
	sb.WriteString(profile)
	sb.WriteString("\n\n## Narrative brief\n")
	sb.WriteString(brief)
	if audienceBlock != "" {
		sb.WriteString(audienceBlock)
		sb.WriteString("\nThe angle and claim selection must serve this audience.\n")
	}
	sb.WriteString(claimsBlock)

	if frameworkBlock != "" {
		sb.WriteString("\n")
		sb.WriteString(frameworkBlock)
		sb.WriteString("\n")
	}

	return sb.String()
}
```

- [ ] **Step 5: Update the editor runner**

Edit `internal/pipeline/steps/editor.go`. Add the audience parsing block near the top of `Run`, before the `ForEditor` call:

```go
	var audienceBlock string
	if raw, ok := input.PriorOutputs["audience_picker"]; ok && raw != "" {
		sel, perr := pipeline.ParseAudienceSelection(raw)
		if perr != nil {
			return pipeline.StepResult{}, fmt.Errorf("editor: parse audience selection: %w", perr)
		}
		audienceBlock = pipeline.FormatAudienceBlock(sel)
	}
```

And update the `ForEditor` call to pass `audienceBlock` as the fifth argument.

- [ ] **Step 6: Run the tests**

Run: `go test ./internal/prompt -run TestForEditor -v && go test ./internal/pipeline/...`
Expected: PASS.

- [ ] **Step 7: Build the repo**

Run: `go build ./...`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add internal/prompt/builder.go internal/prompt/builder_test.go internal/pipeline/steps/editor.go
git commit -m "feat: editor consumes audience selection"
```

---

## Task 12: `writer` consumes audience + style reference (prompt + runner)

**Files:**
- Modify: `internal/prompt/builder.go`
- Modify: `internal/prompt/builder_test.go`
- Modify: `internal/pipeline/steps/writer.go`

### Context

`ForWriter` currently has signature `ForWriter(promptFile, profile, editorOutput, claimsBlock, rejectionReason string)`. Add two trailing args: `audienceBlock string, styleReferenceBlock string`. Both default-safe on empty.

Insertion order in the resulting prompt:
1. Profile
2. Editorial outline
3. **Audience block** (new, after editor output, before claims)
4. Claims block
5. **Style reference block** (new, after claims, before the factual-grounding rule)
6. Factual grounding + anti-AI rules (existing)

The writer runner parses both new prior outputs and passes both blocks.

- [ ] **Step 1: Read the current `ForWriter` method** (around line 273-302 of `internal/prompt/builder.go`).

- [ ] **Step 2: Write the failing tests**

Append to `internal/prompt/builder_test.go`:

```go
func TestForWriter_NoNewBlocks(t *testing.T) {
	b := &Builder{contentPrompts: map[string]string{"blog_post": "Write a blog post."}}
	out := b.ForWriter("blog_post", "profile", "outline", "claims", "", "", "")
	if strings.Contains(out, "## Audience target") {
		t.Error("empty audienceBlock should not produce audience section")
	}
	if strings.Contains(out, "## Style reference") {
		t.Error("empty styleReferenceBlock should not produce style section")
	}
}

func TestForWriter_WithAudienceBlock(t *testing.T) {
	b := &Builder{contentPrompts: map[string]string{"blog_post": "Write a blog post."}}
	audience := "\n## Audience target\nPersona: Professional chef\nWriter guidance: do not recommend the cheapest knife\n"
	out := b.ForWriter("blog_post", "profile", "outline", "claims", "", audience, "")
	if !strings.Contains(out, "Professional chef") {
		t.Error("expected persona label")
	}
	if !strings.Contains(out, "Honor the writer guidance literally") {
		t.Error("expected writer audience instruction")
	}
}

func TestForWriter_WithStyleReferenceBlock(t *testing.T) {
	b := &Builder{contentPrompts: map[string]string{"blog_post": "Write a blog post."}}
	style := "\n## Style reference — match this voice\nDo NOT copy sentences, facts, or structure from these examples.\n\n### Example 1: Foo\nbody body body\n"
	out := b.ForWriter("blog_post", "profile", "outline", "claims", "", "", style)
	if !strings.Contains(out, "## Style reference") {
		t.Error("expected style reference section")
	}
	if !strings.Contains(out, "Do NOT copy sentences, facts, or structure") {
		t.Error("expected do-not-copy rule")
	}
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `go test ./internal/prompt -run TestForWriter -v`
Expected: FAIL — signature mismatch.

- [ ] **Step 4: Update `ForWriter` in `internal/prompt/builder.go`**

Replace the existing function with:

```go
// ForWriter builds the system prompt for the writer step.
// audienceBlock and styleReferenceBlock are optional — pass "" when the
// corresponding step was skipped.
func (b *Builder) ForWriter(promptFile, profile, editorOutput, claimsBlock, rejectionReason, audienceBlock, styleReferenceBlock string) string {
	promptText := b.ContentPrompt(promptFile)
	if promptText == "" {
		promptText = "You are writing a blog post."
	}

	var sb strings.Builder
	sb.WriteString(b.DateHeader())
	sb.WriteString("\n\n")
	sb.WriteString(promptText)
	sb.WriteString("\n\n## Client profile\n")
	sb.WriteString(profile)
	sb.WriteString("\n")
	sb.WriteString(fmt.Sprintf("\n## Editorial outline\nFollow this outline closely. It defines the angle, structure, and which claims belong to each section.\n\n%s\n", editorOutput))
	if audienceBlock != "" {
		sb.WriteString(audienceBlock)
		sb.WriteString("\nThis post addresses the audience above. Honor the writer guidance literally.\n")
	}
	sb.WriteString(claimsBlock)
	if styleReferenceBlock != "" {
		sb.WriteString(styleReferenceBlock)
	}
	sb.WriteString(`

## Factual grounding (NON-NEGOTIABLE)
Every statistic, percentage, dollar amount, date, named entity, and direct quote in your article MUST come from a claim in the claims block above. If a claim isn't there, you don't write it. Do not invent, estimate, or "round up." If you feel a section needs a fact you don't have, leave it out and lean on the angle instead. We are publishing journalism, not opinion.

The editorial outline tells you which claim ids belong to each section. Lean on those claims when you write that section.
`)

	if rejectionReason != "" {
		sb.WriteString(fmt.Sprintf("\n## Previous rejection feedback\n%s. Address this in the new version.\n", rejectionReason))
	}

	sb.WriteString(antiAIRules)
	return sb.String()
}
```

- [ ] **Step 5: Update the writer runner**

Edit `internal/pipeline/steps/writer.go`. Find the `s.Prompt.ForWriter(...)` call (around line 53) and:

a) Above the call, parse both prior outputs:

```go
	var audienceBlock string
	if raw, ok := input.PriorOutputs["audience_picker"]; ok && raw != "" {
		sel, perr := pipeline.ParseAudienceSelection(raw)
		if perr != nil {
			return pipeline.StepResult{}, fmt.Errorf("writer: parse audience selection: %w", perr)
		}
		audienceBlock = pipeline.FormatAudienceBlock(sel)
	}

	var styleReferenceBlock string
	if raw, ok := input.PriorOutputs["style_reference"]; ok && raw != "" {
		ref, perr := pipeline.ParseStyleReference(raw)
		if perr != nil {
			return pipeline.StepResult{}, fmt.Errorf("writer: parse style reference: %w", perr)
		}
		styleReferenceBlock = pipeline.FormatStyleReferenceBlock(ref)
	}
```

b) Update the `ForWriter` call to pass the two new arguments:

```go
	systemPrompt := s.Prompt.ForWriter(promptFile, input.Profile, editorOutput, claimsBlock, rejectionReason, audienceBlock, styleReferenceBlock)
```

- [ ] **Step 6: Run the tests**

Run: `go test ./internal/prompt -run TestForWriter -v && go test ./internal/pipeline/...`
Expected: PASS.

- [ ] **Step 7: Build the repo**

Run: `go build ./...`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add internal/prompt/builder.go internal/prompt/builder_test.go internal/pipeline/steps/writer.go
git commit -m "feat: writer consumes audience and style reference blocks"
```

---

## Task 13: Orchestrator dependencies + dynamic checks

**Files:**
- Modify: `internal/pipeline/orchestrator.go`
- Modify: `internal/pipeline/orchestrator_test.go`

### Context

Two changes to `orchestrator.go`:

1. `StepDependencies()` gains entries for `audience_picker` and `style_reference`.
2. `RunStep` gains two dynamic dep checks, one for `brand_enricher → audience_picker` and one for `write → style_reference`. Pattern matches the existing `editor → claim_verifier` check on lines 60-67.

- [ ] **Step 1: Read the current orchestrator to confirm the existing dynamic-dep pattern**

Run: read lines 10-90 of `internal/pipeline/orchestrator.go`.

- [ ] **Step 2: Write the failing test**

Append to `internal/pipeline/orchestrator_test.go`:

```go
func TestStepDependencies_IncludesNewSteps(t *testing.T) {
	deps := StepDependencies()
	audDeps, ok := deps["audience_picker"]
	if !ok {
		t.Fatal("audience_picker missing from StepDependencies")
	}
	if len(audDeps) != 1 || audDeps[0] != "research" {
		t.Errorf("audience_picker deps: want [research], got %v", audDeps)
	}
	styleDeps, ok := deps["style_reference"]
	if !ok {
		t.Fatal("style_reference missing from StepDependencies")
	}
	if len(styleDeps) != 1 || styleDeps[0] != "editor" {
		t.Errorf("style_reference deps: want [editor], got %v", styleDeps)
	}
}
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `go test ./internal/pipeline -run TestStepDependencies -v`
Expected: FAIL — missing keys.

- [ ] **Step 4: Update `StepDependencies()` in `internal/pipeline/orchestrator.go`**

Change the function body to:

```go
func StepDependencies() map[string][]string {
	return map[string][]string{
		"research":        {},
		"audience_picker": {"research"},
		"brand_enricher":  {"research"},
		"claim_verifier":  {"brand_enricher"},
		"editor":          {"brand_enricher"},
		"style_reference": {"editor"},
		"write":           {"editor"},
	}
}
```

- [ ] **Step 5: Update `RunStep` to add the two dynamic dep checks**

In `RunStep`, the existing block is:

```go
	// Editor must wait for claim_verifier whenever the run includes one.
	if step.StepType == "editor" {
		for _, s := range steps {
			if s.StepType == "claim_verifier" {
				required = append(required, "claim_verifier")
				break
			}
		}
	}
```

Add below it:

```go
	// brand_enricher must wait for audience_picker whenever the run includes one.
	if step.StepType == "brand_enricher" {
		for _, s := range steps {
			if s.StepType == "audience_picker" {
				required = append(required, "audience_picker")
				break
			}
		}
	}

	// write must wait for style_reference whenever the run includes one.
	if step.StepType == "write" {
		for _, s := range steps {
			if s.StepType == "style_reference" {
				required = append(required, "style_reference")
				break
			}
		}
	}
```

- [ ] **Step 6: Run the tests**

Run: `go test ./internal/pipeline -v`
Expected: PASS. If existing orchestrator tests fail, read them and determine whether they need to be updated to account for the new deps map keys (they shouldn't — the test above is additive, and existing tests don't rely on the map being exhaustive).

- [ ] **Step 7: Commit**

```bash
git add internal/pipeline/orchestrator.go internal/pipeline/orchestrator_test.go
git commit -m "feat: orchestrator deps for audience_picker and style_reference"
```

---

## Task 14: `CreateDefaultPipelineSteps` conditional insert

**Files:**
- Modify: `internal/store/steps.go`
- Modify: `internal/store/steps_test.go`

### Context

`CreateDefaultPipelineSteps(runID int64) error` currently builds a static list based on the global `claim_verifier_enabled` setting. It needs to:

1. Look up the project id via `GetPipelineRun(runID)` so it can query project-scoped state.
2. Insert `audience_picker` (between `research` and `brand_enricher`) only when `ListAudiencePersonas(projectID)` is non-empty.
3. Insert `style_reference` (between `editor` and `write`) only when `GetProjectSetting(projectID, "blog_url") != ""`.

This keeps the call sites (`web/handlers/pipeline.go:108` and `web/handlers/topic.go:406`) unchanged.

- [ ] **Step 1: Write the failing tests**

Append to `internal/store/steps_test.go`:

```go
func TestCreateDefaultPipelineSteps_NoPersonas_SkipsAudience(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	if err := q.CreateDefaultPipelineSteps(run.ID); err != nil {
		t.Fatalf("CreateDefaultPipelineSteps: %v", err)
	}

	steps, _ := q.ListPipelineSteps(run.ID)
	for _, s := range steps {
		if s.StepType == "audience_picker" {
			t.Errorf("audience_picker should be skipped when no personas exist")
		}
	}
}

func TestCreateDefaultPipelineSteps_WithPersonas_IncludesAudience(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	if _, err := q.CreateAudiencePersona(p.ID, AudiencePersona{
		Label: "Pro chef", Description: "d", PainPoints: "pp", Push: "pu", Pull: "pl", Anxiety: "a", Habit: "h",
	}); err != nil {
		t.Fatalf("seed persona: %v", err)
	}

	if err := q.CreateDefaultPipelineSteps(run.ID); err != nil {
		t.Fatalf("CreateDefaultPipelineSteps: %v", err)
	}

	steps, _ := q.ListPipelineSteps(run.ID)
	var foundAudience bool
	var audIdx, brandIdx int
	for i, s := range steps {
		if s.StepType == "audience_picker" {
			foundAudience = true
			audIdx = i
		}
		if s.StepType == "brand_enricher" {
			brandIdx = i
		}
	}
	if !foundAudience {
		t.Fatalf("audience_picker missing when personas exist, got %+v", steps)
	}
	if audIdx >= brandIdx {
		t.Errorf("audience_picker must come before brand_enricher, got audience at %d, brand at %d", audIdx, brandIdx)
	}
}

func TestCreateDefaultPipelineSteps_NoBlogURL_SkipsStyleReference(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	if err := q.CreateDefaultPipelineSteps(run.ID); err != nil {
		t.Fatalf("CreateDefaultPipelineSteps: %v", err)
	}

	steps, _ := q.ListPipelineSteps(run.ID)
	for _, s := range steps {
		if s.StepType == "style_reference" {
			t.Errorf("style_reference should be skipped when blog_url is unset")
		}
	}
}

func TestCreateDefaultPipelineSteps_WithBlogURL_IncludesStyleReference(t *testing.T) {
	q := testDB(t)
	p, _ := q.CreateProject("Test", "test")
	run, _ := q.CreatePipelineRun(p.ID, "test topic")

	if err := q.SetProjectSetting(p.ID, "blog_url", "https://brand.example/blog"); err != nil {
		t.Fatalf("set blog_url: %v", err)
	}

	if err := q.CreateDefaultPipelineSteps(run.ID); err != nil {
		t.Fatalf("CreateDefaultPipelineSteps: %v", err)
	}

	steps, _ := q.ListPipelineSteps(run.ID)
	var foundStyle bool
	var styleIdx, writeIdx int
	for i, s := range steps {
		if s.StepType == "style_reference" {
			foundStyle = true
			styleIdx = i
		}
		if s.StepType == "write" {
			writeIdx = i
		}
	}
	if !foundStyle {
		t.Fatalf("style_reference missing when blog_url is set, got %+v", steps)
	}
	if styleIdx >= writeIdx {
		t.Errorf("style_reference must come before write, got style at %d, write at %d", styleIdx, writeIdx)
	}
}
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `go test ./internal/store -run TestCreateDefaultPipelineSteps -v`
Expected: old tests still pass, new tests fail.

- [ ] **Step 3: Update `CreateDefaultPipelineSteps` in `internal/store/steps.go`**

Replace the existing function with:

```go
// CreateDefaultPipelineSteps creates the standard pipeline steps for a run.
// The step list is dynamic per-run:
//   - claim_verifier only if the global setting claim_verifier_enabled == "true"
//   - audience_picker only if the project has at least one persona
//   - style_reference only if the project has a non-empty blog_url setting
func (q *Queries) CreateDefaultPipelineSteps(runID int64) error {
	run, err := q.GetPipelineRun(runID)
	if err != nil {
		return fmt.Errorf("lookup pipeline run %d: %w", runID, err)
	}
	projectID := run.ProjectID

	stepTypes := []string{"research"}

	personas, _ := q.ListAudiencePersonas(projectID)
	if len(personas) > 0 {
		stepTypes = append(stepTypes, "audience_picker")
	}

	stepTypes = append(stepTypes, "brand_enricher")

	if v, _ := q.GetSetting("claim_verifier_enabled"); v == "true" {
		stepTypes = append(stepTypes, "claim_verifier")
	}

	stepTypes = append(stepTypes, "editor")

	if url, _ := q.GetProjectSetting(projectID, "blog_url"); url != "" {
		stepTypes = append(stepTypes, "style_reference")
	}

	stepTypes = append(stepTypes, "write")

	for i, stepType := range stepTypes {
		if _, err := q.CreatePipelineStep(runID, stepType, i); err != nil {
			return err
		}
	}
	return nil
}
```

Add `"fmt"` to the import list if it isn't already imported. If `fmt` would be unused (check — the original file might not import it), wrap the error without fmt by using `errors.New` or leave the `return err` as-is and drop the `fmt.Errorf` wrap.

- [ ] **Step 4: Run the tests**

Run: `go test ./internal/store -run TestCreateDefaultPipelineSteps -v`
Expected: PASS (including the older `_ClaimVerifierDisabled` and `_ClaimVerifierEnabled` tests, which don't set personas or blog_url so they should be unaffected).

If the older tests now fail because the run count changed, inspect: the older tests expected `len(steps) == 4` (disabled) and `len(steps) == 5` (enabled). With no personas and no blog_url, the counts should still match. If they don't, investigate before changing the test — a count shift signals an actual bug.

- [ ] **Step 5: Run the full store package**

Run: `go test ./internal/store/...`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add internal/store/steps.go internal/store/steps_test.go
git commit -m "feat: conditional audience_picker and style_reference scheduling"
```

---

## Task 15: Register new step runners in `cmd/server/main.go`

**Files:**
- Modify: `cmd/server/main.go`

### Context

`NewOrchestrator` takes a variadic list of `StepRunner`s. Add the two new runners with their required dependencies. Compare with the existing registrations on lines 66-70 and match the pattern.

- [ ] **Step 1: Read lines 55-75 of `cmd/server/main.go` to confirm the existing layout**

- [ ] **Step 2: Edit the `NewOrchestrator(...)` call**

Add two entries to the variadic list. Insert `AudiencePickerStep` after `ResearchStep` and `StyleReferenceStep` after `EditorStep`:

```go
	orchestrator := pipeline.NewOrchestrator(
		queries,
		&steps.ResearchStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Model: contentModel},
		&steps.AudiencePickerStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Audience: queries, Model: contentModel},
		&steps.BrandEnricherStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Profile: queries, Model: contentModel},
		&steps.ClaimVerifierStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Model: contentModel},
		&steps.EditorStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Pipeline: queries, VoiceTone: queries, Model: contentModel},
		&steps.StyleReferenceStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, ProjectSettings: queries, Model: contentModel},
		&steps.WriterStep{AI: aiClient, Prompt: promptBuilder, Content: queries, Pipeline: queries, Model: copywritingModel},
	)
```

Note: `Audience: queries` works because `*Queries` implements `AudienceStore` (see the compile-time assertion in `internal/store/interfaces.go` line 140). Same for `ProjectSettings: queries` (line 137).

- [ ] **Step 3: Build**

Run: `go build ./...`
Expected: no errors.

- [ ] **Step 4: Restart the dev server and confirm it boots**

Run: `make restart`
Expected: no panics, no "unknown step type" errors in the startup logs.

If you see a "step runner not found" error at runtime later, it means the step key in `Type()` doesn't match the key used in the scheduler/orchestrator. Double-check `AudiencePickerStep.Type()` returns `"audience_picker"` and `StyleReferenceStep.Type()` returns `"style_reference"`.

- [ ] **Step 5: Commit**

```bash
git add cmd/server/main.go
git commit -m "feat: wire audience_picker and style_reference step runners"
```

---

## Task 16: Frontend step labels

**Files:**
- Modify: `web/static/js/step-cards.js`

### Context

`STEP_LABELS` (line 11 of `web/static/js/step-cards.js`) is the display-name map used by the pipeline UI. Without an entry, step cards render the raw step type string (e.g. `audience_picker` instead of `Audience Picker`).

- [ ] **Step 1: Edit `web/static/js/step-cards.js`**

Change:

```js
    var STEP_LABELS = {
        'research': 'Researcher',
        'brand_enricher': 'Brand Enricher',
        'claim_verifier': 'Claim Verifier',
        'editor': 'Editor',
        'write': 'Writer',
        'topic_explore': 'Explorer',
        'topic_review': 'Reviewer'
    };
```

to:

```js
    var STEP_LABELS = {
        'research': 'Researcher',
        'audience_picker': 'Audience Picker',
        'brand_enricher': 'Brand Enricher',
        'claim_verifier': 'Claim Verifier',
        'editor': 'Editor',
        'style_reference': 'Style Reference',
        'write': 'Writer',
        'topic_explore': 'Explorer',
        'topic_review': 'Reviewer'
    };
```

- [ ] **Step 2: Reload the pipeline page in a browser and confirm the labels render correctly for a run that has one of these steps**

You can trigger this by seeding a persona or setting a blog_url on a test project and starting a new pipeline run. If that's too much setup, at least confirm the existing step types still render correctly.

- [ ] **Step 3: Commit**

```bash
git add web/static/js/step-cards.js
git commit -m "feat: step card labels for audience_picker and style_reference"
```

---

## Task 17: Frontend `renderStepOutput` branches

**Files:**
- Modify: `web/static/js/renderers/step-output.js`

### Context

`renderStepOutput(el, typeName, data)` in `web/static/js/renderers/step-output.js` (line 88) switches on the step's display label (not its type string). It has branches for `Researcher`, `Brand Enricher`, `Tone Analyzer`, `Fact-Checker`, `Editor`, with a catch-all `else` that dumps raw JSON. We add two new branches.

**Audience Picker** renders:
- A mode badge (`persona` / `educational` / `commentary`)
- Persona label (if present)
- Reasoning paragraph
- Writer guidance in a highlighted box

**Style Reference** renders:
- A list of 2-3 collapsible accordions, each showing title, URL, why_chosen, and an expandable full body

Use the existing helpers `renderSection`, `makeSubcard`, and `renderMarkdown` from the same file. Read the Editor branch (lines 149-190) for the canonical pattern.

- [ ] **Step 1: Read the existing Editor branch (lines 149-190) of `web/static/js/renderers/step-output.js`**

- [ ] **Step 2: Add the Audience Picker branch**

Inside `renderStepOutput`, add before the final `else` (line 191):

```js
    } else if (typeName === 'Audience Picker') {
        var modeRow = document.createElement('div');
        modeRow.className = 'mb-2';
        var modeBadge = document.createElement('span');
        modeBadge.className = 'badge badge-sm badge-primary mr-2';
        modeBadge.textContent = data.mode || 'unknown';
        modeRow.appendChild(modeBadge);
        if (data.persona_label) {
            var label = document.createElement('span');
            label.className = 'font-semibold text-sm';
            label.textContent = data.persona_label;
            modeRow.appendChild(label);
        }
        el.appendChild(modeRow);

        if (data.reasoning) {
            var reasoning = document.createElement('div');
            reasoning.className = 'text-sm opacity-80 mb-2';
            reasoning.textContent = data.reasoning;
            el.appendChild(makeSubcard('Reasoning', reasoning));
        }
        if (data.guidance_for_writer) {
            var guidance = document.createElement('div');
            guidance.className = 'text-sm p-2 bg-zinc-800 border-l-4 border-primary rounded';
            guidance.textContent = data.guidance_for_writer;
            el.appendChild(makeSubcard('Writer guidance', guidance));
        }
```

- [ ] **Step 3: Add the Style Reference branch**

Immediately below the Audience Picker branch, add:

```js
    } else if (typeName === 'Style Reference') {
        if (data.reasoning) {
            var sRea = document.createElement('div');
            sRea.className = 'text-sm opacity-80 mb-2';
            sRea.textContent = data.reasoning;
            el.appendChild(makeSubcard('Reasoning', sRea));
        }
        if (data.examples && data.examples.length > 0) {
            data.examples.forEach(function(ex, i) {
                var wrap = document.createElement('div');
                wrap.className = 'mb-2';
                var details = document.createElement('details');
                details.className = 'border border-zinc-800 rounded p-2 bg-zinc-900';
                var summary = document.createElement('summary');
                summary.className = 'cursor-pointer text-sm font-semibold';
                summary.textContent = 'Example ' + (i + 1) + ': ' + (ex.title || ex.url);
                details.appendChild(summary);

                if (ex.url) {
                    var a = document.createElement('a');
                    a.href = ex.url;
                    a.target = '_blank';
                    a.className = 'link link-primary text-xs block mt-1';
                    a.textContent = ex.url;
                    details.appendChild(a);
                }
                if (ex.why_chosen) {
                    var why = document.createElement('div');
                    why.className = 'italic text-xs opacity-70 mt-1';
                    why.textContent = 'Why: ' + ex.why_chosen;
                    details.appendChild(why);
                }
                if (ex.body) {
                    var body = document.createElement('div');
                    body.className = 'text-xs mt-2 whitespace-pre-wrap font-mono';
                    body.textContent = ex.body;
                    details.appendChild(body);
                }

                wrap.appendChild(details);
                el.appendChild(wrap);
            });
        }
```

- [ ] **Step 4: Reload the pipeline page and visually confirm a completed run of each new step renders correctly**

If you don't yet have real output to view, at minimum confirm you did not break the existing step output rendering (navigate to an older completed run with research/editor/writer steps).

- [ ] **Step 5: Commit**

```bash
git add web/static/js/renderers/step-output.js
git commit -m "feat: render audience_picker and style_reference step outputs"
```

---

## Task 18: Frontend skipped-step hint

**Files:**
- Modify: `web/templates/pipeline.templ`

### Context

When the scheduler skips `audience_picker` or `style_reference`, the step is never inserted into `pipeline_steps`, so the user sees nothing at all. The spec requires a muted row in the step list that tells them what to set to enable each step.

This is the part of the plan that depends on reading the current `pipeline.templ` to understand where step cards render and how to insert a conditional row. Open the file and locate the loop that iterates over steps.

- [ ] **Step 1: Read `web/templates/pipeline.templ` end-to-end to locate the step-card rendering loop**

Run: open the file in an editor. Find the `for _, step := range steps` (or similar) block. Note the parent container class.

- [ ] **Step 2: Determine a minimal-change approach**

Two options:
- **A.** Render the hints at the top of the pipeline page as a small banner: "Pro tip: add personas to enable audience targeting. Set blog_url to enable style reference."
- **B.** Render placeholder muted rows inside the step list where the skipped step would have appeared.

Option B is closer to the spec, but requires knowing the project state and where in the order the row belongs. Option A is simpler and still achieves the "tell the user what to set" goal.

**Decision:** implement **Option A** as a single banner above the step list, because it's one insertion point and doesn't depend on interleaving with the existing step rendering.

- [ ] **Step 3: Determine whether the template has access to persona count and blog_url**

Look at the `pipeline.templ` function signature and its call site in `web/handlers/pipeline.go`. If the handler passes a view model that doesn't include persona count or blog_url, you will need to extend it.

**If the view model needs extending:**
- Find the view struct (likely named `PipelineView` or similar) in `web/handlers/pipeline.go` or `web/templates/pipeline.templ`.
- Add two bool fields: `HasPersonas bool` and `HasBlogURL bool`.
- Populate them in the handler's `show` method (around line 113 of `web/handlers/pipeline.go`) by calling `h.queries.ListAudiencePersonas(projectID)` and `h.queries.GetProjectSetting(projectID, "blog_url")`.

- [ ] **Step 4: Add the hint banner in `pipeline.templ`**

Above the step list (inside the main page body), add a templ conditional block:

```templ
if !viewModel.HasPersonas || !viewModel.HasBlogURL {
    <div class="alert alert-info mb-4 text-sm">
        <div class="flex flex-col gap-1">
            if !viewModel.HasPersonas {
                <div>Audience Picker step is disabled. Add personas on the profile page to enable it.</div>
            }
            if !viewModel.HasBlogURL {
                <div>Style Reference step is disabled. Set a blog URL in project settings to enable it.</div>
            }
        </div>
    </div>
}
```

Use whatever variable name the existing template uses for its view model — `viewModel` above is a placeholder.

- [ ] **Step 5: Regenerate templ-generated code if the repo uses `go generate` for templ**

Run: check if there's a `Makefile` target for templ. Run `grep -n templ Makefile`. If there's a `templ` target, run `make templ` (or equivalent). If templates are committed as `.templ` and built at runtime, skip this step.

If using precompiled templ code (`.templ` → `.go`), re-running `templ generate` is required. Typical command: `templ generate`.

- [ ] **Step 6: Build and restart**

Run: `go build ./... && make restart`
Expected: no errors, the pipeline page renders the banner when personas or blog_url are missing.

- [ ] **Step 7: Visually confirm the banner on a project without personas and without blog_url**

Navigate to such a project's pipeline page. Confirm the banner shows both messages.

Set a blog_url on the same project; reload. Confirm only the "add personas" message shows.

Add a persona; reload. Confirm the banner disappears entirely.

- [ ] **Step 8: Commit**

```bash
git add web/templates/pipeline.templ web/handlers/pipeline.go
# if templ generates .go files, also:
# git add web/templates/pipeline_templ.go
git commit -m "feat: pipeline page hints for skipped audience/style steps"
```

---

## Task 19: Manual smoke test

**Files:** none (validation only)

### Context

This task confirms the whole thing works end to end. No code changes. The goal is to run a real pipeline with the new steps and verify the outputs make sense.

- [ ] **Step 1: Confirm test project state**

Pick or create a project in the dev DB that has:
- At least 2 personas (one that fits a chef-knife topic, one that does not)
- A `blog_url` project setting pointing at a real brand blog
- A non-empty profile and product/positioning section

- [ ] **Step 2: Start a pipeline run with a topic that maps to one of your personas**

Use a topic like "mid-tier chef knives under $100." This should result in the audience picker selecting the chef persona (or whichever is closest), not an off-mode.

- [ ] **Step 3: Observe the pipeline page as the run executes**

Expected order in the step cards:
```
Researcher → Audience Picker → Brand Enricher → [Claim Verifier?] → Editor → Style Reference → Writer
```

- [ ] **Step 4: Inspect the audience_picker step card output**

Expected:
- `mode: persona`
- `persona_label: <your chef persona>`
- `reasoning` explains why this persona fits
- `guidance_for_writer` includes a "do not recommend X to Y" constraint

- [ ] **Step 5: Inspect the style_reference step card output**

Expected:
- 2-3 examples, each with a URL that was actually on the blog, a title, a why_chosen line, and an expandable full body
- No body under ~400 chars

- [ ] **Step 6: Inspect the generated blog post**

Compared with a run on the same topic before these changes, the new post should:
- Not recommend the mismatched SKU/plan/product class (check against the persona's guidance)
- Sound closer to the brand's actual blog voice

Read the `guidance_for_writer` from the audience picker output, then re-read the final post — did the writer honor the constraint?

- [ ] **Step 7: Start a second run with a topic that does NOT fit any persona**

Use a topic like "how search engines rank content" on a project whose personas are all about knives. The audience picker should select `educational` or `commentary`, not force a persona match.

- [ ] **Step 8: Run a third pipeline on a project with no personas and no blog_url**

Expected:
- The pipeline runs the original flow exactly (research → brand_enricher → editor → write)
- The pipeline page shows the hint banner
- No errors about skipped steps

- [ ] **Step 9: If any of the above fail, diagnose before proceeding**

Common failures and where to look:
- **audience_picker output missing `persona_label`** — hydration helper bug, see Task 8.
- **writer ignores audience guidance** — check that Task 12's writer runner actually passes `audienceBlock` into `ForWriter` and that the block is non-empty.
- **style_reference returns truncated bodies** — `fetch_url` may be returning HTML that's been stripped; check `internal/tools/fetch.go`'s extraction logic and whether it preserves enough text. The 400-char min-body check will surface this.
- **skipped step hint not showing** — view model extension in Task 18 may be missing fields or handler doesn't populate them.

- [ ] **Step 10: Commit any fixes from the smoke test**

If you had to patch anything, commit it as a fixup per the issue found.

---

## Self-Review

The plan was self-reviewed for:

- **Spec coverage:** Every section of the spec maps to at least one task. Pipeline shape → Tasks 13-14. Audience picker runner, tool, prompt → Tasks 4, 6, 8. Style reference runner, tool, prompt → Tasks 5, 7, 9. Downstream consumption → Tasks 10-12. Data/config/wiring → Tasks 1, 15. UI → Tasks 16-18. Testing → covered inline per task.
- **Placeholder scan:** no "TBD", "implement later", "similar to task N" references. Every code block is complete. Every step has explicit run commands and expected outputs.
- **Type consistency:** `AudienceSelection`, `ParseAudienceSelection`, `FormatAudienceBlock`, `StyleReference`, `StyleReferenceExample`, `ParseStyleReference`, `FormatStyleReferenceBlock`, `AudiencePickerStep`, `StyleReferenceStep`, `hydrateAudienceSelection`, `formatPersonasBlock`, `personaSummary` — all names used consistently across tasks. `ForBrandEnricher` / `ForEditor` / `ForWriter` signature changes are spelled out with the full new parameter list each time they're modified.
- **Compile order:** every task leaves the tree building. Signature changes to prompt builders are paired with the runner update in the same task.

Task 18 has one genuine unknown (view model shape in `pipeline.templ`) that the implementing agent has to discover by reading the file. That's flagged in the task and a fallback path is included.
