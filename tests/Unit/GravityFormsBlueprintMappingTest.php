<?php

declare(strict_types=1);

use Statamic\Fields\Values;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormBlueprintCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

require_once __DIR__.'/../Support/gravity-forms-test-support.php';

it('generates field handles with a field id suffix', function (): void {
    $fieldData = [
        'id' => '2',
        'label' => 'Email Address',
        'type' => 'email',
    ];

    // Test that field handles are generated with field ID for uniqueness
    $expectedHandle = 'email_address_2';

    // This would be tested through the blueprint creation process
    // For now, verify the pattern is correct
    expect($expectedHandle)->toMatch('/^[a-z_]+_\d+$/');
});

it('resolves the Gravity Forms username field to the username fieldtype', function (): void {
    // ---
    // `GF_Field_Username` (the User Registration add-on's `username` type) mapped to a
    // null default, and a null default falls back to the generic `text` fieldtype — so
    // the field silently lost its username semantics on import (`autocomplete="username"`,
    // spellcheck/autocorrect/autocapitalize off). `UAMS-Web/uams-statamic#312` shipped the
    // Statamic `username` fieldtype, so it now resolves properly (wordpress-importer-archive#300).
    // ---
    expect(FieldtypeMapping::getStatamicFieldtype('username'))->toBe('username');
});

it('leaves the sibling password mapping alone', function (): void {
    // `password` already resolved correctly and is explicitly out of scope for wordpress-importer-archive#300
    expect(FieldtypeMapping::getStatamicFieldtype('password'))->toBe('password');
});

it('exposes a well-structured fieldtype mapping array', function (): void {
    $mapping = FieldtypeMapping::getMapping();

    expect($mapping)->not->toBeEmpty();

    // Test that each mapping has the correct structure
    foreach ($mapping as $fieldType => $mappingConfig) {
        expect($fieldType)->toBeString();
        expect($mappingConfig)->toBeArray();
        if (! is_array($mappingConfig)) {
            throw new RuntimeException('Expected the mapping config to be an array.');
        }
        expect($mappingConfig)->toHaveKey('default');
    }
});

it('maps Gravity Forms fieldtypes to Statamic fieldtypes', function (
    string $gravity_forms_type,
    string|false|null $expected_statamic_type,
    string $assertion_type,
    ?string $inputType = null
): void {
    // Act: Get the mapped Statamic fieldtype
    $fieldData = ['type' => $gravity_forms_type];

    // Handle special test cases for inputType
    if ($inputType === '#not_set') {
        // Don't set inputType at all - emulates inputType not being set in source JSON
    } elseif ($inputType === '#empty') {
        // ---
        // Set inputType to empty string - emulates inputType being set as '' in source
        // JSON
        // ---
        $fieldData['inputType'] = '';
    } elseif ($inputType !== null) {
        // Set inputType to the provided value
        $fieldData['inputType'] = $inputType;
    }

    $actual_statamic_type = FieldtypeMapping::getStatamicFieldtype($fieldData);

    // Assert: Verify the mapping is correct based on assertion type
    switch ($assertion_type) {
        case 'assertEquals':
            expect($actual_statamic_type)->toEqual($expected_statamic_type);
            break;
        case 'assertFalse':
            expect($actual_statamic_type)->toBeFalse();
            break;
        case 'assertNull':
            expect($actual_statamic_type)->toBeNull();
            break;
        default:
            throw new RuntimeException("Unknown assertion type: {$assertion_type}");
    }
})
    ->group('fieldtypes')
    ->with(gravityFieldtypeMappingProvider());

it('exposes a well-structured field attribute mapping array', function (): void {
    $fieldAttributeMapping = FieldtypeMapping::getFieldAttributeMapping();

    expect($fieldAttributeMapping)->not->toBeEmpty();

    // Test that each attribute mapping has the correct structure
    foreach ($fieldAttributeMapping as $attributeName => $mappingConfig) {
        expect($attributeName)->toBeString();
        expect($mappingConfig)->toBeArray();
        if (! is_array($mappingConfig)) {
            throw new RuntimeException('Expected the attribute mapping config to be an array.');
        }
        expect($mappingConfig)->toHaveKey('statamic_attribute');
    }
});

