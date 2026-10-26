<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\GravityForms;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\GravityForms\Validator as GravityFormsValidator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;

/**
 * Handles user interaction for Gravity Forms export file selection.
 *
 * This class extends BaseFormPluginSelector to provide Gravity Forms-specific
 * user interaction functionality.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * user interaction for Gravity Forms export file selection.
 */
class Selector extends BaseFormPluginSelector
{
    /**
     * Create a new Selector instance.
     *
     * The loader is injected rather than constructed here, because the command configures
     * its source once the run's `--source` is known and a locally built one would be a
     * different instance (wordpress-importer-archive#809). That is the same reason `MinervaKbSettings\Selector`
     * receives its loader by injection.
     *
     * @param  GravityFormsValidator  $validator  Service for validating Gravity Forms export files
     * @param  GravityFormsLoader  $loader  The sourcing seam, shared with the command
     */
    public function __construct(
        GravityFormsValidator $validator,
        GravityFormsLoader $loader
    ) {
        parent::__construct(
            $validator,
            'GravityForms',
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
        return 'gravity_forms';
    }
}
