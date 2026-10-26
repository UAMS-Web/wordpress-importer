<?php

declare(strict_types=1);

use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Support\Facades\Config;
use PHPUnit\Framework\ExpectationFailedException;
use Statamic\Entries\Collection;
use Statamic\Entries\Entry;
use Statamic\Entries\GetDateFromPath;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Search;
use Statamic\Fields\Blueprint;
use Statamic\Search\Index as SearchIndex;
use Statamic\Search\QueryBuilder as SearchQueryBuilder;
use Statamic\Search\Result as SearchResult;
use Statamic\Search\Searchables;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Stache\Repositories\CollectionTreeRepository;
use Statamic\Stache\Stache;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Extractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Prompter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Validator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SearchIndexUpdates;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

beforeEach(function (): void {
    // Guard the live `content/` submodule against anything this test leaves
    // behind — wordpress-importer-archive#464's stranded `kb-home` root came out of this very file (wordpress-importer-archive#570)
    $this->captureSubmoduleStates();

    // Guarantee a pristine snapshot baseline. Every import in this file creates
    // the knowledge base root entry, under one of two slugs: 'kb-home' when the
    // MinervaKB kb_page resolves (the data-provider rows pass settings), or the
    // fallback 'kb' when it does not (the null-settings tests — "imports kbtag
    // terms" and "orchestrates the MinervaKB import workflow"). If either root
    // is left on disk when snapshotIsolatedState() runs, restoreIsolatedState()
    // perpetuates it across every subsequent row, and it collides with the next
    // import in two ways: createKnowledgeBaseEntry() finds the existing root and
    // short-circuits WITHOUT applying that row's MinervaKB settings (override
    // rows silently read defaults), and a same-titled root forces a duplicate
    // '… (2)' title on the freshly created entry. Delete any stale root under
    // BOTH slugs before snapshotting so each import creates it fresh. See wordpress-importer-archive#464.
    foreach (kbRootSlugs() as $rootSlug) {
        $staleRoot = kbFindKnowledgeBaseEntryBySlug($rootSlug);
        if ($staleRoot instanceof Entry) {
            try {
                $staleRoot->delete();
            } catch (Throwable $e) {
                // Best-effort pre-clean; restoreIsolatedState() is the backstop.
            }
        }
    }

    // Snapshot the content this test mutates before it runs. The KB import
    // re-runs the full import once per data-provider row and writes entries with
    // generated IDs plus a regenerated collection tree, which the slug-list
    // cleanup cannot reset — so each row would corrupt the next. Snapshotting
    // here lets afterEach restore the exact pre-test state.
    $this->snapshotIsolatedState([
        'content/collections/knowledge_bases',
        'content/trees/collections/default',
        // Tags and FAQ categories import as entries in these collections (wordpress-importer-archive#1220)
        'content/collections/knowledge_base_tags',
        'content/collections/faq_categories',
    ]);
});

afterEach(function (): void {
    try {
        // Clean up any created test files
        // ---
        // Define entry slugs to clean up via Statamic's proper deletion method
        //   - Knowledge base entry created by createKnowledgeBaseEntry()
        //   - Topic entries created from kbtopic terms
        //   - Article entries created from kb post types
        // ---
        $kbEntrySlugs = [
            'sample-article-1', // Article entry from kb post type
            'sample-article-2', // Article entry from kb post type
            'sample-article-3', // Article entry from kb post type
            'sample-article-4', // Article entry from kb post type
            'sample-article-5', // Article entry from kb post type
            'sample-article-6', // Article entry from kb post type
            'sample-article-7', // Article entry from kb post type
            'sample-article-8', // Article entry from kb post type
            'sample-article-9', // Article entry from kb post type
            'sample-article-10', // Article entry from kb post type
            'sample-article-11', // Article entry from kb post type
            'sample-article-12', // Article entry from kb post type
            'sample-article-13', // Article entry from kb post type
            'sample-article-14', // Article entry from kb post type
            'sample-topic-1', // Topic entry from kbtopic term
            'sample-topic-2', // Topic entry from kbtopic term
            'sample-topic-3', // Topic entry from kbtopic term
            'sample-topic-1a', // Nested topic entry (child of sample-topic-1)
            'kb', // Knowledge base root entry (fallback slug when kb_page does not resolve)
            'kb-home', // Knowledge base root entry (slug adopted from kb_page since wordpress-importer-archive#331)
        ];

        // Define KB home page entry slug for pages collection cleanup
        $pageEntrySlugs = [
            'kb-home', // Knowledge base home page entry from pages collection
        ];

        // ---
        // No taxonomy terms to clean up: kbtag terms import as
        // `knowledge_base_tags` collection entries (wordpress-importer-archive#1220), which the
        // `snapshotIsolatedState()` restore returns to their prior state.
        // ---
        $taxonomyTerms = [];

        // ---
        // Define asset files specific to KB tests. Attachment items imported
        // from WordPress are stored in the files container. Featured images
        // are stored in the images container.
        // ---
        $assetFiles = [
            'files' => [
                '2025-09-rel-fall-career-expo-2025.pdf',
            ],
            'images' => [
                '2020-05-fpo_100.jpg',
            ],
        ];

        // ---
        // Use the trait's static helper method to do the actual cleanup. Pass
        // knowledge_bases.yaml tree file configuration with deleteOnFailure enabled since
        // this file may be created during tests and needs to be deleted if restore fails
        // ---
        $this::cleanupTestFilesWithPaths(
            $kbEntrySlugs,
            'knowledge_bases', // collection handle
            'default', // site handle
            $assetFiles, // Asset files created during KB tests
            $taxonomyTerms, // Taxonomy terms created during KB tests
            [
                [
                    'file' => 'content/trees/collections/default/knowledge_bases.yaml',
                    'testPatterns' => ['kb'],
                    'deleteOnFailure' => true,
                ],
            ]
        );

        // Clean up KB home page entry from pages collection
        $this::cleanupTestFilesWithPaths(
            $pageEntrySlugs,
            'pages', // collection handle
            'default', // site handle
            [], // No specific asset files
            [], // No taxonomy terms
            [] // No tree files
        );
    } catch (Exception $e) {
        // Log cleanup errors but don't fail the test. This ensures that cleanup
        // issues don't mask the actual test failures.
        error_log('KbImportTest cleanup error: '.$e->getMessage());
    }

    // Restore the snapshotted content so generated-ID entries and the
    // regenerated tree don't leak into the next test / data-provider row.
    $this->restoreIsolatedState();

    // Last, so it judges what the restore above did NOT put back (wordpress-importer-archive#570).
    $this->assertSubmodulesUnchanged();
});

/**
 * Unit tests for KbEntryCreator.
 *
 * Tests the MinervaKB knowledge base import functionality, including:
 *   - Knowledge base entry creation
 *   - Topic hierarchy processing
 *   - Article creation and organization
 *   - Related articles processing
 *   - Content restrictions handling
 *
 * Isolation: each data-provider row re-runs the full import against the real
 * `default` site, protected by snapshotIsolatedState()/restoreIsolatedState()
 * plus a beforeEach pre-clean of any stale KB root so the snapshot baseline is
 * always pristine (see the beforeEach hook and wordpress-importer-archive#464).
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/KbImportTest.php
 */
it('instantiates a KbEntryCreator with mocked dependencies', function (): void {
    // Create KbEntryCreator instance with mocked dependencies
    $kbEntryCreator = new KbEntryCreator(
        $this->createMock(EntryCreator::class),
        $this->createMock(MinervaKbSettingsSelector::class)
    );

    // The mock-constructed instance is a functioning knowledge base creator
    expect($kbEntryCreator->hasShowPeople())->toBeFalse();
});

it('returns an empty array from createEntries', function (): void {
    // Create KbEntryCreator instance with mocked dependencies
    $kbEntryCreator = new KbEntryCreator(
        $this->createMock(EntryCreator::class),
        $this->createMock(MinervaKbSettingsSelector::class)
    );

    // Call createEntries method to verify it returns empty array
    $result = $kbEntryCreator->createEntries(
        'default',
        'knowledge_bases',
        $this->createMock(XmlParser::class),
        $this->createMock(AssetImporter::class),
        $this->createMock(OutputInterface::class),
        $this->createMock(MetadataMapper::class),
        $this->createMock(ContentProcessor::class)
    );

    // The result is an empty array
    expect($result)->toBeEmpty();
});

it('reports no show-people shortcodes', function (): void {
    // Create KbEntryCreator instance with mocked dependencies
    $kbEntryCreator = new KbEntryCreator(
        $this->createMock(EntryCreator::class),
        $this->createMock(MinervaKbSettingsSelector::class)
    );

    // hasShowPeople returns false for knowledge base entries
    expect($kbEntryCreator->hasShowPeople())->toBeFalse();
});

it('returns an empty array of post categories', function (): void {
    // Create KbEntryCreator instance with mocked dependencies
    $kbEntryCreator = new KbEntryCreator(
        $this->createMock(EntryCreator::class),
        $this->createMock(MinervaKbSettingsSelector::class)
    );

    // Call getPostCategories method to get the result
    $result = $kbEntryCreator->getPostCategories();

    // The result is an empty array
    expect($result)->toBeEmpty();
});

it('returns an empty array of post tags', function (): void {
    // Create KbEntryCreator instance with mocked dependencies
    $kbEntryCreator = new KbEntryCreator(
        $this->createMock(EntryCreator::class),
        $this->createMock(MinervaKbSettingsSelector::class)
    );

    // Call getPostTags method to get the result
    $result = $kbEntryCreator->getPostTags();

    // The result is an empty array
    expect($result)->toBeEmpty();
});

it('returns an empty array of people groups', function (): void {
    // Create KbEntryCreator instance with mocked dependencies
    $kbEntryCreator = new KbEntryCreator(
        $this->createMock(EntryCreator::class),
        $this->createMock(MinervaKbSettingsSelector::class)
    );

    // Call getPeopleGroups method to get the result
    $result = $kbEntryCreator->getPeopleGroups();

    // The result is an empty array
    expect($result)->toBeEmpty();
});

it('validates knowledge base taxonomies without error', function (): void {
    // Create KbEntryCreator instance with mocked dependencies
    $kbEntryCreator = new KbEntryCreator(
        $this->createMock(EntryCreator::class),
        $this->createMock(MinervaKbSettingsSelector::class)
    );

    // Knowledge bases use no taxonomies, so validation writes nothing to the output
    $output = $this->createMock(OutputInterface::class);
    $output->expects($this->never())->method('write');
    $output->expects($this->never())->method('writeln');

    // Call it directly: any Throwable, an Error included, fails the test, which a
    // negated toThrow(Exception::class) does not catch (wordpress-importer-archive#1348)
    $kbEntryCreator->validateTaxonomies(
        'knowledge_bases',
        'default',
        $output
    );
});

it('imports kbtag terms as knowledge_base_tags collection entries', function (): void {
    // The term-pass dedup and the destination registry are process statics, so
    // an earlier test's pass would otherwise be reused for entries that the
    // snapshot restore has since removed.
    BaseEntryCreator::clearTaxonomyCaches();

    // Create test XML file with only the necessary kbtag terms
    $xmlContent = kbBuildTagsXml(
        $this->getXmlHeader(),
        $this->getXmlFooter(),
        [
            'sample-tag-1',
            'sample-tag-2',
            'sample-tag-3',
        ]
    );
    $xmlPath = $this->createTempFile('test_kb_tags_');
    file_put_contents(
        $xmlPath,
        $xmlContent
    );

    // Create XML parser instance for parsing WordPress XML files
    $xmlParser = new XmlParser;

    // Parse the test XML file
    $xmlParser->parse($xmlPath);

    // Create asset importer instance for importing assets
    $assetImporter = new AssetImporter;

    // Create mock output interface for console output
    $output = $this->createMock(OutputInterface::class);

    // Create mock site manager for managing site configurations
    $siteManager = $this->createMock(SiteManager::class);

    // Create mock collection structure manager for managing collection structures
    $collectionStructureManager = $this->createMock(CollectionStructureManager::class);

    // ---
    // Record the site manager's enable-collection calls so the "called once with
    // (default, knowledge_bases, OutputInterface)" expectation can be asserted
    // after the import. The invocation-count matcher (`$this->once()`) cannot be
    // typed under the static analyser, so the call is recorded and verified
    // explicitly instead; the callback parameter types enforce the argument
    // constraints the original `->with(...)` matcher asserted.
    // ---
    $enableCollectionCalls = [];
    $siteManager->method('enableCollectionForSite')
        ->willReturnCallback(function (string $site, string $collection, OutputInterface $consoleOutput) use (&$enableCollectionCalls): bool {
            $enableCollectionCalls[] = [$site, $collection];

            return false;
        });

    // Record the collection structure manager's initialize-structure calls
    $initializeStructureCalls = [];
    $collectionStructureManager->method('initializeStructureForSite')
        ->willReturnCallback(function (string $collection, string $site, OutputInterface $consoleOutput) use (&$initializeStructureCalls): void {
            $initializeStructureCalls[] = [$collection, $site];
        });

    // Create KbEntryCreator instance with mocked dependencies
    $kbEntryCreator = new KbEntryCreator(
        $this->createMock(EntryCreator::class),
        $this->createMock(MinervaKbSettingsSelector::class)
    );

    // Call the production process method to import taxonomy terms
    try {
        // Process the KB entries to import taxonomy terms
        $kbEntryCreator->process(
            $xmlParser,
            $assetImporter,
            $output,
            'https://example.com',
            'default',
            $siteManager,
            $collectionStructureManager,
            null
        );
    } catch (Exception $e) {
        // Some exceptions are expected due to missing Statamic setup.
        // We're primarily testing taxonomy import, so continue if process fails later.
    }

    // ---
    // `UAMS-Web/uams-statamic#2534` made `knowledge_base_tags` a site-scoped
    // collection, so each tag is an entry on this site, carrying its plain slug
    // and name rather than the site prefix a flat term needed (wordpress-importer-archive#1220). The
    // registry records the entry each term id became, for the article writer.
    // ---
    $tagCollection = CollectionFacade::findByHandle('knowledge_base_tags');

    if (! $tagCollection instanceof Collection) {
        throw new RuntimeException('Expected the knowledge_base_tags collection to exist in the host app.');
    }

    foreach (kbGetTagData() as $tagSlug => $tag) {
        $tagEntry = $this::findEntryBySlug($tagCollection, 'default', $tagSlug);

        expect($tagEntry)->toBeInstanceOf(Entry::class);

        if (! $tagEntry instanceof Entry) {
            continue;
        }

        expect($tagEntry->get('title'))->toBe(kbDataString($tag, 'name'))
            ->and(TermDestinationRegistry::referenceFor('kbtag', kbDataString($tag, 'term_id')))
            ->toBe($tagEntry->id());
    }

    // The import enabled the knowledge_bases collection for the default site once
    expect($enableCollectionCalls)->toBe([['default', 'knowledge_bases']]);

    // The import initialized the knowledge_bases collection structure once
    expect($initializeStructureCalls)->toBe([['knowledge_bases', 'default']]);

    // Clean up variables to free up memory
    unset(
        $xmlPath,
        $xmlParser,
        $assetImporter,
        $output,
        $siteManager,
        $collectionStructureManager,
        $kbEntryCreator
    );
});

