<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\BaseFormPluginSelector;

/**
 * Shared functionality for user interaction handlers.
 */
trait UserInteractionHandlerTrait
{
    protected BaseContentDetector $contentDetector;

    protected BaseDataManager $dataManager;

    protected BaseFormPluginSelector $formSelector;

    protected function initializeUserInteractionHandler(
        BaseContentDetector $contentDetector,
        BaseDataManager $dataManager,
        BaseFormPluginSelector $formSelector
    ): void {
        $this->contentDetector = $contentDetector;
        $this->dataManager = $dataManager;
        $this->formSelector = $formSelector;
    }

    protected function handleFormPluginDetectionInternal(
        XmlParser $xmlParser,
        InputInterface $input,
        OutputInterface $output,
        Command $command,
        string $pluginName
    ): bool {
        if (! $this->contentDetector->hasFormPluginContent($xmlParser)) {
            return false;
        }

        $output->writeln("<info>{$pluginName} blocks or shortcodes detected in the XML.</info>");

        $formsData = $this->formSelector->select(
            $input,
            $output,
            $command
        );

        $this->dataManager->setFormsData($formsData);

        return $formsData !== null;
    }
}
