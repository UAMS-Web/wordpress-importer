<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\AcfFieldNormalizer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BardParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;

/**
 * Parses WordPress ACF uams-gallery blocks into Statamic page_builder replicator
 * sets.
 *
 * Converts the ACF uams-gallery block attributes (e.g., gallery_heading,
 * gallery_images, gallery_more) into a Statamic uamsweb_block_gallery replicator
 * set for the landing_page blueprint's page_builder field. Processes WYSIWYG
 * fields as Bard content, imports images, and handles conditional fields for the
 * "more" link.
 */
class GalleryParser
{
    /**
     * Fewest images the `uamsweb_block_gallery` block will render.
     *
     * The importer's own intake floor is 1, not 2 — `wordpress-importer-archive#291` lowered it deliberately
     * because Gutenberg never enforced the block's minimum, so single-image
     * galleries exist in the corpus and dropping them would delete content the
     * source site displayed. This constant is the BLOCK's rule, used only to
     * report the disagreement (wordpress-importer-archive#512).
     */
    private const int BLOCK_MINIMUM_IMAGES = 2;

    /**
     * Most images the `uamsweb_block_gallery` block will render.
     */
    private const int BLOCK_MAXIMUM_IMAGES = 6;

    private WpAutoP $wpAutoP;

    private BardParser $bardParser;

    private ShortcodeProcessor $shortcodeProcessor;

    /**
     * Instance of LinkProcessor for preprocessing links in the gallery description.
     */
    private LinkProcessor $linkProcessor;

    /**
     * Constructs a new GalleryParser instance with its dependencies.
     *
     * @param  ?WpAutoP  $wpAutoP  Optional WpAutoP instance for WYSIWYG formatting
     * @param  ?BardParser  $bardParser  Optional Bard parser for Tiptap parsing
     * @param  ?ShortcodeProcessor  $shortcodeProcessor  Optional shortcode processor instance
     * @param  ?LinkProcessor  $linkProcessor  Optional link processor for the description's links
     */
    public function __construct(
        ?WpAutoP $wpAutoP = null,
        ?BardParser $bardParser = null,
        ?ShortcodeProcessor $shortcodeProcessor = null,
        ?LinkProcessor $linkProcessor = null
    ) {
        // Initialize WpAutoP instance for WordPress-style paragraph formatting
        $this->wpAutoP = $wpAutoP ?? new WpAutoP;

        // Initialize BardParser instance for converting HTML to Tiptap format
        $this->bardParser = $bardParser ?? new BardParser;

        // Initialize ShortcodeProcessor instance for handling WordPress shortcodes
        $this->shortcodeProcessor = $shortcodeProcessor ?? new ShortcodeProcessor;

        // Initialize LinkProcessor instance for internal links and linked files
        $this->linkProcessor = $linkProcessor ?? new LinkProcessor;
    }

    /**
     * Defines the mapping between field-based ACF field names and standard field
     * names.
     *
     * This method provides the field mappings needed by AcfFieldNormalizer to
     * handle the conversion of WordPress ACF field names like field_gallery_heading_b
     * to standard names like gallery_heading.
     *
     * @return array<int|string, mixed> The field mappings for gallery blocks
     */
    private function getFieldMappings(): array
    {
        return [
            'field_gallery_heading_b' => 'gallery_heading',
            'field_gallery_hide_heading_b' => 'gallery_hide_heading',
            'field_gallery_description_b' => 'gallery_description',
            'field_gallery_columns_b' => 'gallery_columns',
            'field_gallery_images_b' => 'gallery_images',
            'field_gallery_crop_b' => 'gallery_crop',
            'field_gallery_background_color_b' => 'gallery_background_color',
            'field_gallery_modal_b' => 'gallery_modal',
            'field_gallery_more_b' => 'gallery_more',
            'field_gallery_more_text_b' => 'gallery_more_text',
            'field_gallery_more_button_text_b' => 'gallery_more_button_text',
            'field_gallery_more_button_url_b' => 'gallery_more_button_url',
            'field_gallery_more_button_description_b' => 'gallery_more_button_description',
            'field_gallery_geo_valid_b' => 'gallery_geo_valid',
            'field_gallery_geo_b' => 'gallery_geo',
            'field_gallery_geo_region_b' => 'gallery_geo_region',
        ];
    }

    /**
     * Defines which nested structures should be processed for gallery blocks.
     *
     * This method provides the nested structure mappings needed by AcfFieldNormalizer
     * to handle complex ACF block structures.
     *
     * @return array<int|string, mixed> The nested structure mappings for gallery blocks
     */
    private function getNestedStructures(): array
    {
        return [
            // No nested structures for gallery blocks
        ];
    }

