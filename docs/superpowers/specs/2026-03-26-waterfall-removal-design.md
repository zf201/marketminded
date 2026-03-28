# Waterfall Removal Design

**Date:** 2026-03-26
**Status:** Draft
**Goal:** Remove the waterfall phase from the pipeline, keeping the cornerstone content pipeline untouched. Waterfall will later be reintroduced via a separate chat agent.

## Context

The cornerstone pipeline (researcher → brand enricher → fact-checker → tone analyzer → writer) is performing well. The waterfall phase — where derivative content pieces are planned and generated from the cornerstone — will be better served by a dedicated chat agent that can interactively build the full distribution funnel. Removing it now streamlines the pipeline and lets us focus on making the chat-based waterfall good as a separate effort.

## Approach: Surgical Removal, Schema Intact

Remove all waterfall **code paths, routes, handlers, templates, and JS**. Keep the **database schema** (phase column, parent_id, plan_waterfall step type) unchanged — it's harmless and will be reused by the future chat agent.

## Changes

### 1. Route Registration (`web/handlers/pipeline.go` ~lines 60-63)

**Remove** these two route cases from `Handle()`:
- `strings.HasSuffix(rest, "/waterfall") && r.Method == "GET"` → `h.showWaterfall`
- `strings.HasSuffix(rest, "/waterfall/create-plan") && r.Method == "POST"` → `h.createWaterfallPlan`

### 2. Handlers (`web/handlers/pipeline.go`)

**Delete entirely:**
- `showWaterfall` handler (~lines 216-282)
- `createWaterfallPlan` handler (~lines 284-293)
- `streamWaterfallPlan` function (~lines 1600-1730+)
- `waterfallPlanTool` function (~lines 1569-1597)

**Remove from `streamStep` switch** (~line 1249-1250):
- The `case "plan_waterfall"` branch that dispatches to `streamWaterfallPlan`. Without this removal, deleting `streamWaterfallPlan` causes a compile error.

### 3. Approve Piece Handler (`web/handlers/pipeline.go` ~lines 389-423)

**Replace** the current `approvePiece` logic. Currently:
1. If cornerstone approved → flip phase to waterfall, return `phase_change: "waterfall"`
2. If waterfall piece → check if all done

**New behavior:**
1. If cornerstone approved → mark run as `complete`, return `{"complete": true}`
2. Remove waterfall piece approval logic (no waterfall pieces will exist)

The simplified handler:
```go
func (h *PipelineHandler) approvePiece(...) {
    // ... parse IDs, set status approved ...
    piece, _ := h.queries.GetContentPiece(pieceID)
    h.queries.SetContentPieceStatus(pieceID, "approved")

    // Cornerstone approved = run complete
    if piece.ParentID == nil {
        run, _ := h.queries.GetPipelineRun(runID)
        h.queries.UpdatePipelineStatus(runID, "complete")
        w.Header().Set("Content-Type", "application/json")
        json.NewEncoder(w).Encode(map[string]any{"complete": true})
        return
    }

    // Fallback (shouldn't happen without waterfall, but safe)
    w.Header().Set("Content-Type", "application/json")
    json.NewEncoder(w).Encode(map[string]any{"complete": false})
}
```

### 4. Piece Prompt Builder (`web/handlers/pipeline.go` ~lines 312-325)

**Remove** the `else` branch in `buildPiecePrompt` that injects cornerstone body for waterfall pieces:
```go
// DELETE this entire else block:
} else {
    // Waterfall — inject cornerstone
    cornerstone, _ := h.queries.GetContentPiece(*piece.ParentID)
    prompt += fmt.Sprintf("\n## Cornerstone content (your source material)\n%s\n", cornerstone.Body)
    prompt += "\nIMPORTANT: This content exists to funnel audience..."
}
```

Since only cornerstone pieces will exist, the `if piece.ParentID == nil` check can be simplified — but keeping it is fine for forward compatibility. The else branch just becomes empty or logs a warning.

### 5. Writer System Prompt (`web/handlers/pipeline.go` ~lines 1054-1103)

The cornerstone writer prompt currently includes the client profile via `BuildProfileString`, which includes the `content_strategy` section. **Remove content_strategy from the writer's profile context.** The writer's job is to write the cornerstone piece — it doesn't need distribution strategy.

In `BuildProfileString` or in the writer handler, filter out the `content_strategy` section from the profile string passed to the writer agent. The cleanest approach: add a `BuildProfileStringExcluding(projectID, excludeSections []string)` method, or simply skip it in the writer's prompt assembly.

**Recommended approach:** Since `BuildProfileString` is used by multiple agents, don't modify it globally. Instead, in the writer's prompt construction (~line 1052-1055), build the profile without content_strategy. Either:
- Call `BuildProfileString` and strip the `## Content_strategy` section, or
- Add a variant like `BuildProfileStringFor(projectID, sections []string)` that only includes specified sections

### 6. Profile Section Reorder & Rename (`web/handlers/profile.go`)

**Reorder `allSections`:**
```go
// Before:
var allSections = []string{
    "product_and_positioning", "audience", "voice_and_tone",
    "content_strategy", "guidelines",
}

// After:
var allSections = []string{
    "product_and_positioning", "audience", "voice_and_tone",
    "guidelines", "content_strategy",
}
```

