<?php

declare(strict_types=1);

/**
 * Tests `KbEntryCreator`: the knowledge base root, its topics and articles,
 * the tree they are saved into, and the related articles resolved after.
 *
 * Every case runs the production code over a written export, into a real
 * `knowledge_bases` collection whose structure has no root, as the host's does.
 * The `EntryCreator` is a mock the import never reaches, and the MinervaKB
 * settings selector runs over the real extractor.
 *
 * @command  php vendor/bin/pest tests/Integration/KbEntryCreatorTest.php
 */

use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Statamic\Events\CollectionSaved;
use Statamic\Events\CollectionTreeSaved;
use Statamic\Events\EntrySaving;
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
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredKnowledgeBaseMounts;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\KbPageSubstitutions;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Extractor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Prompter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Validator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\FakeAssetImporter;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * A `KbEntryCreator` that exposes its protected steps and state.
 */
final class KbEntryTestCreator extends KbEntryCreator
{
    /**
     * The taxonomy handles each term import was asked for.
     *
     * @var list<array<int|string, mixed>>
     */
    public array $termImports = [];

    /**
     * What runs in place of the term import, or null to run it.
     */
    public ?Closure $termImport = null;

    /**
     * Whether saving an entry saves nothing and returns null.
     */
    public bool $saveFails = false;

    /**
     * Build the creator with a mock `EntryCreator` and the real settings extractor.
     */
    public function __construct()
    {
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
            throw new RuntimeException('Could not build the creator collaborators.');
        }

        parent::__construct(
            $entryCreator,
            new MinervaKbSettingsSelector($prompter, $loader, $validator, new Extractor)
        );
    }

    /**
     * Record the handles, then import the terms.
     *
     * @param  XmlParser  $xmlParser  The parser
     * @param  array<int|string, mixed>  $wordpressTaxonomyHandles  The handles
     * @param  string  $site  The site
     * @param  OutputInterface  $output  The output
     */
    protected function importTaxonomyTermsAtBeginning(
        XmlParser $xmlParser,
        array $wordpressTaxonomyHandles,
        string $site,
        OutputInterface $output
    ): void {
        $this->termImports[] = $wordpressTaxonomyHandles;

        if ($this->termImport instanceof Closure) {
            ($this->termImport)();

            return;
        }

        parent::importTaxonomyTermsAtBeginning($xmlParser, $wordpressTaxonomyHandles, $site, $output);
    }

    /**
     * Save an entry, unless saving is set to fail.
     *
     * @param  string  $collectionHandle  The collection
     * @param  string  $site  The site
     * @param  string  $entrySlug  The slug
     * @param  array<int|string, mixed>  $data  The data
     * @param  OutputInterface  $output  The output
     * @param  ?DateTime  $entryDate  The date
     * @param  ?bool  $published  Whether published
     * @param  ?string  $postDateStr  The post date
     * @return ?Entry The entry saved, or null
     */
    protected function saveEntry(
        string $collectionHandle,
        string $site,
        string $entrySlug,
        array $data,
        OutputInterface $output,
        ?DateTime $entryDate = null,
        ?bool $published = null,
        ?string $postDateStr = null
    ): ?Entry {
        if ($this->saveFails) {
            return null;
        }

        return parent::saveEntry($collectionHandle, $site, $entrySlug, $data, $output, $entryDate, $published, $postDateStr);
    }

    /**
     * Call a protected method.
     *
     * @param  string  $method  The method
     * @param  mixed  ...$arguments  Its arguments
     * @return mixed What it returns
     */
    public function call(string $method, mixed ...$arguments): mixed
    {
        return (new ReflectionMethod($this, $method))->invoke($this, ...$arguments);
    }

    /**
     * Read a protected property.
     *
     * @param  string  $property  The property
     * @return mixed Its value
     */
    public function read(string $property): mixed
    {
        return (new ReflectionProperty($this, $property))->getValue($this);
    }

    /**
     * Set a protected property.
     *
     * @param  string  $property  The property
     * @param  mixed  $value  Its value
     */
    public function write(string $property, mixed $value): void
    {
        (new ReflectionProperty($this, $property))->setValue($this, $value);
    }
}

beforeEach(function (): void {
    config([
        'statamic.search.indexes' => [],
        'wordpress-import.upload_images' => false,
        'wordpress-import.upload_files' => false,
        'wordpress-import.import_tags' => true,
    ]);
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    kbEntryCollection();

    foreach (['knowledge_base', 'topic', 'article'] as $handle) {
        kbEntryBlueprint($handle);
    }

    BaseEntryCreator::clearTaxonomyCaches();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    KbLinkRegistry::clear();
    DeferredKnowledgeBaseMounts::clear();
    KbPageSubstitutions::clear();
    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    BaseTaxonomyValidator::clearCaches();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearStatamicEntryId();
    ProcessingContext::clearCollectionHandle();
    ProcessingContext::clearEntryData();
    ProcessingContext::setMinervaKbSettings(null);

    foreach (['knowledge_base', 'topic', 'article'] as $handle) {
        $blueprint = BlueprintFacade::find("collections.knowledge_bases.{$handle}");

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }

    TemporaryFileRegistry::sweep();
    Mockery::close();
});

/**
 * Make the `knowledge_bases` collection.
 *
 * @param  bool  $dated  Whether it is dated
 * @param  bool  $structured  Whether it has a structure
 */
function kbEntryCollection(bool $dated = false, bool $structured = true): void
{
    $collection = CollectionFacade::make('knowledge_bases');
    $collection->sites(['default']);
    $collection->dated($dated);

    if ($structured) {
        $collection->structureContents(['root' => false]);
    }

    $collection->save();
}

/**
 * Save a `knowledge_bases` blueprint, with defaults for some fields.
 *
 * @param  string  $handle  The blueprint
 * @param  array<string, mixed>  $defaults  Default values by field
 */
function kbEntryBlueprint(string $handle, array $defaults = []): void
{
    $fields = [['handle' => 'title', 'field' => ['type' => 'text']]];

    foreach ($defaults as $field => $default) {
        $fields[] = ['handle' => $field, 'field' => ['type' => 'text', 'default' => $default]];
    }

    $blueprint = BlueprintFacade::make($handle);
    $blueprint->setNamespace('collections.knowledge_bases');
    $blueprint->setContents(['fields' => $fields]);
    $blueprint->save();
}

/**
 * A string field of a map, or a default.
 *
 * @param  array<mixed>  $map  The map
 * @param  string  $key  The field
 * @param  string  $default  The default
 * @return string The value
 */
function kbEntryField(array $map, string $key, string $default): string
{
    $value = $map[$key] ?? $default;

    return \is_string($value) ? $value : $default;
}

/**
 * A list field of a map.
 *
 * @param  array<mixed>  $map  The map
 * @param  string  $key  The field
 * @return array<int|string, mixed> The values
 */
function kbEntryList(array $map, string $key): array
{
    $value = $map[$key] ?? [];

    return \is_array($value) ? $value : [];
}

/**
 * The `wp:postmeta` or `wp:termmeta` elements for a map.
 *
 * A value may instead be a key and value pair, so a key can repeat.
 *
 * @param  array<int|string, mixed>  $meta  Values by key, or key and value pairs
 * @param  string  $element  The element name
 * @return string The elements
 */
function kbEntryMeta(array $meta, string $element = 'wp:postmeta'): string
{
    $xml = '';

    foreach ($meta as $key => $value) {
        if (\is_array($value)) {
            $key = \is_string($value[0] ?? null) ? $value[0] : '';
            $value = $value[1] ?? '';
        }

        $text = \is_string($value) ? $value : '';
        $xml .= "<{$element}><wp:meta_key>{$key}</wp:meta_key><wp:meta_value><![CDATA[{$text}]]></wp:meta_value></{$element}>";
    }

    return $xml;
}

/**
 * Write an export of terms and items.
 *
 * A term takes `id`, `taxonomy` (kbtopic), `slug`, `name`, `parent` (a slug),
 * `description` and `meta`. An item takes `type` (kb), `id`, `title`, `slug`,
 * `status`, `content`, `excerpt`, `link`, `date`, `parent`, `url` (an
 * attachment's), `categories` (each `domain`, `nicename`, `name`) and `meta`.
 * A key set to null leaves its element out.
 *
 * @param  array<mixed>  $terms  The terms
 * @param  array<mixed>  $items  The items
 * @return string The export path
 */
function kbEntryExport(array $terms = [], array $items = []): string
{
    $termXml = '';

    foreach ($terms as $term) {
        $term = \is_array($term) ? $term : [];
        $id = kbEntryField($term, 'id', '1');
        $description = \array_key_exists('description', $term) && \is_string($term['description'])
            ? "<wp:term_description><![CDATA[{$term['description']}]]></wp:term_description>"
            : '';
        $termXml .= '<wp:term><wp:term_id>'.$id.'</wp:term_id>'
            .'<wp:term_taxonomy>'.kbEntryField($term, 'taxonomy', 'kbtopic').'</wp:term_taxonomy>'
            .'<wp:term_slug>'.kbEntryField($term, 'slug', "topic-{$id}").'</wp:term_slug>'
            .'<wp:term_parent>'.kbEntryField($term, 'parent', '').'</wp:term_parent>'
            .'<wp:term_name><![CDATA['.kbEntryField($term, 'name', "Topic {$id}").']]></wp:term_name>'
            .$description
            .kbEntryMeta(kbEntryList($term, 'meta'), 'wp:termmeta')
            .'</wp:term>';
    }

    $itemXml = '';

    foreach (\array_values($items) as $index => $item) {
        $item = \is_array($item) ? $item : [];
        $id = kbEntryField($item, 'id', (string) (900 + $index));
        $type = kbEntryField($item, 'type', 'kb');
        $element = static fn (string $key, string $name, string $default): string => \array_key_exists($key, $item) && $item[$key] === null
            ? ''
            : "<{$name}>".kbEntryField($item, $key, $default)."</{$name}>";
        $categories = '';

        foreach (kbEntryList($item, 'categories') as $category) {
            $category = \is_array($category) ? $category : [];
            $categories .= '<category domain="'.kbEntryField($category, 'domain', 'kbtopic').'" nicename="'.kbEntryField($category, 'nicename', '').'"><![CDATA['.kbEntryField($category, 'name', '').']]></category>';
        }

        $itemXml .= '<item>'
            .'<title><![CDATA['.kbEntryField($item, 'title', "Article {$id}").']]></title>'
            .'<link>'.kbEntryField($item, 'link', "https://example.test/kb/{$id}/").'</link>'
            .'<content:encoded><![CDATA['.kbEntryField($item, 'content', '<p>Body.</p>').']]></content:encoded>'
            .'<excerpt:encoded><![CDATA['.kbEntryField($item, 'excerpt', '').']]></excerpt:encoded>'
            ."<wp:post_id>{$id}</wp:post_id>"
            .$element('slug', 'wp:post_name', "article-{$id}")
            .$element('date', 'wp:post_date', '2025-04-01 09:00:00')
            .$element('modified', 'wp:post_modified', '2025-04-02 10:00:00')
            .$element('status', 'wp:status', 'publish')
            .'<wp:post_parent>'.kbEntryField($item, 'parent', '0').'</wp:post_parent>'
            ."<wp:post_type>{$type}</wp:post_type>"
            .$element('url', 'wp:attachment_url', "https://example.test/files/{$id}.pdf")
            .$categories
            .kbEntryMeta(kbEntryList($item, 'meta'))
            .'</item>';
    }

    $path = TemporaryFileRegistry::create('kb-entry');

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Knowledge base</title>
<link>https://example.test</link>
<wp:base_blog_url>https://example.test</wp:base_blog_url>
{$termXml}
{$itemXml}
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
function kbEntryParser(string $export): XmlParser
{
    $parser = new XmlParser;
    $parser->setFilePath($export);

    return $parser;
}

/**
 * Run `process()` over an export.
 *
 * @param  string  $export  The export path
 * @param  ?array<int|string, mixed>  $settings  The MinervaKB settings
 * @param  ?KbEntryTestCreator  $creator  The creator, or null for a new one
 * @param  ?AssetImporter  $assetImporter  The asset importer, or null for a real one
 * @param  ?SiteManager  $siteManager  The site manager, or null for one that ignores every call
 * @param  ?CollectionStructureManager  $structureManager  The structure manager, or null for one that ignores every call
 * @return array{output: string, creator: KbEntryTestCreator} The console and the creator
 */
function kbEntryRun(string $export, ?array $settings = null, ?KbEntryTestCreator $creator = null, ?AssetImporter $assetImporter = null, ?SiteManager $siteManager = null, ?CollectionStructureManager $structureManager = null): array
{
    $creator ??= new KbEntryTestCreator;
    $output = new BufferedOutput;
    $siteManager ??= Mockery::mock(SiteManager::class)->shouldIgnoreMissing();
    $structureManager ??= Mockery::mock(CollectionStructureManager::class)->shouldIgnoreMissing();

    if (! $siteManager instanceof SiteManager || ! $structureManager instanceof CollectionStructureManager) {
        throw new RuntimeException('Could not build the run collaborators.');
    }

    $creator->process(kbEntryParser($export), $assetImporter ?? new AssetImporter, $output, 'https://example.test', 'default', $siteManager, $structureManager, $settings);

    return ['output' => $output->fetch(), 'creator' => $creator];
}

/**
 * The entry in `knowledge_bases` with a slug.
 *
 * @param  string  $slug  The slug
 * @return Entry The entry
 */
function kbEntry(string $slug): Entry
{
    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'knowledge_bases' && $entry->slug() === $slug) {
            return $entry;
        }
    }

    throw new RuntimeException("No knowledge base entry '{$slug}'.");
}

