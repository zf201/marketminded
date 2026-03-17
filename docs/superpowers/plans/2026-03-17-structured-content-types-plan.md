# Structured Content Types Implementation Plan

> **For agentic workers:** REQUIRED: Use superpowers:subagent-driven-development (if subagents available) or superpowers:executing-plans to implement this plan. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace untyped body text with structured, typed output per content format. 12 content types, each with a tool definition, dedicated prompt file, and frontend renderer.

**Architecture:** New `internal/content/types.go` registry maps platform+format to tool definitions + prompt file paths. 12 prompt files in `prompts/types/`. Pipeline handler loads prompt + registers type-specific write tool. AI produces structured JSON via tool calls. Frontend renders type-specific cards.

**Tech Stack:** Go, OpenRouter (tool calling), vanilla JS, templ

---

## File Map

```
Create:
  internal/content/types.go               — content type registry (12 types with tool defs)
  internal/content/types_test.go           — registry tests
  prompts/types/blog_post.md              — 12 prompt files, one per type
  prompts/types/linkedin_post.md
  prompts/types/linkedin_carousel.md
  prompts/types/instagram_post.md
  prompts/types/instagram_reel.md
  prompts/types/instagram_carousel.md
  prompts/types/x_post.md
  prompts/types/x_thread.md
  prompts/types/youtube_script.md
  prompts/types/youtube_short.md
  prompts/types/facebook_post.md
  prompts/types/tiktok_video.md

Modify:
  web/handlers/pipeline.go                — use registry, remove inline prompts, handle write tools
  web/static/app.js                       — type-specific content renderers
  web/static/style.css                    — content display styles
  web/templates/pipeline.templ            — pass platform+format data attrs for JS rendering
```

---

## Chunk 1: Content Type Registry

### Task 1: Create the content type registry

**Files:**
- Create: `internal/content/types.go`
- Create: `internal/content/types_test.go`

- [ ] **Step 1: Create internal/content/types.go**

This file defines all 12 content types with their tool definitions and prompt file paths. Each tool has a JSON Schema defining the structured parameters.

