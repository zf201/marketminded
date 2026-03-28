# Waterfall Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove all waterfall phase code from the pipeline while keeping the cornerstone content pipeline and database schema untouched.

**Architecture:** Surgical deletion of waterfall routes, handlers, templates, JS, and prompt references. The cornerstone pipeline (researcher -> brand enricher -> fact-checker -> tone analyzer -> writer) remains unchanged. Database schema (phase column, parent_id, plan_waterfall step type) stays for future chat agent reuse.

**Tech Stack:** Go, templ templates, vanilla JS, SQLite

**Spec:** `docs/superpowers/specs/2026-03-26-waterfall-removal-design.md`

---

### Task 1: Remove Waterfall Routes and Handlers

**Files:**
- Modify: `web/handlers/pipeline.go:50-88` (route switch)
- Modify: `web/handlers/pipeline.go:1249-1250` (streamStep switch case)
- Delete: `web/handlers/pipeline.go:216-293` (showWaterfall + createWaterfallPlan)
- Delete: `web/handlers/pipeline.go:1567-1721` (waterfallPlanTool + streamWaterfallPlan)

- [ ] **Step 1: Remove waterfall route cases from Handle()**

In `web/handlers/pipeline.go`, delete these two cases from the `Handle()` switch (lines 60-63):

```go
// DELETE these two cases:
case strings.HasSuffix(rest, "/waterfall") && r.Method == "GET":
    h.showWaterfall(w, r, projectID, rest)
case strings.HasSuffix(rest, "/waterfall/create-plan") && r.Method == "POST":
    h.createWaterfallPlan(w, r, projectID, rest)
```

- [ ] **Step 2: Remove plan_waterfall case from streamStep()**

In `web/handlers/pipeline.go`, delete the `plan_waterfall` case from the `streamStep` switch (lines 1249-1250):

```go
// DELETE this case:
case "plan_waterfall":
    h.streamWaterfallPlan(w, r, projectID, stepID, run)
```

- [ ] **Step 3: Delete showWaterfall and createWaterfallPlan handlers**

Delete the entire `showWaterfall` function (lines 216-282) and `createWaterfallPlan` function (lines 284-293).

- [ ] **Step 4: Delete waterfall planner agent functions**

Delete the comment block `// --- Waterfall planner agent ---` (line 1567), the `waterfallPlanTool` function (lines 1569-1598), and the `streamWaterfallPlan` function (lines 1600-1721).

- [ ] **Step 5: Build to verify compilation**

Run: `make build`
Expected: Clean build with no errors.

- [ ] **Step 6: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "refactor: remove waterfall routes, handlers, and planner agent"
```

---

### Task 2: Simplify approvePiece Handler

**Files:**
- Modify: `web/handlers/pipeline.go:389-424` (approvePiece)

- [ ] **Step 1: Replace approvePiece with simplified version**

Replace the current `approvePiece` function (lines 389-424) with:

```go
func (h *PipelineHandler) approvePiece(w http.ResponseWriter, r *http.Request, projectID int64, rest string) {
	runID := h.parseRunID(rest)
	pieceID := h.parsePieceID(rest)

	piece, _ := h.queries.GetContentPiece(pieceID)
	h.queries.SetContentPieceStatus(pieceID, "approved")

	// Cornerstone approved = run complete
	if piece.ParentID == nil {
		h.queries.UpdatePipelineStatus(runID, "complete")
	}

	w.Header().Set("Content-Type", "application/json")
	json.NewEncoder(w).Encode(map[string]any{"complete": piece.ParentID == nil})
}
```

- [ ] **Step 2: Build to verify**

Run: `make build`
Expected: Clean build.

- [ ] **Step 3: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "refactor: simplify approvePiece — cornerstone approval completes run"
```

---

### Task 3: Remove Waterfall Branch from buildPiecePrompt

**Files:**
- Modify: `web/handlers/pipeline.go:312-325` (buildPiecePrompt)

- [ ] **Step 1: Remove the else branch for waterfall pieces**

In `buildPiecePrompt`, replace lines 312-325:

```go
// BEFORE:
if piece.ParentID == nil {
    // Cornerstone — inject storytelling framework if set
    if fwKey, err := h.queries.GetProjectSetting(projectID, "storytelling_framework"); err == nil && fwKey != "" {
        if fw := content.FrameworkByKey(fwKey); fw != nil {
            prompt += fmt.Sprintf("\n## Storytelling framework\nFramework: %s (%s)\n%s\n", fw.Name, fw.Attribution, fw.PromptInstruction)
        }
    }
    prompt += fmt.Sprintf("\n## Topic brief\n%s\n", run.Brief)
} else {
    // Waterfall — inject cornerstone
    cornerstone, _ := h.queries.GetContentPiece(*piece.ParentID)
    prompt += fmt.Sprintf("\n## Cornerstone content (your source material)\n%s\n", cornerstone.Body)
    prompt += "\nIMPORTANT: This content exists to funnel audience to the cornerstone piece. Stay faithful to the cornerstone's message and facts. Do not introduce new claims or information that isn't in the cornerstone.\n"
}
```

Replace with (remove the else, keep the if as-is but without else):

```go
// Cornerstone — inject storytelling framework if set
if fwKey, err := h.queries.GetProjectSetting(projectID, "storytelling_framework"); err == nil && fwKey != "" {
    if fw := content.FrameworkByKey(fwKey); fw != nil {
        prompt += fmt.Sprintf("\n## Storytelling framework\nFramework: %s (%s)\n%s\n", fw.Name, fw.Attribution, fw.PromptInstruction)
    }
}
prompt += fmt.Sprintf("\n## Topic brief\n%s\n", run.Brief)
```

- [ ] **Step 2: Build to verify**

Run: `make build`
Expected: Clean build.

