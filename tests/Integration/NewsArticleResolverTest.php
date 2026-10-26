<?php

declare(strict_types=1);

use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Stache\Query\EntryQueryBuilder;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\NewsArticleResolver;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Tests that NewsArticleResolver finds the `posts` entry a WordPress post became.
 *
 * These tests create real entries carrying `wordpress_post_id`, so a hit is
 * asserted as a hit rather than inferred from an absence — a query naming the
 * wrong collection, the wrong locale or the wrong key would satisfy every
 * "omits `article_id`" assertion in `NewsBlockFieldsetShapeTest` while resolving
 * nothing at all.
 *
 * `wordpress_post_id` is written by
 * {@see XpathHelper::extractMigrationRedirectMetadata()}
 * as an **int**, so the int-valued cases below are the shape the importer
 * actually produces; the string-valued one covers an entry edited in the Control
 * Panel, where the same key comes back as a string.
 *
 * `PreventsSavingStacheItemsToDisk` redirects the Stache at a scratch directory
 * that {@see AddonTestCase} deletes on teardown, so the created entries never
 * land in the working tree.
 *
 * @command  php vendor/bin/pest tests/Integration/NewsArticleResolverTest.php
 */
beforeEach(function (): void {
    // The news posts live on their own site, which is what makes the
    // site-scoping assertions below testable at all
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'news' => ['name' => 'News', 'locale' => 'en_US', 'url' => '/news/'],
    ]);

    // The collection the resolver searches, plus one it must not
    makeArticleCollection('posts', ['default', 'news']);
    makeArticleCollection('pages', ['default']);

    // Both statics are process-wide; start from a clean slate
    NewsArticleResolver::clearCache();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    // Do not leak resolved ids or warnings into the next test
    NewsArticleResolver::clearCache();
    ImportWarningManager::clearWarnings();
});

/**
 * Create a collection on the given sites.
 *
 * @param  string  $handle  The collection handle
 * @param  list<string>  $sites  The site handles the collection exists on
 */
function makeArticleCollection(
    string $handle,
    array $sites
): void {
    $collection = CollectionFacade::make($handle);

    $collection->sites($sites);
    $collection->save();
}

/**
 * Create an entry carrying a `wordpress_post_id` and return its id.
 *
 * Passing `$id` pins the entry id instead of taking a random one, which is what
 * makes the ambiguity cases below deterministic: entries come back in creation
 * order, so creating the *higher* id first means an unsorted result would answer
 * with it. Without that, a dropped `sort()` would go unnoticed whenever the
 * random ids happened to be created in ascending order — roughly half the time.
 *
 * @param  int|string  $wordPressPostId  The value to store under `wordpress_post_id`
 * @param  string  $site  The site handle
 * @param  string  $collection  The collection handle
 * @param  string  $slug  The entry slug
 * @param  string|null  $id  An explicit entry id, or null for a random one
 * @return string The created entry's id
 */
function makeArticleEntry(
    int|string $wordPressPostId,
    string $site = 'default',
    string $collection = 'posts',
    string $slug = 'an-article',
    ?string $id = null
): string {
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException("Could not make an entry for '$slug'.");
    }

    if ($id !== null) {
        $entry->id($id);
    }

    $entry->collection($collection);
    $entry->locale($site);
    $entry->slug($slug);
    $entry->set('title', $slug);
    $entry->set('wordpress_post_id', $wordPressPostId);
    $entry->published(true);
    $entry->save();

    $id = $entry->id();

    if (! \is_string($id) || $id === '') {
        throw new RuntimeException("The entry for '$slug' was saved without an id.");
    }

    return $id;
}

/**
 * The `ambiguous_wordpress_post_id` warnings recorded so far.
 *
 * @return list<array<mixed, mixed>> The matching warnings
 */
function ambiguousArticleWarnings(): array
{
    $matching = [];

    foreach (ImportWarningManager::getWarnings('content', 'ambiguous_wordpress_post_id') as $warning) {
        if (\is_array($warning)) {
            $matching[] = $warning;
        }
    }

    return $matching;
}

it('resolves a WordPress post id to the entry that post became', function (): void {
    // The importer writes an int; WordPress hands the block a string. The lookup
    // has to cross that gap or it resolves nothing on real imported content.
    $entryId = makeArticleEntry(163336);

    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBe($entryId);
});

it('matches a string-valued key, as a Control-Panel-edited entry carries it', function (): void {
    $entryId = makeArticleEntry('163336');

    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBe($entryId);
});

it('returns null when no entry carries the id', function (): void {
    makeArticleEntry(163336);

    expect(NewsArticleResolver::entryIdFor('999999', 'default'))->toBeNull();
});

it('returns null for an empty id without consulting the collection', function (): void {
    makeArticleEntry(163336);

    expect(NewsArticleResolver::entryIdFor('', 'default'))->toBeNull();
});

