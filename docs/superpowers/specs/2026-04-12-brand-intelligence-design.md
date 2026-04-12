# Brand Intelligence — Design Spec

## Context

This is **sub-project 1 of 2** for Brand Intelligence. It covers the page, data model, and UI (read/edit/delete). Sub-project 2 adds the AI bootstrap pipeline (OpenRouter integration, agents, streaming).

The Brand Setup page (user inputs) is already built. Brand Intelligence displays the AI-generated insights derived from those inputs. The two pages are linked in the sidebar: Brand Setup → Brand Intelligence.

## What This Builds

A "Brand Intelligence" page showing AI-generated brand positioning, audience personas, and voice profile. Users can view, edit, and delete generated content. The "Generate" and "Regenerate" buttons are present in the UI but non-functional until sub-project 2.

## Route

`/{current_team}/intelligence` — same middleware group as Brand Setup (`auth`, `verified`, `EnsureTeamMembership`).

Named route: `brand.intelligence`

## Data Model

Three new tables. Three new Eloquent models.

### `brand_positionings` (has-one per team)

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigIncrements | no | PK |
| `team_id` | foreignId | no | unique, cascades on delete |
| `value_proposition` | text | yes | One-liner: what you do and why it matters |
| `target_market` | text | yes | Who the product is for |
| `differentiators` | text | yes | What sets you apart |
| `core_problems` | text | yes | What pain points the product addresses |
| `products_services` | text | yes | What you sell |
| `primary_cta` | text | yes | What action you want readers to take |
| `timestamps` | | | created_at, updated_at |

### `audience_personas` (has-many per team)

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigIncrements | no | PK |
| `team_id` | foreignId | no | cascades on delete |
| `label` | string | no | Persona name, e.g., "The Overwhelmed Engineering Lead" |
| `description` | text | yes | Who they are |
| `pain_points` | text | yes | What problems they face |
| `push` | text | yes | What drives them to change |
| `pull` | text | yes | What attracts them to a solution |
| `anxiety` | text | yes | What holds them back |
| `role` | string | yes | Job title/role |
| `sort_order` | integer | no | Default 0, for UI ordering |
| `timestamps` | | | created_at, updated_at |

### `voice_profiles` (has-one per team)

| Column | Type | Nullable | Notes |
|---|---|---|---|
| `id` | bigIncrements | no | PK |
| `team_id` | foreignId | no | unique, cascades on delete |
| `voice_analysis` | text | yes | Brand personality, formality, warmth |
| `content_types` | text | yes | Content approaches (educational, how-to, etc.) |
| `should_avoid` | text | yes | Words, phrases, tones to never use |
| `should_use` | text | yes | Characteristic vocabulary and patterns |
| `style_inspiration` | text | yes | Writing patterns from reference URLs |
| `preferred_length` | integer | no | Target word count, default 1500 |
| `timestamps` | | | created_at, updated_at |

## Models

### `App\Models\BrandPositioning`

- Fillable: all 6 text fields
- Belongs to Team
- Team has-one BrandPositioning

### `App\Models\AudiencePersona`

- Fillable: label, description, pain_points, push, pull, anxiety, role, sort_order
- Belongs to Team
- Team has-many AudiencePersonas

### `App\Models\VoiceProfile`

- Fillable: all 5 text fields + preferred_length
- Belongs to Team
- Team has-one VoiceProfile

### Team model additions

Add three relationship methods to `App\Models\Team`:

```php
public function brandPositioning(): HasOne
public function audiencePersonas(): HasMany
public function voiceProfile(): HasOne
```

## UI

### Page Layout

Uses `flux:main container` with the two-column Flux settings pattern (heading left w-80, content right flex-1, `flux:separator variant="subtle"` between sections). Same layout as the Brand Setup page.

### Prerequisite Validation

Before showing content, check:
1. `$team->homepage_url` exists → if not, link to Brand Setup
2. `$team->openrouter_api_key` exists → if not, link to Team Settings

