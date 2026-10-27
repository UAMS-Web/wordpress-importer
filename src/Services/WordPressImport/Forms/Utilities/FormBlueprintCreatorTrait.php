<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities;

use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Fields\Blueprint;

/**
 * Trait for creating basic blueprints for imported WordPress forms.
 *
 * This trait provides shared functionality for creating and saving basic
 * blueprints for forms imported from WordPress, with plugin-specific field name
 * handling.
 */
trait FormBlueprintCreatorTrait
{
    /**
     * The site handle for placeholder replacement.
     */
    protected ?string $siteHandle = null;

    /**
     * Every form the source export carries, keyed however the plugin serves them.
     *
     * **This is forms, not fields, and the distinction is the whole reason it exists.**
     * A nested-form field names a *sibling* form by id, so the child's fields are not
     * present anywhere in the parent's own field array — no amount of searching the
     * parameter named `$allFields` can find them. `$allFields` carries the current
     * form's fields and is correct for conditional logic; this carries the forms, and
     * is the only thing able to resolve a cross-form reference.
     *
     * Null when the caller did not supply them, which is the pre-#1156 behavior and is
     * why every read of it reports rather than assumes.
     *
     * @var array<int|string, mixed>|null
     */
    protected ?array $sourceForms = null;

    /**
     * Check if a blueprint exists for the given form handle.
     *
     * @param  string  $formHandle  The form handle to check
     * @return bool True if blueprint exists, false otherwise
     */
    public function blueprintExists(
        string $formHandle
    ): bool {
        // Find the blueprint using Statamic's BlueprintFacade
        $blueprint = BlueprintFacade::find('forms.'.$formHandle);

        // Determine if blueprint exists
        $exists = $blueprint !== null;

        // Return whether blueprint exists
        return $exists;
    }

    /**
     * Get the blueprint for the given form handle.
     *
     * @param  string  $formHandle  The form handle
     * @return ?Blueprint The blueprint or null if not found
     */
    public function getBlueprint(
        string $formHandle
    ): ?Blueprint {
        return BlueprintFacade::find('forms.'.$formHandle);
    }

    /**
     * Create a basic blueprint for an imported form.
     *
     * Creates a blueprint with no fields and saves it
     * to the appropriate location in the Statamic blueprint system.
     *
     * @param  string  $formHandle  The form handle (used for blueprint handle)
     * @param  string  $formTitle  The form title (used for blueprint title)
     * @return bool True if blueprint was created successfully, false otherwise
     */
    public function createBasicBlueprint(
        string $formHandle,
        string $formTitle
    ): bool {
        // Wrap blueprint creation in try-catch for error handling
        try {
            // Create a basic blueprint with no fields
            $blueprint = $this->makeBlueprintFromFields([]);

            // Configure and save the blueprint
            $blueprint->setHandle($formHandle);
            $blueprint->setNamespace('forms');
            $blueprint->save();

            // Return success
            return true;
        } catch (Exception $exception) {
            // Log the error and return false
            Log::error(
                'Failed to create blueprint for form: '.$formHandle,
                [
                    'error' => $exception->getMessage(),
                    'form_title' => $formTitle,
                ]
            );

            // Return failure
            return false;
        }
    }

