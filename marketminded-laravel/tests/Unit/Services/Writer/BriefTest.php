<?php

use App\Services\Writer\Brief;

test('fromJson and toJson round-trip', function () {
    $data = ['topic' => ['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]];
    $brief = Brief::fromJson($data);

    expect($brief->toJson())->toBe($data);
});

test('empty brief reports nothing present', function () {
    $brief = Brief::fromJson([]);

    expect($brief->hasTopic())->toBeFalse();
    expect($brief->hasResearch())->toBeFalse();
    expect($brief->hasOutline())->toBeFalse();
    expect($brief->hasContentPiece())->toBeFalse();

    expect($brief->topic())->toBeNull();
    expect($brief->research())->toBeNull();
    expect($brief->outline())->toBeNull();
    expect($brief->contentPieceId())->toBeNull();
});

test('with* methods produce a new brief without mutating the original', function () {
    $original = Brief::fromJson([]);

    $withTopic = $original->withTopic(['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]);

    expect($original->hasTopic())->toBeFalse();
    expect($withTopic->hasTopic())->toBeTrue();
    expect($withTopic->topic())->toBe(['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []]);
});

test('withResearch, withOutline, withContentPieceId set their slots', function () {
    $brief = Brief::fromJson([])
        ->withResearch([
            'topic_summary' => 's',
            'claims' => [['id' => 'c1', 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']]],
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 'T']],
        ])
        ->withOutline([
            'angle' => 'a',
            'target_length_words' => 1500,
            'sections' => [
                ['heading' => 'Intro', 'purpose' => 'hook', 'claim_ids' => ['c1']],
                ['heading' => 'Body', 'purpose' => 'evidence', 'claim_ids' => ['c1']],
            ],
        ])
        ->withContentPieceId(42);

    expect($brief->hasResearch())->toBeTrue();
    expect($brief->hasOutline())->toBeTrue();
    expect($brief->hasContentPiece())->toBeTrue();
    expect($brief->contentPieceId())->toBe(42);
    expect($brief->research()['claims'])->toHaveCount(1);
    expect($brief->outline()['sections'])->toHaveCount(2);
});

test('statusSummary shows checkmarks and counts for filled slots', function () {
    $brief = Brief::fromJson([])
        ->withTopic(['id' => 1, 'title' => 'X', 'angle' => 'a', 'sources' => []])
        ->withResearch([
            'topic_summary' => 's',
            'claims' => array_map(fn ($i) => ['id' => "c{$i}", 'text' => 't', 'type' => 'fact', 'source_ids' => ['s1']], range(1, 13)),
            'sources' => [['id' => 's1', 'url' => 'u', 'title' => 'T']],
        ]);

    $summary = $brief->statusSummary();

    expect($summary)->toContain('topic: ✓');
    expect($summary)->toContain('research: ✓');
    expect($summary)->toContain('13 claims');
    expect($summary)->toContain('outline: ✗');
    expect($summary)->toContain('content_piece: ✗');
});
