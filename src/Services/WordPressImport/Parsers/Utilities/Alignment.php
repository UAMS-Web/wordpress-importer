<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Utilities;

/**
 * Translates WordPress alignment values to Statamic equivalents.
 *
 * Maps WordPress alignment attributes or classes (e.g., 'left', 'right', 'center',
 * 'full', 'wide') to Statamic's alignment options for Bard sets. Supports
 * context-specific mappings for image sets (allowing 'alignleft', 'alignright')
 * and non-image sets (restricting to 'aligncenter', 'alignwide'). Extracts
 * alignment from class strings (e.g., 'alignright' to 'right') if needed,
 * defaulting to 'aligncenter' for unrecognized values.
 */
class Alignment
{
    /**
     * Translates a WordPress alignment value to a Statamic alignment.
     *
     * For image sets, maps 'left' to 'alignleft', 'right' to 'alignright', 'center'
     * to 'aligncenter', and 'full' or 'wide' to 'alignwide'. For non-image sets,
     * maps 'left', 'right', or 'center' to 'aligncenter' and 'full' or 'wide' to
     * 'alignwide'. Extracts alignment from class strings if prefixed with 'align'
     * (e.g., 'alignright' becomes 'right'). Returns 'aligncenter' for invalid or
     * unrecognized alignments.
     *
     * @param  string  $align  The WordPress alignment value or class string (e.g., 'alignright', 'right')
     * @param  bool  $forImage  True for image sets, false for other sets (e.g., pull_quote, video)
     * @return string The Statamic alignment value (e.g., 'alignleft', 'aligncenter', 'alignwide')
     */
    public static function translate(
        string $align,
        bool $forImage = true
    ): string {
        // Extract alignment from class string if prefixed with 'align'
        if (
            \preg_match(
                '/align(wide|full|center|left|right)/',
                $align,
                $matches
            ) === 1
        ) {
            $align = $matches[1];
        }

        if ($forImage) {
            // Image sets allow alignleft, alignright
            return match ($align) {
                'left' => 'alignleft',
                'right' => 'alignright',
                'center' => 'aligncenter',
                'full', 'wide' => 'alignwide',
                default => 'aligncenter',
            };
        }

        // Non-image sets restrict to aligncenter, alignwide
        return match ($align) {
            'full', 'wide' => 'alignwide',
            'center', 'left', 'right' => 'aligncenter',
            default => 'aligncenter',
        };
    }
}
