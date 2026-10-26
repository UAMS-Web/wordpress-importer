<?php

declare(strict_types=1);

/**
 * The Gravity Forms blueprint creator's own readers and nested-form handling (#98).
 *
 * `NestedFormReplicatorSetsTest` drives nested forms through a saved blueprint, and the
 * `GravityForms*` unit tests cover the mapping tables. This file calls the creator's
 * protected methods directly for the cases those leave open: integer and string ids on
 * either side of a lookup, child-form ids that are empty or not strings, malformed sibling
 * forms, and the exact warning a nested form that cannot be resolved records.
 *
 * @command  php vendor/bin/pest tests/Integration/GravityFormsBlueprintCreatorTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

/**
 * Call a protected method on a Gravity Forms blueprint creator.
 *
 * @param  string  $method  The method name
 * @param  ?array<int|string, mixed>  $sourceForms  The sibling forms the creator resolves nested forms against
 * @param  mixed  ...$args  The method's arguments
 * @return mixed What the method returned
 */
function gfbcCall(string $method, ?array $sourceForms, mixed ...$args): mixed
{
    $creator = new FormBlueprintCreator;
    (new ReflectionProperty($creator, 'sourceForms'))->setValue($creator, $sourceForms);

    return (new ReflectionMethod($creator, $method))->invoke($creator, ...$args);
}

/**
 * The rows recorded under one forms warning type.
 *
 * @param  string  $type  The warning type
 * @return list<mixed> The rows
 */
function gfbcWarnings(string $type): array
{
    $rows = ImportWarningManager::getWarningsByCategory('forms')[$type] ?? [];

    return \is_array($rows) ? \array_values($rows) : [];
}

/**
 * A nested-form child form, with fields whose `formId` is written as an int.
 *
 * @return array<string, mixed> The form
 */
function gfbcChildForm(): array
{
    return [
        'id' => 7,
        'title' => 'Courses',
        'fields' => [
            ['id' => 1, 'formId' => 7, 'type' => 'text', 'label' => 'Course Code'],
            // A nested form inside a nested form is not carried into the set.
            ['id' => 2, 'formId' => 7, 'type' => 'form', 'label' => 'Deeper', 'gpnfForm' => '9'],
            ['id' => 3, 'formId' => 7, 'type' => 'number', 'label' => 'Credit Hours'],
        ],
    ];
}

it('names a field with no string label by an empty name', function (string $method): void {
    expect(gfbcCall($method, null, ['id' => 1, 'type' => 'text', 'label' => 12]))->toBe('');
})->with(['getFieldNameForHandle', 'getFieldNameForDisplay']);

it('records an unsupported attribute against the form ID as an int, or none', function (?string $formId, ?int $expected): void {
    gfbcCall('getMappedFieldAttributes', null, ['id' => 1, 'type' => 'text', 'label' => 'Name', 'notAGravityAttribute' => 'x'], 'gravity-forms', [], 'Contact', 'Name', $formId);

    $rows = \array_values(\array_filter(gfbcWarnings('unsupported_attributes'), static fn (mixed $row): bool => \is_array($row) && ($row['attribute_name'] ?? null) === 'notAGravityAttribute'));

    expect($rows)->toHaveCount(1)
        ->and(\array_key_exists('form_id', $rows[0]) && $rows[0]['form_id'] === $expected)->toBeTrue();
})->with([
    'a numeric ID' => ['12', 12],
    'an empty ID' => ['', null],
    'no ID' => [null, null],
]);

it('caps both single-file upload types at one file', function (string $type): void {
    $attributes = gfbcCall('getMappedFieldAttributes', null, ['id' => 1, 'type' => $type, 'label' => 'Photo'], 'gravity-forms');

    expect(\is_array($attributes) ? ($attributes['max_files'] ?? null) : null)->toBe(1);
})->with(['fileupload', 'post_image']);

it('caps no other field, even one whose type only loosely equals an upload type', function (mixed $type): void {
    // `true == 'fileupload'` in a loose comparison, so the type check must be strict.
    $attributes = gfbcCall('getMappedFieldAttributes', null, ['id' => 1, 'type' => $type, 'label' => 'Photo'], 'gravity-forms');

    expect(\is_array($attributes) && \array_key_exists('max_files', $attributes))->toBeFalse();
})->with([
    'a text field' => ['text'],
    'a boolean type' => [true],
]);

it('points an option field at its product, matching ids however they are written', function (): void {
    $allFields = [
        'not a field',
        ['id' => '43', 'type' => 'product', 'label' => 'Adult Ticket'],
        ['id' => 44, 'type' => 'product', 'label' => 'Child Ticket'],
    ];

    $attributes = gfbcCall('getMappedFieldAttributes', null, ['id' => 45, 'type' => 'option', 'label' => 'Size', 'productField' => 44], 'gravity-forms', $allFields);
    $expected = gfbcCall('generateFieldHandle', null, $allFields[2]);

    expect($expected)->toBeString()->not->toBe('')
        ->and(\is_array($attributes) ? ($attributes['product_field'] ?? null) : null)->toBe($expected);
});

