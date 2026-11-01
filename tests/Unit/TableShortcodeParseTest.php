<?php

declare(strict_types=1);

/**
 * Parse-level regression coverage for malformed self-closing `[table]` shortcodes.
 *
 * wordpress-importer-archive#304 taught `ShortcodeProcessor::extract()` to recover a clean id from
 * `[table id=2/]` by `rtrim`ing the self-closing slash an unquoted value absorbs
 * (the attribute pattern's `[^\s"']+` branch stops only at whitespace). That
 * shipped without parse-level tests, so wordpress-importer-archive#310 backfills them.
 *
 * `MalformedShortcodeWarningTest` covers the *warning* wordpress-importer-archive#309 records for the same
 * inputs; this file covers what the parser actually returns, including the
 * quoted values that must NOT be touched. The distinction matters because the
 * recovered id is what `TablePressShortcodeProcessor::parse()` registers for
 * linking — a wrong id imports an empty table set.
 *
 * Registration and linking behaviour lives in
 * `tests/Integration/TableShortcodeIdRegistrationTest.php`, which needs a booted
 * container; everything here is pure string parsing.
 *
 * @command  php vendor/bin/pest tests/Unit/TableShortcodeParseTest.php
 */

use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;

beforeEach(function (): void {
    // The parser records warnings into shared static state; start clean
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();

    $this->processor = new ShortcodeProcessor;
});

afterEach(function (): void {
    // Clear shared static state so it cannot leak between tests
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Parse one shortcode and return the requested attribute as a string.
 *
 * Returned through explicit narrowing because `expect()->toBeArray()` and
 * `toBeString()` do not narrow for PHPStan at `level: max`.
 */
function tableParseAttribute(
    ShortcodeProcessor $processor,
    string $content,
    string $attribute
): string {
    $shortcodes = $processor->extract($content, new NullOutput);
    expect($shortcodes)->not->toBeEmpty();

    $shortcode = $shortcodes[0];
    expect($shortcode)->toBeArray();
    $shortcode = is_array($shortcode) ? $shortcode : [];

    $attributes = $shortcode['attributes'] ?? null;
    expect($attributes)->toBeArray();
    $attributes = is_array($attributes) ? $attributes : [];
    expect($attributes)->toHaveKey($attribute);

    $value = $attributes[$attribute];
    expect($value)->toBeString();

    return is_string($value) ? $value : '';
}

it('recovers a clean numeric id from a malformed self-closing table shortcode', function (string $content, string $expectedId): void {
    expect(tableParseAttribute($this->processor, $content, 'id'))->toBe($expectedId);
})->with([
    // The wordpress-importer-archive#304 case: the slash glues onto the unquoted value
    'glued slash' => ['[table id=2/]', '2'],
    // Whitespace after the `=`, per wordpress-importer-archive#310's acceptance criteria
    'space after equals, glued slash' => ['[table id= 13/]', '13'],
    // The dominant real-world form — the slash is already its own token
    'spaced slash' => ['[table id=1 /]', '1'],
    // Quoted forms were never affected by the slash absorption
    'quoted, spaced slash' => ['[table id="2" /]', '2'],
    'quoted, glued slash' => ['[table id="2"/]', '2'],
    // Not self-closing at all
    'bare unquoted' => ['[table id=3]', '3'],
]);

it('leaves a quoted value untouched, including its internal and trailing slashes', function (string $content, string $attribute, string $expected): void {
    expect(tableParseAttribute($this->processor, $content, $attribute))->toBe($expected);
})->with([
    // A URL's slashes must survive: the rtrim applies only to the unquoted branch
    'url with internal slashes' => [
        '[audio mp3="https://example.com/wp-content/uploads/2024/01/file.mp3"]',
        'mp3',
        'https://example.com/wp-content/uploads/2024/01/file.mp3',
    ],
    // A quoted value that legitimately ends in a slash keeps it
    'url with a trailing slash' => [
        '[audio mp3="https://example.com/media/"]',
        'mp3',
        'https://example.com/media/',
    ],
    // The same rule applies to a quoted `[table]` id, however malformed it looks
    'quoted table id ending in a slash' => ['[table id="2/"]', 'id', '2/'],
]);

it('normalizes the inert responsive=scroll/ value without inventing a table id', function (): void {
    $shortcodes = $this->processor->extract('[table responsive=scroll/]', new NullOutput);

    expect($shortcodes)->not->toBeEmpty();
    $shortcode = $shortcodes[0];
    expect($shortcode)->toBeArray();
    $shortcode = is_array($shortcode) ? $shortcode : [];

    $attributes = $shortcode['attributes'] ?? null;
    expect($attributes)->toBeArray();
    $attributes = is_array($attributes) ? $attributes : [];

    // The slash is stripped from the unquoted value
    expect($attributes['responsive'] ?? null)->toBe('scroll');

    // ---
    // No `id` is synthesised. A bogus id here would be registered for linking by
    // TablePressShortcodeProcessor::parse() and shift every later table set onto
    // the wrong table, so the absence of the key is the load-bearing assertion.
    // ---
    expect($attributes)->not->toHaveKey('id');
});

it('recovers ids independently when one shortcode is malformed and the next is not', function (): void {
    $shortcodes = $this->processor->extract(
        '[table id=2/] and [table id="3" /]',
        new NullOutput
    );

    // Both shortcodes parse, in source order, each to its own clean id
    expect($shortcodes)->toHaveCount(2);

    $ids = array_map(
        function (mixed $shortcode): string {
            $shortcode = is_array($shortcode) ? $shortcode : [];
            $attributes = $shortcode['attributes'] ?? [];
            $attributes = is_array($attributes) ? $attributes : [];
            $id = $attributes['id'] ?? '';

            return is_string($id) ? $id : '';
        },
        $shortcodes
    );

    expect($ids)->toBe(['2', '3']);
});