```go
package content

import (
	"encoding/json"
	"os"
	"path/filepath"

	"github.com/zanfridau/marketminded/internal/ai"
)

type ContentType struct {
	Platform    string
	Format      string
	DisplayName string
	PromptFile  string
	ToolName    string
	Tool        ai.Tool
}

var Registry = map[string]ContentType{}

func init() {
	register("blog", "post", "Blog Post", "blog_post", "write_blog_post",
		`{"type":"object","properties":{"title":{"type":"string","description":"Blog post title"},"body":{"type":"string","description":"Full blog post in markdown"},"meta_description":{"type":"string","description":"SEO meta description, under 160 chars"}},"required":["title","body","meta_description"]}`)

	register("linkedin", "post", "LinkedIn Post", "linkedin_post", "write_linkedin_post",
		`{"type":"object","properties":{"caption":{"type":"string","description":"Post caption. Hook in first line. 1200-1500 chars."},"hashtags":{"type":"string","description":"Hashtags, space-separated. 3-5 max."}},"required":["caption"]}`)

	register("linkedin", "carousel", "LinkedIn Carousel", "linkedin_carousel", "write_linkedin_carousel",
		`{"type":"object","properties":{"slides":{"type":"array","items":{"type":"object","properties":{"title":{"type":"string"},"body":{"type":"string"}},"required":["title","body"]},"description":"Carousel slides. Slide 1 is hook. Last slide is summary + CTA."},"caption":{"type":"string","description":"Post caption."}},"required":["slides","caption"]}`)

	register("instagram", "post", "Instagram Post", "instagram_post", "write_instagram_post",
		`{"type":"object","properties":{"caption":{"type":"string","description":"Post caption. Hook first line. Under 2200 chars."},"hashtags":{"type":"string","description":"Hashtags, space-separated. Up to 15."},"image_instructions":{"type":"string","description":"Instructions for the visual/image to pair with this post."}},"required":["caption"]}`)

	register("instagram", "reel", "Instagram Reel", "instagram_reel", "write_instagram_reel",
		`{"type":"object","properties":{"hook":{"type":"string","description":"First 1-2 seconds. Pattern interrupt or bold claim."},"setup":{"type":"string","description":"2-5 seconds. Context."},"value":{"type":"string","description":"5-25 seconds. The actual content."},"cta":{"type":"string","description":"Last 5 seconds. Follow/comment/share CTA."},"caption":{"type":"string","description":"Post caption with hashtags."}},"required":["hook","setup","value","cta","caption"]}`)

	register("instagram", "carousel", "Instagram Carousel", "instagram_carousel", "write_instagram_carousel",
		`{"type":"object","properties":{"slides":{"type":"array","items":{"type":"object","properties":{"text":{"type":"string"}},"required":["text"]},"description":"Carousel slides. One point per slide."},"caption":{"type":"string","description":"Post caption."},"hashtags":{"type":"string","description":"Hashtags."}},"required":["slides","caption"]}`)

	register("x", "post", "X Post", "x_post", "write_x_post",
		`{"type":"object","properties":{"text":{"type":"string","description":"Single tweet. Under 280 chars."}},"required":["text"]}`)

	register("x", "thread", "X Thread", "x_thread", "write_x_thread",
		`{"type":"object","properties":{"tweets":{"type":"array","items":{"type":"string"},"description":"Array of tweets. First is hook. Last is CTA. Each under 280 chars."}},"required":["tweets"]}`)

	register("youtube", "script", "YouTube Script", "youtube_script", "write_youtube_script",
		`{"type":"object","properties":{"title":{"type":"string","description":"Video title"},"sections":{"type":"array","items":{"type":"object","properties":{"timestamp":{"type":"string"},"heading":{"type":"string"},"content":{"type":"string"},"notes":{"type":"string"}},"required":["heading","content"]},"description":"Script sections with optional timestamps and delivery notes."}},"required":["title","sections"]}`)

	register("youtube", "short", "YouTube Short", "youtube_short", "write_youtube_short",
		`{"type":"object","properties":{"hook":{"type":"string","description":"First 1-2 seconds."},"content":{"type":"string","description":"Main content. Under 60 seconds total."},"cta":{"type":"string","description":"Follow/subscribe CTA."}},"required":["hook","content","cta"]}`)

	register("facebook", "post", "Facebook Post", "facebook_post", "write_facebook_post",
		`{"type":"object","properties":{"caption":{"type":"string","description":"Post text. Conversational. 500 chars ideal."}},"required":["caption"]}`)

	register("tiktok", "video", "TikTok Video", "tiktok_video", "write_tiktok_video",
		`{"type":"object","properties":{"hook":{"type":"string","description":"First 1-2 seconds."},"content":{"type":"string","description":"Main content."},"cta":{"type":"string","description":"Follow CTA."},"caption":{"type":"string","description":"Post caption."}},"required":["hook","content","cta","caption"]}`)
}

func register(platform, format, displayName, promptFile, toolName, paramsJSON string) {
	key := platform + "_" + format
	Registry[key] = ContentType{
		Platform:    platform,
		Format:      format,
		DisplayName: displayName,
		PromptFile:  promptFile,
		ToolName:    toolName,
		Tool: ai.Tool{
			Type: "function",
			Function: ai.ToolFunction{
				Name:        toolName,
				Description: "Write a " + displayName + ". Provide the structured content.",
				Parameters:  json.RawMessage(paramsJSON),
			},
		},
	}
}

func LookupType(platform, format string) (ContentType, bool) {
	ct, ok := Registry[platform+"_"+format]
	return ct, ok
}

// LoadPrompt reads the prompt file for a content type from the prompts/types/ directory.
func LoadPrompt(promptFile string) (string, error) {
	path := filepath.Join("prompts", "types", promptFile+".md")
	data, err := os.ReadFile(path)
	if err != nil {
		return "", err
	}
	return string(data), nil
}

// IsWriteTool returns true if the tool name is a content write tool.
func IsWriteTool(toolName string) bool {
	for _, ct := range Registry {
		if ct.ToolName == toolName {
			return true
		}
	}
	return false
}
```

- [ ] **Step 2: Write test**

