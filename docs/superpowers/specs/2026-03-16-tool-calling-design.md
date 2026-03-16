# Tool Calling — fetch, search, update_section

## Overview

Add tool calling support to the AI client so chat agents can fetch URLs, search the web, and propose profile updates via structured tool calls instead of text markers. Multi-turn loop: AI streams text, calls tools, backend executes them, sends results back, AI continues.

## AI Client Changes

### New types in `internal/ai/client.go`

```go
type Tool struct {
    Type     string       `json:"type"` // "function"
    Function ToolFunction `json:"function"`
}

type ToolFunction struct {
    Name        string          `json:"name"`
    Description string          `json:"description"`
    Parameters  json.RawMessage `json:"parameters"` // JSON Schema
}

type ToolCall struct {
    ID       string           `json:"id"`
    Type     string           `json:"type"` // "function"
    Function ToolCallFunction `json:"function"`
}

type ToolCallFunction struct {
    Name      string `json:"name"`
    Arguments string `json:"arguments"` // JSON string
}
```

### Updated request/response types

```go
type chatRequest struct {
    Model    string          `json:"model"`
    Messages []ChatMessage   `json:"messages"`
    Stream   bool            `json:"stream,omitempty"`
    Tools    []Tool          `json:"tools,omitempty"`
}

// ChatMessage extends types.Message to support tool calls and tool results
type ChatMessage struct {
    Role       string     `json:"role"`
    Content    string     `json:"content,omitempty"`
    ToolCalls  []ToolCall `json:"tool_calls,omitempty"`  // assistant messages with tool calls
    ToolCallID string     `json:"tool_call_id,omitempty"` // tool result messages
    Name       string     `json:"name,omitempty"`         // tool name for tool results
}
```

### New method: `StreamWithTools`

```go
type ToolExecutor func(ctx context.Context, toolName string, arguments string) (string, error)

type ToolEventFn func(event ToolEvent)

type ToolEvent struct {
    Type    string // "tool_start", "tool_result", "proposal"
    Tool    string // tool name
    Args    string // raw JSON args
    Summary string // human-readable summary
    Section string // for update_section proposals
    Content string // for update_section proposals
}

func (c *Client) StreamWithTools(
    ctx context.Context,
    model string,
    messages []types.Message,
    tools []Tool,
    executor ToolExecutor,
    onToolEvent ToolEventFn,
    onChunk types.StreamFunc,
) (string, error)
```

**The multi-turn loop:**

1. Convert `[]types.Message` to `[]ChatMessage`, send request with tools
2. Stream response:
   - Text chunks (`delta.content`) → pass to `onChunk`, accumulate into full text
   - Tool call deltas (`delta.tool_calls`) → accumulate using `index` as key (see below)
   - Detect end of turn via `finish_reason` on final chunk
3. When turn ends:
   - If `finish_reason == "stop"` or no tool calls → return accumulated text. Done.
   - If `finish_reason == "tool_calls"`:
     a. For each accumulated tool call, emit `tool_start` event via `onToolEvent`
     b. Call `executor(ctx, name, args)` to get result
     c. Emit `tool_result` event via `onToolEvent`
     d. If tool is `update_section`, parse args and also emit `proposal` event
     e. Append assistant message (with tool calls, `content` omitted) + tool result messages to conversation
     f. Send new request to API with updated messages. Continue streaming.
4. Max 10 loop iterations to prevent infinite tool call loops.

**Streaming tool call delta accumulation:**

OpenRouter sends tool call data across multiple SSE chunks using an `index` field:

```json
{"choices":[{"delta":{"tool_calls":[{"index":0,"id":"call_abc","type":"function","function":{"name":"fetch_url","arguments":""}}]}}]}
{"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"{\"url\":"}}]}}]}
{"choices":[{"delta":{"tool_calls":[{"index":0,"function":{"arguments":"\"https://example.com\"}"}}]}}]}
```

Use a `map[int]*ToolCall` keyed by `index`. The `id`, `type`, and `name` appear only on the first delta for that index. Subsequent deltas append to `arguments`. The streaming delta type:

```go
type streamDelta struct {
    Role      string `json:"role"`
    Content   string `json:"content"`
    ToolCalls []struct {
        Index    int              `json:"index"`
        ID       string           `json:"id"`
        Type     string           `json:"type"`
        Function ToolCallFunction `json:"function"`
    } `json:"tool_calls"`
}
```

The `finish_reason` is on the choice object:

```go
type streamChoice struct {
    Delta        streamDelta `json:"delta"`
    FinishReason *string     `json:"finish_reason"`
}
```

### Existing methods unchanged

`Complete` and `Stream` remain as-is. No interface changes. `StreamWithTools` is only on the concrete `*Client` type. Handlers that need tools use `*ai.Client` directly instead of `types.AIClient`.

### Conversation persistence

After `StreamWithTools` completes, the handler needs to save the full conversation for continuity. The approach:

- `StreamWithTools` returns both the final text AND the full `[]ChatMessage` history (including intermediate tool call/result messages)
- The handler saves all messages to the DB: assistant messages with tool calls get serialized as text summaries (e.g. "[Used fetch_url: smell100.com]"), tool results are NOT persisted as separate messages
- The final assistant text response is saved as a normal assistant message
- On page reload, the chat history has the text-only summaries. The model loses tool call history but keeps the conversational context, which is sufficient.

This avoids complex message type storage while keeping the chat readable on reload.

## Tools

### internal/tools/tools.go

Central tool definitions and executor factory.

Provides tool definitions (for the API request) and executor functions. Each file exports a `NewXxxTool() Tool` that returns the API definition, and an `ExecuteXxx(ctx, args) (string, error)` function. Handlers build their `ToolExecutor` as a switch dispatching to the right `Execute` function.

### fetch_url

**File:** `internal/tools/fetch.go`

- **Parameters:** `{"type":"object","properties":{"url":{"type":"string","description":"The URL to fetch"}},"required":["url"]}`
- **Action:**
  1. HTTP GET with headers:
     - `User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36`
     - `Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8`
     - `Accept-Language: en-US,en;q=0.5`
  2. Use a dedicated `http.Client` with 10-second timeout and `io.LimitReader(resp.Body, 1<<20)` (1MB max)
  3. Parse HTML with `goquery`
  4. Remove `script`, `style`, `nav`, `footer`, `header` elements
  5. Extract `<title>` for the summary
  6. Get text content from `body`, collapse whitespace
  7. Truncate to 4000 characters
- **Returns:** `"Title: {title}\n\n{text content}"`
- **Summary for frontend:** `"Fetched: {hostname} — {title}"`
- **Dependency:** `go get github.com/PuerkitoBio/goquery`

### web_search

**File:** `internal/tools/search.go`

- **Parameters:** `{"type":"object","properties":{"query":{"type":"string","description":"The search query"}},"required":["query"]}`
- **Action:** Calls existing `search.BraveClient.Search(ctx, query, 5)`
- **Returns:** Formatted text: `"1. {title} ({url})\n   {description}\n\n2. ..."`
- **Summary for frontend:** `"Searched: {query} — {n} results"`
- **Dependency:** Takes `*search.BraveClient` as constructor arg

### update_section

**File:** `internal/tools/update.go`

- **Parameters:** `{"type":"object","properties":{"section":{"type":"string","enum":["business","audience","voice","tone","strategy","pillars","guidelines","competitors","inspiration","offers"],"description":"The profile section to update"},"content":{"type":"string","description":"The full new content for this section"}},"required":["section","content"]}`
- **Action:** Validate section name against the known list. If invalid, return error to model. No backend mutation otherwise.
- **Returns:** `"Proposed update to {section} section. Waiting for user approval."`
- **Special handling:** The handler's `onToolEvent` callback detects `update_section` calls and emits a `proposal` SSE event to the frontend. The content is NOT saved until the user clicks Accept.

## SSE Event Protocol

Replace the current untyped `{chunk/done/error}` format with typed events:

```json
{"type":"chunk","chunk":"text here"}
{"type":"tool_start","tool":"fetch_url","args":"{\"url\":\"https://smell100.com\"}"}
{"type":"tool_result","tool":"fetch_url","summary":"Fetched: smell100.com — Smell100 | Perfume Discovery"}
{"type":"proposal","section":"business","content":"Smell100 is a fragrance discovery platform..."}
{"type":"error","error":"something went wrong"}
{"type":"done"}
```