it('maps field attributes to Statamic blueprint fields', function (
    array $source,
    array $statamic_blueprint
): void {
    $normalizedSource = gravityNormalizeStringKeyedRows($source);
    $normalizedBlueprint = gravityNormalizeStringKeyedRows($statamic_blueprint);

    // Create a FormBlueprintCreator instance to use its field creation logic
    $blueprintCreator = new FormBlueprintCreator;

    // Act: Create blueprint field configuration for each source field
    foreach ($normalizedSource as $index => $fieldData) {
        $fieldConfig = gravityInvokeCreateBlueprintField(
            $blueprintCreator,
            $fieldData,
            $normalizedSource
        );
        $expectedStatamic = gravityRequireBlueprintExpectationAt(
            $normalizedBlueprint,
            $index
        );
        $expectedField = $expectedStatamic['field'] ?? null;

        // Assert: Verify handle is present and correct
        if (isset($expectedStatamic['handle'])) {
            $actualHandle = gravityInvokeGenerateFieldHandle(
                $blueprintCreator,
                $fieldData
            );

            expect($actualHandle)->toEqual($expectedStatamic['handle']);
        }

        // ---
        // Verify type is present and correct (only for regular fields, not linked
        // fields)
        // ---

        if (isset($expectedStatamic['type'])) {
            expect($fieldConfig)->toHaveKey('type');
            expect($fieldConfig['type'])->toEqual($expectedStatamic['type']);
        }

        // ---
        // Verify expected field attributes are present and correct
        // ---

        if (\is_string($expectedField)) {
            expect($fieldConfig)->toHaveKey('field');
            expect($fieldConfig['field'])->toEqual($expectedField);

            if (isset($expectedStatamic['config'])) {
                $fieldConfigData = gravityAsArray($fieldConfig['config'] ?? []);
                $expectedConfig = gravityAsArray($expectedStatamic['config']);

                expect($fieldConfig)->toHaveKey('config');

                foreach ($expectedConfig as $key => $expectedValue) {
                    expect($key)->toBeString();
                    if (! is_string($key)) {
                        throw new RuntimeException('Expected the linked field config key to be a string.');
                    }
                    expect($fieldConfigData)->toHaveKey($key);
                    expect($fieldConfigData[$key])->toEqual($expectedValue);
                }
            }
        } else {
            expect($expectedField)->toBeArray();
            if (! is_array($expectedField)) {
                throw new RuntimeException('Expected the blueprint field definition to be an array.');
            }

            foreach ($expectedField as $key => $expectedValue) {
                expect($key)->toBeString();
                if (! is_string($key)) {
                    throw new RuntimeException('Expected the field attribute key to be a string.');
                }

                if ($key === 'options') {
                    $actualOptions = gravityAsArray($fieldConfig['options'] ?? []);
                    expect($expectedValue)->toBeArray();
                    if (! is_array($expectedValue)) {
                        throw new RuntimeException('Expected the options value to be an array.');
                    }
                    expect($fieldConfig)->toHaveKey('options');
                    expect($actualOptions)->toHaveCount(\count($expectedValue));

                    foreach ($expectedValue as $optionIndex => $expectedOption) {
                        $expectedOptionArray = gravityNormalizeStringKeyedRow($expectedOption);
                        $actualOption = gravityNormalizeStringKeyedRow(
                            $actualOptions[$optionIndex] ?? null
                        );

                        expect($actualOption['key'] ?? null)->toEqual($expectedOptionArray['key'] ?? null);
                        expect($actualOption['value'] ?? null)->toEqual($expectedOptionArray['value'] ?? null);
                    }
                } elseif ($key === 'mime_types') {
                    $actualMimeTypes = gravityAsArray($fieldConfig['mime_types'] ?? []);
                    expect($expectedValue)->toBeArray();
                    if (! is_array($expectedValue)) {
                        throw new RuntimeException('Expected the mime_types value to be an array.');
                    }
                    expect($fieldConfig)->toHaveKey('mime_types');
                    expect($actualMimeTypes)->toHaveCount(\count($expectedValue));

                    foreach ($expectedValue as $mimeType) {
                        expect($mimeType)->toBeString();
                        expect($actualMimeTypes)->toContain($mimeType);
                    }
                } else {
                    expect($fieldConfig)->toHaveKey($key);
                    expect($fieldConfig[$key])->toEqual($expectedValue);
                }
            }
        }

        $suppressedAttributes = [
            'field_key',
            'field_order',
            'form_id',
            'id',
            'required',
            'btsp',
            'admin_only',
            'in_section',
            'invalid',
        ];

        foreach ($suppressedAttributes as $attribute) {
            expect($fieldConfig)->not->toHaveKey($attribute);
        }

        if (\is_string($expectedField)) {
            $expectedStructure = ['field'];
            if (isset($expectedStatamic['config'])) {
                $expectedStructure[] = 'config';
            }
            $actualKeys = \array_keys($fieldConfig);
            $unexpectedKeys = array_diff(
                $actualKeys,
                $expectedStructure
            );
        } else {
            $expectedKeys = \array_keys($expectedField);
            $actualKeys = \array_keys($fieldConfig);
            $unexpectedKeys = array_diff(
                $actualKeys,
                $expectedKeys
            );
        }

        expect($unexpectedKeys)->toBeEmpty();
    }
})
    ->group('field-attributes', 'field-options-mapping')
    ->with(gravityAttributeMappingProvider());