```go
package content

import "testing"

func TestLookupType(t *testing.T) {
	ct, ok := LookupType("x", "thread")
	if !ok {
		t.Fatal("expected to find x_thread")
	}
	if ct.ToolName != "write_x_thread" {
		t.Errorf("expected write_x_thread, got %s", ct.ToolName)
	}
	if ct.DisplayName != "X Thread" {
		t.Errorf("expected X Thread, got %s", ct.DisplayName)
	}
}

func TestLookupTypeNotFound(t *testing.T) {
	_, ok := LookupType("nonexistent", "type")
	if ok {
		t.Error("expected not found")
	}
}

func TestRegistryHas12Types(t *testing.T) {
	if len(Registry) != 12 {
		t.Errorf("expected 12 types, got %d", len(Registry))
	}
}

func TestIsWriteTool(t *testing.T) {
	if !IsWriteTool("write_blog_post") {
		t.Error("expected write_blog_post to be a write tool")
	}
	if IsWriteTool("fetch_url") {
		t.Error("fetch_url should not be a write tool")
	}
}
```

- [ ] **Step 3: Run tests**

```bash
go test ./internal/content/ -v
# Expected: PASS
```

- [ ] **Step 4: Commit**

```bash
git add internal/content/
git commit -m "feat: add content type registry with 12 types and tool definitions"
```

---

## Chunk 2: Prompt Files

### Task 2: Create all 12 prompt files

**Files:**
- Create: `prompts/types/blog_post.md`
- Create: `prompts/types/linkedin_post.md`
- Create: `prompts/types/linkedin_carousel.md`
- Create: `prompts/types/instagram_post.md`
- Create: `prompts/types/instagram_reel.md`
- Create: `prompts/types/instagram_carousel.md`
- Create: `prompts/types/x_post.md`
- Create: `prompts/types/x_thread.md`
- Create: `prompts/types/youtube_script.md`
- Create: `prompts/types/youtube_short.md`
- Create: `prompts/types/facebook_post.md`
- Create: `prompts/types/tiktok_video.md`

Each file should contain:
1. Role definition for the specific format
2. Structure template with what goes where
3. Hook formulas relevant to that format (from `prompts/post-templates.md`)
4. Platform-specific rules (from `prompts/platform-strategies.md`)
5. Quality checklist
6. Instruction to call the appropriate write tool

Use the reference files already saved in the project:
- `prompts/copywriting-reference.md` — writing principles
- `prompts/social-content-reference.md` — social strategy frameworks
- `prompts/post-templates.md` — post structure templates per platform
- `prompts/platform-strategies.md` — detailed platform guides
- `prompts/ai-writing-detection.md` — anti-AI word lists
- `prompts/natural-transitions.md` — human transition phrases
- `prompts/copy-frameworks.md` — headline formulas

Pull directly from these reference files. Don't paraphrase.

**Example prompt files (write ALL 12 with this level of detail):**

`prompts/types/x_thread.md`:
```markdown
You are writing a Twitter/X thread.

## Thread structure
- Tweet 1: Hook + promise of value. This determines if anyone reads the rest.
- Tweets 2-7: One point per tweet. Each tweet stands alone but builds on the previous.
- Final tweet: Summary + CTA (follow, reply, repost).

## Hook formulas
- "I was wrong about [common belief]. Here's what actually works:"
- "[Result] in [timeframe]. Here's the full breakdown:"
- "Everyone says [X]. The truth is [Y]."
- "[Number] things I learned about [topic] after [credibility builder]:"
- "Nobody talks about [insider knowledge]."
- "Stop [common mistake]. Do this instead:"

## Platform rules
- Each tweet under 280 characters. Under 100 gets more engagement.
- Threads keep people on platform and are rewarded by the algorithm.
- First 30 minutes of engagement matters.
- Images and video get more reach.
- No filler tweets. Every tweet must add value.
- Don't start with "Thread:" or use number emojis.
- Use plain numbers: "1.", "2.", etc.
- Last tweet should include a clear follow CTA.

## Quality check
- Does tweet 1 make you want to read tweet 2?
- Can each tweet stand on its own if quoted?
- Is there a clear takeaway in each tweet?
- Would you actually engage with this thread?

Call the write_x_thread tool with your tweets array when done.
```

