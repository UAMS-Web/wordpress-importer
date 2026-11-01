<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMElement;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Processes WordPress [caption] shortcodes into Statamic image Bard sets.
 *
 * Parses [caption] shortcodes
 * (e.g., [caption id="attachment_380" align="alignright" width="300"]<img ...> Caption text[/caption])
 * to extract image attributes and caption text, then delegates to ImageProcessor
 * to generate a Statamic image Bard set. Returns null on import failures to
 * prevent invalid sets. Normalizes align attribute for compatibility with
 * AlignmentExtractor.
 *
 * Automatically removes any anchor tags that wrap images within caption
 * shortcodes to prevent unwanted link behavior in the imported content.
 */
class CaptionShortcodeProcessor implements ShortcodeProcessorInterface
{
    /**
     * Instance of ImageProcessor for generating image Bard sets.
     */
    private ImageProcessor $imageProcessor;

    /**
     * Constructs a new CaptionShortcodeProcessor instance.
     *
     * Initializes the ImageProcessor dependency for consistent image set creation.
     *
     * @param  ?ImageProcessor  $imageProcessor  Optional ImageProcessor instance
     */
    public function __construct(
        ?ImageProcessor $imageProcessor = null
    ) {
        $this->imageProcessor = $imageProcessor ?? new ImageProcessor;
    }

