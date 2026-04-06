# Layout Overhaul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the DaisyUI-based layout with a professional neutral-dark design using pure Tailwind, featuring a persistent sidebar for project pages and polished dashboard cards.

**Architecture:** Remove DaisyUI dependency entirely. Define a minimal custom component layer in `input.css` using `@apply` for buttons, badges, inputs, and cards. Rebuild the layout shells in `layout.templ` — one for non-project pages (top bar only) and one for project pages (sidebar + content). Then migrate each page template to the new shells and Tailwind classes.

**Tech Stack:** Go templ, Tailwind CSS 3, Alpine.js (existing)

---

## File Map

| File | Action | Responsibility |
|------|--------|---------------|
| `tailwind.config.js` | Modify | Remove DaisyUI plugin, add custom sidebar color |
| `package.json` | Modify | Remove daisyui devDependency |
| `web/static/input.css` | Rewrite | Base styles, custom component classes |
| `web/templates/components/layout.templ` | Rewrite | PageShell (top bar), ProjectPageShell (sidebar), shared head/nav |
| `web/templates/components/card.templ` | Modify | Remove DaisyUI classes, use Tailwind |
| `web/templates/components/form.templ` | Modify | Remove DaisyUI classes, use Tailwind |
| `web/templates/components/badge.templ` | Modify | Map statuses to new badge classes |
| `web/templates/dashboard.templ` | Modify | Use new PageShell, restyle cards |
| `web/templates/project.templ` | Modify | Use new ProjectPageShell with activePage |
| `web/templates/profile.templ` | Modify | Use new ProjectPageShell, replace DaisyUI classes |
| `web/templates/pipeline.templ` | Modify | Use new ProjectPageShell, replace steps/cards/badges |
| `web/templates/brainstorm.templ` | Modify | Use new ProjectPageShell, replace DaisyUI classes |
| `web/templates/context.templ` | Modify | Use new ProjectPageShell, replace DaisyUI classes |
| `web/templates/context_memory.templ` | Modify | Use new ProjectPageShell, replace DaisyUI classes |
| `web/templates/settings.templ` | Modify | Use new PageShell, replace DaisyUI classes |
| `web/templates/project_settings.templ` | Modify | Use new ProjectPageShell, replace DaisyUI classes |
| `web/templates/storytelling.templ` | Modify | Use new ProjectPageShell, replace DaisyUI classes |
| `web/templates/project_new.templ` | Modify | Use new PageShell, replace DaisyUI classes |

---

### Task 1: Remove DaisyUI and set up Tailwind foundation

**Files:**
- Modify: `tailwind.config.js`
- Modify: `package.json`
- Rewrite: `web/static/input.css`

- [ ] **Step 1: Update tailwind.config.js — remove DaisyUI, add custom colors**

```js
/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    './web/templates/**/*.templ',
    './web/static/js/**/*.js',
  ],
  theme: {
    extend: {
      colors: {
        sidebar: '#0a0a0f',
        surface: '#111118',
      },
    },
  },
  plugins: [],
}
```

- [ ] **Step 2: Remove daisyui from package.json devDependencies**

Remove the `"daisyui": "^4.12.24"` line from `devDependencies`.

- [ ] **Step 3: Run `npm uninstall daisyui`**

```bash
npm uninstall daisyui
```

- [ ] **Step 4: Rewrite `web/static/input.css` with base styles and custom components**

```css
@tailwind base;
@tailwind components;
@tailwind utilities;

@layer base {
  body {
    @apply bg-zinc-950 text-zinc-100 antialiased;
  }

  /* Form element defaults */
  ::selection {
    @apply bg-zinc-700 text-zinc-100;
  }
}

@layer components {
  /* Buttons */
  .btn {
    @apply px-4 py-2 rounded-lg font-medium text-sm transition-colors inline-flex items-center justify-center gap-2 cursor-pointer;
  }
  .btn-primary {
    @apply bg-zinc-100 text-zinc-900 hover:bg-white;
  }
  .btn-secondary {
    @apply bg-zinc-800 text-zinc-300 hover:bg-zinc-700;
  }
  .btn-ghost {
    @apply text-zinc-400 hover:text-zinc-100 hover:bg-zinc-800;
  }
  .btn-danger {
    @apply bg-red-500/10 text-red-400 hover:bg-red-500/20;
  }
  .btn-success {
    @apply bg-green-500/10 text-green-400 hover:bg-green-500/20;
  }
  .btn-sm {
    @apply px-3 py-1.5 text-xs;
  }
  .btn-xs {
    @apply px-2 py-1 text-xs;
  }

  /* Badges */
  .badge {
    @apply px-2 py-0.5 text-xs font-medium rounded-full inline-flex items-center;
  }
  .badge-success {
    @apply bg-green-500/10 text-green-400;
  }
  .badge-warning {
    @apply bg-yellow-500/10 text-yellow-400;
  }
  .badge-error {
    @apply bg-red-500/10 text-red-400;
  }
  .badge-info {
    @apply bg-blue-500/10 text-blue-400;
  }
  .badge-ghost {
    @apply bg-zinc-800 text-zinc-500;
  }
  .badge-outline {
    @apply border border-zinc-700 text-zinc-400 bg-transparent;
  }

  /* Form inputs */
  .input {
    @apply w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-sm text-zinc-100 placeholder-zinc-600 focus:border-zinc-600 focus:outline-none transition-colors;
  }
  .textarea {
    @apply w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-sm text-zinc-100 placeholder-zinc-600 focus:border-zinc-600 focus:outline-none transition-colors;
  }
  .select {
    @apply w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2 text-sm text-zinc-100 focus:border-zinc-600 focus:outline-none transition-colors appearance-none;
  }

  /* Cards */
  .card {
    @apply bg-zinc-900 border border-zinc-800 rounded-xl;
  }

  /* Alerts */
  .alert-success {
    @apply bg-green-500/10 text-green-400 rounded-lg px-4 py-3 text-sm;
  }
  .alert-error {
    @apply bg-red-500/10 text-red-400 rounded-lg px-4 py-3 text-sm;
  }

  /* Modal backdrop */
  .modal-backdrop {
    @apply fixed inset-0 bg-black/60 z-40;
  }
}
```

