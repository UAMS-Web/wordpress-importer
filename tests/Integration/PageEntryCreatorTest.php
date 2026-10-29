<?php

declare(strict_types=1);

/**
 * Tests `PageEntryCreator`: what each `page` item becomes, from its blueprint,
 * defaults and content to its notes for unmapped blocks, its hero, title options
 * and page data, and the pages it skips.
 *
 * The content converter and the metadata mapper are recording subclasses, and
 * the `EntryCreator` it asks about Classic, Row and Freeform blocks is a stand-in
 * that answers from a list each test sets.
 *
 * @command  php vendor/bin/pest tests/Integration/PageEntryCreatorTest.php
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
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PageEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * An `EntryCreator` that answers the block questions from lists a test sets.
 */
final class PageEntryTestEntryCreator extends EntryCreator
{
    /**
     * The Classic, Row and Freeform block details each page carries, by kind and post id.
     *
     * @var array<string, array<int|string, array<int|string, mixed>>>
     */
    public array $blocks = ['Classic' => [], 'Row' => [], 'Freeform' => []];

    /**
     * The kinds asked about, in order, with the post id.
     *
     * @var list<string>
     */
    public array $asked = [];

    /**
     * Build the stand-in without running EntryCreator's constructor.
     *
     * @return self The stand-in
     */
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    /**
     * The page's Classic block details, if it has one.
     *
     * @param  string  $postId  The WordPress post ID
     * @return ?array<int|string, mixed> The details
     */
    public function hasClassicBlock(string $postId): ?array
    {
        $this->asked[] = "Classic:{$postId}";

        return $this->blocks['Classic'][$postId] ?? null;
    }

    /**
     * The page's Row block details, if it has one.
     *
     * @param  string  $postId  The WordPress post ID
     * @return ?array<int|string, mixed> The details
     */
    public function hasRowBlock(string $postId): ?array
    {
        $this->asked[] = "Row:{$postId}";

        return $this->blocks['Row'][$postId] ?? null;
    }

    /**
     * The page's Freeform block details, if it has one.
     *
     * @param  string  $postId  The WordPress post ID
     * @return ?array<int|string, mixed> The details
     */
    public function hasFreeformBlock(string $postId): ?array
    {
        $this->asked[] = "Freeform:{$postId}";

        return $this->blocks['Freeform'][$postId] ?? null;
    }
}

/**
 * A content converter that records each call and returns a chosen body.
 */
final class PageEntryTestConverter extends ContentProcessor
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
            'blueprint' => $data['blueprint'] ?? null,
        ];

        return $this->result;
    }
}

/**
 * A metadata mapper that records each person and marks the data it maps.
 */
final class PageEntryTestMapper extends MetadataMapper
{
    /**
     * The post id of each page mapped.
     *
     * @var list<string>
     */
    public array $mapped = [];

    /**
     * Fields the mapper writes over the data, as a real metadata mapper would.
     *
     * @var array<mixed>
     */
    public array $sets = [];

    /**
     * Record the page and mark the data.
     *
     * @param  SimpleXMLElement  $page  The page
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

        foreach ($this->sets as $key => $value) {
            $data[$key] = $value;
        }
    }
}

/**
 * A page's string field, or the default when it is absent.
 *
 * @param  array<mixed>  $page  The page's fields
 * @param  string  $key  The field
 * @param  string  $default  The default
 * @return string The value
 */
function pageEntryField(array $page, string $key, string $default): string
{
    $value = $page[$key] ?? $default;

    return \is_string($value) ? $value : $default;
}

/**
 * A page's map of strings, or none when it is absent.
 *
 * @param  array<mixed>  $page  The page's fields
 * @param  string  $key  The field
 * @return array<string> The values
 */
function pageEntryFields(array $page, string $key): array
{
    $values = $page[$key] ?? [];

    return \is_array($values) ? \array_filter($values, \is_string(...)) : [];
}

/**
 * Write an export of the given pages and wp_block posts.
 *
 * @param  list<array<mixed>>  $pages  Each page's fields; `type` makes one another post type
 * @return string The export path
 */
function pageEntryExport(array $pages): string
{
    $xml = '';

    foreach ($pages as $index => $page) {
        $postId = pageEntryField($page, 'id', (string) (800 + $index));
        $title = pageEntryField($page, 'title', "Page {$postId}");
        $type = pageEntryField($page, 'type', 'page');
        $content = pageEntryField($page, 'content', '<p>Body.</p>');
        $excerpt = pageEntryField($page, 'excerpt', '');
        $link = pageEntryField($page, 'link', "https://example.test/pages/{$postId}/");
        $parent = pageEntryField($page, 'parent', '0');
        $order = pageEntryField($page, 'order', '2');
        $slugXml = \array_key_exists('slug', $page) && $page['slug'] === null ? '' : '<wp:post_name>'.pageEntryField($page, 'slug', "page-{$postId}").'</wp:post_name>';
        $statusXml = \array_key_exists('status', $page) && $page['status'] === null ? '' : '<wp:status>'.pageEntryField($page, 'status', 'publish').'</wp:status>';
        $meta = '';

        foreach (pageEntryFields($page, 'meta') as $key => $value) {
            $meta .= "<wp:postmeta><wp:meta_key>{$key}</wp:meta_key><wp:meta_value><![CDATA[{$value}]]></wp:meta_value></wp:postmeta>";
        }

        $xml .= <<<XML
<item>
  <title><![CDATA[{$title}]]></title>
  <link>{$link}</link>
  <content:encoded><![CDATA[{$content}]]></content:encoded>
  <excerpt:encoded><![CDATA[{$excerpt}]]></excerpt:encoded>
  <wp:post_id>{$postId}</wp:post_id>
  {$slugXml}
  <wp:post_date>2025-04-01 09:00:00</wp:post_date>
  <wp:post_modified>2025-04-02 10:00:00</wp:post_modified>
  {$statusXml}
  <wp:post_parent>{$parent}</wp:post_parent>
  <wp:menu_order>{$order}</wp:menu_order>
  <wp:post_type>{$type}</wp:post_type>
  {$meta}
</item>

XML;
    }

    $path = TemporaryFileRegistry::create('page-entry');

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Pages</title>
<link>https://example.test</link>
<wp:base_blog_url>https://example.test</wp:base_blog_url>
{$xml}</channel>
</rss>
XML);

    return $path;
}

/**
 * Run the creator over an export.
 *
 * @param  string  $export  The export path
 * @param  PageEntryTestConverter  $converter  The content converter
 * @param  ?PageEntryTestEntryCreator  $entryCreator  The block stand-in, or null for one with no blocks
 * @param  ?array<int|string, mixed>  $minervaKbSettings  The MinervaKB settings
 * @param  ?PageEntryCreator  $creator  The creator, or null for one built on the stand-in
 * @param  array<mixed>  $mapperSets  Fields the metadata mapper writes over the data
 * @param  string  $baseUrl  The export's base URL
 * @return array{output: string, result: array<int|string, mixed>, mapper: PageEntryTestMapper, creator: PageEntryCreator, blocks: PageEntryTestEntryCreator} What the run produced
 */
function pageEntryRun(string $export, PageEntryTestConverter $converter, ?PageEntryTestEntryCreator $entryCreator = null, ?array $minervaKbSettings = null, ?PageEntryCreator $creator = null, array $mapperSets = [], string $baseUrl = 'https://example.test'): array
{
    $parser = new XmlParser;
    $parser->parse($export);
    $output = new BufferedOutput;
    $mapper = new PageEntryTestMapper;
    $mapper->sets = $mapperSets;
    $entryCreator ??= PageEntryTestEntryCreator::make();
    $creator ??= new PageEntryCreator($entryCreator);
    $assetImporter = new AssetImporter;
    $assetImporter->setBaseBlogUrl($baseUrl);

    $result = $creator->createEntries('default', 'pages', $parser, $assetImporter, $output, $mapper, $converter, $minervaKbSettings);

    return ['output' => $output->fetch(), 'result' => $result, 'mapper' => $mapper, 'creator' => $creator, 'blocks' => $entryCreator];
}

