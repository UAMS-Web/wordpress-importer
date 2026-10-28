<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Entries\Collection as PostsCollection;
use Statamic\Entries\Entry;
use Statamic\Facades\Asset;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Stache;
use Statamic\Facades\Term;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;
use UAMSWeb\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

/**
 * Photo credit written onto the featured-image attachment of the YAML-validation variants.
 *
 * `_media_credit` is what `AttachmentCreditResolver` reads first, so an attachment carrying
 * it resolves to a non-empty asset `creator` and makes `PostEntryCreator` set the
 * `featured_image_credit_query` toggle. Only the YAML-validation variants are credited —
 * every other caller of {@see postCreateTestXmlContent()} leaves the parameter null and so
 * exercises the uncredited path, in which the toggle must never be written (wordpress-importer-archive#543).
 */
const POST_FEATURED_IMAGE_CREDIT = 'Bruce Wayne/UAMS';

beforeEach(function (): void {
    // Guard the live `content/` submodule against anything this test leaves behind (wordpress-importer-archive#570)
    $this->captureSubmoduleStates();

    // Snapshot the content/assets this test mutates so afterEach can restore them
    // verbatim. The slug-list cleanup misses audio assets that Statamic re-imports
    // with a uniqueness suffix on collision, so without this each method's
    // re-import would accumulate suffixed files and fail filename assertions.
    $this->snapshotIsolatedState([
        'content/collections/posts',
        // Categories import as entries in this collection, not as taxonomy terms
        // (wordpress-importer-archive#422); its tree lands under the `content/trees/collections` entry below.
        'content/collections/post_categories',
        'content/trees/collections/default',
        // Tags import as entries in this collection too (wordpress-importer-archive#1220)
        'content/collections/post_tags',
        'public/assets/audio',
        'public/assets/images',
        'public/assets/news_releases',
    ]);
});

afterEach(function (): void {
    // Clean up any created test files, regardless of whether the test passed or
    // failed. Cleanup errors are logged but never fail the test so they cannot
    // mask the actual test failure.
    try {
        // Define entry slugs to clean up via Statamic's proper deletion method
        $entrySlugs = [
            'sample-entry',
            'standard-post-entry-v01',
            'standard-post-entry-v02',
            'standard-post-entry-v03',
            'standard-post-entry-v04',
            'standard-post-entry-v05',
            'standard-post-entry-v06',
            'standard-post-entry-v07',
            'standard-post-entry-v08',
            'standard-post-entry-v09',
            'news-release-post-entry-v01',
            'news-release-post-entry-v02',
            'audio-post-entry-v01',
            'audio-post-entry-v02',
            'audio-post-entry-v03',
            'audio-post-entry-v04',
            'audio-post-entry-v05',
            'audio-post-entry-v06',
            'audio-post-entry-v07',
            'audio-post-entry-v08',
            'audio-post-entry-v09',
            'audio-post-entry-v10',
            'audio-post-entry-v11',
            'audio-post-entry-v12a',
            'audio-post-entry-v12b',
            'audio-post-entry-v12c',
            'audio-post-entry-v12d',
            'audio-post-entry-v12e',
            'audio-post-entry-v12f',
            'audio-post-entry-v12g',
            'audio-post-entry-v12h',
            'audio-post-entry-v12i',
            'audio-post-entry-v13',
            'video-post-entry-v01',
            'video-post-entry-v02',
            'gallery-post-entry-v01',
            'gallery-post-entry-v02',
        ];

        // Define asset files specific to post tests
        $assetFiles = [
            'audio' => [
                '2025-09-htyh-09-08-25-teen-health-once-a-year.mp3',
                '2025-09-htyh-09-09-25-teen-health-vaccinations.mp3',
                'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_foo-bar_123.mp3',
                'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_baz-qux_123.mp3',
                'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_123.mp3',
            ],
            'images' => [
                '2020-05-fpo_100.jpg',
                '2020-05-fpo_200.jpg',
                '2020-05-fpo_400.jpg',
            ],
            'news_releases' => [
                '2025-09-rel-fall-career-expo-2025.pdf',
            ],
        ];

        // ---
        // No taxonomy terms are specific to post tests any more. Categories and
        // tags both import as collection entries (wordpress-importer-archive#422, wordpress-importer-archive#1220), which the
        // `snapshotIsolatedState()` restore above returns to their prior state.
        // ---
        $taxonomyTerms = [];

        // Use the trait's static helper method to do the actual cleanup
        $this::cleanupTestFilesWithPaths(
            $entrySlugs,
            'posts', // collection handle
            'default', // site handle
            $assetFiles,
            $taxonomyTerms,
            false // Don't restore tree files for post tests
        );
    } catch (Exception $e) {
        error_log('PostImportTest cleanup error: '.$e->getMessage());
    }

    // Restore the snapshotted content/assets so re-imported entries and suffixed
    // audio assets don't leak into the next test.
    $this->restoreIsolatedState();

    // Last, so it judges what the restore above did NOT put back (wordpress-importer-archive#570).
    $this->assertSubmodulesUnchanged();
});

/**
 * Unit tests for PostEntryCreator.
 *
 * Tests the WordPress post import functionality, including:
 *   - Post entry creation and mapping
 *   - Featured image import
 *   - Category and tag processing
 *   - Content processing and Bard conversion
 *   - SEO metadata handling
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/PostImportTest.php
 */
