<?php

declare(strict_types=1);

use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Stache\Repositories\CollectionTreeRepository;
use Statamic\Stache\Stache;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PageEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PeopleEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

// Guard the live `content/` submodule: capture it before each test and refuse
// to leave anything behind afterwards (wordpress-importer-archive#570).
beforeEach(function (): void {
    $this->captureSubmoduleStates();
});

afterEach(function (): void {
    try {
        // Clean up any created test files
        $this::cleanupTestFilesWithPaths(
            uamsPeopleTestEntrySlugs(),
            'people',
            'default',
            uamsPeopleTestAssetFiles(),
            uamsPeopleTestTaxonomyTerms(),
            false
        );
        $this::cleanupTestFilesWithPaths(
            ['people-directory'],
            'pages',
            'default',
            [],
            [],
            false
        );
    } catch (Exception $e) {
        // Log the error but don't fail the test
        error_log('Cleanup error in tearDown: '.$e->getMessage());
    }

    // Last, so it judges what the cleanup above did NOT put back (wordpress-importer-archive#570).
    $this->assertSubmodulesUnchanged();
});

/**
 * Unit tests for PeopleEntryCreator.
 *
 * Tests the UAMS People import functionality, including:
 *   - Person entry creation and mapping
 *   - Portrait image import
 *   - People group taxonomy processing
 *   - Bio content processing and Bard conversion
 *   - Contact information mapping
 *   - Profile URL handling
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/UamsPeopleImportTest.php
 */
it('creates people entries using production dependencies', function (): void {
    // Create test XML file with realistic UAMS people content
    $xmlPath = $this->createTempFile('test_uams_people_comprehensive_');
    file_put_contents(
        $xmlPath,
        uamsPeopleTestXmlContent(
            $this->getXmlHeader(),
            $this->getXmlFooter()
        )
    );

    try {
        // Setup real instances for testing production methods
        $xmlParser = new XmlParser;
        $xmlParser->parse($xmlPath);
        $assetImporter = new AssetImporter;
        $output = $this->createMock(OutputInterface::class);
        $metadataMapper = new MetadataMapper;
        $contentProcessor = new ContentProcessor;

        $peopleEntryCreator = new PeopleEntryCreator;

        // Test that we can actually call the production createEntries method
        $result = $peopleEntryCreator->createEntries(
            'default',
            'people',
            $xmlParser,
            $assetImporter,
            $output,
            $metadataMapper,
            $contentProcessor
        );

        // Test that we actually created entries (not just empty arrays)
        $peopleGroups = $peopleEntryCreator->getPeopleGroups();

        // Should create at least one entry
        expect(count($result))->toBeGreaterThan(0);
    } finally {
        // Clean up test file
        $this::cleanupTestFilesWithPaths(
            uamsPeopleTestEntrySlugs(),
            'people',
            'default',
            uamsPeopleTestAssetFiles(),
            uamsPeopleTestTaxonomyTerms(),
            false
        );
        $this::cleanupTestFilesWithPaths(
            ['people-directory'],
            'pages',
            'default',
            [],
            [],
            false
        );
    }

    // Clean up variables to free up memory
    unset(
        $xmlPath,
        $xmlParser,
        $assetImporter,
        $output,
        $metadataMapper,
        $contentProcessor,
        $peopleEntryCreator,
        $result,
        $peopleGroups
    );
});

