# MarketMinded — Content Waterfall Engine

## Overview

Internal tool for a web development team to produce blog posts and accompanying social content for clients. Inspired by the "Content Waterfall" system — one pillar piece of content waterfalls into multiple platform-specific pieces.

Built as a single Go binary with templ + Alpine.js frontend and SQLite storage. Uses OpenRouter for LLM calls and Brave Search API for web research.

Designed for a single team now, architected with project isolation so it can scale to multi-tenant/agency use later.

## Core Concept

Every client is a **Project**. Each project has its own knowledge base (voice, tone, brand docs), content templates, and content log. Content is produced through a staged **waterfall pipeline** that enforces quality gates at each step.

## Data Model

All stored in SQLite. Single DB file. Pure Go driver (`modernc.org/sqlite`, no CGO).

### SQL Schema (draft)

```sql
CREATE TABLE projects (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    description TEXT,
    voice_profile TEXT,        -- synthesized voice profile (JSON), built by Voice Agent
    tone_profile TEXT,         -- synthesized tone profile (JSON), built by Tone Agent
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE knowledge_items (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    type TEXT NOT NULL CHECK(type IN ('voice_sample','tone_guide','brand_doc','reference')),
    title TEXT,
    content TEXT NOT NULL,
    source_url TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE templates (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    name TEXT NOT NULL,
    platform TEXT NOT NULL CHECK(platform IN ('instagram','facebook','linkedin')),
    html_content TEXT NOT NULL,  -- uses {{.Title}}, {{.Body}}, {{.ImageURL}} Go template slots
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE pipeline_runs (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    status TEXT NOT NULL DEFAULT 'ideating'
        CHECK(status IN ('ideating','creating_pillar','waterfalling','complete','abandoned')),
    selected_topic TEXT,        -- chosen topic after ideation
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE content_pieces (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    pipeline_run_id INTEGER REFERENCES pipeline_runs(id),
    type TEXT NOT NULL CHECK(type IN ('blog','social_instagram','social_facebook','social_linkedin')),
    title TEXT,
    body TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'draft' CHECK(status IN ('draft','approved','published')),
    parent_id INTEGER REFERENCES content_pieces(id),  -- social posts link to pillar blog
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE agent_runs (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    pipeline_run_id INTEGER REFERENCES pipeline_runs(id),
    agent_type TEXT NOT NULL CHECK(agent_type IN ('voice','tone','idea','content')),
    prompt_summary TEXT,       -- brief description of what was asked (full prompt can be large)
    response TEXT NOT NULL,
    content_piece_id INTEGER REFERENCES content_pieces(id),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE brainstorm_chats (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id),
    title TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE brainstorm_messages (
    id INTEGER PRIMARY KEY,
    chat_id INTEGER NOT NULL REFERENCES brainstorm_chats(id),
    role TEXT NOT NULL CHECK(role IN ('user','assistant')),
    content TEXT NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

### Key design decisions:
- **Voice/tone profiles** live on the `projects` table directly (not as knowledge items) so agents can look them up in one query. Knowledge items are the raw inputs; profiles are the synthesized output.
- **Pipeline runs** have explicit state: `ideating → creating_pillar → waterfalling → complete`. Users can also `abandon` a run. No backwards transitions — start a new run instead.
- **Template placeholders** use Go template syntax: `{{.Title}}`, `{{.Body}}`, `{{.ImageURL}}`. Templates are validated on upload to ensure required slots exist.
- **Agent runs** store a `prompt_summary` (not the full prompt, which can be huge with context injection) and the full `response`.
- **Brainstorm messages** are stored as individual rows for easy querying and streaming.

## Waterfall Pipeline

Staged pipeline per project. Each run follows this sequence:

### 1. SETUP (once per project, updated over time)
- Voice Agent analyzes client samples → builds voice profile
- Tone Agent extracts tone rules → builds tone profile
- Both stored as project Knowledge
- Iterative: profiles improve as more samples are added

### 2. IDEATE
- Idea Agent uses: Brave web search + project knowledge + content log
- Proposes ranked pillar topics
- User picks or edits the winner

### 3. CREATE PILLAR
- Content Agent writes the blog post
- Uses: voice profile, tone profile, selected topic, content log (continuity)
- User reviews and edits → marks as approved

### 4. WATERFALL
- Content Agent repurposes pillar into social posts
- Uses: pillar content + platform-specific HTML templates + voice/tone profiles
- Generates 2+ social posts (one per selected platform)
- User reviews and edits each → marks as approved

**Key rules:**
- Stage gates — each step requires user approval before advancing
- Content log — every approved piece feeds back into project knowledge for continuity. Injected into prompts as a list of titles + short summaries (not full text) to stay within context limits.
- Pillar is the anchor — social posts always link back to their parent pillar via `parent_id`
- No backward transitions — if ideation was bad, abandon the run and start fresh
- SETUP is skipped if voice/tone profiles already exist on the project. Users can re-run setup from the Knowledge Manager at any time.

### Pipeline State Machine

```
ideating → creating_pillar → waterfalling → complete
    ↓            ↓                ↓
    abandoned    abandoned        abandoned
