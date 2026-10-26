<?php

declare(strict_types=1);

/**
 * The console text FormWarningFormatter prints for each kind of form and content warning (#98).
 *
 * The formatter is reached from `ImportWarningDisplayService`, whose test checks that each
 * category is routed to the right method but not what the method prints. These tests pin the
 * full output: grouping and sorting, deduplication, the defaults for a missing or non-string
 * value, the three-example limit with its "and N more" line, and the ID parsed back out of a
 * form key.
 *
 * @command  php vendor/bin/pest tests/Unit/FormWarningFormatterTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormWarningFormatter;

/**
 * Run one formatter method and return what it printed.
 *
 * BufferedOutput ends each line with PHP_EOL, which is "\r\n" on Windows, so the text is
 * normalized to "\n" and the expectations below hold on every platform (#114).
 *
 * @param  Closure(BufferedOutput, array<int|string, mixed>): void  $method  The formatter method
 * @param  array<int|string, mixed>  $warnings  The warnings to format
 * @return string The printed text, without console markup, with "\n" line endings
 */
function formWarningText(Closure $method, array $warnings): string
{
    $output = new BufferedOutput;
    $method($output, $warnings);

    return \str_replace(\PHP_EOL, "\n", $output->fetch());
}

it('groups unsupported attributes by fieldtype, attribute and form', function (): void {
    $text = formWarningText(FormWarningFormatter::displayUnsupportedFormAttributeWarnings(...), [
        'not a warning',
        ['field_type' => 'text', 'attribute_name' => 'mask', 'form_title' => 'Contact', 'form_id' => 3],
        // A repeat of the same form is listed once.
        ['field_type' => 'text', 'attribute_name' => 'mask', 'form_title' => 'Contact', 'form_id' => '3'],
        ['field_type' => 'text', 'attribute_name' => 'mask', 'form_title' => '  Apply', 'form_id' => 4],
        ['field_type' => 'checkbox', 'attribute_name' => 'enableChoiceValue', 'form_title' => 'Survey', 'form_id' => ''],
        // Every value missing, or not a string, falls back to its default.
        ['field_type' => ['x'], 'form_id' => true],
    ]);

    expect($text)->toBe(
        "The following Gravity Forms fieldtypes had field attributes that are not supported in Statamic and were not imported. You may need to manually configure these field settings in Statamic.\n"
        ."\n"
        ." - Unknown Fieldtype\n"
        ."     - Attribute: 'Unknown Attribute'\n"
        ."         - Form(s):\n"
        ."             - 'Unknown Form' (ID: 1)\n"
        ." - checkbox\n"
        ."     - Attribute: 'enableChoiceValue'\n"
        ."         - Form(s):\n"
        ."             - 'Survey'\n"
        ." - text\n"
        ."     - Attribute: 'mask'\n"
        ."         - Form(s):\n"
        ."             - 'Contact' (ID: 3)\n"
        ."             - 'Apply' (ID: 4)\n"
        ."\n"
    );
});

it('lists unmapped subfields and unmapped subfield attributes separately', function (): void {
    $text = formWarningText(FormWarningFormatter::displayUnmappedSubfieldWarnings(...), [
        42,
        ['field_type' => 'name', 'subfield_label' => 'Prefix'],
        ['field_type' => 'name', 'subfield_label' => 'Prefix', 'attribute_name' => ''],
        ['field_type' => 'name', 'subfield_label' => 'First', 'attribute_name' => 'placeholder'],
        ['field_type' => 'name', 'subfield_label' => 'First', 'attribute_name' => 'placeholder'],
        ['field_type' => 'name', 'subfield_label' => 'First', 'attribute_name' => 'autocomplete'],
        ['field_type' => 'address', 'attribute_name' => 7],
        ['subfield_label' => 'Zip'],
    ]);

    expect($text)->toBe(
        "The following Gravity Forms fieldtypes had subfields or subfield attributes that could not be mapped to Statamic. You may need to manually review these fields and subfields in Statamic.\n"
        ."\n"
        ." - Unknown Fieldtype\n"
        ."     - Unmapped Subfields:\n"
        ."         - 'Zip'\n"
        ." - address\n"
        ."     - Unmapped Subfield Attributes:\n"
        ."         - Subfield: 'Unknown Subfield'\n"
        ."             - 7\n"
        ." - name\n"
        ."     - Unmapped Subfields:\n"
        ."         - 'Prefix'\n"
        ."     - Unmapped Subfield Attributes:\n"
        ."         - Subfield: 'First'\n"
        ."             - placeholder\n"
        ."             - autocomplete\n"
        ."\n"
    );
});

