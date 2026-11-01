<?php

declare(strict_types=1);

use Statamic\Entries\Collection;
use Statamic\Entries\Entry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Stache;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

// Guard the live `content/` submodule: capture it before each test and refuse
// to leave anything behind afterwards (wordpress-importer-archive#570).
beforeEach(function (): void {
    $this->captureSubmoduleStates();

    // ---
    // Categories and labels both import as entries in site-scoped collections,
    // with their trees under the default site's tree directory (wordpress-importer-archive#13, wordpress-importer-archive#1220).
    // Neither is reset by the slug-list cleanup below, so they are restored
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
                'sample-entry',
                'standard-logo-list-item-entry-v01',
            ],
            'logo_list_items', // collection handle
            'default', // site handle
            [
                'logos_unrestricted' => [
                    '2020-05-fpo_200.jpg',
                ],
            ],
            [
                'logos_item_categories::default_baz-qux',
            ],
            false // Don't restore tree files for LogoListItem tests
        );
    } catch (Exception $e) {
        error_log('LogoListItemImportTest cleanup error: '.$e->getMessage());
    }

    // Restore the snapshotted collections and trees so imported category and
    // label entries don't leak into the next test (wordpress-importer-archive#1220).
    $this->restoreIsolatedState();

    // Last, so it judges what the cleanup above did NOT put back (wordpress-importer-archive#570).
    $this->assertSubmodulesUnchanged();
});

/**
 * Unit tests for LogoListItemEntryCreator.
 *
 * Tests the logo list item import functionality, including:
 *   - Logo list item entry creation and mapping
 *   - Logo image import and processing
 *   - Logo metadata handling
 *   - Content processing and organization
 *
 * Note: This functionality is currently marked as "work in progress" (wip)
 * in the WordPress exports configuration and the actual LogoListItemEntryCreator
 * class has not been implemented yet.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/LogoListItemImportTest.php
 */
it('points logos_item_categories at a collection entry, not a taxonomy term', function (): void {
    // `UAMS-Web/uams-statamic-archive#1863` converted `logos_item_categories` into a
    // structured collection, and the blueprint field is `type: entries`. The
    // importer used to write `logos_item_categories::default_baz-qux`, which that
    // fieldtype cannot resolve. This pins the entry-id shape and, more
    // importantly, that the id resolves to the right entry — an id-shaped string
    // pointing at nothing would satisfy a shape-only assertion (wordpress-importer-archive#13).
    $xmlParameters = [
        'postType' => 'logo-list-item',
        'entryVariantType' => 'standard_v01',
    ];

    $xmlGeneratorCallback = function (array $params): string {
        $postType = logoListItemStringParam($params, 'postType', 'logo-list-item');
        $entryVariantType = logoListItemStringParam($params, 'entryVariantType', 'standard_v01');

        $uniqueSlug = logoListItemNullableStringParam($params, 'uniqueSlug')
                ?? $this->getUniqueSlugForEntryVariant($postType, $entryVariantType);

        $xmlContent = logoListItemXmlContent(
            $this->getXmlHeader(),
            $this->getXmlFooter(),
            $uniqueSlug,
            $params
        );

        $xmlPath = $this->createTempFile('test_logo_list_item_');
        file_put_contents($xmlPath, $xmlContent);

        return $xmlPath;
    };

    $entry = $this->generateAndImportFromXmlCallback(
        $xmlParameters,
        $xmlGeneratorCallback
    );

    $categories = $entry->get('logos_item_categories');

    expect($categories)->toBeArray()->toHaveCount(1);

    $categoryId = is_array($categories) ? ($categories[0] ?? null) : null;

    expect($categoryId)->toBeString()
        ->and($categoryId)->not->toContain('::');

    // Resolve it: the reference must reach a real entry in the destination
    // collection, carrying the WordPress term's slug un-prefixed.
    $categoryEntry = is_string($categoryId) ? EntryFacade::find($categoryId) : null;

    expect($categoryEntry)->toBeInstanceOf(Entry::class);

    if ($categoryEntry instanceof Entry) {
        $categoryCollection = $categoryEntry->collection();

        expect($categoryCollection)->toBeInstanceOf(Collection::class);

        if ($categoryCollection instanceof Collection) {
            expect($categoryCollection->handle())->toBe('logos_item_categories');
        }

        expect($categoryEntry->slug())->toBe('baz-qux');
    }
});

