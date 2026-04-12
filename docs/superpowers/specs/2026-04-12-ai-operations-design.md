# AI Operations — Design Spec

## Context

The current AI pipeline has no observability — the user clicks "Generate," sees a spinner, and has no way to know what's happening, what it cost, or why it failed. OpenRouter dashboard shows 40 requests but the app shows nothing. This feature adds a comprehensive AI task tracking system that becomes the foundation for all AI features.

## What This Builds

1. **Data layer** — `ai_tasks` and `ai_task_steps` tables tracking every AI job and its per-agent breakdown (tokens, cost, duration, iterations)
2. **Header indicator** — a sparkles icon in the app header showing running task count, pulsing when active
3. **Toast notifications** — success/failure toasts when tasks complete, even from other pages
4. **Operations page** — full task history with expandable per-agent detail, cancel/retry actions
5. **AI button pattern** — sparkles icon on all AI-triggering buttons across the app
6. **Rewire Brand Intelligence** — remove `intelligence_status`/`intelligence_error` from teams table, use `ai_tasks` instead

## Data Model

### `ai_tasks` table

One row per dispatched AI job.

| Column | Type | Default | Purpose |
|---|---|---|---|
| `id` | bigIncrements | | PK |
| `team_id` | foreignId | | FK to teams, cascades on delete |
| `type` | string(50) | | Job type identifier, e.g., `brand_intelligence` |
| `label` | string | | Human-readable name, e.g., "Generate Brand Intelligence" |
| `status` | string(20) | `pending` | `pending` / `running` / `completed` / `failed` / `cancelled` |
| `current_step` | string(50) | null | Currently executing step name |
| `total_steps` | integer | 0 | Total number of steps |
| `completed_steps` | integer | 0 | Steps finished so far |
| `error` | text | null | Error message if failed |
| `started_at` | timestamp | null | When processing began |
| `completed_at` | timestamp | null | When finished (success or failure) |
| `cancelled_at` | timestamp | null | When user cancelled |
| `total_tokens` | integer | 0 | Sum of all step tokens |
| `total_cost` | decimal(10,6) | 0 | Sum cost in USD |
| `timestamps` | | | created_at, updated_at |

### `ai_task_steps` table

One row per agent/step within a task.

| Column | Type | Default | Purpose |
|---|---|---|---|
| `id` | bigIncrements | | PK |
| `ai_task_id` | foreignId | | FK to ai_tasks, cascades on delete |
| `name` | string(50) | | Step identifier, e.g., `positioning` |
| `label` | string | | Human-readable, e.g., "Analyzing positioning" |
| `status` | string(20) | `pending` | `pending` / `running` / `completed` / `failed` / `skipped` |
| `model` | string | null | Model used, e.g., `deepseek/deepseek-v3.2:nitro` |
| `input_tokens` | integer | 0 | Prompt tokens |
| `output_tokens` | integer | 0 | Completion tokens |
| `cost` | decimal(10,6) | 0 | Step cost in USD |
| `iterations` | integer | 0 | Tool loop iterations |
| `error` | text | null | Error message if step failed |
| `started_at` | timestamp | null | |
| `completed_at` | timestamp | null | |
| `timestamps` | | | |

## Models

### `App\Models\AiTask`

- Fillable: all columns except id/timestamps
- Belongs to Team
- Has many AiTaskSteps
- Scopes: `running()`, `recent()`, `forTeam($teamId)`

### `App\Models\AiTaskStep`

- Fillable: all columns except id/timestamps
- Belongs to AiTask

### Team model additions

```php
public function aiTasks(): HasMany
```

## OpenRouterClient Changes

The `chat()` method currently returns either a string (content) or array (tool call arguments). It needs to also return usage stats.

New return type — a result object or array containing:
- `data` — the parsed tool arguments or content string (same as before)
- `usage` — `['input_tokens' => int, 'output_tokens' => int, 'total_tokens' => int, 'cost' => float]`
- `iterations` — number of tool loop iterations taken

OpenRouter includes usage in every response:
```json
{
  "usage": {
    "prompt_tokens": 1200,
    "completion_tokens": 350,
    "total_tokens": 1550
  }
}
```

Cost can be calculated from the model's per-token pricing, or read from OpenRouter's response if available.

## Agent Changes

Each agent's `generate()` method receives an `AiTaskStep` model and updates it:
- Sets `status = 'running'`, `started_at`, `model`
- After completion: sets `status = 'completed'`, `completed_at`, token counts, cost, iterations
- On failure: sets `status = 'failed'`, error message

## Job Changes

