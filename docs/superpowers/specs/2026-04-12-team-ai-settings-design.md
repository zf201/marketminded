# Team AI Settings — Design Spec

## Context

MarketMinded uses OpenRouter for all AI/LLM calls. The Go app stored model settings globally. In the Laravel app, these become per-team settings so each team can bring their own API key and choose their preferred models.

## Changes

### Database

Add 3 columns to the `teams` table via migration:

- `openrouter_api_key` — text, nullable, stored encrypted via Laravel's `encrypted` cast
- `fast_model` — string, not null, default `deepseek/deepseek-v3.2:nitro`
- `powerful_model` — string, not null, default `deepseek/deepseek-v3.2:nitro`

### Model

Update `Team` model:
- Add columns to `$fillable`
- Add `openrouter_api_key` to `$hidden`
- Cast `openrouter_api_key` as `encrypted`

### UI

Add "AI Settings" section to the existing team edit page (`resources/views/pages/teams/⚡edit.blade.php`), below member management:

- **OpenRouter API Key** — `flux:input` type password with `viewable` prop. Label: "OpenRouter API Key". Description: "Your team's API key for AI features."
- **Fast Model** — `flux:input` type text. Label: "Fast Model". Placeholder: `deepseek/deepseek-v3.2:nitro`. Description hints: `x-ai/grok-4.1-fast`, `anthropic/claude-sonnet-4.6`, `deepseek/deepseek-v3.2:nitro`
- **Powerful Model** — `flux:input` type text. Label: "Powerful Model". Placeholder: `deepseek/deepseek-v3.2:nitro`. Same description hints.
- **Save button** for the AI settings section

The section is only visible to users with `canUpdateTeam` permission (Owner/Admin).

### Sidebar Quick Access

Add a link in the sidebar footer (where repository/documentation links are) pointing to the current team's settings page.

### Authorization

Same as existing team update — `TeamPermission::UpdateTeam` required. No new permissions needed.

### Defaults

| Setting | Default |
|---------|---------|
| `openrouter_api_key` | null (must be provided by team) |
| `fast_model` | `deepseek/deepseek-v3.2:nitro` |
| `powerful_model` | `deepseek/deepseek-v3.2:nitro` |

### What's NOT in scope

- API key validation (calling OpenRouter to verify) — not now
- Model list fetched from OpenRouter API — free text input only
- Temperature setting — removed
- Claim verifier toggle — will come with pipeline port
- Per-project model overrides — will come with project settings port