it('ignores an entry outside the posts collection', function (): void {
    // A `pages` entry carries `wordpress_post_id` too — the same
    // `extractMigrationRedirectMetadata()` call writes it — so an unscoped query
    // would answer a news lookup with a page.
    makeArticleEntry(163336, 'default', 'pages', 'a-page');

    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBeNull();
});

it('ignores an entry on another site', function (): void {
    makeArticleEntry(163336, 'news');

    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBeNull();
});

it('resolves against the site it is asked for', function (): void {
    $newsId = makeArticleEntry(163336, 'news');

    expect(NewsArticleResolver::entryIdFor('163336', 'news'))->toBe($newsId);
});

it('re-queries after a miss, so an entry imported later still resolves', function (): void {
    // The block is parsed during the `page` import and `page` runs before
    // `post`, so a miss must never be memoized.
    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBeNull();

    $entryId = makeArticleEntry(163336);

    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBe($entryId);
});

it('records no ambiguity warning for a single match', function (): void {
    makeArticleEntry(163336);

    NewsArticleResolver::entryIdFor('163336', 'default');

    expect(ambiguousArticleWarnings())->toBe([]);
});

it('picks the lowest entry id when two entries claim one WordPress id', function (): void {
    // The HIGHER id is created first on purpose. Entries come back in creation
    // order, so an unsorted result — a dropped `sort()`, not just a reversed one —
    // would answer `zzz…` here. Only the tie-break produces `aaa…`.
    makeArticleEntry(163336, 'default', 'posts', 'an-article', 'zzz-created-first');
    makeArticleEntry(163336, 'default', 'posts', 'a-duplicate', 'aaa-created-second');

    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBe('aaa-created-second');
});

it('warns that a WordPress id matched more than one entry', function (): void {
    makeArticleEntry(163336, 'default', 'posts', 'an-article', 'zzz-created-first');
    makeArticleEntry(163336, 'default', 'posts', 'a-duplicate', 'aaa-created-second');

    NewsArticleResolver::entryIdFor('163336', 'default');

    $warnings = ambiguousArticleWarnings();

    expect($warnings)->toHaveCount(1);
    expect($warnings[0]['wordpress_post_id'] ?? null)->toBe('163336');
    expect($warnings[0]['entry_ids'] ?? null)->toHaveCount(2);
});

it('still resolves after the cache is cleared', function (): void {
    // The *forgetting* is asserted by the import-session case below, which can
    // observe it because the entry is gone by then. This one covers the other
    // half: clearing must not poison the lookup, so the same id still resolves
    // from a fresh query.
    $entryId = makeArticleEntry(163336);

    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBe($entryId);

    NewsArticleResolver::clearCache();

    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBe($entryId);
});

it('treats a zero id as no selection rather than a lookup', function (): void {
    // `extractMigrationRedirectMetadata()` stamps only a non-zero numeric id, so
    // nothing can carry `0` — but an entry hand-edited in the Control Panel could,
    // and a `0` selection must not find it.
    makeArticleEntry(0, 'default', 'posts', 'a-zero-stamped-article');

    expect(NewsArticleResolver::entryIdFor('0', 'default'))->toBeNull();
});

it('treats a non-numeric id as no selection', function (): void {
    makeArticleEntry('not-a-number', 'default', 'posts', 'a-junk-stamped-article');

    expect(NewsArticleResolver::entryIdFor('not-a-number', 'default'))->toBeNull();
});

it('does not resolve a whitespace-padded id', function (): void {
    makeArticleEntry(163336);

    // The writer trims before stamping; the reader does not silently re-trim,
    // because a padded ACF value is malformed input rather than a selection.
    expect(NewsArticleResolver::entryIdFor(' 163336 ', 'default'))->toBeNull();
});

it('is reset by the import-session cache clear the entry creators call', function (): void {
    $entryId = makeArticleEntry(163336);

    // Memoize the hit
    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBe($entryId);

    $entry = EntryFacade::find($entryId);

    if (! $entry instanceof Entry) {
        throw new RuntimeException("The entry '$entryId' could not be read back.");
    }

    $entry->delete();

    // Still answered from the cache — which is what makes the clear observable
    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBe($entryId);

    BaseEntryCreator::clearTaxonomyCaches();

    // One import session's entry ids must not answer the next one's lookups
    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBeNull();
});

it('keys the memoized hit by site, not by WordPress id alone', function (): void {
    // Both lookups happen without an intervening clear, so a cache keyed on the
    // id alone would answer the second from the first site's entry.
    $defaultId = makeArticleEntry(163336, 'default', 'posts', 'a-default-article');
    $newsId = makeArticleEntry(163336, 'news', 'posts', 'a-news-article');

    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBe($defaultId);
    expect(NewsArticleResolver::entryIdFor('163336', 'news'))->toBe($newsId);
    expect($defaultId)->not->toBe($newsId);
});

