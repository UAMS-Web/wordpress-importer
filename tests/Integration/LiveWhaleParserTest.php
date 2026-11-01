<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\LiveWhaleParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that `LiveWhaleParser` maps an ACF LiveWhale Calendar block, and a
 * modules-template `uams_livewhale` module, onto the LiveWhale replicator set,
 * and skips either with a warning when the heading or the widget id is missing.
 *
 * Both entry points share one mapping, so each mapping case runs through both.
 *
 * @command  php vendor/bin/pest tests/Integration/LiveWhaleParserTest.php
 */
uses(StatamicTestCase::class);

// The skip warnings read the page link from a process-static
beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Parse data through one of the two entry points.
 *
 * `parse()` reads the block's `data` key, so the data is wrapped for it;
 * `parseModule()` reads the module data directly.
 *
 * @param  string  $entry  `block` for `parse()`, `module` for `parseModule()`
 * @param  array<int|string, mixed>  $data  The block or module data
 * @return array<int|string, mixed>|null The replicator set, or null when skipped
 */
function liveWhaleParse(string $entry, array $data): ?array
{
    $assetImporter = new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor();
    $parser = new LiveWhaleParser;

    if ($entry === 'block') {
        $set = $parser->parse(['data' => $data], 'default', $assetImporter, new NullOutput);

        // parse() signals a skip with an empty array
        return $set === [] ? null : $set;
    }

    return $parser->parseModule($data, 'default', $assetImporter, new NullOutput);
}

/**
 * Parse data and drop the generated row id, after checking its shape.
 *
 * @param  string  $entry  `block` for `parse()`, `module` for `parseModule()`
 * @param  array<int|string, mixed>  $data  The block or module data
 * @return array<int|string, mixed> The replicator set without its id
 */
function liveWhaleSet(string $entry, array $data): array
{
    $set = liveWhaleParse($entry, $data);

    expect($set)->toBeArray()
        ->and($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');

    \assert(\is_array($set));
    unset($set['id']);

    return $set;
}

dataset('liveWhaleEntries', ['block', 'module']);

it('maps a complete block onto the full set', function (string $entry): void {
    expect(liveWhaleSet($entry, [
        'livewhale_heading' => 'Upcoming <em>Events</em>',
        'livewhale_id' => '42',
        'livewhale_background_color' => 'bg-gray',
        'livewhale_description' => "  Talks &amp; tours\n ",
    ]))->toBe([
        'type' => 'uamsweb_block_livewhale',
        'block_heading' => 'Upcoming Events',
        'hide_block_heading' => false,
        'livewhale_id' => 42,
        'background_color' => 'bg-gray',
        'enabled' => true,
        'block_description' => 'Talks & tours',
    ]);
})->with('liveWhaleEntries');

it('reads the field-keyed ACF names', function (string $entry): void {
    expect(liveWhaleSet($entry, [
        'field_livewhale_heading_b' => 'Calendar',
        'field_livewhale_id_b' => 7,
        'field_livewhale_background_color_b' => 'bg-white',
        'field_livewhale_description_b' => 'About',
    ]))->toBe([
        'type' => 'uamsweb_block_livewhale',
        'block_heading' => 'Calendar',
        'hide_block_heading' => false,
        'livewhale_id' => 7,
        'background_color' => 'bg-white',
        'enabled' => true,
        'block_description' => 'About',
    ]);
})->with('liveWhaleEntries');

it('defaults the background and omits a missing description', function (string $entry): void {
    expect(liveWhaleSet($entry, ['livewhale_heading' => 'Calendar', 'livewhale_id' => 1]))->toBe([
        'type' => 'uamsweb_block_livewhale',
        'block_heading' => 'Calendar',
        'hide_block_heading' => false,
        'livewhale_id' => 1,
        'background_color' => 'bg-auto',
        'enabled' => true,
    ]);
})->with('liveWhaleEntries');

it('omits a description that is blank or not a string', function (string $entry, mixed $description): void {
    expect(liveWhaleSet($entry, [
        'livewhale_heading' => 'Calendar',
        'livewhale_id' => 1,
        'livewhale_description' => $description,
    ]))->not->toHaveKey('block_description');
})->with('liveWhaleEntries')->with([
    'an empty string' => [''],
    'only whitespace' => [" \n\t "],
    'an array' => [['About']],
    'an integer' => [5],
]);

it('reads a numeric widget id as an integer', function (string $entry, mixed $widgetId, int $expected): void {
    expect(liveWhaleSet($entry, ['livewhale_heading' => 'Calendar', 'livewhale_id' => $widgetId])['livewhale_id'])
        ->toBe($expected);
})->with('liveWhaleEntries')->with([
    'an integer' => [3, 3],
    'a numeric string' => ['12', 12],
    'a decimal string' => ['2.7', 2],
    'a float' => [1.9, 1],
]);

it('skips with a warning when the heading or widget id is missing', function (string $entry, array $data, string $heading, int $widgetId): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(liveWhaleParse($entry, $data))->toBeNull();

    $logSpy->shouldHaveReceived('warning', [
        $entry === 'block'
            ? 'LiveWhaleParser::parse: Skipping wp:acf/livewhale-calendar block due to missing required fields'
            : 'LiveWhaleParser::parseModule: Skipping modules template uams_livewhale due to missing required fields',
        ['site' => 'default', 'heading' => $heading, 'widget_id' => $widgetId],
    ]);
})->with('liveWhaleEntries')->with([
    'no fields' => [[], '', 0],
    'a heading of only markup' => [['livewhale_heading' => '<p></p>', 'livewhale_id' => 4], '', 4],
    'a heading that is not a string' => [['livewhale_heading' => ['Calendar'], 'livewhale_id' => 4], '', 4],
    'a widget id of zero' => [['livewhale_heading' => 'Calendar', 'livewhale_id' => '0'], 'Calendar', 0],
    'a negative widget id' => [['livewhale_heading' => 'Calendar', 'livewhale_id' => -2], 'Calendar', -2],
    'a widget id that is not numeric' => [['livewhale_heading' => 'Calendar', 'livewhale_id' => '4a'], 'Calendar', 0],
    'a widget id below one' => [['livewhale_heading' => 'Calendar', 'livewhale_id' => '0.9'], 'Calendar', 0],
]);

