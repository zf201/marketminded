# Thinking/Reasoning Display Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Surface AI reasoning/thinking tokens in a collapsible section above the output during plan and cornerstone piece generation.

**Architecture:** Add `onReasoning` callback to the streaming pipeline (`StreamWithTools` → `streamOneTurn`), send reasoning as `{type: "thinking"}` SSE events, render in a collapsible `<details>` element in the frontend `streamToElement` helper.

**Tech Stack:** Go, OpenRouter API (SSE), JavaScript (vanilla), CSS

**Spec:** `docs/superpowers/specs/2026-03-25-thinking-display-design.md`

---

### Task 1: Add Reasoning Parsing to AI Client

**Files:**
- Modify: `internal/ai/tools.go:62-71` (streamDelta struct)
- Modify: `internal/ai/tools.go:94-103` (StreamWithTools signature)
- Modify: `internal/ai/tools.go:112-113` (streamOneTurn call inside StreamWithTools)
- Modify: `internal/ai/tools.go:171-177` (streamOneTurn signature)
- Modify: `internal/ai/tools.go:228-234` (content chunk handling in streamOneTurn)
- Modify: `internal/ai/tools_test.go:26-34,67-77` (test calls)

- [ ] **Step 1: Add reasoning fields to streamDelta**

In `internal/ai/tools.go`, change the `streamDelta` struct (line 62-71) from:

```go
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
```

to:

```go
type streamDelta struct {
	Role             string `json:"role,omitempty"`
	Content          string `json:"content,omitempty"`
	Reasoning        string `json:"reasoning,omitempty"`
	ReasoningDetails []struct {
		Type string `json:"type"`
		Text string `json:"text"`
	} `json:"reasoning_details,omitempty"`
	ToolCalls []struct {
		Index    int              `json:"index"`
		ID       string           `json:"id,omitempty"`
		Type     string           `json:"type,omitempty"`
		Function ToolCallFunction `json:"function"`
	} `json:"tool_calls,omitempty"`
}
```

- [ ] **Step 2: Add onReasoning parameter to StreamWithTools**

Change the `StreamWithTools` signature (line 94-103) from:

```go
func (c *Client) StreamWithTools(
	ctx context.Context,
	model string,
	messages []types.Message,
	tools []Tool,
	executor ToolExecutor,
	onToolEvent ToolEventFn,
	onChunk types.StreamFunc,
	temperature *float64,
) (string, error) {
```

to:

```go
func (c *Client) StreamWithTools(
	ctx context.Context,
	model string,
	messages []types.Message,
	tools []Tool,
	executor ToolExecutor,
	onToolEvent ToolEventFn,
	onChunk types.StreamFunc,
	onReasoning types.StreamFunc,
	temperature *float64,
) (string, error) {
```

- [ ] **Step 3: Pass onReasoning to streamOneTurn**

Inside `StreamWithTools`, update the `streamOneTurn` call (line 113) to pass `onReasoning`:

```go
text, toolCalls, err := c.streamOneTurn(ctx, model, chatMsgs, tools, onChunk, onReasoning, temperature)
```

- [ ] **Step 4: Add onReasoning parameter to streamOneTurn**

Change the `streamOneTurn` signature (line 171-177) from:

```go
func (c *Client) streamOneTurn(
	ctx context.Context,
	model string,
	messages []ChatMessage,
	tools []Tool,
	onChunk types.StreamFunc,
	temperature *float64,
) (string, []ToolCall, error) {
```

to:

```go
func (c *Client) streamOneTurn(
	ctx context.Context,
	model string,
	messages []ChatMessage,
	tools []Tool,
	onChunk types.StreamFunc,
	onReasoning types.StreamFunc,
	temperature *float64,
) (string, []ToolCall, error) {
```

- [ ] **Step 5: Handle reasoning in the stream loop**

In `streamOneTurn`, after the content chunk block (line 228-234), add reasoning handling:

```go
		// Handle reasoning — support both field formats
		if choice.Delta.Reasoning != "" {
			onReasoning(choice.Delta.Reasoning)
		}
		for _, rd := range choice.Delta.ReasoningDetails {
			if rd.Type == "reasoning.text" && rd.Text != "" {
				onReasoning(rd.Text)
			}
		}
```

- [ ] **Step 6: Update test calls**