it('points logos_item_labels at a plainly titled collection entry on the imported site', function (): void {
    // `UAMS-Web/uams-statamic-archive#2533` converted `logos_item_labels` into a
    // site-scoped collection, and the blueprint field is `type: entries`. The
    // importer used to write `logos_item_labels::default_foo-bar`, which that
    // fieldtype cannot resolve (wordpress-importer-archive#1220). As for categories above, the id has to
    // resolve to the right entry, not merely look like an id.
    // ---
    // `generateAndImportFromXmlCallback()` caches an import by its parameters,
    // so without a key of its own this test would be handed the category test's
    // import, whose label entry that test's snapshot restore has since removed.
    // `caseKey` is read by nothing but the cache key.
    // ---
    $xmlParameters = [
        'postType' => 'logo-list-item',
        'entryVariantType' => 'standard_v01',
        'caseKey' => 'labels',
    ];

    $xmlGeneratorCallback = function (array $params): string {
        $postType = logoListItemStringParam($params, 'postType', 'logo-list-item');
        $entryVariantType = logoListItemStringParam($params, 'entryVariantType', 'standard_v01');

        $uniqueSlug = logoListItemNullableStringParam($params, 'uniqueSlug')
                ?? $this->getUniqueSlugForEntryVariant($postType, $entryVariantType);

        $xmlContent = logoListItemXmlContent(
            $this->getXmlHeader(),
            $this->getXmlFooter(),
            $uniqueSlug,
            $params
        );

        $xmlPath = $this->createTempFile('test_logo_list_item_');
        file_put_contents($xmlPath, $xmlContent);

        return $xmlPath;
    };

    $entry = $this->generateAndImportFromXmlCallback(
        $xmlParameters,
        $xmlGeneratorCallback
    );

    $labels = $entry->get('logos_item_labels');

    expect($labels)->toBeArray()->toHaveCount(1);

    $labelId = is_array($labels) ? ($labels[0] ?? null) : null;

    expect($labelId)->toBeString()
        ->and($labelId)->not->toContain('::');

    $labelEntry = is_string($labelId) ? EntryFacade::find($labelId) : null;

    expect($labelEntry)->toBeInstanceOf(Entry::class);

    if ($labelEntry instanceof Entry) {
        $labelCollection = $labelEntry->collection();

        expect($labelCollection)->toBeInstanceOf(Collection::class);

        if ($labelCollection instanceof Collection) {
            expect($labelCollection->handle())->toBe('logos_item_labels');
        }

        // The plain WordPress slug and name, on the site the item imported into
        expect($labelEntry->slug())->toBe('foo-bar')
            ->and($labelEntry->get('title'))->toBe('Foo Bar')
            ->and($labelEntry->locale())->toBe('default');
    }
});

it('creates a logo list item entry with the expected slug and original WordPress URL', function (): void {
    // Create XML parameters for standard logo list item entry
    $xmlParameters = [
        'postType' => 'logo-list-item',
        'entryVariantType' => 'standard_v01',
    ];

    // Create a callback function that generates the logo-list-item XML
    $xmlGeneratorCallback = function (array $params): string {
        $postType = logoListItemStringParam($params, 'postType', 'logo-list-item');
        $entryVariantType = logoListItemStringParam($params, 'entryVariantType', 'standard_v01');

        // Resolve the unique slug, falling back to the trait's variant slug
        $uniqueSlug = logoListItemNullableStringParam($params, 'uniqueSlug')
                ?? $this->getUniqueSlugForEntryVariant($postType, $entryVariantType);

        $xmlContent = logoListItemXmlContent(
            $this->getXmlHeader(),
            $this->getXmlFooter(),
            $uniqueSlug,
            $params
        );

        $xmlPath = $this->createTempFile('test_logo_list_item_');
        file_put_contents(
            $xmlPath,
            $xmlContent
        );

        return $xmlPath;
    };

    // Use the trait method to generate and import from XML
    $entry = $this->generateAndImportFromXmlCallback(
        $xmlParameters,
        $xmlGeneratorCallback
    );

    // The entry should have a string ID
    expect($entry->id())->toBeString();

    // The entry should have a string slug
    expect($entry->slug())->toBeString();

    // The entry should have a string title
    $title = $entry->get('title');
    expect($title)->toBeString();

    // The entry data should be an array
    $entryData = $entry->data();
    expect($entryData)->toBeArray();
    if (! is_array($entryData)) {
        throw new RuntimeException('Expected the entry data to be an array.');
    }

    // We actually created an entry with content (not just empty data)
    expect($entryData)->not->toBeEmpty();

    // The entry has the expected slug for a standard logo list item
    $expectedSlug = $this->getUniqueSlugForEntryVariant(
        'logo-list-item',
        'standard_v01'
    );
    expect($entry->slug())->toEqual($expectedSlug);

    // The entry has a non-empty original_wordpress_url field
    expect($entryData)->toHaveKey('original_wordpress_url');
    expect($entryData['original_wordpress_url'])->toBeString();
    expect($entryData['original_wordpress_url'])->not->toBeEmpty();

    // Clean up variables to free up memory
    unset(
        $xmlParameters,
        $xmlGeneratorCallback,
        $entry,
        $entryData,
        $expectedSlug
    );
});

