<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities;

use Illuminate\Support\Facades\Log;
use Statamic\Forms\Form;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Creators\FormCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms\FormConfigurationManager as FormidableFormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms\FormConfigurationManager as GravityFormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms\FormConfigurationManager as NinjaFormConfigurationManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Shared functionality for form creation coordinators.
 *
 * This trait provides common methods used by both Gravity Forms and Formidable
 * Forms form creation coordinators to eliminate code duplication.
 */
trait FormCreationCoordinatorTrait
{
    /**
     * The data manager service instance.
     */
    protected BaseDataManager $dataManager;

    /**
     * The Statamic FormCreator instance.
     */
    protected FormCreator $formCreator;

    /**
     * The form configuration manager instance.
     */
    protected GravityFormConfigurationManager|FormidableFormConfigurationManager|NinjaFormConfigurationManager $formConfigurationManager;

    /**
     * Page data mapping from WordPress post IDs to Statamic entry IDs.
     *
     * @var array<int|string, mixed>
     */
    protected array $pageDataMapping = [];

    /**
     * Cache of created forms to avoid duplicates during import.
     *
     * @var array<string, Form>
     */
    protected array $createdForms = [];

    /**
     * Create a new FormCreationCoordinator instance.
     *
     * @param  BaseDataManager  $dataManager  Service for managing form data
     * @param  FormCreator  $formCreator  Service for creating Statamic forms
     * @param  GravityFormConfigurationManager|FormidableFormConfigurationManager|NinjaFormConfigurationManager  $formConfigurationManager  Service for configuring form settings
     */
    protected function initializeFormCreationCoordinator(
        BaseDataManager $dataManager,
        FormCreator $formCreator,
        GravityFormConfigurationManager|FormidableFormConfigurationManager|NinjaFormConfigurationManager $formConfigurationManager
    ): void {
        // Assign the provided data manager instance to the property
        $this->dataManager = $dataManager;

        // Assign the provided form creator instance to the property
        $this->formCreator = $formCreator;

        // Assign the provided form configuration manager instance to the property
        $this->formConfigurationManager = $formConfigurationManager;
    }

    /**
     * Set the page data mapping for WordPress post ID to Statamic entry ID
     * translation.
     *
     * @param  array<int|string, mixed>  $pageDataMapping  Array mapping WordPress post IDs to Statamic entry data
     */
    public function setPageDataMapping(
        array $pageDataMapping
    ): void {
        $this->pageDataMapping = $pageDataMapping;

        // Also set the page data mapping on the form configuration manager
        $this->formConfigurationManager->setPageDataMapping($pageDataMapping);
    }

    /**
     * Create a Statamic form for a WordPress form ID.
     *
     * Handles caching, validation, and delegates to FormCreator for actual creation.
     *
     * @param  int  $formId  The WordPress form ID
     * @param  array<int|string, mixed>  $formsData  The loaded WordPress forms JSON data
     * @param  string  $siteName  The Statamic site name
     * @param  string  $siteHandle  The Statamic site handle
     * @param  string  $pluginPrefix  The plugin prefix for cache key ( e.g., 'gravity_forms', 'formidable_forms')
     * @param  string  $pluginName  The plugin name for FormCreator (e.g., 'gravity-forms', 'formidable-forms')
     * @param  string  $logPrefix  The prefix for log messages
     * @param  callable  $findFormByIdCallback  Callback to find form by ID in the data
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @return ?Form The created Statamic form or null if not found
     */
    protected function createStatamicFormFromWordPressForm(
        int $formId,
        array $formsData,
        string $siteName,
        string $siteHandle,
        string $pluginPrefix,
        string $pluginName,
        string $logPrefix,
        callable $findFormByIdCallback,
        AssetImporter $assetImporter
    ): ?Form {
        // Check if we've already created this form during this import
        // Generate a cache key for this WordPress form ID
        $cacheKey = "{$pluginPrefix}_{$formId}";

        // Return the cached Statamic form if it was already created
        if (isset($this->createdForms[$cacheKey])) {
            return $this->createdForms[$cacheKey];
        }

        // Search for the WordPress form data by its ID
        $formData = $findFormByIdCallback(
            $formId,
            $formsData
        );

        // If the form data was not found, log a warning and return null
        if (! \is_array($formData) || $formData === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'form_id' => $formId,
                    'plugin_prefix' => $pluginPrefix,
                ]
            );