it('orchestrates the MinervaKB import workflow', function (): void {
    // Create minimal test XML file (only KB home page needed since parser is mocked)
    $xmlContent = kbBuildSelectiveXml(
        $this->getXmlHeader(),
        $this->getXmlFooter(),
        'knowledge_base',
        'kb'
    );
    $xmlPath = $this->createTempFile('test_kb_selective_');
    file_put_contents(
        $xmlPath,
        $xmlContent
    );

    // Use try-finally block to ensure cleanup occurs even if test fails
    try {
        // Create KbEntryCreator instance with mocked dependencies
        $entryCreator = $this->createMock(EntryCreator::class);
        $minervaKbSettingsSelector = $this->createMock(MinervaKbSettingsSelector::class);
        $kbEntryCreator = new KbEntryCreator(
            $entryCreator,
            $minervaKbSettingsSelector
        );

        // Create a mock XmlParser instance for parsing WordPress XML files
        $xmlParser = $this->createMock(XmlParser::class);

        // Create a mock AssetImporter instance for importing assets
        $assetImporter = $this->createMock(AssetImporter::class);

        // Create a mock OutputInterface instance for console output
        $output = $this->createMock(OutputInterface::class);

        // Create a mock SiteManager instance for managing site configurations
        $siteManager = $this->createMock(SiteManager::class);

        // ---
        // Create a mock CollectionStructureManager instance for managing collection
        // structures
        // ---
        $collectionStructureManager = $this->createMock(CollectionStructureManager::class);

        // Configure mock to return the test XML file path
        $xmlParser->method('getFilePath')->willReturn($xmlPath);

        // Configure mock to return empty array for terms
        $xmlParser->method('getTerms')->willReturn([]);

        // Configure mock to return empty array for items
        $xmlParser->method('getItems')->willReturn([]);

        // ---
        // Record method calls so the orchestration expectations can be asserted
        // explicitly after process() runs. The invocation-count matchers
        // (`$this->atLeastOnce()`, `$this->once()`) cannot be typed under the
        // static analyser, so each call is recorded and verified below; the
        // callback parameter types enforce the argument constraints the original
        // `->with(...)` matchers asserted.
        // ---
        $writelnCalls = 0;
        $output->method('writeln')
            ->willReturnCallback(function () use (&$writelnCalls): void {
                $writelnCalls++;
            });

        // Record the site manager's enable-collection calls
        $enableCollectionCalls = [];
        $siteManager->method('enableCollectionForSite')
            ->willReturnCallback(function (string $site, string $collection, OutputInterface $consoleOutput) use (&$enableCollectionCalls): bool {
                $enableCollectionCalls[] = [$site, $collection];

                return false;
            });

        // Record the collection structure manager's initialize-structure calls
        $initializeStructureCalls = [];
        $collectionStructureManager->method('initializeStructureForSite')
            ->willReturnCallback(function (string $collection, string $site, OutputInterface $consoleOutput) use (&$initializeStructureCalls): void {
                $initializeStructureCalls[] = [$collection, $site];
            });

        // ---
        // Call process method - this will fail if dependencies aren't properly mocked
        // but should complete if everything is set up correctly
        //
        // Note: The process method may throw exceptions in test environment due to
        // missing Statamic collections/entries, but we verify the method can be
        // called and dependencies are properly set up
        // ---
        try {
            // Call the process method to verify it orchestrates the import workflow
            $kbEntryCreator->process(
                $xmlParser,
                $assetImporter,
                $output,
                'https://example.com',
                'default',
                $siteManager,
                $collectionStructureManager,
                null
            );
        } catch (Exception $e) {
            // ---
            // Some exceptions are expected due to missing Statamic collections/entries
            // in test environment. The important thing is that the method structure is correct
            // and dependencies are properly configured. We verify this by ensuring the
            // exception is not related to missing mocks or incorrect method signatures.
            // ---
            expect($e->getMessage())->not->toBeEmpty();
        }

        // process() displayed progress output at least once
        expect($writelnCalls)->toBeGreaterThanOrEqual(1);

        // process() enabled the knowledge_bases collection for the default site once
        expect($enableCollectionCalls)->toBe([['default', 'knowledge_bases']]);

        // process() initialized the knowledge_bases collection structure once
        expect($initializeStructureCalls)->toBe([['knowledge_bases', 'default']]);
    } finally {
        // Check if XML file exists before attempting to delete it
        if (\file_exists($xmlPath)) {
            // Delete the temporary XML file
            unlink($xmlPath);
        }

        // Clean up any test files created during the test
        // ---
        // Define entry slugs to clean up via Statamic's proper deletion method
        //   - Knowledge base entry created by createKnowledgeBaseEntry()
        //   - Topic entries created from kbtopic terms
        //   - Article entries created from kb post types
        // ---
        $kbEntrySlugs = [
            'sample-article-1', // Article entry from kb post type
            'sample-article-2', // Article entry from kb post type
            'sample-article-3', // Article entry from kb post type
            'sample-article-4', // Article entry from kb post type
            'sample-article-5', // Article entry from kb post type
            'sample-article-6', // Article entry from kb post type
            'sample-article-7', // Article entry from kb post type
            'sample-article-8', // Article entry from kb post type
            'sample-article-9', // Article entry from kb post type
            'sample-article-10', // Article entry from kb post type
            'sample-article-11', // Article entry from kb post type
            'sample-article-12', // Article entry from kb post type
            'sample-article-13', // Article entry from kb post type
            'sample-article-14', // Article entry from kb post type
            'sample-topic-1', // Topic entry from kbtopic term
            'sample-topic-2', // Topic entry from kbtopic term
            'sample-topic-3', // Topic entry from kbtopic term
            'sample-topic-1a', // Nested topic entry (child of sample-topic-1)
            'kb', // Knowledge base root entry (fallback slug when kb_page does not resolve)
            'kb-home', // Knowledge base root entry (slug adopted from kb_page since wordpress-importer-archive#331)
        ];

        // Define KB home page entry slug for pages collection cleanup
        $pageEntrySlugs = [
            'kb-home', // Knowledge base home page entry from pages collection
        ];

        // ---
        // No taxonomy terms to clean up: kbtag terms import as
        // `knowledge_base_tags` collection entries (wordpress-importer-archive#1220), which the
        // `snapshotIsolatedState()` restore returns to their prior state.
        // ---
        $taxonomyTerms = [];

        // ---
        // Define asset files specific to KB tests. Attachment items imported
        // from WordPress are stored in the files container. Featured images
        // are stored in the images container.
        // ---
        $assetFiles = [
            'files' => [
                '2025-09-rel-fall-career-expo-2025.pdf',
            ],
            'images' => [
                '2020-05-fpo_100.jpg',
            ],
        ];

        // ---
        // Use the trait's static helper method to do the actual cleanup. Pass
        // knowledge_bases.yaml tree file configuration with deleteOnFailure enabled since
        // this file may be created during tests and needs to be deleted if restore fails
        // ---
        $this::cleanupTestFilesWithPaths(
            $kbEntrySlugs,
            'knowledge_bases', // collection handle
            'default', // site handle
            $assetFiles, // Asset files created during KB tests
            $taxonomyTerms, // Taxonomy terms created during KB tests
            [
                [
                    'file' => 'content/trees/collections/default/knowledge_bases.yaml',
                    'testPatterns' => ['kb'],
                    'deleteOnFailure' => true,
                ],
            ]
        );

        // Clean up KB home page entry from pages collection
        $this::cleanupTestFilesWithPaths(
            $pageEntrySlugs,
            'pages', // collection handle
            'default', // site handle
            [], // No specific asset files
            [], // No taxonomy terms
            [] // No tree files
        );
    }

    // Clean up variables to free up memory
    unset(
        $xmlPath,
        $entryCreator,
        $minervaKbSettingsSelector,
        $xmlParser,
        $assetImporter,
        $output,
        $siteManager,
        $collectionStructureManager,
        $kbEntryCreator
    );
});

it('validates each mapped YAML field for a KB entry', function (
    array $fieldAssertions,
    string $postType,
    string $entryVariantType,
    string $entrySlug,
    ?array $jsonParameters
): void {
    // ---
    // Generate selective XML based on entry type and slug. This creates only the
    // necessary entries for the test being run.
    // ---
    $xmlContent = kbBuildSelectiveXml(
        $this->getXmlHeader(),
        $this->getXmlFooter(),
        $entryVariantType,
        $entrySlug
    );
    $xmlPath = $this->createTempFile('test_kb_selective_');
    file_put_contents(
        $xmlPath,
        $xmlContent
    );

    // Run the import against the generated export
    kbRunImport(
        $xmlPath,
        $this->createMock(OutputInterface::class),
        $jsonParameters
    );

    // Find the specific entry by slug
    $entry = kbFindKnowledgeBaseEntryBySlug($entrySlug);

    // The entry was found
    expect($entry)->not->toBeNull();
    if (! $entry instanceof Entry) {
        throw new RuntimeException("Entry with slug '{$entrySlug}' should exist");
    }

    // Validate parent field based on entry type
    kbValidateParentField(
        $entry,
        $entryVariantType,
        $entrySlug
    );

    // Assert each field for this variant against the single import above (wordpress-importer-archive#391)
    foreach ($fieldAssertions as $assertion) {
        if (! \is_array($assertion)) {
            throw new RuntimeException('Each field assertion must be an array.');
        }

        $field = $assertion['field'] ?? null;
        $type = $assertion['type'] ?? null;

        if (! \is_string($field) || ! \is_string($type)) {
            throw new RuntimeException('Each field assertion needs string "field" and "type" entries.');
        }

        $value = $assertion['value'] ?? null;

        // Resolve topic entry IDs for secondary_topic field when entrySlug is sample-article-3
        if (
            $field === 'secondary_topic'
            && $entrySlug === 'sample-article-3'
            && \is_array($value)
            && \count($value) === 1
            && $value[0] === '00000000-0000-0000-0000-000000000000'
        ) {
            // Find the topic entry by slug
            $topicEntry = kbFindKnowledgeBaseEntryBySlug('sample-topic-2');

            // The topic entry was found
            expect($topicEntry)->not->toBeNull();
            if (! $topicEntry instanceof Entry) {
                throw new RuntimeException("Topic entry with slug 'sample-topic-2' should exist for secondary_topic validation");
            }

            // Replace placeholder with actual topic entry ID
            $value = [$topicEntry->id()];

            // Clean up variables to free up memory
            unset($topicEntry);
        }

        // ---
        // Resolve article entry IDs for related_articles field when entrySlug is
        // sample-article-12
        // ---
        if (
            $field === 'related_articles'
            && $entrySlug === 'sample-article-12'
            && \is_array($value)
            && \count($value) === 1
            && $value[0] === '00000000-0000-0000-0000-000000000000'
        ) {
            // Find the related article entry by slug
            $relatedArticleEntry = kbFindKnowledgeBaseEntryBySlug('sample-article-13');

            // The related article entry was found
            expect($relatedArticleEntry)->not->toBeNull();
            if (! $relatedArticleEntry instanceof Entry) {
                throw new RuntimeException("Article entry with slug 'sample-article-13' should exist for related_articles validation");
            }

            // Replace placeholder with actual article entry ID
            $value = [$relatedArticleEntry->id()];

            // Clean up variables to free up memory
            unset(
                $relatedArticleEntry
            );
        }

        // ---
        // Validate the field against the imported entry. Extract the actual value,
        // process placeholders in the expected value, then assert per data type.
        // ---
        $entryData = kbAsArray($entry->data());

        // `slug` is an entry property, not part of its field data; surface it so
        // the field comparison can assert it alongside the data fields. See wordpress-importer-archive#464.
        $entryData['slug'] = $entry->slug();

        // ---
        // `date` is an entry property, not field data — but it cannot be surfaced by
        // calling `$entry->date()`. On a *dated* collection that getter never returns
        // null: it falls back to the file's mtime, and to `Carbon::now()` when no file
        // exists, so a `field_exists` pin fed from it could not fail under any importer
        // behaviour. The filename is the honest witness — `Entry::buildPath()` prefixes
        // a date only when the raw property was set — so `GetDateFromPath` sees a date
        // exactly when the importer wrote one, and the pin goes red when it did not.
        // See wordpress-importer-archive#609.
        // ---
        $importerSetDate = (new GetDateFromPath)($entry->path());

        if ($importerSetDate !== null) {
            $entryData['date'] = $importerSetDate;
        }

        $actualValue = $this->getNestedValue(
            $entryData,
            $field
        );

        // Check if this is a required field (not optional and not just existence check)
        if (
            \strpos(
                $type,
                'optional_'
            ) !== 0
            && $type !== 'field_exists'
            && $type !== 'field_missing'
        ) {
            // Check if field name contains a dot (nested field path)
            if (
                \strpos(
                    $field,
                    '.'
                ) !== false
            ) {
                // Field '{$field}' should exist in production output
                if ($actualValue === null) {
                    throw new RuntimeException("Field '{$field}' should exist in production output");
                }
            } else {
                // Field '{$field}' should exist in production output
                expect($entryData)->toHaveKey($field);
            }
        }

        // Process placeholders in expected value before comparison
        $processedExpectedValue = $this->processPlaceholders(
            $value,
            $actualValue
        );

        // Validate the field based on its expected data type
        switch ($type) {
            case 'string':
                // Field '{$field}' should be a string
                if (! \is_string($actualValue)) {
                    throw new RuntimeException("Field '{$field}' should be a string");
                }
                expect($actualValue)->toEqual($processedExpectedValue);
                break;

            case 'optional_string':
                // ---
                // A pinned expectation makes the field REQUIRED. Guarding the whole
                // body on `$actualValue !== null` meant an absent field skipped the
                // comparison and passed — including where a concrete value was
                // pinned for it, so the assertion held whether or not the importer
                // wrote the field at all (wordpress-importer-archive#609).
                // ---
                if ($processedExpectedValue !== null) {
                    if (! \is_string($actualValue)) {
                        throw new RuntimeException(
                            "Field '{$field}' pins an expected value but is not a string; "
                            .'the importer did not write it.'
                        );
                    }

                    expect($actualValue)->toEqual($processedExpectedValue);

                    break;
                }

                // Nothing pinned: absence is genuinely optional, but a present
                // value must still be the right type.
                if ($actualValue !== null && ! \is_string($actualValue)) {
                    throw new RuntimeException("Field '{$field}' should be a string if set");
                }
                break;

            case 'integer':
                // Field '{$field}' should be an integer
                if (! \is_int($actualValue)) {
                    throw new RuntimeException("Field '{$field}' should be an integer");
                }
                expect($actualValue)->toEqual($processedExpectedValue);
                break;

            case 'optional_integer':
                // ---
                // A pinned expectation makes the field REQUIRED. Guarding the whole
                // body on `$actualValue !== null` meant an absent field skipped the
                // comparison and passed — including where a concrete value was
                // pinned for it, so the assertion held whether or not the importer
                // wrote the field at all (wordpress-importer-archive#609).
                // ---
                if ($processedExpectedValue !== null) {
                    if (! \is_int($actualValue)) {
                        throw new RuntimeException(
                            "Field '{$field}' pins an expected value but is not an integer; "
                            .'the importer did not write it.'
                        );
                    }

                    expect($actualValue)->toEqual($processedExpectedValue);

                    break;
                }

                // Nothing pinned: absence is genuinely optional, but a present
                // value must still be the right type.
                if ($actualValue !== null && ! \is_int($actualValue)) {
                    throw new RuntimeException("Field '{$field}' should be an integer if set");
                }
                break;

            case 'boolean':
                // Field '{$field}' should be a boolean
                if (! \is_bool($actualValue)) {
                    throw new RuntimeException("Field '{$field}' should be a boolean");
                }
                expect($actualValue)->toEqual($processedExpectedValue);
                break;

            case 'optional_boolean':
                // ---
                // A pinned expectation makes the field REQUIRED. Guarding the whole
                // body on `$actualValue !== null` meant an absent field skipped the
                // comparison and passed — including where a concrete value was
                // pinned for it, so the assertion held whether or not the importer
                // wrote the field at all (wordpress-importer-archive#609).
                // ---
                if ($processedExpectedValue !== null) {
                    if (! \is_bool($actualValue)) {
                        throw new RuntimeException(
                            "Field '{$field}' pins an expected value but is not a boolean; "
                            .'the importer did not write it.'
                        );
                    }

                    expect($actualValue)->toEqual($processedExpectedValue);

                    break;
                }

                // Nothing pinned: absence is genuinely optional, but a present
                // value must still be the right type.
                if ($actualValue !== null && ! \is_bool($actualValue)) {
                    throw new RuntimeException("Field '{$field}' should be a boolean if set");
                }
                break;

            case 'array':
                // Field '{$field}' should be an array
                if (! \is_array($actualValue)) {
                    throw new RuntimeException("Field '{$field}' should be an array");
                }

                // The dump hangs off the assertion, never a predicate beside it: a
                // separate `!==` gate drifts from a loose `toEqual` and prints
                // "comparison failed" on a run that passes (wordpress-importer-archive#616, wordpress-importer-archive#737). The raw
                // values are still worth showing — `toEqual` diffs the pair it was
                // given, which hides what arrived before normalization.
                try {
                    expect($actualValue)->toEqual($processedExpectedValue);
                } catch (ExpectationFailedException $comparisonFailure) {
                    echo "\n=== DEBUG: Field '{$field}' comparison failed ===\n";
                    echo "Expected value:\n";
                    var_dump($processedExpectedValue);
                    echo "\nActual value:\n";
                    var_dump($actualValue);
                    echo "==========================================\n\n";

                    throw $comparisonFailure;
                }
                break;

            case 'optional_array':
                // ---
                // Two skips, not one: an absent field skipped the comparison, and
                // so did an EMPTY array — so a pinned expectation passed against
                // both `null` and `[]` (wordpress-importer-archive#609). A pinned value makes the field
                // required and non-empty.
                // ---
                if ($processedExpectedValue !== null && $actualValue === null) {
                    throw new RuntimeException(
                        "Field '{$field}' pins an expected value but is absent; "
                        .'the importer did not write it.'
                    );
                }

                if ($actualValue !== null) {
                    // Field '{$field}' should be an array if set
                    if (! \is_array($actualValue)) {
                        throw new RuntimeException("Field '{$field}' should be an array if set");
                    }

                    // Check if expected value is provided
                    if ($processedExpectedValue !== null) {
                        // Same shape as the `array` case above: the assertion is the
                        // trigger, so the dump cannot drift out of step with it.
                        try {
                            expect($actualValue)->toEqual($processedExpectedValue);
                        } catch (ExpectationFailedException $comparisonFailure) {
                            echo "\n=== DEBUG: Field '{$field}' comparison failed ===\n";
                            echo "Expected value:\n";
                            var_dump($processedExpectedValue);
                            echo "\nActual value:\n";
                            var_dump($actualValue);
                            echo "==========================================\n\n";

                            throw $comparisonFailure;
                        }
                    }
                }
                break;

            case 'field_exists':
                // ---
                // This asserted nothing. The pre-switch existence check skips the
                // type by name — `&& $type !== 'field_exists'` — so the comment
                // that once stood here, "already checked above", described the
                // opposite of what the code does (wordpress-importer-archive#609). Assert it here instead,
                // mirroring that check's nested-path handling.
                // ---
                if (\strpos($field, '.') !== false) {
                    if ($actualValue === null) {
                        throw new RuntimeException("Field '{$field}' should exist in production output");
                    }

                    break;
                }

                expect($entryData)->toHaveKey($field);
                break;

            case 'field_missing':
                // ---
                // The inverse assertion: the importer must NOT write this handle. No
                // existing type can say that — the `optional_*` types tolerate absence
                // but tolerate presence just as happily, while `string` and
                // `field_exists` both *require* the key. Needed for a retired field,
                // where writing it at all is the defect (wordpress-importer-archive#433).
                // ---
                expect($entryData)->not->toHaveKey($field);
                break;

            default:
                throw new RuntimeException("Unknown expected type '{$type}' for field '{$field}'");
        }

        // Clean up variables to free up memory
        unset(
            $entryData,
            $actualValue,
            $processedExpectedValue
        );
    }

    // Check if XML file exists before attempting to delete it
    if (\file_exists($xmlPath)) {
        // Delete the temporary XML file
        unlink($xmlPath);
    }

    // ---
    // Clean up variables to free up memory. The import collaborators are no longer
    // named here: they are locals of kbRunImport() and are released when it returns.
    // ---
    unset(
        $xmlPath,
        $jsonParameters,
        $entry
    );
})->with(kbYamlFieldValidationProvider());

