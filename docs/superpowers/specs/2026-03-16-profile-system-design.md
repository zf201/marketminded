# Profile System Redesign

## Overview

Replace the current flat knowledge base and opaque voice/tone profiles with a structured **client profile system**. The profile has 10 sections, each with its own inputs, AI-generated proposals, and approval workflow. Sections are refinable anytime via scoped chat. The full profile feeds into brainstorms and pipelines as context.

## Profile Sections

Each project has up to 10 profile sections:

| Section | What it captures |
|---------|-----------------|
| `business` | What they do, who they serve, industry |
| `audience` | Demographics, pain points, what they care about |
| `voice` | Personality, vocabulary, sentence style |
| `tone` | Formality, humor, emotion, persuasion style |
| `strategy` | Goals, platforms, posting frequency per platform |
| `pillars` | 3-5 core topic categories |
| `guidelines` | Dos/don'ts, phrases, things to avoid |
| `competitors` | Who they compete with, what competitors do well/poorly in content |
| `inspiration` | Creators, brands, or content they admire and want to emulate |
| `offers` | What they sell, CTAs, what actions content should drive |

## Data Model

Clean break from the current system. This is a fresh project with no production data — no migration needed, just replace.

### New migration (replaces relevant parts of 001)

Drop: `knowledge_items` table, `projects.voice_profile` column, `projects.tone_profile` column.

Add:

```sql
CREATE TABLE profile_sections (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT NOT NULL CHECK(section IN (
        'business','audience','voice','tone','strategy',
        'pillars','guidelines','competitors','inspiration','offers'
    )),
    content TEXT NOT NULL DEFAULT '{}',  -- JSON, the live approved profile data
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE(project_id, section)
);

CREATE TABLE section_inputs (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT,  -- nullable: null means general/unsorted input
    title TEXT,
    content TEXT NOT NULL,
    source_url TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_section_inputs_project ON section_inputs(project_id, section);

CREATE TABLE section_proposals (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    section TEXT NOT NULL,
    proposed_content TEXT NOT NULL,  -- JSON, what the AI wants to save
    status TEXT NOT NULL DEFAULT 'pending' CHECK(status IN ('pending','approved','rejected')),
    rejection_reason TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_section_proposals_project ON section_proposals(project_id, section);

CREATE TABLE project_references (
    id INTEGER PRIMARY KEY,
    project_id INTEGER NOT NULL REFERENCES projects(id) ON DELETE CASCADE,
    title TEXT,
    content TEXT NOT NULL,
    source_url TEXT,
    saved_by TEXT NOT NULL DEFAULT 'user' CHECK(saved_by IN ('user','agent')),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
```

Also update `agent_runs.agent_type` CHECK constraint to include `'profile'`.

### Approval semantics

Approving a proposal **replaces** the section's content entirely. The proposal contains the full new state of the section, not a diff. This keeps the logic simple — no merge conflicts, no deep JSON merging. The agent is responsible for incorporating existing content into its proposal when refining.

### JSON schemas per section

Each section's `content` field stores a JSON object. Example shapes:

```json
// voice
{
  "personality": "Confident, approachable, slightly irreverent",
  "vocabulary": "Technical but accessible, avoids jargon unless audience expects it",
  "sentence_style": "Short punchy sentences mixed with longer explanatory ones",
  "phrases": ["ship it", "here's the thing", "let's be real"],
  "dos": ["Use active voice", "Address the reader directly"],
  "donts": ["Don't use corporate buzzwords", "Don't hedge with 'maybe' or 'perhaps'"]
}

// strategy
{
  "goals": ["Build authority in web dev space", "Generate inbound leads"],
  "platforms": [
    {"name": "linkedin", "frequency": "3x/week", "content_types": ["text posts", "carousels"]},
    {"name": "instagram", "frequency": "5x/week", "content_types": ["reels", "carousels"]}
  ],
  "blog_frequency": "1x/week"
}

// pillars
{
  "pillars": [
    {"name": "Technical Deep Dives", "description": "In-depth posts on Go, architecture, performance"},
    {"name": "Client Success Stories", "description": "Case studies and lessons learned"},
    {"name": "Industry Trends", "description": "Commentary on web dev trends and tools"}
  ]
}

// competitors
{
  "competitors": [
    {"name": "Agency X", "url": "https://...", "strengths": "Great video content", "weaknesses": "Blog is generic"},
    {"name": "Studio Y", "url": "https://...", "strengths": "Strong LinkedIn presence", "weaknesses": "No personality"}
  ]
}

// offers
{
  "products": ["Custom web development", "Headless CMS implementations"],
  "primary_cta": "Book a free consultation",
  "secondary_ctas": ["Subscribe to newsletter", "Download our guide"]
}
```

