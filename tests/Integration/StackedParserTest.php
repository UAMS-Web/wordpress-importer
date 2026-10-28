<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\StackedParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\FakeAssetImporter;

/**
 * Tests that `StackedParser` maps an ACF text-stacked block, through either
 * entry point, onto the `uamsweb_block_stacked` set: the block fields, each
 * stacked section with its placeholders, image and button, the footer link,
 * and the warnings a skipped item or block logs. Also covers the checks and
 * conversions the ACF block parser uses to turn a text-stacked block into a
 * link-list or a section block.
 *
 * @command  php vendor/bin/pest tests/Integration/StackedParserTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    Http::fake(['*' => Http::response('', 404)]);
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::setStatamicEntryId(null);
    ProcessingContext::setCollectionHandle(null);
});

/**
 * Removes and checks the row id of a set and of each of its items.
 *
 * @param  array<int|string, mixed>  $set  The set
 * @return array<int|string, mixed> The set without ids
 */
function stkWithoutIds(array $set): array
{
    expect($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');
    unset($set['id']);

    if (isset($set['items']) && is_array($set['items'])) {
        $set['items'] = array_map(static function (mixed $item): mixed {
            expect($item)->toBeArray();
            if (! is_array($item)) {
                return $item;
            }

            expect($item['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');
            unset($item['id']);

            return $item;
        }, $set['items']);
    }

    return $set;
}

/**
 * Parses block data, wrapped in a `data` key.
 *
 * @param  array<int|string, mixed>  $data  The block data
 * @param  ?FakeAssetImporter  $assetImporter  The asset importer; uploads disabled by default
 * @param  array<int|string, mixed>  $attachments  The export's attachments
 * @return array<int|string, mixed> The set without ids
 */
function stkParse(array $data, ?FakeAssetImporter $assetImporter = null, array $attachments = []): array
{
    return stkWithoutIds((new StackedParser)->parse(
        ['data' => $data],
        'default',
        $assetImporter ?? new FakeAssetImporter(false),
        new NullOutput,
        $attachments
    ));
}

/**
 * Parses module data.
 *
 * @param  array<int|string, mixed>  $data  The module data
 * @param  ?FakeAssetImporter  $assetImporter  The asset importer; uploads disabled by default
 * @param  array<int|string, mixed>  $attachments  The export's attachments
 * @return ?array<int|string, mixed> The set without ids, or null when skipped
 */
function stkModule(array $data, ?FakeAssetImporter $assetImporter = null, array $attachments = []): ?array
{
    $set = (new StackedParser)->parseModule(
        $data,
        'default',
        $assetImporter ?? new FakeAssetImporter(false),
        new NullOutput,
        $attachments
    );

    return $set === null ? null : stkWithoutIds($set);
}

/**
 * Parses through the named entry point. The module entry point is given a
 * heading and one valid item unless the data sets its own, since a module
 * without either is skipped.
 *
 * @param  string  $entry  'block' or 'module'
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?FakeAssetImporter  $assetImporter  The asset importer; uploads disabled by default
 * @param  array<int|string, mixed>  $attachments  The export's attachments
 * @return ?array<int|string, mixed> The set without ids, or null when skipped
 */
function stkEntry(string $entry, array $data, ?FakeAssetImporter $assetImporter = null, array $attachments = []): ?array
{
    return $entry === 'block'
        ? stkParse($data, $assetImporter, $attachments)
        : stkModule($data + [
            'stacked_heading' => 'H',
            'stacked_section' => 1,
            'stacked_section_0_stacked_section_heading' => 'Item',
        ], $assetImporter, $attachments);
}

/**
 * The items one section maps to, through the named entry point.
 *
 * @param  string  $entry  'block' or 'module'
 * @param  array<int|string, mixed>  $section  The section's fields, without their prefix
 * @param  ?FakeAssetImporter  $assetImporter  The asset importer; uploads disabled by default
 * @param  array<int|string, mixed>  $attachments  The export's attachments
 * @return mixed The set's items, or null when the set has none
 */
function stkItems(string $entry, array $section, ?FakeAssetImporter $assetImporter = null, array $attachments = []): mixed
{
    $data = ['stacked_section' => 1];
    foreach ($section as $field => $value) {
        $data["stacked_section_0_stacked_section_{$field}"] = $value;
    }

    $set = $entry === 'block'
        ? stkParse($data, $assetImporter, $attachments)
        : stkModule(['stacked_heading' => 'H'] + $data, $assetImporter, $attachments);

    return $set === null ? null : ($set['items'] ?? null);
}

/**
 * The warnings logged while running a callback, with their contexts.
 *
 * @param  callable(): mixed  $callback  The code to run
 * @return list<array{string, array<int|string, mixed>}> Each warning's message and context, in order
 */
function stkWarnings(callable $callback): array
{
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = [$event->message, $event->context];
        }
    });

    $callback();

    return $warnings;
}

