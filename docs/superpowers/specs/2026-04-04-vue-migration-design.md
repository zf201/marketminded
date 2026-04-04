# Vue.js Frontend Migration Design

## Overview

Migrate the MarketMinded frontend from server-rendered Go templ templates + Alpine.js to a Vue 3 Single Page Application. The Go backend remains but shifts from returning HTML to serving a JSON API. The app continues to deploy as a single binary via `embed.FS`.

## Motivation

- The app is heading toward SaaS — auth flows, multi-tenant dashboards, billing UI, and onboarding wizards will strain the templ + Alpine approach.
- Migrating now with ~15 pages is far easier than migrating later with 50+.
- Vue's component model, reactive state, and ecosystem (Pinia, Vue Router, Composition API) are purpose-built for application UIs.
- Current Alpine components are growing in complexity with duplicated SSE/chat logic across 5 separate files.

## Technology Choices

| Layer | Choice |
|-------|--------|
| Framework | Vue 3 (Composition API) |
| Language | TypeScript |
| State management | Pinia |
| Routing | Vue Router |
| Build tool | Vite |
| Styling | Tailwind CSS + DaisyUI (carried over) |
| Testing | Vitest |
| Deployment | Go `embed.FS` — single binary |

## Migration Strategy

**Big bang, page-mirror with targeted improvements.**

Build the complete Vue frontend in a `frontend/` directory. Convert all Go handlers from HTML rendering to JSON responses. Swap over when complete. No incremental coexistence of two frontends.

Mirror the current page structure 1:1 — each templ page becomes a Vue view. Fix obvious pain points during migration (unify chat components, clean up content renderer) but don't redesign flows.

## Architecture

### System Diagram

```
Browser (Vue 3 SPA)
├── Vue Router (~16 routes)
├── Pinia Stores (5 stores)
└── Composables (useSSE, useApi, useChat, useContentRenderer)
    │
    ▼ JSON (fetch)          ▼ SSE (EventSource)
    │
Go Backend (Single Binary)
├── /api/*          — JSON endpoints (converted from HTML handlers)
├── /api/*/stream   — SSE endpoints (unchanged)
├── embed.FS        — serves Vue dist/, SPA fallback to index.html
├── internal/       — pipeline, store, prompt, tools (unchanged)
└── SQLite          — all data access (unchanged)
```

### Dev vs Production

- **Development:** Vite dev server (port 5173) proxies `/api/*` to Go (port 8080). HMR for Vue, manual restart for Go.
- **Production:** `make build` runs Vite build → `frontend/dist/`, then `go build` embeds it. Single binary serves everything.

## Routing & Views

### Layouts (2)

- **AppLayout** — top navbar, breadcrumbs. Used by dashboard, global settings, project creation.
- **ProjectLayout** — extends AppLayout with project navigation tabs and chat drawer FAB. Used by all `/projects/:id/*` routes.

### Route Map (16 views)

| Route | View | Current Template |
|-------|------|-----------------|
| `/` | DashboardView | dashboard.templ |
| `/settings` | SettingsView | settings.templ |
| `/projects/new` | ProjectNewView | project_new.templ |
| `/projects/:id` | ProjectOverview | project.templ |
| `/projects/:id/brainstorm` | BrainstormView | brainstorm.templ |
| `/projects/:id/brainstorm/:chatId` | BrainstormChatView | brainstorm.templ |
| `/projects/:id/pipeline` | PipelineListView | pipeline.templ |
| `/projects/:id/pipeline/:runId` | PipelineBoardView | pipeline.templ |
| `/projects/:id/content/:pieceId` | ContentPieceView | (inline in pipeline) |
| `/projects/:id/profile` | ProfileView | profile.templ |
| `/projects/:id/context` | ContextView | context.templ |
| `/projects/:id/context/:itemId` | ContextItemView | context.templ |
| `/projects/:id/context-memory` | ContextMemoryView | context_memory.templ |
| `/projects/:id/storytelling` | StorytellingView | storytelling.templ |
| `/projects/:id/settings` | ProjectSettingsView | project_settings.templ |

Profile sub-sections (audience personas, voice & tone) are handled within ProfileView via tabs and modals — no separate routes needed.

## State Management

### Pinia Stores (5)

| Store | State | Purpose |
|-------|-------|---------|
| `useProjectsStore` | project list, current project | CRUD projects, load/select |
| `usePipelineStore` | runs, steps, pieces | Pipeline execution, step streaming state |
| `useProfileStore` | sections, personas, voice profiles | Profile data, generation state |
| `useChatStore` | conversations, messages | Brainstorm + context chat, drawer state |
| `useSettingsStore` | global + project settings | Model, temperature, language |

### Composables (4)

| Composable | Purpose |
|------------|---------|
| `useSSE` | Wraps EventSource — connect, parse events, auto-cleanup on unmount. Used by chat, pipeline, profile generation. |
| `useApi` | Typed fetch wrapper — `GET /api/...` → typed response. Error handling, loading state. |
| `useChat` | Chat logic built on useSSE — send message, stream response, thinking indicators, tool pills. |
| `useContentRenderer` | Replaces content-body.js — takes content type + JSON, returns structured data for Vue components. |