it('maps people_group taxonomy terms with site-specific slugs', function (): void {
    // Create test XML file with realistic UAMS people content
    $xmlPath = $this->createTempFile('test_uams_people_comprehensive_');
    file_put_contents(
        $xmlPath,
        uamsPeopleTestXmlContent(
            $this->getXmlHeader(),
            $this->getXmlFooter()
        )
    );

    try {
        // Setup real instances for testing production methods
        $xmlParser = new XmlParser;
        $xmlParser->parse($xmlPath);
        $assetImporter = new AssetImporter;
        $output = $this->createMock(OutputInterface::class);
        $metadataMapper = new MetadataMapper;
        $contentProcessor = new ContentProcessor;

        $peopleEntryCreator = new PeopleEntryCreator;

        // Call the production createEntries method to get real output
        $result = $peopleEntryCreator->createEntries(
            'default',
            'people',
            $xmlParser,
            $assetImporter,
            $output,
            $metadataMapper,
            $contentProcessor
        );

        // Test that we got results (pageData from createEntries)
        expect(count($result))->toBeGreaterThan(0);

        // Test that the people groups are populated after processing
        $peopleGroups = $peopleEntryCreator->getPeopleGroups();
        expect(count($peopleGroups))->toBeGreaterThan(0);

        // Test that the site-specific people group slugs are available
        $siteSpecificSlugs = $peopleEntryCreator->getSiteSpecificPeopleGroupSlugs();
        expect(count($siteSpecificSlugs))->toBeGreaterThan(0);

        // Test that the people_group contains the expected site-specific slugs
        expect($siteSpecificSlugs)->toContain('default_foo');
        expect($siteSpecificSlugs)->toContain('default_foo_bar');

        // Test that the slugs are properly formatted (kebab-case with site prefix)
        foreach ($siteSpecificSlugs as $slug) {
            expect($slug)->toBeString();

            if (! is_string($slug)) {
                throw new RuntimeException('Expected each site-specific slug to be a string.');
            }

            // Slug should be kebab-case with underscores
            expect($slug)->toMatch('/^[a-z0-9_-]+$/');
            // Slug should start with site prefix
            expect($slug)->toStartWith('default_');
        }

        // ---
        // This test used to create two `people_groups` TAXONOMY terms here and
        // assert them back. `UAMS-Web/uams-statamic-archive#1863` converted
        // `people_groups` into a structured collection, so no taxonomy of that
        // handle exists — `createTaxonomyTerm()` writes nothing and the
        // assertions fail on `main` as surely as they do here (wordpress-importer-archive#13).
        //
        // The collection-entry destination is not assertable from this test:
        // it exercises `PeopleEntryCreator::createEntries()` alone, and the
        // entries are created by the term-import pass, which a full import runs
        // and this test does not. The destination is covered by
        // `tests/Integration/StructuredTermImporterTest.php`, and end-to-end by
        // the `people_groups` dataset in this file.
        //
        // What remains here is still the thing this test is named for: the
        // site-specific slug bookkeeping asserted above.
        // ---
    } finally {
        // Clean up test file
        $this::cleanupTestFilesWithPaths(
            uamsPeopleTestEntrySlugs(),
            'people',
            'default',
            uamsPeopleTestAssetFiles(),
            uamsPeopleTestTaxonomyTerms(),
            false
        );
        $this::cleanupTestFilesWithPaths(
            ['people-directory'],
            'pages',
            'default',
            [],
            [],
            false
        );
    }

    // Clean up variables to free up memory
    unset(
        $xmlPath,
        $xmlParser,
        $assetImporter,
        $output,
        $metadataMapper,
        $contentProcessor,
        $peopleEntryCreator,
        $result,
        $peopleGroups
    );
});

it('validates individual people YAML fields', function (string $field, mixed $value, string $type): void {
    // Create XML parameters array for UAMS people
    $xmlParameters = [
        'postType' => 'uamspeople',
        'uniqueSlug' => 'people-entry',
        'entryVariantType' => 'standard',
    ];

    // Create a callback function that builds the comprehensive people XML
    $xmlGeneratorCallback = function (array $params): string {
        $xmlPath = $this->createTempFile('test_uams_people_comprehensive_');
        file_put_contents(
            $xmlPath,
            uamsPeopleTestXmlContent(
                $this->getXmlHeader(),
                $this->getXmlFooter()
            )
        );

        return $xmlPath;
    };

    // Call the trait method with XML parameters and callback
    $this->testIndividualYamlFieldValidation(
        $field,
        $value,
        $type,
        $xmlParameters,
        $xmlGeneratorCallback,
        'portrait' // Use 'portrait' as the image field name for people
    );
})->with(uamsPeopleYamlFieldValidationProvider());

it('points people_groups at collection entries, not taxonomy terms', function (): void {
    // `UAMS-Web/uams-statamic-archive#1863` converted `people_groups` into a structured
    // collection and the `person` blueprint field is `type: entries`. The importer
    // used to write `default_foo` — a term slug that fieldtype cannot resolve.
    // This pins the entry-id shape AND that each id reaches a real entry in the
    // destination collection: an id-shaped string pointing at nothing would
    // satisfy a shape-only assertion (wordpress-importer-archive#13).
    $xmlParameters = [
        'postType' => 'uamspeople',
        'uniqueSlug' => 'people-entry',
        'entryVariantType' => 'standard',
    ];

    $xmlGeneratorCallback = function (array $params): string {
        $xmlPath = $this->createTempFile('test_uams_people_comprehensive_');
        file_put_contents(
            $xmlPath,
            uamsPeopleTestXmlContent(
                $this->getXmlHeader(),
                $this->getXmlFooter()
            )
        );

        return $xmlPath;
    };

    $entry = $this->generateAndImportFromXmlCallback(
        $xmlParameters,
        $xmlGeneratorCallback
    );

    $groups = $entry->get('people_groups');

    expect($groups)->toBeArray()->not->toBeEmpty();

    // Collect failures rather than throwing on the first, so a bad reference
    // names itself instead of hiding the others (tests/CLAUDE.md).
    $unresolved = [];

    foreach (is_array($groups) ? $groups : [] as $groupReference) {
        if (! is_string($groupReference) || str_contains($groupReference, '::')) {
            $unresolved[] = 'not an entry id: '.var_export($groupReference, true);

            continue;
        }

        $groupEntry = EntryFacade::find($groupReference);

        if (! $groupEntry instanceof StatamicEntry) {
            $unresolved[] = $groupReference.' resolves to no entry';

            continue;
        }

        $groupCollection = $groupEntry->collection();

        if (! $groupCollection instanceof StatamicCollection || $groupCollection->handle() !== 'people_groups') {
            $unresolved[] = $groupReference.' is not in people_groups';
        }
    }

    expect($unresolved)->toBe([]);
});

