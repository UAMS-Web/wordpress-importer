<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor;

use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\BlockProcessorInterface;

/**
 * Processes WordPress wp:image blocks for the image processing pipeline.
 *
 * Handles wp:image block comments and their associated <figure> elements,
 * whether direct siblings or wrapped in a <div>, delegating image processing
 * to the ImageProcessor coordinator.
 */
class WpImageBlockProcessor implements BlockProcessorInterface
{
    /**
     * Instance of ImageProcessor for processing images.
     */
    private ImageProcessor $imageProcessor;

    /**
     * Constructs a new WpImageBlockProcessor instance.
     *
     * Initializes the ImageProcessor dependency, creating a new instance if none
     * is provided.
     *
     * @param  ?ImageProcessor  $imageProcessor  Optional ImageProcessor instance
     */
    public function __construct(
        ?ImageProcessor $imageProcessor = null
    ) {
        $this->imageProcessor = $imageProcessor ?? new ImageProcessor;
    }

    /**
     * Determines if the processor can handle the given DOM node.
     *
     * Checks if the node is a comment node starting with 'wp:image', indicating a
     * WordPress image block.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a wp:image comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        if ($node->nodeType !== XML_COMMENT_NODE) {
            return false;
        }

        // Normalize comment text before matching a wp:image marker.
        $commentText = is_string($node->nodeValue) ? $node->nodeValue : '';

        return \preg_match(
            '/^wp:image\s*({.*?})\s*$/',
            \trim($commentText)
        ) === 1;
    }

    /**
     * Processes a wp:image block into Tiptap-compatible HTML.
     *
     * Extracts the <figure> element following the wp:image comment, either as a
     * direct sibling or within a <div>. Delegates processing to ImageProcessor to
     * create a Statamic Bard image set. Returns an array with Tiptap-compatible
     * HTML, the processed node, and nodes to skip, or null if processing fails.
     *
     * @param  DOMNode  $node  The wp:image comment node
     * @param  array<int|string, mixed>  $attributes  Block attributes from the wp:image comment
     * @param  string  $site  Site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Media attachments for alt text lookup
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|null Array with 'html', 'processedNode', 'skipCount', or null
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
    ): ?array {
        // Get the DOM document containing the node for XPath queries
        $doc = $node->ownerDocument;
        if (! $doc instanceof \DOMDocument) {
            return null;
        }

        // Initialize XPath for querying sibling or nested elements
        $xpath = new DOMXPath($doc);
        // Search for <figure> as a direct sibling or within a following <div>
        $figureNodes = $xpath->query(
            'following-sibling::figure | following-sibling::div/figure[1]',
            $node
        );

        // Select the first <figure> node found; a constant expression against a
        // node of this document cannot fail, so false reads as no figure
        $figureNode = $figureNodes === false ? null : $figureNodes->item(0);

        // Check if no <figure> element is found
        if (! $figureNode instanceof DOMElement) {
            // Capture the next sibling's HTML for logging, if it exists
            $nextSibling = $node->nextSibling instanceof DOMNode
                ? $this->normalizeSavedHtml($doc->saveHTML($node->nextSibling))
                : 'none';
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'attributes' => $attributes,
                    'next_sibling_snippet' => \substr($nextSibling, 0, 100).(\strlen($nextSibling) > 100 ? '...' : ''),
                ]
            );

            // Log a warning for missing <figure> with context
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'WpImageBlockProcessor::process: No valid wp:image figure found after comment',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate processing failure
            return null;
        }

        // Check for an <img> tag within the <figure>
        $img = $figureNode->getElementsByTagName('img')->item(0);
        // Validate the presence of an <img> tag
        if ($img === null) {
            // Enhance log context with entry information
            $figureHtml = $this->normalizeSavedHtml(
                $doc->saveHTML($figureNode)
            );
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'html_snippet' => \substr($figureHtml, 0, 100).'...',
                ]
            );

            // Log a warning if no <img> tag is found, including figure HTML snippet
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'WpImageBlockProcessor::process: No img tag found in wp:image block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate processing failure
            return null;
        }

        // ---
        // Unwrap any <a> tags that wrap the <img> tag
        // ---

        // Get the parent node of the <img> tag
        $parentNode = $img->parentNode;

        // Check if the parent node is an <a> tag
        if (
            $parentNode !== null
            && $parentNode->nodeName === 'a'
        ) {
            // Get the <a> tag node
            $aNode = $parentNode;

            // Get the <figure> node to insert the <img> into
            $figureParent = $aNode->parentNode;

            // An <a> found inside the <figure> always has a parent
            if ($figureParent !== null) {
                // Move the <img> out of the <a> tag and into the <figure>
                $figureParent->insertBefore($img, $aNode);

                // Remove the now-empty <a> tag
                $figureParent->removeChild($aNode);
            }
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

        // Extract the <figure> element's HTML for processing
        $html = $this->normalizeSavedHtml($doc->saveHTML($figureNode));

        // Create a copy of attributes to modify for processing
        $modifiedAttributes = $attributes;

        // Remove ID attribute if image is not on the same site
        if (! $isSameSite) {
            // Remove asset ID from attributes for different-site images
            unset($modifiedAttributes['id']);
        }

        // Delegate image processing to ImageProcessor with block attributes
        $set = $this->imageProcessor->processImage(
            $html,
            $site,
            $assetImporter,
            $output,
            $attachments,
            $pageSlug,
            $modifiedAttributes
        );

        // Check if ImageProcessor failed to generate a Bard image set
        if ($set === null || $set === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'html_snippet' => \substr($html, 0, 100).'...',
                ]
            );

            // Log a warning for failed image set generation with HTML snippet
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'WpImageBlockProcessor::process: ImageProcessor failed to generate image set',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate processing failure
            return null;
        }

        // Initialize empty attributes string for Tiptap HTML
        $attrs = '';
        // Iterate through image set attributes to build Tiptap-compatible data attributes
        $setValues = [];
        $setAttributes = $set['attrs'] ?? [];
        if (
            is_array($setAttributes)
            && isset($setAttributes['values'])
            && is_iterable($setAttributes['values'])
        ) {
            $setValues = $setAttributes['values'];
        }

        foreach ($setValues as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            // Exclude the 'type' key to avoid redundant data-set-type attribute
            if ($key !== 'type') {
                // Format each attribute as data-set-attr-<key> with escaped values
                $attrs .= \sprintf(
                    ' data-set-attr-%s="%s"',
                    htmlspecialchars($key),
                    htmlspecialchars(\is_scalar($value) ? (string) $value : '')
                );
            }
        }

        // Construct Tiptap-compatible HTML div for the image set
        $html = \sprintf(
            '<div data-set-type="image"%s%s></div>',
            SetMarkupNonce::attribute(),
            $attrs
        );

        // Determine number of nodes to skip based on DOM structure
        $figureParentNode = $figureNode->parentNode;
        $skipCount = $figureParentNode instanceof DOMNode
            && $figureParentNode->nodeName === 'div'
                ? 3
                : 2;
        // If <figure> is inside a <div>, skip 3 nodes (comment, div, closing comment)
        // Otherwise, skip 2 nodes (comment, figure)

        // Return the processing result with HTML, processed node, and skip count
        return [
            'html' => $html, // Tiptap-compatible HTML
            'processedNode' => $figureNode, // The processed <figure> node
            'skipCount' => $skipCount, // Nodes to skip in further DOM traversal
        ];
    }

    /**
     * Normalize DOM saveHTML return values to a string.
     *
     * @param  string|false  $html  Raw saveHTML output
     * @return string Normalized HTML string
     */
    private function normalizeSavedHtml(
        string|false $html
    ): string {
        // Return an empty string for failed saveHTML calls.
        return $html === false ? '' : $html;
    }
}
