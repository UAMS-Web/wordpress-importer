<?php

declare(strict_types=1);

/**
 * Tests that `ContentProcessor::linkTableEntries()` points each table set at the
 * `tables` entry for the next table id in content order: found by slug, by an id
 * that carries the WordPress post id, or by reading the entry's file; with the
 * heading and description toggles taken from the TablePress options, and a
 * warning for each id or set it cannot pair.
 *
 * @command  php vendor/bin/pest tests/Integration/ContentProcessorLinkTableEntriesTest.php
 */

use App\Services\BardContentSanitizer;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

// The constructor makes the host app's sanitizer; outside the host app, load the
// pass-through stand-in the package keeps for analysis
if (! class_exists(BardContentSanitizer::class)) {
    require_once __DIR__.'/../Support/IdeStubs/App/Services/BardContentSanitizer.php';
}

/**
 * A TablePress item as the WXR carries it.
 *
 * @param  ?string  $tableId  The `_tablepress_export_table_id` value, or null for none
 * @param  ?string  $options  The `_tablepress_table_options` JSON, or null for none
 */
function linkTablesItem(?string $tableId, string $postId, ?string $options = null): SimpleXMLElement
{
    $meta = '';

    if ($tableId !== null) {
        $meta .= "<wp:postmeta><wp:meta_key>_tablepress_export_table_id</wp:meta_key><wp:meta_value>{$tableId}</wp:meta_value></wp:postmeta>";
    }

    if ($options !== null) {
        $meta .= "<wp:postmeta><wp:meta_key>_tablepress_table_options</wp:meta_key><wp:meta_value><![CDATA[{$options}]]></wp:meta_value></wp:postmeta>";
    }

    $xml = "<rss xmlns:wp=\"http://wordpress.org/export/1.2/\"><channel><item><wp:post_id>{$postId}</wp:post_id>{$meta}</item></channel></rss>";

    return (new SimpleXMLElement($xml))->channel->item;
}

/**
 * Save a `tables` entry with the given slug and id.
 */
function linkTablesEntry(string $slug, string $id): void
{
    $entry = EntryFacade::make();
    assert($entry instanceof Entry);
    $entry->collection('tables');
    $entry->locale('default');
    $entry->slug($slug);
    $entry->id($id);
    $entry->save();
}

/**
 * Write a table entry's file where the content root expects it, and return its path.
 */
function linkTablesFile(string $slug, string $content): string
{
    $path = ContentRoot::collectionEntry('tables', 'default', $slug);
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $content);

    return $path;
}

/**
 * A Bard table set that links to a `tables` entry.
 *
 * @param  array<string, mixed>  $values  Extra set values
 * @return array<string, mixed>
 */
function linkTablesSet(array $values = []): array
{
    return ['type' => 'set', 'attrs' => ['id' => 's', 'values' => ['type' => 'table', 'create_query' => 'collection', ...$values]]];
}

/**
 * The values of the set at the given position of linked content.
 */
/**
 * @return array<int|string, mixed>
 */
function linkTablesValues(mixed $content, int $index): array
{
    $set = is_array($content) ? ($content[$index] ?? null) : null;
    $attrs = is_array($set) ? ($set['attrs'] ?? null) : null;

    $values = is_array($attrs) ? ($attrs['values'] ?? null) : null;

    return is_array($values) ? $values : [];
}

/**
 * The value at the given path of nested arrays, or null where the path ends early.
 *
 * @param  list<int|string>  $path
 */
function linkTablesPath(mixed $value, array $path): mixed
{
    foreach ($path as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            return null;
        }

        $value = $value[$key];
    }

    return $value;
}

/**
 * Link the table sets with the given TablePress items.
 *
 * @param  list<SimpleXMLElement>  $items
 * @param  array<int|string, mixed>  $bard
 * @param  array<int|string, mixed>  $pageBuilder
 * @param  array<int|string, mixed>  $tableIds
 * @param  array<int|string, mixed>  $data
 * @return array<int|string, mixed>
 */
