<?php

declare(strict_types=1);

use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\PostCategoryEntryResolver;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Tests that PostCategoryEntryResolver finds the entries a WordPress category
 * slug imported into.
 *
 * These tests create real `post_categories` entries, so they assert the lookup
 * actually matches rather than only that a miss is omitted — a query naming the
 * wrong collection or the wrong locale would satisfy every absence assertion in
 * `NewsBlockFieldsetShapeTest` while resolving nothing at all.
 *
 * `PreventsSavingStacheItemsToDisk` redirects the Stache at a scratch directory
 * that {@see AddonTestCase} deletes on teardown, so the created
 * entries never land in the working tree.
 *
 * @command  php vendor/bin/pest tests/Integration/PostCategoryEntryResolverTest.php
 */
beforeEach(function (): void {
    // The minimal Statamic boots with `default` alone; the news site is what
    // makes the cross-site behavior testable at all
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'news' => ['name' => 'News', 'locale' => 'en_US', 'url' => '/news/'],
    ]);

    // The two collections these tests resolve against, on two sites
    makeTestCollection('post_categories', ['default', 'news']);
    makeTestCollection('other_collection', ['default']);

    // Both statics are process-wide; start from a clean slate
    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    // Do not leak resolved ids or warnings into the next test
    PostCategoryEntryResolver::clear();
    ImportWarningManager::clearWarnings();
});

/**
 * Create a collection on the given sites.
 *
 * @param  string  $handle  The collection handle
 * @param  list<string>  $sites  The site handles the collection exists on
 */
function makeTestCollection(
    string $handle,
    array $sites
): void {
    $collection = CollectionFacade::make($handle);

    $collection->sites($sites);
    $collection->save();
}

/**
 * Create a `post_categories` entry and return its id.
 *
 * @param  string  $slug  The entry slug
 * @param  string  $site  The site handle
 * @param  string  $collection  The collection handle
 * @return string The created entry's id
 */
function makeCategoryEntry(
    string $slug,
    string $site = 'default',
    string $collection = 'post_categories'
): string {
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException("Could not make an entry for '$slug'.");
    }

    $entry->collection($collection);
    $entry->locale($site);
    $entry->slug($slug);
    $entry->set('title', $slug);
    $entry->published(true);
    $entry->save();

    $id = $entry->id();

    if (! \is_string($id) || $id === '') {
        throw new RuntimeException("The entry for '$slug' was saved without an id.");
    }

    return $id;
}

it('resolves a category slug to the entry it imported into', function (): void {
    // The entry the slug names
    $entryId = makeCategoryEntry('department-news');

    // The block's raw value is the bare slug
    expect(PostCategoryEntryResolver::referencesFor('department-news', 'default'))
        ->toBe([$entryId]);
});

it('resolves every slug in a comma-separated value, in source order', function (): void {
    // The corpus carries multi-category blocks such as `college-of-pharmacy, jobs`
    $pharmacy = makeCategoryEntry('college-of-pharmacy');
    $jobs = makeCategoryEntry('jobs');

    expect(PostCategoryEntryResolver::referencesFor('college-of-pharmacy, jobs', 'default'))
        ->toBe([$pharmacy, $jobs]);
});

it('keeps the slugs that resolve when one of them does not', function (): void {
    // A partially-migrated selection still filters by what it can
    $jobs = makeCategoryEntry('jobs');

    expect(PostCategoryEntryResolver::referencesFor('never-imported, jobs', 'default'))
        ->toBe([$jobs]);
});

it('returns nothing when no slug resolves', function (): void {
    // The caller omits the field entirely rather than writing an empty picker
    expect(PostCategoryEntryResolver::referencesFor('never-imported', 'default'))
        ->toBe([]);
});

