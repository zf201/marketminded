# Vue.js Frontend Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the templ + Alpine.js frontend with a Vue 3 SPA while converting Go handlers from HTML rendering to JSON API endpoints.

**Architecture:** Vue 3 SPA in `frontend/` built with Vite, served by Go via `embed.FS`. All handlers move under `/api/` prefix and return JSON. SSE streaming endpoints are unchanged. TypeScript types generated from Go structs via tygo.

**Tech Stack:** Vue 3, Composition API, TypeScript, Pinia, Vue Router, Vite, Tailwind CSS, DaisyUI, tygo, Vitest

---

## File Structure

### New Files (frontend/)

```
frontend/
├── index.html                          # Vite entry HTML
├── vite.config.ts                      # Vite config with API proxy
├── tsconfig.json                       # TypeScript config
├── tsconfig.node.json                  # Node TypeScript config
├── tailwind.config.js                  # Tailwind + DaisyUI (moved from root)
├── postcss.config.js                   # PostCSS for Tailwind
├── package.json                        # Vue dependencies
├── src/
│   ├── main.ts                         # App entry point
│   ├── App.vue                         # Root component
│   ├── style.css                       # Tailwind imports
│   ├── router/index.ts                 # Vue Router config
│   ├── stores/
│   │   ├── projects.ts                 # Project CRUD + current project
│   │   ├── pipeline.ts                 # Runs, steps, pieces
│   │   ├── profile.ts                  # Sections, personas, voice profiles
│   │   ├── chat.ts                     # Brainstorm + context chat, drawer
│   │   └── settings.ts                 # Global + project settings
│   ├── composables/
│   │   ├── useApi.ts                   # Typed fetch wrapper
│   │   ├── useSSE.ts                   # EventSource wrapper with auto-cleanup
│   │   ├── useChat.ts                  # Chat logic on useSSE
│   │   └── useContentRenderer.ts       # Content type → structured data
│   ├── layouts/
│   │   ├── AppLayout.vue               # Navbar + breadcrumbs
│   │   └── ProjectLayout.vue           # Project nav + chat drawer FAB
│   ├── views/
│   │   ├── DashboardView.vue
│   │   ├── SettingsView.vue
│   │   ├── ProjectNewView.vue
│   │   ├── ProjectOverview.vue
│   │   ├── BrainstormView.vue
│   │   ├── BrainstormChatView.vue
│   │   ├── PipelineListView.vue
│   │   ├── PipelineBoardView.vue
│   │   ├── ContentPieceView.vue
│   │   ├── ProfileView.vue
│   │   ├── ContextView.vue
│   │   ├── ContextItemView.vue
│   │   ├── ContextMemoryView.vue
│   │   ├── StorytellingView.vue
│   │   └── ProjectSettingsView.vue
│   ├── components/
│   │   ├── ChatDrawer.vue              # Floating chat panel (Teleport)
│   │   ├── ChatMessage.vue             # Message bubble
│   │   ├── StreamingChat.vue           # Full chat interface
│   │   ├── PipelineStepCard.vue        # Step card with streaming
│   │   ├── ContentCard.vue             # Content piece with actions
│   │   ├── ContentRenderer.vue         # Renders by content type
│   │   ├── ProfileSection.vue          # Section card with edit/generate
│   │   ├── PersonaCard.vue             # Audience persona card
│   │   ├── VoiceToneCard.vue           # Voice & tone card
│   │   ├── MarkdownContent.vue         # Markdown with expand/collapse
│   │   ├── ToolIndicator.vue           # Active tool call display
│   │   └── ThinkingBlock.vue           # AI thinking/reasoning
│   └── types/
│       ├── generated.ts                # tygo output (DO NOT EDIT)
│       └── index.ts                    # Re-exports + frontend-only types
```

### New Files (backend)

```
tygo.yaml                               # tygo config
web/handlers/spa.go                      # embed.FS SPA handler
web/handlers/json.go                     # JSON response helpers
```

### Modified Files (backend)

```
cmd/server/main.go                       # /api/ prefix routing, SPA fallback
web/handlers/dashboard.go                # HTML → JSON
web/handlers/project.go                  # HTML → JSON
web/handlers/brainstorm.go               # HTML → JSON (keep SSE)
web/handlers/pipeline.go                 # HTML → JSON (keep SSE)
web/handlers/profile.go                  # HTML → JSON (keep SSE)
web/handlers/audience.go                 # Minor: already JSON
web/handlers/voice_tone.go               # Minor: already JSON
web/handlers/context.go                  # HTML → JSON (keep SSE)
web/handlers/storytelling.go             # HTML → JSON
web/handlers/settings.go                 # HTML → JSON
web/handlers/project_settings.go         # HTML → JSON
Makefile                                 # New targets: dev, types, build
```

### Deleted Files

```
web/templates/                           # All .templ and _templ.go files
web/static/                              # All JS, CSS, input.css
package.json                             # Moved to frontend/
package-lock.json                        # Moved to frontend/
tailwind.config.js                       # Moved to frontend/
```

---

## Task 1: Scaffold Vue + Vite Project

**Files:**
- Create: `frontend/package.json`
- Create: `frontend/vite.config.ts`
- Create: `frontend/tsconfig.json`
- Create: `frontend/tsconfig.node.json`
- Create: `frontend/index.html`
- Create: `frontend/src/main.ts`
- Create: `frontend/src/App.vue`
- Create: `frontend/src/style.css`
- Create: `frontend/postcss.config.js`
- Create: `frontend/tailwind.config.js`
- Create: `frontend/.gitignore`

- [ ] **Step 1: Create frontend directory and initialize Vite + Vue + TypeScript**

```bash
cd /Users/zanfridau/CODE/AI/marketminded
npm create vite@latest frontend -- --template vue-ts
```

- [ ] **Step 2: Install dependencies**

```bash
cd frontend
npm install vue-router@4 pinia
npm install -D tailwindcss@3 postcss autoprefixer daisyui@4 @types/node
npx tailwindcss init -p
```

- [ ] **Step 3: Configure Tailwind + DaisyUI**

Replace `frontend/tailwind.config.js`:

```js
/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './index.html',
    './src/**/*.{vue,js,ts,jsx,tsx}',
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

- [ ] **Step 4: Set up Tailwind imports**

Replace `frontend/src/style.css`:

```css
@tailwind base;
@tailwind components;
@tailwind utilities;
```

- [ ] **Step 5: Configure Vite with API proxy**

Replace `frontend/vite.config.ts`:

```ts
import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { fileURLToPath, URL } from 'node:url'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
    },
  },
})
```

- [ ] **Step 6: Set up App.vue with router outlet**

Replace `frontend/src/App.vue`:

```vue
<script setup lang="ts">
import { RouterView } from 'vue-router'
</script>

<template>
  <RouterView />
</template>
```

- [ ] **Step 7: Set up main.ts with Pinia and Router**

Replace `frontend/src/main.ts`:

```ts
import { createApp } from 'vue'
import { createPinia } from 'pinia'
import App from './App.vue'
import router from './router'
import './style.css'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#app')
```

- [ ] **Step 8: Create minimal router**

Create `frontend/src/router/index.ts`:

```ts
import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: () => import('@/layouts/AppLayout.vue'),
      children: [
        { path: '', name: 'dashboard', component: () => import('@/views/DashboardView.vue') },
      ],
    },
  ],
})

export default router
```

- [ ] **Step 9: Create placeholder layout and view**

Create `frontend/src/layouts/AppLayout.vue`:

```vue
<template>
  <div class="min-h-screen bg-base-100" data-theme="business">
    <RouterView />
  </div>
</template>
```

Create `frontend/src/views/DashboardView.vue`:

```vue
<template>
  <div class="p-8">
    <h1 class="text-2xl font-bold">Dashboard</h1>
    <p class="text-base-content/60">Vue app is working.</p>
  </div>
</template>
```

- [ ] **Step 10: Create frontend .gitignore**

Create `frontend/.gitignore`:

```
node_modules
dist
```

- [ ] **Step 11: Verify the app runs**

```bash
cd frontend && npm run dev
```

Open `http://localhost:5173` — should see "Dashboard" with DaisyUI's business theme (dark background).

- [ ] **Step 12: Commit**

```bash
git add frontend/
git commit -m "feat: scaffold Vue 3 + Vite + TypeScript + Tailwind + DaisyUI frontend"
```

---

## Task 2: Set Up tygo Type Generation

**Files:**
- Create: `tygo.yaml`
- Create: `web/handlers/types.go`
- Create: `frontend/src/types/generated.ts` (output)
- Create: `frontend/src/types/index.ts`

- [ ] **Step 1: Install tygo**

```bash
go install github.com/gzuidhof/tygo@latest
```

- [ ] **Step 2: Create API response/request types in a dedicated file**

The Go handler files currently use anonymous structs and inline maps for JSON responses. We need named types for tygo to pick up. Create `web/handlers/types.go`:

```go
package handlers

import "marketminded/internal/store"

// API response types — tygo generates TypeScript from these.

type ProjectResponse struct {
	ID          int64  `json:"id"`
	Name        string `json:"name"`
	Description string `json:"description"`
	CreatedAt   string `json:"created_at"`
}

type ProjectCreateRequest struct {
	Name        string `json:"name"`
	Description string `json:"description"`
}

type DashboardItem struct {
	ID          int64  `json:"id"`
	Name        string `json:"name"`
	Description string `json:"description"`
}

type ChatListItem struct {
	ID      int64  `json:"id"`
	Title   string `json:"title"`
	Preview string `json:"preview"`
}

type ChatMessage struct {
	Role     string `json:"role"`
	Content  string `json:"content"`
	Thinking string `json:"thinking,omitempty"`
}

type PipelineRunResponse struct {
	ID        int64  `json:"id"`
	ProjectID int64  `json:"project_id"`
	Topic     string `json:"topic"`
	Brief     string `json:"brief"`
	Plan      string `json:"plan"`
	Phase     string `json:"phase"`
	Status    string `json:"status"`
	CreatedAt string `json:"created_at"`
}

type PipelineStepResponse struct {
	ID        int64  `json:"id"`
	StepType  string `json:"step_type"`
	Status    string `json:"status"`
	Output    string `json:"output"`
	Thinking  string `json:"thinking"`
	ToolCalls string `json:"tool_calls"`
	SortOrder int    `json:"sort_order"`
}

type ContentPieceResponse struct {
	ID              int64  `json:"id"`
	Platform        string `json:"platform"`
	Format          string `json:"format"`
	Title           string `json:"title"`
	Body            string `json:"body"`
	Status          string `json:"status"`
	RejectionReason string `json:"rejection_reason,omitempty"`
	SortOrder       int    `json:"sort_order"`
}

type ProductionBoardResponse struct {
	Run    PipelineRunResponse    `json:"run"`
	Steps  []PipelineStepResponse `json:"steps"`
	Pieces []ContentPieceResponse `json:"pieces"`
}

type ProfileSectionResponse struct {
	Section    string           `json:"section"`
	Content    string           `json:"content"`
	SourceURLs []store.SourceURL `json:"source_urls"`
	UpdatedAt  string           `json:"updated_at"`
}

type ProfileSaveRequest struct {
	Content  string `json:"content"`
	URLGuide string `json:"url_guide,omitempty"`
}

type ContextURLsRequest struct {
	URLs  []store.SourceURL `json:"urls"`
	Notes string            `json:"notes"`
}

type ProfileVersionResponse struct {
	ID        int64  `json:"id"`
	Content   string `json:"content"`
	CreatedAt string `json:"created_at"`
}

type ContextItemResponse struct {
	ID        int64  `json:"id"`
	Title     string `json:"title"`
	Content   string `json:"content"`
	CreatedAt string `json:"created_at"`
	UpdatedAt string `json:"updated_at"`
}

type ContextItemCreateRequest struct {
	Title   string `json:"title"`
	Content string `json:"content"`
}

type SettingsResponse struct {
	ModelContent     string `json:"model_content"`
	ModelCopywriting string `json:"model_copywriting"`
	ModelIdeation    string `json:"model_ideation"`
	ModelProofread   string `json:"model_proofread"`
	Temperature      string `json:"temperature"`
}

type SettingsSaveRequest struct {
	ModelContent     string `json:"model_content"`
	ModelCopywriting string `json:"model_copywriting"`
	ModelIdeation    string `json:"model_ideation"`
	ModelProofread   string `json:"model_proofread"`
	Temperature      string `json:"temperature"`
}

type ProjectSettingsResponse struct {
	Language              string            `json:"language"`
	StorytellingFramework string            `json:"storytelling_framework"`
	Frameworks            []FrameworkOption  `json:"frameworks"`
}

type FrameworkOption struct {
	Key         string `json:"key"`
	Name        string `json:"name"`
	Description string `json:"description"`
}

type ProjectSettingsSaveRequest struct {
	Language              string `json:"language"`
	StorytellingFramework string `json:"storytelling_framework"`
}

// SSE event types — sent over EventSource streams.

type SSEChunkEvent struct {
	Type  string `json:"type"` // "chunk"
	Chunk string `json:"chunk"`
}

type SSEThinkingEvent struct {
	Type     string `json:"type"` // "thinking"
	Thinking string `json:"thinking"`
}

type SSEToolStartEvent struct {
	Type    string `json:"type"` // "tool_start"
	Tool    string `json:"tool"`
	Summary string `json:"summary"`
}

type SSEToolResultEvent struct {
	Type    string `json:"type"` // "tool_result"
	Tool    string `json:"tool"`
	Summary string `json:"summary"`
}

type SSEDoneEvent struct {
	Type string `json:"type"` // "done"
}

type SSEErrorEvent struct {
	Type  string `json:"type"` // "error"
	Error string `json:"error"`
}

type SSEProposalEvent struct {
	Type    string `json:"type"` // "proposal"
	Section string `json:"section"`
	Content string `json:"content"`
}

type SSEContentWrittenEvent struct {
	Type  string `json:"type"` // "content_written"
	Body  string `json:"body"`
	Title string `json:"title"`
}
```