it('leaves the knowledge-base featured-image keys unset when a credited image fails to import', function (): void {
    // ---
    // The conjunct wordpress-importer-archive#751 added to the knowledge-base gate, and the state no existing
    // fixture could reach. With uploads disabled the importer returns the
    // attachment's URL verbatim, so an attachment row whose URL has gone yields ''
    // rather than null — and the credit toggle must not be written beside an image
    // that is not there (wordpress-importer-archive#439).
    //
    // `sample-article-15` is the credited-featured-image fixture the data provider
    // already uses; it is reused here with the attachment's URL removed, so the only
    // difference from the passing variant is the state under test.
    // ---
    Config::set('wordpress-import.upload_images', false);

    $article = kbGetArticleData()['sample-article-15'];

    // Assemble the export the way kbGetArticleXml() does, so the terms this article
    // needs exist before it is imported
    $xmlContent = $this->getXmlHeader().kbGetKbHomePageXml();

    foreach ($article['topics'] as $topicSlug) {
        $xmlContent .= kbGetTopicXml($topicSlug);
    }

    foreach ($article['tags'] as $tagSlug) {
        $xmlContent .= kbGetTagXml($tagSlug);
    }

    // The article, then its credited attachment stripped of the URL
    $xmlContent .= kbGetSingleArticleXml($article)
        .kbGetImageAttachmentXml(
            '997',
            'Bruce Wayne/UAMS',
            true
        )
        .$this->getXmlFooter();

    $xmlPath = $this->createTempFile('test_kb_credited_without_url_');
    file_put_contents(
        $xmlPath,
        $xmlContent
    );

    kbRunImport(
        $xmlPath,
        $this->createMock(OutputInterface::class)
    );

    $entry = kbFindKnowledgeBaseEntryBySlug('sample-article-15');

    // The article still imports — the missing image must not cost the entry
    expect($entry)->not->toBeNull();
    if (! $entry instanceof Entry) {
        throw new RuntimeException("Entry with slug 'sample-article-15' should exist");
    }

    $entryData = kbAsArray($entry->data());

    // ---
    // Key absence, not falsiness: a `featured_image_credit_query` of `false` would be
    // a written toggle rather than an unwritten one, and the blueprint default is what
    // an uncredited article is supposed to keep.
    //
    // Only the FIRST of these two can fail, and the asymmetry is measured rather than
    // assumed. Dropping `&& $featuredImage !== ''` writes `featured_image_16_9 = ''`,
    // which the entry discards on the way to disk alongside `null` — so that key is
    // absent whether the gate holds or not. Running the mutation with the assertions
    // in the reverse order confirmed it: the `featured_image_16_9` expectation passed
    // and only the toggle went red (macOS, 2026-08-27). The toggle is written as
    // `true`, which survives the strip, and is therefore what makes the empty-string
    // arm observable at all. The second assertion bounds the behavior; it does not
    // pin this gate.
    // ---
    expect(\array_key_exists('featured_image_credit_query', $entryData))->toBeFalse()
        ->and(\array_key_exists('featured_image_16_9', $entryData))->toBeFalse();
});

/**
 * Data provider for individual YAML field validation tests.
 *
 * Each field is tested separately to make it easier to identify
 * which specific fields are failing during import validation.
 *
 * @return array<string, array<string, mixed>> Map of variant label to its XML parameters and per-field assertions
 */
