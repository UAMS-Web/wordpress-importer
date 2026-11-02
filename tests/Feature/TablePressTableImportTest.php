<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File as IlluminateFileFacade;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry as StatamicEntry;
use Statamic\Stache\Stores\Store;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\TablePressEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\EntryQueryHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\Feature\WordPressImportTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(WordPressImportTestCase::class);

/**
 * Asserts what a TablePress import produces, by running one (wordpress-importer-archive#727).
 *
 * **This file used to assert nothing.** Seven of its eleven cases read
 * `expect($creator::class)->toBe(TablePressEntryCreator::class)` under titles
 * like `exposes a createEntries method` — a tautology that never named the
 * method and stayed green with the method deleted. Two more asserted
 * `class_parents()` under unrelated titles, and the comprehensive WXR fixture
 * below was written to disk and, by its own docblock, never parsed. wordpress-importer-archive#481 had
 * already removed four cases that asserted literally nothing and recorded that
 * it was leaving these.
 *
 * The behaviour they named is only reachable through `createEntries()` —
 * `generateUniqueId()` and `generateSlugAndId()` are protected, and the JSON
 * table-data parsing and option mapping run inside the import — so covering it
 * means driving a real import, which is what these do.
 *
 * **Host-bound because the code under test is.** `createEntries()` writes real
 * entries into the `tables` collection through Statamic, so it needs the booted
 * app, its blueprints and a Stache. `tests/CLAUDE.md` puts that in
 * `tests/Feature`.
 *
 * **Not duplicated from the gate.** `mapTableOptions()` already has 37 cases
 * over real `_tablepress_table_options` payloads in
 * `tests/Unit/TablePressTableOptionsMappingTest.php` (wordpress-importer-archive#447). What is asserted
 * here is the part that band cannot see: that a mapped option survives a real
 * import onto the entry.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/TablePressTableImportTest.php
 */
const TABLEPRESS_SITE = 'default';

const TABLEPRESS_SLUG = 'default_tablepress-1';

// Guard the live `content/` submodule (wordpress-importer-archive#570), and isolate the collection this
// import writes into so repeated runs cannot accumulate entries (wordpress-importer-archive#622).
beforeEach(function (): void {
    $this->captureSubmoduleStates();
    $this->snapshotIsolatedState(['content/collections/tables']);
});

afterEach(function (): void {
    $this->restoreIsolatedState();
    $this->assertSubmodulesUnchanged();
});

/**
 * Import the comprehensive TablePress fixture and return the created entry.
 *
 * @return StatamicEntry|null The imported table entry, or null when none was created
 */
function tablePressImportFixture(): ?StatamicEntry
{
    $xmlPath = tablePressCreateTestXmlFile();

    try {
        $xmlParser = new XmlParser;
        $xmlParser->parse($xmlPath);

        $creator = new TablePressEntryCreator;
        $creator->createEntries(
            TABLEPRESS_SITE,
            'tables',
            $xmlParser,
            app(AssetImporter::class),
            new BufferedOutput,
            app(MetadataMapper::class),
            app(ContentProcessor::class)
        );
    } finally {
        if (file_exists($xmlPath)) {
            unlink($xmlPath);
        }
    }

    return tablePressFindImported();
}

/**
 * Find the entry the fixture import should have created.
 *
 * @return StatamicEntry|null The imported table entry, or null when none exists
 */
function tablePressFindImported(): ?StatamicEntry
{
    $query = EntryQueryHelper::query();
    $query->where('collection', 'tables');
    $query->where('locale', TABLEPRESS_SITE);
    $query->where('slug', TABLEPRESS_SLUG);

    $entry = $query->first();

    return $entry instanceof StatamicEntry ? $entry : null;
}

it('imports the TablePress table as an entry in the tables collection', function (): void {
    $entry = tablePressImportFixture();

    expect($entry)->not->toBeNull()
        ->and($entry?->collectionHandle())->toBe('tables')
        ->and($entry?->slug())->toBe(TABLEPRESS_SLUG);
});

it('maps the WordPress table name to the entry title', function (): void {
    // `table_name` in the fixture's content:encoded JSON is "Sample Table".
    expect(tablePressImportFixture()?->get('title'))->toBe('Sample Table');
});