/**
 * The id of the entry in `knowledge_bases` with a slug.
 *
 * @param  string  $slug  The slug
 * @return string The id
 */
function kbEntryId(string $slug): string
{
    $id = kbEntry($slug)->id();

    return \is_string($id) ? $id : '';
}

/**
 * Save an entry into `knowledge_bases` whose id is its slug.
 *
 * @param  string  $slug  The slug and id
 */
function kbEntryMake(string $slug): void
{
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new RuntimeException('Could not make an entry.');
    }

    $entry->collection('knowledge_bases');
    $entry->locale('default');
    $entry->slug($slug);
    $entry->id($slug);
    $entry->data(['title' => \ucfirst($slug)]);
    $entry->save();
}

/**
 * The `knowledge_bases` tree for a site.
 *
 * @param  string  $site  The site
 * @return ?Tree The tree, or null when the site has none
 */
function kbEntryTreeFor(string $site): ?Tree
{
    $structure = CollectionFacade::findByHandle('knowledge_bases')?->structure();
    $tree = $structure instanceof CollectionStructure ? $structure->in($site) : null;

    return $tree instanceof Tree ? $tree : null;
}

/**
 * The slugs of every `knowledge_bases` entry, sorted.
 *
 * @return list<string> The slugs
 */
function kbEntrySlugs(): array
{
    $slugs = [];

    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'knowledge_bases' && \is_string($entry->slug())) {
            $slugs[] = $entry->slug();
        }
    }

    \sort($slugs);

    return $slugs;
}

/**
 * An entry's data, as an array.
 *
 * @param  Entry  $entry  The entry
 * @return array<int|string, mixed> Its data
 */
function kbEntryData(Entry $entry): array
{
    $data = $entry->data();

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * The saved `knowledge_bases` tree for the default site, by slug.
 *
 * @return list<mixed> The tree, each node its slug and children
 */
function kbEntryTree(): array
{
    $tree = kbEntryTreeFor('default');
    $nodes = $tree instanceof Tree ? $tree->tree() : [];

    return kbEntryTreeSlugs(\is_array($nodes) ? $nodes : []);
}

/**
 * Tree nodes with their entry ids read as slugs.
 *
 * @param  array<int|string, mixed>  $nodes  The nodes
 * @return list<mixed> The nodes by slug
 */
function kbEntryTreeSlugs(array $nodes): array
{
    $slugs = [];

    foreach ($nodes as $node) {
        $node = \is_array($node) ? $node : [];
        $id = $node['entry'] ?? null;
        $entry = \is_string($id) ? EntryFacade::find($id) : null;
        $slug = $entry instanceof Entry ? $entry->slug() : $id;
        $children = $node['children'] ?? null;
        $key = \is_string($slug) ? $slug : '';
        $slugs[] = \is_array($children) ? [$key => kbEntryTreeSlugs($children)] : $key;
    }

    return $slugs;
}

/**
 * Collect every warning and error logged, as message and context.
 *
 * @return ArrayObject<int, array{0: mixed, 1: mixed}> The messages, filled as they are logged
 */
function kbEntryWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{0: mixed, 1: mixed}> $warnings */
    $warnings = new ArrayObject;
    Log::shouldReceive('warning')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($warnings): void {
        $warnings->append([$message, $context]);
    });
    Log::shouldReceive('error')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($warnings): void {
        $warnings->append(['ERROR '.(\is_string($message) ? $message : ''), $context]);
    });
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
function kbEntryMessages(ArrayObject $warnings): array
{
    $messages = [];

    foreach ($warnings as $warning) {
        $messages[] = \is_string($warning[0]) ? $warning[0] : '';
    }

    return $messages;
}

/**
 * The context of the first warning whose message contains a phrase.
 *
 * @param  ArrayObject<int, array{0: mixed, 1: mixed}>  $warnings  The warnings
 * @param  string  $phrase  The phrase
 * @return mixed Its context, or null when none matches
 */
function kbEntryContext(ArrayObject $warnings, string $phrase): mixed
{
    foreach ($warnings as $warning) {
        if (\is_string($warning[0]) && \str_contains($warning[0], $phrase)) {
            return $warning[1];
        }
    }

    return null;
}

/**
 * Parse an element.
 *
 * @param  string  $xml  The markup
 * @return SimpleXMLElement The element
 */
function kbEntryElement(string $xml): SimpleXMLElement
{
    $element = \simplexml_load_string($xml);

    if ($element === false) {
        throw new RuntimeException('Could not parse the element.');
    }

    return $element;
}

/**
 * A topic term.
 *
 * @param  string  $id  The term id
 * @param  string  $slug  The slug
 * @param  array<mixed>  $extra  Other fields
 * @return array<mixed> The term
 */
function kbEntryTopic(string $id, string $slug, array $extra = []): array
{
    return ['id' => $id, 'slug' => $slug, 'name' => \ucfirst($slug), ...$extra];
}

/**
 * An article filed under topics.
 *
 * @param  string  $id  The post id
 * @param  array<mixed>  $topics  The topic slugs, primary first
 * @param  array<mixed>  $extra  Other fields
 * @return array<mixed> The article
 */
function kbEntryArticle(string $id, array $topics, array $extra = []): array
{
    $categories = \array_map(static fn (mixed $slug): array => ['domain' => 'kbtopic', 'nicename' => \is_string($slug) ? $slug : '', 'name' => \is_string($slug) ? \ucfirst($slug) : ''], $topics);

    return ['id' => $id, 'categories' => [...$categories, ...kbEntryList($extra, 'categories')], ...\array_diff_key($extra, ['categories' => true])];
}

describe('a whole import', function (): void {
    it('builds the root, its topics and their articles, and saves them as one tree', function (): void {
        $export = kbEntryExport(
            [kbEntryTopic('5', 'billing'), kbEntryTopic('6', 'refunds', ['parent' => 'billing']), kbEntryTopic('7', 'shipping')],
            [kbEntryArticle('901', ['refunds'], ['slug' => 'get-a-refund']), kbEntryArticle('902', ['billing'], ['slug' => 'pay-a-bill'])]
        );

        $run = kbEntryRun($export);

        expect(kbEntrySlugs())->toBe(['billing', 'get-a-refund', 'kb', 'pay-a-bill', 'refunds', 'shipping'])
            ->and(kbEntryTree())->toBe([['kb' => [['billing' => [['refunds' => ['get-a-refund']], 'pay-a-bill']], 'shipping']]])
            ->and($run['output'])->toContain('Processing MinervaKB content...'.PHP_EOL)
            ->toContain('Found 3 kbtopic terms.'.PHP_EOL)
            ->toContain('Processed 3 kbtopic terms, created 3 topic entries.'.PHP_EOL)
            ->toContain('Created 2 article entries.'.PHP_EOL)
            ->toContain('Knowledge base tree structure saved.'.PHP_EOL)
            ->toContain('MinervaKB import complete!'.PHP_EOL)
            ->not->toContain('Note:');
    });
});

describe('the knowledge base root', function (): void {
    it('creates the root with its defaults, published, and records it as the tree top', function (): void {
        $creator = new KbEntryTestCreator;
        $output = new BufferedOutput;

        $creator->call('createKnowledgeBaseEntry', $output, 'default', 'knowledge_bases');
        $root = kbEntry('kb');
        $id = kbEntryId('kb');

        expect(kbEntryData($root))->toBe([
            'blueprint' => 'knowledge_base',
            'title' => 'Default Knowledge Base',
            'knowledge_base_search_query' => true,
            'topic_label' => 'Topics',
            'topic_limit' => -1,
            'show_all_topics_label' => 'Show All',
            'topic_description_query' => true,
            'topic_image_query' => true,
            'restriction_enabled' => false,
            'topic_search_query' => false,
            'subtopic_label' => 'Subtopics',
            'subtopic_limit' => -1,
            'subtopic_description_query' => true,
            'subtopic_image_query' => true,
            'show_all_subtopics_label' => 'Show All',
            'topic_article_label' => 'Articles',
            'topic_article_count' => -1,
            'topic_article_show_all_label' => 'Show All',
            'topic_article_last_modified_query' => false,
            'topic_article_description_query' => true,
            'topic_article_image_query' => true,
            'article_last_modified_query' => true,
            'article_creator_query' => false,
            'article_estimated_reading_time_query' => false,
            'toc_query' => false,
            'attachments_label' => 'Attachments',
            'related_articles_query' => true,
            'related_articles_label' => 'Related Articles',
            'hide_from_menu' => false,
            'knowledge_base_search_title' => 'Search the Knowledge Base',
            'knowledge_base_search_placeholder' => 'e.g., Guidelines',
            'template' => null,
        ])
            ->and($root->published())->toBeTrue()
            ->and($root->locale())->toBe('default')
            ->and($creator->read('knowledgeBaseId'))->toBe($id)
            ->and($creator->read('pendingTreeNodes'))->toBe([['entry' => $id, 'parent' => null]])
            ->and($creator->read('pendingTreeCollectionHandle'))->toBe('knowledge_bases')
            ->and($creator->read('pendingTreeSite'))->toBe('default')
            ->and($creator->read('hasRestrictions'))->toBeFalse()
            ->and($creator->read('hasSitewideRestrictions'))->toBeFalse()
            ->and($output->fetch())->toBe('Creating knowledge base entry...'.PHP_EOL."Created knowledge base entry 'kb' (ID: {$id}) for site 'default'.".PHP_EOL);
    });

    it('names the root after its site', function (): void {
        Site::setSites(['default' => ['name' => 'Medicine', 'locale' => 'en_US', 'url' => '/']]);

        (new KbEntryTestCreator)->call('createKnowledgeBaseEntry', new BufferedOutput, 'default', 'knowledge_bases');

        expect(kbEntry('kb')->get('title'))->toBe('Medicine Knowledge Base');
    });

    it('takes its template and title options from the blueprint, adding a header graphic for a graphic title', function (string $titleOptions, array $expected): void {
        kbEntryBlueprint('knowledge_base', ['template' => 'kb/home', 'title_options' => $titleOptions]);

        (new KbEntryTestCreator)->call('createKnowledgeBaseEntry', new BufferedOutput, 'default', 'knowledge_bases');
        $data = kbEntryData(kbEntry('kb'));

        expect(\array_intersect_key($data, ['template' => 1, 'title_options' => 1, 'header_graphic' => 1]))->toBe($expected);
    })->with([
        'a graphic title' => ['graphic', ['template' => 'kb/home', 'title_options' => 'graphic', 'header_graphic' => ['body' => null, 'image' => null]]],
        'a plain title' => ['normal', ['template' => 'kb/home', 'title_options' => 'normal']],
    ]);

    it('logs that an undated collection takes no date', function (): void {
        $warnings = kbEntryWarnings();
        (new KbEntryTestCreator)->call('createKnowledgeBaseEntry', new BufferedOutput, 'default', 'knowledge_bases');

        expect(kbEntry('kb')->date())->toBeNull()
            ->and(kbEntryContext($warnings, 'KbEntryCreator::createKnowledgeBaseEntry: Skipping date setting - collection not dated'))->toBe(['collection_handle' => 'knowledge_bases', 'collection_dated' => false]);
    });

    it('dates the root in a dated collection, without a warning', function (): void {
        kbEntryCollection(dated: true);
        $blueprint = BlueprintFacade::make('knowledge_base');
        $blueprint->setNamespace('collections.knowledge_bases');
        $blueprint->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']], ['handle' => 'date', 'field' => ['type' => 'date']]]]);
        $blueprint->save();
        $warnings = kbEntryWarnings();
        $before = \time();

        (new KbEntryTestCreator)->call('createKnowledgeBaseEntry', new BufferedOutput, 'default', 'knowledge_bases');

        $date = kbEntry('kb')->date();

        expect($date instanceof Carbon ? $date->getTimestamp() : 0)->toBeGreaterThanOrEqual($before)
            ->and(kbEntryMessages($warnings))->toBe([]);
    });

    it('reuses a root already at its slug', function (): void {
        $existing = EntryFacade::make();

        if (! $existing instanceof Entry) {
            throw new RuntimeException('Could not make an entry.');
        }

        $existing->collection('knowledge_bases');
        $existing->locale('default');
        $existing->slug('kb');
        $existing->id('kb-root');
        $existing->data(['title' => 'Old']);
        $existing->save();
        $creator = new KbEntryTestCreator;
        $output = new BufferedOutput;

        $creator->call('createKnowledgeBaseEntry', $output, 'default', 'knowledge_bases');

        expect(kbEntrySlugs())->toBe(['kb'])
            ->and(kbEntry('kb')->get('title'))->toBe('Old')
            ->and($creator->read('knowledgeBaseId'))->toBe('kb-root')
            ->and($creator->read('pendingTreeNodes'))->toBe([])
            ->and($output->fetch())->toBe('Creating knowledge base entry...'.PHP_EOL);
    });

    it('takes the kb_page slug and an id carrying its post id', function (): void {
        $creator = new KbEntryTestCreator;

        $creator->call('createKnowledgeBaseEntry', new BufferedOutput, 'default', 'knowledge_bases', null, ['wp_id' => '77', 'slug' => 'help', 'title' => 'Help', 'parent_wp_id' => '0']);

        expect(kbEntrySlugs())->toBe(['help'])
            ->and(kbEntry('help')->id())->toStartWith('help-77-')
            ->and($creator->read('knowledgeBaseId'))->toBe(kbEntry('help')->id());
    });

    it('applies the MinervaKB settings over the defaults', function (): void {
        $creator = new KbEntryTestCreator;

        $creator->call('createKnowledgeBaseEntry', new BufferedOutput, 'default', 'knowledge_bases', ['breadcrumbs_home_label' => 'Help Center', 'cpt_topic_label_name' => 'Areas']);
        $data = kbEntryData(kbEntry('kb'));

        expect($data['title'] ?? null)->toBe('Default Help Center')
            ->and($data['nav_alt_name'] ?? null)->toBe('Help Center')
            ->and($data['topic_label'] ?? null)->toBe('Areas');
    });

    it('sets the search title and placeholder only when search is on', function (array $settings, array $expected): void {
        (new KbEntryTestCreator)->call('createKnowledgeBaseEntry', new BufferedOutput, 'default', 'knowledge_bases', $settings);

        expect(\array_intersect_key(kbEntryData(kbEntry('kb')), ['knowledge_base_search_query' => 1, 'knowledge_base_search_title' => 1, 'knowledge_base_search_placeholder' => 1]))->toBe($expected);
    })->with([
        'search off' => [['home_sections_switch' => true, 'home_sections' => ['topics']], ['knowledge_base_search_query' => false]],
        'search on, with its own text' => [['home_sections_switch' => true, 'home_sections' => ['search'], 'search_title' => 'Find it', 'search_placeholder' => 'Type'], ['knowledge_base_search_query' => true, 'knowledge_base_search_title' => 'Find it', 'knowledge_base_search_placeholder' => 'Type']],
    ]);

    it('records whether the root restricts content, and whether sitewide', function (array $settings, bool $restricted, bool $sitewide): void {
        $creator = new KbEntryTestCreator;

        $creator->call('createKnowledgeBaseEntry', new BufferedOutput, 'default', 'knowledge_bases', $settings);

        expect($creator->read('hasRestrictions'))->toBe($restricted)
            ->and($creator->read('hasSitewideRestrictions'))->toBe($sitewide);
    })->with([
        'restricted to roles' => [['restrict_on' => true, 'restrict_article_role' => ['subscriber']], true, true],
        'restricted to guests' => [['restrict_on' => true, 'restrict_article_role' => ['guest']], true, false],
        'not restricted' => [['restrict_on' => false], false, false],
    ]);
});

