<?php

declare(strict_types=1);

/**
 * Tests `TablePressEntryCreator`: what each `tablepress_table` item becomes, from
 * its rows, options, visibility and description to how it is saved under each
 * overwrite behavior, and the items and rows it skips.
 *
 * The creator saves through its own `saveEntry()`, so the `tables` collection and
 * its `table` blueprint are made here. The metadata mapper is a recording
 * subclass, and the content processor is never called.
 *
 * @command  php vendor/bin/pest tests/Integration/TablePressEntryCreatorTest.php
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
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\TablePressEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * A metadata mapper that records each table and marks the data it maps.
 */
final class TablePressEntryTestMapper extends MetadataMapper
{
    /**
     * The post id of each table mapped.
     *
     * @var list<string>
     */
    public array $mapped = [];

    /**
     * Record the table and mark the data.
     *
     * @param  SimpleXMLElement  $page  The table
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
 * A table's string field, or the default when it is absent.
 *
 * @param  array<mixed>  $table  The table's fields
 * @param  string  $key  The field
 * @param  string  $default  The default
 * @return string The value
 */
function tablePressField(array $table, string $key, string $default): string
{
    $value = $table[$key] ?? $default;

    return \is_string($value) ? $value : $default;
}

/**
 * A table's map of strings, or none when it is absent.
 *
 * @param  array<mixed>  $table  The table's fields
 * @param  string  $key  The field
 * @return array<string> The values
 */
function tablePressFields(array $table, string $key): array
{
    $values = $table[$key] ?? [];

    return \is_array($values) ? \array_filter($values, \is_string(...)) : [];
}

/**
 * Write an export of the given tables.
 *
 * @param  list<array<mixed>>  $tables  Each table's fields
 * @return string The export path
 */
function tablePressExport(array $tables): string
{
    $xml = '';

    foreach ($tables as $index => $table) {
        $postId = tablePressField($table, 'id', (string) (600 + $index));
        $title = tablePressField($table, 'title', "Table {$postId}");
        $status = tablePressField($table, 'status', 'publish');
        $content = tablePressField($table, 'content', '[["a","b"],["c",""]]');
        $excerpt = tablePressField($table, 'excerpt', '');
        $date = tablePressField($table, 'date', '2025-04-01 09:00:00');
        $meta = '';

        foreach (tablePressFields($table, 'meta') as $key => $value) {
            $meta .= "<wp:postmeta><wp:meta_key>{$key}</wp:meta_key><wp:meta_value><![CDATA[{$value}]]></wp:meta_value></wp:postmeta>";
        }

        $xml .= <<<XML
<item>
  <title><![CDATA[{$title}]]></title>
  <link>https://example.test/tables/{$postId}/</link>
  <content:encoded><![CDATA[{$content}]]></content:encoded>
  <excerpt:encoded><![CDATA[{$excerpt}]]></excerpt:encoded>
  <wp:post_id>{$postId}</wp:post_id>
  <wp:post_date>{$date}</wp:post_date>
  <wp:post_modified>2025-04-02 10:00:00</wp:post_modified>
  <wp:status>{$status}</wp:status>
  <wp:post_type>tablepress_table</wp:post_type>
  {$meta}
</item>

XML;
    }

    $path = TemporaryFileRegistry::create('tablepress-entry');

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Tables</title>
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
 * @param  ?TablePressEntryCreator  $creator  The creator, or null for a new one
 * @param  ?XmlParser  $parser  The parser, or null for one over the export
 * @return array{output: string, result: array<int|string, mixed>, mapper: TablePressEntryTestMapper} What the run produced
 */
function tablePressRun(string $export, ?TablePressEntryCreator $creator = null, ?XmlParser $parser = null): array
{
    if (! $parser instanceof XmlParser) {
        $parser = new XmlParser;
        $parser->parse($export);
    }

    $output = new BufferedOutput;
    $mapper = new TablePressEntryTestMapper;
    $converter = (new ReflectionClass(ContentProcessor::class))->newInstanceWithoutConstructor();
    $result = ($creator ?? new TablePressEntryCreator)->createEntries('default', 'tables', $parser, new AssetImporter, $output, $mapper, $converter);

    return ['output' => $output->fetch(), 'result' => $result, 'mapper' => $mapper];
}

/**
 * An entry's slug.
 *
 * @param  Entry  $entry  The entry
 * @return string The slug, or an empty string when it has none
 */
function tablePressSlug(Entry $entry): string
{
    $slug = $entry->slug();

    return \is_string($slug) ? $slug : '';
}

/**
 * An entry's date.
 *
 * @param  Entry  $entry  The entry
 * @return ?DateTimeInterface The date, or null when it has none
 */
function tablePressDate(Entry $entry): ?DateTimeInterface
{
    $date = $entry->date();

    return $date instanceof DateTimeInterface ? $date : null;
}

/**
 * Every saved table, by slug.
 *
 * @return array<string, Entry> The entries
 */
function tablePressEntries(): array
{
    $entries = [];

    foreach (EntryFacade::all() as $entry) {
        if ($entry instanceof Entry && $entry->collectionHandle() === 'tables') {
            $entries[tablePressSlug($entry)] = $entry;
        }
    }

    return $entries;
}

/**
 * The saved table with the given slug.
 *
 * @param  string  $slug  The slug
 * @return Entry The entry
 */
function tablePressEntry(string $slug): Entry
{
    return tablePressEntries()[$slug] ?? throw new RuntimeException("No tables entry '{$slug}'.");
}

/**
 * Everything an entry stores.
 *
 * @param  Entry  $entry  The entry
 * @return array<mixed> The entry's data
 */
function tablePressData(Entry $entry): array
{
    $data = $entry->data();

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * Make the tables collection and its blueprint.
 *
 * @param  bool  $dated  Whether the collection is dated
 * @param  bool  $withStatus  Whether the blueprint has a status and visibility field
 */
function tablePressCollection(bool $dated = false, bool $withStatus = false): void
{
    $collection = CollectionFacade::make('tables');
    $collection->sites(['default']);
    $collection->dated($dated);
    $collection->save();

    $fields = [['handle' => 'title', 'field' => ['type' => 'text']]];

    if ($withStatus) {
        $fields[] = ['handle' => 'status_and_visibility', 'field' => ['type' => 'text']];
    }

    $blueprint = BlueprintFacade::make('table');
    $blueprint->setNamespace('collections.tables');
    $blueprint->setContents(['fields' => $fields]);
    $blueprint->save();
}

beforeEach(function (): void {
    config(['statamic.search.indexes' => []]);
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);
    tablePressCollection();

    BaseEntryCreator::clearTaxonomyCaches();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();

    $blueprint = BlueprintFacade::find('collections.tables.table');

    if ($blueprint instanceof Blueprint) {
        $blueprint->delete();
    }

    TemporaryFileRegistry::sweep();
    Mockery::close();
});

/**
 * Collect every warning logged, as message and context.
 *
 * @return ArrayObject<int, array{0: mixed, 1: mixed}> The warnings, filled as they are logged
 */
function tablePressWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{0: mixed, 1: mixed}> $warnings */
    $warnings = new ArrayObject;
    Log::shouldReceive('warning')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($warnings): void {
        $warnings->append([$message, $context]);
    });
    Log::shouldReceive('info')->andReturnNull();

