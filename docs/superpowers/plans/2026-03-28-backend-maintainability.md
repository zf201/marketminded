# Backend Maintainability Refactor — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Extract all business logic from `web/handlers/pipeline.go` (1707 lines) into testable service layers, clean up dead code, and establish clean architecture boundaries.

**Architecture:** New `internal/pipeline/` package with `Orchestrator` + per-step `StepRunner` implementations. Store layer split into domain interfaces backed by SQLite. SSE streaming extracted into reusable helper. Prompt assembly centralized in `internal/prompt/`.

**Tech Stack:** Go, SQLite, templ, OpenRouter API, Brave Search API

---

### Task 1: Create Branch and Delete Dead Code

**Files:**
- Delete: `internal/agents/content.go`
- Delete: `internal/agents/idea.go`
- Delete: `internal/agents/content_test.go`
- Delete: `internal/agents/idea_test.go`
- Delete: `internal/agents/testing_helpers_test.go`
- Delete: `internal/store/agent_runs.go`
- Delete: `internal/store/agent_runs_test.go`

- [ ] **Step 1: Create the refactor branch**

```bash
git checkout -b refactor/backend-maintainability
```

- [ ] **Step 2: Delete dead agent code**

The `internal/agents/` package is never imported by any handler. Delete the entire directory:

```bash
rm -rf internal/agents/
```

- [ ] **Step 3: Delete dead agent_runs store code**

`CreateAgentRun` and `ListAgentRuns` are never called from handlers — only from their own test file.

```bash
rm internal/store/agent_runs.go internal/store/agent_runs_test.go
```

- [ ] **Step 4: Verify the build still compiles**

Run: `go build ./...`
Expected: SUCCESS (no compilation errors)

- [ ] **Step 5: Run existing tests**

Run: `go test ./...`
Expected: PASS (agent tests removed, other tests unaffected)

- [ ] **Step 6: Commit**

```bash
git add -A
git commit -m "chore: delete dead agents/ and agent_runs code"
```

---

### Task 2: Create SSE Helper Package

**Files:**
- Create: `internal/sse/stream.go`
- Create: `internal/sse/stream_test.go`

- [ ] **Step 1: Write the failing test**

Create `internal/sse/stream_test.go`:

```go
package sse_test

import (
	"net/http/httptest"
	"strings"
	"testing"

	"github.com/zanfridau/marketminded/internal/sse"
)

func TestStream_Send(t *testing.T) {
	w := httptest.NewRecorder()
	s, err := sse.New(w)
	if err != nil {
		t.Fatal(err)
	}

	s.Send("chunk", `{"type":"chunk","chunk":"hello"}`)
	s.Close()

	body := w.Body.String()
	if !strings.Contains(body, `event: chunk`) {
		t.Errorf("expected event line, got: %s", body)
	}
	if !strings.Contains(body, `data: {"type":"chunk","chunk":"hello"}`) {
		t.Errorf("expected data line, got: %s", body)
	}
}

func TestStream_SendJSON(t *testing.T) {
	w := httptest.NewRecorder()
	s, err := sse.New(w)
	if err != nil {
		t.Fatal(err)
	}

	s.SendJSON("tool_start", map[string]string{"tool": "fetch_url"})
	s.Close()

	body := w.Body.String()
	if !strings.Contains(body, `"tool":"fetch_url"`) {
		t.Errorf("expected JSON payload, got: %s", body)
	}
}

func TestStream_Headers(t *testing.T) {
	w := httptest.NewRecorder()
	_, err := sse.New(w)
	if err != nil {
		t.Fatal(err)
	}

	ct := w.Header().Get("Content-Type")
	if ct != "text/event-stream" {
		t.Errorf("expected text/event-stream, got: %s", ct)
	}
	cc := w.Header().Get("Cache-Control")
	if cc != "no-cache" {
		t.Errorf("expected no-cache, got: %s", cc)
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `go test ./internal/sse/... -v`
Expected: FAIL (package does not exist)

- [ ] **Step 3: Write the implementation**

Create `internal/sse/stream.go`:

```go
package sse

import (
	"encoding/json"
	"fmt"
	"net/http"
)

// Stream provides Server-Sent Events over an http.ResponseWriter.
type Stream struct {
	w       http.ResponseWriter
	flusher http.Flusher
}

// New creates a Stream, setting the required SSE headers.
// Returns an error if the ResponseWriter does not support flushing.
func New(w http.ResponseWriter) (*Stream, error) {
	flusher, ok := w.(http.Flusher)
	if !ok {
		return nil, fmt.Errorf("streaming not supported")
	}

	w.Header().Set("Content-Type", "text/event-stream")
	w.Header().Set("Cache-Control", "no-cache")
	w.Header().Set("Connection", "keep-alive")

	return &Stream{w: w, flusher: flusher}, nil
}

// Send writes a named event with a string data payload.
func (s *Stream) Send(event, data string) {
	fmt.Fprintf(s.w, "event: %s\ndata: %s\n\n", event, data)
	s.flusher.Flush()
}

// SendJSON writes a named event with a JSON-encoded data payload.
func (s *Stream) SendJSON(event string, v any) {
	data, _ := json.Marshal(v)
	fmt.Fprintf(s.w, "event: %s\ndata: %s\n\n", event, string(data))
	s.flusher.Flush()
}

// SendData writes an unnamed event (just "data:" line), matching the
// current pipeline handler pattern where the frontend parses the type from JSON.
func (s *Stream) SendData(v any) {
	data, _ := json.Marshal(v)
	fmt.Fprintf(s.w, "data: %s\n\n", string(data))
	s.flusher.Flush()
}

// Close is a no-op placeholder for future cleanup.
func (s *Stream) Close() {}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `go test ./internal/sse/... -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add internal/sse/
git commit -m "feat: add internal/sse package for SSE streaming"
```

---

### Task 3: Create Store Interfaces

**Files:**
- Create: `internal/store/interfaces.go`

This task defines the domain interfaces. The existing `Queries` struct already satisfies them — we're just making the contracts explicit so services can depend on interfaces, not the concrete struct.

- [ ] **Step 1: Write the interfaces file**

Create `internal/store/interfaces.go`:

