<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NinjaForms\Validator;

/**
 * Validation of `.nff` export files (#10).
 *
 * A `.nff` is one form per file — a bare object of `settings` / `fields` / `actions` —
 * where a Gravity Forms export is a *list* of forms each carrying `id` and `title`. That
 * difference drives everything here: the base validator iterates its argument as a list,
 * which for a `.nff` walks the three envelope keys and finds no form at all.
 *
 * @command  vendor/bin/pest --testsuite=Unit tests/Unit/NinjaFormsValidatorTest.php
 */

/**
 * Build a minimal `.nff`-shaped export.
 *
 * @param  array<string, mixed>  $settings  Settings to merge over the defaults.
 * @return array<string, mixed>
 */
function nffExport(array $settings = []): array
{
    return [
        'settings' => \array_merge(['title' => 'T-Shirt Reservation'], $settings),
        'fields' => [['key' => 'firstname_1', 'type' => 'firstname']],
        'actions' => [['type' => 'email', 'active' => 1]],
    ];
}

it('accepts a single-form export envelope', function (): void {
    expect((new Validator)->validate(nffExport()))->toBeTrue();
});

it('still accepts a list of exports', function (): void {
    // Kept working in case an operator concatenates several exports into one file.
    expect((new Validator)->validate([nffExport(), nffExport()]))->toBeTrue();
});

it('rejects data that is not a Ninja Forms export', function (mixed $data): void {
    expect((new Validator)->validate($data))->toBeFalse();
})->with([
    'a Gravity Forms export' => [[['id' => 5, 'title' => 'Contact']]],
    'settings without fields' => [[['settings' => ['title' => 'x']]]],
    'fields that are not an array' => [['settings' => ['title' => 'x'], 'fields' => 'nope']],
    'an empty array' => [[]],
    'a string' => ['nope'],
    'null' => [null],
]);

it('reads the form title, the only human-readable identifier a .nff carries', function (): void {
    expect((new Validator)->formTitle(nffExport()))->toBe('T-Shirt Reservation');
});

it('returns no title when the export has none', function (mixed $data): void {
    expect((new Validator)->formTitle($data))->toBeNull();
})->with([
    'empty title' => [nffExport(['title' => ''])],
    'a title that is not a string' => [nffExport(['title' => 5])],
    'no settings' => [['fields' => []]],
    'settings that are not an array' => [['settings' => 'nope', 'fields' => []]],
    'not an export' => ['nope'],
    // Objects are refused before any read: indexing one throws, or reads through ArrayAccess
    'an object' => [new stdClass],
    'an array-like object' => [new ArrayObject(['settings' => ['title' => 'T']])],
    'settings that are an object' => [['settings' => new stdClass, 'fields' => []]],
    'settings that are an array-like object' => [['settings' => new ArrayObject(['title' => 'T']), 'fields' => []]],
]);

it('reads the form id out of embed_form when the export advertises one', function (string $embed, int $expected): void {
    expect((new Validator)->advertisedFormId(nffExport(['embed_form' => $embed])))->toBe($expected);
})->with([
    // Both quoting styles occur across the production exports.
    'single-quoted' => ["[ninja_form id='2']", 2],
    'unquoted' => ['[ninja_form id=3]', 3],
    'double-quoted, several digits' => ['[ninja_form id="12"]', 12],
]);

it('returns no id when the export advertises none', function (mixed $data): void {
    // ---
    // Not an error condition. `settings.embed_form` is an empty string on TWO of the four
    // production exports (Miracle Stars and Love Lights), and no `.nff` carries `id` or
    // `form_id` anywhere. So a null here is the ordinary case for half the corpus, and it
    // is why the file cannot be matched to a detected form ID automatically.
    // ---
    expect((new Validator)->advertisedFormId($data))->toBeNull();
})->with([
    'empty embed_form' => [nffExport(['embed_form' => ''])],
    'absent embed_form' => [nffExport()],
    'not an export' => [['id' => 5, 'title' => 'Contact']],
    'not an array' => ['nope'],
    'settings that are not an array' => [['settings' => 'nope', 'fields' => []]],
    'embed_form that is not a string' => [nffExport(['embed_form' => 2])],
    'embed_form holding another plugin shortcode' => [nffExport(['embed_form' => '[contact-form-7 id=4]'])],
    'embed_form whose id is not a number' => [nffExport(['embed_form' => "[ninja_form id='x']"])],
    'an object' => [new stdClass],
    'an array-like object' => [new ArrayObject(['settings' => ['embed_form' => '[ninja_form id=2]']])],
    'settings that are an object' => [['settings' => new stdClass, 'fields' => []]],
    'settings that are an array-like object' => [['settings' => new ArrayObject(['embed_form' => '[ninja_form id=2]']), 'fields' => []]],
]);

it('names its plugin for logging', function (): void {
    expect((new ReflectionProperty(Validator::class, 'pluginName'))->getValue(new Validator))->toBe('NinjaForms');
});

it('rejects a single export missing either key without reading the missing key', function (array $data): void {
    // Warnings become exceptions, so a read of an absent key fails rather than warns
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        expect((new Validator)->validate($data))->toBeFalse();
    } finally {
        restore_error_handler();
    }
})->with([
    'settings without fields' => [['settings' => ['title' => 'x']]],
    'fields without settings' => [['fields' => []]],
]);