    return $warnings;
}

/**
 * The messages of the collected warnings.
 *
 * @param  ArrayObject<int, array{0: mixed, 1: mixed}>  $warnings  The warnings
 * @return list<mixed> The messages
 */
function tablePressMessages(ArrayObject $warnings): array
{
    return \array_values(\array_map(static fn (array $warning): mixed => $warning[0], $warnings->getArrayCopy()));
}

/**
 * The postmeta every table in these tests carries, plus any given.
 *
 * @param  array<string, string>  $meta  More postmeta
 * @return array<string, string> The postmeta
 */
function tablePressMeta(array $meta = []): array
{
    return ['_tablepress_export_table_id' => '12', ...$meta];
}

describe('importing a table', function (): void {
    it('saves a table with its rows, description, options and defaults, and reports it as page data', function (): void {
        $run = tablePressRun(tablePressExport([[
            'id' => '601',
            'title' => 'Fees &amp; Costs',
            'excerpt' => '<p>About &amp;amp; the fees.</p>',
            'meta' => tablePressMeta([
                '_tablepress_table_options' => '{"table_head":true,"table_foot":true,"alternating_row_colors":true,"row_hover":false,"use_datatables":true,"datatables_sort":true,"datatables_filter":true,"datatables_paginate":true,"datatables_paginate_entries":"25","datatables_lengthchange":true,"datatables_info":true,"datatables_scrollx":false}',
            ]),
        ]]));
        $entry = tablePressEntry('default_tablepress-12');
        $id = $entry->id();

        expect(\is_string($id) ? $id : '')->toStartWith('default_tablepress-12-601-')
            ->and($entry->published())->toBeTrue()
            ->and($entry->locale())->toBe('default')
            ->and(tablePressData($entry))->toBe([
                'blueprint' => 'table',
                'title' => 'Fees & Costs',
                'caption' => 'Fees & Costs',
                'first_row_headers' => true,
                'first_column_headers' => false,
                'fixed_width_table_cells' => false,
                'sorting_enable' => true,
                'search_enable' => true,
                'pagination_enable' => true,
                'info_enable' => true,
                'horizontal_scroll_enable' => false,
                'original_wordpress_url' => 'https://example.test/tables/601/',
                'id' => $id,
                'description' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'About & the fees.']]]],
                'table_data' => [['cells' => ['a', 'b']], ['cells' => ['c', null]]],
                'last_row_footers' => true,
                'striped' => true,
                'row_hover_highlighting' => false,
                'pagination_count' => 25,
                'pagination_change_enable' => true,
                'column_filter_enable' => false,
                'responsive_stack_enable' => false,
                'mapped' => true,
                'updated_at' => (new DateTime('2025-04-02 10:00:00'))->getTimestamp(),
            ])
            ->and($run['mapper']->mapped)->toBe(['601'])
            ->and($run['output'])->toBe(
                "Processing table 'default_tablepress-12' with content length: 20".PHP_EOL
                ."  - Saving entry with slug 'default_tablepress-12' for collection 'tables'".PHP_EOL
            )
            ->and(ProcessingContext::getWordPressPageLink())->toBe('https://example.test/tables/601/')
            ->and(\array_keys($run['result']))->toBe([601])
            ->and(\array_diff_key(\is_array($run['result'][601] ?? null) ? $run['result'][601] : [], ['entry_object' => true]))->toBe([
                'entry' => $id,
                'parent' => '0',
                'menu_order' => 0,
                'title' => 'Fees & Costs',
            ]);
    });

    it('names an untitled table, and stores no description, rows or options it was not given', function (): void {
        $run = tablePressRun(tablePressExport([['id' => '602', 'title' => '', 'content' => '', 'meta' => tablePressMeta()]]));
        $data = tablePressData(tablePressEntry('default_tablepress-12'));

        expect($data['title'] ?? null)->toBe('Untitled Table')
            ->and($data['caption'] ?? null)->toBe('Untitled Table')
            ->and($data)->not->toHaveKeys(['description', 'table_data', 'last_row_footers', 'striped'])
            ->and($run['result'])->toHaveKey('602.title', 'Untitled Table')
            ->and($run['output'])->toContain('with content length: 0');
    });

    it('stores no description for a blank excerpt, and an empty paragraph for an empty one', function (): void {
        tablePressRun(tablePressExport([
            ['excerpt' => " \n ", 'meta' => tablePressMeta()],
            ['id' => '603', 'excerpt' => '<p></p>', 'meta' => tablePressMeta(['_tablepress_export_table_id' => '13'])],
        ]));

        expect(tablePressData(tablePressEntry('default_tablepress-12')))->not->toHaveKey('description')
            ->and(tablePressEntry('default_tablepress-13')->get('description'))->toBe([['type' => 'paragraph']]);
    });

    it('decodes a double-encoded title', function (): void {
        tablePressRun(tablePressExport([['title' => 'Say &AMP;quot;hi&AMP;quot;', 'meta' => tablePressMeta()]]));

        expect(tablePressEntry('default_tablepress-12')->get('title'))->toBe('Say "hi"');
    });

    it('ignores options that are not valid JSON', function (): void {
        tablePressRun(tablePressExport([['meta' => tablePressMeta(['_tablepress_table_options' => 'not json'])]]));

        expect(tablePressData(tablePressEntry('default_tablepress-12')))->not->toHaveKeys(['last_row_footers', 'striped', 'column_filter_enable']);
    });

    it('reads only the first export id, and the options wherever they sit among the postmeta', function (): void {
        tablePressRun(tablePressExport([['meta' => [
            '_tablepress_table_options' => '{"table_foot":true}',
            '_tablepress_export_table_id' => '12',
            'later' => 'x',
        ]], ['id' => '603', 'meta' => [
            '_tablepress_export_table_id' => '13',
        ]]]));

        expect(tablePressEntry('default_tablepress-12')->get('last_row_footers'))->toBeTrue()
            ->and(\array_keys(tablePressEntries()))->toBe(['default_tablepress-12', 'default_tablepress-13']);
    });
});