```go
package store

// PipelineStore handles pipeline runs and steps.
type PipelineStore interface {
	CreatePipelineRun(projectID int64, topic string) (*PipelineRun, error)
	GetPipelineRun(id int64) (*PipelineRun, error)
	ListPipelineRuns(projectID int64) ([]PipelineRun, error)
	UpdatePipelineTopic(id int64, topic string) error
	UpdatePipelineStatus(id int64, status string) error
	UpdatePipelinePlan(id int64, plan string) error
	UpdatePipelinePhase(id int64, phase string) error
	DeletePipelineRun(id int64) error
	CreatePipelineStep(pipelineRunID int64, stepType string, sortOrder int) (*PipelineStep, error)
	GetPipelineStep(id int64) (*PipelineStep, error)
	ListPipelineSteps(pipelineRunID int64) ([]PipelineStep, error)
	TrySetStepRunning(id int64) (bool, error)
	UpdatePipelineStepStatus(id int64, status string) error
	UpdatePipelineStepOutput(id int64, output, thinking string) error
	UpdatePipelineStepInput(id int64, input string) error
	UpdatePipelineStepToolCalls(id int64, toolCalls string) error
}

// ContentStore handles content pieces.
type ContentStore interface {
	CreateContentPiece(projectID, pipelineRunID int64, platform, format, title string, sortOrder int, parentID *int64) (*ContentPiece, error)
	GetContentPiece(id int64) (*ContentPiece, error)
	ListContentByPipelineRun(runID int64) ([]ContentPiece, error)
	NextPendingPiece(runID int64) (*ContentPiece, error)
	UpdateContentPieceBody(id int64, title, body string) error
	SetContentPieceStatus(id int64, status string) error
	SetContentPieceRejection(id int64, reason string) error
	TrySetGenerating(id int64) (bool, error)
	AllPiecesApproved(runID int64) (bool, error)
}

// ProfileStore handles brand profile sections and string building.
type ProfileStore interface {
	UpsertProfileSection(projectID int64, section, content string) error
	GetProfileSection(projectID int64, section string) (*ProfileSection, error)
	ListProfileSections(projectID int64) ([]ProfileSection, error)
	BuildProfileString(projectID int64) (string, error)
	BuildProfileStringExcluding(projectID int64, exclude []string) (string, error)
}

// ProjectStore handles projects.
type ProjectStore interface {
	CreateProject(name, description string) (*Project, error)
	GetProject(id int64) (*Project, error)
	ListProjects() ([]Project, error)
	DeleteProject(id int64) error
}

// SettingsStore handles global app settings.
type SettingsStore interface {
	GetSetting(key string) (string, error)
	SetSetting(key, value string) error
	AllSettings() (map[string]string, error)
}

// ProjectSettingsStore handles per-project key-value settings.
type ProjectSettingsStore interface {
	GetProjectSetting(projectID int64, key string) (string, error)
	SetProjectSetting(projectID int64, key, value string) error
	AllProjectSettings(projectID int64) (map[string]string, error)
}

// BrainstormStore handles brainstorm chats and messages.
type BrainstormStore interface {
	CreateBrainstormChat(projectID int64, title, section string, contentPieceID *int64) (*BrainstormChat, error)
	GetBrainstormChat(id int64) (*BrainstormChat, error)
	ListBrainstormChats(projectID int64) ([]BrainstormChat, error)
	AddBrainstormMessage(chatID int64, role, content, thinking string) (*BrainstormMessage, error)
	ListBrainstormMessages(chatID int64) ([]BrainstormMessage, error)
	GetOrCreateProfileChat(projectID int64) (*BrainstormChat, error)
	GetOrCreateContextChat(projectID, contextItemID int64) (*BrainstormChat, error)
	GetOrCreateSectionChat(projectID int64, section string) (*BrainstormChat, error)
	GetOrCreatePieceChat(projectID, pieceID int64) (*BrainstormChat, error)
}

// ContextStore handles custom knowledge items.
type ContextStore interface {
	CreateContextItem(projectID int64, title string) (*ContextItem, error)
	GetContextItem(id int64) (*ContextItem, error)
	UpdateContextItem(id int64, title, content string) error
	DeleteContextItem(id int64) error
	ListContextItems(projectID int64) ([]ContextItem, error)
	BuildContextString(projectID int64) (string, error)
}

// TemplateStore handles email/social templates.
type TemplateStore interface {
	CreateTemplate(projectID int64, name, platform, htmlContent string) (*Template, error)
	GetTemplate(id int64) (*Template, error)
	ListTemplates(projectID int64) ([]Template, error)
	ListTemplatesByPlatform(projectID int64, platform string) ([]Template, error)
	UpdateTemplate(id int64, name, htmlContent string) error
	DeleteTemplate(id int64) error
}

// Ensure Queries implements all interfaces at compile time.
var _ PipelineStore = (*Queries)(nil)
var _ ContentStore = (*Queries)(nil)
var _ ProfileStore = (*Queries)(nil)
var _ ProjectStore = (*Queries)(nil)
var _ SettingsStore = (*Queries)(nil)
var _ ProjectSettingsStore = (*Queries)(nil)
var _ BrainstormStore = (*Queries)(nil)
var _ ContextStore = (*Queries)(nil)
var _ TemplateStore = (*Queries)(nil)
```

- [ ] **Step 2: Verify it compiles**

Run: `go build ./internal/store/...`
Expected: SUCCESS — the `var _ Interface = (*Queries)(nil)` lines catch any signature mismatches

- [ ] **Step 3: Commit**

```bash
git add internal/store/interfaces.go
git commit -m "feat: add store domain interfaces for testability"
```

---

### Task 4: Create Tool Registry

**Files:**
- Create: `internal/tools/registry.go`
- Create: `internal/tools/registry_test.go`

- [ ] **Step 1: Write the failing test**

Create `internal/tools/registry_test.go`:

```go
package tools_test

import (
	"context"
	"testing"

	"github.com/zanfridau/marketminded/internal/tools"
)

func TestRegistry_ForStep(t *testing.T) {
	r := tools.NewRegistry(nil)

	researchTools := r.ForStep("research")
	if len(researchTools) != 3 {
		t.Fatalf("expected 3 tools for research (fetch_url, web_search, submit_research), got %d", len(researchTools))
	}

	names := make(map[string]bool)
	for _, tool := range researchTools {
		names[tool.Function.Name] = true
	}
	for _, expected := range []string{"fetch_url", "web_search", "submit_research"} {
		if !names[expected] {
			t.Errorf("expected tool %q in research step", expected)
		}
	}
}

func TestRegistry_ForStep_Writer(t *testing.T) {
	r := tools.NewRegistry(nil)
	writerTools := r.ForStep("write")
	if len(writerTools) != 1 {
		t.Fatalf("expected 1 tool for write (write_blog_post), got %d", len(writerTools))
	}
	if writerTools[0].Function.Name != "write_blog_post" {
		t.Errorf("expected write_blog_post, got %s", writerTools[0].Function.Name)
	}
}

func TestRegistry_Execute_UnknownTool(t *testing.T) {
	r := tools.NewRegistry(nil)
	_, err := r.Execute(context.Background(), "nonexistent_tool", "{}")
	if err == nil {
		t.Error("expected error for unknown tool")
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `go test ./internal/tools/... -v -run TestRegistry`
Expected: FAIL (Registry type does not exist)

- [ ] **Step 3: Write the implementation**

Create `internal/tools/registry.go`:

```go
package tools

import (
	"context"
	"encoding/json"
	"fmt"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/search"
)

// Registry holds tool definitions and executors for each pipeline step.
type Registry struct {
	braveClient *search.BraveClient
	stepTools   map[string][]ai.Tool
}