- [ ] **Step 3: Create tygo config**

Create `tygo.yaml`:

```yaml
packages:
  - path: "marketminded/internal/store"
    type_mappings:
      "time.Time": "string"
      "sql.NullString": "string | null"
      "sql.NullInt64": "number | null"
    output_path: "frontend/src/types/generated_store.ts"
    exclude:
      - "Queries"
      - "DBTX"
  - path: "marketminded/web/handlers"
    type_mappings:
      "time.Time": "string"
      "store.SourceURL": "SourceURL"
    output_path: "frontend/src/types/generated_handlers.ts"
    include:
      - "*Response"
      - "*Request"
      - "*Item"
      - "FrameworkOption"
      - "SSE*"
```

- [ ] **Step 4: Run tygo and verify output**

```bash
tygo generate
```

Check that `frontend/src/types/generated_store.ts` and `frontend/src/types/generated_handlers.ts` exist and contain the expected interfaces.

- [ ] **Step 5: Create types index file**

Create `frontend/src/types/index.ts`:

```ts
export * from './generated_store'
export * from './generated_handlers'

// Frontend-only types

export type SSEEvent =
  | SSEChunkEvent
  | SSEThinkingEvent
  | SSEToolStartEvent
  | SSEToolResultEvent
  | SSEDoneEvent
  | SSEErrorEvent
  | SSEProposalEvent
  | SSEContentWrittenEvent

export type LoadingState = 'idle' | 'loading' | 'error' | 'success'
```

- [ ] **Step 6: Commit**

```bash
git add tygo.yaml web/handlers/types.go frontend/src/types/
git commit -m "feat: add tygo type generation from Go structs"
```

---

## Task 3: Build Core Composables

**Files:**
- Create: `frontend/src/composables/useApi.ts`
- Create: `frontend/src/composables/useSSE.ts`
- Test: `frontend/src/composables/__tests__/useApi.test.ts`
- Test: `frontend/src/composables/__tests__/useSSE.test.ts`

- [ ] **Step 1: Install Vitest**

```bash
cd frontend && npm install -D vitest @vue/test-utils happy-dom
```

Add to `frontend/vite.config.ts` inside `defineConfig`:

```ts
  test: {
    environment: 'happy-dom',
  },
```

- [ ] **Step 2: Write test for useApi**

Create `frontend/src/composables/__tests__/useApi.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useApi } from '../useApi'

describe('useApi', () => {
  const api = useApi()

  beforeEach(() => {
    vi.restoreAllMocks()
  })

  it('makes GET requests and returns typed data', async () => {
    const mockData = [{ id: 1, name: 'Test' }]
    vi.spyOn(global, 'fetch').mockResolvedValue({
      ok: true,
      json: () => Promise.resolve(mockData),
    } as Response)

    const result = await api.get<{ id: number; name: string }[]>('/api/projects')
    expect(result).toEqual(mockData)
    expect(fetch).toHaveBeenCalledWith('/api/projects', {
      method: 'GET',
      headers: { 'Content-Type': 'application/json' },
    })
  })

  it('makes POST requests with body', async () => {
    vi.spyOn(global, 'fetch').mockResolvedValue({
      ok: true,
      json: () => Promise.resolve({ id: 1 }),
    } as Response)

    await api.post('/api/projects', { name: 'New' })
    expect(fetch).toHaveBeenCalledWith('/api/projects', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: '{"name":"New"}',
    })
  })

  it('throws on non-OK responses', async () => {
    vi.spyOn(global, 'fetch').mockResolvedValue({
      ok: false,
      status: 404,
      text: () => Promise.resolve('not found'),
    } as Response)

    await expect(api.get('/api/missing')).rejects.toThrow('not found')
  })
})
```

- [ ] **Step 3: Run test to verify it fails**

```bash
cd frontend && npx vitest run src/composables/__tests__/useApi.test.ts
```

Expected: FAIL — module not found.

- [ ] **Step 4: Implement useApi**

Create `frontend/src/composables/useApi.ts`:

```ts
export class ApiError extends Error {
  constructor(
    public status: number,
    message: string,
  ) {
    super(message)
  }
}

export function useApi() {
  async function request<T>(method: string, url: string, body?: unknown): Promise<T> {
    const options: RequestInit = {
      method,
      headers: { 'Content-Type': 'application/json' },
    }
    if (body !== undefined) {
      options.body = JSON.stringify(body)
    }
    const res = await fetch(url, options)
    if (!res.ok) {
      const text = await res.text()
      throw new ApiError(res.status, text)
    }
    return res.json()
  }

  return {
    get: <T>(url: string) => request<T>('GET', url),
    post: <T>(url: string, body?: unknown) => request<T>('POST', url, body),
    put: <T>(url: string, body?: unknown) => request<T>('PUT', url, body),
    del: <T>(url: string) => request<T>('DELETE', url),
  }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
cd frontend && npx vitest run src/composables/__tests__/useApi.test.ts
```

Expected: PASS

- [ ] **Step 6: Write test for useSSE**

Create `frontend/src/composables/__tests__/useSSE.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest'
import { useSSE } from '../useSSE'
import { nextTick } from 'vue'

// Mock EventSource
class MockEventSource {
  onmessage: ((event: MessageEvent) => void) | null = null
  onerror: ((event: Event) => void) | null = null
  readyState = 0
  close = vi.fn()
  constructor(public url: string) {}
}

vi.stubGlobal('EventSource', MockEventSource)

describe('useSSE', () => {
  it('connects to URL and parses JSON events', () => {
    const { connect, events, isConnected } = useSSE()
    connect('/api/test/stream')

    expect(isConnected.value).toBe(true)
  })

  it('closes connection on disconnect', () => {
    const { connect, disconnect, isConnected } = useSSE()
    connect('/api/test/stream')
    disconnect()
    expect(isConnected.value).toBe(false)
  })
})
```

- [ ] **Step 7: Run test to verify it fails**

```bash
cd frontend && npx vitest run src/composables/__tests__/useSSE.test.ts
```

Expected: FAIL — module not found.

- [ ] **Step 8: Implement useSSE**

Create `frontend/src/composables/useSSE.ts`:

```ts
import { ref, onUnmounted } from 'vue'
import type { SSEEvent } from '@/types'

export function useSSE() {
  const events = ref<SSEEvent[]>([])
  const isConnected = ref(false)
  const error = ref<string | null>(null)
  let source: EventSource | null = null

  function connect(
    url: string,
    onEvent?: (event: SSEEvent) => void,
  ) {
    disconnect()
    source = new EventSource(url)
    isConnected.value = true

    source.onmessage = (msg) => {
      try {
        const parsed = JSON.parse(msg.data) as SSEEvent
        events.value.push(parsed)
        onEvent?.(parsed)

        if (parsed.type === 'done' || parsed.type === 'error') {
          disconnect()
        }
      } catch {
        // Non-JSON event, ignore
      }
    }

    source.onerror = () => {
      error.value = 'Connection lost'
      disconnect()
    }
  }

  function disconnect() {
    if (source) {
      source.close()
      source = null
    }
    isConnected.value = false
  }

  function reset() {
    events.value = []
    error.value = null
  }

  onUnmounted(disconnect)

  return { events, isConnected, error, connect, disconnect, reset }
}
```

- [ ] **Step 9: Run test to verify it passes**

```bash
cd frontend && npx vitest run src/composables/__tests__/useSSE.test.ts
```

Expected: PASS

- [ ] **Step 10: Commit**

```bash
git add frontend/src/composables/ frontend/vite.config.ts frontend/package.json frontend/package-lock.json
git commit -m "feat: add useApi and useSSE composables with tests"
```

---

## Task 4: Build useChat Composable

**Files:**
- Create: `frontend/src/composables/useChat.ts`

- [ ] **Step 1: Implement useChat**

Create `frontend/src/composables/useChat.ts`:

```ts
import { ref, computed } from 'vue'
import { useSSE } from './useSSE'
import { useApi } from './useApi'
import type { ChatMessage, SSEEvent } from '@/types'

export function useChat() {
  const api = useApi()
  const sse = useSSE()
  const messages = ref<ChatMessage[]>([])
  const streamingContent = ref('')
  const streamingThinking = ref('')
  const activeTools = ref<string[]>([])
  const isStreaming = computed(() => sse.isConnected.value)

  async function loadMessages(projectId: number, chatId: number) {
    messages.value = await api.get<ChatMessage[]>(
      `/api/projects/${projectId}/brainstorm/${chatId}/messages`,
    )
  }

  function sendMessage(projectId: number, chatId: number, content: string) {
    messages.value.push({ role: 'user', content })
    streamingContent.value = ''
    streamingThinking.value = ''
    activeTools.value = []

    api.post(`/api/projects/${projectId}/brainstorm/${chatId}/message`, { content })

    sse.connect(
      `/api/projects/${projectId}/brainstorm/${chatId}/stream`,
      handleEvent,
    )
  }

  function handleEvent(event: SSEEvent) {
    switch (event.type) {
      case 'chunk':
        streamingContent.value += event.chunk
        break
      case 'thinking':
        streamingThinking.value += event.thinking
        break
      case 'tool_start':
        activeTools.value.push(event.tool)
        break
      case 'tool_result':
        activeTools.value = activeTools.value.filter((t) => t !== event.tool)
        break
      case 'done':
        messages.value.push({
          role: 'assistant',
          content: streamingContent.value,
          thinking: streamingThinking.value || undefined,
        })
        streamingContent.value = ''
        streamingThinking.value = ''
        activeTools.value = []
        break
      case 'error':
        streamingContent.value = ''
        break
    }
  }

  return {
    messages,
    streamingContent,
    streamingThinking,
    activeTools,
    isStreaming,
    loadMessages,
    sendMessage,
  }
}
```

