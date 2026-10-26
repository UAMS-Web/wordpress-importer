<?php

declare(strict_types=1);

/**
 * The Formidable Forms blueprint creator's own readers and repeater handling (#98).
 *
 * `FormidableRepeaterReplicatorSetsTest` drives a repeater through a saved blueprint. This
 * file calls the creator's protected methods directly for the cases that leaves open: a
 * `repeat` option that is not `'1'` or cannot be decoded, end dividers in another form or
 * placed before the divider, child-form ids that are missing or not scalar, malformed sibling
 * forms, and the exact warnings an unresolved repeater records.
 *
 * @command  php vendor/bin/pest tests/Integration/FormidableFormsBlueprintCreatorTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormBlueprintCreator;
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
 * Call a protected method on a Formidable Forms blueprint creator.
 *
 * @param  string  $method  The method name
 * @param  ?array<int|string, mixed>  $sourceForms  The sibling forms the creator resolves repeaters against
 * @param  mixed  ...$args  The method's arguments
 * @return mixed What the method returned
 */
function ffbcCall(string $method, ?array $sourceForms, mixed ...$args): mixed
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
function ffbcWarnings(string $type): array
{
    $rows = ImportWarningManager::getWarningsByCategory('forms')[$type] ?? [];

    return \is_array($rows) ? \array_values($rows) : [];
}

/**
 * A repeater divider in form 40, naming child form 41.
 *
 * @param  mixed  $formSelect  The `form_select` option, or null to leave it out
 * @return array<string, mixed> The divider
 */
function ffbcDivider(mixed $formSelect = '41'): array
{
    $options = ['repeat' => '1'];

    if ($formSelect !== null) {
        $options['form_select'] = $formSelect;
    }

    return ['id' => 10, 'form_id' => '40', 'field_order' => '2', 'type' => 'divider', 'name' => 'Courses', 'field_options' => \json_encode($options)];
}

/**
 * The child form the divider names, with fields whose `form_id` is written as an int.
 *
 * @return array<string, mixed> The form
 */
function ffbcChildForm(): array
{
    return [
        'id' => 41,
        'name' => 'Courses',
        'fields' => [
            'not a field',
            ['id' => 20, 'form_id' => 41, 'type' => 'text', 'name' => 'Course Code'],
            // Section markers in the child form are not carried into the set.
            ['id' => 21, 'form_id' => 41, 'type' => 'divider', 'name' => 'Inner'],
            ['id' => 22, 'form_id' => 41, 'type' => 'end_divider', 'name' => 'Inner end'],
            ['id' => 23, 'form_id' => 41, 'type' => 'number', 'name' => 'Credit Hours'],
        ],
    ];
}

/**
 * The set fields' display labels from a mapped repeater, or null when it has no set.
 *
 * @param  mixed  $attributes  The mapped attributes
 * @return ?list<mixed> The labels
 */
function ffbcSetLabels(mixed $attributes): ?array
{
    $sets = \is_array($attributes) ? ($attributes['sets'] ?? null) : null;
    $group = \is_array($sets) ? ($sets['new_set_group'] ?? null) : null;
    $set = \is_array($group) && \is_array($group['sets'] ?? null) ? ($group['sets']['new_set'] ?? null) : null;

    if (! \is_array($set) || ! \is_array($set['fields'] ?? null)) {
        return null;
    }

    return \array_values(\array_map(
        static fn (mixed $entry): mixed => \is_array($entry) && \is_array($entry['field'] ?? null) ? $entry['field']['display'] ?? null : null,
        $set['fields']
    ));
}

it('names a field by its `name`, or by nothing when that is not a string', function (string $method): void {
    expect(ffbcCall($method, null, ['id' => 1, 'type' => 'text', 'name' => 'Email']))->toBe('Email')
        ->and(ffbcCall($method, null, ['id' => 1, 'type' => 'text', 'name' => 12]))->toBe('');
})->with(['getFieldNameForHandle', 'getFieldNameForDisplay']);

it('reads the required flag from the lowercase attribute and maps no subfields', function (): void {
    expect(ffbcCall('getRequiredAttributeName', null))->toBe('required')
        ->and(ffbcCall('getMappedSubfieldAttributes', null, ['id' => 1, 'type' => 'address', 'name' => 'Home'], 'formidable-forms'))->toBe([]);
});

