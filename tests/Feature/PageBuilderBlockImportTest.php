<?php

declare(strict_types=1);

use Statamic\Facades\Stache;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

// Guard the live `content/` submodule: capture it before each test and refuse
// to leave anything behind afterwards (wordpress-importer-archive#570).
beforeEach(function (): void {
    $this->captureSubmoduleStates();

    // ---
    // The page import runs the logo list term pass, which writes category and
    // label entries into two site-scoped collections and their trees (wordpress-importer-archive#1220).
    // The slug-list cleanup below does not reach them, so they are restored
    // verbatim instead.
    // ---
    $this->snapshotIsolatedState([
        'content/collections/logos_item_categories',
        'content/collections/logos_item_labels',
        'content/trees/collections/default',
    ]);

    // ---
    // Each test's category and label entries are restored away after it, under
    // a live Stache, while the term-pass dedup and the destination registry are
    // process statics. A later import would otherwise skip the term pass and
    // reuse ids for entries that no longer exist on disk.
    // ---
    BaseEntryCreator::clearTaxonomyCaches();
    Stache::clear();
});

afterEach(function (): void {
    // Clean up any created test files, regardless of whether the test passed or
    // failed. Cleanup errors are logged but never fail the test so they cannot
    // mask the actual test failure.
    try {
        $this::cleanupTestFilesWithPaths(
            [
                'sample-page',
                'logo-list-page-entry-v01',
                'logo-list-page-entry-v02',
                'logo-list-page-entry-v03a',
                'logo-list-page-entry-v03b',
                'logo-list-page-entry-v04',
                'logo-list-page-entry-v05',
                'logo-list-page-entry-v06',
                'logo-list-page-entry-v07',
                'logo-list-page-entry-v08a',
                'logo-list-page-entry-v08b',
                'logo-list-page-entry-v09',
                'logo-list-page-entry-v10',
                'logo-list-page-entry-v11',
                'logo-list-page-entry-v12',
                'logo-list-page-entry-v13',
                'logo-list-page-entry-v14a',
                'logo-list-page-entry-v14b',
                'logo-list-page-entry-v14c',
                'logo-list-page-entry-v14d',
                'logo-list-page-entry-v14e',
                'logo-list-page-entry-v14f',
                'logo-list-page-entry-v15a',
                'logo-list-page-entry-v15b',
                'logo-list-page-entry-v15c',
                'logo-list-page-entry-v16',
                'logo-list-page-entry-v17a',
                'logo-list-page-entry-v17b',
            ],
            'pages', // collection handle
            'default', // site handle
            [
                'images' => [
                    '2020-05-fpo_200.jpg',
                ],
            ],
            [
                'logos_item_categories::default_baz-qux',
            ],
            true
        );
    } catch (Exception $e) {
        error_log('PageBuilderBlockImportTest cleanup error: '.$e->getMessage());
    }

    // Restore the snapshotted collections and trees so imported category and
    // label entries don't leak into the next test (wordpress-importer-archive#1220).
    $this->restoreIsolatedState();

    // Last, so it judges what the cleanup above did NOT put back (wordpress-importer-archive#570).
    $this->assertSubmodulesUnchanged();
});

/**
 * Unit tests for PageBuilderBlock import functionality.
 *
 * Tests the page builder block import functionality, including:
 *   - Page builder block entry creation and mapping
 *   - Page builder block content processing
 *   - Page builder block metadata handling
 *   - Content processing and organization
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/PageBuilderBlockImportTest.php
 */
it('validates each mapped YAML field for a page builder block variant', function (
    array $fieldAssertions,
    string $postType,
    string $entryVariantType,
    ?string $uniqueSlug,
    string $acfName,
    array $acfData
): void {
    // Create XML parameters array
    $xmlParameters = [
        'postType' => $postType,
        'entryVariantType' => $entryVariantType,
        'uniqueSlug' => $uniqueSlug,
        'acfName' => $acfName,
        'acfData' => $acfData,
    ];

    // Create a callback function that generates the page-builder XML
    $xmlGeneratorCallback = function (array $params): string {
        $postType = pageBuilderStringParam($params, 'postType', 'page');
        $entryVariantType = pageBuilderStringParam($params, 'entryVariantType', 'standard_v01');
        $acfName = pageBuilderStringParam($params, 'acfName', 'acf/logo-list');

        $rawAcfData = $params['acfData'] ?? [];
        if (! is_array($rawAcfData)) {
            $rawAcfData = [];
        }

        // Resolve the unique slug, falling back to the trait's variant slug
        $uniqueSlug = pageBuilderNullableStringParam($params, 'uniqueSlug')
                ?? $this->getUniqueSlugForEntryVariant($postType, $entryVariantType);

        // Merge defaults with the provided ACF data
        $acfData = array_merge(
            pageBuilderEntryVariantTypeDefaults($entryVariantType),
            pageBuilderNormalizeAcfData($rawAcfData)
        );

        $xmlContent = pageBuilderXmlContent(
            $this->getXmlHeader(),
            $this->getXmlFooter(),
            $uniqueSlug,
            $acfName,
            $acfData
        );

        $xmlPath = $this->createTempFile('test_page_builder_block_');
        file_put_contents(
            $xmlPath,
            $xmlContent
        );

        return $xmlPath;
    };

    // Assert every field for this variant from a single import (one app boot)
    $this->testYamlFieldsForVariant(
        $fieldAssertions,
        $xmlParameters,
        $xmlGeneratorCallback
    );
})->with(pageBuilderYamlFieldValidationProvider());

