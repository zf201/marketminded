# Writer Agents Refactor Design

## Goal

Refactor the writer chat from a single-LLM-does-everything model to an orchestrator + sub-agents architecture. Each sub-agent is a focused LLM call with a narrow prompt, a fit-for-purpose tool allowlist, and a structured output that writes its slice of a shared `Brief`. The orchestrator chat narrates progress, dispatches sub-agent tools, and reasons about state from a compact brief-status summary — never the full payloads.

This addresses two concrete problems with the current writer:
1. **Context bloat.** A single failed run on `dev-chatexperiment` consumed 280K tokens because the orchestrating model carried a 20K writing-rules prompt plus accumulating tool outputs and error retries on every iteration.
2. **No separation of concerns.** One model did research judgment, editorial judgment, prose writing, and orchestration. Each task wants a different prompt and (later) different model selection.

The architecture mirrors the Go pipeline (`internal/pipeline/steps/*.go`) — same explicit dependency model, same brief-as-blackboard pattern, wrapped in the existing chat UX.

**MVP scope:** Research, Editor, Writer, Proofread. The brief schema, agent abstraction, and orchestrator design are explicitly built so that AudiencePicker, BrandEnricher, and StyleReference (PR 2) plug in cleanly with no changes to existing agents.

## Architecture

The existing `writer` chat type stays. The orchestrator chat (Livewire `⚡create-chat.blade.php`) calls four sub-agent tools. Each tool handler is a thin shell that wraps an `Agent` class, runs it, persists results to the conversation's `brief`, and returns a small structured JSON to the orchestrator.

The chat UX (Topic picker, mode tiles, command interception, content piece cards, mode badge) is unchanged. Only the internal tool handlers and the orchestrator prompt change.

## Tech Stack

- Laravel 13 / Livewire / Volt
- PostgreSQL 17 (sail), JSONB column for `brief`
- OpenRouter via existing `OpenRouterClient::streamChatWithTools()` and the non-streaming `chat()` method (sub-agents use the non-streaming path; orchestrator continues to stream)
- Pest

**Spec context:** `docs/superpowers/specs/2026-04-14-writer-blog-agent-design.md` (the original writer feature this refactors)

---

## Data Model

### `conversations.brief` JSONB column

Add via migration:

```php
$table->jsonb('brief')->default('{}');
```

Schema (slots are nullable; sub-agents add their own slot):

```jsonc
{
  "topic": {                         // copied from $conversation->topic on first orchestrator turn
    "id": 6,
    "title": "...",
    "angle": "...",
    "sources": ["...", "..."]
  },
  "research": {                      // ResearchAgent output
    "topic_summary": "2-3 sentences",
    "claims": [
      { "id": "c1", "text": "...", "type": "stat", "source_ids": ["s1"] }
    ],
    "sources": [
      { "id": "s1", "url": "...", "title": "..." }
    ]
  },
  "outline": {                       // EditorAgent output
    "angle": "core narrative",
    "target_length_words": 1500,
    "sections": [
      { "heading": "...", "purpose": "...", "claim_ids": ["c1", "c2"] }
    ]
  },
  "content_piece_id": 42             // WriterAgent: ID of the created ContentPiece
}
```

PR 2 will add `audience`, plus extend `research` to allow appended brand claims (BrandEnricher), plus `style_reference`. WriterAgent reads any of these new slots if present, ignores them otherwise.

### `Brief` value object

Lives at `app/Services/Writer/Brief.php`. Wraps the JSONB blob with typed accessors so callers don't index into raw arrays.

```php
final class Brief
{
    private function __construct(private array $data) {}

    public static function fromJson(array $json): self
    {
        return new self($json);
    }

    public function toJson(): array
    {
        return $this->data;
    }

    public function topic(): ?array            { return $this->data['topic'] ?? null; }
    public function research(): ?array         { return $this->data['research'] ?? null; }
    public function outline(): ?array          { return $this->data['outline'] ?? null; }
    public function contentPieceId(): ?int     { return $this->data['content_piece_id'] ?? null; }

    public function hasTopic(): bool           { return $this->topic() !== null; }
    public function hasResearch(): bool        { return $this->research() !== null; }
    public function hasOutline(): bool         { return $this->outline() !== null; }
    public function hasContentPiece(): bool    { return $this->contentPieceId() !== null; }

    public function withTopic(array $topic): self           { return $this->with('topic', $topic); }
    public function withResearch(array $research): self     { return $this->with('research', $research); }
    public function withOutline(array $outline): self       { return $this->with('outline', $outline); }
    public function withContentPieceId(int $id): self       { return $this->with('content_piece_id', $id); }

    /** Compact one-line-per-slot summary fed into the orchestrator's system prompt. */
    public function statusSummary(): string;

    private function with(string $key, mixed $value): self
    {
        $copy = $this->data;
        $copy[$key] = $value;
        return new self($copy);
    }
}
```

