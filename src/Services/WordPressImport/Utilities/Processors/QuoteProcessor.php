<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Utilities\Alignment;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BardParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Processes WordPress wp:quote blocks into Tiptap-compatible HTML for Statamic
 * Bard sets.
 */
class QuoteProcessor implements BlockProcessorInterface
{
    /**
     * Determines if the processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a wp:quote comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        if ($node->nodeType !== XML_COMMENT_NODE) {
            return false;
        }

        $commentValue = self::extractNullableString($node->nodeValue);

        if ($commentValue === null) {
            return false;
        }

        return \preg_match(
            '/^wp:quote(?:\s*({.+?}))?\s*$/s',
            $commentValue
        ) === 1;
    }

    /**
     * Processes a wp:quote block into Tiptap-compatible HTML.
     *
     * @param  DOMNode  $node  The DOM node (wp:quote comment)
     * @param  array<int|string, mixed>  $attributes  Block attributes from the wp:quote comment
     * @param  string  $site  Site handle for context
     * @param  AssetImporter  $assetImporter  Asset importer instance (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Media attachments (unused)
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
        $doc = $node->ownerDocument;
        if (! $doc instanceof DOMDocument) {
            return null;
        }

        $xpath = new DOMXPath($doc);
        $blockquoteNode = null;

        // Find the next blockquote node after the wp:quote comment
        $blockquoteNodes = $xpath->query(
            'following-sibling::blockquote[contains(@class, "wp-block-quote")][1]',
            $node
        );

        if (
            $blockquoteNodes instanceof DOMNodeList
            && $blockquoteNodes->length > 0
        ) {
            $blockquoteNode = $blockquoteNodes->item(0);
        }

        if ($blockquoteNode === null) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'attributes' => $attributes,
                ]
            );

            // Log warning about missing blockquote node
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'QuoteProcessor::process: No valid wp:quote blockquote found',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        if (! $blockquoteNode instanceof DOMElement) {
            return null;
        }

        // Initialize arrays and variables for collecting quote content
        $paragraphs = [];

        // Initialize citation variable
        $cite = null;

        // Start node count with the blockquote node itself
        $nodeCount = 1;

        // First, check for paragraphs as children of the blockquote
        $childNodes = $xpath->query(
            './/p',
            $blockquoteNode
        );

        // Loop through all paragraph child nodes
        if ($childNodes === false) {
            return null;
        }

        foreach ($childNodes as $childNode) {
            if (! $childNode instanceof DOMElement) {
                continue;
            }

            // Extract text content and trim whitespace
            $textContent = self::extractNullableString($childNode->textContent);

            // Only include non-empty paragraphs
            if ($textContent !== null) {
                // Add paragraph to collection
                $paragraphs[] = $childNode;
            }
        }

        // Also check for cite elements as children of the blockquote
        $citeNodes = $xpath->query(
            './/cite',
            $blockquoteNode
        );

        // If citation found, store the first one
        if (
            $citeNodes instanceof DOMNodeList
            && $citeNodes->length > 0
        ) {
            // Set citation from first found cite element
            $citeNode = $citeNodes->item(0);
            if ($citeNode instanceof DOMElement) {
                $cite = $citeNode;
            }
        }

        // If no paragraphs found as children, check siblings (fallback)
        if ($paragraphs === []) {
            // Start traversing from the next sibling of blockquote
            $currentNode = $blockquoteNode->nextSibling;

            // Continue until we reach the closing wp:quote comment
            while (
                $currentNode instanceof DOMNode
                && ($currentNode->nodeType !== XML_COMMENT_NODE || ! str_contains(
                    self::extractNullableString($currentNode->nodeValue) ?? '',
                    '/wp:quote'
                ))
            ) {
                // Increment node count for each processed node
                $nodeCount++;

                // Only process element nodes
                if ($currentNode->nodeType === XML_ELEMENT_NODE) {
                    // Check if current node is a paragraph
                    if ($currentNode->nodeName === 'p') {
                        // Extract and trim paragraph text content
                        $textContent = self::extractNullableString($currentNode->textContent);

                        // Only include non-empty paragraphs
                        if ($textContent !== null) {
                            // Add paragraph to collection
                            $paragraphs[] = $currentNode;
                        }
                    } elseif ($currentNode->nodeName === 'cite') { // Check if current node is a citation
                        // Store citation element
                        $cite = $currentNode;
                    }
                }

                // Move to next sibling node
                $currentNode = $currentNode->nextSibling;
            }
        }

        $nodeCount++; // Add 1 for the closing comment

        if ($paragraphs === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'node_html' => \substr(
                        self::saveNodeHtml($blockquoteNode),
                        0,
                        100
                    ).'...',
                ]
            );

            // Log warning about missing paragraphs
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'QuoteProcessor::process: No paragraphs found in wp:quote block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        $bardParser = new BardParser;
        $quoteContent = [];
        foreach ($paragraphs as $p) {
            $html = self::saveNodeHtml($p);
            if ($html === '') {
                continue;
            }

            $parsed = $bardParser->parse($html);
            foreach ($parsed as $parsedNode) {
                if (! \is_array($parsedNode)) {
                    continue;
                }

                $parsedNodeType = self::extractNullableString($parsedNode['type'] ?? null);
                $parsedNodeContent = $parsedNode['content'] ?? null;
                if (
                    $parsedNodeType === 'paragraph'
                    && \is_array($parsedNodeContent)
                    && $parsedNodeContent !== []
                ) {
                    $quoteContent[] = $parsedNode;
                }
            }
        }

        if ($quoteContent === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'node_html' => \substr(
                        self::saveNodeHtml($blockquoteNode),
                        0,
                        100
                    ).'...',
                ]
            );

            // Log warning about empty quote content
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'QuoteProcessor::process: No valid paragraph content parsed in wp:quote block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        $alignInput = self::extractNullableString($attributes['align'] ?? null) ?? $blockquoteNode->getAttribute('class');
        $align = Alignment::translate(
            $alignInput,
            false
        );

        $values = [
            'type' => 'pull_quote',
            'align' => $align,
            'quote' => $quoteContent,
        ];

        $author = $cite instanceof DOMElement
            ? self::extractNullableString($cite->textContent)
            : null;
        if ($author !== null) {
            $values['author'] = $author;
        }

        // Convert to Tiptap HTML
        $attrs = '';
        foreach ($values as $key => $value) {
            if ($key === 'quote') {
                $quoteJson = \json_encode($value);
                if (! \is_string($quoteJson)) {
                    $quoteJson = '[]';
                }

                $attrs .= \sprintf(
                    ' data-set-attr-%s="%s"',
                    htmlspecialchars(
                        $key
                    ),
                    htmlspecialchars(
                        $quoteJson
                    )
                );
            } elseif ($key !== 'type') {
                $stringValue = self::extractNullableString($value);

                if ($stringValue === null) {
                    continue;
                }

                $attrs .= \sprintf(
                    ' data-set-attr-%s="%s"',
                    htmlspecialchars(
                        $key
                    ),
                    htmlspecialchars(
                        $stringValue
                    )
                );
            }
        }

        $html = \sprintf(
            '<div data-set-type="pull_quote"%s%s></div>',
            SetMarkupNonce::attribute(),
            $attrs
        );

        return [
            'html' => $html,
            'processedNode' => $blockquoteNode,
            'skipCount' => $nodeCount, // Skip all nodes we processed
        ];
    }

    /**
     * Normalize mixed values into a nullable trimmed string.
     *
     * @param  mixed  $value  Raw value to normalize
     * @return string|null Trimmed string value, or null when unavailable
     */
    private static function extractNullableString(
        mixed $value
    ): ?string {
        if (! \is_scalar($value)) {
            return null;
        }

        $trimmedValue = \trim((string) $value);

        return $trimmedValue === '' ? null : $trimmedValue;
    }

    /**
     * Save a DOM node to HTML, returning an empty string on failure.
     *
     * @param  DOMNode  $node  Node to serialize
     * @return string Serialized node HTML
     */
    private static function saveNodeHtml(
        DOMNode $node
    ): string {
        $ownerDocument = $node->ownerDocument;
        if (! $ownerDocument instanceof DOMDocument) {
            return '';
        }

        $nodeHtml = $ownerDocument->saveHTML($node);

        return \is_string($nodeHtml) ? $nodeHtml : '';
    }
}
