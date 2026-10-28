<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\SideBySideParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\FakeAssetImporter;

/**
 * Tests that `SideBySideParser` maps an ACF image-side block, through either
 * entry point, onto the `uamsweb_block_image_side_by_side` set: the block fields
 * and their defaults, each text layout's body, the link list in each shape
 * WordPress stores it, the button, the image and its options, the blocks it
 * skips, and the quote layouts it reports as incomplete.
 *
 * @command  php vendor/bin/pest tests/Integration/SideBySideParserTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    Http::fake(['*' => Http::response('', 404)]);
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ImportWarningManager::clearWarnings();
});

/**
 * Removes and checks the row id of a set or row.
 *
 * @param  array<int|string, mixed>  $set  The set or row
 * @return array<int|string, mixed> It without its id
 */
function sbsWithoutId(array $set): array
{
    expect($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');
    unset($set['id']);

    return $set;
}

/**
 * Parses through the named entry point, the block data wrapped in a `data` key.
 *
 * @param  string  $entry  'block' or 'module'
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?AssetImporter  $assetImporter  The asset importer; uploads enabled and every import succeeding by default
 * @param  array<int|string, mixed>  $attachments  The export's attachments
 * @return ?array<int|string, mixed> The set as returned, or null when the module is skipped
 */
function sbsRaw(string $entry, array $data, ?AssetImporter $assetImporter = null, array $attachments = []): ?array
{
    $assetImporter ??= new FakeAssetImporter(true, 'assets::photo.jpg');

    return $entry === 'block'
        ? (new SideBySideParser)->parse(['data' => $data], 'default', $assetImporter, new NullOutput, $attachments)
        : (new SideBySideParser)->parseModule($data, 'default', $assetImporter, new NullOutput, $attachments);
}

/**
 * Parses through the named entry point and returns the set, its id and each
 * link list row's id removed.
 *
 * @param  string  $entry  'block' or 'module'
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?AssetImporter  $assetImporter  The asset importer
 * @return array<int|string, mixed> The set without its ids
 */
function sbsSet(string $entry, array $data, ?AssetImporter $assetImporter = null): array
{
    $set = sbsRaw($entry, $data, $assetImporter);
    expect($set)->toBeArray()->not->toBe([]);
    $set = is_array($set) ? sbsWithoutId($set) : [];

    if (isset($set['link_list']) && is_array($set['link_list'])) {
        $set['link_list'] = array_map(
            static fn (mixed $row): array => is_array($row) ? sbsWithoutId($row) : [],
            $set['link_list']
        );
    }

    return $set;
}

/**
 * The warnings the parser logs while running a callback, with their contexts.
 *
 * @param  callable(): mixed  $callback  The code to run
 * @return list<array{string, array<int|string, mixed>}> Each warning's message and context, in order
 */
function sbsWarnings(callable $callback): array
{
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning' && str_starts_with($event->message, 'SideBySideParser::')) {
            $warnings[] = [$event->message, $event->context];
        }
    });

    $callback();

    return $warnings;
}

/**
 * The fewest fields a block or module needs to import.
 *
 * @return array<string, mixed> A heading, body text and an image id
 */
function sbsMinimal(): array
{
    return ['side_heading' => 'Heading', 'side_layout_body_text' => 'Body', 'side_image_side_image_image' => '7'];
}

/**
 * The set the minimal fields produce, every field at its default.
 *
 * @return array<string, mixed> The set without its id
 */
function sbsMinimalSet(): array
{
    return [
        'type' => 'uamsweb_block_image_side_by_side',
        'text_layout' => 'body-only',
        'block_heading' => 'Heading',
        'hide_block_heading' => false,
        'image_crop' => 'center',
        'image_anchor' => 'center',
        'image_position' => 'left',
        'background_color' => 'bg-auto',
        'enabled' => true,
        'body_body_only' => 'Body',
        'image' => 'assets::photo.jpg',
    ];
}

/**
 * A link list row as the set carries it, without its id.
 *
 * @param  string  $text  The link text
 * @param  string  $url  The link URL
 * @param  bool  $target  The row's target switch
 * @param  string  $description  The link description
 * @return array<string, mixed> The row
 */
function sbsRow(string $text, string $url, bool $target = false, string $description = ''): array
{
    return [
        'type' => 'new_set',
        'text' => $text,
        'url' => $url,
        'target' => $target,
        'description' => $description,
        'enabled' => true,
    ];
}

/**
 * An attachment item from the export.
 *
 * @param  string  $postId  The attachment's post id
 * @param  string  $url  The attachment's URL
 */
function sbsAttachment(string $postId, string $url): SimpleXMLElement
{
    return new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        ."<wp:post_id>{$postId}</wp:post_id><wp:attachment_url>{$url}</wp:attachment_url></item>"
    );
}

dataset('sbsEntries', ['block', 'module']);

