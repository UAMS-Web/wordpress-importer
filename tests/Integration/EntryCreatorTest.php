<?php

declare(strict_types=1);

/**
 * Tests `EntryCreator`: which creator each content type gets, what it enables
 * and builds around an import, the tables it imports, and what it reports.
 *
 * The site and structure managers, the tree builder and the two coordinators are
 * recording stand-ins, and the content converter returns a fixed body. Waits go
 * through Laravel's `Sleep`, which every test fakes.
 *
 * @command  php vendor/bin/pest tests/Integration/EntryCreatorTest.php
 */

use Carbon\CarbonInterval;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Fields\Blueprint;
use Statamic\Stache\Stores\Store;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredProcessingCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\AdvertEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\LogoListItemEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PageEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PeopleEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PostEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\ReusableBlockEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\TablePressEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\ReusableBlocks\ReusableBlockCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * A site manager that records what it is asked to enable.
 */
final class EntryCreatorTestSiteManager extends SiteManager
{
    /**
     * Each call, as kind, site, handle and whether output was given.
     *
     * @var list<array{0: string, 1: string, 2: string, 3: bool}>
     */
    public array $calls = [];

    /**
     * Build the manager.
     */
    public function __construct() {}

    /**
     * Record the collection.
     *
     * @param  string  $site  The site
     * @param  string  $collectionHandle  The collection
     * @param  ?OutputInterface  $output  Console output
     * @return bool Always true
     */
    public function enableCollectionForSite(string $site, string $collectionHandle, ?OutputInterface $output = null): bool
    {
        $this->calls[] = ['collection', $site, $collectionHandle, $output instanceof OutputInterface];

        return true;
    }

    /**
     * Record the taxonomy.
     *
     * @param  string  $site  The site
     * @param  string  $statamicTaxonomyHandle  The taxonomy
     * @param  ?OutputInterface  $output  Console output
     * @return bool Always true
     */
    public function enableTaxonomyForSite(string $site, string $statamicTaxonomyHandle, ?OutputInterface $output = null): bool
    {
        $this->calls[] = ['taxonomy', $site, $statamicTaxonomyHandle, $output instanceof OutputInterface];

        return true;
    }
}

/**
 * A structure manager that records each structure it is asked to initialize.
 */
final class EntryCreatorTestStructureManager extends CollectionStructureManager
{
    /**
     * Each call, as collection and site.
     *
     * @var list<array{0: string, 1: string}>
     */
    public array $calls = [];

    /**
     * Record the call.
     *
     * @param  string  $collectionHandle  The collection
     * @param  string  $site  The site
     * @param  OutputInterface  $output  Console output
     */
    public function initializeStructureForSite(string $collectionHandle, string $site, OutputInterface $output): void
    {
        $this->calls[] = [$collectionHandle, $site];
    }
}

/**
 * A tree builder that records each tree it is asked to build.
 */
final class EntryCreatorTestTreeBuilder extends TreeBuilder
{
    /**
     * Each call's arguments, by name.
     *
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    /**
     * Build the builder without running its parent's constructor.
     *
     * @return self The builder
     */
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    /**
     * Record the call.
     *
     * @param  string  $site  The site
     * @param  string  $contentType  The content type
     * @param  string  $collectionHandle  The collection
     * @param  array<int|string, mixed>  $pageData  The page data
     * @param  OutputInterface  $output  Console output
     * @param  ?string  $baseBlogUrl  The blog URL
     */
    public function buildAndSaveTree(string $site, string $contentType, string $collectionHandle, array $pageData, OutputInterface $output, ?string $baseBlogUrl = null): void
    {
        $this->calls[] = ['site' => $site, 'type' => $contentType, 'collection' => $collectionHandle, 'pages' => $pageData, 'url' => $baseBlogUrl];
    }
}

/**
 * A reusable block coordinator that records each call.
 */
final class EntryCreatorTestReusableBlocks extends ReusableBlockCoordinator
{
    /**
     * The calls, by name.
     *
     * @var list<string>
     */
    public array $calls = [];

    /**
     * Build the coordinator without running its parent's constructor.
     *
     * @return self The coordinator
     */
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    /**
     * Record the parser.
     *
     * @param  XmlParser  $xmlParser  The parser
     */
    public function setXmlParser(XmlParser $xmlParser): void
    {
        $this->calls[] = 'parser';
    }

    /**
     * Record the importer.
     *
     * @param  AssetImporter  $assetImporter  The importer
     */
    public function setAssetImporter(AssetImporter $assetImporter): void
    {
        $this->calls[] = 'importer';
    }

    /**
     * Record the clearing.
     */
    public function clearWpBlockPostIds(): void
    {
        $this->calls[] = 'clear';
    }

    /**
     * Record the import.
     *
     * @param  string  $site  The site
     * @param  OutputInterface  $output  Console output
     */
    public function processReusableBlockImports(string $site, OutputInterface $output): void
    {
        $this->calls[] = "import:{$site}";
    }
}

/**
 * A deferred processing coordinator that records each call.
 */
final class EntryCreatorTestDeferred extends DeferredProcessingCoordinator
{
    /**
     * The calls, by name, with what each was given.
     *
     * @var list<array{0: string, 1: mixed}>
     */
    public array $calls = [];

    /**
     * Build the coordinator without running its parent's constructor.
     *
     * @return self The coordinator
     */
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    /**
     * Record the converter.
     *
     * @param  ContentProcessor  $contentProcessor  The converter
     */
    public function setContentProcessor(ContentProcessor $contentProcessor): void
    {
        $this->calls[] = ['converter', $contentProcessor];
    }

    /**
     * Record the form coordinator.
     *
     * @param  FormPluginCoordinator  $formPluginCoordinator  The coordinator
     */
    public function setFormPluginCoordinator(FormPluginCoordinator $formPluginCoordinator): void
    {
        $this->calls[] = ['forms', $formPluginCoordinator];
    }

    /**
     * Record the parser.
     *
     * @param  XmlParser  $xmlParser  The parser
     */
    public function setXmlParser(XmlParser $xmlParser): void
    {
        $this->calls[] = ['parser', $xmlParser];
    }

    /**
     * Record the importer.
     *
     * @param  AssetImporter  $assetImporter  The importer
     */
    public function setAssetImporter(AssetImporter $assetImporter): void
    {
        $this->calls[] = ['importer', $assetImporter];
    }

    /**
     * Record the page data.
     *
     * @param  array<int|string, mixed>  $pageData  The page data
     */
    public function setPageData(array $pageData): void
    {
        $this->calls[] = ['pages', $pageData];
    }

    /**
     * Record the pass.
     *
     * @param  string  $site  The site
     * @param  string  $collectionHandle  The collection
     * @param  OutputInterface  $output  Console output
     */
    public function processPerContentType(string $site, string $collectionHandle, OutputInterface $output): void
    {
        $this->calls[] = ['process', "{$site}:{$collectionHandle}"];
    }
}

/**
 * A content converter that returns a fixed body and the unaddressed nodes it is given.
 */
final class EntryCreatorTestConverter extends ContentProcessor
{
    /**
     * The unaddressed nodes it reports.
     *
     * @var array<string, array<array{id: string, slug: ?string, link: string, original?: string}>>
     */
    public array $nodes = [];