    /**
     * Parses a [caption] shortcode into a Statamic image Bard set.
     *
     * Extracts the <img> tag and caption text, normalizes the align attribute,
     * and uses ImageProcessor to generate a Bard set. Returns null if no <img>
     * tag is found or processing fails, ensuring invalid sets are skipped.
     *
     * Automatically removes any anchor tags that wrap the image to prevent
     * unwanted link behavior in the imported content. Shortcode content is
     * accessed through ProcessingContext.
     *
     * @param  array<int|string, mixed>  $attributes  Shortcode attributes (e.g., ['align' => 'alignright', 'width' => '300'])
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML (unused)
     * @param  ?string  $pageSlug  The page slug for logging
     * @return array<int|string, mixed>|null The Statamic image Bard set configuration or null on failure
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): ?array {
        // Get shortcode content from ProcessingContext
        $content = ProcessingContext::getShortcodeContent();

        if ($content === null || $content === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                ]
            );

            // Log warning about missing content
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'CaptionShortcodeProcessor::parse: No content provided for [caption] shortcode',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // Extract <img> tag and caption text
        $doc = TextProcessor::loadHtml($content);
        $img = $doc->getElementsByTagName('img')->item(0);

        if ($img === null) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'content_snippet' => \substr($content, 0, 100).(\strlen($content) > 100 ? '...' : ''),
                ]
            );

            // Log warning about missing img tag
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'CaptionShortcodeProcessor::parse: No <img> tag found in [caption] content',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // Get the parent node of the image element
        $parent = $img->parentNode;

        // Check if the image is wrapped in an anchor tag. Only an element carries that
        // name, so the type check narrows the parent and decides nothing.
        // @pest-mutate-ignore: InstanceOfToTrue
        if (
            $parent instanceof DOMElement
            && $parent->nodeName === 'a'
        ) {
            // Put the image where the link was, which removes the link
            $parent->replaceWith($img);
        }

        $imgHtml = $doc->saveHTML($img);

        // Stop processing when image HTML serialization fails. A node of its own
        // document always serializes, so this only narrows the type.
        // @pest-mutate-ignore: FalseToTrue
        if ($imgHtml === false) {
            return null;
        }

        // The image's HTML came from this document, so the match below replaces this
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $captionText = '';

        // Extract caption text (content after <img> tag)
        $contentText = TextProcessor::getInnerHtml($doc);
        $captionMatch = \preg_match(
            '/'.preg_quote(
                $imgHtml,
                '/'
            ).'\s*(.*)/s',
            $contentText,
            $matches
        );
        if ($captionMatch === 1) {
            $captionText = $this->unwrapParagraphs($matches[1]);
        }

        $blockAttributes = [];
        if (isset($attributes['align'])) {
            // Normalize align input to string before regex replacement
            $alignInput = \is_scalar($attributes['align'])
                ? (string) $attributes['align']
                : '';

            // Normalize align attribute by removing 'align' prefix. This pattern cannot
            // fail, so the fallback only narrows the type.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $blockAttributes['align'] = \preg_replace(
                '/^align/',
                '',
                $alignInput
            ) ?? '';
        } else {
            $blockAttributes['align'] = 'center';
        }

        // Extract the image source URL from the img element
        $imgSrc = $img->getAttribute('src');

        // Get the base blog URL for same-site comparison
        $baseBlogUrl = $assetImporter->getBaseBlogUrl();

        // Check if image URL is on the same site before using asset ID
        $isSameSite = AssetUtils::isUrlOnSameSite(
            $imgSrc,
            $baseBlogUrl
        );

        // Only use asset ID if the image is on the same site
        if (
            $isSameSite
            && isset($attributes['id'])
            && \is_scalar($attributes['id'])
            && \preg_match(
                '/attachment_(\d+)/',
                (string) $attributes['id'],
                $idMatches
            ) === 1
        ) {
            $blockAttributes['id'] = $idMatches[1];
        }

        // Get shortcode attachments from context for image processing
        $contextAttachments = ProcessingContext::getAttachments();

        // Fall back to empty attachments array when context is unavailable
        if (! \is_array($contextAttachments)) {
            $contextAttachments = [];
        }

        // Delegate to ImageProcessor
        $set = $this->imageProcessor->processImage(
            $imgHtml,
            $site,
            $assetImporter,
            $output,
            $contextAttachments,
            $pageSlug,
            $blockAttributes
        );

        if ($set === null || $set === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'img_html' => $imgHtml,
                ]
            );

            // Log warning about failed image processing
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'CaptionShortcodeProcessor::parse: Failed to process image for [caption] shortcode',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // Ensure caption is included if present
        // Extract values from the parsed set for safe caption mutation
        $setValues = $this->extractSetValues($set);

        // Add caption value when missing from parsed image set values
        if (
            $captionText !== ''
            && (
                ! isset($setValues['caption'])
                || $setValues['caption'] === ''
            )
        ) {
            $setValues['caption'] = $captionText;
            $set = $this->applySetValues(
                $set,
                $setValues
            );
        }

        return $set;
    }

    /**
     * Convert a [caption] shortcode to Tiptap-compatible HTML.
     *
     * Converts caption shortcode data into HTML that can be parsed by Tiptap/Bard.
     * For caption shortcodes, we exclude the content to prevent duplicate caption
     * text, as the caption is already stored as an attribute.
     *
     * @param  array<int|string, mixed>  $shortcode  Shortcode data (['name' => '...', 'attributes' => [...], 'content' => '...'])
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of attachment objects for image processing
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return string Tiptap-compatible HTML
     */
    public function toTiptapHtml(
        array $shortcode,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): string {
        // Set ProcessingContext for the shortcode processor
        $shortcodeContent = $this->extractNullableString(
            $shortcode['content'] ?? null
        );
        ProcessingContext::setShortcodeContext(
            $attachments,
            $shortcodeContent
        );

        // Normalize shortcode attributes to a safe array structure
        $shortcodeAttributes = \is_array($shortcode['attributes'] ?? null)
            ? $shortcode['attributes']
            : [];

        // Parse the shortcode to get the image set data
        $set = $this->parse(
            $shortcodeAttributes,
            $site,
            $assetImporter,
            $output,
            $terms,
            $pageSlug
        );

        // Check if the image set was successfully created
        if ($set === null || $set === []) {
            // Keep the text of a caption that holds no image, and report it (#164)
            if (
                $shortcodeContent !== null
                && $shortcodeContent !== ''
                && ! $this->containsImage($shortcodeContent)
            ) {
                $this->reportImagelessCaption(
                    $shortcodeContent,
                    $site,
                    $pageSlug
                );

                return $shortcodeContent;
            }

            // Return empty string if parsing failed
            return '';
        }

        // Extract values from the parsed set structure
        $values = $this->extractSetValues($set);

        // Set the type for image sets
        $setType = 'image';

        // Use shared utility method to generate Tiptap HTML
        // For caption shortcodes, don't include content as it creates duplicate caption text
        return ShortcodeProcessor::generateTiptapHtml(
            $values,
            $setType,
            '' // Empty content to prevent duplicate caption text
        );
    }