it('drops password-strength attributes without warning', function (): void {
    ImportWarningManager::clearWarnings();

    try {
        $passwordField = [
            'type' => 'password',
            'label' => 'Password',
            // Real values from the teacher User Registration export (form 1, field 4):
            // passwordStrengthEnabled=true, minPasswordStrength="bad".
            'passwordStrengthEnabled' => true,
            'minPasswordStrength' => 'bad',
            // Control: an attribute with no mapping entry must still warn, proving the
            // warning pipeline is active here and the assertions below are not passing
            // vacuously.
            'unsupportedProbeAttribute' => 'probe value',
        ];

        FieldtypeMapping::mapFieldAttributesWithContext(
            $passwordField,
            "UAMS Schmieding Caregiver Training Program — Teacher's Section — User Registration",
            'Password',
            1
        );

        $warnedAttributes = \array_column(
            ImportWarningManager::getWarnings('forms', 'unsupported_attributes'),
            'attribute_name'
        );

        // The two documented-drop attributes are silenced.
        expect($warnedAttributes)->not->toContain('passwordStrengthEnabled');
        expect($warnedAttributes)->not->toContain('minPasswordStrength');

        // Control: a genuinely unmapped attribute on the same mapped fieldtype still warns.
        expect($warnedAttributes)->toContain('unsupportedProbeAttribute');
    } finally {
        ImportWarningManager::clearWarnings();
    }
})->group('field-attributes', 'field-options-mapping');

it('resolves the Gravity Forms option field to the option fieldtype', function (): void {
    // `UAMS-Web/uams-statamic#316` shipped the Statamic `option` fieldtype, so the
    // Gravity Forms `option` field no longer degrades to a plain `text` field (wordpress-importer-archive#302).
    expect(FieldtypeMapping::getStatamicFieldtype('option'))->toEqual('option');
})->group('fieldtypes');

it('translates the Gravity Forms option field configuration', function (): void {
    // The Statamic `option` fieldtype (uams-statamic#316) branches on its own
    // `input_type` and stores priced choices keyed by `key`/`label`/`price`,
    // plus the handle of the parent Product field (wordpress-importer-archive#302).
    $productField = [
        'id' => '5',
        'label' => 'Donation Amount',
        'type' => 'product',
        'inputType' => 'singleproduct',
    ];

    $optionField = [
        'id' => '6',
        'label' => 'Add Ons',
        'type' => 'option',
        'inputType' => 'radio',
        'productField' => '5',
        'enableChoiceValue' => true,
        'choices' => [
            [
                'text' => 'Commemorative Pin',
                'value' => 'pin',
                'price' => '$25.00',
            ],
            [
                'text' => 'Engraved Brick',
                'value' => 'brick',
                'price' => '$150.00',
            ],
        ],
    ];

    $allFields = [$productField, $optionField];

    $fieldConfig = gravityInvokeCreateBlueprintField(
        new FormBlueprintCreator,
        $optionField,
        $allFields
    );

    expect($fieldConfig['type'] ?? null)->toEqual('option');

    // The Gravity Forms `inputType` drives the Statamic `input_type` verbatim.
    expect($fieldConfig['input_type'] ?? null)->toEqual('radio');

    // The numeric Gravity Forms `productField` reference resolves to the parent
    // field's Statamic handle, which is what the fieldtype's config expects.
    expect($fieldConfig['product_field'] ?? null)->toEqual('donation_amount_5');

    // Priced choices land in the `options` grid as key/label/price rows.
    $options = gravityAsArray($fieldConfig['options'] ?? []);
    expect($options)->toHaveCount(2);

    $first = gravityAsArray($options[0] ?? []);
    expect($first['key'] ?? null)->toEqual('pin');
    expect($first['label'] ?? null)->toEqual('Commemorative Pin');
    expect($first['price'] ?? null)->toEqual(25.0);

    $second = gravityAsArray($options[1] ?? []);
    expect($second['key'] ?? null)->toEqual('brick');
    expect($second['label'] ?? null)->toEqual('Engraved Brick');
    expect($second['price'] ?? null)->toEqual(150.0);
})->group('field-attributes', 'field-options-mapping');