    /**
     * Build the converter without running `ContentProcessor`'s constructor.
     *
     * @return self The converter
     */
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    /**
     * Return a fixed body.
     *
     * @param  string  $content  The item's HTML
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  Console output
     * @param  XmlParser  $xmlParser  The parser
     * @param  string  $pageTitle  The item title
     * @param  string  $pageId  The WordPress post id
     * @param  string  $pageLink  The item URL
     * @param  array<int|string, mixed>  $attachments  Attachment elements
     * @param  array<int|string, mixed>  $terms  Term elements
     * @param  array<int|string, mixed>  $items  Items
     * @param  array<int|string, mixed>  $data  The entry data so far
     * @param  string  $collectionHandle  The collection handle
     * @param  ?string  $pageSlug  The entry slug
     * @param  ?string  $statamicEntryId  The entry id
     * @return array{content: mixed, page_builder: array<int|string, mixed>} The body
     */
    public function process(
        string $content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        XmlParser $xmlParser,
        string $pageTitle = '',
        string $pageId = '',
        string $pageLink = '',
        array $attachments = [],
        array $terms = [],
        array $items = [],
        array $data = [],
        string $collectionHandle = '',
        ?string $pageSlug = null,
        ?string $statamicEntryId = null
    ): array {
        return ['content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]]], 'page_builder' => []];
    }

    /**
     * The unaddressed nodes set on it.
     *
     * @return array<string, array<array{id: string, slug: ?string, link: string, original?: string}>> The nodes
     */
    public function getUnaddressedNodes(): array
    {
        return $this->nodes;
    }
}

/**
 * A metadata mapper that reports the unaddressed keys it is given.
 */
final class EntryCreatorTestMapper extends MetadataMapper
{
    /**
     * The unaddressed keys it reports.
     *
     * @var array<int|string, mixed>
     */
    public array $keys = [];

    /**
     * The unaddressed keys set on it.
     *
     * @return array<int|string, mixed> The keys
     */
    public function getUnaddressedKeys(): array
    {
        return $this->keys;
    }
}

/**
 * A creator that records what the coordinator asks of it.
 */
final class EntryCreatorTestStubCreator extends BaseEntryCreator
{
    /**
     * The calls, in order, with what each was given.
     *
     * @var list<array{0: string, 1: mixed}>
     */
    public array $calls = [];

    /**
     * Build the stub.
     *
     * @param  array<int|string, mixed>  $returns  What createEntries() returns
     * @param  bool  $showPeople  What hasShowPeople() returns
     */
    public function __construct(
        private readonly array $returns = [],
        private readonly bool $showPeople = false
    ) {}

    /**
     * Record the behavior.
     *
     * @param  string  $behavior  The behavior
     */
    public function setEntryOverwriteBehavior(string $behavior): void
    {
        $this->calls[] = ['entries', $behavior];
    }

    /**
     * Record the behavior.
     *
     * @param  string  $behavior  The behavior
     */
    public function setTaxonomyOverwriteBehavior(string $behavior): void
    {
        $this->calls[] = ['terms', $behavior];
    }

    /**
     * Record the call and return the page data.
     *
     * @param  string  $site  The site
     * @param  string  $collectionHandle  The collection
     * @param  XmlParser  $xmlParser  The parser
     * @param  AssetImporter  $assetImporter  The importer
     * @param  OutputInterface  $output  Console output
     * @param  MetadataMapper  $metadataMapper  The mapper
     * @param  ContentProcessor  $contentProcessor  The converter
     * @param  ?array<int|string, mixed>  $minervaKbSettings  The MinervaKB settings
     * @return array<int|string, mixed> The page data
     */
    public function createEntries(string $site, string $collectionHandle, XmlParser $xmlParser, AssetImporter $assetImporter, OutputInterface $output, MetadataMapper $metadataMapper, ContentProcessor $contentProcessor, ?array $minervaKbSettings = null): array
    {
        $this->calls[] = ['create', [$site, $collectionHandle, $xmlParser, $assetImporter, $metadataMapper, $contentProcessor, $minervaKbSettings]];

        return $this->returns;
    }

    /**
     * Whether show-people was seen.
     *
     * @return bool As built
     */
    public function hasShowPeople(): bool
    {
        return $this->showPeople;
    }

    /**
     * Some post categories.
     *
     * @return array<int|string, mixed> The categories
     */
    public function getPostCategories(): array
    {
        return ['news' => ['n']];
    }

    /**
     * Some post tags.
     *
     * @return array<int|string, mixed> The tags
     */
    public function getPostTags(): array
    {
        return ['fast' => ['f']];
    }

    /**
     * Some people groups.
     *
     * @return array<int|string, mixed> The groups
     */
    public function getPeopleGroups(): array
    {
        return ['faculty' => ['g']];
    }

    /**
     * Record the flush.
     *
     * @param  string  $site  The site
     * @param  OutputInterface  $output  Console output
     */
    public function flushDeferredTaxonomyTerms(string $site, OutputInterface $output): void
    {
        $this->calls[] = ['flush', $site];
    }

    /**
     * Record the validation.
     *
     * @param  string  $collectionHandle  The collection
     * @param  string  $site  The site
     * @param  OutputInterface  $output  Console output
     */
    public function validateTaxonomies(string $collectionHandle, string $site, OutputInterface $output): void
    {
        $this->calls[] = ['validate', "{$collectionHandle}:{$site}"];
    }
}

/**
 * An EntryCreator that hands out a stub creator when one is set.
 */
final class EntryCreatorTestRouter extends EntryCreator
{
    /**
     * The creator to hand out, or null for the real one.
     */
    public ?BaseEntryCreator $stub = null;

