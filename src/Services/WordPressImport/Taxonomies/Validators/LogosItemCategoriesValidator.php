<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\Validators;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\BaseTaxonomyValidator;

/**
 * Validates logos_item_categories taxonomy terms used in logo list entries.
 *
 * Handles validation and creation of logos_item_categories taxonomy terms
 * referenced in logo list entries. Automatically creates missing terms to ensure
 * proper taxonomy structure for logo categorization.
 */
class LogosItemCategoriesValidator extends BaseTaxonomyValidator
{
    /**
     * Get the taxonomy handle this validator is responsible for.
     *
     * @return string The taxonomy handle
     */
    protected function getTaxonomyHandle(): string
    {
        return 'logos_item_categories';
    }

    /**
     * Get the display name for this taxonomy type.
     *
     * @return string The display name
     */
    protected function getTaxonomyDisplayName(): string
    {
        return 'logos item category';
    }

    /**
     * Get the shortcode or content type name for user messaging.
     *
     * @return string The content type name
     */
    protected function getContentTypeName(): string
    {
        return 'logo list entries';
    }
}
