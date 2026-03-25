# Storytelling Framework & Settings Card Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a storytelling framework selector and convert the settings button to a card on the project overview page, with the selected framework injected into cornerstone content generation.

**Architecture:** Framework definitions live as Go structs in `internal/content/frameworks.go`. The overview page gets two new cards (Settings, Storytelling). A new handler + template serves the storytelling selection page. `buildPiecePrompt` in the pipeline handler conditionally injects framework beats for cornerstone pieces.

**Tech Stack:** Go, Templ, Alpine.js, SQLite (existing `project_settings` table)

**Spec:** `docs/superpowers/specs/2026-03-25-storytelling-framework-and-settings-card-design.md`

---

### Task 1: Framework Data Registry

**Files:**
- Create: `internal/content/frameworks.go`

- [ ] **Step 1: Create framework struct and registry**

```go
package content

// Framework defines a storytelling framework with its beats and prompt instruction.
type Framework struct {
	Key              string
	Name             string
	Attribution      string
	ShortDescription string
	BestFor          string
	Beats            string
	Example          string
	PromptInstruction string
}

var Frameworks = []Framework{
	{
		Key:              "pixar",
		Name:             "Pixar Framework",
		Attribution:      "Pixar Studios",
		ShortDescription: "Makes change memorable through emotionally resonant stories. Perfect for presenting new ideas or initiatives that need instant buy-in.",
		BestFor:          "Change management",
		Beats:            "Once upon a time… (Set the scene) / Every day… (The routine) / One day… (A change or conflict) / Because of that… (Immediate consequence) / Because of that… (What happened next) / Until finally… (The resolution)",
		Example:          "Once upon a time, businesses had to buy and manage their own expensive servers. Every day, IT teams would spend hours maintaining them. One day, AWS launched the cloud. Because of that, companies could rent server space on demand. Because of that, startups could scale globally overnight without massive capital. Until finally, the cloud became the standard for businesses everywhere, unlocking a new era of innovation.",
		PromptInstruction: "Structure this content using the Pixar storytelling framework. Follow these beats:\n1. Once upon a time… (Set the scene and the status quo)\n2. Every day… (Describe the routine, the normal)\n3. One day… (Introduce a change or conflict)\n4. Because of that… (Explain the immediate consequence)\n5. Because of that… (Show what happened next)\n6. Until finally… (Reveal the resolution)",
	},
	{
		Key:              "golden_circle",
		Name:             "Golden Circle",
		Attribution:      "Simon Sinek",
		ShortDescription: "Inspires action by starting with purpose, not product. Ideal for rallying teams, pitching investors, or building a brand people believe in.",
		BestFor:          "Vision/mission",
		Beats:            "WHY (core belief, purpose) → HOW (unique process, value proposition) → WHAT (products or services)",
		Example:          "Why: We believe in challenging the status quo. How: By making our products beautifully designed and simple to use. What: We just happen to make great computers.",
		PromptInstruction: "Structure this content using Simon Sinek's Golden Circle framework. Follow these beats:\n1. WHY — Start with the core belief or purpose\n2. HOW — Explain the unique process or value proposition\n3. WHAT — Describe the products or services\nLead with purpose. Sell the why before the what.",
	},
	{
		Key:              "storybrand",
		Name:             "StoryBrand",
		Attribution:      "Donald Miller",
		ShortDescription: "Flips traditional marketing: the customer is the hero, your brand is the guide. Creates marketing that connects by focusing on the customer's journey.",
		BestFor:          "Sales/marketing",
		Beats:            "Character (customer) has a Problem → meets a Guide (brand) with Empathy + Authority → gets a Plan → Call to Action → avoids Failure → achieves Success",
		Example:          "A small business owner (Hero) is struggling to keep track of their finances (Problem). They discover your accounting software (Guide), which offers a simple three-step setup (Plan). They sign up for a free trial (Call to Action) and finally gain control of their cash flow (Success), avoiding the chaos of tax season (Failure).",
		PromptInstruction: "Structure this content using Donald Miller's StoryBrand framework. Follow these beats:\n1. Character — The customer/reader as the hero\n2. Problem — The challenge they face\n3. Guide — Position the brand as the wise guide with empathy and authority\n4. Plan — Give them a clear plan (process + success path)\n5. Call to Action — Direct and transitional CTAs\n6. Stakes — What failure looks like if they don't act\n7. Success — The transformation after they act",
	},
	{
		Key:              "heros_journey",
		Name:             "Hero's Journey",
		Attribution:      "Joseph Campbell",
		ShortDescription: "The blueprint for epic tales — powerful for founder stories and personal brands because it makes the journey relatable and motivational.",
		BestFor:          "Personal branding",
		Beats:            "Call to Adventure → Crossing the Threshold → Tests, Allies, Enemies → The Ordeal → The Reward → The Road Back & Resurrection",
		Example:          "When a founder shares their story this way, we don't just hear about a company; we see ourselves in their struggle and root for their success.",
		PromptInstruction: "Structure this content using the Hero's Journey framework. Follow these beats:\n1. Call to Adventure — The initial idea or problem\n2. Crossing the Threshold — Committing to the journey\n3. Tests, Allies, Enemies — Challenges, mentors, competitors\n4. The Ordeal — The biggest challenge, a near-failure moment\n5. The Reward — The breakthrough or success\n6. The Road Back & Resurrection — Returning with new knowledge to transform the world",
	},
	{
		Key:              "three_act",
		Name:             "Three-Act Structure",
		Attribution:      "Classic",
		ShortDescription: "The fundamental architecture of all storytelling. Our brains are wired to understand information this way — perfect for keynotes, strategic plans, or presentations.",
		BestFor:          "Formal presentations",
		Beats:            "Act I: Setup (characters, world, status quo) → Act II: Conflict (problem, rising tension, stakes) → Act III: Resolution (confrontation, new reality, transformation)",
		Example:          "Think of it as: Beginning, Middle, End. It provides a clear, logical flow that keeps your audience engaged.",
		PromptInstruction: "Structure this content using the Three-Act Structure. Follow these beats:\n1. Act I — Setup: Introduce the context and the status quo. What is the current situation?\n2. Act II — Conflict: Introduce the problem or rising tension. This is where the struggle happens and stakes are raised.\n3. Act III — Resolution: The conflict is confronted and a new reality is established. What is the transformation or payoff?",
	},
	{
		Key:              "abt",
		Name:             "ABT (And/But/Therefore)",
		Attribution:      "Randy Olson",
		ShortDescription: "The secret weapon for persuasive emails, project updates, or elevator pitches. Distills complex ideas into a clear, compelling narrative in three steps.",
		BestFor:          "Daily communication",
		Beats:            "AND (establish context, agreement) → BUT (introduce the conflict or problem) → THEREFORE (propose the solution or resolution)",
		Example:          "We need to increase our market share, AND our competitors are gaining on us. BUT our current marketing strategy isn't delivering the results we need. THEREFORE, we must pivot to a new digital-first campaign focused on our core demographic.",
		PromptInstruction: "Structure this content using the ABT (And/But/Therefore) framework. Follow these beats:\n1. AND — Establish the context and shared agreement\n2. BUT — Introduce the conflict or the problem that makes the status quo untenable\n3. THEREFORE — Propose the solution or resolution\nKeep it tight and assertive. This framework is about clarity and momentum.",
	},
}

// FrameworkByKey returns the framework with the given key, or nil if not found.
func FrameworkByKey(key string) *Framework {
	for i := range Frameworks {
		if Frameworks[i].Key == key {
			return &Frameworks[i]
		}
	}
	return nil
}
```

