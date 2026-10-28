<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Sleep;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\EntriesStore;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredKnowledgeBaseMounts;
use UAMSWeb\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredProcessingCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpBlockBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpBlockRegistry;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Tests `DeferredProcessingCoordinator`: the pass after a content type is imported that links
 * tables, replaces wp_block placeholders, saves form handles and resolves internal links in the
 * entries that deferred them, and the final pass after every content type.
 *
 * Entries are real, in a `pages` collection kept off disk. The table linker and the form
 * coordinator are mocks, so each case names exactly what they return; everything else is the
 * importer's own code. Sleeps are faked, so the lookup retries take no time.
 *
 * @command  php vendor/bin/pest tests/Integration/DeferredProcessingCoordinatorTest.php
 */

/**
 * An entries store that records the calls the coordinator makes on it. Warming it puts the
 * real store back and saves an entry there, so a lookup after the warm can find it.
 */
final class DpcRecordingStore extends EntriesStore
{
    /**
     * The calls made, in order, shared with the faked sleeps.
     *
     * @var ArrayObject<int, string>
     */
    public static ArrayObject $calls;

    /**
     * The entry to save when warmed, or null to save none.
     */
    public static ?Entry $onWarm = null;

    /**
     * The real entries store, put back when warmed.
     */
    public static ?EntriesStore $real = null;

    /**
     * {@inheritDoc}
     */
    public function clear(): void
    {
        self::$calls->append('clear');
    }

    /**
     * {@inheritDoc}
     */
    public function warm(): void
    {
        self::$calls->append('warm');

        if (self::$real instanceof EntriesStore) {
            Stache::registerStore(self::$real);
        }

        self::$onWarm?->save();
    }
}

/**
 * A content processor that records each table-linking call, with the page link set when it
 * was made, and answers with the given content.
 */
final class DpcContentProcessor extends ContentProcessor
{
    /**
     * Each call, in order.
     *
     * @var list<array{bard: array<int|string, mixed>, builder: array<int|string, mixed>, site: string, tables: array<int|string, mixed>, data: array<int|string, mixed>, collection: string, parser: XmlParser, link: ?string}>
     */
    public array $calls = [];

    /**
     * The Bard content to answer, or null to answer the content given.
     *
     * @var ?array<int|string, mixed>
     */
    public ?array $bard = [];

    /**
     * The builder content to answer.
     *
     * @var array<int|string, mixed>
     */
    public array $builder = [];

    /**
     * {@inheritDoc}
     */
    public function linkTableEntries(
        array $bardContent,
        array $pageBuilderContent,
        string $site,
        array $tableIds,
        array $data,
        string $collectionHandle,
        XmlParser $xmlParser
    ): array {
        $this->calls[] = [
            'bard' => $bardContent,
            'builder' => $pageBuilderContent,
            'site' => $site,
            'tables' => $tableIds,
            'data' => $data,
            'collection' => $collectionHandle,
            'parser' => $xmlParser,
            'link' => ProcessingContext::getWordPressPageLink(),
        ];

        return ['bardContent' => $this->bard ?? $bardContent, 'pageBuilderContent' => $this->builder];
    }
}

/**
 * A form coordinator that records its calls, imports no form, and answers the given form
 * updates.
 */
final class DpcForms extends FormPluginCoordinator
{
    /**
     * Each form import asked for: the site, output, asset importer and page data.
     *
     * @var list<array{string, OutputInterface, AssetImporter, array<int|string, mixed>}>
     */
    public array $imports = [];

    /**
     * Each form update asked for: the site and output.
     *
     * @var list<array{string, OutputInterface}>
     */
    public array $updates = [];

    /**
     * The form updates to answer.
     *
     * @var array<int|string, mixed>
     */
    public array $answer = [];

    /**
     * {@inheritDoc}
     */
    public function processFormImports(
        string $siteHandle,
        OutputInterface $output,
        AssetImporter $assetImporter,
        array $pageDataMapping = []
    ): void {
        $this->imports[] = [$siteHandle, $output, $assetImporter, $pageDataMapping];
    }

    /**
     * {@inheritDoc}
     */
    public function updateAllFormHandlesInEntries(
        string $siteHandle,
        OutputInterface $output
    ): array {
        $this->updates[] = [$siteHandle, $output];

        return $this->answer;
    }
}

beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    $collection = CollectionFacade::make('pages');
    $collection->sites(['default']);
    $collection->save();

    Sleep::fake();
    dpcClear();
});

afterEach(function (): void {
    dpcClear();
});

/**
 * A recording content processor answering the given content. It is made without its parent's
 * constructor, which builds a sanitizer from the host app that nothing here reads.
 *
 * @param  ?array<int|string, mixed>  $bard  The Bard content to answer, or null to answer the content given
 * @param  array<int|string, mixed>  $builder  The builder content to answer
 */
function dpcContent(?array $bard = [], array $builder = []): DpcContentProcessor
{
    $content = (new ReflectionClass(DpcContentProcessor::class))->newInstanceWithoutConstructor();
    $content->bard = $bard;
    $content->builder = $builder;

    return $content;
}

