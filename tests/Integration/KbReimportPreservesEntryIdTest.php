<?php

declare(strict_types=1);

use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Structures\CollectionStructure;
use Statamic\Structures\Tree;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins the two id adoptions `KbEntryCreator` makes after saving (issue wordpress-importer-archive#798).
 *
 * `saveEntry()` preserves a matched entry's id when a re-import overwrites it
 * (wordpress-importer-archive#767), so the id an entry is SAVED under is no longer the id this run
 * generated. `createTopicEntryFromData()` and `createArticleEntry()` therefore
 * each reassign `$entryId` from the saved entry before anything downstream
 * reads it. Neither reassignment was reachable by any test: wordpress-importer-archive#795 measured the
 * standalone gate at an identical `1 skipped, 2111 passed (13,191 assertions)`
 * with both neutralised, and the host band never enters the overwrite branch at
 * all, being a first import into a clean host.
 *
 * The damage the adoptions prevent is not a stale lookup. `EntryFinder::findEntryById()`
 * called with the generated id returns `null`, `addEntryToTree()` is skipped and
 * `$pendingTreeNodes` stays empty, so `buildAndSaveKbTree()` early-returns — while
 * {@see Entry::delete()} on a structured collection has already
 * moved every child page up to the parent and removed the node. The hierarchy is
 * actively pruned with nothing left to rebuild it.
 *
 * Both cases drive a real double import: two passes of topic → article → tree over
 * one source, through the production methods, against a real structured collection
 * shaped like the host's (`structure.root: false`, per
 * `content/collections/knowledge_bases.yaml`).
 *
 * **Two shapes this does not cover, both measured as not weakening the pin.** A
 * fresh probe per pass models a re-import in a fresh process; `runImportForParts()`
 * also re-imports on the SAME creator instance via its "import more content" loop,
 * where only `clearTreeCache()` resets state and the id maps stay warm — both
 * adoptions stay pinned in that shape too. And the pre-made `kb-root` puts both
 * passes down `buildAndSaveKbTree()`'s external-root branch, whereas a genuine first
 * import records the root as a node; that is a coverage gap, not a difference in
 * outcome.
 *
 * Base class: `StatamicTestCase` (`tests/Integration`). Real entries, a real
 * collection structure and a real saved tree are all reachable under Testbench, so
 * this runs in package CI where a `tests/Feature` version would not.
 *
 * @command  php vendor/bin/pest tests/Integration/KbReimportPreservesEntryIdTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    // ---
    // `root: false` matches the host app's `knowledge_bases` collection. With a
    // root the tree save throws `Root page cannot have children`, so this is the
    // shape the importer actually writes against, not a convenience.
    // ---
    $collection = CollectionFacade::make('knowledge_bases');
    $collection->sites(['default']);
    $collection->structureContents(['root' => false]);
    $collection->save();

    // Topics and articles resolve separate blueprints on save
    foreach (['topic', 'article'] as $handle) {
        $blueprint = BlueprintFacade::make($handle);
        $blueprint->setNamespace('collections.knowledge_bases');
        $blueprint->setContents([
            'fields' => [
                ['handle' => 'title', 'field' => ['type' => 'text']],
            ],
        ]);
        $blueprint->save();
    }

    // ---
    // The knowledge base root. It pre-exists the import rather than being created
    // by it, which is why `buildAndSaveKbTree()` treats it as an "external" parent
    // and anchors it at the top level.
    // ---
    $root = EntryFacade::make();

    if (! $root instanceof Entry) {
        throw new RuntimeException('Could not make the knowledge base root entry.');
    }

    $root->collection('knowledge_bases');
    $root->locale('default');
    $root->slug('kb-root');
    $root->data(['title' => 'KB Root']);
    $root->id('kb-root');
    $root->save();
});