    /**
     * Processes the WYSIWYG content for Bard field compatibility.
     *
     * Converts the WordPress WYSIWYG content (e.g., gallery_description) into Bard
     * format, applying wpautop formatting, preprocessing shortcodes with site
     * context, running its links through LinkProcessor as every other block's
     * WYSIWYG field does (wordpress-importer-archive#1205), and parsing with Tiptap.
     *
     * @param  string  $content  The raw WYSIWYG content
     * @param  string  $site  The Statamic site handle for shortcode processing
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of terms for shortcode processing
     * @return array<int|string, mixed>|null The processed Bard content or null if empty
     */
    private function processWysiwyg(
        string $content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = []
    ): ?array {
        // Check if content is empty or contains only whitespace
        if ((\trim($content) === '')) {
            // Return null for empty content
            return null;
        }

        // Preprocess shortcodes
        $shortcodes = $this->shortcodeProcessor->extract(
            $content,
            $output
        );

        // Initialize formatted content with original content
        $formattedContent = $content;

        // Loop through each extracted shortcode and convert to Tiptap HTML
        foreach ($shortcodes as $shortcode) {
            // Skip malformed shortcode payloads that are missing required keys
            if (
                ! \is_array($shortcode)
                || ! isset($shortcode['original'])
                || ! \is_string($shortcode['original'])
            ) {
                continue;
            }

            // Replace shortcode with converted Tiptap HTML
            $formattedContent = \str_replace(
                $shortcode['original'],
                $this->shortcodeProcessor->toTiptapHtml(
                    $shortcode,
                    $site,
                    $assetImporter,
                    $output,
                    [], // No attachments needed for WYSIWYG processing
                    $terms
                ),
                $formattedContent
            );
        }

        // Apply wpautop formatting to mimic WordPress WYSIWYG
        $formattedContent = $this->wpAutoP->process($formattedContent);

        // Preprocess links, converting internal page links and importing linked files
        $formattedContent = $this->linkProcessor->process(
            $formattedContent,
            $site,
            $assetImporter,
            $output,
            ProcessingContext::getStatamicEntryId(),
            ProcessingContext::getCollectionHandle(),
            ProcessingContext::getXmlPath()
        );

        // Parse with Tiptap into Bard format
        $bardContent = $this->bardParser->parse($formattedContent);

        // Prepare result, returning null if Bard content is empty
        $result = $bardContent === [] ? null : $bardContent;

        // Clean up variables to free up memory
        unset(
            $shortcodes,
            $formattedContent,
            $bardContent
        );

        // Return the processed Bard content or null
        return $result;
    }

    /**
     * Normalizes the gallery_images field value to an array.
     *
     * Handles unserialization of serialized PHP arrays from WordPress postmeta,
     * converts single values to arrays, and ensures the result is always an array.
     *
     * @param  mixed  $imageIds  The gallery_images value (may be array, string, or serialized string)
     * @return array<int|string, mixed> Array of image IDs, with a single value wrapped
     */
    private function normalizeGalleryImages(
        mixed $imageIds
    ): array {
        // Check if imageIds is a serialized string and unserialize it
        if (\is_string($imageIds)) {
            // Attempt to unserialize the string value
            $unserialized = @\unserialize($imageIds, ['allowed_classes' => false]);

            // Check if unserialization produced an array
            if (\is_array($unserialized)) {
                // Use the unserialized array
                $imageIds = $unserialized;
            }
        }

        // ---
        // Ensure imageIds is always an array (ACF normalization may return string for
        // single values)
        // ---

        // Wrap a single value; processImages() drops any id that is not numeric, an
        // empty string among them
        return \is_array($imageIds) ? $imageIds : [$imageIds];
    }

