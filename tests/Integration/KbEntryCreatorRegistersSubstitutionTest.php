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
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredKnowledgeBaseMounts;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\KbPageSubstitutions;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Extractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Prompter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Validator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins the registration `KbEntryCreator::process()` makes for the kb_page its root replaces (wordpress-importer-archive#1186).
 *
 * `NavigationMenuImporter` re-points a navigation item at a knowledge base root by reading
 * `KbPageSubstitutions` (wordpress-importer-archive#1171), and `KbPageNavigationSubstitutionTest` asserts that half by
 * registering the pair itself. So the one call that populates the registry in a real import was
 * asserted by nothing: replacing it with a comment left that test at 3 passed.
 *
 * These cases drive the production `process()` with only the steps downstream of the
 * registration stubbed out — topics, articles, the tree build and the related-articles pass,
 * none of which reads or writes the registry. The kb_page lookup and the root creation both run
 * for real, so the two values the registration passes come from where production takes them:
 * the page id from the export, and the root id from the entry actually saved.
 *
 * Base class: `StatamicTestCase` (`tests/Integration`). A real collection, blueprint and entry
 * save are reachable under Testbench, so this runs in the standalone gate.
 *
 * @command  php vendor/bin/pest tests/Integration/KbEntryCreatorRegistersSubstitutionTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'second' => ['name' => 'Second', 'locale' => 'en_US', 'url' => '/second/'],
    ]);

    // `root: false` matches the host app's `knowledge_bases` collection
    $collection = CollectionFacade::make('knowledge_bases');
    $collection->sites(['default', 'second']);
    $collection->structureContents(['root' => false]);
    $collection->save();

    // The root entry resolves the `knowledge_base` blueprint on save
    $blueprint = BlueprintFacade::make('knowledge_base');
    $blueprint->setNamespace('collections.knowledge_bases');
    $blueprint->setContents([
        'fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ],
    ]);
    $blueprint->save();

    KbPageSubstitutions::clear();
    DeferredKnowledgeBaseMounts::clear();
});

afterEach(function (): void {
    Mockery::close();

    // Both registries and the processing context are process-wide statics that outlive a test
    KbPageSubstitutions::clear();
    DeferredKnowledgeBaseMounts::clear();
    ProcessingContext::clearMinervaKbSettings();

    // ---
    // Blueprints are not Stache items, so `PreventsSavingStacheItemsToDisk` does not cover
    // them: `save()` writes YAML into the Testbench workbench, where later tests would read it.
    // ---
    $blueprint = BlueprintFacade::find('collections.knowledge_bases.knowledge_base');

    if ($blueprint instanceof Blueprint) {
        $blueprint->delete();
    }

    // The export written for the case
    $path = test()->exportPath ?? null;

    if (\is_string($path) && \is_file($path)) {
        \unlink($path);
    }
});

it('registers the kb_page against the knowledge base root it creates', function (): void {
    $this->exportPath = kbSubstitutionExport('100');

    kbSubstitutionImport($this->exportPath, 'default');

    $rootId = kbSubstitutionRootId('default');

    // ---
    // Assert the fixture produced a root that replaced the kb_page, BEFORE asserting on the
    // registry. The root embeds the kb_page's WordPress id and adopts its slug only when
    // `resolveKnowledgeBaseHomePageInfo()` found the page; a root created without it would
    // make the registry assertions below vacuous, since nothing would be registered either way.
    // ---
    expect($rootId)->toContain('-4478-');
    expect(kbSubstitutionSlug($rootId))->toBe('health-library');

    // The mount beneath the parent page was deferred, so this is the ordinary nested case
    expect(DeferredKnowledgeBaseMounts::all())->not->toBe([]);

    // The page id resolves to the root that was saved, for the site it was imported into
    expect(KbPageSubstitutions::resolve('4478', 'default'))->toBe($rootId);

    // ...and to nothing on a site the run did not touch
    expect(KbPageSubstitutions::resolve('4478', 'second'))->toBeNull();

    // Exactly one substitution: the root's own id is not registered in the page's place
    expect(KbPageSubstitutions::all())->toHaveCount(1);
});

