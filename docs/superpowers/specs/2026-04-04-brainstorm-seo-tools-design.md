# Brainstorm SEO Tools — Design Spec

## Overview

Add three DataForSEO-powered tools to the brainstorm agent so it can ground content ideation in real SEO data: keyword metrics, keyword suggestions, and domain keyword analysis. The brainstorm agent also gets `web_search` and `fetch_url` (already implemented, just not wired up for brainstorm's tool executor properly — they ARE already available but we formalize them in context).

## Goals

- Help users discover topics with real search demand during brainstorming
- Keep API costs low with hard caps on results and tool call budgets
- Graceful degradation: if no DataForSEO credentials, brainstorm works as before

## Non-Goals

- Post-publish rank tracking
- Content scoring/optimization
- Caching layer (usage will be low)
- UI changes

---

## Tools

### 1. `keyword_research`

Get search metrics for specific keywords.

**Input schema:**
```json
{
  "type": "object",
  "properties": {
    "keywords": {
      "type": "array",
      "items": { "type": "string" },
      "maxItems": 5,
      "description": "Keywords to look up (max 5)"
    },
    "location": {
      "type": "string",
      "description": "Target country, e.g. 'United States'. Defaults to United States."
    }
  },
  "required": ["keywords"]
}
```

**Backend:** `POST https://api.dataforseo.com/v3/keywords_data/google_ads/search_volume/live`

**Request body:**
```json
[{
  "keywords": ["keyword1", "keyword2"],
  "location_name": "United States",
  "language_name": "English"
}]
```

**Output (formatted for agent):** For each keyword:
- Keyword, monthly search volume, CPC, competition level (LOW/MEDIUM/HIGH), competition index (0-100)

**Cost control:** Max 5 keywords per call. One API request regardless of keyword count (1-1000 cost the same, but we cap at 5 to keep agent from dumping large lists).

---

### 2. `keyword_suggestions`

Discover related keywords and questions from a seed keyword.

**Input schema:**
```json
{
  "type": "object",
  "properties": {
    "seed_keyword": {
      "type": "string",
      "description": "The seed keyword to find related terms for"
    },
    "location": {
      "type": "string",
      "description": "Target country. Defaults to United States."
    }
  },
  "required": ["seed_keyword"]
}
```

**Backend:** `POST https://api.dataforseo.com/v3/dataforseo_labs/google/related_keywords/live`

**Request body:**
```json
[{
  "keyword": "seed keyword",
  "location_name": "United States",
  "language_name": "English",
  "depth": 1,
  "limit": 10,
  "include_serp_info": false,
  "include_clickstream_data": false
}]
```

- `depth: 1` keeps it shallow (fewer results, lower cost)
- `limit: 10` caps returned keywords
- `include_serp_info: false` and `include_clickstream_data: false` to avoid cost multipliers

**Output (formatted for agent):** Top 10 related keywords, each with:
- Keyword, search volume, keyword difficulty (0-100), CPC, competition level

---

### 3. `domain_keywords`

See what keywords a domain ranks for — useful for competitive analysis.

**Input schema:**
```json
{
  "type": "object",
  "properties": {
    "domain": {
      "type": "string",
      "description": "The domain to analyze, e.g. 'competitor.com'"
    },
    "location": {
      "type": "string",
      "description": "Target country. Defaults to United States."
    }
  },
  "required": ["domain"]
}
```

**Backend:** `POST https://api.dataforseo.com/v3/dataforseo_labs/google/ranked_keywords/live`

**Request body:**
```json
[{
  "target": "competitor.com",
  "location_name": "United States",
  "language_name": "English",
  "limit": 10,
  "include_clickstream_data": false,
  "item_types": ["organic"],
  "order_by": ["keyword_data.keyword_info.search_volume,desc"]
}]
```

- `limit: 10` — only top 10 keywords by volume
- `item_types: ["organic"]` — skip paid/featured snippet data
- Ordered by search volume descending to surface the most valuable keywords
- `include_clickstream_data: false` to avoid double cost

**Output (formatted for agent):** Top 10 keywords the domain ranks for, each with:
- Keyword, ranking position, search volume, keyword difficulty, CPC, the URL that ranks

---

## Cost Guardrails

### Hard limits in code
- `keyword_research`: max 5 keywords per call
- `keyword_suggestions`: max 10 results, depth 1, no clickstream
- `domain_keywords`: max 10 results, no clickstream

### Tool call budget in prompt
- Max **5 SEO tool calls per brainstorm session** (across all three tools)
- Added to the brainstorm system prompt: "SEO tools (keyword_research, keyword_suggestions, domain_keywords) cost real money per call. You have a budget of 5 SEO tool calls per session. Use them strategically — batch keywords into single calls, use your existing knowledge first, validate with SEO data only when it adds value. Don't call keyword_research or keyword_suggestions repeatedly for similar queries."

### Graceful degradation
- If `DATAFORSEO_LOGIN` or `DATAFORSEO_PASSWORD` env vars are not set, SEO tools are simply not registered. Brainstorm works as before with just web_search + fetch_url.

---

## Implementation

### New files

**`internal/seo/client.go`** — DataForSEO HTTP client
- HTTP Basic Auth (login:password)
- Base URL: `https://api.dataforseo.com`
- `SearchVolume(ctx, keywords []string, location string) ([]KeywordMetric, error)`
- `RelatedKeywords(ctx, seed string, location string) ([]KeywordSuggestion, error)`
- `RankedKeywords(ctx, domain string, location string) ([]RankedKeyword, error)`
- Standard error handling: check `status_code` in response, return meaningful errors
- Default location: "United States", default language: "English"

**`internal/seo/types.go`** — Request/response structs
- DataForSEO API request/response wrappers
- Clean output types: `KeywordMetric`, `KeywordSuggestion`, `RankedKeyword`

**`internal/tools/seo.go`** — Tool definitions and executors
- `NewKeywordResearchTool() ai.Tool`
- `NewKeywordSuggestionsTool() ai.Tool`
- `NewDomainKeywordsTool() ai.Tool`
- `NewSEOExecutor(client *seo.Client) func(ctx, name, args string) (string, error)`
- Each executor parses args, calls seo client, formats results as readable text for the agent

### Modified files

**`internal/config/config.go`**
- Add `DataForSEOLogin string` and `DataForSEOPassword string` fields
- Env vars: `DATAFORSEO_LOGIN`, `DATAFORSEO_PASSWORD`

**`cmd/server/main.go`**
- Create `seo.Client` if credentials are configured
- Pass SEO client to brainstorm handler

**`web/handlers/brainstorm.go`**
- Accept optional `*seo.Client` in constructor
- If SEO client is present, add three SEO tools to `toolList` and wire up executor cases
- Add SEO cost guardrail text to system prompt

**`web/handlers/settings.go`**
- Add `dataforseo_login` and `dataforseo_password` settings to `save()` and `show()`

**`web/templates/settings.templ`** + **`web/templates/settings_templ.go`**
- Add `DataForSEOLogin` and `DataForSEOPassword` fields to `SettingsData`
- Add a new "SEO / DataForSEO" section to the settings form with login and password inputs
- Password field uses `type="password"` for the API password

**`cmd/server/main.go`**
- SEO client credential resolution: DB settings > env vars > disabled
- When credentials change in settings, the SEO client should pick them up (resolve from DB at call time, similar to model resolvers)

---

## Authentication

DataForSEO uses HTTP Basic Auth. Credentials can be configured two ways (DB settings take precedence):

1. **Settings UI** — "SEO / DataForSEO" section with login and password fields (stored in DB via `settings` table)
2. **Environment variables** — `DATAFORSEO_LOGIN` and `DATAFORSEO_PASSWORD` as fallback

The SEO client resolves credentials at call time (not at startup) so that changes in the settings UI take effect immediately without restart.

---

## Testing approach

- Unit test the seo client with a mock HTTP server returning sample DataForSEO responses
- Unit test tool executors with sample args
- Manual E2E: run a brainstorm session, ask the agent about SEO opportunities for a topic, verify it calls the tools and returns sensible data