All SSE consumers (profile chat, brainstorm chat) must be updated to switch on `type`.

## Handler Changes

### Profile handler (`web/handlers/profile.go`)

The `stream` method:
1. Builds tool list: `fetch_url`, `web_search`, `update_section`
2. Creates executor that dispatches to the right tool function
3. Creates `onToolEvent` callback that writes SSE events for `tool_start`, `tool_result`, `proposal`
4. Calls `client.StreamWithTools(...)` instead of `client.Stream(...)`
5. Text chunks still go to `onChunk` → SSE `{"type":"chunk",...}`

**Constructor change:** `NewProfileHandler` takes `*ai.Client` (concrete type, not interface) + tool dependencies (`*search.BraveClient`).

### Brainstorm handler (`web/handlers/brainstorm.go`)

Same pattern but only registers `fetch_url` + `web_search`. No `update_section`.

**Constructor change:** `NewBrainstormHandler` takes `*ai.Client` + `*search.BraveClient`.

### Pipeline handler

Unchanged. Uses `Stream` (no tools).

## System Prompt Changes

### Profile chat

Remove:
- The `[UPDATE]` marker format instructions
- The "You CANNOT browse URLs" note

Add to the tool descriptions themselves (via the `description` field on each tool). The AI knows how to use tools from the schema — no need for verbose prompt instructions.

Keep the section definitions and rules about being conversational.

### Brainstorm chat

Add note: "You have access to web search and URL fetching tools. Use them when the user asks you to research something or shares a URL."

## Frontend Changes

### Shared SSE handler in `web/static/app.js`

Extract a reusable function:

```js
function handleSSEEvent(data, handlers) {
    switch (data.type) {
        case 'chunk': handlers.onChunk(data.chunk); break;
        case 'tool_start': handlers.onToolStart(data.tool, data.args); break;
        case 'tool_result': handlers.onToolResult(data.tool, data.summary); break;
        case 'proposal': handlers.onProposal(data.section, data.content); break;
        case 'error': handlers.onError(data.error); break;
        case 'done': handlers.onDone(); break;
    }
}
```

### Profile chat JS

- Delete all `[UPDATE]` marker/buffer parsing code
- Use `handleSSEEvent` with:
  - `onChunk` → append text to chat bubble
  - `onToolStart` → show indicator: "Fetching smell100.com..." or "Searching: query..."
  - `onToolResult` → replace indicator with collapsible summary block
  - `onProposal` → render Accept/Reject proposal block (same UI)
  - `onDone` → re-enable input

### Brainstorm chat JS

- Update to use `handleSSEEvent` with same handlers minus `onProposal`

### Tool indicator UI

```html
<div class="tool-indicator">
    <span class="typing-indicator">...</span> Fetching smell100.com...
</div>
```

Replaced by collapsible summary on `tool_result`:
```html
<details class="tool-result">
    <summary>Fetched: smell100.com — Smell100 | Perfume Discovery</summary>
</details>
```

## What Gets Removed

- `[UPDATE:section]...[/UPDATE]` marker format in system prompt
- Buffer/marker parsing JS (the entire `inUpdate`/`buffer`/`openIdx` logic)
- "You CANNOT browse URLs" system prompt note

## What Gets Added

- `internal/ai/client.go` — `Tool`, `ToolCall`, `ChatMessage` types, `StreamWithTools` method
- `internal/tools/tools.go` — tool registry and `ToolDef` type
- `internal/tools/fetch.go` — `fetch_url` implementation with goquery
- `internal/tools/search.go` — `web_search` wrapping Brave client
- `internal/tools/update.go` — `update_section` (returns proposal message)
- Updated SSE event format everywhere

## What Gets Modified

- `web/handlers/profile.go` — use `StreamWithTools`, register 3 tools
- `web/handlers/brainstorm.go` — use `StreamWithTools`, register 2 tools
- `web/static/app.js` — shared SSE handler, delete marker parsing
- `cmd/server/main.go` — pass `*ai.Client` and `*search.BraveClient` to handlers

## Dependencies

- `go get github.com/PuerkitoBio/goquery` — HTML parsing for fetch_url
