# Thinking/Reasoning Display for Pipeline Generation

## Summary

Display AI reasoning/thinking content in a collapsible section above the output during plan generation and cornerstone piece generation. Currently the thinking tokens are silently dropped — this surfaces them so users can follow the AI's thought process instead of watching a spinner.

## Scope

Plan generation (`streamPlan`) and cornerstone piece generation (`streamPiece`) only. Other streaming contexts (brainstorm, profile, improve) pass a no-op callback.

## Backend: Parsing Reasoning from OpenRouter

**File:** `internal/ai/tools.go`

OpenRouter streams reasoning as `choices[].delta.reasoning_details` — an array of objects with `type: "reasoning.text"` and `text` fields. Some providers may also send `choices[].delta.reasoning` as a plain string. Support both defensively.

Add to the existing `streamDelta` struct:

```go
Reasoning        string `json:"reasoning,omitempty"`
ReasoningDetails []struct {
    Type string `json:"type"`
    Text string `json:"text"`
} `json:"reasoning_details,omitempty"`
```

Add `onReasoning types.StreamFunc` parameter to both `StreamWithTools` and `streamOneTurn`. In `streamOneTurn`, after the existing content chunk handling block:

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

All existing callers that don't need reasoning pass a no-op: `func(string) error { return nil }`.

## Pipeline SSE: Sending Thinking Events

**File:** `web/handlers/pipeline.go`

Add `sendThinking` to `setupSSE` return values:

```go
sendThinking := func(chunk string) error {
    sendEvent(map[string]string{"type": "thinking", "chunk": chunk})
    return nil
}
```

`setupSSE` signature grows from 5 to 6 return values. **All 3 destructure sites must be updated** (`streamPlan`, `streamPiece`, `streamImprove`).

- `streamPlan` and `streamPiece`: pass `sendThinking` as `onReasoning` to `StreamWithTools`
- `streamImprove`: pass no-op `func(string) error { return nil }`

## Multi-turn Reasoning Behavior

`StreamWithTools` loops up to 10 turns (tool call → result → continue). The `onReasoning` callback fires on every turn. On the frontend, if thinking was already collapsed and a new turn produces more reasoning, simply re-open the `<details>` and continue appending. This is fine — multi-turn reasoning is useful context.

## Frontend: Collapsible Thinking Section

**Files:** `web/static/app.js`, `web/static/style.css`

Modify `streamToElement` (the shared helper used by both plan and piece generation, at ~line 1204). This function takes `(url, el, onDone)` and creates an EventSource. Add thinking support:

1. Before opening the EventSource, create a `thinkingEl` variable (initially null)
2. On `thinking` event: if `thinkingEl` is null, create `<details open class="thinking-details"><summary>Thinking...</summary><pre></pre></details>` and insert it before `el` (`el.parentNode.insertBefore(thinkingEl, el)`). Append `d.chunk` to the `<pre>` inside it.
3. On first `chunk` (content) event: if `thinkingEl` exists, remove `open` attribute and update summary text to "Thinking (done)"

CSS in `web/static/style.css`:
```css
.thinking-details { margin-bottom: 1rem; }
.thinking-details summary { cursor: pointer; font-style: italic; color: #6b7280; }
.thinking-details pre { white-space: pre-wrap; font-size: 0.8rem; color: #9ca3af; max-height: 300px; overflow-y: auto; }
```

## Files to Modify

- `internal/ai/tools.go` — add `Reasoning` + `ReasoningDetails` to `streamDelta`, add `onReasoning` param to `StreamWithTools` and `streamOneTurn`
- `internal/ai/tools_test.go` — update test calls to pass no-op `onReasoning` (9th argument)
- `web/handlers/pipeline.go` — add `sendThinking` to `setupSSE` (6 return values), update all 3 destructure sites, pass `sendThinking` or no-op to `StreamWithTools`
- `web/handlers/brainstorm.go` — update `StreamWithTools` call with no-op reasoning callback
- `web/handlers/profile.go` — update `StreamWithTools` call with no-op reasoning callback
- `web/static/app.js` — add thinking handling to `streamToElement`
- `web/static/style.css` — add `.thinking-details` styles
