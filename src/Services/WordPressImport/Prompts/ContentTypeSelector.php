<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;

/**
 * Handles the selection of a WordPress content type for import.
 *
 * This class prompts the user to choose a content type (e.g., 'page', 'post',
 * 'uamspeople') from the parsed XML data and ensures a valid selection is made.
 * Only supported content types are offered as options.
 */
class ContentTypeSelector
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
     * Prompt the user to select a content type to import from the XML file.
     *
     * Displays a list of supported content types with their counts and exits if none
     * are found.
     *
     * @param  array<int|string, mixed>  $contentTypes  Array of [type, count] pairs from the XML (e.g., [['page', 17], ['post', 2]])
     * @param  InputInterface  $input  Console input interface for user interaction
     * @param  OutputInterface  $output  Console output interface for feedback
     * @param  Command  $command  Command instance for helper access and output
     * @return string The selected content type (e.g., 'page')
     */
    public function select(
        array $contentTypes,
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): string {
        // Check if content types array is empty before processing
        if ($contentTypes === []) {
            // Display error message when no content types are available
            $command->error('No supported content types (page, post, uamspeople) found in the XML.');

            // Exit command with error code to indicate failure
            exit(1);
        }

        // Filter out nav_menu_item from content type options
        $contentTypes = \array_filter(
            $contentTypes,
            fn ($type): bool => \is_array($type)
                && isset($type[0])
                && \is_string($type[0])
                && $type[0] !== 'nav_menu_item'
        );

        // Re-index array after filtering
        $contentTypes = \array_values($contentTypes);

        // Check if any content types remain after filtering
        if ($contentTypes === []) {
            // Display error message when no supported content types are available
            $command->error('No supported content types (page, post, uamspeople) found in the XML.');

            // Exit command with error code to indicate failure
            exit(1);
        }

        // Format content types as display options with counts
        $options = [];

        foreach ($contentTypes as $type) {
            $count = $type[1];
            $countLabel = \is_scalar($count) ? (string) $count : '0';
            $options[] = "{$type[0]} ({$countLabel})";
        }

        // Prompt user to select a content type from the available options
        $selected = $this->askChoice(
            'Which content type would you like to import?',
            $options,
            $options[0], // Use first option as default
            $input,
            $output,
            $command
        );

        // Extract content type name from selected option (remove count in parentheses)
        return \explode(
            ' (',
            $selected
        )[0];
    }
}
