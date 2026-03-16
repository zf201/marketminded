# Tool Calling Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add tool calling to the AI client (StreamWithTools) with three tools: fetch_url, web_search, update_section. Replace [UPDATE] marker parsing with structured SSE events.

**Architecture:** Extend ai.Client with StreamWithTools (multi-turn tool loop). Tools are Go functions in internal/tools/. Handlers register tools and pass an executor. Frontend switches on typed SSE events instead of parsing text markers.

**Tech Stack:** Go, OpenRouter API (tool_use), goquery (HTML parsing), Brave Search API, vanilla JS

---

## File Map

```
Create:
  internal/ai/tools.go                    — Tool types, StreamWithTools method, streaming delta accumulation
  internal/ai/tools_test.go               — Test StreamWithTools with mock server
  internal/tools/fetch.go                 — fetch_url tool (HTTP + goquery)
  internal/tools/fetch_test.go            — Test with mock HTTP server
  internal/tools/search.go                — web_search tool (wraps Brave)
  internal/tools/update.go                — update_section tool (validation only)

Modify:
  internal/ai/client.go                   — Export ChatMessage type, minor refactors
  web/handlers/profile.go                 — Use StreamWithTools, register 3 tools, typed SSE events
  web/handlers/brainstorm.go              — Use StreamWithTools, register 2 tools, typed SSE events
  web/handlers/pipeline.go                — Update SSE events to typed format
  web/static/app.js                       — Shared SSE handler, delete marker parsing, tool indicators
  web/static/style.css                    — Tool indicator styles
  cmd/server/main.go                      — Pass *ai.Client and *search.BraveClient to handlers
```

---

## Chunk 1: AI Client Tool Support

### Task 1: Tool types and StreamWithTools

**Files:**
- Create: `internal/ai/tools.go`
- Create: `internal/ai/tools_test.go`
- Modify: `internal/ai/client.go` (minor: export ChatMessage)

- [ ] **Step 1: Add tool types and StreamWithTools to internal/ai/tools.go**

This is the core of the feature. The file contains:
- `Tool`, `ToolFunction`, `ToolCall`, `ToolCallFunction` types (for API request/response)
- `ChatMessage` type (extends Message with tool call fields)
- `streamChoice` and `streamDelta` types for parsing streaming tool call deltas
- `ToolExecutor` and `ToolEventFn` callback types
- `ToolEvent` struct
- `StreamWithTools` method on `*Client`

