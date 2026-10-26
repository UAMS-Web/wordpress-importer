<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginValidator;

/**
 * Validates Formidable Forms export file content.
 *
 * This class extends BaseFormPluginValidator to provide Formidable Forms-specific
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
        parent::__construct('FormidableForms');
    }

    /**
     * Validate the structure of a single form.
     *
     * Checks that the form has at least 'id' and 'name' fields, as required by
     * Formidable Forms.
     *
     * @param  mixed  $form  The form data to validate
     * @return bool True if the form structure is valid, false otherwise
     */
    protected function validateFormStructure(
        mixed $form
    ): bool {
        // Formidable Forms forms should have at least 'id' and 'name'
        return \is_array($form)
            && isset($form['id'])
            && isset($form['name']);
    }
}