it('carries the table rows through to table_data as a grid', function (): void {
    // Verified against a real import: the rows arrive wrapped as a single grid
    // row whose `cells` holds the 3x3 body, not as a bare array of rows.
    $tableData = tablePressImportFixture()?->get('table_data');

    expect($tableData)->toBeArray()->toHaveCount(1);

    // `get()` is `mixed`, so narrow before indexing rather than widening a type
    $grid = \is_array($tableData) ? \array_values($tableData) : [];
    $cells = \is_array($grid[0] ?? null) ? ($grid[0]['cells'] ?? null) : null;
    $rows = \is_array($cells) ? \array_values($cells) : [];

    expect($rows)->toHaveCount(3)
        ->and($rows[0] ?? null)->toBe(['Header 1', 'Header 2', 'Header 3'])
        ->and($rows[2] ?? null)->toBe(['Row 2 Col 1', 'Row 2 Col 2', 'Row 2 Col 3']);
});

it('maps the WordPress excerpt to the entry description', function (): void {
    // `excerpt:encoded` becomes a Bard/ProseMirror node list, not a plain string. A Bard
    // document's top level holds block nodes, so the excerpt's text sits inside a paragraph.
    $description = tablePressImportFixture()?->get('description');

    expect($description)->toBeArray()->toHaveCount(1);

    $nodes = \is_array($description) ? \array_values($description) : [];
    $first = \is_array($nodes[0] ?? null) ? $nodes[0] : [];
    $inline = \is_array($first['content'] ?? null) ? \array_values($first['content']) : [];
    $text = \is_array($inline[0] ?? null) ? $inline[0] : [];

    expect($first['type'] ?? null)->toBe('paragraph')
        ->and($inline)->toHaveCount(1)
        ->and($text['type'] ?? null)->toBe('text')
        ->and($text['text'] ?? null)
        ->toBe('This is a sample table description for testing purposes.');
});

it('does not read the fixture\'s table options, because they do not deserialize', function (): void {
    // Documents a real property of this fixture rather than asserting a mapping
    // it cannot exercise: its `_tablepress_table_options` blob declares wrong
    // string lengths throughout (`s:8:"last_editor"` for an 11-character key),
    // so `unserialize()` returns false and no option in it is ever read. The
    // three options `mapTableOptions()` sets unconditionally therefore never
    // reach the entry. Tracked separately; `mapTableOptions()` itself is covered
    // by 37 real payloads in `tests/Unit/TablePressTableOptionsMappingTest.php`.
    $entry = tablePressImportFixture();

    // Assert the entry exists first: `null?->get()` is also null, so without
    // this the case would pass when nothing imported at all — the very
    // false-green this file was rewritten to remove.
    expect($entry)->not->toBeNull()
        ->and($entry?->get('last_row_footers'))->toBeNull()
        ->and($entry?->get('striped'))->toBeNull()
        ->and($entry?->get('row_hover_highlighting'))->toBeNull();
});

/**
 * Drive `EntryCreator::importTables()` over the fixture and return its output.
 *
 * This is the wrapper the import command actually calls, and the one carrying
 * the retry loop and the mis-slug `delete()` that wordpress-importer-archive#724 re-rooted. Nothing
 * exercised it before, so neither of those was observable from any suite.
 *
 * @return string Everything the importer wrote to the console
 */
function tablePressImportViaEntryCreator(): string
{
    $xmlPath = tablePressCreateTestXmlFile();
    $output = new BufferedOutput;

    try {
        $xmlParser = new XmlParser;
        $xmlParser->parse($xmlPath);

        $entryCreator = app(EntryCreator::class);
        $entryCreator->setXmlParser($xmlParser);
        $entryCreator->setAssetImporter(app(AssetImporter::class));
        $entryCreator->importTables(TABLEPRESS_SITE, $output, 'tablepress_table');
    } finally {
        if (file_exists($xmlPath)) {
            unlink($xmlPath);
        }
    }

    return $output->fetch();
}

it('imports the table through the EntryCreator wrapper the command uses', function (): void {
    // Covers `importTables()` itself — the wrapper the import command calls,
    // which nothing exercised before. This is its direct `tablepress_table`
    // branch; the shortcode branch below carries the paths wordpress-importer-archive#724 re-rooted.
    $console = tablePressImportViaEntryCreator();

    expect(tablePressFindImported())->not->toBeNull()
        ->and($console)->toContain('Imported 1 table(s).');
});

