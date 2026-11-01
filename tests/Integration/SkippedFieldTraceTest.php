<?php

declare(strict_types=1);

/**
 * Pins the trace a skipped form field leaves in the imported blueprint.
 *
 * wordpress-importer-archive#815 stopped an unsupported field importing as a `text` field, which left an editor
 * with no sign anything had been dropped. wordpress-importer-archive#831 decided a skipped field leaves a trace,
 * scoped by a new `trace_when_skipped` flag to the types whose absence changes what the
 * form DOES — a form that took payment silently becoming one that does not.
 *
 * **The trace is a blueprint SECTION's `instructions`, and two separate measurements
 * put it there.** It is not a `section` FIELD: a form blueprint's fields ARE the public
 * form, and the host renders a section field through
 * `vendor/statamic/forms/fields/section.antlers.html` as a visible heading, so a note in
 * one would be shown to every visitor. And it is not the TAB's instructions either,
 * which is where it first shipped: measured against a booted Control Panel, a tab-level
 * key is not displayed while a section-level key is, with a no-instructions control
 * proving the probe could see the string at all (wordpress-importer-archive#857).
 *
 * @command  php vendor/bin/pest tests/Integration/SkippedFieldTraceTest.php
 */

use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormBlueprintCreator as FormidableCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator as GravityCreator;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Build a blueprint from the given fields and return its saved contents.
 *
 * @param  GravityCreator|FormidableCreator  $creator  The plugin's blueprint creator
 * @param  array<int, array<string, mixed>>  $fields  The WordPress field definitions
 * @return array<string, mixed> The blueprint contents as stored
 */
function traceBuild(GravityCreator|FormidableCreator $creator, string $plugin, array $fields, string $handle): array
{
    $created = $creator->createBlueprintWithFields(
        $handle,
        'Trace Probe',
        $fields,
        $plugin,
        '831',
        null
    );

    expect($created)->toBeTrue();

    $blueprint = BlueprintFacade::find('forms.'.$handle);

    if (! $blueprint instanceof Blueprint) {
        throw new RuntimeException('The trace-probe blueprint was not saved.');
    }

    // Narrow to the string-keyed shape the helpers below declare, rather than
    // asserting it with an inline annotation.
    $narrowed = [];

    foreach ($blueprint->contents() as $key => $value) {
        if (\is_string($key)) {
            $narrowed[$key] = $value;
        }
    }

    return $narrowed;
}

/**
 * Read the first SECTION's instructions from blueprint contents.
 *
 * This is where the note lives and where the Control Panel displays it. The tab's own
 * `instructions` key is a different level and is read by `traceTabInstructions()`,
 * which exists to assert the note is NOT there.
 *
 * @param  array<string, mixed>  $contents  The blueprint contents as stored
 * @return string|null The note, or null when the blueprint carries none
 */
function traceInstructions(array $contents): ?string
{
    $sections = traceSections($contents);

    if (! \is_array($sections[0] ?? null)) {
        return null;
    }

    $value = $sections[0]['instructions'] ?? null;

    return \is_string($value) ? $value : null;
}

/**
 * Read TAB-level instructions, the location the note must no longer use.
 *
 * Kept so a regression back to the tab is detectable rather than silent: the value
 * would still round-trip and every storage assertion would still pass, while no editor
 * would ever see it.
 *
 * @param  array<string, mixed>  $contents  The blueprint contents as stored
 * @return string|null The tab-level note, which must always be null
 */
function traceTabInstructions(array $contents): ?string
{
    $tabs = $contents['tabs'] ?? [];

    if (! \is_array($tabs) || $tabs === []) {
        return null;
    }

    $first = $tabs[\array_key_first($tabs)] ?? [];

    if (! \is_array($first)) {
        return null;
    }

    $value = $first['instructions'] ?? null;

    return \is_string($value) ? $value : null;
}

/**
 * Read the sections array from blueprint contents, narrowing at each step.
 *
 * @param  array<string, mixed>  $contents  The blueprint contents as stored
 * @return array<int, mixed> The sections, or an empty array when the shape differs
 */
function traceSections(array $contents): array
{
    $tabs = $contents['tabs'] ?? null;

    if (! \is_array($tabs) || ! \is_array($tabs['main'] ?? null)) {
        return [];
    }

    $sections = $tabs['main']['sections'] ?? null;

    return \is_array($sections) ? \array_values($sections) : [];
}

/**
 * Read the raw field definitions from blueprint contents.
 *
 * Distinct from `traceSections()`: the note lives ON the section, so encoding a section
 * finds it by design. Only the FIELDS are the public form, so only the fields are what
 * a leak assertion may look at.
 *
 * @param  array<string, mixed>  $contents  The blueprint contents as stored
 * @return array<int, mixed> The field definitions, in blueprint order
 */
function traceFields(array $contents): array
{
    $sections = traceSections($contents);

    if (! \is_array($sections[0] ?? null)) {
        return [];
    }

    $fields = $sections[0]['fields'] ?? null;

    return \is_array($fields) ? \array_values($fields) : [];
}

/**
 * Read the field handles from blueprint contents, narrowing at each step.
 *
 * @param  array<string, mixed>  $contents  The blueprint contents as stored
 * @return array<int, string> The handles, in blueprint order
 */