describe('the tree', function (): void {
    it('reports a missing collection and stops', function (): void {
        $warnings = kbEntryWarnings();
        $output = new BufferedOutput;

        (new KbEntryTestCreator)->call('createInitialTreeStructure', $output, 'default', 'missing');

        expect($output->fetch())->toBe('Creating initial tree structure for knowledge_bases...'.PHP_EOL."Collection 'missing' not found.".PHP_EOL)
            ->and(kbEntryMessages($warnings))->toBe(["ERROR KbEntryCreator::createInitialTreeStructure: Collection 'missing' not found for site default."]);
    });

    it('gives an unstructured collection a structure', function (): void {
        kbEntryCollection(structured: false);
        $output = new BufferedOutput;

        (new KbEntryTestCreator)->call('createInitialTreeStructure', $output, 'default', 'knowledge_bases');

        expect(kbEntryTreeFor('default'))->toBeInstanceOf(Tree::class)
            ->and($output->fetch())->toBe(
                'Creating initial tree structure for knowledge_bases...'.PHP_EOL
                ."Collection 'knowledge_bases' has no structure defined. Creating one.".PHP_EOL
                ."Initial tree structure created for 'knowledge_bases' in site 'default'.".PHP_EOL
            );
    });

    it('makes a tree for a site that has none', function (): void {
        Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'], 'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/']]);
        $collection = CollectionFacade::findByHandle('knowledge_bases');
        $collection?->sites(['default', 'other']);
        $collection?->save();
        $output = new BufferedOutput;

        expect(kbEntryTreeFor('other'))->toBeNull();

        (new KbEntryTestCreator)->call('createInitialTreeStructure', $output, 'other', 'knowledge_bases');

        expect(kbEntryTreeFor('other'))->toBeInstanceOf(Tree::class)
            ->and($output->fetch())->toBe(
                'Creating initial tree structure for knowledge_bases...'.PHP_EOL
                ."No tree exists for 'knowledge_bases' in site 'other'. Creating a new one.".PHP_EOL
                ."Initial tree structure created for 'knowledge_bases' in site 'other'.".PHP_EOL
            );
    });

    it('leaves a tree that already exists', function (): void {
        $creator = new KbEntryTestCreator;
        $creator->call('createInitialTreeStructure', new BufferedOutput, 'default', 'knowledge_bases');
        $output = new BufferedOutput;

        $creator->call('createInitialTreeStructure', $output, 'default', 'knowledge_bases');

        expect($output->fetch())->toBe('Creating initial tree structure for knowledge_bases...'.PHP_EOL."Initial tree structure created for 'knowledge_bases' in site 'default'.".PHP_EOL);
    });

    it('saves nothing when no entry was recorded', function (): void {
        $output = new BufferedOutput;

        (new KbEntryTestCreator)->call('buildAndSaveKbTree', $output);

        expect($output->fetch())->toBe('')
            ->and(kbEntryTree())->toBe([]);
    });

    it('anchors an unrecorded parent at the top, and places each entry once', function (): void {
        foreach (['root', 'a', 'b', 'c', 'outside'] as $slug) {
            kbEntryMake($slug);
        }

        $creator = new KbEntryTestCreator;
        $creator->write('pendingTreeCollectionHandle', 'knowledge_bases');
        $creator->write('pendingTreeSite', 'default');
        $creator->write('pendingTreeNodes', [
            ['entry' => 'root', 'parent' => null],
            ['entry' => 'a', 'parent' => 'root'],
            ['entry' => 'b', 'parent' => 'a'],
            ['entry' => 'b', 'parent' => 'a'],
            ['entry' => 'c', 'parent' => 'outside'],
            ['entry' => 'root', 'parent' => 'a'],
        ]);
        $output = new BufferedOutput;

        $creator->call('buildAndSaveKbTree', $output);

        expect(kbEntryTree())->toBe([['root' => [['a' => ['b']]]], ['outside' => ['c']]])
            ->and($output->fetch())->toBe('Knowledge base tree structure saved.'.PHP_EOL);
    });

    it('warns and saves nothing when the collection has no structure', function (string $handle): void {
        $flat = CollectionFacade::make('flat');
        $flat->sites(['default']);
        $flat->save();
        $warnings = kbEntryWarnings();
        $creator = new KbEntryTestCreator;
        $creator->write('pendingTreeCollectionHandle', $handle);
        $creator->write('pendingTreeSite', 'default');
        $creator->write('pendingTreeNodes', [['entry' => 'a', 'parent' => null]]);
        $output = new BufferedOutput;

        $creator->call('buildAndSaveKbTree', $output);

        expect($output->fetch())->toBe('')
            ->and(kbEntryMessages($warnings))->toBe(["KbEntryCreator::buildAndSaveKbTree: Collection '{$handle}' not found or has no structure for site default."])
            ->and(kbEntryContext($warnings, 'buildAndSaveKbTree'))->toBe(['collection_handle' => $handle, 'site' => 'default']);
    })->with([
        'a missing collection' => ['missing'],
        'an unstructured collection' => ['flat'],
    ]);
});

describe('topics', function (): void {
    it('says so when the export has no topics', function (): void {
        $run = kbEntryRun(kbEntryExport([['id' => '30', 'taxonomy' => 'kbtag', 'slug' => 'fast']]));

        expect($run['output'])->toContain('Processing topics...'.PHP_EOL.'No kbtopic terms found in XML.'.PHP_EOL.'Processing articles...')
            ->and(kbEntrySlugs())->toBe(['kb']);
    });

    it('creates a parent before its child, and files a topic whose parent is not a topic at the top', function (): void {
        $run = kbEntryRun(kbEntryExport([
            kbEntryTopic('8', 'grandchild', ['parent' => 'child']),
            kbEntryTopic('6', 'child', ['parent' => 'parent']),
            kbEntryTopic('5', 'parent'),
            kbEntryTopic('9', 'orphan', ['parent' => 'nowhere']),
            ['id' => '30', 'taxonomy' => 'kbtag', 'slug' => 'parentless', 'parent' => ''],
        ]));
        \preg_match_all("/Created entry '([a-z]+)'/", $run['output'], $created);

        expect($created[1])->toBe(['parent', 'child', 'grandchild', 'orphan'])
            ->and(kbEntryTree())->toBe([['kb' => [['parent' => [['child' => ['grandchild']]]], 'orphan']]])
            ->and($run['output'])->toContain('Found 4 kbtopic terms.'.PHP_EOL)
            ->toContain('Processed 4 kbtopic terms, created 4 topic entries.'.PHP_EOL);
    });

    it('saves a topic with its title, excerpt, parent and blueprint defaults, and registers it for links', function (): void {
        kbEntryBlueprint('topic', ['template' => 'kb/topic', 'title_options' => 'graphic']);
        $run = kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing', ['name' => '<b>Bill</b>ing', 'description' => '<p>All about <em>bills</em>.</p>'])]));
        $topic = kbEntry('billing');
        $id = $topic->id();

        expect(kbEntryData($topic))->toBe([
            'blueprint' => 'topic',
            'title' => 'Billing',
            'excerpt' => 'All about bills.',
            'parent' => $run['creator']->read('knowledgeBaseId'),
            'template' => 'kb/topic',
            'title_options' => 'graphic',
            'header_graphic' => ['body' => null, 'image' => null],
        ])
            ->and($id)->toStartWith('billing-5-')
            ->and($topic->published())->toBeTrue()
            ->and($run['creator']->read('topicIdMap'))->toBe(['5' => $id])
            ->and(KbLinkRegistry::getKbtopicEntryIdBySlug('billing'))->toBe($id)
            ->and(KbLinkRegistry::getKbtopicEntryIdByTermId('5'))->toBe($id);
    });

    it('leaves out the header graphic for a topic whose title is not graphic', function (): void {
        kbEntryBlueprint('topic', ['title_options' => 'normal']);
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')]));

        expect(kbEntryData(kbEntry('billing')))->toHaveKey('title_options', 'normal')
            ->not->toHaveKey('header_graphic');
    });

    it('reads its description from the term, then its description meta, and shortens a long one', function (array $term, string $excerpt): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing', $term)]));

        expect(kbEntry('billing')->get('excerpt'))->toBe($excerpt);
    })->with([
        'the term description' => [['description' => 'From the term', 'meta' => ['description' => 'From meta']], 'From the term'],
        'a blank term description, then meta' => [['description' => '  ', 'meta' => ['color' => 'red', 'description' => 'From meta']], 'From meta'],
        'blank meta' => [['meta' => ['description' => ' ', 'other' => 'x']], ''],
        'meta that stops at the first description' => [['meta' => ['description' => ' ']], ''],
        'none' => [[], ''],
        '160 characters' => [['description' => \str_repeat('a', 160)], \str_repeat('a', 160)],
        '161 characters' => [['description' => 'X'.\str_repeat('é', 160)], 'X'.\str_repeat('é', 156).'...'],
    ]);

    it('keeps a re-imported topic under its first id, registering it again', function (): void {
        $export = kbEntryExport([kbEntryTopic('5', 'billing')]);
        kbEntryRun($export);
        $first = kbEntry('billing')->id();
        KbLinkRegistry::clear();

        $second = kbEntryRun($export);

        expect($second['creator']->read('topicIdMap'))->toBe(['5' => $first])
            ->and(KbLinkRegistry::getKbtopicEntryIdBySlug('billing'))->toBe($first)
            ->and(KbLinkRegistry::getKbtopicEntryIdByTermId('5'))->toBe($first)
            ->and(kbEntrySlugs())->toBe(['billing', 'kb']);
    });

    it('creates no topic without a term id or slug', function (array $termData): void {
        $creator = new KbEntryTestCreator;

        expect($creator->call('createTopicEntryFromData', $termData, 'kb', new BufferedOutput, 'default', 'knowledge_bases'))->toBe('')
            ->and(kbEntrySlugs())->toBe([])
            ->and($creator->read('topicIdMap'))->toBe([]);
    })->with([
        'no id' => [['term_slug' => 'billing', 'term_name' => 'Billing']],
        'no slug' => [['term_id' => '5', 'term_name' => 'Billing']],
        'an id that is not a string' => [['term_id' => true, 'term_slug' => 'billing']],
    ]);

    it('creates a topic from an integer term id, and returns its id', function (): void {
        $creator = new KbEntryTestCreator;

        $id = $creator->call('createTopicEntryFromData', ['term_id' => 5, 'term_slug' => 'billing'], 'kb', new BufferedOutput, 'default', 'knowledge_bases');

        expect($id)->toBe(kbEntry('billing')->id())
            ->and(kbEntry('billing')->get('title'))->toBe('')
            ->and($creator->read('pendingTreeNodes'))->toBe([['entry' => $id, 'parent' => 'kb']]);
    });
});

