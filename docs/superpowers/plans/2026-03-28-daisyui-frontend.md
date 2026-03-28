# DaisyUI Frontend Migration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Migrate the entire frontend from custom CSS to Tailwind + DaisyUI, consolidate 1,889 lines of vanilla JS into Alpine.js components, and establish a reusable templ component library.

**Architecture:** Build tooling (Tailwind CLI) → component library (templ) → JS consolidation (Alpine components + renderers) → page migrations (all 12 pages). Each task produces a compiling, working state.

**Tech Stack:** Tailwind CSS 3, DaisyUI 4, Alpine.js 3, templ, marked.js, Go

---

### Task 1: Tailwind + DaisyUI Build Setup

**Files:**
- Create: `tailwind.config.js`
- Create: `web/static/input.css`
- Create: `package.json`
- Modify: `Makefile`
- Modify: `.gitignore`

- [ ] **Step 1: Initialize npm and install Tailwind + DaisyUI**

```bash
cd /Users/zanfridau/CODE/AI/marketminded
npm init -y
npm install -D tailwindcss@3 daisyui@4
```

- [ ] **Step 2: Create Tailwind config**

Create `tailwind.config.js`:

```js
/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './web/templates/**/*.templ',
    './web/static/js/**/*.js',
  ],
  theme: {
    extend: {},
  },
  plugins: [require('daisyui')],
  daisyui: {
    themes: ['business'],
  },
}
```

- [ ] **Step 3: Create Tailwind input CSS**

Create `web/static/input.css`:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;
```

- [ ] **Step 4: Generate initial output.css**

```bash
npx tailwindcss -i web/static/input.css -o web/static/output.css
```

- [ ] **Step 5: Add output.css and node_modules to .gitignore**

Add to `.gitignore`:
```
node_modules/
web/static/output.css
```

- [ ] **Step 6: Update Makefile**

Replace the existing Makefile with:

```makefile
include .env
export

.PHONY: generate build run dev start restart test clean reset css

css:
	npx tailwindcss -i web/static/input.css -o web/static/output.css

generate: css
	~/go/bin/templ generate ./web/templates/

build: generate
	go build -o server ./cmd/server/

run: build
	./server

dev: build
	@echo "Starting MarketMinded on :8080..."
	@./server

start: build
	@pkill -f './server' 2>/dev/null || true
	@sleep 1
	@echo "Starting MarketMinded on :8080..."
	@./server &

restart: build
	@pkill -f './server' 2>/dev/null || true
	@sleep 1
	@echo "Restarting MarketMinded on :8080..."
	@./server &

test:
	go test ./...

clean:
	rm -f server marketminded

reset: clean
	rm -f marketminded.db
	@echo "DB reset. Run 'make start' to start fresh."
```

- [ ] **Step 7: Update layout.templ to load output.css alongside style.css**

Temporarily load both so the app works during migration. In `web/templates/layout.templ`, add the output.css link:

```go
templ Layout(title string) {
	<!DOCTYPE html>
	<html lang="en" data-theme="business">
	<head>
		<meta charset="UTF-8"/>
		<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
		<title>{ title } - MarketMinded</title>
		<link rel="stylesheet" href="/static/style.css"/>
		<link rel="stylesheet" href="/static/output.css"/>
		<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
		<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
		<script src="/static/app.js" defer></script>
	</head>
	<body>
		<nav class="flex-between">
			<a href="/">MarketMinded</a>
			<div class="nav-links">
				<a href="/settings">Settings</a>
			</div>
		</nav>
		<main>
			{ children... }
		</main>
	</body>
	</html>
}
```

Note: `data-theme="business"` activates the DaisyUI theme.

- [ ] **Step 8: Verify build works**

```bash
make build
```

Expected: SUCCESS — templ generates, Tailwind generates output.css, Go compiles.

- [ ] **Step 9: Verify app runs**

```bash
make restart
```

Open http://localhost:8080 — app should look identical (old CSS still loaded, DaisyUI available but not yet used).

- [ ] **Step 10: Commit**

```bash
git add tailwind.config.js web/static/input.css package.json package-lock.json Makefile .gitignore web/templates/layout.templ
git commit -m "feat: add Tailwind + DaisyUI build setup"
```

---

### Task 2: Templ Component Library — Layout, Card, Badge, Form

**Files:**
- Create: `web/templates/components/layout.templ`
- Create: `web/templates/components/card.templ`
- Create: `web/templates/components/badge.templ`
- Create: `web/templates/components/form.templ`

- [ ] **Step 1: Create components directory**

```bash
mkdir -p web/templates/components
```

- [ ] **Step 2: Create layout component**

Create `web/templates/components/layout.templ`:

```go
package components