`prompts/types/blog_post.md`:
```markdown
You are writing a blog post.

## Structure
- Headline: your single most important message. Specific > generic. Communicate core value.
- Introduction: hook with a relatable problem or surprising insight. Not "In today's world..."
- Body: clear sections with H2/H3 headers. Each section advances one argument.
- Include actionable takeaways the reader can use immediately.
- Conclusion: recap value + clear CTA or next step.

## Headline formulas
- "{Achieve outcome} without {pain point}"
- "The {category} for {audience}"
- "Never {unpleasant event} again"
- "[Number] [things] that [outcome]"
- "How to [outcome] in [timeframe]"
- "Stop [pain]. Start [pleasure]."

## Copywriting principles
- Clarity over cleverness. When in doubt, be clear.
- Benefits over features. Focus on outcomes.
- Specificity over vagueness. "Cut reporting from 4 hours to 15 minutes" not "Save time."
- Customer language over corporate speak. Mirror the audience.
- One idea per section. Build logical flow.

## Style rules
- Simple words: "use" not "utilize", "help" not "facilitate"
- Active voice: "We generate reports" not "Reports are generated"
- Confident tone: remove "almost", "very", "really", "basically"
- Show outcomes, don't claim them.
- Use rhetorical questions to engage.
- Use analogies for abstract concepts.

## SEO
- 1200-2000 words.
- SEO-friendly H2/H3 headers.
- Use subheadings for scannability.
- Meta description under 160 characters.

## Quality check
- Any jargon that could confuse outsiders? Remove it.
- Any sentences trying to do too much? Split them.
- Any passive voice? Rewrite to active.
- Any exclamation points? Remove them.
- Any marketing buzzwords without substance? Cut them.
- Any fabricated claims or statistics? Delete them.
- Every paragraph earns its place?

Call the write_blog_post tool with title, body (markdown), and meta_description when done.
```

`prompts/types/instagram_reel.md`:
```markdown
You are writing an Instagram Reel script.

## Script structure
- Hook (0-2 sec): Pattern interrupt or bold claim. First frame must grab attention.
- Setup (2-5 sec): Quick context for the tip or story.
- Value (5-25 sec): The actual advice, content, or story payoff.
- CTA (25-30 sec): Follow, comment, share, or link in bio.

## Hook formulas for reels
- "Did you know [surprising fact]?"
- "Stop doing [common mistake]."
- "The #1 reason [pain point]..."
- "I tested [thing] for [time]. Here's what happened."
- "POV: you just learned [insight]"

## Platform rules
- Reels get 2x reach of static posts.
- Hook in first 1-2 seconds or people scroll past.
- Keep under 60 seconds to start. 30 seconds is ideal.
- Vertical 9:16 format.
- Conversational delivery, not scripted-sounding.
- Use trending sounds if appropriate.
- First frame/thumbnail must stop the scroll.
- Saves and shares matter more than likes.

## Quality check
- Does the hook stop a thumb mid-scroll?
- Is there one clear point (not three)?
- Would someone watch this to the end?
- Does the CTA feel natural, not forced?

Call the write_instagram_reel tool with hook, setup, value, cta, and caption when done.
```

Write ALL 12 prompt files following these patterns. For each, pull the relevant framework details from the reference files. Every prompt file should end with "Call the [write_tool_name] tool with [fields] when done."

- [ ] **Step 1: Create prompts/types/ directory and all 12 files**

```bash
mkdir -p prompts/types
```

Then create each file using the reference materials.

- [ ] **Step 2: Commit**

```bash
git add prompts/types/
git commit -m "feat: add 12 content type prompt files with platform-specific craft instructions"
```

---

## Chunk 3: Pipeline Handler Update

### Task 3: Update pipeline handler to use registry + prompt files

**Files:**
- Modify: `web/handlers/pipeline.go`

- [ ] **Step 1: Remove old inline prompts and platformGuidance**

Delete the entire `platformGuidance` map (lines 20-47). Delete the `cornerstonePrompt()` and `waterfallPrompt()` methods.

- [ ] **Step 2: Add import for content package**

```go
import "github.com/zanfridau/marketminded/internal/content"
```

- [ ] **Step 3: Rewrite the `streamPiece` method**

New flow:
1. Look up `ContentType` from registry
2. Load the prompt file via `content.LoadPrompt(ct.PromptFile)`
3. Build system prompt: date + prompt file + client profile + (cornerstone if waterfall) + anti-AI rules + rejection reason
4. Register tools: fetch + search + the type's write tool
5. Stream via `StreamWithTools`
6. When AI calls the write tool: save the JSON args directly to `body`, set status `draft`