```go
// internal/ai/tools.go
package ai

import (
	"bufio"
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"

	"github.com/zanfridau/marketminded/internal/types"
)

// Tool definitions for the API request
type Tool struct {
	Type     string       `json:"type"`
	Function ToolFunction `json:"function"`
}

type ToolFunction struct {
	Name        string          `json:"name"`
	Description string          `json:"description"`
	Parameters  json.RawMessage `json:"parameters"`
}

// Tool calls from the API response
type ToolCall struct {
	ID       string           `json:"id"`
	Type     string           `json:"type"`
	Function ToolCallFunction `json:"function"`
}

type ToolCallFunction struct {
	Name      string `json:"name"`
	Arguments string `json:"arguments"`
}

// ChatMessage supports tool calls and tool results
type ChatMessage struct {
	Role       string     `json:"role"`
	Content    string     `json:"content,omitempty"`
	ToolCalls  []ToolCall `json:"tool_calls,omitempty"`
	ToolCallID string     `json:"tool_call_id,omitempty"`
}

// Streaming response types
type toolChatRequest struct {
	Model    string        `json:"model"`
	Messages []ChatMessage `json:"messages"`
	Stream   bool          `json:"stream,omitempty"`
	Tools    []Tool        `json:"tools,omitempty"`
}

type streamChoice struct {
	Delta        streamDelta `json:"delta"`
	FinishReason *string     `json:"finish_reason"`
}

type streamDelta struct {
	Role      string `json:"role,omitempty"`
	Content   string `json:"content,omitempty"`
	ToolCalls []struct {
		Index    int              `json:"index"`
		ID       string           `json:"id,omitempty"`
		Type     string           `json:"type,omitempty"`
		Function ToolCallFunction `json:"function"`
	} `json:"tool_calls,omitempty"`
}

type streamResponse struct {
	Choices []streamChoice `json:"choices"`
}

// Callback types
type ToolExecutor func(ctx context.Context, toolName string, arguments string) (string, error)

type ToolEventFn func(event ToolEvent)

type ToolEvent struct {
	Type    string // "tool_start", "tool_result", "proposal"
	Tool    string
	Args    string
	Summary string
	Section string
	Content string
}

// StreamWithTools streams a chat completion with tool calling support.
// It handles the multi-turn loop: stream text → detect tool calls → execute → send results → continue.
// Returns the final accumulated text and any error.
func (c *Client) StreamWithTools(
	ctx context.Context,
	model string,
	messages []types.Message,
	tools []Tool,
	executor ToolExecutor,
	onToolEvent ToolEventFn,
	onChunk types.StreamFunc,
) (string, error) {
	// Convert types.Message to ChatMessage
	chatMsgs := make([]ChatMessage, len(messages))
	for i, m := range messages {
		chatMsgs[i] = ChatMessage{Role: m.Role, Content: m.Content}
	}

	var fullText strings.Builder

	for iteration := 0; iteration < 10; iteration++ {
		text, toolCalls, err := c.streamOneTurn(ctx, model, chatMsgs, tools, onChunk)
		if err != nil {
			return fullText.String(), err
		}
		fullText.WriteString(text)

		if len(toolCalls) == 0 {
			// No tool calls — we're done
			return fullText.String(), nil
		}

		// Append assistant message with tool calls
		assistantMsg := ChatMessage{
			Role:      "assistant",
			ToolCalls: toolCalls,
		}
		if text != "" {
			assistantMsg.Content = text
		}
		chatMsgs = append(chatMsgs, assistantMsg)

		// Execute each tool call
		for _, tc := range toolCalls {
			// Emit tool_start
			onToolEvent(ToolEvent{
				Type: "tool_start",
				Tool: tc.Function.Name,
				Args: tc.Function.Arguments,
			})

			// Execute
			result, execErr := executor(ctx, tc.Function.Name, tc.Function.Arguments)
			if execErr != nil {
				result = "Error: " + execErr.Error()
			}

			// Emit tool_result
			onToolEvent(ToolEvent{
				Type:    "tool_result",
				Tool:    tc.Function.Name,
				Args:    tc.Function.Arguments,
				Summary: result,
			})

			// Append tool result message
			chatMsgs = append(chatMsgs, ChatMessage{
				Role:       "tool",
				Content:    result,
				ToolCallID: tc.ID,
			})
		}
	}

	return fullText.String(), fmt.Errorf("max tool call iterations reached")
}

// streamOneTurn sends one request and streams the response.
// Returns accumulated text content, any tool calls, and error.
func (c *Client) streamOneTurn(
	ctx context.Context,
	model string,
	messages []ChatMessage,
	tools []Tool,
	onChunk types.StreamFunc,
) (string, []ToolCall, error) {
	body, _ := json.Marshal(toolChatRequest{
		Model:    model,
		Messages: messages,
		Stream:   true,
		Tools:    tools,
	})

	req, err := http.NewRequestWithContext(ctx, "POST", c.baseURL+"/chat/completions", bytes.NewReader(body))
	if err != nil {
		return "", nil, err
	}
	req.Header.Set("Authorization", "Bearer "+c.apiKey)
	req.Header.Set("Content-Type", "application/json")

	resp, err := c.http.Do(req)
	if err != nil {
		return "", nil, err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		b, _ := io.ReadAll(resp.Body)
		return "", nil, fmt.Errorf("openrouter: %d: %s", resp.StatusCode, string(b))
	}

	var textContent strings.Builder
	pendingToolCalls := make(map[int]*ToolCall)

	scanner := bufio.NewScanner(resp.Body)
	for scanner.Scan() {
		line := scanner.Text()
		if !strings.HasPrefix(line, "data: ") {
			continue
		}
		data := strings.TrimPrefix(line, "data: ")
		if data == "[DONE]" {
			break
		}

		var sr streamResponse
		if err := json.Unmarshal([]byte(data), &sr); err != nil {
			continue
		}
		if len(sr.Choices) == 0 {
			continue
		}
		choice := sr.Choices[0]

		// Accumulate text content
		if choice.Delta.Content != "" {
			textContent.WriteString(choice.Delta.Content)
			if err := onChunk(choice.Delta.Content); err != nil {
				return textContent.String(), nil, err
			}
		}

		// Accumulate tool call deltas
		for _, tc := range choice.Delta.ToolCalls {
			existing, ok := pendingToolCalls[tc.Index]
			if !ok {
				existing = &ToolCall{
					ID:   tc.ID,
					Type: tc.Type,
					Function: ToolCallFunction{
						Name: tc.Function.Name,
					},
				}
				pendingToolCalls[tc.Index] = existing
			}
			// Append arguments fragment
			existing.Function.Arguments += tc.Function.Arguments
		}
	}

	if err := scanner.Err(); err != nil {
		return textContent.String(), nil, err
	}

	// Collect tool calls in order
	var toolCalls []ToolCall
	for i := 0; i < len(pendingToolCalls); i++ {
		if tc, ok := pendingToolCalls[i]; ok {
			toolCalls = append(toolCalls, *tc)
		}
	}

	return textContent.String(), toolCalls, nil
}
```

