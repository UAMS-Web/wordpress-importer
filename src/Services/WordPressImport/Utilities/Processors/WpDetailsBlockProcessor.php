<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMComment;
use DOMElement;
use DOMNode;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits\UnwrappingBlockProcessorTrait;

/**
 * Processes WordPress wp:details blocks by unwrapping their contents.
 *
 * Extracts the inner content of the details block and processes it. Converts
 * <summary> tags to headings based on preceding heading levels. If no heading
 * precedes the wp:details block, treats the summary as H2. If a heading precedes
 * the block, treats the summary as one level lower than the first preceding
 * heading.
 */
class WpDetailsBlockProcessor implements BlockProcessorInterface
{
    use UnwrappingBlockProcessorTrait;

    /**
     * Gets the block name for this processor.
     *
     * @return string The block name without the 'wp:' prefix
     */
    protected function getBlockName(): string
    {
        return 'details';
    }

    /**
     * Gets the wrapper element name.
     *
     * @return string The wrapper element name
     */
    protected function getWrapperElementName(): string
    {
        return 'details';
    }

    /**
     * Gets the wrapper class prefix.
     *
     * @return string The wrapper class prefix
     */
    protected function getWrapperClassPrefix(): string
    {
        return 'wp-block-details';
    }

    /**
     * Checks if the processor can handle the given DOM node.
     *
     * Determines if the node is a wp:details comment node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug
     * @return bool True if the node is a wp:details comment
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        return $this->canProcessUnwrappingBlock(
            $node,
            $site,
            $pageSlug
        );
    }

    /**
     * Processes a wp:details block by unwrapping its contents.
     *
     * Extracts the inner content of the details block and processes it. Converts
     * <summary> tags to headings based on preceding heading levels.
     *
     * @param  DOMNode  $node  The wp:details block comment node
     * @param  array<int|string, mixed>  $attributes  Block attributes from JSON
     * @param  string  $site  Site handle
     * @param  AssetImporter  $assetImporter  Asset importer instance
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Media attachments
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug
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
    ): array|string|null {
        // Use trait's processUnwrappingBlock method
        return $this->processUnwrappingBlock(
            $node,
            $attributes,
            $site,
            $assetImporter,
            $output,
            $attachments,
            $terms,
            $pageSlug
        );
    }

    /**
     * Processes inner content before returning.
     *
     * Converts <summary> tags to headings based on preceding heading levels.
     *
     * @param  string  $innerContent  The extracted inner content
     * @param  DOMNode  $node  The block comment node
     * @return string The processed inner content
     */
    protected function processInnerContent(
        string $innerContent,
        DOMNode $node
    ): string {
        // Find the first preceding heading level
        $precedingHeadingLevel = $this->findFirstPrecedingHeadingLevel(
            $node
        );

        // Determine heading level for summary tags
        $summaryHeadingLevel = $precedingHeadingLevel === null
            ? 2 // Default to H2 if no preceding heading
            : \min(
                // convertSummaryToHeading() clamps to h6 again, so raising this
                // cap does not change the result
                // @pest-mutate-ignore: IncrementInteger
                6,
                $precedingHeadingLevel + 1
            ); // One level lower than preceding heading, max H6

        // Convert summary tags to headings in inner content
        return $this->convertSummaryToHeading(
            $innerContent,
            $summaryHeadingLevel
        );
    }

