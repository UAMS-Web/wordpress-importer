<?php

declare(strict_types=1);

/**
 * Tests that `EntryFinder` finds an entry by id, slug or embedded WordPress id within
 * one collection, narrows to a site only when one is named, and, when asked for a
 * fresh read, forgets the cached Stache item it is about to look up.
 *
 * Real entries in two collections and two sites, so each filter is shown to exclude
 * something. The cache forgetting is observed through the Stache cache store: a
 * sentinel is written under the item key `forgetItem()` clears, and read back.
 *
 * @command  php vendor/bin/pest tests/Integration/EntryFinderTest.php
 */

use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Stache as StacheFacade;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryFinder;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Save an entry with a fixed id.
 */
function entryFinderEntry(string $collection, string $site, string $id, string $slug): void
{
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException('Could not make an entry.');
    }

    $entry->collection($collection);
    $entry->locale($site);
    $entry->id($id);
    $entry->slug($slug);
    $entry->data(['title' => $slug]);
    $entry->save();
}

/**
 * The cache key `forgetItem()` clears for an item of a collection's entry store.
 */
function entryFinderCacheKey(string $collection, string $key): string
{
    return "stache::items::entries::{$collection}::{$key}";
}

/**
 * Write a sentinel under each item key, so a later read shows which were forgotten.
 *
 * @param  list<array{string, string}>  $items  [collection, key] pairs
 */
function entryFinderPlantSentinels(array $items): void
{
    foreach ($items as [$collection, $key]) {
        StacheFacade::cacheStore()->forever(entryFinderCacheKey($collection, $key), 'sentinel');
    }
}

/**
 * Replace an entry's cached item with a copy whose title reads "stale", as an item
 * cached before a save elsewhere would.
 */
function entryFinderCacheStale(string $collection, string $id): void
{
    $entry = EntryFacade::find($id);

    if (! $entry instanceof Entry) {
        throw new RuntimeException("No entry {$id} to cache.");
    }

    $stale = clone $entry;
    $stale->set('title', 'stale');

    StacheFacade::cacheStore()->forever(entryFinderCacheKey($collection, $id), $stale);
}

/**
 * Whether the sentinel under an item key is still there.
 */
function entryFinderSentinelKept(string $collection, string $key): bool
{
    return StacheFacade::cacheStore()->get(entryFinderCacheKey($collection, $key)) === 'sentinel';
}

/**
 * The title of an entry as the Stache currently serves it.
 */
function entryFinderTitle(string $id): mixed
{
    $entry = EntryFacade::find($id);

    return $entry instanceof Entry ? $entry->get('title') : null;
}

/**
 * The id of a found entry, or null when none was found.
 */
function entryFinderId(?Entry $entry): ?string
{
    $id = $entry?->id();

    return \is_string($id) ? $id : null;
}

beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/'],
    ]);

    foreach (['pages', 'news'] as $handle) {
        $collection = CollectionFacade::make($handle);
        $collection->sites(['default', 'other']);
        $collection->save();
    }

    // Ids follow the importer's {slug}-{wordpress_id}-{random} shape
    entryFinderEntry('pages', 'default', 'about-12-abc', 'about');
    entryFinderEntry('pages', 'other', 'about-13-xyz', 'about');
    entryFinderEntry('pages', 'default', 'contact-112-mno', 'contact');
    entryFinderEntry('news', 'default', 'story-12-qrs', 'story');
});

it('finds an entry by id within its collection only', function (): void {
    expect(entryFinderId(EntryFinder::findEntryById('pages', 'about-12-abc')))->toBe('about-12-abc')
        ->and(EntryFinder::findEntryById('news', 'about-12-abc'))->toBeNull()
        ->and(EntryFinder::findEntryById('pages', 'missing'))->toBeNull();
});

it('narrows an id lookup to a named site, and to none when the site is empty', function (?string $site, ?string $found): void {
    expect(entryFinderId(EntryFinder::findEntryById('pages', 'about-13-xyz', site: $site)))->toBe($found);
})->with([
    'the entry\'s site' => ['other', 'about-13-xyz'],
    'another site' => ['default', null],
    'no site' => [null, 'about-13-xyz'],
    'an empty site' => ['', 'about-13-xyz'],
]);