it('validates each mapped YAML field for a logo list item entry', function (
    string $field,
    mixed $value,
    string $type,
    string $postType,
    string $entryVariantType,
    ?string $menuOrder = null,
    ?string $logoTitle = null,
    ?string $logoImage = null,
    ?string $logoLink = null,
    ?string $logoLinkText = null,
    ?string $logoLinkTarget = null,
    ?string $logoCategory = null,
    ?string $logoLabel = null,
    ?string $logoShortdescr = null,
    ?string $logoLongdescr = null,
    ?string $uniqueSlug = null
): void {
    // Create XML parameters array
    $xmlParameters = [
        'postType' => $postType,
        'entryVariantType' => $entryVariantType,
        'menuOrder' => $menuOrder,
        'logoTitle' => $logoTitle,
        'logoImage' => $logoImage,
        'logoLink' => $logoLink,
        'logoLinkText' => $logoLinkText,
        'logoLinkTarget' => $logoLinkTarget,
        'logoCategory' => $logoCategory,
        'logoLabel' => $logoLabel,
        'logoShortdescr' => $logoShortdescr,
        'logoLongdescr' => $logoLongdescr,
        'uniqueSlug' => $uniqueSlug,
    ];

    // Create a callback function that generates the logo-list-item XML
    $xmlGeneratorCallback = function (array $params): string {
        $postType = logoListItemStringParam($params, 'postType', 'logo-list-item');
        $entryVariantType = logoListItemStringParam($params, 'entryVariantType', 'standard_v01');

        // Resolve the unique slug, falling back to the trait's variant slug
        $uniqueSlug = logoListItemNullableStringParam($params, 'uniqueSlug')
                ?? $this->getUniqueSlugForEntryVariant($postType, $entryVariantType);

        $xmlContent = logoListItemXmlContent(
            $this->getXmlHeader(),
            $this->getXmlFooter(),
            $uniqueSlug,
            $params
        );

        $xmlPath = $this->createTempFile('test_logo_list_item_');
        file_put_contents(
            $xmlPath,
            $xmlContent
        );

        return $xmlPath;
    };

    // Call the trait method with XML parameters and callback
    $this->testIndividualYamlFieldValidation(
        $field,
        $value,
        $type,
        $xmlParameters,
        $xmlGeneratorCallback
    );
})->with(logoListItemYamlFieldValidationProvider());

/**
 * Data provider for individual YAML field validation tests.
 *
 * Each field is tested separately to make it easier to identify
 * which specific fields are failing during import validation.
 *
 * @return array<string, array<string, mixed>> Test cases keyed by label
 */