it('processes a div.lwcw LiveWhale widget as a uamsweb_block_livewhale page builder block', function (): void {
    // ---
    // Create minimal XML file so XmlParser has a path for ProcessingContext
    // ---

    // Create a temporary file path for the minimal XML
    $xmlPath = $this->createTempFile('test_livewhale_lwcw_');

    // Build minimal RSS XML so XmlParser has valid structure and getFilePath() returns a path
    $minimalXml = '<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
    </channel>
</rss>';

    // Write the minimal XML to the temp file
    file_put_contents($xmlPath, $minimalXml);

    // ---
    // Instantiate XmlParser and parse the temp file so ProcessingContext has a path
    // ---

    // Instantiate XmlParser for ContentProcessor
    $xmlParser = new XmlParser;

    // Parse the temp XML file to set file path for ProcessingContext
    $xmlParser->parse($xmlPath);

    // ---
    // Instantiate ContentProcessor and dependencies for process()
    // ---

    // Instantiate ContentProcessor
    $contentProcessor = new ContentProcessor;

    // Instantiate AssetImporter for process()
    $assetImporter = new AssetImporter;

    // Use NullOutput to suppress console output during process()
    $output = new NullOutput;

    // ---
    // Content matching WordPressExport_uamscaregiving-org_eldorado_debug.xml
    // calendar-test page: div.lwcw with data-options id=20, no Gutenberg comments
    // ---
    $content = '<div class="lwcw" data-options="id=20&format=html"></div>
<script type="text/javascript" id="lw_lwcw" src="//calendar.uams.edu/livewhale/theme/core/scripts/lwcw.js"></script>';

    // Process div.lwcw content through ContentProcessor with pages collection
    $result = $contentProcessor->process(
        $content,
        'default',
        $assetImporter,
        $output,
        $xmlParser,
        'Calendar test',
        '1842',
        'https://uamscaregiving.org/eldorado/calendar-test/',
        [],
        [],
        [],
        ['post_name' => 'calendar-test'],
        'pages',
        'calendar-test'
    );

    // ---
    // Assert result contains page_builder with one LiveWhale block
    // ---

    // Assert result contains page_builder key
    $pageBuilder = pageBuilderRequirePageBuilder($result);

    // Assert exactly one block for single div.lwcw widget
    expect($pageBuilder)->toHaveCount(1);

    // ---
    // Assert first block has expected type, livewhale_id, and block_heading
    // ---

    // Get the first (and only) block from page_builder
    $block = pageBuilderRequireFirstBlock($pageBuilder);

    // Assert block type is uamsweb_block_livewhale
    expect($block['type'] ?? null)->toBe('uamsweb_block_livewhale');

    // Assert livewhale_id is 20 from data-options id=20
    $livewhaleId = $block['livewhale_id'] ?? null;
    expect(\is_scalar($livewhaleId) ? (string) $livewhaleId : '')->toBe('20');

    // Assert block has block_heading key
    expect($block)->toHaveKey('block_heading');

    // Assert block_heading is non-empty (placeholder for div.lwcw)
    expect($block['block_heading'] ?? '')->not->toBeEmpty();

    // Clean up temp file
    if (file_exists($xmlPath)) {
        // Remove the temporary XML file from disk
        unlink($xmlPath);
    }
});

it('processes a single [blogroll] shortcode as a uamsweb_block_news page builder block', function (): void {
    // Create a temporary file path for the minimal XML
    $xmlPath = $this->createTempFile('test_blogroll_');

    // ---
    // Build minimal RSS XML so XmlParser has valid structure and getFilePath() returns a path
    // ---

    // Define minimal RSS XML for parser
    $minimalXml = '<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">
    <channel>
    </channel>
</rss>';

    // Write minimal XML to temp file
    file_put_contents($xmlPath, $minimalXml);

    // ---
    // Initialize XML parser with temp file path
    // ---

    // Create XML parser instance
    $xmlParser = new XmlParser;

    // Parse the minimal XML to load channel structure
    $xmlParser->parse($xmlPath);

    // ---
    // Create content processor and dependencies
    // ---

    // Create ContentProcessor instance
    $contentProcessor = new ContentProcessor;

    // Create AssetImporter for content processing
    $assetImporter = new AssetImporter;

    // Create null output for silent processing
    $output = new NullOutput;

    // Content with wp:shortcode containing single [blogroll] (like caregiving exports)
    $content = '<!-- wp:shortcode -->
[blogroll number=4 trim=true author=hide image=show date=show]
<!-- /wp:shortcode -->';

    // Process content through ContentProcessor with blogroll shortcode
    $result = $contentProcessor->process(
        $content,
        'default',
        $assetImporter,
        $output,
        $xmlParser,
        'Test Page',
        '123',
        'https://example.org/test-page/',
        [],
        [],
        [],
        ['post_name' => 'test-page'],
        'pages',
        'test-page'
    );

    // ---
    // Assert page_builder structure and single block
    // ---

    // Assert result has page_builder key
    $pageBuilder = pageBuilderRequirePageBuilder($result);

    // Assert exactly one block for single blogroll shortcode
    expect($pageBuilder)->toHaveCount(1);

    // ---
    // Assert block type and field values from shortcode attributes
    // ---

    // Get the single block for assertions
    $block = pageBuilderRequireFirstBlock($pageBuilder);

    // Assert block type is uamsweb_block_news
    expect($block['type'] ?? null)->toBe('uamsweb_block_news');

    // Assert placeholder block heading
    expect($block['block_heading'] ?? null)->toBe('Placeholder Block Heading — Please Replace');

    // Assert hide_block_heading is false
    expect($block['hide_block_heading'] ?? true)->toBeFalse();

    // Assert background_color is bg-auto
    expect($block['background_color'] ?? null)->toBe('bg-auto');

    // Assert source_sites selects both the news site and the placement's own
    // site, and that the retired `local` / `advanced_cat` keys are gone (wordpress-importer-archive#417)
    expect($block['source_sites'] ?? null)->toBe(['news', 'current']);
    expect($block)->not->toHaveKey('local');
    expect($block)->not->toHaveKey('advanced_cat');

    // Assert offset is 0
    expect($block['offset'] ?? -1)->toBe(0);

    // Assert image=show sets hide_img false
    expect($block['hide_img'] ?? true)->toBeFalse();

    // Assert date=show sets hide_date false
    expect($block['hide_date'] ?? true)->toBeFalse();

    // Assert author=hide sets hide_creator_query true
    expect($block['hide_creator_query'] ?? false)->toBeTrue();

    // Check if temp file exists before cleanup
    if (file_exists($xmlPath)) {
        // Remove temp file
        unlink($xmlPath);
    }
});