    /**
     * Processes the gallery_images field to import WordPress images into Statamic.
     *
     * Maps WordPress image IDs to Statamic asset paths, importing images using the
     * AssetImporter. When image uploads are disabled, uses original URLs instead.
     * Validates the number of images against the IMPORTER's floor of 1 — not the
     * block's 2 — because Gutenberg never enforced the block minimum, so
     * single-image galleries exist in the corpus and `wordpress-importer-archive#291` chose to keep them
     * rather than delete content the source site displayed. The ceiling is 6, which
     * matches the block. Where the two rules disagree the gallery still imports and
     * reportUnrenderableGallery() records it (wordpress-importer-archive#512). Handles disabled uploads
     * gracefully by continuing processing even when images fail to import.
     *
     * @param  array<int|string, mixed>  $imageIds  Array of WordPress image IDs
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML
     * @param  ?string  $sourceUrl  The source URL for logging context
     * @return array<int|string, mixed> Array of Statamic asset paths or original URLs, or empty if invalid
     */
    private function processImages(
        array $imageIds,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        ?string $sourceUrl = null
    ): array {
        // Initialize array to store processed image paths
        $imagePaths = [];

        // Filter image IDs to only include numeric values
        $imageIds = \array_filter(
            $imageIds,
            is_numeric(...)
        );

        // Check if we have a valid number of images (between 1 and 6)
        if (
            \count($imageIds) < 1 // The actual minimum is 2, but Gutenberg didn't enforce it
            || \count($imageIds) > 6
        ) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'source_url' => $sourceUrl,
                    'image_count' => \count($imageIds),
                ]
            );

            // Log warning about invalid image count
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'GalleryParser::processImages: Invalid number of images for gallery block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return empty array for invalid image count
            return [];
        }

        // Loop through each image ID to import images
        foreach ($imageIds as $imageId) {
            // Import image with disabled upload handling using utility method
            $assetPath = ImageProcessor::importImageWithDisabledUploadHandling(
                (string) $imageId,
                $site,
                $assetImporter,
                $output,
                $attachments,
                'gallery image',
                'GalleryParser::processImages'
            );

            // Check if import was successful
            if ($assetPath !== null && $assetPath !== '') {
                // Add successfully imported image path to results
                $imagePaths[] = $assetPath;
            } else { // Otherwise
                // ---
                // Check if image uploads are enabled (missing images are only problematic when
                // uploads are enabled)
                // ---
                if ($assetImporter->getUploadImages()) {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'site' => $site,
                            'source_url' => $sourceUrl,
                            'image_id' => $imageId,
                        ]
                    );

                    // Log warning about failed import when uploads are enabled
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'GalleryParser::processImages: Failed to import image when uploads are enabled',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }

                // When uploads are disabled, missing images are expected.
                // Continue processing without this image.
            }
        }

        // Keep the gallery as long as at least one image imported successfully. A
        // single valid image is a usable gallery; only a gallery that produced zero
        // valid images is discarded. (The intake check above already accepts a
        // single image id, so the post-import minimum matches it.)
        if (\count($imagePaths) < 1) {
            // ---
            // Check if image uploads are enabled (zero valid images are only problematic
            // when uploads are enabled)
            // ---
            if ($assetImporter->getUploadImages()) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'source_url' => $sourceUrl,
                        'valid_image_count' => \count($imagePaths),
                    ]
                );

                // Log warning about no valid images when uploads are enabled
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'GalleryParser::processImages: No valid images after import when uploads are enabled',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // When uploads are disabled, missing images are expected. Either way the
            // empty list is returned below, and parse() skips the block only when
            // uploads are enabled.
        }

        // Clean up variables to free up memory
        unset(
            $imageIds,
            $imageId,
            $assetPath
        );

        // Return the array of processed image paths
        return $imagePaths;
    }

    /**
     * Converts a WordPress `gallery_crop` value to the block's radio option key.
     *
     * The `crop` radio in `uamsweb_block_gallery.yaml` declares NUMERIC option keys
     * — `1`, `0`, `1.7778`, `1.3333`, `0.75` — while WordPress stores the value as a
     * string, using the literal `none` for the `0` (None) option. Emitting the string
     * form left the stored value's type diverging from the blueprint, so the Control
     * Panel radio did not mark the imported option as selected on edit. The template
     * still rendered it, because its ratio line unwraps either form with a loose
     * `block:crop != 0` (wordpress-importer-archive#430, and see #1514 for the render repair itself).
     *
     * Any other numeric string is cast rather than whitelisted, so a crop key added
     * to the blueprint later still imports with the right type. A non-numeric value
     * cannot match any option, so it warns and falls back to None — which is also the
     * field's own default.
     *
     * @param  string  $cropValue  The raw WordPress `gallery_crop` value
     * @param  string  $site  The Statamic site handle, for log context
     * @return int|float The radio's option key
     */
    private function normalizeCropValue(
        string $cropValue,
        string $site
    ): int|float {
        // Trim so a padded export value still matches
        $cropValue = \trim($cropValue);

        // `none` is the WordPress spelling of the radio's `0` (None) key
        if (
            $cropValue === 'none'
            || $cropValue === ''
        ) {
            return 0;
        }

        // Reject anything that cannot be an option key before casting
        if (! \is_numeric($cropValue)) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'gallery_crop' => $cropValue,
                ]
            );

            // Log warning about the unrecognized crop value
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'GalleryParser::normalizeCropValue: Unrecognized gallery crop value; falling back to None',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return 0;
        }

        // ---
        // Cast to the option key's own type: the whole-number keys (`1`, `0`) are
        // integers in the fieldset and the ratios (`1.7778`, `1.3333`, `0.75`) floats.
        // Adding zero preserves that split — `'1'` yields int 1, `'1.7778'` float —
        // where an explicit (int) or (float) cast would flatten one of them.
        // ---
        // Subtracting zero gives the same key as adding it, so PlusToMinus cannot be killed.
        // @pest-mutate-ignore: PlusToMinus
        return $cropValue + 0;
    }

    /**
     * Extracts gallery fields from normalized data.
     *
     * Processes all gallery-related fields from normalized ACF data, handling
     * differences between ACF block format and modules template format for URL
     * extraction.
     *
     * @param  array<int|string, mixed>  $normalizedData  The normalized ACF field data
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of terms for shortcode processing
     * @param  bool  $isModule  Whether this is from modules template (affects URL processing)
     * @return array{
     *     heading: string,
     *     hideHeading: bool,
     *     description: array<int|string, mixed>|null,
     *     columns: string,
     *     imageIds: array<int|string, mixed>,
     *     crop: int|float,
     *     backgroundColor: string,
     *     modal: bool,
     *     moreQuery: bool,
     *     moreHeading: string,
     *     moreButtonText: string,
     *     moreButtonUrl: string,
     *     moreButtonTarget: bool,
     *     moreButtonDescription: string
     * } Array of extracted field values
     */
    private function extractGalleryFields(
        array $normalizedData,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        bool $isModule = false
    ): array {
        // Extract gallery heading from normalized data
        $heading = HtmlTagStripper::strip(
            $this->readStringValue(
                $normalizedData,
                'gallery_heading'
            )
        );

        // Extract hide heading flag and convert to boolean
        $hideHeading = ($normalizedData['gallery_hide_heading'] ?? '0') === '1';

        // Process gallery description as WYSIWYG content if present
        $description = isset($normalizedData['gallery_description'])
            ? $this->processWysiwyg(
                $this->readStringValue(
                    $normalizedData,
                    'gallery_description'
                ),
                $site,
                $assetImporter,
                $output,
                $terms
            ) : null;

        // Extract gallery columns setting with default of 3
        $columns = $this->readStringValue(
            $normalizedData,
            'gallery_columns',
            '3'
        );

        // Extract and normalize gallery images array
        $imageIds = $this->normalizeGalleryImages(
            $normalizedData['gallery_images'] ?? []
        );

        // ---
        // Get gallery crop value from normalized data, defaulting to '0' if the key is
        // missing
        // ---
        $cropValue = $this->readStringValue(
            $normalizedData,
            'gallery_crop',
            '0'
        );

        // Convert the WordPress crop value to the radio's numeric option key
        $crop = $this->normalizeCropValue(
            $cropValue,
            $site
        );

        // Extract background color with default of 'bg-auto'
        $backgroundColor = $this->readStringValue(
            $normalizedData,
            'gallery_background_color',
            'bg-auto'
        );

        // Extract modal setting and convert to boolean
        $modal = ($normalizedData['gallery_modal'] ?? '1') === '1';

        // Extract more query flag and convert to boolean
        $moreQuery = ($normalizedData['gallery_more'] ?? '0') === '1';

        // Extract more heading text
        $moreHeading = HtmlTagStripper::strip(
            $this->readStringValue(
                $normalizedData,
                'gallery_more_text'
            )
        );

        // Extract more button text
        $moreButtonText = HtmlTagStripper::strip(
            $this->readStringValue(
                $normalizedData,
                'gallery_more_button_text'
            )
        );

        // ---
        // Extract more button URL and target based on format
        // ---

        // Check if this is from modules template
        if ($isModule) {
            // Extract original more button URL using LinkHelper
            $originalMoreButtonUrl = LinkHelper::getUrl($normalizedData['gallery_more_button_url'] ?? '');

            // Process URL through LinkProcessor to handle internal links and file imports
            $moreButtonUrl = LinkProcessor::processUrlString(
                $originalMoreButtonUrl,
                $site,
                $assetImporter,
                $output,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );

            // Extract more button target using LinkHelper
            $moreButtonTarget = LinkHelper::getTarget($normalizedData['gallery_more_button_url'] ?? null);
        } else { // Otherwise
            // Extract more button URL data array
            $moreButtonUrlData = $normalizedData['gallery_more_button_url'] ?? [];
            if (! \is_array($moreButtonUrlData)) {
                $moreButtonUrlData = [];
            }

            // Extract URL from button URL data, then process it through LinkProcessor
            // as the modules branch does, so the ACF block's button is not stored raw (wordpress-importer-archive#1205)
            $moreButtonUrl = LinkProcessor::processUrlString(
                $this->readStringValue(
                    $moreButtonUrlData,
                    'url'
                ),
                $site,
                $assetImporter,
                $output,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );

            // Extract target setting and convert to boolean
            $moreButtonTarget = isset($moreButtonUrlData['target']) && $moreButtonUrlData['target'] === '_blank';
        }

        // Extract more button description
        $moreButtonDescription = HtmlTagStripper::strip(
            $this->readStringValue(
                $normalizedData,
                'gallery_more_button_description'
            )
        );

        // Return the extracted fields array
        return [
            'heading' => $heading,
            'hideHeading' => $hideHeading,
            'description' => $description,
            'columns' => $columns,
            'imageIds' => $imageIds,
            'crop' => $crop,
            'backgroundColor' => $backgroundColor,
            'modal' => $modal,
            'moreQuery' => $moreQuery,
            'moreHeading' => $moreHeading,
            'moreButtonText' => $moreButtonText,
            'moreButtonUrl' => $moreButtonUrl,
            'moreButtonTarget' => $moreButtonTarget,
            'moreButtonDescription' => $moreButtonDescription,
        ];
    }

    /**
     * Validates the gallery heading field.
     *
     * @param  string  $heading  The gallery heading
     * @param  string  $site  The Statamic site handle
     * @param  ?string  $sourceUrl  The source URL for logging context
     * @param  string  $methodName  The method name for log messages
     * @param  string  $blockType  The block type description for log messages
     * @return array{message: string, context: array<int|string, mixed>, pageUrl: string}|null Null
     *                                                                                         if valid, validation payload if invalid
     */
    private function validateHeading(
        string $heading,
        string $site,
        ?string $sourceUrl,
        string $methodName,
        string $blockType = 'wp:acf/uams-gallery block'
    ): ?array {
        // Check if required heading field is present
        if ($heading === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'source_url' => $sourceUrl,
                ]
            );

            // Return validation error details
            $pageUrl = $this->readStringValue(
                $logData,
                'page_url'
            );

            return [
                'message' => "$methodName: Skipping $blockType due to missing required heading",
                'context' => $logData['context'],
                'pageUrl' => $pageUrl,
            ];
        }

        // Return null to indicate validation passed
        return null;
    }

    /**
     * Validates that images were successfully processed.
     *
     * @param  array<int|string, mixed>  $images  The processed images array
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  string  $site  The Statamic site handle
     * @param  ?string  $sourceUrl  The source URL for logging context
     * @param  string  $methodName  The method name for log messages
     * @param  string  $blockType  The block type description for log messages
     * @return array{message: string, context: array<int|string, mixed>, pageUrl: string}|null Null
     *                                                                                         if valid, validation payload if invalid
     */
    private function validateImages(
        array $images,
        AssetImporter $assetImporter,
        string $site,
        ?string $sourceUrl,
        string $methodName,
        string $blockType = 'wp:acf/uams-gallery block'
    ): ?array {
        // Check if any images were successfully processed
        if (
            $assetImporter->getUploadImages() // Missing images are only problematic when uploads are enabled
            && $images === []
        ) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'source_url' => $sourceUrl,
                ]
            );

            // Return validation error details
            $pageUrl = $this->readStringValue(
                $logData,
                'page_url'
            );

            return [
                'message' => "$methodName: Skipping $blockType due to invalid or missing images when uploads are enabled",
                'context' => $logData['context'],
                'pageUrl' => $pageUrl,
            ];
        }

        // Return null to indicate validation passed
        return null;
    }

    /**
     * Validates the "more" link fields if enabled.
     *
     * @param  bool  $moreQuery  Whether more query is enabled
     * @param  string  $moreHeading  The more heading text
     * @param  string  $moreButtonText  The more button text
     * @param  string  $moreButtonUrl  The more button URL
     * @param  string  $site  The Statamic site handle
     * @param  ?string  $sourceUrl  The source URL for logging context
     * @param  string  $methodName  The method name for log messages
     * @param  string  $blockType  The block type description for log messages
     * @return array{message: string, context: array<int|string, mixed>, pageUrl: string}|null Null
     *                                                                                         if valid, validation payload if invalid
     */
    private function validateMoreLinkFields(
        bool $moreQuery,
        string $moreHeading,
        string $moreButtonText,
        string $moreButtonUrl,
        string $site,
        ?string $sourceUrl,
        string $methodName,
        string $blockType = 'wp:acf/uams-gallery block'
    ): ?array {
        // ---
        // Validate "more" link fields if enabled
        // ---

        // Check if more query is enabled and if the required fields are empty
        if (
            $moreQuery
            && (
                $moreHeading === ''
                || $moreButtonText === ''
                || $moreButtonUrl === ''
            )
        ) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'source_url' => $sourceUrl,
                    'more_heading' => $moreHeading,
                    'more_button_text' => $moreButtonText,
                    'more_button_url' => $moreButtonUrl,
                ]
            );

            // Return validation error details
            $pageUrl = $this->readStringValue(
                $logData,
                'page_url'
            );

            return [
                'message' => "$methodName: Skipping $blockType due to missing required more link fields",
                'context' => $logData['context'],
                'pageUrl' => $pageUrl,
            ];
        }

        // Return null to indicate validation passed
        return null;
    }

    /**
     * Builds the Statamic replicator set from extracted gallery fields.
     *
     * @param  array<int|string, mixed>  $fields  The extracted gallery fields
     * @param  array<int|string, mixed>  $images  The processed images array
     * @param  string  $site  The Statamic site handle, for reporting context
     * @param  ?string  $sourceUrl  The source URL, for reporting context
     * @return array<int|string, mixed> The Statamic uamsweb_block_gallery replicator set
     */
    private function buildReplicatorSet(
        array $fields,
        array $images,
        string $site = '',
        ?string $sourceUrl = null
    ): array {
        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_gallery',
            'block_heading' => $fields['heading'],
            'hide_block_heading' => $fields['hideHeading'],
            'columns' => $fields['columns'],
            'images' => $images,
            'crop' => $fields['crop'],
            'background_color' => $fields['backgroundColor'],
            'modal' => $fields['modal'],
            'more_query' => $fields['moreQuery'],
            'enabled' => true,
        ];

        // ---
        // Add optional description field if present
        // ---

        // Check if description is present
        if ($fields['description'] !== null) {
            // Set block description from processed WYSIWYG content
            $result['block_description'] = $fields['description'];
        }

        // Add more link fields if more query is enabled
        if ($fields['moreQuery'] === true) {
            // Set more heading text
            $result['more_heading'] = $fields['moreHeading'];

            // Set more button text
            $result['more_button_text'] = $fields['moreButtonText'];

            // Set more button URL
            $result['more_button_url'] = $fields['moreButtonUrl'];

            // Set more button target
            $result['more_button_target'] = $fields['moreButtonTarget'];

            // Add optional button description if present
            if ($fields['moreButtonDescription'] !== '') {
                // Set optional button link description
                $result['more_button_link_description'] = $fields['moreButtonDescription'];
            }
        }

        // Report anything the block's required-field contract will reject at render
        $this->reportUnrenderableGallery(
            $result,
            $site,
            $sourceUrl
        );

        // Return the completed replicator set
        return $result;
    }

    /**
     * Reports a gallery that cannot satisfy the block's required-field contract.
     *
     * `uamsweb_block_gallery` renders only when every required field has a value —
     * `UAMS-Web/uams-statamic@df1ab6049` applied that guard to 17 blocks
     * deliberately. For gallery the contract is `block_heading`,
     * `block_description`, `background_color`, `columns`, 2-6 `images`, `crop`,
     * `modal` and `more_query`; a block that misses any of them outputs nothing.
     *
     * The importer does not enforce that contract, and deliberately so: the
     * gallery is imported anyway and reported here, rather than skipped (which
     * would silently delete content the source site displayed) or completed with
     * synthesized copy (which would invent editorial text). The block then waits
     * for an editor to supply what is missing, and the report is what makes that
     * visible instead of a blank space nobody was told about (wordpress-importer-archive#512).
     *
     * The image floor stays at 1. `wordpress-importer-archive#291` lowered the post-import minimum from 2 to
     * 1 on purpose, so the two rules genuinely disagree and this reports the
     * disagreement rather than resolving it by dropping galleries.
     *
     * Measured over the 307 `wp:acf/uams-gallery` blocks in the export corpus: 198
     * (64%) carry no description and 15 carry exactly one image.
     *
     * @param  array<int|string, mixed>  $result  The assembled replicator set
     * @param  string  $site  The Statamic site handle, for reporting context
     * @param  ?string  $sourceUrl  The source URL, for reporting context
     */
    private function reportUnrenderableGallery(
        array $result,
        string $site,
        ?string $sourceUrl
    ): void {
        $missing = [];

        // ---
        // A description the block requires but WordPress never carried. `isset()`
        // covers both the absent key and a null payload, since buildReplicatorSet()
        // omits the key entirely when processWysiwyg() returned null.
        // ---
        if (
            ! isset($result['block_description'])
            || $result['block_description'] === []
        ) {
            $missing[] = 'block_description';
        }

        // An image count the block's 2-6 rule rejects
        $imageCount = \is_array($result['images'] ?? null)
            ? \count($result['images'])
            : 0;

        if (
            $imageCount < self::BLOCK_MINIMUM_IMAGES
            || $imageCount > self::BLOCK_MAXIMUM_IMAGES
        ) {
            $missing[] = 'images';
        }

        // A gallery that satisfies the contract needs no report
        if ($missing === []) {
            return;
        }

        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            [
                'site' => $site,
                'source_url' => $sourceUrl,
            ]
        );

        $pageUrl = $this->readStringValue(
            $logData,
            'page_url'
        );

        ImportWarningManager::addWarning(
            'content',
            'unrenderable_gallery_blocks',
            [
                'block_type' => 'uamsweb_block_gallery',
                'page_url' => $pageUrl,
                'heading' => $result['block_heading'],
                'missing_fields' => $missing,
                'image_count' => $imageCount,
                'reason' => 'The gallery block renders only when every required field '
                    .'has a value, so this block imports but displays nothing until an '
                    .'editor supplies the missing fields.',
            ]
        );

        // Keep the detail in the log alongside the import report
        $this->logWarning(
            \sprintf(
                'GalleryParser::reportUnrenderableGallery: Imported a %s that cannot render — missing %s',
                'wp:acf/uams-gallery block',
                \implode(', ', $missing)
            ),
            $logData['context'],
            $pageUrl
        );
    }

    /**
     * Logs a warning message with entry context.
     *
     * @param  string  $message  The log message
     * @param  array<int|string, mixed>  $context  The log context
     * @param  string  $pageUrl  The page URL for log formatting
     */
    private function logWarning(
        string $message,
        array $context,
        string $pageUrl
    ): void {
        // Log warning
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                $message,
                $pageUrl
            ),
            $context
        );
    }

    /**
     * Parses a WordPress ACF uams-gallery block into a Statamic page_builder
     * replicator set.
     *
     * Maps attributes to a uamsweb_block_gallery set, processing images, WYSIWYG
     * content, and conditional "more" link fields. Validates required fields and
     * omits empty optional fields. Processes shortcodes in WYSIWYG content with
     * site context for accurate Bard set conversion. Uses AcfFieldNormalizer for
     * consistent field mapping across different WordPress ACF data structures.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/uams-gallery comment
     * @param  string  $site  The Statamic site handle for asset paths and shortcode processing
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML
     * @param  array<int|string, mixed>  $terms  Array of terms for shortcode processing
     * @param  ?string  $sourceUrl  The source URL for logging context
     * @return array<int|string, mixed> The Statamic uamsweb_block_gallery replicator set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $sourceUrl = null
    ): array {
        // Handle attributes with or without 'data' key
        $data = isset($attributes['data']) && \is_array($attributes['data'])
            ? $attributes['data']
            : $attributes;

        // Normalize field names using AcfFieldNormalizer

        // Normalize the data using field mappings and nested structures
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Extract gallery fields from normalized data
        $fields = $this->extractGalleryFields(
            $normalizedData,
            $site,
            $assetImporter,
            $output,
            $terms,
            false
        );

        // Validate heading field
        $validation = $this->validateHeading(
            $fields['heading'],
            $site,
            $sourceUrl,
            'GalleryParser::parse',
            'wp:acf/uams-gallery block'
        );

        // Check if validation failed
        if ($validation !== null) {
            // Log warning about missing required heading
            $this->logWarning(
                $validation['message'],
                $validation['context'],
                $validation['pageUrl']
            );

            // Clean up variables to free up memory
            unset(
                $data,
                $normalizedData,
                $fields
            );

            // Return empty array to skip this block
            return [];
        }

        // Process images
        $images = $this->processImages(
            $fields['imageIds'],
            $site,
            $assetImporter,
            $output,
            $attachments,
            $sourceUrl
        );

        // Validate images
        $validation = $this->validateImages(
            $images,
            $assetImporter,
            $site,
            $sourceUrl,
            'GalleryParser::parse',
            'wp:acf/uams-gallery block'
        );

        // Check if validation failed
        if ($validation !== null) {
            // Log warning and skip block when uploads are enabled but no images are available
            $this->logWarning(
                $validation['message'],
                $validation['context'],
                $validation['pageUrl']
            );

            // Clean up variables to free up memory
            unset(
                $data,
                $normalizedData,
                $fields,
                $images
            );

            // Return empty array to skip the block
            return [];

            // When uploads are disabled, missing images are expected.
            // Continue processing with empty images array.
        }

        // Validate "more" link fields if enabled
        $validation = $this->validateMoreLinkFields(
            $fields['moreQuery'],
            $fields['moreHeading'],
            $fields['moreButtonText'],
            $fields['moreButtonUrl'],
            $site,
            $sourceUrl,
            'GalleryParser::parse',
            'wp:acf/uams-gallery block'
        );

        // Check if validation failed
        if ($validation !== null) {
            // Log warning about missing required more link fields
            $this->logWarning(
                $validation['message'],
                $validation['context'],
                $validation['pageUrl']
            );

            // Clean up variables to free up memory
            unset(
                $data,
                $normalizedData,
                $fields,
                $images
            );

            // Return empty array to skip this block
            return [];
        }

        // Build the replicator set
        $result = $this->buildReplicatorSet(
            $fields,
            $images,
            $site,
            $sourceUrl
        );

        // Clean up variables to free up memory
        unset(
            $data,
            $normalizedData,
            $fields,
            $images
        );

        // Return the completed replicator set
        return $result;
    }

    /**
     * Parses WordPress modules template uams_gallery data into Statamic page_builder
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
     * @param  array<int|string, mixed>  $terms  Array of terms for shortcode processing
     * @param  ?string  $sourceUrl  The source URL for logging context
     * @return array<int|string, mixed>|null The page_builder block or null if parsing failed
     */
    public function parseModule(
        array $moduleData,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?XmlStreamParser $xmlParser = null,
        array $terms = [],
        ?string $sourceUrl = null
    ): ?array {
        // Normalize field names using AcfFieldNormalizer
        // Normalize the module data using field mappings and nested structures
        $normalizedData = AcfFieldNormalizer::normalize(
            $moduleData,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Extract gallery fields from normalized data
        $fields = $this->extractGalleryFields(
            $normalizedData,
            $site,
            $assetImporter,
            $output,
            $terms,
            true
        );

        // Validate heading field
        $validation = $this->validateHeading(
            $fields['heading'],
            $site,
            $sourceUrl,
            'GalleryParser::parseModule',
            'modules template uams_gallery'
        );

        // Check if validation failed
        if ($validation !== null) {
            // Log warning about missing required heading
            $this->logWarning(
                $validation['message'],
                $validation['context'],
                $validation['pageUrl']
            );

            // Clean up variables to free up memory
            unset(
                $normalizedData,
                $fields
            );

            // Return null to skip this module
            return null;
        }

        // Process images
        $images = $this->processImages(
            $fields['imageIds'],
            $site,
            $assetImporter,
            $output,
            $attachments,
            $sourceUrl
        );

        // Validate images
        $validation = $this->validateImages(
            $images,
            $assetImporter,
            $site,
            $sourceUrl,
            'GalleryParser::parseModule',
            'modules template uams_gallery'
        );

        // Check if validation failed
        if ($validation !== null) {
            // Log warning and skip block when uploads are enabled but no images are available
            $this->logWarning(
                $validation['message'],
                $validation['context'],
                $validation['pageUrl']
            );

            // Clean up variables to free up memory
            unset(
                $normalizedData,
                $fields,
                $images
            );

            // Return null to skip the block
            return null;

            // When uploads are disabled, missing images are expected.
            // Continue processing with empty images array.
        }

        // Validate "more" link fields if enabled
        $validation = $this->validateMoreLinkFields(
            $fields['moreQuery'],
            $fields['moreHeading'],
            $fields['moreButtonText'],
            $fields['moreButtonUrl'],
            $site,
            $sourceUrl,
            'GalleryParser::parseModule',
            'modules template uams_gallery'
        );

        // Check if validation failed
        if ($validation !== null) {
            // Log warning about missing required more link fields
            $this->logWarning(
                $validation['message'],
                $validation['context'],
                $validation['pageUrl']
            );

            // Clean up variables to free up memory
            unset(
                $normalizedData,
                $fields,
                $images
            );

            // Return null to skip this module
            return null;
        }

        // Build the replicator set
        $result = $this->buildReplicatorSet(
            $fields,
            $images,
            $site,
            $sourceUrl
        );

        // Clean up variables to free up memory
        unset(
            $normalizedData,
            $fields,
            $images
        );

        // Return the completed replicator set
        return $result;
    }

    /**
     * Reads a string value from an associative payload.
     *
     * @param  array<int|string, mixed>  $data  Payload to read from
     * @param  string  $key  Key to read
     * @param  string  $default  Fallback value when key is absent
     * @return string Normalized string value
     */
    private function readStringValue(
        array $data,
        string $key,
        string $default = ''
    ): string {
        // Return default when the key is missing
        if (! isset($data[$key])) {
            return $default;
        }

        // Convert scalar values, strings among them, to strings for downstream processing
        if (\is_scalar($data[$key])) {
            return (string) $data[$key];
        }

        // Fallback for unsupported value types
        return $default;
    }
}