- [ ] **Step 2: Verify it compiles**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go build ./internal/content/...`
Expected: no errors

- [ ] **Step 3: Commit**

```bash
git add internal/content/frameworks.go
git commit -m "feat: add storytelling framework registry with 6 frameworks"
```

---

### Task 2: Update Project Overview — Settings Card + Storytelling Card

**Files:**
- Modify: `web/templates/project.templ:5-12` (ProjectDetail struct)
- Modify: `web/templates/project.templ:20-62` (ProjectOverview template)
- Modify: `web/handlers/project.go:77-113` (ShowProject handler)

- [ ] **Step 1: Add fields to ProjectDetail struct**

In `web/templates/project.templ`, add two fields to the `ProjectDetail` struct:

```go
type ProjectDetail struct {
	ID             int64
	Name           string
	Description    string
	HasProfile     bool
	RunCount       int
	ContextItems   []ContextItemView
	Language       string // from project settings
	FrameworkName  string // display name of selected storytelling framework, empty if none
}
```

- [ ] **Step 2: Replace settings button with settings card, add storytelling card**

In the `ProjectOverview` template, remove the Settings button from the header `<div>` (line 25) so it only has the "Back" button. Then add two new cards to the grid — Settings card and Storytelling Framework card — after the Client Profile card:

Header becomes:
```
<div>
    <a href="/" class="btn btn-secondary">Back</a>