it('lists the forms carrying each unmapped fieldtype, sorted', function (): void {
    $text = formWarningText(FormWarningFormatter::displayUnmappedFieldtypeWarnings(...), [
        null,
        ['field_type' => 'signature', 'form_title' => 'Waiver', 'form_id' => 9],
        ['field_type' => 'signature', 'form_title' => 'Waiver', 'form_id' => 9],
        ['field_type' => 'signature', 'form_title' => ' Apply', 'form_id' => 2.0],
        ['field_type' => 'signature', 'form_title' => 'Anonymous'],
        ['field_type' => 'captcha', 'form_title' => 'Contact', 'form_id' => false],
        ['form_id' => 5],
    ]);

    expect($text)->toBe(
        "The following Gravity Forms fieldtypes could not be mapped to a Statamic fieldtype. You may need to manually review these fieldtypes and configure them in Statamic.\n"
        ."\n"
        ." - Unknown Fieldtype\n"
        ."     - Form(s):\n"
        ."         - 'Unknown Form' (ID: 5)\n"
        ." - captcha\n"
        ."     - Form(s):\n"
        ."         - 'Contact' (ID: 0)\n"
        ." - signature\n"
        ."     - Form(s):\n"
        // The leading space sorts the form first, and is trimmed once the ID is parsed out.
        ."         - 'Apply' (ID: 2)\n"
        ."         - 'Anonymous'\n"
        ."         - 'Waiver' (ID: 9)\n"
        ."\n"
    );
});

it('summarizes unsupported embeds with up to three examples each', function (): void {
    $embed = static fn (string $url, ?string $slug = null, ?string $site = null): array => \array_filter(
        ['provider_name' => 'Vimeo', 'embed_type' => 'video', 'url' => $url, 'page_slug' => $slug, 'site' => $site],
        static fn (mixed $value): bool => $value !== null
    );

    $text = formWarningText(FormWarningFormatter::displayUnsupportedEmbedWarnings(...), [
        'not a warning',
        // Listed first, so the output order is the sort's and not the input's.
        ['url' => 'https://example.com/x', 'site' => 'main'],
        $embed('https://vimeo.com/1', 'about', 'main'),
        // An empty URL counts as an occurrence but is not an example.
        $embed(''),
        $embed('https://vimeo.com/2', null, 'main'),
        $embed('https://vimeo.com/3'),
        $embed('https://vimeo.com/4', 'news', 'main'),
        ['provider_name' => 'Vimeo', 'embed_type' => 'audio', 'url' => 'https://vimeo.com/a'],
    ]);

    expect($text)->toBe(
        "The following WordPress embed providers are not currently supported and were not imported. You may need to manually review these embeds in Statamic.\n"
        ."\n"
        ." - Provider: 'Vimeo'\n"
        ."     - Embed type: 'audio'\n"
        ."         - Occurrences: 1\n"
        ."         - Example: https://vimeo.com/a\n"
        ."     - Embed type: 'video'\n"
        ."         - Occurrences: 5\n"
        ."         - Example: https://vimeo.com/1 (page: about, site: main)\n"
        ."         - Example: https://vimeo.com/2 (site: main)\n"
        ."         - Example: https://vimeo.com/3\n"
        ."         - ... and 2 more\n"
        ." - Provider: 'unknown'\n"
        ."     - Embed type: 'none'\n"
        ."         - Occurrences: 1\n"
        ."         - Example: https://example.com/x (site: main)\n"
        ."\n"
    );
});

it('adds no remainder line for exactly three embeds', function (): void {
    $warnings = [];

    foreach ([1, 2, 3] as $n) {
        $warnings[] = ['provider_name' => 'Vimeo', 'embed_type' => 'video', 'url' => "https://vimeo.com/{$n}"];
    }

    $text = formWarningText(FormWarningFormatter::displayUnsupportedEmbedWarnings(...), $warnings);

    expect($text)->toContain("         - Occurrences: 3\n         - Example: https://vimeo.com/1\n         - Example: https://vimeo.com/2\n         - Example: https://vimeo.com/3\n\n")
        ->and($text)->not->toContain('more');
});

