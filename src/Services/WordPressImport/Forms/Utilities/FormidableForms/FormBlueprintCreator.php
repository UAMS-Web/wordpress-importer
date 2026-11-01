<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormBlueprintCreatorTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Creates basic blueprints for imported Formidable Forms.
 *
 * This class is responsible for creating and saving basic blueprints for forms
 * imported from Formidable Forms, with Formidable Forms-specific field  name
 * handling.
 */
class FormBlueprintCreator
{
    use FormBlueprintCreatorTrait;

    /**
     * Get the field name to use for handle generation.
     * Formidable Forms uses 'name' field.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return string The field name for handle generation
     */
    protected function getFieldNameForHandle(
        array $field
    ): string {
        $fieldName = $field['name'] ?? null;

        return \is_string($fieldName) ? $fieldName : '';
    }

    /**
     * Get the field name to use for display name generation.
     * Formidable Forms uses 'name' field.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return string The field name for display
     */
    protected function getFieldNameForDisplay(
        array $field
    ): string {
        $fieldName = $field['name'] ?? null;

        return \is_string($fieldName) ? $fieldName : '';
    }

    /**
     * Get mapped field attributes using the Formidable Forms mapping class.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $pluginName  The form plugin name
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for conditional logic mapping
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     * @param  ?string  $siteHandle  The Statamic site handle for placeholder replacement
     * @return array<int|string, mixed> The mapped Statamic field attributes
     */
    protected function getMappedFieldAttributes(
        array $field,
        string $pluginName,
        array $allFields = [],
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null,
        ?string $siteHandle = null
    ): array {
        // Get mapped attributes from Formidable Forms mapping class
        $mappedAttributes = FieldtypeMapping::mapFieldAttributesWithContext(
            $field,
            $formTitle,
            $fieldLabel,
            $formId !== null && $formId !== '' ? (int) $formId : null,
            $siteHandle
        );

        // Get mapped field options attributes
        $mappedFieldOptions = FieldtypeMapping::mapFieldOptions(
            $field,
            $allFields,
            null,
            $siteHandle
        );

        // Merge both sets of attributes
        $mergedAttributes = \array_merge(
            $mappedAttributes,
            $mappedFieldOptions
        );

        // Handle repeater fields (divider with repeat=1)
        $repeaterAttributes = [];
        if ($field['type'] === 'divider' && $this->isRepeaterField($field)) {
            // The two repeater lookups read DIFFERENT scopes and must not share one
            // haystack. `button_label` comes from the `end_divider` beside this divider
            // in its own form, which is `$allFields`; the set fields come from the child
            // form, which lives in a sibling form and is reachable only through
            // `$this->sourceForms` (wordpress-importer-archive#1158, the Formidable instance of wordpress-importer-archive#1156).
            $repeaterAttributes = $this->getRepeaterAttributes(
                $field,
                $allFields,
                $this->extractAllFieldsFromForms($this->sourceForms ?? []),
                $formTitle,
                $fieldLabel,
                $formId
            );
            $mergedAttributes = \array_merge(
                $mergedAttributes,
                $repeaterAttributes
            );
        }

        // Clean up variables to free up memory
        unset(
            $mappedAttributes,
            $mappedFieldOptions,
            $repeaterAttributes
        );

        return $mergedAttributes;
    }

    /**
     * Check if a divider field is a repeater field.
     *
     * @param  array<int|string, mixed>  $field  The field data
     * @return bool True if the field is a repeater, false otherwise
     */
    private function isRepeaterField(
        array $field
    ): bool {
        // Check if field_options contains repeat=1
        if (
            isset($field['field_options'])
            && \is_string($field['field_options'])
        ) {
            $fieldOptions = \json_decode(
                $field['field_options'],
                true
            );
            if (
                \is_array($fieldOptions)
                && isset($fieldOptions['repeat'])
                && $fieldOptions['repeat'] === '1'
            ) {
                // Clean up variables to free up memory
                unset($fieldOptions);

                return true;
            }

            // Clean up variables to free up memory
            unset($fieldOptions);
        }

        return false;
    }

