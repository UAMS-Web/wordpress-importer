<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Utilities\Alignment;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Processes WordPress wp:table blocks into Tiptap-compatible HTML for Statamic
 * Bard sets.
 */
class TableProcessor implements BlockProcessorInterface
{
    /**
     * Determines if the processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a wp:table comment, false otherwise
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

        // Check whether the node is an XML comment.
        if ($node->nodeType !== XML_COMMENT_NODE) {
            return false;
        }

        // Ensure the comment value is a string before matching.
        if (! \is_string($node->nodeValue)) {
            return false;
        }

        // Match the wp:table block comment format.
        return \preg_match(
            '/^wp:table(?:\s*({.+?}))?\s*$/s',
            \trim($node->nodeValue)
        ) === 1;
    }

    /**
     * Processes a wp:table block into Tiptap-compatible HTML.
     *
     * @param  DOMNode  $node  The DOM node (wp:table comment)
     * @param  array<int|string, mixed>  $attributes  Block attributes from the wp:table comment
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
        // Ignore unused interface parameters for this implementation.
        unset(
            $assetImporter,
            $output,
            $attachments,
            $terms
        );

        // Resolve the owner document for XPath operations.
        $doc = $node->ownerDocument;
        if (! $doc instanceof DOMDocument) {
            return null;
        }

        // Read the block's own nodes, up to its closing comment (#197)
        $extent = self::blockExtent($node);
        $figureNode = $extent['figure'];

        // A block with no table figure among its own nodes has nothing to import
        if (! $figureNode instanceof DOMElement) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'attributes' => $attributes,
                ]
            );

            // Log warning about missing figure node
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'TableProcessor::process: No valid wp:table figure found',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        $tableNode = null;
        foreach ($figureNode->getElementsByTagName('table') as $table) {
            $tableNode = $table;
            break;
        }

        if ($tableNode === null) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'node_html' => \substr(
                        $this->renderNodeSnippet($figureNode, $doc),
                        0,
                        100
                    ).'...',
                ]
            );

            // Log warning about missing table node
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'TableProcessor::process: No table found in wp:table block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        $tableData = [];
        $firstRowHeaders = false;
        $lastRowFooters = false;
        $firstColumnHeaders = false;
        $fixedWidthTableCells = false;

        // Check for has-fixed-layout class on table element
        if (
            $tableNode->hasAttribute('class')
            && \preg_match(
                '/\bhas-fixed-layout\b/',
                $tableNode->getAttribute('class')
            ) === 1
        ) {
            $fixedWidthTableCells = true;
        }

        // Process table sections
        $thead = $tableNode->getElementsByTagName('thead')->item(0);
        $tbody = $tableNode->getElementsByTagName('tbody')->item(0);
        $tfoot = $tableNode->getElementsByTagName('tfoot')->item(0);

        if ($thead !== null) {
            $firstRowHeaders = true;
            foreach ($thead->getElementsByTagName('tr') as $tr) {
                $row = [];
                foreach ($tr->getElementsByTagName('th') as $th) {
                    $row[] = $this->getInnerHTML($th, $doc);
                }

                if ($row !== []) {
                    $tableData[] = ['cells' => $row];
                }
            }
        }

        if ($tbody !== null) {
            foreach ($tbody->getElementsByTagName('tr') as $tr) {
                $row = [];
                $cells = $tr->getElementsByTagName('td');
                foreach ($cells as $td) {
                    $row[] = $this->getInnerHTML($td, $doc);
                }

                if ($row !== []) {
                    $tableData[] = ['cells' => $row];
                }
            }
        }

        if ($tfoot !== null) {
            $lastRowFooters = true;
            foreach ($tfoot->getElementsByTagName('tr') as $tr) {
                $row = [];
                foreach ($tr->getElementsByTagName('td') as $td) {
                    $row[] = $this->getInnerHTML($td, $doc);
                }

                if ($row !== []) {
                    $tableData[] = ['cells' => $row];
                }
            }
        }

        if ($tableData === []) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'node_html' => \substr(
                        $this->renderNodeSnippet($figureNode, $doc),
                        0,
                        100
                    ).'...',
                ]
            );

            // Log warning about empty table data
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'TableProcessor::process: No valid table rows parsed in wp:table block',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // Alignment
        $alignInput = $attributes['align'] ?? $figureNode->getAttribute('class');
        if (! \is_string($alignInput)) {
            // Any string without an align class translates to the center default,
            // so this value cannot be told apart from another such string.
            // @pest-mutate-ignore: EmptyStringToNotEmpty
            $alignInput = '';
        }

        $align = Alignment::translate(
            $alignInput,
            false
        );

        // Striped style
        $striped = \preg_match(
            '/\bis-style-stripes\b/',
            $figureNode->getAttribute('class')
        ) === 1;

        // Caption
        $captionNode = $figureNode->getElementsByTagName('figcaption')->item(0);
        $caption = $captionNode !== null ? \trim($captionNode->textContent) : '';

        $values = [
            'type' => 'table',
            'align' => $align,
            'striped' => $striped,
            'first_row_headers' => $firstRowHeaders,
            'first_column_headers' => $firstColumnHeaders,
            'last_row_footers' => $lastRowFooters,
            'fixed_width_table_cells' => $fixedWidthTableCells,
            'table_data' => $tableData,
        ];

        if ($caption !== '') {
            $values['caption'] = $caption;
        }

        // Convert to Tiptap HTML
        $attrs = '';
        $booleanFields = ['first_row_headers', 'first_column_headers', 'last_row_footers', 'striped', 'fixed_width_table_cells'];
        foreach ($values as $key => $value) {
            if ($key === 'table_data') {
                // Use single quotes for table_data to avoid conflicts with JSON double quotes
                $attrs .= \sprintf(
                    " data-set-attr-%s='%s'",
                    $key,
                    htmlspecialchars(
                        $this->encodeJsonAttributeValue($value),
                        ENT_QUOTES,
                        'UTF-8'
                    )
                );

                continue;
            }

            if (
                ! \is_string($value)
                && ! \is_bool($value)
            ) {
                continue;
            }

            // Ensure boolean values are serialized as strings for HTML attributes.
            $attrValue = \is_bool($value) ? ($value ? 'true' : 'false') : $value;
            $attrs .= \sprintf(
                ' data-set-attr-%s="%s"',
                $key,
                htmlspecialchars($attrValue)
            );
        }

        // Add boolean fields metadata
        $attrs .= \sprintf(
            ' data-set-boolean-fields="%s"',
            \implode(
                ',',
                $booleanFields
            )
        );

        $html = \sprintf(
            '<div data-set-type="table"%s%s></div>',
            SetMarkupNonce::attribute(),
            $attrs
        );

        // ---
        // The skip covers the whole block, so anything else in it is not imported.
        // WordPress writes a table block as its figure alone, so this records a
        // block that was written some other way rather than losing it silently.
        // ---
        if ($extent['others'] !== []) {
            // Enhance log context with entry information. saveHTML() returns false
            // only for a node of another document, and these are the block's own
            // siblings, so the cast phpstan needs changes no result.
            // @pest-mutate-ignore: RemoveStringCast
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'dropped_html' => \substr(
                        \implode('', \array_map(
                            static fn (DOMNode $other): string => (string) $doc->saveHTML($other),
                            $extent['others']
                        )),
                        0,
                        100
                    ),
                ]
            );

            // Log warning about the content dropped with the block
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'TableProcessor::process: Dropped content in a wp:table block outside its figure',
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        return [
            'html' => $html,
            'processedNode' => $figureNode,
            'skipCount' => $extent['skipCount'],
        ];
    }

    /**
     * Reads a wp:table block's own nodes, from its opening comment to its closing one.
     *
     * The block ends at its closing comment, which it includes, or at any other block
     * comment, which it does not, so a block that is never closed takes nothing from
     * the blocks after it. The first `wp-block-table` figure among the nodes is the
     * block's figure; every other node but whitespace and comments is content the
     * block holds besides it. Whitespace-only text is not counted, as the caller's
     * walk over a skip count does not count it.
     *
     * @param  DOMNode  $comment  The block's opening comment
     * @return array{figure: ?DOMElement, skipCount: int, others: list<DOMNode>} The figure, the number of nodes the block spans with its opening comment, and its other content
     */
    private static function blockExtent(
        DOMNode $comment
    ): array {
        // Start with the opening comment and nothing found
        $figure = null;
        $others = [];
        $skipCount = 1;

        // Walk the siblings after the opening comment
        for ($current = $comment->nextSibling; $current instanceof DOMNode; $current = $current->nextSibling) {
            // A block comment ends the block; only the block's own closing one is part of it
            if ($current instanceof DOMComment && \preg_match('/^\/?wp:/', \trim($current->data)) === 1) {
                if (\trim($current->data) === '/wp:table') {
                    $skipCount++;
                }

                break;
            }

            // Whitespace between the block's nodes is not counted
            if ($current instanceof DOMText && \trim($current->data) === '') {
                continue;
            }

            $skipCount++;

            // Take the first table figure as the block's figure
            if (
                $figure === null
                && $current instanceof DOMElement
                && $current->tagName === 'figure'
                && \str_contains($current->getAttribute('class'), 'wp-block-table')
            ) {
                $figure = $current;

                continue;
            }

            // A comment that is not a block's holds no content
            if ($current instanceof DOMComment) {
                continue;
            }

            $others[] = $current;
        }

        return [
            'figure' => $figure,
            'skipCount' => $skipCount,
            'others' => $others,
        ];
    }