it('maps the ACF field keys to the standard field names', function (): void {
    $parser = new SideBySideParser;

    expect(new ReflectionMethod(SideBySideParser::class, 'getFieldMappings')->invoke($parser))->toBe([
        'field_side_layout_b' => 'side_text_layout',
        'field_side_heading_b' => 'side_heading',
        'field_side_layout_body_text_b' => 'side_layout_body_text',
        'field_side_layout_link_text_b' => 'side_layout_link_text',
        'field_side_link_list_b' => 'side_link_list',
        'field_side_link_include_more_b' => 'side_link_include_more',
        'field_side_cta_text_b' => 'side_cta_side_cta_text',
        'field_side_cta_url_b' => 'side_cta_side_cta_url',
        'field_side_cta_description_b' => 'side_cta_side_cta_description',
        'field_side_image_image_b' => 'side_image_side_image_image',
        'field_side_image_alt_text_b' => 'side_image_side_image_alt_text',
        'field_side_image_crop_b' => 'side_image_side_image_crop',
        'field_side_image_anchor_b' => 'side_image_side_image_anchor',
        'field_side_image_position_b' => 'side_image_position',
        'field_side_image_background_color_b' => 'side_image_background_color',
        'field_side_quote_text_b' => 'side_quote_text',
        'field_side_quote_text_link_b' => 'side_quote_text_link',
        'field_side_quote_speaker_name_b' => 'side_quote_speaker_name',
        'field_side_quote_speaker_title_b' => 'side_quote_speaker_title',
    ])->and(new ReflectionMethod(SideBySideParser::class, 'getNestedStructures')->invoke($parser))->toBe([
        'field_side_cta_b',
        'field_side_image_b',
    ]);
});

it('reads a block stored under its ACF field keys, the button and image nested', function (): void {
    $set = sbsSet('block', [
        'field_side_layout_b' => 'body-only',
        'field_side_heading_b' => 'Keyed',
        'field_side_layout_body_text_b' => 'Keyed body',
        'field_side_image_position_b' => 'right',
        'field_side_image_background_color_b' => 'bg-white',
        'field_side_cta_b' => [
            'field_side_cta_text_b' => 'Go',
            'field_side_cta_url_b' => ['url' => 'https://example.com/go'],
            'field_side_cta_description_b' => 'Go there',
        ],
        'field_side_image_b' => [
            'field_side_image_image_b' => '7',
            'field_side_image_alt_text_b' => 'Alt',
            'field_side_image_crop_b' => 'top',
            'field_side_image_anchor_b' => 'left',
        ],
    ]);

    expect($set)->toBe([
        'type' => 'uamsweb_block_image_side_by_side',
        'text_layout' => 'body-only',
        'block_heading' => 'Keyed',
        'hide_block_heading' => false,
        'image_crop' => 'top',
        'image_anchor' => 'left',
        'image_position' => 'right',
        'background_color' => 'bg-white',
        'enabled' => true,
        'body_body_only' => 'Keyed body',
        'image' => 'assets::photo.jpg',
        'alt_text' => 'Alt',
        'button_text' => 'Go',
        'button_url' => 'https://example.com/go',
        'button_target' => false,
        'button_link_description' => 'Go there',
    ]);
});

it('reads block attributes with no data wrapper, or with a data key that is not an array', function (mixed $data, ?string $heading): void {
    $set = (new SideBySideParser)->parse(
        sbsMinimal() + ($data === null ? [] : ['data' => $data]),
        'default',
        new FakeAssetImporter(true, 'assets::photo.jpg'),
        new NullOutput
    );

    expect($set['block_heading'] ?? null)->toBe($heading);
})->with([
    'no data key' => [null, 'Heading'],
    'a string data key' => ['x', null],
]);

it('gives each field its default when the block leaves it out', function (string $entry): void {
    expect(sbsSet($entry, sbsMinimal()))->toBe(sbsMinimalSet());
})->with('sbsEntries');

it('imports a block with no heading as an empty one', function (): void {
    $data = sbsMinimal();
    unset($data['side_heading']);

    expect(sbsSet('block', $data)['block_heading'] ?? null)->toBe('');
});

