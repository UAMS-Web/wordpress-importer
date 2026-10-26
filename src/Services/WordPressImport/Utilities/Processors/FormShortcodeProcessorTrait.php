<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Shared functionality for form shortcode processors.
 *
 * This trait provides common methods used by both Gravity Forms and Formidable
 * Forms shortcode processors to eliminate code duplication.
 */
trait FormShortcodeProcessorTrait
{
    /**
     * Use base form processor functionality.
     */
    use FormProcessorTrait;

    /**
     * Parse a WordPress form shortcode into a Statamic form Bard set.
     *
     * Maps the shortcode 'id' attribute to an import_wordpress_notes field with
     * format 'Plugin Name form ID X' or 'Plugin Name form ID not specified'.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML (unused)
     * @param  string  $pluginName  The plugin name for logging and notes (e.g., 'Gravity Forms',
     *                              'Formidable Forms')
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> The Statamic form Bard set configuration
     */
    protected function parseFormShortcode(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms,
        string $pluginName,
        ?string $pageSlug
    ): array {
        // Extract id attribute, treat empty string as null
        $formId = $this->formIdFromAttributes($attributes);

        // Track form ID for import and linking
        $this->trackFormId($formId);

        // Check if we have a form handle for this form ID
        $formHandle = $this->getFormHandle(
            $formId,
            $pluginName
        );

        // Build the values array for the Bard set
        $values = $this->buildFormBardSetValues(
            $formId,
            $pluginName,
            $formHandle
        );

        // Build the result array for the Bard set
        $result = [
            'type' => 'set', // Bard set type for Tiptap
            'attrs' => [ // Attributes for the Bard set
                'id' => (new RowId)->generate(), // Unique row ID for the Bard set
                'values' => $values, // Values for the Bard set
            ],
        ];

        // Return the Bard set configuration
        return $result;
    }

    /**
     * Build a page builder block for a form shortcode.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML (unused)
     * @param  string  $pluginName  The plugin name for logging and notes (e.g., 'Gravity Forms',
     *                              'Formidable Forms')
     * @param  string  $blockType  The block type for the page builder (e.g., 'uamsweb_block_gravityform',
     *                             'uamsweb_block_formidable')
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> The page builder block structure
     */
    protected function toFormPageBuilderBlock(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms,
        string $pluginName,
        string $blockType,
        ?string $pageSlug
    ): array {
        // Parse the shortcode and get the Bard set
        $set = $this->parseFormShortcode(
            $attributes,
            $site,
            $assetImporter,
            $output,
            $terms,
            $pluginName,
            $pageSlug
        );

        // Extract the values array from the Bard set
        $values = [];

        if (
            isset($set['attrs'])
            && \is_array($set['attrs'])
            && isset($set['attrs']['values'])
            && \is_array($set['attrs']['values'])
        ) {
            $values = $set['attrs']['values'];
        } elseif (\is_array($set)) {
            $values = $set;
        }

        // Check if we have a form handle for this form ID
        $formId = $this->formIdFromAttributes($attributes);
        $formHandle = $this->getFormHandle(
            $formId,
            $pluginName
        );

        $importNotes = $values['import_wordpress_notes'] ?? "{$pluginName} form ID not specified";
        $importNotes = \is_string($importNotes)
            ? $importNotes
            : "{$pluginName} form ID not specified";

        // Return the page builder block structure
        return [
            'id' => (new RowId)->generate(), // Unique row ID for the block
            'type' => $blockType, // Block type for the specific form plugin
            'enabled' => true, // Block is enabled by default
            'form_notes' => $formHandle !== null
                ? "form handle: $formHandle"
                : $importNotes, // Notes for import tracking
        ];
    }

    /**
     * Normalize a shortcode id attribute to int, string, or null.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @return int|string|null Form ID when present and scalar, otherwise null
     */
    private function formIdFromAttributes(
        array $attributes
    ): int|string|null {
        if (
            ! isset($attributes['id'])
            || $attributes['id'] === ''
        ) {
            return null;
        }

        $formId = $attributes['id'];

        if (\is_int($formId) || \is_string($formId)) {
            return $formId;
        }

        if (\is_numeric($formId)) {
            return (string) $formId;
        }

        return null;
    }
}
