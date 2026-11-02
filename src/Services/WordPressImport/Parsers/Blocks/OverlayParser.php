<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

/**
 * Parses WordPress ACF text-overlay blocks into Statamic page_builder replicator
 * sets.
 *
 * Converts the ACF text-overlay block attributes (e.g., overlay_section) into a
 * Statamic uamsweb_block_overlay replicator set for the landing_page blueprint's
 * page_builder field. Handles textarea fields as plain text, processes the
 * overlay_section repeater for items, imports images with metadata, and maps
 * button fields.
 */
class OverlayParser
{
    /**
     * Normalizes the overlay_section repeater data from indexed keys to an array of
     * sections.
     *
     * Transforms the flat key-value array
     * (e.g., overlay_section_0_overlay_section_heading) into an array of section
     * arrays, grouping fields by their index.
     *
     * @param  array<int|string, mixed>  $data  The raw attributes array containing overlay_section fields
     * @return array<int|string, mixed> Array of section arrays, each containing fields for one item
     */
    private function normalizeSections(
        array $data
    ): array {
        $sections = [];
        // Resolve the section count only when the source value is numeric.
        $sectionCountRaw = $data['overlay_section'] ?? null;
        // A count of -1 runs the loop below as many times as 0 does.
        // @pest-mutate-ignore: DecrementInteger
        $sectionCount = 0;
        if (
            is_int($sectionCountRaw)
            || (is_string($sectionCountRaw) && is_numeric($sectionCountRaw))
        ) {
            $sectionCount = (int) $sectionCountRaw;
        }

        for ($i = 0; $i < $sectionCount; $i++) {
            $section = [
                'overlay_section_heading' => $data["overlay_section_{$i}_overlay_section_heading"] ?? '',
                'overlay_section_body' => $data["overlay_section_{$i}_overlay_section_body"] ?? '',
                'overlay_section_button_text' => $data["overlay_section_{$i}_overlay_section_button_text"] ?? '',
                'overlay_section_button_url' => $data["overlay_section_{$i}_overlay_section_button_url"] ?? [],
                'overlay_section_button_description' => $data["overlay_section_{$i}_overlay_section_button_description"] ?? '',
                'overlay_section_background_color' => $data["overlay_section_{$i}_overlay_section_background_color"] ?? 'bg-black',
                'overlay_section_image' => $data["overlay_section_{$i}_overlay_section_image"] ?? '',
            ];
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * Processes the overlay_section repeater from WordPress to Statamic format.
     *
     * Converts the overlay_section repeater into a Statamic items replicator,
     * mapping heading, body, button, background color, and image fields. Ensures
     * URLs are valid and handles the target attribute for new window behavior.
     *
     * @param  array<int|string, mixed>  $sections  The overlay_section repeater data
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @return array<int|string, mixed> The processed items replicator sets
     */
    private function processItems(
        array $sections,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments
    ): array {
        $result = [];
        foreach ($sections as $section) {
            // Skip invalid section rows that are not arrays.
            if (! is_array($section)) {
                continue;
            }

            // Extract and process the heading for the overlay section item
            $heading = HtmlTagStripper::strip(
                $this->getSectionStringValue(
                    $section,
                    'overlay_section_heading'
                )
            );

            // Track if heading was originally empty
            $isHeadingEmpty = $heading === '';

            // Process body text
            $body = HtmlTagStripper::strip(
                $this->getSectionStringValue(
                    $section,
                    'overlay_section_body'
                )
            );

            // Track if body was originally empty
            $isBodyEmpty = $body === '';

            // Process button fields
            $buttonText = HtmlTagStripper::strip(
                $this->getSectionStringValue(
                    $section,
                    'overlay_section_button_text'
                )
            );
            $buttonDescription = $this->getSectionStringValue(
                $section,
                'overlay_section_button_description'
            );
            $buttonUrlField = $section['overlay_section_button_url'] ?? [];
            $originalUrl = LinkHelper::getUrl($buttonUrlField);

            // Process URL through LinkProcessor to handle internal links and file imports
            $buttonUrl = LinkProcessor::processUrlString(
                $originalUrl,
                $site,
                $assetImporter,
                $output,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );
            $buttonTarget = LinkHelper::getTarget($buttonUrlField);

            // Import image
            $image = null;
            $sectionImage = $this->getSectionStringValue(
                $section,
                'overlay_section_image'
            );
            if ($sectionImage !== '') {
                // Import image with disabled upload handling
                $image = ImageProcessor::importImageWithDisabledUploadHandling(
                    $sectionImage,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    'overlay image',
                    'OverlayParser::processItems'
                );

                // Check if image is available after import attempt
                if (
                    $assetImporter->getUploadImages() // Missing images are only problematic when uploads are enabled
                    && ($image === null || $image === '')
                ) {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'site' => $site,
                            'image_id' => $sectionImage,
                        ]
                    );

                    // Log warning when uploads are enabled but no image is available
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'OverlayParser::processItems: Failed to import overlay section image when uploads are enabled',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );

                    // When uploads are disabled, missing images are expected.
                    // Continue processing without the image.
                }
            }

            // Skip overlay section item when heading, body, button_text, and button_url are all empty
            if (
                $isHeadingEmpty
                && $isBodyEmpty
                && $buttonText === ''
                && $buttonUrl === ''
            ) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'heading' => $heading,
                        'has_body' => false,
                        'has_button_text' => false,
                        'has_button_url' => false,
                        'has_image' => ($image !== null && $image !== ''),
                    ]
                );

                // Log warning for invalid overlay section item
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'OverlayParser::processItems: Skipping invalid overlay_section item',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Skip to next item in the loop
                continue;
            }

            $item = [
                'id' => (new RowId)->generate(), // Unique ID for replicator set
                'type' => 'new_set', // Statamic replicator set type
                'heading' => $heading, // Required, 32-char limit
                'body' => $body, // Required, 280-char limit
                'button_text' => $buttonText, // Required, 27-char limit
                'button_url' => $buttonUrl, // Required
                'button_target' => $buttonTarget, // Required
                'background_color' => $this->getSectionStringValue(
                    $section,
                    'overlay_section_background_color',
                    'bg-black'
                ), // Required
                'background_image' => $image, // Required
                'enabled' => true, // Standard for replicator sets
            ];

            // Include optional fields if non-empty
            if ($buttonDescription !== '') {
                $item['button_link_description'] = $buttonDescription;
            }

            $result[] = $item;
        }

        return $result;
    }

    /**
     * Parses a WordPress ACF text-overlay block into a Statamic page_builder
     * replicator set.
     *
     * Maps block attributes to a uamsweb_block_overlay set, processing the
     * overlay_section repeater for items. Handles textareas as plain text, imports
     * images via AssetImporter, and omits empty optional fields.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/text-overlay comment
     * @param  string  $site  The Statamic site handle (e.g., 'default') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML
     * @return array<int|string, mixed> The Statamic uamsweb_block_overlay replicator set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = []
    ): array {
        // Handle attributes with or without 'data' key
        $data = isset($attributes['data']) && is_array($attributes['data'])
            ? $attributes['data']
            : $attributes;

        // Normalize and process items repeater
        $items = [];
        $sections = $this->normalizeSections($data);
        if ($sections !== []) {
            $items = $this->processItems(
                $sections,
                $site,
                $assetImporter,
                $output,
                $attachments
            );
        }

        // Skip block if no valid items (required field)
        if ($items === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['site' => $site]
            );

            // Log warning about no valid items
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'OverlayParser::parse: No valid items for wp:acf/text-overlay block; skipping',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(), // Unique ID for replicator set
            'type' => 'uamsweb_block_overlay', // Statamic fieldset handle
            'items' => $items, // Required, at least one set
            'enabled' => true, // Standard for replicator sets
        ];

        return $result;
    }

    /**
     * Parses WordPress modules template text_overlay data into Statamic page_builder
     * format.
     *
     * This method handles the conversion of modules template data (from
     * uamswp_modules) to Statamic page_builder format, as opposed to the parse()
     * method which handles ACF block data. The modules template uses a different data
     * structure with prefixed field names.
     *
     * @param  array<int|string, mixed>  $moduleData  The module data from uamswp_modules
     * @param  string  $site  The site handle for asset processing
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $attachments  Array of attachment data for image processing
     * @param  ?XmlStreamParser  $xmlParser  The XML stream parser for additional data access
     * @param  array<int|string, mixed>  $terms  Array of taxonomy terms (unused in this parser)
     * @return array<int|string, mixed>|null The page_builder block or null if parsing failed
     */
    public function parseModule(
        array $moduleData,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?XmlStreamParser $xmlParser = null,
        array $terms = []
    ): ?array {
        // Normalize the overlay_section repeater, which shares the block's field names
        $sections = $this->normalizeSections($moduleData);

        // Process items
        $items = [];
        if ($sections !== []) {
            $items = $this->processItems(
                $sections,
                $site,
                $assetImporter,
                $output,
                $attachments
            );
        }

        // Skip block if no valid items (required field)
        if ($items === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['site' => $site]
            );

            // Log warning about no valid items
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'OverlayParser::parseModule: No valid items for modules template text_overlay; skipping',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_overlay',
            'items' => $items,
            'enabled' => true,
        ];

        return $result;
    }

    /**
     * Returns a section field value as a string.
     *
     * @param  array<int|string, mixed>  $section  Overlay section data row
     * @param  string  $key  The section field key to read
     * @param  string  $default  The fallback value when the field is missing
     * @return string The resolved section field value
     */
    private function getSectionStringValue(
        array $section,
        string $key,
        string $default = ''
    ): string {
        // Read the field value from the section array.
        $value = $section[$key] ?? $default;

        // Return the value only when it is already a string.
        if (is_string($value)) {
            return $value;
        }

        // Fall back to the provided default for non-string values.
        return $default;
    }
}
