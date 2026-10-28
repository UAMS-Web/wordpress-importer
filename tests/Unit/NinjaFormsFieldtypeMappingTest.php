<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FieldtypeMapping;

/**
 * Ninja Forms field type -> Statamic fieldtype mapping (#10).
 *
 * The type list below is the complete set the four production exports use, obtained by
 * walking every `.nff` in the corpus **recursively**. That recursion is the point: six
 * types — `textbox`, `listradio`, `listcheckbox`, `checkbox`, `date`, `zip` — appear ONLY
 * inside `repeater` sets. A top-level walk reports 17 types and silently omits `textbox`,
 * which is the second most common type in the corpus.
 *
 * The list is inlined rather than read from the private corpus so this stays a Unit
 * test; the corpus is not present in a standalone run.
 *
 * @command  vendor/bin/pest --testsuite=Unit tests/Unit/NinjaFormsFieldtypeMappingTest.php
 */

/**
 * Every Ninja Forms field type used by the four production forms, with its expected
 * Statamic target. `false` means deliberately suppressed.
 *
 * @return array<string, array{0: string, 1: string|false}>
 */
function ninjaProductionTypes(): array
{
    return [
        'textbox' => ['textbox', 'text'],
        'firstname' => ['firstname', 'text'],
        'lastname' => ['lastname', 'text'],
        'email' => ['email', 'email'],
        'phone' => ['phone', 'phone'],
        'address' => ['address', 'text'],
        'city' => ['city', 'text'],
        'zip' => ['zip', 'text'],
        'liststate' => ['liststate', 'select'],
        'number' => ['number', 'float'],
        'date' => ['date', 'date'],
        'listselect' => ['listselect', 'select'],
        'listradio' => ['listradio', 'radio'],
        'listcheckbox' => ['listcheckbox', 'checkboxes'],
        'checkbox' => ['checkbox', 'toggle'],
        'html' => ['html', 'html'],
        'hr' => ['hr', 'subsection'],
        'repeater' => ['repeater', 'replicator'],
        'submit' => ['submit', false],
    ];
}

it('maps every field type the production forms use', function (string $ninjaType, string|false $expected): void {
    expect(FieldtypeMapping::getStatamicFieldtype($ninjaType))->toBe($expected);
})->with(ninjaProductionTypes());

it('leaves no production field type unmapped', function (): void {
    // Bounds the set on the other side: a null here means the importer would warn and
    // drop a field a real form depends on.
    $unmapped = [];

    foreach (\array_keys(ninjaProductionTypes()) as $type) {
        if (FieldtypeMapping::getStatamicFieldtype($type) === null) {
            $unmapped[] = $type;
        }
    }

    expect($unmapped)->toBe([]);
});

it('maps a repeater to a replicator, since it is the waiver form\'s entire body', function (): void {
    // The waiver is the highest-volume Ninja Form at UAMS — 566 submissions — and a
    // repeating fieldset is all it contains, so this single row carries that whole form.
    expect(FieldtypeMapping::getStatamicFieldtype('repeater'))->toBe('replicator');
});

it('maps a single checkbox to a toggle rather than a checkboxes list', function (): void {
    // Ninja's `checkbox` is one boolean; `listcheckbox` is its multi-option sibling.
    // Mapping both to `checkboxes` would model a single boolean as a one-item list.
    expect(FieldtypeMapping::getStatamicFieldtype('checkbox'))->toBe('toggle');
    expect(FieldtypeMapping::getStatamicFieldtype('listcheckbox'))->toBe('checkboxes');
});

it('suppresses the submit field rather than leaving it unmapped', function (): void {
    // Statamic renders its own submit control, so importing one would duplicate it.
    // `false` suppresses; `null` would raise an unmapped warning on every single form.
    expect(FieldtypeMapping::getStatamicFieldtype('submit'))->toBeFalse();
});

it('leaves the credit-card types unmapped, deferring them to #631', function (string $type): void {
    // Only Miracle Stars uses these, and their Statamic target depends on
    // uams-statamic#12. An unmapped warning is the correct outcome until then — an
    // invented mapping would silently produce the wrong blueprint for a payment form.
    expect(FieldtypeMapping::getStatamicFieldtype($type))->toBeNull();
})->with([
    'creditcardnumber', 'creditcardcvc', 'creditcardexpiration', 'creditcardzip',
]);

it('exposes its mapping to the shared engine', function (): void {
    expect(FieldtypeMapping::getMapping())->toHaveKey('repeater');
});
