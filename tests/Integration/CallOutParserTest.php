<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\CallOutParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\FakeAssetImporter;

/**
 * Tests that `CallOutParser` maps an ACF Call Out block, and a modules-template
 * `uams_call_out` module, onto the call-out replicator set, including the
 * background image toggle and the warning for an image that fails to import
 * while uploads are enabled.
 *
 * Both entry points share one mapping, so each case runs through both.
 *
 * @command  php vendor/bin/pest tests/Integration/CallOutParserTest.php
 */
uses(StatamicTestCase::class);

// The image warning reads the page link from a process-static
beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

/**
 * An `AssetImporter` whose upload setting and image import result are fixed.
 *
 * @param  bool  $uploadImages  Whether image uploads are enabled
 * @param  ?string  $imported  What `importImage()` returns when called
 * @return FakeAssetImporter The fake importer
 */
function callOutAssetImporter(bool $uploadImages = true, ?string $imported = null): FakeAssetImporter
{
    return new FakeAssetImporter($uploadImages, $imported);
}

/**
 * Parse data through one of the two entry points.
 *
 * @param  string  $entry  `block` for `parse()`, `module` for `parseModule()`
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?AssetImporter  $assetImporter  The importer, or one with uploads on that imports nothing
 * @return array<int|string, mixed> The replicator set without its id
 */
function callOutSet(string $entry, array $data, ?AssetImporter $assetImporter = null): array
{
    $assetImporter ??= callOutAssetImporter();
    $parser = new CallOutParser;

    $set = $entry === 'block'
        ? $parser->parse(['data' => $data], 'default', $assetImporter, new NullOutput)
        : $parser->parseModule($data, 'default', $assetImporter, new NullOutput);

    expect($set)->toBeArray()
        ->and($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');

    \assert(\is_array($set));
    unset($set['id']);

    return $set;
}

dataset('callOutEntries', ['block', 'module']);

it('fills every default when the block sets nothing', function (string $entry): void {
    expect(callOutSet($entry, []))->toBe([
        'type' => 'uamsweb_block_call_out',
        'block_heading' => '',
        'hide_block_heading' => false,
        'body' => '',
        'background_color' => 'bg-auto',
        'background_image_query' => false,
        'enabled' => true,
    ]);
})->with('callOutEntries');

it('maps the heading, body and background color, stripping markup', function (string $entry): void {
    expect(callOutSet($entry, [
        'call_out_heading' => 'Get <strong>care</strong> &amp; more',
        'call_out_body' => '<p>Call us today.</p>',
        'call_out_background_color' => 'bg-red',
    ]))->toBe([
        'type' => 'uamsweb_block_call_out',
        'block_heading' => 'Get care & more',
        'hide_block_heading' => false,
        'body' => 'Call us today.',
        'background_color' => 'bg-red',
        'background_image_query' => false,
        'enabled' => true,
    ]);
})->with('callOutEntries');

it('falls back for text and color fields that are not strings', function (string $entry): void {
    $set = callOutSet($entry, [
        'call_out_heading' => ['Heading'],
        'call_out_body' => 12,
        'call_out_background_color' => ['bg-red'],
    ]);

    expect($set['block_heading'])->toBe('')
        ->and($set['body'])->toBe('')
        ->and($set['background_color'])->toBe('bg-auto');
})->with('callOutEntries');

it('imports the background image when the toggle is on', function (string $entry): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $assetImporter = callOutAssetImporter(true, 'images/call-out.jpg');

    $set = callOutSet($entry, ['call_out_use_image' => '1', 'call_out_image' => 55], $assetImporter);

    expect($set['background_image_query'])->toBeTrue()
        ->and($set['background_image'] ?? null)->toBe('images/call-out.jpg')
        ->and($assetImporter->importedImages)->toBe([['55', 'default']]);

    $logSpy->shouldNotHaveReceived('warning');
})->with('callOutEntries');

it('leaves the image alone when the toggle is off', function (string $entry, mixed $toggle): void {
    $assetImporter = callOutAssetImporter();

    $set = callOutSet($entry, ['call_out_use_image' => $toggle, 'call_out_image' => '55'], $assetImporter);

    expect($set['background_image_query'])->toBeFalse()
        ->and($set)->not->toHaveKey('background_image')
        ->and($assetImporter->importedImages)->toBe([]);
})->with('callOutEntries')->with([
    'a zero string' => ['0'],
    'an empty string' => [''],
    'missing' => [null],
]);

it('sets the toggle but imports nothing without a usable image id', function (string $entry, mixed $imageId): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $assetImporter = callOutAssetImporter();

    $set = callOutSet($entry, ['call_out_use_image' => '1', 'call_out_image' => $imageId], $assetImporter);

    expect($set['background_image_query'])->toBeTrue()
        ->and($set)->not->toHaveKey('background_image')
        ->and($assetImporter->importedImages)->toBe([]);

    // No id means nothing to import, so there is no failure to warn about
    $logSpy->shouldNotHaveReceived('warning');
})->with('callOutEntries')->with([
    'an empty string' => [''],
    'false, which casts to an empty string' => [false],
    'an array' => [['55']],
    'missing' => [null],
]);

it('warns when an image fails to import while uploads are enabled', function (string $entry, ?string $imported, string $method): void {
    ProcessingContext::setWordPressPageLink('https://example.org/clinic/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $set = callOutSet(
        $entry,
        ['call_out_use_image' => '1', 'call_out_image' => '55'],
        callOutAssetImporter(true, $imported)
    );

    expect($set)->not->toHaveKey('background_image');

    $logSpy->shouldHaveReceived('warning', [
        "CallOutParser::$method: Failed to import call-out background image when uploads are enabled (from entry: https://example.org/clinic/)",
        ['site' => 'default', 'image_id' => '55', 'entry_url' => 'https://example.org/clinic/'],
    ]);
})->with([
    'block, nothing imported' => ['block', null, 'parse'],
    'block, an empty path' => ['block', '', 'parse'],
    'module, nothing imported' => ['module', null, 'parseModule'],
    'module, an empty path' => ['module', '', 'parseModule'],
]);

it('stays quiet about a missing image while uploads are disabled', function (string $entry): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    $set = callOutSet(
        $entry,
        ['call_out_use_image' => '1', 'call_out_image' => '55'],
        callOutAssetImporter(false)
    );

    expect($set)->not->toHaveKey('background_image');

    $logSpy->shouldNotHaveReceived('warning', function (string $message): bool {
        return str_starts_with($message, 'CallOutParser::');
    });
})->with('callOutEntries');

it('reads block attributes without a data key, and ignores a data key that is not an array', function (): void {
    $parser = new CallOutParser;

    $bare = $parser->parse(['call_out_heading' => 'Bare'], 'default', callOutAssetImporter(), new NullOutput);
    $scalar = $parser->parse(['data' => 'x', 'call_out_heading' => 'Ignored'], 'default', callOutAssetImporter(), new NullOutput);

    expect($bare['block_heading'] ?? null)->toBe('Bare')
        ->and($scalar['block_heading'] ?? null)->toBe('');
});

it('gives each set its own row id', function (string $entry): void {
    $parser = new CallOutParser;
    $parse = fn (): array => $entry === 'block'
        ? $parser->parse([], 'default', callOutAssetImporter(), new NullOutput)
        : (array) $parser->parseModule([], 'default', callOutAssetImporter(), new NullOutput);

    expect($parse()['id'] ?? null)->not->toBe($parse()['id'] ?? null);
})->with('callOutEntries');
