# Structured Content Types

## Overview

Replace the untyped `body TEXT` content model with structured, typed output per content format. Each content type gets a dedicated tool definition (typed parameters), a prompt file (format-specific craft instructions), and a frontend renderer (type-aware UI). The AI produces structured JSON via tool calls instead of free text.

## Content Type Registry

12 content types, each identified by `platform+format`:

| Key | Platform | Format | Tool | Parameters |
|-----|----------|--------|------|------------|
| `blog_post` | blog | post | `write_blog_post` | `title`, `body` (markdown), `meta_description` |
| `linkedin_post` | linkedin | post | `write_linkedin_post` | `caption`, `hashtags` (string) |
| `linkedin_carousel` | linkedin | carousel | `write_linkedin_carousel` | `slides[]` ({title, body}), `caption` |
| `instagram_post` | instagram | post | `write_instagram_post` | `caption`, `hashtags` (string), `image_instructions` |
| `instagram_reel` | instagram | reel | `write_instagram_reel` | `hook`, `setup`, `value`, `cta`, `caption` |
| `instagram_carousel` | instagram | carousel | `write_instagram_carousel` | `slides[]` ({text}), `caption`, `hashtags` (string) |
| `x_post` | x | post | `write_x_post` | `text` |
| `x_thread` | x | thread | `write_x_thread` | `tweets[]` (string array) |
| `youtube_script` | youtube | script | `write_youtube_script` | `title`, `sections[]` ({timestamp, heading, content, notes}) |
| `youtube_short` | youtube | short | `write_youtube_short` | `hook`, `content`, `cta` |
| `facebook_post` | facebook | post | `write_facebook_post` | `caption` |
| `tiktok_video` | tiktok | video | `write_tiktok_video` | `hook`, `content`, `cta`, `caption` |

### Registry implementation

`internal/content/types.go` — a Go struct registry:

```go
type ContentType struct {
    Platform    string
    Format      string
    DisplayName string
    PromptFile  string           // path to prompts/types/*.md
    Tool        ai.Tool          // tool definition with JSON Schema params
}

var Registry = map[string]ContentType{...}  // keyed by "platform_format"

func LookupType(platform, format string) (ContentType, bool)
```

This replaces the current `platformGuidance` map entirely.

## Tool Definitions

Each tool enforces structured output via JSON Schema parameters. Examples:

### write_x_thread
```json
{
  "type": "function",
  "function": {
    "name": "write_x_thread",
    "description": "Write a Twitter/X thread. Provide an array of tweets.",
    "parameters": {
      "type": "object",
      "properties": {
        "tweets": {
          "type": "array",
          "items": {"type": "string"},
          "description": "Array of tweets. First is the hook. Last is the CTA. Each under 280 chars."
        }
      },
      "required": ["tweets"]
    }
  }
}
```

### write_blog_post
```json
{
  "type": "function",
  "function": {
    "name": "write_blog_post",
    "description": "Write a blog post with title, markdown body, and meta description.",
    "parameters": {
      "type": "object",
      "properties": {
        "title": {"type": "string", "description": "Blog post title"},
        "body": {"type": "string", "description": "Full blog post in markdown"},
        "meta_description": {"type": "string", "description": "SEO meta description, under 160 chars"}
      },
      "required": ["title", "body", "meta_description"]
    }
  }
}
```

### write_instagram_reel
```json
{
  "type": "function",
  "function": {
    "name": "write_instagram_reel",
    "description": "Write an Instagram Reel script with structured sections.",
    "parameters": {
      "type": "object",
      "properties": {
        "hook": {"type": "string", "description": "First 1-2 seconds. Pattern interrupt or bold claim."},
        "setup": {"type": "string", "description": "2-5 seconds. Context for the tip."},
        "value": {"type": "string", "description": "5-25 seconds. The actual advice/content."},
        "cta": {"type": "string", "description": "Last 5 seconds. Follow, comment, share CTA."},
        "caption": {"type": "string", "description": "Post caption with hashtags."}
      },
      "required": ["hook", "setup", "value", "cta", "caption"]
    }
  }
}
```

### write_linkedin_carousel
```json
{
  "type": "function",
  "function": {
    "name": "write_linkedin_carousel",
    "description": "Write a LinkedIn carousel post with slide content.",
    "parameters": {
      "type": "object",
      "properties": {
        "slides": {
          "type": "array",
          "items": {
            "type": "object",
            "properties": {
              "title": {"type": "string"},
              "body": {"type": "string"}
            },
            "required": ["title", "body"]
          },
          "description": "Carousel slides. Slide 1 is the hook. Last slide is summary + CTA."
        },
        "caption": {"type": "string", "description": "Post caption text."}
      },
      "required": ["slides", "caption"]
    }
  }
}
```

All other tool definitions follow the same pattern with appropriate fields per type.

## Prompt Files

12 files in `prompts/types/`, one per content type. Each file contains format-specific writing craft instructions pulled from the marketingskills frameworks.

### File structure
```
prompts/types/
├── blog_post.md
├── linkedin_post.md
├── linkedin_carousel.md
├── instagram_post.md
├── instagram_reel.md
├── instagram_carousel.md
├── x_post.md
├── x_thread.md
├── youtube_script.md
├── youtube_short.md
├── facebook_post.md
└── tiktok_video.md
```

### What each prompt file contains
- Role definition for that specific format
- Structure template (what goes where)
- Hook formulas relevant to that format
- Platform-specific rules (char limits, algorithm tips, engagement signals)
- Quality checklist for that format
- Example structure (not example content)