- [ ] **Step 3: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "refactor: remove waterfall branch from buildPiecePrompt"
```

---

### Task 4: Exclude content_strategy from Writer Profile + Add BuildProfileStringExcluding

**Files:**
- Modify: `internal/store/profile.go:56-69` (add new method)
- Modify: `web/handlers/pipeline.go:1052` (writer prompt)
- Modify: `web/handlers/pipeline.go:349` (streamPiece profile call)

- [ ] **Step 1: Add BuildProfileStringExcluding to store**

In `internal/store/profile.go`, add after `BuildProfileString`:

```go
func (q *Queries) BuildProfileStringExcluding(projectID int64, exclude []string) (string, error) {
	sections, err := q.ListProfileSections(projectID)
	if err != nil {
		return "", err
	}
	excludeMap := make(map[string]bool, len(exclude))
	for _, e := range exclude {
		excludeMap[e] = true
	}
	var b strings.Builder
	for _, s := range sections {
		if s.Content == "" || excludeMap[s.Section] {
			continue
		}
		fmt.Fprintf(&b, "## %s\n%s\n\n", sectionTitle(s.Section), s.Content)
	}
	return b.String(), nil
}
```

- [ ] **Step 2: Use it in streamWrite**

In `web/handlers/pipeline.go`, change line 1052 from:

```go
profile, _ := h.queries.BuildProfileString(projectID)
```

to:

```go
profile, _ := h.queries.BuildProfileStringExcluding(projectID, []string{"content_strategy"})
```

- [ ] **Step 3: Use it in buildPiecePrompt too**

In `web/handlers/pipeline.go`, change line 349 (in `streamPiece`) from:

```go
profile, _ := h.queries.BuildProfileString(projectID)
```

to:

```go
profile, _ := h.queries.BuildProfileStringExcluding(projectID, []string{"content_strategy"})
```

- [ ] **Step 4: Build to verify**

Run: `make build`
Expected: Clean build.

- [ ] **Step 5: Commit**

```bash
git add internal/store/profile.go web/handlers/pipeline.go
git commit -m "refactor: exclude content_strategy from cornerstone writer profile context"
```

---

### Task 5: Reorder and Rename Profile Section

**Files:**
- Modify: `web/handlers/profile.go:19-36` (allSections + sectionDescriptions)
- Modify: `web/handlers/profile.go:318-323` (sectionTitle)
- Modify: `internal/store/profile.go:71-76` (sectionTitle)

- [ ] **Step 1: Reorder allSections**

In `web/handlers/profile.go`, change lines 19-22 from:

```go
var allSections = []string{
	"product_and_positioning", "audience", "voice_and_tone",
	"content_strategy", "guidelines",
}
```

to:

```go
var allSections = []string{
	"product_and_positioning", "audience", "voice_and_tone",
	"guidelines", "content_strategy",
}
```

- [ ] **Step 2: Update content_strategy description**

In `web/handlers/profile.go`, replace the `content_strategy` description (lines 32-34) from:

```go
"content_strategy": `Content goals (traffic, leads, authority, community). Which platforms to post on and why. Content formats per platform (blog, carousel, reel, thread, newsletter). Posting frequency per platform. 3-5 content pillars: recurring topic categories with example post ideas for each. For each pillar, include both "searchable" content (captures existing demand via SEO) and "shareable" content (creates demand through insights, stories, original takes).

IMPORTANT: The core of this strategy is the "content waterfall" approach. One cornerstone piece of content (like a blog post or video) gets repurposed into many smaller pieces across platforms. Define the client's waterfall flows clearly. For example: "Each blog post becomes 2 Instagram posts, 2 reels, 1 LinkedIn post, 1 X post, and 1 X thread." Be specific about what goes where and how many.`,
```

with:

```go
"content_strategy": `Content goals (traffic, leads, authority, community). Which platforms to post on and why. Content formats per platform (blog, carousel, reel, thread, newsletter). Posting frequency per platform. 3-5 content pillars: recurring topic categories with example post ideas for each. For each pillar, include both "searchable" content (captures existing demand via SEO) and "shareable" content (creates demand through insights, stories, original takes). Define how the client's cornerstone content gets distributed across social platforms — what goes where and how many pieces per platform.`,
```

- [ ] **Step 3: Add display title map to profile handler sectionTitle**

In `web/handlers/profile.go`, replace the `sectionTitle` function (lines 318-323):

```go
func sectionTitle(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
```

with:

```go
var sectionDisplayTitles = map[string]string{
	"content_strategy": "Social Content Strategy",
}

func sectionTitle(s string) string {
	if t, ok := sectionDisplayTitles[s]; ok {
		return t
	}
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
```

- [ ] **Step 4: Update store-level sectionTitle too**

In `internal/store/profile.go`, replace the `sectionTitle` function (lines 71-76):

```go
func sectionTitle(s string) string {
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
```

with:

```go
var sectionDisplayTitles = map[string]string{
	"content_strategy": "Social Content Strategy",
}

func sectionTitle(s string) string {
	if t, ok := sectionDisplayTitles[s]; ok {
		return t
	}
	if s == "" {
		return s
	}
	return strings.ToUpper(s[:1]) + s[1:]
}
```

- [ ] **Step 5: Build to verify**

Run: `make build`
Expected: Clean build.

- [ ] **Step 6: Commit**

```bash
git add web/handlers/profile.go internal/store/profile.go
git commit -m "refactor: reorder profile sections, rename content_strategy to Social Content Strategy"
```

---

### Task 6: Remove Waterfall Templates

**Files:**
- Modify: `web/templates/pipeline.templ:49-52` (phase badge)
- Modify: `web/templates/pipeline.templ:243-247` (waterfall link)
- Delete: `web/templates/pipeline.templ:91-98` (hasPendingPieces)
- Delete: `web/templates/pipeline.templ:252-343` (WaterfallPageData + WaterfallPage)

- [ ] **Step 1: Remove phase badge from pipeline list**

In `web/templates/pipeline.templ`, remove lines 49-51 (the phase badge block):

```go
// DELETE:
if run.Phase != "" {
    <span class={ "badge badge-" + run.Phase }>{ run.Phase }</span>
}
```

Keep the status badge on line 52 intact.

- [ ] **Step 2: Remove "Go to Waterfall" link from production board**

In `web/templates/pipeline.templ`, delete lines 243-247:

```go
// DELETE:
if data.Phase == "waterfall" {
    <div class="mt-4">
        <a href={ templ.SafeURL(fmt.Sprintf("/projects/%d/pipeline/%d/waterfall", data.ProjectID, data.RunID)) } class="btn">Go to Waterfall</a>
    </div>
}
```

- [ ] **Step 3: Delete WaterfallPageData struct, WaterfallPage template, and hasPendingPieces helper**

Delete lines 252-343 (the `WaterfallPageData` struct and entire `WaterfallPage` template), then delete lines 91-98 (the `hasPendingPieces` method on `WaterfallPageData`). These must be deleted together since `hasPendingPieces` is a method on `WaterfallPageData` and the template calls it.

- [ ] **Step 4: Run templ generate and build**

Run: `make build`
Expected: Clean build. Templ generates successfully with no references to deleted types.

- [ ] **Step 5: Commit**

```bash
git add web/templates/pipeline.templ web/templates/pipeline_templ.go
git commit -m "refactor: remove waterfall templates and phase badges"
```

---

### Task 7: Clean Up JavaScript

**Files:**
- Modify: `web/static/app.js:483-486` (waterfall page init)
- Modify: `web/static/app.js:827-841` (renderPlan waterfall block)
- Modify: `web/static/app.js:1381-1402` (approve handler phase_change)
- Delete: `web/static/app.js:1643-1799` (initWaterfallPage)

- [ ] **Step 1: Delete initWaterfallPage function**

Delete the comment `// --- Waterfall parallel generation ---` (line 1643) and the entire `initWaterfallPage` function (lines 1645-1799).

- [ ] **Step 2: Remove waterfall page init hook**

Delete lines 483-486:

```js
// DELETE:
var waterfallPage = document.getElementById('waterfall-page');
if (waterfallPage) {
    initWaterfallPage(waterfallPage.dataset.projectId, waterfallPage.dataset.runId);
}
```

- [ ] **Step 3: Remove waterfall block from renderPlan**

In `renderPlan` function, delete lines 827-841 (the `data.waterfall` block):

```js
// DELETE:
if (data.waterfall && data.waterfall.length > 0) {
    var wh = document.createElement('div');
    wh.style.cssText = 'font-weight:600;margin-top:0.75rem;margin-bottom:0.5rem;font-size:0.9rem';
    wh.textContent = 'Waterfall pieces:';
    el.appendChild(wh);
    var list = document.createElement('div');
    list.className = 'content-items';
    data.waterfall.forEach(function(w) {
        var item = document.createElement('div');
        item.className = 'content-item';
        item.textContent = (w.count || 1) + 'x ' + w.platform + ' ' + w.format;
        list.appendChild(item);
    });
    el.appendChild(list);
}
```

- [ ] **Step 4: Simplify approve handler**

Replace lines 1381-1402 (the approve click handler):

```js
// BEFORE:
// Approve button with phase_change support
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.piece-approve-btn');
    if (!btn) return;

    var pieceId = btn.dataset.pieceId;
    if (!pieceId) return;

    btn.disabled = true;
    btn.textContent = 'Approving...';

    fetch(basePath + '/piece/' + pieceId + '/approve', { method: 'POST' })
        .then(function(resp) { return resp.json(); })
        .then(function(data) {
            if (data.phase_change === 'waterfall') {
                window.location.href = '/projects/' + projectID + '/pipeline/' + runID + '/waterfall';
            } else {
                window.location.reload();
            }
        })
        .catch(function() { window.location.reload(); });
});
```

with:

```js
// Approve button
document.addEventListener('click', function(e) {
    var btn = e.target.closest('.piece-approve-btn');
    if (!btn) return;

    var pieceId = btn.dataset.pieceId;
    if (!pieceId) return;

    btn.disabled = true;
    btn.textContent = 'Approving...';

    fetch(basePath + '/piece/' + pieceId + '/approve', { method: 'POST' })
        .then(function() { window.location.reload(); })
        .catch(function() { window.location.reload(); });
});
```

- [ ] **Step 5: Build to verify**

Run: `make build`
Expected: Clean build.

- [ ] **Step 6: Commit**

```bash
git add web/static/app.js
git commit -m "refactor: remove waterfall JS — initWaterfallPage, renderPlan waterfall block, phase redirect"
```

---

### Task 8: Clean Up Prompts

**Files:**
- Modify: `web/handlers/brainstorm.go:170` (brainstorm prompt)
- Modify: `prompts/types/blog_post.md` (line 1)
- Modify: `prompts/types/linkedin_post.md` (line 1)
- Modify: `prompts/types/linkedin_carousel.md` (line 1)
- Modify: `prompts/types/x_post.md` (line 1)
- Modify: `prompts/types/x_thread.md` (line 1)
- Modify: `prompts/types/instagram_post.md` (line 1)
- Modify: `prompts/types/instagram_carousel.md` (line 1)
- Modify: `prompts/types/instagram_reel.md` (line 1)
- Modify: `prompts/types/facebook_post.md` (line 1)
- Modify: `prompts/types/youtube_short.md` (line 1)
- Modify: `prompts/types/tiktok_video.md` (line 1)

- [ ] **Step 1: Update brainstorm prompt**

In `web/handlers/brainstorm.go`, change line 170 from:

```
- Reference their content pillars and waterfall flows from the profile when relevant
```

to:

```
- Reference their content pillars and social content strategy from the profile when relevant
```

- [ ] **Step 2: Update blog_post.md**

Change line 1 from:
```
You are writing a blog post. This is a cornerstone pillar content piece that will be repurposed into social waterfall content across platforms.
```
to:
```
You are writing a blog post. This is a cornerstone pillar content piece — authoritative, in-depth, and optimized for search.
```

- [ ] **Step 3: Update all social platform prompt files**

For each file, replace "waterfall piece" with a description that stands on its own. Replace line 1 of each:

**`linkedin_post.md`** — change:
```
You are writing a LinkedIn post. This is a waterfall piece designed for B2B thought leadership, professional networking, and audience building.
```
to:
```
You are writing a LinkedIn post designed for B2B thought leadership, professional networking, and audience building.
```

**`linkedin_carousel.md`** — change:
```
You are writing a LinkedIn carousel (document post). This is a high-reach waterfall piece. Document/carousel posts get the strongest organic reach on LinkedIn.
```
to:
```
You are writing a LinkedIn carousel (document post). Document/carousel posts get the strongest organic reach on LinkedIn.
```

**`x_post.md`** — change:
```
You are writing a single Twitter/X post. This is a waterfall piece for real-time engagement, hot takes, and community building.
```
to:
```
You are writing a single Twitter/X post for real-time engagement, hot takes, and community building.
```

**`x_thread.md`** — change:
```
You are writing a Twitter/X thread. Threads keep people on platform, which the algorithm rewards heavily. This is a high-reach waterfall piece for teaching, storytelling, and breakdowns.
```
to:
```
You are writing a Twitter/X thread. Threads keep people on platform, which the algorithm rewards heavily. Great for teaching, storytelling, and breakdowns.
```

**`instagram_post.md`** — change:
```
You are writing an Instagram feed post. This is a waterfall piece for visual brand building, community engagement, and audience growth.
```
to:
```
You are writing an Instagram feed post for visual brand building, community engagement, and audience growth.
```

**`instagram_carousel.md`** — change:
```
You are writing an Instagram carousel. Carousels drive saves and shares, which are the highest-value algorithm signals on Instagram. This is a high-engagement waterfall piece.
```
to:
```
You are writing an Instagram carousel. Carousels drive saves and shares, which are the highest-value algorithm signals on Instagram.
```

**`instagram_reel.md`** — change:
```
You are writing an Instagram Reel script. Reels get 2x the reach of static posts. This is a high-priority waterfall piece for audience growth.
```
to:
```
You are writing an Instagram Reel script. Reels get 2x the reach of static posts — high priority for audience growth.
```

**`facebook_post.md`** — change:
```
You are writing a Facebook post. This is a waterfall piece for community building, discussion, and reaching a 25-55+ audience. Facebook rewards native content and conversation.
```
to:
```
You are writing a Facebook post for community building, discussion, and reaching a 25-55+ audience. Facebook rewards native content and conversation.
```

**`youtube_short.md`** — change:
```
You are writing a YouTube Short script. Shorts are under 60 seconds, vertical (9:16), and optimized for rapid-fire value delivery. This is a high-reach waterfall piece for audience growth and channel discovery.
```
to:
```
You are writing a YouTube Short script. Shorts are under 60 seconds, vertical (9:16), and optimized for rapid-fire value delivery and channel discovery.
```

**`tiktok_video.md`** — change:
```
You are writing a TikTok video script. TikTok rewards native, unpolished, trend-aware content. This is a high-reach waterfall piece for brand awareness and audience growth, especially with 16-34 audiences.
```
to:
```
You are writing a TikTok video script. TikTok rewards native, unpolished, trend-aware content — great for brand awareness and audience growth, especially with 16-34 audiences.
```

- [ ] **Step 4: Build to verify**

Run: `make build`
Expected: Clean build.

- [ ] **Step 5: Commit**

```bash
git add web/handlers/brainstorm.go prompts/types/
git commit -m "refactor: remove waterfall language from all prompts"
```

---

### Task 9: Remove Waterfall CSS + Final Cleanup

**Files:**
- Modify: `web/static/style.css:33` (badge-waterfalling)

- [ ] **Step 1: Remove waterfall CSS class**

In `web/static/style.css`, delete line 33:

```css
.badge-waterfalling { background: #cffafe; color: #155e75; }
```

- [ ] **Step 2: Full build and manual smoke test**

Run: `make restart`

Verify:
1. Pipeline list page loads — no phase badges, only status badges
2. Create a new pipeline run — cornerstone steps execute normally
3. Approve a cornerstone piece — run status changes to `complete`, no redirect to waterfall
4. Profile page — content_strategy section appears below guidelines with "Social Content Strategy" title

- [ ] **Step 3: Commit**

```bash
git add web/static/style.css
git commit -m "chore: remove waterfall CSS class"
```

- [ ] **Step 4: Final grep for stray waterfall references**

Run: `grep -ri "waterfall" --include="*.go" --include="*.js" --include="*.templ" --include="*.md" --include="*.css" web/ internal/ prompts/ | grep -v "docs/" | grep -v "_templ.go"`

Expected: No matches in source code (only docs/specs/plans may reference it).

If stray references found, clean them up and amend the commit.