function traceFieldHandles(array $contents): array
{
    $tabs = $contents['tabs'] ?? null;

    if (! \is_array($tabs)) {
        return [];
    }

    $main = $tabs['main'] ?? null;

    if (! \is_array($main)) {
        return [];
    }

    $sections = $main['sections'] ?? null;

    if (! \is_array($sections) || ! \is_array($sections[0] ?? null)) {
        return [];
    }

    $fields = $sections[0]['fields'] ?? null;

    if (! \is_array($fields)) {
        return [];
    }

    $handles = [];

    foreach ($fields as $field) {
        if (\is_array($field) && \is_string($field['handle'] ?? null)) {
            $handles[] = $field['handle'];
        }
    }

    return $handles;
}

it('notes a dropped field that changes what the form does', function (): void {
    $contents = traceBuild(new GravityCreator, 'gravity_forms', [
        ['id' => '1', 'label' => 'Full Name', 'type' => 'text'],
        ['id' => '2', 'label' => 'How Many', 'type' => 'quantity'],
    ], 'trace_dropped');

    $note = traceInstructions($contents);

    expect($note)->not->toBeNull();
    expect($note)->toContain('How Many (quantity)');
    expect($note)->toContain('no longer collects');
});

it('leaves no note when nothing that traces was dropped', function (): void {
    $contents = traceBuild(new GravityCreator, 'gravity_forms', [
        ['id' => '1', 'label' => 'Full Name', 'type' => 'text'],
        ['id' => '2', 'label' => 'Email', 'type' => 'email'],
    ], 'trace_clean');

    // A form that lost nothing must be indistinguishable from one imported before this.
    expect(traceInstructions($contents))->toBeNull();
});

it('leaves no note when the only dropped field is a captcha', function (): void {
    $contents = traceBuild(new GravityCreator, 'gravity_forms', [
        ['id' => '1', 'label' => 'Full Name', 'type' => 'text'],
        ['id' => '2', 'label' => 'Are You Human', 'type' => 'captcha'],
    ], 'trace_captcha');

    // The captcha IS dropped -- control below -- but carries nothing an editor can act on.
    expect(traceInstructions($contents))->toBeNull();

    $handles = traceFieldHandles($contents);

    expect($handles)->toHaveCount(1);
    expect($handles[0])->toStartWith('full_name');
});

it('applies to Formidable Forms on the same terms, since the trait is shared', function (): void {
    $contents = traceBuild(new FormidableCreator, 'formidable_forms', [
        ['id' => '1', 'field_key' => 'name', 'name' => 'Full Name', 'type' => 'text'],
        ['id' => '2', 'field_key' => 'card', 'name' => 'Card', 'type' => 'credit_card'],
    ], 'trace_formidable');

    $note = traceInstructions($contents);

    expect($note)->not->toBeNull();
    expect($note)->toContain('credit_card');
});

it('writes the note where the Control Panel reads it, and not where it does not', function (): void {
    // ---
    // Measured against a booted CP with three arms: no-instructions control shows
    // nothing, TAB-level is not displayed, SECTION-level is. Storing the note on the tab
    // would round-trip and pass every other assertion in this file while being invisible
    // to the only audience it has, so the tab location is pinned as an absence (wordpress-importer-archive#857).
    // ---
    $contents = traceBuild(new GravityCreator, 'gravity_forms', [
        ['id' => '1', 'label' => 'Full Name', 'type' => 'text'],
        ['id' => '2', 'label' => 'How Many', 'type' => 'quantity'],
    ], 'trace_location');

    expect(traceInstructions($contents))->toContain('How Many (quantity)');
    expect(traceTabInstructions($contents))->toBeNull();
});

it('never adds a field, so the note cannot reach the public form', function (): void {
    // ---
    // The guard that matters. A form blueprint's fields are the public form, and the
    // host renders a `section` as a visible heading -- so a note carried in a FIELD
    // would be shown to every visitor. Counting fields is what pins that it is not.
    // ---
    $withDrop = traceBuild(new GravityCreator, 'gravity_forms', [
        ['id' => '1', 'label' => 'Full Name', 'type' => 'text'],
        ['id' => '2', 'label' => 'How Many', 'type' => 'quantity'],
    ], 'trace_fieldcount_drop');

    $without = traceBuild(new GravityCreator, 'gravity_forms', [
        ['id' => '1', 'label' => 'Full Name', 'type' => 'text'],
    ], 'trace_fieldcount_clean');

    expect(traceInstructions($withDrop))->not->toBeNull();
    expect(traceFieldHandles($withDrop))->toHaveCount(1);
    expect(traceFieldHandles($withDrop))->toBe(traceFieldHandles($without));

    // ---
    // The note must live in the SECTION's instructions and NOWHERE in the fields,
    // because the fields are the public form. Scope this to the FIELDS: the note sits
    // on the section itself, so encoding a section finds it by design and the search
    // would pass or fail for the wrong reason.
    // ---
    $encoded = \json_encode(traceFields($withDrop));

    expect($encoded)->toBeString();
    expect((string) $encoded)->not->toContain('no longer collects');

    // ---
    // Two controls, because this assertion has two ways to pass for the wrong reason.
    // The note must be present on the section (or the search above is vacuous), and no
    // field may be of type `section` -- a blueprint SECTION's instructions and a
    // `section` FIELDTYPE's instructions are different things with nearly the same name,
    // and the fieldtype renders straight onto the public form.
    // ---
    expect(traceInstructions($withDrop))->toContain('no longer collects');
    expect((string) $encoded)->not->toContain('"type":"section"');
});
