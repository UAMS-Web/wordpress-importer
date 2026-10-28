<?php

declare(strict_types=1);

/**
 * Tests `ReusableBlockEntryCreator`: how it reads each `wp_block` item, what it
 * hands the content converter and the metadata mapper, what it saves, the
 * entries it skips, and the post-id-to-entry-id map it keeps.
 *
 * The content converter and the metadata mapper are recording subclasses, so the
 * test controls what a block's body converts to. Entries are saved into a
 * `reusable_content` collection this test makes.
 *
 * @command  php vendor/bin/pest tests/Integration/ReusableBlockEntryCreatorTest.php
 */

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
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\ReusableBlockEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpBlockRegistry;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * A content converter that records each call and returns the body it is given.
 */
final class ReusableBlockTestConverter extends ContentProcessor
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
    public array $result = ['content' => [], 'page_builder' => []];

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
            'data' => $data,
            'collection' => $collectionHandle,
            'slug' => $pageSlug,
            'entry' => $statamicEntryId,
        ];

        return $this->result;
    }
}

/**
 * A metadata mapper that records each item and marks the data it maps.
 */
final class ReusableBlockTestMapper extends MetadataMapper
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
 * The warnings logged by a test.
 */
final class ReusableBlockTestWarnings
{
    /**
     * Each warning's message and context.
     *
     * @var list<array{mixed, mixed}>
     */
    public array $logged = [];
}

/**
 * Write a `wp_block` export.
 *
 * @param  list<array<string, string>>  $items  Each item's fields: title, slug, status, content, thumbnail, modified
 * @return string The export path
 */
function reusableBlockExport(array $items): string
{
    $xml = '';

    foreach ($items as $index => $item) {
        $postId = $item['id'] ?? (string) (700 + $index);
        $slug = $item['slug'] ?? "block-{$postId}";
        $title = $item['title'] ?? "Block {$postId}";
        $status = $item['status'] ?? 'publish';
        $content = $item['content'] ?? '<p>Body.</p>';
        $thumbnail = isset($item['thumbnail'])
            ? "<wp:postmeta><wp:meta_key>_thumbnail_id</wp:meta_key><wp:meta_value><![CDATA[{$item['thumbnail']}]]></wp:meta_value></wp:postmeta>"
            : '';
        $modified = $item['modified'] ?? '2025-03-02 10:00:00';

        $xml .= <<<XML
<item>
  <title><![CDATA[{$title}]]></title>
  <link>https://example.test/wp_block/{$postId}/</link>
  <content:encoded><![CDATA[{$content}]]></content:encoded>
  <wp:post_id>{$postId}</wp:post_id>
  <wp:post_name>{$slug}</wp:post_name>
  <wp:post_date>2025-03-01 09:00:00</wp:post_date>
  <wp:post_modified>{$modified}</wp:post_modified>
  <wp:status>{$status}</wp:status>
  <wp:post_type>wp_block</wp:post_type>
  {$thumbnail}
</item>

XML;
    }

    $path = TemporaryFileRegistry::create('reusable-block');

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Blocks</title>
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
 * @param  ReusableBlockTestConverter  $converter  The content converter
 * @param  ?ReusableBlockEntryCreator  $creator  The creator, or null for a new one
 * @param  ?ReusableBlockTestMapper  $mapper  The metadata mapper, or null for a new one
 * @return array{output: string, result: array<int|string, mixed>, creator: ReusableBlockEntryCreator, mapper: ReusableBlockTestMapper} What the run produced
 */
function reusableBlockRun(string $export, ReusableBlockTestConverter $converter, ?ReusableBlockEntryCreator $creator = null, ?ReusableBlockTestMapper $mapper = null): array
{
    $parser = new XmlParser;
    $parser->parse($export);
    $output = new BufferedOutput;
    $creator ??= new ReusableBlockEntryCreator;
    $mapper ??= new ReusableBlockTestMapper;

    $result = $creator->createEntries('default', 'reusable_content', $parser, new AssetImporter, $output, $mapper, $converter);

    return ['output' => $output->fetch(), 'result' => $result, 'creator' => $creator, 'mapper' => $mapper];
}

/**
 * The saved entry with the given slug.
 *
 * @param  string  $slug  The slug
 * @return Entry The entry
 */
function reusableBlockEntry(string $slug): Entry
{
    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'reusable_content' && $entry->slug() === $slug) {
            return $entry;
        }
    }

    throw new RuntimeException("No reusable_content entry '{$slug}'.");
}

