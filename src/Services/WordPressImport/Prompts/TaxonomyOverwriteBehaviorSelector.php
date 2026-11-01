<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;

/**
 * Handles the selection of taxonomy overwrite behavior for import.
 *
 * This class prompts the user to choose how existing taxonomy terms should be
 * handled during the import process: overwrite, skip, or create additional terms.
 */
class TaxonomyOverwriteBehaviorSelector
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
     * Prompt the user to select how existing taxonomy terms should be handled.
     *
     * Displays a list of available overwrite behaviors and returns the user's
     * selection. Uses Laravel Prompts on supported platforms with fallback to
     * Symfony Console Questions.
     *
     * @param  InputInterface  $input  Console input interface for user interaction
     * @param  OutputInterface  $output  Console output interface for feedback
     * @param  Command  $command  Command instance for helper access and output
     * @return string The selected overwrite behavior
     */
    public function select(
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): string {
        // Check if the application is running unit tests
        if (app()->runningUnitTests()) {
            // Return the first option during automated tests
            return $this->getDefault();
        }

        // Use Symfony Console to select the taxonomy overwrite behavior
        return $this->askChoice(
            'How should existing taxonomy terms be handled?',
            $this->getOptions(),
            $this->getDefault(), // Default to first option
            $input,
            $output,
            $command
        );
    }

    /**
     * Get all available overwrite behavior options.
     *
     * The list is returned from here rather than held in a class constant, whose literal a
     * coverage driver records no line of, so that mutation testing runs the tests that
     * assert it (#100).
     *
     * @return list<string> Array of available overwrite behaviors
     */
    public function getOptions(): array
    {
        return [
            'Overwrite matching terms',
            'Skip matching terms',
            'Create an additional term',
        ];
    }

    /**
     * Get the default overwrite behavior option.
     *
     * @return string The default overwrite behavior
     */
    public function getDefault(): string
    {
        return $this->getOptions()[0];
    }
}
