<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseContentDetector;

/**
 * Detects Ninja Forms usage in WordPress XML export content.
 *
 * This class extends BaseContentDetector to provide Ninja Forms-specific
 * content detection functionality.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * content detection operations.
 */
class ContentDetector extends BaseContentDetector
{
    /**
     * Create a new ContentDetector instance.
     */
    public function __construct()
    {
        parent::__construct('NinjaForms');
    }

    /**
     * Check if content matches the Ninja Forms block pattern.
     *
     * @param  string  $content  The content to check
     * @return bool True if content matches block pattern, false otherwise
     */
    protected function matchesBlockPattern(
        string $content
    ): bool {
        // Look for wp:ninja-forms/form blocks. The plugin's block handle is hyphenated
        // (ninja-forms), unlike the Gravity Forms and Formidable Forms handles.
        return \preg_match(
            '/<!--\s*wp:ninja-forms\/form\b/',
            $content
        ) === 1;
    }

    /**
     * Check if content matches the Ninja Forms shortcode pattern.
     *
     * @param  string  $content  The content to check
     * @return bool True if content matches shortcode pattern, false otherwise
     */
    protected function matchesShortcodePattern(
        string $content
    ): bool {
        // ---
        // Look for [ninja_form id=N] shortcodes. The quotes are optional: the export
        // corpus carries both `[ninja_form id='2']` and `[ninja_form id=3]`, so a
        // quote-mandatory pattern (as Gravity Forms uses) would miss real usages.
        // ---
        return \preg_match(
            '/\[ninja_form\s+id=["\']?[0-9]+["\']?/',
            $content
        ) === 1;
    }
}
