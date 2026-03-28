# Backend Maintainability Refactor — Design Spec

**Date:** 2026-03-28
**Branch:** `refactor/backend-maintainability`
**Goal:** Extract business logic from HTTP handlers into testable service layers, clean up dead code, and establish clean architecture boundaries so the upcoming DaisyUI migration only touches the handler/template layer.

## Context

The codebase has solid domain modeling but poor separation of concerns. The primary symptom is `web/handlers/pipeline.go` at 1700 lines — a god file that handles step orchestration, SSE streaming, tool execution, prompt building, and content creation. Other handlers (`brainstorm.go`, `profile.go`) have similar but less severe coupling issues.

This is a "rebirth" branch. Dead code gets deleted. No backward compatibility constraints.

## Decisions

- **Pipeline steps stay as-is:** 6 sequential steps (research, brand_enricher, factcheck, tone_analyzer, editor, write) — no changes to step types or ordering.
- **Keep templ + DaisyUI:** Server-rendered templates with DaisyUI classes. No Vue/SPA.
- **Keep SSE:** Server-sent events for real-time step streaming. No WebSockets.
- **Domain store interfaces:** Split the monolithic `Queries` struct into domain-specific interfaces for testability.
- **Delete aggressively:** `internal/agents/`, unused store methods, legacy templates — anything not actively used.

## Package Structure

```
internal/
├── ai/                    # KEEP — OpenRouter client, StreamWithTools loop
│   ├── client.go
│   └── tools.go
├── config/                # KEEP — env var parsing
├── content/               # KEEP — content type registry + prompt file paths
├── pipeline/              # NEW — core business logic
│   ├── orchestrator.go    # Step dependency resolution & dispatch
│   ├── runner.go          # StepRunner interface, StepInput, StepStream
│   └── steps/             # One file per step type
│       ├── research.go
│       ├── brand_enricher.go
│       ├── factcheck.go
│       ├── tone_analyzer.go
│       ├── editor.go
│       └── writer.go
├── prompt/                # NEW — prompt assembly
│   └── builder.go         # Profile context, system prompts, anti-AI rules
├── render/                # KEEP — PNG renderer
├── search/                # KEEP — Brave client
├── sse/                   # NEW — reusable SSE helper
│   └── stream.go
├── store/                 # REFACTORED — interfaces + SQLite implementations
│   ├── db.go              # SQLite setup, migrations (keep)
│   ├── interfaces.go      # PipelineStore, ContentStore, ProfileStore, etc.
│   ├── types.go           # Shared types: PipelineRun, PipelineStep, ContentPiece, etc.
│   └── sqlite/            # Interface implementations
│       ├── store.go       # SQLiteStore struct, implements all interfaces
│       ├── pipeline.go
│       ├── content.go
│       ├── profile.go
│       ├── settings.go
│       ├── brainstorm.go
│       ├── context.go
│       └── projects.go
├── tools/                 # REFACTORED — add registry
│   ├── registry.go        # Tool factory/registry
│   ├── fetch.go           # Keep
│   └── search.go          # Keep
└── types/                 # KEEP — shared domain types

web/
├── handlers/              # REFACTORED — thin HTTP adapters
│   ├── pipeline.go        # ~200 lines (down from 1700)
│   ├── brainstorm.go
│   ├── profile.go
│   ├── ... (other handlers stay similar size)
└── templates/             # UNCHANGED — templ files untouched (DaisyUI is a separate effort)
```

**Deleted packages:**
- `internal/agents/` — dead code; logic moves to `pipeline/steps/`

## Core Interfaces

### StepRunner

The central abstraction. Each pipeline step implements this interface.

```go
// internal/pipeline/runner.go

type StepRunner interface {
    Type() string
    Run(ctx context.Context, input StepInput, stream StepStream) error
}

type StepInput struct {
    ProjectID    int64
    RunID        int64
    StepID       int64
    Topic        string
    Profile      string            // pre-built profile context string
    PriorOutputs map[string]string // stepType -> JSON output from completed steps
}

type StepStream interface {
    SendText(chunk string) error
    SendThinking(chunk string) error
    SendToolStart(name, args string) error
    SendToolResult(name, result string) error
    SendError(msg string) error
    Done()
}
```

