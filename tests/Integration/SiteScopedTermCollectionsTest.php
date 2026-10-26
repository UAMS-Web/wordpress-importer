<?php

declare(strict_types=1);

use Illuminate\Support\Collection;
use Statamic\Entries\Collection as StatamicCollection;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TaxonomyDispositions;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Tests that the four site-scoped WordPress taxonomies import as collection entries (wordpress-importer-archive#1220).
 *
 * `wordpress-importer-archive#1215` decided that `post_tag`, `logo-list-label`, `kbtag` and
 * `mkb_faq_category` import as site-scoped structured collections rather than as
 * flat taxonomy terms, and `UAMS-Web/uams-statamic#2532`–`#2535` converted each
 * destination. A flat term is shared by every site, which is why the flat path
 * prefixed each slug and title with the site; a collection entry belongs to one
 * site and keeps its plain name.
 *
 * These run the real term pass against real collections, so what is asserted is
 * what an import actually writes: one entry per term per site, a plain title, a
 * registry record a writer can resolve, and no taxonomy term anywhere.
 *
 * @command  php vendor/bin/pest tests/Integration/SiteScopedTermCollectionsTest.php
 */

/**
 * The WordPress taxonomy, its Statamic destination, and the fixture term's id, slug and name.
 *
 * @return array<string, array{collection: string, id: string, slug: string, name: string}>
 */
function siteScopedTermRoutes(): array
{
    return [
        'post_tag' => ['collection' => 'post_tags', 'id' => '8', 'slug' => 'annual-report', 'name' => 'Annual Report'],
        'logo-list-label' => ['collection' => 'logos_item_labels', 'id' => '31', 'slug' => 'external-logos', 'name' => 'External Logos'],
        'kbtag' => ['collection' => 'knowledge_base_tags', 'id' => '51', 'slug' => 'mri', 'name' => 'MRI'],
        'mkb_faq_category' => ['collection' => 'faq_categories', 'id' => '61', 'slug' => 'billing', 'name' => 'Billing & Payments'],
    ];
}

beforeEach(function (): void {
    // ---
    // Multisite is off in the minimal boot, and with it off Statamic reports a
    // collection as enabled on the default site only, whatever it was given.
    // The host runs multisite, so the site claim below is only observable with
    // it on.
    // ---
    config(['statamic.system.multisite' => true]);

    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'news' => ['name' => 'News', 'locale' => 'en_US', 'url' => '/news/'],
    ]);

    // ---
    // Each destination starts enabled on `default` only, as the host's do on
    // fewer sites than the content that references them. The term pass has to
    // claim `news` itself before it writes there.
    // ---
    foreach (siteScopedTermRoutes() as $route) {
        $collection = CollectionFacade::make($route['collection']);
        $collection->sites(['default']);
        $collection->structureContents(['root' => false]);
        $collection->save();
    }

    BaseEntryCreator::clearTaxonomyCaches();
});

afterEach(function (): void {
    BaseEntryCreator::clearTaxonomyCaches();

    foreach (siteScopedTermFixtures() as $path) {
        if (file_exists($path)) {
            unlink($path);
        }
    }
});

/**
 * A concrete `BaseEntryCreator` that exposes the protected term pass and term maps.
 *
 * Named rather than anonymous so the helpers can declare its type; a subclass
 * rather than a mock because the behavior under test is the base class's own.
 */
final class SiteScopedTermProbeCreator extends BaseEntryCreator
{
    /**
     * Run the protected term pass.
     *
     * @param  XmlParser  $xmlParser  Parser for the export
     * @param  list<string>  $handles  WordPress taxonomy handles to import
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
     * Run the protected term initialization, which also builds the term maps.
     *
     * @param  XmlParser  $xmlParser  Parser for the export
     * @param  list<string>  $handles  WordPress taxonomy handles to import
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output
     */
    public function runInitializeTerms(
        XmlParser $xmlParser,
        array $handles,
        string $site,
        OutputInterface $output
    ): void {
        $this->initializeTerms($xmlParser, $handles, $site, $output);
    }