type Breadcrumb struct {
	Label string
	URL   string
}

templ PageShell(title string, breadcrumbs []Breadcrumb) {
	<!DOCTYPE html>
	<html lang="en" data-theme="business">
	<head>
		<meta charset="UTF-8"/>
		<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
		<title>{ title } - MarketMinded</title>
		<link rel="stylesheet" href="/static/output.css"/>
		<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
		<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
		<script src="/static/app.js" defer></script>
	</head>
	<body class="min-h-screen bg-base-200">
		<div class="navbar bg-base-100 shadow-sm">
			<div class="flex-1">
				<a href="/" class="btn btn-ghost text-xl">MarketMinded</a>
			</div>
			<div class="flex-none">
				<a href="/settings" class="btn btn-ghost btn-sm">Settings</a>
			</div>
		</div>
		if len(breadcrumbs) > 0 {
			<div class="bg-base-100 border-b border-base-300 px-4 py-2">
				<div class="breadcrumbs text-sm max-w-5xl mx-auto">
					<ul>
						for _, bc := range breadcrumbs {
							<li>
								if bc.URL != "" {
									<a href={ templ.SafeURL(bc.URL) }>{ bc.Label }</a>
								} else {
									<span>{ bc.Label }</span>
								}
							</li>
						}
					</ul>
				</div>
			</div>
		}
		<main class="max-w-5xl mx-auto p-4">
			{ children... }
		</main>
	</body>
	</html>
}
```

- [ ] **Step 3: Create card components**

Create `web/templates/components/card.templ`:

```go
package components

templ Card(title string) {
	<div class="card bg-base-100 shadow-sm border border-base-300">
		<div class="card-body">
			if title != "" {
				<h3 class="card-title text-base">{ title }</h3>
			}
			{ children... }
		</div>
	</div>
}

templ CollapsibleCard(title string, defaultOpen bool) {
	<div class="card bg-base-100 shadow-sm border border-base-300" x-data={ collapseInit(defaultOpen) }>
		<div class="card-body">
			<div class="flex items-center justify-between cursor-pointer" @click="open = !open">
				<h3 class="card-title text-base">{ title }</h3>
				<button class="btn btn-ghost btn-xs" x-text="open ? '−' : '+'"></button>
			</div>
			<div x-show="open" x-collapse>
				{ children... }
			</div>
		</div>
	</div>
}

func collapseInit(defaultOpen bool) string {
	if defaultOpen {
		return "{ open: true }"
	}
	return "{ open: false }"
}
```

- [ ] **Step 4: Create badge component**

Create `web/templates/components/badge.templ`:

```go
package components

func badgeClass(status string) string {
	switch status {
	case "pending":
		return "badge badge-ghost"
	case "running":
		return "badge badge-warning"
	case "completed", "complete":
		return "badge badge-success"
	case "failed":
		return "badge badge-error"
	case "draft":
		return "badge badge-info"
	case "approved":
		return "badge badge-success"
	case "rejected":
		return "badge badge-error"
	case "generating":
		return "badge badge-warning animate-pulse"
	case "abandoned":
		return "badge badge-ghost"
	default:
		return "badge"
	}
}

templ StatusBadge(status string) {
	<span class={ badgeClass(status) }>{ status }</span>
}
```

- [ ] **Step 5: Create form components**

Create `web/templates/components/form.templ`:

```go
package components

templ FormGroup(label string) {
	<div class="form-control w-full">
		if label != "" {
			<label class="label">
				<span class="label-text">{ label }</span>
			</label>
		}
		{ children... }
	</div>
}