it('creates a post entry with the expected slug and original WordPress URL', function (): void {
    // Create XML parameters for standard post entry
    $xmlParameters = [
        'postType' => 'post',
        'entryVariantType' => 'standard_v01',
    ];

    // Create a callback function that generates the comprehensive post XML
    $xmlGeneratorCallback = function (array $params): string {
        $xmlPath = $this->createTempFile('test_post_comprehensive_');
        file_put_contents(
            $xmlPath,
            postCreateTestXmlContent(
                $this->getXmlHeader(),
                $this->getXmlFooter(),
                $this->getUniqueSlugForEntryVariant('post', 'standard_v01'),
                'standard_v01'
            )
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

    // The entry data should be an array
    $entryData = $entry->data();
    expect($entryData)->toBeArray();
    if (! is_array($entryData)) {
        throw new RuntimeException('Expected the entry data to be an array.');
    }

    // We actually created an entry with content (not just empty data)
    expect($entryData)->not->toBeEmpty();

    // The entry should have a title
    $title = $entryData['title'] ?? null;
    expect($title)->toBeString();

    // The entry has the expected slug for a standard post
    $expectedSlug = $this->getUniqueSlugForEntryVariant(
        'post',
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

it('handles site-specific taxonomy and category mapping', function (): void {
    // Clear taxonomy caches so importTaxonomyTermsAtBeginning runs and terms are
    // created/updated with correct title (avoids stale state from prior test)
    BaseEntryCreator::clearTaxonomyCaches();

    // Create test XML file with realistic post content (using default standard post)
    $xmlPath = $this->createTempFile('test_post_comprehensive_');
    file_put_contents(
        $xmlPath,
        postCreateTestXmlContent(
            $this->getXmlHeader(),
            $this->getXmlFooter(),
            $this->getUniqueSlugForEntryVariant('post', 'standard_v01'),
            'standard_v01'
        )
    );

    // Setup real instances for testing production methods
    $xmlParser = new XmlParser;
    $xmlParser->parse($xmlPath);
    $assetImporter = new AssetImporter;
    $output = $this->createMock(OutputInterface::class);
    $metadataMapper = new MetadataMapper;
    $contentProcessor = new ContentProcessor;

    $postEntryCreator = new PostEntryCreator;

    // Call the production createEntries method to get real output
    $result = $postEntryCreator->createEntries(
        'default',
        'posts',
        $xmlParser,
        $assetImporter,
        $output,
        $metadataMapper,
        $contentProcessor
    );

    // Should create at least one entry (pageData from createEntries)
    expect(\count($result))->toBeGreaterThan(0);

    // ---
    // The `news` category imports as an ENTRY in the `post_categories`
    // collection, not as a taxonomy term. `UAMS-Web/uams-statamic-archive#1433` migrated
    // that destination and repointed the `posts` field to an `entries` picker, so
    // the term pass records where each category landed and the post references
    // that entry id (wordpress-importer-archive#422).
    // ---
    // `postGetCategoryTermsXml()` declares the `news` category as term id 1
    $categoryEntryId = TermDestinationRegistry::referenceFor('category', '1');

    expect($categoryEntryId)->toBeString()
        ->and($categoryEntryId)->not->toBeEmpty();

    // The destination is the collection, not the retired taxonomy
    expect(TermDestinationRegistry::resolve('category', '1'))
        ->toMatchArray([
            'shape' => TaxonomyDispositions::SHAPE_COLLECTION,
            'handle' => 'post_categories',
        ]);

    // The category entry itself exists in the collection and carries its name
    Stache::clear();
    $categoryEntry = EntryFacade::find($categoryEntryId);
    expect($categoryEntry)->toBeInstanceOf(Entry::class);
    if (! $categoryEntry instanceof Entry) {
        throw new RuntimeException('Expected the category to import as a collection entry.');
    }

    expect($categoryEntry->collectionHandle())->toBe('post_categories')
        ->and($categoryEntry->get('title'))->toBe('News')
        // The slug is NOT site-prefixed: collection entries are already scoped by
        // site on disk, and a prefix would leak the site handle into the URL.
        ->and($categoryEntry->slug())->toBe('news');

    // ---
    // The imported post references that entry id under the PLURAL handle. Both
    // assertions matter: the value has to resolve, and the handle has to be one
    // the blueprint defines — the importer previously wrote a site-prefixed slug
    // under `post_category`, which failed on both counts.
    // ---
    $importedSlug = $this->getUniqueSlugForEntryVariant('post', 'standard_v01');

    $postsCollection = postImportPostsCollection();
    $importedPost = $postsCollection === null
        ? null
        : $this::findEntryBySlug(
            $postsCollection,
            'default',
            $importedSlug
        );

    // The finder resolves as an untyped value under static analysis, so the guard
    // both asserts existence and narrows it for the assertions below
    if (! $importedPost instanceof Entry) {
        throw new RuntimeException('Expected the imported post to exist.');
    }

    expect($importedPost->get('post_categories'))->toBe([$categoryEntryId])
        // The retired singular keys must not survive anywhere on the entry
        ->and($importedPost->get('post_category'))->toBeNull()
        ->and($importedPost->get('post_tag'))->toBeNull();

    // ---
    // Tags followed categories: `UAMS-Web/uams-statamic-archive#2532` made `post_tags`
    // a site-scoped collection, so the tag imports as an entry on this site with
    // its plain slug and name, and the post references that entry id (wordpress-importer-archive#1220).
    // `postGetCategoryTermsXml()` declares the `lorem-ipsum` tag as term id 5.
    // ---
    $tagEntryId = TermDestinationRegistry::referenceFor('post_tag', '5');

    expect($tagEntryId)->toBeString()
        ->and($tagEntryId)->not->toBeEmpty()
        ->and($importedPost->get('post_tags'))->toBe([$tagEntryId]);

    Stache::clear();
    $tagEntry = EntryFacade::find((string) $tagEntryId);

    if (! $tagEntry instanceof Entry) {
        throw new RuntimeException('Expected the tag to import as a collection entry.');
    }

    expect($tagEntry->collectionHandle())->toBe('post_tags')
        ->and($tagEntry->locale())->toBe('default')
        ->and($tagEntry->slug())->toBe('lorem-ipsum')
        ->and($tagEntry->get('title'))->toBe('Lorem Ipsum');

    // No taxonomy term is created for the tag any more
    Stache::clear();
    expect(Term::find('post_tags::default_lorem-ipsum'))->toBeNull();

    // No taxonomy term is created for the category any more
    Stache::clear();
    expect(Term::find('post_categories::default_news'))->toBeNull();

    // Clean up variables to free up memory
    unset(
        $xmlPath,
        $xmlParser,
        $assetImporter,
        $output,
        $metadataMapper,
        $contentProcessor,
        $postEntryCreator,
        $result,
        $categoryEntryId,
        $categoryEntry,
        $importedPost
    );
});

it('validates the mapped YAML fields for each post variant', function (
    array $fieldAssertions,
    string $postType,
    string $entryVariantType,
    ?string $contentEncoded,
    ?string $postFormatNicename,
    ?string $postFormatValue,
    ?string $includeBoilerplate,
    ?string $newsReleasePdfUrl
): void {
    // Create XML parameters array
    $xmlParameters = [
        'postType' => $postType,
        'entryVariantType' => $entryVariantType,
        'contentEncoded' => $contentEncoded,
        'postFormatNicename' => $postFormatNicename,
        'postFormatValue' => $postFormatValue,
        'includeBoilerplate' => $includeBoilerplate,
        'newsReleasePdfUrl' => $newsReleasePdfUrl,
    ];

    // Create a callback function that generates the comprehensive post XML
    $xmlGeneratorCallback = function (array $params) use (
        $postType,
        $entryVariantType,
        $contentEncoded,
        $postFormatNicename,
        $postFormatValue,
        $includeBoilerplate,
        $newsReleasePdfUrl
    ): string {
        $xmlPath = $this->createTempFile('test_post_comprehensive_');
        file_put_contents(
            $xmlPath,
            postCreateTestXmlContent(
                $this->getXmlHeader(),
                $this->getXmlFooter(),
                $this->getUniqueSlugForEntryVariant($postType, $entryVariantType),
                $entryVariantType,
                $contentEncoded,
                $postFormatNicename,
                $postFormatValue,
                $includeBoilerplate,
                $newsReleasePdfUrl,
                POST_FEATURED_IMAGE_CREDIT
            )
        );

        return $xmlPath;
    };

    // Assert every field for this variant from a single import (one app boot)
    $this->testYamlFieldsForVariant(
        $fieldAssertions,
        $xmlParameters,
        $xmlGeneratorCallback
    );
})->with(postYamlFieldValidationProvider());

it('leaves featured_image_credit_query unset when the featured image carries no credit', function (): void {
    // ---
    // The trait caches parsed entry data in a static array keyed by md5 of these
    // parameters, and never clears it. The credited variants already differ by the five
    // XML parameters the YAML-validation provider carries, so the collision to separate
    // is with the slug test above, whose two parameters would otherwise be identical to
    // these. This third key is never read by the generator below — it exists only to give
    // this import its own cache entry rather than silently reusing that one's.
    // ---
    $xmlParameters = [
        'postType' => 'post',
        'entryVariantType' => 'standard_v01',
        'featuredImageCredit' => null,
    ];

    // Build the same standard post, leaving its featured-image attachment uncredited
    $xmlGeneratorCallback = function (array $params): string {
        $xmlPath = $this->createTempFile('test_post_uncredited_');
        file_put_contents(
            $xmlPath,
            postCreateTestXmlContent(
                $this->getXmlHeader(),
                $this->getXmlFooter(),
                $this->getUniqueSlugForEntryVariant('post', 'standard_v01'),
                'standard_v01'
            )
        );

        return $xmlPath;
    };

    // Import the uncredited post through the production entry creator
    $entry = $this->generateAndImportFromXmlCallback(
        $xmlParameters,
        $xmlGeneratorCallback
    );

    $entryData = $entry->data();
    expect($entryData)->toBeArray();
    if (! is_array($entryData)) {
        throw new RuntimeException('Expected the entry data to be an array.');
    }

    // ---
    // Guard against a vacuous pass. The toggle is only meaningfully absent when the
    // featured image itself imported: a failed download leaves both keys unset, and the
    // credit assertion below would then hold for entirely the wrong reason.
    // ---
    expect($entryData)->toHaveKey('featured_image_16_9');
    expect($entryData['featured_image_16_9'])->toEqual('default/2020-05-fpo_200.jpg');

    // An uncredited featured image leaves the toggle on the blueprint default
    expect($entryData)->not->toHaveKey('featured_image_credit_query');

    // Clean up variables to free up memory
    unset(
        $xmlParameters,
        $xmlGeneratorCallback,
        $entry,
        $entryData
    );
});

it('handles category and tag lookups in streaming XML parser mode', function (): void {
    // Create test XML file with categories and tags
    $xmlPath = $this->createTempFile('test_post_comprehensive_');
    file_put_contents(
        $xmlPath,
        postCreateTestXmlContent(
            $this->getXmlHeader(),
            $this->getXmlFooter(),
            $this->getUniqueSlugForEntryVariant('post', 'standard_v01'),
            'standard_v01'
        )
    );

    // Setup XmlParser in streaming mode (simulating the scenario from the import command)
    $xmlParser = new XmlParser;

    // Use streaming parser to get content type counts (like in parseLargeXmlFile)
    $streamParser = new XmlStreamParser($xmlPath);
    $contentTypes = [];

    // Count all content types dynamically by streaming through items
    foreach ($streamParser->streamAllItems() as $item) {
        // Extract the post type from the WordPress XML item
        $postType = XpathHelper::getStringValue(
            $item,
            'wp:post_type'
        );

        // Check if post type is not empty before counting
        if ($postType !== '') {
            // Increment the count for this post type
            $contentTypes[$postType] = ($contentTypes[$postType] ?? 0) + 1;
        }

        // Clean up variables to free up memory
        unset($item);
    }

    // Set the content types in the regular parser for compatibility (streaming mode)
    $xmlParser->setContentTypes($contentTypes);
    $xmlParser->setFilePath($xmlPath);

    // Get base blog URL from a quick XMLReader scan
    $reader = XMLReader::open(
        $xmlPath,
        null,
        LIBXML_NOCDATA | LIBXML_PARSEHUGE
    );
    if (! $reader instanceof XMLReader) {
        throw new RuntimeException('Unable to open XML file for streaming base URL scan.');
    }
    $baseBlogUrl = '';

    try {
        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::ELEMENT && $reader->name === 'wp:base_blog_url') {
                $reader->read();
                $baseBlogUrl = $reader->value;
                break;
            }
        }
    } finally {
        $reader->close();

        // Clean up variables to free up memory
        unset($reader);
    }

    $xmlParser->setBaseBlogUrl($baseBlogUrl);

    // Clean up variables to free up memory
    unset(
        $streamParser,
        $contentTypes
    );
    gc_collect_cycles();

    // Verify that the XML parser is in streaming mode (xml property is null)
    $reflection = new ReflectionClass($xmlParser);
    $xmlProperty = $reflection->getProperty('xml');
    $xmlProperty->setAccessible(true);
    expect($xmlProperty->getValue($xmlParser))->toBeNull();

    // Setup other required instances
    $assetImporter = new AssetImporter;
    $output = $this->createMock(OutputInterface::class);
    $metadataMapper = new MetadataMapper;
    $contentProcessor = new ContentProcessor;

    // Create PostEntryCreator and test that it can handle streaming mode
    $postEntryCreator = new PostEntryCreator;

    // This should not throw "XML has not been parsed yet" error
    $result = $postEntryCreator->createEntries(
        'default',
        'posts',
        $xmlParser,
        $assetImporter,
        $output,
        $metadataMapper,
        $contentProcessor
    );

    // The import completed successfully
    expect(\count($result))->toBeGreaterThan(0);

    // Clean up variables to free up memory
    unset($xmlPath);
});

it('stores the news release entry ID in the imported PDF asset metadata', function (): void {
    // Skip when asset uploads are disabled in config
    if (
        config(
            'wordpress-import.upload_images',
            true
        ) === false
        || config(
            'wordpress-import.upload_files',
            true
        ) === false
    ) {
        // Skip test if asset uploads are disabled in config
        $this::markTestSkipped('Asset uploads disabled in config.');
    }

    // Prevent live HTTP for the PDF URL used in news release test XML
    $pdfUrl = 'https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf';

    // Fake the PDF URL response
    Http::fake([
        $pdfUrl => Http::response(
            "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n",
            200,
            [
                'Content-Type' => 'application/pdf',
            ]
        ),
    ]);

    // Clear importer caches so this test does not reuse another case's path
    AssetImporter::clearFileCache();

    // Create test XML file with news release PDF URL
    $xmlPath = $this->createTempFile('test_post_comprehensive_');
    file_put_contents(
        $xmlPath,
        postCreateTestXmlContent(
            $this->getXmlHeader(),
            $this->getXmlFooter(),
            $this->getUniqueSlugForEntryVariant('post', 'news_release_v01'),
            'news_release_v01'
        )
    );

    // Initialize XML parser for the export file
    $xmlParser = new XmlParser;

    // Parse the export so post and attachment nodes are available
    $xmlParser->parse($xmlPath);

    // Build asset importer using default upload and same-site settings
    $assetImporter = new AssetImporter;

    // Mock console output so createEntries does not print during the test
    $output = $this->createMock(OutputInterface::class);

    // Build metadata mapper for WordPress postmeta to entry fields
    $metadataMapper = new MetadataMapper;

    // Build content processor for body content and embedded assets
    $contentProcessor = new ContentProcessor;

    // Build the creator under test
    $postEntryCreator = new PostEntryCreator;

    // Import posts for the default site into the post collection
    $postEntryCreator->createEntries(
        'default',
        'posts',
        $xmlParser,
        $assetImporter,
        $output,
        $metadataMapper,
        $contentProcessor
    );

    // Find the entry matching the news release fixture slug
    $collection = postImportPostsCollection();
    $createdEntry = $collection === null
        ? null
        : $this::findEntryBySlug(
            $collection,
            'default',
            'news-release-post-entry-v01'
        );

    // The news release entry was created (the host-app trait finder resolves as an
    // untyped value under static analysis, so the instanceof guard both asserts its
    // existence and narrows it to Entry for the assertions below)
    if (! $createdEntry instanceof Entry) {
        throw new RuntimeException('Expected a post Entry for the news release slug.');
    }

    // Read the imported PDF path from the entry field
    $pdfPath = $createdEntry->value('news_release_pdf');

    // The PDF field was populated by import
    expect($pdfPath)->not->toBeEmpty();

    // ---
    // The stored shape is a single-element LIST, and that is the host app's design
    // rather than anything the importer does (wordpress-importer-archive#611).
    //
    // `news_release_pdf` is an `assets` field. When the entry saves, the host's
    // `GenerateNewsReleasePdf` listener regenerates the PDF from the news-release
    // template and overwrites the field via
    // `NewsReleasePdfGenerator::rawNewsReleasePdfFieldValue()`, which is documented
    // `@return list<string>` and comments the value as "the single-path list
    // expected by the assets field in YAML". So whatever path the importer wrote is
    // replaced, and asserting a bare string here asserted a shape nothing produces.
    // ---
    expect($pdfPath)->toBeArray()->toHaveCount(1);

    // Narrow for PHPStan: Entry::value() is typed mixed, matching the guard idiom below
    if (! \is_array($pdfPath)) {
        throw new RuntimeException('Expected the news_release_pdf field to hold a list.');
    }

    $pdfPath = $pdfPath[0] ?? null;

    // The single entry in that list is a container-relative path string
    expect($pdfPath)->toBeString();
    if (! is_string($pdfPath)) {
        throw new RuntimeException('Expected the imported PDF path to be a string.');
    }

    // Load the asset from the news_releases container by path
    $asset = Asset::find('news_releases::'.$pdfPath);

    // The PDF file exists as a Statamic asset
    expect($asset)->not->toBeNull();

    // Resolve concrete asset: Asset::find() is typed as the contract, which omits data()
    $statamicAsset = $asset instanceof StatamicAsset ? $asset : null;
    expect($statamicAsset)->not->toBeNull();
    if (! $statamicAsset instanceof StatamicAsset) {
        throw new RuntimeException('Asset::find() must return '.StatamicAsset::class.'.');
    }

    // Asset metadata points back to this entry id
    expect($statamicAsset->get('news_release_entry'))->toBe($createdEntry->id());

    // Check if the test XML file exists and delete it
    if (\file_exists($xmlPath)) {
        // Delete the temporary export file
        unlink($xmlPath);
    }

    // Unset local variables to free up memory
    unset(
        $xmlPath,
        $xmlParser,
        $assetImporter,
        $output,
        $metadataMapper,
        $contentProcessor,
        $postEntryCreator,
        $createdEntry,
        $pdfPath,
        $asset,
        $statamicAsset
    );
});

it('excludes post_tag from taxonomy handles when tag import is disabled', function (): void {
    // Set tag import to false to simulate WORDPRESS_IMPORT_TAGS=false
    Config::set(
        'wordpress-import.import_tags',
        false
    );

    // Use try/finally to restore config after assertions
    try {
        // Get taxonomy handles with tag import disabled
        $handles = PostEntryCreator::getTaxonomyHandlesForPostImport();

        // post_tag must be excluded when wordpress-import.import_tags is false
        expect($handles)->not->toContain('post_tag');

        // category is still in handles
        expect($handles)->toContain('category');
    } finally {
        // Restore default config so other tests are not affected
        Config::set(
            'wordpress-import.import_tags',
            true
        );
    }
});

it('includes post_tag in taxonomy handles when tag import is enabled', function (): void {
    // Set tag import to true for this test
    Config::set(
        'wordpress-import.import_tags',
        true
    );

    // Use try/finally to restore config after assertions
    try {
        // Get taxonomy handles with tag import enabled
        $handles = PostEntryCreator::getTaxonomyHandlesForPostImport();

        // post_tag is in handles when tag import is enabled
        expect($handles)->toContain('post_tag');

        // category is in handles
        expect($handles)->toContain('category');
    } finally {
        // Restore config so other tests are not affected
        Config::set(
            'wordpress-import.import_tags',
            true
        );
    }
});

/**
 * Resolve the posts collection when it exists.
 *
 * @return ?PostsCollection The posts collection, or null when missing
 */
function postImportPostsCollection(): ?PostsCollection
{
    $collection = CollectionFacade::find('posts');

    return $collection instanceof PostsCollection ? $collection : null;
}

/**
 * Deep-merge YAML validation case data while preserving the base array shape.
 *
 * @template T of array{base_test_name: string, xml_parameters: array{postType: string, entryVariantType: string, contentEncoded: ?string, postFormatNicename: ?string, postFormatValue: ?string, includeBoilerplate: ?string, newsReleasePdfUrl: ?string}, yaml_assertions: array<string, array{field: string, value: mixed, type: string}>}
 *
 * @param  T  $base
 * @param  array<string, mixed>  $overrides
 * @return T
 */
function postReplaceYamlCase(
    array $base,
    array $overrides
): array {
    /** @var T */
    return array_replace_recursive(
        $base,
        $overrides
    );
}

/**
 * Base YAML validation fixture shared by post import field tests.
 *
 * @return array{
 *     base_test_name: string,
 *     xml_parameters: array{
 *         postType: string,
 *         entryVariantType: string,
 *         contentEncoded: ?string,
 *         postFormatNicename: ?string,
 *         postFormatValue: ?string,
 *         includeBoilerplate: ?string,
 *         newsReleasePdfUrl: ?string,
 *     },
 *     yaml_assertions: array<string, array{field: string, value: mixed, type: string}>,
 * }
 */
function postYamlValidationBase(): array
{
    return [
        'base_test_name' => '',
        'xml_parameters' => [
            'postType' => 'post',
            'entryVariantType' => 'standard_v01',
            'contentEncoded' => null,
            'postFormatNicename' => null,
            'postFormatValue' => null,
            'includeBoilerplate' => null,
            'newsReleasePdfUrl' => null,
        ],
        'yaml_assertions' => [
            'blueprint' => [
                'field' => 'blueprint',
                'value' => 'post',
                'type' => 'string',
            ],
            'title' => [
                'field' => 'title',
                'value' => 'Sample Blog Post',
                'type' => 'string',
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
            'slug' => [
                'field' => 'slug',
                'value' => 'standard-post-entry-v01',
                'type' => 'string',
            ],
            'short title' => [
                'field' => 'title_short',
                'value' => 'SEO Title for Sample Blog Post',
                'type' => 'string',
            ],
            'excerpt' => [
                'field' => 'excerpt',
                'value' => 'This is a sample blog post excerpt',
                'type' => 'string',
            ],
            'featured image 16:9' => [
                'field' => 'featured_image_16_9',
                'value' => 'default/2020-05-fpo_200.jpg',
                'type' => 'string',
            ],
            // The variant XML credits the featured-image attachment, so the imported
            // entry must carry the toggle that surfaces that credit (wordpress-importer-archive#543).
            'featured image credit query' => [
                'field' => 'featured_image_credit_query',
                'value' => true,
                'type' => 'boolean',
            ],
            'stick this post to the front page' => [
                'field' => 'sticky_query',
                'value' => false,
                'type' => 'boolean',
            ],
            'hide author information' => [
                'field' => 'hide_creator_query',
                'value' => true,
                'type' => 'boolean',
            ],
            // ---
            // A `post_categories` COLLECTION-ENTRY id, generated per import, so
            // it is matched by shape rather than by value — a 36-character UUID.
            // The handle is plural and the value is an id, not a site-prefixed
            // slug: both changed when the destination became a collection (wordpress-importer-archive#422).
            // ---
            'post categories' => [
                'field' => 'post_categories',
                'value' => ['#rowId(36)'],
                'type' => 'array',
            ],
            // Tags import as `post_tags` COLLECTION entries too (wordpress-importer-archive#1220), so this
            // is an entry id matched by shape, like the categories above
            'post tags' => [
                'field' => 'post_tags',
                'value' => ['#rowId(36)'],
                'type' => 'array',
            ],
            'include boilerplate' => [
                'field' => 'boilerplate_query',
                'value' => false,
                'type' => 'boolean',
            ],
            'header scripts' => [
                'field' => 'scripts_head',
                'value' => [
                    'code' => '<script>console.log("Head script");</script>',
                    'mode' => 'htmlmixed',
                ],
                'type' => 'array',
            ],
            'body scripts' => [
                'field' => 'scripts_body',
                'value' => [
                    'code' => '<script>console.log("Body script");</script>',
                    'mode' => 'htmlmixed',
                ],
                'type' => 'array',
            ],
            'body scripts position' => [
                'field' => 'scripts_body_position',
                'value' => 'bottom',
                'type' => 'string',
            ],
            'noindex' => [
                'field' => 'robots_index_meta',
                'value' => true,
                'type' => 'boolean',
            ],
            'nofollow' => [
                'field' => 'robots_follow_meta',
                'value' => true,
                'type' => 'boolean',
            ],
            'noodp' => [
                'field' => 'robots_odp_meta',
                'value' => true,
                'type' => 'boolean',
            ],
            'noimageindex' => [
                'field' => 'robots_imageindex_meta',
                'value' => true,
                'type' => 'boolean',
            ],
            'noarchive' => [
                'field' => 'robots_archive_meta',
                'value' => true,
                'type' => 'boolean',
            ],
            'nosnippet' => [
                'field' => 'robots_snippet_meta',
                'value' => true,
                'type' => 'boolean',
            ],
            'canonical url' => [
                'field' => 'robots_canonical_meta',
                'value' => 'https://example.com/canonical-url',
                'type' => 'string',
            ],
        ],
    ];
}

/**
 * Assemble a comprehensive post test XML document with entry variant content.
 *
 * Pure — the trait-bound WXR header, footer, and resolved unique slug are passed
 * in so the assembly needs no test-case instance.
 *
 * @param  string  $header  The WXR document header
 * @param  string  $footer  The WXR document footer
 * @param  string  $uniqueSlug  The resolved unique slug for the entry variant
 * @param  string  $entryVariantType  The type of entry variant to create (standard, news_release, audio, video,
 *                                    gallery)
 * @param  ?string  $contentEncoded  The content for the entry variant (defaults based on entry variant type)
 * @param  ?string  $postFormatNicename  The post format category nicename (defaults based on entry variant type)
 * @param  ?string  $postFormatValue  The post format category value (defaults based on entry variant type)
 * @param  ?string  $includeBoilerplate  The include_boilerplate meta value (defaults based on entry variant type)
 * @param  ?string  $newsReleasePdfUrl  The news_release_pdf_url meta value (defaults based on entry variant type)
 * @param  ?string  $featuredImageCredit  Photo credit written as `_media_credit` on the featured-image attachment
 *                                        (`wp:post_id` 456); null emits no credit postmeta at all, which is the
 *                                        uncredited case `featured_image_credit_query` must leave unset (wordpress-importer-archive#543)
 * @return string The assembled XML document
 */
function postCreateTestXmlContent(
    string $header,
    string $footer,
    string $uniqueSlug,
    string $entryVariantType = 'standard_v01',
    ?string $contentEncoded = null,
    ?string $postFormatNicename = null,
    ?string $postFormatValue = null,
    ?string $includeBoilerplate = null,
    ?string $newsReleasePdfUrl = null,
    ?string $featuredImageCredit = null
): string {
    // Set default values based on entry variant type
    $defaults = postGetEntryVariantTypeDefaults($entryVariantType);

    $contentEncoded = $contentEncoded ?? $defaults['content_encoded'];
    $postFormatNicename = $postFormatNicename ?? $defaults['post_format_nicename'];
    $postFormatValue = $postFormatValue ?? $defaults['post_format_value'];
    $includeBoilerplate = $includeBoilerplate ?? $defaults['include_boilerplate'];
    $newsReleasePdfUrl = $newsReleasePdfUrl ?? $defaults['news_release_pdf_url'];

    $xmlContent = $header;

    // Add post categories
    $xmlContent .= postGetCategoryTermsXml($entryVariantType);

    // Add post with full metadata
    $xmlContent .= '        <item>
            <title><![CDATA[Sample Blog Post]]></title>
            <link>https://example.com/'.$uniqueSlug.'/</link>
            <pubDate>Wed, 12 Jun 2024 15:10:35 +0000</pubDate>
            <dc:creator><![CDATA[testuser]]></dc:creator>
            <guid isPermaLink="false">https://example.com/?post_type=post&#038;p=123</guid>
            <description></description>
            <content:encoded><![CDATA['.$contentEncoded.']]></content:encoded>
            <excerpt:encoded><![CDATA[This is a sample blog post excerpt]]></excerpt:encoded>
            <wp:post_id>123</wp:post_id>
            <wp:post_date><![CDATA[2024-06-12 15:10:35]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2024-06-12 15:10:35]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-06-12 15:10:35]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-06-12 15:10:35]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[open]]></wp:comment_status>
            <wp:ping_status><![CDATA[open]]></wp:ping_status>
            <wp:post_name><![CDATA['.$uniqueSlug.']]></wp:post_name>
            <wp:status><![CDATA[publish]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[post]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            '.postGetCategoryXml($entryVariantType).'
            <category domain="post_tag" nicename="lorem-ipsum"><![CDATA[Lorem Ipsum]]></category>'.
        ($postFormatNicename !== null && $postFormatValue !== null ? '
            <category domain="post_format" nicename="'.$postFormatNicename.'"><![CDATA['.$postFormatValue.']]></category>' : '').
        '<wp:postmeta>
                <wp:meta_key><![CDATA[_edit_last]]></wp:meta_key>
                <wp:meta_value><![CDATA[10021]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_genesis_scripts]]></wp:meta_key>
                <wp:meta_value><![CDATA[<script>console.log("Head script");</script>]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_genesis_scripts_body]]></wp:meta_key>
                <wp:meta_value><![CDATA[<script>console.log("Body script");</script>]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_genesis_scripts_body_position]]></wp:meta_key>
                <wp:meta_value><![CDATA[bottom]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[include_boilerplate]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$includeBoilerplate.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[news_release_pdf_url]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$newsReleasePdfUrl.']]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[post_hide_author]]></wp:meta_key>
                <wp:meta_value><![CDATA[1]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[pricat_get_categories]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[seo_canonical_url]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[seo_custom_redirect_url]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[seo_document_title]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[seo_meta_description]]></wp:meta_key>
                <wp:meta_value><![CDATA[Alternative SEO meta description]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[seo_meta_keywords]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_analysis_data]]></wp:meta_key>
                <wp:meta_value><![CDATA[a:19:{s:5:"title";s:55:" UAMS to Host Fall Career Expo on Oct. 4  |  UAMS News ";s:9:"meta_desc";s:187:"LITTLE ROCK — The University of Arkansas for Medical Sciences (UAMS) is hosting a Fall Career Expo from 9 a.m. to 1 p.m. Oct. 4 in the I. Dodd Wilson Building on the Little Rock campus.";s:8:"og_title";a:2:{s:5:"count";i:1;s:6:"values";a:1:{i:0;s:51:"UAMS to Host Fall Career Expo on Oct. 4 - UAMS News";}}s:7:"og_desc";a:2:{s:5:"count";i:1;s:6:"values";a:1:{i:0;s:187:"LITTLE ROCK — The University of Arkansas for Medical Sciences (UAMS) is hosting a Fall Career Expo from 9 a.m. to 1 p.m. Oct. 4 in the I. Dodd Wilson Building on the Little Rock campus.";}}s:6:"og_img";a:2:{s:5:"count";i:1;s:6:"values";a:1:{i:0;s:81:"https://news.uams.edu/wp-content/uploads/2025/09/GettyImages-481068305-scaled.jpg";}}s:6:"og_url";a:3:{s:5:"count";i:1;s:6:"values";a:1:{i:0;s:72:"https://news.uams.edu/2025/09/22/uams-to-host-fall-career-expo-on-oct-4/";}s:4:"host";s:13:"news.uams.edu";}s:12:"og_site_name";a:2:{s:5:"count";i:1;s:6:"values";a:1:{i:0;s:9:"UAMS News";}}s:8:"tw_title";a:2:{s:5:"count";i:1;s:6:"values";a:1:{i:0;s:51:"UAMS to Host Fall Career Expo on Oct. 4 - UAMS News";}}s:7:"tw_desc";a:2:{s:5:"count";i:1;s:6:"values";a:1:{i:0;s:187:"LITTLE ROCK — The University of Arkansas for Medical Sciences (UAMS) is hosting a Fall Career Expo from 9 a.m. to 1 p.m. Oct. 4 in the I. Dodd Wilson Building on the Little Rock campus.";}}s:6:"tw_img";a:2:{s:5:"count";i:1;s:6:"values";a:1:{i:0;s:83:"https://news.uams.edu/wp-content/uploads/2025/09/GettyImages-481068305-1024x683.jpg";}}s:9:"canonical";s:72:"https://news.uams.edu/2025/09/22/uams-to-host-fall-career-expo-on-oct-4/";s:13:"all_canonical";a:1:{i:0;s:72:"https://news.uams.edu/2025/09/22/uams-to-host-fall-career-expo-on-oct-4/";}s:2:"h1";a:1:{s:9:"nomatches";a:1:{s:5:"count";i:2;}}s:3:"img";a:1:{s:6:"images";a:0:{}}s:11:"meta_robots";a:1:{i:0;a:1:{i:0;s:13:"index, follow";}}s:14:"outbound_links";a:25:{i:1;a:1:{s:19:"http://www.uams.edu";s:43:"University of Arkansas for Medical Sciences";}i:2;a:1:{s:21:"http://uamshealth.com";s:10:"UAMSHealth";}i:3;a:1:{s:20:"http://jobs.uams.edu";s:4:"Jobs";}i:4;a:1:{s:22:"http://giving.uams.edu";s:6:"Giving";}i:5;a:1:{s:19:"http://www.uams.edu";s:4:"Home";}i:6;a:1:{s:52:"https://hr.uams.edu/talent-management/uams-job-expo/";s:44:"hr.uams.edu/talent-management/uams-job-expo/";}i:7;a:1:{s:21:"https://www.uams.edu/";s:12:"www.uams.edu";}i:8;a:1:{s:23:"https://uamshealth.com/";s:14:"uamshealth.com";}i:9;a:1:{s:35:"https://www.facebook.com/UAMShealth";s:8:"Facebook";}i:10;a:1:{s:30:"https://twitter.com/uamshealth";s:20:"X (formerly Twitter)";}i:11;a:1:{s:39:"https://www.youtube.com/user/UAMSHealth";s:7:"YouTube";}i:12;a:1:{s:33:"https://instagram.com/uamshealth/";s:9:"Instagram";}i:13;a:1:{s:19:"http://www.uams.edu";s:43:"University of Arkansas for Medical Sciences";}i:14;a:1:{s:30:"http://facebook.com/uamshealth";s:8:"Facebook";}i:15;a:1:{s:29:"http://twitter.com/uamshealth";s:7:"Twitter";}i:16;a:1:{s:31:"http://instagram.com/uamshealth";s:9:"Instagram";}i:17;a:1:{s:39:"https://www.youtube.com/user/UAMSHealth";s:7:"YouTube";}i:18;a:1:{s:37:"https://www.linkedin.com/company/uams";s:8:"LinkedIn";}i:19;a:1:{s:31:"http://pinterest.com/uamshealth";s:9:"Pinterest";}i:20;a:1:{s:34:"https://uamshealth.com/disclaimer/";s:10:"Disclaimer";}i:21;a:1:{s:22:"https://jobs.uams.edu/";s:4:"Jobs";}i:22;a:1:{s:37:"https://uamshealth.com/privacy/#legal";s:19:"Copyright Statement";}i:23;a:1:{s:31:"https://uamshealth.com/privacy/";s:7:"Privacy";}i:24;a:1:{s:36:"https://uamshealth.com/terms-of-use/";s:5:"Terms";}i:25;a:1:{s:37:"https://uamshealth.com/legal-notices/";s:13:"Legal Notices";}}s:13:"words_counter";i:150;s:20:"words_counter_unique";i:114;s:5:"score";b:0;}]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_analysis_target_kw]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_redirections_type]]></wp:meta_key>
                <wp:meta_value><![CDATA[301]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_redirections_value]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_robots_archive_meta]]></wp:meta_key>
                <wp:meta_value><![CDATA[1]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_robots_canonical_meta]]></wp:meta_key>
                <wp:meta_value><![CDATA[https://example.com/canonical-url]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_robots_follow_meta]]></wp:meta_key>
                <wp:meta_value><![CDATA[1]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_robots_imageindex_meta]]></wp:meta_key>
                <wp:meta_value><![CDATA[1]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_robots_index_meta]]></wp:meta_key>
                <wp:meta_value><![CDATA[1]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_robots_odp_meta]]></wp:meta_key>
                <wp:meta_value><![CDATA[1]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_robots_primary_cat]]></wp:meta_key>
                <wp:meta_value><![CDATA[none]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_robots_snippet_meta]]></wp:meta_key>
                <wp:meta_value><![CDATA[1]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_social_fb_desc]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_social_fb_img]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_social_fb_title]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_social_twitter_desc]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_social_twitter_img]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_social_twitter_title]]></wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_titles_desc]]></wp:meta_key>
                <wp:meta_value><![CDATA[SEO description for the sample blog post]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_seopress_titles_title]]></wp:meta_key>
                <wp:meta_value><![CDATA[SEO Title for Sample Blog Post]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[show_image_single]]></wp:meta_key>
                <wp:meta_value><![CDATA[1]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_thumbnail_id]]></wp:meta_key>
                <wp:meta_value><![CDATA[456]]></wp:meta_value>
            </wp:postmeta>
        </item>';

    // Add attachments for featured images, etc.
    $xmlContent .= '        <item>
            <title><![CDATA[fpo_200.jpg]]></title>
            <link>https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_200.jpg</link>
            <pubDate>Wed, 12 Jun 2024 15:10:35 +0000</pubDate>
            <dc:creator><![CDATA[admin]]></dc:creator>
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
            <wp:attachment_url><![CDATA[https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_200.jpg]]></wp:attachment_url>'.
        // Credit the featured-image attachment only when the caller asks for one, so
        // every existing caller's XML stays byte-identical to before (wordpress-importer-archive#543).
        ($featuredImageCredit !== null ? '
            <wp:postmeta>
                <wp:meta_key><![CDATA[_media_credit]]></wp:meta_key>
                <wp:meta_value><![CDATA['.$featuredImageCredit.']]></wp:meta_value>
            </wp:postmeta>' : '').'
        </item>
        <item>
            <title><![CDATA[fpo_100.jpg]]></title>
            <link>https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_100.jpg</link>
            <pubDate>Wed, 12 Jun 2024 15:10:35 +0000</pubDate>
            <dc:creator><![CDATA[admin]]></dc:creator>
            <guid isPermaLink="false">https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_100.jpg</guid>
            <description></description>
            <content:encoded><![CDATA[]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>457</wp:post_id>
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
            <wp:attachment_url><![CDATA[https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_100.jpg]]></wp:attachment_url>
        </item>
        <item>
            <title><![CDATA[fpo_400.jpg]]></title>
            <link>https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_400.jpg</link>
            <pubDate>Wed, 12 Jun 2024 15:10:35 +0000</pubDate>
            <dc:creator><![CDATA[admin]]></dc:creator>
            <guid isPermaLink="false">https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_400.jpg</guid>
            <description></description>
            <content:encoded><![CDATA[]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>458</wp:post_id>
            <wp:post_date><![CDATA[2024-06-12 15:10:35]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2024-06-12 15:10:35]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-06-12 15:10:35]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-06-12 15:10:35]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[fpo_400]]></wp:post_name>
            <wp:status><![CDATA[inherit]]></wp:status>
            <wp:post_parent>123</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[attachment]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            <wp:attachment_url><![CDATA[https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_400.jpg]]></wp:attachment_url>
        </item>
        <item>
            <title><![CDATA[HTYH 09-08-25 Teen Health - Once a year]]></title>
            <link>https://news.uams.edu/htyh-09-08-25-teen-health-once-a-year/</link>
            <pubDate>Fri, 05 Sep 2025 17:52:47 +0000</pubDate>
            <dc:creator><![CDATA[Tim]]></dc:creator>
            <guid isPermaLink="false">
                https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3</guid>
            <description></description>
            <content:encoded><![CDATA["HTYH 09-08-25 Teen Health - Once a year". Released: 2025.]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>457</wp:post_id>
            <wp:post_date><![CDATA[2025-09-05 12:52:47]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2025-09-05 17:52:47]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2025-09-05 12:52:47]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2025-09-05 17:52:47]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[htyh-09-08-25-teen-health-once-a-year]]></wp:post_name>
            <wp:status><![CDATA[inherit]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[attachment]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            <wp:attachment_url><![CDATA[https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3]]></wp:attachment_url>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_wp_attached_file]]></wp:meta_key>
                <wp:meta_value><![CDATA[2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_wp_attachment_metadata]]></wp:meta_key>
                <wp:meta_value><![CDATA[a:17:{s:10:"dataformat";s:3:"mp3";s:8:"channels";i:2;s:11:"sample_rate";i:44100;s:7:"bitrate";i:192000;s:11:"channelmode";s:12:"joint stereo";s:12:"bitrate_mode";s:3:"cbr";s:8:"lossless";b:0;s:15:"encoder_options";s:6:"CBR192";s:17:"compression_ratio";d:0.1360544217687075;s:10:"fileformat";s:3:"mp3";s:8:"filesize";i:2180504;s:9:"mime_type";s:10:"audio/mpeg";s:6:"length";i:90;s:16:"length_formatted";s:4:"1:30";s:4:"year";s:4:"2025";s:4:"date";s:4:"0209";s:4:"time";s:4:"1114";}]]></wp:meta_value>
            </wp:postmeta>
        </item>
        <item>
            <title><![CDATA[HTYH 09-09-25 Teen Health - Vaccinations]]></title>
            <link>https://news.uams.edu/htyh-09-09-25-teen-health-vaccinations/</link>
            <pubDate>Fri, 05 Sep 2025 17:52:47 +0000</pubDate>
            <dc:creator><![CDATA[Tim]]></dc:creator>
            <guid isPermaLink="false">
                https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3</guid>
            <description></description>
            <content:encoded><![CDATA["HTYH 09-09-25 Teen Health - Vaccinations". Released: 2025.]]></content:encoded>
            <excerpt:encoded><![CDATA[]]></excerpt:encoded>
            <wp:post_id>458</wp:post_id>
            <wp:post_date><![CDATA[2025-09-05 12:52:47]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2025-09-05 17:52:47]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2025-09-05 12:52:47]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2025-09-05 17:52:47]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[htyh-09-09-25-teen-health-vaccinations]]></wp:post_name>
            <wp:status><![CDATA[inherit]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[attachment]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            <wp:attachment_url><![CDATA[https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3]]></wp:attachment_url>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_wp_attached_file]]></wp:meta_key>
                <wp:meta_value><![CDATA[2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_wp_attachment_metadata]]></wp:meta_key>
                <wp:meta_value><![CDATA[a:17:{s:10:"dataformat";s:3:"mp3";s:8:"channels";i:2;s:11:"sample_rate";i:44100;s:7:"bitrate";i:192000;s:11:"channelmode";s:12:"joint stereo";s:12:"bitrate_mode";s:3:"cbr";s:8:"lossless";b:0;s:15:"encoder_options";s:6:"CBR192";s:17:"compression_ratio";d:0.1360544217687075;s:10:"fileformat";s:3:"mp3";s:8:"filesize";i:2183099;s:9:"mime_type";s:10:"audio/mpeg";s:6:"length";i:90;s:16:"length_formatted";s:4:"1:30";s:4:"year";s:4:"2025";s:4:"date";s:4:"0209";s:4:"time";s:4:"1115";}]]></wp:meta_value>
            </wp:postmeta>
        </item>';

    $xmlContent .= $footer;

    return $xmlContent;
}

