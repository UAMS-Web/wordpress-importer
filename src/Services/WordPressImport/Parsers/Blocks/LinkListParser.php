<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\AcfFieldNormalizer;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

/**
 * Parses WordPress ACF link-list blocks into Statamic page_builder replicator
 * sets.
 *
 * Converts the ACF link-list block attributes (e.g., link_list_heading,
 * link_list_description, link_list_section) into a Statamic
 * uamsweb_block_link_list replicator set for the landing_page blueprint's
 * page_builder field. Handles textarea fields as plain text, processes the
 * link_list_section repeater for items, and omits empty optional fields.
 */
class LinkListParser
{
    /**
     * Convert a mixed value into plain text for text fields.
     *
     * @param  mixed  $value  The value to normalize
     * @return string Normalized string value
     */
    private function normalizeTextValue(
        mixed $value
    ): string {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value) || \is_bool($value)) {
            return (string) $value;
        }

        return '';
    }

    /**
     * Normalize section count from ACF data.
     *
     * @param  mixed  $sectionCountValue  Raw section count from ACF
     * @return int Safe section count; a negative count runs no section
     */
    private function normalizeSectionCount(
        mixed $sectionCountValue
    ): int {
        if (\is_int($sectionCountValue)) {
            return $sectionCountValue;
        }

        if (\is_string($sectionCountValue) && \is_numeric($sectionCountValue)) {
            return (int) $sectionCountValue;
        }

        // A count of -1 runs the section loops as many times as 0 does.
        // @pest-mutate-ignore: DecrementInteger
        return 0;
    }

    /**
     * Defines the mapping between field-based ACF field names and standard field
     * names.
     *
     * This method provides the field mappings needed by AcfFieldNormalizer to handle
     * the conversion of WordPress ACF field names like field_link_list_heading_b to
     * standard names like link_list_heading.
     *
     * @return array<int|string, mixed> The field mappings for link list blocks
     */
    private function getFieldMappings(): array
    {
        return [
            'field_link_list_heading_b' => 'link_list_heading',
            'field_link_list_description_b' => 'link_list_description',
            'field_link_list_background_color_b' => 'link_list_background_color',
            'field_link_list_section_b' => 'link_list_section',
        ];
    }

    /**
     * Defines which nested structures should be processed for link list blocks.
     *
     * This method provides the nested structure mappings needed by AcfFieldNormalizer
     * to handle complex ACF block structures.
     *
     * @return array<int|string, mixed> The nested structure mappings for link list blocks
     */
    private function getNestedStructures(): array
    {
        return [
            // No nested structures for link list blocks
        ];
    }

    /**
     * Normalizes the link_list_section repeater data from indexed keys to an array of
     * sections.
     *
     * Transforms the flat key-value array
     * (e.g., link_list_section_0_link_list_section_title) into an array of section
     * arrays, grouping fields by their index.
     *
     * @param  array<int|string, mixed>  $data  The raw attributes array containing link_list_section fields
     * @return array<int|string, mixed> Array of section arrays, each containing fields for one item
     */
    private function normalizeSections(
        array $data
    ): array {
        $sections = [];
        $sectionCount = $this->normalizeSectionCount(
            $data['link_list_section'] ?? null
        );

        for ($i = 0; $i < $sectionCount; $i++) {
            $section = [
                'link_list_section_title' => $data["link_list_section_{$i}_link_list_section_title"] ?? '',
                'link_list_section_body' => $data["link_list_section_{$i}_link_list_section_body"] ?? '',
                'link_list_section_url' => $data["link_list_section_{$i}_link_list_section_url"] ?? [],
                'link_list_section_description' => $data["link_list_section_{$i}_link_list_section_description"] ?? '',
            ];
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * Processes the link_list_section repeater from WordPress to Statamic format.
     *
     * Converts the link_list_section repeater into a Statamic items replicator,
     * mapping title, body, URL, and description fields. Uses LinkProcessor to handle
     * all link processing (file imports, internal page conversions, etc.) and
     * determines the appropriate replicator type based on the processed result.
     *
     * @param  array<int|string, mixed>  $sections  The link_list_section repeater data
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @return array<int|string, mixed> The processed items replicator sets
     */
    private function processItems(
        array $sections,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): array {
        // Initialize the result array to store processed items
        $result = [];

        // Loop through each section to process individual link items
        foreach ($sections as $section) {
            // Skip malformed section rows that are not arrays
            if (! \is_array($section)) {
                continue;
            }

            // Extract and process the heading/title for the link item
            $heading = HtmlTagStripper::strip(
                $this->normalizeTextValue(
                    $section['link_list_section_title']
                )
            );

            // Add placeholder if heading is missing
            if ($heading === '') {
                $heading = 'Placeholder Item Heading — Please Replace';
            }

            // Extract and process the body text (plain text, not HTML)
            $body = isset($section['link_list_section_body'])
                ? HtmlTagStripper::strip(
                    $this->normalizeTextValue(
                        $section['link_list_section_body']
                    )
                )
                : '';

            // Extract the original URL from the link data
            $originalUrl = LinkHelper::getUrl($section['link_list_section_url']);

            // Extract the target attribute (new window behavior)
            $target = LinkHelper::getTarget($section['link_list_section_url']);

            // Extract the accessibility description for the link
            $description = $this->normalizeTextValue(
                $section['link_list_section_description']
            );

            // Skip invalid items (missing required fields)
            if ($originalUrl === '') {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'heading' => $heading,
                        'has_url' => false,
                        'original_url' => $originalUrl,
                        'wordpress_post_id' => ProcessingContext::getWordPressPostId(),
                        'page_slug' => ProcessingContext::getEntryData()['slug'] ?? null,
                    ]
                );

                // Log warning about invalid item and skip processing
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'LinkListParser::processItems: Skipping invalid link_list_section item',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Skip to next section if required fields are missing
                continue;
            }

            // Process URL through LinkProcessor to handle internal links and file imports
            $processedUrl = LinkProcessor::processUrlString(
                $originalUrl,
                $site,
                $assetImporter,
                $output,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );

            // Determine if this should be an asset_file type
            // Check if the processed URL is a Statamic file URL (indicating it was imported)
            // and the original URL is on the same site as the base blog URL
            $isAssetFile = (
                LinkProcessor::isFileUrl($originalUrl)
                && \str_starts_with(
                    $processedUrl,
                    $this->normalizeTextValue(config('app.url')).'/files/'
                )
                && AssetUtils::isUrlOnSameSite(
                    $originalUrl,
                    $assetImporter->getBaseBlogUrl()
                )
            );

            // Check if this should be an asset_file type (file was imported)
            if ($isAssetFile) {
                // Extract the asset path from the Statamic URL
                $assetPath = \str_replace(
                    $this->normalizeTextValue(config('app.url')).'/files/',
                    '',
                    $processedUrl
                );

                // Create asset_file type item with imported asset path
                $item = [
                    'id' => (new RowId)->generate(), // Unique ID for replicator set
                    'type' => 'asset_file', // Statamic replicator set type for assets
                    'heading' => $heading, // Required text
                    'url' => [$assetPath], // Required assets array
                    'target' => $target, // Required button_group
                    'enabled' => true, // Standard for replicator sets
                ];
            } else { // Otherwise
                // Use URL type for non-asset URLs or when processing didn't change the URL
                $item = [
                    'id' => (new RowId)->generate(), // Unique ID for replicator set
                    'type' => 'url', // Statamic replicator set type
                    'heading' => $heading, // Required text
                    'url' => $processedUrl, // Required link (processed by LinkProcessor)
                    'target' => $target, // Required button_group
                    'enabled' => true, // Standard for replicator sets
                ];
            }

            // Check if body text is provided and add to item
            if ($body !== '') {
                // Add optional body text to the item
                $item['body'] = $body; // Optional, 90-char limit
            }

            // Check if description is provided and add to item
            if ($description !== '') {
                // Add optional accessibility description to the item
                $item['link_description'] = $description; // Optional
            }

            // Add the completed item to the result array
            $result[] = $item;
        }

        // Clean up variables to free up memory
        unset(
            $sections,
            $section,
            $heading,
            $body,
            $originalUrl,
            $target,
            $description,
            $processedUrl,
            $isAssetFile,
            $assetPath,
            $item
        );

        // Return the array of processed link list items
        return $result;
    }

    /**
     * Parses a WordPress ACF link-list block into a Statamic page_builder replicator
     * set.
     *
     * Maps block attributes to a uamsweb_block_link_list set, processing the
     * link_list_section repeater for items. Handles text fields as plain text,
     * sets default hidden fields, and omits empty optional fields. Uses
     * AcfFieldNormalizer for consistent field mapping across different WordPress
     * ACF data structures.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/link-list comment
     * @param  string  $site  The Statamic site handle (e.g., 'default') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images (unused in this block)
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML (unused in this block)
     * @return array<int|string, mixed> The Statamic uamsweb_block_link_list replicator set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = []
    ): array {
        // Handle attributes with or without 'data' key
        $data = $attributes['data'] ?? $attributes;

        // Normalize malformed payloads to arrays before field normalization
        if (! \is_array($data)) {
            $data = [];
        }

        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Map basic fields using normalized data
        $heading = HtmlTagStripper::strip(
            $this->normalizeTextValue($normalizedData['link_list_heading'] ?? '')
        );
        $description = isset($normalizedData['link_list_description'])
            ? HtmlTagStripper::strip(
                $this->normalizeTextValue(
                    $normalizedData['link_list_description']
                )
            )
            : '';
        $backgroundColor = $this->normalizeTextValue(
            $normalizedData['link_list_background_color'] ?? 'bg-auto'
        );

        if ($backgroundColor === '') {
            $backgroundColor = 'bg-auto';
        }

        // Add placeholder if heading is empty
        if ($heading === '') {
            $heading = 'Placeholder Block Heading — Please Replace';
        }

        // Normalize and process items repeater
        $items = [];
        $sections = $this->normalizeSections($normalizedData);
        if ($sections !== []) {
            $items = $this->processItems(
                $sections,
                $site,
                $assetImporter,
                $output
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
                    'LinkListParser::parse: No valid items for wp:acf/link-list block; skipping',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(), // Unique ID for replicator set
            'type' => 'uamsweb_block_link_list', // Statamic fieldset handle
            'block_heading' => $heading, // Required text
            'hide_block_heading' => false, // Hidden field, default value
            'background_color' => $backgroundColor, // Required select
            'items' => $items, // Required replicator
            'enabled' => true, // Standard for replicator sets
        ];

        // Include optional description if non-empty
        if ($description !== '') {
            $result['block_description'] = $description; // Optional textarea
        }

        return $result;
    }

    /**
     * Parses WordPress modules template link_list data into Statamic page_builder
     * format.
     *
     * This method handles the conversion of modules template data (from
     * uamswp_modules) to Statamic page_builder format, as opposed to the parse()
     * method which handles ACF block data. The modules template uses a different data
     * structure with prefixed field names. Uses AcfFieldNormalizer for consistent
     * field mapping.
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
        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $moduleData,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Map basic fields from modules template format using normalized data
        $heading = HtmlTagStripper::strip(
            $this->normalizeTextValue($normalizedData['link_list_heading'] ?? '')
        );
        $description = isset($normalizedData['link_list_description'])
            ? HtmlTagStripper::strip(
                $this->normalizeTextValue(
                    $normalizedData['link_list_description']
                )
            )
            : '';
        $backgroundColor = $this->normalizeTextValue(
            $normalizedData['link_list_background_color'] ?? 'bg-auto'
        );

        if ($backgroundColor === '') {
            $backgroundColor = 'bg-auto';
        }

        // Add placeholder if heading is empty
        if ($heading === '') {
            $heading = 'Placeholder Block Heading — Please Replace';
        }

        // Process sections
        $sections = [];
        $sectionCount = $this->normalizeSectionCount(
            $normalizedData['link_list_section'] ?? null
        );

        for ($i = 0; $i < $sectionCount; $i++) {
            $section = [
                'link_list_section_title' => HtmlTagStripper::strip(
                    $this->normalizeTextValue(
                        $normalizedData["link_list_section_{$i}_link_list_section_title"] ?? ''
                    )
                ),
                'link_list_section_body' => $this->normalizeTextValue(
                    $normalizedData["link_list_section_{$i}_link_list_section_body"] ?? ''
                ),
                'link_list_section_url' => $normalizedData["link_list_section_{$i}_link_list_section_url"] ?? '',
                'link_list_section_description' => $this->normalizeTextValue(
                    $normalizedData["link_list_section_{$i}_link_list_section_description"] ?? ''
                ),
            ];
            $sections[] = $section;
        }

        // Process items
        $items = [];
        if ($sections !== []) {
            $items = $this->processItems(
                $sections,
                $site,
                $assetImporter,
                $output
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
                    'LinkListParser::parseModule: No valid items for modules template link_list; skipping',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_link_list',
            'block_heading' => $heading,
            'hide_block_heading' => false,
            'background_color' => $backgroundColor,
            'items' => $items,
            'enabled' => true,
        ];

        // Include optional description if non-empty
        if ($description !== '') {
            $result['block_description'] = $description;
        }

        return $result;
    }
}
