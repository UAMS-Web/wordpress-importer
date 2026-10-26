<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Manages import warnings throughout the WordPress import process.
 *
 * This class provides a centralized way to collect and display warnings
 * from various parts of the import process. Warnings are collected during
 * import and displayed at the end to provide a comprehensive summary.
 */
class ImportWarningManager
{
    /**
     * @var array<string, array<string, list<array<int|string, mixed>>>> Collection
     *                                                                   of warnings organized by category
     */
    private static array $warnings = [];

    /**
     * Add a warning to the collection.
     *
     * @param  string  $category  The warning category (e.g., 'forms', 'content', 'assets')
     * @param  string  $type  The warning type (e.g., 'unsupported_attributes', 'missing_files')
     * @param  array<int|string, mixed>  $data  The warning data
     */
    public static function addWarning(
        string $category,
        string $type,
        array $data
    ): void {
        self::$warnings[$category] ??= [];

        self::$warnings[$category][$type] ??= [];

        // Capture existing warnings for duplicate checks
        $existingWarnings = self::$warnings[$category][$type];

        // For unmapped_fieldtypes, check for duplicates before adding
        if ($category === 'forms' && $type === 'unmapped_fieldtypes') {
            $fieldType = $data['field_type'] ?? '';
            $formId = $data['form_id'] ?? null;

            // Check if we already have a warning for this fieldtype and form
            foreach ($existingWarnings as $existingWarning) {
                $existingFieldType = $existingWarning['field_type'] ?? '';
                $existingFormId = $existingWarning['form_id'] ?? null;

                if ($fieldType === $existingFieldType && $formId === $existingFormId) {
                    // Duplicate found, don't add this warning
                    return;
                }
            }
        }

        // For unsupported_attributes, check for duplicates before adding
        if ($category === 'forms' && $type === 'unsupported_attributes') {
            $fieldType = $data['field_type'] ?? '';
            $attributeName = $data['attribute_name'] ?? '';
            $formId = $data['form_id'] ?? null;

            // Check if we already have a warning for this fieldtype, attribute, and form
            foreach ($existingWarnings as $existingWarning) {
                $existingFieldType = $existingWarning['field_type'] ?? '';
                $existingAttributeName = $existingWarning['attribute_name'] ?? '';
                $existingFormId = $existingWarning['form_id'] ?? null;

                if ($fieldType === $existingFieldType && $attributeName === $existingAttributeName && $formId === $existingFormId) {
                    // Duplicate found, don't add this warning
                    return;
                }
            }
        }

        // For unmapped_subfields, check for duplicates before adding
        if ($category === 'forms' && $type === 'unmapped_subfields') {
            $fieldType = $data['field_type'] ?? '';
            $subfieldLabel = $data['subfield_label'] ?? '';
            $attributeName = $data['attribute_name'] ?? null;
            $formId = $data['form_id'] ?? null;

            // Check if we already have a warning for this fieldtype, subfield, attribute, and form
            foreach ($existingWarnings as $existingWarning) {
                $existingFieldType = $existingWarning['field_type'] ?? '';
                $existingSubfieldLabel = $existingWarning['subfield_label'] ?? '';
                $existingAttributeName = $existingWarning['attribute_name'] ?? null;
                $existingFormId = $existingWarning['form_id'] ?? null;

                if ($fieldType === $existingFieldType && $subfieldLabel === $existingSubfieldLabel && $attributeName === $existingAttributeName && $formId === $existingFormId) {
                    // Duplicate found, don't add this warning
                    return;
                }
            }
        }

        // For malformed_shortcodes, check for duplicates before adding
        if ($category === 'content' && $type === 'malformed_shortcodes') {
            $shortcodeName = $data['shortcode_name'] ?? '';
            $attributeName = $data['attribute'] ?? '';
            $original = $data['original'] ?? '';
            $pageUrl = $data['page_url'] ?? null;

            // The same content can be re-parsed by multiple processing passes, so
            // dedupe by entry + shortcode + attribute to avoid inflated occurrence counts.
            foreach ($existingWarnings as $existingWarning) {
                if (
                    ($existingWarning['shortcode_name'] ?? '') === $shortcodeName
                    && ($existingWarning['attribute'] ?? '') === $attributeName
                    && ($existingWarning['original'] ?? '') === $original
                    && ($existingWarning['page_url'] ?? null) === $pageUrl
                ) {
                    // Duplicate found, don't add this warning
                    return;
                }
            }
        }

        // Add the warning data to the appropriate category and type
        self::$warnings[$category][$type][] = $data;

        // Clean up variables to free up memory
        unset(
            $fieldType,
            $formId,
            $attributeName,
            $subfieldLabel,
            $shortcodeName,
            $original,
            $pageUrl,
            $existingWarning,
            $existingFieldType,
            $existingFormId,
            $existingAttributeName,
            $existingSubfieldLabel
        );
    }

    /**
     * Get all warnings for a specific category and type.
     *
     * @param  string  $category  The warning category
     * @param  string  $type  The warning type
     * @return array<int|string, mixed> Array of warnings
     */
    public static function getWarnings(
        string $category,
        string $type
    ): array {
        if (! isset(self::$warnings[$category][$type])) {
            return [];
        }

        return self::$warnings[$category][$type];
    }

    /**
     * Get all warnings for a specific category.
     *
     * @param  string  $category  The warning category
     * @return array<int|string, mixed> Array of warnings organized by type
     */
    public static function getWarningsByCategory(
        string $category
    ): array {
        if (! isset(self::$warnings[$category])) {
            return [];
        }

        return self::$warnings[$category];
    }

    /**
     * Get all warnings.
     *
     * @return array<int|string, mixed> All warnings organized by category and type
     */
    public static function getAllWarnings(): array
    {
        return self::$warnings;
    }

    /**
     * Check if there are any warnings.
     *
     * @return bool True if there are warnings, false otherwise
     */
    public static function hasWarnings(): bool
    {
        return self::$warnings !== [];
    }

    /**
     * Get the total count of all warnings.
     *
     * @return int Total warning count
     */
    public static function getWarningCount(): int
    {
        // Initialize warning count
        $count = 0;

        // Iterate through each warning category
        foreach (self::$warnings as $category) {
            // Iterate through each warning type in the category
            foreach ($category as $type) {
                // Add the number of warnings of this type to the total count
                $count += \count($type);
            }
        }

        // Return the total warning count
        return $count;
    }

    /**
     * Clear all warnings.
     */
    public static function clearWarnings(): void
    {
        self::$warnings = [];
    }

    /**
     * Clear warnings for a specific category.
     *
     * @param  string  $category  The warning category to clear
     */
    public static function clearWarningsByCategory(
        string $category
    ): void {
        unset(self::$warnings[$category]);
    }

    /**
     * Clear warnings for a specific category and type.
     *
     * @param  string  $category  The warning category
     * @param  string  $type  The warning type
     */
    public static function clearWarningsByCategoryAndType(
        string $category,
        string $type
    ): void {
        if (! isset(self::$warnings[$category])) {
            return;
        }

        unset(self::$warnings[$category][$type]);
    }
}
