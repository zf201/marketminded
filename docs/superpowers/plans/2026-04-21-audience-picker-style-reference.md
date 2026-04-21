# Audience Picker & Style Reference Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port `audience_picker` and `style_reference` from the Go pipeline to the Laravel writer chat, and remove checkpoint mode entirely so the writer always autoruns.

**Architecture:** Two new `BaseAgent` subclasses (`AudiencePickerAgent`, `StyleReferenceAgent`) each backed by a thin `ToolHandler` following the exact pattern of `ResearchTopicToolHandler`. New `audience` and `style_reference` slots are added to `Brief`. `EditorAgent` and `WriterAgent` inject audience/style blocks into their prompts when present. The orchestrator prompt is rewritten to always autorun the full pipeline. Checkpoint mode (UI, commands, DB column) is removed.

**Tech Stack:** Laravel 13, Livewire/Volt, Pest (run via `./vendor/bin/sail test`), PostgreSQL, OpenRouter via existing `OpenRouterClient`.

**Scope anchor:** all paths below are relative to `marketminded-laravel/`. Run `cd marketminded-laravel` once per shell.

---

## File Structure

**Create:**
- `app/Services/Writer/Agents/AudiencePickerAgent.php` — sub-agent that picks a persona or mode (educational/commentary)
- `app/Services/Writer/Agents/StyleReferenceAgent.php` — sub-agent that selects 2–3 exemplar blog posts
- `app/Services/PickAudienceToolHandler.php` — thin handler wrapping AudiencePickerAgent; skips if no personas
- `app/Services/FetchStyleReferenceToolHandler.php` — wraps StyleReferenceAgent; fetches bodies post-submit; skips if no blog_url + style_reference_urls
- `database/migrations/2026_04_21_000000_drop_writer_mode_from_conversations.php`
- `tests/Unit/Services/Writer/Agents/AudiencePickerAgentTest.php`
- `tests/Unit/Services/Writer/Agents/StyleReferenceAgentTest.php`
- `tests/Unit/Services/PickAudienceToolHandlerTest.php`
- `tests/Unit/Services/FetchStyleReferenceToolHandlerTest.php`

**Modify:**
- `app/Services/Writer/Brief.php` — add `audience` + `style_reference` slots
- `app/Services/Writer/Agents/EditorAgent.php` — inject audience block into system prompt
- `app/Services/Writer/Agents/WriterAgent.php` — inject audience + style_reference blocks into system prompt
- `app/Services/ChatPromptBuilder.php` — register new tools; rewrite orchestrator prompt; remove checkpoint mode
- `resources/views/pages/teams/⚡create-chat.blade.php` — wire new handlers; add cards; remove checkpoint mode UI
- `tests/Unit/Services/Writer/BriefTest.php` — extend for new slots

---

### Task 1: Add `audience` and `style_reference` slots to Brief

**Files:**
- Modify: `tests/Unit/Services/Writer/BriefTest.php`
- Modify: `app/Services/Writer/Brief.php`

- [ ] **Step 1: Write failing tests**

Add to the end of `tests/Unit/Services/Writer/BriefTest.php`:

```php
test('withAudience and withStyleReference set their slots immutably', function () {
    $original = Brief::fromJson([]);

    $withAudience = $original->withAudience([
        'mode' => 'persona',
        'persona_id' => 3,
        'persona_label' => 'Pro Chef',
        'persona_summary' => 'Daily professional user.',
        'reasoning' => 'Topic targets chefs.',
        'guidance_for_writer' => 'Assume deep knife knowledge.',
    ]);

    expect($original->hasAudience())->toBeFalse();
    expect($withAudience->hasAudience())->toBeTrue();
    expect($withAudience->audience()['mode'])->toBe('persona');
    expect($withAudience->audience()['persona_label'])->toBe('Pro Chef');

    $withRef = $original->withStyleReference([
        'examples' => [
            ['url' => 'https://x.com/1', 'title' => 'Post 1', 'why_chosen' => 'Good rhythm', 'body' => 'text...'],
            ['url' => 'https://x.com/2', 'title' => 'Post 2', 'why_chosen' => 'Short paragraphs', 'body' => 'text...'],
        ],
        'reasoning' => 'Best voice examples.',
    ]);

    expect($original->hasStyleReference())->toBeFalse();
    expect($withRef->hasStyleReference())->toBeTrue();
    expect($withRef->styleReference()['examples'])->toHaveCount(2);
});

test('statusSummary includes audience and style_reference lines', function () {
    $brief = Brief::fromJson([])
        ->withTopic(['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []])
        ->withAudience(['mode' => 'educational', 'reasoning' => 'r', 'guidance_for_writer' => 'g'])
        ->withStyleReference(['examples' => [
            ['url' => 'u', 'title' => 't', 'why_chosen' => 'w', 'body' => 'b'],
            ['url' => 'u2', 'title' => 't2', 'why_chosen' => 'w2', 'body' => 'b2'],
        ], 'reasoning' => 'r']);

    $summary = $brief->statusSummary();

    expect($summary)->toContain('audience: ✓');
    expect($summary)->toContain('style_reference: ✓');
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=Brief
```

Expected: new tests FAIL with "Call to undefined method".

- [ ] **Step 3: Add slots to Brief**

In `app/Services/Writer/Brief.php`, add after `outline(): ?array` (line 37):

```php
    /** @return array<string, mixed>|null */
    public function audience(): ?array
    {
        return $this->data['audience'] ?? null;
    }

    /** @return array<string, mixed>|null */
    public function styleReference(): ?array
    {
        return $this->data['style_reference'] ?? null;
    }
```

Add after `hasOutline(): bool` (line 65):

```php
    public function hasAudience(): bool
    {
        return $this->audience() !== null;
    }

    public function hasStyleReference(): bool
    {
        return $this->styleReference() !== null;
    }
```

Add after `withOutline()` (line 87):

```php
    /** @param array<string, mixed> $audience */
    public function withAudience(array $audience): self
    {
        return $this->with('audience', $audience);
    }

    /** @param array<string, mixed> $ref */
    public function withStyleReference(array $ref): self
    {
        return $this->with('style_reference', $ref);
    }
```

In `statusSummary()`, add two lines after the `content_piece` block (before the final `return`):

```php
        if ($this->hasAudience()) {
            $mode = $this->audience()['mode'] ?? '?';
            $lines[] = "audience: ✓ (mode={$mode})";
        } else {
            $lines[] = 'audience: ✗';
        }

        if ($this->hasStyleReference()) {
            $n = count($this->styleReference()['examples'] ?? []);
            $lines[] = "style_reference: ✓ ({$n} examples)";
        } else {
            $lines[] = 'style_reference: ✗';
        }
```

The `with()` method already accepts `array|int $value` — no signature change needed since both new values are arrays.

- [ ] **Step 4: Run tests**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=Brief
```

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Writer/Brief.php tests/Unit/Services/Writer/BriefTest.php
git commit -m "feat(writer): add audience + style_reference slots to Brief"
```

---

### Task 2: Drop writer_mode from conversations

**Files:**
- Create: `database/migrations/2026_04_21_000000_drop_writer_mode_from_conversations.php`

- [ ] **Step 1: Create the migration**

```bash
cd marketminded-laravel && ./vendor/bin/sail artisan make:migration drop_writer_mode_from_conversations
```

Open the generated file and replace its `up()` and `down()`:

```php
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn('writer_mode');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->string('writer_mode', 20)->nullable()->after('type');
        });
    }
```

- [ ] **Step 2: Run the migration**

```bash
cd marketminded-laravel && ./vendor/bin/sail artisan migrate
```

Expected: `Migrating: ... drop_writer_mode_from_conversations` then `Migrated` with no errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/
git commit -m "feat(writer): drop writer_mode column from conversations"
```

---

### Task 3: AudiencePickerAgent

**Files:**
- Create: `tests/Unit/Services/Writer/Agents/AudiencePickerAgentTest.php`
- Create: `app/Services/Writer/Agents/AudiencePickerAgent.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Services/Writer/Agents/AudiencePickerAgentTest.php`:

```php
<?php

use App\Models\AudiencePersona;
use App\Models\Team;
use App\Models\User;
use App\Services\Writer\Agents\AudiencePickerAgent;
use App\Services\Writer\Brief;

class StubbedAudiencePickerAgent extends AudiencePickerAgent
{
    public function __construct(private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($extraContext);
    }

    protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, ?string $key, int $to = 120): ?array
    {
        return $this->stubPayload;
    }
}

function briefWithResearchForAudience(): Brief
{
    return Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'Knife maintenance', 'angle' => 'Professional kitchens', 'sources' => []],
        'research' => [
            'topic_summary' => 'Summary about knife care for professionals.',
            'claims' => [
                ['id' => 'c1', 'text' => 'Honing extends edge life by 3x.', 'type' => 'fact', 'source_ids' => ['s1']],
            ],
            'sources' => [['id' => 's1', 'url' => 'https://example.com', 'title' => 'Knife Guide']],
        ],
    ]);
}

function teamWithPersonas(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $persona = AudiencePersona::create([
        'team_id' => $team->id,
        'label' => 'Pro Chef',
        'description' => 'Works in commercial kitchens daily.',
        'pain_points' => 'Cheap tools that break under heavy use.',
        'push' => 'Needs reliable equipment.',
        'pull' => 'Wants professional-grade results.',
        'anxiety' => 'Wasting money on low-quality knives.',
        'role' => 'Executive Chef',
        'sort_order' => 1,
    ]);
    return [$team, $persona];
}

test('AudiencePickerAgent ok path mode=persona: hydrates label and summary, writes to brief', function () {
    [$team, $persona] = teamWithPersonas();

    $payload = [
        'mode' => 'persona',
        'persona_id' => $persona->id,
        'reasoning' => 'Topic clearly targets professional kitchen users.',
        'guidance_for_writer' => 'Assume daily professional use. Skip beginner explanations.',
    ];

    $agent = new StubbedAudiencePickerAgent($payload);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->hasAudience())->toBeTrue();

    $audience = $result->brief->audience();
    expect($audience['mode'])->toBe('persona');
    expect($audience['persona_id'])->toBe($persona->id);
    expect($audience['persona_label'])->toBe('Pro Chef');
    expect($audience['persona_summary'])->toContain('commercial kitchens');
    expect($audience['guidance_for_writer'])->toContain('beginner');

    expect($result->cardPayload['kind'])->toBe('audience');
    expect($result->summary)->toContain('persona');
});

test('AudiencePickerAgent ok path mode=educational: no persona_id in brief', function () {
    [$team] = teamWithPersonas();

    $payload = [
        'mode' => 'educational',
        'reasoning' => 'Topic is informational, no persona fits well.',
        'guidance_for_writer' => 'Write for a curious reader with no professional background.',
    ];

    $agent = new StubbedAudiencePickerAgent($payload);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeTrue();
    $audience = $result->brief->audience();
    expect($audience['mode'])->toBe('educational');
    expect(isset($audience['persona_id']))->toBeFalse();
    expect($result->summary)->toContain('educational');
});

test('AudiencePickerAgent ok path mode=commentary: no persona_id in brief', function () {
    [$team] = teamWithPersonas();

    $agent = new StubbedAudiencePickerAgent([
        'mode' => 'commentary',
        'reasoning' => 'Opinion piece.',
        'guidance_for_writer' => 'Write for an informed reader.',
    ]);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->audience()['mode'])->toBe('commentary');
    expect($result->summary)->toContain('commentary');
});

test('AudiencePickerAgent rejects persona_id on educational mode', function () {
    [$team] = teamWithPersonas();

    $agent = new StubbedAudiencePickerAgent([
        'mode' => 'educational',
        'persona_id' => 1,
        'reasoning' => 'r',
        'guidance_for_writer' => 'g',
    ]);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('persona_id');
});

test('AudiencePickerAgent rejects missing persona_id on persona mode', function () {
    [$team] = teamWithPersonas();

    $agent = new StubbedAudiencePickerAgent([
        'mode' => 'persona',
        'reasoning' => 'r',
        'guidance_for_writer' => 'g',
    ]);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('persona_id');
});