/**
 * The handle of an entry's blueprint.
 *
 * @param  Entry  $entry  The entry
 * @return ?string The handle, or null when it has none
 */
function reusableBlockBlueprint(Entry $entry): ?string
{
    $blueprint = $entry->blueprint();

    return $blueprint instanceof Blueprint ? $blueprint->handle() : null;
}

/**
 * The slugs of every saved reusable_content entry.
 *
 * @return list<mixed> The slugs, sorted
 */
function reusableBlockSlugs(): array
{
    $slugs = [];

    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'reusable_content') {
            $slugs[] = $entry->slug();
        }
    }

    \sort($slugs);

    return $slugs;
}

/**
 * Record the warnings logged.
 *
 * @return ReusableBlockTestWarnings The recorder
 */
function reusableBlockWarnings(): ReusableBlockTestWarnings
{
    $warnings = new ReusableBlockTestWarnings;

    Log::shouldReceive('warning')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($warnings): void {
        $warnings->logged[] = [$message, $context];
    });

    return $warnings;
}

beforeEach(function (): void {
    config(['statamic.search.indexes' => [], 'wordpress-import.upload_images' => false, 'wordpress-import.upload_files' => false]);
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    $collection = CollectionFacade::make('reusable_content');
    $collection->sites(['default']);
    $collection->save();

    foreach (['reusable_content_basic_page', 'reusable_content_landing_page'] as $handle) {
        $blueprint = BlueprintFacade::make($handle);
        $blueprint->setNamespace('collections.reusable_content');
        $blueprint->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
        $blueprint->save();
    }

    WpBlockRegistry::clear();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    WpBlockRegistry::clear();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();

    foreach (['collections.reusable_content.reusable_content_basic_page', 'collections.reusable_content.reusable_content_landing_page'] as $handle) {
        $blueprint = BlueprintFacade::find($handle);

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }

    TemporaryFileRegistry::sweep();
    Mockery::close();
});

