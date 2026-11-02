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
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

/**
 * Parses WordPress ACF text-stacked blocks into Statamic page_builder replicator
 * sets.
 *
 * Converts the ACF text-stacked block attributes (e.g., stacked_heading,
 * stacked_description, stacked_section, stacked_more) into a Statamic
 * uamsweb_block_stacked replicator set for the landing_page blueprint's
 * page_builder field. Handles textarea fields as plain text, processes the
 * stacked_section repeater, imports images with metadata, and maps footer link
 * fields.
 */
class StackedParser
{
    /**
     * Gets the field mappings for stacked blocks.
     *
     * Defines the mapping between field-based ACF field names and standard
     * field names for stacked blocks.
     *
     * @return array<int|string, mixed> Array of field name mappings
     */
    public function getFieldMappings(): array
    {
        return [
            'field_stacked_heading_b' => 'stacked_heading',
            'field_stacked_hide_heading_b' => 'stacked_hide_heading',
            'field_stacked_description_b' => 'stacked_description',
            'field_stacked_background_color_b' => 'stacked_background_color',
            'field_stacked_more_b' => 'stacked_more',
            'field_stacked_more_text_b' => 'stacked_more_text',
            'field_stacked_more_button_text_b' => 'stacked_more_button_text',
            'field_stacked_more_button_url_b' => 'stacked_more_button_url',
            'field_stacked_more_button_description_b' => 'stacked_more_button_description',
        ];
    }

    /**
     * Gets the nested structure keys for stacked blocks.
     *
     * Defines which nested structures should be processed for stacked blocks.
     *
     * @return array<int|string, mixed> Array of nested structure keys
     */
    public function getNestedStructures(): array
    {
        return [];
    }

    /**
     * Checks if all items in a stacked block have no images.
     *
     * Examines the normalized data to determine if all stacked_section items
     * have empty image fields. Used to determine if a text-stacked block should
     * be converted to a link-list block.
     *
     * @param  array<int|string, mixed>  $normalizedData  The normalized block attributes
     * @return bool True if all items have no images, false otherwise
     */
    public static function allItemsHaveNoImages(
        array $normalizedData
    ): bool {
        // Get section count from normalized data
        $sectionCount = self::getIntegerFieldValue(
            $normalizedData,
            'stacked_section'
        );

        // ---
        // If there are no sections, return false (can't convert empty block)
        // ---

        // Check if there are no sections
        if ($sectionCount === 0) {
            // Cannot convert empty block
            return false;
        }

        // ---
        // Check each section for images
        // ---

        // Loop through each section to check for images
        for ($i = 0; $i < $sectionCount; $i++) {
            // Get image field value for current section
            $imageField = $normalizedData["stacked_section_{$i}_stacked_section_image"] ?? '';

            // Check if image field is not empty
            if ($imageField !== '') {
                // Found at least one item with an image
                return false;
            }
        }

        // All items have no images
        return true;
    }

    /**
     * Checks if all items in a stacked block have URLs.
     *
     * Examines the normalized data to determine if all stacked_section items
     * have non-empty button_url fields. Used to determine if a text-stacked
     * block should be converted to a link-list block.
     *
     * @param  array<int|string, mixed>  $normalizedData  The normalized block attributes
     * @return bool True if all items have URLs, false otherwise
     */
    public static function allItemsHaveUrls(
        array $normalizedData
    ): bool {
        // Get section count from normalized data
        $sectionCount = self::getIntegerFieldValue(
            $normalizedData,
            'stacked_section'
        );

        // ---
        // If there are no sections, return false (can't convert empty block)
        // ---

        // Check if there are no sections
        if ($sectionCount === 0) {
            // Cannot convert empty block
            return false;
        }

        // ---
        // Check each section for URLs
        // ---

        // Loop through each section to check for URLs
        for ($i = 0; $i < $sectionCount; $i++) {
            // Get button URL field value for current section
            $buttonUrlField = $normalizedData["stacked_section_{$i}_stacked_section_button_url"] ?? '';

            // Extract URL from button URL field using LinkHelper (handles array or string)
            $url = LinkHelper::getUrl($buttonUrlField);

            // Check if URL is empty
            if ($url === '') {
                // Found at least one item without a URL
                return false;
            }
        }

        // All items have URLs
        return true;
    }