it('leaves an option field with an empty product reference unlinked', function (): void {
    $attributes = gfbcCall('getMappedFieldAttributes', null, ['id' => 45, 'type' => 'option', 'label' => 'Size', 'productField' => ''], 'gravity-forms', [['id' => '', 'type' => 'product', 'label' => 'Blank']]);

    expect(\is_array($attributes) && \array_key_exists('product_field', $attributes))->toBeFalse();
});

it('builds the replicator set from the child form, skipping its own nested forms', function (): void {
    $sourceForms = [
        // A sibling whose `fields` is not a list contributes nothing rather than failing.
        ['id' => 8, 'title' => 'Broken', 'fields' => 'nope'],
        'not a form',
        gfbcChildForm(),
    ];

    $attributes = gfbcCall('getMappedFieldAttributes', $sourceForms, ['id' => 6, 'formId' => 6, 'type' => 'form', 'label' => 'Courses', 'gpnfForm' => '7'], 'gravity-forms', [], 'Transfer', 'Courses', '6');
    $sets = \is_array($attributes) ? ($attributes['sets'] ?? null) : null;
    $group = \is_array($sets) ? ($sets['new_set_group'] ?? null) : null;
    $set = \is_array($group) && \is_array($group['sets'] ?? null) ? ($group['sets']['new_set'] ?? null) : null;
    $fields = \is_array($set) && \is_array($set['fields'] ?? null) ? $set['fields'] : [];

    expect(\is_array($sets) ? \array_keys($sets) : null)->toBe(['new_set_group'])
        ->and(\is_array($group) ? $group['display'] ?? null : null)->toBe('New Set Group')
        ->and(\is_array($group) && \is_array($group['sets'] ?? null) ? \array_keys($group['sets']) : null)->toBe(['new_set'])
        ->and(\is_array($set) ? $set['display'] ?? null : null)->toBe('New Set')
        ->and(\array_map(static fn (mixed $entry): mixed => \is_array($entry) && \is_array($entry['field'] ?? null) ? $entry['field']['display'] ?? null : null, $fields))->toBe(['Course Code', 'Credit Hours'])
        ->and(gfbcWarnings('unresolved_nested_forms'))->toBe([]);
});

it('resolves a child form named by an int id', function (): void {
    $attributes = gfbcCall('getMappedFieldAttributes', [gfbcChildForm()], ['id' => 6, 'type' => 'form', 'label' => 'Courses', 'gpnfForm' => 7], 'gravity-forms');
    $sets = \is_array($attributes) ? ($attributes['sets'] ?? null) : null;

    expect(\is_array($sets) ? \array_keys($sets) : null)->toBe(['new_set_group']);
});

it('records why a nested form imported empty when it names no usable child form', function (mixed $gpnfForm): void {
    $attributes = gfbcCall('getMappedFieldAttributes', [gfbcChildForm()], ['id' => 6, 'type' => 'form', 'label' => 'Courses', 'gpnfForm' => $gpnfForm], 'gravity-forms', [], 'Transfer', 'Courses', '6');

    expect(\is_array($attributes) ? ($attributes['sets'] ?? null) : null)->toBe([])
        ->and(gfbcWarnings('unresolved_nested_forms'))->toBe([[
            'form_id' => '6',
            'form_title' => 'Transfer',
            'field_label' => 'Courses',
            'child_form_id' => null,
            'reason' => 'the field declares no child form id',
            'consequence' => 'the nested section imported with no fields and will collect nothing',
        ]]);
})->with([
    'an empty id' => [''],
    'false' => [false],
    'an array' => [['7']],
    'none' => [null],
]);

it('looks up a composite field with no usable type under the empty fieldtype', function (array $field): void {
    // A mapping keyed by the empty fieldtype is set here to make the lookup visible, then restored.
    $mapping = new ReflectionProperty(FieldtypeMapping::class, 'subfieldMapping');
    $original = $mapping->getValue();
    $mapping->setValue(null, ['fieldtypes' => ['' => ['subfields' => [
        'First' => ['handle' => 'first', 'attributes' => ['placeholder' => ['statamic_attribute' => '[#subfield]_placeholder']]],
    ]]]]);

    try {
        $result = gfbcCall('getMappedSubfieldAttributes', null, $field + ['id' => 1, 'label' => 'Name', 'inputs' => [
            'not an input',
            ['id' => '1.3', 'label' => 'First', 'placeholder' => 'Given name'],
        ]], 'gravity-forms');
    } finally {
        $mapping->setValue(null, $original);
    }

    expect($result)->toBe(['first_placeholder' => 'Given name']);
})->with([
    'no type' => [[]],
    'a type that is not a string' => [['type' => 5]],
]);
