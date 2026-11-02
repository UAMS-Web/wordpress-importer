<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Normalizes ACF field names by mapping field-based variations to standard names.
 *
 * Handles the conversion of WordPress ACF field names like `field_side_heading_b`
 * to standard names like `side_heading`. This utility provides a generic
 * interface for normalizing ACF field names across different block parsers,
 * allowing each parser to define its own field mappings while using consistent
 * normalization logic.
 */
class AcfFieldNormalizer
{
    /**
     * Normalizes field names by mapping field-based variations to standard names.
     *
     * Converts field names like `field_side_heading_b` to `side_heading` and
     * handles nested field structures. This simplifies field access throughout
     * parsers by providing a consistent interface regardless of the WordPress
     * ACF data structure.
     *
     * @param  array<int|string, mixed>  $data  The raw block data from WordPress
     * @param  array<int|string, mixed>  $fieldMappings  Array of field name mappings (field_name => standard_name)
     * @param  array<int|string, mixed>  $nestedStructures  Array of nested structure keys to process
     * @return array<int|string, mixed> The normalized data with consistent field names
     */
    public static function normalize(
        array $data,
        array $fieldMappings = [],
        array $nestedStructures = []
    ): array {
        $normalized = [];

        // Copy all original data first (including indexed fields for repeater processing)
        foreach ($data as $key => $value) {
            $normalized[$key] = $value;
        }

        // Map field-based names to standard names
        foreach ($fieldMappings as $fieldName => $standardName) {
            if (
                ! \is_string($fieldName)
                || ! \is_string($standardName)
            ) {
                continue;
            }

            if (isset($data[$fieldName])) {
                $normalized[$standardName] = $data[$fieldName];
            }
        }

        // Handle nested structures
        foreach ($nestedStructures as $nestedKey) {
            if (
                ! \is_string($nestedKey)
                || ! isset($data[$nestedKey])
                || ! \is_array($data[$nestedKey])
            ) {
                continue;
            }

            $nestedData = $data[$nestedKey];
            foreach ($nestedData as $key => $value) {
                if (
                    \is_string($key)
                    && isset($fieldMappings[$key])
                    && \is_string($fieldMappings[$key])
                ) {
                    $normalized[$fieldMappings[$key]] = $value;
                }
            }
        }

        return $normalized;
    }

    /**
     * Creates field mappings for a given prefix pattern.
     *
     * Generates field mappings for blocks that follow a consistent naming pattern.
     * Useful for creating mappings for other ACF block types.
     *
     * @param  string  $prefix  The field prefix (e.g., 'side', 'content', 'hero')
     * @param  array<int|string, mixed>  $fieldNames  Array of field names without the prefix
     * @return array<int|string, mixed> Array of field mappings
     */
    public static function createFieldMappings(
        string $prefix,
        array $fieldNames
    ): array {
        $mappings = [];
        foreach ($fieldNames as $fieldName) {
            if (! \is_string($fieldName)) {
                continue;
            }

            $mappings["field_{$prefix}_{$fieldName}_b"] = "{$prefix}_{$fieldName}";
        }

        return $mappings;
    }
}
