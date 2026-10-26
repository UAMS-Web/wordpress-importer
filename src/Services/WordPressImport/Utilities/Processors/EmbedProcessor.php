<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Utilities\Alignment;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Utilities\VideoSetType;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Processes WordPress wp:embed and wp:core-embed blocks into Tiptap-compatible
 * HTML for Statamic Bard sets.
 */
class EmbedProcessor implements BlockProcessorInterface
{
    /**
     * Determines if the processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a wp:embed or wp:core-embed comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        // Only comment nodes can carry a WordPress block marker
        if (! $node instanceof DOMComment) {
            return false;
        }

        // Check if comment matches wp:embed or wp:core-embed block pattern
        return \preg_match(
            '/^wp:(?:core-)?embed(?:\/[^\s]+)?(?:\s*({.+?}))?\s*$/s',
            \trim($node->data)
        ) === 1;
    }

    /**
     * Processes a wp:embed or wp:core-embed block into Tiptap-compatible HTML.
     *
     * @param  DOMNode  $node  The DOM node (wp:embed or wp:core-embed comment)
     * @param  array<int|string, mixed>  $attributes  Block attributes from the embed comment
     * @param  string  $site  Site handle for context
     * @param  AssetImporter  $assetImporter  Asset importer instance (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects (unused)
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|string|null Array with 'html', 'processedNode', and 'skipCount', Tiptap HTML string, or null if invalid
     */
    public function process(
        DOMNode $node,
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): array|string|null {
        // Extract embed type from attributes
        $embedType = $attributes['type'] ?? '';

        // Check if embed type is missing but URL suggests a video embed
        if (
            $embedType === ''
            && isset($attributes['url'])
        ) {
            // Extract URL from attributes
            $url = $attributes['url'];

            // Check if the URL belongs to a platform-specific video set
            if (
                \is_string($url)
                && VideoSetType::forUrl($url) !== null
            ) {
                // Treat as video embed when URL matches video providers
                $embedType = 'video';
            }
        }

        // Route to appropriate processor based on embed type
        return match ($embedType) {
            // Process video embed blocks
            'video' => $this->processVideoEmbed(
                $node,
                $attributes,
                $site,
                $pageSlug
            ),

            // Unsupported embed type
            default => $this->handleUnsupportedEmbed(
                $attributes,
                $site,
                $pageSlug
            ),
        };
    }

    /**
     * Handles unsupported embed types by collecting warning data and converting to
     * paragraph with link.
     *
     * Collects warning information when an unsupported embed provider is encountered,
     * including the provider name and embed type if available. Warnings are displayed
     * at the end of the import process. If a URL is available, converts the embed to a
     * paragraph containing a link to the URL.
     *
     * @param  array<int|string, mixed>  $attributes  Block attributes from the embed comment
     * @param  string  $site  Site handle for context
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return ?string HTML paragraph with link if URL is available, null otherwise
     */
    private function handleUnsupportedEmbed(
        array $attributes,
        string $site,
        ?string $pageSlug = null
    ): ?string {
        // Extract embed type from attributes
        $embedType = $this->extractStringAttribute(
            $attributes,
            'type',
            'none'
        );

        // Extract provider name from attributes
        $providerName = $this->extractStringAttribute(
            $attributes,
            'providerNameSlug',
            ''
        );

        // Extract URL from attributes if available
        $url = $this->extractStringAttribute(
            $attributes,
            'url',
            ''
        );

        // Add warning to centralized warning manager
        ImportWarningManager::addWarning(
            'content',
            'unsupported_embeds',
            [
                'embed_type' => $embedType,
                'provider_name' => $providerName !== '' ? $providerName : 'unknown',
                'url' => $url,
                'site' => $site,
                'page_slug' => $pageSlug,
            ]
        );

        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            [
                'site' => $site,
                'pageSlug' => $pageSlug,
                'providerNameSlug' => $providerName,
                'url' => $url,
                'attributes' => $attributes,
            ]
        );