- [ ] **Step 2: Write test**

```go
// internal/ai/tools_test.go
package ai

import (
	"context"
	"fmt"
	"net/http"
	"net/http/httptest"
	"testing"

	"github.com/zanfridau/marketminded/internal/types"
)

func TestStreamWithTools_NoToolCalls(t *testing.T) {
	// Server that returns a simple text response with no tool calls
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/event-stream")
		fmt.Fprintln(w, `data: {"choices":[{"delta":{"content":"Hello "},"finish_reason":null}]}`)
		fmt.Fprintln(w, `data: {"choices":[{"delta":{"content":"world!"},"finish_reason":"stop"}]}`)
		fmt.Fprintln(w, `data: [DONE]`)
	}))
	defer server.Close()

	c := NewClient("test-key", WithBaseURL(server.URL))
	var chunks []string
	text, err := c.StreamWithTools(
		context.Background(), "test-model",
		[]types.Message{{Role: "user", Content: "hi"}},
		nil, // no tools
		func(ctx context.Context, name, args string) (string, error) { return "", nil },
		func(event ToolEvent) {},
		func(chunk string) error { chunks = append(chunks, chunk); return nil },
	)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if text != "Hello world!" {
		t.Errorf("expected 'Hello world!', got %q", text)
	}
	if len(chunks) != 2 {
		t.Errorf("expected 2 chunks, got %d", len(chunks))
	}
}

func TestStreamWithTools_WithToolCall(t *testing.T) {
	callCount := 0
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		callCount++
		w.Header().Set("Content-Type", "text/event-stream")
		if callCount == 1 {
			// First call: AI wants to call a tool
			fmt.Fprintln(w, `data: {"choices":[{"delta":{"content":"Let me check. "},"finish_reason":null}]}`)
			fmt.Fprintln(w, `data: {"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_1","type":"function","function":{"name":"web_search","arguments":""}}]},"finish_reason":null}]}`)
			fmt.Fprintln(w, `data: {"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"query\":\"test\"}"}}]},"finish_reason":"tool_calls"}]}`)
			fmt.Fprintln(w, `data: [DONE]`)
		} else {
			// Second call: AI responds with the tool result
			fmt.Fprintln(w, `data: {"choices":[{"delta":{"content":"Found some results!"},"finish_reason":"stop"}]}`)
			fmt.Fprintln(w, `data: [DONE]`)
		}
	}))
	defer server.Close()

	c := NewClient("test-key", WithBaseURL(server.URL))
	var events []ToolEvent
	text, err := c.StreamWithTools(
		context.Background(), "test-model",
		[]types.Message{{Role: "user", Content: "search for test"}},
		[]Tool{{Type: "function", Function: ToolFunction{Name: "web_search", Description: "search"}}},
		func(ctx context.Context, name, args string) (string, error) {
			return "Search results here", nil
		},
		func(event ToolEvent) { events = append(events, event) },
		func(chunk string) error { return nil },
	)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if !strings.Contains(text, "Found some results!") {
		t.Errorf("expected final text, got %q", text)
	}
	if callCount != 2 {
		t.Errorf("expected 2 API calls, got %d", callCount)
	}
	if len(events) < 2 {
		t.Fatalf("expected at least 2 events, got %d", len(events))
	}
	if events[0].Type != "tool_start" {
		t.Errorf("expected tool_start, got %s", events[0].Type)
	}
	if events[1].Type != "tool_result" {
		t.Errorf("expected tool_result, got %s", events[1].Type)
	}
}
```

