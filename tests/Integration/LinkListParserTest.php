<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LinkListParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\FakeAssetImporter;

/**
 * Tests that `LinkListParser` maps an ACF Link List block, and a
 * modules-template `link_list` module, onto the link-list replicator set: one
 * item per counted section with a link, an `asset_file` item for a same-site
 * file that was imported, and the whole set skipped when no item survives.
 *
 * Both entry points share one mapping, so each case runs through both.
 *
 * @command  php vendor/bin/pest tests/Integration/LinkListParserTest.php
 */
uses(StatamicTestCase::class);

// The warnings read the page link, post id and entry data from process-statics
beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearWordPressPostId();
    ProcessingContext::clearEntryData();
    config(['app.url' => 'https://statamic.test']);
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearWordPressPostId();
    ProcessingContext::clearEntryData();
});

/**
 * Parse data through one of the two entry points.
 *
 * @param  string  $entry  `block` for `parse()`, `module` for `parseModule()`
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?FakeAssetImporter  $assetImporter  The importer, or one that imports nothing
 * @return array<int|string, mixed>|null The replicator set, `[]` or null when skipped
 */
function linkListParse(string $entry, array $data, ?FakeAssetImporter $assetImporter = null): ?array
{
    $parser = new LinkListParser;
    $assetImporter ??= new FakeAssetImporter;

    return $entry === 'block'
        ? $parser->parse(['data' => $data], 'default', $assetImporter, new NullOutput)
        : $parser->parseModule($data, 'default', $assetImporter, new NullOutput);
}

/**
 * Strip a row id after checking its shape.
 *
 * @param  array<int|string, mixed>  $row  A set or an item
 * @return array<int|string, mixed> The row without its id
 */
function linkListWithoutId(array $row): array
{
    expect($row['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');

    unset($row['id']);

    return $row;
}

/**
 * Parse data and strip the ids from the set and its items.
 *
 * @param  string  $entry  `block` or `module`
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?FakeAssetImporter  $assetImporter  The importer
 * @return array<int|string, mixed> The replicator set without ids
 */
function linkListSet(string $entry, array $data, ?FakeAssetImporter $assetImporter = null): array
{
    $set = linkListParse($entry, $data, $assetImporter);

    expect($set)->toBeArray()->not->toBe([]);
    \assert(\is_array($set));

    $set = linkListWithoutId($set);
    $items = \is_array($set['items'] ?? null) ? $set['items'] : [];
    $set['items'] = array_map(
        fn (mixed $item): array => linkListWithoutId(\is_array($item) ? $item : []),
        $items
    );

    return $set;
}

/**
 * The items of a parsed set, without ids.
 *
 * @param  string  $entry  `block` or `module`
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?FakeAssetImporter  $assetImporter  The importer
 * @return list<array<int|string, mixed>> The items, each an array
 */
function linkListItems(string $entry, array $data, ?FakeAssetImporter $assetImporter = null): array
{
    return linkListSetItems(linkListSet($entry, $data, $assetImporter));
}

/**
 * The items of a replicator set.
 *
 * @param  array<int|string, mixed>  $set  The replicator set
 * @return list<array<int|string, mixed>> Its items, each an array
 */
function linkListSetItems(array $set): array
{
    $items = [];

    foreach (\is_array($set['items'] ?? null) ? $set['items'] : [] as $item) {
        $items[] = \is_array($item) ? $item : [];
    }

    return $items;
}

/**
 * The indexed fields of one link-list section.
 *
 * @param  int  $index  The section index
 * @param  array<int|string, mixed>  $fields  Field values keyed by the name after `link_list_section_`
 * @return array<string, mixed> The prefixed fields
 */
function linkListSection(int $index, array $fields): array
{
    $prefixed = [];

    foreach ($fields as $name => $value) {
        $prefixed["link_list_section_{$index}_link_list_section_{$name}"] = $value;
    }

    return $prefixed;
}

dataset('linkListEntries', ['block', 'module']);

it('maps the block and each counted section with a link onto the set', function (string $entry): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(linkListSet($entry, [
        'link_list_heading' => 'Useful <b>links</b>',
        'link_list_description' => 'Pick <i>one</i>',
        'link_list_background_color' => 'bg-red',
        'link_list_section' => '2',
        ...linkListSection(0, [
            'title' => 'Apply <em>now</em>',
            'body' => '<p>Start here.</p>',
            'url' => ['url' => 'https://example.com/apply', 'target' => '_blank'],
            'description' => 'Apply <i>online</i>',
        ]),
        ...linkListSection(1, ['title' => 'Visit', 'url' => 'https://example.com/visit']),
        ...linkListSection(2, ['title' => 'Not counted', 'url' => 'https://example.com/no']),
    ]))->toBe([
        'type' => 'uamsweb_block_link_list',
        'block_heading' => 'Useful links',
        'hide_block_heading' => false,
        'background_color' => 'bg-red',
        'items' => [
            [
                'type' => 'url',
                'heading' => 'Apply now',
                'url' => 'https://example.com/apply',
                'target' => true,
                'enabled' => true,
                'body' => 'Start here.',
                'link_description' => 'Apply <i>online</i>',
            ],
            [
                'type' => 'url',
                'heading' => 'Visit',
                'url' => 'https://example.com/visit',
                'target' => false,
                'enabled' => true,
            ],
        ],
        'enabled' => true,
        'block_description' => 'Pick one',
    ]);

    $logSpy->shouldNotHaveReceived('warning');
})->with('linkListEntries');

