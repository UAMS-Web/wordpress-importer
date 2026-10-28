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
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;

/**
 * Parses WordPress ACF Side-by-Side Image and Text blocks into Statamic
 * page_builder replicator sets.
 *
 * Converts the ACF Side-by-Side Image and Text block attributes
 * (e.g., side_heading, side_text_layout, side_layout_body_text, side_image_image,
 * side_cta) into a Statamic uamsweb_block_image_side_by_side replicator set for
 * the landing_page blueprint's page_builder field. Handles textarea fields as
 * plain text, processes the link_list repeater, imports images with metadata, and
 * maps CTA fields.
 */
class SideBySideParser
{
    /**
     * The fieldset's `character_limit` for each top-level text handle this parser
     * writes, from `uamsweb_block_image_side_by_side.yaml` (wordpress-importer-archive#1345).
     *
     * Each limit is derived from the line budget of the box the block renders the
     * text in, so a longer value overflows it. The fieldset carries these as
     * authoring hints with no `max:` rule, so nothing else checks imported values.
     *
     * @var array<string, int>
     */
    private const array CHARACTER_LIMITS = [
        'block_heading' => 50,
        'body_body_only' => 449,
        'body_link_list' => 173,
        'button_text' => 42,
        'quote_text' => 306,
        'quote_text_with_link' => 217,
        'speaker_name' => 58,
        'speaker_title' => 121,
    ];

    /**
     * The fieldset's `character_limit` for the text of each `link_list` row (wordpress-importer-archive#1345).
     */
    private const int LINK_TEXT_CHARACTER_LIMIT = 22;

    /**
     * The WordPress Blockquote with Citation layouts, keyed by their
     * `side_text_layout` value, each with the Statamic `text_layout` it becomes,
     * the WordPress field its quote is read from and the handle it lands in (wordpress-importer-archive#1281).
     *
     * The layout with a link keeps its quote in a separate field on both sides,
     * because the button takes a line and the two quotes carry different limits.
     *
     * @var array<string, array{layout: string, source: string, handle: string}>
     */
    private const array QUOTE_LAYOUTS = [
        'blockquote-citation' => [
            'layout' => 'blockquote_with_citation',
            'source' => 'side_quote_text',
            'handle' => 'quote_text',
        ],
        'blockquote-citation-link' => [
            'layout' => 'blockquote_with_citation_and_link',
            'source' => 'side_quote_text_link',
            'handle' => 'quote_text_with_link',
        ],
    ];

    /**
     * The WordPress layout that hides the Call to Action Button. A button saved
     * under another layout stays in the data, and the theme drops it (wordpress-importer-archive#1281).
     */
    private const string LAYOUT_WITHOUT_BUTTON = 'blockquote-citation';

    /**
     * Normalizes link list data from different WordPress ACF formats.
     *
     * Handles various data structures that WordPress ACF can produce for repeater
     * fields, including row-based format after duplication, indexed format after
     * saving, and count-only format. Converts all formats to a consistent array
     * structure for processing.
     *
     * @param  mixed  $linkListData  The raw link list data from WordPress
     * @param  array<int|string, mixed>  $data  The full block data for context
     * @return array<int|string, mixed> The normalized link list array
     */
    private function normalizeLinkListData(
        $linkListData,
        array $data
    ): array {
        // Handle row-based format (after duplication) - e.g., {"row-0": {...}, "row-1": {...}}
        if (
            \is_array($linkListData)
            && isset($linkListData['row-0'])
        ) {
            // Initialize normalized array for row-based format
            $normalized = [];

            // Process each row in the link list data
            foreach ($linkListData as $rowKey => $rowData) {
                // Check if this is a valid row key (starts with 'row-')
                if (
                    \is_string($rowKey)
                    && \is_array($rowData)
                    &&
                    str_starts_with($rowKey, 'row-')
                ) {
                    // Add normalized link data to the array
                    $normalized[] = [
                        'side_link_list_text' => $rowData['field_side_link_list_text_b'] ?? '',
                        'side_link_list_url' => $rowData['field_side_link_list_url_b'] ?? [],
                        'side_link_list_description' => $rowData['field_side_link_list_description_b'] ?? '',
                    ];
                }

                // Clean up variables to free up memory
                unset(
                    $rowKey,
                    $rowData
                );
            }

            // Clean up variables to free up memory
            unset(
                $linkListData,
                $data
            );

            // Return the normalized link list data
            return $normalized;
        }

        // Initialize normalized array for indexed format
        $normalized = [];

        // Start with index 0 for sequential processing
        $index = 0;

        // Process sequential indexed link list entries
        while (isset($data["side_link_list_{$index}_side_link_list_text"])) {
            $normalized[] = [
                'side_link_list_text' => $data["side_link_list_{$index}_side_link_list_text"],
                'side_link_list_url' => $data["side_link_list_{$index}_side_link_list_url"] ?? [],
                'side_link_list_description' => $data["side_link_list_{$index}_side_link_list_description"] ?? '',
            ];
            $index++;
        }

        // Return normalized data if any entries were found
        if ($normalized !== []) {
            // Clean up variables to free up memory
            unset(
                $linkListData,
                $data,
                $index
            );

            // Return the processed link list data
            return $normalized;
        }

        // Handle numeric count format (number of links)
        if (\is_numeric($linkListData)) {
            // Convert to integer count
            $count = (int) $linkListData;

            // Process each link based on the count
            for ($i = 0; $i < $count; $i++) {
                // Add link data for each index
                $normalized[] = [
                    'side_link_list_text' => $data["side_link_list_{$i}_side_link_list_text"] ?? '',
                    'side_link_list_url' => $data["side_link_list_{$i}_side_link_list_url"] ?? [],
                    'side_link_list_description' => $data["side_link_list_{$i}_side_link_list_description"] ?? '',
                ];
            }

            // Clean up variables to free up memory
            unset(
                $linkListData,
                $data,
                $count
            );

            // Return the processed link list data
            return $normalized;
        }

        // Handle direct array format (already normalized)
        if (
            \is_array($linkListData)
            && $linkListData !== []
        ) {
            // Get the first key to determine format
            $firstKey = \array_keys($linkListData)[0];

            // Check if this is already in the correct format
            if (
                \is_numeric($firstKey)
                || $firstKey === 'side_link_list_text'
            ) {
                // Clean up variables to free up memory
                unset(
                    $data,
                    $firstKey
                );

                // Return the data as-is (already normalized)
                return $linkListData;
            }

            // Clean up variables to free up memory
            unset($firstKey);
        }

        // Clean up variables to free up memory
        unset(
            $linkListData,
            $data
        );

        // Return empty array if no valid format found
        return [];
    }

