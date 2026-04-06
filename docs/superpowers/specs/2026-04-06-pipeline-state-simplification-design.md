# Pipeline State Simplification

## Problem

`running` as a persisted DB state creates unrecoverable states. When execution is interrupted (server restart, connection drop, cancel race condition), steps get stuck as `running` — the UI hides action buttons, retries break, and the user is stranded.

## Core Principle

**`running` is a client-side-only state.** The database only stores `pending`, `failed`, or `completed`. If you reload the page, every step is in a state you can act on.

## Changes

### 1. Database: Remove `running` from CHECK constraints

New migration removes `running` from allowed values for:

- `pipeline_steps.status` — `('pending','completed','failed')`
- `topic_steps.status` — `('pending','completed','failed')`
- `topic_runs.status` — `('pending','completed','failed')`, default changed from `'running'` to `'pending'`

Also reset any existing rows stuck at `running` to `failed`.

### 2. Store layer: Remove `TrySetStepRunning`

Delete `TrySetStepRunning()` entirely from `store/steps.go` and `store/interfaces.go`. No double-execution guard needed — single user app, `beforeunload` prevents navigation, button is disabled during streaming.

### 3. Orchestrator: Simplify error handling

`RunStep` changes:

- Remove the `TrySetStepRunning` call
- On success: save output/thinking/tool_calls, set `completed`
- On failure: clear output/thinking to empty strings, save error message in output **only if** the AI returned a meaningful error (not context cancellation or infra errors), set `failed`

### 4. Server templates: Remove `running` cases

- `stepProgressClass()` and `stepIcon()` in `pipeline.templ` — remove `running` case (server never renders it)
- `hasPendingSteps()` stays the same (already checks `pending` and `failed`, which is now exhaustive for actionable states)

### 5. JS: `beforeunload` guard

In `pipeline.js`, when streaming starts:

- Set a `beforeunload` listener that warns the user
- Remove it when streaming ends (done, error, or cancel)

The cancel button handler stays the same (close EventSource, reload). The race condition no longer matters because the DB never stores `running` — the step was `pending` or `failed` before streaming started, so on reload the page always shows a valid actionable state.

### 6. JS step-cards: `running` stays as client-only visual state

`step-cards.js` `stream()` still sets `card.dataset.status = 'running'` and renders the running badge — this is purely DOM state, never persisted. `BADGE_CLASSES` keeps the `running` entry for visual rendering during streaming.

### 7. Brand enricher: Remove premature `SendDone()`

`brand_enricher.go` fast-path currently calls `stream.SendDone()` before returning to the orchestrator. Remove it — the handler in `pipeline.go:streamStep` already calls `SendDone()` on success. This eliminates the double-done protocol inconsistency.

## What doesn't change

- The SSE streaming protocol (chunk/thinking/tool_start/done/error)
- The dependency resolution logic in the orchestrator
- The `runNextStep` chaining logic in pipeline.js
- The step card rendering for completed/failed/pending states

## Files to modify

| File | Change |
|------|--------|
| `migrations/017_remove_running_status.sql` | New migration: alter CHECK constraints, reset stuck rows |
| `internal/store/steps.go` | Remove `TrySetStepRunning` |
| `internal/store/interfaces.go` | Remove `TrySetStepRunning` from interface |
| `internal/pipeline/orchestrator.go` | Remove `TrySetStepRunning` call, simplify failure path |
| `internal/pipeline/steps/brand_enricher.go` | Remove premature `SendDone()` |
| `web/templates/pipeline.templ` | Remove `running` cases from `stepProgressClass` and `stepIcon` |
| `web/static/js/alpine-components/pipeline.js` | Add `beforeunload` guard during streaming |
