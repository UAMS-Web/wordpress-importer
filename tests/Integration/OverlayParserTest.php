<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\OverlayParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\FakeAssetImporter;

/**
 * Tests that `OverlayParser` maps an ACF Text Overlay block, and a
 * modules-template `text_overlay` module, onto the overlay replicator set: one
 * item per counted section, skipping a section with no text and no link, and
 * the whole set when no section survives.
 *
 * Both entry points share one mapping, so each case runs through both.
 *
 * @command  php vendor/bin/pest tests/Integration/OverlayParserTest.php
 */
uses(StatamicTestCase::class);

// The warnings read the page link from a process-static
beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Parse data through one of the two entry points.
 *
 * @param  string  $entry  `block` for `parse()`, `module` for `parseModule()`
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?FakeAssetImporter  $assetImporter  The importer, or one with uploads on that imports nothing
 * @return array<int|string, mixed>|null The replicator set, `[]` or null when skipped
 */
function overlayParse(string $entry, array $data, ?FakeAssetImporter $assetImporter = null): ?array
{
    $assetImporter ??= new FakeAssetImporter;
    $parser = new OverlayParser;

    return $entry === 'block'
        ? $parser->parse(['data' => $data], 'default', $assetImporter, new NullOutput)
        : $parser->parseModule($data, 'default', $assetImporter, new NullOutput);
}

/**
 * The items of a parsed set, with their row ids checked and dropped.
 *
 * @param  string  $entry  `block` or `module`
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?FakeAssetImporter  $assetImporter  The importer, or one with uploads on that imports nothing
 * @return list<array<int|string, mixed>> The items without their ids
 */