- [ ] **Step 5: Build CSS to verify no errors**

```bash
npx tailwindcss -i web/static/input.css -o web/static/output.css
```

Expected: Builds successfully with no errors. Warnings about browserslist are ok.

- [ ] **Step 6: Commit**

```bash
git add tailwind.config.js package.json package-lock.json web/static/input.css
git commit -m "feat: remove DaisyUI, set up pure Tailwind foundation with custom component classes"
```

---

### Task 2: Rebuild layout shells (PageShell + ProjectPageShell with sidebar)

**Files:**
- Rewrite: `web/templates/components/layout.templ`

This is the core architectural change. The `ProjectPageShell` gains a sidebar and an `activePage` parameter to highlight the current nav item. The `PageShell` gets a simple top bar.

- [ ] **Step 1: Rewrite `layout.templ` with new shells**

```go
package components

import "fmt"

type Breadcrumb struct {
	Label string
	URL   string
}

templ pageHead(title string) {
	<meta charset="UTF-8"/>
	<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
	<title>{ title } - MarketMinded</title>
	<link rel="stylesheet" href="/static/output.css"/>
	<script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
	<script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
	<script src="/static/js/renderers/markdown.js"></script>
	<script src="/static/js/renderers/step-output.js"></script>
	<script src="/static/js/renderers/content-body.js"></script>
	<script src="/static/js/alpine-components/chat.js"></script>
	<script src="/static/js/alpine-components/pipeline.js"></script>
	<script src="/static/js/alpine-components/content-piece.js"></script>
	<script src="/static/js/chat-drawer.js"></script>
	<script src="/static/app.js" defer></script>
}

templ topBar() {
	<header class="h-14 border-b border-zinc-800 flex items-center justify-between px-6">
		<a href="/" class="text-sm font-semibold text-zinc-100 hover:text-white transition-colors">MarketMinded</a>
		<a href="/settings" class="text-sm text-zinc-500 hover:text-zinc-300 transition-colors">Settings</a>
	</header>
}

templ pageBreadcrumbs(breadcrumbs []Breadcrumb) {
	if len(breadcrumbs) > 0 {
		<nav class="flex items-center gap-1.5 text-xs text-zinc-600 mb-6">
			for i, bc := range breadcrumbs {
				if i > 0 {
					<span>/</span>
				}
				if bc.URL != "" {
					<a href={ templ.SafeURL(bc.URL) } class="hover:text-zinc-400 transition-colors">{ bc.Label }</a>
				} else {
					<span class="text-zinc-400">{ bc.Label }</span>
				}
			}
		</nav>
	}
}

// PageShell is for non-project pages (dashboard, global settings, new project).
templ PageShell(title string, breadcrumbs []Breadcrumb) {
	<!DOCTYPE html>
	<html lang="en">
	<head>
		@pageHead(title)
	</head>
	<body class="min-h-screen bg-zinc-950">
		@topBar()
		<main class="max-w-5xl mx-auto px-6 py-8">
			@pageBreadcrumbs(breadcrumbs)
			{ children... }
		</main>
	</body>
	</html>
}

func sidebarLinkClass(current, target string) string {
	if current == target {
		return "flex items-center gap-3 px-3 py-2 rounded-lg text-sm font-medium text-zinc-100 bg-zinc-800/80"
	}
	return "flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-zinc-500 hover:text-zinc-300 hover:bg-zinc-800/50 transition-colors"
}

// ProjectPageShell is for project pages — includes sidebar and floating chat button.
templ ProjectPageShell(title string, breadcrumbs []Breadcrumb, projectID int64, projectName string, activePage string) {
	<!DOCTYPE html>
	<html lang="en">
	<head>
		@pageHead(title)
	</head>
	<body class="min-h-screen bg-zinc-950" x-data="{ sidebarOpen: false }">
		<div class="flex h-screen overflow-hidden">
			<!-- Mobile sidebar backdrop -->
			<div
				x-show="sidebarOpen"
				x-transition:enter="transition-opacity ease-out duration-200"
				x-transition:enter-start="opacity-0"
				x-transition:enter-end="opacity-100"
				x-transition:leave="transition-opacity ease-in duration-150"
				x-transition:leave-start="opacity-100"
				x-transition:leave-end="opacity-0"
				class="fixed inset-0 bg-black/60 z-30 md:hidden"
				@click="sidebarOpen = false"
			></div>

			<!-- Sidebar -->
			<aside
				class="fixed md:static inset-y-0 left-0 z-40 w-60 bg-sidebar border-r border-zinc-800/50 flex flex-col transform transition-transform duration-200 md:translate-x-0"
				:class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
			>
				<!-- Sidebar header -->
				<div class="h-14 flex items-center px-5 border-b border-zinc-800/50">
					<a href="/" class="text-sm font-semibold text-zinc-100 hover:text-white transition-colors">MarketMinded</a>
				</div>

				<!-- Project name -->
				<div class="px-5 pt-5 pb-2">
					<p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-600">Project</p>
					<p class="text-sm font-medium text-zinc-300 mt-1 truncate">{ projectName }</p>
				</div>

				<!-- Nav links -->
				<nav class="flex-1 px-3 py-2 space-y-0.5 overflow-y-auto">
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d", projectID)) } class={ sidebarLinkClass(activePage, "overview") }>
						<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z"></path></svg>
						Overview
					</a>
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/profile", projectID)) } class={ sidebarLinkClass(activePage, "profile") }>
						<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"></path></svg>
						Profile
					</a>
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/pipeline", projectID)) } class={ sidebarLinkClass(activePage, "pipeline") }>
						<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 12h16.5m-16.5 3.75h16.5M3.75 19.5h16.5M5.625 4.5h12.75a1.875 1.875 0 010 3.75H5.625a1.875 1.875 0 010-3.75z"></path></svg>
						Pipeline
					</a>
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/context-memory", projectID)) } class={ sidebarLinkClass(activePage, "context-memory") }>
						<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"></path></svg>
						Context & Memory
					</a>
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/brainstorm", projectID)) } class={ sidebarLinkClass(activePage, "chat") }>
						<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"></path></svg>
						Chat
					</a>
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/storytelling", projectID)) } class={ sidebarLinkClass(activePage, "storytelling") }>
						<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25"></path></svg>
						Storytelling
					</a>
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/settings", projectID)) } class={ sidebarLinkClass(activePage, "settings") }>
						<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>
						Settings
					</a>
				</nav>

				<!-- Sidebar footer -->
				<div class="px-3 py-4 border-t border-zinc-800/50">
					<a href="/" class="flex items-center gap-3 px-3 py-2 rounded-lg text-sm text-zinc-600 hover:text-zinc-400 hover:bg-zinc-800/50 transition-colors">
						<svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 15L3 9m0 0l6-6M3 9h12a6 6 0 010 12h-3"></path></svg>
						All Projects
					</a>
				</div>
			</aside>

			<!-- Main area -->
			<div class="flex-1 flex flex-col overflow-hidden">
				<!-- Mobile top bar -->
				<header class="h-14 border-b border-zinc-800 flex items-center px-6 md:hidden">
					<button @click="sidebarOpen = !sidebarOpen" class="text-zinc-400 hover:text-zinc-100 mr-4">
						<svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5"></path></svg>
					</button>
					<span class="text-sm font-semibold text-zinc-100">{ projectName }</span>
				</header>

				<!-- Scrollable content -->
				<main class="flex-1 overflow-y-auto">
					<div class="max-w-5xl mx-auto px-6 py-8">
						@pageBreadcrumbs(breadcrumbs)
						{ children... }
					</div>
				</main>
			</div>
		</div>

		@chatDrawer(projectID)
	</body>
	</html>
}

templ chatDrawer(projectID int64) {
	<!-- Floating chat button -->
	<button
		id="chat-fab"
		class="btn btn-secondary shadow-lg fixed bottom-6 right-6 z-20 rounded-full w-12 h-12 p-0"
		onclick="window.chatDrawer && window.chatDrawer.open()"
	>
		<svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
			<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
		</svg>
	</button>

	<!-- Chat drawer overlay -->
	<div
		id="chat-drawer"
		class="fixed inset-0 z-50 hidden"
		data-project-id={ fmt.Sprintf("%d", projectID) }
	>
		<!-- Backdrop -->
		<div class="absolute inset-0 bg-black/60" onclick="window.chatDrawer && window.chatDrawer.close()"></div>

		<!-- Drawer panel -->
		<div class="absolute right-0 top-0 h-full w-[70%] max-w-2xl bg-zinc-900 border-l border-zinc-800 flex flex-col">
			<!-- Header -->
			<div class="flex items-center justify-between p-3 border-b border-zinc-800">
				<div class="flex items-center gap-2">
					<button id="chat-drawer-back" class="btn btn-secondary btn-xs" onclick="window.chatDrawer && window.chatDrawer.showList()">Back</button>
					<h3 class="font-semibold text-sm text-zinc-100" id="chat-drawer-title">Chats</h3>
				</div>
				<button class="btn btn-ghost btn-xs" onclick="window.chatDrawer && window.chatDrawer.close()">✕</button>
			</div>

			<!-- Chat list view -->
			<div id="chat-drawer-list" class="flex-1 overflow-y-auto p-3">
				<p class="text-zinc-600 text-sm">Loading chats...</p>
			</div>

			<!-- Active chat view -->
			<div id="chat-drawer-chat" class="flex-1 flex-col hidden">
				<!-- Messages -->
				<div id="chat-drawer-messages" class="flex-1 overflow-y-auto p-3 space-y-3"></div>

				<!-- Input -->
				<div class="border-t border-zinc-800 p-3">
					<div class="flex gap-2">
						<textarea
							id="chat-drawer-input"
							class="textarea flex-1 min-h-[40px]"
							placeholder="Type a message... (Cmd+Enter to send)"
							rows="2"
						></textarea>
						<button id="chat-drawer-send" class="btn btn-primary btn-sm self-end" onclick="window.chatDrawer && window.chatDrawer.send()">Send</button>
					</div>
				</div>
			</div>
		</div>
	</div>
}
```