it('finds the entry it just wrote instead of exhausting its retries', function (): void {
    // The retry loop and the mis-slug `delete()` that wordpress-importer-archive#724 re-rooted are in the
    // shortcode branch — the one a `page` import takes — not the direct
    // `tablepress_table` branch above. Reaching it means registering the table
    // id a shortcode would have contributed.
    TablePressShortcodeProcessor::clearTableIds();
    TablePressShortcodeProcessor::addTableId('1', TABLEPRESS_SITE, 'probe-page', '900001');

    // Hold the spy rather than calling back through the facade:
    // `Log::shouldNotHaveReceived()` is proxied at runtime and invisible to the
    // analyser.
    $logSpy = Log::spy();

    $xmlPath = tablePressCreateTestXmlFile();
    $output = new BufferedOutput;

    try {
        $xmlParser = new XmlParser;
        $xmlParser->parse($xmlPath);

        $entryCreator = app(EntryCreator::class);
        $entryCreator->setXmlParser($xmlParser);
        $entryCreator->setAssetImporter(app(AssetImporter::class));
        $entryCreator->importTables(TABLEPRESS_SITE, $output, 'page');
    } finally {
        TablePressShortcodeProcessor::clearTableIds();

        if (file_exists($xmlPath)) {
            unlink($xmlPath);
        }
    }

    // The console now distinguishes the two outcomes — `EntryCreator` emits the
    // success line inside `if ($fileExists)` and says something different in the
    // other branch — so this is no longer the only signal available. It is kept
    // because it is the most direct one: it asserts the *absence* of the failure
    // record on the success path, where a console assertion can only observe the
    // absence of a string. `it('says so on the console when the entry file never
    // appears')` covers the inverse.
    // ---
    // Order matters, and wordpress-importer-archive#734 is why it is written down.
    //
    // These two assertions do cover different regressions, but only in this
    // order. The file check runs first: a resolver that regressed to
    // `base_path()` puts the entry file somewhere this path does not name, so
    // it fails here. Run the other way round, the spy fires first — an
    // unresolvable path also exhausts the loop — and this line is never
    // reached, which is how wordpress-importer-archive#728 came to claim a second line of defence that
    // was unreachable in practice.
    //
    // The spy then covers what the file check cannot: a loop that exhausted for
    // any reason other than a bad path.
    // ---
    expect(IlluminateFileFacade::exists(
        ContentRoot::collectionEntry('tables', TABLEPRESS_SITE, TABLEPRESS_SITE.'_tablepress-1')
    ))->toBeTrue()
        ->and(tablePressFindImported())->not->toBeNull();

    $logSpy->shouldNotHaveReceived('warning', [
        Mockery::pattern('/Entry file not found after retries/'),
        Mockery::any(),
    ]);
});

it('says so on the console when the entry file never appears', function (): void {
    // wordpress-importer-archive#729: the success line used to print unconditionally, outside the
    // `if (! $fileExists)` guard, so the console read identically whether the
    // poll found the file or exhausted all five attempts.
    //
    // The branch is only reachable when the canonical path reports missing.
    // Re-pointing the entries store does not do it — the run writes into
    // whatever tree the store names, so the file simply appears there. Forcing
    // `exists()` to answer false for that one path is what isolates the poll,
    // and it is safe: the only other Laravel `File` calls on this path are
    // `TablePressEntryCreator`'s, and they address `$incorrectPath` instead.
    TablePressShortcodeProcessor::clearTableIds();
    TablePressShortcodeProcessor::addTableId('1', TABLEPRESS_SITE, 'probe-page', '900002');
    ImportWarningManager::clearWarnings();

    $canonical = ContentRoot::collectionEntry(
        'tables',
        TABLEPRESS_SITE,
        TABLEPRESS_SITE.'_tablepress-1'
    );

    // `shouldReceive()` returns a union without `with()`; the shared trait helper
    // unwraps the composite so the chain is statically visible
    $fileMock = IlluminateFileFacade::partialMock();
    $this->singleMethodExpectation($fileMock, 'exists')
        ->with($canonical)
        ->andReturnFalse();

    $xmlPath = tablePressCreateTestXmlFile();
    $output = new BufferedOutput;

    try {
        $xmlParser = new XmlParser;
        $xmlParser->parse($xmlPath);

        $entryCreator = app(EntryCreator::class);
        $entryCreator->setXmlParser($xmlParser);
        $entryCreator->setAssetImporter(app(AssetImporter::class));
        $entryCreator->importTables(TABLEPRESS_SITE, $output, 'page');
    } finally {
        TablePressShortcodeProcessor::clearTableIds();

        if (file_exists($xmlPath)) {
            unlink($xmlPath);
        }
    }

    $console = $output->fetch();

    expect($console)->not->toContain('Table entry imported successfully.')
        ->and($console)->toContain('no file appeared at')
        // and it reaches the end-of-run warning report, not only the log
        ->and(ImportWarningManager::getWarningsByCategory('content'))
        ->toHaveKey('table_entry_file_missing');

    ImportWarningManager::clearWarnings();
});

