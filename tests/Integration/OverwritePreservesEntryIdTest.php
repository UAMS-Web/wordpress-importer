<?php

declare(strict_types=1);

use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins that re-importing a post keeps its entry id (issue wordpress-importer-archive#767).
 *
 * Two importer surfaces resolve a WordPress post id to a Statamic **entry id**
 * and write that id into content during the `page` pass —
 * `LinkProcessor::convertInternalPageLink()`'s `statamic://entry::<id>`, and
 * `NewsParser::resolveArticleId()`'s `article_id`. Both write immediately when
 * the target already exists, and defer only on a miss.
 *
 * Under the default `Overwrite matching files`, `saveEntry()` used to delete the
 * matched entry and save a replacement carrying a **freshly generated** id, so
 * within a single run `ImportWordPress::$supportedContentTypes` listing `page`
 * before `post` meant: the page pass resolved post N to one id, the post pass
 * replaced that entry with another, and the run ended with the page naming an
 * entry that no longer existed. Re-running never converged, because every run
 * repeated the delete-and-recreate.
 *
 * `PostCategoryEntryResolver`'s ids stay valid for the opposite reason —
 * `StructuredTermImporter::createOrUpdateEntry()` re-saves in place — so the
 * difference was in the entry-creation model, not in the lookups.
 *
 * @command  php vendor/bin/pest tests/Integration/OverwritePreservesEntryIdTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    $collection = CollectionFacade::make('posts');
    $collection->sites(['default']);
    $collection->save();
});

it('keeps the entry id when an existing entry is overwritten', function (): void {
    $creator = new OverwriteProbeCreator;
    $output = new BufferedOutput;

    // ---
    // The random suffix must be EXACTLY 16 characters. `findExistingEntry()` gates
    // its slug-plus-post-id match on `strlen($entryRandomPart) === 16`, which is
    // what `Statamic\Support\Str::random()` returns, so a shorter stand-in makes
    // the second save miss the match entirely and create a second entry — the
    // overwrite branch under test never runs, and the case passes or fails for a
    // reason unrelated to wordpress-importer-archive#767.
    // ---
    $first = $creator->save('my-article-163336-AAAAbbbbCCCCdddd', 'my-article', $output);

    if (! $first instanceof Entry) {
        throw new RuntimeException('The first import did not produce an entry.');
    }

    expect($first->id())->toBe('my-article-163336-AAAAbbbbCCCCdddd');

    // ---
    // The re-import. `SlugGenerator` appends a fresh `Str::random()` per run, so
    // the id offered the second time genuinely differs — that is the input, not
    // an artefact of the test.
    // ---
    $second = $creator->save('my-article-163336-EEEEffffGGGGhhhh', 'my-article', $output);

    if (! $second instanceof Entry) {
        throw new RuntimeException('The re-import did not produce an entry.');
    }

    // ---
    // Assert the overwrite branch actually ran, BEFORE asserting what it did. A
    // fixture that misses `findExistingEntry()` gets two entries and two ids, and
    // every assertion below would then be about a scenario this ticket is not
    // about.
    // ---
    expect(overwriteProbePostIds())->toHaveCount(1);

    // ---
    // The assertion. The replacement must adopt the id the matched entry already
    // had, or every `statamic://entry::<id>` written earlier in this same run
    // points at an entry that has just been deleted.
    // ---
    expect($second->id())->toBe('my-article-163336-AAAAbbbbCCCCdddd');
});

it('still finds the entry by the id written before the re-import', function (): void {
    $creator = new OverwriteProbeCreator;
    $output = new BufferedOutput;

    $first = $creator->save('my-article-163336-AAAAbbbbCCCCdddd', 'my-article', $output);

    if (! $first instanceof Entry) {
        throw new RuntimeException('The first import did not produce an entry.');
    }

    // The reference a page's `statamic://entry::<id>` would have captured
    $writtenReference = $first->id();

    if (! \is_string($writtenReference)) {
        throw new RuntimeException('The first import produced an entry with no id.');
    }

    $creator->save('my-article-163336-EEEEffffGGGGhhhh', 'my-article', $output);

    // ---
    // This is the defect stated as the user sees it: the page renders nothing,
    // because the id it holds resolves to no entry. Asserting the lookup rather
    // than the id makes the failure legible as the symptom rather than as an
    // internal mismatch.
    // ---
    expect(EntryFacade::find($writtenReference))->not->toBeNull();
});

/**
 * A concrete `BaseEntryCreator` exposing the protected save path.
 *
 * A subclass rather than a mock because the behaviour under test is the base
 * class's own overwrite branch, which stubbing would erase.
 */
final class OverwriteProbeCreator extends BaseEntryCreator
{
    /**
     * Save an entry through the real `saveEntry()` path.
     *
     * @param  string  $entryId  The id this run generated
     * @param  string  $slug  The entry slug, stable across runs
     * @param  OutputInterface  $output  Console output
     * @return ?Entry The saved entry
     */
    public function save(
        string $entryId,
        string $slug,
        OutputInterface $output
    ): ?Entry {
        return $this->saveEntry(
            'posts',
            'default',
            $slug,
            ['id' => $entryId, 'title' => 'My Article'],
            $output
        );
    }

    /**
     * Not exercised here.
     *
     * @param  string  $site  The Statamic site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  XmlParser  $xmlParser  Parser for the export
     * @param  mixed  $assetImporter  Unused
     * @param  OutputInterface  $output  Console output
     * @param  mixed  $metadataMapper  Unused
     * @param  mixed  $contentProcessor  Unused
     * @param  ?array<int|string, mixed>  $minervaKbSettings  Unused
     * @return array<int|string, mixed> Always empty
     */
    public function createEntries(
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        $assetImporter,
        OutputInterface $output,
        $metadataMapper,
        $contentProcessor,
        ?array $minervaKbSettings = null
    ): array {
        return [];
    }

    /**
     * Not exercised here.
     */
    public function hasShowPeople(): bool
    {
        return false;
    }

    /**
     * Not exercised here.
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostCategories(): array
    {
        return [];
    }

    /**
     * Not exercised here.
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostTags(): array
    {
        return [];
    }

    /**
     * Not exercised here.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {}
}

/**
 * The ids of every entry currently in the `posts` collection.
 *
 * The facade's query builder is typed to the contract, which declares neither
 * `where()` nor `id()`, so the narrowing happens here once rather than inside
 * each expectation.
 *
 * @return list<string> The entry ids
 */
function overwriteProbePostIds(): array
{
    $ids = [];

    foreach (EntryFacade::all() as $entry) {
        if (! $entry instanceof Entry) {
            continue;
        }

        if ($entry->collectionHandle() !== 'posts') {
            continue;
        }

        $id = $entry->id();

        if (\is_string($id)) {
            $ids[] = $id;
        }
    }

    return $ids;
}