it('maps the body-only layout, with every optional field filled', function (string $entry, bool $target): void {
    $set = sbsSet($entry, [
        'side_text_layout' => 'body-only',
        'side_heading' => 'Heading',
        'side_layout_body_text' => '<b>Body</b>',
        'side_layout_link_text' => 'Ignored lead-in',
        'side_cta_side_cta_text' => '<i>Go</i>',
        'side_cta_side_cta_url' => ['url' => 'https://example.com/go', 'target' => '_blank'],
        'side_cta_side_cta_description' => 'Go there',
        'side_image_side_image_image' => '7',
        'side_image_side_image_alt_text' => 'Alt',
        'side_image_side_image_crop' => 'bottom',
        'side_image_side_image_anchor' => 'right',
        'side_image_position' => 'right',
        'side_image_background_color' => 'bg-white',
    ]);

    expect($set)->toBe([
        'type' => 'uamsweb_block_image_side_by_side',
        'text_layout' => 'body-only',
        'block_heading' => 'Heading',
        'hide_block_heading' => false,
        'image_crop' => 'bottom',
        'image_anchor' => 'right',
        'image_position' => 'right',
        'background_color' => 'bg-white',
        'enabled' => true,
        'body_body_only' => 'Body',
        'image' => 'assets::photo.jpg',
        'alt_text' => 'Alt',
        'button_text' => 'Go',
        'button_url' => 'https://example.com/go',
        'button_target' => $target,
        'button_link_description' => 'Go there',
    ]);
})->with([
    'block' => ['block', true],
    'module' => ['module', false],
]);

it('maps the link-list layout from the row format a duplicated block stores', function (): void {
    ProcessingContext::setWordPressPageLink('https://src.test/page/');

    $warnings = sbsWarnings(function () use (&$set): void {
        $set = sbsSet('block', [
            'side_text_layout' => 'link-list',
            'side_heading' => 'Heading',
            'side_layout_body_text' => 'Ignored body',
            'side_layout_link_text' => '<b>Lead</b>',
            'side_link_include_more' => '1',
            'side_image_side_image_image' => '7',
            'side_link_list' => [
                'row-0' => [
                    'field_side_link_list_text_b' => '<b>A</b>',
                    'field_side_link_list_url_b' => ['url' => 'https://example.com/a', 'target' => '_self'],
                    'field_side_link_list_description_b' => 'Da',
                ],
                'other' => ['field_side_link_list_text_b' => 'Other', 'field_side_link_list_url_b' => ['url' => 'https://example.com/o']],
                5 => ['field_side_link_list_text_b' => 'Int', 'field_side_link_list_url_b' => ['url' => 'https://example.com/i']],
                'row-1' => 'not an array',
                'row-2' => ['field_side_link_list_url_b' => ['url' => 'https://example.com/b', 'target' => '_blank']],
                'row-3' => ['field_side_link_list_text_b' => 'No URL'],
            ],
        ]);
    });

    expect($set)->toBe([
        'type' => 'uamsweb_block_image_side_by_side',
        'text_layout' => 'link-list',
        'block_heading' => 'Heading',
        'hide_block_heading' => false,
        'image_crop' => 'center',
        'image_anchor' => 'center',
        'image_position' => 'left',
        'background_color' => 'bg-auto',
        'enabled' => true,
        'body_link_list' => 'Lead',
        'link_list' => [
            sbsRow('A', 'https://example.com/a', true, 'Da'),
            sbsRow('', 'https://example.com/b'),
        ],
        'include_more' => true,
        'image' => 'assets::photo.jpg',
    ])->and($warnings)->toBe([[
        'SideBySideParser::processLinkList: Skipping link with empty URL in side_link_list (from entry: https://src.test/page/)',
        ['link_text' => 'No URL', 'entry_url' => 'https://src.test/page/'],
    ]]);
});

it('reads the indexed link list a saved block stores, up to the first missing index', function (): void {
    $set = sbsSet('block', [
        'side_text_layout' => 'link-list',
        'side_heading' => 'Heading',
        'side_layout_link_text' => 'Lead',
        'side_image_side_image_image' => '7',
        'side_link_list' => '9',
        'side_link_list_0_side_link_list_text' => 'A',
        'side_link_list_0_side_link_list_url' => ['url' => 'https://example.com/a'],
        'side_link_list_0_side_link_list_description' => 'Da',
        'side_link_list_1_side_link_list_text' => 'B',
        'side_link_list_1_side_link_list_url' => 'https://example.com/b',
        'side_link_list_3_side_link_list_text' => 'Gap',
        'side_link_list_3_side_link_list_url' => ['url' => 'https://example.com/gap'],
    ]);

    expect($set['link_list'] ?? null)->toBe([
        sbsRow('A', 'https://example.com/a', false, 'Da'),
        sbsRow('B', 'https://example.com/b'),
    ])->and($set['include_more'] ?? null)->toBeFalse();
});

it('reads a link count with no indexed text as that many links', function (mixed $count): void {
    $warnings = sbsWarnings(function () use ($count, &$set): void {
        $set = sbsSet('block', [
            'side_text_layout' => 'link-list',
            'side_heading' => 'Heading',
            'side_layout_link_text' => 'Lead',
            'side_image_side_image_image' => '7',
            'side_link_list' => $count,
            'side_link_list_0_side_link_list_url' => ['url' => 'https://example.com/a'],
            'side_link_list_0_side_link_list_description' => 'Da',
            'side_link_list_1_side_link_list_text' => 'B',
            'side_link_list_2_side_link_list_url' => ['url' => 'https://example.com/c'],
            'side_link_list_3_side_link_list_url' => ['url' => 'https://example.com/d'],
        ]);
    });

    expect($set['link_list'] ?? null)->toBe([
        sbsRow('', 'https://example.com/a', false, 'Da'),
        sbsRow('', 'https://example.com/c'),
    ])->and($warnings)->toBe([[
        'SideBySideParser::processLinkList: Skipping link with empty URL in side_link_list',
        ['link_text' => 'B'],
    ]]);
})->with(['a string' => ['3'], 'an integer' => [3]]);