it('records an unsupported attribute against the form ID as an int, or none', function (?string $formId, ?int $expected): void {
    ffbcCall('getMappedFieldAttributes', null, ['id' => 1, 'type' => 'text', 'name' => 'Name', 'notAFormidableAttribute' => 'x'], 'formidable-forms', [], 'Contact', 'Name', $formId);

    $rows = \array_values(\array_filter(ffbcWarnings('unsupported_attributes'), static fn (mixed $row): bool => \is_array($row) && ($row['attribute_name'] ?? null) === 'notAFormidableAttribute'));

    expect($rows)->toHaveCount(1)
        ->and(\array_key_exists('form_id', $rows[0]) && $rows[0]['form_id'] === $expected)->toBeTrue();
})->with([
    'a numeric ID' => ['12', 12],
    'an empty ID' => ['', null],
    'no ID' => [null, null],
]);

it('builds the repeater set from the child form, skipping its section markers', function (): void {
    $parent = [
        'not a field',
        ffbcDivider(),
        // An end divider in another form, or before the divider, is not this one's.
        ['id' => 11, 'form_id' => '99', 'field_order' => '5', 'type' => 'end_divider', 'field_options' => \json_encode(['add_label' => 'Wrong form'])],
        ['id' => 12, 'form_id' => 40, 'field_order' => '1', 'type' => 'end_divider', 'field_options' => \json_encode(['add_label' => 'Too early'])],
        ['id' => 13, 'form_id' => 40, 'field_order' => '3', 'type' => 'end_divider', 'field_options' => \json_encode(['add_label' => 'Add a course'])],
    ];
    $sourceForms = [
        'not a form',
        // A sibling whose `fields` is not a list contributes nothing rather than failing.
        ['id' => 42, 'name' => 'Broken', 'fields' => 'nope'],
        ffbcChildForm(),
    ];

    $attributes = ffbcCall('getMappedFieldAttributes', $sourceForms, ffbcDivider(), 'formidable-forms', $parent, 'Transfer', 'Courses', '40');
    $sets = \is_array($attributes) ? ($attributes['sets'] ?? null) : null;
    $group = \is_array($sets) ? ($sets['new_set_group'] ?? null) : null;

    expect(\is_array($attributes) ? ($attributes['button_label'] ?? null) : null)->toBe('Add a course')
        ->and(\is_array($sets) ? \array_keys($sets) : null)->toBe(['new_set_group'])
        ->and(\is_array($group) ? $group['display'] ?? null : null)->toBe('New Set Group')
        ->and(\is_array($group) && \is_array($group['sets'] ?? null) && \is_array($group['sets']['new_set'] ?? null) ? $group['sets']['new_set']['display'] ?? null : null)->toBe('New Set')
        ->and(ffbcSetLabels($attributes))->toBe(['Course Code', 'Credit Hours'])
        ->and(ffbcWarnings('unresolved_nested_forms'))->toBe([]);
});

it('gives a repeater no button label when its end divider carries none', function (mixed $options): void {
    $parent = [ffbcDivider(), ['id' => 13, 'form_id' => '40', 'field_order' => '3', 'type' => 'end_divider', 'field_options' => $options]];

    $attributes = ffbcCall('getMappedFieldAttributes', [ffbcChildForm()], ffbcDivider(), 'formidable-forms', $parent);

    expect(\is_array($attributes) && \array_key_exists('button_label', $attributes))->toBeFalse()
        ->and(ffbcSetLabels($attributes))->toBe(['Course Code', 'Credit Hours']);
})->with([
    'no add_label' => [\json_encode(['other' => 'x'])],
    'options that are not JSON' => ['not json'],
    'options that are not a string' => [['add_label' => 'x']],
]);