    /**
     * The stub, or the real creator for the type.
     *
     * @param  string  $contentType  The content type
     * @return BaseEntryCreator The creator
     */
    protected function getCreator(string $contentType): BaseEntryCreator
    {
        return $this->stub ?? parent::getCreator($contentType);
    }
}

/**
 * The creator and the stand-ins around it.
 */
final class EntryCreatorTestRig
{
    /**
     * Build the rig.
     *
     * @param  EntryCreatorTestRouter  $creator  The creator
     * @param  EntryCreatorTestSiteManager  $sites  The site manager
     * @param  EntryCreatorTestStructureManager  $structures  The structure manager
     * @param  EntryCreatorTestTreeBuilder  $trees  The tree builder
     * @param  EntryCreatorTestMapper  $mapper  The metadata mapper
     * @param  EntryCreatorTestConverter  $converter  The converter
     */
    public function __construct(
        public readonly EntryCreatorTestRouter $creator,
        public readonly EntryCreatorTestSiteManager $sites,
        public readonly EntryCreatorTestStructureManager $structures,
        public readonly EntryCreatorTestTreeBuilder $trees,
        public readonly EntryCreatorTestMapper $mapper,
        public readonly EntryCreatorTestConverter $converter
    ) {}
}

/**
 * Build a creator around recording stand-ins.
 *
 * @return EntryCreatorTestRig The rig
 */
function entryCreatorRig(): EntryCreatorTestRig
{
    $sites = new EntryCreatorTestSiteManager;
    $structures = new EntryCreatorTestStructureManager;
    $trees = EntryCreatorTestTreeBuilder::make();
    $mapper = new EntryCreatorTestMapper;
    $converter = EntryCreatorTestConverter::make();

    return new EntryCreatorTestRig(new EntryCreatorTestRouter($mapper, $converter, $sites, $structures, $trees), $sites, $structures, $trees, $mapper, $converter);
}

/**
 * A string field of a map, or a default.
 *
 * @param  array<mixed>  $map  The map
 * @param  string  $key  The field
 * @param  string  $default  The default
 * @return string The value
 */
function entryCreatorField(array $map, string $key, string $default): string
{
    $value = $map[$key] ?? $default;

    return \is_string($value) ? $value : $default;
}

/**
 * Write an export of items.
 *
 * An item takes `type` (page), `id`, `slug`, `title`, `content`, `date` and
 * `export` (a TablePress export id).
 *
 * @param  array<mixed>  $items  The items
 * @return string The export path
 */
function entryCreatorExport(array $items = []): string
{
    $xml = '';

    foreach (\array_values($items) as $index => $item) {
        $item = \is_array($item) ? $item : [];
        $id = entryCreatorField($item, 'id', (string) (700 + $index));
        $type = entryCreatorField($item, 'type', 'page');
        $export = \array_key_exists('export', $item) && \is_string($item['export'])
            ? "<wp:postmeta><wp:meta_key>_tablepress_export_table_id</wp:meta_key><wp:meta_value><![CDATA[{$item['export']}]]></wp:meta_value></wp:postmeta>"
            : '';
        $xml .= '<item>'
            .'<title><![CDATA['.entryCreatorField($item, 'title', "Item {$id}").']]></title>'
            ."<link>https://example.test/items/{$id}/</link>"
            .'<content:encoded><![CDATA['.entryCreatorField($item, 'content', $type === 'tablepress_table' ? '[["a","b"]]' : '<p>Body.</p>').']]></content:encoded>'
            .'<excerpt:encoded><![CDATA[]]></excerpt:encoded>'
            ."<wp:post_id>{$id}</wp:post_id>"
            .'<wp:post_name>'.entryCreatorField($item, 'slug', "item-{$id}").'</wp:post_name>'
            .'<wp:post_date>'.entryCreatorField($item, 'date', '2025-04-01 09:00:00').'</wp:post_date>'
            .'<wp:post_modified>2025-04-02 10:00:00</wp:post_modified>'
            .'<wp:status>publish</wp:status>'
            .'<wp:post_parent>0</wp:post_parent>'
            .'<wp:menu_order>0</wp:menu_order>'
            ."<wp:post_type>{$type}</wp:post_type>"
            .$export
            .'</item>';
    }

    $path = TemporaryFileRegistry::create('entry-creator');

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Items</title>
<link>https://example.test</link>
<wp:base_blog_url>https://example.test</wp:base_blog_url>
{$xml}
</channel>
</rss>
XML);

    return $path;
}

/**
 * A parser over an export.
 *
 * @param  string  $export  The export path
 * @return XmlParser The parser
 */
function entryCreatorParser(string $export): XmlParser
{
    $parser = new XmlParser;
    $parser->setFilePath($export);

    return $parser;
}

/**
 * Save a collection with a blueprint that has a title.
 *
 * @param  string  $handle  The collection
 * @param  list<string>  $blueprints  Its blueprints
 */
function entryCreatorCollection(string $handle, array $blueprints): void
{
    $collection = CollectionFacade::make($handle);
    $collection->sites(['default']);
    $collection->save();

    foreach ($blueprints as $name) {
        $blueprint = BlueprintFacade::make($name);
        $blueprint->setNamespace("collections.{$handle}");
        $blueprint->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
        $blueprint->save();
    }
}

/**
 * Collect every warning logged, as message and context.
 *
 * @return ArrayObject<int, array{0: mixed, 1: mixed}> The warnings, filled as they are logged
 */
function entryCreatorWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{0: mixed, 1: mixed}> $warnings */
    $warnings = new ArrayObject;
    Log::shouldReceive('warning')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($warnings): void {
        $warnings->append([$message, $context]);
    });
    Log::shouldReceive('error')->andReturnNull();
    Log::shouldReceive('info')->andReturnNull();
    Log::shouldReceive('debug')->andReturnNull();

    return $warnings;
}

/**
 * The messages of the warnings collected.
 *
 * @param  ArrayObject<int, array{0: mixed, 1: mixed}>  $warnings  The warnings
 * @return list<string> Their messages
 */
function entryCreatorMessages(ArrayObject $warnings): array
{
    $messages = [];

    foreach ($warnings as $warning) {
        $messages[] = \is_string($warning[0]) ? $warning[0] : '';
    }

    return $messages;
}

/**
 * Set the table ids the shortcode processor has collected.
 *
 * @param  list<string>  $ids  The export ids
 */
function entryCreatorTableIds(array $ids): void
{
    (new ReflectionProperty(TablePressShortcodeProcessor::class, 'tableIds'))->setValue(null, $ids);
}

/**
 * The slugs of every entry in a collection, sorted.
 *
 * @param  string  $handle  The collection
 * @return list<string> The slugs
 */
function entryCreatorSlugs(string $handle): array
{
    $slugs = [];

    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === $handle && \is_string($entry->slug())) {
            $slugs[] = $entry->slug();
        }
    }

    \sort($slugs);

    return $slugs;
}

beforeEach(function (): void {
    config([
        'statamic.search.indexes' => [],
        'wordpress-import.upload_images' => false,
        'wordpress-import.upload_files' => false,
    ]);
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);
    Sleep::fake();
    TablePressShortcodeProcessor::clearTableIds();
    BaseEntryCreator::clearTaxonomyCaches();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    TablePressShortcodeProcessor::clearTableIds();
    PeopleShortcodeProcessor::resetTermsUsed();
    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    BaseTaxonomyValidator::clearCaches();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();

    foreach (['collections.pages.basic_page', 'collections.pages.landing_page', 'collections.tables.table', 'collections.posts.post'] as $handle) {
        $blueprint = BlueprintFacade::find($handle);

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }

    File::deleteDirectory(ContentRoot::collections().'/tables');
    Sleep::fake(false);
    TemporaryFileRegistry::sweep();
    Mockery::close();
});

describe('the block lookups', function (): void {
    it('finds the page with a post id in each list, and none for another id', function (Closure $set, Closure $find): void {
        $creator = entryCreatorRig()->creator;
        $page = ['title' => 'T', 'id' => '7', 'link' => 'https://example.test/7/'];
        $set($creator, ['not a page', ['id' => 7], ['title' => 'No id'], $page, ['id' => '7', 'title' => 'Later']]);

        expect($find($creator, '7'))->toBe($page)
            ->and($find($creator, '8'))->toBeNull();
    })->with([
        'Classic' => [static fn (EntryCreator $creator, array $pages) => $creator->setClassicBlockPages($pages), static fn (EntryCreator $creator, string $id): ?array => $creator->hasClassicBlock($id)],
        'Row' => [static fn (EntryCreator $creator, array $pages) => $creator->setRowBlockPages($pages), static fn (EntryCreator $creator, string $id): ?array => $creator->hasRowBlock($id)],
        'Details' => [static fn (EntryCreator $creator, array $pages) => $creator->setDetailsBlockPages($pages), static fn (EntryCreator $creator, string $id): ?array => $creator->hasDetailsBlock($id)],
        'Freeform' => [static fn (EntryCreator $creator, array $pages) => $creator->setFreeformBlockPages($pages), static fn (EntryCreator $creator, string $id): ?array => $creator->hasFreeformBlock($id)],
    ]);

    it('finds nothing in an empty list', function (): void {
        $creator = entryCreatorRig()->creator;

        expect($creator->hasClassicBlock('7'))->toBeNull()
            ->and($creator->hasRowBlock('7'))->toBeNull()
            ->and($creator->hasDetailsBlock('7'))->toBeNull()
            ->and($creator->hasFreeformBlock('7'))->toBeNull();
    });
});