/**
 * Get category terms XML based on entry variant type.
 *
 * @param  string  $entryVariantType  The type of entry variant
 * @return string The category terms XML string
 */
function postGetCategoryTermsXml(
    string $entryVariantType
): string {
    // ---
    // Every variant tags its post `lorem-ipsum`, so the tag is declared the way
    // WordPress declares one: a channel-level `<wp:tag>`. The corpus carries
    // 16,363 item-level tag references and every one is declared this way, and
    // the term pass creates tag entries from the declarations only (wordpress-importer-archive#1220).
    // ---
    $tagXml = '        <wp:tag>
            <wp:term_id>5</wp:term_id>
            <wp:tag_slug>lorem-ipsum</wp:tag_slug>
            <wp:tag_name><![CDATA[Lorem Ipsum]]></wp:tag_name>
        </wp:tag>
';

    // Default to news category
    $termsXml = '        <wp:term>
            <wp:term_id>1</wp:term_id>
            <wp:term_taxonomy>category</wp:term_taxonomy>
            <wp:term_slug>news</wp:term_slug>
            <wp:term_name><![CDATA[News]]></wp:term_name>
            <wp:term_parent></wp:term_parent>
        </wp:term>';

    // For audio posts, use the audio series category
    if (
        \strpos(
            $entryVariantType,
            'audio'
        ) === 0
    ) {
        $termsXml = '        <wp:term>
            <wp:term_id>2</wp:term_id>
            <wp:term_taxonomy>category</wp:term_taxonomy>
            <wp:term_slug>heres-to-your-health</wp:term_slug>
            <wp:term_name><![CDATA[Here\'s To Your Health]]></wp:term_name>
            <wp:term_parent></wp:term_parent>
        </wp:term>';
    }

    return $tagXml.$termsXml;
}

/**
 * Get category XML based on entry variant type.
 *
 * @param  string  $entryVariantType  The type of entry variant
 * @return string The category XML string
 */
function postGetCategoryXml(
    string $entryVariantType
): string {
    // Default to news category
    $categoryXml = '<category domain="category" nicename="news"><![CDATA[News]]></category>';

    // For audio posts, use the audio series category
    if (
        \strpos(
            $entryVariantType,
            'audio'
        ) === 0
    ) {
        $categoryXml = '<category domain="category" nicename="heres-to-your-health"><![CDATA[Here\'s To Your Health]]></category>';
    }

    return $categoryXml;
}

/**
 * Get default values for different entry variant types.
 *
 * @param  string  $entryVariantType  The type of entry variant
 * @return array{
 *     content_encoded: string,
 *     post_format_nicename: ?string,
 *     post_format_value: ?string,
 *     include_boilerplate: string,
 *     news_release_pdf_url: string,
 * }
 */
function postGetEntryVariantTypeDefaults(
    string $entryVariantType
): array {
    $defaults = [
        'standard_v01' => [
            'content_encoded' => 'Lorem ipsum dolor sit amet consectetuer.

Aenean commodo ligula eget dolor.

Aenean massa cum sociis natoque penatibus.',
            'post_format_nicename' => null,
            'post_format_value' => null,
            'include_boilerplate' => '0',
            'news_release_pdf_url' => '',
        ],
        'news_release_v01' => [
            'content_encoded' => 'LITTLE ROCK — Lorem ipsum dolor sit amet consectetuer.

Aenean commodo ligula eget dolor.

Aenean massa cum sociis natoque penatibus.',
            'post_format_nicename' => null,
            'post_format_value' => null,
            'include_boilerplate' => '1',
            'news_release_pdf_url' => 'https://news.uams.edu/wp-content/uploads/2025/09/REL-Fall-Career-Expo-2025.pdf',
        ],
        'audio_v01' => [
            'content_encoded' => 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.',
            'post_format_nicename' => 'post-format-audio',
            'post_format_value' => 'Audio',
            'include_boilerplate' => '0',
            'news_release_pdf_url' => '',
        ],
        'video_v01' => [
            'content_encoded' => 'Lorem ipsum dolor sit amet consectetuer.

Aenean commodo ligula eget dolor.

Aenean massa cum sociis natoque penatibus.',
            'post_format_nicename' => 'post-format-video',
            'post_format_value' => 'Video',
            'include_boilerplate' => '0',
            'news_release_pdf_url' => '',
        ],
        'gallery_v01' => [
            'content_encoded' => 'Lorem ipsum dolor sit amet consectetuer.

Aenean commodo ligula eget dolor.

Aenean massa cum sociis natoque penatibus.',
            'post_format_nicename' => 'post-format-gallery',
            'post_format_value' => 'Gallery',
            'include_boilerplate' => '0',
            'news_release_pdf_url' => '',
        ],
    ];

    $defaults['standard_v02'] = $defaults['standard_v01'];
    $defaults['standard_v03'] = $defaults['standard_v01'];
    $defaults['standard_v04'] = $defaults['standard_v01'];
    $defaults['standard_v05'] = $defaults['standard_v01'];
    $defaults['standard_v06'] = $defaults['standard_v01'];
    $defaults['standard_v08'] = $defaults['standard_v01'];
    $defaults['news_release_v02'] = $defaults['news_release_v01'];
    $defaults['audio_v02'] = $defaults['audio_v01'];
    $defaults['audio_v03'] = $defaults['audio_v01'];
    $defaults['audio_v04'] = $defaults['audio_v01'];
    $defaults['audio_v05'] = $defaults['audio_v01'];
    $defaults['audio_v06'] = $defaults['audio_v01'];
    $defaults['audio_v07'] = $defaults['audio_v01'];
    $defaults['audio_v08'] = $defaults['audio_v01'];
    $defaults['audio_v09'] = $defaults['audio_v01'];
    $defaults['audio_v10'] = $defaults['audio_v01'];
    $defaults['audio_v11'] = $defaults['audio_v01'];
    $defaults['audio_v12a'] = $defaults['audio_v01'];
    $defaults['audio_v12b'] = $defaults['audio_v01'];
    $defaults['audio_v12c'] = $defaults['audio_v01'];
    $defaults['audio_v12d'] = $defaults['audio_v01'];
    $defaults['audio_v12e'] = $defaults['audio_v01'];
    $defaults['audio_v12f'] = $defaults['audio_v01'];
    $defaults['audio_v12g'] = $defaults['audio_v01'];
    $defaults['audio_v12h'] = $defaults['audio_v01'];
    $defaults['audio_v12i'] = $defaults['audio_v01'];
    $defaults['audio_v13'] = $defaults['audio_v01'];
    $defaults['video_v02'] = $defaults['video_v01'];
    $defaults['gallery_v02'] = $defaults['gallery_v01'];

    return $defaults[$entryVariantType] ?? $defaults['standard_v01'];
}

/**
 * Data provider for individual YAML field validation tests.
 *
 * Each field is tested separately to make it easier to identify
 * which specific fields are failing during import validation.
 *
 * @return array<string, array<string, mixed>> Array of test cases with field name, expected value, and data type
 */
function postYamlFieldValidationProvider(): array
{
    $base = postYamlValidationBase();

    // wpautop content
    $standard_post_v01 = $base;
    $standard_post_v01['base_test_name'] = 'standard post v01';
    $standard_post_v01['yaml_assertions']['content'] = [
        'field' => 'content',
        'value' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Lorem ipsum dolor sit amet consectetuer.',
                    ],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Aenean commodo ligula eget dolor.',
                    ],
                ],
            ],
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
        'type' => 'array',
    ];

    // Gutenberg content
    $standard_post_v02 = $standard_post_v01;
    $standard_post_v02['base_test_name'] = 'standard post v02';
    $standard_post_v02['xml_parameters']['entryVariantType'] = 'standard_v02';
    $standard_post_v02['xml_parameters']['contentEncoded'] = '<!-- wp:paragraph -->