it('reports no show-people shortcodes', function (): void {
    $tablePressEntryCreator = new TablePressEntryCreator;

    expect($tablePressEntryCreator->hasShowPeople())->toBeFalse();
});

it('returns an empty array of post categories', function (): void {
    $tablePressEntryCreator = new TablePressEntryCreator;

    expect($tablePressEntryCreator->getPostCategories())->toBeEmpty();
});

/**
 * Write the comprehensive TablePress fixture to a temporary file and return
 * its path.
 *
 * Reimplements the original class's `createTablePressTestXmlFile()` /
 * `createTempFile()` helpers as a file-level function: Pest binds a test
 * closure's `$this` to the base PHPUnit `TestCase`, which does not expose the
 * import trait's protected `$this->createTempFile()` / `$this->getXmlHeader()`
 * builders, so calling them from a closure is not statically resolvable.
 *
 * @return string The path to the created XML fixture file
 */
function tablePressCreateTestXmlFile(): string
{
    // ---
    // Through the shared registry rather than a local `tempnam(...).'.xml'`. That
    // form strands the stub `tempnam()` creates and nothing writes to, and this
    // file's copy of it accounted for 41 of the files left in the system temp
    // directory (wordpress-importer-archive#783). The registry is a static class precisely so a Pest closure
    // can reach it without `$this`, which is the constraint this helper was
    // written around.
    // ---
    $xmlPath = TemporaryFileRegistry::create('test_tablepress_comprehensive_');

    file_put_contents(
        $xmlPath,
        tablePressComprehensiveXml()
    );

    return $xmlPath;
}

/**
 * Build the comprehensive TablePress WXR fixture (a WordPress eXtended RSS
 * export carrying one `tablepress_table` item with full metadata).
 *
 * Reproduces the exact bytes the original class produced by concatenating the
 * trait's `getXmlHeader()`, the TablePress `<item>`, and the trait's
 * `getXmlFooter()`. It was written to disk and never parsed until #727; the
 * tests above now import it, so its `table_name`, `table_data` and serialized
 * `_tablepress_table_options` are the values they assert against.
 *
 * @return string The complete WXR XML content
 */