it('normalizes a leading-zero id the way the writer would have', function (): void {
    // `extractMigrationRedirectMetadata()` accepts `0163336` — it is `ctype_digit`
    // and non-zero — and stamps `(int) 163336`. The reader casts for the same
    // reason, and the cast is the only thing that makes this match: the Stache
    // stringifies both sides, so an uncast `'0163336'` needle misses an entry
    // stamped `163336`.
    $entryId = makeArticleEntry(163336);

    expect(NewsArticleResolver::entryIdFor('0163336', 'default'))->toBe($entryId);
});

it('keeps memoized hits apart where a careless cache key would run site and id together', function (): void {
    $entryId = makeArticleEntry(11);

    expect(NewsArticleResolver::entryIdFor('11', 'default'))->toBe($entryId)
        // Same site, another id.
        ->and(NewsArticleResolver::entryIdFor('12', 'default'))->toBeNull()
        // `default` + `11` read the same as `default1` + `1` without a separator.
        ->and(NewsArticleResolver::entryIdFor('1', 'default1'))->toBeNull()
        // And as `1` + `1default` with the id written first.
        ->and(NewsArticleResolver::entryIdFor('1', '1default'))->toBeNull();
});

it('accepts the smallest positive id', function (): void {
    expect(NewsArticleResolver::isWellFormedId('1'))->toBeTrue();
});

it('records everything a reader needs to find the ambiguous entries again', function (): void {
    makeArticleEntry(163336, 'default', 'posts', 'an-article', 'zzz-created-first');
    makeArticleEntry(163336, 'default', 'posts', 'a-duplicate', 'aaa-created-second');
    ProcessingContext::setWordPressPageLink('https://example.test/news/an-article/');

    try {
        NewsArticleResolver::entryIdFor('163336', 'default');
    } finally {
        ProcessingContext::clearWordPressPageLink();
    }

    expect(ambiguousArticleWarnings())->toBe([[
        'site' => 'default',
        'wordpress_post_id' => '163336',
        // In the order the query returned them, which is creation order.
        'entry_ids' => ['zzz-created-first', 'aaa-created-second'],
        'page_url' => 'https://example.test/news/an-article/',
        'reason' => 'More than one `posts` entry carries this WordPress post id, so a reference to it '
            .'is ambiguous. The lowest entry id was used, which is stable but may not be the one '
            .'intended. Duplicating an entry in the Control Panel copies the id, and so does '
            .'re-importing a post whose slug has changed since the last import. Remove whichever '
            .'entry is the stale one.',
    ]]);
});

/**
 * Return the sole concrete Expectation inside a CompositeExpectation so its fluent
 * methods are visible to PHPStan; the CompositeExpectation only proxies them through
 * `__call`.
 *
 * File-local for the reason `tests/Integration/CollectionStructureManagerTest.php` gives
 * for its own copy: a shared trait would read to the analyser as unused.
 *
 * @param  LegacyMockInterface|MockInterface  $mock  The mock to expect a call on
 * @param  string  $method  The method expected to be called
 * @return Expectation The unwrapped expectation, ready to chain
 */
function newsResolverExpects(LegacyMockInterface|MockInterface $mock, string $method): Expectation
{
    $composite = $mock->shouldReceive($method);

    if (! $composite instanceof CompositeExpectation) {
        throw new RuntimeException('Expected a Mockery CompositeExpectation.');
    }

    /** @var list<ExpectationInterface> $expectations */
    $expectations = new ReflectionClass(CompositeExpectation::class)->getProperty('_expectations')->getValue($composite);

    if (\count($expectations) !== 1 || ! $expectations[0] instanceof Expectation) {
        throw new RuntimeException('Expected exactly one Mockery Expectation.');
    }

    return $expectations[0];
}

/**
 * Make the entry query answer with the given result, whatever it is asked.
 *
 * @param  mixed  $result  What the builder's get() returns
 */
function newsResolverQueryReturns(mixed $result): void
{
    $builder = Mockery::mock(EntryQueryBuilder::class);
    newsResolverExpects($builder, 'where')->andReturnSelf();
    newsResolverExpects($builder, 'get')->andReturn($result);
    EntryFacade::shouldReceive('query')->andReturn($builder);
}

/**
 * An unsaved entry carrying the given id.
 */
function newsResolverEntryWithId(string $id): Entry
{
    $entry = new Entry;
    $entry->id($id);

    return $entry;
}

it('resolves nothing when the query answers with something that is not a list', function (): void {
    newsResolverQueryReturns('not a list');

    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBeNull();
});

it('skips matches that are not entries or carry no id', function (): void {
    newsResolverQueryReturns(['not an entry', newsResolverEntryWithId(''), newsResolverEntryWithId('the-real-one')]);

    expect(NewsArticleResolver::entryIdFor('163336', 'default'))->toBe('the-real-one')
        ->and(ambiguousArticleWarnings())->toBe([]);
});
