<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Coordinates the MinervaKB settings selection and processing workflow.
 *
 * This class orchestrates the interaction between specialized components:
 *   - Prompter: Handles user interaction and file path collection
 *   - Loader: Manages file reading and JSON parsing
 *   - Validator: Ensures file format and content validity
 *   - Extractor: Maps settings to Statamic field values
 *
 * Follows the Single Responsibility Principle by delegating specific tasks
 * to focused, single-purpose classes.
 */
class Selector
{
    /**
     * Create a new Selector instance.
     *
     * @param  Prompter  $prompter  Service for user interaction
     * @param  Loader  $loader  Service for file operations
     * @param  Validator  $validator  Service for content validation
     * @param  Extractor  $extractor  Service for settings mapping
     */
    public function __construct(protected Prompter $prompter, protected Loader $loader, protected Validator $validator, protected Extractor $extractor) {}

    /**
     * Coordinate the MinervaKB settings selection and processing workflow.
     *
     * Orchestrates the complete workflow:
     *   1. Prompts user for settings file path
     *   2. Loads and parses the JSON file
     *   3. Validates the settings content
     *   4. Returns processed settings for use in import
     *
     * @param  InputInterface  $input  Console input interface for user interaction
     * @param  OutputInterface  $output  Console output interface for feedback
     * @param  Command  $command  Command instance for helper access and output
     * @return array<int|string, mixed>|null The parsed settings array or null if no file provided
     */
    public function select(
        InputInterface $input,
        OutputInterface $output,
        Command $command
    ): ?array {
        // ---
        // The migration API path, which has no file to prompt for (wordpress-importer-archive#679)
        // ---

        // Prompting first is what made this unreachable: on `--source=api` the operator has
        // no local JSON file, so the only sane answer was Enter, and Enter skipped straight
        // past the loader — taking the "refusing to import with no configuration" raise
        // with it.
        if ($this->loader->hasMigrationApiSource()) {
            return $this->selectFromMigrationApi($output);
        }

        // Step 1: Prompt user for settings file
        $filePath = $this->prompter->promptForSettingsFile(
            $input,
            $output,
            $command
        );
        if ($filePath === null || $filePath === '') {
            $output->writeln('<comment>Skipping MinervaKB settings configuration.</comment>');

            return null;
        }

        // Step 2: Load and parse the settings file
        $settings = $this->loader->loadSettings(
            $filePath,
            $output
        );
        if ($settings === null || $settings === []) {
            return null;
        }

        // Step 3: Validate the settings content
        if (
            ! $this->validator->validateSettings(
                $settings,
                $output
            )
        ) {
            return null;
        }

        // Step 4: Log successful loading
        $output->writeln(\sprintf('<info>Successfully loaded MinervaKB settings from: %s</info>', $filePath));

        return $settings;
    }

    /**
     * Load and validate settings from the migration API.
     *
     * The loader ignores the file path on this branch — the `minerva-kb-options` row is the
     * source of truth, with no fallback — so an empty string is passed rather than a path
     * that would be misleading to read in a stack trace.
     *
     * An absent or withheld row raises out of here rather than returning null. That is
     * deliberate: a knowledge base imported with no configuration is the silent success this
     * whole path exists to prevent.
     *
     * @param  OutputInterface  $output  Console output interface for feedback
     * @return array<int|string, mixed>|null The parsed settings, or null when unusable
     */
    private function selectFromMigrationApi(
        OutputInterface $output
    ): ?array {
        $settings = $this->loader->loadSettings(
            '',
            $output
        );

        if ($settings === null || $settings === []) {
            return null;
        }

        if (
            ! $this->validator->validateSettings(
                $settings,
                $output
            )
        ) {
            return null;
        }

        $output->writeln('<info>Successfully loaded MinervaKB settings from the migration API.</info>');

        return $settings;
    }

    /**
     * Extract knowledge base settings for mapping to Statamic fields.
     *
     * @param  array<int|string, mixed>  $settings  The full MinervaKB settings array
     * @param  string  $siteName  The site name for title composition
     * @return array<int|string, mixed> The extracted settings for knowledge base mapping
     */
    public function extractKnowledgeBaseSettings(
        array $settings,
        string $siteName
    ): array {
        return $this->extractor->extractKnowledgeBaseSettings(
            $settings,
            $siteName
        );
    }
}