    /**
     * Create a blueprint for an imported form with fields from WordPress form data.
     *
     * Creates a blueprint with fields based on the WordPress form fields and saves it
     * to the appropriate location in the Statamic blueprint system.
     *
     * @param  string  $formHandle  The form handle (used for blueprint handle)
     * @param  string  $formTitle  The form title (used for blueprint title)
     * @param  array<int|string, mixed>  $formFields  Array of WordPress form field data
     * @param  string  $pluginName  The form plugin name (e.g., 'gravity-forms', 'formidable-forms')
     * @param  ?string  $formId  The form ID for warning context
     * @param  ?string  $siteHandle  The site handle for placeholder replacement
     * @param  array<int|string, mixed>|null  $sourceForms  Every form the export carries, which a
     *                                                      nested-form field needs in order to
     *                                                      resolve the sibling form it names. Null
     *                                                      leaves cross-form references unresolved
     *                                                      and reported
     * @return bool True if blueprint was created successfully, false otherwise
     */
    public function createBlueprintWithFields(
        string $formHandle,
        string $formTitle,
        array $formFields,
        string $pluginName,
        ?string $formId = null,
        ?string $siteHandle = null,
        ?array $sourceForms = null
    ): bool {
        // Store the site handle for use in field processing
        $this->siteHandle = $siteHandle;

        // Store the sibling forms, which only a cross-form reference reads
        $this->sourceForms = $sourceForms;

        // Wrap blueprint creation in try-catch for error handling
        try {
            // Start with empty fields array
            $blueprintFields = [];

            // Fields dropped by the skip guard whose type declares `trace_when_skipped`.
            // Collected here and written into the blueprint's first SECTION instructions
            // below.
            $tracedSkips = [];

            // Add fields for each WordPress form field
            foreach ($formFields as $fieldData) {
                // Skip values that are not valid field arrays.
                if (! \is_array($fieldData)) {
                    continue;
                }

                $field = $fieldData;

                // Determine the field type, defaulting to 'unknown' if not set
                $rawFieldType = $field['type'] ?? 'unknown';
                $fieldType = \is_scalar($rawFieldType)
                    ? (string) $rawFieldType
                    : 'unknown';

                // Generate a display name for the field (used for warnings and blueprint labels)
                $fieldLabel = $this->generateDisplayName($field);

                // Get the mapping array for the current plugin
                $mapping = $this->getMappingForPlugin($pluginName);
                $mappingEntry = $mapping[$fieldType] ?? null;

                // Skip suppressed fieldtypes (mapped to false) without warning
                if (
                    \is_array($mappingEntry)
                    && \array_key_exists(
                        'default',
                        $mappingEntry
                    )
                    && $mappingEntry['default'] === false
                ) {
                    continue;
                }

                // Warn and skip if fieldtype is unmapped
                $this->warnIfFieldtypeUnmapped(
                    $mapping,
                    $fieldType,
                    $formTitle,
                    $fieldLabel,
                    $formId
                );

                // ---
                // Skip further processing for unmapped fieldtypes.
                //
                // **Ask the mapping, do not re-derive the answer.** `isFieldtypeMapped()`
                // already draws this line correctly and this guard simply never consulted
                // it, which is why a genuinely unsupported type reached
                // `createBlueprintField()` and landed as a `text` field — a captcha
                // becoming an editable input on a public form (wordpress-importer-archive#815).
                //
                // A null `default` alone is NOT the test, and reading it as one drops
                // working fields. It means "no single default — resolve the fieldtype from
                // this field's `inputType`", and four types rely on that:
                // `multi_choice`, `poll`, `survey` and `post_tags` carry a null default
                // *and* a real `inputType` map, so `survey + likert` is a `likert_scale`
                // and `multi_choice + radio` is a `radio`. Only the five attribute-less
                // null-default types are unsupported.
                //
                // An absent type and a null entry both leave `$mappingEntry` not an array,
                // so the array test below covers them as well.
                // ---
                if (
                    ! \is_array($mappingEntry)
                    || ! $this->getMappingClass()::isFieldtypeMapped($mappingEntry)
                ) {
                    // ---
                    // Record the drop when the type declares it changes what the form
                    // does. Deliberately NOT keyed on `alert_unsupported`: that governs a
                    // warning read once by the operator, while this is read by an editor
                    // opening the form months later. A captcha is quiet in both; a
                    // `quantity` warns and traces (wordpress-importer-archive#831).
                    // ---
                    if (
                        \is_array($mappingEntry)
                        && $this->getMappingClass()::tracesWhenSkipped($mappingEntry)
                    ) {
                        // generateDisplayName() falls back to a "Type (ID: n)" name, so the
                        // label is never empty here.
                        $tracedSkips[] = \sprintf('%s (%s)', $fieldLabel, $fieldType);
                    }

                    continue;
                }

                // Generate a unique handle for this field
                $fieldHandle = $this->generateFieldHandle($field);

                // Create the blueprint field configuration
                $blueprintField = $this->createBlueprintField(
                    $field,
                    $pluginName,
                    $formFields,
                    $formTitle,
                    $formId,
                    $siteHandle
                );
                if ($blueprintField !== null) {
                    $blueprintFields[$fieldHandle] = $blueprintField;
                }
            }

            // Create the blueprint
            $blueprint = $this->makeBlueprintFromFields($blueprintFields);

            // Leave the note on a blueprint SECTION rather than on a field, and note
            // that "section" is overloaded here: this is the structural section inside a
            // tab, NOT the `section` FIELDTYPE. That fieldtype has a partial at
            // `vendor/statamic/forms/fields/section.antlers.html` which renders a visible
            // heading, so a note carried in one would be shown to every visitor -- which
            // is why the first attempt at this was withdrawn.
            //
            // The write site below carries the measurements that chose this location.
            $this->noteSkippedFieldsOnBlueprint(
                $blueprint,
                $tracedSkips
            );

            // Configure and save the blueprint
            $blueprint->setHandle($formHandle);
            $blueprint->setNamespace('forms');
            $blueprint->save();

            // Return success
            return true;
        } catch (Exception $exception) {
            // Log the error and return false
            Log::error(
                'Failed to create blueprint with fields for form: '.$formHandle,
                [
                    'error' => $exception->getMessage(),
                    'form_title' => $formTitle,
                    'field_count' => \count($formFields),
                ]
            );

            // Return failure
            return false;
        }
    }