it('resolves only the placement site, never another site that shares the slug', function (): void {
    // Both sites own an entry for this slug, with two different ids
    $newsId = makeCategoryEntry('research', 'news');
    $defaultId = makeCategoryEntry('research', 'default');

    // Both ids now survive the host's read, so this is a preference rather than a
    // constraint: the local entry is the one the block means, and resolving the
    // syndicate instead would change an already-correct import's answer (wordpress-importer-archive#628)
    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))
        ->toBe([$defaultId])
        ->not->toContain($newsId);
});

it('does not match an entry with the same slug in another collection', function (): void {
    // A post or page sharing the slug is not a category
    makeCategoryEntry('research', 'default', 'other_collection');

    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))
        ->toBe([]);
});

it('returns no duplicate ids when a slug is repeated across the value', function (): void {
    // The picker stores each reference once
    $entryId = makeCategoryEntry('research');

    expect(PostCategoryEntryResolver::referencesFor('research, research', 'default'))
        ->toBe([$entryId]);
});

it('reports an unresolved slug rather than dropping the filter in silence', function (): void {
    // The host reads an absent `category` as "no filter", so a lost slug widens
    // the block from one category to every news post
    expect(PostCategoryEntryResolver::referencesFor('never-imported', 'default'))
        ->toBe([]);

    $warnings = ImportWarningManager::getWarnings(
        'content',
        'unresolvable_news_category'
    );

    expect($warnings)->toHaveCount(1);

    $warning = $warnings[0] ?? null;

    expect($warning)->toBeArray();

    $slugs = \is_array($warning) ? ($warning['category_slugs'] ?? null) : null;
    $effect = \is_array($warning) ? ($warning['effect'] ?? null) : null;

    expect($slugs)->toBe('never-imported')
        ->and($effect)->toContain('every news post');
});

it('matches a differently-cased value without any normalization', function (): void {
    // The corpus's two `News` values resolve here, and they resolve because the
    // Stache slug query is case-insensitive — NOT because of the slugify
    // fallback below. Pinned deliberately: a reading of the corpus that compares
    // strings rather than running the query concludes these are unresolvable and
    // sends someone to write a case fix that is already there.
    $entryId = makeCategoryEntry('news');

    expect(PostCategoryEntryResolver::referencesFor('News', 'default'))
        ->toBe([$entryId]);
});

it('resolves a name whose punctuation differs from its slug', function (): void {
    // This is what the slugify fallback is actually for. Case the query handles;
    // a space against a hyphen it cannot, at any casing.
    $entryId = makeCategoryEntry('faculty-news');

    expect(PostCategoryEntryResolver::referencesFor('Faculty News', 'default'))
        ->toBe([$entryId]);
});

it('prefers the entry whose slug matches exactly', function (): void {
    // A site can hold both forms. The stored value is a slug first and a name
    // second, so an exact hit must win rather than be normalized past.
    $exact = makeCategoryEntry('faculty news');
    makeCategoryEntry('faculty-news');

    expect(PostCategoryEntryResolver::referencesFor('faculty news', 'default'))
        ->toBe([$exact]);
});

it('does not invent a resolution for a name nothing matches', function (): void {
    // Slugifying is normalization, not a search: `Some Category` becomes
    // `some-category` and still has to exist.
    makeCategoryEntry('unrelated');

    expect(PostCategoryEntryResolver::referencesFor('Some Category', 'default'))
        ->toBe([]);
});

it('reports the operator-facing slug the block actually carried', function (): void {
    // The warning has to name what an editor will find in WordPress, not the
    // normalized form the importer tried second.
    expect(PostCategoryEntryResolver::referencesFor('Never Imported', 'default'))
        ->toBe([]);

    $warning = ImportWarningManager::getWarnings('content', 'unresolvable_news_category')[0] ?? null;
    $slugs = \is_array($warning) ? ($warning['category_slugs'] ?? null) : null;

    expect($slugs)->toBe('Never Imported');
});

