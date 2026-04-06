# Storytelling Frameworks + Voice & Tone Integration

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move storytelling framework selection into the Voice & Tone agent, add preferred content length, display V&T as individual subcards, and remove the manual storytelling picker.

**Architecture:** Add two columns to `voice_tone_profiles` (JSON frameworks array + integer length). Update the V&T agent prompt and tool schema to output these fields. Replace the editor step's project-settings-based framework lookup with V&T profile lookup. Rework the V&T card in the profile template to show individual subcards per section.

**Tech Stack:** Go, SQLite (goose migrations), templ templates, vanilla JS

**Spec:** `docs/superpowers/specs/2026-04-04-storytelling-voice-tone-integration-design.md`

---

### Task 1: Database Migration

**Files:**
- Create: `migrations/014_voice_tone_storytelling.sql`

- [ ] **Step 1: Create migration file**

```sql
-- +goose Up
ALTER TABLE voice_tone_profiles ADD COLUMN storytelling_frameworks TEXT NOT NULL DEFAULT '[]';
ALTER TABLE voice_tone_profiles ADD COLUMN preferred_length INTEGER NOT NULL DEFAULT 1500;

DELETE FROM project_settings WHERE key = 'storytelling_framework';

-- +goose Down
-- SQLite doesn't support DROP COLUMN, but goose down is for dev only
```

- [ ] **Step 2: Run migration**

Run: `make migrate-up` (or however migrations run — check Makefile)
Expected: Migration applies cleanly, no errors.

- [ ] **Step 3: Verify columns exist**

Run: `sqlite3 data/marketminded.db ".schema voice_tone_profiles"`
Expected: Table shows `storytelling_frameworks TEXT` and `preferred_length INTEGER` columns.

- [ ] **Step 4: Commit**

```bash
git add migrations/014_voice_tone_storytelling.sql
git commit -m "feat: add storytelling_frameworks and preferred_length to voice_tone_profiles"
```

---

### Task 2: Update Go Store Layer

**Files:**
- Modify: `internal/store/voice_tone.go`
- Modify: `internal/store/interfaces.go:48-53`

- [ ] **Step 1: Add FrameworkSelection type and update VoiceToneProfile struct**

In `internal/store/voice_tone.go`, add the `FrameworkSelection` type and two new fields to `VoiceToneProfile`:

```go
type FrameworkSelection struct {
	Key  string `json:"key"`
	Note string `json:"note"`
}

type VoiceToneProfile struct {
	ID                     int64
	ProjectID              int64
	VoiceAnalysis          string
	ContentTypes           string
	ShouldAvoid            string
	ShouldUse              string
	StyleInspiration       string
	StorytellingFrameworks string // JSON: [{"key":"storybrand","note":"..."},...]
	PreferredLength        int
	CreatedAt              time.Time
}
```

- [ ] **Step 2: Add ParseFrameworks helper**

In `internal/store/voice_tone.go`:

```go
func (vt *VoiceToneProfile) ParseFrameworks() []FrameworkSelection {
	var fs []FrameworkSelection
	json.Unmarshal([]byte(vt.StorytellingFrameworks), &fs)
	return fs
}
```

Add `"encoding/json"` to the import block.

- [ ] **Step 3: Update UpsertVoiceToneProfile**

Replace the existing `UpsertVoiceToneProfile` method:

```go
func (q *Queries) UpsertVoiceToneProfile(projectID int64, vt VoiceToneProfile) error {
	_, err := q.db.Exec(
		`INSERT INTO voice_tone_profiles (project_id, voice_analysis, content_types, should_avoid, should_use, style_inspiration, storytelling_frameworks, preferred_length)
		 VALUES (?, ?, ?, ?, ?, ?, ?, ?)
		 ON CONFLICT(project_id) DO UPDATE SET
		   voice_analysis = ?, content_types = ?, should_avoid = ?, should_use = ?, style_inspiration = ?,
		   storytelling_frameworks = ?, preferred_length = ?`,
		projectID, vt.VoiceAnalysis, vt.ContentTypes, vt.ShouldAvoid, vt.ShouldUse, vt.StyleInspiration, vt.StorytellingFrameworks, vt.PreferredLength,
		vt.VoiceAnalysis, vt.ContentTypes, vt.ShouldAvoid, vt.ShouldUse, vt.StyleInspiration, vt.StorytellingFrameworks, vt.PreferredLength,
	)
	return err
}
```

- [ ] **Step 4: Update GetVoiceToneProfile**

Replace the existing `GetVoiceToneProfile` method:

```go
func (q *Queries) GetVoiceToneProfile(projectID int64) (*VoiceToneProfile, error) {
	vt := &VoiceToneProfile{}
	err := q.db.QueryRow(
		`SELECT id, project_id, voice_analysis, content_types, should_avoid, should_use, style_inspiration, storytelling_frameworks, preferred_length, created_at
		 FROM voice_tone_profiles WHERE project_id = ?`, projectID,
	).Scan(&vt.ID, &vt.ProjectID, &vt.VoiceAnalysis, &vt.ContentTypes, &vt.ShouldAvoid, &vt.ShouldUse, &vt.StyleInspiration, &vt.StorytellingFrameworks, &vt.PreferredLength, &vt.CreatedAt)
	return vt, err
}
```