function linkTables(array $items, array $bard, array $pageBuilder, array $tableIds, array $data = [], string $collection = 'posts'): array
{
    $parser = new XmlParser;
    $parser->setItems($items);

    return (new ContentProcessor)->linkTableEntries($bard, $pageBuilder, 'default', $tableIds, $data, $collection, $parser);
}

/**
 * Record each warning logged during a test.
 *
 * @return ArrayObject<int, array{message: string, context: array<int|string, mixed>}> The warnings, in order
 */
function linkTablesWarnings(): ArrayObject
{
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            $warnings->append(['message' => $message, 'context' => $context]);
        }
    );

    return $warnings;
}

beforeEach(function (): void {
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    // Point the entries store at a directory of this test's own, so table files
    // written here never reach the package's fixtures
    $store = Stache::store('entries');
    assert(is_object($store) && method_exists($store, 'directory'));
    $this->entriesDirectory = $store->directory();
    $this->tablesRoot = sys_get_temp_dir().'/link-tables-'.bin2hex(random_bytes(6));
    $store->directory($this->tablesRoot);

    $collection = CollectionFacade::make('tables');
    $collection->sites(['default']);
    $collection->save();
});

afterEach(function (): void {
    $store = Stache::store('entries');
    assert(is_object($store) && method_exists($store, 'directory'));
    $store->directory($this->entriesDirectory);

    // Remove the table files this test wrote
    if (is_dir($this->tablesRoot)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->tablesRoot, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) {
            assert($file instanceof SplFileInfo);
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }
        rmdir($this->tablesRoot);
    }
});

it('links a table set to the entry whose slug names its table id', function (): void {
    linkTablesEntry('default_tablepress-3', 'tables-3');
    $warnings = linkTablesWarnings();

    $result = linkTables([linkTablesItem('3', '30', '{"print_name":true,"print_description":true}')], [linkTablesSet()], [], [3]);

    expect(linkTablesValues($result['bardContent'], 0))->toBe([
        'type' => 'table',
        'create_query' => 'collection',
        'table_entry' => 'tables-3',
        'heading_query' => true,
        'description_query' => true,
        'heading_level' => 'h2',
    ])
        ->and($result['pageBuilderContent'])->toBe([])
        ->and($warnings->getArrayCopy())->toBe([]);
});

it('falls back to an entry whose id carries the table id and post id', function (): void {
    linkTablesEntry('renamed', 'default_tablepress-4-40-abc');
    linkTablesEntry('other', 'default_tablepress-4-41-abc');

    $result = linkTables([linkTablesItem('4', '40')], [linkTablesSet()], [], ['4']);

    expect(linkTablesValues($result['bardContent'], 0))->toBe([
        'type' => 'table',
        'create_query' => 'collection',
        'table_entry' => 'default_tablepress-4-40-abc',
        'heading_query' => false,
        'description_query' => false,
    ]);
});

it('reads the entry id from the table file for a landing page, and gives it an h3 heading', function (): void {
    // A landing page skips the query, so even an entry that would match is not used
    linkTablesEntry('default_tablepress-5', 'from-query');
    linkTablesFile('default_tablepress-5', "---\ntitle: T\nid:   from-file  \n---\n");

    $result = linkTables([linkTablesItem('5', '50', '{"print_name":true}')], [], [linkTablesSet(['heading_level' => 'h6'])], ['5'], ['blueprint' => 'landing_page'], 'pages');

    expect(linkTablesValues($result['pageBuilderContent'], 0))->toBe([
        'type' => 'table',
        'create_query' => 'collection',
        'heading_level' => 'h3',
        'table_entry' => 'from-file',
        'heading_query' => true,
        'description_query' => false,
    ]);
});

