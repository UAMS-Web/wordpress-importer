<?php

declare(strict_types=1);

/**
 * Tests `LogoListItemEntryCreator`: what each `logo-list-item` becomes, from its
 * logo fields, image, categories and label to its descriptions and menu order,
 * the categories it keeps for validation, and the items it skips.
 *
 * The term pass runs for real against `logos_item_categories` and
 * `logos_item_labels` collections this test makes, so an item's categories and
 * label resolve to the entries that pass writes. The site and structure managers
 * are mocks, the description converter is a recording subclass, and uploads are
 * off unless a test turns them on.
 *
 * @command  php vendor/bin/pest tests/Integration/LogoListItemEntryCreatorTest.php
 */

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
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
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\LogoListItemEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * A description converter that records each call and returns a chosen body.
 */
final class LogoItemEntryTestConverter extends ContentProcessor
{
    /**
     * The arguments of each call, by name.
     *
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    /**
     * What every call returns.
     *
     * @var array{content: mixed, page_builder: array<int|string, mixed>}
     */
    public array $result = ['content' => null, 'page_builder' => []];

    /**
     * Build the converter without running `ContentProcessor`'s constructor.
     *
     * @param  array{content: mixed, page_builder: array<int|string, mixed>}  $result  What every call returns
     * @return self The converter
     */
    public static function returning(array $result): self
    {
        $converter = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $converter->result = $result;

        return $converter;
    }

    /**
     * Record the call and return the chosen body.
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
     * @return array{content: mixed, page_builder: array<int|string, mixed>} The chosen body
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
        $this->calls[] = [
            'content' => $content,
            'site' => $site,
            'title' => $pageTitle,
            'id' => $pageId,
            'link' => $pageLink,
            'attachments' => \count($attachments),
            'terms' => \count($terms),
            'items' => $items,
            'collection' => $collectionHandle,
            'slug' => $pageSlug,
            'entry' => $statamicEntryId,
            'logo_image' => $data['logo_image'] ?? null,
        ];

        return $this->result;
    }
}

/**
 * A metadata mapper that records each person and marks the data it maps.
 */
final class LogoItemEntryTestMapper extends MetadataMapper
{
    /**
     * The post id of each item mapped.
     *
     * @var list<string>
     */
    public array $mapped = [];

    /**
     * Record the item and mark the data.
     *
     * @param  SimpleXMLElement  $page  The item
     * @param  array<int|string, mixed>  $data  The entry data
     * @param  XmlParser  $xmlParser  The parser
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  array<int|string, mixed>  $attachments  Attachment elements
     * @param  OutputInterface  $output  Console output
     */
    public function mapMetadata(
        SimpleXMLElement $page,
        array &$data,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        array $attachments,
        OutputInterface $output
    ): void {
        $this->mapped[] = (string) $page->children('wp', true)->post_id;
        $data['mapped'] = true;
    }
}

/**
 * A description converter that throws for item 701 and converts every other.
 */
final class LogoItemThrowingConverter extends ContentProcessor
{
    /**
     * Throw for item 701 and return a body for the rest.
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
        if ($pageId === '701') {
            throw new RuntimeException('conversion failed');
        }

        return logoItemBody();
    }
}

/**
 * A class whose unserializing would be recorded, to show the import never builds one.
 */
final class LogoItemWakeupProbe
{
    /**
     * Whether any instance has been unserialized.
     */
    public static bool $woken = false;