- [ ] **Step 2: Commit**

```bash
git add frontend/src/composables/useChat.ts
git commit -m "feat: add useChat composable for streaming chat"
```

---

## Task 5: Build Pinia Stores

**Files:**
- Create: `frontend/src/stores/projects.ts`
- Create: `frontend/src/stores/pipeline.ts`
- Create: `frontend/src/stores/profile.ts`
- Create: `frontend/src/stores/chat.ts`
- Create: `frontend/src/stores/settings.ts`

- [ ] **Step 1: Create projects store**

Create `frontend/src/stores/projects.ts`:

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import type { ProjectResponse, ProjectCreateRequest, DashboardItem } from '@/types'

export const useProjectsStore = defineStore('projects', () => {
  const api = useApi()
  const projects = ref<DashboardItem[]>([])
  const current = ref<ProjectResponse | null>(null)

  async function fetchAll() {
    projects.value = await api.get<DashboardItem[]>('/api/projects')
  }

  async function fetchOne(id: number) {
    current.value = await api.get<ProjectResponse>(`/api/projects/${id}`)
  }

  async function create(data: ProjectCreateRequest): Promise<ProjectResponse> {
    const project = await api.post<ProjectResponse>('/api/projects', data)
    await fetchAll()
    return project
  }

  async function update(id: number, data: Partial<ProjectCreateRequest>) {
    current.value = await api.put<ProjectResponse>(`/api/projects/${id}`, data)
  }

  async function remove(id: number) {
    await api.del(`/api/projects/${id}`)
    await fetchAll()
  }

  return { projects, current, fetchAll, fetchOne, create, update, remove }
})
```

- [ ] **Step 2: Create pipeline store**

Create `frontend/src/stores/pipeline.ts`:

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import type {
  PipelineRunResponse,
  ProductionBoardResponse,
  ContentPieceResponse,
} from '@/types'

export const usePipelineStore = defineStore('pipeline', () => {
  const api = useApi()
  const runs = ref<PipelineRunResponse[]>([])
  const currentBoard = ref<ProductionBoardResponse | null>(null)

  async function fetchRuns(projectId: number) {
    runs.value = await api.get<PipelineRunResponse[]>(
      `/api/projects/${projectId}/pipeline`,
    )
  }

  async function fetchBoard(projectId: number, runId: number) {
    currentBoard.value = await api.get<ProductionBoardResponse>(
      `/api/projects/${projectId}/pipeline/${runId}`,
    )
  }

  async function createRun(projectId: number, topic: string): Promise<PipelineRunResponse> {
    const run = await api.post<PipelineRunResponse>(
      `/api/projects/${projectId}/pipeline`,
      { topic },
    )
    await fetchRuns(projectId)
    return run
  }

  async function approvePiece(projectId: number, runId: number, pieceId: number) {
    await api.post(`/api/projects/${projectId}/pipeline/${runId}/pieces/${pieceId}/approve`)
    await fetchBoard(projectId, runId)
  }

  async function rejectPiece(
    projectId: number,
    runId: number,
    pieceId: number,
    reason: string,
  ) {
    await api.post(`/api/projects/${projectId}/pipeline/${runId}/pieces/${pieceId}/reject`, {
      reason,
    })
    await fetchBoard(projectId, runId)
  }

  return { runs, currentBoard, fetchRuns, fetchBoard, createRun, approvePiece, rejectPiece }
})
```

- [ ] **Step 3: Create profile store**

Create `frontend/src/stores/profile.ts`:

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import type {
  ProfileSectionResponse,
  ProfileSaveRequest,
  ProfileVersionResponse,
  AudiencePersona,
  VoiceToneProfile,
  SourceURL,
} from '@/types'

export const useProfileStore = defineStore('profile', () => {
  const api = useApi()
  const sections = ref<ProfileSectionResponse[]>([])
  const personas = ref<AudiencePersona[]>([])
  const voiceToneProfile = ref<VoiceToneProfile | null>(null)
  const versions = ref<ProfileVersionResponse[]>([])

  async function fetchSections(projectId: number) {
    sections.value = await api.get<ProfileSectionResponse[]>(
      `/api/projects/${projectId}/profile`,
    )
  }

  async function saveSection(projectId: number, section: string, data: ProfileSaveRequest) {
    await api.put(`/api/projects/${projectId}/profile/${section}`, data)
    await fetchSections(projectId)
  }

  async function fetchVersions(projectId: number, section: string) {
    versions.value = await api.get<ProfileVersionResponse[]>(
      `/api/projects/${projectId}/profile/${section}/versions`,
    )
  }

  async function fetchPersonas(projectId: number) {
    personas.value = await api.get<AudiencePersona[]>(
      `/api/projects/${projectId}/profile/audience/personas`,
    )
  }

  async function savePersona(projectId: number, persona: Partial<AudiencePersona>) {
    if (persona.id) {
      await api.put(`/api/projects/${projectId}/profile/audience/personas/${persona.id}`, persona)
    } else {
      await api.post(`/api/projects/${projectId}/profile/audience/personas`, persona)
    }
    await fetchPersonas(projectId)
  }

  async function deletePersona(projectId: number, personaId: number) {
    await api.del(`/api/projects/${projectId}/profile/audience/personas/${personaId}`)
    await fetchPersonas(projectId)
  }

  async function fetchVoiceTone(projectId: number) {
    voiceToneProfile.value = await api.get<VoiceToneProfile>(
      `/api/projects/${projectId}/profile/voice_and_tone/profile`,
    )
  }

  return {
    sections,
    personas,
    voiceToneProfile,
    versions,
    fetchSections,
    saveSection,
    fetchVersions,
    fetchPersonas,
    savePersona,
    deletePersona,
    fetchVoiceTone,
  }
})
```

- [ ] **Step 4: Create chat store**

Create `frontend/src/stores/chat.ts`:

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import type { ChatListItem } from '@/types'

export const useChatStore = defineStore('chat', () => {
  const api = useApi()
  const chats = ref<ChatListItem[]>([])
  const drawerOpen = ref(false)
  const drawerChatId = ref<number | null>(null)

  async function fetchChats(projectId: number) {
    chats.value = await api.get<ChatListItem[]>(
      `/api/projects/${projectId}/brainstorm`,
    )
  }

  async function createChat(projectId: number, message: string): Promise<number> {
    const result = await api.post<{ id: number }>(
      `/api/projects/${projectId}/brainstorm`,
      { message },
    )
    await fetchChats(projectId)
    return result.id
  }

  function openDrawer(chatId?: number) {
    drawerOpen.value = true
    if (chatId) drawerChatId.value = chatId
  }

  function closeDrawer() {
    drawerOpen.value = false
  }

  return { chats, drawerOpen, drawerChatId, fetchChats, createChat, openDrawer, closeDrawer }
})
```

- [ ] **Step 5: Create settings store**

Create `frontend/src/stores/settings.ts`:

```ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import { useApi } from '@/composables/useApi'
import type {
  SettingsResponse,
  SettingsSaveRequest,
  ProjectSettingsResponse,
  ProjectSettingsSaveRequest,
} from '@/types'

export const useSettingsStore = defineStore('settings', () => {
  const api = useApi()
  const global = ref<SettingsResponse | null>(null)
  const project = ref<ProjectSettingsResponse | null>(null)

  async function fetchGlobal() {
    global.value = await api.get<SettingsResponse>('/api/settings')
  }

  async function saveGlobal(data: SettingsSaveRequest) {
    await api.put('/api/settings', data)
    await fetchGlobal()
  }

  async function fetchProject(projectId: number) {
    project.value = await api.get<ProjectSettingsResponse>(
      `/api/projects/${projectId}/settings`,
    )
  }

  async function saveProject(projectId: number, data: ProjectSettingsSaveRequest) {
    await api.put(`/api/projects/${projectId}/settings`, data)
    await fetchProject(projectId)
  }

  return { global, project, fetchGlobal, saveGlobal, fetchProject, saveProject }
})
```

- [ ] **Step 6: Commit**

```bash
git add frontend/src/stores/
git commit -m "feat: add Pinia stores for projects, pipeline, profile, chat, settings"
```

---

## Task 6: Build Full Router

**Files:**
- Modify: `frontend/src/router/index.ts`

- [ ] **Step 1: Add all routes**

Replace `frontend/src/router/index.ts`:

```ts
import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: () => import('@/layouts/AppLayout.vue'),
      children: [
        {
          path: '',
          name: 'dashboard',
          component: () => import('@/views/DashboardView.vue'),
        },
        {
          path: 'settings',
          name: 'settings',
          component: () => import('@/views/SettingsView.vue'),
        },
        {
          path: 'projects/new',
          name: 'project-new',
          component: () => import('@/views/ProjectNewView.vue'),
        },
        {
          path: 'projects/:projectId',
          component: () => import('@/layouts/ProjectLayout.vue'),
          props: (route) => ({ projectId: Number(route.params.projectId) }),
          children: [
            {
              path: '',
              name: 'project-overview',
              component: () => import('@/views/ProjectOverview.vue'),
            },
            {
              path: 'brainstorm',
              name: 'brainstorm',
              component: () => import('@/views/BrainstormView.vue'),
            },
            {
              path: 'brainstorm/:chatId',
              name: 'brainstorm-chat',
              component: () => import('@/views/BrainstormChatView.vue'),
              props: (route) => ({ chatId: Number(route.params.chatId) }),
            },
            {
              path: 'pipeline',
              name: 'pipeline-list',
              component: () => import('@/views/PipelineListView.vue'),
            },
            {
              path: 'pipeline/:runId',
              name: 'pipeline-board',
              component: () => import('@/views/PipelineBoardView.vue'),
              props: (route) => ({ runId: Number(route.params.runId) }),
            },
            {
              path: 'content/:pieceId',
              name: 'content-piece',
              component: () => import('@/views/ContentPieceView.vue'),
              props: (route) => ({ pieceId: Number(route.params.pieceId) }),
            },
            {
              path: 'profile',
              name: 'profile',
              component: () => import('@/views/ProfileView.vue'),
            },
            {
              path: 'context',
              name: 'context',
              component: () => import('@/views/ContextView.vue'),
            },
            {
              path: 'context/:itemId',
              name: 'context-item',
              component: () => import('@/views/ContextItemView.vue'),
              props: (route) => ({ itemId: Number(route.params.itemId) }),
            },
            {
              path: 'context-memory',
              name: 'context-memory',
              component: () => import('@/views/ContextMemoryView.vue'),
            },
            {
              path: 'storytelling',
              name: 'storytelling',
              component: () => import('@/views/StorytellingView.vue'),
            },
            {
              path: 'settings',
              name: 'project-settings',
              component: () => import('@/views/ProjectSettingsView.vue'),
            },
          ],
        },
      ],
    },
  ],
})

export default router
```

- [ ] **Step 2: Create placeholder views for all routes**

For each view that doesn't exist yet, create a placeholder file with this pattern:

```vue
<template>
  <div class="p-8">
    <h1 class="text-2xl font-bold">[View Name]</h1>
    <p class="text-base-content/60">Coming soon.</p>
  </div>
</template>
```

Create placeholders for: `SettingsView.vue`, `ProjectNewView.vue`, `ProjectOverview.vue`, `BrainstormView.vue`, `BrainstormChatView.vue`, `PipelineListView.vue`, `PipelineBoardView.vue`, `ContentPieceView.vue`, `ProfileView.vue`, `ContextView.vue`, `ContextItemView.vue`, `ContextMemoryView.vue`, `StorytellingView.vue`, `ProjectSettingsView.vue`.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/router/ frontend/src/views/
git commit -m "feat: add Vue Router with all 16 routes and placeholder views"
```

---

## Task 7: Build Layouts

**Files:**
- Modify: `frontend/src/layouts/AppLayout.vue`
- Create: `frontend/src/layouts/ProjectLayout.vue`

- [ ] **Step 1: Build AppLayout**

This mirrors the current `PageShell` templ component — navbar with breadcrumbs. Replace `frontend/src/layouts/AppLayout.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { RouterView, RouterLink, useRoute } from 'vue-router'
import { useProjectsStore } from '@/stores/projects'

