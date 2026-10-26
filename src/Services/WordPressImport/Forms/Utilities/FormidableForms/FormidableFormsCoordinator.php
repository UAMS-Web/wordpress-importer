<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;

use Illuminate\Console\Command;
use Statamic\Forms\Form;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginCoordinatorTrait;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginFormCreationCoordinatorContract;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;

/**
 * Coordinates all Formidable Forms operations for the WordPress import process.
 *
 * This class acts as a coordinator for all Formidable Forms operations,
 * delegating specific responsibilities to specialized utility classes:
 *   - ContentDetector: for detecting Formidable Forms usage in XML
 *   - FormIdExtractor: for extracting form IDs from content
 *   - DataManager: for managing Formidable Forms data storage
 *   - FormCreationCoordinator: for coordinating form creation
 *   - UserInteractionHandler: for handling user interaction
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * coordination between specialized utility classes.
 */
class FormidableFormsCoordinator
{
    /**
     * Use shared functionality for form plugin coordination.
     */
    use FormPluginCoordinatorTrait;

    /**
     * Create a new FormidableFormsCoordinator instance.
     *
     * @param  ContentDetector  $contentDetector  Service for detecting Formidable Forms content
     * @param  FormIdExtractor  $formIdExtractor  Service for extracting form IDs
     * @param  DataManager  $dataManager  Service for managing Formidable Forms data
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
     * Set the loaded Formidable Forms forms data.
     *
     * @param  array<int|string, mixed>|null  $forms  The loaded Formidable Forms forms array
     */
    public function setFormidableFormsData(
        ?array $forms
    ): void {
        $this->setFormPluginData($forms);
    }

    /**
     * Get the loaded Formidable Forms forms data.
     *
     * @return array<int|string, mixed>|null The loaded Formidable Forms forms array
     */
    public function getFormidableFormsData(): ?array
    {
        return $this->getFormPluginData();
    }

    /**
     * Detect if any Formidable Forms blocks or shortcodes are present in the XML.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @return bool True if Formidable Forms usage is detected, false otherwise
     */
    public function hasFormidableFormsContent(
        XmlParser $xmlParser
    ): bool {
        return $this->hasFormPluginContent($xmlParser);
    }

    /**
     * Collect the Formidable Forms form IDs referenced in blocks or shortcodes, with their pages.
     *
     * Replaces `getFormidableFormsFormIdsUsed()`, which returned bare IDs and had no caller.
     * The page slugs are what makes extraction useful: form creation reads IDs recorded by
     * the block processors, so the only thing extraction can contribute is telling an
     * operator which page references a form their export does not contain.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @return array<array-key, list<string>> Form ID => the page slugs referencing it
     */
    public function getFormidableFormsFormIdsWithPages(
        XmlParser $xmlParser
    ): array {
        return $this->getFormPluginFormIdsWithPages($xmlParser);
    }

    /**
     * Create a Statamic form for a Formidable Forms form ID when needed by processors.
     *
     * @param  int  $formId  The Formidable Forms form ID
     * @param  string  $siteName  The Statamic site name
     * @param  string  $siteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @return ?Form The created Statamic form or null if not found
     */
    public function createFormidableFormsFormIfNeeded(
        int $formId,
        string $siteName,
        string $siteHandle,
        AssetImporter $assetImporter
    ): ?Form {
        if (! $this->formCreationCoordinator instanceof FormPluginFormCreationCoordinatorContract) {
            return null;
        }

        $formCreationCoordinator = $this->formCreationCoordinator;

        if (! $formCreationCoordinator instanceof FormCreationCoordinator) {
            return null;
        }

        return $this->createFormPluginFormIfNeeded(
            $formId,
            $siteName,
            $siteHandle,
            $formCreationCoordinator->createStatamicFormFromFormidableForm(...),
            'Formidable Forms',
            $assetImporter
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
        return $this->getCreatedFormHandleForPlugin($formId);
    }

    /**
     * Clear the Formidable Forms data.
     *
     * Useful for resetting between different import sessions.
     */
    public function clearFormidableFormsData(): void
    {
        $this->clearFormPluginData();
    }

    /**
     * Handle Formidable Forms detection and user interaction.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return bool True if Formidable Forms data was loaded, false otherwise
     */
    public function handleFormidableFormsDetection(
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
     * Set the form creation coordinator after construction.
     *
     * @param  FormCreationCoordinator  $formCreationCoordinator  The form creation coordinator
     */
    public function setFormCreationCoordinator(
        FormCreationCoordinator $formCreationCoordinator
    ): void {
        $this->formCreationCoordinator = $formCreationCoordinator;

        // Set this coordinator on the FormCreationCoordinator so it can access plugin-specific methods
        $formCreationCoordinator->setCoordinator($this);
    }

    /**
     * Get the form title attribute name for Formidable Forms.
     *
     * Formidable Forms uses 'name' for the form title.
     *
     * @return string The attribute name used for the form title
     */
    public function getFormTitleAttributeName(): string
    {
        return 'name';
    }

    /**
     * Create a FormCreationCoordinator instance for Formidable Forms.
     *
     * @return FormCreationCoordinator The FormCreationCoordinator instance
     */
    protected function createFormCreationCoordinator(): FormCreationCoordinator
    {
        return new FormCreationCoordinator(
            $this->dataManager,
            new FormConfigurationManager
        );
    }
}