/**
 * Data provider for individual YAML field validation tests.
 *
 * Each field is tested separately to make it easier to identify
 * which specific fields are failing during import validation.
 *
 * @return array<string, array<string, mixed>> Test cases keyed by label
 */
function pageBuilderYamlFieldValidationProvider(): array
{
    $testCases = [];
    $entryVariantTypes = [];

    // Create base page for all blocks
    $base = [
        'xml_parameters' => [
            'postType' => 'page',
            'entryVariantType' => null,
            'uniqueSlug' => null,
            'acfName' => 'acf/logo-list',
            'acfData' => [],
        ],
        'yaml_assertions' => [
            'blueprint' => [
                'field' => 'blueprint',
                'value' => 'landing_page',
                'type' => 'string',
            ],
            'title' => [
                'field' => 'title',
                'value' => 'Sample Page',
                'type' => 'string',
            ],
            'slug' => [
                'field' => 'slug',
                'value' => 'logo-list-page-entry-v01',
                'type' => 'string',
            ],
            'page builder enabled' => [
                'field' => 'page_builder.0.enabled',
                'value' => true,
                'type' => 'boolean',
            ],
            'status and visibility' => [
                'field' => 'status_and_visibility',
                'value' => 'published',
                'type' => 'string',
            ],
            'password protected' => [
                'field' => 'password_protected',
                'value' => false,
                'type' => 'boolean',
            ],
        ],
    ];

    // Create base page for logo list block
    $base_logo_list_block = $base;
    $base_logo_list_block['xml_parameters']['entryVariantType'] = 'logo_list_v01';
    $base_logo_list_block['xml_parameters']['acfName'] = 'acf/logo-list';
    $base_logo_list_block['xml_parameters']['acfData'] = [
        'heading' => 'Sample Logo List Block',
        'hide_heading' => '0',
        'description' => 'This is a sample logo list block for testing purposes.',
        'background_color' => 'bg-auto',
        'filter_ids' => '["200"]',
        'filter_logic' => 'IN',
        'labels_ids' => '["100"]',
        'labels_logic' => 'IN',
        'featured' => '0',
        'layout' => 'grid',
        'width' => 'alignfull',
        'titles' => '0',
        'labels' => '1',
        'shortdescr' => '0',
        'links' => '1',
        'links_target' => '1',
        'order' => '["title|ASC"]',
        'max' => '',
        'more' => '0',
        'more_text' => '',
        'more_button_text' => '',
        'more_button_url' => '',
        'more_button_description' => '',
    ];
    $base_logo_list_block['yaml_assertions'] = \array_merge(
        $base_logo_list_block['yaml_assertions'],
        [
            'page builder id' => [
                'field' => 'page_builder.0.id',
                'value' => '#rowId(8)',
                'type' => 'string',
            ],
            'page builder type' => [
                'field' => 'page_builder.0.type',
                'value' => 'uamsweb_block_logo_list',
                'type' => 'string',
            ],
            'page builder block heading' => [
                'field' => 'page_builder.0.block_heading',
                'value' => 'Sample Logo List Block',
                'type' => 'string',
            ],
            'page builder hide block heading' => [
                'field' => 'page_builder.0.hide_block_heading',
                'value' => false,
                'type' => 'boolean',
            ],
            'page builder block description' => [
                'field' => 'page_builder.0.block_description',
                'value' => 'This is a sample logo list block for testing purposes.',
                'type' => 'string',
            ],
            'page builder background color' => [
                'field' => 'page_builder.0.background_color',
                'value' => 'bg-auto',
                'type' => 'string',
            ],
            'page builder logo list filter category terms' => [
                'field' => 'page_builder.0.logo_list_filter_category',
                // The page import now runs the logo list term pass, so the
                // category resolves to its `logos_item_categories` entry id,
                // generated per import and matched by shape (wordpress-importer-archive#1220)
                'value' => [
                    '#rowId(36)',
                ],
                'type' => 'array',
            ],
            'page builder logo list filter category logic' => [
                'field' => 'page_builder.0.logo_list_filter_category_logic',
                'value' => 'any',
                'type' => 'string',
            ],
            'page builder logo list filter label terms' => [
                'field' => 'page_builder.0.logo_list_filter_label',
                // A `logos_item_labels` entry id, matched by shape (wordpress-importer-archive#1220)
                'value' => [
                    '#rowId(36)',
                ],
                'type' => 'array',
            ],
            'page builder logo list filter label logic' => [
                'field' => 'page_builder.0.logo_list_filter_label_logic',
                'value' => 'any',
                'type' => 'string',
            ],
            'page builder logo list featured' => [
                'field' => 'page_builder.0.logo_list_featured',
                'value' => false,
                'type' => 'boolean',
            ],
            'page builder logo list layout' => [
                'field' => 'page_builder.0.logo_list_layout',
                'value' => 'grid',
                'type' => 'string',
            ],
            'page builder logo list width' => [
                'field' => 'page_builder.0.logo_list_width',
                'value' => 'alignfull',
                'type' => 'string',
            ],
            'page builder logo list titles' => [
                'field' => 'page_builder.0.logo_list_titles',
                'value' => false,
                'type' => 'boolean',
            ],
            'page builder logo list labels' => [
                'field' => 'page_builder.0.logo_list_labels',
                'value' => true,
                'type' => 'boolean',
            ],
            'page builder logo list shortdescr' => [
                'field' => 'page_builder.0.logo_list_shortdescr',
                'value' => false,
                'type' => 'boolean',
            ],
            'page builder logo list links' => [
                'field' => 'page_builder.0.logo_list_links',
                'value' => true,
                'type' => 'boolean',
            ],
            'page builder logo list links target' => [
                'field' => 'page_builder.0.logo_list_links_target',
                'value' => true,
                'type' => 'boolean',
            ],
            'page builder logo list sort' => [
                'field' => 'page_builder.0.logo_list_sort',
                'value' => [
                    [
                        'id' => '#rowId(8)',
                        'type' => 'sort_option',
                        'sort_by' => 'title',
                        'sort_order' => 'asc',
                        'enabled' => true,
                    ],
                ],
                'type' => 'array',
            ],
            'page builder more query' => [
                'field' => 'page_builder.0.more_query',
                'value' => false,
                'type' => 'boolean',
            ],
        ]
    );

    // Logo List Block v01 (base)
    $logo_list_block_v01 = $base_logo_list_block;
    $logo_list_block_v01['base_test_name'] = 'logo list block v01';
    $logo_list_block_v01['xml_parameters']['entryVariantType'] = 'logo_list_v01';
    $entryVariantTypes[] = $logo_list_block_v01;

    // Logo List Block v02 (hide_heading = 1)
    $logo_list_block_v02 = $base_logo_list_block;
    $logo_list_block_v02['base_test_name'] = 'logo list block v02';
    $logo_list_block_v02['xml_parameters']['entryVariantType'] = 'logo_list_v02';
    $logo_list_block_v02['xml_parameters']['acfData']['hide_heading'] = '1';
    $logo_list_block_v02['yaml_assertions']['page builder hide block heading']['value'] = true;
    $logo_list_block_v02['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v02';
    $entryVariantTypes[] = $logo_list_block_v02;

    // Logo List Block v03a (background_color = 'bg-white')
    $logo_list_block_v03a = $base_logo_list_block;
    $logo_list_block_v03a['base_test_name'] = 'logo list block v03a';
    $logo_list_block_v03a['xml_parameters']['entryVariantType'] = 'logo_list_v03a';
    $logo_list_block_v03a['xml_parameters']['acfData']['background_color'] = 'bg-white';
    $logo_list_block_v03a['yaml_assertions']['page builder background color']['value'] = 'bg-white';
    $logo_list_block_v03a['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v03a';
    $entryVariantTypes[] = $logo_list_block_v03a;

    // Logo List Block v03b (background_color = 'bg-gray')
    $logo_list_block_v03b = $base_logo_list_block;
    $logo_list_block_v03b['base_test_name'] = 'logo list block v03b';
    $logo_list_block_v03b['xml_parameters']['entryVariantType'] = 'logo_list_v03b';
    $logo_list_block_v03b['xml_parameters']['acfData']['background_color'] = 'bg-gray';
    $logo_list_block_v03b['yaml_assertions']['page builder background color']['value'] = 'bg-gray';
    $logo_list_block_v03b['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v03b';
    $entryVariantTypes[] = $logo_list_block_v03b;

    // Logo List Block v04 (filter_logic = 'AND')
    $logo_list_block_v04 = $base_logo_list_block;
    $logo_list_block_v04['base_test_name'] = 'logo list block v04';
    $logo_list_block_v04['xml_parameters']['entryVariantType'] = 'logo_list_v04';
    $logo_list_block_v04['xml_parameters']['acfData']['filter_logic'] = 'AND';
    $logo_list_block_v04['yaml_assertions']['page builder logo list filter category logic']['value'] = 'all';
    $logo_list_block_v04['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v04';
    $entryVariantTypes[] = $logo_list_block_v04;

    // Logo List Block v05 (labels_logic = 'AND')
    $logo_list_block_v05 = $base_logo_list_block;
    $logo_list_block_v05['base_test_name'] = 'logo list block v05';
    $logo_list_block_v05['xml_parameters']['entryVariantType'] = 'logo_list_v05';
    $logo_list_block_v05['xml_parameters']['acfData']['labels_logic'] = 'AND';
    $logo_list_block_v05['yaml_assertions']['page builder logo list filter label logic']['value'] = 'all';
    $logo_list_block_v05['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v05';
    $entryVariantTypes[] = $logo_list_block_v05;

    // Logo List Block v06 (featured = '1')
    $logo_list_block_v06 = $base_logo_list_block;
    $logo_list_block_v06['base_test_name'] = 'logo list block v06';
    $logo_list_block_v06['xml_parameters']['entryVariantType'] = 'logo_list_v06';
    $logo_list_block_v06['xml_parameters']['acfData']['featured'] = '1';
    $logo_list_block_v06['yaml_assertions']['page builder logo list featured']['value'] = true;
    $logo_list_block_v06['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v06';
    $entryVariantTypes[] = $logo_list_block_v06;

    // Logo List Block v07 (layout = 'vertical')
    $logo_list_block_v07 = $base_logo_list_block;
    $logo_list_block_v07['base_test_name'] = 'logo list block v07';
    $logo_list_block_v07['xml_parameters']['entryVariantType'] = 'logo_list_v07';
    $logo_list_block_v07['xml_parameters']['acfData']['layout'] = 'vertical';
    unset($logo_list_block_v07['xml_parameters']['acfData']['width']);
    unset($logo_list_block_v07['xml_parameters']['acfData']['titles']);
    unset($logo_list_block_v07['xml_parameters']['acfData']['shortdescr']);
    $logo_list_block_v07['yaml_assertions']['page builder logo list layout']['value'] = 'vertical';
    unset($logo_list_block_v07['yaml_assertions']['page builder logo list width']);
    unset($logo_list_block_v07['yaml_assertions']['page builder logo list titles']);
    unset($logo_list_block_v07['yaml_assertions']['page builder logo list shortdescr']);
    $logo_list_block_v07['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v07';
    $entryVariantTypes[] = $logo_list_block_v07;

    // Logo List Block v08a (width = 'aligncenter')
    $logo_list_block_v08a = $base_logo_list_block;
    $logo_list_block_v08a['base_test_name'] = 'logo list block v08a';
    $logo_list_block_v08a['xml_parameters']['entryVariantType'] = 'logo_list_v08a';
    $logo_list_block_v08a['xml_parameters']['acfData']['width'] = 'aligncenter';
    $logo_list_block_v08a['yaml_assertions']['page builder logo list width']['value'] = 'aligncenter';
    $logo_list_block_v08a['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v08a';
    $entryVariantTypes[] = $logo_list_block_v08a;

    // Logo List Block v08b (width = 'alignwide')
    $logo_list_block_v08b = $base_logo_list_block;
    $logo_list_block_v08b['base_test_name'] = 'logo list block v08b';
    $logo_list_block_v08b['xml_parameters']['entryVariantType'] = 'logo_list_v08b';
    $logo_list_block_v08b['xml_parameters']['acfData']['width'] = 'alignwide';
    $logo_list_block_v08b['yaml_assertions']['page builder logo list width']['value'] = 'alignwide';
    $logo_list_block_v08b['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v08b';
    $entryVariantTypes[] = $logo_list_block_v08b;

    // Logo List Block v09 (titles = '1')
    $logo_list_block_v09 = $base_logo_list_block;
    $logo_list_block_v09['base_test_name'] = 'logo list block v09';
    $logo_list_block_v09['xml_parameters']['entryVariantType'] = 'logo_list_v09';
    $logo_list_block_v09['xml_parameters']['acfData']['titles'] = '1';
    $logo_list_block_v09['yaml_assertions']['page builder logo list titles']['value'] = true;
    $logo_list_block_v09['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v09';
    $entryVariantTypes[] = $logo_list_block_v09;

    // Logo List Block v10 (labels = '0')
    $logo_list_block_v10 = $base_logo_list_block;
    $logo_list_block_v10['base_test_name'] = 'logo list block v10';
    $logo_list_block_v10['xml_parameters']['entryVariantType'] = 'logo_list_v10';
    $logo_list_block_v10['xml_parameters']['acfData']['labels'] = '0';
    $logo_list_block_v10['yaml_assertions']['page builder logo list labels']['value'] = false;
    $logo_list_block_v10['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v10';
    $entryVariantTypes[] = $logo_list_block_v10;

    // Logo List Block v11 (shortdescr = '1')
    $logo_list_block_v11 = $base_logo_list_block;
    $logo_list_block_v11['base_test_name'] = 'logo list block v11';
    $logo_list_block_v11['xml_parameters']['entryVariantType'] = 'logo_list_v11';
    $logo_list_block_v11['xml_parameters']['acfData']['shortdescr'] = '1';
    $logo_list_block_v11['yaml_assertions']['page builder logo list shortdescr']['value'] = true;
    $logo_list_block_v11['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v11';
    $entryVariantTypes[] = $logo_list_block_v11;

    // Logo List Block v12 (links = '0')
    $logo_list_block_v12 = $base_logo_list_block;
    $logo_list_block_v12['base_test_name'] = 'logo list block v12';
    $logo_list_block_v12['xml_parameters']['entryVariantType'] = 'logo_list_v12';
    $logo_list_block_v12['xml_parameters']['acfData']['links'] = '0';
    unset($logo_list_block_v12['xml_parameters']['acfData']['links_target']);
    $logo_list_block_v12['yaml_assertions']['page builder logo list links']['value'] = false;
    unset($logo_list_block_v12['yaml_assertions']['page builder logo list links target']);
    $logo_list_block_v12['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v12';
    $entryVariantTypes[] = $logo_list_block_v12;

    // Logo List Block v13 (links_target = '0')
    $logo_list_block_v13 = $base_logo_list_block;
    $logo_list_block_v13['base_test_name'] = 'logo list block v13';
    $logo_list_block_v13['xml_parameters']['entryVariantType'] = 'logo_list_v13';
    $logo_list_block_v13['xml_parameters']['acfData']['links_target'] = '0';
    $logo_list_block_v13['yaml_assertions']['page builder logo list links target']['value'] = false;
    $logo_list_block_v13['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v13';
    $entryVariantTypes[] = $logo_list_block_v13;

    // Logo List Block v14a (order = '["meta_value|ASC"]')
    $logo_list_block_v14a = $base_logo_list_block;
    $logo_list_block_v14a['base_test_name'] = 'logo list block v14a';
    $logo_list_block_v14a['xml_parameters']['entryVariantType'] = 'logo_list_v14a';
    $logo_list_block_v14a['xml_parameters']['acfData']['order'] = '["meta_value|ASC"]';
    $logo_list_block_v14a['yaml_assertions']['page builder logo list sort']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'logo_title',
            'sort_order' => 'asc',
            'enabled' => true,
        ],
    ];
    $logo_list_block_v14a['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v14a';
    $entryVariantTypes[] = $logo_list_block_v14a;

    // Logo List Block v14b (order = '["menu_order|ASC"]')
    $logo_list_block_v14b = $base_logo_list_block;
    $logo_list_block_v14b['base_test_name'] = 'logo list block v14b';
    $logo_list_block_v14b['xml_parameters']['entryVariantType'] = 'logo_list_v14b';
    $logo_list_block_v14b['xml_parameters']['acfData']['order'] = '["menu_order|ASC"]';
    $logo_list_block_v14b['yaml_assertions']['page builder logo list sort']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'order_values',
            'sort_key' => 'default-general',
            'sort_order' => 'asc',
            'enabled' => true,
        ],
    ];
    $logo_list_block_v14b['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v14b';
    $entryVariantTypes[] = $logo_list_block_v14b;

    // Logo List Block v14c (order = '["menu_order|DESC"]')
    $logo_list_block_v14c = $base_logo_list_block;
    $logo_list_block_v14c['base_test_name'] = 'logo list block v14c';
    $logo_list_block_v14c['xml_parameters']['entryVariantType'] = 'logo_list_v14c';
    $logo_list_block_v14c['xml_parameters']['acfData']['order'] = '["menu_order|DESC"]';
    $logo_list_block_v14c['yaml_assertions']['page builder logo list sort']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'order_values',
            'sort_key' => 'default-general',
            'sort_order' => 'desc',
            'enabled' => true,
        ],
    ];
    $logo_list_block_v14c['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v14c';
    $entryVariantTypes[] = $logo_list_block_v14c;

    // Logo List Block v14d (order = '["rand|ASC"]')
    $logo_list_block_v14d = $base_logo_list_block;
    $logo_list_block_v14d['base_test_name'] = 'logo list block v14d';
    $logo_list_block_v14d['xml_parameters']['entryVariantType'] = 'logo_list_v14d';
    $logo_list_block_v14d['xml_parameters']['acfData']['order'] = '["rand|ASC"]';
    $logo_list_block_v14d['yaml_assertions']['page builder logo list sort']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'random',
            'enabled' => true,
        ],
    ];
    $logo_list_block_v14d['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v14d';
    $entryVariantTypes[] = $logo_list_block_v14d;

    // Logo List Block v14e (order = '["title|DESC"]')
    $logo_list_block_v14e = $base_logo_list_block;
    $logo_list_block_v14e['base_test_name'] = 'logo list block v14e';
    $logo_list_block_v14e['xml_parameters']['entryVariantType'] = 'logo_list_v14e';
    $logo_list_block_v14e['xml_parameters']['acfData']['order'] = '["title|DESC"]';
    $logo_list_block_v14e['yaml_assertions']['page builder logo list sort']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'title',
            'sort_order' => 'desc',
            'enabled' => true,
        ],
    ];
    $logo_list_block_v14e['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v14e';
    $entryVariantTypes[] = $logo_list_block_v14e;

    // Logo List Block v14f (order = '["rand|DESC"]')
    $logo_list_block_v14f = $base_logo_list_block;
    $logo_list_block_v14f['base_test_name'] = 'logo list block v14f';
    $logo_list_block_v14f['xml_parameters']['entryVariantType'] = 'logo_list_v14f';
    $logo_list_block_v14f['xml_parameters']['acfData']['order'] = '["rand|DESC"]';
    $logo_list_block_v14f['yaml_assertions']['page builder logo list sort']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'random',
            'enabled' => true,
        ],
    ];
    $logo_list_block_v14f['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v14f';
    $entryVariantTypes[] = $logo_list_block_v14f;

    // Logo List Block v15a (order = '["menu_order|DESC","title|DESC"]')
    $logo_list_block_v15a = $base_logo_list_block;
    $logo_list_block_v15a['base_test_name'] = 'logo list block v15a';
    $logo_list_block_v15a['xml_parameters']['entryVariantType'] = 'logo_list_v15a';
    $logo_list_block_v15a['xml_parameters']['acfData']['order'] = '["menu_order|DESC","title|DESC"]';
    $logo_list_block_v15a['yaml_assertions']['page builder logo list sort']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'order_values',
            'sort_key' => 'default-general',
            'sort_order' => 'desc',
            'enabled' => true,
        ],
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'title',
            'sort_order' => 'desc',
            'enabled' => true,
        ],
    ];
    $logo_list_block_v15a['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v15a';
    $entryVariantTypes[] = $logo_list_block_v15a;

    // Logo List Block v15b (order = '["title|ASC","menu_order|ASC","rand|ASC"]')
    $logo_list_block_v15b = $base_logo_list_block;
    $logo_list_block_v15b['base_test_name'] = 'logo list block v15b';
    $logo_list_block_v15b['xml_parameters']['entryVariantType'] = 'logo_list_v15b';
    $logo_list_block_v15b['xml_parameters']['acfData']['order'] = '["title|ASC","menu_order|ASC","rand|ASC"]';
    $logo_list_block_v15b['yaml_assertions']['page builder logo list sort']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'enabled' => true,
        ],
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'order_values',
            'sort_key' => 'default-general',
            'sort_order' => 'asc',
            'enabled' => true,
        ],
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'random',
            'enabled' => true,
        ],
    ];
    $logo_list_block_v15b['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v15b';
    $entryVariantTypes[] = $logo_list_block_v15b;

    // Logo List Block v15c (order = '["meta_value|ASC","menu_order|DESC","title|ASC","rand|ASC"]')
    $logo_list_block_v15c = $base_logo_list_block;
    $logo_list_block_v15c['base_test_name'] = 'logo list block v15c';
    $logo_list_block_v15c['xml_parameters']['entryVariantType'] = 'logo_list_v15c';
    $logo_list_block_v15c['xml_parameters']['acfData']['order'] = '["meta_value|ASC","menu_order|DESC","title|ASC","rand|ASC"]';
    $logo_list_block_v15c['yaml_assertions']['page builder logo list sort']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'logo_title',
            'sort_order' => 'asc',
            'enabled' => true,
        ],
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'order_values',
            'sort_key' => 'default-general',
            'sort_order' => 'desc',
            'enabled' => true,
        ],
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'title',
            'sort_order' => 'asc',
            'enabled' => true,
        ],
        [
            'id' => '#rowId(8)',
            'type' => 'sort_option',
            'sort_by' => 'random',
            'enabled' => true,
        ],
    ];
    $logo_list_block_v15c['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v15c';
    $entryVariantTypes[] = $logo_list_block_v15c;

    // Logo List Block v16 (max = '2')
    $logo_list_block_v16 = $base_logo_list_block;
    $logo_list_block_v16['base_test_name'] = 'logo list block v16';
    $logo_list_block_v16['xml_parameters']['entryVariantType'] = 'logo_list_v16';
    $logo_list_block_v16['xml_parameters']['acfData']['max'] = '2';
    // ---
    // The handle is `logo_list_max`, not `max`. `LogoListParser` reads the ACF
    // attribute `block_logo_list_max` and writes `logo_list_max`, matching
    // `handle: logo_list_max` in the host app's `uamsweb_block_logo_list.yaml`
    // fieldset and its `logo_list_*` siblings. `page_builder.0.max` never existed,
    // so `getNestedValue()` returned null and this row failed rather than
    // asserting anything (wordpress-importer-archive#482). It is an integer, because the parser casts
    // through `getOptionalIntAttribute()`.
    // ---
    $logo_list_block_v16['yaml_assertions']['page builder max'] = [
        'field' => 'page_builder.0.logo_list_max',
        'value' => 2,
        'type' => 'integer',
    ];
    $logo_list_block_v16['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v16';
    $entryVariantTypes[] = $logo_list_block_v16;

    // Logo List Block v17a (more = '1'; more_button_target = '1')
    $logo_list_block_v17a = $base_logo_list_block;
    $logo_list_block_v17a['base_test_name'] = 'logo list block v17a';
    $logo_list_block_v17a['xml_parameters']['entryVariantType'] = 'logo_list_v17a';
    $logo_list_block_v17a['xml_parameters']['acfData']['more'] = '1';
    $logo_list_block_v17a['xml_parameters']['acfData']['more_text'] = 'Lorem ipsum dolor sit amet';
    $logo_list_block_v17a['xml_parameters']['acfData']['more_button_text'] = 'Aenean commodo';
    $logo_list_block_v17a['xml_parameters']['acfData']['more_button_url'] = [
        'url' => 'https://www.google.com/',
        'target' => '_blank',
    ];
    $logo_list_block_v17a['xml_parameters']['acfData']['more_button_description'] = 'Ligula eget dolor';
    $logo_list_block_v17a['yaml_assertions']['page builder more query']['value'] = true;
    $logo_list_block_v17a['yaml_assertions']['page builder more heading'] = [
        'field' => 'page_builder.0.more_heading',
        'value' => 'Lorem ipsum dolor sit amet',
        'type' => 'string',
    ];
    $logo_list_block_v17a['yaml_assertions']['page builder more button text'] = [
        'field' => 'page_builder.0.more_button_text',
        'value' => 'Aenean commodo',
        'type' => 'string',
    ];
    $logo_list_block_v17a['yaml_assertions']['page builder more button url'] = [
        'field' => 'page_builder.0.more_button_url',
        'value' => 'https://www.google.com/',
        'type' => 'string',
    ];
    $logo_list_block_v17a['yaml_assertions']['page builder more button target'] = [
        'field' => 'page_builder.0.more_button_target',
        'value' => true,
        'type' => 'boolean',
    ];
    $logo_list_block_v17a['yaml_assertions']['page builder more button link description'] = [
        'field' => 'page_builder.0.more_button_link_description',
        'value' => 'Ligula eget dolor',
        'type' => 'string',
    ];
    $logo_list_block_v17a['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v17a';
    $entryVariantTypes[] = $logo_list_block_v17a;

    // Logo List Block v17b (more = '1'; more_button_target = '0')
    $logo_list_block_v17b = $logo_list_block_v17a;
    $logo_list_block_v17b['base_test_name'] = 'logo list block v17b';
    $logo_list_block_v17b['xml_parameters']['entryVariantType'] = 'logo_list_v17b';
    $logo_list_block_v17b['xml_parameters']['acfData']['more_button_url'] = [
        'url' => 'https://www.google.com/',
        'target' => '',
    ];
    $logo_list_block_v17b['yaml_assertions']['page builder more button target']['value'] = false;
    $logo_list_block_v17b['yaml_assertions']['slug']['value'] = 'logo-list-page-entry-v17b';
    $entryVariantTypes[] = $logo_list_block_v17b;

    // Loop through entry variant types and add to test cases
    foreach ($entryVariantTypes as $entryVariantType) {
        $baseTestName = $entryVariantType['base_test_name'];

        // Collect every valid field assertion for this variant into a single case so
        // the import runs once per variant rather than once per field. Each field is
        // still asserted individually inside the test (wordpress-importer-archive#391).
        $fieldAssertions = [];

        foreach ($entryVariantType['yaml_assertions'] as $fieldName => $assertion) {
            if (
                ! \is_array($assertion)
                || ! isset($assertion['field'], $assertion['value'], $assertion['type'])
            ) {
                continue;
            }

            $fieldAssertions[] = [
                'field' => $assertion['field'],
                'value' => $assertion['value'],
                'type' => $assertion['type'],
            ];
        }

        $testCases[$baseTestName] = [
            'fieldAssertions' => $fieldAssertions,
            'postType' => $entryVariantType['xml_parameters']['postType'],
            'entryVariantType' => $entryVariantType['xml_parameters']['entryVariantType'],
            'uniqueSlug' => $entryVariantType['xml_parameters']['uniqueSlug'],
            'acfName' => $entryVariantType['xml_parameters']['acfName'],
            'acfData' => $entryVariantType['xml_parameters']['acfData'],
        ];
    }

    return $testCases;
}

/**
 * Assemble the page-builder WXR content from the resolved header, footer, unique
 * slug, and merged ACF data.
 *
 * Pure — the trait-bound header, footer, and resolved unique slug are passed in
 * so the assembly needs no test-case instance.
 *
 * @param  array<string, mixed>  $acfData
 */
function pageBuilderXmlContent(
    string $header,
    string $footer,
    string $uniqueSlug,
    string $acfName,
    array $acfData
): string {
    // Build ACF block content dynamically using parameters
    $contentEncoded = pageBuilderAcfBlockContent($acfName, $acfData);

    // Get the XML header
    $xmlContent = $header;

    // Add logo list labels
    $xmlContent .= '        <wp:term>
            <wp:term_id>100</wp:term_id>
            <wp:term_taxonomy><![CDATA[logo-list-label]]></wp:term_taxonomy>
            <wp:term_slug><![CDATA[foo-bar]]></wp:term_slug>
            <wp:term_parent><![CDATA[]]></wp:term_parent>
            <wp:term_name><![CDATA[Foo Bar]]></wp:term_name>
            <wp:term_description><![CDATA[Lorem ipsum dolor sit amet, consectetuer adipiscing elit.]]></wp:term_description>
        </wp:term>';

    // Add logo list categories
    $xmlContent .= '        <wp:term>
            <wp:term_id>200</wp:term_id>
            <wp:term_taxonomy><![CDATA[logo-list-category]]></wp:term_taxonomy>
            <wp:term_slug><![CDATA[baz-qux]]></wp:term_slug>
            <wp:term_parent><![CDATA[]]></wp:term_parent>
            <wp:term_name><![CDATA[Baz Qux]]></wp:term_name>
            <wp:term_description><![CDATA[Aenean commodo ligula eget dolor.]]></wp:term_description>
        </wp:term>';

    // Add page with page builder block content
    $xmlContent .= '        <item>
            <title><![CDATA[Sample Page]]></title>
            <link>https://communications.uams.edu/web/'.$uniqueSlug.'/</link>
            <pubDate>Mon, 05 May 2025 15:41:23 +0000</pubDate>
            <dc:creator><![CDATA[testuser]]></dc:creator>
            <guid isPermaLink="false">https://communications.uams.edu/web/?post_type=page&#038;p=123</guid>
            <description></description>
            <content:encoded><![CDATA['.$contentEncoded.']]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>123</wp:post_id>
            <wp:post_date><![CDATA[2025-05-05 15:41:23]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2025-05-05 15:47:31]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2025-05-15 14:52:14]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2025-05-15 14:52:14]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA['.$uniqueSlug.']]></wp:post_name>
            <wp:status><![CDATA[publish]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[page]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
        </item>';

    // Add attachment for general image
    $xmlContent .= '        <item>
            <title><![CDATA[fpo_200.jpg]]></title>
            <link>https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_200.jpg</link>
            <pubDate>Wed, 12 Jun 2024 15:10:35 +0000</pubDate>
            <dc:creator><![CDATA[testuser]]></dc:creator>
            <guid isPermaLink="false">https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_200.jpg</guid>
            <description></description>
            <content:encoded><![CDATA[]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>456</wp:post_id>
            <wp:post_date><![CDATA[2024-06-12 15:10:35]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2024-06-12 15:10:35]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-06-12 15:10:35]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-06-12 15:10:35]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[fpo_200]]></wp:post_name>
            <wp:status><![CDATA[inherit]]></wp:status>
            <wp:post_parent>123</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[attachment]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            <wp:attachment_url><![CDATA[https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_200.jpg]]></wp:attachment_url>
        </item>';

    $xmlContent .= $footer;

    return $xmlContent;
}

