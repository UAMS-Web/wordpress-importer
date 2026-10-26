<?php

declare(strict_types=1);

/**
 * Tests that `RssBlockParser` maps a WordPress `core/rss` block's attributes
 * onto the RSS replicator set, with its defaults for every attribute the block
 * leaves out (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/RssBlockParserTest.php
 */

use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\RssBlockParser;

/**
 * Parse a block's attributes.
 *
 * @param  array<mixed>  $attributes  The block attributes
 * @return array<mixed> The replicator set
 */
function rssBlockParse(array $attributes): array
{
    $assetImporter = new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor();

    return new RssBlockParser()->parse($attributes, 'default', $assetImporter, new NullOutput);
}

it('fills every default when the block sets nothing', function (): void {
    $set = rssBlockParse([]);

    expect($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');

    unset($set['id']);

    expect($set)->toBe([
        'type' => 'uamsweb_block_rss',
        'enabled' => true,
        'item_limit' => 5,
        'author_query' => false,
        'date_query' => false,
        'excerpt_query' => false,
        'block_heading' => "Placeholder Block Heading \u{2014} Please Replace",
        'hide_block_heading' => false,
        'background_color' => 'bg-auto',
    ]);
});

it('gives each set its own row id', function (): void {
    expect(rssBlockParse([])['id'])->not->toBe(rssBlockParse([])['id']);
});

it('maps the feed URL only when it is a non-empty string', function (mixed $feedUrl, bool $mapped): void {
    $set = rssBlockParse(['feedURL' => $feedUrl]);

    if ($mapped) {
        expect($set['feed_url'] ?? null)->toBe($feedUrl);
    } else {
        expect($set)->not->toHaveKey('feed_url');
    }
})->with([
    'a URL' => ['https://example.org/feed', true],
    'an empty string' => ['', false],
    'not a string' => [5, false],
    'null' => [null, false],
]);

it('maps the item count, falling back to five', function (mixed $itemsToShow, int $expected): void {
    expect(rssBlockParse(['itemsToShow' => $itemsToShow])['item_limit'] ?? null)->toBe($expected);
})->with([
    'an integer' => [3, 3],
    'zero' => [0, 0],
    'a numeric string' => ['12', 12],
    'a string with trailing text' => ['12a', 5],
    'a string with leading text' => ['a12', 5],
    'a negative string' => ['-3', 5],
    'a float' => [3.0, 5],
    'null' => [null, 5],
]);

it('maps each display flag to a boolean, defaulting to false', function (string $attribute, string $field, mixed $value, bool $expected): void {
    expect(rssBlockParse([$attribute => $value])[$field] ?? null)->toBe($expected);
})->with(function (): Generator {
    foreach (['displayAuthor' => 'author_query', 'displayDate' => 'date_query', 'displayExcerpt' => 'excerpt_query'] as $attribute => $field) {
        yield "{$attribute} true" => [$attribute, $field, true, true];
        yield "{$attribute} false" => [$attribute, $field, false, false];
        yield "{$attribute} a truthy string" => [$attribute, $field, 'yes', true];
        yield "{$attribute} zero" => [$attribute, $field, 0, false];
        yield "{$attribute} null" => [$attribute, $field, null, false];
    }
});

it('converts the excerpt length from words to characters', function (mixed $excerptLength, int $expected): void {
    $set = rssBlockParse(['displayExcerpt' => true, 'excerptLength' => $excerptLength]);

    expect($set['excerpt_limit'] ?? null)->toBe($expected);
})->with([
    'an integer, rounded up from a half' => [5, 30],
    'an integer, rounded down' => [6, 35],
    'a numeric string, rounded down' => ['6', 35],
    'an integer' => [10, 59],
    'a float' => [2.5, 15],
    'a numeric string' => ['5', 30],
    'a numeric string with a fraction' => ['2.5', 15],
    'zero' => [0, 0],
]);

it('sets no excerpt length when it cannot read one', function (array $attributes): void {
    expect(rssBlockParse($attributes))->not->toHaveKey('excerpt_limit');
})->with([
    'a length that is not a number' => [['displayExcerpt' => true, 'excerptLength' => 'ten']],
    'a length that is neither number nor string' => [['displayExcerpt' => true, 'excerptLength' => [10]]],
    'no length' => [['displayExcerpt' => true]],
    'excerpts off' => [['displayExcerpt' => false, 'excerptLength' => 10]],
    'excerpts not set' => [['excerptLength' => 10]],
    'excerpts on as a truthy value that is not true' => [['displayExcerpt' => 1, 'excerptLength' => 10]],
]);