it('puts placeholders in for a missing heading and defaults the color', function (string $entry, mixed $color): void {
    $set = linkListSet($entry, [
        'link_list_heading' => '<b></b>',
        'link_list_background_color' => $color,
        'link_list_section' => 1,
        ...linkListSection(0, ['url' => 'https://example.com/a']),
    ]);

    expect($set['block_heading'])->toBe("Placeholder Block Heading \u{2014} Please Replace")
        ->and($set['background_color'])->toBe('bg-auto')
        ->and(array_key_exists('block_description', $set))->toBeFalse()
        ->and(linkListSetItems($set)[0]['heading'] ?? null)->toBe("Placeholder Item Heading \u{2014} Please Replace");
})->with('linkListEntries')->with([
    'missing' => [null],
    'empty' => [''],
    'not text' => [['bg-red']],
]);

it('puts the placeholder in for a block with no heading at all', function (string $entry): void {
    expect(linkListSet($entry, [
        'link_list_section' => 1,
        ...linkListSection(0, ['title' => 'Item', 'url' => 'https://example.com/a']),
    ])['block_heading'])->toBe("Placeholder Block Heading \u{2014} Please Replace");
})->with('linkListEntries');

it('reads the section count as an integer, a numeric string, or nothing', function (string $entry, mixed $count, int $expected): void {
    $data = ['link_list_section' => $count];

    for ($i = 0; $i < 3; $i++) {
        $data += linkListSection($i, ['title' => "Link $i", 'url' => "https://example.com/$i"]);
    }

    if ($expected === 0) {
        expect(linkListParse($entry, $data))->toBe($entry === 'block' ? [] : null);

        return;
    }

    expect(array_column(linkListItems($entry, $data), 'heading'))
        ->toBe(array_slice(['Link 0', 'Link 1', 'Link 2'], 0, $expected));
})->with('linkListEntries')->with([
    'an integer' => [3, 3],
    'a numeric string' => ['2', 2],
    'a decimal string' => ['1.9', 1],
    'one' => [1, 1],
    'a float' => [2.0, 0],
    'a non-numeric string' => ['two', 0],
    'zero' => [0, 0],
    'a negative integer' => [-1, 0],
    'a negative string' => ['-2', 0],
    'missing' => [null, 0],
]);