it('reads a decimal link count as its whole part', function (): void {
    $set = sbsSet('block', [
        'side_text_layout' => 'link-list',
        'side_heading' => 'Heading',
        'side_layout_link_text' => 'Lead',
        'side_image_side_image_image' => '7',
        'side_link_list' => '1.5',
        'side_link_list_0_side_link_list_url' => ['url' => 'https://example.com/a'],
        'side_link_list_1_side_link_list_url' => ['url' => 'https://example.com/b'],
    ]);

    expect($set['link_list'] ?? null)->toBe([sbsRow('', 'https://example.com/a')]);
});

it('reads a link list already stored as a list of links', function (): void {
    $warnings = sbsWarnings(function () use (&$set): void {
        $set = sbsSet('block', [
            'side_text_layout' => 'link-list',
            'side_heading' => 'Heading',
            'side_layout_link_text' => 'Lead',
            'side_image_side_image_image' => '7',
            'side_link_list' => [
                ['side_link_list_text' => 'A', 'side_link_list_url' => ['url' => 'https://example.com/a', 'target' => 'x']],
                'not a link',
                ['side_link_list_description' => 'Nothing else'],
                ['side_link_list_url' => 'https://example.com/n'],
            ],
        ]);
    });

    expect($set['link_list'] ?? null)->toBe([sbsRow('A', 'https://example.com/a', true), sbsRow('', 'https://example.com/n')])
        ->and($warnings)->toBe([[
            'SideBySideParser::processLinkList: Skipping link with empty URL in side_link_list',
            ['link_text' => 'unknown'],
        ]]);
});

it('imports no link list from a shape it does not recognize, or from no links', function (mixed $links): void {
    $set = sbsSet('block', [
        'side_text_layout' => 'link-list',
        'side_heading' => 'Heading',
        'side_layout_link_text' => 'Lead',
        'side_link_include_more' => '1',
        'side_image_side_image_image' => '7',
    ] + ($links === null ? [] : ['side_link_list' => $links]));

    expect(array_key_exists('link_list', $set))->toBeFalse()
        ->and(array_key_exists('include_more', $set))->toBeFalse()
        ->and($set['body_link_list'] ?? null)->toBe('Lead');
})->with([
    'no link list' => [null],
    'an empty list' => [[]],
    'a zero count' => ['0'],
    'a keyed map' => [['links' => ['side_link_list_text' => 'A', 'side_link_list_url' => 'https://example.com/a']]],
    'a word' => ['links'],
]);

it('reads only the string 1 as an include-more switch', function (mixed $value, bool $on): void {
    $set = sbsSet('block', [
        'side_text_layout' => 'link-list',
        'side_heading' => 'Heading',
        'side_layout_link_text' => 'Lead',
        'side_image_side_image_image' => '7',
        'side_link_list' => [['side_link_list_text' => 'A', 'side_link_list_url' => 'https://example.com/a']],
    ] + ($value === null ? [] : ['side_link_include_more' => $value]));

    expect($set['include_more'] ?? null)->toBe($on);
})->with([
    'the string 1' => ['1', true],
    'the string 0' => ['0', false],
    'the integer 1' => [1, false],
    'absent' => [null, false],
]);

it('keeps each layout to its own body text and link list', function (string $layout, array $expected): void {
    $set = sbsSet('block', [
        'side_text_layout' => $layout,
        'side_heading' => 'Heading',
        'side_layout_body_text' => 'Body',
        'side_layout_link_text' => 'Lead',
        'side_image_side_image_image' => '7',
        'side_link_list' => [['side_link_list_text' => 'A', 'side_link_list_url' => 'https://example.com/a']],
    ]);

    expect(array_intersect_key($set, array_flip(['body_body_only', 'body_link_list', 'link_list', 'include_more'])))
        ->toBe($expected);
})->with([
    'body-only' => ['body-only', ['body_body_only' => 'Body']],
    'link-list' => ['link-list', [
        'body_link_list' => 'Lead',
        'link_list' => [sbsRow('A', 'https://example.com/a')],
        'include_more' => false,
    ]],
    'another layout' => ['custom', []],
]);

