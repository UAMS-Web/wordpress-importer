<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts;

/**
 * Base class for form plugin validators.
 *
 * This abstract class provides common validation functionality
 * that can be extended by specific form plugin validators.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * common validation operations.
 */
abstract class BaseFormPluginValidator
{
    /**
     * Create a new BaseFormPluginValidator instance.
     *
     * @param  string  $pluginName  The name of the plugin for logging
     */
    public function __construct(protected string $pluginName) {}

    /**
     * Validate the forms data structure.
     *
     * Checks that the provided data is an array of forms with required
     * structure for form creation.
     *
     * @param  mixed  $forms  The forms data to validate
     * @return bool True if valid, false otherwise
     */
    public function validate(
        mixed $forms
    ): bool {
        // Ensure the forms parameter is actually an array
        if (! \is_array($forms)) {
            return false;
        }

        return array_any($forms, fn ($form): bool => $this->validateFormStructure($form));
    }

    /**
     * Validate the structure of a single form.
     *
     * @param  mixed  $form  The form data to validate
     * @return bool True if valid, false otherwise
     */
    abstract protected function validateFormStructure(
        mixed $form
    ): bool;
}