afterEach(function (): void {
    Mockery::close();

    // ---
    // The driven code writes process-wide state that outlives the test. Both passes
    // register into `KbLinkRegistry`'s three static maps — which is also why the
    // helper's "nothing carries over" only describes the creator's own instance
    // maps — and `createArticleEntry()` sets `ProcessingContext`. Left alone, the
    // slugs `billing` and `how-to-pay-a-bill` and term id `55` stay registered for
    // every later test in the process.
    // ---
    KbLinkRegistry::clear();
    ProcessingContext::clearStatamicEntryId();
    ProcessingContext::clearCollectionHandle();

    // ---
    // Blueprints are NOT Stache items, so `PreventsSavingStacheItemsToDisk` does not
    // cover them — `save()` writes YAML into the Testbench workbench under `vendor/`,
    // where it outlives the process and is read by any later test resolving this
    // namespace. Same hazard, and same remedy, as `forgetNinjaFormsArtifacts()`.
    // ---
    foreach (['topic', 'article'] as $handle) {
        $blueprint = BlueprintFacade::find('collections.knowledge_bases.'.$handle);

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }
});

it('keeps the topic and article entry ids across a re-import', function (): void {
    $first = kbReimportRun();
    $second = kbReimportRun();

    // ---
    // Assert the fixture produced the scenario this ticket is about, BEFORE
    // asserting anything about it. Two ways the second pass can fail to exercise
    // the overwrite branch and still look healthy: it can MISS
    // `findExistingEntry()` and create duplicates (wordpress-importer-archive#767 shipped exactly that, via
    // a 14-character random suffix against a `strlen === 16` gate), or it can SKIP,
    // which leaves the count correct and every id assertion below trivially true.
    // ---
    kbReimportExpectOverwrote($second);

    // ---
    // And assert the maps carry something, or the comparisons below are identities
    // between empty arrays. Deleting the single `articleIdMap` write in production
    // makes both passes return `[]`, and `toBe()` passes on it while the liveness
    // loop never runs a single iteration.
    // ---
    expect($first['articles'])->toHaveCount(1);
    expect($first['articles'])->toHaveKey(901);

    // ---
    // `createTopicEntryFromData()` writes `topicIdMap[$termId]` itself, from the same
    // `$entryId` it returns. Articles never receive a parent argument — they read the
    // topic back out of that map through `findArticleTopic()`, which returns whatever
    // it holds without revalidating. So a generated id here is handed straight back
    // as a parent, and every article under it is pruned when the tree is rebuilt.
    // ---
    expect($second['topic'])->toBe($first['topic']);

    // ---
    // `articleIdMap` is read by the deferred related-articles pass, and the same id
    // reaches `KbLinkRegistry::registerKbPostMapping()` — which
    // `LinkProcessor::convertKbLink()` renders as `statamic://entry::<id>`. A
    // generated id here names an entry that is not there, reintroducing the
    // dangling reference wordpress-importer-archive#767 removed.
    // ---
    expect($second['articles'])->toBe($first['articles']);

    // Every recorded id must still resolve to a live entry
    foreach ($second['articles'] as $articleId) {
        expect(EntryFacade::find($articleId))->not->toBeNull();
    }

    expect(EntryFacade::find($second['topic']))->not->toBeNull();

    // ---
    // The registry is where the damage becomes user-visible, so assert it rather
    // than only the maps that feed it. `LinkProcessor` resolves a knowledge-base
    // link by slug through `getKbtopicEntryIdBySlug()` / `getKbPostEntryId()` and
    // hands the result to `generateEntryReference()`, which emits
    // `statamic://entry::<id>`. If the re-import registers a generated id, every
    // such link renders as a reference to an entry that is not there — the wordpress-importer-archive#767
    // defect, arriving by way of the knowledge base.
    // ---
    expect(KbLinkRegistry::getKbtopicEntryIdBySlug('billing'))->toBe($first['topic']);
    expect(KbLinkRegistry::getKbPostEntryId('how-to-pay-a-bill'))->toBe($first['articles'][901] ?? '');
});