/**
 * A recording form coordinator answering the given form updates, made without the form plugin
 * coordinators its parent's constructor takes, since it calls none.
 *
 * @param  array<int|string, mixed>  $answer  The form updates to answer
 */
function dpcForms(array $answer = []): DpcForms
{
    $forms = (new ReflectionClass(DpcForms::class))->newInstanceWithoutConstructor();
    $forms->answer = $answer;

    return $forms;
}

/**
 * Clear every registry the coordinator reads.
 */
function dpcClear(): void
{
    TablePressShortcodeProcessor::clearTableIds();
    WpBlockBlockProcessor::clearDeferredWpBlockReferences();
    WpBlockRegistry::clear();
    LinkProcessor::clearDeferredData();
    DeferredKnowledgeBaseMounts::clear();
    ProcessingContext::clearWordPressPageLink();
}

/**
 * A coordinator with every dependency set.
 *
 * @param  array<int|string, mixed>  $pageData  The page data
 */
function dpcCoordinator(
    array $pageData = [],
    ?FormPluginCoordinator $forms = null,
    ?ContentProcessor $content = null,
    ?AssetImporter $assets = null,
    ?XmlParser $parser = null
): DeferredProcessingCoordinator {
    $coordinator = new DeferredProcessingCoordinator;
    $coordinator->setContentProcessor($content ?? dpcContent());
    $coordinator->setFormPluginCoordinator($forms ?? dpcForms());
    $coordinator->setXmlParser($parser ?? new XmlParser);
    $coordinator->setAssetImporter($assets ?? new AssetImporter('http://93.184.216.34/site'));
    $coordinator->setPageData($pageData);

    return $coordinator;
}

/**
 * Run the pass for the `pages` collection, with warnings the importer raises turned into
 * exceptions, and answer its output with plain line endings. Statamic's own file writes can
 * race on a directory, so a warning from outside `src/` is left to PHP.
 */
function dpcRun(DeferredProcessingCoordinator $coordinator, string $collection = 'pages'): string
{
    $output = new BufferedOutput;

    set_error_handler(static function (int $level, string $message, string $file): bool {
        if (str_contains($file, DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR) && ! str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)) {
            throw new ErrorException($message, 0, $level, $file);
        }

        return false;
    });

    try {
        $coordinator->processPerContentType('default', $collection, $output);
    } finally {
        restore_error_handler();
    }

    return str_replace(PHP_EOL, "\n", $output->fetch());
}

/**
 * Make an entry in `pages`, saved unless asked not to.
 *
 * @param  array<string, mixed>  $data  The entry's data
 */
function dpcEntry(string $id, array $data = [], bool $save = true): Entry
{
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new LogicException('Expected an entry.');
    }

    $entry->collection('pages');
    $entry->locale('default');
    $entry->slug($id);
    $entry->id($id);
    $entry->data($data);

    if ($save) {
        $entry->save();
    }

    return $entry;
}

/**
 * The entry's data as the Stache holds it, whether saved or not.
 *
 * @return array<mixed>
 */
function dpcData(string $id): array
{
    $entry = EntryFacade::find($id);
    $data = $entry instanceof Entry ? $entry->data() : null;

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * The entry's data as its file holds it, sorted by key. The Stache's copy is forgotten first,
 * since it holds whatever was set on the entry, saved or not. Statamic writes no empty value,
 * and writes the blueprint, which the coordinator never sets, so that is left out.
 *
 * @return array<mixed>
 */
function dpcSaved(string $id): array
{
    $store = Stache::store('entries');
    $pages = $store instanceof EntriesStore ? $store->store('pages') : null;

    if (is_object($pages) && method_exists($pages, 'forgetItem')) {
        $pages->forgetItem($id);
    }

    $entry = EntryFacade::find($id);
    $data = $entry instanceof Entry ? $entry->data() : null;
    $values = $data instanceof Collection ? $data->all() : [];
    unset($values['blueprint']);
    ksort($values);

    return $values;
}

/**
 * Collect what is logged as a warning.
 *
 * @return ArrayObject<int, array{string, array<mixed>}>
 */
function dpcWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{string, array<mixed>}> $warnings */
    $warnings = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $logged) use ($warnings): void {
        if ($logged->level === 'warning') {
            $warnings->append([$logged->message, $logged->context]);
        }
    });

    return $warnings;
}

/**
 * The messages collected.
 *
 * @param  ArrayObject<int, array{string, array<mixed>}>  $warnings
 * @return list<string>
 */
function dpcMessages(ArrayObject $warnings): array
{
    return array_values(array_map(static fn (array $warning): string => $warning[0], $warnings->getArrayCopy()));
}

/**
 * The context of the warning at the given position, or an empty one.
 *
 * @param  ArrayObject<int, array{string, array<mixed>}>  $warnings
 * @return array<mixed>
 */
function dpcContext(ArrayObject $warnings, int $index): array
{
    return $warnings->getArrayCopy()[$index][1] ?? [];
}

/**
 * Defer wp_block references for the given entry ids.
 *
 * @param  list<string>  $entryIds  The entry ids
 */