The key change in the executor function: detect write tool calls via `content.IsWriteTool(name)` and save the args JSON directly to the piece body.

```go
executor := func(ctx context.Context, name, args string) (string, error) {
    switch name {
    case "fetch_url":
        return tools.ExecuteFetch(ctx, args)
    case "web_search":
        return searchExec(ctx, args)
    default:
        if content.IsWriteTool(name) {
            // Save structured content to piece body
            h.queries.UpdateContentPieceBody(pieceID, "", args)
            h.queries.SetContentPieceStatus(pieceID, "draft")
            return "Content saved successfully. The user will review it.", nil
        }
        return "", fmt.Errorf("unknown tool: %s", name)
    }
}
```

The `onToolEvent` callback should send the structured data to the frontend when a write tool is called, so the card can render immediately:

```go
if content.IsWriteTool(event.Tool) {
    sendEvent(map[string]any{
        "type": "content_written",
        "platform": piece.Platform,
        "format": piece.Format,
        "data": json.RawMessage(event.Args),
    })
}
```

- [ ] **Step 4: Build the system prompt from prompt file + context**

Create a new method `buildPiecePrompt`:

```go
func (h *PipelineHandler) buildPiecePrompt(piece *store.ContentPiece, run *store.PipelineRun, profile string) string {
    ct, _ := content.LookupType(piece.Platform, piece.Format)
    promptText, _ := content.LoadPrompt(ct.PromptFile)

    prompt := fmt.Sprintf("Today's date: %s\n\n%s\n\n## Client profile\n%s\n",
        time.Now().Format("January 2, 2006"), promptText, profile)

    if piece.ParentID == nil {
        // Cornerstone
        prompt += fmt.Sprintf("\n## Topic\n%s\n", run.Topic)
    } else {
        // Waterfall — inject cornerstone
        cornerstone, _ := h.queries.GetContentPiece(*piece.ParentID)
        prompt += fmt.Sprintf("\n## Cornerstone content (your source material)\n%s\n", cornerstone.Body)
    }

    if piece.RejectionReason != "" {
        prompt += fmt.Sprintf("\nPrevious version was rejected. Feedback: %s. Address this.\n", piece.RejectionReason)
    }

    // Anti-AI rules (same for all types)
    prompt += antiAIRules

    return prompt
}
```

Define `antiAIRules` as a const string with the comprehensive banned word list (same as current, just extracted into a constant).

- [ ] **Step 5: Update improve flow similarly**

The `streamImprove` method should also load the type's prompt file and register the write tool. Inject current body JSON + user feedback.

- [ ] **Step 6: Build to verify**

```bash
go build ./...
```

- [ ] **Step 7: Commit**

```bash
git add web/handlers/pipeline.go
git commit -m "feat: pipeline handler uses content type registry, prompt files, and write tools"
```

---

## Chunk 4: Frontend Renderers

### Task 4: Content type renderers in JS + CSS

**Files:**
- Modify: `web/static/app.js`
- Modify: `web/static/style.css`
- Modify: `web/templates/pipeline.templ`

- [ ] **Step 1: Add content renderer CSS**

```css
/* Content type renderers */
.content-caption { white-space: pre-wrap; line-height: 1.5; }
.content-hashtags { margin-top: 0.5rem; color: #3b82f6; font-size: 0.85rem; }
.content-instructions { margin-top: 0.5rem; font-style: italic; }
.content-meta { margin-top: 0.5rem; padding: 0.5rem; background: #f9fafb; border-radius: 4px; }
.content-title { font-size: 1.1rem; margin-bottom: 0.75rem; }
.content-items { display: flex; flex-direction: column; gap: 0.5rem; }
.content-item { padding: 0.5rem 0.75rem; background: #f9fafb; border-radius: 4px; border-left: 3px solid #3b82f6; font-size: 0.85rem; }
.content-item-num { font-weight: 600; color: #3b82f6; margin-right: 0.5rem; }
.content-script { display: flex; flex-direction: column; gap: 0.5rem; }
.script-section { padding: 0.5rem 0.75rem; border-left: 3px solid #e5e5e5; }
.script-section strong { color: #374151; font-size: 0.8rem; text-transform: uppercase; display: block; margin-bottom: 0.25rem; }
.content-slides .slide-card { padding: 0.5rem 0.75rem; background: #f9fafb; border-radius: 4px; margin-bottom: 0.5rem; }
.slide-card-title { font-weight: 600; font-size: 0.85rem; margin-bottom: 0.25rem; }
.slide-card-body { font-size: 0.85rem; }
```

