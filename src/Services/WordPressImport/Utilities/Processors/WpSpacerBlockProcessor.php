<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMComment;
use DOMNode;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Processes WordPress wp:spacer blocks by stripping them from content.
 *
 * Spacer blocks are used for vertical spacing in WordPress but are not needed
 * in Statamic Bard content. This processor effectively removes them by
 * returning empty content and skipping the block nodes.
 */
class WpSpacerBlockProcessor implements BlockProcessorInterface
{
    /**
     * Determines if the processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug
     * @return bool True if the node is a wp:spacer comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        // Check if node is a comment node (only comment nodes contain block definitions)
        if ($node->nodeType !== XML_COMMENT_NODE) {
            // Return false for non-comment nodes
            return false;
        }

        // Check if comment matches wp:spacer block pattern
        $nodeValue = $node->nodeValue;

        // A comment node's value is always a string; the test narrows it for PHPStan
        // @pest-mutate-ignore: FalseToTrue, RemoveEarlyReturn
        if (! is_string($nodeValue)) {
            return false;
        }

        return \preg_match(
            '/^wp:spacer\s*({.+?})?\s*$/s',
            \trim($nodeValue)
        ) === 1;
    }

    /**
     * Processes a wp:spacer block by stripping it from content.
     *
     * Returns empty content and skips the block nodes (opening comment, div
     * element, closing comment) to effectively remove the spacer from the output.
     *
     * @param  DOMNode  $node  The wp:spacer block comment node
     * @param  array<int|string, mixed>  $attributes  Block attributes from JSON
     * @param  string  $site  Site handle
     * @param  AssetImporter  $assetImporter  Asset importer instance
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Media attachments
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug
     * @return array<int|string, mixed> Array with empty 'html', 'processedNode', and 'skipCount'
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
        // Count nodes in the spacer block (opening comment, div, closing comment)
        $skipCount = $this->countSpacerNodes($node);

        // Return empty content to effectively strip the spacer block
        return [
            'html' => '', // Empty HTML to strip the spacer
            'processedNode' => $node,
            'skipCount' => $skipCount,
        ];
    }

    /**
     * Counts the number of nodes in a wp:spacer block.
     *
     * Traverses from the opening comment to the closing comment to count all
     * nodes that should be skipped (opening comment, div element, closing comment).
     *
     * @param  DOMNode  $node  The opening wp:spacer comment node
     * @return int The number of nodes to skip
     */
    private function countSpacerNodes(
        DOMNode $node
    ): int {
        // Start with the opening comment
        $count = 1;

        // Start with the next sibling of the current node
        $currentNode = $node->nextSibling;

        // Traverse nodes until we find the closing comment
        while ($currentNode instanceof DOMNode) {
            // Increment count for each node traversed
            $count++;

            // Check if this is a closing wp:spacer comment
            if (
                $currentNode instanceof DOMComment
            ) {
                $commentValue = $currentNode->nodeValue;

                if (
                    is_string($commentValue)
                    && \preg_match(
                        '/^\/wp:spacer\s*$/',
                        \trim($commentValue)
                    ) === 1
                ) {
                    // Exit the loop as the block has been fully traversed
                    break;
                }
            }

            // Move to next sibling node
            $currentNode = $currentNode->nextSibling;
        }

        // Return total count of nodes in the spacer block
        return $count;
    }
}