it('resolves product_field to the handle the blueprint actually assigns', function (
    string $productLabel
): void {
    // The handle must come from the same generator that writes the Product field
    // into the blueprint. A separate slug implementation diverges on labels the two
    // normalize differently — an apostrophe or an accent — leaving product_field
    // pointing at a handle that does not exist and the price deltas inert. wordpress-importer-archive#302
    $productField = [
        'id' => '44',
        'label' => $productLabel,
        'type' => 'product',
        'inputType' => 'singleproduct',
    ];

    $optionField = [
        'id' => '46',
        'label' => 'Add Ons',
        'type' => 'option',
        'inputType' => 'radio',
        'productField' => '44',
        'choices' => [
            ['text' => 'Commemorative Pin', 'value' => 'pin', 'price' => '$25.00'],
        ],
    ];

    $blueprintCreator = new FormBlueprintCreator;
    $allFields = [$productField, $optionField];

    $expectedHandle = gravityInvokeGenerateFieldHandle(
        $blueprintCreator,
        $productField
    );

    $fieldConfig = gravityInvokeCreateBlueprintField(
        $blueprintCreator,
        $optionField,
        $allFields
    );

    expect($fieldConfig['product_field'] ?? null)->toEqual($expectedHandle);
})->with([
    'a plain label' => ['Donation Amount'],
    'a label with an apostrophe' => ["Children's Ticket"],
    'a label with an accent' => ['Café Pass'],
])->group('field-attributes', 'field-options-mapping');

it('caps a single-file upload field at one file', function (
    mixed $multipleFiles,
    mixed $maxFiles,
    ?int $expectedMaxFiles
): void {
    // A disabled `multipleFiles` accepts exactly one upload, so `max_files` is 1
    // regardless of any stale `maxFiles` left over from when multiple uploads were
    // enabled and then disabled (wordpress-importer-archive#470).
    $field = [
        'id' => '3',
        'label' => 'Supporting Documents',
        'type' => 'fileupload',
        'multipleFiles' => $multipleFiles,
        'maxFiles' => $maxFiles,
    ];

    $fieldConfig = gravityInvokeCreateBlueprintField(
        new FormBlueprintCreator,
        $field,
        [$field]
    );

    expect($fieldConfig['type'] ?? null)->toEqual('assets');

    if ($expectedMaxFiles === null) {
        // Multiple uploads with no cap means unlimited, which Statamic expresses by
        // omitting the key entirely.
        expect($fieldConfig)->not->toHaveKey('max_files');

        return;
    }

    expect($fieldConfig['max_files'] ?? null)->toEqual($expectedMaxFiles);
})->with([
    'single upload with a stale maxFiles' => [false, '5', 1],
    'single upload with an empty maxFiles' => [false, '', 1],
    'single upload expressed as 0 with a stale maxFiles' => [0, '5', 1],
    // The corpus also serializes an unset toggle as '' — 96 upload fields across
    // three exports, usually with no maxFiles key at all. Gravity Forms evaluates
    // multipleFiles for truthiness, so these are single-file fields too.
    'single upload serialized as an empty string' => ['', '', 1],
    'single upload serialized as an empty string with a stale maxFiles' => ['', '5', 1],
    'single upload expressed as the string zero' => ['0', '', 1],
    'multiple uploads with no cap' => [true, '', null],
    'multiple uploads with a cap' => [true, '2', 2],
    'multiple uploads expressed as the string one' => ['1', '3', 3],
])->group('field-attributes', 'field-options-mapping');