    /**
     * Record skipped fields in the blueprint's first section instructions.
     *
     * Leaves nothing behind when no field was skipped, so a form that lost nothing is
     * indistinguishable from one imported before this existed.
     *
     * @param  Blueprint  $blueprint  The blueprint to annotate
     * @param  array<int, string>  $skipped  Labels of the fields that were dropped
     */
    protected function noteSkippedFieldsOnBlueprint(
        Blueprint $blueprint,
        array $skipped
    ): void {
        // Nothing was dropped, so leave the blueprint exactly as it was built.
        if ($skipped === []) {
            return;
        }

        $contents = $blueprint->contents();

        // Only annotate the shape `makeFromFields()` produces; anything else is not ours
        // to rewrite, and silently reshaping a blueprint is worse than not annotating it.
        if (
            ! isset($contents['tabs'])
            || ! \is_array($contents['tabs'])
            || $contents['tabs'] === []
        ) {
            return;
        }

        $tabs = $contents['tabs'];
        $first = \array_key_first($tabs);

        // `$first` cannot be null: the guard above returns on an empty `tabs` array.
        if (! \is_array($tabs[$first])) {
            // isset() on a string offset is false, so the guard below returns as well.
            // @pest-mutate-ignore: RemoveEarlyReturn
            return;
        }

        // The note goes on the first SECTION, not on the tab. Measured against a booted
        // Control Panel with a three-armed probe: a blueprint carrying no instructions
        // shows nothing, a TAB-level key is NOT displayed, and a SECTION-level key IS.
        // The two live arms control each other, so the tab result is a real absence
        // rather than a probe that could not observe. Writing it to the tab stores a
        // note no editor ever reads (wordpress-importer-archive#857).
        if (
            ! isset($tabs[$first]['sections'])
            || ! \is_array($tabs[$first]['sections'])
            || ! \is_array($tabs[$first]['sections'][0] ?? null)
        ) {
            return;
        }

        // Name what was lost and what it means, rather than only that something was.
        $note = \sprintf(
            'Imported from WordPress. %d field%s could not be imported because Statamic '
                .'has no equivalent, so this form no longer collects %s: %s. Review whether '
                .'the form still does what it was built for.',
            \count($skipped),
            \count($skipped) === 1 ? '' : 's',
            \count($skipped) === 1 ? 'it' : 'them',
            \implode(', ', $skipped)
        );

        $tabs[$first]['sections'][0]['instructions'] = $note;
        $contents['tabs'] = $tabs;

        $blueprint->setContents($contents);
    }