it('registers the kb_page when the knowledge base has no parent page', function (): void {
    $this->exportPath = kbSubstitutionExport('0');

    kbSubstitutionImport($this->exportPath, 'default');

    $rootId = kbSubstitutionRootId('default');

    // The same fixture guard as the nested case
    expect($rootId)->toContain('-4478-');

    // ---
    // No parent means no deferred mount. The registration sits outside that branch because
    // the root still replaces the kb_page, and this is the assertion that pins it there: moving
    // the call inside the `parent_wp_id` guard leaves the nested case green and fails this one.
    // ---
    expect(DeferredKnowledgeBaseMounts::all())->toBe([]);
    expect(KbPageSubstitutions::resolve('4478', 'default'))->toBe($rootId);
});

it('registers nothing when no kb_page is configured', function (): void {
    $this->exportPath = kbSubstitutionExport('100');

    kbSubstitutionImport($this->exportPath, 'default', []);

    // ---
    // Control for the two cases above: the same export and the same run, with no kb_page in
    // the settings. The root falls back to the `kb` slug and nothing replaced a page, so the
    // registry stays empty. Without this, a registration keyed on something other than the
    // kb_page would pass both cases above whenever it happened to produce an entry.
    // ---
    expect(kbSubstitutionSlug(kbSubstitutionRootId('default')))->toBe('kb');
    expect(KbPageSubstitutions::all())->toBe([]);
});

/**
 * Run `KbEntryCreator::process()` over an export for one site.
 *
 * @param  string  $exportPath  The WXR file to read
 * @param  string  $site  The site to import into
 * @param  array<int|string, mixed>|null  $settings  MinervaKB settings; defaults to a kb_page of 4478
 * @return string The console output
 */
function kbSubstitutionImport(
    string $exportPath,
    string $site,
    ?array $settings = null
): string {
    $xmlParser = new XmlParser;
    $xmlParser->setFilePath($exportPath);

    // ---
    // Both collaborators prepare the site and structure before the root is created, and
    // neither reads or writes the registry. Their real implementations are exercised by the
    // host-app import tests; here they only need to accept the calls.
    // ---
    $siteManager = Mockery::mock(SiteManager::class)->shouldIgnoreMissing();
    $structureManager = Mockery::mock(CollectionStructureManager::class)->shouldIgnoreMissing();

    if (
        ! $siteManager instanceof SiteManager
        || ! $structureManager instanceof CollectionStructureManager
    ) {
        throw new RuntimeException('Could not build the process collaborators.');
    }

    $output = new BufferedOutput;

    (new KbSubstitutionProbe)->process(
        $xmlParser,
        new AssetImporter,
        $output,
        'https://example.test',
        $site,
        $siteManager,
        $structureManager,
        $settings ?? ['kb_page' => '4478']
    );

    return $output->fetch();
}

/**
 * The id of the one `knowledge_bases` entry in a site.
 *
 * Read from the repository rather than from the creator, so the assertion compares the
 * registry against the entry that was actually saved.
 *
 * @param  string  $site  The site handle
 * @return string The root entry id
 */
function kbSubstitutionRootId(string $site): string
{
    $ids = [];

    foreach (EntryFacade::all() as $entry) {
        if (
            ! $entry instanceof Entry
            || $entry->collectionHandle() !== 'knowledge_bases'
            || $entry->locale() !== $site
        ) {
            continue;
        }

        $id = $entry->id();

        if (\is_string($id)) {
            $ids[] = $id;
        }
    }

    // One root per run, or the id compared below is not the one the run created
    expect($ids)->toHaveCount(1);

    return $ids[0] ?? '';
}

/**
 * The slug of a saved entry, or null when no such entry exists.
 *
 * @param  string  $id  The entry id
 * @return string|null The slug
 */
function kbSubstitutionSlug(string $id): ?string
{
    $entry = EntryFacade::find($id);

    if (! $entry instanceof Entry) {
        return null;
    }

    // `slug()` is a fluent getter/setter, so its declared return is `mixed`
    $slug = $entry->slug();

    return \is_string($slug) ? $slug : null;
}

/**
 * Write a minimal export carrying the kb_page, and return its path.
 *
 * @param  string  $parentWpId  The kb_page's `wp:post_parent`; '0' means no parent page
 * @return string The export path
 */