- [ ] **Step 5: Update BuildVoiceToneString to include frameworks and length**

Replace the existing `BuildVoiceToneString` method:

```go
func (q *Queries) BuildVoiceToneString(projectID int64) (string, error) {
	vt, err := q.GetVoiceToneProfile(projectID)
	if err != nil {
		return "", nil
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

	// Storytelling frameworks summary
	frameworks := vt.ParseFrameworks()
	if len(frameworks) > 0 {
		b.WriteString("### Storytelling Frameworks\n")
		for _, f := range frameworks {
			fw := content.FrameworkByKey(f.Key)
			if fw != nil {
				fmt.Fprintf(&b, "- %s: %s\n", fw.Name, f.Note)
			}
		}
		b.WriteString("\n")
	}

	// Preferred length
	if vt.PreferredLength > 0 {
		fmt.Fprintf(&b, "### Preferred Length\nTarget: ~%d words\n\n", vt.PreferredLength)
	}

	return b.String(), nil
}
```

Add `"github.com/zanfridau/marketminded/internal/content"` to the import block.

- [ ] **Step 6: Build and verify**

Run: `go build ./...`
Expected: Compiles cleanly.

- [ ] **Step 7: Commit**

```bash
git add internal/store/voice_tone.go
git commit -m "feat: add storytelling frameworks and preferred length to voice tone store"
```

---

### Task 3: Update Voice & Tone Agent (Prompt + Tool Schema + Save/Get Handlers)

**Files:**
- Modify: `web/handlers/voice_tone.go:97-141` (getProfile, saveProfile)
- Modify: `web/handlers/voice_tone.go:225-313` (prompt + tool schema)
- Modify: `web/handlers/voice_tone.go:256-263` (existing profile section)
- Modify: `web/handlers/voice_tone.go:1370-1416` (app.js result handling)

- [ ] **Step 1: Update getProfile handler**

In `web/handlers/voice_tone.go`, replace the `getProfile` method (lines 97-113):

```go
func (h *VoiceToneHandler) getProfile(w http.ResponseWriter, r *http.Request, projectID int64) {
	vt, err := h.queries.GetVoiceToneProfile(projectID)
	if err != nil {
		w.Header().Set("Content-Type", "application/json")
		w.Write([]byte("{}"))
		return
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{
		"voice_analysis":          vt.VoiceAnalysis,
		"content_types":           vt.ContentTypes,
		"should_avoid":            vt.ShouldAvoid,
		"should_use":              vt.ShouldUse,
		"style_inspiration":       vt.StyleInspiration,
		"storytelling_frameworks": json.RawMessage(vt.StorytellingFrameworks),
		"preferred_length":        vt.PreferredLength,
	})
}
```

- [ ] **Step 2: Update saveProfile handler**

Replace the `saveProfile` method (lines 115-141):

```go
func (h *VoiceToneHandler) saveProfile(w http.ResponseWriter, r *http.Request, projectID int64) {
	var body struct {
		VoiceAnalysis          string          `json:"voice_analysis"`
		ContentTypes           string          `json:"content_types"`
		ShouldAvoid            string          `json:"should_avoid"`
		ShouldUse              string          `json:"should_use"`
		StyleInspiration       string          `json:"style_inspiration"`
		StorytellingFrameworks json.RawMessage `json:"storytelling_frameworks"`
		PreferredLength        int             `json:"preferred_length"`
	}
	if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
		http.Error(w, "Invalid request", http.StatusBadRequest)
		return
	}

	frameworksJSON := "[]"
	if len(body.StorytellingFrameworks) > 0 {
		frameworksJSON = string(body.StorytellingFrameworks)
	}

	preferredLength := body.PreferredLength
	if preferredLength == 0 {
		preferredLength = 1500
	}

	err := h.queries.UpsertVoiceToneProfile(projectID, store.VoiceToneProfile{
		VoiceAnalysis:          body.VoiceAnalysis,
		ContentTypes:           body.ContentTypes,
		ShouldAvoid:            body.ShouldAvoid,
		ShouldUse:              body.ShouldUse,
		StyleInspiration:       body.StyleInspiration,
		StorytellingFrameworks: frameworksJSON,
		PreferredLength:        preferredLength,
	})
	if err != nil {
		http.Error(w, "Failed to save profile", http.StatusInternalServerError)
		return
	}

	w.WriteHeader(http.StatusOK)
}
```

- [ ] **Step 3: Update system prompt — inject frameworks list and new step**

In `streamGenerate`, after the existing prompt section that writes "## Your Task" (around line 272), replace the task steps. Find the string that starts with `` `## Your Task` `` and replace it with:

```go
// Build framework reference for the prompt
var frameworkRef strings.Builder
frameworkRef.WriteString("## Available Storytelling Frameworks\n")
for _, fw := range content.Frameworks {
	fmt.Fprintf(&frameworkRef, "\n**%s** (%s)\n", fw.Name, fw.Attribution)
	fmt.Fprintf(&frameworkRef, "Best for: %s\n", fw.BestFor)
	fmt.Fprintf(&frameworkRef, "%s\n", fw.ShortDescription)
	fmt.Fprintf(&frameworkRef, "Key: `%s`\n", fw.Key)
}
frameworkRef.WriteString("\n")
systemPrompt.WriteString(frameworkRef.String())
```

Then update the task instructions string to include the new step:

```go
systemPrompt.WriteString(`## Your Task

### Step 1: Discover and fetch blog posts
The blog URLs above are listing/index pages. Use fetch_url to find links to 3-5 recent individual blog posts on each listing page, then fetch each individual post to read the full content. Do the same for any liked articles and inspiration URLs that point to listing pages.

### Step 2: Analyze writing patterns
Analyze the writing patterns across ALL fetched posts. Focus on STYLE, not content. Look at:
- Voice and personality (formal/informal, warm/cold, peer/authority)
- Sentence structure, length, and rhythm
- Vocabulary level and recurring phrases
- How they address the reader
- Formatting patterns (headings, lists, CTAs)
- What makes their good posts (liked articles) different from average ones
- What style patterns the inspiration sources share

### Step 3: Select storytelling frameworks
Review the available frameworks listed above. Pick 1-3 that best fit this brand's voice, audience, and content style. For each, write a short adaptation note explaining why it fits and how the editor/writer should apply it to this brand specifically.

### Step 4: Determine preferred content length
If you can infer a typical article length from the analyzed blog posts (estimate their word counts and average them), use that as the preferred length. If you cannot infer, default to 1500.

### Step 5: Produce structured output
Call submit_voice_tone with 7 fields:
1. **Voice Analysis** - Brand personality, formality level, warmth, how they relate to the reader
2. **Content Types** - What content approaches the brand uses (educational, promotional, storytelling, opinion, how-to, case study, etc.)
3. **Should Avoid** - Words, phrases, patterns, and tones to never use
4. **Should Use** - Characteristic vocabulary, phrases, sentence patterns, formatting conventions
5. **Style Inspiration** - Writing style patterns observed from the inspiration sources
6. **Storytelling Frameworks** - 1-3 framework selections from the available frameworks, each with key and adaptation note
7. **Preferred Length** - Target word count as an integer

## Rules
- ALWAYS write in English.
- Analyze STYLE, not content. Focus on HOW they write, not WHAT they write about.
- Be specific to THIS brand. Generic voice guidelines are useless.
- Include concrete examples and direct quotes from the source material where possible.
- NEVER use em dashes. Use commas, periods, or restructure.
- Write like a human. Short, direct sentences. Vary length.
- You MUST fetch individual blog posts — do not analyze only the listing page.
`)
```

Add `"github.com/zanfridau/marketminded/internal/content"` to the import block if not already present.

- [ ] **Step 4: Update existing profile section in prompt**

Find the block around lines 256-263 where `existingVT` is written into the prompt. Add the new fields:

```go
if existingVT != nil {
	systemPrompt.WriteString("## Existing Voice & Tone Profile (review and improve)\n")
	fmt.Fprintf(&systemPrompt, "### Voice Analysis\n%s\n\n", existingVT.VoiceAnalysis)
	fmt.Fprintf(&systemPrompt, "### Content Types\n%s\n\n", existingVT.ContentTypes)
	fmt.Fprintf(&systemPrompt, "### Should Avoid\n%s\n\n", existingVT.ShouldAvoid)
	fmt.Fprintf(&systemPrompt, "### Should Use\n%s\n\n", existingVT.ShouldUse)
	fmt.Fprintf(&systemPrompt, "### Style Inspiration\n%s\n\n", existingVT.StyleInspiration)
	fmt.Fprintf(&systemPrompt, "### Storytelling Frameworks\n%s\n\n", existingVT.StorytellingFrameworks)
	fmt.Fprintf(&systemPrompt, "### Preferred Length\n%d words\n\n", existingVT.PreferredLength)
}
```

- [ ] **Step 5: Update tool schema**

Replace the `submitVoiceToneTool` definition (around line 306-313):

```go
submitVoiceToneTool := ai.Tool{
	Type: "function",
	Function: ai.ToolFunction{
		Name:        "submit_voice_tone",
		Description: "Submit the structured voice & tone analysis.",
		Parameters: json.RawMessage(`{
			"type":"object",
			"properties":{
				"voice_analysis":{"type":"string","description":"Brand personality, formality level, warmth, how they relate to the reader"},
				"content_types":{"type":"string","description":"What content approaches the brand uses"},
				"should_avoid":{"type":"string","description":"Words, phrases, patterns, and tones to never use"},
				"should_use":{"type":"string","description":"Characteristic vocabulary, phrases, sentence patterns"},
				"style_inspiration":{"type":"string","description":"Writing style patterns observed from the inspiration sources"},
				"storytelling_frameworks":{"type":"array","items":{"type":"object","properties":{"key":{"type":"string","enum":["pixar","golden_circle","storybrand","heros_journey","three_act","abt"]},"note":{"type":"string","description":"Brand-specific adaptation: why this framework fits and how to apply it"}},"required":["key","note"]},"description":"1-3 storytelling frameworks best suited for this brand"},
				"preferred_length":{"type":"integer","description":"Target word count. Infer from analyzed blog posts if possible, default 1500"}
			},
			"required":["voice_analysis","content_types","should_avoid","should_use","style_inspiration","storytelling_frameworks","preferred_length"]
		}`),
	},
}
```