it('gives a landing page outside pages an h2 heading, and a page that is not a landing page one too', function (array $data, string $collection, string $level): void {
    linkTablesEntry('default_tablepress-6', 'tables-6');
    linkTablesFile('default_tablepress-6', "id: tables-6\n");

    $result = linkTables([linkTablesItem('6', '60', '{"print_name":true}')], [linkTablesSet()], [], ['6'], $data, $collection);

    expect(linkTablesValues($result['bardContent'], 0)['heading_level'] ?? null)->toBe($level);
})->with([
    'a landing page in reusable content' => [['blueprint' => 'landing_page'], 'reusable_content', 'h2'],
    'a page without the landing blueprint' => [['blueprint' => 'basic_page'], 'pages', 'h2'],
]);

it('warns when the table file is missing or has no id line', function (?string $file, string $warning): void {
    $path = $file !== null
        ? linkTablesFile('default_tablepress-7', $file)
        : ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-7');
    $warnings = linkTablesWarnings();

    // A landing page reads the file directly; the Stache would index the file as an
    // entry of its own and the query would find it first
    $result = linkTables([linkTablesItem('7', '70')], [linkTablesSet()], [], ['7'], ['blueprint' => 'landing_page']);

    expect(linkTablesValues($result['bardContent'], 0))->toHaveKey('table_entry', null)
        ->and($warnings->getArrayCopy())->toBe([[
            'message' => "ContentProcessor::linkTableEntries: {$warning}",
            'context' => ['site' => 'default', 'tableId' => '7', 'postId' => '70', 'path' => $path],
        ]]);
})->with([
    'no file' => [null, 'Table entry file not found'],
    'a file without an id line' => ["title: T\nlink_id: 7\n", 'Could not extract entry ID from file'],
]);

it('warns when a table id has no TablePress item, and links nothing', function (): void {
    $warnings = linkTablesWarnings();

    $result = linkTables([linkTablesItem(null, '80'), linkTablesItem('', '81'), linkTablesItem('9', '')], [linkTablesSet()], [], ['8']);

    expect(linkTablesValues($result['bardContent'], 0))->toHaveKey('table_entry', null)
        ->and($warnings->getArrayCopy())->toBe([[
            'message' => "ContentProcessor::linkTableEntries: No postId found for table ID '8'",
            'context' => ['site' => 'default', 'tableId' => '8'],
        ]]);
});

it('warns for a table item with an empty post id the same way', function (): void {
    $warnings = linkTablesWarnings();

    linkTables([linkTablesItem('9', '')], [linkTablesSet()], [], ['9']);

    expect(array_column($warnings->getArrayCopy(), 'message'))->toBe(["ContentProcessor::linkTableEntries: No postId found for table ID '9'"]);
});

it('warns for a table set with no table id left, and for table ids no set used', function (): void {
    linkTablesEntry('default_tablepress-1', 'tables-1');
    $warnings = linkTablesWarnings();

    $extra = linkTablesSet(['marker' => 'second']);
    $result = linkTables([linkTablesItem('1', '10')], [linkTablesSet(), $extra], [], ['1']);
    $leftover = linkTables([linkTablesItem('1', '10')], [linkTablesSet()], [], ['1', '2', '3']);

    expect(linkTablesValues($result['bardContent'], 1))->toBe(linkTablesValues([$extra], 0))
        ->and($warnings->getArrayCopy())->toBe([
            ['message' => 'ContentProcessor::linkTableEntries: No more table IDs available to link table set', 'context' => ['site' => 'default', 'set' => $extra]],
            ['message' => 'ContentProcessor::linkTableEntries: Table IDs not linked to sets', 'context' => ['site' => 'default', 'unlinkedTableIds' => ['2', '3']]],
        ])
        ->and(linkTablesValues($leftover['bardContent'], 0)['table_entry'] ?? null)->toBe('tables-1');
});

it('reads a table id that is not a scalar as no table id', function (): void {
    $warnings = linkTablesWarnings();

    linkTables([], [linkTablesSet()], [], [['1']]);

    expect(array_column($warnings->getArrayCopy(), 'message'))->toBe(["ContentProcessor::linkTableEntries: No postId found for table ID ''"]);
});

