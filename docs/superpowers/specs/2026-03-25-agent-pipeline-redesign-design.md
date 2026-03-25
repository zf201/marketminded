# Agent Pipeline Redesign

Separate the content pipeline into two distinct phases — cornerstone (with sequential research, fact-checking, and writing agents) and waterfall (planned and generated only after cornerstone approval). Each phase gets its own page. Waterfall pieces generate in parallel and funnel audience back to the cornerstone.

## Data Model

### New table: `pipeline_steps`

| Column | Type | Description |
|--------|------|-------------|
| id | INTEGER PK | Auto-increment |
| pipeline_run_id | FK -> pipeline_runs | Parent pipeline run |
| step_type | TEXT | `research`, `factcheck`, `write`, `plan_waterfall` |
| status | TEXT | `pending`, `running`, `completed`, `failed` |
| input | TEXT | JSON — what was fed to the agent |
| output | TEXT | The agent's produced content |
| thinking | TEXT | AI reasoning chain (for display) |
| sort_order | INT | Execution order within the pipeline |
| created_at | DATETIME | |
| updated_at | DATETIME | |

### Changes to existing tables

**`pipeline_runs`:** Add `phase` column (`cornerstone` | `waterfall`).
- `planning` status removed (no upfront planning anymore)
- `producing` — cornerstone agents running or waterfall generating
- `complete` / `abandoned` — unchanged

**`content_pieces`:** No schema changes. Cornerstone piece created when writer step completes. Waterfall pieces created after plan_waterfall step.

**`agent_runs`:** Left as-is (legacy).

### Data flow

1. User starts pipeline -> `pipeline_run` created (`phase=cornerstone`, `status=producing`)
2. Three `pipeline_steps` created: research (sort 0), factcheck (sort 1), write (sort 2) — all pending
3. Steps execute sequentially, each feeding output to the next's input
4. Writer step completion -> creates `ContentPiece` (cornerstone, sort_order=0)
5. User approves cornerstone -> phase flips to `waterfall`
6. User clicks "Create Waterfall" on waterfall page
7. `plan_waterfall` step created and executed -> creates waterfall `ContentPiece` rows
8. All waterfall pieces generate in parallel
9. All approved -> `status=complete`

## Agent Definitions

### Researcher Agent

- **Input:** Topic brief (or brainstorm conversation), client profile, current date
- **Tools:** `web_search`, `fetch_url`, `submit_research`
- **Output schema:**
  ```json
  {
    "sources": [{"url": "", "title": "", "summary": "", "date": ""}],
    "brief": "narrative synthesis of findings"
  }
  ```
- **Temperature:** 0.3
- **Model:** `model_content`

### Fact-Checker Agent

- **Input:** Researcher's output (sources + brief), client profile, current date
- **Tools:** `web_search`, `fetch_url`, `submit_factcheck`
- **Output schema:**
  ```json
  {
    "issues_found": [{"claim": "", "problem": "", "resolution": ""}],
    "enriched_brief": "corrected and enriched version of research brief",
    "sources": [{"url": "", "title": "", "summary": "", "date": ""}]
  }
  ```
- **System prompt emphasis:** Verify dates/timeliness, check claims against sources, fix inaccuracies, enrich with missing context. Current date awareness is critical.
- **Temperature:** 0.2
- **Model:** `model_content`

### Writer Agent

- **Input:** Enriched brief from fact-checker, sources, client profile, storytelling framework (if set), anti-AI rules
- **Tools:** Existing `write_*` tools (platform/format specific)
- **Output:** Content piece (same as current generation)
- **Temperature:** 0.3
- **Model:** `model_content`

### Waterfall Planner Agent

- **Input:** Approved cornerstone piece body + title, client profile
- **Tools:** `submit_waterfall_plan` (only outputs waterfall array — no cornerstone)
- **Output schema:**
  ```json
  {
    "waterfall": [{"platform": "", "format": "", "title": "", "count": 1, "notes": ""}]
  }
  ```
