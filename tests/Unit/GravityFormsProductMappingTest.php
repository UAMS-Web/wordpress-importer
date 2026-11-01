<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Map a Gravity Forms product field through the real attribute mapping.
 *
 * @param  list<array<string, mixed>>|null  $choices
 * @return array<int|string, mixed>
 */
function productAttributes(string $inputType, ?array $choices = null): array
{
    $field = [
        'id' => 69,
        'label' => 'Product Name',
        'type' => 'product',
        'inputType' => $inputType,
    ];

    if ($choices !== null) {
        $field['choices'] = $choices;
    }

    return FieldtypeMapping::mapFieldAttributes($field);
}

/**
 * Read the `options` rows out of a mapped product field.
 *
 * @param  array<int|string, mixed>  $attributes
 * @return list<array<int|string, mixed>>
 */
function productOptions(array $attributes): array
{
    $options = $attributes['options'] ?? [];

    if (! \is_array($options)) {
        return [];
    }

    $rows = [];

    foreach ($options as $option) {
        if (\is_array($option)) {
            $rows[] = $option;
        }
    }

    return $rows;
}

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
});

it('gives each product option a human label and a stable slug key', function (string $inputType): void {
    // `App\Fieldtypes\Product::findOption()` matches a submitted value against the
    // option `key`, so the key has to be a slug — the raw choice text is a label,
    // not a key. wordpress-importer-archive#299
    $options = productOptions(productAttributes($inputType, [
        ['text' => 'Arkansas TBI Resource Guide (English)', 'value' => 'Arkansas TBI Resource Guide (English)', 'price' => '$42.13'],
        ['text' => 'Second Choice', 'value' => 'Second Choice', 'price' => '$13.42'],
    ]));

    expect($options)->toHaveCount(2);

    expect($options[0]['label'] ?? null)->toBe('Arkansas TBI Resource Guide (English)');
    expect($options[0]['key'] ?? null)->toBe('product_name_69_arkansas_tbi_resource_guide_english_1');
    expect($options[0]['price'] ?? null)->toBe(42.13);

    expect($options[1]['label'] ?? null)->toBe('Second Choice');
    expect($options[1]['key'] ?? null)->toBe('product_name_69_second_choice_2');
    expect($options[1]['price'] ?? null)->toBe(13.42);
})->with([
    'drop down' => ['select'],
    'radio buttons' => ['radio'],
])->group('gf-product');

it('never emits the raw human choice text as an option key', function (): void {
    // The pre-#299 shape used the choice text verbatim as the key, so a label with
    // spaces, parentheses or an ampersand became the submitted value.
    $options = productOptions(productAttributes('radio', [
        ['text' => 'Children\'s Ticket & Meal', 'value' => 'Children\'s Ticket & Meal', 'price' => '$5.00'],
    ]));

    $key = $options[0]['key'] ?? '';

    expect($key)->toBeString();
    expect($key)->toMatch('/^[a-z0-9_]+$/');
    expect($key)->not->toBe('Children\'s Ticket & Meal');
})->group('gf-product');

it('keeps option keys distinct when two choices share the same text', function (): void {
    // The trailing count is what makes the key unique; without it a duplicated label
    // would collapse two priced options onto one key. wordpress-importer-archive#299
    $options = productOptions(productAttributes('select', [
        ['text' => 'Ticket', 'value' => 'Ticket', 'price' => '$10.00'],
        ['text' => 'Ticket', 'value' => 'Ticket', 'price' => '$20.00'],
    ]));

    $keys = \array_map(
        static fn (array $option): mixed => $option['key'] ?? null,
        $options
    );

    expect($keys)->toBe([
        'product_name_69_ticket_1',
        'product_name_69_ticket_2',
    ]);
})->group('gf-product');

it('maps a negative-price single product without options', function (): void {
    // The e-Link corpus export carries negative-price credits; `convert_dollar_amount`
    // has to survive the sign, and a single product has no option rows at all.
    $attributes = FieldtypeMapping::mapFieldAttributes([
        'id' => 12,
        'label' => 'Discount',
        'type' => 'product',
        'inputType' => 'singleproduct',
        'basePrice' => '-$2,500.00',
        'disableQuantity' => true,
    ]);

    expect($attributes['price'] ?? null)->toBe(-2500.0);
    expect($attributes['disable_quantity'] ?? null)->toBeTrue();
    expect($attributes)->not->toHaveKey('options');

    // `single` is the Statamic default, so the mapping deliberately emits nothing
    expect($attributes)->not->toHaveKey('selection_type');
})->group('gf-product');

it('resolves the two supported multiple-choice modes to the multiple selection type', function (string $inputType): void {
    expect(productAttributes($inputType, [
        ['text' => 'One', 'value' => 'One', 'price' => '$1.00'],
    ])['selection_type'] ?? null)->toBe('multiple');
})->with([
    'drop down' => ['select'],
    'radio buttons' => ['radio'],
])->group('gf-product');

it('warns on each product mode the Statamic fieldtype cannot express', function (string $inputType): void {
    // 3 fields out of 309 in the corpus. Extending the fieldtype is not proportionate
    // and a silent fallback would hide them, so each is named. wordpress-importer-archive#299
    FieldtypeMapping::addUnsupportedProductModeWarning(
        [
            'id' => 8,
            'label' => 'Product Name',
            'type' => 'product',
            'inputType' => $inputType,
        ],
        'Springdale Caregiving',
        'Product Name',
        '16'
    );

    $warnings = ImportWarningManager::getWarnings('forms', 'unsupported_product_modes');

    expect($warnings)->toHaveCount(1);

    $first = \is_array($warnings[0] ?? null) ? $warnings[0] : [];
    expect($first['input_type'] ?? null)->toBe($inputType);
    expect($first['form_id'] ?? null)->toBe('16');
    expect($first['field_id'] ?? null)->toBe('8');
    expect($first['field_label'] ?? null)->toBe('Product Name');
    expect($first['form_title'] ?? null)->toBe('Springdale Caregiving');
})->with([
    'hidden' => ['hiddenproduct'],
    'user defined price' => ['price'],
    'calculation' => ['calculation'],
])->group('gf-product');

