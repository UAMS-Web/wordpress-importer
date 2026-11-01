<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\NinjaForms;

use Illuminate\Console\Command;
use Statamic\Forms\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinatorTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;

/**
 * Coordinates all Ninja Forms import operations.
 *
 * This class acts as a coordinator for all Ninja Forms operations,
 * delegating to the focused services that carry each responsibility.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * coordination.
 */
class NinjaFormsCoordinator
{
    /**
     * Use shared functionality for form plugin coordination.
     */
    use FormPluginCoordinatorTrait;

    /**
     * Create a new NinjaFormsCoordinator instance.
     *
     * @param  ContentDetector  $contentDetector  Service for detecting Ninja Forms content
     * @param  FormIdExtractor  $formIdExtractor  Service for extracting form IDs
     * @param  DataManager  $dataManager  Service for managing Ninja Forms data
     * @param  UserInteractionHandler  $userInteractionHandler  Service for handling user interaction
     * @param  ?FormCreationCoordinator  $formCreationCoordinator  Service for coordinating form creation (optional)
     */
    public function __construct(
        ContentDetector $contentDetector,
        FormIdExtractor $formIdExtractor,
        DataManager $dataManager,
        UserInteractionHandler $userInteractionHandler,
        ?FormCreationCoordinator $formCreationCoordinator = null
    ) {
        $this->initializeFormPluginCoordinator(
            $contentDetector,
            $formIdExtractor,
            $dataManager,
            $formCreationCoordinator,
            $userInteractionHandler
        );
    }

    /**
     * Set the form creation coordinator after construction.
     *
     * @param  FormCreationCoordinator  $formCreationCoordinator  The form creation coordinator
     */
    public function setFormCreationCoordinator(
        FormCreationCoordinator $formCreationCoordinator
    ): void {
        $this->formCreationCoordinator = $formCreationCoordinator;

        // Give the creation coordinator a way back to this one for plugin-specific calls.
        $formCreationCoordinator->setCoordinator($this);
    }

    /**
     * Set the loaded Ninja Forms data.
     *
     * @param  array<int|string, mixed>|null  $forms  The loaded Ninja Forms exports
     */
    public function setNinjaFormsData(
        ?array $forms
    ): void {
        $this->setFormPluginData($forms);
    }

    /**
     * Get the loaded Ninja Forms data.
     *
     * @return array<int|string, mixed>|null The loaded Ninja Forms exports
     */
    public function getNinjaFormsData(): ?array
    {
        return $this->getFormPluginData();
    }

    /**
     * Clear the Ninja Forms data.
     */
    public function clearNinjaFormsData(): void
    {
        $this->clearFormPluginData();
    }

    /**
     * Detect whether any Ninja Forms blocks or shortcodes are present in the XML.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @return bool True if Ninja Forms usage is detected, false otherwise
     */
    public function hasNinjaFormsContent(
        XmlParser $xmlParser
    ): bool {
        return $this->hasFormPluginContent($xmlParser);
    }

    /**
     * Collect the Ninja Forms form IDs referenced in blocks or shortcodes, with their pages.
     *
     * Replaces `getNinjaFormsFormIdsUsed()`, which returned bare IDs and had no caller.
     * The page slugs are what makes extraction useful: form creation reads IDs recorded by
     * the block processors, so the only thing extraction can contribute is telling an
     * operator which page references a form their export does not contain.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @return array<array-key, list<string>> Form ID => the page slugs referencing it
     */
    public function getNinjaFormsFormIdsWithPages(
        XmlParser $xmlParser
    ): array {
        return $this->getFormPluginFormIdsWithPages($xmlParser);
    }

    /**
     * Create a Statamic form for a Ninja Forms form ID when needed by processors.
     *
     * @param  int  $formId  The Ninja Forms form ID
     * @param  string  $siteName  The Statamic site name
     * @param  string  $siteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @return ?Form The created Statamic form, or null when not found
     */
    public function createNinjaFormsFormIfNeeded(
        int $formId,
        string $siteName,
        string $siteHandle,
        AssetImporter $assetImporter
    ): ?Form {
        $formCreationCoordinator = $this->formCreationCoordinator;

        // Nothing can be created without a creation coordinator.
        if (! $formCreationCoordinator instanceof FormCreationCoordinator) {
            return null;
        }

        return $this->createFormPluginFormIfNeeded(
            $formId,
            $siteName,
            $siteHandle,
            $formCreationCoordinator->createStatamicFormFromNinjaForm(...),
            'Ninja Forms',
            $assetImporter
        );
    }

    /**
     * Handle Ninja Forms detection and user interaction.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return bool True if Ninja Forms data was loaded, false otherwise
     */
    public function handleNinjaFormsDetection(
        XmlParser $xmlParser,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): bool {
        return $this->handleFormPluginDetection(
            $xmlParser,
            $input,
            $output,
            $command
        );
    }

    /**
     * Get the attribute name that carries the form title.
     *
     * A `.nff` nests this under `settings`, which
     * NinjaForms\FormConfigurationManager::parseFormData() lifts to the top level, so the
     * bare key is what callers see.
     *
     * @return string The attribute name used for the form title
     */
    public function getFormTitleAttributeName(): string
    {
        return 'title';
    }

    /**
     * Create a FormCreationCoordinator instance for Ninja Forms.
     *
     * @return FormCreationCoordinator The FormCreationCoordinator instance
     */
    protected function createFormCreationCoordinator(): FormCreationCoordinator
    {
        return new FormCreationCoordinator(
            $this->dataManager,
            new FormConfigurationManager,
            $this
        );
    }
}
