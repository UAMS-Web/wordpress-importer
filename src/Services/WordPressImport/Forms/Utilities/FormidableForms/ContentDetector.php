<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseContentDetector;

/**
 * Detects Formidable Forms usage in WordPress XML export content.
 *
 * This class extends BaseContentDetector to provide Formidable Forms-specific
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
        parent::__construct('FormidableForms');
    }

    /**
     * Check if content matches the Formidable Forms block pattern.
     *
     * @param  string  $content  The content to check
     * @return bool True if content matches block pattern, false otherwise
     */
    protected function matchesBlockPattern(
        string $content
    ): bool {
        // Look for wp:formidable/simple-form blocks
        return \preg_match(
            '/<!--\s*wp:formidable\/simple-form\b/',
            $content
        ) === 1;
    }

    /**
     * Check if content matches the Formidable Forms shortcode pattern.
     *
     * @param  string  $content  The content to check
     * @return bool True if content matches shortcode pattern, false otherwise
     */
    protected function matchesShortcodePattern(
        string $content
    ): bool {
        // Look for [formidable id="X"], [formidable id='X'] or unquoted [formidable id=X] shortcodes (wordpress-importer-archive#1369)
        return \preg_match(
            '/\[formidable\s+id=(?:["\'][0-9]+["\']|[0-9]+\b)/i',
            $content
        ) === 1;
    }
}