    /**
     * Get the inner HTML of a DOM element as a string.
     *
     * @param  DOMNode  $element  The DOM element to extract inner HTML from
     * @param  DOMDocument  $doc  The document the element belongs to
     * @return string The inner HTML string
     */
    private function getInnerHTML(
        DOMNode $element,
        DOMDocument $doc
    ): string {
        // Build the inner HTML by rendering each child node.
        $innerHTML = '';
        foreach ($element->childNodes as $child) {
            $childHtml = $doc->saveHTML($child);
            if (! \is_string($childHtml)) {
                continue;
            }

            $innerHTML .= $childHtml;
        }

        // Return a trimmed inner HTML string.
        return \trim($innerHTML);
    }

    /**
     * Render a DOM element to HTML for log snippets.
     *
     * @param  DOMElement  $element  The element to render
     * @param  DOMDocument  $doc  The document the element belongs to
     * @return string Safe rendered HTML or an empty string
     */
    private function renderNodeSnippet(
        DOMElement $element,
        DOMDocument $doc
    ): string {
        // Render the element to an HTML string.
        $html = $doc->saveHTML($element);
        if (! \is_string($html)) {
            return '';
        }

        // Return the rendered HTML content.
        return $html;
    }

    /**
     * Encode mixed data to a safe JSON attribute string.
     *
     * @param  mixed  $value  The value to encode
     * @return string JSON representation for HTML attributes
     */
    private function encodeJsonAttributeValue(
        mixed $value
    ): string {
        // Encode the value to JSON for storage in a data attribute.
        $json = \json_encode($value);
        if (! \is_string($json)) {
            return '[]';
        }

        // Return the encoded JSON string.
        return $json;
    }
}