`statusSummary()` example output:

```
research: ✓ (13 claims, 8 sources)
outline: ✓ (5 sections, ~1500 words)
content_piece: ✗
```

When a slot is missing it shows `✗`. When present, a one-line factual count — no payload contents. Total summary stays under 200 chars even with all four slots filled, so the orchestrator's per-turn context cost is bounded.

---

## Agent Abstraction

### Interface and base class

```php
namespace App\Services\Writer;

interface Agent
{
    public function execute(Brief $brief, Team $team): AgentResult;
}

readonly class AgentResult
{
    private function __construct(
        public string $status,                   // 'ok' | 'error'
        public ?Brief $brief,
        public ?array $cardPayload,              // small UI payload for inline rendering
        public ?string $summary,                 // one-line for orchestrator's tool-result JSON
        public ?string $errorMessage,
    ) {}

    public static function ok(Brief $brief, array $cardPayload, string $summary): self
    {
        return new self('ok', $brief, $cardPayload, $summary, null);
    }

    public static function error(string $message): self
    {
        return new self('error', null, null, null, $message);
    }
}

abstract class BaseAgent implements Agent
{
    abstract protected function systemPrompt(Brief $brief, Team $team): string;
    abstract protected function submitToolSchema(): array;
    abstract protected function additionalTools(): array;
    abstract protected function model(Team $team): string;
    abstract protected function temperature(): float;
    abstract protected function validate(array $payload): ?string;
    abstract protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief;
    abstract protected function buildCard(array $payload): array;
    abstract protected function buildSummary(array $payload): string;

    final public function execute(Brief $brief, Team $team): AgentResult
    {
        // 1. Build messages: [{ role: 'system', content: $this->systemPrompt(...) }]
        //    Sub-agents work single-shot — no user turn needed; the system prompt
        //    contains all input.
        // 2. Call OpenRouterClient::chat() with the submit_* tool + additionalTools(),
        //    tool_choice='auto' (the LLM is told it MUST use the submit tool to deliver).
        // 3. Walk the response for the submit_* tool call. If absent, return error.
        // 4. Run validate($payload). If non-null, return error with that message.
        // 5. Run applyToBrief() to produce the new Brief.
        // 6. Return AgentResult::ok with the new brief, card payload, and summary.
    }
}
```

Constructor receives an optional `?string $extraContext = null` — when the orchestrator retries a tool, it can pass guidance that gets appended to the system prompt under an `## Orchestrator guidance for this attempt` heading.

### Concrete agents

Each lives at `app/Services/Writer/Agents/{Name}Agent.php`. Each is small (~80-150 lines) — just the prompt builder, tool schema, validation rules, and brief writer. No LLM-call code (lives in `BaseAgent`).

---

## The Sub-Agents (MVP)

### ResearchAgent

| | |
|---|---|
| **Reads from brief** | `topic` (title, angle) |
| **Tools** | `submit_research`, server-side web_search (always passed by `OpenRouterClient`) |
| **Model** | `$team->fast_model` |
| **Temperature** | 0.4 |
| **Submits** | `{ topic_summary, claims: [{id, text, type, source_ids}], sources: [{id, url, title}] }` |
| **Validates** | At least 3 claims; each claim cites at least one existing source; claim and source IDs are unique |
| **Writes** | `brief.research` |
| **Card** | "Gathered N claims from M sources" |

System prompt is short (~600 words). Tells the LLM to do 5-8 web searches focused on the topic angle, extract 8-15 verifiable claims with source attribution, and submit via `submit_research`. Refuses to submit fewer than 3 claims. Includes brand profile for context.

### EditorAgent

