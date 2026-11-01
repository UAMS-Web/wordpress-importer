<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;

use ReflectionClass;
use Statamic\Forms\Form;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Creators\FormCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormCreationCoordinatorTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginFormCreationCoordinatorContract;

/**
 * Coordinates Statamic form creation from Gravity Forms data.
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
    private ?GravityFormsCoordinator $coordinator;

    /**
     * Create a new FormCreationCoordinator instance.
     *
     * @param  BaseDataManager  $dataManager  Service for managing Gravity Forms data
     * @param  FormConfigurationManager  $formConfigurationManager  Service for configuring form settings
     * @param  ?GravityFormsCoordinator  $coordinator  The form plugin coordinator instance
     */
    public function __construct(
        BaseDataManager $dataManager,
        FormConfigurationManager $formConfigurationManager,
        ?GravityFormsCoordinator $coordinator = null
    ) {
        // Create the Gravity Forms-specific FormBlueprintCreator
        $formBlueprintCreator = new FormBlueprintCreator;

        // Create the FormCreator with the plugin-specific FormBlueprintCreator and coordinator
        $formCreator = new FormCreator(
            $formBlueprintCreator,
            $coordinator
        );

        // Store the coordinator for potential future use
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
        if (! $coordinator instanceof GravityFormsCoordinator) {
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
     * @return ?GravityFormsCoordinator The form plugin coordinator, if set
     */
    public function getCoordinator(): ?GravityFormsCoordinator
    {
        return $this->coordinator;
    }

    /**
     * Create a Statamic form for a Gravity Forms form ID.
     *
     * Handles caching, validation, and delegates to FormCreator for actual creation.
     *
     * @param  int  $formId  The Gravity Forms form ID
     * @param  array<int|string, mixed>  $gravityFormsData  The loaded Gravity Forms JSON data
     * @param  string  $siteName  The Statamic site name
     * @param  string  $siteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @return ?Form The created Statamic form or null if not found
     */
    public function createStatamicFormFromGravityForm(
        int $formId,
        array $gravityFormsData,
        string $siteName,
        string $siteHandle,
        AssetImporter $assetImporter
    ): ?Form {
        return $this->createStatamicFormFromWordPressForm(
            $formId,
            $gravityFormsData,
            $siteName,
            $siteHandle,
            'gravity_forms',
            'gravity-forms',
            'Gravity Forms',
            $this->findGravityFormsFormById(...),
            $assetImporter
        );
    }

    /**
     * Find a Gravity Forms form by ID in the provided data.
     *
     * @param  int  $formId  The Gravity Forms form ID to find
     * @param  array<int|string, mixed>  $gravityFormsData  The loaded Gravity Forms JSON data
     * @return array<int|string, mixed>|null The form data or null if not found
     */
    protected function findGravityFormsFormById(
        int $formId,
        array $gravityFormsData
    ): ?array {
        return $this->findWordPressFormById(
            $formId,
            $gravityFormsData
        );
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
     * Get the created Statamic form handle for a Gravity Forms form ID.
     *
     * @param  int  $formId  The Gravity Forms form ID
     * @return ?string The Statamic form handle or null if not created
     */
    public function getCreatedFormHandle(
        int $formId
    ): ?string {
        return $this->getCreatedFormHandleInternal(
            $formId,
            'gravity_forms'
        );
    }
}
