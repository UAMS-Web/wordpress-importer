<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms\Validator as FormidableFormsValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;

/**
 * Handles user interaction for Formidable Forms export file selection.
 *
 * This class extends BaseFormPluginSelector to provide Formidable Forms-specific
 * user interaction functionality.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * user interaction for Formidable Forms export file selection.
 */
class Selector extends BaseFormPluginSelector
{
    /**
     * Create a new Selector instance.
     *
     * @param  FormidableFormsValidator  $validator  Service for validating Formidable Forms export files
     * @param  FormidableFormsLoader  $loader  The sourcing seam, shared with the command
     */
    public function __construct(
        FormidableFormsValidator $validator,
        FormidableFormsLoader $loader
    ) {
        // The loader is injected rather than built here, because the command configures its
        // source once the run's `--source` is known and a locally built one would be a
        // different instance (wordpress-importer-archive#810). The file half is still the Formidable-specific XML
        // reader, which the loader wraps; only the API path differs (wordpress-importer-archive#811).
        parent::__construct(
            $validator,
            'FormidableForms',
            $loader
        );
    }

    /**
     * Get the plugin slug for file path suggestions.
     *
     * @return string The plugin slug
     */
    protected function getPluginSlug(): string
    {
        return 'formidable_forms';
    }
}