const route = useRoute()
const projectsStore = useProjectsStore()

const breadcrumbs = computed(() => {
  const crumbs: { label: string; to?: string }[] = [{ label: 'MarketMinded', to: '/' }]
  const project = projectsStore.current

  if (project && route.params.projectId) {
    crumbs.push({ label: project.name, to: `/projects/${project.id}` })

    const subpage = route.name?.toString()
    const labels: Record<string, string> = {
      'brainstorm': 'Brainstorm',
      'brainstorm-chat': 'Brainstorm',
      'pipeline-list': 'Pipeline',
      'pipeline-board': 'Pipeline',
      'content-piece': 'Content',
      'profile': 'Profile',
      'context': 'Context',
      'context-item': 'Context',
      'context-memory': 'Context & Memories',
      'storytelling': 'Storytelling',
      'project-settings': 'Settings',
    }
    if (subpage && labels[subpage]) {
      crumbs.push({ label: labels[subpage] })
    }
  }

  return crumbs
})
</script>

<template>
  <div class="min-h-screen bg-base-100" data-theme="business">
    <div class="navbar bg-base-200 px-4">
      <div class="flex-1">
        <RouterLink to="/" class="btn btn-ghost text-lg">MarketMinded</RouterLink>
      </div>
      <div class="flex-none">
        <RouterLink to="/settings" class="btn btn-ghost btn-sm">Settings</RouterLink>
      </div>
    </div>

    <div class="breadcrumbs text-sm px-6 py-2">
      <ul>
        <li v-for="(crumb, i) in breadcrumbs" :key="i">
          <RouterLink v-if="crumb.to" :to="crumb.to">{{ crumb.label }}</RouterLink>
          <span v-else>{{ crumb.label }}</span>
        </li>
      </ul>
    </div>

    <main class="px-6 py-4">
      <RouterView />
    </main>
  </div>
</template>
```

- [ ] **Step 2: Build ProjectLayout**

This mirrors `ProjectPageShell` — adds project nav tabs and chat drawer FAB. Create `frontend/src/layouts/ProjectLayout.vue`:

```vue
<script setup lang="ts">
import { onMounted, computed } from 'vue'
import { RouterView, RouterLink, useRoute } from 'vue-router'
import { useProjectsStore } from '@/stores/projects'
import { useChatStore } from '@/stores/chat'
import ChatDrawer from '@/components/ChatDrawer.vue'

const props = defineProps<{ projectId: number }>()
const route = useRoute()
const projectsStore = useProjectsStore()
const chatStore = useChatStore()

onMounted(() => {
  projectsStore.fetchOne(props.projectId)
})

const tabs = computed(() => {
  const base = `/projects/${props.projectId}`
  return [
    { label: 'Overview', to: base, name: 'project-overview' },
    { label: 'Profile', to: `${base}/profile`, name: 'profile' },
    { label: 'Context', to: `${base}/context`, name: 'context' },
    { label: 'Brainstorm', to: `${base}/brainstorm`, name: 'brainstorm' },
    { label: 'Pipeline', to: `${base}/pipeline`, name: 'pipeline-list' },
    { label: 'Storytelling', to: `${base}/storytelling`, name: 'storytelling' },
    { label: 'Settings', to: `${base}/settings`, name: 'project-settings' },
  ]
})

function isTabActive(tabName: string): boolean {
  const current = route.name?.toString() || ''
  if (tabName === 'brainstorm') return current.startsWith('brainstorm')
  if (tabName === 'pipeline-list') return current.startsWith('pipeline') || current === 'content-piece'
  if (tabName === 'context') return current.startsWith('context')
  return current === tabName
}
</script>

<template>
  <div>
    <div role="tablist" class="tabs tabs-bordered mb-4">
      <RouterLink
        v-for="tab in tabs"
        :key="tab.name"
        :to="tab.to"
        role="tab"
        class="tab"
        :class="{ 'tab-active': isTabActive(tab.name) }"
      >
        {{ tab.label }}
      </RouterLink>
    </div>

    <RouterView />

    <button
      class="btn btn-circle btn-primary fixed bottom-6 right-6 shadow-lg"
      @click="chatStore.openDrawer()"
    >
      <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
      </svg>
    </button>

    <ChatDrawer :project-id="projectId" />
  </div>
</template>
```

- [ ] **Step 3: Create ChatDrawer placeholder**

Create `frontend/src/components/ChatDrawer.vue`:

```vue
<script setup lang="ts">
import { useChatStore } from '@/stores/chat'

defineProps<{ projectId: number }>()
const chatStore = useChatStore()
</script>

<template>
  <Teleport to="body">
    <div
      v-if="chatStore.drawerOpen"
      class="fixed inset-y-0 right-0 w-96 bg-base-200 shadow-xl z-50 flex flex-col"
    >
      <div class="flex items-center justify-between p-4 border-b border-base-300">
        <h3 class="font-bold">Chat</h3>
        <button class="btn btn-ghost btn-sm" @click="chatStore.closeDrawer()">✕</button>
      </div>
      <div class="flex-1 p-4 overflow-y-auto">
        <p class="text-base-content/60">Chat drawer — coming soon.</p>
      </div>
    </div>
  </Teleport>
</template>
```

- [ ] **Step 4: Verify routing works**

```bash
cd frontend && npm run dev
```

Navigate to `http://localhost:5173/` (dashboard), `http://localhost:5173/projects/1` (project with tabs), and verify layouts render correctly.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/layouts/ frontend/src/components/ChatDrawer.vue
git commit -m "feat: add AppLayout, ProjectLayout with nav tabs and ChatDrawer"
```

---

## Task 8: Go API Helpers + SPA Handler

**Files:**
- Create: `web/handlers/json.go`
- Create: `web/handlers/spa.go`

- [ ] **Step 1: Create JSON response helpers**

Create `web/handlers/json.go`:

```go
package handlers

import (
	"encoding/json"
	"log"
	"net/http"
)

func writeJSON(w http.ResponseWriter, status int, v any) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	if err := json.NewEncoder(w).Encode(v); err != nil {
		log.Printf("writeJSON: %v", err)
	}
}

func readJSON(r *http.Request, v any) error {
	return json.NewDecoder(r.Body).Decode(v)
}

func writeError(w http.ResponseWriter, status int, msg string) {
	writeJSON(w, status, map[string]string{"error": msg})
}
```

- [ ] **Step 2: Create SPA handler**

Create `web/handlers/spa.go`:

```go
package handlers

import (
	"io/fs"
	"net/http"
	"strings"
)

// SPAHandler serves the Vue SPA from an embedded filesystem.
// All non-API, non-file requests fall through to index.html for client-side routing.
func SPAHandler(distFS fs.FS) http.Handler {
	fileServer := http.FileServer(http.FS(distFS))

	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		// Try to serve the file directly
		path := strings.TrimPrefix(r.URL.Path, "/")
		if path == "" {
			path = "index.html"
		}

		// Check if file exists in the embedded FS
		if _, err := fs.Stat(distFS, path); err == nil {
			fileServer.ServeHTTP(w, r)
			return
		}

		// Fall through to index.html for SPA routing
		r.URL.Path = "/"
		fileServer.ServeHTTP(w, r)
	})
}
```

- [ ] **Step 3: Commit**

```bash
git add web/handlers/json.go web/handlers/spa.go
git commit -m "feat: add JSON response helpers and SPA handler for Vue dist"
```

---

## Task 9: Convert Dashboard Handler to JSON

**Files:**
- Modify: `web/handlers/dashboard.go`
- Modify: `frontend/src/views/DashboardView.vue`

This is the pattern-setting task — the first full handler conversion + Vue view build.

- [ ] **Step 1: Read current dashboard handler**

Read `web/handlers/dashboard.go` to understand the current implementation.

- [ ] **Step 2: Convert handler to return JSON**

Replace the `ServeHTTP` method in `web/handlers/dashboard.go`. Keep the handler struct and constructor, replace the rendering logic:

The handler currently fetches projects and renders `templates.Dashboard(items)`. Change it to return JSON:

```go
func (h *DashboardHandler) ServeHTTP(w http.ResponseWriter, r *http.Request) {
	projects, err := h.queries.ListProjects(r.Context())
	if err != nil {
		writeError(w, http.StatusInternalServerError, "failed to load projects")
		return
	}

	items := make([]DashboardItem, len(projects))
	for i, p := range projects {
		items[i] = DashboardItem{
			ID:          p.ID,
			Name:        p.Name,
			Description: p.Description,
		}
	}

	writeJSON(w, http.StatusOK, items)
}
```

Remove the `templates` import if it was the only usage.

- [ ] **Step 3: Build the DashboardView**

Replace `frontend/src/views/DashboardView.vue`:

```vue
<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink } from 'vue-router'
import { useProjectsStore } from '@/stores/projects'

const projectsStore = useProjectsStore()