it('casts scalars in text fields and falls back for other types', function (string $entry): void {
    $set = linkListSet($entry, [
        'link_list_heading' => 2026,
        'link_list_description' => 1.5,
        'link_list_background_color' => 7,
        'link_list_section' => 2,
        ...linkListSection(0, [
            'title' => true,
            'body' => 42,
            'url' => 'https://example.com/a',
            'description' => false,
        ]),
        ...linkListSection(1, [
            'title' => ['Ignored'],
            'body' => ['Ignored'],
            'url' => 'https://example.com/b',
            'description' => 3,
        ]),
    ]);

    $items = linkListSetItems($set);

    expect($set['block_heading'])->toBe('2026')
        ->and($set['block_description'] ?? null)->toBe('1.5')
        ->and($set['background_color'])->toBe('7')
        ->and($items)->toHaveCount(2)
        ->and($items[0]['heading'] ?? null)->toBe('1')
        ->and($items[0]['body'] ?? null)->toBe('42')
        ->and(array_key_exists('link_description', $items[0] ?? []))->toBeFalse()
        ->and($items[1]['heading'] ?? null)->toBe("Placeholder Item Heading \u{2014} Please Replace")
        ->and(array_key_exists('body', $items[1] ?? []))->toBeFalse()
        ->and($items[1]['link_description'] ?? null)->toBe('3');
})->with('linkListEntries');

it('maps the ACF field keys onto the standard names, over the standard keys', function (string $entry): void {
    $set = linkListSet($entry, [
        'link_list_heading' => 'Overridden',
        'field_link_list_heading_b' => 'From the field key',
        'field_link_list_description_b' => 'Described',
        'field_link_list_background_color_b' => 'bg-blue',
        'field_link_list_section_b' => '1',
        ...linkListSection(0, ['title' => 'Only', 'url' => 'https://example.com/a']),
    ]);

    expect($set['block_heading'])->toBe('From the field key')
        ->and($set['block_description'] ?? null)->toBe('Described')
        ->and($set['background_color'])->toBe('bg-blue')
        ->and(array_column(linkListSetItems($set), 'heading'))->toBe(['Only']);
})->with('linkListEntries');

it('makes an imported same-site file an asset file item', function (string $entry): void {
    $assetImporter = new FakeAssetImporter(importedFile: 'docs/report.pdf', baseBlogUrl: 'https://wp.example.org');

    $items = linkListItems($entry, [
        'link_list_section' => 1,
        ...linkListSection(0, [
            'title' => 'Report',
            'url' => ['url' => 'https://wp.example.org/wp-content/uploads/report.pdf', 'target' => '_blank'],
        ]),
    ], $assetImporter);

    expect($items)->toBe([[
        'type' => 'asset_file',
        'heading' => 'Report',
        'url' => ['docs/report.pdf'],
        'target' => true,
        'enabled' => true,
    ]])->and($assetImporter->importedFiles)->toBe([
        ['https://wp.example.org/wp-content/uploads/report.pdf', 'default', 'files'],
    ]);
})->with('linkListEntries');

it('keeps a link a url item when it is not an imported same-site file', function (string $entry, string $url, ?string $imported, ?string $baseBlogUrl, string $expected): void {
    $items = linkListItems($entry, [
        'link_list_section' => 1,
        ...linkListSection(0, ['title' => 'Link', 'url' => $url]),
    ], new FakeAssetImporter(importedFile: $imported, baseBlogUrl: $baseBlogUrl));

    expect($items[0]['type'] ?? null)->toBe('url')
        ->and($items[0]['url'] ?? null)->toBe($expected);
})->with('linkListEntries')->with([
    'a file on another site' => [
        'https://other.example.net/report.pdf',
        'docs/report.pdf',
        'https://wp.example.org',
        'https://statamic.test/files/docs/report.pdf',
    ],
    'a file that was not imported' => [
        'https://wp.example.org/wp-content/uploads/report.pdf',
        null,
        'https://wp.example.org',
        'https://wp.example.org/wp-content/uploads/report.pdf',
    ],
    'a file on the app host that was not imported' => [
        'https://statamic.test/docs/report.pdf',
        null,
        null,
        'https://statamic.test/docs/report.pdf',
    ],
    'a files url that is not a file' => [
        'https://statamic.test/files/report',
        'docs/report.pdf',
        null,
        'https://statamic.test/files/report',
    ],
]);

