<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Contracts\FileLoaderInterface;

/**
 * Loads and parses XML export files for form plugins.
 *
 * This class handles the loading and parsing of XML-formatted
 * export files, providing error handling and logging.
 */
class XmlFileLoader implements FileLoaderInterface
{
    /**
     * Create a new XmlFileLoader instance.
     *
     * @param  string  $pluginName  The name of the plugin for logging
     */
    public function __construct(protected string $pluginName) {}

    /**
     * Load and parse the XML export file.
     *
     * @param  string  $filePath  The path to the XML export file
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
                "{$this->pluginName}\\XmlFileLoader::load: File not found",
                ['file_path' => $filePath]
            );

            return null;
        }

        // Read XML file content
        $xmlContent = file_get_contents($filePath);
        // Only an unreadable file reaches this branch, and `pest --mutate`'s stream
        // wrapper reports one as missing, so the test that covers it skips there.
        // The plain suite kills each of these (#1314).
        // @pest-mutate-ignore: FalseToTrue, RemoveMethodCall, RemoveArrayItem, RemoveEarlyReturn
        if ($xmlContent === false) {
            $command->error("Unable to read file: {$filePath}");
            Log::error(
                "{$this->pluginName}\\XmlFileLoader::load: Unable to read file",
                ['file_path' => $filePath]
            );

            return null;
        }

        // Parse XML content
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            $command->error("Invalid XML in file: {$filePath}");
            Log::error(
                "{$this->pluginName}\\XmlFileLoader::load: Invalid XML",
                ['file_path' => $filePath]
            );

            return null;
        }

        // Convert XML to array format expected by the validator
        $forms = $this->convertXmlToArray($xml);

        return $forms;
    }

    /**
     * Convert XML structure to array format expected by validators.
     *
     * @param  SimpleXMLElement  $xml  The parsed XML element
     * @return array<int|string, mixed> The converted forms array
     */
    protected function convertXmlToArray(
        SimpleXMLElement $xml
    ): array {
        return $this->parseForms($xml);
    }

    /**
     * Read each `<form>` and its `<field>` children, casting each value to its type.
     *
     * @param  SimpleXMLElement  $xml  The parsed XML element
     * @return list<array{id: int, name: string, description: string, created_at: string, logged_in: int, is_template: int, editable: int, options: string, status: string, parent_form_id: int, fields: list<array<string, int|string>>}> The forms, in document order
     */
    protected function parseForms(
        SimpleXMLElement $xml
    ): array {
        $forms = [];
        foreach ($xml->form as $form) {
            $formData = [
                'id' => (int) $form->id,
                'name' => (string) $form->name,
                'description' => (string) $form->description,
                'created_at' => (string) $form->created_at,
                'logged_in' => (int) $form->logged_in,
                'is_template' => (int) $form->is_template,
                'editable' => (int) $form->editable,
                'options' => (string) $form->options,
                'status' => (string) $form->status,
                'parent_form_id' => (int) $form->parent_form_id,
                'fields' => [],
            ];

            // Process fields
            foreach ($form->field as $field) {
                $fieldData = [
                    'id' => (int) $field->id,
                    'field_key' => (string) $field->field_key,
                    'name' => (string) $field->name,
                    'description' => (string) $field->description,
                    'type' => (string) $field->type,
                    'default_value' => (string) $field->default_value,
                    'field_order' => (int) $field->field_order,
                    'form_id' => (int) $field->form_id,
                    'required' => (int) $field->required,
                    'options' => (string) $field->options,
                    'field_options' => (string) $field->field_options,
                ];
                $formData['fields'][] = $fieldData;
            }

            $forms[] = $formData;
        }

        return $forms;
    }

    /**
     * Get the file extension for XML files.
     *
     * @return string The file extension
     */
    public function getFileExtension(): string
    {
        return 'xml';
    }

    /**
     * Get the file format description for user prompts.
     *
     * @return string The format description
     */
    public function getFormatDescription(): string
    {
        return 'XML file';
    }
}
