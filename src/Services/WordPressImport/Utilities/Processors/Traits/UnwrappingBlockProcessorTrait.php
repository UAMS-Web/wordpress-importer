<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits;

use DOMComment;
use DOMElement;
use DOMNode;
use DOMText;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Trait providing shared functionality for unwrapping block processors.
 *
 * This trait provides common methods used by block processors that unwrap
 * their content (e.g., wp:group, wp:details) to eliminate code duplication.
 */
trait UnwrappingBlockProcessorTrait
{
    /**
     * Gets the block name for this processor (e.g., 'group', 'details').
     *
     * @return string The block name without the 'wp:' prefix
     */
    abstract protected function getBlockName(): string;

    /**
     * Gets the wrapper element name (e.g., 'div', 'details').
     *
     * @return string The wrapper element name
     */
    abstract protected function getWrapperElementName(): string;

    /**
     * Gets the wrapper class prefix (e.g., 'wp-block-group', 'wp-block-details').
     *
     * @return string The wrapper class prefix
     */
    abstract protected function getWrapperClassPrefix(): string;

    /**
     * Processes inner content before returning.
     *
     * Allows subclasses to modify inner content before it's processed through
     * TextProcessor and ShortcodeProcessor. Default implementation returns
     * the content unchanged.
     *
     * @param  string  $innerContent  The extracted inner content
     * @param  DOMNode  $node  The block comment node
     * @return string The processed inner content
     */
    protected function processInnerContent(
        string $innerContent,
        DOMNode $node
    ): string {
        // Default implementation returns content unchanged
        return $innerContent;
    }

    /**
     * Processes Bard content after TextProcessor processing.
     *
     * Allows subclasses to modify the processed Bard content before returning.
     * Default implementation returns the content unchanged.
     *
     * @param  array<int|string, mixed>  $processedBardContent  The processed Bard content
     * @param  string  $innerContent  The original inner content
     * @param  DOMNode  $node  The block comment node
     * @return array<int|string, mixed> The processed Bard content
     */
    protected function processBardContent(
        array $processedBardContent,
        string $innerContent,
        DOMNode $node
    ): array {
        // Default implementation returns content unchanged
        return $processedBardContent;
    }

    /**
     * Checks if the processor can handle the given DOM node.
     *
     * Determines if the node is a comment node matching the block pattern.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug
     * @return bool True if the node matches the block pattern
     */
    protected function canProcessUnwrappingBlock(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        // Check if node is a comment node (only comment nodes contain block definitions)
        if ($node->nodeType !== XML_COMMENT_NODE) {
            // Return false for non-comment nodes
            return false;
        }

        // Get block name for pattern matching
        $blockName = $this->getBlockName();

        // Check if comment matches block pattern
        return \preg_match(
            '/^wp:'.\preg_quote($blockName, '/').'\s*({.+?})?\s*$/s',
            $this->trimmedCommentText($node)
        ) === 1;
    }

    /**
     * Get trimmed text from a DOM comment node.
     *
     * @param  DOMNode  $node  The comment node
     * @return string Trimmed comment text, or empty string when unavailable
     */
    private function trimmedCommentText(
        DOMNode $node
    ): string {
        if (! ($node instanceof DOMComment)) {
            return '';
        }

        $value = $node->nodeValue;

        return \is_string($value) ? \trim($value) : '';
    }

    /**
     * Processes an unwrapping block by extracting and processing its contents.
     *
     * Extracts inner content, processes it through TextProcessor and
     * ShortcodeProcessor, and returns the result.
     *
     * @param  DOMNode  $node  The block comment node
     * @param  array<int|string, mixed>  $attributes  Block attributes from JSON
     * @param  string  $site  Site handle
     * @param  AssetImporter  $assetImporter  Asset importer instance
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Media attachments
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug
     * @return array<int|string, mixed>|null Array with 'html', 'processedNode', 'skipCount', or null
     */
    protected function processUnwrappingBlock(
        DOMNode $node,
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        array $terms,
        ?string $pageSlug
    ): array|string|null {
        // Extract inner content from the block
        $innerContent = $this->extractInnerContent($node);

        // Check if inner content is empty
        if ($innerContent === '') {
            // Return null if no content found
            return null;
        }

        // Allow subclasses to process inner content
        $processedInnerContent = $this->processInnerContent(
            $innerContent,
            $node
        );

        // ---
        // Recursively process inner content through TextProcessor to handle nested
        // blocks. This ensures block comments like wp:shortcode are processed correctly.
        // ---
        $processedBardContent = TextProcessor::processForBard(
            $processedInnerContent,
            $site,
            $assetImporter,
            $output,
            false, // applyWpAutoP
            false, // isInnerBlocks
            $attachments,
            $terms,
            $pageSlug,
            null, // statamicEntryId
            null, // collectionHandle
            [] // preProcessedNodes
        );

        // Allow subclasses to process Bard content
        $processedBardContent = $this->processBardContent(
            $processedBardContent,
            $processedInnerContent,
            $node
        );

        // ---
        // Process shortcodes in inner content HTML for NonAcfBlockParser.
        //
        // NonAcfBlockParser expects HTML with shortcodes already converted to Tiptap
        // HTML. When inner content contains wp:shortcode blocks (e.g., <!-- wp:shortcode
        // -->[table id=6 /]<!-- /wp:shortcode -->), we need to process these shortcodes
        // before returning the HTML. Otherwise, BardParser will strip the block comments
        // and the shortcode will remain as plain text [table id=6 /] instead of being
        // converted to a table set.
        // ---
        $shortcodeProcessor = new ShortcodeProcessor;
        $processedInnerContentHtml = $shortcodeProcessor->processShortcodesInContent(
            $processedInnerContent,
            $site,
            $assetImporter,
            $output,
            $attachments,
            $terms,
            [], // unaddressedBlocks
            $pageSlug
        );

        // ---
        // Return both bardContent (for TextProcessor) and html (for NonAcfBlockParser).
        // TextProcessor will handle bardContent specially by merging it into bardContent.
        // NonAcfBlockParser will use html to accumulate content. The inner content HTML
        // is now processed with shortcodes converted to Tiptap HTML.
        // ---
        return [
            'bardContent' => $processedBardContent, // Return Bard content directly
            'html' => $processedInnerContentHtml, // Return processed inner content HTML for NonAcfBlockParser
            'processedNode' => $node,
            'skipCount' => $this->countBlockNodes($node),
        ];
    }