describe('importing a block', function (): void {
    it('hands the block to the converter and the mapper, then saves it with its page builder content', function (): void {
        $converter = ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => [['type' => 'text', 'text' => 'Set.']]]);
        $run = reusableBlockRun(reusableBlockExport([['id' => '701', 'slug' => 'banner', 'title' => 'Banner &amp; Bar', 'content' => '<p>Hi</p>', 'thumbnail' => '9001']]), $converter);
        $entry = reusableBlockEntry('banner');

        expect($run['result'])->toBe([])
            ->and($converter->calls)->toHaveCount(1)
            ->and(\array_diff_key($converter->calls[0] ?? [], ['data' => true, 'entry' => true]))->toBe([
                'content' => '<p>Hi</p>',
                'site' => 'default',
                'title' => 'Banner & Bar',
                'id' => '701',
                'link' => 'https://example.test/wp_block/701/',
                'attachments' => 0,
                'terms' => 0,
                'items' => [],
                'collection' => 'reusable_content',
                'slug' => 'banner',
            ])
            ->and($converter->calls[0]['entry'] ?? null)->toBe($entry->id())
            ->and(\array_diff_key(\is_array($converter->calls[0]['data'] ?? null) ? $converter->calls[0]['data'] : [], ['id' => true]))->toBe([
                'blueprint' => 'reusable_content_landing_page',
                'title' => 'Banner & Bar',
                'site' => 'default',
                'original_wordpress_url' => 'https://example.test/wp_block/701/',
                '_thumbnail_id' => '9001',
                'published' => true,
            ])
            ->and($run['mapper']->mapped)->toBe(['701'])
            ->and(reusableBlockBlueprint($entry))->toBe('reusable_content_landing_page')
            ->and($entry->get('page_builder'))->toBe([['type' => 'text', 'text' => 'Set.']])
            ->and($entry->get('mapped'))->toBeTrue()
            ->and($entry->get('_thumbnail_id'))->toBe('9001')
            ->and($entry->get('updated_at'))->toBe((new DateTime('2025-03-02 10:00:00'))->getTimestamp())
            ->and($entry->published())->toBeTrue()
            ->and($run['output'])->toContain("Processing reusable content 'banner' with content length: 9".PHP_EOL);
    });

    it('treats a block without ACF blocks as a landing page too, so a body with no page builder content is not stored', function (): void {
        // Characterization of #239: `preg_match() !== false` is true for no match as
        // well as a match, so the basic-page branch that stores `content` never runs
        $converter = ReusableBlockTestConverter::returning([
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]]],
            'page_builder' => [],
        ]);
        reusableBlockRun(reusableBlockExport([['slug' => 'plain']]), $converter);
        $entry = reusableBlockEntry('plain');

        expect(reusableBlockBlueprint($entry))->toBe('reusable_content_landing_page')
            ->and($entry->get('content'))->toBeNull()
            ->and($entry->get('page_builder'))->toBeNull();
    });

    it('registers each block\'s entry id, against its post id, for blocks that refer to it', function (): void {
        reusableBlockRun(reusableBlockExport([['id' => '701', 'slug' => 'one'], ['id' => '702', 'slug' => 'two']]), ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]));

        expect(WpBlockRegistry::getAllMappings())->toBe(['701' => reusableBlockEntry('one')->id(), '702' => reusableBlockEntry('two')->id()]);
    });

    it('registers each block\'s URL for internal links', function (): void {
        reusableBlockRun(reusableBlockExport([['id' => '701', 'slug' => 'one']]), ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]));

        $map = (new ReflectionProperty(LinkProcessor::class, 'urlToPostIdMap'))->getValue();

        expect(\is_array($map) ? ($map['https://example.test/wp_block/701/'] ?? null) : null)->toBe('701');
    });

    it('names an untitled block, and stores no thumbnail it has none of', function (): void {
        reusableBlockRun(reusableBlockExport([['slug' => 'untitled', 'title' => '']]), ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]));
        $entry = reusableBlockEntry('untitled');

        expect($entry->get('title'))->toBe('Untitled Reusable Block')
            ->and($entry->get('_thumbnail_id'))->toBeNull();
    });

    it('makes a slug from the title and post id when the block has none, and warns', function (): void {
        $warnings = reusableBlockWarnings();

        reusableBlockRun(reusableBlockExport([['id' => '705', 'slug' => '', 'title' => 'Call Out']]), ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]));

        expect(reusableBlockSlugs())->toBe(['call-out-705'])
            ->and($warnings->logged)->toBe([[
                "ReusableBlockEntryCreator::createEntries: Missing wp:post_name for wp_block ID '705', using generated slug 'call-out-705' (from entry: https://example.test/wp_block/705/)",
                ['site' => 'default', 'title' => 'Call Out', 'entry_url' => 'https://example.test/wp_block/705/'],
            ]]);
    });

    it('skips a trashed or virtual block, and carries on to the next', function (string $status): void {
        // The parser leaves these statuses out of its own reads, so the items are
        // handed over the way a filtered run hands them
        $xml = \simplexml_load_file(reusableBlockExport([['id' => '701', 'slug' => 'gone', 'title' => 'Gone', 'status' => $status], ['id' => '702', 'slug' => 'kept']]));
        $items = $xml instanceof SimpleXMLElement ? $xml->xpath('//item') : null;
        $parser = new XmlParser;
        $parser->setItems(\is_array($items) ? $items : []);
        $converter = ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]);
        $output = new BufferedOutput;
        (new ReusableBlockEntryCreator)->createEntries('default', 'reusable_content', $parser, new AssetImporter, $output, new ReusableBlockTestMapper, $converter);
        $run = ['output' => $output->fetch()];

        expect(reusableBlockSlugs())->toBe(['kept'])
            ->and($converter->calls)->toHaveCount(1)
            ->and($run['output'])->toContain("Skipping wp_block with status '{$status}': Gone (ID: 701)".PHP_EOL);
    })->with(['trash', 'virtual']);

    it('saves a draft block unpublished', function (): void {
        reusableBlockRun(reusableBlockExport([['slug' => 'draft', 'status' => 'draft']]), ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]));

        expect(reusableBlockEntry('draft')->published())->toBeFalse();
    });

    it('leaves out the modified date when the block has none', function (): void {
        reusableBlockRun(reusableBlockExport([['slug' => 'undated', 'modified' => '']]), ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]));

        expect(reusableBlockEntry('undated')->get('updated_at'))->toBeNull();
    });
});