    /**
     * Get repeater-specific attributes from the corresponding end_divider field.
     *
     * @param  array<int|string, mixed>  $dividerField  The divider field data
     * @param  array<int|string, mixed>  $parentFormFields  This divider's OWN form's fields,
     *                                                      where its `end_divider` sits
     * @param  array<int|string, mixed>  $siblingFormFields  Every field across every exported
     *                                                       form, where the child form's are
     * @param  ?string  $formTitle  The parent form title, for warning context
     * @param  ?string  $fieldLabel  The repeater field label, for warning context
     * @param  ?string  $formId  The parent form ID, for warning context
     * @return array<int|string, mixed> The repeater attributes
     */
    private function getRepeaterAttributes(
        array $dividerField,
        array $parentFormFields,
        array $siblingFormFields,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): array {
        $repeaterAttributes = [];

        // Find the corresponding end_divider field, which sits beside this divider in
        // its OWN form rather than in a sibling one
        $endDividerField = $this->findEndDividerField(
            $dividerField,
            $parentFormFields
        );

        if ($endDividerField !== null) {
            // Extract add_label from end_divider field options
            if (
                isset($endDividerField['field_options']) &&
                \is_string($endDividerField['field_options'])
            ) {
                $fieldOptions = \json_decode(
                    $endDividerField['field_options'],
                    true
                );
                if (
                    \is_array($fieldOptions) &&
                    isset($fieldOptions['add_label'])
                ) {
                    $repeaterAttributes['button_label'] = $fieldOptions['add_label'];
                }

                // Clean up variables to free up memory
                unset($fieldOptions);
            }
        }

        // Create sets structure from child form fields, which live in a SIBLING form
        $repeaterAttributes['sets'] = $this->createRepeaterSets(
            $dividerField,
            $siblingFormFields,
            $formTitle,
            $fieldLabel,
            $formId
        );

        // Clean up variables to free up memory
        unset($endDividerField);

        return $repeaterAttributes;
    }

    /**
     * Report a repeater whose child form could not be resolved.
     *
     * Mirrors the Gravity path's `unresolved_nested_forms` warning, deliberately reusing
     * that category and type rather than inventing a Formidable-specific pair: the two
     * defects are the same one in two plugins, an operator triaging either wants them in
     * one place, and `ImportWarningDisplayService` renders both through the same fallback
     * branch, which names every datum below.
     *
     * Both failure branches report, because a `replicator` with no sets is valid Statamic
     * and so silent in both directions — the property that let this survive since the
     * repeater path was written.
     *
     * @param  ?string  $childFormId  The child form id named by the divider, or null when it named none
     * @param  string  $reason  Why resolution failed, in a form an operator can act on
     * @param  ?string  $formTitle  The parent form title
     * @param  ?string  $fieldLabel  The repeater field label
     * @param  ?string  $formId  The parent form ID
     */
    private function warnRepeaterChildUnresolved(
        ?string $childFormId,
        string $reason,
        ?string $formTitle,
        ?string $fieldLabel,
        ?string $formId
    ): void {
        ImportWarningManager::addWarning(
            'forms',
            'unresolved_nested_forms',
            [
                'form_id' => $formId,
                'form_title' => $formTitle,
                'field_label' => $fieldLabel,
                'child_form_id' => $childFormId,
                'reason' => $reason,
                'consequence' => 'the repeater imported with no sets and will collect nothing',
            ]
        );
    }

