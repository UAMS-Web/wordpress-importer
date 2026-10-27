<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use DOMElement;
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
     * The heading a block with body content but no heading receives.
     */
    private const string DEFAULT_HEADING = 'Content Block Title - Please Replace';

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
     * empty, sets heading to the default placeholder.
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

        // Normalize field names using AcfFieldNormalizer
        $normalizedData = AcfFieldNormalizer::normalize(
            \is_array($dataRaw) ? $dataRaw : [],
            $this->getFieldMappings()
        );

        $set = $this->buildSet($normalizedData, $site, $assetImporter, $output, $attachments, $terms);

        // Skip the block if its body is empty, whatever its heading
        if ($set['body'] === []) {
            // Get entry data for post_name access
            $entryData = ProcessingContext::getEntryData();

            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'block_heading' => $set['block_heading'],
                    'heading_is_default' => $set['block_heading'] === self::DEFAULT_HEADING,
                    'body_content_empty' => true,
                    'content_content_empty' => self::contentString($normalizedData) === '',
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

            return null;
        }

        // Give a block with body content but no heading the placeholder
        if ($set['block_heading'] === '') {
            $set['block_heading'] = self::DEFAULT_HEADING;
        }

        return $set;
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
        return $this->buildSet(
            AcfFieldNormalizer::normalize($moduleData, $this->getFieldMappings()),
            $site,
            $assetImporter,
            $output,
            $attachments,
            $terms
        );
    }

    /**
     * Builds the uamsweb_block_content set both entry points share.
     *
     * Strips tags from the heading, reads the hide flag and background color, and
     * runs the content_content WYSIWYG through TextProcessor with WpAutoP after
     * decoding its Unicode escapes and converting its iframes to video sets.
     *
     * @param  array<int|string, mixed>  $normalizedData  The normalized block or module data
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output
     * @param  array<int|string, mixed>  $attachments  Array of attachment data
     * @param  array<int|string, mixed>  $terms  Array of terms data
     * @return array{id: mixed, type: string, block_heading: string, hide_block_heading: bool, background_color: string, body: array<int|string, mixed>, enabled: bool} The replicator set
     */
    private function buildSet(
        array $normalizedData,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        array $terms
    ): array {
        $heading = $normalizedData['content_heading'] ?? null;
        $backgroundColor = $normalizedData['content_background_color'] ?? null;
        $contentContent = self::contentString($normalizedData);

        // Process content_content (WYSIWYG) into Bard format
        $bodyContent = [];
        // Empty content parses to an empty body either way; the check only saves the work.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        if ($contentContent !== '') {
            // Convert iframes to video embed sets before processing
            $decodedContent = $this->convertIframesToVideoEmbeds(
                self::decodeContentString($contentContent)
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
            'block_heading' => HtmlTagStripper::strip(
                \is_string($heading) ? $heading : self::DEFAULT_HEADING
            ),
            'hide_block_heading' => ($normalizedData['content_hide_heading'] ?? null) === '1',
            'background_color' => \is_string($backgroundColor) ? $backgroundColor : 'bg-auto',
            'body' => $bodyContent,
            'enabled' => true,
        ];
    }

    /**
     * Reads the content_content WYSIWYG value, or an empty string when it is not one.
     *
     * @param  array<int|string, mixed>  $normalizedData  The normalized block or module data
     * @return string The raw content
     */
    private static function contentString(
        array $normalizedData
    ): string {
        $content = $normalizedData['content_content'] ?? null;

        return \is_string($content) ? $content : '';
    }

    /**
     * Converts iframe elements to video embed sets in HTML content.
     *
     * Detects YouTube and Vimeo iframes in the content and converts them to
     * Statamic video embed set HTML (`data-set-type="youtube"` / `"vimeo"` /
     * `"box"`, routed by `VideoSetType`, wordpress-importer-archive#298) that can be
     * processed by TextProcessor. Extracts video URL, alignment, and caption from
     * the iframe and its parent container. An iframe whose URL routes to no
     * platform-specific set is left alone rather than emitting a set type the Bard
     * field does not define.
     *
     * @param  string  $html  The HTML content containing iframes
     * @return string The HTML content with iframes converted to video embed sets
     */
    private function convertIframesToVideoEmbeds(
        string $html
    ): string {
        // Return content unchanged if no iframes found
        if (! str_contains($html, '<iframe')) {
            return $html;
        }

        // Load HTML into DOMDocument for parsing
        $doc = TextProcessor::loadHtml($html);

        // Copy the live list, since replacing an iframe removes it from the document
        foreach (\iterator_to_array($doc->getElementsByTagName('iframe')) as $iframe) {
            // Extract the watch URL, skipping an iframe from an unsupported provider
            $videoUrl = $this->extractVideoUrlFromIframeSrc($iframe->getAttribute('src'));
            if ($videoUrl === null) {
                continue;
            }

            // Leave an unroutable iframe alone rather than emitting a set type the
            // Bard field does not define, or a URL its validation rejects
            $resolved = VideoSetType::resolve($videoUrl);
            if ($resolved === null) {
                continue;
            }

            // The alignment comes from a parent div's classes, centered otherwise
            $parent = $iframe->parentNode;
            // A loaded iframe always has an element parent, at least the body; PHPStan needs the check.
            // @pest-mutate-ignore: InstanceOfToTrue
            $parentDiv = $parent instanceof DOMElement && $parent->nodeName === 'div' ? $parent : null;
            $align = $parentDiv === null
                ? 'aligncenter'
                : Alignment::translate($parentDiv->getAttribute('class'), false);

            // The caption is the iframe title, without a leading "Video: " prefix
            $caption = \trim($iframe->getAttribute('title'));
            if (str_starts_with($caption, 'Video: ')) {
                $caption = \substr($caption, 7);
            }

            // ---
            // Build video set values. The URL handle is `url`, not `video_url`, and
            // `embed_mode` is written explicitly: a WordPress iframe carries no
            // transcript, creators, or transparency metadata, so the `full` segment
            // mode is never justified by the source data (wordpress-importer-archive#298).
            // ---
            $values = [
                'embed_mode' => VideoSetType::EMBED_MODE_SIMPLE,
                'url' => $resolved['url'],
                'aspect_ratio' => '16by9', // Default per uamsweb_common_video.yaml
                'align' => $align,
            ];

            if ($caption !== '') {
                $values['caption'] = $caption;
            }

            // Create the set div with the platform-specific set handle
            $videoSetDiv = $doc->createElement('div');
            $videoSetDiv->setAttribute('data-set-type', $resolved['type']);

            // Stamp the run nonce so Set::parseHTML() trusts this as our own markup
            $videoSetDiv->setAttribute(SetMarkupNonce::ATTRIBUTE, SetMarkupNonce::value());

            foreach ($values as $key => $value) {
                $videoSetDiv->setAttribute("data-set-attr-{$key}", $value);
            }

            // Replace an embed-responsive container whole, and the iframe otherwise
            $replaced = $parentDiv !== null && str_contains($parentDiv->getAttribute('class'), 'embed-responsive')
                ? $parentDiv
                : $iframe;
            $replaced->replaceWith($videoSetDiv);

            // Clean up variables to free up memory
            unset($iframe, $parent, $parentDiv, $values, $videoSetDiv, $replaced);
        }

        // Extract inner HTML from DOMDocument
        $convertedHtml = TextProcessor::getInnerHtml($doc);

        // Clean up variables to free up memory
        unset($doc);

        return $convertedHtml;
    }

    /**
     * Extracts the video watch URL from an iframe src attribute.
     *
     * Converts YouTube embed URLs (e.g., youtube.com/embed/VIDEO_ID) to watch URLs
     * (e.g., youtube.com/watch?v=VIDEO_ID) and Vimeo player URLs to vimeo.com/ID.
     * A src that is already a YouTube or Vimeo watch URL is returned as written.
     *
     * @param  string  $iframeSrc  The iframe src attribute value
     * @return ?string The watch URL, or null for an unsupported provider
     */
    private function extractVideoUrlFromIframeSrc(
        string $iframeSrc
    ): ?string {
        // Convert a YouTube embed URL to a watch URL
        if (
            \preg_match(
                '#^(?:https?:)?//(?:www\.|m\.)?(?:youtube\.com|youtube-nocookie\.com)/embed/([a-zA-Z0-9_-]+)#i',
                $iframeSrc,
                $matches
            ) === 1
        ) {
            return "https://www.youtube.com/watch?v={$matches[1]}";
        }

        // Convert a Vimeo embed URL to a watch URL
        if (
            \preg_match(
                '#^(?:https?:)?//(?:www\.|player\.)?vimeo\.com/video/(\d+)#i',
                $iframeSrc,
                $matches
            ) === 1
        ) {
            return "https://vimeo.com/{$matches[1]}";
        }

        // Keep a src that is already a YouTube or Vimeo watch URL
        if (
            \preg_match('#^(?:https?:)?//(?:www\.|m\.)?youtube\.com/watch\?v=[a-zA-Z0-9_-]+#i', $iframeSrc) === 1
            || \preg_match('#^(?:https?:)?//(?:www\.)?vimeo\.com/\d+#i', $iframeSrc) === 1
        ) {
            return $iframeSrc;
        }

        return null;
    }

    /**
     * Decode JSON-encoded Unicode content strings from ACF WYSIWYG fields.
     *
     * @param  string  $contentContent  The raw content string from WordPress
     * @return string The decoded content string, or the raw string when it does not decode
     */
    private static function decodeContentString(
        string $contentContent
    ): string {
        $decodedContent = \json_decode(
            '"'.\str_replace('"', '\"', $contentContent).'"'
        );

        return \is_string($decodedContent) ? $decodedContent : $contentContent;
    }
}
