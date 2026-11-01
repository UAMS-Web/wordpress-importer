<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\AcfFieldNormalizer;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Parses WordPress ACF LiveWhale Calendar blocks into Statamic page_builder
 * replicator sets.
 *
 * Converts the ACF LiveWhale Calendar block attributes (e.g., livewhale_heading,
 * livewhale_id, livewhale_background_color) into a Statamic
 * uamsweb_block_livewhale replicator set for the landing_page blueprint's
 * page_builder field. Processes text fields and handles the livewhale_id as an
 * integer, omitting empty optional fields.
 */
class LiveWhaleParser
{
    /**
     * Processes the body textarea content as plain text.
     *
     * The block_description field in Statamic is a textarea, not WYSIWYG, so it's
     * processed as plain text without shortcode or Bard parsing if provided. Uses
     * TextProcessor to decode HTML entities for consistent special character handling.
     *
     * @param  string  $bodyContent  The raw textarea content
     * @return string The processed plain text content
     */
    private function processBody(
        string $bodyContent
    ): string {
        // Decode HTML entities and trim whitespace using TextProcessor
        return \trim(TextProcessor::decodeEntities($bodyContent));
    }

    /**
     * Defines the mapping between field-based ACF field names and standard field
     * names.
     *
     * This method provides the field mappings needed by AcfFieldNormalizer to handle
     * the conversion of WordPress ACF field names like field_livewhale_heading_b to
     * standard names like livewhale_heading.
     *
     * @return array<int|string, mixed> The field mappings for livewhale blocks
     */
    private function getFieldMappings(): array
    {
        return [
            'field_livewhale_heading_b' => 'livewhale_heading',
            'field_livewhale_id_b' => 'livewhale_id',
            'field_livewhale_background_color_b' => 'livewhale_background_color',
            'field_livewhale_description_b' => 'livewhale_description',
        ];
    }

    /**
     * Defines which nested structures should be processed for livewhale blocks.
     *
     * This method provides the nested structure mappings needed by AcfFieldNormalizer
     * to handle complex ACF block structures.
     *
     * @return array<int|string, mixed> The nested structure mappings for livewhale blocks
     */
    private function getNestedStructures(): array
    {
        return [
            // No nested structures for livewhale blocks
        ];
    }

    /**
     * Parses a WordPress ACF livewhale-calendar block into a Statamic page_builder
     * replicator set.
     *
     * Maps block attributes to a uamsweb_block_livewhale set, including heading,
     * widget ID, and background color. Processes text fields, sets default hidden
     * fields, and omits empty optional fields. Uses AcfFieldNormalizer for consistent
     * field mapping across different WordPress ACF data structures.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/livewhale-calendar comment
     * @param  string  $site  The Statamic site handle (e.g., 'default') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images (unused in this block)
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML (unused in this block)
     * @return array<int|string, mixed> The Statamic uamsweb_block_livewhale replicator set
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

        if (! \is_array($data)) {
            $data = [];
        }

        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Map fields using normalized data
        $headingRaw = $normalizedData['livewhale_heading'] ?? '';
        $heading = HtmlTagStripper::strip(
            \is_string($headingRaw) ? $headingRaw : ''
        );
        $widgetIdRaw = $normalizedData['livewhale_id'] ?? null;
        $widgetId = \is_numeric($widgetIdRaw) ? (int) $widgetIdRaw : 0;
        $backgroundColor = $normalizedData['livewhale_background_color'] ?? 'bg-auto';
        $descriptionRaw = $normalizedData['livewhale_description'] ?? null;
        $description = \is_string($descriptionRaw)
            ? $this->processBody($descriptionRaw)
            : '';

        // Validate required fields
        if ($heading === '' || $widgetId < 1) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'heading' => $heading,
                    'widget_id' => $widgetId,
                ]
            );

            // Log warning about missing required fields
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'LiveWhaleParser::parse: Skipping wp:acf/livewhale-calendar block due to missing required fields',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return [];
        }

        // Build the replicator set, omitting empty optional fields
        $result = [
            'id' => (new RowId)->generate(), // Unique ID for replicator set
            'type' => 'uamsweb_block_livewhale', // Statamic fieldset handle
            'block_heading' => $heading, // Required text
            'hide_block_heading' => false, // Hidden field, default value
            'livewhale_id' => $widgetId, // Required integer
            'background_color' => $backgroundColor, // Required select
            'enabled' => true, // Standard for replicator sets
        ];

        // Include optional description if non-empty
        if ($description !== '') {
            $result['block_description'] = $description; // Optional textarea
        }

        return $result;
    }

    /**
     * Parses WordPress modules template uams_livewhale data into Statamic
     * page_builder format.
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

        // Map fields from modules template format using normalized data
        $headingRaw = $normalizedData['livewhale_heading'] ?? '';
        $heading = HtmlTagStripper::strip(
            \is_string($headingRaw) ? $headingRaw : ''
        );
        $widgetIdRaw = $normalizedData['livewhale_id'] ?? null;
        $widgetId = \is_numeric($widgetIdRaw) ? (int) $widgetIdRaw : 0;
        $backgroundColor = $normalizedData['livewhale_background_color'] ?? 'bg-auto';
        $descriptionRaw = $normalizedData['livewhale_description'] ?? null;
        $description = \is_string($descriptionRaw)
            ? $this->processBody($descriptionRaw)
            : '';

        // Validate required fields
        if ($heading === '' || $widgetId < 1) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'heading' => $heading,
                    'widget_id' => $widgetId,
                ]
            );

            // Log warning about missing required fields
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'LiveWhaleParser::parseModule: Skipping modules template uams_livewhale due to missing required fields',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // Build the replicator set, omitting empty optional fields
        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_livewhale',
            'block_heading' => $heading,
            'hide_block_heading' => false,
            'livewhale_id' => $widgetId,
            'background_color' => $backgroundColor,
            'enabled' => true,
        ];

        // Include optional description if non-empty
        if ($description !== '') {
            $result['block_description'] = $description;
        }

        return $result;
    }
}