/**
 * Build ACF block content dynamically using parameters.
 *
 * @param  string  $acfName  The ACF block name (e.g., 'acf/logo-list')
 * @param  array<string, mixed>  $acfData  Array of ACF field values
 * @return string The ACF block content
 */
function pageBuilderAcfBlockContent(
    string $acfName = 'acf/logo-list',
    array $acfData = []
): string {
    // Build the ACF block data array dynamically
    $data = [];

    foreach ($acfData as $key => $value) {
        // Add the main field with block_logo_list_ prefix
        $fieldKey = "block_logo_list_{$key}";

        // Handle JSON-encoded values (arrays)
        if (
            \is_string($value)
            && (
                \str_starts_with(
                    $value,
                    '['
                )
                || \str_starts_with(
                    $value,
                    '{'
                )
            )
        ) {
            $data[$fieldKey] = \json_decode(
                $value,
                true
            );
        } else {
            $data[$fieldKey] = $value;
        }

        // Add the corresponding field reference
        $data["_{$fieldKey}"] = "field_{$fieldKey}";
    }

    // Build the ACF block JSON data
    $acfBlockData = [
        'name' => $acfName,
        'data' => $data,
        'align' => '',
        'mode' => 'preview',
    ];

    // Convert to JSON and create the ACF block comment
    $jsonData = \json_encode(
        $acfBlockData,
        JSON_UNESCAPED_UNICODE
    );

    return "<!-- wp:{$acfName} {$jsonData} /-->";

}