function logoListItemYamlFieldValidationProvider(): array
{
    $base = [
        'xml_parameters' => [
            'postType' => 'logo-list-item',
            'entryVariantType' => 'standard_v01',
            'menuOrder' => null,
            'logoTitle' => null,
            'logoImage' => null,
            'logoLink' => null,
            'logoLinkText' => null,
            'logoLinkTarget' => null,
            'logoCategory' => null,
            'logoLabel' => null,
            'logoShortdescr' => null,
            'logoLongdescr' => null,
            'uniqueSlug' => null,
        ],
        'yaml_assertions' => [
            'blueprint' => [
                'field' => 'blueprint',
                'value' => 'logo_list_item',
                'type' => 'string',
            ],
            'title' => [
                'field' => 'title',
                'value' => 'Sample Entry',
                'type' => 'string',
            ],
            'slug' => [
                'field' => 'slug',
                'value' => 'standard-logo-list-item-entry-v01',
                'type' => 'string',
            ],
            'logo title' => [
                'field' => 'logo_title',
                'value' => 'Sample Logo List Item',
                'type' => 'string',
            ],
            'logo image' => [
                'field' => 'logo_image',
                'value' => 'default/2020-05-fpo_200.jpg',
                'type' => 'string',
            ],
            'logo link' => [
                'field' => 'logo_link',
                'value' => 'https://statamic.dev/',
                'type' => 'string',
            ],
            'logo link text' => [
                'field' => 'logo_link_text',
                'value' => 'Lorem ipsum dolor sit amet',
                'type' => 'string',
            ],
            'logo link target' => [
                'field' => 'logo_link_target',
                'value' => true,
                'type' => 'boolean',
            ],
            // `logo category` is asserted separately: `logos_item_categories`
            // became a structured collection in `UAMS-Web/uams-statamic-archive#1863`, so
            // the field holds a generated entry id rather than a fixed
            // `handle::slug` taxonomy reference and cannot be a literal here.
            // A `logos_item_labels` entry id, generated per import, so matched by
            // shape; the entry it resolves to is pinned separately (wordpress-importer-archive#1220)
            'logo label' => [
                'field' => 'logos_item_labels',
                'value' => ['#rowId(36)'],
                'type' => 'array',
            ],
            'logo short description' => [
                'field' => 'logo_shortdescr',
                'value' => 'Aenean massa.',
                'type' => 'string',
            ],
            'logo long description' => [
                'field' => 'logo_longdescr',
                'value' => [
                    [
                        'type' => 'paragraph',
                        'content' => [
                            [
                                'type' => 'text',
                                'text' => 'Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.',
                            ],
                        ],
                    ],
                ],
                'type' => 'array',
            ],
            'menu order' => [
                'field' => 'order_values',
                'value' => [
                    'default-general' => '42',
                ],
                'type' => 'array',
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

    // Standard Logo List Item v01
    $standard_logo_list_item_v01 = $base;
    $standard_logo_list_item_v01['base_test_name'] = 'standard logo list item v01';

    $testCases = [];
    $entryVariantTypes = [$standard_logo_list_item_v01];

    foreach ($entryVariantTypes as $entryVariantType) {
        $baseTestName = $entryVariantType['base_test_name'];

        foreach ($entryVariantType['yaml_assertions'] as $fieldName => $assertion) {
            $testCases["{$baseTestName} {$fieldName}"] = [
                'field' => $assertion['field'],
                'value' => $assertion['value'],
                'type' => $assertion['type'],
                'postType' => $entryVariantType['xml_parameters']['postType'],
                'entryVariantType' => $entryVariantType['xml_parameters']['entryVariantType'],
                'menuOrder' => $entryVariantType['xml_parameters']['menuOrder'],
                'logoTitle' => $entryVariantType['xml_parameters']['logoTitle'],
                'logoImage' => $entryVariantType['xml_parameters']['logoImage'],
                'logoLink' => $entryVariantType['xml_parameters']['logoLink'],
                'logoLinkText' => $entryVariantType['xml_parameters']['logoLinkText'],
                'logoLinkTarget' => $entryVariantType['xml_parameters']['logoLinkTarget'],
                'logoCategory' => $entryVariantType['xml_parameters']['logoCategory'],
                'logoLabel' => $entryVariantType['xml_parameters']['logoLabel'],
                'logoShortdescr' => $entryVariantType['xml_parameters']['logoShortdescr'],
                'logoLongdescr' => $entryVariantType['xml_parameters']['logoLongdescr'],
                'uniqueSlug' => $entryVariantType['xml_parameters']['uniqueSlug'],
            ];
        }
    }

    return $testCases;
}

/**
 * Assemble the logo-list-item WXR content from a loosely typed parameter bag.
 *
 * Pure — the trait-bound header, footer, and resolved unique slug are passed in
 * so the assembly needs no test-case instance.
 *
 * @param  array<mixed>  $params
 */
function logoListItemXmlContent(
    string $header,
    string $footer,
    string $uniqueSlug,
    array $params
): string {
    // Set default values based on entry variant type
    $entryVariantType = logoListItemStringParam($params, 'entryVariantType', 'standard_v01');
    $defaults = logoListItemVariantDefaults($entryVariantType);

    $menuOrder = logoListItemCoalesceDefault(logoListItemNullableStringParam($params, 'menuOrder'), $defaults, 'menu_order');
    $logoTitle = logoListItemCoalesceDefault(logoListItemNullableStringParam($params, 'logoTitle'), $defaults, 'logo_title');
    $logoImage = logoListItemCoalesceDefault(logoListItemNullableStringParam($params, 'logoImage'), $defaults, 'logo_image');
    $logoLink = logoListItemCoalesceDefault(logoListItemNullableStringParam($params, 'logoLink'), $defaults, 'logo_link');
    $logoLinkText = logoListItemCoalesceDefault(logoListItemNullableStringParam($params, 'logoLinkText'), $defaults, 'logo_link_text');
    $logoLinkTarget = logoListItemCoalesceDefault(logoListItemNullableStringParam($params, 'logoLinkTarget'), $defaults, 'logo_link_target');
    $logoCategory = logoListItemCoalesceDefault(logoListItemNullableStringParam($params, 'logoCategory'), $defaults, 'logo_category');
    $logoLabel = logoListItemCoalesceDefault(logoListItemNullableStringParam($params, 'logoLabel'), $defaults, 'logo_label');
    $logoShortdescr = logoListItemCoalesceDefault(logoListItemNullableStringParam($params, 'logoShortdescr'), $defaults, 'logo_shortdescr');
    $logoLongdescr = logoListItemCoalesceDefault(logoListItemNullableStringParam($params, 'logoLongdescr'), $defaults, 'logo_longdescr');

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

    // Add logo list item with full metadata structure
    $xmlContent .= '        <item>
            <title><![CDATA[Sample Entry]]></title>
            <link>https://communications.uams.edu/web/logo-list-item/sample-entry/</link>
            <pubDate>Mon, 05 May 2025 15:41:23 +0000</pubDate>
            <dc:creator><![CDATA[testuser]]></dc:creator>
            <guid isPermaLink="false">https://communications.uams.edu/web/?post_type=logo-list-item&#038;p=123</guid>
            <description></description>
            <content:encoded><![CDATA[]]></content:encoded>
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
            <wp:menu_order>'.$menuOrder.'</wp:menu_order>
            <wp:post_type><![CDATA[logo-list-item]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            <category domain="logo-list-label" nicename="foo-bar"><![CDATA[Foo Bar]]></category>
            <category domain="logo-list-category" nicename="baz-qux"><![CDATA[Baz Qux]]></category>
            <wp:postmeta>
                <wp:meta_key><![CDATA[logo_title]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$logoTitle.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_logo_title]]></wp:meta_key>
                <wp:meta_value><![CDATA[field_logo_title]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[logo_image]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$logoImage.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_logo_image]]></wp:meta_key>
                <wp:meta_value><![CDATA[field_logo_image]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[logo_link]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$logoLink.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_logo_link]]></wp:meta_key>
                <wp:meta_value><![CDATA[field_logo_link]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[logo_link_text]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$logoLinkText.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_logo_link_text]]></wp:meta_key>
                <wp:meta_value><![CDATA[field_logo_link_text]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[logo_link_target]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$logoLinkTarget.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_logo_link_target]]></wp:meta_key>
                <wp:meta_value><![CDATA[field_logo_link_target]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[logo_category]]></wp:meta_key>
                <wp:meta_value><![CDATA[a:1:{i:0;s:'.\strlen($logoCategory).':"'.$logoCategory.'";}]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_logo_category]]></wp:meta_key>
                <wp:meta_value><![CDATA[field_logo_category]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[logo_label]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$logoLabel.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_logo_label]]></wp:meta_key>
                <wp:meta_value><![CDATA[field_logo_label]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[logo_shortdescr]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$logoShortdescr.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_logo_shortdescr]]></wp:meta_key>
                <wp:meta_value><![CDATA[field_logo_shortdescr]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[logo_longdescr]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$logoLongdescr.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_logo_longdescr]]></wp:meta_key>
                <wp:meta_value><![CDATA[field_logo_longdescr]]></wp:meta_value>
            </wp:postmeta>
        </item>';

    // Add attachment for logo image
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
 * Get default values for different entry variant types.
 *
 * @param  string  $entryVariantType  The type of entry variant
 * @return array<string, string|null> Default values for the entry variant type
 */