- **System prompt emphasis:** These are funnel pieces. Every piece should drive the audience toward the cornerstone content. Do not introduce new information — repurpose and repackage.
- **Temperature:** 0.3
- **Model:** `model_content`

### Waterfall Piece Generation

Same as current piece generation for waterfall, with stronger system prompt priming:
- Cornerstone body as primary source material
- Explicit instruction: "This content exists to funnel audience to the cornerstone piece. Stay faithful to the cornerstone's message and facts. Do not introduce new claims."

## Pipeline Execution Flow

### Cornerstone Phase

```
User provides topic/brief (or pushes from brainstorm)
    |
    v
PipelineRun created (phase=cornerstone, status=producing)
3 pipeline_steps created (research, factcheck, write — all pending)
    |
    v
Researcher runs automatically (status=running)
    -> streams to UI (SSE: chunks, thinking, tool events)
    -> on completion: saves output, status=completed
    |
    v
Fact-checker runs automatically (status=running)
    -> receives researcher output as input
    -> streams to UI
    -> on completion: saves output, status=completed
    |
    v
Writer runs automatically (status=running)
    -> receives fact-checker enriched_brief + sources as input
    -> streams to UI
    -> on completion: creates ContentPiece (cornerstone), status=completed
    |
    v
User reviews cornerstone piece
    -> Approve / Reject / Improve (same as current)
    -> If rejected: writer step re-runs with rejection reason context
    -> If improve: existing improve chat flow
    |
    v
On approval: phase flips to waterfall
```

### Waterfall Phase

```
Phase = waterfall
    |
    v
User navigates to waterfall page, clicks "Create Waterfall"
    |
    v
plan_waterfall step created and runs
    -> receives cornerstone body as input
    -> streams to UI
    -> on completion: creates ContentPiece rows for each waterfall item
    |
    v
All waterfall pieces generate in parallel
    -> each streams independently via its own SSE connection
    |
    v
User reviews waterfall pieces as they complete
    -> Approve / Reject / Improve per piece (same as current)
    |
    v
All pieces approved -> status=complete
```

### Failure handling

- If a cornerstone step fails, it stays in `failed` status. User sees error and can retry that step.
- Abort sends a running step back to `pending`.
- Rejection of cornerstone piece only re-runs the writer step. Research and fact-check output are preserved.

## UI & Navigation

### Cornerstone Page (`/projects/{id}/pipeline/{runID}`)

- Topic/brief at top
- Three step cards stacked vertically: Researcher -> Fact-Checker -> Writer
- Each card: step type label, status badge, output content (expandable), thinking (collapsible)
- Active step streams in real-time, completed steps show output
- Writer completion -> cornerstone piece card appears with Approve / Reject / Improve
- On approval -> "Create Waterfall" button appears (links to waterfall page)

### Waterfall Page (`/projects/{id}/pipeline/{runID}/waterfall`)

- Separate page linked from cornerstone page (after approval) and project home
- Cornerstone title/summary as read-only context header
- Waterfall plan step card (streams, then shows planned pieces)
- Waterfall piece cards below, generating in parallel
- Each piece: Approve / Reject / Improve / Proofread (same as current)

### Project Home

Pipeline runs list shows phase progress:
```
Run: "AI in Healthcare"  |  Cornerstone (approved)  |  Waterfall (3/5 approved)
```
Clicking navigates to cornerstone page, with clear link to waterfall page.

## Implementation Notes

- Agent functions live in the pipeline handler (same pattern as current `streamPlan`/`streamPiece`)
- No changes to the `internal/agents/` package (legacy, unused)
- New store methods for `pipeline_steps` CRUD
- New migration for `pipeline_steps` table and `phase` column on `pipeline_runs`
- SSE streaming reuses existing event types (`chunk`, `thinking`, `tool_start`, `tool_result`, `done`, `error`)
- Parallel waterfall generation: frontend opens multiple SSE connections simultaneously