    /**
     * Delete a blueprint if it exists (for testing purposes).
     *
     * @param  string  $formHandle  The form handle to delete
     * @return bool True if blueprint was deleted, false if it didn't exist
     */
    public function deleteBlueprint(
        string $formHandle
    ): bool {
        // Find the blueprint to delete
        $blueprint = BlueprintFacade::find('forms.'.$formHandle);

        // Check if blueprint exists before attempting deletion
        if ($blueprint !== null) {
            // Delete the blueprint
            $blueprint->delete();

            // Return success
            return true;
        }

        // Return failure since blueprint didn't exist
        return false;
    }

    /**
     * Generate a field handle from WordPress field data, always appending the field
     * ID for uniqueness.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return string The generated field handle
     */
    protected function generateFieldHandle(
        array $field
    ): string {
        // Always require a field ID for uniqueness
        $rawFieldId = $field['id'] ?? null;
        // The ID is only concatenated, which casts a scalar the same way.
        // @pest-mutate-ignore: RemoveStringCast
        $id = \is_scalar($rawFieldId) ? (string) $rawFieldId : null;

        // Use the snake_case field name as the base handle when it slugs to something,
        // otherwise the field ID alone. An empty or blank name slugs to ''.
        if ($id !== null) {
            $base = \str_replace(
                '-',
                '_',
                Str::slug($this->getFieldNameForHandle($field))
            );

            return $base !== '' ? $base.'_'.$id : 'field_'.$id;
        }

        // Fallback to using field type if available
        $rawFieldType = $field['type'] ?? null;
        if (\is_scalar($rawFieldType) && (string) $rawFieldType !== '') {
            return 'field_'.$rawFieldType.'_'.Str::random(8);
        }

        // Final fallback with random string to ensure uniqueness
        return 'field_'.Str::random(8);
    }

