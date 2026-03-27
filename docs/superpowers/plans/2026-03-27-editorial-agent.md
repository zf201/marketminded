# Editorial Agent Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an editorial agent between the tone analyzer and writer that digests all research into a structured outline, so the writer receives condensed input and focuses on prose.

**Architecture:** New `editor` step type at sort_order 4 (writer moves to 5). Editor receives all research/sources/tone/framework, outputs structured outline via `submit_editorial_outline` tool. Writer prompt rebuilt around the outline instead of raw research. Source collection extracted into shared helper.

**Tech Stack:** Go, templ templates, SQLite, vanilla JS

**Spec:** `docs/superpowers/specs/2026-03-27-editorial-agent-design.md`

---

### Task 1: Database Migration

**Files:**
- Create: `migrations/009_editor_step.sql`

- [ ] **Step 1: Create migration file**

Create `migrations/009_editor_step.sql` following the exact pattern of `migrations/008_tone_analyzer_step.sql`:

```sql
-- +goose Up
-- +goose NO TRANSACTION

PRAGMA foreign_keys = OFF;

CREATE TABLE pipeline_steps_new (
    id INTEGER PRIMARY KEY,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL CHECK(step_type IN ('research','brand_enricher','factcheck','tone_analyzer','editor','write','plan_waterfall')),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','running','completed','failed')),
    input TEXT NOT NULL DEFAULT '',
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO pipeline_steps_new SELECT * FROM pipeline_steps;

DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;

PRAGMA foreign_keys = ON;

-- +goose Down
-- +goose NO TRANSACTION

PRAGMA foreign_keys = OFF;

CREATE TABLE pipeline_steps_new (
    id INTEGER PRIMARY KEY,
    pipeline_run_id INTEGER NOT NULL REFERENCES pipeline_runs(id) ON DELETE CASCADE,
    step_type TEXT NOT NULL CHECK(step_type IN ('research','brand_enricher','factcheck','tone_analyzer','write','plan_waterfall')),
    status TEXT NOT NULL DEFAULT 'pending'
        CHECK(status IN ('pending','running','completed','failed')),
    input TEXT NOT NULL DEFAULT '',
    output TEXT NOT NULL DEFAULT '',
    thinking TEXT NOT NULL DEFAULT '',
    sort_order INTEGER NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO pipeline_steps_new SELECT * FROM pipeline_steps WHERE step_type != 'editor';

DROP TABLE pipeline_steps;
ALTER TABLE pipeline_steps_new RENAME TO pipeline_steps;

PRAGMA foreign_keys = ON;
```

- [ ] **Step 2: Run migration**

Run: `make build && make restart`
Expected: App starts, migration applied without errors.

- [ ] **Step 3: Commit**

```bash
git add migrations/009_editor_step.sql
git commit -m "feat: add editor step type to pipeline_steps migration"
```

---

### Task 2: Extract Source Collection Helper + Add Editor Step Label

**Files:**
- Modify: `web/handlers/pipeline.go:886-925` (extract source collection from streamWrite)
- Modify: `web/templates/pipeline.templ:97-112` (add editor label)

- [ ] **Step 1: Extract collectSources helper**

In `web/handlers/pipeline.go`, add this helper function before `streamWrite` (around line 865, after the fact-checker section):

```go
// collectSources gathers deduplicated sources from all pipeline step outputs.
type pipelineSource struct {
	URL, Title, Summary, Date string
}

func collectSources(steps []store.PipelineStep) []pipelineSource {
	seen := map[string]bool{}
	var sources []pipelineSource
	for _, s := range steps {
		if s.Output == "" {
			continue
		}
		var parsed struct {
			Sources []struct {
				URL     string `json:"url"`
				Title   string `json:"title"`
				Summary string `json:"summary"`
				Date    string `json:"date"`
			} `json:"sources"`
		}
		if json.Unmarshal([]byte(s.Output), &parsed) == nil {
			for _, src := range parsed.Sources {
				if src.URL != "" && !seen[src.URL] {
					seen[src.URL] = true
					sources = append(sources, pipelineSource{src.URL, src.Title, src.Summary, src.Date})
				}
			}
		}
	}
	return sources
}

func formatSourcesText(sources []pipelineSource) string {
	if len(sources) == 0 {
		return ""
	}
	var b strings.Builder
	b.WriteString("\n## Sources (from research, brand analysis, and fact-checking)\n")
	for _, s := range sources {
		line := fmt.Sprintf("- [%s](%s): %s", s.Title, s.URL, s.Summary)
		if s.Date != "" {
			line += fmt.Sprintf(" (%s)", s.Date)
		}
		b.WriteString(line + "\n")
	}
	return b.String()
}
```

- [ ] **Step 2: Refactor streamWrite to use the helper**