function kbSubstitutionExport(string $parentWpId): string
{
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
  <title>Example</title>
  <item>
    <title>Health Library</title>
    <content:encoded><![CDATA[]]></content:encoded>
    <wp:post_id>4478</wp:post_id>
    <wp:post_name>health-library</wp:post_name>
    <wp:post_parent>{$parentWpId}</wp:post_parent>
    <wp:status>publish</wp:status>
    <wp:post_type>page</wp:post_type>
  </item>
</channel>
</rss>
XML;

    $path = \tempnam(\sys_get_temp_dir(), 'kb-substitution-');

    if ($path === false) {
        throw new RuntimeException('Could not create the export file.');
    }

    \file_put_contents($path, $xml);

    return $path;
}

/**
 * A `KbEntryCreator` whose steps after the registration do nothing.
 *
 * `process()` itself is not overridden: the kb_page lookup, the root creation and the
 * registration between them all run as production runs them. Only work that follows the
 * registration and never touches the registry is stubbed.
 */
final class KbSubstitutionProbe extends KbEntryCreator
{
    /**
     * Build the probe with inert collaborators.
     */
    public function __construct()
    {
        // ---
        // `EntryCreator` is not reached by the steps that still run, and its own graph does not
        // resolve under Testbench; a mock with no expectations fails loudly if it ever is.
        //
        // The selector IS reached, once, by the root creation, which reads the per-site
        // settings copied onto the root's fields. It delegates that to `Extractor`, so the real
        // selector runs over the real extractor; its prompter, loader and validator are only
        // used to obtain settings interactively, which this run supplies directly.
        // ---
        $entryCreator = Mockery::mock(EntryCreator::class);
        $prompter = Mockery::mock(Prompter::class);
        $loader = Mockery::mock(Loader::class);
        $validator = Mockery::mock(Validator::class);

        if (
            ! $entryCreator instanceof EntryCreator
            || ! $prompter instanceof Prompter
            || ! $loader instanceof Loader
            || ! $validator instanceof Validator
        ) {
            throw new RuntimeException('Could not build the probe collaborators.');
        }

        parent::__construct(
            $entryCreator,
            new MinervaKbSettingsSelector(
                $prompter,
                $loader,
                $validator,
                new Extractor
            )
        );
    }

    /**
     * Skip the taxonomy term import; the export carries no terms.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  array<int|string, mixed>  $wordpressTaxonomyHandles  WordPress taxonomy handles
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output
     */
    protected function importTaxonomyTermsAtBeginning(
        XmlParser $xmlParser,
        array $wordpressTaxonomyHandles,
        string $site,
        OutputInterface $output
    ): void {}

    /**
     * Skip topic creation.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  OutputInterface  $output  Console output
     * @param  string  $site  Selected site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  array<int|string, mixed>|null  $minervaKbSettings  MinervaKB settings
     */
    protected function processTopics(
        XmlParser $xmlParser,
        OutputInterface $output,
        string $site,
        string $collectionHandle,
        ?array $minervaKbSettings = null
    ): void {}

    /**
     * Skip article creation.
     *
     * @param  XmlParser  $xmlParser  Service for parsing WordPress XML files
     * @param  OutputInterface  $output  Console output
     * @param  string  $site  Selected site handle
     * @param  AssetImporter  $assetImporter  Service for importing assets
     * @param  string  $collectionHandle  The collection handle
     */
    protected function processArticles(
        XmlParser $xmlParser,
        OutputInterface $output,
        string $site,
        AssetImporter $assetImporter,
        string $collectionHandle
    ): void {}

    /**
     * Skip the tree build.
     *
     * @param  OutputInterface  $output  Console output
     */
    protected function buildAndSaveKbTree(OutputInterface $output): void {}

    /**
     * Skip the related-articles pass.
     *
     * @param  OutputInterface  $output  Console output
     * @param  string  $site  Selected site handle
     * @param  string  $collectionHandle  The collection handle
     */
    protected function processDeferredRelatedArticles(
        OutputInterface $output,
        string $site,
        string $collectionHandle
    ): void {}
}
