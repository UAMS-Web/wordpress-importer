<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms;

use ReflectionClass;
use Statamic\Forms\Form;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Creators\FormCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\BaseDataManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormCreationCoordinatorTrait;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginFormCreationCoordinatorContract;

/**
 * Coordinates Statamic form creation from Ninja Forms exports.
 *
 * This class is responsible for coordinating the creation of Statamic forms
 * from Ninja Forms `.nff` export data.
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
     * The Ninja Forms coordinator, when one has been supplied.
     */
    protected ?NinjaFormsCoordinator $coordinator = null;

    /**
     * Create a new FormCreationCoordinator instance.
     *
     * @param  BaseDataManager  $dataManager  Service for managing Ninja Forms data
     * @param  FormConfigurationManager  $formConfigurationManager  Service for mapping form configuration
     * @param  ?NinjaFormsCoordinator  $coordinator  The Ninja Forms coordinator (optional)
     */
    public function __construct(
        BaseDataManager $dataManager,
        FormConfigurationManager $formConfigurationManager,
        ?NinjaFormsCoordinator $coordinator = null
    ) {
        // Create the Ninja Forms-specific FormBlueprintCreator.
        $formBlueprintCreator = new FormBlueprintCreator;

        // Create the FormCreator with the plugin-specific blueprint creator and coordinator.
        $formCreator = new FormCreator(
            $formBlueprintCreator,
            $coordinator
        );

        // Store the coordinator for potential future use.
        $this->coordinator = $coordinator;

        $this->initializeFormCreationCoordinator(
            $dataManager,
            $formCreator,
            $formConfigurationManager
        );
    }

    /**
     * Create a Statamic form from a Ninja Forms export.
     *
     * @param  int  $formId  The Ninja Forms form ID
     * @param  array<int|string, mixed>  $ninjaFormsData  The loaded Ninja Forms exports
     * @param  string  $siteName  The Statamic site name
     * @param  string  $siteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @return ?Form The created Statamic form, or null when the form was not found
     */
    public function createStatamicFormFromNinjaForm(
        int $formId,
        array $ninjaFormsData,
        string $siteName,
        string $siteHandle,
        AssetImporter $assetImporter
    ): ?Form {
        return $this->createStatamicFormFromWordPressForm(
            $formId,
            $ninjaFormsData,
            $siteName,
            $siteHandle,
            'ninja_forms',
            'ninja-forms',
            'Ninja Forms',
            $this->findNinjaFormsFormById(...),
            $assetImporter
        );
    }

    /**
     * Find a Ninja Forms export by the form ID it was supplied for.
     *
     * A `.nff` holds one form per file and carries no ID of its own, so the store is
     * keyed by the ID detection surfaced and the operator answered the prompt for — see
     * NinjaForms\DataManager. That makes this a direct lookup rather than the scan the
     * sibling importers need over a list of forms.
     *
     * @param  int  $formId  The Ninja Forms form ID
     * @param  array<int|string, mixed>  $ninjaFormsData  The loaded Ninja Forms exports
     * @return array<int|string, mixed>|null The export, or null when none was supplied
     */
    protected function findNinjaFormsFormById(
        int $formId,
        array $ninjaFormsData
    ): ?array {
        $form = $ninjaFormsData[$formId] ?? null;

        return \is_array($form) ? $form : null;
    }

    /**
     * Get the Statamic form handle created for a Ninja Forms form ID.
     *
     * @param  int  $formId  The Ninja Forms form ID
     * @return string|null The created form handle, or null when none was created
     */
    public function getCreatedFormHandle(
        int $formId
    ): ?string {
        return $this->getCreatedFormHandleInternal(
            $formId,
            'ninja_forms'
        );
    }

    /**
     * Set the Ninja Forms coordinator after construction.
     *
     * @param  mixed  $coordinator  The Ninja Forms coordinator
     */
    public function setCoordinator(
        $coordinator
    ): void {
        if (! $coordinator instanceof NinjaFormsCoordinator) {
            return;
        }

        $this->coordinator = $coordinator;

        // Hand the coordinator to the FormCreator too, which reads every form title through it
        $reflection = new ReflectionClass($this->formCreator);
        $coordinatorProperty = $reflection->getProperty('coordinator');
        $coordinatorProperty->setValue(
            $this->formCreator,
            $coordinator
        );
    }

    /**
     * Get the Ninja Forms coordinator.
     *
     * @return ?NinjaFormsCoordinator The coordinator, when one has been supplied
     */
    public function getCoordinator(): ?NinjaFormsCoordinator
    {
        return $this->coordinator;
    }
}
