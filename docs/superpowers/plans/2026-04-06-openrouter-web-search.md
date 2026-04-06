# Replace Brave API with OpenRouter Web Search

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the Brave API dependency and use OpenRouter's built-in `web_search` server tool, eliminating a required API key and simplifying the codebase.

**Architecture:** OpenRouter's server tool (`{"type": "openrouter:web_search"}`) executes searches server-side — results are injected into the model's context automatically. Our code never sees tool_calls for search, so we remove all client-side search execution. The `ai.Tool` struct needs `Function` to be `omitempty` so server tools serialize correctly without an empty `function` field.

**Tech Stack:** Go, OpenRouter API

---

## File Map

| Action | File | Responsibility |
|--------|------|----------------|
| Modify | `internal/ai/tools.go:17-20` | Make `Tool.Function` omitempty for server tool compat |
| Delete | `internal/search/brave.go` | Brave API client — no longer needed |
| Delete | `internal/search/brave_test.go` | Tests for Brave client |
| Delete | `internal/tools/search.go` | `NewSearchTool`, `NewSearchExecutor`, `SearchSummary` — replaced by server tool |
| Modify | `internal/tools/registry.go` | Remove BraveClient dep, replace search tool with server tool, remove search executor |
| Modify | `internal/tools/registry_test.go` | Update tool count expectations, remove search name checks |
| Modify | `internal/config/config.go` | Remove `BraveAPIKey` field and requirement |
| Modify | `internal/config/config_test.go` | Remove `BRAVE_API_KEY` from test env setup |
| Modify | `internal/pipeline/steps/common.go` | Remove `web_search` from `onToolEvent` handler |
| Modify | `web/handlers/audience.go` | Remove BraveClient dep, replace search tool with server tool, drop search executor |
| Modify | `web/handlers/profile.go` | Remove BraveClient from ProfileHandler and AudienceHandler construction |
| Modify | `web/handlers/topic.go` | Remove BraveClient from TopicHandler |
| Modify | `cmd/server/main.go` | Remove BraveClient creation, remove from handler constructors |

---

### Task 1: Make ai.Tool support server tools

The `Tool` struct currently always serializes the `Function` field, producing `{"type":"openrouter:web_search","function":{"name":"","description":"","parameters":null}}`. OpenRouter needs `{"type":"openrouter:web_search"}` with no `function` key.

**Files:**
- Modify: `internal/ai/tools.go:17-20`

- [ ] **Step 1: Update Tool struct**

In `internal/ai/tools.go`, change the `Tool` struct to use a pointer for `Function` so it can be omitted:

```go
type Tool struct {
	Type     string        `json:"type"`
	Function *ToolFunction `json:"function,omitempty"`
}
```

- [ ] **Step 2: Update all Tool construction sites to use pointer**

Every place that creates an `ai.Tool` with a `Function` value needs `&ToolFunction{...}` instead of `ToolFunction{...}`. Files to update:

- `internal/tools/registry.go` — `submitTool()` function and `NewFetchTool()`
- `internal/tools/fetch.go` — `NewFetchTool()`
- `internal/tools/seo.go` — `NewKeywordResearchTool()`, `NewKeywordSuggestionsTool()`, `NewDomainKeywordsTool()`
- `internal/content/types.go` — content type tool definitions
- `web/handlers/audience.go` — `submitPersonasTool`
- `web/handlers/voice_tone.go` — `submitVoiceToneTool`
- `web/handlers/profile.go` — `submitTool` in `streamGenerate`

Every `ai.ToolFunction{` becomes `&ai.ToolFunction{`. Every `Function: ai.ToolFunction{` becomes `Function: &ai.ToolFunction{`.

Also update any code that reads `tool.Function.Name` to use `tool.Function.Name` (still works with pointer, but check for nil if needed).

- [ ] **Step 3: Add a helper for creating server tools**

In `internal/ai/tools.go`, add:

```go
// ServerTool creates an OpenRouter server tool (e.g. "openrouter:web_search").
func ServerTool(toolType string) Tool {
	return Tool{Type: toolType}
}
```

- [ ] **Step 4: Build and test**

Run: `go build ./... && go test ./...`
Expected: All pass. No behavior change yet — just structural.

- [ ] **Step 5: Commit**

```
git add internal/ai/tools.go internal/tools/ internal/content/types.go web/handlers/audience.go web/handlers/voice_tone.go web/handlers/profile.go
git commit -m "refactor: make ai.Tool.Function a pointer for server tool support"
```

---

### Task 2: Add OpenRouter web search to tool registry, remove Brave

Replace the custom `web_search` function tool with the OpenRouter server tool in the registry and remove all Brave dependencies.