/**
 * Get default values for different block variant types.
 *
 * @param  string  $entryVariantType  The type of block variant
 * @return array<string, string> Default values for the block variant type
 */
function pageBuilderEntryVariantTypeDefaults(
    string $entryVariantType
): array {
    $defaults = [
        'standard_v01' => [
            'heading' => 'Sample Logo List Block',
            'hide_heading' => '0',
            'description' => 'This is a sample logo list block for testing purposes.',
            'background_color' => 'bg-white',
            'filter_ids' => '["200"]',
            'filter_logic' => 'IN',
            'labels_ids' => '["100"]',
            'labels_logic' => 'IN',
            'featured' => '0',
            'layout' => 'grid',
            'width' => 'alignfull',
            'titles' => '0',
            'labels' => '1',
            'shortdescr' => '0',
            'links' => '1',
            'links_target' => '1',
            'order' => '["title|ASC"]',
            'max' => '',
            'more' => '0',
            'more_text' => '',
            'more_button_text' => '',
            'more_button_url' => '',
            'more_button_description' => '',
        ],
    ];

    return $defaults[$entryVariantType] ?? $defaults['standard_v01'];

}

/**
 * Read a string parameter from a loosely typed parameter bag.
 *
 * @param  array<mixed>  $params
 */
function pageBuilderStringParam(
    array $params,
    string $key,
    string $fallback
): string {
    $value = $params[$key] ?? $fallback;

    return \is_string($value) ? $value : $fallback;
}

