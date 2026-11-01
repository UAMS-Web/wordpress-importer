<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits;

use DOMComment;
use DOMElement;
use DOMNode;
use DOMText;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

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
        return $this->isOpeningBlockComment($node);
    }

    /**
     * Whether a node is an opening comment of this processor's block.
     *
     * @param  DOMNode  $node  The node to check
     * @return bool True for a `wp:<block>` comment, with or without attributes
     */
    private function isOpeningBlockComment(
        DOMNode $node
    ): bool {
        return $node instanceof DOMComment
            && \preg_match(
                '/^wp:'.\preg_quote($this->getBlockName(), '/').'\s*({.+?})?\s*$/s',
                \trim($node->data)
            ) === 1;
    }

    /**
     * Whether a node is a closing comment of this processor's block.
     *
     * @param  DOMNode  $node  The node to check
     * @return bool True for a `/wp:<block>` comment
     */
    private function isClosingBlockComment(
        DOMNode $node
    ): bool {
        return $node instanceof DOMComment
            && \preg_match(
                '/^\/wp:'.\preg_quote($this->getBlockName(), '/').'\s*$/',
                \trim($node->data)
            ) === 1;
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

        // Traverse nodes until we find the matching closing comment
        while ($currentNode instanceof DOMNode) {
            if ($this->isClosingBlockComment($currentNode)) {
                // Decrement depth when finding closing comment
                $depth--;

                // Check if the depth is zero, meaning the matching closing comment is found
                if ($depth === 0) {
                    // Exit the loop as the block has been fully traversed
                    break;
                }
            } elseif ($this->isOpeningBlockComment($currentNode)) {
                // Increment depth for nested blocks
                $depth++;
            }

            // ---
            // A nested block's comments stay in the content, so they can be processed
            // recursively by TextProcessor::processForBard(). This allows nested wp:group
            // blocks to be processed through WpGroupBlockProcessor instead of being
            // unwrapped.
            // ---
            $content .= $this->nodeContent($currentNode);

            // Move to the next sibling node
            $currentNode = $currentNode->nextSibling;
        }

        // Return the extracted content
        return $content;
    }

    /**
     * Reads one node of a block's content.
     *
     * @param  DOMNode  $node  The node to read
     * @return string The node's content; nothing for a text node of only whitespace
     */
    private function nodeContent(
        DOMNode $node
    ): string {
        if ($node instanceof DOMText) {
            return \trim($node->data) === '' ? '' : $node->data;
        }

        if ($node instanceof DOMElement) {
            return $this->extractContentFromElement($node);
        }

        if ($node instanceof DOMComment) {
            return "<!--{$node->data}-->";
        }

        return '';
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
        // Include non-wrapper elements as-is
        if (! $this->isWrapperElement($element)) {
            // A rendered element is a string; the cast covers the declared false and null
            // @pest-mutate-ignore: RemoveStringCast
            return (string) $element->ownerDocument?->saveHTML($element);
        }

        // Extract the wrapper's children instead of including the element itself
        $content = '';

        foreach ($element->childNodes as $child) {
            $content .= $this->nodeContent($child);
        }

        return $content;
    }

    /**
     * Whether an element is the block's own wrapper.
     *
     * @param  DOMElement  $element  The element to check
     * @return bool True when the element name matches and a class begins with the wrapper prefix
     */
    private function isWrapperElement(
        DOMElement $element
    ): bool {
        if ($element->nodeName !== $this->getWrapperElementName()) {
            return false;
        }

        // Split the class attribute into individual class values
        $classes = \preg_split(
            '/\s+/',
            $element->getAttribute('class'),
            -1,
            PREG_SPLIT_NO_EMPTY
        );

        foreach ($classes ?: [] as $classValue) {
            if (\str_starts_with($classValue, $this->getWrapperClassPrefix())) {
                return true;
            }
        }

        return false;
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

        // Traverse nodes until we find the matching closing comment
        while ($currentNode instanceof DOMNode) {
            // Increment count for each node traversed
            $count++;

            if ($this->isClosingBlockComment($currentNode)) {
                // Decrement depth when finding closing comment
                $depth--;

                // Check if the depth is zero, meaning the matching closing comment is found
                if ($depth === 0) {
                    // Exit the loop as the block has been fully traversed
                    break;
                }
            } elseif ($this->isOpeningBlockComment($currentNode)) {
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