describe('what a run reads and records', function (): void {
    it('imports the tags taxonomy only when tag import is on', function (bool $tags, array $handles): void {
        config(['wordpress-import.import_tags' => $tags]);

        $run = kbEntryRun(kbEntryExport());

        expect($run['creator']->termImports)->toBe([$handles]);
    })->with([
        'on' => [true, ['mkb_faq_category', 'kbtag']],
        'off' => [false, ['mkb_faq_category']],
    ]);

    it('hands the link processing the MinervaKB settings', function (): void {
        kbEntryRun(kbEntryExport(), ['kb_page' => '', 'other' => 'x']);

        expect(ProcessingContext::getMinervaKbSettings())->toBe(['kb_page' => '', 'other' => 'x']);
    });

    it('notes the restrictions to configure by hand, after the import', function (array $settings, array $notes): void {
        $run = kbEntryRun(kbEntryExport(), $settings);
        $tail = \substr($run['output'], (int) \strpos($run['output'], 'MinervaKB import complete!'));

        expect($tail)->toBe('MinervaKB import complete!'.PHP_EOL.\implode('', \array_map(static fn (mixed $note): string => (\is_string($note) ? $note : '').PHP_EOL, $notes)));
    })->with([
        'sitewide' => [['restrict_on' => true, 'restrict_article_role' => ['subscriber']], [
            'Note: This knowledge base has sitewide content restrictions enabled. Review the MinervaKB settings on the source site to identify the sitewide content restrictions. Then, manually configure those same restrictions on the top-level knowledge base entry in Statamic to ensure consistent application across the site.',
            'Note: Examine the knowledge base topics and articles on the source site to identify any configured content restrictions. For any topics or articles with restrictions, manually apply the same restrictions to their corresponding entries imported into Statamic to maintain consistent access control.',
        ]],
        'guests only' => [['restrict_on' => true, 'restrict_article_role' => ['guest']], [
            'Note: Examine the knowledge base topics and articles on the source site to identify any configured content restrictions. For any topics or articles with restrictions, manually apply the same restrictions to their corresponding entries imported into Statamic to maintain consistent access control.',
        ]],
        'none' => [['restrict_on' => false], []],
    ]);
});

describe('the knowledge base home page', function (): void {
    it('mounts the root at the kb_page, under its parent page, and records what it replaced', function (int|string $kbPage): void {
        $run = kbEntryRun(kbEntryExport([], [['type' => 'page', 'id' => '77', 'slug' => 'help', 'title' => 'Help &amp; <b>Support</b>', 'parent' => '12']]), ['kb_page' => $kbPage]);
        $root = kbEntry('help');

        expect($root->id())->toStartWith('help-77-')
            ->and($run['creator']->read('knowledgeBaseId'))->toBe($root->id())
            ->and(DeferredKnowledgeBaseMounts::all())->toBe([['kb_root_id' => $root->id(), 'parent_wp_id' => '12', 'site' => 'default']])
            ->and(KbPageSubstitutions::resolve('77', 'default'))->toBe($root->id())
            ->and($run['output'])->toContain('Resolving knowledge base home page (WordPress ID: 77)...'.PHP_EOL.'Creating knowledge base entry...');
    })->with([
        'a string id' => ['77'],
        'an integer id' => [77],
    ]);

    it('records the replacement but mounts nothing for a kb_page with no parent page', function (string $parent): void {
        kbEntryRun(kbEntryExport([], [['type' => 'page', 'id' => '77', 'slug' => 'help', 'parent' => $parent]]), ['kb_page' => '77']);

        expect(DeferredKnowledgeBaseMounts::all())->toBe([])
            ->and(KbPageSubstitutions::resolve('77', 'default'))->toBe(kbEntry('help')->id());
    })->with([
        'parent 0' => ['0'],
        'a blank parent' => [''],
    ]);

    it('names a kb_page with no slug after its title, or calls it untitled', function (string $title, string $slug): void {
        kbEntryRun(kbEntryExport([], [['type' => 'page', 'id' => '77', 'slug' => '', 'title' => $title]]), ['kb_page' => '77']);

        expect(kbEntrySlugs())->toBe([$slug]);
    })->with([
        'a title' => ['Get Help', 'get-help'],
        'no title' => ['', 'untitled-page'],
    ]);

    it('keeps the default root when the kb_page is not in the export', function (): void {
        $warnings = kbEntryWarnings();
        $export = kbEntryExport([], [['type' => 'page', 'id' => '78']]);

        $run = kbEntryRun($export, ['kb_page' => '77']);

        expect(kbEntrySlugs())->toBe(['kb'])
            ->and(KbPageSubstitutions::all())->toBe([])
            ->and($run['output'])->toContain("Warning: MinervaKB home page (WordPress ID '77') was not found in the XML export; the knowledge base will use its default slug and URL.".PHP_EOL.'Creating knowledge base entry...')
            ->and(kbEntryContext($warnings, "KbEntryCreator::findPageInXml: Page with WordPress ID '77' not found in XML file: {$export}"))->toBe(['wordpress_post_id' => '77', 'xml_path' => $export]);
    });

    it('resolves no kb_page without settings or a kb_page in them', function (?array $settings): void {
        $run = kbEntryRun(kbEntryExport([], [['type' => 'page', 'id' => '77']]), $settings);

        expect(kbEntrySlugs())->toBe(['kb'])
            ->and($run['output'])->not->toContain('Resolving knowledge base home page');
    })->with([
        'no settings' => [null],
        'no kb_page' => [['other' => 'x']],
        'a blank kb_page' => [['kb_page' => '']],
        'a kb_page that is not an id' => [['kb_page' => ['77']]],
    ]);

    it('finds no page in a parser with no file', function (): void {
        expect((new KbEntryTestCreator)->call('findPageInXml', new XmlParser, '77'))->toBeNull();
    });
});