it('counts a page builder table block against the table ids, and links Bard and page builder sets independently', function (): void {
    linkTablesEntry('default_tablepress-1', 'tables-1');
    linkTablesEntry('default_tablepress-2', 'tables-2');
    $warnings = linkTablesWarnings();

    $result = linkTables(
        [linkTablesItem('1', '10'), linkTablesItem('2', '20')],
        [linkTablesSet()],
        [['type' => 'uamsweb_block_table', 'table_id' => '1'], linkTablesSet()],
        ['1', '2']
    );

    // The page builder's table block uses id 1, so its set takes id 2; Bard starts again at id 1
    expect(linkTablesValues($result['bardContent'], 0)['table_entry'] ?? null)->toBe('tables-1')
        ->and(linkTablesValues($result['pageBuilderContent'], 1)['table_entry'] ?? null)->toBe('tables-2')
        ->and(linkTablesPath($result, ['pageBuilderContent', 0]))->toBe(['type' => 'uamsweb_block_table', 'table_id' => '1'])
        ->and($warnings->getArrayCopy())->toBe([]);
});

it('counts a page builder table block only while table ids remain', function (): void {
    $warnings = linkTablesWarnings();

    linkTables([], [], [['type' => 'uamsweb_block_table'], ['type' => 'uamsweb_block_table']], ['1']);

    expect($warnings->getArrayCopy())->toBe([]);
});

it('links table sets nested in a block body, and leaves other sets and values alone', function (): void {
    linkTablesEntry('default_tablepress-1', 'tables-1');
    linkTablesEntry('default_tablepress-2', 'tables-2');

    $content = [
        'loose',
        ['type' => 'paragraph'],
        ['type' => 'set', 'attrs' => ['values' => ['type' => 'table', 'create_query' => 'manual']]],
        ['type' => 'set', 'attrs' => ['values' => ['type' => 'image']]],
        ['type' => 'set', 'attrs' => 'not attrs'],
        ['type' => 'set', 'attrs' => ['values' => 'not values']],
        ['type' => 5],
        ['type' => 'uamsweb_block_content', 'body' => [linkTablesSet()]],
        ['type' => 'uamsweb_block_content', 'body' => 'not a body'],
        linkTablesSet(),
    ];

    $result = linkTables([linkTablesItem('1', '10'), linkTablesItem('2', '20')], $content, [], ['1', '2']);
    $linked = $result['bardContent'];
    assert(is_array($linked));

    expect(array_slice($linked, 0, 7))->toBe(array_slice($content, 0, 7))
        ->and(linkTablesValues(linkTablesPath($linked, [7, 'body']), 0)['table_entry'] ?? null)->toBe('tables-1')
        ->and($linked[8] ?? null)->toBe($content[8])
        ->and(linkTablesValues($linked, 9)['table_entry'] ?? null)->toBe('tables-2');
});

it('reads table options that are not an object as no options', function (string $options): void {
    linkTablesEntry('default_tablepress-1', 'tables-1');

    $result = linkTables([linkTablesItem('1', '10', $options)], [linkTablesSet()], [], ['1']);

    expect(linkTablesValues($result['bardContent'], 0))->toMatchArray(['heading_query' => false, 'description_query' => false]);
})->with([
    'invalid JSON' => ['{nope'],
    'a scalar' => ['"print_name"'],
    'options set to something other than true' => ['{"print_name":1,"print_description":"yes"}'],
]);

it('falls back to the table file when no entry matches by slug or id', function (): void {
    $path = ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-8');
    $warnings = linkTablesWarnings();

    // Not a landing page, so both queries run first and find nothing
    $result = linkTables([linkTablesItem('8', '80')], [linkTablesSet()], [], ['8']);

    expect(linkTablesValues($result['bardContent'], 0))->toHaveKey('table_entry', null)
        ->and($warnings->getArrayCopy())->toBe([[
            'message' => 'ContentProcessor::linkTableEntries: Table entry file not found',
            'context' => ['site' => 'default', 'tableId' => '8', 'postId' => '80', 'path' => $path],
        ]]);
});

