<?php

declare(strict_types=1);

/**
 * Unit tests for LinkProcessor::generateEntryReference() query/fragment ordering.
 *
 * Statamic 6 (statamic/cms#11468, merged 2026-04-09) appends `?query#anchor` to
 * Bard entry links in standard URL order — the query precedes the fragment. The
 * importer must emit the same shape so the Bard LinkToolbar's "Append" field
 * round-trips and any consumer that re-parses the href reads the query params.
 *
 * Before the #3 fix the helper appended the fragment first, producing
 * `statamic://entry::123#section?foo=bar`, which is malformed: the `#`
 * terminates query parsing, so `?foo=bar` lands inside the fragment.
 *
 * Pure string logic when given a string id — callable without a booted Statamic
 * app, so this extends the bare package `TestCase`.
 *
 * Standalone (no host Statamic app required):
 *
 * @command  php vendor/bin/pest tests/Unit/EntryReferenceQueryFragmentOrderTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;

it('assembles the reference with the query before the fragment', function (?string $fragment, ?string $query, string $expected): void {
    // The "both" row is the #3 regression guard: a reversed append order emits
    // `statamic://entry::123#section?foo=bar` and fails the exact-string assert.
    expect(LinkProcessor::generateEntryReference('123', $fragment, $query))->toBe($expected);
})->with([
    // [fragment, query, expected]
    'no query, no fragment' => [null, null, 'statamic://entry::123'],
    'query only' => [null, '?foo=bar', 'statamic://entry::123?foo=bar'],
    'fragment only' => ['#section', null, 'statamic://entry::123#section'],
    'both — query precedes fragment' => ['#section', '?foo=bar', 'statamic://entry::123?foo=bar#section'],
]);

it('omits an empty-string fragment or query instead of appending a bare delimiter', function (): void {
    // Bounds the `!== ''` guard so a mutation that drops it (and re-emits stray
    // `?`/`#` delimiters) is caught.
    expect(LinkProcessor::generateEntryReference('123', '', ''))->toBe('statamic://entry::123');
});