Show a `flux:callout` with variant warning listing what's missing, with links to the relevant pages.

### UI States

**State 1: Missing prerequisites**
- `flux:callout` warning at top
- No generate button
- No content sections

**State 2: Prerequisites met, no data generated**
- Bootstrap CTA card: "Ready to analyze your brand..." with "Generate Brand Intelligence" button (disabled/placeholder in sub-project 1)

**State 3: Data exists**
- Three sections with content
- Bootstrap CTA hidden

### Section 1: Positioning

**Read mode (default):** Each of the 6 fields displayed with an uppercase label and text content below. Right-aligned "Regenerate" (disabled) and "Edit" buttons at bottom.

**Edit mode:** Same fields as `flux:textarea` inputs with labels. "Save" and "Cancel" buttons. Save updates the `brand_positionings` row. Cancel reverts to read mode.

### Section 2: Audience Personas

**Read mode:** Each persona as a card (`flux:card`) showing:
- Header: label (bold) + role (muted)
- Description text
- Grid of pain_points, push, pull, anxiety fields with uppercase labels
- Edit (pencil) and Delete (x) icon buttons per card

Below cards: "Regenerate all" (disabled) and "Add persona" buttons.

**Edit persona (modal):** `flux:modal` with form fields for all 7 persona fields. Save updates the row, cancel closes modal.

**Add persona (modal):** Same modal but creates a new row.

**Delete persona:** `flux:modal` confirmation dialog, then deletes the row.

### Section 3: Voice & Tone

**Read mode:** Each of the 6 fields displayed with uppercase label and text content. Preferred length shown as "Target: 1,500 words". Right-aligned "Regenerate" (disabled) and "Edit" buttons.

**Edit mode:** Text fields as `flux:textarea`, preferred_length as `flux:input` type number. Save/Cancel buttons.

## Sidebar

Add "Brand Intelligence" link below Brand Setup:

```blade
<flux:sidebar.item icon="sparkles" :href="route('brand.intelligence')" :current="request()->routeIs('brand.intelligence')" wire:navigate>
    {{ __('Brand Intelligence') }}
</flux:sidebar.item>
```

## Authorization

Uses the existing `update` gate on Team policy for edit/delete operations. All team members can view. Only Owner/Admin can edit, delete, or trigger generation.

## Validation Rules

### Positioning (on save)
All 6 fields: `['nullable', 'string', 'max:10000']`

### Persona (on save)
- `label`: `['required', 'string', 'max:255']`
- `description`, `pain_points`, `push`, `pull`, `anxiety`: `['nullable', 'string', 'max:10000']`
- `role`: `['nullable', 'string', 'max:255']`
- `sort_order`: `['integer', 'min:0']`

### Voice Profile (on save)
- Text fields: `['nullable', 'string', 'max:10000']`
- `preferred_length`: `['required', 'integer', 'min:100', 'max:10000']`

## File Structure

### New Files

```
database/migrations/XXXX_create_brand_positionings_table.php
database/migrations/XXXX_create_audience_personas_table.php
database/migrations/XXXX_create_voice_profiles_table.php
app/Models/BrandPositioning.php
app/Models/AudiencePersona.php
app/Models/VoiceProfile.php
resources/views/pages/teams/⚡brand-intelligence.blade.php
tests/Feature/Teams/BrandIntelligenceTest.php
```

### Modified Files

```
app/Models/Team.php                                  — Add 3 relationship methods
resources/views/layouts/app/sidebar.blade.php        — Add Brand Intelligence nav item
routes/web.php                                       — Add brand.intelligence route
```

## What's NOT in Scope (sub-project 2)

- AI generation / bootstrap pipeline
- OpenRouter API integration
- Regenerate functionality (buttons present but disabled)
- Streaming responses
- URL crawling
- "Generate Brand Intelligence" button functionality