describe('skipping a table', function (): void {
    it('skips a table with no export id, with a warning naming it', function (): void {
        $warnings = tablePressWarnings();

        $run = tablePressRun(tablePressExport([['id' => '604', 'title' => '', 'meta' => ['other' => 'x']], ['id' => '605', 'meta' => tablePressMeta()]]));

        expect(\array_keys(tablePressEntries()))->toBe(['default_tablepress-12'])
            ->and(tablePressMessages($warnings))->toBe(["TablePressEntryCreator::createEntries: Missing _tablepress_export_table_id for table ID '604', skipping. (from entry: https://example.test/tables/604/)"])
            ->and($warnings[0][1] ?? null)->toMatchArray(['site' => 'default', 'title' => 'Untitled Table'])
            ->and(\array_keys($run['result']))->toBe([605]);
    });

    it('skips a trashed table handed to it directly, without streaming', function (): void {
        $export = tablePressExport([['id' => '606', 'status' => 'trash', 'meta' => tablePressMeta()], ['id' => '607', 'meta' => tablePressMeta(['_tablepress_export_table_id' => '14'])]]);
        $source = new XmlParser;
        $source->parse($export);
        $parser = new XmlParser;
        $parser->parse($export);
        $parser->setItems(\iterator_to_array((new XmlStreamParser($export))->streamAllItems(), false));

        $run = tablePressRun($export, parser: $parser);

        expect(\array_keys(tablePressEntries()))->toBe(['default_tablepress-14'])
            ->and($run['output'])->not->toContain('Progress:');
    });
});