it('names the entry in a skip warning when the page link is known', function (string $entry, string $message): void {
    ProcessingContext::setWordPressPageLink('https://example.org/events/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(liveWhaleParse($entry, ['livewhale_id' => 4]))->toBeNull();

    $logSpy->shouldHaveReceived('warning', [
        $message.' (from entry: https://example.org/events/)',
        ['site' => 'default', 'heading' => '', 'widget_id' => 4, 'entry_url' => 'https://example.org/events/'],
    ]);
})->with([
    'block' => ['block', 'LiveWhaleParser::parse: Skipping wp:acf/livewhale-calendar block due to missing required fields'],
    'module' => ['module', 'LiveWhaleParser::parseModule: Skipping modules template uams_livewhale due to missing required fields'],
]);

it('logs nothing for a valid block', function (string $entry): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    liveWhaleSet($entry, ['livewhale_heading' => 'Calendar', 'livewhale_id' => 1]);

    $logSpy->shouldNotHaveReceived('warning');
})->with('liveWhaleEntries');

it('reads block attributes without a data key', function (): void {
    $assetImporter = new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor();

    $set = new LiveWhaleParser()->parse(
        ['livewhale_heading' => 'Calendar', 'livewhale_id' => 9],
        'default',
        $assetImporter,
        new NullOutput
    );

    expect($set['block_heading'] ?? null)->toBe('Calendar')
        ->and($set['livewhale_id'] ?? null)->toBe(9);
});

it('skips a block whose data key is not an array', function (): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();
    $assetImporter = new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor();

    expect(new LiveWhaleParser()->parse(
        ['data' => 'Calendar', 'livewhale_heading' => 'Calendar', 'livewhale_id' => 9],
        'default',
        $assetImporter,
        new NullOutput
    ))->toBe([]);

    $logSpy->shouldHaveReceived('warning', [
        'LiveWhaleParser::parse: Skipping wp:acf/livewhale-calendar block due to missing required fields',
        ['site' => 'default', 'heading' => '', 'widget_id' => 0],
    ]);
});

it('gives each set its own row id', function (string $entry): void {
    $data = ['livewhale_heading' => 'Calendar', 'livewhale_id' => 1];

    expect(liveWhaleParse($entry, $data)['id'] ?? null)->not->toBe(liveWhaleParse($entry, $data)['id'] ?? null);
})->with('liveWhaleEntries');