- [ ] **Step 3: Run tests**

```bash
go test ./internal/ai/ -v
# Expected: PASS
```

- [ ] **Step 4: Commit**

```bash
git add internal/ai/tools.go internal/ai/tools_test.go
git commit -m "feat: add StreamWithTools with multi-turn tool calling loop"
```

---

## Chunk 2: Tool Implementations

### Task 2: fetch_url tool

**Files:**
- Create: `internal/tools/fetch.go`
- Create: `internal/tools/fetch_test.go`

- [ ] **Step 1: Add goquery dependency**

```bash
go get github.com/PuerkitoBio/goquery
```

- [ ] **Step 2: Implement fetch.go**

```go
// internal/tools/fetch.go
package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"net/url"
	"strings"
	"time"

	"github.com/PuerkitoBio/goquery"
	"github.com/zanfridau/marketminded/internal/ai"
)

var fetchHTTPClient = &http.Client{Timeout: 10 * time.Second}

func NewFetchTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "fetch_url",
			Description: "Fetch a URL and extract the main text content from the page. Use this when the user shares a link or you need to read a webpage.",
			Parameters:  json.RawMessage(`{"type":"object","properties":{"url":{"type":"string","description":"The URL to fetch"}},"required":["url"]}`),
		},
	}
}

type fetchArgs struct {
	URL string `json:"url"`
}

func ExecuteFetch(ctx context.Context, argsJSON string) (string, error) {
	var args fetchArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}

	req, err := http.NewRequestWithContext(ctx, "GET", args.URL, nil)
	if err != nil {
		return "", fmt.Errorf("invalid URL: %w", err)
	}
	req.Header.Set("User-Agent", "Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36")
	req.Header.Set("Accept", "text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8")
	req.Header.Set("Accept-Language", "en-US,en;q=0.5")

	resp, err := fetchHTTPClient.Do(req)
	if err != nil {
		return "", fmt.Errorf("fetch failed: %w", err)
	}
	defer resp.Body.Close()

	// Limit to 1MB
	limited := io.LimitReader(resp.Body, 1<<20)

	doc, err := goquery.NewDocumentFromReader(limited)
	if err != nil {
		return "", fmt.Errorf("parse HTML failed: %w", err)
	}

	// Extract title
	title := strings.TrimSpace(doc.Find("title").First().Text())

	// Remove noise elements
	doc.Find("script, style, nav, footer, header, aside, iframe, noscript").Remove()

	// Get text content
	text := strings.TrimSpace(doc.Find("body").Text())

	// Collapse whitespace
	lines := strings.Split(text, "\n")
	var cleaned []string
	for _, line := range lines {
		line = strings.TrimSpace(line)
		if line != "" {
			cleaned = append(cleaned, line)
		}
	}
	text = strings.Join(cleaned, "\n")

	// Truncate
	if len(text) > 4000 {
		text = text[:4000] + "..."
	}

	return fmt.Sprintf("Title: %s\n\n%s", title, text), nil
}

// FetchSummary returns a human-readable summary for the frontend indicator
func FetchSummary(argsJSON string) string {
	var args fetchArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "Fetching URL..."
	}
	u, err := url.Parse(args.URL)
	if err != nil {
		return "Fetching URL..."
	}
	return fmt.Sprintf("Fetching: %s", u.Host)
}
```

- [ ] **Step 3: Write test**