<p>Lorem ipsum dolor sit amet consectetuer.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Aenean commodo ligula eget dolor.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Aenean massa cum sociis natoque penatibus.</p>
<!-- /wp:paragraph -->';
    $standard_post_v02['yaml_assertions']['slug']['value'] = 'standard-post-entry-v02';

    // Vimeo embed block
    $standard_post_v03 = $standard_post_v01;
    $standard_post_v03['base_test_name'] = 'standard post v03 (vimeo embed)';
    $standard_post_v03['xml_parameters']['entryVariantType'] = 'standard_v03';
    $standard_post_v03['xml_parameters']['contentEncoded'] = '<!-- wp:paragraph -->
<p>Lorem ipsum dolor sit amet consectetuer.</p>
<!-- /wp:paragraph -->

<!-- wp:core-embed/vimeo {"url":"https://vimeo.com/336892869","type":"video","providerNameSlug":"vimeo","align":"wide","className":"wp-embed-aspect-16-9 wp-has-aspect-ratio"} -->
<figure class="wp-block-embed-vimeo alignwide wp-block-embed is-type-video is-provider-vimeo wp-embed-aspect-16-9 wp-has-aspect-ratio"><div class="wp-block-embed__wrapper">
https://vimeo.com/336892869
</div></figure>
<!-- /wp:core-embed/vimeo -->

