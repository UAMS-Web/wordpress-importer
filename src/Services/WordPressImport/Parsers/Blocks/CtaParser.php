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
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;

/**
 * Parses WordPress wp:acf/cta blocks into Statamic page_builder CTA sets.
 *
 * Converts the ACF CTA Bar block attributes (e.g., cta_bar_heading, cta_bar_body)
 * into a Statamic uamsweb_block_cta replicator set for the landing_page
 * blueprint's page_builder field. Processes the WYSIWYG cta_bar_body field with
 * wpautop formatting, shortcode preprocessing, and link preprocessing before
 * parsing into Bard format using Tiptap's setContent. Handles action types (URL,
 * phone, none), layout, size, background, and optional images. Uses
 * AcfFieldNormalizer for consistent field mapping across different WordPress ACF
 * data structures.
 */
class CtaParser
{
    /**
     * Instance of BardParser for processing WYSIWYG content into Bard format.
     */
    private BardParser $bardParser;

    /**
     * Instance of LinkProcessor for preprocessing links in HTML content.
     */
    private LinkProcessor $linkProcessor;

    /**
     * Instance of WpAutoP for applying WordPress-style paragraph formatting.
     */
    private WpAutoP $wpAutoP;

    /**
     * Instance of ShortcodeProcessor for handling shortcodes in WYSIWYG content.
     */
    private ShortcodeProcessor $shortcodeProcessor;

    /**
     * Constructs a new CtaParser instance with its dependencies.
     *
     * Initializes the parser with instances of BardParser, LinkProcessor, WpAutoP,
     * and ShortcodeProcessor. If not provided, new instances are created to ensure
     * default functionality.
     *
     * @param  ?BardParser  $bardParser  Optional Bard parser instance for Tiptap parsing
     * @param  ?LinkProcessor  $linkProcessor  Optional link processor for file imports
     * @param  ?WpAutoP  $wpAutoP  Optional WpAutoP instance for wpautop formatting
     * @param  ?ShortcodeProcessor  $shortcodeProcessor  Optional shortcode processor instance
     */
    public function __construct(
        ?BardParser $bardParser = null,
        ?LinkProcessor $linkProcessor = null,
        ?WpAutoP $wpAutoP = null,
        ?ShortcodeProcessor $shortcodeProcessor = null
    ) {
        $this->bardParser = $bardParser ?? new BardParser;
        $this->linkProcessor = $linkProcessor ?? new LinkProcessor;
        $this->wpAutoP = $wpAutoP ?? new WpAutoP;
        $this->shortcodeProcessor = $shortcodeProcessor ?? new ShortcodeProcessor;
    }

    /**
     * Defines the mapping between field-based ACF field names and standard field
     * names.
     *
     * This method provides the field mappings needed by AcfFieldNormalizer to handle
     * the conversion of WordPress ACF field names like field_cta_bar_heading_b to
     * standard names like cta_bar_heading.
     *
     * @return array<int|string, mixed> The field mappings for CTA blocks
     */
    private function getFieldMappings(): array
    {
        return [
            'field_cta_bar_heading_b' => 'cta_bar_heading',
            'field_cta_bar_body_b' => 'cta_bar_body',
            'field_cta_bar_action_type_b' => 'cta_bar_action_type',
            'field_cta_bar_button_text_b' => 'cta_bar_button_text',
            'field_cta_bar_button_url_b' => 'cta_bar_button_url',
            'field_cta_bar_button_description_b' => 'cta_bar_button_description',
            'field_cta_bar_phone_prepend_b' => 'cta_bar_phone_prepend',
            'field_cta_bar_phone_b' => 'cta_bar_phone',
            'field_cta_bar_layout_b' => 'cta_bar_layout',
            'field_cta_bar_size_b' => 'cta_bar_size',
            'field_cta_bar_background_color_b' => 'cta_bar_background_color',
            'field_cta_bar_use_image_b' => 'cta_bar_use_image',
            'field_cta_bar_image_b' => 'cta_bar_image',
            'field_cta_geo_valid_b' => 'cta_geo_valid',
            'field_cta_bar_geo_b' => 'cta_bar_geo',
            'field_cta_bar_geo_region_b' => 'cta_bar_geo_region',
        ];
    }

