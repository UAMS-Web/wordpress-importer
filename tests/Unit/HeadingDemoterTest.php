<?php

declare(strict_types=1);

/**
 * Tests that `HeadingDemoter` shifts Bard heading levels down one step and turns
 * an h6 into a bold paragraph, leaving every other node untouched (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/HeadingDemoterTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HeadingDemoter;

/**
 * A Bard heading node at a given level.
 *
 * @param  mixed  $level  The heading level, as stored
 * @param  list<mixed>  $content  The heading's inline content
 * @return array<string, mixed> The heading node
 */
function demoterHeading(mixed $level, array $content = []): array
{
    return [
        'type' => 'heading',
        'attrs' => ['level' => $level, 'textAlign' => 'left'],
        'content' => $content,
    ];
}

it('demotes each heading level below six by one', function (int|string $level, int $expected): void {
    $result = (new HeadingDemoter)->demote([demoterHeading($level)]);

    expect($result)->toBe([
        [
            'type' => 'heading',
            'attrs' => ['level' => $expected, 'textAlign' => 'left'],
            'content' => [],
        ],
    ]);
})->with([
    'h1 becomes h2' => [1, 2],
    'h2 becomes h3' => [2, 3],
    'h5 becomes h6' => [5, 6],
    'a numeric string level' => ['3', 4],
]);

it('truncates a fractional level before demoting it', function (): void {
    $result = (new HeadingDemoter)->demote([demoterHeading('2.5')]);

    expect($result[0])->toBe([
        'type' => 'heading',
        'attrs' => ['level' => 3, 'textAlign' => 'left'],
        'content' => [],
    ]);
});

it('treats a non-numeric level as zero and demotes it to one', function (): void {
    $result = (new HeadingDemoter)->demote([demoterHeading('big')]);

    expect($result[0])->toBe([
        'type' => 'heading',
        'attrs' => ['level' => 1, 'textAlign' => 'left'],
        'content' => [],
    ]);
});

it('turns an h6 into a paragraph with bold added to each text node', function (int $level): void {
    $result = (new HeadingDemoter)->demote([
        demoterHeading($level, [
            ['type' => 'text', 'text' => 'Plain'],
            ['type' => 'text', 'text' => 'Italic', 'marks' => [['type' => 'italic']]],
        ]),
    ]);

    expect($result)->toBe([
        [
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Plain', 'marks' => [['type' => 'bold']]],
                ['type' => 'text', 'text' => 'Italic', 'marks' => [['type' => 'italic'], ['type' => 'bold']]],
            ],
        ],
    ]);
})->with([
    'h6' => [6],
    'a level above six' => [7],
]);

it('leaves non-text inline nodes in an h6 as they were', function (): void {
    $hardBreak = ['type' => 'hardBreak'];

    $result = (new HeadingDemoter)->demote([
        demoterHeading(6, [$hardBreak, 'not a node']),
    ]);

    expect($result)->toBe([
        ['type' => 'paragraph', 'content' => [$hardBreak, 'not a node']],
    ]);
});

it('replaces non-array marks on an h6 text node with bold alone', function (): void {
    $result = (new HeadingDemoter)->demote([
        demoterHeading(6, [['type' => 'text', 'text' => 'X', 'marks' => 'bad']]),
    ]);

    $first = $result[0] ?? null;

    expect(\is_array($first) ? ($first['content'] ?? null) : null)->toBe([
        ['type' => 'text', 'text' => 'X', 'marks' => [['type' => 'bold']]],
    ]);
});

it('gives an h6 with no usable content an empty paragraph', function (mixed $content): void {
    $node = ['type' => 'heading', 'attrs' => ['level' => 6]];

    if ($content !== 'absent') {
        $node['content'] = $content;
    }

    expect((new HeadingDemoter)->demote([$node]))->toBe([
        ['type' => 'paragraph', 'content' => []],
    ]);
})->with([
    'no content key' => ['absent'],
    'non-array content' => ['text'],
]);

it('leaves nodes that are not usable headings untouched', function (mixed $node): void {
    expect((new HeadingDemoter)->demote([$node]))->toBe([$node]);
})->with([
    'a paragraph' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'p']]]],
    'a heading with no attrs' => [['type' => 'heading', 'content' => []]],
    'a heading with non-array attrs' => [['type' => 'heading', 'attrs' => 'h2']],
    'a heading with no level' => [['type' => 'heading', 'attrs' => ['textAlign' => 'left']]],
    'a node with no type' => [['attrs' => ['level' => 2]]],
    'a non-array node' => ['loose string'],
]);

it('keeps going past a node that is not an array', function (): void {
    $result = (new HeadingDemoter)->demote(['loose string', demoterHeading(2)]);

    expect($result)->toBe([
        'loose string',
        ['type' => 'heading', 'attrs' => ['level' => 3, 'textAlign' => 'left'], 'content' => []],
    ]);
});

it('demotes every heading in a mixed document and keeps keys and order', function (): void {
    $paragraph = ['type' => 'paragraph', 'content' => []];

    $result = (new HeadingDemoter)->demote([
        'a' => demoterHeading(2),
        'b' => $paragraph,
        'c' => demoterHeading(4),
    ]);

    expect($result)->toBe([
        'a' => ['type' => 'heading', 'attrs' => ['level' => 3, 'textAlign' => 'left'], 'content' => []],
        'b' => $paragraph,
        'c' => ['type' => 'heading', 'attrs' => ['level' => 5, 'textAlign' => 'left'], 'content' => []],
    ]);
});