```go
// internal/tools/fetch_test.go
package tools

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestExecuteFetch(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Header.Get("User-Agent") == "" {
			t.Error("missing user agent")
		}
		w.Header().Set("Content-Type", "text/html")
		w.Write([]byte(`<html><head><title>Test Page</title></head><body><nav>nav</nav><main><p>Hello world</p><p>Content here</p></main><script>bad</script></body></html>`))
	}))
	defer server.Close()

	args, _ := json.Marshal(fetchArgs{URL: server.URL})
	result, err := ExecuteFetch(context.Background(), string(args))
	if err != nil {
		t.Fatalf("fetch: %v", err)
	}
	if !strings.Contains(result, "Test Page") {
		t.Errorf("expected title, got: %s", result)
	}
	if !strings.Contains(result, "Hello world") {
		t.Errorf("expected content, got: %s", result)
	}
	if strings.Contains(result, "bad") {
		t.Error("script content should be removed")
	}
}

func TestFetchSummary(t *testing.T) {
	args, _ := json.Marshal(fetchArgs{URL: "https://example.com/page"})
	summary := FetchSummary(string(args))
	if summary != "Fetching: example.com" {
		t.Errorf("unexpected summary: %s", summary)
	}
}
```

- [ ] **Step 4: Run tests**

```bash
go test ./internal/tools/ -v
# Expected: PASS
```

- [ ] **Step 5: Commit**

```bash
git add internal/tools/
git commit -m "feat: add fetch_url tool with goquery HTML extraction"
```

---

### Task 3: web_search and update_section tools

**Files:**
- Create: `internal/tools/search.go`
- Create: `internal/tools/update.go`

- [ ] **Step 1: Implement search.go**

```go
// internal/tools/search.go
package tools

import (
	"context"
	"encoding/json"
	"fmt"
	"strings"

	"github.com/zanfridau/marketminded/internal/ai"
	"github.com/zanfridau/marketminded/internal/search"
)

func NewSearchTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "web_search",
			Description: "Search the web for information. Use this to research topics, find competitors, discover trends, or look up anything the user mentions.",
			Parameters:  json.RawMessage(`{"type":"object","properties":{"query":{"type":"string","description":"The search query"}},"required":["query"]}`),
		},
	}
}

type searchArgs struct {
	Query string `json:"query"`
}

func NewSearchExecutor(braveClient *search.BraveClient) func(ctx context.Context, argsJSON string) (string, error) {
	return func(ctx context.Context, argsJSON string) (string, error) {
		var args searchArgs
		if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
			return "", fmt.Errorf("invalid arguments: %w", err)
		}

		results, err := braveClient.Search(ctx, args.Query, 5)
		if err != nil {
			return "", fmt.Errorf("search failed: %w", err)
		}

		var b strings.Builder
		for i, r := range results {
			fmt.Fprintf(&b, "%d. %s (%s)\n   %s\n\n", i+1, r.Title, r.URL, r.Description)
		}
		return b.String(), nil
	}
}

func SearchSummary(argsJSON string) string {
	var args searchArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "Searching..."
	}
	return fmt.Sprintf("Searched: %s", args.Query)
}
```

- [ ] **Step 2: Implement update.go**

```go
// internal/tools/update.go
package tools

import (
	"context"
	"encoding/json"
	"fmt"

	"github.com/zanfridau/marketminded/internal/ai"
)

var validSections = map[string]bool{
	"business": true, "audience": true, "voice": true, "tone": true,
	"strategy": true, "pillars": true, "guidelines": true,
	"competitors": true, "inspiration": true, "offers": true,
}

func NewUpdateSectionTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "update_section",
			Description: "Propose an update to a profile section. The user will be asked to accept or reject. Write the full new content for the section as clear, natural prose.",
			Parameters: json.RawMessage(`{"type":"object","properties":{"section":{"type":"string","enum":["business","audience","voice","tone","strategy","pillars","guidelines","competitors","inspiration","offers"],"description":"The profile section to update"},"content":{"type":"string","description":"The full new content for this section. Write natural prose, not JSON."}},"required":["section","content"]}`),
		},
	}
}

type UpdateArgs struct {
	Section string `json:"section"`
	Content string `json:"content"`
}

func ExecuteUpdateSection(ctx context.Context, argsJSON string) (string, error) {
	var args UpdateArgs
	if err := json.Unmarshal([]byte(argsJSON), &args); err != nil {
		return "", fmt.Errorf("invalid arguments: %w", err)
	}
	if !validSections[args.Section] {
		return fmt.Sprintf("Error: '%s' is not a valid section. Valid sections: business, audience, voice, tone, strategy, pillars, guidelines, competitors, inspiration, offers.", args.Section), nil
	}
	return fmt.Sprintf("Proposed update to %s section. Waiting for user approval.", args.Section), nil
}

func ParseUpdateArgs(argsJSON string) (UpdateArgs, error) {
	var args UpdateArgs
	err := json.Unmarshal([]byte(argsJSON), &args)
	return args, err
}
```

