<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseFormIdExtractor;

/**
 * Extracts Gravity Forms IDs from WordPress XML export content.
 *
 * This class extends BaseFormIdExtractor to provide Gravity Forms-specific
 * form ID extraction functionality.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * form ID extraction operations.
 */
class FormIdExtractor extends BaseFormIdExtractor
{
    /**
     * Create a new FormIdExtractor instance.
     */
    public function __construct()
    {
        parent::__construct('GravityForms');
    }

    /**
     * Get the regex pattern for matching Gravity Forms blocks.
     *
     * @return string The regex pattern for blocks
     */
    protected function getBlockPattern(): string
    {
        // ---
        // The optional slash before the comment terminator is load-bearing. Gutenberg
        // writes a block with no inner content as SELF-CLOSING — `{…} /-->` — and `/` is
        // not whitespace, so a pattern ending `\s*-->` matches none of them.
        //
        // In the export corpus every one of the 870 Gravity Forms blocks is self-closing,
        // so without this the extractor returned an empty list for all of them.
        //
        // Not caught sooner because ContentDetector uses a looser pattern with no
        // terminator requirement, so detection reported the plugin present correctly and
        // only ID extraction came back empty — a failure downstream of a green-looking step.
        // ---
        return '/<!--\s*wp:gravityforms\/form\s+({.*?})\s*\/?-->/';
    }

    /**
     * Get the regex pattern for matching Gravity Forms shortcodes.
     *
     * @return string The regex pattern for shortcodes
     */
    protected function getShortcodePattern(): string
    {
        return '/\[gravityform\s+id=["\\\']([0-9]+)["\\\']/';
    }

    /**
     * Get the form ID attribute name for Gravity Forms.
     *
     * @return string The form ID attribute name
     */
    protected function getFormIdAttribute(): string
    {
        // ---
        // The Gutenberg block spells this `formId`, not `id`. Reading `id` finds nothing,
        // so every parsed block yielded no form ID even once the pattern matched it.
        //
        // Verified against the export corpus: all 869 `Gravity Forms` block attribute
        // objects carry `formId`, and none carries `id`.
        // ---
        return 'formId';
    }
}