    /**
     * Removes the paragraphs WpAutoP puts around a caption's text.
     *
     * WpAutoP treats an <img> as a block, so it closes the paragraph before a bare
     * image in a [caption] and opens one for the text after it (#330). A linked
     * image reaches the same point with none. Any other markup in the caption stays.
     *
     * @param  string  $caption  The caption's HTML
     * @return string The caption with no paragraph tags or surrounding space
     */
    private function unwrapParagraphs(
        string $caption
    ): string {
        // Remove the tags, leaving the caption as it is if the pattern fails
        $caption = \preg_replace(
            '#</?p(?:\s[^>]*)?>#i',
            '',
            $caption
        ) ?? $caption;

        return \trim($caption);
    }

    /**
     * Whether caption content holds an <img> element.
     *
     * @param  string  $content  The caption shortcode's content
     * @return bool True when the content holds an image
     */
    private function containsImage(
        string $content
    ): bool {
        return TextProcessor::loadHtml($content)
            ->getElementsByTagName('img')
            ->length > 0;
    }

    /**
     * Records a caption that holds no image for the run summary.
     *
     * Its text stays in the imported content, so the report names the site, the
     * page and the start of that text for an editor to check.
     *
     * @param  string  $content  The caption shortcode's content
     * @param  string  $site  The Statamic site handle
     * @param  ?string  $pageSlug  The slug of the page being processed
     */
    private function reportImagelessCaption(
        string $content,
        string $site,
        ?string $pageSlug
    ): void {
        ImportWarningManager::addWarning(
            'content',
            'imageless_captions',
            [
                'site' => $site,
                'page_slug' => $pageSlug ?? '',
                'page_link' => ProcessingContext::getWordPressPageLink() ?? '',
                'content_snippet' => \mb_substr($content, 0, 100)
                    .(\mb_strlen($content) > 100 ? '...' : ''),
                'reason' => 'The [caption] holds no image, so its text was kept '
                    .'in the content as written.',
            ]
        );
    }

    /**
     * Extract normalized value payload from an image set structure.
     *
     * @param  array<int|string, mixed>  $set  Parsed image set
     * @return array<int|string, mixed> Normalized values payload
     */
    private function extractSetValues(
        array $set
    ): array {
        // Return nested values when attrs.values exists as an array
        if (
            isset($set['attrs'])
            && \is_array($set['attrs'])
            && isset($set['attrs']['values'])
            && \is_array($set['attrs']['values'])
        ) {
            return $set['attrs']['values'];
        }

        // Return top-level set values when already a plain array payload
        return $set;
    }

    /**
     * Apply normalized values payload back to an image set structure.
     *
     * @param  array<int|string, mixed>  $set  Parsed image set
     * @param  array<int|string, mixed>  $values  Normalized values payload
     * @return array<int|string, mixed> Updated parsed set
     */
    private function applySetValues(
        array $set,
        array $values
    ): array {
        // Check if set uses nested attrs.values structure
        if (
            isset($set['attrs'])
            && \is_array($set['attrs'])
        ) {
            // Update nested values payload on structured set data
            $set['attrs']['values'] = $values;

            return $set;
        }

        // Return values as top-level payload for flat set structures
        return $values;
    }

    /**
     * Extract nullable string values from shortcode payloads.
     *
     * @param  mixed  $value  Raw shortcode value
     * @return string|null Normalized nullable string
     */
    private function extractNullableString(
        mixed $value
    ): ?string {
        // Return scalar values as strings, and null for anything else
        return \is_scalar($value) ? (string) $value : null;
    }
}