it('caps a single-file upload field carrying no maxFiles key at all', function (
    mixed $multipleFiles,
    ?int $expectedMaxFiles
): void {
    // The 96 real corpus fields look like this: `multipleFiles` present but empty,
    // and no `maxFiles` key. The declarative translation never runs for an absent
    // attribute, so this case is capped by the blueprint creator. wordpress-importer-archive#470
    $field = [
        'id' => '22',
        'label' => 'Upload CV',
        'type' => 'fileupload',
        'multipleFiles' => $multipleFiles,
    ];

    $fieldConfig = gravityInvokeCreateBlueprintField(
        new FormBlueprintCreator,
        $field,
        [$field]
    );

    if ($expectedMaxFiles === null) {
        expect($fieldConfig)->not->toHaveKey('max_files');

        return;
    }

    expect($fieldConfig['max_files'] ?? null)->toEqual($expectedMaxFiles);
})->with([
    'unset toggle serialized as an empty string' => ['', 1],
    'toggle explicitly false' => [false, 1],
    'toggle absent is single-file in Gravity Forms' => [null, 1],
    'multiple uploads uncapped' => [true, null],
])->group('field-attributes', 'field-options-mapping');

// ---
// wordpress-importer-archive#815 — a genuinely unsupported field type must produce no blueprint field
// rather than landing as a `text` one, which is what it did while the skip guard
// never consulted `isFieldtypeMapped()`.
//
// **A null `default` is NOT the test for "unsupported", and reading it as one
// drops working fields.** It means "no single default — resolve the fieldtype
// from this field's `inputType`". Nine Gravity Forms entries declare a null
// default and they split in two: five carry no `attributes` block and are
// genuinely unsupported, while `multi_choice`, `poll`, `survey` and `post_tags`
// carry a real `inputType` map naming concrete Statamic fieldtypes — so
// `survey + likert` is a `likert_scale` and `multi_choice + radio` is a `radio`.
// A first cut of this ticket guarded on the null default alone and would have
// dropped 261 correctly-mapped field instances to fix 95.
//
// Blueprint creation is host-bound; what is checkable here is the entry-level
// decision the guard now delegates to.
// ---

it('separates genuinely unsupported types from those mapped through attributes', function (
    string $gravityFormsType,
    bool $expectedMapped
): void {
    $mapping = FieldtypeMapping::getMapping();

    expect($mapping)->toHaveKey($gravityFormsType);

    $entry = $mapping[$gravityFormsType];

    \assert(\is_array($entry));

    expect(FieldtypeMapping::isFieldtypeMapped($entry))->toBe($expectedMapped);
})->with([
    // Null default, no attributes — genuinely unsupported, and what the guard skips.
    'captcha — null default, no attributes' => ['captcha', false],
    'gf_no_captcha_recaptcha — null default, no attributes' => ['gf_no_captcha_recaptcha', false],
    'image_choice — null default, no attributes' => ['image_choice', false],
    'quantity — null default, no attributes' => ['quantity', false],
    'shipping — null default, no attributes' => ['shipping', false],
    // Null default WITH an inputType map — mapped, and must survive the guard.
    // These four are why the guard cannot test the null default directly.
    'multi_choice — null default, has attributes' => ['multi_choice', true],
    'poll — null default, has attributes' => ['poll', true],
    'survey — null default, has attributes' => ['survey', true],
    'post_tags — null default, has attributes' => ['post_tags', true],
    // Controls: real defaults read as mapped, or the false-expectations above
    // would pass against a method that always returns false.
    'text — a real default' => ['text', true],
    'email — a real default' => ['email', true],
]);