In `streamWrite`, replace the source collection block (lines 886-925) — from `// Collect sources from ALL pipeline steps` through the closing `}` of the sourcesText builder — with:

```go
	steps, _ := h.queries.ListPipelineSteps(run.ID)
	allSources := collectSources(steps)
	sourcesText := formatSourcesText(allSources)
```

Also update the reference at line 958 from `sourcesText.String()` to just `sourcesText`:

```go
	systemPrompt += sourcesText
```

And remove the now-unused `source` type struct and related variables that were replaced.

- [ ] **Step 3: Add editor label to template**

In `web/templates/pipeline.templ`, add the editor case to `stepTypeLabel` (between `tone_analyzer` and `write`, around line 106):

```
	case "editor":
		Editor
```

- [ ] **Step 4: Build to verify**

Run: `make build`
Expected: Clean build.

- [ ] **Step 5: Commit**

```bash
git add web/handlers/pipeline.go web/templates/pipeline.templ web/templates/pipeline_templ.go
git commit -m "refactor: extract source collection helper, add editor step label"
```

---

### Task 3: Update Step Creation

**Files:**
- Modify: `web/handlers/pipeline.go:125-130` (create handler)

- [ ] **Step 1: Add editor step and update writer sort order**

In `web/handlers/pipeline.go`, replace lines 125-130:

```go
	// Create the three cornerstone agent steps
	h.queries.CreatePipelineStep(run.ID, "research", 0)
	h.queries.CreatePipelineStep(run.ID, "brand_enricher", 1)
	h.queries.CreatePipelineStep(run.ID, "factcheck", 2)
	h.queries.CreatePipelineStep(run.ID, "tone_analyzer", 3)
	h.queries.CreatePipelineStep(run.ID, "write", 4)
```

with:

```go
	// Create cornerstone agent steps
	h.queries.CreatePipelineStep(run.ID, "research", 0)
	h.queries.CreatePipelineStep(run.ID, "brand_enricher", 1)
	h.queries.CreatePipelineStep(run.ID, "factcheck", 2)
	h.queries.CreatePipelineStep(run.ID, "tone_analyzer", 3)
	h.queries.CreatePipelineStep(run.ID, "editor", 4)
	h.queries.CreatePipelineStep(run.ID, "write", 5)
```

**Note:** New pipeline runs created between this task and Task 4 will fail at the editor step. Complete Task 4 immediately after.

- [ ] **Step 2: Build to verify**

Run: `make build`
Expected: Clean build.

- [ ] **Step 3: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: add editor step to pipeline creation, writer moves to sort_order 5"
```

---

### Task 4: Implement Editorial Agent

**Files:**
- Modify: `web/handlers/pipeline.go` (add editorOutlineTool, streamEditor, and editor case in dispatcher)

- [ ] **Step 1: Add the editorial outline tool definition**

Add before line 865 (`// --- Writer agent ---`), with a `// --- Editor agent ---` section header. This keeps logical reading order in the file:

```go
// --- Editor agent ---

func (h *PipelineHandler) editorOutlineTool() ai.Tool {
	return ai.Tool{
		Type: "function",
		Function: ai.ToolFunction{
			Name:        "submit_editorial_outline",
			Description: "Submit the structured editorial outline for the writer. Call this when you have determined the narrative structure.",
			Parameters: json.RawMessage(`{
				"type": "object",
				"properties": {
					"angle": {
						"type": "string",
						"description": "The core narrative angle in one sentence"
					},
					"sections": {
						"type": "array",
						"description": "Ordered sections of the article",
						"items": {
							"type": "object",
							"properties": {
								"heading": {"type": "string", "description": "Suggested section heading"},
								"framework_beat": {"type": "string", "description": "Storytelling framework beat this maps to, if any"},
								"key_points": {
									"type": "array",
									"items": {"type": "string"},
									"description": "Specific points to make, with data/stats where relevant"
								},
								"sources_to_use": {
									"type": "array",
									"items": {"type": "string"},
									"description": "Source URLs that back the points in this section"
								},
								"editorial_notes": {"type": "string", "description": "Tone and approach guidance for this section"}
							},
							"required": ["heading", "key_points"]
						}
					},
					"conclusion_strategy": {
						"type": "string",
						"description": "How to close: what ties back, what CTA, what feeling to leave"
					}
				},
				"required": ["angle", "sections", "conclusion_strategy"]
			}`),
		},
	}
}
```

- [ ] **Step 2: Add streamEditor function**

Add after `editorOutlineTool`:

```go
func (h *PipelineHandler) streamEditor(w http.ResponseWriter, r *http.Request, projectID int64, stepID int64, run *store.PipelineRun, factcheckOutput string) {
	ok, err := h.queries.TrySetStepRunning(stepID)
	if err != nil || !ok {
		http.Error(w, "Step already running or completed", http.StatusConflict)
		return
	}

	// Parse factcheck output for enriched brief
	var factcheck struct {
		EnrichedBrief string `json:"enriched_brief"`
	}
	_ = json.Unmarshal([]byte(factcheckOutput), &factcheck)

	// Collect all sources
	steps, _ := h.queries.ListPipelineSteps(run.ID)
	allSources := collectSources(steps)
	sourcesText := formatSourcesText(allSources)

	// Build profile
	profile, _ := h.queries.BuildProfileStringExcluding(projectID, []string{"content_strategy"})

	// Build system prompt
	brief := factcheck.EnrichedBrief
	if brief == "" {
		brief = run.Brief
	}

	systemPrompt := fmt.Sprintf(`Today's date: %s

You are an editorial director. You receive research, sources, and brand context about a topic. Your job is to craft a structured editorial outline that a copywriter will use to write the final article.

Your job is narrative reasoning:
- Analyze the research and determine the strongest angle/hook
- Decide what facts to include, what to cut, and how to order them for maximum impact
- Build a logical throughline so the conclusion feels inevitable, not forced
- Specify which sources back which points
- Produce a tight outline the writer can execute without needing the raw research

Do NOT write the article. Produce only the structural outline via the tool.

## Client profile
%s

## Research brief
%s
%s`, time.Now().Format("January 2, 2006"), profile, brief, sourcesText)

	// Storytelling framework
	if fwKey, err := h.queries.GetProjectSetting(projectID, "storytelling_framework"); err == nil && fwKey != "" {
		if fw := content.FrameworkByKey(fwKey); fw != nil {
			systemPrompt += fmt.Sprintf("\n## Storytelling framework\nFramework: %s (%s)\n%s\nMap the framework beats to the article sections in your outline.\n", fw.Name, fw.Attribution, fw.PromptInstruction)
		}
	}

	// Tone guide
	for _, s := range steps {
		if s.StepType == "tone_analyzer" && s.Status == "completed" && s.Output != "" {
			var toneResult struct {
				ToneGuide string `json:"tone_guide"`
			}
			if json.Unmarshal([]byte(s.Output), &toneResult) == nil && toneResult.ToneGuide != "" {
				systemPrompt += "\n## Tone & style reference\nKeep this voice in mind when choosing the angle and editorial notes.\n\n"
				systemPrompt += toneResult.ToneGuide + "\n"
			}
			break
		}
	}

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Create the editorial outline now."},
	}

	flusher, sendEvent, sendChunk, sendThinking, sendDone, sendError := h.setupSSE(w)
	if flusher == nil {
		return
	}

	toolList := []ai.Tool{h.editorOutlineTool()}

	var thinkingBuf strings.Builder
	var savedOutput string

	executor := func(ctx context.Context, name, args string) (string, error) {
		if name == "submit_editorial_outline" {
			savedOutput = args
			h.queries.UpdatePipelineStepOutput(stepID, args, thinkingBuf.String())
			return "Editorial outline saved successfully.", ai.ErrToolDone
		}
		return "", fmt.Errorf("unknown tool: %s", name)
	}

	origSendThinking := sendThinking
	capturingSendThinking := func(chunk string) error {
		thinkingBuf.WriteString(chunk)
		return origSendThinking(chunk)
	}

	onToolEvent := h.buildToolEventCallback(sendEvent, 0)

	temp := 0.3
	_, err = h.aiClient.StreamWithTools(r.Context(), h.model(), aiMsgs, toolList, executor, onToolEvent, sendChunk, capturingSendThinking, &temp)
	if err != nil {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError(err.Error())
		return
	}

	if savedOutput == "" {
		h.queries.UpdatePipelineStepStatus(stepID, "failed")
		sendError("Editor did not submit outline via tool call. Try again.")
		return
	}

	h.queries.UpdatePipelineStepStatus(stepID, "completed")
	sendDone()
}
```

- [ ] **Step 3: Add editor case to streamStep dispatcher**

In the `streamStep` switch (around line 1110-1144), add the editor case between `tone_analyzer` and `write`:

```go
	case "editor":
		factcheckOutput, ok := findOutput("factcheck")
		if !ok {
			http.Error(w, "Factcheck step not completed yet", http.StatusConflict)
			return
		}
		h.streamEditor(w, r, projectID, stepID, run, factcheckOutput)
```

- [ ] **Step 4: Build to verify**

Run: `make build`
Expected: Clean build.

- [ ] **Step 5: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: implement editorial agent with submit_editorial_outline tool"
```

---

### Task 5: Rebuild Writer to Use Editorial Outline

**Files:**
- Modify: `web/handlers/pipeline.go` (streamWrite function and its dispatcher case)

- [ ] **Step 1: Change writer dispatcher to use editor output**

In the `streamStep` switch, change the `write` case from:

```go
	case "write":
		factcheckOutput, ok := findOutput("factcheck")
		if !ok {
			http.Error(w, "Factcheck step not completed yet", http.StatusConflict)
			return
		}
		h.streamWrite(w, r, projectID, stepID, run, factcheckOutput)
