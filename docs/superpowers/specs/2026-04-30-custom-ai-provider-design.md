# Custom AI Provider Support

**Date:** 2026-04-30  
**Status:** Approved

## Summary

Add a provider picker (OpenRouter | Custom) to the team AI settings. When Custom is selected, a URL field appears so users can point MarketMinded at any OpenAI-compatible endpoint — enabling direct use of Claude, GPT, Kimi K2.6, GLM 5.1, Ollama Cloud, OpenCode Go, and others.

## Database

Three changes to the `teams` table via a single migration:

1. Rename `openrouter_api_key` → `ai_api_key` (same encrypted cast, same `#[Hidden]` attribute)
2. Add `ai_provider` string column, default `'openrouter'`
3. Add `ai_api_url` nullable string column, default `null`

`null` for `ai_api_url` means "use the provider's default base URL" — no need to store it explicitly for OpenRouter.

## Model (`App\Models\Team`)

- Update `#[Fillable]` to replace `openrouter_api_key` with `ai_api_key`, and add `ai_provider`, `ai_api_url`
- Update `#[Hidden]` to reference `ai_api_key`
- Update `casts()` to encrypt `ai_api_key` instead of `openrouter_api_key`
- Default `ai_provider` → `'openrouter'` in `$attributes`

## Service (`App\Services\OpenRouterClient`)

- Replace the `API_URL` constant with a `$baseUrl` constructor parameter, default `'https://openrouter.ai/api/v1'`
- Add a `$provider` constructor parameter (`'openrouter'|'custom'`), default `'openrouter'`
- All three methods (`chat`, `streamChat`, `streamChatWithTools`) build the endpoint as `$this->baseUrl . '/chat/completions'`

### Request body — required defaults

Every request body must include:

```php
// reasoning_effort: controls thinking depth on reasoning models (o1, o3, DeepSeek-R1, etc.).
// Ignored by non-reasoning models. 'medium' is the safe balanced default.
// Future: make this configurable per team or per call.
'reasoning_effort' => 'medium',
```

When `$this->provider === 'openrouter'` only, also include:

```php
// verbosity: OpenRouter-specific; controls response detail level.
// 'medium' is the default. Future: expose as a team setting.
'verbosity' => 'medium',
```

### Usage tracking

Parse these fields from every usage object and surface them in `ChatResult` / `StreamResult`:

| Field | Source in `usage` |
|---|---|
| `reasoningTokens` | `completion_tokens_details.reasoning_tokens ?? 0` |
| `cacheReadTokens` | `prompt_tokens_details.cached_tokens ?? 0` |
| `cacheWriteTokens` | `prompt_tokens_details.cache_write_tokens ?? 0` |

`cost` is already read with `?? 0`, so it degrades gracefully on custom providers that don't return it.

### Instantiation sites

Both places that `new OpenRouterClient(...)` pass:

```php
baseUrl: $team->ai_api_url ?? 'https://openrouter.ai/api/v1',
provider: $team->ai_provider,
```

### Server tools

`useServerTools` is set to `false` when `$team->ai_provider === 'custom'`. This disables `openrouter:web_search` and `openrouter:datetime`, which are OpenRouter-specific and would error on other providers. Web search for custom providers will be addressed separately.

Concretely:
- `BaseAgent::llmCall()` — passes `useServerTools: $team->ai_provider !== 'custom' && $this->useServerTools()`
- Chat Livewire component — same gate before passing `useServerTools` to `streamChatWithTools`

## UI (`resources/views/pages/teams/⚡edit.blade.php`)

### New Livewire properties

```php
public string $aiProvider = 'openrouter';
public string $aiApiKey = '';
public string $aiApiUrl = '';
```

Populated in `mount()` from `$team->ai_provider`, `$team->ai_api_key`, `$team->ai_api_url`.

### Form changes

**Provider picker** — `flux:radio-group` at the top of the AI settings section:
- Options: "OpenRouter" (value: `openrouter`), "Custom" (value: `custom`)
- Wire to `$aiProvider`

**API Key field** — always visible:
- Label: "OpenRouter API Key" when `$aiProvider === 'openrouter'`, "API Key" when custom
- Placeholder: `sk-or-...` when openrouter, empty when custom
- Wire to `$aiApiKey`

**API URL field** — visible only when `$aiProvider === 'custom'`:
- Label: "API Base URL"
- Placeholder: `https://api.moonshot.ai/v1`
- Description: "Use MarketMinded with any OpenAI-compatible provider — Claude, GPT, Kimi K2.6, GLM 5.1, Ollama Cloud, OpenCode Go, and more."
- Wire to `$aiApiUrl`

**Model fields** — descriptions updated to remove OpenRouter-specific model ID examples and use generic examples valid for both OpenRouter and direct providers.

### Validation (`updateAiSettings`)

```php
'aiProvider'  => ['required', 'in:openrouter,custom'],
'aiApiKey'    => ['nullable', 'string', 'max:255'],
'aiApiUrl'    => ['required_if:aiProvider,custom', 'nullable', 'url', 'max:500'],
'fastModel'   => ['required', 'string', 'max:255'],
'powerfulModel' => ['required', 'string', 'max:255'],
```

Saves to `ai_provider`, `ai_api_key`, `ai_api_url`, `fast_model`, `powerful_model`.

## Out of scope

- Web search for custom providers (separate step 2)
- Per-provider saved API keys (single key field, switching providers requires re-entry)