describe('articles', function (): void {
    it('saves an article with its fields, published, under its topic, and registers it for links', function (): void {
        kbEntryBlueprint('article', ['template' => 'kb/article', 'title_options' => 'hidden', 'restriction_query' => 'no']);
        $run = kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'pay', 'title' => 'Pay &amp; <b>Save</b>', 'content' => '<p>Body.</p>'])]));
        $article = kbEntry('pay');
        $id = $article->id();

        expect(kbEntryData($article))->toBe([
            'blueprint' => 'article',
            'title' => 'Pay & Save',
            'title_options' => 'hidden',
            'parent' => kbEntry('billing')->id(),
            'template' => 'kb/article',
            'restriction_query' => 'no',
            'original_wordpress_url' => 'https://example.test/kb/901/',
            'updated_at' => 1743588000,
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']], 'attrs' => []]],
        ])
            ->and($id)->toStartWith('pay-901-')
            ->and($article->published())->toBeTrue()
            ->and($article->date())->toBeNull()
            ->and($run['creator']->read('articleIdMap'))->toBe([901 => $id])
            ->and(KbLinkRegistry::getKbPostEntryId('pay'))->toBe($id)
            ->and(ProcessingContext::getCollectionHandle())->toBe('knowledge_bases')
            ->and(ProcessingContext::getStatamicEntryId())->toBe($id)
            ->and(ProcessingContext::getWordPressPageLink())->toBe('https://example.test/kb/901/')
            ->and(ProcessingContext::getXmlPath())->not->toBeNull();
    });

    it('calls an article with no title untitled', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'blank', 'title' => '<b></b>'])]));

        expect(kbEntry('blank')->get('title'))->toBe('Untitled');
    });

    it('gives a published article with no content placeholder text, and a draft none', function (string $status, mixed $content, bool $published): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'empty', 'content' => '', 'status' => $status])]));

        expect(kbEntry('empty')->get('content'))->toBe($content)
            ->and(kbEntry('empty')->published())->toBe($published);
    })->with([
        'published' => ['publish', [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "Placeholder Article Content \u{2014} Please Replace"]], 'attrs' => []]], true],
        'a draft' => ['draft', null, false],
    ]);

    it('reads a missing status as published', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'nostatus', 'status' => null, 'content' => ''])]));

        expect(kbEntry('nostatus')->published())->toBeTrue()
            ->and(kbEntry('nostatus')->get('content'))->not->toBe([]);
    });

    it('leaves a gated article unpublished', function (): void {
        $run = kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'gated', 'meta' => ['_wpmem_block' => '1']])]));

        expect(kbEntry('gated')->published())->toBeFalse()
            ->and($run['output'])->toContain('WP-Members');
    });

    it('skips an article with no topic, still counting it', function (array $topics): void {
        $run = kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', $topics, ['slug' => 'loose'])]));

        expect(kbEntrySlugs())->toBe(['billing', 'kb'])
            ->and($run['output'])->toContain('Created 1 article entries.');
    })->with([
        'no topic' => [[]],
        'a topic that was not imported' => [['shipping']],
    ]);

    it('skips an item that is not a knowledge base post, without counting it', function (): void {
        $export = kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'kept']), kbEntryArticle('902', ['billing'], ['slug' => 'post', 'type' => 'post'])]);
        $creator = new KbEntryTestCreator;
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')]), creator: $creator);
        $parser = kbEntryParser($export);
        $items = kbEntryElement((string) \file_get_contents($export))->xpath('//item');
        $parser->setItems(\is_array($items) ? $items : []);
        $output = new BufferedOutput;

        $creator->call('processArticles', $parser, $output, 'default', new AssetImporter, 'knowledge_bases');

        expect(kbEntrySlugs())->toBe(['billing', 'kb', 'kept'])
            ->and($output->fetch())->toStartWith('Processing articles...'.PHP_EOL)
            ->toContain('Created 1 article entries.');
    });

    it('files an article under its first topic and lists the others once, as secondary topics', function (): void {
        kbEntryRun(kbEntryExport(
            [kbEntryTopic('5', 'billing'), kbEntryTopic('6', 'refunds'), kbEntryTopic('7', 'shipping')],
            [kbEntryArticle('901', ['refunds', 'billing', 'refunds', 'billing', 'missing', '', 'shipping'], ['slug' => 'multi'])]
        ));

        expect(kbEntry('multi')->get('parent'))->toBe(kbEntry('refunds')->id())
            ->and(kbEntry('multi')->get('secondary_topic'))->toBe([kbEntry('billing')->id(), kbEntry('shipping')->id()]);
    });

    it('lists the second topic of two as secondary', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing'), kbEntryTopic('6', 'refunds'), kbEntryTopic('7', 'shipping')], [kbEntryArticle('901', ['refunds', 'billing', 'shipping'], ['slug' => 'pair'])]));

        expect(kbEntry('pair')->get('secondary_topic'))->toBe([kbEntryId('billing'), kbEntryId('shipping')]);
    });

    it('sets no secondary topics for an article with one topic', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'single'])]));

        expect(kbEntryData(kbEntry('single')))->not->toHaveKey('secondary_topic');
    });

    it('files an article only under topics the term map names', function (array $map, ?string $parent, array $secondary): void {
        $creator = new KbEntryTestCreator;
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing'), kbEntryTopic('6', 'refunds'), kbEntryTopic('7', 'shipping')]), creator: $creator);
        $creator->write('nicenameToTermIdMap', $map);
        $item = kbEntryElement('<item xmlns:wp="http://wordpress.org/export/1.2/" xmlns:content="http://purl.org/rss/1.0/modules/content/"><title>T</title><link>https://example.test/kb/901/</link><content:encoded>B</content:encoded><wp:post_id>901</wp:post_id><wp:post_name>mapped</wp:post_name><wp:post_type>kb</wp:post_type><category domain="kbtopic" nicename="refunds">R</category><category domain="kbtopic" nicename="shipping">S</category><category domain="kbtopic" nicename="billing">B</category><category domain="kbtopic">None</category></item>');

        $creator->call('createArticleEntry', $item, new BufferedOutput, 'default', kbEntryParser(kbEntryExport()), new AssetImporter, 'knowledge_bases');
        $article = \in_array('mapped', kbEntrySlugs(), true) ? kbEntryData(kbEntry('mapped')) : null;

        expect($article === null ? null : ($article['parent'] ?? null))->toBe($parent === null ? null : kbEntryId($parent))
            ->and($article === null ? [] : ($article['secondary_topic'] ?? []))->toBe(\array_map(static fn (mixed $slug): string => kbEntryId(\is_string($slug) ? $slug : ''), $secondary));
    })->with([
        'the primary unnamed' => [['kbtopic' => ['shipping' => '7', 'billing' => '5']], null, []],
        'a secondary unnamed' => [['kbtopic' => ['refunds' => '6', 'billing' => '5']], 'refunds', ['billing']],
        'a term with no topic' => [['kbtopic' => ['refunds' => '6', 'shipping' => '99']], 'refunds', []],
        'no topic map at all' => [['kbtag' => []], null, []],
    ]);

    it('lists the tags the term pass imported, once each, and no others', function (): void {
        $export = kbEntryExport(
            [kbEntryTopic('5', 'billing'), ['id' => '30', 'taxonomy' => 'kbtag', 'slug' => 'fast'], ['id' => '31', 'taxonomy' => 'kbtag', 'slug' => 'slow'], ['id' => '32', 'taxonomy' => 'kbtag', 'slug' => 'blank']],
            [kbEntryArticle('901', ['billing'], ['slug' => 'tagged', 'categories' => [
                ['domain' => 'kbtag', 'nicename' => 'unknown'], ['domain' => 'kbtag', 'nicename' => 'fast'], ['domain' => 'kbtag', 'nicename' => 'fast'], ['domain' => 'kbtag', 'nicename' => 'slow'],
                ['domain' => 'kbtag', 'nicename' => ''], ['domain' => 'kbtag', 'nicename' => 'unknown'], ['domain' => 'kbtag', 'nicename' => 'blank'], ['domain' => 'kbtag', 'nicename' => 'fast-too'],
            ]])]
        );
        $creator = new KbEntryTestCreator;
        $creator->termImport = static function (): void {
            TermDestinationRegistry::registerCollectionEntry('kbtag', '30', 'knowledge_base_tags', 'tag-fast');
            TermDestinationRegistry::registerCollectionEntry('kbtag', '32', 'knowledge_base_tags', '');
        };

        kbEntryRun($export, creator: $creator);

        expect(kbEntry('tagged')->get('tags'))->toBe(['tag-fast']);
    });

    it('lists no tags when tag import is off, or none resolve', function (bool $tags): void {
        config(['wordpress-import.import_tags' => $tags]);
        TermDestinationRegistry::registerCollectionEntry('kbtag', '30', 'knowledge_base_tags', 'tag-fast');
        $creator = new KbEntryTestCreator;
        $creator->termImport = static function (): void {};

        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing'), ['id' => '30', 'taxonomy' => 'kbtag', 'slug' => 'fast']], [kbEntryArticle('901', ['billing'], ['slug' => 'plain', 'categories' => [['domain' => 'kbtag', 'nicename' => $tags ? 'unknown' : 'fast']]])]), creator: $creator);

        expect(kbEntryData(kbEntry('plain')))->not->toHaveKey('tags');
    })->with([
        'off' => [false],
        'on, with nothing resolved' => [true],
    ]);

    it('cleans the excerpt, and shortens a long one, noting the original', function (string $excerpt, array $expected): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'ex', 'excerpt' => $excerpt])]));

        expect(\array_intersect_key(kbEntryData(kbEntry('ex')), ['excerpt' => 1, 'import_wordpress_notes' => 1]))->toBe($expected);
    })->with([
        'markup and space' => ['<p>  Short.  </p>', ['excerpt' => 'Short.']],
        'blank' => ['<p> </p>', []],
        '160 characters' => [\str_repeat('b', 160), ['excerpt' => \str_repeat('b', 160)]],
        '161 characters' => [\str_repeat('é', 161), ['import_wordpress_notes' => ['Original excerpt value: '.\str_repeat('é', 161)], 'excerpt' => \str_repeat('é', 157).'...']],
    ]);

    it('logs a date it cannot read, and still saves the article', function (): void {
        $warnings = kbEntryWarnings();

        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'dateless', 'title' => 'Dateless', 'date' => 'not a date'])]));

        expect(kbEntrySlugs())->toContain('dateless')
            ->and(kbEntryMessages($warnings))->toContain("KbEntryCreator::createArticleEntry: Invalid date format for article 'Dateless' (ID: 901): not a date (from entry: https://example.test/kb/901/)")
            ->and(kbEntryContext($warnings, 'Invalid date format'))->toMatchArray(['site' => 'default', 'entry_url' => 'https://example.test/kb/901/']);
    });
});

describe('what an article carries', function (): void {
    it('takes its featured image from the attachment its thumbnail names, with the credit toggle when credited', function (array $meta, array $expected): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [
            ['type' => 'attachment', 'id' => '40', 'url' => 'https://example.test/files/other.jpg'],
            ['type' => 'attachment', 'id' => '41', 'url' => 'https://example.test/files/photo.jpg', 'meta' => $meta],
            kbEntryArticle('901', ['billing'], ['slug' => 'pictured', 'meta' => ['_edit_lock' => '1', '_thumbnail_id' => '41', '_thumbnail_id_extra' => '40']]),
        ]));

        expect(\array_intersect_key(kbEntryData(kbEntry('pictured')), ['featured_image_16_9' => 1, 'featured_image_credit_query' => 1, '_thumbnail_id' => 1]))->toBe($expected);
    })->with([
        'credited' => [['_media_credit' => 'A. Photographer'], ['featured_image_16_9' => 'https://example.test/files/photo.jpg', 'featured_image_credit_query' => true, '_thumbnail_id' => '41']],
        'uncredited' => [[], ['featured_image_16_9' => 'https://example.test/files/photo.jpg', '_thumbnail_id' => '41']],
    ]);

    it('keeps the thumbnail id but sets no image when the attachment is missing or has no file', function (array $items): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [...$items, kbEntryArticle('901', ['billing'], ['slug' => 'unpictured', 'meta' => ['_thumbnail_id' => '41']])]));

        expect(\array_intersect_key(kbEntryData(kbEntry('unpictured')), ['featured_image_16_9' => 1, 'featured_image_credit_query' => 1, '_thumbnail_id' => 1]))->toBe(['_thumbnail_id' => '41']);
    })->with([
        'missing' => [[]],
        'no file' => [[['type' => 'attachment', 'id' => '41', 'url' => '', 'meta' => ['_media_credit' => 'A. Photographer']]]],
    ]);

    it('hands the content converter the thumbnail id', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'thumbed', 'meta' => ['_thumbnail_id' => '41']])]));

        expect(ProcessingContext::getEntryData())->toBe(['_thumbnail_id' => '41']);
    });

    it('hands the content converter no thumbnail id when there is none', function (array $meta): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'plain', 'meta' => $meta])]));

        expect(ProcessingContext::getEntryData())->toBe([])
            ->and(kbEntryData(kbEntry('plain')))->not->toHaveKey('_thumbnail_id');
    })->with([
        'none' => [[]],
        'a blank one' => [['_thumbnail_id' => '']],
    ]);

    it('lists its attachments: files by id, and external links with their labels', function (): void {
        $external = 'EXTERNAL{{https://example.test/guide.pdf}}LABEL{{The Guide}}SIZE{{2 MB}}';
        $value = \serialize(['50', 51, $external, 'EXTERNALish', 'EXTERNAL{{', '99', ['nested'], 'plain']);
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [
            ['type' => 'attachment', 'id' => '50', 'url' => 'https://example.test/files/a.pdf'],
            ['type' => 'attachment', 'id' => '51', 'url' => 'https://example.test/files/b.pdf'],
            ['type' => 'attachment', 'id' => '52', 'url' => ''],
            kbEntryArticle('901', ['billing'], ['slug' => 'attached', 'meta' => ['_other' => 'x', '_mkb_article_attachments' => $value, '_mkb_article_attachments_two' => \serialize(['52'])]]),
        ]));
        $attachments = kbEntry('attached')->get('attachment');
        $rows = \array_map(static fn (mixed $row): mixed => \is_array($row) ? \array_diff_key($row, ['id' => 1]) : $row, \is_array($attachments) ? $attachments : []);

        expect($rows)->toBe([
            ['type' => 'asset', 'enabled' => true, 'asset' => 'https://example.test/files/a.pdf'],
            ['type' => 'asset', 'enabled' => true, 'asset' => 'https://example.test/files/b.pdf'],
            ['type' => 'external_file', 'enabled' => true, 'label' => 'The Guide', 'url' => 'https://example.test/guide.pdf'],
            ['type' => 'external_file', 'enabled' => true, 'label' => '', 'url' => ''],
        ])
            ->and(\is_array($attachments) ? \array_map(static fn (mixed $row): bool => \is_array($row) && \is_string($row['id'] ?? null) && \strlen($row['id']) === 8, $attachments) : [])->toBe([true, true, true, true]);
    });

    it('lists no attachments when there are none to list', function (array $meta): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [['type' => 'attachment', 'id' => '52', 'url' => ''], kbEntryArticle('901', ['billing'], ['slug' => 'bare', 'meta' => $meta])]));

        expect(kbEntryData(kbEntry('bare')))->not->toHaveKey('attachment');
    })->with([
        'no meta' => [[]],
        'meta that does not unserialize' => [['_mkb_article_attachments' => 'not serialized']],
        'an attachment with no file' => [['_mkb_article_attachments' => \serialize(['52'])]],
        'only the first meta counts' => [['_mkb_article_attachments' => \serialize([]), '_mkb_article_attachments ' => \serialize(['50'])]],
    ]);

    it('restricts an article to the roles it names, and leaves a guest-only one open', function (string $roles, array $expected): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'locked', 'meta' => ['_mkb_restrict_role' => $roles, '_mkb_restrict_role_extra' => '["admin"]']])]));

        expect(\array_intersect_key(kbEntryData(kbEntry('locked')), ['restriction_query' => 1, 'restriction_category' => 1, 'restriction_user_roles' => 1, 'import_wordpress_notes' => 1]))->toBe($expected);
    })->with([
        'a role' => ['["subscriber"]', ['restriction_query' => true, 'restriction_category' => ['user_roles'], 'import_wordpress_notes' => ['restriction_user_roles: "subscriber"']]],
        'two roles, one a guest' => ['["guest","editor"]', ['restriction_query' => true, 'restriction_category' => ['user_roles'], 'import_wordpress_notes' => ['restriction_user_roles: "guest", "editor"']]],
        'guests' => ['["guest"]', ['restriction_query' => false]],
        'a blank role' => ['[""]', ['restriction_query' => false]],
        'a null role' => ['[null]', ['restriction_query' => false]],
        'roles as an object' => ['{"0":"subscriber"}', ['restriction_query' => true, 'restriction_category' => ['user_roles'], 'import_wordpress_notes' => ['restriction_user_roles: "subscriber"']]],
    ]);

    it('takes the blueprint default restriction when the roles say nothing', function (string $roles): void {
        kbEntryBlueprint('article', ['restriction_query' => 'inherit']);
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'open', 'meta' => ['_mkb_restrict_role' => $roles]])]));

        expect(\array_intersect_key(kbEntryData(kbEntry('open')), ['restriction_query' => 1, 'restriction_category' => 1]))->toBe(['restriction_query' => 'inherit']);
    })->with([
        'no roles' => ['[]'],
        'not JSON' => ['subscriber'],
        'not a list' => ['"subscriber"'],
    ]);

    it('records its engagement under the id it was saved as', function (): void {
        $warnings = kbEntryWarnings();
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'popular', 'meta' => ['_mkb_views' => '9']])]));

        expect(kbEntryContext($warnings, 'ArticleEngagementSeeder::seed'))->toMatchArray(['entry_id' => kbEntry('popular')->id()]);
    });

    it('saves a re-imported article under its first id, in the tree under its topic', function (): void {
        $export = kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'again', 'meta' => ['_mkb_related_articles' => \serialize(['901'])]])]);
        kbEntryRun($export);
        $first = kbEntry('again')->id();
        KbLinkRegistry::clear();

        $second = kbEntryRun($export);

        expect(kbEntry('again')->id())->toBe($first)
            ->and($second['creator']->read('articleIdMap'))->toBe([901 => $first])
            ->and(KbLinkRegistry::getKbPostEntryId('again'))->toBe($first)
            ->and(kbEntry('again')->get('related_articles'))->toBe([$first])
            ->and(kbEntryTree())->toBe([['kb' => [['billing' => ['again']]]]]);
    });

    it('records the saved id under skip-matching, for links and engagement', function (): void {
        $export = kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'kept', 'meta' => ['_mkb_views' => '2']])]);
        kbEntryRun($export);
        $first = kbEntry('kept')->id();
        $creator = new KbEntryTestCreator;
        $creator->setEntryOverwriteBehavior('Skip matching files');
        $warnings = kbEntryWarnings();

        kbEntryRun($export, creator: $creator);

        expect($creator->read('articleIdMap'))->toBe([901 => $first])
            ->and(kbEntryContext($warnings, 'ArticleEngagementSeeder::seed'))->toMatchArray(['entry_id' => $first]);
    });
});

