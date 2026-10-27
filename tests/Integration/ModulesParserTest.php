<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\ModulesParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that `ModulesParser` reads each module's fields out of the page's
 * postmeta by its index, routes every supported module type to its parser,
 * and warns about, or logs the failure of, a module it cannot parse.
 *
 * @command  php vendor/bin/pest tests/Integration/ModulesParserTest.php
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
 * An `AssetImporter` that imports nothing.
 */
function modulesAssetImporter(): AssetImporter
{
    /** @var AssetImporter&MockInterface $assetImporter */
    $assetImporter = Mockery::mock(AssetImporter::class)->shouldIgnoreMissing();

    return $assetImporter;
}

/**
 * Parse a page's modules.
 *
 * @param  array<int|string, mixed>  $modules  The module order, index => type
 * @param  array<int|string, mixed>  $postmeta  The page's postmeta
 * @param  ?AssetImporter  $assetImporter  The importer, or one that imports nothing
 * @return array<int|string, mixed> The page builder sets
 */
function modulesParse(array $modules, array $postmeta = [], ?AssetImporter $assetImporter = null): array
{
    return new ModulesParser()->parseModules(
        $modules,
        'default',
        $assetImporter ?? modulesAssetImporter(),
        new NullOutput,
        [],
        null,
        $postmeta
    );
}

/**
 * The block headings of a list of page builder sets, in order.
 *
 * @param  array<int|string, mixed>  $sets  The page builder sets
 * @return list<mixed> One heading per set
 */
function modulesHeadings(array $sets): array
{
    return array_values(array_map(
        fn (mixed $set): mixed => \is_array($set) ? ($set['block_heading'] ?? null) : null,
        $sets
    ));
}

it('extracts the fields of one module by its index prefix', function (): void {
    // A key that is not a string comes first, so skipping it must not end the scan
    expect(ModulesParser::extractModuleDataFromPostmeta([
        7 => 'uamswp_modules_1_numeric_key',
        'uamswp_modules_1_section_heading' => 'One',
        'uamswp_modules_1_' => 'empty name',
        'uamswp_modules_10_section_heading' => 'Ten',
        'uamswp_modules_0_section_heading' => 'Zero',
        'xuamswp_modules_1_section_heading' => 'Not a prefix',
    ], 1))->toBe([
        'section_heading' => 'One',
        '' => 'empty name',
    ]);
});

it('returns no fields when no key carries the prefix', function (): void {
    expect(ModulesParser::extractModuleDataFromPostmeta(['other' => 'x'], 3))->toBe([]);
});

it('parses modules in order, reading each one from its own postmeta', function (): void {
    $sets = modulesParse(
        [0 => 'modules_uams_section', '1' => 'modules_uams_section'],
        [
            'uamswp_modules_0_section_heading' => 'First',
            'uamswp_modules_1_section_heading' => 'Second',
            'uamswp_modules_1_section_background_color' => 'bg-gray',
        ]
    );

    expect(modulesHeadings($sets))->toBe(['First', 'Second'])
        ->and(array_keys($sets))->toBe([0, 1])
        ->and($sets[0]['type'] ?? null)->toBe('uamsweb_block_content')
        ->and($sets[0]['background_color'] ?? null)->toBe('bg-auto')
        ->and($sets[1]['background_color'] ?? null)->toBe('bg-gray');
});

it('skips a module whose index is not a number or whose type is not a string', function (): void {
    $sets = modulesParse(
        [
            'first' => 'modules_uams_section',
            '1a' => 'modules_uams_section',
            2 => ['modules_uams_section'],
            3 => 'modules_uams_section',
        ],
        [
            'uamswp_modules_2_section_heading' => 'Two',
            'uamswp_modules_3_section_heading' => 'Three',
        ]
    );

    expect(modulesHeadings($sets))->toBe(['Three']);
});

it('routes every supported module type to a parser', function (string $type): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    modulesParse([0 => $type]);

    $logSpy->shouldNotHaveReceived('warning', function (string $message): bool {
        return str_contains($message, 'module type');
    });
})->with([
    'modules_text_stacked',
    'modules_link_list',
    'modules_action_bar',
    'modules_call_out',
    'modules_cta',
    'modules_news_grid',
    'modules_text_overlay',
    'modules_uams_gallery',
    'modules_uams_content',
    'modules_uams_livewhale',
    'modules_uams_section',
    'modules_image_side',
]);

it('drops a module of an unimplemented type, with a warning naming it', function (string $type): void {
    ProcessingContext::setWordPressPageLink('https://example.org/about/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(modulesParse([0 => $type, 1 => 'modules_uams_section'], [
        'uamswp_modules_0_title' => 'Hero',
        'uamswp_modules_1_section_heading' => 'Kept',
    ]))->toHaveCount(1);

    $logSpy->shouldHaveReceived('warning', [
        "ModulesParser::parseModule: Module type '$type' is not yet implemented (from entry: https://example.org/about/)",
        ['module_data' => ['title' => 'Hero'], 'entry_url' => 'https://example.org/about/'],
    ]);
})->with(['modules_hero', 'modules_post_tile', 'modules_post_tiles']);

it('drops a module of an unknown type, with a warning naming it', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.org/about/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(modulesParse([0 => 'modules_mystery'], ['uamswp_modules_0_x' => 'y']))->toBe([]);

    $logSpy->shouldHaveReceived('warning', [
        "ModulesParser::parseModule: Unknown module type 'modules_mystery' (from entry: https://example.org/about/)",
        ['module_data' => ['x' => 'y'], 'entry_url' => 'https://example.org/about/'],
    ]);
});

it('omits the entry link from the warning when none is set', function (): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    modulesParse([0 => 'modules_mystery']);

    $logSpy->shouldHaveReceived('warning', [
        "ModulesParser::parseModule: Unknown module type 'modules_mystery'",
        ['module_data' => []],
    ]);
});

it('logs and drops a module whose parser throws, and keeps parsing', function (): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    /** @var AssetImporter&MockInterface $assetImporter */
    $assetImporter = Mockery::mock(AssetImporter::class);
    $assetImporter->allows('getUploadImages')->andReturn(true);
    $assetImporter->allows('importImage')->andThrow(new RuntimeException('disk full'));

    $sets = modulesParse(
        [0 => 'modules_call_out', 1 => 'modules_uams_section'],
        [
            'uamswp_modules_0_call_out_use_image' => '1',
            'uamswp_modules_0_call_out_image' => '55',
            'uamswp_modules_1_section_heading' => 'After',
        ],
        $assetImporter
    );

    expect(modulesHeadings($sets))->toBe(['After']);

    $logSpy->shouldHaveReceived('error', [
        "ModulesParser::parseModule: Error parsing module 'modules_call_out'",
        [
            'error' => 'disk full',
            'module_data' => ['call_out_use_image' => '1', 'call_out_image' => '55'],
        ],
    ]);
});
