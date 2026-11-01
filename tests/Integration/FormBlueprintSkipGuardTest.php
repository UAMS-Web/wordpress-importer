<?php

declare(strict_types=1);

/**
 * Pins the blueprint skip guard itself, not only the mapping the guard consults.
 *
 * wordpress-importer-archive#830 fixed `FormBlueprintCreatorTrait`'s guard to ask `isFieldtypeMapped()` rather
 * than test `default === null` directly, and pinned that method's five/four split in
 * `tests/Unit/GravityFormsBlueprintMappingTest.php`. Nothing pinned the guard
 * *expression* that consumes it: measured on the merged branch, reverting the guard to
 * the defective form left the standalone suite entirely green, and the host subset did
 * not close it either — its Gravity Forms fixtures carry one `captcha` and zero
 * `survey` / `multi_choice` / `poll` / `post_tags` fields (wordpress-importer-archive#834).
 *
 * **The guard is driven through the real creator rather than re-implemented.** A copy
 * of the condition in a test passes happily while the production expression drifts,
 * which is the failure this file exists to prevent — so it calls the public
 * `createBlueprintWithFields()` and reads the saved blueprint back.
 *
 * **This is Integration, not Unit, because the guard's own method saves.** The loop
 * lives inside `createBlueprintWithFields()`, which ends at `$blueprint->save()`; a
 * Unit-tier call would have the trait catch the failure and return `false`, so the
 * guard's verdict would never be observable. It does NOT need the host app: the
 * per-process workbench (wordpress-importer-archive#827) points `BlueprintRepository` at a directory owned by
 * this process, so the blueprint round-trips inside the minimal Statamic band.
 *
 * @command  php vendor/bin/pest tests/Integration/FormBlueprintSkipGuardTest.php
 */

use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Every null-default Gravity Forms type, with the field id it is given in the form.
 *
 * **The id is fixed here rather than derived.** The blueprint handle is the snake-cased
 * label plus the field id, so pinning the id makes the expected handle a literal the
 * test can state; computing it from the case's position would make the assertion depend
 * on the order of this list.
 *
 * The four `expected` entries resolve their fieldtype from `inputType` rather than from
 * a single default, which is why `default === null` is not the test. The five with a
 * null `expected` carry no attribute map at all and are genuinely unsupported.
 *
 * @return list<array{id: string, name: string, inputType: string|null, expected: string|null}>
 */
function skipGuardCases(): array
{
    return [
        // Kept — a null default plus a real `inputType` map, resolved concretely.
        ['id' => '2', 'name' => 'multi_choice', 'inputType' => 'radio', 'expected' => 'radio'],
        ['id' => '3', 'name' => 'survey', 'inputType' => 'likert', 'expected' => 'likert_scale'],
        ['id' => '4', 'name' => 'poll', 'inputType' => 'select', 'expected' => 'select'],
        ['id' => '5', 'name' => 'post_tags', 'inputType' => 'checkbox', 'expected' => 'checkboxes'],

        // Skipped — a null default and no attribute map at all.
        ['id' => '6', 'name' => 'image_choice', 'inputType' => null, 'expected' => null],
        ['id' => '7', 'name' => 'captcha', 'inputType' => null, 'expected' => null],
        ['id' => '8', 'name' => 'gf_no_captcha_recaptcha', 'inputType' => null, 'expected' => null],
        ['id' => '9', 'name' => 'quantity', 'inputType' => null, 'expected' => null],
        ['id' => '10', 'name' => 'shipping', 'inputType' => null, 'expected' => null],
    ];
}

/**
 * Build one form carrying every case, create its blueprint, and return handle => type.
 *
 * One creation covers every assertion, so a field appearing that should have been
 * skipped is visible in the same run as a field wrongly dropped.
 *
 * @return array<string, string>
 */
function skipGuardResolvedFields(): array
{
    // A plain mapped type first: the control that makes the skip assertions non-vacuous.
    $formFields = [
        ['id' => '1', 'label' => 'Control Text', 'type' => 'text'],
    ];

    foreach (skipGuardCases() as $case) {
        $field = [
            'id' => $case['id'],
            'label' => $case['name'],
            'type' => $case['name'],
        ];

        // Only the kept types carry an inputType; the skipped ones have no attribute map.
        if ($case['inputType'] !== null) {
            $field['inputType'] = $case['inputType'];
        }

        $formFields[] = $field;
    }

    $creator = new FormBlueprintCreator;

    $created = $creator->createBlueprintWithFields(
        'skip_guard_form',
        'Skip Guard Form',
        $formFields,
        'gravity_forms',
        '834',
        null
    );

    expect($created)->toBeTrue();

    $blueprint = BlueprintFacade::find('forms.skip_guard_form');

    // Narrowed with `instanceof` rather than an expectation: a failed `find()` must stop
    // the test here, not surface as a null-method error three lines down.
    if (! $blueprint instanceof Blueprint) {
        throw new RuntimeException('The skip-guard blueprint was not saved.');
    }

    $resolved = [];

    foreach ($blueprint->fields()->all() as $handle => $field) {
        if (! $field instanceof Field) {
            continue;
        }

        // `Field::type()` is untyped upstream, so narrow rather than cast: a non-string
        // would otherwise be stringified into a passing comparison.
        $type = $field->type();

        if (! \is_string($type)) {
            continue;
        }

        $resolved[(string) $handle] = $type;
    }

    return $resolved;
}

it('creates the blueprint and keeps the control field, so the skip assertions cannot pass vacuously', function (): void {
    $resolved = skipGuardResolvedFields();

    // The control is a plain mapped type with a real default — it must survive.
    expect($resolved)->toHaveKey('control_text_1');
    expect($resolved['control_text_1'])->toBe('text');

    // One control plus the four kept types, and nothing else.
    expect($resolved)->toHaveCount(5);
});

it('carries the five/four split the datasets are derived from', function (): void {
    // ---
    // Both datasets below are derived from `skipGuardCases()`, and a derived dataset that
    // comes back empty runs ZERO cases while reporting green — the same false pass as a
    // mutation run finding nothing to mutate. Pinning the split makes an emptied or
    // mis-keyed table fail here instead of silently disarming the assertions.
    // ---
    $kept = \array_filter(skipGuardCases(), static fn (array $case): bool => $case['expected'] !== null);
    $skipped = \array_filter(skipGuardCases(), static fn (array $case): bool => $case['expected'] === null);

    expect($kept)->toHaveCount(4);
    expect($skipped)->toHaveCount(5);
});

it('keeps an attribute-mapped null-default type and resolves its concrete fieldtype', function (string $handle, string $expected): void {
    $resolved = skipGuardResolvedFields();

    expect($resolved)->toHaveKey($handle);
    expect($resolved[$handle])->toBe($expected);
})->with(function (): array {
    $cases = [];

    foreach (skipGuardCases() as $case) {
        if ($case['expected'] !== null) {
            $cases[] = [$case['name'].'_'.$case['id'], $case['expected']];
        }
    }

    return $cases;
});

it('skips an unsupported null-default type before it reaches createBlueprintField', function (string $name): void {
    $resolved = skipGuardResolvedFields();

    // No handle for this field may exist at any id — assert on the prefix, not one guess.
    $matching = array_filter(
        array_keys($resolved),
        static fn (string $handle): bool => str_starts_with($handle, $name.'_')
    );

    expect($matching)->toBe([]);
})->with(function (): array {
    $cases = [];

    foreach (skipGuardCases() as $case) {
        if ($case['expected'] === null) {
            $cases[] = [$case['name']];
        }
    }

    return $cases;
});