describe('related articles', function (): void {
    it('records each article for the deferred pass, with what its meta names', function (): void {
        $creator = new KbEntryTestCreator;
        $item = static fn (string $meta): SimpleXMLElement => kbEntryElement('<item xmlns:wp="http://wordpress.org/export/1.2/">'.$meta.'</item>');
        $date = Carbon::parse('2025-01-02 03:04:05');

        $creator->call('getRelatedArticles', $item(kbEntryMeta(['_other' => 'x', '_mkb_related_articles' => \serialize(['7', 8, '', true, 9.5]), '_mkb_related_articles_two' => \serialize(['1'])])), 'a', 'slug-a', $date);
        $creator->call('getRelatedArticles', $item(kbEntryMeta(['_mkb_related_articles' => 'broken', '_mkb_related_articles ' => \serialize(['3'])])), 'b');
        $creator->call('getRelatedArticles', $item(''), 'c', 'slug-c');

        expect($creator->read('deferredRelatedArticles'))->toBe([
            'a' => ['wp_post_ids' => ['7', '8', '9.5'], 'slug' => 'slug-a', 'date' => $date],
            'b' => ['wp_post_ids' => [], 'slug' => null, 'date' => null],
            'c' => ['wp_post_ids' => [], 'slug' => 'slug-c', 'date' => null],
        ]);
    });

    it('keeps what an article recorded first when it is recorded again with no meta', function (): void {
        $creator = new KbEntryTestCreator;
        $item = static fn (string $meta): SimpleXMLElement => kbEntryElement('<item xmlns:wp="http://wordpress.org/export/1.2/">'.$meta.'</item>');

        $creator->call('getRelatedArticles', $item(kbEntryMeta(['_mkb_related_articles' => \serialize(['7'])])), 'a', 'first');
        $creator->call('getRelatedArticles', $item(''), 'a', 'second');

        expect($creator->read('deferredRelatedArticles'))->toBe(['a' => ['wp_post_ids' => ['7'], 'slug' => 'first', 'date' => null]]);
    });

    it('links each related article it imported, and reports the rest', function (): void {
        $warnings = kbEntryWarnings();
        $run = kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [
            kbEntryArticle('901', ['billing'], ['slug' => 'one', 'title' => 'One', 'meta' => ['_mkb_related_articles' => \serialize(['902', '998', '903', '999'])]]),
            kbEntryArticle('902', ['billing'], ['slug' => 'two', 'meta' => ['_mkb_related_articles' => \serialize(['997'])]]),
            kbEntryArticle('903', ['billing'], ['slug' => 'three']),
        ]));

        expect(kbEntry('one')->get('related_articles'))->toBe([kbEntry('two')->id(), kbEntry('three')->id()])
            ->and(kbEntryData(kbEntry('two')))->not->toHaveKey('related_articles')
            ->and($run['output'])->toContain('Processing deferred related articles...'.PHP_EOL)
            ->toContain('Processed 1 entries with related articles.'.PHP_EOL.'Warning: 3 related article references could not be resolved.'.PHP_EOL)
            ->and(kbEntryMessages($warnings))->toContain("KbEntryCreator::processDeferredRelatedArticles: Entry 'One' (ID: ".kbEntryId('one').') has unmapped related articles: 998, 999 for site default. (from entry: https://example.test/kb/903/)')
            ->and(kbEntryContext($warnings, "Entry 'One'"))->toMatchArray(['entry_id' => kbEntry('one')->id(), 'entry_title' => 'One', 'unmapped_articles' => ['998', '999'], 'site' => 'default']);
    });

    it('reports no unresolved references when all resolve, and runs no pass when nothing was recorded', function (): void {
        $run = kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'one', 'meta' => ['_mkb_related_articles' => \serialize(['902', ''])]]), kbEntryArticle('902', ['billing'], ['slug' => 'two'])]));
        $quiet = new KbEntryTestCreator;
        $output = new BufferedOutput;
        $quiet->call('processDeferredRelatedArticles', $output, 'default', 'knowledge_bases');

        expect($run['output'])->toContain('Processed 1 entries with related articles.'.PHP_EOL.'MinervaKB import complete!')
            ->and($output->fetch())->toBe('');
    });

    it('warns of an entry it cannot find, and goes on to the next', function (): void {
        $warnings = kbEntryWarnings();
        $creator = new KbEntryTestCreator;
        kbEntryMake('there');
        $creator->write('articleIdMap', [7 => 'there']);
        $creator->write('deferredRelatedArticles', ['gone' => ['wp_post_ids' => ['7'], 'slug' => null, 'date' => null], 'there' => ['wp_post_ids' => ['7'], 'slug' => null, 'date' => null]]);
        $output = new BufferedOutput;

        $creator->call('processDeferredRelatedArticles', $output, 'default', 'knowledge_bases');

        expect(kbEntryMessages($warnings))->toContain("KbEntryCreator::processDeferredRelatedArticles: Entry with ID 'gone' not found when processing deferred related articles for site default.")
            ->and(kbEntryContext($warnings, "Entry with ID 'gone'"))->toBe(['entry_id' => 'gone', 'site' => 'default'])
            ->and(kbEntry('there')->get('related_articles'))->toBe(['there'])
            ->and($output->fetch())->toContain('Processed 1 entries with related articles.'.PHP_EOL);
    });

    it('logs an update that does not land, and does not count it', function (): void {
        $warnings = kbEntryWarnings();
        $creator = new KbEntryTestCreator;
        kbEntryMake('halted');
        $creator->write('articleIdMap', [7 => 'halted']);
        $creator->write('deferredRelatedArticles', ['halted' => ['wp_post_ids' => ['7'], 'slug' => null, 'date' => null]]);
        Event::listen(EntrySaving::class, static fn (): bool => false);
        $output = new BufferedOutput;

        $creator->call('processDeferredRelatedArticles', $output, 'default', 'knowledge_bases');

        expect(kbEntryMessages($warnings))->toContain("ERROR KbEntryCreator::processDeferredRelatedArticles: Failed to update entry 'Halted' (ID: halted) with related articles for site default.")
            ->and($output->fetch())->toContain('Processed 0 entries with related articles.'.PHP_EOL);
    });
});

describe('external attachments', function (): void {
    it('reads the link, its label and its size', function (string $value, ?array $expected): void {
        expect((new KbEntryTestCreator)->call('parseExternalAttachment', $value))->toBe($expected);
    })->with([
        'all three' => ['EXTERNAL{{ https://x.test/a}}LABEL{{ Guide }}SIZE{{ 2 MB }}', ['url' => 'https://x.test/a', 'label' => 'Guide', 'size' => '2 MB']],
        'space before the closing braces' => ['EXTERNAL{{https://x.test/a }}', ['url' => 'https://x.test/a ', 'label' => 'https://x.test/a ']],
        'a link alone' => ['EXTERNAL{{https://x.test/a}}', ['url' => 'https://x.test/a', 'label' => 'https://x.test/a']],
        'a link and a label' => ['EXTERNAL{{https://x.test/a}}LABEL{{Guide}}}', ['url' => 'https://x.test/a', 'label' => 'Guide']],
        'a link and a size' => ['EXTERNAL{{https://x.test/a}}SIZE{{3 KB}}', ['url' => 'https://x.test/a', 'label' => 'https://x.test/a', 'size' => '3 KB']],
        'a size before the label' => ['EXTERNAL{{https://x.test/a}}SIZE{{3 KB}}LABEL{{Guide}}', ['url' => 'https://x.test/a}}SIZE{{3 KB', 'label' => 'Guide', 'size' => '3 KB}}LABEL{{Guide']],
        'a label right at the start' => ['EXTERNAL{{LABEL{{Guide}}', ['url' => '', 'label' => 'Guide']],
        'not external' => ['https://x.test/a', null],
        'external without braces' => ['EXTERNAL https://x.test/a', null],
    ]);

    it('strips every trailing brace', function (string $value, string $expected): void {
        expect((new KbEntryTestCreator)->call('removeTrailingClosingBraces', $value))->toBe($expected);
    })->with([
        'pairs' => ['a}}}}', 'a'],
        'an odd number' => ['a}}}', 'a'],
        'one' => ['a}', 'a'],
        'none' => ['a}b', 'a}b'],
        'only braces' => ['}}}', ''],
    ]);
});

describe('the smaller lookups', function (): void {
    it('finds an attachment url by its id, passing over anything that is not an element', function (): void {
        $attachment = static fn (string $id, string $url): SimpleXMLElement => kbEntryElement("<item xmlns:wp=\"http://wordpress.org/export/1.2/\"><wp:post_id>{$id}</wp:post_id><wp:attachment_url>{$url}</wp:attachment_url></item>");
        $creator = new KbEntryTestCreator;
        $attachments = ['not an element', $attachment('1', 'https://x.test/one'), $attachment('2', 'https://x.test/two'), $attachment('2', 'https://x.test/again')];

        expect($creator->call('findAttachmentUrl', '2', $attachments))->toBe('https://x.test/two')
            ->and($creator->call('findAttachmentUrl', '3', $attachments))->toBeNull();
    });

    it('finds a term element by its id', function (): void {
        $export = kbEntryExport([kbEntryTopic('5', 'billing'), kbEntryTopic('6', 'refunds'), kbEntryTopic('6', 'again')]);
        $creator = new KbEntryTestCreator;
        $term = $creator->call('findTermElementById', kbEntryParser($export), '6');

        expect($term instanceof SimpleXMLElement ? (string) $term->children('wp', true)->term_slug : null)->toBe('refunds')
            ->and($creator->call('findTermElementById', kbEntryParser($export), '9'))->toBeNull();
    });

    it('shortens text past 160 characters to 157 and an ellipsis', function (string $text, string $expected): void {
        expect((new KbEntryTestCreator)->call('truncateToExcerptLimit', $text))->toBe($expected);
    })->with([
        'short' => ['Short', 'Short'],
        '160' => [\str_repeat('c', 160), \str_repeat('c', 160)],
        '161' => ['X'.\str_repeat('c', 160), 'X'.\str_repeat('c', 156).'...'],
        'multibyte' => ['Y'.\str_repeat('ü', 199), 'Y'.\str_repeat('ü', 156).'...'],
    ]);

    it('has no entries, taxonomies or people of its own to report', function (): void {
        $creator = new KbEntryTestCreator;
        $output = new BufferedOutput;
        $creator->validateTaxonomies('knowledge_bases', 'default', $output);

        $mapper = Mockery::mock(MetadataMapper::class);
        $converter = Mockery::mock(ContentProcessor::class);

        if (! $mapper instanceof MetadataMapper || ! $converter instanceof ContentProcessor) {
            throw new RuntimeException('Could not build the collaborators.');
        }

        expect($creator->createEntries('default', 'knowledge_bases', new XmlParser, new AssetImporter, $output, $mapper, $converter))->toBe([])
            ->and($creator->hasShowPeople())->toBeFalse()
            ->and($creator->getPostCategories())->toBe([])
            ->and($creator->getPostTags())->toBe([])
            ->and($creator->getPeopleGroups())->toBe([])
            ->and($output->fetch())->toBe('');
    });
});