- [ ] **Step 6: Build and verify**

Run: `go build ./...`
Expected: Compiles cleanly.

- [ ] **Step 7: Commit**

```bash
git add web/handlers/voice_tone.go
git commit -m "feat: update voice tone agent with storytelling frameworks and preferred length"
```

---

### Task 4: Update Editor Step to Read Frameworks from V&T Profile

**Files:**
- Modify: `internal/pipeline/steps/editor.go`
- Modify: `cmd/server/main.go:73`

- [ ] **Step 1: Replace ProjectSettings dependency with VoiceTone**

In `internal/pipeline/steps/editor.go`, change the struct:

```go
type EditorStep struct {
	AI        *ai.Client
	Tools     *tools.Registry
	Prompt    *prompt.Builder
	Pipeline  store.PipelineStore
	VoiceTone store.VoiceToneStore
	Model     func() string
}
```

- [ ] **Step 2: Replace framework lookup in Run method**

Replace lines 43-48 (the `frameworkBlock` construction) with:

```go
var frameworkBlock string
if vt, err := s.VoiceTone.GetVoiceToneProfile(input.ProjectID); err == nil {
	frameworks := vt.ParseFrameworks()
	if len(frameworks) > 0 {
		var fb strings.Builder
		fb.WriteString("## Storytelling frameworks\n")
		for _, f := range frameworks {
			fw := content.FrameworkByKey(f.Key)
			if fw == nil {
				continue
			}
			fmt.Fprintf(&fb, "\n### %s (%s)\n", fw.Name, fw.Attribution)
			fb.WriteString(fw.PromptInstruction)
			fmt.Fprintf(&fb, "\nBrand adaptation: %s\n", f.Note)
		}
		frameworkBlock = fb.String()
	}
}
```

Add these to the import block: `"fmt"`, `"strings"`, `"github.com/zanfridau/marketminded/internal/content"`.

Remove the `"github.com/zanfridau/marketminded/internal/store"` import if it's no longer used (it was only needed for `ProjectSettingsStore`). Keep it if other types from store are still referenced.

- [ ] **Step 3: Update wiring in main.go**

In `cmd/server/main.go`, find line 73:

```go
&steps.EditorStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Pipeline: queries, ProjectSettings: queries, Model: contentModel},
```

Replace with:

```go
&steps.EditorStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Pipeline: queries, VoiceTone: queries, Model: contentModel},
```

- [ ] **Step 4: Build and verify**

Run: `go build ./...`
Expected: Compiles cleanly.

- [ ] **Step 5: Commit**

```bash
git add internal/pipeline/steps/editor.go cmd/server/main.go
git commit -m "feat: editor step reads storytelling frameworks from voice tone profile"
```

---

### Task 4b: Update Pipeline Handler's Direct Rewrite Path

**Files:**
- Modify: `web/handlers/pipeline.go:265-270`

The pipeline handler has a separate code path for direct piece rewrites (not through the orchestrator) that also reads `storytelling_framework` from project settings. This needs the same treatment as the editor step.

- [ ] **Step 1: Replace framework lookup in pipeline handler**

In `web/handlers/pipeline.go`, find lines 265-270:

```go
var frameworkBlock string
if fwKey, err := h.queries.GetProjectSetting(projectID, "storytelling_framework"); err == nil && fwKey != "" {
	if fw := content.FrameworkByKey(fwKey); fw != nil {
		frameworkBlock = fmt.Sprintf("## Storytelling framework\nFramework: %s (%s)\n%s", fw.Name, fw.Attribution, fw.PromptInstruction)
	}
}
```

Replace with:

```go
var frameworkBlock string
if vt, err := h.queries.GetVoiceToneProfile(projectID); err == nil {
	frameworks := vt.ParseFrameworks()
	if len(frameworks) > 0 {
		var fb strings.Builder
		fb.WriteString("## Storytelling frameworks\n")
		for _, f := range frameworks {
			fw := content.FrameworkByKey(f.Key)
			if fw == nil {
				continue
			}
			fmt.Fprintf(&fb, "\n### %s (%s)\n", fw.Name, fw.Attribution)
			fb.WriteString(fw.PromptInstruction)
			fmt.Fprintf(&fb, "\nBrand adaptation: %s\n", f.Note)
		}
		frameworkBlock = fb.String()
	}
}
```

Ensure `"strings"` is in the import block.

- [ ] **Step 2: Build and verify**

Run: `go build ./...`
Expected: Compiles cleanly.