describe('reading the rows', function (): void {
    it('reports and skips a row that is not a list of cells', function (): void {
        $warnings = tablePressWarnings();

        tablePressRun(tablePressExport([['id' => '608', 'content' => '[["a"],"loose",["b"]]', 'meta' => tablePressMeta()]]));

        expect(tablePressEntry('default_tablepress-12')->get('table_data'))->toBe([['cells' => ['a']], ['cells' => ['b']]])
            ->and(tablePressMessages($warnings))->toBe(['TablePressEntryCreator::createEntries: Invalid row data, expected array of cells (from entry: https://example.test/tables/608/)'])
            ->and($warnings[0][1] ?? null)->toMatchArray(['row' => 'loose', 'table_id' => '608', 'slug' => 'default_tablepress-12']);
    });

    it('keys each row\'s cells as a list', function (): void {
        tablePressRun(tablePressExport([['content' => '[{"x":"a","y":"b"}]', 'meta' => tablePressMeta()]]));

        expect(tablePressEntry('default_tablepress-12')->get('table_data'))->toBe([['cells' => ['a', 'b']]]);
    });

    it('reports a payload that is not JSON, with the parse error', function (): void {
        $warnings = tablePressWarnings();

        tablePressRun(tablePressExport([['id' => '609', 'content' => '[["a"', 'meta' => tablePressMeta()]]));

        expect(tablePressData(tablePressEntry('default_tablepress-12')))->not->toHaveKey('table_data')
            ->and(tablePressMessages($warnings))->toBe(["TablePressEntryCreator::createEntries: Invalid table data JSON for table 'default_tablepress-12' (ID: 609): Syntax error (from entry: https://example.test/tables/609/)"])
            ->and($warnings[0][1] ?? null)->toMatchArray(['content' => '[["a"...', 'decoded' => null, 'json_error' => 'Syntax error', 'json_error_offset' => null]);
    });

    it('reports an empty table without a parse error', function (): void {
        $warnings = tablePressWarnings();

        tablePressRun(tablePressExport([['id' => '610', 'content' => '[]', 'meta' => tablePressMeta()]]));

        expect(tablePressMessages($warnings))->toBe(["TablePressEntryCreator::createEntries: Invalid table data JSON for table 'default_tablepress-12' (ID: 610) (from entry: https://example.test/tables/610/)"])
            ->and($warnings[0][1] ?? null)->toMatchArray(['json_error' => null, 'json_error_offset' => null]);
    });

    it('says when it repaired unescaped attribute quotes', function (): void {
        $run = tablePressRun(tablePressExport([['id' => '611', 'content' => '[["<a href="x">link</a>"]]', 'meta' => tablePressMeta()]]));

        expect($run['output'])->toContain("Repaired unescaped HTML-attribute quotes in table 'default_tablepress-12' (ID: 611)".PHP_EOL)
            ->and(tablePressEntry('default_tablepress-12')->get('table_data'))->toBe([['cells' => ['<a href="x">link</a>']]]);
    });

    it('adds an empty header row when the table has none', function (): void {
        tablePressRun(tablePressExport([['meta' => tablePressMeta(['_tablepress_table_options' => '{"table_head":false}'])]]));

        expect(tablePressEntry('default_tablepress-12')->get('table_data'))->toBe([
            ['cells' => [null, null]],
            ['cells' => ['a', 'b']],
            ['cells' => ['c', null]],
        ]);
    });
});

