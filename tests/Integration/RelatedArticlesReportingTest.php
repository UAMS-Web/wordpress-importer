<?php

declare(strict_types=1);

use Carbon\Carbon;
use Illuminate\Support\Facades\Event;
use Statamic\Entries\Entry;
use Statamic\Events\EntrySaving;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins what the deferred related-articles pass REPORTS (issue wordpress-importer-archive#761).
 *
 * The pass had two independent defects, and only one of them is visible to
 * `KbImportTest`. The ordering defect — the pass ran before the collection tree
 * was written, so the host app's `ValidateKnowledgeBaseEntry` listener rejected
 * every re-save — is pinned there, because it needs the host listener to exist.
 *
 * This defect is the other one: the caller tested
 * `EntryFieldUpdater::updateEntryFields()`'s return with `instanceof Entry`, but
 * that helper hands back the `Entry` only when told NOT to save. This call takes
 * the saving default, so a SUCCESSFUL update took the failure branch — logging an
 * error and leaving `$processedCount` at zero. The entry was written correctly
 * either way, so the field assertion in `KbImportTest` passes with the defect
 * present and with it absent. Verified on macOS, 2026-08-27: mutating the check
 * back survives that suite untouched at 6 assertions.
 *
 * It lives here rather than in the host suite because the host cannot pin it
 * SPECIFICALLY, not because the host cannot see it. `KbImportTest` builds its
 * console as a mocked `OutputInterface`, and swapping that for a `BufferedOutput`
 * would expose the same `Processed N` line. But in the host both defects reach
 * that line: `Processed 0` there is equally consistent with the ordering defect,
 * so the assertion would not say which one it caught. Here the ordering defect
 * cannot occur — the listener is not registered — so the report is the only
 * variable, and a red test names one cause.
 *
 * What the count is worth: it is the only signal an operator gets that the pass
 * did anything. A run that resolved every reference reported `Processed 0 entries
 * with related articles.` beside an error line per entry.
 *
 * @command  php vendor/bin/pest tests/Integration/RelatedArticlesReportingTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    // The collection the pass looks entries up in
    $collection = CollectionFacade::make('knowledge_bases');
    $collection->sites(['default']);
    $collection->save();
});

it('reports a resolved reference as processed rather than failed', function (): void {
    // The referring article, and the one it points at
    $referrerId = makeKbArticle('sample-article-12-12-aaaa', 'sample-article-12');
    $targetId = makeKbArticle('sample-article-13-13-bbbb', 'sample-article-13');

    $output = new BufferedOutput;

    // Seed the pass exactly as `getRelatedArticles()` and the article loop would
    $creator = new RelatedArticlesProbeCreator(
        $this->createMock(EntryCreator::class),
        $this->createMock(MinervaKbSettingsSelector::class)
    );
    $creator->seedDeferred(
        [$referrerId => ['wp_post_ids' => ['13'], 'slug' => 'sample-article-12', 'date' => null]],
        ['13' => $targetId]
    );

    $creator->runDeferredRelatedArticles($output, 'default', 'knowledge_bases');

    $reported = $output->fetch();

    // ---
    // The count is the assertion. `Processed 0` is what the defect produced on a
    // run that had in fact updated the entry, so asserting only that the field
    // landed would pass in both worlds.
    // ---
    expect($reported)->toContain('Processed 1 entries with related articles.');

    // The write itself still happened, which is what makes the count a lie rather
    // than a symptom of a failed save
    $saved = EntryFacade::find($referrerId);

    if (! $saved instanceof Entry) {
        throw new RuntimeException("The referring entry '$referrerId' was not found after the pass.");
    }

    expect($saved->get('related_articles'))->toBe([$targetId]);
});

it('reports a reference whose target is absent from the export', function (): void {
    // Only the referring article exists; post 999 was never imported
    $referrerId = makeKbArticle('sample-article-12-12-cccc', 'sample-article-12');

    $output = new BufferedOutput;

    $creator = new RelatedArticlesProbeCreator(
        $this->createMock(EntryCreator::class),
        $this->createMock(MinervaKbSettingsSelector::class)
    );
    $creator->seedDeferred(
        [$referrerId => ['wp_post_ids' => ['999'], 'slug' => 'sample-article-12', 'date' => null]],
        []
    );

    $creator->runDeferredRelatedArticles($output, 'default', 'knowledge_bases');

    $reported = $output->fetch();

    // The reference is surfaced, not silently dropped
    expect($reported)->toContain('1 related article references could not be resolved.');

    // ---
    // And nothing is written: an unresolvable reference must not leave a partial
    // or empty value behind, which would be indistinguishable from a field the
    // importer never touched.
    // ---
    $saved = EntryFacade::find($referrerId);

    if (! $saved instanceof Entry) {
        throw new RuntimeException("The referring entry '$referrerId' was not found after the pass.");
    }

    expect($saved->get('related_articles'))->toBeNull();
});