/**
 * A site manager that records each collection it is asked to enable.
 */
final class KbEntryTestSiteManager extends SiteManager
{
    /**
     * The site, collection and whether output was given, for each call.
     *
     * @var list<array{0: string, 1: string, 2: bool}>
     */
    public array $calls = [];

    /**
     * Build the manager.
     */
    public function __construct() {}

    /**
     * Record the call.
     *
     * @param  string  $site  The site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  ?OutputInterface  $output  Console output
     * @return bool Always true
     */
    public function enableCollectionForSite(
        string $site,
        string $collectionHandle,
        ?OutputInterface $output = null
    ): bool {
        $this->calls[] = [$site, $collectionHandle, $output instanceof OutputInterface];

        return true;
    }
}

/**
 * A structure manager that records each structure it is asked to initialize.
 */
final class KbEntryTestStructureManager extends CollectionStructureManager
{
    /**
     * The collection and site of each call.
     *
     * @var list<array{0: string, 1: string}>
     */
    public array $calls = [];

    /**
     * Record the call.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output
     */
    public function initializeStructureForSite(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {
        $this->calls[] = [$collectionHandle, $site];
    }
}

/**
 * A class whose unserializing would be recorded, to show the import never builds one.
 */
final class KbEntryWakeupProbe
{
    /**
     * How many times an instance was unserialized.
     */
    public static int $woken = 0;

    /**
     * Record the unserializing.
     */
    public function __wakeup(): void
    {
        self::$woken++;
    }
}

/**
 * Count the events of a class dispatched from now on.
 *
 * @param  class-string  $event  The event class
 * @return ArrayObject<int, object> The events, filled as they are dispatched
 */
function kbEntryEvents(string $event): ArrayObject
{
    /** @var ArrayObject<int, object> $events */
    $events = new ArrayObject;
    Event::listen($event, static function (object $dispatched) use ($events): void {
        $events->append($dispatched);
    });

    return $events;
}

describe('a run, more closely', function (): void {
    it('starts with no root and no restrictions', function (): void {
        $creator = new KbEntryTestCreator;

        expect($creator->read('knowledgeBaseId'))->toBe('')
            ->and($creator->read('hasRestrictions'))->toBeFalse()
            ->and($creator->read('hasSitewideRestrictions'))->toBeFalse();
    });

    it('notes no restrictions when it reuses a root', function (): void {
        kbEntryMake('kb');

        $run = kbEntryRun(kbEntryExport(), ['restrict_on' => true, 'restrict_article_role' => ['subscriber']]);

        expect($run['output'])->not->toContain('Note:')
            ->and($run['creator']->read('knowledgeBaseId'))->toBe('kb');
    });

    it('starts each run with an empty tree', function (): void {
        $creator = new KbEntryTestCreator;
        $creator->write('pendingTreeNodes', [['entry' => 'stale', 'parent' => null]]);

        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')]), creator: $creator);

        expect(\array_column(\is_array($nodes = $creator->read('pendingTreeNodes')) ? $nodes : [], 'entry'))->toBe([kbEntryId('kb'), kbEntryId('billing')]);
    });

    it('imports tags when the setting is missing', function (): void {
        $settings = config('wordpress-import');
        $settings = \is_array($settings) ? $settings : [];
        unset($settings['import_tags']);
        config(['wordpress-import' => $settings]);
        $creator = new KbEntryTestCreator;
        $creator->termImport = static function (): void {
            TermDestinationRegistry::registerCollectionEntry('kbtag', '30', 'knowledge_base_tags', 'tag-fast');
        };

        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing'), ['id' => '30', 'taxonomy' => 'kbtag', 'slug' => 'fast']], [kbEntryArticle('901', ['billing'], ['slug' => 'tagged', 'categories' => [['domain' => 'kbtag', 'nicename' => 'fast']]])]), creator: $creator);

        expect($creator->termImports)->toBe([['mkb_faq_category', 'kbtag']])
            ->and(kbEntry('tagged')->get('tags'))->toBe(['tag-fast']);
    });

    it('lists no tags when the tag map is missing', function (): void {
        $creator = new KbEntryTestCreator;
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')]), creator: $creator);
        $creator->write('nicenameToTermIdMap', ['kbtopic' => ['billing' => '5']]);
        TermDestinationRegistry::registerCollectionEntry('kbtag', '30', 'knowledge_base_tags', 'tag-fast');
        $item = kbEntryElement('<item xmlns:wp="http://wordpress.org/export/1.2/"><title>T</title><wp:post_id>901</wp:post_id><wp:post_name>untagged</wp:post_name><category domain="kbtopic" nicename="billing">B</category><category domain="kbtag" nicename="fast">F</category></item>');

        $creator->call('createArticleEntry', $item, new BufferedOutput, 'default', kbEntryParser(kbEntryExport()), new AssetImporter, 'knowledge_bases');

        expect(kbEntryData(kbEntry('untagged')))->not->toHaveKey('tags');
    });

    it('enables the collection and its structure for the site', function (): void {
        $siteManager = new KbEntryTestSiteManager;
        $structureManager = new KbEntryTestStructureManager;

        kbEntryRun(kbEntryExport(), siteManager: $siteManager, structureManager: $structureManager);

        expect($siteManager->calls)->toBe([['default', 'knowledge_bases', true]])
            ->and($structureManager->calls)->toBe([['knowledge_bases', 'default']]);
    });

    it('creates the initial tree before the root', function (): void {
        $run = kbEntryRun(kbEntryExport());

        expect($run['output'])->toContain('Creating initial tree structure for knowledge_bases...'.PHP_EOL."Initial tree structure created for 'knowledge_bases' in site 'default'.".PHP_EOL.'Creating knowledge base entry...');
    });
});

describe('the tree, more closely', function (): void {
    it('saves the collection it gives a structure', function (): void {
        kbEntryCollection(structured: false);
        $saved = kbEntryEvents(CollectionSaved::class);

        (new KbEntryTestCreator)->call('createInitialTreeStructure', new BufferedOutput, 'default', 'knowledge_bases');

        expect($saved)->toHaveCount(1);
    });

    it('saves the tree it builds, once', function (): void {
        kbEntryMake('a');
        $saved = kbEntryEvents(CollectionTreeSaved::class);
        $creator = new KbEntryTestCreator;
        $creator->write('pendingTreeCollectionHandle', 'knowledge_bases');
        $creator->write('pendingTreeSite', 'default');
        $creator->write('pendingTreeNodes', [['entry' => 'a', 'parent' => null]]);

        $creator->call('buildAndSaveKbTree', new BufferedOutput);

        expect($saved)->toHaveCount(1);
    });

    it('builds nothing without both nodes and where to save them', function (array $state): void {
        $warnings = kbEntryWarnings();
        $creator = new KbEntryTestCreator;

        foreach ($state as $property => $value) {
            $creator->write($property, $value);
        }

        $output = new BufferedOutput;
        $creator->call('buildAndSaveKbTree', $output);

        expect($output->fetch())->toBe('')
            ->and(kbEntryMessages($warnings))->toBe([]);
    })->with([
        'a place but no nodes' => [['pendingTreeCollectionHandle' => 'knowledge_bases', 'pendingTreeSite' => 'default']],
        'nodes but no collection' => [['pendingTreeNodes' => [['entry' => 'a', 'parent' => null]], 'pendingTreeSite' => 'default']],
        'nodes but no site' => [['pendingTreeNodes' => [['entry' => 'a', 'parent' => null]], 'pendingTreeCollectionHandle' => 'knowledge_bases']],
    ]);

    it('nests a recorded entry under its recorded parent, even when the parent comes later', function (): void {
        foreach (['a', 'b', 'c', 'd', 'outside'] as $slug) {
            kbEntryMake($slug);
        }

        $creator = new KbEntryTestCreator;
        $creator->write('pendingTreeCollectionHandle', 'knowledge_bases');
        $creator->write('pendingTreeSite', 'default');
        $creator->write('pendingTreeNodes', [
            ['entry' => 'b', 'parent' => 'a'],
            ['entry' => 'b', 'parent' => 'a'],
            ['entry' => 'c', 'parent' => 'a'],
            ['entry' => 'a', 'parent' => 'outside'],
            ['entry' => 'd', 'parent' => 'c'],
        ]);

        $creator->call('buildAndSaveKbTree', new BufferedOutput);

        expect(kbEntryTree())->toBe([['outside' => [['a' => ['b', ['c' => ['d']]]]]]]);
    });

    it('saves nothing for a site with no tree', function (): void {
        $warnings = kbEntryWarnings();
        $creator = new KbEntryTestCreator;
        $creator->write('pendingTreeCollectionHandle', 'knowledge_bases');
        $creator->write('pendingTreeSite', 'other');
        $creator->write('pendingTreeNodes', [['entry' => 'a', 'parent' => null]]);
        $output = new BufferedOutput;

        $creator->call('buildAndSaveKbTree', $output);

        expect($output->fetch())->toBe('')
            ->and(kbEntryMessages($warnings))->toBe([]);
    });
});

describe('the root, more closely', function (): void {
    it('creates the root in its own site', function (): void {
        Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'], 'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/']]);
        $collection = CollectionFacade::findByHandle('knowledge_bases');
        $collection?->sites(['default', 'other']);
        $collection?->save();
        $creator = new KbEntryTestCreator;
        $creator->call('createInitialTreeStructure', new BufferedOutput, 'other', 'knowledge_bases');

        $creator->call('createKnowledgeBaseEntry', new BufferedOutput, 'other', 'knowledge_bases');

        expect(kbEntry('kb')->locale())->toBe('other')
            ->and(kbEntry('kb')->get('title'))->toBe('Other Knowledge Base');
    });

});

describe('topics, more closely', function (): void {
    it('passes over anything in the term map that is not a term', function (): void {
        $creator = new KbEntryTestCreator;
        $creator->write('termMap', ['5' => ['term_id' => '5', 'term_slug' => 'billing', 'term_name' => 'Billing', 'parent_id' => ''], '6' => 'not a term']);
        $output = new BufferedOutput;

        $creator->call('processTopics', kbEntryParser(kbEntryExport()), $output, 'default', 'knowledge_bases');

        expect(kbEntrySlugs())->toBe(['billing'])
            ->and($output->fetch())->toContain('Found 1 kbtopic terms.');
    });

    it('gives a topic no description when the export does not carry its term', function (?XmlParser $parser): void {
        (new KbEntryTestCreator)->call('createTopicEntryFromData', ['term_id' => '5', 'term_slug' => 'billing'], 'kb', new BufferedOutput, 'default', 'knowledge_bases', $parser);

        expect(kbEntryData(kbEntry('billing')))->toHaveKey('excerpt', '');
    })->with([
        'no parser' => [null],
        'a parser without the term' => [fn (): XmlParser => kbEntryParser(kbEntryExport([kbEntryTopic('6', 'other')]))],
    ]);

    it('keeps the generated id, and leaves the tree alone, for a topic that does not save', function (): void {
        $creator = new KbEntryTestCreator;
        $creator->saveFails = true;

        $id = $creator->call('createTopicEntryFromData', ['term_id' => '5', 'term_slug' => 'billing'], 'kb', new BufferedOutput, 'default', 'knowledge_bases');

        expect($id)->toBeString()->toStartWith('billing-5-')
            ->and($creator->read('topicIdMap'))->toBe(['5' => $id])
            ->and($creator->read('pendingTreeNodes'))->toBe([]);
    });
});

describe('articles, more closely', function (): void {
    it('decodes the title once more than the stripper does', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'coded', 'title' => '&AMP;copy; 2025'])]));

        expect(kbEntry('coded')->get('title'))->toBe('© 2025');
    });

    it('decodes the kb_page title once more than the stripper does', function (): void {
        kbEntryRun(kbEntryExport([], [['type' => 'page', 'id' => '77', 'slug' => '', 'title' => '&AMP;copy; Help']]), ['kb_page' => '77']);

        expect(kbEntrySlugs())->toBe(['help']);
    });

    it('converts no content that is only space', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'spaced', 'content' => "  \n "])]));

        expect(kbEntry('spaced')->get('content'))->toBeNull()
            ->and(ProcessingContext::getStatamicEntryId())->toBeNull();
    });

    it('sets nothing up for the content converter when there is no content', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'draft', 'content' => '', 'status' => 'draft', 'meta' => ['_thumbnail_id' => '41']])]));

        expect(ProcessingContext::getStatamicEntryId())->toBeNull()
            ->and(ProcessingContext::getEntryData())->toBeNull();
    });

    it('converts content as a whole body, keeping what its block comments say', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'aligned', 'content' => '<!-- wp:image {"align":"left","id":5} --><figure><img src="x.jpg"/></figure><!-- /wp:image -->'])]));
        $content = kbEntry('aligned')->get('content');
        $set = \is_array($content) && \is_array($content[0] ?? null) && \is_array($content[0]['attrs'] ?? null) ? $content[0]['attrs'] : [];

        expect($set['values'] ?? null)->toBe(['type' => 'image', 'image' => 'x.jpg', 'align' => 'alignleft']);
    });

    it('converts content without adding paragraphs, as a whole body', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'lines', 'content' => "One\n\nTwo"])]));

        expect(kbEntry('lines')->get('content'))->toBe([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'OneTwo']], 'attrs' => []]]);
    });

    it('records the date of an article for the deferred pass, or none without one', function (): void {
        $creator = new KbEntryTestCreator;
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'dated']), kbEntryArticle('902', ['billing'], ['slug' => 'undated', 'date' => null])]), creator: $creator);
        $deferred = $creator->read('deferredRelatedArticles');
        $deferred = \is_array($deferred) ? $deferred : [];
        $date = static fn (string $slug): mixed => \is_array($deferred[kbEntryId($slug)] ?? null) && \array_key_exists('date', $deferred[kbEntryId($slug)]) ? $deferred[kbEntryId($slug)]['date'] : 'missing';

        expect(($dated = $date('dated')) instanceof Carbon ? $dated->format('Y-m-d H:i:s') : $dated)->toBe('2025-04-01 09:00:00')
            ->and($date('undated'))->toBeNull();
    });

    it('logs the parse error with a date it cannot read', function (): void {
        $warnings = kbEntryWarnings();

        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'dateless', 'date' => 'not a date'])]));
        $context = kbEntryContext($warnings, 'Invalid date format');

        expect(\is_array($context) ? \array_keys($context) : [])->toBe(['site', 'error', 'entry_url'])
            ->and(\is_array($context) ? $context['error'] : null)->toBeString()->toContain('not a date');
    });

    it('keeps the generated id, and leaves the tree alone, for an article that does not save', function (): void {
        $creator = new KbEntryTestCreator;
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')]), creator: $creator);
        $nodes = $creator->read('pendingTreeNodes');
        $creator->saveFails = true;
        $item = kbEntryElement('<item xmlns:wp="http://wordpress.org/export/1.2/"><title>T</title><wp:post_id>901</wp:post_id><wp:post_name>unsaved</wp:post_name><category domain="kbtopic" nicename="billing">B</category></item>');

        $creator->call('createArticleEntry', $item, new BufferedOutput, 'default', kbEntryParser(kbEntryExport()), new AssetImporter, 'knowledge_bases');
        $map = $creator->read('articleIdMap');

        expect(\is_array($map) ? ($map[901] ?? null) : null)->toBeString()->toStartWith('unsaved-901-')
            ->and($creator->read('pendingTreeNodes'))->toBe($nodes);
    });

    it('takes the first of each repeated meta', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [
            ['type' => 'attachment', 'id' => '40', 'url' => 'https://example.test/files/second.jpg'],
            ['type' => 'attachment', 'id' => '41', 'url' => 'https://example.test/files/first.jpg'],
            ['type' => 'attachment', 'id' => '41', 'url' => 'https://example.test/files/again.jpg'],
            ['type' => 'attachment', 'id' => '50', 'url' => 'https://example.test/files/a.pdf'],
            ['type' => 'attachment', 'id' => '51', 'url' => 'https://example.test/files/b.pdf'],
            kbEntryArticle('901', ['billing'], ['slug' => 'repeated', 'meta' => [
                ['_thumbnail_id', '41'], ['_thumbnail_id', '40'],
                ['_mkb_restrict_role', '["guest"]'], ['_mkb_restrict_role', '["subscriber"]'],
                ['_mkb_article_attachments', \serialize(['50'])], ['_mkb_article_attachments', \serialize(['51'])],
                ['_mkb_related_articles', \serialize(['901'])], ['_mkb_related_articles', \serialize(['999'])],
            ]]),
        ]));
        $data = kbEntryData(kbEntry('repeated'));
        $attachments = \is_array($data['attachment'] ?? null) ? $data['attachment'] : [];

        expect($data['featured_image_16_9'] ?? null)->toBe('https://example.test/files/first.jpg')
            ->and($data['_thumbnail_id'] ?? null)->toBe('41')
            ->and($data['restriction_query'] ?? null)->toBeFalse()
            ->and(\array_map(static fn (mixed $row): mixed => \is_array($row) ? ($row['asset'] ?? null) : null, $attachments))->toBe(['https://example.test/files/a.pdf'])
            ->and($data['related_articles'] ?? null)->toBe([kbEntryId('repeated')]);
    });

    it('stops at the first description meta of a term, blank or not', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing', ['meta' => [['description', ' '], ['description', 'Later']]])]));

        expect(kbEntry('billing')->get('excerpt'))->toBe('');
    });

    it('sets no featured image for a blank thumbnail id, even beside an attachment with no id', function (): void {
        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [['type' => 'attachment', 'id' => '', 'url' => 'https://example.test/files/noid.jpg'], kbEntryArticle('901', ['billing'], ['slug' => 'blank', 'meta' => ['_thumbnail_id' => '']])]));

        expect(kbEntryData(kbEntry('blank')))->not->toHaveKey('featured_image_16_9');
    });

    it('builds no object from serialized attachments or related articles', function (string $key): void {
        KbEntryWakeupProbe::$woken = 0;

        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'probed', 'meta' => [$key => 'a:1:{i:0;O:18:"KbEntryWakeupProbe":0:{}}']])]));

        expect(KbEntryWakeupProbe::$woken)->toBe(0);
    })->with([
        'attachments' => ['_mkb_article_attachments'],
        'related articles' => ['_mkb_related_articles'],
    ]);

    it('lists no attachment the importer returns no file for', function (?string $imported): void {
        config(['wordpress-import.upload_files' => true]);

        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [['type' => 'attachment', 'id' => '50', 'url' => 'https://example.test/files/a.pdf'], kbEntryArticle('901', ['billing'], ['slug' => 'unfiled', 'meta' => ['_mkb_article_attachments' => \serialize(['50'])]])]), assetImporter: new FakeAssetImporter(importedFile: $imported));

        expect(kbEntryData(kbEntry('unfiled')))->not->toHaveKey('attachment');
    })->with([
        'no file' => [null],
        'a blank file' => [''],
    ]);
});