test('AudiencePickerAgent rejects empty guidance_for_writer', function () {
    [$team] = teamWithPersonas();

    $agent = new StubbedAudiencePickerAgent([
        'mode' => 'educational',
        'reasoning' => 'r',
        'guidance_for_writer' => '   ',
    ]);
    $result = $agent->execute(briefWithResearchForAudience(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('guidance_for_writer');
});

test('AudiencePickerAgent returns error when brief has no research', function () {
    [$team] = teamWithPersonas();
    $brief = Brief::fromJson(['topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]]);

    $agent = new StubbedAudiencePickerAgent(['mode' => 'educational', 'reasoning' => 'r', 'guidance_for_writer' => 'g']);
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('research');
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=AudiencePickerAgent
```

Expected: all FAIL with "Class 'StubbedAudiencePickerAgent' not found" or similar.

- [ ] **Step 3: Create AudiencePickerAgent**

Create `app/Services/Writer/Agents/AudiencePickerAgent.php`:

```php
<?php

namespace App\Services\Writer\Agents;

use App\Models\AudiencePersona;
use App\Models\Team;
use App\Services\Writer\AgentResult;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class AudiencePickerAgent extends BaseAgent
{
    public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasResearch()) {
            return AgentResult::error('Cannot pick audience without research. Run research_topic first.');
        }

        return parent::execute($brief, $team);
    }

    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $topic = $brief->topic() ?? ['title' => '', 'angle' => ''];
        $topicSummary = $brief->research()['topic_summary'] ?? '';
        $personasBlock = $this->formatPersonasBlock($team->audiencePersonas()->get());
        $extra = $this->extraContextBlock();

        return <<<PROMPT
## Role & Output Contract
You are the AudiencePicker sub-agent. You deliver output EXCLUSIVELY by calling `submit_audience_selection`.
- Text responses are system failures. Do not narrate, plan, or explain.
- You MUST end your turn with a `submit_audience_selection` call.

## Task
Read the topic, research summary, and available personas. Select the persona this post should address, or pick a mode if no persona fits.

## Modes
- `persona` — the post targets a specific persona. Set `persona_id` to the persona's id.
- `educational` — no persona fits; write for a curious learner.
- `commentary` — no persona fits; write for an informed reader of this space.

## Quality rules
- Choose `persona` only if the topic + angle clearly matches that persona's needs or pain points.
- `guidance_for_writer` must be concrete and actionable (1–2 sentences). Do NOT echo the persona description.

## Topic
Title: {$topic['title']}
Angle: {$topic['angle']}

## Research summary
{$topicSummary}

## Available personas
{$personasBlock}
{$extra}

## IMPORTANT
Your turn MUST end with a `submit_audience_selection` call. Any text output is a failure.
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_audience_selection',
                'description' => 'Submit the audience selection. This is your ONLY way to deliver output.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['mode', 'reasoning', 'guidance_for_writer'],
                    'properties' => [
                        'mode' => ['type' => 'string', 'enum' => ['persona', 'educational', 'commentary']],
                        'persona_id' => ['type' => 'integer'],
                        'reasoning' => ['type' => 'string'],
                        'guidance_for_writer' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    protected function additionalTools(): array
    {
        return [];
    }

    protected function useServerTools(): bool
    {
        return false;
    }

    protected function model(Team $team): string
    {
        return $team->fast_model;
    }

    protected function temperature(): float
    {
        return 0.2;
    }

    protected function validate(array $payload): ?string
    {
        $mode = $payload['mode'] ?? '';

        if (! in_array($mode, ['persona', 'educational', 'commentary'], true)) {
            return 'mode must be one of: persona, educational, commentary.';
        }

        if ($mode === 'persona' && empty($payload['persona_id'])) {
            return 'persona_id is required when mode=persona.';
        }

        if ($mode !== 'persona' && isset($payload['persona_id'])) {
            return "persona_id must not be set when mode={$mode}.";
        }

        if (trim($payload['guidance_for_writer'] ?? '') === '') {
            return 'guidance_for_writer must not be empty.';
        }

        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        $audience = [
            'mode' => $payload['mode'],
            'reasoning' => $payload['reasoning'],
            'guidance_for_writer' => $payload['guidance_for_writer'],
        ];

        if ($payload['mode'] === 'persona') {
            $personaId = (int) $payload['persona_id'];
            $persona = AudiencePersona::where('id', $personaId)
                ->where('team_id', $team->id)
                ->first();

            if ($persona !== null) {
                $audience['persona_id'] = $personaId;
                $audience['persona_label'] = $persona->label;
                $audience['persona_summary'] = $this->buildPersonaSummary($persona);
            }
        }

        return $brief->withAudience($audience);
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'audience',
            'summary' => $this->buildSummary($payload),
            'mode' => $payload['mode'],
            'guidance_for_writer' => $payload['guidance_for_writer'],
        ];
    }

    protected function buildSummary(array $payload): string
    {
        return match ($payload['mode']) {
            'persona' => 'Audience: persona selected',
            'educational' => 'Audience: educational (no persona)',
            'commentary' => 'Audience: commentary (no persona)',
            default => 'Audience selected',
        };
    }

    private function buildPersonaSummary(AudiencePersona $persona): string
    {
        $parts = [];
        if ($persona->description) $parts[] = $persona->description;
        if ($persona->pain_points) $parts[] = 'Pain points: ' . $persona->pain_points;
        if ($persona->push) $parts[] = 'Push: ' . $persona->push;
        if ($persona->pull) $parts[] = 'Pull: ' . $persona->pull;
        if ($persona->anxiety) $parts[] = 'Anxiety: ' . $persona->anxiety;
        return implode('. ', $parts);
    }

    /** @param \Illuminate\Database\Eloquent\Collection<int, AudiencePersona> $personas */
    private function formatPersonasBlock($personas): string
    {
        if ($personas->isEmpty()) {
            return '(none)';
        }

        $lines = [];
        foreach ($personas as $i => $p) {
            $lines[] = ($i + 1) . ". [id={$p->id}] {$p->label}" . ($p->role ? " ({$p->role})" : '');
            if ($p->description) $lines[] = "   description: {$p->description}";
            if ($p->pain_points) $lines[] = "   pain_points: {$p->pain_points}";
            if ($p->push) $lines[] = "   push: {$p->push}";
            if ($p->pull) $lines[] = "   pull: {$p->pull}";
            if ($p->anxiety) $lines[] = "   anxiety: {$p->anxiety}";
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
```

- [ ] **Step 4: Run tests**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=AudiencePickerAgent
```

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Writer/Agents/AudiencePickerAgent.php tests/Unit/Services/Writer/Agents/AudiencePickerAgentTest.php
git commit -m "feat(writer): add AudiencePickerAgent"
```

---

### Task 4: StyleReferenceAgent

**Files:**
- Create: `tests/Unit/Services/Writer/Agents/StyleReferenceAgentTest.php`
- Create: `app/Services/Writer/Agents/StyleReferenceAgent.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Services/Writer/Agents/StyleReferenceAgentTest.php`:

```php
<?php

use App\Models\User;
use App\Services\Writer\Agents\StyleReferenceAgent;
use App\Services\Writer\Brief;

class StubbedStyleReferenceAgent extends StyleReferenceAgent
{
    public function __construct(private array $stubPayload, ?string $extraContext = null)
    {
        parent::__construct($extraContext);
    }

    protected function llmCall(string $sp, array $t, string $m, float $temp, bool $ust, ?string $key, int $to = 120): ?array
    {
        return $this->stubPayload;
    }
}

function briefWithOutlineForStyle(): Brief
{
    return Brief::fromJson([
        'topic' => ['id' => 1, 'title' => 'Knife maintenance', 'angle' => 'Professional kitchens', 'sources' => []],
        'research' => [
            'topic_summary' => 'Summary.',
            'claims' => [['id' => 'c1', 'text' => 'Fact.', 'type' => 'fact', 'source_ids' => ['s1']]],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
        ],
        'outline' => [
            'angle' => 'Professional maintenance',
            'target_length_words' => 1500,
            'sections' => [
                ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1']],
                ['heading' => 'Body', 'purpose' => 'evidence', 'claim_ids' => ['c1']],
            ],
        ],
    ]);
}

function validStylePayload(): array
{
    return [
        'examples' => [
            ['url' => 'https://brand.com/post-1', 'title' => 'Post One', 'why_chosen' => 'Strong hook, short paragraphs.'],
            ['url' => 'https://brand.com/post-2', 'title' => 'Post Two', 'why_chosen' => 'Direct voice, benefit-first.'],
        ],
        'reasoning' => 'These best represent the brand voice.',
    ];
}

test('StyleReferenceAgent ok path: stores body-less examples in brief', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $agent = new StubbedStyleReferenceAgent(validStylePayload());
    $result = $agent->execute(briefWithOutlineForStyle(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->hasStyleReference())->toBeTrue();

    $ref = $result->brief->styleReference();
    expect($ref['examples'])->toHaveCount(2);
    expect($ref['examples'][0]['url'])->toBe('https://brand.com/post-1');
    expect($ref['examples'][0]['body'])->toBe('');  // bodies fetched by handler
    expect($result->cardPayload['kind'])->toBe('style_reference');
    expect($result->summary)->toContain('2 example');
});

test('StyleReferenceAgent accepts 3 examples', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validStylePayload();
    $payload['examples'][] = ['url' => 'https://brand.com/post-3', 'title' => 'Post Three', 'why_chosen' => 'Great rhythm.'];

    $agent = new StubbedStyleReferenceAgent($payload);
    $result = $agent->execute(briefWithOutlineForStyle(), $team);

    expect($result->isOk())->toBeTrue();
    expect($result->brief->styleReference()['examples'])->toHaveCount(3);
});

test('StyleReferenceAgent rejects fewer than 2 examples', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validStylePayload();
    $payload['examples'] = [$payload['examples'][0]];

    $agent = new StubbedStyleReferenceAgent($payload);
    $result = $agent->execute(briefWithOutlineForStyle(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('2');
});

test('StyleReferenceAgent rejects example with missing url', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validStylePayload();
    $payload['examples'][0]['url'] = '';

    $agent = new StubbedStyleReferenceAgent($payload);
    $result = $agent->execute(briefWithOutlineForStyle(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('url');
});

test('StyleReferenceAgent rejects example with missing why_chosen', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $payload = validStylePayload();
    $payload['examples'][1]['why_chosen'] = '';

    $agent = new StubbedStyleReferenceAgent($payload);
    $result = $agent->execute(briefWithOutlineForStyle(), $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('why_chosen');
});

test('StyleReferenceAgent returns error when brief has no outline', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $brief = Brief::fromJson(['topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]]);

    $agent = new StubbedStyleReferenceAgent(validStylePayload());
    $result = $agent->execute($brief, $team);

    expect($result->isOk())->toBeFalse();
    expect($result->errorMessage)->toContain('outline');
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=StyleReferenceAgent
```

Expected: all FAIL.

- [ ] **Step 3: Create StyleReferenceAgent**

Create `app/Services/Writer/Agents/StyleReferenceAgent.php`:

```php
<?php

namespace App\Services\Writer\Agents;

use App\Models\Team;
use App\Services\BrandIntelligenceToolHandler;
use App\Services\Writer\AgentResult;
use App\Services\Writer\BaseAgent;
use App\Services\Writer\Brief;

class StyleReferenceAgent extends BaseAgent
{
    public function execute(Brief $brief, Team $team): AgentResult
    {
        if (! $brief->hasOutline()) {
            return AgentResult::error('Cannot fetch style reference without an outline. Run create_outline first.');
        }

        return parent::execute($brief, $team);
    }

    protected function systemPrompt(Brief $brief, Team $team): string
    {
        $topic = $brief->topic() ?? ['title' => '', 'angle' => ''];

        $curatedBlock = '';
        if (! empty($team->style_reference_urls)) {
            $urls = implode("\n", array_map(fn ($u) => "- {$u}", $team->style_reference_urls));
            $curatedBlock = "\n\n## Pre-curated style reference URLs (prefer these)\n{$urls}";
        }

        $blogUrlBlock = $team->blog_url
            ? "\n\n## Blog URL (browse index to find posts if curated list is empty)\n{$team->blog_url}"
            : '';

        $extra = $this->extraContextBlock();

        return <<<PROMPT
## Role & Output Contract
You are the StyleReference sub-agent. You deliver output EXCLUSIVELY by calling `submit_style_reference`.
- Text responses are system failures. Do not narrate, plan, or explain.
- You MUST end your turn with a `submit_style_reference` call.

## Task
Find 2–3 blog posts from this brand that best represent their voice and writing style. Use the pre-curated URLs if provided; otherwise use fetch_url to browse the blog index and discover posts.

## Quality rules
- Pick posts with clear brand voice. Avoid product announcements or press releases.
- `why_chosen` must explain the voice/style qualities observed — not just the topic.
- Do NOT include the post body in your submission. Only url, title, why_chosen.
- Submit exactly 2–3 examples.

## Topic being written
Title: {$topic['title']}
Angle: {$topic['angle']}
{$curatedBlock}{$blogUrlBlock}
{$extra}

## IMPORTANT
Your turn MUST end with a `submit_style_reference` call. Any text output is a failure.
PROMPT;
    }

    protected function submitToolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => 'submit_style_reference',
                'description' => 'Submit the style reference selection. This is your ONLY way to deliver output.',
                'parameters' => [
                    'type' => 'object',
                    'required' => ['examples', 'reasoning'],
                    'properties' => [
                        'examples' => [
                            'type' => 'array',
                            'minItems' => 2,
                            'maxItems' => 3,
                            'items' => [
                                'type' => 'object',
                                'required' => ['url', 'title', 'why_chosen'],
                                'properties' => [
                                    'url' => ['type' => 'string'],
                                    'title' => ['type' => 'string'],
                                    'why_chosen' => ['type' => 'string'],
                                ],
                            ],
                        ],
                        'reasoning' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    protected function additionalTools(): array
    {
        return [BrandIntelligenceToolHandler::fetchUrlToolSchema()];
    }

    protected function useServerTools(): bool
    {
        return false;
    }

    protected function model(Team $team): string
    {
        return $team->fast_model;
    }

    protected function temperature(): float
    {
        return 0.2;
    }

    protected function timeout(): int
    {
        return 180;
    }

    protected function validate(array $payload): ?string
    {
        $examples = $payload['examples'] ?? [];
        $n = count($examples);

        if ($n < 2 || $n > 3) {
            return "style_reference must have 2–3 examples, got {$n}.";
        }

        foreach ($examples as $i => $ex) {
            if (trim($ex['url'] ?? '') === '') {
                return "Example[{$i}] missing url.";
            }
            if (trim($ex['title'] ?? '') === '') {
                return "Example[{$i}] missing title.";
            }
            if (trim($ex['why_chosen'] ?? '') === '') {
                return "Example[{$i}] missing why_chosen.";
            }
        }

        return null;
    }

    protected function applyToBrief(Brief $brief, array $payload, Team $team): Brief
    {
        // Store body-less examples. FetchStyleReferenceToolHandler fetches bodies
        // after execute() returns and re-calls withStyleReference with bodies.
        return $brief->withStyleReference([
            'examples' => array_map(fn ($ex) => [
                'url' => $ex['url'],
                'title' => $ex['title'],
                'why_chosen' => $ex['why_chosen'],
                'body' => '',
            ], $payload['examples']),
            'reasoning' => $payload['reasoning'],
        ]);
    }

    protected function buildCard(array $payload): array
    {
        return [
            'kind' => 'style_reference',
            'summary' => $this->buildSummary($payload),
            'examples' => array_map(fn ($ex) => [
                'title' => $ex['title'],
                'why_chosen' => $ex['why_chosen'],
            ], $payload['examples']),
        ];
    }

    protected function buildSummary(array $payload): string
    {
        $n = count($payload['examples']);
        return "Style reference: {$n} example" . ($n === 1 ? '' : 's') . ' selected';
    }
}
```

- [ ] **Step 4: Run tests**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=StyleReferenceAgent
```

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Writer/Agents/StyleReferenceAgent.php tests/Unit/Services/Writer/Agents/StyleReferenceAgentTest.php
git commit -m "feat(writer): add StyleReferenceAgent"
```

---

### Task 5: PickAudienceToolHandler

**Files:**
- Create: `tests/Unit/Services/PickAudienceToolHandlerTest.php`
- Create: `app/Services/PickAudienceToolHandler.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Services/PickAudienceToolHandlerTest.php`:

```php
<?php

use App\Models\AudiencePersona;
use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\PickAudienceToolHandler;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Agents\AudiencePickerAgent;
use App\Services\Writer\Brief;

class FakeAudiencePickerAgent extends AudiencePickerAgent
{
    public ?AgentResult $stubResult = null;

    public function execute(Brief $brief, $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub set');
    }
}

function convWithResearchAndPersona(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $persona = AudiencePersona::create([
        'team_id' => $team->id,
        'label' => 'Pro Chef',
        'description' => 'Works in kitchens.',
        'pain_points' => 'Cheap tools.',
        'sort_order' => 1,
    ]);

    $topic = Topic::create([
        'team_id' => $team->id,
        'title' => 'Knives',
        'angle' => 'Pro kitchens',
        'status' => 'available',
    ]);

    $brief = [
        'topic' => ['id' => $topic->id, 'title' => 'Knives', 'angle' => 'Pro kitchens', 'sources' => []],
        'research' => [
            'topic_summary' => 's',
            'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
        ],
    ];

    $conversation = Conversation::create([
        'team_id' => $team->id,
        'user_id' => $user->id,
        'title' => 't',
        'type' => 'writer',
        'topic_id' => $topic->id,
        'brief' => $brief,
    ]);

    return [$team, $conversation, $persona];
}

test('handler returns skipped when team has no personas', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;
    $topic = Topic::create(['team_id' => $team->id, 'title' => 'X', 'angle' => 'a', 'status' => 'available']);
    $conversation = Conversation::create([
        'team_id' => $team->id, 'user_id' => $user->id,
        'title' => 't', 'type' => 'writer', 'topic_id' => $topic->id,
        'brief' => ['topic' => ['id' => $topic->id, 'title' => 'X', 'angle' => 'a', 'sources' => []]],
    ]);

    $handler = new PickAudienceToolHandler;
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('skipped');
    expect($decoded['reason'])->toContain('persona');

    // brief must not be modified
    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->hasAudience())->toBeFalse();
});

test('handler returns ok and persists brief on agent success', function () {
    [$team, $conversation] = convWithResearchAndPersona();

    $newBrief = Brief::fromJson($conversation->brief)->withAudience([
        'mode' => 'persona',
        'persona_id' => 1,
        'persona_label' => 'Pro Chef',
        'persona_summary' => 'Works in kitchens.',
        'reasoning' => 'r',
        'guidance_for_writer' => 'g',
    ]);

    $agent = new FakeAudiencePickerAgent;
    $agent->stubResult = AgentResult::ok(
        $newBrief,
        ['kind' => 'audience', 'summary' => 'Audience: persona selected', 'mode' => 'persona', 'guidance_for_writer' => 'g'],
        'Audience: persona selected',
    );

    $handler = new PickAudienceToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toBe('Audience: persona selected');
    expect($decoded['card']['kind'])->toBe('audience');

    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->hasAudience())->toBeTrue();
});

test('handler is idempotent on duplicate in-turn call', function () {
    [$team, $conversation] = convWithResearchAndPersona();

    // Persist audience into the brief first.
    $audienceBrief = Brief::fromJson($conversation->brief)->withAudience([
        'mode' => 'educational',
        'reasoning' => 'r',
        'guidance_for_writer' => 'g',
    ]);
    $conversation->update(['brief' => $audienceBrief->toJson()]);

    $agent = new FakeAudiencePickerAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new PickAudienceToolHandler($agent);
    $priorTurnTools = [['name' => 'pick_audience', 'args' => [], 'status' => 'ok']];
    $result = $handler->execute($team, $conversation->id, [], $priorTurnTools);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toContain('already');
});

test('handler returns error from agent', function () {
    [$team, $conversation] = convWithResearchAndPersona();

    $agent = new FakeAudiencePickerAgent;
    $agent->stubResult = AgentResult::error('validation failed');

    $handler = new PickAudienceToolHandler($agent);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toBe('validation failed');
});

test('toolSchema returns valid schema', function () {
    $schema = PickAudienceToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('pick_audience');
    expect($schema['function']['parameters']['properties'])->toHaveKey('extra_context');
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=PickAudienceToolHandler
```

Expected: all FAIL.

- [ ] **Step 3: Create PickAudienceToolHandler**

Create `app/Services/PickAudienceToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agent;
use App\Services\Writer\Agents\AudiencePickerAgent;
use App\Services\Writer\Brief;

class PickAudienceToolHandler
{
    public function __construct(private ?Agent $agent = null) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        if (! $team->audiencePersonas()->exists()) {
            return json_encode([
                'status' => 'skipped',
                'reason' => 'No personas configured for this team.',
            ]);
        }

        $callsSoFar = collect($priorTurnTools)->where('name', 'pick_audience')->where('status', 'ok')->count();
        if ($callsSoFar >= 1) {
            $conversation = Conversation::findOrFail($conversationId);
            $brief = Brief::fromJson($conversation->brief ?? []);
            $audience = $brief->audience();
            return json_encode([
                'status' => 'ok',
                'summary' => 'Audience already selected this turn',
                'card' => [
                    'kind' => 'audience',
                    'summary' => 'Audience already selected this turn',
                    'mode' => $audience['mode'] ?? 'unknown',
                    'guidance_for_writer' => $audience['guidance_for_writer'] ?? '',
                ],
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new AudiencePickerAgent($extraContext) : ($this->agent ?? new AudiencePickerAgent);

        try {
            $result = $agent->execute($brief, $team);
        } catch (\Throwable $e) {
            return json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        if (! $result->isOk()) {
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
                'name' => 'pick_audience',
                'description' => 'Run the AudiencePicker sub-agent. Reads brief.research + team personas; writes brief.audience with mode, persona selection, and writer guidance. Returns status=skipped if no personas are configured.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extra_context' => [
                            'type' => 'string',
                            'description' => 'Optional guidance for the sub-agent on retry.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run tests**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=PickAudienceToolHandler
```

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PickAudienceToolHandler.php tests/Unit/Services/PickAudienceToolHandlerTest.php
git commit -m "feat(writer): add PickAudienceToolHandler"
```

---

### Task 6: FetchStyleReferenceToolHandler

**Files:**
- Create: `tests/Unit/Services/FetchStyleReferenceToolHandlerTest.php`
- Create: `app/Services/FetchStyleReferenceToolHandler.php`

- [ ] **Step 1: Write failing tests**

Create `tests/Unit/Services/FetchStyleReferenceToolHandlerTest.php`:

```php
<?php

use App\Models\Conversation;
use App\Models\Topic;
use App\Models\User;
use App\Services\FetchStyleReferenceToolHandler;
use App\Services\Writer\AgentResult;
use App\Services\Writer\Agents\StyleReferenceAgent;
use App\Services\Writer\Brief;

class FakeStyleReferenceAgent extends StyleReferenceAgent
{
    public ?AgentResult $stubResult = null;

    public function execute(Brief $brief, $team): AgentResult
    {
        return $this->stubResult ?? AgentResult::error('no stub set');
    }
}

function convWithOutline(): array
{
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $topic = Topic::create([
        'team_id' => $team->id, 'title' => 'Knives', 'angle' => 'Pro kitchens', 'status' => 'available',
    ]);

    $brief = [
        'topic' => ['id' => $topic->id, 'title' => 'Knives', 'angle' => 'Pro kitchens', 'sources' => []],
        'research' => [
            'topic_summary' => 's',
            'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 't']],
        ],
        'outline' => [
            'angle' => 'Pro maintenance',
            'target_length_words' => 1500,
            'sections' => [
                ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1']],
                ['heading' => 'Body', 'purpose' => 'evidence', 'claim_ids' => ['c1']],
            ],
        ],
    ];

    $conversation = Conversation::create([
        'team_id' => $team->id, 'user_id' => $user->id,
        'title' => 't', 'type' => 'writer', 'topic_id' => $topic->id,
        'brief' => $brief,
    ]);

    return [$team, $conversation, $topic];
}

test('handler returns skipped when no blog_url and no style_reference_urls', function () {
    [$team, $conversation] = convWithOutline();
    // team has no blog_url and empty style_reference_urls by default

    $handler = new FetchStyleReferenceToolHandler;
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('skipped');
    expect($decoded['reason'])->toContain('blog URL');

    $conversation->refresh();
    expect(Brief::fromJson($conversation->brief)->hasStyleReference())->toBeFalse();
});

test('handler fetches bodies, persists brief, returns ok', function () {
    [$team, $conversation] = convWithOutline();
    $team->update(['blog_url' => 'https://brand.com/blog']);

    $bodyLessBrief = Brief::fromJson($conversation->brief)->withStyleReference([
        'examples' => [
            ['url' => 'https://brand.com/post-1', 'title' => 'Post One', 'why_chosen' => 'Good hook.', 'body' => ''],
            ['url' => 'https://brand.com/post-2', 'title' => 'Post Two', 'why_chosen' => 'Direct.', 'body' => ''],
        ],
        'reasoning' => 'Best examples.',
    ]);

    $agent = new FakeStyleReferenceAgent;
    $agent->stubResult = AgentResult::ok(
        $bodyLessBrief,
        ['kind' => 'style_reference', 'summary' => 'Style reference: 2 examples selected', 'examples' => [
            ['title' => 'Post One', 'why_chosen' => 'Good hook.'],
            ['title' => 'Post Two', 'why_chosen' => 'Direct.'],
        ]],
        'Style reference: 2 examples selected',
    );

    // Fake URL fetcher returns long-enough bodies
    $fakeFetcher = fn (string $url) => str_repeat('word ', 100); // 500 chars, above 400 threshold

    $handler = new FetchStyleReferenceToolHandler($agent, $fakeFetcher);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['card']['kind'])->toBe('style_reference');

    $conversation->refresh();
    $ref = Brief::fromJson($conversation->brief)->styleReference();
    expect($ref)->not->toBeNull();
    expect($ref['examples'])->toHaveCount(2);
    expect(strlen($ref['examples'][0]['body']))->toBeGreaterThan(0);
});

test('handler drops examples with body shorter than 400 chars, passes if 2+ survive', function () {
    [$team, $conversation] = convWithOutline();
    $team->update(['blog_url' => 'https://brand.com/blog']);

    $bodyLessBrief = Brief::fromJson($conversation->brief)->withStyleReference([
        'examples' => [
            ['url' => 'https://brand.com/post-1', 'title' => 'Post One', 'why_chosen' => 'Good.', 'body' => ''],
            ['url' => 'https://brand.com/post-2', 'title' => 'Post Two', 'why_chosen' => 'Great.', 'body' => ''],
            ['url' => 'https://brand.com/post-3', 'title' => 'Post Three', 'why_chosen' => 'Nice.', 'body' => ''],
        ],
        'reasoning' => 'Three examples.',
    ]);

    $agent = new FakeStyleReferenceAgent;
    $agent->stubResult = AgentResult::ok($bodyLessBrief, ['kind' => 'style_reference', 'summary' => 'Style reference: 3 examples selected', 'examples' => []], 'ok');

    $callCount = 0;
    $fakeFetcher = function (string $url) use (&$callCount): string {
        $callCount++;
        // First URL returns too-short body; others return long bodies
        return $callCount === 1 ? 'short' : str_repeat('word ', 100);
    };

    $handler = new FetchStyleReferenceToolHandler($agent, $fakeFetcher);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');

    $conversation->refresh();
    $ref = Brief::fromJson($conversation->brief)->styleReference();
    expect($ref['examples'])->toHaveCount(2); // post-1 was dropped
});

test('handler returns error when fewer than 2 examples survive body fetch', function () {
    [$team, $conversation] = convWithOutline();
    $team->update(['blog_url' => 'https://brand.com/blog']);

    $bodyLessBrief = Brief::fromJson($conversation->brief)->withStyleReference([
        'examples' => [
            ['url' => 'https://brand.com/post-1', 'title' => 'P1', 'why_chosen' => 'w', 'body' => ''],
            ['url' => 'https://brand.com/post-2', 'title' => 'P2', 'why_chosen' => 'w', 'body' => ''],
        ],
        'reasoning' => 'r',
    ]);

    $agent = new FakeStyleReferenceAgent;
    $agent->stubResult = AgentResult::ok($bodyLessBrief, ['kind' => 'style_reference', 'summary' => 'ok', 'examples' => []], 'ok');

    $fakeFetcher = fn (string $url) => 'too short'; // all below threshold

    $handler = new FetchStyleReferenceToolHandler($agent, $fakeFetcher);
    $result = $handler->execute($team, $conversation->id, [], []);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('error');
    expect($decoded['message'])->toContain('2');
});

test('handler is idempotent on duplicate in-turn call', function () {
    [$team, $conversation] = convWithOutline();
    $team->update(['blog_url' => 'https://brand.com/blog']);

    // Pre-populate style_reference in brief
    $refBrief = Brief::fromJson($conversation->brief)->withStyleReference([
        'examples' => [
            ['url' => 'u1', 'title' => 'Post One', 'why_chosen' => 'w', 'body' => 'body text'],
            ['url' => 'u2', 'title' => 'Post Two', 'why_chosen' => 'w', 'body' => 'body text'],
        ],
        'reasoning' => 'r',
    ]);
    $conversation->update(['brief' => $refBrief->toJson()]);

    $agent = new FakeStyleReferenceAgent;
    $agent->stubResult = AgentResult::error('should not be called');

    $handler = new FetchStyleReferenceToolHandler($agent);
    $priorTurnTools = [['name' => 'fetch_style_reference', 'args' => [], 'status' => 'ok']];
    $result = $handler->execute($team, $conversation->id, [], $priorTurnTools);
    $decoded = json_decode($result, true);

    expect($decoded['status'])->toBe('ok');
    expect($decoded['summary'])->toContain('already');
});

test('toolSchema returns valid schema', function () {
    $schema = FetchStyleReferenceToolHandler::toolSchema();
    expect($schema['function']['name'])->toBe('fetch_style_reference');
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=FetchStyleReferenceToolHandler
```

Expected: all FAIL.

- [ ] **Step 3: Create FetchStyleReferenceToolHandler**

Create `app/Services/FetchStyleReferenceToolHandler.php`:

```php
<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Team;
use App\Services\Writer\Agent;
use App\Services\Writer\Agents\StyleReferenceAgent;
use App\Services\Writer\Brief;

class FetchStyleReferenceToolHandler
{
    private const MIN_BODY_CHARS = 400;

    /** @param callable|null $urlFetcher fn(string $url): string — injected in tests */
    public function __construct(
        private ?Agent $agent = null,
        private $urlFetcher = null,
    ) {}

    public function execute(Team $team, int $conversationId, array $args, array $priorTurnTools = []): string
    {
        $hasBlogUrl = ! empty($team->blog_url);
        $hasCuratedUrls = ! empty($team->style_reference_urls);

        if (! $hasBlogUrl && ! $hasCuratedUrls) {
            return json_encode([
                'status' => 'skipped',
                'reason' => 'No blog URL or style reference URLs configured for this team.',
            ]);
        }

        $callsSoFar = collect($priorTurnTools)->where('name', 'fetch_style_reference')->where('status', 'ok')->count();
        if ($callsSoFar >= 1) {
            $conversation = Conversation::findOrFail($conversationId);
            $ref = Brief::fromJson($conversation->brief ?? [])->styleReference();
            return json_encode([
                'status' => 'ok',
                'summary' => 'Style reference already fetched this turn',
                'card' => [
                    'kind' => 'style_reference',
                    'summary' => 'Style reference already fetched this turn',
                    'examples' => array_map(fn ($ex) => [
                        'title' => $ex['title'],
                        'why_chosen' => $ex['why_chosen'],
                    ], $ref['examples'] ?? []),
                ],
            ]);
        }

        $conversation = Conversation::findOrFail($conversationId);
        $brief = Brief::fromJson($conversation->brief ?? []);

        $extraContext = $args['extra_context'] ?? null;
        $agent = $extraContext !== null ? new StyleReferenceAgent($extraContext) : ($this->agent ?? new StyleReferenceAgent);

        try {
            $result = $agent->execute($brief, $team);
        } catch (\Throwable $e) {
            return json_encode(['status' => 'error', 'message' => $e->getMessage()]);
        }

        if (! $result->isOk()) {
            return json_encode(['status' => 'error', 'message' => $result->errorMessage]);
        }

        // Fetch bodies for each submitted example and rebuild the style_reference slot.
        $fetcher = $this->urlFetcher ?? fn (string $url) => (new UrlFetcher)->fetch($url);
        $examples = $result->brief->styleReference()['examples'] ?? [];
        $kept = [];

        foreach ($examples as $ex) {
            $body = $fetcher($ex['url']);
            if (strlen($body) < self::MIN_BODY_CHARS) {
                continue;
            }
            $ex['body'] = $body;
            $kept[] = $ex;
        }

        if (count($kept) < 2) {
            return json_encode([
                'status' => 'error',
                'message' => 'Style reference: fewer than 2 examples had fetchable content (need at least 2).',
            ]);
        }

        $finalBrief = $result->brief->withStyleReference([
            'examples' => $kept,
            'reasoning' => $result->brief->styleReference()['reasoning'] ?? '',
        ]);

        $conversation->update(['brief' => $finalBrief->toJson()]);

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
                'name' => 'fetch_style_reference',
                'description' => 'Run the StyleReference sub-agent. Reads team blog_url / style_reference_urls; writes brief.style_reference with 2–3 exemplar posts and their full bodies. Returns status=skipped if no blog URL or style reference URLs are configured.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'extra_context' => [
                            'type' => 'string',
                            'description' => 'Optional guidance for the sub-agent on retry.',
                        ],
                    ],
                ],
            ],
        ];
    }
}
```

- [ ] **Step 4: Run tests**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=FetchStyleReferenceToolHandler
```

Expected: all PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/FetchStyleReferenceToolHandler.php tests/Unit/Services/FetchStyleReferenceToolHandlerTest.php
git commit -m "feat(writer): add FetchStyleReferenceToolHandler"
```

---

### Task 7: Inject audience + style_reference into EditorAgent and WriterAgent

**Files:**
- Modify: `app/Services/Writer/Agents/EditorAgent.php`
- Modify: `app/Services/Writer/Agents/WriterAgent.php`
- Test: existing `EditorAgentTest.php` and `WriterAgentTest.php` must still pass (no changes needed — blocks are optional)

- [ ] **Step 1: Add audience block helper to EditorAgent**

In `app/Services/Writer/Agents/EditorAgent.php`, add this private method before the closing brace:

```php
    private function audienceBlock(Brief $brief): string
    {
        if (! $brief->hasAudience()) {
            return '';
        }

        $audience = $brief->audience();
        $lines = ["\n## Audience target"];
        $lines[] = 'Mode: ' . ($audience['mode'] ?? 'unknown');

        if (($audience['mode'] ?? '') === 'persona' && ! empty($audience['persona_label'])) {
            $summary = $audience['persona_summary'] ?? '';
            $lines[] = 'Persona: ' . $audience['persona_label'] . ($summary ? ' — ' . $summary : '');
        }

        $lines[] = 'Writer guidance: ' . ($audience['guidance_for_writer'] ?? '');

        return implode("\n", $lines);
    }
```

In `systemPrompt()`, append `$this->audienceBlock($brief)` after the `$extra` variable. Replace the return statement (the `PROMPT` heredoc closing) to append the block:

The system prompt currently ends with:
```php
        return <<<PROMPT
...
## Research claims
{$claimsBlock}
{$extra}

## IMPORTANT
Your turn MUST end with a `submit_outline` call. Any text output is a failure.
PROMPT;
```

Change to:
```php
        $audienceBlock = $this->audienceBlock($brief);

        return <<<PROMPT
...
## Research claims
{$claimsBlock}
{$audienceBlock}
{$extra}

## IMPORTANT
Your turn MUST end with a `submit_outline` call. Any text output is a failure.
PROMPT;
```

- [ ] **Step 2: Add audience + style_reference block helpers to WriterAgent**

In `app/Services/Writer/Agents/WriterAgent.php`, add these private methods before the closing brace:

```php
    private function audienceBlock(Brief $brief): string
    {
        if (! $brief->hasAudience()) {
            return '';
        }

        $audience = $brief->audience();
        $lines = ["\n## Audience target"];
        $lines[] = 'Mode: ' . ($audience['mode'] ?? 'unknown');

        if (($audience['mode'] ?? '') === 'persona' && ! empty($audience['persona_label'])) {
            $summary = $audience['persona_summary'] ?? '';
            $lines[] = 'Persona: ' . $audience['persona_label'] . ($summary ? ' — ' . $summary : '');
        }

        $lines[] = 'Writer guidance: ' . ($audience['guidance_for_writer'] ?? '');

        return implode("\n", $lines);
    }

    private function styleReferenceBlock(Brief $brief): string
    {
        if (! $brief->hasStyleReference()) {
            return '';
        }

        $ref = $brief->styleReference();
        $lines = ["\n## Style reference — match this voice"];
        $lines[] = "The following are real posts from this brand's blog. Match their rhythm, sentence length, opener patterns, register, and feel. Do NOT copy sentences or facts — the new post's content comes from the claims block.";

        foreach ($ref['examples'] as $i => $ex) {
            $lines[] = '';
            $lines[] = '### Example ' . ($i + 1) . ': ' . ($ex['title'] ?? '');
            $lines[] = $ex['body'] ?? '';
        }

        return implode("\n", $lines);
    }
```

In `systemPrompt()`, inject both blocks. Find where `$brandProfile` and `$extra` are used and add the new variables. Replace the existing `return <<<PROMPT` section to include both blocks:

Find this in `systemPrompt()`:
```php
        $brandProfile = $this->brandProfileBlock($team);
        $extra = $this->extraContextBlock();

        return <<<PROMPT
```

Replace with:
```php
        $brandProfile = $this->brandProfileBlock($team);
        $audienceBlock = $this->audienceBlock($brief);
        $styleRefBlock = $this->styleReferenceBlock($brief);
        $extra = $this->extraContextBlock();

        return <<<PROMPT
```

Then in the PROMPT body, find the `## Brand profile` section and insert the two new blocks after it. The prompt currently ends with:

```
## Brand profile
{$brandProfile}
{$extra}

## IMPORTANT
```

Change to:

```
## Brand profile
{$brandProfile}
{$audienceBlock}
{$styleRefBlock}
{$extra}

## IMPORTANT
```

- [ ] **Step 3: Run existing agent tests**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter="EditorAgent|WriterAgent"
```

Expected: all PASS (audience/style blocks are empty strings when slots are absent — no behaviour change for existing tests).

- [ ] **Step 4: Commit**

```bash
git add app/Services/Writer/Agents/EditorAgent.php app/Services/Writer/Agents/WriterAgent.php
git commit -m "feat(writer): inject audience + style_reference blocks into Editor and Writer prompts"
```

---

### Task 8: Update ChatPromptBuilder — register tools, rewrite orchestrator

**Files:**
- Modify: `app/Services/ChatPromptBuilder.php`

- [ ] **Step 1: Register the two new tool schemas in tools()**

In `app/Services/ChatPromptBuilder.php`, add the two new `use` statements at the top of the file, after the existing ones:

```php
use App\Services\FetchStyleReferenceToolHandler;
use App\Services\PickAudienceToolHandler;
```

In the `tools()` method, replace the `'writer'` case:

```php
            'writer' => [
                ResearchTopicToolHandler::toolSchema(),
                PickAudienceToolHandler::toolSchema(),
                CreateOutlineToolHandler::toolSchema(),
                FetchStyleReferenceToolHandler::toolSchema(),
                WriteBlogPostToolHandler::toolSchema(),
                ProofreadBlogPostToolHandler::toolSchema(),
                BrandIntelligenceToolHandler::fetchUrlToolSchema(),
            ],
```

- [ ] **Step 2: Simplify writerPrompt() and rewrite orchestratorPrompt()**

Replace the `writerPrompt()` method (currently lines 225–233):

```php
    private static function writerPrompt(string $profile, bool $hasProfile, ?Conversation $conversation): string
    {
        $brief = Brief::fromJson($conversation?->brief ?? []);
        return self::orchestratorPrompt($profile, $hasProfile, $brief);
    }
```

Replace the entire `orchestratorPrompt()` method with:

```php
    private static function orchestratorPrompt(string $profile, bool $hasProfile, Brief $brief): string
    {
        $statusBlock = $brief->statusSummary();

        return <<<PROMPT
You orchestrate a blog writing pipeline. You DO NOT do research, write outlines, or write blog posts yourself. You call sub-agent tools. They do the work.

## Your tools (call these in order — each fills a brief slot)
- research_topic — runs the Research sub-agent. Fills brief.research.
- pick_audience — runs the AudiencePicker sub-agent. Fills brief.audience. Requires brief.research. Returns status=skipped if no personas are configured — treat skipped as success and continue.
- create_outline — runs the Editor sub-agent. Fills brief.outline. Requires brief.research.
- fetch_style_reference — runs the StyleReference sub-agent. Fills brief.style_reference. Requires brief.outline. Returns status=skipped if no blog URL is configured — treat skipped as success and continue.
- write_blog_post — runs the Writer sub-agent. Creates a ContentPiece. Requires brief.research and brief.outline.
- proofread_blog_post(feedback) — runs the Proofread sub-agent on the existing piece. Call only when the user asks for revisions. Requires brief.content_piece_id.

## Pipeline order
Run tools back-to-back without pausing for approval:
1. research_topic
2. pick_audience
3. create_outline
4. fetch_style_reference
5. write_blog_post
6. After write_blog_post completes, send a short plain-text summary and invite the user to review.

Brief plain-text status lines between calls are fine ("Researching…", "Outlining…"). Do NOT narrate the content of tool results.

## CRITICAL: function calling
You only do work through tool calls. Never narrate research, outlines, or prose in plain text.

## Handling skipped tools
When a tool returns {status: skipped}, log it briefly ("Audience step skipped — no personas configured.") and immediately call the next tool in the pipeline.

## Brief status (current state)
<brief-status>
{$statusBlock}
</brief-status>

## Retry policy
When a tool returns {status: error, message: ...}, retry that tool ONCE per turn with an `extra_context` argument explaining what to fix. After one retry, surface the issue to the user and ask for guidance.

## Good / bad examples
GOOD: tool call → wait → tool call → wait → tool call → narrate result.
BAD: narrate "I researched the topic and found c1: …" without calling research_topic. Nothing is saved.

## Brand context (reference data — do not echo back)
<brand-profile>
{$profile}
</brand-profile>
PROMPT;
    }
```

- [ ] **Step 3: Run the ChatPromptBuilder tests**

```bash
cd marketminded-laravel && ./vendor/bin/sail test --filter=ChatPromptBuilder
```

Expected: all PASS. If `ChatPromptBuilderWriterTest` checks for mode-specific strings like "autopilot" or "checkpoint", those tests need to be updated — remove assertions that check for those strings and replace with assertions on the new pipeline language (e.g., `toContain('pick_audience')`).

- [ ] **Step 4: Commit**

```bash
git add app/Services/ChatPromptBuilder.php
git commit -m "feat(writer): register new tools in ChatPromptBuilder; replace checkpoint orchestrator with always-autorun"
```

---

### Task 9: Update the blade — wire handlers, add cards, remove checkpoint UI

**Files:**
- Modify: `resources/views/pages/teams/⚡create-chat.blade.php`

This is the largest single-file change. Work through it section by section.

- [ ] **Step 1: Add new use imports at the top of the blade**

Find the existing use block (around line 6–16):

```php
use App\Services\BrandIntelligenceToolHandler;
...
use App\Services\WriteBlogPostToolHandler;
```

Add two new lines:

```php
use App\Services\FetchStyleReferenceToolHandler;
use App\Services\PickAudienceToolHandler;
```

- [ ] **Step 2: Remove $writerMode property and selectWriterMode() method**

Find and delete:
- The `$writerMode` state property (e.g. `public string $writerMode = 'autopilot';`)
- The `mount()` line that sets it: `$this->writerMode = $this->conversation->writer_mode;`
- The `selectWriterMode()` method (lines ~76–80)

- [ ] **Step 3: Remove !autopilot / !checkpoint command handling**

Find the block handling `!autopilot` and `!checkpoint` commands (around lines 101–109) and delete it.

- [ ] **Step 4: Instantiate the two new handlers in sendMessage()**

Find the handler instantiation block (around lines 197–202):

```php
        $researchHandler = new ResearchTopicToolHandler;
        $outlineHandler = new CreateOutlineToolHandler;
        $writeHandler = new WriteBlogPostToolHandler;
        $proofreadHandler = new ProofreadBlogPostToolHandler;
```

Add after `$outlineHandler`:

```php
        $audienceHandler = new PickAudienceToolHandler;
        $styleRefHandler = new FetchStyleReferenceToolHandler;
```

Update the `use` closure to capture the new variables. Find:

```php
            $writeHandler, $proofreadHandler, $team, $conversation, &$priorTurnTools
```

Replace with:

```php
            $audienceHandler, $styleRefHandler, $writeHandler, $proofreadHandler, $team, $conversation, &$priorTurnTools
```

- [ ] **Step 5: Add new tool dispatch in the toolExecutor closure**

Find the `if ($name === 'research_topic')` block (around line 225) and add two new branches after it:

```php
            if ($name === 'pick_audience') {
                $result = $audienceHandler->execute($team, $conversation->id, $args, $priorTurnTools);
            }

            if ($name === 'fetch_style_reference') {
                $result = $styleRefHandler->execute($team, $conversation->id, $args, $priorTurnTools);
            }
```

- [ ] **Step 6: Add new tools to $writerTools array**

Find:

```php
        $writerTools = ['research_topic', 'create_outline', 'write_blog_post', 'proofread_blog_post'];
```

Replace with:

```php
        $writerTools = ['research_topic', 'pick_audience', 'create_outline', 'fetch_style_reference', 'write_blog_post', 'proofread_blog_post'];
```

- [ ] **Step 7: Add active-tool labels for streaming UI**

Find the active-tool labels array (around lines 526–529):

```php
            'research_topic' => ['title' => 'Research sub-agent', ...],
            'create_outline' => ['title' => 'Editor sub-agent', ...],
```

Add entries for the two new tools:

```php
            'pick_audience' => ['title' => 'Audience sub-agent', 'hint' => 'Selecting the best audience persona…', 'color' => 'amber'],
            'fetch_style_reference' => ['title' => 'Style sub-agent', 'hint' => 'Finding style reference posts…', 'color' => 'violet'],
```

- [ ] **Step 8: Add streaming cards for the new tools**

Find `contentPieceCards()` (around line 642). The method currently handles `research_topic`, `create_outline`, and writer tools. Add handling for `pick_audience` and `fetch_style_reference` after the `research_topic` branch:

```php
            } elseif ($tool->name === 'pick_audience' && $kind === 'audience') {
                $html .= $this->renderAudienceCard($card);
            } elseif ($tool->name === 'fetch_style_reference' && $kind === 'style_reference') {
                $html .= $this->renderStyleReferenceCard($card);
```

Add the two new render methods to the class:

```php
    private function renderAudienceCard(array $card): string
    {
        $mode = $card['mode'] ?? 'unknown';
        $guidance = e($card['guidance_for_writer'] ?? '');
        $summary = e($card['summary'] ?? 'Audience selected');
        $metricsFooter = $this->buildMetricsFooter($card);

        return <<<HTML
<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
    <div class="text-xs text-amber-400">&#10003; {$summary}</div>
    <div class="mt-1 text-xs text-zinc-400">{$guidance}</div>
    {$metricsFooter}
</div>
HTML;
    }

    private function renderStyleReferenceCard(array $card): string
    {
        $summary = e($card['summary'] ?? 'Style reference ready');
        $examples = $card['examples'] ?? [];
        $metricsFooter = $this->buildMetricsFooter($card);

        $items = '';
        foreach ($examples as $ex) {
            $title = e($ex['title'] ?? '');
            $items .= "<li class=\"text-xs text-zinc-400\">· {$title}</li>";
        }

        return <<<HTML
<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
    <div class="text-xs text-violet-400">&#10003; {$summary}</div>
    <ul class="mt-1 list-none">{$items}</ul>
    {$metricsFooter}
</div>
HTML;
    }
```

Note: `buildMetricsFooter()` should already exist or be extractable from the existing inline metrics logic in the class. If it doesn't exist as a method yet, extract it:

```php
    private function buildMetricsFooter(array $card): string
    {
        $parts = [];
        if (($card['input_tokens'] ?? 0) + ($card['output_tokens'] ?? 0) > 0) {
            $parts[] = number_format(($card['input_tokens'] ?? 0) + ($card['output_tokens'] ?? 0)) . ' tokens';
        }
        if (($card['cost'] ?? 0) > 0) {
            $parts[] = '$' . number_format($card['cost'], 4);
        }
        if (empty($parts)) {
            return '';
        }
        $text = e(implode(' · ', $parts));
        return "<div class=\"mt-2 border-t border-zinc-700 pt-2 text-xs text-zinc-500\">{$text}</div>";
    }
```

- [ ] **Step 9: Add history cards for new tools**

Find the history `@foreach` loop that renders tool cards from `$message['metadata']['tools']` (around line 835). Add two new `@elseif` branches after `create_outline`:

```blade
@elseif ($tool['name'] === 'pick_audience' && $kind === 'audience')
    <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
        <div class="text-xs text-amber-400">&#10003; {{ $card['summary'] ?? 'Audience selected' }}</div>
        <div class="mt-1 text-xs text-zinc-400">{{ $card['guidance_for_writer'] ?? '' }}</div>
        {!! $metricsFooter !!}
    </div>
@elseif ($tool['name'] === 'fetch_style_reference' && $kind === 'style_reference')
    <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
        <div class="text-xs text-violet-400">&#10003; {{ $card['summary'] ?? 'Style reference ready' }}</div>
        <ul class="mt-1 list-none">
            @foreach ($card['examples'] ?? [] as $ex)
                <li class="text-xs text-zinc-400">· {{ $ex['title'] ?? '' }}</li>
            @endforeach
        </ul>
        {!! $metricsFooter !!}
    </div>
```

- [ ] **Step 10: Remove the mode tile UI and mode badge**

Find and delete:

1. The mode badge in the chat header (around line 755–756):
```blade
<flux:badge variant="pill" size="sm" color="{{ $writerMode === 'autopilot' ? 'indigo' : 'amber' }}">
    {{ $writerMode === 'autopilot' ? __('Autopilot') : __('Checkpoint') }}
</flux:badge>
```

2. The mode tile sub-cards UI block (around lines 1019–1030 — the autopilot/checkpoint selection buttons shown before the first message). Delete the entire block including the subheading and both `<button wire:click="selectWriterMode(...)">` elements.

- [ ] **Step 11: Run the full test suite**

```bash
cd marketminded-laravel && ./vendor/bin/sail test
```

Expected: all PASS. If any existing test references `writer_mode`, `selectWriterMode`, `autopilot`, or `checkpoint` on conversations, update it to remove those references.

- [ ] **Step 12: Commit**

```bash
git add resources/views/pages/teams/
git commit -m "feat(writer): wire pick_audience + fetch_style_reference; remove checkpoint mode UI"
```

---

### Task 10: Full suite + manual QA

- [ ] **Step 1: Run the complete test suite**

```bash
cd marketminded-laravel && ./vendor/bin/sail test
```

Expected: all green.

- [ ] **Step 2: Manual QA checklist**

Start Sail if not running:

```bash
cd marketminded-laravel && ./vendor/bin/sail up -d
```

In a browser:

- [ ] Create a writer conversation on a team **with personas**. Confirm the pipeline runs: research → audience (persona card appears) → outline → style_reference (if blog_url set) → write.
- [ ] Create a writer conversation on a team **without personas**. Confirm audience step shows "skipped" in the orchestrator's status message and the pipeline continues to outline.
- [ ] Create a writer conversation on a team **without blog_url and no style_reference_urls**. Confirm style_reference step is skipped and write_blog_post still runs.
- [ ] Reload a completed conversation. Confirm audience and style_reference cards render in history.
- [ ] Confirm there is no autopilot/checkpoint mode tile on the new conversation creation screen.
- [ ] Confirm there is no mode badge in the chat header.

- [ ] **Step 3: Commit**

No code changes — this is a verification step. If issues found, fix and commit before marking done.

---

## Self-Review

**Spec coverage:**
- ✓ Pipeline shape: research → pick_audience → create_outline → fetch_style_reference → write_blog_post (Tasks 8, 9)
- ✓ Checkpoint mode removal: writerMode property, selectWriterMode(), command interception, mode tiles, mode badge, DB column (Tasks 2, 8, 9)
- ✓ Brief audience + style_reference slots (Task 1)
- ✓ AudiencePickerAgent: three modes, validation, persona hydration (Task 3)
- ✓ StyleReferenceAgent: body-less submit, fetch in handler, 400-char threshold (Tasks 4, 6)
- ✓ PickAudienceToolHandler: skipped if no personas, retry guard (Task 5)
- ✓ FetchStyleReferenceToolHandler: skipped if no blog_url + style_reference_urls, body fetch, 2-survivor minimum (Task 6)
- ✓ EditorAgent + WriterAgent audience/style injection (Task 7)
- ✓ ChatPromptBuilder: new tools registered, orchestrator rewrites to autorun (Task 8)
- ✓ Blade: new handlers wired, tool dispatch, streaming + history cards (Task 9)

**Type consistency:**
- `Brief::withAudience()` / `hasAudience()` / `audience()` used consistently across Tasks 1, 3, 5, 7, 9
- `Brief::withStyleReference()` / `hasStyleReference()` / `styleReference()` used consistently across Tasks 1, 4, 6, 7, 9
- Tool names `pick_audience` and `fetch_style_reference` used consistently in handlers, schemas, blade dispatch, and blade card rendering
- `AgentResult::ok(brief, cardPayload, summary)` signature matches existing usage throughout
