<?php

declare(strict_types=1);

use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Navigation\TaxonomyNavigationResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Tests that TaxonomyNavigationResolver finds the entry a WordPress taxonomy
 * navigation item should link to, and names the cause when there is none.
 *
 * Real entries are created rather than mocked, because the fallback's whole
 * point is that it reads the *saved* content when the in-process registry is
 * cold — a test that stubbed the query would assert nothing about the case the
 * fallback exists for. `PreventsSavingStacheItemsToDisk` redirects the Stache
 * at a scratch directory {@see AddonTestCase} deletes on teardown, so nothing
 * lands in the working tree.
 *
 * @command  php vendor/bin/pest tests/Integration/TaxonomyNavigationResolverTest.php
 */
beforeEach(function (): void {
    // A second site is what makes the site-scoping behavior testable at all.
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'news' => ['name' => 'News', 'locale' => 'en_US', 'url' => '/news/'],
    ]);

    // `post_tags` joined `post_categories` as a collection in wordpress-importer-archive#1220.
    foreach (['post_categories', 'post_tags'] as $handle) {
        $collection = CollectionFacade::make($handle);
        $collection->sites(['default', 'news']);
        $collection->save();
    }

    // Both registries are process-wide statics; start from a clean slate.
    TermDestinationRegistry::clear();
    KbLinkRegistry::clear();
});

afterEach(function (): void {
    // Do not leak destinations into the next test.
    TermDestinationRegistry::clear();
    KbLinkRegistry::clear();
});

/**
 * Create a `post_categories` entry, or one in another collection, and return its id.
 *
 * @param  string  $slug  The entry slug, which is the raw WordPress term slug
 * @param  string  $site  The site handle the entry belongs to
 * @param  string  $collection  The collection handle
 * @return string The created entry's id
 */
function navCategoryEntry(
    string $slug,
    string $site = 'default',
    string $collection = 'post_categories'
): string {
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException("Could not make an entry for '{$slug}'.");
    }

    $entry->collection($collection);
    $entry->locale($site);
    $entry->slug($slug);
    $entry->data(['title' => $slug]);
    $entry->save();

    $id = $entry->id();

    if (! \is_string($id) || $id === '') {
        throw new RuntimeException("Entry '{$slug}' saved without an id.");
    }

    return $id;
}

/**
 * Build a resolver over a WXR fixture written to a temporary file.
 *
 * The `category` taxonomy is declared as `<wp:category>` and everything else as
 * `<wp:term>`, which is exactly how WordPress serializes them — the fixture
 * would not exercise the real parsing paths otherwise.
 *
 * @return TaxonomyNavigationResolver A resolver primed from the fixture
 */
function navResolverFromFixture(): TaxonomyNavigationResolver
{
    $fixture = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <wp:category>
                <wp:term_id>19</wp:term_id>
                <wp:category_nicename><![CDATA[publications]]></wp:category_nicename>
                <wp:category_parent><![CDATA[]]></wp:category_parent>
                <wp:cat_name><![CDATA[Publications]]></wp:cat_name>
            </wp:category>
            <wp:category>
                <wp:term_id>11</wp:term_id>
                <wp:category_nicename><![CDATA[good-catch-archives]]></wp:category_nicename>
                <wp:category_parent><![CDATA[]]></wp:category_parent>
                <wp:cat_name><![CDATA[Good Catch Archives]]></wp:cat_name>
            </wp:category>
            <wp:tag>
                <wp:term_id>8</wp:term_id>
                <wp:tag_slug><![CDATA[annual-report]]></wp:tag_slug>
                <wp:tag_name><![CDATA[Annual Report]]></wp:tag_name>
            </wp:tag>
            <wp:term>
                <wp:term_id>42</wp:term_id>
                <wp:term_taxonomy><![CDATA[advert_category]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[for-sale]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[For Sale]]></wp:term_name>
            </wp:term>
            <wp:term>
                <wp:term_id>77</wp:term_id>
                <wp:term_taxonomy><![CDATA[logo-list-category]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[partners]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[Partners]]></wp:term_name>
            </wp:term>
        </channel>
    </rss>
    XML;

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_nav_');
    file_put_contents($path, $fixture);

    $xmlParser = new XmlParser;
    $xmlParser->parse($path);

    $resolver = TaxonomyNavigationResolver::fromExport($xmlParser);

    unlink($path);

    return $resolver;
}

it('links the entry the term pass recorded for a structured taxonomy', function (): void {
    TermDestinationRegistry::registerCollectionEntry(
        'category',
        19,
        'post_categories',
        'recorded-entry-id'
    );

    $destination = TaxonomyNavigationResolver::registryOnly()->resolve('category', '19', 'default');

    expect($destination->entryId)->toBe('recorded-entry-id')
        ->and($destination->skipCode)->toBeNull();
});

it('skips a term the registry recorded against a flat taxonomy', function (): void {
    TermDestinationRegistry::registerTaxonomyTerm(
        'advert_category',
        42,
        'classified_categories',
        'default_for-sale'
    );

    $destination = TaxonomyNavigationResolver::registryOnly()->resolve('advert_category', '42', 'default');

    expect($destination->entryId)->toBeNull()
        ->and($destination->skipCode)->toBe('flat_destination')
        // The reason has to name the destination, or an operator cannot tell
        // which of the item's two identifiers was the problem.
        ->and($destination->skipReason)->toContain('classified_categories');
});

it('falls back to the saved entry when the registry never ran', function (): void {
    $entryId = navCategoryEntry('publications');

    // No registerCollectionEntry() call: this is the nav-only re-run.
    $destination = navResolverFromFixture()->resolve('category', '19', 'default');

    expect($destination->entryId)->toBe($entryId);
});

