# Profile Chat — Chat-First Profile Builder

## Overview

Replace the form-based profile page with a **chat-first experience**. 70/30 layout: chat on the left, profile cards on the right. The AI drives profile building through conversation, proposing card updates inline using `[UPDATE]` markers. Cards are also directly editable. Free-form text, not JSON.

## Layout

Single page at `/projects/:id/profile`.

- **Left (70%):** Chat area — message history, streaming response, text input at bottom
- **Right (30%):** 10 profile cards stacked vertically, scrollable. Each card: section title + content. Empty cards dimmed with placeholder. Cards are click-to-edit.
- **Input placeholder:** "Tell me about this client — paste a website, brand docs, or just describe what they do..."
- **Input disabled** during AI generation

## Profile Sections

Same 10 sections as before, but content is **free-form text**, not JSON:

| Section | What it captures |
|---------|-----------------|
| business | What they do, who they serve, industry |
| audience | Demographics, pain points, what they care about |
| voice | Personality, vocabulary, sentence style |
| tone | Formality, humor, emotion, persuasion style |
| strategy | Goals, platforms, posting frequency |
| pillars | 3-5 core topic categories |
| guidelines | Dos/don'ts, phrases to use/avoid |
| competitors | Who they compete with, content strengths/weaknesses |
| inspiration | Creators or brands they admire |
| offers | What they sell, CTAs |

## AI Response Format

The AI streams a single response mixing conversation and update proposals:

```
Great, sounds like a solid agency focused on technical clients.

[UPDATE:business]
Web development agency specializing in scalable applications for mid-size
tech companies. Founded with a focus on clean code, performance, and
modern frameworks like Go, Vue, and Alpine.js.
[/UPDATE]

Want to tell me more about who you're trying to reach?
```

### Frontend parsing

The JS accumulates the full streamed text in a buffer. After each SSE chunk, it re-scans the buffer from the last processed position for complete `[UPDATE:x]...[/UPDATE]` blocks. This handles the case where markers are split across chunks (e.g. `[UPDATE:bu` in one chunk, `siness]` in the next).

**Rendering states:**

1. **Normal text** (outside markers) → append to the chat bubble as it streams
2. **Opening marker detected, closing not yet received** → show a "building proposal" indicator (section name + spinner) in place of the proposal block. Buffer the content but don't render it as chat text.
3. **Closing marker received** → render the complete **proposal block**: styled card with section name, proposed text, Accept and Reject buttons
4. **Text after closing marker** → continues as normal chat text

Multiple proposals in one response render sequentially as each `[/UPDATE]` is received. All proposal blocks are interactive independently.

### On Accept
- Card on the right updates immediately with the proposed text
- POST to `/projects/:id/profile/sections/:section` with form-encoded body: `content=<the proposed text>`. Returns 200.
- Proposal block in chat shows accepted state (green border or checkmark)

### On Reject
- Proposal block shows rejected state (faded/strikethrough)
- No server call. When the user sends their next message, the handler prepends "[Rejected: {section}]" to the user message so the AI sees it in context. Alternatively, the raw assistant message (with markers) in the chat history is sufficient context — the AI can see the proposal was made but the section wasn't updated.

## System Prompt