describe('hiding rows and columns', function (): void {
    it('drops the hidden rows and columns, and logs what it filtered', function (): void {
        $infos = new ArrayObject;
        Log::shouldReceive('info')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($infos): void {
            $infos->append([$message, $context]);
        });

        tablePressRun(tablePressExport([['id' => '612', 'content' => '[["a","b","c"],["d","e","f"],["g","h","i"]]', 'meta' => tablePressMeta([
            '_tablepress_table_visibility' => '{"rows":[1,0,1],"columns":[1,1,0]}',
        ])]]));

        expect(tablePressEntry('default_tablepress-12')->get('table_data'))->toBe([['cells' => ['a', 'b']], ['cells' => ['g', 'h']]])
            ->and($infos->getArrayCopy())->toBe([[
                "TablePressEntryCreator::filterHiddenRowsAndColumns: Filtered table 'default_tablepress-12' (ID: 612)",
                ['original_dimensions' => '3x3', 'filtered_dimensions' => '2x2', 'hidden_rows' => 1, 'hidden_columns' => 1],
            ]]);
    });

    it('keeps an added header row and shifts the visibility past it', function (): void {
        Log::shouldReceive('info')->andReturnNull();

        tablePressRun(tablePressExport([['content' => '[["a","b"],["c","d"],["e","f"]]', 'meta' => tablePressMeta([
            '_tablepress_table_options' => '{"table_head":false}',
            '_tablepress_table_visibility' => '{"rows":[0,1,1],"columns":[0,1]}',
        ])]]));

        expect(tablePressEntry('default_tablepress-12')->get('table_data'))->toBe([['cells' => [null]], ['cells' => ['d']], ['cells' => ['f']]]);
    });

    it('logs nothing when nothing is hidden, and treats a missing index as hidden', function (): void {
        $infos = new ArrayObject;
        Log::shouldReceive('info')->andReturnUsing(static function (mixed $message) use ($infos): void {
            $infos->append($message);
        });

        tablePressRun(tablePressExport([['content' => '[["a","b"],["c","d"]]', 'meta' => tablePressMeta([
            '_tablepress_table_visibility' => '{"rows":[1,1],"columns":[1,1]}',
        ])], ['id' => '613', 'content' => '[["a","b"],["c","d"]]', 'meta' => tablePressMeta([
            '_tablepress_export_table_id' => '13',
            '_tablepress_table_visibility' => '{"rows":[1],"columns":[1]}',
        ])]]));

        expect(tablePressEntry('default_tablepress-12')->get('table_data'))->toBe([['cells' => ['a', 'b']], ['cells' => ['c', 'd']]])
            ->and(tablePressEntry('default_tablepress-13')->get('table_data'))->toBe([['cells' => ['a']]])
            ->and($infos->getArrayCopy())->toHaveCount(1);
    });

    it('hides everything when every row is hidden', function (): void {
        $infos = new ArrayObject;
        Log::shouldReceive('info')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($infos): void {
            $infos->append($context);
        });

        tablePressRun(tablePressExport([['content' => '[["a","b"]]', 'meta' => tablePressMeta([
            '_tablepress_table_visibility' => '{"rows":[0],"columns":[1,1]}',
        ])]]));

        expect(tablePressData(tablePressEntry('default_tablepress-12')))->not->toHaveKey('table_data')
            ->and($infos->getArrayCopy())->toBe([['original_dimensions' => '1x2', 'filtered_dimensions' => '0x0', 'hidden_rows' => 1, 'hidden_columns' => 2]]);
    });

    it('leaves the table whole for visibility values other than 0 and 1, with a warning', function (): void {
        $warnings = tablePressWarnings();

        tablePressRun(tablePressExport([['id' => '614', 'meta' => tablePressMeta([
            '_tablepress_table_visibility' => '{"rows":[1,2],"columns":[1,1]}',
        ])], ['id' => '615', 'meta' => tablePressMeta([
            '_tablepress_export_table_id' => '13',
            '_tablepress_table_visibility' => '{"rows":[1,1],"columns":[1,5]}',
        ])]]));

        expect(tablePressEntry('default_tablepress-12')->get('table_data'))->toBe([['cells' => ['a', 'b']], ['cells' => ['c', null]]])
            ->and(tablePressEntry('default_tablepress-13')->get('table_data'))->toBe([['cells' => ['a', 'b']], ['cells' => ['c', null]]])
            ->and(tablePressMessages($warnings))->toBe([
                "TablePressEntryCreator::filterHiddenRowsAndColumns: Invalid visibility values for table 'default_tablepress-12' (ID: 614) (from entry: https://example.test/tables/614/)",
                "TablePressEntryCreator::filterHiddenRowsAndColumns: Invalid visibility values for table 'default_tablepress-13' (ID: 615) (from entry: https://example.test/tables/615/)",
            ])
            ->and($warnings[0][1] ?? null)->toMatchArray(['rows_visibility' => [1, 2], 'columns_visibility' => [1, 1], 'expected_values' => [0, 1]]);
    });

    it('ignores visibility that is not JSON, or lacks rows or columns', function (string $visibility): void {
        tablePressRun(tablePressExport([['meta' => tablePressMeta(['_tablepress_table_visibility' => $visibility])]]));

        expect(tablePressEntry('default_tablepress-12')->get('table_data'))->toBe([['cells' => ['a', 'b']], ['cells' => ['c', null]]]);
    })->with([
        'not JSON' => ['hidden'],
        'no columns' => ['{"rows":[0,0]}'],
        'columns not a list' => ['{"rows":[0,0],"columns":"none"}'],
        'rows not a list' => ['{"rows":"none","columns":[0,0]}'],
    ]);
});