**Files:**
- Modify: `internal/tools/registry.go`
- Modify: `internal/tools/registry_test.go`
- Delete: `internal/tools/search.go`

- [ ] **Step 1: Update registry.go**

Replace the entire file content. Key changes:
- Remove `search` import
- Remove `braveClient` field from Registry struct
- `NewRegistry()` takes no args (remove `braveClient *search.BraveClient` param)
- Replace `searchTool := NewSearchTool()` with `searchTool := ai.ServerTool("openrouter:web_search")`
- Remove `web_search` case from `Execute()` — server tools never hit our executor
- Remove `search` import

```go
package tools

import (
	"context"
	"encoding/json"
	"fmt"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/content"
)

type Registry struct {
	stepTools map[string][]ai.Tool
}

func NewRegistry() *Registry {
	r := &Registry{
		stepTools: make(map[string][]ai.Tool),
	}

	fetchTool := NewFetchTool()
	searchTool := ai.ServerTool("openrouter:web_search")

	r.stepTools["research"] = []ai.Tool{fetchTool, searchTool, submitTool(
		// ... keep existing submit tool definitions unchanged ...
	)}

	r.stepTools["brand_enricher"] = []ai.Tool{fetchTool, submitTool(
		// ... unchanged ...
	)}

	r.stepTools["factcheck"] = []ai.Tool{fetchTool, searchTool, submitTool(
		// ... unchanged ...
	)}

	r.stepTools["editor"] = []ai.Tool{submitTool(
		// ... unchanged ...
	)}

	r.stepTools["topic_explore"] = []ai.Tool{fetchTool, searchTool, submitTool(
		// ... unchanged ...
	)}

	r.stepTools["topic_review"] = []ai.Tool{submitTool(
		// ... unchanged ...
	)}

	ct, ok := content.LookupType("blog", "post")
	if ok {
		r.stepTools["write"] = []ai.Tool{ct.Tool}
	}

	return r
}

func (r *Registry) ForStep(stepType string) []ai.Tool {
	return r.stepTools[stepType]
}

func (r *Registry) Execute(ctx context.Context, name, argsJSON string) (string, error) {
	switch name {
	case "fetch_url":
		return ExecuteFetch(ctx, argsJSON)
	default:
		return "", fmt.Errorf("unknown tool: %s", name)
	}
}

func submitTool(name, description, paramsJSON string) ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: &ai.ToolFunction{
			Name:        name,
			Description: description,
			Parameters:  json.RawMessage(paramsJSON),
		},
	}
}
```

- [ ] **Step 2: Update registry_test.go**

The research step now has 3 tools but `web_search` is a server tool (no `Function.Name`). Update test:

```go
func TestRegistry_ForStep(t *testing.T) {
	r := tools.NewRegistry()

	researchTools := r.ForStep("research")
	if len(researchTools) != 3 {
		t.Fatalf("expected 3 tools for research, got %d", len(researchTools))
	}

	// Check that the server tool is present
	hasServerSearch := false
	for _, tool := range researchTools {
		if tool.Type == "openrouter:web_search" {
			hasServerSearch = true
		}
	}
	if !hasServerSearch {
		t.Error("expected openrouter:web_search server tool in research step")
	}
}

func TestRegistry_ForStep_Writer(t *testing.T) {
	r := tools.NewRegistry()
	writerTools := r.ForStep("write")
	if len(writerTools) != 1 {
		t.Fatalf("expected 1 tool for write, got %d", len(writerTools))
	}
}

func TestRegistry_Execute_UnknownTool(t *testing.T) {
	r := tools.NewRegistry()
	_, err := r.Execute(context.Background(), "nonexistent_tool", "{}")
	if err == nil {
		t.Error("expected error for unknown tool")
	}
}
```

- [ ] **Step 3: Delete search.go**

```bash
rm internal/tools/search.go
```

- [ ] **Step 4: Build and test**

Run: `go build ./internal/tools/... && go test ./internal/tools/...`
Expected: Pass. Note — `cmd/` and `web/handlers/` will not compile yet (they still reference BraveClient and `NewRegistry(braveClient)`).

- [ ] **Step 5: Commit**

```
git add internal/tools/
git commit -m "feat: replace Brave search tool with OpenRouter server tool in registry"
```

---

### Task 3: Remove Brave from config

**Files:**
- Modify: `internal/config/config.go`
- Modify: `internal/config/config_test.go`

- [ ] **Step 1: Update config.go**

Remove `BraveAPIKey` field and its env var requirement:

```go
type Config struct {
	Port               string
	DBPath             string
	OpenRouterAPIKey   string
	DataForSEOLogin    string
	DataForSEOPassword string
	ModelContent       string
	ModelCopywriting   string
	ModelIdeation      string
}

func Load() (*Config, error) {
	orKey := os.Getenv("OPENROUTER_API_KEY")

	if orKey == "" {
		return nil, fmt.Errorf("OPENROUTER_API_KEY is required")
	}

	return &Config{
		Port:               envOr("MARKETMINDED_PORT", "8080"),
		DBPath:             envOr("MARKETMINDED_DB_PATH", "./marketminded.db"),
		OpenRouterAPIKey:   orKey,
		DataForSEOLogin:    os.Getenv("DATAFORSEO_LOGIN"),
		DataForSEOPassword: os.Getenv("DATAFORSEO_PASSWORD"),
		ModelContent:       envOr("MARKETMINDED_MODEL_CONTENT", "x-ai/grok-4.1-fast"),
		ModelCopywriting:   envOr("MARKETMINDED_MODEL_COPYWRITING", "x-ai/grok-4.1-fast"),
		ModelIdeation:      envOr("MARKETMINDED_MODEL_IDEATION", "x-ai/grok-4.1-fast"),
	}, nil
}
```

- [ ] **Step 2: Update config_test.go**

Remove `BRAVE_API_KEY` from test setup:

```go
func TestLoad_Defaults(t *testing.T) {
	t.Setenv("OPENROUTER_API_KEY", "test-key")

	cfg, err := Load()
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if cfg.Port != "8080" {
		t.Errorf("expected port 8080, got %s", cfg.Port)
	}
	if cfg.OpenRouterAPIKey != "test-key" {
		t.Errorf("expected test-key, got %s", cfg.OpenRouterAPIKey)
	}
}

func TestLoad_MissingRequiredKeys(t *testing.T) {
	t.Setenv("OPENROUTER_API_KEY", "")

	_, err := Load()
	if err == nil {
		t.Fatal("expected error for missing API key")
	}
}
```

- [ ] **Step 3: Build and test**

Run: `go test ./internal/config/...`
Expected: Pass.

- [ ] **Step 4: Commit**

```
git add internal/config/
git commit -m "refactor: remove BRAVE_API_KEY from config"
```

---

### Task 4: Remove Brave from handlers and main.go

Remove BraveClient from all handler constructors and the audience handler's custom search executor.

**Files:**
- Modify: `web/handlers/audience.go`
- Modify: `web/handlers/profile.go`
- Modify: `web/handlers/topic.go`
- Modify: `cmd/server/main.go`

- [ ] **Step 1: Update audience.go**

Remove `braveClient` field, remove from constructor, replace search tool and executor:

```go
type AudienceHandler struct {
	queries  *store.Queries
	aiClient *ai.Client
	model    func() string
}

func NewAudienceHandler(q *store.Queries, aiClient *ai.Client, model func() string) *AudienceHandler {
	return &AudienceHandler{queries: q, aiClient: aiClient, model: model}
}
```

In `streamGenerate`, replace the tool list and executor:

```go
toolList := []ai.Tool{
    ai.ServerTool("openrouter:web_search"),
    submitPersonasTool,
}

var submittedResult string

executor := func(ctx context.Context, name, args string) (string, error) {
    switch name {
    case "submit_personas":
        submittedResult = args
        return "Personas submitted successfully.", ai.ErrToolDone
    default:
        return "", fmt.Errorf("unknown tool: %s", name)
    }
}
```

