<?php

declare(strict_types=1);

use Statamic\Contracts\Structures\Nav as NavContract;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Nav as NavFacade;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Navigation\TaxonomyNavigationResolver;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\Aether\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

/**
 * Checks the host-side assumptions the taxonomy navigation resolver rests on.
 *
 * `wordpress-importer-archive#12`'s coverage is at the Integration tier, where the `post_categories`
 * collection and the `primary_navigation` nav are both created by the test. That
 * proves the logic and leaves exactly two claims about the *real* app unproven,
 * and both are load-bearing:
 *
 *   1. `primary_navigation` is the handle the host actually uses. The importer
 *      hard-codes it and returns early when it finds nothing, so a rename would
 *      make every navigation import a silent no-op.
 *   2. A `post_categories` entry is findable by slug on its own site, under the
 *      real collection config. `post_categories` sets `propagate: false` and its
 *      per-site entries carry no `origin`, which is exactly the configuration
 *      the site-scoped fallback query is written for.
 *
 * **This file deliberately does not run a navigation import.** Doing so would
 * call `clearExistingNavigationItems()` against a real site's menu, and a run
 * interrupted between the wipe and the restore would leave that site with an
 * empty navigation. The tree-writing path is covered at the Integration tier
 * against the same Statamic structures, so the marginal value here does not
 * justify pointing a destructive write at real content.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/TaxonomyNavigationHostAssumptionsTest.php
 */

/**
 * The Statamic site these assertions run against.
 */
const TAXONOMY_NAV_SITE = 'default';

/**
 * Slug for the scratch category entry, namespaced so it cannot collide with content.
 */
const TAXONOMY_NAV_SLUG = 'zz-taxonomy-nav-probe';

/**
 * WordPress term id the scratch entry stands in for.
 */
const TAXONOMY_NAV_TERM_ID = '990019';

beforeEach(function (): void {
    // Guard the live `content/` submodule against anything this test leaves behind (wordpress-importer-archive#570)
    $this->captureSubmoduleStates();

    // The registry is a process-wide static shared with every other test here
    TermDestinationRegistry::clear();
});

afterEach(function (): void {
    // Remove the scratch entry however the test ended, before anything else
    // Statements rather than a chain: the builder's `where()` is untyped, so
    // chaining loses the typed receiver.
    $query = EntryQueryHelper::query();
    $query->where('collection', 'post_categories');
    $query->where('locale', TAXONOMY_NAV_SITE);
    $query->where('slug', TAXONOMY_NAV_SLUG);

    $existing = $query->first();

    if ($existing instanceof StatamicEntry) {
        $existing->delete();
    }

    TermDestinationRegistry::clear();

    $this->assertSubmodulesUnchanged();
});

/**
 * Create the scratch `post_categories` entry this file resolves against.
 *
 * A purpose-made entry rather than whichever category the query returns first:
 * per `tests/CLAUDE.md`, an ordinal sample asserts against Stache ordering, and
 * the `default` site currently holds no `post_categories` entries at all.
 *
 * @return string The created entry's id
 */
function taxonomyNavProbeEntry(): string
{
    $entry = EntryFacade::make();

    if (! $entry instanceof StatamicEntry) {
        throw new RuntimeException('Statamic did not return an entry to make.');
    }

    $entry->collection('post_categories');
    $entry->locale(TAXONOMY_NAV_SITE);
    $entry->slug(TAXONOMY_NAV_SLUG);
    $entry->data(['title' => 'Taxonomy Nav Probe']);
    $entry->save();

    $id = $entry->id();

    if (! \is_string($id) || $id === '') {
        throw new RuntimeException('The scratch category entry saved without an id.');
    }

    return $id;
}

/**
 * Build a resolver over a WXR fixture declaring the probe term.
 *
 * @return TaxonomyNavigationResolver A resolver primed from the fixture
 */
function taxonomyNavProbeResolver(): TaxonomyNavigationResolver
{
    $termId = TAXONOMY_NAV_TERM_ID;
    $slug = TAXONOMY_NAV_SLUG;

    $fixture = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <wp:category>
                <wp:term_id>{$termId}</wp:term_id>
                <wp:category_nicename><![CDATA[{$slug}]]></wp:category_nicename>
                <wp:category_parent><![CDATA[]]></wp:category_parent>
                <wp:cat_name><![CDATA[Taxonomy Nav Probe]]></wp:cat_name>
            </wp:category>
        </channel>
    </rss>
    XML;

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_navprobe_');
    file_put_contents($path, $fixture);

    $xmlParser = new XmlParser;
    $xmlParser->parse($path);

    $resolver = TaxonomyNavigationResolver::fromExport($xmlParser);

    unlink($path);

    return $resolver;
}

it('finds the navigation handle the importer hard-codes', function (): void {
    // `NavigationMenuImporter` looks this up and returns early when it is null,
    // so a rename in the host would make every navigation import a no-op that
    // still reports success.
    expect(NavFacade::findByHandle('primary_navigation'))->toBeInstanceOf(NavContract::class);
});

it('resolves a real `post_categories` entry by slug on its own site', function (): void {
    $entryId = taxonomyNavProbeEntry();

    $destination = taxonomyNavProbeResolver()->resolve(
        'category',
        TAXONOMY_NAV_TERM_ID,
        TAXONOMY_NAV_SITE
    );

    expect($destination->entryId)->toBe($entryId);
});

it('does not resolve that entry from another site', function (): void {
    taxonomyNavProbeEntry();

    // `post_categories` is `propagate: false` with no `origin` on its per-site
    // entries, so an id from `default` has no localization in `news` and the
    // host would drop it on read. The fallback must miss rather than write it.
    $destination = taxonomyNavProbeResolver()->resolve(
        'category',
        TAXONOMY_NAV_TERM_ID,
        'news'
    );

    expect($destination->entryId)->toBeNull()
        ->and($destination->skipCode)->toBe('not_imported_into_site');
});

it('exposes the resolver the navigation importer uses without an export', function (): void {
    // The registry-only resolver is what a caller gets before
    // `importNavigationMenu()` primes one; it must still answer rather than fatal.
    TermDestinationRegistry::registerCollectionEntry(
        'category',
        TAXONOMY_NAV_TERM_ID,
        'post_categories',
        'registry-recorded-id'
    );

    $destination = TaxonomyNavigationResolver::registryOnly()->resolve(
        'category',
        TAXONOMY_NAV_TERM_ID,
        TAXONOMY_NAV_SITE
    );

    expect($destination->entryId)->toBe('registry-recorded-id')
        ->and($destination->skipCode)->toBeNull();
});
