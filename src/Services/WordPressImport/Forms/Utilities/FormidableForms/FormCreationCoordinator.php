<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;

use ReflectionClass;
use Statamic\Forms\Form;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Creators\FormCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormCreationCoordinatorTrait;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginFormCreationCoordinatorContract;

/**
 * Coordinates Statamic form creation from Formidable Forms data.
 *
 * This class is responsible for coordinating the creation of Statamic forms
 * when needed by processors. It handles validation, error checking, and caching.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * form creation coordination.
 */
class FormCreationCoordinator implements FormPluginFormCreationCoordinatorContract
{
    /**
     * Use shared functionality for form creation coordination.
     */
    use FormCreationCoordinatorTrait;

    /**
     * The form plugin coordinator instance.
     */
    private ?FormidableFormsCoordinator $coordinator;

    /**
     * Create a new FormCreationCoordinator instance.
     *
     * @param  BaseDataManager  $dataManager  Service for managing Formidable Forms data
     * @param  FormConfigurationManager  $formConfigurationManager  Service for configuring form settings
     * @param  ?FormidableFormsCoordinator  $coordinator  The form plugin coordinator (optional)
     */
    public function __construct(
        BaseDataManager $dataManager,
        FormConfigurationManager $formConfigurationManager,
        ?FormidableFormsCoordinator $coordinator = null
    ) {
        // Create the Formidable Forms-specific FormBlueprintCreator
        $formBlueprintCreator = new FormBlueprintCreator;

        // Create the FormCreator with the plugin-specific FormBlueprintCreator and coordinator
        $formCreator = new FormCreator(
            $formBlueprintCreator,
            $coordinator
        );

        $this->coordinator = $coordinator;

        $this->initializeFormCreationCoordinator(
            $dataManager,
            $formCreator,
            $formConfigurationManager
        );
    }

    /**
     * Set the form plugin coordinator after construction.
     *
     * @param  object  $coordinator  The form plugin coordinator
     */
    public function setCoordinator(
        object $coordinator
    ): void {
        if (! $coordinator instanceof FormidableFormsCoordinator) {
            return;
        }

        $this->coordinator = $coordinator;

        // Update the FormCreator with the new coordinator
        // Use reflection to set the coordinator property
        $reflection = new ReflectionClass($this->formCreator);
        $coordinatorProperty = $reflection->getProperty('coordinator');
        $coordinatorProperty->setValue(
            $this->formCreator,
            $coordinator
        );
    }

    /**
     * Get the form plugin coordinator instance.
     *
     * @return ?FormidableFormsCoordinator The form plugin coordinator, if set
     */
    public function getCoordinator(): ?FormidableFormsCoordinator
    {
        return $this->coordinator;
    }

    /**
     * Create a Statamic form for a Formidable Forms form ID.
     *
     * Handles caching, validation, and delegates to FormCreator for actual creation.
     *
     * @param  int  $formId  The Formidable Forms form ID
     * @param  array<int|string, mixed>  $formidableFormsData  The loaded Formidable Forms JSON data
     * @param  string  $siteName  The Statamic site name
     * @param  string  $siteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @return ?Form The created Statamic form or null if not found
     */
    public function createStatamicFormFromFormidableForm(
        int $formId,
        array $formidableFormsData,
        string $siteName,
        string $siteHandle,
        AssetImporter $assetImporter
    ): ?Form {
        return $this->createStatamicFormFromWordPressForm(
            $formId,
            $formidableFormsData,
            $siteName,
            $siteHandle,
            'formidable_forms',
            'formidable-forms',
            'Formidable Forms',
            $this->findFormidableFormsFormById(...),
            $assetImporter
        );
    }

    /**
     * Find a Formidable Forms form by ID in the provided data.
     *
     * @param  int  $formId  The Formidable Forms form ID to find
     * @param  array<int|string, mixed>  $formidableFormsData  The loaded Formidable Forms JSON data
     * @return array<int|string, mixed>|null The form data or null if not found
     */
    protected function findFormidableFormsFormById(
        int $formId,
        array $formidableFormsData
    ): ?array {
        return $this->findWordPressFormById(
            $formId,
            $formidableFormsData
        );
    }

    /**
     * Get the created Statamic form handle for a Formidable Forms form ID.
     *
     * @param  int  $formId  The Formidable Forms form ID
     * @return ?string The Statamic form handle or null if not created
     */
    public function getCreatedFormHandle(
        int $formId
    ): ?string {
        return $this->getCreatedFormHandleInternal(
            $formId,
            'formidable_forms'
        );
    }
}