    /**
     * Processes the link list repeater from WordPress to Statamic format.
     *
     * Converts the side_link_list repeater into a Statamic link_list replicator,
     * mapping text, URL, description, and target fields. Each URL goes through
     * LinkProcessor, as the block's button and CTA URLs do, so an internal page link
     * becomes an entry reference and a linked file is imported (wordpress-importer-archive#1205). Handles the
     * target attribute for new window behavior.
     *
     * @param  array<int|string, mixed>  $linkList  The side_link_list repeater data
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @return array<int|string, mixed> The processed link_list replicator sets
     */
    private function processLinkList(
        array $linkList,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): array {
        // Initialize result array for processed links
        $result = [];

        // Process each link in the list
        foreach ($linkList as $link) {
            // Skip non-array link payloads
            if (! \is_array($link)) {
                continue;
            }

            // Extract URL from link data
            $url = LinkHelper::getUrl($link['side_link_list_url'] ?? '');

            // Skip links with empty URLs
            if ($url === '') {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'link_text' => $link['side_link_list_text'] ?? 'unknown',
                    ]
                );

                // Log warning about invalid link
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'SideBySideParser::processLinkList: Skipping link with empty URL in side_link_list',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Clean up variables to free up memory
                unset(
                    $link,
                    $url
                );

                // Skip to next link
                continue;
            }

            // Extract target attribute from link data, which a non-empty URL means is present
            $target = LinkHelper::getTarget($link['side_link_list_url']);

            // Process URL through LinkProcessor to handle internal links and file imports
            $url = LinkProcessor::processUrlString(
                $url,
                $site,
                $assetImporter,
                $output,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );

            // Add processed link to result array
            $result[] = [
                'id' => (new RowId)->generate(), // Unique ID for replicator set
                'type' => 'new_set', // Statamic replicator set type
                'text' => HtmlTagStripper::strip(
                    $this->normalizeStringValue($link['side_link_list_text'] ?? '')
                ), // Required, 40-char limit
                'url' => $url, // Required
                'target' => $target, // Required TrueFalse
                'description' => $this->normalizeStringValue(
                    $link['side_link_list_description'] ?? ''
                ), // Optional
                'enabled' => true, // Standard for replicator sets
            ];