it('reports a save halted by a listener as not processed', function (): void {
    // Both articles must exist before the listener starts refusing saves
    $referrerId = makeKbArticle('sample-article-12-12-dddd', 'sample-article-12');
    $targetId = makeKbArticle('sample-article-13-13-eeee', 'sample-article-13');

    // ---
    // A listener can refuse a save two ways, and they take different paths:
    // throwing reaches `updateEntryFields()`'s `catch`, while returning false makes
    // `EntrySaving::dispatch()` halt and `Entry::save()` return a plain `false` that
    // nothing throws over. This is the quiet one.
    //
    // No host listener takes it — all eleven declare `handle(): void`, and the one
    // that rejected these saves in wordpress-importer-archive#761 throws — so this case pins the helper's
    // contract rather than a reachable host behavior. It earns its place anyway: it
    // is the only test that exercises the sentinel's FAILURE branch, without which
    // an always-true mutant there is caught by PHPStan alone.
    // ---
    Event::listen(EntrySaving::class, fn (): bool => false);

    $output = new BufferedOutput;

    $creator = new RelatedArticlesProbeCreator(
        $this->createMock(EntryCreator::class),
        $this->createMock(MinervaKbSettingsSelector::class)
    );
    $creator->seedDeferred(
        [$referrerId => ['wp_post_ids' => ['13'], 'slug' => 'sample-article-12', 'date' => null]],
        ['13' => $targetId]
    );

    $creator->runDeferredRelatedArticles($output, 'default', 'knowledge_bases');

    // ---
    // The reference resolved — this is not the unmapped case — so the count is
    // reporting the SAVE, and a halted save must not read as processed.
    // ---
    $reported = $output->fetch();

    expect($reported)->toContain('Processed 0 entries with related articles.');
    expect($reported)->not->toContain('could not be resolved');

    // ---
    // Deliberately no assertion on the entry's stored value here. `$entry->set()`
    // mutates the in-memory instance before the save is refused, and the repository
    // can hand that same instance back — so a read would describe the object rather
    // than what was persisted. The report is the contract this case owns.
    // ---
});

/**
 * Create a knowledge-base article entry with a pinned id.
 *
 * The id is pinned rather than generated because the deferred map is keyed by it:
 * a random id would still pass, but would not demonstrate that the pass reads the
 * key it was given.
 *
 * @param  string  $id  The entry id
 * @param  string  $slug  The entry slug
 * @return string The created entry's id
 */
function makeKbArticle(
    string $id,
    string $slug
): string {
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException("Could not make an entry for '$slug'.");
    }

    $entry->id($id);
    $entry->collection('knowledge_bases');
    $entry->locale('default');
    $entry->slug($slug);
    $entry->set('title', $slug);
    $entry->published(true);
    $entry->save();

    return $id;
}

/**
 * A `KbEntryCreator` that exposes the protected deferred pass and its state.
 *
 * A named subclass rather than a mock because the behavior under test is this
 * class's own reporting branch, which stubbing would erase; a subclass rather
 * than an anonymous class so the tests above can declare what they hold.
 */
final class RelatedArticlesProbeCreator extends KbEntryCreator
{
    /**
     * Seed the state the article loop would have built.
     *
     * @param  array<string, array{wp_post_ids: list<string>, slug: string|null, date: Carbon|null}>  $deferred  Keyed by entry id, as `getRelatedArticles()` writes it
     * @param  array<int|string, string>  $idMap  WordPress post id to Statamic entry id
     */
    public function seedDeferred(
        array $deferred,
        array $idMap
    ): void {
        $this->deferredRelatedArticles = $deferred;
        $this->articleIdMap = $idMap;
    }

    /**
     * Run the protected deferred related-articles pass.
     *
     * @param  OutputInterface  $output  Console output
     * @param  string  $site  The Statamic site handle
     * @param  string  $collectionHandle  The collection handle
     */
    public function runDeferredRelatedArticles(
        OutputInterface $output,
        string $site,
        string $collectionHandle
    ): void {
        $this->processDeferredRelatedArticles($output, $site, $collectionHandle);
    }
}
