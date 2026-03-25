# Thinking/Reasoning Display for Pipeline Generation

## Summary

Display AI reasoning/thinking content in a collapsible section above the output during plan generation and cornerstone piece generation. Currently the thinking tokens are silently dropped — this surfaces them so users can follow the AI's thought process instead of watching a spinner.

## Scope

Plan generation (`streamPlan`) and cornerstone piece generation (`streamPiece`) only. Other streaming contexts (brainstorm, profile, context, improve) are not affected — they pass a no-op callback.

## Backend: Parsing Reasoning from OpenRouter

**File:** `internal/ai/tools.go`

OpenRouter streams reasoning as `choices[].delta.reasoning_details` — an array of objects with `type: "reasoning.text"` and `text` fields.

Add `ReasoningDetails` to the existing `streamDelta` struct:

```go
ReasoningDetails []struct {
    Type string `json:"type"`
    Text string `json:"text"`
} `json:"reasoning_details,omitempty"`
```

Add `onReasoning types.StreamFunc` parameter to both `StreamWithTools` and `streamOneTurn`. In `streamOneTurn`, after the existing content chunk handling block, iterate `choice.Delta.ReasoningDetails` and call `onReasoning(rd.Text)` for entries with `type == "reasoning.text"` and non-empty text.

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

`setupSSE` signature grows by one return value (`sendThinking`).

- `streamPlan` and `streamPiece`: pass `sendThinking` as `onReasoning` to `StreamWithTools`
- `streamImprove`: pass no-op `func(string) error { return nil }`

## Frontend: Collapsible Thinking Section

**Files:** `web/static/app.js`, `web/static/style.css`

For pipeline SSE handlers (plan generation and piece generation), handle the `thinking` event type:

1. On first `thinking` chunk: create a `<details open>` element with `<summary>Thinking...</summary>` above the output area, with a `<pre>` inside for the thinking text
2. On subsequent `thinking` chunks: append text to the `<pre>`
3. On first `chunk` (content) event: collapse the `<details>` (remove `open` attribute), update summary to "Thinking (done)"

CSS:
```css
.thinking-details { margin-bottom: 1rem; }
.thinking-details summary { cursor: pointer; font-style: italic; color: #6b7280; }
.thinking-details pre { white-space: pre-wrap; font-size: 0.8rem; color: #9ca3af; max-height: 300px; overflow-y: auto; }
```

## Files to Create/Modify

- `internal/ai/tools.go` — add `ReasoningDetails` to `streamDelta`, add `onReasoning` param to `StreamWithTools` and `streamOneTurn`
- `web/handlers/pipeline.go` — add `sendThinking` to `setupSSE`, pass to `StreamWithTools` in `streamPlan` and `streamPiece`
- `web/handlers/brainstorm.go` — update `StreamWithTools` call with no-op reasoning callback
- `web/handlers/profile.go` — update `StreamWithTools` call with no-op reasoning callback
- `web/handlers/context.go` — update `StreamWithTools` call with no-op reasoning callback (if it uses `StreamWithTools`)
- `web/static/app.js` — handle `thinking` SSE event in pipeline plan + piece generation
- `web/static/style.css` — add `.thinking-details` styles

Note: Any file calling `StreamWithTools` must be updated to pass the new `onReasoning` parameter (no-op where not needed). Grep for all callers to ensure none are missed.
