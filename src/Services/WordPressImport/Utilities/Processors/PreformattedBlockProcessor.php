<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMComment;
use DOMElement;
use DOMNode;
use DOMText;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Processes WordPress wp:preformatted blocks into Tiptap-compatible HTML for
 * Statamic Bard sets.
 */
class PreformattedBlockProcessor implements BlockProcessorInterface
{
    /**
     * Determines if the processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a wp:preformatted comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        // Check if the node is a comment node
        if ($node->nodeType !== XML_COMMENT_NODE) {
            // Return false for non-comment nodes
            return false;
        }

        $commentValue = $node->nodeValue;

        // A comment node's value is always a string; the test narrows it for PHPStan
        // @pest-mutate-ignore: FalseToTrue, RemoveEarlyReturn
        if (! \is_string($commentValue)) {
            return false;
        }

        // Check if the comment content matches the wp:preformatted pattern
        return \preg_match(
            '/^wp:preformatted\s*$/',
            \trim($commentValue)
        ) === 1;
    }

    /**
     * Processes a wp:preformatted block into Tiptap-compatible HTML.
     *
     * @param  DOMNode  $node  The DOM node (wp:preformatted comment)
     * @param  array<int|string, mixed>  $attributes  Block attributes from the wp:preformatted comment
     * @param  string  $site  Site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing files (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects (unused)
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML (unused)
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return ?string Tiptap HTML string or null if processing fails
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
    ): ?string {
        // Initialize variable to store the preformatted content
        $preformattedContent = '';

        // Start processing from the next sibling node after the opening comment
        $currentNode = $node->nextSibling;

        // Find the closing comment
        while ($currentNode instanceof DOMNode) {
            if ($currentNode instanceof DOMComment) {
                $closingComment = $currentNode->nodeValue;

                if (
                    \is_string($closingComment)
                    && \preg_match(
                        '/^\/wp:preformatted\s*$/',
                        \trim($closingComment)
                    ) === 1
                ) {
                    break;
                }
            }

            // Check if the current node is a text node
            if ($currentNode instanceof DOMText) {
                $textValue = $currentNode->nodeValue;

                // A text node's value is always a string; the test narrows it for PHPStan
                // @pest-mutate-ignore: ContinueToBreak
                if (! \is_string($textValue)) {
                    $currentNode = $currentNode->nextSibling;

                    continue;
                }

                // Extract and trim the text content from the node
                $textContent = \trim($textValue);

                // Check if the text content is not empty
                if ($textContent !== '') {
                    // Append the text content to our preformatted content
                    $preformattedContent .= $textContent;
                }
            } elseif ($currentNode instanceof DOMElement) {
                $document = $currentNode->ownerDocument;

                // A sibling of the opening comment always belongs to a document, so
                // this narrows the type for PHPStan and never fails at runtime
                // @pest-mutate-ignore: InstanceOfToTrue
                if ($document instanceof \DOMDocument) {
                    // Convert the DOM element to HTML string
                    $nodeContent = $document->saveHTML($currentNode);

                    if (\is_string($nodeContent)) {
                        // Append the HTML content to our preformatted content
                        $preformattedContent .= $nodeContent;
                    }
                }
            }

            // Move to the next sibling node
            $currentNode = $currentNode->nextSibling;
        }

        // Trim any leading/trailing whitespace from the collected content
        $preformattedContent = \trim($preformattedContent);

        // Check if no content was found in the preformatted block
        if ($preformattedContent === '') {
            // Log a warning message about the empty preformatted block
            $output->writeln("  - <comment>No content found in wp:preformatted block for site '$site' (slug: ".($pageSlug ?? 'none').')</comment>');

            // Return null to indicate processing failure
            return null;
        }

        // Return the preformatted content wrapped in <pre> tags
        return '<pre>'.htmlspecialchars(
            $preformattedContent,
            ENT_QUOTES,
            'UTF-8'
        ).'</pre>';
    }
}
