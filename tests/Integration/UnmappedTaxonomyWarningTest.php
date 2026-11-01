<?php

declare(strict_types=1);

use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Taxonomies\Taxonomy as StatamicTaxonomy;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins the reporting contract for a deliberately unmapped taxonomy (issue wordpress-importer-archive#741).
 *
 * `tshowcase-taxonomy` and its five siblings across both showcase plugins have no
 * Statamic destination by decision. Before wordpress-importer-archive#741 they were absent from the
 * disposition table instead, and were discarded earlier than it looks: every entry
 * creator passes only the handles it routes — `PeopleEntryCreator` passes
 * `tshowcase-categories` and nothing else — and the term pass admits a term only
 * when its taxonomy appears in that list. So 71 terms and 311 people's department
 * labels were dropped at the handle filter, with nothing written and nothing said.
 *
 * That is why the first test below passes a handle list WITHOUT the unmapped
 * taxonomy: it is the only shape a real import produces, and a guard placed inside
 * the per-taxonomy loop would never run.
 *
 * The terms are still not imported; that is the decision. What these tests pin is
 * that the loss is now *reported*, and that the guard is selective rather than a
 * blanket skip — the flat taxonomy alongside it still imports.
 *
 * @command  php vendor/bin/pest tests/Integration/UnmappedTaxonomyWarningTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    $taxonomy = TaxonomyFacade::make('classified_categories');

    // `TaxonomyFacade::make()` is typed to the contract, which declares no save().
    if (! $taxonomy instanceof StatamicTaxonomy) {
        throw new RuntimeException('Statamic did not return a concrete taxonomy.');
    }

    $taxonomy->save();

    ImportWarningManager::clearWarnings();
    BaseEntryCreator::clearTaxonomyCaches();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
    BaseEntryCreator::clearTaxonomyCaches();

    foreach (unmappedTaxonomyFixtures() as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

it('reports the discard when no caller lists the unmapped taxonomy', function (): void {
    // The production shape. Every entry creator passes only the handles it
    // routes — `PeopleEntryCreator` passes `tshowcase-categories` and nothing
    // else — so an unmapped taxonomy is filtered out before the per-taxonomy
    // loop is reached. This is the path a real import actually takes.
    $console = new BufferedOutput;

    unmappedTaxonomyCreator()->runTermPass(
        unmappedTaxonomyParser(),
        ['advert_category'],
        'default',
        $console
    );

    $reported = ImportWarningManager::getWarnings(
        'content',
        'taxonomy_deliberately_unmapped'
    );

    expect($reported)->toHaveCount(1);

    $payload = $reported[0] ?? null;

    // Narrow before reading fields; the manager stores warning data as mixed.
    if (! \is_array($payload)) {
        throw new RuntimeException('The warning payload was not an array.');
    }

    // Naming the taxonomy, its site, and how much was dropped.
    expect($payload['taxonomy'])->toBe('tshowcase-taxonomy')
        ->and($payload['term_count'])->toBe(2)
        ->and($payload['site'])->toBe('default');

    // Assert the skip sentence itself, not merely that the handle appears
    // somewhere — a bare handle match cannot tell this line from any other.
    expect($console->fetch())->toContain(
        "Skipping 'tshowcase-taxonomy': recorded as having no Statamic destination. 2 term(s) not imported."
    );
});

it('reports the discard when a caller does list the unmapped taxonomy', function (): void {
    // The safety net. No caller does this today, but if a handle list ever grew
    // to include one, the terms must still not be imported — and must still be
    // reported exactly once rather than twice by both paths.
    $console = new BufferedOutput;

    unmappedTaxonomyCreator()->runTermPass(
        unmappedTaxonomyParser(),
        ['tshowcase-taxonomy', 'advert_category'],
        'default',
        $console
    );

    // `fetch()` drains the buffer, so read it once and assert against the copy.
    $printed = $console->fetch();

    expect(ImportWarningManager::getWarnings('content', 'taxonomy_deliberately_unmapped'))
        ->toHaveCount(1)
        ->and($printed)->toContain(
            "Skipping 'tshowcase-taxonomy': recorded as having no Statamic destination. 2 term(s) not imported."
        );

    // ---
    // The report is emitted BEFORE the guard's `continue`, so asserting it alone
    // leaves the skip itself untested — deleting the `continue` kept every test
    // in this file green. Without it the loop falls through to
    // registerFlatTermDestinations(), which records the taxonomy as a flat
    // destination and makes TaxonomyNavigationResolver report a nav item for it
    // as `flat_destination` — the exact mislabel wordpress-importer-archive#741 removed. Assert the skip.
    // ---
    expect($printed)->not->toContain("Processing 'tshowcase-taxonomy' taxonomy terms")
        ->and(TermDestinationRegistry::referenceFor('tshowcase-taxonomy', '101'))->toBeNull();
});

it('reports a discard once even when the pass runs for several creators', function (): void {
    // Each entry creator runs its own term pass over the same export. The pass
    // dedup keys on the handle list, so two creators with different lists both
    // scan — and both would report without the once-per-site guard.
    $creator = unmappedTaxonomyCreator();

    $creator->runTermPass(unmappedTaxonomyParser(), ['advert_category'], 'default', new BufferedOutput);
    $creator->runTermPass(unmappedTaxonomyParser(), ['category'], 'default', new BufferedOutput);

    expect(ImportWarningManager::getWarnings('content', 'taxonomy_deliberately_unmapped'))
        ->toHaveCount(1);
});

it('still imports the flat taxonomy sitting alongside the unmapped one', function (): void {
    // The bound on the other side: the guard must skip one taxonomy, not the pass.
    $console = new BufferedOutput;

    unmappedTaxonomyCreator()->runTermPass(
        unmappedTaxonomyParser(),
        ['advert_category'],
        'default',
        $console
    );

    // The creation line is the import event itself, and terms are written
    // site-prefixed — so the id proves both that it ran and where it landed.
    expect($console->fetch())
        ->toContain("Created 'classified_categories::default_cancer'");
});

it('stays silent for an export declaring no unmapped taxonomy', function (): void {
    unmappedTaxonomyCreator()->runTermPass(
        unmappedTaxonomyParser(withUnmapped: false),
        ['advert_category'],
        'default',
        new BufferedOutput
    );

    expect(ImportWarningManager::getAllWarnings())
        ->not->toHaveKey('content');
});

/**
 * A concrete `BaseEntryCreator` that exposes the protected term pass.
 *
 * A named class rather than an anonymous one so the helper below can declare its
 * return type; a subclass rather than a mock because the behavior under test is
 * the base class's own branching, which stubbing would erase.
 */
final class UnmappedTaxonomyProbeCreator extends BaseEntryCreator
{
    /**
     * Run the protected term pass.
     *
     * @param  XmlParser  $xmlParser  Parser for the export
     * @param  array<int|string, mixed>  $handles  The WordPress taxonomy handles, matching the base signature
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
 * @return UnmappedTaxonomyProbeCreator The creator, with a public `runTermPass()`
 */
function unmappedTaxonomyCreator(): UnmappedTaxonomyProbeCreator
{
    return new UnmappedTaxonomyProbeCreator;
}

/**
 * Write a WXR fixture and parse it.
 *
 * @param  bool  $withUnmapped  Whether to declare two `tshowcase-taxonomy` terms
 * @return XmlParser A parser over the fixture
 */
function unmappedTaxonomyParser(
    bool $withUnmapped = true
): XmlParser {
    $unmapped = $withUnmapped
        ? <<<'XML'
            <wp:term>
                <wp:term_id>101</wp:term_id>
                <wp:term_taxonomy><![CDATA[tshowcase-taxonomy]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[anes]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[Anesthesiology]]></wp:term_name>
            </wp:term>
            <wp:term>
                <wp:term_id>102</wp:term_id>
                <wp:term_taxonomy><![CDATA[tshowcase-taxonomy]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[child-neurology]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[Child Neurology]]></wp:term_name>
            </wp:term>
        XML
        : '';

    $fixture = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
    {$unmapped}
            <wp:term>
                <wp:term_id>42</wp:term_id>
                <wp:term_taxonomy><![CDATA[advert_category]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[cancer]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name><![CDATA[Cancer]]></wp:term_name>
            </wp:term>
        </channel>
    </rss>
    XML;

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_unmapped_');
    file_put_contents($path, $fixture);

    // The parser reads the file lazily, so it has to outlive this function;
    // `afterEach` removes whatever accumulated.
    unmappedTaxonomyFixtures($path);

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
function unmappedTaxonomyFixtures(
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