it('summarizes unaddressed shortcodes with up to three examples each', function (): void {
    $shortcode = static fn (string $original, ?string $slug = null, ?string $site = null): array => \array_filter(
        ['shortcode_name' => 'gallery', 'original' => $original, 'page_slug' => $slug, 'site' => $site],
        static fn (mixed $value): bool => $value !== null
    );

    $text = formWarningText(FormWarningFormatter::displayUnaddressedShortcodeWarnings(...), [
        false,
        ['original' => '[mystery]', 'page_slug' => 'home', 'site' => 'main'],
        $shortcode('[gallery ids="1"]', 'about', 'main'),
        $shortcode(''),
        $shortcode('[gallery ids="2"]', null, 'main'),
        $shortcode('[gallery ids="3"]'),
        $shortcode('[gallery ids="4"]'),
    ]);

    expect($text)->toBe(
        "The following WordPress shortcodes are not currently supported and were not imported. You may need to manually review these shortcodes in Statamic.\n"
        ."\n"
        ." - Shortcode: '[gallery]'\n"
        ."     - Occurrences: 5\n"
        ."     - Example: [gallery ids=\"1\"] (page: about, site: main)\n"
        ."     - Example: [gallery ids=\"2\"] (site: main)\n"
        ."     - Example: [gallery ids=\"3\"]\n"
        ."     - ... and 2 more\n"
        ." - Shortcode: '[unknown]'\n"
        ."     - Occurrences: 1\n"
        ."     - Example: [mystery] (page: home, site: main)\n"
        ."\n"
    );
});

it('adds no remainder line for exactly three unaddressed shortcodes', function (): void {
    $text = formWarningText(FormWarningFormatter::displayUnaddressedShortcodeWarnings(...), [
        ['shortcode_name' => 'gallery', 'original' => '[gallery a]'],
        ['shortcode_name' => 'gallery', 'original' => '[gallery b]'],
        ['shortcode_name' => 'gallery', 'original' => '[gallery c]'],
    ]);

    expect($text)->toContain("     - Occurrences: 3\n     - Example: [gallery a]\n     - Example: [gallery b]\n     - Example: [gallery c]\n\n")
        ->and($text)->not->toContain('more');
});

it('shows how each malformed shortcode attribute was normalized', function (): void {
    $malformed = static fn (string $original, ?string $url = null): array => \array_filter(
        ['shortcode_name' => 'table', 'attribute' => 'id', 'raw_value' => '2/', 'normalized_value' => '2', 'original' => $original, 'page_url' => $url],
        static fn (mixed $value): bool => $value !== null
    );

    $text = formWarningText(FormWarningFormatter::displayMalformedShortcodeWarnings(...), [
        'not a warning',
        ['original' => '[odd x=1/]'],
        $malformed('[table id=2/]', 'https://www.uams.edu/about/'),
        // With no original text, the shortcode's name stands in for it.
        $malformed(''),
        $malformed('[table id=2/]'),
        $malformed('[table id=2/]', 'https://www.uams.edu/x/'),
        $malformed('[table id=2/]'),
    ]);

    expect($text)->toBe(
        "The following WordPress shortcodes were malformed: a glued-on self-closing slash was stripped from an attribute value during import (e.g. [table id=2/] became id=\"2\"). The import normalized them automatically, but the source content should be corrected.\n"
        ."\n"
        ." - Shortcode: '[table]'\n"
        ."     - Occurrences: 5\n"
        ."     - Example: [table id=2/] (id: '2/' -> '2') (entry: https://www.uams.edu/about/)\n"
        ."     - Example: [table] (id: '2/' -> '2')\n"
        ."     - Example: [table id=2/] (id: '2/' -> '2')\n"
        ."     - ... and 2 more\n"
        ." - Shortcode: '[unknown]'\n"
        ."     - Occurrences: 1\n"
        ."     - Example: [odd x=1/] (: '' -> '')\n"
        ."\n"
    );
});

it('adds no remainder line for exactly three malformed shortcodes', function (): void {
    $text = formWarningText(FormWarningFormatter::displayMalformedShortcodeWarnings(...), [
        ['shortcode_name' => 'table', 'original' => '[table a]'],
        ['shortcode_name' => 'table', 'original' => '[table b]'],
        ['shortcode_name' => 'table', 'original' => '[table c]'],
    ]);

    expect($text)->toContain("     - Occurrences: 3\n")
        ->and($text)->toContain("     - Example: [table c] (: '' -> '')\n\n")
        ->and($text)->not->toContain('more');
});