describe('importing a block that already exists', function (): void {
    it('skips it when told to, registering its URL but not its entry, and carries on', function (): void {
        $export = reusableBlockExport([['id' => '701', 'slug' => 'one'], ['id' => '702', 'slug' => 'two']]);
        reusableBlockRun($export, ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]));

        // A new run starts with nothing registered
        LinkProcessor::clearDeferredData();
        WpBlockRegistry::clear();

        $creator = new ReusableBlockEntryCreator;
        $creator->setEntryOverwriteBehavior('Skip matching files');
        $converter = ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]);
        $mapper = new ReusableBlockTestMapper;
        $run = reusableBlockRun($export, $converter, $creator, $mapper);

        $map = (new ReflectionProperty(LinkProcessor::class, 'urlToPostIdMap'))->getValue();

        // Characterization of #245: a reference to a skipped block cannot resolve
        expect($converter->calls)->toBe([])
            ->and($mapper->mapped)->toBe([])
            ->and(WpBlockRegistry::getAllMappings())->toBe([])
            ->and(\is_array($map) ? ($map['https://example.test/wp_block/702/'] ?? null) : null)->toBe('702')
            ->and($run['output'])->not->toContain('Processing reusable content');
    });

    it('imports a block that does not exist yet when told to skip existing ones', function (): void {
        $creator = new ReusableBlockEntryCreator;
        $creator->setEntryOverwriteBehavior('Skip matching files');
        $converter = ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]);

        reusableBlockRun(reusableBlockExport([['id' => '701', 'slug' => 'new']]), $converter, $creator);

        expect(reusableBlockSlugs())->toBe(['new'])
            ->and($converter->calls)->toHaveCount(1);
    });

    it('registers nothing for a block whose save fails', function (): void {
        $creator = new class extends ReusableBlockEntryCreator
        {
            /**
             * Fail every save.
             *
             * @param  string  $collectionHandle  The collection
             * @param  string  $site  The site
             * @param  string  $entrySlug  The slug
             * @param  array<int|string, mixed>  $data  The data
             * @param  OutputInterface  $output  The output
             * @param  ?DateTime  $entryDate  The date
             * @param  ?bool  $published  Whether published
             * @param  ?string  $postDateStr  The post date
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
                return null;
            }
        };

        reusableBlockRun(reusableBlockExport([['id' => '701', 'slug' => 'lost']]), ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]), $creator);

        expect(WpBlockRegistry::getAllMappings())->toBe([]);
    });

    it('overwrites it by default, keeping its entry id', function (): void {
        $export = reusableBlockExport([['id' => '701', 'slug' => 'one', 'title' => 'First']]);
        reusableBlockRun($export, ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]));
        $id = reusableBlockEntry('one')->id();

        $run = reusableBlockRun(reusableBlockExport([['id' => '701', 'slug' => 'one', 'title' => 'Second']]), ReusableBlockTestConverter::returning(['content' => [], 'page_builder' => []]));

        expect(reusableBlockEntry('one')->get('title'))->toBe('Second')
            ->and($run['result'])->toBe([])
            ->and(WpBlockRegistry::getEntryId('701'))->toBe($id);
    });
});

describe('the content type\'s other answers', function (): void {
    it('has no people, post categories or post tags, and nothing to validate', function (): void {
        $creator = new ReusableBlockEntryCreator;
        $output = new BufferedOutput;
        $creator->validateTaxonomies('reusable_content', 'default', $output);

        expect($creator->hasShowPeople())->toBeFalse()
            ->and($creator->getPostCategories())->toBe([])
            ->and($creator->getPostTags())->toBe([])
            ->and($output->fetch())->toBe('');
    });
});