onMounted(() => {
  projectsStore.fetchAll()
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold">Projects</h1>
      <RouterLink to="/projects/new" class="btn btn-primary btn-sm">New Project</RouterLink>
    </div>

    <div v-if="projectsStore.projects.length === 0" class="text-base-content/60">
      No projects yet. Create one to get started.
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
      <RouterLink
        v-for="project in projectsStore.projects"
        :key="project.id"
        :to="`/projects/${project.id}`"
        class="card bg-base-200 shadow hover:shadow-lg transition-shadow"
      >
        <div class="card-body">
          <h2 class="card-title">{{ project.name }}</h2>
          <p class="text-base-content/60">{{ project.description || 'No description' }}</p>
        </div>
      </RouterLink>
    </div>
  </div>
</template>
```

- [ ] **Step 4: Test end-to-end**

Start Go server and Vite dev server. Navigate to `http://localhost:5173/`. Verify projects load from the API and display as cards.

```bash
make start  # Go server on :8080
cd frontend && npm run dev  # Vite on :5173
```

- [ ] **Step 5: Commit**

```bash
git add web/handlers/dashboard.go frontend/src/views/DashboardView.vue
git commit -m "feat: convert dashboard to JSON API + Vue view"
```

---

## Task 10: Convert Settings + Project CRUD Handlers

**Files:**
- Modify: `web/handlers/settings.go`
- Modify: `web/handlers/project.go`
- Modify: `web/handlers/project_settings.go`
- Modify: `web/handlers/storytelling.go`
- Modify: `frontend/src/views/SettingsView.vue`
- Modify: `frontend/src/views/ProjectNewView.vue`
- Modify: `frontend/src/views/ProjectOverview.vue`
- Modify: `frontend/src/views/ProjectSettingsView.vue`
- Modify: `frontend/src/views/StorytellingView.vue`

These are all simple form-based pages — same conversion pattern as dashboard.

- [ ] **Step 1: Convert settings.go**

Replace the GET handler to return `SettingsResponse` JSON. Replace the POST handler to accept `SettingsSaveRequest` JSON via `readJSON()` and return the updated settings.

Pattern:
```go
// GET
func (h *SettingsHandler) handleGet(w http.ResponseWriter, r *http.Request) {
    // Read settings from store (h.queries.GetSetting for each key)
    resp := SettingsResponse{
        ModelContent:     getOrDefault(h.queries, r.Context(), "model_content", ""),
        ModelCopywriting: getOrDefault(h.queries, r.Context(), "model_copywriting", ""),
        // ... etc
    }
    writeJSON(w, http.StatusOK, resp)
}

// POST
func (h *SettingsHandler) handleSave(w http.ResponseWriter, r *http.Request) {
    var req SettingsSaveRequest
    if err := readJSON(r, &req); err != nil {
        writeError(w, http.StatusBadRequest, "invalid request")
        return
    }
    // Save each setting via h.queries.UpsertSetting
    writeJSON(w, http.StatusOK, map[string]string{"status": "ok"})
}
```

- [ ] **Step 2: Build SettingsView.vue**

Form with model selection dropdowns and temperature input. Uses `useSettingsStore` to load and save. Pattern:

```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useSettingsStore } from '@/stores/settings'

const store = useSettingsStore()
const form = ref({ model_content: '', model_copywriting: '', model_ideation: '', model_proofread: '', temperature: '' })
const saved = ref(false)

onMounted(async () => {
  await store.fetchGlobal()
  if (store.global) Object.assign(form.value, store.global)
})

async function save() {
  await store.saveGlobal(form.value)
  saved.value = true
  setTimeout(() => saved.value = false, 2000)
}
</script>

<template>
  <div class="max-w-2xl">
    <h1 class="text-2xl font-bold mb-6">Settings</h1>
    <div v-if="saved" class="alert alert-success mb-4">Settings saved.</div>
    <!-- Form fields with DaisyUI form-control, input, select classes -->
    <!-- Each model field: input.input.input-bordered -->
    <!-- Temperature: input type number step 0.1 -->
    <button class="btn btn-primary mt-4" @click="save">Save</button>
  </div>
</template>
```

- [ ] **Step 3: Convert project.go**

Convert the project handler to return JSON for:
- `GET /api/projects/:id` → `ProjectResponse` JSON
- `POST /api/projects` → Accept `ProjectCreateRequest`, return `ProjectResponse`
- `PUT /api/projects/:id` → Accept partial update, return `ProjectResponse`
- `DELETE /api/projects/:id` → Return 204

- [ ] **Step 4: Build ProjectNewView.vue**

Simple form: name + description inputs, submit creates project and navigates to it.

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useProjectsStore } from '@/stores/projects'

const router = useRouter()
const store = useProjectsStore()
const name = ref('')
const description = ref('')

async function create() {
  const project = await store.create({ name: name.value, description: description.value })
  router.push(`/projects/${project.id}`)
}
</script>

<template>
  <div class="max-w-xl">
    <h1 class="text-2xl font-bold mb-6">New Project</h1>
    <div class="form-control mb-4">
      <label class="label"><span class="label-text">Name</span></label>
      <input v-model="name" class="input input-bordered" placeholder="Project name" />
    </div>
    <div class="form-control mb-4">
      <label class="label"><span class="label-text">Description</span></label>
      <textarea v-model="description" class="textarea textarea-bordered" placeholder="Optional description" />
    </div>
    <button class="btn btn-primary" :disabled="!name" @click="create">Create</button>
  </div>
</template>
```

- [ ] **Step 5: Build ProjectOverview.vue**

Shows project name, description, and quick links to sub-pages. Uses `useProjectsStore().current`.

- [ ] **Step 6: Convert project_settings.go and storytelling.go**

Same pattern — return JSON for GET, accept JSON for POST/PUT.

- [ ] **Step 7: Build ProjectSettingsView.vue and StorytellingView.vue**

Both are form pages. ProjectSettings has language and framework selection. Storytelling shows framework options with radio buttons.

- [ ] **Step 8: Test all simple pages end-to-end**

Verify each page loads data from the API and form submissions work.

- [ ] **Step 9: Commit**

```bash
git add web/handlers/ frontend/src/views/
git commit -m "feat: convert settings, project, storytelling handlers to JSON + Vue views"
```

---

## Task 11: Build Shared Streaming Components

**Files:**
- Create: `frontend/src/components/ChatMessage.vue`
- Create: `frontend/src/components/StreamingChat.vue`
- Create: `frontend/src/components/ToolIndicator.vue`
- Create: `frontend/src/components/ThinkingBlock.vue`
- Create: `frontend/src/components/MarkdownContent.vue`

- [ ] **Step 1: Install marked**

```bash
cd frontend && npm install marked
npm install -D @types/marked
```

- [ ] **Step 2: Create MarkdownContent component**

Create `frontend/src/components/MarkdownContent.vue`:

```vue
<script setup lang="ts">
import { computed, ref } from 'vue'
import { marked } from 'marked'

const props = withDefaults(defineProps<{
  content: string
  collapsible?: boolean
  maxHeight?: number
}>(), {
  collapsible: false,
  maxHeight: 200,
})

const expanded = ref(false)
const html = computed(() => marked.parse(props.content) as string)
</script>

<template>
  <div>
    <div
      class="prose prose-sm max-w-none"
      :class="{ 'max-h-[200px] overflow-hidden': collapsible && !expanded }"
      v-html="html"
    />
    <button
      v-if="collapsible"
      class="btn btn-ghost btn-xs mt-1"
      @click="expanded = !expanded"
    >
      {{ expanded ? 'Show less' : 'Show more' }}
    </button>
  </div>
</template>
```

- [ ] **Step 3: Create ToolIndicator component**

Create `frontend/src/components/ToolIndicator.vue`:

```vue
<script setup lang="ts">
defineProps<{ tools: string[] }>()
</script>

<template>
  <div v-if="tools.length" class="flex gap-2 flex-wrap">
    <span
      v-for="tool in tools"
      :key="tool"
      class="badge badge-outline badge-sm gap-1"
    >
      <span class="loading loading-spinner loading-xs" />
      {{ tool }}
    </span>
  </div>
</template>
```

- [ ] **Step 4: Create ThinkingBlock component**

Create `frontend/src/components/ThinkingBlock.vue`:

```vue
<script setup lang="ts">
import { ref } from 'vue'

defineProps<{ thinking: string }>()
const expanded = ref(false)
</script>

<template>
  <div v-if="thinking" class="collapse collapse-arrow bg-base-300 rounded-lg mb-2">
    <input v-model="expanded" type="checkbox" />
    <div class="collapse-title text-sm font-medium text-base-content/60">
      Thinking...
    </div>
    <div class="collapse-content">
      <pre class="text-xs whitespace-pre-wrap text-base-content/50">{{ thinking }}</pre>
    </div>
  </div>
</template>
```

- [ ] **Step 5: Create ChatMessage component**

Create `frontend/src/components/ChatMessage.vue`:

```vue
<script setup lang="ts">
import MarkdownContent from './MarkdownContent.vue'
import ThinkingBlock from './ThinkingBlock.vue'

defineProps<{
  role: string
  content: string
  thinking?: string
}>()
</script>

<template>
  <div class="chat" :class="role === 'user' ? 'chat-end' : 'chat-start'">
    <div class="chat-header text-xs text-base-content/50 mb-1">
      {{ role === 'user' ? 'You' : 'AI' }}
    </div>
    <div
      class="chat-bubble"
      :class="role === 'user' ? 'chat-bubble-primary' : 'chat-bubble-neutral'"
    >
      <ThinkingBlock v-if="thinking" :thinking="thinking" />
      <MarkdownContent :content="content" />
    </div>
  </div>
</template>
```

- [ ] **Step 6: Create StreamingChat component**

Create `frontend/src/components/StreamingChat.vue`:

```vue
<script setup lang="ts">
import { ref, nextTick, watch } from 'vue'
import ChatMessage from './ChatMessage.vue'
import ToolIndicator from './ToolIndicator.vue'
import MarkdownContent from './MarkdownContent.vue'
import type { ChatMessage as ChatMessageType } from '@/types'

const props = defineProps<{
  messages: ChatMessageType[]
  streamingContent: string
  streamingThinking: string
  activeTools: string[]
  isStreaming: boolean
}>()

const emit = defineEmits<{
  send: [content: string]
}>()

const input = ref('')
const messagesEl = ref<HTMLElement>()

function send() {
  if (!input.value.trim() || props.isStreaming) return
  emit('send', input.value)
  input.value = ''
}

watch(
  () => props.messages.length + props.streamingContent.length,
  async () => {
    await nextTick()
    messagesEl.value?.scrollTo({ top: messagesEl.value.scrollHeight })
  },
)
</script>

<template>
  <div class="flex flex-col h-full">
    <div ref="messagesEl" class="flex-1 overflow-y-auto space-y-4 p-4">
      <ChatMessage
        v-for="(msg, i) in messages"
        :key="i"
        :role="msg.role"
        :content="msg.content"
        :thinking="msg.thinking"
      />

      <!-- Streaming response -->
      <div v-if="isStreaming || streamingContent" class="chat chat-start">
        <div class="chat-header text-xs text-base-content/50 mb-1">AI</div>
        <div class="chat-bubble chat-bubble-neutral">
          <ToolIndicator :tools="activeTools" />
          <MarkdownContent v-if="streamingContent" :content="streamingContent" />
          <span v-else class="loading loading-dots loading-sm" />
        </div>
      </div>
    </div>

    <div class="p-4 border-t border-base-300">
      <div class="flex gap-2">
        <input
          v-model="input"
          class="input input-bordered flex-1"
          placeholder="Type a message..."
          :disabled="isStreaming"
          @keyup.enter="send"
        />
        <button class="btn btn-primary" :disabled="!input.trim() || isStreaming" @click="send">
          Send
        </button>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/ frontend/package.json frontend/package-lock.json
git commit -m "feat: add shared streaming components — ChatMessage, StreamingChat, ToolIndicator, ThinkingBlock, MarkdownContent"
```

---

## Task 12: Convert Brainstorm Handler + Build Views

**Files:**
- Modify: `web/handlers/brainstorm.go`
- Modify: `frontend/src/views/BrainstormView.vue`
- Modify: `frontend/src/views/BrainstormChatView.vue`

- [ ] **Step 1: Convert brainstorm handler to JSON**

The handler already has `listJSON` and `messagesJSON` methods. Convert:
- `GET /api/projects/:id/brainstorm` → Return `[]ChatListItem` (rename `listJSON` to be the main list handler)
- `GET /api/projects/:id/brainstorm/:chatId/messages` → Return `[]ChatMessage`
- `POST /api/projects/:id/brainstorm/:chatId/message` → Accept `{"content": "..."}`, keep as-is
- `GET /api/projects/:id/brainstorm/:chatId/stream` → SSE, keep as-is
- `POST /api/projects/:id/brainstorm` → Create new chat, return `{"id": chatId}`

Remove all templ rendering calls. Keep SSE streaming methods unchanged.

- [ ] **Step 2: Build BrainstormView.vue**

Chat list page — shows all conversations with option to start a new one.

```vue
<script setup lang="ts">
import { onMounted } from 'vue'
import { RouterLink, useRoute } from 'vue-router'
import { useChatStore } from '@/stores/chat'

const route = useRoute()
const chatStore = useChatStore()
const projectId = Number(route.params.projectId)

onMounted(() => {
  chatStore.fetchChats(projectId)
})
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-6">
      <h1 class="text-2xl font-bold">Brainstorm</h1>
      <RouterLink :to="`/projects/${projectId}/brainstorm/new`" class="btn btn-primary btn-sm">
        New Chat
      </RouterLink>
    </div>

    <div v-if="chatStore.chats.length === 0" class="text-base-content/60">
      No conversations yet.
    </div>

    <div class="space-y-2">
      <RouterLink
        v-for="chat in chatStore.chats"
        :key="chat.id"
        :to="`/projects/${projectId}/brainstorm/${chat.id}`"
        class="card bg-base-200 shadow-sm hover:shadow transition-shadow cursor-pointer"
      >
        <div class="card-body py-3">
          <h3 class="font-medium">{{ chat.title || 'Untitled' }}</h3>
          <p class="text-sm text-base-content/60">{{ chat.preview }}</p>
        </div>
      </RouterLink>
    </div>
  </div>
</template>
```

- [ ] **Step 3: Build BrainstormChatView.vue**

Full chat page using StreamingChat component and useChat composable.

```vue
<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useChat } from '@/composables/useChat'
import StreamingChat from '@/components/StreamingChat.vue'