describe('saving a table', function (): void {
    it('publishes a table by its status when the blueprint carries one', function (string $status, bool $published): void {
        BlueprintFacade::find('collections.tables.table')?->delete();
        tablePressCollection(withStatus: true);

        tablePressRun(tablePressExport([['status' => $status, 'meta' => tablePressMeta()]]));

        expect(tablePressEntry('default_tablepress-12')->published())->toBe($published);
    })->with([
        'published' => ['publish', true],
        'private' => ['private', true],
        'draft' => ['draft', false],
        'pending' => ['pending', false],
    ]);

    it('skips a table that already exists when told to', function (): void {
        $export = tablePressExport([['meta' => tablePressMeta()]]);
        tablePressRun($export);
        $first = tablePressEntry('default_tablepress-12')->id();

        $creator = new TablePressEntryCreator;
        $creator->setOverwriteBehavior('Skip matching files');
        $run = tablePressRun($export, $creator);

        expect(tablePressEntry('default_tablepress-12')->id())->toBe($first)
            ->and($run['output'])->toContain("Skipped entry 'default_tablepress-12' as it already exists.".PHP_EOL)
            ->not->toContain('Saving entry')
            ->and($run['result'])->toBe([]);
    });

    it('replaces a table that already exists by slug', function (): void {
        $export = tablePressExport([['title' => 'First', 'meta' => tablePressMeta()]]);
        tablePressRun($export);
        $first = tablePressEntry('default_tablepress-12')->id();

        tablePressRun(tablePressExport([['title' => 'Second', 'meta' => tablePressMeta()]]));
        $entries = tablePressEntries();

        expect(\array_keys($entries))->toBe(['default_tablepress-12'])
            ->and($entries['default_tablepress-12']->get('title'))->toBe('Second')
            ->and($entries['default_tablepress-12']->id())->not->toBe($first)
            ->and(EntryFacade::find(\is_string($first) ? $first : ''))->toBeNull();
    });

    it('creates an additional table beside one that already exists when told to', function (): void {
        $export = tablePressExport([['meta' => tablePressMeta()]]);
        tablePressRun($export);
        $saved = [];
        $outputs = [];

        foreach ([1, 2] as $copy) {
            $creator = new TablePressEntryCreator;
            $creator->setOverwriteBehavior('Create an additional file');
            $outputs[] = tablePressRun($export, $creator)['output'];
        }

        foreach (EntryFacade::all() as $entry) {
            if ($entry instanceof Entry && $entry->collectionHandle() === 'tables') {
                $saved[] = [tablePressSlug($entry), $entry->get('id') === $entry->id()];
            }
        }

        // ---
        // The copies take the slug `default_tablepress-12.1`, which Statamic stores
        // without its dot, so both land on table 121's slug, and the id the data
        // carries is not the entry's (#265).
        // ---
        expect($saved)->toBe([
            ['default_tablepress-12', true],
            ['default_tablepress-121', false],
            ['default_tablepress-121', false],
        ])
            ->and($outputs[0])->toContain("Saving entry with slug 'default_tablepress-12' for collection 'tables'");
    });

    it('deletes a file left under the old slug format', function (): void {
        $export = tablePressExport([['id' => '601', 'meta' => tablePressMeta()]]);
        $stale = ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-12-601');
        $kept = ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-12-602');
        @\mkdir(\dirname($stale), 0777, true);
        \file_put_contents($stale, "---\ntitle: stale\n---\n");
        \file_put_contents($kept, "---\ntitle: other\n---\n");

        try {
            tablePressRun($export);

            expect(\file_exists($stale))->toBeFalse()
                ->and(\file_exists($kept))->toBeTrue();
        } finally {
            @\unlink($stale);
            @\unlink($kept);
        }
    });
});