| | |
|---|---|
| **Reads from brief** | `topic`, `research` (claims and sources) |
| **Tools** | `submit_outline` only |
| **Model** | `$team->fast_model` |
| **Temperature** | 0.5 |
| **Submits** | `{ angle, target_length_words, sections: [{heading, purpose, claim_ids}] }` |
| **Validates** | At least 2 sections; every `claim_id` referenced exists in `brief.research.claims` |
| **Writes** | `brief.outline` |
| **Card** | "Outline ready · N sections · ~K words" with expandable section list |

System prompt is short (~500 words). Instructs the LLM to read the claims block, find the strongest narrative angle, build 4-7 sections, and reference claim IDs per section. Brand profile included for voice context.

### WriterAgent

| | |
|---|---|
| **Reads from brief** | `topic`, `research`, `outline` |
| **Structural gate** | Returns error if `!$brief->hasResearch() || !$brief->hasOutline()` — the orchestrator should never reach here, but the gate enforces it |
| **Tools** | `submit_blog_post` only |
| **Model** | `$team->powerful_model` |
| **Temperature** | 0.6 |
| **Submits** | `{ title, body }` (markdown) |
| **Validates** | Non-empty title; body length ≥ 800 words (lower bound — pieces typically run longer) |
| **Writes** | Creates `ContentPiece` via `saveSnapshot()`; sets `brief.content_piece_id`; marks the linked Topic as `status='used'` |
| **Card** | Existing "Draft created · v1" content piece card |

System prompt is long (~3K words) — this is where the writing rules from the original `writerPrompt` move: 1200-2000 words target, banned words list, anti-AI prose, headline formulas, "every fact must trace to a claim_id", brand voice instructions. The good/bad function-call examples stay with the orchestrator since they apply to orchestration; the writer's prompt focuses on prose craft.

### ProofreadAgent

| | |
|---|---|
| **Reads from brief** | `content_piece_id`; loads the piece and feeds title/body to the LLM. Receives `feedback` arg from orchestrator. |
| **Structural gate** | Returns error if `!$brief->hasContentPiece()` |
| **Tools** | `submit_revision` only |
| **Model** | `$team->fast_model` |
| **Temperature** | 0.4 |
| **Submits** | `{ title, body, change_description }` |
| **Validates** | Non-empty title and body; `change_description` non-empty |
| **Writes** | Calls `$piece->saveSnapshot($title, $body, $change_description)` — version increments to v2/v3/...; brief unchanged |
| **Card** | Existing "Revised (v2)" content piece card |

System prompt (~600 words) instructs the LLM to apply user-requested changes surgically rather than rewrite from scratch, match the existing voice, preserve sourced facts. Brand profile included.

---

## The Orchestrator

### System prompt

`ChatPromptBuilder::writerPrompt()` shrinks dramatically — from ~20K to ~2K. Structure:

```
You orchestrate a blog writing pipeline. You DO NOT do research, write
outlines, or write blog posts yourself. You call sub-agent tools. They do
the work.

## Your tools (you call these; sub-agents fulfill them)
- research_topic — runs the Research sub-agent. Fills brief.research.
- create_outline — runs the Editor sub-agent. Fills brief.outline.
  Requires brief.research.
- write_blog_post — runs the Writer sub-agent. Creates a ContentPiece and
  fills brief.content_piece_id. Requires brief.research and brief.outline.
- proofread_blog_post(feedback) — runs the Proofread sub-agent on the
  existing piece. Requires brief.content_piece_id.

## CRITICAL: function calling
You only do work through tool calls. Never narrate research, outlines, or
prose in plain text. Brief plain-text status updates between tool calls
are fine ("Researching now…", "Outlining…").

## Brief status (current state)
<brief-status>
{$brief->statusSummary()}
</brief-status>

## Mode: {autopilot|checkpoint}
[mode-specific rhythm — see writerAutopilotPrompt / writerCheckpointPrompt]

## Retry policy
When a tool returns {status: error, message: ...}, you may retry that tool
ONCE per turn with an `extra_context` argument explaining what to fix.
After one retry, surface the issue to the user and ask for guidance.

## Good / bad examples
[the existing function-calling good/bad examples stay here — they teach
the orchestrator to invoke tools, not narrate]

## Brand context (reference data — do not echo back)
<brand-profile>
{$profile}
</brand-profile>
```

The compact `<brief-status>` block replaces the previous huge `<topic>` and `<current-content-piece>` blocks. The orchestrator never sees claims or outlines verbatim; if it needs that detail, the sub-agent has it.