    /**
     * Defines which nested structures should be processed for CTA blocks.
     *
     * This method provides the nested structure mappings needed by AcfFieldNormalizer
     * to handle complex ACF block structures.
     *
     * @return array<int|string, mixed> The nested structure mappings for CTA blocks
     */
    private function getNestedStructures(): array
    {
        return [
            // No nested structures for CTA blocks
        ];
    }

    /**
     * Parses a WordPress ACF cta block into a Statamic page_builder CTA set.
     *
     * Maps attributes to a uamsweb_block_cta set, including heading, body, action
     * type, layout, size, and background. Processes cta_bar_body WYSIWYG field by
     * extracting shortcodes, converting them to Tiptap HTML with site context,
     * applying wpautop formatting, preprocessing file links, and parsing into Bard
     * format. Supports URL, phone, or no-action configurations with optional images.
     * Uses AcfFieldNormalizer for consistent field mapping across different WordPress
     * ACF data structures.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/cta comment
     *                                                (e.g., ['data' => ['cta_bar_heading' => '...']])
     * @param  string  $site  The Statamic site handle for asset paths and shortcode processing
     * @param  AssetImporter  $assetImporter  Instance for importing images and files
     * @param  OutputInterface  $output  Console output for logging import progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from the WordPress XML
     * @param  array<int|string, mixed>  $terms  Array of terms for shortcode processing
     * @return array<int|string, mixed> The Statamic uamsweb_block_cta replicator set with Bard body content
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = []
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

        // ---
        // Check if placeholder body should be set
        // ---

        // Get the body value from normalized data
        $bodyValue = $this->stringFromValue($normalizedData['cta_bar_body'] ?? '');

        // ---
        // Check if placeholder body should be set when heading has value, body is
        // empty, and action type scenarios are met
        // ---
        if (
            $this->hasValue($normalizedData['cta_bar_heading'] ?? '')
            && ! $this->hasValue($bodyValue)
            && $this->shouldSetPlaceholderBody($normalizedData)
        ) {
            // Set placeholder body value
            $bodyValue = '<p>Placeholder Body — Please Replace</p>';
        }

        // Map basic fields using normalized data
        $heading = HtmlTagStripper::strip(
            $this->stringFromValue($normalizedData['cta_bar_heading'] ?? '')
        );
        $bodyContent = $this->processBody(
            $bodyValue,
            $site,
            $assetImporter,
            $output,
            $terms
        );

        // Action type handling
        $actionType = $normalizedData['cta_bar_action_type'] ?? 'url';
        $validActionTypes = ['url', 'phone', 'none'];
        $actionType = \in_array(
            $actionType,
            $validActionTypes,
            true
        ) ? $actionType : 'none';

        // Layout, size, and background
        $layout = $normalizedData['cta_bar_layout'] ?? 'cta-bar-centered';
        $size = $normalizedData['cta_bar_size'] ?? 'normal';
        $backgroundColor = $normalizedData['cta_bar_background_color'] ?? 'bg-auto';

        // Background image
        $useImage = (bool) ($normalizedData['cta_bar_use_image'] ?? '0');
        $backgroundImage = null;

        // Check if CTA background image should be used
        $ctaBarImage = $this->stringFromValue($normalizedData['cta_bar_image'] ?? '');
        if (
            $useImage
            && $ctaBarImage !== ''
        ) {
            // Import image with disabled upload handling
            $backgroundImage = ImageProcessor::importImageWithDisabledUploadHandling(
                $ctaBarImage,
                $site,
                $assetImporter,
                $output,
                $attachments,
                'CTA background image',
                'CtaParser::parse'
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
                        'image_id' => $ctaBarImage,
                    ]
                );

                // Log warning when uploads are enabled but no image is available
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'CtaParser::parse: Failed to import CTA background image when uploads are enabled',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // When uploads are disabled, missing images are expected.
                // Continue processing without the image.
            }
        }

        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_cta',
            'block_heading' => $heading,
            'hide_block_heading' => false,
            'body' => $bodyContent,
            'action_type' => $actionType,
            'layout' => $layout,
            'size' => $size,
            'background_color' => $backgroundColor,
            'background_image_query' => $useImage,
            'enabled' => true,
            'sms_phone_type_group' => [
                'type' => null,
                'phone' => null,
                'sms_short_code' => null,
            ],
        ];

        // URL action fields (only include if action_type is 'url')
        if ($actionType === 'url') {
            $result['button_text'] = HtmlTagStripper::strip(
                $this->stringFromValue($normalizedData['cta_bar_button_text'] ?? '')
            );
            $originalUrl = LinkHelper::getUrl(
                $normalizedData['cta_bar_button_url'] ?? '',
                ''
            );

            // Process URL through LinkProcessor to handle internal links and file imports
            $result['button_url'] = LinkProcessor::processUrlString(
                $originalUrl,
                $site,
                $assetImporter,
                $output,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );
            $result['button_target'] = LinkHelper::getTarget($normalizedData['cta_bar_button_url'] ?? '');
            $result['button_link_description'] = $normalizedData['cta_bar_button_description'] ?? '';
        }

        // Phone action fields (only include if action_type is 'phone')
        if ($actionType === 'phone') {
            $result['phone_prepend'] = HtmlTagStripper::strip(
                $this->stringFromValue(
                    $normalizedData['cta_bar_phone_prepend'] ?? 'Call'
                )
            );
            $result['phone'] = $normalizedData['cta_bar_phone'] ?? '';
        }

        // Only include background_image if it has a value, as a string (not an array)
        if ($backgroundImage !== null && $backgroundImage !== '') {
            $result['background_image'] = $backgroundImage;
        }

        return $result;
    }

    /**
     * Processes WYSIWYG body content into Bard format.
     *
     * Preprocesses shortcodes with site context, applies wpautop formatting to ensure
     * proper paragraph structure, preprocesses links for file imports, and parses
     * into Bard-compatible ProseMirror JSON using Tiptap's setContent.
     *
     * @param  string  $bodyContent  The raw WYSIWYG content from cta_bar_body
     * @param  string  $site  The Statamic site handle for asset paths and shortcode processing
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of terms for shortcode processing
     * @return array<int|string, mixed> The parsed Bard content as ProseMirror JSON
     */
    private function processBody(
        string $bodyContent,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = []
    ): array {
        if ($bodyContent === '') {
            return [];
        }

        // Preprocess shortcodes
        $shortcodes = $this->shortcodeProcessor->extract(
            $bodyContent,
            $output
        );
        $formattedContent = $bodyContent;
        foreach ($shortcodes as $shortcode) {
            // Skip malformed shortcode values and only process array payloads.
            if (! \is_array($shortcode)) {
                continue;
            }

            // Get the original shortcode string used as the replacement target.
            $originalShortcode = $shortcode['original'] ?? null;
            if (! \is_string($originalShortcode) || $originalShortcode === '') {
                continue;
            }

            $formattedContent = \str_replace(
                $originalShortcode,
                $this->shortcodeProcessor->toTiptapHtml(
                    $shortcode,
                    $site,
                    $assetImporter,
                    $output,
                    [], // No attachments available in this context
                    $terms
                ),
                $formattedContent
            );
        }

        // Apply wpautop formatting to mimic WordPress WYSIWYG
        $formattedContent = $this->wpAutoP->process($formattedContent);

        // Preprocess links for file imports
        $processedContent = $this->linkProcessor->process(
            $formattedContent,
            $site,
            $assetImporter,
            $output,
            ProcessingContext::getStatamicEntryId(),
            ProcessingContext::getCollectionHandle(),
            ProcessingContext::getXmlPath()
        );

        // Parse with Tiptap into Bard format
        return $this->bardParser->parse($processedContent);
    }