**Update `content_strategy` description** — remove waterfall-specific language:
```go
"content_strategy": `Content goals (traffic, leads, authority, community). Which platforms to post on and why. Content formats per platform (blog, carousel, reel, thread, newsletter). Posting frequency per platform. 3-5 content pillars: recurring topic categories with example post ideas for each. For each pillar, include both "searchable" content (captures existing demand via SEO) and "shareable" content (creates demand through insights, stories, original takes). Define how the client's cornerstone content gets distributed across social platforms — what goes where and how many pieces per platform.`,
```

**Update `sectionTitle` or add special-case rendering** to display "Social Content Strategy" as the h2 title for the `content_strategy` section. The simplest approach: add a `sectionDisplayTitle` map:
```go
var sectionDisplayTitles = map[string]string{
    "content_strategy": "Social Content Strategy",
}
```

And use it in the title rendering. **Both `sectionTitle` functions** must be updated — there are two copies:
- `web/handlers/profile.go` line 318 — used for profile page card titles and profile chat agent prompts
- `internal/store/profile.go` line 71 — used by `BuildProfileString` for agent-facing section headers

Both must resolve `content_strategy` → "Social Content Strategy" so agents see the correct header.

### 7. Templates (`web/templates/pipeline.templ`)

**Delete:**
- `WaterfallPageData` struct (~lines 252-260)
- `WaterfallPage` template (~lines 262-343)
- `hasPendingPieces()` helper if only used by waterfall

**Remove from `ProductionBoardPage`:**
- "Go to Waterfall" link (~lines 243-247): the `if data.Phase == "waterfall"` block

**Remove from `PipelineListPage`:**
- Phase badge rendering (~lines 49-52): the `if run.Phase != ""` block. Only show the status badge.

### 8. JavaScript (`web/static/app.js`)

**Delete:**
- `initWaterfallPage` function (~lines 1645-1799)
- Waterfall page init hook (~lines 483-486)

**Modify approve handler** (~lines 1381-1402):
- Remove `phase_change === 'waterfall'` redirect logic
- On `complete: true`, show a completion state or reload

**Remove from `renderPlan`** (~lines 827-841):
- The waterfall pieces rendering block (`data.waterfall`) that renders planned waterfall items. Dead code after removal.

### 9. Store Layer

**Keep all methods.** `UpdatePipelinePhase`, `NextPendingPiece`, `AllPiecesApproved` etc. remain in the store. They're not called from waterfall paths anymore, but they're harmless and may be reused.

### 10. Cornerstone Agent Prompts — Audit

Review all 5 cornerstone agent prompts for stray waterfall/distribution references. Based on current code:

- **Researcher** (~lines 787-806): No waterfall references. No changes needed.
- **Brand Enricher** (~lines 1339-1362): No waterfall references. No changes needed.
- **Fact-Checker** (~lines 915-931): No waterfall references. No changes needed.
- **Tone Analyzer** (~lines 1490-1513): No waterfall references. No changes needed.
- **Writer** (~lines 1054-1103): No direct waterfall text in the prompt, but receives `content_strategy` via profile. Addressed by change #5 above — content_strategy is excluded from the writer's profile context.

### 11. Brainstorm Agent Prompt (`web/handlers/brainstorm.go`)

The brainstorm agent system prompt (~line 170) contains: "Reference their content pillars and waterfall flows from the profile when relevant". **Update** to remove the "waterfall flows" reference. Replace with language about content pillars and social content strategy.

### 12. Content Type Prompt Files (`prompts/types/`)

11 of 12 prompt files describe themselves as "waterfall piece" or reference waterfall distribution:
- `blog_post.md`: "repurposed into social waterfall content"
- All social platform prompts (`linkedin_post.md`, `x_post.md`, `instagram_post.md`, etc.): describe themselves as "waterfall piece"

**Update all prompt files** to remove "waterfall" language. Social platform prompts should describe themselves as social content pieces derived from cornerstone content, without using the term "waterfall."

### 13. Existing Runs in Waterfall Phase

Existing pipeline runs with `phase = 'waterfall'` become stranded:
- The waterfall page routes are removed (404 on direct access)
- Waterfall pieces (`parent_id IS NOT NULL`) remain in the database but are inaccessible
- The cornerstone piece on the production board is still viewable

This is acceptable — existing waterfall data is harmless in the DB and the schema stays for future use. No migration or cleanup needed.

## What Stays Untouched

- All 5 cornerstone agents and their execution flow
- SSE streaming infrastructure
- Step creation on pipeline run creation
- Piece approve/reject/improve/proofread workflows (minus waterfall-specific paths)
- Database schema: phase column, parent_id, plan_waterfall step type, all CHECK constraints
- All store methods
- Migration files

## What Changes (Summary)

| Area | Change |
|------|--------|
| Routes | Remove 2 waterfall routes |
| Handlers | Delete 4 functions + `plan_waterfall` case in `streamStep` |
| approvePiece | Cornerstone approval → run complete (no phase flip) |
| buildPiecePrompt | Remove waterfall else branch |
| Writer prompt | Exclude content_strategy from profile |
| Profile | Reorder: guidelines before content_strategy; rename to "Social Content Strategy"; update description; fix both `sectionTitle` functions |
| Templates | Delete WaterfallPage; remove phase badges; remove waterfall nav link |
| JavaScript | Delete initWaterfallPage; remove phase_change redirect; clean renderPlan waterfall block |
| Brainstorm prompt | Remove "waterfall flows" reference |
| Prompt files | Update 11 files in `prompts/types/` to remove "waterfall" language |
| Existing data | Waterfall-phase runs become stranded (acceptable, no cleanup) |
