<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\GravityForms;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginValidator;

/**
 * Validates Gravity Forms export file content.
 *
 * This class extends BaseFormPluginValidator to provide Gravity Forms-specific
 * validation functionality.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * validation operations.
 */
class Validator extends BaseFormPluginValidator
{
    /**
     * Create a new Validator instance.
     */
    public function __construct()
    {
        parent::__construct('GravityForms');
    }

    /**
     * Validate the structure of a single form.
     *
     * Checks that the form has at least 'id' and 'title' fields, as required by
     * Gravity Forms.
     *
     * @param  mixed  $form  The form data to validate
     * @return bool True if the form structure is valid, false otherwise
     */
    protected function validateFormStructure(
        mixed $form
    ): bool {
        // Gravity Forms forms should have at least 'id' and 'title'
        return \is_array($form)
            && isset($form['id'])
            && isset($form['title']);
    }
}
