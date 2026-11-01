<?php

declare(strict_types=1);

/**
 * Pins that a Formidable repeater carries its child form's fields into the replicator.
 *
 * A `divider` with `repeat` maps to a `replicator`, and the replicator was written with no
 * sets: the repeater path handed `$allFields` — a flat array of THIS form's fields — to
 * `extractAllFieldsFromForms()`, which looks for `$form['fields']`. That key is set on no
 * element of a field array, so it returned `[]` and the child's fields landed nowhere
 * (wordpress-importer-archive#1158). The Gravity instance of the same defect is wordpress-importer-archive#1156, pinned by
 * `NestedFormReplicatorSetsTest`.
 *
 * **The failure was silent in both directions, which is what this file exists to catch.**
 * A `replicator` with no sets is valid Statamic, the run reported nothing, and the
 * blueprint looked complete — only a submission revealed that the repeater captured
 * nothing. So an assertion on "the import succeeded" cannot see this; only an assertion on
 * the sets can.
 *
 * **It also pins the SECOND scope, which is the half the one-line fix gets wrong.**
 * `button_label` comes from the `end_divider` beside the divider in its OWN form, while
 * the set fields come from a sibling form. Before wordpress-importer-archive#1158 both lookups read one haystack, so
 * on the production path both were lost — the ticket measured the sets and the
 * `button_label` went with them. A fix that feeds the sibling forms to both restores the
 * sets and silently drops `button_label`, which is why that case is asserted here.
 *
 * **Driven through the public `createBlueprintWithFields()` and read back off the saved
 * blueprint**, for the reason the Gravity file records: re-implementing the resolution in
 * the test would pass happily while the production path drifted.
 *
 * The shapes are taken from the corpus rather than invented — `communications-uams-edu_web`
 * carries 17 repeaters of exactly this form, each naming a child form present in the same
 * export.
 *
 * @command  php vendor/bin/pest tests/Integration/FormidableRepeaterReplicatorSetsTest.php
 */

use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormBlueprintCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

/**
 * The child form, whose fields carry `form_id` 41 — what `getChildFormFields()` matches on
 * and what makes them reachable only through the forms array, never the parent's own.
 *
 * @return array<int|string, mixed>
 */
function formidableRepeaterChildForm(): array
{
    return [
        'id' => '41',
        'name' => 'Reference Webpages',
        'fields' => [
            ['id' => '201', 'form_id' => '41', 'type' => 'text', 'name' => 'Webpage Title'],
            ['id' => '202', 'form_id' => '41', 'type' => 'url', 'name' => 'Webpage URL'],
        ],
    ];
}

/**
 * The parent form's own fields: a control, the repeater divider, and its `end_divider`.
 *
 * The control is not decoration — it makes a failed assertion distinguishable from a
 * blueprint that was never written at all. The `end_divider` is the `button_label` source
 * and sits HERE, in the parent, which is the scope distinction this file pins.
 *
 * @param  string  $formSelect  The child form id the divider names; `'0'` names none
 * @return array<int|string, mixed>
 */
function formidableRepeaterParentFields(string $formSelect = '41'): array
{
    // `field_order` is load-bearing rather than decoration: `findEndDividerField()`
    // matches an `end_divider` in the same form whose order is GREATER than the
    // divider's, so a fixture omitting it leaves both at 0 and finds nothing. Every
    // field in the exports carries one.
    return [
        ['id' => '100', 'form_id' => '40', 'field_order' => '0', 'type' => 'text', 'name' => 'Submitter Name'],
        [
            'id' => '101',
            'form_id' => '40',
            'field_order' => '1',
            'type' => 'divider',
            'name' => 'Reference Webpages',
            'field_options' => '{"align":"block","form_select":"'.$formSelect.'","repeat":"1"}',
        ],
        [
            'id' => '102',
            'form_id' => '40',
            'field_order' => '2',
            'type' => 'end_divider',
            'name' => 'End',
            'field_options' => '{"add_label":"Add Reference Webpage"}',
        ],
    ];
}

/**
 * Create the parent blueprint and return its saved field configuration, keyed by handle.
 *
 * @param  array<int|string, mixed>|null  $sourceForms  The forms array to resolve against
 * @param  string  $formSelect  The child form id the divider names
 * @return array<string, mixed>
 */