- [ ] **Step 3: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: pipeline handler reads frameworks from voice tone profile"
```

---

### Task 5: Update V&T Card UI — Subcards Display

**Files:**
- Modify: `web/templates/profile.templ:169-178`

- [ ] **Step 1: Replace V&T profile content blob with subcards**

In `web/templates/profile.templ`, replace the block from line 169 (`if card.VoiceToneProfile != nil {`) through line 178 (`}`) — the block that renders the single blob. Replace with individual subcards:

```templ
if card.VoiceToneProfile != nil {
	<!-- Voice Analysis -->
	<div class="bg-zinc-800/50 border border-zinc-800 rounded-lg mt-2">
		<div class="p-3">
			<strong class="text-sm">Voice Analysis</strong>
			<div class="profile-content-preview max-h-20 overflow-hidden relative mt-1" data-section="vt-voice-analysis">
				<div class="profile-md-content text-sm leading-relaxed text-zinc-300">{ card.VoiceToneProfile.VoiceAnalysis }</div>
				<div class="absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-zinc-900 to-transparent pointer-events-none profile-fade"></div>
			</div>
			<button type="button" class="btn btn-ghost btn-xs mt-1 profile-expand-btn" data-section="vt-voice-analysis">Show more</button>
		</div>
	</div>
	<!-- Content Types -->
	<div class="bg-zinc-800/50 border border-zinc-800 rounded-lg mt-2">
		<div class="p-3">
			<strong class="text-sm">Content Types</strong>
			<div class="profile-content-preview max-h-20 overflow-hidden relative mt-1" data-section="vt-content-types">
				<div class="profile-md-content text-sm leading-relaxed text-zinc-300">{ card.VoiceToneProfile.ContentTypes }</div>
				<div class="absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-zinc-900 to-transparent pointer-events-none profile-fade"></div>
			</div>
			<button type="button" class="btn btn-ghost btn-xs mt-1 profile-expand-btn" data-section="vt-content-types">Show more</button>
		</div>
	</div>
	<!-- Should Avoid -->
	<div class="bg-zinc-800/50 border border-zinc-800 rounded-lg mt-2">
		<div class="p-3">
			<strong class="text-sm">Should Avoid</strong>
			<div class="profile-content-preview max-h-20 overflow-hidden relative mt-1" data-section="vt-should-avoid">
				<div class="profile-md-content text-sm leading-relaxed text-zinc-300">{ card.VoiceToneProfile.ShouldAvoid }</div>
				<div class="absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-zinc-900 to-transparent pointer-events-none profile-fade"></div>
			</div>
			<button type="button" class="btn btn-ghost btn-xs mt-1 profile-expand-btn" data-section="vt-should-avoid">Show more</button>
		</div>
	</div>
	<!-- Should Use -->
	<div class="bg-zinc-800/50 border border-zinc-800 rounded-lg mt-2">
		<div class="p-3">
			<strong class="text-sm">Should Use</strong>
			<div class="profile-content-preview max-h-20 overflow-hidden relative mt-1" data-section="vt-should-use">
				<div class="profile-md-content text-sm leading-relaxed text-zinc-300">{ card.VoiceToneProfile.ShouldUse }</div>
				<div class="absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-zinc-900 to-transparent pointer-events-none profile-fade"></div>
			</div>
			<button type="button" class="btn btn-ghost btn-xs mt-1 profile-expand-btn" data-section="vt-should-use">Show more</button>
		</div>
	</div>
	<!-- Style Inspiration -->
	<div class="bg-zinc-800/50 border border-zinc-800 rounded-lg mt-2">
		<div class="p-3">
			<strong class="text-sm">Style Inspiration</strong>
			<div class="profile-content-preview max-h-20 overflow-hidden relative mt-1" data-section="vt-style-inspiration">
				<div class="profile-md-content text-sm leading-relaxed text-zinc-300">{ card.VoiceToneProfile.StyleInspiration }</div>
				<div class="absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-zinc-900 to-transparent pointer-events-none profile-fade"></div>
			</div>
			<button type="button" class="btn btn-ghost btn-xs mt-1 profile-expand-btn" data-section="vt-style-inspiration">Show more</button>
		</div>
	</div>
	<!-- Storytelling Frameworks -->
	for _, f := range card.VoiceToneProfile.ParseFrameworks() {
		<div class="bg-zinc-800/50 border border-zinc-800 rounded-lg mt-2">
			<div class="p-3">
				if fw := content.FrameworkByKey(f.Key); fw != nil {
					<div class="flex items-center gap-2">
						<strong class="text-sm">{ fw.Name }</strong>
						<span class="badge badge-outline text-[10px]">{ fw.BestFor }</span>
					</div>
					<div class="profile-content-preview max-h-20 overflow-hidden relative mt-1" data-section={ "vt-fw-" + f.Key }>
						<p class="text-sm leading-relaxed text-zinc-300">{ f.Note }</p>
						<p class="text-xs text-zinc-500 mt-2">{ fw.PromptInstruction }</p>
						<div class="absolute bottom-0 left-0 right-0 h-8 bg-gradient-to-t from-zinc-900 to-transparent pointer-events-none profile-fade"></div>
					</div>
					<button type="button" class="btn btn-ghost btn-xs mt-1 profile-expand-btn" data-section={ "vt-fw-" + f.Key }>Show more</button>
				}
			</div>
		</div>
	}
	<!-- Preferred Length -->
	<div class="bg-zinc-800/50 border border-zinc-800 rounded-lg mt-2">
		<div class="p-3">
			<div class="flex items-center justify-between">
				<strong class="text-sm">Preferred Length</strong>
				<span class="text-sm text-zinc-300">{ fmt.Sprintf("~%s words", formatNumber(card.VoiceToneProfile.PreferredLength)) }</span>
			</div>
		</div>
	</div>
} else {
	<p class="text-zinc-500 italic text-sm mt-2">No voice & tone profile yet. Click Build to generate one.</p>
}
```

- [ ] **Step 2: Add formatNumber helper and imports**

In `web/templates/profile.templ`, add the `content` import at the top:

```go
import (
	"fmt"
	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates/components"
)
```

Add a helper function near the `pluralS` function:

```go
func formatNumber(n int) string {
	if n < 1000 {
		return fmt.Sprintf("%d", n)
	}
	return fmt.Sprintf("%d,%03d", n/1000, n%1000)
}
```

- [ ] **Step 3: Generate templ output**

Run: `templ generate`
Expected: No errors.

- [ ] **Step 4: Build and verify**

Run: `go build ./...`
Expected: Compiles cleanly.

- [ ] **Step 5: Commit**

```bash
git add web/templates/profile.templ web/templates/profile_templ.go
git commit -m "feat: display voice tone profile as individual subcards"
```

---

### Task 6: Update V&T Edit Modal and JS for New Fields

**Files:**
- Modify: `web/templates/profile.templ:435-448` (vt-edit-modal)
- Modify: `web/static/app.js:1370-1482`

- [ ] **Step 1: Update vt-edit-modal to include framework checkboxes and length input**

In `web/templates/profile.templ`, replace the vt-edit-modal content (lines 435-448):

```templ
<!-- Voice & Tone Edit modal -->
<dialog id="vt-edit-modal" class="modal">
	<div class="bg-zinc-900 border border-zinc-800 rounded-xl shadow-2xl w-[90%] max-w-3xl p-6 mx-auto mt-[10vh] max-h-[80vh] overflow-y-auto">
		<div class="flex items-center justify-between mb-4">
			<h3 class="font-bold text-lg">Edit Voice & Tone Profile</h3>
			<button type="button" class="btn btn-ghost btn-xs" onclick="this.closest('dialog').close()">&#x2715;</button>
		</div>
		<div id="vt-edit-fields"></div>
		<div id="vt-edit-frameworks" class="mb-4">
			<label class="block text-sm font-medium text-zinc-300 mb-2">Storytelling Frameworks</label>
			for _, fw := range content.Frameworks {
				<div class="bg-zinc-800/50 rounded-lg p-3 mb-2">
					<label class="flex items-center gap-2 cursor-pointer">
						<input type="checkbox" class="checkbox checkbox-sm vt-fw-checkbox" data-fw-key={ fw.Key }/>
						<strong class="text-sm">{ fw.Name }</strong>
						<span class="badge badge-outline text-[10px]">{ fw.BestFor }</span>
					</label>
					<textarea class="textarea w-full text-sm mt-2 hidden vt-fw-note" data-fw-key={ fw.Key } rows="2" placeholder={ "Why " + fw.Name + " fits this brand..." }></textarea>
				</div>
			}
		</div>
		<div class="mb-4">
			<label class="block text-sm font-medium text-zinc-300 mb-1">Preferred Length (words)</label>
			<input type="number" id="vt-edit-preferred-length" class="input w-32" min="300" max="10000" step="50" value="1500"/>
		</div>
		<div class="flex justify-end gap-2 mt-6">
			<button type="button" id="vt-edit-save-btn" class="btn btn-primary">Save changes</button>
			<button type="button" id="vt-edit-cancel-btn" class="btn btn-ghost">Cancel</button>
		</div>
	</div>
	<form method="dialog"><button class="hidden">close</button></form>
