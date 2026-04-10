# Audience Picker and Style Reference Pipeline Steps

## Overview

Two new pipeline steps that distil the information the writer receives, with the explicit goal of making a good blog post all but guaranteed:

1. **Audience picker** — runs after `research`, before `brand_enricher`. Picks which persona the post addresses (or picks an "off" mode when no persona fits). Prevents the failure mode of recommending the wrong SKU, plan, or product class to the wrong reader (e.g. cheapest knife to a pro chef, 50-seat team plan to a freelancer, city car to a construction company).
2. **Style reference** — runs after `editor`, before `write`. Fetches the brand's blog, reads 3-5 candidate posts, and hands 2-3 of the best-written ones verbatim to the writer as voice reference. Goal: the new post should be indistinguishable from the brand's existing posts.

Both steps are conditional on their inputs existing. They are skipped at run-creation time when the project has no personas (audience picker) or no `blog_url` project setting (style reference). Skipped steps show a muted row in the pipeline UI with a hint telling the user what to set to enable the step.

## Pipeline Shape

New step order:

```
research
  → audience_picker   [conditional: requires ≥1 persona]
  → brand_enricher
  → [claim_verifier]
  → editor
  → style_reference   [conditional: requires blog_url setting]
  → write
```

### Dependency map

`StepDependencies()` in `internal/pipeline/orchestrator.go`:

```go
"research":        {},
"audience_picker": {"research"},
"brand_enricher":  {"research"},
"claim_verifier":  {"brand_enricher"},
"editor":          {"brand_enricher"},
"style_reference": {"editor"},
"write":           {"editor"},
```

`brand_enricher` does **not** statically depend on `audience_picker`. Instead, `RunStep` applies a dynamic-dependency check: when it sees that the run includes an `audience_picker` step, it appends that step to `brand_enricher`'s required list. This mirrors the existing `editor → claim_verifier` pattern in `orchestrator.go`.

Same trick for `write → style_reference`.

The payoff: both new steps stay fully optional. A project with no personas and no `blog_url` runs the exact same pipeline as today, with no new failure modes and no config changes.

### Run-creation scheduling

The code that builds the step list for a new run gains two conditionals:

- Insert `audience_picker` only if `len(ListAudiencePersonas(projectID)) > 0`.
- Insert `style_reference` only if `GetProjectSetting(projectID, "blog_url") != ""`.

The exact location will be identified during implementation planning (likely `internal/pipeline/runner.go` or the handler that creates runs).

### UI hint for skipped steps

When a step is skipped at scheduling time, the pipeline page renders a muted row in its slot (not a full step card, no output panel) with one of:

- *"Audience step skipped — add personas on the profile page to enable."*
- *"Style reference skipped — set a blog URL in project settings to enable."*

This keeps the capability discoverable without adding configuration surface.

## Audience Picker Step

### Responsibility

Read the topic, brief, profile, research output, and all personas. Decide who the post addresses. The decision and supporting writer guidance flow into `brand_enricher`, `editor`, and `write`.

### Inputs

From `StepInput`:

- `Topic`, `Brief`
- `Profile` (contains the persona block already, useful as narrative context)
- `PriorOutputs["research"]` — research JSON, so the step understands what the topic is actually about

The step additionally fetches `ListAudiencePersonas(projectID)` directly so it has structured persona records with `id` fields. The profile string is prose and cannot be used to return a specific persona id.

### Tool: `submit_audience_selection`

```json
{
  "type": "object",
  "properties": {
    "mode": {
      "type": "string",
      "enum": ["persona", "educational", "commentary"]
    },
    "persona_id": {
      "type": ["integer", "null"],
      "description": "Required when mode=persona, must match an existing persona id. Null otherwise."
    },
    "reasoning": {
      "type": "string",
      "description": "1-3 sentences: why this target, and which competing options were rejected and why."
    },
    "guidance_for_writer": {
      "type": "string",
      "description": "2-4 sentences of concrete guidance downstream steps should honor: what to emphasize, what to avoid recommending, what register to use."
    }
  },
  "required": ["mode", "reasoning", "guidance_for_writer"]
}
```

Tool executor validation:

- `mode=persona` → `persona_id` must be non-null and exist in the fetched personas list. Otherwise the step fails with a descriptive error.
- `mode=educational` or `commentary` → `persona_id` must be null.
- `guidance_for_writer` must be non-empty.

### Mode semantics

- **`persona`** — default and preferred. The post addresses a specific persona. The writer speaks in their language, to their pain points, recommending offerings appropriate for them.
- **`educational`** — no persona. Reference-style or how-it-works pieces that teach the category rather than selling. Writer addresses "someone learning the topic."
- **`commentary`** — no persona. Industry reactions, news commentary, trend pieces. Writer addresses "an informed reader of this space."

The prompt biases strongly toward `persona`. An off-mode is only picked when no persona genuinely fits the topic.

### Step output (stored in `pipeline_steps.output` as JSON)

```json
{
  "mode": "persona",
  "persona_id": 7,
  "persona_label": "Professional chef",
  "persona_summary": "Short block copied from the persona row covering description, pain points, push, pull, anxiety, habit, plus role/company if present.",
  "reasoning": "...",
  "guidance_for_writer": "..."
}
```

For `educational` / `commentary`, `persona_id`, `persona_label`, and `persona_summary` are omitted.

### System prompt (`Builder.ForAudiencePicker`)

New method in `internal/prompt/builder.go`. Structure:

1. Date header.
2. Role: *"You are an audience strategist. Your job is to decide which reader this post is for, so downstream steps can tailor product recommendations, framing, and voice to that reader."*
3. Topic and brief.
4. Client profile (the persona block inside the profile may repeat content from the structured list below — this is fine, the structured version is what the model selects from).
5. Research output (claims and narrative brief) so the model knows what the topic really is.
6. Numbered list of personas, each with `id`, `label`, and the full field set (`description`, `pain_points`, `push`, `pull`, `anxiety`, `habit`, `role`, `demographics`, `company_info`, `content_habits`, `buying_triggers`) for every field that is populated.
7. Decision rules with concrete anti-examples:
    - "If the post is about the cheapest knife in the lineup, do not target a persona like 'Professional chef.' Pick a value-buyer persona, or `educational`."
    - "If it's a 50-seat team plan, do not target 'Freelancer.' Pick the relevant team-size persona, or `commentary`."
    - "If it's a city car and your only buyer persona is 'Construction company,' pick `educational` or `commentary` — do not force a bad match."
8. Mandatory-constraint rule: when the topic involves a product recommendation, `guidance_for_writer` must include at least one explicit "do not recommend X to Y" constraint. This is the failure-prevention mechanism.
9. Mandatory-tool-call rule (standard pattern — a response without a `submit_audience_selection` call is treated as failure).

Temperature: 0.2.

### Downstream consumption

Three prompt builders gain an optional `audienceBlock` argument. All three skip the section when the block is empty, matching the existing optional-argument pattern used for `rejectionReason`.

- **`ForBrandEnricher(profile, researchOutput, urlList, audienceBlock)`** — inserts `audienceBlock` as a `## Audience target` section before the research output. Adds an instruction line: *"When enriching with brand claims, prefer products, plans, and SKUs appropriate for this audience. Do not add claims about offerings that clearly mismatch the target."*
- **`ForEditor(profile, brief, claimsBlock, frameworkBlock, audienceBlock)`** — inserts `audienceBlock` after the narrative brief with: *"The angle and claim selection must serve this audience."*
- **`ForWriter(promptFile, profile, editorOutput, claimsBlock, rejectionReason, audienceBlock, styleReferenceBlock)`** — inserts `audienceBlock` above the claims block with: *"This post addresses the audience below. Honor the writer guidance literally."*

The `audienceBlock` is built by a new helper `pipeline.FormatAudienceBlock(sel *AudienceSelection) string` that returns `""` for `nil`. Each step runner parses `PriorOutputs["audience_picker"]` via `pipeline.ParseAudienceSelection`, and passes either the formatted block or `""` to the prompt builder.

### UI

Standard step card. Thinking stream flows normally. The step output panel renders as:

- Mode badge (`persona` / `educational` / `commentary`)
- Persona label, when present
- `reasoning` paragraph
- `guidance_for_writer` paragraph in a highlighted box

