<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\GravityForms;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Handles file loading and JSON parsing for Gravity Forms forms export.
 *
 * This class is responsible for:
 *   - Reading file content from the filesystem
 *   - Parsing JSON content into PHP arrays
 *   - Handling file reading errors
 *   - Managing JSON parsing errors
 *
 * Follows the Single Responsibility Principle by focusing solely on file
 * operations.
 */
class Loader
{
    /**
     * Load and parse the Gravity Forms forms export JSON file.
     *
     * @param  string  $filePath  The path to the JSON file
     * @param  OutputInterface  $output  Console output interface
     * @return array<int|string, mixed>|null The parsed forms array or null if loading fails
     */
    public function loadForms(
        string $filePath,
        OutputInterface $output
    ): ?array {
        // Attempt to read and parse the Gravity Forms forms export file
        try {
            // Read the file content from the filesystem
            $jsonContent = $this->readFileContent(
                $filePath,
                $output
            );

            // If reading failed, return null
            if ($jsonContent === null) {
                return null;
            }

            // Parse the JSON content into a PHP array
            $forms = $this->parseJsonContent(
                $jsonContent,
                $filePath,
                $output
            );

            // Return the parsed forms array
            return $forms;
        }

        // Handle any exceptions that occur during file loading or parsing
        catch (\Exception $exception) {
            // Output an error message to the console
            $output->writeln("<error>Error loading forms file: {$exception->getMessage()}</error>");

            // Log the error for debugging purposes
            Log::error("Loader::loadForms: Error loading forms file {$filePath}: {$exception->getMessage()}");

            // Return null to indicate failure
            return null;
        }
    }

    /**
     * Read file content from the filesystem.
     *
     * @param  string  $filePath  The path to the file
     * @param  OutputInterface  $output  Console output interface
     * @return ?string The file content or null if reading fails
     */
    protected function readFileContent(
        string $filePath,
        OutputInterface $output
    ): ?string {
        // Read the file content using file_get_contents
        $jsonContent = file_get_contents($filePath);

        // If reading failed, output an error and log it
        if ($jsonContent === false) {
            // Output an error message to the console
            $output->writeln("<error>Failed to read file: {$filePath}</error>");

            // Log the error for debugging purposes
            Log::error("Loader::readFileContent: Failed to read file {$filePath}");

            // Return null to indicate failure
            return null;
        }

        // Return the file content as a string
        return $jsonContent;
    }

    /**
     * Parse JSON content into a PHP array.
     *
     * @param  string  $jsonContent  The JSON content to parse
     * @param  string  $filePath  The file path for error reporting
     * @param  OutputInterface  $output  Console output interface
     * @return array<int|string, mixed>|null The parsed array or null if parsing fails
     */
    protected function parseJsonContent(
        string $jsonContent,
        string $filePath,
        OutputInterface $output
    ): ?array {
        // Decode the JSON content into a PHP array
        $forms = \json_decode(
            $jsonContent,
            true
        );

        // If decoding failed, output an error and log it
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Output an error message to the console
            $output->writeln('<error>Invalid JSON format: '.json_last_error_msg().'</error>');

            // Log the error for debugging purposes
            Log::error("Loader::parseJsonContent: Invalid JSON format in {$filePath}: ".json_last_error_msg());

            // Return null to indicate failure
            return null;
        }

        // Check if decoded JSON is an array
        if (! \is_array($forms)) {
            // Output an error message to the console
            $output->writeln('<error>Invalid JSON format: expected an array of forms.</error>');

            // Log the error for debugging purposes
            Log::error("Loader::parseJsonContent: JSON root is not an array in {$filePath}");

            // Return null to indicate failure
            return null;
        }

        // Return the parsed forms array
        return $forms;
    }
}