</dialog>
```

- [ ] **Step 2: Update JS — edit modal population**

In `web/static/app.js`, find the vt-edit-btn click handler (around line 1423). Replace the `then(function(data) { ... })` callback:

```javascript
.then(function(data) {
    var fields = [
        {key: 'voice_analysis', label: 'Voice Analysis', rows: 4},
        {key: 'content_types', label: 'Content Types', rows: 4},
        {key: 'should_avoid', label: 'Should Avoid', rows: 4},
        {key: 'should_use', label: 'Should Use', rows: 4},
        {key: 'style_inspiration', label: 'Style Inspiration', rows: 4}
    ];
    fields.forEach(function(f) {
        var wrapper = document.createElement('div');
        wrapper.className = 'mb-3';

        var lbl = document.createElement('label');
        lbl.className = 'label';
        var span = document.createElement('span');
        span.className = 'label-text font-semibold text-sm';
        span.textContent = f.label;
        lbl.appendChild(span);
        wrapper.appendChild(lbl);

        var textarea = document.createElement('textarea');
        textarea.className = 'textarea textarea-bordered w-full text-sm';
        textarea.rows = f.rows;
        textarea.value = data[f.key] || '';
        textarea.dataset.field = f.key;
        wrapper.appendChild(textarea);

        fieldsContainer.appendChild(wrapper);
    });

    // Populate framework checkboxes
    var frameworks = data.storytelling_frameworks || [];
    var fwMap = {};
    frameworks.forEach(function(f) { fwMap[f.key] = f.note; });
    document.querySelectorAll('.vt-fw-checkbox').forEach(function(cb) {
        var key = cb.dataset.fwKey;
        var noteEl = document.querySelector('.vt-fw-note[data-fw-key="' + key + '"]');
        if (fwMap[key] !== undefined) {
            cb.checked = true;
            noteEl.classList.remove('hidden');
            noteEl.value = fwMap[key];
        } else {
            cb.checked = false;
            noteEl.classList.add('hidden');
            noteEl.value = '';
        }
        cb.addEventListener('change', function() {
            if (cb.checked) {
                noteEl.classList.remove('hidden');
            } else {
                noteEl.classList.add('hidden');
                noteEl.value = '';
            }
        });
    });

    // Populate preferred length
    document.getElementById('vt-edit-preferred-length').value = data.preferred_length || 1500;
});
```

- [ ] **Step 3: Update JS — edit save handler**

Replace the vt-edit-save-btn click handler (around line 1466):

```javascript
document.getElementById('vt-edit-save-btn').addEventListener('click', function() {
    var fieldsContainer = document.getElementById('vt-edit-fields');
    var inputs = fieldsContainer.querySelectorAll('[data-field]');
    var data = {};
    inputs.forEach(function(input) {
        data[input.dataset.field] = input.value;
    });

    // Collect frameworks
    var frameworks = [];
    document.querySelectorAll('.vt-fw-checkbox:checked').forEach(function(cb) {
        var key = cb.dataset.fwKey;
        var note = document.querySelector('.vt-fw-note[data-fw-key="' + key + '"]').value;
        frameworks.push({key: key, note: note});
    });
    data.storytelling_frameworks = frameworks;
    data.preferred_length = parseInt(document.getElementById('vt-edit-preferred-length').value) || 1500;

    fetch('/projects/' + projectId + '/profile/voice_and_tone/profile', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(data)
    }).then(function() { window.location.reload(); });
});
```

- [ ] **Step 4: Update JS — generation result handling**

In the SSE `case 'result':` block (around line 1370), update to handle the new fields:

```javascript
case 'result':
    var parsed = typeof d.data === 'string' ? JSON.parse(d.data) : d.data;
    vtGeneratedResult = parsed;
    var text = '';
    if (parsed.voice_analysis) text += '## Voice Analysis\n' + parsed.voice_analysis + '\n\n';
    if (parsed.content_types) text += '## Content Types\n' + parsed.content_types + '\n\n';
    if (parsed.should_avoid) text += '## Should Avoid\n' + parsed.should_avoid + '\n\n';
    if (parsed.should_use) text += '## Should Use\n' + parsed.should_use + '\n\n';
    if (parsed.style_inspiration) text += '## Style Inspiration\n' + parsed.style_inspiration + '\n\n';
    if (parsed.storytelling_frameworks && parsed.storytelling_frameworks.length > 0) {
        text += '## Storytelling Frameworks\n';
        parsed.storytelling_frameworks.forEach(function(f) {
            text += '- ' + f.key + ': ' + f.note + '\n';
        });
        text += '\n';
    }
    if (parsed.preferred_length) text += '## Preferred Length\n~' + parsed.preferred_length + ' words\n\n';
    textarea.value = text.trim();
    textarea.scrollTop = 0;
    break;