No new UI components required.

## Style Reference Step

### Responsibility

Given the brand's blog URL, find the 2-3 best-written posts on the site and hand them to the writer verbatim as voice reference. Quality-first, topic-agnostic.

### Inputs

- `blog_url` from `GetProjectSetting(projectID, "blog_url")` (guaranteed to exist — otherwise the step was not scheduled)
- `input.Topic` — passed to the model only for context, so it can skip a post that would actively mislead (e.g. a legal disclaimer page). **Topic is not a selection criterion.**

The step does not read profile, claims, or editor output. It cares about style, not substance.

### Tools

- **`fetch_url`** — reused from `internal/tools/fetch.go`. No changes.
- **`submit_style_reference`** — new, single mandatory submission tool.

No web search. The agent has exactly one source of truth: the brand's own blog.

### `submit_style_reference` schema

```json
{
  "type": "object",
  "properties": {
    "examples": {
      "type": "array",
      "minItems": 2,
      "maxItems": 3,
      "items": {
        "type": "object",
        "properties": {
          "url": { "type": "string" },
          "title": { "type": "string" },
          "body": { "type": "string", "description": "Full post body verbatim, no summarization, no edits." },
          "why_chosen": { "type": "string", "description": "One sentence on what makes this post a strong style exemplar." }
        },
        "required": ["url", "title", "body", "why_chosen"]
      }
    },
    "reasoning": { "type": "string", "description": "Brief note on how the candidates were narrowed down." }
  },
  "required": ["examples", "reasoning"]
}
```

Tool executor validation:

- Reject any submission where `examples` contains fewer than 2 or more than 3 entries.
- Reject any `body` shorter than 400 characters. This catches the failure mode of the model returning a truncated excerpt instead of the full post.
- Every `url` must be one the agent previously fetched during this step (tracked in the step runner).

### System prompt (`Builder.ForStyleReference`)

New method in `internal/prompt/builder.go`. Structure:

1. Date header.
2. Role: *"You are a style scout. Your job is to pick the 2-3 highest-quality posts from this brand's blog and return them verbatim so a writer can imitate the house voice."*
3. Blog URL.
4. Topic one-liner (context only, not a selection criterion).
5. Workflow:
    1. Fetch the blog index at the blog URL with `fetch_url`.
    2. Extract post URLs from the index. Cap the candidate set at ~10.
    3. Pick 3-5 that look most promising from title or preview alone. Fetch each with `fetch_url`.
    4. Read the fetched posts. Pick the best 2-3 by writing quality: voice, rhythm, structure, specificity, a distinctive point of view. **Ignore topic match.**
    5. Call `submit_style_reference` with those 2-3 posts' full body text verbatim.
6. Hard rules:
    - **Do not summarize, rewrite, shorten, or "clean up" post bodies.** Whatever text came back from `fetch_url` for the chosen post is what goes into `body`. This step's value depends entirely on the writer seeing real house-voice sentences. Paraphrasing destroys the signal.
    - Do not invent URLs. Only posts fetched in this step are eligible.
    - If the index has fewer than 2 viable posts, fetch what exists and submit with 2 if possible. If fewer than 2 exist, fail explicitly rather than padding with low-quality posts.
7. Mandatory-tool-call rule.

Temperature: 0.2. Max iterations: 8 (1 index fetch + up to 5 post fetches + submission, with headroom).

Fetch budget constant: `tools.StyleReferenceMaxFetches = 6`, declared alongside the existing `tools.ResearchSearchCap` etc. The step runner enforces it.

### Step output

The JSON from `submit_style_reference` stored as-is in `pipeline_steps.output`.

### Writer prompt integration

`ForWriter` gains an optional `styleReferenceBlock` string. When non-empty, it is inserted after the claims block and before the anti-AI rules, shaped as:

```markdown
## Style reference — match this voice
The following posts are real, previously published pieces from this brand's blog. They are the ground truth for how this brand sounds. When writing the new post below, match their rhythm, sentence length, opener patterns, register, and overall feel. The reader should not be able to tell which post was written by AI.

Do NOT copy sentences, facts, or structure from these examples. They are voice reference only. The new post's content comes from the claims block above.

### Example 1: {title}
{full body verbatim}

### Example 2: {title}
{full body verbatim}
```