function dpcDeferWpBlocks(array $entryIds): void
{
    $references = [];

    foreach ($entryIds as $entryId) {
        $references[$entryId] = [['wp_block_post_id' => '40', 'collection_handle' => 'pages', 'site' => 'default']];
    }

    (new ReflectionProperty(WpBlockBlockProcessor::class, 'deferredWpBlockReferences'))->setValue(null, $references);
}

/**
 * A Bard set holding the placeholder for wp_block 40.
 *
 * @return array<string, mixed>
 */
function dpcBlockSet(): array
{
    return ['type' => 'set', 'attrs' => ['id' => 'r1', 'values' => ['type' => 'reusable_content', 'import_wordpress_notes' => 'wp_block post ID 40']]];
}

/**
 * The Bard set wp_block 40 becomes once its entry is known.
 *
 * @return array<string, mixed>
 */
function dpcBlockSetResolved(): array
{
    return ['type' => 'set', 'attrs' => ['id' => 'r1', 'values' => ['type' => 'reusable_content', 'content' => 'block-40']]];
}

/**
 * A deferred link to the `about` page from a `pages` entry.
 *
 * @param  array<string, ?string>  $values  Values over the defaults
 * @return array{url: string, post_name: string, post_id: string, post_type: string, collection_handle: ?string, source_collection_handle: string, site: string, fragment: ?string, query: ?string}
 */
function dpcLink(array $values = []): array
{
    $link = array_merge([
        'url' => 'http://93.184.216.34/site/about/',
        'post_name' => 'about',
        'post_id' => '12',
        'post_type' => 'page',
        'collection_handle' => 'pages',
        'source_collection_handle' => 'pages',
        'site' => 'default',
        'fragment' => null,
        'query' => null,
    ], $values);

    return [
        'url' => (string) $link['url'],
        'post_name' => (string) $link['post_name'],
        'post_id' => (string) $link['post_id'],
        'post_type' => (string) $link['post_type'],
        'collection_handle' => $link['collection_handle'],
        'source_collection_handle' => (string) $link['source_collection_handle'],
        'site' => (string) $link['site'],
        'fragment' => $link['fragment'],
        'query' => $link['query'],
    ];
}

/**
 * A deferred link that resolves to nothing.
 *
 * @return array{url: string, post_name: string, post_id: string, post_type: string, collection_handle: ?string, source_collection_handle: string, site: string, fragment: ?string, query: ?string}
 */
function dpcGoneLink(): array
{
    return dpcLink(['url' => 'http://93.184.216.34/site/gone/', 'post_name' => 'gone', 'post_id' => '98']);
}

/**
 * Setters that leave one dependency of the coordinator unset.
 *
 * @return array<string, array{Closure(DeferredProcessingCoordinator): void}>
 */
function dpcMissingDependencies(): array
{
    $all = [
        'content processor' => static fn (DeferredProcessingCoordinator $c) => $c->setContentProcessor(dpcContent()),
        'form coordinator' => static fn (DeferredProcessingCoordinator $c) => $c->setFormPluginCoordinator(dpcForms()),
        'parser' => static fn (DeferredProcessingCoordinator $c) => $c->setXmlParser(new XmlParser),
        'asset importer' => static fn (DeferredProcessingCoordinator $c) => $c->setAssetImporter(new AssetImporter),
        'page data' => static fn (DeferredProcessingCoordinator $c) => $c->setPageData([]),
    ];

    $cases = [];

    foreach (array_keys($all) as $missing) {
        $cases["no {$missing}"] = [static function (DeferredProcessingCoordinator $coordinator) use ($all, $missing): void {
            foreach ($all as $name => $set) {
                if ($name !== $missing) {
                    $set($coordinator);
                }
            }
        }];
    }

    return $cases;
}

it('refuses to run without each of its dependencies', function (Closure $setUp): void {
    $coordinator = new DeferredProcessingCoordinator;
    $setUp($coordinator);

    expect(fn () => $coordinator->processPerContentType('default', 'pages', new BufferedOutput))
        ->toThrow(Exception::class, 'Required dependencies not set for deferred processing.');
})->with(dpcMissingDependencies());

it('imports the referenced forms with the asset importer and the page data', function (): void {
    $assets = new AssetImporter;
    $pageData = [12 => ['entry' => 'missing-entry']];
    $output = new BufferedOutput;
    $forms = dpcForms();

    dpcCoordinator($pageData, $forms, null, $assets)->processPerContentType('default', 'pages', $output);

    expect($output->fetch())->toBe('')
        ->and($forms->imports)->toBe([['default', $output, $assets, $pageData]])
        ->and($forms->updates)->toBe([['default', $output]]);
});

it('says nothing when nothing was deferred', function (): void {
    dpcEntry('home-1-abc');

    expect(dpcRun(dpcCoordinator([1 => ['entry' => 'home-1-abc']])))->toBe('');
});

