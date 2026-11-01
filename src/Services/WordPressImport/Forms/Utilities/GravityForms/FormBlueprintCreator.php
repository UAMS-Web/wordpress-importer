<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormBlueprintCreatorTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;

/**
 * Creates basic blueprints for imported Gravity Forms.
 *
 * This class is responsible for creating and saving basic blueprints for forms
 * imported from Gravity Forms, with Gravity Forms-specific field name handling.
 */
class FormBlueprintCreator
{
    use FormBlueprintCreatorTrait;

    /**
     * Get the field name to use for handle generation.
     * Gravity Forms uses 'label' field.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return string The field name for handle generation
     */
    protected function getFieldNameForHandle(
        array $field
    ): string {
        $label = $field['label'] ?? null;

        return \is_string($label) ? $label : '';
    }

    /**
     * Get the field name to use for display name generation.
     * Gravity Forms uses 'label' field.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return string The field name for display
     */
    protected function getFieldNameForDisplay(
        array $field
    ): string {
        $label = $field['label'] ?? null;

        return \is_string($label) ? $label : '';
    }

    /**
     * Get mapped field attributes using the Gravity Forms mapping class.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $pluginName  The form plugin name
     * @param  array<int|string, mixed>  $allFields  THIS form's fields, flat — what conditional
     *                                               logic and the product/option lookup resolve
     *                                               against. It does NOT contain any other form's
     *                                               fields, which is why the nested-form path reads
     *                                               `$this->sourceForms` instead (wordpress-importer-archive#1156)
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
        // Get mapped attributes from Gravity Forms mapping class
        $mappedAttributes = FieldtypeMapping::mapFieldAttributes(
            $field,
            $allFields,
            null,
            $siteHandle
        );
        $mappedAttributesWithContext = FieldtypeMapping::mapFieldAttributesWithContext(
            $field,
            $formTitle,
            $fieldLabel,
            $formId !== null && $formId !== '' ? (int) $formId : null,
            $siteHandle
        );

        // Merge conditional logic with context attributes
        $mergedAttributes = \array_merge(
            $mappedAttributesWithContext,
            $mappedAttributes
        );

        // ---
        // Handle form fields (nested forms) that map to replicator fieldtype.
        //
        // **Read the SIBLING FORMS here, never `$allFields`.** A Gravity Perks nested-form
        // field names another form by id in `gpnfForm`, and that form's fields are not in
        // the parent's field array at all — they carry a different `formId`. Passing
        // `$allFields` handed a flat array of FIELDS to `extractAllFieldsFromForms()`,
        // which looks for `$form['fields']`; that key was set on nothing, so it returned
        // `[]`, `getChildFormFields()` matched nothing, and the replicator was written
        // with empty sets (wordpress-importer-archive#1156).
        //
        // The failure was silent in both directions: a `replicator` with no sets is legal
        // Statamic and the form imported cleanly, so only a submission revealed that the
        // nested section captured nothing.
        // ---
        $replicatorAttributes = [];
        if ($field['type'] === 'form') {
            // Extract all fields from all forms in the source array
            $extractedFields = $this->extractAllFieldsFromForms($this->sourceForms ?? []);
            $replicatorAttributes = $this->getReplicatorAttributes(
                $field,
                $extractedFields,
                $formTitle,
                $fieldLabel,
                $formId
            );
            $mergedAttributes = \array_merge(
                $mergedAttributes,
                $replicatorAttributes
            );

            // Clean up variables to free up memory
            unset($extractedFields);
        }

        // Resolve the `option` field's parent Product reference, which Gravity Forms
        // stores as a numeric field id while the Statamic fieldtype expects a handle.
        //
        // The handle MUST come from generateFieldHandle(), the same generator that
        // writes the Product field into the blueprint. An independent
        // slug/handle implementation diverges on any label the two normalize
        // differently — `Children's Ticket` becomes `childrens_ticket_44` in the
        // blueprint but `children_s_ticket_44` under the mapping trait's
        // `preg_replace('/[^a-z0-9_]/', '_')`, and an accented label diverges the
        // same way — leaving product_field pointing at a handle that does not exist
        // and the price deltas silently inert. wordpress-importer-archive#302
        if ($field['type'] === 'option') {
            $productField = $this->findGravityFieldById(
                $field['productField'] ?? null,
                $allFields
            );

            if ($productField !== null) {
                $mergedAttributes['product_field'] = $this->generateFieldHandle($productField);
            }
        }

        // A Gravity Forms upload field accepts exactly one file unless
        // `multipleFiles` is truthy, so cap it explicitly.
        //
        // The declarative `maxFiles` translation cannot cover this. It only runs for
        // an attribute actually present in the field data, and its branches are keyed
        // on `'true'`/`'false'`/`1`/`0` — but the corpus also serializes an unset
        // toggle as `''` (96 upload fields across three exports), usually with no
        // `maxFiles` key at all. Those resolve no branch, so `max_files` was never
        // emitted and the imported assets field accepted unlimited uploads. wordpress-importer-archive#470
        if (\in_array($field['type'], ['fileupload', 'post_image'], true)) {
            $multipleFiles = $field['multipleFiles'] ?? null;

            // Gravity Forms evaluates this for truthiness, so '', '0', 0, false and
            // an absent key all mean a single-file field.
            if (! \filter_var($multipleFiles, \FILTER_VALIDATE_BOOLEAN)) {
                $mergedAttributes['max_files'] = 1;
            }
        }

        // Report a Gravity Forms product mode the Statamic fieldtype cannot express,
        // rather than letting the field import with no selection_type at all. wordpress-importer-archive#299
        if ($field['type'] === 'product') {
            FieldtypeMapping::addUnsupportedProductModeWarning(
                $field,
                $formTitle,
                $fieldLabel,
                $formId
            );
        }

        // Clean up variables to free up memory
        unset(
            $mappedAttributes,
            $mappedAttributesWithContext,
            $replicatorAttributes,
        );

        return $mergedAttributes;
    }

    /**
     * Find a Gravity Forms field on this form by its numeric id.
     *
     * @param  mixed  $fieldId  The referenced field id, as Gravity Forms stores it
     * @param  array<int|string, mixed>  $allFields  All fields on the form
     * @return ?array<int|string, mixed> The referenced field, or null when unresolvable
     */
    private function findGravityFieldById(
        mixed $fieldId,
        array $allFields
    ): ?array {
        if (! \is_scalar($fieldId)) {
            return null;
        }

        $needle = (string) $fieldId;

        if ($needle === '') {
            return null;
        }

        foreach ($allFields as $candidate) {
            if (! \is_array($candidate)) {
                continue;
            }

            $candidateId = $candidate['id'] ?? null;
            if (\is_scalar($candidateId) && (string) $candidateId === $needle) {
                return $candidate;
            }
        }

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
        // Check if the field has subfields (inputs). Non-composite Gravity Forms
        // fields omit the 'inputs' key entirely, so guard the access with isset()
        // first — mirroring the sibling FormConfigurationManager check.
        if (! isset($field['inputs']) || ! \is_array($field['inputs']) || $field['inputs'] === []) {
            return [];
        }

        $combinedSubfieldAttributes = [];

        // Process each subfield individually
        foreach ($field['inputs'] as $subfieldData) {
            if (! \is_array($subfieldData)) {
                continue;
            }

            $fieldType = $field['type'] ?? '';
            $subfieldAttributes = FieldtypeMapping::mapSubfieldAttributes(
                \is_string($fieldType) ? $fieldType : '',
                $subfieldData,
                $field,
                $pluginName,
                $allFields
            );

            // Merge the subfield attributes into the combined result
            if ($subfieldAttributes !== []) {
                $combinedSubfieldAttributes = \array_merge(
                    $combinedSubfieldAttributes,
                    $subfieldAttributes
                );
            }
        }

        return $combinedSubfieldAttributes;
    }

