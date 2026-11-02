<?php

declare(strict_types=1);

use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins entry-id preservation across a re-import of a FLAT collection (issue wordpress-importer-archive#797).
 *
 * `KbReimportPreservesEntryIdTest` covers the structured case, and
 * `OverwritePreservesEntryIdTest` covers the `saveEntry()` boundary through a
 * probe subclass. Neither drives a real creator's public entry point, which is
 * where wordpress-importer-archive#767's two blockers actually lived — in caller-side code reading
 * `$entryId` after the save returned.
 *
 * This one does: `PostEntryCreator::createEntries()` is the genuine entry point
 * the import command calls, run twice over one WXR file on disk, and its RETURN
 * VALUE is asserted — `$this->pageData`, whose `entry` key is the id
 * `addPageData()` resolved from the saved entry. Reverting that resolution leaves
 * every other test in the suite green, so this file is what pins it. It reaches the
 * `Overwrite matching files` branch the same way a real second import does —
 * through `findExistingEntry()`'s slug-plus-post-id fallback, whose own comment
 * says it exists for "cases where the random part of the ID has changed between
 * imports".
 *
 * **The export is a real file, not injected nodes.** `createEntries()` opens its
 * pass with `ContentStreamFactory::open()`, which refuses a source serving no
 * paths (wordpress-importer-archive#662) and streams from disk, so `setItems()` cannot reach it.
 *
 * **`ContentProcessor` is mocked, and that is a boundary rather than a
 * convenience.** Its constructor does `new BardContentSanitizer`, which resolves
 * to the host app's `App\Services\BardContentSanitizer` and does not exist under
 * Testbench. `createEntries()` calls `process()` on it exactly once, for content
 * transformation that is incidental to id preservation, so a mock keeps this in
 * the tier where the gate can see it. Mockery does not invoke the original
 * constructor, which is what makes that possible at all.
 *
 * Base class: `StatamicTestCase` (`tests/Integration`).
 *
 * @command  php vendor/bin/pest tests/Integration/PostReimportPreservesEntryIdTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    // ---
    // `date: true` matches the host app's `content/collections/posts.yaml`. It is
    // not cosmetic: `saveEntry()` branches on `$isCollectionDated`, so an undated
    // probe collection exercises the other side of that branch from production.
    // ---
    $collection = CollectionFacade::make('posts');
    $collection->sites(['default']);
    $collection->dated(true);
    $collection->save();

    $blueprint = BlueprintFacade::make('post');
    $blueprint->setNamespace('collections.posts');
    $blueprint->setContents([
        'fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ],
    ]);
    $blueprint->save();
});

afterEach(function (): void {
    Mockery::close();

    // ---
    // `registerUrlMapping()` writes the process-wide `$urlToPostIdMap`, and both
    // passes register the same post. `clearDeferredData()` is what resets it, along
    // with the resolved- and unresolved-link caches the passes also populate.
    // ---
    LinkProcessor::clearDeferredData();

    // ---
    // `importTaxonomyTermsAtBeginning()` short-circuits on the process-wide
    // `$processedTaxonomyImports`, which production resets once per command
    // (`ImportWordPress::handle()`), not per pass. Left set, this file exports that
    // short-circuit to every later test in the process.
    // ---
    BaseEntryCreator::clearTaxonomyCaches();

    // Left pointing at the temporary export this same teardown deletes
    ProcessingContext::clearXmlPath();

    // ---
    // Blueprints are not Stache items, so `PreventsSavingStacheItemsToDisk` does
    // not cover them — `save()` writes YAML into the Testbench workbench under
    // `vendor/`, where it outlives the process.
    // ---
    $blueprint = BlueprintFacade::find('collections.posts.post');

    if ($blueprint instanceof Blueprint) {
        $blueprint->delete();
    }

    TemporaryFileRegistry::sweep();
});

it('keeps a post entry id across a re-import', function (): void {
    $export = postReimportExport();

    $first = postReimportRun($export);
    $second = postReimportRun($export);

    postReimportExpectOverwrote($second);

    // ---
    // The assertion. `SlugGenerator::generateSlugAndId()` appends a fresh
    // `Str::random()` on every call, so the second pass genuinely offered a
    // different id and stability here is preservation rather than coincidence —
    // neutralising the adoption in `saveEntry()`'s overwrite branch makes these two
    // diverge, which is what the mutation run confirms.
    //
    // Every `statamic://entry::<id>` an earlier pass wrote against this post —
    // `LinkProcessor::convertInternalPageLink()`'s references, and
    // `NewsParser::resolveArticleId()`'s `article_id` — names the first pass's id.
    // ---
    expect($second['persisted'])->toBe($first['persisted']);
});

it('leaves the id written by the first import still resolvable', function (): void {
    $export = postReimportExport();

    $first = postReimportRun($export);

    // The reference a page's `statamic://entry::<id>` would have captured
    $reference = $first['persisted'];

    $second = postReimportRun($export);

    // ---
    // The same guard. Without it this case has no discriminating power: under
    // `Skip matching files` nothing is deleted, so the reference resolves whatever
    // the adoption does, and the case would pass with the adoption removed.
    // ---
    postReimportExpectOverwrote($second);

    // ---
    // Stated as the reader sees it: the page renders nothing, because the id it
    // holds resolves to no entry. Asserting the lookup rather than the id makes
    // the failure legible as the symptom rather than as an internal mismatch.
    // ---
    expect(EntryFacade::find($reference))->not->toBeNull();
});

