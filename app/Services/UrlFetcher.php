<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class UrlFetcher
{
    private const MAX_CONTENT_LENGTH = 12000;

    public function fetch(string $url): string
    {
        try {
            $response = Http::timeout(10)
                ->withUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36')
                ->withHeaders([
                    'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                    'Accept-Language' => 'en-US,en;q=0.5',
                ])
                ->get($url);

            if ($response->failed()) {
                return "Error fetching {$url}: HTTP {$response->status()}";
            }

            return $this->extractContent($response->body(), $url);
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

    private function extractContent(string $html, string $url): string
    {
        $dom = new \DOMDocument;
        @$dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR);

        // Extract title before removing head
        $title = '';
        $titleNodes = $dom->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            $title = trim($titleNodes->item(0)->textContent);
        }

        // Remove non-content elements (matching Go version)
        $tagsToRemove = [
            'head', 'script', 'style', 'noscript', 'iframe',
            'nav', 'footer', 'header', 'svg', 'form', 'button',
            'img', 'picture', 'figure', 'video', 'audio', 'canvas',
        ];

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

        // Strip all attributes except href (matching Go version)
        $xpath = new \DOMXPath($dom);
        $allElements = $xpath->query('//*');
        foreach ($allElements as $element) {
            $attrsToRemove = [];
            foreach ($element->attributes as $attr) {
                if ($attr->name !== 'href') {
                    $attrsToRemove[] = $attr->name;
                }
            }
            foreach ($attrsToRemove as $attrName) {
                $element->removeAttribute($attrName);
            }
        }

        // Get cleaned HTML with links preserved
        $body = $dom->getElementsByTagName('body')->item(0);
        $content = $body ? $dom->saveHTML($body) : $dom->saveHTML();

        // Strip the outer body tags
        $content = preg_replace('/<\/?body[^>]*>/', '', $content);

        // Clean up excessive whitespace while preserving structure
        $lines = explode("\n", $content);
        $cleaned = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line !== '') {
                $cleaned[] = $line;
            }
        }
        $content = implode("\n", $cleaned);

        if (strlen($content) > self::MAX_CONTENT_LENGTH) {
            $content = substr($content, 0, self::MAX_CONTENT_LENGTH) . "\n[truncated]";
        }

        return "Title: {$title}\n\n{$content}";
    }
}