it('stays quiet for the product modes that do map', function (mixed $inputType): void {
    $field = [
        'id' => 8,
        'label' => 'Product Name',
        'type' => 'product',
    ];

    if ($inputType !== null) {
        $field['inputType'] = $inputType;
    }

    FieldtypeMapping::addUnsupportedProductModeWarning($field, 'A Form', 'Product Name', '1');

    expect(ImportWarningManager::getWarnings('forms', 'unsupported_product_modes'))->toBeEmpty();
})->with([
    'single product' => ['singleproduct'],
    'drop down' => ['select'],
    'radio buttons' => ['radio'],
    'absent (defaults to single product)' => [null],
    'non-string' => [0],
])->group('gf-product');

it('raises the unsupported-mode warning through the real blueprint path', function (): void {
    // The cases above call the reporter directly, which proves the rule but not
    // that anything reaches it. This drives the blueprint creator's own attribute
    // mapping — the path an import actually takes — so deleting the call site
    // turns this red rather than shipping the feature dead.
    $creator = new FormBlueprintCreator;
    $method = (new ReflectionClass($creator))->getMethod('getMappedFieldAttributes');
    $method->setAccessible(true);

    $method->invoke(
        $creator,
        [
            'id' => 8,
            'label' => 'Product Name',
            'type' => 'product',
            'inputType' => 'hiddenproduct',
        ],
        'Gravity Forms',
        [],
        'Springdale Caregiving',
        'Product Name',
        '16'
    );

    $warnings = ImportWarningManager::getWarnings('forms', 'unsupported_product_modes');

    expect($warnings)->toHaveCount(1);

    $first = \is_array($warnings[0] ?? null) ? $warnings[0] : [];
    expect($first['input_type'] ?? null)->toBe('hiddenproduct');
    expect($first['form_id'] ?? null)->toBe('16');
    expect($first['field_id'] ?? null)->toBe('8');
})->group('gf-product');

it('raises no unsupported-mode warning through the blueprint path for a supported mode', function (): void {
    $creator = new FormBlueprintCreator;
    $method = (new ReflectionClass($creator))->getMethod('getMappedFieldAttributes');
    $method->setAccessible(true);

    $attributes = $method->invoke(
        $creator,
        [
            'id' => 69,
            'label' => 'Product Name',
            'type' => 'product',
            'inputType' => 'radio',
            'choices' => [
                ['text' => 'First Choice', 'value' => 'First Choice', 'price' => '$42.13'],
            ],
        ],
        'Gravity Forms',
        [],
        'A Form',
        'Product Name',
        '1'
    );

    expect(ImportWarningManager::getWarnings('forms', 'unsupported_product_modes'))->toBeEmpty();

    // And the key/label shape survives the full blueprint path, not just the mapper
    $options = productOptions(\is_array($attributes) ? $attributes : []);
    expect($options[0]['label'] ?? null)->toBe('First Choice');
    expect($options[0]['key'] ?? null)->toBe('product_name_69_first_choice_1');
})->group('gf-product');

it('does not warn about a non-product field that carries a product-like inputType', function (): void {
    // The guard keys on the field type, so an `option` or `text` field whose
    // inputType happens to collide cannot raise a product warning. wordpress-importer-archive#299
    FieldtypeMapping::addUnsupportedProductModeWarning(
        [
            'id' => 8,
            'label' => 'Some Field',
            'type' => 'option',
            'inputType' => 'price',
        ],
        'A Form',
        'Some Field',
        '1'
    );

    expect(ImportWarningManager::getWarnings('forms', 'unsupported_product_modes'))->toBeEmpty();
})->group('gf-product');

it('leaves the sibling option fieldtype key and label shape untouched', function (): void {
    // The `option` fieldtype's own choices mapping predates wordpress-importer-archive#299 and keeps GF `value`
    // as the key. Widening `product` must not reach it.
    $options = productOptions(FieldtypeMapping::mapFieldAttributes([
        'id' => 44,
        'label' => 'Ticket Options',
        'type' => 'option',
        'inputType' => 'select',
        'choices' => [
            ['text' => 'Vegetarian Meal', 'value' => 'veg', 'price' => '$3.00'],
        ],
    ]));

    expect($options[0]['key'] ?? null)->toBe('veg');
    expect($options[0]['label'] ?? null)->toBe('Vegetarian Meal');
})->group('gf-product');

it('leaves a non-pricing fieldtype choices mapping untouched', function (string $type): void {
    // The product-specific key generation is scoped by source fieldtype, so the
    // default `key`/`value` choices shape must not acquire a generated slug key —
    // that would change the value every select/radio/checkbox submission carries.
    $options = productOptions(FieldtypeMapping::mapFieldAttributes([
        'id' => 7,
        'label' => 'Favorite Color',
        'type' => $type,
        'choices' => [
            ['text' => 'Blue', 'value' => 'blue'],
        ],
    ]));

    expect($options[0]['key'] ?? null)->toBe('blue');
    expect($options[0])->not->toHaveKey('label');
    expect($options[0])->not->toHaveKey('price');
})->with([
    'drop down' => ['select'],
    'radio buttons' => ['radio'],
    'checkboxes' => ['checkbox'],
])->group('gf-product');
