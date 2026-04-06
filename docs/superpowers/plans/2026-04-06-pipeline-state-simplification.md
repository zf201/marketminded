# Pipeline State Simplification Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove `running` as a persisted database state so pipeline steps are always in a recoverable state (`pending`, `completed`, or `failed`).

**Architecture:** `running` becomes a client-side-only visual state. The database CHECK constraints are tightened to only allow `pending`, `completed`, `failed`. The `TrySetStepRunning` guard is removed. A `beforeunload` browser warning prevents accidental navigation during streaming. On failure, output is cleared (except AI error messages). The topic generator's `running` states get the same treatment.

**Tech Stack:** Go, SQLite (goose migrations), templ, vanilla JS

---

### Task 1: Database Migration — Remove `running` from CHECK Constraints

**Files:**
- Create: `migrations/017_remove_running_status.sql`

SQLite doesn't support `ALTER TABLE ... ALTER COLUMN`, so we need to recreate the tables. The migration must:
1. Fix any rows currently stuck as `running` → `failed`
2. Recreate `pipeline_steps` without `running` in the CHECK
3. Recreate `topic_steps` without `running` in the CHECK
4. Recreate `topic_runs` without `running` in the CHECK, change default from `running` to `pending`

- [ ] **Step 1: Write the migration file**

```sql
-- +goose Up

-- Fix stuck rows before altering
UPDATE pipeline_steps SET status = 'failed' WHERE status = 'running';
UPDATE topic_steps SET status = 'failed' WHERE status = 'running';
UPDATE topic_runs SET status = 'failed' WHERE status = 'running';

-- Recreate pipeline_steps without 'running'
CREATE TABLE pipeline_steps_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','completed','failed')),
    input TEXT NOT NULL DEFAULT '',
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    tool_calls TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO pipeline_steps_new SELECT * FROM pipeline_steps;
DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;

-- Recreate topic_steps without 'running'
CREATE TABLE topic_steps_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_run_id INTEGER NOT NULL REFERENCES topic_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL CHECK(step_type IN ('topic_explore','topic_review')),
    round INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','completed','failed')),
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    tool_calls TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO topic_steps_new SELECT * FROM topic_steps;
DROP TABLE topic_steps;
ALTER TABLE topic_steps_new RENAME TO topic_steps;

-- Recreate topic_runs: remove 'running', default to 'pending'
CREATE TABLE topic_runs_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    instructions TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','completed','failed')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO topic_runs_new SELECT * FROM topic_runs;
DROP TABLE topic_runs;
ALTER TABLE topic_runs_new RENAME TO topic_runs;

-- +goose Down

-- Recreate with 'running' allowed
CREATE TABLE pipeline_steps_old (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','running','completed','failed')),
    input TEXT NOT NULL DEFAULT '',
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    tool_calls TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO pipeline_steps_old SELECT * FROM pipeline_steps;
DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_old RENAME TO pipeline_steps;

CREATE TABLE topic_steps_old (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_run_id INTEGER NOT NULL REFERENCES topic_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL CHECK(step_type IN ('topic_explore','topic_review')),
    round INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','running','completed','failed')),
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    tool_calls TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO topic_steps_old SELECT * FROM topic_steps;
DROP TABLE topic_steps;
ALTER TABLE topic_steps_old RENAME TO topic_steps;

CREATE TABLE topic_runs_old (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    instructions TEXT NOT NULL DEFAULT '',
    status TEXT NOT NULL DEFAULT 'running'
        CHECK(status IN ('running','completed','failed')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
INSERT INTO topic_runs_old SELECT * FROM topic_runs;
DROP TABLE topic_runs;
ALTER TABLE topic_runs_old RENAME TO topic_runs;
```

- [ ] **Step 2: Run the migration**

Run: `make restart`

Expected: Server starts cleanly, migration 017 applied. Any rows previously stuck as `running` are now `failed`.

- [ ] **Step 3: Commit**

```bash
git add migrations/017_remove_running_status.sql
git commit -m "migration: remove running from step/run status CHECK constraints"
```

---

### Task 2: Store Layer — Remove `TrySetStepRunning`