    /**
     * Checks if a value has content (is not empty after trimming).
     *
     * Handles strings, arrays, and other types. For arrays, checks if the array is
     * not empty. For strings, checks if trimmed value is not empty.
     *
     * @param  mixed  $value  The value to check
     * @return bool True if the value has content, false otherwise
     */
    private function hasValue(
        mixed $value
    ): bool {
        // Check if value is an array
        if (\is_array($value)) {
            // Return whether array is not empty
            return $value !== [];
        }

        // Check if value is a string
        if (\is_string($value)) {
            // Return whether trimmed string is not empty
            return \trim($value) !== '';
        }

        // Check if value is a scalar
        if (\is_scalar($value)) {
            // Return whether scalar converted to string has content
            return \trim((string) $value) !== '';
        }

        // Return false for unsupported types
        return false;
    }

    /**
     * Determines if placeholder body should be set based on action type scenarios.
     *
     * Checks if one of three scenarios is met:
     *   - Scenario 1: action_type is empty, button_text has value, button_url has
     *     value
     *   - Scenario 2: action_type is 'url', button_text has value, button_url has
     *     value
     *   - Scenario 3: action_type is 'phone', phone has value
     *
     * @param  array<int|string, mixed>  $normalizedData  The normalized ACF field data
     * @return bool True if placeholder body should be set, false otherwise
     */
    private function shouldSetPlaceholderBody(
        array $normalizedData
    ): bool {
        // Extract action type from normalized data
        $actionType = $normalizedData['cta_bar_action_type'] ?? '';

        // Extract button text from normalized data
        $buttonText = $normalizedData['cta_bar_button_text'] ?? '';

        // Extract button URL from normalized data
        $buttonUrl = $normalizedData['cta_bar_button_url'] ?? '';

        // Extract phone from normalized data
        $phone = $normalizedData['cta_bar_phone'] ?? '';

        // ---
        // Scenario 1: action_type is empty, button_text has value, button_url has value
        // ---

        // Check if scenario 1 conditions are met
        if (
            ! $this->hasValue($actionType)
            && $this->hasValue($buttonText)
            && $this->hasButtonUrl($buttonUrl)
        ) {
            // Return true for scenario 1
            return true;
        }

        // ---
        // Scenario 2: action_type is 'url', button_text has value, button_url has value
        // ---

        // Check if scenario 2 conditions are met
        if (
            $actionType === 'url'
            && $this->hasValue($buttonText)
            && $this->hasButtonUrl($buttonUrl)
        ) {
            // Return true for scenario 2
            return true;
        }

        // ---
        // Scenario 3: action_type is 'phone', phone has value
        // ---
        // Check if scenario 3 conditions are met
        // Return false if no scenarios match
        // Return true for scenario 3
        return $actionType === 'phone'
        && $this->hasValue($phone);
    }