Update `onToolEvent` to remove the `web_search` summary case (server tools don't produce tool events):

```go
onToolEvent := func(event ai.ToolEvent) {
    switch event.Type {
    case "tool_start":
        summary := ""
        if event.Tool == "submit_personas" {
            summary = "Submitting personas..."
        }
        if summary != "" {
            stream.SendData(map[string]string{"type": "status", "status": summary})
        }
    }
}
```

Remove the `search` import. Remove the `tools` import if no longer used (check — `SearchSummary` was the only usage).

- [ ] **Step 2: Update profile.go**

Remove `braveClient` from `ProfileHandler` and its constructor:

```go
type ProfileHandler struct {
	queries          *store.Queries
	aiClient         *ai.Client
	model            func() string
	audienceHandler  *AudienceHandler
	voiceToneHandler *VoiceToneHandler
}

func NewProfileHandler(q *store.Queries, aiClient *ai.Client, model func() string) *ProfileHandler {
	return &ProfileHandler{
		queries:          q,
		aiClient:         aiClient,
		model:            model,
		audienceHandler:  NewAudienceHandler(q, aiClient, model),
		voiceToneHandler: NewVoiceToneHandler(q, aiClient, model),
	}
}
```

Remove the `search` import.

- [ ] **Step 3: Update topic.go**

Remove `braveClient` from `TopicHandler` and its constructor:

```go
type TopicHandler struct {
	queries       *store.Queries
	aiClient      *ai.Client
	toolRegistry  *tools.Registry
	promptBuilder *prompt.Builder
	model         func() string
}

func NewTopicHandler(q *store.Queries, aiClient *ai.Client, toolRegistry *tools.Registry, promptBuilder *prompt.Builder, model func() string) *TopicHandler {
	return &TopicHandler{queries: q, aiClient: aiClient, toolRegistry: toolRegistry, promptBuilder: promptBuilder, model: model}
}
```

Remove the `search` import.

- [ ] **Step 4: Update main.go**

Remove BraveClient creation and all references:

```go
// Remove this line:
// braveClient := search.NewBraveClient(cfg.BraveAPIKey)

// Change tool registry:
toolRegistry := tools.NewRegistry()  // no args

// Change handler constructors:
profileHandler := handlers.NewProfileHandler(queries, aiClient, contentModel)
topicHandler := handlers.NewTopicHandler(queries, aiClient, toolRegistry, promptBuilder, ideationModel)
```

Remove the `search` import.

- [ ] **Step 5: Build and test**

Run: `go build ./... && go test ./...`
Expected: All pass. The `search` package is now unused.

- [ ] **Step 6: Commit**

```
git add web/handlers/ cmd/server/main.go
git commit -m "refactor: remove BraveClient from handlers and main.go"
```

---

### Task 5: Remove web_search from pipeline event handlers

The `onToolEvent` in `common.go` currently handles `web_search` tool_start events to show UI status. Since OpenRouter server tools don't produce these events, remove the dead code.

**Files:**
- Modify: `internal/pipeline/steps/common.go`

- [ ] **Step 1: Clean up onToolEvent in common.go**

Remove the `web_search` cases from the `onToolEvent` handler. The tool event handler should only handle `fetch_url` now:

```go
onToolEvent := func(event ai.ToolEvent) {
    switch event.Type {
    case "tool_start":
        if event.Tool == submitToolName {
            return
        }
        summary := ""
        if event.Tool == "fetch_url" {
            summary = tools.FetchSummary(event.Args)
            var args struct{ URL string `json:"url"` }
            if json.Unmarshal([]byte(event.Args), &args) == nil && args.URL != "" {
                toolCallsList = append(toolCallsList, pipeline.ToolCallRecord{Type: "fetch", Value: args.URL})
            }
        }
        evt := map[string]string{"type": "tool_start", "tool": event.Tool, "summary": summary}
        if event.Tool == "fetch_url" {
            var a struct{ URL string `json:"url"` }
            if json.Unmarshal([]byte(event.Args), &a) == nil {
                evt["url"] = a.URL
            }
        }
        stream.SendEvent(evt)
    case "tool_result":
        summary := event.Summary
        if len(summary) > 200 {
            summary = summary[:200] + "..."
        }
        stream.SendEvent(map[string]string{"type": "tool_result", "tool": event.Tool, "summary": summary})
    }
}
```

Also remove the `tools.SearchSummary` import usage — check if `tools` import is still needed (yes, for `FetchSummary`).

- [ ] **Step 2: Build and test**

Run: `go build ./... && go test ./...`
Expected: All pass.

- [ ] **Step 3: Commit**

```
git add internal/pipeline/steps/common.go
git commit -m "refactor: remove web_search event handling from pipeline steps"
```

---

### Task 6: Delete the search package and clean up

**Files:**
- Delete: `internal/search/brave.go`
- Delete: `internal/search/brave_test.go`

- [ ] **Step 1: Delete search package**

```bash
rm -r internal/search/
```

- [ ] **Step 2: Run go mod tidy**

```bash
go mod tidy
```

- [ ] **Step 3: Full build and test**

Run: `go build ./... && go test ./...`
Expected: All pass. No references to `search` package remain.

- [ ] **Step 4: Commit**

```
git add -A
git commit -m "chore: delete Brave search package, clean up go.mod"
```

---

### Task 7: Final verification

- [ ] **Step 1: Verify no Brave references remain**

```bash
grep -r "brave\|Brave\|BRAVE" --include="*.go" .
grep -r "brave\|Brave\|BRAVE" --include="*.env*" .
```

Expected: No results (or only in docs/git history).

- [ ] **Step 2: Verify no search.go references remain**

```bash
grep -r "NewSearchTool\|NewSearchExecutor\|SearchSummary\|BraveClient" --include="*.go" .
```

Expected: No results.

- [ ] **Step 3: Run full test suite**

Run: `go test ./...`
Expected: All pass.

- [ ] **Step 4: Final commit (squash if desired)**

All tasks are complete. The codebase no longer depends on Brave API. Users only need `OPENROUTER_API_KEY`.
