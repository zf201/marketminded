<?php

namespace App\Jobs;

use App\Events\ConversationEvent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Team;
use App\Services\BrandIntelligenceToolHandler;
use App\Services\BraveSearchClient;
use App\Services\ChatPromptBuilder;
use App\Services\ConversationBus;
use App\Services\CreateOutlineToolHandler;
use App\Services\FetchStyleReferenceToolHandler;
use App\Services\OpenRouterClient;
use App\Services\PickAudienceToolHandler;
use App\Services\ProofreadBlogPostToolHandler;
use App\Services\ResearchTopicToolHandler;
use App\Services\SocialPostToolHandler;
use App\Services\StreamResult;
use App\Services\TopicToolHandler;
use App\Services\TurnStoppedException;
use App\Services\UrlFetcher;
use App\Services\WriteBlogPostToolHandler;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunConversationTurn implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 1;

    public function __construct(
        public int $teamId,
        public int $conversationId,
    ) {}

    public function handle(): void
    {
        $team = Team::query()->find($this->teamId);
        $conversation = Conversation::query()
            ->with(['topic', 'contentPiece', 'messages'])
            ->find($this->conversationId);

        if (! $team || ! $conversation) {
            return;
        }

        $type = $conversation->type;

        if ($type === 'writer' && $conversation->topic && empty(($conversation->brief ?? [])['topic'])) {
            $topic = $conversation->topic;
            $brief = $conversation->brief ?? [];
            $brief['topic'] = [
                'id' => $topic->id,
                'title' => $topic->title,
                'angle' => $topic->angle,
                'sources' => $topic->sources ?? [],
            ];
            $conversation->update(['brief' => $brief]);
            $conversation->refresh();
        }

        $systemPrompt = ChatPromptBuilder::build($type, $team, $conversation);
        $tools = ChatPromptBuilder::tools($type);

        $apiMessages = $conversation->messages
            ->map(function (Message $message) {
                $content = $message->content ?? '';
                if ($message->role === 'assistant' && ! empty($message->metadata['interrupted'])) {
                    $content = trim($content) === ''
                        ? '[The previous response was interrupted by the user before it completed.]'
                        : $content."\n\n[The previous response was interrupted by the user before it completed.]";
                }

                return ['role' => $message->role, 'content' => $content];
            })
            ->values()
            ->toArray();

        $webSearchProvider = $team->web_search_provider ?? 'openrouter_builtin';
        $braveClient = ($webSearchProvider === 'brave' && $team->brave_api_key)
            ? new BraveSearchClient($team->brave_api_key)
            : null;

        $client = new OpenRouterClient(
            apiKey: $team->ai_api_key,
            model: $team->fast_model,
            urlFetcher: new UrlFetcher,
            maxIterations: 8,
            baseUrl: $team->ai_api_url ?? 'https://openrouter.ai/api/v1',
            provider: $team->ai_provider ?? 'openrouter',
            braveSearchClient: $braveClient,
        );

        $brandHandler = new BrandIntelligenceToolHandler;
        $topicHandler = new TopicToolHandler;
        $researchHandler = new ResearchTopicToolHandler;
        $audienceHandler = new PickAudienceToolHandler;
        $outlineHandler = new CreateOutlineToolHandler;
        $styleRefHandler = new FetchStyleReferenceToolHandler;
        $writeHandler = new WriteBlogPostToolHandler;
        $proofreadHandler = new ProofreadBlogPostToolHandler;
        $socialHandler = new SocialPostToolHandler;
        $priorTurnTools = [];
        $bus = new ConversationBus($conversation->id);

        $toolExecutor = function (string $name, array $args) use (
            $brandHandler,
            $topicHandler,
            $researchHandler,
            $audienceHandler,
            $outlineHandler,
            $styleRefHandler,
            $writeHandler,
            $proofreadHandler,
            $socialHandler,
            $team,
            $conversation,
            $bus,
            &$priorTurnTools,
        ): string {
            if ($name === 'update_brand_intelligence') {
                $bus->publish('subagent_started', ['agent' => 'brand', 'title' => __('Updating brand profile'), 'color' => 'sky']);
                $result = $brandHandler->execute($team, $args);
                $decoded = json_decode($result, true) ?? [];
                if (($decoded['status'] ?? '') === 'saved') {
                    $bus->publish('subagent_completed', ['agent' => 'brand', 'card' => [
                        'kind' => 'brand_update',
                        'summary' => __('Brand profile updated'),
                        'sections' => $decoded['sections'] ?? [],
                    ]]);
                } else {
                    $bus->publish('subagent_error', ['agent' => 'brand', 'message' => $decoded['message'] ?? __('Failed to update brand profile.')]);
                }

                return $result;
            }

            if ($name === 'save_topics') {
                $bus->publish('subagent_started', ['agent' => 'topics', 'title' => __('Saving topics'), 'color' => 'teal']);
                $result = $topicHandler->execute($team, $conversation->id, $args);
                $decoded = json_decode($result, true) ?? [];
                $titles = $decoded['titles'] ?? [];
                $bus->publish('subagent_completed', ['agent' => 'topics', 'card' => [
                    'kind' => 'topics',
                    'summary' => count($titles).' '.__('topic(s) saved to backlog'),
                    'titles' => $titles,
                    'topics_url' => route('topics', ['current_team' => $team]),
                ]]);

                return $result;
            }

            if ($name === 'fetch_url') {
                $url = $args['url'] ?? '';
                $pillId = bin2hex(random_bytes(8));
                $bus->publish('subagent_tool_call', ['agent' => 'main', 'name' => 'fetch', 'id' => $pillId, 'status' => 'running', 'detail' => $url]);
                $result = (new UrlFetcher)->fetch($url);
                $failed = str_starts_with($result, 'Error fetching');
                $bus->publish('subagent_tool_call_status', ['agent' => 'main', 'id' => $pillId, 'status' => $failed ? 'error' : 'ok']);

                return $result;
            }

            if ($name === 'research_topic') {
                $result = $researchHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];

                return $result;
            }

            if ($name === 'pick_audience') {
                $result = $audienceHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];

                return $result;
            }

            if ($name === 'create_outline') {
                $result = $outlineHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];

                return $result;
            }

            if ($name === 'fetch_style_reference') {
                $result = $styleRefHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];

                return $result;
            }

            if ($name === 'write_blog_post') {
                $result = $writeHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];

                return $result;
            }

            if ($name === 'proofread_blog_post') {
                $result = $proofreadHandler->execute($team, $conversation->id, $args, $priorTurnTools, $bus);
                $priorTurnTools[] = ['name' => $name, 'args' => $args, 'status' => json_decode($result, true)['status'] ?? 'error'];

                return $result;
            }

            if ($name === 'propose_posts' || $name === 'replace_all_posts') {
                $piece = $conversation->contentPiece;
                if (! $piece) {
                    return json_encode(['status' => 'error', 'message' => 'No content piece is associated with this conversation.']);
                }

                $title = $name === 'propose_posts' ? __('Proposing posts') : __('Replacing posts');
                $bus->publish('subagent_started', ['agent' => 'social', 'title' => $title, 'color' => 'pink']);
                $result = $name === 'propose_posts'
                    ? $socialHandler->propose($team, $conversation->id, $piece, $args)
                    : $socialHandler->replaceAll($team, $conversation->id, $piece, $args);
                $decoded = json_decode($result, true) ?? [];
                if (($decoded['status'] ?? '') === 'saved') {
                    $ids = $decoded['ids'] ?? [];
                    $posts = collect($args['posts'] ?? [])->values()->map(fn ($p, $i) => [
                        'id' => $ids[$i] ?? null,
                        'platform' => $p['platform'] ?? '',
                        'hook' => $p['hook'] ?? '',
                        'preview' => mb_substr(strip_tags($p['body'] ?? ''), 0, 160),
                    ])->all();
                    $verb = $name === 'propose_posts' ? __('created') : __('replaced');
                    $bus->publish('subagent_completed', ['agent' => 'social', 'card' => [
                        'kind' => 'social_posts',
                        'summary' => count($posts).' '.__('posts').' '.$verb,
                        'social_url' => route('social.show', ['current_team' => $team, 'contentPiece' => $piece->id]),
                        'posts' => $posts,
                    ]]);
                } else {
                    $bus->publish('subagent_error', ['agent' => 'social', 'message' => $decoded['message'] ?? 'Failed to save posts.']);
                }

                return $result;
            }

            if ($name === 'update_post') {
                $pillId = bin2hex(random_bytes(8));
                $bus->publish('subagent_tool_call', ['agent' => 'main', 'name' => 'update post', 'id' => $pillId, 'status' => 'running']);
                $result = $socialHandler->update($team, $conversation->id, $args);
                $status = (json_decode($result, true)['status'] ?? '') === 'saved' ? 'ok' : 'error';
                $bus->publish('subagent_tool_call_status', ['agent' => 'main', 'id' => $pillId, 'status' => $status]);

                return $result;
            }

            if ($name === 'delete_post') {
                $pillId = bin2hex(random_bytes(8));
                $bus->publish('subagent_tool_call', ['agent' => 'main', 'name' => 'delete post', 'id' => $pillId, 'status' => 'running']);
                $result = $socialHandler->delete($team, $args);
                $status = (json_decode($result, true)['status'] ?? '') === 'deleted' ? 'ok' : 'error';
                $bus->publish('subagent_tool_call_status', ['agent' => 'main', 'id' => $pillId, 'status' => $status]);

                return $result;
            }

            return "Unknown tool: {$name}";
        };

        $useServerTools = $team->ai_provider !== 'custom' && $webSearchProvider === 'openrouter_builtin';
        $chatTools = $tools;
        if ($braveClient !== null) {
            $chatTools[] = BraveSearchClient::toolSchema();
        }

        try {
            $streamResult = $client->streamChatWithTools(
                systemPrompt: $systemPrompt,
                messages: $apiMessages,
                tools: $chatTools,
                toolExecutor: $toolExecutor,
                temperature: 0.7,
                useServerTools: $useServerTools,
                bus: $bus,
            );

            $this->writeChatDebugLog($team, $conversation, $systemPrompt, $chatTools, $apiMessages, $bus->text(), $bus->events(), $streamResult, false);
            $this->persistTurn($team, $conversation, $bus, $streamResult, interrupted: false);
            $bus->publish('turn_complete');
        } catch (TurnStoppedException) {
            $this->writeChatDebugLog($team, $conversation, $systemPrompt, $chatTools, $apiMessages, $bus->text(), $bus->events(), null, true);
            $this->persistTurn($team, $conversation, $bus, streamResult: null, interrupted: true);
            try {
                $bus->publish('turn_interrupted');
            } catch (TurnStoppedException) {
                broadcast(new ConversationEvent($conversation->id, 'turn_interrupted', []));
            }
        } catch (\Throwable $e) {
            $this->writeChatDebugLog($team, $conversation, $systemPrompt, $chatTools, $apiMessages, $bus->text(), $bus->events(), null, true);
            $this->persistTurn($team, $conversation, $bus, streamResult: null, interrupted: true);
            \Log::error('RunConversationTurn failed', ['error' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
            try {
                $bus->publish('turn_error', ['message' => $e->getMessage()]);
            } catch (TurnStoppedException) {
                broadcast(new ConversationEvent($conversation->id, 'turn_error', ['message' => $e->getMessage()]));
            }
        }
    }

    private function persistTurn(
        Team $team,
        Conversation $conversation,
        ConversationBus $bus,
        ?StreamResult $streamResult,
        bool $interrupted,
    ): void {
        $metadata = [];

        $events = $bus->events();
        if (! empty($events)) {
            $metadata['events'] = $events;
        }

        if ($streamResult && $streamResult->webSearchRequests > 0) {
            $metadata['web_searches'] = $streamResult->webSearchRequests;
        }

        $reasoning = $streamResult?->reasoningContent ?: '';
        if ($reasoning !== '') {
            $metadata['reasoning'] = $reasoning;
        }

        if ($streamResult && $streamResult->reasoningTokens > 0) {
            $metadata['reasoning_tokens'] = $streamResult->reasoningTokens;
        }

        if ($interrupted) {
            $metadata['interrupted'] = true;
        }

        $content = $this->cleanContent($bus->text());

        if ($content === '' && empty($metadata)) {
            return;
        }

        Message::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => $content,
            'model' => $team->fast_model,
            'input_tokens' => $streamResult?->inputTokens ?? 0,
            'output_tokens' => $streamResult?->outputTokens ?? 0,
            'cost' => $streamResult?->cost ?? 0,
            'metadata' => ! empty($metadata) ? $metadata : null,
        ]);
    }

    private function writeChatDebugLog(
        Team $team,
        Conversation $conversation,
        string $systemPrompt,
        array $tools,
        array $apiMessages,
        string $responseContent,
        array $busEvents,
        ?StreamResult $streamResult,
        bool $interrupted,
    ): void {
        if (! env('CHAT_DEBUG_LOG', false)) {
            return;
        }

        $entry = [
            'ts' => now()->toIso8601String(),
            'conversation_id' => $conversation->id,
            'type' => $conversation->type,
            'topic_id' => $conversation->topic_id,
            'team_id' => $team->id,
            'model' => $team->fast_model,
            'system_prompt' => $systemPrompt,
            'tool_schemas' => array_map(fn ($t) => $t['function']['name'] ?? ($t['type'] ?? 'unknown'), $tools),
            'history_sent' => $apiMessages,
            'response_content' => $responseContent,
            'bus_events' => array_map(fn ($e) => ['type' => $e['type'], 'agent' => $e['payload']['agent'] ?? null], $busEvents),
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
                json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n",
                FILE_APPEND | LOCK_EX,
            );
        } catch (\Throwable $e) {
            \Log::warning('chat-debug log write failed', ['error' => $e->getMessage()]);
        }
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
        $content = preg_replace("/\n{3,}/", "\n\n", $content);

        return trim($content);
    }
}
