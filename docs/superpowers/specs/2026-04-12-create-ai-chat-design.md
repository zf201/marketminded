# Create (AI Chat) — Design Spec

## Overview

Add a persistent, streaming AI chat page to MarketMinded. This is a generic LLM assistant scoped to the current team, using the team's OpenRouter API key and configured models. Named "Create" internally and in the UI.

This is the foundation layer. Future iterations will add brand context (positioning, personas, voice profile) and tool-calling capabilities.

## Data Model

### `conversations` table

| Column       | Type                | Notes                              |
|-------------|---------------------|------------------------------------|
| id          | bigIncrements       | Primary key                        |
| team_id     | foreignId           | cascadeOnDelete                    |
| user_id     | foreignId           | cascadeOnDelete                    |
| title       | string(255)         | Auto-generated from first user message (first ~80 chars) |
| created_at  | timestamp           |                                    |
| updated_at  | timestamp           |                                    |

**Indexes:** `[team_id, updated_at]` for listing conversations.

### `messages` table

| Column          | Type            | Notes                                  |
|----------------|-----------------|----------------------------------------|
| id             | bigIncrements   | Primary key                            |
| conversation_id | foreignId      | cascadeOnDelete                        |
| role           | string(20)      | 'user' or 'assistant'                  |
| content        | text            | Message body                           |
| model          | string(100), nullable | Model used (assistant messages only) |
| input_tokens   | integer, default 0 | Token usage                         |
| output_tokens  | integer, default 0 | Token usage                         |
| cost           | decimal(10,6), default 0 | Cost from OpenRouter             |
| created_at     | timestamp       |                                        |

No `updated_at` — messages are immutable once written.

### Eloquent Models

**Conversation:**
- `belongsTo` Team, User
- `hasMany` Messages (ordered by created_at asc)
- Scoped to current team via route model binding

**Message:**
- `belongsTo` Conversation
- No timestamps trait — only `created_at` via `$table->timestamp('created_at')`

## Streaming

### OpenRouterClient changes

Add a `streamChat()` method alongside the existing `chat()` method:

```php
public function streamChat(string $systemPrompt, array $messages): Generator
```

- Sets `'stream' => true` in the OpenRouter request
- Uses Laravel HTTP client's `withResponseStreaming()` or `curl` streaming
- Yields content chunks as they arrive (parsing SSE `data:` lines)
- After stream completes, parses the final `[DONE]` usage data for token/cost tracking
- Returns a `StreamResult` object (or similar) with accumulated usage stats after the generator is exhausted

No tool calling in streaming mode for now — this is a plain chat.

### Livewire streaming

Use Livewire's `wire:stream` to push content chunks to the browser:

1. User submits message via Livewire action `sendMessage()`
2. Component saves user message to DB
3. Component calls `$this->stream('assistant-response', $chunk)` in a loop
4. After streaming completes, save the full assistant message to DB with usage stats
5. Frontend appends chunks to a target element via `wire:stream="assistant-response"`

## UI

### Page location

- Route: `/{current_team}/create` (name: `create`)
- Sidebar item: "Create" with `chat-bubble-left-right` icon, placed after "AI Operations"

### Layout

The page uses the standard `layouts.app` (sidebar layout). Content fills the full width of `<flux:main>`.

```
+--------------------------------------------------+
|  Create                           [New chat btn]  |
+--------------------------------------------------+
|                                                    |
|  (messages scroll area)                            |
|                                                    |
|  [User message bubble]                             |
|                                                    |
|          [Assistant message bubble]                |
|                                                    |
|  [User message bubble]                             |
|                                                    |
|          [Assistant streaming...]                  |
|                                                    |
+--------------------------------------------------+
|  [Input field                        ] [Send btn] |
+--------------------------------------------------+
```

### Components and styling

- **Page heading:** `flux:heading size="xl"` with "Create", plus a "New conversation" button
- **Messages area:** Scrollable div (`overflow-y-auto`, `flex-1`) with `flex flex-col` to push content to bottom
- **User messages:** Right-aligned, subtle background (`bg-zinc-100 dark:bg-zinc-700`), rounded
- **Assistant messages:** Left-aligned, no background, just text
- **Input area:** Fixed to bottom of the chat area. `flux:input` with a `flux:button` icon="paper-airplane" to send. Submit on Enter, Shift+Enter for newlines.
- **Streaming indicator:** Pulsing cursor or `flux:icon.loading` at the end of the assistant's in-progress message
- **Empty state:** When no conversation exists, centered prompt like "What would you like to create?"

### Behavior

- On page load: show the most recent conversation for this team+user, or empty state
- Sending a message with no active conversation creates one (title = first ~80 chars of first message)
- Auto-scroll to bottom on new messages and during streaming
- Input disabled while assistant is responding
- "New conversation" button starts a fresh conversation

## API Integration

### System prompt

Minimal for now:

```
You are a helpful AI assistant.
```

Future iterations will inject brand context here.

### Message history

Send the full conversation history to OpenRouter on each request. The messages array is built from the `messages` table:

```php
$apiMessages = $conversation->messages->map(fn ($m) => [
    'role' => $m->role,
    'content' => $m->content,
])->toArray();
```

### Model selection

Use the team's `fast_model` for chat responses. This keeps latency low for conversational use.

### Cost tracking

After each assistant response completes, store `input_tokens`, `output_tokens`, and `cost` on the message record. This keeps per-message cost visibility without needing the `ai_tasks` system (which is for background jobs).

## Route and Middleware

```php
// In the existing team-scoped route group:
Route::livewire('create', 'pages::teams.create')->name('create');
```

Same middleware stack as other team pages: `auth`, `verified`, `EnsureTeamMembership`.

## Files to create

| File | Purpose |
|------|---------|
| `database/migrations/..._create_conversations_table.php` | Conversations schema |
| `database/migrations/..._create_messages_table.php` | Messages schema |
| `app/Models/Conversation.php` | Eloquent model |
| `app/Models/Message.php` | Eloquent model |
| `resources/views/pages/teams/⚡create.blade.php` | Volt page component |

## Files to modify

| File | Change |
|------|--------|
| `app/Services/OpenRouterClient.php` | Add `streamChat()` method |
| `app/Models/Team.php` | Add `conversations()` relationship |
| `resources/views/layouts/app/sidebar.blade.php` | Add "Create" nav item |
| `routes/web.php` | Add create route |

## What's explicitly out of scope

- Conversation list/switcher UI (future)
- Brand context injection (future)
- Tool calling in chat (future)
- File uploads (future)
- Markdown rendering (future — plain text for now)
- Message editing or deletion
- Sharing conversations