const props = defineProps<{ chatId: number }>()
const route = useRoute()
const projectId = Number(route.params.projectId)
const chat = useChat()

onMounted(() => {
  chat.loadMessages(projectId, props.chatId)
})

function handleSend(content: string) {
  chat.sendMessage(projectId, props.chatId, content)
}
</script>

<template>
  <div class="h-[calc(100vh-12rem)]">
    <StreamingChat
      :messages="chat.messages.value"
      :streaming-content="chat.streamingContent.value"
      :streaming-thinking="chat.streamingThinking.value"
      :active-tools="chat.activeTools.value"
      :is-streaming="chat.isStreaming.value"
      @send="handleSend"
    />
  </div>
</template>
```

- [ ] **Step 4: Test brainstorm flow end-to-end**

Start both servers, navigate to brainstorm, verify chat list loads, open a chat, send a message, verify SSE streaming works.

- [ ] **Step 5: Commit**

```bash
git add web/handlers/brainstorm.go frontend/src/views/BrainstormView.vue frontend/src/views/BrainstormChatView.vue
git commit -m "feat: convert brainstorm to JSON API + Vue views with streaming chat"
```

---

## Task 13: Build Content Renderer + Convert Pipeline Handler

**Files:**
- Create: `frontend/src/composables/useContentRenderer.ts`
- Create: `frontend/src/components/ContentRenderer.vue`
- Create: `frontend/src/components/ContentCard.vue`
- Create: `frontend/src/components/PipelineStepCard.vue`
- Modify: `web/handlers/pipeline.go`
- Modify: `frontend/src/views/PipelineListView.vue`
- Modify: `frontend/src/views/PipelineBoardView.vue`
- Modify: `frontend/src/views/ContentPieceView.vue`

- [ ] **Step 1: Create useContentRenderer composable**

Port the logic from `web/static/js/renderers/content-body.js` into a typed composable. Create `frontend/src/composables/useContentRenderer.ts`:

```ts
export interface RenderedContent {
  title: string
  sections: { label: string; content: string; isMarkdown?: boolean }[]
  hashtags?: string[]
  cta?: string
}

export function useContentRenderer() {
  function render(platform: string, format: string, body: string): RenderedContent {
    try {
      const data = JSON.parse(body)
      return renderByType(platform, format, data)
    } catch {
      // Plain text fallback
      return { title: '', sections: [{ label: '', content: body, isMarkdown: true }] }
    }
  }

  function renderByType(platform: string, format: string, data: any): RenderedContent {
    // Blog post
    if (platform === 'blog' || format === 'blog_post') {
      return {
        title: data.title || '',
        sections: [{ label: '', content: data.body || data.content || '', isMarkdown: true }],
      }
    }

    // Social posts (LinkedIn, Instagram, Facebook, X)
    if (['linkedin', 'instagram', 'facebook', 'x'].includes(platform)) {
      return {
        title: data.hook || data.title || '',
        sections: [{ label: 'Post', content: data.body || data.content || '' }],
        hashtags: data.hashtags || [],
        cta: data.cta || data.call_to_action || '',
      }
    }

    // Carousel
    if (format === 'carousel') {
      const slides = data.slides || data.cards || []
      return {
        title: data.title || 'Carousel',
        sections: slides.map((s: any, i: number) => ({
          label: `Slide ${i + 1}`,
          content: s.content || s.text || '',
        })),
      }
    }

    // Video script
    if (format === 'video_script' || format === 'youtube_script') {
      return {
        title: data.title || 'Video Script',
        sections: [
          { label: 'Hook', content: data.hook || '' },
          { label: 'Script', content: data.script || data.body || '', isMarkdown: true },
          { label: 'CTA', content: data.cta || '' },
        ].filter((s) => s.content),
      }
    }

    // Fallback
    return {
      title: data.title || '',
      sections: [{ label: '', content: JSON.stringify(data, null, 2) }],
    }
  }

  return { render }
}
```

- [ ] **Step 2: Create ContentRenderer component**

Create `frontend/src/components/ContentRenderer.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue'
import { useContentRenderer } from '@/composables/useContentRenderer'
import MarkdownContent from './MarkdownContent.vue'

const props = defineProps<{
  platform: string
  format: string
  body: string
}>()

const renderer = useContentRenderer()
const rendered = computed(() => renderer.render(props.platform, props.format, props.body))
</script>

<template>
  <div>
    <h3 v-if="rendered.title" class="font-bold text-lg mb-2">{{ rendered.title }}</h3>

    <div v-for="(section, i) in rendered.sections" :key="i" class="mb-3">
      <div v-if="section.label" class="text-xs font-semibold text-base-content/50 uppercase mb-1">
        {{ section.label }}
      </div>
      <MarkdownContent v-if="section.isMarkdown" :content="section.content" />
      <p v-else class="whitespace-pre-wrap">{{ section.content }}</p>
    </div>

    <div v-if="rendered.hashtags?.length" class="flex flex-wrap gap-1 mt-2">
      <span v-for="tag in rendered.hashtags" :key="tag" class="badge badge-outline badge-sm">
        {{ tag.startsWith('#') ? tag : `#${tag}` }}
      </span>
    </div>

    <div v-if="rendered.cta" class="mt-2 text-sm font-medium text-primary">
      {{ rendered.cta }}
    </div>
  </div>
</template>
```

- [ ] **Step 3: Create PipelineStepCard component**

Create `frontend/src/components/PipelineStepCard.vue`:

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { useSSE } from '@/composables/useSSE'
import ToolIndicator from './ToolIndicator.vue'
import MarkdownContent from './MarkdownContent.vue'
import type { PipelineStepResponse, SSEEvent } from '@/types'

const props = defineProps<{
  step: PipelineStepResponse
  projectId: number
  runId: number
}>()

const emit = defineEmits<{ completed: [] }>()

const sse = useSSE()
const streamOutput = ref('')
const activeTools = ref<string[]>([])
const status = ref(props.step.status)

const stepLabels: Record<string, string> = {
  research: 'Researcher',
  brand_enricher: 'Brand Enricher',
  factcheck: 'Fact Checker',
  editor: 'Editor',
  write: 'Writer',
}

function run() {
  status.value = 'running'
  streamOutput.value = ''
  activeTools.value = []

  sse.connect(
    `/api/projects/${props.projectId}/pipeline/${props.runId}/stream/step/${props.step.id}`,
    (event: SSEEvent) => {
      if (event.type === 'chunk') streamOutput.value += event.chunk
      else if (event.type === 'tool_start') activeTools.value.push(event.tool)
      else if (event.type === 'tool_result') activeTools.value = activeTools.value.filter(t => t !== event.tool)
      else if (event.type === 'done') {
        status.value = 'done'
        emit('completed')
      }
      else if (event.type === 'error') status.value = 'error'
    },
  )
}
</script>

<template>
  <div class="card bg-base-200">
    <div class="card-body">
      <div class="flex items-center justify-between">
        <h3 class="card-title text-sm">
          {{ stepLabels[step.step_type] || step.step_type }}
        </h3>
        <span
          class="badge badge-sm"
          :class="{
            'badge-ghost': status === 'pending',
            'badge-warning': status === 'running',
            'badge-success': status === 'done',
            'badge-error': status === 'error',
          }"
        >
          {{ status }}
        </span>
      </div>

      <ToolIndicator :tools="activeTools" />

      <div v-if="step.output || streamOutput" class="mt-2">
        <MarkdownContent :content="streamOutput || step.output" collapsible :max-height="150" />
      </div>

      <div v-if="status === 'pending'" class="card-actions justify-end mt-2">
        <button class="btn btn-primary btn-sm" @click="run">Run</button>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 4: Create ContentCard component**

Create `frontend/src/components/ContentCard.vue`:

```vue
<script setup lang="ts">
import { ref } from 'vue'
import ContentRenderer from './ContentRenderer.vue'
import type { ContentPieceResponse } from '@/types'

const props = defineProps<{
  piece: ContentPieceResponse
}>()

const emit = defineEmits<{
  approve: [id: number]
  reject: [id: number, reason: string]
}>()

const rejectReason = ref('')
const showRejectForm = ref(false)

function approve() {
  emit('approve', props.piece.id)
}

function reject() {
  emit('reject', props.piece.id, rejectReason.value)
  showRejectForm.value = false
  rejectReason.value = ''
}
</script>

<template>
  <div class="card bg-base-200">
    <div class="card-body">
      <div class="flex items-center justify-between mb-2">
        <div class="flex gap-2">
          <span class="badge badge-sm">{{ piece.platform }}</span>
          <span class="badge badge-sm badge-outline">{{ piece.format }}</span>
        </div>
        <span
          class="badge badge-sm"
          :class="{
            'badge-ghost': piece.status === 'draft',
            'badge-success': piece.status === 'approved',
            'badge-error': piece.status === 'rejected',
          }"
        >
          {{ piece.status }}
        </span>
      </div>

      <ContentRenderer
        v-if="piece.body"
        :platform="piece.platform"
        :format="piece.format"
        :body="piece.body"
      />

      <div v-if="piece.status === 'draft'" class="card-actions justify-end mt-3">
        <button class="btn btn-success btn-sm" @click="approve">Approve</button>
        <button class="btn btn-error btn-sm btn-outline" @click="showRejectForm = !showRejectForm">
          Reject
        </button>
      </div>

      <div v-if="showRejectForm" class="mt-2">
        <textarea
          v-model="rejectReason"
          class="textarea textarea-bordered w-full"
          placeholder="Reason for rejection..."
        />
        <button class="btn btn-error btn-sm mt-2" :disabled="!rejectReason" @click="reject">
          Submit Rejection
        </button>
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 5: Convert pipeline.go to JSON**

Convert the pipeline handler:
- `GET /api/projects/:id/pipeline` → Return `[]PipelineRunResponse`
- `GET /api/projects/:id/pipeline/:runId` → Return `ProductionBoardResponse` (run + steps + pieces)
- `POST /api/projects/:id/pipeline` → Create run, return `PipelineRunResponse`
- `POST /api/projects/:id/pipeline/:runId/pieces/:pieceId/approve` → Approve piece
- `POST /api/projects/:id/pipeline/:runId/pieces/:pieceId/reject` → Reject piece
- SSE streaming endpoints → Keep unchanged

Remove all templ rendering. Keep the SSE `streamStep`, `streamPiece`, `streamImprove` methods as-is.

- [ ] **Step 6: Build PipelineListView.vue**

```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { usePipelineStore } from '@/stores/pipeline'

const route = useRoute()
const router = useRouter()
const store = usePipelineStore()
const projectId = Number(route.params.projectId)
const topic = ref('')

onMounted(() => store.fetchRuns(projectId))

async function create() {
  const run = await store.createRun(projectId, topic.value)
  router.push(`/projects/${projectId}/pipeline/${run.id}`)
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold mb-6">Content Pipeline</h1>

    <div class="flex gap-2 mb-6">
      <input v-model="topic" class="input input-bordered flex-1" placeholder="Enter a topic..." />
      <button class="btn btn-primary" :disabled="!topic" @click="create">Create Run</button>
    </div>

    <div class="space-y-2">
      <RouterLink
        v-for="run in store.runs"
        :key="run.id"
        :to="`/projects/${projectId}/pipeline/${run.id}`"
        class="card bg-base-200 shadow-sm hover:shadow cursor-pointer"
      >
        <div class="card-body py-3">
          <div class="flex items-center justify-between">
            <h3 class="font-medium">{{ run.topic }}</h3>
            <span class="badge badge-sm" :class="run.status === 'done' ? 'badge-success' : 'badge-warning'">
              {{ run.status }}
            </span>
          </div>
        </div>
      </RouterLink>
    </div>
  </div>
</template>
```