<!-- wp:paragraph -->
<p>Aenean massa cum sociis natoque penatibus.</p>
<!-- /wp:paragraph -->';
    $standard_post_v03['yaml_assertions']['slug']['value'] = 'standard-post-entry-v03';
    $standard_post_v03['yaml_assertions']['content'] = [
        'field' => 'content',
        'value' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Lorem ipsum dolor sit amet consectetuer.',
                    ],
                ],
            ],
            [
                'type' => 'set',
                'attrs' => [
                    'id' => '#rowId(8)',
                    'values' => [
                        // `UAMS-Web/uams-statamic-archive#589` retired the `video` set for
                        // three platform-specific ones, with a `url` handle and an
                        // `embed_mode` toggle (wordpress-importer-archive#298)
                        'type' => 'vimeo',
                        'embed_mode' => 'simple',
                        'url' => 'https://vimeo.com/336892869',
                        'aspect_ratio' => '16by9',
                        'align' => 'alignwide',
                    ],
                ],
            ],
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
        'type' => 'array',
    ];

    // YouTube embed block
    $standard_post_v04 = $standard_post_v01;
    $standard_post_v04['base_test_name'] = 'standard post v04 (youtube embed)';
    $standard_post_v04['xml_parameters']['entryVariantType'] = 'standard_v04';
    $standard_post_v04['xml_parameters']['contentEncoded'] = '<!-- wp:paragraph -->
<p>Lorem ipsum dolor sit amet consectetuer.</p>
<!-- /wp:paragraph -->

<!-- wp:core-embed/youtube {"url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ","type":"video","providerNameSlug":"youtube","align":"wide","className":""} -->
<figure class="wp-block-embed-youtube alignwide wp-block-embed is-type-video is-provider-youtube"><div class="wp-block-embed__wrapper">
https://www.youtube.com/watch?v=dQw4w9WgXcQ
</div></figure>
<!-- /wp:core-embed/youtube -->

<!-- wp:paragraph -->
<p>Aenean massa cum sociis natoque penatibus.</p>
<!-- /wp:paragraph -->';
    $standard_post_v04['yaml_assertions']['slug']['value'] = 'standard-post-entry-v04';
    $standard_post_v04['yaml_assertions']['content'] = [
        'field' => 'content',
        'value' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Lorem ipsum dolor sit amet consectetuer.',
                    ],
                ],
            ],
            [
                'type' => 'set',
                'attrs' => [
                    'id' => '#rowId(8)',
                    'values' => [
                        // `UAMS-Web/uams-statamic-archive#589` retired the `video` set for
                        // three platform-specific ones, with a `url` handle and an
                        // `embed_mode` toggle (wordpress-importer-archive#298)
                        'type' => 'youtube',
                        'embed_mode' => 'simple',
                        'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                        'aspect_ratio' => '16by9',
                        'align' => 'alignwide',
                    ],
                ],
            ],
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
        'type' => 'array',
    ];

    // Gallery block
    $standard_post_v05 = $standard_post_v01;
    $standard_post_v05['base_test_name'] = 'standard post v05 (gallery block)';
    $standard_post_v05['xml_parameters']['entryVariantType'] = 'standard_v05';
    $standard_post_v05['xml_parameters']['contentEncoded'] = '<!-- wp:paragraph -->
<p>Lorem ipsum dolor sit amet consectetuer.</p>
<!-- /wp:paragraph -->

<!-- wp:gallery {"ids":[456,457,458],"columns":3,"linkTo":"media"} -->
<figure class="wp-block-gallery columns-3 is-cropped"><ul class="blocks-gallery-grid"><li class="blocks-gallery-item"><figure><a href="https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_200.jpg"><imgsrc="https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_200.jpg"alt="Foo bar baz qux" data-id="456" class="wp-image-456" /></a></figure></li><li class="blocks-gallery-item"><figure><a href="https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_100.jpg"><imgsrc="https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_100.jpg"alt="Quux" data-id="457" class="wp-image-457" /></a></figure></li><li class="blocks-gallery-item"><figure><a href="https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_400.jpg"><imgsrc="https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_400.jpg"alt="Corge grault" data-id="458" class="wp-image-458" /></a></figure></li></ul></figure>
<!-- /wp:gallery -->