it('leaves the knowledge-base hierarchy intact after a re-import', function (): void {
    $first = kbReimportRun();

    $expected = [
        [
            'entry' => 'kb-root',
            'children' => [
                [
                    'entry' => $first['topic'],
                    'children' => [
                        ['entry' => $first['articles'][901] ?? ''],
                    ],
                ],
            ],
        ],
    ];

    // The hierarchy the first import built, before anything overwrites it
    expect(kbReimportTree())->toBe($expected);

    $second = kbReimportRun();

    // ---
    // The same fixture guard test 1 carries. Without it this case has no
    // discriminating power at all: a second pass that SKIPS rebuilds an identical
    // tree, so the assertion below would hold with both adoptions removed and the
    // case would pass about a scenario the ticket is not about.
    // ---
    kbReimportExpectOverwrote($second);

    // ---
    // The same three-level nesting must survive. The two adoptions break it in
    // different places, both measured against this fixture:
    //
    // - Without the TOPIC adoption, `addEntryToTree()` records the article beneath a
    //   topic id that names a deleted entry, so `validateTree()` prunes the article
    //   out of the tree entirely — `kb-root` is left with no children and the topic
    //   sits beside it. The article entry still exists; nothing links to it.
    // - Without the ARTICLE adoption, the topic keeps its place under the root and
    //   the article is stranded at the top level instead, while `articleIdMap`
    //   records an id no entry carries.
    //
    // Both are `Entry::delete()`'s child promotion left standing, because the
    // rebuilt tree could not put back what the delete moved.
    //
    // The expected ids come from the FIRST pass, captured before the second one
    // runs — the claim is that the re-import preserves them, so comparing against
    // the second pass's own output would assert nothing.
    // ---
    expect(kbReimportTree())->toBe($expected);
});

/**
 * Perform one full knowledge-base import pass over the same source.
 *
 * Topic first, then the article that names it, then the deferred tree build —
 * the order `process()` uses. (`createEntries()` is a no-op on this class: its
 * body is `return [];`.) A production import continues past the tree save into
 * `processDeferredRelatedArticles()`, which re-saves already-created articles;
 * the tree is asserted here at the tree-save point, not at the end of an import. A fresh creator per pass mirrors a fresh
 * process: the creator's own id maps and pending tree nodes start empty. Static
 * state is a separate matter — `KbLinkRegistry`'s maps do survive between the two
 * passes, and are cleared in `afterEach` rather than between them, because a real
 * re-import in one process does not clear them either.
 *
 * Each pass owns its own buffer so `console` holds that pass alone, which is
 * what {@see kbReimportExpectOverwrote()} reads to prove which `saveEntry()`
 * branch ran.
 *
 * @return array{topic: string, articles: array<int|string, mixed>, console: string} What this pass recorded
 */
function kbReimportRun(): array
{
    $output = new BufferedOutput;
    $creator = new KbReimportProbe;

    $topicId = $creator->importTopic($output);
    $creator->importArticle($output);
    $creator->saveTree($output);

    return [
        'topic' => $topicId,
        'articles' => $creator->articles(),
        'console' => $output->fetch(),
    ];
}

/**
 * Assert a pass overwrote both existing entries rather than skipping them.
 *
 * The entry count alone cannot establish this. Under `Skip matching files`
 * `saveEntry()` returns the pre-existing entry before deleting or adopting
 * anything, so the count is still three, `$entryId` is never wrong, and BOTH
 * cases here would pass with the two adoptions removed — green for a scenario
 * this ticket is not about.
 *
 * The console separates the branches directly: overwriting re-creates the entry
 * and logs `Created entry`, skipping logs `Skipped entry` and returns early.
 * Two creations and no skips, taken together with an entry count of three,
 * leave only the overwrite path — a missed `findExistingEntry()` would also log
 * two creations, but would leave five entries behind.
 *
 * @param  array{topic: string, articles: array<int|string, mixed>, console: string}  $pass  The pass to check
 */
function kbReimportExpectOverwrote(array $pass): void
{
    expect(\substr_count($pass['console'], 'Created entry'))->toBe(2);
    expect($pass['console'])->not->toContain('Skipped entry');
    expect(kbReimportEntryIds())->toHaveCount(3);
}

/**
 * The saved `knowledge_bases` tree for the default site.
 *
 * @return array<int|string, mixed> The nested tree, or an empty array when unsaved
 */
function kbReimportTree(): array
{
    $collection = CollectionFacade::findByHandle('knowledge_bases');

    if ($collection === null) {
        return [];
    }

    $structure = $collection->structure();

    if (! $structure instanceof CollectionStructure) {
        return [];
    }

    $tree = $structure->in('default');

    if (! $tree instanceof Tree) {
        return [];
    }

    $nodes = $tree->tree();

    return \is_array($nodes) ? $nodes : [];
}

/**
 * The ids of every entry currently in the `knowledge_bases` collection.
 *
 * @return list<string> The entry ids
 */