            // Clean up variables to free up memory
            unset(
                $link,
                $url,
                $target
            );
        }

        // Clean up variables to free up memory
        unset(
            $linkList
        );

        // Return the processed link list
        return $result;
    }

    /**
     * Gets the field mappings for side-by-side blocks.
     *
     * Defines the mapping between field-based ACF field names and standard
     * field names for side-by-side blocks.
     *
     * @return array<int|string, mixed> Array of field name mappings
     */
    private function getFieldMappings(): array
    {
        return [
            'field_side_layout_b' => 'side_text_layout',
            'field_side_heading_b' => 'side_heading',
            'field_side_layout_body_text_b' => 'side_layout_body_text',
            'field_side_layout_link_text_b' => 'side_layout_link_text',
            'field_side_link_list_b' => 'side_link_list',
            'field_side_link_include_more_b' => 'side_link_include_more',
            'field_side_cta_text_b' => 'side_cta_side_cta_text',
            'field_side_cta_url_b' => 'side_cta_side_cta_url',
            'field_side_cta_description_b' => 'side_cta_side_cta_description',
            'field_side_image_image_b' => 'side_image_side_image_image',
            'field_side_image_alt_text_b' => 'side_image_side_image_alt_text',
            'field_side_image_crop_b' => 'side_image_side_image_crop',
            'field_side_image_anchor_b' => 'side_image_side_image_anchor',
            'field_side_image_position_b' => 'side_image_position',
            'field_side_image_background_color_b' => 'side_image_background_color',
            // Blockquote with Citation fields (wordpress-importer-archive#1281)
            'field_side_quote_text_b' => 'side_quote_text',
            'field_side_quote_text_link_b' => 'side_quote_text_link',
            'field_side_quote_speaker_name_b' => 'side_quote_speaker_name',
            'field_side_quote_speaker_title_b' => 'side_quote_speaker_title',
        ];
    }

    /**
     * The Statamic `text_layout` for a WordPress `side_text_layout` value.
     *
     * The Body only and Body and Link List values are the same on both sides; the
     * two Blockquote with Citation values are renamed (wordpress-importer-archive#1281).
     *
     * @param  string  $layout  The WordPress `side_text_layout` value
     * @return string The Statamic `text_layout` value
     */
    private function statamicTextLayout(
        string $layout
    ): string {
        return self::QUOTE_LAYOUTS[$layout]['layout'] ?? $layout;
    }

    /**
     * The quote and citation fields of a Blockquote with Citation layout (wordpress-importer-archive#1281).
     *
     * Values are imported as the editor typed them, quotation marks included: the
     * block's template strips marks at either end and adds its own. An empty value
     * is left out, as other empty fields are; the quote and speaker name are
     * required, and their absence is reported by `reportIncompleteQuote()`.
     *
     * @param  string  $layout  The WordPress `side_text_layout` value
     * @param  array<int|string, mixed>  $source  The block or module data, with WordPress field names
     * @return array<string, string> The non-empty Statamic fields, none for any other layout
     */
    private function quoteFields(
        string $layout,
        array $source
    ): array {
        // Only the two quote layouts carry these fields
        $quoteLayout = self::QUOTE_LAYOUTS[$layout] ?? null;

        if ($quoteLayout === null) {
            return [];
        }

        // The quote, from the field that belongs to this layout, and its citation
        $fields = [
            $quoteLayout['handle'] => $source[$quoteLayout['source']] ?? '',
            'speaker_name' => $source['side_quote_speaker_name'] ?? '',
            'speaker_title' => $source['side_quote_speaker_title'] ?? '',
        ];

        // Strip each value, keeping only those with text
        $quoteFields = [];

        foreach ($fields as $handle => $value) {
            $text = HtmlTagStripper::strip($this->normalizeStringValue($value));

            if ($text !== '') {
                $quoteFields[$handle] = $text;
            }
        }

        return $quoteFields;
    }

    /**
     * Report a quote block whose quote or speaker name is empty (wordpress-importer-archive#1281).
     *
     * The block's template renders nothing without both, so the block would be
     * blank on the page where WordPress showed placeholder text.
     *
     * @param  string  $layout  The WordPress `side_text_layout` value, a quote layout
     * @param  array<string, string>  $quoteFields  The fields `quoteFields()` returned
     * @param  string  $heading  The block heading, to find the block on its page
     * @param  bool  $skipped  Whether the block was skipped rather than imported
     * @return bool Whether anything was missing, and so reported
     */
    private function reportIncompleteQuote(
        string $layout,
        array $quoteFields,
        string $heading,
        bool $skipped
    ): bool {
        // Name each required value that is missing
        $missing = \array_values(\array_diff(
            [self::QUOTE_LAYOUTS[$layout]['handle'] ?? 'quote_text', 'speaker_name'],
            \array_keys($quoteFields)
        ));

        if ($missing === []) {
            return false;
        }

        // Record it for the run summary, with the page the block belongs to
        $pageUrl = LogContextHelper::enhanceWithEntryContext([])['page_url'];

        ImportWarningManager::addWarning(
            'content',
            'side_by_side_incomplete_quote',
            [
                'block_type' => 'uamsweb_block_image_side_by_side',
                'page_url' => \is_string($pageUrl) ? $pageUrl : '',
                'heading' => $heading,
                'text_layout' => $layout,
                'missing' => $missing,
                'reason' => $skipped
                    ? 'The block needs a quote and a speaker name to render, so it was skipped.'
                    : 'The block needs a quote and a speaker name to render, so it imports '
                        .'blank until an editor fills them in.',
            ]
        );

        return true;
    }

    /**
     * Gets the nested structure keys for side-by-side blocks.
     *
     * Defines which nested structures should be processed for side-by-side blocks.
     *
     * @return array<int|string, mixed> Array of nested structure keys
     */
    private function getNestedStructures(): array
    {
        return [
            'field_side_cta_b',
            'field_side_image_b',
        ];
    }

    /**
     * Parses a WordPress ACF image-side block into a Statamic page_builder replicator
     * set.
     *
     * Maps block attributes to a uamsweb_block_image_side_by_side set, including
     * heading, text layout, body content, link list, CTA, image options, and layout
     * settings. Processes textareas as plain text, imports images via AssetImporter,
     * and handles conditional fields based on text_layout. Omits fields with empty
     * values (e.g., body_link_list, link_list, alt_text, button_text) as per
     * requirements.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/image-side comment
     * @param  string  $site  The Statamic site handle (e.g., 'default') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML
     * @return array<int|string, mixed> The Statamic uamsweb_block_image_side_by_side replicator set
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

        // Normalize field names
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Extract text layout configuration
        $textLayout = $normalizedData['side_text_layout'] ?? 'body-only';

        // Extract and process heading text
        $heading = HtmlTagStripper::strip(
            $this->normalizeStringValue($normalizedData['side_heading'] ?? '')
        );

        // Extract image position setting
        $imagePosition = $normalizedData['side_image_position'] ?? 'left';

        // Extract background color setting
        $backgroundColor = $normalizedData['side_image_background_color'] ?? 'bg-auto';

        // Initialize body text for body-only layout
        $bodyBodyOnly = '';

        // Process body text for body-only layout
        if ($textLayout === 'body-only') {
            // Extract and process body text content
            $bodyBodyOnly = isset($normalizedData['side_layout_body_text'])
                ? HtmlTagStripper::strip(
                    $this->normalizeStringValue(
                        $normalizedData['side_layout_body_text']
                    )
                )
                : '';
        }

        // Initialize body text for link-list layout
        $bodyLinkList = '';

        // Process body text for link-list layout
        if ($textLayout === 'link-list') {
            // Extract and process link text content
            $bodyLinkList = isset($normalizedData['side_layout_link_text'])
                ? HtmlTagStripper::strip(
                    $this->normalizeStringValue(
                        $normalizedData['side_layout_link_text']
                    )
                )
                : '';
        }

        // Initialize link list array
        $linkList = [];

        // Extract include more setting
        $includeMore = ($normalizedData['side_link_include_more'] ?? '0') === '1';

        // ---
        // Only the link-list layout carries a link list: the fieldset shows link_list and
        // include_more only for it, and the template renders the list only for it, so a
        // list saved under another layout is never shown (#199). parseModule() applies
        // the same rule.
        // ---
        if ($textLayout === 'link-list') {
            // Extract link list data
            $linkListData = $normalizedData['side_link_list'] ?? null;

            // Process link list if data exists
            if ($linkListData !== null) {
                // Normalize link list data format
                $normalizedLinkList = $this->normalizeLinkListData(
                    $linkListData,
                    $normalizedData
                );

                // Process normalized link list if not empty
                if ($normalizedLinkList !== []) {
                    // Convert to Statamic format
                    $linkList = $this->processLinkList(
                        $normalizedLinkList,
                        $site,
                        $assetImporter,
                        $output
                    );
                }
            }
        }

        // Read the quote and citation of a Blockquote with Citation layout
        $quoteFields = \is_string($textLayout)
            ? $this->quoteFields($textLayout, $normalizedData)
            : [];

        // Extract and process CTA button text
        $buttonText = HtmlTagStripper::strip(
            $this->normalizeStringValue($normalizedData['side_cta_side_cta_text'] ?? '')
        );

        // Drop a button saved under another layout when this one hides it, as the theme does
        if ($textLayout === self::LAYOUT_WITHOUT_BUTTON) {
            $buttonText = '';
        }

        // Initialize the CTA button fields, none without its text
        $buttonFields = [];

        // Process CTA button data if text is provided
        if ($buttonText !== '') {
            // Extract URL data from CTA field
            $urlData = $normalizedData['side_cta_side_cta_url'] ?? [];
            if (! \is_array($urlData)) {
                $urlData = [];
            }

            // Extract original button URL
            $originalButtonUrl = $this->normalizeStringValue($urlData['url'] ?? '');

            // Process URL through LinkProcessor to handle internal links and file imports
            $buttonUrl = LinkProcessor::processUrlString(
                $originalButtonUrl,
                $site,
                $assetImporter,
                $output,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );

            // Set the button text, URL and target (new window or same window)
            $buttonFields = [
                'button_text' => $buttonText, // Optional
                'button_url' => $buttonUrl, // Conditional, required if button_text
                'button_target' => isset($urlData['target']) && $urlData['target'] === '_blank', // Conditional, required if button_text
            ];

            // Extract button description
            $buttonDescription = $normalizedData['side_cta_side_cta_description'] ?? '';

            // Set the button link description when it is provided
            if ($buttonDescription !== '') {
                $buttonFields['button_link_description'] = $buttonDescription; // Conditional
            }
        }

        // Extract image alt text
        $altText = $normalizedData['side_image_side_image_alt_text'] ?? '';

        // Extract the image crop setting, kept to the fieldset's options as parseModule()
        // keeps it: the template's validity guard needs a truthy image_crop, so the old '0'
        // for 'none' hid the whole block, and a value outside the options renders no crop
        // the stylesheet knows (#199, wordpress-importer-archive#1346)
        $imageCrop = $this->radioOption(
            $normalizedData['side_image_side_image_crop'] ?? null,
            ['top', 'bottom'],
            'center'
        );

        // Extract the image anchor setting, kept to the fieldset's options for the same reason
        $imageAnchor = $this->radioOption(
            $normalizedData['side_image_side_image_anchor'] ?? null,
            ['left', 'right'],
            'center'
        );

        // Extract image ID for processing
        $imageId = $this->normalizeStringValue(
            $normalizedData['side_image_side_image_image'] ?? ''
        );

        // Import image with disabled upload handling; an empty id imports nothing
        $image = ImageProcessor::importImageWithDisabledUploadHandling(
            $imageId,
            $site,
            $assetImporter,
            $output,
            $attachments,
            'side-by-side image',
            'SideBySideParser::parse'
        );

        // Check if image is available after import attempt
        if (
            $assetImporter->getUploadImages() // Missing images are only problematic when uploads are enabled
            && ($image === null || $image === '')
        ) {
            // Log warning and skip block when uploads are enabled but no image is available
            Log::warning(
                'SideBySideParser::parse: Skipping wp:acf/image-side block due to missing required image when uploads are enabled',
                [
                    'site' => $site,
                    'image_id' => $imageId,
                ]
            );

            // Clean up variables to free up memory
            unset(
                $data,
                $normalizedData,
                $textLayout,
                $heading,
                $imagePosition,
                $backgroundColor,
                $bodyBodyOnly,
                $bodyLinkList,
                $linkList,
                $includeMore,
                $linkListData,
                $normalizedLinkList,
                $buttonText,
                $buttonUrl,
                $buttonFields,
                $buttonDescription,
                $urlData,
                $image,
                $altText,
                $imageCrop,
                $imageAnchor,
                $imageId,
                $quoteFields
            );

            // Return empty array to skip the block
            return [];

            // When uploads are disabled, missing images are expected.
            // Continue processing without the image.
        }

        // Build the replicator set, omitting empty fields
        $result = [
            'id' => (new RowId)->generate(), // Unique ID for replicator set
            'type' => 'uamsweb_block_image_side_by_side', // Statamic fieldset handle
            'text_layout' => \is_string($textLayout) ? $this->statamicTextLayout($textLayout) : $textLayout, // Required radio field
            'block_heading' => $heading, // Required text, 50-char limit
            'hide_block_heading' => false, // Default, hidden in Statamic
            'image_crop' => $imageCrop, // Required radio
            'image_anchor' => $imageAnchor, // Required radio
            'image_position' => $imagePosition, // Required radio
            'background_color' => $backgroundColor, // Required select
            'enabled' => true, // Standard for replicator sets
        ];

        // Add the quote and citation of a Blockquote with Citation layout
        $result += $quoteFields;

        // A quote block without its quote or speaker name renders blank, so say so
        if (\is_string($textLayout) && isset(self::QUOTE_LAYOUTS[$textLayout])) {
            $this->reportIncompleteQuote($textLayout, $quoteFields, $heading, false);
        }

        // Include non-empty fields conditionally
        if ($bodyBodyOnly !== '') {
            // Set body body only field
            $result['body_body_only'] = $bodyBodyOnly; // Conditional, required for body-only
        }

        // Check if body link list is provided
        if ($bodyLinkList !== '') {
            $result['body_link_list'] = $bodyLinkList; // Conditional, required for link-list
        }

        // Check if link list is provided
        if ($linkList !== []) {
            // Set link list field
            $result['link_list'] = $linkList; // Conditional, required for link-list

            // Set include more field
            $result['include_more'] = $includeMore; // Include only if link_list is non-empty
        }

        // Set required image field
        $result['image'] = $image; // Required, imported path or original URL

        // Check if alt text is provided
        if ($altText !== '') {
            // Set alt text field
            $result['alt_text'] = $altText; // Optional, include only if non-empty
        }

        // Add the button fields, when the button has text
        $result += $buttonFields;

        // Clean up variables to free up memory
        unset(
            $data,
            $normalizedData,
            $textLayout,
            $heading,
            $imagePosition,
            $backgroundColor,
            $bodyBodyOnly,
            $bodyLinkList,
            $linkList,
            $includeMore,
            $linkListData,
            $normalizedLinkList,
            $buttonText,
            $buttonUrl,
            $buttonFields,
            $buttonDescription,
            $urlData,
            $image,
            $altText,
            $imageCrop,
            $imageAnchor,
            $imageId,
            $quoteFields
        );

        // Report any text longer than the field it lands in can show
        $this->reportOverLimitText($result);

        // Return the completed result
        return $result;
    }

    /**
     * Parses WordPress modules template image_side data into Statamic page_builder
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
        // Extract and process heading text from module data
        $heading = HtmlTagStripper::strip(
            $this->normalizeStringValue($moduleData['side_heading'] ?? '')
        );

        // Extract text layout configuration. An absent layout defaults to body-only, as the ACF
        // path and the fieldset do; 'left' was never a text_layout option (wordpress-importer-archive#1346).
        $textLayout = $moduleData['side_text_layout'] ?? 'body-only';

        // Extract and process body text content
        $bodyText = isset($moduleData['side_layout_body_text'])
            ? HtmlTagStripper::strip(
                $this->normalizeStringValue($moduleData['side_layout_body_text'])
            )
            : '';

        // Extract and process link text content
        $linkText = isset($moduleData['side_layout_link_text'])
            ? HtmlTagStripper::strip(
                $this->normalizeStringValue($moduleData['side_layout_link_text'])
            )
            : '';

        // Extract include more setting
        $includeMore = ($moduleData['side_link_include_more'] ?? '0') === '1';

        // Extract image position setting. An absent position defaults to left, as the ACF path,
        // the fieldset and the theme's own module renderer do (wordpress-importer-archive#1346).
        $imagePosition = $moduleData['side_image_position'] ?? 'left';

        // Extract background color setting
        $backgroundColor = $moduleData['side_image_background_color'] ?? 'bg-auto';

        // Get the count of links in the link list from module data
        $linkListCount = $this->normalizeIntValue($moduleData['side_link_list'] ?? 0);

        // Get current entry data from processing context for logging
        $entryData = ProcessingContext::getEntryData();

        // Extract post name for inclusion in log messages
        $postName = $entryData['post_name'] ?? 'unknown';

        // Validate required heading field
        if ($heading === '') {
            // Log warning with post context for debugging
            Log::warning(
                'SideBySideParser::parseModule: Skipping modules template image_side due to missing required heading',
                [
                    'site' => $site,
                    'post_name' => $postName,
                ]
            );

            // Clean up variables to free up memory
            unset(
                $heading,
                $textLayout,
                $bodyText,
                $linkText,
                $includeMore,
                $imagePosition,
                $backgroundColor,
                $entryData,
                $postName
            );

            // Skip processing this module due to missing required field
            return null;
        }

        // Validate required fields based on text layout
        if ($textLayout === 'body-only') {
            // For body-only layout, validate that body text is provided
            if ($bodyText === '') {
                // Log warning with post context for debugging
                Log::warning(
                    'SideBySideParser::parseModule: Skipping modules template image_side due to missing required body text for body-only layout',
                    [
                        'site' => $site,
                        'post_name' => $postName,
                        'text_layout' => $textLayout,
                    ]
                );

                // Clean up variables to free up memory
                unset(
                    $heading,
                    $textLayout,
                    $bodyText,
                    $linkText,
                    $includeMore,
                    $imagePosition,
                    $backgroundColor,
                    $entryData,
                    $postName
                );

                // Skip processing this module due to missing required field
                return null;
            }
        } elseif ($textLayout === 'link-list') {
            // For link-list layout, validate that link text is provided
            if ($linkText === '') {
                // Log warning with post context for debugging
                Log::warning(
                    'SideBySideParser::parseModule: Skipping modules template image_side due to missing required link text for link-list layout',
                    [
                        'site' => $site,
                        'post_name' => $postName,
                        'text_layout' => $textLayout,
                    ]
                );

                // Clean up variables to free up memory
                unset(
                    $heading,
                    $textLayout,
                    $bodyText,
                    $linkText,
                    $includeMore,
                    $imagePosition,
                    $backgroundColor,
                    $entryData,
                    $postName
                );

                // Skip processing this module due to missing required field
                return null;
            }

            // Validate that link list has content for link-list layout
            if ($linkListCount === 0) {
                // Log warning with post context for debugging
                Log::warning(
                    'SideBySideParser::parseModule: Skipping modules template image_side due to missing required link list for link-list layout',
                    [
                        'site' => $site,
                        'post_name' => $postName,
                        'text_layout' => $textLayout,
                    ]
                );

                // Clean up variables to free up memory
                unset(
                    $heading,
                    $textLayout,
                    $bodyText,
                    $linkText,
                    $includeMore,
                    $imagePosition,
                    $backgroundColor,
                    $entryData,
                    $postName,
                    $linkListCount
                );

                // Skip processing this module due to missing required field
                return null;
            }
        } elseif (\is_string($textLayout) && isset(self::QUOTE_LAYOUTS[$textLayout])) {
            // For a quote layout, the template renders nothing without a quote and a speaker name
            $quoteFields = $this->quoteFields($textLayout, $moduleData);

            if ($this->reportIncompleteQuote($textLayout, $quoteFields, $heading, true)) {
                // Log warning with post context for debugging
                Log::warning(
                    'SideBySideParser::parseModule: Skipping modules template image_side due to missing required quote or speaker name for a quote layout',
                    [
                        'site' => $site,
                        'post_name' => $postName,
                        'text_layout' => $textLayout,
                    ]
                );

                // Clean up variables to free up memory
                unset(
                    $heading,
                    $textLayout,
                    $bodyText,
                    $linkText,
                    $includeMore,
                    $imagePosition,
                    $backgroundColor,
                    $entryData,
                    $postName,
                    $quoteFields
                );

                // Skip processing this module due to missing required field
                return null;
            }
        }

        // Import the side image with disabled upload handling; an absent or empty id imports nothing
        $image = ImageProcessor::importImageWithDisabledUploadHandling(
            $this->normalizeStringValue($moduleData['side_image_side_image_image'] ?? ''),
            $site,
            $assetImporter,
            $output,
            $attachments,
            'side-by-side image',
            'SideBySideParser::parseModule'
        );

        // Validate required fields - image is now required
        if ($image === null || $image === '') {
            // Log warning about missing required image
            Log::warning(
                'SideBySideParser::parseModule: Skipping modules template image_side due to missing required image',
                [
                    'site' => $site,
                    'post_name' => $postName,
                    'image_id' => $moduleData['side_image_side_image_image'] ?? 'none',
                ]
            );

            // Clean up variables to free up memory
            unset(
                $heading,
                $textLayout,
                $bodyText,
                $linkText,
                $includeMore,
                $imagePosition,
                $backgroundColor,
                $entryData,
                $postName,
                $image,
                $quoteFields
            );

            // Return null if no image
            return null;
        }

        // Set alt text from module data
        $altText = $moduleData['side_image_side_image_alt_text'] ?? '';

        // Set crop value from module data. Only the fieldset's options survive: the template's
        // validity guard needs a truthy image_crop, so the old '0' for 'none' or an absent
        // value hid the whole block (wordpress-importer-archive#1346).
        $crop = $this->radioOption(
            $moduleData['side_image_side_image_crop'] ?? null,
            ['top', 'bottom'],
            'center'
        );

        // Set anchor value from module data, kept to the fieldset's options
        $anchor = $this->radioOption(
            $moduleData['side_image_side_image_anchor'] ?? null,
            ['left', 'right'],
            'center'
        );

        // Initialize link list array
        $linkList = [];

        // ---
        // Only the link-list layout carries a link list, as in parse(): the fieldset and
        // the template show one only for it, so the rows of any other layout are not read,
        // and their URLs are not run through LinkProcessor, which can import the files
        // they link to (#199)
        // ---
        $linkRowCount = $textLayout === 'link-list' ? $linkListCount : 0;

        // Process each link in the list
        for ($i = 0; $i < $linkRowCount; $i++) {
            // Add link data to the list
            $linkList[] = [
                'side_link_list_text' => HtmlTagStripper::strip(
                    $this->normalizeStringValue(
                        $moduleData["side_link_list_{$i}_side_link_list_text"] ?? ''
                    )
                ),
                'side_link_list_url' => $moduleData["side_link_list_{$i}_side_link_list_url"] ?? '',
                'side_link_list_description' => $moduleData["side_link_list_{$i}_side_link_list_description"] ?? '',
            ];
        }

        // Initialize processed link list array
        $processedLinkList = [];

        // Process link list if not empty
        if ($linkList !== []) {
            // Convert link list to Statamic format
            $processedLinkList = $this->processLinkList(
                $linkList,
                $site,
                $assetImporter,
                $output
            );
        }

        // Extract and process CTA button text
        $ctaText = HtmlTagStripper::strip(
            $this->normalizeStringValue($moduleData['side_cta_side_cta_text'] ?? '')
        );

        // Drop a button saved under another layout when this one hides it, as the theme does
        if ($textLayout === self::LAYOUT_WITHOUT_BUTTON) {
            $ctaText = '';
        }

        // Initialize the CTA button fields, none without its text
        $buttonFields = [];

        // Process CTA button data if text is provided
        if ($ctaText !== '') {
            // A button without a link has an empty URL and opens in the same window
            $buttonFields = [
                'button_text' => $ctaText,
                'button_url' => '',
                'button_target' => false,
            ];

            // Read the link when the module has one
            if (isset($moduleData['side_cta_side_cta_url'])) {
                // Extract original CTA button URL
                $originalCtaUrl = LinkHelper::getUrl($moduleData['side_cta_side_cta_url']);

                // Process URL through LinkProcessor to handle internal links and file imports
                $buttonFields['button_url'] = LinkProcessor::processUrlString(
                    $originalCtaUrl,
                    $site,
                    $assetImporter,
                    $output,
                    ProcessingContext::getStatamicEntryId(),
                    ProcessingContext::getCollectionHandle()
                );

                // Extract CTA button target
                $buttonFields['button_target'] = LinkHelper::getTarget($moduleData['side_cta_side_cta_url']);

                // Extract CTA button description
                $ctaDescription = $moduleData['side_cta_side_cta_description'] ?? '';

                // Set the button link description when it is provided
                if ($ctaDescription !== '') {
                    $buttonFields['button_link_description'] = $ctaDescription;
                }
            }
        }

        // Build the replicator set with the fieldset's handles, the same keys parse() writes
        // (wordpress-importer-archive#1346). The old body_text, link_text, crop, anchor, cta_* and link_include_more keys
        // had no field to land in, and the missing image_crop and image_anchor failed the
        // template's validity guard, so the block rendered nothing.
        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_image_side_by_side',
            'text_layout' => \is_string($textLayout) ? $this->statamicTextLayout($textLayout) : $textLayout,
            'block_heading' => $heading,
            'hide_block_heading' => false,
            'image_crop' => $crop,
            'image_anchor' => $anchor,
            'image_position' => $imagePosition,
            'background_color' => $backgroundColor,
            'enabled' => true,
        ];

        // Body text for the body-only layout, required non-empty by the validation above
        if ($textLayout === 'body-only') {
            $result['body_body_only'] = $bodyText;
        }

        // Body text for the link-list layout, required non-empty by the validation above
        if ($textLayout === 'link-list') {
            $result['body_link_list'] = $linkText;
        }

        // The quote and citation of a quote layout, checked non-empty by the validation above
        $result += $quoteFields ?? [];

        // Check if processed link list is provided
        if ($processedLinkList !== []) {
            // Set link list field
            $result['link_list'] = $processedLinkList;

            // Set include more field, only beside a link list, as parse() does
            $result['include_more'] = $includeMore;
        }

        // Set required image field
        $result['image'] = $image; // Required, imported path or original URL

        // Check if alt text is provided
        if ($altText !== '') {
            // Set alt text field
            $result['alt_text'] = $altText;
        }

        // Add the button fields, when the button has text
        $result += $buttonFields;

        // Clean up variables to free up memory
        unset(
            $heading,
            $textLayout,
            $bodyText,
            $linkText,
            $includeMore,
            $imagePosition,
            $backgroundColor,
            $entryData,
            $postName,
            $image,
            $altText,
            $crop,
            $anchor,
            $linkList,
            $linkListCount,
            $processedLinkList,
            $ctaText,
            $buttonFields,
            $ctaDescription,
            $quoteFields
        );

        // Report any text longer than the field it lands in can show
        $this->reportOverLimitText($result);

        // Return the completed replicator set
        return $result;
    }

    /**
     * Report each text value in a built set that is longer than its field's
     * `character_limit` (wordpress-importer-archive#1345).
     *
     * The value is imported unchanged: shortening content silently would be worse
     * than an editor reading a report and rewording it. One warning is recorded
     * per over-limit value, naming the page, the handle, the limit and the length.
     *
     * @param  array<string, mixed>  $result  The built replicator set
     */
    private function reportOverLimitText(
        array $result
    ): void {
        // Collect each handle with its value and limit, top-level fields first
        $checks = [];

        foreach (self::CHARACTER_LIMITS as $handle => $limit) {
            $checks[] = [$handle, $result[$handle] ?? null, $limit];
        }

        // Then the text of each link list row, named by its position in the list
        $linkList = $result['link_list'] ?? [];

        if (\is_array($linkList)) {
            foreach ($linkList as $index => $row) {
                $checks[] = [
                    "link_list.{$index}.text",
                    \is_array($row) ? ($row['text'] ?? null) : null,
                    self::LINK_TEXT_CHARACTER_LIMIT,
                ];
            }
        }

        // Read the page the block belongs to once, only if a warning needs it
        $pageUrl = null;

        foreach ($checks as [$handle, $value, $limit]) {
            // Only a string longer than its limit is reported
            if (! \is_string($value)) {
                continue;
            }

            $length = \mb_strlen($value, 'UTF-8');

            if ($length <= $limit) {
                continue;
            }

            if ($pageUrl === null) {
                $entryUrl = LogContextHelper::enhanceWithEntryContext([])['page_url'];
                $pageUrl = \is_string($entryUrl) ? $entryUrl : '';
            }

            // Both callers always write block_heading, so its fallback is never read
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            ImportWarningManager::addWarning(
                'content',
                'side_by_side_over_character_limit',
                [
                    'block_type' => 'uamsweb_block_image_side_by_side',
                    'page_url' => $pageUrl,
                    'heading' => $result['block_heading'] ?? '',
                    'handle' => $handle,
                    'limit' => $limit,
                    'length' => $length,
                    'reason' => 'The value is longer than the character limit of the '
                        .'box the block renders it in, so it overflows; it was imported '
                        .'unchanged for an editor to shorten.',
                ]
            );
        }
    }

    /**
     * Normalize mixed values to safe strings.
     *
     * @param  mixed  $value  Value to normalize
     * @return string Normalized string value
     */
    private function normalizeStringValue(
        mixed $value
    ): string {
        if (! \is_scalar($value)) {
            return '';
        }

        return (string) $value;
    }

    /**
     * A radio field's value, kept to the field's options.
     *
     * @param  mixed  $value  The stored value
     * @param  list<string>  $options  The radio field's option keys other than its default
     * @param  string  $default  The field's default, used for anything else
     * @return string One of the options or the default
     */
    private function radioOption(
        mixed $value,
        array $options,
        string $default
    ): string {
        $value = $this->normalizeStringValue($value);

        // A string loosely equals a non-numeric option only when identical, so the strict flag
        // phpstan-strict-rules requires changes nothing and TrueToFalse cannot be killed
        // @pest-mutate-ignore: TrueToFalse
        return \in_array($value, $options, true) ? $value : $default;
    }

    /**
     * Normalize mixed values to safe integer values.
     *
     * @param  mixed  $value  Value to normalize
     * @return int Normalized integer value
     */
    private function normalizeIntValue(
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