<!-- wp:paragraph -->
<p>Aenean massa cum sociis natoque penatibus.</p>
<!-- /wp:paragraph -->';
    $standard_post_v05['yaml_assertions']['slug']['value'] = 'standard-post-entry-v05';
    $standard_post_v05['yaml_assertions']['content'] = [
        'field' => 'content',
        'value' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Lorem ipsum dolor sit amet consectetuer.',
                    ],
                ],
            ],
            [
                'type' => 'set',
                'attrs' => [
                    'id' => '#rowId(8)',
                    'values' => [
                        'type' => 'gallery',
                        'heading' => 'Image Gallery',
                        'images' => [
                            'default/2020-05-fpo_200.jpg',
                            'default/2020-05-fpo_100.jpg',
                            'default/2020-05-fpo_400.jpg',
                        ],
                        'more_query' => false,
                    ],
                ],
            ],
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
        'type' => 'array',
    ];

    // Gallery shortcode block
    $standard_post_v06 = $standard_post_v01;
    $standard_post_v06['base_test_name'] = 'standard post v06 (gallery shortcode)';
    $standard_post_v06['xml_parameters']['entryVariantType'] = 'standard_v06';
    $standard_post_v06['xml_parameters']['contentEncoded'] = '<!-- wp:paragraph -->
<p>Lorem ipsum dolor sit amet consectetuer.</p>
<!-- /wp:paragraph -->

<!-- wp:shortcode -->
[gallery ids="456,457,458"]
<!-- /wp:shortcode -->

<!-- wp:paragraph -->
<p>Aenean massa cum sociis natoque penatibus.</p>
<!-- /wp:paragraph -->';
    $standard_post_v06['yaml_assertions']['slug']['value'] = 'standard-post-entry-v06';
    $standard_post_v06['yaml_assertions']['content'] = [
        'field' => 'content',
        'value' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Lorem ipsum dolor sit amet consectetuer.',
                    ],
                ],
            ],
            [
                'type' => 'set',
                'attrs' => [
                    'id' => '#rowId(8)',
                    'values' => [
                        'type' => 'gallery',
                        'heading' => 'Image Gallery',
                        'images' => [
                            'default/2020-05-fpo_200.jpg',
                            'default/2020-05-fpo_100.jpg',
                            'default/2020-05-fpo_400.jpg',
                        ],
                        'more_query' => false,
                    ],
                ],
            ],
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
        'type' => 'array',
    ];

    // UAMS Gallery ACF block
    $standard_post_v07 = $standard_post_v01;
    $standard_post_v07['base_test_name'] = 'standard post v07 (uams gallery acf block)';
    $standard_post_v07['xml_parameters']['entryVariantType'] = 'standard_v07';
    $standard_post_v07['xml_parameters']['contentEncoded'] = '<!-- wp:paragraph -->
<p>Lorem ipsum dolor sit amet consectetuer.</p>
<!-- /wp:paragraph -->

<!-- wp:acf/uams-gallery {"name":"acf/uams-gallery","data":{"gallery_heading":"Foo Bar Baz","_gallery_heading":"field_gallery_heading_b","gallery_hide_heading":"0","_gallery_hide_heading":"field_gallery_hide_heading_b","gallery_description":"Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor.\r\n\r\nAenean massa. Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.","_gallery_description":"field_gallery_description_b","gallery_columns":"3","_gallery_columns":"field_gallery_columns_b","gallery_images":["456","457","458"],"_gallery_images":"field_gallery_images_b","gallery_crop":"1.3333","_gallery_crop":"field_gallery_crop_b","gallery_background_color":"bg-auto","_gallery_background_color":"field_gallery_background_color_b","gallery_modal":"1","_gallery_modal":"field_gallery_modal_b","gallery_more":"1","_gallery_more":"field_gallery_more_b","gallery_more_text":"Would you like to see more photos like this?","_gallery_more_text":"field_gallery_more_text_b","gallery_more_button_text":"View More Photos","_gallery_more_button_text":"field_gallery_more_button_text_b","gallery_more_button_url":{"title":"","url":"https://statamic.dev/","target":"_blank"},"_gallery_more_button_url":"field_gallery_more_button_url_b","gallery_more_button_description":"View More Photos Like This","_gallery_more_button_description":"field_gallery_more_button_description_b","geo_valid":"false","_geo_valid":"field_gallery_geo_valid_b"},"align":"full","mode":"auto"} /-->

<!-- wp:paragraph -->
<p>Aenean massa cum sociis natoque penatibus.</p>
<!-- /wp:paragraph -->';
    $standard_post_v07['yaml_assertions']['slug']['value'] = 'standard-post-entry-v07';
    $standard_post_v07['yaml_assertions']['content'] = [
        'field' => 'content',
        'value' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Lorem ipsum dolor sit amet consectetuer.',
                    ],
                ],
            ],
            [
                'type' => 'set',
                'attrs' => [
                    'id' => '#rowId(8)',
                    'values' => [
                        'type' => 'gallery',
                        'heading' => 'Foo Bar Baz',
                        'images' => [
                            'default/2020-05-fpo_200.jpg',
                            'default/2020-05-fpo_100.jpg',
                            'default/2020-05-fpo_400.jpg',
                        ],
                        'more_query' => true,
                        'description' => [
                            [
                                'type' => 'paragraph',
                                'content' => [
                                    [
                                        'type' => 'text',
                                        'text' => 'Lorem ipsum dolor sit amet, consectetuer adipiscing elit. Aenean commodo ligula eget dolor.',
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
                        ],
                        'more_heading' => 'Would you like to see more photos like this?',
                        'more_button_text' => 'View More Photos',
                        'more_button_url' => 'https://statamic.dev/',
                        'more_button_target' => true,
                        'more_button_link_description' => 'View More Photos Like This',
                    ],
                ],
            ],
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
        'type' => 'array',
    ];

    // Featured image block
    $standard_post_v08 = $standard_post_v01;
    $standard_post_v08['base_test_name'] = 'standard post v08 (featured image)';
    $standard_post_v08['xml_parameters']['entryVariantType'] = 'standard_v08';
    $standard_post_v08['xml_parameters']['contentEncoded'] = '<!-- wp:paragraph -->
<p>Lorem ipsum dolor sit amet consectetuer.</p>
<!-- /wp:paragraph -->

<!-- wp:post-featured-image /-->

<!-- wp:paragraph -->
<p>Aenean massa cum sociis natoque penatibus.</p>
<!-- /wp:paragraph -->';
    $standard_post_v08['yaml_assertions']['slug']['value'] = 'standard-post-entry-v08';
    $standard_post_v08['yaml_assertions']['content'] = [
        'field' => 'content',
        'value' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Lorem ipsum dolor sit amet consectetuer.',
                    ],
                ],
            ],
            [
                'type' => 'set',
                'attrs' => [
                    'id' => '#rowId(8)',
                    'values' => [
                        'type' => 'image',
                        'image' => 'default/2020-05-fpo_200.jpg',
                        'align' => 'aligncenter',
                    ],
                ],
            ],
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
        'type' => 'array',
    ];

    // Preformatted block
    $standard_post_v09 = $standard_post_v01;
    $standard_post_v09['base_test_name'] = 'standard post v09 (preformatted block)';
    $standard_post_v09['xml_parameters']['entryVariantType'] = 'standard_v09';
    $standard_post_v09['xml_parameters']['contentEncoded'] = '<!-- wp:paragraph -->
<p>Lorem ipsum dolor sit amet consectetuer.</p>
<!-- /wp:paragraph -->

<!-- wp:preformatted -->
<pre class="wp-block-preformatted">You came alive at dawn, craving speed,
          eager to clock time and distance.
                Arms and legs pumping faster, ever faster,
            pressing through boundaries real or imagined,
            you relished each strident strike
           against the concrete maze of city streets.
</pre>
<!-- /wp:preformatted -->

<!-- wp:paragraph -->
<p>Aenean massa cum sociis natoque penatibus.</p>
<!-- /wp:paragraph -->';
    $standard_post_v09['yaml_assertions']['slug']['value'] = 'standard-post-entry-v09';
    $standard_post_v09['yaml_assertions']['content'] = [
        'field' => 'content',
        'value' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Lorem ipsum dolor sit amet consectetuer.',
                    ],
                ],
            ],
            [
                'type' => 'codeBlock',
                'attrs' => [
                    'language' => 'null',
                ],
                'content' => [
                    [
                        'type' => 'text',
                        'text' => <<<'EOT'
                                You came alive at dawn, craving speed,
                                          eager to clock time and distance.
                                                Arms and legs pumping faster, ever faster,
                                            pressing through boundaries real or imagined,
                                            you relished each strident strike
                                           against the concrete maze of city streets.
                                EOT
                    ],
                ],
            ],
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
        'type' => 'array',
    ];

    // wpautop content
    $news_release_v01 = postReplaceYamlCase(
        $base,
        [
            'base_test_name' => 'news release post',
            'xml_parameters' => [
                'postType' => 'post',
                'entryVariantType' => 'news_release_v01',
                'includeBoilerplate' => null,
                'newsReleasePdfUrl' => null,
            ],
            'yaml_assertions' => [
                'blueprint' => [
                    'value' => 'news_release',
                ],
                'content' => [
                    'field' => 'content',
                    'value' => [
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'LITTLE ROCK — Lorem ipsum dolor sit amet consectetuer.',
                                ],
                            ],
                        ],
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'Aenean commodo ligula eget dolor.',
                                ],
                            ],
                        ],
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
                    'type' => 'array',
                ],
                'slug' => [
                    'value' => 'news-release-post-entry-v01',
                ],
                // ---
                // The host regenerates this PDF on save and overwrites the field, so the
                // expectation is the GENERATED artefact, not the imported one (wordpress-importer-archive#611).
                //
                // `NewsReleasePdfGenerator` writes `{Y-m-d}_{slug}.pdf` at the container
                // ROOT — no `default/` prefix — as a single-element list. The old
                // expectation was stale on all three counts: shape, prefix and filename.
                // The date is the publish date in the Statamic *display* timezone, which
                // is deliberately not `app.timezone`; see that class's docblock.
                // ---
                'news release pdf' => [
                    'field' => 'news_release_pdf',
                    'value' => ['2024-06-12_news-release-post-entry-v01.pdf'],
                    'type' => 'array',
                ],
            ],
        ]
    );

    // The news release fixtures set include_boilerplate=1, so the importer maps
    // boilerplate_query to true (the base default is false for standard posts).
    $news_release_v01['yaml_assertions']['include boilerplate']['value'] = true;

    // Gutenberg content
    $news_release_v02 = $news_release_v01;
    $news_release_v02['base_test_name'] = 'news release post v02';
    $news_release_v02['xml_parameters']['entryVariantType'] = 'news_release_v02';
    $news_release_v02['xml_parameters']['contentEncoded'] = '<!-- wp:paragraph -->
<p>LITTLE ROCK — Lorem ipsum dolor sit amet consectetuer.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Aenean commodo ligula eget dolor.</p>
<!-- /wp:paragraph -->

