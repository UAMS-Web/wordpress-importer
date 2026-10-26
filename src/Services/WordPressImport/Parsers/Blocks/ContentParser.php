<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Utilities\Alignment;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Utilities\VideoSetType;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlStreamParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\AcfFieldNormalizer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;

/**
 * Parses WordPress ACF uams-content blocks into Statamic page_builder replicator
 * sets.
 *
 * Converts the ACF uams-content block attributes (e.g., content_heading,
 * content_content) into a Statamic uamsweb_block_content replicator set for the
 * landing_page blueprint's page_builder field. Uses TextProcessor to process the
 * WYSIWYG content_content field into Bard format, applying WordPress-style
 * wpautop formatting and preprocessing links, shortcodes, and images for file
 * imports and Bard sets. Uses AcfFieldNormalizer for consistent field mapping
 * across different WordPress ACF data structures.
 */
class ContentParser
{
    /**
     * Defines the mapping between field-based ACF field names and standard field
     * names.
     *
     * This method provides the field mappings needed by AcfFieldNormalizer to
     * handle the conversion of WordPress ACF field names like field_content_heading_b
     * to standard names like content_heading.
     *
     * @return array<int|string, mixed> The field mappings for content blocks
     */
    private function getFieldMappings(): array
    {
        return [
            'field_content_heading_b' => 'content_heading',
            'field_content_hide_heading_b' => 'content_hide_heading',
            'field_content_content_b' => 'content_content',
            'field_content_description_b' => 'content_content',
            'field_content_background_color_b' => 'content_background_color',
            'field_content_geo_valid_b' => 'content_geo_valid',
            'field_content_geo_b' => 'content_geo',
            'field_content_geo_region_b' => 'content_geo_region',
        ];
    }

    /**
     * Defines which nested structures should be processed for content blocks.
     *
     * This method provides the nested structure mappings needed by AcfFieldNormalizer
     * to handle complex ACF block structures.
     *
     * @return array<int|string, mixed> The nested structure mappings for content blocks
     */
    private function getNestedStructures(): array
    {
        return [
            // No nested structures for content blocks
        ];
    }

    /**
     * Parses a WordPress ACF uams-content block into a Statamic replicator set.
     *
     * Processes the block attributes to map content_heading, hide_block_heading, and
     * background_color to the uamsweb_block_content set. The content_content WYSIWYG
     * field is JSON-decoded with Unicode support, processed using TextProcessor with
     * WpAutoP formatting enabled, and parsed into Bard format with images converted
     * to image Bard sets. The innerContent parameter is ignored as content is
     * sourced from attributes. Generates a unique ID for the replicator set using
     * RowId. Uses AcfFieldNormalizer for consistent field mapping across different
     * WordPress ACF data structures. Skips the block (returns null) if the body
     * content is empty, regardless of heading. If body has content but heading is
     * empty or default, sets heading to the default placeholder.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:acf/uams-content comment
     * @param  array<int|string, mixed>  $innerContent  The parsed inner content (unused)
     * @param  string  $site  The Statamic site handle for asset paths and shortcode processing
     * @param  AssetImporter  $assetImporter  Instance for importing linked files (e.g., PDFs)
     * @param  OutputInterface  $output  Console output for logging import progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects (default: [])
     * @param  array<int|string, mixed>  $terms  Array of terms data
     * @return array<int|string, mixed>|null The Statamic uamsweb_block_content replicator set with Bard body content, or null if body is empty
     */
    public function parse(
        array $attributes,
        array $innerContent,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = []
    ): ?array {
        $dataRaw = $attributes['data'] ?? [];
        $data = \is_array($dataRaw) ? $dataRaw : [];

        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            $data,
            $this->getFieldMappings(),
            $this->getNestedStructures()
        );

        // Map WordPress ACF fields to Statamic fields using normalized data
        $headingRaw = $normalizedData['content_heading'] ?? 'Content Block Title - Please Replace';
        $blockHeading = HtmlTagStripper::strip(
            \is_string($headingRaw) ? $headingRaw : 'Content Block Title - Please Replace'
        );
        $hideBlockHeading = isset($normalizedData['content_hide_heading']) && $normalizedData['content_hide_heading'] === '1';
        $backgroundColorRaw = $normalizedData['content_background_color'] ?? 'bg-auto';
        $backgroundColor = \is_string($backgroundColorRaw)
            ? $backgroundColorRaw
            : 'bg-auto';

