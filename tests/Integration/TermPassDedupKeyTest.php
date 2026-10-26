<?php

declare(strict_types=1);

use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins the term-pass dedup contract that `wordpress-importer-archive#624`'s third decision rests on.
 *
 * `importTaxonomyTermsAtBeginning()` skips a pass it has already run, keyed on
 * the site plus the **whole sorted taxonomy list** — not per taxonomy. That is
 * why `PageEntryCreator` passes `PostEntryCreator::getTaxonomyHandlesForPostImport()`
 * rather than the narrower `['category']` it actually needs: the same list means
 * the same key, so the post pass's own call becomes a no-op and a page+post
 * import does the term work once. A narrower list would key differently and
 * re-run the entire term import, structured `category` tree included.
 *
 * These tests fail if that dedup contract changes — which is the point. Making
 * the key per-taxonomy is a reasonable future change, and it is one that
 * requires revisiting the page pass's handle list rather than silently
 * inheriting it.
 *
 * @command  php vendor/bin/pest tests/Integration/TermPassDedupKeyTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    // `category` and `post_tag` both land in structured collections (wordpress-importer-archive#1220).
    foreach (['post_categories', 'post_tags'] as $handle) {
        $collection = CollectionFacade::make($handle);
        $collection->sites(['default']);
        $collection->save();
    }

    BaseEntryCreator::clearTaxonomyCaches();
});

afterEach(function (): void {
    BaseEntryCreator::clearTaxonomyCaches();

    foreach (termPassFixtures() as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

/**
 * A concrete `BaseEntryCreator` that exposes the protected term pass.
 *
 * A named class rather than an anonymous one so the helper below can declare its
 * return type — an `object` return widens the type and the analyser then cannot
 * see `runTermPass()` at all. A subclass rather than a partial mock because the
 * behavior under test is the base class's own dedup, which stubbing would erase.
 */
final class TermPassProbeCreator extends BaseEntryCreator
{
    /**
     * Run the protected term pass.
     *
     * @param  XmlParser  $xmlParser  Parser for the export
     * @param  array<int|string, mixed>  $handles  The WordPress taxonomy handles to import, matching the base signature
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output
     */
    public function runTermPass(
        XmlParser $xmlParser,
        array $handles,
        string $site,
        OutputInterface $output
    ): void {
        $this->importTaxonomyTermsAtBeginning($xmlParser, $handles, $site, $output);
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
     *
     * @return bool Always false
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
 * Build a creator that exposes the protected term pass.
 *
 * @return TermPassProbeCreator The creator, with a public `runTermPass()`
 */
function termPassCreator(): TermPassProbeCreator
{
    return new TermPassProbeCreator;
}

/**
 * Write a WXR fixture declaring one category and one tag, and parse it.
 *
 * @return XmlParser A parser over the fixture
 */
function termPassParser(): XmlParser
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
            <wp:term>
                <wp:term_id>42</wp:term_id>
                <wp:term_taxonomy><![CDATA[post_tag]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[cancer]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[Cancer]]></wp:term_name>
            </wp:term>
        </channel>
    </rss>
    XML;

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_termpass_');
    file_put_contents($path, $fixture);

    // The parser reads the file lazily, so it has to outlive this function;
    // `afterEach` removes whatever accumulated.
    termPassFixtures($path);

    $xmlParser = new XmlParser;
    $xmlParser->parse($path);

    return $xmlParser;
}

/**
 * Track fixture paths so they can be removed however a test ends.
 *
 * @param  ?string  $path  A path to remember, or null to drain the list
 * @return list<string> The tracked paths
 */
function termPassFixtures(
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
 * Count how many times the term pass announced itself.
 *
 * @param  string  $console  Captured console output
 * @return int The number of passes that actually ran
 */
function termPassRuns(
    string $console
): int {
    return \substr_count($console, 'Importing taxonomy terms at beginning of import process');
}

it('runs the term pass once for a given site and handle list', function (): void {
    $creator = termPassCreator();
    $parser = termPassParser();
    $output = new BufferedOutput;

    $handles = PostEntryCreator::getTaxonomyHandlesForPostImport();

    // This is the page pass followed by the post pass, with the list the page
    // pass deliberately borrows.
    $creator->runTermPass($parser, $handles, 'default', $output);
    $creator->runTermPass($parser, $handles, 'default', $output);

    expect(termPassRuns($output->fetch()))->toBe(1);
});

it('runs it again for a narrower list, because the key is the whole list', function (): void {
    $creator = termPassCreator();
    $parser = termPassParser();
    $output = new BufferedOutput;

    // The rejected shape: a page pass asking only for what it needs. With
    // `import_tags` on — the default — the post pass then keys differently and
    // redoes the whole term import.
    $creator->runTermPass($parser, ['category'], 'default', $output);
    $creator->runTermPass($parser, ['category', 'post_tag'], 'default', $output);

    expect(termPassRuns($output->fetch()))->toBe(2);
});

it('keys per site, so a second site still imports its own terms', function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'news' => ['name' => 'News', 'locale' => 'en_US', 'url' => '/news/'],
    ]);

    $creator = termPassCreator();
    $parser = termPassParser();
    $output = new BufferedOutput;

    $handles = PostEntryCreator::getTaxonomyHandlesForPostImport();

    $creator->runTermPass($parser, $handles, 'default', $output);
    $creator->runTermPass($parser, $handles, 'news', $output);

    expect(termPassRuns($output->fetch()))->toBe(2);
});

it('always asks for `category`, whatever the tag setting is', function (): void {
    // The page pass borrows this list to get categories imported before it
    // parses a news block. A config change that dropped `category` from it would
    // silently take the fix with it.
    expect(PostEntryCreator::getTaxonomyHandlesForPostImport())->toContain('category');

    config()->set('wordpress-import.import_tags', false);

    expect(PostEntryCreator::getTaxonomyHandlesForPostImport())->toContain('category')
        ->and(PostEntryCreator::getTaxonomyHandlesForPostImport())->not->toContain('post_tag');
});