templ Button(text, variant string) {
	<button type="button" class={ "btn btn-" + variant }>{ text }</button>
}

templ SubmitButton(text string) {
	<button type="submit" class="btn btn-primary">{ text }</button>
}
```

- [ ] **Step 6: Create modal component**

Create `web/templates/components/modal.templ`:

```go
package components

templ Modal(id string) {
	<dialog id={ id } class="modal">
		<div class="modal-box">
			{ children... }
		</div>
		<form method="dialog" class="modal-backdrop">
			<button>close</button>
		</form>
	</dialog>
}
```

Open with `document.getElementById(id).showModal()`, close with `document.getElementById(id).close()` or clicking backdrop.

- [ ] **Step 7: Verify components compile**

```bash
~/go/bin/templ generate ./web/templates/ && go build ./...
```

Expected: SUCCESS

- [ ] **Step 8: Commit**

```bash
git add web/templates/components/
git commit -m "feat: add DaisyUI templ component library (layout, card, badge, form)"
```

---

### Task 3: JavaScript Reorganization — Renderers

**Files:**
- Create: `web/static/js/renderers/markdown.js`
- Create: `web/static/js/renderers/step-output.js`
- Create: `web/static/js/renderers/content-body.js`

Extract the renderer functions from `app.js` into separate files. These are pure functions with no Alpine dependency — they just take DOM elements and data, and render content.

- [ ] **Step 1: Create JS directory structure**

```bash
mkdir -p web/static/js/renderers web/static/js/alpine-components
```

- [ ] **Step 2: Create markdown renderer**

Create `web/static/js/renderers/markdown.js`:

```js
// Markdown rendering via marked.js
function renderMarkdown(text) {
    if (!text) return '';
    // Strip leading/trailing whitespace per line for cleaner markdown
    var cleaned = text.split('\n').map(function(line) { return line.trimEnd(); }).join('\n');
    return marked.parse(cleaned, { breaks: false, gfm: true });
}
```

- [ ] **Step 3: Create step output renderer**

Create `web/static/js/renderers/step-output.js`. Copy the `renderStepOutput`, `renderSourcesSubcard`, `makeSubcard`, `renderSection`, and `renderField` functions from `app.js` (lines 766-1717). These functions are already standalone — just move them to this file verbatim.

Read the current `app.js` functions at lines 766-810 (renderSection, renderField), 827-970 (content renderers — skip, those go to content-body.js), 1545-1600 (makeSubcard, renderSourcesSubcard, renderMarkdown), and 1601-1717 (renderStepOutput).

Copy them all into this file. No modifications needed — they're pure functions.

- [ ] **Step 4: Create content body renderer**

Create `web/static/js/renderers/content-body.js`. Copy the `renderContentBody`, `renderBlogPost`, `renderSimplePost`, `renderXPost`, `renderXThread`, `renderLinkedinCarousel`, `renderInstagramCarousel`, `renderScript`, `renderYoutubeScript`, and `renderPlan` functions from `app.js` (lines 809-970).

Copy them verbatim — they're pure functions.

- [ ] **Step 5: Load renderer scripts in layout.templ**

Update `web/templates/layout.templ` to load the new scripts (keep app.js too for now):

```html
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="/static/js/renderers/markdown.js"></script>
<script src="/static/js/renderers/step-output.js"></script>
<script src="/static/js/renderers/content-body.js"></script>
<script src="/static/app.js" defer></script>
```

- [ ] **Step 6: Remove extracted functions from app.js**

Delete the following function definitions from `app.js`:
- `renderSection` (line ~766)
- `renderField` (line ~805)
- `renderPlan` (line ~809)
- `renderContentBody` (line ~827)
- `renderBlogPost` (line ~871)
- `renderSimplePost` (line ~890)
- `renderXPost` (line ~895)
- `renderXThread` (line ~899)
- `renderLinkedinCarousel` (line ~913)
- `renderInstagramCarousel` (line ~926)
- `renderScript` (line ~940)
- `renderYoutubeScript` (line ~954)
- `makeSubcard` (line ~1545)
- `renderSourcesSubcard` (line ~1558)
- `renderMarkdown` (line ~1589)
- `renderStepOutput` (line ~1601)

Keep everything else in `app.js` for now.

- [ ] **Step 7: Verify app still works**

```bash
make restart
```

Navigate to a pipeline run with completed steps. Verify step outputs still render correctly. The functions are now loaded from separate files but called from the same places in `app.js`.

- [ ] **Step 8: Commit**

```bash
git add web/static/js/ web/static/app.js web/templates/layout.templ
git commit -m "refactor: extract JS renderers into separate files"
```

---

### Task 4: JavaScript Reorganization — Alpine Components

**Files:**
- Create: `web/static/js/alpine-components/chat.js`
- Create: `web/static/js/alpine-components/pipeline.js`
- Create: `web/static/js/alpine-components/content-piece.js`
- Create: `web/static/js/app-new.js`

This is the largest JS task. We're converting the duplicated chat initializers and pipeline functions into Alpine components.

- [ ] **Step 1: Create the streaming chat Alpine component**

Create `web/static/js/alpine-components/chat.js`:

This component replaces `initProfileChat`, `initProfileSectionChat`, `initContextChat`, and the brainstorm inline script. It handles:
- Message sending via POST
- SSE streaming with thinking, chunks, tool events, proposals
- Auto-scroll
- Cmd+Enter keyboard shortcut

Read the existing chat initializer patterns in `app.js` (lines 158-461 for profile chat, 485-653 for profile section chat, 654-765 for context chat) and the brainstorm inline script in `brainstorm.templ` (lines 89-228). Consolidate the common pattern into one Alpine component.

The component should be registered as:
```js
document.addEventListener('alpine:init', () => {
    Alpine.data('streamingChat', (config) => ({
        // config: { sendURL, streamURL, projectID, onProposal, onSave, onDone }
        messages: [],
        input: '',
        streaming: false,
        thinkingContent: '',
        thinkingDone: false,
        streamContent: '',
        error: '',
        source: null,
        // ... methods
    }));
});
```

Key config options:
- `sendURL` — POST endpoint for user messages
- `streamURL` — SSE endpoint
- `projectID` — for proposal accept/reject URLs
- `onProposal(section, content)` — callback for proposal events (profile chat only)
- `onSave()` — callback for save button (context chat only)
- `onDone()` — callback on completion (default: reload page)

- [ ] **Step 2: Create the pipeline Alpine component**

Create `web/static/js/alpine-components/pipeline.js`:

This replaces `initCornerstonePipeline` and `initProductionBoard`. Read the existing functions at lines 1200-1544 and 1718-1889 of `app.js`.

Register as:
```js
document.addEventListener('alpine:init', () => {
    Alpine.data('pipelineBoard', (config) => ({
        // config: { projectID, runID, nextPieceID }
        // ... state and methods for step streaming
    }));
});
```

Key responsibilities:
- `streamStep(stepEl)` — stream a single step with tool pills, thinking ticker
- `runNextStep()` — chain through pending steps
- `runPipeline()` — start the full pipeline sequence
- Tool pill rendering into step card's pill container
- Thinking ticker animation and auto-scroll
- Step output rendering on completion

- [ ] **Step 3: Create the content piece Alpine component**

Create `web/static/js/alpine-components/content-piece.js`:

This replaces the inline event delegation for approve/reject/improve/proofread. Read `openContentModal` (lines 974-1199) and the event delegation in `initCornerstonePipeline`.

Register as:
```js
document.addEventListener('alpine:init', () => {
    Alpine.data('contentPiece', (config) => ({
        // config: { projectID, runID, pieceID }
        // ... methods for approve, reject, improve, proofread
    }));
});
```

- [ ] **Step 4: Create new app.js entry point**

Create `web/static/js/app-new.js`:

```js
// Entry point — registers DOMContentLoaded init for any page-specific setup
document.addEventListener('DOMContentLoaded', function() {
    // Auto-scroll chat messages to bottom on page load
    document.querySelectorAll('.chat-messages').forEach(function(el) {
        el.scrollTop = el.scrollHeight;
    });
});
```

This replaces the massive DOMContentLoaded handler in the current `app.js`. Most initialization is now handled by Alpine `x-data` attributes in templates — no imperative init needed.

- [ ] **Step 5: Load Alpine component scripts in layout.templ**

Update the layout to load new JS files. At this point, load BOTH old and new JS (the old app.js still has some functions used by un-migrated pages):

Add before the closing `</head>`:
```html
<script src="/static/js/alpine-components/chat.js"></script>
<script src="/static/js/alpine-components/pipeline.js"></script>
<script src="/static/js/alpine-components/content-piece.js"></script>
```

- [ ] **Step 6: Verify build**

```bash
make build
```

Expected: SUCCESS. Alpine components are registered but not yet used by templates.

- [ ] **Step 7: Commit**

```bash
git add web/static/js/alpine-components/ web/static/js/app-new.js
git commit -m "feat: add Alpine.js components for chat, pipeline, and content pieces"
```

---

### Task 5: Migrate Simple Pages (Dashboard, Project New, Settings)

**Files:**
- Modify: `web/templates/dashboard.templ`
- Modify: `web/templates/project_new.templ`
- Modify: `web/templates/settings.templ`

Start with the simplest pages to validate the component library works end-to-end.

- [ ] **Step 1: Migrate dashboard**

Rewrite `web/templates/dashboard.templ` to use `PageShell` and DaisyUI classes:

```go
package templates