// NewRegistry creates a Registry with tool definitions for each step type.
func NewRegistry(braveClient *search.BraveClient) *Registry {
	r := &Registry{
		braveClient: braveClient,
		stepTools:   make(map[string][]ai.Tool),
	}

	fetchTool := NewFetchTool()
	searchTool := NewSearchTool()

	r.stepTools["research"] = []ai.Tool{fetchTool, searchTool, submitTool(
		"submit_research",
		"Submit your research findings. Call this when you have gathered sufficient sources and are ready to write the research brief.",
		`{"type":"object","properties":{"sources":{"type":"array","description":"List of sources found during research","items":{"type":"object","properties":{"url":{"type":"string","description":"Source URL"},"title":{"type":"string","description":"Source title"},"summary":{"type":"string","description":"What this source contributes"},"date":{"type":"string","description":"Publication date if known"}},"required":["url","title","summary"]}},"brief":{"type":"string","description":"A comprehensive research brief synthesizing all findings. Include key facts, angles, statistics, and anything the writer needs to produce an authoritative piece."}},"required":["sources","brief"]}`,
	)}

	r.stepTools["brand_enricher"] = []ai.Tool{fetchTool, submitTool(
		"submit_brand_enrichment",
		"Submit the enriched research brief. You MUST call this tool to deliver your results.",
		`{"type":"object","properties":{"enriched_brief":{"type":"string","description":"The complete research brief rewritten with brand context woven in — product names, pricing, features, messaging. Include everything the writer needs."},"sources":{"type":"array","description":"ALL sources: original research sources plus brand URLs you fetched","items":{"type":"object","properties":{"url":{"type":"string"},"title":{"type":"string"},"summary":{"type":"string"}},"required":["url","title"]}}},"required":["enriched_brief","sources"]}`,
	)}

	r.stepTools["factcheck"] = []ai.Tool{fetchTool, searchTool, submitTool(
		"submit_factcheck",
		"Submit your fact-check results. Call this when you have verified the research and are ready to provide the enriched brief.",
		`{"type":"object","properties":{"issues_found":{"type":"array","description":"List of issues found during fact-checking (may be empty if everything checks out)","items":{"type":"object","properties":{"claim":{"type":"string","description":"The claim that was checked"},"problem":{"type":"string","description":"What is wrong or uncertain"},"resolution":{"type":"string","description":"How to address this in the final content"}},"required":["claim","problem","resolution"]}},"enriched_brief":{"type":"string","description":"The research brief, corrected and enriched with any additional context from fact-checking. This is what the writer will use."},"sources":{"type":"array","description":"Verified sources to cite in the final piece","items":{"type":"object","properties":{"url":{"type":"string"},"title":{"type":"string"},"summary":{"type":"string"},"date":{"type":"string"}},"required":["url","title","summary"]}}},"required":["issues_found","enriched_brief","sources"]}`,
	)}

	r.stepTools["tone_analyzer"] = []ai.Tool{fetchTool, submitTool(
		"submit_tone_analysis",
		"Submit the tone and style guide based on the company's existing blog posts.",
		`{"type":"object","properties":{"tone_guide":{"type":"string","description":"A concise guide describing the writing tone, voice, style patterns, sentence structure, vocabulary level, and formatting conventions observed in the blog posts. The writer will use this to match the brand's voice."},"posts":{"type":"array","description":"The blog posts that were analyzed","items":{"type":"object","properties":{"title":{"type":"string"},"url":{"type":"string"},"excerpt":{"type":"string","description":"A short excerpt showing the post's typical writing style"}},"required":["title","url"]}}},"required":["tone_guide","posts"]}`,
	)}

	r.stepTools["editor"] = []ai.Tool{submitTool(
		"submit_editorial_outline",
		"Submit the structured editorial outline for the writer. Call this when you have determined the narrative structure.",
		`{"type":"object","properties":{"angle":{"type":"string","description":"The core narrative angle in one sentence"},"sections":{"type":"array","description":"Ordered sections of the article","items":{"type":"object","properties":{"heading":{"type":"string","description":"Suggested section heading"},"framework_beat":{"type":"string","description":"Storytelling framework beat this maps to, if any"},"key_points":{"type":"array","items":{"type":"string"},"description":"Specific points to make, with data/stats where relevant"},"sources_to_use":{"type":"array","items":{"type":"string"},"description":"Source URLs that back the points in this section"},"editorial_notes":{"type":"string","description":"Tone and approach guidance for this section"}},"required":["heading","key_points"]}},"conclusion_strategy":{"type":"string","description":"How to close: what ties back, what CTA, what feeling to leave"}},"required":["angle","sections","conclusion_strategy"]}`,
	)}

	// Writer step uses the blog_post content type tool
	ct, ok := content.LookupType("blog", "post")
	if ok {
		r.stepTools["write"] = []ai.Tool{ct.Tool}
	}

	return r
}

// ForStep returns the tool definitions for a given step type.
func (r *Registry) ForStep(stepType string) []ai.Tool {
	return r.stepTools[stepType]
}

// Execute runs a base tool (fetch_url or web_search) by name.
// Submit tools are NOT handled here — each step's executor handles those.
func (r *Registry) Execute(ctx context.Context, name, argsJSON string) (string, error) {
	switch name {
	case "fetch_url":
		return ExecuteFetch(ctx, argsJSON)
	case "web_search":
		if r.braveClient == nil {
			return "", fmt.Errorf("web_search not available: no Brave client configured")
		}
		exec := NewSearchExecutor(r.braveClient)
		return exec(ctx, argsJSON)
	default:
		return "", fmt.Errorf("unknown tool: %s", name)
	}
}

func submitTool(name, description, paramsJSON string) ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        name,
			Description: description,
			Parameters:  json.RawMessage(paramsJSON),
		},
	}
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `go test ./internal/tools/... -v -run TestRegistry`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add internal/tools/registry.go internal/tools/registry_test.go
git commit -m "feat: add tool registry for pipeline step tool management"
```

---

### Task 5: Create Prompt Builder

**Files:**
- Create: `internal/prompt/builder.go`
- Create: `internal/prompt/builder_test.go`

- [ ] **Step 1: Write the failing test**

Create `internal/prompt/builder_test.go`:

```go
package prompt_test

import (
	"os"
	"path/filepath"
	"testing"

	"github.com/zanfridau/marketminded/internal/prompt"
)

func TestBuilder_LoadPrompts(t *testing.T) {
	dir := t.TempDir()
	typesDir := filepath.Join(dir, "types")
	os.MkdirAll(typesDir, 0o755)
	os.WriteFile(filepath.Join(typesDir, "blog_post.md"), []byte("Write a blog post."), 0o644)

	b, err := prompt.NewBuilder(dir)
	if err != nil {
		t.Fatal(err)
	}

	p := b.ContentPrompt("blog_post")
	if p != "Write a blog post." {
		t.Errorf("expected prompt content, got: %q", p)
	}
}

func TestBuilder_ContentPrompt_Missing(t *testing.T) {
	dir := t.TempDir()
	os.MkdirAll(filepath.Join(dir, "types"), 0o755)

	b, _ := prompt.NewBuilder(dir)
	p := b.ContentPrompt("nonexistent")
	if p != "" {
		t.Errorf("expected empty string for missing prompt, got: %q", p)
	}
}