**Files:**
- Modify: `internal/store/steps.go:81-91` — delete `TrySetStepRunning`
- Modify: `internal/store/interfaces.go:16` — remove from `PipelineStore` interface

- [ ] **Step 1: Remove `TrySetStepRunning` from the interface**

In `internal/store/interfaces.go`, delete line 16:

```go
TrySetStepRunning(id int64) (bool, error)
```

The `PipelineStore` interface should go from:

```go
type PipelineStore interface {
	// ...
	TrySetStepRunning(id int64) (bool, error)
	UpdatePipelineStepStatus(id int64, status string) error
	// ...
}
```

To:

```go
type PipelineStore interface {
	// ...
	UpdatePipelineStepStatus(id int64, status string) error
	// ...
}
```

- [ ] **Step 2: Remove `TrySetStepRunning` implementation**

In `internal/store/steps.go`, delete the entire function (lines 81-91):

```go
// TrySetStepRunning atomically sets status to running if not already completed.
func (q *Queries) TrySetStepRunning(id int64) (bool, error) {
	res, err := q.db.Exec(
		"UPDATE pipeline_steps SET status = 'running', updated_at = CURRENT_TIMESTAMP WHERE id = ? AND status IN ('pending', 'failed', 'running')", id,
	)
	if err != nil {
		return false, err
	}
	n, _ := res.RowsAffected()
	return n > 0, nil
}
```

- [ ] **Step 3: Verify it compiles**

Run: `go build ./...`

Expected: Compilation error in `internal/pipeline/orchestrator.go` because it still calls `TrySetStepRunning`. This is expected — we fix it in Task 3.

- [ ] **Step 4: Commit**

```bash
git add internal/store/steps.go internal/store/interfaces.go
git commit -m "store: remove TrySetStepRunning — running is no longer a DB state"
```

---

### Task 3: Orchestrator — Remove Running Guard, Simplify Failure Path

**Files:**
- Modify: `internal/pipeline/orchestrator.go:78-99`

The orchestrator currently:
1. Calls `TrySetStepRunning` (line 78-81) — remove this
2. On failure: saves partial output, then sets `failed` (lines 85-91) — change to clear output
3. On success: saves output, sets `completed` (lines 94-98) — keep as-is

- [ ] **Step 1: Rewrite `RunStep` error handling**

Replace lines 78-99 of `internal/pipeline/orchestrator.go` (from `ok, err = o.store.TrySetStepRunning(stepID)` to the end of the function) with:

```go
	result, runErr := runner.Run(ctx, input, stream)

	if runErr != nil {
		// On failure: clear output unless the AI returned a meaningful error
		errOutput := ""
		if result.Output != "" && ctx.Err() == nil {
			errOutput = result.Output
		}
		o.store.UpdatePipelineStepOutput(stepID, errOutput, "")
		o.store.UpdatePipelineStepToolCalls(stepID, "")
		o.store.UpdatePipelineStepStatus(stepID, "failed")
		return runErr
	}

	o.store.UpdatePipelineStepOutput(stepID, result.Output, result.Thinking)
	if result.ToolCalls != "" {
		o.store.UpdatePipelineStepToolCalls(stepID, result.ToolCalls)
	}
	o.store.UpdatePipelineStepStatus(stepID, "completed")
	return nil
```

Key changes:
- `TrySetStepRunning` call removed entirely
- On failure: output is cleared to `""` unless the AI returned output AND the context was NOT canceled (meaning the AI itself errored, not infra)
- Thinking is always cleared on failure (`""`)
- Tool calls are always cleared on failure (`""`)

- [ ] **Step 2: Verify it compiles**

Run: `go build ./...`

Expected: Clean compilation.

- [ ] **Step 3: Commit**

```bash
git add internal/pipeline/orchestrator.go
git commit -m "orchestrator: remove TrySetStepRunning, clear output on failure"
```

---

### Task 4: Brand Enricher — Remove Premature `SendDone()`

**Files:**
- Modify: `internal/pipeline/steps/brand_enricher.go:29-31`