describe('related articles, more closely', function (): void {
    it('names an untitled entry as blank in what it logs', function (): void {
        $warnings = kbEntryWarnings();
        $creator = new KbEntryTestCreator;
        $entry = EntryFacade::make();

        if (! $entry instanceof Entry) {
            throw new RuntimeException('Could not make an entry.');
        }

        $entry->collection('knowledge_bases');
        $entry->locale('default');
        $entry->slug('untitled');
        $entry->id('untitled');
        $entry->save();
        $creator->write('articleIdMap', [7 => 'untitled']);
        $creator->write('deferredRelatedArticles', ['untitled' => ['wp_post_ids' => ['7', '8'], 'slug' => null, 'date' => null]]);
        Event::listen(EntrySaving::class, static fn (): bool => false);

        $creator->call('processDeferredRelatedArticles', new BufferedOutput, 'default', 'knowledge_bases');

        expect(kbEntryMessages($warnings))->toContain("ERROR KbEntryCreator::processDeferredRelatedArticles: Failed to update entry '' (ID: untitled) with related articles for site default.")
            ->toContain("KbEntryCreator::processDeferredRelatedArticles: Entry '' (ID: untitled) has unmapped related articles: 8 for site default.")
            ->and(kbEntryContext($warnings, "Entry '' (ID: untitled)"))->toMatchArray(['entry_title' => '']);
    });
});

describe('the knowledge base home page, more closely', function (): void {
    it('logs nothing about a kb_page it finds', function (): void {
        $warnings = kbEntryWarnings();

        kbEntryRun(kbEntryExport([], [['type' => 'page', 'id' => '77', 'slug' => 'help']]), ['kb_page' => '77']);

        expect(\array_filter(kbEntryMessages($warnings), static fn (string $message): bool => \str_contains($message, 'findPageInXml')))->toBe([]);
    });

    it('finds no page, and logs nothing, in a parser with a blank path', function (): void {
        $warnings = kbEntryWarnings();
        $parser = new XmlParser;
        $parser->setFilePath('');

        expect((new KbEntryTestCreator)->call('findPageInXml', $parser, '77'))->toBeNull()
            ->and(kbEntryMessages($warnings))->toBe([]);
    });
});

describe('what the review asked about', function (): void {
    it('leaves the related articles of an article it skips as they are', function (): void {
        $export = kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'one', 'meta' => ['_mkb_related_articles' => \serialize(['902'])]]), kbEntryArticle('902', ['billing'], ['slug' => 'two'])]);
        kbEntryRun($export);
        $one = kbEntry('one');
        $one->set('related_articles', ['curated']);
        $one->save();
        $creator = new KbEntryTestCreator;
        $creator->setEntryOverwriteBehavior('Skip matching files');

        $run = kbEntryRun($export, creator: $creator);

        expect(kbEntry('one')->get('related_articles'))->toBe(['curated'])
            ->and($creator->read('deferredRelatedArticles'))->toBe([])
            ->and($run['output'])->not->toContain('Processing deferred related articles...');
    });

    it('links the related articles of a new article under skip-matching', function (): void {
        $creator = new KbEntryTestCreator;
        $creator->setEntryOverwriteBehavior('Skip matching files');

        kbEntryRun(kbEntryExport([kbEntryTopic('5', 'billing')], [kbEntryArticle('901', ['billing'], ['slug' => 'one', 'meta' => ['_mkb_related_articles' => \serialize(['902'])]]), kbEntryArticle('902', ['billing'], ['slug' => 'two'])]), creator: $creator);

        expect(kbEntry('one')->get('related_articles'))->toBe([kbEntryId('two')]);
    });

    it('creates a topic whose term id a later term of another taxonomy shares', function (): void {
        kbEntryRun(kbEntryExport(
            [kbEntryTopic('5', 'billing'), ['id' => '5', 'taxonomy' => 'kbtag', 'slug' => 'fast']],
            [kbEntryArticle('901', ['billing'], ['slug' => 'shared'])]
        ));

        expect(kbEntrySlugs())->toBe(['billing', 'kb', 'shared'])
            ->and(kbEntry('shared')->get('parent'))->toBe(kbEntryId('billing'));
    });

    it('dates the root in a collection whose dated setting is truthy', function (): void {
        $collection = CollectionFacade::make('knowledge_bases');
        $collection->sites(['default']);
        $collection->dated(1);
        $collection->structureContents(['root' => false]);
        $collection->save();
        $blueprint = BlueprintFacade::make('knowledge_base');
        $blueprint->setNamespace('collections.knowledge_bases');
        $blueprint->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']], ['handle' => 'date', 'field' => ['type' => 'date']]]]);
        $blueprint->save();
        $warnings = kbEntryWarnings();

        (new KbEntryTestCreator)->call('createKnowledgeBaseEntry', new BufferedOutput, 'default', 'knowledge_bases');

        expect(kbEntryMessages($warnings))->toBe([]);
    });
});