/**
 * Save an entry with the given slug and id in the given collection and site.
 */
function linkTablesEntryIn(string $collection, string $site, string $slug, string $id): void
{
    $entry = EntryFacade::make();
    assert($entry instanceof Entry);
    $entry->collection($collection);
    $entry->locale($site);
    $entry->slug($slug);
    $entry->id($id);
    $entry->save();
}

it('looks a table up only among the site\'s tables', function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
        'other' => ['name' => 'Other', 'locale' => 'en_US', 'url' => '/other/'],
    ]);
    foreach (['tables', 'pages'] as $handle) {
        $collection = CollectionFacade::make($handle);
        $collection->sites(['default', 'other']);
        $collection->save();
    }

    // Decoys that match the slug or the id pattern but sit in another site or collection
    linkTablesEntryIn('tables', 'other', 'default_tablepress-3', 'other-site-slug');
    linkTablesEntryIn('pages', 'default', 'default_tablepress-3', 'other-collection-slug');
    linkTablesEntryIn('tables', 'other', 'x', 'default_tablepress-3-30-other-site');
    linkTablesEntryIn('pages', 'default', 'y', 'default_tablepress-3-30-other-collection');
    linkTablesEntryIn('tables', 'default', 'z', 'unrelated-table');
    linkTablesEntryIn('tables', 'default', 'w', 'default_tablepress-3-30-match');

    $result = linkTables([linkTablesItem('3', '30')], [linkTablesSet()], [], ['3']);

    expect(linkTablesValues($result['bardContent'], 0)['table_entry'] ?? null)->toBe('default_tablepress-3-30-match');
});

it('maps no table for an item without a table id, even when a table id is empty', function (): void {
    $warnings = linkTablesWarnings();

    linkTables([linkTablesItem(null, '80'), linkTablesItem('', '81')], [linkTablesSet()], [], [['not a scalar']]);

    expect(array_column($warnings->getArrayCopy(), 'message'))->toBe(["ContentProcessor::linkTableEntries: No postId found for table ID ''"]);
});

it('names an integer table id as a string in its warning', function (): void {
    $warnings = linkTablesWarnings();

    linkTables([], [linkTablesSet()], [], [3]);

    expect($warnings[0]['context'] ?? null)->toBe(['site' => 'default', 'tableId' => '3']);
});

it('leaves a node shaped like a table set alone unless its type is set', function (): void {
    $warnings = linkTablesWarnings();
    $node = ['type' => 'paragraph', 'attrs' => ['values' => ['type' => 'table', 'create_query' => 'collection']]];

    $result = linkTables([], [$node], [], ['1']);

    expect($result['bardContent'])->toBe([$node])
        ->and(array_column($warnings->getArrayCopy(), 'message'))->toBe(['ContentProcessor::linkTableEntries: Table IDs not linked to sets']);
});

it('warns for each table set left over once the table ids run out', function (): void {
    linkTablesEntry('default_tablepress-1', 'tables-1');
    $warnings = linkTablesWarnings();

    linkTables([linkTablesItem('1', '10')], [linkTablesSet(), linkTablesSet(['n' => 2]), linkTablesSet(['n' => 3])], [], ['1']);

    expect(array_column($warnings->getArrayCopy(), 'message'))->toBe([
        'ContentProcessor::linkTableEntries: No more table IDs available to link table set',
        'ContentProcessor::linkTableEntries: No more table IDs available to link table set',
    ]);
});

it('does not fall back to an entry in another collection whose id matches the pattern', function (): void {
    $pages = CollectionFacade::make('pages');
    $pages->sites(['default']);
    $pages->save();
    linkTablesEntryIn('pages', 'default', 'p', 'default_tablepress-3-30-a-page');

    $result = linkTables([linkTablesItem('3', '30')], [linkTablesSet()], [], ['3']);

    expect(linkTablesValues($result['bardContent'], 0))->toHaveKey('table_entry', null);
});