it('treats a divider as a repeater only when its options say repeat 1', function (mixed $options): void {
    $divider = ['id' => 10, 'form_id' => '40', 'type' => 'divider', 'name' => 'Section', 'field_options' => $options];

    $attributes = ffbcCall('getMappedFieldAttributes', [ffbcChildForm()], $divider, 'formidable-forms', [$divider]);

    expect(\is_array($attributes) && \array_key_exists('sets', $attributes))->toBeFalse()
        ->and(ffbcWarnings('unresolved_nested_forms'))->toBe([]);
})->with([
    'repeat 0' => [\json_encode(['repeat' => '0', 'form_select' => '41'])],
    'repeat as an int' => [\json_encode(['repeat' => 1, 'form_select' => '41'])],
    'no repeat' => [\json_encode(['form_select' => '41'])],
    'options that are not JSON' => ['not json'],
    'options that are not a string' => [['repeat' => '1']],
    'no options' => [null],
]);

it('records why a repeater imported empty when it names no usable child form', function (mixed $formSelect): void {
    $attributes = ffbcCall('getMappedFieldAttributes', [ffbcChildForm()], ffbcDivider($formSelect), 'formidable-forms', [], 'Transfer', 'Courses', '40');

    expect(\is_array($attributes) ? ($attributes['sets'] ?? null) : null)->toBe([])
        ->and(ffbcWarnings('unresolved_nested_forms'))->toBe([[
            'form_id' => '40',
            'form_title' => 'Transfer',
            'field_label' => 'Courses',
            'child_form_id' => null,
            'reason' => 'The repeater names no child form.',
            'consequence' => 'the repeater imported with no sets and will collect nothing',
        ]]);
})->with([
    'no form_select' => [null],
    'a form_select that is not scalar' => [['41']],
]);

it('records why a repeater imported empty when its child form is not in the export', function (): void {
    $attributes = ffbcCall('getMappedFieldAttributes', [ffbcChildForm()], ffbcDivider('77'), 'formidable-forms', [], 'Transfer', 'Courses', '40');

    expect(\is_array($attributes) ? ($attributes['sets'] ?? null) : null)->toBe([])
        ->and(ffbcWarnings('unresolved_nested_forms'))->toBe([[
            'form_id' => '40',
            'form_title' => 'Transfer',
            'field_label' => 'Courses',
            'child_form_id' => '77',
            'reason' => 'The child form was not found among the exported forms, or carries no fields.',
            'consequence' => 'the repeater imported with no sets and will collect nothing',
        ]]);
});

it('resolves a child form named by an int id', function (): void {
    $attributes = ffbcCall('getMappedFieldAttributes', [ffbcChildForm()], ffbcDivider(41), 'formidable-forms', []);

    expect(ffbcSetLabels($attributes))->toBe(['Course Code', 'Credit Hours']);
});

it('merges the attributes read from a field\'s JSON options', function (): void {
    $attributes = ffbcCall('getMappedFieldAttributes', null, ['id' => 1, 'type' => 'text', 'name' => 'Code', 'field_options' => \json_encode(['max' => '10', 'placeholder' => 'ABC-123'])], 'formidable-forms');

    expect(\is_array($attributes) ? [$attributes['character_limit'] ?? null, $attributes['placeholder'] ?? null] : null)->toBe([10, 'ABC-123']);
});

it('takes the first end divider in the divider\'s own form that comes after it', function (array $endDivider, ?string $label): void {
    $parent = [
        ffbcDivider(),
        // Placed ahead of the candidate so a looser match would pick it instead.
        ['id' => 30, 'form_id' => '40', 'field_order' => '9', 'type' => 'text', 'field_options' => \json_encode(['add_label' => 'Not a divider'])],
        ['id' => 31, 'field_order' => '9', 'type' => 'end_divider', 'field_options' => \json_encode(['add_label' => 'No form'])],
        ['id' => 32, 'form_id' => ['40'], 'field_order' => '9', 'type' => 'end_divider', 'field_options' => \json_encode(['add_label' => 'Form not scalar'])],
        ['id' => 33, 'form_id' => '40', 'field_order' => '9', 'type' => 7, 'field_options' => \json_encode(['add_label' => 'Type not a string'])],
        $endDivider + ['id' => 13, 'type' => 'end_divider'],
    ];

    $attributes = ffbcCall('getMappedFieldAttributes', [ffbcChildForm()], ffbcDivider(), 'formidable-forms', $parent);

    expect(\is_array($attributes) ? ($attributes['button_label'] ?? null) : null)->toBe($label);
})->with([
    'after it, form id written as an int' => [['form_id' => 40, 'field_order' => '3', 'field_options' => \json_encode(['add_label' => 'Add a course'])], 'Add a course'],
    'at the same position' => [['form_id' => '40', 'field_order' => '2', 'field_options' => \json_encode(['add_label' => 'Same position'])], null],
    // A position that is not a number counts as 0, so it comes before the divider at 2.
    'at a position that is not a number' => [['form_id' => '40', 'field_order' => 'abc', 'field_options' => \json_encode(['add_label' => 'Not a number'])], null],
    // With no position at all it also counts as 0.
    'with no position' => [['form_id' => '40', 'field_options' => \json_encode(['add_label' => 'No position'])], null],
]);