import (
	"fmt"
	"github.com/zanfridau/marketminded/web/templates/components"
)

templ Dashboard(projects []DashboardProject) {
	@components.PageShell("Dashboard", []components.Breadcrumb{{Label: "Projects"}}) {
		<div class="flex items-center justify-between mb-6">
			<h1 class="text-2xl font-bold">Projects</h1>
			<a href="/projects/new" class="btn btn-primary">New Project</a>
		</div>
		if len(projects) == 0 {
			<p class="text-base-content/60">No projects yet. Create one to get started.</p>
		}
		<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
			for _, p := range projects {
				<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d", p.ID)) } class="card bg-base-100 shadow-sm border border-base-300 hover:border-primary transition-colors no-underline">
					<div class="card-body">
						<h3 class="card-title text-base">{ p.Name }</h3>
						<p class="text-base-content/60 text-sm">{ p.Description }</p>
					</div>
				</a>
			}
		</div>
	}
}

type DashboardProject struct {
	ID          int64
	Name        string
	Description string
}
```

- [ ] **Step 2: Migrate project new page**

Rewrite `web/templates/project_new.templ`:

```go
package templates

import "github.com/zanfridau/marketminded/web/templates/components"

templ ProjectNew() {
	@components.PageShell("New Project", []components.Breadcrumb{
		{Label: "Projects", URL: "/"},
		{Label: "New Project"},
	}) {
		<h1 class="text-2xl font-bold mb-6">New Project</h1>
		<div class="card bg-base-100 shadow-sm border border-base-300">
			<div class="card-body">
				<form method="POST" action="/projects">
					@components.FormGroup("Project Name") {
						<input type="text" name="name" required placeholder="e.g. Acme Corp" class="input input-bordered w-full"/>
					}
					@components.FormGroup("Description") {
						<textarea name="description" placeholder="Brief description of the client/project" class="textarea textarea-bordered w-full"></textarea>
					}
					<div class="mt-4">
						@components.SubmitButton("Create Project")
					</div>
				</form>
			</div>
		</div>
	}
}
```

- [ ] **Step 3: Migrate settings page**

Rewrite `web/templates/settings.templ` using `PageShell`, DaisyUI form controls, and `StatusBadge` for the saved alert:

```go
package templates