In `internal/ai/tools_test.go`, update both `StreamWithTools` calls to add a no-op `onReasoning` argument (9th param, before `temperature`).

Test 1 (line 26-34) — add `func(chunk string) error { return nil },` after the `onChunk` line:

```go
	text, err := c.StreamWithTools(
		context.Background(), "test-model",
		[]types.Message{{Role: "user", Content: "hi"}},
		nil, // no tools
		func(ctx context.Context, name, args string) (string, error) { return "", nil },
		func(event ToolEvent) {},
		func(chunk string) error { chunks = append(chunks, chunk); return nil },
		func(chunk string) error { return nil }, // onReasoning
		nil,
	)
```

Test 2 (line 67-77) — same pattern:

```go
	text, err := c.StreamWithTools(
		context.Background(), "test-model",
		[]types.Message{{Role: "user", Content: "search for test"}},
		[]Tool{{Type: "function", Function: ToolFunction{Name: "web_search", Description: "search"}}},
		func(ctx context.Context, name, args string) (string, error) {
			return "Search results here", nil
		},
		func(event ToolEvent) { events = append(events, event) },
		func(chunk string) error { return nil },
		func(chunk string) error { return nil }, // onReasoning
		nil,
	)
```

- [ ] **Step 7: Run tests**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go test ./internal/ai/... -v`
Expected: PASS

- [ ] **Step 8: Commit**

```bash
git add internal/ai/tools.go internal/ai/tools_test.go
git commit -m "feat: parse reasoning/thinking tokens from OpenRouter streaming responses"
```

---

### Task 2: Update All StreamWithTools Callers with No-Op

**Files:**
- Modify: `web/handlers/brainstorm.go:257` (StreamWithTools call)
- Modify: `web/handlers/profile.go:298` (StreamWithTools call)

- [ ] **Step 1: Update brainstorm.go**

In `web/handlers/brainstorm.go` line 257, add the no-op `onReasoning` argument after `sendChunk`:

```go
fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, func(string) error { return nil }, &temp)
```

- [ ] **Step 2: Update profile.go**

In `web/handlers/profile.go` line 298, same change:

```go
fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, func(string) error { return nil }, &temp)
```

- [ ] **Step 3: Verify build**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go build ./...`
Expected: no errors

- [ ] **Step 4: Commit**

```bash
git add web/handlers/brainstorm.go web/handlers/profile.go
git commit -m "chore: update brainstorm and profile StreamWithTools calls with no-op reasoning"
```

---

### Task 3: Pipeline SSE — sendThinking + Wiring

**Files:**
- Modify: `web/handlers/pipeline.go:579-609` (setupSSE)
- Modify: `web/handlers/pipeline.go:247` (streamPlan destructure + call)
- Modify: `web/handlers/pipeline.go:413` (streamPiece destructure + call)
- Modify: `web/handlers/pipeline.go:528` (streamImprove destructure + call)

- [ ] **Step 1: Add sendThinking to setupSSE**

In `web/handlers/pipeline.go`, modify `setupSSE` (line 579). Add `sendThinking` after `sendChunk` and before `sendDone`. Change the return signature from 5 to 6 values:

```go
func (h *PipelineHandler) setupSSE(w http.ResponseWriter) (http.Flusher, func(any), func(string) error, func(string) error, func(), func(string)) {
```

Add after `sendChunk` (line 596-598):

```go
	sendThinking := func(chunk string) error {
		sendEvent(map[string]string{"type": "thinking", "chunk": chunk})
		return nil
	}
```

Update the return statement:

```go
	return flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError
```

- [ ] **Step 2: Update streamPlan (line 247)**

Change:
```go
flusher, sendEvent, sendChunk, sendDone, sendError := h.setupSSE(w)
```
to:
```go
flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
```

And the `StreamWithTools` call (line 256) from:
```go
fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, &temp)
```
to:
```go
fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, sendThinking, &temp)
```

- [ ] **Step 3: Update streamPiece (line 413)**

Change:
```go
flusher, sendEvent, sendChunk, sendDone, sendError := h.setupSSE(w)
```
to:
```go
flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
```

And the `StreamWithTools` call (line 428) from:
```go
fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, &temp)
```
to:
```go
fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, sendThinking, &temp)
```

- [ ] **Step 4: Update streamImprove (line 528)**

