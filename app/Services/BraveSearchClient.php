<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class BraveSearchClient
{
    public function __construct(private string $apiKey) {}

    /**
     * Call the Brave LLM Context API and return formatted context as a string.
     * Returns an error string on failure so the model can react gracefully.
     */
    public function search(string $query, ?string $country = null): string
    {
        $params = [
            'q'                       => $query,
            'count'                   => 10,
            'maximum_number_of_tokens' => 4096,
        ];

        $headers = [
            'Accept'               => 'application/json',
            'X-Subscription-Token' => $this->apiKey,
        ];

        if ($country !== null && $country !== '') {
            $headers['X-Loc-Country'] = strtoupper(trim($country));
        }

        $response = Http::timeout(30)
            ->withHeaders($headers)
            ->get('https://api.search.brave.com/res/v1/llm/context', $params);

        if ($response->failed()) {
            return 'Brave search error ' . $response->status() . ': ' . $response->body();
        }

        $data = $response->json();
        $results = $data['grounding']['generic'] ?? [];

        if (empty($results)) {
            return 'No results found for: ' . $query;
        }

        $output = "Search results for: {$query}\n\n";
        foreach ($results as $result) {
            $output .= '## ' . ($result['title'] ?? 'Untitled') . "\n";
            $output .= 'URL: ' . ($result['url'] ?? '') . "\n";
            foreach ($result['snippets'] ?? [] as $snippet) {
                $output .= $snippet . "\n";
            }
            $output .= "\n";
        }

        return trim($output);
    }

    public static function toolSchema(): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name'        => 'brave_web_search',
                'description' => 'Search the web using Brave Search and get LLM-optimised context. Use this to find current information, news, statistics, or facts. Optionally specify a country code to get locally relevant results.',
                'parameters'  => [
                    'type'       => 'object',
                    'required'   => ['query'],
                    'properties' => [
                        'query'   => ['type' => 'string', 'description' => 'The search query'],
                        'country' => ['type' => 'string', 'description' => 'Optional ISO 3166-1 alpha-2 country code (e.g. GB, US, DE) for locally relevant results. Use the brand\'s target country when relevant.'],
                    ],
                ],
            ],
        ];
    }
}