it('places a divider with no usable position at 0', function (mixed $order): void {
    $divider = ['field_order' => $order, 'form_id' => 40] + ffbcDivider();
    $parent = [$divider, ['id' => 13, 'form_id' => '40', 'field_order' => '1', 'type' => 'end_divider', 'field_options' => \json_encode(['add_label' => 'After zero'])]];

    $attributes = ffbcCall('getMappedFieldAttributes', [ffbcChildForm()], $divider, 'formidable-forms', $parent);

    expect(\is_array($attributes) ? ($attributes['button_label'] ?? null) : null)->toBe('After zero');
})->with([
    'a position that is not a number' => ['abc'],
    'a position that is not scalar' => [['2']],
]);

it('matches no end divider when the divider names no form', function (): void {
    $divider = \array_diff_key(ffbcDivider(), ['form_id' => true]);
    $parent = [$divider, ['id' => 13, 'form_id' => '40', 'field_order' => '3', 'type' => 'end_divider', 'field_options' => \json_encode(['add_label' => 'Other form'])]];

    $attributes = ffbcCall('getMappedFieldAttributes', [ffbcChildForm()], $divider, 'formidable-forms', $parent);

    expect(\is_array($attributes) && \array_key_exists('button_label', $attributes))->toBeFalse();
});

it('skips a child field whose type only loosely equals a section marker', function (): void {
    $child = ffbcChildForm();
    $fields = \is_array($child['fields']) ? $child['fields'] : [];
    // `true == 'divider'` in a loose comparison, so the marker check must be strict.
    $fields[] = ['id' => 24, 'form_id' => 41, 'type' => true, 'name' => 'Loose'];
    $child['fields'] = $fields;

    $labels = ffbcSetLabels(ffbcCall('getMappedFieldAttributes', [$child], ffbcDivider(), 'formidable-forms', []));

    // A field whose type is `true` imports as a text field, so it stays in the set.
    expect($labels)->toBe(['Course Code', 'Credit Hours', 'Loose']);
});

it('reads no child form id from options that are missing or not a string', function (mixed $options): void {
    expect(ffbcCall('getChildFormId', null, ['id' => 10, 'type' => 'divider', 'field_options' => $options]))->toBeNull();
})->with([
    'options that are not a string' => [['form_select' => '41']],
    'no options' => [null],
    'options that are not JSON' => ['not json'],
]);

it('counts a missing position as exactly 0 on either divider', function (mixed $dividerOrder, mixed $endOrder, ?string $label): void {
    $divider = ['field_order' => $dividerOrder] + ffbcDivider();
    $end = ['id' => 13, 'form_id' => '40', 'type' => 'end_divider', 'field_options' => \json_encode(['add_label' => 'Matched'])];

    if ($endOrder !== null) {
        $end['field_order'] = $endOrder;
    }

    $attributes = ffbcCall('getMappedFieldAttributes', [ffbcChildForm()], $divider, 'formidable-forms', [$divider, $end]);

    expect(\is_array($attributes) ? ($attributes['button_label'] ?? null) : null)->toBe($label);
})->with([
    // The end divider has to come strictly after the divider.
    'divider unpositioned, end divider at 0' => [['x'], '0', null],
    'divider at 0, end divider unpositioned' => ['0', null, null],
    'divider at -1, end divider unpositioned' => ['-1', null, 'Matched'],
]);