    /**
     * Record that an instance was unserialized.
     */
    public function __wakeup(): void
    {
        self::$woken = true;
    }
}

/**
 * An item's string field, or the default when it is absent.
 *
 * @param  array<mixed>  $item  The item's fields
 * @param  string  $key  The field
 * @param  string  $default  The default
 * @return string The value
 */
function logoItemField(array $item, string $key, string $default): string
{
    $value = $item[$key] ?? $default;

    return \is_string($value) ? $value : $default;
}

/**
 * An item's map of strings, or none when it is absent.
 *
 * @param  array<mixed>  $item  The item's fields
 * @param  string  $key  The field
 * @return array<string> The values
 */
function logoItemFields(array $item, string $key): array
{
    $values = $item[$key] ?? [];

    return \is_array($values) ? \array_filter($values, \is_string(...)) : [];
}

/**
 * Write an export of logo categories and labels, an attachment and the given items.
 *
 * @param  list<array<mixed>>  $items  Each item's fields
 * @return string The export path
 */
function logoItemExport(array $items): string
{
    $xml = '';

    foreach ($items as $index => $item) {
        $postId = logoItemField($item, 'id', (string) (700 + $index));
        $slug = logoItemField($item, 'slug', "logo-{$postId}");
        $title = logoItemField($item, 'title', "Logo {$postId}");
        $status = logoItemField($item, 'status', 'publish');
        $order = logoItemField($item, 'order', '3');
        $date = logoItemField($item, 'date', '2025-04-01 09:00:00');
        $idXml = $postId === 'none' ? '' : "<wp:post_id>{$postId}</wp:post_id>";
        $meta = '';

        foreach (logoItemFields($item, 'meta') as $key => $value) {
            $meta .= "<wp:postmeta><wp:meta_key>{$key}</wp:meta_key><wp:meta_value><![CDATA[{$value}]]></wp:meta_value></wp:postmeta>";
        }

        $xml .= <<<XML
<item>
  <title><![CDATA[{$title}]]></title>
  <link>https://example.test/logos/{$postId}/</link>
  {$idXml}
  <wp:post_name>{$slug}</wp:post_name>
  <wp:post_date>{$date}</wp:post_date>
  <wp:post_modified>2025-04-02 10:00:00</wp:post_modified>
  <wp:status>{$status}</wp:status>
  <wp:post_parent>0</wp:post_parent>
  <wp:menu_order>{$order}</wp:menu_order>
  <wp:post_type>logo-list-item</wp:post_type>
  {$meta}
</item>

XML;
    }

    $path = TemporaryFileRegistry::create('logo-item-entry');

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Logos</title>
<link>https://example.test</link>
<wp:base_blog_url>https://example.test</wp:base_blog_url>
<wp:term><wp:term_id>71</wp:term_id><wp:term_taxonomy>logo-list-category</wp:term_taxonomy><wp:term_slug>partners</wp:term_slug><wp:term_name><![CDATA[Partners]]></wp:term_name></wp:term>
<wp:term><wp:term_id>72</wp:term_id><wp:term_taxonomy>logo-list-category</wp:term_taxonomy><wp:term_slug>sponsors</wp:term_slug><wp:term_parent>partners</wp:term_parent><wp:term_name><![CDATA[Sponsors]]></wp:term_name></wp:term>
<wp:term><wp:term_id>81</wp:term_id><wp:term_taxonomy>logo-list-label</wp:term_taxonomy><wp:term_slug>featured</wp:term_slug><wp:term_name><![CDATA[Featured]]></wp:term_name></wp:term>
<item>
  <title>logo</title>
  <link>https://example.test/logos/logo/</link>
  <wp:post_id>900</wp:post_id>
  <wp:post_type>attachment</wp:post_type>
  <wp:attachment_url>https://example.test/wp-content/uploads/2025/04/logo.png</wp:attachment_url>
</item>
{$xml}</channel>
</rss>
XML);

    return $path;
}

/**
 * A site manager that records each collection it is asked to enable.
 */
final class LogoItemTestSiteManager extends SiteManager
{
    /**
     * The site, collection and whether output was given, for each call.
     *
     * @var list<array{0: string, 1: string, 2: bool}>
     */
    public array $calls = [];

    /**
     * Build the manager.
     *
     * @param  ?Closure  $onCall  Run on each call, which comes just after the term pass
     */
    public function __construct(
        private readonly ?Closure $onCall = null
    ) {}

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

        if ($this->onCall instanceof Closure) {
            ($this->onCall)();
        }

        return true;
    }
}

/**
 * A structure manager that records each structure it is asked to initialize.
 */
final class LogoItemTestStructureManager extends CollectionStructureManager
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
 * Build a creator with recording managers.
 *
 * @param  ?Closure  $afterTermPass  Run when the site manager is called, which is just after the term pass
 * @return LogoListItemEntryCreator The creator
 */