`GenerateBrandIntelligenceJob` is updated to:
- Accept an `AiTask` model (instead of just Team)
- Create `AiTaskStep` records for each agent step at the start
- Pass each step model to the corresponding agent
- Check `$aiTask->fresh()->status === 'cancelled'` before each agent step
- If cancelled: mark remaining steps as `skipped`, stop processing
- On completion: update `AiTask` with totals (sum tokens/cost from steps)
- On failure: update `AiTask` with error

## Removing intelligence_status from Teams

Migration to drop `intelligence_status` and `intelligence_error` columns from teams table. All status tracking moves to `ai_tasks`.

The Brand Intelligence Livewire component queries `ai_tasks` instead:
```php
$latestTask = $team->aiTasks()->where('type', 'brand_intelligence')->latest()->first();
```

## Header Indicator

A Livewire component: `<livewire:ai-task-indicator />`

Placed in `resources/views/layouts/app/sidebar.blade.php` (in the header area).

**Behavior:**
- Queries `ai_tasks` for the current team where status is `running` or `pending`
- No running tasks: dim sparkle icon, no badge
- Running tasks: pulsing sparkle icon with count badge
- Polls every 5s when tasks are active (uses `wire:poll.5s` conditionally)
- Clicking opens a dropdown showing:
  - Running tasks with step progress
  - Last 3 completed/failed tasks
  - "View all" link to operations page

**Toast notifications:**
- On each poll, check if any tasks transitioned to `completed` or `failed` since last check
- If so, fire a `Flux::toast()` with the result

## Operations Page

**Route:** `/{current_team}/ai-operations`

**Sidebar:** Add "AI Operations" link with `chart-bar` icon, in the Platform group after Brand Intelligence.

**Layout:** Standard Flux page layout (not two-column settings — this is a data page).

**Sections:**

### Summary Cards (top)
Three cards showing 30-day totals:
- Total Cost
- Tasks Run
- Total Tokens

### Task List
Each task shows:
- Status badge (Running/Completed/Failed/Cancelled)
- Label
- Summary line (duration, agents, tokens, cost)
- Timestamp (relative)
- Actions: Cancel (if running), Retry (if failed)

Expandable detail: click a task to show the per-step table with columns:
- Agent name
- Model
- Input/Output tokens
- Cost
- Iterations
- Duration
- Status

## AI Button Pattern

All buttons that dispatch AI jobs:
- Use the `sparkles` Heroicon
- Visually consistent across the app

When clicked:
1. Create an `AiTask` record with status `pending`
2. Create `AiTaskStep` records for each planned step
3. Dispatch the job with the `AiTask` id
4. Show a toast: "AI task started"

## Cancellation Flow

1. User clicks Cancel on the operations page or header dropdown
2. Livewire method sets `ai_tasks.status = 'cancelled'`, `cancelled_at = now()`
3. The job checks `$aiTask->fresh()->status === 'cancelled'` before each agent step
4. If cancelled: marks remaining steps as `skipped`, stops processing
5. Any data from completed steps is kept (e.g., positioning saved even if personas were skipped)
6. In-flight API call to OpenRouter cannot be interrupted — that call finishes, result is discarded

## File Structure

### New Files

```
database/migrations/XXXX_create_ai_tasks_table.php
database/migrations/XXXX_create_ai_task_steps_table.php
database/migrations/XXXX_drop_intelligence_status_from_teams_table.php
app/Models/AiTask.php
app/Models/AiTaskStep.php
resources/views/livewire/ai-task-indicator.blade.php      — Header indicator component
resources/views/pages/teams/⚡ai-operations.blade.php     — Operations page
tests/Feature/AiOperations/AiTaskTest.php
tests/Feature/AiOperations/AiOperationsPageTest.php
```

### Modified Files

```
app/Models/Team.php                                       — Add aiTasks relationship, remove intelligence fields
app/Services/OpenRouterClient.php                         — Return usage stats
app/Services/Agents/PositioningAgent.php                  — Accept and update AiTaskStep
app/Services/Agents/PersonaAgent.php                      — Accept and update AiTaskStep
app/Services/Agents/VoiceProfileAgent.php                 — Accept and update AiTaskStep
app/Jobs/GenerateBrandIntelligenceJob.php                 — Use AiTask, create steps, check cancellation
resources/views/layouts/app/sidebar.blade.php             — Add indicator + operations nav item
resources/views/pages/teams/⚡brand-intelligence.blade.php — Query ai_tasks instead of team columns
routes/web.php                                            — Add ai-operations route
```

## Authorization

- All team members can view the operations page and see task history
- Only Owner/Admin can trigger AI tasks, cancel tasks, or retry

## What's NOT in Scope

- Real-time streaming (still polling)
- Per-API-call logging (we log per-agent-step)
- Cost budgets or spending limits
- Webhook/email notifications
- Task queuing priority