/**
 * The saved page with the given slug.
 *
 * @param  string  $slug  The slug
 * @return Entry The entry
 */
function pageEntry(string $slug): Entry
{
    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'pages' && $entry->slug() === $slug) {
            return $entry;
        }
    }

    throw new RuntimeException("No pages entry '{$slug}'.");
}

/**
 * Every saved page's slug.
 *
 * @return list<string> The slugs
 */
function pageEntrySlugs(): array
{
    $slugs = [];

    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'pages') {
            $slug = $entry->slug();
            $slugs[] = \is_string($slug) ? $slug : '';
        }
    }

    return $slugs;
}

/**
 * Everything an entry stores.
 *
 * @param  Entry  $entry  The entry
 * @return array<mixed> The entry's data
 */
function pageEntryData(Entry $entry): array
{
    $data = $entry->data();

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * A content converter result with the given Bard content and page builder sets.
 *
 * @param  mixed  $content  The content
 * @param  array<int|string, mixed>  $pageBuilder  The page builder sets
 * @return array{content: mixed, page_builder: array<int|string, mixed>} The result
 */
function pageEntryBody(mixed $content = null, array $pageBuilder = []): array
{
    return [
        'content' => $content ?? [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]]],
        'page_builder' => $pageBuilder,
    ];
}

beforeEach(function (): void {
    config([
        'statamic.search.indexes' => [],
        'wordpress-import.upload_images' => false,
        'wordpress-import.upload_files' => false,
    ]);
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    foreach (['basic_page', 'landing_page'] as $handle) {
        $blueprint = BlueprintFacade::make($handle);
        $blueprint->setNamespace('collections.pages');
        $blueprint->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
        $blueprint->save();
    }

    $pages = CollectionFacade::make('pages');
    $pages->sites(['default']);
    $pages->save();

    BaseEntryCreator::clearTaxonomyCaches();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    BaseTaxonomyValidator::clearCaches();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();

    foreach (['basic_page', 'landing_page'] as $handle) {
        $blueprint = BlueprintFacade::find("collections.pages.{$handle}");

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }

    TemporaryFileRegistry::sweep();
    Mockery::close();
});

/**
 * Collect every warning logged, as message and context.
 *
 * @return ArrayObject<int, array{0: mixed, 1: mixed}> The warnings, filled as they are logged
 */
function pageEntryWarnings(): ArrayObject
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

    return $warnings;
}

/**
 * The messages of the collected warnings.
 *
 * @param  ArrayObject<int, array{0: mixed, 1: mixed}>  $warnings  The warnings
 * @return list<string> The messages
 */
function pageEntryMessages(ArrayObject $warnings): array
{
    return \array_values(\array_map(static fn (array $warning): string => \is_string($warning[0]) ? $warning[0] : '', $warnings->getArrayCopy()));
}

/**
 * The text at a path through a page's Bard content.
 *
 * @param  mixed  $content  The content
 * @param  list<int|string>  $path  The keys to follow
 * @return mixed The value there, or null when the path does not exist
 */
function pageEntryAt(mixed $content, array $path): mixed
{
    foreach ($path as $key) {
        if (! \is_array($content) || ! \array_key_exists($key, $content)) {
            return null;
        }

        $content = $content[$key];
    }

    return $content;
}

/**
 * A hero's mapped sets, without their random ids.
 *
 * @param  Entry  $entry  The entry
 * @return mixed The sets
 */
function pageEntryHero(Entry $entry): mixed
{
    $hero = $entry->get('header_hero');

    return \is_array($hero) ? \array_map(static fn (mixed $set): mixed => \is_array($set) ? \array_diff_key($set, ['id' => true]) : $set, $hero) : $hero;
}

describe('importing a page', function (): void {
    it('saves a basic page with its defaults, excerpt and content, and reports it as page data', function (): void {
        $converter = PageEntryTestConverter::returning(pageEntryBody());
        $run = pageEntryRun(pageEntryExport([['id' => '801', 'slug' => 'about', 'title' => 'About &amp; Us', 'excerpt' => 'Summary.', 'order' => '4', 'meta' => ['_thumbnail_id' => '5']]]), $converter);
        $entry = pageEntry('about');
        $id = $entry->id();

        expect(pageEntryData($entry))->toBe([
            'blueprint' => 'basic_page',
            'template' => 'pages/default',
            'title' => 'About & Us',
            'title_options' => 'graphic',
            'subsection' => false,
            'hide_breadcrumbs' => false,
            'hide_from_menu' => false,
            'hide_child_menu' => false,
            'header_graphic' => ['image' => null, 'body' => null],
            'site' => 'default',
            'scripts_head' => ['code' => null, 'mode' => 'htmlmixed'],
            'scripts_body' => ['code' => null, 'mode' => 'htmlmixed'],
            'robots_index_meta' => false,
            'robots_follow_meta' => false,
            'robots_odp_meta' => false,
            'robots_imageindex_meta' => false,
            'robots_archive_meta' => false,
            'robots_snippet_meta' => false,
            'excerpt' => 'Summary.',
            'original_wordpress_url' => 'https://example.test/pages/801/',
            'wordpress_post_id' => 801,
            '_thumbnail_id' => '5',
            'content' => pageEntryBody()['content'],
            'mapped' => true,
            'updated_at' => (new DateTime('2025-04-02 10:00:00'))->getTimestamp(),
        ])
            ->and(\is_string($id) ? $id : '')->toStartWith('about-801-')
            ->and($converter->calls)->toBe([[
                'content' => '<p>Body.</p>',
                'site' => 'default',
                'title' => 'About & Us',
                'id' => '801',
                'link' => 'https://example.test/pages/801/',
                'attachments' => 0,
                'terms' => 0,
                'items' => [],
                'collection' => 'pages',
                'slug' => 'about',
                'entry' => $id,
                'blueprint' => 'basic_page',
            ]])
            ->and($run['mapper']->mapped)->toBe(['801'])
            ->and($run['blocks']->asked)->toBe(['Classic:801', 'Row:801', 'Freeform:801'])
            ->and($run['output'])->toContain("Processing page 'about' with content length: 12".PHP_EOL)
            ->and(ProcessingContext::getWordPressPageLink())->toBe('https://example.test/pages/801/')
            ->and(\array_diff_key(\is_array($run['result'][801] ?? null) ? $run['result'][801] : [], ['entry_object' => true]))->toBe([
                'entry' => $id,
                'parent' => '0',
                'menu_order' => 4,
                'title' => 'About & Us',
                'link' => 'https://example.test/pages/801/',
            ])
            ->and((new ReflectionProperty(LinkProcessor::class, 'urlToPostIdMap'))->getValue())->toMatchArray(['https://example.test/pages/801/' => '801']);
    });

    it('names an untitled page, and stores no content or thumbnail it was not given', function (): void {
        pageEntryRun(pageEntryExport([['id' => '804', 'slug' => 'untitled', 'title' => '', 'meta' => ['_thumbnail_id' => '']]]), PageEntryTestConverter::returning(pageEntryBody([])));
        $data = pageEntryData(pageEntry('untitled'));

        expect($data['title'] ?? null)->toBe('Untitled Page')
            ->and($data)->toHaveKey('excerpt')
            ->and($data['excerpt'])->toBeNull()
            ->and($data)->not->toHaveKeys(['content', 'page_builder', '_thumbnail_id']);
    });

    it('generates a slug for a page with no post name, and defaults a missing status, with warnings', function (): void {
        $warnings = pageEntryWarnings();

        pageEntryRun(pageEntryExport([['id' => '805', 'title' => 'No Name', 'slug' => null, 'status' => null]]), PageEntryTestConverter::returning(pageEntryBody()));

        expect(pageEntrySlugs())->toBe(['no-name-805'])
            ->and(\array_slice(pageEntryMessages($warnings), 0, 2))->toBe([
                "PageEntryCreator::createEntries: Missing wp:post_name for page ID '805', using generated slug 'no-name-805' (from entry: https://example.test/pages/805/)",
                "PageEntryCreator::createEntries: Missing wp:status for page ID '805', defaulting to 'publish' (from entry: https://example.test/pages/805/)",
            ])
            ->and($warnings[0][1] ?? null)->toMatchArray(['site' => 'default', 'title' => 'No Name']);
    });

    it('skips a classified, and the MinervaKB home page, registering the latter\'s URL', function (array $settings): void {
        $converter = PageEntryTestConverter::returning(pageEntryBody());
        $run = pageEntryRun(pageEntryExport([
            ['id' => '806', 'meta' => ['classifieds_type' => 'sale']],
            ['id' => '807', 'link' => 'https://example.test/kb/'],
            ['id' => '808'],
        ]), $converter, minervaKbSettings: $settings);

        expect(pageEntrySlugs())->toBe(['page-808'])
            ->and($run['output'])->toContain("Skipping page ID '807'")
            ->and((new ReflectionProperty(LinkProcessor::class, 'urlToPostIdMap'))->getValue())->toMatchArray(['https://example.test/kb/' => '807']);
    })->with([
        'a string id' => [['kb_page' => '807']],
        'an integer id' => [['kb_page' => 807]],
    ]);

    it('imports a page the MinervaKB settings do not name as the home page', function (?array $settings): void {
        pageEntryRun(pageEntryExport([['id' => '807']]), PageEntryTestConverter::returning(pageEntryBody()), minervaKbSettings: $settings);

        expect(pageEntrySlugs())->toBe(['page-807']);
    })->with([
        'no settings' => [null],
        'no kb page' => [['other' => 1]],
        'a blank kb page' => [['kb_page' => '']],
        'a false kb page' => [['kb_page' => false]],
        'a list' => [['kb_page' => ['807']]],
        'another page' => [['kb_page' => '9']],
    ]);

    it('imports a page with a blank post id when the kb page is blank too', function (array $settings): void {
        $run = pageEntryRun(pageEntryExport([['id' => '', 'slug' => 'no-id']]), PageEntryTestConverter::returning(pageEntryBody()), minervaKbSettings: $settings);

        expect(pageEntrySlugs())->toBe(['no-id'])
            ->and($run['output'])->not->toContain('Skipping page ID');
    })->with([
        'a blank kb page' => [['kb_page' => '']],
        'a false kb page' => [['kb_page' => false]],
    ]);

    it('skips a page that already exists when told to, still reporting it as page data', function (): void {
        $export = pageEntryExport([['id' => '809', 'slug' => 'kept']]);
        pageEntryRun($export, PageEntryTestConverter::returning(pageEntryBody()));
        $first = pageEntry('kept')->id();
        LinkProcessor::clearDeferredData();
        BaseEntryCreator::clearTaxonomyCaches();

        $creator = new PageEntryCreator(PageEntryTestEntryCreator::make());
        $creator->setEntryOverwriteBehavior('Skip matching files');
        $converter = PageEntryTestConverter::returning(pageEntryBody());
        $run = pageEntryRun($export, $converter, creator: $creator);

        expect($converter->calls)->toBe([])
            ->and($run['output'])->toContain("Skipped entry 'kept' as it already exists.".PHP_EOL)
            ->and(\is_array($run['result'][809] ?? null) ? ($run['result'][809]['entry'] ?? null) : null)->toBe($first)
            ->and((new ReflectionProperty(LinkProcessor::class, 'urlToPostIdMap'))->getValue())->toMatchArray(['https://example.test/pages/809/' => '809']);
    });
});