The "do not copy" instruction is load-bearing. Without it, models will sometimes lift phrases straight from the examples, which is both plagiarism and a factual-grounding violation (those facts are not in the claims block).

The block is built by a new helper `pipeline.FormatStyleReferenceBlock(ref *StyleReference) string` that returns `""` for `nil`.

### Context cost note

Two full blog posts can run 3-4k tokens each. Combined with claims, editorial outline, profile, and anti-AI rules, the writer prompt gets heavy. This is acceptable — the project is BYOK, and spending context on the thing that most determines output quality (voice) is exactly the intended tradeoff.

### UI

Standard step card. Output renders as 2-3 collapsible accordions showing `title`, `url`, and `why_chosen`. Full body is hidden behind an expand toggle to keep the card readable.

## Data, Config, and Wiring

### No database changes

No new tables, no new migrations, no new columns. Both steps read existing state:

- Audience picker reads `audience_personas` (existing table) and writes JSON to `pipeline_steps.output` (existing column).
- Style reference reads `project_settings.blog_url` (existing setting) and writes JSON to `pipeline_steps.output`.

### New files

- `internal/pipeline/steps/audience_picker.go` — `AudiencePickerStep` struct implementing `StepRunner`. Shape mirrors `editor.go`: single mandatory tool, no content streaming.
- `internal/pipeline/steps/style_reference.go` — `StyleReferenceStep` struct implementing `StepRunner`. Shape mirrors `research.go` / `brand_enricher.go`: tool loop with `fetch_url` + submission tool.
- `internal/pipeline/audience.go` — `AudienceSelection` struct, `ParseAudienceSelection`, `FormatAudienceBlock`.
- `internal/pipeline/style_reference.go` — `StyleReference` struct, `ParseStyleReference`, `FormatStyleReferenceBlock`.
- `internal/pipeline/audience_test.go` — parser and formatter tests.
- `internal/pipeline/style_reference_test.go` — parser and formatter tests.
- `internal/tools/audience.go` — `submit_audience_selection` tool definition and registration.
- `internal/tools/style_reference.go` — `submit_style_reference` tool definition and registration, plus the `StyleReferenceMaxFetches` constant.

### Modified files

- `internal/pipeline/orchestrator.go` — two entries in `StepDependencies()`; dynamic-dep checks for `brand_enricher → audience_picker` and `write → style_reference`.
- `internal/prompt/builder.go`:
    - New `ForAudiencePicker(topic, brief, profile, researchOutput, personasStructured string)` method.
    - New `ForStyleReference(blogURL, topic string)` method.
    - Extended `ForBrandEnricher` signature to accept `audienceBlock`.
    - Extended `ForEditor` signature to accept `audienceBlock`.
    - Extended `ForWriter` signature to accept `audienceBlock` and `styleReferenceBlock`.
- `internal/prompt/builder_test.go` — smoke tests for the new methods and the new optional arguments (see Testing section).
- `internal/pipeline/steps/brand_enricher.go` — parse `PriorOutputs["audience_picker"]`, build the block, pass to `ForBrandEnricher`.
- `internal/pipeline/steps/editor.go` — same pattern.
- `internal/pipeline/steps/writer.go` — same pattern, plus parse `PriorOutputs["style_reference"]` and pass `styleReferenceBlock` to `ForWriter`.
- Run-scheduling code (exact file identified during implementation planning) — two conditional step inserts.
- `cmd/` — register `AudiencePickerStep` and `StyleReferenceStep` with the orchestrator in the startup wiring, alongside the existing step runners.
- `web/static/js/step-cards.js` — add `'audience_picker': 'Audience Picker'` and `'style_reference': 'Style Reference'` labels.
- `web/templates/pipeline.templ` (or equivalent) — render the two new step outputs and the skipped-step muted row.

### Configuration

- `tools.StyleReferenceMaxFetches = 6` — the only new constant. No new env vars, no new settings.
- Both steps use temperature `0.2`, declared inline in the step runners.

## Testing

### Parser and formatter unit tests