/**
 * An attachment item from the export.
 *
 * @param  string  $postId  The attachment's post id
 * @param  string  $url  The attachment's URL
 */
function stkAttachment(string $postId, string $url): SimpleXMLElement
{
    return new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        ."<wp:post_id>{$postId}</wp:post_id><wp:attachment_url>{$url}</wp:attachment_url></item>"
    );
}

/**
 * The placeholder a missing item field is given.
 *
 * @param  string  $field  'Heading' or 'Body'
 */
function stkPlaceholder(string $field): string
{
    return "Placeholder Item {$field} \u{2014} Please Replace";
}

dataset('stkEntries', ['block', 'module']);

it('maps the ACF field keys to the standard field names', function (): void {
    $parser = new StackedParser;

    expect($parser->getFieldMappings())->toBe([
        'field_stacked_heading_b' => 'stacked_heading',
        'field_stacked_hide_heading_b' => 'stacked_hide_heading',
        'field_stacked_description_b' => 'stacked_description',
        'field_stacked_background_color_b' => 'stacked_background_color',
        'field_stacked_more_b' => 'stacked_more',
        'field_stacked_more_text_b' => 'stacked_more_text',
        'field_stacked_more_button_text_b' => 'stacked_more_button_text',
        'field_stacked_more_button_url_b' => 'stacked_more_button_url',
        'field_stacked_more_button_description_b' => 'stacked_more_button_description',
    ])->and($parser->getNestedStructures())->toBe([]);
});

it('reports whether every section lacks an image', function (array $data, bool $expected): void {
    expect(StackedParser::allItemsHaveNoImages($data))->toBe($expected);
})->with([
    'no sections' => [[], false],
    'zero sections' => [['stacked_section' => 0], false],
    'a count that is not a number' => [['stacked_section' => 'two'], false],
    'a float count' => [['stacked_section' => 2.0], false],
    'one section, no image key' => [['stacked_section' => 1], true],
    'one section, an empty image' => [['stacked_section' => '1', 'stacked_section_0_stacked_section_image' => ''], true],
    'one section, a null image' => [['stacked_section' => 1, 'stacked_section_0_stacked_section_image' => null], true],
    'one section, an integer image' => [['stacked_section' => 1, 'stacked_section_0_stacked_section_image' => 0], false],
    'an image past the count' => [['stacked_section' => 1, 'stacked_section_1_stacked_section_image' => '9'], true],
    'an image before the first section' => [['stacked_section' => 1, 'stacked_section_-1_stacked_section_image' => '9'], true],
    'an image in the last section' => [['stacked_section' => 3, 'stacked_section_2_stacked_section_image' => '9'], false],
]);

it('reports whether every section has a URL', function (array $data, bool $expected): void {
    expect(StackedParser::allItemsHaveUrls($data))->toBe($expected);
})->with([
    'no sections' => [[], false],
    'a numeric string count, every URL set' => [[
        'stacked_section' => '2',
        'stacked_section_0_stacked_section_button_url' => ['url' => 'https://example.org/a/'],
        'stacked_section_1_stacked_section_button_url' => 'https://example.org/b/',
    ], true],
    'the last URL missing' => [[
        'stacked_section' => 2,
        'stacked_section_0_stacked_section_button_url' => 'https://example.org/a/',
    ], false],
    'the first URL empty' => [[
        'stacked_section' => 2,
        'stacked_section_0_stacked_section_button_url' => ['url' => ''],
        'stacked_section_1_stacked_section_button_url' => 'https://example.org/b/',
    ], false],
    'a URL past the count' => [[
        'stacked_section' => 1,
        'stacked_section_0_stacked_section_button_url' => 'https://example.org/a/',
        'stacked_section_1_stacked_section_button_url' => '',
    ], true],
]);