describe('choosing the blueprint', function (): void {
    it('makes a page with page builder blocks a landing page', function (string $content): void {
        $converter = PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']]));
        pageEntryRun(pageEntryExport([['slug' => 'landing', 'content' => $content]]), $converter);
        $data = pageEntryData(pageEntry('landing'));

        expect(\array_intersect_key($data, \array_flip(['blueprint', 'template', 'title_options', 'page_builder'])))->toBe([
            'blueprint' => 'landing_page',
            'template' => 'pages/page_landing',
            'title_options' => 'graphic',
            'page_builder' => [['type' => 'set']],
        ])->and($data)->not->toHaveKey('content');
    })->with([
        'an ACF block' => ['<!-- wp:acf/text {"data":{}} /-->'],
        'a spaced ACF block' => ['<!--   wp:acf/text {"data":{}} /-->'],
        'a MinervaKB block' => ['<!-- wp:minervakb/search /-->'],
        'an RSS block' => ['<!-- wp:rss {"feedURL":"x"} /-->'],
        'a LiveWhale widget' => ['<div class="lwcw" data-options="id=12&amp;format=x"></div>'],
        'a blogroll shortcode' => ['[blogroll category="x"]'],
    ]);

    it('keeps a page basic for content that only looks like a page builder block', function (string $content): void {
        pageEntryRun(pageEntryExport([['slug' => 'plain', 'parent' => '9', 'content' => $content]]), PageEntryTestConverter::returning(pageEntryBody()));

        expect(pageEntry('plain')->get('blueprint'))->toBe('basic_page');
    })->with([
        'a LiveWhale class without an id' => ['<div class="lwcw" data-options="format=x"></div>'],
        'a LiveWhale id without the class' => ['<div class="other" data-options="id=12"></div>'],
        'a blogroll word' => ['[blogroll]'],
        'a core block' => ['<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'],
    ]);

    it('makes a page a landing page when a reusable block it embeds has page builder blocks', function (): void {
        pageEntryRun(pageEntryExport([
            ['id' => '810', 'slug' => 'reuse', 'content' => '<!-- wp:block {"ref":811} /--><!-- wp:block {"ref":813} /-->'],
            ['id' => '811', 'type' => 'wp_block', 'content' => '<p>plain</p>'],
            ['id' => '812', 'slug' => 'reuse-other', 'content' => '<!-- wp:block {"ref":813} /-->'],
            ['id' => '813', 'type' => 'wp_block', 'content' => '<!-- wp:acf/text {"data":{}} /-->'],
            ['id' => '814', 'slug' => 'reuse-missing', 'content' => '<!-- wp:block {"ref":999} /-->'],
        ]), PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']])));

        expect(pageEntry('reuse')->get('blueprint'))->toBe('landing_page')
            ->and(pageEntry('reuse-other')->get('blueprint'))->toBe('landing_page')
            ->and(pageEntry('reuse-missing')->get('blueprint'))->toBe('basic_page');
    });
});

/**
 * A page creator whose save returns the given entry, or nothing.
 *
 * @param  ?Entry  $returns  What every save returns
 * @return PageEntryCreator The creator
 */
function pageEntryCreatorSaving(?Entry $returns): PageEntryCreator
{
    $creator = new class(PageEntryTestEntryCreator::make()) extends PageEntryCreator
    {
        /**
         * What every save returns.
         */
        public ?Entry $returns = null;

        /**
         * Return the chosen entry.
         *
         * @param  string  $collectionHandle  The collection handle
         * @param  string  $site  The site handle
         * @param  string  $entrySlug  The entry slug
         * @param  array<int|string, mixed>  $data  The entry data
         * @param  OutputInterface  $output  Console output
         * @param  ?DateTime  $entryDate  The entry date
         * @param  ?bool  $published  Whether the entry is published
         * @param  ?string  $postDateStr  The post date
         * @return ?Entry The chosen entry
         */
        protected function saveEntry(string $collectionHandle, string $site, string $entrySlug, array $data, OutputInterface $output, ?DateTime $entryDate = null, ?bool $published = null, ?string $postDateStr = null): ?Entry
        {
            return $this->returns;
        }
    };
    $creator->returns = $returns;

    return $creator;
}

describe('a hero for the title', function (): void {
    it('uses an ACF hero block for the title, and leaves it out of the content', function (string $option): void {
        $converter = PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']]));
        $hero = '<!-- wp:acf/hero {"name":"acf/hero","data":{"hero_0_hero_heading":"Welcome","hero_0_hero_body":"Hi"}} /-->';

        pageEntryRun(pageEntryExport([['slug' => 'hero', 'content' => $hero.'<!-- wp:acf/text {"data":{}} /-->', 'meta' => ['page_title_options' => $option]]]), $converter);
        $entry = pageEntry('hero');

        expect($entry->get('title_options'))->toBe('hero')
            ->and($entry->get('blueprint'))->toBe('landing_page')
            ->and(pageEntryHero($entry))->toBe([['type' => 'new_set', 'enabled' => true, 'heading' => 'Welcome', 'body' => 'Hi']])
            ->and(\array_column($converter->calls, 'content'))->toBe(['<!-- wp:acf/text {"data":{}} /-->']);
    })->with(['hidden', 'none', 'graphic']);

    it('keeps a page with only a hero block a landing page', function (): void {
        $converter = PageEntryTestConverter::returning(pageEntryBody([], []));

        pageEntryRun(pageEntryExport([['slug' => 'only-hero', 'content' => '<!-- wp:acf/hero {"data":{"hero_0_hero_heading":"Welcome"}} /--><!-- /wp:acf/hero -->', 'meta' => ['page_title_options' => 'hidden']]]), $converter);

        expect(pageEntry('only-hero')->get('blueprint'))->toBe('landing_page')
            ->and(\array_column($converter->calls, 'content'))->toBe(['']);
    });

    it('leaves the title and content alone for a hero it cannot read, or a title option that wants none', function (string $content, string $option): void {
        $converter = PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']]));

        pageEntryRun(pageEntryExport([['slug' => 'no-hero', 'content' => $content, 'meta' => ['page_title_options' => $option]]]), $converter);
        $entry = pageEntry('no-hero');

        expect($entry->get('title_options'))->toBe('graphic')
            ->and($entry->get('header_hero'))->toBeNull()
            ->and(\array_column($converter->calls, 'content'))->toBe([$content]);
    })->with([
        'a normal title option' => ['<!-- wp:acf/hero {"data":{"hero_0_hero_heading":"x"}} /-->', 'normal'],
        'no hero data' => ['<!-- wp:acf/hero {"data":{}} /-->', 'hidden'],
        'hero data that is not a list' => ['<!-- wp:acf/hero {"data":"x"} /-->', 'hidden'],
        'a hero that is not JSON' => ['<!-- wp:acf/hero {bad} /-->', 'hidden'],
    ]);

    it('maps a module-format hero', function (): void {
        pageEntryRun(pageEntryExport([['slug' => 'module-hero', 'meta' => [
            '_wp_page_template' => 'templates/modules.php',
            'uamswp_modules' => 'a:2:{i:0;s:12:"modules_text";i:1;s:12:"modules_hero";}',
            'uamswp_modules_1_hero_heading' => 'Module hero',
            'page_title_options' => 'none',
        ]]]), PageEntryTestConverter::returning(pageEntryBody()));
        $entry = pageEntry('module-hero');

        expect($entry->get('title_options'))->toBe('hero')
            ->and(pageEntryHero($entry))->toBe([['type' => 'new_set', 'enabled' => true, 'heading' => 'Module hero']]);
    });
});

describe('a modules page', function (): void {
    it('builds the page from its modules rather than its content, and records the context', function (): void {
        $converter = PageEntryTestConverter::returning(pageEntryBody());

        pageEntryRun(pageEntryExport([['id' => '820', 'slug' => 'modular', 'meta' => [
            '_wp_page_template' => 'templates/modules.php',
            'uamswp_modules' => 'a:1:{i:0;s:12:"modules_text";}',
        ]]]), $converter);
        $entry = pageEntry('modular');

        expect($converter->calls)->toBe([])
            ->and($entry->get('page_builder'))->toBe([])
            ->and($entry->get('post_name'))->toBe('modular')
            ->and($entry->get('content'))->toBeNull()
            ->and(ProcessingContext::getCollectionHandle())->toBe('pages')
            ->and(ProcessingContext::getStatamicEntryId())->toBe($entry->id());
    });

    it('logs modules it cannot unserialize and builds the page from none', function (): void {
        $warnings = pageEntryWarnings();

        pageEntryRun(pageEntryExport([['id' => '821', 'slug' => 'broken-modules', 'meta' => [
            '_wp_page_template' => 'templates/modules.php',
            'uamswp_modules' => 'not serialized',
        ]]]), PageEntryTestConverter::returning(pageEntryBody()));

        expect(pageEntryMessages($warnings))->toContain('ERROR PageEntryCreator::createEntries: Failed to unserialize uamswp_modules for page')
            ->and(pageEntry('broken-modules')->get('page_builder'))->toBe([]);
    });

    it('treats a page with modules but another template as an ordinary page', function (): void {
        $converter = PageEntryTestConverter::returning(pageEntryBody());

        pageEntryRun(pageEntryExport([['slug' => 'not-modular', 'meta' => ['uamswp_modules' => 'a:0:{}']]]), $converter);

        expect($converter->calls)->toHaveCount(1);
    });
});

describe('a block the page cannot map', function (): void {
    it('replaces the content with a note naming the block, and still builds its ACF blocks', function (string $kind): void {
        $blocks = PageEntryTestEntryCreator::make();
        $blocks->blocks[$kind]['830'] = ['title' => 'Old Page', 'id' => 830, 'link' => 'https://example.test/old/'];
        $converter = PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']]));

        pageEntryRun(pageEntryExport([['id' => '830', 'slug' => 'unmapped', 'content' => '<!-- wp:acf/text {"data":{}} /-->']]), $converter, $blocks);
        $entry = pageEntry('unmapped');
        $link = ['type' => 'link', 'attrs' => ['href' => 'https://example.test/old/', 'rel' => null, 'target' => '_blank', 'title' => null]];

        expect($entry->get('content'))->toBe([
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'Note:'],
                ['type' => 'text', 'text' => " This page contains WordPress {$kind} block content which hasn't been mapped to Statamic Bard."],
            ]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Original details:']]],
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Title: "Old Page"']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'ID: 830']]]]],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Permalink: '],
                    ['type' => 'text', 'marks' => [$link], 'text' => 'https://example.test/old/'],
                ]]]],
            ]],
        ])
            ->and($entry->get('page_builder'))->toBe([['type' => 'set']]);
    })->with(['Classic', 'Row', 'Freeform']);

    it('checks Classic, then Row, then Freeform, and stops at the first it finds', function (): void {
        $blocks = PageEntryTestEntryCreator::make();
        $blocks->blocks['Row']['831'] = ['title' => '', 'id' => '', 'link' => ''];
        $blocks->blocks['Freeform']['831'] = ['title' => 'x', 'id' => 'x', 'link' => 'x'];
        $converter = PageEntryTestConverter::returning(pageEntryBody());

        pageEntryRun(pageEntryExport([['id' => '831', 'slug' => 'row']]), $converter, $blocks);
        $content = pageEntry('row')->get('content');

        expect($blocks->asked)->toBe(['Classic:831', 'Row:831'])
            ->and($converter->calls)->toBe([])
            ->and(pageEntryAt($content, [0, 'content', 1, 'text']))->toBeString()->toContain('WordPress Row block')
            ->and(pageEntry('row')->get('page_builder'))->toBeNull();
    });

    it('ignores a block answer that holds nothing, and blank details', function (): void {
        $blocks = PageEntryTestEntryCreator::make();
        $blocks->blocks['Classic']['832'] = [];
        $blocks->blocks['Row']['833'] = ['title' => ['x'], 'link' => 'l'];

        pageEntryRun(pageEntryExport([['id' => '832', 'slug' => 'empty-answer'], ['id' => '833', 'slug' => 'odd-details']]), PageEntryTestConverter::returning(pageEntryBody()), $blocks);
        $content = pageEntry('odd-details')->get('content');

        expect(pageEntry('empty-answer')->get('content'))->toBe(pageEntryBody()['content'])
            ->and(pageEntryAt($content, [2, 'content', 0, 'content', 0, 'content', 0, 'text']))->toBe('Title: ""')
            ->and(pageEntryAt($content, [2, 'content', 1, 'content', 0, 'content', 0, 'text']))->toBe('ID: ');
    });
});