```

- [ ] **Step 5: Generate templ and build**

Run: `templ generate && go build ./...`
Expected: Compiles cleanly.

- [ ] **Step 6: Commit**

```bash
git add web/templates/profile.templ web/templates/profile_templ.go web/static/app.js
git commit -m "feat: voice tone edit modal with framework checkboxes and preferred length"
```

---

### Task 7: Remove Storytelling Page and Navigation

**Files:**
- Delete: `web/handlers/storytelling.go`
- Delete: `web/templates/storytelling.templ` (and generated `web/templates/storytelling_templ.go`)
- Modify: `cmd/server/main.go:87,129-130`
- Modify: `web/templates/components/layout.templ:150-153`
- Modify: `web/handlers/project_settings.go`
- Modify: `web/templates/project_settings.templ`

- [ ] **Step 1: Delete storytelling handler and template files**

```bash
rm web/handlers/storytelling.go
rm web/templates/storytelling.templ
rm -f web/templates/storytelling_templ.go
```

- [ ] **Step 2: Remove storytelling handler wiring from main.go**

In `cmd/server/main.go`, remove line 87:

```go
storytellingHandler := handlers.NewStorytellingHandler(queries)
```

And remove lines 129-130:

```go
case strings.HasPrefix(rest, "storytelling"):
	storytellingHandler.Handle(w, r, projectID, rest)