function logoItemCreator(?Closure $afterTermPass = null): LogoListItemEntryCreator
{
    return new LogoListItemEntryCreator(new LogoItemTestSiteManager($afterTermPass), new LogoItemTestStructureManager);
}

/**
 * Run the creator over an export.
 *
 * @param  string  $export  The export path
 * @param  ContentProcessor  $converter  The description converter
 * @param  ?LogoListItemEntryCreator  $creator  The creator, or null for a new one
 * @param  ?AssetImporter  $assetImporter  The asset importer, or null for a new one
 * @return array{output: string, result: array<int|string, mixed>, creator: LogoListItemEntryCreator} What the run produced
 */
function logoItemRun(string $export, ContentProcessor $converter, ?LogoListItemEntryCreator $creator = null, ?AssetImporter $assetImporter = null): array
{
    $parser = new XmlParser;
    $parser->parse($export);
    $output = new BufferedOutput;
    $creator ??= logoItemCreator();

    $result = $creator->createEntries('default', 'logo_list_items', $parser, $assetImporter ?? new AssetImporter, $output, new LogoItemEntryTestMapper, $converter);

    return ['output' => $output->fetch(), 'result' => $result, 'creator' => $creator];
}

/**
 * The saved item with the given slug, or null when none was saved.
 *
 * @param  string  $slug  The slug
 * @return ?Entry The entry
 */
function logoItemFind(string $slug): ?Entry
{
    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'logo_list_items' && $entry->slug() === $slug) {
            return $entry;
        }
    }

    return null;
}

/**
 * The saved item with the given slug.
 *
 * @param  string  $slug  The slug
 * @return Entry The entry
 */
function logoItemEntry(string $slug): Entry
{
    return logoItemFind($slug) ?? throw new RuntimeException("No logo_list_items entry '{$slug}'.");
}

/**
 * Everything an entry stores.
 *
 * @param  Entry  $entry  The entry
 * @return array<mixed> The entry's data
 */