it('finds an entry by slug within its collection and the named site', function (): void {
    expect(entryFinderId(EntryFinder::findEntryBySlug('pages', 'about', site: 'other')))->toBe('about-13-xyz')
        ->and(entryFinderId(EntryFinder::findEntryBySlug('pages', 'about', site: 'default')))->toBe('about-12-abc')
        ->and(EntryFinder::findEntryBySlug('news', 'about'))->toBeNull()
        ->and(EntryFinder::findEntryBySlug('pages', 'contact', site: 'other'))->toBeNull();
});

it('looks a slug up in every site when the site is empty', function (): void {
    // Both sites carry this slug, so only which collection and slug it has is pinned
    $entry = EntryFinder::findEntryBySlug('pages', 'about', site: '');

    expect($entry?->slug())->toBe('about')
        ->and($entry?->collectionHandle())->toBe('pages')
        ->and(EntryFinder::findEntryBySlug('pages', 'contact', site: ''))->not->toBeNull();
});

it('finds an entry by the WordPress id embedded in its id', function (): void {
    // 112 ends in 12 and story-12 shares the number in another collection; neither matches
    expect(entryFinderId(EntryFinder::findEntryByWordPressId('pages', '12')))->toBe('about-12-abc')
        ->and(entryFinderId(EntryFinder::findEntryByWordPressId('pages', '112')))->toBe('contact-112-mno')
        ->and(entryFinderId(EntryFinder::findEntryByWordPressId('news', '12')))->toBe('story-12-qrs')
        ->and(EntryFinder::findEntryByWordPressId('pages', '999'))->toBeNull();
});

it('treats a WordPress id as literal text, not a pattern', function (): void {
    // Unquoted, `1.` would match the `12` in about-12-abc
    expect(EntryFinder::findEntryByWordPressId('pages', '1.'))->toBeNull();
});

it('narrows a WordPress id lookup to a named site, and to none when the site is empty', function (?string $site, ?string $found): void {
    expect(entryFinderId(EntryFinder::findEntryByWordPressId('pages', '13', site: $site)))->toBe($found);
})->with([
    'the entry\'s site' => ['other', 'about-13-xyz'],
    'another site' => ['default', null],
    'no site' => [null, 'about-13-xyz'],
    'an empty site' => ['', 'about-13-xyz'],
]);

it('reads past a stale cached item by id only when a fresh read is asked for', function (): void {
    entryFinderCacheStale('pages', 'about-12-abc');

    $cached = EntryFinder::findEntryById('pages', 'about-12-abc')?->get('title');
    $fresh = EntryFinder::findEntryById('pages', 'about-12-abc', forceFresh: true)?->get('title');

    expect($cached)->toBe('stale')
        ->and($fresh)->toBe('about');
});

it('forgets the cache key named by the slug only when a fresh read is asked for', function (): void {
    // Characterization: items are cached by id, so the key a slug names holds no item
    // and a fresh slug lookup does not refresh the entry (#127)
    entryFinderPlantSentinels([['pages', 'about']]);

    EntryFinder::findEntryBySlug('pages', 'about');
    $keptWithoutFresh = entryFinderSentinelKept('pages', 'about');

    EntryFinder::findEntryBySlug('pages', 'about', forceFresh: true);

    expect($keptWithoutFresh)->toBeTrue()
        ->and(entryFinderSentinelKept('pages', 'about'))->toBeFalse();
});

it('still returns a stale cached item from a fresh slug lookup', function (): void {
    // Characterization of the same gap: the item cached under the entry's id survives
    entryFinderCacheStale('pages', 'about-12-abc');

    expect(EntryFinder::findEntryBySlug('pages', 'about', forceFresh: true, site: 'default')?->get('title'))
        ->toBe('stale');
});

it('forgets the cached item of each entry a fresh WordPress id lookup reads', function (): void {
    // The query has already loaded the stale copies, so the match it returns is one;
    // what the fresh flag changes is that the cache no longer holds them afterwards
    foreach (['about-12-abc', 'contact-112-mno'] as $id) {
        entryFinderCacheStale('pages', $id);
    }

    entryFinderCacheStale('news', 'story-12-qrs');

    EntryFinder::findEntryByWordPressId('pages', '999', forceFresh: true);

    expect(entryFinderTitle('about-12-abc'))->toBe('about')
        ->and(entryFinderTitle('contact-112-mno'))->toBe('contact')
        ->and(entryFinderTitle('story-12-qrs'))->toBe('stale');
});

it('keeps the cached items in a WordPress id lookup that is not fresh', function (): void {
    entryFinderCacheStale('pages', 'about-12-abc');

    EntryFinder::findEntryByWordPressId('pages', '999');

    expect(entryFinderTitle('about-12-abc'))->toBe('stale');
});