        // Log warning for debugging
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                "EmbedProcessor::process: Unsupported embed type '{$embedType}' with provider '".($providerName !== '' ? $providerName : 'unknown')."'",
                $logData['page_url']
            ),
            $logData['context']
        );

        // Check if URL is available
        if ($url === '') {
            // Unset local variables
            unset(
                $embedType,
                $providerName,
                $url
            );

            // Return null if no URL is available
            return null;
        }

        // Escape URL for HTML attribute
        $escapedUrl = htmlspecialchars(
            $url,
            ENT_QUOTES,
            'UTF-8'
        );

        // Escape URL for link text
        $escapedUrlText = htmlspecialchars(
            $url,
            ENT_NOQUOTES,
            'UTF-8'
        );

        // Generate paragraph with link to URL
        $html = \sprintf(
            '<p><a href="%s">%s</a></p>',
            $escapedUrl,
            $escapedUrlText
        );

        // Unset local variables
        unset(
            $embedType,
            $providerName,
            $url,
            $escapedUrl,
            $escapedUrlText
        );

        // Return HTML paragraph with link
        return $html;
    }

    /**
     * Processes a wp:embed video block.
     *
     * Handles embed blocks with opening and closing comments (e.g., <!-- wp:embed
     * {...} --> ... <!-- /wp:embed -->) that contain video embeds. Extracts video
     * information from attributes and the figure element.
     *
     * @param  DOMNode  $node  The DOM node (wp:embed opening comment)
     * @param  array<int|string, mixed>  $attributes  Block attributes from the embed comment
     * @param  string  $site  Site handle for context
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|null Array with 'html', 'processedNode', and 'skipCount', or null if invalid
     */
    private function processVideoEmbed(
        DOMNode $node,
        array $attributes,
        string $site,
        ?string $pageSlug = null
    ): ?array {
        // Get document object from node for XPath queries
        $doc = $node->ownerDocument;

        // Stop processing when node is not attached to a DOM document
        if (! $doc instanceof DOMDocument) {
            return null;
        }

        // Create XPath query object for finding elements
        $xpath = new DOMXPath($doc);

        // Query for figure element containing wp-block-embed class after the comment node
        $figureNodes = $xpath->query(
            'following-sibling::figure[contains(@class, "wp-block-embed")][1]',
            $node
        );

        // Select the first figure found. query() returns false only for a
        // malformed expression or context node, which this constant expression
        // and in-document node cannot be.
        // @pest-mutate-ignore: FalseToTrue
        $figureNode = $figureNodes === false ? null : $figureNodes->item(0);

        // Check if figure node is missing (invalid embed block structure)
        if (! $figureNode instanceof DOMElement) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug,
                    'attributes' => $attributes,
                ]
            );

            // Log warning for missing figure element
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'EmbedProcessor::processVideoEmbed: No valid wp:embed figure found',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null for invalid block structure
            return null;
        }

        // Get wrapper div element from figure node
        $wrapper = $figureNode->getElementsByTagName('div')->item(0);

        // Check if wrapper div is missing or doesn't have wp-block-embed__wrapper class
        if (
            $wrapper === null
            || \preg_match(
                '/\bwp-block-embed__wrapper\b/',
                $wrapper->getAttribute('class')
            ) !== 1
        ) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug,
                    'node_html' => $this->buildNodeHtmlSnippet(
                        $doc,
                        $figureNode
                    ),
                ]
            );

            // Log warning for invalid wrapper structure
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'EmbedProcessor::processVideoEmbed: No valid wp-block-embed__wrapper div found in wp:embed block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null for invalid wrapper
            return null;
        }

        // Extract video URL from block attributes
        $videoUrl = $this->extractStringAttribute(
            $attributes,
            'url',
            ''
        );

        // Check if video URL is missing
        if ($videoUrl === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug,
                    'node_html' => $this->buildNodeHtmlSnippet(
                        $doc,
                        $figureNode
                    ),
                ]
            );

            // Log warning for missing video URL
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'EmbedProcessor::processVideoEmbed: No video URL found in wp:embed block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null for missing URL
            return null;
        }

        // Extract provider name from figure element class attribute
        $provider = \preg_match(
            '/is-provider-(\w+)/',
            $figureNode->getAttribute('class'),
            $matches
        ) === 1 ? \strtolower($matches[1]) : '';

        // ---
        // Resolve the platform-specific Bard set that hosts this URL, and rewrite the
        // URL into the shape that set's own validation accepts. #589 retired the
        // single `video` set for `youtube` / `vimeo` / `box`, so a URL that resolves
        // to none of them has no set to land on at all (wordpress-importer-archive#298).
        // ---
        $resolved = VideoSetType::resolve($videoUrl);

        $setType = $resolved['type'] ?? null;

        // Check if the provider is unsupported (no platform-specific set hosts it)
        if ($setType === null || $resolved === null) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug,
                    'provider' => $provider !== '' ? $provider : 'none',
                    'video_url' => $videoUrl,
                ]
            );

            // Log warning for unsupported provider
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'EmbedProcessor::processVideoEmbed: Unsupported provider in wp:embed block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null for unsupported provider
            return null;
        }

        // Extract alignment input from attributes or figure class
        $alignInput = $this->extractStringAttribute(
            $attributes,
            'align',
            ''
        );
        if ($alignInput === '') {
            $alignInput = $figureNode->getAttribute('class');
        }

        // Translate alignment to Statamic format
        $align = Alignment::translate(
            $alignInput,
            false
        );

        // Initialize caption string
        $caption = '';

        // Loop through child nodes to find figcaption element
        foreach ($figureNode->childNodes as $child) {
            // Check if child is a figcaption element
            if (
                $child instanceof DOMElement
                && $child->nodeName === 'figcaption'
            ) {
                // Extract caption text content
                $caption = \trim($child->textContent);

                // Break loop after finding first caption
                break;
            }
        }

        // ---
        // Build video set values. The URL handle is `url`, not `video_url`, and
        // `embed_mode` is written explicitly: a WordPress embed carries no transcript,
        // creators, or transparency metadata, so the `full` segment mode is never
        // justified by the source data (wordpress-importer-archive#298).
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

        // Generate Tiptap HTML from values
        $html = $this->buildVideoSetHtml(
            $setType,
            $values
        );

        // Return processing result with HTML, processed node, and skip count
        return [
            'html' => $html,
            'processedNode' => $figureNode,
            'skipCount' => 3, // Skip opening comment, figure node, and closing comment
        ];
    }

    /**
     * Builds Tiptap HTML for a video set from values array.
     *
     * Converts a values array into Tiptap-compatible HTML with data-set-type and
     * data-set-attr attributes for Statamic Bard video sets.
     *
     * @param  string  $setType  The platform-specific set handle (`youtube` / `vimeo` / `box`)
     * @param  array<string, string>  $values  Array of video set values (type, url, embed_mode, aspect_ratio, align, caption)
     * @return string Tiptap HTML string
     */
    private function buildVideoSetHtml(
        string $setType,
        array $values
    ): string {
        // Initialize HTML attributes string
        $attrs = '';

        // Loop through values to build HTML attributes
        foreach ($values as $key => $value) {
            // Check if key is not 'type' (type is used in data-set-type attribute)
            if ($key !== 'type') {
                // Append attribute to HTML attributes string
                $attrs .= \sprintf(
                    ' data-set-attr-%s="%s"',
                    htmlspecialchars($key),
                    htmlspecialchars($value)
                );
            }
        }

        // Generate Tiptap HTML div element with video set attributes
        return \sprintf(
            '<div data-set-type="%s"%s%s></div>',
            htmlspecialchars($setType),
            SetMarkupNonce::attribute(),
            $attrs
        );
    }

    /**
     * Extract a scalar attribute value as string.
     *
     * @param  array<int|string, mixed>  $attributes  Attributes array from block
     * @param  string  $key  Attribute key to extract
     * @param  string  $default  Default value when key is missing or non-scalar
     * @return string Normalized attribute string value
     */
    private function extractStringAttribute(
        array $attributes,
        string $key,
        string $default
    ): string {
        // Return default when attribute key is missing
        if (! \array_key_exists($key, $attributes)) {
            return $default;
        }

        // Get raw attribute value
        $value = $attributes[$key];

        // Return scalar values as strings
        if (\is_scalar($value)) {
            return (string) $value;
        }

        // Return default for non-scalar values
        return $default;
    }

    /**
     * Build a short HTML snippet for logging.
     *
     * @param  DOMDocument  $doc  The document the node belongs to
     * @param  DOMNode  $node  Node to serialize for diagnostics
     * @return string Serialized HTML snippet (max 100 chars)
     */
    private function buildNodeHtmlSnippet(
        DOMDocument $doc,
        DOMNode $node
    ): string {
        // Return a short snippet to avoid oversized log payloads. saveHTML() of
        // a node in its own document does not fail, so the cast only satisfies
        // the string|false return type.
        return \substr(
            (string) $doc->saveHTML($node),
            0,
            100
        ).'...';
    }
}
