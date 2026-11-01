<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\GravityForms;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\FormPluginUserInteractionHandlerContract;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities\UserInteractionHandlerTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\GravityForms\Selector as GravityFormsSelector;

/**
 * Handles user interaction for Gravity Forms operations.
 *
 * This class provides Gravity Forms-specific user interaction functionality.
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
     * @param  ContentDetector  $contentDetector  Service for detecting Gravity Forms content
     * @param  DataManager  $dataManager  Service for managing Gravity Forms data
     * @param  GravityFormsSelector  $gravityFormsSelector  Service for Gravity Forms user interaction
     */
    public function __construct(
        ContentDetector $contentDetector,
        DataManager $dataManager,
        GravityFormsSelector $gravityFormsSelector
    ) {
        $this->initializeUserInteractionHandler(
            $contentDetector,
            $dataManager,
            $gravityFormsSelector
        );
    }

    /**
     * Handle Gravity Forms detection and user interaction.
     *
     * Detects Gravity Forms usage in XML and prompts user for export file if needed.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return bool True if Gravity Forms data was loaded, false otherwise
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
            'Gravity Forms'
        );
    }
}
