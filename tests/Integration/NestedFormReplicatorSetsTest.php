<?php

declare(strict_types=1);

/**
 * Pins that a Gravity Perks nested form carries its child's fields into the replicator.
 *
 * A `form`-type field maps to a `replicator`, and the replicator was written with no sets:
 * the nested-form path handed `$allFields` — a flat array of THIS form's fields — to
 * `extractAllFieldsFromForms()`, which looks for `$form['fields']`. That key is set on no
 * element of a field array, so it returned `[]` and the child's fields landed nowhere
 * (wordpress-importer-archive#1156).
 *
 * **The failure was silent in both directions, which is what this file exists to catch.**
 * A `replicator` with no sets is valid Statamic, the run reported nothing, and the
 * blueprint looked complete — only a submission revealed that the nested section captured
 * nothing. So an assertion on "the import succeeded" cannot see this; only an assertion on
 * the sets can.
 *
 * **Driven through the public `createBlueprintWithFields()` and read back off the saved
 * blueprint.** Re-implementing the resolution in the test would pass happily while the
 * production path drifted, which is the same reasoning `FormBlueprintSkipGuardTest`
 * records.
 *
 * **Integration rather than Unit, for that file's reason:** the work happens inside a
 * method that ends at `$blueprint->save()`, and the trait catches failures and returns
 * `false`, so a Unit-tier call would make the verdict unobservable. It needs no host app
 * — the per-process workbench points `BlueprintRepository` at this process's own
 * directory.
 *
 * @command  php vendor/bin/pest tests/Integration/NestedFormReplicatorSetsTest.php
 */

use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

/**
 * The child form, carrying the three fields the ticket measured on `registrar.uams.edu`.
 *
 * Each field carries `formId` 7, which is what `getChildFormFields()` matches on and what
 * makes these reachable only through the forms array rather than the parent's own fields.
 *
 * @return array<int|string, mixed>
 */
function nestedChildForm(): array
{
    return [
        'id' => '7',
        'title' => 'Transfer Credit Applied Nested Form',
        'fields' => [
            ['id' => '1', 'formId' => '7', 'type' => 'text', 'label' => 'Course Code'],
            ['id' => '2', 'formId' => '7', 'type' => 'text', 'label' => 'Course Title'],
            ['id' => '3', 'formId' => '7', 'type' => 'number', 'label' => 'Number of Credit Hours Applied'],
        ],
    ];
}

/**
 * The parent form's own fields: a plain control field, then the nested-form field.
 *
 * The control is not decoration — it makes a failed assertion distinguishable from a
 * blueprint that was never written at all.
 *
 * @return array<int|string, mixed>
 */
function nestedParentFields(): array
{
    return [
        ['id' => '5', 'formId' => '6', 'type' => 'text', 'label' => 'Student Name'],
        ['id' => '6', 'formId' => '6', 'type' => 'form', 'label' => 'Course Info', 'gpnfForm' => '7'],
    ];
}

/**
 * Create the parent blueprint and return its saved field configuration.
 *
 * @param  array<int|string, mixed>|null  $sourceForms  The forms array to resolve against
 * @return array<string, mixed> The blueprint's fields, keyed by handle
 */
function nestedBlueprintFields(
    ?array $sourceForms
): array {
    $handle = 'nested_form_test_'.\uniqid();

    $creator = new FormBlueprintCreator;
    $creator->createBlueprintWithFields(
        $handle,
        'Transfer Credit Applied',
        nestedParentFields(),
        'gravity-forms',
        '6',
        'default',
        $sourceForms
    );

    $blueprint = BlueprintFacade::find('forms.'.$handle);

    if (! $blueprint instanceof Blueprint) {
        return [];
    }

    $contents = $blueprint->contents();
    $tabs = $contents['tabs'] ?? [];
    $fields = [];

    // Walk the saved structure back down to handle => config.
    foreach (\is_array($tabs) ? $tabs : [] as $tab) {
        foreach (\is_array($tab) && \is_array($tab['sections'] ?? null) ? $tab['sections'] : [] as $section) {
            foreach (\is_array($section) && \is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
                if (\is_array($field) && \is_string($field['handle'] ?? null)) {
                    $fields[$field['handle']] = $field['field'] ?? null;
                }
            }
        }
    }

    return $fields;
}

/**
 * Read one field's saved configuration as a typed array.
 *
 * These accessors exist because the blueprint round-trips through `contents()`, which is
 * `mixed` all the way down — so every assertion below would otherwise be an offset access
 * on `mixed`, which level max refuses. Narrowing once here keeps the assertions readable
 * and keeps the narrowing honest: a shape that does not match yields an empty array and
 * fails the assertion, rather than being cast into looking right.
 *
 * @param  array<string, mixed>  $fields  The blueprint fields, keyed by handle
 * @param  string  $handle  The field handle to read
 * @return array<int|string, mixed> The field configuration, or empty when absent
 */
function nestedFieldConfig(
    array $fields,
    string $handle
): array {
    $config = $fields[$handle] ?? null;

    return \is_array($config) ? $config : [];
}

/**
 * Read a field's `sets` structure as a typed array.
 *
 * @param  array<string, mixed>  $fields  The blueprint fields, keyed by handle
 * @param  string  $handle  The field handle to read
 * @return array<int|string, mixed> The sets structure, or empty when absent
 */
function nestedSetsOf(
    array $fields,
    string $handle
): array {
    $sets = nestedFieldConfig($fields, $handle)['sets'] ?? null;

    return \is_array($sets) ? $sets : [];
}

/**
 * Walk the replicator's single generated set down to its list of fields.
 *
 * @param  array<string, mixed>  $fields  The blueprint fields, keyed by handle
 * @param  string  $handle  The replicator field handle
 * @return list<array<int|string, mixed>> The set's fields, or empty when the shape does not match
 */