    /**
     * Expose the nicename-to-term-id map built by initialization.
     *
     * @return array<int|string, mixed> The map
     */
    public function nicenameMap(): array
    {
        return $this->nicenameToTermIdMap;
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
 * Write a WXR fixture declaring one term in each of the four taxonomies, and parse it.
 *
 * The tag is a `<wp:tag>` element, which is how WordPress exports `post_tag`;
 * the other three are `<wp:term>` elements.
 *
 * @return XmlParser A parser over the fixture
 */
function siteScopedTermParser(): XmlParser
{
    $terms = '';

    foreach (siteScopedTermRoutes() as $taxonomy => $route) {
        if ($taxonomy === 'post_tag') {
            continue;
        }

        $name = htmlspecialchars($route['name'], ENT_XML1);
        $terms .= <<<XML
            <wp:term>
                <wp:term_id>{$route['id']}</wp:term_id>
                <wp:term_taxonomy><![CDATA[{$taxonomy}]]></wp:term_taxonomy>
                <wp:term_slug><![CDATA[{$route['slug']}]]></wp:term_slug>
                <wp:term_parent><![CDATA[]]></wp:term_parent>
                <wp:term_name>{$name}</wp:term_name>
            </wp:term>

        XML;
    }

    $tag = siteScopedTermRoutes()['post_tag'];

    $fixture = <<<XML
    <?xml version="1.0" encoding="UTF-8"?>
    <rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
        <channel>
            <wp:tag>
                <wp:term_id>{$tag['id']}</wp:term_id>
                <wp:tag_slug><![CDATA[{$tag['slug']}]]></wp:tag_slug>
                <wp:tag_name><![CDATA[{$tag['name']}]]></wp:tag_name>
            </wp:tag>
    {$terms}
        </channel>
    </rss>
    XML;

    $path = (string) tempnam(sys_get_temp_dir(), 'wxr_sitescoped_');
    file_put_contents($path, $fixture);

    // The parser reads the file lazily, so it has to outlive this function
    siteScopedTermFixtures($path);

    $xmlParser = new XmlParser;
    $xmlParser->parse($path);

    return $xmlParser;
}

/**
 * Track fixture paths so they can be removed however a test ends.
 *
 * @param  ?string  $path  A path to remember, or null to read the list
 * @return list<string> The tracked paths
 */
function siteScopedTermFixtures(
    ?string $path = null
): array {
    /** @var list<string> $paths */
    static $paths = [];

    if ($path !== null) {
        $paths[] = $path;
    }

    return $paths;
}

/**
 * Return every entry a collection holds on one site.
 *
 * @param  string  $collection  The collection handle
 * @param  string  $site  The Statamic site handle
 * @return list<Entry> The entries
 */
function siteScopedEntries(
    string $collection,
    string $site
): array {
    $query = EntryQueryHelper::query();
    $query->where('collection', $collection);
    $query->where('locale', $site);

    $entries = [];

    $results = $query->get();

    foreach (is_iterable($results) ? $results : [] as $entry) {
        if ($entry instanceof Entry) {
            $entries[] = $entry;
        }
    }

    return $entries;
}

it('records all four taxonomies as collections with their destinations', function (string $taxonomy): void {
    $route = siteScopedTermRoutes()[$taxonomy];

    expect(TaxonomyDispositions::isStructured($taxonomy))->toBeTrue()
        ->and(TaxonomyDispositions::isStructuredDestination($route['collection']))->toBeTrue();
})->with(array_keys(siteScopedTermRoutes()));

it('imports each term as one plainly titled entry in the imported site', function (string $taxonomy): void {
    $route = siteScopedTermRoutes()[$taxonomy];

    (new SiteScopedTermProbeCreator)->runTermPass(
        siteScopedTermParser(),
        array_keys(siteScopedTermRoutes()),
        'default',
        new BufferedOutput
    );

    $entries = siteScopedEntries($route['collection'], 'default');

    // One entry, carrying the WordPress slug and name with no site prefix
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->slug())->toBe($route['slug'])
        ->and($entries[0]->get('title'))->toBe($route['name']);

    // The writers resolve the term through the registry, to exactly this entry
    expect(TermDestinationRegistry::resolve($taxonomy, $route['id']))->toBe([
        'shape' => TaxonomyDispositions::SHAPE_COLLECTION,
        'handle' => $route['collection'],
        'reference' => $entries[0]->id(),
    ]);

    // Nothing was written to the other site
    expect(siteScopedEntries($route['collection'], 'news'))->toBe([]);
})->with(array_keys(siteScopedTermRoutes()));

it('imports a term two sites both carry as two entries, one per site', function (string $taxonomy): void {
    $route = siteScopedTermRoutes()[$taxonomy];
    $creator = new SiteScopedTermProbeCreator;
    $parser = siteScopedTermParser();

    $creator->runTermPass($parser, array_keys(siteScopedTermRoutes()), 'default', new BufferedOutput);
    $defaultEntries = siteScopedEntries($route['collection'], 'default');

    $creator->runTermPass($parser, array_keys(siteScopedTermRoutes()), 'news', new BufferedOutput);
    $newsEntries = siteScopedEntries($route['collection'], 'news');

    // Each site holds its own entry, with the same plain slug and title
    expect($defaultEntries)->toHaveCount(1)
        ->and($newsEntries)->toHaveCount(1)
        ->and($newsEntries[0]->id())->not->toBe($defaultEntries[0]->id())
        ->and($newsEntries[0]->slug())->toBe($route['slug'])
        ->and($newsEntries[0]->get('title'))->toBe($route['name']);

    // The second site's entry is not a localization of the first
    expect($newsEntries[0]->hasOrigin())->toBeFalse();

    // The destination was claimed for the second site before it was written to
    $collection = CollectionFacade::findByHandle($route['collection']);
    $siteList = $collection instanceof StatamicCollection ? $collection->sites() : null;
    $sites = $siteList instanceof Collection ? $siteList->all() : [];

    expect($sites)->toContain('news');

    // The registry now points at the site just imported
    expect(TermDestinationRegistry::referenceFor($taxonomy, $route['id']))->toBe($newsEntries[0]->id());
})->with(array_keys(siteScopedTermRoutes()));

it('creates no taxonomy term in any of the four destinations', function (): void {
    $output = new BufferedOutput;

    (new SiteScopedTermProbeCreator)->runTermPass(
        siteScopedTermParser(),
        array_keys(siteScopedTermRoutes()),
        'default',
        $output
    );

    $printed = $output->fetch();

    // The flat path announces each term it creates; the structured path does not
    expect($printed)->not->toContain("Created '")
        ->and($printed)->toContain("term(s) into 'post_tags'");

    foreach (siteScopedTermRoutes() as $route) {
        expect(TaxonomyFacade::findByHandle($route['collection']))->toBeNull();
    }
});

it('maps a post tag nicename to its term id, so a post can resolve it', function (): void {
    // ---
    // WordPress exports tags as `<wp:tag>`, which `getTerms()` never returns.
    // Without the tag pseudo-terms the map has no `post_tag` row, and a post's
    // tag nicenames resolve to nothing at all.
    // ---
    $creator = new SiteScopedTermProbeCreator;

    $creator->runInitializeTerms(
        siteScopedTermParser(),
        ['post_tag'],
        'default',
        new BufferedOutput
    );

    $map = $creator->nicenameMap();
    $tagMap = $map['post_tag'] ?? [];

    expect($tagMap)->toBeArray()
        ->and(is_array($tagMap) ? ($tagMap['annual-report'] ?? null) : null)->toBe('8');

    // End to end: the post's nicename resolves to the imported entry
    $entryId = TermDestinationRegistry::referenceFor('post_tag', '8');

    expect($entryId)->toBeString()
        ->and((new PostEntryCreator)->resolveTermEntryReferences(
            'post_tag',
            ['annual-report', 'never-imported'],
            is_array($tagMap) ? $tagMap : []
        ))->toBe([$entryId]);
});