describe('what it hands out', function (): void {
    it('reports nothing from a creator it has just built', function (): void {
        $creator = entryCreatorRig()->creator;

        expect($creator->hasShowPeople('page'))->toBeFalse()
            ->and($creator->getPeopleGroups())->toBe([])
            ->and($creator->getPostCategories())->toBe([])
            ->and($creator->getPostTags())->toBe([])
            ->and($creator->getPageData())->toBe([]);
    });

    it('hands out its converter and its site manager, and takes a new site manager', function (): void {
        $rig = entryCreatorRig();
        $other = new EntryCreatorTestSiteManager;

        expect($rig->creator->getContentProcessor())->toBe($rig->converter)
            ->and($rig->creator->getSiteManager())->toBe($rig->sites);

        $rig->creator->setSiteManager($other);

        expect($rig->creator->getSiteManager())->toBe($other);
    });

    it('refuses the knowledge base and an unknown type', function (string $type, string $message): void {
        expect(fn (): bool => entryCreatorRig()->creator->hasShowPeople($type))->toThrow(Exception::class, $message);
    })->with([
        'the knowledge base' => ['kb', "KbEntryCreator should be used directly for 'kb' content type, not through EntryCreator::getCreator()"],
        'an unknown type' => ['nope', 'Unsupported content type: nope'],
    ]);

    it('builds a creator for each type it supports', function (string $type): void {
        expect(entryCreatorRig()->creator->hasShowPeople($type))->toBeFalse();
    })->with(['page', 'post', 'uamspeople', 'tshowcase', 'tablepress_table', 'logo-list-item', 'advert', 'wp_block']);
});

/**
 * Return the sole concrete Expectation inside a CompositeExpectation, so its
 * fluent methods are visible to PHPStan.
 *
 * File-local for the reason `tests/Integration/TreeBuilderBranchTest.php` gives
 * for its own copy.
 *
 * @param  LegacyMockInterface|MockInterface  $mock  The mock to expect a call on
 * @param  string  $method  The method expected to be called
 * @return Expectation The unwrapped expectation, ready to chain
 */
function entryCreatorExpects(LegacyMockInterface|MockInterface $mock, string $method): Expectation
{
    $composite = $mock->shouldReceive($method);

    if (! $composite instanceof CompositeExpectation) {
        throw new RuntimeException('Expected a Mockery CompositeExpectation.');
    }

    $expectations = (new ReflectionProperty(CompositeExpectation::class, '_expectations'))->getValue($composite);

    if (! \is_array($expectations) || \count($expectations) !== 1 || ! $expectations[0] instanceof Expectation) {
        throw new RuntimeException('Expected exactly one Mockery Expectation.');
    }

    return $expectations[0];
}

/**
 * Read a private property of an object.
 *
 * @param  object  $object  The object
 * @param  string  $property  The property
 * @return mixed Its value
 */
function entryCreatorRead(object $object, string $property): mixed
{
    return (new ReflectionProperty($object, $property))->getValue($object);
}

describe('the creator for each type', function (): void {
    it('builds the creator each type names, wired to this one', function (string $type, string $class, array $properties): void {
        $rig = entryCreatorRig();
        $creator = (new ReflectionMethod($rig->creator, 'getCreator'))->invoke($rig->creator, $type);
        $wired = [];

        foreach ($properties as $property => $expected) {
            $value = \is_object($creator) ? entryCreatorRead($creator, $property) : null;
            $wired[$property] = match ($expected) {
                'creator' => $value === $rig->creator,
                'sites' => $value === $rig->sites,
                'structures' => $value === $rig->structures,
                default => $value === $expected,
            };
        }

        expect(\is_object($creator) ? $creator::class : null)->toBe($class)
            ->and($wired)->toBe(\array_map(static fn (): bool => true, $properties));
    })->with([
        'a page' => ['page', PageEntryCreator::class, ['entryCreator' => 'creator']],
        'a post' => ['post', PostEntryCreator::class, []],
        'a UAMS person' => ['uamspeople', PeopleEntryCreator::class, ['postType' => 'uamspeople']],
        'a showcase person' => ['tshowcase', PeopleEntryCreator::class, ['postType' => 'tshowcase']],
        'a table' => ['tablepress_table', TablePressEntryCreator::class, []],
        'a logo' => ['logo-list-item', LogoListItemEntryCreator::class, ['siteManager' => 'sites', 'collectionStructureManager' => 'structures']],
        'an advert' => ['advert', AdvertEntryCreator::class, []],
        'a reusable block' => ['wp_block', ReusableBlockEntryCreator::class, []],
    ]);
});