it('no longer tells an operator to re-run an import that cannot help', function (): void {
    // The page pass now creates the categories before parsing any block, so a
    // miss means the slug belongs to another site's export — a re-run changes
    // nothing, and the old advice sent operators to do exactly that (wordpress-importer-archive#624/wordpress-importer-archive#628).
    expect(PostCategoryEntryResolver::referencesFor('never-imported', 'default'))
        ->toBe([]);

    $warning = ImportWarningManager::getWarnings('content', 'unresolvable_news_category')[0] ?? null;
    $reason = \is_array($warning) ? ($warning['reason'] ?? null) : null;

    expect($reason)->toBeString()
        ->and($reason)->toContain('re-run will NOT help')
        ->and($reason)->toContain('628');
});

it('names every unresolved slug when only some of them resolve', function (): void {
    // A partially-migrated selection still narrows the block, so the warning
    // must say so rather than claim the filter was lost
    makeCategoryEntry('jobs');

    expect(PostCategoryEntryResolver::referencesFor('never-imported, jobs, also-missing', 'default'))
        ->toHaveCount(1);

    $warnings = ImportWarningManager::getWarnings(
        'content',
        'unresolvable_news_category'
    );

    $warning = $warnings[0] ?? null;
    $slugs = \is_array($warning) ? ($warning['category_slugs'] ?? null) : null;
    $effect = \is_array($warning) ? ($warning['effect'] ?? null) : null;

    expect($slugs)->toBe('never-imported, also-missing')
        ->and($effect)->toContain('kept its other categories');
});

it('records no warning when every slug resolves', function (): void {
    // A fully-resolved selection is not worth an operator's attention
    makeCategoryEntry('research');

    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))
        ->toHaveCount(1);

    expect(ImportWarningManager::getWarnings(
        'content',
        'unresolvable_news_category'
    ))->toBe([]);
});

it('re-queries a slug that missed, so a later pass can still resolve it', function (): void {
    // The term pass that creates category entries belongs to the `post` import,
    // which runs after `page` — so an absent category can appear mid-run
    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))
        ->toBe([]);

    $entryId = makeCategoryEntry('research');

    // A cached miss would pin the earlier answer for the rest of the run
    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))
        ->toBe([$entryId]);
});

it('forgets resolved ids when the run is cleared', function (): void {
    // Memoized hits must not survive into the next import run, where the same
    // slug can belong to a different entry
    $entryId = makeCategoryEntry('research');

    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))
        ->toBe([$entryId]);

    // Drop the entry the id points at, then reset — a live cache would keep
    // handing back the dead id instead of reporting the miss
    $entry = EntryFacade::find($entryId);

    if (! $entry instanceof Entry) {
        throw new RuntimeException("The entry '$entryId' could not be re-read for deletion.");
    }

    $entry->delete();

    PostCategoryEntryResolver::clear();

    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))
        ->toBe([]);
});

// --- cross-site resolution (wordpress-importer-archive#628) -------------------------------------------
//
// `uams-statamic-archive#2165` put `select_across_sites: true` on the block's `category`
// field, so an id from the syndicating site now survives the host's read instead
// of being dropped. These pin the behavior that change made possible, and the
// preference order that keeps an already-correct import resolving as it did.

it('resolves a category that exists only on the syndicating site', function (): void {
    // The case no import order could reach before: the block sits on one site and
    // filters posts syndicated from `news`, where the category actually lives
    $newsEntry = makeCategoryEntry('research', 'news');

    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))
        ->toBe([$newsEntry]);
});

it('prefers the placement site over the syndicating site when both carry the slug', function (): void {
    // Preferring the syndicate would silently change which entry an import that
    // was already correct resolves to, which is a regression dressed as a feature
    $local = makeCategoryEntry('research', 'default');
    makeCategoryEntry('research', 'news');

    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))
        ->toBe([$local]);
});

it('records no warning once a cross-site slug resolves', function (): void {
    // The warning existed to stop an operator re-running an import that could not
    // help; a slug that now resolves must stop producing it
    makeCategoryEntry('research', 'news');

    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))
        ->toHaveCount(1);

    expect(ImportWarningManager::getWarnings(
        'content',
        'unresolvable_news_category'
    ))->toBe([]);
});

