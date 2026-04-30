<?php

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Services\BrandIntelligenceToolHandler;
use App\Services\ChatPromptBuilder;
use App\Services\CreateOutlineToolHandler;
use App\Services\FetchStyleReferenceToolHandler;
use App\Services\OpenRouterClient;
use App\Services\PickAudienceToolHandler;
use App\Services\ResearchTopicToolHandler;
use App\Services\StreamResult;
use App\Services\ToolEvent;
use App\Services\TopicToolHandler;
use App\Services\ProofreadBlogPostToolHandler;
use App\Services\UrlFetcher;
use App\Services\WriteBlogPostToolHandler;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component
{
    public Team $teamModel;

    public ?Conversation $conversation = null;

    public ?string $type = null;

    public string $prompt = '';

    public bool $isStreaming = false;

    public ?string $topicsMode = null;

    public ?int $topicId = null;

    public array $messages = [];

    public function mount(Team $current_team, ?Conversation $conversation = null): void
    {
        $this->teamModel = $current_team;
        $this->conversation = $conversation;
        $this->type = $conversation?->type ?? request('type') ?: null;

        if ($conversation) {
            $this->loadMessages();
            $this->topicId = $conversation->topic_id;
        }
    }

    public function selectType(string $type): void
    {
        $this->type = $type;

        if ($this->conversation) {
            $this->conversation->update(['type' => $type]);
            $this->conversation->refresh();
        }
    }

    public function selectTopicsMode(string $mode): void
    {
        $this->topicsMode = $mode;

        if ($mode === 'discover') {
            $this->prompt = __('Search for current trends and news in my industry and propose 3-5 content topics.');
            $this->submitPrompt();
        }
    }

    public function selectWriterTopic(int $topicId): void
    {
        $topic = \App\Models\Topic::where('team_id', $this->teamModel->id)
            ->where('status', 'available')
            ->findOrFail($topicId);

        $this->topicId = $topic->id;
        $this->prompt = __("Let's write a blog post about: :title", ['title' => $topic->title]);

        if ($this->conversation) {
            $this->conversation->update(['topic_id' => $topic->id]);
            $this->conversation->refresh();
        }
    }

    public function submitPrompt(): void
    {
        $content = trim($this->prompt);

        if ($content === '' || $this->isStreaming || ! $this->type) {
            return;
        }

        if (! $this->teamModel->ai_api_key) {
            \Flux\Flux::toast(variant: 'danger', text: __('API key required. Add it in Team Settings.'));
            return;
        }

        if (! $this->conversation) {
            $this->conversation = Conversation::create([
                'team_id' => $this->teamModel->id,
                'user_id' => Auth::id(),
                'title' => mb_substr($content, 0, 80),
                'type' => $this->type,
                'topic_id' => $this->topicId ?: null,
            ]);
            $url = route('create.chat', ['current_team' => $this->teamModel, 'conversation' => $this->conversation]);
            $this->js("history.replaceState(null, '', '" . addslashes($url) . "')");
        } elseif ($this->conversation->title === __('New conversation')) {
            $this->conversation->update(['title' => mb_substr($content, 0, 80)]);
        }

        Message::create([
            'conversation_id' => $this->conversation->id,
            'role' => 'user',
            'content' => $content,
        ]);

        $this->messages[] = [
            'role' => 'user',
            'content' => $content,
            'metadata' => null,
        ];
        $this->prompt = '';
        $this->isStreaming = true;

        $this->js('$wire.ask()');
    }

    public function ask(): void
    {
        // 15 minutes: a full autopilot run (research + outline + write) can
        // take a few minutes each step with a large brand profile + big model.
        set_time_limit(900);
        // Let the PHP process run to completion even if the client stops the
        // stream — otherwise the finally block (which persists the message
        // and writes chat-debug.log) may be killed mid-flight, losing the
        // debug trace for aborted attempts.
        ignore_user_abort(true);

        $type = $this->conversation->type;
        $this->teamModel->refresh();
        $this->conversation->load('topic');

        // Hydrate brief.topic on first writer turn if missing (also covers
        // conversations created before the brief column existed).
        if ($type === 'writer' && $this->conversation->topic && empty(($this->conversation->brief ?? [])['topic'])) {
            $topic = $this->conversation->topic;
            $brief = $this->conversation->brief ?? [];
            $brief['topic'] = [
                'id' => $topic->id,
                'title' => $topic->title,
                'angle' => $topic->angle,
                'sources' => $topic->sources ?? [],
            ];
            $this->conversation->update(['brief' => $brief]);
            $this->conversation->refresh();
        }

        $systemPrompt = ChatPromptBuilder::build($type, $this->teamModel, $this->conversation);
        $tools = ChatPromptBuilder::tools($type);

        $apiMessages = collect($this->messages)
            ->map(fn ($m) => ['role' => $m['role'], 'content' => $m['content']])
            ->toArray();

        $client = new OpenRouterClient(
            apiKey: $this->teamModel->ai_api_key,
            model: $this->teamModel->fast_model,
            urlFetcher: new UrlFetcher,
            maxIterations: 8,
            baseUrl: $this->teamModel->ai_api_url ?? 'https://openrouter.ai/api/v1',
            provider: $this->teamModel->ai_provider ?? 'openrouter',
        );

        $brandHandler = new BrandIntelligenceToolHandler;
        $topicHandler = new TopicToolHandler;
        $researchHandler = new ResearchTopicToolHandler;
        $audienceHandler = new PickAudienceToolHandler;
        $outlineHandler = new CreateOutlineToolHandler;
        $styleRefHandler = new FetchStyleReferenceToolHandler;
        $writeHandler = new WriteBlogPostToolHandler;
        $proofreadHandler = new ProofreadBlogPostToolHandler;
        $team = $this->teamModel;
        $conversation = $this->conversation;

        // Tool calls completed earlier in this ask() turn. The writer's gate
        // checks this first so research_topic results are visible to
        // create_outline and write_blog_post within the same turn (before the
        // assistant message is persisted).
        $priorTurnTools = [];

        $toolExecutor = function (string $name, array $args) use (
            $brandHandler, $topicHandler, $researchHandler, $audienceHandler, $outlineHandler,
            $styleRefHandler, $writeHandler, $proofreadHandler, $team, $conversation, &$priorTurnTools
        ): string {
            if ($name === 'update_brand_intelligence') {
                return $brandHandler->execute($team, $args);
            }
            if ($name === 'save_topics') {
                return $topicHandler->execute($team, $conversation->id, $args);
            }
            if ($name === 'fetch_url') {
                return (new UrlFetcher)->fetch($args['url'] ?? '');
            }
            if ($name === 'research_topic') {
                $result = $researchHandler->execute($team, $conversation->id, $args, $priorTurnTools);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            if ($name === 'pick_audience') {
                $result = $audienceHandler->execute($team, $conversation->id, $args, $priorTurnTools);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            if ($name === 'create_outline') {
                $result = $outlineHandler->execute($team, $conversation->id, $args, $priorTurnTools);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            if ($name === 'fetch_style_reference') {
                $result = $styleRefHandler->execute($team, $conversation->id, $args, $priorTurnTools);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            if ($name === 'write_blog_post') {
                $result = $writeHandler->execute($team, $conversation->id, $args, $priorTurnTools);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            if ($name === 'proofread_blog_post') {
                $result = $proofreadHandler->execute($team, $conversation->id, $args, $priorTurnTools);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];
                return $result;
            }
            return "Unknown tool: {$name}";
        };

        $fullContent = '';
        $streamResult = null;
        $completedTools = [];
        $interrupted = false;

        try {
            $useServerTools = $this->teamModel->ai_provider !== 'custom';
            foreach ($client->streamChatWithTools($systemPrompt, $apiMessages, $tools, $toolExecutor, temperature: 0.7, useServerTools: $useServerTools) as $item) {
                if ($item instanceof ToolEvent) {
                    if ($item->status === 'completed') {
                        $completedTools[] = $item;
                    }
                    $activeTool = $item->status === 'started' ? $item : null;
                    $this->streamUI($this->cleanContent($fullContent), $completedTools, $activeTool);
                } elseif ($item instanceof StreamResult) {
                    $streamResult = $item;
                } else {
                    $fullContent .= $item;
                    $this->streamUI($this->cleanContent($fullContent), $completedTools, null);
                }

                if (connection_aborted()) {
                    $interrupted = true;
                    break;
                }
            }
        } catch (\Throwable $e) {
            $interrupted = true;
            \Log::error('Chat streaming error', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            if (! $fullContent && empty($completedTools)) {
                $fullContent = 'Error: ' . $e->getMessage();
            }
        } finally {
            // Always save the message, even if the connection was interrupted.
            // Tool side effects (e.g. Topic::create) are already committed,
            // so the message metadata must be persisted to render cards.
            ignore_user_abort(true);

            $fullContent = $this->cleanContent($fullContent);

            $metadata = [];
            if (! empty($completedTools)) {
                $metadata['tools'] = collect($completedTools)->map(function (ToolEvent $t) {
                    $entry = [
                        'name' => $t->name,
                        'args' => $t->arguments,
                    ];
                    $result = json_decode($t->result ?? '{}', true);
                    if (isset($result['card'])) {
                        $entry['card'] = $result['card'];
                    }
                    if (isset($result['piece_id'])) {
                        $entry['piece_id'] = $result['piece_id'];
                    }
                    if (isset($result['status'])) {
                        $entry['status'] = $result['status'];
                    }
                    return $entry;
                })->toArray();
            }
            if ($streamResult?->webSearchRequests > 0) {
                $metadata['web_searches'] = $streamResult->webSearchRequests;
            }
            if ($interrupted) {
                $metadata['interrupted'] = true;
            }

            // Only save if there's content or tool calls (avoid empty messages)
            if ($fullContent !== '' || ! empty($metadata)) {
                Message::create([
                    'conversation_id' => $this->conversation->id,
                    'role' => 'assistant',
                    'content' => $fullContent,
                    'model' => $this->teamModel->fast_model,
                    'input_tokens' => $streamResult?->inputTokens ?? 0,
                    'output_tokens' => $streamResult?->outputTokens ?? 0,
                    'cost' => $streamResult?->cost ?? 0,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                ]);

                $this->messages[] = [
                    'role' => 'assistant',
                    'content' => $fullContent,
                    'metadata' => ! empty($metadata) ? $metadata : null,
                    'input_tokens' => $streamResult?->inputTokens ?? 0,
                    'output_tokens' => $streamResult?->outputTokens ?? 0,
                    'cost' => $streamResult?->cost ?? 0,
                ];
            }

            $this->writeChatDebugLog(
                systemPrompt: $systemPrompt,
                tools: $tools,
                apiMessages: $apiMessages,
                responseContent: $fullContent,
                completedTools: $completedTools,
                streamResult: $streamResult,
                interrupted: $interrupted,
            );

            $this->isStreaming = false;
        }
    }

    /**
     * Append a structured JSON line to storage/logs/chat-debug.log for each
     * completed ask() turn. Captures everything needed to diagnose why a model
     * did or did not call tools: full system prompt, registered tool schemas,
     * conversation history sent, raw response, every tool invocation with args
     * and result, plus token/cost/web-search metrics.
     */
    private function writeChatDebugLog(
        string $systemPrompt,
        array $tools,
        array $apiMessages,
        string $responseContent,
        array $completedTools,
        ?StreamResult $streamResult,
        bool $interrupted,
    ): void {
        $entry = [
            'ts' => now()->toIso8601String(),
            'conversation_id' => $this->conversation->id,
            'type' => $this->conversation->type,
            'topic_id' => $this->conversation->topic_id,
            'team_id' => $this->teamModel->id,
            'model' => $this->teamModel->fast_model,
            'system_prompt' => $systemPrompt,
            'tool_schemas' => array_map(fn ($t) => $t['function']['name'] ?? ($t['type'] ?? 'unknown'), $tools),
            'history_sent' => $apiMessages,
            'response_content' => $responseContent,
            'tool_calls' => array_map(fn (ToolEvent $t) => [
                'name' => $t->name,
                'args' => $t->arguments,
                'result' => mb_substr((string) ($t->result ?? ''), 0, 2000),
            ], $completedTools),
            'input_tokens' => $streamResult?->inputTokens ?? 0,
            'output_tokens' => $streamResult?->outputTokens ?? 0,
            'cost' => (float) ($streamResult?->cost ?? 0),
            'web_searches' => (int) ($streamResult?->webSearchRequests ?? 0),
            'interrupted' => $interrupted,
        ];

        try {
            $path = storage_path('logs/chat-debug.log');
            file_put_contents(
                $path,
                json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n",
                FILE_APPEND | LOCK_EX,
            );
        } catch (\Throwable $e) {
            \Log::warning('chat-debug log write failed', ['error' => $e->getMessage()]);
        }
    }

    public function getConversationStatsProperty(): array
    {
        if (! $this->conversation) {
            return ['context' => 0];
        }

        $lastAssistant = $this->conversation->messages()->where('role', 'assistant')->latest('id')->first();

        return [
            'context' => (int) ($lastAssistant?->input_tokens ?? 0),
        ];
    }

    public function render()
    {
        return $this->view()->title($this->conversation?->title ?? __('New conversation'));
    }

    private function loadMessages(): void
    {
        $this->messages = $this->conversation->messages
            ->map(fn (Message $m) => [
                'role' => $m->role,
                'content' => $this->cleanContent($m->content),
                'metadata' => $m->metadata,
                'input_tokens' => $m->input_tokens,
                'output_tokens' => $m->output_tokens,
                'cost' => (float) $m->cost,
            ])
            ->toArray();
    }

    private function cleanContent(string $content): string
    {
        $maxPasses = 5;
        for ($i = 0; $i < $maxPasses; $i++) {
            $cleaned = preg_replace(
                "/\[\{['\"]type['\"]: ['\"]text['\"], ['\"]text['\"]: ['\"](.+?)['\"]\}\]/s",
                '$1',
                $content
            );
            if ($cleaned === $content) {
                break;
            }
            $content = $cleaned;
        }

        $decoded = json_decode($content, true);
        if (is_array($decoded) && isset($decoded[0]['type']) && $decoded[0]['type'] === 'text') {
            $content = collect($decoded)->pluck('text')->implode('');
        }

        $content = str_replace(["\\'", '\\"', '\\n'], ["'", '"', "\n"], $content);

        // Collapse runs of 3+ newlines (multiple blank lines) down to a single
        // blank line. These appear when the model emits text chunks between
        // tool calls and each chunk starts/ends with its own newlines.
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        return trim($content);
    }

    /**
     * Stream UI: text content first, then tools below as pills, then active tool.
     */
    private function streamUI(string $content, array $completedTools, ?ToolEvent $activeTool): void
    {
        $html = '';

        // Text content first
        if ($content !== '') {
            $isError = str_starts_with($content, 'Error:');
            $textClass = $isError ? 'whitespace-pre-wrap text-red-400' : 'whitespace-pre-wrap';
            $html .= '<div class="' . $textClass . '">' . e($content) . '</div>';
        }

        // Writer sub-agents get dedicated full-width cards instead of small pills.
        // Suppress the pill spinner for them but keep $activeTool intact so the
        // activeSubAgentCard() at the bottom still renders.
        $writerTools = ['research_topic', 'pick_audience', 'create_outline', 'fetch_style_reference', 'write_blog_post', 'proofread_blog_post'];
        $isWriterTool = $activeTool && in_array($activeTool->name, $writerTools, true);

        if ($activeTool && ! $isWriterTool) {
            $label = match ($activeTool->name) {
                'fetch_url' => 'Reading ' . ($activeTool->arguments['url'] ?? ''),
                'update_brand_intelligence' => 'Updating brand profile',
                'save_topics' => 'Saving topics...',
                default => $activeTool->name,
            };
            $html .= '<div class="mt-2 flex flex-wrap items-center gap-1.5">';
            foreach ($completedTools as $tool) {
                $html .= $this->toolPill($tool, false);
            }
            $html .= '<span class="inline-flex items-center gap-1 rounded-full bg-indigo-500/10 px-2.5 py-0.5 text-xs text-indigo-400"><svg class="size-3.5 animate-spin inline-block" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> ' . e($label) . '</span>';
            $html .= '</div>';
        } elseif (! empty($completedTools)) {
            // All tools done — show completed pills (excludes writer tools; those get their own cards)
            $pills = array_filter($completedTools, fn ($t) => ! in_array($t->name, $writerTools, true));
            if (! empty($pills)) {
                $html .= '<div class="mt-2 flex flex-wrap items-center gap-1.5">';
                foreach ($pills as $tool) {
                    $html .= $this->toolPill($tool, false);
                }
                $html .= '</div>';
            }
        } elseif ($content === '') {
            $html .= '<span class="inline-flex items-center gap-1.5 text-zinc-500"><svg class="size-3.5 animate-spin inline-block" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> Thinking...</span>';
        }

        // Saved topic cards
        $html .= $this->savedTopicCards($completedTools);
        $html .= $this->contentPieceCards($completedTools);
        // Processing card for the currently-running sub-agent, if any
        if ($activeTool !== null) {
            $html .= $this->activeSubAgentCard($activeTool);
        }

        $this->stream(to: 'streamed-response', content: $html, replace: true);
    }

    /**
     * Render a full-width "working" card while a sub-agent tool is in flight.
     * Gives the user a visible dedicated area per dispatched agent (rather
     * than just the small pill), with a note that the agent's output will
     * replace this card when it finishes.
     */
    private function activeSubAgentCard(ToolEvent $activeTool): string
    {
        $agentMap = [
            'research_topic' => ['title' => 'Research sub-agent', 'hint' => 'Searching the web and extracting structured claims…', 'color' => 'purple'],
            'pick_audience' => ['title' => 'Audience sub-agent', 'hint' => 'Selecting the best audience persona…', 'color' => 'amber'],
            'create_outline' => ['title' => 'Editor sub-agent', 'hint' => 'Building an outline from the research claims…', 'color' => 'blue'],
            'fetch_style_reference' => ['title' => 'Style sub-agent', 'hint' => 'Finding style reference posts…', 'color' => 'violet'],
            'write_blog_post' => ['title' => 'Writer sub-agent', 'hint' => 'Composing the blog post from the outline…', 'color' => 'green'],
            'proofread_blog_post' => ['title' => 'Proofread sub-agent', 'hint' => 'Applying the requested revisions…', 'color' => 'green'],
        ];

        if (! isset($agentMap[$activeTool->name])) {
            return '';
        }

        $meta = $agentMap[$activeTool->name];
        $colorText = "text-{$meta['color']}-400";

        $spinner = '<svg class="size-3.5 animate-spin inline-block" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>';

        return sprintf(
            '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">'
            . '<div class="flex items-center gap-2">'
            . '<span class="%s">%s</span>'
            . '<span class="text-xs font-semibold %s">%s</span>'
            . '<span class="text-xs text-zinc-500">working…</span>'
            . '</div>'
            . '<div class="mt-1 text-xs text-zinc-400">%s</div>'
            . '</div>',
            $colorText,
            $spinner,
            $colorText,
            e($meta['title']),
            e($meta['hint']),
        );
    }

    /**
     * Small cost/tokens footer appended to a completed sub-agent card.
     */
    private function cardMetricsFooter(array $card): string
    {
        $cost = (float) ($card['cost'] ?? 0);
        $inTok = (int) ($card['input_tokens'] ?? 0);
        $outTok = (int) ($card['output_tokens'] ?? 0);

        if ($cost === 0.0 && $inTok === 0 && $outTok === 0) {
            return '';
        }

        $parts = [];
        if ($inTok > 0 || $outTok > 0) {
            $parts[] = number_format($inTok + $outTok) . ' tokens';
        }
        if ($cost > 0) {
            $parts[] = '$' . number_format($cost, 4);
        }

        return '<div class="mt-2 border-t border-zinc-700 pt-2 text-xs text-zinc-500">' . e(implode(' · ', $parts)) . '</div>';
    }

    private function toolPill(ToolEvent $tool, bool $active): string
    {
        $label = match ($tool->name) {
            'fetch_url' => 'Read ' . ($tool->arguments['url'] ?? ''),
            'update_brand_intelligence' => 'Updated profile: ' . implode(', ', json_decode($tool->result ?? '{}', true)['sections'] ?? []),
            'save_topics' => 'Saved ' . (json_decode($tool->result ?? '{}', true)['count'] ?? 0) . ' topics',
            'research_topic' => (function () use ($tool) {
                $r = json_decode($tool->result ?? '{}', true);
                return ($r['status'] ?? null) === 'ok'
                    ? 'Gathered ' . count($r['card']['claims'] ?? []) . ' claims'
                    : 'Research failed';
            })(),
            'create_outline' => (function () use ($tool) {
                $r = json_decode($tool->result ?? '{}', true);
                return ($r['status'] ?? null) === 'ok' ? 'Outline ready' : 'Outline failed';
            })(),
            'write_blog_post' => (function () use ($tool) {
                $r = json_decode($tool->result ?? '{}', true);
                return ($r['status'] ?? null) === 'ok' ? 'Draft created' : 'Draft failed';
            })(),
            'proofread_blog_post' => (function () use ($tool) {
                $r = json_decode($tool->result ?? '{}', true);
                return ($r['status'] ?? null) === 'ok' ? 'Revised' : 'Proofread failed';
            })(),
            default => $tool->name,
        };

        $classes = 'inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs';
        $classes .= $active
            ? ' bg-indigo-500/10 text-indigo-400'
            : ' bg-zinc-500/10 text-zinc-500';

        $icon = $active ? '<span class="animate-spin">&#8635;</span>' : '&#10003;';

        return "<span class=\"{$classes}\">{$icon} " . e($label) . '</span>';
    }

    private function savedTopicCards(array $completedTools): string
    {
        $html = '';
        $topicsUrl = route('topics', ['current_team' => $this->teamModel]);
        foreach ($completedTools as $tool) {
            if ($tool->name !== 'save_topics') {
                continue;
            }
            $topics = $tool->arguments['topics'] ?? [];
            foreach ($topics as $topic) {
                $html .= '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">';
                $html .= '<div class="flex items-center justify-between mb-1">';
                $html .= '<span class="text-xs text-purple-400">&#10003; Saved</span>';
                $html .= '<a href="' . e($topicsUrl) . '" class="text-xs text-zinc-500 hover:text-zinc-300">Manage in Topics &rarr;</a>';
                $html .= '</div>';
                $html .= '<div class="text-sm font-semibold text-zinc-200">' . e($topic['title'] ?? '') . '</div>';
                $html .= '<div class="mt-1 text-xs text-zinc-400">' . e($topic['angle'] ?? '') . '</div>';
                $html .= '</div>';
            }
        }
        return $html;
    }

    private function contentPieceCards(array $completedTools): string
    {
        $html = '';
        $seenPieceIds = [];

        $skippable = ['pick_audience', 'fetch_style_reference'];

        foreach ($completedTools as $tool) {
            $result = json_decode($tool->result ?? '{}', true);
            $status = $result['status'] ?? '';

            if ($status === 'skipped' && in_array($tool->name, $skippable, true)) {
                $html .= $this->renderSkippedCard($tool->name, $result['reason'] ?? '');
                continue;
            }

            if ($status !== 'ok') {
                continue;
            }

            $card = $result['card'] ?? null;
            $kind = $card['kind'] ?? null;

            if ($tool->name === 'research_topic' && $kind === 'research') {
                $html .= $this->renderResearchCard($card);
            } elseif ($tool->name === 'pick_audience' && $kind === 'audience') {
                $html .= $this->renderAudienceCard($card);
            } elseif ($tool->name === 'create_outline' && $kind === 'outline') {
                $html .= $this->renderOutlineCard($card);
            } elseif ($tool->name === 'fetch_style_reference' && $kind === 'style_reference') {
                $html .= $this->renderStyleReferenceCard($card);
            } elseif (in_array($tool->name, ['write_blog_post', 'proofread_blog_post'], true)) {
                $pieceId = $result['piece_id'] ?? null;
                if ($pieceId === null || isset($seenPieceIds[$pieceId])) {
                    continue;
                }
                $seenPieceIds[$pieceId] = true;

                $piece = \App\Models\ContentPiece::where('id', $pieceId)
                    ->where('team_id', $this->teamModel->id)
                    ->first();
                if ($piece) {
                    $html .= $this->renderContentPieceCard($piece, $tool->name, $card ?? []);
                }
            }
        }
        return $html;
    }

    private function renderResearchCard(array $card): string
    {
        $summary = e($card['summary'] ?? 'Research complete');
        $claims = $card['claims'] ?? [];
        // Show first 5 claims as a bullet preview
        $preview = collect(array_slice($claims, 0, 5))
            ->map(fn ($c) => '<li class="text-xs text-zinc-400">' . e($c['text'] ?? '') . '</li>')
            ->implode('');
        $more = count($claims) > 5 ? '<div class="mt-1 text-xs text-zinc-500">…and ' . (count($claims) - 5) . ' more</div>' : '';

        return '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">'
            . '<div class="text-xs text-purple-400">&#10003; ' . $summary . '</div>'
            . '<ul class="mt-1 list-disc pl-5">' . $preview . '</ul>'
            . $more
            . $this->cardMetricsFooter($card)
            . '</div>';
    }

    private function renderOutlineCard(array $card): string
    {
        $summary = e($card['summary'] ?? 'Outline ready');
        $sections = $card['sections'] ?? [];
        $sectionList = collect($sections)
            ->map(fn ($s) => '<li class="text-xs text-zinc-400">' . e($s['heading'] ?? '') . '</li>')
            ->implode('');

        return '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">'
            . '<div class="text-xs text-blue-400">&#10003; ' . $summary . '</div>'
            . '<ul class="mt-1 list-disc pl-5">' . $sectionList . '</ul>'
            . $this->cardMetricsFooter($card)
            . '</div>';
    }

    private function renderAudienceCard(array $card): string
    {
        $guidance = e($card['guidance_for_writer'] ?? '');
        $summary = e($card['summary'] ?? 'Audience selected');

        return '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">'
            . '<div class="text-xs text-amber-400">&#10003; ' . $summary . '</div>'
            . '<div class="mt-1 text-xs text-zinc-400">' . $guidance . '</div>'
            . $this->cardMetricsFooter($card)
            . '</div>';
    }

    private function renderStyleReferenceCard(array $card): string
    {
        $summary = e($card['summary'] ?? 'Style reference ready');
        $examples = $card['examples'] ?? [];
        $items = '';
        foreach ($examples as $ex) {
            $items .= '<li class="text-xs text-zinc-400">· ' . e($ex['title'] ?? '') . '</li>';
        }

        return '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">'
            . '<div class="text-xs text-violet-400">&#10003; ' . $summary . '</div>'
            . '<ul class="mt-1 list-none">' . $items . '</ul>'
            . $this->cardMetricsFooter($card)
            . '</div>';
    }

    private function renderSkippedCard(string $toolName, string $reason): string
    {
        $label = match ($toolName) {
            'pick_audience' => 'Audience step skipped',
            'fetch_style_reference' => 'Style reference skipped',
            default => ucfirst(str_replace('_', ' ', $toolName)) . ' skipped',
        };
        $note = $reason ?: match ($toolName) {
            'pick_audience' => 'No audience personas configured.',
            'fetch_style_reference' => 'No blog URL configured.',
            default => '',
        };

        return '<div class="mt-2 rounded-lg border border-zinc-800 bg-zinc-900/50 p-3">'
            . '<div class="text-xs text-zinc-500">&#8212; ' . e($label) . ($note ? ' &middot; ' . e($note) : '') . '</div>'
            . '</div>';
    }

    private function renderContentPieceCard(\App\Models\ContentPiece $piece, string $toolName, array $card = []): string
    {
        $url = route('content.show', ['current_team' => $this->teamModel, 'contentPiece' => $piece->id]);
        $preview = trim(mb_substr(strip_tags($piece->body), 0, 200));
        $badge = $toolName === 'write_blog_post' ? __('Draft created') : __('Revised');
        $wordCount = str_word_count(strip_tags($piece->body));

        return sprintf(
            '<div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">'
            . '<div class="flex items-center justify-between mb-1">'
            . '<span class="text-xs text-green-400">&#10003; %s &middot; v%d &middot; %s words</span>'
            . '<a href="%s" class="text-xs text-indigo-400 hover:text-indigo-300">%s &rarr;</a>'
            . '</div>'
            . '<div class="text-sm font-semibold text-zinc-200">%s</div>'
            . '<div class="mt-1 text-xs text-zinc-400 line-clamp-3">%s</div>'
            . '%s'
            . '</div>',
            e($badge),
            e($piece->current_version),
            number_format($wordCount),
            e($url),
            e(__('Open')),
            e($piece->title),
            e($preview),
            $this->cardMetricsFooter($card),
        );
    }
}; ?>

<div class="flex h-[calc(100vh-4rem)] flex-col">
    {{-- Header --}}
    <div class="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-3">
        <div class="flex items-center gap-3">
            <flux:button variant="subtle" size="sm" icon="arrow-left" :href="route('create')" wire:navigate />
            <flux:heading size="lg">{{ $conversation?->title ?? __('New conversation') }}</flux:heading>
            @if ($type)
                <flux:badge variant="pill" size="sm">{{ match($type) {
                    'brand' => __('Brand Knowledge'),
                    'topics' => __('Brainstorm'),
                    'writer' => __('Writer'),
                    default => $type,
                } }}</flux:badge>
            @endif
        </div>
        @if ($this->conversationStats['context'] > 0)
            <flux:tooltip content="{{ __('Current context size. Longer chats send more tokens per message -- start a new conversation for a new task to keep costs low.') }}" position="bottom">
                <div class="flex items-center gap-1.5 cursor-help">
                    <flux:text class="text-xs text-zinc-500">{{ number_format($this->conversationStats['context']) }} {{ __('context tokens') }}</flux:text>
                    <flux:icon name="information-circle" variant="mini" class="size-3.5 text-zinc-400" />
                </div>
            </flux:tooltip>
        @endif
    </div>

    {{-- Messages --}}
    <div
        class="flex-1 overflow-y-auto"
        id="chat-scroll"
        x-data
        x-init="
            const el = $el;
            const scroll = () => el.scrollTop = el.scrollHeight;
            scroll();
            new MutationObserver(scroll).observe(el, { childList: true, subtree: true, characterData: true });
        "
    >
        <div class="mx-auto flex w-full max-w-5xl flex-col-reverse px-6 py-4">
            {{-- Streaming response --}}
            @if ($isStreaming)
                <div class="mb-6">
                    <div class="mb-1.5 flex items-center gap-2">
                        <flux:badge variant="pill" color="indigo" size="sm">AI</flux:badge>
                        <flux:icon.loading class="size-3.5 text-zinc-500" />
                    </div>
                    <div class="text-sm" wire:stream="streamed-response"><span class="inline-flex items-center gap-1.5 text-zinc-500"><flux:icon.loading class="size-3.5" /> {{ __('Thinking...') }}</span></div>
                </div>
            @endif

            {{-- Message history (reversed for flex-col-reverse) --}}
            @foreach (array_reverse($messages) as $message)
                @if ($message['role'] === 'user')
                    <div class="mb-6 flex justify-end">
                        <div class="max-w-2xl rounded-2xl rounded-br-md bg-zinc-100 px-4 py-2.5 dark:bg-zinc-700">
                            <p class="text-sm whitespace-pre-wrap">{{ $message['content'] }}</p>
                        </div>
                    </div>
                @else
                    <div class="mb-6">
                        <flux:badge variant="pill" color="indigo" size="sm" class="mb-1.5">AI</flux:badge>
                        <p class="text-sm whitespace-pre-wrap {{ str_starts_with($message['content'], 'Error:') ? 'text-red-400' : '' }}">{{ $message['content'] }}</p>

                        {{-- Tool pills + stats for every assistant message --}}
                        @if (!empty($message['metadata']['tools']) || ($message['input_tokens'] ?? 0) > 0)
                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                @foreach ($message['metadata']['tools'] ?? [] as $tool)
                                    @if ($tool['name'] === 'fetch_url')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-500/10 px-2.5 py-0.5 text-xs text-zinc-500">&#10003; Read {{ $tool['args']['url'] ?? '' }}</span>
                                    @elseif ($tool['name'] === 'update_brand_intelligence')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-500/10 px-2.5 py-0.5 text-xs text-zinc-500">&#10003; Updated profile</span>
                                    @elseif ($tool['name'] === 'save_topics')
                                        <span class="inline-flex items-center gap-1 rounded-full bg-zinc-500/10 px-2.5 py-0.5 text-xs text-zinc-500">&#10003; Saved {{ count($tool['args']['topics'] ?? []) }} topics</span>
                                    @endif
                                @endforeach
                                @if (!empty($message['metadata']['interrupted']))
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-500/10 px-2.5 py-0.5 text-xs text-amber-500">&#9888; {{ __('Interrupted') }}</span>
                                @endif
                                @if (!empty($message['metadata']['web_searches']))
                                    <span class="inline-flex items-center gap-1 rounded-full bg-zinc-500/10 px-2.5 py-0.5 text-xs text-zinc-500">{{ $message['metadata']['web_searches'] }} web searches</span>
                                @endif
                                @if (($message['input_tokens'] ?? 0) + ($message['output_tokens'] ?? 0) > 0)
                                    <span class="text-xs text-zinc-500">{{ number_format(($message['input_tokens'] ?? 0) + ($message['output_tokens'] ?? 0)) }} tokens</span>
                                @endif
                                @if (($message['cost'] ?? 0) > 0)
                                    <span class="text-xs text-zinc-500">&middot; ${{ number_format($message['cost'], 4) }}</span>
                                @endif
                            </div>
                        @endif

                        {{-- Saved topic cards from history --}}
                        @foreach ($message['metadata']['tools'] ?? [] as $tool)
                            @if ($tool['name'] === 'save_topics')
                                @foreach ($tool['args']['topics'] ?? [] as $topic)
                                    <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-purple-400">&#10003; {{ __('Saved') }}</span>
                                            <a href="{{ route('topics', ['current_team' => $teamModel]) }}" wire:navigate class="text-xs text-zinc-500 hover:text-zinc-300">{{ __('Manage in Topics') }} &rarr;</a>
                                        </div>
                                        <div class="text-sm font-semibold text-zinc-200">{{ $topic['title'] ?? '' }}</div>
                                        <div class="mt-1 text-xs text-zinc-400">{{ $topic['angle'] ?? '' }}</div>
                                    </div>
                                @endforeach
                            @endif
                        @endforeach

                        {{-- Sub-agent cards from history --}}
                        @php
                            $seenHistoryPieceIds = [];
                        @endphp
                        @foreach ($message['metadata']['tools'] ?? [] as $tool)
                            @php
                                $status = $tool['status'] ?? 'ok';
                                $skippableHistoryTools = ['pick_audience', 'fetch_style_reference'];
                                if ($status === 'skipped' && in_array($tool['name'], $skippableHistoryTools, true)) {
                                    echo $this->renderSkippedCard($tool['name'], '');
                                    continue;
                                }
                                if ($status !== 'ok') {
                                    continue;
                                }

                                $card = $tool['card'] ?? null;
                                $kind = $card['kind'] ?? null;
                                $metricsParts = [];
                                if ($card) {
                                    if (($card['input_tokens'] ?? 0) + ($card['output_tokens'] ?? 0) > 0) {
                                        $metricsParts[] = number_format(($card['input_tokens'] ?? 0) + ($card['output_tokens'] ?? 0)) . ' tokens';
                                    }
                                    if (($card['cost'] ?? 0) > 0) {
                                        $metricsParts[] = '$' . number_format($card['cost'], 4);
                                    }
                                }
                                $metricsFooter = empty($metricsParts) ? '' : '<div class="mt-2 border-t border-zinc-700 pt-2 text-xs text-zinc-500">' . e(implode(' · ', $metricsParts)) . '</div>';
                            @endphp

                            @if ($tool['name'] === 'research_topic' && $kind === 'research')
                                <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                    <div class="text-xs text-purple-400">&#10003; {{ $card['summary'] ?? 'Research complete' }}</div>
                                    <ul class="mt-1 list-disc pl-5">
                                        @foreach (array_slice($card['claims'] ?? [], 0, 5) as $c)
                                            <li class="text-xs text-zinc-400">{{ $c['text'] ?? '' }}</li>
                                        @endforeach
                                    </ul>
                                    @if (count($card['claims'] ?? []) > 5)
                                        <div class="mt-1 text-xs text-zinc-500">…and {{ count($card['claims']) - 5 }} more</div>
                                    @endif
                                    {!! $metricsFooter !!}
                                </div>
                            @elseif ($tool['name'] === 'pick_audience' && $kind === 'audience')
                                <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                    <div class="text-xs text-amber-400">&#10003; {{ $card['summary'] ?? 'Audience selected' }}</div>
                                    <div class="mt-1 text-xs text-zinc-400">{{ $card['guidance_for_writer'] ?? '' }}</div>
                                    {!! $metricsFooter !!}
                                </div>
                            @elseif ($tool['name'] === 'create_outline' && $kind === 'outline')
                                <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                    <div class="text-xs text-blue-400">&#10003; {{ $card['summary'] ?? 'Outline ready' }}</div>
                                    <ul class="mt-1 list-disc pl-5">
                                        @foreach ($card['sections'] ?? [] as $s)
                                            <li class="text-xs text-zinc-400">{{ $s['heading'] }}</li>
                                        @endforeach
                                    </ul>
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
                            @elseif (in_array($tool['name'], ['write_blog_post', 'proofread_blog_post'], true))
                                @php
                                    $pieceId = $tool['piece_id'] ?? null;
                                    if ($pieceId === null || isset($seenHistoryPieceIds[$pieceId])) {
                                        $piece = null;
                                    } else {
                                        $seenHistoryPieceIds[$pieceId] = true;
                                        $piece = \App\Models\ContentPiece::where('id', $pieceId)
                                            ->where('team_id', $teamModel->id)
                                            ->first();
                                    }
                                    $badge = $tool['name'] === 'write_blog_post' ? __('Draft created') : __('Revised');
                                @endphp
                                @if ($piece)
                                    <div class="mt-2 rounded-lg border border-zinc-700 bg-zinc-900 p-3">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs text-green-400">&#10003; {{ $badge }} &middot; v{{ $piece->current_version }} &middot; {{ number_format(str_word_count(strip_tags($piece->body))) }} words</span>
                                            <a href="{{ route('content.show', ['current_team' => $teamModel, 'contentPiece' => $piece->id]) }}" wire:navigate class="text-xs text-indigo-400 hover:text-indigo-300">{{ __('Open') }} &rarr;</a>
                                        </div>
                                        <div class="text-sm font-semibold text-zinc-200">{{ $piece->title }}</div>
                                        <div class="mt-1 text-xs text-zinc-400 line-clamp-3">{{ mb_substr(strip_tags($piece->body), 0, 200) }}</div>
                                        {!! $metricsFooter !!}
                                    </div>
                                @endif
                            @endif
                        @endforeach
                    </div>
                @endif
            @endforeach

            {{-- Type selection (no type yet, no messages) --}}
            @if (!$type && empty($messages))
                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('What would you like to create?') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('Choose a mode to get started.') }}</flux:subheading>

                    <div class="grid w-full max-w-3xl gap-3 sm:grid-cols-3">
                        <button wire:click="selectType('brand')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="building-storefront" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Build brand knowledge') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Improve copywriting performance with deep brand understanding') }}</flux:text>
                        </button>

                        <button wire:click="selectType('topics')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="light-bulb" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Brainstorm topics') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Generate fresh content ideas for your audience') }}</flux:text>
                        </button>

                        <button wire:click="selectType('writer')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="document-text" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Write a blog post') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Produce a cornerstone blog post grounded in one of your topics') }}</flux:text>
                        </button>
                    </div>
                </div>
            @endif

            {{-- Topics sub-card selection --}}
            @if ($type === 'topics' && !$topicsMode && empty($messages))
                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('How would you like to brainstorm?') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('Choose how to discover topics.') }}</flux:subheading>

                    <div class="grid w-full max-w-xl gap-3 sm:grid-cols-2">
                        <button wire:click="selectTopicsMode('discover')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="magnifying-glass" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Auto-discover topics') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Research trends and discover topics for your brand automatically') }}</flux:text>
                        </button>

                        <button wire:click="selectTopicsMode('conversation')" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                            <flux:icon name="chat-bubble-left" class="mb-2 size-6 text-zinc-400 group-hover:text-indigo-400" />
                            <flux:heading size="sm">{{ __('Start a conversation') }}</flux:heading>
                            <flux:text class="mt-1 text-xs">{{ __('Guide the brainstorming with your own direction') }}</flux:text>
                        </button>
                    </div>
                </div>
            @endif

            {{-- Writer: Topic picker (required before mode) --}}
            @if ($type === 'writer' && !$topicId && empty($messages))
                @php
                    $availableTopics = \App\Models\Topic::where('team_id', $teamModel->id)
                        ->where('status', 'available')
                        ->latest()
                        ->get();
                @endphp

                <div class="flex flex-col items-center justify-center py-16">
                    <flux:heading size="xl" class="mb-2">{{ __('Pick a topic for this blog post') }}</flux:heading>
                    <flux:subheading class="mb-8">{{ __('The writer grounds the post in one of your topics.') }}</flux:subheading>

                    @if ($availableTopics->isEmpty())
                        <div class="w-full max-w-xl text-center">
                            <flux:icon name="light-bulb" class="mx-auto size-10 text-zinc-400" />
                            <flux:heading size="sm" class="mt-3">{{ __('No available topics') }}</flux:heading>
                            <flux:subheading class="mt-1">{{ __('Brainstorm topics first, then come back to write one.') }}</flux:subheading>
                            <div class="mt-4">
                                <flux:button variant="primary" icon="light-bulb" :href="route('topics', ['current_team' => $teamModel])" wire:navigate>
                                    {{ __('Go to Topics') }}
                                </flux:button>
                            </div>
                        </div>
                    @else
                        <div class="grid w-full max-w-2xl gap-3 sm:grid-cols-2">
                            @foreach ($availableTopics as $t)
                                <button wire:click="selectWriterTopic({{ $t->id }})" class="group cursor-pointer rounded-xl border border-zinc-200 p-4 text-left transition hover:border-indigo-400 hover:bg-indigo-500/5 dark:border-zinc-700 dark:hover:border-indigo-500">
                                    <flux:heading size="sm">{{ $t->title }}</flux:heading>
                                    <flux:text class="mt-1 text-xs">{{ $t->angle }}</flux:text>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif

        </div>
    </div>

    {{-- Input (only shown after type is selected) --}}
    @if ($type
        && !($type === 'topics' && !$topicsMode && empty($messages))
        && !($type === 'writer' && !$topicId && empty($messages)))
        @if ($type === 'writer' && $topicId && $conversation?->topic)
            <div class="mx-auto w-full max-w-5xl px-6 pb-1">
                <p class="text-xs text-zinc-400 dark:text-zinc-500">
                    <flux:icon name="document-text" class="inline size-3.5 -mt-0.5 mr-0.5" />
                    {{ __('Writing about: :title', ['title' => $conversation?->topic?->title ?? '']) }}
                </p>
            </div>
        @endif

        <div class="mx-auto w-full max-w-5xl px-6 pb-4 pt-2">
            @if ($isStreaming)
                <div class="flex justify-center">
                    <flux:button variant="danger" size="sm" icon="stop-circle" x-on:click="window.location.reload()">
                        {{ __('Stop generating') }}
                    </flux:button>
                </div>
            @else
                <form wire:submit="submitPrompt">
                    <flux:composer
                        wire:model="prompt"
                        submit="enter"
                        label="Message"
                        label:sr-only
                        placeholder="{{ __('Type your message...') }}"
                        rows="1"
                        max-rows="6"
                    >
                        <x-slot name="actionsTrailing">
                            <flux:button type="submit" size="sm" variant="primary" icon="paper-airplane" />
                        </x-slot>
                    </flux:composer>
                </form>
            @endif
        </div>
    @endif
</div>