```

Each transition requires user action (approve/advance). Failed agent calls can be retried within the same stage.

## Agents

| Agent | Purpose | Inputs | External APIs |
|-------|---------|--------|---------------|
| Voice Agent | Analyze samples, build voice profile | Client content samples | OpenRouter |
| Tone Agent | Extract tone rules, build tone profile | Client content samples, brand docs | OpenRouter |
| Idea Agent | Research trends, propose pillar topics | Project knowledge, content log, search query | OpenRouter, Brave Search |
| Content Agent | Generate pillar blog + waterfall social posts | Voice/tone profiles, topic, content log, templates | OpenRouter |

All agents are Go functions within a single binary. They chain OpenRouter chat completion calls with project context injected as system/user messages.

### Agent Configuration
- **Model selection**: Configurable per agent type via environment variable or config. Default to a capable model (e.g., `anthropic/claude-sonnet-4-20250514`) for content generation, cheaper model for ideation.
- **Streaming**: Agents stream responses to the UI via SSE (Server-Sent Events). The Pipeline Run and Brainstorm pages hold an open SSE connection during generation.
- **Context management**: Content log is injected as titles + summaries. Voice/tone profiles are injected in full. Knowledge items are selected by relevance (most recent + matching type).
- **Retries**: On OpenRouter failure, the UI shows an error with a "Retry" button. No automatic retries.

### Brave Search
- Idea Agent formulates search queries autonomously based on the topic/niche
- Fetches top 5-10 results per query
- Results are summarized and injected into the ideation prompt, not stored permanently

## Brainstorm Chat

- Per-project freeform chat with full access to project knowledge
- No stage gates — open-ended conversation for ideation
- Save to pipeline: push a brainstorm idea directly into the ideation stage
- Chat history persisted per project

## Tech Stack

| Component | Choice | Rationale |
|-----------|--------|-----------|
| Language | Go | Team preference, single binary deployment |
| Templates | templ | Type-safe HTML components, compiled to Go |
| Interactivity | Alpine.js | Lightweight client-side state for modals, previews, dropdowns |
| Database | SQLite (modernc.org/sqlite) | Pure Go, no CGO, single file, simple |
| LLM | OpenRouter | Model flexibility, single API for multiple providers |
| Web Search | Brave Search API | Simple REST API for idea agent research |
| HTML-to-PNG | rod | Headless Chrome rendering for social post templates. Requires Chrome/Chromium on the host. |
| Streaming | SSE (Server-Sent Events) | Agent output streamed to browser during pipeline and brainstorm |
| Migrations | goose or similar | SQL migration management |

## Project Structure

```
marketminded/
├── cmd/server/          # main.go — single entry point
├── internal/
│   ├── project/         # project CRUD, knowledge management
│   ├── pipeline/        # waterfall orchestration (stage gates, state machine)
│   ├── agents/          # agent definitions & prompt chains
│   │   ├── voice.go     # voice profile builder
│   │   ├── tone.go      # tone profile builder
│   │   ├── idea.go      # ideation + Brave search
│   │   └── content.go   # pillar & waterfall generation
│   ├── ai/              # OpenRouter client (chat completions, streaming)
│   ├── search/          # Brave Search API client
│   ├── templates/       # HTML-to-PNG rendering for social posts
│   └── store/           # SQLite queries and models
├── web/
│   ├── templates/       # templ components
│   ├── static/          # Alpine.js, CSS, images
│   └── handlers/        # HTTP handlers per page/action
├── migrations/          # SQL migrations
└── go.mod
```

## UI Pages

| Page | Purpose |
|------|---------|
| Dashboard | List projects, create new |
| Project Overview | Knowledge items, voice/tone status, content log |
| Knowledge Manager | Add/edit voice samples, brand docs, references |
| Pipeline Run | Step-through the waterfall — ideate → pillar → waterfall. Each stage shows agent output streaming in, lets you edit/approve before advancing |
| Content Piece | View/edit a single blog or social post. For socials: live preview of HTML template with filled content |
| Template Manager | Upload/edit HTML social templates per platform |
| Brainstorm | Freeform chat with project context, can push ideas to pipeline |

The **Pipeline Run** page is the core experience — a wizard that walks through the waterfall stages with live AI output.

## Configuration

API keys and settings via environment variables:
- `OPENROUTER_API_KEY` — required
- `BRAVE_API_KEY` — required
- `MARKETMINDED_DB_PATH` — SQLite file path (default: `./marketminded.db`)
- `MARKETMINDED_PORT` — HTTP port (default: `8080`)
- `MARKETMINDED_MODEL_CONTENT` — OpenRouter model for content generation
- `MARKETMINDED_MODEL_IDEATION` — OpenRouter model for ideation/brainstorm

## Architecture Layers

```
web/handlers → internal/pipeline → internal/agents → internal/ai
                    ↓                    ↓               ↓
              internal/store      internal/search    OpenRouter API
                    ↓
                 SQLite
```

- **handlers** — HTTP + SSE, calls pipeline or project services
- **pipeline** — orchestrates agent calls, manages run state
- **agents** — prompt construction, context assembly, response parsing
- **ai** — thin OpenRouter HTTP client (streaming + non-streaming)
- **search** — thin Brave Search HTTP client
- **store** — all SQL queries, returns typed Go structs
- **project** — CRUD operations that combine multiple store calls

## Future Considerations (not MVP)

- Multi-tenancy (user accounts, team management)
- Direct publishing integrations (LinkedIn API, Meta API, etc.)
- Content calendar / scheduling
- Analytics feedback loop (what performed well feeds back into ideation)
- Multiple pillar formats (video transcripts, podcasts)