it('converts a block to section format, keeping the data wrapper', function (): void {
    expect(StackedParser::convertToSectionFormat(['data' => [
        'field_stacked_heading_b' => 'Heading',
        'field_stacked_hide_heading_b' => '1',
        'field_stacked_background_color_b' => 'bg-white',
        'field_stacked_description_b' => 'Not carried',
        'stacked_section' => 3,
        'stacked_section_0_stacked_section_heading' => '<b>Tom & "Jerry"</b>',
        'stacked_section_0_stacked_section_body' => 'Body <a href="/x">link</a>',
        'stacked_section_1_stacked_section_heading' => '<i></i>',
        'stacked_section_2_stacked_section_body' => 'Only body',
    ]]))->toBe([
        'attributes' => ['data' => [
            'section_heading' => 'Heading',
            'section_hide_heading' => '1',
            'section_background_color' => 'bg-white',
        ]],
        'innerContent' => [
            ['html' => "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Tom &amp; &quot;Jerry&quot;</h3>\n<!-- /wp:heading -->\n"],
            ['html' => "<!-- wp:paragraph -->\n<p>Body <a href=\"/x\">link</a></p>\n<!-- /wp:paragraph -->\n"],
            ['html' => "<!-- wp:paragraph -->\n<p>Only body</p>\n<!-- /wp:paragraph -->\n"],
        ],
    ]);
});

