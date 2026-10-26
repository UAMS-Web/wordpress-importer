<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\AcfFieldNormalizer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Parses WordPress ACF uams-section blocks into Statamic page_builder replicator
 * sets.
 *
 * Converts the ACF uams-section block attributes (e.g., section_heading or
 * field_section_heading_b) and InnerBlocks into a Statamic uamsweb_block_content
 * replicator set for the landing_page blueprint's page_builder field. Processes
 * InnerBlocks using TextProcessor to generate Bard content, ensuring consistent
 * handling with non-ACF content.
 */
class SectionParser
{
    /**
     * Gets the field mappings for section blocks.
     *
     * Defines the mapping between field-based ACF field names and standard
     * field names for section blocks.
     *
     * @return array<int|string, mixed> Array of field name mappings
     */
    private function getFieldMappings(): array
    {
        return [
            'field_section_heading_b' => 'section_heading',
            'field_section_hide_heading_b' => 'section_hide_heading',
            'field_section_background_color_b' => 'section_background_color',
        ];
    }

    /**
     * Gets the nested structure keys for section blocks.
     *
     * Defines which nested structures should be processed for section blocks.
     *
     * @return array<int|string, mixed> Array of nested structure keys
     */
    private function getNestedStructures(): array
    {
        return [];
    }

    /**
     * Parses a WordPress ACF uams-section block into a Statamic replicator set.
     *
     * Processes block attributes and InnerBlocks, mapping section_heading or
     * field_section_heading_b to block_heading with a fallback to
     * 'Section Title - Please Replace'. Uses TextProcessor to process InnerBlocks
     * into Bard content, ensuring consistent handling with non-ACF content.
     * Skips the block (returns null) if the body content is empty, regardless of
     * heading. If body has content but heading is empty or default, sets heading
     * to the default placeholder.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from wp:acf/uams-section
     * @param  array<int|string, mixed>  $innerContent  The parsed InnerBlock HTML fragments
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing assets
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of terms data
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|null The Statamic uamsweb_block_content replicator set or null if body is empty
     */
    public function parse(
        array $attributes,
        array $innerContent,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): ?array {
        // Handle attributes with or without 'data' key
        $data = $attributes['data'] ?? $attributes;

        if (! \is_array($data)) {
            $data = [];
        }

        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        $sectionHeading = $normalizedData['section_heading'] ?? 'Section Title - Please Replace';

        // Map WordPress ACF fields to Statamic fields (now simplified with normalized data)
        $blockHeading = HtmlTagStripper::strip(
            \is_string($sectionHeading)
                ? $sectionHeading
                : 'Section Title - Please Replace'
        );
        $hideBlockHeading = isset($normalizedData['section_hide_heading']) && $normalizedData['section_hide_heading'] === '1';
        $backgroundColor = $normalizedData['section_background_color'] ?? 'bg-auto';

        // Process InnerBlocks into Bard format
        $bodyContent = [];
        if ($innerContent !== []) {
            // Concatenate InnerBlocks HTML fragments
            $innerHtml = \implode(
                "\n",
                \array_map(
                    function ($fragment): string {
                        if (! \is_array($fragment)) {
                            return '';
                        }

                        $html = $fragment['html'] ?? '';

                        return \is_string($html) ? $html : '';
                    },
                    $innerContent
                )
            );

            // Initialize array to collect processed nodes
            $processedNodes = [];

            // Loop through each fragment in inner content
            foreach ($innerContent as $fragment) {
                // Check if fragment has a processed node
                if (
                    \is_array($fragment)
                    && isset($fragment['processedNode'])
                ) {
                    // Add processed node to collection
                    $processedNodes[] = $fragment['processedNode'];
                }
            }

            // Process all InnerBlocks content with TextProcessor
            $bodyContent = TextProcessor::processForBard(
                $innerHtml,
                $site,
                $assetImporter,
                $output,
                false, // Do not apply WpAutoP for uams-section InnerBlocks
                true, // Mark as InnerBlocks
                $attachments,
                $terms,
                $pageSlug,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle(),
                $processedNodes // Pass processed nodes
            );
        }

        // Determine if heading is empty or default placeholder
        $headingIsEmpty = $blockHeading === '' || $blockHeading === 'Section Title - Please Replace';

        // Check if body content is empty
        $bodyIsEmpty = $bodyContent === [];

        // ---
        // Skip block if body is empty (regardless of heading)
        // ---

        // Check if body is empty to skip the block
        if ($bodyIsEmpty) {
            // Get entry data for post_name access
            $entryData = ProcessingContext::getEntryData();

            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'block_heading' => $blockHeading,
                    'heading_is_default' => $blockHeading === 'Section Title - Please Replace',
                    'body_content_empty' => true,
                    'has_inner_content' => $innerContent !== [],
                    'normalized_data_keys' => \array_keys($normalizedData),
                    'section_heading_in_data' => isset($normalizedData['section_heading']),
                    'wordpress_post_id' => ProcessingContext::getWordPressPostId(),
                    'statamic_entry_id' => ProcessingContext::getStatamicEntryId(),
                    'post_name' => $entryData['post_name'] ?? null,
                ]
            );

