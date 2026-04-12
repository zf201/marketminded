<?php

use App\Services\UrlFetcher;
use Illuminate\Support\Facades\Http;

test('fetches and cleans html content', function () {
    Http::fake([
        'https://example.com' => Http::response('<html><head><title>Example</title><script>alert("hi")</script></head><body><nav>Menu</nav><main><h1>Hello</h1><p>World</p></main><footer>Footer</footer></body></html>'),
    ]);

    $fetcher = new UrlFetcher;
    $result = $fetcher->fetch('https://example.com');

    expect($result)->toContain('Title: Example');
    expect($result)->toContain('Hello');
    expect($result)->toContain('World');
    expect($result)->not->toContain('alert');
    expect($result)->not->toContain('Menu');
    expect($result)->not->toContain('Footer');
});

test('truncates content to 12kb', function () {
    $longContent = str_repeat('<p>Lorem ipsum dolor sit amet. </p>', 1000);
    Http::fake([
        'https://example.com' => Http::response("<html><head><title>Long</title></head><body>{$longContent}</body></html>"),
    ]);

    $fetcher = new UrlFetcher;
    $result = $fetcher->fetch('https://example.com');

    expect(strlen($result))->toBeLessThanOrEqual(12288 + 100);
});

test('returns error message on http failure', function () {
    Http::fake([
        'https://example.com' => Http::response('Not Found', 404),
    ]);

    $fetcher = new UrlFetcher;
    $result = $fetcher->fetch('https://example.com');

    expect($result)->toContain('Error fetching');
});

test('returns error message on connection failure', function () {
    Http::fake([
        'https://example.com' => fn () => throw new \Illuminate\Http\Client\ConnectionException('timeout'),
    ]);

    $fetcher = new UrlFetcher;
    $result = $fetcher->fetch('https://example.com');

    expect($result)->toContain('Error fetching');
});

test('fetches many urls', function () {
    Http::fake([
        'https://a.com' => Http::response('<html><head><title>A</title></head><body><p>Content A</p></body></html>'),
        'https://b.com' => Http::response('<html><head><title>B</title></head><body><p>Content B</p></body></html>'),
    ]);

    $fetcher = new UrlFetcher;
    $results = $fetcher->fetchMany(['https://a.com', 'https://b.com']);

    expect($results)->toHaveCount(2);
    expect($results['https://a.com'])->toContain('Content A');
    expect($results['https://b.com'])->toContain('Content B');
});

test('skips empty urls in fetchMany', function () {
    Http::fake([
        'https://a.com' => Http::response('<html><head><title>A</title></head><body><p>A</p></body></html>'),
    ]);

    $fetcher = new UrlFetcher;
    $results = $fetcher->fetchMany(['https://a.com', '', null]);

    expect($results)->toHaveCount(1);
});
