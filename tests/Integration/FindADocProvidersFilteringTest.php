<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocProvidersParser;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that an imported Find-a-Doc Providers block preserves the WordPress
 * theme's always-on facet filtering (issue wordpress-importer-archive#421).
 *
 * `UAMS-Web/uams-statamic#1242` gates reader-facing facet filtering behind an
 * `enable_filtering` toggle on the `uamsweb_block_fad_providers` fieldset that
 * defaults to `false` (a static list). The WordPress theme filtered facets over
 * `admin-ajax.php` unconditionally, so without an explicit flag every imported
 * block would arrive with filtering off — a behavior regression versus the source
 * site.
 *
 * The block attributes are the ones a real Find-a-Doc providers block carries,
 * so this doubles as that shape's expectation for the flag.
 *
 * @command  php vendor/bin/pest tests/Integration/FindADocProvidersFilteringTest.php
 */
uses(StatamicTestCase::class);

/**
 * The `wp:acf/fad-providers` block attributes from the verified fixture.
 *
 * @return array<string, mixed>
 */
function fadProvidersFixtureAttributes(): array
{
    return [
        'name' => 'acf/fad-providers',
        'data' => [
            'field_block_fad_providers_heading' => 'Block 1 Heading',
            'field_block_fad_providers_description' => 'Block 1 body',
            'field_block_fad_providers_filter_aoe' => ['998634', '998653'],
            'field_block_fad_providers_filter_location' => ['903201', '10449'],
            'field_block_fad_providers_filter_region' => ['5629', '5625'],
            'field_block_fad_providers_filter_ids' => ['1039425', '1018894', '3182'],
            'field_block_fad_providers_count' => '12',
            'field_block_fad_providers_background_color' => 'bg-white',
            'field_block_fad_providers_more' => '1',
            'field_block_fad_providers_more_text' => 'Block 1 More Heading',
            'field_block_fad_providers_more_button_text' => 'Block 1 More Button Text',
            'field_block_fad_providers_more_button_url' => [
                'title' => '',
                'url' => 'https://google.com/',
                'target' => '_blank',
            ],
            'field_block_fad_providers_more_button_description' => 'Block 1 more button link description',
        ],
        'align' => 'full',
        'mode' => 'auto',
    ];
}

/**
 * Parse the fixture's Find-a-Doc Providers block into its replicator set.
 *
 * @return array<int|string, mixed>
 */
function parseFadProvidersFixtureBlock(AssetImporter $assetImporter): array
{
    return (new FindADocProvidersParser)->parse(
        fadProvidersFixtureAttributes(),
        'uamshealth',
        $assetImporter,
        new BufferedOutput,
    );
}

it('enables filtering on an imported Find-a-Doc Providers block', function (): void {
    // A typed PHPUnit mock satisfies the AssetImporter parameter. The more-link
    // passes through LinkProcessor, which asks the importer for the source blog URL
    // to tell internal links from external ones.
    $assetImporter = $this->createMock(AssetImporter::class);
    $assetImporter->method('getBaseBlogUrl')->willReturn('https://uamshealth.com/');

    $set = parseFadProvidersFixtureBlock($assetImporter);

    expect($set['type'] ?? null)->toBe('uamsweb_block_fad_providers');

    // The WordPress block filtered unconditionally, so the imported block must not
    // fall back to the fieldset's static-list default.
    expect($set)->toHaveKey('enable_filtering');
    expect($set['enable_filtering'] ?? null)->toBeTrue();
})->group('blocks', 'find-a-doc');