/**
 * Assert a pass overwrote the existing entry rather than skipping it.
 *
 * The entry count alone cannot establish this. Under `Skip matching files`
 * `saveEntry()` returns the pre-existing entry before deleting or re-saving
 * anything, so the count is still one and the id is trivially stable — both
 * cases here pass with the adoption removed unless the branch itself is
 * asserted. The console separates them directly: overwriting re-creates the
 * entry and logs `Created entry`, skipping logs `Skipped entry` and returns.
 *
 * @param  array{persisted: string, reported: string, console: string}  $pass  The pass to check
 */
function postReimportExpectOverwrote(array $pass): void
{
    expect(\substr_count($pass['console'], 'Created entry'))->toBe(1);
    expect($pass['console'])->not->toContain('Skipped entry');
    expect(postReimportEntryIds())->toHaveCount(1);

    // ---
    // And the id the pass REPORTED must be the one it actually persisted. This is
    // the caller-side read: `addPageData()` resolves
    // `$actualEntryId = $entry !== null ? $entry->id() : $id;` and the result is
    // what `createEntries()` hands back for tree building and post-processing.
    // Reverting that ternary leaves `OverwritePreservesEntryIdTest` and
    // `KbReimportPreservesEntryIdTest` green as well as this file's other
    // assertions — measured — so nothing else in the suite pins it.
    // ---
    expect($pass['reported'])->not->toBe('');
    expect($pass['reported'])->toBe($pass['persisted']);
}

/**
 * Perform one full post-import pass over an export on disk.
 *
 * A fresh `XmlParser` and creator per pass mirror a fresh process; the export
 * file is the only thing shared, which is what makes the second pass a genuine
 * re-import of the same source rather than of a different one.
 *
 * @param  string  $export  Path to the WXR file
 * @return array{persisted: string, reported: string, console: string} What this pass produced
 */
function postReimportRun(string $export): array
{
    $parser = new XmlParser;
    $parser->parse($export);

    // ---
    // `process()`'s declared return is
    // `array{content: mixed, page_builder: array<int|string, mixed>}`, and
    // `createEntries()` reads `$parsedContent['content']` and discards it unless it
    // is an array — so a string here would be silently dropped rather than stubbed.
    // Content transformation does not bear on id preservation; the real class
    // cannot be built outside the host app, per the file docblock.
    // ---
    $contentProcessor = Mockery::mock(ContentProcessor::class, [
        'process' => [
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]]],
            'page_builder' => [],
        ],
    ]);

    if (! $contentProcessor instanceof ContentProcessor) {
        throw new RuntimeException('Could not build the content processor stub.');
    }

    $output = new BufferedOutput;

    $creator = new PostEntryCreator;
    $pageData = $creator->createEntries(
        'default',
        'posts',
        $parser,
        new AssetImporter,
        $output,
        new MetadataMapper,
        $contentProcessor
    );

    $ids = postReimportEntryIds();

    // ---
    // `createEntries()` returns `$this->pageData`, whose `entry` key is the id
    // `addPageData()` resolved — the caller-side read this test exists to cover.
    // Discarding it is what left that resolution unpinned.
    // ---
    $row = $pageData['501'] ?? ($pageData[501] ?? null);
    $reported = \is_array($row) ? ($row['entry'] ?? '') : '';

    return [
        'persisted' => $ids[0] ?? '',
        'reported' => \is_string($reported) ? $reported : '',
        'console' => $output->fetch(),
    ];
}

/**
 * The ids of every entry currently in the `posts` collection.
 *
 * @return list<string> The entry ids
 */
function postReimportEntryIds(): array
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

/**
 * Write the one-post WXR export both passes read.
 *
 * @return string The path to the written export
 */
function postReimportExport(): string
{
    $path = TemporaryFileRegistry::create('post-reimport');

    \file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Re-import fixture</title>
<link>https://example.test</link>
<wp:base_blog_url>https://example.test</wp:base_blog_url>
<item>
  <title>Hello World</title>
  <link>https://example.test/hello-world/</link>
  <content:encoded><![CDATA[<p>Body.</p>]]></content:encoded>
  <wp:post_id>501</wp:post_id>
  <wp:post_name>hello-world</wp:post_name>
  <wp:post_date>2024-02-01 10:00:00</wp:post_date>
  <wp:status>publish</wp:status>
  <wp:post_type>post</wp:post_type>
</item>
</channel></rss>
XML);

    return $path;
}

it('reports progress through the streamed posts (#182)', function (): void {
    // A second post, so the streamed loop has a total its progress reports
    $export = postReimportExport();
    $xml = (string) \file_get_contents($export);
    $second = \str_replace(
        ['Hello World', 'hello-world', '<wp:post_id>501</wp:post_id>'],
        ['Hello Again', 'hello-again', '<wp:post_id>502</wp:post_id>'],
        \substr($xml, (int) \strpos($xml, '<item>'), (int) \strpos($xml, '</item>') + \strlen('</item>') - (int) \strpos($xml, '<item>'))
    );
    \file_put_contents($export, \str_replace('</channel>', $second."\n</channel>", $xml));

    $console = postReimportRun($export)['console'];

    expect($console)->toContain('Progress: post 0 of 2 (0%)'.\PHP_EOL)
        ->and($console)->toContain('Progress: post 2 of 2 (100%)'.\PHP_EOL);
});