it('links the tables each page holds, in the entry the page became', function (): void {
    dpcEntry('about-12-abc', ['title' => 'About', 'content' => [['type' => 'paragraph']], 'page_builder' => [['type' => 'table']]]);
    dpcEntry('team-13-abc', ['title' => 'Team']);
    TablePressShortcodeProcessor::addTableId('5', null, null, '12');
    TablePressShortcodeProcessor::addTableId('6', null, null, '12');
    TablePressShortcodeProcessor::addTableId('7', null, null, '99');
    $parser = new XmlParser;
    $content = dpcContent(['linked bard'], ['linked builder']);

    $output = dpcRun(dpcCoordinator([
        14 => 'not a page',
        15 => ['link' => 'https://old.test/no-entry/'],
        12 => ['entry' => 'about-12-abc', 'link' => 'https://old.test/about/'],
        13 => ['entry' => 'team-13-abc'],
    ], null, $content, null, $parser));

    expect($output)->toBe(
        "Processing table updates...\n"
        ."Re-processing entry ID 'about-12-abc' in the 'pages' collection\n"
        ."  - Updated table links.\n"
        ."  - Saved entry with table updates.\n"
    )
        ->and($content->calls)->toBe([[
            'bard' => [['type' => 'paragraph']],
            'builder' => [['type' => 'table']],
            'site' => 'default',
            'tables' => ['5', '6'],
            'data' => ['title' => 'About', 'content' => [['type' => 'paragraph']], 'page_builder' => [['type' => 'table']]],
            'collection' => 'pages',
            'parser' => $parser,
            'link' => 'https://old.test/about/',
        ]])
        ->and(dpcData('about-12-abc'))->toBe(['title' => 'About', 'content' => ['linked bard'], 'page_builder' => ['linked builder']])
        ->and(TablePressShortcodeProcessor::getTableIds())->toBe([])
        ->and(TablePressShortcodeProcessor::getTableIdsForPage('99'))->toBe([]);
});

it('links tables for a page keyed by a numeric string, into an entry whose id is a number', function (): void {
    dpcEntry('77');
    TablePressShortcodeProcessor::addTableId('5', null, null, '12');
    $content = dpcContent(['b']);
    ProcessingContext::setWordPressPageLink('https://old.test/earlier/');

    $output = dpcRun(dpcCoordinator(['12' => ['entry' => 77, 'link' => 5]], null, $content));

    expect($output)->toContain("Re-processing entry ID '77' in the 'pages' collection\n")
        ->and(array_column($content->calls, 'tables'))->toBe([['5']])
        ->and(array_column($content->calls, 'link'))->toBe([null])
        ->and(dpcData('77'))->toBe(['content' => ['b'], 'page_builder' => []]);
});

it('hands the table linker no content or builder when the entry holds something else', function (): void {
    dpcEntry('about-12-abc', ['content' => 'text', 'page_builder' => 'text']);
    TablePressShortcodeProcessor::addTableId('5', null, null, '12');
    $content = dpcContent();

    dpcRun(dpcCoordinator([12 => ['entry' => 'about-12-abc']], null, $content));

    expect(array_column($content->calls, 'bard'))->toBe([[]])
        ->and(array_column($content->calls, 'builder'))->toBe([[]])
        ->and(array_column($content->calls, 'data'))->toBe([['content' => 'text', 'page_builder' => 'text']])
        ->and(dpcData('about-12-abc'))->toBe(['content' => [], 'page_builder' => []]);
});

it('keeps the table ids when no page in this run holds one', function (): void {
    TablePressShortcodeProcessor::addTableId('5', null, null, '12');
    $content = dpcContent();

    $output = dpcRun(dpcCoordinator([13 => ['entry' => 'team-13-abc']], null, $content));

    expect($output)->toBe("Processing table updates...\n")
        ->and($content->calls)->toBe([])
        ->and(TablePressShortcodeProcessor::getTableIds())->toBe(['5']);
});

it('uses the entry the page data holds, without looking it up', function (): void {
    $entry = dpcEntry('about-12-abc', ['title' => 'Cached'], false);
    TablePressShortcodeProcessor::addTableId('5', null, null, '12');
    $content = dpcContent(['b'], ['p']);

    $output = dpcRun(dpcCoordinator([12 => ['entry' => 'about-12-abc', 'entry_object' => $entry]], null, $content));

    Sleep::assertNeverSlept();

    expect($output)->toBe(
        "Processing table updates...\n"
        ."Re-processing entry ID 'about-12-abc' in the 'pages' collection\n"
        ."  - Using cached entry object.\n"
        ."  - Updated table links.\n"
        ."  - Saved entry with table updates.\n"
    )
        ->and(dpcData('about-12-abc'))->toBe(['title' => 'Cached', 'content' => ['b'], 'page_builder' => ['p']]);
});

it('looks the entry up when the page data holds something other than an entry', function (): void {
    dpcEntry('about-12-abc', ['title' => 'Saved']);
    TablePressShortcodeProcessor::addTableId('5', null, null, '12');
    $content = dpcContent();

    $output = dpcRun(dpcCoordinator([12 => ['entry' => 'about-12-abc', 'entry_object' => 'not an entry']], null, $content));

    expect($output)->not->toContain('Using cached entry object.')
        ->and(dpcData('about-12-abc'))->toBe(['title' => 'Saved', 'content' => [], 'page_builder' => []]);
});

