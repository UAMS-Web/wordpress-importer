<?php

declare(strict_types=1);

/**
 * Tests for NinjaFormsShortcodeProcessor::parse(), which turns a
 * `[ninja_form id="N"]` shortcode into a form Bard set.
 *
 * The set carries the created Ninja Forms handle when one exists, otherwise an
 * import note naming the form ID, and the form ID is tracked for import. This
 * is in the light Statamic band because the set's row ID comes from Statamic's
 * RowId fieldtype.
 *
 * @command  php vendor/bin/pest tests/Integration/NinjaFormsShortcodeProcessorTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\NinjaFormsShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Replace one of the processor's created-handle caches.
 *
 * @param  string  $property  The static cache property
 * @param  array<array-key, string>  $handles  Form ID => Statamic form handle
 */
function setNinjaShortcodeHandles(string $property, array $handles): void
{
    (new ReflectionProperty(NinjaFormsShortcodeProcessor::class, $property))->setValue(null, $handles);
}

/**
 * Reset the processor's tracked form IDs and every created-handle cache.
 */
function resetNinjaShortcodeState(): void
{
    NinjaFormsShortcodeProcessor::clearFormIds();

    foreach (['createdNinjaFormsHandles', 'createdGravityFormsHandles', 'createdFormidableFormsHandles'] as $property) {
        setNinjaShortcodeHandles($property, []);
    }
}

/**
 * Parse a shortcode with the given attributes.
 *
 * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
 * @return array<int|string, mixed> The Bard set
 */
function parseNinjaShortcode(array $attributes): array
{
    return (new NinjaFormsShortcodeProcessor)->parse(
        $attributes,
        'default',
        (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor(),
        new BufferedOutput,
        [],
        'contact'
    );
}

/**
 * Read a key from a Bard set's attrs, or null when the set has no attrs array.
 *
 * @param  array<int|string, mixed>  $set  The Bard set
 * @param  string  $key  The attrs key
 * @return mixed The value, or null
 */
function ninjaSetAttr(array $set, string $key): mixed
{
    $attrs = $set['attrs'] ?? null;

    return \is_array($attrs) ? ($attrs[$key] ?? null) : null;
}

beforeEach(fn () => resetNinjaShortcodeState());

afterEach(fn () => resetNinjaShortcodeState());

it('emits a form set with an import note naming the Ninja Forms form ID', function (): void {
    $set = parseNinjaShortcode(['id' => '5']);

    expect($set['type'] ?? null)->toBe('set')
        ->and(ninjaSetAttr($set, 'values'))->toBe([
            'type' => 'form',
            'import_wordpress_notes' => 'Ninja Forms form ID 5',
        ])
        ->and(ninjaSetAttr($set, 'id'))->toBeString()->not->toBe('')
        ->and(NinjaFormsShortcodeProcessor::getFormIds())->toBe(['5']);
});

it('uses the created Ninja Forms handle, not another plugin\'s handle for the same ID', function (): void {
    setNinjaShortcodeHandles('createdGravityFormsHandles', ['5' => 'gravity_handle']);
    setNinjaShortcodeHandles('createdFormidableFormsHandles', ['5' => 'formidable_handle']);

    $noteOnly = parseNinjaShortcode(['id' => '5']);

    setNinjaShortcodeHandles('createdNinjaFormsHandles', ['5' => 'newsletter']);

    $withHandle = parseNinjaShortcode(['id' => 5]);

    expect(ninjaSetAttr($noteOnly, 'values'))->toBe([
        'type' => 'form',
        'import_wordpress_notes' => 'Ninja Forms form ID 5',
    ])
        ->and(ninjaSetAttr($withHandle, 'values'))->toBe([
            'type' => 'form',
            'form' => 'newsletter',
        ]);
});

it('notes a missing form ID without tracking one', function (array $attributes): void {
    $set = parseNinjaShortcode($attributes);

    expect(ninjaSetAttr($set, 'values'))->toBe([
        'type' => 'form',
        'import_wordpress_notes' => 'Ninja Forms form ID not specified',
    ])
        ->and(NinjaFormsShortcodeProcessor::getFormIds())->toBe([]);
})->with([
    'no id' => [[]],
    'empty id' => [['id' => '']],
]);

it('ignores a handle cached under an empty ID when the shortcode names none', function (array $attributes): void {
    setNinjaShortcodeHandles('createdNinjaFormsHandles', ['' => 'stray']);

    expect(ninjaSetAttr(parseNinjaShortcode($attributes), 'values'))->toBe([
        'type' => 'form',
        'import_wordpress_notes' => 'Ninja Forms form ID not specified',
    ]);
})->with([
    'no id' => [[]],
    'empty id' => [['id' => '']],
]);

it('keeps a form ID that is not numeric, and the string form of a float', function (mixed $id, string $expected): void {
    $set = parseNinjaShortcode(['id' => $id]);

    expect(ninjaSetAttr($set, 'values'))->toBe([
        'type' => 'form',
        'import_wordpress_notes' => "Ninja Forms form ID {$expected}",
    ])
        ->and(NinjaFormsShortcodeProcessor::getFormIds())->toBe([$expected]);
})->with([
    'a slug' => ['contact-us', 'contact-us'],
    'a float' => [7.5, '7.5'],
]);

it('drops an ID that is neither an int, a string nor a float', function (): void {
    expect(ninjaSetAttr(parseNinjaShortcode(['id' => true]), 'values'))->toBe([
        'type' => 'form',
        'import_wordpress_notes' => 'Ninja Forms form ID not specified',
    ])
        ->and(NinjaFormsShortcodeProcessor::getFormIds())->toBe([]);
});

it('gives each set its own row ID', function (): void {
    $first = parseNinjaShortcode(['id' => '1']);
    $second = parseNinjaShortcode(['id' => '1']);

    expect(ninjaSetAttr($first, 'id'))->not->toBe(ninjaSetAttr($second, 'id'));
});