- [ ] **Step 7: Build PipelineBoardView.vue**

The production board — shows steps in order, then content pieces below.

```vue
<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { usePipelineStore } from '@/stores/pipeline'
import PipelineStepCard from '@/components/PipelineStepCard.vue'
import ContentCard from '@/components/ContentCard.vue'

const props = defineProps<{ runId: number }>()
const route = useRoute()
const store = usePipelineStore()
const projectId = Number(route.params.projectId)

onMounted(() => store.fetchBoard(projectId, props.runId))

function onStepCompleted() {
  store.fetchBoard(projectId, props.runId)
}

function onApprove(pieceId: number) {
  store.approvePiece(projectId, props.runId, pieceId)
}

function onReject(pieceId: number, reason: string) {
  store.rejectPiece(projectId, props.runId, pieceId, reason)
}
</script>

<template>
  <div v-if="store.currentBoard">
    <h1 class="text-2xl font-bold mb-2">{{ store.currentBoard.run.topic }}</h1>
    <p class="text-sm text-base-content/60 mb-6">{{ store.currentBoard.run.brief }}</p>

    <h2 class="text-lg font-semibold mb-3">Steps</h2>
    <div class="space-y-3 mb-8">
      <PipelineStepCard
        v-for="step in store.currentBoard.steps"
        :key="step.id"
        :step="step"
        :project-id="projectId"
        :run-id="runId"
        @completed="onStepCompleted"
      />
    </div>

    <h2 v-if="store.currentBoard.pieces.length" class="text-lg font-semibold mb-3">Content</h2>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
      <ContentCard
        v-for="piece in store.currentBoard.pieces"
        :key="piece.id"
        :piece="piece"
        @approve="onApprove"
        @reject="onReject"
      />
    </div>
  </div>
</template>
```

- [ ] **Step 8: Build ContentPieceView.vue**

Detail view for a single content piece with edit/improve capabilities. Uses SSE streaming for improvements.

- [ ] **Step 9: Test pipeline flow end-to-end**

Create a pipeline run, run steps, verify SSE streaming, check content pieces render correctly with the ContentRenderer.

- [ ] **Step 10: Commit**

```bash
git add web/handlers/pipeline.go frontend/src/
git commit -m "feat: convert pipeline to JSON API + Vue views with streaming steps and content"
```

---

## Task 14: Convert Profile Handler + Build Views

**Files:**
- Modify: `web/handlers/profile.go`
- Modify: `web/handlers/audience.go` (minor adjustments)
- Modify: `web/handlers/voice_tone.go` (minor adjustments)
- Create: `frontend/src/components/ProfileSection.vue`
- Create: `frontend/src/components/PersonaCard.vue`
- Create: `frontend/src/components/VoiceToneCard.vue`
- Modify: `frontend/src/views/ProfileView.vue`

- [ ] **Step 1: Convert profile.go to JSON**

Convert:
- `GET /api/projects/:id/profile` → Return `[]ProfileSectionResponse`
- `PUT /api/projects/:id/profile/:section` → Accept `ProfileSaveRequest`, save section
- `GET /api/projects/:id/profile/:section/versions` → Return `[]ProfileVersionResponse`
- `GET /api/projects/:id/profile/:section/context` → Return context JSON (URLs + notes)
- `PUT /api/projects/:id/profile/:section/context` → Accept `ContextURLsRequest`
- `GET /api/projects/:id/profile/:section/generate` → SSE stream, keep unchanged

Remove templ rendering. Adjust audience.go and voice_tone.go only if their routing prefix needs to change to fit under `/api/`.

- [ ] **Step 2: Create ProfileSection component**

Create `frontend/src/components/ProfileSection.vue`:

```vue
<script setup lang="ts">
import { ref } from 'vue'
import { useSSE } from '@/composables/useSSE'
import MarkdownContent from './MarkdownContent.vue'
import ToolIndicator from './ToolIndicator.vue'
import type { ProfileSectionResponse, SSEEvent } from '@/types'

const props = defineProps<{
  section: ProfileSectionResponse
  projectId: number
}>()

const emit = defineEmits<{ updated: [] }>()

const editing = ref(false)
const editContent = ref('')
const generating = ref(false)
const streamContent = ref('')
const activeTools = ref<string[]>([])
const sse = useSSE()

function startEdit() {
  editContent.value = props.section.content
  editing.value = true
}

function cancelEdit() {
  editing.value = false
}

async function save() {
  const { useApi } = await import('@/composables/useApi')
  const api = useApi()
  await api.put(`/api/projects/${props.projectId}/profile/${props.section.section}`, {
    content: editContent.value,
  })
  editing.value = false
  emit('updated')
}

function generate() {
  generating.value = true
  streamContent.value = ''
  activeTools.value = []

  sse.connect(
    `/api/projects/${props.projectId}/profile/${props.section.section}/generate`,
    (event: SSEEvent) => {
      if (event.type === 'chunk') streamContent.value += event.chunk
      else if (event.type === 'tool_start') activeTools.value.push(event.tool)
      else if (event.type === 'tool_result') activeTools.value = activeTools.value.filter(t => t !== event.tool)
      else if (event.type === 'done' || event.type === 'result') {
        generating.value = false
        emit('updated')
      }
      else if (event.type === 'error') generating.value = false
    },
  )
}

const sectionLabels: Record<string, string> = {
  product: 'Product & Positioning',
  audience: 'Audience',
  voice_and_tone: 'Voice & Tone',
}
</script>

<template>
  <div class="card bg-base-200">
    <div class="card-body">
      <div class="flex items-center justify-between">
        <h3 class="card-title">{{ sectionLabels[section.section] || section.section }}</h3>
        <div class="flex gap-2">
          <button class="btn btn-ghost btn-sm" @click="startEdit">Edit</button>
          <button class="btn btn-primary btn-sm" :disabled="generating" @click="generate">
            <span v-if="generating" class="loading loading-spinner loading-xs" />
            {{ section.content ? 'Rebuild' : 'Build' }}
          </button>
        </div>
      </div>

      <ToolIndicator :tools="activeTools" />

      <!-- Editing mode -->
      <div v-if="editing" class="mt-3">
        <textarea v-model="editContent" class="textarea textarea-bordered w-full h-48" />
        <div class="flex gap-2 mt-2">
          <button class="btn btn-primary btn-sm" @click="save">Save</button>
          <button class="btn btn-ghost btn-sm" @click="cancelEdit">Cancel</button>
        </div>
      </div>

      <!-- Content display -->
      <div v-else-if="streamContent || section.content" class="mt-3">
        <MarkdownContent :content="streamContent || section.content" collapsible />
      </div>

      <div v-else class="mt-3 text-base-content/40">
        No content yet. Click Build to generate.
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 3: Create PersonaCard component**

Create `frontend/src/components/PersonaCard.vue`:

```vue
<script setup lang="ts">
import type { AudiencePersona } from '@/types'

defineProps<{ persona: AudiencePersona }>()
defineEmits<{
  edit: [persona: AudiencePersona]
  delete: [id: number]
}>()
</script>

<template>
  <div class="card bg-base-200">
    <div class="card-body">
      <div class="flex items-center justify-between">
        <h3 class="card-title text-sm">{{ persona.label }}</h3>
        <div class="flex gap-1">
          <button class="btn btn-ghost btn-xs" @click="$emit('edit', persona)">Edit</button>
          <button class="btn btn-ghost btn-xs text-error" @click="$emit('delete', persona.id)">Delete</button>
        </div>
      </div>
      <p class="text-sm text-base-content/70">{{ persona.description }}</p>
      <div v-if="persona.role" class="text-xs text-base-content/50">Role: {{ persona.role }}</div>
    </div>
  </div>
</template>
```

- [ ] **Step 4: Create VoiceToneCard component**

Create `frontend/src/components/VoiceToneCard.vue`:

```vue
<script setup lang="ts">
import MarkdownContent from './MarkdownContent.vue'
import type { VoiceToneProfile } from '@/types'

defineProps<{ profile: VoiceToneProfile }>()
</script>

<template>
  <div class="space-y-3">
    <div v-if="profile.voice_analysis" class="card bg-base-200">
      <div class="card-body">
        <h4 class="font-semibold text-sm">Voice Analysis</h4>
        <MarkdownContent :content="profile.voice_analysis" collapsible />
      </div>
    </div>
    <div v-if="profile.should_use" class="card bg-base-200">
      <div class="card-body">
        <h4 class="font-semibold text-sm">Should Use</h4>
        <MarkdownContent :content="profile.should_use" />
      </div>
    </div>
    <div v-if="profile.should_avoid" class="card bg-base-200">
      <div class="card-body">
        <h4 class="font-semibold text-sm">Should Avoid</h4>
        <MarkdownContent :content="profile.should_avoid" />
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 5: Build ProfileView.vue**

The profile hub — shows all three sections as cards with audience personas and voice tone sub-sections.

```vue
<script setup lang="ts">
import { onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useProfileStore } from '@/stores/profile'
import ProfileSection from '@/components/ProfileSection.vue'
import PersonaCard from '@/components/PersonaCard.vue'
import VoiceToneCard from '@/components/VoiceToneCard.vue'

const route = useRoute()
const store = useProfileStore()
const projectId = Number(route.params.projectId)

onMounted(async () => {
  await Promise.all([
    store.fetchSections(projectId),
    store.fetchPersonas(projectId),
    store.fetchVoiceTone(projectId),
  ])
})

function onSectionUpdated() {
  store.fetchSections(projectId)
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold mb-6">Brand Profile</h1>

    <div class="space-y-6">
      <ProfileSection
        v-for="section in store.sections"
        :key="section.section"
        :section="section"
        :project-id="projectId"
        @updated="onSectionUpdated"
      />

      <!-- Audience Personas -->
      <div>
        <h2 class="text-lg font-semibold mb-3">Audience Personas</h2>
        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <PersonaCard
            v-for="persona in store.personas"
            :key="persona.id"
            :persona="persona"
            @delete="(id) => store.deletePersona(projectId, id)"
          />
        </div>
      </div>

      <!-- Voice & Tone -->
      <div v-if="store.voiceToneProfile">
        <h2 class="text-lg font-semibold mb-3">Voice & Tone Profile</h2>
        <VoiceToneCard :profile="store.voiceToneProfile" />
      </div>
    </div>
  </div>
</template>
```

- [ ] **Step 6: Test profile flow end-to-end**

Verify sections load, edit/save works, generation streams correctly, personas display and delete.

- [ ] **Step 7: Commit**

```bash
git add web/handlers/profile.go web/handlers/audience.go web/handlers/voice_tone.go frontend/src/
git commit -m "feat: convert profile, audience, voice_tone to JSON API + Vue views"
```

---

## Task 15: Convert Context Handler + Build Views

**Files:**
- Modify: `web/handlers/context.go`
- Modify: `frontend/src/views/ContextView.vue`
- Modify: `frontend/src/views/ContextItemView.vue`
- Modify: `frontend/src/views/ContextMemoryView.vue`

- [ ] **Step 1: Convert context.go to JSON**

Convert:
- `GET /api/projects/:id/context` → Return `[]ContextItemResponse`
- `GET /api/projects/:id/context/:itemId` → Return `ContextItemResponse` + messages
- `POST /api/projects/:id/context` → Accept `ContextItemCreateRequest`, return created item
- `PUT /api/projects/:id/context/:itemId` → Update item
- `POST /api/projects/:id/context/:itemId/message` → Post message, keep as-is
- `GET /api/projects/:id/context/:itemId/stream` → SSE, keep unchanged
- `POST /api/projects/:id/context/:itemId/save` → Save refined content

Remove templ rendering. Keep SSE streaming unchanged.