it('leaves out a layout body the block does not carry', function (string $layout): void {
    $set = sbsSet('block', ['side_text_layout' => $layout, 'side_heading' => 'Heading', 'side_image_side_image_image' => '7']);

    expect(array_intersect_key($set, array_flip(['body_body_only', 'body_link_list'])))->toBe([]);
})->with(['body-only', 'link-list']);

it('leaves the button out without its text, whatever else it carries', function (string $entry, mixed $text): void {
    $set = sbsSet($entry, sbsMinimal() + [
        'side_cta_side_cta_text' => $text,
        'side_cta_side_cta_url' => ['url' => 'https://example.com/go', 'target' => '_blank'],
        'side_cta_side_cta_description' => 'Go there',
    ]);

    expect(array_intersect_key($set, array_flip(['button_text', 'button_url', 'button_target', 'button_link_description'])))
        ->toBe([]);
})->with('sbsEntries')->with(['empty' => [''], 'markup only' => ['<b></b>'], 'an array' => [['Go']]]);

it('imports a button with no link as one with an empty URL', function (string $entry): void {
    $set = sbsSet($entry, sbsMinimal() + ['side_cta_side_cta_text' => 'Go', 'side_cta_side_cta_description' => '']);

    expect(array_slice($set, -3))->toBe(['button_text' => 'Go', 'button_url' => '', 'button_target' => false]);
})->with('sbsEntries');

it('reads the block button link only from a link array', function (mixed $link, string $url, bool $target): void {
    $set = sbsSet('block', sbsMinimal() + ['side_cta_side_cta_text' => 'Go', 'side_cta_side_cta_url' => $link]);

    expect(array_slice($set, -3))->toBe(['button_text' => 'Go', 'button_url' => $url, 'button_target' => $target]);
})->with([
    'a URL string' => ['https://example.com/go', '', false],
    'a link with no URL' => [['target' => '_blank'], '', true],
    'a link opening in the same tab' => [['url' => 'https://example.com/go', 'target' => '_self'], 'https://example.com/go', false],
    'a link with no target' => [['url' => 'https://example.com/go'], 'https://example.com/go', false],
    'a link with a number for a URL' => [['url' => 5], '5', false],
]);

it('reads the module button link through LinkHelper', function (mixed $link, string $url, bool $target): void {
    $set = sbsSet('module', sbsMinimal() + ['side_cta_side_cta_text' => 'Go', 'side_cta_side_cta_url' => $link]);

    expect(array_slice($set, -3))->toBe(['button_text' => 'Go', 'button_url' => $url, 'button_target' => $target]);
})->with([
    'a URL string' => ['https://example.com/go', 'https://example.com/go', false],
    'a link opening in a new tab' => [['url' => 'https://example.com/go', 'target' => '_blank'], 'https://example.com/go', false],
    'a link opening in the same tab' => [['url' => 'https://example.com/go', 'target' => '_self'], 'https://example.com/go', true],
]);

it('drops the button under the quote layout that hides it', function (string $entry): void {
    $set = sbsSet($entry, [
        'side_text_layout' => 'blockquote-citation',
        'side_heading' => 'Heading',
        'side_quote_text' => 'Q',
        'side_quote_speaker_name' => 'N',
        'side_image_side_image_image' => '7',
        'side_cta_side_cta_text' => 'Go',
        'side_cta_side_cta_url' => ['url' => 'https://example.com/go'],
    ]);

    expect(array_key_exists('button_text', $set))->toBeFalse();
})->with('sbsEntries');

it('skips a block with no image while uploads are on, and warns', function (?string $imageId, ?string $imported): void {
    $data = sbsMinimal();
    unset($data['side_image_side_image_image']);
    $assetImporter = new FakeAssetImporter(true, $imported);

    $warnings = sbsWarnings(function () use ($data, $imageId, $assetImporter, &$set): void {
        $set = sbsRaw('block', $data + ($imageId === null ? [] : ['side_image_side_image_image' => $imageId]), $assetImporter);
    });

    expect($set)->toBe([])
        ->and($warnings)->toBe([[
            'SideBySideParser::parse: Skipping wp:acf/image-side block due to missing required image when uploads are enabled',
            ['site' => 'default', 'image_id' => $imageId ?? ''],
        ]])
        ->and(array_column($assetImporter->importedImages, 0))->toBe($imageId === null ? [] : [$imageId]);
})->with([
    'no image id' => [null, 'assets::photo.jpg'],
    'an import that finds nothing' => ['7', null],
    'an import that returns nothing' => ['7', ''],
]);

it('uses the export URL while uploads are off, and imports a block without one', function (array $attachments, ?string $image): void {
    $set = sbsRaw('block', sbsMinimal(), new FakeAssetImporter(false), $attachments);

    expect(is_array($set) && array_key_exists('image', $set))->toBeTrue()
        ->and($set['image'] ?? null)->toBe($image);
})->with([
    'an attachment in the export' => [[sbsAttachment('7', 'https://src.test/photo.jpg')], 'https://src.test/photo.jpg'],
    'no attachment' => [[], null],
]);