function overlayItems(string $entry, array $data, ?FakeAssetImporter $assetImporter = null): array
{
    $set = overlayParse($entry, $data, $assetImporter);

    expect($set)->toBeArray()
        ->and($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/')
        ->and($set['type'] ?? null)->toBe('uamsweb_block_overlay')
        ->and($set['enabled'] ?? null)->toBeTrue()
        ->and(array_keys(is_array($set) ? $set : []))->toBe(['id', 'type', 'items', 'enabled']);

    $items = [];

    foreach (is_array($set) && is_array($set['items'] ?? null) ? $set['items'] : [] as $item) {
        $item = is_array($item) ? $item : [];

        expect($item['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');
        unset($item['id']);

        $items[] = $item;
    }

    return $items;
}

/**
 * The fields of one overlay section, keyed for the block data.
 *
 * @param  int  $index  The section index
 * @param  array<int|string, mixed>  $fields  Field suffix => value
 * @return array<string, mixed> The prefixed fields
 */
function overlaySection(int $index, array $fields): array
{
    $prefixed = [];

    foreach ($fields as $name => $value) {
        $prefixed["overlay_section_{$index}_overlay_section_{$name}"] = $value;
    }

    return $prefixed;
}

dataset('overlayEntries', ['block', 'module']);

it('maps each counted section onto an item, importing no image for a section without one', function (string $entry): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();
    $assetImporter = new FakeAssetImporter;

    $items = overlayItems($entry, [
        'overlay_section' => '2',
        ...overlaySection(0, [
            'heading' => 'Our <b>care</b>',
            'body' => '<p>Close to home.</p>',
            'button_text' => '<span>Find us</span>',
            'button_url' => ['url' => 'https://example.com/find', 'target' => '_self'],
            'button_description' => 'Find a <i>clinic</i>',
            'background_color' => 'bg-red',
        ]),
        ...overlaySection(1, [
            'heading' => 'Second',
        ]),
        ...overlaySection(2, [
            'heading' => 'Not counted',
        ]),
    ], $assetImporter);

    expect($assetImporter->importedImages)->toBe([]);

    // Uploads are on, so an attempted import of no image would warn
    $logSpy->shouldNotHaveReceived('warning');

    expect($items)->toBe([
        [
            'type' => 'new_set',
            'heading' => 'Our care',
            'body' => 'Close to home.',
            'button_text' => 'Find us',
            'button_url' => 'https://example.com/find',
            'button_target' => true,
            'background_color' => 'bg-red',
            'background_image' => null,
            'enabled' => true,
            'button_link_description' => 'Find a <i>clinic</i>',
        ],
        [
            'type' => 'new_set',
            'heading' => 'Second',
            'body' => '',
            'button_text' => '',
            'button_url' => '',
            'button_target' => false,
            'background_color' => 'bg-black',
            'background_image' => null,
            'enabled' => true,
        ],
    ]);
})->with('overlayEntries');

it('keeps a section that has only a body, only button text, or only a link', function (string $entry, array $fields, string $key, string $value): void {
    $items = overlayItems($entry, ['overlay_section' => 1, ...overlaySection(0, $fields)]);

    expect($items)->toHaveCount(1)
        ->and($items[0][$key] ?? null)->toBe($value);
})->with('overlayEntries')->with([
    'a body' => [['body' => 'Text'], 'body', 'Text'],
    'button text' => [['button_text' => 'Go'], 'button_text', 'Go'],
    'a link' => [['button_url' => 'https://example.com/'], 'button_url', 'https://example.com/'],
]);

it('reads the section count as an integer or a numeric string, and nothing else', function (string $entry, mixed $count, int $expected): void {
    $data = ['overlay_section' => $count];

    for ($i = 0; $i < 3; $i++) {
        $data += overlaySection($i, ['heading' => "Section $i"]);
    }

    $set = overlayParse($entry, $data);

    if ($expected === 0) {
        expect($set)->toBe($entry === 'block' ? [] : null);

        return;
    }

    expect(array_column(overlayItems($entry, $data), 'heading'))
        ->toBe(array_slice(['Section 0', 'Section 1', 'Section 2'], 0, $expected));
})->with('overlayEntries')->with([
    'an integer' => [3, 3],
    'a numeric string' => ['2', 2],
    'a numeric string with a fraction' => ['1.9', 1],
    'a non-numeric string' => ['two', 0],
    'a float' => [2.0, 0],
    'missing' => [null, 0],
]);

it('falls back for section fields that are not strings', function (string $entry): void {
    $items = overlayItems($entry, [
        'overlay_section' => 1,
        ...overlaySection(0, [
            'heading' => 'Kept',
            'body' => ['Body'],
            'button_text' => 5,
            'button_description' => ['Description'],
            'background_color' => ['bg-red'],
            'image' => 55,
        ]),
    ]);

    expect($items[0]['body'] ?? null)->toBe('')
        ->and($items[0]['button_text'] ?? null)->toBe('')
        ->and($items[0]['background_color'] ?? null)->toBe('bg-black')
        ->and(array_key_exists('background_image', $items[0] ?? []))->toBeTrue()
        ->and($items[0]['background_image'] ?? null)->toBeNull()
        ->and($items[0])->not->toHaveKey('button_link_description');
})->with('overlayEntries');

it('imports a section image', function (string $entry): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();
    $assetImporter = new FakeAssetImporter(true, 'images/overlay.jpg');

    $items = overlayItems($entry, [
        'overlay_section' => 1,
        ...overlaySection(0, ['heading' => 'Pictured', 'image' => '55']),
    ], $assetImporter);

    expect($items[0]['background_image'] ?? null)->toBe('images/overlay.jpg')
        ->and($assetImporter->importedImages)->toBe([['55', 'default']]);

    $logSpy->shouldNotHaveReceived('warning');
})->with('overlayEntries');

it('warns when a section image fails to import while uploads are enabled', function (string $entry, ?string $imported): void {
    ProcessingContext::setWordPressPageLink('https://example.org/landing/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $items = overlayItems($entry, [
        'overlay_section' => 1,
        ...overlaySection(0, ['heading' => 'Pictured', 'image' => '55']),
    ], new FakeAssetImporter(true, $imported));

    expect(array_key_exists('background_image', $items[0] ?? []))->toBeTrue()
        ->and($items[0]['background_image'] ?? null)->toBe($imported);

    $logSpy->shouldHaveReceived('warning', [
        'OverlayParser::processItems: Failed to import overlay section image when uploads are enabled (from entry: https://example.org/landing/)',
        ['site' => 'default', 'image_id' => '55', 'entry_url' => 'https://example.org/landing/'],
    ]);
})->with('overlayEntries')->with([
    'nothing imported' => [null],
    'an empty path' => [''],
]);