    /**
     * Create a blueprint field configuration from WordPress field data.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $pluginName  The form plugin name
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for context
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $formId  The form ID for warning context
     * @param  ?string  $siteHandle  The site handle for placeholder replacement
     * @return array<int|string, mixed>|null The blueprint field configuration, or null if field should be suppressed
     */
    protected function createBlueprintField(
        array $field,
        string $pluginName,
        array $allFields = [],
        ?string $formTitle = null,
        ?string $formId = null,
        ?string $siteHandle = null
    ): ?array {
        // Generate a descriptive display name
        $displayName = $this->generateDisplayName($field);

        // Get the field type
        $fieldType = $this->getFieldType(
            $field,
            $pluginName
        );

        // Return null if field should be suppressed
        if ($fieldType === null) {
            return null;
        }

        // Check if this is a linked field (returns a field handle instead of fieldtype)
        // Linked fields reference existing Statamic field definitions instead of creating new ones
        $linkedField = $this->getLinkedFieldFromFieldtypeMapping(
            $field,
            $pluginName
        );

        // Start with basic field configuration
        if ($linkedField !== null) {
            // For linked fields, use 'field' property and put other attributes in 'config'
            // This creates a reference to an existing field definition
            $fieldConfig = [
                'field' => $linkedField,
                'config' => [
                    'display' => $displayName,
                ],
            ];
        } else {
            // For regular fields, use 'type' property to create a new field definition
            $fieldConfig = [
                'type' => $fieldType,
                'display' => $displayName,
            ];
        }

        // Add validation if field is required (plugin-specific logic)
        $validation = $this->getFieldValidation($field);
        if ($validation !== []) {
            if ($linkedField !== null) {
                $fieldConfig['config']['validate'] = $validation;
            } else {
                $fieldConfig['validate'] = $validation;
            }
        }

        // Apply field attribute mapping based on plugin
        $fieldLabel = $this->generateDisplayName($field);
        $mappedAttributes = $this->getMappedFieldAttributes(
            $field,
            $pluginName,
            $allFields,
            $formTitle,
            $fieldLabel,
            $formId,
            $siteHandle ?? $this->siteHandle
        );

        // Merge mapped attributes into field configuration if any exist
        if ($mappedAttributes !== []) {
            if ($linkedField !== null) {
                $existingConfig = $this->normalizeBlueprintConfigSection(
                    $fieldConfig['config'] ?? null
                );

                // For linked fields, merge into config array
                $fieldConfig['config'] = \array_merge(
                    $existingConfig,
                    $mappedAttributes
                );
            } else {
                // For regular fields, merge directly into field configuration
                $fieldConfig = \array_merge(
                    $fieldConfig,
                    $mappedAttributes
                );
            }
        }

        // Apply set_attributes from fieldtype mapping if available
        $setAttributes = $this->getSetAttributesFromFieldtypeMapping(
            $field,
            $pluginName,
            $siteHandle ?? $this->siteHandle
        );
        if ($setAttributes !== []) {
            if ($linkedField !== null) {
                $existingConfig = $this->normalizeBlueprintConfigSection(
                    $fieldConfig['config'] ?? null
                );

                // For linked fields, merge into config array
                $fieldConfig['config'] = \array_merge(
                    $existingConfig,
                    $setAttributes
                );
            } else {
                // For regular fields, merge directly into field configuration
                $fieldConfig = \array_merge(
                    $fieldConfig,
                    $setAttributes
                );
            }
        }

        // Process subfields if the field has them (e.g., address fields with inputs)
        $subfieldConfig = $this->getMappedSubfieldAttributes(
            $field,
            $pluginName,
            $allFields,
            $formTitle,
            $fieldLabel,
            $formId
        );
        if ($subfieldConfig !== []) {
            if ($linkedField !== null) {
                $existingConfig = $this->normalizeBlueprintConfigSection(
                    $fieldConfig['config'] ?? null
                );

                // For linked fields, merge into config array
                $fieldConfig['config'] = \array_merge(
                    $existingConfig,
                    $subfieldConfig
                );
            } else {
                // For regular fields, merge directly into field configuration
                $fieldConfig = \array_merge(
                    $fieldConfig,
                    $subfieldConfig
                );
            }
        }

        // Filter out null values from field configuration before returning
        if ($linkedField !== null) {
            // A linked field holds only `field` and `config` at the top level, and every
            // attribute is merged into `config`, so only that is filtered.
            $fieldConfig['config'] = \array_filter(
                $this->normalizeBlueprintConfigSection($fieldConfig['config'] ?? null),
                fn ($value): bool => $value !== null
            );
        } else {
            // For regular fields, filter normally
            $fieldConfig = \array_filter(
                $fieldConfig,
                fn ($value): bool => $value !== null
            );
        }

        // Clean up variables to free up memory
        unset(
            $displayName,
            $fieldType,
            $linkedField,
            $validation,
            $fieldLabel,
            $mappedAttributes,
            $setAttributes,
            $subfieldConfig,
        );

        // Return the complete field configuration
        return $fieldConfig;
    }

    /**
     * Generate a descriptive display name for a field.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return string The display name
     */
    protected function generateDisplayName(
        array $field
    ): string {
        // Use the plugin-specific field name if it exists and is not empty
        $fieldName = $this->getFieldNameForDisplay($field);
        if (\trim($fieldName) !== '') {
            return $fieldName;
        }

        // Generate descriptive name based on field type
        $rawFieldType = $field['type'] ?? 'unknown';
        $fieldType = \is_scalar($rawFieldType)
            ? (string) $rawFieldType
            : 'unknown';
        $rawFieldId = $field['id'] ?? 'unknown';
        // sprintf('%s') below casts a scalar the same way.
        // @pest-mutate-ignore: RemoveStringCast
        $fieldId = \is_scalar($rawFieldId)
            ? (string) $rawFieldId
            : 'unknown';

        // Create a human-readable type name
        $typeName = \ucwords(\str_replace(
            '_',
            ' ',
            $fieldType
        ));

        return \sprintf(
            '%s (ID: %s)',
            $typeName,
            $fieldId
        );
    }

    /**
     * Get the field name to use for handle generation.
     * Override this method in plugin-specific classes.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return string The field name for handle generation
     */
    abstract protected function getFieldNameForHandle(
        array $field
    ): string;

