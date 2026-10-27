<?php

declare(strict_types=1);

/**
 * Tests that `LinkProcessor` swaps a resolved WordPress URL for its Statamic
 * reference in an entry's fields: in a Bard document's link marks and strings,
 * in a replicator's sets and the Bard fields inside them, and in a plain string;
 * that it says which fields it touched; and that it builds an entry reference
 * from an entry or its ID.
 *
 * @command  php vendor/bin/pest tests/Integration/LinkProcessorReplaceUrlTest.php
 */

use Statamic\Entries\Entry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Run the callback with warnings turned into exceptions, as the host app does.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function replaceUrlStrict(callable $callback): mixed
{
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        return $callback();
    } finally {
        restore_error_handler();
    }
}

/**
 * A Bard text node linking to the URL.
 *
 * @param  array<mixed>  $attrs  Extra link attributes
 * @return array<string, mixed>
 */
function replaceUrlText(string $href, array $attrs = []): array
{
    return ['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'bold'], ['type' => 'link', 'attrs' => array_merge(['href' => $href], $attrs)]]];
}

/**
 * Replace the URL in Bard content.
 *
 * @param  array<int|string, mixed>  $content
 * @return array<int|string, mixed>
 */
function replaceUrlInBard(array $content, ?string $fragment = null, ?string $query = null): array
{
    return replaceUrlStrict(static fn (): array => LinkProcessor::replaceUrlInBardContent($content, 'https://wp.test/a/', 'statamic://entry::a', $fragment, $query));
}

/**
 * Replace the URL in replicator content.
 *
 * @param  array<int|string, mixed>  $content
 * @return array<int|string, mixed>
 */
function replaceUrlInReplicator(array $content, ?string $fragment = null, ?string $query = null): array
{
    return replaceUrlStrict(static fn (): array => LinkProcessor::replaceUrlInReplicatorContent($content, 'https://wp.test/a/', 'statamic://entry::a', $fragment, $query));
}

it('points a matching link mark at the reference, adding the fragment and query', function (?string $fragment, ?string $query, array $added): void {
    $content = [['type' => 'paragraph', 'content' => [replaceUrlText('https://wp.test/a/', ['target' => '_blank'])]]];

    expect(replaceUrlInBard($content, $fragment, $query))
        ->toBe([['type' => 'paragraph', 'content' => [replaceUrlText('statamic://entry::a', ['target' => '_blank', ...$added])]]]);
})->with([
    'neither' => [null, null, []],
    'empty ones' => ['', '', []],
    'a fragment' => ['#top', null, ['fragment' => '#top']],
    'a query' => [null, '?x=1', ['query' => '?x=1']],
    'both' => ['#top', '?x=1', ['fragment' => '#top', 'query' => '?x=1']],
]);

it('leaves link marks to other URLs, and checks the marks after them', function (): void {
    $text = ['type' => 'text', 'text' => 'x', 'marks' => [
        ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/b/']],
        'not a mark',
        ['type' => 'italic'],
        ['attrs' => ['href' => 'https://wp.test/a/']],
        ['type' => 'link'],
        ['type' => 'link', 'attrs' => 'none'],
        ['type' => 'link', 'attrs' => ['title' => 't']],
        ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/a/']],
    ]];

    expect(replaceUrlInBard([$text]))->toBe([[...$text, 'marks' => [
        ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/b/']],
        'not a mark',
        ['type' => 'italic'],
        ['attrs' => ['href' => 'https://wp.test/a/']],
        ['type' => 'link'],
        ['type' => 'link', 'attrs' => 'none'],
        ['type' => 'link', 'attrs' => ['title' => 't']],
        ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a']],
    ]]]);
});

it('points a link node itself at the reference', function (?string $fragment, array $added): void {
    $content = ['marks' => [['type' => 'link', 'attrs' => ['href' => 'https://wp.test/a/']], ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/b/']]]];

    expect(replaceUrlInBard($content, $fragment, '?q'))->toBe(['marks' => [
        ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a', ...$added, 'query' => '?q']],
        ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/b/']],
    ]]);
})->with([
    'a fragment' => ['#f', ['fragment' => '#f']],
    'none' => [null, []],
    'an empty one' => ['', []],
]);

it('adds no query to a link node for an empty one', function (): void {
    expect(replaceUrlInBard([['type' => 'link', 'attrs' => ['href' => 'https://wp.test/a/']]], null, ''))
        ->toBe([['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a']]]);
});

it('searches a node that is not a link, even one with an href attribute', function (): void {
    // Searched as plain values, the href is replaced as a string, without a fragment
    expect(replaceUrlInBard([['type' => 'image', 'attrs' => ['href' => 'https://wp.test/a/']]], '#f'))
        ->toBe([['type' => 'image', 'attrs' => ['href' => 'statamic://entry::a']]]);
});

it('replaces the URL in every string, and in nodes nested at any depth', function (): void {
    $content = [
        'type' => 'doc',
        'text' => 'see https://wp.test/a/ and https://wp.test/a/',
        'count' => 3,
        'content' => [['type' => 'set', 'attrs' => ['values' => ['url' => 'https://wp.test/a/', 'body' => [replaceUrlText('https://wp.test/a/')]]]]],
    ];

    expect(replaceUrlInBard($content))->toBe([
        'type' => 'doc',
        'text' => 'see statamic://entry::a and statamic://entry::a',
        'count' => 3,
        'content' => [['type' => 'set', 'attrs' => ['values' => ['url' => 'statamic://entry::a', 'body' => [replaceUrlText('statamic://entry::a')]]]]],
    ]);
});

it('treats a node without a link or text type as one to search', function (array $node): void {
    expect(replaceUrlInBard([$node]))->toBe([[...$node, 'content' => [replaceUrlText('statamic://entry::a')]]]);
})->with([
    'no type' => [['content' => [replaceUrlText('https://wp.test/a/')]]],
    'a link without attributes' => [['type' => 'link', 'content' => [replaceUrlText('https://wp.test/a/')]]],
    'a text node without marks' => [['type' => 'text', 'content' => [replaceUrlText('https://wp.test/a/')]]],
    'a text node whose marks are not a list' => [['type' => 'text', 'marks' => 'none', 'content' => [replaceUrlText('https://wp.test/a/')]]],
]);

it('replaces the URL in a replicator\'s strings, sets and the Bard fields inside them', function (): void {
    $pageBuilder = [
        ['id' => 's1', 'type' => 'uamsweb_block_content', 'button_url' => 'https://wp.test/a/', 'enabled' => true, 'body' => [['type' => 'paragraph', 'content' => [replaceUrlText('https://wp.test/a/')]]]],
        ['id' => 's2', 'type' => 'list', 'items' => [['link' => 'https://wp.test/a/?x', 'node' => ['type' => 'paragraph', 'content' => [replaceUrlText('https://wp.test/a/')]]]]],
    ];

    expect(replaceUrlInReplicator($pageBuilder, '#f'))->toBe([
        ['id' => 's1', 'type' => 'uamsweb_block_content', 'button_url' => 'statamic://entry::a', 'enabled' => true, 'body' => [['type' => 'paragraph', 'content' => [replaceUrlText('statamic://entry::a', ['fragment' => '#f'])]]]],
        ['id' => 's2', 'type' => 'list', 'items' => [['link' => 'statamic://entry::a?x', 'node' => ['type' => 'paragraph', 'content' => [replaceUrlText('statamic://entry::a', ['fragment' => '#f'])]]]]],
    ]);
});

it('reads a single Bard node in a replicator as Bard', function (array $node, array $replaced): void {
    expect(replaceUrlInReplicator($node, '#f'))->toBe($replaced);
})->with([
    'a node with content' => [
        ['type' => 'paragraph', 'content' => [replaceUrlText('https://wp.test/a/')]],
        ['type' => 'paragraph', 'content' => [replaceUrlText('statamic://entry::a', ['fragment' => '#f'])]],
    ],
    'a node with marks' => [
        replaceUrlText('https://wp.test/a/'),
        replaceUrlText('statamic://entry::a', ['fragment' => '#f']),
    ],
    'a node with content and a link among its attributes' => [
        ['type' => 'paragraph', 'attrs' => ['link' => ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/a/']]], 'content' => []],
        ['type' => 'paragraph', 'attrs' => ['link' => ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a', 'fragment' => '#f']]], 'content' => []],
    ],
]);

it('reads a list of nodes in a replicator as Bard, and anything else as a replicator', function (array $content, array $replaced): void {
    // A link mark reached as a replicator value is a string field to it, and gets
    // no fragment; reached as Bard, it gets one
    expect(replaceUrlInReplicator($content, '#f'))->toBe($replaced);
})->with([
    'a list of nodes' => [
        [['type' => 'link', 'attrs' => ['href' => 'https://wp.test/a/']]],
        [['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a', 'fragment' => '#f']]],
    ],
    'a list whose first value is not a node' => [
        ['x', ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/a/']]],
        ['x', ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a']]],
    ],
    'a list whose first node has no type' => [
        [['href' => 'https://wp.test/a/'], ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/a/']]],
        [['href' => 'statamic://entry::a'], ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a']]],
    ],
    'keys that are not a list' => [
        [1 => ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/a/']]],
        [1 => ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a']]],
    ],
    'a typed set that is not a node' => [
        ['type' => 'set', 'link' => ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/a/']]],
        ['type' => 'set', 'link' => ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a']]],
    ],
    'an untyped value with content' => [
        ['content' => 'x', 'link' => ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/a/']]],
        ['content' => 'x', 'link' => ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a']]],
    ],
    'a node at key 0 among other keys' => [
        [0 => ['type' => 'link', 'attrs' => ['href' => 'https://wp.test/a/']], 'x' => 'y'],
        [0 => ['type' => 'link', 'attrs' => ['href' => 'statamic://entry::a']], 'x' => 'y'],
    ],
    'an empty replicator' => [[], []],
]);

it('replaces the URL in each field it can, and says which', function (mixed $content, mixed $pageBuilder, mixed $newContent, mixed $newPageBuilder, bool $contentUpdated, bool $pageBuilderUpdated): void {
    expect(LinkProcessor::processResolvedLinkInFields($content, $pageBuilder, 'https://wp.test/a/', 'statamic://entry::a', '#f', '?q'))->toBe([
        'content' => $newContent,
        'page_builder' => $newPageBuilder,
        'content_updated' => $contentUpdated,
        'page_builder_updated' => $pageBuilderUpdated,
    ]);
})->with([
    'Bard content and a replicator' => [
        [replaceUrlText('https://wp.test/a/')],
        [['id' => 's', 'button_url' => 'https://wp.test/a/']],
        [replaceUrlText('statamic://entry::a', ['fragment' => '#f', 'query' => '?q'])],
        [['id' => 's', 'button_url' => 'statamic://entry::a']],
        true,
        true,
    ],
    'string content and no replicator' => ['go to https://wp.test/a/', null, 'go to statamic://entry::a', null, true, false],
    'neither' => [null, 'https://wp.test/a/', null, 'https://wp.test/a/', false, false],
]);

it('builds an entry reference with the query before the fragment', function (?string $fragment, ?string $query, string $reference): void {
    expect(LinkProcessor::generateEntryReference('abc', $fragment, $query))->toBe($reference);
})->with([
    'neither' => [null, null, 'statamic://entry::abc'],
    'a fragment' => ['#f', null, 'statamic://entry::abc#f'],
    'a query' => [null, '?q=1', 'statamic://entry::abc?q=1'],
    'both' => ['#f', '?q=1', 'statamic://entry::abc?q=1#f'],
]);

it('builds an entry reference from an entry\'s ID, whatever its type', function (mixed $id, string $reference): void {
    $entry = new Entry;
    $entry->id($id);

    expect(LinkProcessor::generateEntryReference($entry))->toBe($reference);
})->with([
    'a string' => ['abc', 'statamic://entry::abc'],
    'an integer' => [12, 'statamic://entry::12'],
    'a float' => [1.5, 'statamic://entry::1.5'],
    'neither' => [true, 'statamic://entry::'],
]);