- [ ] **Step 2: Build ContextView.vue**

List of context items with create form.

```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useApi } from '@/composables/useApi'
import type { ContextItemResponse } from '@/types'

const route = useRoute()
const router = useRouter()
const api = useApi()
const projectId = Number(route.params.projectId)
const items = ref<ContextItemResponse[]>([])
const title = ref('')

onMounted(async () => {
  items.value = await api.get<ContextItemResponse[]>(`/api/projects/${projectId}/context`)
})

async function create() {
  const item = await api.post<ContextItemResponse>(`/api/projects/${projectId}/context`, {
    title: title.value,
    content: '',
  })
  router.push(`/projects/${projectId}/context/${item.id}`)
}
</script>

<template>
  <div>
    <h1 class="text-2xl font-bold mb-6">Context & Research</h1>

    <div class="flex gap-2 mb-6">
      <input v-model="title" class="input input-bordered flex-1" placeholder="New context item..." />
      <button class="btn btn-primary" :disabled="!title" @click="create">Add</button>
    </div>

    <div class="space-y-2">
      <RouterLink
        v-for="item in items"
        :key="item.id"
        :to="`/projects/${projectId}/context/${item.id}`"
        class="card bg-base-200 shadow-sm hover:shadow cursor-pointer"
      >
        <div class="card-body py-3">
          <h3 class="font-medium">{{ item.title }}</h3>
          <p class="text-sm text-base-content/60 line-clamp-2">{{ item.content }}</p>
        </div>
      </RouterLink>
    </div>
  </div>
</template>
```

- [ ] **Step 3: Build ContextItemView.vue**

Detail view with chat for refining context — uses StreamingChat.

```vue
<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useApi } from '@/composables/useApi'
import { useChat } from '@/composables/useChat'
import StreamingChat from '@/components/StreamingChat.vue'
import MarkdownContent from '@/components/MarkdownContent.vue'
import type { ContextItemResponse } from '@/types'

const props = defineProps<{ itemId: number }>()
const route = useRoute()
const api = useApi()
const projectId = Number(route.params.projectId)
const item = ref<ContextItemResponse | null>(null)
const chat = useChat()

onMounted(async () => {
  item.value = await api.get<ContextItemResponse>(`/api/projects/${projectId}/context/${props.itemId}`)
  // Load any existing chat messages for this context item
})

function handleSend(content: string) {
  chat.sendMessage(projectId, props.itemId, content)
}

async function saveContent() {
  await api.post(`/api/projects/${projectId}/context/${props.itemId}/save`)
  item.value = await api.get<ContextItemResponse>(`/api/projects/${projectId}/context/${props.itemId}`)
}
</script>

<template>
  <div v-if="item" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div>
      <h1 class="text-xl font-bold mb-4">{{ item.title }}</h1>
      <div class="card bg-base-200">
        <div class="card-body">
          <MarkdownContent v-if="item.content" :content="item.content" />
          <p v-else class="text-base-content/40">No content yet. Use the chat to build it.</p>
          <button v-if="item.content" class="btn btn-primary btn-sm mt-3" @click="saveContent">
            Save
          </button>
        </div>
      </div>
    </div>

    <div class="h-[calc(100vh-16rem)]">
      <StreamingChat
        :messages="chat.messages.value"
        :streaming-content="chat.streamingContent.value"
        :streaming-thinking="chat.streamingThinking.value"
        :active-tools="chat.activeTools.value"
        :is-streaming="chat.isStreaming.value"
        @send="handleSend"
      />
    </div>
  </div>
</template>
```

- [ ] **Step 4: Build ContextMemoryView.vue**

Simple form page for quick context and memory entries.

- [ ] **Step 5: Test context flow end-to-end**

- [ ] **Step 6: Commit**

```bash
git add web/handlers/context.go frontend/src/views/Context*.vue
git commit -m "feat: convert context to JSON API + Vue views"
```

---

## Task 16: Build Full ChatDrawer

**Files:**
- Modify: `frontend/src/components/ChatDrawer.vue`

- [ ] **Step 1: Replace ChatDrawer placeholder with full implementation**

Replace `frontend/src/components/ChatDrawer.vue`:

```vue
<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useChatStore } from '@/stores/chat'
import { useChat } from '@/composables/useChat'
import StreamingChat from './StreamingChat.vue'
import type { ChatListItem } from '@/types'

const props = defineProps<{ projectId: number }>()
const chatStore = useChatStore()
const chat = useChat()
const chatList = ref<ChatListItem[]>([])
const selectedChatId = ref<number | null>(null)
const newMessage = ref('')

watch(
  () => chatStore.drawerOpen,
  async (open) => {
    if (open) {
      await chatStore.fetchChats(props.projectId)
      chatList.value = chatStore.chats
      if (chatStore.drawerChatId) {
        selectChat(chatStore.drawerChatId)
      }
    }
  },
)

async function selectChat(chatId: number) {
  selectedChatId.value = chatId
  await chat.loadMessages(props.projectId, chatId)
}

async function startNewChat() {
  if (!newMessage.value.trim()) return
  const chatId = await chatStore.createChat(props.projectId, newMessage.value)
  newMessage.value = ''
  await selectChat(chatId)
}

function handleSend(content: string) {
  if (!selectedChatId.value) return
  chat.sendMessage(props.projectId, selectedChatId.value, content)
}

function back() {
  selectedChatId.value = null
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="chatStore.drawerOpen"
      class="fixed inset-y-0 right-0 w-96 bg-base-200 shadow-xl z-50 flex flex-col"
    >
      <div class="flex items-center justify-between p-4 border-b border-base-300">
        <div class="flex items-center gap-2">
          <button v-if="selectedChatId" class="btn btn-ghost btn-xs" @click="back">&larr;</button>
          <h3 class="font-bold">Chat</h3>
        </div>
        <button class="btn btn-ghost btn-sm" @click="chatStore.closeDrawer()">✕</button>
      </div>

      <!-- Chat list -->
      <div v-if="!selectedChatId" class="flex-1 flex flex-col">
        <div class="p-3 border-b border-base-300">
          <div class="flex gap-2">
            <input
              v-model="newMessage"
              class="input input-bordered input-sm flex-1"
              placeholder="Start a new chat..."
              @keyup.enter="startNewChat"
            />
            <button class="btn btn-primary btn-sm" :disabled="!newMessage.trim()" @click="startNewChat">
              Go
            </button>
          </div>
        </div>
        <div class="flex-1 overflow-y-auto">
          <button
            v-for="c in chatList"
            :key="c.id"
            class="w-full text-left p-3 hover:bg-base-300 border-b border-base-300"
            @click="selectChat(c.id)"
          >
            <div class="font-medium text-sm">{{ c.title || 'Untitled' }}</div>
            <div class="text-xs text-base-content/50 truncate">{{ c.preview }}</div>
          </button>
        </div>
      </div>

      <!-- Active chat -->
      <div v-else class="flex-1 min-h-0">
        <StreamingChat
          :messages="chat.messages.value"
          :streaming-content="chat.streamingContent.value"
          :streaming-thinking="chat.streamingThinking.value"
          :active-tools="chat.activeTools.value"
          :is-streaming="chat.isStreaming.value"
          @send="handleSend"
        />
      </div>
    </div>
  </Teleport>
</template>
```

- [ ] **Step 2: Test drawer end-to-end**

Open the drawer from a project page, start a new chat, verify streaming works, switch between chats.

- [ ] **Step 3: Commit**

```bash
git add frontend/src/components/ChatDrawer.vue
git commit -m "feat: implement full ChatDrawer with chat list, new chat, streaming"
```

---

## Task 17: Wire Up Go SPA Serving + API Routing

**Files:**
- Modify: `cmd/server/main.go`

- [ ] **Step 1: Read current main.go**

Read `cmd/server/main.go` to understand the current routing setup.

- [ ] **Step 2: Restructure routing with /api/ prefix**

Update `cmd/server/main.go` to:
1. Register all handler routes under `/api/` prefix
2. Add `embed.FS` for the Vue dist directory
3. Use `SPAHandler` as the catch-all fallback

```go
//go:embed all:frontend/dist
var frontendFS embed.FS

func main() {
    // ... existing setup (config, db, queries, aiClient) ...

    mux := http.NewServeMux()

    // API routes
    mux.HandleFunc("/api/projects", dashboardHandler.ServeHTTP)
    mux.HandleFunc("/api/projects/", projectRouter) // dispatches to sub-handlers
    mux.HandleFunc("/api/settings", settingsHandler.Handle)

    // SPA fallback — serves Vue app for all non-API routes
    distFS, _ := fs.Sub(frontendFS, "frontend/dist")
    mux.Handle("/", handlers.SPAHandler(distFS))

    // ... existing server start ...
}
```

The project router function dispatches `/api/projects/{id}/brainstorm/...`, `/api/projects/{id}/pipeline/...`, etc. to their respective handlers — same pattern as current code but with `/api/` prefix.

- [ ] **Step 3: Verify SPA serving works in production mode**

```bash
cd frontend && npm run build
cd .. && make build
./marketminded
```

Navigate to `http://localhost:8080/` — should serve the Vue app. Navigate to `http://localhost:8080/projects/1` — should serve index.html (SPA fallback). API calls from the Vue app should work.

- [ ] **Step 4: Commit**

```bash
git add cmd/server/main.go
git commit -m "feat: wire up API routing with /api/ prefix and SPA fallback"
```

---

## Task 18: Update Makefile + Clean Up Old Frontend

**Files:**
- Modify: `Makefile`
- Delete: `web/templates/` (all files)
- Delete: `web/static/` (all files)
- Delete: `package.json` (root)
- Delete: `package-lock.json` (root)
- Delete: `tailwind.config.js` (root)

- [ ] **Step 1: Update Makefile**

Replace the Makefile build targets:

```makefile
# Replace existing css/generate/build targets with:

.PHONY: types dev build test test-frontend clean

types:
	tygo generate

dev:
	@echo "Starting dev servers..."
	@cd frontend && npm run dev &
	@make start

build: types
	cd frontend && npm run build
	go build -o marketminded ./cmd/server

test:
	go test ./...

test-frontend:
	cd frontend && npx vitest run

clean:
	rm -rf frontend/dist marketminded
```

Keep existing `start`, `restart` targets that run the Go binary.

- [ ] **Step 2: Delete old frontend files**

```bash
rm -rf web/templates/ web/static/
rm -f package.json package-lock.json tailwind.config.js
```

- [ ] **Step 3: Remove templ imports from handler files**

Search all handler files for `templates` imports and remove them. The handlers should now only import `encoding/json`, `net/http`, and internal packages.

- [ ] **Step 4: Verify clean build**

```bash
make build
```

Should complete successfully: tygo generates types → Vite builds frontend → Go compiles with embedded dist.

- [ ] **Step 5: Run all tests**

```bash
make test
make test-frontend
```

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "feat: remove old templ/Alpine frontend, update Makefile for Vue build"
```

---

## Task 19: Final Integration Testing

- [ ] **Step 1: Start the app and test every page**

```bash
make build && ./marketminded
```

Walk through every route:
- Dashboard: projects load, create project works
- Settings: load and save
- Project overview: displays correctly
- Profile: sections load, edit, generate with SSE
- Audience personas: list, create, delete
- Voice & Tone: profile displays
- Brainstorm: chat list, open chat, send message, SSE streaming
- Pipeline: create run, run steps with SSE, content pieces render, approve/reject
- Context: list, create, item detail with chat
- Context memory: form works
- Storytelling: framework selection
- Project settings: load and save
- Chat drawer: opens, new chat, streaming

- [ ] **Step 2: Fix any issues found during testing**

Address each issue as a separate fix.

- [ ] **Step 3: Final commit**

```bash
git add -A
git commit -m "fix: address integration testing issues"
```