it('still warns when neither the placement nor the syndicating site has the slug', function (): void {
    // The fallback widens where the resolver looks; it must not make a genuine
    // miss silent
    expect(PostCategoryEntryResolver::referencesFor('never-imported', 'default'))
        ->toBe([]);

    $warnings = ImportWarningManager::getWarnings('content', 'unresolvable_news_category');

    expect($warnings)->toHaveCount(1);

    $warning = $warnings[0] ?? null;
    $reason = \is_array($warning) ? ($warning['reason'] ?? '') : '';

    // The operator is told both sites were searched, so "re-run it" is visibly
    // not the remedy
    expect($reason)->toContain('news');
});

it('resolves a mixed value where one slug is local and the other syndicated', function (): void {
    // A single block can name both, and source order is the picker's order
    $local = makeCategoryEntry('jobs', 'default');
    $syndicated = makeCategoryEntry('research', 'news');

    expect(PostCategoryEntryResolver::referencesFor('jobs, research', 'default'))
        ->toBe([$local, $syndicated]);
});

it('applies the slugified retry on the syndicating site too', function (): void {
    // The display-name fallback must not be a placement-site-only affordance, or
    // a cross-site `Faculty News` resolves where a local one would not
    $newsEntry = makeCategoryEntry('faculty-news', 'news');

    expect(PostCategoryEntryResolver::referencesFor('Faculty News', 'default'))
        ->toBe([$newsEntry]);
});

it('does not query the syndicating site twice when it is the placement site', function (): void {
    // A block placed on `news` itself resolves locally; the fallback is skipped
    // rather than repeating the same query
    $newsEntry = makeCategoryEntry('research', 'news');

    expect(PostCategoryEntryResolver::referencesFor('research', 'news'))
        ->toBe([$newsEntry]);
});

// --- #102: the warning in full, the dedup order and the cache -----------------------------

it('names the site, the slugs, the page, the effect and the reason in its warning', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/news-page/');
    makeCategoryEntry('research');

    PostCategoryEntryResolver::referencesFor('research, lost-one, lost-two', 'default');

    expect(ImportWarningManager::getWarnings('content', 'unresolvable_news_category'))->toBe([[
        'site' => 'default',
        'category_slugs' => 'lost-one, lost-two',
        'page_url' => 'https://example.test/news-page/',
        'effect' => 'The block kept its other categories; only the slugs named here were dropped.',
        'reason' => 'No `post_categories` entry exists for these slugs on this site or on the `news` site the block syndicates from. The page import creates the categories before it parses any block and the cross-site case is now resolved against the syndicating site, so a re-run will NOT help: no export imported so far declares this slug on either site (see wordpress-importer-archive#628). Set the category on the block in the Control Panel, or import an export that declares it.',
    ]]);

    ProcessingContext::setWordPressPageLink(null);
});

it('keeps the slugs after two that name the same entry', function (): void {
    // The slug query matches case-insensitively, so these two name one entry
    $news = makeCategoryEntry('news');
    $events = makeCategoryEntry('events');

    expect(PostCategoryEntryResolver::referencesFor('news, News, events', 'default'))->toBe([$news, $events]);
});

it('remembers an entry it found for the rest of the run', function (): void {
    $entryId = makeCategoryEntry('research');

    PostCategoryEntryResolver::referencesFor('research', 'default');

    $entry = EntryFacade::find($entryId);
    assert($entry instanceof Entry);
    $entry->delete();

    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))->toBe([$entryId]);
});

it('remembers each site\'s entry for a slug apart', function (): void {
    $local = makeCategoryEntry('research');
    $syndicated = makeCategoryEntry('research', 'news');

    expect(PostCategoryEntryResolver::referencesFor('research', 'default'))->toBe([$local])
        ->and(PostCategoryEntryResolver::referencesFor('research', 'news'))->toBe([$syndicated]);
});