    /**
     * Extract all fields from all forms in the source array.
     *
     * **Takes FORMS, not fields.** Handing it a flat array of one form's fields is
     * silently empty rather than an error: `$form['fields']` is set on no element, so
     * every iteration skips and the result is `[]`. The repeater path then finds no child
     * form and writes empty sets, which is valid Statamic and reports nothing. That is
     * exactly how wordpress-importer-archive#1158 happened — the caller passed `$allFields`, this form's own
     * fields — so the parameter is named for what it must receive and the one call site
     * reads `$this->sourceForms`.
     *
     * @param  array<int|string, mixed>  $forms  Array of forms (each containing a 'fields' array)
     * @return array<int|string, mixed> Array of all fields from all forms
     */
    private function extractAllFieldsFromForms(
        array $forms
    ): array {
        $allFields = [];

        foreach ($forms as $form) {
            if (! \is_array($form)) {
                continue;
            }

            if (isset($form['fields']) && \is_array($form['fields'])) {
                $allFields = \array_merge(
                    $allFields,
                    $form['fields']
                );
            }
        }

        return $allFields;
    }

    /**
     * Create the sets structure for a repeater field based on child form fields.
     *
     * @param  array<int|string, mixed>  $dividerField  The divider field data
     * @param  array<int|string, mixed>  $siblingFormFields  Every field across every exported
     *                                                       form; the child form's fields are
     *                                                       selected from these by `form_id`
     * @param  ?string  $formTitle  The parent form title, for warning context
     * @param  ?string  $fieldLabel  The repeater field label, for warning context
     * @param  ?string  $formId  The parent form ID, for warning context
     * @return array<int|string, mixed> The sets structure for the replicator field
     */
    private function createRepeaterSets(
        array $dividerField,
        array $siblingFormFields,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): array {
        // Get the child form ID from the divider field's form_select option
        $childFormId = $this->getChildFormId($dividerField);

        if ($childFormId === null) {
            // A repeater naming no child form yields a replicator with no sets, which is
            // valid Statamic and therefore silent in both directions. Report it.
            $this->warnRepeaterChildUnresolved(
                null,
                'The repeater names no child form.',
                $formTitle,
                $fieldLabel,
                $formId
            );

            return [];
        }

        // Find the child form fields
        $childFormFields = $this->getChildFormFields(
            $childFormId,
            $siblingFormFields
        );

        if ($childFormFields === []) {
            // The child form was named but is not in the export, or carries no fields.
            $this->warnRepeaterChildUnresolved(
                $childFormId,
                'The child form was not found among the exported forms, or carries no fields.',
                $formTitle,
                $fieldLabel,
                $formId
            );

            // The loop below over no fields builds no sets and returns the same empty
            // array, so this return only saves the walk.
            // @pest-mutate-ignore: RemoveEarlyReturn
            return [];
        }

        // Create the sets structure from child form fields
        $sets = [];
        $setFields = [];

        foreach ($childFormFields as $childField) {
            // Skip certain field types that shouldn't be in repeater sets. Any default
            // but the two marker names skips nothing, so the empty default is one choice
            // among equivalent ones.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            if (
                \in_array(
                    $childField['type'] ?? '',
                    ['divider', 'end_divider'],
                    true
                )
            ) {
                continue;
            }

            // Generate field handle and configuration
            $fieldHandle = $this->generateFieldHandle($childField);
            $fieldConfig = $this->createBlueprintField(
                $childField,
                'formidable-forms'
            );

            if ($fieldConfig !== null) {
                $setFields[] = [
                    'handle' => $fieldHandle,
                    'field' => $fieldConfig,
                ];
            }
        }

        // Only create sets structure if we have fields
        if ($setFields !== []) {
            $sets['new_set_group'] = [
                'display' => 'New Set Group',
                'sets' => [
                    'new_set' => [
                        'display' => 'New Set',
                        'fields' => $setFields,
                    ],
                ],
            ];
        }

        // Clean up variables to free up memory
        unset(
            $childFormId,
            $childFormFields,
            $setFields
        );