    /**
     * Converts stacked block attributes to uams-section block format.
     *
     * Maps stacked block field names to uams-section block format, creating
     * InnerBlocks HTML content from the stacked sections. Used when a
     * text-stacked block has no images and no URLs, and should be treated as
     * a uams-section block.
     *
     * @param  array<int|string, mixed>  $attributes  The original stacked block attributes
     * @return array{attributes: array<int|string, mixed>, innerContent: list<array{html: string}>} The uams-section block attributes and the HTML fragments for its InnerBlocks
     */
    public static function convertToSectionFormat(
        array $attributes
    ): array {
        // Handle attributes with or without 'data' key
        $data = self::normalizeAttributesData(
            $attributes
        );

        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            (new self)->getFieldMappings(),
            (new self)->getNestedStructures()
        );

        // Initialize converted attributes
        $convertedAttributes = [];

        // ---
        // Map basic fields
        // ---

        // Check if heading exists and map to section_heading
        if (isset($normalizedData['stacked_heading'])) {
            // Map stacked heading to section heading
            $convertedAttributes['section_heading'] = $normalizedData['stacked_heading'];
        }

        // Check if hide heading exists and map to section_hide_heading
        if (isset($normalizedData['stacked_hide_heading'])) {
            // Map stacked hide heading to section hide heading
            $convertedAttributes['section_hide_heading'] = $normalizedData['stacked_hide_heading'];
        }

        // Check if background color exists and map to section_background_color
        if (isset($normalizedData['stacked_background_color'])) {
            // Map stacked background color to section background color
            $convertedAttributes['section_background_color'] = $normalizedData['stacked_background_color'];
        }

        // ---
        // Build InnerBlocks HTML content from stacked sections
        // ---

        // Initialize array to store HTML fragments
        $innerContent = [];

        // Get section count
        $sectionCount = self::getIntegerFieldValue(
            $normalizedData,
            'stacked_section'
        );

        // Loop through each section to create HTML content
        for ($i = 0; $i < $sectionCount; $i++) {
            // Get heading for current section
            $heading = HtmlTagStripper::strip(
                self::getStringFieldValue(
                    $normalizedData,
                    "stacked_section_{$i}_stacked_section_heading"
                )
            );

            // Get body for current section
            $body = self::getStringFieldValue(
                $normalizedData,
                "stacked_section_{$i}_stacked_section_body"
            );

            // ---
            // Create heading HTML fragment if heading exists
            // ---

            // Check if heading exists
            if ($heading !== '') {
                // Escape heading for HTML
                $escapedHeading = \htmlspecialchars(
                    $heading,
                    ENT_QUOTES,
                    'UTF-8'
                );

                // Create heading HTML fragment
                $innerContent[] = [
                    'html' => "<!-- wp:heading {\"level\":3} -->\n<h3 class=\"wp-block-heading\">{$escapedHeading}</h3>\n<!-- /wp:heading -->\n",
                ];
            }

            // ---
            // Create paragraph HTML fragment if body exists
            // ---

            // Check if body exists
            if ($body !== '') {
                // Create paragraph HTML fragment (TextProcessor will handle HTML processing)
                $innerContent[] = [
                    'html' => "<!-- wp:paragraph -->\n<p>{$body}</p>\n<!-- /wp:paragraph -->\n",
                ];
            }
        }

        // ---
        // Preserve original structure if attributes had 'data' key
        // ---

        // Check if original attributes had 'data' key
        if (isset($attributes['data'])) {
            // Return converted data wrapped in 'data' key
            return [
                'attributes' => ['data' => $convertedAttributes],
                'innerContent' => $innerContent,
            ];
        }

