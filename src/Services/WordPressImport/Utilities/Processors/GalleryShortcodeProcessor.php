<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits\DefaultTiptapHtmlTrait;

/**
 * Processes WordPress [gallery] shortcodes into Statamic Bard gallery sets.
 *
 * Converts gallery shortcodes with ids attribute into Bard gallery sets,
 * importing the specified images and creating a gallery set structure.
 */
class GalleryShortcodeProcessor implements ShortcodeProcessorInterface
{
    /**
     * Provides default implementation of toTiptapHtml method.
     *
     * This trait handles the standard conversion of shortcode data to
     * Tiptap-compatible HTML, including content processing and HTML
     * structure generation.
     */
    use DefaultTiptapHtmlTrait;

    /**
     * Parses gallery shortcode attributes into a Bard set structure.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> The Bard set structure
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        // Get attachments from ProcessingContext
        $attachments = ProcessingContext::getAttachments() ?? [];

        // Extract image IDs from the ids attribute
        $idsString = $attributes['ids'] ?? null;

        // Initialize array to store image IDs
        $imageIds = [];

        // Check if we have image IDs to process
        if (\is_string($idsString)) {
            // Split comma-separated IDs and trim whitespace
            $imageIds = \array_map(
                trim(...),
                \explode(
                    ',',
                    $idsString
                )
            );

            // Keep the numeric IDs, which drops an empty value too
            $imageIds = \array_filter(
                $imageIds,
                \is_numeric(...)
            );
        }

        // Check if we have any image IDs to process
        if ($imageIds === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'attributes' => $attributes,
                ]
            );

            // Log warning if no image IDs are found
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'GalleryShortcodeProcessor::parse: No valid image IDs found in gallery shortcode',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return empty gallery set
            return [
                'type' => 'gallery',
                'heading' => 'Image Gallery',
                'images' => [],
                'more_query' => false,
            ];
        }

        // Initialize array to store processed image paths
        $imagePaths = [];

        // Loop through each image ID to import images
        foreach ($imageIds as $imageId) {
            // Import image with disabled upload handling using utility method
            $assetPath = ImageProcessor::importImageWithDisabledUploadHandling(
                $imageId,
                $site,
                $assetImporter,
                $output,
                $attachments,
                'gallery image',
                'GalleryShortcodeProcessor::parse'
            );

            // Check if import was successful
            if ($assetPath !== null && $assetPath !== '') {
                // Add successfully imported image path to results
                $imagePaths[] = $assetPath;
            }
        }

        // Check if we have enough valid images for a gallery
        if (\count($imagePaths) < 2) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'image_count' => \count($imagePaths),
                    'expected_minimum' => 2,
                ]
            );

            // Log warning about insufficient images
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'GalleryShortcodeProcessor::parse: Insufficient images for gallery shortcode',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return empty gallery set for insufficient images
            return [
                'type' => 'gallery',
                'heading' => 'Image Gallery',
                'images' => [],
                'more_query' => false,
            ];
        }

        // Return the gallery set structure
        return [
            'type' => 'gallery',
            'heading' => 'Image Gallery',
            'images' => $imagePaths,
            'more_query' => false,
        ];
    }

    /**
     * Converts parsed gallery shortcode data into a page builder block.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> The uamsweb_block_gallery page builder block structure
     */
    public function toPageBuilderBlock(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        $set = $this->parse(
            $attributes,
            $site,
            $assetImporter,
            $output,
            $terms,
            $pageSlug
        );

        $block = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_gallery',
            'enabled' => true,
            'block_heading' => $set['heading'],
            'hide_block_heading' => false,
            'columns' => '3',
            'images' => $set['images'],
            // The `crop` radio declares numeric option keys, and `0` is None. The
            // ACF-block path emits the key type through
            // `GalleryParser::normalizeCropValue()`; this second emitter of the same
            // block and the same field has to agree, or one site holds both types
            // and the Control Panel radio stays unselected for shortcode galleries
            // (wordpress-importer-archive#430).
            'crop' => 0,
            'background_color' => 'bg-auto',
            'modal' => true,
            'more_query' => $set['more_query'],
        ];

        unset($set);

        return $block;
    }
}