it('prints only the header and blank lines when there is nothing to report', function (Closure $method): void {
    $lines = \explode("\n", formWarningText($method, []));

    expect($lines)->toHaveCount(4)
        ->and($lines[1])->toBe('')
        ->and($lines[2])->toBe('')
        ->and($lines[3])->toBe('');
})->with([
    'unsupported attributes' => [FormWarningFormatter::displayUnsupportedFormAttributeWarnings(...)],
    'unmapped subfields' => [FormWarningFormatter::displayUnmappedSubfieldWarnings(...)],
    'unmapped fieldtypes' => [FormWarningFormatter::displayUnmappedFieldtypeWarnings(...)],
    'unsupported embeds' => [FormWarningFormatter::displayUnsupportedEmbedWarnings(...)],
    'unaddressed shortcodes' => [FormWarningFormatter::displayUnaddressedShortcodeWarnings(...)],
    'malformed shortcodes' => [FormWarningFormatter::displayMalformedShortcodeWarnings(...)],
]);

it('shows three of four embeds, skips one with no URL, and counts the rest', function (): void {
    $text = formWarningText(FormWarningFormatter::displayUnsupportedEmbedWarnings(...), [
        // No URL at all: counted, but nothing to show.
        ['provider_name' => 'Vimeo', 'embed_type' => 'video'],
        ['provider_name' => 'Vimeo', 'embed_type' => 'video', 'url' => 'https://vimeo.com/1'],
        ['provider_name' => 'Vimeo', 'embed_type' => 'video', 'url' => 'https://vimeo.com/2'],
        ['provider_name' => 'Vimeo', 'embed_type' => 'video', 'url' => 'https://vimeo.com/3'],
    ]);

    expect($text)->toContain(
        "         - Occurrences: 4\n"
        ."         - Example: https://vimeo.com/1\n"
        ."         - Example: https://vimeo.com/2\n"
        ."         - Example: https://vimeo.com/3\n"
        ."         - ... and 1 more\n"
    );
});

it('shows three of four unaddressed shortcodes, skips one with no text, and counts the rest', function (): void {
    $text = formWarningText(FormWarningFormatter::displayUnaddressedShortcodeWarnings(...), [
        ['shortcode_name' => 'gallery'],
        ['shortcode_name' => 'gallery', 'original' => '[gallery a]'],
        ['shortcode_name' => 'gallery', 'original' => '[gallery b]'],
        ['shortcode_name' => 'gallery', 'original' => '[gallery c]'],
    ]);

    expect($text)->toContain(
        "     - Occurrences: 4\n"
        ."     - Example: [gallery a]\n"
        ."     - Example: [gallery b]\n"
        ."     - Example: [gallery c]\n"
        ."     - ... and 1 more\n"
    );
});

it('shows three of four malformed shortcodes, naming one with no text by its shortcode', function (): void {
    $text = formWarningText(FormWarningFormatter::displayMalformedShortcodeWarnings(...), [
        ['shortcode_name' => 'table', 'attribute' => 'id', 'raw_value' => '2/', 'normalized_value' => '2'],
        ['shortcode_name' => 'table', 'original' => '[table a]'],
        ['shortcode_name' => 'table', 'original' => '[table b]'],
        ['shortcode_name' => 'table', 'original' => '[table c]'],
    ]);

    expect($text)->toContain(
        "     - Occurrences: 4\n"
        ."     - Example: [table] (id: '2/' -> '2')\n"
        ."     - Example: [table a] (: '' -> '')\n"
        ."     - Example: [table b] (: '' -> '')\n"
        ."     - ... and 1 more\n"
    );
});

it('prints an all-digit form title with no ID rather than failing on it', function (Closure $method, string $expected): void {
    // PHP stores the key '2024' as the int 2024, which the form-key parsing must accept.
    expect(formWarningText($method, [['field_type' => 'text', 'attribute_name' => 'mask', 'form_title' => '2024']]))->toContain($expected);
})->with([
    'unsupported attributes' => [FormWarningFormatter::displayUnsupportedFormAttributeWarnings(...), "             - '2024'\n"],
    'unmapped fieldtypes' => [FormWarningFormatter::displayUnmappedFieldtypeWarnings(...), "         - '2024'\n"],
]);