function nestedSetFields(
    array $fields,
    string $handle
): array {
    $cursor = nestedSetsOf($fields, $handle);

    // Each step narrows before the next, so a missing level returns empty rather than
    // throwing — the assertion is what reports it.
    foreach (['new_set_group', 'sets', 'new_set', 'fields'] as $key) {
        $next = $cursor[$key] ?? null;

        if (! \is_array($next)) {
            return [];
        }

        $cursor = $next;
    }

    $setFields = [];

    foreach ($cursor as $entry) {
        if (\is_array($entry)) {
            $setFields[] = $entry;
        }
    }

    return $setFields;
}

/**
 * Read the first unresolved-nested-form warning as a typed array.
 *
 * @return array<int|string, mixed> The warning data, or empty when none was recorded
 */
function nestedFirstWarning(): array
{
    $rows = ImportWarningManager::getWarningsByCategory('forms')['unresolved_nested_forms'] ?? null;

    if (! \is_array($rows)) {
        return [];
    }

    $first = $rows[0] ?? null;

    return \is_array($first) ? $first : [];
}

it('carries the child form fields into the replicator sets', function (): void {
    $fields = nestedBlueprintFields([nestedChildForm()]);

    // The control: the parent's own field must be present, or the assertions below would
    // pass vacuously on a blueprint that failed to save.
    expect($fields)->toHaveKey('student_name_5');

    $replicator = nestedFieldConfig($fields, 'course_info_6');

    expect($replicator['type'] ?? null)->toBe('replicator')
        ->and(nestedSetsOf($fields, 'course_info_6'))->not->toBe([]);

    $setFields = nestedSetFields($fields, 'course_info_6');

    expect($setFields)->toHaveCount(3)
        ->and(\array_column($setFields, 'handle'))->toEqual([
            'course_code_1',
            'course_title_2',
            'number_of_credit_hours_applied_3',
        ]);
});

it('preserves each child field type and label', function (): void {
    $fields = nestedBlueprintFields([nestedChildForm()]);

    $byHandle = [];

    foreach (nestedSetFields($fields, 'course_info_6') as $entry) {
        $handle = $entry['handle'] ?? null;
        $config = $entry['field'] ?? null;

        if (\is_string($handle) && \is_array($config)) {
            $byHandle[$handle] = $config;
        }
    }

    // The number field is the one that proves types are not flattened to `text`. It
    // resolves to `float` rather than `integer` — Gravity Forms' `number` carries no
    // integer/decimal distinction, so `FieldtypeMapping` declares the wider type as its
    // default and narrowing it here would assert something the mapping does not promise.
    expect($byHandle['course_code_1']['type'] ?? null)->toBe('text')
        ->and($byHandle['course_code_1']['display'] ?? null)->toBe('Course Code')
        ->and($byHandle['course_title_2']['type'] ?? null)->toBe('text')
        ->and($byHandle['course_title_2']['display'] ?? null)->toBe('Course Title')
        ->and($byHandle['number_of_credit_hours_applied_3']['type'] ?? null)->toBe('float')
        ->and($byHandle['number_of_credit_hours_applied_3']['display'] ?? null)
        ->toBe('Number of Credit Hours Applied');
});

it('reports a warning when no sibling forms are supplied', function (): void {
    // This is the pre-#1156 state reached deliberately: without the forms array the child
    // is unreachable, and the point is that it now SAYS so instead of importing empty.
    $fields = nestedBlueprintFields(null);

    expect($fields)->toHaveKey('student_name_5')
        ->and(nestedSetsOf($fields, 'course_info_6'))->toBe([])
        ->and(ImportWarningManager::getWarningsByCategory('forms'))
        ->toHaveKey('unresolved_nested_forms');

    $reported = nestedFirstWarning();

    expect($reported['child_form_id'] ?? null)->toBe('7')
        ->and($reported['form_id'] ?? null)->toBe('6')
        ->and($reported['reason'] ?? null)->toContain('no sibling forms');
});

it('reports a warning when the child form is absent from the export', function (): void {
    // A forms array that exists but does not contain form 7 — the realistic case of a
    // partial export, which must not be confused with "nothing was supplied".
    $fields = nestedBlueprintFields([
        ['id' => '9', 'title' => 'Unrelated', 'fields' => [
            ['id' => '1', 'formId' => '9', 'type' => 'text', 'label' => 'Something Else'],
        ]],
    ]);

    expect(nestedSetsOf($fields, 'course_info_6'))->toBe([]);

    $reported = nestedFirstWarning();

    expect($reported['child_form_id'] ?? null)->toBe('7')
        ->and($reported['reason'] ?? null)->toContain('absent from the export');
});

it('reports a warning when the field names no child form at all', function (): void {
    $handle = 'nested_form_test_'.\uniqid();

    $creator = new FormBlueprintCreator;
    $creator->createBlueprintWithFields(
        $handle,
        'Transfer Credit Applied',
        [['id' => '6', 'formId' => '6', 'type' => 'form', 'label' => 'Course Info']],
        'gravity-forms',
        '6',
        'default',
        [nestedChildForm()]
    );

    $reported = nestedFirstWarning();

    expect($reported['child_form_id'] ?? null)->toBeNull()
        ->and($reported['reason'] ?? null)->toContain('declares no child form id');
});

it('emits no unresolved warning on the resolving path', function (): void {
    // The negative control for the three above: a warning that fires unconditionally
    // would satisfy every assertion in them and still be wrong.
    nestedBlueprintFields([nestedChildForm()]);

    $warnings = ImportWarningManager::getWarningsByCategory('forms');

    expect($warnings['unresolved_nested_forms'] ?? [])->toBe([]);
});