        return $sets;
    }

    /**
     * Get the child form ID from the divider field's form_select option.
     *
     * @param  array<int|string, mixed>  $dividerField  The divider field data
     * @return ?string The child form ID or null if not found
     */
    private function getChildFormId(
        array $dividerField
    ): ?string {
        if (
            isset($dividerField['field_options']) &&
            \is_string($dividerField['field_options'])
        ) {
            $fieldOptions = \json_decode(
                $dividerField['field_options'],
                true
            );
            if (
                \is_array($fieldOptions) &&
                isset($fieldOptions['form_select'])
            ) {
                $childFormId = $fieldOptions['form_select'];

                // Skip non-scalar child form IDs.
                if (! \is_scalar($childFormId)) {
                    unset($fieldOptions);

                    return null;
                }

                // Clean up variables to free up memory
                unset($fieldOptions);

                return (string) $childFormId;
            }

            // Clean up variables to free up memory
            unset($fieldOptions);
        }

        return null;
    }

    /**
     * Get fields from a child form.
     *
     * @param  string  $childFormId  The child form ID
     * @param  array<int|string, mixed>  $allFields  Array of all form fields
     * @return list<array<int|string, mixed>> Array of child form fields
     */
    private function getChildFormFields(
        string $childFormId,
        array $allFields
    ): array {
        $childFields = [];

        foreach ($allFields as $field) {
            if (! \is_array($field)) {
                continue;
            }

            $formId = $field['form_id'] ?? null;
            if (
                \is_scalar($formId)
                && (string) $formId === $childFormId
            ) {
                $childFields[] = $field;
            }
        }

        return $childFields;
    }

    /**
     * Find the corresponding end_divider field for a divider field.
     *
     * @param  array<int|string, mixed>  $dividerField  The divider field data
     * @param  array<int|string, mixed>  $allFields  Array of all form fields
     * @return array<int|string, mixed>|null The end_divider field or null if not found
     */
    private function findEndDividerField(
        array $dividerField,
        array $allFields
    ): ?array {
        $dividerFormId = $dividerField['form_id'] ?? null;
        $dividerFieldOrder = \is_scalar($dividerField['field_order'] ?? null)
            ? (int) $dividerField['field_order']
            : 0;

        foreach ($allFields as $field) {
            // Skip values that are not valid field arrays.
            if (! \is_array($field)) {
                continue;
            }

            $fieldType = $field['type'] ?? null;
            $fieldFormId = $field['form_id'] ?? null;

            // Check if this is an end_divider field in the same form
            if (
                \is_string($fieldType) &&
                $fieldType === 'end_divider' &&
                \is_scalar($fieldFormId) &&
                \is_scalar($dividerFormId) &&
                (string) $fieldFormId === (string) $dividerFormId
            ) {
                $endDividerFieldOrder = \is_scalar($field['field_order'] ?? null)
                    ? (int) $field['field_order']
                    : 0;

                // The end_divider should come after the divider
                if ($endDividerFieldOrder > $dividerFieldOrder) {
                    // Clean up variables to free up memory
                    unset(
                        $dividerFormId,
                        $dividerFieldOrder,
                        $endDividerFieldOrder
                    );

                    return $field;
                }
            }
        }

        // Clean up variables to free up memory
        unset(
            $dividerFormId,
            $dividerFieldOrder
        );

        return null;
    }

    /**
     * Get mapped subfield attributes.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $pluginName  The form plugin name
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for context
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     * @return array<int|string, mixed> The mapped subfield attributes
     */
    protected function getMappedSubfieldAttributes(
        array $field,
        string $pluginName,
        array $allFields = [],
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): array {
        // For now, return empty array as subfield mapping is not implemented for Formidable Forms
        return [];
    }

    /**
     * Get the name of the attribute that indicates if a field is required.
     * Formidable Forms uses 'required' attribute.
     *
     * @return string The attribute name
     */
    protected function getRequiredAttributeName(): string
    {
        return 'required';
    }
}