it('skips a section with no link, naming the post and page', function (string $entry, mixed $url): void {
    ProcessingContext::setWordPressPageLink('https://example.org/links/');
    ProcessingContext::setWordPressPostId('77');
    ProcessingContext::setEntryData(['slug' => 'links']);

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $items = linkListItems($entry, [
        'link_list_section' => 2,
        ...linkListSection(0, ['title' => 'No <b>link</b>', 'url' => $url]),
        ...linkListSection(1, ['title' => 'Linked', 'url' => 'https://example.com/a']),
    ]);

    expect(array_column($items, 'heading'))->toBe(['Linked']);

    $logSpy->shouldHaveReceived('warning', [
        'LinkListParser::processItems: Skipping invalid link_list_section item (from entry: https://example.org/links/)',
        [
            'site' => 'default',
            'heading' => 'No link',
            'has_url' => false,
            'original_url' => '',
            'wordpress_post_id' => '77',
            'page_slug' => 'links',
            'entry_url' => 'https://example.org/links/',
        ],
    ]);
})->with('linkListEntries')->with([
    'an empty url' => [''],
    'an empty link array' => [['url' => '', 'target' => '_blank']],
    'missing' => [null],
]);

it('names no post or page when none is set', function (string $entry): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    linkListItems($entry, [
        'link_list_section' => 2,
        ...linkListSection(0, ['title' => 'Unlinked']),
        ...linkListSection(1, ['title' => 'Linked', 'url' => 'https://example.com/a']),
    ]);

    $logSpy->shouldHaveReceived('warning', [
        'LinkListParser::processItems: Skipping invalid link_list_section item',
        [
            'site' => 'default',
            'heading' => 'Unlinked',
            'has_url' => false,
            'original_url' => '',
            'wordpress_post_id' => null,
            'page_slug' => null,
        ],
    ]);
})->with('linkListEntries');

it('skips the whole set when no section has a link', function (string $entry, string $message, mixed $expected): void {
    ProcessingContext::setWordPressPageLink('https://example.org/links/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(linkListParse($entry, [
        'link_list_heading' => 'Heading',
        'link_list_section' => 1,
        ...linkListSection(0, ['title' => 'Unlinked']),
    ]))->toBe($expected);

    $logSpy->shouldHaveReceived('warning', [
        $message.' (from entry: https://example.org/links/)',
        ['site' => 'default', 'entry_url' => 'https://example.org/links/'],
    ]);
})->with([
    'block' => ['block', 'LinkListParser::parse: No valid items for wp:acf/link-list block; skipping', []],
    'module' => ['module', 'LinkListParser::parseModule: No valid items for modules template link_list; skipping', null],
]);

it('reads block attributes without a data key, and reads nothing from a data key that is not an array', function (): void {
    $parser = new LinkListParser;
    $data = [
        'link_list_heading' => 'Bare',
        'link_list_section' => 1,
        ...linkListSection(0, ['url' => 'https://example.com/a']),
    ];

    $bare = $parser->parse($data, 'default', new FakeAssetImporter, new NullOutput);
    $scalar = $parser->parse(['data' => 'x', ...$data], 'default', new FakeAssetImporter, new NullOutput);

    expect($bare['block_heading'] ?? null)->toBe('Bare')
        ->and($scalar)->toBe([]);
});

it('gives each set and each item its own row id', function (string $entry): void {
    $data = [
        'link_list_section' => 2,
        ...linkListSection(0, ['url' => 'https://example.com/a']),
        ...linkListSection(1, ['url' => 'https://example.com/b']),
    ];

    $first = (array) linkListParse($entry, $data);
    $items = \is_array($first['items'] ?? null) ? $first['items'] : [];
    $ids = array_map(
        fn (mixed $item): string => \is_array($item) && \is_string($item['id'] ?? null) ? $item['id'] : '',
        $items
    );

    expect($first['id'] ?? null)->not->toBe(((array) linkListParse($entry, $data))['id'] ?? null)
        ->and($ids)->toHaveCount(2)
        ->and(array_unique($ids))->toHaveCount(2);
})->with('linkListEntries');