- [ ] **Step 3: Run tests**

```bash
go test ./internal/tools/ -v
go test ./... -v
# Expected: PASS
```

- [ ] **Step 4: Commit**

```bash
git add internal/tools/search.go internal/tools/update.go
git commit -m "feat: add web_search and update_section tools"
```

---

## Chunk 3: Handler Updates

### Task 4: Update profile handler

**Files:**
- Modify: `web/handlers/profile.go`
- Modify: `cmd/server/main.go`

- [ ] **Step 1: Rewrite profile handler to use StreamWithTools**

Key changes to `web/handlers/profile.go`:
- Constructor takes `*ai.Client` (concrete), `*search.BraveClient`, and `func() string` model
- Remove `[UPDATE]` marker instructions from system prompt
- Remove "You CANNOT browse URLs" note
- The `stream` method builds tool list, creates executor switch, creates onToolEvent callback that writes typed SSE events, calls `StreamWithTools`
- SSE events use typed format: `{"type":"chunk","chunk":"..."}`, `{"type":"tool_start",...}`, etc.
- After streaming, save tool usage summaries + final response to DB

The executor switch:
```go
executor := func(ctx context.Context, name, args string) (string, error) {
    switch name {
    case "fetch_url":
        return tools.ExecuteFetch(ctx, args)
    case "web_search":
        return searchExecutor(ctx, args)
    case "update_section":
        return tools.ExecuteUpdateSection(ctx, args)
    default:
        return "", fmt.Errorf("unknown tool: %s", name)
    }
}
```

The onToolEvent callback — sends typed SSE events AND handles `update_section` specially:
```go
onToolEvent := func(event ai.ToolEvent) {
    switch event.Type {
    case "tool_start":
        summary := ""
        switch event.Tool {
        case "fetch_url":
            summary = tools.FetchSummary(event.Args)
        case "web_search":
            summary = tools.SearchSummary(event.Args)
        }
        sendEvent(map[string]string{"type": "tool_start", "tool": event.Tool, "summary": summary})
    case "tool_result":
        summary := event.Summary
        // Truncate summary for frontend
        if len(summary) > 200 {
            summary = summary[:200] + "..."
        }
        sendEvent(map[string]string{"type": "tool_result", "tool": event.Tool, "summary": summary})
    }

    // Special handling for update_section: emit proposal event
    if event.Tool == "update_section" && event.Type == "tool_result" {
        args, _ := tools.ParseUpdateArgs(event.Args)
        sendEvent(map[string]string{"type": "proposal", "section": args.Section, "content": args.Content})
    }
}
```

System prompt changes: remove `[UPDATE]` format section and URL note. Keep section definitions and conversational rules.

- [ ] **Step 2: Update main.go**

```go
profileHandler := handlers.NewProfileHandler(queries, aiClient, braveClient, contentModel)
brainstormHandler := handlers.NewBrainstormHandler(queries, aiClient, braveClient, ideationModel)
```

- [ ] **Step 3: Build to verify**

```bash
go build ./...
```

- [ ] **Step 4: Commit**

```bash
git add web/handlers/profile.go cmd/server/main.go
git commit -m "feat: profile handler uses StreamWithTools with fetch, search, update tools"
```

---

### Task 5: Update brainstorm handler

**Files:**
- Modify: `web/handlers/brainstorm.go`

- [ ] **Step 1: Update brainstorm handler**