it('reads the category slug from `wp:category`, which is where WordPress puts it', function (): void {
    // `getTerms()` never returns the built-in category taxonomy, so a resolver
    // that only read `<wp:term>` would miss on nearly every real export.
    $entryId = navCategoryEntry('good-catch-archives');

    $destination = navResolverFromFixture()->resolve('category', '11', 'default');

    expect($destination->entryId)->toBe($entryId);
});

it('reads the tag slug from `wp:tag`, where WordPress puts a post tag', function (): void {
    // `post_tag` imports into a collection since wordpress-importer-archive#1220, and like categories it
    // is serialized under its own element, which `getTerms()` never returns.
    $entryId = navCategoryEntry('annual-report', 'default', 'post_tags');

    $destination = navResolverFromFixture()->resolve('post_tag', '8', 'default');

    expect($destination->entryId)->toBe($entryId)
        ->and($destination->skipCode)->toBeNull();
});

it('does not reach across sites for an entry', function (): void {
    // The entry exists, but on the wrong site. `post_categories` sets
    // `propagate: false`, so its id would be dropped on read in `default`.
    navCategoryEntry('publications', 'news');

    $destination = navResolverFromFixture()->resolve('category', '19', 'default');

    expect($destination->entryId)->toBeNull()
        ->and($destination->skipCode)->toBe('not_imported_into_site')
        ->and($destination->skipReason)->toContain('default');
});

it('resolves the same term on the site that does have it', function (): void {
    // The mirror of the test above: proves the miss came from the site scoping
    // and not from the lookup being broken outright.
    $entryId = navCategoryEntry('publications', 'news');

    $destination = navResolverFromFixture()->resolve('category', '19', 'news');

    expect($destination->entryId)->toBe($entryId);
});

it('names a term the export never declared', function (): void {
    navCategoryEntry('publications');

    $destination = navResolverFromFixture()->resolve('category', '9999', 'default');

    expect($destination->entryId)->toBeNull()
        ->and($destination->skipCode)->toBe('term_absent_from_export')
        ->and($destination->skipReason)->toContain('9999');
});

it('falls through when the registry recorded an empty reference', function (): void {
    // The term pass records a destination before the entry is saved, so a failed
    // save leaves an empty reference behind. Writing it would produce a branch
    // with neither an entry nor a URL — an unclickable label rather than a
    // visible failure — so the lookup has to continue past it.
    $entryId = navCategoryEntry('publications');

    TermDestinationRegistry::registerCollectionEntry(
        'category',
        19,
        'post_categories',
        ''
    );

    expect(navResolverFromFixture()->resolve('category', '19', 'default')->entryId)->toBe($entryId);
});

it('refuses a flat taxonomy on shape, with the registry cold', function (): void {
    // `advert_category` is in the fixture, so the fallback has a slug to work
    // with — it must still refuse on shape rather than hunt for an entry that
    // cannot exist. (`post_tag` stood here until it became a collection, wordpress-importer-archive#1220.)
    $destination = navResolverFromFixture()->resolve('advert_category', '42', 'default');

    expect($destination->entryId)->toBeNull()
        ->and($destination->skipCode)->toBe('flat_destination');
});

it('reports a taxonomy nothing maps', function (): void {
    // A handle WordPress will never emit. `seopress_404_cat` stood here until
    // wordpress-importer-archive#743 recorded it, at which point it stopped exercising the UNRECORDED arm
    // — both arms return this same skip code, so the test would have kept
    // passing while covering the other branch.
    $destination = navResolverFromFixture()->resolve('not-a-wordpress-taxonomy', '3', 'default');

    expect($destination->entryId)->toBeNull()
        ->and($destination->skipCode)->toBe('no_statamic_destination');
});

it('reports a recorded-but-unmapped taxonomy as having no destination', function (): void {
    // Recorded is not the same as flat. `tshowcase-taxonomy` sits in the
    // disposition table precisely because it has no destination, so reporting it
    // as `flat_destination` would send an operator hunting for a taxonomy that
    // nothing declares. The unrecorded case above cannot catch this: an
    // unrecorded handle takes the same arm whichever way the branch is written.
    $destination = navResolverFromFixture()->resolve('tshowcase-taxonomy', '101', 'default');

    expect($destination->entryId)->toBeNull()
        ->and($destination->skipCode)->toBe('no_statamic_destination');
});

it('links a kbtopic term through KbLinkRegistry', function (): void {
    KbLinkRegistry::registerKbtopicMappingByTermId('4', 'kb-entry-id');

    $destination = TaxonomyNavigationResolver::registryOnly()->resolve('kbtopic', '4', 'default');

    expect($destination->entryId)->toBe('kb-entry-id');
});

it('does not mistake an unregistered kbtopic term for a flat taxonomy', function (): void {
    // `kbtopic` is absent from TaxonomyDispositions by design, so a resolver
    // reasoning from that table alone would call it flat and say so — which
    // would send an operator looking for a taxonomy that does not exist.
    $destination = navResolverFromFixture()->resolve('kbtopic', '4', 'default');

    expect($destination->entryId)->toBeNull()
        ->and($destination->skipCode)->toBe('kbtopic_term_not_imported')
        ->and($destination->skipReason)->toContain('knowledge-base');
});

it('refuses a menu item that names no term', function (): void {
    $destination = navResolverFromFixture()->resolve('category', '', 'default');

    expect($destination->entryId)->toBeNull()
        ->and($destination->skipCode)->toBe('incomplete_menu_item');
});