```

- [ ] **Step 3: Remove storytelling nav link from sidebar**

In `web/templates/components/layout.templ`, remove lines 150-153 (the storytelling link):

```templ
<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/storytelling", projectID)) } class={ sidebarLinkClass(activePage, "storytelling") }>
	<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"></path></svg>
	Storytelling
</a>
```

- [ ] **Step 4: Remove storytelling framework from project settings page**

In `web/handlers/project_settings.go`, remove the framework-related code. Update the `show` method to remove `fwOptions` construction and remove `StorytellingFramework` and `Frameworks` from the template data:

```go
func (h *ProjectSettingsHandler) show(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	settings, _ := h.queries.AllProjectSettings(projectID)

	templates.ProjectSettingsPage(templates.ProjectSettingsData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Language:    settings["language"],
		Saved:       r.URL.Query().Get("saved") == "1",
	}).Render(r.Context(), w)
}
```

Update the `save` method to remove the storytelling framework setting:

```go
func (h *ProjectSettingsHandler) save(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	h.queries.SetProjectSetting(projectID, "language", r.FormValue("language"))
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/settings?saved=1", projectID), http.StatusSeeOther)
}
```

Remove the `"github.com/zanfridau/marketminded/internal/content"` import since it's no longer used.

- [ ] **Step 5: Update project_settings.templ to remove framework section**

In `web/templates/project_settings.templ`, remove `FrameworkOption` type, remove `StorytellingFramework` and `Frameworks` from `ProjectSettingsData`, and remove the entire "Storytelling Framework" card block (lines 46-72). The resulting template should only have the Language card:

```templ
package templates

import "fmt"
import "github.com/zanfridau/marketminded/web/templates/components"

type ProjectSettingsData struct {
	ProjectID   int64
	ProjectName string
	Language    string
	Saved       bool
}

templ ProjectSettingsPage(data ProjectSettingsData) {
	@components.ProjectPageShell(data.ProjectName + " - Settings", []components.Breadcrumb{
		{Label: "Projects", URL: "/"},
		{Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
		{Label: "Settings"},
	}, data.ProjectID, data.ProjectName, "settings") {
		<div class="flex items-center justify-between mb-6">
			<h1 class="text-xl font-semibold text-zinc-100">Project Settings</h1>
			<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d", data.ProjectID)) } class="btn btn-ghost">Back</a>
		</div>

		if data.Saved {
			<div class="alert-success mb-4">Settings saved.</div>
		}

		<form method="POST" action={ templ.SafeURL(fmt.Sprintf("/projects/%d/settings", data.ProjectID)) }>
			<div class="mb-4">
				@components.Card("Language") {
					@components.FormGroup("Content Language") {
						<input type="text" name="language" value={ data.Language } placeholder="e.g. English, Slovenian, Spanish..." class="input"/>
						<p class="text-zinc-500 text-xs mt-1">Used for proofreading and grammar checks. AI will write and correct in this language.</p>
					}
				}
			</div>

			@components.SubmitButton("Save Settings")
		</form>
	}
}
```

- [ ] **Step 6: Generate templ and build**

Run: `templ generate && go build ./...`
Expected: Compiles cleanly.

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "feat: remove storytelling page, clean up project settings"
```

---

### Task 8: Manual Smoke Test

- [ ] **Step 1: Start the application**

Run: `make start`

- [ ] **Step 2: Verify storytelling page is gone**

Navigate to `/projects/{id}/storytelling`. Expected: 404.
Check sidebar. Expected: No "Storytelling" link.

- [ ] **Step 3: Verify V&T card displays subcards**

Navigate to `/projects/{id}/profile`. If a V&T profile exists, verify:
- 5 individual text section subcards (Voice Analysis, Content Types, Should Avoid, Should Use, Style Inspiration)
- Each has "Show more" button that expands
- Storytelling Frameworks subcards (if any were set)
- Preferred Length subcard showing formatted word count

- [ ] **Step 4: Test V&T edit modal**

Click "Edit" on the V&T card. Verify:
- 5 text fields populated with existing data
- Framework checkboxes section (all 6 frameworks listed)
- Checking a framework reveals its adaptation note textarea
- Preferred length number input
- Save persists changes and page reloads with updated subcards

- [ ] **Step 5: Test V&T generation**

Click "Build" (or "Rebuild") on the V&T card. Verify:
- Generation stream includes framework selection in the result
- Generated result shows storytelling_frameworks and preferred_length
- Saving the result persists all 7 fields
- Card re-renders with framework subcards and length

- [ ] **Step 6: Test pipeline**

Run a content pipeline. Verify:
- Editor step receives framework block with full PromptInstruction + adaptation note
- Writer step receives profile string with preferred length
- No errors from missing `storytelling_framework` project setting

- [ ] **Step 7: Verify project settings page**

Navigate to `/projects/{id}/settings`. Verify:
- Only "Language" setting remains
- No storytelling framework dropdown
- Save works correctly