        // Process content_content (WYSIWYG) into Bard format
        $contentContentRaw = $normalizedData['content_content'] ?? '';
        $contentContent = \is_string($contentContentRaw) ? $contentContentRaw : '';
        $bodyContent = [];
        if ($contentContent !== '') {
            $decodedContent = self::decodeContentString($contentContent);

            // Convert iframes to video embed sets before processing
            $decodedContent = $this->convertIframesToVideoEmbeds(
                $decodedContent
            );

            // Process content using TextProcessor with WpAutoP enabled
            $bodyContent = TextProcessor::processForBard(
                $decodedContent,
                $site,
                $assetImporter,
                $output,
                true, // Apply WpAutoP for uams-content WYSIWYG
                false,
                $attachments,
                $terms,
                null,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );
        }

        // Determine if heading is empty or default placeholder
        $headingIsEmpty = $blockHeading === '' || $blockHeading === 'Content Block Title - Please Replace';

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
                    'heading_is_default' => $blockHeading === 'Content Block Title - Please Replace',
                    'body_content_empty' => true,
                    'content_content_empty' => $contentContent === '',
                    'normalized_data_keys' => \array_keys($normalizedData),
                    'content_heading_in_data' => isset($normalizedData['content_heading']),
                    'wordpress_post_id' => ProcessingContext::getWordPressPostId(),
                    'statamic_entry_id' => ProcessingContext::getStatamicEntryId(),
                    'post_name' => $entryData['post_name'] ?? null,
                ]
            );

            // Log warning when content block is skipped due to empty body
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'ContentParser::parse: Returning null - empty body',
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
            $blockHeading = 'Content Block Title - Please Replace';
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
     * Parses WordPress modules template uams_content data into Statamic page_builder
     * format.
     *
     * This method handles the conversion of modules template data (from
     * uamswp_modules) to Statamic page_builder format, as opposed to the parse()
     * method which handles ACF block data. The modules template uses a different data
     * structure with prefixed field names. Uses AcfFieldNormalizer for consistent
     * field mapping. Processes content_content WYSIWYG field if present, otherwise
     * returns block with empty body content.
     *
     * @param  array<int|string, mixed>  $moduleData  The module data from uamswp_modules
     * @param  string  $site  The site handle for asset processing
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  The console output interface
     * @param  array<int|string, mixed>  $attachments  Array of attachment data for image processing
     * @param  ?XmlStreamParser  $xmlParser  The XML stream parser for additional data access
     * @param  array<int|string, mixed>  $terms  Array of terms data
     * @return array<int|string, mixed> The page_builder block with processed or empty body content
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

        // Map WordPress ACF fields to Statamic fields using normalized data
        $headingRaw = $normalizedData['content_heading'] ?? 'Content Block Title - Please Replace';
        $blockHeading = HtmlTagStripper::strip(
            \is_string($headingRaw) ? $headingRaw : 'Content Block Title - Please Replace'
        );
        $hideBlockHeading = isset($normalizedData['content_hide_heading']) && $normalizedData['content_hide_heading'] === '1';
        $backgroundColorRaw = $normalizedData['content_background_color'] ?? 'bg-auto';
        $backgroundColor = \is_string($backgroundColorRaw)
            ? $backgroundColorRaw
            : 'bg-auto';

        // Process content_content (WYSIWYG) into Bard format
        $contentContentRaw = $normalizedData['content_content'] ?? '';
        $contentContent = \is_string($contentContentRaw) ? $contentContentRaw : '';
        $bodyContent = [];
        if ($contentContent !== '') {
            $decodedContent = self::decodeContentString($contentContent);

            // Convert iframes to video embed sets before processing
            $decodedContent = $this->convertIframesToVideoEmbeds(
                $decodedContent
            );

            // Process content using TextProcessor with WpAutoP enabled
            $bodyContent = TextProcessor::processForBard(
                $decodedContent,
                $site,
                $assetImporter,
                $output,
                true, // Apply WpAutoP for uams-content WYSIWYG
                false,
                $attachments,
                $terms,
                null,
                ProcessingContext::getStatamicEntryId(),
                ProcessingContext::getCollectionHandle()
            );
        }

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

    /**
     * Converts iframe elements to video embed sets in HTML content.
     *
     * Detects YouTube and Vimeo iframes in the content and converts them to
     * Statamic video embed set HTML — `data-set-type="youtube"` / `"vimeo"` /
     * `"box"`, routed by `VideoSetType` (wordpress-importer-archive#298) — that can be processed by
     * TextProcessor. Extracts video URL, alignment, and caption from the iframe and
     * its parent container. An iframe whose URL routes to no platform-specific set
     * is left alone rather than emitting a set type the Bard field does not define.
     *
     * @param  string  $html  The HTML content containing iframes
     * @return string The HTML content with iframes converted to video embed sets
     */
    private function convertIframesToVideoEmbeds(
        string $html
    ): string {
        // Check if content contains iframes
        if (
            ! str_contains(
                $html,
                '<iframe'
            )
        ) {
            // Return content unchanged if no iframes found
            return $html;
        }

        // Load HTML into DOMDocument for parsing
        $doc = TextProcessor::loadHtml($html);

        // Create XPath instance for DOM navigation
        $xpath = new DOMXPath($doc);

        // Find all iframe elements
        $iframes = $xpath->query('//iframe');

        if ($iframes === false) {
            return $html;
        }

        // Process iframes in reverse order to maintain correct positions
        for ($i = $iframes->length - 1; $i >= 0; $i--) {
            // Get current iframe element from collection
            $iframe = $iframes->item($i);

            // Check if iframe is a valid DOMElement
            if (! ($iframe instanceof DOMElement)) {
                // Skip to next iframe if not a valid element
                continue;
            }

            // Extract iframe src attribute
            $iframeSrc = $iframe->getAttribute('src');

            // Check if iframe src is empty
            if ($iframeSrc === '') {
                // Skip to next iframe if src is empty
                continue;
            }

            // Extract video URL and provider from iframe src
            $videoData = $this->extractVideoDataFromIframeSrc($iframeSrc);

            // Check if video data extraction was successful
            if ($videoData === null || $videoData === []) {
                // Skip to next iframe if extraction failed
                continue;
            }

            // ---
            // Extract alignment from parent div classes
            // ---

            // Get parent node from iframe element
            $parentDiv = $iframe->parentNode;

            // Initialize alignment with default center value
            $align = 'aligncenter'; // Default alignment

            // Check if parent div is a valid DOMElement div node
            if (
                $parentDiv instanceof DOMElement
                && $parentDiv->nodeName === 'div'
            ) {
                // Get parent div class attribute
                $parentClass = $parentDiv->getAttribute('class');

                // Extract alignment from class string
                $align = Alignment::translate(
                    $parentClass,
                    false // Not for image sets
                );
            }

            // Extract caption from iframe title attribute
            $caption = \trim($iframe->getAttribute('title'));

            // Check if caption starts with "Video: " prefix
            if (
                str_starts_with($caption, 'Video: ')
            ) {
                // Remove "Video: " prefix from caption
                $caption = \substr(
                    $caption,
                    7
                );
            }

            // ---
            // Resolve the platform-specific Bard set that hosts this URL. #589 retired
            // the single `video` set for `youtube` / `vimeo` / `box` (wordpress-importer-archive#298).
            // ---
            $videoUrl = \is_string($videoData['url']) ? $videoData['url'] : '';
            $provider = isset($videoData['provider']) && \is_string($videoData['provider'])
                ? $videoData['provider']
                : '';

            $resolved = VideoSetType::resolve($videoUrl);

            // Leave an unroutable iframe alone rather than emitting a set type the
            // Bard field does not define, or a URL its validation rejects
            if ($resolved === null) {
                continue;
            }

            $setType = $resolved['type'];

            // ---
            // Build video set values. The URL handle is `url`, not `video_url`, and
            // `embed_mode` is written explicitly: a WordPress iframe carries no
            // transcript, creators, or transparency metadata, so the `full` segment
            // mode is never justified by the source data (wordpress-importer-archive#298).
            // ---
            $values = [
                'type' => $setType,
                'embed_mode' => VideoSetType::EMBED_MODE_SIMPLE,
                'url' => $resolved['url'],
                'aspect_ratio' => '16by9', // Default per uamsweb_common_video.yaml
                'align' => $align,
            ];

            // Check if caption exists
            if ($caption !== '') {
                // Add caption to values array
                $values['caption'] = $caption;
            }

            // ---
            // Create video embed set div element
            // ---

            // Create div element for video embed set
            $videoSetDiv = $doc->createElement('div');

            // Set data-set-type attribute to the platform-specific set handle
            $videoSetDiv->setAttribute(
                'data-set-type',
                $setType
            );

            // Stamp the run nonce so Set::parseHTML() trusts this as our own markup
            $videoSetDiv->setAttribute(
                SetMarkupNonce::ATTRIBUTE,
                SetMarkupNonce::value()
            );

            // ---
            // Set data-set-attr attributes for each value
            // ---

            // Loop through values array to set data-set-attr attributes
            foreach ($values as $key => $value) {
                // Check if key is not 'type' (type is used in data-set-type attribute)
                if ($key !== 'type') {
                    // Set attribute on div element
                    $videoSetDiv->setAttribute(
                        "data-set-attr-{$key}",
                        $value
                    );
                }
            }

            // Get parent container (div with embed-responsive class)
            $parentContainer = $iframe->parentNode;

            // Check if parent container is a div with embed-responsive class
            if (
                $parentContainer instanceof DOMElement
                && $parentContainer->nodeName === 'div'
                && str_contains(
                    $parentContainer->getAttribute('class'),
                    'embed-responsive'
                )
            ) {
                $parentContainerParent = $parentContainer->parentNode;

                // Replace parent container with video embed set
                if ($parentContainerParent instanceof \DOMNode) {
                    $parentContainerParent->replaceChild(
                        $videoSetDiv,
                        $parentContainer
                    );
                }
            } else { // Otherwise
                $iframeParent = $iframe->parentNode;

                // Replace iframe directly with video embed set
                if ($iframeParent instanceof \DOMNode) {
                    $iframeParent->replaceChild(
                        $videoSetDiv,
                        $iframe
                    );
                }
            }

            // Clean up variables to free up memory
            unset(
                $iframe,
                $iframeSrc,
                $videoData,
                $parentDiv,
                $align,
                $parentClass,
                $caption,
                $values,
                $videoSetDiv,
                $parentContainer,
                $key,
                $value
            );
        }

        // Extract inner HTML from DOMDocument
        $convertedHtml = TextProcessor::getInnerHtml($doc);

        // Clean up variables to free up memory
        unset(
            $doc,
            $xpath,
            $iframes,
            $i
        );

        // Return converted HTML
        return $convertedHtml;
    }

    /**
     * Extracts video URL and provider information from an iframe src attribute.
     *
     * Parses YouTube and Vimeo embed URLs to extract the watch URL and provider
     * name. Converts YouTube embed URLs (e.g., youtube.com/embed/VIDEO_ID) to watch
     * URLs (e.g., youtube.com/watch?v=VIDEO_ID). Extracts Vimeo video IDs from
     * embed URLs.
     *
     * @param  string  $iframeSrc  The iframe src attribute value
     * @return array<int|string, mixed>|null Array with 'url' and 'provider' keys, or null if extraction failed
     */
    private function extractVideoDataFromIframeSrc(
        string $iframeSrc
    ): ?array {
        // Check if this is a YouTube embed URL
        if (
            \preg_match(
                '#^(?:https?:)?//(?:www\.|m\.)?(?:youtube\.com|youtube-nocookie\.com)/embed/([a-zA-Z0-9_-]+)#i',
                $iframeSrc,
                $matches
            ) === 1
        ) {
            // Extract video ID from embed URL
            $videoId = $matches[1];

            // Convert embed URL to watch URL
            $watchUrl = "https://www.youtube.com/watch?v={$videoId}";

            // Return video data
            return [
                'url' => $watchUrl,
                'provider' => 'youtube',
            ];
        }

        // Check if this is a Vimeo embed URL
        if (
            \preg_match(
                '#^(?:https?:)?//(?:www\.|player\.)?vimeo\.com/video/(\d+)#i',
                $iframeSrc,
                $matches
            ) === 1
        ) {
            // Extract video ID from embed URL
            $videoId = $matches[1];

            // Convert embed URL to watch URL
            $watchUrl = "https://vimeo.com/{$videoId}";

            // Return video data
            return [
                'url' => $watchUrl,
                'provider' => 'vimeo',
            ];
        }

        // Check if this is already a YouTube watch URL
        if (
            \preg_match(
                '#^(?:https?:)?//(?:www\.|m\.)?youtube\.com/watch\?v=([a-zA-Z0-9_-]+)#i',
                $iframeSrc,
                $matches
            ) === 1
        ) {
            // Return video data with original URL
            return [
                'url' => $iframeSrc,
                'provider' => 'youtube',
            ];
        }

        // Check if this is already a Vimeo watch URL
        if (
            \preg_match(
                '#^(?:https?:)?//(?:www\.)?vimeo\.com/(\d+)#i',
                $iframeSrc,
                $matches
            ) === 1
        ) {
            // Return video data with original URL
            return [
                'url' => $iframeSrc,
                'provider' => 'vimeo',
            ];
        }

        // Return null for unsupported video providers
        return null;
    }

    /**
     * Decode JSON-encoded Unicode content strings from ACF WYSIWYG fields.
     *
     * @param  string  $contentContent  The raw content string from WordPress
     * @return string The decoded content string
     */
    private static function decodeContentString(
        string $contentContent
    ): string {
        $decodedContent = \json_decode(
            '"'.\str_replace(
                '"',
                '\"',
                $contentContent
            ).'"',
            false,
            512
        );

        if (\is_string($decodedContent)) {
            return $decodedContent;
        }

        return $contentContent;
    }
}