function formidableRepeaterBlueprintFields(
    ?array $sourceForms,
    string $formSelect = '41'
): array {
    $handle = 'formidable_repeater_test_'.\uniqid();

    $creator = new FormBlueprintCreator;
    $creator->createBlueprintWithFields(
        $handle,
        'Provider Profile Request',
        formidableRepeaterParentFields($formSelect),
        'formidable-forms',
        '40',
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
 * Narrowing once here keeps the assertions readable and honest: a shape that does not
 * match yields an empty array and fails the assertion rather than being cast into looking
 * right.
 *
 * Formidable suffixes a generated handle with the field id — `submitter_name_100` —
 * so the expectations below carry it rather than the bare slug.
 *
 * @param  array<string, mixed>  $fields  The blueprint fields, keyed by handle
 * @param  string  $handle  The field handle to read
 * @return array<int|string, mixed>
 */
function formidableRepeaterFieldConfig(array $fields, string $handle): array
{
    $config = $fields[$handle] ?? null;

    return \is_array($config) ? $config : [];
}

/**
 * Every field name carried by the replicator's sets, flattened.
 *
 * @param  array<int|string, mixed>  $config  The replicator field configuration
 * @return list<string>
 */
function formidableRepeaterSetFieldHandles(array $config): array
{
    $handles = [];
    $sets = $config['sets'] ?? [];

    foreach (\is_array($sets) ? $sets : [] as $set) {
        if (! \is_array($set)) {
            continue;
        }

        // A set group carries its own nested `sets`; a set carries `fields`.
        $inner = \is_array($set['sets'] ?? null) ? $set['sets'] : [$set];

        foreach ($inner as $candidate) {
            if (! \is_array($candidate) || ! \is_array($candidate['fields'] ?? null)) {
                continue;
            }

            foreach ($candidate['fields'] as $field) {
                if (\is_array($field) && \is_string($field['handle'] ?? null)) {
                    $handles[] = $field['handle'];
                }
            }
        }
    }

    return $handles;
}

it('carries the child form fields into the repeater sets', function (): void {
    $fields = formidableRepeaterBlueprintFields([formidableRepeaterChildForm()]);

    // The control: the blueprint was written at all.
    expect($fields)->toHaveKey('submitter_name_100');

    $config = formidableRepeaterFieldConfig($fields, 'reference_webpages_101');
    expect($config['type'] ?? null)->toBe('replicator');

    $setHandles = formidableRepeaterSetFieldHandles($config);

    // The defect: these were empty. Both child fields must be present.
    expect($setHandles)->toContain('webpage_title_201');
    expect($setHandles)->toContain('webpage_url_202');
});

it('reads button_label from the end_divider in the parent form, not from the sibling forms', function (): void {
    $fields = formidableRepeaterBlueprintFields([formidableRepeaterChildForm()]);
    $config = formidableRepeaterFieldConfig($fields, 'reference_webpages_101');

    // The second scope. A fix that feeds the sibling forms to both lookups restores the
    // sets and drops this silently, because the `end_divider` is in the PARENT form.
    expect($config['button_label'] ?? null)->toBe('Add Reference Webpage');
});

it('reports a child form that is absent from the export rather than writing empty sets in silence', function (): void {
    // The divider names form 41, but no such form is among the exported forms.
    $fields = formidableRepeaterBlueprintFields([]);

    expect($fields)->toHaveKey('submitter_name_100');

    $config = formidableRepeaterFieldConfig($fields, 'reference_webpages_101');
    expect(formidableRepeaterSetFieldHandles($config))->toBe([]);

    $warnings = ImportWarningManager::getWarnings('forms', 'unresolved_nested_forms');
    expect($warnings)->not->toBe([]);
    expect(\json_encode($warnings))->toContain('41');
});

it('reports a repeater that names no child form at all', function (): void {
    // `form_select` of '0' is how Formidable records a repeater with nothing selected.
    $fields = formidableRepeaterBlueprintFields([formidableRepeaterChildForm()], '0');

    expect($fields)->toHaveKey('submitter_name_100');

    $config = formidableRepeaterFieldConfig($fields, 'reference_webpages_101');
    expect(formidableRepeaterSetFieldHandles($config))->toBe([]);

    expect(ImportWarningManager::getWarnings('forms', 'unresolved_nested_forms'))->not->toBe([]);
});