func TestBuilder_AntiAIRules(t *testing.T) {
	dir := t.TempDir()
	os.MkdirAll(filepath.Join(dir, "types"), 0o755)
	b, _ := prompt.NewBuilder(dir)

	rules := b.AntiAIRules()
	if rules == "" {
		t.Error("expected non-empty anti-AI rules")
	}
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `go test ./internal/prompt/... -v`
Expected: FAIL (package does not exist)

- [ ] **Step 3: Write the implementation**

Create `internal/prompt/builder.go`:

```go
package prompt

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"
)

const antiAIRules = `

## Anti-AI writing rules (CRITICAL)

NEVER use em dashes (—). They are the #1 marker of AI writing. Use commas, colons, or parentheses instead.
No emoji in blog posts or scripts.

Banned verbs: delve, leverage, optimize, utilize, facilitate, foster, bolster, underscore, unveil, navigate, streamline, enhance, endeavour, ascertain, elucidate
Banned adjectives: robust, comprehensive, pivotal, crucial, vital, transformative, cutting-edge, groundbreaking, innovative, seamless, intricate, nuanced, multifaceted, holistic
Banned transitions: furthermore, moreover, notwithstanding, "that being said", "at its core", "it is worth noting", "in the realm of", "in today's [anything]"
Banned openings: "In today's fast-paced world", "In today's digital age", "In an era of", "In the ever-evolving landscape", "Let's delve into", "Imagine a world where"
Banned conclusions: "In conclusion", "To sum up", "At the end of the day", "All things considered", "In the final analysis"
Banned patterns: "Whether you're a X, Y, or Z", "It's not just X, it's also Y", starting sentences with "By" + gerund ("By understanding X, you can Y")
Banned filler: absolutely, basically, certainly, clearly, definitely, essentially, extremely, fundamentally, incredibly, interestingly, naturally, obviously, quite, really, significantly, simply, surely, truly, ultimately, undoubtedly, very

Use natural transitions instead: "Here's the thing", "But", "So", "Also", "Plus", "On top of that", "That said", "However"
Vary sentence length. Read it aloud. If it sounds like a press release, rewrite it.`

// Builder loads prompt files at startup and assembles system prompts.
type Builder struct {
	contentPrompts map[string]string
}

// NewBuilder loads all prompt files from the given directory.
func NewBuilder(promptDir string) (*Builder, error) {
	b := &Builder{
		contentPrompts: make(map[string]string),
	}

	typesDir := filepath.Join(promptDir, "types")
	entries, err := os.ReadDir(typesDir)
	if err != nil {
		return b, nil
	}

	for _, entry := range entries {
		if entry.IsDir() || !strings.HasSuffix(entry.Name(), ".md") {
			continue
		}
		name := strings.TrimSuffix(entry.Name(), ".md")
		data, err := os.ReadFile(filepath.Join(typesDir, entry.Name()))
		if err != nil {
			return nil, fmt.Errorf("failed to load prompt %s: %w", entry.Name(), err)
		}
		b.contentPrompts[name] = string(data)
	}

	return b, nil
}

// ContentPrompt returns the prompt text for a content type (e.g., "blog_post").
func (b *Builder) ContentPrompt(promptFile string) string {
	return b.contentPrompts[promptFile]
}

// AntiAIRules returns the standard anti-AI writing rules block.
func (b *Builder) AntiAIRules() string {
	return antiAIRules
}

// DateHeader returns today's date formatted for system prompts.
func (b *Builder) DateHeader() string {
	return fmt.Sprintf("Today's date: %s", time.Now().Format("January 2, 2006"))
}

// ForPiece builds the system prompt for content piece generation.
func (b *Builder) ForPiece(promptFile, profile, brief, frameworkBlock, rejectionReason string) string {
	promptText := b.ContentPrompt(promptFile)
	if promptText == "" {
		promptText = "You are writing content."
	}

	var sb strings.Builder
	sb.WriteString(b.DateHeader())
	sb.WriteString("\n\n")
	sb.WriteString(promptText)
	sb.WriteString("\n\n## Client profile\n")
	sb.WriteString(profile)
	sb.WriteString("\n")

	if frameworkBlock != "" {
		sb.WriteString("\n")
		sb.WriteString(frameworkBlock)
		sb.WriteString("\n")
	}

	sb.WriteString(fmt.Sprintf("\n## Topic brief\n%s\n", brief))

	if rejectionReason != "" {
		sb.WriteString(fmt.Sprintf("\nPrevious version was rejected. Feedback: %s. Address this.\n", rejectionReason))
	}

	sb.WriteString(antiAIRules)
	return sb.String()
}

// ForResearch builds the system prompt for the research step.
func (b *Builder) ForResearch(profile, brief string) string {
	return fmt.Sprintf(`%s

You are a research specialist. Your job is to gather reliable, up-to-date information on a topic so a writer can produce an authoritative piece.

Client profile:
%s

Topic brief:
%s

Search the web thoroughly. Look for:
- Key facts, data, and statistics
- Recent developments (last 12 months preferred)
- Expert opinions and quotes if available
- Relevant angles and sub-topics
- Anything that makes this topic interesting or surprising

Fetch pages when search snippets are insufficient. Aim for at least 3-5 solid sources.

When you have gathered enough material, call submit_research with your sources and a comprehensive brief.`, b.DateHeader(), profile, brief)
}

// ForBrandEnricher builds the system prompt for the brand enricher step.
func (b *Builder) ForBrandEnricher(profile, researchOutput, urlList string) string {
	return fmt.Sprintf(`%s

You are a brand enricher. You receive market research about a specific topic and company brand URLs. Your job is to connect the research topic to the brand's actual offerings.

## Workflow

1. Read the research brief carefully — understand what specific topic the article is about
2. Fetch each company URL below
3. Critically evaluate what you find: only extract information that is directly relevant to the article's topic. A page may contain 20 products but only 2 matter for this article. Ignore the rest.
4. Enrich the research brief with the relevant brand context — specific product names, pricing, features, value propositions that connect to the topic
5. Call submit_brand_enrichment with the enriched brief and complete sources list

## Client profile
%s

## Research to enrich
%s

## Company URLs to fetch
%s

## Rules
- Fetch ALL URLs above, but be selective about what you extract. More is not better — relevance is.
- Ask yourself: "Would a writer need this specific detail for THIS article?" If not, leave it out.
- Include specific numbers (pricing, terms, features) that strengthen the article's argument.
- Your sources list MUST include ALL sources from the original research, plus the brand URLs you fetched. Never drop sources.
- When done, call submit_brand_enrichment. This is your only way to deliver results.`, b.DateHeader(), profile, researchOutput, urlList)
}

// ForFactcheck builds the system prompt for the factcheck step.
func (b *Builder) ForFactcheck(researchOutput string) string {
	return fmt.Sprintf(`%s

You are a fact-checker. Verify the key claims in the research brief below, then call submit_factcheck with your findings.

## Research output to verify
%s

## Workflow
1. Identify the 3-5 most important claims that could be wrong (prices, dates, statistics, percentages)
2. Do focused searches to verify those specific claims — do NOT try to verify everything
3. Correct anything wrong, add caveats where needed
4. Call submit_factcheck with the enriched brief and complete sources list

## Rules
- Be efficient. 3-5 targeted searches, not 15+ scattered ones.
- Focus on claims that would embarrass the brand if wrong (prices, percentages, dates).
- Accept reasonable claims from credible sources without re-verifying.
- Your sources list MUST include ALL sources from the input above, plus any new ones. Never drop sources.
- Call submit_factcheck when done. This is your only way to deliver results.`, b.DateHeader(), researchOutput)
}

// ForToneAnalyzer builds the system prompt for the tone analyzer step.
func (b *Builder) ForToneAnalyzer(blogURLs string) string {
	return fmt.Sprintf(`%s

You are a tone analyzer. Your job is to read 3-5 recent blog posts from the company's blog and create a tone/style guide for the content writer.

Blog URL(s) to start from:
%s

Steps:
1. Fetch the blog listing page(s) above
2. Find links to 3-5 recent individual blog posts
3. Fetch each post and read the full content
4. Analyze the writing patterns across all posts

Create a tone guide covering:
- Voice and tone (formal/informal, authoritative/conversational, etc.)
- Typical sentence structure and length
- Vocabulary level and any recurring phrases or expressions
- How they address the reader (you/vi, formal/informal)
- Formatting patterns (headings, lists, CTAs, etc.)
- Language (what language the posts are written in)

IMPORTANT: You are analyzing STYLE only, not content. The writer will use your guide to match the brand's voice, not to copy facts.

You MUST call submit_tone_analysis with your findings.`, b.DateHeader(), blogURLs)
}

// ForEditor builds the system prompt for the editor step.
func (b *Builder) ForEditor(profile, brief, sourcesText, frameworkBlock, toneGuide string) string {
	var sb strings.Builder
	sb.WriteString(b.DateHeader())
	sb.WriteString(`

You are an editorial director. You receive research, sources, and brand context about a topic. Your job is to craft a structured editorial outline that a copywriter will use to write the final article.

Your job is narrative reasoning:
- Analyze the research and determine the strongest angle/hook
- Decide what facts to include, what to cut, and how to order them for maximum impact
- Build a logical throughline so the conclusion feels inevitable, not forced
- Specify which sources back which points
- Produce a tight outline the writer can execute without needing the raw research

Do NOT write the article. Produce only the structural outline via the tool.

## Client profile
`)
	sb.WriteString(profile)
	sb.WriteString("\n\n## Research brief\n")
	sb.WriteString(brief)
	sb.WriteString("\n")
	sb.WriteString(sourcesText)

	if frameworkBlock != "" {
		sb.WriteString("\n")
		sb.WriteString(frameworkBlock)
		sb.WriteString("\n")
	}

	if toneGuide != "" {
		sb.WriteString("\n## Tone & style reference\nKeep this voice in mind when choosing the angle and editorial notes.\n\n")
		sb.WriteString(toneGuide)
		sb.WriteString("\n")
	}

	return sb.String()
}

// ForWriter builds the system prompt for the writer step.
func (b *Builder) ForWriter(promptFile, profile, editorOutput, rejectionReason, toneGuide string) string {
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
	sb.WriteString(fmt.Sprintf("\n## Editorial outline\nFollow this outline closely. It defines the angle, structure, and key points. Your job is to write compelling prose that brings this outline to life.\n\n%s\n", editorOutput))

	if rejectionReason != "" {
		sb.WriteString(fmt.Sprintf("\n## Previous rejection feedback\n%s. Address this in the new version.\n", rejectionReason))
	}

	if toneGuide != "" {
		sb.WriteString("\n## Tone & style reference (from company blog)\nUse this ONLY to match the writing tone, voice, and style. Do NOT use any factual information from the blog posts — all facts must come from the editorial outline above.\n\n")
		sb.WriteString(toneGuide)
		sb.WriteString("\n")
	}

	sb.WriteString(antiAIRules)
	return sb.String()
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `go test ./internal/prompt/... -v`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add internal/prompt/
git commit -m "feat: add prompt builder for centralized prompt assembly"
```

---

### Task 6: Create Pipeline StepRunner Interface, Orchestrator, and Source Helpers

**Files:**
- Create: `internal/pipeline/runner.go`
- Create: `internal/pipeline/orchestrator.go`
- Create: `internal/pipeline/orchestrator_test.go`
- Create: `internal/pipeline/sources.go`

- [ ] **Step 1: Write StepRunner interface and types**

Create `internal/pipeline/runner.go`:

```go
package pipeline

import "context"

// StepRunner executes a single pipeline step type.
type StepRunner interface {
	Type() string
	Run(ctx context.Context, input StepInput, stream StepStream) (StepResult, error)
}

// StepInput is everything a step needs to execute.
type StepInput struct {
	ProjectID    int64
	RunID        int64
	StepID       int64
	Topic        string
	Brief        string
	Profile      string
	PriorOutputs map[string]string
}

// StepResult is what a step returns after execution.
type StepResult struct {
	Output    string
	Thinking  string
	ToolCalls string
}

// StepStream abstracts SSE output so steps don't depend on HTTP.
type StepStream interface {
	SendChunk(chunk string) error
	SendThinking(chunk string) error
	SendEvent(v any)
	SendError(msg string)
	SendDone()
}

// ToolCallRecord tracks tool usage for UI display.
type ToolCallRecord struct {
	Type  string `json:"type"`
	Value string `json:"value"`
}
```

- [ ] **Step 2: Write source collection helpers**

Create `internal/pipeline/sources.go`:

```go
package pipeline

import (
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/store"
)

// Source represents a research source from pipeline step outputs.
type Source struct {
	URL     string
	Title   string
	Summary string
	Date    string
}

// CollectSources gathers all unique sources from completed pipeline steps.
func CollectSources(steps []store.PipelineStep) []Source {
	seen := map[string]bool{}
	var sources []Source
	for _, s := range steps {
		if s.Output == "" {
			continue
		}
		var parsed struct {
			Sources []struct {
				URL     string `json:"url"`
				Title   string `json:"title"`
				Summary string `json:"summary"`
				Date    string `json:"date"`
			} `json:"sources"`
		}
		if json.Unmarshal([]byte(s.Output), &parsed) == nil {
			for _, src := range parsed.Sources {
				if src.URL != "" && !seen[src.URL] {
					seen[src.URL] = true
					sources = append(sources, Source{src.URL, src.Title, src.Summary, src.Date})
				}
			}
		}
	}
	return sources
}

// FormatSourcesText formats sources for inclusion in prompts.
func FormatSourcesText(sources []Source) string {
	if len(sources) == 0 {
		return ""
	}
	var b strings.Builder
	b.WriteString("\n## Sources (from research, brand analysis, and fact-checking)\n")
	for _, s := range sources {
		line := fmt.Sprintf("- [%s](%s): %s", s.Title, s.URL, s.Summary)
		if s.Date != "" {
			line += fmt.Sprintf(" (%s)", s.Date)
		}
		b.WriteString(line + "\n")
	}
	return b.String()
}

// ToolCallsJSON serializes tool call records to JSON string.
func ToolCallsJSON(calls []ToolCallRecord) string {
	if len(calls) == 0 {
		return ""
	}
	data, _ := json.Marshal(calls)
	return string(data)
}
```

- [ ] **Step 3: Write the orchestrator**

Create `internal/pipeline/orchestrator.go`:

```go
package pipeline

import (
	"context"
	"fmt"

	"github.com/zanfridau/marketminded/internal/store"
)

// StepDependencies returns the dependency map: step type -> required prior step types.
func StepDependencies() map[string][]string {
	return map[string][]string{
		"research":       {},
		"brand_enricher": {"research"},
		"factcheck":      {"brand_enricher"},
		"tone_analyzer":  {},
		"editor":         {"factcheck"},
		"write":          {"editor"},
	}
}

// Orchestrator manages step dependencies and dispatching.
type Orchestrator struct {
	steps map[string]StepRunner
	store store.PipelineStore
}

// NewOrchestrator creates an Orchestrator with the given step runners.
func NewOrchestrator(pipelineStore store.PipelineStore, runners ...StepRunner) *Orchestrator {
	steps := make(map[string]StepRunner, len(runners))
	for _, r := range runners {
		steps[r.Type()] = r
	}
	return &Orchestrator{steps: steps, store: pipelineStore}
}

// RunStep resolves dependencies, builds input, and dispatches to the appropriate StepRunner.
func (o *Orchestrator) RunStep(ctx context.Context, stepID int64, run *store.PipelineRun, profile string, stream StepStream) error {
	step, err := o.store.GetPipelineStep(stepID)
	if err != nil {
		return fmt.Errorf("step not found: %w", err)
	}

	runner, ok := o.steps[step.StepType]
	if !ok {
		return fmt.Errorf("unknown step type: %s", step.StepType)
	}

	// Resolve dependencies
	steps, err := o.store.ListPipelineSteps(step.PipelineRunID)
	if err != nil {
		return fmt.Errorf("failed to list steps: %w", err)
	}

	deps := StepDependencies()
	priorOutputs := make(map[string]string)
	for _, s := range steps {
		if s.Status == "completed" && s.Output != "" {
			priorOutputs[s.StepType] = s.Output
		}
	}

	for _, dep := range deps[step.StepType] {
		if _, ok := priorOutputs[dep]; !ok {
			return fmt.Errorf("%s step not completed yet", dep)
		}
	}

	input := StepInput{
		ProjectID:    run.ProjectID,
		RunID:        run.ID,
		StepID:       stepID,
		Topic:        run.Topic,
		Brief:        run.Brief,
		Profile:      profile,
		PriorOutputs: priorOutputs,
	}

	ok, err = o.store.TrySetStepRunning(stepID)
	if err != nil || !ok {
		return fmt.Errorf("step already running or completed")
	}

	result, runErr := runner.Run(ctx, input, stream)

	if runErr != nil {
		o.store.UpdatePipelineStepOutput(stepID, result.Output, result.Thinking)
		if result.ToolCalls != "" {
			o.store.UpdatePipelineStepToolCalls(stepID, result.ToolCalls)
		}
		o.store.UpdatePipelineStepStatus(stepID, "failed")
		return runErr
	}

	o.store.UpdatePipelineStepOutput(stepID, result.Output, result.Thinking)
	if result.ToolCalls != "" {
		o.store.UpdatePipelineStepToolCalls(stepID, result.ToolCalls)
	}
	o.store.UpdatePipelineStepStatus(stepID, "completed")
	return nil
}
```

- [ ] **Step 4: Write the tests**

Create `internal/pipeline/orchestrator_test.go`:

```go
package pipeline_test

import (
	"testing"

	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/store"
)

func TestCollectSources(t *testing.T) {
	steps := []store.PipelineStep{
		{Output: `{"sources":[{"url":"https://a.com","title":"A","summary":"s1"}]}`},
		{Output: `{"sources":[{"url":"https://a.com","title":"A","summary":"s1"},{"url":"https://b.com","title":"B","summary":"s2"}]}`},
		{Output: ""},
	}

	sources := pipeline.CollectSources(steps)
	if len(sources) != 2 {
		t.Fatalf("expected 2 unique sources, got %d", len(sources))
	}
	if sources[0].URL != "https://a.com" {
		t.Errorf("expected first source a.com, got %s", sources[0].URL)
	}
	if sources[1].URL != "https://b.com" {
		t.Errorf("expected second source b.com, got %s", sources[1].URL)
	}
}

func TestFormatSourcesText_Empty(t *testing.T) {
	result := pipeline.FormatSourcesText(nil)
	if result != "" {
		t.Errorf("expected empty string, got: %q", result)
	}
}

func TestStepDependencies(t *testing.T) {
	deps := pipeline.StepDependencies()

	if len(deps["research"]) != 0 {
		t.Errorf("research should have no deps, got %v", deps["research"])
	}
	if deps["brand_enricher"][0] != "research" {
		t.Errorf("brand_enricher should depend on research")
	}
	if deps["write"][0] != "editor" {
		t.Errorf("write should depend on editor")
	}
}

func TestToolCallsJSON_Empty(t *testing.T) {
	result := pipeline.ToolCallsJSON(nil)
	if result != "" {
		t.Errorf("expected empty string, got: %q", result)
	}
}

func TestToolCallsJSON_WithRecords(t *testing.T) {
	records := []pipeline.ToolCallRecord{
		{Type: "fetch", Value: "https://example.com"},
	}
	result := pipeline.ToolCallsJSON(records)
	if result == "" {
		t.Error("expected non-empty JSON")
	}
}
```

- [ ] **Step 5: Run tests**

Run: `go test ./internal/pipeline/... -v`
Expected: PASS

- [ ] **Step 6: Commit**

```bash
git add internal/pipeline/
git commit -m "feat: add pipeline orchestrator with StepRunner interface"
```

---

### Task 7: Implement Step Runners

**Files:**
- Create: `internal/pipeline/steps/common.go`
- Create: `internal/pipeline/steps/research.go`
- Create: `internal/pipeline/steps/brand_enricher.go`
- Create: `internal/pipeline/steps/factcheck.go`
- Create: `internal/pipeline/steps/tone_analyzer.go`
- Create: `internal/pipeline/steps/editor.go`
- Create: `internal/pipeline/steps/writer.go`

- [ ] **Step 1: Create common helper for the shared streaming pattern**

Create `internal/pipeline/steps/common.go`:

```go
package steps

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/tools"
	"github.com/zanfridau/marketminded/internal/types"
)

// runWithTools is the common pattern for streaming a step with tool calling.
func runWithTools(
	ctx context.Context,
	aiClient *ai.Client,
	model string,
	systemPrompt string,
	userPrompt string,
	toolList []ai.Tool,
	registry *tools.Registry,
	submitToolName string,
	stream pipeline.StepStream,
	temp float64,
	maxIter int,
) (pipeline.StepResult, error) {
	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: userPrompt},
	}

	var thinkingBuf strings.Builder
	var chunkBuf strings.Builder
	var savedOutput string
	var toolCallsList []pipeline.ToolCallRecord

	executor := func(ctx context.Context, name, args string) (string, error) {
		if name == submitToolName {
			savedOutput = args
			return "Saved successfully.", ai.ErrToolDone
		}
		return registry.Execute(ctx, name, args)
	}

	onToolEvent := func(event ai.ToolEvent) {
		switch event.Type {
		case "tool_start":
			if event.Tool == submitToolName {
				return
			}
			summary := ""
			switch event.Tool {
			case "fetch_url":
				summary = tools.FetchSummary(event.Args)
				var args struct{ URL string `json:"url"` }
				if json.Unmarshal([]byte(event.Args), &args) == nil && args.URL != "" {
					toolCallsList = append(toolCallsList, pipeline.ToolCallRecord{Type: "fetch", Value: args.URL})
				}
			case "web_search":
				summary = tools.SearchSummary(event.Args)
				var args struct{ Query string `json:"query"` }
				if json.Unmarshal([]byte(event.Args), &args) == nil && args.Query != "" {
					toolCallsList = append(toolCallsList, pipeline.ToolCallRecord{Type: "search", Value: args.Query})
				}
			}
			evt := map[string]string{"type": "tool_start", "tool": event.Tool, "summary": summary}
			if event.Tool == "fetch_url" {
				var a struct{ URL string `json:"url"` }
				if json.Unmarshal([]byte(event.Args), &a) == nil {
					evt["url"] = a.URL
				}
			} else if event.Tool == "web_search" {
				var a struct{ Query string `json:"query"` }
				if json.Unmarshal([]byte(event.Args), &a) == nil {
					evt["query"] = a.Query
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

	sendChunk := func(chunk string) error {
		chunkBuf.WriteString(chunk)
		return stream.SendChunk(chunk)
	}

	sendThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return stream.SendThinking(chunk)
	}

	temperature := temp
	_, err := aiClient.StreamWithTools(ctx, model, aiMsgs, toolList, executor, onToolEvent, sendChunk, sendThinking, &temperature, maxIter)

	result := pipeline.StepResult{
		Output:    savedOutput,
		Thinking:  thinkingBuf.String(),
		ToolCalls: pipeline.ToolCallsJSON(toolCallsList),
	}

	if err != nil {
		if result.Output == "" {
			result.Output = chunkBuf.String()
		}
		return result, err
	}

	if savedOutput == "" {
		result.Output = chunkBuf.String()
		return result, fmt.Errorf("step did not submit results via tool call")
	}

	return result, nil
}
```

- [ ] **Step 2: Create research step**

Create `internal/pipeline/steps/research.go`:

```go
package steps

import (
	"context"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/tools"
)

type ResearchStep struct {
	AI     *ai.Client
	Tools  *tools.Registry
	Prompt *prompt.Builder
	Model  func() string
}

func (s *ResearchStep) Type() string { return "research" }

func (s *ResearchStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	systemPrompt := s.Prompt.ForResearch(input.Profile, input.Brief)
	toolList := s.Tools.ForStep("research")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Begin researching this topic now.", toolList, s.Tools, "submit_research", stream, 0.3, 25)
}
```

- [ ] **Step 3: Create brand enricher step**

Create `internal/pipeline/steps/brand_enricher.go`:

```go
package steps

import (
	"context"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type BrandEnricherStep struct {
	AI              *ai.Client
	Tools           *tools.Registry
	Prompt          *prompt.Builder
	ProjectSettings store.ProjectSettingsStore
	Model           func() string
}

func (s *BrandEnricherStep) Type() string { return "brand_enricher" }

func (s *BrandEnricherStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	researchOutput := input.PriorOutputs["research"]

	settings, _ := s.ProjectSettings.AllProjectSettings(input.ProjectID)
	type brandURL struct{ URL, Notes, Label string }
	var urls []brandURL
	if v := settings["company_website"]; v != "" {
		for _, u := range splitURLs(v) {
			urls = append(urls, brandURL{u, settings["website_notes"], "Company Website"})
		}
	}
	if v := settings["company_pricing"]; v != "" {
		for _, u := range splitURLs(v) {
			urls = append(urls, brandURL{u, settings["pricing_notes"], "Pricing Page"})
		}
	}

	if len(urls) == 0 {
		stream.SendDone()
		return pipeline.StepResult{Output: researchOutput}, nil
	}

	var urlList strings.Builder
	for _, u := range urls {
		fmt.Fprintf(&urlList, "- %s: %s", u.Label, u.URL)
		if u.Notes != "" {
			fmt.Fprintf(&urlList, " (Usage notes: %s)", u.Notes)
		}
		urlList.WriteString("\n")
	}

	systemPrompt := s.Prompt.ForBrandEnricher(input.Profile, researchOutput, urlList.String())
	toolList := s.Tools.ForStep("brand_enricher")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Fetch the brand URLs and enrich the research with brand context.", toolList, s.Tools, "submit_brand_enrichment", stream, 0.3, 12)
}

func splitURLs(s string) []string {
	parts := strings.Split(s, ",")
	var urls []string
	for _, p := range parts {
		p = strings.TrimSpace(p)
		if p != "" {
			urls = append(urls, p)
		}
	}
	return urls
}
```

- [ ] **Step 4: Create factcheck step**

Create `internal/pipeline/steps/factcheck.go`:

```go
package steps

import (
	"context"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/tools"
)

type FactcheckStep struct {
	AI     *ai.Client
	Tools  *tools.Registry
	Prompt *prompt.Builder
	Model  func() string
}

func (s *FactcheckStep) Type() string { return "factcheck" }

func (s *FactcheckStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	enricherOutput := input.PriorOutputs["brand_enricher"]
	systemPrompt := s.Prompt.ForFactcheck(enricherOutput)
	toolList := s.Tools.ForStep("factcheck")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Begin fact-checking now.", toolList, s.Tools, "submit_factcheck", stream, 0.2, 20)
}
```

- [ ] **Step 5: Create tone analyzer step**

Create `internal/pipeline/steps/tone_analyzer.go`:

```go
package steps

import (
	"context"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type ToneAnalyzerStep struct {
	AI              *ai.Client
	Tools           *tools.Registry
	Prompt          *prompt.Builder
	ProjectSettings store.ProjectSettingsStore
	Model           func() string
}

func (s *ToneAnalyzerStep) Type() string { return "tone_analyzer" }

func (s *ToneAnalyzerStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	settings, _ := s.ProjectSettings.AllProjectSettings(input.ProjectID)
	blogURL := settings["company_blog"]

	if blogURL == "" {
		stream.SendDone()
		return pipeline.StepResult{Output: `{"tone_guide":"","posts":[]}`}, nil
	}

	blogURLs := strings.Join(splitURLs(blogURL), "\n")
	systemPrompt := s.Prompt.ForToneAnalyzer(blogURLs)
	toolList := s.Tools.ForStep("tone_analyzer")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Fetch the blog posts and analyze the writing tone.", toolList, s.Tools, "submit_tone_analysis", stream, 0.3, 10)
}
```

- [ ] **Step 6: Create editor step**

Create `internal/pipeline/steps/editor.go`:

```go
package steps

import (
	"context"
	"encoding/json"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/tools"
)

type EditorStep struct {
	AI              *ai.Client
	Tools           *tools.Registry
	Prompt          *prompt.Builder
	Pipeline        store.PipelineStore
	ProjectSettings store.ProjectSettingsStore
	Model           func() string
}

func (s *EditorStep) Type() string { return "editor" }

func (s *EditorStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	factcheckOutput := input.PriorOutputs["factcheck"]

	var factcheck struct {
		EnrichedBrief string `json:"enriched_brief"`
	}
	_ = json.Unmarshal([]byte(factcheckOutput), &factcheck)

	brief := factcheck.EnrichedBrief
	if brief == "" {
		brief = input.Brief
	}

	steps, _ := s.Pipeline.ListPipelineSteps(input.RunID)
	allSources := pipeline.CollectSources(steps)
	sourcesText := pipeline.FormatSourcesText(allSources)

	var frameworkBlock string
	if fwKey, err := s.ProjectSettings.GetProjectSetting(input.ProjectID, "storytelling_framework"); err == nil && fwKey != "" {
		if fw := content.FrameworkByKey(fwKey); fw != nil {
			frameworkBlock = "## Storytelling framework\nFramework: " + fw.Name + " (" + fw.Attribution + ")\n" + fw.PromptInstruction + "\nMap the framework beats to the article sections in your outline.\n"
		}
	}

	var toneGuide string
	if toneOutput, ok := input.PriorOutputs["tone_analyzer"]; ok {
		var toneResult struct{ ToneGuide string `json:"tone_guide"` }
		if json.Unmarshal([]byte(toneOutput), &toneResult) == nil {
			toneGuide = toneResult.ToneGuide
		}
	}

	systemPrompt := s.Prompt.ForEditor(input.Profile, brief, sourcesText, frameworkBlock, toneGuide)
	toolList := s.Tools.ForStep("editor")
	return runWithTools(ctx, s.AI, s.Model(), systemPrompt, "Create the editorial outline now.", toolList, s.Tools, "submit_editorial_outline", stream, 0.3, 5)
}
```

- [ ] **Step 7: Create writer step**

Create `internal/pipeline/steps/writer.go`:

```go
package steps

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/content"
	"github.com/zanfridau/marketminded/internal/pipeline"
	"github.com/zanfridau/marketminded/internal/prompt"
	"github.com/zanfridau/marketminded/internal/store"
	"github.com/zanfridau/marketminded/internal/types"
)

type WriterStep struct {
	AI       *ai.Client
	Prompt   *prompt.Builder
	Content  store.ContentStore
	Pipeline store.PipelineStore
	Model    func() string
}

func (s *WriterStep) Type() string { return "write" }

func (s *WriterStep) Run(ctx context.Context, input pipeline.StepInput, stream pipeline.StepStream) (pipeline.StepResult, error) {
	editorOutput := input.PriorOutputs["editor"]

	platform := "blog"
	format := "post"
	ct, ctOk := content.LookupType(platform, format)

	var toneGuide string
	if toneOutput, ok := input.PriorOutputs["tone_analyzer"]; ok {
		var toneResult struct{ ToneGuide string `json:"tone_guide"` }
		if json.Unmarshal([]byte(toneOutput), &toneResult) == nil {
			toneGuide = toneResult.ToneGuide
		}
	}

	var rejectionReason string
	pieces, _ := s.Content.ListContentByPipelineRun(input.RunID)
	for _, p := range pieces {
		if p.ParentID == nil && p.Status == "rejected" && p.RejectionReason != "" {
			rejectionReason = p.RejectionReason
			break
		}
	}

	promptFile := ""
	if ctOk {
		promptFile = ct.PromptFile
	}
	systemPrompt := s.Prompt.ForWriter(promptFile, input.Profile, editorOutput, rejectionReason, toneGuide)

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Write the cornerstone blog post now."},
	}

	var toolList []ai.Tool
	if ctOk {
		toolList = []ai.Tool{ct.Tool}
	}

	var savedPieceID int64
	var thinkingBuf strings.Builder

	executor := func(ctx context.Context, name, args string) (string, error) {
		if content.IsWriteTool(name) {
			var writeArgs struct{ Title string `json:"title"` }
			_ = json.Unmarshal([]byte(args), &writeArgs)
			title := writeArgs.Title
			if title == "" {
				title = input.Topic
			}

			piece, err := s.Content.CreateContentPiece(input.ProjectID, input.RunID, platform, format, title, 0, nil)
			if err != nil {
				return "", fmt.Errorf("failed to create content piece: %w", err)
			}
			savedPieceID = piece.ID

			s.Pipeline.UpdatePipelineTopic(input.RunID, title)
			s.Content.UpdateContentPieceBody(piece.ID, title, args)
			s.Content.SetContentPieceStatus(piece.ID, "draft")

			return "Content piece created successfully.", ai.ErrToolDone
		}
		return "", fmt.Errorf("unknown tool: %s", name)
	}

	onToolEvent := func(event ai.ToolEvent) {
		if event.Type == "tool_result" && content.IsWriteTool(event.Tool) && savedPieceID > 0 {
			piece, err := s.Content.GetContentPiece(savedPieceID)
			if err == nil {
				stream.SendEvent(map[string]any{
					"type":     "content_written",
					"platform": piece.Platform,
					"format":   piece.Format,
					"data":     json.RawMessage(piece.Body),
				})
			}
			stream.SendDone()
		}
	}

	sendChunk := func(chunk string) error {
		return stream.SendChunk(chunk)
	}
	sendThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return stream.SendThinking(chunk)
	}

	temp := 0.3
	_, err := s.AI.StreamWithTools(ctx, s.Model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, sendThinking, &temp)

	result := pipeline.StepResult{
		Output:   fmt.Sprintf(`{"piece_id":%d}`, savedPieceID),
		Thinking: thinkingBuf.String(),
	}

	if err != nil && savedPieceID == 0 {
		return result, err
	}

	if savedPieceID == 0 {
		return result, fmt.Errorf("writer did not submit content via tool call")
	}

	return result, nil
}
```

- [ ] **Step 8: Verify all steps compile**

Run: `go build ./internal/pipeline/...`
Expected: SUCCESS

- [ ] **Step 9: Commit**

```bash
git add internal/pipeline/steps/
git commit -m "feat: implement all 6 pipeline step runners"
```

---

### Task 8: Rewire Pipeline Handler and main.go

**Files:**
- Modify: `web/handlers/pipeline.go` (reduce from ~1700 to ~350 lines)
- Modify: `cmd/server/main.go`

This is the integration task. The handler keeps all non-step methods (list, create, show, approve, reject, abort, improve, proofread) but delegates step streaming to the orchestrator.

- [ ] **Step 1: Rewrite the pipeline handler**

Key changes to `web/handlers/pipeline.go`:
1. Remove `antiAIRules` const (now in `prompt` package)
2. Change struct to hold `orchestrator`, `promptBuilder`, and remove `braveClient`
3. Replace `streamStep` switch statement with single orchestrator call
4. Replace `setupSSE` calls with `sse.New` in streamPiece, streamImprove, proofread
5. Remove all `stream{Step}` methods (research, factcheck, brandEnricher, toneAnalyzer, editor, write)
6. Remove all tool definition methods (researchTool, factcheckTool, etc.)
7. Remove `buildTools`, `buildToolEventCallback`, `toolCallsJSON`, `toolCallRecord`
8. Remove `collectSources`, `formatSourcesText`, `pipelineSource`, `splitURLs`
9. Add `httpStepStream` adapter type

The handler struct becomes:

```go
type PipelineHandler struct {
	queries       *store.Queries
	orchestrator  *pipeline.Orchestrator
	aiClient      *ai.Client
	writerModel   func() string
	promptBuilder *prompt.Builder
}
```

The new `streamStep` method:

```go
func (h *PipelineHandler) streamStep(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	stepID := h.parseStepID(rest)

	run, err := h.queries.GetPipelineRun(runID)
	if err != nil {
		http.Error(w, "Run not found", http.StatusNotFound)
		return
	}

	profile, _ := h.queries.BuildProfileStringExcluding(projectID, []string{"content_strategy"})

	sseStream, err := sse.New(w)
	if err != nil {
		http.Error(w, "Streaming not supported", http.StatusInternalServerError)
		return
	}

	stream := &httpStepStream{sse: sseStream}

	if err := h.orchestrator.RunStep(r.Context(), stepID, run, profile, stream); err != nil {
		sseStream.SendData(map[string]string{"type": "error", "error": err.Error()})
	}
}
```

The `httpStepStream` adapter:

```go
type httpStepStream struct{ sse *sse.Stream }

func (s *httpStepStream) SendChunk(chunk string) error {
	s.sse.SendData(map[string]string{"type": "chunk", "chunk": chunk})
	return nil
}
func (s *httpStepStream) SendThinking(chunk string) error {
	s.sse.SendData(map[string]string{"type": "thinking", "chunk": chunk})
	return nil
}
func (s *httpStepStream) SendEvent(v any) { s.sse.SendData(v) }
func (s *httpStepStream) SendError(msg string) {
	s.sse.SendData(map[string]string{"type": "error", "error": msg})
}
func (s *httpStepStream) SendDone() {
	s.sse.SendData(map[string]string{"type": "done"})
}
```

Update `streamPiece` and `streamImprove` to use `sse.New` instead of `setupSSE`, and `promptBuilder.ForPiece` instead of `buildPiecePrompt`.

- [ ] **Step 2: Update main.go**

Update `cmd/server/main.go` to construct orchestrator and pass new deps to PipelineHandler:

```go
// New service layer
promptBuilder, err := prompt.NewBuilder("prompts")
if err != nil {
	log.Fatalf("prompts: %v", err)
}

toolRegistry := tools.NewRegistry(braveClient)

orchestrator := pipeline.NewOrchestrator(
	queries,
	&steps.ResearchStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Model: contentModel},
	&steps.BrandEnricherStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, ProjectSettings: queries, Model: contentModel},
	&steps.FactcheckStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Model: contentModel},
	&steps.ToneAnalyzerStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, ProjectSettings: queries, Model: contentModel},
	&steps.EditorStep{AI: aiClient, Tools: toolRegistry, Prompt: promptBuilder, Pipeline: queries, ProjectSettings: queries, Model: contentModel},
	&steps.WriterStep{AI: aiClient, Prompt: promptBuilder, Content: queries, Pipeline: queries, Model: copywritingModel},
)

pipelineHandler := handlers.NewPipelineHandler(queries, orchestrator, aiClient, copywritingModel, promptBuilder)
```

- [ ] **Step 3: Verify it compiles**

Run: `go build ./...`
Expected: SUCCESS

- [ ] **Step 4: Run all tests**

Run: `go test ./...`
Expected: PASS

- [ ] **Step 5: Manual smoke test**

Run: `make restart`

Verify:
1. Create a new pipeline run with a topic
2. Run the research step — verify SSE streaming works
3. Run remaining steps through completion
4. Approve/reject content pieces
5. Test improve chat flow
6. Test proofreading

- [ ] **Step 6: Commit**

```bash
git add web/handlers/pipeline.go cmd/server/main.go
git commit -m "feat: rewire pipeline handler to use orchestrator and step runners

Pipeline handler reduced from ~1700 to ~350 lines.
Step orchestration in internal/pipeline/.
Prompt assembly in internal/prompt/.
SSE streaming via internal/sse/."
```

---

### Task 9: Final Cleanup and Verification

**Files:**
- Possibly modify: various files for unused import cleanup

- [ ] **Step 1: Check for unused imports and dead code**

Run: `go vet ./...`
Expected: No errors

- [ ] **Step 2: Run full test suite**

Run: `go test ./... -v`
Expected: ALL PASS

- [ ] **Step 3: Verify line counts**

Run: `wc -l web/handlers/pipeline.go`
Expected: ~350 lines (down from 1707)

Run: `wc -l internal/pipeline/steps/*.go`
Expected: Each step file ~30-80 lines

- [ ] **Step 4: Full smoke test**

Run: `make restart`

Test the complete flow:
1. Create a new pipeline run
2. Run all 6 steps in order
3. Approve/reject the resulting content piece
4. Test the improve chat flow
5. Test proofreading

- [ ] **Step 5: Commit any final fixes**

```bash
git add -A
git commit -m "chore: final cleanup after backend maintainability refactor"
```