### Orchestrator

Manages step dependencies and dispatching. The only component that knows the full pipeline topology.

```go
// internal/pipeline/orchestrator.go

type Orchestrator struct {
    steps  map[string]StepRunner
    store  store.PipelineStore
    prompt *prompt.Builder
}

func NewOrchestrator(
    store store.PipelineStore,
    prompt *prompt.Builder,
    steps ...StepRunner,
) *Orchestrator

func (o *Orchestrator) RunStep(ctx context.Context, stepID int64, stream StepStream) error
```

`RunStep` does:
1. Load step from store, verify status is pending/failed
2. Call `TrySetStepRunning()` for atomic lock
3. Load prior step outputs from store
4. Build `StepInput` using prompt builder + prior outputs
5. Dispatch to the correct `StepRunner`
6. On success: call `store.UpdateStepOutput()` with the result
7. On failure: mark step as failed with error message

### Store Interfaces

```go
// internal/store/interfaces.go

type PipelineStore interface {
    CreateRun(ctx context.Context, projectID int64, topic string) (PipelineRun, error)
    GetRun(ctx context.Context, id int64) (PipelineRun, error)
    GetRunsForProject(ctx context.Context, projectID int64) ([]PipelineRun, error)
    TrySetStepRunning(ctx context.Context, stepID int64) (bool, error)
    UpdateStepOutput(ctx context.Context, stepID int64, output, thinking string) error
    SetStepStatus(ctx context.Context, stepID int64, status string) error
    GetStepsForRun(ctx context.Context, runID int64) ([]PipelineStep, error)
    GetStep(ctx context.Context, stepID int64) (PipelineStep, error)
}

type ContentStore interface {
    CreatePiece(ctx context.Context, params CreatePieceParams) (ContentPiece, error)
    GetPiece(ctx context.Context, id int64) (ContentPiece, error)
    GetPiecesForRun(ctx context.Context, runID int64) ([]ContentPiece, error)
    SetPieceStatus(ctx context.Context, id int64, status string) error
    UpdatePiece(ctx context.Context, id int64, title, body string) error
}

type ProfileStore interface {
    GetSections(ctx context.Context, projectID int64) ([]ProfileSection, error)
    UpsertSection(ctx context.Context, projectID int64, section, content string) error
    BuildProfileString(ctx context.Context, projectID int64, exclude []string) (string, error)
}

type ProjectStore interface {
    Create(ctx context.Context, name, description string) (Project, error)
    Get(ctx context.Context, id int64) (Project, error)
    List(ctx context.Context) ([]Project, error)
    Delete(ctx context.Context, id int64) error
}

type SettingsStore interface {
    Get(ctx context.Context, key string) (string, error)
    Set(ctx context.Context, key, value string) error
    GetAll(ctx context.Context) (map[string]string, error)
}

type BrainstormStore interface {
    CreateChat(ctx context.Context, pieceID int64) (BrainstormChat, error)
    AddMessage(ctx context.Context, chatID int64, role, content string) error
    GetMessages(ctx context.Context, chatID int64) ([]BrainstormMessage, error)
}

type ContextStore interface {
    Add(ctx context.Context, projectID int64, title, content string) (ContextItem, error)
    List(ctx context.Context, projectID int64) ([]ContextItem, error)
    Delete(ctx context.Context, id int64) error
}
```

One `SQLiteStore` struct in `store/sqlite/` implements all interfaces. Services accept only the interfaces they need.

## Step Implementation Pattern

Each step is ~50-80 lines. Example:

```go
// internal/pipeline/steps/research.go

type ResearchStep struct {
    ai    *ai.Client
    tools *tools.Registry
    model func() string
}

func (s *ResearchStep) Type() string { return "research" }

func (s *ResearchStep) Run(ctx context.Context, input StepInput, stream StepStream) error {
    toolSet := s.tools.ForStep("research")

    executor := func(ctx context.Context, name, args string) (string, error) {
        if name == "submit_research" {
            return "OK", ai.ErrToolDone
        }
        return s.tools.Execute(ctx, name, args)
    }

    return s.ai.StreamWithTools(ctx, ai.StreamParams{
        Model:         s.model(),
        System:        input.Profile, // orchestrator pre-builds the full system prompt
        Tools:         toolSet,
        Temperature:   0.3,
        MaxIterations: 25,
        Executor:      executor,
        OnText:        stream.SendText,
        OnThinking:    stream.SendThinking,
        OnToolStart:   stream.SendToolStart,
        OnToolResult:  stream.SendToolResult,
    })
}
```

**Writer step** is the only one with extra logic — its submit tool creates a `ContentPiece` via `ContentStore`:

```go
// internal/pipeline/steps/writer.go

type WriterStep struct {
    ai      *ai.Client
    tools   *tools.Registry
    content store.ContentStore
    model   func() string
}

func (s *WriterStep) Run(ctx context.Context, input StepInput, stream StepStream) error {
    executor := func(ctx context.Context, name, args string) (string, error) {
        if name == "write_blog_post" {
            // Parse args, create content piece
            piece, err := s.content.CreatePiece(ctx, store.CreatePieceParams{...})
            if err != nil { return "", err }
            return fmt.Sprintf("Created piece %d", piece.ID), ai.ErrToolDone
        }
        return s.tools.Execute(ctx, name, args)
    }
    // ... StreamWithTools call
}
```

## SSE Helper

```go
// internal/sse/stream.go

type Stream struct {
    w       http.ResponseWriter
    flusher http.Flusher
}

func New(w http.ResponseWriter) (*Stream, error)
// Sets Content-Type: text/event-stream, Cache-Control, Connection headers
// Returns error if ResponseWriter doesn't support Flusher

func (s *Stream) Send(event, data string) error
func (s *Stream) SendJSON(event string, v any) error
func (s *Stream) Close()
```

## Prompt Builder

```go
// internal/prompt/builder.go

type Builder struct {
    profile store.ProfileStore
    prompts map[string]string // step type -> prompt content, loaded at startup
}

func NewBuilder(profile store.ProfileStore, promptDir string) (*Builder, error)
// Loads all prompt files at startup. Returns error if any are missing.

func (b *Builder) ForStep(ctx context.Context, stepType string, input StepInput) (string, error)
// Assembles: date + step prompt + profile context + topic + anti-AI rules

func (b *Builder) ForContent(ctx context.Context, contentType string, input ContentInput) (string, error)
// Assembles content generation prompts
```

Eliminates scattered `BuildProfileStringExcluding()` calls and runtime prompt file loading. Fails fast at startup if prompt files are missing.

## Tool Registry

```go
// internal/tools/registry.go

type Registry struct {
    fetch  *FetchTool
    search *search.BraveClient
    defs   map[string][]ai.Tool // step type -> tool definitions
}

func NewRegistry(braveClient *search.BraveClient) *Registry

func (r *Registry) ForStep(stepType string) []ai.Tool
// Returns tool definitions for a step (fetch_url, web_search, submit_*)

func (r *Registry) Execute(ctx context.Context, name, args string) (string, error)
// Routes fetch_url -> FetchTool, web_search -> BraveClient
// Returns error for unknown tool names (no silent failures)
```

Submit tools are **defined** in the registry (so they appear in tool lists) but **executed** in each step's executor (because submit handling is step-specific logic).

## Handler Wiring

`pipeline.go` shrinks from 1700 lines to ~200:

```go
// web/handlers/pipeline.go

type PipelineHandler struct {
    orchestrator *pipeline.Orchestrator
    pipeline     store.PipelineStore
    content      store.ContentStore
}

func (h *PipelineHandler) StreamStep(w http.ResponseWriter, r *http.Request) {
    stepID := parseID(r, "stepId")

    sseStream, err := sse.New(w)
    if err != nil { http.Error(...); return }
    defer sseStream.Close()

    stream := &httpStepStream{sse: sseStream}

    if err := h.orchestrator.RunStep(r.Context(), stepID, stream); err != nil {
        sseStream.Send("error", err.Error())
    }
}

// httpStepStream adapts sse.Stream to pipeline.StepStream
type httpStepStream struct { sse *sse.Stream }
func (s *httpStepStream) SendText(chunk string) error    { return s.sse.Send("chunk", chunk) }
func (s *httpStepStream) SendThinking(chunk string) error { return s.sse.Send("thinking", chunk) }
// ... etc
```

## Dependency Injection (main.go)

```go
// cmd/server/main.go

func main() {
    cfg := config.Load()
    db := store.OpenDB(cfg.DBPath)
    sqliteStore := sqlite.New(db)

    aiClient := ai.NewClient(cfg.OpenRouterKey)
    braveClient := search.NewBraveClient(cfg.BraveKey)

    promptBuilder, err := prompt.NewBuilder(sqliteStore, "prompts/")
    // fail fast if prompts missing

    toolRegistry := tools.NewRegistry(braveClient)

    orchestrator := pipeline.NewOrchestrator(
        sqliteStore, // PipelineStore
        promptBuilder,
        &steps.ResearchStep{AI: aiClient, Tools: toolRegistry, Model: cfg.Model},
        &steps.BrandEnricherStep{AI: aiClient, Tools: toolRegistry, Model: cfg.Model},
        &steps.FactcheckStep{AI: aiClient, Tools: toolRegistry, Model: cfg.Model},
        &steps.ToneAnalyzerStep{AI: aiClient, Tools: toolRegistry, Model: cfg.Model},
        &steps.EditorStep{AI: aiClient, Tools: toolRegistry, Model: cfg.Model},
        &steps.WriterStep{AI: aiClient, Tools: toolRegistry, Content: sqliteStore, Model: cfg.WriterModel},
    )

    pipelineHandler := handlers.NewPipelineHandler(orchestrator, sqliteStore, sqliteStore)
    // ... register routes
}
```

## Cleanup Targets

| Target | Action | Reason |
|--------|--------|--------|
| `internal/agents/` | Investigate, likely delete | Logic moves to `pipeline/steps/` |
| `internal/store/agent_runs.go` | Delete if unused | Legacy logging |
| `internal/store/templates.go` | Delete if unused | Legacy feature |
| Unused handler routes | Delete | Dead endpoints |
| `internal/types/types.go` | Merge into `store/types.go` if redundant | Consolidate types |

## What Stays Unchanged

- `internal/ai/` — clean client and tool loop
- `internal/config/` — simple env var parsing
- `internal/content/` — well-designed type registry
- `internal/search/` — clean Brave client
- `internal/render/` — unrelated PNG renderer
- `migrations/` — no schema changes
- `prompts/` — files stay, loading mechanism changes
- `web/templates/` — templ files untouched

## Testing Strategy

The refactor enables testing that's currently impossible:

- **Step unit tests:** Mock `StepStream`, test each step's tool routing and output parsing
- **Orchestrator tests:** Mock `PipelineStore`, test dependency resolution and error handling
- **Store tests:** Already exist, just reorganized into `sqlite/` package
- **Integration tests:** Wire real `SQLiteStore` + mock `ai.Client` to test full pipeline flow

## Minor API Changes

- `ai.StreamWithTools()` currently takes individual parameters. Refactor to accept a `StreamParams` struct for cleaner call sites. This is an internal API change with no external impact.
- `ai.StreamWithTools()` callback signatures may need slight adjustment to match `StepStream` interface (e.g., `OnToolStart` taking both name and args).

## Out of Scope

- DaisyUI styling (separate effort after this refactor)
- New pipeline steps or step reordering
- Schema changes
- WebSocket migration
- Content piece review/approval flow changes (handlers for approve/reject stay in handler layer)