function logoItemData(Entry $entry): array
{
    $data = $entry->data();

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * A description converter result with the given Bard content.
 *
 * @param  mixed  $content  The content
 * @return array{content: mixed, page_builder: array<int|string, mixed>} The result
 */
function logoItemBody(mixed $content = null): array
{
    return [
        'content' => $content ?? [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Long.']]]],
        'page_builder' => [],
    ];
}

beforeEach(function (): void {
    config([
        'statamic.search.indexes' => [],
        'wordpress-import.upload_images' => false,
        'wordpress-import.upload_files' => false,
    ]);
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    $items = CollectionFacade::make('logo_list_items');
    $items->sites(['default']);
    $items->save();

    foreach (['logos_item_categories', 'logos_item_labels'] as $handle) {
        $collection = CollectionFacade::make($handle);
        $collection->sites(['default']);
        $collection->structureContents(['root' => false]);
        $collection->save();
    }

    $blueprint = BlueprintFacade::make('logo_list_item');
    $blueprint->setNamespace('collections.logo_list_items');
    $blueprint->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
    $blueprint->save();

    BaseEntryCreator::clearTaxonomyCaches();
    TermDestinationRegistry::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    TermDestinationRegistry::clear();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();

    $blueprint = BlueprintFacade::find('collections.logo_list_items.logo_list_item');

    if ($blueprint instanceof Blueprint) {
        $blueprint->delete();
    }

    TemporaryFileRegistry::sweep();
    Mockery::close();
});

/**
 * The postmeta of the item the main test imports.
 *
 * @return array<string, string> The postmeta
 */
function logoItemMeta(): array
{
    return [
        'logo_title' => 'Acme',
        'logo_image' => '900',
        'logo_link' => 'https://acme.test',
        'logo_link_text' => 'Visit',
        'logo_link_target' => '_blank',
        'logo_category' => 'a:3:{i:0;s:2:"71";i:1;i:72;i:2;s:2:"99";}',
        'logo_label' => '81',
        'logo_shortdescr' => 'Short.',
        'logo_longdescr' => '<p>Long.</p>',
        '_thumbnail_id' => '900',
    ];
}

describe('importing a logo list item', function (): void {
    it('saves an item with its logo fields, categories, label, descriptions and order', function (): void {
        $converter = LogoItemEntryTestConverter::returning(logoItemBody());
        $siteManager = new LogoItemTestSiteManager;
        $structureManager = new LogoItemTestStructureManager;
        $run = logoItemRun(logoItemExport([['id' => '701', 'slug' => 'acme', 'title' => 'Acme &amp; Co', 'meta' => logoItemMeta()]]), $converter, new LogoListItemEntryCreator($siteManager, $structureManager));
        $entry = logoItemEntry('acme');

        expect(logoItemData($entry))->toBe([
            'blueprint' => 'logo_list_item',
            'title' => 'Acme & Co',
            'logo_title' => 'Acme',
            'logo_image' => 'https://example.test/wp-content/uploads/2025/04/logo.png',
            'logo_link' => 'https://acme.test',
            'logo_link_text' => 'Visit',
            'logo_link_target' => true,
            'logos_item_categories' => [
                TermDestinationRegistry::referenceFor('logo-list-category', '71'),
                TermDestinationRegistry::referenceFor('logo-list-category', '72'),
            ],
            'logos_item_labels' => [TermDestinationRegistry::referenceFor('logo-list-label', '81')],
            'logo_shortdescr' => 'Short.',
            '_thumbnail_id' => '900',
            'logo_longdescr' => logoItemBody()['content'],
            'order_values' => ['default-general' => '3'],
            'original_wordpress_url' => 'https://example.test/logos/701/',
            'updated_at' => (new DateTime('2025-04-02 10:00:00'))->getTimestamp(),
        ])
            ->and($entry->id())->toStartWith('acme-701-')
            ->and($siteManager->calls)->toBe([['default', 'logo_list_items', true]])
            ->and($structureManager->calls)->toBe([['logo_list_items', 'default']])
            ->and(\array_map(static fn (array $call): array => \array_diff_key($call, ['items' => true]), $converter->calls))->toBe([[
                'content' => '<p>Long.</p>',
                'site' => 'default',
                'title' => 'Acme & Co',
                'id' => '701',
                'link' => 'https://example.test/logos/701/',
                'attachments' => 1,
                'terms' => 3,
                'collection' => 'logo_list_items',
                'slug' => 'acme',
                'entry' => $entry->id(),
                'logo_image' => 'https://example.test/wp-content/uploads/2025/04/logo.png',
            ]])
            ->and(\is_array($converter->calls[0]['items'] ?? null) ? \count($converter->calls[0]['items']) : null)->toBe(2)
            ->and($run['output'])->toContain('Processing logo list items...'.PHP_EOL)
            ->toContain("Processing logo list item 'acme' with content length: 18".PHP_EOL)
            ->toContain('Logo list item processing complete: 1 processed, 0 skipped'.PHP_EOL)
            ->and(ProcessingContext::getWordPressPageLink())->toBe('https://example.test/logos/701/')
            ->and($run['creator']->getPostCategories())->toBe([
                'default_partners' => ['acme'],
                'default_partners_sponsors' => ['acme'],
            ])
            ->and(\array_keys($run['result']))->toBe([701])
            ->and(\array_diff_key(\is_array($run['result'][701] ?? null) ? $run['result'][701] : [], ['entry_object' => true]))->toBe([
                'entry' => $entry->id(),
                'parent' => '0',
                'menu_order' => 3,
                'title' => 'Acme & Co',
                'link' => 'https://example.test/logos/701/',
            ]);
    });

    it('falls back to a flat category reference, and omits a label, when the term pass recorded no destination', function (): void {
        logoItemRun(
            logoItemExport([['slug' => 'flat', 'meta' => ['logo_category' => 'a:2:{i:0;s:2:"72";i:1;s:2:"99";}', 'logo_label' => '81']]]),
            LogoItemEntryTestConverter::returning(logoItemBody()),
            logoItemCreator(static fn () => TermDestinationRegistry::clear())
        );
        $data = logoItemData(logoItemEntry('flat'));

        expect($data['logos_item_categories'] ?? null)->toBe(['logos_item_categories::default_partners_sponsors'])
            ->and($data)->not->toHaveKey('logos_item_labels');
    });

    it('stores no categories for a value that is not a serialized list, or holds no usable id', function (string $value): void {
        logoItemRun(logoItemExport([['slug' => 'uncategorized', 'meta' => ['logo_category' => $value, 'logo_label' => 'unknown']]]), LogoItemEntryTestConverter::returning(logoItemBody()));
        $data = logoItemData(logoItemEntry('uncategorized'));

        expect($data)->not->toHaveKeys(['logos_item_categories', 'logos_item_labels']);
    })->with([
        'not serialized' => ['71'],
        'a serialized string' => ['s:2:"71";'],
        'blank, unusable and unknown ids' => ['a:4:{i:0;s:0:"";i:1;b:1;i:2;a:0:{}i:3;s:2:"99";}'],
    ]);

    it('leaves out every blank field, and reads a target other than _blank as false', function (): void {
        logoItemRun(logoItemExport([['slug' => 'sparse', 'meta' => [
            'logo_title' => '',
            'logo_image' => '',
            'logo_link' => '',
            'logo_link_text' => '',
            'logo_link_target' => '_self',
            'logo_category' => '',
            'logo_label' => '',
            'logo_shortdescr' => '',
            'logo_longdescr' => '',
            '_thumbnail_id' => '',
        ]]]), LogoItemEntryTestConverter::returning(logoItemBody()));
        $data = logoItemData(logoItemEntry('sparse'));

        expect($data['logo_link_target'] ?? null)->toBeFalse()
            ->and($data)->not->toHaveKeys(['logo_title', 'logo_image', 'logo_link', 'logo_link_text', 'logos_item_categories', 'logos_item_labels', 'logo_shortdescr', 'logo_longdescr', '_thumbnail_id']);
    });

    it('stores no long description that converts to nothing', function (mixed $content): void {
        logoItemRun(logoItemExport([['slug' => 'no-long', 'meta' => ['logo_longdescr' => '<p></p>']]]), LogoItemEntryTestConverter::returning(['content' => $content, 'page_builder' => []]));

        expect(logoItemData(logoItemEntry('no-long')))->not->toHaveKey('logo_longdescr');
    })->with([
        'an empty list' => [[]],
        'no content' => [null],
    ]);

    it('names an untitled item, and decodes a double-encoded title', function (string $title, string $expected): void {
        logoItemRun(logoItemExport([['slug' => 'named', 'title' => $title]]), LogoItemEntryTestConverter::returning(logoItemBody()));

        expect(logoItemEntry('named')->get('title'))->toBe($expected);
    })->with([
        'untitled' => ['', 'Untitled'],
        'double-encoded' => ['Say &AMP;quot;hi&AMP;quot;', 'Say "hi"'],
    ]);
});

describe('skipping a logo list item', function (): void {
    it('skips an item with no post id and carries on, and never sees a trashed one', function (): void {
        /** @var ArrayObject<int, array{0: mixed, 1: mixed}> $warnings */
        $warnings = new ArrayObject;
        Log::shouldReceive('warning')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($warnings): void {
            $warnings->append([$message, $context]);
        });
        $run = logoItemRun(logoItemExport([
            ['id' => 'none', 'slug' => 'no-id', 'title' => 'No Id'],
            ['slug' => 'trashed', 'status' => 'trash'],
            ['slug' => 'kept'],
        ]), LogoItemEntryTestConverter::returning(logoItemBody()));

        expect(logoItemFind('no-id'))->toBeNull()
            ->and(logoItemFind('trashed'))->toBeNull()
            ->and(logoItemFind('kept'))->not->toBeNull()
            ->and($run['output'])->toContain('Logo list item processing complete: 1 processed, 1 skipped'.PHP_EOL)
            ->and($warnings->getArrayCopy())->toHaveCount(1)
            ->and($warnings[0][0] ?? null)->toContain('Skipping logo list item with missing post ID or title')
            ->and($warnings[0][1] ?? null)->toMatchArray(['post_id' => '', 'title' => 'No Id']);
    });

    it('skips an item that already exists when told to', function (): void {
        $export = logoItemExport([['id' => '701', 'slug' => 'again']]);
        logoItemRun($export, LogoItemEntryTestConverter::returning(logoItemBody()));
        BaseEntryCreator::clearTaxonomyCaches();
        TermDestinationRegistry::clear();

        $creator = logoItemCreator();
        $creator->setEntryOverwriteBehavior('Skip matching files');
        $converter = LogoItemEntryTestConverter::returning(logoItemBody());
        $run = logoItemRun($export, $converter, $creator);

        expect($converter->calls)->toBe([])
            ->and($run['output'])->toContain("Skipped entry 'again' as it already exists.".PHP_EOL)
            ->toContain('0 processed, 1 skipped')
            ->and($run['result'])->toBe([]);
    });

    it('reports an item whose processing throws, counts it skipped and carries on', function (): void {
        /** @var ArrayObject<int, array{0: mixed, 1: mixed}> $errors */
        $errors = new ArrayObject;
        Log::shouldReceive('error')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($errors): void {
            $errors->append([$message, $context]);
        });
        $converter = (new ReflectionClass(LogoItemThrowingConverter::class))->newInstanceWithoutConstructor();
        $run = logoItemRun(logoItemExport([
            ['id' => '701', 'slug' => 'broken', 'meta' => ['logo_longdescr' => '<p>x</p>']],
            ['id' => '702', 'slug' => 'fine', 'meta' => ['logo_longdescr' => '<p>y</p>']],
        ]), $converter);
        $first = $errors[0] ?? null;

        expect(logoItemFind('broken'))->toBeNull()
            ->and(logoItemFind('fine'))->not->toBeNull()
            ->and($run['output'])->toContain('Error processing logo list item: conversion failed'.PHP_EOL)
            ->toContain('1 processed, 1 skipped')
            ->and($errors->getArrayCopy())->toHaveCount(1)
            ->and($first[0] ?? null)->toBe('LogoListItemEntryCreator::createEntries: Error processing logo list item')
            ->and(\is_array($first[1] ?? null) ? \array_diff_key($first[1], ['trace' => true]) : null)->toBe(['post_id' => '701', 'error' => 'conversion failed'])
            ->and(\is_array($first[1] ?? null) ? ($first[1]['trace'] ?? null) : null)->toBeString()->not->toBe('');
    });

    it('counts an item that fails to save as skipped, and tracks no category for it', function (): void {
        $creator = new class(new LogoItemTestSiteManager, new LogoItemTestStructureManager) extends LogoListItemEntryCreator
        {
            /**
             * Fail every save.
             *
             * @param  string  $collectionHandle  The collection handle
             * @param  string  $site  The site handle
             * @param  string  $entrySlug  The entry slug
             * @param  array<int|string, mixed>  $data  The entry data
             * @param  OutputInterface  $output  Console output
             * @param  ?DateTime  $entryDate  The entry date
             * @param  ?bool  $published  Whether the entry is published
             * @param  ?string  $postDateStr  The post date
             * @return ?Entry Always null
             */
            protected function saveEntry(string $collectionHandle, string $site, string $entrySlug, array $data, OutputInterface $output, ?DateTime $entryDate = null, ?bool $published = null, ?string $postDateStr = null): ?Entry
            {
                return null;
            }
        };
        $run = logoItemRun(logoItemExport([['slug' => 'unsaved', 'meta' => ['logo_category' => 'a:1:{i:0;s:2:"71";}']]]), LogoItemEntryTestConverter::returning(logoItemBody()), $creator);

        expect($run['result'])->toBe([])
            ->and($run['output'])->toContain('0 processed, 1 skipped')
            ->and($creator->getPostCategories())->toBe([]);
    });
});