function logoListItemVariantDefaults(
    string $entryVariantType
): array {
    $defaults = [
        'standard_v01' => [
            'menu_order' => '42',
            'logo_title' => 'Sample Logo List Item',
            'logo_image' => '456',
            'logo_link' => 'https://statamic.dev/',
            'logo_link_text' => 'Lorem ipsum dolor sit amet',
            'logo_link_target' => '_blank',
            'logo_category' => '200',
            'logo_label' => '100',
            'logo_shortdescr' => 'Aenean massa.',
            'logo_longdescr' => 'Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.',
            'unique_slug' => null,
        ],
    ];

    return $defaults[$entryVariantType] ?? $defaults['standard_v01'];
}

/**
 * Resolve a string default from overrides or the variant defaults map.
 *
 * @param  array<string, string|null>  $defaults
 */
function logoListItemCoalesceDefault(
    ?string $value,
    array $defaults,
    string $key
): string {
    if ($value !== null) {
        return $value;
    }

    return $defaults[$key] ?? '';
}

/**
 * Read a string parameter from a loosely typed parameter bag.
 *
 * @param  array<mixed>  $params
 */
function logoListItemStringParam(
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
function logoListItemNullableStringParam(
    array $params,
    string $key
): ?string {
    $value = $params[$key] ?? null;

    if ($value === null) {
        return null;
    }

    return \is_string($value) ? $value : null;
}
