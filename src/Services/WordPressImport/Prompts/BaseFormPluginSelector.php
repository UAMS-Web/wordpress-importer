<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Contracts\FileLoaderInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\MigrationApiFormLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasQuestionHelper;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

/**
 * Base class for form plugin selectors.
 *
 * This abstract class provides common selector functionality
 * that can be extended by specific form plugin selectors.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * common selector operations.
 */
abstract class BaseFormPluginSelector
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
     * Create a new BaseFormPluginSelector instance.
     *
     * @param  BaseFormPluginValidator  $validator  Service for validating form plugin data
     * @param  string  $pluginName  The name of the plugin for logging
     * @param  FileLoaderInterface  $fileLoader  Service for loading export files
     */
    public function __construct(protected BaseFormPluginValidator $validator, protected string $pluginName, protected FileLoaderInterface $fileLoader) {}

    /**
     * Report whether this selector's run is sourced from the migration API.
     *
     * Exposed publicly because a caller may need to know before it decides how to ask for a
     * form at all — Ninja Forms prompts once per referenced ID on the file path, and that
     * loop has no counterpart when the endpoint serves every form with its own ID (wordpress-importer-archive#810).
     *
     * @return bool True when an API source is configured on this selector's loader
     */
    public function hasMigrationApiSource(): bool
    {
        return $this->fileLoader instanceof MigrationApiFormLoader
            && $this->fileLoader->hasMigrationApiSource();
    }

    /**
     * Prompt the user to select a form plugin export file.
     *
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return array<int|string, mixed>|null The loaded forms array or null if skipped
     */
    public function select(
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): ?array {
        // The one place sourcing is decided (wordpress-importer-archive#809). On an API-sourced run there is no local
        // file to prompt for, so the prompt is skipped entirely and the loader is asked
        // directly. Prompting anyway is how wordpress-importer-archive#679 made a load path unreachable in production:
        // the operator pressed Enter, the selector returned, and the code below it never ran.
        if ($this->hasMigrationApiSource()) {
            $forms = $this->fileLoader->load('', $command);

            if ($forms === null) {
                return null;
            }

            // Validated on both paths rather than only the file one: an API payload that
            // does not carry forms is exactly as unusable as a malformed export, and finding
            // out in the mapping is finding out too late.
            if (! $this->validator->validate($forms)) {
                $command->error(
                    "The migration API returned no usable {$this->pluginName} form definitions."
                );

                return null;
            }

            return $forms;
        }

        // Check if Laravel Prompts is supported and input is interactive
        if (
            $this->isPromptsSupported()
            && $input->isInteractive()
        ) {
            // Return the selected form plugin export file
            return $this->selectWithLaravelPrompts($command);
        }

        // Use Symfony Console to select the form plugin export file
        return $this->selectWithSymfonyConsole(
            $input,
            $output,
            $command
        );
    }

    /**
     * Select form plugin export file using Laravel Prompts.
     *
     * @param  Command  $command  Command instance
     * @return array<int|string, mixed>|null The loaded forms array or null if skipped
     */
    protected function selectWithLaravelPrompts(
        Command $command
    ): ?array {
        // Ask if user wants to provide form plugin export file
        $provideFile = confirm(
            label: "Would you like to provide a {$this->pluginName} export file to create Statamic forms?",
            default: true
        );

        if (! $provideFile) {
            return null;
        }

        // Prompt for file path
        $filePath = text(
            label: "Enter the path to your {$this->pluginName} export file:",
            placeholder: "/path/to/{$this->getPluginSlug()}-export.{$this->fileLoader->getFileExtension()}",
            required: true
        );

        // ---
        // Return null when prompt adapters return a non-string/empty value
        // ---

        // Check if the file path is empty
        if (\trim($filePath) === '') {
            // Return null to skip the import
            return null;
        }

        // Load and validate the file
        return $this->loadAndValidateFile(
            $filePath,
            $command
        );
    }

    /**
     * Select form plugin export file using Symfony Console.
     *
     * @param  InputInterface  $input  Console input interface
     * @param  OutputInterface  $output  Console output interface
     * @param  Command  $command  Command instance
     * @return array<int|string, mixed>|null The loaded forms array or null if skipped
     */
    protected function selectWithSymfonyConsole(
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): ?array {
        // Ask if user wants to provide form plugin export file
        $provideFile = $this->askConfirmation(
            "Would you like to provide a {$this->pluginName} export file to create Statamic forms?",
            true,
            $input,
            $output,
            $command
        );

        if (! $provideFile) {
            return null;
        }

        // Prompt for file path
        $filePath = $this->askWithValidation(
            "Enter the path to your {$this->pluginName} export file:",
            fn ($answer) => (\is_string($answer) && \trim($answer) === '') ? throw new \RuntimeException('File path cannot be empty.') : $answer,
            $input,
            $output,
            $command
        );

        // ---
        // Return null when interactive helpers return a non-string/empty value
        // ---

        // Check if the file path is empty
        if (
            ! \is_string($filePath)
            || \trim($filePath) === ''
        ) {
            // Return null to skip the import
            return null;
        }

        // Load and validate the file
        return $this->loadAndValidateFile(
            $filePath,
            $command
        );
    }

    /**
     * Load and validate the form plugin export file.
     *
     * @param  string  $filePath  The path to the form plugin export file
     * @param  Command  $command  Command instance
     * @return array<int|string, mixed>|null The loaded forms array or null if invalid
     */
    protected function loadAndValidateFile(
        string $filePath,
        Command $command
    ): ?array {
        // Use the file loader to load the file
        $forms = $this->fileLoader->load(
            $filePath,
            $command
        );
        if ($forms === null) {
            return null;
        }

        // Validate the forms data structure
        if (! $this->validator->validate($forms)) {
            $command->error("Invalid {$this->pluginName} data structure in file: {$filePath}");
            Log::error(
                "{$this->pluginName}\\Selector::loadAndValidateFile: Invalid {$this->pluginName} data structure",
                ['file_path' => $filePath]
            );

            return null;
        }

        $command->info("Successfully loaded {$this->pluginName} export file with ".\count($forms).' forms.');

        return $forms;
    }

    /**
     * Get the plugin slug for file path suggestions.
     *
     * @return string The plugin slug
     */
    abstract protected function getPluginSlug(): string;

    /**
     * Get the prompt message for form plugin export file selection.
     *
     * @return string The prompt message
     */
    protected function getPromptMessage(): string
    {
        return "{$this->pluginName} blocks or shortcodes detected. Please provide the path to your {$this->pluginName} forms export ".$this->fileLoader->getFormatDescription().' (or press Enter to skip):';
    }

    /**
     * Get the help text for form plugin export file selection.
     *
     * @return string The help text
     */
    protected function getHelpText(): string
    {
        $article = $this->fileLoader->getFormatDescription() === 'XML file' ? 'an' : 'a';

        return "This should be {$article} ".$this->fileLoader->getFormatDescription()." exported from {$this->pluginName} containing all your forms data.";
    }

    /**
     * Get the skip message for form plugin export file selection.
     *
     * @return string The skip message
     */
    protected function getSkipMessage(): string
    {
        return "Skipping {$this->pluginName} forms import.";
    }

    /**
     * Get the success message for form plugin export file selection.
     *
     * @return string The success message
     */
    protected function getSuccessMessage(): string
    {
        return "{$this->pluginName} forms data loaded successfully.";
    }
}