- [ ] **Step 2: Add renderContentBody function to app.js**

```js
function renderContentBody(el, platform, format, bodyText) {
    // Try parsing as JSON
    var data;
    try {
        data = JSON.parse(bodyText);
    } catch (e) {
        // Fallback: plain text
        el.textContent = bodyText;
        return;
    }

    el.textContent = ''; // Clear
    var key = platform + '_' + format;

    switch (key) {
    case 'blog_post':
        renderBlogPost(el, data); break;
    case 'linkedin_post':
    case 'instagram_post':
    case 'facebook_post':
        renderSimplePost(el, data); break;
    case 'x_post':
        renderXPost(el, data); break;
    case 'x_thread':
        renderXThread(el, data); break;
    case 'linkedin_carousel':
        renderLinkedinCarousel(el, data); break;
    case 'instagram_carousel':
        renderInstagramCarousel(el, data); break;
    case 'instagram_reel':
    case 'youtube_short':
    case 'tiktok_video':
        renderScript(el, data); break;
    case 'youtube_script':
        renderYoutubeScript(el, data); break;
    default:
        el.textContent = bodyText;
    }
}

function renderBlogPost(el, data) {
    if (data.title) { var h = document.createElement('h3'); h.className = 'content-title'; h.textContent = data.title; el.appendChild(h); }
    if (data.body) { var b = document.createElement('div'); b.className = 'content-caption'; b.textContent = data.body; el.appendChild(b); }
    if (data.meta_description) { var m = document.createElement('div'); m.className = 'content-meta text-muted'; m.textContent = 'Meta: ' + data.meta_description; el.appendChild(m); }
}

function renderSimplePost(el, data) {
    if (data.caption) { var c = document.createElement('div'); c.className = 'content-caption'; c.textContent = data.caption; el.appendChild(c); }
    if (data.hashtags) { var h = document.createElement('div'); h.className = 'content-hashtags'; h.textContent = data.hashtags; el.appendChild(h); }
    if (data.image_instructions) { var i = document.createElement('div'); i.className = 'content-instructions text-muted'; i.textContent = 'Image: ' + data.image_instructions; el.appendChild(i); }
}

function renderXPost(el, data) {
    if (data.text) { var t = document.createElement('div'); t.className = 'content-caption'; t.textContent = data.text; el.appendChild(t); }
}

function renderXThread(el, data) {
    if (!data.tweets) return;
    var items = document.createElement('div'); items.className = 'content-items';
    data.tweets.forEach(function(tweet, i) {
        var item = document.createElement('div'); item.className = 'content-item';
        var num = document.createElement('span'); num.className = 'content-item-num'; num.textContent = (i + 1) + '.';
        item.appendChild(num);
        item.appendChild(document.createTextNode(' ' + tweet));
        items.appendChild(item);
    });
    el.appendChild(items);
}

function renderLinkedinCarousel(el, data) {
    if (data.slides) {
        data.slides.forEach(function(slide, i) {
            var card = document.createElement('div'); card.className = 'slide-card';
            var title = document.createElement('div'); title.className = 'slide-card-title'; title.textContent = 'Slide ' + (i + 1) + ': ' + (slide.title || '');
            var body = document.createElement('div'); body.className = 'slide-card-body'; body.textContent = slide.body || '';
            card.appendChild(title); card.appendChild(body); el.appendChild(card);
        });
    }
    if (data.caption) { var c = document.createElement('div'); c.className = 'content-caption'; c.style.marginTop = '0.5rem'; c.textContent = data.caption; el.appendChild(c); }
}

function renderInstagramCarousel(el, data) {
    if (data.slides) {
        var items = document.createElement('div'); items.className = 'content-items';
        data.slides.forEach(function(slide, i) {
            var item = document.createElement('div'); item.className = 'content-item';
            item.textContent = 'Slide ' + (i + 1) + ': ' + (slide.text || '');
            items.appendChild(item);
        });
        el.appendChild(items);
    }
    if (data.caption) { var c = document.createElement('div'); c.className = 'content-caption'; c.style.marginTop = '0.5rem'; c.textContent = data.caption; el.appendChild(c); }
    if (data.hashtags) { var h = document.createElement('div'); h.className = 'content-hashtags'; h.textContent = data.hashtags; el.appendChild(h); }
}

function renderScript(el, data) {
    var script = document.createElement('div'); script.className = 'content-script';
    var fields = ['hook', 'setup', 'value', 'content', 'cta'];
    fields.forEach(function(field) {
        if (data[field]) {
            var sec = document.createElement('div'); sec.className = 'script-section';
            var label = document.createElement('strong'); label.textContent = field.charAt(0).toUpperCase() + field.slice(1);
            sec.appendChild(label);
            sec.appendChild(document.createTextNode(data[field]));
            script.appendChild(sec);
        }
    });
    el.appendChild(script);
    if (data.caption) { var c = document.createElement('div'); c.className = 'content-caption'; c.style.marginTop = '0.5rem'; c.textContent = data.caption; el.appendChild(c); }
}

function renderYoutubeScript(el, data) {
    if (data.title) { var h = document.createElement('h3'); h.className = 'content-title'; h.textContent = data.title; el.appendChild(h); }
    if (data.sections) {
        data.sections.forEach(function(sec) {
            var div = document.createElement('div'); div.className = 'script-section';
            var heading = document.createElement('strong');
            heading.textContent = (sec.timestamp ? '[' + sec.timestamp + '] ' : '') + sec.heading;
            div.appendChild(heading);
            div.appendChild(document.createTextNode(sec.content));
            if (sec.notes) { var n = document.createElement('p'); n.className = 'text-muted'; n.textContent = '[' + sec.notes + ']'; div.appendChild(n); }
            el.appendChild(div);
        });
    }
}
```

