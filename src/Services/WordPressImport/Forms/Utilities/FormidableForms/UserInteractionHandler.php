<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormidableForms;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginUserInteractionHandlerContract;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\UserInteractionHandlerTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\FormidableForms\Selector as FormidableFormsSelector;

/**
 * Handles user interaction for Formidable Forms operations.
 *
 * This class provides Formidable Forms-specific user interaction functionality.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * user interaction operations.
 */
class UserInteractionHandler implements FormPluginUserInteractionHandlerContract
{
    /**
     * Use shared functionality for user interaction handling.
     */
    use UserInteractionHandlerTrait;

    /**
     * Create a new UserInteractionHandler instance.
     *
     * @param  ContentDetector  $contentDetector  Service for detecting Formidable Forms content
     * @param  DataManager  $dataManager  Service for managing Formidable Forms data
     * @param  FormidableFormsSelector  $formidableFormsSelector  Service for Formidable Forms user interaction
     */
    public function __construct(
        ContentDetector $contentDetector,
        DataManager $dataManager,
        FormidableFormsSelector $formidableFormsSelector
    ) {
        $this->initializeUserInteractionHandler(
            $contentDetector,
            $dataManager,
            $formidableFormsSelector
        );
    }

    /**
     * Handle Formidable Forms detection and user interaction.
     *
     * Detects Formidable Forms usage in XML and prompts user for export file if
     * needed.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return bool True if Formidable Forms data was loaded, false otherwise
     */
    public function handleFormPluginDetection(
        XmlParser $xmlParser,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): bool {
        return $this->handleFormPluginDetectionInternal(
            $xmlParser,
            $input,
            $output,
            $command,
            'Formidable Forms'
        );
    }
}