    /**
     * Get the name of the attribute that indicates if a field is required.
     * Gravity Forms uses 'isRequired' attribute.
     *
     * @return string The attribute name
     */
    protected function getRequiredAttributeName(): string
    {
        return 'isRequired';
    }

    /**
     * Report a nested-form field whose child form could not be resolved.
     *
     * Uses the `forms` category with a type that has no bespoke formatter, so
     * `ImportWarningDisplayService` renders it through its fallback branch. That is a
     * deliberate choice rather than an omission: the generic render names the category,
     * the type and every datum below, which is enough for an operator to find the form,
     * and a bespoke formatter is a larger change than this defect warrants.
     *
     * @param  ?string  $childFormId  The child form id named by the field, or null when it named none
     * @param  string  $reason  Why resolution failed, in a form an operator can act on
     * @param  ?string  $formTitle  The parent form title
     * @param  ?string  $fieldLabel  The nested-form field label
     * @param  ?string  $formId  The parent form ID
     */
    private function warnNestedFormUnresolved(
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
                'consequence' => 'the nested section imported with no fields and will collect nothing',
            ]
        );
    }

    /**
     * Extract all fields from all forms in the source array.
     *
     * **Takes FORMS, not fields.** Handing it a flat array of fields is silently empty
     * rather than an error — `$form['fields']` is set on no element, so every iteration
     * skips and the result is `[]`. That is exactly how wordpress-importer-archive#1156 happened, so the parameter
     * is named for what it must receive and the one call site reads `$this->sourceForms`.
     *
     * @param  array<int|string, mixed>  $forms  Array of forms (each containing a 'fields' array)
     * @return array<int|string, mixed> Array of all fields from all forms, each retaining its `formId`
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
     * Get replicator-specific attributes from the form field.
     *
     * @param  array<int|string, mixed>  $formField  The form field data
     * @param  array<int|string, mixed>  $siblingFields  Every field of every form in the export,
     *                                                   already flattened out of their forms, each
     *                                                   still carrying its own `formId`
     * @param  ?string  $formTitle  The parent form title for warning context
     * @param  ?string  $fieldLabel  The nested-form field label for warning context
     * @param  ?string  $formId  The parent form ID for warning context
     * @return array<int|string, mixed> The replicator attributes
     */
    private function getReplicatorAttributes(array $formField, array $siblingFields, ?string $formTitle = null, ?string $fieldLabel = null, ?string $formId = null): array
    {
        return ['sets' => $this->createReplicatorSets(
            $formField,
            $siblingFields,
            $formTitle,
            $fieldLabel,
            $formId
        )];
    }

    /**
     * Create the sets structure for a replicator field based on child form fields.
     *
     * **An empty return is reported, never silent.** Both ways of reaching one mean the
     * nested section will collect nothing, and a `replicator` with no sets is valid
     * Statamic — so without a warning the form imports looking complete and only a
     * submission reveals the loss (wordpress-importer-archive#1156).
     *
     * @param  array<int|string, mixed>  $formField  The form field data
     * @param  array<int|string, mixed>  $siblingFields  Every field of every form in the export,
     *                                                   each still carrying its own `formId`
     * @param  ?string  $formTitle  The parent form title for warning context
     * @param  ?string  $fieldLabel  The nested-form field label for warning context
     * @param  ?string  $formId  The parent form ID for warning context
     * @return array<int|string, mixed> The sets structure for the replicator field
     */
    private function createReplicatorSets(
        array $formField,
        array $siblingFields,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): array {
        // Get the child form ID from the form field's gpnfForm attribute
        $childFormId = $this->getChildFormId($formField);

        if ($childFormId === null) {
            // A nested-form field that names no child form: report and return empty sets
            $this->warnNestedFormUnresolved(
                null,
                'the field declares no child form id',
                $formTitle,
                $fieldLabel,
                $formId
            );

            return [];
        }

        // Find the child form fields
        $childFormFields = $this->getChildFormFields(
            $childFormId,
            $siblingFields
        );

        if ($childFormFields === []) {
            // The child form was named but is not in the export, or carries no fields
            $this->warnNestedFormUnresolved(
                $childFormId,
                $siblingFields === []
                    ? 'no sibling forms were supplied to resolve it against'
                    : 'the child form is absent from the export or has no fields',
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
            // Only the comparison with 'form' reads this, and any default but 'form' skips
            // nothing, so the empty default is one choice among equivalent ones.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $childFieldType = $childField['type'] ?? '';

            // Skip certain field types that shouldn't be in replicator sets
            if ($childFieldType === 'form') {
                continue;
            }

            // Generate field handle and configuration
            $fieldHandle = $this->generateFieldHandle($childField);
            $fieldConfig = $this->createBlueprintField(
                $childField,
                'gravity-forms'
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
            $setFields,
        );

        return $sets;
    }

    /**
     * Get the child form ID from the form field's gpnfForm attribute.
     *
     * @param  array<int|string, mixed>  $formField  The form field data
     * @return ?string The child form ID or null if not found
     */
    private function getChildFormId(
        array $formField
    ): ?string {
        $childFormId = $formField['gpnfForm'] ?? null;

        if (\is_string($childFormId) && $childFormId !== '') {
            // The scalar branch below returns the same string, so this return is a
            // shortcut that changes no result.
            // @pest-mutate-ignore: RemoveEarlyReturn
            return $childFormId;
        }

        if (\is_scalar($childFormId) && (string) $childFormId !== '') {
            return (string) $childFormId;
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

            $fieldFormId = $field['formId'] ?? null;

            if (
                \is_scalar($fieldFormId)
                && (string) $fieldFormId === $childFormId
            ) {
                $childFields[] = $field;
            }
        }

        return $childFields;
    }
}