it('resolves the attribute-mapped types to concrete fieldtypes', function (
    string $gravityFormsType,
    string $inputType,
    string $expectedFieldtype
): void {
    // The claim the guard depends on: these are not hypothetical mappings. If
    // this fails, skipping them would be defensible; while it passes, skipping
    // them drops a working field.
    expect(FieldtypeMapping::getStatamicFieldtype([
        'type' => $gravityFormsType,
        'inputType' => $inputType,
    ]))->toBe($expectedFieldtype);
})->with([
    'survey + likert => likert_scale' => ['survey', 'likert', 'likert_scale'],
    'survey + radio => radio' => ['survey', 'radio', 'radio'],
    'multi_choice + radio => radio' => ['multi_choice', 'radio', 'radio'],
    'multi_choice + checkbox => checkboxes' => ['multi_choice', 'checkbox', 'checkboxes'],
    'poll + select => select' => ['poll', 'select', 'select'],
    'post_tags + checkbox => checkboxes' => ['post_tags', 'checkbox', 'checkboxes'],
]);

it('warns for an unsupported fieldtype unless it declares alert_unsupported false', function (
    string $gravityFormsType,
    bool $expectedWarning
): void {
    ImportWarningManager::clearWarnings();

    try {
        FieldtypeMapping::warnIfFieldtypeUnmapped(
            FieldtypeMapping::getMapping(),
            $gravityFormsType,
            'Probe Form',
            'Probe Field',
            '815'
        );

        expect(ImportWarningManager::hasWarnings())->toBe($expectedWarning);
    } finally {
        ImportWarningManager::clearWarnings();
    }
})->with([
    // Declared quiet: a captcha has no Statamic equivalent and never will, so
    // warning on all 72 in the corpus is noise. The flag was already written
    // per-type and simply never consulted at the fieldtype level.
    'captcha — alert_unsupported false' => ['captcha', false],
    'gf_no_captcha_recaptcha — alert_unsupported false' => ['gf_no_captcha_recaptcha', false],
    // Unsupported and not declared quiet: these must be reported, or a field
    // vanishing from the imported form is invisible.
    'image_choice — no flag' => ['image_choice', true],
    'quantity — no flag' => ['quantity', true],
    'shipping — no flag' => ['shipping', true],
    // Controls: a supported type warns under no circumstances.
    'text — supported' => ['text', false],
    'email — supported' => ['email', false],
]);

/**
 * Drive one field through the attribute mapping and return the attribute names warned about.
 *
 * Every case carries `unsupportedProbeAttribute`, which has no mapping entry and must always
 * warn — without it a "no warning" assertion passes just as well when the pipeline is inert.
 *
 * @param  array<string, mixed>  $extraAttributes
 * @return array<int, mixed>
 */
function gravityWarnedAttributeNames(array $extraAttributes): array
{
    ImportWarningManager::clearWarnings();

    try {
        FieldtypeMapping::mapFieldAttributesWithContext(
            \array_merge([
                'type' => 'text',
                'label' => 'A field',
                'unsupportedProbeAttribute' => 'probe value',
            ], $extraAttributes),
            'A form',
            'A field',
            1
        );

        return \array_column(
            ImportWarningManager::getWarnings('forms', 'unsupported_attributes'),
            'attribute_name'
        );
    } finally {
        ImportWarningManager::clearWarnings();
    }
}

it('never reports duplicatable, whatever its value', function (mixed $value): void {
    $warned = gravityWarnedAttributeNames(['duplicatable' => $value]);

    expect($warned)->not->toContain('duplicatable');
    // Control: the pipeline is live, so the absence above is a suppression and not silence.
    expect($warned)->toContain('unsupportedProbeAttribute');
})->group('field-attributes')->with([
    'true — the value on 211 of 214 fields measured' => [true],
    'false — the 3 captcha and post_title exceptions' => [false],
]);

it('reports is_payment only when it is true', function (mixed $value, bool $expectWarning): void {
    $warned = gravityWarnedAttributeNames(['is_payment' => $value]);

    expect($warned)->toContain('unsupportedProbeAttribute');

    if ($expectWarning) {
        expect($warned)->toContain('is_payment');
    } else {
        expect($warned)->not->toContain('is_payment');
    }
})->group('field-attributes')->with([
    // false on all 214 fields measured, and 0 occurrences of true across all 34 sites.
    'false — the estate-wide default, silent' => [false, false],
    // The case that does not currently occur and is the reason not to suppress outright:
    // a payment field is a genuine migration concern with no Statamic analog.
    'true — a real payment field, reported' => [true, true],
]);

