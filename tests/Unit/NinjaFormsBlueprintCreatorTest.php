<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FieldtypeMapping;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormBlueprintCreator;

/**
 * Ninja-specific readers on the blueprint creator (#10).
 *
 * The shared FormBlueprintCreatorTrait assembles the blueprint; this class supplies four
 * readers, and each of the four differs from the Gravity Forms equivalent in a way that
 * would fail silently if inherited:
 *
 * - handles derive from `label`, because `key` carries a creation-timestamp suffix;
 * - `required` is lowercase, where Gravity Forms uses `isRequired`;
 * - a repeater's children live under `fields`, where Gravity Forms uses `inputs`.
 *
 * @command  vendor/bin/pest --testsuite=Unit tests/Unit/NinjaFormsBlueprintCreatorTest.php
 */

/**
 * Call a protected reader on the creator.
 */
function nfCreator(string $method, mixed ...$args): mixed
{
    $creator = new FormBlueprintCreator;
    $reflected = (new ReflectionClass($creator))->getMethod($method);
    $reflected->setAccessible(true);

    return $reflected->invoke($creator, ...$args);
}

/**
 * A repeater shaped like the waiver form's participant set.
 *
 * @return array<string, mixed>
 */
function nfRepeaterField(): array
{
    return [
        'key' => 'participant_waiver_1645112953805',
        'type' => 'repeater',
        'label' => 'Participant Waiver',
        'required' => 1,
        'fields' => [
            ['key' => 'first_name_1', 'type' => 'firstname', 'label' => "Participant's First Name", 'required' => 1],
            ['key' => 'signature_1', 'type' => 'textbox', 'label' => 'Signature', 'required' => 1],
        ],
    ];
}

it('derives handles from the label, not the timestamped key', function (): void {
    // `key` is `firstname_1642172426631`; using it would put the creation timestamp into
    // the Statamic blueprint handle.
    $field = ['key' => 'firstname_1642172426631', 'type' => 'firstname', 'label' => 'First Name'];

    expect(nfCreator('getFieldNameForHandle', $field))->toBe('First Name');
    expect(nfCreator('getFieldNameForDisplay', $field))->toBe('First Name');
});

it('returns an empty name when a field carries no label', function (): void {
    expect(nfCreator('getFieldNameForHandle', ['key' => 'x', 'type' => 'textbox']))->toBe('');
});

it('reads the required flag from Ninja\'s lowercase attribute', function (): void {
    // Gravity Forms uses `isRequired`; reading that here would treat every required
    // field as optional.
    expect(nfCreator('getRequiredAttributeName'))->toBe('required');
});

it('finds a repeater\'s children under fields, where the siblings use inputs', function (): void {
    // The waiver form is one repeater and nothing else, so reading `inputs` here would
    // drop every field it has.
    $result = nfCreator('getMappedSubfieldAttributes', nfRepeaterField(), 'NinjaForms');

    expect($result)->toBeArray();
});

it('returns no subfield attributes for a flat field', function (mixed $field): void {
    expect(nfCreator('getMappedSubfieldAttributes', $field, 'NinjaForms'))->toBe([]);
})->with([
    'no fields key' => [['key' => 'a', 'type' => 'textbox', 'label' => 'A']],
    'empty fields' => [['key' => 'a', 'type' => 'repeater', 'label' => 'A', 'fields' => []]],
    'fields not an array' => [['key' => 'a', 'type' => 'repeater', 'label' => 'A', 'fields' => 'nope']],
]);

it('resolves its mapping class from its own namespace', function (): void {
    // The trait derives the class from the namespace, so NinjaForms\FormBlueprintCreator
    // must reach NinjaForms\FieldtypeMapping without explicit wiring.
    expect(nfCreator('getMappingClass'))
        ->toBe('UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FieldtypeMapping');
});

it('maps a repeater field to a replicator through that mapping class', function (): void {
    // End-to-end through the resolved mapping: the type the waiver form depends on.
    expect(nfCreator('getMappedFieldType', nfRepeaterField(), 'NinjaForms'))->toBe('replicator');
});

it('builds a field handle from the key, in snake case without stray separators', function (string $key, string $expected): void {
    expect(nfCreator('generateFieldHandle', ['key' => $key, 'label' => 'Ignored']))->toBe($expected);
})->with([
    'separators collapsed and trimmed' => ['--Email-Address_1642--', 'email_address_1642'],
    'a dot' => ['Phone.Number', 'phone_number'],
]);

it('falls back to a generated handle when the key is missing, blank or not a string', function (array $field): void {
    // The trait's fallback names the field `field_` plus eight random characters.
    $handle = nfCreator('generateFieldHandle', $field);

    expect(\is_string($handle) ? $handle : '')->toMatch('/^field_[A-Za-z0-9]{8}$/');
})->with([
    'no key' => [['label' => 'First Name']],
    'a blank key' => [['key' => '   ', 'label' => 'First Name']],
    'a numeric key' => [['key' => 7, 'label' => 'First Name']],
]);

it('displays no name for a label that is not a string', function (): void {
    expect(nfCreator('getFieldNameForDisplay', ['key' => 'x', 'label' => 12]))->toBe('');
});