- [ ] **Step 2: Verify templ generates without errors**

```bash
~/go/bin/templ generate ./web/templates/
```

Expected: All templates generate with 0 errors.

- [ ] **Step 3: Commit**

```bash
git add web/templates/components/layout.templ
git commit -m "feat: rebuild layout shells — sidebar for project pages, top bar for global pages"
```

**Important note for all subsequent tasks:** The `ProjectPageShell` signature changed — it now takes 5 arguments: `title`, `breadcrumbs`, `projectID`, `projectName`, `activePage`. Every template that calls `ProjectPageShell` must be updated. The `PageShell` signature is unchanged.

---

### Task 3: Update shared components (card, form, badge)

**Files:**
- Modify: `web/templates/components/card.templ`
- Modify: `web/templates/components/form.templ`
- Modify: `web/templates/components/badge.templ`

- [ ] **Step 1: Rewrite `card.templ`**

```go
package components

templ Card(title string) {
	<div class="card">
		<div class="p-5">
			if title != "" {
				<h3 class="text-sm font-semibold text-zinc-100 mb-3">{ title }</h3>
			}
			{ children... }
		</div>
	</div>
}

templ CollapsibleCard(title string, defaultOpen bool) {
	<div class="card" x-data={ collapseInit(defaultOpen) }>
		<div class="px-5 py-3 cursor-pointer flex items-center gap-2" @click="open = !open">
			<button class="btn btn-ghost btn-xs" x-text="open ? '−' : '+'"></button>
			<span class="text-sm font-semibold text-zinc-100">{ title }</span>
		</div>
		<div x-show="open" x-collapse>
			<div class="px-5 pb-4">
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

- [ ] **Step 2: Rewrite `form.templ`**

```go
package components