Sections not shown (business, audience, tone, guidelines, inspiration) follow the same pattern: a JSON object with descriptive fields. The agent determines the exact shape based on the inputs provided. Consumers treat the content as opaque text — they serialize it into prompts, not parse individual fields.

## Profile Analysis Agent

Replaces the separate `VoiceAgent` and `ToneAgent`. Single agent that handles all 10 sections.

### Inputs
- All `section_inputs` for the project (tagged and untagged)
- Current `profile_sections` content (what's already approved)
- Past rejected `section_proposals` with their `rejection_reason`

### Behavior
- Reads all inputs and existing profile
- For each section where it has enough signal, proposes structured JSON content
- Does not propose for sections with insufficient data
- Does not replace existing approved content — proposes additions or refinements
- Accounts for rejection reasons: if the user previously said "we're not that formal", the agent adjusts
- Returns an array of proposals, one per section it has something for

### Output
Each proposal is a `section_proposals` row with `status = 'pending'`.

## Proposal/Approval Flow

### Step 1: Add inputs
User pastes raw content (blog posts, brand docs, notes, URLs) into the profile page. Each input can be tagged to a section or left general.

### Step 2: Analyze
User clicks "Analyze". The Profile Analysis Agent runs and creates `section_proposals` rows.

### Step 3: Review
Each pending proposal is shown as a card with:
- Section name
- Proposed content preview
- Three actions:
  - **Approve** — `proposed_content` merges into `profile_sections.content` for that section. Proposal status → `approved`.
  - **Reject** — user provides a reason. Proposal status → `rejected`, reason saved.
  - **Edit & Approve** — user modifies the proposed content, then approves.

### Step 4: Re-analyze (optional)
After rejections, user can re-trigger analysis. The agent sees rejection reasons and adjusts its proposals.

## Section Refinement Chat

Any profile section can be refined via a scoped chat.

- Click "Refine" on a section
- Opens a chat (reuses the brainstorm chat pattern: vanilla JS, SSE streaming)
- AI has context: current section content, inputs for that section, past rejections
- User discusses changes
- When the AI has a concrete update, it creates a `section_proposal` for that section
- User approves/rejects as usual

Chat history is persisted using the existing `brainstorm_chats` / `brainstorm_messages` tables. Add a `section TEXT` column to `brainstorm_chats` (nullable). When null, it's a regular brainstorm chat. When set (e.g., `"voice"`), it's a refinement chat scoped to that section. The handler uses this to filter and scope context.

## Context Injection

When brainstorm chats or pipeline agents need the client profile, they pull all `profile_sections` for the project and serialize them into a single `profile` string for the system prompt. Format:

```
## Business
{business section JSON}

## Voice
{voice section JSON}

...
```

Whatever sections exist get included; missing sections are skipped.

### Agent interface change

Replace the `VoiceProfile string` and `ToneProfile string` fields on `PillarInput`, `SocialInput`, and `IdeaInput` with a single `Profile string` field. The handler builds this string from `profile_sections` and passes it in. This is a breaking change to the agent structs — all callers (pipeline handler, brainstorm handler) need updating.

## References

Cross-cutting reference library. Not tied to a specific section.

- Users can save references manually
- Agents (brainstorm, idea agent) can save references they find useful (Brave search results, interesting content)
- References are available as context when agents need them but are not automatically injected into every call

## What Gets Removed

- `internal/agents/voice.go` — replaced by Profile Analysis Agent
- `internal/agents/tone.go` — replaced by Profile Analysis Agent
- `internal/store/knowledge.go` — replaced by section_inputs/proposals/project_references store
- `web/handlers/knowledge.go` — replaced by profile handler
- `web/templates/knowledge.templ` — replaced by profile template
- `knowledge_items` table
- `projects.voice_profile` column
- `projects.tone_profile` column

## What Gets Added

- `internal/agents/profile.go` — Profile Analysis Agent
- `internal/store/profile.go` — CRUD for profile_sections, section_inputs, section_proposals, project_references
- `web/handlers/profile.go` — profile page, analyze, approve/reject, refinement chat
- `web/templates/profile.templ` — section tabs, inputs, proposals, refinement UI

## UI

The Knowledge page is replaced by a **Profile page** at `/projects/:id/profile`.

- **Section tabs** across the top (Business, Audience, Voice, etc.)
- Each tab shows:
  - Current approved content for that section
  - Inputs tagged to that section
  - Pending proposals (if any) with approve/reject/edit actions
  - "Refine" button to start a refinement chat
- **Inputs area** at the bottom or in a sidebar: add new inputs, tag to section or leave general
- **Analyze button** in the header: triggers Profile Analysis Agent across all sections
- **References tab**: separate tab for the cross-cutting reference library