    /**
     * Checks if a button URL value has content.
     *
     * Handles both array structures (with 'url' key) and string values.
     *
     * @param  mixed  $buttonUrl  The button URL value (array or string)
     * @return bool True if the button URL has content, false otherwise
     */
    private function hasButtonUrl(
        mixed $buttonUrl
    ): bool {
        // Check if button URL is an array
        if (\is_array($buttonUrl)) {
            // Return whether URL value in array has content
            return $this->hasValue($buttonUrl['url'] ?? '');
        }

        // Return whether button URL string has content
        return $this->hasValue($buttonUrl);
    }

    /**
     * Parses WordPress modules template cta data into Statamic page_builder format.
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
            $this->arrayFromValue($moduleData),
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // ---
        // Check if placeholder body should be set
        // ---

        // Get the body value from normalized data
        $bodyValue = $this->stringFromValue($normalizedData['cta_bar_body'] ?? '');

        // ---
        // Check if placeholder body should be set when heading has value, body is empty,
        // and action type scenarios are met
        // ---
        if (
            $this->hasValue($normalizedData['cta_bar_heading'] ?? '')
            && ! $this->hasValue($bodyValue)
            && $this->shouldSetPlaceholderBody($normalizedData)
        ) {
            // Set placeholder body value
            $bodyValue = '<p>Placeholder Body — Please Replace</p>';
        }

        // Map basic fields from modules template format using normalized data
        $heading = HtmlTagStripper::strip(
            $this->stringFromValue($normalizedData['cta_bar_heading'] ?? '')
        );
        $bodyContent = $this->processBody(
            $bodyValue,
            $site,
            $assetImporter,
            $output,
            $terms
        );

        // Action type handling
        $actionType = $normalizedData['cta_bar_action_type'] ?? 'url';
        $validActionTypes = ['url', 'phone', 'none'];
        $actionType = \in_array(
            $actionType,
            $validActionTypes,
            true
        ) ? $actionType : 'none';

        // Layout, size, and background
        $layout = $normalizedData['cta_bar_layout'] ?? 'cta-bar-centered';
        $size = $normalizedData['cta_bar_size'] ?? 'normal';
        $backgroundColor = $normalizedData['cta_bar_background_color'] ?? 'bg-auto';

        // Background image
        $useImage = (bool) ($normalizedData['cta_bar_use_image'] ?? '0');
        $backgroundImage = null;

        // Check if CTA background image should be used
        $ctaBarImage = $this->stringFromValue($normalizedData['cta_bar_image'] ?? '');
        if (
            $useImage
            && $ctaBarImage !== ''
        ) {
            // Import image with disabled upload handling
            $backgroundImage = ImageProcessor::importImageWithDisabledUploadHandling(
                $ctaBarImage,
                $site,
                $assetImporter,
                $output,
                $attachments,
                'CTA background image',
                'CtaParser::parseModule'
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
                        'image_id' => $ctaBarImage,
                    ]
                );

                // Log warning when uploads are enabled but no image is available
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'CtaParser::parseModule: Failed to import CTA background image when uploads are enabled',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // ---
                // When uploads are disabled, missing images are expected. Continue processing
                // without the image.
                // ---
            }
        }

        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_cta',
            'block_heading' => $heading,
            'hide_block_heading' => false,
            'body' => $bodyContent,
            'action_type' => $actionType,
            'layout' => $layout,
            'size' => $size,
            'background_color' => $backgroundColor,
            'background_image_query' => $useImage,
            'enabled' => true,
            'sms_phone_type_group' => [
                'type' => null,
                'phone' => null,
                'sms_short_code' => null,
            ],
        ];

        // URL action fields (only include if action_type is 'url')
        if ($actionType === 'url') {
            $result['button_text'] = HtmlTagStripper::strip(
                $this->stringFromValue($normalizedData['cta_bar_button_text'] ?? '')
            );
            $originalUrl = LinkHelper::getUrl($normalizedData['cta_bar_button_url'] ?? '');

            // Process URL through LinkProcessor to handle internal links and file imports
            $result['button_url'] = LinkProcessor::processUrlString(
                $originalUrl,
                $site,
                $assetImporter,
                $output,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );
            $result['button_target'] = LinkHelper::getTarget($normalizedData['cta_bar_button_url'] ?? '');
            $result['button_link_description'] = $normalizedData['cta_bar_button_description'] ?? '';
        }

        // Phone action fields (only include if action_type is 'phone')
        if ($actionType === 'phone') {
            $result['phone_prepend'] = $normalizedData['cta_bar_phone_prepend'] ?? 'Call';
            $result['phone'] = $normalizedData['cta_bar_phone'] ?? '';
        }

        // Only include background_image if it has a value
        if ($backgroundImage !== null && $backgroundImage !== '') {
            $result['background_image'] = $backgroundImage;
        }

        return $result;
    }

    /**
     * Convert mixed values to an array for strict consumers.
     *
     * @param  mixed  $value  The value to convert
     * @return array<int|string, mixed> The original value when array, or empty array
     */
    private function arrayFromValue(
        mixed $value
    ): array {
        // Return the value when it is already an array.
        if (\is_array($value)) {
            return $value;
        }

        // Return an empty array for non-array values.
        return [];
    }

    /**
     * Convert mixed values to safe strings.
     *
     * @param  mixed  $value  The value to normalize
     * @return string The original string, scalar cast, or empty string
     */
    private function stringFromValue(
        mixed $value
    ): string {
        // Return the value when it is already a string.
        if (\is_string($value)) {
            return $value;
        }

        // Cast scalar values to strings while excluding null and arrays.
        if (\is_scalar($value)) {
            return (string) $value;
        }

        // Return empty string for unsupported values.
        return '';
    }
}