templ FormGroup(label string) {
	<div class="mb-3">
		if label != "" {
			<label class="block text-sm font-medium text-zinc-300 mb-1.5">
				{ label }
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

- [ ] **Step 3: Rewrite `badge.templ`**

```go
package components

func badgeClass(status string) string {
	switch status {
	case "pending":
		return "badge badge-ghost"
	case "running":
		return "badge badge-warning animate-pulse"
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
		return "badge badge-ghost"
	}
}

templ StatusBadge(status string) {
	<span class={ badgeClass(status) }>{ status }</span>
}
```

- [ ] **Step 4: Verify templ generates**

```bash
~/go/bin/templ generate ./web/templates/
```

Expected: 0 errors.

- [ ] **Step 5: Commit**

```bash
git add web/templates/components/card.templ web/templates/components/form.templ web/templates/components/badge.templ
git commit -m "feat: restyle shared components (card, form, badge) with pure Tailwind"
```

---

### Task 4: Migrate dashboard and non-project pages

**Files:**
- Modify: `web/templates/dashboard.templ`
- Modify: `web/templates/settings.templ`
- Modify: `web/templates/project_new.templ`

These pages use `PageShell` (unchanged signature), so they just need DaisyUI class replacements.

- [ ] **Step 1: Rewrite `dashboard.templ`**

```go
package templates

import (
	"fmt"
	"github.com/zanfridau/marketminded/web/templates/components"
)

templ Dashboard(projects []DashboardProject) {
	@components.PageShell("Dashboard", []components.Breadcrumb{{Label: "Projects"}}) {
		<div class="flex items-center justify-between mb-8">
			<h1 class="text-xl font-semibold text-zinc-100">Projects</h1>
			<a href="/projects/new" class="btn btn-primary">New Project</a>
		</div>
		if len(projects) == 0 {
			<p class="text-zinc-500">No projects yet. Create one to get started.</p>
		}
		<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
			for _, p := range projects {
				<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d", p.ID)) } class="card hover:border-zinc-700 transition-colors group">
					<div class="p-5">
						<h3 class="text-sm font-semibold text-zinc-100 group-hover:text-white">{ p.Name }</h3>
						if p.Description != "" {
							<p class="text-zinc-500 text-sm mt-1.5 line-clamp-2">{ p.Description }</p>
						}
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

- [ ] **Step 2: Rewrite `settings.templ`**

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
	@components.PageShell("Settings", []components.Breadcrumb{{Label: "Projects", URL: "/"}, {Label: "Settings"}}) {
		<div class="flex items-center justify-between mb-8">
			<h1 class="text-xl font-semibold text-zinc-100">Settings</h1>
			<a href="/" class="btn btn-ghost">Back</a>
		</div>
		if data.Saved {
			<div class="alert-success mb-4">Settings saved.</div>
		}
		<form method="POST" action="/settings">
			@components.Card("AI Models") {
				<p class="text-zinc-500 text-xs mb-4">Choose which OpenRouter models to use. Leave blank to use defaults from environment variables.</p>
				@components.FormGroup("Content Research Model (research, fact-checking, brand enrichment, tone analysis)") {
					<input type="text" id="model_content" name="model_content" value={ data.ModelContent } placeholder="e.g. x-ai/grok-4.1-fast" class="input"/>
				}
				@components.FormGroup("Copywriting Model (cornerstone article writing)") {
					<input type="text" id="model_copywriting" name="model_copywriting" value={ data.ModelCopywriting } placeholder="e.g. anthropic/claude-sonnet-4-20250514" class="input"/>
				}
				@components.FormGroup("Ideation Model (brainstorming, idea generation)") {
					<input type="text" id="model_ideation" name="model_ideation" value={ data.ModelIdeation } placeholder="e.g. anthropic/claude-sonnet-4-20250514" class="input"/>
				}
				@components.FormGroup("Proofread Model (should be fast + cheap, e.g. openai/gpt-4o-mini)") {
					<input type="text" id="model_proofread" name="model_proofread" value={ data.ModelProofread } placeholder="openai/gpt-4o-mini" class="input"/>
				}
				@components.FormGroup("Temperature (0.0 = precise, 1.0 = creative, default: 0.3 for profile, 0.7 for brainstorm)") {
					<input type="text" id="temperature" name="temperature" value={ data.Temperature } placeholder="0.3" class="input"/>
				}
				<p class="text-zinc-600 text-xs mt-2">
					Popular models: x-ai/grok-4.1-fast, anthropic/claude-sonnet-4-20250514, openai/gpt-4o, google/gemini-2.0-flash-001
				</p>
				<div class="mt-4">
					@components.SubmitButton("Save Settings")
				</div>
			}
		</form>
	}
}
```

- [ ] **Step 3: Rewrite `project_new.templ`**

```go
package templates

import "github.com/zanfridau/marketminded/web/templates/components"

templ ProjectNew() {
	@components.PageShell("New Project", []components.Breadcrumb{{Label: "Projects", URL: "/"}, {Label: "New Project"}}) {
		<h1 class="text-xl font-semibold text-zinc-100 mb-8">New Project</h1>
		<form method="POST" action="/projects">
			@components.Card("") {
				@components.FormGroup("Project Name") {
					<input type="text" id="name" name="name" required placeholder="e.g. Acme Corp" class="input"/>
				}
				@components.FormGroup("Description") {
					<textarea id="description" name="description" placeholder="Brief description of the client/project" class="textarea"></textarea>
				}
				<div class="mt-4">
					@components.SubmitButton("Create Project")
				</div>
			}
		</form>
	}
}
```

- [ ] **Step 4: Verify templ generates**

```bash
~/go/bin/templ generate ./web/templates/
```

Expected: 0 errors.

- [ ] **Step 5: Commit**

```bash
git add web/templates/dashboard.templ web/templates/settings.templ web/templates/project_new.templ
git commit -m "feat: migrate dashboard, settings, and new project pages to pure Tailwind"
```

---

### Task 5: Migrate project overview page

**Files:**
- Modify: `web/templates/project.templ`

The signature of `ProjectPageShell` changed — now requires `projectName` and `activePage` parameters.

- [ ] **Step 1: Rewrite `project.templ`**

```go
package templates

import "fmt"
import "github.com/zanfridau/marketminded/web/templates/components"

type ProjectDetail struct {
	ID          int64
	Name        string
	Description string
	HasProfile  bool
	RunCount    int
	Language    string
	HasContext  bool
	HasMemory   bool
}

templ ProjectOverview(p ProjectDetail) {
	@components.ProjectPageShell(p.Name, []components.Breadcrumb{{Label: "Projects", URL: "/"}, {Label: p.Name}}, p.ID, p.Name, "overview") {
		<div class="mb-8">
			<h1 class="text-xl font-semibold text-zinc-100">{ p.Name }</h1>
			if p.Description != "" {
				<p class="text-zinc-500 text-sm mt-1.5">{ p.Description }</p>
			}
		</div>

		<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
			<div class="card">
				<div class="p-5">
					<h3 class="text-sm font-semibold text-zinc-100">Client Profile</h3>
					<p class="mt-2">
						if p.HasProfile {
							@components.StatusBadge("approved")
						} else {
							@components.StatusBadge("draft")
						}
					</p>
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/profile", p.ID)) } class="btn btn-secondary btn-sm mt-3">Manage Profile</a>
				</div>
			</div>

			<div class="card">
				<div class="p-5">
					<h3 class="text-sm font-semibold text-zinc-100">Context & Memories</h3>
					<p class="mt-2">
						if p.HasContext || p.HasMemory {
							@components.StatusBadge("approved")
						} else {
							@components.StatusBadge("draft")
						}
					</p>
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/context-memory", p.ID)) } class="btn btn-secondary btn-sm mt-3">Manage</a>
				</div>
			</div>

			<div class="card">
				<div class="p-5">
					<h3 class="text-sm font-semibold text-zinc-100">Settings</h3>
					<p class="mt-2">
						if p.Language != "" {
							<span class="text-zinc-400 text-sm">Language: <strong class="text-zinc-200">{ p.Language }</strong></span>
						} else {
							@components.StatusBadge("draft")
						}
					</p>
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/settings", p.ID)) } class="btn btn-secondary btn-sm mt-3">Manage Settings</a>
				</div>
			</div>

			<div class="card">
				<div class="p-5">
					<h3 class="text-sm font-semibold text-zinc-100">Content Pipeline</h3>
					<p class="text-zinc-500 text-sm mt-2">{ fmt.Sprintf("%d pipeline runs", p.RunCount) }</p>
					<a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/pipeline", p.ID)) } class="btn btn-secondary btn-sm mt-3">Pipeline Runs</a>
				</div>
			</div>
		</div>
	}
}
```

- [ ] **Step 2: Verify templ generates**

```bash
~/go/bin/templ generate ./web/templates/
```

Expected: 0 errors.

- [ ] **Step 3: Commit**

```bash
git add web/templates/project.templ
git commit -m "feat: migrate project overview to sidebar layout"
```

---

### Task 6: Migrate profile page

**Files:**
- Modify: `web/templates/profile.templ`

This is the largest template. Key changes: update `ProjectPageShell` call, replace all DaisyUI classes. The structure and Alpine.js interactions stay the same.

- [ ] **Step 1: Update `ProfilePage` template call and restyle all classes**

Update the `ProjectPageShell` call to include `projectName` and `activePage`:

```go
@components.ProjectPageShell(data.ProjectName+" - Profile", []components.Breadcrumb{
    {Label: "Projects", URL: "/"},
    {Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
    {Label: "Profile"},
}, data.ProjectID, data.ProjectName, "profile") {
```

Then do a systematic find-and-replace across the entire file for these DaisyUI classes:

| Find | Replace |
|------|---------|
| `class="card bg-base-100 shadow-sm border border-base-300 mb-3"` | `class="card mb-3"` |
| `class="card bg-base-200 border border-base-300 mt-3"` | `class="bg-zinc-800/50 border border-zinc-800 rounded-lg mt-3"` |
| `class="card bg-base-200 border border-base-300 mt-2"` | `class="bg-zinc-800/50 border border-zinc-800 rounded-lg mt-2"` |
| `card-body` | `p-5` (on outer cards) or `p-3` (on inner subcards) |
| `text-base-content/60` | `text-zinc-500` |
| `text-base-content/70` | `text-zinc-400` |
| `text-base-content/50` | `text-zinc-600` |
| `text-base-content/40` | `text-zinc-700` |
| `badge badge-ghost` | `badge badge-ghost` (unchanged — our custom class) |
| `badge badge-success badge-sm` | `badge badge-success text-[10px]` |
| `badge badge-outline badge-sm` | `badge badge-outline text-[10px]` |
| `btn btn-secondary btn-sm` | `btn btn-secondary btn-sm` (unchanged) |
| `btn btn-ghost btn-sm` | `btn btn-ghost btn-sm` (unchanged) |
| `btn btn-ghost btn-xs` | `btn btn-ghost btn-xs` (unchanged) |
| `btn btn-ghost btn-xs text-error` | `btn btn-ghost btn-xs text-red-400` |
| `bg-base-200` | `bg-zinc-800/50` |
| `bg-gradient-to-t from-base-200 to-transparent` | `bg-gradient-to-t from-zinc-900 to-transparent` |
| `bg-gradient-to-t from-base-100 to-transparent` | `bg-gradient-to-t from-zinc-900 to-transparent` |
| `text-error` | `text-red-400` |
| `textarea textarea-bordered` | `textarea` |
| `input input-bordered input-sm` | `input text-xs py-1.5` |
| `input input-bordered` | `input` |
| `collapse bg-base-200 rounded-lg p-2 mb-1` | `bg-zinc-800/50 rounded-lg p-2 mb-1` |

For all modals (`<dialog>` elements), replace:

| Find | Replace |
|------|---------|
| `class="modal"` | `class="modal"` (keep — we style via CSS or inline) |
| `class="modal-box w-11/12 max-w-2xl"` | `class="bg-zinc-900 border border-zinc-800 rounded-xl shadow-2xl w-[90%] max-w-2xl p-6 mx-auto mt-[10vh]"` |
| `class="modal-box w-11/12 max-w-3xl"` | `class="bg-zinc-900 border border-zinc-800 rounded-xl shadow-2xl w-[90%] max-w-3xl p-6 mx-auto mt-[10vh]"` |
| `class="modal-action"` | `class="flex justify-end gap-2 mt-6"` |
| `class="modal-backdrop"` | `class="fixed inset-0 bg-black/60"` |

Also add this to `input.css` in the `@layer components` block for the `<dialog>` styling:

```css
dialog.modal {
  @apply fixed inset-0 z-50 flex items-start justify-center bg-black/60 p-4;
}
dialog.modal::backdrop {
  @apply bg-black/60;
}
dialog.modal[open] {
  @apply flex;
}
```

- [ ] **Step 2: Verify templ generates**

```bash
~/go/bin/templ generate ./web/templates/
```

Expected: 0 errors.

- [ ] **Step 3: Commit**

```bash
git add web/templates/profile.templ web/static/input.css
git commit -m "feat: migrate profile page to sidebar layout and pure Tailwind"
```

---

### Task 7: Migrate pipeline pages

**Files:**
- Modify: `web/templates/pipeline.templ`

Key changes: update both `PipelineListPage` and `ProductionBoardPage` to use new `ProjectPageShell`, replace DaisyUI steps with custom step indicator, replace all DaisyUI classes.

- [ ] **Step 1: Update `PipelineListPage`**

Update the `ProjectPageShell` call:

```go
@components.ProjectPageShell(data.ProjectName+" - Pipeline", []components.Breadcrumb{
    {Label: "Projects", URL: "/projects"},
    {Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
    {Label: "Pipeline"},
}, data.ProjectID, data.ProjectName, "pipeline") {
```

Replace DaisyUI classes:

| Find | Replace |
|------|---------|
| `btn btn-ghost` | `btn btn-ghost` (unchanged) |
| `btn btn-primary` | `btn btn-primary` (unchanged) |
| `input input-bordered` | `input` |
| `card bg-base-100 shadow-sm border border-base-300 no-underline text-inherit block hover:shadow-md transition-shadow` | `card hover:border-zinc-700 transition-colors block` |
| `card-body p-4 flex-row items-center justify-between` | `p-4 flex items-center justify-between` |
| `text-base-content/60` | `text-zinc-500` |

- [ ] **Step 2: Update `ProductionBoardPage`**

Update the `ProjectPageShell` call:

```go
@components.ProjectPageShell(fmt.Sprintf("Pipeline: %s", data.Topic), []components.Breadcrumb{
    {Label: "Projects", URL: "/projects"},
    {Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
    {Label: "Pipeline", URL: fmt.Sprintf("/projects/%d/pipeline", data.ProjectID)},
    {Label: data.Topic},
}, data.ProjectID, data.ProjectName, "pipeline") {
```

Replace the DaisyUI step indicator. Change `stepProgressClass` function:

```go
func stepProgressClass(status string) string {
	base := "flex flex-col items-center gap-1 text-xs"
	switch status {
	case "completed":
		return base + " text-green-400"
	case "running":
		return base + " text-yellow-400"
	case "failed":
		return base + " text-red-400"
	default:
		return base + " text-zinc-600"
	}
}
```

Replace the `<ul class="steps steps-horizontal w-full mb-6">` block with:

```html
<div class="flex items-center justify-between mb-6 px-2">
    for i, step := range data.Steps {
        <div class={ stepProgressClass(step.Status) }>
            <div class={
                "w-8 h-8 rounded-full flex items-center justify-center text-xs font-medium border",
                templ.KV("bg-green-500/10 border-green-500/30", step.Status == "completed"),
                templ.KV("bg-yellow-500/10 border-yellow-500/30 animate-pulse", step.Status == "running"),
                templ.KV("bg-red-500/10 border-red-500/30", step.Status == "failed"),
                templ.KV("bg-zinc-800 border-zinc-700", step.Status != "completed" && step.Status != "running" && step.Status != "failed"),
            }>
                { stepIcon(step.Status) }
            </div>
            <span class="text-[10px] whitespace-nowrap">@stepTypeLabel(step.StepType)</span>
        </div>
        if i < len(data.Steps) - 1 {
            <div class={
                "flex-1 h-px mx-1",
                templ.KV("bg-green-500/30", step.Status == "completed"),
                templ.KV("bg-zinc-800", step.Status != "completed"),
            }></div>
        }
    }
</div>
```

Replace all remaining DaisyUI classes following the same pattern as Task 6:
- `card bg-base-100 shadow-sm border border-base-300` → `card`
- `card-body p-4` → `p-4`
- `text-base-content/60` → `text-zinc-500`
- `text-error` → `text-red-400`
- `btn btn-error btn-sm` → `btn btn-danger btn-sm`
- `btn btn-success btn-sm` → `btn btn-success btn-sm`
- `btn btn-primary` → `btn btn-primary`
- `bg-error/5 border border-error/20` → `bg-red-500/5 border border-red-500/20`
- `border-l-base-300` → `border-l-zinc-700`
- `border-l-warning` → `border-l-yellow-500`
- `border-l-info` → `border-l-blue-500`
- `border-l-success` → `border-l-green-500`
- `border-l-error` → `border-l-red-500`

Update `pieceBorderClass` function:

```go
func pieceBorderClass(status string) string {
	switch status {
	case "pending":
		return "border-l-4 border-l-zinc-700 opacity-75 border-dashed"
	case "generating":
		return "border-l-4 border-l-yellow-500"
	case "draft":
		return "border-l-4 border-l-blue-500"
	case "approved":
		return "border-l-4 border-l-green-500"
	case "rejected":
		return "border-l-4 border-l-red-500"
	default:
		return "border-l-4 border-l-zinc-700"
	}
}
```

- [ ] **Step 3: Update `ContentEditPage`**

Update the `ProjectPageShell` call:

```go
@components.ProjectPageShell("Edit Content", []components.Breadcrumb{
    {Label: "Projects", URL: "/projects"},
    {Label: "Content"},
}, data.ProjectID, "", "pipeline") {
```

Replace DaisyUI classes: `badge badge-outline` stays, `input input-bordered` → `input`, `textarea textarea-bordered` → `textarea`, `btn btn-ghost` stays, `btn btn-primary` stays, `btn btn-success` → `btn btn-success`.

- [ ] **Step 4: Verify templ generates**

```bash
~/go/bin/templ generate ./web/templates/
```

Expected: 0 errors.

- [ ] **Step 5: Commit**

```bash
git add web/templates/pipeline.templ
git commit -m "feat: migrate pipeline pages to sidebar layout and pure Tailwind"
```

---

### Task 8: Migrate remaining project pages

**Files:**
- Modify: `web/templates/brainstorm.templ`
- Modify: `web/templates/context.templ`
- Modify: `web/templates/context_memory.templ`
- Modify: `web/templates/project_settings.templ`
- Modify: `web/templates/storytelling.templ`

All follow the same pattern: update `ProjectPageShell` call, replace DaisyUI classes.

- [ ] **Step 1: Update `brainstorm.templ`**

Update both `BrainstormListPage` and `BrainstormChatPage`:

```go
// BrainstormListPage
@components.ProjectPageShell(data.ProjectName+" - Chat", []components.Breadcrumb{
    {Label: "Projects", URL: "/"},
    {Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
    {Label: "Chat"},
}, data.ProjectID, data.ProjectName, "chat") {

// BrainstormChatPage
@components.ProjectPageShell(data.ChatTitle, []components.Breadcrumb{
    {Label: "Projects", URL: "/"},
    {Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
    {Label: "Chat", URL: fmt.Sprintf("/projects/%d/brainstorm", data.ProjectID)},
    {Label: data.ChatTitle},
}, data.ProjectID, data.ProjectName, "chat") {
```

Replace DaisyUI classes:
- `btn btn-ghost btn-sm` → `btn btn-ghost btn-sm` (unchanged)
- `btn btn-primary btn-sm` → `btn btn-primary btn-sm` (unchanged)
- `card bg-base-100 shadow-sm border border-base-300 mb-2 hover:border-primary transition-colors block no-underline text-inherit` → `card mb-2 hover:border-zinc-700 transition-colors block`
- `card-body py-3` → `p-4`
- `text-base-content/60` → `text-zinc-500`
- `textarea textarea-bordered` → `textarea`
- `collapse bg-base-200 rounded-lg p-2 mb-1` �� `bg-zinc-800/50 rounded-lg p-2 mb-1`
- `text-base-content/60 cursor-pointer` → `text-zinc-500 cursor-pointer`

- [ ] **Step 2: Update `context.templ`**

Update both `ContextNewPage` and `ContextItemPage`:

```go
// ContextNewPage
@components.ProjectPageShell("Add Context", []components.Breadcrumb{
    {Label: "Projects", URL: "/"},
    {Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
    {Label: "Add Context"},
}, data.ProjectID, data.ProjectName, "context-memory") {

// ContextItemPage
@components.ProjectPageShell(data.Title, []components.Breadcrumb{
    {Label: "Projects", URL: "/"},
    {Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
    {Label: data.Title},
}, data.ProjectID, data.ProjectName, "context-memory") {
```

Replace DaisyUI classes:
- `btn btn-ghost btn-sm` → `btn btn-ghost btn-sm`
- `btn btn-error btn-sm` → `btn btn-danger btn-sm`
- `btn btn-primary` → `btn btn-primary`
- `btn btn-success` → `btn btn-success`
- `input input-bordered` → `input`
- `textarea textarea-bordered` → `textarea`
- `text-base-content/60` → `text-zinc-500`

- [ ] **Step 3: Update `context_memory.templ`**

```go
@components.ProjectPageShell(data.ProjectName+" - Context & Memories", []components.Breadcrumb{
    {Label: "Projects", URL: "/"},
    {Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
    {Label: "Context & Memories"},
}, data.ProjectID, data.ProjectName, "context-memory") {
```

Replace DaisyUI classes:
- `alert alert-success` → `alert-success`
- `textarea textarea-bordered` → `textarea`
- `text-base-content/60` → `text-zinc-500`

- [ ] **Step 4: Update `project_settings.templ`**

```go
@components.ProjectPageShell(data.ProjectName + " - Settings", []components.Breadcrumb{
    {Label: "Projects", URL: "/"},
    {Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
    {Label: "Settings"},
}, data.ProjectID, data.ProjectName, "settings") {
```

Replace DaisyUI classes:
- `btn btn-ghost` → `btn btn-ghost`
- `alert alert-success` → `alert-success`
- `input input-bordered` → `input`
- `select select-bordered` → `select`
- `text-base-content/60` → `text-zinc-500`
- `bg-base-200 rounded-lg p-3 mb-2 text-sm` → `bg-zinc-800/50 rounded-lg p-3 mb-2 text-sm`

- [ ] **Step 5: Update `storytelling.templ`**

```go
@components.ProjectPageShell(data.ProjectName+" - Storytelling Framework", []components.Breadcrumb{
    {Label: "Projects", URL: "/"},
    {Label: data.ProjectName, URL: fmt.Sprintf("/projects/%d", data.ProjectID)},
    {Label: "Storytelling"},
}, data.ProjectID, data.ProjectName, "storytelling") {
```

Replace DaisyUI classes:
- `alert alert-success` → `alert-success`
- `card bg-base-100 shadow-sm border mb-3` → `card mb-3`
- `border-primary` → `border-zinc-500`
- `border-base-300` → `border-zinc-800`
- `card-body` → `p-5`
- `radio radio-primary` → `accent-zinc-400`
- `text-base-content/60` → `text-zinc-500`
- `badge badge-ghost` → `badge badge-ghost`
- `btn btn-ghost btn-xs` → `btn btn-ghost btn-xs`
- `border-t border-base-300` → `border-t border-zinc-800`

- [ ] **Step 6: Verify templ generates**

```bash
~/go/bin/templ generate ./web/templates/
```

Expected: 0 errors.

- [ ] **Step 7: Commit**

```bash
git add web/templates/brainstorm.templ web/templates/context.templ web/templates/context_memory.templ web/templates/project_settings.templ web/templates/storytelling.templ
git commit -m "feat: migrate remaining project pages to sidebar layout and pure Tailwind"
```

---

### Task 9: Update handler calls for new ProjectPageShell signature

**Files:**
- Check all handlers that render templates using `ProjectPageShell`

The `ProjectPageShell` signature changed from `(title, breadcrumbs, projectID)` to `(title, breadcrumbs, projectID, projectName, activePage)`. Since templates call `ProjectPageShell` directly (not handlers), the handler code doesn't need changes — the templates were already updated in Tasks 5-8. But some templates (like `context.templ` with `ContextNewPage`) may need the `ProjectName` field added to their data structs.

- [ ] **Step 1: Check if all template data structs have ProjectName**

Review each data struct. These already have `ProjectName`:
- `PipelineListData` ✓
- `ProductionBoardData` ✓ (has `ProjectName`)
- `BrainstormListData` ✓
- `BrainstormChatData` ✓
- `ContextNewData` ✓
- `ContextItemData` ✓
- `ContextMemoryData` ✓
- `ProfilePageData` ✓
- `ProjectSettingsData` ✓
- `StorytellingData` ✓

`ContentEditData` does NOT have `ProjectName`. It only has `ProjectID`. Add it:

In `pipeline.templ`, add `ProjectName string` to the `ContentEditData` struct:

```go
type ContentEditData struct {
	ProjectID   int64
	ProjectName string
	Piece       ContentPieceView
}
```

Then check the handler that creates `ContentEditData` and ensure it populates `ProjectName`. Find and update in the pipeline handler.

- [ ] **Step 2: Find and update the handler that creates ContentEditData**

```bash
grep -rn "ContentEditData" web/handlers/
```

Update the handler to populate `ProjectName` from the project record.

- [ ] **Step 3: Build the Go server to verify everything compiles**

```bash
go build -o server ./cmd/server/
```

Expected: Builds successfully with 0 errors.

- [ ] **Step 4: Commit if any handler changes were needed**

```bash
git add -A
git commit -m "fix: populate ProjectName in ContentEditData for sidebar layout"
```

---

### Task 10: Build, run, and verify

- [ ] **Step 1: Run full build chain**

```bash
npx tailwindcss -i web/static/input.css -o web/static/output.css && ~/go/bin/templ generate ./web/templates/ && go build -o server ./cmd/server/
```

Expected: All three steps succeed with 0 errors.

- [ ] **Step 2: Start the server and visually verify**

```bash
make restart
```

Check in browser:
1. Dashboard (/) — top bar, project cards grid, no sidebar
2. Project overview (/projects/1) — sidebar visible, "Overview" highlighted
3. Profile page — sidebar with "Profile" highlighted
4. Pipeline page — sidebar with "Pipeline" highlighted
5. Settings page — sidebar with "Settings" highlighted
6. Mobile (resize to < 768px) — sidebar hidden, hamburger toggles it

- [ ] **Step 3: Fix any visual issues found during verification**

Common issues to check:
- Text colors too dark/light
- Card borders not visible enough
- Modals not styling correctly (backdrop, positioning)
- Chat drawer styling
- Radio buttons on storytelling page

- [ ] **Step 4: Final commit with any fixes**

```bash
git add -A
git commit -m "fix: visual polish after layout overhaul verification"
```