- [ ] **Step 3: Update pipeline template to add data attributes for rendering**

In `pipeline.templ`, update the piece body div to include `data-platform` and `data-format` attributes:

```
<div class="board-card-body" id={ fmt.Sprintf("piece-body-%d", piece.ID) } data-platform={ piece.Platform } data-format={ piece.Format }>
```

- [ ] **Step 4: Call renderContentBody on page load and after generation**

In `initProductionBoard`, after the page loads, render all existing piece bodies:

```js
// Render existing content bodies
document.querySelectorAll('.board-card-body').forEach(function(el) {
    var text = el.textContent.trim();
    if (text && el.dataset.platform) {
        renderContentBody(el, el.dataset.platform, el.dataset.format, text);
    }
});
```

Also, when a `content_written` SSE event arrives during generation, render it:
```js
case 'content_written':
    var bodyEl = document.getElementById('piece-body-' + currentPieceId);
    if (bodyEl) {
        renderContentBody(bodyEl, d.platform, d.format, JSON.stringify(d.data));
    }
    break;
```

- [ ] **Step 5: Build and verify**

```bash
templ generate ./web/templates/
go build ./...
```

- [ ] **Step 6: Commit**

```bash
git add web/static/ web/templates/pipeline.templ
git commit -m "feat: add type-specific content renderers for all 12 content types"
```

---

## Chunk 5: Integration

### Task 5: Wire up and test

- [ ] **Step 1: go mod tidy + run all tests**

```bash
go mod tidy
rm -f marketminded.db
go test ./... -v
```

- [ ] **Step 2: Build and manual smoke test**

```bash
templ generate ./web/templates/
go build -o server ./cmd/server/
OPENROUTER_API_KEY=... BRAVE_API_KEY=... ./server
```

Test:
1. Create project, fill profile with content strategy including waterfall
2. Start pipeline run
3. Plan should include typed pieces (blog_post, x_thread, etc.)
4. Cornerstone generates → blog post renders with title/body/meta
5. Waterfall pieces generate → each renders in type-specific format
6. X thread shows as numbered tweet list
7. Reel/short shows as script sections
8. Carousel shows as slide cards
9. Improve works — AI rewrites by calling the same write tool

- [ ] **Step 3: Final commit**

```bash
git add -A
git commit -m "feat: complete structured content types — 12 types with tools, prompts, and renderers"
```
