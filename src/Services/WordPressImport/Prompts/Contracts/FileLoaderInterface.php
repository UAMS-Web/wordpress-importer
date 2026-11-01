<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Contracts;

use Illuminate\Console\Command;

/**
 * Interface for loading and parsing form plugin export files.
 *
 * This interface abstracts the file loading logic to support different
 * file formats (JSON, XML, etc.) without hardcoding format assumptions
 * in the base selector class.
 */
interface FileLoaderInterface
{
    /**
     * Load and parse the export file.
     *
     * @param  string  $filePath  The path to the export file
     * @param  Command  $command  Command instance for error reporting
     * @return array<int|string, mixed>|null The parsed forms data or null if loading failed
     */
    public function load(
        string $filePath,
        Command $command
    ): ?array;

    /**
     * Get the file extension for this loader.
     *
     * @return string The file extension (e.g., 'json', 'xml')
     */
    public function getFileExtension(): string;

    /**
     * Get the file format description for user prompts.
     *
     * @return string The format description (e.g., 'JSON file', 'XML file')
     */
    public function getFormatDescription(): string;
}