Key changes:
- Constructor takes `*ai.Client` (concrete), `*search.BraveClient`, `func() string`
- `streamResponse` uses `StreamWithTools` with `fetch_url` + `web_search` tools (no `update_section`)
- SSE events use typed format
- Add to system prompt: "You have access to web search and URL fetching tools. Use them when the user asks you to research something or shares a URL."

- [ ] **Step 2: Build to verify**

```bash
go build ./...
```

- [ ] **Step 3: Commit**

```bash
git add web/handlers/brainstorm.go
git commit -m "feat: brainstorm handler uses StreamWithTools with fetch and search tools"
```

---

### Task 6: Update pipeline handler SSE format

**Files:**
- Modify: `web/handlers/pipeline.go`

- [ ] **Step 1: Update SSE events to typed format**

Change all `sendChunk` calls to use `{"type":"chunk","chunk":"..."}`. Change `sendDone` to `{"type":"done"}`. Change `sendError` to `{"type":"error","error":"..."}`. Pipeline doesn't use tools — just the event format change.

- [ ] **Step 2: Build to verify**

```bash
go build ./...
```

- [ ] **Step 3: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "refactor: pipeline handler uses typed SSE events"
```

---

## Chunk 4: Frontend

### Task 7: Shared SSE handler + profile chat rewrite

**Files:**
- Modify: `web/static/app.js`
- Modify: `web/static/style.css`

- [ ] **Step 1: Add tool indicator CSS to style.css**

```css
/* Tool indicators */
.tool-indicator { color: #6b7280; font-size: 0.85rem; padding: 0.5rem 0; }
.tool-indicator .typing-indicator { display: inline; }
.tool-result-block { margin: 0.25rem 0; }
.tool-result-block summary { font-size: 0.8rem; color: #6b7280; cursor: pointer; }
.tool-result-block summary:hover { color: #374151; }
```

- [ ] **Step 2: Rewrite app.js**

Key changes:
- Add shared `handleSSEEvent(data, handlers)` function that switches on `data.type`
- Rewrite `initProfileChat` to use `handleSSEEvent` — delete ALL `[UPDATE]` marker/buffer parsing logic
- Add `onToolStart` handler: creates a tool indicator div with spinner
- Add `onToolResult` handler: replaces indicator with collapsible `<details>` block
- Add `onProposal` handler: creates Accept/Reject proposal block (same UI as before, but triggered by event not text parsing)
- Update brainstorm chat JS to use `handleSSEEvent` — switch on `data.type` instead of checking `data.done`/`data.chunk`/`data.error`
- Update pipeline `streamOutput` Alpine component to use `data.type` field

The `onChunk` handler is now simple — just append text, no buffer:
```js
onChunk: function(chunk) { addChatText(aBody, chunk); scrollToBottom(); }
```

- [ ] **Step 3: Verify build (static files don't need compilation)**

```bash
go build ./...
```

- [ ] **Step 4: Commit**

```bash
git add web/static/app.js web/static/style.css
git commit -m "feat: shared SSE handler with tool indicators, delete marker parsing"
```

---

## Chunk 5: Integration

### Task 8: Final wiring and smoke test

- [ ] **Step 1: go mod tidy**

```bash
go get github.com/PuerkitoBio/goquery
go mod tidy
```

- [ ] **Step 2: Run all tests**

```bash
go test ./... -v
# Expected: all PASS
```

- [ ] **Step 3: Delete DB, rebuild, manual test**

```bash
rm -f marketminded.db
templ generate ./web/templates/
go build -o server ./cmd/server/
OPENROUTER_API_KEY=... BRAVE_API_KEY=... ./server
```

Test:
1. Profile chat: type "check out https://example.com" → should see "Fetching: example.com" indicator, then AI analyzes and proposes updates
2. Profile chat: type something about the business → should see proposal blocks with Accept/Reject
3. Brainstorm chat: type "search for content marketing trends" → should see search indicator + results
4. Pipeline: verify streaming still works with new event format

- [ ] **Step 4: Commit any fixes**

```bash
git add -A
git commit -m "feat: complete tool calling — fetch, search, update_section with typed SSE events"
```