describe('the title options', function (): void {
    it('gives a basic page the graphic title when it is the homepage, top level, a subsection or a subsection\'s child', function (array $page, string $expected): void {
        pageEntryRun(pageEntryExport([
            ['id' => '840', 'slug' => 'section-parent', 'parent' => '7', 'meta' => ['page_subsection' => '1']],
            ['id' => '841', 'slug' => 'plain-parent', 'parent' => '7'],
            ['slug' => 'subject', ...$page],
        ]), PageEntryTestConverter::returning(pageEntryBody()));

        expect(pageEntry('subject')->get('title_options'))->toBe($expected);
    })->with([
        'the homepage' => [['parent' => '7', 'link' => 'https://example.test/'], 'graphic'],
        'the homepage without its slash' => [['parent' => '7', 'link' => 'https://example.test'], 'graphic'],
        'a top-level page' => [['parent' => '0'], 'graphic'],
        'a subsection page\'s child' => [['parent' => '840'], 'graphic'],
        'an ordinary page\'s child' => [['parent' => '841'], 'normal'],
        'a page whose parent is not in the export' => [['parent' => '999'], 'normal'],
    ]);

    it('gives a landing page whose title option is none the graphic title', function (): void {
        pageEntryRun(pageEntryExport([['slug' => 'landing-none', 'parent' => '7', 'content' => '<!-- wp:acf/text {"data":{}} /-->', 'meta' => ['page_title_options' => 'none']]]), PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']])));

        expect(pageEntry('landing-none')->get('title_options'))->toBe('graphic');
    });
});

describe('the field checks', function (): void {
    it('warns about a basic page with no content', function (): void {
        $warnings = pageEntryWarnings();

        pageEntryRun(pageEntryExport([['id' => '850', 'slug' => 'empty', 'parent' => '7']]), PageEntryTestConverter::returning(pageEntryBody([])));

        expect(pageEntryMessages($warnings))->toContain('Page entry created with basic_page blueprint but no content field (from entry: https://example.test/pages/850/)')
            ->and($warnings[0][1] ?? null)->toMatchArray(['entry_slug' => 'empty', 'blueprint' => 'basic_page', 'has_content' => false, 'has_page_builder' => false, 'source_url' => 'https://example.test/pages/850/']);
    });

    it('warns about a landing page with no page builder, or with content', function (): void {
        $warnings = pageEntryWarnings();

        pageEntryRun(pageEntryExport([['id' => '852', 'slug' => 'landing-empty', 'content' => '<!-- wp:acf/text {"data":{}} /-->']]), PageEntryTestConverter::returning(pageEntryBody(null, [])));

        expect(pageEntryMessages($warnings))->toBe([
            'Page entry created with landing_page blueprint but no page_builder field (from entry: https://example.test/pages/852/)',
            'Page entry created with landing_page blueprint but has content field (from entry: https://example.test/pages/852/)',
        ])
            ->and($warnings[1][1] ?? null)->toMatchArray(['entry_slug' => 'landing-empty', 'blueprint' => 'landing_page', 'has_content' => true, 'has_page_builder' => false]);
    });

    it('raises no field warning for pages whose fields match their blueprints', function (): void {
        $warnings = pageEntryWarnings();

        pageEntryRun(pageEntryExport([['id' => '853', 'slug' => 'right']]), PageEntryTestConverter::returning(pageEntryBody()));
        pageEntryRun(pageEntryExport([['id' => '854', 'slug' => 'right-landing', 'content' => '<!-- wp:acf/text {"data":{}} /-->']]), PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']])));

        expect(pageEntryMessages($warnings))->toBe([]);
    });
});

describe('a save that does not go to plan', function (): void {
    it('falls back to the generated id, with warnings, when the save returns nothing and no entry is found', function (): void {
        $warnings = pageEntryWarnings();

        $run = pageEntryRun(pageEntryExport([['id' => '860', 'slug' => 'unsaved']]), PageEntryTestConverter::returning(pageEntryBody()), creator: pageEntryCreatorSaving(null));
        $page = \is_array($run['result'][860] ?? null) ? $run['result'][860] : [];

        expect(pageEntryMessages($warnings))->toBe([
            'PageEntryCreator::createEntries: saveEntry returned null for page, attempting to find existing entry (from entry: https://example.test/pages/860/)',
            'PageEntryCreator::createEntries: Could not find existing entry, adding to pageData with generated ID (from entry: https://example.test/pages/860/)',
        ])
            ->and($warnings[0][1] ?? null)->toMatchArray(['postId' => '860', 'slug' => 'unsaved', 'site' => 'default', 'collection' => 'pages'])
            ->and($warnings[1][1] ?? null)->toMatchArray(['postId' => '860', 'slug' => 'unsaved'])
            ->and($page['entry'] ?? '')->toBeString()->toStartWith('unsaved-860-')
            ->and($page)->not->toHaveKey('entry_object');
    });

    it('uses an existing entry when the save returns nothing', function (): void {
        pageEntryRun(pageEntryExport([['id' => '861', 'slug' => 'present']]), PageEntryTestConverter::returning(pageEntryBody()));
        $existing = pageEntry('present');
        LinkProcessor::clearDeferredData();
        BaseEntryCreator::clearTaxonomyCaches();
        pageEntryWarnings();

        $run = pageEntryRun(pageEntryExport([['id' => '861', 'slug' => 'present']]), PageEntryTestConverter::returning(pageEntryBody()), creator: pageEntryCreatorSaving(null));

        expect(\is_array($run['result'][861] ?? null) ? ($run['result'][861]['entry'] ?? null) : null)->toBe($existing->id());
    });

    it('warns when the saved entry carries another id, and reports the one it carries', function (): void {
        $warnings = pageEntryWarnings();
        $other = new Entry;
        $other->id('other-id');

        $run = pageEntryRun(pageEntryExport([['id' => '862', 'slug' => 'mismatched']]), PageEntryTestConverter::returning(pageEntryBody()), creator: pageEntryCreatorSaving($other));

        expect(pageEntryMessages($warnings))->toContain('PageEntryCreator::createEntries: Entry ID mismatch detected (from entry: https://example.test/pages/862/)')
            ->and(\is_array($run['result'][862] ?? null) ? ($run['result'][862]['entry'] ?? null) : null)->toBe('other-id');
    });
});

describe('people and the knowledge base', function (): void {
    it('notes a show-people shortcode, and validates people groups only then', function (): void {
        $run = pageEntryRun(pageEntryExport([['slug' => 'staff', 'content' => '<p>[show-people group="x"]</p>']]), PageEntryTestConverter::returning(pageEntryBody()));
        $quiet = pageEntryRun(pageEntryExport([['id' => '871', 'slug' => 'nobody', 'content' => '<p>[show-people</p>']]), PageEntryTestConverter::returning(pageEntryBody()));
        $output = new BufferedOutput;
        $run['creator']->validateTaxonomies('pages', 'default', $output);
        $silent = new BufferedOutput;
        $quiet['creator']->validateTaxonomies('pages', 'default', $silent);

        expect($run['creator']->hasShowPeople())->toBeTrue()
            ->and($quiet['creator']->hasShowPeople())->toBeFalse()
            ->and($output->fetch())->not->toBe('')
            ->and($silent->fetch())->toBe('')
            ->and($run['creator']->getPeopleGroups())->toBe(PeopleShortcodeProcessor::getTermsUsed())
            ->and($run['creator']->getPostCategories())->toBe([])
            ->and($run['creator']->getPostTags())->toBe([]);
    });
});

/**
 * A class whose unserializing would be recorded, to show the import never builds one.
 */
final class PageEntryWakeupProbe
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
 * Make a collection for a test.
 *
 * @param  string  $handle  The collection handle
 * @param  string  $blueprint  The blueprint handle
 * @param  bool  $structured  Whether the collection has a structure
 */
function pageEntryCollection(string $handle, string $blueprint, bool $structured = false): void
{
    $made = BlueprintFacade::make($blueprint);
    $made->setNamespace("collections.{$handle}");
    $made->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
    $made->save();

    $collection = CollectionFacade::make($handle);
    $collection->sites(['default']);

    if ($structured) {
        $collection->structureContents(['root' => false]);
    }

    $collection->save();
}

describe('what else a run reads and writes', function (): void {
    it('runs the post and logo list term passes before any page', function (): void {
        pageEntryCollection('post_categories', 'post_category', true);
        pageEntryCollection('logos_item_categories', 'logos_item_category', true);
        $export = TemporaryFileRegistry::create('page-terms');
        \file_put_contents($export, \str_replace(
            '<wp:base_blog_url>https://example.test</wp:base_blog_url>',
            '<wp:base_blog_url>https://example.test</wp:base_blog_url>'
                .'<wp:category><wp:term_id>11</wp:term_id><wp:category_nicename>news</wp:category_nicename><wp:category_parent></wp:category_parent><wp:cat_name><![CDATA[News]]></wp:cat_name></wp:category>'
                .'<wp:term><wp:term_id>71</wp:term_id><wp:term_taxonomy>logo-list-category</wp:term_taxonomy><wp:term_slug>partners</wp:term_slug><wp:term_name><![CDATA[Partners]]></wp:term_name></wp:term>',
            (string) \file_get_contents(pageEntryExport([['slug' => 'termed']]))
        ));

        pageEntryRun($export, PageEntryTestConverter::returning(pageEntryBody()));

        expect(TermDestinationRegistry::referenceFor('category', '11'))->toBeString()
            ->and(TermDestinationRegistry::referenceFor('logo-list-category', '71'))->toBeString();

        foreach (['collections.post_categories.post_category', 'collections.logos_item_categories.logos_item_category'] as $handle) {
            BlueprintFacade::find($handle)?->delete();
        }
    });

    it('hands the converter the export\'s attachments, and decodes a double-encoded title', function (): void {
        $export = TemporaryFileRegistry::create('page-attachment');
        \file_put_contents($export, \str_replace(
            '<wp:base_blog_url>https://example.test</wp:base_blog_url>',
            '<wp:base_blog_url>https://example.test</wp:base_blog_url><item><title>img</title><link>https://example.test/img/</link><wp:post_id>900</wp:post_id><wp:post_type>attachment</wp:post_type><wp:attachment_url>https://example.test/img.png</wp:attachment_url></item>',
            (string) \file_get_contents(pageEntryExport([['slug' => 'pictured', 'title' => 'Say &AMP;quot;hi&AMP;quot;']]))
        ));
        $converter = PageEntryTestConverter::returning(pageEntryBody());

        pageEntryRun($export, $converter);

        expect($converter->calls[0]['attachments'] ?? null)->toBe(1)
            ->and(pageEntry('pictured')->get('title'))->toBe('Say "hi"');
    });

    it('records the missing status warning\'s context, and reads a missing menu order as zero', function (): void {
        $warnings = pageEntryWarnings();
        $export = TemporaryFileRegistry::create('page-no-order');
        \file_put_contents($export, \str_replace('<wp:menu_order>2</wp:menu_order>', '', (string) \file_get_contents(pageEntryExport([['id' => '880', 'slug' => 'unordered', 'title' => 'Unordered', 'status' => null]]))));

        $run = pageEntryRun($export, PageEntryTestConverter::returning(pageEntryBody()));

        expect($warnings[0][1] ?? null)->toMatchArray(['site' => 'default', 'title' => 'Unordered'])
            ->and(\is_array($run['result'][880] ?? null) ? ($run['result'][880]['menu_order'] ?? null) : null)->toBe(0);
    });

    it('falls back to the SEO description, then the meta description, for the excerpt', function (array $meta, ?string $expected): void {
        pageEntryRun(pageEntryExport([['slug' => 'described', 'meta' => $meta]]), PageEntryTestConverter::returning(pageEntryBody()));

        expect(pageEntry('described')->get('excerpt'))->toBe($expected);
    })->with([
        'the SEO description' => [['_seopress_titles_desc' => 'Seo', 'seo_meta_description' => 'Meta'], 'Seo'],
        'the meta description' => [['seo_meta_description' => 'Meta'], 'Meta'],
    ]);

    it('imports the next page after one it skips as existing, and a new one under skip', function (): void {
        pageEntryRun(pageEntryExport([['id' => '881', 'slug' => 'old']]), PageEntryTestConverter::returning(pageEntryBody()));
        LinkProcessor::clearDeferredData();
        BaseEntryCreator::clearTaxonomyCaches();
        $creator = new PageEntryCreator(PageEntryTestEntryCreator::make());
        $creator->setEntryOverwriteBehavior('Skip matching files');

        pageEntryRun(pageEntryExport([['id' => '881', 'slug' => 'old'], ['id' => '882', 'slug' => 'new']]), PageEntryTestConverter::returning(pageEntryBody()), creator: $creator);

        expect(\array_values(\array_unique(pageEntrySlugs())))->toBe(['old', 'new']);
    });

    it('sets the knowledge base for the site in the processing context', function (): void {
        pageEntryCollection('knowledge_bases', 'knowledge_base');
        foreach ([['kb', 'knowledge_base', 'kb-entry'], ['other', 'knowledge_base', 'other-entry']] as [$slug, $blueprint, $id]) {
            $kb = new Entry;
            $kb->collection('knowledge_bases');
            $kb->blueprint($blueprint);
            $kb->locale('default');
            $kb->slug($slug);
            $kb->id($id);
            $kb->save();
        }

        pageEntryRun(pageEntryExport([['slug' => 'with-kb']]), PageEntryTestConverter::returning(pageEntryBody()));

        expect(ProcessingContext::getKnowledgeBaseId())->toBe('kb-entry');

        BlueprintFacade::find('collections.knowledge_bases.knowledge_base')?->delete();
    });

    it('validates people groups found in show-people shortcodes as shortcodes', function (): void {
        $run = pageEntryRun(pageEntryExport([['slug' => 'staff', 'content' => '[show-people]']]), PageEntryTestConverter::returning(pageEntryBody()));
        (new ReflectionProperty(PeopleShortcodeProcessor::class, 'termsUsed'))->setValue(null, ['faculty' => [['page_slug' => 'staff', 'original' => 'faculty', 'name' => 'Faculty', 'site_specific' => 'default_faculty']]]);
        $output = new BufferedOutput;

        $run['creator']->validateTaxonomies('pages', 'default', $output);

        expect($run['creator']->getPeopleGroups())->toHaveKey('faculty')
            ->and($output->fetch())->toContain('shortcodes');

        PeopleShortcodeProcessor::resetTermsUsed();
    });
});

describe('modules and their hero', function (): void {
    it('reports a hero module it does not use for the title, and not one it does', function (): void {
        $warnings = pageEntryWarnings();
        $modules = 'a:2:{i:0;s:11:"modules_cta";i:1;s:12:"modules_hero";}';

        pageEntryRun(pageEntryExport([
            ['id' => '883', 'slug' => 'm-plain', 'parent' => '7', 'meta' => ['_wp_page_template' => 'templates/modules.php', 'uamswp_modules' => $modules, 'uamswp_modules_1_hero_heading' => 'H']],
            ['id' => '884', 'slug' => 'm-hero', 'parent' => '7', 'meta' => ['_wp_page_template' => 'templates/modules.php', 'uamswp_modules' => $modules, 'uamswp_modules_1_hero_heading' => 'H', 'page_title_options' => 'hidden']],
        ]), PageEntryTestConverter::returning(pageEntryBody()));
        $notImplemented = \array_values(\array_filter(pageEntryMessages($warnings), static fn (string $message): bool => \str_contains($message, "'modules_hero' is not yet implemented")));

        expect($notImplemented)->toHaveCount(1)
            ->and($notImplemented[0] ?? '')->toContain('/pages/883/')
            ->and(pageEntry('m-hero')->get('title_options'))->toBe('hero')
            ->and(pageEntry('m-plain')->get('title_options'))->toBe('normal');
    });

    it('uses no hero module for the title when it cannot read one', function (array $meta): void {
        pageEntryRun(pageEntryExport([['slug' => 'm-none', 'parent' => '7', 'meta' => ['_wp_page_template' => 'templates/modules.php', 'page_title_options' => 'none', ...$meta]]]), PageEntryTestConverter::returning(pageEntryBody()));

        expect(pageEntry('m-none')->get('title_options'))->toBe('normal');
    })->with([
        'modules that are not serialized' => [['uamswp_modules' => 'broken']],
        'no hero module' => [['uamswp_modules' => 'a:1:{i:0;s:11:"modules_cta";}', 'uamswp_modules_0_cta_heading' => 'Call']],
        'a hero module with no fields' => [['uamswp_modules' => 'a:1:{i:0;s:12:"modules_hero";}']],
    ]);

    it('ignores a hero module on a page without the modules template', function (): void {
        pageEntryRun(pageEntryExport([['slug' => 'not-modular-hero', 'parent' => '7', 'meta' => [
            'uamswp_modules' => 'a:1:{i:0;s:12:"modules_hero";}',
            'uamswp_modules_0_hero_heading' => 'H',
            'page_title_options' => 'none',
        ]]]), PageEntryTestConverter::returning(pageEntryBody()));

        expect(pageEntry('not-modular-hero')->get('title_options'))->toBe('normal');
    });

    it('records the entry data and the export path for the module parsers', function (): void {
        $export = pageEntryExport([['id' => '885', 'slug' => 'm-context', 'meta' => ['_wp_page_template' => 'templates/modules.php', 'uamswp_modules' => 'a:0:{}']]]);

        pageEntryRun($export, PageEntryTestConverter::returning(pageEntryBody()));
        $entryData = ProcessingContext::getEntryData();

        expect($entryData['post_name'] ?? null)->toBe('m-context')
            ->and(ProcessingContext::getXmlPath())->toBe($export);
    });

    it('records the title as the post name when the title generates no slug', function (): void {
        pageEntryRun(pageEntryExport([['id' => '887', 'slug' => '', 'title' => '???', 'meta' => ['_wp_page_template' => 'templates/modules.php', 'uamswp_modules' => 'a:0:{}']]]), PageEntryTestConverter::returning(pageEntryBody()));
        $entryData = ProcessingContext::getEntryData();

        expect($entryData['post_name'] ?? null)->toBe('???');
    });

    it('reads a hero module listed under a string key at the first module index', function (): void {
        pageEntryRun(pageEntryExport([['id' => '888', 'slug' => 'm-keyed', 'parent' => '7', 'meta' => [
            '_wp_page_template' => 'templates/modules.php',
            'uamswp_modules' => 'a:1:{s:1:"x";s:12:"modules_hero";}',
            'uamswp_modules_0_hero_heading' => 'H',
            'page_title_options' => 'hidden',
        ]]]), PageEntryTestConverter::returning(pageEntryBody()));

        expect(pageEntry('m-keyed')->get('title_options'))->toBe('hero');
    });

    it('logs the page and its modules when they do not unserialize', function (): void {
        $warnings = pageEntryWarnings();

        pageEntryRun(pageEntryExport([['id' => '886', 'slug' => 'm-broken', 'meta' => ['_wp_page_template' => 'templates/modules.php', 'uamswp_modules' => 'broken']]]), PageEntryTestConverter::returning(pageEntryBody()));
        $error = \array_values(\array_filter($warnings->getArrayCopy(), static fn (array $warning): bool => \is_string($warning[0]) && \str_starts_with($warning[0], 'ERROR')));

        expect($error[0][1] ?? null)->toBe(['postId' => '886', 'slug' => 'm-broken', 'uamswp_modules' => 'broken']);
    });

    it('builds no object from serialized modules', function (string $option): void {
        PageEntryWakeupProbe::$woken = false;

        pageEntryRun(pageEntryExport([['slug' => 'm-objects', 'meta' => [
            '_wp_page_template' => 'templates/modules.php',
            'uamswp_modules' => 'a:1:{i:0;O:20:"PageEntryWakeupProbe":0:{}}',
            'page_title_options' => $option,
        ]]]), PageEntryTestConverter::returning(pageEntryBody()));

        expect((new ReflectionProperty(PageEntryWakeupProbe::class, 'woken'))->getValue())->toBeFalse();
    })->with(['normal', 'hidden']);

    it('warns about a basic page with a page builder', function (): void {
        $warnings = pageEntryWarnings();

        pageEntryRun(pageEntryExport([['id' => '887', 'slug' => 'm-built', 'parent' => '7', 'meta' => [
            '_wp_page_template' => 'templates/modules.php',
            'uamswp_modules' => 'a:1:{i:0;s:11:"modules_cta";}',
            'uamswp_modules_0_cta_heading' => 'Call',
        ]]]), PageEntryTestConverter::returning(pageEntryBody()));
        $built = \array_values(\array_filter($warnings->getArrayCopy(), static fn (array $warning): bool => \is_string($warning[0]) && \str_contains($warning[0], 'but has page_builder field')));

        expect($built)->toHaveCount(1)
            ->and($built[0][0] ?? null)->toBe('Page entry created with basic_page blueprint but has page_builder field (from entry: https://example.test/pages/887/)')
            ->and($built[0][1] ?? null)->toMatchArray(['entry_slug' => 'm-built', 'blueprint' => 'basic_page', 'has_content' => false, 'has_page_builder' => true, 'source_url' => 'https://example.test/pages/887/']);
    });
});

describe('heroes and reusable blocks, more closely', function (): void {
    it('reads a hero block keyed by numbers, and one at a later slide index', function (): void {
        pageEntryRun(pageEntryExport([
            ['slug' => 'hero-numeric', 'content' => '<!-- wp:acf/hero {"data":{"0":"x","hero_0_hero_heading":"Numbered"}} /-->', 'meta' => ['page_title_options' => 'hidden']],
            ['id' => '888', 'slug' => 'hero-later', 'content' => '<!-- wp:acf/hero {"data":{"field":"x","hero_1_hero_heading":"Later"}} /-->', 'meta' => ['page_title_options' => 'hidden']],
        ]), PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']])));

        expect(pageEntryHero(pageEntry('hero-numeric')))->toBe([['type' => 'new_set', 'enabled' => true, 'heading' => 'Numbered']])
            ->and(pageEntryHero(pageEntry('hero-later')))->toBe([1 => ['type' => 'new_set', 'enabled' => true, 'heading' => 'Later']]);
    });

    it('goes on to the next reusable block after one that is missing', function (): void {
        pageEntryRun(pageEntryExport([
            ['id' => '889', 'slug' => 'reuse-after-missing', 'content' => '<!-- wp:block {"ref":999} /--><!-- wp:block {"ref":890} /-->'],
            ['id' => '890', 'type' => 'wp_block', 'content' => '<!-- wp:acf/text {"data":{}} /-->'],
        ]), PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']])));

        expect(pageEntry('reuse-after-missing')->get('blueprint'))->toBe('landing_page');
    });
});

describe('the title options a metadata mapper leaves', function (): void {
    it('gives a landing page whose title option is none the graphic title over the mapper\'s value, and leaves other values', function (array $meta, string $expected): void {
        pageEntryRun(pageEntryExport([['slug' => 'mapped-landing', 'parent' => '7', 'content' => '<!-- wp:acf/text {"data":{}} /-->', 'meta' => $meta]]), PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']])), mapperSets: ['title_options' => 'hidden']);

        expect(pageEntry('mapped-landing')->get('title_options'))->toBe($expected);
    })->with([
        'none, after another key' => [['other' => 'none', 'page_title_options' => 'none'], 'graphic'],
        'another option' => [['page_title_options' => 'normal'], 'hidden'],
        'none under another key' => [['page_title_option' => 'none'], 'hidden'],
    ]);

    it('leaves a basic page whose title option is none to the other rules', function (): void {
        pageEntryRun(pageEntryExport([['slug' => 'basic-none', 'parent' => '7', 'meta' => ['page_title_options' => 'none']]]), PageEntryTestConverter::returning(pageEntryBody()), mapperSets: ['title_options' => 'hidden']);

        expect(pageEntry('basic-none')->get('title_options'))->toBe('hidden');
    });

    it('leaves a landing page\'s or a non-normal title alone, and honors a subsection the mapper set', function (array $page, array $sets, string $expected): void {
        pageEntryRun(pageEntryExport([['slug' => 'mapped', ...$page]]), PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']])), mapperSets: $sets);

        expect(pageEntry('mapped')->get('title_options'))->toBe($expected);
    })->with([
        'a top-level landing page the mapper made normal' => [['content' => '<!-- wp:acf/text {"data":{}} /-->'], ['title_options' => 'normal'], 'normal'],
        'a top-level basic page the mapper made hidden' => [[], ['title_options' => 'hidden'], 'hidden'],
        'a child the mapper made a subsection' => [['parent' => '7'], ['subsection' => true], 'graphic'],
        'a child whose subsection is a string' => [['parent' => '7'], ['subsection' => '1'], 'normal'],
    ]);

    it('matches the homepage against a base URL with a trailing slash', function (): void {
        pageEntryRun(pageEntryExport([['slug' => 'home', 'parent' => '7', 'link' => 'https://example.test']]), PageEntryTestConverter::returning(pageEntryBody()), baseUrl: 'https://example.test/');

        expect(pageEntry('home')->get('title_options'))->toBe('graphic');
    });
});

describe('the warnings a save raises, in full', function (): void {
    it('records the ids and slug of an id mismatch, and of a save that returns nothing', function (): void {
        $warnings = pageEntryWarnings();
        $other = new Entry;
        $other->id('other-id');

        pageEntryRun(pageEntryExport([['id' => '891', 'slug' => 'mismatch-context']]), PageEntryTestConverter::returning(pageEntryBody()), creator: pageEntryCreatorSaving($other));
        pageEntryRun(pageEntryExport([['id' => '892', 'slug' => 'null-context']]), PageEntryTestConverter::returning(pageEntryBody()), creator: pageEntryCreatorSaving(null));
        $byMessage = [];

        foreach ($warnings->getArrayCopy() as [$message, $context]) {
            if (\is_string($message) && \is_array($context)) {
                $byMessage[\explode(' (from entry', $message)[0]] = $context;
            }
        }

        $mismatch = $byMessage['PageEntryCreator::createEntries: Entry ID mismatch detected'] ?? [];
        $null = $byMessage['PageEntryCreator::createEntries: saveEntry returned null for page, attempting to find existing entry'] ?? [];
        $fallback = $byMessage['PageEntryCreator::createEntries: Could not find existing entry, adding to pageData with generated ID'] ?? [];

        expect($mismatch)->toHaveKeys(['postId', 'generated_entry_id', 'actual_entry_id', 'slug'])
            ->and($mismatch['postId'] ?? null)->toBe('891')
            ->and($mismatch['actual_entry_id'] ?? null)->toBe('other-id')
            ->and($mismatch['slug'] ?? null)->toBe('mismatch-context')
            ->and($mismatch['generated_entry_id'] ?? '')->toBeString()->toStartWith('mismatch-context-891-')
            ->and($null['entryId'] ?? '')->toBeString()->toStartWith('null-context-892-')
            ->and($fallback['entryId'] ?? '')->toBeString()->toStartWith('null-context-892-');
    });

    it('records the full context of a basic page with no content', function (): void {
        $warnings = pageEntryWarnings();

        pageEntryRun(pageEntryExport([['id' => '893', 'slug' => 'bare-context', 'parent' => '7']]), PageEntryTestConverter::returning(pageEntryBody([])));

        $context = \is_array($warnings[0][1] ?? null) ? $warnings[0][1] : [];

        expect(\array_diff_key($context, ['entry_url' => true]))->toBe([
            'entry_slug' => 'bare-context',
            'blueprint' => 'basic_page',
            'has_content' => false,
            'has_page_builder' => false,
            'source_url' => 'https://example.test/pages/893/',
        ]);
    });
});

describe('the creator\'s state', function (): void {
    it('starts with no show-people shortcode noted', function (): void {
        expect((new PageEntryCreator(PageEntryTestEntryCreator::make()))->hasShowPeople())->toBeFalse();
    });
});

describe('the last details', function (): void {
    it('filters out a hero module only when that module is the hero used for the title', function (): void {
        $warnings = pageEntryWarnings();

        pageEntryRun(pageEntryExport([['id' => '894', 'slug' => 'block-hero-modules', 'parent' => '7', 'content' => '<!-- wp:acf/hero {"data":{"hero_0_hero_heading":"Block"}} /-->', 'meta' => [
            '_wp_page_template' => 'templates/modules.php',
            'uamswp_modules' => 'a:1:{i:0;s:12:"modules_hero";}',
            'page_title_options' => 'hidden',
        ]]]), PageEntryTestConverter::returning(pageEntryBody()));
        $notImplemented = \array_values(\array_filter(pageEntryMessages($warnings), static fn (string $message): bool => \str_contains($message, "'modules_hero' is not yet implemented")));

        expect(pageEntry('block-hero-modules')->get('title_options'))->toBe('hero')
            ->and($notImplemented)->toHaveCount(1);
    });

    it('records the full context of a landing page with no page builder', function (): void {
        $warnings = pageEntryWarnings();

        pageEntryRun(pageEntryExport([['id' => '895', 'slug' => 'landing-context', 'content' => '<!-- wp:acf/text {"data":{}} /-->']]), PageEntryTestConverter::returning(pageEntryBody(null, [])));
        $contexts = \array_map(static fn (array $warning): mixed => \is_array($warning[1]) ? \array_diff_key($warning[1], ['entry_url' => true]) : null, $warnings->getArrayCopy());
        $expected = [
            'entry_slug' => 'landing-context',
            'blueprint' => 'landing_page',
            'has_content' => true,
            'has_page_builder' => false,
            'source_url' => 'https://example.test/pages/895/',
        ];

        expect($contexts)->toBe([$expected, $expected]);
    });

    it('takes the first slide index a hero block names', function (): void {
        pageEntryRun(pageEntryExport([['slug' => 'hero-first', 'content' => '<!-- wp:acf/hero {"data":{"hero_0_hero_heading":"First","hero_2_hero_body":"Body"}} /-->', 'meta' => ['page_title_options' => 'hidden']]]), PageEntryTestConverter::returning(pageEntryBody([], [['type' => 'set']])));

        expect(\array_keys(\is_array($hero = pageEntryHero(pageEntry('hero-first'))) ? $hero : []))->toBe([0]);
    });

    it('takes no knowledge base from an entry that misses one of its collection, blueprint, slug and site', function (string $collection, string $blueprint, string $site, string $slug): void {
        Site::setSites([
            'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
            'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/'],
        ]);
        pageEntryCollection('knowledge_bases', 'knowledge_base');
        pageEntryCollection('decoy_bases', 'knowledge_base');
        $other = BlueprintFacade::make('other_base');
        $other->setNamespace('collections.knowledge_bases');
        $other->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
        $other->save();
        $bases = CollectionFacade::find('knowledge_bases');
        $bases?->sites(['default', 'other']);
        $bases?->save();
        $kb = new Entry;
        $kb->collection($collection);
        $kb->blueprint($blueprint);
        $kb->locale($site);
        $kb->slug($slug);
        $kb->id('decoy');
        $kb->save();
        ProcessingContext::clearKnowledgeBaseId();

        pageEntryRun(pageEntryExport([['slug' => 'without-the-kb']]), PageEntryTestConverter::returning(pageEntryBody()));

        expect(ProcessingContext::getKnowledgeBaseId())->toBeNull();

        foreach (['collections.knowledge_bases.knowledge_base', 'collections.knowledge_bases.other_base', 'collections.decoy_bases.knowledge_base'] as $handle) {
            BlueprintFacade::find($handle)?->delete();
        }
    })->with([
        'another collection' => ['decoy_bases', 'knowledge_base', 'default', 'kb'],
        'another blueprint' => ['knowledge_bases', 'other_base', 'default', 'kb'],
        'another site' => ['knowledge_bases', 'knowledge_base', 'other', 'kb'],
        'another slug' => ['knowledge_bases', 'knowledge_base', 'default', 'not-kb'],
    ]);
});
