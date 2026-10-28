<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings;

use Illuminate\Console\Command;
use RuntimeException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;

/**
 * Handles user interaction for MinervaKB settings file selection.
 *
 * This class is responsible for:
 *   - Displaying instructions to the user
 *   - Prompting for file path input
 *   - Validating file existence
 *   - Managing user confirmation flow
 *
 * Follows the Single Responsibility Principle by focusing solely on user
 * interaction.
 */
class Prompter
{
    /**
     * Include the HasPromptsSupport trait to provide platform detection for Laravel
     * Prompts support.
     */
    use HasPromptsSupport;

    /**
     * Include the HasQuestionHelper trait to provide centralized question handling.
     */
    use HasQuestionHelper;

    /**
     * Display instructions and prompt user for MinervaKB settings file.
     *
     * @param  InputInterface  $input  Console input interface for user interaction
     * @param  OutputInterface  $output  Console output interface for feedback
     * @param  Command  $command  Command instance for helper access and output
     * @return ?string The file path or null if user chooses to skip
     */
    public function promptForSettingsFile(
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): ?string {
        $this->displayInstructions($output);

        return $this->promptForFilePath(
            $input,
            $output,
            $command
        );
    }

    /**
     * Display instructions for obtaining the MinervaKB settings file.
     *
     * @param  OutputInterface  $output  Console output interface
     */
    protected function displayInstructions(
        OutputInterface $output
    ): void {
        $output->writeln('<info>MinervaKB Settings Configuration</info>');
        $output->writeln('');
        $output->writeln('You can optionally provide a MinervaKB export settings JSON file to map configuration values to Statamic fields.');
        $output->writeln('');
        $output->writeln('To get this file:');
        $output->writeln('  1. Go to the "Import / Export" tab of the MinervaKB settings page on your WordPress site');
        $output->writeln('  2. Click the "Download JSON file" button under "Settings export"');
        $output->writeln('  3. Provide the path to the downloaded JSON file below');
        $output->writeln('');
    }

    /**
     * Prompt user for the file path to the MinervaKB settings JSON file.
     *
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return ?string The file path or null if cancelled
     */
    protected function promptForFilePath(
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): ?string {
        $filePath = $this->askWithValidation(
            'Enter the path to your MinervaKB settings JSON file (or press Enter to skip): ',
            // The command's own question answers an empty line with its default, null, which
            // skips as an empty answer does (#100)
            fn (mixed $answer): ?string => $this->validateFilePath(
                \is_string($answer) ? $answer : ''
            ),
            $input,
            $output,
            $command
        );

        // The validator answers with a path or null, so this only narrows the type for phpstan
        return \is_string($filePath) ? $filePath : null;
    }

    /**
     * Validate the file path provided by the user.
     *
     * @param  string  $answer  The file path to validate
     * @return ?string The validated file path or null if empty
     *
     * @throws RuntimeException If the file does not exist
     */
    private function validateFilePath(
        string $answer
    ): ?string {
        if ($answer === '') {
            return null; // Allow empty to skip
        }

        if (! \file_exists($answer)) {
            throw new RuntimeException('The specified file does not exist.');
        }

        return $answer;
    }
}
