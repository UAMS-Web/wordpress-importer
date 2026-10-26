<?php

declare(strict_types=1);

/**
 * Pins `generatePageItem()`'s `post_id` coercion (wordpress-importer-archive#531).
 *
 * `configString()` resolved through `stringValue()`, which returns `null` for
 * anything that is not a string — so an integer `post_id` took the `'123'` default.
 * Every fixture helper that wrote its id as a number therefore emitted a page whose
 * `<wp:post_id>` disagreed with the id the helper named, and a test asserting on the
 * named id was asserting a value the XML never carried.
 *
 * **Anchoring note.** These cases deliberately use `generatePageItem()` directly and
 * the `generateBasicPageItem()` / `generateLandingPageItem()` / `getGutenbergPageItem()`
 * helpers — all of which wordpress-importer-archive#533 lists as KEEP. The four `get*PageItem()` block helpers
 * that wordpress-importer-archive#531's own table tabulates are the ones wordpress-importer-archive#533 DELETES, and they have no live
 * caller today (their only references are comments). Asserting on them here would
 * hand dead code a live caller and obstruct that cleanup, so they are left alone.
 *
 * **Base class.** `WordPressImportTestCase`, which extends the host app's
 * `Tests\TestCase`: not because the assertions need a booted app, but because
 * the helpers live in `WordPressImportTestTrait`, which that class uses.
 * Consequence: this file does NOT run in package CI. See `tests/CLAUDE.md` and
 * the `host-app-tests` skill.
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/GeneratePageItemPostIdTest.php
 */

use UAMSWeb\WordPressImporter\Tests\Feature\WordPressImportTestCase;

uses(WordPressImportTestCase::class);

// Guard the live `content/` submodule: capture it before each test and refuse
// to leave anything behind afterwards (wordpress-importer-archive#570).
beforeEach(function (): void {
    $this->captureSubmoduleStates();
});

afterEach(function (): void {
    $this->assertSubmodulesUnchanged();
});

/**
 * Extract the `<wp:post_id>` an item XML string actually carries.
 *
 * @param  string  $xml  The generated page-item XML
 * @return ?string The post id, or null when the element is absent
 */
function generatePageItemEmittedId(string $xml): ?string
{
    return preg_match('#<wp:post_id>([^<]*)</wp:post_id>#', $xml, $matches) === 1
        ? $matches[1]
        : null;
}

it('emits an integer post_id rather than silently dropping it', function (): void {
    // The exact case from the issue's acceptance criteria.
    $xml = $this->generatePageItem(['post_id' => 456]);

    expect(generatePageItemEmittedId($xml))->toBe('456');
})->group('fixture-generation');

it('still accepts a string post_id', function (): void {
    $xml = $this->generatePageItem(['post_id' => '456']);

    expect(generatePageItemEmittedId($xml))->toBe('456');
})->group('fixture-generation');

it('emits the id in the guid as well as the post_id element', function (): void {
    // ---
    // Both are built from the same resolved value, so a regression fixing only one
    // would leave a page whose guid and post id disagree.
    // ---
    $xml = $this->generatePageItem(['post_id' => 456]);

    expect($xml)->toContain('?page_id=456');
    expect($xml)->toContain('<wp:post_id>456</wp:post_id>');
})->group('fixture-generation');

it('falls back to the default for a value that is neither string nor int', function (mixed $postId): void {
    // ---
    // Stringifying these would swap one silent substitution for another: `false`
    // would become an empty element that reads as legitimate.
    // ---
    $xml = $this->generatePageItem(['post_id' => $postId]);

    expect(generatePageItemEmittedId($xml))->toBe('123');
})->with([
    'null' => [null],
    'bool' => [false],
    'array' => [[456]],
    'float' => [456.5],
])->group('fixture-generation');

it('gives the basic-page helper the id it names', function (): void {
    // `generateBasicPageItem()` passes 900 and emitted 123 before the fix.
    expect(generatePageItemEmittedId($this->generateBasicPageItem()))->toBe('900');
})->group('fixture-generation');

it('gives the landing-page helper the id it names', function (): void {
    // `generateLandingPageItem()` passes 901 and emitted 123 before the fix.
    expect(generatePageItemEmittedId($this->generateLandingPageItem()))->toBe('901');
})->group('fixture-generation');

it('leaves the Gutenberg helper on 123, which it always named', function (): void {
    // ---
    // This helper passes the integer 123, which happened to match the default — the
    // reason the defect went unnoticed at all. Its output must not move.
    // ---
    expect(generatePageItemEmittedId($this->getGutenbergPageItem()))->toBe('123');
})->group('fixture-generation');

it('keeps the two page helpers on distinct ids', function (): void {
    // ---
    // Before the fix both collapsed onto 123, so a run importing them together
    // produced two pages claiming the same WordPress id.
    // ---
    $basic = generatePageItemEmittedId($this->generateBasicPageItem());
    $landing = generatePageItemEmittedId($this->generateLandingPageItem());

    expect($basic)->not->toBe($landing);
})->group('fixture-generation');

it('does not disturb the other configString-backed fields', function (): void {
    // The coercion changes how a value is read, not which keys are read.
    $xml = $this->generatePageItem(['post_id' => 456]);

    expect($xml)->toContain('<wp:status><![CDATA[publish]]></wp:status>');
    expect($xml)->toContain('<wp:post_type><![CDATA[page]]></wp:post_type>');
})->group('fixture-generation');