<!-- wp:paragraph -->
<p>Aenean massa cum sociis natoque penatibus.</p>
<!-- /wp:paragraph -->';
    $news_release_v02['yaml_assertions']['content']['value'] = $news_release_v01['yaml_assertions']['content']['value'];
    $news_release_v02['yaml_assertions']['slug']['value'] = 'news-release-post-entry-v02';

    // The generated PDF name embeds the slug, so v02 cannot inherit v01's (wordpress-importer-archive#611)
    $news_release_v02['yaml_assertions']['news release pdf']['value'] = ['2024-06-12_news-release-post-entry-v02.pdf'];

    $audio_post_v01 = postReplaceYamlCase(
        $base,
        [
            'base_test_name' => 'audio post v01',
            'xml_parameters' => [
                'postType' => 'post',
                'entryVariantType' => 'audio_v01',
                'contentEncoded' => null,
                'postFormatNicename' => null,
                'postFormatValue' => null,
            ],
            'yaml_assertions' => [
                'blueprint' => [
                    'value' => 'audio',
                ],
                'intro' => [
                    'field' => 'intro',
                    'value' => [
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'Lorem ipsum dolor sit amet, ',
                                ],
                                [
                                    'type' => 'text',
                                    'marks' => [
                                        [
                                            'type' => 'link',
                                            'attrs' => [
                                                'href' => 'https://statamic.dev/',
                                                'target' => '_blank',
                                            ],
                                        ],
                                    ],
                                    'text' => 'consectetuer adipiscing elit',
                                ],
                                [
                                    'type' => 'text',
                                    'text' => '. Aenean commodo ligula eget dolor. Aenean massa.',
                                ],
                            ],
                        ],
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.',
                                ],
                            ],
                        ],
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.',
                                ],
                            ],
                        ],
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
                    'type' => 'array',
                ],
                'series' => [
                    'field' => 'series',
                    'value' => 'heres-to-your-health',
                    'type' => 'string',
                ],
                'audio segments' => [
                    'field' => 'segments',
                    'value' => [
                        [
                            'id' => '#rowId(8)',
                            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_foo-bar_123.mp3',
                            'preload' => 'metadata',
                            'loop' => false,
                            'type' => 'local_file',
                            'enabled' => true,
                        ],
                        [
                            'id' => '#rowId(8)',
                            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_baz-qux_123.mp3',
                            'preload' => 'metadata',
                            'loop' => false,
                            'type' => 'local_file',
                            'enabled' => true,
                        ],
                    ],
                    'type' => 'array',
                ],
                'slug' => [
                    'value' => 'audio-post-entry-v01',
                ],
            ],
        ]
    );
    unset($audio_post_v01['yaml_assertions']['post categories']);

    // Different spacing in the contentEncoded field, but same expected output
    $audio_post_v02 = postReplaceYamlCase(
        $audio_post_v01,
        [
            'base_test_name' => 'audio post v02',
            'xml_parameters' => [
                'entryVariantType' => 'audio_v02',
                'contentEncoded' => 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]
<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]
<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.',
            ],
            'yaml_assertions' => [
                'slug' => [
                    'value' => 'audio-post-entry-v02',
                ],
            ],
        ]
    );

    // Only one audio segment in the post
    $audio_post_v03 = $audio_post_v01;
    $audio_post_v03['base_test_name'] = 'audio post v03';
    $audio_post_v03['xml_parameters']['entryVariantType'] = 'audio_v03';
    $audio_post_v03['xml_parameters']['contentEncoded'] = '[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]
<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v03['yaml_assertions']['intro'] = [
        'field' => 'intro',
        'value' => [
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Donec pede justo, ',
                    ],
                    [
                        'type' => 'text',
                        'marks' => [
                            [
                                'type' => 'link',
                                'attrs' => [
                                    'href' => 'https://statamic.dev/',
                                    'target' => '_blank',
                                ],
                            ],
                        ],
                        'text' => 'fringilla vel',
                    ],
                    [
                        'type' => 'text',
                        'text' => ', aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.',
                    ],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.',
                    ],
                ],
            ],
            [
                'type' => 'paragraph',
                'content' => [
                    [
                        'type' => 'text',
                        'text' => 'Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.',
                    ],
                ],
            ],
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
        'type' => 'array',
    ];
    $audio_post_v03['yaml_assertions']['slug']['value'] = 'audio-post-entry-v03';
    $audio_post_v03['yaml_assertions']['audio segments'] = [
        'field' => 'segments',
        'value' => [
            [
                'id' => '#rowId(8)',
                'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_123.mp3',
                'preload' => 'metadata',
                'loop' => false,
                'type' => 'local_file',
                'enabled' => true,
            ],
        ],
        'type' => 'array',
    ];

    // Pattern A: "wpautop intro" + "repeated <h3> segments" + "wpautop outro"
    $audio_post_v04 = $audio_post_v01;
    $audio_post_v04['base_test_name'] = 'audio post v04 (pattern a)';
    $audio_post_v04['xml_parameters']['entryVariantType'] = 'audio_v04';
    $audio_post_v04['xml_parameters']['contentEncoded'] = 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v04['yaml_assertions']['slug']['value'] = 'audio-post-entry-v04';
    $audio_post_v04['yaml_assertions']['intro']['value'] = [
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Lorem ipsum dolor sit amet, ',
                ],
                [
                    'type' => 'text',
                    'marks' => [
                        [
                            'type' => 'link',
                            'attrs' => [
                                'href' => 'https://statamic.dev/',
                                'target' => '_blank',
                            ],
                        ],
                    ],
                    'text' => 'consectetuer adipiscing elit',
                ],
                [
                    'type' => 'text',
                    'text' => '. Aenean commodo ligula eget dolor. Aenean massa.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Aenean massa cum sociis natoque penatibus.',
                ],
            ],
        ],
    ];
    $audio_post_v04['yaml_assertions']['audio segments']['value'] = [
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_foo-bar_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_baz-qux_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
    ];

    // Pattern B: "repeated <h3> segments with <p> in accordion" + "wpautop outro"
    $audio_post_v05 = $audio_post_v01;
    $audio_post_v05['base_test_name'] = 'audio post v05 (pattern b)';
    $audio_post_v05['xml_parameters']['entryVariantType'] = 'audio_v05';
    $audio_post_v05['xml_parameters']['contentEncoded'] = '<h3>Foo Bar</h3>

[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

[accordion]
[section title="Transcript"]
<p>Lorem ipsum dolor sit amet consectetuer.</p>
[/section]
[/accordion]

<h3>Baz Qux</h3>

[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

[accordion]
[section title="Transcript"]
<p>Aenean commodo ligula eget dolor.</p>
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v05['yaml_assertions']['slug']['value'] = 'audio-post-entry-v05';
    $audio_post_v05['yaml_assertions']['intro']['value'] = [
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Aenean massa cum sociis natoque penatibus.',
                ],
            ],
        ],
    ];
    $audio_post_v05['yaml_assertions']['audio segments']['value'] = [
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_foo-bar_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_baz-qux_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
    ];

    // Pattern C: "wpautop intro" + "repeated <h3> segments with segment intro" + "wpautop outro"
    $audio_post_v06 = $audio_post_v01;
    $audio_post_v06['base_test_name'] = 'audio post v06 (pattern c)';
    $audio_post_v06['xml_parameters']['entryVariantType'] = 'audio_v06';
    $audio_post_v06['xml_parameters']['contentEncoded'] = 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v06['yaml_assertions']['slug']['value'] = 'audio-post-entry-v06';
    $audio_post_v06['yaml_assertions']['intro']['value'] = [
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Lorem ipsum dolor sit amet, ',
                ],
                [
                    'type' => 'text',
                    'marks' => [
                        [
                            'type' => 'link',
                            'attrs' => [
                                'href' => 'https://statamic.dev/',
                                'target' => '_blank',
                            ],
                        ],
                    ],
                    'text' => 'consectetuer adipiscing elit',
                ],
                [
                    'type' => 'text',
                    'text' => '. Aenean commodo ligula eget dolor. Aenean massa.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Aenean massa cum sociis natoque penatibus.',
                ],
            ],
        ],
    ];
    $audio_post_v06['yaml_assertions']['audio segments']['value'] = [
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_foo-bar_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_baz-qux_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
    ];

    // Pattern D: "intro with <p>" + "repeated <h3> segments" + "outro with <p>"
    $audio_post_v07 = $audio_post_v01;
    $audio_post_v07['base_test_name'] = 'audio post v07 (pattern d)';
    $audio_post_v07['xml_parameters']['entryVariantType'] = 'audio_v07';
    $audio_post_v07['xml_parameters']['contentEncoded'] = '<p>Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.</p>
<p>Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>
<p>Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.</p>
<h2>Broadcasts</h2>
<h3>Foo Bar</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
<p>Aenean massa cum sociis natoque penatibus.</p>';
    $audio_post_v07['yaml_assertions']['slug']['value'] = 'audio-post-entry-v07';
    $audio_post_v07['yaml_assertions']['intro']['value'] = [
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Lorem ipsum dolor sit amet, ',
                ],
                [
                    'type' => 'text',
                    'marks' => [
                        [
                            'type' => 'link',
                            'attrs' => [
                                'href' => 'https://statamic.dev/',
                                'target' => '_blank',
                            ],
                        ],
                    ],
                    'text' => 'consectetuer adipiscing elit',
                ],
                [
                    'type' => 'text',
                    'text' => '. Aenean commodo ligula eget dolor. Aenean massa.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Aenean massa cum sociis natoque penatibus.',
                ],
            ],
        ],
    ];
    $audio_post_v07['yaml_assertions']['audio segments']['value'] = [
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_foo-bar_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_baz-qux_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
    ];

    // Pattern F: "single segment with no <h3> with segment intro" + "wpautop outro"
    $audio_post_v08 = $audio_post_v01;
    $audio_post_v08['base_test_name'] = 'audio post v08 (pattern f)';
    $audio_post_v08['xml_parameters']['entryVariantType'] = 'audio_v08';
    $audio_post_v08['xml_parameters']['contentEncoded'] = '[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v08['yaml_assertions']['slug']['value'] = 'audio-post-entry-v08';
    $audio_post_v08['yaml_assertions']['intro']['value'] = [
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Donec pede justo, ',
                ],
                [
                    'type' => 'text',
                    'marks' => [
                        [
                            'type' => 'link',
                            'attrs' => [
                                'href' => 'https://statamic.dev/',
                                'target' => '_blank',
                            ],
                        ],
                    ],
                    'text' => 'fringilla vel',
                ],
                [
                    'type' => 'text',
                    'text' => ', aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Aenean massa cum sociis natoque penatibus.',
                ],
            ],
        ],
    ];
    $audio_post_v08['yaml_assertions']['audio segments']['value'] = [
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
    ];

    // Pattern G: "single segment with no <h3>" + "outro with <p>"
    $audio_post_v09 = $audio_post_v01;
    $audio_post_v09['base_test_name'] = 'audio post v09 (pattern g)';
    $audio_post_v09['xml_parameters']['entryVariantType'] = 'audio_v09';
    $audio_post_v09['xml_parameters']['contentEncoded'] = '<p>Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.</p>
<p>Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>
<p>Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.</p>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]
<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>
<p>Aenean massa cum sociis natoque penatibus.</p>';
    $audio_post_v09['yaml_assertions']['slug']['value'] = 'audio-post-entry-v09';
    $audio_post_v09['yaml_assertions']['intro']['value'] = [
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Lorem ipsum dolor sit amet, ',
                ],
                [
                    'type' => 'text',
                    'marks' => [
                        [
                            'type' => 'link',
                            'attrs' => [
                                'href' => 'https://statamic.dev/',
                                'target' => '_blank',
                            ],
                        ],
                    ],
                    'text' => 'consectetuer adipiscing elit',
                ],
                [
                    'type' => 'text',
                    'text' => '. Aenean commodo ligula eget dolor. Aenean massa.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Aenean massa cum sociis natoque penatibus.',
                ],
            ],
        ],
    ];
    $audio_post_v09['yaml_assertions']['audio segments']['value'] = [
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
    ];

    // Pattern H: "single segment with no <h3>" + "wpautop outro"
    $audio_post_v10 = $audio_post_v01;
    $audio_post_v10['base_test_name'] = 'audio post v10 (pattern h)';
    $audio_post_v10['xml_parameters']['entryVariantType'] = 'audio_v10';
    $audio_post_v10['xml_parameters']['contentEncoded'] = '<p>Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.</p>