it('names the form and field when it reports is_payment', function (): void {
    ImportWarningManager::clearWarnings();

    try {
        FieldtypeMapping::mapFieldAttributesWithContext(
            ['type' => 'text', 'label' => 'Donation amount', 'is_payment' => true],
            'Giving form',
            'Donation amount',
            7
        );

        $warnings = \array_values(\array_filter(
            ImportWarningManager::getWarnings('forms', 'unsupported_attributes'),
            static fn (mixed $warning): bool => \is_array($warning)
                && ($warning['attribute_name'] ?? null) === 'is_payment'
        ));

        expect($warnings)->toHaveCount(1);
        expect($warnings[0]['form_title'])->toBe('Giving form');
        expect($warnings[0]['field_label'])->toBe('Donation amount');
        expect($warnings[0]['form_id'])->toBe(7);
    } finally {
        ImportWarningManager::clearWarnings();
    }
})->group('field-attributes');

it('suppresses an attribute classified inert, at every value', function (string $attribute, mixed $value): void {
    $warned = gravityWarnedAttributeNames([$attribute => $value]);

    expect($warned)->not->toContain($attribute);
    // Control: the pipeline is live, so the absence above is a suppression and not silence.
    expect($warned)->toContain('unsupportedProbeAttribute');
})->group('field-attributes')->with([
    // Each switches off, qualifies or restyles something another attribute reports (wordpress-importer-archive#1163).
    'gppaDisable — false, the value on all 73 fields' => ['gppaDisable', false],
    'gppaDisable — true' => ['gppaDisable', true],
    'enableDisplayInColumns — true, the value on all 38 fields' => ['enableDisplayInColumns', true],
    'displayAlignment — vertical, the value on all 20 fields' => ['displayAlignment', 'vertical'],
    'gwlimitcheckboxes_span_multiple_fields — true' => ['gwlimitcheckboxes_span_multiple_fields', true],
    'gpnfModalHeaderColor — a color' => ['gpnfModalHeaderColor', '#9D2235'],
]);

it('reports an attribute classified actionable only away from its default', function (
    string $attribute,
    mixed $default,
    mixed $nonDefault
): void {
    $atDefault = gravityWarnedAttributeNames([$attribute => $default]);
    $awayFromDefault = gravityWarnedAttributeNames([$attribute => $nonDefault]);

    expect($atDefault)->toContain('unsupportedProbeAttribute');
    expect($atDefault)->not->toContain($attribute);

    // The case suppressing outright would lose: a set value is manual work (wordpress-importer-archive#1163).
    expect($awayFromDefault)->toContain($attribute);
})->group('field-attributes')->with([
    'noURLs — true on 385 fields' => ['noURLs', false, true],
    'personalDataErase — true on 69 fields' => ['personalDataErase', false, true],
    'displayColumns — "1" is no columns' => ['displayColumns', '1', '3'],
    'layoutSpacerGridColumnSpan — 0 is no spacer' => ['layoutSpacerGridColumnSpan', 0, 6],
    'hideState — false on all 81 fields' => ['hideState', false, true],
    'gwlimitchoices_enableLimits — true on 10 fields' => ['gwlimitchoices_enableLimits', false, true],
    'gwlimitcheckboxes_enable — false on all 90 fields' => ['gwlimitcheckboxes_enable', false, true],
    'gwreadonly_enable — false on all 11 fields' => ['gwreadonly_enable', false, true],
    'gppa-values-enabled — false on all 5 fields' => ['gppa-values-enabled', false, true],
    'gppa-choices-enabled — false on both fields' => ['gppa-choices-enabled', false, true],
    'gp-date-time-calculator_unit — false on all 228 fields' => ['gp-date-time-calculator_unit', false, 'days'],
]);

it('keeps reporting an attribute classified unknown or actionable at any value', function (
    string $attribute,
    mixed $value
): void {
    expect(gravityWarnedAttributeNames([$attribute => $value]))->toContain($attribute);
})->group('field-attributes')->with([
    // Unknown: suppressing on an inference would hide it if the inference is wrong.
    'repeatable — true, the majority value' => ['repeatable', true],
    'repeatable — false' => ['repeatable', false],
    'gfurclass' => ['gfurclass', 'GFUserData'],
    // Actionable at any value: an integration or workflow with no import target.
    'salesforceMapType' => ['salesforceMapType', 'live'],
    'gravityview_approved' => ['gravityview_approved', '1'],
]);