import "github.com/zanfridau/marketminded/web/templates/components"

type SettingsData struct {
	ModelContent     string
	ModelCopywriting string
	ModelIdeation    string
	ModelProofread   string
	Temperature      string
	Saved            bool
}

templ SettingsPage(data SettingsData) {
	@components.PageShell("Settings", []components.Breadcrumb{
		{Label: "Projects", URL: "/"},
		{Label: "Settings"},
	}) {
		<div class="flex items-center justify-between mb-6">
			<h1 class="text-2xl font-bold">Settings</h1>
			<a href="/" class="btn btn-ghost btn-sm">Back</a>
		</div>

		if data.Saved {
			<div class="alert alert-success mb-4">
				<span>Settings saved.</span>
			</div>
		}

		<form method="POST" action="/settings">
			<div class="card bg-base-100 shadow-sm border border-base-300">
				<div class="card-body">
					<h3 class="card-title text-base mb-2">AI Models</h3>
					<p class="text-base-content/60 text-sm mb-4">Choose which OpenRouter models to use. Leave blank to use defaults from environment variables.</p>

					@components.FormGroup("Content Research Model (research, fact-checking, brand enrichment, tone analysis)") {
						<input type="text" name="model_content" value={ data.ModelContent } placeholder="e.g. x-ai/grok-4.1-fast" class="input input-bordered w-full"/>
					}

					@components.FormGroup("Copywriting Model (cornerstone article writing)") {
						<input type="text" name="model_copywriting" value={ data.ModelCopywriting } placeholder="e.g. anthropic/claude-sonnet-4-20250514" class="input input-bordered w-full"/>
					}

					@components.FormGroup("Ideation Model (brainstorming, idea generation)") {
						<input type="text" name="model_ideation" value={ data.ModelIdeation } placeholder="e.g. anthropic/claude-sonnet-4-20250514" class="input input-bordered w-full"/>
					}

					@components.FormGroup("Proofread Model (should be fast + cheap)") {
						<input type="text" name="model_proofread" value={ data.ModelProofread } placeholder="openai/gpt-4o-mini" class="input input-bordered w-full"/>
					}

					@components.FormGroup("Temperature (0.0 = precise, 1.0 = creative)") {
						<input type="text" name="temperature" value={ data.Temperature } placeholder="0.3" class="input input-bordered w-full"/>
					}

					<p class="text-base-content/40 text-xs mt-2">
						Popular models: x-ai/grok-4.1-fast, anthropic/claude-sonnet-4-20250514, openai/gpt-4o, google/gemini-2.0-flash-001
					</p>

					<div class="mt-4">
						@components.SubmitButton("Save Settings")
					</div>
				</div>
			</div>
		</form>
	}
}
```

- [ ] **Step 4: Verify build and visual check**

```bash
make restart
```

Open http://localhost:8080 — dashboard, new project, and settings pages should now use DaisyUI styling with the "business" theme. Other pages still use old styling.

- [ ] **Step 5: Commit**

```bash
git add web/templates/dashboard.templ web/templates/project_new.templ web/templates/settings.templ
git commit -m "feat: migrate dashboard, project new, and settings pages to DaisyUI"
```

---

### Task 6: Migrate Project Overview and Project Settings

**Files:**
- Modify: `web/templates/project.templ`
- Modify: `web/templates/project_settings.templ`

- [ ] **Step 1: Migrate project overview**

Rewrite `web/templates/project.templ` using `PageShell`, `Card`, `StatusBadge`, and DaisyUI classes. Keep the same layout structure (feature cards grid + context items list) but use DaisyUI components.

Key changes:
- Use `PageShell` with breadcrumbs: `[{Projects, /}, {ProjectName}]`
- Feature cards use DaisyUI `card` classes
- Context items use DaisyUI card list with proper spacing
- Delete inline styles; use Tailwind utilities
- Badges use `StatusBadge` component

- [ ] **Step 2: Migrate project settings**

Rewrite `web/templates/project_settings.templ` using `PageShell`, `Card`, `FormGroup`, DaisyUI form controls.

Key changes:
- Use `PageShell` with breadcrumbs: `[{Projects, /}, {ProjectName, /projects/:id}, {Settings}]`
- Each settings section (Memory, Language, Company URLs, Storytelling) in a separate `Card`
- Form inputs use `input input-bordered`, textareas use `textarea textarea-bordered`
- Select uses `select select-bordered`
- Saved alert uses `alert alert-success`
- Framework descriptions use DaisyUI card styling

- [ ] **Step 3: Verify and commit**

```bash
make restart
```

Check project overview and settings pages. Then:

```bash
git add web/templates/project.templ web/templates/project_settings.templ
git commit -m "feat: migrate project overview and settings pages to DaisyUI"
```

---

### Task 7: Migrate Profile and Storytelling Pages

**Files:**
- Modify: `web/templates/profile.templ`
- Modify: `web/templates/storytelling.templ`

- [ ] **Step 1: Migrate profile page**

Rewrite `web/templates/profile.templ` using `PageShell`, `Card`, `StatusBadge`.

Key changes:
- Profile overview: cards list with section badges (Done/Empty/Locked)
- Profile section chat page: use Alpine `streamingChat` component via `x-data` instead of relying on `initProfileSectionChat` from app.js
- Chat messages area and form use DaisyUI classes
- Remove the inline script reliance — the chat component handles everything

- [ ] **Step 2: Migrate storytelling page**

Rewrite `web/templates/storytelling.templ` using `PageShell`, DaisyUI `radio` inputs, `collapse` components.

Key changes:
- Framework cards use DaisyUI `card` with `border-primary` when selected
- Radio inputs use DaisyUI `radio radio-primary`
- Details/summary replaced with DaisyUI `collapse collapse-arrow`
- Saved alert uses `alert alert-success`

- [ ] **Step 3: Verify and commit**

```bash
make restart
```

Check profile and storytelling pages. Then:

```bash
git add web/templates/profile.templ web/templates/storytelling.templ
git commit -m "feat: migrate profile and storytelling pages to DaisyUI"
```

---

### Task 8: Migrate Brainstorm and Context Pages

**Files:**
- Modify: `web/templates/brainstorm.templ`
- Modify: `web/templates/context.templ`

- [ ] **Step 1: Migrate brainstorm pages**

Rewrite `web/templates/brainstorm.templ`:

Key changes:
- Chat list page: `PageShell`, DaisyUI card list
- Chat page: Use Alpine `streamingChat` component via `x-data` attribute
- **Delete the inline `<script>` block** (lines 89-228) — all behavior now handled by the Alpine component
- Chat messages styled with DaisyUI `chat chat-start`/`chat-end` bubble pattern
- Thinking details use DaisyUI `collapse`

- [ ] **Step 2: Migrate context pages**

Rewrite `web/templates/context.templ`:

Key changes:
- Context new page: `PageShell`, DaisyUI form
- Context item page: Use Alpine `streamingChat` component with `onSave` callback
- Saved content card uses DaisyUI `Card` component
- Chat area uses same DaisyUI chat bubble pattern

- [ ] **Step 3: Verify and commit**

```bash
make restart
```

Test brainstorm chat (send message, verify streaming works) and context chat. Then:

```bash
git add web/templates/brainstorm.templ web/templates/context.templ
git commit -m "feat: migrate brainstorm and context pages to DaisyUI"
```

---

### Task 9: Migrate Templates Manager Page

**Files:**
- Modify: `web/templates/templates_mgr.templ`

- [ ] **Step 1: Migrate templates manager**

Rewrite `web/templates/templates_mgr.templ` using `PageShell`, `Card`, `FormGroup`, DaisyUI form controls.

Key changes:
- Add template form in a `Card` with DaisyUI inputs
- Select uses `select select-bordered`
- Template list uses DaisyUI card list
- HTML preview uses DaisyUI `mockup-code` or `bg-base-200` pre block
- Delete button uses `btn btn-error btn-xs`

- [ ] **Step 2: Verify and commit**

```bash
make restart
```

Check templates page. Then:

```bash
git add web/templates/templates_mgr.templ
git commit -m "feat: migrate templates manager page to DaisyUI"
```

---

### Task 10: Migrate Pipeline Pages

**Files:**
- Modify: `web/templates/pipeline.templ`

This is the most important page. Keep the same structure but restyle with DaisyUI and add the step progress indicator.

- [ ] **Step 1: Migrate pipeline list page**

Rewrite the `PipelineListPage` component in `pipeline.templ`:

Key changes:
- `PageShell` with breadcrumbs: `[{Projects, /}, {ProjectName, /projects/:id}, {Pipeline}]`
- Topic form uses DaisyUI `Card`, `FormGroup`, `input input-bordered`, `SubmitButton`
- Run cards use DaisyUI `card` with `StatusBadge`

- [ ] **Step 2: Migrate production board page**

Rewrite the `ProductionBoardPage` component:

Key changes:
- `PageShell` with breadcrumbs: `[{Projects, /}, {ProjectName, /projects/:id}, {Pipeline, /projects/:id/pipeline}, {Topic}]`
- **Add step progress indicator**: DaisyUI `steps` component at the top showing all 6 steps with status-based classes (`step-primary` for completed, `step-warning` for running, default for pending)
- Topic/brief section uses `CollapsibleCard`
- Step cards keep `data-step-id`, `data-status`, `data-tool-calls` attributes for JS compatibility
- Step cards styled with DaisyUI card + status-colored left border via `border-l-4` + border color classes
- Tool pills area uses `badge badge-sm` components
- Thinking ticker keeps monospace + auto-scroll (Tailwind: `font-mono text-xs max-h-24 overflow-y-auto`)
- Content piece cards use DaisyUI card with status-colored left border
- Action buttons use DaisyUI `btn` variants (`btn-success`, `btn-error`, `btn-ghost`)
- Abandon/Delete buttons use DaisyUI `btn btn-error`
- Use Alpine `pipelineBoard` component via `x-data` on the page wrapper
- Keep `ContentEditPage` as a DaisyUI form card

- [ ] **Step 3: Verify pipeline functionality end-to-end**

```bash
make restart
```

Critical checks:
1. Create a new pipeline run — form works
2. Run pipeline — steps stream correctly with tool pills and thinking ticker
3. Step outputs render correctly after completion
4. Content piece appears after writer step
5. Approve/reject/improve/proofread buttons work
6. Step progress indicator shows correct states

- [ ] **Step 4: Commit**

```bash
git add web/templates/pipeline.templ
git commit -m "feat: migrate pipeline pages to DaisyUI with step progress indicator"
```

---

### Task 11: Remove Old CSS and JS, Final Cleanup

**Files:**
- Delete: `web/static/style.css`
- Delete: `web/static/app.js`
- Rename: `web/static/js/app-new.js` → loaded as entry point
- Modify: `web/templates/layout.templ` (remove, now replaced by PageShell)
- Modify: `web/templates/components/layout.templ` (finalize JS loading)

- [ ] **Step 1: Remove old style.css**

```bash
rm web/static/style.css
```

- [ ] **Step 2: Remove old app.js**

```bash
rm web/static/app.js
```

- [ ] **Step 3: Update layout component to load final JS**

Update `web/templates/components/layout.templ` to load the new JS structure. Remove references to old `app.js` and `style.css`:

```html
<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
<script src="/static/js/renderers/markdown.js"></script>
<script src="/static/js/renderers/step-output.js"></script>
<script src="/static/js/renderers/content-body.js"></script>
<script src="/static/js/alpine-components/chat.js"></script>
<script src="/static/js/alpine-components/pipeline.js"></script>
<script src="/static/js/alpine-components/content-piece.js"></script>
<script src="/static/js/app-new.js"></script>
<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
```

Note: Alpine must load AFTER components are registered (defer ensures this).

- [ ] **Step 4: Delete old layout.templ if no pages still reference it**

Check if any page still uses `@Layout(...)`. If all pages have been migrated to `@components.PageShell(...)`, delete the old `Layout` function from `web/templates/layout.templ` (or delete the file entirely if empty).

- [ ] **Step 5: Full build and test**

```bash
make build && go test ./...
```

Expected: SUCCESS

- [ ] **Step 6: Full smoke test**

```bash
make restart
```

Test every page:
1. Dashboard — project grid
2. New project — form submission
3. Project overview — feature cards, context items
4. Settings — model inputs, save
5. Project settings — memory, URLs, framework
6. Profile — cards, section chat with streaming
7. Brainstorm — chat list, chat with streaming
8. Context — new item, chat with save
9. Storytelling — framework selection
10. Templates — add/delete templates
11. Pipeline list — create run
12. Pipeline board — run all steps, approve/reject content

- [ ] **Step 7: Commit**

```bash
git add -A
git commit -m "chore: remove old CSS and JS, finalize DaisyUI migration"
```