    /**
     * Get the field name to use for display name generation.
     * Override this method in plugin-specific classes.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return string The field name for display
     */
    abstract protected function getFieldNameForDisplay(
        array $field
    ): string;

    /**
     * Get the mapping array for the specified plugin.
     * This method delegates to the plugin-specific mapping class.
     *
     * @param  string  $pluginName  The plugin name
     * @return array<int|string, mixed> The mapping array
     */
    protected function getMappingForPlugin(
        string $pluginName
    ): array {
        // Resolve the mapping class for the active form plugin.
        $mappingClass = $this->getMappingClass();

        // Return the mapping from the plugin-specific class.
        return $this->getMappingFromClass($mappingClass);
    }

    /**
     * Get the mapping class for this plugin.
     * Automatically determines the mapping class based on the current class namespace.
     *
     * @return string The fully qualified mapping class name
     */
    protected function getMappingClass(): string
    {
        // Get the current class name
        $currentClass = $this::class;

        // Extract the plugin name from the class namespace
        // e.g., "UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormBlueprintCreator"
        // becomes "FormidableForms"
        $namespaceParts = \explode(
            '\\',
            $currentClass
        );
        $pluginName = $namespaceParts[\count($namespaceParts) - 2]; // Second to last part

        // Construct the mapping class name
        $mappingClass = "UAMSWeb\\WordPressImporter\\Services\\WordPressImport\\Forms\\Utilities\\{$pluginName}\\FieldtypeMapping";

        return $mappingClass;
    }

    /**
     * Get the mapped Statamic field type using the plugin-specific mapping class.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $pluginName  The form plugin name
     * @return string|null|false The mapped Statamic field type, null if not mapped, or false if suppressed
     */
    protected function getMappedFieldType(
        array $field,
        string $pluginName
    ): string|null|false {
        // Resolve the mapping class for the active form plugin.
        $mappingClass = $this->getMappingClass();

        // Get the mapped Statamic fieldtype for the current field.
        return $this->getMappedFieldTypeFromClass(
            $mappingClass,
            $field
        );
    }

    /**
     * Warn if fieldtype is unmapped.
     *
     * @param  array<int|string, mixed>  $mapping  The mapping array
     * @param  string  $fieldType  The field type
     * @param  ?string  $formTitle  The form title
     * @param  ?string  $fieldLabel  The field label
     * @param  ?string  $formId  The form ID
     */
    protected function warnIfFieldtypeUnmapped(
        array $mapping,
        string $fieldType,
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): void {
        FieldtypeMappingTrait::warnIfFieldtypeUnmapped(
            $mapping,
            $fieldType,
            $formTitle,
            $fieldLabel,
            $formId
        );
    }

    /**
     * Get mapped field attributes using the appropriate mapping class.
     * Override this method in plugin-specific classes.
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
    abstract protected function getMappedFieldAttributes(
        array $field,
        string $pluginName,
        array $allFields = [],
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null,
        ?string $siteHandle = null
    ): array;

    /**
     * Get the appropriate Statamic field type for a WordPress field.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $pluginName  The form plugin name
     * @return ?string The Statamic field type, or null if suppressed
     */
    protected function getFieldType(
        array $field,
        string $pluginName
    ): ?string {
        // Pass the full field array to the mapping function
        $mappedType = $this->getMappedFieldType(
            $field,
            $pluginName
        );

        // Return null if suppressed, otherwise return the mapped type or 'text' as fallback
        if ($mappedType === false) {
            return null;
        }

        return $mappedType ?? 'text';
    }

    /**
     * Get the linked field handle for a field based on its WordPress data.
     *
     * This method resolves linked field handles from the fieldtype mapping
     * configuration. Linked fields allow mapping WordPress form fields to existing
     * Statamic field definitions instead of creating new field configurations.
     *
     * When a linked field is returned, the blueprint field will use the 'field'
     * property instead of 'type', with all other attributes placed in a 'config'
     * array.
     *
     * Override this method in plugin-specific classes to handle plugin-specific
     * linked field mapping.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $pluginName  The form plugin name
     * @return ?string The linked field handle or null if not found
     */
    protected function getLinkedFieldFromFieldtypeMapping(
        array $field,
        string $pluginName
    ): ?string {
        // Get the mapping class for this plugin
        $mappingClass = $this->getMappingClass();

        // Get the mapping array from the mapping class
        $mappingArray = $this->getMappingFromClass($mappingClass);

        // Resolve the linked field handle from the mapping array.
        return $this->getLinkedFieldFromClass(
            $mappingClass,
            $mappingArray,
            $field
        );
    }