The fast-path (no URLs to fetch) currently calls `stream.SendDone()` before returning. This is wrong — the handler in `pipeline.go:streamStep` already calls `SendDone()` on success. Remove it.

- [ ] **Step 1: Remove the `SendDone()` call**

In `internal/pipeline/steps/brand_enricher.go`, change lines 29-31 from:

```go
	if urlList == "" {
		stream.SendDone()
		return pipeline.StepResult{Output: researchOutput}, nil
	}
```

To:

```go
	if urlList == "" {
		return pipeline.StepResult{Output: researchOutput}, nil
	}
```

- [ ] **Step 2: Verify it compiles**

Run: `go build ./...`

Expected: Clean compilation.

- [ ] **Step 3: Commit**

```bash
git add internal/pipeline/steps/brand_enricher.go
git commit -m "brand_enricher: remove premature SendDone on fast-path"
```

---

### Task 5: Server Templates — Remove `running` from Rendering Functions

**Files:**
- Modify: `web/templates/pipeline.templ:116-141` — remove `running` cases from `stepProgressClass` and `stepIcon`
- Modify: `web/templates/components/badge.templ:7-8` — remove `running` case from `badgeClass`

The server will never render `running` from the DB anymore. These cases are dead code.

- [ ] **Step 1: Remove `running` from `stepProgressClass`**

In `web/templates/pipeline.templ`, change `stepProgressClass` from:

```go
func stepProgressClass(status string) string {
	base := "flex flex-col items-center gap-1 text-xs"
	switch status {
	case "completed":
		return base + " text-green-400"
	case "running":
		return base + " text-yellow-400"
	case "failed":
		return base + " text-red-400"
	default:
		return base + " text-zinc-600"
	}
}
```

To:

```go
func stepProgressClass(status string) string {
	base := "flex flex-col items-center gap-1 text-xs"
	switch status {
	case "completed":
		return base + " text-green-400"
	case "failed":
		return base + " text-red-400"
	default:
		return base + " text-zinc-600"
	}
}
```

- [ ] **Step 2: Remove `running` from `stepIcon`**

In `web/templates/pipeline.templ`, change `stepIcon` from:

```go
func stepIcon(status string) string {
	switch status {
	case "completed":
		return "✓"
	case "running":
		return "●"
	case "failed":
		return "✗"
	default:
		return ""
	}
}
```

To:

```go
func stepIcon(status string) string {
	switch status {
	case "completed":
		return "✓"
	case "failed":
		return "✗"
	default:
		return ""
	}
}
```

- [ ] **Step 3: Remove `running` from `badgeClass`**

In `web/templates/components/badge.templ`, remove the `running` case (lines 7-8):

```go
	case "running":
		return "badge badge-warning animate-pulse"
```

The `running` badge is still rendered client-side by `step-cards.js` via CSS classes (`badge-running`), so this server-side case is unused.

- [ ] **Step 4: Regenerate templ and verify compilation**

Run: `templ generate && go build ./...`

Expected: Clean generation and compilation.

- [ ] **Step 5: Commit**

```bash
git add web/templates/pipeline.templ web/templates/pipeline_templ.go web/templates/components/badge.templ web/templates/components/badge_templ.go
git commit -m "templates: remove running status from server-side rendering"
```

---

### Task 6: Topic Handler — Remove `running` Status Usage

**Files:**
- Modify: `web/handlers/topic.go:158,199,256,350`
- Modify: `web/templates/topic.templ:180`

The topic handler uses `running` in four places:
1. Line 158: Guards streaming with `run.Status != "running"` — change to check `"pending"`
2. Lines 199, 256: Sets step status to `running` during execution — remove these calls
3. Line 350: Sets run status to `running` on retry — change to `"pending"`

The topic template checks `data.Status == "running"` on line 180 to show the Run button — change to `"pending"`.

- [ ] **Step 1: Fix the streaming guard**

In `web/handlers/topic.go`, change line 158 from:

```go
	if err != nil || run.Status != "running" {
```

To:

```go
	if err != nil || run.Status != "pending" {
```

- [ ] **Step 2: Remove the `UpdateTopicStepStatus(... "running")` calls**

