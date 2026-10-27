<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Processes WordPress wp:gallery blocks into Statamic Bard gallery sets.
 *
 * Handles wp:gallery block comments and their associated <figure> elements,
 * converting them to Bard gallery sets with imported images.
 */
class WpGalleryBlockProcessor implements BlockProcessorInterface
{
    /**
     * Determines if the processor can handle the given DOM node.
     *
     * Checks if the node is a comment node starting with 'wp:gallery', indicating a
     * WordPress gallery block.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a wp:gallery comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        // Ignore unused interface parameters for this implementation.
        unset(
            $site,
            $pageSlug
        );

        // Check if the node is a comment node
        if ($node->nodeType !== XML_COMMENT_NODE) {
            // Only process comment nodes
            return false;
        }

        // Ensure comment text is available before matching.
        if (! \is_string($node->nodeValue)) {
            return false;
        }

        // Check if this is a wp:gallery comment
        $canProcess = \preg_match(
            '/^wp:gallery\s*({.*?})\s*$/',
            \trim($node->nodeValue)
        ) === 1;

        // Return whether this is a gallery block
        return $canProcess;
    }

    /**
     * Processes a wp:gallery block into a Statamic Bard gallery set.
     *
     * Extracts the <figure> element following the wp:gallery comment and processes
     * the gallery images to create a Bard gallery set. Returns an array with the
     * Bard gallery set structure.
     *
     * @param  DOMNode  $node  The wp:gallery comment node
     * @param  array<int|string, mixed>  $attributes  Block attributes from the wp:gallery comment
     * @param  string  $site  Site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Media attachments for alt text lookup
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|null Array with Bard gallery set structure, or null if processing fails
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
        if (! $doc instanceof DOMDocument) {
            return null;
        }

        // Initialize XPath for querying sibling or nested elements
        $xpath = new DOMXPath($doc);

        // Search for <figure> as a direct sibling or within a following <div>
        $figureNodes = $xpath->query(
            'following-sibling::figure | following-sibling::div/figure[1]',
            $node
        );

        // Select the first <figure> node found. query() returns false only for a
        // malformed expression or context node, which this constant expression
        // and in-document node cannot be.
        // @pest-mutate-ignore: FalseToTrue
        $figureNode = $figureNodes === false ? null : $figureNodes->item(0);

        // Check if no <figure> element is found
        if (! $figureNode instanceof DOMNode) {
            // Capture the next sibling's HTML for logging, if it exists
            $nextSibling = $node->nextSibling instanceof DOMNode
                ? $this->renderNodeHtml($node->nextSibling, $doc)
                : 'none';

            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'attributes' => $attributes,
                    'next_sibling_snippet' => \substr(
                        $nextSibling,
                        0,
                        100
                    ).(\strlen($nextSibling) > 100 ? '...' : ''),
                ]
            );

            // Log a warning for missing <figure> with context
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'WpGalleryBlockProcessor::process: No valid wp:gallery figure found after comment',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate processing failure
            return null;
        }

        // Extract image IDs from block attributes
        $imageIds = $attributes['ids'] ?? [];
        if (! \is_array($imageIds)) {
            $imageIds = [];
        }

        // Check if there are no IDs in attributes
        if ($imageIds === []) {
            // Try to extract image IDs from the figure element
            $imageIds = $this->extractImageIdsFromFigure($figureNode, $doc);
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

            // Log a warning if no image IDs are found
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'WpGalleryBlockProcessor::process: No image IDs found in wp:gallery block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate processing failure
            return null;
        }

        // Initialize array to store processed image paths
        $imagePaths = [];

        // Loop through each image ID to import images
        foreach ($imageIds as $imageId) {
            if (\is_int($imageId)) {
                $imageId = (string) $imageId;
            }

            if (! \is_string($imageId)) {
                continue;
            }

            // Import image with disabled upload handling using utility method
            $assetPath = ImageProcessor::importImageWithDisabledUploadHandling(
                $imageId,
                $site,
                $assetImporter,
                $output,
                $attachments,
                'gallery image',
                'WpGalleryBlockProcessor::process'
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
                    'WpGalleryBlockProcessor::process: Insufficient images for gallery block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null for insufficient images
            return null;
        }

        // Create the gallery values array
        $values = [
            'heading' => 'Image Gallery',
            'images' => $imagePaths,
            'more_query' => false,
        ];

        // Initialize attributes string
        $attrs = '';

        // Loop through values to build HTML attributes
        foreach ($values as $key => $value) {
            // Handle array values by converting to JSON.
            if (\is_array($value)) {
                // Convert array to JSON string.
                $jsonValue = $this->encodeJsonAttributeValue($value);

                // Add JSON attribute to HTML.
                $attrs .= \sprintf(
                    " data-set-attr-%s='%s'",
                    $key,
                    htmlspecialchars(
                        $jsonValue,
                        ENT_QUOTES,
                        'UTF-8'
                    )
                );

                continue;
            }

            // Convert the scalar gallery values, all constants, to attribute strings.
            $stringValue = $value === false ? 'false' : $value;
            $attrs .= \sprintf(
                ' data-set-attr-%s="%s"',
                $key,
                $stringValue
            );
        }

        // Create Tiptap-compatible HTML for the gallery set
        $html = \sprintf(
            '<div data-set-type="gallery"%s%s></div>',
            SetMarkupNonce::attribute(),
            $attrs
        );

        // Return the processing result with HTML, processed node, and skip count
        return [
            'html' => $html, // Tiptap-compatible HTML
            'processedNode' => $figureNode, // The processed <figure> node
            'skipCount' => 2, // Skip comment and figure nodes
        ];
    }

    /**
     * Extracts image IDs from a figure element containing gallery images.
     *
     * @param  DOMNode  $figureNode  The figure element containing gallery images
     * @param  DOMDocument  $doc  The document the figure belongs to
     * @return array<int|string, mixed> Array of image IDs
     */
    private function extractImageIdsFromFigure(
        DOMNode $figureNode,
        DOMDocument $doc
    ): array {
        // Initialize array to store image IDs
        $imageIds = [];

        // Create XPath instance for querying
        $xpath = new DOMXPath($doc);

        // Find all img elements with data-id attributes. query() returns false
        // only for a malformed expression or context node, which this constant
        // expression and in-document node cannot be.
        $imgNodes = $xpath->query(
            './/img[@data-id]',
            $figureNode
        );

        // Loop through each image node; query() never returns false here, as above.
        // @pest-mutate-ignore: FalseToTrue
        foreach ($imgNodes === false ? [] : $imgNodes as $imgNode) {
            // The expression selects only img elements; the guard narrows the type.
            // @pest-mutate-ignore: InstanceOfToTrue
            if (! $imgNode instanceof DOMElement) {
                continue;
            }

            // Get the data-id attribute value
            $dataId = $imgNode->getAttribute('data-id');

            // Check if the data-id is numeric; an empty string is not
            if (\is_numeric($dataId)) {
                // Add the image ID to our array
                $imageIds[] = $dataId;
            }
        }

        // Return the collected image IDs
        return $imageIds;
    }

    /**
     * Render a DOM node to a safe HTML string.
     *
     * @param  DOMNode  $node  The node to render
     * @param  DOMDocument  $doc  The document the node belongs to
     * @return string Rendered HTML or an empty string
     */
    private function renderNodeHtml(
        DOMNode $node,
        DOMDocument $doc
    ): string {
        // Render the node as HTML.
        $html = $doc->saveHTML($node);
        if (! \is_string($html)) {
            return '';
        }

        // Return the rendered HTML output.
        return $html;
    }

    /**
     * Encode mixed data to JSON for HTML attributes.
     *
     * @param  mixed  $value  The value to encode
     * @return string JSON string or empty array JSON on failure
     */
    private function encodeJsonAttributeValue(
        mixed $value
    ): string {
        // Convert the value to JSON text for storage in data attributes.
        $json = \json_encode($value);
        if (! \is_string($json)) {
            return '[]';
        }

        // Return the encoded JSON.
        return $json;
    }
}
