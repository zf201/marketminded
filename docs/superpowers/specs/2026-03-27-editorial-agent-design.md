# Editorial Agent Design

**Date:** 2026-03-27
**Status:** Draft
**Goal:** Add an editorial agent between the tone analyzer and writer in the cornerstone pipeline. The editor digests all research, sources, tone, and framework into a structured outline, so the writer receives a condensed, focused brief instead of raw context.

## Context

The current pipeline feeds the writer everything: enriched research brief, all sources, tone guide, framework, and profile. This creates a large context window and asks the writer to simultaneously figure out story structure AND write great prose. Splitting these into two agents — editor for structure, writer for prose — produces better results and lets us use the expensive copywriting model only for the final writing step with a much smaller input.

## Pipeline Order

```
0. Researcher
1. Brand Enricher
2. Fact-Checker
3. Tone Analyzer
4. Editor        ← NEW
5. Writer         (modified input)
```

## Editorial Agent

**Step type:** `editor`
**Sort order:** 4
**Model:** Content research model (same as researcher, fact-checker, etc.)
**Temperature:** 0.3
**Tools:** `submit_editorial_outline` only (no search/fetch)

### Input

The editor receives everything the writer currently gets:
- Enriched brief from fact-checker (via pipeline step output)
- All sources from researcher + brand enricher + fact-checker steps
- Tone guide from tone analyzer step (if available)
- Storytelling framework (if set in project settings)
- Client profile (excluding content_strategy)

### System Prompt Direction

The editor's job is **narrative reasoning**:
- Analyze the research and determine the strongest angle/hook
- Decide what facts to include, what to cut, and how to order them for maximum impact
- Map the storytelling framework beats to the actual content (if framework is set)
- Build a logical throughline so the conclusion feels inevitable, not forced
- Specify which sources back which points
- Produce a tight outline that a writer can execute without needing the raw research

### Output Tool: `submit_editorial_outline`

```json
{
  "angle": "The core narrative angle — one sentence",
  "sections": [
    {
      "heading": "Suggested section heading",
      "framework_beat": "e.g. StoryBrand: Problem, or empty if no framework",
      "key_points": [
        "Specific point to make, with data if relevant",
        "Another point"
      ],
      "sources_to_use": ["url1", "url2"],
      "editorial_notes": "Tone/approach guidance for this section"
    }
  ],
  "conclusion_strategy": "How to close — what ties back, what CTA, what feeling to leave"
}
```

### Source Collection

The editor needs the same source-collection logic that currently lives in `streamWrite` (iterates all pipeline steps, parses `sources` from JSON output, deduplicates by URL). **Extract this into a shared helper** (e.g. `collectSources(steps []PipelineStep) []source`) to avoid duplication between the editor and the writer's current code. The editor passes source URLs + titles into the outline's `sources_to_use` field so the writer can reference them.

### Error Handling

Same pattern as other agents: if the tool is not called, step is marked failed with "Editor did not submit outline via tool call. Try again." Uses `ErrToolDone` to stop streaming after the tool fires.

## Writer Agent Changes

### New Input (replaces current)

The writer no longer receives:
- ~~Raw enriched brief~~
- ~~Raw sources list~~
- ~~Storytelling framework instructions~~

The writer now receives:
- **Editorial outline** (primary input — the structured JSON from the editor)
- Tone guide (still needed for prose style matching)
- Anti-AI rules
- Client profile (excluding content_strategy)

### Prompt Restructuring

The writer's system prompt is rebuilt around the outline:

```
Today's date: {date}

{content type prompt text}

## Client profile
{profile}

## Editorial outline
{outline JSON or formatted version}

## Tone & style reference
{tone guide}

{anti-AI rules}
```

If the piece was previously rejected, the rejection reason is injected after the outline:

```
## Previous rejection feedback
{rejection reason}. Address this in the new version.
```

The writer's job becomes pure prose execution: take the outline and write the best possible piece, matching the tone guide and respecting the editorial direction.

### Model

Continues to use the copywriting model (expensive). Now benefits from a significantly smaller context window since raw research and sources are not included.

## Database Changes

**Migration required:** Add `editor` to the `pipeline_steps.step_type` CHECK constraint. Same pattern as migrations 007 and 008 which added `brand_enricher` and `tone_analyzer`.

No other schema changes needed.

## Step Creation

The `create` handler currently creates 5 steps:
```go
h.queries.CreatePipelineStep(run.ID, "research", 0)
h.queries.CreatePipelineStep(run.ID, "brand_enricher", 1)
h.queries.CreatePipelineStep(run.ID, "factcheck", 2)
h.queries.CreatePipelineStep(run.ID, "tone_analyzer", 3)
h.queries.CreatePipelineStep(run.ID, "write", 4)
```

Updated to 6 steps:
```go
h.queries.CreatePipelineStep(run.ID, "research", 0)
h.queries.CreatePipelineStep(run.ID, "brand_enricher", 1)
h.queries.CreatePipelineStep(run.ID, "factcheck", 2)
h.queries.CreatePipelineStep(run.ID, "tone_analyzer", 3)
h.queries.CreatePipelineStep(run.ID, "editor", 4)
h.queries.CreatePipelineStep(run.ID, "write", 5)
```

## Step Dispatcher

Add `case "editor"` to the `streamStep` switch in `pipeline.go`. The editor needs the fact-checker output (for enriched brief + sources) and the tone analyzer output (for tone guide). It follows the same pattern as the other cornerstone agents.

The writer's dispatcher case changes from `findOutput("factcheck")` to `findOutput("editor")`. The `streamWrite` function signature changes to accept the editor output (the outline) instead of the fact-checker output. The tone guide is looked up from steps inside `streamWrite` (same as currently).

## Auto-Chain

The editor auto-chains after the tone analyzer completes (existing JS logic handles sequential step chaining). The writer auto-chains after the editor completes. No JS changes needed — the frontend already chains based on step completion events.

## Rejection Handling

When a cornerstone piece is rejected, **only the writer re-runs** (same as current behavior). The editor's outline is structural and generally valid even if the prose needs work. The rejection reason is injected directly into the writer prompt so it can address the feedback without needing a new outline.

The `rejectPiece` handler continues to reset only the `write` step to "pending". No changes needed.

If the rejection is about structural issues (wrong angle, missing sections), the user can abort and re-run the entire pipeline. This is an edge case that doesn't warrant automatic editor re-runs.

## UI

Add `case "editor": Editor` to the `stepTypeLabel` function in `pipeline.templ` for a human-friendly label. Otherwise no template changes — the editor step card renders automatically like the others.

## Existing Runs

Pipeline runs created before the migration continue with 5 steps and are unaffected. Only new runs get the editor step.

## What Stays Untouched

- Researcher, brand enricher, fact-checker, tone analyzer — unchanged
- Piece approve/reject/improve/proofread workflows
- SSE streaming infrastructure
- Database schema (beyond the CHECK constraint migration)
- JavaScript auto-chain logic

## Summary

| Aspect | Detail |
|--------|--------|
| New step | `editor` at sort_order 4 |
| Model | Content research model |
| Temperature | 0.3 |
| Input | Everything (research, sources, tone, framework, profile) |
| Output | Structured outline via `submit_editorial_outline` tool |
| Writer change | Receives outline instead of raw research/sources |
| Writer model | Copywriting model (unchanged, but smaller context now) |
| Migration | Add `editor` to step_type CHECK constraint |
| Template | Add `editor` label to `stepTypeLabel` |
| JS changes | None |
| Rejection | Writer-only re-run (same as current) |
| Source collection | Extract shared helper, avoid duplication |
