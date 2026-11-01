<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseFormIdExtractor;

/**
 * Extracts Ninja Forms form IDs from WordPress XML export content.
 *
 * This class extends BaseFormIdExtractor to provide Ninja Forms-specific
 * form ID extraction from both blocks and shortcodes.
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
        parent::__construct('NinjaForms');
    }

    /**
     * Get the regex pattern for matching Ninja Forms blocks.
     *
     * @return string The regex pattern for blocks
     */
    protected function getBlockPattern(): string
    {
        // The block is self-closing — `<!-- wp:ninja-forms/form {…} /-->` — so the
        // optional slash before the comment terminator has to be matched.
        return '/<!--\s*wp:ninja-forms\/form\s+({.*?})\s*\/?-->/';
    }

    /**
     * Get the regex pattern for matching Ninja Forms shortcodes.
     *
     * @return string The regex pattern for shortcodes
     */
    protected function getShortcodePattern(): string
    {
        // Quotes are optional and may be single or double; the corpus carries
        // `[ninja_form id='2']` alongside `[ninja_form id=3]`.
        return '/\[ninja_form\s+id=["\']?([0-9]+)["\']?/';
    }

    /**
     * Get the form ID attribute name used inside a Ninja Forms block.
     *
     * @return string The form ID attribute name
     */
    protected function getFormIdAttribute(): string
    {
        // Ninja Forms spells this `formID` — capital D — where Gravity Forms uses
        // `formId`. Matching the plugin's own casing is what makes block extraction work.
        return 'formID';
    }
}
