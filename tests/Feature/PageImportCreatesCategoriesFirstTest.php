<?php

declare(strict_types=1);

use Statamic\Entries\Entry as StatamicEntry;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\PageEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

/**
 * Proves the page import creates the post categories before it parses a page.
 *
 * This is `wordpress-importer-archive#624`'s whole point. A news block in a page's `page_builder` stores
 * its category as a `post_categories` entry id; those entries come from the term
 * pass, which belonged to `PostEntryCreator` alone. Since the operator selects
 * content types one at a time, a first import routinely parsed the page first
 * and wrote the block with no `category` key — which the host reads as "no
 * filter", rendering every news post instead of the one category WordPress
 * asked for.
 *
 * **This is host-bound because the code under test is.** `createEntries()`
 * builds a `ContentProcessor`, which news-up `App\Services\BardContentSanitizer`
 * — a host-app class the minimal Statamic band cannot resolve. So the package's
 * own gate structurally cannot reach this ticket's acceptance criterion, and
 * this file is the only thing that verifies it. The dedup contract the fix rests
 * on is separately pinned at the Integration tier by `TermPassDedupKeyTest`.
 *
 * The fixture declares a category and **no page items**, so the assertion is
 * about the term pass rather than page creation, and the run is fast.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/PageImportCreatesCategoriesFirstTest.php
 */

/**
 * The Statamic site this imports into.
 */
const PAGE_IMPORT_SITE = 'default';

/**
 * Category slug the fixture declares, namespaced so it cannot collide with content.
 */
const PAGE_IMPORT_SLUG = 'zz-page-import-probe';

beforeEach(function (): void {
    // Guard the live `content/` submodule against anything this test leaves behind (wordpress-importer-archive#570)
    $this->captureSubmoduleStates();

    BaseEntryCreator::clearTaxonomyCaches();
    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    // Remove the category this import created, however the test ended
    $query = EntryQueryHelper::query();
    $query->where('collection', 'post_categories');
    $query->where('locale', PAGE_IMPORT_SITE);
    $query->where('slug', PAGE_IMPORT_SLUG);

    $created = $query->first();

    if ($created instanceof StatamicEntry) {
        $created->delete();
    }

    BaseEntryCreator::clearTaxonomyCaches();
    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();

    foreach (pageImportFixtures() as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }

    $this->assertSubmodulesUnchanged();
});

/**
 * Track fixture paths so they can be removed however a test ends.
 *
 * @param  ?string  $path  A path to remember, or null to drain the list
 * @return list<string> The tracked paths
 */
function pageImportFixtures(
    ?string $path = null
): array {
    /** @var list<string> $paths */
    static $paths = [];

    if ($path === null) {
        $drained = $paths;
        $paths = [];

        return $drained;
    }

    $paths[] = $path;

    return $paths;
}

/**
 * Run the page import over an export that declares a category and no pages.
 *
 * @return string Everything the importer wrote to the console
 */
function runPageImport(): string
{
    $slug = PAGE_IMPORT_SLUG;

    $fixture = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <wp:base_blog_url>https://page-import-probe.uams.test</wp:base_blog_url>
            <wp:category>
                <wp:term_id>990024</wp:term_id>
                <wp:category_nicename><![CDATA[{$slug}]]></wp:category_nicename>
                <wp:category_parent><![CDATA[]]></wp:category_parent>
                <wp:cat_name><![CDATA[Page Import Probe]]></wp:cat_name>
            </wp:category>
        </channel>
    </rss>
    XML;

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_pageimport_');
    file_put_contents($path, $fixture);
    pageImportFixtures($path);

    $xmlParser = new XmlParser;
    $xmlParser->parse($path);

    $output = new BufferedOutput;

    $creator = new PageEntryCreator(app(EntryCreator::class));
    $creator->createEntries(
        PAGE_IMPORT_SITE,
        'pages',
        $xmlParser,
        app(AssetImporter::class),
        $output,
        app(MetadataMapper::class),
        app(ContentProcessor::class)
    );

    return $output->fetch();
}

it('creates the post categories during the page pass', function (): void {
    runPageImport();

    // The resolver is the consumer that matters: a news block asks it for an
    // entry id, and before wordpress-importer-archive#624 there was nothing on a first import to find.
    expect(PostCategoryEntryResolver::referencesFor(PAGE_IMPORT_SLUG, PAGE_IMPORT_SITE))
        ->toHaveCount(1);
});

it('announces the term pass from inside the page import', function (): void {
    // The announcement belongs to the term pass; its presence in a page import
    // is the behavior this ticket added. Remove the `initializeTerms()` call and
    // this goes red.
    expect(runPageImport())
        ->toContain('Importing taxonomy terms at beginning of import process');
});