</div>
```

Add after the Client Profile card:
```html
<div class="card">
    <h3>Settings</h3>
    <p class="mb-2">
        if p.Language != "" {
            Language: <strong>{ p.Language }</strong>
        } else {
            <span class="badge badge-draft">Not set</span>
        }
    </p>
    <a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/settings", p.ID)) } class="btn btn-secondary">Manage Settings</a>
</div>

<div class="card">
    <h3>Storytelling Framework</h3>
    <p class="mb-2">
        if p.FrameworkName != "" {
            <span class="badge badge-approved">{ p.FrameworkName }</span>
        } else {
            <span class="badge badge-draft">Not set</span>
        }
    </p>
    <a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/storytelling", p.ID)) } class="btn btn-secondary">Choose Framework</a>
</div>
```

- [ ] **Step 3: Update ShowProject handler to populate new fields**

In `web/handlers/project.go`, in `ShowProject`, after the existing queries add:

```go
settings, _ := h.queries.AllProjectSettings(id)
frameworkName := ""
if fwKey := settings["storytelling_framework"]; fwKey != "" {
    if fw := content.FrameworkByKey(fwKey); fw != nil {
        frameworkName = fw.Name
    }
}
```

Add to the `detail` struct initialization:
```go
Language:      settings["language"],
FrameworkName: frameworkName,
```

Add import for `"github.com/zanfridau/marketminded/internal/content"` at the top.

- [ ] **Step 4: Generate templ and verify it compiles**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && make generate && go build ./...`
Expected: no errors

- [ ] **Step 5: Commit**

```bash
git add web/templates/project.templ web/templates/project_templ.go web/handlers/project.go
git commit -m "feat: add settings and storytelling framework cards to project overview"
```

---

### Task 3: Storytelling Framework Selection Page

**Files:**
- Create: `web/templates/storytelling.templ`
- Create: `web/handlers/storytelling.go`
- Modify: `cmd/server/main.go:58,87-104` (instantiate handler, add route)

- [ ] **Step 1: Create the storytelling template**

Create `web/templates/storytelling.templ`:

```go
package templates

import (
	"fmt"
	"github.com/zanfridau/marketminded/internal/content"
)

type StorytellingData struct {
	ProjectID    int64
	ProjectName  string
	Frameworks   []content.Framework
	SelectedKey  string
	Saved        bool
}

templ StorytellingPage(data StorytellingData) {
	@Layout(data.ProjectName + " - Storytelling Framework") {
		<div class="flex-between mb-4">
			<h1>Storytelling Framework</h1>
			<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d", data.ProjectID)) } class="btn btn-secondary">Back</a>
		</div>

		if data.Saved {
			<div class="card" style="background:#d1fae5;border-color:#059669;margin-bottom:1rem">
				<p style="color:#065f46">Framework saved.</p>
			</div>
		}

		<p class="text-muted mb-2">Choose a storytelling framework to structure your cornerstone content (blog posts, video scripts). This guides how the AI structures the narrative.</p>

		<form method="POST" action={ templ.SafeURL(fmt.Sprintf("/projects/%d/storytelling", data.ProjectID)) }>
			<!-- None option -->
			<div class={ "card mb-1", templ.KV("card-selected", data.SelectedKey == "") } style="cursor:pointer" x-data="{ expanded: false }">
				<label style="display:flex;align-items:center;gap:0.75rem;cursor:pointer;margin-bottom:0">
					<input type="radio" name="storytelling_framework" value=""
						if data.SelectedKey == "" {
							checked
						}
					/>
					<div>
						<strong>None</strong>
						<span class="text-muted" style="margin-left:0.5rem">— No framework applied. AI uses its default structure.</span>
					</div>
				</label>
			</div>

			for _, fw := range data.Frameworks {
				<div class={ "card mb-1", templ.KV("card-selected", data.SelectedKey == fw.Key) } style="cursor:pointer" x-data="{ expanded: false }">
					<label style="display:flex;align-items:start;gap:0.75rem;cursor:pointer;margin-bottom:0">
						<input type="radio" name="storytelling_framework" value={ fw.Key }
							if data.SelectedKey == fw.Key {
								checked
							}
							style="margin-top:0.25rem"
						/>
						<div style="flex:1">
							<div class="flex-between">
								<div>
									<strong>{ fw.Name }</strong>
									<span class="text-muted" style="margin-left:0.5rem">— { fw.Attribution }</span>
								</div>
								<span class="badge badge-draft">{ fw.BestFor }</span>
							</div>
							<p class="text-muted" style="margin:0.5rem 0 0.25rem 0;font-size:0.875rem">{ fw.ShortDescription }</p>
							<button type="button" class="btn btn-secondary" style="font-size:0.75rem;padding:0.15rem 0.5rem;margin-top:0.25rem" @click.prevent="expanded = !expanded" x-text="expanded ? 'Hide details' : 'Show details'">Show details</button>
							<div x-show="expanded" x-cloak style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid #e5e5e5">
								<p style="font-size:0.875rem"><strong>Beats:</strong> { fw.Beats }</p>
								<p style="font-size:0.875rem;margin-top:0.5rem"><strong>Example:</strong> { fw.Example }</p>
							</div>
						</div>
					</label>
				</div>
			}

			<button type="submit" class="btn mt-2">Save Framework</button>
		</form>
	}
}
```

- [ ] **Step 2: Create the storytelling handler**

Create `web/handlers/storytelling.go`:

```go
package handlers

import (
	"fmt"
	"net/http"

	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/web/templates"
)

type StorytellingHandler struct {
	queries *store.Queries
}

func NewStorytellingHandler(q *store.Queries) *StorytellingHandler {
	return &StorytellingHandler{queries: q}
}

func (h *StorytellingHandler) Handle(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	if r.Method == "POST" {
		h.save(w, r, projectID)
		return
	}
	h.show(w, r, projectID)
}

func (h *StorytellingHandler) show(w http.ResponseWriter, r *http.Request, projectID int64) {
	project, err := h.queries.GetProject(projectID)
	if err != nil {
		http.NotFound(w, r)
		return
	}

	selectedKey, _ := h.queries.GetProjectSetting(projectID, "storytelling_framework")

	templates.StorytellingPage(templates.StorytellingData{
		ProjectID:   projectID,
		ProjectName: project.Name,
		Frameworks:  content.Frameworks,
		SelectedKey: selectedKey,
		Saved:       r.URL.Query().Get("saved") == "1",
	}).Render(r.Context(), w)
}

func (h *StorytellingHandler) save(w http.ResponseWriter, r *http.Request, projectID int64) {
	r.ParseForm()
	value := r.FormValue("storytelling_framework")
	// Only save known keys or empty string (clear)
	if value != "" && content.FrameworkByKey(value) == nil {
		value = ""
	}
	h.queries.SetProjectSetting(projectID, "storytelling_framework", value)
	http.Redirect(w, r, fmt.Sprintf("/projects/%d/storytelling?saved=1", projectID), http.StatusSeeOther)
}
```

- [ ] **Step 3: Register route and handler in main.go**

In `cmd/server/main.go`:

After `projectSettingsHandler` initialization (line 58), add:
```go
storytellingHandler := handlers.NewStorytellingHandler(queries)
```

In the switch block (after `case rest == "settings"...` at line 100-101), add:
```go
case strings.HasPrefix(rest, "storytelling"):
    storytellingHandler.Handle(w, r, projectID, rest)
```

- [ ] **Step 4: Generate templ and verify it compiles**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && make generate && go build ./...`
Expected: no errors

- [ ] **Step 5: Add card-selected CSS style**

The storytelling template uses `card-selected` class to highlight the active framework. Add to `web/static/style.css`:

```css
.card-selected { border-color: #2563eb; background: #eff6ff; }
```

- [ ] **Step 6: Commit**

```bash
git add web/templates/storytelling.templ web/templates/storytelling_templ.go web/handlers/storytelling.go cmd/server/main.go web/static/style.css
git commit -m "feat: add storytelling framework selection page"
```

---

### Task 4: Pipeline Integration — Inject Framework into Cornerstone Prompts

**Files:**
- Modify: `web/handlers/pipeline.go:353-384` (buildPiecePrompt)
- Modify: `web/handlers/pipeline.go:399-401` (streamPiece caller)

- [ ] **Step 1: Add projectID parameter to buildPiecePrompt**

Change the signature from:
```go
func (h *PipelineHandler) buildPiecePrompt(piece *store.ContentPiece, run *store.PipelineRun, profile string) string {
```
to:
```go
func (h *PipelineHandler) buildPiecePrompt(projectID int64, piece *store.ContentPiece, run *store.PipelineRun, profile string) string {
```

- [ ] **Step 2: Inject framework for cornerstone pieces**

Inside `buildPiecePrompt`, within the existing `if piece.ParentID == nil` block (line 368-370), add the framework injection *before* the topic brief line. The block currently reads:

```go
if piece.ParentID == nil {
    // Cornerstone
    prompt += fmt.Sprintf("\n## Topic brief\n%s\n", run.Brief)
}
```

Change it to:

```go
if piece.ParentID == nil {
    // Cornerstone — inject storytelling framework if set
    if fwKey, err := h.queries.GetProjectSetting(projectID, "storytelling_framework"); err == nil && fwKey != "" {
        if fw := content.FrameworkByKey(fwKey); fw != nil {
            prompt += fmt.Sprintf("\n## Storytelling framework\nFramework: %s (%s)\n%s\n", fw.Name, fw.Attribution, fw.PromptInstruction)
        }
    }
    prompt += fmt.Sprintf("\n## Topic brief\n%s\n", run.Brief)
}
```

- [ ] **Step 3: Update the caller in streamPiece**

In `streamPiece` (line 401), change:
```go
systemPrompt := h.buildPiecePrompt(piece, run, profile)
```
to:
```go
systemPrompt := h.buildPiecePrompt(projectID, piece, run, profile)
```

Check for any other callers of `buildPiecePrompt` and update them too.

- [ ] **Step 4: Verify it compiles**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go build ./...`
Expected: no errors

- [ ] **Step 5: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: inject storytelling framework into cornerstone piece prompts"
```

---

### Task 5: Manual Smoke Test

- [ ] **Step 1: Start the server**

Run: `make start` (or `make restart` if already running)

- [ ] **Step 2: Verify overview page**

Navigate to a project overview page. Confirm:
- Settings button is gone from the header
- Settings card appears in the grid showing language or "Not set"
- Storytelling Framework card appears showing "Not set"
- All 6 cards render properly in the grid

- [ ] **Step 3: Verify storytelling page**

Click "Choose Framework" on the storytelling card. Confirm:
- All 6 frameworks display with name, attribution, description, best-for badge
- "Show details" expands to show beats and example
- Radio selection works
- Saving redirects back with success message
- Overview card now shows the selected framework name

- [ ] **Step 4: Verify pipeline integration**

Start a pipeline run on a project with a framework selected. Check that the cornerstone piece's generation includes the framework in its prompt (visible in server logs or by checking the generated content structure).