it('converts a block to section format without a data wrapper', function (): void {
    expect(StackedParser::convertToSectionFormat([
        'stacked_heading' => 'Heading',
        'stacked_hide_heading' => null,
        'stacked_section' => 1,
        'stacked_section_0_stacked_section_heading' => 'Only heading',
    ]))->toBe([
        'attributes' => ['section_heading' => 'Heading'],
        'innerContent' => [
            ['html' => "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">Only heading</h3>\n<!-- /wp:heading -->\n"],
        ],
    ]);
});

it('converts only the sections from the first up to the count to section format', function (): void {
    expect(StackedParser::convertToSectionFormat([
        'stacked_section' => 1,
        'stacked_section_-1_stacked_section_heading' => 'Before',
        'stacked_section_0_stacked_section_body' => 'Inside',
        'stacked_section_1_stacked_section_heading' => 'After',
    ])['innerContent'])->toBe([
        ['html' => "<!-- wp:paragraph -->\n<p>Inside</p>\n<!-- /wp:paragraph -->\n"],
    ]);
});

it('converts data that is not an array to empty section attributes, still wrapped', function (): void {
    expect(StackedParser::convertToSectionFormat(['data' => 'x']))
        ->toBe(['attributes' => ['data' => []], 'innerContent' => []]);
});

it('reads a null data wrapper as unwrapped attributes', function (): void {
    expect(StackedParser::convertToSectionFormat(['data' => null, 'stacked_heading' => 'H']))
        ->toBe(['attributes' => ['section_heading' => 'H'], 'innerContent' => []])
        ->and(StackedParser::convertToLinkListAttributes(['data' => null, 'stacked_heading' => 'H']))
        ->toBe(['link_list_heading' => 'H']);
});

it('converts a block to link-list attributes, keeping the data wrapper', function (): void {
    expect(StackedParser::convertToLinkListAttributes(['data' => [
        'field_stacked_heading_b' => 'Heading',
        'field_stacked_description_b' => 'Description',
        'field_stacked_background_color_b' => 'bg-gray',
        'field_stacked_hide_heading_b' => '1',
        'stacked_section' => '2',
        'stacked_section_0_stacked_section_heading' => 'One',
        'stacked_section_0_stacked_section_body' => 'Body one',
        'stacked_section_0_stacked_section_button_url' => ['url' => 'https://example.org/1/'],
        'stacked_section_0_stacked_section_button_description' => 'Go one',
        'stacked_section_0_stacked_section_image' => '5',
        'stacked_section_1_stacked_section_heading' => 'Two',
        'stacked_section_1_stacked_section_body' => null,
        'stacked_section_2_stacked_section_heading' => 'Past the count',
    ]]))->toBe(['data' => [
        'link_list_heading' => 'Heading',
        'link_list_description' => 'Description',
        'link_list_background_color' => 'bg-gray',
        'link_list_section' => 2,
        'link_list_section_0_link_list_section_title' => 'One',
        'link_list_section_0_link_list_section_body' => 'Body one',
        'link_list_section_0_link_list_section_url' => ['url' => 'https://example.org/1/'],
        'link_list_section_0_link_list_section_description' => 'Go one',
        'link_list_section_1_link_list_section_title' => 'Two',
    ]]);
});

it('converts a block with one section to link-list attributes, reading only that section', function (): void {
    expect(StackedParser::convertToLinkListAttributes([
        'stacked_section' => 1,
        'stacked_section_-1_stacked_section_heading' => 'Before',
        'stacked_section_0_stacked_section_heading' => 'One',
    ]))->toBe([
        'link_list_section' => 1,
        'link_list_section_0_link_list_section_title' => 'One',
    ]);
});

it('converts a block with no sections to link-list attributes without a data wrapper', function (array $attributes, array $expected): void {
    expect(StackedParser::convertToLinkListAttributes($attributes))->toBe($expected);
})->with([
    'no sections' => [['stacked_heading' => 'H'], ['link_list_heading' => 'H']],
    'zero sections' => [['stacked_section' => 0, 'stacked_background_color' => 'bg-x'], ['link_list_background_color' => 'bg-x']],
    'data that is not an array' => [['data' => 5], ['data' => []]],
]);

it('maps an empty block to the defaults', function (): void {
    expect(stkParse([]))->toBe([
        'type' => 'uamsweb_block_stacked',
        'block_heading' => '',
        'hide_block_heading' => false,
        'background_color' => 'bg-auto',
        'more_query' => false,
        'enabled' => true,
    ]);
});

it('maps every block field, in the order the set carries them', function (): void {
    $assetImporter = new FakeAssetImporter(true, 'images/one.jpg');

    expect(stkParse([
        'field_stacked_heading_b' => '<b>Heading</b>',
        'field_stacked_hide_heading_b' => '1',
        'field_stacked_description_b' => '<p>Description</p>',
        'field_stacked_background_color_b' => 'bg-white',
        'field_stacked_more_b' => '1',
        'field_stacked_more_text_b' => '<i>More</i>',
        'field_stacked_more_button_text_b' => '<i>All</i>',
        'field_stacked_more_button_url_b' => ['url' => 'https://example.org/all/', 'target' => '_blank'],
        'field_stacked_more_button_description_b' => '<b>See all</b>',
        'stacked_section' => 1,
        'stacked_section_0_stacked_section_heading' => 'One',
        'stacked_section_0_stacked_section_body' => 'Body',
        'stacked_section_0_stacked_section_image' => '5',
        'stacked_section_0_stacked_section_alt_override' => 'Alt',
        'stacked_section_0_stacked_section_button_text' => 'Go',
        'stacked_section_0_stacked_section_button_url' => ['url' => 'https://example.org/go/', 'target' => '_blank'],
        'stacked_section_0_stacked_section_button_description' => 'Go now',
    ], $assetImporter))->toBe([
        'type' => 'uamsweb_block_stacked',
        'block_heading' => 'Heading',
        'hide_block_heading' => true,
        'background_color' => 'bg-white',
        'more_query' => true,
        'enabled' => true,
        'block_description' => 'Description',
        'items' => [[
            'type' => 'new_set',
            'heading' => 'One',
            'body' => 'Body',
            'image' => 'images/one.jpg',
            'enabled' => true,
            'alt_override' => 'Alt',
            'button_text' => 'Go',
            'button_url' => 'https://example.org/go/',
            'button_target' => true,
            'button_link_description' => 'Go now',
        ]],
        'more_heading' => '<i>More</i>',
        'more_button_text' => '<i>All</i>',
        'more_button_url' => 'https://example.org/all/',
        'more_button_target' => true,
        'more_button_link_description' => 'See all',
    ])->and($assetImporter->importedImages)->toBe([['5', 'default']]);
});

it('maps every module field, in the order the set carries them', function (): void {
    expect(stkModule([
        'field_stacked_heading_b' => '<b>Heading</b>',
        'field_stacked_hide_heading_b' => '1',
        'field_stacked_description_b' => '<p>Description</p>',
        'field_stacked_background_color_b' => 'bg-white',
        'field_stacked_more_b' => '1',
        'field_stacked_more_text_b' => '<i>More</i>',
        'field_stacked_more_button_text_b' => '<i>All</i>',
        'field_stacked_more_button_url_b' => ['url' => 'https://example.org/all/', 'target' => '_blank'],
        'field_stacked_more_button_description_b' => '<b>See all</b>',
        'stacked_section' => '1',
        'stacked_section_0_stacked_section_heading' => '<b>One</b>',
        'stacked_section_0_stacked_section_body' => '<p>Body</p>',
    ]))->toBe([
        'type' => 'uamsweb_block_stacked',
        'block_heading' => 'Heading',
        'hide_block_heading' => true,
        'background_color' => 'bg-white',
        'more_query' => true,
        'enabled' => true,
        'block_description' => 'Description',
        'items' => [[
            'type' => 'new_set',
            'heading' => 'One',
            'body' => 'Body',
            'image' => null,
            'enabled' => true,
        ]],
        'more_heading' => 'More',
        'more_button_text' => 'All',
        'more_button_url' => 'https://example.org/all/',
        'more_button_target' => true,
        'more_button_link_description' => 'See all',
    ]);
});

it('reads the heading, description and background color as text', function (string $entry, mixed $value, string $text): void {
    $set = stkEntry($entry, [
        ...($entry === 'block' ? ['stacked_heading' => $value] : []),
        'stacked_description' => $value,
        'stacked_background_color' => $value,
    ]);

    expect($set['block_description'] ?? '')->toBe($text)
        ->and($set['background_color'] ?? null)->toBe($text === '' ? 'bg-auto' : $text);

    if ($entry === 'block') {
        expect($set['block_heading'] ?? null)->toBe($text);
    }
})->with('stkEntries')->with([
    'an integer' => [5, '5'],
    'a float' => [1.5, '1.5'],
    'a boolean' => [true, ''],
    'an array' => [['x'], ''],
]);

it('hides the heading only when the switch is the string or integer one', function (string $entry, mixed $hide, bool $hidden): void {
    expect(stkEntry($entry, ['stacked_hide_heading' => $hide])['hide_block_heading'] ?? null)->toBe($hidden);
})->with('stkEntries')->with([
    'one' => ['1', true],
    'an integer one' => [1, true],
    'zero' => ['0', false],
    'true' => [true, false],
    'yes' => ['yes', false],
]);

it('maps only the attributes under the data key of a block', function (): void {
    expect(stkWithoutIds((new StackedParser)->parse(
        ['stacked_heading' => 'Outside', 'data' => ['stacked_heading' => 'Inside']],
        'default',
        new FakeAssetImporter(false),
        new NullOutput
    ))['block_heading'] ?? null)->toBe('Inside')
        ->and(stkWithoutIds((new StackedParser)->parse(
            ['stacked_heading' => 'Bare'],
            'default',
            new FakeAssetImporter(false),
            new NullOutput
        ))['block_heading'] ?? null)->toBe('Bare')
        ->and(stkWithoutIds((new StackedParser)->parse(
            ['data' => 'x', 'stacked_heading' => 'Ignored'],
            'default',
            new FakeAssetImporter(false),
            new NullOutput
        ))['block_heading'] ?? null)->toBe('');
});

it('reads module data as it is, without unwrapping a data key', function (): void {
    expect(stkWarnings(function (): void {
        expect(stkModule(['data' => ['stacked_heading' => 'H']]))->toBeNull();
    }))->toBe([[
        'StackedParser::parseModule: Skipping modules template text_stacked due to missing required heading',
        ['site' => 'default'],
    ]]);
});

it('skips a module whose heading is empty once tags are stripped, before importing anything', function (): void {
    $assetImporter = new FakeAssetImporter(true, 'images/x.jpg');

    expect(stkWarnings(function () use ($assetImporter): void {
        expect(stkModule([
            'stacked_heading' => '<b></b>',
            'stacked_section' => 1,
            'stacked_section_0_stacked_section_heading' => 'One',
            'stacked_section_0_stacked_section_image' => '5',
        ], $assetImporter))->toBeNull();
    }))->toHaveCount(1)
        ->and($assetImporter->importedImages)->toBe([]);
});

it('skips a module with no valid items', function (array $sections): void {
    expect(stkWarnings(function () use ($sections): void {
        expect(stkModule(['stacked_heading' => 'H'] + $sections))->toBeNull();
    }))->toBe([
        ...(($sections['stacked_section'] ?? 0) === 1 ? [[
            'StackedParser::processItems: Skipping invalid stacked_section item',
            ['heading' => stkPlaceholder('Heading'), 'has_body' => false, 'has_image' => false],
        ]] : []),
        [
            'StackedParser::parseModule: No valid items for modules template text_stacked; skipping',
            ['site' => 'default'],
        ],
    ]);
})->with([
    'no sections' => [[]],
    'one empty section' => [['stacked_section' => 1]],
]);

it('omits the items of a block with no valid items, and keeps the block', function (): void {
    expect(stkParse(['stacked_heading' => 'H', 'stacked_section' => 1]))->not->toHaveKey('items')
        ->and(stkParse(['stacked_heading' => 'H']))->not->toHaveKey('items');
});

it('maps each section to an item, in order, up to the section count', function (string $entry): void {
    $set = stkEntry($entry, [
        'stacked_section' => 2,
        'stacked_section_0_stacked_section_heading' => 'One',
        'stacked_section_1_stacked_section_heading' => 'Two',
        'stacked_section_2_stacked_section_heading' => 'Three',
    ]);

    expect(array_column(is_array($set['items'] ?? null) ? $set['items'] : [], 'heading'))->toBe(['One', 'Two']);
})->with('stkEntries');

it('keeps the items after one it skips', function (string $entry): void {
    $set = stkEntry($entry, [
        'stacked_section' => 2,
        'stacked_section_0_stacked_section_heading' => '',
        'stacked_section_1_stacked_section_heading' => 'Two',
    ]);

    expect(array_column(is_array($set['items'] ?? null) ? $set['items'] : [], 'heading'))->toBe(['Two']);
})->with('stkEntries');

it('omits a description that is missing', function (string $entry): void {
    expect(stkEntry($entry, []))->not->toHaveKey('block_description');
})->with('stkEntries');

it('gives an item without a heading or body a placeholder for it', function (string $entry, array $section, string $heading, string $body): void {
    expect(stkItems($entry, $section))->toBe([[
        'type' => 'new_set',
        'heading' => $heading,
        'body' => $body,
        'image' => null,
        'enabled' => true,
    ]]);
})->with('stkEntries')->with([
    'no body' => [['heading' => 'One'], 'One', stkPlaceholder('Body')],
    'no heading' => [['body' => 'Text'], stkPlaceholder('Heading'), 'Text'],
    'a heading of tags only' => [['heading' => '<b> </b>', 'body' => '<p>Text</p>'], stkPlaceholder('Heading'), 'Text'],
    'an array body' => [['heading' => 'One', 'body' => ['x']], 'One', stkPlaceholder('Body')],
]);

it('skips an item with neither a heading nor a body, with a warning', function (string $entry): void {
    $warnings = stkWarnings(function () use ($entry): void {
        stkItems($entry, ['body' => '<p></p>']);
    });

    expect($warnings[0] ?? null)->toBe([
        'StackedParser::processItems: Skipping invalid stacked_section item',
        ['heading' => stkPlaceholder('Heading'), 'has_body' => false, 'has_image' => false],
    ]);
})->with('stkEntries');

it('skips an item with no image while uploads are enabled', function (string $entry): void {
    $assetImporter = new FakeAssetImporter(true, 'images/x.jpg');

    expect(stkWarnings(function () use ($entry, $assetImporter): void {
        expect(stkItems($entry, ['heading' => 'One', 'body' => 'Text'], $assetImporter))->toBeNull();
    })[0] ?? null)->toBe([
        'StackedParser::processItems: Skipping invalid stacked_section item',
        ['heading' => 'One', 'has_body' => true, 'has_image' => false],
    ])->and($assetImporter->importedImages)->toBe([]);
})->with('stkEntries');

it('skips an item whose image fails to import while uploads are enabled, with two warnings', function (string $entry, ?string $imported): void {
    $warnings = stkWarnings(function () use ($entry, $imported): void {
        expect(stkItems($entry, ['heading' => 'One', 'image' => '5'], new FakeAssetImporter(true, $imported)))->toBeNull();
    });

    expect(array_slice($warnings, 0, 2))->toBe([
        [
            'StackedParser::processItems: Failed to import stacked section image when uploads are enabled',
            ['site' => 'default', 'image_id' => '5'],
        ],
        [
            'StackedParser::processItems: Skipping invalid stacked_section item',
            ['heading' => 'One', 'has_body' => false, 'has_image' => false],
        ],
    ]);
})->with('stkEntries')->with([
    'null' => [null],
    'empty' => [''],
]);

it('keeps an item with an imported image, and logs nothing', function (string $entry): void {
    expect(stkWarnings(function () use ($entry): void {
        expect(stkItems($entry, ['heading' => 'One', 'image' => '5'], new FakeAssetImporter(true, 'images/one.jpg')))->toBe([[
            'type' => 'new_set',
            'heading' => 'One',
            'body' => stkPlaceholder('Body'),
            'image' => 'images/one.jpg',
            'enabled' => true,
        ]]);
    }))->toBe([]);
})->with('stkEntries');

it('uses the attachment URL for an image while uploads are disabled', function (string $entry, string $id, ?string $image): void {
    expect(stkWarnings(function () use ($entry, $id, $image): void {
        $items = stkItems($entry, ['heading' => 'One', 'image' => $id], null, [
            'not an attachment',
            stkAttachment('5', 'https://example.org/one.jpg'),
        ]);

        expect(is_array($items) ? array_column($items, 'image') : null)->toBe([$image]);
    }))->toBe([]);
})->with('stkEntries')->with([
    'a known attachment' => ['5', 'https://example.org/one.jpg'],
    'an unknown attachment' => ['6', null],
]);

it('adds the alt override only when it is set', function (string $entry, mixed $alt, bool $added): void {
    $items = stkItems($entry, ['heading' => 'One', 'alt_override' => $alt]);
    $item = is_array($items) ? ($items[0] ?? []) : [];

    expect(is_array($item) && array_key_exists('alt_override', $item))->toBe($added);
})->with('stkEntries')->with([
    'set' => ['Alt', true],
    'empty' => ['', false],
    'an array' => [['x'], false],
]);

it('adds the button only when it has text', function (string $entry, array $button, ?array $fields): void {
    $items = stkItems($entry, ['heading' => 'One'] + $button);
    $item = is_array($items) ? ($items[0] ?? []) : [];

    expect(is_array($item) ? array_diff_key($item, ['type' => 1, 'heading' => 1, 'body' => 1, 'image' => 1, 'enabled' => 1]) : null)
        ->toBe($fields ?? []);
})->with('stkEntries')->with([
    'no text' => [['button_url' => 'https://example.org/', 'button_description' => 'D'], null],
    'text and a URL array' => [
        ['button_text' => 'Go', 'button_url' => ['url' => 'https://example.org/go/', 'target' => '_self'], 'button_description' => 'D'],
        ['button_text' => 'Go', 'button_url' => 'https://example.org/go/', 'button_target' => false, 'button_link_description' => 'D'],
    ],
    'text and a URL string' => [
        ['button_text' => 'Go', 'button_url' => 'https://example.org/go/'],
        ['button_text' => 'Go', 'button_url' => 'https://example.org/go/', 'button_target' => false],
    ],
    'text, a new-tab target and an empty description' => [
        ['button_text' => 'Go', 'button_url' => ['url' => 'https://example.org/go/', 'target' => '_blank'], 'button_description' => ''],
        ['button_text' => 'Go', 'button_url' => 'https://example.org/go/', 'button_target' => true],
    ],
    'text and no URL' => [
        ['button_text' => 'Go'],
        ['button_text' => 'Go', 'button_url' => '', 'button_target' => false],
    ],
]);

it('keeps the button text as it is', function (string $entry): void {
    $items = stkItems($entry, ['heading' => 'One', 'button_text' => '<b>Go</b>']);

    expect(is_array($items) ? array_column($items, 'button_text') : null)->toBe(['<b>Go</b>']);
})->with('stkEntries');

it('adds no footer link fields while the footer link is off', function (string $entry, mixed $more): void {
    expect(stkEntry($entry, [
        'stacked_more' => $more,
        'stacked_more_text' => 'More',
        'stacked_more_button_text' => 'All',
        'stacked_more_button_url' => 'https://example.org/all/',
        'stacked_more_button_description' => 'See all',
    ]))->toMatchArray(['more_query' => false])
        ->not->toHaveKeys(['more_heading', 'more_button_text', 'more_button_url', 'more_button_target', 'more_button_link_description']);
})->with('stkEntries')->with([
    'zero' => ['0'],
    'true' => [true],
    'missing' => [null],
]);

it('adds the footer heading and button only when each is set', function (string $entry, array $more, array $fields): void {
    $set = stkEntry($entry, ['stacked_more' => '1'] + $more);

    expect(is_array($set) ? array_intersect_key($set, array_flip([
        'more_heading', 'more_button_text', 'more_button_url', 'more_button_target', 'more_button_link_description',
    ])) : null)->toBe($fields);
})->with('stkEntries')->with([
    'nothing set' => [[], []],
    'a heading only' => [['stacked_more_text' => 'More', 'stacked_more_button_description' => 'D'], ['more_heading' => 'More']],
    'a button without a URL or description' => [
        ['stacked_more_button_text' => 'All'],
        ['more_button_text' => 'All', 'more_button_url' => '', 'more_button_target' => false],
    ],
    'a button with a string URL' => [
        ['stacked_more_button_text' => 'All', 'stacked_more_button_url' => 'https://example.org/all/', 'stacked_more_button_description' => '<b></b>'],
        ['more_button_text' => 'All', 'more_button_url' => 'https://example.org/all/', 'more_button_target' => false],
    ],
]);

it('reads the footer button target the same for a block and a module', function (string $entry, mixed $url, bool $target): void {
    expect(stkEntry($entry, [
        'stacked_more' => '1',
        'stacked_more_button_text' => 'All',
        'stacked_more_button_url' => $url,
    ])['more_button_target'] ?? null)->toBe($target);
})->with([
    'a block, _blank' => ['block', ['url' => 'https://example.org/', 'target' => '_blank'], true],
    'a block, _self' => ['block', ['url' => 'https://example.org/', 'target' => '_self'], false],
    'a block, no target' => ['block', ['url' => 'https://example.org/'], false],
    'a module, _blank' => ['module', ['url' => 'https://example.org/', 'target' => '_blank'], true],
    'a module, _self' => ['module', ['url' => 'https://example.org/', 'target' => '_self'], false],
    'a module, no target' => ['module', ['url' => 'https://example.org/'], false],
]);

it('processes the item and footer button URLs through the link processor', function (string $entry): void {
    $url = 'https://wp.example.org/wp-content/uploads/report.pdf';
    $assetImporter = new FakeAssetImporter(false, null, 'docs/report.pdf', 'https://wp.example.org');

    $set = stkEntry($entry, [
        'stacked_more' => '1',
        'stacked_more_button_text' => 'All',
        'stacked_more_button_url' => ['url' => $url],
        'stacked_section' => 1,
        'stacked_section_0_stacked_section_heading' => 'One',
        'stacked_section_0_stacked_section_button_text' => 'Go',
        'stacked_section_0_stacked_section_button_url' => $url,
    ], $assetImporter);
    $items = is_array($set) && is_array($set['items'] ?? null) ? $set['items'] : [];

    expect([array_column($items, 'button_url'), $set['more_button_url'] ?? null])->toBe([['http://localhost/files/docs/report.pdf'], 'http://localhost/files/docs/report.pdf'])
        ->and($assetImporter->importedFiles)->toBe([[$url, 'default', 'files'], [$url, 'default', 'files']]);
})->with('stkEntries');