### Improvement Over Current State

Currently there are 5 scattered Alpine components + a vanilla JS chat drawer, all managing SSE independently. In Vue:
- `useSSE` is one composable shared everywhere
- `useChat` builds on it for chat-specific logic
- The chat drawer shares `useChatStore` with the brainstorm page — no duplicate state

## Components (12)

| Component | Purpose |
|-----------|---------|
| ChatDrawer | Floating chat panel (Teleport to body), shares useChatStore |
| ChatMessage | Single message bubble with role, content, thinking block |
| StreamingChat | Full chat interface — message list, input, streaming state |
| PipelineStepCard | Step card with status badge, streaming output, tool indicators |
| ContentCard | Content piece card with type badge, preview, approve/reject actions |
| ContentRenderer | Renders content by type (blog, social, video script, carousel) |
| ProfileSection | Collapsible profile section with edit, generate, version history |
| PersonaCard | Audience persona card with edit/delete |
| VoiceToneCard | Voice & tone profile card with edit/delete |
| MarkdownContent | Renders markdown with expand/collapse |
| ToolIndicator | Shows active tool calls (web_search, fetch_url) during streaming |
| ThinkingBlock | Expandable AI thinking/reasoning display |

## API Layer

### REST Conventions

| Method | Pattern | Example |
|--------|---------|---------|
| GET | `/api/{resource}` | `GET /api/projects` |
| GET | `/api/{resource}/{id}` | `GET /api/projects/5` |
| POST | `/api/{resource}` | `POST /api/projects` (create) |
| PUT | `/api/{resource}/{id}` | `PUT /api/projects/5` (update) |
| DELETE | `/api/{resource}/{id}` | `DELETE /api/projects/5` |
| GET | `/api/.../stream` | SSE endpoints (unchanged) |

### Endpoint Groups

| Group | Endpoints | Migration Notes |
|-------|-----------|-----------------|
| Projects | CRUD + list | Form POST → redirect becomes JSON |
| Brainstorm | list chats, get messages, post message, stream | Already partially JSON (`list-json`, `messages-json`) |
| Pipeline | list runs, get run, create run, stream step, stream piece, approve/reject | SSE streams unchanged |
| Profile | get sections, update section, generate (SSE) | Section CRUD partly JSON already |
| Audience | list/create/update/delete personas, get/update context, generate (SSE) | Already JSON |
| Voice & Tone | get/update profile, get/update context, generate (SSE) | Already JSON |
| Context | list items, get item, create/update, chat stream | Needs conversion |
| Storytelling | get/update framework | Simple conversion |
| Settings | get/update global, get/update project | Simple conversion |

SSE endpoints remain unchanged — they already stream JSON events over EventSource.

## TypeScript Types

Three type files:

- **`types/models.ts`** — Domain models matching Go structs: `Project`, `Pipeline`, `PipelineStep`, `ContentPiece`, `ProfileSection`, `AudiencePersona`, `VoiceToneProfile`, `BrainstormChat`, `ContextItem`, `Settings`.
- **`types/api.ts`** — API response wrappers and request payloads.
- **`types/events.ts`** — SSE event types: `ChunkEvent`, `ThinkingEvent`, `ToolStartEvent`, `ToolResultEvent`, `DoneEvent`, `ErrorEvent`, `ProposalEvent`, `ContentWrittenEvent`.

## Directory Structure

### Frontend (new)

```
frontend/
├── index.html
├── vite.config.ts
├── tsconfig.json
├── tailwind.config.js        ← moved from root
├── package.json               ← moved from root
└── src/
    ├── main.ts
    ├── App.vue
    ├── router/index.ts
    ├── stores/                (5 files)
    ├── composables/           (4 files)
    ├── layouts/               (2 files)
    ├── views/                 (16 files)
    ├── components/            (12 files)
    └── types/                 (3 files)
```

### Backend Changes

- **Removed:** `web/templates/` (all .templ and _templ.go), `web/static/` (JS, CSS)
- **Modified:** All `web/handlers/*.go` — return JSON instead of rendering templ
- **Added:** `web/handlers/spa.go` — serves Vue `dist/` via `embed.FS` with SPA fallback
- **Modified:** `cmd/server/main.go` — new `/api/` routing prefix
- **Unchanged:** All `internal/` packages (pipeline, store, prompt, tools, sse, applog), migrations

## Build & Dev Workflow

### Makefile Targets

| Target | What it does |
|--------|-------------|
| `make dev` | Vite dev server + Go server concurrently |
| `make build` | Vite build + Go build → single binary |
| `make test` | `go test ./...` (backend) |
| `make test-frontend` | `vitest run` (frontend) |

Removed targets: `make css` (Vite handles Tailwind), `make generate` (no more templ).

## Testing Strategy

- **Backend:** Existing Go tests continue to work. Update handler tests for JSON responses.
- **Frontend:** Vitest for unit testing composables, stores, and utility functions.
- **E2E:** Out of scope for the migration. Add later.

## What Does NOT Change

- Pipeline orchestrator and step runners
- Prompt builder
- Tool registry
- Store layer (all SQL queries)
- SSE streaming package
- App logging
- Database migrations
- AI/LLM integration logic