it('stays quiet about a missing image while uploads are disabled', function (string $entry): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();
    $assetImporter = new FakeAssetImporter(false);

    $items = overlayItems($entry, [
        'overlay_section' => 1,
        ...overlaySection(0, ['heading' => 'Pictured', 'image' => '55']),
    ], $assetImporter);

    expect(array_key_exists('background_image', $items[0] ?? []))->toBeTrue()
        ->and($items[0]['background_image'] ?? null)->toBeNull()
        ->and($assetImporter->importedImages)->toBe([]);

    $logSpy->shouldNotHaveReceived('warning', function (string $message): bool {
        return str_starts_with($message, 'OverlayParser::');
    });
})->with('overlayEntries');

it('skips a section with no text and no link, with a warning', function (string $entry, ?string $imported, bool $hasImage): void {
    ProcessingContext::setWordPressPageLink('https://example.org/landing/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $items = overlayItems($entry, [
        'overlay_section' => 2,
        ...overlaySection(0, ['heading' => '<span></span>', 'image' => '55', 'button_description' => 'Only a description']),
        ...overlaySection(1, ['heading' => 'Kept']),
    ], new FakeAssetImporter(true, $imported));

    expect(array_column($items, 'heading'))->toBe(['Kept']);

    $logSpy->shouldHaveReceived('warning', [
        'OverlayParser::processItems: Skipping invalid overlay_section item (from entry: https://example.org/landing/)',
        [
            'heading' => '',
            'has_body' => false,
            'has_button_text' => false,
            'has_button_url' => false,
            'has_image' => $hasImage,
            'entry_url' => 'https://example.org/landing/',
        ],
    ]);
})->with('overlayEntries')->with([
    'with an image' => ['images/overlay.jpg', true],
    'with an empty image path' => ['', false],
    'with no image' => [null, false],
]);

it('skips the whole set, with a warning, when no section survives', function (string $entry, array $data): void {
    ProcessingContext::setWordPressPageLink('https://example.org/landing/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(overlayParse($entry, $data))->toBe($entry === 'block' ? [] : null);

    $logSpy->shouldHaveReceived('warning', [
        $entry === 'block'
            ? 'OverlayParser::parse: No valid items for wp:acf/text-overlay block; skipping (from entry: https://example.org/landing/)'
            : 'OverlayParser::parseModule: No valid items for modules template text_overlay; skipping (from entry: https://example.org/landing/)',
        ['site' => 'default', 'entry_url' => 'https://example.org/landing/'],
    ]);
})->with('overlayEntries')->with([
    'no sections' => [[]],
    'only empty sections' => [['overlay_section' => 1]],
]);

it('reads block attributes without a data key, and a data key that is not an array as no data key', function (): void {
    $parser = new OverlayParser;
    $section = ['overlay_section' => 1, ...overlaySection(0, ['heading' => 'Bare'])];

    $bare = $parser->parse($section, 'default', new FakeAssetImporter, new NullOutput);
    $scalar = $parser->parse(['data' => 'x', ...$section], 'default', new FakeAssetImporter, new NullOutput);

    $heading = fn (array $set): mixed => is_array($set['items'] ?? null) && is_array($set['items'][0] ?? null)
        ? ($set['items'][0]['heading'] ?? null)
        : null;

    expect($heading($bare))->toBe('Bare')
        ->and($heading($scalar))->toBe('Bare');
});

it('gives each set and each item its own row id', function (string $entry): void {
    $data = ['overlay_section' => 2, ...overlaySection(0, ['heading' => 'A']), ...overlaySection(1, ['heading' => 'B'])];

    $first = overlayParse($entry, $data);
    $second = overlayParse($entry, $data);
    $ids = [];

    foreach (is_array($first) && is_array($first['items'] ?? null) ? $first['items'] : [] as $item) {
        $ids[] = is_array($item) && is_string($item['id'] ?? null) ? $item['id'] : '';
    }

    expect($first['id'] ?? null)->not->toBe($second['id'] ?? null)
        ->and(array_unique($ids))->toHaveCount(2);
})->with('overlayEntries');