    /**
     * Get the validation rules for a field based on its WordPress data.
     * Override this method in plugin-specific classes to handle plugin-specific
     * validation.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return array<int|string, mixed> The validation rules
     */
    protected function getFieldValidation(
        array $field
    ): array {
        // Get the required attribute name from the plugin-specific class
        $requiredAttribute = $this->getRequiredAttributeName();

        // Use the common validation logic
        return $this->getValidationFromAttribute(
            $field,
            $requiredAttribute
        );
    }

    /**
     * Get the name of the attribute that indicates if a field is required.
     * Override this method in plugin-specific classes.
     *
     * @return string The attribute name
     */
    protected function getRequiredAttributeName(): string
    {
        // Default implementation - override in plugin-specific classes
        return '';
    }

    /**
     * Get validation rules from a field attribute.
     * Common logic for checking if a field is required based on an attribute.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $attributeName  The name of the attribute to check
     * @return array<int|string, mixed> The validation rules
     */
    protected function getValidationFromAttribute(
        array $field,
        string $attributeName
    ): array {
        // Check if the attribute exists and is truthy
        if (isset($field[$attributeName]) && $this->isAttributeTruthy($field[$attributeName])) {
            $validation = ['required', 'sometimes'];

            // Clean up variables to free up memory
            unset(
                $field,
                $attributeName,
            );

            return $validation;
        }

        // Clean up variables to free up memory
        unset(
            $field,
            $attributeName,
        );

        return [];
    }

    /**
     * Check if an attribute value is truthy.
     * Override this method in plugin-specific classes to handle different truthy
     * values.
     *
     * @param  mixed  $value  The attribute value to check
     * @return bool Whether the value is truthy
     */
    protected function isAttributeTruthy(
        $value
    ): bool {
        // Default implementation - treat as boolean
        return (bool) $value;
    }

    /**
     * Get mapped subfield attributes.
     * Override this method in plugin-specific classes.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $pluginName  The form plugin name
     * @param  array<int|string, mixed>  $allFields  Array of all form fields for context
     * @param  ?string  $formTitle  The form title for warning context
     * @param  ?string  $fieldLabel  The field label for warning context
     * @param  ?string  $formId  The form ID for warning context
     * @return array<int|string, mixed> The mapped subfield attributes
     */
    abstract protected function getMappedSubfieldAttributes(
        array $field,
        string $pluginName,
        array $allFields = [],
        ?string $formTitle = null,
        ?string $fieldLabel = null,
        ?string $formId = null
    ): array;

    /**
     * Get set_attributes from fieldtype mapping for a field.
     *
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  string  $pluginName  The form plugin name
     * @param  ?string  $siteHandle  The site handle for placeholder replacement
     * @return array<int|string, mixed> The set_attributes configuration or empty array if not found
     */
    protected function getSetAttributesFromFieldtypeMapping(
        array $field,
        string $pluginName,
        ?string $siteHandle = null
    ): array {
        // Get the mapping class for the plugin
        $mappingClass = $this->getMappingClass();

        // Get the mapping array from the mapping class
        $mappingArray = $this->getMappingFromClass($mappingClass);

        // Get set_attributes from the mapping
        $setAttributes = $this->getSetAttributesFromClass(
            $mappingClass,
            $mappingArray,
            $field,
            $siteHandle
        );

        // Return the set_attributes from the mapping
        return $setAttributes;
    }

    /**
     * Normalize a blueprint config section to a mergeable array.
     *
     * @param  mixed  $config  The config section value
     * @return array<int|string, mixed> Normalized config array
     */
    protected function normalizeBlueprintConfigSection(
        mixed $config
    ): array {
        if (! \is_array($config)) {
            return [];
        }

        return $config;
    }