it('converts the show-people shortcode to a Bard set block', function (): void {
    // Create test XML file with page containing show-people shortcode
    $xmlPath = $this->createTempFile('test_uams_people_comprehensive_');
    file_put_contents(
        $xmlPath,
        uamsPeopleTestXmlContent(
            $this->getXmlHeader(),
            $this->getXmlFooter()
        )
    );

    try {
        // Setup real instances for testing production methods
        $xmlParser = new XmlParser;
        $xmlParser->parse($xmlPath);

        // Debug: Check what items the XML parser found
        $pageItems = $xmlParser->getItems('page');
        // XML should contain page items
        expect(count($pageItems))->toBeGreaterThan(0);

        $assetImporter = new AssetImporter;
        $output = $this->createMock(OutputInterface::class);
        $metadataMapper = new MetadataMapper;
        $contentProcessor = new ContentProcessor;

        // Import the page entry (not people entries)
        $siteManager = new SiteManager;
        $collectionStructureManager = new CollectionStructureManager;
        $stache = new Stache;
        $treeRepository = new CollectionTreeRepository($stache);
        $treeBuilder = new TreeBuilder($treeRepository);

        $entryCreator = new EntryCreator(
            $metadataMapper,
            $contentProcessor,
            $siteManager,
            $collectionStructureManager,
            $treeBuilder
        );
        $pageEntryCreator = new PageEntryCreator($entryCreator);

        // Call the production createEntries method to get real output
        $result = $pageEntryCreator->createEntries(
            'default',
            'pages',
            $xmlParser,
            $assetImporter,
            $output,
            $metadataMapper,
            $contentProcessor
        );

        // Test that we got results
        expect(count($result))->toBeGreaterThan(0);

        // Find the people directory page entry
        $peopleDirectoryPage = null;
        foreach ($result as $pageData) {
            $entry = uamsPeopleEntryFromPageData($pageData);

            if ($entry !== null && $entry->slug() === 'people-directory') {
                $peopleDirectoryPage = $entry;
                break;
            }
        }

        // Should find the people directory page
        expect($peopleDirectoryPage)->not->toBeNull();

        if ($peopleDirectoryPage === null) {
            throw new RuntimeException('Expected to find the people directory page.');
        }

        // Test that the content field exists and has the expected structure
        $contentRaw = $peopleDirectoryPage->get('content');
        expect($contentRaw)->toBeArray();
        $content = uamsPeopleNormalizeStringKeyedList($contentRaw);

        // Test the first paragraph
        // Content should have 3 blocks
        expect($content)->toHaveCount(3);
        $firstBlock = uamsPeopleNormalizeStringKeyedArray($content[0] ?? null);
        // First block should be paragraph
        expect($firstBlock['type'] ?? null)->toBe('paragraph');
        $firstBlockContent = uamsPeopleNormalizeStringKeyedList($firstBlock['content'] ?? null);
        $firstTextBlock = uamsPeopleNormalizeStringKeyedArray($firstBlockContent[0] ?? null);
        // First paragraph should have correct text
        expect($firstTextBlock['text'] ?? null)->toBe('This page displays people using the show-people shortcode.');

        // Test the set block (converted shortcode)
        $setBlock = uamsPeopleNormalizeStringKeyedArray($content[1] ?? null);
        // Second block should be set
        expect($setBlock['type'] ?? null)->toBe('set');
        // Set block should have attrs
        expect($setBlock)->toHaveKey('attrs');
        $setAttrs = uamsPeopleNormalizeStringKeyedArray($setBlock['attrs'] ?? null);
        // Set block should have values
        expect($setAttrs)->toHaveKey('values');

        $setValues = uamsPeopleNormalizeStringKeyedArray($setAttrs['values'] ?? null);

        // Test set block values
        // Set type should be people
        expect($setValues['type'] ?? null)->toBe('people');
        // multiple_groups_selection should be false
        expect($setValues['multiple_groups_selection'] ?? null)->toBeFalse();
        // group_single should be default_foo
        expect($setValues['group_single'] ?? null)->toBe('default_foo');
        // id_include should be 0
        expect($setValues['id_include'] ?? null)->toBe('0');
        // id_exclude should be 0
        expect($setValues['id_exclude'] ?? null)->toBe('0');
        // order_by should be sortpriority
        expect($setValues['order_by'] ?? null)->toBe('sortpriority');

        // Test display_detail array (may be stored as JSON string when round-tripped)
        // Should have display_detail
        expect($setValues)->toHaveKey('display_detail');
        $displayDetail = $setValues['display_detail'] ?? null;
        if (is_string($displayDetail)) {
            $decoded = json_decode($displayDetail, true);
            $displayDetail = is_array($decoded) ? $decoded : $displayDetail;
        }
        expect($displayDetail)->toBeArray();

        if (! is_array($displayDetail)) {
            throw new RuntimeException('Expected display_detail to be an array.');
        }

        $expectedDisplayDetail = [
            'name',
            'photo',
            'freehtml',
            'position',
            'email',
            'location',
            'telephone',
            'fax',
            'website',
            'deptweb',
            'triweb',
            'youtube',
        ];

        // display_detail should match expected values
        expect($displayDetail)->toEqualCanonicalizing($expectedDisplayDetail);

        // entry_link_query should be false
        expect($setValues['entry_link_query'] ?? null)->toBeFalse();
        // groups_filter should be inactive
        expect($setValues['groups_filter'] ?? null)->toBe('inactive');

        // Test the last paragraph
        $lastBlock = uamsPeopleNormalizeStringKeyedArray($content[2] ?? null);
        // Third block should be paragraph
        expect($lastBlock['type'] ?? null)->toBe('paragraph');
        $lastBlockContent = uamsPeopleNormalizeStringKeyedList($lastBlock['content'] ?? null);
        $lastTextBlock = uamsPeopleNormalizeStringKeyedArray($lastBlockContent[0] ?? null);
        // Last paragraph should have correct text
        expect($lastTextBlock['text'] ?? null)->toBe('End of people directory content.');
    } finally {
        // Clean up test file
        $this::cleanupTestFilesWithPaths(
            uamsPeopleTestEntrySlugs(),
            'people',
            'default',
            uamsPeopleTestAssetFiles(),
            uamsPeopleTestTaxonomyTerms(),
            false
        );
        $this::cleanupTestFilesWithPaths(
            ['people-directory'],
            'pages',
            'default',
            [],
            [],
            false
        );
    }

    // Clean up variables to free up memory
    unset(
        $xmlPath,
        $xmlParser,
        $assetImporter,
        $output,
        $metadataMapper,
        $contentProcessor,
        $siteManager,
        $stache,
        $treeRepository,
        $treeBuilder,
        $entryCreator,
        $pageEntryCreator,
        $result,
        $peopleDirectoryPage,
        $content,
        $setValues
    );
});