describe('an import', function (): void {
    it('enables the collection, builds the tree from the page data, and hands the coordinators what they need', function (): void {
        entryCreatorCollection('pages', ['basic_page', 'landing_page']);
        $rig = entryCreatorRig();
        $reusable = EntryCreatorTestReusableBlocks::make();
        $deferred = EntryCreatorTestDeferred::make();
        $forms = (new ReflectionClass(FormPluginCoordinator::class))->newInstanceWithoutConstructor();
        $rig->creator->setReusableBlockCoordinator($reusable);
        $rig->creator->setDeferredProcessingCoordinator($deferred);
        $rig->creator->setFormPluginCoordinator($forms);
        $rig->creator->setEntryOverwriteBehavior('Skip matching files');
        $parser = entryCreatorParser(entryCreatorExport([['id' => '701', 'slug' => 'about']]));
        $importer = new AssetImporter;
        $output = new BufferedOutput;

        $rig->creator->createEntries('default', 'page', 'pages', $parser, $importer, $output, 'https://example.test');
        $pages = $rig->creator->getPageData();

        expect(entryCreatorSlugs('pages'))->toBe(['about'])
            ->and($pages)->toHaveKey(701)
            ->and($rig->sites->calls)->toBe([['collection', 'default', 'pages', true]])
            ->and($rig->structures->calls)->toBe([['pages', 'default']])
            ->and($rig->trees->calls)->toBe([['site' => 'default', 'type' => 'page', 'collection' => 'pages', 'pages' => $pages, 'url' => 'https://example.test']])
            ->and($reusable->calls)->toBe(['parser', 'importer', 'clear', 'import:default'])
            ->and(\array_column($deferred->calls, 0))->toBe(['converter', 'forms', 'parser', 'importer', 'pages', 'process'])
            ->and($deferred->calls[0][1])->toBe($rig->converter)
            ->and($deferred->calls[1][1])->toBe($forms)
            ->and($deferred->calls[2][1])->toBe($parser)
            ->and($deferred->calls[3][1])->toBe($importer)
            ->and($deferred->calls[4][1])->toBe($pages)
            ->and($deferred->calls[5][1])->toBe('default:pages');

        Sleep::assertSlept(static fn (CarbonInterval $duration): bool => (int) $duration->totalMicroseconds === 1500000, 1);
    });

    it('hands the creator its overwrite behaviors', function (): void {
        entryCreatorCollection('pages', ['basic_page', 'landing_page']);
        $rig = entryCreatorRig();
        $export = entryCreatorExport([['id' => '701', 'slug' => 'about', 'title' => 'First']]);
        $rig->creator->createEntries('default', 'page', 'pages', entryCreatorParser($export), new AssetImporter, new BufferedOutput);
        $rig->creator->setEntryOverwriteBehavior('Skip matching files');
        $output = new BufferedOutput;

        $rig->creator->createEntries('default', 'page', 'pages', entryCreatorParser($export), new AssetImporter, $output);

        expect($output->fetch())->toContain("Skipped entry 'about' as it already exists.");
    });

    it('enables people for a page with a show-people shortcode, and not for one without', function (string $content, array $calls, array $structures): void {
        entryCreatorCollection('pages', ['basic_page', 'landing_page']);
        $rig = entryCreatorRig();

        $rig->creator->createEntries('default', 'page', 'pages', entryCreatorParser(entryCreatorExport([['id' => '701', 'content' => $content]])), new AssetImporter, new BufferedOutput);

        expect($rig->sites->calls)->toBe($calls)
            ->and($rig->structures->calls)->toBe($structures);
    })->with([
        'a shortcode' => ['<p>[show-people group="x"]</p>', [['collection', 'default', 'pages', true], ['collection', 'default', 'people', true], ['taxonomy', 'default', 'people_groups', true]], [['pages', 'default'], ['people', 'default']]],
        'none' => ['<p>Body.</p>', [['collection', 'default', 'pages', true]], [['pages', 'default']]],
    ]);

    it('enables people for a show-people page only when the type is a page', function (): void {
        entryCreatorCollection('posts', ['post']);
        $rig = entryCreatorRig();

        $rig->creator->createEntries('default', 'post', 'posts', entryCreatorParser(entryCreatorExport([['type' => 'post', 'id' => '701', 'content' => '<p>[show-people group="x"]</p>']])), new AssetImporter, new BufferedOutput);

        expect(\array_column($rig->sites->calls, 2))->not->toContain('people');
    });

    it('enables and structures the tables collection when the content references tables', function (): void {
        entryCreatorCollection('pages', ['basic_page', 'landing_page']);
        $rig = entryCreatorRig();
        entryCreatorTableIds(['9']);

        $rig->creator->createEntries('default', 'page', 'pages', entryCreatorParser(entryCreatorExport([['id' => '701']])), new AssetImporter, new BufferedOutput);

        expect($rig->sites->calls)->toBe([['collection', 'default', 'pages', true], ['collection', 'default', 'tables', true]])
            ->and($rig->structures->calls)->toBe([['pages', 'default'], ['tables', 'default']]);
    });

    it('imports no tables and runs no coordinator for a table import', function (): void {
        entryCreatorCollection('tables', ['table']);
        $rig = entryCreatorRig();
        $reusable = EntryCreatorTestReusableBlocks::make();
        $deferred = EntryCreatorTestDeferred::make();
        $rig->creator->setReusableBlockCoordinator($reusable);
        $rig->creator->setDeferredProcessingCoordinator($deferred);
        entryCreatorTableIds(['9']);

        $rig->creator->createEntries('default', 'tablepress_table', 'tables', entryCreatorParser(entryCreatorExport([['type' => 'tablepress_table', 'id' => '801', 'export' => '9']])), new AssetImporter, new BufferedOutput);

        expect($reusable->calls)->toBe(['parser', 'importer', 'clear'])
            ->and($deferred->calls)->toBe([])
            ->and(\array_column($rig->sites->calls, 2))->toBe(['tables']);
    });

    it('enables the tables collection and its structure for the tables type, before refusing it', function (): void {
        $rig = entryCreatorRig();

        expect(fn () => $rig->creator->createEntries('default', 'tables', 'tables', entryCreatorParser(entryCreatorExport()), new AssetImporter, new BufferedOutput))->toThrow(Exception::class, 'Unsupported content type: tables')
            ->and($rig->sites->calls)->toBe([['collection', 'default', 'tables', true]])
            ->and($rig->structures->calls)->toBe([['tables', 'default']]);
    });

    it('enables an audio series taxonomy through the series manager, before refusing it', function (): void {
        $rig = entryCreatorRig();
        $handle = 'audio_series_probe';
        $config = new AudioSeriesConfig($handle, 'Probe', 'probe', 'Probe', 'probe', 'probe');
        $registered = new ReflectionProperty(AudioSeriesManager::class, 'registeredSeries');
        $before = $registered->getValue();
        $registered->setValue(null, [$config]);

        try {
            expect(fn () => $rig->creator->createEntries('default', $handle, 'unused', entryCreatorParser(entryCreatorExport()), new AssetImporter, new BufferedOutput))->toThrow(Exception::class, "Unsupported content type: {$handle}")
                ->and($rig->sites->calls)->toBe([['taxonomy', 'default', $handle, true]])
                ->and($rig->structures->calls)->toBe([]);
        } finally {
            $registered->setValue(null, $before);
        }
    });
});