it('keeps the image options to the radio options, for a block as for a module', function (string $entry, mixed $crop, mixed $anchor, string $cropOption, string $anchorOption): void {
    // #199: the template's validity guard needs a truthy crop and anchor, and a value
    // outside the options renders no crop the stylesheet knows, so both entry points
    // keep the values to the fieldset's options
    $set = sbsSet($entry, sbsMinimal() + array_filter(
        ['side_image_side_image_crop' => $crop, 'side_image_side_image_anchor' => $anchor],
        static fn (mixed $value): bool => $value !== null
    ));

    expect([$set['image_crop'] ?? null, $set['image_anchor'] ?? null])->toBe([$cropOption, $anchorOption]);
})->with(['block', 'module'])->with([
    'top and left' => ['top', 'left', 'top', 'left'],
    'bottom and right' => ['bottom', 'right', 'bottom', 'right'],
    'center and center' => ['center', 'center', 'center', 'center'],
    'the old zero for none' => ['0', '0', 'center', 'center'],
    'not options' => ['none', 'middle', 'center', 'center'],
    'absent' => [null, null, 'center', 'center'],
]);

it('skips a module with no image, and warns', function (mixed $imageId, ?string $imported, string $logged): void {
    $data = sbsMinimal();
    unset($data['side_image_side_image_image']);
    ProcessingContext::setEntryData(['post_name' => 'my-post']);

    $warnings = sbsWarnings(function () use ($data, $imageId, $imported, &$set): void {
        $set = sbsRaw('module', $data + ($imageId === null ? [] : ['side_image_side_image_image' => $imageId]), new FakeAssetImporter(true, $imported));
    });

    expect($set)->toBeNull()
        ->and($warnings)->toBe([[
            'SideBySideParser::parseModule: Skipping modules template image_side due to missing required image',
            ['site' => 'default', 'post_name' => 'my-post', 'image_id' => $logged],
        ]]);
})->with([
    'no image id' => [null, 'assets::photo.jpg', 'none'],
    'an empty image id' => ['', 'assets::photo.jpg', ''],
    'an import that finds nothing' => ['7', null, '7'],
    'an import that returns nothing' => ['7', '', '7'],
]);

it('uses the export URL for a module while uploads are off', function (): void {
    $set = sbsRaw('module', sbsMinimal(), new FakeAssetImporter(false), [sbsAttachment('7', 'https://src.test/photo.jpg')]);

    expect($set['image'] ?? null)->toBe('https://src.test/photo.jpg');
});

it('skips a module without a heading, and warns with the post it belongs to', function (mixed $heading, ?array $entryData, string $postName): void {
    $data = sbsMinimal();
    unset($data['side_heading']);

    if ($entryData !== null) {
        ProcessingContext::setEntryData($entryData);
    }

    $warnings = sbsWarnings(function () use ($data, $heading, &$set): void {
        $set = sbsRaw('module', $data + ($heading === null ? [] : ['side_heading' => $heading]));
    });

    expect($set)->toBeNull()
        ->and($warnings)->toBe([[
            'SideBySideParser::parseModule: Skipping modules template image_side due to missing required heading',
            ['site' => 'default', 'post_name' => $postName],
        ]]);
})->with([
    'no heading and no entry' => [null, null, 'unknown'],
    'an empty heading in an entry without a name' => ['', ['post_title' => 'T'], 'unknown'],
    'a heading that is not text in a named entry' => [['Heading'], ['post_name' => 'my-post'], 'my-post'],
]);

it('reads a number as heading text', function (string $entry): void {
    expect(sbsSet($entry, ['side_heading' => 5] + sbsMinimal())['block_heading'] ?? null)->toBe('5');
})->with('sbsEntries');

it('skips a module whose layout lacks its required text, and warns', function (array $data, string $reason, string $layout): void {
    $warnings = sbsWarnings(function () use ($data, &$set): void {
        $set = sbsRaw('module', $data + ['side_heading' => 'Heading', 'side_image_side_image_image' => '7']);
    });

    expect($set)->toBeNull()
        ->and($warnings)->toBe([[
            "SideBySideParser::parseModule: Skipping modules template image_side due to missing required {$reason}",
            ['site' => 'default', 'post_name' => 'unknown', 'text_layout' => $layout],
        ]]);
})->with([
    'body-only with no body' => [['side_text_layout' => 'body-only', 'side_layout_link_text' => 'Lead'], 'body text for body-only layout', 'body-only'],
    'no layout and no body' => [[], 'body text for body-only layout', 'body-only'],
    'body-only with empty body' => [['side_layout_body_text' => '<p></p>'], 'body text for body-only layout', 'body-only'],
    'link-list with no lead-in' => [['side_text_layout' => 'link-list', 'side_layout_body_text' => 'Body', 'side_link_list' => '1'], 'link text for link-list layout', 'link-list'],
    'link-list with no links' => [['side_text_layout' => 'link-list', 'side_layout_link_text' => 'Lead'], 'link list for link-list layout', 'link-list'],
    'link-list with a zero count' => [['side_text_layout' => 'link-list', 'side_layout_link_text' => 'Lead', 'side_link_list' => '0'], 'link list for link-list layout', 'link-list'],
    'link-list with a word for a count' => [['side_text_layout' => 'link-list', 'side_layout_link_text' => 'Lead', 'side_link_list' => 'two'], 'link list for link-list layout', 'link-list'],
    'link-list with a float count' => [['side_text_layout' => 'link-list', 'side_layout_link_text' => 'Lead', 'side_link_list' => 2.0], 'link list for link-list layout', 'link-list'],
    'a quote with no speaker' => [['side_text_layout' => 'blockquote-citation', 'side_quote_text' => 'Q'], 'quote or speaker name for a quote layout', 'blockquote-citation'],
]);