function kbYamlFieldValidationProvider(): array
{
    // Base variables

    // General base variable

    $base = [
        'xml_parameters' => [
            'postType' => 'kb',
            'entryVariantType' => null,
            'entrySlug' => null,
        ],
        'yaml_assertions' => [
            'blueprint' => [
                'field' => 'blueprint',
                'value' => 'article',
                'type' => 'string',
            ],
            'title' => [
                'field' => 'title',
                'value' => 'Sample Article 1',
                'type' => 'string',
            ],
            'slug' => [
                'field' => 'slug',
                'value' => 'sample-article-1',
                'type' => 'string',
            ],
            'title_options' => [
                'field' => 'title_options',
                'value' => null, // Uses blueprint default
                'type' => 'optional_string',
            ],
            'header_graphic' => [
                'field' => 'header_graphic',
                'value' => [
                    'body' => null,
                    'image' => null,
                ],
                'type' => 'optional_array',
            ],
            'title_short' => [
                'field' => 'title_short',
                'value' => null,
                'type' => 'optional_string',
            ],
            'excerpt' => [
                'field' => 'excerpt',
                'value' => null,
                'type' => 'optional_string',
            ],
            'featured_image_16_9' => [
                'field' => 'featured_image_16_9',
                'value' => null,
                'type' => 'optional_string',
            ],
            'restriction_query' => [
                'field' => 'restriction_query',
                'value' => null,
                'type' => 'optional_boolean',
            ],
            'restriction_message' => [
                'field' => 'restriction_message',
                'value' => null,
                'type' => 'optional_string',
            ],
            'restriction_category' => [
                'field' => 'restriction_category',
                'value' => null,
                'type' => 'optional_array',
            ],
            'restriction_users' => [
                'field' => 'restriction_users',
                'value' => null,
                'type' => 'optional_array',
            ],
            'restriction_user_roles' => [
                'field' => 'restriction_user_roles',
                'value' => null,
                'type' => 'optional_array',
            ],
            'restriction_user_groups' => [
                'field' => 'restriction_user_groups',
                'value' => null,
                'type' => 'optional_array',
            ],
            'date' => [
                'field' => 'date',
                'value' => null,
                'type' => 'field_exists',
            ],
            'template' => [
                'field' => 'template',
                'value' => null,
                'type' => 'field_exists',
            ],
            'ancestor_restriction_enabled' => [
                'field' => 'ancestor_restriction_enabled',
                'value' => null,
                'type' => 'optional_boolean',
            ],
            'import_wordpress_notes' => [
                'field' => 'import_wordpress_notes',
                'value' => null,
                'type' => 'optional_array',
            ],
        ],
        'json_parameters' => [
            'kb_page' => '123',
            'home_sections_switch' => false,
            'home_sections' => 'search,topics',
            'home_topics_limit' => '-1',
            'home_topics_show_description' => true,
            'show_all_label' => 'Show All',
            'search_title' => 'Search the Knowledge Base',
            'search_placeholder' => 'e.g., Guidelines',
            'last_modified_date_text' => 'Last modified:',
            'show_reading_estimate' => false,
            'show_related_articles' => true,
            'related_articles_label' => 'Related Articles',
            'show_article_author' => false,
            'article_attach_label' => 'Attachments',
            'show_topic_description' => true,
            'show_search_page_last_edit' => true,
            'show_search_page_excerpt' => true,
            'topic_articles_per_page' => '-1',
            'add_topic_search' => false,
            'breadcrumbs_home_label' => 'Knowledge Base',
            'toc_dynamic_enable' => false,
            'toc_headings_exclude' => '',
            'toc_label' => 'In This Article',
            'restrict_on' => false,
            'restrict_article_role' => '[\"guest\"]',
            'restrict_article_message' => 'The content you are trying to access is for certain users only. Please log in to view it.',
            'cpt_topic_label_name' => 'Topics',
        ],
    ];

    // Article base variable

    $base_article = $base;
    $base_article['base_test_name'] = 'article entry with ';
    $base_article['xml_parameters']['entryVariantType'] = 'article';
    $base_article['xml_parameters']['entrySlug'] = 'sample-article-1';
    $base_article['yaml_assertions']['blueprint']['value'] = 'article';
    $base_article['yaml_assertions']['title_options']['type'] = 'field_exists';
    unset($base_article['yaml_assertions']['header_graphic']);
    unset($base_article['yaml_assertions']['title_short']);
    $base_article['yaml_assertions']['restriction_query']['value'] = false;
    $base_article['yaml_assertions']['restriction_query']['type'] = 'boolean';
    $base_article['yaml_assertions']['content'] = [
        'field' => 'content',
        'value' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'This is sample article content for testing purposes.',
                    ],
                ],
                'attrs' => [
                    'id' => null,
                ],
            ],
        ],
        'type' => 'array',
    ];
    $base_article['yaml_assertions']['original_wordpress_url'] = [
        'field' => 'original_wordpress_url',
        'value' => 'https://example.com/kb/sample-article-1/',
        'type' => 'string',
    ];
    $base_article['yaml_assertions']['tags'] = [
        'field' => 'tags',
        // A `knowledge_base_tags` entry id, generated per import, so matched by
        // shape (wordpress-importer-archive#1220)
        'value' => ['#rowId(36)'],
        'type' => 'array',
    ];
    $base_article['yaml_assertions']['secondary_topic'] = [
        'field' => 'secondary_topic',
        'value' => null,
        'type' => 'optional_array',
    ];
    $base_article['yaml_assertions']['attachment'] = [
        'field' => 'attachment',
        'value' => null,
        'type' => 'optional_array',
    ];
    $base_article['yaml_assertions']['related_articles'] = [
        'field' => 'related_articles',
        'value' => null,
        'type' => 'optional_array',
    ];

    // Topic base variable

    $base_topic = $base;
    $base_topic['base_test_name'] = 'topic entry with ';
    $base_topic['xml_parameters']['entryVariantType'] = 'topic';
    $base_topic['xml_parameters']['entrySlug'] = 'sample-topic-1';
    $base_topic['yaml_assertions']['blueprint']['value'] = 'topic';
    $base_topic['yaml_assertions']['title']['value'] = 'Sample Topic 1';
    $base_topic['yaml_assertions']['slug']['value'] = 'sample-topic-1';

    // Knowledge Base base variable

    $base_knowledge_base = $base;
    $base_knowledge_base['base_test_name'] = 'knowledge base entry with ';
    $base_knowledge_base['xml_parameters']['entryVariantType'] = 'knowledge_base';
    // Since wordpress-importer-archive#331 the KB root entry adopts the MinervaKB home page's slug
    // ('kb-home', derived from the kb_page) and embeds its WordPress ID,
    // rather than using the literal 'kb' slug.
    $base_knowledge_base['xml_parameters']['entrySlug'] = 'kb-home';
    $base_knowledge_base['yaml_assertions']['blueprint']['value'] = 'knowledge_base';
    $base_knowledge_base['yaml_assertions']['title']['value'] = 'University of Arkansas for Medical Sciences Knowledge Base';
    $base_knowledge_base['yaml_assertions']['slug']['value'] = 'kb-home';
    $base_knowledge_base['yaml_assertions']['knowledge_base_search_query'] = [
        'field' => 'knowledge_base_search_query',
        'value' => true,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['topic_label'] = [
        'field' => 'topic_label',
        'value' => 'Topics',
        'type' => 'string',
    ];
    $base_knowledge_base['yaml_assertions']['topic_limit'] = [
        'field' => 'topic_limit',
        'value' => -1,
        'type' => 'integer',
    ];
    $base_knowledge_base['yaml_assertions']['show_all_topics_label'] = [
        'field' => 'show_all_topics_label',
        'value' => 'Show All',
        'type' => 'string',
    ];
    $base_knowledge_base['yaml_assertions']['topic_description_query'] = [
        'field' => 'topic_description_query',
        'value' => true,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['topic_image_query'] = [
        'field' => 'topic_image_query',
        'value' => true,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['restriction_enabled'] = [
        'field' => 'restriction_enabled',
        'value' => false,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['knowledge_base_search_title'] = [
        'field' => 'knowledge_base_search_title',
        'value' => 'Search the Knowledge Base',
        'type' => 'string',
    ];
    $base_knowledge_base['yaml_assertions']['knowledge_base_search_placeholder'] = [
        'field' => 'knowledge_base_search_placeholder',
        'value' => 'e.g., Guidelines',
        'type' => 'string',
    ];
    $base_knowledge_base['yaml_assertions']['restriction_global_query'] = [
        'field' => 'restriction_global_query',
        'value' => null,
        'type' => 'optional_boolean',
    ];
    $base_knowledge_base['yaml_assertions']['topic_search_query'] = [
        'field' => 'topic_search_query',
        'value' => false,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['topic_search_bar_title'] = [
        'field' => 'topic_search_bar_title',
        'value' => null,
        'type' => 'optional_string',
    ];
    $base_knowledge_base['yaml_assertions']['topic_search_placeholder'] = [
        'field' => 'topic_search_placeholder',
        'value' => null,
        'type' => 'optional_string',
    ];
    $base_knowledge_base['yaml_assertions']['subtopic_label'] = [
        'field' => 'subtopic_label',
        'value' => 'Subtopics',
        'type' => 'string',
    ];
    $base_knowledge_base['yaml_assertions']['subtopic_limit'] = [
        'field' => 'subtopic_limit',
        'value' => -1,
        'type' => 'integer',
    ];
    $base_knowledge_base['yaml_assertions']['subtopic_description_query'] = [
        'field' => 'subtopic_description_query',
        'value' => true,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['subtopic_image_query'] = [
        'field' => 'subtopic_image_query',
        'value' => true,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['show_all_subtopics_label'] = [
        'field' => 'show_all_subtopics_label',
        'value' => 'Show All',
        'type' => 'string',
    ];
    $base_knowledge_base['yaml_assertions']['topic_article_label'] = [
        'field' => 'topic_article_label',
        'value' => 'Articles',
        'type' => 'string',
    ];
    $base_knowledge_base['yaml_assertions']['topic_article_count'] = [
        'field' => 'topic_article_count',
        'value' => -1,
        'type' => 'integer',
    ];
    $base_knowledge_base['yaml_assertions']['topic_article_show_all_label'] = [
        'field' => 'topic_article_show_all_label',
        'value' => 'Show All',
        'type' => 'string',
    ];
    $base_knowledge_base['yaml_assertions']['topic_article_last_modified_query'] = [
        'field' => 'topic_article_last_modified_query',
        'value' => false,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['topic_article_description_query'] = [
        'field' => 'topic_article_description_query',
        'value' => true,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['topic_article_image_query'] = [
        'field' => 'topic_article_image_query',
        'value' => true,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['article_last_modified_query'] = [
        'field' => 'article_last_modified_query',
        'value' => true,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['article_last_modified_label'] = [
        'field' => 'article_last_modified_label',
        'value' => null,
        'type' => 'optional_string',
    ];
    $base_knowledge_base['yaml_assertions']['article_creator_query'] = [
        'field' => 'article_creator_query',
        'value' => false,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['article_estimated_reading_time_query'] = [
        'field' => 'article_estimated_reading_time_query',
        'value' => false,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['toc_query'] = [
        'field' => 'toc_query',
        'value' => false,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['toc_heading_exclude'] = [
        'field' => 'toc_heading_exclude',
        'value' => null,
        'type' => 'optional_array',
    ];
    $base_knowledge_base['yaml_assertions']['toc_label'] = [
        'field' => 'toc_label',
        'value' => null,
        'type' => 'optional_string',
    ];
    $base_knowledge_base['yaml_assertions']['attachments_label'] = [
        'field' => 'attachments_label',
        'value' => 'Attachments',
        'type' => 'string',
    ];
    $base_knowledge_base['yaml_assertions']['related_articles_query'] = [
        'field' => 'related_articles_query',
        'value' => true,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['related_articles_label'] = [
        'field' => 'related_articles_label',
        'value' => 'Related Articles',
        'type' => 'string',
    ];
    $base_knowledge_base['yaml_assertions']['hide_from_menu'] = [
        'field' => 'hide_from_menu',
        'value' => false,
        'type' => 'boolean',
    ];
    $base_knowledge_base['yaml_assertions']['nav_alt_name'] = [
        'field' => 'nav_alt_name',
        'value' => null,
        'type' => 'optional_string',
    ];

    // ---
    // Note: parent field will be tested dynamically since it depends on entry IDs
    // that are generated at runtime. We'll need to handle this in the test method.
    // ---

    // Initialize entry variant types array
    $entryVariantTypes = [];

    // Construct entry variant types

    // Article entry with defaults

    $article_default = $base_article;
    $article_default['base_test_name'] .= 'defaults';

    // Add Article entry with defaults to test cases
    $entryVariantTypes[] = $article_default;

    // Article entry with non-default tags

    $article_tags = $base_article;
    $article_tags['base_test_name'] .= 'tags';
    $article_tags['xml_parameters']['entrySlug'] = 'sample-article-2';
    $article_tags['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_tags['yaml_assertions']['tags'] = $base_article['yaml_assertions']['tags'];
    $article_tags['yaml_assertions']['tags']['value'] = [
        '#rowId(36)',
        '#rowId(36)',
    ];

    // Add Article entry with non-default tags to test cases
    $entryVariantTypes[] = $article_tags;

    // Article entry with non-default secondary_topic

    $article_secondary_topic = $base_article;
    $article_secondary_topic['base_test_name'] .= 'secondary_topic';
    $article_secondary_topic['xml_parameters']['entrySlug'] = 'sample-article-3';
    $article_secondary_topic['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_secondary_topic['yaml_assertions']['secondary_topic'] = $base_article['yaml_assertions']['secondary_topic'];
    $article_secondary_topic['yaml_assertions']['secondary_topic']['value'] = [
        '00000000-0000-0000-0000-000000000000', // Placeholder - will be resolved to actual topic entry ID at test time
    ];

    // Add Article entry with non-default secondary_topic to test cases
    $entryVariantTypes[] = $article_secondary_topic;

    // Article entry with non-default attachment external file with label and no size

    $article_attachment_external_file_with_label = $base_article;
    $article_attachment_external_file_with_label['base_test_name'] .= 'attachment external file with label and no size';
    $article_attachment_external_file_with_label['xml_parameters']['entrySlug'] = 'sample-article-4';
    $article_attachment_external_file_with_label['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_attachment_external_file_with_label['yaml_assertions']['attachment'] = $base_article['yaml_assertions']['attachment'];
    $article_attachment_external_file_with_label['yaml_assertions']['attachment']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'external_file',
            'enabled' => true,
            'label' => 'BAR',
            'url' => 'https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf',
        ],
    ];

    // Add Article entry with non-default attachment to test cases
    $entryVariantTypes[] = $article_attachment_external_file_with_label;

    // Article entry with non-default attachment external file with label and size

    $article_attachment_external_file_with_label_and_size = $base_article;
    $article_attachment_external_file_with_label_and_size['base_test_name'] .= 'attachment external file with label and size';
    $article_attachment_external_file_with_label_and_size['xml_parameters']['entrySlug'] = 'sample-article-5';
    $article_attachment_external_file_with_label_and_size['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_attachment_external_file_with_label_and_size['yaml_assertions']['attachment'] = $base_article['yaml_assertions']['attachment'];
    $article_attachment_external_file_with_label_and_size['yaml_assertions']['attachment']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'external_file',
            'enabled' => true,
            'label' => 'BAR',
            'url' => 'https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf',
        ],
    ];

    // Add Article entry with non-default attachment to test cases
    $entryVariantTypes[] = $article_attachment_external_file_with_label_and_size;

    // Article entry with non-default attachment external file with size and no label

    $article_attachment_external_file_with_size = $base_article;
    $article_attachment_external_file_with_size['base_test_name'] .= 'attachment external file with size and no label';
    $article_attachment_external_file_with_size['xml_parameters']['entrySlug'] = 'sample-article-6';
    $article_attachment_external_file_with_size['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_attachment_external_file_with_size['yaml_assertions']['attachment'] = $base_article['yaml_assertions']['attachment'];
    $article_attachment_external_file_with_size['yaml_assertions']['attachment']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'external_file',
            'enabled' => true,
            'label' => 'https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf',
            'url' => 'https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf',
        ],
    ];

    // Add Article entry with non-default attachment to test cases
    $entryVariantTypes[] = $article_attachment_external_file_with_size;

    // Article entry with non-default attachment external file with URL only

    $article_attachment_external_file_with_url_only = $base_article;
    $article_attachment_external_file_with_url_only['base_test_name'] .= 'attachment external file with url only';
    $article_attachment_external_file_with_url_only['xml_parameters']['entrySlug'] = 'sample-article-7';
    $article_attachment_external_file_with_url_only['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_attachment_external_file_with_url_only['yaml_assertions']['attachment'] = $base_article['yaml_assertions']['attachment'];
    $article_attachment_external_file_with_url_only['yaml_assertions']['attachment']['value'] = [
        [
            'id' => '#rowId(8)',
            'type' => 'external_file',
            'enabled' => true,
            'label' => 'https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf',
            'url' => 'https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf',
        ],
    ];

    // Add Article entry with non-default attachment to test cases
    $entryVariantTypes[] = $article_attachment_external_file_with_url_only;

    // Article entry with non-default attachment asset with no label override

    $article_attachment_asset_no_label = $base_article;
    $article_attachment_asset_no_label['base_test_name'] .= 'attachment asset with no label override';
    $article_attachment_asset_no_label['xml_parameters']['entrySlug'] = 'sample-article-8';
    $article_attachment_asset_no_label['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_attachment_asset_no_label['yaml_assertions']['attachment'] = $base_article['yaml_assertions']['attachment'];
    $article_attachment_asset_no_label['yaml_assertions']['attachment']['value'] = [
        [
            'id' => '#rowId(8)',
            'asset' => 'default/2025-09-rel-fall-career-expo-2025.pdf',
            'type' => 'asset',
            'enabled' => true,
        ],
    ];

    // Add Article entry with non-default attachment to test cases
    $entryVariantTypes[] = $article_attachment_asset_no_label;

    // Article entry with non-default short excerpt

    $article_excerpt_short = $base_article;
    $article_excerpt_short['base_test_name'] .= 'excerpt short';
    $article_excerpt_short['xml_parameters']['entrySlug'] = 'sample-article-9';
    $article_excerpt_short['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_excerpt_short['yaml_assertions']['excerpt'] = $base_article['yaml_assertions']['excerpt'];
    $article_excerpt_short['yaml_assertions']['excerpt']['value'] = 'BAR';

    // Add Article entry with non-default excerpt to test cases
    $entryVariantTypes[] = $article_excerpt_short;

    // Article entry with non-default excerpt longer than 160 characters

    $article_excerpt_long = $base_article;
    $article_excerpt_long['base_test_name'] .= 'excerpt long';
    $article_excerpt_long['xml_parameters']['entrySlug'] = 'sample-article-10';
    $article_excerpt_long['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_excerpt_long['yaml_assertions']['excerpt'] = $base_article['yaml_assertions']['excerpt'];
    $article_excerpt_long['yaml_assertions']['excerpt']['value'] = 'Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor. Aenean massa. Cum sociis natoque penatibus et magnis dis parturie...';
    $article_excerpt_long['yaml_assertions']['import_wordpress_notes'] = $base_article['yaml_assertions']['import_wordpress_notes'];
    $article_excerpt_long['yaml_assertions']['import_wordpress_notes']['value'] = [
        'Original excerpt value: Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor. Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.',
    ];

    // Add Article entry with non-default excerpt to test cases
    $entryVariantTypes[] = $article_excerpt_long;

    // ---
    // Article entry with non-default featured image. The handle is load-bearing:
    // `UAMS-Web/uams-statamic#1553` retired the single `featured_image` field from
    // the `knowledge_bases` blueprints, so an import that writes it produces an
    // orphaned key that never resolves to an asset (wordpress-importer-archive#433).
    // ---

    $article_featured_image = $base_article;
    $article_featured_image['base_test_name'] .= 'featured_image_16_9';
    $article_featured_image['xml_parameters']['entrySlug'] = 'sample-article-11';
    $article_featured_image['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_featured_image['yaml_assertions']['featured_image_16_9'] = $base_article['yaml_assertions']['featured_image_16_9'];
    $article_featured_image['yaml_assertions']['featured_image_16_9']['value'] = 'default/2020-05-fpo_100.jpg';

    // ---
    // `string`, not the inherited `optional_string`: this variant exists to prove the
    // image lands. When wordpress-importer-archive#433 chose `string`, `optional_string` skipped its comparison
    // entirely on an absent field, so it passed whether or not the importer wrote
    // anything; wordpress-importer-archive#609 closed that hole. `string` stays because it is still the stricter
    // statement of intent — this variant must never tolerate absence.
    // ---
    $article_featured_image['yaml_assertions']['featured_image_16_9']['type'] = 'string';

    // The retired handle must not be written alongside it
    $article_featured_image['yaml_assertions']['featured_image'] = [
        'field' => 'featured_image',
        'value' => null,
        'type' => 'field_missing',
    ];

    // ---
    // Attachment 998 carries no postmeta, so the imported asset lands with no
    // `creator` and the toggle must stay on the blueprint default. Writing it
    // `false` would be the inert toggle wordpress-importer-archive#439 exists to prevent, so assert the key
    // is absent rather than falsy.
    // ---
    $article_featured_image['yaml_assertions']['featured_image_credit_query'] = [
        'field' => 'featured_image_credit_query',
        'value' => null,
        'type' => 'field_missing',
    ];

    // Add Article entry with non-default featured image to test cases
    $entryVariantTypes[] = $article_featured_image;

    // ---
    // Article entry whose featured image carries a photo credit. wordpress-importer-archive#433 repointed the
    // KB featured image onto `featured_image_16_9`, which lifted the reason this
    // collection was excluded from the wordpress-importer-archive#439 credit rule; wordpress-importer-archive#603 extended the rule to
    // it. Before that, a credited WordPress image imported with its attribution
    // hidden.
    // ---

    $article_featured_image_credited = $base_article;
    $article_featured_image_credited['base_test_name'] .= 'featured_image_credit_query';
    $article_featured_image_credited['xml_parameters']['entrySlug'] = 'sample-article-15';
    $article_featured_image_credited['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults

    // The image has to have landed, or the toggle beside it would be inert.
    $article_featured_image_credited['yaml_assertions']['featured_image_16_9'] = $base_article['yaml_assertions']['featured_image_16_9'];
    $article_featured_image_credited['yaml_assertions']['featured_image_16_9']['value'] = 'default/2020-05-fpo_100.jpg';
    $article_featured_image_credited['yaml_assertions']['featured_image_16_9']['type'] = 'string';

    // And the credit the WordPress site displayed is surfaced rather than hidden.
    $article_featured_image_credited['yaml_assertions']['featured_image_credit_query'] = [
        'field' => 'featured_image_credit_query',
        'value' => true,
        'type' => 'boolean',
    ];

    // Add Article entry with a credited featured image to test cases
    $entryVariantTypes[] = $article_featured_image_credited;

    // Article entry with non-default related_articles

    $article_related_articles = $base_article;
    $article_related_articles['base_test_name'] .= 'related_articles';
    $article_related_articles['xml_parameters']['entrySlug'] = 'sample-article-12';
    $article_related_articles['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_related_articles['yaml_assertions']['related_articles'] = $base_article['yaml_assertions']['related_articles'];
    $article_related_articles['yaml_assertions']['related_articles']['value'] = [
        '00000000-0000-0000-0000-000000000000', // Placeholder - will be resolved to actual topic entry ID at test time
    ];

    // Add Article entry with non-default related_articles to test cases
    $entryVariantTypes[] = $article_related_articles;

    // Article entry with non-default restriction

    $article_restriction = $base_article;
    $article_restriction['base_test_name'] .= 'restriction';
    $article_restriction['xml_parameters']['entrySlug'] = 'sample-article-14';
    $article_restriction['json_parameters']['restrict_on'] = true;
    $article_restriction['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $article_restriction['yaml_assertions']['restriction_query'] = $base_article['yaml_assertions']['restriction_query'];
    $article_restriction['yaml_assertions']['restriction_query']['value'] = true;
    $article_restriction['yaml_assertions']['restriction_category'] = $base_article['yaml_assertions']['restriction_category'];
    $article_restriction['yaml_assertions']['restriction_category']['value'] = [
        'user_roles',
    ];
    $article_restriction['yaml_assertions']['restriction_user_roles'] = $base_article['yaml_assertions']['restriction_user_roles'];
    $article_restriction['yaml_assertions']['restriction_user_roles']['value'] = null;
    $article_restriction['yaml_assertions']['import_wordpress_notes'] = $base_article['yaml_assertions']['import_wordpress_notes'];
    $article_restriction['yaml_assertions']['import_wordpress_notes']['value'] = [
        'restriction_user_roles: "editor"',
    ];

    // Add Article entry with non-default restriction to test cases
    $entryVariantTypes[] = $article_restriction;

    // Topic with defaults

    $topic_default = $base_topic;
    $topic_default['base_test_name'] .= 'defaults';

    // Add topic with defaults entry to test cases
    $entryVariantTypes[] = $topic_default;

    // Topic entry with non-default wp:term_description

    $topic_term_description = $base_topic;
    $topic_term_description['base_test_name'] .= 'term_description';
    $topic_term_description['xml_parameters']['entrySlug'] = 'sample-topic-3';
    $topic_term_description['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $topic_term_description['yaml_assertions']['excerpt'] = $base_topic['yaml_assertions']['excerpt'];
    $topic_term_description['yaml_assertions']['excerpt']['value'] = 'This is a sample topic description for testing purposes.';

    // Add Topic entry with non-default wp:term_description to test cases
    $entryVariantTypes[] = $topic_term_description;

    // Nested topic entry (child of another topic) with defaults

    $topic_nested_default = $base_topic;
    $topic_nested_default['base_test_name'] = 'topic entry nested with defaults';
    $topic_nested_default['xml_parameters']['entrySlug'] = 'sample-topic-1a';
    $topic_nested_default['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $topic_nested_default['yaml_assertions']['title'] = $base_topic['yaml_assertions']['title'];
    $topic_nested_default['yaml_assertions']['title']['value'] = 'Sample Topic 1A';
    $topic_nested_default['yaml_assertions']['slug'] = $base_topic['yaml_assertions']['slug'];
    $topic_nested_default['yaml_assertions']['slug']['value'] = 'sample-topic-1a';

    // Add nested topic entry to test cases
    $entryVariantTypes[] = $topic_nested_default;

    // Knowledge Base entry with defaults

    $knowledge_base_default = $base_knowledge_base;
    $knowledge_base_default['base_test_name'] .= 'defaults';

    // Add knowledge base entry with defaults to test cases
    $entryVariantTypes[] = $knowledge_base_default;

    // Knowledge Base entry with non-default breadcrumbs_home_label

    $knowledge_base_breadcrumbs_home_label = $base_knowledge_base;
    $knowledge_base_breadcrumbs_home_label['base_test_name'] .= 'breadcrumbs_home_label';
    $knowledge_base_breadcrumbs_home_label['json_parameters']['breadcrumbs_home_label'] = 'BAR';
    $knowledge_base_breadcrumbs_home_label['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_breadcrumbs_home_label['yaml_assertions']['title'] = $base_knowledge_base['yaml_assertions']['title'];
    $knowledge_base_breadcrumbs_home_label['yaml_assertions']['title']['value'] = 'University of Arkansas for Medical Sciences BAR';
    $knowledge_base_breadcrumbs_home_label['yaml_assertions']['nav_alt_name'] = $base_knowledge_base['yaml_assertions']['nav_alt_name'];
    $knowledge_base_breadcrumbs_home_label['yaml_assertions']['nav_alt_name']['value'] = null;

    // Add Knowledge Base entry with non-default breadcrumbs_home_label to test cases
    $entryVariantTypes[] = $knowledge_base_breadcrumbs_home_label;

    // ---
    // Knowledge Base entry with home_sections_switch set to true and home_sections
    // containing "search"
    // ---

    $knowledge_base_home_sections_search = $base_knowledge_base;
    $knowledge_base_home_sections_search['base_test_name'] .= 'home_sections_switch with search';
    $knowledge_base_home_sections_search['json_parameters']['home_sections_switch'] = true;
    $knowledge_base_home_sections_search['json_parameters']['home_sections'] = 'search,topics';
    $knowledge_base_home_sections_search['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_home_sections_search['yaml_assertions']['knowledge_base_search_query'] = $base_knowledge_base['yaml_assertions']['knowledge_base_search_query'];
    $knowledge_base_home_sections_search['yaml_assertions']['knowledge_base_search_query']['value'] = true;

    // ---
    // Add Knowledge Base entry with home_sections_switch set to true and
    // home_sections containing "search" to test cases
    // ---
    $entryVariantTypes[] = $knowledge_base_home_sections_search;

    // ---
    // Knowledge Base entry with home_sections_switch set to true and home_sections
    // not containing "search"
    // ---

    $knowledge_base_home_sections_no_search = $base_knowledge_base;
    $knowledge_base_home_sections_no_search['base_test_name'] .= 'home_sections_switch without search';
    $knowledge_base_home_sections_no_search['json_parameters']['home_sections_switch'] = true;
    $knowledge_base_home_sections_no_search['json_parameters']['home_sections'] = 'topics';
    $knowledge_base_home_sections_no_search['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_home_sections_no_search['yaml_assertions']['knowledge_base_search_query'] = $base_knowledge_base['yaml_assertions']['knowledge_base_search_query'];
    $knowledge_base_home_sections_no_search['yaml_assertions']['knowledge_base_search_query']['value'] = false;

    // ---
    // Add Knowledge Base entry with home_sections_switch set to true and
    // home_sections not containing "search" to test cases
    // ---
    $entryVariantTypes[] = $knowledge_base_home_sections_no_search;

    // Knowledge Base entry with non-default search_title

    $knowledge_base_search_title = $base_knowledge_base;
    $knowledge_base_search_title['base_test_name'] .= 'search_title';
    $knowledge_base_search_title['json_parameters']['search_title'] = 'BAR';
    $knowledge_base_search_title['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_search_title['yaml_assertions']['knowledge_base_search_title'] = $base_knowledge_base['yaml_assertions']['knowledge_base_search_title'];
    $knowledge_base_search_title['yaml_assertions']['knowledge_base_search_title']['value'] = 'BAR';

    // Add Knowledge Base entry with non-default search_title to test cases
    $entryVariantTypes[] = $knowledge_base_search_title;

    // Knowledge Base entry with non-default search_placeholder

    $knowledge_base_search_placeholder = $base_knowledge_base;
    $knowledge_base_search_placeholder['base_test_name'] .= 'search_placeholder';
    $knowledge_base_search_placeholder['json_parameters']['search_placeholder'] = 'BAR';
    $knowledge_base_search_placeholder['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_search_placeholder['yaml_assertions']['knowledge_base_search_placeholder'] = $base_knowledge_base['yaml_assertions']['knowledge_base_search_placeholder'];
    $knowledge_base_search_placeholder['yaml_assertions']['knowledge_base_search_placeholder']['value'] = 'BAR';

    // Add Knowledge Base entry with non-default search_placeholder to test cases
    $entryVariantTypes[] = $knowledge_base_search_placeholder;

    // Knowledge Base entry with non-default cpt_topic_label_name

    $knowledge_base_cpt_topic_label_name = $base_knowledge_base;
    $knowledge_base_cpt_topic_label_name['base_test_name'] .= 'cpt_topic_label_name';
    $knowledge_base_cpt_topic_label_name['json_parameters']['cpt_topic_label_name'] = 'BAR';
    $knowledge_base_cpt_topic_label_name['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_cpt_topic_label_name['yaml_assertions']['topic_label'] = $base_knowledge_base['yaml_assertions']['topic_label'];
    $knowledge_base_cpt_topic_label_name['yaml_assertions']['topic_label']['value'] = 'BAR';

    // Add Knowledge Base entry with non-default cpt_topic_label_name to test cases
    $entryVariantTypes[] = $knowledge_base_cpt_topic_label_name;

    // Knowledge Base entry with non-default home_topics_limit

    $knowledge_base_home_topics_limit = $base_knowledge_base;
    $knowledge_base_home_topics_limit['base_test_name'] .= 'home_topics_limit';
    $knowledge_base_home_topics_limit['json_parameters']['home_topics_limit'] = '42';
    $knowledge_base_home_topics_limit['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_home_topics_limit['yaml_assertions']['topic_limit'] = $base_knowledge_base['yaml_assertions']['topic_limit'];
    $knowledge_base_home_topics_limit['yaml_assertions']['topic_limit']['value'] = 42;

    // Add Knowledge Base entry with non-default home_topics_limit to test cases
    $entryVariantTypes[] = $knowledge_base_home_topics_limit;

    // Knowledge Base entry with non-default show_all_label

    $knowledge_base_show_all_label = $base_knowledge_base;
    $knowledge_base_show_all_label['base_test_name'] .= 'show_all_label';
    $knowledge_base_show_all_label['json_parameters']['show_all_label'] = 'BAR';
    $knowledge_base_show_all_label['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_show_all_label['yaml_assertions']['show_all_topics_label'] = $base_knowledge_base['yaml_assertions']['show_all_topics_label'];
    $knowledge_base_show_all_label['yaml_assertions']['show_all_topics_label']['value'] = 'BAR';

    // Add Knowledge Base entry with non-default show_all_label to test cases
    $entryVariantTypes[] = $knowledge_base_show_all_label;

    // Knowledge Base entry with non-default home_topics_show_description

    $knowledge_base_home_topics_show_description = $base_knowledge_base;
    $knowledge_base_home_topics_show_description['base_test_name'] .= 'home_topics_show_description';
    $knowledge_base_home_topics_show_description['json_parameters']['home_topics_show_description'] = false;
    $knowledge_base_home_topics_show_description['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_home_topics_show_description['yaml_assertions']['topic_description_query'] = $base_knowledge_base['yaml_assertions']['topic_description_query'];
    $knowledge_base_home_topics_show_description['yaml_assertions']['topic_description_query']['value'] = false;

    // ---
    // Add Knowledge Base entry with non-default home_topics_show_description to test
    // cases
    // ---
    $entryVariantTypes[] = $knowledge_base_home_topics_show_description;

    // Knowledge Base entry with content restriction enabled but non-global

    $knowledge_base_restrict_on = $base_knowledge_base;
    $knowledge_base_restrict_on['base_test_name'] .= 'restrict_on not global';
    $knowledge_base_restrict_on['json_parameters']['restrict_on'] = true;
    $knowledge_base_restrict_on['json_parameters']['restrict_article_role'] = [
        'guest',
    ]; // double-check expected value format here (array or string?)
    $knowledge_base_restrict_on['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_restrict_on['yaml_assertions']['restriction_enabled'] = $base_knowledge_base['yaml_assertions']['restriction_enabled'];
    $knowledge_base_restrict_on['yaml_assertions']['restriction_enabled']['value'] = true;

    // Add Knowledge Base entry with content restriction enabled but non-global to test cases
    $entryVariantTypes[] = $knowledge_base_restrict_on;

    // Knowledge Base entry with content restriction enabled but non-global

    $knowledge_base_restrict_on_global = $base_knowledge_base;
    $knowledge_base_restrict_on_global['base_test_name'] .= 'restrict_on global';
    $knowledge_base_restrict_on_global['json_parameters']['restrict_on'] = true;
    $knowledge_base_restrict_on_global['json_parameters']['restrict_article_role'] = [
        'editor',
    ]; // double-check expected value format here (array or string?)
    $knowledge_base_restrict_on_global['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_restrict_on_global['yaml_assertions']['restriction_enabled'] = $base_knowledge_base['yaml_assertions']['restriction_enabled'];
    $knowledge_base_restrict_on_global['yaml_assertions']['restriction_enabled']['value'] = true;
    $knowledge_base_restrict_on_global['yaml_assertions']['restriction_global_query'] = $base_knowledge_base['yaml_assertions']['restriction_global_query'];
    $knowledge_base_restrict_on_global['yaml_assertions']['restriction_global_query']['value'] = true;
    $knowledge_base_restrict_on_global['yaml_assertions']['restriction_message'] = $base_knowledge_base['yaml_assertions']['restriction_message'];
    $knowledge_base_restrict_on_global['yaml_assertions']['restriction_message']['value'] = 'The content you are trying to access is for certain users only. Please log in to view it.';
    $knowledge_base_restrict_on_global['yaml_assertions']['restriction_category'] = $base_knowledge_base['yaml_assertions']['restriction_category'];
    $knowledge_base_restrict_on_global['yaml_assertions']['restriction_category']['value'] = [
        'user_roles',
    ];
    $knowledge_base_restrict_on_global['yaml_assertions']['restriction_user_roles'] = $base_knowledge_base['yaml_assertions']['restriction_user_roles'];
    $knowledge_base_restrict_on_global['yaml_assertions']['restriction_user_roles']['value'] = null;
    $knowledge_base_restrict_on_global['yaml_assertions']['import_wordpress_notes'] = $base_knowledge_base['yaml_assertions']['import_wordpress_notes'];
    $knowledge_base_restrict_on_global['yaml_assertions']['import_wordpress_notes']['value'] = [
        'restriction_user_roles: "editor"',
    ];

    // Add Knowledge Base entry with content restriction enabled but non-global to test cases
    $entryVariantTypes[] = $knowledge_base_restrict_on_global;

    // Knowledge Base entry with non-default restrict_article_message

    $knowledge_base_restrict_article_message = $base_knowledge_base;
    $knowledge_base_restrict_article_message['base_test_name'] .= 'restrict_article_message';

    // These two once targeted `$knowledge_base_restrict_on_global`, which had already
    // been appended to `$entryVariantTypes` above — and PHP copies arrays by value, so
    // both writes were dead. This variant therefore never enabled `restrict_on`, the
    // importer's restriction extraction never ran for it, and its `restriction_message`
    // expectation could not be met by any correct importer. See wordpress-importer-archive#609.
    $knowledge_base_restrict_article_message['json_parameters']['restrict_on'] = true;
    $knowledge_base_restrict_article_message['json_parameters']['restrict_article_role'] = [
        'editor',
    ];
    $knowledge_base_restrict_article_message['json_parameters']['restrict_article_message'] = 'BAR';
    $knowledge_base_restrict_article_message['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_restrict_article_message['yaml_assertions']['restriction_message'] = $base_knowledge_base['yaml_assertions']['restriction_message'];
    $knowledge_base_restrict_article_message['yaml_assertions']['restriction_message']['value'] = 'BAR';

    // Add Knowledge Base entry with non-default restrict_article_message to test cases
    $entryVariantTypes[] = $knowledge_base_restrict_article_message;

    // Knowledge Base entry with non-default add_topic_search

    $knowledge_base_add_topic_search = $base_knowledge_base;
    $knowledge_base_add_topic_search['base_test_name'] .= 'add_topic_search';
    $knowledge_base_add_topic_search['json_parameters']['add_topic_search'] = true;
    $knowledge_base_add_topic_search['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_add_topic_search['yaml_assertions']['topic_search_query'] = $base_knowledge_base['yaml_assertions']['topic_search_query'];
    $knowledge_base_add_topic_search['yaml_assertions']['topic_search_query']['value'] = true;

    // Add Knowledge Base entry with non-default add_topic_search to test cases
    $entryVariantTypes[] = $knowledge_base_add_topic_search;

    // Knowledge Base entry with non-default show_topic_description

    $knowledge_base_show_topic_description = $base_knowledge_base;
    $knowledge_base_show_topic_description['base_test_name'] .= 'show_topic_description';
    $knowledge_base_show_topic_description['json_parameters']['show_topic_description'] = false;
    $knowledge_base_show_topic_description['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_show_topic_description['yaml_assertions']['subtopic_description_query'] = $base_knowledge_base['yaml_assertions']['subtopic_description_query'];
    $knowledge_base_show_topic_description['yaml_assertions']['subtopic_description_query']['value'] = false;

    // Add Knowledge Base entry with non-default show_topic_description to test cases
    $entryVariantTypes[] = $knowledge_base_show_topic_description;

    // Knowledge Base entry with non-default topic_articles_per_page

    $knowledge_base_topic_articles_per_page = $base_knowledge_base;
    $knowledge_base_topic_articles_per_page['base_test_name'] .= 'topic_articles_per_page';
    $knowledge_base_topic_articles_per_page['json_parameters']['topic_articles_per_page'] = '42';
    $knowledge_base_topic_articles_per_page['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_topic_articles_per_page['yaml_assertions']['topic_article_count'] = $base_knowledge_base['yaml_assertions']['topic_article_count'];
    $knowledge_base_topic_articles_per_page['yaml_assertions']['topic_article_count']['value'] = 42;

    // Add Knowledge Base entry with non-default topic_articles_per_page to test cases
    $entryVariantTypes[] = $knowledge_base_topic_articles_per_page;

    // Knowledge Base entry with non-default show_search_page_excerpt

    $knowledge_base_show_search_page_excerpt = $base_knowledge_base;
    $knowledge_base_show_search_page_excerpt['base_test_name'] .= 'show_search_page_excerpt';
    $knowledge_base_show_search_page_excerpt['json_parameters']['show_search_page_excerpt'] = false;
    $knowledge_base_show_search_page_excerpt['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_show_search_page_excerpt['yaml_assertions']['topic_article_description_query'] = $base_knowledge_base['yaml_assertions']['topic_article_description_query'];
    $knowledge_base_show_search_page_excerpt['yaml_assertions']['topic_article_description_query']['value'] = false;

    // Add Knowledge Base entry with non-default show_search_page_excerpt to test cases
    $entryVariantTypes[] = $knowledge_base_show_search_page_excerpt;

    // Knowledge Base entry with non-default show_search_page_last_edit

    $knowledge_base_show_search_page_last_edit = $base_knowledge_base;
    $knowledge_base_show_search_page_last_edit['base_test_name'] .= 'show_search_page_last_edit';
    $knowledge_base_show_search_page_last_edit['json_parameters']['show_search_page_last_edit'] = true;
    $knowledge_base_show_search_page_last_edit['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_show_search_page_last_edit['yaml_assertions']['article_last_modified_query'] = $base_knowledge_base['yaml_assertions']['article_last_modified_query'];
    $knowledge_base_show_search_page_last_edit['yaml_assertions']['article_last_modified_query']['value'] = true;

    // ---
    // Add Knowledge Base entry with non-default show_search_page_last_edit to test
    // cases
    // ---
    $entryVariantTypes[] = $knowledge_base_show_search_page_last_edit;

    // Knowledge Base entry with non-default last_modified_date_text

    $knowledge_base_last_modified_date_text = $base_knowledge_base;
    $knowledge_base_last_modified_date_text['base_test_name'] .= 'last_modified_date_text';
    $knowledge_base_last_modified_date_text['json_parameters']['last_modified_date_text'] = 'BAR';
    $knowledge_base_last_modified_date_text['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_last_modified_date_text['yaml_assertions']['article_last_modified_label'] = $base_knowledge_base['yaml_assertions']['article_last_modified_label'];
    $knowledge_base_last_modified_date_text['yaml_assertions']['article_last_modified_label']['value'] = 'BAR';

    // Add Knowledge Base entry with non-default last_modified_date_text to test cases
    $entryVariantTypes[] = $knowledge_base_last_modified_date_text;

    // Knowledge Base entry with non-default show_article_author

    $knowledge_base_show_article_author = $base_knowledge_base;
    $knowledge_base_show_article_author['base_test_name'] .= 'show_article_author';
    $knowledge_base_show_article_author['json_parameters']['show_article_author'] = true;
    $knowledge_base_show_article_author['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_show_article_author['yaml_assertions']['article_creator_query'] = $base_knowledge_base['yaml_assertions']['article_creator_query'];
    $knowledge_base_show_article_author['yaml_assertions']['article_creator_query']['value'] = true;

    // Add Knowledge Base entry with non-default show_article_author to test cases
    $entryVariantTypes[] = $knowledge_base_show_article_author;

    // Knowledge Base entry with non-default show_reading_estimate

    $knowledge_base_show_reading_estimate = $base_knowledge_base;
    $knowledge_base_show_reading_estimate['base_test_name'] .= 'show_reading_estimate';
    $knowledge_base_show_reading_estimate['json_parameters']['show_reading_estimate'] = true;
    $knowledge_base_show_reading_estimate['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_show_reading_estimate['yaml_assertions']['article_estimated_reading_time_query'] = $base_knowledge_base['yaml_assertions']['article_estimated_reading_time_query'];
    $knowledge_base_show_reading_estimate['yaml_assertions']['article_estimated_reading_time_query']['value'] = true;

    // Add Knowledge Base entry with non-default show_reading_estimate to test cases
    $entryVariantTypes[] = $knowledge_base_show_reading_estimate;

    // Knowledge Base entry with non-default toc_dynamic_enable

    $knowledge_base_toc_dynamic_enable = $base_knowledge_base;
    $knowledge_base_toc_dynamic_enable['base_test_name'] .= 'toc_dynamic_enable';
    $knowledge_base_toc_dynamic_enable['json_parameters']['toc_dynamic_enable'] = true;
    $knowledge_base_toc_dynamic_enable['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_toc_dynamic_enable['yaml_assertions']['toc_query'] = $base_knowledge_base['yaml_assertions']['toc_query'];
    $knowledge_base_toc_dynamic_enable['yaml_assertions']['toc_query']['value'] = true;

    // Add Knowledge Base entry with non-default toc_dynamic_enable to test cases
    $entryVariantTypes[] = $knowledge_base_toc_dynamic_enable;

    // ---
    // Knowledge Base entry with non-default toc_dynamic_enable and
    // toc_headings_exclude
    // ---

    $knowledge_base_toc_headings_exclude = $base_knowledge_base;
    $knowledge_base_toc_headings_exclude['base_test_name'] .= 'toc_headings_exclude';
    $knowledge_base_toc_headings_exclude['json_parameters']['toc_dynamic_enable'] = true;
    $knowledge_base_toc_headings_exclude['json_parameters']['toc_headings_exclude'] = [
        'h2',
    ]; // double-check expected value format here (array or string?)
    $knowledge_base_toc_headings_exclude['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_toc_headings_exclude['yaml_assertions']['toc_query'] = $base_knowledge_base['yaml_assertions']['toc_query'];
    $knowledge_base_toc_headings_exclude['yaml_assertions']['toc_query']['value'] = true;
    $knowledge_base_toc_headings_exclude['yaml_assertions']['toc_heading_exclude'] = $base_knowledge_base['yaml_assertions']['toc_heading_exclude'];
    $knowledge_base_toc_headings_exclude['yaml_assertions']['toc_heading_exclude']['value'] = ['h2'];

    // ---
    // Add Knowledge Base entry with non-default toc_dynamic_enable and
    // toc_headings_exclude to test cases
    // ---
    $entryVariantTypes[] = $knowledge_base_toc_headings_exclude;

    // Knowledge Base entry with non-default toc_label

    $knowledge_base_toc_label = $base_knowledge_base;
    $knowledge_base_toc_label['base_test_name'] .= 'toc_label';
    $knowledge_base_toc_label['json_parameters']['toc_dynamic_enable'] = true;
    $knowledge_base_toc_label['json_parameters']['toc_label'] = 'BAR';
    $knowledge_base_toc_label['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_toc_label['yaml_assertions']['toc_query'] = $base_knowledge_base['yaml_assertions']['toc_query'];
    $knowledge_base_toc_label['yaml_assertions']['toc_query']['value'] = true;
    $knowledge_base_toc_label['yaml_assertions']['toc_label'] = $base_knowledge_base['yaml_assertions']['toc_label'];
    $knowledge_base_toc_label['yaml_assertions']['toc_label']['value'] = 'BAR';

    // Add Knowledge Base entry with non-default toc_label to test cases
    $entryVariantTypes[] = $knowledge_base_toc_label;

    // Knowledge Base entry with non-default article_attach_label

    $knowledge_base_article_attach_label = $base_knowledge_base;
    $knowledge_base_article_attach_label['base_test_name'] .= 'article_attach_label';
    $knowledge_base_article_attach_label['json_parameters']['article_attach_label'] = 'BAR';
    $knowledge_base_article_attach_label['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_article_attach_label['yaml_assertions']['attachments_label'] = $base_knowledge_base['yaml_assertions']['attachments_label'];
    $knowledge_base_article_attach_label['yaml_assertions']['attachments_label']['value'] = 'BAR';

    // Add Knowledge Base entry with non-default article_attach_label to test cases
    $entryVariantTypes[] = $knowledge_base_article_attach_label;

    // Knowledge Base entry with non-default show_related_articles

    $knowledge_base_show_related_articles = $base_knowledge_base;
    $knowledge_base_show_related_articles['base_test_name'] .= 'show_related_articles';
    $knowledge_base_show_related_articles['json_parameters']['show_related_articles'] = false;
    $knowledge_base_show_related_articles['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_show_related_articles['yaml_assertions']['related_articles_query'] = $base_knowledge_base['yaml_assertions']['related_articles_query'];
    $knowledge_base_show_related_articles['yaml_assertions']['related_articles_query']['value'] = false;

    // Add Knowledge Base entry with non-default show_related_articles to test cases
    $entryVariantTypes[] = $knowledge_base_show_related_articles;

    // Knowledge Base entry with non-default related_articles_label

    $knowledge_base_related_articles_label = $base_knowledge_base;
    $knowledge_base_related_articles_label['base_test_name'] .= 'related_articles_label';
    $knowledge_base_related_articles_label['json_parameters']['related_articles_label'] = 'BAR';
    $knowledge_base_related_articles_label['yaml_assertions'] = []; // Clear 'yaml_assertions' so we don't have to test all the defaults
    $knowledge_base_related_articles_label['yaml_assertions']['related_articles_label'] = $base_knowledge_base['yaml_assertions']['related_articles_label'];
    $knowledge_base_related_articles_label['yaml_assertions']['related_articles_label']['value'] = 'BAR';

    // Add Knowledge Base entry with non-default related_articles_label to test cases
    $entryVariantTypes[] = $knowledge_base_related_articles_label;

    // Construct test cases

    // Initialize test cases array
    $testCases = [];

    // Loop through entry variant types and construct test cases
    foreach ($entryVariantTypes as $entryVariantType) {
        // Construct base test name
        $baseTestName = $entryVariantType['base_test_name'];

        // Collect every field assertion for this variant into a single case so the
        // KB import runs once per variant instead of once per field (wordpress-importer-archive#391).
        $fieldAssertions = [];

        foreach ($entryVariantType['yaml_assertions'] as $assertion) {
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
            'entrySlug' => $entryVariantType['xml_parameters']['entrySlug'],
            'jsonParameters' => $entryVariantType['json_parameters'],
        ];
    }

    return $testCases;
}

/**
 * Assemble the selective KB WXR content for the given entry type and slug.
 *
 * The trait-bound header and footer are passed in so the assembly needs no
 * test-case instance.
 */
function kbBuildSelectiveXml(
    string $header,
    string $footer,
    string $entryVariantType,
    string $entrySlug
): string {
    // Initialize XML content with header
    $xmlContent = $header;

    // Always include KB home page for MinervaKB settings
    $xmlContent .= kbGetKbHomePageXml();

    // Determine which entries to include based on entry type
    switch ($entryVariantType) {
        // Knowledge base entry case
        case 'knowledge_base':
            // Only need KB home page, no topics or articles
            break;

            // Topic entry case
        case 'topic':
            // Add the topic and its parent topics if nested
            $xmlContent .= kbGetTopicXml($entrySlug);

            // Exit switch statement
            break;

            // Article entry case
        case 'article':
            // Add the article, its primary topic, and tags
            $xmlContent .= kbGetArticleXml($entrySlug);

            // Exit switch statement
            break;
    }

    // Append XML footer to complete the document structure
    $xmlContent .= $footer;

    return $xmlContent;
}

/**
 * Assemble the KB WXR content with only the KB home page and tag terms.
 *
 * @param  list<string>  $tagSlugs  Array of tag slugs to include in the XML
 */
function kbBuildTagsXml(
    string $header,
    string $footer,
    array $tagSlugs
): string {
    // Initialize XML content with header
    $xmlContent = $header;

    // Always include KB home page for MinervaKB settings
    $xmlContent .= kbGetKbHomePageXml();

    // Loop through each tag slug to add tag XML snippets
    foreach ($tagSlugs as $tagSlug) {
        // Add the tag XML snippet to the content
        $xmlContent .= kbGetTagXml($tagSlug);
    }

    // Append XML footer to complete the document structure
    $xmlContent .= $footer;

    return $xmlContent;
}

/**
 * Get KB home page XML snippet.
 */
function kbGetKbHomePageXml(): string
{
    return '        <item>
            <title><![CDATA[Knowledge Base Home Page]]></title>
            <link>https://example.com/kb/</link>
            <pubDate>Mon, 01 Apr 2024 12:00:00 +0000</pubDate>
            <dc:creator><![CDATA[admin]]></dc:creator>
            <guid isPermaLink="false">https://example.com/?p=123</guid>
            <description></description>
            <content:encoded><![CDATA[]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>123</wp:post_id>
            <wp:post_date><![CDATA[2024-04-01 12:00:00]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2024-04-01 17:00:00]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-04-01 12:00:00]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-04-01 17:00:00]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[kb-home]]></wp:post_name>
            <wp:status><![CDATA[publish]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[page]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
        </item>';
}

/**
 * Get topic XML snippet(s) including parent topics if nested.
 *
 * @param  string  $entrySlug  The slug of the topic entry
 */
function kbGetTopicXml(
    string $entrySlug
): string {
    // Initialize XML content string
    $xmlContent = '';

    // Get centralized topic data
    $topics = kbGetTopicData();

    // Check if the requested topic exists in the topics map
    if (! isset($topics[$entrySlug])) {
        // Return empty XML content if topic not found
        return $xmlContent;
    }

    // Get the topic data for the requested slug
    $topic = $topics[$entrySlug];

    // Check if this topic has a parent topic
    if (kbDataString($topic, 'parent') !== '') {
        // Initialize parent slug variable
        $parentSlug = null;
        $parentTermId = kbDataString($topic, 'parent');

        // Loop through all topics to find the parent by term_id
        foreach ($topics as $slug => $topicData) {
            // Check if this topic's term_id matches the parent term_id
            if (kbDataString($topicData, 'term_id') === $parentTermId) {
                // Store the parent topic slug
                $parentSlug = $slug;

                // Exit loop once parent is found
                break;
            }
        }

        // Check if parent topic was found and exists in topics map
        if (
            $parentSlug !== null
            && isset($topics[$parentSlug])
        ) {
            // Add parent topic XML before child topic
            $xmlContent .= kbGetSingleTopicXml($topics[$parentSlug]);
        }
    }

    // Add the requested topic
    $xmlContent .= kbGetSingleTopicXml($topic);

    // Return the complete XML content
    return $xmlContent;
}

/**
 * Get a single topic XML snippet.
 *
 * @param  array{term_id: string, slug: string, name: string, parent: string, description: string}  $topic  Topic data array with term_id, slug, name, parent, description
 */
function kbGetSingleTopicXml(
    array $topic
): string {
    // Initialize description XML variable
    $descriptionXml = '';
    $description = kbDataString($topic, 'description');

    // Check if topic has a description
    if ($description !== '') {
        // Build description XML element with CDATA
        $descriptionXml = "
            <wp:term_description><![CDATA[{$description}]]></wp:term_description>";
    }

    $termmetaXml = '';

    // Check if topic has no description
    if ($description === '') {
        // Add empty termmeta element for description
        $termmetaXml = '
            <wp:termmeta>
                <wp:meta_key><![CDATA[description]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:termmeta>';
    }

    $termId = kbDataString($topic, 'term_id');
    $slug = kbDataString($topic, 'slug');
    $parent = kbDataString($topic, 'parent');
    $name = kbDataString($topic, 'name');

    // Return the complete topic XML snippet
    return "        <wp:term>
            <wp:term_id>{$termId}</wp:term_id>
            <wp:term_taxonomy><![CDATA[kbtopic]]></wp:term_taxonomy>
            <wp:term_slug><![CDATA[{$slug}]]></wp:term_slug>
            <wp:term_parent><![CDATA[{$parent}]]></wp:term_parent>
            <wp:term_name><![CDATA[{$name}]]></wp:term_name>{$termmetaXml}{$descriptionXml}
        </wp:term>";
}

/**
 * Get a single tag XML snippet.
 *
 * @param  string  $tagSlug  The slug of the tag
 */
function kbGetTagXml(
    string $tagSlug
): string {
    // Get centralized tag data
    $tags = kbGetTagData();

    // Check if the requested tag exists in the tags map
    if (! isset($tags[$tagSlug])) {
        // Return empty string if tag not found
        return '';
    }

    // Get the tag data for the requested slug
    $tag = $tags[$tagSlug];
    $termId = kbDataString($tag, 'term_id');
    $slug = kbDataString($tag, 'slug');
    $name = kbDataString($tag, 'name');

    // Return the complete tag XML snippet
    return "        <wp:term>
            <wp:term_id>{$termId}</wp:term_id>
            <wp:term_taxonomy><![CDATA[kbtag]]></wp:term_taxonomy>
            <wp:term_slug><![CDATA[{$slug}]]></wp:term_slug>
            <wp:term_parent><![CDATA[]]></wp:term_parent>
            <wp:term_name><![CDATA[{$name}]]></wp:term_name>
        </wp:term>";
}

/**
 * Get article XML snippet with its dependencies (topics and tags).
 *
 * The first topic in the topics array is the primary topic, and
 * subsequent topics are secondary topics.
 *
 * @param  string  $entrySlug  The slug of the article entry
 */
function kbGetArticleXml(
    string $entrySlug
): string {
    // Initialize XML content variable
    $xmlContent = '';

    // Get centralized article data
    $articles = kbGetArticleData();

    // Check if the requested article exists in the articles map
    if (! isset($articles[$entrySlug])) {
        // Return empty XML content if article not found
        return $xmlContent;
    }

    // Get the article data for the requested slug
    $article = $articles[$entrySlug];

    // Check if article has topics
    if ($article['topics'] !== []) {
        // Loop through each topic slug used by the article (first is primary, rest are secondary)
        foreach ($article['topics'] as $topicSlug) {
            // Add the topic XML snippet to the content
            $xmlContent .= kbGetTopicXml($topicSlug);
        }
    }

    // Loop through each tag slug used by the article
    foreach ($article['tags'] as $tagSlug) {
        // Add the tag XML snippet to the content
        $xmlContent .= kbGetTagXml($tagSlug);
    }

    // Add the article itself
    $xmlContent .= kbGetSingleArticleXml($article);

    // Check if this article needs attachment items in the XML
    if ($entrySlug === 'sample-article-8') {
        // Add attachment item for article 8
        $xmlContent .= kbGetFileAttachmentXml('999');
    }

    // Check if this article needs featured image attachment in the XML
    if ($entrySlug === 'sample-article-11') {
        // Add featured image attachment item for article 11 — uncredited
        $xmlContent .= kbGetImageAttachmentXml('998');
    }

    // Article 15 carries the same image with a photo credit attached (wordpress-importer-archive#603)
    if ($entrySlug === 'sample-article-15') {
        // Add credited featured image attachment item for article 15
        $xmlContent .= kbGetImageAttachmentXml('997', 'Bruce Wayne/UAMS');
    }

    // Check if this article needs related article in the XML. Articles 12 and 13
    // reference each other, so include both when one is requested.
    if ($entrySlug === 'sample-article-12') {
        // Get article 13 data and add it to XML
        $article13 = $articles['sample-article-13'] ?? null;

        // Check if article 13 exists
        if ($article13 !== null) {
            // Check if article 13 has topics
            if ($article13['topics'] !== []) {
                // Loop through each topic
                foreach ($article13['topics'] as $topicSlug) {
                    // Add topic XML snippet to content
                    $xmlContent .= kbGetTopicXml($topicSlug);
                }
            }

            // Loop through each tag
            foreach ($article13['tags'] as $tagSlug) {
                // Add tag XML snippet to content
                $xmlContent .= kbGetTagXml($tagSlug);
            }

            // Add article 13 itself
            $xmlContent .= kbGetSingleArticleXml($article13);
        }
    }

    // Check if this is article 13 which has related articles
    if ($entrySlug === 'sample-article-13') {
        // Get article 12 data and add it to XML
        $article12 = $articles['sample-article-12'] ?? null;

        // Check if article 12 exists
        if ($article12 !== null) {
            // Check if article 12 has topics
            if ($article12['topics'] !== []) {
                // Loop through each topic
                foreach ($article12['topics'] as $topicSlug) {
                    // Add topic XML snippet to content
                    $xmlContent .= kbGetTopicXml($topicSlug);
                }
            }

            // Loop through each tag
            foreach ($article12['tags'] as $tagSlug) {
                // Add tag XML snippet to content
                $xmlContent .= kbGetTagXml($tagSlug);
            }

            // Add article 12 itself
            $xmlContent .= kbGetSingleArticleXml($article12);
        }
    }

    // Return the complete XML content
    return $xmlContent;
}

/**
 * Get a single article XML snippet.
 *
 * @param  array{post_id: string, title: string, slug: string, topics?: list<string>, tags?: list<string>}  $article  Article data array
 */
function kbGetSingleArticleXml(
    array $article
): string {
    // Initialize categories XML variable
    $categoriesXml = '';

    // Initialize tags XML variable (unused but kept for consistency)
    $tagsXml = '';

    // Get centralized data
    $articles = kbGetArticleData();
    $topics = kbGetTopicData();
    $tags = kbGetTagData();

    // Get article data for this article's slug
    $articleSlug = kbDataString($article, 'slug');
    $articleData = $articles[$articleSlug] ?? [
        'post_id' => '',
        'title' => '',
        'slug' => $articleSlug,
        'topics' => [],
        'tags' => [],
    ];

    // Check if article has topics (first is primary, rest are secondary)
    if ($articleData['topics'] !== []) {
        // Loop through each topic
        foreach ($articleData['topics'] as $topicSlug) {
            // Get topic name from centralized data
            $topicRow = $topics[$topicSlug] ?? null;
            $topicName = $topicRow !== null
                ? kbDataString($topicRow, 'name')
                : $topicSlug;
            if ($topicName === '') {
                $topicName = $topicSlug;
            }

            // Add topic category XML
            $categoriesXml .= "
            <category domain=\"kbtopic\" nicename=\"{$topicSlug}\"><![CDATA[{$topicName}]]></category>";
        }
    }

    // Check if article has tags
    if ($articleData['tags'] !== []) {
        // Loop through each tag
        foreach ($articleData['tags'] as $tagSlug) {
            // Get tag name from centralized data
            $tagRow = $tags[$tagSlug] ?? null;
            $tagName = $tagRow !== null
                ? kbDataString($tagRow, 'name')
                : $tagSlug;
            if ($tagName === '') {
                $tagName = $tagSlug;
            }

            // Add tag category XML
            $categoriesXml .= "
            <category domain=\"kbtag\" nicename=\"{$tagSlug}\"><![CDATA[{$tagName}]]></category>";
        }
    }

    // Initialize excerpt XML variable
    $excerptXml = '';

    // Initialize related articles meta value (default: empty array)
    $relatedArticlesMeta = '<wp:meta_value><![CDATA[a:0:{}]]></wp:meta_value>';

    // Initialize restrict role meta value (default: guest role)
    $restrictRoleMeta = '<wp:meta_value><![CDATA[["guest"]]]></wp:meta_value>';

    // Initialize attachments meta value (default: empty array)
    $attachmentsMeta = '<wp:meta_value><![CDATA[a:0:{}]]></wp:meta_value>';

    // Check if this is article 2 which has different metadata
    if ($article['slug'] === 'sample-article-2') {
        // Set excerpt XML for article 2
        $excerptXml = '<excerpt:encoded><![CDATA[Sample excerpt for article 2]]></excerpt:encoded>';

        // Set related articles meta for article 2 (references article 1)
        $relatedArticlesMeta = '<wp:meta_value><![CDATA[a:1:{i:0;i:1;}]]></wp:meta_value>';

        // Set restrict role meta for article 2 (subscriber and editor roles)
        $restrictRoleMeta = '<wp:meta_value><![CDATA[["subscriber","editor"]]]></wp:meta_value>';
    }

    // Check if this is article 4 which has external file attachment with label and no size
    if ($article['slug'] === 'sample-article-4') {
        // Set attachments meta for article 4 (external file attachment with label)
        $attachmentsMeta = '<wp:meta_value><![CDATA[a:1:{i:0;s:102:"EXTERNAL{{https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdfLABEL{{BAR}}}}";}]]></wp:meta_value>';
    }

    // Check if this is article 5 which has external file attachment with label and size
    if ($article['slug'] === 'sample-article-5') {
        // Set attachments meta for article 5 (external file attachment with label and size)
        $attachmentsMeta = '<wp:meta_value><![CDATA[a:1:{i:0;s:116:"EXTERNAL{{https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdfLABEL{{BAR}}SIZE{{2.5 MB}}}}";}]]></wp:meta_value>';
    }

    // Check if this is article 6 which has external file attachment with size and no label
    if ($article['slug'] === 'sample-article-6') {
        // Set attachments meta for article 6 (external file attachment with size, no label)
        $attachmentsMeta = '<wp:meta_value><![CDATA[a:1:{i:0;s:104:"EXTERNAL{{https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdfSIZE{{2.5 MB}}}}";}]]></wp:meta_value>';
    }

    // Check if this is article 7 which has external file attachment with URL only
    if ($article['slug'] === 'sample-article-7') {
        // Set attachments meta for article 7 (external file attachment with URL only)
        $attachmentsMeta = '<wp:meta_value><![CDATA[a:1:{i:0;s:90:"EXTERNAL{{https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf}}";}]]></wp:meta_value>';
    }

    // Check if this is article 8 which has asset attachment with no label override
    if ($article['slug'] === 'sample-article-8') {
        // Set attachments meta for article 8 (asset attachment with no label override)
        $attachmentsMeta = '<wp:meta_value><![CDATA[a:1:{i:0;s:3:"999";}]]></wp:meta_value>';
    }

    // Check if this is article 9 which has short excerpt
    if ($article['slug'] === 'sample-article-9') {
        // Set excerpt XML for article 9 (short excerpt)
        $excerptXml = '<excerpt:encoded><![CDATA[BAR]]></excerpt:encoded>';
    }

    // Check if this is article 10 which has long excerpt
    if ($article['slug'] === 'sample-article-10') {
        // Set excerpt XML for article 10 (long excerpt)
        $excerptXml = '<excerpt:encoded><![CDATA[Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor. Aenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus. Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.]]></excerpt:encoded>';
    }

    // Initialize thumbnail ID meta value (default: empty)
    $thumbnailIdMeta = '';

    // Check if this is article 11 which has featured image
    if ($article['slug'] === 'sample-article-11') {
        // Set thumbnail ID meta for article 11
        $thumbnailIdMeta = '
            <wp:postmeta>
                <wp:meta_key><![CDATA[_thumbnail_id]]></wp:meta_key>
                <wp:meta_value><![CDATA[998]]></wp:meta_value>
            </wp:postmeta>';
    }

    // Check if this is article 15, whose featured image carries a credit
    if ($article['slug'] === 'sample-article-15') {
        // Set thumbnail ID meta for article 15
        $thumbnailIdMeta = '
            <wp:postmeta>
                <wp:meta_key><![CDATA[_thumbnail_id]]></wp:meta_key>
                <wp:meta_value><![CDATA[997]]></wp:meta_value>
            </wp:postmeta>';
    }

    // Check if this is article 12 which has related articles
    if ($article['slug'] === 'sample-article-12') {
        // Set related articles meta for article 12 (references article 13)
        $relatedArticlesMeta = '<wp:meta_value><![CDATA[a:1:{i:0;s:2:"13";}]]></wp:meta_value>';
    }

    // Check if this is article 13 which has related articles
    if ($article['slug'] === 'sample-article-13') {
        // Set related articles meta for article 13 (references article 12)
        $relatedArticlesMeta = '<wp:meta_value><![CDATA[a:1:{i:0;s:2:"12";}]]></wp:meta_value>';
    }

    // Check if this is article 14 which has non-default restriction
    if ($article['slug'] === 'sample-article-14') {
        // Set restrict role meta for article 14 (editor role)
        $restrictRoleMeta = '<wp:meta_value><![CDATA[["editor"]]]></wp:meta_value>';
    }

    $title = kbDataString($article, 'title');
    $slug = kbDataString($article, 'slug');
    $postId = kbDataString($article, 'post_id');

    // Return the complete article XML snippet
    return "        <item>
            <title><![CDATA[{$title}]]></title>
            <link>https://example.com/kb/{$slug}/</link>
            <pubDate>Tue, 30 Apr 2024 16:32:29 +0000</pubDate>
            <dc:creator><![CDATA[admin]]></dc:creator>
            <guid isPermaLink=\"false\">https://example.com/?post_type=kb&#038;p={$postId}</guid>
            <description></description>
            <content:encoded><![CDATA[<!-- wp:paragraph -->
<p>This is sample article content for testing purposes.</p>
<!-- /wp:paragraph -->]]></content:encoded>
            {$excerptXml}
            <wp:post_id>{$postId}</wp:post_id>
            <wp:post_date><![CDATA[2024-04-30 11:32:29]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2024-04-30 16:32:29]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-04-30 15:34:34]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-04-30 20:34:34]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[open]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[{$slug}]]></wp:post_name>
            <wp:status><![CDATA[publish]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[kb]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>{$categoriesXml}
            <wp:postmeta>
                <wp:meta_key><![CDATA[_mkb_related_articles]]></wp:meta_key>
                {$relatedArticlesMeta}
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_mkb_article_attachments]]></wp:meta_key>
                {$attachmentsMeta}
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_mkb_restrict_role]]></wp:meta_key>
                {$restrictRoleMeta}
            </wp:postmeta>{$thumbnailIdMeta}
        </item>";
}

/**
 * Get file attachment XML snippet.
 *
 * @param  string  $attachmentId  The WordPress post ID of the attachment
 */
function kbGetFileAttachmentXml(
    string $attachmentId
): string {
    return "        <item>
            <title><![CDATA[REL-Fall-Career-Expo-2025.pdf]]></title>
            <link>https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf</link>
            <pubDate>Tue, 30 Apr 2024 16:32:29 +0000</pubDate>
            <dc:creator><![CDATA[admin]]></dc:creator>
            <guid isPermaLink=\"false\">https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf</guid>
            <description></description>
            <content:encoded><![CDATA[]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>{$attachmentId}</wp:post_id>
            <wp:post_date><![CDATA[2024-04-30 11:32:29]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2024-04-30 16:32:29]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-04-30 15:34:34]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-04-30 20:34:34]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[rel-fall-career-expo-2025-pdf]]></wp:post_name>
            <wp:status><![CDATA[inherit]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[attachment]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            <wp:attachment_url><![CDATA[https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf]]></wp:attachment_url>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_wp_attached_file]]></wp:meta_key>
                <wp:meta_value><![CDATA[2025/09/REL-Fall-Career-Expo-2025.pdf]]></wp:meta_value>
            </wp:postmeta>
        </item>";
}

/**
 * Get image attachment XML snippet.
 *
 * `_media_credit` is the structured postmeta key `AttachmentCreditResolver` reads
 * first, so an attachment carrying it resolves to a non-empty asset `creator` and
 * makes the KB path set `featured_image_credit_query` (wordpress-importer-archive#603). Left null, the
 * attachment carries no postmeta at all and exercises the uncredited path, in which
 * the toggle must never be written (wordpress-importer-archive#439).
 *
 * @param  string  $attachmentId  The WordPress post ID of the image attachment
 * @param  ?string  $credit  The photo credit to attach, or null for an uncredited image
 * @param  bool  $omitAttachmentUrl  Emit an empty `wp:attachment_url`, as an attachment whose URL does not resolve exports
 */
function kbGetImageAttachmentXml(
    string $attachmentId,
    ?string $credit = null,
    bool $omitAttachmentUrl = false
): string {
    // ---
    // An attachment whose URL does not resolve still exports its `wp:attachment_url`
    // — empty, because the exporter emits the element for every attachment. All
    // 131,113 attachment items across the 259 real exports carry it, so the empty
    // element is the faithful shape and an omitted one would not be.
    //
    // The cause is narrower than "the file was deleted": removing the file leaves
    // `_wp_attached_file` and the guid in place, and the URL still resolves. Reaching
    // an empty value takes both of those yielding nothing. That empty value is what
    // produces the empty-string arm of the featured-image gate, so it is an option
    // here rather than a second near-identical fixture.
    // ---
    $attachmentUrl = $omitAttachmentUrl
        ? '<wp:attachment_url><![CDATA[]]></wp:attachment_url>'
        : '<wp:attachment_url><![CDATA[https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_100.jpg]]></wp:attachment_url>';

    $creditMeta = ($credit === null) ? '' : "
            <wp:postmeta>
                <wp:meta_key><![CDATA[_media_credit]]></wp:meta_key>
                <wp:meta_value><![CDATA[{$credit}]]></wp:meta_value>
            </wp:postmeta>";

    return "        <item>
            <title><![CDATA[fpo_100.jpg]]></title>
            <link>https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_100.jpg</link>
            <pubDate>Wed, 12 Jun 2024 15:10:35 +0000</pubDate>
            <dc:creator><![CDATA[admin]]></dc:creator>
            <guid isPermaLink=\"false\">https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_100.jpg</guid>
            <description></description>
            <content:encoded><![CDATA[]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>{$attachmentId}</wp:post_id>
            <wp:post_date><![CDATA[2024-06-12 15:10:35]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2024-06-12 15:10:35]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-06-12 15:10:35]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-06-12 15:10:35]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[fpo_200]]></wp:post_name>
            <wp:status><![CDATA[publish]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[attachment]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            {$attachmentUrl}{$creditMeta}
        </item>";
}

/**
 * Run a MinervaKB import against a generated export.
 *
 * Extracted so the field-validation provider and the uploads-disabled case — both
 * defined above this helper — share one wiring; the collaborators are real rather than mocked, because what
 * these tests assert is what the real pipeline writes.
 *
 * @param  string  $xmlPath  Path to the generated WXR file
 * @param  OutputInterface  $output  Console output for the import
 * @param  ?array<mixed, mixed>  $jsonParameters  MinervaKB settings overriding the defaults, matching the provider's bare `array` shape
 */
function kbRunImport(
    string $xmlPath,
    OutputInterface $output,
    ?array $jsonParameters = null
): void {
    // Parse the generated export
    $xmlParser = new XmlParser;
    $xmlParser->parse($xmlPath);

    // Real collaborators, matching a production import
    $assetImporter = new AssetImporter;
    $metadataMapper = new MetadataMapper;
    $contentProcessor = new ContentProcessor;
    $siteManager = new SiteManager;
    $collectionStructureManager = new CollectionStructureManager;

    // Create real MinervaKB settings selector with dependencies
    $minervaKbSettingsSelector = new MinervaKbSettingsSelector(
        new Prompter,
        new Loader,
        new Validator,
        new Extractor
    );

    // Create MinervaKB settings for testing
    $minervaKbSettings = kbCreateMinervaKbSettings();

    // Check if JSON parameters are provided to override default settings
    if ($jsonParameters !== null) {
        // Merge JSON parameters with default settings to override defaults
        $minervaKbSettings = \array_merge(
            $minervaKbSettings,
            $jsonParameters // override default settings
        );
    }

    // Create KbEntryCreator instance with real dependencies
    $kbEntryCreator = new KbEntryCreator(
        new EntryCreator(
            $metadataMapper,
            $contentProcessor,
            $siteManager,
            $collectionStructureManager,
            new TreeBuilder(
                new CollectionTreeRepository(
                    app(Stache::class)
                )
            )
        ),
        $minervaKbSettingsSelector
    );

    // Process the KB entries
    $kbEntryCreator->process(
        $xmlParser,
        $assetImporter,
        $output,
        'https://example.com',
        'default',
        $siteManager,
        $collectionStructureManager,
        $minervaKbSettings
    );
}

/**
 * Get centralized article data.
 *
 * @return array<string, array{post_id: string, title: string, slug: string, topics: list<string>, tags: list<string>}> Map of article slugs to their data
 */
function kbGetArticleData(): array
{
    return [
        'sample-article-1' => [
            'post_id' => '1',
            'title' => 'Sample Article 1',
            'slug' => 'sample-article-1',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-2' => [
            'post_id' => '2',
            'title' => 'Sample Article 2',
            'slug' => 'sample-article-2',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-2', 'sample-tag-3'],
        ],
        'sample-article-3' => [
            'post_id' => '3',
            'title' => 'Sample Article 3',
            'slug' => 'sample-article-3',
            'topics' => ['sample-topic-1', 'sample-topic-2'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-4' => [
            'post_id' => '4',
            'title' => 'Sample Article 4',
            'slug' => 'sample-article-4',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-5' => [
            'post_id' => '5',
            'title' => 'Sample Article 5',
            'slug' => 'sample-article-5',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-6' => [
            'post_id' => '6',
            'title' => 'Sample Article 6',
            'slug' => 'sample-article-6',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-7' => [
            'post_id' => '7',
            'title' => 'Sample Article 7',
            'slug' => 'sample-article-7',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-8' => [
            'post_id' => '8',
            'title' => 'Sample Article 8',
            'slug' => 'sample-article-8',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-9' => [
            'post_id' => '9',
            'title' => 'Sample Article 9',
            'slug' => 'sample-article-9',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-10' => [
            'post_id' => '10',
            'title' => 'Sample Article 10',
            'slug' => 'sample-article-10',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-11' => [
            'post_id' => '11',
            'title' => 'Sample Article 11',
            'slug' => 'sample-article-11',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-12' => [
            'post_id' => '12',
            'title' => 'Sample Article 12',
            'slug' => 'sample-article-12',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-13' => [
            'post_id' => '13',
            'title' => 'Sample Article 13',
            'slug' => 'sample-article-13',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-14' => [
            'post_id' => '14',
            'title' => 'Sample Article 14',
            'slug' => 'sample-article-14',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
        'sample-article-15' => [
            'post_id' => '15',
            'title' => 'Sample Article 15',
            'slug' => 'sample-article-15',
            'topics' => ['sample-topic-1'],
            'tags' => ['sample-tag-1'],
        ],
    ];
}

/**
 * Get centralized topic data.
 *
 * @return array<string, array{term_id: string, slug: string, name: string, parent: string, description: string}> Map of topic slugs to their data
 */
function kbGetTopicData(): array
{
    return [
        // Topic with defaults for primary topic
        'sample-topic-1' => [
            'term_id' => '1',
            'slug' => 'sample-topic-1',
            'name' => 'Sample Topic 1',
            'parent' => '',
            'description' => '',
        ],
        // Topic with defaults for secondary topic
        'sample-topic-2' => [
            'term_id' => '2',
            'slug' => 'sample-topic-2',
            'name' => 'Sample Topic 2',
            'parent' => '',
            'description' => '',
        ],
        // Topic with description
        'sample-topic-3' => [
            'term_id' => '3',
            'slug' => 'sample-topic-3',
            'name' => 'Sample Topic 3',
            'parent' => '',
            'description' => 'This is a sample topic description for testing purposes.',
        ],
        // Nested topic (child of sample-topic-1)
        'sample-topic-1a' => [
            'term_id' => '5',
            'slug' => 'sample-topic-1a',
            'name' => 'Sample Topic 1A',
            'parent' => '1',
            'description' => '',
        ],
    ];
}

/**
 * Get centralized tag data.
 *
 * @return array<string, array{term_id: string, slug: string, name: string}> Map of tag slugs to their data
 */
function kbGetTagData(): array
{
    return [
        'sample-tag-1' => [
            'term_id' => '101',
            'slug' => 'sample-tag-1',
            'name' => 'Sample Tag 1',
        ],
        'sample-tag-2' => [
            'term_id' => '102',
            'slug' => 'sample-tag-2',
            'name' => 'Sample Tag 2',
        ],
        'sample-tag-3' => [
            'term_id' => '103',
            'slug' => 'sample-tag-3',
            'name' => 'Sample Tag 3',
        ],
    ];
}

/**
 * Create MinervaKB settings JSON structure for testing.
 *
 * @return array<string, mixed> The MinervaKB settings array
 */
function kbCreateMinervaKbSettings(): array
{
    return [
        'breadcrumbs_home_label' => 'Knowledge Base',
        'home_sections_switch' => true,
        'home_sections' => 'search,topics',
        'search_title' => 'Search the Knowledge Base',
        'search_placeholder' => 'e.g., Guidelines',
        'cpt_topic_label_name' => 'Topics',
        'home_topics_limit' => '-1',
        'show_all_label' => 'Show All',
        'home_topics_show_description' => true,
        'restrict_on' => false,
        'restrict_article_role' => '["guest"]',
        'restrict_article_message' => null,
        'add_topic_search' => false,
        'show_topic_description' => true,
        'topic_articles_per_page' => '-1',
        'show_search_page_excerpt' => true,
        'show_search_page_last_edit' => true,
        'last_modified_date_text' => 'Last modified:',
        'show_article_author' => false,
        'show_reading_estimate' => false,
        'toc_dynamic_enable' => false,
        'toc_headings_exclude' => '',
        'toc_label' => null,
        'article_attach_label' => 'Attachments',
        'show_related_articles' => true,
        'related_articles_label' => 'Related Articles',
    ];
}

/**
 * Validate the parent field for KB entries.
 *
 * Verifies that entries have the correct parent relationships:
 *   - Knowledge base entry: no parent (root entry)
 *   - Topic entries: parent is the knowledge base entry (or another topic)
 *   - Article entries: parent is a topic entry
 *
 * @param  Entry  $entry  The entry to validate
 * @param  string  $entryVariantType  The entry variant type (knowledge_base, topic, article)
 * @param  string  $entrySlug  The entry slug for finding related entries
 */
function kbValidateParentField(
    Entry $entry,
    string $entryVariantType,
    string $entrySlug
): void {
    // Get entry data as array for accessing fields
    $entryData = kbAsArray($entry->data());

    // Extract parent ID from entry data, defaulting to null if not set
    $parentId = $entryData['parent'] ?? null;

    // Switch based on entry variant type to validate parent relationships
    switch ($entryVariantType) {
        // Knowledge base entry case
        case 'knowledge_base':
            // Knowledge base entry should have no parent (it's the root)
            expect($parentId)->toBeNull();
            break;

            // Topic entry case
        case 'topic':
            // Topic entries should have a parent (either knowledge base or another topic)
            expect($parentId)->not->toBeNull();

            // Verify the parent entry exists
            $parentIdString = kbStringValue($parentId);
            if ($parentIdString === null) {
                throw new RuntimeException("Topic entry '{$entrySlug}' should have a parent ID");
            }

            $parentEntry = kbFindKnowledgeBaseEntryById($parentIdString);

            // The parent entry was found
            expect($parentEntry)->not->toBeNull();
            if (! $parentEntry instanceof Entry) {
                throw new RuntimeException("Parent entry with ID '{$parentIdString}' should exist for topic '{$entrySlug}'");
            }

            // Get the parent entry's blueprint handle to determine entry type
            $parentBlueprint = kbEntryBlueprintHandle($parentEntry);

            // Check if parent is a knowledge base entry (top-level topic)
            if ($parentBlueprint === 'knowledge_base') {
                // Top-level topic: parent should be the knowledge base root entry.
                // Since wordpress-importer-archive#331 the root adopts the MinervaKB home page's slug
                // (here 'kb-home', from the kb_page) rather than the literal 'kb'.
                expect($parentEntry->slug())->toEqual('kb-home');
            } elseif ($parentBlueprint === 'topic') { // Check if parent is another topic entry (nested topic)
                // Nested topic: parent should be another topic
                expect(kbEntryBlueprintHandle($parentEntry))->toEqual('topic');

                // Check if this is the nested topic sample-topic-1a
                if ($entrySlug === 'sample-topic-1a') {
                    // Nested topic should be under sample-topic-1
                    expect($parentEntry->slug())->toEqual('sample-topic-1');
                }
            } else { // Otherwise
                $parentBlueprintString = kbStringValue($parentBlueprint) ?? '';

                // Fail test if parent has unexpected blueprint type
                throw new RuntimeException("Topic '{$entrySlug}' has unexpected parent blueprint '{$parentBlueprintString}'");
            }

            // Clean up variables to free up memory
            unset(
                $parentEntry
            );

            // Exit switch statement
            break;

            // Article entry case
        case 'article':
            // Article entries should have a topic entry as parent
            expect($parentId)->not->toBeNull();

            // Verify the parent is actually a topic entry
            $parentIdString = kbStringValue($parentId);
            if ($parentIdString === null) {
                throw new RuntimeException("Article entry '{$entrySlug}' should have a parent ID");
            }

            $parentEntry = kbFindKnowledgeBaseEntryById($parentIdString);

            // The parent entry was found
            expect($parentEntry)->not->toBeNull();
            if (! $parentEntry instanceof Entry) {
                throw new RuntimeException("Parent entry with ID '{$parentIdString}' should exist for article '{$entrySlug}'");
            }

            // Parent entry is a topic entry
            expect(kbEntryBlueprintHandle($parentEntry))->toEqual('topic');

            // Check if this is article 1 or article 2 or article 3
            if (
                $entrySlug === 'sample-article-1'
                || $entrySlug === 'sample-article-2'
                || $entrySlug === 'sample-article-3'
            ) {
                // Article should be under sample-topic-1 (primary topic)
                expect($parentEntry->slug())->toEqual('sample-topic-1');
            }

            // Clean up variables to free up memory
            unset(
                $parentEntry
            );

            // Exit switch statement
            break;
    }

    // Clean up variables to free up memory
    unset(
        $entryData,
        $parentId
    );
}

/**
 * The slugs the KB import may assign to the knowledge base root entry:
 * 'kb-home' when the MinervaKB kb_page resolves, 'kb' as the fallback when it
 * does not. Both are cleaned before/after each row so no stale root survives to
 * short-circuit the next import or force a duplicate '… (2)' title. See wordpress-importer-archive#464.
 *
 * @return list<string>
 */
function kbRootSlugs(): array
{
    return ['kb', 'kb-home'];
}

/**
 * Find a knowledge base entry by site and slug.
 *
 * @param  string  $slug  The entry slug
 * @return ?Entry The matching entry, or null when not found
 */
function kbFindKnowledgeBaseEntryBySlug(
    string $slug
): ?Entry {
    $collection = kbKnowledgeBasesCollection();
    if ($collection === null) {
        return null;
    }

    $query = $collection->queryEntries();
    if (! $query instanceof EntryQueryBuilder) {
        return null;
    }

    $siteQuery = $query->where(
        'site',
        'default'
    );
    if (! $siteQuery instanceof EntryQueryBuilder) {
        return null;
    }

    $slugQuery = $siteQuery->where(
        'slug',
        $slug
    );
    if (! $slugQuery instanceof EntryQueryBuilder) {
        return null;
    }

    $entry = $slugQuery->first();

    return $entry instanceof Entry ? $entry : null;
}

/**
 * Find a knowledge base entry by site and ID.
 *
 * @param  string  $id  The entry ID
 * @return ?Entry The matching entry, or null when not found
 */
function kbFindKnowledgeBaseEntryById(
    string $id
): ?Entry {
    $collection = kbKnowledgeBasesCollection();
    if ($collection === null) {
        return null;
    }

    $query = $collection->queryEntries();
    if (! $query instanceof EntryQueryBuilder) {
        return null;
    }

    $siteQuery = $query->where(
        'site',
        'default'
    );
    if (! $siteQuery instanceof EntryQueryBuilder) {
        return null;
    }

    $idQuery = $siteQuery->where(
        'id',
        $id
    );
    if (! $idQuery instanceof EntryQueryBuilder) {
        return null;
    }

    $entry = $idQuery->first();

    return $entry instanceof Entry ? $entry : null;
}

/**
 * Resolve the knowledge_bases collection when it exists.
 *
 * @return ?Collection The knowledge bases collection, or null when missing
 */
function kbKnowledgeBasesCollection(): ?Collection
{
    $collection = CollectionFacade::find('knowledge_bases');

    return $collection instanceof Collection ? $collection : null;
}

/**
 * Resolve an entry blueprint handle for assertions.
 *
 * @param  Entry  $entry  The entry to inspect
 * @return string The blueprint handle, or an empty string when unavailable
 */
function kbEntryBlueprintHandle(
    Entry $entry
): string {
    $blueprint = $entry->blueprint();
    if (! $blueprint instanceof Blueprint) {
        return '';
    }

    return $blueprint->handle() ?? '';
}

/**
 * Read a string field from a KB test data row.
 *
 * @param  array<string, mixed>  $row  The test data row
 * @param  string  $key  The field key
 */
function kbDataString(
    array $row,
    string $key
): string {
    $value = $row[$key] ?? null;

    return \is_string($value) ? $value : '';
}

/**
 * Normalize a mixed value to array shape for safe iteration and offsets.
 *
 * @param  mixed  $value  The candidate array value
 * @return array<int|string, mixed> The normalized array value
 */
function kbAsArray(
    mixed $value
): array {
    if (\is_array($value)) {
        return $value;
    }

    // Statamic returns data/values as a Collection or Values object (Arrayable),
    // not a plain array; convert those so callers see the actual fields rather
    // than an empty array. See wordpress-importer-archive#464.
    if ($value instanceof Arrayable) {
        return $value->toArray();
    }

    return [];
}

/**
 * Normalize a mixed value to a string for safe string-only operations.
 *
 * @param  mixed  $value  The candidate string value
 * @return ?string The normalized string value
 */
function kbStringValue(
    mixed $value
): ?string {
    if (! \is_string($value)) {
        return null;
    }

    return $value;
}

// ---
// wordpress-importer-archive#432 — imported knowledge-base content must be discoverable through the
// app's `knowledge_bases` search index and its subtree scope.
//
// **The 2026-07-31 attempt's blocker did not reproduce.** That attempt could not
// get the imported article back out of the index and recorded results that
// varied run to run — 2 hits then 0 with no code change — so it was deferred
// rather than guessed at. Re-run against current `main`, the index is stable
// (2 hits on three consecutive searches in one run) and the imported entry is
// present. Whatever caused the instability was fixed app-side; the closest
// match is `uams-statamic#2039`, an isolated test's `Cache::flush()` starving
// the shared Stache index.
//
// **The "not found" half was a probe defect, and it is worth naming because it
// is silent.** `Search::index(...)->searchables()->all()` yields a Collection of
// **reference strings** shaped `entry::<id>`, not `Entry` objects — so comparing
// against a bare `$entry->id()` reports "absent" for an entry that is present.
// Search results are `Statamic\Search\Result` objects, which have no `id()` and
// throw on string cast; the accessor is `getReference()`, in the same
// `entry::<id>` form. Both comparisons below use that form deliberately.
// ---

/**
 * The reference form the search index uses for an entry.
 *
 * @param  Entry  $entry  The entry to reference
 * @return string The `entry::<id>` reference
 */
function kbSearchReference(
    Entry $entry
): string {
    $id = $entry->id();

    \assert(\is_string($id));

    return 'entry::'.$id;
}

/**
 * The `knowledge_bases` search index, narrowed for the analyser.
 *
 * The `Search` facade is untyped, so every call through it reads as `mixed` at
 * level max. Narrowing once here keeps the assertions readable.
 *
 * @return SearchIndex The configured index
 */
function kbSearchIndex(): SearchIndex
{
    $index = Search::index('knowledge_bases');

    \assert($index instanceof SearchIndex);

    return $index;
}

/**
 * Every reference returned by a search against the `knowledge_bases` index.
 *
 * @param  string  $term  The search term
 * @return list<string> The `entry::<id>` references of the hits
 */
function kbSearchReferences(
    string $term
): array {
    $references = [];

    $query = kbSearchIndex()->search($term);

    \assert($query instanceof SearchQueryBuilder);

    /** @var iterable<mixed> $results */
    $results = $query->get();

    foreach ($results as $result) {
        if (! $result instanceof SearchResult) {
            continue;
        }

        // `Result` has no `id()` and cannot be cast to string; `getReference()`
        // returns the `entry::<id>` form the index stores
        $references[] = $result->getReference();
    }

    return $references;
}

it('makes an imported knowledge-base article discoverable through the search index', function (): void {
    $xmlPath = $this->createTempFile('kb_search_');
    file_put_contents(
        $xmlPath,
        kbBuildSelectiveXml($this->getXmlHeader(), $this->getXmlFooter(), 'article', 'sample-article-9')
    );

    try {
        // ---
        // Mirror the production run rather than a bare import. `ImportWordPress`
        // wraps the whole import in `SearchIndexUpdates::withDisabled()` so the
        // per-save index listener does not thrash once per entry, then rebuilds
        // every index once at the end. Importing *without* that wrapper leaves
        // live indexing on, which indexes the entry on save and makes the
        // `reindexAll()` below do nothing — the test would then pass with the
        // importer's own reindex deleted, which is exactly what it exists to
        // pin. Verified: with a bare import, neutralising `reindexAll()` left
        // this green.
        // ---
        SearchIndexUpdates::withDisabled(
            function () use ($xmlPath): void {
                kbRunImport($xmlPath, $this->createMock(OutputInterface::class), null);
            }
        );

        $article = kbFindKnowledgeBaseEntryBySlug('sample-article-9');

        expect($article)->not->toBeNull();
        \assert($article instanceof Entry);

        // The import wrote the fields the index is configured over
        expect($article->get('title'))->toBe('Sample Article 9');
        expect($article->get('excerpt'))->not->toBeEmpty();

        $reference = kbSearchReference($article);

        // ---
        // Nothing has reindexed yet, so the entry must NOT be searchable. This is
        // what makes the rebuild below load-bearing instead of incidental.
        // ---
        expect(kbSearchReferences('Sample Article 9'))->not->toContain($reference);

        // The importer rebuilds every configured index once, after the import
        SearchIndexUpdates::reindexAll();

        // ---
        // Membership first: an entry absent from `searchables()` cannot be found
        // by any term, so asserting this separately distinguishes "not indexed"
        // from "indexed but this term does not match it".
        // ---
        $searchables = [];

        $searchableSet = kbSearchIndex()->searchables();

        \assert($searchableSet instanceof Searchables);

        /** @var iterable<mixed> $indexed */
        $indexed = $searchableSet->all();

        foreach ($indexed as $searchable) {
            if (\is_string($searchable)) {
                $searchables[] = $searchable;
            }
        }

        expect($searchables)->toContain($reference);

        // Found by title, and by excerpt — the excerpt is a configured index field
        expect(kbSearchReferences('Sample Article 9'))->toContain($reference);
        $excerpt = $article->get('excerpt');

        \assert(\is_string($excerpt));

        expect(kbSearchReferences($excerpt))->toContain($reference);

        // ---
        // The positive control's counterpart. Without this, a `search()` that
        // returned every entry regardless of term would satisfy every assertion
        // above.
        // ---
        expect(kbSearchReferences('zzzznotpresentinanyentry'))->toBe([]);
    } finally {
        if (\file_exists($xmlPath)) {
            unlink($xmlPath);
        }
    }
});