it('retries a lookup that misses, waiting between attempts, and finds an entry saved meanwhile', function (): void {
    $warnings = dpcWarnings();
    $entry = dpcEntry('about-12-abc', ['title' => 'Late'], false);
    $sleeps = 0;
    Sleep::whenFakingSleep(function () use (&$sleeps, $entry): void {
        $sleeps++;

        if ($sleeps === 2) {
            $entry->save();
        }
    });
    TablePressShortcodeProcessor::addTableId('5', null, null, '12');
    $content = dpcContent();

    $output = dpcRun(dpcCoordinator([12 => ['entry' => 'about-12-abc']], null, $content));

    Sleep::assertSequence([Sleep::usleep(500000), Sleep::usleep(500000)]);
    expect($output)->toContain('Saved entry with table updates.')
        ->and(dpcMessages($warnings))->toBe([
            "DeferredProcessingCoordinator::loadEntry: Entry 'about-12-abc' not found in 'pages' collection on attempt 1",
            "DeferredProcessingCoordinator::loadEntry: Entry 'about-12-abc' not found in 'pages' collection on attempt 2",
        ])
        ->and(dpcContext($warnings, 0))->toMatchArray(['site' => 'default', 'collection' => 'pages', 'retry_count' => 1])
        ->and(dpcContext($warnings, 1))->toMatchArray(['retry_count' => 2]);
});

it('rebuilds the entries store after seven misses, and looks once more', function (): void {
    $warnings = dpcWarnings();
    DpcRecordingStore::$calls = new ArrayObject;
    DpcRecordingStore::$onWarm = dpcEntry('about-12-abc', ['title' => 'Rebuilt'], false);
    $real = Stache::store('entries');
    DpcRecordingStore::$real = $real instanceof EntriesStore ? $real : null;
    Stache::registerStore(new DpcRecordingStore);
    Sleep::whenFakingSleep(function (): void {
        DpcRecordingStore::$calls->append('sleep');
    });
    TablePressShortcodeProcessor::addTableId('5', null, null, '12');
    $content = dpcContent();

    $output = dpcRun(dpcCoordinator([12 => ['entry' => 'about-12-abc']], null, $content));

    expect(DpcRecordingStore::$calls->getArrayCopy())->toBe(['sleep', 'sleep', 'sleep', 'sleep', 'sleep', 'sleep', 'sleep', 'clear', 'warm', 'sleep'])
        ->and(dpcMessages($warnings))->toBe(array_map(
            static fn (int $attempt): string => "DeferredProcessingCoordinator::loadEntry: Entry 'about-12-abc' not found in 'pages' collection on attempt {$attempt}",
            range(1, 7)
        ))
        ->and(dpcContext($warnings, 6))->toMatchArray(['retry_count' => 7])
        ->and($output)->toContain('Saved entry with table updates.')
        ->and(dpcData('about-12-abc'))->toBe(['blueprint' => 'page', 'title' => 'Rebuilt', 'content' => [], 'page_builder' => []]);
    Sleep::assertSequence(array_fill(0, 8, Sleep::usleep(500000)));
});

it('says so, and goes on, when an entry cannot be found', function (): void {
    $warnings = dpcWarnings();
    dpcEntry('team-13-abc');
    TablePressShortcodeProcessor::addTableId('5', null, null, '12');
    TablePressShortcodeProcessor::addTableId('6', null, null, '13');
    $content = dpcContent();

    $output = dpcRun(dpcCoordinator([12 => ['entry' => 'gone-12-abc'], 13 => ['entry' => 'team-13-abc']], null, $content));

    expect($output)->toBe(
        "Processing table updates...\n"
        ."Re-processing entry ID 'gone-12-abc' in the 'pages' collection\n"
        ."Failed to find entry 'gone-12-abc' in 'pages' collection in site 'default'.\n"
        ."Re-processing entry ID 'team-13-abc' in the 'pages' collection\n"
        ."  - Updated table links.\n"
        ."  - Saved entry with table updates.\n"
    )
        ->and(count($warnings))->toBe(8)
        ->and(dpcMessages($warnings)[7] ?? null)->toBe("DeferredProcessingCoordinator::loadEntry: Failed to find entry 'gone-12-abc' in 'pages' collection in site 'default'")
        ->and(dpcContext($warnings, 7))->toMatchArray(['site' => 'default', 'collection' => 'pages', 'entry_id' => 'gone-12-abc'])
        ->and(TablePressShortcodeProcessor::getTableIds())->toBe([]);
});

