<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMComment;
use DOMNode;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Processes WordPress wp:tablepress/table blocks into Statamic Bard sets.
 *
 * Converts TablePress blocks into table Bard sets, extracting the id from JSON
 * attributes and ensuring it matches the data type of
 * _tablepress_export_table_id. Stores the id for table import processing via
 * TablePressShortcodeProcessor. Ignores nested [table] shortcodes as they are
 * handled by the block.
 */
class TablePressBlockProcessor implements BlockProcessorInterface
{
    /**
     * Checks if the processor can handle the given DOM node.
     *
     * Determines if the node is a wp:tablepress/table comment node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug
     * @return bool True if the node is a wp:tablepress/table comment
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        // Only a comment can open a block, and its text is always a string
        if (! $node instanceof DOMComment) {
            return false;
        }

        return \preg_match(
            '/^wp:tablepress\/table\s*({.+?})?\s*$/s',
            \trim($node->data)
        ) === 1;
    }

    /**
     * Processes a wp:tablepress block into Tiptap-compatible HTML.
     *
     * Extracts the id from JSON attributes, converts it to a string, and generates
     * a table Bard set with initial attributes for Tiptap parsing. Stores the id
     * for table import processing using TablePressShortcodeProcessor. Logs
     * warnings for missing id attributes and minimal info for extracted IDs to
     * reduce verbosity.
     *
     * @param  DOMNode  $node  The wp:tablepress block comment node
     * @param  array<int|string, mixed>  $attributes  Block attributes from JSON
     * @param  string  $site  Site handle
     * @param  AssetImporter  $assetImporter  Asset importer (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Media attachments (unused)
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
        // Extract and convert id to string
        $tableId = self::tableIdFromAttributes($attributes);
        if ($tableId === null || $tableId === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                ]
            );

            // Log warning about missing id attribute
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "TablePressBlockProcessor::process: Missing 'id' in wp:tablepress block",
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // ---
        // Note: We don't call TablePressShortcodeProcessor::addTableId() here because
        // the shortcode processor will handle the [table] shortcode content and
        // the suppression mechanism will prevent duplicate processing
        // ---

        // Generate HTML for Tiptap with table set attributes
        $html = \sprintf(
            '<div data-set-type="table"%s data-set-attr-create_query="collection" '.
            'data-set-attr-table_entry="" data-set-attr-heading_query="false" '.
            'data-set-attr-description_query="false" data-set-attr-heading_level="h2" '.
            'data-set-attr-align="aligncenter"></div>',
            SetMarkupNonce::attribute()
        );

        return [
            'html' => $html,
            'processedNode' => $node,
            'skipCount' => 3, // Skip opening comment, shortcode, closing comment
        ];
    }

    /**
     * Extract a TablePress table ID from block attributes.
     *
     * @param  array<int|string, mixed>  $attributes  Block attributes from JSON
     * @return string|null The table ID, or null when unavailable
     */
    private static function tableIdFromAttributes(
        array $attributes
    ): ?string {
        if (! isset($attributes['id'])) {
            return null;
        }

        $id = $attributes['id'];

        if (is_string($id)) {
            return $id;
        }

        if (is_int($id)) {
            return (string) $id;
        }

        return null;
    }
}
