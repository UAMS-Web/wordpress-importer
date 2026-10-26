<?php

declare(strict_types=1);

/**
 * Tests that malformed self-closing shortcodes surface as import warnings.
 *
 * Since wordpress-importer-archive#304, a glued-on self-closing slash in an unquoted attribute value
 * (e.g. [table id=2/]) is silently stripped by ShortcodeProcessor::extract.
 * wordpress-importer-archive#309 makes that source-content defect visible by recording a
 * content / malformed_shortcodes warning naming the affected entry.
 *
 * @command  php vendor/bin/pest tests/Unit/MalformedShortcodeWarningTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningDisplayService;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;

beforeEach(function (): void {
    // Start each test with a clean warning collection and entry context
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();

    $this->processor = new ShortcodeProcessor;
});

afterEach(function (): void {
    // Clear shared static state so it doesn't leak between tests
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

/**
 * Return a typed console sink for driving the shortcode processor.
 *
 * The parser only writes progress to this output and never asserts on it, so a
 * NullOutput suffices — returned through a declared OutputInterface so PHPStan
 * types the value at each call site (a bare dynamic property reads as mixed).
 */
function malformedShortcodeOutput(): OutputInterface
{
    return new NullOutput;
}

/**
 * Return the first parsed shortcode after asserting the list is non-empty.
 *
 * @param  array<int|string, mixed>  $shortcodes
 * @return array<mixed>
 */
function malformedFirstShortcode(array $shortcodes): array
{
    expect($shortcodes)->not->toBeEmpty();

    $shortcode = $shortcodes[0];
    expect($shortcode)->toBeArray();

    // toBeArray() does not narrow for PHPStan; narrow before returning.
    return is_array($shortcode) ? $shortcode : [];
}

/**
 * Return a string shortcode attribute value.
 *
 * @param  array<mixed>  $shortcode
 */
function malformedShortcodeAttribute(
    array $shortcode,
    string $attribute
): string {
    $attributes = $shortcode['attributes'] ?? null;
    expect($attributes)->toBeArray();

    // toBeArray() does not narrow for PHPStan; narrow before the key lookup.
    $attributes = is_array($attributes) ? $attributes : [];
    expect($attributes)->toHaveKey($attribute);

    $value = $attributes[$attribute];
    expect($value)->toBeString();

    // toBeString() does not narrow for PHPStan; narrow before returning.
    return is_string($value) ? $value : '';
}

/**
 * Return the first malformed-shortcode warning after asserting one exists.
 *
 * @return array<mixed>
 */
function malformedFirstWarning(): array
{
    $warnings = ImportWarningManager::getWarnings(
        'content',
        'malformed_shortcodes'
    );
    expect($warnings)->not->toBeEmpty();

    $warning = $warnings[0];
    expect($warning)->toBeArray();

    // toBeArray() does not narrow for PHPStan; narrow before returning.
    return is_array($warning) ? $warning : [];
}

it('records a warning naming the entry when a stripped slash is normalized', function (): void {
    // Set the entry currently being processed so the warning can name it
    ProcessingContext::setWordPressPageLink('https://example.com/affected-page');

    // Parse a malformed self-closing table shortcode
    $shortcodes = $this->processor->extract('[table id=2/]', malformedShortcodeOutput());

    // The slash is still stripped from the parsed value (parent wordpress-importer-archive#304 behavior)
    expect(
        malformedShortcodeAttribute(
            malformedFirstShortcode($shortcodes),
            'id'
        )
    )->toBe('2');

    // A single malformed_shortcodes warning is recorded
    $warnings = ImportWarningManager::getWarnings('content', 'malformed_shortcodes');
    expect($warnings)->toHaveCount(1);

    // The warning names the shortcode, attribute, raw -> normalized value, and entry
    $warning = malformedFirstWarning();
    expect($warning['shortcode_name'] ?? null)->toBe('table');
    expect($warning['attribute'] ?? null)->toBe('id');
    expect($warning['raw_value'] ?? null)->toBe('2/');
    expect($warning['normalized_value'] ?? null)->toBe('2');
    expect($warning['original'] ?? null)->toBe('[table id=2/]');
    expect($warning['page_url'] ?? null)->toBe('https://example.com/affected-page');
});

it('surfaces a warning for the inert responsive=scroll/ slash', function (): void {
    // Parse a table shortcode carrying the inert responsive=scroll/ attribute
    $this->processor->extract('[table responsive=scroll/]', malformedShortcodeOutput());

    // A warning is recorded for the stripped slash on the responsive attribute
    $warnings = ImportWarningManager::getWarnings('content', 'malformed_shortcodes');
    expect($warnings)->toHaveCount(1);
    $warning = malformedFirstWarning();
    expect($warning['attribute'] ?? null)->toBe('responsive');
    expect($warning['raw_value'] ?? null)->toBe('scroll/');
    expect($warning['normalized_value'] ?? null)->toBe('scroll');
});

it('does not warn for a quoted trailing slash', function (): void {
    // Parse a table shortcode whose id is quoted and legitimately ends in a slash
    $shortcodes = $this->processor->extract('[table id="2/"]', malformedShortcodeOutput());

    // The quoted value is preserved untouched
    expect(
        malformedShortcodeAttribute(
            malformedFirstShortcode($shortcodes),
            'id'
        )
    )->toBe('2/');

    // No malformed_shortcodes warning is recorded
    expect(
        ImportWarningManager::getWarnings('content', 'malformed_shortcodes')
    )->toHaveCount(0);
});

it('does not duplicate the warning on repeated extraction', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.com/affected-page');

    // Parse the same malformed shortcode twice, as different passes would
    $this->processor->extract('[table id=2/]', malformedShortcodeOutput());
    $this->processor->extract('[table id=2/]', malformedShortcodeOutput());

    // Only one warning is retained for the same entry + shortcode + attribute
    expect(
        ImportWarningManager::getWarnings('content', 'malformed_shortcodes')
    )->toHaveCount(1);
});

it('surfaces the warning in the end-of-import summary', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.com/affected-page');

    // Record the warning by parsing a malformed shortcode
    $this->processor->extract('[table id=2/]', malformedShortcodeOutput());

    // Render the full warning summary to a buffer
    $buffer = new BufferedOutput;
    ImportWarningDisplayService::displayAllWarnings($buffer);
    $rendered = $buffer->fetch();

    // The summary surfaces the shortcode, the normalization, and the entry
    expect($rendered)->toContain('malformed');
    expect($rendered)->toContain('[table id=2/]');
    expect($rendered)->toContain("'2/' -> '2'");
    expect($rendered)->toContain('https://example.com/affected-page');
});
