<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\Validators;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;

/**
 * Validates people_group taxonomy terms used in people entries and show-people
 * shortcodes.
 *
 * Handles validation and creation of people_group taxonomy terms referenced in
 * people entries and show-people shortcodes. Automatically creates missing terms
 * to resolve shortcode references and ensure proper taxonomy structure.
 */
class PeopleGroupValidator extends BaseTaxonomyValidator
{
    /**
     * The context for this validator instance.
     */
    private ?string $context = null;

    /**
     * Set the context for this validator instance.
     *
     * @param  string  $context  The context ('entries' or 'shortcodes')
     */
    public function setContext(
        string $context
    ): void {
        $this->context = $context;
    }

    /**
     * Get the taxonomy handle this validator is responsible for.
     *
     * @return string The taxonomy handle
     */
    protected function getTaxonomyHandle(): string
    {
        return 'people_groups';
    }

    /**
     * Get the display name for this taxonomy type.
     *
     * @return string The display name
     */
    protected function getTaxonomyDisplayName(): string
    {
        return 'people group';
    }

    /**
     * Get the shortcode or content type name for user messaging.
     *
     * @return string The content type name
     */
    protected function getContentTypeName(): string
    {
        // Check if the context is set to 'entries'
        if ($this->context === 'entries') {
            // Return the content type name for people entries
            return 'people entries';
        }

        // Default fallback
        return 'people shortcodes';
    }
}