            // Log warning when section is skipped due to empty body
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'SectionParser::parse: Returning null - empty body',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to skip the block
            return null;
        }

        // Check if heading is empty to set default placeholder
        if ($headingIsEmpty) {
            // Set heading to default placeholder when body has content but heading is empty
            $blockHeading = 'Section Title - Please Replace';
        }

        // Return replicator set with processed content
        return [
            'id' => (new RowId)->generate(), // Unique ID for replicator set
            'type' => 'uamsweb_block_content',
            'block_heading' => $blockHeading,
            'hide_block_heading' => $hideBlockHeading,
            'background_color' => $backgroundColor,
            'body' => $bodyContent,
            'enabled' => true,
        ];
    }

    /**
     * Parses WordPress modules template uams_section data into Statamic page_builder
     * format.
     *
     * This method handles the conversion of modules template data (from
     * uamswp_modules) to Statamic page_builder format, as opposed to the parse()
     * method which handles ACF block data. The modules template uses a different data
     * structure with prefixed field names. For modules template, body content is
     * empty as the section serves as a container for other modules.
     *
     * @param  array<int|string, mixed>  $moduleData  The module data from uamswp_modules
     * @param  string  $site  The site handle for asset processing
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $attachments  Array of attachment data for image processing
     * @param  ?XmlStreamParser  $xmlParser  The XML stream parser for additional data access
     * @param  array<int|string, mixed>  $terms  Array of terms data
     * @return array<int|string, mixed> The page_builder block with empty body content
     */
    public function parseModule(
        array $moduleData,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?XmlStreamParser $xmlParser = null,
        array $terms = []
    ): array {
        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $moduleData,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Map WordPress ACF fields to Statamic fields (now simplified with normalized data)
        $sectionHeading = $normalizedData['section_heading'] ?? 'Section Title - Please Replace';
        $blockHeading = HtmlTagStripper::strip(
            \is_string($sectionHeading)
                ? $sectionHeading
                : 'Section Title - Please Replace'
        );
        $hideBlockHeading = isset($normalizedData['section_hide_heading']) && $normalizedData['section_hide_heading'] === '1';
        $backgroundColor = $normalizedData['section_background_color'] ?? 'bg-auto';

        // For modules template, we don't have InnerBlocks, so we'll create empty body content
        // The section will be a container for other modules
        $bodyContent = [];

        return [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_content',
            'block_heading' => $blockHeading,
            'hide_block_heading' => $hideBlockHeading,
            'background_color' => $backgroundColor,
            'body' => $bodyContent,
            'enabled' => true,
        ];
    }
}