```

to:

```go
	case "write":
		editorOutput, ok := findOutput("editor")
		if !ok {
			http.Error(w, "Editor step not completed yet", http.StatusConflict)
			return
		}
		h.streamWrite(w, r, projectID, stepID, run, editorOutput)
```

- [ ] **Step 2: Rebuild streamWrite function signature and prompt**

Replace the `streamWrite` function signature and the prompt-building section (lines 867-991). The new function:

```go
func (h *PipelineHandler) streamWrite(w http.ResponseWriter, r *http.Request, projectID int64, stepID int64, run *store.PipelineRun, editorOutput string) {
	ok, err := h.queries.TrySetStepRunning(stepID)
	if err != nil || !ok {
		http.Error(w, "Step already running or completed", http.StatusConflict)
		return
	}

	// Defaults for cornerstone
	platform := "blog"
	format := "post"

	ct, ctOk := content.LookupType(platform, format)
	var promptText string
	if ctOk {
		promptText, _ = content.LoadPrompt(ct.PromptFile)
	}
	if promptText == "" {
		promptText = fmt.Sprintf("You are writing a %s %s.", platform, format)
	}

	profile, _ := h.queries.BuildProfileStringExcluding(projectID, []string{"content_strategy"})

	systemPrompt := fmt.Sprintf("Today's date: %s\n\n%s\n\n## Client profile\n%s\n",
		time.Now().Format("January 2, 2006"), promptText, profile)

	// Editorial outline is the primary input
	systemPrompt += fmt.Sprintf("\n## Editorial outline\nFollow this outline closely. It defines the angle, structure, and key points. Your job is to write compelling prose that brings this outline to life.\n\n%s\n", editorOutput)

	// Check for rejected cornerstone piece — include rejection reason for re-runs
	pieces, _ := h.queries.ListContentByPipelineRun(run.ID)
	for _, p := range pieces {
		if p.ParentID == nil && p.Status == "rejected" && p.RejectionReason != "" {
			systemPrompt += fmt.Sprintf("\n## Previous rejection feedback\n%s. Address this in the new version.\n", p.RejectionReason)
			break
		}
	}

	// Inject tone reference from tone_analyzer step (if it ran)
	steps, _ := h.queries.ListPipelineSteps(run.ID)
	for _, s := range steps {
		if s.StepType == "tone_analyzer" && s.Status == "completed" && s.Output != "" {
			var toneResult struct {
				ToneGuide string `json:"tone_guide"`
				Posts     []struct {
					Title string `json:"title"`
					URL   string `json:"url"`
				} `json:"posts"`
			}
			if json.Unmarshal([]byte(s.Output), &toneResult) == nil && toneResult.ToneGuide != "" {
				systemPrompt += "\n## Tone & style reference (from company blog)\nUse this ONLY to match the writing tone, voice, and style. Do NOT use any factual information from the blog posts — all facts must come from the editorial outline above.\n\n"
				systemPrompt += toneResult.ToneGuide + "\n"
			}
			break
		}
	}

	systemPrompt += antiAIRules

	aiMsgs := []types.Message{
		{Role: "system", Content: systemPrompt},
		{Role: "user", Content: "Write the cornerstone blog post now."},
	}
```

Keep everything after `aiMsgs` (the SSE setup, tool list, executor, etc.) unchanged — that's the write tool execution logic which stays the same.

- [ ] **Step 3: Build to verify**

Run: `make build`
Expected: Clean build.

- [ ] **Step 4: Smoke test**

Run: `make restart`

Create a new pipeline run and verify:
1. Six step cards appear (Researcher, Brand Enricher, Fact-Checker, Tone Analyzer, Editor, Writer)
2. Steps auto-chain through all 6
3. Editor produces a structured outline (visible in step output)
4. Writer produces the article based on the outline
5. Approve/reject still works

- [ ] **Step 5: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: rebuild writer to use editorial outline instead of raw research"
```

---

### Task 6: Final Verification

**Files:** None (verification only)

- [ ] **Step 1: Full build**

Run: `make build`
Expected: Clean build, no errors.

- [ ] **Step 2: Restart and end-to-end test**

Run: `make restart`

Run a complete pipeline:
1. All 6 steps complete successfully
2. Editor outline is visible and well-structured
3. Writer article follows the outline
4. Rejection re-runs only the writer (not the editor)
5. Existing old pipeline runs (5 steps) still render correctly

- [ ] **Step 3: Verify model usage**

Check that:
- Editor uses the content research model (same as researcher, fact-checker)
- Writer uses the copywriting model
- No model regression for other agents