describe('dating a table', function (): void {
    beforeEach(function (): void {
        tablePressCollection(dated: true);
    });

    it('dates a table in a dated collection from its post date', function (): void {
        tablePressRun(tablePressExport([['date' => '2024-02-03 04:05:06', 'meta' => tablePressMeta()]]));

        expect(tablePressDate(tablePressEntry('default_tablepress-12'))?->format('Y-m-d H:i:s'))->toBe('2024-02-03 04:05:06');
    });

    it('dates a table now when its post date cannot be read, with a warning', function (): void {
        $warnings = tablePressWarnings();
        $before = new DateTime;

        tablePressRun(tablePressExport([['date' => 'not a date', 'meta' => tablePressMeta()]]));
        $date = tablePressDate(tablePressEntry('default_tablepress-12'));

        expect($date?->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp() - 1)
            ->and(\implode(PHP_EOL, \array_filter(tablePressMessages($warnings), \is_string(...))))->toContain('TablePressEntryCreator::saveEntry: Failed to parse post date string')
            ->and($warnings[0][1] ?? null)->toMatchArray(['collection_handle' => 'tables', 'post_date_str' => 'not a date']);
    });
});

describe('saving with the creator\'s own rules', function (): void {
    it('refuses data without a string id, with a warning', function (array $data): void {
        $warnings = tablePressWarnings();
        $method = new ReflectionMethod(TablePressEntryCreator::class, 'saveEntry');

        $entry = $method->invoke(new TablePressEntryCreator, 'tables', 'default', 'slug', $data, new BufferedOutput);

        expect($entry)->toBeNull()
            ->and(tablePressMessages($warnings))->toBe(['TablePressEntryCreator::saveEntry: Missing or invalid entry ID in data payload.'])
            ->and(tablePressEntries())->toBe([]);
    })->with([
        'no id' => [['title' => 'x']],
        'an integer id' => [['id' => 5]],
    ]);

    it('publishes as told, and leaves a scheduled table unpublished only when told to', function (?bool $published, bool $expected): void {
        $method = new ReflectionMethod(TablePressEntryCreator::class, 'saveEntry');

        $entry = $method->invoke(new TablePressEntryCreator, 'tables', 'default', 'told', ['id' => 'told-1', 'status_and_visibility' => 'scheduled'], new BufferedOutput, null, $published);

        expect($entry instanceof Entry ? $entry->published() : null)->toBe($expected);
    })->with([
        'told nothing, scheduled' => [null, true],
        'told unpublished' => [false, false],
    ]);

    it('warns and sets no date for a dated entry in an undated collection', function (): void {
        $warnings = tablePressWarnings();
        $method = new ReflectionMethod(TablePressEntryCreator::class, 'saveEntry');

        $entry = $method->invoke(new TablePressEntryCreator, 'tables', 'default', 'undated', ['id' => 'undated-1'], new BufferedOutput, new DateTime('2030-01-01'));

        expect(tablePressMessages($warnings))->toBe(['TablePressEntryCreator::saveEntry: Skipping date setting - collection not dated'])
            ->and($warnings[0][1] ?? null)->toMatchArray(['collection_handle' => 'tables', 'collection_dated' => false])
            ->and($entry instanceof Entry ? $entry->hasDate() : null)->toBeFalse();
    });
});

describe('the content type\'s other answers', function (): void {
    it('has no people, categories or tags, and nothing to validate', function (): void {
        $creator = new TablePressEntryCreator;
        $output = new BufferedOutput;
        $creator->validateTaxonomies('tables', 'default', $output);

        expect($creator->hasShowPeople())->toBeFalse()
            ->and($creator->getPostCategories())->toBe([])
            ->and($creator->getPostTags())->toBe([])
            ->and($output->fetch())->toBe('');
    });
});
