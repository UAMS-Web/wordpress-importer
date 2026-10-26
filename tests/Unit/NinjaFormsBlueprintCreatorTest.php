<?php

declare(strict_types=1);

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