it('maps choice options in their export order, keeping the first of any repeated value', function (): void {
    $attributes = nfCreator('getMappedFieldAttributes', ['type' => 'listselect', 'label' => 'Pick', 'key' => 'pick_1', 'options' => [
        // `order` may be a string or an int; either is honored over the array position.
        ['label' => 'Second', 'value' => 'b', 'order' => '1'],
        ['label' => 'First', 'value' => 'a', 'order' => 0],
        // No usable label: skipped.
        ['label' => false, 'value' => 'x'],
        ['label' => '', 'value' => 'y'],
        ['label' => ['z'], 'value' => 'z'],
        'not an option',
        // No usable value: the label is what the form submits.
        ['label' => 5, 'value' => false],
        ['label' => 'Float', 'value' => 1.5],
        // A repeated value keeps the earlier option.
        ['label' => 'Dup', 'value' => 'a', 'order' => 9],
        // An unusable order falls back to the array position.
        ['label' => 'Arr order', 'value' => 'q', 'order' => ['x']],
        ['label' => 'Letters', 'value' => 'l', 'order' => 'abc'],
    ]], 'ninja-forms');

    expect(\is_array($attributes) ? ($attributes['options'] ?? null) : null)->toBe([
        'a' => 'First',
        'b' => 'Second',
        5 => '5',
        '1.5' => 'Float',
        'q' => 'Arr order',
        'l' => 'Letters',
    ]);
});

it('adds no options for a field with no choice list', function (mixed $options): void {
    $attributes = nfCreator('getMappedFieldAttributes', ['type' => 'listselect', 'label' => 'Pick', 'key' => 'pick_1', 'options' => $options], 'ninja-forms');

    expect(\is_array($attributes) && \array_key_exists('options', $attributes))->toBeFalse();
})->with([
    'none' => [null],
    'an empty list' => [[]],
    'only unusable entries' => [[['label' => '', 'value' => 'a']]],
]);

it('merges the attributes of every mapped repeater child', function (): void {
    // Ninja Forms ships no subfield mapping, so a repeater's children map to nothing today.
    // A mapping is set here to show the merge works once one exists, then restored.
    $mapping = new ReflectionProperty(FieldtypeMapping::class, 'subfieldMapping');
    $original = $mapping->getValue();
    $placeholder = ['placeholder' => ['statamic_attribute' => '[#subfield]_placeholder']];
    $mapping->setValue(null, ['fieldtypes' => ['repeater' => ['subfields' => [
        'Name' => ['handle' => 'name', 'attributes' => $placeholder],
        'Email' => ['handle' => 'email', 'attributes' => $placeholder],
    ]]]]);

    try {
        $result = nfCreator('getMappedSubfieldAttributes', ['type' => 'repeater', 'key' => 'rep_1', 'label' => 'People', 'fields' => [
            ['type' => 'textbox', 'label' => 'Name', 'placeholder' => 'Your name'],
            'not a field',
            // A child the mapping does not name contributes nothing.
            ['type' => 'textbox', 'label' => 'Unmapped', 'placeholder' => 'n/a'],
            ['type' => 'email', 'label' => 'Email', 'placeholder' => 'you@example.com'],
        ]], 'ninja-forms');
    } finally {
        $mapping->setValue(null, $original);
    }

    expect($result)->toBe(['name_placeholder' => 'Your name', 'email_placeholder' => 'you@example.com']);
});

it('ships no Ninja Forms subfield mapping, so a repeater child maps to nothing', function (): void {
    expect(nfCreator('getMappedSubfieldAttributes', nfRepeaterField(), 'ninja-forms'))->toBe([]);
});

it('reads a flat field without raising a PHP warning', function (array $field): void {
    // The early return is what keeps a missing or non-list `fields` out of the loop below.
    set_error_handler(static function (int $severity, string $message): never {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        $result = nfCreator('getMappedSubfieldAttributes', $field, 'ninja-forms');
    } finally {
        restore_error_handler();
    }

    expect($result)->toBe([]);
})->with([
    'no fields key' => [['key' => 'a', 'type' => 'repeater', 'label' => 'A']],
    'fields not an array' => [['key' => 'a', 'type' => 'repeater', 'label' => 'A', 'fields' => 'nope']],
]);

it('looks up a repeater with no usable type under the empty fieldtype', function (array $parent): void {
    $mapping = new ReflectionProperty(FieldtypeMapping::class, 'subfieldMapping');
    $original = $mapping->getValue();
    $mapping->setValue(null, ['fieldtypes' => ['' => ['subfields' => [
        'Name' => ['handle' => 'name', 'attributes' => ['placeholder' => ['statamic_attribute' => '[#subfield]_placeholder']]],
    ]]]]);

    try {
        $result = nfCreator('getMappedSubfieldAttributes', $parent + ['key' => 'rep_1', 'label' => 'People', 'fields' => [
            ['type' => 'textbox', 'label' => 'Name', 'placeholder' => 'Your name'],
        ]], 'ninja-forms');
    } finally {
        $mapping->setValue(null, $original);
    }

    expect($result)->toBe(['name_placeholder' => 'Your name']);
})->with([
    'no type' => [[]],
    'a type that is not a string' => [['type' => 5]],
]);