describe('what an import reports', function (): void {
    it('lists the unaddressed keys once each, leaving out anything that is not a key', function (): void {
        entryCreatorCollection('posts', ['post']);
        $rig = entryCreatorRig();
        $rig->mapper->keys = ['_one', 7, '_two', '_one'];
        $output = new BufferedOutput;

        $rig->creator->createEntries('default', 'post', 'posts', entryCreatorParser(entryCreatorExport()), new AssetImporter, $output);

        expect($output->fetch())->toEndWith('Unaddressed XML keys found:'.PHP_EOL.'- _one'.PHP_EOL.'- _two'.PHP_EOL);
    });

    it('lists no keys or nodes when there are none', function (): void {
        entryCreatorCollection('posts', ['post']);
        $rig = entryCreatorRig();
        $output = new BufferedOutput;

        $rig->creator->createEntries('default', 'post', 'posts', entryCreatorParser(entryCreatorExport()), new AssetImporter, $output);

        expect($output->fetch())->not->toContain('Unaddressed');
    });

    it('lists each unaddressed node once per entry, by collection title, with a shortcode as written', function (?string $title, string $name): void {
        entryCreatorCollection('posts', ['post']);
        $collection = CollectionFacade::findByHandle('posts');
        $collection?->title($title);
        $collection?->save();
        $rig = entryCreatorRig();
        $rig->converter->nodes = [
            'figure' => [
                ['id' => '1', 'slug' => 'one', 'link' => 'https://example.test/one/'],
                ['id' => '1', 'slug' => 'again', 'link' => 'https://example.test/again/'],
                ['id' => '2', 'slug' => null, 'link' => 'https://example.test/path/two/', 'original' => '<figure>'],
                ['id' => '4', 'slug' => 'four', 'link' => 'https://example.test/elsewhere/', 'original' => '<figure>'],
            ],
            'shortcode:foo' => [
                ['id' => '3', 'slug' => 'three', 'link' => 'https://example.test/three/', 'original' => '[foo bar="1"]'],
            ],
        ];
        $output = new BufferedOutput;

        $rig->creator->createEntries('default', 'post', 'posts', entryCreatorParser(entryCreatorExport()), new AssetImporter, $output);

        expect($output->fetch())->toEndWith(
            'Unaddressed nodes found in <content:encoded>:'.PHP_EOL
            .'- figure'.PHP_EOL
            ."    - {$name} entry 'one'".PHP_EOL
            ."    - {$name} entry 'two'".PHP_EOL
            ."    - {$name} entry 'four'".PHP_EOL
            .'- shortcode:foo'.PHP_EOL
            ."    - {$name} entry 'three'".PHP_EOL
            .'        - [foo bar="1"]'.PHP_EOL
        );
    })->with([
        'a titled collection' => ['News Posts', 'News Posts'],
        'an untitled one' => ['', 'posts'],
    ]);

    it('names the collection by its handle when it does not exist', function (): void {
        $rig = entryCreatorRig();
        $rig->converter->nodes = ['figure' => [['id' => '1', 'slug' => 'one', 'link' => 'https://example.test/one/']]];
        $output = new BufferedOutput;

        $rig->creator->createEntries('default', 'post', 'missing', entryCreatorParser(entryCreatorExport()), new AssetImporter, $output);

        expect($output->fetch())->toContain("    - missing entry 'one'".PHP_EOL);
    });

    it('warns when the collection it warmed the cache for does not exist', function (): void {
        $warnings = entryCreatorWarnings();

        entryCreatorRig()->creator->createEntries('default', 'post', 'missing', entryCreatorParser(entryCreatorExport()), new AssetImporter, new BufferedOutput);

        expect(entryCreatorMessages($warnings))->toContain("EntryCreator::createEntries: Could not find 'missing' collection for cache warming");
    });

    it('warns of nothing about the cache when the collection exists', function (): void {
        entryCreatorCollection('posts', ['post']);
        $warnings = entryCreatorWarnings();

        entryCreatorRig()->creator->createEntries('default', 'post', 'posts', entryCreatorParser(entryCreatorExport()), new AssetImporter, new BufferedOutput);

        expect(\array_filter(entryCreatorMessages($warnings), static fn (string $message): bool => \str_contains($message, 'cache warming')))->toBe([]);
    });

    it('warns, and waits for nothing, when it cannot reach the entries store', function (): void {
        entryCreatorCollection('posts', ['post']);
        $warnings = entryCreatorWarnings();
        $stache = app(Statamic\Stache\Stache::class);
        entryCreatorExpects(Stache::partialMock(), 'store')->andReturnUsing(static function (string $key) use ($stache): mixed {
            // Answer null to the cache warming alone, which calls the facade from createEntries() itself
            foreach (\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $index => $frame) {
                if ($frame['function'] === '__callStatic') {
                    $caller = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[$index + 1] ?? [];

                    if (($caller['class'] ?? '') === EntryCreator::class && $caller['function'] === 'createEntries') {
                        return null;
                    }

                    break;
                }
            }

            return $stache->store($key);
        });

        entryCreatorRig()->creator->createEntries('default', 'post', 'posts', entryCreatorParser(entryCreatorExport()), new AssetImporter, new BufferedOutput);

        expect(entryCreatorMessages($warnings))->toContain('EntryCreator::createEntries: Could not access Stache store for cache warming');
        Sleep::assertNeverSlept();
    });
});