/**
 * Entry slugs created by the UAMS people import tests, for cleanup.
 *
 * @return array<int, string>
 */
function uamsPeopleTestEntrySlugs(): array
{
    return ['people-entry', 'people-directory'];
}

/**
 * Portrait asset files created by the UAMS people import tests, keyed by container.
 *
 * @return array<string, array<int, string>>
 */
function uamsPeopleTestAssetFiles(): array
{
    return ['portraits' => ['2025-09-provider-portrait-placeholder_08_3-4.jpg']];
}

/**
 * The people_groups taxonomy term IDs created by the UAMS people import tests.
 *
 * @return array<int, string>
 */
function uamsPeopleTestTaxonomyTerms(): array
{
    return ['people_groups::default_foo', 'people_groups::default_foo_bar'];
}

/**
 * Build the comprehensive UAMS people test XML document with person content.
 *
 * The WXR header and footer are injected so this stays a pure string builder
 * (the trait's header/footer/temp-file helpers are protected and run on the
 * bound TestCase at the call site).
 *
 * @param  string  $header  The WXR document header from the trait.
 * @param  string  $footer  The WXR document footer from the trait.
 * @return string The full WXR XML document.
 */
function uamsPeopleTestXmlContent(string $header, string $footer): string
{
    $xmlContent = $header;

    // Add people group terms
    $xmlContent .= '        <wp:term>
            <wp:term_id>1</wp:term_id>
            <wp:term_taxonomy><![CDATA[uamspeople-categories]]></wp:term_taxonomy>
            <wp:term_slug><![CDATA[foo]]></wp:term_slug>
            <wp:term_parent><![CDATA[]]></wp:term_parent>
            <wp:term_name><![CDATA[Foo]]></wp:term_name>
        </wp:term>
        <wp:term>
            <wp:term_id>2</wp:term_id>
            <wp:term_taxonomy><![CDATA[uamspeople-categories]]></wp:term_taxonomy>
            <wp:term_slug><![CDATA[bar]]></wp:term_slug>
            <wp:term_parent><![CDATA[foo]]></wp:term_parent>
            <wp:term_name><![CDATA[Bar]]></wp:term_name>
        </wp:term>';

    // Add generator tag
    $xmlContent .= '

        <generator>https://wordpress.org/?v=6.7.3</generator>

';

    // Add attachment for portrait image
    $xmlContent .= '        <item>
            <title><![CDATA[Waldo, Grault Garply]]></title>
            <link>https://communications.uams.edu/web/Provider-Portrait-Placeholder_08_3-4/</link>
            <pubDate>Wed, 12 Oct 2022 17:34:22 +0000</pubDate>
            <dc:creator><![CDATA[testuser]]></dc:creator>
            <guid isPermaLink="false">
                https://communications.uams.edu/web/wp-content/uploads/sites/2/2025/09/Provider-Portrait-Placeholder_08_3-4.jpg</guid>
            <description></description>
            <content:encoded><![CDATA[]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>200</wp:post_id>
            <wp:post_date><![CDATA[2022-10-12 12:30:08]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2022-10-12 17:30:08]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-08-13 19:30:40]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-08-14 00:30:40]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[Provider-Portrait-Placeholder_08_3-4]]></wp:post_name>
            <wp:status><![CDATA[publish]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[attachment]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            <wp:attachment_url><![CDATA[https://communications.uams.edu/web/wp-content/uploads/sites/2/2025/09/Provider-Portrait-Placeholder_08_3-4.jpg]]></wp:attachment_url>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_wp_attached_file]]></wp:meta_key>
                <wp:meta_value><![CDATA[2025/09/Provider-Portrait-Placeholder_08_3-4.jpg]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_wp_attachment_metadata]]></wp:meta_key>
                <wp:meta_value><![CDATA[a:6:{s:5:"width";i:899;s:6:"height";i:1200;s:4:"file";s:31:"2025/09/Provider-Portrait-Placeholder_08_3-4.jpg";s:8:"filesize";i:274000;s:5:"sizes";a:18:{s:6:"medium";a:5:{s:4:"file";s:31:"Provider-Portrait-Placeholder_08_3-4-225x300.jpg";s:5:"width";i:225;s:6:"height";i:300;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:12943;}s:5:"large";a:5:{s:4:"file";s:32:"Provider-Portrait-Placeholder_08_3-4-768x1024.jpg";s:5:"width";i:768;s:6:"height";i:1024;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:122436;}s:9:"thumbnail";a:5:{s:4:"file";s:31:"Provider-Portrait-Placeholder_08_3-4-150x150.jpg";s:5:"width";i:150;s:6:"height";i:150;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:5801;}s:12:"medium_large";a:5:{s:4:"file";s:32:"Provider-Portrait-Placeholder_08_3-4-768x1024.jpg";s:5:"width";i:768;s:6:"height";i:1024;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:122436;}s:16:"uamspeople-thumb";a:5:{s:4:"file";s:31:"Provider-Portrait-Placeholder_08_3-4-245x327.jpg";s:5:"width";i:245;s:6:"height";i:327;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:14737;}s:9:"1536x1536";a:5:{s:4:"file";s:33:"Provider-Portrait-Placeholder_08_3-4-1152x1536.jpg";s:5:"width";i:1152;s:6:"height";i:1536;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:258461;}s:9:"2048x2048";a:5:{s:4:"file";s:33:"Provider-Portrait-Placeholder_08_3-4-1536x2048.jpg";s:5:"width";i:1536;s:6:"height";i:2048;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:420951;}s:18:"bootstrap-featured";a:5:{s:4:"file";s:31:"Provider-Portrait-Placeholder_08_3-4-730x973.jpg";s:5:"width";i:730;s:6:"height";i:973;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:111792;}s:11:"aspect-16-9";a:5:{s:4:"file";s:32:"Provider-Portrait-Placeholder_08_3-4-1024x576.jpg";s:5:"width";i:1024;s:6:"height";i:576;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:97464;}s:17:"aspect-16-9-small";a:5:{s:4:"file";s:31:"Provider-Portrait-Placeholder_08_3-4-512x288.jpg";s:5:"width";i:512;s:6:"height";i:288;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:28384;}s:10:"aspect-8-3";a:5:{s:4:"file";s:32:"Provider-Portrait-Placeholder_08_3-4-1024x384.jpg";s:5:"width";i:1024;s:6:"height";i:384;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:63496;}s:10:"aspect-4-3";a:5:{s:4:"file";s:32:"Provider-Portrait-Placeholder_08_3-4-1024x768.jpg";s:5:"width";i:1024;s:6:"height";i:768;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:129571;}s:10:"aspect-2-1";a:5:{s:4:"file";s:32:"Provider-Portrait-Placeholder_08_3-4-1024x512.jpg";s:5:"width";i:1024;s:6:"height";i:512;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:85345;}s:10:"aspect-1-1";a:5:{s:4:"file";s:33:"Provider-Portrait-Placeholder_08_3-4-1024x1024.jpg";s:5:"width";i:1024;s:6:"height";i:1024;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:168711;}s:11:"hero-tablet";a:5:{s:4:"file";s:31:"Provider-Portrait-Placeholder_08_3-4-455x256.jpg";s:5:"width";i:455;s:6:"height";i:256;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:23028;}s:18:"content-image-side";a:5:{s:4:"file";s:31:"Provider-Portrait-Placeholder_08_3-4-299x399.jpg";s:5:"width";i:299;s:6:"height";i:399;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:20980;}s:20:"content-image-center";a:5:{s:4:"file";s:31:"Provider-Portrait-Placeholder_08_3-4-630x840.jpg";s:5:"width";i:630;s:6:"height";i:840;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:84154;}s:18:"content-image-wide";a:5:{s:4:"file";s:33:"Provider-Portrait-Placeholder_08_3-4-1020x1360.jpg";s:5:"width";i:1020;s:6:"height";i:1360;s:9:"mime-type";s:10:"image/jpeg";s:8:"filesize";i:207766;}}s:10:"image_meta";a:12:{s:8:"aperture";s:1:"0";s:6:"credit";s:0:"";s:6:"camera";s:0:"";s:7:"caption";s:0:"";s:17:"created_timestamp";s:1:"0";s:9:"copyright";s:0:"";s:12:"focal_length";s:1:"0";s:3:"iso";s:1:"0";s:13:"shutter_speed";s:1:"0";s:5:"title";s:0:"";s:11:"orientation";s:1:"0";s:8:"keywords";a:0:{}}}]]></wp:meta_value>
            </wp:postmeta>
        </item>';

    // Add UAMS people entry with full metadata
    $xmlContent .= '        <item>
            <title><![CDATA[Bar Baz]]></title>
            <link>https://communications.uams.edu/web/people/people-entry/</link>
            <pubDate>Wed, 12 Oct 2022 17:30:08 +0000</pubDate>
            <dc:creator><![CDATA[testuser]]></dc:creator>
            <guid isPermaLink="false">
                https://communications.uams.edu/web/?post_type=uamspeople&#038;p=100</guid>
            <description></description>
            <content:encoded><![CDATA[Lorem ipsum dolor sit amet, consectetuer adipiscing elit.

Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

<ul>
    <li>Lorem ipsum dolor sit amet consectetuer.</li>
    <li>Aenean commodo ligula eget dolor.</li>
    <li>Aenean massa cum sociis natoque penatibus.</li>
</ul>]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>100</wp:post_id>
            <wp:post_date><![CDATA[2022-10-12 12:30:08]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2022-10-12 17:30:08]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-08-13 19:30:40]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-08-14 00:30:40]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[people-entry]]></wp:post_name>
            <wp:status><![CDATA[publish]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>42</wp:menu_order>
            <wp:post_type><![CDATA[uamspeople]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            <category domain="uamspeople-categories" nicename="foo"><![CDATA[Foo]]></category>
            <category domain="uamspeople-categories" nicename="bar"><![CDATA[Bar]]></category>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_uamspeople_page_template]]></wp:meta_key>
                <wp:meta_value><![CDATA[default]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_ps_lastname]]></wp:meta_key>
                <wp:meta_value><![CDATA[Baz, Bar ]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_edit_last]]></wp:meta_key>
                <wp:meta_value><![CDATA[1]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_psfreehtml]]></wp:meta_key>
                <wp:meta_value><![CDATA[<p>Foo Bar: <br />Baz qux quux</p>]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_psposition]]></wp:meta_key>
                <wp:meta_value><![CDATA[Foo]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_psemail]]></wp:meta_key>
                <wp:meta_value><![CDATA[foo@bar.com]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_pslocation]]></wp:meta_key>
                <wp:meta_value><![CDATA[Foo Bar, Room 42]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_pstel]]></wp:meta_key>
                <wp:meta_value><![CDATA[501-555-1234]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_psfax]]></wp:meta_key>
                <wp:meta_value><![CDATA[501-555-5678]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_pspersonal]]></wp:meta_key>
                <wp:meta_value><![CDATA[https://foo.bar/baz/]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_pspersonalanchor]]></wp:meta_key>
                <wp:meta_value><![CDATA[Foo Bar Bio]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_psdeptweb]]></wp:meta_key>
                <wp:meta_value><![CDATA[https://qux.quux/corge/]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_psdeptwebanchor]]></wp:meta_key>
                <wp:meta_value><![CDATA[Baz Qux Bio]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_psposition]]></wp:meta_key>
                <wp:meta_value><![CDATA[Foo]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_pstriweb]]></wp:meta_key>
                <wp:meta_value><![CDATA[https://uams-triprofiles.uams.edu/profiles/display/1234567]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_pstriwebanchor]]></wp:meta_key>
                <wp:meta_value><![CDATA[Quux Corge Bio]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_pstwitter]]></wp:meta_key>
                <wp:meta_value><![CDATA[https://twitter.com/FooBarBaz]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_psyoutube]]></wp:meta_key>
                <wp:meta_value><![CDATA[https://www.youtube.com/watch?v=dQw4w9WgXcQ]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_psuser]]></wp:meta_key>
                <wp:meta_value><![CDATA[0]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_pssorttext]]></wp:meta_key>
                <wp:meta_value><![CDATA[Baz, Bar]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_pssortpriority]]></wp:meta_key>
                <wp:meta_value><![CDATA[4]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_thumbnail_id]]></wp:meta_key>
                <wp:meta_value><![CDATA[200]]></wp:meta_value>
            </wp:postmeta>
        </item>';

    // Add basic page with show-people shortcode
    $xmlContent .= '        <item>
            <title><![CDATA[People Directory Page]]></title>
            <link>https://communications.uams.edu/web/people-directory/</link>
            <pubDate>Wed, 12 Oct 2022 17:35:08 +0000</pubDate>
            <dc:creator><![CDATA[testuser]]></dc:creator>
            <guid isPermaLink="false">
                https://communications.uams.edu/web/?page_id=300</guid>
            <description></description>
            <content:encoded><![CDATA[<!-- wp:paragraph -->
<p>This page displays people using the show-people shortcode.</p>
<!-- /wp:paragraph -->

<!-- wp:html -->
[show-people category=\'foo\' layout=\'grid\' style=\'img-square,text-left,img-left,1-column,white-card-theme\' display=\'name,photo,social,freehtml,position,email,location,telephone,fax,website,deptweb,triweb,twitter,youtube\']
<!-- /wp:html -->

<!-- wp:paragraph -->
<p>End of people directory content.</p>
<!-- /wp:paragraph -->]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>300</wp:post_id>
            <wp:post_date><![CDATA[2022-10-12 12:35:08]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2022-10-12 17:35:08]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-08-13 19:35:40]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-08-14 00:35:40]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[people-directory]]></wp:post_name>
            <wp:status><![CDATA[publish]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[page]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
        </item>';

    $xmlContent .= $footer;

    return $xmlContent;
}

/**
 * Resolve a Statamic entry from createEntries page data.
 *
 * @param  mixed  $pageData  Page data returned by createEntries.
 * @return StatamicEntry|null The entry object when present.
 */
function uamsPeopleEntryFromPageData(mixed $pageData): ?StatamicEntry
{
    if (! is_array($pageData)) {
        return null;
    }

    $entry = $pageData['entry_object'] ?? null;

    return $entry instanceof StatamicEntry ? $entry : null;
}

/**
 * Normalize mixed array data to a string-keyed array.
 *
 * @param  mixed  $candidate  Raw array data.
 * @return array<string, mixed> Normalized string-keyed array.
 */
function uamsPeopleNormalizeStringKeyedArray(mixed $candidate): array
{
    if (! is_array($candidate)) {
        return [];
    }

    $normalized = [];

    foreach ($candidate as $key => $value) {
        $normalized[(string) $key] = $value;
    }

    return $normalized;
}

/**
 * Normalize mixed list data to a zero-indexed array.
 *
 * @param  mixed  $candidate  Raw list data.
 * @return list<mixed> Normalized list.
 */
function uamsPeopleNormalizeStringKeyedList(mixed $candidate): array
{
    if (! is_array($candidate)) {
        return [];
    }

    return array_values($candidate);
}

/**
 * Data provider for individual YAML field validation tests.
 *
 * Each field is tested separately to make it easier to identify
 * which specific fields are failing during import validation.
 *
 * @return array<string, array{field: string, value: mixed, type: string}> Array of test cases with field name, expected value, and data type
 */
function uamsPeopleYamlFieldValidationProvider(): array
{
    return [
        'blueprint' => [
            'field' => 'blueprint',
            'value' => 'person',
            'type' => 'string',
        ],
        'title' => [
            'field' => 'title',
            'value' => 'Bar Baz',
            'type' => 'string',
        ],
        'slug' => [
            'field' => 'slug',
            'value' => 'people-entry',
            'type' => 'string',
        ],
        'sort_text_alpha' => [
            'field' => 'sort_text_alpha',
            'value' => 'Baz, Bar',
            'type' => 'string',
        ],
        // ---
        // `job_title` is a replicator, so assert the nested set fields rather than the
        // whole array: the set's `id` is a freshly generated RowId and cannot be
        // matched exactly (wordpress-importer-archive#31).
        // ---
        'job_title set title' => [
            'field' => 'job_title.0.title',
            'value' => 'Foo',
            'type' => 'string',
        ],
        'job_title set type' => [
            'field' => 'job_title.0.type',
            'value' => 'free_text',
            'type' => 'string',
        ],
        'job_title set enabled' => [
            'field' => 'job_title.0.enabled',
            'value' => true,
            'type' => 'boolean',
        ],
        'bio' => [
            'field' => 'bio',
            'value' => [
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Lorem ipsum dolor sit amet, consectetuer adipiscing elit.',
                        ],
                    ],
                ],
                [
                    'type' => 'paragraph',
                    'content' => [
                        [
                            'type' => 'text',
                            'text' => 'Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.',
                        ],
                    ],
                ],
                [
                    'type' => 'bulletList',
                    'content' => [
                        [
                            'type' => 'listItem',
                            'content' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => 'Lorem ipsum dolor sit amet consectetuer.',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'type' => 'listItem',
                            'content' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => 'Aenean commodo ligula eget dolor.',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                        [
                            'type' => 'listItem',
                            'content' => [
                                [
                                    'type' => 'paragraph',
                                    'content' => [
                                        [
                                            'type' => 'text',
                                            'text' => 'Aenean massa cum sociis natoque penatibus.',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'type' => 'array',
        ],
        // ---
        // `people_groups` is asserted separately, below the datasets: it became a
        // structured collection in `UAMS-Web/uams-statamic-archive#1863`, so the field
        // holds generated entry ids rather than the fixed `default_foo` /
        // `default_foo_bar` slugs and cannot be a literal here (wordpress-importer-archive#13).
        // ---
        'priority' => [
            'field' => 'priority',
            'value' => 4,
            'type' => 'integer',
        ],
        'email' => [
            'field' => 'email',
            'value' => 'foo@bar.com',
            'type' => 'string',
        ],
        'telephone' => [
            'field' => 'telephone',
            'value' => '+15015551234',
            'type' => 'string',
        ],
        'fax' => [
            'field' => 'fax',
            'value' => '+15015555678',
            'type' => 'string',
        ],
        'location' => [
            'field' => 'location',
            'value' => 'Foo Bar, Room 42',
            'type' => 'string',
        ],
        'clinical_profile_url' => [
            'field' => 'clinical_profile_url',
            'value' => 'https://foo.bar/baz/',
            'type' => 'string',
        ],
        'clinical_profile_link_text' => [
            'field' => 'clinical_profile_link_text',
            'value' => 'Foo Bar Bio',
            'type' => 'string',
        ],
        'academic_profile_url' => [
            'field' => 'academic_profile_url',
            'value' => 'https://qux.quux/corge/',
            'type' => 'string',
        ],
        'academic_profile_link_text' => [
            'field' => 'academic_profile_link_text',
            'value' => 'Baz Qux Bio',
            'type' => 'string',
        ],
        'research_profile_url' => [
            'field' => 'research_profile_url',
            'value' => 'https://uams-triprofiles.uams.edu/profiles/display/1234567',
            'type' => 'string',
        ],
        'research_profile_link_text' => [
            'field' => 'research_profile_link_text',
            'value' => 'Quux Corge Bio',
            'type' => 'string',
        ],
        'twitter' => [
            'field' => 'twitter',
            'value' => 'https://twitter.com/FooBarBaz',
            'type' => 'string',
        ],
        // ---
        // `_psyoutube` lands on the `institutional_` pair, not a bare `video_url`:
        // the `person` blueprint defines only the four prefixed pairs, so the bare
        // handle augmented to nothing and rendered nowhere (wordpress-importer-archive#501). The context is a
        // recorded default — WordPress carries none — and the choice is pinned by
        // `import_wordpress_notes` below.
        // ---
        'institutional_video_url' => [
            'field' => 'institutional_video_url',
            'value' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
            'type' => 'string',
        ],
        'institutional_video_link_text' => [
            'field' => 'institutional_video_link_text',
            'value' => 'Video Referencing Bar Baz',
            'type' => 'string',
        ],
        'import_wordpress_notes' => [
            'field' => 'import_wordpress_notes',
            'value' => 'Video imported to the Institutional context; WordPress recorded no '
                .'academic, clinical, institutional or research context for it.',
            'type' => 'string',
        ],
        'portrait' => [
            'field' => 'portrait',
            'value' => '2025-09-provider-portrait-placeholder_08_3-4.jpg',
            'type' => 'string',
        ],
        'status_and_visibility' => [
            'field' => 'status_and_visibility',
            'value' => 'published',
            'type' => 'string',
        ],
        'password_protected' => [
            'field' => 'password_protected',
            'value' => false,
            'type' => 'boolean',
        ],
    ];
}