Change:
```go
flusher, sendEvent, sendChunk, sendDone, sendError := h.setupSSE(w)
```
to:
```go
flusher, sendEvent, sendChunk, _, sendDone, sendError := h.setupSSE(w)
```

And the `StreamWithTools` call (line 549) — pass no-op:
```go
fullResponse, err := h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, func(string) error { return nil }, &temp)
```

- [ ] **Step 5: Verify build**

Run: `cd /Users/zanfridau/CODE/AI/marketminded && go build ./...`
Expected: no errors

- [ ] **Step 6: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: add sendThinking SSE helper, wire into plan and piece generation"
```

---

### Task 4: Frontend — Thinking Display in streamToElement

**Files:**
- Modify: `web/static/app.js:1204-1228` (streamToElement function)
- Modify: `web/static/style.css` (add thinking styles)

- [ ] **Step 1: Add thinking handling to streamToElement**

In `web/static/app.js`, replace the `streamToElement` function (lines 1204-1228) with:

```javascript
    function streamToElement(url, el, onDone) {
        el.textContent = '';
        var thinkingEl = null;
        var thinkingPre = null;
        var contentStarted = false;
        var source = new EventSource(url);
        source.onmessage = function(event) {
            var d = JSON.parse(event.data);
            if (d.type === 'thinking') {
                if (!thinkingEl) {
                    thinkingEl = document.createElement('details');
                    thinkingEl.className = 'thinking-details';
                    thinkingEl.setAttribute('open', '');
                    var summary = document.createElement('summary');
                    summary.textContent = 'Thinking...';
                    thinkingEl.appendChild(summary);
                    thinkingPre = document.createElement('pre');
                    thinkingEl.appendChild(thinkingPre);
                    el.parentNode.insertBefore(thinkingEl, el);
                }
                thinkingPre.textContent += d.chunk;
                thinkingPre.scrollTop = thinkingPre.scrollHeight;
            } else if (d.type === 'chunk') {
                if (!contentStarted && thinkingEl) {
                    thinkingEl.removeAttribute('open');
                    thinkingEl.querySelector('summary').textContent = 'Thinking (done)';
                    contentStarted = true;
                }
                el.textContent += d.chunk;
                el.scrollTop = el.scrollHeight;
            } else if (d.type === 'tool_start') {
                if (!contentStarted && thinkingEl) {
                    thinkingEl.removeAttribute('open');
                    thinkingEl.querySelector('summary').textContent = 'Thinking (done)';
                    contentStarted = true;
                }
                el.textContent += '\n[' + d.summary + '...]\n';
            } else if (d.type === 'content_written') {
                if (!contentStarted && thinkingEl) {
                    thinkingEl.removeAttribute('open');
                    thinkingEl.querySelector('summary').textContent = 'Thinking (done)';
                    contentStarted = true;
                }
                renderContentBody(el, d.platform, d.format, JSON.stringify(d.data));
            } else if (d.type === 'done') {
                source.close();
                if (onDone) onDone();
            } else if (d.type === 'error') {
                source.close();
                el.textContent += '\nError: ' + d.error;
            }
        };
        source.onerror = function() {
            source.close();
        };
        return source;
    }
```

- [ ] **Step 2: Add CSS styles**

Add to `web/static/style.css`:

```css
.thinking-details { margin-bottom: 1rem; }
.thinking-details summary { cursor: pointer; font-style: italic; color: #6b7280; }
.thinking-details pre { white-space: pre-wrap; font-size: 0.8rem; color: #9ca3af; max-height: 300px; overflow-y: auto; }
```

- [ ] **Step 3: Commit**

```bash
git add web/static/app.js web/static/style.css
git commit -m "feat: display AI thinking in collapsible section during pipeline generation"
```

---

### Task 5: Smoke Test

- [ ] **Step 1: Start the server**

Run: `make restart`

- [ ] **Step 2: Test plan generation**

Navigate to a project pipeline, start a new run, generate a plan. Confirm:
- A "Thinking..." section appears above the plan body while reasoning streams
- When content starts, thinking collapses and shows "Thinking (done)"
- Clicking the summary re-expands to show the full reasoning
- Plan content renders normally below

- [ ] **Step 3: Test piece generation**

Approve a plan and generate a cornerstone piece. Same behavior expected.

- [ ] **Step 4: Test no-regression on improve**

Open the improve flow on a piece. Confirm no thinking section appears (no-op) and improve works as before.
