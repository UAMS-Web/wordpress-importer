<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormBlueprintCreator;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Field-attribute mapping for Ninja Forms (issue #10).
 *
 * The fieldtype map decides *what kind* of field each one becomes; this covers what it is
 * configured with once it gets there — instructions, defaults, choice lists, the repeater
 * button label and the date settings.
 *
 * Choice lists are the reason this file leads with them. A Ninja option carries a
 * `settingModel` blob of admin-UI scaffolding whose own `value` key holds the plugin's
 * default template list — `One` / `Two` / `Three`. Reading that instead of the option
 * itself produces a blueprint full of placeholder choices that look entirely plausible and
 * raise no error, so the negative assertion against it is the point of the first test
 * rather than a flourish.
 *
 * @command  php vendor/bin/pest tests/Integration/NinjaFormsFieldAttributesTest.php
 */
uses(StatamicTestCase::class);

/**
 * Invoke the protected attribute mapper the blueprint creator uses.
 *
 * @param  array<int|string, mixed>  $field  The Ninja Forms field data
 * @return array<int|string, mixed> The mapped Statamic attributes
 */
function mapNinjaFieldAttributes(array $field): array
{
    $creator = app(FormBlueprintCreator::class);

    $method = new ReflectionMethod($creator, 'getMappedFieldAttributes');
    $method->setAccessible(true);

    $mapped = $method->invoke($creator, $field, 'ninja-forms', [], null, null, null, 'default');

    return \is_array($mapped) ? $mapped : [];
}

/**
 * A Ninja option object, including the settingModel blob the real exports carry.
 *
 * @return array<string, mixed>
 */
function ninjaOption(string $label, string $value, int|string $order, string $calc = ''): array
{
    return [
        'errors' => [],
        'max_options' => 0,
        'label' => $label,
        'value' => $value,
        'calc' => $calc,
        'selected' => 0,
        'order' => $order,
        // The trap: Ninja's default template list, present on every real option.
        'settingModel' => [
            'name' => 'options',
            'type' => 'option-repeater',
            'value' => [
                ['label' => 'One', 'value' => 'one', 'calc' => '', 'selected' => 0, 'order' => 0],
                ['label' => 'Two', 'value' => 'two', 'calc' => '', 'selected' => 0, 'order' => 1],
                ['label' => 'Three', 'value' => 'three', 'calc' => '', 'selected' => 0, 'order' => 2],
            ],
        ],
    ];
}

it('maps a choice list from the options array, never from the settingModel template', function (): void {
    $mapped = mapNinjaFieldAttributes([
        'type' => 'listcheckbox',
        'key' => 'purpose_for_waiver_1645113676695',
        'label' => 'Purpose for Waiver',
        'options' => [
            ninjaOption('Entertainment', 'entertainment', 0),
            ninjaOption('Marketing', 'marketing', 1),
        ],
    ]);

    expect($mapped['options'] ?? null)->toBe([
        'entertainment' => 'Entertainment',
        'marketing' => 'Marketing',
    ]);

    // The scaffolding must not leak through in any form.
    $flattened = \json_encode($mapped);

    expect($flattened)->not->toContain('settingModel')
        ->and($flattened)->not->toContain('option-repeater')
        ->and($mapped['options'] ?? [])->not->toHaveKey('one')
        ->and($mapped['options'] ?? [])->not->toHaveKey('two')
        ->and($mapped['options'] ?? [])->not->toHaveKey('three');
});

it('orders choices by the export order rather than array position', function (): void {
    $mapped = mapNinjaFieldAttributes([
        'type' => 'listselect',
        'key' => 'number_of_miracle_stars_1637610265081',
        'label' => 'Number of Miracle Stars',
        'options' => [
            // Deliberately out of order, and `order` as a string as the real exports write it.
            ninjaOption('Three', '3', '2'),
            ninjaOption('One', '1', '0'),
            ninjaOption('Two', '2', '1'),
        ],
    ]);

    // PHP coerces a numeric-string array key to an integer, so a value => label map of
    // numeric option values necessarily comes back with int keys. That is inherent to the
    // shape Statamic wants rather than something this mapper chooses, and it is what the
    // sibling importers produce for numeric options too — asserted explicitly so a future
    // reader does not "fix" it into string keys that PHP will not hold.
    $options = $mapped['options'] ?? null;

    expect($options)->toBeArray();

    expect(\is_array($options) ? \array_keys($options) : [])->toBe([1, 2, 3])
        ->and($options)->toBe([1 => 'One', 2 => 'Two', 3 => 'Three']);
});

it('drops per-option calc values, which Statamic has nowhere to put', function (): void {
    $mapped = mapNinjaFieldAttributes([
        'type' => 'listselect',
        'key' => 'stars_1637610265081',
        'label' => 'Stars',
        // The real Miracle Stars select prices every option at 4.
        'options' => [ninjaOption('1', '1', 0, '4')],
    ]);

    expect($mapped['options'] ?? null)->toBe(['1' => '1'])
        ->and(\json_encode($mapped))->not->toContain('"calc"');
});

it('falls back to the label when an option carries no value', function (): void {
    $mapped = mapNinjaFieldAttributes([
        'type' => 'listradio',
        'key' => 'choice_1',
        'label' => 'Choice',
        'options' => [ninjaOption('Yes', '', 0)],
    ]);

    expect($mapped['options'] ?? null)->toBe(['Yes' => 'Yes']);
});

it('leaves a field with no choice list without an options key', function (): void {
    $mapped = mapNinjaFieldAttributes([
        'type' => 'textbox',
        'key' => 'notes_1',
        'label' => 'Notes',
    ]);

    // The negative control for every assertion above: an empty map here would make them
    // meaningless, and an empty `options` array would write one into every text field.
    expect($mapped)->not->toHaveKey('options');
});

it('carries description text to instructions, treating an emptied drawer as empty', function (): void {
    $withText = mapNinjaFieldAttributes([
        'type' => 'textbox',
        'key' => 'notes_1',
        'label' => 'Notes',
        'desc_text' => '<p>Tell us more.</p>',
    ]);

    expect($withText['instructions'] ?? null)->toBe('<p>Tell us more.</p>');

    // 33 corpus fields declare desc_text and only 7 hold anything; the rest hold the
    // string an emptied rich-text drawer saves, not an empty string.
    $emptied = mapNinjaFieldAttributes([
        'type' => 'textbox',
        'key' => 'notes_2',
        'label' => 'Notes',
        'desc_text' => '<p><br></p>',
    ]);

    expect($emptied['instructions'] ?? '')->toBe('');
});

it('maps the repeater add-another button label', function (): void {
    $mapped = mapNinjaFieldAttributes([
        'type' => 'repeater',
        'key' => 'participant_waivers_1',
        'label' => 'Participant Waivers',
        'button_text' => 'Add Another Participant Waiver',
    ]);

    expect($mapped['button_label'] ?? null)->toBe('Add Another Participant Waiver');
});

it('translates the date mode and format rather than passing moment.js through', function (): void {
    $mapped = mapNinjaFieldAttributes([
        'type' => 'date',
        'key' => 'event_date_1',
        'label' => 'Event Date',
        'date_mode' => 'date_only',
        'date_format' => 'MM/DD/YYYY',
    ]);

    // moment.js and PHP share letters with different meanings, so an untranslated format
    // renders wrong dates silently.
    expect($mapped['format'] ?? null)->toBe('m/d/Y')
        ->and($mapped['time_enabled'] ?? null)->toBeFalse();
});

it('maps a checkbox initial state to a boolean toggle default', function (): void {
    $mapped = mapNinjaFieldAttributes([
        'type' => 'checkbox',
        'key' => 'agree_1',
        'label' => 'I agree',
        'default_value' => 'checked',
        'checked_value' => 'Checked',
        'unchecked_value' => 'Unchecked',
    ]);

    expect($mapped['default'] ?? null)->toBeTrue();
});

it('does not invent a character limit from the message alone', function (): void {
    // 19 corpus fields carry input_limit_type and input_limit_msg while input_limit itself
    // is never populated anywhere, so these are Ninja defaults behind an unset limit.
    $mapped = mapNinjaFieldAttributes([
        'type' => 'textbox',
        'key' => 'notes_1',
        'label' => 'Notes',
        'input_limit' => '',
        'input_limit_type' => 'characters',
        'input_limit_msg' => 'Character(s) left',
    ]);

    expect($mapped)->not->toHaveKey('character_limit')
        ->and($mapped)->not->toHaveKey('input_limit')
        ->and($mapped)->not->toHaveKey('limit');
});

it('exposes the attribute mapping and leaves the options mapping empty', function (): void {
    // $fieldAttributeOptionsMapping stays empty by design: the engine only consults it
    // after parsing a `field_options` JSON string, which is a Formidable Forms shape.
    expect(FieldtypeMapping::getFieldAttributeMapping())->not->toBeEmpty()
        ->and(FieldtypeMapping::getFieldOptionsMapping())->toBe([]);
});

it('keeps a leading prompt option so the rendered form matches WordPress', function (): void {
    // Both corpus `liststate` fields open with this pseudo-option, value equal to label.
    $mapped = mapNinjaFieldAttributes([
        'type' => 'liststate',
        'key' => 'liststate_1623683536115',
        'label' => 'State',
        'options' => [
            ninjaOption('- Select State -', '- Select State -', 0),
            ninjaOption('Alabama', 'AL', 1),
        ],
    ]);

    // Stripping it would silently change the rendered form; `required` is what prevents
    // submitting it, exactly as in WordPress.
    expect($mapped['options'] ?? null)->toBe([
        '- Select State -' => '- Select State -',
        'AL' => 'Alabama',
    ]);
});
