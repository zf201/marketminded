# Brand Setup — Design Spec

## Context

MarketMinded is migrating from Go to Laravel. In the Go app, brand information was scattered across profile sections, voice/tone profiles, context items, and a key-value project settings store. In the Laravel app, teams replace projects as the root entity. Brand setup consolidates all user-provided brand inputs into a single page with clear sections.

This is **step 1** of two: user inputs only. Step 2 (AI agents that process these inputs into a full brand intelligence profile) comes later.

## What This Builds

A dedicated "Brand Setup" page where team owners/admins provide URLs, descriptions, and hints about their brand. Only the homepage URL is required — everything else is optional but helps the AI agents do a better job.

## Route

`/{current_team}/brand` — sits under the existing team-scoped prefix with `auth`, `verified`, and `EnsureTeamMembership` middleware.

Named route: `brand.setup`

## Data Model

Columns added to the `teams` table (no new tables):

| Column | Type | Required | Default | Notes |
|---|---|---|---|---|
| `homepage_url` | `string(255)` | yes (validated in form, nullable in DB) | `null` | Main website URL |
| `blog_url` | `string(255)` | no | `null` | Company blog index page |
| `brand_description` | `text` | no | `null` | Brief description of company, value prop, differentiation |
| `product_urls` | `json` | no | `[]` | Array of URL strings — product pages, about, case studies, docs |
| `competitor_urls` | `json` | no | `[]` | Array of URL strings — competitor websites |
| `style_reference_urls` | `json` | no | `[]` | Array of URL strings — articles/posts to emulate |
| `target_audience` | `text` | no | `null` | Who the content is for |
| `tone_keywords` | `string(255)` | no | `null` | Words describing desired brand voice |
| `content_language` | `string(50)` | no | `'English'` | Output language |

`homepage_url` is nullable in the DB so teams start with no brand setup. The form validates it as required when submitting.

JSON columns store simple string arrays (`["https://...", "https://..."]`), not objects.

## Team Model Changes

- Add all 9 columns to `#[Fillable]`
- Add casts: `product_urls` → `array`, `competitor_urls` → `array`, `style_reference_urls` → `array`

## UI

### Page Layout

Single-page form using Flux Pro components inside the existing `x-pages::settings.layout` wrapper (consistent with team edit page). One save button at the bottom.

### Sections

**1. Company**
- **Homepage URL** (required) — `flux:input` type url. Helper: "Your main website. We'll crawl this to understand your brand."
- **Blog URL** — `flux:input` type url. Helper: "Your blog's index page. Helps us understand your existing content and avoid repetition."
- **Brand Description** — `flux:textarea`. Helper: "A brief description of what your company does, who it serves, and what makes it different. 2-3 sentences is plenty."

**2. Product & Brand Pages**
- Repeatable URL list with add/remove buttons.
- Section helper: "Links to your product pages, about page, case studies, or documentation. These help the AI understand your offerings in depth."

**3. Competitors**
- Repeatable URL list with add/remove buttons.
- Section helper: "Competitor websites. Helps the AI differentiate your positioning and find unique angles for your content."

**4. Style References**
- Repeatable URL list with add/remove buttons.
- Section helper: "Articles or blogs whose writing style you admire — including your own posts if you already have an established style. These guide the AI's tone and writing approach and don't need to be in your industry."

**5. Additional Context**
- **Target Audience** — `flux:textarea`. Helper: "Who are you writing for? e.g., 'CTOs at mid-size SaaS companies' or 'first-time homebuyers in their 30s'"
- **Tone Keywords** — `flux:input`. Helper: "Words that describe how your brand should sound. e.g., 'professional but approachable', 'technical but not jargon-heavy'"
- **Content Language** — `flux:input` with default "English". Helper: "The language your content should be written in."

### Repeatable URL Pattern

Each multi-URL section uses the same Livewire pattern:
- Array of strings as a public property
- "Add URL" button appends an empty string to the array
- Remove button (x icon) splices the entry
- Each URL renders as a `flux:input` type url with `wire:model` bound to the array index

## Sidebar

Add "Brand Setup" link in the main sidebar nav group (under "Platform"), below Dashboard:

```blade
<flux:sidebar.item icon="building-storefront" :href="route('brand.setup', ['current_team' => ...])" :current="request()->routeIs('brand.setup')" wire:navigate>
    {{ __('Brand Setup') }}
</flux:sidebar.item>
```

## Authorization

Uses the existing `update` gate on the Team policy — same as team settings. Owner and Admin can edit. Members see a read-only view (or are redirected — follow existing pattern from team edit page).

## Validation Rules

```php
'homepage_url' => ['required', 'url', 'max:255'],
'blog_url' => ['nullable', 'url', 'max:255'],
'brand_description' => ['nullable', 'string', 'max:5000'],
'product_urls' => ['nullable', 'array', 'max:20'],
'product_urls.*' => ['required', 'url', 'max:255'],
'competitor_urls' => ['nullable', 'array', 'max:20'],
'competitor_urls.*' => ['required', 'url', 'max:255'],
'style_reference_urls' => ['nullable', 'array', 'max:20'],
'style_reference_urls.*' => ['required', 'url', 'max:255'],
'target_audience' => ['nullable', 'string', 'max:5000'],
'tone_keywords' => ['nullable', 'string', 'max:255'],
'content_language' => ['nullable', 'string', 'max:50'],
```

## File Structure

### New Files

```
database/migrations/XXXX_add_brand_setup_to_teams_table.php
resources/views/pages/teams/⚡brand-setup.blade.php
tests/Feature/Teams/BrandSetupTest.php
```

### Modified Files

```
app/Models/Team.php                                  — Add fillable + casts
resources/views/layouts/app/sidebar.blade.php        — Add Brand Setup nav item
routes/web.php                                       — Add brand.setup route
```

## What's NOT in Scope

- AI generation / bootstrap agents (step 2)
- URL validation by crawling (checking if URLs are reachable)
- URL metadata fetching or preview cards
- Per-URL notes
- Audience personas (AI-generated, step 2)
- Voice/tone analysis (AI-generated, step 2)