```
You are a brand profile builder. Your job is to learn about this client through natural conversation and build out their content marketing profile.

You have 10 profile sections to fill:

1. **Business** — What the company does, who they serve, their industry, and what makes them different
2. **Audience** — Who they're trying to reach: demographics, roles, pain points, aspirations, and what content they consume
3. **Voice** — How the brand sounds: personality traits, vocabulary style, sentence patterns, characteristic phrases
4. **Tone** — The emotional register: formality level, humor, warmth, persuasion approach, how they relate to the audience
5. **Strategy** — Content goals (awareness, leads, authority), which platforms to publish on, posting frequency per platform
6. **Pillars** — The 3-5 core topic categories all content revolves around
7. **Guidelines** — Specific rules: words/phrases to always use or avoid, formatting preferences, brand-specific dos and don'ts
8. **Competitors** — Key competitors, what they do well in content, where they fall short, opportunities to differentiate
9. **Inspiration** — Creators, brands, or specific content the client admires and wants to emulate (not necessarily competitors)
10. **Offers** — Products/services they sell, primary call-to-action, secondary CTAs, what content should ultimately drive people toward

## Current profile state

{each section with its current content, or "(empty)" if not yet filled}

## How to propose updates

When you learn something relevant to a section, propose an update using this exact format:

[UPDATE:section_name]
Write the full updated content for this section here.
Use clear, natural prose. Not JSON. Not raw bullet lists unless they genuinely fit.
If the section already has content, rewrite it to incorporate both old and new information.
[/UPDATE]

## Rules

- Propose one section update at a time. If you have updates for multiple sections from a single message, include them all in your response but each as a separate [UPDATE] block.
- Always rewrite the full section content when updating — do not write diffs or "add this to existing."
- After proposing updates, continue the conversation. Ask follow-up questions to fill gaps in other sections.
- Do not make up information. Only propose updates based on what the user has actually told you.
- Be conversational and concise. Don't lecture. Don't repeat back everything the user said.
- If the user gives you a large dump of info (like a website paste), process it methodically — propose the most important sections first.
- If a proposal is rejected, acknowledge it briefly and move on. You'll see rejected proposals in the chat history.
```

## Data Model

### Keep
- `profile_sections` — one row per section per project. `content` is free-form text (not JSON).

### Drop (clean break)
- `section_inputs` table
- `section_proposals` table
- `project_references` table

### Migration
Rewrite `001_initial.sql`: remove the three dropped tables, update `profile_sections.content` DEFAULT from `'{}'` to `''`. Delete the DB file and recreate (no goose migration needed — clean break).

Update `BuildProfileString` to skip sections where `content == ""` instead of `content == "{}"`.

## Chat Persistence

The profile chat is stored using the existing `brainstorm_chats` / `brainstorm_messages` tables. One profile chat per project, auto-created on first visit.

Use `section = 'profile'` on the `brainstorm_chats` row to distinguish it from regular brainstorm chats.

### Chat resolution

Add a store function `GetOrCreateProfileChat(projectID int64) (*BrainstormChat, error)` that:
1. Queries `brainstorm_chats WHERE project_id = ? AND section = 'profile'`
2. If found, returns it
3. If not found, creates one with `title = "Profile Builder"` and `section = "profile"`, returns it

All profile endpoints (message, stream) resolve the chat via this function using just the `projectID` from the URL — no chat ID in the path.

## Endpoints

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/projects/:id/profile` | Render profile page (chat + cards) |
| POST | `/projects/:id/profile/message` | Save user message, return 200 |
| GET | `/projects/:id/profile/stream` | SSE stream AI response |
| POST | `/projects/:id/profile/sections/:section` | Direct edit save from card |

## Context Injection for Other Features

Brainstorm chats and pipeline agents continue to use `BuildProfileString()` to get the full profile. This function reads all `profile_sections` rows and concatenates them. Since content is now free-form text instead of JSON, the output is cleaner and more natural in prompts.

## What Gets Removed

- `section_inputs`, `section_proposals`, `project_references` tables (from migration)
- `internal/agents/profile.go` — batch ProfileAgent no longer needed
- `internal/agents/profile_test.go`
- `web/handlers/profile.go` — rewrite entirely
- `web/templates/profile.templ` — rewrite entirely
- `internal/store/profile.go` — keep only `UpsertProfileSection`, `GetProfileSection`, `ListProfileSections`, `BuildProfileString`. Remove input/proposal/reference CRUD.

## What Gets Added/Rewritten

- `web/handlers/profile.go` — new handler: page render, message save, SSE stream with profile system prompt, direct section edit
- `web/templates/profile.templ` — 70/30 layout with chat and cards
- `web/static/app.js` — add `[UPDATE]` marker parsing to the chat JS for the profile page
- `web/static/style.css` — profile layout styles (flexbox 70/30, card styles, proposal block styles)