### What the handler appends (NOT in the prompt file)
- Client profile
- Cornerstone content (for waterfall pieces)
- Topic (for cornerstone pieces)
- Standard anti-AI writing rules
- Rejection reason (if re-generating)

### Example: `prompts/types/x_thread.md`
```
You are writing a Twitter/X thread.

## Thread structure
- Tweet 1: Hook + promise of value. This determines if anyone reads the rest.
- Tweets 2-7: One point per tweet. Each tweet stands alone but builds on the previous.
- Final tweet: Summary + CTA (follow, reply, repost).

## Hook formulas for threads
- "I was wrong about [common belief]. Here's what actually works:"
- "[Result] in [timeframe]. Here's the full breakdown:"
- "Everyone says [X]. The truth is [Y]."
- "[Number] things I learned about [topic] after [credibility builder]:"

## Rules
- Each tweet under 280 characters. Under 100 gets more engagement.
- No filler tweets. Every tweet must add value.
- Don't start with "Thread:" or use number emojis (1️⃣).
- Use plain numbers: "1.", "2.", etc.
- Use the client's voice and language exactly.
- Quote tweets with added insight beat plain retweets.
- Last tweet should include a clear follow CTA.

## Quality check
- Does tweet 1 make you want to read tweet 2?
- Can each tweet stand on its own if quoted?
- Is there a clear takeaway?
- Would you actually engage with this thread?

Call the write_x_thread tool with your tweets array when done.
```

## Data Model

No schema changes needed. `content_pieces.body` stores JSON (currently stores plain text). `platform` + `format` determine which type registry entry to use.

The `body` field for old pieces still works — frontend falls back to plain text display if JSON parsing fails.

## Pipeline Handler Changes

### Piece generation (`streamPiece`)

Current flow:
1. Determine if cornerstone or waterfall
2. Build prompt from inline `cornerstonePrompt()` or `waterfallPrompt()`
3. Register fetch + search tools
4. Stream via `StreamWithTools`

New flow:
1. Look up `ContentType` from registry using `piece.Platform + piece.Format`
2. Load the prompt file
3. Build system prompt: prompt file content + client profile + (cornerstone body if waterfall) + anti-AI rules + rejection reason
4. Register fetch + search tools + that type's write tool
5. Stream via `StreamWithTools`
6. When AI calls the write tool: validate, JSON-marshal, save to `body`, set status `draft`

### Tool call handling

The write tool executor:
```go
func (h *PipelineHandler) handleWriteTool(toolName, args string) (string, error) {
    // Just validate it's valid JSON with required fields
    // Save to content piece body
    // Return confirmation to AI
}
```

The `onToolEvent` callback detects write tool calls and sends the structured data to the frontend so it can render immediately.

### What gets deleted
- `cornerstonePrompt()` method
- `waterfallPrompt()` method
- `platformGuidance` map
- Inline prompt text in the handler

### Improve flow
Same approach — load the type's prompt file, inject current JSON body + user feedback, register the write tool. AI rewrites by calling the tool again.

## Frontend Renderers

The production board card body currently does `{ piece.Body }` as pre-wrapped text. Replace with a JS renderer that:

1. Parses `body` as JSON
2. Checks `platform` + `format`
3. Renders type-specific HTML

### Renderer types

**Simple text** (linkedin_post, instagram_post, facebook_post, x_post):
```html
<div class="content-caption">{caption/text}</div>
<div class="content-hashtags">{hashtags as badges}</div>
<div class="content-instructions text-muted">{image_instructions}</div>
```

**Array types** (x_thread, linkedin_carousel, instagram_carousel):
```html
<div class="content-items">
  <div class="content-item">1. {tweet/slide text}</div>
  <div class="content-item">2. {tweet/slide text}</div>
  ...
</div>
<div class="content-caption">{caption}</div>
```

**Script types** (instagram_reel, youtube_short, tiktok_video):
```html
<div class="content-script">
  <div class="script-section"><strong>Hook:</strong> {hook}</div>
  <div class="script-section"><strong>Setup:</strong> {setup}</div>
  <div class="script-section"><strong>Value:</strong> {value}</div>
  <div class="script-section"><strong>CTA:</strong> {cta}</div>
</div>
<div class="content-caption">{caption}</div>
```

**Blog post:**
```html
<h2 class="content-title">{title}</h2>
<div class="content-body">{body as pre-wrapped markdown}</div>
<div class="content-meta text-muted">Meta: {meta_description}</div>
```

**YouTube script:**
```html
<h2 class="content-title">{title}</h2>
<div class="content-sections">
  <div class="script-section">
    <strong>[{timestamp}] {heading}</strong>
    <p>{content}</p>
    <p class="text-muted">[{notes}]</p>
  </div>
  ...
</div>
```

Rendering happens in JS at page load + after generation completes. Falls back to raw text if JSON parse fails.

## What Gets Removed

- `platformGuidance` map in pipeline handler
- `cornerstonePrompt()` method
- `waterfallPrompt()` method
- Plain text body rendering in pipeline template
- Inline prompt text for waterfall/cornerstone

## What Gets Added

- `internal/content/types.go` — content type registry with tool definitions
- `prompts/types/*.md` — 12 prompt files with format-specific craft instructions
- Type-specific tool executors in pipeline handler
- Frontend JS renderers per content type
- CSS for content type displays (script sections, tweet items, slide cards, etc.)

## What Gets Modified

- `web/handlers/pipeline.go` — use registry for prompt + tool, remove inline prompts
- `web/static/app.js` — add content renderers
- `web/static/style.css` — content type display styles
- `web/templates/pipeline.templ` — render body via JS instead of plain text
