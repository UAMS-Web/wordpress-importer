<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseFormIdExtractor;

/**
 * Extracts Formidable Forms IDs from WordPress XML export content.
 *
 * This class extends BaseFormIdExtractor to provide Formidable Forms-specific
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
        parent::__construct('FormidableForms');
    }

    /**
     * Get the regex pattern for matching Formidable Forms blocks.
     *
     * @return string The regex pattern for blocks
     */
    protected function getBlockPattern(): string
    {
        // ---
        // The optional slash before the comment terminator. Gutenberg writes a block
        // with no inner content as SELF-CLOSING — `{…} /-->` — and `/` is not
        // whitespace, so a pattern ending `\s*-->` cannot match one.
        //
        // Formidable Forms is the near-miss of the three: the corpus holds 24 paired
        // blocks and exactly ONE self-closing, so the omission cost a single form
        // rather than all of them. Fixed alongside Gravity Forms because it is the
        // identical defect, and leaving it because the corpus barely triggers it is
        // how it comes back the first time an editor saves a block on a newer
        // Gutenberg.
        // ---
        return '/<!--\s*wp:formidable\/simple-form\s+({.*?})\s*\/?-->/';
    }

    /**
     * Get the regex pattern for matching Formidable Forms shortcodes.
     *
     * @return string The regex pattern for shortcodes
     */
    protected function getShortcodePattern(): string
    {
        return '/\[formidable\s+id=["\\\']([0-9]+)["\\\']/';
    }

    /**
     * Get the form ID attribute name for Formidable Forms.
     *
     * @return string The form ID attribute name
     */
    protected function getFormIdAttribute(): string
    {
        // ---
        // The Gutenberg block spells this `formId`, not `id`. Reading `id` finds nothing,
        // so every parsed block yielded no form ID even once the pattern matched it.
        //
        // Verified against the export corpus: all 24 `Formidable Forms` block attribute
        // objects carry `formId`, and none carries `id`.
        // ---
        return 'formId';
    }
}