    /**
     * Finds the level of the first preceding heading before the current node.
     *
     * Traverses backwards through previous siblings to find the first heading
     * (h1-h6) or wp:heading block comment. Returns the heading level (1-6) or
     * null if no heading is found.
     *
     * @param  DOMNode  $node  The current node to start searching from
     * @return ?int The heading level (1-6) or null if no heading found
     */
    private function findFirstPrecedingHeadingLevel(
        DOMNode $node
    ): ?int {
        // Start with the previous sibling of the current node
        $currentNode = $node->previousSibling;

        // Traverse backwards through previous siblings
        while ($currentNode instanceof DOMNode) {
            // Check if this is a wp:heading block comment
            if ($currentNode instanceof DOMComment) {
                $commentValue = $currentNode->nodeValue;
                // A comment node's value is always a string; the check narrows it for PHPStan
                // @pest-mutate-ignore: ContinueToBreak
                if (! \is_string($commentValue)) {
                    $currentNode = $currentNode->previousSibling;

                    continue;
                }

                if (
                    \preg_match(
                        '/^wp:heading\s*({.+?})?\s*$/s',
                        \trim($commentValue)
                    ) !== 1
                ) {
                    $currentNode = $currentNode->previousSibling;

                    continue;
                }

                // Extract heading level from JSON attributes
                $level = $this->extractHeadingLevelFromComment(
                    $commentValue
                );
                // Return the heading level if found
                if ($level !== null) {
                    return $level;
                }

                // Default to level 2 if wp:heading found but no level specified
                return 2;
            }

            // ---
            // Check if this is a heading element. On libxml 2.14 and later no other
            // node type reaching here (text, CDATA) has a name matching ^h[1-6]$, so
            // the type test only narrows for PHPStan: measured on libxml 2.15.4,
            // where a processing-instruction-shaped tag such as `<?h1 x>` in HTML
            // parses as a comment. Earlier libxml makes it a processing instruction
            // named h1, which this would then misread.
            // ---
            // @pest-mutate-ignore: InstanceOfToTrue
            if ($currentNode instanceof DOMElement) {
                // Check if this is a heading element (h1-h6)
                // Check if element is a heading tag
                if (
                    \preg_match(
                        '/^h[1-6]$/i',
                        $currentNode->nodeName
                    ) === 1
                ) {
                    // Extract heading level from tag name (h1 = 1, h2 = 2, etc.)
                    $level = (int) \substr(
                        $currentNode->nodeName,
                        1
                    );

                    // Return the heading level
                    return $level;
                }
            }

            // Move to the previous sibling node
            $currentNode = $currentNode->previousSibling;
        }

        // Return null if no heading found
        return null;
    }

    /**
     * Extracts heading level from a wp:heading block comment.
     *
     * Parses the JSON attributes in the comment to find the level property.
     *
     * @param  string  $commentValue  The comment node value
     * @return ?int The heading level (1-6) or null if not found
     */
    private function extractHeadingLevelFromComment(
        string $commentValue
    ): ?int {
        // Check if comment contains JSON attributes in braces
        if (
            \preg_match(
                '/{.+?}/s',
                $commentValue,
                $matches
            ) === 1
        ) {
            // Decode JSON attributes from comment
            $attributes = \json_decode(
                $matches[0],
                true,
                512
            );

            // Without this return, isset() below is false on null and the method
            // still returns null, so the guard narrows the type rather than the result
            // @pest-mutate-ignore: RemoveEarlyReturn
            if (! \is_array($attributes)) {
                return null;
            }

            // Check if level is set in attributes
            if (
                isset($attributes['level'])
                && \is_numeric($attributes['level'])
            ) {
                // Cast to int and ensure it's between 1 and 6
                $level = (int) $attributes['level'];

                // Return level if valid (1-6)
                if ($level >= 1 && $level <= 6) {
                    return $level;
                }
            }
        }

        // Return null if no level found
        return null;
    }

    /**
     * Converts <summary> tags to heading tags in HTML content.
     *
     * Replaces <summary> tags with heading tags of the specified level. Preserves
     * the content and any attributes of the summary tag.
     *
     * @param  string  $html  The HTML content containing summary tags
     * @param  int  $headingLevel  The heading level to use (1-6)
     * @return string The HTML content with summary tags converted to headings
     */
    private function convertSummaryToHeading(
        string $html,
        int $headingLevel
    ): string {
        // ---
        // Ensure heading level is valid (1-6). processInnerContent(), the only
        // caller, passes 2 to 6, so the floor never applies and the ceiling only
        // matters when it is lowered.
        // ---
        $headingLevel = \max(
            // @pest-mutate-ignore: IncrementInteger, DecrementInteger
            1,
            \min(
                // @pest-mutate-ignore: IncrementInteger
                6,
                $headingLevel
            )
        );

        // Create heading tag name
        $headingTag = "h{$headingLevel}";

        // Replace <summary> tags with heading tags, preserving content
        $converted = \preg_replace(
            '/<summary\b[^>]*>(.*?)<\/summary>/is',
            "<{$headingTag}>$1</{$headingTag}>",
            $html
        );

        // Return the converted HTML, or the original when replacement fails
        return \is_string($converted) ? $converted : $html;
    }
}