it('replaces the wp_block placeholders in each entry that deferred them', function (): void {
    WpBlockRegistry::registerMapping('40', 'block-40');
    dpcEntry('about-12-abc', ['title' => 'About', 'content' => [dpcBlockSet()], 'page_builder' => [dpcBlockSet()]]);
    dpcEntry('team-13-abc', ['content' => [dpcBlockSet()]]);
    dpcDeferWpBlocks(['about-12-abc']);

    $output = dpcRun(dpcCoordinator([
        14 => 'not a page',
        15 => ['link' => 'https://old.test/no-entry/'],
        12 => ['entry' => 'about-12-abc'],
        13 => ['entry' => 'team-13-abc'],
    ]));

    expect($output)->toBe(
        "Processing wp_block updates...\n"
        ."Re-processing entry ID 'about-12-abc' in the 'pages' collection\n"
        ."  - Applied wp_block updates: updated wp_block placeholders with reusable_block entry IDs.\n"
        ."  - Saved entry with wp_block updates.\n"
    )
        ->and(dpcSaved('about-12-abc'))->toBe(['content' => [dpcBlockSetResolved()], 'page_builder' => [dpcBlockSetResolved()], 'title' => 'About'])
        ->and(dpcData('team-13-abc'))->toBe(['content' => [dpcBlockSet()]]);
});

it('saves an entry whose builder alone held a wp_block placeholder', function (): void {
    WpBlockRegistry::registerMapping('40', 'block-40');
    dpcEntry('about-12-abc', ['content' => 'text', 'page_builder' => [dpcBlockSet()]]);
    dpcDeferWpBlocks(['about-12-abc']);

    $output = dpcRun(dpcCoordinator([12 => ['entry' => 'about-12-abc']]));

    expect($output)->toContain('Saved entry with wp_block updates.')
        ->and(dpcData('about-12-abc'))->toBe(['content' => [], 'page_builder' => [dpcBlockSetResolved()]]);
});

it('leaves an entry unsaved when its wp_block is still unknown', function (): void {
    dpcEntry('about-12-abc', ['content' => [dpcBlockSet()]]);
    dpcDeferWpBlocks(['about-12-abc']);

    $output = dpcRun(dpcCoordinator([12 => ['entry' => 'about-12-abc']]));

    expect($output)->toBe(
        "Processing wp_block updates...\n"
        ."Re-processing entry ID 'about-12-abc' in the 'pages' collection\n"
    )
        ->and(dpcData('about-12-abc'))->toBe(['content' => [dpcBlockSet()]]);
});

it('stops when no page in this run deferred a wp_block', function (): void {
    dpcDeferWpBlocks(['about-12-abc']);

    expect(dpcRun(dpcCoordinator([13 => ['entry' => 'team-13-abc']])))->toBe("Processing wp_block updates...\n");
});

it('skips an entry with a wp_block that cannot be found, and goes on', function (): void {
    WpBlockRegistry::registerMapping('40', 'block-40');
    dpcEntry('team-13-abc', ['content' => [dpcBlockSet()]]);
    dpcDeferWpBlocks(['about-12-abc', 'team-13-abc']);

    $output = dpcRun(dpcCoordinator([12 => ['entry' => 'about-12-abc'], 13 => ['entry' => 'team-13-abc']]));

    expect($output)->toBe(
        "Processing wp_block updates...\n"
        ."Re-processing entry ID 'about-12-abc' in the 'pages' collection\n"
        ."Failed to find entry 'about-12-abc' in 'pages' collection in site 'default'.\n"
        ."Re-processing entry ID 'team-13-abc' in the 'pages' collection\n"
        ."  - Applied wp_block updates: updated wp_block placeholders with reusable_block entry IDs.\n"
        ."  - Saved entry with wp_block updates.\n"
    )
        ->and(dpcSaved('team-13-abc'))->toBe(['content' => [dpcBlockSetResolved()]]);
});

it('saves each entry the form coordinator updated, naming each form it applied', function (): void {
    $about = dpcEntry('about-12-abc', ['title' => 'About']);
    $team = dpcEntry('team-13-abc', ['title' => 'Team']);
    $numbered = dpcEntry('77', ['title' => 'Numbered']);
    $forms = dpcForms([
        'about-12-abc' => [
            'entry' => $about,
            'collection' => 'pages',
            'form_updates' => [
                ['form_note' => 'Gravity Form 3', 'form_handle' => 'contact'],
                'not an update',
                ['form_note' => 5, 'form_handle' => null],
            ],
            'data' => ['title' => 'About', 'content' => 'with form'],
        ],
        'junk' => 'not a record',
        'no-entry' => ['entry' => 'not an entry', 'collection' => 'pages', 'data' => []],
        'no-collection' => ['entry' => $team, 'collection' => 5, 'data' => []],
        'team-13-abc' => ['entry' => $team, 'collection' => 'pages', 'form_updates' => 'not a list', 'data' => 'not data'],
        77 => ['entry' => $numbered, 'collection' => 'pages', 'data' => ['title' => 'Numbered', 'content' => 'form']],
    ]);

    $output = dpcRun(dpcCoordinator([], $forms));

    expect($output)->toBe(
        "Processing form updates...\n"
        ."Re-processing entry ID 'about-12-abc' in the 'pages' collection\n"
        ."  - Using cached entry object.\n"
        ."  - Applied form update: Gravity Form 3 -> contact\n"
        ."  - Applied form update:  -> \n"
        ."  - Saved entry with form updates.\n"
        ."Re-processing entry ID 'team-13-abc' in the 'pages' collection\n"
        ."  - Using cached entry object.\n"
        ."Re-processing entry ID '77' in the 'pages' collection\n"
        ."  - Using cached entry object.\n"
        ."  - Saved entry with form updates.\n"
    )
        ->and(dpcSaved('about-12-abc'))->toBe(['content' => 'with form', 'title' => 'About'])
        ->and(dpcSaved('team-13-abc'))->toBe(['title' => 'Team'])
        ->and(dpcSaved('77'))->toBe(['content' => 'form', 'title' => 'Numbered']);
});

