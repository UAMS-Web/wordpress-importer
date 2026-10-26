<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Contracts\FileLoaderInterface;

/**
 * Loads and parses JSON export files for form plugins.
 *
 * This class handles the loading and parsing of JSON-formatted
 * export files, providing error handling and logging.
 */
class JsonFileLoader implements FileLoaderInterface
{
    /**
     * Create a new JsonFileLoader instance.
     *
     * @param  string  $pluginName  The name of the plugin for logging
     */
    public function __construct(protected string $pluginName) {}

    /**
     * Load and parse the JSON export file.
     *
     * @param  string  $filePath  The path to the JSON export file
     * @param  Command  $command  Command instance for error reporting
     * @return array<int|string, mixed>|null The parsed forms data or null if loading failed
     */
    public function load(
        string $filePath,
        Command $command
    ): ?array {
        // Check if file exists
        if (! \file_exists($filePath)) {
            $command->error("File not found: {$filePath}");
            Log::error(
                "{$this->pluginName}\\JsonFileLoader::load: File not found",
                ['file_path' => $filePath]
            );

            return null;
        }

        // Read JSON file content
        $jsonContent = file_get_contents($filePath);
        // Only an unreadable file reaches this branch, and `pest --mutate`'s stream
        // wrapper reports one as missing, so the test that covers it skips there.
        // The plain suite kills each of these (#1314).
        // @pest-mutate-ignore: FalseToTrue, RemoveMethodCall, RemoveArrayItem, RemoveEarlyReturn
        if ($jsonContent === false) {
            $command->error("Unable to read file: {$filePath}");
            Log::error(
                "{$this->pluginName}\\JsonFileLoader::load: Unable to read file",
                ['file_path' => $filePath]
            );

            return null;
        }

        // Parse JSON content
        $forms = \json_decode(
            $jsonContent,
            true
        );
        if (json_last_error() !== JSON_ERROR_NONE) {
            $command->error("Invalid JSON in file: {$filePath}");
            Log::error(
                "{$this->pluginName}\\JsonFileLoader::load: Invalid JSON",
                [
                    'file_path' => $filePath,
                    'json_error' => json_last_error_msg(),
                ]
            );

            return null;
        }

        if (! \is_array($forms)) {
            $command->error("Invalid JSON in file: {$filePath} (expected an array)");
            Log::error(
                "{$this->pluginName}\\JsonFileLoader::load: JSON root is not an array",
                ['file_path' => $filePath]
            );

            return null;
        }

        return $forms;
    }

    /**
     * Get the file extension for JSON files.
     *
     * @return string The file extension
     */
    public function getFileExtension(): string
    {
        return 'json';
    }

    /**
     * Get the file format description for user prompts.
     *
     * @return string The format description
     */
    public function getFormatDescription(): string
    {
        return 'JSON file';
    }
}
