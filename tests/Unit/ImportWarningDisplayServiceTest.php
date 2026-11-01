<?php

declare(strict_types=1);

/**
 * Tests that `ImportWarningDisplayService` prints every recorded import warning,
 * routing the six types that have a dedicated formatter to it and printing
 * every other type as a header and one JSON line per warning (#1314).
 *
 * The warning manager is process-wide static state, so every case starts and
 * ends with it cleared.
 *
 * @command  php vendor/bin/pest tests/Unit/ImportWarningDisplayServiceTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormWarningFormatter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningDisplayService;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

/**
 * What the service prints for the warnings recorded so far.
 *
 * @return string The output
 */
function warningDisplayOutput(): string
{
    $output = new BufferedOutput;

    ImportWarningDisplayService::displayAllWarnings($output);

    return warningDisplayLines($output);
}

/**
 * What a buffered output holds, with every line break folded to LF.
 *
 * `BufferedOutput` ends each line with `PHP_EOL`, which is CRLF on Windows. The lines
 * printed are under test, not how a console ends one.
 *
 * @param  BufferedOutput  $output  The output to read
 * @return string The output, LF-separated
 */
function warningDisplayLines(BufferedOutput $output): string
{
    return \preg_replace('/\R/', "\n", $output->fetch()) ?? '';
}

it('prints nothing when there are no warnings', function (): void {
    expect(warningDisplayOutput())->toBe('');
});

it('routes each formatted type to its formatter', function (string $category, string $type, Closure $formatter, array $warning): void {
    /** @var array<string, mixed> $warning */
    ImportWarningManager::addWarning($category, $type, $warning);

    $direct = new BufferedOutput;
    $formatter($direct, [$warning]);
    $expected = warningDisplayLines($direct);

    // The formatter must print something for this warning, and not the fallback
    // header, or the comparison below would pass whatever the routing did
    expect($expected)->not->toBe('')
        ->and($expected)->not->toContain("{$category} - {$type}:")
        ->and(warningDisplayOutput())->toBe($expected);
})->with([
    'unsupported form attributes' => ['forms', 'unsupported_attributes', FormWarningFormatter::displayUnsupportedFormAttributeWarnings(...), ['field_type' => 'text', 'attribute_name' => 'mask', 'form_id' => 3, 'form_title' => 'Contact']],
    'unmapped subfields' => ['forms', 'unmapped_subfields', FormWarningFormatter::displayUnmappedSubfieldWarnings(...), ['field_type' => 'name', 'subfield_label' => 'Prefix', 'attribute_name' => 'prefix', 'form_id' => 3, 'form_title' => 'Contact']],
    'unmapped fieldtypes' => ['forms', 'unmapped_fieldtypes', FormWarningFormatter::displayUnmappedFieldtypeWarnings(...), ['field_type' => 'signature', 'form_id' => 3, 'form_title' => 'Contact']],
    'unsupported embeds' => ['content', 'unsupported_embeds', FormWarningFormatter::displayUnsupportedEmbedWarnings(...), ['provider' => 'vimeo', 'url' => 'https://vimeo.com/1', 'page_url' => 'https://example.org/p/']],
    'unaddressed shortcodes' => ['content', 'unaddressed_shortcodes', FormWarningFormatter::displayUnaddressedShortcodeWarnings(...), ['shortcode' => 'foo', 'page_url' => 'https://example.org/p/']],
    'malformed shortcodes' => ['content', 'malformed_shortcodes', FormWarningFormatter::displayMalformedShortcodeWarnings(...), ['shortcode_name' => 'gallery', 'attribute' => 'ids', 'original' => '[gallery ids=]', 'page_url' => 'https://example.org/p/']],
]);

it('prints any other type as a header and a JSON line per warning', function (): void {
    ImportWarningManager::addWarning('content', 'side_by_side_over_character_limit', ['handle' => 'block_heading', 'length' => 51]);
    ImportWarningManager::addWarning('content', 'side_by_side_over_character_limit', ['handle' => 'button_text', 'length' => 43]);

    expect(warningDisplayOutput())->toBe(
        "content - side_by_side_over_character_limit:\n"
        ." - {\"handle\":\"block_heading\",\"length\":51}\n"
        ." - {\"handle\":\"button_text\",\"length\":43}\n"
        ."\n"
    );
});

it('uses the fallback for a formatted type name in the other category', function (): void {
    ImportWarningManager::addWarning('content', 'unmapped_fieldtypes', ['field_type' => 'x']);
    ImportWarningManager::addWarning('forms', 'malformed_shortcodes', ['shortcode_name' => 'y']);

    expect(warningDisplayOutput())->toBe(
        "content - unmapped_fieldtypes:\n - {\"field_type\":\"x\"}\n\n"
        ."forms - malformed_shortcodes:\n - {\"shortcode_name\":\"y\"}\n\n"
    );
});

it('groups each type under its category, categories in the order first recorded', function (): void {
    ImportWarningManager::addWarning('zeta', 'one', ['n' => 1]);
    ImportWarningManager::addWarning('alpha', 'two', ['n' => 2]);
    ImportWarningManager::addWarning('zeta', 'three', ['n' => 3]);

    expect(warningDisplayOutput())->toBe(
        "zeta - one:\n - {\"n\":1}\n\n"
        ."zeta - three:\n - {\"n\":3}\n\n"
        ."alpha - two:\n - {\"n\":2}\n\n"
    );
});

it('skips a category or type whose name PHP turned into an integer key', function (): void {
    // Characterization: a numeric category or type name becomes an integer array
    // key, which the display skips, so those warnings are never printed
    ImportWarningManager::addWarning('5', 'one', ['n' => 1]);
    ImportWarningManager::addWarning('content', '7', ['n' => 2]);
    ImportWarningManager::addWarning('content', 'shown', ['n' => 3]);

    expect(warningDisplayOutput())->toBe("content - shown:\n - {\"n\":3}\n\n");
});

it('prints the same through its instance wrapper', function (): void {
    ImportWarningManager::addWarning('content', 'other', ['n' => 1]);

    $output = new BufferedOutput;
    (new ImportWarningDisplayService)->displayAllWarningsWrapper($output);

    expect(warningDisplayLines($output))->toBe("content - other:\n - {\"n\":1}\n\n");
});
