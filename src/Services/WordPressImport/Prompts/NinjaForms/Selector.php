<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NinjaForms;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\NinjaFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NinjaForms\Validator as NinjaFormsValidator;

/**
 * Handles user interaction for Ninja Forms export file selection.
 *
 * This class extends BaseFormPluginSelector to provide Ninja Forms-specific
 * user interaction functionality.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * user interaction for Ninja Forms export file selection.
 */
class Selector extends BaseFormPluginSelector
{
    /**
     * Create a new Selector instance.
     *
     * @param  NinjaFormsValidator  $validator  Service for validating Ninja Forms export files
     * @param  NinjaFormsLoader  $loader  The sourcing seam, shared with the command
     */
    public function __construct(
        NinjaFormsValidator $validator,
        NinjaFormsLoader $loader
    ) {
        // The loader is injected rather than built here, because the command configures its
        // source once the run's `--source` is known and a locally built one would be a
        // different instance (wordpress-importer-archive#810). A `.nff` is plain JSON despite the extension, so the
        // file half still needs no Ninja-specific reader.
        parent::__construct(
            $validator,
            'NinjaForms',
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
        return 'ninja_forms';
    }
}
