# Topic Generator — Design Spec

## Overview

A standalone topic generation feature that uses two AI agents in a ping-pong loop to discover and validate blog topics for a project. The explorer agent searches the web, fetches the project's blog/homepage, and proposes 3-5 topic candidates. The reviewer agent evaluates each topic for coherence and brand fit. The loop runs up to 3 rounds until at least 2 approved topics emerge. Approved topics are saved to a backlog where the user can start pipeline runs from them.

## Data Model

### New Tables

```sql
-- Topic generation runs (mirrors pipeline_runs)
CREATE TABLE topic_runs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    status TEXT NOT NULL DEFAULT 'running' CHECK(status IN ('running','completed','failed')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Steps within a topic run (mirrors pipeline_steps)
CREATE TABLE topic_steps (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    topic_run_id INTEGER NOT NULL REFERENCES topic_runs(id),
    step_type TEXT NOT NULL CHECK(step_type IN ('topic_explore','topic_review')),
    round INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','running','completed','failed')),
    output TEXT,
    thinking TEXT,
    tool_calls TEXT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- Saved topic cards (the backlog)
CREATE TABLE topic_backlog (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    topic_run_id INTEGER REFERENCES topic_runs(id),
    title TEXT NOT NULL,
    angle TEXT NOT NULL,
    sources TEXT,
    status TEXT NOT NULL DEFAULT 'available' CHECK(status IN ('available','used','deleted')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### Project Settings (key-value, no migration needed)

- `blog_url` — project's blog URL, explorer fetches to discover recent posts
- `homepage_url` — project's homepage URL, explorer fetches for brand context

## Agent Design

### Explorer Agent (`topic_explore`)

**Input:**
- Project profile (including memory/context, audience personas, voice/tone)
- Blog URL content (fetched at start if configured)
- Homepage URL content (fetched at start if configured)
- On rounds 2-3: rejected topics with reviewer reasoning, plus any already-approved topics to avoid re-proposing

**Behavior:**
- Searches the web for trending topics in the project's niche
- Fetches the blog URL to see recently published posts (avoid duplicates)
- Considers audience pain points, content gaps, and opportunities
- Proposes 3-5 topic candidates with title, angle, and supporting evidence

**Tools:**
- `web_search` — Brave search (existing)
- `fetch_url` — URL fetcher (existing)
- `submit_topics` — submit tool, schema:

```json
{
  "topics": [
    {
      "title": "string",
      "angle": "string — why this fits the brand, 1-2 sentences",
      "evidence": "string — what research supports this"
    }
  ]
}
```

**Temperature:** 0.7 (creative exploration)
**Max iterations:** 15 (needs room to search and fetch)

### Reviewer Agent (`topic_review`)

**Input:**
- The explorer's topic candidates
- Project profile (for brand context)

**Behavior:**
- Evaluates each topic for coherence: can this be logically angled into a story for this brand's blog?
- Criteria: Is the brand connection natural or forced? Is the angle specific enough? Too broad/narrow? Would a reader understand why this brand is writing about this?
- Approves or rejects each topic with clear reasoning

**Tools:**
- `submit_review` — submit tool, schema:

```json
{
  "reviews": [
    {
      "title": "string — echo back the topic title",
      "verdict": "approved | rejected",
      "reasoning": "string — why approved or rejected"
    }
  ]
}
```

No web tools — pure reasoning on the explorer's output.

**Temperature:** 0.3 (precise evaluation)
**Max iterations:** 5 (no tool calls besides submit)

## Ping-Pong Loop

Orchestrated server-side in Go, not by the agents themselves.

```
all_approved = []

