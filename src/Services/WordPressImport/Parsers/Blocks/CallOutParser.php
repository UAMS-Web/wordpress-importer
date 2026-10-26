<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Parses WordPress ACF call-out blocks into Statamic page_builder replicator sets.
 *
 * Converts the ACF call-out block attributes (e.g., call_out_heading,
 * call_out_body, call_out_background_color, call_out_use_image, call_out_image)
 * into a Statamic uamsweb_block_call_out replicator set for the landing_page
 * blueprint's page_builder field. Handles textarea-based call_out_body as plain
 * text, maps background color options, and processes optional background images
 * with metadata using AssetImporter, mirroring the approach used for similar
 * blocks like uamsweb_block_cta.
 */
class CallOutParser
{
    /**
     * Parses a WordPress ACF call-out block into a Statamic page_builder replicator
     * set.
     *
     * Maps block attributes to a uamsweb_block_call_out set, including heading, body,
     * background color, and optional background image. The call_out_body textarea is
     * processed as plain text. The background image is imported only if
     * call_out_use_image is true, with metadata (alt, caption) applied via
     * AssetImporter.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/call-out comment
     *                                                (e.g., ['data' => ['call_out_heading' => '...']])
     * @param  string  $site  The Statamic site handle (e.g., 'student_information_system') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging import progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from the WordPress XML
     * @return array<int|string, mixed> The Statamic uamsweb_block_call_out replicator set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = []
    ): array {
        // Handle attributes with or without 'data' key, as per WordPress XML structure
        $dataRaw = $attributes['data'] ?? $attributes;
        $data = \is_array($dataRaw) ? $dataRaw : [];

        // Map basic fields from WordPress to Statamic
        $heading = self::stripStringField(
            $data,
            'call_out_heading'
        );
        $bodyContent = self::stripStringField(
            $data,
            'call_out_body'
        );
        $backgroundColorRaw = $data['call_out_background_color'] ?? 'bg-auto';
        $backgroundColor = \is_string($backgroundColorRaw)
            ? $backgroundColorRaw
            : 'bg-auto';

        // Handle background image toggle
        $useImage = (bool) ($data['call_out_use_image'] ?? '0');
        $backgroundImageQuery = $useImage;

        // Initialize background image as null
        $backgroundImage = null;

        // Check if call-out background image should be used
        $imageId = self::imageIdFromData(
            $data,
            'call_out_image'
        );

        if ($useImage && $imageId !== null) {
            // Import image with disabled upload handling
            $backgroundImage = ImageProcessor::importImageWithDisabledUploadHandling(
                $imageId,
                $site,
                $assetImporter,
                $output,
                $attachments,
                'call-out background image',
                'CallOutParser::parse'
            );

            // Check if image is available after import attempt
            if (
                $assetImporter->getUploadImages() // Missing images are only problematic when uploads are enabled
                && ($backgroundImage === null || $backgroundImage === '')
            ) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'image_id' => $imageId,
                    ]
                );

                // Log warning when uploads are enabled but no image is available
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'CallOutParser::parse: Failed to import call-out background image when uploads are enabled',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // When uploads are disabled, missing images are expected.
                // Continue processing without the image.
            }
        }

        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(), // Generate unique ID for replicator set
            'type' => 'uamsweb_block_call_out', // Statamic fieldset handle
            'block_heading' => $heading, // Required text field
            'hide_block_heading' => false, // Default to visible, as per fieldset
            'body' => $bodyContent, // Required textarea, processed as plain text
            'background_color' => $backgroundColor, // Required select field
            'background_image_query' => $backgroundImageQuery, // Required TrueFalse field
            'enabled' => true, // Standard for replicator sets
        ];

        // Include background_image only if imported successfully
        if ($backgroundImage !== null && $backgroundImage !== '') {
            $result['background_image'] = $backgroundImage;
        }

        return $result;
    }

    /**
     * Parses WordPress modules template call_out data into Statamic page_builder
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
        // Map basic fields from modules template format
        $heading = self::stripStringField(
            $moduleData,
            'call_out_heading'
        );
        $bodyContent = self::stripStringField(
            $moduleData,
            'call_out_body'
        );
        $backgroundColorRaw = $moduleData['call_out_background_color'] ?? 'bg-auto';
        $backgroundColor = \is_string($backgroundColorRaw)
            ? $backgroundColorRaw
            : 'bg-auto';

        // Handle background image toggle
        $useImage = (bool) ($moduleData['call_out_use_image'] ?? '0');
        $backgroundImageQuery = $useImage;

        // Initialize background image as null
        $backgroundImage = null;

        // Check if call-out background image should be used
        $imageId = self::imageIdFromData(
            $moduleData,
            'call_out_image'
        );

        if ($useImage && $imageId !== null) {
            // Import image with disabled upload handling
            $backgroundImage = ImageProcessor::importImageWithDisabledUploadHandling(
                $imageId,
                $site,
                $assetImporter,
                $output,
                $attachments,
                'call-out background image',
                'CallOutParser::parseModule'
            );

            // Check if image is available after import attempt
            if (
                $assetImporter->getUploadImages() // Missing images are only problematic when uploads are enabled
                && ($backgroundImage === null || $backgroundImage === '')
            ) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'image_id' => $imageId,
                    ]
                );

                // Log warning when uploads are enabled but no image is available
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'CallOutParser::parseModule: Failed to import call-out background image when uploads are enabled',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // When uploads are disabled, missing images are expected.
                // Continue processing without the image.
            }
        }

        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_call_out',
            'block_heading' => $heading,
            'hide_block_heading' => false,
            'body' => $bodyContent,
            'background_color' => $backgroundColor,
            'background_image_query' => $backgroundImageQuery,
            'enabled' => true,
        ];

        // Include background_image only if imported successfully
        if ($backgroundImage !== null && $backgroundImage !== '') {
            $result['background_image'] = $backgroundImage;
        }

        return $result;
    }

    /**
     * Strip HTML tags from a string field in a data array.
     *
     * @param  array<int|string, mixed>  $data  The source data array
     * @param  string  $key  The field key to read
     * @return string The stripped string value, or empty string when missing
     */
    private static function stripStringField(
        array $data,
        string $key
    ): string {
        $value = $data[$key] ?? null;

        return \is_string($value)
            ? HtmlTagStripper::strip($value)
            : '';
    }

    /**
     * Resolve an image attachment ID from a data array field.
     *
     * @param  array<int|string, mixed>  $data  The source data array
     * @param  string  $key  The field key to read
     * @return string|null The image ID string, or null when unavailable
     */
    private static function imageIdFromData(
        array $data,
        string $key
    ): ?string {
        $value = $data[$key] ?? null;

        if (! \is_scalar($value) || (string) $value === '') {
            return null;
        }

        return (string) $value;
    }
}