            // Log a warning that the form ID was not found in the provided data
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "FormCreationCoordinator::createStatamicFormFromWordPressForm: {$logPrefix} form ID {$formId} not found in provided data",
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate the form could not be created
            return null;
        }

        // Create the Statamic form
        // Delegate to the FormCreator to create the Statamic form.
        //
        // `$formsData` is passed WHOLE, not just `$formData`: a nested-form field names a
        // sibling form by id, and this is the last frame that still holds the other forms.
        // Below here only the single form survives, which is why the child's fields were
        // unreachable and the replicator imported empty (wordpress-importer-archive#1156).
        $statamicForm = $this->formCreator->createForm(
            $formData,
            $siteName,
            $siteHandle,
            $pluginName,
            null,
            $formsData
        );

        // If the Statamic form was created successfully, cache it and log the creation
        if ($statamicForm !== null) {
            // Configure email settings for every plugin that maps notifications. This is
            // an allow-list rather than an unconditional call, so a plugin missing from
            // it silently imports its forms with no email configuration at all.
            if (
                in_array($pluginName, ['gravity-forms', 'formidable-forms', 'ninja-forms'], true)
            ) {
                $this->formConfigurationManager->configureFormEmailSettings(
                    $statamicForm,
                    $formData,
                    $siteHandle,
                    $assetImporter
                );
            }

            // Store the created Statamic form in the cache for this import session
            $this->createdForms[$cacheKey] = $statamicForm;
        }

        // Return the created Statamic form (or null if creation failed)
        return $statamicForm;
    }

    /**
     * Find a WordPress form by ID in the provided data.
     *
     * @param  int  $formId  The WordPress form ID to find
     * @param  array<int|string, mixed>  $formsData  The loaded WordPress forms JSON data
     * @return array<int|string, mixed>|null The form data or null if not found
     */
    protected function findWordPressFormById(
        int $formId,
        array $formsData
    ): ?array {
        // Iterate through the provided forms to find a match by ID
        foreach ($formsData as $form) {
            if (! \is_array($form)) {
                continue;
            }

            // If the form's ID matches the requested ID, return the form data
            if (
                isset($form['id'])
                && \is_numeric($form['id'])
                && (int) $form['id'] === $formId
            ) {
                return $form;
            }
        }

        // Return null if no matching form was found
        return null;
    }

    /**
     * Clear the created forms cache.
     *
     * Useful for resetting between different import sessions.
     */
    public function clearCreatedFormsCache(): void
    {
        // Reset the created forms cache to an empty array
        $this->createdForms = [];
    }

    /**
     * Get the count of forms created during this session.
     *
     * @return int The number of forms created
     */
    public function getCreatedFormsCount(): int
    {
        return \count($this->createdForms);
    }

    /**
     * Get the created Statamic form handle for a WordPress form ID.
     *
     * @param  int  $formId  The WordPress form ID
     * @param  string  $pluginPrefix  The plugin prefix for cache key (e.g., 'gravity_forms', 'formidable_forms')
     * @return ?string The Statamic form handle or null if not created
     */
    protected function getCreatedFormHandleInternal(
        int $formId,
        string $pluginPrefix
    ): ?string {
        // Generate a cache key for this WordPress form ID using the plugin prefix
        $cacheKey = "{$pluginPrefix}_{$formId}";

        // Return the Statamic form handle if the form exists in cache, otherwise return null
        $cachedForm = $this->createdForms[$cacheKey] ?? null;

        if ($cachedForm instanceof Form) {
            $handle = $cachedForm->handle();

            return \is_string($handle) ? $handle : null;
        }

        return null;
    }
}