for round = 1 to 3:
    create topic_explore step (round=round)
    run explorer agent
    parse explorer output → proposed_topics

    create topic_review step (round=round)
    run reviewer agent with proposed_topics
    parse reviewer output → reviews

    new_approved = reviews where verdict == "approved"
    all_approved += new_approved
    rejected = reviews where verdict == "rejected"

    if len(all_approved) >= 2:
        save all_approved to topic_backlog
        mark run as completed
        break

    if round < 3:
        feed rejected topics + reasoning back to explorer as context
        also tell explorer which topics are already approved (don't re-propose)

    if round == 3:
        save all_approved to topic_backlog (even if 0 or 1)
        mark run as completed
```

Each round produces 2 step rows in the DB. Maximum 6 steps (3 rounds).

## Routes

```
GET  /projects/{id}/topics                           → list page (backlog + runs)
POST /projects/{id}/topics/generate                  → create run, redirect to run page
GET  /projects/{id}/topics/{runID}                   → run detail page
GET  /projects/{id}/topics/{runID}/stream            → SSE, drives the full ping-pong loop
POST /projects/{id}/topics/backlog/{topicID}/start   → create pipeline run from topic
POST /projects/{id}/topics/backlog/{topicID}/delete  → soft-delete backlog item
```

## SSE Streaming Protocol

Single SSE connection for the entire run (unlike pipeline where each step is triggered separately).

**Events:**

| Event | Fields | When |
|-------|--------|------|
| `step_start` | `round`, `step_type` | A new step begins |
| `thinking` | `chunk` | Streaming thinking tokens |
| `chunk` | `chunk` | Streaming content tokens |
| `tool_start` | `tool`, `summary` | Tool execution started |
| `tool_result` | `tool`, `summary` | Tool execution completed |
| `step_done` | `round`, `step_type` | Step completed |
| `round_complete` | `round`, `approved_count`, `total_approved` | Round finished |
| `done` | `topics: [...]` | Run complete, final approved topics |
| `error` | `error` | Error occurred |

The frontend builds step cards progressively as `step_start`/`step_done` events arrive, showing streaming content within each active step.

## UI

### Topics List Page (`/projects/{id}/topics`)

**Sidebar:** New "Topics" nav item between Pipeline and Context & Memory.

**Layout:**
- Header: "Topics" with "Generate" button
- **Topic Backlog** section: grid of topic cards
  - Each card shows: title, angle (truncated), source count
  - Actions: "Start Pipeline" button, "Delete" button
  - Cards with `status: used` shown dimmed with "Used" badge
  - Cards with `status: deleted` not shown
- **Generation Runs** section: list of past/active runs
  - Shows: timestamp, status badge, number of topics produced
  - Links to run detail page

### Topic Run Detail Page (`/projects/{id}/topics/{runID}`)

**Layout mirrors pipeline run detail:**
- Back link to topics list
- Step cards grouped by round ("Round 1", "Round 2", etc.)
- Each step card shows:
  - Step type label ("Explorer" / "Reviewer")
  - Status badge (pending/running/completed/failed)
  - Expandable output and thinking sections
- When run is active: SSE streams content into the current step card
- When run completes: approved topic cards appear at the top as a summary

## Handler & Orchestration

**`TopicHandler`** in `web/handlers/topic.go`:
- Holds: `queries`, `aiClient`, `braveClient`, `model` (ideation model), prompt builder
- The `/stream` endpoint:
  1. Opens SSE connection
  2. Fetches blog URL and homepage URL from project settings
  3. Builds profile string
  4. Runs the ping-pong loop, creating steps in DB as it goes
  5. Each agent call uses `runWithTools` (same helper as pipeline steps)
  6. Streams events to the client throughout
  7. On completion, saves approved topics to `topic_backlog`

**Tool registry additions:**
- `topic_explore` step tools: `web_search`, `fetch_url`, `submit_topics`
- `topic_review` step tools: `submit_review`

**Prompt builder additions:**
- `ForTopicExplore(profile, blogContent, homepageContent, rejectedTopics, approvedTopics string) string`
- `ForTopicReview(profile, topics string) string`

## Project Settings UI

Two new fields on the project settings page:
- **Blog URL** — text input, optional
- **Homepage URL** — text input, optional

These are stored via the existing `project_settings` key-value table.

## Files to Create/Modify

### New files:
- `migrations/NNN_topic_generator.sql` — new tables
- `internal/store/topic.go` — DB queries for topic_runs, topic_steps, topic_backlog
- `web/handlers/topic.go` — handler with routes and orchestration loop
- `web/templates/topic.templ` — list page and run detail page templates
- `prompts/topic_explore.md` — explorer system prompt
- `prompts/topic_review.md` — reviewer system prompt

### Modified files:
- `internal/tools/registry.go` — add `topic_explore` and `topic_review` tool sets
- `internal/prompt/builder.go` — add `ForTopicExplore` and `ForTopicReview` methods
- `cmd/server/main.go` — register TopicHandler, restore ideation model
- `web/templates/components/layout.templ` — add "Topics" sidebar nav item
- `web/templates/project_settings.templ` — add blog URL and homepage URL fields
- `web/handlers/project_settings.go` — handle saving the new fields