`internal/pipeline/audience_test.go`:

- `ParseAudienceSelection` happy paths for `mode=persona`, `educational`, `commentary`.
- `ParseAudienceSelection` rejects: missing `mode`; `mode=persona` with null `persona_id`; `mode=educational` or `commentary` with non-null `persona_id`; empty `guidance_for_writer`.
- `FormatAudienceBlock` returns `""` for a nil selection.
- `FormatAudienceBlock` for `mode=persona` includes the persona label and summary.
- `FormatAudienceBlock` for `educational` / `commentary` omits persona fields but includes the mode label and `guidance_for_writer`.

`internal/pipeline/style_reference_test.go`:

- `ParseStyleReference` happy path for 2 examples and 3 examples.
- `ParseStyleReference` rejects: fewer than 2 examples; more than 3 examples; any `body` under 400 characters; missing `url` / `title` / `body` / `why_chosen`.
- `FormatStyleReferenceBlock` returns `""` for a nil ref.
- `FormatStyleReferenceBlock` produces the full `## Style reference` block with the "do not copy" instruction and verbatim bodies.

### Prompt builder smoke tests

Extending `internal/prompt/builder_test.go`:

- `ForAudiencePicker` — call with realistic profile, structured personas, topic, research output. Assert the result contains: date header, each persona's label, at least one of the concrete anti-example phrases, the "do not recommend X to Y" rule, and the mandatory-tool-call rule.
- `ForStyleReference` — call with a topic and blog URL. Assert the result contains: the URL, the "verbatim, do not summarize" rule, and the mandatory-tool-call rule.
- `ForBrandEnricher` with an empty `audienceBlock` — assert the audience section is absent (regression guard on existing behavior).
- `ForBrandEnricher` with a populated `audienceBlock` — assert the audience section appears before the research output.
- `ForEditor` with and without `audienceBlock` — same assertions.
- `ForWriter` with empty `audienceBlock` and empty `styleReferenceBlock` — regression guard.
- `ForWriter` with a populated `styleReferenceBlock` — assert the "do not copy sentences, facts, or structure" line is present.

### Orchestrator tests

Extending `internal/pipeline/orchestrator_test.go`:

- Run with personas and `blog_url` set — both new steps are scheduled in the expected order.
- Run with no personas — `audience_picker` is not scheduled; `brand_enricher` runs without waiting for it.
- Run with no `blog_url` — `style_reference` is not scheduled; `write` runs without waiting for it.
- Run with both missing — the pipeline degrades to current behavior with no new steps.
- Run with `audience_picker` scheduled but failed — `brand_enricher` is blocked (dynamic dep works).
- Run with `style_reference` scheduled but failed — `write` is blocked.

### Step runner tests

New files in `internal/pipeline/steps/` (matching whatever runner-level test pattern exists):

- `audience_picker_test.go`:
    - Fake AI client returning `submit_audience_selection` with each mode. Assert the step output JSON matches expected shape.
    - `mode=persona` with a `persona_id` that doesn't exist in the project's personas — assert the step fails cleanly with a descriptive error.
    - `mode=persona` with null `persona_id` — same.
    - Empty `guidance_for_writer` — same.
- `style_reference_test.go`:
    - Fake AI client that calls `fetch_url` for an index then `fetch_url` for 3 posts then `submit_style_reference`. Assert the step output is correctly parsed.
    - Fake client returns a submission containing a `body` under 400 characters — assert the validator rejects and the step fails.
    - Fake client submits a `url` it did not fetch — assert rejection.
    - Fake client exceeds `StyleReferenceMaxFetches` — assert the step halts.

### Out of scope for tests

- LLM output quality (no unit test can judge "did it pick the right persona"). Manual verification on real runs during implementation.
- `fetch_url` behavior — covered by existing tool tests.

## Open Implementation Questions

These do not block the design but will be answered during planning:

- Exact location of the run-scheduling code that inserts steps (likely `internal/pipeline/runner.go` but needs confirmation).
- Whether any existing end-to-end pipeline test harness exists that will need updating beyond the orchestrator tests.
- Specific markup / styling for the skipped-step muted row in `pipeline.templ`.
