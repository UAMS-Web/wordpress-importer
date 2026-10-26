<?php

declare(strict_types=1);

use Statamic\Entries\Entry as StatamicEntry;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PageEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

/**
 * Proves an imported page lands with a populated `excerpt` summary (`wordpress-importer-archive#429`).
 *
 * `UAMS-Web/uams-statamic#1378` made `excerpt` a **required** field on
 * `pages/basic_page` and `pages/landing_page`, sourcing it from the WordPress
 * excerpt or, failing that, a SEO plugin's meta description. `PageEntryCreator`
 * wrote no `excerpt` at all, so every imported page landed violating that
 * requirement — the emptiness `wordpress-importer-archive#1378` exists to eliminate.
 *
 * **This is host-bound because the code under test is.** `createEntries()` builds
 * a `ContentProcessor`, which news-up `App\Services\BardContentSanitizer` — a
 * host-app class the minimal Statamic band cannot resolve. The precedence logic
 * itself is pinned at the Unit tier by `ExcerptResolverTest`, which the package's
 * own gate does run; what only this file can reach is that `PageEntryCreator`
 * actually writes the resolved value onto the entry.
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/PageExcerptImportTest.php
 */

/**
 * The Statamic site this imports into.
 */
const PAGE_EXCERPT_SITE = 'default';

/**
 * Page slug the fixture declares, namespaced so it cannot collide with content.
 */
const PAGE_EXCERPT_SLUG = 'zz-page-excerpt-probe';

beforeEach(function (): void {
    // Guard the live `content/` submodule against anything this test leaves behind (wordpress-importer-archive#570)
    $this->captureSubmoduleStates();

    BaseEntryCreator::clearTaxonomyCaches();
    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    // Remove the page this import created, however the test ended
    $query = EntryQueryHelper::query();
    $query->where('collection', 'pages');
    $query->where('locale', PAGE_EXCERPT_SITE);
    $query->where('slug', PAGE_EXCERPT_SLUG);

    $created = $query->first();

    if ($created instanceof StatamicEntry) {
        $created->delete();
    }

    BaseEntryCreator::clearTaxonomyCaches();
    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();

    foreach (pageExcerptFixtures() as $path) {
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
function pageExcerptFixtures(
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
 * Import a single page whose summary sources are supplied by the caller.
 *
 * @param  string  $excerptEncoded  The `excerpt:encoded` value, or '' to omit the element
 * @param  string  $seoMetaDesc  The `seo_meta_description` postmeta value, or '' to omit it
 * @return ?StatamicEntry The imported page, or null when nothing was created
 */
function importPageWithSummary(
    string $excerptEncoded,
    string $seoMetaDesc
): ?StatamicEntry {
    $slug = PAGE_EXCERPT_SLUG;

    // Omit each element entirely when empty, so the fixture models a real export
    // rather than one carrying empty tags
    $excerptElement = $excerptEncoded === ''
        ? ''
        : "<excerpt:encoded><![CDATA[{$excerptEncoded}]]></excerpt:encoded>";

    $seoElement = $seoMetaDesc === ''
        ? ''
        : "<wp:postmeta>
                    <wp:meta_key><![CDATA[seo_meta_description]]></wp:meta_key>
                    <wp:meta_value><![CDATA[{$seoMetaDesc}]]></wp:meta_value>
                </wp:postmeta>";

    $fixture = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0"
        xmlns:content="http://purl.org/rss/1.0/modules/content/"
        xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
        xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <wp:base_blog_url>https://page-excerpt-probe.uams.test</wp:base_blog_url>
            <item>
                <title><![CDATA[Page Excerpt Probe]]></title>
                <link>https://page-excerpt-probe.uams.test/{$slug}/</link>
                <wp:post_id>990031</wp:post_id>
                <wp:post_name><![CDATA[{$slug}]]></wp:post_name>
                <wp:status><![CDATA[publish]]></wp:status>
                <wp:post_type><![CDATA[page]]></wp:post_type>
                <wp:post_parent>0</wp:post_parent>
                <wp:menu_order>0</wp:menu_order>
                <wp:post_date><![CDATA[2026-01-01 00:00:00]]></wp:post_date>
                <content:encoded><![CDATA[<p>Body copy.</p>]]></content:encoded>
                {$excerptElement}
                {$seoElement}
            </item>
        </channel>
    </rss>
    XML;

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_pageexcerpt_');
    file_put_contents($path, $fixture);
    pageExcerptFixtures($path);

    $xmlParser = new XmlParser;
    $xmlParser->parse($path);

    $creator = new PageEntryCreator(app(EntryCreator::class));
    $creator->createEntries(
        PAGE_EXCERPT_SITE,
        'pages',
        $xmlParser,
        app(AssetImporter::class),
        new BufferedOutput,
        app(MetadataMapper::class),
        app(ContentProcessor::class)
    );

    $query = EntryQueryHelper::query();
    $query->where('collection', 'pages');
    $query->where('locale', PAGE_EXCERPT_SITE);
    $query->where('slug', PAGE_EXCERPT_SLUG);

    $found = $query->first();

    return $found instanceof StatamicEntry ? $found : null;
}

// ---
// The acceptance criterion wordpress-importer-archive#429 was filed for: a page whose *only* summary is a
// SEO meta description must still import with a populated `excerpt`. Before this
// change `PageEntryCreator` wrote no `excerpt` key at all, so this was null.
// ---
it('populates excerpt from the SEO meta description when the page has no excerpt', function (): void {
    $entry = importPageWithSummary('', 'Summary from the SEO plugin.');

    expect($entry)->not->toBeNull();
    expect($entry?->get('excerpt'))->toBe('Summary from the SEO plugin.');
});

it('prefers the page excerpt over the SEO meta description', function (): void {
    $entry = importPageWithSummary('Author-written summary.', 'Summary from the SEO plugin.');

    expect($entry?->get('excerpt'))->toBe('Author-written summary.');
});

it('strips markup from the excerpt it writes', function (): void {
    $entry = importPageWithSummary('<p>Author <em>written</em> summary.</p>', '');

    expect($entry?->get('excerpt'))->toBe('Author written summary.');
});

// ---
// A page with no summary source anywhere still cannot be given one. The field
// stays null rather than becoming an empty string, so the required-field
// violation is visible in the CP rather than silently satisfied by blank text.
// ---
it('leaves excerpt null when the page carries no summary at all', function (): void {
    $entry = importPageWithSummary('', '');

    expect($entry)->not->toBeNull();
    expect($entry?->get('excerpt'))->toBeNull();
});