function kbReimportEntryIds(): array
{
    $ids = [];

    foreach (EntryFacade::all() as $entry) {
        if (! $entry instanceof Entry) {
            continue;
        }

        if ($entry->collectionHandle() !== 'knowledge_bases') {
            continue;
        }

        $id = $entry->id();

        if (\is_string($id)) {
            $ids[] = $id;
        }
    }

    return $ids;
}

/**
 * The `wp:post` element for the one article both passes import.
 *
 * Built fresh per call so neither pass can mutate the other's input. The
 * `category[@domain="kbtopic"]` element is what `findArticleTopic()` resolves
 * against the topic created moments earlier.
 */
function kbReimportArticleItem(): SimpleXMLElement
{
    $xml = <<<'XML'
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel><item>
  <title>How To Pay A Bill</title>
  <content:encoded><![CDATA[<p>Pay it here.</p>]]></content:encoded>
  <wp:post_id>901</wp:post_id>
  <wp:post_name>how-to-pay-a-bill</wp:post_name>
  <wp:post_date>2024-01-05 09:00:00</wp:post_date>
  <wp:status>publish</wp:status>
  <wp:post_type>kb</wp:post_type>
  <category domain="kbtopic" nicename="billing"><![CDATA[Billing]]></category>
</item></channel></rss>
XML;

    $rss = new SimpleXMLElement($xml);

    return $rss->channel->item[0];
}

/**
 * A concrete `KbEntryCreator` exposing the two protected creation paths.
 *
 * A subclass rather than a mock because the behaviour under test is the id
 * adoption inside those methods, which stubbing would erase.
 */
final class KbReimportProbe extends KbEntryCreator
{
    /**
     * Build the probe with inert collaborators.
     */
    public function __construct()
    {
        // ---
        // Neither collaborator is reached by the two paths driven here, and
        // `EntryCreator`'s own graph does not resolve from the container under
        // Testbench — `ContentProcessor`'s constructor fails first. Mocks with no
        // expectations satisfy the signature and would fail loudly if either were
        // ever called, which is the property worth having.
        // ---
        $entryCreator = Mockery::mock(EntryCreator::class);
        $settingsSelector = Mockery::mock(MinervaKbSettingsSelector::class);

        if (
            ! $entryCreator instanceof EntryCreator
            || ! $settingsSelector instanceof MinervaKbSettingsSelector
        ) {
            throw new RuntimeException('Could not build the probe collaborators.');
        }

        parent::__construct(
            $entryCreator,
            $settingsSelector
        );

        // ---
        // Production builds this in `process()` via
        // `TermMapBuilder::buildNicenameToTermIdMap()`, and `findArticleTopic()`
        // reads it FIRST. Leaving it empty routes every lookup down the slug-matching
        // fallback, which resolves through `EntryFinder` and so fails closed when a
        // topic id goes stale — a different, gentler failure than production's, which
        // returns the stale id unconditionally. Seeding it keeps the failure the test
        // pins the one a real import would suffer.
        // ---
        $this->nicenameToTermIdMap = ['kbtopic' => ['billing' => '55']];

        $this->clearTreeCache();
    }

    /**
     * Create the one topic both passes import.
     *
     * @param  OutputInterface  $output  Console output
     * @return string The topic entry id the importer recorded
     */
    public function importTopic(OutputInterface $output): string
    {
        return $this->createTopicEntryFromData(
            [
                'term_id' => '55',
                'term_slug' => 'billing',
                'term_name' => 'Billing',
            ],
            'kb-root',
            $output,
            'default',
            'knowledge_bases'
        );
    }

    /**
     * Create the one article both passes import.
     *
     * @param  OutputInterface  $output  Console output
     */
    public function importArticle(OutputInterface $output): void
    {
        $this->createArticleEntry(
            kbReimportArticleItem(),
            $output,
            'default',
            new XmlParser,
            new AssetImporter,
            'knowledge_bases'
        );
    }

    /**
     * Run the deferred tree build.
     *
     * @param  OutputInterface  $output  Console output
     */
    public function saveTree(OutputInterface $output): void
    {
        $this->buildAndSaveKbTree($output);
    }

    /**
     * The WordPress-post-id to entry-id map this pass recorded.
     *
     * @return array<int|string, mixed> The article id map
     */
    public function articles(): array
    {
        return $this->articleIdMap;
    }
}
