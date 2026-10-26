<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseContentDetector;

/**
 * Detects Gravity Forms usage in WordPress XML export content.
 *
 * This class extends BaseContentDetector to provide Gravity Forms-specific
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
        parent::__construct('GravityForms');
    }

    /**
     * Check if content matches the Gravity Forms block pattern.
     *
     * @param  string  $content  The content to check
     * @return bool True if content matches block pattern, false otherwise
     */
    protected function matchesBlockPattern(
        string $content
    ): bool {
        // Look for wp:gravityforms/form blocks
        return \preg_match(
            '/<!--\s*wp:gravityforms\/form\b/',
            $content
        ) === 1;
    }

    /**
     * Check if content matches the Gravity Forms shortcode pattern.
     *
     * @param  string  $content  The content to check
     * @return bool True if content matches shortcode pattern, false otherwise
     */
    protected function matchesShortcodePattern(
        string $content
    ): bool {
        // Look for [gravityform id="X"], [gravityform id='X'] or unquoted [gravityform id=X] shortcodes (wordpress-importer-archive#1369)
        return \preg_match(
            '/\[gravityform\s+id=(?:["\'][0-9]+["\']|[0-9]+\b)/i',
            $content
        ) === 1;
    }
}