it('resolves the internal links each entry of this collection deferred, and forgets them', function (): void {
    dpcEntry('about-12-abc');
    dpcEntry('home-1-abc', ['content' => 'saved']);
    $home = dpcEntry('home-1-abc', ['content' => 'see http://93.184.216.34/site/about/', 'page_builder' => [['id' => 's', 'url' => 'http://93.184.216.34/site/about/']]], false);
    dpcEntry('blog-2-abc', ['content' => 'see http://93.184.216.34/site/about/']);
    LinkProcessor::setDeferredInternalLinks([
        'empty-3-abc' => [],
        'blog-2-abc' => [dpcLink(['source_collection_handle' => 'posts'])],
        'home-1-abc' => [dpcLink()],
    ]);

    $output = dpcRun(dpcCoordinator([1 => 'not a page', 2 => ['entry' => 'other-2-abc'], 3 => ['entry' => 'home-1-abc', 'entry_object' => $home]]));

    expect($output)->toBe(
        "Resolving deferred internal links for entries in current post type...\n"
        ."Re-processing entry ID 'home-1-abc' in the 'pages' collection\n"
        ."  - Using cached entry object.\n"
        ."  - Resolved deferred link: http://93.184.216.34/site/about/ -> statamic://entry::about-12-abc\n"
        ."  - Saved entry with internal link updates.\n"
    )
        ->and(dpcData('home-1-abc'))->toBe(['content' => 'see statamic://entry::about-12-abc', 'page_builder' => [['id' => 's', 'url' => 'statamic://entry::about-12-abc']]])
        ->and(dpcData('blog-2-abc'))->toBe(['content' => 'see http://93.184.216.34/site/about/'])
        ->and(LinkProcessor::getDeferredInternalLinks())->toBe([
            'empty-3-abc' => [],
            'blog-2-abc' => [dpcLink(['source_collection_handle' => 'posts'])],
        ]);
});

it('keeps the links an entry could not resolve, renumbered', function (): void {
    dpcEntry('about-12-abc');
    dpcEntry('home-1-abc', ['content' => 'see http://93.184.216.34/site/gone/ and http://93.184.216.34/site/about/']);
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [dpcGoneLink(), dpcLink(), dpcGoneLink()]]);

    dpcRun(dpcCoordinator());

    expect(dpcData('home-1-abc'))->toBe(['content' => 'see http://93.184.216.34/site/gone/ and statamic://entry::about-12-abc'])
        ->and(LinkProcessor::getDeferredInternalLinks())->toBe(['home-1-abc' => [dpcGoneLink(), dpcGoneLink()]]);
});

it('saves no entry, forgets no link and warns of none when none of its links resolved', function (): void {
    $warnings = dpcWarnings();
    dpcEntry('home-1-abc', ['content' => 'see http://93.184.216.34/site/gone/']);
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [dpcGoneLink()]]);

    $output = dpcRun(dpcCoordinator());

    expect($output)->toBe(
        "Resolving deferred internal links for entries in current post type...\n"
        ."Re-processing entry ID 'home-1-abc' in the 'pages' collection\n"
    )
        ->and(LinkProcessor::getDeferredInternalLinks())->toBe(['home-1-abc' => [dpcGoneLink()]])
        ->and(dpcMessages($warnings))->toBe([]);
});

it('writes only the field a resolved link rewrote', function (string $field, mixed $before, mixed $after): void {
    dpcEntry('about-12-abc');
    dpcEntry('home-1-abc', [$field => $before]);
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [dpcLink()]]);

    dpcRun(dpcCoordinator());

    expect(dpcData('home-1-abc'))->toBe([$field => $after]);
})->with([
    'content' => ['content', 'see http://93.184.216.34/site/about/', 'see statamic://entry::about-12-abc'],
    'builder' => ['page_builder', [['id' => 's', 'url' => 'http://93.184.216.34/site/about/']], [['id' => 's', 'url' => 'statamic://entry::about-12-abc']]],
]);

it('takes the collection of an entry whose first link is not at the start of its list to be this one', function (): void {
    dpcEntry('about-12-abc');
    dpcEntry('home-1-abc', ['content' => 'see http://93.184.216.34/site/about/']);
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [3 => dpcLink(['source_collection_handle' => 'posts'])]]);

    $output = dpcRun(dpcCoordinator());

    expect($output)->toContain("Re-processing entry ID 'home-1-abc' in the 'pages' collection")
        ->and(dpcData('home-1-abc'))->toBe(['content' => 'see statamic://entry::about-12-abc']);
});

