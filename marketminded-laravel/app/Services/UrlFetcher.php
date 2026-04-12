<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class UrlFetcher
{
    private const MAX_CONTENT_LENGTH = 12288; // 12KB

    public function fetch(string $url): string
    {
        try {
            $response = Http::timeout(10)
                ->withUserAgent('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36')
                ->get($url);

            if ($response->failed()) {
                return "Error fetching {$url}: HTTP {$response->status()}";
            }

            return $this->cleanHtml($response->body(), $url);
        } catch (ConnectionException $e) {
            return "Error fetching {$url}: {$e->getMessage()}";
        } catch (\Throwable $e) {
            return "Error fetching {$url}: {$e->getMessage()}";
        }
    }

    public function fetchMany(array $urls): array
    {
        $results = [];

        foreach ($urls as $url) {
            if (empty($url)) {
                continue;
            }

            $results[$url] = $this->fetch($url);
        }

        return $results;
    }

    private function cleanHtml(string $html, string $url): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR);

        $tagsToRemove = ['head', 'script', 'style', 'nav', 'footer', 'img', 'video', 'svg', 'noscript', 'iframe'];

        foreach ($tagsToRemove as $tag) {
            $elements = $dom->getElementsByTagName($tag);
            $toRemove = [];
            for ($i = 0; $i < $elements->length; $i++) {
                $toRemove[] = $elements->item($i);
            }
            foreach ($toRemove as $element) {
                $element->parentNode->removeChild($element);
            }
        }

        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/si', $html, $matches)) {
            $title = trim(html_entity_decode($matches[1]));
        }

        $text = trim($dom->textContent ?? '');
        $text = preg_replace('/\s+/', ' ', $text);
        $text = preg_replace('/\n{3,}/', "\n\n", $text);

        $content = "Title: {$title}\n\n{$text}";

        if (strlen($content) > self::MAX_CONTENT_LENGTH) {
            $content = substr($content, 0, self::MAX_CONTENT_LENGTH);
        }

        return $content;
    }
}