<p>Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.</p>
<p>Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.</p>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]
<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Aenean massa cum sociis natoque penatibus.';
    $audio_post_v10['yaml_assertions']['slug']['value'] = 'audio-post-entry-v10';
    $audio_post_v10['yaml_assertions']['intro']['value'] = [
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Lorem ipsum dolor sit amet, ',
                ],
                [
                    'type' => 'text',
                    'marks' => [
                        [
                            'type' => 'link',
                            'attrs' => [
                                'href' => 'https://statamic.dev/',
                                'target' => '_blank',
                            ],
                        ],
                    ],
                    'text' => 'consectetuer adipiscing elit',
                ],
                [
                    'type' => 'text',
                    'text' => '. Aenean commodo ligula eget dolor. Aenean massa.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.',
                ],
            ],
        ],
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Aenean massa cum sociis natoque penatibus.',
                ],
            ],
        ],
    ];
    $audio_post_v10['yaml_assertions']['audio segments']['value'] = [
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
    ];

    // Pattern I: "repeated <h3> segments with no segment intro or accordion" + "wpautop outro"
    $audio_post_v11 = $audio_post_v01;
    $audio_post_v11['base_test_name'] = 'audio post v11 (pattern i)';
    $audio_post_v11['xml_parameters']['entryVariantType'] = 'audio_v11';
    $audio_post_v11['xml_parameters']['contentEncoded'] = '<h3>Foo Bar</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Aenean massa cum sociis natoque penatibus.';
    $audio_post_v11['yaml_assertions']['slug']['value'] = 'audio-post-entry-v11';
    $audio_post_v11['yaml_assertions']['intro']['value'] = [
        [
            'type' => 'paragraph',
            'content' => [
                [
                    'type' => 'text',
                    'text' => 'Aenean massa cum sociis natoque penatibus.',
                ],
            ],
        ],
    ];
    $audio_post_v11['yaml_assertions']['audio segments']['value'] = [
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_foo-bar_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
        [
            'id' => '#rowId(8)',
            'file' => 'heres-to-your-health/2024-06-12_sample-blog-post/heres-to-your-health_2024-06-12_sample-blog-post_baz-qux_123.mp3',
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ],
    ];

    // Legacy (broken) MP3 URLs (//news.uams.edu//wp-content/uploads/)
    $audio_post_v12a = $audio_post_v01;
    $audio_post_v12a['base_test_name'] = 'audio post v12a';
    $audio_post_v12a['xml_parameters']['entryVariantType'] = 'audio_v12a';
    $audio_post_v12a['xml_parameters']['contentEncoded'] = 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uams.edu//wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="http://news.uams.edu//wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v12a['yaml_assertions']['slug']['value'] = 'audio-post-entry-v12a';

    // Legacy (broken) MP3 URLs (//news.uams.edu/pload/media/)
    $audio_post_v12b = $audio_post_v01;
    $audio_post_v12b['base_test_name'] = 'audio post v12b';
    $audio_post_v12b['xml_parameters']['entryVariantType'] = 'audio_v12b';
    $audio_post_v12b['xml_parameters']['contentEncoded'] = 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uams.edu/pload/media/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/pload/media/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v12b['yaml_assertions']['slug']['value'] = 'audio-post-entry-v12b';

    // Legacy (broken) MP3 URLs (//news.uams.edu/upload/media/)
    $audio_post_v12c = $audio_post_v01;
    $audio_post_v12c['base_test_name'] = 'audio post v12c';
    $audio_post_v12c['xml_parameters']['entryVariantType'] = 'audio_v12c';
    $audio_post_v12c['xml_parameters']['contentEncoded'] = 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uams.edu/upload/media/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/upload/media/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v12c['yaml_assertions']['slug']['value'] = 'audio-post-entry-v12c';

    // Legacy (broken) MP3 URLs (//news.uamsweb.com//wp-content/uploads/)
    $audio_post_v12d = $audio_post_v01;
    $audio_post_v12d['base_test_name'] = 'audio post v12d';
    $audio_post_v12d['xml_parameters']['entryVariantType'] = 'audio_v12d';
    $audio_post_v12d['xml_parameters']['contentEncoded'] = 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uamsweb.com//wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uamsweb.com//wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v12d['yaml_assertions']['slug']['value'] = 'audio-post-entry-v12d';

    // Legacy (broken) MP3 URLs (//news.uamsweb.com/pload/media/)
    $audio_post_v12e = $audio_post_v01;
    $audio_post_v12e['base_test_name'] = 'audio post v12e';
    $audio_post_v12e['xml_parameters']['entryVariantType'] = 'audio_v12e';
    $audio_post_v12e['xml_parameters']['contentEncoded'] = 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uamsweb.com/pload/media/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uamsweb.com/pload/media/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v12e['yaml_assertions']['slug']['value'] = 'audio-post-entry-v12e';

    // Legacy (broken) MP3 URLs (//news.uamsweb.com/upload/media/)
    $audio_post_v12f = $audio_post_v01;
    $audio_post_v12f['base_test_name'] = 'audio post v12f';
    $audio_post_v12f['xml_parameters']['entryVariantType'] = 'audio_v12f';
    $audio_post_v12f['xml_parameters']['contentEncoded'] = 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uamsweb.com/upload/media/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uamsweb.com/upload/media/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v12f['yaml_assertions']['slug']['value'] = 'audio-post-entry-v12f';

    // Legacy (broken) MP3 URLs (//news.uamsweb.com/wp-content/uploads/)
    $audio_post_v12g = $audio_post_v01;
    $audio_post_v12g['base_test_name'] = 'audio post v12g';
    $audio_post_v12g['xml_parameters']['entryVariantType'] = 'audio_v12g';
    $audio_post_v12g['xml_parameters']['contentEncoded'] = 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uamsweb.com/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uamsweb.com/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v12g['yaml_assertions']['slug']['value'] = 'audio-post-entry-v12g';

    // Legacy (broken) MP3 URLs (//uamshealth.com/wp-content/uploads/)
    $audio_post_v12h = $audio_post_v01;
    $audio_post_v12h['base_test_name'] = 'audio post v12h';
    $audio_post_v12h['xml_parameters']['entryVariantType'] = 'audio_v12h';
    $audio_post_v12h['xml_parameters']['contentEncoded'] = 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://uamshealth.com/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://uamshealth.com/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v12h['yaml_assertions']['slug']['value'] = 'audio-post-entry-v12h';

    // Legacy (broken) MP3 URLs (/wp-content/uploads/)
    $audio_post_v12i = $audio_post_v01;
    $audio_post_v12i['base_test_name'] = 'audio post v12i';
    $audio_post_v12i['xml_parameters']['entryVariantType'] = 'audio_v12i';
    $audio_post_v12i['xml_parameters']['contentEncoded'] = 'Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v12i['yaml_assertions']['slug']['value'] = 'audio-post-entry-v12i';

    // Image in contentEncoded
    $audio_post_v13 = $audio_post_v01;
    $audio_post_v13['base_test_name'] = 'audio post v13';
    $audio_post_v13['xml_parameters']['entryVariantType'] = 'audio_v13';
    $audio_post_v13['xml_parameters']['contentEncoded'] = '<img class="img_style_1 alignright" src="https://communications.uams.edu/web/wp-content/uploads/sites/2/2020/05/fpo_200.jpg" alt="Foo bar baz qux" />

Lorem ipsum dolor sit amet, <a href="https://statamic.dev/" target="_blank">consectetuer adipiscing elit</a>. Aenean commodo ligula eget dolor. Aenean massa.

Cum sociis natoque penatibus et magnis dis parturient montes, nascetur ridiculus mus.

Donec quam felis, ultricies nec, pellentesque eu, pretium quis, sem. Nulla consequat massa quis enim.
<h3>Foo Bar</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-08-25-Teen-Health-Once-a-year.mp3">Download this episode</a>

Donec pede justo, <a href="https://statamic.dev/" target="_blank">fringilla vel</a>, aliquet nec, vulputate eget, arcu. In enim justo, rhoncus ut, imperdiet a, venenatis vitae, justo.

Nullam dictum felis eu pede mollis pretium. Integer tincidunt. Cras dapibus. Vivamus elementum semper nisi.

Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu, consequat vitae, eleifend ac, enim.

[accordion]
[section title="Transcript"]
Lorem ipsum dolor sit amet consectetuer.
[/section]
[/accordion]
<h3>Baz Qux</h3>
[audio mp3="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3"][/audio]

<a href="https://news.uams.edu/wp-content/uploads/2025/09/HTYH-09-09-25-Teen-Health-Vaccinations.mp3">Download this episode</a>
[accordion]
[section title="Transcript"]
Aenean commodo ligula eget dolor.
[/section]
[/accordion]
Aenean massa cum sociis natoque penatibus.';
    $audio_post_v13['yaml_assertions']['slug']['value'] = 'audio-post-entry-v13';

    $video_post_v01 = postReplaceYamlCase(
        $base,
        [
            'base_test_name' => 'video post',
            'xml_parameters' => [
                'postType' => 'post',
                'entryVariantType' => 'video_v01',
                'contentEncoded' => null,
                'postFormatNicename' => null,
                'postFormatValue' => null,
            ],
            'yaml_assertions' => [
                'blueprint' => [
                    'value' => 'post',
                ],
                'content' => [
                    'field' => 'content',
                    'value' => [
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'Lorem ipsum dolor sit amet consectetuer.',
                                ],
                            ],
                        ],
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'Aenean commodo ligula eget dolor.',
                                ],
                            ],
                        ],
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
                    'type' => 'array',
                ],
                'slug' => [
                    'value' => 'video-post-entry-v01',
                ],
            ],
        ]
    );

    // Gutenberg content
    $video_post_v02 = $video_post_v01;
    $video_post_v02['base_test_name'] = 'video post v02';
    $video_post_v02['xml_parameters']['entryVariantType'] = 'video_v02';
    $video_post_v02['xml_parameters']['contentEncoded'] = $standard_post_v02['xml_parameters']['contentEncoded'];
    $video_post_v02['yaml_assertions']['slug']['value'] = 'video-post-entry-v02';

    $gallery_post_v01 = postReplaceYamlCase(
        $base,
        [
            'base_test_name' => 'gallery post',
            'xml_parameters' => [
                'postType' => 'post',
                'entryVariantType' => 'gallery_v01',
                'contentEncoded' => null,
                'postFormatNicename' => null,
                'postFormatValue' => null,
            ],
            'yaml_assertions' => [
                'blueprint' => [
                    'value' => 'post',
                ],
                'content' => [
                    'field' => 'content',
                    'value' => [
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'Lorem ipsum dolor sit amet consectetuer.',
                                ],
                            ],
                        ],
                        [
                            'type' => 'paragraph',
                            'content' => [
                                [
                                    'type' => 'text',
                                    'text' => 'Aenean commodo ligula eget dolor.',
                                ],
                            ],
                        ],
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
                    'type' => 'array',
                ],
                'slug' => [
                    'value' => 'gallery-post-entry-v01',
                ],
            ],
        ]
    );

    // Gutenberg content
    $gallery_post_v02 = $gallery_post_v01;
    $gallery_post_v02['base_test_name'] = 'gallery post v02';
    $gallery_post_v02['xml_parameters']['entryVariantType'] = 'gallery_v02';
    $gallery_post_v02['xml_parameters']['contentEncoded'] = $standard_post_v02['xml_parameters']['contentEncoded'];
    $gallery_post_v02['yaml_assertions']['slug']['value'] = 'gallery-post-entry-v02';

    $testCases = [];
    $entryVariantTypes = [
        $standard_post_v01,
        $standard_post_v02,
        $standard_post_v03,
        $standard_post_v04,
        $standard_post_v05,
        $standard_post_v06,
        $standard_post_v07,
        $standard_post_v08,
        $standard_post_v09,
        $news_release_v01,
        $news_release_v02,
        $audio_post_v01,
        $audio_post_v02,
        $audio_post_v03,
        $audio_post_v04,
        $audio_post_v05,
        $audio_post_v06,
        $audio_post_v07,
        $audio_post_v08,
        $audio_post_v09,
        $audio_post_v10,
        $audio_post_v11,
        $audio_post_v12a,
        $audio_post_v12b,
        $audio_post_v12c,
        $audio_post_v12d,
        $audio_post_v12e,
        $audio_post_v12f,
        $audio_post_v12g,
        $audio_post_v12h,
        $audio_post_v12i,
        $audio_post_v13,
        $video_post_v01,
        $video_post_v02,
        $gallery_post_v01,
        $gallery_post_v02,
    ];

    foreach ($entryVariantTypes as $entryVariantType) {
        $baseTestName = $entryVariantType['base_test_name'];

        // Collect every field assertion for this variant into a single case so the
        // import runs once per variant rather than once per field. Each field is
        // still asserted individually inside the test (wordpress-importer-archive#391).
        $fieldAssertions = [];

        foreach ($entryVariantType['yaml_assertions'] as $fieldName => $assertion) {
            $field = $assertion['field'] ?? null;
            $type = $assertion['type'] ?? null;
            if (
                ! \is_string($field)
                || ! \is_string($type)
            ) {
                throw new InvalidArgumentException(
                    "Invalid YAML assertion for '{$fieldName}' in '{$baseTestName}'."
                );
            }

            $fieldAssertions[] = [
                'field' => $field,
                'value' => $assertion['value'],
                'type' => $type,
            ];
        }

        $testCases[$baseTestName] = [
            'fieldAssertions' => $fieldAssertions,
            'postType' => $entryVariantType['xml_parameters']['postType'],
            'entryVariantType' => $entryVariantType['xml_parameters']['entryVariantType'],
            'contentEncoded' => $entryVariantType['xml_parameters']['contentEncoded'],
            'postFormatNicename' => $entryVariantType['xml_parameters']['postFormatNicename'],
            'postFormatValue' => $entryVariantType['xml_parameters']['postFormatValue'],
            'includeBoilerplate' => $entryVariantType['xml_parameters']['includeBoilerplate'],
            'newsReleasePdfUrl' => $entryVariantType['xml_parameters']['newsReleasePdfUrl'],
        ];
    }

    return $testCases;
}