/**
 * Read a nullable string parameter from a loosely typed parameter bag.
 *
 * @param  array<mixed>  $params
 */
function pageBuilderNullableStringParam(
    array $params,
    string $key
): ?string {
    $value = $params[$key] ?? null;

    if ($value === null) {
        return null;
    }

    return \is_string($value) ? $value : null;
}

/**
 * Normalize a loosely typed ACF data bag to string-keyed entries.
 *
 * @param  array<mixed>  $acfData
 * @return array<string, mixed>
 */
function pageBuilderNormalizeAcfData(
    array $acfData
): array {
    $normalized = [];

    foreach ($acfData as $key => $value) {
        if (\is_string($key)) {
            $normalized[$key] = $value;
        }
    }

    return $normalized;
}

/**
 * Return the page_builder array from a ContentProcessor result.
 *
 * @param  array<int|string, mixed>  $result
 * @return array<int|string, mixed>
 */
function pageBuilderRequirePageBuilder(
    array $result
): array {
    expect($result)->toHaveKey('page_builder');

    $pageBuilder = $result['page_builder'] ?? null;
    if (! is_array($pageBuilder)) {
        throw new RuntimeException('Expected the page_builder result to be an array.');
    }

    return $pageBuilder;
}

/**
 * Return the first page_builder block after asserting the list is non-empty.
 *
 * @param  array<int|string, mixed>  $pageBuilder
 * @return array<mixed>
 */
function pageBuilderRequireFirstBlock(
    array $pageBuilder
): array {
    expect($pageBuilder)->not->toBeEmpty();

    $block = $pageBuilder[0] ?? null;
    expect($block)->toBeArray();
    if (! is_array($block)) {
        throw new RuntimeException('Expected the first page_builder block to be an array.');
    }

    return $block;
}