it('uses the first page record holding the entry, and the entry it holds', function (): void {
    dpcEntry('about-12-abc');
    dpcEntry('home-1-abc', ['content' => 'saved']);
    $first = dpcEntry('home-1-abc', ['content' => 'first http://93.184.216.34/site/about/'], false);
    $second = dpcEntry('home-1-abc', ['content' => 'second http://93.184.216.34/site/about/'], false);
    (new ReflectionProperty(LinkProcessor::class, 'deferredInternalLinks'))->setValue(null, [77 => [dpcLink()], 'home-1-abc' => [dpcLink()]]);

    $output = dpcRun(dpcCoordinator([
        1 => ['entry' => 'home-1-abc', 'entry_object' => $first],
        2 => ['entry' => 'home-1-abc', 'entry_object' => $second],
    ]));

    expect($output)->toContain("Re-processing entry ID '77' in the 'pages' collection")
        ->and($output)->toContain('Using cached entry object.')
        ->and(dpcData('home-1-abc'))->toBe(['content' => 'first statamic://entry::about-12-abc']);
});

it('runs the steps in order: tables, wp_blocks, forms, then internal links', function (): void {
    WpBlockRegistry::registerMapping('40', 'block-40');
    $about = dpcEntry('about-12-abc', ['content' => [dpcBlockSet()]]);
    dpcEntry('home-1-abc', ['content' => 'see http://93.184.216.34/site/about/']);
    TablePressShortcodeProcessor::addTableId('5', null, null, '12');
    dpcDeferWpBlocks(['about-12-abc']);
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [dpcLink()]]);
    $content = dpcContent(null);
    $forms = dpcForms(['about-12-abc' => ['entry' => $about, 'collection' => 'pages']]);

    $output = dpcRun(dpcCoordinator([12 => ['entry' => 'about-12-abc'], 1 => ['entry' => 'home-1-abc']], $forms, $content));

    $positions = array_map(static fn (string $line): int|false => strpos($output, $line), [
        'Processing table updates...',
        'Processing wp_block updates...',
        'Processing form updates...',
        'Resolving deferred internal links',
    ]);

    expect($positions)->not->toContain(false)
        ->and($positions)->toBe(array_values(array_unique($positions)))
        ->and($positions)->toBe((static function (array $sorted): array {
            sort($sorted);

            return $sorted;
        })($positions));
});

it('resolves the remaining links, mounts the knowledge bases, then forgets what was deferred', function (): void {
    dpcEntry('about-12-abc');
    dpcEntry('home-1-abc', ['content' => 'see http://93.184.216.34/site/about/ and http://93.184.216.34/site/gone/']);
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [dpcLink(), dpcGoneLink()]]);
    DeferredKnowledgeBaseMounts::defer('kb-root', '55', 'default');
    DeferredKnowledgeBaseMounts::defer('kb-other', '56', 'other');
    $warnings = dpcWarnings();
    $output = new BufferedOutput;

    (new DeferredProcessingCoordinator)->processFinal('default', $output);

    $text = str_replace(PHP_EOL, "\n", $output->fetch());

    expect($text)->toStartWith("Processing deferred internal links...\n")
        ->and($text)->toContain("Remaining deferred internal links: 1\n")
        ->and($text)->toContain("WordPress ID '55'")
        ->and(dpcMessages($warnings))->toContain('LinkProcessor::resolveDeferredLink: Entry not found by slug or WordPress ID after all content types imported')
        ->and(dpcData('home-1-abc'))->toBe(['blueprint' => 'page', 'content' => 'see statamic://entry::about-12-abc and http://93.184.216.34/site/gone/'])
        ->and(LinkProcessor::getDeferredInternalLinks())->toBe([])
        ->and(DeferredKnowledgeBaseMounts::all())->toBe([['kb_root_id' => 'kb-other', 'parent_wp_id' => '56', 'site' => 'other']]);
});

it('saves each entry it updates at the path its collection builds, leaving the file it was read from', function (string $step): void {
    WpBlockRegistry::registerMapping('40', 'block-40');
    dpcEntry('about-12-abc');
    $entry = dpcEntry('home-1-abc', ['content' => $step === 'wp_block' ? [dpcBlockSet()] : 'see http://93.184.216.34/site/about/']);
    $builtPath = $entry->buildPath();
    $oldPath = dirname(is_string($builtPath) ? $builtPath : '').'/old-home.md';
    file_put_contents($oldPath, "---\nid: old\n---\n");
    $entry->initialPath($oldPath);
    $pageData = [1 => ['entry' => 'home-1-abc', 'entry_object' => $entry]];
    $forms = dpcForms();

    match ($step) {
        'table' => TablePressShortcodeProcessor::addTableId('5', null, null, '1'),
        'wp_block' => dpcDeferWpBlocks(['home-1-abc']),
        'form' => $forms = dpcForms(['home-1-abc' => ['entry' => $entry, 'collection' => 'pages', 'data' => ['content' => 'form']]]),
        default => LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [dpcLink()]]),
    };

    dpcRun(dpcCoordinator($pageData, $forms));

    expect($entry->initialPath())->toBe($entry->buildPath())
        ->and(file_get_contents($oldPath))->toBe("---\nid: old\n---\n");

    unlink($oldPath);
})->with(['table', 'wp_block', 'form', 'link']);