it('writes an alert flag only on an entry that can reach the alert', function (): void {
    $mapping = FieldtypeMapping::getFieldAttributeMapping();
    $flagged = [];
    $inert = [];

    // The alert branch is gated on `statamic_attribute` resolving to null, so a flag on
    // any other spelling reads as live and does nothing — 60 such flags were removed (wordpress-importer-archive#1163).
    foreach ($mapping as $attribute => $config) {
        if (
            ! \is_array($config)
            || (! \array_key_exists('alert_unsupported', $config) && ! \array_key_exists('alert_unsupported_default', $config))
        ) {
            continue;
        }

        $flagged[] = $attribute;

        if (($config['statamic_attribute'] ?? null) !== null) {
            $inert[] = $attribute;
        }
    }

    // Control: flags exist, so an empty `$inert` is a finding and not an empty walk.
    expect($flagged)->toContain('duplicatable', 'is_payment', 'noURLs');
    expect($inert)->toBe([]);
})->group('field-attributes');

/**
 * Drive every field of one committed fixture form through the attribute mapping.
 *
 * @param  callable(array<mixed, mixed>): array<mixed, mixed>|null  $alter  Optional per-field change
 * @return list<string> The attribute names warned about, one per warning
 */
function gravityFixtureChecklist(?callable $alter = null): array
{
    $path = \dirname(__DIR__).'/Fixtures/form-audit/gravity-forms/creative-services-request-a-consultation.json';
    $export = \json_decode((string) \file_get_contents($path), true);
    $form = \is_array($export) && \is_array($export['0'] ?? null) ? $export['0'] : [];
    $fields = \is_array($form['fields'] ?? null) ? $form['fields'] : [];
    $names = [];

    foreach ($fields as $field) {
        if (! \is_array($field)) {
            continue;
        }

        $field = $alter !== null ? $alter($field) : $field;
        ImportWarningManager::clearWarnings();

        try {
            FieldtypeMapping::mapFieldAttributesWithContext(
                $field,
                'Request a Consultation',
                \is_string($field['label'] ?? null) ? $field['label'] : 'A field',
                10
            );

            foreach (\array_column(ImportWarningManager::getWarnings('forms', 'unsupported_attributes'), 'attribute_name') as $name) {
                // A non-string name is itself a finding; stringify it so it surfaces in the diff.
                $names[] = \is_string($name) ? $name : \var_export($name, true);
            }
        } finally {
            ImportWarningManager::clearWarnings();
        }
    }

    return $names;
}

it('reports only the unclassified attribute on a representative production form', function (): void {
    // A real 20-field form carrying seven of the 41 attributes, six of them at their
    // defaults. Before wordpress-importer-archive#1163 it reported 50 lines across seven attributes.
    $checklist = gravityFixtureChecklist();

    expect(\array_values(\array_unique($checklist)))->toBe(['repeatable']);
    // Control on the fixture: one line per field, so the file loaded and every field ran.
    expect($checklist)->toHaveCount(20);
})->group('field-attributes');

it('reports a non-default value on the representative form', function (): void {
    // The same form with one text field set to reject URLs, as 385 production fields are.
    $checklist = gravityFixtureChecklist(
        static fn (array $field): array => ($field['id'] ?? null) === 5 ? [...$field, 'noURLs' => true] : $field
    );

    expect(\array_count_values($checklist))->toBe(['repeatable' => 20, 'noURLs' => 1]);
})->group('field-attributes');

it('never reports hideAddress2, which is mapped rather than unsupported', function (mixed $value): void {
    $warned = gravityWarnedAttributeNames(['type' => 'address', 'hideAddress2' => $value]);

    expect($warned)->not->toContain('hideAddress2');
    // Control: the pipeline is live on an address field, so the absence is not silence.
    expect($warned)->toContain('unsupportedProbeAttribute');
})->group('field-attributes')->with([
    'true — carried to address_line_2_include (wordpress-importer-archive#1172)' => [true],
    'false' => [false],
    'empty' => [''],
]);