    /**
     * Create a Statamic blueprint from field definitions.
     *
     * @param  array<int|string, mixed>  $fields  Blueprint field definitions
     * @return Blueprint The blueprint instance
     */
    protected function makeBlueprintFromFields(
        array $fields
    ): Blueprint {
        // Build a blueprint from the provided field definitions
        return BlueprintFacade::makeFromFields($fields);
    }

    /**
     * Get the mapping array from a mapping class.
     *
     * @param  string  $mappingClass  The mapping class name
     * @return array<int|string, mixed> The mapping array
     */
    protected function getMappingFromClass(
        string $mappingClass
    ): array {
        // Return an empty mapping when the class does not define getMapping.
        if (
            ! \method_exists(
                $mappingClass,
                'getMapping'
            )
        ) {
            return [];
        }

        // Read the mapping array from the plugin-specific mapping class.
        $mapping = $mappingClass::getMapping();

        // Return an empty mapping when the result is not an array.
        if (! \is_array($mapping)) {
            return [];
        }

        // Return the normalized mapping array.
        return $mapping;
    }

    /**
     * Get the mapped Statamic fieldtype from a mapping class.
     *
     * @param  string  $mappingClass  The mapping class name
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return string|null|false The mapped fieldtype, null, or false when suppressed
     */
    protected function getMappedFieldTypeFromClass(
        string $mappingClass,
        array $field
    ): string|null|false {
        // Return null when the class does not define getStatamicFieldtype.
        if (
            ! \method_exists(
                $mappingClass,
                'getStatamicFieldtype'
            )
        ) {
            return null;
        }

        // Resolve the mapped fieldtype from the mapping class.
        $mappedType = $mappingClass::getStatamicFieldtype($field);

        // Preserve explicit suppression.
        if ($mappedType === false) {
            return false;
        }

        // Return null for non-string values.
        if (! \is_string($mappedType)) {
            return null;
        }

        // Return the mapped Statamic fieldtype.
        return $mappedType;
    }

    /**
     * Get the linked field handle from a mapping class.
     *
     * @param  string  $mappingClass  The mapping class name
     * @param  array<int|string, mixed>  $mappingArray  The mapping array
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @return ?string The linked field handle, or null when unavailable
     */
    protected function getLinkedFieldFromClass(
        string $mappingClass,
        array $mappingArray,
        array $field
    ): ?string {
        // Return null when the class does not define getLinkedFieldFromMapping.
        if (
            ! \method_exists(
                $mappingClass,
                'getLinkedFieldFromMapping'
            )
        ) {
            return null;
        }

        // Resolve the linked field handle from the mapping class.
        $linkedField = $mappingClass::getLinkedFieldFromMapping(
            $mappingArray,
            $field
        );

        // Return null when no valid string handle is available.
        if (! \is_string($linkedField) || $linkedField === '') {
            return null;
        }

        // Return the linked field handle.
        return $linkedField;
    }

    /**
     * Get set_attributes from a mapping class.
     *
     * @param  string  $mappingClass  The mapping class name
     * @param  array<int|string, mixed>  $mappingArray  The mapping array
     * @param  array<int|string, mixed>  $field  The WordPress field data
     * @param  ?string  $siteHandle  The site handle for placeholder replacement
     * @return array<int|string, mixed> The set_attributes configuration
     */
    protected function getSetAttributesFromClass(
        string $mappingClass,
        array $mappingArray,
        array $field,
        ?string $siteHandle = null
    ): array {
        // Return an empty array when the class lacks getSetAttributesFromMapping.
        if (
            ! \method_exists(
                $mappingClass,
                'getSetAttributesFromMapping'
            )
        ) {
            return [];
        }

        // Resolve set_attributes from the mapping class.
        $setAttributes = $mappingClass::getSetAttributesFromMapping(
            $mappingArray,
            $field,
            $siteHandle
        );

        // Return an empty array when the result is not an array.
        if (! \is_array($setAttributes)) {
            return [];
        }

        // Return the normalized set_attributes array.
        return $setAttributes;
    }
}