### First-turn topic hydration

`ChatPromptBuilder::writerPrompt()` is a pure prompt builder — no side effects. Hydration happens in the chat component's `ask()` method instead: at the top of `ask()`, if `$conversation->type === 'writer'` and `$conversation->brief['topic']` is empty, load the topic via the relationship, populate the brief, and save before building the system prompt. Backfill for conversations created before this refactor is automatic on their next `ask()` turn.

### Mode-specific rhythm

Identical to the current implementation — same `writerAutopilotPrompt` / `writerCheckpointPrompt` separation. Just shorter, with the brief-status block replacing the topic and content-piece blocks.

### Per-turn flow

1. User message arrives.
2. `ChatPromptBuilder::writerPrompt($team, $conversation)` builds the orchestrator system prompt — loads `$conversation->brief`, hydrates topic if missing, builds compact status block.
3. `streamChatWithTools()` runs with the four sub-agent tools (plus the existing `fetch_url` for orchestrator inspection if needed).
4. When the LLM picks a tool, the tool handler:
   a. Counts that tool's name in `$priorTurnTools`. If ≥ 1 (already called once this turn), returns `{status: error, message: 'Already retried this turn — get help from the user.'}`.
   b. Loads the brief from the (refreshed) conversation.
   c. Instantiates the agent, optionally with `$args['extra_context']` if the orchestrator passed it on a retry.
   d. Calls `$agent->execute($brief, $team)`.
   e. On `AgentResult::ok`, persists the updated brief to `$conversation->brief` and returns `{status: 'ok', summary: ..., card: {...}}` JSON.
   f. On `AgentResult::error`, returns `{status: 'error', message: ...}` JSON.
5. Tool result goes back to the orchestrator LLM, which decides next move (call another tool, retry once with `extra_context`, narrate to user, pause for user input in checkpoint mode).
6. Stream finishes, message persists with `metadata.tools` capturing the tool name and the small card payload (NOT the full agent payloads — those live on the brief).

---

## Tool Handlers (Thin Shells)

All four tool handler classes already exist (`ResearchTopicToolHandler`, `CreateOutlineToolHandler`, `WriteBlogPostToolHandler`, `UpdateBlogPostToolHandler`). They get gutted and rewritten as thin shells:

```php
class ResearchTopicToolHandler
{
    public function __construct(private ResearchAgent $agent = new ResearchAgent()) {}

    public function execute(Team $team, int $conversationId, array $args, ?Topic $topic = null, array $priorTurnTools = []): string
    {
        // Retry guard: count occurrences of the tool name in priorTurnTools.
        // ≥1 means this is the second call this turn → already retried once.
        $callsSoFar = collect($priorTurnTools)->where('name', 'research_topic')->count();
        if ($callsSoFar >= 1) {
            return json_encode([
                'status' => 'error',
                'message' => 'You already retried research_topic once this turn. Ask the user for guidance.',
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext ? new ResearchAgent($extraContext) : $this->agent;

        $result = $agent->execute($brief, $team);

        if ($result->status === 'error') {
            return json_encode(['status' => 'error', 'message' => $result->errorMessage]);
        }

        $conversation->update(['brief' => $result->brief->toJson()]);

        return json_encode([
            'status' => 'ok',
            'summary' => $result->summary,
            'card' => $result->cardPayload,
        ]);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'research_topic',
                'description' => 'Run the Research sub-agent. Reads brief.topic; writes brief.research with structured claims.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extra_context' => [
                            'type' => 'string',
                            'description' => 'Optional guidance for the sub-agent on retry, e.g. "first attempt found mostly anecdotes; focus on quantitative data".',
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

`CreateOutlineToolHandler`, `WriteBlogPostToolHandler`, and `ProofreadBlogPostToolHandler` (renamed from `UpdateBlogPostToolHandler`) follow the identical pattern. Each takes only `extra_context` (and `feedback` for proofread) — no other args. The agent reads everything else from the brief.

The `$priorTurnTools` plumbing already exists in `⚡create-chat.blade.php`. No changes there.

### `update_blog_post` → `proofread_blog_post`

Rename in:
- The handler class file: `UpdateBlogPostToolHandler.php` → `ProofreadBlogPostToolHandler.php`
- The class name and namespace
- The tool schema name (`update_blog_post` → `proofread_blog_post`)
- The handler dispatch in `⚡create-chat.blade.php`'s `$toolExecutor`
- The pill/card labels
- The tests
- The orchestrator system prompt

The proofread tool also gains a new `feedback` argument that the orchestrator passes through (the user's request, distilled). Sub-agent uses this in its prompt.

---

## Removed: Per-Tool History Scans

The current handlers (`CreateOutlineToolHandler::latestResearchClaimIds()` and `WriteBlogPostToolHandler::missingPrereqs()`) walk message metadata to check prerequisites. Both go away. Their job is now done by structural Brief checks:

```php
// EditorAgent
if (!$brief->hasResearch()) {
    return AgentResult::error('Cannot create outline without research. Call research_topic first.');
}

