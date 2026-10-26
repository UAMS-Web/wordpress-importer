<?php

declare(strict_types=1);

/**
 * Tests that a `[table]` shortcode registers its TablePress id exactly once,
 * under the page key the deferred linker actually reads.
 *
 * `TextProcessor` used to special-case a strict, quoted-only `[table]` regex
 * (`/^\[table\s+id="(\d+)"\s*\/\]/`) and call `addTableId()` itself before
 * falling through to `toTiptapHtml()` — which reaches `addTableId()` again via
 * `TablePressShortcodeProcessor::parse()`. The two calls disagreed on the page
 * key: the branch passed `$statamicEntryId ?? getWordPressPostId()`, while
 * `parse()` always passes `getWordPressPostId()`.
 *
 * `addTableId()` dedupes per page key, so two different keys bypassed it and wrote
 * two things nothing wanted: a `$tableIdsByPage[$statamicEntryId]` bucket no
 * consumer reads — `DeferredProcessingCoordinator` looks the ids up by WordPress
 * post id — and a DUPLICATE append to the global `getTableIds()` cache.
 *
 * The duplicate was inert, not harmful: `ContentProcessor::linkTableEntries` does
 * index its `$tableIds` argument positionally, but it is only ever handed the
 * PER-PAGE bucket, which was already correct before the fix. The global cache's
 * three consumers are an `array_unique()` plus two emptiness checks. So these
 * tests pin the registration contract — one registrar, one key, no dead state —
 * rather than guarding a live mislinking bug.
 *
 * wordpress-importer-archive#308 removed the branch rather than making its regex tolerant: the generic
 * path is byte-identical apart from that extra `addTableId()`, so the strict
 * regex only ever duplicated work. Making it tolerant instead would have armed
 * the duplicate on `[table id=1 /]`, the dominant real-world form.
 *
 * @command  php vendor/bin/pest tests/Integration/TableShortcodeIdRegistrationTest.php
 */

use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * The WordPress post id the deferred table linker keys its lookups by.
 */
const TABLE_ID_WP_POST_ID = '1234';

/**
 * A Statamic entry id — a different identifier space from the WordPress post id,
 * which is what made the two `addTableId()` calls disagree.
 */
const TABLE_ID_STATAMIC_ENTRY_ID = 'a1b2c3d4-0000-4000-8000-statamicentry';

beforeEach(function (): void {
    // Start from an empty table-id cache so counts below are unambiguous
    TablePressShortcodeProcessor::clearTableIds();

    // The page key parse() registers under
    ProcessingContext::setWordPressPostId(TABLE_ID_WP_POST_ID);
});

afterEach(function (): void {
    // Clear shared static state so it cannot leak into later tests
    TablePressShortcodeProcessor::clearTableIds();
    ProcessingContext::clearWordPressPostId();
});

/**
 * Run content through the Bard pipeline the way an entry import does, passing a
 * Statamic entry id so the deferred-processing path is exercised.
 *
 * A real `AssetImporter` is used rather than a mock: the `[table]` path never
 * calls it, and its constructor only reads config, so a concrete instance keeps
 * the argument properly typed without a PHPDoc override.
 */
function processTableShortcodes(string $html): void
{
    TextProcessor::processForBard(
        $html,
        'www',
        new AssetImporter,
        new NullOutput,
        false,
        false,
        [],
        [],
        'some-page',
        TABLE_ID_STATAMIC_ENTRY_ID,
    );
}

it('registers a table id once per shortcode, whatever the shortcode form', function (string $shortcode, string $expectedId): void {
    processTableShortcodes(sprintf('<p>%s</p>', $shortcode));

    expect(TablePressShortcodeProcessor::getTableIds())->toBe([$expectedId]);
})->with([
    // The dominant real-world form: unquoted, space before the self-closing slash
    'unquoted with spaced slash' => ['[table id=1 /]', '1'],
    // The malformed form wordpress-importer-archive#304 taught `extract()` to recover
    'unquoted with glued slash' => ['[table id=2/]', '2'],
    // Whitespace after the `=`, per wordpress-importer-archive#308's acceptance criteria
    'unquoted with space after equals' => ['[table id= 13/]', '13'],
    // The only form the deleted strict regex ever matched
    'quoted with spaced slash' => ['[table id="2" /]', '2'],
    'quoted with glued slash' => ['[table id="2"/]', '2'],
    // Not self-closing at all
    'unquoted without slash' => ['[table id=3]', '3'],
    'quoted without slash' => ['[table id="3"]', '3'],
]);

it('appends each table id to the global cache exactly once', function (): void {
    // Two distinct tables on one page — the shape that exposed the double registration
    processTableShortcodes('<p>[table id="2" /]</p><p>[table id="3" /]</p>');

    // ---
    // The strongest signal that the second registrar is gone: before the fix this
    // read ['2','2','3','3']. The cache's own consumers tolerate duplicates, so this
    // asserts the registration contract rather than a downstream linking failure.
    // ---
    expect(TablePressShortcodeProcessor::getTableIds())->toBe(['2', '3']);
});

it('registers table ids under the WordPress post id the deferred linker reads', function (): void {
    processTableShortcodes('<p>[table id="2" /]</p>');

    expect(TablePressShortcodeProcessor::getTableIdsForPage(TABLE_ID_WP_POST_ID))->toBe(['2']);
});

it('writes no table-id bucket keyed by the Statamic entry id', function (): void {
    processTableShortcodes('<p>[table id="2" /]</p>');

    // DeferredProcessingCoordinator never looks ids up by entry id, so a bucket
    // under that key is unread state that also duplicates the global cache.
    expect(TablePressShortcodeProcessor::getTableIdsForPage(TABLE_ID_STATAMIC_ENTRY_ID))->toBe([]);
});