function tablePressComprehensiveXml(): string
{
    $header = '<?xml version="1.0" encoding="UTF-8"?>
<!-- This is a WordPress eXtended RSS file generated by WordPress as an export of your site. -->
<!-- It contains information about your site\'s posts, pages, comments, categories, and other
content. -->
<!-- You may use this file to transfer that content from one site to another. -->
<!-- This file is not intended to serve as a complete backup of your site. -->

<!-- To import this information into a WordPress site follow these steps: -->
<!-- 1. Log in to that site as an administrator. -->
<!-- 2. Go to Tools: Import in the WordPress admin panel. -->
<!-- 3. Install the "WordPress" importer from the list. -->
<!-- 4. Activate & Run Importer. -->
<!-- 5. Upload this file using the form provided on that page. -->
<!-- 6. You will first be asked to map the authors in this export file to users -->
<!--    on the site. For each author, you may choose to map to an -->
<!--    existing user on the site or to create a new user. -->
<!-- 7. WordPress will then import each of the posts, pages, comments, categories, etc. -->
<!--    contained in this file into your site. -->

<!-- generator="WordPress/6.7.3" created="2025-09-09 13:40" -->
<rss version="2.0"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wfw="http://wellformedweb.org/CommentAPI/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/"
>

    <channel>
        <title>Web Services</title>
        <link>https://supplychain.uams.edu/</link>
        <description>UAMS</description>
        <pubDate>Tue, 09 Sep 2025 13:40:52 +0000</pubDate>
        <language>en-US</language>
        <wp:wxr_version>1.2</wp:wxr_version>
        <wp:base_site_url>https://uamsdev.com/</wp:base_site_url>
        <wp:base_blog_url>https://supplychain.uams.edu/</wp:base_blog_url>

        <wp:author>
            <wp:author_id>1</wp:author_id>
            <wp:author_login><![CDATA[uamsonline]]></wp:author_login>
            <wp:author_email><![CDATA[uamsonline@uams.edu]]></wp:author_email>
            <wp:author_display_name><![CDATA[uamsonline]]></wp:author_display_name>
            <wp:author_first_name><![CDATA[]]></wp:author_first_name>
            <wp:author_last_name><![CDATA[]]></wp:author_last_name>
        </wp:author>

        <generator>https://wordpress.org/?v=6.7.3</generator>';

    // The TablePress table item with full metadata (verbatim fixture)
    $item = '        <item>
            <title><![CDATA[Sample Table]]></title>
            <link>https://example.com/sample-table/</link>
            <pubDate>Wed, 12 Jun 2024 15:10:35 +0000</pubDate>
            <dc:creator><![CDATA[admin]]></dc:creator>
            <guid isPermaLink="false">https://example.com/?post_type=tablepress_table&#038;p=123</guid>
            <description></description>
            <content:encoded><![CDATA[{"table_id":"1","table_name":"Sample Table","table_description":"A sample table for testing","table_data":[["Header 1","Header 2","Header 3"],["Row 1 Col 1","Row 1 Col 2","Row 1 Col 3"],["Row 2 Col 1","Row 2 Col 2","Row 2 Col 3"]]}]]></content:encoded>
            <excerpt:encoded><![CDATA[This is a sample table description for testing purposes.]]></excerpt:encoded>
            <wp:post_id>123</wp:post_id>
            <wp:post_date><![CDATA[2024-06-12 15:10:35]]></wp:post_date>
            <wp:post_date_gmt><![CDATA[2024-06-12 15:10:35]]></wp:post_date_gmt>
            <wp:post_modified><![CDATA[2024-06-12 15:10:35]]></wp:post_modified>
            <wp:post_modified_gmt><![CDATA[2024-06-12 15:10:35]]></wp:post_modified_gmt>
            <wp:comment_status><![CDATA[closed]]></wp:comment_status>
            <wp:ping_status><![CDATA[closed]]></wp:ping_status>
            <wp:post_name><![CDATA[sample-table]]></wp:post_name>
            <wp:status><![CDATA[publish]]></wp:status>
            <wp:post_parent>0</wp:post_parent>
            <wp:menu_order>0</wp:menu_order>
            <wp:post_type><![CDATA[tablepress_table]]></wp:post_type>
            <wp:post_password><![CDATA[]]></wp:post_password>
            <wp:is_sticky>0</wp:is_sticky>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_tablepress_export_table_id]]></wp:meta_key>
                <wp:meta_value><![CDATA[1]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_tablepress_table_options]]></wp:meta_key>
                <wp:meta_value><![CDATA[a:20:{s:8:"last_editor";s:1:"1";s:10:"last_editor_id";s:1:"1";s:8:"table_head";b:1;s:9:"table_foot";b:0;s:8:"alternating_row_colors";b:1;s:5:"row_hover";b:1;s:7:"cell_padding";s:2:"8";s:7:"cell_spacing";s:1:"0";s:5:"border";s:1:"1";s:8:"border_collapse";s:6:"collapse";s:8:"border_color";s:7:"#cccccc";s:9:"header_cells";b:1;s:9:"header_bg_color";s:7:"#e1e1e1";s:9:"header_text_color";s:7:"#000000";s:9:"header_font_size";s:2:"14";s:9:"header_font_weight";s:3:"bold";s:9:"header_font_style";s:6:"normal";s:9:"header_text_align";s:4:"left";s:9:"header_vertical_align";s:3:"top";s:9:"header_border_color";s:7:"#cccccc";}]]></wp:meta_value>
            </wp:postmeta>
            <wp:postmeta>
                <wp:meta_key><![CDATA[_tablepress_table_visibility]]></wp:meta_key>
                <wp:meta_value><![CDATA[a:2:{s:6:"columns";a:0:{}s:4:"rows";a:0:{}}]]></wp:meta_value>
            </wp:postmeta>
        </item>';

    $footer = '    </channel>
</rss>';

    return $header.$item.$footer;
}