// WriterAgent  
if (!$brief->hasResearch() || !$brief->hasOutline()) {
    return AgentResult::error('Cannot write without research and outline. Run research_topic and create_outline first.');
}
```

The Brief is persisted on each successful agent run, so cross-turn continuity (checkpoint mode) just works — the next turn's brief load includes everything from prior turns.

The `$priorTurnTools` array is no longer needed for gate enforcement (the brief covers it) but stays for retry-counting.

---

## Stream UI / Cards

No visible changes for the user. The streaming card renderer (`contentPieceCards`) already handles `write_blog_post` and `update_blog_post`. Adding cards for `research_topic` and `create_outline` is a small extension:

- `research_topic` complete → card: "Gathered N claims · M sources" with expandable list (claim text + source URLs)
- `create_outline` complete → card: "Outline ready · N sections" with expandable section list (heading + key claim_ids)
- `write_blog_post` complete → existing content piece card (unchanged)
- `proofread_blog_post` complete → existing revised card (label changes from "Revised" stays)

Card payloads come from `AgentResult::cardPayload`. The streaming renderer reads from the tool result JSON, the history renderer reads from `message.metadata.tools[].card` (we extend the metadata save in `⚡create-chat.blade.php` to include the card payload alongside `name` and `args`).

---

## ChatPromptBuilder Changes

`writerPrompt()` rewritten end-to-end:

- Drop the `<topic>` block (now lives on brief)
- Drop the `<current-content-piece>` block (orchestrator doesn't need the body verbatim — just knows it exists from brief-status)
- Add the `<brief-status>` block at the top (Brief::statusSummary())
- Drop the writing rules entirely (move into `WriterAgent`)
- Add explicit retry-policy section
- Keep the good/bad function-call examples (orchestrator-relevant)
- Keep mode-specific rhythm (autopilot vs checkpoint)
- Keep brand profile block (orchestrator may reference it for narration)
- Hydrate `brief.topic` if empty: load from `$conversation->topic` and persist

Result: ~2K tokens vs current ~20K.

---

## Files Summary

### Create

| File | Purpose |
|------|---------|
| `database/migrations/<ts>_add_brief_to_conversations_table.php` | jsonb brief column |
| `app/Services/Writer/Brief.php` | Value object wrapping the brief JSONB |
| `app/Services/Writer/AgentResult.php` | Sub-agent return type |
| `app/Services/Writer/Agent.php` | Interface |
| `app/Services/Writer/BaseAgent.php` | Abstract base with LLM-call orchestration |
| `app/Services/Writer/Agents/ResearchAgent.php` | |
| `app/Services/Writer/Agents/EditorAgent.php` | |
| `app/Services/Writer/Agents/WriterAgent.php` | |
| `app/Services/Writer/Agents/ProofreadAgent.php` | |
| `app/Services/ProofreadBlogPostToolHandler.php` | Renamed from UpdateBlogPostToolHandler |
| `resources/prompts/agents/research.md` | ResearchAgent's system prompt template |
| `resources/prompts/agents/editor.md` | EditorAgent's system prompt template |
| `resources/prompts/agents/writer.md` | WriterAgent's system prompt template (long-form writing rules live here) |
| `resources/prompts/agents/proofread.md` | ProofreadAgent's system prompt template |
| `tests/Unit/Services/Writer/BriefTest.php` | |
| `tests/Unit/Services/Writer/Agents/ResearchAgentTest.php` | Validation rules + brief mutation |
| `tests/Unit/Services/Writer/Agents/EditorAgentTest.php` | |
| `tests/Unit/Services/Writer/Agents/WriterAgentTest.php` | Includes structural gate test |
| `tests/Unit/Services/Writer/Agents/ProofreadAgentTest.php` | Includes structural gate test |
| `tests/Unit/Services/ProofreadBlogPostToolHandlerTest.php` | |

### Modify

| File | Change |
|------|--------|
| `app/Services/ChatPromptBuilder.php` | Shrink writerPrompt; add brief-status injection; hydrate brief.topic |
| `app/Services/ResearchTopicToolHandler.php` | Gut to thin shell wrapping ResearchAgent |
| `app/Services/CreateOutlineToolHandler.php` | Gut to thin shell wrapping EditorAgent; remove latestResearchClaimIds |
| `app/Services/WriteBlogPostToolHandler.php` | Gut to thin shell wrapping WriterAgent; remove missingPrereqs |
| `app/Models/Conversation.php` | Add `brief` to fillable; cast `brief` as array |
| `resources/views/pages/teams/⚡create-chat.blade.php` | Rename update_blog_post dispatch to proofread_blog_post; add `feedback` arg pass-through; extend metadata save to include card payload; add streaming cards for research_topic and create_outline; update tool labels |
| `tests/Unit/Services/CreateOutlineToolHandlerTest.php` | Rewrite around brief instead of message metadata |
| `tests/Unit/Services/WriteBlogPostToolHandlerTest.php` | Rewrite around brief instead of message metadata |
| `tests/Unit/Services/UpdateBlogPostToolHandlerTest.php` | Rewrite + rename to ProofreadBlogPostToolHandlerTest |
| `tests/Unit/Services/ChatPromptBuilderWriterTest.php` | Update assertions: brief-status block instead of topic/piece blocks |

### Delete

| File | Reason |
|------|--------|
| `app/Services/UpdateBlogPostToolHandler.php` | Replaced by ProofreadBlogPostToolHandler |

---

## Plug-in Design for PR 2

The MVP must support clean addition of optional pre-writer agents without modifying existing agents or the orchestrator core. Verify the design against this constraint:

**AudiencePicker (PR 2)** reads `brief.research`, writes `brief.audience = {mode, persona_id, guidance_for_writer, ...}`. Orchestrator gets a new tool `pick_audience`. The brief-status summary gains an `audience` line. WriterAgent's prompt builder checks `$brief->audience()` — if present, includes `guidance_for_writer` in its system prompt; if absent, no change. EditorAgent unchanged. Cleanly additive.

**BrandEnricher (PR 2)** reads `brief.research` + brand URLs from the Team profile (`product_urls`, `homepage_url`, etc. — exact set decided in PR 2), writes `brief.research` (appends new claims to the existing list, preserves prior IDs). The brief-status `research` line shows the new total. EditorAgent reads the (now richer) `brief.research` — no change. WriterAgent unchanged. The append-only contract is enforced in BrandEnricher's `applyToBrief` (assertion: every prior claim ID still present, prior text unchanged).

**StyleReference (PR 2)** reads `brief.outline` (for context), fetches blog URL from `$team->blog_url`, writes `brief.style_reference = {examples: [{url, body, why_chosen}]}`. WriterAgent's prompt builder checks `$brief->styleReference()` — if present, includes 2-3 example bodies in its system prompt; if absent, no change. Cleanly additive.

The Brief schema accommodates new top-level slots without migration. The Agent base class supports new agents without changes. The orchestrator gains new tools without restructuring its prompt — just new lines in the tool list and the brief-status block.

---

## Out of Scope

- **AudiencePicker, BrandEnricher, StyleReference agents** — PR 2.
- **Editor's claim-validation retry-with-feedback loop** — Go has 3 attempts; we get 1 retry from the orchestrator instead. Sufficient for MVP.
- **Per-agent model configuration** — hard-coded `fast_model` / `powerful_model` per agent. Per-team/per-agent model overrides may come later.
- **Multi-content-type pluggability (LinkedIn, Instagram, etc.)** — out of scope for the writer chat type entirely; future content types get their own chat type.
- **Async/queued sub-agent execution** — sync calls for now. Each sub-agent is one OpenRouter request; aggregate latency stays workable.
- **Sub-agent observability** — the existing `chat-debug.log` captures the orchestrator turn. Sub-agent calls are not separately logged. Acceptable for MVP; consider later if debugging gets hard.
