# Structured claims pipeline redesign

**Date:** 2026-04-07
**Status:** Approved, ready for implementation plan

## Motivation

The current pipeline (`research → brand_enricher → factcheck → editor → writer`) has two related weaknesses:

1. **The researcher emits a freeform `brief` blob.** Verifiable facts (stats, dates, prices, quotes) are dissolved into prose with no machine-readable link to the sources that back them. Downstream steps cannot tell which sentence rests on which source.
2. **The fact-checker exists to patch over (1).** Because the brief is unstructured, fact-checking has to re-parse the prose, guess at high-risk claims, and rewrite the whole brief. It is an expensive extra LLM call doing the work that the researcher should have done structurally on the first pass.

This redesign fixes the data structure at the source: the researcher emits explicit `claims[]` with stable IDs and source attribution. Once claims are first-class, fact-checking can be repurposed into a leaner "claim verifier" that targets specific high-risk claims by ID — and it can be made optional, since the structural fix already covers most of what fact-checking was protecting against.

The downstream steps (editor, writer) become more rigorous: the editor builds outlines that reference specific claim IDs per section, and the writer is hard-constrained to ground every fact in a claim from the array. No claim, no fact in the article.

## Goals

- Replace freeform research output with structured `claims[]` carrying stable IDs and source attribution.
- Make brand enrichment additive: it appends brand claims, never mutates research claims.
- Repurpose `factcheck` into a `claim_verifier` step that operates on the structured claims array, gated by a team setting (default off).
- Force editor outlines and writer prose to be grounded in specific claims by ID.
- Keep the overall pipeline shape; no new step types beyond the rename.

## Non-goals

- Per-paragraph claim citation in the writer's output schema. The hard rule lives in the prompt; we trust the model + prompt for v1.
- Automated post-write claim-coverage validation. YAGNI until the prompt-based rule proves insufficient.
- The cornerstone/waterfall split (tracked separately).
- Changes to `topic_explore` / `topic_review`.

## Architecture

The pipeline shape stays the same; only the data flowing through it changes:

```
research → brand_enricher → [claim_verifier?] → editor → writer
```

- **research** emits structured `claims[]` with stable IDs (`c1`, `c2`, …) alongside `sources[]` (now also with IDs `s1`, `s2`, …) and a freeform narrative `brief`.
- **brand_enricher** receives the researcher payload and *appends* brand claims to the same `claims[]` array using IDs that continue the sequence. It also appends brand sources. It does not mutate, delete, or renumber existing claims or sources, and it rewrites `enriched_brief` for narrative direction only.
- **claim_verifier** (renamed from `factcheck`) is gated by team setting `claim_verifier_enabled` (default `false`). When disabled, the pipeline runner omits the step entirely from the run. When enabled, it picks the 3–5 highest-risk claims (by type), spot-checks them with web search, and emits a patched claims array plus a list of per-claim verdicts for audit display in the UI. Source URLs and claim IDs are preserved across verification.
- **editor** consumes claims by ID. Its outline references `claim_ids[]` per section instead of source URLs. Claims are formatted into the prompt as a labeled block; the brief is still passed in for narrative direction.
- **writer** receives the editorial outline plus the full claims array and is hard-constrained by prompt: every concrete fact in the article must come from a claim in the array.

**Helper for claim provenance:** the editor and writer steps need to read "the most recent claims array in the run." Implement a small helper that walks `[claim_verifier, brand_enricher, research]` and returns the first claims array found in `PriorOutputs`. This avoids hardcoding the step graph in each step.

## Data structures

### Researcher output (`submit_research` tool schema)

```json
{
  "sources": [
    {
      "id": "s1",
      "url": "https://...",
      "title": "...",
      "summary": "What this source contributes",
      "date": "2026-03-15"
    }
  ],
  "claims": [
    {
      "id": "c1",
      "text": "The average 30-year fixed mortgage rate reached 6.8% in March 2026.",
      "type": "stat",
      "source_ids": ["s1", "s4"]
    }
  ],
  "brief": "Freeform narrative direction: what's the story, what's surprising, what angles a writer should consider. 3–6 sentences. Facts live in claims, not here."
}
```

**Field rules:**

- `sources[].id` — assigned by the model, format `s1`, `s2`, …, stable across the pipeline.
- `claims[].id` — assigned by the model, format `c1`, `c2`, …, stable across the pipeline.
- `claims[].type` — enum: `stat | quote | fact | date | price`. Used by the verifier to pick high-risk candidates (`stat | date | price` are highest risk by default).
- `claims[].source_ids` — array of source IDs from the same payload. At least one required. Multi-source = stronger.
- `claims[].text` — single declarative sentence stating one verifiable fact. Not a paragraph, not an opinion.
- `brief` — narrative direction only, kept short. The prompt explicitly says: do not repeat facts here, those belong in `claims[]`.
- Target volume: 8–15 claims per research run. Quality over quantity.

### Brand enricher output (`submit_brand_enrichment` tool schema)

Mirrors researcher output:

```json
{
  "claims": [ /* full merged array: original research claims unchanged + new brand claims appended */ ],
  "sources": [ /* full merged array: original sources unchanged + new brand sources appended */ ],
  "enriched_brief": "Updated narrative direction weaving the brand into the story. Still short, still narrative."
}
```

**Field rules:**

- New brand claim IDs continue the sequence (if last research claim was `c12`, first brand claim is `c13`).
- New brand source IDs continue the source sequence.
- Original claims and sources are immutable: same IDs, same text, same `source_ids`.
- Brand claims look like: `"AcmeCorp's Premium plan is priced at $49/month."` or `"AcmeCorp offers a 30-day free trial on all plans."`
- Validation: all `source_ids` referenced by claims must exist in `sources[]`. Hard error on broken refs.

### Claim verifier output (`submit_claim_verification` tool schema)

```json
{
  "verified_claims": [
    { "id": "c3",  "verdict": "confirmed",    "note": "matches Fed data 2026-03-15" },
    { "id": "c7",  "verdict": "corrected",    "corrected_text": "...", "note": "original said 6.8%, source says 6.7%" },
    { "id": "c11", "verdict": "unverifiable", "note": "no public source within budget" }
  ],
  "claims":  [ /* full patched claims array — corrections applied in place, IDs preserved */ ],
  "sources": [ /* full sources array — any new verifier sources appended with new IDs */ ]
}
```

**Field rules:**

- `verdict` enum: `confirmed | corrected | unverifiable`.
- `corrected_text` is required when `verdict == "corrected"`.
- The patched `claims` array is the source of truth for downstream steps. `verified_claims` is audit metadata stored on the step result for the UI to render.
- The verifier MAY append new sources (e.g., a Fed page it pulled) with new IDs continuing the source sequence.
- The verifier MAY NOT add new claims, delete claims, renumber claim IDs, modify existing source entries, or delete sources.

### Editor output (`submit_editorial_outline` tool schema)

One field changes per section: `sources_to_use[]` (URLs) becomes `claim_ids[]`.

```json
{
  "angle": "...",
  "sections": [
    {
      "heading": "...",
      "framework_beat": "...",
      "key_points": ["..."],
      "claim_ids": ["c3", "c7", "c11"],
      "editorial_notes": "..."
    }
  ],
  "conclusion_strategy": "..."
}
```

**Validation:** every entry in `claim_ids` must exist in the input claims array. Hard error on broken refs.

### Writer

The writer's existing content-type tool (`submit_blog_post` etc.) is unchanged. The hard rule lives in the writer prompt.

## Validation

A small validation helper runs after each step that emits claims (`research`, `brand_enricher`, `claim_verifier`):

- All `claims[].source_ids` must reference existing source IDs in the same payload.
- All claim IDs must be unique within the payload.
- All source IDs must be unique within the payload.
- `brand_enricher` and `claim_verifier`: all IDs from the prior step's claims/sources must still be present (no deletion or renumbering).
- `claim_verifier` only: every `verified_claims[].id` must reference an existing claim.

A validation failure errors the step out, same path as a missing tool call. This catches sloppy model output before it poisons downstream steps.

## Prompt changes

### Researcher prompt (`ForResearch`)

Add an "Output structure: claims first" section before the workflow rules:

> Your job isn't to write a research essay. It's to extract **factual claims** with source attribution, then add brief narrative context.
>
> A claim is a single declarative sentence stating one verifiable fact: a statistic, a quote, a date, a price, a named-entity assertion. Examples:
>
> - "The average 30-year fixed mortgage rate reached 6.8% in March 2026."
> - "Anthropic released Claude 4.6 Opus on April 1, 2026."
> - "Zillow reports 23% of US homes sold above asking in Q1 2026."
>
> Bad claims (don't do this):
>
> - "Mortgage rates have been rising and this affects buyers in many ways." (vague, multi-fact, opinion)
> - "Anthropic is a leading AI company." (opinion, not verifiable)
>
> Every claim must cite at least one source by `id`. Prefer multi-source claims when you have them.
>
> The `brief` field is for **narrative direction only**: what's the story, what's surprising, what angles a writer should consider. It is NOT a place to repeat facts — those belong in `claims[]`. Keep it short (3–6 sentences).
>
> Aim for **8–15 claims** total. Quality over quantity.

The existing "what to look for" section is retuned: "Hunt for verifiable atoms: numbers, dates, named entities, direct quotes." Workflow and tool-call rules stay.

### Brand enricher prompt (`ForBrandEnricher`)

Add a section explaining the append-only contract:

> You receive a claims array and sources from the researcher. Your job is to **append brand-specific claims** based on the brand URLs you fetch.
>
> Rules:
>
> - Do NOT modify, delete, or renumber existing claims. They are immutable.
> - Append new claims with IDs continuing the sequence (if the last research claim was `c12`, your first claim is `c13`).
> - Append new sources with IDs continuing the source sequence.
> - Brand claims look like: "AcmeCorp's Premium plan is priced at $49/month." or "AcmeCorp offers a 30-day free trial on all plans."
> - The `enriched_brief` field updates the narrative direction to weave the brand into the story. Still short, still narrative — facts live in claims.

### Claim verifier prompt (`ForClaimVerifier`, replaces `ForFactcheck`)

> You are a claim verifier. You receive a structured claims array and sources from the brand enrichment step. Spot-check the highest-risk claims and submit a verification result.
>
> Selection: pick the **3–5 highest-risk claims** by type. `stat | date | price` are highest risk; verify these first. Ignore opinions, generalities, and well-sourced facts.
>
> One focused web search per claim. Do not exceed the search cap.
>
> For each verified claim, emit a verdict: `confirmed`, `corrected` (with `corrected_text`), or `unverifiable`.
>
> Output the patched claims array with corrections applied in place. Preserve all claim IDs. You may append new sources that you used during verification, but do not modify or remove existing sources, and do not add or remove claims.

### Editor prompt (`ForEditor`)

Replace the `sourcesText` block with a labeled claims block, formatted one line per claim:

```
[c1] (stat)  "The average 30-year fixed mortgage rate reached 6.8% in March 2026."  → s1, s4
[c2] (date)  "Anthropic released Claude 4.6 Opus on April 1, 2026."                 → s2
...
```

Add a section explaining the contract:

> You will reference claims by ID in your outline. Every section's `claim_ids[]` lists exactly which claims it leans on. The writer reads only those claims for that section, plus your editorial guidance. Pick claims deliberately — a section with weak claim coverage is a section that will be weak prose.

The brief is still passed in for narrative direction.

### Writer prompt (`ForWriter`)

Add a hard factual-grounding rule:

> **Factual grounding is non-negotiable.** Every statistic, percentage, dollar amount, date, named entity, and direct quote in your article MUST come from the claims array. If a claim isn't there, you don't write it. Do not invent, estimate, or "round up." If you feel a section needs a fact you don't have, leave it out and lean on the angle instead. We are publishing journalism, not opinion.

The writer also receives the full claims array (formatted the same way as the editor's block) plus the editorial outline. The outline tells the writer which claims belong to which section.

## Settings and gating

New team setting:

- Key: `claim_verifier_enabled`
- Type: boolean
- Default: `false`
- UI: checkbox on the team settings page (`web/templates/settings.templ`, handler in `web/handlers/settings.go`)

The pipeline runner reads this setting when constructing the step list for a run. When `false`, the `claim_verifier` step is **omitted from the run entirely** (not added then skipped), so the editor's "most recent claims" lookup naturally falls back to `brand_enricher` output.

## Rename: `factcheck` → `claim_verifier`

The conceptual shift is large enough to justify renaming the step type in code, prompts, and DB.

**Migration:**

```sql
UPDATE pipeline_steps SET type = 'claim_verifier' WHERE type = 'factcheck';
INSERT OR IGNORE INTO settings (key, value) VALUES ('claim_verifier_enabled', 'false');
```

Existing completed `factcheck` step rows are renamed in place. Their stored output JSON is in the old shape (no `claims[]`, freeform `enriched_brief`), but they're already-completed runs that are never re-read as input by code — only displayed in the UI, which is forgiving of missing fields.

## Code touch list

- `internal/pipeline/steps/factcheck.go` → rename to `claim_verifier.go`, update step type string
- `internal/pipeline/steps/research.go` → no code change beyond passing through structured output
- `internal/pipeline/steps/brand_enricher.go` → reads prior claims, no major code change
- `internal/pipeline/steps/editor.go` → use "most recent claims" helper, format claims block
- `internal/pipeline/steps/writer.go` → reads claims, passes them into prompt
- `internal/pipeline/steps/common.go` → helper for "most recent claims array in PriorOutputs"; helper for formatting a claims block; validation helper for claim/source ref integrity
- `internal/prompt/builder.go` → all five `For*` methods updated; `ForFactcheck` renamed to `ForClaimVerifier`
- `internal/tools/registry.go` → updated schemas for `submit_research`, `submit_brand_enrichment`, `submit_claim_verification` (renamed), `submit_editorial_outline`
- `internal/store/migrations/` → new migration for type rename + settings default
- `web/templates/settings.templ` + `_templ.go` + `web/handlers/settings.go` → new toggle
- `web/templates/topic.templ` + `_templ.go` → if it displays the step name, update label

## Rollout

Single feature branch, squash merge to `main`, per usual flow. No feature flag beyond the team setting itself. The migration handles existing data on startup.

## Open risks

- **Model compliance with structured claims.** A weaker model may dump everything into the `brief` and emit thin or missing `claims[]`. Mitigation: the prompt is explicit, validation rejects empty claims arrays, and we can add a minimum-claim-count check (`>= 5`) if needed.
- **Editor referencing claims that don't exist.** Validation hard-errors the step. Forces a re-run, but catches the bug early.
- **Writer ignoring the hard grounding rule.** No programmatic check in v1. If this proves to be a real failure mode in practice, follow-up work can add a post-write claim-coverage check (LLM or otherwise). Out of scope for now.
- **Claim ID collisions across steps.** Each step is told to continue the sequence from the highest existing ID. Validation catches duplicates if the model gets it wrong.
