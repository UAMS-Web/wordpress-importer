<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Helper class for extracting URL and target values from WordPress link data.
 *
 * Provides reusable methods to process link structures (JSON arrays or serialized
 * strings) into Statamic-compatible button_url and button_target fields.
 */
class LinkHelper
{
    /**
     * Extract the URL from a link value.
     *
     * Handles JSON arrays or serialized strings, returning the URL or a fallback.
     *
     * @param  mixed  $linkValue  The link value (array or serialized string)
     * @param  string  $fallback  Fallback value if URL cannot be extracted
     * @return string The extracted URL
     */
    public static function getUrl(
        mixed $linkValue,
        string $fallback = ''
    ): string {
        // Extract the URL value from the link data structure
        $url = self::extractValue(
            $linkValue,
            'url',
            $fallback
        );

        // Return the extracted URL when it is a non-empty string
        if (\is_string($url) && $url !== '') {
            return $url;
        }

        // Return the link value when it is already a string, otherwise the fallback
        return \is_string($linkValue) ? $linkValue : $fallback;
    }

    /**
     * Extract the target from a link value.
     *
     * Returns true for `_blank`, the target WordPress writes for a link that opens
     * in a new window, and false for any other target or none. The host app reads
     * true as "open in a new window" (#150).
     *
     * @param  mixed  $linkValue  The link value (array or serialized string)
     * @return bool Whether the link opens in a new window
     */
    public static function getTarget(
        mixed $linkValue
    ): bool {
        // Extract the target value from the link data structure
        $target = self::extractValue(
            $linkValue,
            'target'
        );

        // Only _blank opens a new window; a missing target is null
        return $target === '_blank';
    }

    /**
     * Extract a value from a link data structure.
     *
     * Handles both array structures and serialized strings, extracting the value
     * for the specified key.
     *
     * @param  mixed  $linkValue  The link value (array or serialized string)
     * @param  string  $key  The key to extract from the link data
     * @param  mixed  $fallback  Fallback value if key cannot be extracted
     * @return mixed The extracted value or fallback
     */
    private static function extractValue(
        mixed $linkValue,
        string $key,
        mixed $fallback = null
    ): mixed {
        // Check if the link value is an array with the requested key
        if (
            \is_array($linkValue)
            && isset($linkValue[$key])
        ) {
            // Return the value from the array data
            return $linkValue[$key];
        }

        // Check if the link value is a string before attempting to unserialize
        if (\is_string($linkValue)) {
            // Attempt to unserialize the string value to extract link data
            $linkData = @\unserialize($linkValue, ['allowed_classes' => false]);

            // Check if unserialization was successful and contains the requested key
            if (
                \is_array($linkData)
                && isset($linkData[$key])
            ) {
                // Return the value from the unserialized data
                return $linkData[$key];
            }
        }

        // Return fallback if value could not be extracted
        return $fallback;
    }
}