it('maps the module link-list layout from its indexed rows', function (mixed $count): void {
    $warnings = sbsWarnings(function () use ($count, &$set): void {
        $set = sbsSet('module', [
            'side_text_layout' => 'link-list',
            'side_heading' => 'Heading',
            'side_layout_body_text' => 'Ignored body',
            'side_layout_link_text' => '<b>Lead</b>',
            'side_link_include_more' => '1',
            'side_image_side_image_image' => '7',
            'side_link_list' => $count,
            'side_link_list_0_side_link_list_text' => '<b>A</b>',
            'side_link_list_0_side_link_list_url' => ['url' => 'https://example.com/a', 'target' => '_self'],
            'side_link_list_0_side_link_list_description' => 'Da',
            'side_link_list_1_side_link_list_url' => ['url' => 'https://example.com/b'],
            'side_link_list_2_side_link_list_text' => 'Beyond the count',
            'side_link_list_2_side_link_list_url' => ['url' => 'https://example.com/c'],
        ]);
    });

    expect($set)->toBe([
        'type' => 'uamsweb_block_image_side_by_side',
        'text_layout' => 'link-list',
        'block_heading' => 'Heading',
        'hide_block_heading' => false,
        'image_crop' => 'center',
        'image_anchor' => 'center',
        'image_position' => 'left',
        'background_color' => 'bg-auto',
        'enabled' => true,
        'body_link_list' => 'Lead',
        'link_list' => [
            sbsRow('A', 'https://example.com/a', true, 'Da'),
            sbsRow('', 'https://example.com/b'),
        ],
        'include_more' => true,
        'image' => 'assets::photo.jpg',
    ])->and($warnings)->toBe([]);
})->with(['a string count' => ['2'], 'an integer count' => [2]]);

it('skips a module link with no URL, and warns', function (): void {
    $warnings = sbsWarnings(function () use (&$set): void {
        $set = sbsSet('module', [
            'side_text_layout' => 'link-list',
            'side_heading' => 'Heading',
            'side_layout_link_text' => 'Lead',
            'side_image_side_image_image' => '7',
            'side_link_list' => '2',
            'side_link_list_0_side_link_list_text' => 'A',
            'side_link_list_1_side_link_list_text' => 'B',
            'side_link_list_1_side_link_list_url' => ['url' => 'https://example.com/b'],
        ]);
    });

    expect($set['link_list'] ?? null)->toBe([sbsRow('B', 'https://example.com/b')])
        ->and($warnings)->toBe([[
            'SideBySideParser::processLinkList: Skipping link with empty URL in side_link_list',
            ['link_text' => 'A'],
        ]]);
});

it('reads no link list outside the link-list layout, for a block as for a module', function (string $entry): void {
    $assetImporter = new FakeAssetImporter(true, 'assets::photo.jpg');

    // #199: the fieldset and the template show a link list only for the link-list
    // layout, so its rows under another layout are neither written nor run through
    // LinkProcessor, which would import the file they link to
    $set = sbsSet($entry, sbsMinimal() + [
        'side_link_list' => '1',
        'side_link_list_0_side_link_list_text' => 'A',
        'side_link_list_0_side_link_list_url' => 'https://example.com/wp-content/uploads/2020/01/a.pdf',
        'side_link_include_more' => '1',
    ], $assetImporter);

    expect(array_intersect_key($set, ['link_list' => true, 'include_more' => true, 'body_link_list' => true]))->toBe([])
        ->and($assetImporter->importedFiles)->toBe([]);
})->with(['block', 'module']);

