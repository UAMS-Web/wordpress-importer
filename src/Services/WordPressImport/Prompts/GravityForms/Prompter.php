<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\GravityForms;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;

/**
 * Handles user interaction for Gravity Forms forms export file selection.
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
     * Display instructions and prompt user for Gravity Forms forms export file.
     *
     * @param  InputInterface  $input  Console input interface for user interaction
     * @param  OutputInterface  $output  Console output interface for feedback
     * @param  Command  $command  Command instance for helper access and output
     * @return ?string The file path or null if user chooses to skip
     */
    public function promptForFormsFile(
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): ?string {
        // Display instructions to the user about obtaining the Gravity Forms export file
        $this->displayInstructions($output);

        // Prompt the user for the file path and return the result
        return $this->promptForFilePath(
            $input,
            $output,
            $command
        );
    }

    /**
     * Display instructions for obtaining the Gravity Forms forms export file.
     *
     * @param  OutputInterface  $output  Console output interface
     */
    protected function displayInstructions(
        OutputInterface $output
    ): void {
        // Display the configuration header
        $output->writeln('<info>Gravity Forms Export Configuration</info>');
        // Explain the purpose of the Gravity Forms export file
        $output->writeln('You can optionally provide a Gravity Forms forms export JSON file to map Gravity Forms blocks and shortcodes to Statamic forms.');
        // Provide step-by-step instructions
        $output->writeln('To get this file:');
        $output->writeln('1. In your WordPress dashboard, go to Gravity Forms > Import/Export.');
        $output->writeln('2. Click the Export Forms tab.');
        $output->writeln('3. Select all forms and click Download Export.');
        $output->writeln('4. Provide the path to the downloaded JSON file below.');
    }

    /**
     * Prompt user for the file path to the Gravity Forms forms export JSON file.
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
            'Enter the path to your Gravity Forms forms export JSON file (or press Enter to skip): ',
            function ($answer): ?string {
                // Allow empty input to skip the Gravity Forms configuration
                if ($answer === '' || $answer === []) {
                    return null; // Allow empty to skip
                }

                if (! \is_string($answer)) {
                    throw new \RuntimeException('Invalid file path.');
                }

                // Validate that the specified file exists
                if (! \file_exists($answer)) {
                    throw new \RuntimeException('The specified file does not exist.');
                }

                return $answer;
            },
            $input,
            $output,
            $command
        );

        return \is_string($filePath) || $filePath === null ? $filePath : null;
    }
}