    /**
     * Extracts inner content from a block.
     *
     * Collects all content between the opening and closing block comments,
     * skipping wrapper elements.
     *
     * @param  DOMNode  $node  The opening block comment node
     * @return string The inner content as HTML string
     */
    protected function extractInnerContent(
        DOMNode $node
    ): string {
        // Initialize an empty string to store the extracted content
        $content = '';

        // Start with the next sibling of the current node
        $currentNode = $node->nextSibling;

        // Initialize depth to 1 for the current block
        $depth = 1;

        // Get block name for pattern matching
        $blockName = $this->getBlockName();
        $escapedBlockName = \preg_quote($blockName, '/');

        // Traverse nodes until we find the matching closing comment.
        // The loop breaks as soon as $depth reaches 0, so $depth is always > 0 here.
        while ($currentNode instanceof DOMNode) {
            // Check if this is a closing block comment
            if ($currentNode instanceof DOMComment
            && \preg_match(
                '/^\/wp:'.$escapedBlockName.'\s*$/',
                $this->trimmedCommentText($currentNode)
            ) === 1) {
                // Decrement depth when finding closing comment
                $depth--;
                // Check if the depth is zero, meaning the matching closing comment is found
                if ($depth === 0) {
                    // Exit the loop as the block has been fully traversed
                    break;
                }

                // ---
                // Append closing comment for nested blocks so they can be processed
                // recursively by TextProcessor::processForBard().
                // ---
                $content .= "<!--{$currentNode->nodeValue}-->";
                // Move to the next node and continue
                $currentNode = $currentNode->nextSibling;

                continue;
            }

            // Check if this is a closing block comment
            if ($currentNode instanceof DOMComment
            && \preg_match(
                '/^wp:'.$escapedBlockName.'\s*({.+?})?\s*$/s',
                $this->trimmedCommentText($currentNode)
            ) === 1) {
                // Check if this is an opening block comment (nested block)
                // Increment depth for nested blocks
                $depth++;
                // ---
                // Preserve nested block comments so they can be processed recursively by
                // TextProcessor::processForBard(). This allows nested wp:group blocks to be
                // processed through WpGroupBlockProcessor instead of being unwrapped.
                // Append the opening block comment and let normal traversal handle the content.
                // ---
                $content .= "<!--{$currentNode->nodeValue}-->";
                // Move to the next node to continue normal traversal
                $currentNode = $currentNode->nextSibling;

                // Continue to next iteration to process content normally
                continue;
            }

            // ---
            // Add node content to inner content. We are always still inside the block
            // here ($depth > 0), because the loop breaks as soon as $depth reaches 0.
            // ---

            // Check if node is a text node
            if ($currentNode instanceof DOMText) {
                // Trim whitespace from the text node's value
                $nodeValue = $currentNode->nodeValue;
                $text = \is_string($nodeValue) ? \trim($nodeValue) : '';

                // Check if the trimmed text is not empty
                if ($text !== '') {
                    // Append the text node's value to the content
                    $content .= $currentNode->nodeValue;
                }
            } elseif ($currentNode instanceof DOMElement) { // Check if node is an element
                // Extract content from element, skipping wrapper elements
                $content .= $this->extractContentFromElement($currentNode);
            } elseif ($currentNode instanceof DOMComment) { // Check if node is a comment
                // Append the comment node's HTML representation to the content
                $content .= "<!--{$currentNode->nodeValue}-->";
            }

            // Move to the next sibling node
            $currentNode = $currentNode->nextSibling;
        }

        // Return the extracted content
        return $content;
    }