In `web/handlers/topic.go`, delete line 199:

```go
		h.queries.UpdateTopicStepStatus(exploreStep.ID, "running")
```

And delete line 256 (which will have shifted after the previous deletion):

```go
		h.queries.UpdateTopicStepStatus(reviewStep.ID, "running")
```

- [ ] **Step 3: Fix the retry handler**

In `web/handlers/topic.go`, change line 350 from:

```go
	h.queries.UpdateTopicRunStatus(runID, "running")
```

To:

```go
	h.queries.UpdateTopicRunStatus(runID, "pending")
```

- [ ] **Step 4: Fix the topic template**

In `web/templates/topic.templ`, change line 180 from:

```go
			if data.Status == "running" && len(data.Steps) == 0 {
```

To:

```go
			if data.Status == "pending" && len(data.Steps) == 0 {
```

- [ ] **Step 5: Fix the topic template JS auto-start check**

In `web/templates/topic.templ`, change line 266 from:

```go
				if (status === 'running') {
```

To:

```go
				if (status === 'pending') {
```

- [ ] **Step 6: Regenerate templ and verify compilation**

Run: `templ generate && go build ./...`

Expected: Clean generation and compilation.

- [ ] **Step 7: Commit**

```bash
git add web/handlers/topic.go web/templates/topic.templ web/templates/topic_templ.go
git commit -m "topics: replace running status with pending, remove in-flight status writes"
```

---

### Task 7: JS — Add `beforeunload` Guard During Streaming

**Files:**
- Modify: `web/static/js/alpine-components/pipeline.js:253-259` — add beforeunload in the run handler
- Modify: `web/templates/topic.templ:208-262` — add beforeunload in the topic stream handler

- [ ] **Step 1: Add `beforeunload` guard to pipeline.js**

In `web/static/js/alpine-components/pipeline.js`, in the cornerstone pipeline init section, add a `beforeunload` handler. Change the `runBtn` click handler (lines 253-259) from:

```js
                    if (runBtn) {
                        runBtn.addEventListener('click', function() {
                            runBtn.disabled = true;
                            runBtn.textContent = 'Running...';
                            if (cancelBtn) cancelBtn.classList.remove('hidden');
                            var cards = Array.prototype.slice.call(document.querySelectorAll('.step-card[data-step-id]'));
                            self.runNextStep(cards, 0);
                        });
                    }
```

To:

```js
                    var beforeUnloadHandler = function(e) {
                        e.preventDefault();
                    };

                    if (runBtn) {
                        runBtn.addEventListener('click', function() {
                            runBtn.disabled = true;
                            runBtn.textContent = 'Running...';
                            if (cancelBtn) cancelBtn.classList.remove('hidden');
                            window.addEventListener('beforeunload', beforeUnloadHandler);
                            var cards = Array.prototype.slice.call(document.querySelectorAll('.step-card[data-step-id]'));
                            self.runNextStep(cards, 0);
                        });
                    }
```

Then update `runNextStep` to remove the guard when all steps finish. Change the completion check (lines 24-27) from:

```js
                if (index >= cards.length) {
                    window.location.reload();
                    return;
                }
```

To:

```js
                if (index >= cards.length) {
                    window.removeEventListener('beforeunload', beforeUnloadHandler);
                    window.location.reload();
                    return;
                }
```

Also remove the guard in the error callback. Change the `onError` callback in `runNextStep` (lines 49-57) from:

```js
                }, function() {
                    self.activeSource = null;
                    var cancelBtn = document.getElementById('cancel-pipeline-btn');
                    if (cancelBtn) cancelBtn.classList.add('hidden');
                    var runBtn = document.getElementById('run-pipeline-btn');
                    if (runBtn) {
                        runBtn.disabled = false;
                        runBtn.textContent = 'Retry';
                        runBtn.classList.remove('hidden');
                    }
                });
```

To:

```js
                }, function() {
                    self.activeSource = null;
                    window.removeEventListener('beforeunload', beforeUnloadHandler);
                    var cancelBtn = document.getElementById('cancel-pipeline-btn');
                    if (cancelBtn) cancelBtn.classList.add('hidden');
                    var runBtn = document.getElementById('run-pipeline-btn');
                    if (runBtn) {
                        runBtn.disabled = false;
                        runBtn.textContent = 'Retry';
                        runBtn.classList.remove('hidden');
                    }
                });
```

And remove the guard in the cancel handler. Change lines 263-270 from:

```js
                    if (cancelBtn) {
                        cancelBtn.addEventListener('click', function() {
                            if (self.activeSource) {
                                self.activeSource.close();
                                self.activeSource = null;
                            }
                            cancelBtn.classList.add('hidden');
                            // Reload to get clean state from server
                            window.location.reload();
                        });
                    }
```

To:

```js
                    if (cancelBtn) {
                        cancelBtn.addEventListener('click', function() {
                            if (self.activeSource) {
                                self.activeSource.close();
                                self.activeSource = null;
                            }
                            window.removeEventListener('beforeunload', beforeUnloadHandler);
                            cancelBtn.classList.add('hidden');
                            window.location.reload();
                        });
                    }
```

- [ ] **Step 2: Add `beforeunload` guard to topic streaming**

In `web/templates/topic.templ`, in the `<script>` block, add a `beforeunload` handler around the topic `startStream` function. Change the `startStream` function (lines 208-263) from:

```js
				function startStream() {
					if (runBtn) {
						runBtn.disabled = true;
						runBtn.textContent = 'Running...';
					}

					var currentCard = null;
					var currentRefs = null;
					var source = new EventSource('/projects/' + projectID + '/topics/' + runID + '/stream');
```

To:

```js
				var beforeUnloadHandler = function(e) {
					e.preventDefault();
				};

				function startStream() {
					if (runBtn) {
						runBtn.disabled = true;
						runBtn.textContent = 'Running...';
					}
					window.addEventListener('beforeunload', beforeUnloadHandler);

					var currentCard = null;
					var currentRefs = null;
					var source = new EventSource('/projects/' + projectID + '/topics/' + runID + '/stream');
```

Then remove the guard when the stream ends. Change the `done` handler (line 249-251) from:

```js
						} else if (d.type === 'done') {
							source.close();
							window.location.reload();
```

To:

```js
						} else if (d.type === 'done') {
							source.close();
							window.removeEventListener('beforeunload', beforeUnloadHandler);
							window.location.reload();
```

And the `error` handler (lines 252-258) — add removeEventListener after `source.close()`:

```js
						} else if (d.type === 'error') {
							source.close();
							window.removeEventListener('beforeunload', beforeUnloadHandler);
```

And the `onerror` handler (line 262):

```js
					source.onerror = function() {
						source.close();
						window.removeEventListener('beforeunload', beforeUnloadHandler);
					};
```

- [ ] **Step 3: Verify the JS changes load correctly**

Run: `make restart`

Expected: Server starts. Navigate to a pipeline page — no JS console errors.

- [ ] **Step 4: Commit**

```bash
git add web/static/js/alpine-components/pipeline.js web/templates/topic.templ web/templates/topic_templ.go
git commit -m "js: add beforeunload guard during pipeline and topic streaming"
```

---

### Task 8: Verify End-to-End

- [ ] **Step 1: Run the full build**

Run: `templ generate && go build ./...`

Expected: Clean compilation, no errors.

- [ ] **Step 2: Start the server and verify**

Run: `make restart`

Expected: Server starts, migrations applied. Verify:
1. Existing completed pipeline runs still display correctly
2. Any previously stuck `running` steps now show as `failed`
3. The Retry button appears for failed steps
4. The Run Pipeline button appears for pending steps

- [ ] **Step 3: Test a pipeline run**

Start a new pipeline run. Verify:
1. Steps stream correctly with the `running` badge shown client-side
2. On completion, steps show `completed`
3. If you cancel mid-stream, the page reloads showing a valid state (step as `pending` or `failed`, never `running`)
4. The `beforeunload` warning fires if you try to close the tab during streaming

- [ ] **Step 4: Final commit if any fixes needed**

If any fixes were needed during verification, commit them.