describe('tables', function (): void {
    beforeEach(function (): void {
        entryCreatorCollection('tables', ['table']);
    });

    it('needs a parser and an asset importer', function (bool $parser, bool $importer): void {
        $creator = entryCreatorRig()->creator;

        if ($parser) {
            $creator->setXmlParser(entryCreatorParser(entryCreatorExport()));
        }

        if ($importer) {
            $creator->setAssetImporter(new AssetImporter);
        }

        expect(fn () => $creator->importTables('default', new BufferedOutput))->toThrow(Exception::class, 'XML parser or asset importer not set for table imports.');
    })->with([
        'neither' => [false, false],
        'only a parser' => [true, false],
        'only an importer' => [false, true],
    ]);

    it('imports every table for a table import, or says there are none', function (array $items, array $slugs, string $line): void {
        $creator = entryCreatorRig()->creator;
        $creator->setXmlParser(entryCreatorParser(entryCreatorExport($items)));
        $creator->setAssetImporter(new AssetImporter);
        $output = new BufferedOutput;

        $creator->importTables('default', $output, 'tablepress_table');

        expect(entryCreatorSlugs('tables'))->toBe($slugs)
            ->and($output->fetch())->toEndWith($line.PHP_EOL);
    })->with([
        'two tables' => [[['type' => 'tablepress_table', 'id' => '801', 'export' => '9'], ['type' => 'tablepress_table', 'id' => '802', 'export' => '10']], ['default_tablepress-10', 'default_tablepress-9'], 'Imported 2 table(s).'],
        'none' => [[['id' => '701']], [], 'No tablepress_table items found in XML; skipping table imports.'],
    ]);

    it('imports nothing for other content when no table is referenced', function (): void {
        $creator = entryCreatorRig()->creator;
        $creator->setXmlParser(entryCreatorParser(entryCreatorExport([['type' => 'tablepress_table', 'id' => '801', 'export' => '9']])));
        $creator->setAssetImporter(new AssetImporter);
        $output = new BufferedOutput;

        $creator->importTables('default', $output, 'page');

        expect(entryCreatorSlugs('tables'))->toBe([])
            ->and($output->fetch())->toBe('');
    });

    it('imports each referenced table once, the latest of each export id, or the first when none has a date', function (): void {
        $creator = entryCreatorRig()->creator;
        $creator->setXmlParser(entryCreatorParser(entryCreatorExport([
            ['type' => 'tablepress_table', 'id' => '801', 'export' => '9', 'title' => 'Old nine', 'date' => '2024-01-01 00:00:00'],
            ['type' => 'tablepress_table', 'id' => '802', 'export' => '9', 'title' => 'New nine', 'date' => '2025-01-01 00:00:00'],
            ['type' => 'tablepress_table', 'id' => '803', 'export' => '9', 'title' => 'Middle nine', 'date' => '2024-06-01 00:00:00'],
            ['type' => 'tablepress_table', 'id' => '804', 'export' => '10', 'title' => 'First ten', 'date' => 'not a date'],
            ['type' => 'tablepress_table', 'id' => '805', 'export' => '10', 'title' => 'Second ten', 'date' => ''],
            ['type' => 'tablepress_table', 'id' => '806', 'export' => '11', 'title' => 'Unreferenced'],
            ['type' => 'tablepress_table', 'id' => '807', 'title' => 'No export id'],
            ['type' => 'tablepress_table', 'id' => '808', 'export' => '12', 'title' => 'Undated twelve', 'date' => ''],
            ['type' => 'tablepress_table', 'id' => '809', 'export' => '12', 'title' => 'Dated twelve', 'date' => '2020-01-01 00:00:00'],
        ])));
        $creator->setAssetImporter(new AssetImporter);
        entryCreatorTableIds(['9', '10', '9', '12']);
        $output = new BufferedOutput;

        $creator->importTables('default', $output, 'page');
        $titles = [];

        foreach (EntryFacade::all() as $entry) {
            if ($entry instanceof Entry && $entry->collectionHandle() === 'tables') {
                $titles[] = $entry->get('title');
            }
        }

        \sort($titles);

        expect($titles)->toBe(['Dated twelve', 'First ten', 'New nine'])
            ->and($output->fetch())->toContain('Progress: tablepress_table 3 of 3 (100%)');
    });

    it('reports a table whose file appeared, and removes one at the old slug', function (): void {
        $creator = entryCreatorRig()->creator;
        $creator->setXmlParser(entryCreatorParser(entryCreatorExport([['type' => 'tablepress_table', 'id' => '801', 'export' => '9']])));
        $creator->setAssetImporter(new AssetImporter);
        entryCreatorTableIds(['9']);
        $expected = ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-9');
        $old = ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-9-801');
        File::ensureDirectoryExists(\dirname($old));
        File::put($old, "---\ntitle: Old\n---\n");
        entryCreatorExpects(File::partialMock(), 'exists')->andReturnUsing(static fn (string $path): bool => $path === $expected || \file_exists($path));
        $output = new BufferedOutput;

        $creator->importTables('default', $output, 'page');

        expect($output->fetch())->toContain('  - Table entry imported successfully.'.PHP_EOL)
            ->and(File::exists($old))->toBeFalse()
            ->and(ImportWarningManager::getWarningsByCategory('content'))->toBe([]);
        Sleep::assertSleptTimes(1);
        Sleep::assertSlept(static fn (CarbonInterval $duration): bool => (int) $duration->totalMicroseconds === 300000, 1);
    });

    it('reports a table whose file never appeared, after five tries', function (): void {
        $warnings = entryCreatorWarnings();
        $creator = entryCreatorRig()->creator;
        $creator->setXmlParser(entryCreatorParser(entryCreatorExport([['type' => 'tablepress_table', 'id' => '801', 'export' => '9']])));
        $creator->setAssetImporter(new AssetImporter);
        entryCreatorTableIds(['9']);
        $expected = ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-9');
        $output = new BufferedOutput;

        $creator->importTables('default', $output, 'page');
        $context = null;

        foreach ($warnings as $warning) {
            if (\is_string($warning[0]) && \str_starts_with($warning[0], 'EntryCreator::importTables: Entry file not found after retries')) {
                $context = $warning[1];
            }
        }

        $warning = ImportWarningManager::getWarnings('content', 'table_entry_file_missing')[0] ?? null;

        expect($output->fetch())->toContain("  - Table entry saved, but no file appeared at {$expected} after 5 attempts.".PHP_EOL)
            ->and(\is_array($context) ? \array_keys($context) : null)->toBe(['site', 'entryId', 'path', 'entry_url'])
            ->and(\is_array($context) ? [$context['site'], $context['path']] : null)->toBe(['default', $expected])
            ->and(\is_array($context) && \is_string($context['entryId']) ? $context['entryId'] : '')->toStartWith('default_tablepress-9-801-')
            ->and(\is_array($warning) ? \array_diff_key($warning, ['entry_id' => 1]) : null)->toBe([
                'site' => 'default',
                'path' => $expected,
                'attempts' => 5,
                'reason' => 'The table entry was saved through Statamic, but no file appeared at the canonical path. The entry most likely landed under a different slug.',
            ])
            ->and(\is_array($warning) && \is_string($warning['entry_id'] ?? null) ? $warning['entry_id'] : '')->toStartWith('default_tablepress-9-801-');
        Sleep::assertSleptTimes(6);
        Sleep::assertSlept(static fn (CarbonInterval $duration): bool => (int) $duration->totalMicroseconds === 300000, 6);
    });

    it('skips a table when the parser has no file', function (): void {
        $parser = new XmlParser;
        $parser->setItems([kbItemFromExport(entryCreatorExport([['type' => 'tablepress_table', 'id' => '801', 'export' => '9']]))]);
        $creator = entryCreatorRig()->creator;
        $creator->setXmlParser($parser);
        $creator->setAssetImporter(new AssetImporter);
        entryCreatorTableIds(['9']);
        $output = new BufferedOutput;

        $creator->importTables('default', $output, 'page');

        expect(entryCreatorSlugs('tables'))->toBe([])
            ->and($output->fetch())->not->toContain('Table entry');
        Sleep::assertNeverSlept();
    });
});

/**
 * The first item of an export, as an element.
 *
 * @param  string  $export  The export path
 * @return SimpleXMLElement The item
 */
function kbItemFromExport(string $export): SimpleXMLElement
{
    $rss = \simplexml_load_string((string) \file_get_contents($export));
    $items = $rss === false ? false : $rss->xpath('//item');
    $item = \is_array($items) ? ($items[0] ?? null) : null;

    if (! $item instanceof SimpleXMLElement) {
        throw new RuntimeException('The export has no item.');
    }

    return $item;
}