    /**
     * Extracts content from an element, skipping wrapper elements.
     *
     * Recursively extracts content from elements, skipping wrapper elements
     * with specific class prefixes to unwrap the content.
     *
     * @param  DOMElement  $element  The element to extract content from
     * @return string The extracted content
     */
    protected function extractContentFromElement(
        DOMElement $element
    ): string {
        // Initialize content string for accumulating extracted content
        $content = '';

        // ---
        // Check if this is a wrapper element
        // ---

        // Get class attribute value from the element
        $class = $element->getAttribute('class');

        // Initialize flag to track if element is a wrapper
        $isWrapper = false;

        // Get wrapper element name and class prefix
        $wrapperElementName = $this->getWrapperElementName();
        $wrapperClassPrefix = $this->getWrapperClassPrefix();

        // Check if element matches wrapper criteria
        if ($element->nodeName === $wrapperElementName && $class !== '') {
            // Split class attribute into individual class values
            $classes = \preg_split(
                '/\s+/',
                \trim($class)
            );

            if (! \is_array($classes)) {
                $classes = [];
            }

            // ---
            // Check if any class value begins with the wrapper class prefix
            // ---

            // Loop through each class value to check for wrapper class prefix
            foreach ($classes as $classValue) {
                // Check if class value begins with wrapper class prefix
                if (
                    str_starts_with($classValue, $wrapperClassPrefix)
                ) {
                    // Mark element as wrapper when matching class is found
                    $isWrapper = true;

                    // Exit loop early since we found a match
                    break;
                }
            }
        }

        // Check if element is a wrapper that should be skipped
        if ($isWrapper) {
            // ---
            // Extract inner content of wrapper element instead of including the element itself
            // ---

            // Loop through all child nodes of the wrapper element
            foreach ($element->childNodes as $child) {
                // Check if child node is a text node
                if ($child instanceof DOMText) {
                    // Trim whitespace from text content
                    $childValue = $child->nodeValue;
                    $text = \is_string($childValue) ? \trim($childValue) : '';

                    // Check if text content is not empty after trimming
                    if ($text !== '') {
                        // Append text content to extracted content
                        $content .= $child->nodeValue;
                    }
                } elseif ($child instanceof DOMElement) { // Check if child node is an element node
                    // Recursively extract content from child elements
                    $content .= $this->extractContentFromElement($child);
                } elseif ($child instanceof DOMComment) { // Check if child node is a comment node
                    // Append comment to extracted content preserving comment syntax
                    $content .= "<!--{$child->nodeValue}-->";
                }
            }
        } else { // Otherwise
            // Include non-wrapper elements as-is
            $ownerDocument = $element->ownerDocument;

            if ($ownerDocument instanceof \DOMDocument) {
                $content .= $ownerDocument->saveHTML($element);
            }
        }

        // Return extracted content string
        return $content;
    }

    /**
     * Counts the number of nodes in a block.
     *
     * Traverses from the opening comment to the closing comment to count all nodes
     * that should be skipped.
     *
     * @param  DOMNode  $node  The opening block comment node
     * @return int The number of nodes to skip
     */
    protected function countBlockNodes(
        DOMNode $node
    ): int {
        // Start with the opening comment
        $count = 1;

        // Start with the next sibling of the current node
        $currentNode = $node->nextSibling;

        // Initialize depth to 1 for the current block
        $depth = 1;

        // Get block name for pattern matching
        $blockName = $this->getBlockName();
        $escapedBlockName = \preg_quote($blockName, '/');

        // Traverse nodes until we find the matching closing comment.
        // The loop breaks as soon as $depth reaches 0, so $depth is always > 0 here.
        while ($currentNode instanceof DOMNode) {
            // Increment count for each node traversed
            $count++;

            // Check if this is a closing block comment
            if (
                $currentNode instanceof DOMComment
                && \preg_match(
                    '/^\/wp:'.$escapedBlockName.'\s*$/',
                    $this->trimmedCommentText($currentNode)
                ) === 1
            ) {
                // Decrement depth when finding closing comment
                $depth--;

                // ---
                // Break if we've found the matching closing comment
                // ---

                // Check if the depth is zero, meaning the matching closing comment is found
                if ($depth === 0) {
                    // Exit the loop as the block has been fully traversed
                    break;
                }
            } elseif (
                $currentNode instanceof DOMComment
                && \preg_match(
                    '/^wp:'.$escapedBlockName.'\s*({.+?})?\s*$/s',
                    $this->trimmedCommentText($currentNode)
                ) === 1
            ) { // Check if this is an opening block comment (nested block)
                // Increment depth for nested blocks
                $depth++;
            }

            // Move to next sibling node
            $currentNode = $currentNode->nextSibling;
        }

        // Return total count of nodes in the block
        return $count;
    }
}