it('runs the link rows of the link-list layout through LinkProcessor, for a block as for a module', function (string $entry): void {
    $assetImporter = new FakeAssetImporter(true, 'assets::photo.jpg');

    // The control for the test above: the same row under the link-list layout imports
    // the file it links to
    sbsSet($entry, sbsMinimal() + [
        'side_text_layout' => 'link-list',
        'side_layout_link_text' => 'Lead',
        'side_link_list' => '1',
        'side_link_list_0_side_link_list_text' => 'A',
        'side_link_list_0_side_link_list_url' => 'https://example.com/wp-content/uploads/2020/01/a.pdf',
    ], $assetImporter);

    expect(array_column($assetImporter->importedFiles, 0))->toBe(['https://example.com/wp-content/uploads/2020/01/a.pdf']);
})->with(['block', 'module']);

it('imports a module of another layout without a layout body', function (): void {
    $warnings = sbsWarnings(function () use (&$set): void {
        $set = sbsSet('module', ['side_text_layout' => 'custom'] + sbsMinimal());
    });

    $expected = sbsMinimalSet();
    unset($expected['body_body_only']);

    expect($set)->toBe(array_replace($expected, ['text_layout' => 'custom']))
        ->and($warnings)->toBe([]);
});

it('maps a complete quote layout, leaving out an absent speaker title', function (string $entry, string $layout, string $source, string $handle, string $statamic): void {
    $set = sbsSet($entry, [
        'side_text_layout' => $layout,
        'side_heading' => 'Heading',
        'side_quote_text' => 'Plain quote',
        'side_quote_text_link' => 'Linked quote',
        'side_quote_speaker_name' => '<b>Name</b>',
        'side_image_side_image_image' => '7',
    ]);

    expect($set)->toBe([
        'type' => 'uamsweb_block_image_side_by_side',
        'text_layout' => $statamic,
        'block_heading' => 'Heading',
        'hide_block_heading' => false,
        'image_crop' => 'center',
        'image_anchor' => 'center',
        'image_position' => 'left',
        'background_color' => 'bg-auto',
        'enabled' => true,
        $handle => $source,
        'speaker_name' => 'Name',
        'image' => 'assets::photo.jpg',
    ])->and(ImportWarningManager::getWarnings('content', 'side_by_side_incomplete_quote'))->toBe([]);
})->with('sbsEntries')->with([
    'the plain quote' => ['blockquote-citation', 'Plain quote', 'quote_text', 'blockquote_with_citation'],
    'the quote with a link' => ['blockquote-citation-link', 'Linked quote', 'quote_text_with_link', 'blockquote_with_citation_and_link'],
]);

it('reports a quote block missing its quote or speaker name', function (string $entry, string $layout, array $data, array $missing, ?string $pageUrl): void {
    if ($pageUrl !== null) {
        ProcessingContext::setWordPressPageLink($pageUrl);
    }

    $set = sbsRaw($entry, $data + ['side_text_layout' => $layout, 'side_heading' => 'Heading', 'side_image_side_image_image' => '7']);

    expect($entry === 'block' ? is_array($set) && $set !== [] : $set === null)->toBeTrue()
        ->and(ImportWarningManager::getWarnings('content', 'side_by_side_incomplete_quote'))->toBe([[
            'block_type' => 'uamsweb_block_image_side_by_side',
            'page_url' => $pageUrl ?? '',
            'heading' => 'Heading',
            'text_layout' => $layout,
            'missing' => $missing,
            'reason' => $entry === 'block'
                ? 'The block needs a quote and a speaker name to render, so it imports blank until an editor fills them in.'
                : 'The block needs a quote and a speaker name to render, so it was skipped.',
        ]]);
})->with('sbsEntries')->with([
    'no speaker name' => ['blockquote-citation', ['side_quote_text' => 'Q'], ['speaker_name'], 'https://src.test/page/'],
    'nothing at all' => ['blockquote-citation', ['side_quote_speaker_title' => 'Title'], ['quote_text', 'speaker_name'], null],
    'the linked quote' => ['blockquote-citation-link', ['side_quote_text' => 'Q', 'side_quote_speaker_name' => 'N'], ['quote_text_with_link'], null],
]);

it('reports no quote for a layout that is not a quote', function (string $entry, string $layout): void {
    $data = [
        'side_text_layout' => $layout,
        'side_layout_link_text' => 'Lead',
        'side_link_list' => [['side_link_list_text' => 'A', 'side_link_list_url' => 'https://example.com/a']],
        'side_link_list_0_side_link_list_text' => 'A',
        'side_link_list_0_side_link_list_url' => 'https://example.com/a',
    ] + sbsMinimal();

    if ($entry === 'module') {
        $data['side_link_list'] = '1';
    }

    $warnings = sbsWarnings(function () use ($entry, $data, &$set): void {
        $set = sbsRaw($entry, $data);
    });

    expect($set)->toBeArray()->not->toBe([])
        ->and(ImportWarningManager::getWarnings('content', 'side_by_side_incomplete_quote'))->toBe([])
        ->and($warnings)->toBe([]);
})->with('sbsEntries')->with(['body-only', 'link-list', 'custom']);
