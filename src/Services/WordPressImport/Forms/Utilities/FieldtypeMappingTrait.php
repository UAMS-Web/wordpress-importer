<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities;

use Exception;
use Illuminate\Support\Str;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FieldtypeMapping as FormidableFormsFieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FieldtypeMapping as GravityFormsFieldtypeMapping;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SafeMathEvaluator;

/**
 * Shared trait for fieldtype mapping functionality.
 *
 * This trait provides common methods for mapping WordPress form field types
 * to Statamic fieldtypes, including utilities for discovering and reporting
 * unmapped field types.
 */
trait FieldtypeMappingTrait
{
    /**
     * Validation lists for checking attribute values against predefined sets.
     *
     * This array contains lists of valid values that can be used to validate
     * attribute values during the mapping process. Each key represents a list
     * identifier that can be referenced in the fieldAttributeMapping array.
     *
     * @var array<string, array<string>>
     */
    protected static array $validationLists = [
        'visibility_values' => [
            'hidden',
        ],
    ];

    /**
     * Extracted other_label from options processing.
     *
     * This property stores the other_label extracted from other_* keys in options
     * during the transformOptionsJsonToArray process.
     */
    protected static ?string $extractedOtherLabel = null;

    /**
     * Normalize mixed values to array shape for safe iteration and offsets.
     *
     * @param  mixed  $value  The candidate array value
     * @return array<int|string, mixed> The normalized array value
     */
    protected static function asArray(
        mixed $value
    ): array {
        if (! \is_array($value)) {
            return [];
        }

        // Re-assert the broad declared shape so callers always receive a
        // generic mixed-keyed array. Without this widening, static analysis
        // would preserve narrow inferred shapes from configuration data and
        // falsely report dynamic keys (e.g. '#not_set') as non-existent.
        /** @var array<int|string, mixed> $value */
        return $value;
    }

    /**
     * Normalize mixed values to strings for safe string-only operations.
     *
     * @param  mixed  $value  The candidate string value
     * @return ?string The normalized string value
     */
    protected static function stringValue(
        mixed $value
    ): ?string {
        if (! \is_string($value)) {
            return null;
        }

        return $value;
    }

    /**
     * Normalize mixed configuration values to mapping arrays.
     *
     * @param  mixed  $value  The candidate mapping configuration
     * @return array<int|string, mixed> The normalized mapping configuration
     */
    protected static function mappingConfig(
        mixed $value
    ): array {
        return self::asArray($value);
    }

    /**
     * Read a mapping array entry when the key exists.
     *
     * @param  array<int|string, mixed>  $map  The mapping array
     * @param  int|string  $key  The key to read
     * @return mixed The mapped value, or null when the key is absent
     */
    protected static function tryMappingValue(
        array $map,
        int|string $key
    ): mixed {
        if (! array_key_exists($key, $map)) {
            return null;
        }

        return $map[$key];
    }

    /**
     * Safely read a value from a mixed array using a mixed key.
     *
     * Consolidates the recurring "check key exists, then read offset" pattern
     * for mixed-typed configuration data. Returns null when the candidate is
     * not an array or the normalized key is not present.
     *
     * @param  mixed  $array  The candidate array to read from
     * @param  mixed  $key  The candidate key to look up
     * @return mixed The stored value, or null when missing or inaccessible
     */
    protected static function mappingValue(
        mixed $array,
        mixed $key
    ): mixed {
        // Bail out when the candidate is not a real array.
        if (! \is_array($array)) {
            return null;
        }

        // Normalize the key to a valid int or string array offset.
        $normalizedKey = self::normalizeArrayKey($key);

        // Bail out when the key cannot be used as an array offset.
        if ($normalizedKey === null) {
            return null;
        }

        // Return the stored value when present, otherwise null.
        return $array[$normalizedKey] ?? null;
    }

    /**
     * Get the Statamic fieldtype for a given WordPress field.
     *
     * @param  string|array<int|string, mixed>  $wordpressField  The field type
     *                                                           or full WordPress field data
     * @return string|null|false Statamic fieldtype, null if not mapped, or
     *                           false if suppressed
     */
    abstract public static function getStatamicFieldtype(
        string|array $wordpressField
    ): string|null|false;

    /**
     * Get all unique WordPress form fieldtypes in the provided forms array.
     *
     * @param  array<int|string, mixed>  $forms  WordPress forms array
     * @return list<array<int|string, mixed>> Unique field arrays found
     */
    public static function getAllFieldtypes(
        array $forms
    ): array {
        // Initialize an array to collect all field types found in the forms
        $types = [];

        // Iterate through each form in the provided forms array
        foreach ($forms as $form) {
            // Skip entries that are not valid form arrays.
            if (! \is_array($form)) {
                continue;
            }

            // Resolve fields from the current form.
            $fields = $form['fields'] ?? null;

            // Check if the form has a non-empty 'fields' array
            if (
                $fields !== []
                && \is_array($fields)
            ) {
                // Iterate through each field in the form's 'fields' array
                foreach ($fields as $field) {
                    // Skip entries that are not valid field arrays.
                    if (! \is_array($field)) {
                        continue;
                    }

                    // Check if the field has a 'type' key
                    if (
                        isset($field['type'])
                        && \is_string($field['type'])
                    ) {
                        // Add the field array to the types array for attribute-based mapping
                        $types[] = $field;
                    }
                }
            }
        }

        // Return a unique list of field types found across all forms (by type and relevant attributes)
        return $types;
    }

    /**
     * Get all unmapped WordPress form fieldtypes in the provided forms array.
     *
     * @param  array<int|string, mixed>  $forms  WordPress forms array
     * @return list<string> Unmapped fieldtypes
     */
    public static function getUnmappedFieldtypes(
        array $forms
    ): array {
        // Get all unique field arrays found in the provided forms
        $allFields = self::getAllFieldtypes($forms);

        // Initialize an array to collect unmapped field types
        $unmapped = [];

        // Iterate through each field found in the forms
        foreach ($allFields as $field) {
            // Check if the field type is not mapped or explicitly mapped to null
            if (self::getStatamicFieldtype($field) === null) {
                if (
                    ! isset($field['type'])
                    || ! \is_string($field['type'])
                ) {
                    continue;
                }

                // Add the unmapped field type to the unmapped array
                $unmapped[] = $field['type'];
            }
        }

        // Return the list of unmapped field types
        return array_values(
            array_unique($unmapped)
        );
    }

    /**
     * Add a warning for an unmapped or null-mapped fieldtype.
     *
     * @param  string  $fieldType  The field type being checked
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     */
    public static function addUnmappedFieldtypeWarning(
        string $fieldType,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): void {
        ImportWarningManager::addWarning(
            'forms',
            'unmapped_fieldtypes',
            [
                'form_title' => $formTitle,
                'field_label' => $fieldLabel,
                'form_id' => $formId,
                'field_type' => $fieldType,
            ]
        );
    }

    /**
     * Check if a fieldtype mapping indicates the field should be processed.
     *
     * @param  array<int|string, mixed>  $mapping  The mapping configuration for a fieldtype
     * @return bool True if the fieldtype is mapped to a valid fieldtype or false (suppressed), false if unmapped
     */
    public static function isFieldtypeMapped(
        array $mapping
    ): bool {
        // Check if default is set and is not null (null means unmapped, false means suppressed)
        if (
            isset($mapping['default'])

        ) {
            return true;
        }

        // Check if any attribute-based mappings exist
        if (
            isset($mapping['attributes'])
            && \is_array($mapping['attributes'])
        ) {
            foreach ($mapping['attributes'] as $attrMap) {
                if (! \is_array($attrMap)) {
                    continue;
                }

                foreach ($attrMap as $val) {
                    if ($val !== null) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Check whether a skipped fieldtype should leave a trace in the imported blueprint.
     *
     * **A separate flag from `alert_unsupported`, deliberately.** That flag governs a
     * warning, whose audience is the operator running the import and who sees it once.
     * This one governs a note left in the blueprint, whose audience is an editor opening
     * the form months later. Tying the two together would mean the first time they needed
     * to diverge, one of them would be wrong — and they already diverge: `captcha` is
     * quiet AND leaves no trace, while `quantity` warns AND leaves one (wordpress-importer-archive#831).
     *
     * @param  array<int|string, mixed>  $mapping  The mapping configuration for a fieldtype
     * @return bool True when the type declares `trace_when_skipped => true`
     */
    public static function tracesWhenSkipped(
        array $mapping
    ): bool {
        // Absent means no trace: the flag opts a type in rather than out, so a type
        // added later is silent until someone decides it changes what the form does.
        return \array_key_exists(
            'trace_when_skipped',
            $mapping
        )
            && $mapping['trace_when_skipped'] === true;
    }

    /**
     * Check if a fieldtype is mapped to null or not mapped at all, and warn if so.
     *
     * @param  array<int|string, mixed>  $mapping  The mapping array (fieldtype => statamic_fieldtype)
     * @param  string  $fieldType  The field type to check
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     */
    public static function warnIfFieldtypeUnmapped(
        array $mapping,
        string $fieldType,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): void {
        $entry = $mapping[$fieldType] ?? null;

        // ---
        // A type declaring `alert_unsupported => false` is deliberately unsupported
        // and deliberately quiet: `captcha` has no Statamic equivalent and never
        // will, so warning on every one is noise that trains a reader to skim. The
        // flag was already declared per-type and simply never consulted here (wordpress-importer-archive#815).
        // ---
        if (
            \is_array($entry)
            && \array_key_exists(
                'alert_unsupported',
                $entry
            )
            && $entry['alert_unsupported'] === false
        ) {
            return;
        }

        if (
            ! self::arrayKeyExists(
                $fieldType,
                $mapping
            )
            || ! \is_array($mapping[$fieldType])
            || ! self::isFieldtypeMapped($mapping[$fieldType])
        ) {
            self::addUnmappedFieldtypeWarning(
                $fieldType,
                $formTitle,
                $fieldLabel,
                $formId
            );
        }
    }

    /**
     * Check if an attribute is not set (i.e., the key doesn't exist in the data
     * array). This is used to support '#not_set' in value_translation mappings.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array to check against
     * @return bool True if the attribute is not set, false otherwise
     */
    protected static function isAttributeNotSet(
        int|string|null $attrName,
        array $data
    ): bool {
        if (self::normalizeArrayKey($attrName) === null) {
            return true;
        }

        return ! self::arrayKeyExists(
            $attrName,
            $data
        );
    }

    /**
     * Check if an attribute is not set in field options specifically.
     *
     * This is used to support '#not_set' in value_translation mappings for field
     * options.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $fieldData  The original field data
     * @return bool True if the attribute is not set in field_options, false otherwise
     */
    protected static function isFieldOptionNotSet(
        int|string|null $attrName,
        array $fieldData
    ): bool {
        if (self::normalizeArrayKey($attrName) === null) {
            return true;
        }

        // Check if field_options exists and is a string
        if (
            ! isset($fieldData['field_options'])
            || ! \is_string($fieldData['field_options'])
        ) {
            return true; // If no field_options, consider it not set
        }

        // Parse the field_options JSON
        $fieldOptions = \json_decode(
            $fieldData['field_options'],
            true
        );
        if (! \is_array($fieldOptions)) {
            return true; // If parsing fails, consider it not set
        }

        // Check if the attribute exists in the parsed field_options
        return ! self::arrayKeyExists(
            $attrName,
            $fieldOptions
        );
    }

    /**
     * Check if an attribute is not empty (not null, not empty string, not empty
     * array).
     *
     * This is used to support '#not_empty' in value_translation mappings.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array
     * @return bool True if the attribute is set and not empty, false otherwise
     */
    protected static function isAttributeNotEmpty(
        int|string|null $attrName,
        array $data
    ): bool {
        $normalizedAttrName = self::normalizeArrayKey($attrName);

        if ($normalizedAttrName === null) {
            return false;
        }

        if (
            ! self::arrayKeyExists(
                $normalizedAttrName,
                $data
            )
        ) {
            return false; // Not set means not empty
        }

        $value = $data[$normalizedAttrName];

        // Check for null
        if ($value === null) {
            return false;
        }

        // Check for empty string
        if ($value === '') {
            return false;
        }

        // Check for empty array
        if (
            \is_array($value)
            && $value === []
        ) {
            return false;
        }

        // Check for array with only empty values (like ["foo":"","bar":""])
        if (\is_array($value)) {
            foreach ($value as $val) {
                if (
                    ! in_array($val, [null, '', []], true)
                ) {
                    return true; // Found at least one non-empty value
                }
            }

            return false; // All values are empty
        }

        return true; // Value exists and is not empty
    }

    /**
     * Check if an attribute is not empty in field options specifically.
     *
     * This is used to support '#not_empty' in value_translation mappings for field
     * options.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $fieldData  The original field data
     * @return bool True if the attribute is set and not empty in field_options, false otherwise
     */
    protected static function isFieldOptionNotEmpty(
        int|string|null $attrName,
        array $fieldData
    ): bool {
        $normalizedAttrName = self::normalizeArrayKey($attrName);

        if ($normalizedAttrName === null) {
            return false;
        }

        // Check if field_options exists and is a string
        if (
            ! isset($fieldData['field_options'])
            || ! \is_string($fieldData['field_options'])
        ) {
            return false; // If no field_options, consider it not empty
        }

        // Parse the field_options JSON
        $fieldOptions = \json_decode(
            $fieldData['field_options'],
            true
        );
        if (! \is_array($fieldOptions)) {
            return false; // If parsing fails, consider it not empty
        }

        // Check if the attribute exists in the parsed field_options
        if (
            ! self::arrayKeyExists(
                $normalizedAttrName,
                $fieldOptions
            )
        ) {
            return false; // Not set means not empty
        }

        $value = $fieldOptions[$normalizedAttrName];

        // Check for null
        if ($value === null) {
            return false;
        }

        // Check for empty string
        if ($value === '') {
            return false;
        }

        // Check for empty array
        if (
            \is_array($value)
            && $value === []
        ) {
            return false;
        }

        // Check for array with only empty values (like ["foo":"","bar":""])
        if (\is_array($value)) {
            foreach ($value as $val) {
                if (
                    ! in_array($val, [null, '', []], true)
                ) {
                    return true; // Found at least one non-empty value
                }
            }

            return false; // All values are empty
        }

        return true; // Value exists and is not empty
    }

    /**
     * Check if an attribute is not empty, handling both regular data and field
     * options.
     *
     * This is used to support '#not_empty' in various mapping contexts.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array
     * @param  array<int|string, mixed>|null  $originalFieldData  Optional original field data for field options
     * @return bool True if the attribute is set and not empty, false otherwise
     */
    protected static function isAttributeNotEmptyWithFallback(
        int|string|null $attrName,
        array $data,
        ?array $originalFieldData = null
    ): bool {
        // If we have original field data, check if the attribute is not empty in field options
        if ($originalFieldData !== null) {
            return self::isFieldOptionNotEmpty(
                $attrName,
                $originalFieldData
            );
        }

        // Fallback to checking if the attribute is not empty in the current data
        return self::isAttributeNotEmpty(
            $attrName,
            $data
        );
    }

    /**
     * Check if an attribute is not set, handling both regular data and field options.
     * This is used to support '#not_set' in various mapping contexts.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array
     * @param  array<int|string, mixed>|null  $originalFieldData  Optional original field data for field options
     * @return bool True if the attribute is not set, false otherwise
     */
    protected static function isAttributeNotSetWithFallback(
        int|string|null $attrName,
        array $data,
        ?array $originalFieldData = null
    ): bool {
        // If we have original field data, check if the attribute is not set in field options
        if ($originalFieldData !== null) {
            return self::isFieldOptionNotSet(
                $attrName,
                $originalFieldData
            );
        }

        // Fallback to checking if the attribute is not set in the current data
        return self::isAttributeNotSet(
            $attrName,
            $data
        );
    }

    /**
     * Check if an attribute is empty, handling both regular data and field options.
     * This is used to support '#empty' in various mapping contexts.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array
     * @param  array<int|string, mixed>|null  $originalFieldData  Optional original field data for field options
     * @return bool True if the attribute is set and is an empty string, false otherwise
     */
    protected static function isAttributeEmptyWithFallback(
        int|string|null $attrName,
        array $data,
        ?array $originalFieldData = null
    ): bool {
        // If we have original field data, check if the attribute is empty in field options
        if ($originalFieldData !== null) {
            return self::isFieldOptionEmpty(
                $attrName,
                $originalFieldData
            );
        }

        // Fallback to checking if the attribute is empty in the current data
        return self::isAttributeEmpty(
            $attrName,
            $data
        );
    }

    /**
     * Check if an attribute is empty (set but empty string).
     * This is used to support '#empty' in value_translation mappings.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $data  The data array
     * @return bool True if the attribute is set and is an empty string, false otherwise
     */
    protected static function isAttributeEmpty(
        int|string|null $attrName,
        array $data
    ): bool {
        $normalizedAttrName = self::normalizeArrayKey($attrName);

        if ($normalizedAttrName === null) {
            return false;
        }

        if (
            ! self::arrayKeyExists(
                $normalizedAttrName,
                $data
            )
        ) {
            return false; // Not set means not empty
        }

        $value = $data[$normalizedAttrName];

        // Check for empty string specifically
        return $value === '';
    }

    /**
     * Check if an attribute is empty in field options specifically.
     *
     * This is used to support '#empty' in value_translation mappings for field
     * options.
     *
     * @param  int|string  $attrName  The attribute name to check
     * @param  array<int|string, mixed>  $fieldData  The original field data
     * @return bool True if the attribute is set and is an empty string in field_options, false otherwise
     */
    protected static function isFieldOptionEmpty(
        int|string|null $attrName,
        array $fieldData
    ): bool {
        $normalizedAttrName = self::normalizeArrayKey($attrName);

        if ($normalizedAttrName === null) {
            return false;
        }

        // Check if field_options exists and is a string
        if (
            ! isset($fieldData['field_options'])
            || ! \is_string($fieldData['field_options'])
        ) {
            return false; // If no field_options, consider it not empty
        }

        // Parse the field_options JSON
        $fieldOptions = \json_decode(
            $fieldData['field_options'],
            true
        );
        if (! \is_array($fieldOptions)) {
            return false; // If parsing fails, consider it not empty
        }

        // Check if the attribute exists in the parsed field_options
        if (
            ! self::arrayKeyExists(
                $normalizedAttrName,
                $fieldOptions
            )
        ) {
            return false; // Not set means not empty
        }

        $value = $fieldOptions[$normalizedAttrName];

        // Check for empty string specifically
        return $value === '';
    }

    /**
     * Check if an array field option should be skipped for fieldtypes with subfields.
     *
     * This method identifies array field options like placeholder configurations
     * (e.g., {"first":"","middle":"","last":""}) that are used for fieldtypes
     * with subfields (e.g., name, address) and should be disregarded during
     * mapping since they represent subfield-specific configurations rather than
     * general field attributes.
     *
     * @param  string  $attrName  The attribute name to check
     * @param  mixed  $attrValue  The attribute value to check
     * @param  ?string  $fieldtype  The fieldtype to check against
     * @return bool True if the array field option should be skipped, false otherwise
     */
    protected static function shouldSkipArrayFieldOption(
        int|string|null $attrName,
        mixed $attrValue,
        ?string $fieldtype
    ): bool {
        // Only check array values
        if (! \is_array($attrValue)) {
            // Not an array, so no need to filter
            return false;
        }

        // Define fieldtypes that have subfields
        $fieldtypesWithSubfields = [
            'name',
            'address',
        ];

        // Only apply this logic to fieldtypes with subfields
        if (
            ! \in_array(
                $fieldtype,
                $fieldtypesWithSubfields,
                true
            )
        ) {
            // Clean up variables to free up memory
            unset(
                $fieldtypesWithSubfields
            );

            // Not a fieldtype with subfields, so no filtering needed
            return false;
        }

        // Check if this is a placeholder configuration for subfields
        // Common patterns: {"first":"","middle":"","last":""} for name fields
        // or similar structures for address fields
        if ($attrName === 'placeholder') {
            $allEmptyStrings = array_all($attrValue, fn ($value): bool => $value === '');
            // If all values are empty strings, this is likely a subfield configuration
            if ($allEmptyStrings) {
                // Clean up variables to free up memory
                unset(
                    $fieldtypesWithSubfields,
                    $allEmptyStrings
                );

                // Skip this array field option as it's a subfield configuration
                return true;
            }
        }

        // Clean up variables to free up memory
        unset(
            $fieldtypesWithSubfields
        );

        // No filtering needed for this attribute
        return false;
    }

    /**
     * Core attribute mapping logic shared by field and subfield mapping.
     *
     * If the mapping's statamic_attribute contains a '*', it will be replaced
     * with the provided $handle (e.g., '*_default' => 'address_line_1_default').
     *
     * Supports 'alternate_statamic_attribute' for edge cases:
     *   - If set, and the current source fieldtype (from $excludeFieldtype) is
     *     present in the array, map the attribute to the alternate attribute for that
     *     fieldtype and do NOT also map it to the default statamic_attribute for that
     *     fieldtype.
     *
     * Supports comprehensive mapping structures with priority-based resolution:
     *   - 'statamic_attribute.based_on_attribute_value.default/specific': Maps to
     *     different Statamic attributes based on the attribute value
     *   - 'statamic_attribute.based_on_other_attribute_value.default/specific': Maps
     *     to different Statamic attributes based on other attribute values
     *   - 'statamic_attribute.default/specific': Fieldtype-specific or default
     *     attribute mappings
     *   - 'statamic_other_field_value_based_on_attribute_value.default/specific':
     *     Sets other field values based on the attribute value
     *   - 'statamic_other_field_value_based_on_nested_attribute_value.default/specific':
     *     Sets other field values based on nested attribute patterns
     *   - 'statamic_other_field_value': Sets other field values when this attribute
     *     is non-empty
     *   - 'choices_as_fields.based_on_attribute_value.default/specific': Converts
     *     choices to fields based on attribute value
     *   - 'choices_as_fields.based_on_other_attribute_value.default/specific':
     *     Converts choices to fields based on other attribute values
     *   - 'choices_as_fields.default/specific': Fieldtype-specific or default c
     *     hoices-to-fields conversion
     *   - 'expected_keys.based_on_attribute_value.default/specific': Sets expected
     *     keys based on attribute value
     *   - 'expected_keys.based_on_other_attribute_value.default/specific': Sets
     *     expected keys based on other attribute values
     *   - 'expected_keys.default/specific': Fieldtype-specific or default expected
     *     keys
     *   - 'get_selected_choice.based_on_attribute_value.default/specific': Processes
     *     selected choice based on attribute value
     *   - 'get_selected_choice.based_on_other_attribute_value.default/specific':
     *     Processes selected choice based on other attribute values
     *   - 'get_selected_choice.default/specific': Fieldtype-specific or default
     *     selected choice processing
     *   - 'key_translation.based_on_attribute_value.default/specific': Translates
     *     keys based on attribute value
     *   - 'key_translation.based_on_other_attribute_value.default/specific':
     *     Translates keys based on other attribute values
     *   - 'key_translation.default/specific': Fieldtype-specific or default key
     *     translation
     *   - 'preserve_newlines.based_on_attribute_value.default/specific': Preserves
     *     newlines based on attribute value
     *   - 'preserve_newlines.based_on_other_attribute_value.default/specific':
     *     Preserves newlines based on other attribute values
     *   - 'preserve_newlines.default/specific': Fieldtype-specific or default newline
     *     preservation
     *   - 'separate_value_field_option.based_on_attribute_value.default/specific':
     *     Separates value field option based on attribute value
     *   - 'separate_value_field_option.based_on_other_attribute_value.default/specific':
     *     Separates value field option based on other attribute values
     *   - 'separate_value_field_option.default/specific': Fieldtype-specific or
     *     default value field option separation
     *   - 'value_translation.default/specific': Fieldtype-specific or default value
     *     translation
     *   - 'extract_array_value': Extracts specific key values from array of arrays
     *     (e.g., 'text' from [{'text': 'value1'}, {'text': 'value2'}])
     *
     * @param  array<int|string, mixed>  $data  The input data (field or subfield)
     * @param  array<int|string, mixed>  $mapping  The mapping array (attribute name => mapping config)
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field reference translation
     * @param  ?string  $handle  Optional handle for wildcard replacement
     * @param  ?string  $excludeFieldtype  Optional source fieldtype for exclude_fieldtypes/alternate_statamic_attribute
     *                                     logic
     * @param  array<int|string, mixed>|null  $originalFieldData  Optional original field data for #not_set checks in field options
     * @param  ?string  $siteHandle  Optional site handle for [#site_handle] in mapped values
     * @return array<int|string, mixed> Mapped Statamic attributes
     */
    protected static function mapAttributesFromMapping(
        array $data,
        array $mapping,
        array $allFields = [],
        ?string $handle = null,
        ?string $excludeFieldtype = null,
        ?array $originalFieldData = null,
        ?string $siteHandle = null
    ): array {
        $mappedData = [];

        // First, process attributes that exist in the data
        foreach ($data as $attrName => $attrValue) {
            // Skip if this attribute is not in our mapping
            if (! isset($mapping[$attrName])) {
                continue;
            }

            // Skip array field options for fieldtypes with subfields (e.g., placeholder configurations)
            if (
                static::shouldSkipArrayFieldOption(
                    $attrName,
                    $attrValue,
                    $excludeFieldtype
                )
            ) {
                continue;
            }

            $attrConfig = $mapping[$attrName];
            $attrConfigMap = self::mappingConfig($attrConfig);

            // Normalize the attribute name to a string for downstream helpers
            // and offset lookups that expect string keys. Numeric string keys
            // are normalized back to integers automatically on array access.
            $attrName = (string) $attrName;

            // Get the appropriate configuration based on fieldtype
            $config = static::getFieldtypeSpecificConfig(
                $attrConfigMap,
                $excludeFieldtype,
                $attrName
            );

            // Check exclude_fieldtypes logic
            if (
                static::shouldExcludeFieldtype(
                    $config,
                    $excludeFieldtype
                )
            ) {
                continue;
            }

            // Skip if there is no Statamic attribute to map to and no other field value mappings
            if (
                ! isset($config['statamic_attribute'])
                && ! isset($config['statamic_other_field_value'])
                && ! isset($config['statamic_other_field_value_based_on_attribute_value'])
                && ! isset($config['statamic_other_field_value_based_on_nested_attribute_value'])
                && ! isset($config['statamic_type_based_on_attribute_value']
                )
            ) {
                continue; // Skip this attribute if no mappings are available
            }

            // Handle statamic_attribute.based_on_other_attribute_value
            $statamicAttr = $config['statamic_attribute'] ?? null;

            // Initialize generic match found flag
            $matchFound = false;

            // Initialize mappedValue
            $mappedValue = null;

            // Only process statamic_attribute if it's set and has a value
            if (
                $statamicAttr !== null
                && $statamicAttr !== ''
            ) {
                // Process array-based configurations with correct hierarchy order
                if (\is_array($statamicAttr)) {
                    $statamicAttrConfig = self::mappingConfig($statamicAttr);

                    // 1. Check for based_on_attribute_value.specific (highest priority)
                    $basedOnAttributeValue = self::mappingConfig(
                        $statamicAttrConfig['based_on_attribute_value'] ?? null
                    );
                    if (isset($basedOnAttributeValue['specific'])) {
                        $result = self::processBasedOnAttributeValue(
                            $basedOnAttributeValue,
                            $excludeFieldtype,
                            $attrName,
                            $attrValue,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // 2. Check for based_on_attribute_value.default (second priority)
                    if (
                        isset($basedOnAttributeValue['default'])
                        && ! $matchFound
                    ) {
                        $result = self::processBasedOnAttributeValue(
                            $basedOnAttributeValue,
                            $excludeFieldtype,
                            $attrName,
                            $attrValue,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // 3. Check for based_on_other_attribute_value.specific (third priority)
                    $basedOnOtherAttributeValue = self::mappingConfig(
                        $statamicAttrConfig['based_on_other_attribute_value'] ?? null
                    );
                    if (
                        isset($basedOnOtherAttributeValue['specific'])
                        && ! $matchFound
                    ) {
                        $result = self::processBasedOnOtherAttributeValue(
                            $basedOnOtherAttributeValue,
                            $excludeFieldtype,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // 4. Check for based_on_other_attribute_value.default (fourth priority)
                    if (
                        isset($basedOnOtherAttributeValue['default'])
                        && ! $matchFound
                    ) {
                        $result = self::processBasedOnOtherAttributeValue(
                            $basedOnOtherAttributeValue,
                            $excludeFieldtype,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // 5. Check for specific fieldtype configuration (fifth priority)
                    if (
                        isset($statamicAttrConfig['specific'])
                        && ! $matchFound
                    ) {
                        $specificConfig = self::mappingConfig(
                            $statamicAttrConfig['specific']
                        );
                        if (
                            $excludeFieldtype !== null
                            && $excludeFieldtype !== ''
                            && self::arrayKeyExists(
                                $excludeFieldtype,
                                $specificConfig
                            )
                        ) {
                            $statamicAttr = $specificConfig[$excludeFieldtype];
                            $matchFound = true;
                        }
                    }

                    // 6. Check for default configuration (lowest priority)
                    if (
                        isset($statamicAttrConfig['default'])
                        && ! $matchFound
                    ) {
                        $statamicAttr = $statamicAttrConfig['default'];
                        $matchFound = true;
                    }

                    // Legacy support for old structures
                    // Check for specific_based_on_attribute_value (legacy)
                    if (
                        isset($statamicAttrConfig['specific_based_on_attribute_value'])
                        && ! $matchFound
                    ) {
                        $specificBasedOnConfig = self::mappingConfig(
                            $statamicAttrConfig['specific_based_on_attribute_value']
                        );
                        $result = self::processBasedOnAttributeValue(
                            $specificBasedOnConfig,
                            $excludeFieldtype,
                            $attrName,
                            $attrValue,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // Check for based_on_attribute_value (legacy)
                    if (
                        $basedOnAttributeValue !== []
                        && ! isset($basedOnAttributeValue['default'])
                        && ! isset($basedOnAttributeValue['specific'])
                        && ! $matchFound
                    ) {
                        $result = self::processBasedOnAttributeValue(
                            $basedOnAttributeValue,
                            $excludeFieldtype,
                            $attrName,
                            $attrValue,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }

                    // Check for based_on_other_attribute_value (legacy)
                    if (
                        $basedOnOtherAttributeValue !== []
                        && ! isset($basedOnOtherAttributeValue['default'])
                        && ! isset($basedOnOtherAttributeValue['specific'])
                        && ! $matchFound
                    ) {
                        $result = self::processBasedOnOtherAttributeValue(
                            $basedOnOtherAttributeValue,
                            $excludeFieldtype,
                            $data
                        );
                        if ($result !== null) {
                            [$statamicAttr, $matchFound] = $result;
                        }
                    }
                }

                // Only process statamic_attribute mapping if it's a valid string
                if (\is_string($statamicAttr)) {
                    if (
                        $handle !== null && $handle !== ''
                        && \str_contains(
                            $statamicAttr,
                            '*'
                        )
                    ) {
                        // Replace '*' with the actual handle for subfield mapping
                        $statamicAttr = \str_replace(
                            '*',
                            $handle,
                            $statamicAttr
                        );
                    }

                    // Set the mapped value to the original attribute value by default
                    $mappedValue = $attrValue;
                }
            }

            // Extract array value: extract specific key values from array of arrays
            // --------------------------------------------------------------------------------
            // The 'extract_array_value' configuration allows extracting specific key values
            // from an array of associative arrays. This is useful for processing complex
            // data structures like Gravity Forms survey field rows.
            //
            // Example:
            //   Input: [{'text': 'First row', 'value': 'id1'}, {'text': 'Second row', 'value': 'id2'}]
            //   Config: 'extract_array_value' => 'text'
            //   Output: ['First row', 'Second row']
            //
            // The configuration expects a string value representing the key to extract
            // from each array element. Only array elements that contain the specified
            // key will have their values extracted.
            // --------------------------------------------------------------------------------
            if (
                isset($config['extract_array_value'])
                && \is_string($config['extract_array_value'])
                && \is_array($mappedValue)
            ) {
                $extractKey = $config['extract_array_value'];
                $extractedValues = [];

                // Process each array element in the mapped value
                foreach ($mappedValue as $arrayElement) {
                    if (
                        \is_array($arrayElement)
                        && isset($arrayElement[$extractKey])
                    ) {
                        $extractedValues[] = $arrayElement[$extractKey];
                    }
                }

                // Replace the mapped value with the extracted values
                $mappedValue = $extractedValues;
            }

            // Extract object values: collect a JSON object's values into a
            // de-duplicated, translated list.
            // --------------------------------------------------------------------------------
            // The 'extract_object_values' configuration handles attributes whose source
            // value is a JSON object (decoded to an associative array) where only the
            // values are meaningful — e.g. Formidable Forms `ftypes`, which maps extension
            // groups to MIME types ({"jpg|jpeg|jpe":"image/jpeg", ...}) and feeds the
            // Statamic assets `mime_types` list.
            //
            // The object's values are collected, run through the attribute's existing
            // `value_translation` rules (e.g. application/x-gzip => application/gzip), and
            // de-duplicated while preserving order. The shared string pipeline below
            // (explodeString, trim, etc.) is not object-aware, so this step is
            // self-contained and short-circuits the remaining processing for the attribute.
            //
            // Example:
            //   Input: {"jpg|jpeg|jpe":"image/jpeg","png":"image/png"}
            //   Config: 'extract_object_values' => true
            //   Output: ['image/jpeg', 'image/png']
            // --------------------------------------------------------------------------------
            if (
                isset($config['extract_object_values'])
                && $config['extract_object_values'] === true
                && \is_array($mappedValue)
                && \is_string($statamicAttr)
                && $statamicAttr !== ''
            ) {
                // Collect the object's values, dropping the (extension-group) keys.
                $objectValues = \array_values($mappedValue);

                // Run the attribute's existing per-value translation rules.
                $objectValues = static::applyValueTranslation(
                    $objectValues,
                    $config,
                    $attrName,
                    $data,
                    $excludeFieldtype,
                    $originalFieldData,
                    $statamicAttr
                );

                // Keep only non-empty strings, then de-duplicate while preserving
                // order. A well-formed source object maps each key to a string, but a
                // malformed one (nested values, numbers, null) would otherwise leak
                // arrays/nulls into the Statamic blueprint as list items.
                $objectValues = \array_values(
                    \array_unique(
                        \array_filter(
                            \is_array($objectValues) ? $objectValues : [],
                            static fn (mixed $value): bool => \is_string($value) && $value !== ''
                        ),
                        SORT_REGULAR
                    )
                );

                // Emit the attribute only when a value survived; an empty list is dead
                // config the string-oriented pipeline below would never have produced.
                if ($objectValues !== []) {
                    $mappedData[$statamicAttr] = $objectValues;
                }

                // The attribute is fully handled here; the string-oriented pipeline
                // below would otherwise discard the array, so skip to the next attribute.
                continue;
            }

            // Key translation for array values (like choices)
            if (
                isset($config['key_translation'])
                && \is_array($config['key_translation'])
                && \is_array($mappedValue)
            ) {
                $keyTranslationConfig = self::mappingConfig(
                    $config['key_translation']
                );
                $keyTranslationArray = null;

                // Handle different key_translation structures
                if (
                    self::arrayKeyExists(
                        'default',
                        $keyTranslationConfig
                    )
                    || self::arrayKeyExists(
                        'specific',
                        $keyTranslationConfig
                    )
                    || self::arrayKeyExists(
                        'based_on_other_attribute_value',
                        $keyTranslationConfig
                    )
                    || self::arrayKeyExists(
                        'specific_based_on_attribute_value',
                        $keyTranslationConfig
                    )
                ) {
                    // Complex structure with 'default', 'specific', 'based_on_other_attribute_value', etc.
                    $defaultTranslations = self::asArray(
                        $keyTranslationConfig['default'] ?? null
                    );
                    $specificTranslations = self::asArray(
                        $keyTranslationConfig['specific'] ?? null
                    );
                    $basedOnOtherAttributeValue = self::asArray(
                        $keyTranslationConfig['based_on_other_attribute_value'] ?? null
                    );
                    $specificBasedOnAttributeValue = self::asArray(
                        $keyTranslationConfig['specific_based_on_attribute_value'] ?? null
                    );

                    // Check for specific_based_on_attribute_value first (most specific)
                    if (
                        $excludeFieldtype !== null
                        && $excludeFieldtype !== ''
                        && self::arrayKeyExists(
                            $excludeFieldtype,
                            $specificBasedOnAttributeValue
                        )
                    ) {
                        $fieldtypeMappings = self::asArray(
                            $specificBasedOnAttributeValue[$excludeFieldtype]
                        );
                        foreach ($fieldtypeMappings as $attributeName => $attributeValueMappings) {
                            $normalizedAttributeName = self::normalizeArrayKey(
                                $attributeName
                            );
                            if ($normalizedAttributeName === null) {
                                continue;
                            }

                            $attributeValueMappings = self::asArray(
                                $attributeValueMappings
                            );

                            // Get the value of the attribute from the field data
                            $attributeValue = $data[$normalizedAttributeName] ?? null;

                            // Check for special cases first
                            if (isset($attributeValueMappings['#not_set'])) {
                                if ($originalFieldData !== null) {
                                    if (
                                        self::isFieldOptionNotSet(
                                            $normalizedAttributeName,
                                            $originalFieldData
                                        )
                                    ) {
                                        $keyTranslationArray = self::asArray(
                                            $attributeValueMappings['#not_set']
                                        );
                                        break;
                                    }
                                } else {
                                    if (
                                        self::isAttributeNotSet(
                                            $normalizedAttributeName,
                                            $data
                                        )
                                    ) {
                                        $keyTranslationArray = self::asArray(
                                            $attributeValueMappings['#not_set']
                                        );
                                        break;
                                    }
                                }
                            } elseif (isset($attributeValueMappings['#not_empty'])) { // Check for '#not_empty' special case
                                if (
                                    self::isAttributeNotEmptyWithFallback(
                                        $normalizedAttributeName,
                                        $data,
                                        $originalFieldData
                                    )
                                ) {
                                    $keyTranslationArray = self::asArray(
                                        $attributeValueMappings['#not_empty']
                                    );
                                    break;
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    $attributeValue,
                                    $attributeValueMappings
                                )
                            ) { // Check if this attribute value has specific mappings
                                $keyTranslationArray = self::asArray(
                                    self::mappingValue(
                                        $attributeValueMappings,
                                        $attributeValue
                                    )
                                );
                                break;
                            }
                        }
                    } elseif ($basedOnOtherAttributeValue !== []) { // Check for based_on_other_attribute_value
                        // Check if based_on_other_attribute_value has 'default' and 'specific' structure
                        if (
                            self::arrayKeyExists(
                                'default',
                                $basedOnOtherAttributeValue
                            )
                            || self::arrayKeyExists(
                                'specific',
                                $basedOnOtherAttributeValue
                            )
                        ) {
                            $defaultBasedOnOtherAttributeValue = self::asArray(
                                $basedOnOtherAttributeValue['default'] ?? null
                            );
                            $specificBasedOnOtherAttributeValue = self::asArray(
                                $basedOnOtherAttributeValue['specific'] ?? null
                            );

                            // Use fieldtype-specific configuration if available
                            $configToUse = $defaultBasedOnOtherAttributeValue;
                            if (
                                $excludeFieldtype !== null
                                && $excludeFieldtype !== ''
                                && self::arrayKeyExists(
                                    $excludeFieldtype,
                                    $specificBasedOnOtherAttributeValue
                                )
                            ) {
                                $configToUse = self::asArray(
                                    $specificBasedOnOtherAttributeValue[$excludeFieldtype]
                                );
                            }

                            foreach ($configToUse as $otherAttrName => $valueMappings) {
                                $normalizedOtherAttrName = self::normalizeArrayKey(
                                    $otherAttrName
                                );
                                if ($normalizedOtherAttrName === null) {
                                    continue;
                                }

                                $valueMappings = self::asArray($valueMappings);
                                $otherAttrValue = $data[$normalizedOtherAttrName] ?? null;

                                // Find the appropriate mapping based on the other attribute's value
                                $currentValueMappings = null;

                                // Check for '#not_set' special case first
                                if (
                                    self::arrayKeyExists(
                                        '#not_set',
                                        $valueMappings
                                    )
                                ) {
                                    if (
                                        self::isAttributeNotSet(
                                            $normalizedOtherAttrName,
                                            $data
                                        )
                                    ) {
                                        $currentValueMappings = self::asArray(
                                            $valueMappings['#not_set'] ?? null
                                        );
                                    }
                                } elseif (
                                    self::arrayKeyExists(
                                        '#not_empty',
                                        $valueMappings
                                    )
                                ) { // Check for '#not_empty' special case
                                    if (
                                        self::isAttributeNotEmpty(
                                            $normalizedOtherAttrName,
                                            $data
                                        )
                                    ) {
                                        $currentValueMappings = self::asArray(
                                            $valueMappings['#not_empty'] ?? null
                                        );
                                    }
                                } elseif (
                                    self::arrayKeyExists(
                                        '#empty',
                                        $valueMappings
                                    )
                                ) { // Check for '#empty' special case
                                    if (
                                        self::isAttributeEmpty(
                                            $normalizedOtherAttrName,
                                            $data
                                        )
                                    ) {
                                        $currentValueMappings = self::asArray(
                                            $valueMappings['#empty'] ?? null
                                        );
                                    }
                                } elseif (
                                    self::arrayKeyExists(
                                        $otherAttrValue,
                                        $valueMappings
                                    )
                                ) { // Check for exact value match
                                    $currentValueMappings = self::asArray(
                                        self::mappingValue(
                                            $valueMappings,
                                            $otherAttrValue
                                        )
                                    );
                                }

                                // If we found mappings for the other attribute's value, apply them to this attribute
                                if ($currentValueMappings !== null) {
                                    // Check for '#not_set' special case first
                                    if (
                                        self::arrayKeyExists(
                                            '#not_set',
                                            $currentValueMappings
                                        )
                                    ) {
                                        if ($originalFieldData !== null) {
                                            if (
                                                self::isFieldOptionNotSet(
                                                    $attrName,
                                                    $originalFieldData
                                                )
                                            ) {
                                                $keyTranslationArray = self::asArray(
                                                    $currentValueMappings['#not_set'] ?? null
                                                );
                                                break;
                                            }
                                        } else {
                                            if (
                                                self::isAttributeNotSet(
                                                    $attrName,
                                                    $data
                                                )
                                            ) {
                                                $keyTranslationArray = self::asArray(
                                                    $currentValueMappings['#not_set'] ?? null
                                                );
                                                break;
                                            }
                                        }
                                    } elseif (
                                        self::arrayKeyExists(
                                            '#not_empty',
                                            $currentValueMappings
                                        )
                                    ) { // Check for '#not_empty' special case
                                        if (
                                            self::isAttributeNotEmptyWithFallback(
                                                $attrName,
                                                $data,
                                                $originalFieldData
                                            )
                                        ) {
                                            $keyTranslationArray = self::asArray(
                                                $currentValueMappings['#not_empty'] ?? null
                                            );
                                            break;
                                        }
                                    } elseif (
                                        self::arrayKeyExists(
                                            '#empty',
                                            $currentValueMappings
                                        )
                                    ) { // Check for '#empty' special case
                                        if (
                                            self::isAttributeEmptyWithFallback(
                                                $attrName,
                                                $data,
                                                $originalFieldData
                                            )
                                        ) {
                                            $keyTranslationArray = self::asArray(
                                                $currentValueMappings['#empty'] ?? null
                                            );
                                            break;
                                        }
                                    } elseif (
                                        self::arrayKeyExists(
                                            self::mappingLookupKey($mappedValue),
                                            $currentValueMappings
                                        )
                                    ) {
                                        $keyTranslationArray = self::asArray(
                                            $currentValueMappings[self::mappingLookupKey($mappedValue)]
                                        );
                                        break;
                                    }
                                }
                            }
                        } else {
                            // Legacy structure
                            foreach ($basedOnOtherAttributeValue as $otherAttrName => $valueMappings) {
                                $normalizedOtherAttrName = self::normalizeArrayKey(
                                    $otherAttrName
                                );
                                if ($normalizedOtherAttrName === null) {
                                    continue;
                                }

                                $valueMappings = self::asArray($valueMappings);
                                $otherAttrValue = $data[$normalizedOtherAttrName] ?? null;

                                // Find the appropriate mapping based on the other attribute's value
                                $currentValueMappings = null;

                                // Check for '#not_set' special case first
                                if (
                                    self::arrayKeyExists(
                                        '#not_set',
                                        $valueMappings
                                    )
                                ) {
                                    if (
                                        self::isAttributeNotSet(
                                            $normalizedOtherAttrName,
                                            $data
                                        )
                                    ) {
                                        $currentValueMappings = self::asArray(
                                            $valueMappings['#not_set'] ?? null
                                        );
                                    }
                                } elseif (
                                    self::arrayKeyExists(
                                        '#not_empty',
                                        $valueMappings
                                    )
                                ) { // Check for '#not_empty' special case
                                    if (
                                        self::isAttributeNotEmpty(
                                            $normalizedOtherAttrName,
                                            $data
                                        )
                                    ) {
                                        $currentValueMappings = self::asArray(
                                            $valueMappings['#not_empty'] ?? null
                                        );
                                    }
                                } elseif (
                                    self::arrayKeyExists(
                                        '#empty',
                                        $valueMappings
                                    )
                                ) { // Check for '#empty' special case
                                    if (
                                        self::isAttributeEmpty(
                                            $normalizedOtherAttrName,
                                            $data
                                        )
                                    ) {
                                        $currentValueMappings = self::asArray(
                                            $valueMappings['#empty'] ?? null
                                        );
                                    }
                                } elseif (
                                    self::arrayKeyExists(
                                        $otherAttrValue,
                                        $valueMappings
                                    )
                                ) { // Check for exact value match
                                    $currentValueMappings = self::asArray(
                                        self::mappingValue(
                                            $valueMappings,
                                            $otherAttrValue
                                        )
                                    );
                                }

                                // If we found mappings for the other attribute's value, apply them to this attribute
                                if ($currentValueMappings !== null) {
                                    // Check for '#not_set' special case first
                                    if (
                                        self::arrayKeyExists(
                                            '#not_set',
                                            $currentValueMappings
                                        )
                                    ) {
                                        if ($originalFieldData !== null) {
                                            if (
                                                self::isFieldOptionNotSet(
                                                    $attrName,
                                                    $originalFieldData
                                                )
                                            ) {
                                                $keyTranslationArray = self::asArray(
                                                    $currentValueMappings['#not_set'] ?? null
                                                );
                                                break;
                                            }
                                        } else {
                                            if (
                                                self::isAttributeNotSet(
                                                    $attrName,
                                                    $data
                                                )
                                            ) {
                                                $keyTranslationArray = self::asArray(
                                                    $currentValueMappings['#not_set'] ?? null
                                                );
                                                break;
                                            }
                                        }
                                    } elseif (
                                        self::arrayKeyExists(
                                            '#not_empty',
                                            $currentValueMappings
                                        )
                                    ) { // Check for '#not_empty' special case
                                        if (
                                            self::isAttributeNotEmptyWithFallback(
                                                $attrName,
                                                $data,
                                                $originalFieldData
                                            )
                                        ) {
                                            $keyTranslationArray = self::asArray(
                                                $currentValueMappings['#not_empty'] ?? null
                                            );
                                            break;
                                        }
                                    } elseif (
                                        self::arrayKeyExists(
                                            '#empty',
                                            $currentValueMappings
                                        )
                                    ) { // Check for '#empty' special case
                                        if (
                                            self::isAttributeEmptyWithFallback(
                                                $attrName,
                                                $data,
                                                $originalFieldData
                                            )
                                        ) {
                                            $keyTranslationArray = self::asArray(
                                                $currentValueMappings['#empty'] ?? null
                                            );
                                            break;
                                        }
                                    } elseif (
                                        self::arrayKeyExists(
                                            self::mappingLookupKey($mappedValue),
                                            $currentValueMappings
                                        )
                                    ) {
                                        $keyTranslationArray = self::asArray(
                                            $currentValueMappings[self::mappingLookupKey($mappedValue)]
                                        );
                                        break;
                                    }
                                }
                            }
                        }
                    } elseif (
                        $excludeFieldtype !== null
                        && $excludeFieldtype !== ''
                        && self::arrayKeyExists(
                            $excludeFieldtype,
                            $specificTranslations
                        )
                    ) { // Check for fieldtype-specific translations
                        $keyTranslationArray = self::asArray(
                            $specificTranslations[$excludeFieldtype]
                        );
                    } else { // Fall back to default translations
                        $keyTranslationArray = $defaultTranslations;
                    }
                } else {
                    // Simple array structure
                    $keyTranslationArray = self::asArray($keyTranslationConfig);
                }

                // Apply key translations if we have a valid translation array
                if ($keyTranslationArray !== null) {
                    // Normalize value transformations to an array or null so the
                    // translation helper receives the expected nullable array.
                    $valueTransformationsConfig = $config['value_transformations'] ?? null;
                    $valueTransformations = \is_array($valueTransformationsConfig)
                        ? $valueTransformationsConfig
                        : null;
                    $mappedValue = self::mapArrayWithKeyTranslation(
                        $mappedValue,
                        $keyTranslationArray,
                        $valueTransformations,
                        $excludeFieldtype,
                        $data
                    );
                }

                // Handle separate_value_field_option logic for array values (like Gravity Forms choices)
                if (
                    isset($config['separate_value_field_option'])

                ) {
                    $separateValueFieldOptionConfig = $config['separate_value_field_option'];

                    // Use the DRY helper method to process modifier patterns
                    $modifierResult = self::processModifierPatterns(
                        $separateValueFieldOptionConfig,
                        $data,
                        null,
                        $excludeFieldtype,
                        $attrName,
                        $attrValue
                    );
                    $separateValue = $modifierResult['value'];
                    $matchFound = $modifierResult['matchFound'];

                    // $separateValue is NOT the field's runtime enableChoiceValue here:
                    // processModifierPatterns() resolves the config
                    // ['default' => 'enableChoiceValue', 'specific' => ['survey' => null]]
                    // to a constant — (bool) 'enableChoiceValue' === true for every
                    // non-survey fieldtype, and false only for survey. The real
                    // enableChoiceValue-driven label nulling happens upstream in
                    // mapArrayWithKeyTranslation() (value_transformations). So this branch
                    // only nulls the option value for SURVEY fields, where a separate
                    // choice value is not applicable. The prior `!== null && !== ''`
                    // condition also fired on the constant `true`, erroneously nulling
                    // every non-survey option label; `=== false` restricts it to survey.
                    // See wordpress-importer-archive#463.
                    if ($separateValue === false) {
                        foreach ($mappedValue as &$option) {
                            if (
                                \is_array($option)
                                && isset($option['value'])
                            ) {
                                $option['value'] = null;
                            }
                        }

                        // Clean up variables to free up memory
                        unset($option);
                    }
                }

                // Ensure all options have the expected structure based on fieldtype
                if (isset($config['expected_keys'])) {
                    self::normalizeOptionsStructure(
                        $mappedValue,
                        $data,
                        $attrValue,
                        $excludeFieldtype,
                        $attrName
                    );
                }
            }

            // Combine value with other attribute values
            $mappedValue = static::processCombineValueWithOtherAttributeValue(
                $mappedValue,
                $config,
                $data
            );

            // Explode string (must be applied before value_translation). Only
            // string and array values are eligible; other types pass through
            // unchanged just as the helper would return them.
            if (
                \is_string($mappedValue)
                || \is_array($mappedValue)
            ) {
                $mappedValue = static::explodeString(
                    $mappedValue,
                    $config
                );
            }

            // Trim whitespace (applied after explode_string to ensure proper trimming of individual elements)
            $mappedValue = static::trimValue(
                $mappedValue,
                $config
            );

            // Trim specific characters (applied after trim to ensure proper
            // character trimming). Only string, array, and null values are
            // eligible; other types pass through unchanged.
            if (
                $mappedValue === null
                || \is_string($mappedValue)
                || \is_array($mappedValue)
            ) {
                $mappedValue = static::trimCharacters(
                    $mappedValue,
                    $config
                );
            }

            // Apply value translation if configured
            $mappedValue = static::applyValueTranslation(
                $mappedValue,
                $config,
                $attrName,
                $data,
                $excludeFieldtype,
                $originalFieldData,
                $statamicAttr
            );

            // Value substitution regex: apply regex patterns to transform values
            if (
                isset($config['value_substitution_regex'])
                && \is_array($config['value_substitution_regex'])
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = self::applyValueSubstitutionRegex(
                    $mappedValue,
                    $config['value_substitution_regex']
                );
            }

            // Value calculation: apply mathematical expressions to transform values
            if (isset($config['value_calculation'])) {
                $calculationConfig = $config['value_calculation'];

                // Handle different value_calculation structures
                if (\is_string($calculationConfig)) {
                    // Simple string expression - only apply if value is numeric
                    if (
                        $mappedValue !== null
                        && \is_numeric($mappedValue)
                    ) {
                        $mappedValue = self::applyValueCalculation(
                            $mappedValue,
                            $calculationConfig
                        );
                    }
                } elseif (\is_array($calculationConfig)) {
                    // Complex structure with default, specific, based_on_other_attribute_value, etc.
                    $expression = null;

                    // Check for specific_based_on_attribute_value first (most specific)
                    if (
                        isset($calculationConfig['specific_based_on_attribute_value'])
                        && $excludeFieldtype !== ''
                    ) {
                        $specificMappings = self::asArray(
                            $calculationConfig['specific_based_on_attribute_value']
                        );
                        $fieldtypeMappings = self::asArray(
                            self::mappingValue($specificMappings, $excludeFieldtype)
                        );
                        foreach ($fieldtypeMappings as $pattern => $calcExpression) {
                            // Check for '#not_set' special case first
                            if ($pattern === '#not_set') {
                                // If we have original field data, check if the attribute is not set in field options
                                if ($originalFieldData !== null) {
                                    if (
                                        self::isFieldOptionNotSet(
                                            $attrName,
                                            $originalFieldData
                                        )
                                    ) {
                                        $expression = $calcExpression;
                                        break;
                                    }
                                } else { // Otherwise
                                    // Fallback to checking if the attribute is not set in the current data
                                    if (
                                        self::isAttributeNotSet(
                                            $attrName,
                                            $data
                                        )
                                    ) {
                                        $expression = $calcExpression;
                                        break;
                                    }
                                }
                            } else { // Otherwise
                                // Check against the original value before translation
                                if (
                                    self::matchesPattern(
                                        $attrValue,
                                        $pattern
                                    )
                                ) {
                                    $expression = $calcExpression;
                                    break;
                                }
                            }
                        }
                    }

                    // Check for based_on_other_attribute_value if no specific match found
                    if (
                        $expression === null
                        && isset($calculationConfig['based_on_other_attribute_value'])
                    ) {
                        $basedOnConfig = $calculationConfig['based_on_other_attribute_value'];

                        // Handle the new structure with default and specific overrides
                        if (
                            \is_array($basedOnConfig)
                            && (
                                self::arrayKeyExists(
                                    'default',
                                    $basedOnConfig
                                )
                                || self::arrayKeyExists(
                                    'specific',
                                    $basedOnConfig
                                )
                            )
                        ) {
                            // New structure: ['default' => [attr => valueMappings], 'specific' => [fieldtype => [attr => valueMappings]]]
                            $defaultMappings = self::asArray(
                                $basedOnConfig['default'] ?? null
                            );
                            $specificMappings = self::asArray(
                                $basedOnConfig['specific'] ?? null
                            );

                            // Process default mappings first
                            foreach ($defaultMappings as $otherAttrName => $valueMappings) {
                                $valueMappings = self::asArray($valueMappings);
                                $otherAttrValue = $data[$otherAttrName] ?? null;
                                if (
                                    self::arrayKeyExists(
                                        $otherAttrValue,
                                        $valueMappings
                                    )
                                ) {
                                    $expression = self::mappingValue(
                                        $valueMappings,
                                        $otherAttrValue
                                    );
                                    break;
                                }

                                // Check for #not_set case
                                if (
                                    isset($valueMappings['#not_set'])
                                    && $otherAttrValue === null
                                ) {
                                    $expression = $valueMappings['#not_set'];
                                    break;
                                }
                            }

                            // Process fieldtype-specific mappings if no default match found
                            if (
                                $expression === null
                                && $excludeFieldtype !== ''
                            ) {
                                $fieldtypeMappings = self::asArray(
                                    self::mappingValue(
                                        $specificMappings,
                                        $excludeFieldtype
                                    )
                                );
                                foreach ($fieldtypeMappings as $otherAttrName => $valueMappings) {
                                    $valueMappings = self::asArray($valueMappings);
                                    $otherAttrValue = $data[$otherAttrName] ?? null;
                                    if (
                                        self::arrayKeyExists(
                                            $otherAttrValue,
                                            $valueMappings
                                        )
                                    ) {
                                        $expression = self::mappingValue(
                                            $valueMappings,
                                            $otherAttrValue
                                        );
                                        break;
                                    }

                                    // Check for #not_set case
                                    if (
                                        isset($valueMappings['#not_set'])
                                        && $otherAttrValue === null
                                    ) {
                                        $expression = $valueMappings['#not_set'];
                                        break;
                                    }
                                }
                            }
                        } else { // Otherwise
                            // Legacy structure: direct attr => valueMappings mapping
                            foreach (self::asArray($basedOnConfig) as $otherAttrName => $valueMappings) {
                                $valueMappings = self::asArray($valueMappings);
                                $otherAttrValue = $data[$otherAttrName] ?? null;
                                if (
                                    self::arrayKeyExists(
                                        $otherAttrValue,
                                        $valueMappings
                                    )
                                ) {
                                    $expression = self::mappingValue(
                                        $valueMappings,
                                        $otherAttrValue
                                    );
                                    break;
                                }

                                // Check for #not_set case
                                if (
                                    isset($valueMappings['#not_set'])
                                    && $otherAttrValue === null
                                ) {
                                    $expression = $valueMappings['#not_set'];
                                    break;
                                }
                            }
                        }
                    }

                    // Check for specific fieldtype override if no other match found
                    if (
                        $expression === null
                        && isset($calculationConfig['specific'])
                        && $excludeFieldtype !== ''
                    ) {
                        $specificMappings = self::asArray(
                            $calculationConfig['specific']
                        );
                        $specificExpression = self::mappingValue(
                            $specificMappings,
                            $excludeFieldtype
                        );
                        if ($specificExpression !== null) {
                            $expression = $specificExpression;
                        }
                    }

                    // Use default if no other match found
                    if (
                        $expression === null
                        && isset($calculationConfig['default'])
                    ) {
                        $expression = $calculationConfig['default'];
                    }

                    // Apply the calculation if we found an expression and the value is numeric
                    if (
                        $expression !== null
                        && \is_string($expression)
                        && $mappedValue !== null
                        && \is_numeric($mappedValue)
                    ) {
                        $mappedValue = self::applyValueCalculation(
                            $mappedValue,
                            $expression
                        );
                    }
                }
            }

            // Translate field references in string values
            if (
                isset($config['translate_field_references'])
                && $config['translate_field_references'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = self::translateFieldReferences(
                    $mappedValue,
                    $allFields,
                    $data
                );
            }

            // Translate calculation field references in string values
            if (
                isset($config['translate_field_references_calculation'])
                && $config['translate_field_references_calculation'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = self::translateCalculationFieldReferences(
                    $mappedValue,
                    $allFields,
                    $data
                );
            }

            // Process markdown fields to preserve newlines
            if (
                isset($config['preserve_newlines'])
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $preserveNewlinesConfig = $config['preserve_newlines'];

                // Use the DRY helper method to process modifier patterns
                $modifierResult = self::processModifierPatterns(
                    $preserveNewlinesConfig,
                    $data,
                    null,
                    $excludeFieldtype,
                    $attrName,
                    $attrValue
                );
                $shouldPreserveNewlines = $modifierResult['value'];
                $matchFound = $modifierResult['matchFound'];

                // Legacy boolean check
                if (
                    ! $matchFound
                    && $preserveNewlinesConfig === true
                ) {
                    $shouldPreserveNewlines = true;
                    $matchFound = true;
                }

                // Apply newline preservation if needed
                if ($shouldPreserveNewlines === true) {
                    // Convert escaped newlines back to actual newlines for markdown fields
                    $mappedValue = \str_replace(
                        '\\n',
                        "\n",
                        $mappedValue
                    );
                }
            }

            // Unescape forward slashes in HTML content
            if (
                isset($config['unescape_slashes'])
                && $config['unescape_slashes'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                // Convert escaped forward slashes back to actual forward slashes
                $mappedValue = \str_replace(
                    '\/',
                    '/',
                    $mappedValue
                );
            }

            // Convert empty strings to null after all processing
            if ($mappedValue === '') {
                $mappedValue = null;
            }

            // Value prepend
            if (
                isset($config['value_prepend'])
                && \is_string($config['value_prepend'])
                && $mappedValue !== null
                && $mappedValue !== []
                && \is_scalar($mappedValue)
            ) {
                // Coerce the scalar mapped value to string before prepending
                $mappedValue = $config['value_prepend'].$mappedValue;
            }

            // Collapse whitespace
            if (
                isset($config['collapse_whitespace'])
                && $config['collapse_whitespace'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = \preg_replace(
                    '/\s+/',
                    '',
                    $mappedValue
                );
            }

            // Convert file size
            if (
                isset($config['convert_file_size'])
                && \is_string($config['convert_file_size'])
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = self::convertFileSize(
                    $mappedValue,
                    $config['convert_file_size']
                );
            }

            // Convert to integer
            if (
                isset($config['as_integer'])
                && $config['as_integer'] === true
                && $mappedValue !== null
                && \is_numeric($mappedValue)
            ) {
                $mappedValue = (int) $mappedValue;
            }

            // Process boolean conversion if configured
            if (
                isset($config['as_boolean'])
                && $config['as_boolean'] === true
            ) {
                $mappedValue = static::convertToBoolean($mappedValue);

                // Apply boolean inversion if configured
                if (
                    isset($config['invert_boolean'])
                    && $config['invert_boolean'] === true
                ) {
                    $mappedValue = ! $mappedValue;
                }
            }

            // Convert dollar amount to float
            if (
                isset($config['convert_dollar_amount'])
                && $config['convert_dollar_amount'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = (float) \str_replace(
                    ['$', ','],
                    '',
                    $mappedValue
                );
            }

            // Transform JSON to array for options fields
            if (
                isset($config['transform_json_to_array'])
                && $config['transform_json_to_array'] === true
                && $mappedValue !== null
                && \is_string($mappedValue)
            ) {
                $mappedValue = static::transformOptionsJsonToArray(
                    $mappedValue,
                    $data,
                    $config,
                    $excludeFieldtype
                );

                // Check if other_label was extracted during options processing
                if (static::$extractedOtherLabel !== null) {
                    $mappedData['other_label'] = static::$extractedOtherLabel;
                    static::$extractedOtherLabel = null; // Reset for next use
                }
            }

            // Handle choices_as_fields logic for array values (like Gravity Forms choices)
            if (
                isset($config['choices_as_fields'])
                && \is_array($mappedValue)
            ) {
                $choicesAsFieldsConfig = $config['choices_as_fields'];

                // Use the DRY helper method to process modifier patterns
                $modifierResult = self::processModifierPatterns(
                    $choicesAsFieldsConfig,
                    $data,
                    null,
                    $excludeFieldtype,
                    $attrName,
                    $attrValue
                );
                $shouldConvertToFields = $modifierResult['value'];
                $matchFound = $modifierResult['matchFound'];

                // Convert choices to fields format if needed
                if ($shouldConvertToFields === true) {
                    $mappedValue = self::convertChoicesToFields(
                        $mappedValue,
                        $data
                    );

                    // Update the statamic attribute to 'fields' when converting choices to fields
                    if ($attrName === 'choices') {
                        $statamicAttr = 'fields';
                    }
                }
            }

            // Check against validation list
            if (
                isset($config['check_against_list'])
                && \is_string($config['check_against_list'])
            ) {
                // Skip validation if the value is null (null values are handled by the calling logic)
                if (
                    $mappedValue !== null
                    && ! self::validateValueAgainstList(
                        self::stringValue($mappedValue) ?? '',
                        $config['check_against_list']
                    )
                ) {
                    continue; // Skip this attribute if validation fails
                }
            }

            // Alert for unsupported attributes
            if (
                isset($config['alert_unsupported'])
                && $config['alert_unsupported'] === true
                && (! in_array($attrValue, [null, '', []], true))
            ) {
                // This will be handled by the calling method to generate warnings
                // We could add a flag here to indicate unsupported attributes were found
            }

            // Handle statamic_attribute.specific_based_on_attribute_value
            $statamicAttributeConfig = self::mappingConfig(
                $config['statamic_attribute'] ?? null
            );
            if (isset($statamicAttributeConfig['specific_based_on_attribute_value'])) {
                $specificMappings = self::mappingConfig(
                    $statamicAttributeConfig['specific_based_on_attribute_value']
                );

                // Handle fieldtype-specific structure
                if (
                    $excludeFieldtype !== null
                    && $excludeFieldtype !== ''
                    && self::arrayKeyExists(
                        $excludeFieldtype,
                        $specificMappings
                    )
                ) {
                    $fieldtypeMappings = self::asArray(
                        $specificMappings[$excludeFieldtype]
                    );
                    foreach ($fieldtypeMappings as $pattern => $targetAttribute) {
                        // Check for '#not_set' special case first
                        if ($pattern === '#not_set') {
                            // If we have original field data, check if the attribute is not set in field options
                            if ($originalFieldData !== null) {
                                if (
                                    self::isFieldOptionNotSet(
                                        $attrName,
                                        $originalFieldData
                                    )
                                ) {
                                    // Replace wildcards in the target attribute
                                    $resolvedTargetAttribute = self::resolveWildcards(
                                        self::stringValue($targetAttribute) ?? '',
                                        '',
                                        $pattern
                                    );
                                    // Apply value_substitution_regex to the attribute value before assigning
                                    $finalValue = '';
                                    if (
                                        isset($config['value_substitution_regex'])
                                        && \is_array($config['value_substitution_regex'])
                                    ) {
                                        $finalValue = self::applyValueSubstitutionRegex(
                                            '',
                                            $config['value_substitution_regex']
                                        );
                                    }

                                    $mappedData[$resolvedTargetAttribute] = $finalValue;
                                    // Set mappedValue to ensure statamic_other_field_value gets applied
                                    $mappedValue = $finalValue;
                                    break; // Use the first matching pattern
                                }
                            } else { // Otherwise
                                // Fallback to checking if the attribute is not set in the current data
                                if (
                                    self::isAttributeNotSet(
                                        $attrName,
                                        $data
                                    )
                                ) {
                                    // Replace wildcards in the target attribute
                                    $resolvedTargetAttribute = self::resolveWildcards(
                                        self::stringValue($targetAttribute) ?? '',
                                        '',
                                        $pattern
                                    );
                                    // Apply value_substitution_regex to the attribute value before assigning
                                    $finalValue = '';
                                    if (
                                        isset($config['value_substitution_regex'])
                                        && \is_array($config['value_substitution_regex'])
                                    ) {
                                        $finalValue = self::applyValueSubstitutionRegex(
                                            '',
                                            $config['value_substitution_regex']
                                        );
                                    }

                                    $mappedData[$resolvedTargetAttribute] = $finalValue;
                                    // Set mappedValue to ensure statamic_other_field_value gets applied
                                    $mappedValue = $finalValue;
                                    break; // Use the first matching pattern
                                }
                            }
                        } elseif ($pattern === '#not_empty') { // Check for '#not_empty' special case
                            if (
                                self::isAttributeNotEmptyWithFallback(
                                    $attrName,
                                    $data,
                                    $originalFieldData
                                )
                            ) {
                                // Replace wildcards in the target attribute
                                $resolvedTargetAttribute = self::resolveWildcards(
                                    self::stringValue($targetAttribute) ?? '',
                                    self::stringValue($attrValue) ?? '',
                                    $pattern
                                );
                                // Apply value_substitution_regex to the attribute value before assigning
                                $finalValue = $attrValue;
                                if (
                                    isset($config['value_substitution_regex'])
                                    && \is_array($config['value_substitution_regex'])
                                ) {
                                    $finalValue = self::applyValueSubstitutionRegex(
                                        self::stringValue($attrValue) ?? '',
                                        $config['value_substitution_regex']
                                    );
                                }

                                $mappedData[$resolvedTargetAttribute] = $finalValue;
                                // Set mappedValue to ensure statamic_other_field_value gets applied
                                $mappedValue = $finalValue;
                                break; // Use the first matching pattern
                            }
                        } else { // Otherwise
                            // Check against the original value before translation
                            if (
                                self::matchesPattern(
                                    $attrValue,
                                    $pattern
                                )
                            ) {
                                // Replace wildcards in the target attribute
                                $resolvedTargetAttribute = self::resolveWildcards(
                                    self::stringValue($targetAttribute) ?? '',
                                    self::stringValue($attrValue) ?? '',
                                    (string) $pattern
                                );
                                // Apply value_substitution_regex to the attribute value before assigning
                                $finalValue = $attrValue;
                                if (
                                    isset($config['value_substitution_regex'])
                                    && \is_array($config['value_substitution_regex'])
                                ) {
                                    $finalValue = self::applyValueSubstitutionRegex(
                                        self::stringValue($attrValue) ?? '',
                                        $config['value_substitution_regex']
                                    );
                                }

                                $mappedData[$resolvedTargetAttribute] = $finalValue;
                                // Set mappedValue to ensure statamic_other_field_value gets applied
                                $mappedValue = $finalValue;
                                break; // Use the first matching pattern
                            }
                        }
                    }
                }
            }

            // Handle statamic_other_field_value_based_on_attribute_value
            if (isset($attrConfigMap['statamic_other_field_value_based_on_attribute_value'])) {
                $otherFieldMappings = self::mappingConfig(
                    $attrConfigMap['statamic_other_field_value_based_on_attribute_value']
                );

                // Handle the new structure with default and specific overrides
                if (
                    self::arrayKeyExists(
                        'default',
                        $otherFieldMappings
                    )
                ) {
                    // New structure: ['default' => [pattern => fieldValues], 'specific' => [fieldtype => [pattern => fieldValues]]]
                    $defaultMappings = self::asArray(
                        $otherFieldMappings['default'] ?? null
                    );
                    $specificMappings = self::asArray(
                        $otherFieldMappings['specific'] ?? null
                    );

                    // Process default mappings
                    foreach ($defaultMappings as $pattern => $fieldValues) {
                        $fieldValuesMap = self::asArray($fieldValues);
                        // Check for '#not_set' special case first
                        if ($pattern === '#not_set') {
                            // If we have original field data, check if the attribute is not set in field options
                            if ($originalFieldData !== null) {
                                if (
                                    self::isFieldOptionNotSet(
                                        $attrName,
                                        $originalFieldData
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            } else { // Otherwise
                                // Fallback to checking if the attribute is not set in the current data
                                if (
                                    self::isAttributeNotSet(
                                        $attrName,
                                        $data
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            }
                        } elseif ($pattern === '#not_empty') { // Check for '#not_empty' special case
                            if (
                                self::isAttributeNotEmptyWithFallback(
                                    $attrName,
                                    $data,
                                    $originalFieldData
                                )
                            ) {
                                foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }

                                break; // Use the first matching pattern
                            }
                        } else { // Otherwise
                            // Check against the original value before translation
                            if (
                                self::matchesPattern(
                                    $attrValue,
                                    $pattern
                                )
                            ) {
                                foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }

                                break; // Use the first matching pattern
                            }
                        }
                    }

                    // Process fieldtype-specific mappings
                    if (
                        $excludeFieldtype !== null
                        && $excludeFieldtype !== ''
                        && self::arrayKeyExists(
                            $excludeFieldtype,
                            $specificMappings
                        )
                    ) {
                        $fieldtypeMappings = self::asArray(
                            $specificMappings[$excludeFieldtype]
                        );
                        foreach ($fieldtypeMappings as $pattern => $fieldValues) {
                            $fieldValuesMap = self::asArray($fieldValues);
                            // Check for '#not_set' special case first
                            if ($pattern === '#not_set') {
                                // If we have original field data, check if the attribute is not set in field options
                                if ($originalFieldData !== null) {
                                    if (
                                        self::isFieldOptionNotSet(
                                            $attrName,
                                            $originalFieldData
                                        )
                                    ) {
                                        foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                            $mappedData[$fieldName] = $fieldValue;
                                        }

                                        break; // Use the first matching pattern
                                    }
                                } else { // Otherwise
                                    // Fallback to checking if the attribute is not set in the current data
                                    if (
                                        self::isAttributeNotSet(
                                            $attrName,
                                            $data
                                        )
                                    ) {
                                        foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                            $mappedData[$fieldName] = $fieldValue;
                                        }

                                        break; // Use the first matching pattern
                                    }
                                }
                            } elseif ($pattern === '#not_empty') { // Check for '#not_empty' special case
                                if (
                                    self::isAttributeNotEmptyWithFallback(
                                        $attrName,
                                        $data,
                                        $originalFieldData
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            } else { // Otherwise
                                // Check against the original value before translation
                                if (
                                    self::matchesPattern(
                                        $attrValue,
                                        $pattern
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            }
                        }
                    }
                } else { // Otherwise
                    // Legacy structure: direct pattern => fieldValues mapping
                    foreach ($otherFieldMappings as $pattern => $fieldValues) {
                        $fieldValuesMap = self::asArray($fieldValues);
                        // Check for '#not_set' special case first
                        if ($pattern === '#not_set') {
                            // If we have original field data, check if the attribute is not set in field options
                            if ($originalFieldData !== null) {
                                if (
                                    self::isFieldOptionNotSet(
                                        $attrName,
                                        $originalFieldData
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            } else { // Otherwise
                                // Fallback to checking if the attribute is not set in the current data
                                if (
                                    self::isAttributeNotSet(
                                        $attrName,
                                        $data
                                    )
                                ) {
                                    foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                        $mappedData[$fieldName] = $fieldValue;
                                    }

                                    break; // Use the first matching pattern
                                }
                            }
                        } else { // Otherwise
                            // Check against the original value before translation
                            if (
                                self::matchesPattern(
                                    $attrValue,
                                    $pattern
                                )
                            ) {
                                foreach ($fieldValuesMap as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }

                                break; // Use the first matching pattern
                            }
                        }
                    }
                }
            }

            // Handle statamic_other_field_value
            if (
                isset($attrConfigMap['statamic_other_field_value'])
                && (! in_array($mappedValue, [null, '', []], true))
            ) {
                $otherFieldValues = self::mappingConfig(
                    $attrConfigMap['statamic_other_field_value']
                );

                // Handle default values
                if (
                    isset($otherFieldValues['default'])
                ) {
                    $defaultOtherFieldValues = self::asArray(
                        $otherFieldValues['default']
                    );
                    foreach ($defaultOtherFieldValues as $fieldName => $fieldValue) {
                        $mappedData[$fieldName] = $fieldValue;
                    }
                }

                // Handle fieldtype-specific values
                if (
                    isset($otherFieldValues['specific'])
                    && $excludeFieldtype !== null
                    && $excludeFieldtype !== ''
                ) {
                    $specificOtherFieldValues = self::asArray(
                        $otherFieldValues['specific']
                    );
                    if (
                        self::arrayKeyExists(
                            $excludeFieldtype,
                            $specificOtherFieldValues
                        )
                    ) {
                        $fieldtypeOtherValues = self::asArray(
                            $specificOtherFieldValues[$excludeFieldtype]
                        );
                        foreach ($fieldtypeOtherValues as $fieldName => $fieldValue) {
                            $mappedData[$fieldName] = $fieldValue;
                        }
                    }
                }
            }

            // Handle statamic_other_field_value_based_on_nested_attribute_value
            if (isset($attrConfigMap['statamic_other_field_value_based_on_nested_attribute_value'])) {
                $nestedMappings = self::mappingConfig(
                    $attrConfigMap['statamic_other_field_value_based_on_nested_attribute_value']
                );

                // Handle the structure with default and specific overrides
                if (
                    self::arrayKeyExists(
                        'default',
                        $nestedMappings
                    )
                ) {
                    $defaultMappings = self::asArray($nestedMappings['default'] ?? null);
                    $specificMappings = self::asArray($nestedMappings['specific'] ?? null);

                    // Process default mappings
                    foreach ($defaultMappings as $mappingConfig) {
                        $mappingConfigArray = self::mappingConfig($mappingConfig);
                        if (
                            self::matchesNestedAttributePattern(
                                $attrValue,
                                $mappingConfigArray
                            )
                        ) {
                            $otherAttributes = self::asArray(
                                $mappingConfigArray['other_attribute'] ?? null
                            );
                            foreach ($otherAttributes as $fieldName => $fieldValue) {
                                $mappedData[$fieldName] = $fieldValue;
                            }

                            break; // Use the first matching pattern
                        }
                    }

                    // Process fieldtype-specific mappings
                    if (
                        $excludeFieldtype !== null
                        && $excludeFieldtype !== ''
                        && self::arrayKeyExists(
                            $excludeFieldtype,
                            $specificMappings
                        )
                    ) {
                        $fieldtypeNestedMappings = self::asArray(
                            $specificMappings[$excludeFieldtype]
                        );
                        foreach ($fieldtypeNestedMappings as $mappingConfig) {
                            $mappingConfigArray = self::mappingConfig($mappingConfig);
                            if (
                                self::matchesNestedAttributePattern(
                                    $attrValue,
                                    $mappingConfigArray
                                )
                            ) {
                                $otherAttributes = self::asArray(
                                    $mappingConfigArray['other_attribute'] ?? null
                                );
                                foreach ($otherAttributes as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }

                                break; // Use the first matching pattern
                            }
                        }
                    }
                }
            }

            // Handle statamic_type_based_on_attribute_value
            if (isset($attrConfigMap['statamic_type_based_on_attribute_value'])) {
                $typeMappings = self::asArray(
                    $attrConfigMap['statamic_type_based_on_attribute_value']
                );
                foreach ($typeMappings as $pattern => $newType) {
                    // Check for '#not_set' special case first
                    if ($pattern === '#not_set') {
                        // If we have original field data, check if the attribute is not set in field options
                        if ($originalFieldData !== null) {
                            if (
                                self::isFieldOptionNotSet(
                                    $attrName,
                                    $originalFieldData
                                )
                            ) {
                                $mappedData['type'] = $newType;
                                break; // Use the first matching pattern
                            }
                        } else { // Otherwise
                            // Fallback to checking if the attribute is not set in the current data
                            if (
                                self::isAttributeNotSet(
                                    $attrName,
                                    $data
                                )
                            ) {
                                $mappedData['type'] = $newType;
                                break; // Use the first matching pattern
                            }
                        }
                    } else { // Otherwise
                        // Check against the original value before translation
                        if (
                            self::matchesPattern(
                                $attrValue,
                                $pattern
                            )
                        ) {
                            $mappedData['type'] = $newType;
                            break; // Use the first matching pattern
                        }
                    }
                }
            }

            // Handle statamic_form_config
            if (
                isset($attrConfigMap['statamic_form_config'])
                && (! in_array($mappedValue, [null, '', []], true))
            ) {
                $formConfigKey = self::stringValue(
                    $attrConfigMap['statamic_form_config']
                );
                if ($formConfigKey !== null) {
                    $mappedData['form_config_'.$formConfigKey] = $mappedValue;
                }
            }

            // Handle get_selected_choice logic for defaultValue mapping
            if (
                isset($config['get_selected_choice'])
                && $statamicAttr === 'default'
                && $mappedValue === null
            ) {
                $getSelectedChoiceConfig = $config['get_selected_choice'];

                // Use the DRY helper method to process modifier patterns
                $modifierResult = self::processModifierPatterns(
                    $getSelectedChoiceConfig,
                    $data,
                    null,
                    $excludeFieldtype,
                    $attrName,
                    $attrValue
                );
                $shouldProcessSelectedChoice = $modifierResult['value'];
                $matchFound = $modifierResult['matchFound'];

                // Process the selected choice if we should
                if ($shouldProcessSelectedChoice === true) {
                    // Check if we have choices data and expected_keys equals ['key', 'value']
                    if (
                        isset($data['choices'])
                        && \is_array($data['choices'])
                    ) {
                        $expectedKeys = static::getExpectedKeys(
                            $data,
                            $attrValue,
                            $excludeFieldtype,
                            $attrName
                        );

                        // Only process if expected_keys equals ['key', 'value']
                        if (
                            $expectedKeys === [
                                'key',
                                'value',
                            ]
                        ) {
                            $selectedChoice = array_find(self::asArray($data['choices']), fn ($choice): bool => \is_array($choice)
                            && isset($choice['isSelected'])
                            && $choice['isSelected'] === true);
                            // If we found a selected choice, get the value that maps to 'key'
                            if ($selectedChoice !== null) {
                                // Get the choices configuration to determine key mapping
                                $choicesConfig = static::$fieldAttributeMapping['choices'] ?? null;
                                if (
                                    $choicesConfig !== null
                                    && $choicesConfig !== []
                                    && isset($choicesConfig['key_translation'])
                                ) {
                                    $keyTranslationConfig = $choicesConfig['key_translation'];

                                    // Get the appropriate key translation configuration
                                    $keyTranslationArray = null;
                                    if (
                                        \is_array($keyTranslationConfig)
                                        && self::arrayKeyExists(
                                            'default',
                                            $keyTranslationConfig
                                        )
                                    ) {
                                        $defaultTranslations = self::asArray($keyTranslationConfig['default'] ?? null);
                                        $specificTranslations = self::asArray($keyTranslationConfig['specific'] ?? null);
                                        $basedOnOtherAttributeValue = $keyTranslationConfig['based_on_other_attribute_value'] ?? [];

                                        // Check for based_on_other_attribute_value first (most specific)
                                        if (($basedOnOtherAttributeValue !== '' && $basedOnOtherAttributeValue !== [])) {
                                            foreach (self::asArray($basedOnOtherAttributeValue) as $otherAttrName => $valueMappings) {
                                                $otherAttrValue = $data[$otherAttrName] ?? null;
                                                // Normalize the value mappings and lookup key for safe offset access
                                                $valueMappingsArr = self::asArray($valueMappings);
                                                $normalizedOtherValue = self::normalizeArrayKey($otherAttrValue);
                                                if (
                                                    $normalizedOtherValue !== null
                                                    && self::arrayKeyExists(
                                                        $normalizedOtherValue,
                                                        $valueMappingsArr
                                                    )
                                                ) {
                                                    $keyTranslationArray = $valueMappingsArr[$normalizedOtherValue];
                                                    break;
                                                }
                                            }
                                        } elseif ($excludeFieldtype !== null && $excludeFieldtype !== '' && isset($specificTranslations[$excludeFieldtype])) { // Check for fieldtype-specific translations
                                            $keyTranslationArray = $specificTranslations[$excludeFieldtype];
                                        } else { // Fall back to default translations
                                            $keyTranslationArray = $defaultTranslations;
                                        }
                                    } else { // Otherwise
                                        // Simple array structure
                                        $keyTranslationArray = $keyTranslationConfig;
                                    }

                                    // Find the attribute that maps to 'key' in the key translation
                                    $keyAttribute = null;
                                    if ($keyTranslationArray !== null) {
                                        foreach (self::asArray($keyTranslationArray) as $sourceAttr => $targetAttr) {
                                            if ($targetAttr === 'key') {
                                                $keyAttribute = $sourceAttr;
                                                break;
                                            }
                                        }
                                    }

                                    // Get the value from the selected choice that maps to 'key'
                                    if (
                                        $keyAttribute !== null
                                        && isset($selectedChoice[$keyAttribute])
                                    ) {
                                        $mappedValue = $selectedChoice[$keyAttribute];
                                    } else { // Otherwise
                                        // Fallback: if we can't find the key mapping, use the 'text' value
                                        // This handles cases where the key translation might not be properly configured
                                        if (isset($selectedChoice['text'])) {
                                            $mappedValue = $selectedChoice['text'];
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // Now assign the transformed value to the statamic attribute
            if (
                \is_string($statamicAttr)
                && ($statamicAttr !== '' && $statamicAttr !== '')
            ) {
                if (
                    isset($config['as_array'])
                    && $config['as_array'] === true
                ) {
                    // Handle as array
                    if ($mappedValue !== null) {
                        // Ensure the existing value is an array before appending
                        $existingValues = self::asArray(
                            $mappedData[$statamicAttr] ?? []
                        );
                        $existingValues[] = $mappedValue;
                        $mappedData[$statamicAttr] = $existingValues;
                    }
                } else { // Otherwise
                    // Apply deduplication if configured
                    if (
                        isset($config['deduplicate_values'])
                        && $config['deduplicate_values'] === true
                        && \is_array($mappedValue)
                    ) {
                        $mappedValue = \array_values(array_unique($mappedValue, SORT_REGULAR));
                    }

                    // Apply value transformations if configured
                    if (isset($config['value_transformations']) && $mappedValue !== null) {
                        $valueTransformations = $config['value_transformations'];

                        // Check if this is already a processed transformation config (flat structure)
                        // or if it needs to be resolved through resolveValueTransformations
                        $transformationConfig = null;

                        // If the valueTransformations is already a flat transformation config (like {"as_numeric_string":true}),
                        // use it directly. Otherwise, resolve it through the resolveValueTransformations method.
                        // A flat transformation config should only contain transformation keys (like as_numeric_string, as_date_iso_8601, etc.)
                        // and should not contain structural keys like 'default', 'specific', 'based_on_attribute_value', etc.
                        if (\is_array($valueTransformations)) {
                            $isFlatConfig = ! isset($valueTransformations['default'])
                                && ! isset($valueTransformations['specific'])
                                && ! isset($valueTransformations['based_on_attribute_value'])
                                && ! isset($valueTransformations['based_on_other_attribute_value'])
                                && ! isset($valueTransformations['specific_based_on_attribute_value']);

                            if ($isFlatConfig) {
                                // This is already a processed transformation config
                                $transformationConfig = $valueTransformations;
                            } else {
                                // This needs to be resolved through resolveValueTransformations
                                // Try different keys to find the right transformation config
                                $transformationConfig = static::resolveValueTransformations(
                                    $valueTransformations,
                                    $data,
                                    'based_on_attribute_value',
                                    $excludeFieldtype
                                );
                                if ($transformationConfig === null || $transformationConfig === []) {
                                    $transformationConfig = static::resolveValueTransformations(
                                        $valueTransformations,
                                        $data,
                                        'default',
                                        $excludeFieldtype
                                    );
                                }
                            }
                        }

                        if ($transformationConfig !== null && $transformationConfig !== []) {
                            $mappedValue = static::applyValueTransformation(
                                $mappedValue,
                                $transformationConfig,
                                $data,
                                1
                            );
                        }
                    }

                    // Set the value (null values will be filtered out later)
                    if ($mappedValue !== null) {
                        $mappedData[$statamicAttr] = $mappedValue;
                    }
                }
            }
        }

        // Now, process #not_set cases for attributes that don't exist in the data
        // Process missing attributes that have #not_set value translations, even when originalFieldData is null
        foreach ($mapping as $attrName => $attrConfig) {
            // Skip if this attribute already exists in the data (already processed above)
            if (
                self::arrayKeyExists(
                    $attrName,
                    $data
                )
            ) {
                continue;
            }

            // Get the appropriate configuration based on fieldtype
            $config = static::getFieldtypeSpecificConfig(
                self::asArray($attrConfig),
                $excludeFieldtype
            );

            // Check exclude_fieldtypes logic
            if (
                static::shouldExcludeFieldtype(
                    $config,
                    $excludeFieldtype
                )
            ) {
                continue;
            }

            // Check if this attribute has a statamic_attribute mapping
            $statamicAttr = $config['statamic_attribute'] ?? null;
            $statamicAttrName = null;

            // Handle both string and array statamic_attribute configurations
            if (\is_string($statamicAttr)) {
                $statamicAttrName = $statamicAttr;
            } elseif (\is_array($statamicAttr)) {
                // Normalize the specific overrides to an array for safe offset access
                $specificStatamicAttr = self::asArray($statamicAttr['specific'] ?? null);
                // Check for specific fieldtype mapping first
                if ($excludeFieldtype !== null && isset($specificStatamicAttr[$excludeFieldtype])) {
                    $statamicAttrName = $specificStatamicAttr[$excludeFieldtype];
                } elseif (isset($statamicAttr['default'])) {
                    $statamicAttrName = $statamicAttr['default'];
                }
            }

            // Only process if we have a statamic attribute name and it's not null
            if ($statamicAttrName !== null && $statamicAttrName !== '') {
                // Check for value_translation with #not_set
                if (isset($config['value_translation'])) {
                    $valueTranslationConfig = $config['value_translation'];
                    $transformationConfig = static::getFieldtypeSpecificConfig(
                        self::asArray($valueTranslationConfig),
                        $excludeFieldtype
                    );

                    // Normalize the value translation config for safe offset access
                    $valueTranslationArr = self::asArray($valueTranslationConfig);
                    $specificTranslation = self::asArray(
                        $valueTranslationArr['specific'] ?? null
                    );

                    // If getFieldtypeSpecificConfig didn't work, try direct access to specific fieldtype
                    if (
                        $transformationConfig === []
                        && $excludeFieldtype !== null
                        && isset($specificTranslation[$excludeFieldtype])
                    ) {
                        $transformationConfig = self::asArray(
                            $specificTranslation[$excludeFieldtype]
                        );
                    }

                    // If still empty, the valueTranslationConfig might already be the fieldtype-specific config
                    $firstTranslation = self::asArray($valueTranslationArr[0] ?? null);
                    if (
                        $transformationConfig === []
                        && \is_array($valueTranslationConfig)
                        && isset($firstTranslation['search'])
                    ) {
                        $transformationConfig = $valueTranslationConfig;
                    }

                    if ($transformationConfig !== []) {
                        // Look for #not_set translation
                        foreach (self::asArray($transformationConfig) as $translation) {
                            // Narrow each translation entry to an array
                            $translationEntry = self::asArray($translation);
                            if (isset($translationEntry['search']) && $translationEntry['search'] === '#not_set') {
                                // Normalize the attribute name before using it as an array key
                                $statamicAttrKey = self::normalizeArrayKey($statamicAttrName);
                                if ($statamicAttrKey !== null) {
                                    $mappedData[$statamicAttrKey] = $translationEntry['replace'];
                                }

                                break;
                            }
                        }
                    }
                }
            }
        }

        if ($originalFieldData !== null) {
            foreach ($mapping as $attrName => $attrConfig) {
                // Skip if this attribute already exists in the data (already processed above)
                if (
                    self::arrayKeyExists(
                        $attrName,
                        $data
                    )
                ) {
                    continue;
                }

                // Normalize the attribute config to an array for safe offset access
                $attrConfigArr = self::asArray($attrConfig);

                // Get the appropriate configuration based on fieldtype
                $config = static::getFieldtypeSpecificConfig(
                    $attrConfigArr,
                    $excludeFieldtype
                );

                // Check exclude_fieldtypes logic
                if (
                    static::shouldExcludeFieldtype(
                        $config,
                        $excludeFieldtype
                    )
                ) {
                    continue;
                }

                // Skip if there is no Statamic attribute to map to and no other field value mappings
                if (
                    ! isset($config['statamic_attribute'])
                    && ! isset($attrConfigArr['statamic_other_field_value'])
                    && ! isset($attrConfigArr['statamic_other_field_value_based_on_attribute_value'])
                    && ! isset($attrConfigArr['statamic_other_field_value_based_on_nested_attribute_value'])
                    && ! isset($attrConfigArr['statamic_type_based_on_attribute_value'])
                ) {
                    continue;
                }

                // Support '*' as a placeholder for the subfield handle in statamic_attribute
                $statamicAttr = $config['statamic_attribute'] ?? null;

                // Ensure statamicAttr is a valid string (only if we need it for value_translation)
                if (
                    isset($config['value_translation'])
                    && (
                        ! \is_string($statamicAttr)
                        || $statamicAttr === ''
                    )
                ) {
                    continue;
                }

                if ($handle !== null && $handle !== '' && \is_string($statamicAttr)) {
                    $statamicAttr = self::replacePlaceholders(
                        $statamicAttr,
                        $handle
                    );
                    // For array statamicAttr (complex structures), placeholder replacement
                    // is handled in the processSubfieldStatamicAttribute method
                }

                // Check for #not_set in value_translation
                if (
                    isset($config['value_translation'])
                    && \is_array($config['value_translation'])
                    && self::arrayKeyExists(
                        '#not_set',
                        $config['value_translation']
                    )
                ) {
                    // Check if the attribute is not set in field options
                    if (
                        self::isFieldOptionNotSet(
                            $attrName,
                            $originalFieldData
                        )
                    ) {
                        $mappedValue = $config['value_translation']['#not_set'];

                        // Convert empty strings to null after all processing
                        if ($mappedValue === '') {
                            $mappedValue = null;
                        }

                        // Apply deduplication if configured
                        if (
                            isset($config['deduplicate_values'])
                            && $config['deduplicate_values'] === true
                            && \is_array($mappedValue)
                        ) {
                            $mappedValue = \array_values(array_unique($mappedValue, SORT_REGULAR));
                        }

                        // Apply value transformations if configured
                        if (isset($config['value_transformations']) && $mappedValue !== null) {
                            // Normalize the transformations config to an array
                            $valueTransformations = self::asArray(
                                $config['value_transformations']
                            );

                            // Apply value transformations using the resolveValueTransformations method
                            // This method handles all the different transformation structures
                            $transformationConfig = static::resolveValueTransformations(
                                $valueTransformations,
                                $data,
                                'based_on_attribute_value',
                                $excludeFieldtype
                            );
                            if ($transformationConfig !== null && $transformationConfig !== []) {
                                $mappedValue = static::applyValueTransformation(
                                    $mappedValue,
                                    $transformationConfig,
                                    $data,
                                    1
                                );
                            }
                        }

                        // Set the value (null values will be filtered out later)
                        $statamicAttrKey = self::normalizeArrayKey($statamicAttr);
                        if ($statamicAttrKey !== null) {
                            $mappedData[$statamicAttrKey] = $mappedValue;
                        }
                    }
                }

                // Check for #not_set in statamic_other_field_value_based_on_attribute_value
                if (isset($attrConfigArr['statamic_other_field_value_based_on_attribute_value'])) {
                    $otherFieldMappings = $attrConfigArr['statamic_other_field_value_based_on_attribute_value'];

                    // Handle the new structure with default and specific overrides
                    if (
                        \is_array($otherFieldMappings)
                        && self::arrayKeyExists(
                            'default',
                            $otherFieldMappings
                        )
                    ) {
                        // New structure: ['default' => [pattern => fieldValues], 'specific' => [fieldtype => [pattern => fieldValues]]]
                        $defaultMappings = self::asArray($otherFieldMappings['default'] ?? null);
                        $specificMappings = self::asArray($otherFieldMappings['specific'] ?? null);

                        // Process default mappings for #not_set
                        if (isset($defaultMappings['#not_set'])) {
                            // Check if the attribute is not set in field options
                            if (
                                self::isFieldOptionNotSet(
                                    $attrName,
                                    $originalFieldData
                                )
                            ) {
                                foreach (self::asArray($defaultMappings['#not_set']) as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }
                            }
                        }

                        // Process fieldtype-specific mappings for #not_set
                        $specificFieldtypeMappings = self::asArray(
                            $excludeFieldtype !== null ? ($specificMappings[$excludeFieldtype] ?? null) : null
                        );
                        if (
                            $excludeFieldtype !== ''
                            && isset($specificFieldtypeMappings['#not_set'])
                        ) {
                            // Check if the attribute is not set in field options
                            if (
                                self::isFieldOptionNotSet(
                                    $attrName,
                                    $originalFieldData
                                )
                            ) {
                                foreach (self::asArray($specificFieldtypeMappings['#not_set']) as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }
                            }
                        }
                    } else { // Otherwise
                        // Legacy structure: direct pattern => fieldValues mapping
                        $otherFieldMappingsArr = self::asArray($otherFieldMappings);
                        if (isset($otherFieldMappingsArr['#not_set'])) {
                            // Check if the attribute is not set in field options
                            if (
                                self::isFieldOptionNotSet(
                                    $attrName,
                                    $originalFieldData
                                )
                            ) {
                                foreach (self::asArray($otherFieldMappingsArr['#not_set']) as $fieldName => $fieldValue) {
                                    $mappedData[$fieldName] = $fieldValue;
                                }
                            }
                        }
                    }
                }

                // Check for #not_set in statamic_attribute.specific_based_on_attribute_value
                $statamicAttributeArr = self::asArray($attrConfigArr['statamic_attribute'] ?? null);
                if (isset($statamicAttributeArr['specific_based_on_attribute_value'])) {
                    $specificMappings = self::asArray($statamicAttributeArr['specific_based_on_attribute_value']);

                    // Handle fieldtype-specific structure
                    $specificFieldtypeMappings = self::asArray(
                        $excludeFieldtype !== null ? ($specificMappings[$excludeFieldtype] ?? null) : null
                    );
                    if (
                        $excludeFieldtype !== ''
                        && isset($specificFieldtypeMappings['#not_set'])
                    ) {
                        // Check if the attribute is not set in field options
                        if (
                            self::isFieldOptionNotSet(
                                $attrName,
                                $originalFieldData
                            )
                        ) {
                            $targetAttribute = $specificFieldtypeMappings['#not_set'];
                            // Replace wildcards in the target attribute
                            $resolvedTargetAttribute = self::resolveWildcards(
                                self::stringValue($targetAttribute) ?? '',
                                '',
                                '#not_set'
                            );
                            $mappedData[$resolvedTargetAttribute] = '';
                        }
                    }
                }

                // Check for #not_set in statamic_type_based_on_attribute_value
                $statamicTypeMappings = self::asArray(
                    $attrConfigArr['statamic_type_based_on_attribute_value'] ?? null
                );
                if (isset($statamicTypeMappings['#not_set'])) {
                    // Check if the attribute is not set in field options
                    if (
                        self::isFieldOptionNotSet(
                            $attrName,
                            $originalFieldData
                        )
                    ) {
                        $mappedData['type'] = $statamicTypeMappings['#not_set'];
                    }
                }

                // Check for #not_set in value_calculation
                if (
                    isset($attrConfigArr['value_calculation'])
                    && \is_array($attrConfigArr['value_calculation'])
                ) {
                    $calculationConfig = $attrConfigArr['value_calculation'];
                    $expression = null;

                    // Check for specific_based_on_attribute_value first (most specific)
                    if (
                        isset($calculationConfig['specific_based_on_attribute_value'])
                        && $excludeFieldtype !== null
                        && $excludeFieldtype !== ''
                    ) {
                        $specificMappings = self::asArray(
                            $calculationConfig['specific_based_on_attribute_value']
                        );
                        $specificFieldtypeMappings = self::asArray(
                            $specificMappings[$excludeFieldtype] ?? null
                        );
                        if (isset($specificFieldtypeMappings['#not_set'])) {
                            // Check if the attribute is not set in field options
                            if (
                                self::isFieldOptionNotSet(
                                    $attrName,
                                    $originalFieldData
                                )
                            ) {
                                $expression = $specificFieldtypeMappings['#not_set'];
                            }
                        }
                    }

                    // Check for based_on_other_attribute_value if no specific match found
                    if (
                        $expression === null
                        && isset($calculationConfig['based_on_other_attribute_value'])
                    ) {
                        $basedOnConfig = $calculationConfig['based_on_other_attribute_value'];

                        // Handle the new structure with default and specific overrides
                        if (
                            \is_array($basedOnConfig)
                            && (
                                self::arrayKeyExists(
                                    'default',
                                    $basedOnConfig
                                )
                                || self::arrayKeyExists(
                                    'specific',
                                    $basedOnConfig
                                )
                            )
                        ) {
                            // New structure: ['default' => [attr => valueMappings], 'specific' => [fieldtype => [attr => valueMappings]]]
                            $defaultMappings = self::asArray($basedOnConfig['default'] ?? null);
                            $specificMappings = self::asArray($basedOnConfig['specific'] ?? null);

                            // Process default mappings first
                            foreach ($defaultMappings as $otherAttrName => $valueMappings) {
                                $otherAttrValue = $originalFieldData[$otherAttrName] ?? null;
                                // Narrow the value mappings to an array before reading offsets
                                $valueMappingsArr = self::asArray($valueMappings);
                                if (
                                    isset($valueMappingsArr['#not_set'])
                                    && $otherAttrValue === null
                                ) {
                                    // Check if the attribute is not set in field options
                                    if (
                                        self::isFieldOptionNotSet(
                                            $attrName,
                                            $originalFieldData
                                        )
                                    ) {
                                        $expression = $valueMappingsArr['#not_set'];
                                        break;
                                    }
                                }
                            }

                            // Process fieldtype-specific mappings if no default match found
                            if (
                                $expression === null
                                && $excludeFieldtype !== null
                                && $excludeFieldtype !== ''
                                && isset($specificMappings[$excludeFieldtype])
                            ) {
                                $fieldtypeMappings = self::asArray($specificMappings[$excludeFieldtype]);
                                foreach ($fieldtypeMappings as $otherAttrName => $valueMappings) {
                                    $otherAttrValue = $originalFieldData[$otherAttrName] ?? null;
                                    // Narrow the value mappings to an array before reading offsets
                                    $valueMappingsArr = self::asArray($valueMappings);
                                    if (
                                        isset($valueMappingsArr['#not_set'])
                                        && $otherAttrValue === null
                                    ) {
                                        // Check if the attribute is not set in field options
                                        if (
                                            self::isFieldOptionNotSet(
                                                $attrName,
                                                $originalFieldData
                                            )
                                        ) {
                                            $expression = $valueMappingsArr['#not_set'];
                                            break;
                                        }
                                    }
                                }
                            }
                        } else { // Otherwise
                            // Legacy structure: direct attr => valueMappings mapping
                            foreach (self::asArray($basedOnConfig) as $otherAttrName => $valueMappings) {
                                $otherAttrValue = $originalFieldData[$otherAttrName] ?? null;
                                // Narrow the value mappings to an array before reading offsets
                                $valueMappingsArr = self::asArray($valueMappings);
                                if (
                                    isset($valueMappingsArr['#not_set'])
                                    && $otherAttrValue === null
                                ) {
                                    // Check if the attribute is not set in field options
                                    if (
                                        self::isFieldOptionNotSet(
                                            $attrName,
                                            $originalFieldData
                                        )
                                    ) {
                                        $expression = $valueMappingsArr['#not_set'];
                                        break;
                                    }
                                }
                            }
                        }
                    }

                    // Use default if no other match found
                    if (
                        $expression === null
                        && isset($calculationConfig['default'])
                    ) {
                        // Check if the attribute is not set in field options
                        if (
                            self::isFieldOptionNotSet(
                                $attrName,
                                $originalFieldData
                            )
                        ) {
                            $expression = $calculationConfig['default'];
                        }
                    }

                    // Apply the calculation if we found an expression and have a statamic attribute
                    if (
                        $expression !== null
                        && \is_string($expression)
                        && isset($config['statamic_attribute'])
                        && \is_string($config['statamic_attribute'])
                    ) {
                        // For missing attributes, we need to provide a default value to calculate with
                        // We'll use 0 as the default value for calculations
                        $calculatedValue = self::applyValueCalculation(
                            0,
                            $expression
                        );

                        // Only add the attribute if the calculated value is not null and not 0
                        // This prevents adding attributes with meaningless default values
                        if (
                            $calculatedValue !== null
                            && $calculatedValue !== 0
                        ) {
                            $mappedData[$config['statamic_attribute']] = $calculatedValue;
                        }
                    }
                }
            }
        }

        // Second pass: process attributes defined in mapping but not present in data (for #not_set cases)
        foreach ($mapping as $attrName => $attrConfig) {
            // Skip if this attribute already exists in the data (was processed in first pass)
            if (
                self::arrayKeyExists(
                    $attrName,
                    $data
                )
            ) {
                continue;
            }

            // Get the appropriate configuration based on fieldtype
            $config = static::getFieldtypeSpecificConfig(
                self::asArray($attrConfig),
                $excludeFieldtype,
                $attrName
            );

            // Check exclude_fieldtypes logic
            if (
                static::shouldExcludeFieldtype(
                    $config,
                    $excludeFieldtype
                )
            ) {
                continue;
            }

            // Skip if there is no Statamic attribute to map to
            if (
                ! isset($config['statamic_attribute'])
            ) {
                continue;
            }

            // Only process attributes that have #not_set or #not_empty cases in value_translation
            $hasNotSetCases = false;
            if (
                isset($config['value_translation'])
                && \is_array($config['value_translation'])
            ) {
                $translationConfig = $config['value_translation'];

                // Check for simple structure with #not_set or #not_empty
                if (
                    self::arrayKeyExists(
                        '#not_set',
                        $translationConfig
                    )
                    || self::arrayKeyExists(
                        '#not_empty',
                        $translationConfig
                    )
                ) {
                    $hasNotSetCases = true;
                } elseif (
                    self::arrayKeyExists(
                        'default',
                        $translationConfig
                    )
                    || self::arrayKeyExists(
                        'specific',
                        $translationConfig
                    )
                    || self::arrayKeyExists(
                        'based_on_other_attribute_value',
                        $translationConfig
                    )
                ) { // Check for complex structure with #not_set or #not_empty

                    $defaultTranslations = self::asArray($translationConfig['default'] ?? null);
                    $specificTranslations = self::asArray($translationConfig['specific'] ?? null);
                    $basedOnOtherAttributeValue = self::asArray($translationConfig['based_on_other_attribute_value'] ?? null);

                    // Check default translations
                    if (
                        self::arrayKeyExists(
                            '#not_set',
                            $defaultTranslations
                        )
                        || self::arrayKeyExists(
                            '#not_empty',
                            $defaultTranslations
                        )
                    ) {
                        $hasNotSetCases = true;
                    } elseif ($excludeFieldtype !== null && $excludeFieldtype !== '' && isset($specificTranslations[$excludeFieldtype])) { // Check specific translations
                        $fieldtypeTranslations = $specificTranslations[$excludeFieldtype];
                        if (
                            self::arrayKeyExists(
                                '#not_set',
                                $fieldtypeTranslations
                            )
                            || self::arrayKeyExists(
                                '#not_empty',
                                $fieldtypeTranslations
                            )
                        ) {
                            $hasNotSetCases = true;
                        }
                    } elseif ($basedOnOtherAttributeValue !== []) { // Check based_on_other_attribute_value
                        foreach ($basedOnOtherAttributeValue as $valueMappings) {
                            if (
                                self::arrayKeyExists(
                                    '#not_set',
                                    $valueMappings
                                )
                                || self::arrayKeyExists(
                                    '#not_empty',
                                    $valueMappings
                                )
                                || self::arrayKeyExists(
                                    '#empty',
                                    $valueMappings
                                )
                            ) {
                                $hasNotSetCases = true;
                                break;
                            }
                        }
                    }
                }
            }

            // Skip if no #not_set or #not_empty cases found
            if (! $hasNotSetCases) {
                continue;
            }

            // Handle value translation for #not_set case
            if (
                isset($config['value_translation'])
                && \is_array($config['value_translation'])
            ) {
                $translationConfig = $config['value_translation'];
                $translationArray = null;

                // Handle different value_translation structures
                if (
                    self::arrayKeyExists(
                        'default',
                        $translationConfig
                    )
                    || self::arrayKeyExists(
                        'specific',
                        $translationConfig
                    )
                    || self::arrayKeyExists(
                        'based_on_other_attribute_value',
                        $translationConfig
                    )
                ) {

                    // Complex structure with 'default', 'specific', 'based_on_other_attribute_value', etc.
                    $defaultTranslations = self::asArray($translationConfig['default'] ?? null);
                    $specificTranslations = self::asArray($translationConfig['specific'] ?? null);
                    $basedOnOtherAttributeValue = self::asArray($translationConfig['based_on_other_attribute_value'] ?? null);

                    // Check for based_on_other_attribute_value first (most specific)
                    if ($basedOnOtherAttributeValue !== []) {
                        foreach ($basedOnOtherAttributeValue as $otherAttrName => $valueMappings) {
                            $otherAttrValue = $data[$otherAttrName] ?? null;

                            // Find the appropriate mapping based on the other attribute's value
                            $currentValueMappings = null;

                            // Check for '#not_set' special case first
                            if (
                                self::arrayKeyExists(
                                    '#not_set',
                                    $valueMappings
                                )
                            ) {
                                if (
                                    self::isAttributeNotSet(
                                        $otherAttrName,
                                        $data
                                    )
                                ) {
                                    $currentValueMappings = self::mappingValue($valueMappings, '#not_set');
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    '#not_empty',
                                    $valueMappings
                                )
                            ) { // Check for '#not_empty' special case
                                if (
                                    self::isAttributeNotEmpty(
                                        $otherAttrName,
                                        $data
                                    )
                                ) {
                                    $currentValueMappings = self::mappingValue($valueMappings, '#not_empty');
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    '#empty',
                                    $valueMappings
                                )
                            ) { // Check for '#empty' special case
                                if (
                                    self::isAttributeEmpty(
                                        $otherAttrName,
                                        $data
                                    )
                                ) {
                                    $currentValueMappings = self::mappingValue($valueMappings, '#empty');
                                }
                            } elseif (
                                self::arrayKeyExists(
                                    $otherAttrValue,
                                    $valueMappings
                                )
                            ) { // Check for exact value match
                                $currentValueMappings = self::mappingValue($valueMappings, $otherAttrValue);
                            }

                            // If we found mappings for the other attribute's value, apply them to this attribute
                            if ($currentValueMappings !== null) {
                                // Check for '#not_set' special case first
                                if (
                                    self::arrayKeyExists(
                                        '#not_set',
                                        $currentValueMappings
                                    )
                                ) {
                                    if ($originalFieldData !== null) {
                                        if (
                                            self::isFieldOptionNotSet(
                                                $attrName,
                                                $originalFieldData
                                            )
                                        ) {
                                            $translationArray = self::mappingValue($currentValueMappings, '#not_set');
                                            break;
                                        }
                                    } else { // Otherwise
                                        if (
                                            self::isAttributeNotSet(
                                                $attrName,
                                                $data
                                            )
                                        ) {
                                            $translationArray = self::mappingValue($currentValueMappings, '#not_set');
                                            break;
                                        }
                                    }
                                } elseif (
                                    self::arrayKeyExists(
                                        '#not_empty',
                                        $currentValueMappings
                                    )
                                ) { // Check for '#not_empty' special case
                                    if (
                                        self::isAttributeNotEmptyWithFallback(
                                            $attrName,
                                            $data,
                                            $originalFieldData
                                        )
                                    ) {
                                        $translationArray = self::mappingValue($currentValueMappings, '#not_empty');
                                        break;
                                    }
                                } elseif (
                                    self::arrayKeyExists(
                                        '#empty',
                                        $currentValueMappings
                                    )
                                ) { // Check for '#empty' special case
                                    if (
                                        self::isAttributeEmptyWithFallback(
                                            $attrName,
                                            $data,
                                            $originalFieldData
                                        )
                                    ) {
                                        $translationArray = self::mappingValue($currentValueMappings, '#empty');
                                        break;
                                    }
                                } elseif (
                                    self::arrayKeyExists(
                                        '',
                                        $currentValueMappings
                                    )
                                ) { // Check for exact value match (shouldn't happen for missing attributes)
                                    $translationArray = self::mappingValue($currentValueMappings, '');
                                    break;
                                }
                            }
                        }
                    } elseif (
                        $excludeFieldtype !== null
                        && $excludeFieldtype !== ''
                        && isset($specificTranslations[$excludeFieldtype])
                    ) { // Check for fieldtype-specific translations
                        $translationArray = $specificTranslations[$excludeFieldtype];
                    } else { // Fall back to default translations
                        $translationArray = $defaultTranslations;
                    }
                } else { // Otherwise
                    // Simple array structure
                    $translationArray = $translationConfig;
                }

                // Apply translations if we have a valid translation array
                if ($translationArray !== null) {
                    // Ensure $translationArray is an array before using array_key_exists
                    if (\is_array($translationArray)) {
                        // Check for '#not_set' special case first
                        if (
                            self::arrayKeyExists(
                                '#not_set',
                                $translationArray
                            )
                        ) {
                            if ($originalFieldData !== null) {
                                if (
                                    self::isFieldOptionNotSet(
                                        $attrName,
                                        $originalFieldData
                                    )
                                ) {
                                    // Resolve the statamic_attribute before using it as an array key
                                    $resolvedMapping = self::processAttributeMapping(
                                        (string) $attrName,
                                        null,
                                        $config,
                                        $data,
                                        null,
                                        null
                                    );
                                    if ($resolvedMapping !== null && $resolvedMapping !== []) {
                                        $mappedData = \array_merge(
                                            $mappedData,
                                            $resolvedMapping
                                        );
                                    }
                                }
                            } else { // Otherwise
                                if (
                                    self::isAttributeNotSet(
                                        $attrName,
                                        $data
                                    )
                                ) {
                                    // Resolve the statamic_attribute before using it as an array key
                                    $resolvedMapping = self::processAttributeMapping(
                                        (string) $attrName,
                                        null,
                                        $config,
                                        $data,
                                        null,
                                        null
                                    );
                                    if ($resolvedMapping !== null && $resolvedMapping !== []) {
                                        $mappedData = \array_merge(
                                            $mappedData,
                                            $resolvedMapping
                                        );
                                    }
                                }
                            }
                        }

                        // Check for '#not_empty' special case
                        if (
                            self::arrayKeyExists(
                                '#not_empty',
                                $translationArray
                            )
                        ) {
                            if (
                                self::isAttributeNotEmptyWithFallback(
                                    $attrName,
                                    $data,
                                    $originalFieldData
                                )
                            ) {
                                // Resolve the statamic_attribute before using it as an array key
                                $resolvedMapping = self::processAttributeMapping(
                                    (string) $attrName,
                                    null,
                                    $config,
                                    $data,
                                    null,
                                    null
                                );
                                if ($resolvedMapping !== null && $resolvedMapping !== []) {
                                    $mappedData = \array_merge(
                                        $mappedData,
                                        $resolvedMapping
                                    );
                                }
                            }
                        }

                        // Check for '#empty' special case
                        if (
                            self::arrayKeyExists(
                                '#empty',
                                $translationArray
                            )
                        ) {
                            if (
                                self::isAttributeEmptyWithFallback(
                                    $attrName,
                                    $data,
                                    $originalFieldData
                                )
                            ) {
                                // Resolve the statamic_attribute before using it as an array key
                                $resolvedMapping = self::processAttributeMapping(
                                    (string) $attrName,
                                    null,
                                    $config,
                                    $data,
                                    null,
                                    null
                                );
                                if ($resolvedMapping !== null && $resolvedMapping !== []) {
                                    $mappedData = \array_merge(
                                        $mappedData,
                                        $resolvedMapping
                                    );
                                }
                            }
                        }

                        // Check for null value (for missing attributes)
                        if (
                            self::arrayKeyExists(
                                'null',
                                $translationArray
                            )
                        ) {
                            // Resolve the statamic_attribute before using it as an array key
                            $resolvedMapping = self::processAttributeMapping(
                                (string) $attrName,
                                null,
                                $config,
                                $data,
                                null,
                                null
                            );
                            if ($resolvedMapping !== null && $resolvedMapping !== []) {
                                $mappedData = \array_merge(
                                    $mappedData,
                                    $resolvedMapping
                                );
                            }
                        }
                    } else { // Otherwise
                        // If $translationArray is not an array (e.g., it's a string), use it directly
                        $statamicAttributeKey = self::normalizeArrayKey(
                            $config['statamic_attribute']
                        );
                        if ($statamicAttributeKey !== null) {
                            $mappedData[$statamicAttributeKey] = $translationArray;
                        }
                    }
                }
            }
        }

        // Filter out null values, empty arrays, and arrays with only empty values from mapped attributes
        $mappedData = \array_filter(
            $mappedData,
            function ($value): bool {
                if ($value === null) {
                    return false;
                }

                if (\is_array($value)) {
                    // Filter out empty arrays
                    if ($value === []) {
                        return false;
                    }

                    // Filter out arrays that contain only empty strings or null values
                    $nonEmptyValues = \array_filter(
                        $value,
                        fn ($item): bool => $item !== null && $item !== ''
                    );

                    return $nonEmptyValues !== [];
                }

                return true;
            }
        );

        // Resolve [#site_handle] in mapped strings (e.g., folder on assets fields).
        if ($siteHandle !== null) {
            $withPlaceholdersResolved = self::applySiteHandlePlaceholderToMappedStructure(
                $mappedData,
                $siteHandle
            );
            if (\is_array($withPlaceholdersResolved)) {
                $mappedData = $withPlaceholdersResolved;
            }
        }

        return $mappedData;
    }

    /**
     * Get fieldtype-specific configuration from a mapping configuration.
     *
     * This method handles the new configuration structure where each property can
     * have both a default value and fieldtype-specific overrides.
     *
     * @param  array<int|string, mixed>  $attrConfig  The attribute configuration
     * @param  ?string  $fieldtype  The current fieldtype (null for default)
     * @return array<int|string, mixed> The resolved configuration for the current fieldtype
     */
    protected static function getFieldtypeSpecificConfig(
        array $attrConfig,
        ?string $fieldtype = null,
        int|string|null $attrName = null
    ): array {
        // Initialize the config array
        $config = [];

        // Define the properties that support fieldtype-specific overrides
        $properties = [
            'statamic_attribute',
            'key_translation',
            'value_translation',
            'value_substitution_regex',
            'value_calculation',
            'value_transformations',
            'exclude_fieldtypes',
            'value_prepend',
            'collapse_whitespace',
            'as_array',
            'convert_file_size',
            'as_integer',
            'as_boolean',
            'invert_boolean',
            'convert_dollar_amount',
            'alert_unsupported',
            'check_against_list',
            'statamic_other_field_value',
            'statamic_other_field_value_based_on_attribute_value',
            'statamic_other_field_value_based_on_nested_attribute_value',
            'statamic_type_based_on_attribute_value',
            'statamic_form_config',
            'transform_json_to_array',
            'separate_value_field_option',
            'translate_field_references',
            'translate_field_references_calculation',
            'combine_value_with_other_attribute_value',
            'explode_string',
            'trim',
            'trim_characters',
            'preserve_newlines',
            'unescape_slashes',
            'choices_as_fields',
            'extract_array_value',
            'extract_object_values',
            'expected_keys',
            'deduplicate_values',
            'get_selected_choice',
        ];

        // Process each property that supports fieldtype-specific overrides
        foreach ($properties as $property) {
            // Skip properties that don't exist in the attribute configuration
            if (! isset($attrConfig[$property])) {
                continue;
            }

            // Get the property configuration for processing
            $propertyConfig = $attrConfig[$property];

            // Initialize flag to track if we've found a matching configuration
            $matchFound = false;

            // Define all possible "new structure" keys
            $newStructureKeys = [
                'based_on_other_attribute_value',
                'specific_based_on_attribute_value',
                'based_on_attribute_value',
                'specific',
                'default',
            ];

            // Check if this is a new structure (has any of the new structure keys)
            $isNewStructure = \is_array($propertyConfig)
                && array_intersect_key(
                    $propertyConfig,
                    array_flip($newStructureKeys)
                ) !== [];

            // Handle properties that need to preserve their entire structure
            if (
                $isNewStructure
            ) {
                // Define properties that always require their complete structure to be preserved
                $preserveStructureProperties = [
                    'statamic_other_field_value_based_on_attribute_value',
                    'statamic_other_field_value_based_on_nested_attribute_value',
                ];

                // Preserve the entire configuration for these special properties
                if (
                    \in_array($property, $preserveStructureProperties, true)
                ) {
                    $config[$property] = $propertyConfig;
                    $matchFound = true;
                }
            }

            // Handle properties with complex nested structures that need preservation
            if (
                $isNewStructure
                && ! $matchFound
            ) {
                // Define keys that indicate complex nested structures requiring full preservation
                $complexStructureKeys = [
                    'based_on_other_attribute_value',
                    'specific_based_on_attribute_value',
                    'based_on_attribute_value',
                ];

                // Check if the property has any complex structure keys and preserve the entire config
                foreach ($complexStructureKeys as $complexKey) {
                    if (isset($propertyConfig[$complexKey])) {
                        // Preserve the entire property configuration since it contains complex nested structures
                        $config[$property] = $propertyConfig;

                        // Mark that we've found a matching configuration
                        $matchFound = true;

                        // Exit the loop since we found a complex structure to preserve
                        break;
                    }
                }
            }

            // Handle fieldtype-specific override (if not already processed)
            if (
                $isNewStructure
                && isset($propertyConfig['specific'])
                && $fieldtype !== null
                && $fieldtype !== ''
                && self::arrayKeyExists(
                    $fieldtype,
                    $propertyConfig['specific']
                )
                && ! $matchFound
            ) {
                // Use the fieldtype-specific configuration value
                $specificConfig = self::asArray(
                    $propertyConfig['specific']
                );
                $config[$property] = self::mappingValue(
                    $specificConfig,
                    $fieldtype
                );

                // Mark that we've found a matching configuration
                $matchFound = true;
            }

            // Handle default value (if not already processed)
            if (
                $isNewStructure
                && isset($propertyConfig['default'])
                && ! $matchFound
            ) {
                // Use the default configuration value
                $config[$property] = $propertyConfig['default'];

                // Mark that we've found a matching configuration
                $matchFound = true;
            }

            // Legacy structure: direct value (if not already processed)
            if (! $matchFound) {
                $config[$property] = $propertyConfig;
            }
        }

        // Return the processed configuration with fieldtype-specific overrides applied
        return $config;
    }

    /**
     * Map field attributes from WordPress form plugin to Statamic field attributes.
     *
     * Uses the fieldAttributeMapping array to transform field attributes from
     * WordPress form plugins (Gravity Forms, Formidable Forms) to Statamic
     * field attributes.
     *
     * @param  array<int|string, mixed>  $fieldData  The WordPress form field data
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for conditional logic mapping
     * @param  ?string  $statamicFieldtype  Optional Statamic fieldtype handle (not used for exclude_fieldtypes)
     * @param  ?string  $siteHandle  Optional site handle for placeholder replacement in mapped values
     * @return array<int|string, mixed> The mapped Statamic field attributes
     */
    public static function mapFieldAttributes(
        array $fieldData,
        array $allFields = [],
        ?string $statamicFieldtype = null,
        ?string $siteHandle = null
    ): array {
        // Use the source fieldtype for exclude_fieldtypes logic
        $excludeFieldtype = self::stringValue(
            $fieldData['type'] ?? null
        );

        // Check if fieldAttributeMapping is defined in the implementing class
        if (
            ! property_exists(
                static::class,
                'fieldAttributeMapping'
            )
        ) {
            // Return empty array since no mappings are available
            return [];
        }

        // Get standard attribute mappings from trait
        $mappedAttributes = static::mapAttributesFromMapping(
            $fieldData,
            static::$fieldAttributeMapping,
            $allFields,
            null,
            $excludeFieldtype,
            null,
            $siteHandle
        );

        // Handle conditional logic mapping
        $conditionalLogicData = static::extractConditionalLogicData($fieldData);

        if ($conditionalLogicData !== null) {
            // Transform conditional logic to Statamic format
            $conditionalLogic = static::mapConditionalLogic(
                $conditionalLogicData,
                $allFields
            );

            // Merge conditional logic attributes with other mapped attributes
            $mappedAttributes = \array_merge(
                $mappedAttributes,
                $conditionalLogic
            );
        }

        // Filter out null values from mapped attributes (consistent with mapFieldOptions)
        $mappedAttributes = \array_filter(
            $mappedAttributes,
            fn ($value): bool => $value !== null
        );

        // Return all mapped field attributes including conditional logic
        return $mappedAttributes;
    }

    /**
     * Map field options from WordPress form plugin to Statamic field attributes.
     *
     * Uses the fieldAttributeOptionsMapping array to transform field options from
     * WordPress form plugins (Gravity Forms, Formidable Forms) to Statamic
     * field attributes.
     *
     * @param  array<int|string, mixed>  $fieldData  The WordPress form field data
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for conditional logic mapping
     * @param  ?string  $statamicFieldtype  Optional Statamic fieldtype handle
     * @param  ?string  $siteHandle  Optional site handle for placeholder replacement
     * @return array<int|string, mixed> The mapped Statamic field attributes
     */
    public static function mapFieldOptions(
        array $fieldData,
        array $allFields = [],
        ?string $statamicFieldtype = null,
        ?string $siteHandle = null
    ): array {
        // Use the source fieldtype for exclude_fieldtypes logic
        $excludeFieldtype = self::stringValue(
            $fieldData['type'] ?? null
        );

        // Check if fieldAttributeOptionsMapping is defined in the implementing class
        if (
            ! property_exists(
                static::class,
                'fieldAttributeOptionsMapping'
            )
        ) {
            // Return empty array since no mappings are available
            return [];
        }

        // Parse field_options JSON if it exists
        $parsedFieldData = $fieldData;
        if (
            isset($fieldData['field_options'])
            && \is_string($fieldData['field_options'])
        ) {
            $fieldOptions = \json_decode(
                $fieldData['field_options'],
                true
            );
            if (\is_array($fieldOptions)) {
                // Merge parsed field_options with the original field data
                $parsedFieldData = \array_merge(
                    $fieldData,
                    $fieldOptions
                );
            }
        }

        // Get standard attribute mappings from trait using parsed data
        $fieldAttributeOptionsMapping = self::resolveFieldAttributeOptionsMapping();
        $mappedAttributes = static::mapAttributesFromMapping(
            $parsedFieldData,
            $fieldAttributeOptionsMapping,
            $allFields,
            null,
            $excludeFieldtype,
            $fieldData,
            $siteHandle
        );

        // Handle conditional logic mapping
        $conditionalLogicData = static::extractConditionalLogicData($fieldData);

        if ($conditionalLogicData !== null) {
            // Transform conditional logic to Statamic format
            $conditionalLogic = static::mapConditionalLogic(
                $conditionalLogicData,
                $allFields
            );

            // Merge conditional logic attributes with other mapped attributes
            $mappedAttributes = \array_merge(
                $mappedAttributes,
                $conditionalLogic
            );
        }

        // Filter out null values from mapped attributes (consistent with mapFieldAttributes)
        $mappedAttributes = \array_filter(
            $mappedAttributes,
            fn ($value): bool => $value !== null
        );

        // Return all mapped field options attributes including conditional logic
        return $mappedAttributes;
    }

    /**
     * Process combine_value_with_other_attribute_value configuration.
     *
     * @param  mixed  $mappedValue  The current mapped value
     * @param  array<int|string, mixed>  $config  The attribute configuration
     * @param  array<int|string, mixed>  $data  The input data
     * @return mixed The processed mapped value
     */
    protected static function processCombineValueWithOtherAttributeValue(
        mixed $mappedValue,
        array $config,
        array $data
    ) {
        if (
            ! isset($config['combine_value_with_other_attribute_value'])
            || ! \is_array($config['combine_value_with_other_attribute_value'])
        ) {
            return $mappedValue;
        }

        $combineConfig = self::asArray(
            $config['combine_value_with_other_attribute_value']
        );

        // Validate required keys
        $combinationType = self::stringValue(
            $combineConfig['combination_type'] ?? null
        );
        $attributeNames = self::asArray(
            $combineConfig['attribute_names'] ?? null
        );
        if (
            $combinationType === null
            || $attributeNames === []
        ) {
            return $mappedValue;
        }

        // Collect values from specified attributes
        $valuesToCombine = [];

        // Always include the current attribute's value first
        if (
            $mappedValue !== null
            && $mappedValue !== ''
        ) {
            if (\is_array($mappedValue)) {
                // If mappedValue is already an array, join it back to a string
                $stringParts = [];
                foreach ($mappedValue as $part) {
                    $stringParts[] = self::normalizeMixedToString($part);
                }

                $valuesToCombine[] = \implode(
                    ',',
                    $stringParts
                );
            } else { // Otherwise
                $valuesToCombine[] = self::normalizeMixedToString($mappedValue);
            }
        }

        // Then add values from the specified additional attributes
        foreach ($attributeNames as $additionalAttrName) {
            $normalizedAttrName = self::normalizeArrayKey($additionalAttrName);
            if ($normalizedAttrName === null) {
                continue;
            }

            $additionalValue = self::mappingValue(
                $data,
                $normalizedAttrName
            );
            if (
                $additionalValue !== null
                && $additionalValue !== ''
            ) {
                $valuesToCombine[] = self::normalizeMixedToString($additionalValue);
            }
        }

        // Combine values based on combination type
        $separator = self::stringValue(
            $combineConfig['string_separator'] ?? null
        );
        if (
            $combinationType === 'string'
            && $separator !== null
        ) {
            $mappedValue = \implode(
                $separator,
                $valuesToCombine
            );
        }

        // If no values were combined, return null to indicate no processing should occur
        if ($valuesToCombine === []) {
            return null;
        }

        return $mappedValue;
    }

    /**
     * Convert file size from one unit to another.
     *
     * Supports conversion between B, KB, MB, GB, TB units.
     * Input format should be like "800MB" or "1.5GB".
     * Normalizes whitespace in the input (e.g., "800 MB" becomes "800MB").
     *
     * @param  string  $fileSize  The file size string (e.g., "800MB" or "800 MB")
     * @param  string  $targetUnit  The target unit (e.g., "KB")
     * @return string The converted file size as a numeric string
     */
    protected static function convertFileSize(
        string $fileSize,
        string $targetUnit
    ): string {
        // Normalize the input by removing all whitespace
        $normalizedFileSize = \preg_replace(
            '/\s+/',
            '',
            $fileSize
        ) ?? $fileSize;

        // Extract the numeric value and unit from the normalized input
        if (
            \preg_match(
                '/^(\d+(?:\.\d+)?)([KMGT]?B)$/i',
                $normalizedFileSize,
                $matches
            ) !== 1
        ) {
            // Return the original value if format is invalid
            return '0';
        }

        $size = (float) $matches[1];
        $unit = \strtoupper($matches[2]);

        // Convert to bytes first
        $bytes = self::convertToBytes(
            $size,
            $unit
        );

        // Convert from bytes to target unit
        $result = self::convertFromBytes(
            $bytes,
            \strtoupper($targetUnit)
        );

        return (string) $result;
    }

    /**
     * Validate a value against a predefined validation list.
     *
     * Checks if the given value exists in the specified validation list.
     * This method is used to ensure that attribute values conform to
     * predefined sets of valid values (e.g., HTML5 autocomplete values).
     *
     * @param  string  $value  The value to validate
     * @param  string  $listKey  The key of the validation list to check against
     * @return bool True if the value is in the list, false otherwise
     */
    public static function validateValueAgainstList(
        string $value,
        string $listKey
    ): bool {
        // Check if the validation list exists
        if (! isset(static::$validationLists[$listKey])) {
            // Return false if the list doesn't exist
            return false;
        }

        // Get the validation list
        $validationList = static::$validationLists[$listKey];

        // Check if the value exists in the list
        $isValid = \in_array(
            $value,
            $validationList,
            true
        );

        return $isValid;
    }

    /**
     * Convert a size value to bytes.
     *
     * @param  float  $size  The size value
     * @param  string  $unit  The unit (B, KB, MB, GB, TB)
     * @return int The size in bytes
     */
    protected static function convertToBytes(
        float $size,
        string $unit
    ): int {
        $multipliers = [
            'B' => 1,
            'KB' => 1000,
            'MB' => 1000 * 1000,
            'GB' => 1000 * 1000 * 1000,
            'TB' => 1000 * 1000 * 1000 * 1000,
        ];

        return (int) ($size * ($multipliers[$unit] ?? 1));
    }

    /**
     * Convert bytes to a target unit.
     *
     * @param  int  $bytes  The size in bytes
     * @param  string  $targetUnit  The target unit (B, KB, MB, GB, TB)
     * @return int|float The size in the target unit
     */
    protected static function convertFromBytes(
        int $bytes,
        string $targetUnit
    ): int|float {
        $divisors = [
            'B' => 1,
            'KB' => 1000,
            'MB' => 1000 * 1000,
            'GB' => 1000 * 1000 * 1000,
            'TB' => 1000 * 1000 * 1000 * 1000,
        ];

        $result = $bytes / ($divisors[$targetUnit] ?? 1);

        // Return as integer if it's a whole number, otherwise as float
        $intResult = (int) $result;

        return $result === (float) $intResult ? $intResult : $result;
    }

    /**
     * Map array values using the provided key translation array.
     *
     * This method is specifically for handling complex array transformations
     * like choices in select fields, where the key of the option might need
     * to be translated.
     *
     * @param  array<int|string, mixed>  $array  The original array
     * @param  array<int|string, mixed>  $keyTranslation  The key translation array
     * @param  array<int|string, mixed>|null  $valueTransformations  Optional array of value transformations to apply to specific keys
     * @param  array<int|string, mixed>|null  $data  Optional field data for context
     * @return array<int|string, mixed> The mapped array
     */
    protected static function mapArrayWithKeyTranslation(
        array $array,
        array $keyTranslation,
        ?array $valueTransformations = null,
        ?string $fieldtype = null,
        ?array $data = null
    ): array {
        // Initialize array to store mapped array elements
        $mappedArray = [];

        // Check if this is an array of objects (like choices array)
        $isArrayOfObjects = $array !== [] &&
            \is_array(reset($array)) &&
            \array_keys(reset($array)) !== range(
                0,
                \count(reset($array)) - 1
            );

        // If this is an array of objects (e.g., array of choices), process each object individually
        if ($isArrayOfObjects) {
            // This is an array of objects, transform each object
            // Iterate through each object in the array and apply key translation
            foreach ($array as $index => $object) {
                // Initialize a new mapped object for the translated keys/values
                $mappedObject = [];
                $objectArray = self::asArray($object);

                // Transform each key in the object
                foreach ($objectArray as $key => $value) {
                    // Check if the key exists in the translation array
                    if (
                        self::arrayKeyExists(
                            $key,
                            $keyTranslation
                        )
                    ) {
                        // If the translation is null, skip this key entirely
                        $translatedKey = self::mappingValue(
                            $keyTranslation,
                            $key
                        );
                        if ($translatedKey === null) {
                            // Skip this key entirely
                            continue;
                        }

                        // Apply value transformations if configured
                        $transformedValue = $value;
                        $normalizedKey = self::normalizeArrayKey($key);
                        if (
                            $valueTransformations !== null
                            && $valueTransformations !== []
                            && $normalizedKey !== null
                        ) {
                            $transformationConfig = static::resolveValueTransformations(
                                $valueTransformations,
                                $data ?? [],
                                self::normalizeTransformationKey($normalizedKey),
                                $fieldtype
                            );
                            if ($transformationConfig !== null && $transformationConfig !== []) {
                                $indexCount = self::numericChoiceCount($index);
                                $transformedValue = static::applyValueTransformation(
                                    $value,
                                    $transformationConfig,
                                    $data,
                                    $indexCount
                                );
                            }
                        }

                        // Add the translated key and transformed value to the new object
                        $mappedObject[self::normalizeArrayKeyForAssignment($translatedKey)] = $transformedValue;
                    } else { // Otherwise
                        // Apply value transformations if configured
                        $transformedValue = $value;
                        $normalizedKey = self::normalizeArrayKey($key);
                        if (
                            $valueTransformations !== null
                            && $valueTransformations !== []
                            && $normalizedKey !== null
                        ) {
                            $transformationConfig = static::resolveValueTransformations(
                                $valueTransformations,
                                $data ?? [],
                                self::normalizeTransformationKey($normalizedKey),
                                $fieldtype
                            );
                            if ($transformationConfig !== null && $transformationConfig !== []) {
                                $indexCount = self::numericChoiceCount($index);
                                $transformedValue = static::applyValueTransformation(
                                    $value,
                                    $transformationConfig,
                                    $data,
                                    $indexCount
                                );
                            }
                        }

                        // Add the original key and transformed value to the new object
                        $mappedObject[self::normalizeArrayKeyForAssignment($key)] = $transformedValue;
                    }
                }

                // Check for generate_value transformations for missing keys
                if ($valueTransformations !== null && $valueTransformations !== []) {
                    // Get the expected keys for this fieldtype to check what keys should be generated
                    $expectedKeys = static::getExpectedKeys(
                        $data ?? [],
                        null,
                        $fieldtype
                    );
                    foreach ($expectedKeys as $expectedKey) {
                        $normalizedExpectedKey = self::normalizeArrayKey($expectedKey);
                        if ($normalizedExpectedKey === null) {
                            continue;
                        }

                        if (
                            ! self::arrayKeyExists(
                                $normalizedExpectedKey,
                                $mappedObject
                            )
                        ) {
                            // Try to resolve transformation config for this key
                            $transformationConfig = static::resolveValueTransformations(
                                $valueTransformations,
                                $data ?? [],
                                self::normalizeTransformationKey($normalizedExpectedKey),
                                $fieldtype
                            );
                            $generateConfig = self::asArray(
                                self::mappingValue(
                                    self::asArray($transformationConfig),
                                    'generate_value'
                                )
                            );
                            if ($generateConfig !== []) {
                                // Merge field data with current choice item data for context
                                $contextData = \array_merge(
                                    $data ?? [],
                                    $objectArray
                                );
                                $generatedValue = static::generateValue(
                                    $generateConfig,
                                    $contextData,
                                    $indexCount ?? 1
                                );
                                $mappedObject[$normalizedExpectedKey] = $generatedValue;
                            }
                        }
                    }
                }

                // Add the transformed object to the mapped array
                $mappedArray[] = $mappedObject;
            }
        } else { // Otherwise
            // This is a simple associative array, transform keys directly
            // Iterate through each element in the original array
            foreach ($array as $key => $value) {
                // Check if the key exists in the translation array
                $translatedKey = self::mappingValue(
                    $keyTranslation,
                    $key
                );
                if ($translatedKey !== null) {
                    // Add the translated key and original value to the new array
                    $mappedArray[self::normalizeArrayKeyForAssignment($translatedKey)] = $value;
                } else { // Otherwise
                    // Add the original key and value to the new array
                    $mappedArray[self::normalizeArrayKeyForAssignment($key)] = $value;
                }
            }
        }

        // Return the mapped array
        return $mappedArray;
    }

    /**
     * Resolve value transformations based on fieldtype and attribute values.
     *
     * @param  array<int|string, mixed>  $valueTransformations  The value transformations configuration
     * @param  array<int|string, mixed>  $data  The field data
     * @param  string  $key  The key to get transformations for
     * @param  ?string  $fieldtype  The current fieldtype
     * @return array<int|string, mixed>|null The resolved transformation configuration for the key
     */
    protected static function resolveValueTransformations(
        array $valueTransformations,
        array $data,
        string $key,
        ?string $fieldtype
    ): ?array {
        // Check for specific_based_on_attribute_value first (most specific)
        if (
            isset($valueTransformations['specific_based_on_attribute_value'])
            && $fieldtype !== null
            && $fieldtype !== ''
        ) {
            $specificBasedOnAttributeValue = self::normalizeMixedToArray(
                $valueTransformations['specific_based_on_attribute_value']
            );
            if (self::arrayKeyExists($fieldtype, $specificBasedOnAttributeValue)) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificBasedOnAttributeValue,
                        $fieldtype
                    )
                );
                foreach ($fieldtypeMappings as $attributeName => $attributeValueMappings) {
                    $normalizedAttributeName = self::normalizeArrayKey($attributeName);
                    if ($normalizedAttributeName === null) {
                        continue;
                    }

                    // Get the value of the attribute from the field data
                    $attributeValue = $data[$normalizedAttributeName] ?? null;

                    // Check if this attribute value has specific transformations for the key
                    $attributeValueMappingsArray = self::normalizeMixedToArray(
                        $attributeValueMappings
                    );
                    $attributeValueKey = self::normalizeArrayKey($attributeValue);
                    if ($attributeValueKey === null) {
                        continue;
                    }

                    $attributeValueConfig = self::mappingValue(
                        $attributeValueMappingsArray,
                        $attributeValueKey
                    );
                    if (
                        self::arrayKeyExists(
                            $key,
                            self::asArray($attributeValueConfig)
                        )
                    ) {
                        return self::normalizeTransformationConfig(
                            self::mappingValue(
                                self::asArray($attributeValueConfig),
                                $key
                            ),
                            $key
                        );
                    }
                }
            }
        }

        // Check for based_on_attribute_value
        if (isset($valueTransformations['based_on_attribute_value'])) {
            $basedOnAttributeValue = self::normalizeMixedToArray(
                $valueTransformations['based_on_attribute_value']
            );

            // Check for flat structure first (direct based_on_attribute_value)
            // This handles the case where based_on_attribute_value is used directly in value_transformations
            foreach ($data as $attrName => $attrValue) {
                $notEmptyConfig = self::mappingValue(
                    $basedOnAttributeValue,
                    '#not_empty'
                );
                if (
                    self::arrayKeyExists(
                        $key,
                        self::asArray($notEmptyConfig)
                    )
                    && (! in_array($attrValue, [null, '', []], true))
                ) {
                    $transformationValue = self::mappingValue(
                        self::asArray($notEmptyConfig),
                        $key
                    );

                    return \is_array($transformationValue) ? $transformationValue : [$key => $transformationValue];
                }

                $notSetConfig = self::mappingValue(
                    $basedOnAttributeValue,
                    '#not_set'
                );
                if (
                    self::arrayKeyExists(
                        $key,
                        self::asArray($notSetConfig)
                    )
                    && ! self::arrayKeyExists(
                        $attrName,
                        $data
                    )
                ) {
                    $transformationValue = self::mappingValue(
                        self::asArray($notSetConfig),
                        $key
                    );

                    return \is_array($transformationValue) ? $transformationValue : [$key => $transformationValue];
                }

                $emptyConfig = self::mappingValue(
                    $basedOnAttributeValue,
                    '#empty'
                );
                if (
                    self::arrayKeyExists(
                        $key,
                        self::asArray($emptyConfig)
                    )
                    && (in_array($attrValue, [null, '', []], true))
                ) {
                    $transformationValue = self::mappingValue(
                        self::asArray($emptyConfig),
                        $key
                    );

                    return \is_array($transformationValue) ? $transformationValue : [$key => $transformationValue];
                }
            }

            // Check for flat structure with transformation type as key
            // This handles the case where the key is the transformation type (like 'based_on_attribute_value')
            if ($key === 'based_on_attribute_value') {
                // Check each attribute in the data to see if any match the conditions
                foreach ($data as $attrName => $attrValue) {
                    $flatNotEmptyConfig = self::mappingValue(
                        $basedOnAttributeValue,
                        '#not_empty'
                    );
                    // Check for special conditions first
                    if (
                        $flatNotEmptyConfig !== null
                        && (! in_array($attrValue, [null, '', []], true))
                    ) {
                        return self::normalizeTransformationConfig(
                            self::asArray($flatNotEmptyConfig),
                            $key
                        );
                    }

                    $flatNotSetConfig = self::mappingValue(
                        $basedOnAttributeValue,
                        '#not_set'
                    );
                    if (
                        $flatNotSetConfig !== null
                        && ! self::arrayKeyExists(
                            $attrName,
                            $data
                        )
                    ) {
                        return self::normalizeTransformationConfig(
                            self::asArray($flatNotSetConfig),
                            $key
                        );
                    }

                    $flatEmptyConfig = self::mappingValue(
                        $basedOnAttributeValue,
                        '#empty'
                    );
                    if (
                        $flatEmptyConfig !== null
                        && (in_array($attrValue, [null, '', []], true))
                    ) {
                        return self::normalizeTransformationConfig(
                            self::asArray($flatEmptyConfig),
                            $key
                        );
                    }

                    // Then check for specific attribute values (only for scalar values)
                    if (\is_scalar($attrValue)) {
                        $attrValueKey = self::mappingLookupKey($attrValue);
                        $attrValueConfig = self::mappingValue(
                            $basedOnAttributeValue,
                            $attrValueKey
                        );
                        if ($attrValueConfig !== null) {
                            return self::normalizeTransformationConfig(
                                self::asArray($attrValueConfig),
                                $key
                            );
                        }
                    }
                }
            }

            // Check for specific first
            if (
                isset($basedOnAttributeValue['specific'])
                && $fieldtype !== null
                && $fieldtype !== ''
            ) {
                $specificConfig = self::normalizeMixedToArray(
                    $basedOnAttributeValue['specific']
                );
                if (isset($specificConfig[$fieldtype])) {
                    $fieldtypeMappings = self::normalizeMixedToArray(
                        $specificConfig[$fieldtype]
                    );
                    foreach ($fieldtypeMappings as $attributeName => $attributeValueMappings) {
                        $normalizedAttributeName = self::normalizeArrayKey($attributeName);
                        if ($normalizedAttributeName === null) {
                            continue;
                        }

                        // Get the value of the attribute from the field data
                        $attributeValue = $data[$normalizedAttributeName] ?? null;
                        $attributeValueMappingsArray = self::normalizeMixedToArray(
                            $attributeValueMappings
                        );

                        // Check for #not_set case
                        $notSetMappings = self::mappingValue(
                            $attributeValueMappingsArray,
                            '#not_set'
                        );
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($notSetMappings)
                            )
                            && ! self::arrayKeyExists(
                                $normalizedAttributeName,
                                $data
                            )
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($notSetMappings),
                                    $key
                                ),
                                $key
                            );
                        }

                        // Check for #not_empty case
                        $notEmptyMappings = self::mappingValue(
                            $attributeValueMappingsArray,
                            '#not_empty'
                        );
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($notEmptyMappings)
                            )
                            && (! in_array($attributeValue, [null, '', []], true))
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($notEmptyMappings),
                                    $key
                                ),
                                $key
                            );
                        }

                        // Check for #empty case
                        $emptyMappings = self::mappingValue(
                            $attributeValueMappingsArray,
                            '#empty'
                        );
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($emptyMappings)
                            )
                            && (in_array($attributeValue, [null, '', []], true))
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($emptyMappings),
                                    $key
                                ),
                                $key
                            );
                        }

                        // Check if this attribute value has specific transformations for the key
                        $attributeValueKey = self::normalizeArrayKey($attributeValue);
                        $attributeValueConfig = $attributeValueKey !== null
                            ? self::mappingValue(
                                $attributeValueMappingsArray,
                                $attributeValueKey
                            )
                            : null;
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($attributeValueConfig)
                            )
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($attributeValueConfig),
                                    $key
                                ),
                                $key
                            );
                        }
                    }
                }
            }

            // Check for default
            if (isset($basedOnAttributeValue['default'])) {
                $defaultConfig = self::normalizeMixedToArray(
                    $basedOnAttributeValue['default']
                );

                // First, check if the default config contains attribute values directly
                // This handles the case where default contains attribute values as keys
                foreach ($data as $attrName => $attrValue) {
                    $defaultNotEmptyConfig = self::mappingValue(
                        $defaultConfig,
                        '#not_empty'
                    );
                    // Check for special conditions first
                    if (
                        self::arrayKeyExists(
                            '#not_empty',
                            $defaultConfig
                        )
                        && (! in_array($attrValue, [null, '', []], true))
                    ) {
                        // Check if the key is the transformation type
                        if ($key === 'based_on_attribute_value') {
                            return self::normalizeTransformationConfig(
                                self::asArray($defaultNotEmptyConfig),
                                $key
                            );
                        }

                        // Check if the transformation config contains the key
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($defaultNotEmptyConfig)
                            )
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($defaultNotEmptyConfig),
                                    $key
                                ),
                                $key
                            );
                        }
                    }

                    $defaultNotSetConfig = self::mappingValue(
                        $defaultConfig,
                        '#not_set'
                    );
                    if (
                        self::arrayKeyExists(
                            '#not_set',
                            $defaultConfig
                        )
                        && ! self::arrayKeyExists(
                            $attrName,
                            $data
                        )
                    ) {
                        // Check if the key is the transformation type
                        if ($key === 'based_on_attribute_value') {
                            return self::normalizeTransformationConfig(
                                self::asArray($defaultNotSetConfig),
                                $key
                            );
                        }

                        // Check if the transformation config contains the key
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($defaultNotSetConfig)
                            )
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($defaultNotSetConfig),
                                    $key
                                ),
                                $key
                            );
                        }
                    }

                    $defaultEmptyConfig = self::mappingValue(
                        $defaultConfig,
                        '#empty'
                    );
                    if (
                        self::arrayKeyExists(
                            '#empty',
                            $defaultConfig
                        )
                        && (in_array($attrValue, [null, '', []], true))
                    ) {
                        // Check if the key is the transformation type
                        if ($key === 'based_on_attribute_value') {
                            return self::normalizeTransformationConfig(
                                self::asArray($defaultEmptyConfig),
                                $key
                            );
                        }

                        // Check if the transformation config contains the key
                        if (
                            self::arrayKeyExists(
                                $key,
                                self::asArray($defaultEmptyConfig)
                            )
                        ) {
                            return self::normalizeTransformationConfig(
                                self::mappingValue(
                                    self::asArray($defaultEmptyConfig),
                                    $key
                                ),
                                $key
                            );
                        }
                    }

                    // Then check for specific attribute values (only for scalar values)
                    if (\is_scalar($attrValue)) {
                        $attrValueKey = self::mappingLookupKey($attrValue);
                        $attrValueConfig = self::mappingValue(
                            $defaultConfig,
                            $attrValueKey
                        );
                        if ($attrValueConfig !== null) {
                            // Check if the key is the transformation type
                            if ($key === 'based_on_attribute_value') {
                                return self::normalizeTransformationConfig(
                                    self::asArray($attrValueConfig),
                                    $key
                                );
                            }

                            // Check if the transformation config contains the key
                            if (
                                self::arrayKeyExists(
                                    $key,
                                    self::asArray($attrValueConfig)
                                )
                            ) {
                                return self::normalizeTransformationConfig(
                                    self::mappingValue(
                                        self::asArray($attrValueConfig),
                                        $key
                                    ),
                                    $key
                                );
                            }
                        }
                    }
                }

                // Then, check the traditional structure where default contains attribute names as keys
                foreach ($defaultConfig as $attributeName => $attributeValueMappings) {
                    $normalizedAttributeName = self::normalizeArrayKey($attributeName);
                    if ($normalizedAttributeName === null) {
                        continue;
                    }

                    // Get the value of the attribute from the field data
                    $attributeValue = $data[$normalizedAttributeName] ?? null;
                    $attributeValueMappingsArray = self::normalizeMixedToArray(
                        $attributeValueMappings
                    );

                    // Check for special conditions first
                    // Check for #not_set case
                    $notSetMappings = self::mappingValue(
                        $attributeValueMappingsArray,
                        '#not_set'
                    );
                    if (
                        self::arrayKeyExists(
                            $key,
                            self::asArray($notSetMappings)
                        )
                        && ! self::arrayKeyExists(
                            $normalizedAttributeName,
                            $data
                        )
                    ) {
                        return self::normalizeTransformationConfig(
                            self::mappingValue(
                                self::asArray($notSetMappings),
                                $key
                            ),
                            $key
                        );
                    }

                    // Check for #not_empty case
                    $notEmptyMappings = self::mappingValue(
                        $attributeValueMappingsArray,
                        '#not_empty'
                    );
                    if (
                        self::arrayKeyExists(
                            $key,
                            self::asArray($notEmptyMappings)
                        )
                        && (! in_array($attributeValue, [null, '', []], true))
                    ) {
                        return self::normalizeTransformationConfig(
                            self::mappingValue(
                                self::asArray($notEmptyMappings),
                                $key
                            ),
                            $key
                        );
                    }

                    // Check for #empty case
                    $emptyMappings = self::mappingValue(
                        $attributeValueMappingsArray,
                        '#empty'
                    );
                    if (
                        self::arrayKeyExists(
                            $key,
                            self::asArray($emptyMappings)
                        )
                        && (in_array($attributeValue, [null, '', []], true))
                    ) {
                        return self::normalizeTransformationConfig(
                            self::mappingValue(
                                self::asArray($emptyMappings),
                                $key
                            ),
                            $key
                        );
                    }

                    // Check if this attribute value has transformations for the key
                    $attributeValueKey = self::normalizeArrayKey($attributeValue);
                    $attributeValueConfig = $attributeValueKey !== null
                        ? self::mappingValue(
                            $attributeValueMappingsArray,
                            $attributeValueKey
                        )
                        : null;
                    if (
                        self::arrayKeyExists(
                            $key,
                            self::asArray($attributeValueConfig)
                        )
                    ) {
                        return self::normalizeTransformationConfig(
                            self::mappingValue(
                                self::asArray($attributeValueConfig),
                                $key
                            ),
                            $key
                        );
                    }

                    // Check if the key is the transformation type and we have a direct mapping for the attribute value
                    if ($key === 'based_on_attribute_value') {
                        $directMapping = $attributeValueKey !== null
                            ? self::tryMappingValue(
                                $attributeValueMappingsArray,
                                $attributeValueKey
                            )
                            : null;
                        if ($directMapping !== null) {
                            return self::normalizeTransformationConfig(
                                $directMapping,
                                $key
                            );
                        }
                    }
                }
            }
        }

        // Check for based_on_other_attribute_value
        if (isset($valueTransformations['based_on_other_attribute_value'])) {
            $basedOnOtherAttributeValue = self::normalizeMixedToArray(
                $valueTransformations['based_on_other_attribute_value']
            );
            foreach ($basedOnOtherAttributeValue as $otherAttrName => $valueMappings) {
                $normalizedOtherAttrName = self::normalizeArrayKey($otherAttrName);
                if ($normalizedOtherAttrName === null) {
                    continue;
                }

                $otherAttrValue = $data[$normalizedOtherAttrName] ?? null;
                $valueMappingsArray = self::normalizeMixedToArray($valueMappings);
                $otherAttrValueKey = self::normalizeArrayKey($otherAttrValue);
                $otherAttrValueConfig = $otherAttrValueKey !== null
                    ? self::mappingValue(
                        $valueMappingsArray,
                        $otherAttrValueKey
                    )
                    : null;
                if (
                    self::arrayKeyExists(
                        $key,
                        self::asArray($otherAttrValueConfig)
                    )
                ) {
                    return self::normalizeTransformationConfig(
                        self::mappingValue(
                            self::asArray($otherAttrValueConfig),
                            $key
                        ),
                        $key
                    );
                }
            }
        }

        // Check for fieldtype-specific transformations
        if (
            isset($valueTransformations['specific'])
            && $fieldtype !== null
            && $fieldtype !== ''
        ) {
            $specificConfig = self::normalizeMixedToArray(
                $valueTransformations['specific']
            );
            $fieldtypeConfig = self::mappingValue(
                $specificConfig,
                $fieldtype
            );
            if (
                self::arrayKeyExists(
                    $key,
                    self::asArray($fieldtypeConfig)
                )
            ) {
                return self::normalizeTransformationConfig(
                    self::mappingValue(
                        self::asArray($fieldtypeConfig),
                        $key
                    ),
                    $key
                );
            }
        }

        // Check for default transformations
        $defaultTransformations = self::asArray(
            self::mappingValue($valueTransformations, 'default')
        );
        if (self::arrayKeyExists($key, $defaultTransformations)) {
            return self::normalizeTransformationConfig(
                self::mappingValue(
                    $defaultTransformations,
                    $key
                ),
                $key
            );
        }

        // Check for simple structure (legacy)
        if (self::arrayKeyExists($key, $valueTransformations)) {
            return self::normalizeTransformationConfig(
                self::mappingValue(
                    $valueTransformations,
                    $key
                ),
                $key
            );
        }

        return null;
    }

    /**
     * Apply a value transformation to a given value.
     *
     * @param  mixed  $value  The value to transform
     * @param  array<int|string, mixed>  $transformationConfig  The transformation configuration
     * @param  array<int|string, mixed>|null  $data  Optional data array for generate_value transformations
     * @param  ?int  $count  Optional count for generate_value transformations
     * @return mixed The transformed value
     */
    protected static function applyValueTransformation(
        mixed $value,
        array $transformationConfig,
        ?array $data = null,
        ?int $count = null
    ): mixed {
        // Apply dollar amount conversion if configured
        if (
            isset($transformationConfig['convert_dollar_amount'])
            && $transformationConfig['convert_dollar_amount'] === true
            && \is_string($value)
        ) {
            $value = (float) \str_replace(
                ['$', ','],
                '',
                $value
            );
        }

        // Set value to null if configured
        if (
            isset($transformationConfig['set_to_null'])
            && $transformationConfig['set_to_null'] === true
        ) {
            $value = null;
        }

        // Generate value if configured
        if (
            isset($transformationConfig['generate_value'])
            && \is_array($transformationConfig['generate_value'])
        ) {
            $value = self::generateValue(
                $transformationConfig['generate_value'],
                $data ?? [],
                $count ?? 1
            );
        }

        // Transform value to ISO 8601 date format if configured
        if (
            isset($transformationConfig['as_date_iso_8601'])
            && $transformationConfig['as_date_iso_8601'] === true
        ) {
            $value = self::transformToIso8601Date($value);
        }

        // Transform value to complex array if configured
        if (
            isset($transformationConfig['as_complex_array'])
            && \is_array($transformationConfig['as_complex_array'])
        ) {
            $value = self::transformToComplexArray(
                $value,
                $transformationConfig['as_complex_array'],
                $data ?? [],
                $count ?? 1
            );
        }

        // Transform numeric values to numeric strings if configured
        if (
            isset($transformationConfig['as_numeric_string'])
            && $transformationConfig['as_numeric_string'] === true
        ) {
            return self::transformToNumericString($value);
        }

        return $value;
    }

    /**
     * Generate a value based on the generate_value configuration.
     *
     * @param  array<int|string, mixed>  $generateConfig  Array of generation rules
     * @param  array<int|string, mixed>  $data  The data array to extract values from
     * @param  int  $count  The current count/index
     * @return string The generated value
     */
    protected static function generateValue(
        array $generateConfig,
        array $data,
        int $count
    ): string {
        $parts = [];

        foreach ($generateConfig as $rule) {
            if (
                ! \is_array($rule)
                || \count($rule) !== 1
            ) {
                continue; // Skip invalid rules
            }

            $key = array_key_first($rule);
            $normalizedKey = self::normalizeArrayKey($key);
            if ($normalizedKey === null) {
                continue;
            }

            $value = $rule[$normalizedKey];

            switch ($normalizedKey) {
                case 'value':
                    if ($value === '#field_handle') {
                        // Get the field handle from the data
                        $fieldName = self::normalizeMixedToString(
                            static::getFieldNameFromField($data)
                        );
                        $fieldId = self::normalizeMixedToString(
                            $data['id'] ?? ''
                        );
                        $parts[] = static::convertFieldNameToHandle(
                            $fieldName,
                            $fieldId
                        );
                    } elseif ($value === '#count') {
                        $parts[] = (string) $count;
                    } else { // Otherwise
                        $parts[] = self::normalizeMixedToString($value);
                    }

                    break;

                case 'attribute_value_sluggified':
                    $attributeValue = self::getNestedAttributeValue(
                        $data,
                        self::normalizeMixedToString($value)
                    );
                    $parts[] = self::sluggifyValue($attributeValue);
                    break;

                case 'attribute_value_snake_case':
                    $attributeValue = self::getNestedAttributeValue(
                        $data,
                        self::normalizeMixedToString($value)
                    );
                    $parts[] = self::snakeCaseValue($attributeValue);
                    break;
            }
        }

        return \implode(
            '_',
            $parts
        );
    }

    /**
     * Transform a value to a complex array structure.
     *
     * @param  mixed  $value  The value to transform (string or array of strings)
     * @param  array<int|string, mixed>  $complexArrayConfig  The complex array configuration
     * @param  array<int|string, mixed>  $data  The data array for placeholder substitution
     * @param  int  $count  The current count/index
     * @return array<int|string, mixed> The transformed complex array
     */
    protected static function transformToComplexArray(
        mixed $value,
        array $complexArrayConfig,
        array $data,
        int $count
    ): array {
        // If value is null or empty, return empty array
        if ($value === null || $value === '') {
            return [];
        }

        // If value is a string, treat as single value and return single complex array
        if (\is_string($value)) {
            // Create a copy of the complex array config
            $complexArray = $complexArrayConfig;
            // Replace placeholders in the complex array structure
            $complexArray = self::replaceComplexArrayPlaceholders(
                $complexArray,
                $value,
                $data,
                $count
            );

            return $complexArray;
        }

        // If value is a string, treat as single value and return single complex array
        if (\is_array($value)) {
            // If value is an array, process each element and return array of complex arrays
            $result = [];
            foreach ($value as $index => $stringValue) {
                // Create a copy of the complex array config for this iteration
                $complexArray = $complexArrayConfig;

                // Replace placeholders in the complex array structure
                $complexArray = self::replaceComplexArrayPlaceholders(
                    $complexArray,
                    self::normalizeMixedToString($stringValue),
                    $data,
                    $index + 1
                );

                $result[] = $complexArray;
            }

            return $result;
        }

        // Otherwise
        // Convert other types to string and treat as single value
        $complexArray = $complexArrayConfig;
        // Replace placeholders in the complex array structure
        $complexArray = self::replaceComplexArrayPlaceholders(
            $complexArray,
            self::normalizeMixedToString($value),
            $data,
            $count
        );

        return $complexArray;
    }

    /**
     * Replace placeholders in a complex array structure.
     *
     * @param  array<int|string, mixed>  $complexArray  The complex array structure
     * @param  string  $stringValue  The original string value
     * @param  array<int|string, mixed>  $data  The data array for context
     * @param  int  $count  The current count/index
     * @return array<int|string, mixed> The array with placeholders replaced
     */
    protected static function replaceComplexArrayPlaceholders(
        array $complexArray,
        string $stringValue,
        array $data,
        int $count
    ): array {
        $result = [];

        foreach ($complexArray as $key => $value) {
            // Replace placeholders in the key
            $newKey = self::replaceComplexArrayPlaceholder(
                $key,
                $stringValue,
                $data,
                $count
            );

            // Replace placeholders in the value
            if (\is_array($value)) {
                $newValue = self::replaceComplexArrayPlaceholders(
                    $value,
                    $stringValue,
                    $data,
                    $count
                );
            } else { // Otherwise
                $newValue = self::replaceComplexArrayPlaceholder(
                    $value,
                    $stringValue,
                    $data,
                    $count
                );
            }

            $result[self::normalizeArrayKeyForAssignment($newKey)] = $newValue;
        }

        return $result;
    }

    /**
     * Replace placeholders in a single value.
     *
     * @param  mixed  $value  The value to process
     * @param  string  $stringValue  The original string value
     * @param  array<int|string, mixed>  $data  The data array for context
     * @param  int  $count  The current count/index
     * @return mixed The value with placeholders replaced
     */
    protected static function replaceComplexArrayPlaceholder(
        mixed $value,
        string $stringValue,
        array $data,
        int $count
    ): mixed {
        if (! \is_string($value)) {
            return $value;
        }

        // Replace [#value] with the original string value
        $value = \str_replace(
            '[#value]',
            $stringValue,
            $value
        );

        // Replace [#count] with the current count
        $value = \str_replace(
            '[#count]',
            (string) $count,
            $value
        );

        // Replace [#field_handle] with the field handle
        if (
            \str_contains(
                $value,
                '[#field_handle]'
            )
        ) {
            $fieldName = static::getFieldNameFromField($data);
            $fieldId = self::normalizeMixedToString($data['id'] ?? '');
            $fieldHandle = static::convertFieldNameToHandle(
                self::normalizeMixedToString($fieldName),
                $fieldId
            );
            $value = \str_replace(
                '[#field_handle]',
                $fieldHandle,
                $value
            );
        }

        return $value;
    }

    /**
     * Get a nested attribute value from the data array.
     *
     * @param  array<int|string, mixed>  $data  The data array
     * @param  string  $path  The dot-separated path to the attribute
     * @return mixed The attribute value
     */
    protected static function getNestedAttributeValue(
        array $data,
        string $path
    ): mixed {
        // If path doesn't contain dots, it's a direct attribute
        if (
            ! str_contains(
                $path,
                '.'
            )
        ) {
            return $data[$path] ?? null;
        }

        // Handle dot-separated paths
        $parts = \explode(
            '.',
            $path
        );
        $current = $data;

        foreach ($parts as $part) {
            if (
                \is_array($current)
                && self::arrayKeyExists(
                    $part,
                    $current
                )
            ) {
                $current = $current[$part];
            } else { // Otherwise
                return null;
            }
        }

        return $current;
    }

    /**
     * Transform a value to ISO 8601 date format.
     *
     * This method handles both string and array values, converting date strings
     * in various formats (MM/DD/YYYY, DD/MM/YYYY, YYYY/MM/DD, MM-DD-YYYY, etc.)
     * to ISO 8601 format (YYYY-MM-DD).
     *
     * @param  mixed  $value  The value to transform (string or array of strings)
     * @return mixed The transformed value in ISO 8601 format
     */
    protected static function transformToIso8601Date(
        $value
    ): mixed {
        // Handle null values
        if ($value === null) {
            return null;
        }

        // Handle array values
        if (\is_array($value)) {
            $result = [];
            foreach ($value as $item) {
                $result[] = self::convertDateStringToIso8601(
                    self::normalizeMixedToString($item)
                );
            }

            return $result;
        }

        // Handle string values
        if (\is_string($value)) {
            return self::convertDateStringToIso8601($value);
        }

        // Return unchanged for other types
        return $value;
    }

    /**
     * Convert a single date string to ISO 8601 format.
     *
     * Supports various date formats including:
     *   - MM/DD/YYYY, DD/MM/YYYY, YYYY/MM/DD
     *   - MM-DD-YYYY, DD-MM-YYYY, YYYY-MM-DD
     *   - MM\/DD\/YYYY, DD\/MM\/YYYY, YYYY\/MM\/DD (with escaped slashes)
     *
     * @param  string  $dateString  The date string to convert
     * @return string The date in ISO 8601 format (YYYY-MM-DD)
     */
    protected static function convertDateStringToIso8601(
        string $dateString
    ): string {
        // Trim whitespace
        $dateString = \trim($dateString);

        // Handle empty strings
        if ($dateString === '') {
            return $dateString;
        }

        // Define patterns to match various date formats
        $patterns = [
            // MM/DD/YYYY or DD/MM/YYYY patterns
            '/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/' => 'month_day_year',
            // YYYY/MM/DD pattern
            '/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/' => 'year_month_day',
            // MM-DD-YYYY or DD-MM-YYYY patterns
            '/^(\d{1,2})-(\d{1,2})-(\d{4})$/' => 'month_day_year',
            // YYYY-MM-DD pattern
            '/^(\d{4})-(\d{1,2})-(\d{1,2})$/' => 'year_month_day',
            // MM\/DD\/YYYY or DD\/MM\/YYYY patterns (escaped slashes)
            '/^(\d{1,2})\\\\\/(\d{1,2})\\\\\/(\d{4})$/' => 'month_day_year',
            // YYYY\/MM\/DD pattern (escaped slashes)
            '/^(\d{4})\\\\\/(\d{1,2})\\\\\/(\d{1,2})$/' => 'year_month_day',
        ];

        foreach ($patterns as $pattern => $format) {
            if (
                \preg_match(
                    $pattern,
                    $dateString,
                    $matches
                ) === 1
            ) {
                switch ($format) {
                    case 'month_day_year':
                        $month = str_pad(
                            $matches[1],
                            2,
                            '0',
                            STR_PAD_LEFT
                        );
                        $day = str_pad(
                            $matches[2],
                            2,
                            '0',
                            STR_PAD_LEFT
                        );
                        $year = $matches[3];

                        return "{$year}-{$month}-{$day}";

                    case 'year_month_day':
                        $year = $matches[1];
                        $month = str_pad(
                            $matches[2],
                            2,
                            '0',
                            STR_PAD_LEFT
                        );
                        $day = str_pad(
                            $matches[3],
                            2,
                            '0',
                            STR_PAD_LEFT
                        );

                        return "{$year}-{$month}-{$day}";
                }
            }
        }

        // If no pattern matches, return the original string
        return $dateString;
    }

    /**
     * Transform numeric values to numeric strings.
     *
     * This method converts numeric values (integers and floats) to their string
     * representations while leaving other value types unchanged. For arrays,
     * it recursively processes each element.
     *
     * Examples:
     *   - 1 → '1'
     *   - 1.23 → '1.23'
     *   - [1, 2, 3] → ['1', '2', '3']
     *   - [1.23, 4.56] → ['1.23', '4.56']
     *   - [1, '2', 3.45, '5.67', true, false, 'foo'] → ['1', '2', '3.45', '5.67', true, false, 'foo']
     *
     * @param  mixed  $value  The value to transform
     * @return mixed The transformed value with numeric values converted to strings
     */
    protected static function transformToNumericString(
        $value
    ): mixed {
        // Handle null values
        if ($value === null) {
            return null;
        }

        // Handle array values
        if (\is_array($value)) {
            $result = [];
            foreach ($value as $item) {
                $result[] = self::transformToNumericString($item);
            }

            return $result;
        }

        // Handle integer values
        if (\is_int($value)) {
            return (string) $value;
        }

        // Handle float values (but not strings that represent floats)
        if (\is_float($value)) {
            return (string) $value;
        }

        // Return unchanged for other types (strings, booleans, etc.)
        return $value;
    }

    /**
     * Sluggify a value by converting to lowercase and replacing non-alphanumeric
     * characters with hyphens.
     *
     * @param  mixed  $value  The value to sluggify
     * @return string The sluggified value
     */
    protected static function sluggifyValue(
        $value
    ): string {
        if ($value === null) {
            return '';
        }

        $string = self::normalizeMixedToString($value);

        // Convert to lowercase
        $string = \strtolower($string);

        // Replace non-alphanumeric characters with hyphens
        $string = \preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $string
        );
        if (! \is_string($string)) {
            $string = '';
        }

        // Remove leading and trailing hyphens
        $string = \trim(
            $string,
            '-'
        );

        return $string;
    }

    /**
     * Convert a value to snake_case by converting to lowercase and replacing
     * non-alphanumeric characters with underscores.
     *
     * @param  mixed  $value  The value to convert to snake_case
     * @return string The snake_case value
     */
    protected static function snakeCaseValue(
        $value
    ): string {
        if ($value === null) {
            return '';
        }

        $string = self::normalizeMixedToString($value);

        // Convert to lowercase
        $string = \strtolower($string);

        // Replace non-alphanumeric characters with underscores
        $string = \preg_replace(
            '/[^a-z0-9]+/',
            '_',
            $string
        );
        if (! \is_string($string)) {
            $string = '';
        }

        // Remove leading and trailing underscores
        $string = \trim(
            $string,
            '_'
        );

        return $string;
    }

    /**
     * Get all field attributes that have mappings defined.
     *
     * @return array<int|string, mixed> Array of field attribute names that have mappings
     */
    public static function getMappedFieldAttributes(): array
    {
        // Check if fieldAttributeMapping is defined in the implementing class
        if (
            ! property_exists(
                static::class,
                'fieldAttributeMapping'
            )
        ) {
            return [];
        }

        // Initialize array to collect mapped attribute names
        $mappedAttributes = [];

        // Iterate through all defined field attribute mappings
        foreach (static::$fieldAttributeMapping as $attributeName => $mapping) {
            // Check if this attribute has a valid Statamic attribute mapping
            if (
                isset($mapping['statamic_attribute'])

            ) {
                // Add the attribute name to our list of mapped attributes
                $mappedAttributes[] = $attributeName;
            }
        }

        // Return the list of field attributes that have mappings
        return $mappedAttributes;
    }

    /**
     * Whether an unsupported attribute is worth reporting to the operator.
     *
     * **An attribute sitting at its default carries no operator-actionable information (wordpress-importer-archive#1157).**
     * The checklist exists to name manual work; an attribute whose value is what every form has
     * anyway names none, and five such notices in one afternoon were carried into a migration
     * checklist before anyone examined the values.
     *
     * Three gates, in order:
     *
     * - `alert_unsupported => false` suppresses at any value, for an attribute with no runtime
     *   meaning to carry across — `duplicatable` describes whether the Gravity Forms editor offers
     *   a duplicate control while building the form, and says nothing about the rendered form.
     * - `alert_unsupported_default` suppresses only while the value equals that default, so an
     *   attribute that matters *when set* keeps its warning for exactly the case that needs it.
     *   `is_payment` is `false` on all 214 fields measured, and a payment field is a genuine
     *   migration concern Statamic has no analog for — suppressing it outright would remove the
     *   warning for the one estate state that would need it.
     * - An empty value is never reported, which is the pre-existing behavior.
     *
     * **`false` is not empty here, and that is why `is_payment` was reported at all.** The emptiness
     * test is `null`/`''`/`[]`, and a strict comparison passes `false` through all three — so a
     * boolean attribute that is false everywhere warned on every field of every form.
     *
     * @param  array<int|string, mixed>  $mapping
     */
    protected static function shouldAlertUnsupportedAttribute(
        array $mapping,
        mixed $attributeValue
    ): bool {
        // Explicitly suppressed at any value.
        if (($mapping['alert_unsupported'] ?? true) === false) {
            return false;
        }

        // Suppressed while the attribute sits at the default the estate universally carries.
        if (
            \array_key_exists('alert_unsupported_default', $mapping)
            && $attributeValue === $mapping['alert_unsupported_default']
        ) {
            return false;
        }

        // Pre-existing behavior: an absent or empty value is not a finding.
        return ! in_array($attributeValue, [null, '', []], true);
    }

    /**
     * Map field attributes from WordPress form plugin to Statamic field attributes
     * with context for warnings.
     *
     * This method extends the basic mapFieldAttributes method to include context
     * information for generating warnings about unsupported attributes.
     *
     * @param  array<int|string, mixed>  $fieldData  The WordPress form field data
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?int  $formId  The form ID for warning context
     * @param  ?string  $siteHandle  Optional site handle for placeholder replacement in mapped values
     * @return array<int|string, mixed> The mapped Statamic field attributes
     */
    public static function mapFieldAttributesWithContext(
        array $fieldData,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?int $formId = null,
        ?string $siteHandle = null
    ): array {
        // First, get the basic mapped attributes
        $mappedAttributes = self::mapFieldAttributes(
            $fieldData,
            [],
            null,
            $siteHandle
        );

        // If we don't have context information, just return the mapped attributes
        if (
            $formTitle === null
            || $fieldLabel === null
        ) {
            return $mappedAttributes;
        }

        // Check if fieldAttributeMapping is defined in the implementing class
        if (
            ! property_exists(
                static::class,
                'fieldAttributeMapping'
            )
        ) {
            return $mappedAttributes;
        }

        // Only add unsupported attribute warnings for mapped fieldtypes

        // Get the field type from the field data
        $fieldType = self::stringValue($fieldData['type'] ?? null);

        // Get the full mapping array for the implementing class
        $mappingArray = static::getMapping();

        // Determine if this fieldtype is mapped using the new mapping structure
        $fieldTypeMapping = self::mappingConfig(
            $fieldType !== null
                ? self::mappingValue($mappingArray, $fieldType)
                : null
        );
        $isMapped = $fieldType !== null
            && $fieldType !== ''
            && self::arrayKeyExists($fieldType, $mappingArray)
            && self::isFieldtypeMapped($fieldTypeMapping);

        // If the fieldtype is not mapped, do not add unsupported attribute warnings
        if (! $isMapped) {
            return $mappedAttributes;
        }

        // Collect warnings for unsupported attributes
        foreach ($fieldData as $attributeName => $attributeValue) {
            // Skip if this attribute is not defined in our mapping
            if (! isset(static::$fieldAttributeMapping[$attributeName])) {
                // This attribute is not mapped at all - add warning
                if ((! in_array($attributeValue, [null, '', []], true))) {
                    ImportWarningManager::addWarning(
                        'forms',
                        'unsupported_attributes',
                        [
                            'form_title' => $formTitle,
                            'field_label' => $fieldLabel,
                            'attribute_name' => $attributeName,
                            'attribute_value' => $attributeValue,
                            'form_id' => $formId,
                            'field_type' => $fieldData['type'] ?? 'unknown',
                        ]
                    );
                }

                continue;
            }

            // Get the mapping configuration for this attribute
            $mapping = static::$fieldAttributeMapping[$attributeName];

            // Check if this attribute is mapped to null (unsupported)
            if (($mapping['statamic_attribute'] ?? null) === null) {
                if (self::shouldAlertUnsupportedAttribute($mapping, $attributeValue)) {
                    ImportWarningManager::addWarning(
                        'forms',
                        'unsupported_attributes',
                        [
                            'form_title' => $formTitle,
                            'field_label' => $fieldLabel,
                            'attribute_name' => $attributeName,
                            'attribute_value' => $attributeValue,
                            'form_id' => $formId,
                            'field_type' => $fieldData['type'] ?? 'unknown',
                        ]
                    );
                }
            }
        }

        // Filter out null values from mapped attributes
        $mappedAttributes = \array_filter(
            $mappedAttributes,
            fn ($value): bool => $value !== null
        );

        return $mappedAttributes;
    }

    /**
     * Get the Statamic preset value for a parent field using the mapping config.
     *
     * This is DRY and generic: it finds the attribute mapped to 'preset' and applies
     * value_translation if present.
     *
     * @param  array<int|string, mixed>  $parentField  The parent field data
     * @param  array<int|string, mixed>  $mappingConfig  The fieldtype mapping config
     */
    protected static function getStatamicPresetValue(
        array $parentField,
        array $mappingConfig
    ): ?string {
        // Iterate through the mapping config to find the attribute mapped to 'preset'
        foreach ($mappingConfig as $attr => $config) {
            $configArray = self::mappingConfig($config);
            // Check if this attribute is mapped to Statamic's 'preset' attribute
            if (self::stringValue(self::mappingValue($configArray, 'statamic_attribute')) === 'preset') {
                $normalizedAttr = self::normalizeArrayKey($attr);
                if ($normalizedAttr === null) {
                    continue;
                }

                // Retrieve the value from the parent field for this attribute
                $value = self::mappingValue(
                    $parentField,
                    $normalizedAttr
                );

                // If the value is not set, return null (no preset to translate)
                if ($value === null) {
                    return null;
                }

                // If a value_translation array exists, translate the value accordingly
                $valueTranslation = self::asArray(
                    self::mappingValue($configArray, 'value_translation')
                );
                if ($valueTranslation !== []) {
                    $translated = self::mappingValue(
                        $valueTranslation,
                        $value
                    );

                    return self::stringValue($translated) ?? self::stringValue($value);
                }

                // Return the raw value if no translation is needed
                return self::stringValue($value);
            }
        }

        // No attribute mapped to 'preset' found in the mapping config
        return null;
    }

    /**
     * Resolve the Statamic fieldtype class for a given field type and plugin.
     *
     * @param  string|array<int|string, mixed>  $fieldType  The field type (e.g., 'address') or full field array
     * @param  string  $pluginName  The plugin name (e.g., 'gravity-forms')
     * @return ?string Fully qualified Statamic fieldtype class name or null if not found
     */
    public static function resolveStatamicFieldtypeClass(
        string|array $fieldType,
        string $pluginName
    ): ?string {
        // Map plugin to mapping class and get the Statamic fieldtype handle
        $statamicType = match ($pluginName) {
            // Use GravityForms mapping class to get Statamic fieldtype handle
            'gravity-forms' => GravityFormsFieldtypeMapping::getStatamicFieldtype($fieldType),
            // Use FormidableForms mapping class to get Statamic fieldtype handle
            'formidable-forms' => FormidableFormsFieldtypeMapping::getStatamicFieldtype($fieldType),
            // Unknown plugin, cannot resolve fieldtype
            default => null,
        };

        // If no Statamic fieldtype handle was found, return null
        if (
            in_array($statamicType, [null, '', false], true)
        ) {
            return null;
        }

        // Convert Statamic fieldtype handle to fully qualified class name (e.g., 'address' => 'App\\Fieldtypes\\Address')
        $class = 'App\\Fieldtypes\\'.Str::studly($statamicType);

        // Return the class name if it exists, otherwise return null
        return class_exists($class) ? $class : null;
    }

    /**
     * Resolve the Statamic fieldtype from a mapping array, supporting attribute-based
     * mapping.
     *
     * @param  array<int|string, mixed>  $mappingArray  The mapping array (type => mapping config)
     * @param  string|array<int|string, mixed>  $fieldOrType  The field type string or full field array
     * @return string|null|false Statamic fieldtype, null if not mapped, or false if suppressed
     */
    protected static function resolveFieldtypeFromMapping(
        array $mappingArray,
        string|array $fieldOrType
    ): string|null|false {
        if (\is_string($fieldOrType)) {
            $type = $fieldOrType;
            $field = [];
        } else { // Otherwise
            $type = self::stringValue($fieldOrType['type'] ?? null);
            $field = $fieldOrType;
        }

        if (
            $type === null
            || $type === ''
            || ! self::arrayKeyExists($type, $mappingArray)
        ) {
            return null;
        }

        $mapping = self::mappingValue(
            $mappingArray,
            $type
        );

        if (! \is_array($mapping)) {
            return self::resolveFieldtypeMappingValue($mapping);
        }

        // Parse field_options JSON if it exists to make attributes available for based_on_other_attribute_value
        $parsedField = $field;
        if (
            isset($field['field_options'])
            && \is_string($field['field_options'])
        ) {
            $fieldOptions = \json_decode(
                $field['field_options'],
                true
            );
            if (\is_array($fieldOptions)) {
                // Merge parsed field_options with the original field data
                $parsedField = \array_merge(
                    $field,
                    $fieldOptions
                );
            }
        }

        // Handle based_on_other_attribute_value logic
        if (isset($mapping['based_on_other_attribute_value'])) {
            $basedOnConfig = self::asArray(
                $mapping['based_on_other_attribute_value']
            );

            // Check if based_on_other_attribute_value has 'default' and 'specific' structure
            if (
                self::arrayKeyExists(
                    'default',
                    $basedOnConfig
                )
                || self::arrayKeyExists(
                    'specific',
                    $basedOnConfig
                )
            ) {
                $defaultBasedOnConfig = self::asArray(
                    self::mappingValue($basedOnConfig, 'default')
                );
                $specificBasedOnConfig = self::asArray(
                    self::mappingValue($basedOnConfig, 'specific')
                );

                // Process each based_on_other_attribute_value configuration
                $configToUse = $defaultBasedOnConfig;

                // Check for fieldtype-specific configuration
                $typeSpecificConfig = self::mappingValue(
                    $specificBasedOnConfig,
                    $type
                );
                if ($typeSpecificConfig !== null) {
                    $configToUse = self::asArray($typeSpecificConfig);
                }

                foreach ($configToUse as $otherAttrName => $valueMappings) {
                    $normalizedOtherAttrName = self::normalizeArrayKey($otherAttrName);
                    if ($normalizedOtherAttrName === null) {
                        continue;
                    }

                    $valueMappingsArray = self::asArray($valueMappings);

                    // Get the value of the other attribute from parsed field data
                    $otherAttrValue = self::mappingValue(
                        $parsedField,
                        $normalizedOtherAttrName
                    );

                    // Check if we have a mapping for this value
                    if (self::arrayKeyExists($otherAttrValue, $valueMappingsArray)) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                $otherAttrValue
                            )
                        );
                    }

                    // Check for #not_set case
                    if (
                        self::arrayKeyExists(
                            '#not_set',
                            $valueMappingsArray
                        )
                        && ! self::arrayKeyExists(
                            $normalizedOtherAttrName,
                            $parsedField
                        )
                    ) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                '#not_set'
                            )
                        );
                    }

                    // Check for #not_empty case
                    if (
                        self::arrayKeyExists(
                            '#not_empty',
                            $valueMappingsArray
                        )
                        && (! in_array($otherAttrValue, [null, '', []], true))
                    ) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                '#not_empty'
                            )
                        );
                    }

                    // Check for empty string case
                    if (
                        self::arrayKeyExists(
                            '',
                            $valueMappingsArray
                        )
                        && $otherAttrValue === ''
                    ) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                ''
                            )
                        );
                    }
                }
            } else { // Otherwise
                // Legacy structure - process each based_on_other_attribute_value configuration
                foreach ($basedOnConfig as $otherAttrName => $valueMappings) {
                    $normalizedOtherAttrName = self::normalizeArrayKey($otherAttrName);
                    if ($normalizedOtherAttrName === null) {
                        continue;
                    }

                    $valueMappingsArray = self::asArray($valueMappings);

                    // Get the value of the other attribute from parsed field data
                    $otherAttrValue = self::mappingValue(
                        $parsedField,
                        $normalizedOtherAttrName
                    );

                    // Check if we have a mapping for this value
                    if (self::arrayKeyExists($otherAttrValue, $valueMappingsArray)) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                $otherAttrValue
                            )
                        );
                    }

                    // Check for #not_set case
                    if (
                        self::arrayKeyExists(
                            '#not_set',
                            $valueMappingsArray
                        )
                        && ! self::arrayKeyExists(
                            $normalizedOtherAttrName,
                            $parsedField
                        )
                    ) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                '#not_set'
                            )
                        );
                    }

                    // Check for #not_empty case
                    if (
                        self::arrayKeyExists(
                            '#not_empty',
                            $valueMappingsArray
                        )
                        && (! in_array($otherAttrValue, [null, '', []], true))
                    ) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                '#not_empty'
                            )
                        );
                    }

                    // Check for empty string case
                    if (
                        self::arrayKeyExists(
                            '',
                            $valueMappingsArray
                        )
                        && $otherAttrValue === ''
                    ) {
                        return self::resolveFieldtypeMappingValue(
                            self::mappingValue(
                                $valueMappingsArray,
                                ''
                            )
                        );
                    }
                }
            }
        }

        // Handle attributes-based mapping
        if (
            isset($mapping['attributes'])

        ) {
            foreach (self::asArray($mapping['attributes']) as $attr => $attrMapValue) {
                $attrMap = self::asArray($attrMapValue);
                $normalizedAttr = self::normalizeArrayKey($attr);
                if ($normalizedAttr === null) {
                    continue;
                }

                $attrValue = self::mappingValue(
                    $parsedField,
                    $normalizedAttr
                );

                // Check for exact value match
                if (
                    $attrValue !== null
                    && self::arrayKeyExists($attrValue, $attrMap)
                ) {
                    return self::resolveFieldtypeMappingValue(
                        self::mappingValue(
                            $attrMap,
                            $attrValue
                        )
                    );
                }

                // Check for #not_set case
                if (
                    self::arrayKeyExists(
                        '#not_set',
                        $attrMap
                    )
                    && ! self::arrayKeyExists(
                        $normalizedAttr,
                        $parsedField
                    )
                ) {
                    return self::resolveFieldtypeMappingValue(
                        self::mappingValue(
                            $attrMap,
                            '#not_set'
                        )
                    );
                }

                // Check for #empty case
                $emptyMapping = self::mappingValue(
                    $attrMap,
                    '#empty'
                );
                if (
                    $attrValue === ''
                    && $emptyMapping !== null
                ) {
                    return self::resolveFieldtypeMappingValue($emptyMapping);
                }

                // Check for #not_empty case
                if (
                    self::arrayKeyExists(
                        '#not_empty',
                        $attrMap
                    )
                    && ($attrValue !== '' && $attrValue !== [])
                ) {
                    return self::resolveFieldtypeMappingValue(
                        self::mappingValue(
                            $attrMap,
                            '#not_empty'
                        )
                    );
                }
            }
        }

        // Check for linked_field configuration
        $linkedField = static::getLinkedFieldFromMapping(
            $mappingArray,
            $fieldOrType
        );
        if ($linkedField !== null) {
            // If linked_field is found, return the linked field handle instead of the default fieldtype
            return $linkedField;
        }

        return self::resolveFieldtypeMappingValue(
            self::mappingValue($mapping, 'default')
        );
    }

    /**
     * Get the linked field handle for a fieldtype mapping.
     *
     * This method resolves linked field handles from the mapping configuration,
     * supporting the same conditional logic patterns as regular fieldtype mapping:
     *   - `default`: Simple default linked field handle
     *   - `based_on_attribute_value`: Link based on the value of a specific attribute
     *   - `based_on_other_attribute_value`: Link based on the value of another
     *     attribute
     *
     * When a linked field is used, the resulting Statamic blueprint field will have
     * a 'field' property instead of a 'type' property, with all other attributes
     * (except 'handle') placed in a 'config' array.
     *
     * Example mapping structure:
     * ```php
     * 'textarea' => [
     *     'default' => 'textarea',
     *     'linked_field' => [
     *         'default' => 'uamsweb_common.bard_simple',
     *         'based_on_attribute_value' => [
     *             'useRichTextEditor' => [
     *                 'true' => 'uamsweb_common.bard_simple',
     *                 true => 'uamsweb_common.bard_simple',
     *                 '1' => 'uamsweb_common.bard_simple',
     *                 1 => 'uamsweb_common.bard_simple',
     *             ],
     *         ],
     *     ],
     * ],
     * ```
     *
     * This would result in a Statamic blueprint field like:
     * ```php
     * [
     *     'handle' => 'test_field_123',
     *     'field' => 'uamsweb_common.bard_simple',
     *     'config' => [
     *         'display' => 'Test Field',
     *         'validate' => ['required'],
     *     ],
     * ]
     * ```
     *
     * @param  array<int|string, mixed>  $mappingArray  The mapping array (type => mapping config)
     * @param  string|array<int|string, mixed>  $fieldOrType  The field type string or full field array
     * @return ?string The linked field handle or null if not found
     */
    public static function getLinkedFieldFromMapping(
        array $mappingArray,
        string|array $fieldOrType
    ): ?string {
        if (\is_string($fieldOrType)) {
            $type = $fieldOrType;
            $field = [];
        } else { // Otherwise
            $type = self::stringValue($fieldOrType['type'] ?? null);
            $field = $fieldOrType;
        }

        if (
            $type === null
            || $type === ''
            || ! self::arrayKeyExists($type, $mappingArray)
        ) {
            return null;
        }

        $mapping = self::mappingValue(
            $mappingArray,
            $type
        );

        if (
            ! \is_array($mapping)
            || ! isset($mapping['linked_field'])
        ) {
            return null;
        }

        $linkedFieldConfig = self::asArray($mapping['linked_field']);

        // Parse field_options JSON if it exists to make attributes available for based_on_other_attribute_value
        $parsedField = $field;
        if (
            isset($field['field_options'])
            && \is_string($field['field_options'])
        ) {
            $fieldOptions = \json_decode(
                $field['field_options'],
                true
            );
            if (\is_array($fieldOptions)) {
                // Merge parsed field_options with the original field data
                $parsedField = \array_merge(
                    $field,
                    $fieldOptions
                );
            }
        }

        // Handle based_on_other_attribute_value logic
        $basedOnOtherConfig = self::asArray(
            self::mappingValue($linkedFieldConfig, 'based_on_other_attribute_value')
        );
        if ($basedOnOtherConfig !== []) {
            if (
                self::arrayKeyExists(
                    'default',
                    $basedOnOtherConfig
                )
                || self::arrayKeyExists(
                    'specific',
                    $basedOnOtherConfig
                )
            ) {
                $resolvedLinkedField = self::resolveStringMappingFromConditionalConfig(
                    self::resolveStructuredConditionalConfig(
                        $basedOnOtherConfig,
                        $type
                    ),
                    $parsedField
                );
                if ($resolvedLinkedField !== null) {
                    return $resolvedLinkedField;
                }
            } else {
                $resolvedLinkedField = self::resolveStringMappingFromConditionalConfig(
                    $basedOnOtherConfig,
                    $parsedField
                );
                if ($resolvedLinkedField !== null) {
                    return $resolvedLinkedField;
                }
            }
        }

        // Handle based_on_attribute_value logic
        $basedOnAttributeConfig = self::asArray(
            self::mappingValue($linkedFieldConfig, 'based_on_attribute_value')
        );
        if ($basedOnAttributeConfig !== []) {
            if (
                self::arrayKeyExists(
                    'default',
                    $basedOnAttributeConfig
                )
                || self::arrayKeyExists(
                    'specific',
                    $basedOnAttributeConfig
                )
            ) {
                $resolvedLinkedField = self::resolveStringMappingFromConditionalConfig(
                    self::resolveStructuredConditionalConfig(
                        $basedOnAttributeConfig,
                        $type
                    ),
                    $parsedField
                );
                if ($resolvedLinkedField !== null) {
                    return $resolvedLinkedField;
                }
            } else {
                $resolvedLinkedField = self::resolveStringMappingFromConditionalConfig(
                    $basedOnAttributeConfig,
                    $parsedField
                );
                if ($resolvedLinkedField !== null) {
                    return $resolvedLinkedField;
                }
            }
        }

        return self::resolveOptionalStringMappingValue(
            self::mappingValue($linkedFieldConfig, 'default')
        );
    }

    /**
     * Get the set_attributes configuration for a fieldtype mapping.
     *
     * @param  array<int|string, mixed>  $mappingArray  The mapping array (type => mapping config)
     * @param  string|array<int|string, mixed>  $fieldOrType  The field type string or full field array
     * @param  ?string  $siteHandle  The site handle for placeholder replacement
     * @return array<int|string, mixed>|null The set_attributes configuration or null if not found
     */
    public static function getSetAttributesFromMapping(
        array $mappingArray,
        string|array $fieldOrType,
        ?string $siteHandle = null
    ): ?array {
        if (\is_string($fieldOrType)) {
            $type = $fieldOrType;
            $field = [];
        } else { // Otherwise
            $type = self::stringValue($fieldOrType['type'] ?? null);
            $field = $fieldOrType;
        }

        if (
            $type === null
            || $type === ''
            || ! self::arrayKeyExists($type, $mappingArray)
        ) {
            return null;
        }

        $mapping = self::mappingValue(
            $mappingArray,
            $type
        );

        if (
            ! \is_array($mapping)
            || ! isset($mapping['set_attributes'])
        ) {
            return null;
        }

        $setAttributes = self::asArray($mapping['set_attributes']);

        // Start with default attributes
        $attributes = self::asArray(
            self::mappingValue($setAttributes, 'default')
        );

        // Merge with specific attributes if the field has a specific fieldtype handle
        $specificAttributes = self::asArray(
            self::mappingValue($setAttributes, 'specific')
        );
        if ($specificAttributes !== []) {
            $fieldHandle = self::stringValue(
                $field['field_key'] ?? $field['id'] ?? null
            );
            $fieldSpecificAttributes = $fieldHandle !== null
                ? self::asArray(
                    self::mappingValue(
                        $specificAttributes,
                        $fieldHandle
                    )
                )
                : [];
            if ($fieldSpecificAttributes !== []) {
                $attributes = \array_merge(
                    $attributes,
                    $fieldSpecificAttributes
                );
            }
        }

        // Process placeholders in the attributes
        if ($attributes !== [] && $siteHandle !== null) {
            $processedAttributes = [];
            foreach ($attributes as $key => $value) {
                // Process placeholders in the key
                $processedKey = self::replacePlaceholders(
                    self::normalizeMixedToString($key),
                    null,
                    null,
                    null,
                    $siteHandle
                );

                // Process placeholders in the value if it's a string
                if (\is_string($value)) {
                    $processedValue = self::replacePlaceholders(
                        $value,
                        null,
                        null,
                        null,
                        $siteHandle
                    );
                } else { // Otherwise
                    $processedValue = $value;
                }

                $processedAttributes[$processedKey] = $processedValue;
            }

            $attributes = $processedAttributes;
        }

        return $attributes;
    }

    /**
     * Get the translated preset value for placeholder replacement.
     * This is different from getStatamicPresetValue because it should always
     * return a value for placeholder replacement, even when the actual preset
     * attribute should be excluded.
     *
     * @param  array<int|string, mixed>  $parentField  The parent field data
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration
     * @return ?string The translated preset value for placeholder replacement
     */
    protected static function getPresetValueForPlaceholder(
        array $parentField,
        array $mappingConfig
    ): ?string {
        // Iterate through the mapping config to find the attribute mapped to 'preset'
        foreach ($mappingConfig as $attr => $config) {
            $configArray = self::mappingConfig($config);
            $statamicAttr = self::mappingValue($configArray, 'statamic_attribute');
            $isPresetAttribute = false;

            // Handle simple string statamic_attribute
            if ($statamicAttr === 'preset') {
                $isPresetAttribute = true;
            } elseif (\is_array($statamicAttr)) {
                $statamicAttrArray = self::asArray($statamicAttr);
                if (self::stringValue(self::mappingValue($statamicAttrArray, 'default')) === 'preset') {
                    $isPresetAttribute = true;
                }
            }

            if ($isPresetAttribute) {
                $normalizedAttr = self::normalizeArrayKey($attr);
                if ($normalizedAttr === null) {
                    continue;
                }

                // Retrieve the value from the parent field for this attribute
                $value = self::mappingValue(
                    $parentField,
                    $normalizedAttr
                );

                // If the value is not set, return null
                if ($value === null) {
                    return null;
                }

                // Check for placeholder_translation first (takes precedence)
                $placeholderTranslation = self::asArray(
                    self::mappingValue($configArray, 'placeholder_translation')
                );
                if ($placeholderTranslation !== []) {
                    $translated = self::mappingValue(
                        $placeholderTranslation,
                        $value
                    );

                    return self::stringValue($translated) ?? self::stringValue($value);
                }

                // Fallback to value_translation if no placeholder_translation is defined
                $valueTranslation = self::asArray(
                    self::mappingValue($configArray, 'value_translation')
                );
                if ($valueTranslation !== []) {
                    $translated = self::mappingValue(
                        $valueTranslation,
                        $value
                    );

                    return self::stringValue($translated) ?? self::stringValue($value);
                }

                // Return the raw value if no translation is needed
                return self::stringValue($value);
            }
        }

        // No attribute mapped to 'preset' found in the mapping config
        return null;
    }

    /**
     * Replace placeholders in attribute names with actual values.
     *
     * This method handles the replacement of various placeholders in attribute names:
     *   - '*' is replaced with the provided handle
     *   - '[#preset]' is replaced with the provided preset value
     *   - '[#subfield]' is replaced with the provided subfield handle
     *   - '[#site_handle]' is replaced with the provided site
     *     handle
     *
     * @param  string  $attributeName  The attribute name with placeholders
     * @param  ?string  $handle  The handle to substitute for '*' placeholder
     * @param  ?string  $subfieldHandle  The subfield handle to substitute for '[#subfield]' placeholder
     * @param  ?string  $preset  The preset value to substitute for '[#preset]' placeholder
     * @param  ?string  $siteHandle  The site handle to substitute for '[#site_handle]'
     *                               placeholders
     * @return string The attribute name with placeholders replaced
     */
    protected static function replacePlaceholders(
        string $attributeName,
        ?string $handle = null,
        ?string $subfieldHandle = null,
        ?string $preset = null,
        ?string $siteHandle = null
    ): string {
        // Replace '*' with the handle (for simple subfield handle replacement)
        if ($handle !== null) {
            $attributeName = \str_replace(
                '*',
                $handle,
                $attributeName
            );
        }

        // Replace '[#preset]' first, then '[#subfield]' to handle nested placeholders
        // This ensures that if subfieldHandle contains [#preset], it gets resolved before
        // being used as a replacement for [#subfield]
        if ($preset !== null) {
            $attributeName = \str_replace(
                '[#preset]',
                $preset,
                $attributeName
            );
        }

        // Replace '[#subfield]' with the actual subfield handle (after preset replacement)
        if ($subfieldHandle !== null) {
            // First resolve any placeholders in the subfieldHandle itself
            $resolvedSubfieldHandle = $subfieldHandle;
            if ($preset !== null) {
                $resolvedSubfieldHandle = \str_replace(
                    '[#preset]',
                    $preset,
                    $resolvedSubfieldHandle
                );
            }

            $attributeName = \str_replace(
                '[#subfield]',
                $resolvedSubfieldHandle,
                $attributeName
            );
        }

        // Replace '[#site_handle]' with the site handle
        if ($siteHandle !== null) {
            return \str_replace(
                '[#site_handle]',
                $siteHandle,
                $attributeName
            );
        }

        return $attributeName;
    }

    /**
     * Recursively replace site handle placeholders in mapped attribute structures.
     *
     * @param  mixed  $value  Mapped value or nested array
     * @param  string  $siteHandle  Site handle for [#site_handle]
     * @return mixed Structure with placeholders resolved where applicable
     */
    protected static function applySiteHandlePlaceholderToMappedStructure(
        mixed $value,
        string $siteHandle
    ): mixed {
        if (\is_string($value)) {
            return self::replacePlaceholders(
                $value,
                null,
                null,
                null,
                $siteHandle
            );
        }

        if (! \is_array($value)) {
            return $value;
        }

        $result = [];
        foreach ($value as $key => $nested) {
            $resolvedKey = \is_string($key)
                ? self::replacePlaceholders(
                    $key,
                    null,
                    null,
                    null,
                    $siteHandle
                )
                : $key;
            $result[$resolvedKey] = self::applySiteHandlePlaceholderToMappedStructure(
                $nested,
                $siteHandle
            );
        }

        return $result;
    }

    /**
     * Process subfield attribute mappings to replace placeholders and handle
     * conditional logic.
     *
     * This method processes attribute mappings for subfields, supporting:
     *   - Simple string mappings with placeholder replacement
     *   - based_on_other_attribute_value patterns (e.g., based on addressType)
     *   - based_on_attribute_value patterns (e.g., based on current attribute value)
     *   - default values in array configurations
     *
     * @param  array<int|string, mixed>  $attributeMappings  The attribute mappings to process
     * @param  array<int|string, mixed>  $parentField  The parent field data for context
     * @param  string  $subfieldHandle  The subfield handle
     * @param  array<int|string, mixed>  $subfieldData  The subfield data
     * @param  ?string  $preset  The preset value
     * @return array<int|string, mixed> The processed attribute mappings
     */
    protected static function processSubfieldAttributeMappings(
        array $attributeMappings,
        array $parentField,
        string $subfieldHandle,
        array $subfieldData = [],
        ?string $preset = null
    ): array {
        $processedMappings = [];

        foreach ($attributeMappings as $attrName => $attrConfig) {
            $attrConfigArray = self::mappingConfig($attrConfig);
            $statamicAttribute = self::mappingValue(
                $attrConfigArray,
                'statamic_attribute'
            );
            if ($statamicAttribute !== null) {
                $processedConfig = $attrConfigArray;

                // Use helper method to process the statamic attribute
                $result = self::processSubfieldStatamicAttribute(
                    $statamicAttribute,
                    $attrName,
                    $parentField,
                    $subfieldHandle,
                    $subfieldData,
                    $preset
                );

                if ($result !== null) {
                    [$processedAttribute, $matchFound] = $result;
                    if ($matchFound === true) {
                        $processedConfig['statamic_attribute'] = $processedAttribute;
                        $processedMappings[$attrName] = $processedConfig;
                    } else { // Otherwise
                        // Keep the original config if no match was found
                        $processedMappings[$attrName] = $attrConfigArray;
                    }
                } else { // Otherwise
                    // Keep the original config if no processing was needed
                    $processedMappings[$attrName] = $attrConfigArray;
                }
            } else { // Otherwise
                // Keep the original config if no statamic_attribute to process
                $processedMappings[$attrName] = $attrConfigArray;
            }
        }

        return $processedMappings;
    }

    /**
     * Process a single statamic attribute configuration for subfield mapping.
     *
     * This helper method DRYs out the logic for processing statamic_attribute
     * configurations in subfield mappings, handling all the conditional patterns.
     *
     * @param  mixed  $statamicAttr  The statamic attribute configuration
     * @param  string  $attrName  The attribute name
     * @param  array<int|string, mixed>  $parentField  The parent field data
     * @param  string  $subfieldHandle  The subfield handle
     * @param  array<int|string, mixed>  $subfieldData  The subfield data for attribute value lookup
     * @param  ?string  $preset  The preset value
     * @return array<int|string, mixed>|null Returns [processed_attribute, match_found] or null if no processing needed
     */
    protected static function processSubfieldStatamicAttribute(
        mixed $statamicAttr,
        int|string|null $attrName,
        array $parentField,
        string $subfieldHandle,
        array $subfieldData = [],
        ?string $preset = null
    ): ?array {
        $matchFound = false;

        // Only process statamic_attribute if it's set and has a value
        if (
            $statamicAttr !== null
            && $statamicAttr !== ''
        ) {
            // Handle array-based configurations
            if (\is_array($statamicAttr)) {
                $statamicAttrArray = self::asArray($statamicAttr);

                // Handle based_on_other_attribute_value logic
                $basedOnOtherConfig = self::asArray(
                    self::mappingValue(
                        $statamicAttrArray,
                        'based_on_other_attribute_value'
                    )
                );
                if ($basedOnOtherConfig !== []) {
                    $addressTypeConfig = self::asArray(
                        self::mappingValue(
                            $basedOnOtherConfig,
                            'addressType'
                        )
                    );
                    $addressType = self::stringValue(
                        self::mappingValue(
                            $parentField,
                            'addressType'
                        )
                    );
                    $resolvedAttribute = $addressType !== null
                        ? self::mappingValue(
                            $addressTypeConfig,
                            $addressType
                        )
                        : null;

                    // If we resolved an attribute, use it
                    if ($resolvedAttribute !== null) {
                        return [
                            self::replacePlaceholders(
                                self::normalizeMixedToString($resolvedAttribute),
                                null,
                                $subfieldHandle,
                                $preset
                            ),
                            true,
                        ];
                    }
                }

                // Handle based_on_attribute_value logic
                $basedOnConfig = self::mappingValue(
                    $statamicAttrArray,
                    'based_on_attribute_value'
                );
                if ($basedOnConfig !== null) {
                    $normalizedAttrName = self::normalizeArrayKey($attrName);
                    $attrValue = $normalizedAttrName !== null
                        ? self::mappingValue(
                            $subfieldData,
                            $normalizedAttrName
                        )
                        : null;
                    $basedOnConfigArray = \is_array($basedOnConfig)
                        ? $basedOnConfig
                        : self::normalizeMixedToString($basedOnConfig);

                    // Use existing helper method to process based_on_attribute_value patterns
                    // This leverages the same logic used in the main field mapping for consistency
                    $result = self::processBasedOnAttributeValue(
                        $basedOnConfigArray,
                        null,
                        self::normalizeMixedToString($attrName),
                        $attrValue,
                        $parentField
                    );

                    if ($result !== null) {
                        [$resolvedAttribute, $matchFound] = $result;
                        if ($matchFound) {
                            // If resolvedAttribute is null, this means the attribute should be excluded
                            if ($resolvedAttribute === null) {
                                return null; // Exclude this attribute
                            }

                            return [
                                self::replacePlaceholders(
                                    self::normalizeMixedToString($resolvedAttribute),
                                    null,
                                    $subfieldHandle,
                                    $preset
                                ),
                                $matchFound,
                            ];
                        }
                    }

                    // If based_on_attribute_value didn't find a match, fall back to default
                    // This handles the case where based_on_attribute_value only has exclusion mappings
                    $defaultAttribute = self::mappingValue(
                        $statamicAttrArray,
                        'default'
                    );
                    if ($defaultAttribute !== null) {
                        return [
                            self::replacePlaceholders(
                                self::normalizeMixedToString($defaultAttribute),
                                null,
                                $subfieldHandle,
                                $preset
                            ),
                            true,
                        ];
                    }
                }

                // Handle default value (if not already processed)
                $defaultAttribute = self::mappingValue(
                    $statamicAttrArray,
                    'default'
                );
                if ($defaultAttribute !== null) {
                    return [
                        self::replacePlaceholders(
                            self::normalizeMixedToString($defaultAttribute),
                            null,
                            $subfieldHandle,
                            $preset
                        ),
                        true,
                    ];
                }
            }

            // Handle simple string statamic_attribute (if not already processed)
            if (\is_string($statamicAttr)) {
                return [
                    self::replacePlaceholders(
                        $statamicAttr,
                        null,
                        $subfieldHandle,
                        $preset
                    ),
                    true,
                ];
            }
        }

        return null;
    }

    /**
     * Maps Gravity Forms subfield attributes to Statamic field attributes.
     *
     * This method processes subfield data (like address field inputs) and maps
     * them to Statamic field configuration attributes.
     *
     * @param  string  $fieldType  The Gravity Forms field type
     * @param  array<int|string, mixed>  $subfieldData  The subfield data (e.g., from inputs array)
     * @param  array<int|string, mixed>  $parentField  The parent field data for context
     * @param  ?string  $pluginName  The plugin name for fieldtype resolution
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for context
     * @return array<int|string, mixed> The mapped Statamic subfield attributes
     */
    public static function mapSubfieldAttributes(
        string $fieldType,
        array $subfieldData,
        array $parentField = [],
        ?string $pluginName = null,
        array $allFields = []
    ): array {
        // Return early if subfield mapping is not defined in the implementing class
        if (
            ! property_exists(
                static::class,
                'subfieldMapping'
            )
        ) {
            return [];
        }

        // Extract the subfield label from the input data for mapping lookup
        $label = self::stringValue(
            self::mappingValue(
                $subfieldData,
                'label'
            )
        );

        // Return early if label is missing from the subfield data
        if ($label === null || $label === '') {
            return [];
        }

        $subfieldMappingConfig = static::getSubfieldMapping();

        // Build the attribute mappings hierarchy from general to specific
        $attributeMappings = self::asArray(
            self::mappingValue(
                $subfieldMappingConfig,
                'attributes'
            )
        );
        $fieldtypesConfig = self::asArray(
            self::mappingValue(
                $subfieldMappingConfig,
                'fieldtypes'
            )
        );
        $fieldtypeConfig = self::asArray(
            self::mappingValue(
                $fieldtypesConfig,
                $fieldType
            )
        );

        // Merge with fieldtype-specific attributes (overrides general)
        if (
            self::arrayKeyExists(
                'attributes',
                $fieldtypeConfig
            )
        ) {
            $attributeMappings = \array_merge(
                $attributeMappings,
                self::asArray(
                    self::mappingValue(
                        $fieldtypeConfig,
                        'attributes'
                    )
                )
            );
        }

        // Merge with subfield-specific attributes (overrides fieldtype)
        $subfieldsConfig = self::asArray(
            self::mappingValue(
                $fieldtypeConfig,
                'subfields'
            )
        );
        $subfieldConfig = self::asArray(
            self::mappingValue(
                $subfieldsConfig,
                $label
            )
        );
        if (
            self::arrayKeyExists(
                'attributes',
                $subfieldConfig
            )
        ) {
            $attributeMappings = \array_merge(
                $attributeMappings,
                self::asArray(
                    self::mappingValue(
                        $subfieldConfig,
                        'attributes'
                    )
                )
            );
        }

        // Get the canonical handle from the mapping array for this subfield
        $mappingHandle = self::stringValue(
            self::mappingValue(
                $subfieldConfig,
                'handle'
            )
        );

        // Will hold the resolved Statamic handle for config keys
        $statamicHandle = null;

        // Get the mapping config for the current fieldtype
        // Use the field attribute mapping (not subfield mapping) for preset translation
        $fieldAttributeMapping = property_exists(
            static::class,
            'fieldAttributeMapping'
        )
            ? static::$fieldAttributeMapping
            : [];
        $statamicPreset = self::getStatamicPresetValue(
            $parentField,
            $fieldAttributeMapping
        );

        // Get the preset value for placeholder replacement (different from actual preset attribute)
        $presetForPlaceholder = self::getPresetValueForPlaceholder(
            $parentField,
            $fieldAttributeMapping
        );

        // Fallback to old logic if not found
        $preset = $statamicPreset ?? self::stringValue(
            self::mappingValue(
                $parentField,
                'preset'
            )
        ) ?? self::stringValue(
            self::mappingValue(
                $parentField,
                'addressType'
            )
        );
        $presetForPlaceholderString = self::stringValue($presetForPlaceholder);

        // Attempt to resolve the Statamic fieldtype class and use its helper to get the correct handle
        if ($pluginName !== null && $pluginName !== '') {
            // Resolve the Statamic fieldtype class for this field
            $fieldtypeClass = static::resolveStatamicFieldtypeClass(
                $fieldType,
                $pluginName
            );

            // If the class exists, instantiate and configure it
            if (
                $fieldtypeClass !== null
                && $fieldtypeClass !== ''
                && class_exists($fieldtypeClass)
            ) {
                $fieldtypeInstance = new $fieldtypeClass;

                // Set the preset config if available
                $config = [];
                if ($preset !== null && $preset !== '') {
                    $config['preset'] = $preset;
                }

                if (
                    method_exists(
                        $fieldtypeInstance,
                        'setConfig'
                    )
                ) {
                    $fieldtypeInstance->setConfig($config);
                }

                // Use the helper to get the preset-specific Statamic handle
                if (
                    method_exists(
                        $fieldtypeInstance,
                        'getPresetSpecificSubfieldHandle'
                    )
                    && $mappingHandle !== null
                ) {
                    $statamicHandle = self::stringValue(
                        $fieldtypeInstance->getPresetSpecificSubfieldHandle(
                            $mappingHandle,
                            $preset
                        )
                    );
                }
            }
        }

        // Fallback to the mapping handle if no Statamic handle was resolved
        if ($statamicHandle === null || $statamicHandle === '') {
            $statamicHandle = $mappingHandle;
        }

        $statamicHandle = self::stringValue($statamicHandle);

        // Return early if no handle or no attribute mappings are available
        if (
            $statamicHandle === null
            || $statamicHandle === ''
            || $attributeMappings === []
        ) {
            return [];
        }

        // Process the attribute mappings to replace placeholders
        $processedMappings = self::processSubfieldAttributeMappings(
            $attributeMappings,
            $parentField,
            $statamicHandle,
            $subfieldData,
            $presetForPlaceholderString
        );

        // Map the subfield attributes using the processed mappings
        // Combine subfield data with parent field data so that based_on_other_attribute_value can access both
        $combinedData = \array_merge(
            $subfieldData,
            $parentField
        );

        // Process attributes that exist in the source data
        $mapped = [];

        // First, process attributes from subfield data
        foreach ($subfieldData as $attrName => $attrValue) {
            $result = self::processAttributeIfValid(
                $attrName,
                $attrValue,
                $processedMappings,
                $combinedData,
                [],
                $statamicHandle,
                $presetForPlaceholderString
            );
            if ($result !== null) {
                $mapped = \array_merge(
                    $mapped,
                    $result
                );
            }
        }

        // Then, process attributes from parent field that weren't already processed
        foreach ($parentField as $attrName => $attrValue) {
            $result = self::processAttributeIfValid(
                $attrName,
                $attrValue,
                $processedMappings,
                $combinedData,
                $subfieldData,
                $statamicHandle,
                $presetForPlaceholderString
            );
            if ($result !== null) {
                $mapped = \array_merge(
                    $mapped,
                    $result
                );
            }
        }

        // Post-process the mapped result to replace placeholders in attribute names
        $postProcessPreset = $presetForPlaceholderString ?? self::stringValue(
            self::mappingValue(
                $parentField,
                'addressType'
            )
        );

        // Return the mapped subfield attributes
        return self::postProcessMappedAttributes(
            $mapped,
            $statamicHandle,
            $postProcessPreset
        );
    }

    /**
     * Add warnings for unmapped subfields and subfield attributes in a field using
     * the provided subfield mapping.
     *
     * @param  array<int|string, mixed>  $field  The field data (must include 'inputs' if subfields exist)
     * @param  array<int|string, mixed>  $subfieldMapping  The subfield mapping array (from the mapping class)
     * @param  array<int|string, mixed>  $excludeFieldtypes  An optional array of field types to exclude from warnings
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     */
    protected static function addUnmappedSubfieldWarningsToField(
        array $field,
        array $subfieldMapping,
        array $excludeFieldtypes = [],
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): void {
        // Get the field type for mapping lookup
        $fieldType = self::stringValue(
            self::mappingValue(
                $field,
                'type'
            )
        ) ?? 'unknown';

        // If this fieldtype is in the exclusion list, skip warning logic
        if (
            \in_array(
                $fieldType,
                $excludeFieldtypes,
                true
            )
        ) {
            return;
        }

        // Only add unmapped subfield warnings for mapped fieldtypes
        $mappingArray = static::getMapping();
        $fieldtypeMapping = self::asArray(
            self::mappingValue(
                $mappingArray,
                $fieldType
            )
        );
        $isMapped = $fieldType !== '' && self::isFieldtypeMapped($fieldtypeMapping);
        if (! $isMapped) {
            return;
        }

        $fieldInputs = self::asArray(
            self::mappingValue(
                $field,
                'inputs'
            )
        );

        // Return early if there are no subfields to check
        if ($fieldInputs === []) {
            return;
        }

        $subfieldMappingConfig = self::mappingConfig($subfieldMapping);
        $fieldtypesConfig = self::asArray(
            self::mappingValue(
                $subfieldMappingConfig,
                'fieldtypes'
            )
        );
        $fieldtypeConfig = self::asArray(
            self::mappingValue(
                $fieldtypesConfig,
                $fieldType
            )
        );

        // Get the subfield mapping definitions for this field type
        $mappedSubfields = self::asArray(
            self::mappingValue(
                $fieldtypeConfig,
                'subfields'
            )
        );

        // Get the general attribute mappings for all subfields
        $generalAttributes = self::asArray(
            self::mappingValue(
                $subfieldMappingConfig,
                'attributes'
            )
        );

        // Get the fieldtype-specific attribute mappings for all subfields of this type
        $fieldtypeAttributes = self::asArray(
            self::mappingValue(
                $fieldtypeConfig,
                'attributes'
            )
        );

        // Iterate through each subfield (input) in the field
        foreach ($fieldInputs as $input) {
            $inputData = self::asArray($input);

            // Extract the subfield label for mapping lookup
            $label = self::stringValue(
                self::mappingValue(
                    $inputData,
                    'label'
                )
            );

            // Skip subfields without a label
            if ($label === null || $label === '') {
                continue;
            }

            // Check if subfield label is mapped in the subfield mapping
            if (
                ! self::arrayKeyExists(
                    $label,
                    $mappedSubfields
                )
            ) {
                // Add a warning for an unmapped subfield label
                ImportWarningManager::addWarning(
                    'forms',
                    'unmapped_subfields',
                    [
                        'form_title' => $formTitle,
                        'field_label' => $fieldLabel,
                        'form_id' => $formId,
                        'subfield_label' => $label,
                        'field_type' => $fieldType,
                    ]
                );

                // No need to check attributes if subfield itself is unmapped
                continue;
            }

            // Get the subfield-specific attribute mappings for this subfield
            $subfieldLabelConfig = self::asArray(
                self::mappingValue(
                    $mappedSubfields,
                    $label
                )
            );
            $subfieldAttributes = self::asArray(
                self::mappingValue(
                    $subfieldLabelConfig,
                    'attributes'
                )
            );

            // Iterate through each attribute in the subfield input
            foreach ($inputData as $attrName => $attrValue) {
                $normalizedAttrName = self::normalizeArrayKey($attrName);
                if ($normalizedAttrName === null) {
                    continue;
                }

                // Skip label and id, which are always present and not mapped
                if (
                    \in_array(
                        $normalizedAttrName,
                        ['label', 'id'],
                        true
                    )
                ) {
                    continue;
                }

                // Check if attribute is mapped in any of the three levels: subfield, fieldtype, or general
                $isMapped = self::arrayKeyExists(
                    $normalizedAttrName,
                    $subfieldAttributes
                )
                    || self::arrayKeyExists(
                        $normalizedAttrName,
                        $fieldtypeAttributes
                    )
                    || self::arrayKeyExists(
                        $normalizedAttrName,
                        $generalAttributes
                    );

                // Add a warning for any unmapped subfield attribute
                if (! $isMapped) {
                    ImportWarningManager::addWarning(
                        'forms',
                        'unmapped_subfields',
                        [
                            'form_title' => $formTitle,
                            'field_label' => $fieldLabel,
                            'form_id' => $formId,
                            'subfield_label' => $label,
                            'attribute_name' => $normalizedAttrName,
                            'attribute_value' => $attrValue,
                            'field_type' => $fieldType,
                        ]
                    );
                }
            }
        }
    }

    /**
     * Get the fieldtype mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The mapping array for fieldtypes
     */
    public static function getMapping(): array
    {
        return static::$mapping;
    }

    /**
     * Resolve field-attribute options mapping when defined on the implementing class.
     *
     * @return array<int|string, mixed> The options mapping array, or empty when
     *                                  undefined
     */
    protected static function resolveFieldAttributeOptionsMapping(): array
    {
        if (
            ! property_exists(
                static::class,
                'fieldAttributeOptionsMapping'
            )
        ) {
            return [];
        }

        $classVariables = \get_class_vars(static::class);

        return self::mappingConfig(
            $classVariables['fieldAttributeOptionsMapping'] ?? []
        );
    }

    /**
     * Get the subfield mapping array for the implementing class.
     *
     * @return array<int|string, mixed> The subfield mapping array
     */
    public static function getSubfieldMapping(): array
    {
        if (
            ! property_exists(
                static::class,
                'subfieldMapping'
            )
        ) {
            return [];
        }

        return static::$subfieldMapping;
    }

    /**
     * Gets the appropriate Statamic conditional attribute based on action and logic
     * type.
     *
     * This method maps WordPress form plugin action and logic types to Statamic
     * conditional attributes. It handles the conversion of:
     *   - actionType: "show" | "hide" → if/unless attributes
     *   - logicType: "all" | "any" → if/if_any/unless/unless_any attributes
     *
     * @param  string  $actionType  The WordPress form plugin action type ("show" or "hide")
     * @param  string  $logicType  The WordPress form plugin logic type ("all" or "any")
     * @return ?string The Statamic attribute name or null if not supported
     */
    protected static function getStatamicConditionalAttribute(
        string $actionType,
        string $logicType
    ): ?string {
        // Map WordPress form plugin action and logic types to Statamic conditional attributes
        return match ($actionType) {
            'show' => $logicType === 'any' ? 'if_any' : 'if',
            'hide' => $logicType === 'any' ? 'unless_any' : 'unless',
            default => null,
        };
    }

    /**
     * Maps conditional logic to Statamic conditional logic attributes.
     *
     * This is a template method that delegates to the implementing class's specific
     * conditional logic mapping implementation.
     *
     * @param  mixed  $conditionalLogicData  The conditional logic data (format depends on implementing class)
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @return array<int|string, mixed> Statamic conditional logic attributes
     */
    protected static function mapConditionalLogic(
        $conditionalLogicData,
        array $allFields = []
    ): array {
        // This method should be overridden by implementing classes
        // that support conditional logic mapping
        return [];
    }

    /**
     * Gets the field handle for a given field ID.
     *
     * This method searches through all form fields to find a matching field ID
     * and converts the field's name/label to a Statamic handle format.
     *
     * @param  string  $fieldId  The field ID to search for
     * @param  array<int|string, mixed>  $allFields  Array of all form fields
     * @return ?string The field handle or null if not found
     */
    protected static function getFieldHandleById(
        string $fieldId,
        array $allFields = []
    ): ?string {
        // Search through all form fields to find matching field ID
        foreach ($allFields as $field) {
            $fieldData = self::asArray($field);
            $candidateFieldId = self::mappingValue($fieldData, 'id');

            // Check if field ID matches (convert to string for comparison)
            if (
                $candidateFieldId !== null
                && self::normalizeMixedToString($candidateFieldId) === $fieldId
            ) {
                // Get the field name/label using the implementing class's method
                $fieldName = static::getFieldNameFromField($fieldData);

                if ($fieldName !== null && $fieldName !== '') {
                    // Convert the name to a Statamic handle format using the implementing class's method
                    return static::convertFieldNameToHandle(
                        $fieldName,
                        $fieldId
                    );
                }
            }
        }

        // Return null if field ID not found in any field
        return null;
    }

    /**
     * Gets the field name/label from a field array.
     *
     * This method should be overridden by implementing classes to extract
     * the appropriate field name or label from their specific field structure.
     *
     * @param  array<int|string, mixed>  $field  The field array
     * @return ?string The field name/label or null if not found
     */
    protected static function getFieldNameFromField(
        array $field
    ): ?string {
        // Default implementation - should be overridden by implementing classes
        return null;
    }

    /**
     * Converts a field name/label to a Statamic handle format with field ID suffix.
     *
     * This method provides the common logic for converting field names to
     * Statamic handles. It converts the name to lowercase, replaces
     * non-alphanumeric characters with underscores, collapses multiple
     * underscores, and appends the field ID for uniqueness.
     *
     * @param  string  $fieldName  The field name/label
     * @param  string  $fieldId  The field ID to append for uniqueness
     * @return string The Statamic handle
     */
    protected static function convertFieldNameToHandle(
        string $fieldName,
        string $fieldId
    ): string {
        // Convert to lowercase and trim whitespace
        $handle = \strtolower(\trim($fieldName));

        // Replace non-alphanumeric characters with underscores
        $handle = \preg_replace(
            '/[^a-z0-9_]/',
            '_',
            $handle
        ) ?? $handle;

        // Collapse multiple consecutive underscores into single underscore
        $handle = \preg_replace(
            '/_+/',
            '_',
            $handle
        ) ?? $handle;

        // Remove leading and trailing underscores
        $handle = \trim(
            $handle,
            '_'
        );

        // Append field ID to ensure uniqueness
        return $handle.'_'.$fieldId;
    }

    /**
     * Check if a value matches a pattern with wildcards.
     *
     * @param  mixed  $value  The value to check
     * @param  mixed  $pattern  The pattern to match against (supports * wildcards)
     * @return bool True if the value matches the pattern
     */
    protected static function matchesPattern(
        mixed $value,
        mixed $pattern
    ): bool {
        // If value is null, it can't match any pattern
        if ($value === null) {
            return false;
        }

        // Handle boolean values - convert to string for comparison
        if (\is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        if (\is_bool($pattern)) {
            $pattern = $pattern ? 'true' : 'false';
        } elseif (! \is_string($pattern)) {
            $pattern = self::normalizeMixedToString($pattern);
        }

        // Handle array values by converting to JSON string
        if (\is_array($value)) {
            $encodedValue = \json_encode($value);
            if (! \is_string($encodedValue)) {
                return false;
            }

            $value = $encodedValue;
        } elseif (! \is_string($value)) {
            $value = self::normalizeMixedToString($value);
        }

        // Simple wildcard matching - convert pattern to regex
        // Escape regex special characters except for * which we want to treat as wildcard
        $regexPattern = preg_quote(
            $pattern,
            '/'
        );
        // Replace the escaped * with .* for wildcard matching
        $regexPattern = \str_replace(
            '\*',
            '.*',
            $regexPattern
        );
        $regex = "/^{$regexPattern}$/";

        return \preg_match(
            $regex,
            $value
        ) === 1;
    }

    /**
     * Check if a nested attribute value matches a pattern configuration.
     *
     * This method handles complex nested attribute matching for the
     * 'statamic_other_field_value_based_on_nested_attribute_value' functionality.
     *
     * @param  mixed  $attrValue  The attribute value to check (can be array or scalar)
     * @param  array<int|string, mixed>  $mappingConfig  The mapping configuration containing:
     *                                                   - 'nested_attribute': The pattern to match against
     *                                                   - 'numeric_value_equivalent': Whether to treat numeric strings as equivalent
     *                                                   to numbers
     *                                                   - 'case_sensitive_key': Whether key matching should be case-sensitive
     *                                                   - 'case_sensitive_value': Whether value matching should be case-sensitive
     * @return bool True if the attribute value matches the pattern
     */
    protected static function matchesNestedAttributePattern(
        mixed $attrValue,
        array $mappingConfig
    ): bool {
        $nestedAttributeRaw = self::mappingValue(
            $mappingConfig,
            'nested_attribute'
        ) ?? [];
        $nestedAttribute = \is_string($nestedAttributeRaw)
            ? $nestedAttributeRaw
            : self::asArray($nestedAttributeRaw);
        $numericValueEquivalentRaw = self::mappingValue(
            $mappingConfig,
            'numeric_value_equivalent'
        );
        $numericValueEquivalent = \is_bool($numericValueEquivalentRaw)
            ? $numericValueEquivalentRaw
            : true;
        $caseSensitiveKeyRaw = self::mappingValue(
            $mappingConfig,
            'case_sensitive_key'
        );
        $caseSensitiveKey = \is_bool($caseSensitiveKeyRaw) && $caseSensitiveKeyRaw;
        $caseSensitiveValueRaw = self::mappingValue(
            $mappingConfig,
            'case_sensitive_value'
        );
        $caseSensitiveValue = \is_bool($caseSensitiveValueRaw) && $caseSensitiveValueRaw;

        // If nested_attribute is empty, no match
        if ($nestedAttribute === '') {
            return false;
        }

        // Handle case where nested_attribute contains key/value pairs
        if (\is_array($nestedAttribute)
        && self::isAssociativeArray($nestedAttribute)) {
            // If attrValue is also associative array, check if all key/value pairs match
            if (\is_array($attrValue)
            && self::isAssociativeArray($attrValue)) {
                return self::matchesAssociativeArrays(
                    $attrValue,
                    $nestedAttribute,
                    $numericValueEquivalent,
                    $caseSensitiveKey,
                    $caseSensitiveValue
                );
            }

            // If attrValue is also associative array, check if all key/value pairs match
            if (\is_array($attrValue) && ! self::isAssociativeArray($attrValue)) {
                // If attrValue is array of arrays, check if each array contains the key/value pairs
                return self::matchesArrayOfArrays(
                    $attrValue,
                    $nestedAttribute,
                    $numericValueEquivalent,
                    $caseSensitiveKey,
                    $caseSensitiveValue
                );
            }

            // If attrValue is scalar, no match
            return false;
        }

        // Handle case where nested_attribute contains key/value pairs
        if (\is_array($nestedAttribute)) {
            // Handle case where nested_attribute is array of arrays
            // If attrValue is also array of arrays, check positional matching
            if (
                \is_array($attrValue)
                && ! self::isAssociativeArray($attrValue)
            ) {
                return self::matchesArrayOfArraysPositional(
                    $attrValue,
                    $nestedAttribute,
                    $numericValueEquivalent,
                    $caseSensitiveKey,
                    $caseSensitiveValue
                );
            }

            // If attrValue is associative array or scalar, no match
            return false;
        }

        return false;
    }

    /**
     * Check if an array is associative (has string keys).
     *
     * @param  array<int|string, mixed>  $array  The array to check
     * @return bool True if the array is associative
     */
    protected static function isAssociativeArray(
        array $array
    ): bool {
        return \array_keys($array) !== range(
            0,
            \count($array) - 1
        );
    }

    /**
     * Check if two associative arrays match based on the specified criteria.
     *
     * @param  array<int|string, mixed>  $attrValue  The attribute value (associative array)
     * @param  array<int|string, mixed>  $nestedAttribute  The nested attribute pattern (associative array)
     * @param  bool  $numericValueEquivalent  Whether to treat numeric strings as equivalent to numbers
     * @param  bool  $caseSensitiveKey  Whether key matching should be case-sensitive
     * @param  bool  $caseSensitiveValue  Whether value matching should be case-sensitive
     * @return bool True if the arrays match
     */
    protected static function matchesAssociativeArrays(
        array $attrValue,
        array $nestedAttribute,
        bool $numericValueEquivalent,
        bool $caseSensitiveKey,
        bool $caseSensitiveValue
    ): bool {
        foreach ($nestedAttribute as $key => $value) {
            $attrKey = $caseSensitiveKey
                ? (string) $key
                : \strtolower((string) $key);
            $found = false;

            foreach ($attrValue as $attrK => $attrV) {
                $attrKNormalized = $caseSensitiveKey
                    ? (string) $attrK
                    : \strtolower((string) $attrK);
                if ($attrKNormalized === $attrKey) {
                    if (
                        self::valuesMatch(
                            $attrV,
                            $value,
                            $numericValueEquivalent,
                            $caseSensitiveValue
                        )
                    ) {
                        $found = true;
                        break;
                    }
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if an array of arrays contains the specified key/value pairs.
     *
     * @param  array<int|string, mixed>  $attrValue  The attribute value (array of arrays)
     * @param  array<int|string, mixed>  $nestedAttribute  The nested attribute pattern (associative array)
     * @param  bool  $numericValueEquivalent  Whether to treat numeric strings as equivalent to numbers
     * @param  bool  $caseSensitiveKey  Whether key matching should be case-sensitive
     * @param  bool  $caseSensitiveValue  Whether value matching should be case-sensitive
     * @return bool True if the arrays match
     */
    protected static function matchesArrayOfArrays(
        array $attrValue,
        array $nestedAttribute,
        bool $numericValueEquivalent,
        bool $caseSensitiveKey,
        bool $caseSensitiveValue
    ): bool {
        foreach ($nestedAttribute as $key => $value) {
            $attrKey = $caseSensitiveKey
                ? (string) $key
                : \strtolower((string) $key);
            $found = false;

            foreach ($attrValue as $arrayItem) {
                if (\is_array($arrayItem)) {
                    foreach ($arrayItem as $itemKey => $itemValue) {
                        $itemKeyNormalized = $caseSensitiveKey
                            ? (string) $itemKey
                            : \strtolower((string) $itemKey);
                        if ($itemKeyNormalized === $attrKey) {
                            if (
                                self::valuesMatch(
                                    $itemValue,
                                    $value,
                                    $numericValueEquivalent,
                                    $caseSensitiveValue
                                )
                            ) {
                                $found = true;
                                break 2;
                            }
                        }
                    }
                }
            }

            if (! $found) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if two arrays of arrays match positionally.
     *
     * @param  array<int|string, mixed>  $attrValue  The attribute value (array of arrays)
     * @param  array<int|string, mixed>  $nestedAttribute  The nested attribute pattern (array of arrays)
     * @param  bool  $numericValueEquivalent  Whether to treat numeric strings as equivalent to numbers
     * @param  bool  $caseSensitiveKey  Whether key matching should be case-sensitive
     * @param  bool  $caseSensitiveValue  Whether value matching should be case-sensitive
     * @return bool True if the arrays match
     */
    protected static function matchesArrayOfArraysPositional(
        array $attrValue,
        array $nestedAttribute,
        bool $numericValueEquivalent,
        bool $caseSensitiveKey,
        bool $caseSensitiveValue
    ): bool {
        // Must have the same number of arrays
        if (\count($attrValue) !== \count($nestedAttribute)) {
            return false;
        }

        foreach ($nestedAttribute as $index => $patternArray) {
            if (
                ! \is_array($patternArray)
                || ! isset($attrValue[$index])
                || ! \is_array($attrValue[$index])
            ) {
                return false;
            }

            foreach ($patternArray as $key => $value) {
                $attrKey = $caseSensitiveKey ? $key : \strtolower($key);
                $found = false;

                foreach ($attrValue[$index] as $itemKey => $itemValue) {
                    $itemKeyNormalized = $caseSensitiveKey ? $itemKey : \strtolower($itemKey);
                    if ($itemKeyNormalized === $attrKey) {
                        if (
                            self::valuesMatch(
                                $itemValue,
                                $value,
                                $numericValueEquivalent,
                                $caseSensitiveValue
                            )
                        ) {
                            $found = true;
                            break;
                        }
                    }
                }

                if (! $found) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Check if two values match based on the specified criteria.
     *
     * @param  mixed  $attrValue  The attribute value
     * @param  mixed  $patternValue  The pattern value
     * @param  bool  $numericValueEquivalent  Whether to treat numeric strings as equivalent to numbers
     * @param  bool  $caseSensitiveValue  Whether value matching should be case-sensitive
     * @return bool True if the values match
     */
    protected static function valuesMatch(
        mixed $attrValue,
        mixed $patternValue,
        bool $numericValueEquivalent,
        bool $caseSensitiveValue
    ): bool {
        // Handle numeric equivalence
        if ($numericValueEquivalent) {
            // Convert both values to numbers if they are numeric strings
            $attrNumeric = \is_numeric($attrValue) ? (float) $attrValue : $attrValue;
            $patternNumeric = \is_numeric($patternValue) ? (float) $patternValue : $patternValue;

            if (
                \is_numeric($attrValue)
                && \is_numeric($patternValue)
            ) {
                return $attrNumeric === $patternNumeric;
            }
        }

        // Handle case sensitivity for strings
        if (
            \is_string($attrValue)
            && \is_string($patternValue)
        ) {
            return $caseSensitiveValue ? $attrValue === $patternValue : \strtolower($attrValue) === \strtolower($patternValue);
        }

        // Default comparison
        return $attrValue === $patternValue;
    }

    /**
     * Resolve wildcards in a target attribute based on the matched value and pattern.
     *
     * @param  string  $targetAttribute  The target attribute with potential wildcards
     * @param  string  $matchedValue  The value that matched the pattern
     * @param  string  $pattern  The original pattern that was matched
     * @return string The resolved target attribute
     */
    protected static function resolveWildcards(
        string $targetAttribute,
        string $matchedValue,
        string $pattern
    ): string {
        // If the pattern contains a wildcard, extract the matched part
        if (
            \str_contains(
                $pattern,
                '*'
            )
        ) {
            // Convert pattern to regex using the same logic as matchesPattern
            $regexPattern = preg_quote(
                $pattern,
                '/'
            );
            $regexPattern = \str_replace(
                '\*',
                '(.*)',
                $regexPattern
            );
            $regex = '/^'.$regexPattern.'$/';

            // Extract the captured group
            if (
                \preg_match(
                    $regex,
                    $matchedValue,
                    $matches
                ) === 1
            ) {
                $wildcardValue = $matches[1];

                // Replace * in target attribute with the captured value
                return \str_replace(
                    '*',
                    $wildcardValue,
                    $targetAttribute
                );
            }
        }

        return $targetAttribute;
    }

    /**
     * Transform Formidable Forms options JSON to Statamic array format.
     *
     * @param  string  $optionsJson  The options JSON string
     * @param  array<int|string, mixed>  $fieldData  The Formidable Forms field data
     * @param  array<int|string, mixed>  $config  The field configuration
     * @param  ?string  $fieldType  The field type to determine if other_label should be extracted
     * @return array<int|string, mixed> The transformed options array
     */
    protected static function transformOptionsJsonToArray(
        string $optionsJson,
        array $fieldData,
        array $config,
        ?string $fieldType = null
    ): array {
        $options = \json_decode(
            $optionsJson,
            true
        );
        if (! \is_array($options)) {
            return [];
        }

        // Check if separate_value is enabled
        $separateValue = false;

        // First check if the config specifies a separate_value_field_option
        if (
            self::arrayKeyExists(
                'separate_value_field_option',
                $config
            )
        ) {
            $separateValueFieldOption = self::normalizeArrayKeyForAssignment(
                self::mappingValue(
                    $config,
                    'separate_value_field_option'
                )
            );
            if (
                self::arrayKeyExists(
                    $separateValueFieldOption,
                    $fieldData
                )
            ) {
                $separateValue = (bool) self::mappingValue(
                    $fieldData,
                    $separateValueFieldOption
                );
            }
        }

        // Fallback to Formidable Forms specific field_options.separate_value
        if (
            ! $separateValue
            && isset($fieldData['field_options'])
            && \is_string($fieldData['field_options'])
        ) {
            $fieldOptions = \json_decode(
                $fieldData['field_options'],
                true
            );
            if (
                \is_array($fieldOptions)
                && isset($fieldOptions['separate_value'])
            ) {
                $separateValue = (bool) $fieldOptions['separate_value'];
            }
        }

        $transformedOptions = [];
        $otherLabel = null;

        foreach ($options as $key => $option) {
            // Check if this is an other_* key
            if (
                \is_string($key)
                && str_starts_with($key, 'other_')
            ) {
                // Extract the other label from the value
                $otherLabel = \is_string($option) ? $option : null;

                continue; // Skip processing this as a regular option
            }

            if (
                ! \is_array($option)
                || ! isset($option['label'])
            ) {
                continue;
            }

            // Skip empty options (empty label and empty value)
            if (
                $option['label'] === ''
                && $option['value'] === ''
            ) {
                continue;
            }

            // Apply key translation if available
            if (
                isset($config['key_translation'])
                && \is_array($config['key_translation'])
            ) {
                $transformedOption = [];
                foreach (self::mappingConfig($config['key_translation']) as $sourceKey => $targetKey) {
                    $normalizedSourceKey = self::normalizeArrayKey($sourceKey);
                    $normalizedTargetKey = self::normalizeArrayKey($targetKey);
                    if (
                        $normalizedSourceKey === null
                        || $normalizedTargetKey === null
                    ) {
                        continue; // Skip excluded fields
                    }

                    if (
                        self::arrayKeyExists(
                            $normalizedSourceKey,
                            $option
                        )
                    ) {
                        $transformedOption[$normalizedTargetKey] = self::mappingValue(
                            $option,
                            $normalizedSourceKey
                        );
                    }
                }
            } else { // Otherwise
                // Fallback to manual transformation
                $transformedOption = [];

                if (
                    $separateValue
                    && isset($option['value'])
                ) {
                    // Use value as key, label as value
                    $transformedOption['key'] = $option['value'];
                    $transformedOption['value'] = $option['label'];
                } else { // Otherwise
                    // Use label as key, value as null
                    $transformedOption['key'] = $option['label'];
                    $transformedOption['value'] = null;
                }
            }

            // Handle separate_value logic after key translation
            if (
                $separateValue
                && isset($transformedOption['key'])
                && isset($transformedOption['value'])
            ) {
                // Swap key and value when separate_value is enabled
                $tempKey = $transformedOption['key'];
                $transformedOption['key'] = $transformedOption['value'];
                $transformedOption['value'] = $tempKey;
            } elseif (! $separateValue && isset($transformedOption['key'])) {
                // Set value to null when separate_value is disabled
                $transformedOption['value'] = null;
            }

            $transformedOptions[] = $transformedOption;
        }

        // Ensure all options have the expected structure based on fieldtype
        if (
            $transformedOptions !== []
            && isset($config['expected_keys'])
        ) {
            self::normalizeOptionsStructure(
                $transformedOptions,
                [],
                null,
                $fieldType
            );
        }

        // Store the other_label in a static property for later access
        // Only extract other_label for field types that support it (radio and checkboxes)
        if (
            $otherLabel !== null
            && \in_array(
                $fieldType,
                [
                    'radio',
                    'checkbox',
                ],
                true
            )
        ) {
            static::$extractedOtherLabel = \trim($otherLabel);
        }

        return $transformedOptions;
    }

    /**
     * Translate field references in text values to Statamic field handles.
     *
     * This method replaces Formidable Forms field references like [field_name] or
     * [329] with the appropriate Statamic field handles wrapped in square brackets.
     *
     * @param  string  $text  The text containing field references
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for field handle lookup
     * @param  array<int|string, mixed>|null  $currentField  Optional current field data for [field_name] replacement
     * @return string The text with field references translated
     */
    protected static function translateFieldReferences(
        string $text,
        array $allFields = [],
        ?array $currentField = null
    ): string {
        // Pattern to match field references: [field_name] or [329]
        $pattern = '/\[([^\]]+)\]/';

        $translatedText = \preg_replace_callback(
            $pattern,
            function ($matches) use ($allFields, $currentField): string {
                $reference = $matches[1];

                // Check if it's a numeric field ID
                if (\is_numeric($reference)) {
                    // Find the field by ID
                    foreach ($allFields as $field) {
                        $fieldData = self::asArray($field);
                        $candidateFieldId = self::mappingValue($fieldData, 'id');
                        if (
                            $candidateFieldId !== null
                            && self::normalizeMixedToString($candidateFieldId) === $reference
                        ) {
                            // Get the field name/label using the implementing class's method
                            $fieldName = static::getFieldNameFromField($fieldData);
                            if ($fieldName !== null && $fieldName !== '') {
                                // Convert to Statamic handle format and wrap in square brackets
                                return '['.static::convertFieldNameToHandle(
                                    $fieldName,
                                    $reference
                                ).']';
                            }
                        }
                    }
                } else { // Otherwise
                    // Check if it's a field name reference (like [field_name])
                    if ($reference === 'field_name') {
                        // Replace with the current field's name if available
                        if ($currentField !== null && $currentField !== []) {
                            $fieldName = static::getFieldNameFromField($currentField);
                            if ($fieldName !== null && $fieldName !== '') {
                                $fieldId = self::normalizeMixedToString(
                                    self::mappingValue(
                                        $currentField,
                                        'id'
                                    ) ?? 'unknown'
                                );

                                return '['.static::convertFieldNameToHandle(
                                    $fieldName,
                                    $fieldId
                                ).']';
                            }
                        }

                        // Fallback to a generic placeholder if no current field
                        return '[current_field_name]';
                    }

                    // Try to find the field by name/label
                    foreach ($allFields as $field) {
                        $fieldData = self::asArray($field);
                        $fieldName = static::getFieldNameFromField($fieldData);
                        if ($fieldName === $reference) {
                            // Convert to Statamic handle format and wrap in square brackets
                            $fieldId = self::normalizeMixedToString(
                                self::mappingValue(
                                    $fieldData,
                                    'id'
                                ) ?? 'unknown'
                            );

                            return '['.static::convertFieldNameToHandle(
                                $fieldName,
                                $fieldId
                            ).']';
                        }
                    }
                }

                // If no match found, return the original reference
                return $matches[0];
            },
            $text
        );

        return $translatedText ?? $text;
    }

    /**
     * Apply regex substitution patterns to a value.
     *
     * This method applies a series of regex patterns to transform a value.
     * The patterns support variable-length expressions for lookbehind and lookahead
     * assertions, and the replacement values support referencing captured groups
     * using either \1 or $1 syntax.
     *
     * @param  string  $value  The value to transform
     * @param  array<int|string, mixed>  $patterns  Array of regex patterns where keys are patterns and values are replacements
     * @return string The transformed value
     */
    protected static function applyValueSubstitutionRegex(
        string $value,
        array $patterns
    ): string {
        $result = $value;

        foreach ($patterns as $pattern => $replacement) {
            if (
                ! \is_string($pattern)
                || ! \is_string($replacement)
            ) {
                continue;
            }

            // Apply the regex substitution
            $result = \preg_replace(
                $pattern,
                $replacement,
                $result
            ) ?? $result;
        }

        return $result;
    }

    /**
     * Apply mathematical calculations to a value.
     *
     * This method evaluates mathematical expressions, replacing [value] with the
     * original value. The expression is parsed by {@see SafeMathEvaluator}, whose
     * closed grammar covers the four operators, parentheses, decimals and
     * `round()`; anything outside it leaves the value untouched (#2).
     *
     * @param  mixed  $value  The value to apply calculations to
     * @param  string  $expression  The mathematical expression with [value] placeholder
     * @return mixed The calculated result or original value if calculation fails
     */
    protected static function applyValueCalculation(
        mixed $value,
        string $expression
    ): mixed {
        // Only process if the value is numeric
        if (! \is_numeric($value)) {
            return $value;
        }

        // Replace [value] with the actual value
        $expression = \str_replace(
            '[value]',
            (string) $value,
            $expression
        );

        // Parse and evaluate; a null result means the expression is not valid
        // arithmetic, in which case the original value stands
        $result = SafeMathEvaluator::evaluate($expression);

        if ($result === null) {
            return $value;
        }

        // Return a whole result as an int, so a calculation feeding an integer
        // field such as `character_limit` does not write `1198.0`
        return $result === \floor($result)
            && \abs($result) < (float) PHP_INT_MAX
            ? (int) $result
            : $result;
    }

    /**
     * Apply value translation to a given value.
     *
     * This method handles value translation using the value_translation
     * configuration. It supports both simple associative arrays and complex
     * structures with default, specific, and based_on_other_attribute_value
     * configurations.
     *
     * The method now supports two different value_translation structures:
     *
     *   1. **Associative Array Structure** (legacy):
     *      ```php
     *      'value_translation' => [
     *          'default' => [
     *              'foo' => 'bar',
     *              'baz' => 'qux',
     *          ],
     *      ],
     *      ```
     *
     *   2. **Index Array Structure** (new):
     *      ```php
     *      'value_translation' => [
     *          'default' => [
     *              [
     *                  'search' => ['foo', 'bar'],
     *                  'replace' => ['baz', 'qux'],
     *              ],
     *              [
     *                  'search' => 'quux',
     *                  'replace' => 'grault',
     *              ],
     *              [
     *                  'search' => 'waldo',
     *                  'replace' => null, // Excludes the value
     *              ],
     *          ],
     *      ],
     *      ```
     *
     * **Index Array Features:**
     *   - `search` can be a single value or an array of values to match
     *   - `replace` can be a single value, an array, or null (to exclude)
     *   - Supports wildcard patterns in search values (e.g., 'prefix_*')
     *   - Processes rules in order, using the first match found
     *   - Handles array values by translating each element individually
     *   - Supports boolean to string conversion for matching
     *
     * @param  mixed  $value  The value to translate
     * @param  array<int|string, mixed>  $config  Configuration array that may contain 'value_translation' key
     * @param  ?string  $attrName  The attribute name being processed
     * @param  array<int|string, mixed>  $data  The full data array for other attribute lookups
     * @param  ?string  $excludeFieldtype  The fieldtype to exclude
     * @param  array<int|string, mixed>|null  $originalFieldData  Original field data for fallback checks
     * @param  mixed  $statamicAttr  The statamic attribute value
     * @return mixed The translated value
     */
    protected static function applyValueTranslation(
        mixed $value,
        mixed $config,
        int|string|null $attrName = null,
        $data = [],
        $excludeFieldtype = null,
        $originalFieldData = null,
        $statamicAttr = null
    ) {
        $configArray = self::mappingConfig($config);
        $dataArray = self::asArray($data);
        $excludeFieldtypeString = self::stringValue($excludeFieldtype) ?? '';

        // Check if value_translation configuration exists
        if (
            ! self::arrayKeyExists(
                'value_translation',
                $configArray
            )
            || $statamicAttr === null
        ) {
            return $value;
        }

        $translationConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'value_translation'
            )
        );

        // Automatically inject #empty => null mapping if not present
        $translationConfig = self::injectEmptyValueMapping($translationConfig);

        // Check if there's a direct array replacement in value_translation first
        $arrayReplacement = self::checkForArrayReplacement(
            $value,
            $translationConfig
        );
        if ($arrayReplacement !== false) {
            return $arrayReplacement;
        }

        // Handle array values by processing each element individually
        if (\is_array($value)) {
            // Process each element in the array
            $translatedArray = [];
            foreach ($value as $item) {
                $translatedItem = self::applyValueTranslation(
                    $item,
                    $configArray,
                    $attrName,
                    $dataArray,
                    $excludeFieldtype,
                    $originalFieldData,
                    $statamicAttr
                );
                $translatedArray[] = $translatedItem;
            }

            return $translatedArray;
        }

        $translationArray = null;

        // Handle different value_translation structures
        if (
            self::arrayKeyExists(
                'default',
                $translationConfig
            )
            || self::arrayKeyExists(
                'specific',
                $translationConfig
            )
            || self::arrayKeyExists(
                'based_on_other_attribute_value',
                $translationConfig
            )
        ) {

            // Complex structure with 'default', 'specific', 'based_on_other_attribute_value', etc.
            $defaultTranslations = self::asArray(
                self::mappingValue(
                    $translationConfig,
                    'default'
                )
            );
            $specificTranslations = self::asArray(
                self::mappingValue(
                    $translationConfig,
                    'specific'
                )
            );
            $basedOnOtherAttributeValue = self::asArray(
                self::mappingValue(
                    $translationConfig,
                    'based_on_other_attribute_value'
                )
            );

            // Check for based_on_other_attribute_value first (most specific)
            if ($basedOnOtherAttributeValue !== []) {
                // Check if based_on_other_attribute_value has 'default' and 'specific' structure
                if (
                    self::arrayKeyExists(
                        'default',
                        $basedOnOtherAttributeValue
                    )
                    || self::arrayKeyExists(
                        'specific',
                        $basedOnOtherAttributeValue
                    )
                ) {
                    $defaultBasedOnOtherAttributeValue = self::asArray(
                        self::mappingValue(
                            $basedOnOtherAttributeValue,
                            'default'
                        )
                    );
                    $specificBasedOnOtherAttributeValue = self::asArray(
                        self::mappingValue(
                            $basedOnOtherAttributeValue,
                            'specific'
                        )
                    );

                    // Use fieldtype-specific configuration if available
                    $configToUse = $defaultBasedOnOtherAttributeValue;
                    if (
                        $excludeFieldtypeString !== ''
                        && self::arrayKeyExists(
                            $excludeFieldtypeString,
                            $specificBasedOnOtherAttributeValue
                        )
                    ) {
                        $configToUse = self::asArray(
                            self::mappingValue(
                                $specificBasedOnOtherAttributeValue,
                                $excludeFieldtypeString
                            )
                        );
                    }

                    foreach ($configToUse as $otherAttrName => $valueMappings) {
                        $normalizedOtherAttrName = self::normalizeArrayKey(
                            $otherAttrName
                        );
                        if ($normalizedOtherAttrName === null) {
                            continue;
                        }

                        $valueMappings = self::asArray($valueMappings);
                        $otherAttrValue = $dataArray[$normalizedOtherAttrName] ?? null;

                        // Find the appropriate mapping based on the other attribute's value
                        $currentValueMappings = null;

                        // Check for '#not_set' special case first
                        if (
                            self::arrayKeyExists(
                                '#not_set',
                                $valueMappings
                            )
                        ) {
                            if (
                                self::isAttributeNotSet(
                                    $normalizedOtherAttrName,
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#not_set'
                                    )
                                );
                            }
                        } elseif (
                            self::arrayKeyExists(
                                '#not_empty',
                                $valueMappings
                            )
                        ) { // Check for '#not_empty' special case
                            if (
                                self::isAttributeNotEmpty(
                                    $normalizedOtherAttrName,
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#not_empty'
                                    )
                                );
                            }
                        } elseif (
                            self::arrayKeyExists(
                                '#empty',
                                $valueMappings
                            )
                        ) { // Check for '#empty' special case
                            if (
                                self::isAttributeEmpty(
                                    $normalizedOtherAttrName,
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#empty'
                                    )
                                );
                            }
                        } elseif (
                            self::arrayKeyExists(
                                $otherAttrValue,
                                $valueMappings
                            )
                        ) { // Check for exact value match (with boolean to string conversion)
                            $currentValueMappings = self::asArray(
                                self::mappingValue(
                                    $valueMappings,
                                    $otherAttrValue
                                )
                            );
                        } elseif (\is_bool($otherAttrValue)) { // Handle boolean to string conversion for comparison
                            $stringValue = $otherAttrValue ? 'true' : 'false';
                            if (
                                self::arrayKeyExists(
                                    $stringValue,
                                    $valueMappings
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        $stringValue
                                    )
                                );
                            }
                        }

                        // If we found mappings for the other attribute's value, apply them to this attribute
                        if ($currentValueMappings !== null) {
                            // Hand the resolved inner mapping to the shared
                            // general value-translation applicator below, which
                            // resolves #empty / #not_set /
                            // #not_empty / exact-value / search-replace lists and
                            // ASSIGNS the result to $value. The previous per-branch
                            // pre-resolution set matchFound and skipped that
                            // applicator, so the resolved value was computed and
                            // then discarded — max_files was never emitted and
                            // address default-state names were never abbreviated.
                            // See wordpress-importer-archive#463.
                            $translationArray = $currentValueMappings;
                            break;
                        }
                    }
                } else { // Otherwise
                    // Legacy structure
                    foreach ($basedOnOtherAttributeValue as $otherAttrName => $valueMappings) {
                        $normalizedOtherAttrName = self::normalizeArrayKey(
                            $otherAttrName
                        );
                        if ($normalizedOtherAttrName === null) {
                            continue;
                        }

                        $valueMappings = self::asArray($valueMappings);
                        $otherAttrValue = $dataArray[$normalizedOtherAttrName] ?? null;

                        // Find the appropriate mapping based on the other attribute's value
                        $currentValueMappings = null;

                        // Check for '#not_set' special case first
                        if (
                            self::arrayKeyExists(
                                '#not_set',
                                $valueMappings
                            )
                        ) {
                            if (
                                self::isAttributeNotSet(
                                    $normalizedOtherAttrName,
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#not_set'
                                    )
                                );
                            }
                        } elseif (
                            self::arrayKeyExists(
                                '#not_empty',
                                $valueMappings
                            )
                        ) { // Check for '#not_empty' special case
                            if (
                                self::isAttributeNotEmpty(
                                    $normalizedOtherAttrName,
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#not_empty'
                                    )
                                );
                            }
                        } elseif (
                            self::arrayKeyExists(
                                '#empty',
                                $valueMappings
                            )
                        ) { // Check for '#empty' special case
                            if (
                                self::isAttributeEmpty(
                                    $normalizedOtherAttrName,
                                    $dataArray
                                )
                            ) {
                                $currentValueMappings = self::asArray(
                                    self::mappingValue(
                                        $valueMappings,
                                        '#empty'
                                    )
                                );
                            }
                        } elseif (
                            self::arrayKeyExists(
                                $otherAttrValue,
                                $valueMappings
                            )
                        ) { // Check for exact value match
                            $currentValueMappings = self::asArray(
                                self::mappingValue(
                                    $valueMappings,
                                    $otherAttrValue
                                )
                            );
                        }

                        // If we found mappings for the other attribute's value, apply them to this attribute
                        if ($currentValueMappings !== null) {
                            // Hand the resolved inner mapping to the shared
                            // general value-translation applicator below, which
                            // resolves #empty / #not_set /
                            // #not_empty / exact-value / search-replace lists and
                            // ASSIGNS the result to $value. The previous per-branch
                            // pre-resolution set matchFound and skipped that
                            // applicator, so the resolved value was computed and
                            // then discarded — max_files was never emitted and
                            // address default-state names were never abbreviated.
                            // See wordpress-importer-archive#463.
                            $translationArray = $currentValueMappings;
                            break;
                        }
                    }
                }
            } elseif (
                $excludeFieldtypeString !== ''
                && self::arrayKeyExists(
                    $excludeFieldtypeString,
                    $specificTranslations
                )
            ) { // Check for fieldtype-specific translations
                $translationArray = self::asArray(
                    self::mappingValue(
                        $specificTranslations,
                        $excludeFieldtypeString
                    )
                );
            } else { // Fall back to default translations
                $translationArray = $defaultTranslations;
            }
        } else { // Otherwise
            // Simple array structure
            $translationArray = $translationConfig;
        }

        // Apply translations if we have a valid translation array
        // Skip general translation logic if based_on_other_attribute_value provided a value
        if (
            $translationArray !== null
        ) {
            // Check for '#not_set' special case first
            if (
                self::arrayKeyExists(
                    '#not_set',
                    $translationArray
                )
            ) {
                if (
                    self::isAttributeNotSetWithFallback(
                        $attrName,
                        $dataArray,
                        $originalFieldData
                    )
                ) {
                    $value = self::mappingValue(
                        $translationArray,
                        '#not_set'
                    );
                }
            }

            // Check for '#not_empty' special case
            if (
                self::arrayKeyExists(
                    '#not_empty',
                    $translationArray
                )
            ) {
                if (
                    self::isAttributeNotEmptyWithFallback(
                        $attrName,
                        $dataArray,
                        $originalFieldData
                    )
                ) {
                    $value = self::mappingValue(
                        $translationArray,
                        '#not_empty'
                    );
                }
            }

            // Check for '#empty' special case
            if (
                self::arrayKeyExists(
                    '#empty',
                    $translationArray
                )
            ) {
                // For array processing, only check if the current value is empty, not the original attribute
                // The original attribute might be empty because it's being populated by combining other attributes
                $isCurrentValueEmpty = ($value === '' || $value === null);

                if ($isCurrentValueEmpty) {
                    $value = self::mappingValue(
                        $translationArray,
                        '#empty'
                    );
                }
            }

            // Check if this is an index array structure with search/replace pairs
            if (self::isIndexArrayWithSearchReplace($translationArray)) {
                $value = self::applyIndexArrayTranslations(
                    $value,
                    $translationArray,
                    $attrName,
                    $dataArray,
                    $originalFieldData
                );
            } elseif (
                (
                    \is_string($value)
                    || \is_int($value)
                    || \is_bool($value)
                )
                && self::arrayKeyExists(
                    $value,
                    $translationArray
                )
            ) { // Check for exact value match first (associative array structure)
                $value = self::mappingValue(
                    $translationArray,
                    $value
                );
            } elseif (\is_string($value)) { // If no exact match, try pattern matching for wildcard patterns
                foreach ($translationArray as $pattern => $replacement) {
                    // Skip special cases that were already handled
                    if (
                        in_array($pattern, ['#not_set', '#not_empty', '#empty'], true)
                    ) {
                        continue;
                    }

                    // Normalize the pattern key to a string so wildcard
                    // detection and matching operate on string-only inputs.
                    $patternString = self::stringValue($pattern);
                    if ($patternString === null) {
                        continue;
                    }

                    // Check if this is a wildcard pattern (contains *)
                    if (
                        \str_contains(
                            $patternString,
                            '*'
                        )
                    ) {
                        if (
                            self::matchesPattern(
                                $value,
                                $patternString
                            )
                        ) {
                            // Resolve wildcards in the replacement
                            $replacementString = self::stringValue($replacement);
                            if ($replacementString === null) {
                                continue;
                            }

                            $value = self::resolveWildcards(
                                $replacementString,
                                $value,
                                $patternString
                            );
                            break; // Use the first matching pattern
                        }
                    }
                }
            }
        }

        return $value;
    }

    /**
     * Check if the translation array is an index array structure with search/replace
     * pairs.
     *
     * @param  array<int|string, mixed>  $translationArray  The translation array to check
     * @return bool True if this is an index array with search/replace structure
     */
    protected static function isIndexArrayWithSearchReplace(
        array $translationArray
    ): bool {
        // Must be a non-empty array
        if ($translationArray === []) {
            return false;
        }

        // Check if all elements are arrays with 'search' and 'replace' keys
        foreach ($translationArray as $item) {
            if (! \is_array($item)) {
                return false;
            }

            // Must have 'search' key
            if (
                ! self::arrayKeyExists(
                    'search',
                    $item
                )
            ) {
                return false;
            }

            // Must have 'replace' key
            if (
                ! self::arrayKeyExists(
                    'replace',
                    $item
                )
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Apply translations using index array structure with search/replace pairs.
     *
     * @param  mixed  $value  The value to translate
     * @param  array<int|string, mixed>  $translationArray  The index array with search/replace pairs
     * @param  ?string  $attrName  The attribute name for placeholder context
     * @param  array<int|string, mixed>  $data  The data array for placeholder context
     * @param  array<int|string, mixed>|null  $originalFieldData  The original field data for placeholder context
     * @return mixed The translated value
     */
    protected static function applyIndexArrayTranslations(
        mixed $value,
        array $translationArray,
        int|string|null $attrName = null,
        array $data = [],
        ?array $originalFieldData = null
    ) {
        // Process each search/replace pair first (try to match the entire value)
        foreach ($translationArray as $translationRule) {
            $translationRuleArray = self::asArray($translationRule);
            $search = self::mappingValue(
                $translationRuleArray,
                'search'
            );
            $replace = self::mappingValue(
                $translationRuleArray,
                'replace'
            );

            if (
                self::valueMatches(
                    $value,
                    $search,
                    $attrName,
                    $data,
                    $originalFieldData
                )
            ) {
                // Apply the replacement
                return self::applyReplacement(
                    $replace,
                    $value,
                    $search
                );
            }
        }

        // No match found, return original value
        return $value;
    }

    /**
     * Check if a value matches a search pattern.
     *
     * @param  mixed  $value  The value to check
     * @param  mixed  $search  The search pattern
     * @param  ?string  $attrName  The attribute name for placeholder context
     * @param  array<int|string, mixed>  $data  The data array for placeholder context
     * @param  array<int|string, mixed>|null  $originalFieldData  The original field data for placeholder context
     * @return bool True if the value matches the search pattern
     */
    protected static function valueMatches(
        mixed $value,
        mixed $search,
        int|string|null $attrName = null,
        array $data = [],
        ?array $originalFieldData = null
    ): bool {
        // Handle special placeholders in search values
        if (\is_string($search)) {
            // Check for '#not_empty' placeholder
            if ($search === '#not_empty') {
                return self::isAttributeNotEmptyWithFallback(
                    $attrName,
                    $data,
                    $originalFieldData
                );
            }

            // Check for '#not_set' placeholder
            if ($search === '#not_set') {
                return self::isAttributeNotSetWithFallback(
                    $attrName,
                    $data,
                    $originalFieldData
                );
            }

            // Check for '#empty' placeholder
            if ($search === '#empty') {
                return self::isAttributeEmptyWithFallback(
                    $attrName,
                    $data,
                    $originalFieldData
                );
            }
        }

        // Exact match for scalar values
        if (\is_scalar($value) && \is_scalar($search)) {
            // Check for wildcard patterns in search string
            if (
                \is_string($search)
                && \is_string($value)
                && \str_contains(
                    $search,
                    '*'
                )
            ) {
                return self::matchesPattern(
                    $value,
                    $search
                );
            }

            return $value === $search;
        }

        // Handle array values by converting to string representation
        if (\is_array($value) && \is_scalar($search)) {
            return \json_encode($value) === $search;
        }

        // Handle array-to-array matching
        if (\is_array($value) && \is_array($search)) {
            // Check for exact array match (same elements, same order)
            return $value === $search;
        }

        return false;
    }

    /**
     * Apply a replacement value to a matched value.
     *
     * @param  mixed  $replace  The replacement value
     * @param  mixed  $originalValue  The original value that was matched
     * @param  mixed  $searchPattern  The search pattern that was matched
     * @return mixed The replacement value
     */
    protected static function applyReplacement(
        mixed $replace,
        mixed $originalValue,
        $searchPattern
    ) {
        // If replacement is null, return null (exclude the value)
        if ($replace === null) {
            return null;
        }

        // If replacement is an array, return it as-is
        if (\is_array($replace)) {
            return $replace;
        }

        // If replacement is a string and the search pattern contains wildcards,
        // resolve wildcards in the replacement
        if (
            \is_string($replace)
            && \is_string($searchPattern)
            && \str_contains(
                $searchPattern,
                '*'
            )
        ) {
            return self::resolveWildcards(
                $replace,
                self::normalizeMixedToString($originalValue),
                $searchPattern
            );
        }

        // Return the replacement value as-is
        return $replace;
    }

    /**
     * Translate calculation field references from Gravity Forms format to Statamic
     * format.
     *
     * Converts Gravity Forms calculation field references like:
     *   - {Math Calculation Target Field 1:1016} -> [math_calculation_target_field_1_1016]
     *   - {Field Label:123} -> [field_handle_123]
     *
     * @param  string  $text  The text containing field references
     * @param  array<int|string, mixed>  $allFields  Array of all form fields
     * @param  array<int|string, mixed>|null  $currentField  Current field data for context
     * @return string The text with translated field references
     */
    protected static function translateCalculationFieldReferences(
        string $text,
        array $allFields = [],
        ?array $currentField = null
    ): string {
        // Pattern to match Gravity Forms calculation field references: {descriptor:field_id}
        $pattern = '/\{([^}]+)\}/';

        $translatedText = \preg_replace_callback(
            $pattern,
            function ($matches) use ($allFields): string {
                $reference = $matches[1];

                // Parse the content to find the field ID
                $parts = \explode(
                    ':',
                    $reference
                );

                // If there's only one part, it's not a field reference
                if (\count($parts) < 2) {
                    return $matches[0]; // Return original unchanged
                }

                // Find the field ID by looking for a number (integer or decimal)
                $fieldId = null;
                $descriptorParts = [];

                foreach ($parts as $part) {
                    // Check if this part is a number (field ID)
                    if (
                        \preg_match(
                            '/^\d+(?:\.\d+)?$/',
                            $part
                        ) === 1
                    ) {
                        $fieldId = $part;
                        break;
                    } else { // Otherwise
                        // This part is part of the descriptor
                        $descriptorParts[] = $part;
                    }
                }

                // If no field ID found, this is not a field reference
                if ($fieldId === null) {
                    return $matches[0]; // Return original unchanged
                }

                // Find the field handle for this field ID
                $fieldHandle = self::getFieldHandleById(
                    $fieldId,
                    $allFields
                );

                if ($fieldHandle !== null) {
                    return "[{$fieldHandle}]";
                }

                // If field handle not found, return original unchanged
                return $matches[0];
            },
            $text
        );

        return $translatedText ?? $text;
    }

    /**
     * Convert a value to boolean.
     *
     * @param  mixed  $value  The value to convert
     * @return bool The boolean value
     */
    protected static function convertToBoolean(
        $value
    ) {
        if (\is_string($value)) {
            return filter_var(
                $value,
                FILTER_VALIDATE_BOOLEAN
            );
        }

        if (\is_numeric($value)) {
            return (bool) $value;
        }

        if (\is_array($value)) {
            return $value !== [];
        }

        if (\is_object($value)) {
            return true;
        }

        if (\is_null($value)) {
            return false;
        }

        if (\is_bool($value)) {
            return $value;
        }

        // Otherwise
        throw new Exception('Unsupported value type for boolean conversion: '.gettype($value));
    }

    /**
     * Automatically inject #empty => null mapping to value_translation configurations
     * if they don't already have it. This prevents unexpected attributes from being
     * added when empty values are processed.
     *
     * @param  array<int|string, mixed>  $translationConfig  The value_translation configuration array
     * @return array<int|string, mixed> The modified configuration with #empty => null mapping added
     */
    protected static function injectEmptyValueMapping(
        array $translationConfig
    ): array {
        // Handle simple array structure
        if (
            ! self::arrayKeyExists(
                'default',
                $translationConfig
            )
            && ! self::arrayKeyExists(
                'specific',
                $translationConfig
            )
            && ! self::arrayKeyExists(
                'based_on_other_attribute_value',
                $translationConfig
            )
        ) {

            // Check if this is an index array structure with search/replace pairs
            if (self::isIndexArrayWithSearchReplace($translationConfig)) {
                // For index arrays, we don't inject #empty => null as they handle empty values differently
                // The empty value handling is done through the search/replace logic
                return $translationConfig;
            }

            // Simple associative structure - add #empty => null if not present
            if (
                ! self::arrayKeyExists(
                    '#empty',
                    $translationConfig
                )
            ) {
                $translationConfig['#empty'] = null;
            }

            return $translationConfig;
        }

        // Handle complex structure with 'default', 'specific', etc.

        // Add #empty => null to default translations if not present
        if (
            self::arrayKeyExists(
                'default',
                $translationConfig
            )
        ) {
            $defaultConfig = self::asArray(
                self::mappingValue(
                    $translationConfig,
                    'default'
                )
            );
            // Check if default is an index array structure
            if (self::isIndexArrayWithSearchReplace($defaultConfig)) {
                // For index arrays, we don't inject #empty => null
                // The empty value handling is done through the search/replace logic
            } else { // Otherwise
                // Associative structure - add #empty => null if not present
                if (
                    ! self::arrayKeyExists(
                        '#empty',
                        $defaultConfig
                    )
                ) {
                    $defaultConfig['#empty'] = null;
                }
            }

            $translationConfig['default'] = $defaultConfig;
        }

        // Add #empty => null to specific translations if not present
        if (
            self::arrayKeyExists(
                'specific',
                $translationConfig
            )
        ) {
            $specificConfig = self::asArray(
                self::mappingValue(
                    $translationConfig,
                    'specific'
                )
            );
            foreach ($specificConfig as $fieldtype => $translations) {
                $normalizedFieldtype = self::normalizeArrayKeyForAssignment(
                    $fieldtype
                );
                $translationsArray = self::asArray($translations);
                if ($translationsArray !== []) {
                    // Check if this fieldtype's translations is an index array structure
                    if (self::isIndexArrayWithSearchReplace($translationsArray)) {
                        // For index arrays, we don't inject #empty => null
                        // The empty value handling is done through the search/replace logic
                    } else { // Otherwise
                        // Associative structure - add #empty => null if not present
                        if (
                            ! self::arrayKeyExists(
                                '#empty',
                                $translationsArray
                            )
                        ) {
                            $translationsArray['#empty'] = null;
                        }
                    }
                }

                $specificConfig[$normalizedFieldtype] = $translationsArray;
            }

            $translationConfig['specific'] = $specificConfig;
        }

        // Don't inject #empty => null into based_on_other_attribute_value structures
        // as they have their own logic for handling empty values

        return $translationConfig;
    }

    /**
     * Convert Gravity Forms choices array to Statamic fields format.
     *
     * This method transforms a Gravity Forms choices array into the format expected
     * by Statamic grid fields, where each choice becomes a separate field with
     * its own handle and field configuration.
     *
     * @param  array<int|string, mixed>  $choices  The Gravity Forms choices array
     * @param  array<int|string, mixed>  $fieldData  The complete field data for context
     * @return array<int|string, mixed> The converted fields array
     */
    protected static function convertChoicesToFields(
        array $choices,
        array $fieldData
    ): array {
        $fields = [];
        $choiceCount = 1;

        // Use original choices from fieldData if available, otherwise fall back to processed choices
        $originalChoices = self::asArray(
            self::mappingValue(
                $fieldData,
                'choices'
            ) ?? $choices
        );

        // Get the parent field name and ID for handle generation
        $parentFieldName = static::getFieldNameFromField($fieldData);
        $parentFieldId = self::normalizeMixedToString(
            self::mappingValue(
                $fieldData,
                'id'
            ) ?? ''
        );

        // Generate the parent field handle using the existing method
        $parentHandle = static::convertFieldNameToHandle(
            $parentFieldName ?? '',
            $parentFieldId
        );

        foreach ($originalChoices as $choice) {
            $choiceData = self::asArray($choice);
            // Extract the choice text/value for the field display name
            $choiceText = self::stringValue(
                self::mappingValue(
                    $choiceData,
                    'text'
                )
            ) ?? self::stringValue(
                self::mappingValue(
                    $choiceData,
                    'value'
                )
            ) ?? '';

            // Generate a snake_case handle for this choice
            $choiceHandle = self::convertToSnakeCase($choiceText);

            // Create the field handle: <parent_handle>_<snake_case_choice_value>_<choice_count>
            $fieldHandle = $parentHandle.'_'.$choiceHandle.'_'.$choiceCount;

            // Create the field configuration
            $fieldConfig = [
                'handle' => $fieldHandle,
                'field' => [
                    'type' => 'text',
                    'display' => $choiceText,
                ],
            ];

            $fields[] = $fieldConfig;
            $choiceCount++;
        }

        return $fields;
    }

    /**
     * Get the expected keys for options arrays for a given fieldtype.
     *
     * This method looks up the expected_keys configuration from the choices mapping
     * to determine which keys should be present in the options array for each
     * fieldtype.
     *
     * @param  array<int|string, mixed>  $data  The field data to check for based_on_other_attribute_value conditions
     * @param  mixed  $attrValue  The attribute value for based_on_attribute_value checks
     * @param  ?string  $fieldtype  The Statamic fieldtype
     * @param  ?string  $attrName  The attribute name for based_on_attribute_value checks
     * @return array<string> The expected keys for the fieldtype
     */
    public static function getExpectedKeys(
        array $data = [],
        mixed $attrValue = null,
        ?string $fieldtype = null,
        int|string|null $attrName = null
    ): array {
        // Get the choices configuration from the implementing class
        $choicesConfig = static::$fieldAttributeMapping['choices'] ?? null;
        if (
            $choicesConfig === null
            || $choicesConfig === []
            || ! isset($choicesConfig['expected_keys'])
        ) {
            // Fallback to default keys if no configuration exists
            return ['key', 'value'];
        }

        $expectedKeysConfig = self::asArray(
            self::mappingValue(
                self::mappingConfig($choicesConfig),
                'expected_keys'
            )
        );
        $defaultExpectedKeys = self::asArray(
            self::mappingValue(
                $expectedKeysConfig,
                'default'
            ) ?? ['key', 'value']
        );

        // Use the DRY helper method to process modifier patterns
        $resolvedKeys = self::processModifierPatternsWithReturn(
            $expectedKeysConfig,
            $attrValue,
            $data,
            $defaultExpectedKeys,
            null,
            $fieldtype,
            $attrName
        );

        return \array_values(
            \array_map(
                self::normalizeMixedToString(...),
                self::asArray($resolvedKeys)
            )
        );
    }

    /**
     * Ensure all options in an array have the expected structure based on fieldtype.
     *
     * This method adds missing keys to each option array based on the expected_keys
     * configuration for the given fieldtype.
     *
     * @param  array<int|string, mixed>  &$options  The options array to normalize (passed by reference)
     * @param  array<int|string, mixed>  $data  The field data to check for based_on_other_attribute_value conditions
     * @param  mixed  $attrValue  The attribute value for based_on_attribute_value checks
     * @param  ?string  $fieldtype  The Statamic fieldtype
     * @param  ?string  $attrName  The attribute name for based_on_attribute_value checks
     */
    protected static function normalizeOptionsStructure(
        array &$options,
        array $data = [],
        mixed $attrValue = null,
        ?string $fieldtype = null,
        int|string|null $attrName = null
    ): void {
        if ($options === []) {
            return;
        }

        $expectedKeys = self::getExpectedKeys(
            $data,
            $attrValue,
            $fieldtype,
            $attrName
        );

        // Add missing keys to each option
        foreach ($options as &$option) {
            if (\is_array($option)) {
                foreach ($expectedKeys as $expectedKey) {
                    if (
                        ! self::arrayKeyExists(
                            $expectedKey,
                            $option
                        )
                    ) {
                        // Add missing key with null value
                        $option[$expectedKey] = null;
                    }
                }
            }
        }

        // Clean up variables to free up memory
        unset($option);
    }

    /**
     * Convert a string to snake_case.
     *
     * @param  string  $string  The string to convert
     * @return string The snake_case version
     */
    protected static function convertToSnakeCase(
        string $string
    ): string {
        // Convert to lowercase and trim whitespace
        $handle = \strtolower(\trim($string));

        // Replace non-alphanumeric characters with underscores
        $handle = \preg_replace(
            '/[^a-z0-9_]/',
            '_',
            $handle
        ) ?? $handle;

        // Collapse multiple consecutive underscores into single underscore
        $handle = \preg_replace(
            '/_+/',
            '_',
            $handle
        ) ?? $handle;

        // Remove leading and trailing underscores
        $handle = \trim(
            $handle,
            '_'
        );

        return $handle;
    }

    /**
     * Process value mappings for based_on_other_attribute_value configurations.
     *
     * @param  array<int|string, mixed>  $valueMappings  The value mappings to process
     * @param  string  $otherAttrName  The name of the other attribute
     * @param  array<int|string, mixed>  $data  The field data
     * @return array{0: mixed, 1: bool}|null Returns [value, found] tuple if match found, null otherwise
     */
    protected static function processValueMappings(
        array $valueMappings,
        int|string $otherAttrName,
        array $data
    ): ?array {
        // Get the value of the other attribute
        $otherAttrValue = $data[$otherAttrName] ?? null;
        $lookupValue = self::mappingLookupKey($otherAttrValue);

        // Check if we have a mapping for this value
        if (
            self::arrayKeyExists(
                $lookupValue,
                $valueMappings
            )
        ) {
            return [$valueMappings[$lookupValue], true];
        }

        // Check for #not_set case
        if (
            self::arrayKeyExists(
                '#not_set',
                $valueMappings
            )
            && self::isAttributeNotSet(
                $otherAttrName,
                $data
            )
        ) {
            return [$valueMappings['#not_set'], true];
        }

        // Check for #not_empty case
        if (
            self::arrayKeyExists(
                '#not_empty',
                $valueMappings
            )
            && self::isAttributeNotEmpty(
                $otherAttrName,
                $data
            )
        ) {
            return [$valueMappings['#not_empty'], true];
        }

        // Check for #empty case
        if (
            self::arrayKeyExists(
                '#empty',
                $valueMappings
            )
            && self::isAttributeEmpty(
                $otherAttrName,
                $data
            )
        ) {
            return [$valueMappings['#empty'], true];
        }

        // Check for empty string case
        if (
            self::arrayKeyExists(
                '',
                $valueMappings
            )
            && $otherAttrValue === ''
        ) {
            return [$valueMappings[''], true];
        }

        return null;
    }

    /**
     * Process pattern mappings for based_on_attribute_value configurations.
     *
     * @param  array<int|string, mixed>  $patternMappings  The pattern mappings to process
     * @param  string  $attrName  The name of the current attribute
     * @param  mixed  $attrValue  The value of the current attribute
     * @param  array<int|string, mixed>  $data  The field data
     * @return array{0: mixed, 1: bool}|null Returns [value, found] tuple if match found, null otherwise
     */
    protected static function processPatternMappings(
        array $patternMappings,
        int|string|null $attrName,
        mixed $attrValue,
        array $data
    ): ?array {
        foreach ($patternMappings as $pattern => $targetAttribute) {
            // Check for '#not_set' special case first
            if ($pattern === '#not_set') {
                if (
                    self::isAttributeNotSet(
                        $attrName,
                        $data
                    )
                ) {
                    return [$targetAttribute, true];
                }
            } elseif ($pattern === '#not_empty') { // Check for '#not_empty' special case
                if (
                    self::isAttributeNotEmpty(
                        $attrName,
                        $data
                    )
                ) {
                    return [$targetAttribute, true];
                }
            } else { // Otherwise
                // Check against the original value before translation
                if (
                    self::matchesPattern(
                        $attrValue,
                        $pattern
                    )
                ) {
                    return [$targetAttribute, true];
                }
            }
        }

        return null;
    }

    /**
     * Process fieldtype-specific or general mappings for
     * based_on_other_attribute_value.
     *
     * @param  array<int|string, mixed>|string  $config  The configuration array or legacy string
     * @param  ?string  $excludeFieldtype  The fieldtype to check for
     * @param  array<int|string, mixed>  $data  The field data
     * @return array{0: mixed, 1: bool}|null Returns [value, found] tuple if match found, null otherwise
     */
    protected static function processBasedOnOtherAttributeValue(
        mixed $config,
        mixed $excludeFieldtype,
        array $data
    ): ?array {
        $matchFound = false;
        $result = null;
        $configArray = self::mappingConfig($config);
        $excludeFieldtypeString = self::stringValue($excludeFieldtype) ?? '';

        // Check if config is an array
        // Check for 'specific' key
        if (
            self::arrayKeyExists(
                'specific',
                $configArray
            )
        ) {
            $specificConfig = self::asArray(
                self::mappingValue(
                    $configArray,
                    'specific'
                )
            );
            // Check for fieldtype-specific configuration
            if (
                $excludeFieldtypeString !== ''
                && self::arrayKeyExists(
                    $excludeFieldtypeString,
                    $specificConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificConfig,
                        $excludeFieldtypeString
                    )
                );

                foreach ($fieldtypeMappings as $otherAttrName => $valueMappings) {
                    $normalizedOtherAttrName = self::normalizeArrayKey(
                        $otherAttrName
                    );
                    if ($normalizedOtherAttrName === null) {
                        continue;
                    }

                    $result = self::processValueMappings(
                        self::asArray($valueMappings),
                        $normalizedOtherAttrName,
                        $data
                    );
                    if ($result !== null) {
                        $matchFound = true;
                        break;
                    }
                }
            }
        }

        // Check for 'default' key (if match not found)
        if (
            self::arrayKeyExists(
                'default',
                $configArray
            )
            && ! $matchFound
        ) {
            $defaultConfig = self::asArray(
                self::mappingValue(
                    $configArray,
                    'default'
                )
            );

            foreach ($defaultConfig as $otherAttrName => $valueMappings) {
                $normalizedOtherAttrName = self::normalizeArrayKey(
                    $otherAttrName
                );
                if ($normalizedOtherAttrName === null) {
                    continue;
                }

                $result = self::processValueMappings(
                    self::asArray($valueMappings),
                    $normalizedOtherAttrName,
                    $data
                );
                if ($result !== null) {
                    $matchFound = true;
                    break;
                }
            }
        }

        // Legacy structure - process each based_on_other_attribute_value configuration directly (if match not found)
        if (
            ! $matchFound
            && $configArray !== []
            && ! self::arrayKeyExists(
                'specific',
                $configArray
            )
            && ! self::arrayKeyExists(
                'default',
                $configArray
            )
        ) {
            foreach ($configArray as $otherAttrName => $valueMappings) {
                $normalizedOtherAttrName = self::normalizeArrayKey(
                    $otherAttrName
                );
                if ($normalizedOtherAttrName === null) {
                    continue;
                }

                $result = self::processValueMappings(
                    self::asArray($valueMappings),
                    $normalizedOtherAttrName,
                    $data
                );
                if ($result !== null) {
                    $matchFound = true;
                    break;
                }
            }
        }

        // Check if config is a string (if match not found)
        if (
            \is_string($config)
            && ! $matchFound
        ) {
            // For string configs, we would process them directly
            // This would be the legacy string-based configuration
            $result = [$config, true];
            $matchFound = true;
        }

        return $matchFound ? $result : null;
    }

    /**
     * Process fieldtype-specific or general mappings for based_on_attribute_value.
     *
     * @param  array<int|string, mixed>|string  $config  The configuration array or legacy string
     * @param  ?string  $excludeFieldtype  The fieldtype to check for
     * @param  string  $attrName  The name of the current attribute
     * @param  mixed  $attrValue  The value of the current attribute
     * @param  array<int|string, mixed>  $data  The field data
     * @return array{0: mixed, 1: bool}|null Returns [value, found] tuple if match found, null otherwise
     */
    protected static function processBasedOnAttributeValue(
        mixed $config,
        mixed $excludeFieldtype,
        int|string|null $attrName,
        mixed $attrValue,
        array $data
    ): ?array {
        $matchFound = false;
        $result = null;
        $configArray = self::mappingConfig($config);
        $excludeFieldtypeString = self::stringValue($excludeFieldtype) ?? '';

        // Check if config is an array
        // Check for 'specific' key
        if (
            self::arrayKeyExists(
                'specific',
                $configArray
            )
        ) {
            $specificConfig = self::asArray(
                self::mappingValue(
                    $configArray,
                    'specific'
                )
            );
            // Check for fieldtype-specific configuration
            if (
                $excludeFieldtypeString !== ''
                && self::arrayKeyExists(
                    $excludeFieldtypeString,
                    $specificConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificConfig,
                        $excludeFieldtypeString
                    )
                );
                $result = self::processPatternMappings(
                    $fieldtypeMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    $matchFound = true;
                }
            }
        }

        // Check for 'default' key (if match not found)
        if (
            self::arrayKeyExists(
                'default',
                $configArray
            )
            && ! $matchFound
        ) {
            $defaultConfig = self::asArray(
                self::mappingValue(
                    $configArray,
                    'default'
                )
            );
            $result = self::processPatternMappings(
                $defaultConfig,
                $attrName,
                $attrValue,
                $data
            );
            if ($result !== null) {
                $matchFound = true;
            }
        }

        // Legacy structure - process each based_on_attribute_value configuration directly (if match not found)
        if (
            ! $matchFound
            && $configArray !== []
            && ! self::arrayKeyExists(
                'specific',
                $configArray
            )
            && ! self::arrayKeyExists(
                'default',
                $configArray
            )
        ) {
            // Check for fieldtype-specific configuration first
            if (
                $excludeFieldtypeString !== ''
                && self::arrayKeyExists(
                    $excludeFieldtypeString,
                    $configArray
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $configArray,
                        $excludeFieldtypeString
                    )
                );
                $result = self::processPatternMappings(
                    $fieldtypeMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    $matchFound = true;
                }
            } else { // If no fieldtype-specific mapping was found, check for general mappings
                $result = self::processPatternMappings(
                    $configArray,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    $matchFound = true;
                }
            }
        }

        // Check if config is a string (if match not found)
        if (
            \is_string($config)
            && ! $matchFound
        ) {
            // For string configs, we would process them directly
            // This would be the legacy string-based configuration
            $result = [$config, true];
            $matchFound = true;
        }

        return $matchFound ? $result : null;
    }

    /**
     * Check if the current fieldtype should be excluded based on configuration.
     *
     * @param  array<int|string, mixed>  $config  Configuration array that may contain 'exclude_fieldtypes' key
     * @param  ?string  $excludeFieldtype  The fieldtype to check
     * @return bool True if the fieldtype should be excluded, false otherwise
     */
    protected static function shouldExcludeFieldtype(
        mixed $config,
        $excludeFieldtype
    ) {
        if (
            ! isset($config['exclude_fieldtypes'])
            || ! \is_array($config['exclude_fieldtypes'])
        ) {
            return false;
        }

        // Get the current Statamic fieldtype (if available)
        $currentFieldtype = $excludeFieldtype; // For now, use the source fieldtype

        // Return true if the current fieldtype is in the exclude list
        return \in_array($currentFieldtype, $config['exclude_fieldtypes'], true);
    }

    /**
     * Explode a string into an array using a delimiter.
     *
     * @param  mixed  $value  The string to explode, an array to return as-is, or any other value (returns '')
     * @param  array<int|string, mixed>  $config  Configuration array that may contain 'explode_string' key
     * @return string|array<int|string, mixed> The exploded list, the original string, or the array unchanged
     */
    protected static function explodeString(
        mixed $value,
        array $config
    ): string|array {
        // An array value is already structured (e.g. Gravity Forms `choices`
        // mapped to blueprint `options`, or a decoded list) and has nothing to
        // split; pass it through unchanged. Only a string is a candidate for
        // explode_string splitting. Returning '' here previously discarded every
        // array-valued attribute — options, list `fields`, date `days_of_week`,
        // etc. — before it reached the blueprint. See wordpress-importer-archive#463.
        if (\is_array($value)) {
            return $value;
        }

        if (! \is_string($value)) {
            return '';
        }

        // Check if explode_string configuration exists and is valid
        if (
            ! isset($config['explode_string'])
            || ! \is_string($config['explode_string'])
            || $config['explode_string'] === ''
        ) {
            return $value;
        }

        return \explode(
            $config['explode_string'],
            $value
        );
    }

    /**
     * Trim whitespace from a string or array of strings.
     *
     * @param  mixed  $value  The value to trim
     * @param  array<int|string, mixed>  $config  Configuration array that may contain 'trim' key
     * @return mixed The trimmed value
     */
    protected static function trimValue(
        mixed $value,
        array $config
    ): mixed {
        // Check if trim configuration exists and is enabled
        if (
            ! isset($config['trim'])
            || $config['trim'] !== true

        ) {
            return $value;
        }

        if (\is_array($value)) {
            // If value is an array (from explode_string), trim each element
            return \array_map(
                fn (mixed $item): string => \is_string($item)
                    ? \trim($item)
                    : self::normalizeMixedToString($item),
                $value
            );
        }

        return \is_string($value) ? \trim($value) : $value;
    }

    /**
     * Trim specific characters from the beginning and end of a string or array of
     * strings.
     *
     * @param  string|array<int|string, mixed>|null  $value  The string or array of strings to trim
     * @param  array<int|string, mixed>  $config  Configuration array that may contain 'trim_characters' key
     * @return string|array<int|string, mixed>|null The trimmed string or array of trimmed strings
     */
    protected static function trimCharacters(
        string|array|null $value,
        array $config
    ): mixed {
        // Check if trim_characters configuration exists
        if (! isset($config['trim_characters'])) {
            return $value;
        }

        $characters = self::asArray(
            self::mappingValue(
                $config,
                'trim_characters'
            )
        );
        $characterStrings = \array_values(
            \array_filter(
                \array_map(
                    self::stringValue(...),
                    $characters
                ),
                fn (?string $character): bool => $character !== null
            )
        );

        // Validate input parameters
        if (
            $value === null
            || $characterStrings === []
            || $value === []
        ) {
            return $value;
        }

        if (\is_array($value)) {
            // If value is an array (from explode_string), trim each element
            return \array_map(
                fn (mixed $item): mixed => (
                    \is_string($item)
                    || \is_array($item)
                    || $item === null
                )
                    ? static::trimCharacters(
                        $item,
                        $config
                    )
                    : self::normalizeMixedToString($item),
                $value
            );
        }

        // First trim whitespace, then trim the specific characters
        $value = \trim($value);
        // Create a character mask for \trim() by joining all characters
        $characterMask = \implode(
            '',
            $characterStrings
        );

        // Use \trim() with the character mask to remove characters from both ends
        return \trim(
            $value,
            $characterMask
        );
    }

    /**
     * Process an attribute if it meets the criteria.
     *
     * @param  string  $attrName  The attribute name
     * @param  mixed  $attrValue  The attribute value
     * @param  array<int|string, mixed>  $processedMappings  The processed mappings
     * @param  array<int|string, mixed>  $combinedData  The combined data array
     * @param  array<int|string, mixed>  $subfieldData  The subfield data (for checking if already processed)
     * @param  ?string  $statamicHandle  The Statamic handle
     * @param  ?string  $preset  The preset value
     * @return array<int|string, mixed>|null Returns mapped attributes array or null if no mapping
     */
    protected static function processAttributeIfValid(
        int|string|null $attrName,
        mixed $attrValue,
        array $processedMappings,
        array $combinedData,
        array $subfieldData = [],
        ?string $statamicHandle = null,
        ?string $preset = null
    ): ?array {
        $normalizedAttrName = self::normalizeArrayKey($attrName);
        if ($normalizedAttrName === null) {
            return null;
        }

        // Skip if this attribute is not in processedMappings
        if (! isset($processedMappings[$normalizedAttrName])) {
            return null;
        }

        // Skip if already processed from subfield data (for parent field processing)
        if ($subfieldData !== [] && isset($subfieldData[$normalizedAttrName])) {
            return null;
        }

        $attrConfig = self::asArray(
            self::mappingValue(
                $processedMappings,
                $normalizedAttrName
            )
        );
        $statamicAttr = self::mappingValue(
            $attrConfig,
            'statamic_attribute'
        );
        if ($statamicAttr === null) {
            return null;
        }

        // Skip if attrValue is empty string or null (these shouldn't create attributes)
        if ($attrValue === null || $attrValue === '') {
            return null;
        }

        // Process the attribute
        return self::processAttributeMapping(
            $normalizedAttrName,
            $attrValue,
            $attrConfig,
            $combinedData,
            $statamicHandle,
            $preset
        );
    }

    /**
     * Process a single attribute mapping.
     *
     * @param  string  $attrName  The attribute name
     * @param  mixed  $attrValue  The attribute value
     * @param  array<int|string, mixed>  $attrConfig  The attribute configuration
     * @param  array<int|string, mixed>  $combinedData  The combined data array
     * @param  ?string  $statamicHandle  The Statamic handle
     * @param  ?string  $preset  The preset value
     * @return array<int|string, mixed>|null Returns mapped attributes array or null if no mapping
     */
    protected static function processAttributeMapping(
        int|string|null $attrName,
        mixed $attrValue,
        array $attrConfig,
        array $combinedData,
        ?string $statamicHandle,
        ?string $preset
    ): ?array {
        $statamicAttr = self::mappingValue(
            $attrConfig,
            'statamic_attribute'
        );
        if ($statamicAttr === null) {
            return null;
        }

        // Handle complex statamic_attribute structures with proper precedence order
        if (\is_array($statamicAttr)) {
            $statamicAttrArray = self::mappingConfig($statamicAttr);
            $basedOnAttributeValueArray = self::asArray(
                self::mappingValue(
                    $statamicAttrArray,
                    'based_on_attribute_value'
                )
            );
            $resolvedAttribute = null;
            $finalValue = $attrValue;

            // 1. based_on_attribute_value.specific
            if (
                self::arrayKeyExists(
                    'specific',
                    $basedOnAttributeValueArray
                )
            ) {
                $result = self::processBasedOnAttributeValue(
                    $basedOnAttributeValueArray,
                    null,
                    $attrName,
                    $attrValue,
                    $combinedData
                );
                if ($result !== null) {
                    [$resolvedAttribute, $matchFound] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            // 2. based_on_attribute_value.default
            if (
                $resolvedAttribute === null
                && self::arrayKeyExists(
                    'default',
                    $basedOnAttributeValueArray
                )
            ) {
                $result = self::processBasedOnAttributeValue(
                    $basedOnAttributeValueArray,
                    null,
                    $attrName,
                    $attrValue,
                    $combinedData
                );
                if ($result !== null) {
                    [$resolvedAttribute, $matchFound] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            // 3. based_on_attribute_value (legacy)
            if (
                $resolvedAttribute === null
                && $basedOnAttributeValueArray !== []
                && ! self::arrayKeyExists(
                    'default',
                    $basedOnAttributeValueArray
                )
                && ! self::arrayKeyExists(
                    'specific',
                    $basedOnAttributeValueArray
                )
            ) {
                $result = self::processBasedOnAttributeValue(
                    $basedOnAttributeValueArray,
                    null,
                    $attrName,
                    $attrValue,
                    $combinedData
                );
                if ($result !== null) {
                    [$resolvedAttribute, $matchFound] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            // 4. based_on_other_attribute_value.specific
            $basedOnOtherAttributeValue = self::asArray(
                self::mappingValue(
                    $statamicAttrArray,
                    'based_on_other_attribute_value'
                )
            );
            if (
                $resolvedAttribute === null
                && self::arrayKeyExists(
                    'specific',
                    $basedOnOtherAttributeValue
                )
            ) {
                $basedOnConfig = self::asArray(
                    self::mappingValue(
                        $basedOnOtherAttributeValue,
                        'specific'
                    )
                );
                $result = self::processBasedOnOtherAttributeValueMapping(
                    $basedOnConfig,
                    $combinedData,
                    $attrValue
                );
                if ($result !== null) {
                    [$resolvedAttribute, $finalValue] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            // 5. based_on_other_attribute_value.default
            if (
                $resolvedAttribute === null
                && self::arrayKeyExists(
                    'default',
                    $basedOnOtherAttributeValue
                )
            ) {
                $basedOnConfig = self::asArray(
                    self::mappingValue(
                        $basedOnOtherAttributeValue,
                        'default'
                    )
                );
                $result = self::processBasedOnOtherAttributeValueMapping(
                    $basedOnConfig,
                    $combinedData,
                    $attrValue
                );
                if ($result !== null) {
                    [$resolvedAttribute, $finalValue] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            // 6. based_on_other_attribute_value (legacy)
            if (
                $resolvedAttribute === null
                && $basedOnOtherAttributeValue !== []
                && ! self::arrayKeyExists(
                    'default',
                    $basedOnOtherAttributeValue
                )
                && ! self::arrayKeyExists(
                    'specific',
                    $basedOnOtherAttributeValue
                )
            ) {
                $result = self::processBasedOnOtherAttributeValueMapping(
                    $basedOnOtherAttributeValue,
                    $combinedData,
                    $attrValue
                );
                if ($result !== null) {
                    [$resolvedAttribute, $finalValue] = $result;
                    if ($resolvedAttribute === null) {
                        return null; // Skip this attribute
                    }

                    // Don't return yet - continue to check for value_translation
                }
            }

            $resolvedAttribute ??= self::mappingValue(
                $statamicAttrArray,
                'specific'
            ) ?? self::mappingValue(
                $statamicAttrArray,
                'default'
            );

            // If we still don't have a resolved attribute, delegate to mapAttributesFromMapping
            if ($resolvedAttribute === null) {
                $mappingKey = self::normalizeArrayKey($attrName);
                if ($mappingKey === null) {
                    return null;
                }

                return static::mapAttributesFromMapping(
                    $combinedData,
                    [$mappingKey => $attrConfig],
                    [],
                    $statamicHandle,
                    $preset
                );
            }

            // Apply value_translation if present in the original attrConfig and we have a resolved attribute
            if (isset($attrConfig['value_translation'])) {
                $finalValue = static::applyValueTranslation(
                    $finalValue,
                    $attrConfig,
                    $attrName,
                    $combinedData,
                    null,
                    null,
                    $resolvedAttribute
                );
            }

            return [
                self::normalizeArrayKeyForAssignment($resolvedAttribute) => $finalValue,
            ];
        }

        // Otherwise
        // Handle simple string statamic_attribute
        $statamicAttrString = self::stringValue($statamicAttr)
            ?? self::normalizeMixedToString($statamicAttr);
        $finalValue = self::processSimpleStatamicAttribute(
            $attrValue,
            $attrConfig,
            $statamicAttrString,
            $combinedData,
            $attrName
        );

        return [
            self::normalizeArrayKeyForAssignment($statamicAttrString) => $finalValue,
        ];
    }

    /**
     * Process based_on_other_attribute_value mapping logic.
     *
     * This method handles the common logic for processing
     * based_on_other_attribute_value configurations, supporting both
     * 'default'/'specific' structure and legacy structure.
     *
     * @param  array<int|string, mixed>  $basedOnConfig  The based_on_other_attribute_value configuration
     * @param  array<int|string, mixed>  $data  The data array to get other attribute values from
     * @param  mixed  $attrValue  The attribute value to map
     * @return array<int|string, mixed>|null Returns [resolvedAttribute, attrValue] if match found, null otherwise
     */
    protected static function processBasedOnOtherAttributeValueMapping(
        array $basedOnConfig,
        array $data,
        mixed $attrValue
    ): ?array {
        // Check if based_on_other_attribute_value has 'default' and 'specific' structure
        if (
            self::arrayKeyExists(
                'default',
                $basedOnConfig
            )
            || self::arrayKeyExists(
                'specific',
                $basedOnConfig
            )
        ) {
            $defaultBasedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnConfig,
                    'default'
                )
            );
            $configToUse = $defaultBasedOnConfig;

            // Process each based_on_other_attribute_value configuration
            foreach ($configToUse as $otherAttrName => $valueMappings) {
                $normalizedOtherAttrName = self::normalizeArrayKey(
                    $otherAttrName
                );
                if ($normalizedOtherAttrName === null) {
                    continue;
                }

                $valueMappingsArray = self::asArray($valueMappings);
                $otherAttrValue = $data[$normalizedOtherAttrName] ?? null;

                // Check for exact value match
                if (
                    self::arrayKeyExists(
                        $otherAttrValue,
                        $valueMappingsArray
                    )
                ) {
                    $resolvedAttribute = self::mappingValue(
                        $valueMappingsArray,
                        $otherAttrValue
                    );

                    return [$resolvedAttribute, $attrValue];
                }
            }
        } else { // Otherwise
            // Legacy structure - process each based_on_other_attribute_value configuration
            foreach ($basedOnConfig as $otherAttrName => $valueMappings) {
                $normalizedOtherAttrName = self::normalizeArrayKey(
                    $otherAttrName
                );
                if ($normalizedOtherAttrName === null) {
                    continue;
                }

                $valueMappingsArray = self::asArray($valueMappings);
                $otherAttrValue = $data[$normalizedOtherAttrName] ?? null;

                // Check for exact value match
                if (
                    self::arrayKeyExists(
                        $otherAttrValue,
                        $valueMappingsArray
                    )
                ) {
                    $resolvedAttribute = self::mappingValue(
                        $valueMappingsArray,
                        $otherAttrValue
                    );

                    return [$resolvedAttribute, $attrValue];
                }
            }
        }

        return null;
    }

    /**
     * Post-process mapped attributes to replace placeholders and filter empty keys.
     *
     * @param  array<int|string, mixed>  $mapped  The mapped attributes array
     * @param  ?string  $statamicHandle  The statamic handle for placeholder replacement
     * @param  ?string  $preset  The preset value for placeholder replacement
     * @return array<int|string, mixed> The processed mapped attributes
     */
    protected static function postProcessMappedAttributes(
        array $mapped,
        ?string $statamicHandle,
        ?string $preset
    ): array {
        $processedMapped = [];
        foreach ($mapped as $key => $value) {
            $processedKey = self::replacePlaceholders(
                (string) $key,
                null,
                $statamicHandle,
                $preset
            );
            $processedMapped[$processedKey] = $value;
        }

        // Remove any attributes with empty keys
        return \array_filter(
            $processedMapped,
            fn ($key): bool => $key !== '',
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Process simple statamic_attribute with optional value_translation.
     *
     * @param  mixed  $attrValue  The attribute value
     * @param  array<int|string, mixed>  $attrConfig  The attribute configuration
     * @param  string  $statamicAttr  The statamic attribute name
     * @param  array<int|string, mixed>  $data  The full data array for context
     * @param  ?string  $attrName  The attribute name being processed
     * @return mixed The processed value
     */
    protected static function processSimpleStatamicAttribute(
        mixed $attrValue,
        array $attrConfig,
        string $statamicAttr,
        array $data = [],
        int|string|null $attrName = null
    ): mixed {
        // Apply value_translation if present
        if (isset($attrConfig['value_translation'])) {
            return static::applyValueTranslation(
                $attrValue,
                $attrConfig,
                $attrName,
                $data,
                null,
                null,
                $statamicAttr
            );
        }

        return $attrValue;
    }

    /**
     * Match a modifier value from based_on_other_attribute_value mappings.
     *
     * @param  mixed  $mappings  The other-attribute value maps
     * @param  array<int|string, mixed>  $data  The field data
     * @return mixed The matched value, or null when no match is found
     */
    private static function matchModifierOtherAttributeValue(
        mixed $mappings,
        array $data
    ): mixed {
        foreach (self::asArray($mappings) as $otherAttrName => $valueMap) {
            $normalizedOtherAttrName = self::normalizeArrayKey(
                $otherAttrName
            );
            if (
                $normalizedOtherAttrName === null
                || ! self::arrayKeyExists(
                    $normalizedOtherAttrName,
                    $data
                )
            ) {
                continue;
            }

            $otherAttrValue = $data[$normalizedOtherAttrName];
            $valueMapArray = self::asArray($valueMap);
            if (
                self::arrayKeyExists(
                    $otherAttrValue,
                    $valueMapArray
                )
            ) {
                return self::mappingValue(
                    $valueMapArray,
                    $otherAttrValue
                );
            }
        }

        return null;
    }

    /**
     * Process modifier patterns with the correct hierarchy order.
     *
     * This method eliminates duplicated logic across all pattern implementations
     * by providing a unified way to process the modifier hierarchy:
     *   1. based_on_attribute_value.specific
     *   2. based_on_attribute_value.default
     *   3. based_on_other_attribute_value.specific
     *   4. based_on_other_attribute_value.default
     *   5. specific
     *   6. default
     *
     * @param  mixed  $config  The configuration value to process (can be array, boolean, string, etc.)
     * @param  array<int|string, mixed>  $data  The field data to check for based_on_other_attribute_value conditions
     * @param  ?callable  $resultProcessor  Optional callback to process the result before returning
     * @param  ?string  $excludeFieldtype  The fieldtype to exclude
     * @param  ?string  $attrName  The attribute name for based_on_attribute_value checks
     * @param  mixed  $attrValue  The attribute value for based_on_attribute_value checks
     * @return array{value: mixed, matchFound: bool} Returns [value, matchFound] array
     */
    protected static function processModifierPatterns(
        mixed $config,
        array $data,
        ?callable $resultProcessor = null,
        ?string $excludeFieldtype = null,
        int|string|null $attrName = null,
        mixed $attrValue = null
    ): array {
        $value = false;
        $matchFound = false;

        // Handle non-array configurations (boolean, string, etc.)
        if (! \is_array($config)) {
            // Check if this is a string that refers to a field name in the data
            if (\is_string($config) && isset($data[$config])) {
                $value = (bool) $data[$config];
                $matchFound = true;
            } elseif (\is_string($config)) {
                // String field reference that doesn't exist - default to false
                $value = false;
                $matchFound = true;
            } else { // Otherwise
                // Non-string value (boolean, number, etc.)
                $value = $config;
                $matchFound = true;
            }

            // Apply result processor if provided
            if ($resultProcessor !== null) {
                $value = $resultProcessor($value);
            }

            return [
                'value' => $value,
                'matchFound' => $matchFound,
            ];
        }

        $configArray = self::mappingConfig($config);
        $basedOnAttributeValueConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'based_on_attribute_value'
            )
        );

        // 1. Check for based_on_attribute_value.specific (highest priority)
        if (
            self::arrayKeyExists(
                'specific',
                $basedOnAttributeValueConfig
            )
        ) {
            $basedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnAttributeValueConfig,
                    'specific'
                )
            );
            $specificBasedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnConfig,
                    'specific'
                )
            );

            // Check for specific fieldtype configuration
            if (
                $excludeFieldtype !== null
                && $excludeFieldtype !== ''
                && self::arrayKeyExists(
                    $excludeFieldtype,
                    $specificBasedOnConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificBasedOnConfig,
                        $excludeFieldtype
                    )
                );
                $result = self::processPatternMappings(
                    $fieldtypeMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    $value = $resultProcessor !== null ? $resultProcessor($result) : (bool) $result;
                    $matchFound = true;
                }
            }

            // Check for default configuration if no specific match
            if (
                ! $matchFound
                && self::arrayKeyExists(
                    'default',
                    $basedOnConfig
                )
            ) {
                $defaultPatternMappings = self::asArray(
                    self::mappingValue(
                        $basedOnConfig,
                        'default'
                    )
                );
                $result = self::processPatternMappings(
                    $defaultPatternMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    $value = $resultProcessor !== null ? $resultProcessor($result) : (bool) $result;
                    $matchFound = true;
                }
            }
        }

        // 2. Check for based_on_attribute_value.default (second priority)
        if (
            ! $matchFound
            && self::arrayKeyExists(
                'default',
                $basedOnAttributeValueConfig
            )
        ) {
            $basedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnAttributeValueConfig,
                    'default'
                )
            );
            $result = self::processPatternMappings(
                $basedOnConfig,
                $attrName,
                $attrValue,
                $data
            );
            if ($result !== null) {
                $value = $resultProcessor !== null ? $resultProcessor($result) : (bool) $result;
                $matchFound = true;
            }
        }

        $basedOnOtherAttributeValueConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'based_on_other_attribute_value'
            )
        );

        // 3. Check for based_on_other_attribute_value.specific (third priority)
        if (
            ! $matchFound
            && self::arrayKeyExists(
                'specific',
                $basedOnOtherAttributeValueConfig
            )
        ) {
            $basedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherAttributeValueConfig,
                    'specific'
                )
            );
            $specificBasedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherConfig,
                    'specific'
                )
            );

            // Check for specific fieldtype configuration
            if (
                $excludeFieldtype !== null
                && $excludeFieldtype !== ''
                && self::arrayKeyExists(
                    $excludeFieldtype,
                    $specificBasedOnOtherConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificBasedOnOtherConfig,
                        $excludeFieldtype
                    )
                );
                $matchedValue = self::matchModifierOtherAttributeValue(
                    $fieldtypeMappings,
                    $data
                );
                if ($matchedValue !== null) {
                    $value = $resultProcessor !== null ? $resultProcessor($matchedValue) : (bool) $matchedValue;
                    $matchFound = true;
                }
            }

            // Check for default configuration if no specific match
            if (
                ! $matchFound
                && self::arrayKeyExists(
                    'default',
                    $basedOnOtherConfig
                )
            ) {
                $defaultOtherMappings = self::asArray(
                    self::mappingValue(
                        $basedOnOtherConfig,
                        'default'
                    )
                );
                $matchedValue = self::matchModifierOtherAttributeValue(
                    $defaultOtherMappings,
                    $data
                );
                if ($matchedValue !== null) {
                    $value = $resultProcessor !== null ? $resultProcessor($matchedValue) : (bool) $matchedValue;
                    $matchFound = true;
                }
            }

            // Legacy structure - process each based_on_other_attribute_value configuration directly
            if (! $matchFound) {
                $matchedValue = self::matchModifierOtherAttributeValue(
                    $basedOnOtherConfig,
                    $data
                );
                if ($matchedValue !== null) {
                    $value = $resultProcessor !== null ? $resultProcessor($matchedValue) : (bool) $matchedValue;
                    $matchFound = true;
                }
            }
        }

        // 4. Check for based_on_other_attribute_value.default (fourth priority)
        if (
            ! $matchFound
            && self::arrayKeyExists(
                'default',
                $basedOnOtherAttributeValueConfig
            )
        ) {
            $basedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherAttributeValueConfig,
                    'default'
                )
            );
            $matchedValue = self::matchModifierOtherAttributeValue(
                $basedOnOtherConfig,
                $data
            );
            if ($matchedValue !== null) {
                $value = $resultProcessor !== null ? $resultProcessor($matchedValue) : (bool) $matchedValue;
                $matchFound = true;
            }
        }

        $specificConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'specific'
            )
        );

        // 5. Check for specific fieldtype configuration (fifth priority)
        if (
            ! $matchFound
            && $excludeFieldtype !== null
            && $excludeFieldtype !== ''
            && self::arrayKeyExists(
                $excludeFieldtype,
                $specificConfig
            )
        ) {
            $specificValue = self::mappingValue(
                $specificConfig,
                $excludeFieldtype
            );
            $value = $resultProcessor !== null ? $resultProcessor($specificValue) : (bool) $specificValue;
            $matchFound = true;
        }

        // 6. Check for default configuration (lowest priority)
        if (
            ! $matchFound
            && self::arrayKeyExists(
                'default',
                $configArray
            )
        ) {
            $defaultValue = self::mappingValue(
                $configArray,
                'default'
            );
            $value = $resultProcessor !== null ? $resultProcessor($defaultValue) : (bool) $defaultValue;
            $matchFound = true;
        }

        return [
            'value' => $value,
            'matchFound' => $matchFound,
        ];
    }

    /**
     * Process modifier patterns for return-based patterns (like getExpectedKeys).
     *
     * This method is similar to processModifierPatterns but returns the value
     * directly instead of a [value, matchFound] array, making it suitable for methods
     * that need to return early when a match is found.
     *
     * @param  array<int|string, mixed>|string  $config  The configuration array or legacy string containing the modifier patterns
     * @param  mixed  $attrValue  The attribute value for based_on_attribute_value checks
     * @param  array<int|string, mixed>  $data  The field data to check for based_on_other_attribute_value conditions
     * @param  mixed  $fallbackValue  The value to return if no match is found
     * @param  ?callable  $resultProcessor  Optional callback to process the result before returning
     * @param  ?string  $excludeFieldtype  The fieldtype to exclude
     * @param  ?string  $attrName  The attribute name for based_on_attribute_value checks
     * @return mixed Returns the matched value or fallback value
     */
    protected static function processModifierPatternsWithReturn(
        mixed $config,
        mixed $attrValue,
        array $data,
        mixed $fallbackValue,
        ?callable $resultProcessor = null,
        ?string $excludeFieldtype = null,
        int|string|null $attrName = null
    ): mixed {
        if (! \is_array($config)) {
            return $resultProcessor !== null
                ? $resultProcessor($config)
                : $config;
        }

        $configArray = self::mappingConfig($config);
        $basedOnAttributeValueConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'based_on_attribute_value'
            )
        );

        // 1. Check for based_on_attribute_value.specific (highest priority)
        if (
            self::arrayKeyExists(
                'specific',
                $basedOnAttributeValueConfig
            )
        ) {
            $basedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnAttributeValueConfig,
                    'specific'
                )
            );
            $specificBasedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnConfig,
                    'specific'
                )
            );

            // Check for specific fieldtype configuration
            if (
                $excludeFieldtype !== null
                && $excludeFieldtype !== ''
                && self::arrayKeyExists(
                    $excludeFieldtype,
                    $specificBasedOnConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificBasedOnConfig,
                        $excludeFieldtype
                    )
                );
                $result = self::processPatternMappings(
                    $fieldtypeMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    return $resultProcessor !== null ? $resultProcessor($result) : $result;
                }
            }

            // Check for default configuration if no specific match
            if (
                self::arrayKeyExists(
                    'default',
                    $basedOnConfig
                )
            ) {
                $defaultPatternMappings = self::asArray(
                    self::mappingValue(
                        $basedOnConfig,
                        'default'
                    )
                );
                $result = self::processPatternMappings(
                    $defaultPatternMappings,
                    $attrName,
                    $attrValue,
                    $data
                );
                if ($result !== null) {
                    return $resultProcessor !== null ? $resultProcessor($result) : $result;
                }
            }
        }

        // 2. Check for based_on_attribute_value.default (second priority)
        if (
            self::arrayKeyExists(
                'default',
                $basedOnAttributeValueConfig
            )
        ) {
            $basedOnConfig = self::asArray(
                self::mappingValue(
                    $basedOnAttributeValueConfig,
                    'default'
                )
            );
            $result = self::processPatternMappings(
                $basedOnConfig,
                $attrName,
                $attrValue,
                $data
            );
            if ($result !== null) {
                return $resultProcessor !== null ? $resultProcessor($result) : $result;
            }
        }

        $basedOnOtherAttributeValueConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'based_on_other_attribute_value'
            )
        );

        // 3. Check for based_on_other_attribute_value.specific (third priority)
        if (
            self::arrayKeyExists(
                'specific',
                $basedOnOtherAttributeValueConfig
            )
        ) {
            $basedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherAttributeValueConfig,
                    'specific'
                )
            );
            $specificBasedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherConfig,
                    'specific'
                )
            );

            // Check for specific fieldtype configuration
            if (
                $excludeFieldtype !== null
                && $excludeFieldtype !== ''
                && self::arrayKeyExists(
                    $excludeFieldtype,
                    $specificBasedOnOtherConfig
                )
            ) {
                $fieldtypeMappings = self::asArray(
                    self::mappingValue(
                        $specificBasedOnOtherConfig,
                        $excludeFieldtype
                    )
                );
                $matchedValue = self::matchModifierOtherAttributeValue(
                    $fieldtypeMappings,
                    $data
                );
                if ($matchedValue !== null) {
                    return $resultProcessor !== null ? $resultProcessor($matchedValue) : $matchedValue;
                }
            }

            // Check for default configuration if no specific match
            if (
                self::arrayKeyExists(
                    'default',
                    $basedOnOtherConfig
                )
            ) {
                $defaultOtherMappings = self::asArray(
                    self::mappingValue(
                        $basedOnOtherConfig,
                        'default'
                    )
                );
                $matchedValue = self::matchModifierOtherAttributeValue(
                    $defaultOtherMappings,
                    $data
                );
                if ($matchedValue !== null) {
                    return $resultProcessor !== null ? $resultProcessor($matchedValue) : $matchedValue;
                }
            }

            // Legacy structure - process each based_on_other_attribute_value configuration directly
            $matchedValue = self::matchModifierOtherAttributeValue(
                $basedOnOtherConfig,
                $data
            );
            if ($matchedValue !== null) {
                return $resultProcessor !== null ? $resultProcessor($matchedValue) : $matchedValue;
            }
        }

        // 4. Check for based_on_other_attribute_value.default (fourth priority)
        if (
            self::arrayKeyExists(
                'default',
                $basedOnOtherAttributeValueConfig
            )
        ) {
            $basedOnOtherConfig = self::asArray(
                self::mappingValue(
                    $basedOnOtherAttributeValueConfig,
                    'default'
                )
            );
            $matchedValue = self::matchModifierOtherAttributeValue(
                $basedOnOtherConfig,
                $data
            );
            if ($matchedValue !== null) {
                return $resultProcessor !== null ? $resultProcessor($matchedValue) : $matchedValue;
            }
        }

        $specificConfig = self::asArray(
            self::mappingValue(
                $configArray,
                'specific'
            )
        );

        // 5. Check for specific fieldtype configuration (fifth priority)
        if (
            $excludeFieldtype !== null
            && $excludeFieldtype !== ''
            && self::arrayKeyExists(
                $excludeFieldtype,
                $specificConfig
            )
        ) {
            $specificValue = self::mappingValue(
                $specificConfig,
                $excludeFieldtype
            );

            return $resultProcessor !== null ? $resultProcessor($specificValue) : $specificValue;
        }

        // 6. Check for default configuration (lowest priority)
        if (
            self::arrayKeyExists(
                'default',
                $configArray
            )
        ) {
            $defaultValue = self::mappingValue(
                $configArray,
                'default'
            );

            return $resultProcessor !== null ? $resultProcessor($defaultValue) : $defaultValue;
        }

        return $fallbackValue;
    }

    /**
     * Check if there's a direct array replacement in value_translation configuration.
     *
     * This method checks if the entire array value should be replaced with a specific
     * value based on the value_translation configuration, before processing
     * individual elements.
     *
     * @param  mixed  $value  The value to check (should be an array)
     * @param  array<int|string, mixed>  $translationConfig  The value translation configuration
     * @return mixed|false The replacement value if found, false otherwise
     */
    protected static function checkForArrayReplacement(
        mixed $value,
        array $translationConfig
    ): mixed {
        if (! \is_array($value)) {
            return false;
        }

        // Check for direct array replacement in default configuration
        if (
            self::arrayKeyExists(
                'default',
                $translationConfig
            )
        ) {
            foreach (
                self::asArray(
                    self::mappingValue(
                        $translationConfig,
                        'default'
                    )
                ) as $translation
            ) {
                $translationArray = self::asArray($translation);
                $search = self::mappingValue(
                    $translationArray,
                    'search'
                );
                if (
                    self::arrayKeyExists(
                        'replace',
                        $translationArray
                    )
                    && \is_array($search)
                    && $search === $value
                ) {
                    return self::mappingValue(
                        $translationArray,
                        'replace'
                    );
                }
            }
        }

        // Check for direct array replacement when translationConfig is directly an array of translations
        if (
            ! self::arrayKeyExists(
                'default',
                $translationConfig
            )
            && ! self::arrayKeyExists(
                'specific',
                $translationConfig
            )
        ) {
            foreach (self::asArray($translationConfig) as $translation) {
                $translationArray = self::asArray($translation);
                $search = self::mappingValue(
                    $translationArray,
                    'search'
                );
                if (
                    self::arrayKeyExists(
                        'replace',
                        $translationArray
                    )
                    && \is_array($search)
                    && $search === $value
                ) {
                    return self::mappingValue(
                        $translationArray,
                        'replace'
                    );
                }
            }
        }

        // Check for direct array replacement in specific configuration
        if (
            self::arrayKeyExists(
                'specific',
                $translationConfig
            )
        ) {
            foreach (
                self::asArray(
                    self::mappingValue(
                        $translationConfig,
                        'specific'
                    )
                ) as $specificConfig
            ) {
                foreach (self::asArray($specificConfig) as $translation) {
                    $translationArray = self::asArray($translation);
                    $search = self::mappingValue(
                        $translationArray,
                        'search'
                    );
                    if (
                        self::arrayKeyExists(
                            'replace',
                            $translationArray
                        )
                        && \is_array($search)
                        && $search === $value
                    ) {
                        return self::mappingValue(
                            $translationArray,
                            'replace'
                        );
                    }
                }
            }
        }

        return false;
    }

    /**
     * Normalize mixed values into a safe array shape.
     *
     * @param  mixed  $value  The candidate value
     * @return array<int|string, mixed> The normalized array value
     */
    protected static function normalizeMixedToArray(
        mixed $value
    ): array {
        return \is_array($value) ? $value : [];
    }

    /**
     * Resolve a mixed fieldtype mapping result to string|null|false.
     *
     * @param  mixed  $value  The candidate mapping result
     * @return string|null|false The resolved fieldtype, null, or false when suppressed
     */
    protected static function resolveFieldtypeMappingValue(
        mixed $value
    ): string|null|false {
        if ($value === false || $value === null) {
            return $value;
        }

        return self::stringValue($value);
    }

    /**
     * Resolve a mixed mapping value to an optional string.
     *
     * @param  mixed  $value  The candidate mapping result
     * @return ?string The resolved string value, or null when not a string
     */
    protected static function resolveOptionalStringMappingValue(
        mixed $value
    ): ?string {
        return self::stringValue($value);
    }

    /**
     * Resolve the active conditional mapping block from a default/specific config.
     *
     * @param  array<int|string, mixed>  $basedOnConfig  The conditional mapping config
     * @param  string  $type  The current fieldtype
     * @return array<int|string, mixed> The resolved mapping block to evaluate
     */
    protected static function resolveStructuredConditionalConfig(
        array $basedOnConfig,
        string $type
    ): array {
        $defaultBasedOnConfig = self::asArray(
            self::mappingValue($basedOnConfig, 'default')
        );
        $specificBasedOnConfig = self::asArray(
            self::mappingValue($basedOnConfig, 'specific')
        );
        $typeSpecificConfig = self::mappingValue(
            $specificBasedOnConfig,
            $type
        );

        if ($typeSpecificConfig !== null) {
            return self::asArray($typeSpecificConfig);
        }

        return $defaultBasedOnConfig;
    }

    /**
     * Resolve a string mapping from a conditional attribute-value config block.
     *
     * @param  array<int|string, mixed>  $configToUse  Attribute-name keyed mapping rules
     * @param  array<int|string, mixed>  $parsedField  Parsed field data
     * @return ?string Resolved string mapping value or null when unmatched
     */
    protected static function resolveStringMappingFromConditionalConfig(
        array $configToUse,
        array $parsedField
    ): ?string {
        foreach ($configToUse as $otherAttrName => $valueMappings) {
            $normalizedOtherAttrName = self::normalizeArrayKey($otherAttrName);
            if ($normalizedOtherAttrName === null) {
                continue;
            }

            $valueMappingsArray = self::asArray($valueMappings);
            $otherAttrValue = self::mappingValue(
                $parsedField,
                $normalizedOtherAttrName
            );

            if (self::arrayKeyExists($otherAttrValue, $valueMappingsArray)) {
                return self::resolveOptionalStringMappingValue(
                    self::mappingValue(
                        $valueMappingsArray,
                        $otherAttrValue
                    )
                );
            }

            if (
                self::arrayKeyExists(
                    '#not_set',
                    $valueMappingsArray
                )
                && ! self::arrayKeyExists(
                    $normalizedOtherAttrName,
                    $parsedField
                )
            ) {
                return self::resolveOptionalStringMappingValue(
                    self::mappingValue(
                        $valueMappingsArray,
                        '#not_set'
                    )
                );
            }

            if (
                self::arrayKeyExists(
                    '#not_empty',
                    $valueMappingsArray
                )
                && (! in_array($otherAttrValue, [null, '', []], true))
            ) {
                return self::resolveOptionalStringMappingValue(
                    self::mappingValue(
                        $valueMappingsArray,
                        '#not_empty'
                    )
                );
            }

            if (
                self::arrayKeyExists(
                    '',
                    $valueMappingsArray
                )
                && $otherAttrValue === ''
            ) {
                return self::resolveOptionalStringMappingValue(
                    self::mappingValue(
                        $valueMappingsArray,
                        ''
                    )
                );
            }
        }

        return null;
    }

    /**
     * Normalize a transformation lookup key to a string.
     *
     * @param  int|string  $key  The candidate transformation key
     * @return string The normalized transformation key
     */
    protected static function normalizeTransformationKey(
        int|string $key
    ): string {
        return \is_string($key) ? $key : (string) $key;
    }

    /**
     * Normalize mixed values into a safe string representation.
     *
     * @param  mixed  $value  The candidate value
     * @return string The normalized string value
     */
    protected static function normalizeMixedToString(
        mixed $value
    ): string {
        if (\is_string($value)) {
            return $value;
        }

        if (
            \is_int($value)
            || \is_float($value)
            || \is_bool($value)
        ) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Normalize resolved transformation values to the expected array config shape.
     *
     * @param  mixed  $transformationValue  The resolved value
     * @param  string  $key  The target transformation key
     * @return array<int|string, mixed>|null The normalized configuration
     */
    protected static function normalizeTransformationConfig(
        mixed $transformationValue,
        string $key
    ): ?array {
        if (\is_array($transformationValue)) {
            return $transformationValue;
        }

        if ($transformationValue === null) {
            return null;
        }

        return [$key => $transformationValue];
    }

    /**
     * Normalize mixed keys to supported array assignment key types.
     *
     * @param  mixed  $key  The candidate key
     * @return int|string The normalized key for array assignment
     */
    protected static function normalizeArrayKeyForAssignment(
        mixed $key
    ): int|string {
        $normalizedKey = self::normalizeArrayKey($key);
        if ($normalizedKey !== null) {
            return $normalizedKey;
        }

        return self::normalizeMixedToString($key);
    }

    /**
     * Determine whether an array contains a given key.
     *
     * @param  mixed  $key  The candidate array key
     * @param  mixed  $array  The candidate array
     */
    protected static function arrayKeyExists(
        mixed $key,
        mixed $array
    ): bool {
        $normalizedKey = self::normalizeArrayKey($key);

        if (
            $normalizedKey === null
            || ! \is_array($array)
        ) {
            return false;
        }

        return \array_key_exists(
            $normalizedKey,
            $array
        );
    }

    /**
     * Normalize candidate array keys for safe lookup operations.
     *
     * @param  mixed  $key  The candidate key value
     * @return int|string|null The normalized key, or null when unsupported
     */
    protected static function normalizeArrayKey(
        mixed $key
    ): int|string|null {
        if (
            \is_int($key)
            || \is_string($key)
        ) {
            return $key;
        }

        if (
            \is_bool($key)
            || \is_float($key)
        ) {
            return (string) $key;
        }

        return null;
    }

    /**
     * Resolve a value-translation mapping lookup key for scalar or array values.
     *
     * @param  mixed  $value  The mapped attribute value
     */
    private static function mappingLookupKey(mixed $value): int|string
    {
        if (\is_array($value)) {
            return (string) \json_encode($value);
        }

        if (\is_int($value)) {
            return $value;
        }

        return self::normalizeMixedToString($value);
    }

    /**
     * Convert a foreach index to a 1-based numeric count for transformations.
     *
     * @param  int|string  $index  The array index from foreach
     * @return ?int The 1-based count, or null when the index is not numeric
     */
    private static function numericChoiceCount(
        int|string $index
    ): ?int {
        if (\is_int($index)) {
            return $index + 1;
        }

        if (\is_numeric($index)) {
            return (int) $index + 1;
        }

        return null;
    }
}