        // Return converted attributes directly
        return [
            'attributes' => $convertedAttributes,
            'innerContent' => $innerContent,
        ];
    }

    /**
     * Converts stacked block attributes to link-list block attributes.
     *
     * Maps stacked block field names to link-list block field names according
     * to the specified conversion rules. Used when a text-stacked block has no
     * images and should be treated as a link-list block.
     *
     * @param  array<int|string, mixed>  $attributes  The original stacked block attributes
     * @return array<int|string, mixed> The converted link-list block attributes
     */
    public static function convertToLinkListAttributes(
        array $attributes
    ): array {
        // Handle attributes with or without 'data' key
        $data = self::normalizeAttributesData(
            $attributes
        );

        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            (new self)->getFieldMappings(),
            (new self)->getNestedStructures()
        );

        // Initialize converted attributes
        $converted = [];

        // ---
        // Map basic fields
        // ---

        // Check if heading exists and map to link_list_heading
        if (isset($normalizedData['stacked_heading'])) {
            // Map stacked heading to link list heading
            $converted['link_list_heading'] = $normalizedData['stacked_heading'];
        }

        // Check if description exists and map to link_list_description
        if (isset($normalizedData['stacked_description'])) {
            // Map stacked description to link list description
            $converted['link_list_description'] = $normalizedData['stacked_description'];
        }

        // Check if background color exists and map to link_list_background_color
        if (isset($normalizedData['stacked_background_color'])) {
            // Map stacked background color to link list background color
            $converted['link_list_background_color'] = $normalizedData['stacked_background_color'];
        }

        // Map section fields
        $sectionCount = self::getIntegerFieldValue(
            $normalizedData,
            'stacked_section'
        );

        // Check if there are sections to process
        if ($sectionCount > 0) {
            // Set link list section count
            $converted['link_list_section'] = $sectionCount;

            // Loop through each section to map fields
            for ($i = 0; $i < $sectionCount; $i++) {
                // ---
                // Map heading to title
                // ---

                // Check if heading exists and map to link_list_section_title
                if (isset($normalizedData["stacked_section_{$i}_stacked_section_heading"])) {
                    // Map stacked section heading to link list section title
                    $converted["link_list_section_{$i}_link_list_section_title"] =
                        $normalizedData["stacked_section_{$i}_stacked_section_heading"];
                }

                // ---
                // Map body (same field name structure)
                // ---

                // Check if body exists and map to link_list_section_body
                if (isset($normalizedData["stacked_section_{$i}_stacked_section_body"])) {
                    // Map stacked section body to link list section body
                    $converted["link_list_section_{$i}_link_list_section_body"] =
                        $normalizedData["stacked_section_{$i}_stacked_section_body"];
                }

                // ---
                // Map button_url to url
                // ---

                // Check if button URL exists and map to link_list_section_url
                if (isset($normalizedData["stacked_section_{$i}_stacked_section_button_url"])) {
                    // Map stacked section button URL to link list section URL
                    $converted["link_list_section_{$i}_link_list_section_url"] =
                        $normalizedData["stacked_section_{$i}_stacked_section_button_url"];
                }

                // ---
                // Map button_description to description
                // ---

                // Check if button description exists and map to link_list_section_description
                if (isset($normalizedData["stacked_section_{$i}_stacked_section_button_description"])) {
                    // Map stacked section button description to link list section description
                    $converted["link_list_section_{$i}_link_list_section_description"] =
                        $normalizedData["stacked_section_{$i}_stacked_section_button_description"];
                }
            }
        }

        // ---
        // Preserve original structure if attributes had 'data' key
        // ---

        // Check if original attributes had 'data' key
        if (isset($attributes['data'])) {
            // Return converted data wrapped in 'data' key
            return ['data' => $converted];
        }

        // Return converted attributes directly
        return $converted;
    }

    /**
     * Normalizes the stacked_section repeater data from indexed keys to an array of
     * sections.
     *
     * Transforms the flat key-value array
     * (e.g., stacked_section_0_stacked_section_heading) into an array of section
     * arrays, grouping fields by their index.
     *
     * @param  array<int|string, mixed>  $data  The raw attributes array containing stacked_section fields
     * @return array<int, array{
     *   stacked_section_heading: string,
     *   stacked_section_body: string,
     *   stacked_section_image: string,
     *   stacked_section_alt_override: string,
     *   stacked_section_button_text: string,
     *   stacked_section_button_url: mixed,
     *   stacked_section_button_description: string
     * }> Array of section arrays, each containing fields for one item
     */
    private function normalizeSections(
        array $data
    ): array {
        $sections = [];
        $sectionCount = self::getIntegerFieldValue(
            $data,
            'stacked_section'
        );

        for ($i = 0; $i < $sectionCount; $i++) {
            $section = [
                'stacked_section_heading' => self::getStringFieldValue(
                    $data,
                    "stacked_section_{$i}_stacked_section_heading"
                ),
                'stacked_section_body' => self::getStringFieldValue(
                    $data,
                    "stacked_section_{$i}_stacked_section_body"
                ),
                'stacked_section_image' => self::getStringFieldValue(
                    $data,
                    "stacked_section_{$i}_stacked_section_image"
                ),
                'stacked_section_alt_override' => self::getStringFieldValue(
                    $data,
                    "stacked_section_{$i}_stacked_section_alt_override"
                ),
                'stacked_section_button_text' => self::getStringFieldValue(
                    $data,
                    "stacked_section_{$i}_stacked_section_button_text"
                ),
                'stacked_section_button_url' => $data["stacked_section_{$i}_stacked_section_button_url"] ?? [],
                'stacked_section_button_description' => self::getStringFieldValue(
                    $data,
                    "stacked_section_{$i}_stacked_section_button_description"
                ),
            ];
            $sections[] = $section;
        }

        return $sections;
    }

    /**
     * Processes the stacked_section repeater from WordPress to Statamic format.
     *
     * Converts the stacked_section repeater into a Statamic items replicator,
     * mapping heading, body, image, and button fields. Ensures URLs are valid
     * and handles the target attribute for new window behavior.
     *
     * @param  array<int, array{
     *   stacked_section_heading: string,
     *   stacked_section_body: string,
     *   stacked_section_image: string,
     *   stacked_section_alt_override: string,
     *   stacked_section_button_text: string,
     *   stacked_section_button_url: mixed,
     *   stacked_section_button_description: string
     * }>  $sections  The stacked_section repeater data
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
            // Extract and process the heading for the stacked section item
            $heading = HtmlTagStripper::strip(
                $section['stacked_section_heading']
            );

            // Track if heading was originally empty
            $isHeadingEmpty = $heading === '';

            // Add placeholder if heading is missing
            if ($isHeadingEmpty) {
                $heading = 'Placeholder Item Heading — Please Replace';
            }

            // Process body text
            $body = HtmlTagStripper::strip(
                $section['stacked_section_body']
            );

            // Track if body was originally empty
            $isBodyEmpty = $body === '';

            // Add placeholder if body is missing
            if ($isBodyEmpty) {
                $body = 'Placeholder Item Body — Please Replace';
            }

            // Import image
            $image = null;

            // Check if stacked section image is provided
            if (
                $section['stacked_section_image'] !== ''
            ) {
                // Import image with disabled upload handling
                $image = ImageProcessor::importImageWithDisabledUploadHandling(
                    $section['stacked_section_image'],
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    'stacked image',
                    'StackedParser::processItems'
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
                            'image_id' => $section['stacked_section_image'],
                        ]
                    );

                    // Log warning when uploads are enabled but no image is available
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'StackedParser::processItems: Failed to import stacked section image when uploads are enabled',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );

                    // When uploads are disabled, missing images are expected.
                    // Continue processing without the image.
                }
            }

            // Process button fields, which an item carries only when the button has text
            $buttonText = $section['stacked_section_button_text'];
            $button = [];
            if ($buttonText !== '') {
                $originalUrl = LinkHelper::getUrl($section['stacked_section_button_url']);

                // Process URL through LinkProcessor to handle internal links and file imports
                $button = [
                    'button_text' => $buttonText,
                    'button_url' => LinkProcessor::processUrlString(
                        $originalUrl,
                        $site,
                        $assetImporter,
                        $output,
                        ProcessingContext::getStatamicEntryId(),
                        ProcessingContext::getCollectionHandle()
                    ),
                    'button_target' => LinkHelper::getTarget($section['stacked_section_button_url']),
                ];

                // Include the button description only if non-empty
                $buttonDescription = $section['stacked_section_button_description'];
                if ($buttonDescription !== '') {
                    $button['button_link_description'] = $buttonDescription;
                }
            }

            // Skip invalid items (missing required fields)
            if (
                (
                    $isHeadingEmpty
                    && $isBodyEmpty
                )
                || (
                    $assetImporter->getUploadImages() // Missing images are only problematic when uploads are enabled
                    && ($image === null || $image === '')
                )
            ) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'heading' => $heading,
                        'has_body' => ! $isBodyEmpty,
                        'has_image' => ($image !== null && $image !== ''),
                    ]
                );

                // Log warning for invalid stacked section item
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'StackedParser::processItems: Skipping invalid stacked_section item',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Skip to next item in the loop
                continue;

                // When uploads are disabled, missing images are expected.
                // Continue processing without the image if other required fields are present.
            }

            $item = [
                'id' => (new RowId)->generate(), // Unique ID for replicator set
                'type' => 'new_set', // Statamic replicator set type
                'heading' => $heading, // Required, 78-char limit
                'body' => $body, // Required, 179-char limit
                'image' => $image, // Required
                'enabled' => true, // Standard for replicator sets
            ];

            // Include optional fields if non-empty
            $altOverride = $section['stacked_section_alt_override'];
            if ($altOverride !== '') {
                $item['alt_override'] = $altOverride;
            }

            // Add the button fields after the alt override
            $result[] = $item + $button;
        }

        return $result;
    }

    /**
     * Parses a WordPress ACF text-stacked block into a Statamic page_builder
     * replicator set.
     *
     * Maps block attributes to a uamsweb_block_stacked set, including heading,
     * description, items, background color, and footer link fields. Processes
     * textareas as plain text, imports images via AssetImporter, and omits empty
     * optional fields.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/text-stacked comment
     * @param  string  $site  The Statamic site handle (e.g., 'default') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML
     * @return array<int|string, mixed> The Statamic uamsweb_block_stacked replicator set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = []
    ): array {
        // Handle attributes with or without 'data' key
        $data = self::normalizeAttributesData(
            $attributes
        );

        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Map basic fields (now simplified with normalized data)
        $heading = HtmlTagStripper::strip(
            self::getStringFieldValue(
                $normalizedData,
                'stacked_heading'
            )
        );
        $hideHeading = self::getStringFieldValue(
            $normalizedData,
            'stacked_hide_heading',
            '0'
        ) === '1';
        $description = HtmlTagStripper::strip(
            self::getStringFieldValue(
                $normalizedData,
                'stacked_description'
            )
        );
        $backgroundColor = self::getStringFieldValue(
            $normalizedData,
            'stacked_background_color',
            'bg-auto'
        );
        $moreQuery = self::getStringFieldValue(
            $normalizedData,
            'stacked_more',
            '0'
        ) === '1';

        // Normalize and process items repeater
        $items = [];
        $sections = $this->normalizeSections($normalizedData);
        if ($sections !== []) {
            $items = $this->processItems(
                $sections,
                $site,
                $assetImporter,
                $output,
                $attachments
            );
        }

        // Process footer link fields, which the set carries only while the footer link is on
        $more = [];
        if ($moreQuery) {
            // Include the footer heading only if non-empty
            $moreHeading = self::getStringFieldValue(
                $normalizedData,
                'stacked_more_text'
            );
            if ($moreHeading !== '') {
                $more['more_heading'] = $moreHeading;
            }

            $urlData = self::getMixedFieldValue(
                $normalizedData,
                'stacked_more_button_url'
            );
            $originalMoreButtonUrl = LinkHelper::getUrl(
                $urlData
            );

            // Process URL through LinkProcessor to handle internal links and file imports
            $moreButtonUrl = LinkProcessor::processUrlString(
                $originalMoreButtonUrl,
                $site,
                $assetImporter,
                $output,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );

            // Include the footer button only if it has text
            $moreButtonText = self::getStringFieldValue(
                $normalizedData,
                'stacked_more_button_text'
            );
            if ($moreButtonText !== '') {
                $more['more_button_text'] = $moreButtonText;
                $more['more_button_url'] = $moreButtonUrl;
                $more['more_button_target'] = \is_array($urlData) && isset($urlData['target']) && $urlData['target'] === '_blank';

                // Include the footer button description only if non-empty
                $moreButtonDescription = HtmlTagStripper::strip(
                    self::getStringFieldValue(
                        $normalizedData,
                        'stacked_more_button_description'
                    )
                );
                if ($moreButtonDescription !== '') {
                    $more['more_button_link_description'] = $moreButtonDescription;
                }
            }
        }

        // Build the replicator set, omitting empty fields
        $result = [
            'id' => (new RowId)->generate(), // Unique ID for replicator set
            'type' => 'uamsweb_block_stacked', // Statamic fieldset handle
            'block_heading' => $heading, // Required text
            'hide_block_heading' => $hideHeading, // Required TrueFalse
            'background_color' => $backgroundColor, // Required select
            'more_query' => $moreQuery, // Required TrueFalse
            'enabled' => true, // Standard for replicator sets
        ];

        // Include non-empty fields conditionally
        if ($description !== '') {
            $result['block_description'] = $description; // Optional
        }

        if ($items !== []) {
            $result['items'] = $items; // Required, include only if non-empty
        }

        // Add the footer link fields after the items
        $result += $more;

        return $result;
    }

    /**
     * Parses WordPress modules template text_stacked data into Statamic page_builder
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
        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $moduleData,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Map basic fields from modules template format (now simplified with normalized data)
        $heading = HtmlTagStripper::strip(
            self::getStringFieldValue(
                $normalizedData,
                'stacked_heading'
            )
        );
        $hideHeading = self::getStringFieldValue(
            $normalizedData,
            'stacked_hide_heading',
            '0'
        ) === '1';
        $description = HtmlTagStripper::strip(
            self::getStringFieldValue(
                $normalizedData,
                'stacked_description'
            )
        );
        $backgroundColor = self::getStringFieldValue(
            $normalizedData,
            'stacked_background_color',
            'bg-auto'
        );
        $moreQuery = self::getStringFieldValue(
            $normalizedData,
            'stacked_more',
            '0'
        ) === '1';

        // Validate required heading
        if ($heading === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['site' => $site]
            );

            // Log warning about missing required heading
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'StackedParser::parseModule: Skipping modules template text_stacked due to missing required heading',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // Process sections
        $sections = [];
        $sectionCount = self::getIntegerFieldValue(
            $normalizedData,
            'stacked_section'
        );

        for ($i = 0; $i < $sectionCount; $i++) {
            $section = [
                'stacked_section_heading' => HtmlTagStripper::strip(
                    self::getStringFieldValue(
                        $normalizedData,
                        "stacked_section_{$i}_stacked_section_heading"
                    )
                ),
                'stacked_section_body' => self::getStringFieldValue(
                    $normalizedData,
                    "stacked_section_{$i}_stacked_section_body"
                ),
                'stacked_section_image' => self::getStringFieldValue(
                    $normalizedData,
                    "stacked_section_{$i}_stacked_section_image"
                ),
                'stacked_section_alt_override' => self::getStringFieldValue(
                    $normalizedData,
                    "stacked_section_{$i}_stacked_section_alt_override"
                ),
                'stacked_section_button_text' => self::getStringFieldValue(
                    $normalizedData,
                    "stacked_section_{$i}_stacked_section_button_text"
                ),
                'stacked_section_button_url' => self::getMixedFieldValue(
                    $normalizedData,
                    "stacked_section_{$i}_stacked_section_button_url"
                ),
                'stacked_section_button_description' => self::getStringFieldValue(
                    $normalizedData,
                    "stacked_section_{$i}_stacked_section_button_description"
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
                    'StackedParser::parseModule: No valid items for modules template text_stacked; skipping',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // Process footer link fields
        $moreHeading = HtmlTagStripper::strip(
            self::getStringFieldValue(
                $normalizedData,
                'stacked_more_text'
            )
        );
        $moreButtonText = HtmlTagStripper::strip(
            self::getStringFieldValue(
                $normalizedData,
                'stacked_more_button_text'
            )
        );
        $moreButtonUrlData = self::getMixedFieldValue(
            $normalizedData,
            'stacked_more_button_url'
        );
        $originalMoreButtonUrl = LinkHelper::getUrl(
            $moreButtonUrlData
        );

        // Process URL through LinkProcessor to handle internal links and file imports
        $moreButtonUrl = LinkProcessor::processUrlString(
            $originalMoreButtonUrl,
            $site,
            $assetImporter,
            $output,
            ProcessingContext::getStatamicEntryId(),
            ProcessingContext::getCollectionHandle()
        );
        $moreButtonTarget = LinkHelper::getTarget(
            $moreButtonUrlData
        );
        $moreButtonDescription = HtmlTagStripper::strip(
            self::getStringFieldValue(
                $normalizedData,
                'stacked_more_button_description'
            )
        );

        // Build the replicator set
        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_stacked',
            'block_heading' => $heading,
            'hide_block_heading' => $hideHeading,
            'background_color' => $backgroundColor,
            'more_query' => $moreQuery,
            'enabled' => true,
        ];

        // Include non-empty fields conditionally
        if ($description !== '') {
            $result['block_description'] = $description;
        }

        // Items are guaranteed non-empty here (method returns early otherwise)
        $result['items'] = $items;
        if ($moreQuery) {
            if ($moreHeading !== '') {
                $result['more_heading'] = $moreHeading;
            }

            if ($moreButtonText !== '') {
                $result['more_button_text'] = $moreButtonText;
                $result['more_button_url'] = $moreButtonUrl;
                $result['more_button_target'] = $moreButtonTarget;
                if ($moreButtonDescription !== '') {
                    $result['more_button_link_description'] = $moreButtonDescription;
                }
            }
        }

        return $result;
    }

    /**
     * Normalizes stacked block attributes for ACF field normalization.
     *
     * @param  array<int|string, mixed>  $attributes  The incoming block attributes
     * @return array<int|string, mixed> Safe attribute data array
     */
    private static function normalizeAttributesData(
        array $attributes
    ): array {
        $data = $attributes['data'] ?? $attributes;

        return \is_array($data) ? $data : [];
    }

    /**
     * Returns a string value from a field in the provided array.
     *
     * @param  array<int|string, mixed>  $data  The source data array
     * @param  string  $key  The field key
     * @param  string  $default  The default value when conversion is not possible
     * @return string The normalized string value
     */
    private static function getStringFieldValue(
        array $data,
        string $key,
        string $default = ''
    ): string {
        return self::normalizeStringValue(
            $data[$key] ?? $default,
            $default
        );
    }

    /**
     * Returns a mixed field value from the provided array.
     *
     * @param  array<int|string, mixed>  $data  The source data array
     * @param  string  $key  The field key
     * @return mixed The source value or null when the key is missing
     */
    private static function getMixedFieldValue(
        array $data,
        string $key
    ): mixed {
        return $data[$key] ?? null;
    }

    /**
     * Returns an integer field value from the provided array.
     *
     * @param  array<int|string, mixed>  $data  The source data array
     * @param  string  $key  The field key
     * @return int The normalized integer value
     */
    private static function getIntegerFieldValue(
        array $data,
        string $key
    ): int {
        return self::normalizeIntegerValue(
            $data[$key] ?? 0
        );
    }

    /**
     * Normalizes mixed values to strings for field parsing.
     *
     * @param  mixed  $value  The source value
     * @param  string  $default  The default fallback value
     * @return string The normalized string value
     */
    private static function normalizeStringValue(
        mixed $value,
        string $default = ''
    ): string {
        if (\is_string($value)) {
            return $value;
        }

        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }

        return $default;
    }

    /**
     * Normalizes mixed values to integers for section counts.
     *
     * @param  mixed  $value  The source value
     * @return int The normalized integer value
     */
    private static function normalizeIntegerValue(
        mixed $value
    ): int {
        if (\is_int($value)) {
            return $value;
        }

        if (\is_string($value) && \is_numeric($value)) {
            return (int) $value;
        }

        return 0;
    }
}