describe('what it asks of the creator', function (): void {
    it('configures the creator, has it import, then flushes and validates its terms', function (): void {
        $rig = entryCreatorRig();
        $stub = new EntryCreatorTestStubCreator([7 => ['entry' => 'e']]);
        $rig->creator->stub = $stub;
        $rig->creator->setEntryOverwriteBehavior('Create an additional file');
        $rig->creator->setTaxonomyOverwriteBehavior('Skip matching terms');
        $parser = entryCreatorParser(entryCreatorExport());
        $importer = new AssetImporter;

        $rig->creator->createEntries('default', 'post', 'posts', $parser, $importer, new BufferedOutput, null, ['kb_page' => '5']);

        expect(\array_column($stub->calls, 0))->toBe(['entries', 'terms', 'create', 'flush', 'validate'])
            ->and($stub->calls[0][1])->toBe('Create an additional file')
            ->and($stub->calls[1][1])->toBe('Skip matching terms')
            ->and($stub->calls[2][1])->toBe(['default', 'posts', $parser, $importer, $rig->mapper, $rig->converter, ['kb_page' => '5']])
            ->and($stub->calls[3][1])->toBe('default')
            ->and($stub->calls[4][1])->toBe('posts:default')
            ->and($rig->creator->getPageData())->toBe([7 => ['entry' => 'e']]);
    });

    it('reports what the creator for each type reports', function (): void {
        $rig = entryCreatorRig();
        $rig->creator->stub = new EntryCreatorTestStubCreator(showPeople: true);

        expect($rig->creator->hasShowPeople('page'))->toBeTrue()
            ->and($rig->creator->getPeopleGroups())->toBe(['faculty' => ['g']])
            ->and($rig->creator->getPostCategories())->toBe(['news' => ['n']])
            ->and($rig->creator->getPostTags())->toBe(['fast' => ['f']]);
    });

    it('clears and warms the entries store after the import', function (): void {
        entryCreatorCollection('posts', ['post']);
        $rig = entryCreatorRig();
        $rig->creator->stub = new EntryCreatorTestStubCreator;
        $store = Mockery::mock(Store::class);
        entryCreatorExpects($store, 'clear')->once();
        entryCreatorExpects($store, 'warm')->once();
        $stache = app(Statamic\Stache\Stache::class);
        entryCreatorExpects(Stache::partialMock(), 'store')->andReturnUsing(static function (string $key) use ($stache, $store): mixed {
            foreach (\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $index => $frame) {
                if ($frame['function'] === '__callStatic') {
                    $caller = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[$index + 1] ?? [];

                    if (($caller['class'] ?? '') === EntryCreator::class && $caller['function'] === 'createEntries') {
                        return $store;
                    }

                    break;
                }
            }

            return $stache->store($key);
        });

        $rig->creator->createEntries('default', 'post', 'posts', entryCreatorParser(entryCreatorExport()), new AssetImporter, new BufferedOutput);
    });

    it('logs the site, and the collection, with each cache warning', function (bool $store, string $phrase, array $context): void {
        $warnings = entryCreatorWarnings();
        $rig = entryCreatorRig();
        $rig->creator->stub = new EntryCreatorTestStubCreator;

        if (! $store) {
            $stache = app(Statamic\Stache\Stache::class);
            entryCreatorExpects(Stache::partialMock(), 'store')->andReturnUsing(static function (string $key) use ($stache): mixed {
                foreach (\debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS) as $index => $frame) {
                    if ($frame['function'] === '__callStatic') {
                        $caller = \debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS)[$index + 1] ?? [];

                        if (($caller['class'] ?? '') === EntryCreator::class && $caller['function'] === 'createEntries') {
                            return null;
                        }

                        break;
                    }
                }

                return $stache->store($key);
            });
        }

        $rig->creator->createEntries('default', 'post', 'missing', entryCreatorParser(entryCreatorExport()), new AssetImporter, new BufferedOutput);
        $found = null;

        foreach ($warnings as $warning) {
            if (\is_string($warning[0]) && \str_contains($warning[0], $phrase)) {
                $found = $warning[1];
            }
        }

        expect($found)->toBe($context);
    })->with([
        'a missing collection' => [true, "Could not find 'missing' collection", ['site' => 'default', 'collection_handle' => 'missing']],
        'no store' => [false, 'Could not access Stache store', ['site' => 'default']],
    ]);

    it('imports the tables the content references', function (): void {
        entryCreatorCollection('tables', ['table']);
        $rig = entryCreatorRig();
        $rig->creator->stub = new EntryCreatorTestStubCreator;
        entryCreatorTableIds(['9']);

        $rig->creator->createEntries('default', 'post', 'posts', entryCreatorParser(entryCreatorExport([['type' => 'tablepress_table', 'id' => '801', 'export' => '9']])), new AssetImporter, new BufferedOutput);

        expect(entryCreatorSlugs('tables'))->toBe(['default_tablepress-9']);
    });
});

describe('tables, more closely', function (): void {
    beforeEach(function (): void {
        entryCreatorCollection('tables', ['table']);
    });

    it('hands the table creator its overwrite behavior', function (): void {
        $creator = entryCreatorRig()->creator;
        $creator->setXmlParser(entryCreatorParser(entryCreatorExport([['type' => 'tablepress_table', 'id' => '801', 'export' => '9']])));
        $creator->setAssetImporter(new AssetImporter);
        $creator->importTables('default', new BufferedOutput, 'tablepress_table');
        $creator->setTableOverwriteBehavior('Skip matching files');
        $output = new BufferedOutput;

        $creator->importTables('default', $output, 'tablepress_table');

        expect($output->fetch())->toContain("Skipped entry 'default_tablepress-9' as it already exists.");
    });

    it('matches an export id exactly, and keeps the first of two tables with the same date', function (): void {
        $creator = entryCreatorRig()->creator;
        $creator->setXmlParser(entryCreatorParser(entryCreatorExport([
            ['type' => 'tablepress_table', 'id' => '801', 'export' => '09', 'title' => 'Padded'],
            ['type' => 'tablepress_table', 'id' => '802', 'export' => '9', 'title' => 'First', 'date' => '2025-01-01 00:00:00'],
            ['type' => 'tablepress_table', 'id' => '803', 'export' => '9', 'title' => 'Same date', 'date' => '2025-01-01 00:00:00'],
            ['type' => 'tablepress_table', 'id' => '804', 'export' => '13', 'title' => 'Unreadable', 'date' => 'not a date'],
            ['type' => 'tablepress_table', 'id' => '805', 'export' => '13', 'title' => 'Readable', 'date' => '2019-01-01 00:00:00'],
        ])));
        $creator->setAssetImporter(new AssetImporter);
        entryCreatorTableIds(['9', '13']);

        $creator->importTables('default', new BufferedOutput, 'page');
        $titles = [];

        foreach (EntryFacade::all() as $entry) {
            if ($entry instanceof Entry && $entry->collectionHandle() === 'tables') {
                $titles[] = $entry->get('title');
            }
        }

        \sort($titles);

        expect($titles)->toBe(['First', 'Readable']);
    });
});

describe('the old slug', function (): void {
    beforeEach(function (): void {
        entryCreatorCollection('tables', ['table']);
    });

    it('leaves no file at the old slug after the import', function (): void {
        $creator = entryCreatorRig()->creator;
        $creator->setXmlParser(entryCreatorParser(entryCreatorExport([['type' => 'tablepress_table', 'id' => '801', 'export' => '9']])));
        $creator->setAssetImporter(new AssetImporter);
        entryCreatorTableIds(['9']);
        $old = ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-9-801');
        File::ensureDirectoryExists(\dirname($old));
        File::put($old, "---\ntitle: Old\n---\n");

        $creator->importTables('default', new BufferedOutput, 'page');

        expect(File::exists($old))->toBeFalse();
    });
});

describe('what the review asked about', function (): void {
    beforeEach(function (): void {
        entryCreatorCollection('tables', ['table']);
    });

    it('leaves the run pointed at the export after importing tables', function (): void {
        $export = entryCreatorExport([['type' => 'tablepress_table', 'id' => '801', 'export' => '9']]);
        $creator = entryCreatorRig()->creator;
        $creator->setXmlParser(entryCreatorParser($export));
        $creator->setAssetImporter(new AssetImporter);
        entryCreatorTableIds(['9']);

        $creator->importTables('default', new BufferedOutput, 'page');

        expect(ProcessingContext::getXmlPath())->toBe($export);
    });

    it('removes the old-slug file of a table whose export id has a hyphen', function (): void {
        $creator = entryCreatorRig()->creator;
        $creator->setXmlParser(entryCreatorParser(entryCreatorExport([['type' => 'tablepress_table', 'id' => '801', 'export' => 'my-table']])));
        $creator->setAssetImporter(new AssetImporter);
        entryCreatorTableIds(['my-table']);
        $old = ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-my-table-801');
        File::ensureDirectoryExists(\dirname($old));
        File::put($old, "---\ntitle: Old\n---\n");

        $creator->importTables('default', new BufferedOutput, 'page');

        expect(File::exists($old))->toBeFalse();
    });
});