describe('the content type\'s other answers', function (): void {
    it('needs the label and category taxonomies first, and has no people, classic block or tags', function (): void {
        $creator = logoItemCreator();

        expect(LogoListItemEntryCreator::getTaxonomyHandlesForLogoListImport())->toBe(['logo-list-label', 'logo-list-category'])
            ->and($creator->hasShowPeople())->toBeFalse()
            ->and($creator->hasClassicBlock())->toBeFalse()
            ->and($creator->getPostTags())->toBe([])
            ->and($creator->getPostCategories())->toBe([]);
    });

    it('hands the validator the categories the import tracked', function (): void {
        $run = logoItemRun(logoItemExport([['slug' => 'tracked', 'meta' => ['logo_category' => 'a:1:{i:0;s:2:"71";}']]]), LogoItemEntryTestConverter::returning(logoItemBody()));
        $output = new BufferedOutput;
        $run['creator']->validateTaxonomies('logo_list_items', 'default', $output);
        $empty = new BufferedOutput;
        logoItemCreator()->validateTaxonomies('logo_list_items', 'default', $empty);

        expect($output->fetch())->toContain('All logos item category terms referenced in logo list entries exist')
            ->and($empty->fetch())->toContain('No logo list entries found that reference');
    });
});

describe('what an item carries', function (): void {
    it('stores no logo field and converts nothing for an item with no postmeta', function (): void {
        $converter = LogoItemEntryTestConverter::returning(logoItemBody());
        $run = logoItemRun(logoItemExport([['slug' => 'bare']]), $converter);

        expect(logoItemData(logoItemEntry('bare')))->not->toHaveKeys([
            'logo_title', 'logo_image', 'logo_link', 'logo_link_text', 'logo_link_target',
            'logos_item_categories', 'logos_item_labels', 'logo_shortdescr', 'logo_longdescr', '_thumbnail_id',
        ])
            ->and($converter->calls)->toBe([])
            ->and($run['creator']->getPostCategories())->toBe([])
            ->and($run['output'])->toContain("Processing logo list item 'bare' with content length: 0".PHP_EOL);
    });

    it('goes on to the next category after one it cannot use', function (): void {
        $run = logoItemRun(logoItemExport([['slug' => 'mixed', 'meta' => ['logo_category' => 'a:2:{i:0;b:1;i:1;s:2:"71";}']]]), LogoItemEntryTestConverter::returning(logoItemBody()));

        expect(logoItemEntry('mixed')->get('logos_item_categories'))->toBe([TermDestinationRegistry::referenceFor('logo-list-category', '71')])
            ->and($run['creator']->getPostCategories())->toBe(['default_partners' => ['mixed']]);
    });

    it('builds no object from a serialized category value', function (): void {
        LogoItemWakeupProbe::$woken = false;

        logoItemRun(logoItemExport([['slug' => 'objects', 'meta' => ['logo_category' => 'a:1:{i:0;O:19:"LogoItemWakeupProbe":0:{}}']]]), LogoItemEntryTestConverter::returning(logoItemBody()));

        expect((new ReflectionProperty(LogoItemWakeupProbe::class, 'woken'))->getValue())->toBeFalse()
            ->and(logoItemData(logoItemEntry('objects')))->not->toHaveKey('logos_item_categories');
    });

    it('imports an item that does not exist yet when told to skip existing ones', function (): void {
        $creator = logoItemCreator();
        $creator->setEntryOverwriteBehavior('Skip matching files');

        $run = logoItemRun(logoItemExport([['slug' => 'new-item']]), LogoItemEntryTestConverter::returning(logoItemBody()), $creator);

        expect(logoItemFind('new-item'))->not->toBeNull()
            ->and($run['output'])->toContain('1 processed, 0 skipped');
    });

    it('clears the taxonomy validator cache, so validation sees the terms just imported', function (): void {
        $cache = new ReflectionProperty(BaseTaxonomyValidator::class, 'existingTermsCache');
        $cache->setValue(null, ['logos_item_categories' => ['stale']]);

        logoItemRun(logoItemExport([['slug' => 'cached']]), LogoItemEntryTestConverter::returning(logoItemBody()));

        expect($cache->getValue())->toBe([]);
    });
});
