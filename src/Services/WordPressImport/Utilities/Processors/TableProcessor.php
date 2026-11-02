<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMComment;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Utilities\Alignment;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Processes WordPress wp:table blocks into Tiptap-compatible HTML for Statamic
 * Bard sets.
 */
class TableProcessor implements BlockProcessorInterface
{
    /**
     * The most columns a table is laid out to. It is the widest a single `colspan`
     * can be in HTML, and it keeps hostile spans from growing a grid out of memory.
     */
    private const int MAX_COLUMNS = 1000;

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

        // Render the table as a set; a table with no rows renders as nothing
        $html = $this->renderSet(
            $tableNode,
            $figureNode,
            $attributes
        );

        if ($html === null) {
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
     * Renders a table element as the markup of a `table` set.
     *
     * A wp:table block and a plain `<table>` in content arrive here alike, so both
     * import the same way. A nested table stays in its cell's HTML: only the
     * outermost table is a set.
     *
     * @param  DOMElement  $table  The table element
     * @param  ?DOMElement  $figure  The figure holding the table, when there is one
     * @param  array<int|string, mixed>  $attributes  Block attributes from a wp:table comment
     * @return ?string The set's markup, or null for a table with no rows
     */
    public function renderSet(
        DOMElement $table,
        ?DOMElement $figure = null,
        array $attributes = []
    ): ?string {
        // Read the rows; a table without any has nothing to import
        $rows = $this->readRows($table);

        if ($rows['tableData'] === []) {
            return null;
        }

        // Read the classes the figure carries, when there is one
        $figureClass = $figure?->getAttribute('class') ?? '';

        // Alignment
        $alignInput = $attributes['align'] ?? $figureClass;
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
            $figureClass
        ) === 1;

        // Caption
        $captionNode = $figure?->getElementsByTagName('figcaption')->item(0)
            ?? self::childElements($table, ['caption'])[0]
            ?? null;
        $caption = $captionNode !== null ? \trim($captionNode->textContent) : '';

        $values = [
            'type' => 'table',
            'align' => $align,
            'striped' => $striped,
            'first_row_headers' => $rows['firstRowHeaders'],
            'first_column_headers' => false,
            'last_row_footers' => $rows['lastRowFooters'],
            'fixed_width_table_cells' => \preg_match(
                '/\bhas-fixed-layout\b/',
                $table->getAttribute('class')
            ) === 1,
            'table_data' => $rows['tableData'],
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

        return \sprintf(
            '<div data-set-type="table"%s%s></div>',
            SetMarkupNonce::attribute(),
            $attrs
        );
    }

    /**
     * Whether a table holds data, so that it belongs in a `table` set.
     *
     * A table that holds another table is page layout, a set in a cell could not stay
     * a set in the cell's HTML, and a table with no text is spacing around images. Each
     * of those is read as content instead.
     *
     * @param  DOMElement  $table  The table element
     * @return bool True when the table belongs in a set
     */
    public function isDataTable(
        DOMElement $table
    ): bool {
        // A nested table makes this one layout
        if ($table->getElementsByTagName('table')->length > 0) {
            return false;
        }

        // A converted shortcode in a cell is a set of its own
        foreach ($table->getElementsByTagName('*') as $element) {
            if ($element->hasAttribute('data-set-type')) {
                return false;
            }
        }

        // A non-breaking space is what a blank cell holds
        return \trim(\str_replace("\u{A0}", ' ', $table->textContent)) !== '';
    }

    /**
     * Reads a table's rows, in the order the table displays them.
     *
     * Only the table's own rows are read, so a nested table stays in its cell. Each
     * row group gets its own grid, since a rowspan ends with its group.
     *
     * @param  DOMElement  $table  The table element
     * @return array{tableData: list<array{cells: list<string>}>, firstRowHeaders: bool, lastRowFooters: bool} The rows, and what the first and last of them are
     */
    private function readRows(
        DOMElement $table
    ): array {
        // Sort the table's row groups by where they display; rows with no section
        // in between are one group
        $groups = ['thead' => [], 'tbody' => [], 'tfoot' => []];
        $loose = null;

        foreach (self::childElements($table, ['thead', 'tbody', 'tfoot', 'tr']) as $section) {
            if ($section->tagName === 'tr') {
                $loose ??= \count($groups['tbody']);
                $groups['tbody'][$loose][] = $section;

                continue;
            }

            $loose = null;
            $groups[$section->tagName][] = self::childElements($section, ['tr']);
        }

        // Lay out each kind of row
        $head = $this->groupsGrid($groups['thead']);
        $foot = $this->groupsGrid($groups['tfoot']);
        $grid = \array_merge($head, $this->groupsGrid($groups['tbody']), $foot);

        // A first row of nothing but header cells is a header row without a thead
        $firstRowHeaders = $head !== [];
        $displayed = \array_merge($groups['thead'], $groups['tbody'], $groups['tfoot']);

        foreach (\array_merge(...$displayed) as $row) {
            $cells = self::childElements($row, ['td', 'th']);

            if ($cells !== []) {
                $firstRowHeaders = $firstRowHeaders || self::childElements($row, ['th']) === $cells;

                break;
            }
        }

        // Bring every row to the width of the widest
        $width = $grid === [] ? 0 : \max(\array_map(\count(...), $grid));

        return [
            'tableData' => \array_map(
                static fn (array $cells): array => ['cells' => \array_pad($cells, $width, '')],
                $grid
            ),
            'firstRowHeaders' => $firstRowHeaders,
            'lastRowFooters' => $foot !== [],
        ];
    }

    /**
     * Lays out each row group, joining their rows.
     *
     * @param  array<int, list<DOMElement>>  $groups  The row groups
     * @return list<list<string>> Each row's cells
     */
    private function groupsGrid(
        array $groups
    ): array {
        return \array_merge(
            ...\array_map($this->cellGrid(...), \array_values($groups))
        );
    }

    /**
     * Lays one row group out on a grid, so each cell stays in its own column.
     *
     * The `table` set has no merged cells, so a cell that spans keeps its content in
     * its first position and leaves the others blank. Without them, every cell after
     * a span would move into the wrong column. A row with no cells is left out, though
     * a rowspan still counts it. A cell past MAX_COLUMNS is dropped.
     *
     * @param  list<DOMElement>  $rows  The group's `tr` elements
     * @return list<list<string>> Each row's cells
     */
    private function cellGrid(
        array $rows
    ): array {
        // Columns still held by a rowspan from above, each with the rows it has left
        $held = [];
        $grid = [];

        foreach ($rows as $row) {
            $cells = self::childElements($row, ['td', 'th']);
            $line = [];
            $column = 0;

            foreach ($cells as $cell) {
                // Step past the columns held from above
                while (isset($held[$column])) {
                    $line[] = '';
                    $column++;
                }

                // A row is full at the limit
                if ($column >= self::MAX_COLUMNS) {
                    break;
                }

                $line[] = $this->getInnerHTML($cell);

                // Hold the rest of the cell's span
                $rowspan = self::rowspanOf($cell);
                $colspan = \min(self::colspanOf($cell), self::MAX_COLUMNS - $column);

                for ($offset = 0; $offset < $colspan; $offset++) {
                    $held[$column + $offset] = $rowspan;
                }

                $line = \array_pad($line, \count($line) + $colspan - 1, '');
                $column += $colspan;
            }

            // Count down the rows each hold has left, this one included
            foreach ($held as $heldColumn => $left) {
                if ($left <= 1) {
                    unset($held[$heldColumn]);
                } else {
                    $held[$heldColumn] = $left - 1;
                }
            }

            if ($cells !== []) {
                $grid[] = $line;
            }
        }

        return $grid;
    }

    /**
     * Reads a cell's colspan, as at least one and no more than the grid holds.
     *
     * @param  DOMElement  $cell  The `td` or `th` element
     * @return int The span
     */
    private static function colspanOf(
        DOMElement $cell
    ): int {
        return \max(1, \min(self::MAX_COLUMNS, (int) $cell->getAttribute('colspan')));
    }

    /**
     * Reads a cell's rowspan, as at least one, where zero means the rest of the group.
     *
     * @param  DOMElement  $cell  The `td` or `th` element
     * @return int The span
     */
    private static function rowspanOf(
        DOMElement $cell
    ): int {
        $rowspan = $cell->getAttribute('rowspan');

        return $rowspan === '0' ? PHP_INT_MAX : \max(1, (int) $rowspan);
    }

    /**
     * Returns an element's direct child elements with the given names.
     *
     * @param  DOMElement  $element  The parent element
     * @param  list<string>  $names  The tag names to keep
     * @return list<DOMElement> The child elements, in order
     */
    private static function childElements(
        DOMElement $element,
        array $names
    ): array {
        // Keep only the elements named
        $children = [];

        foreach ($element->childNodes as $child) {
            if ($child instanceof DOMElement && \in_array($child->tagName, $names, true)) {
                $children[] = $child;
            }
        }

        return $children;
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
                ! $figure instanceof DOMElement
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
     * @return string The inner HTML string
     */
    private function getInnerHTML(
        DOMNode $element
    ): string {
        // Build the inner HTML by rendering each child node.
        $innerHTML = '';
        foreach ($element->childNodes as $child) {
            $childHtml = $element->ownerDocument?->saveHTML($child);
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
