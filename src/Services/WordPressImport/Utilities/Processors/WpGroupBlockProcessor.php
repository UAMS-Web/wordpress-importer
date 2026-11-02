<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMNode;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits\UnwrappingBlockProcessorTrait;

/**
 * Processes WordPress wp:group blocks by unwrapping their contents recursively.
 *
 * Always unwraps wp:group blocks and their nested content. When a wp:group block
 * has align="wide" or align="full" and contains only a table (either
 * wp:shortcode with [table] or wp:tablepress/table), sets align: alignwide on
 * the resulting table Bard set.
 */
class WpGroupBlockProcessor implements BlockProcessorInterface
{
    use UnwrappingBlockProcessorTrait;

    /**
     * Gets the block name for this processor.
     *
     * @return string The block name without the 'wp:' prefix
     */
    protected function getBlockName(): string
    {
        return 'group';
    }

    /**
     * Gets the wrapper element name.
     *
     * @return string The wrapper element name
     */
    protected function getWrapperElementName(): string
    {
        return 'div';
    }

    /**
     * Gets the wrapper class prefix.
     *
     * @return string The wrapper class prefix
     */
    protected function getWrapperClassPrefix(): string
    {
        return 'wp-block-group';
    }

    /**
     * Checks if the processor can handle the given DOM node.
     *
     * Determines if the node is a wp:group comment node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug
     * @return bool True if the node is a wp:group comment
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
     * Processes a wp:group block by unwrapping its contents recursively.
     *
     * Always unwraps the group block and processes its inner content. Nested
     * wp:group blocks are also unwrapped recursively. If the group has
     * align="wide" or align="full" and contains only a table, sets align:
     * alignwide on the resulting table set.
     *
     * @param  DOMNode  $node  The wp:group block comment node
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
        // Store attributes for use in processBardContent
        $this->currentAttributes = $attributes;

        // Always use trait's processUnwrappingBlock method to unwrap recursively
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
     * Current block attributes for use in processBardContent.
     *
     * @var array<int|string, mixed>
     */
    private array $currentAttributes = [];

    /**
     * Processes Bard content after TextProcessor processing.
     *
     * Modifies table align for wide/full aligned groups that contain only a table.
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
        // Extract the align attribute from group attributes
        $align = $this->currentAttributes['align'] ?? null;

        // Check if group has wide or full alignment
        $hasWideOrFullAlign = (
            $align === 'wide'
            || $align === 'full'
        );

        // Check if group has wide/full align and contains only a table
        $containsOnlyTable = $this->containsOnlyTable($innerContent);

        // Check if we need to modify table align for wide/full aligned groups
        if (
            $hasWideOrFullAlign
            && $containsOnlyTable
            && $processedBardContent !== []
        ) {
            // Find the table set in the processed content and modify align
            foreach ($processedBardContent as &$item) {
                if (! \is_array($item)) {
                    continue;
                }

                if (
                    isset($item['type'])
                    && $item['type'] === 'set'
                    && isset($item['attrs'])
                    && \is_array($item['attrs'])
                    && isset($item['attrs']['values'])
                    && \is_array($item['attrs']['values'])
                    && isset($item['attrs']['values']['type'])
                    && $item['attrs']['values']['type'] === 'table'
                ) {
                    // Set align to alignwide for wide/full aligned tables
                    $item['attrs']['values']['align'] = 'alignwide';

                    // Exit loop after finding and modifying the table set
                    break;
                }
            }
        }

        // Return the processed Bard content
        return $processedBardContent;
    }

    /**
     * Checks if inner content contains only a table.
     *
     * Determines if the content contains only a wp:shortcode block with [table]
     * shortcode or a wp:tablepress/table block, with no other significant content.
     * Handles nested wp:group blocks recursively.
     *
     * @param  string  $innerContent  The inner content HTML string
     * @return bool True if content contains only a table
     */
    private function containsOnlyTable(
        string $innerContent
    ): bool {
        // Trim whitespace from content
        $trimmed = \trim($innerContent);

        // Check if content is a wp:shortcode block with [table] shortcode
        if (
            \preg_match(
                '/^<!--\s*wp:shortcode\s*-->.*?\[table\s+id=\d+\s*\/?\].*?<!--\s*\/wp:shortcode\s*-->$/is',
                $trimmed
            ) === 1
        ) {
            // Return true if content matches wp:shortcode with [table] pattern
            return true;
        }

        // Check if content is a wp:tablepress/table block
        if (
            \preg_match(
                '/^<!--\s*wp:tablepress\/table\s*({.*?})?\s*-->.*?<!--\s*\/wp:tablepress\/table\s*-->$/is',
                $trimmed
            ) === 1
        ) {
            // Return true if content matches wp:tablepress/table block pattern
            return true;
        }

        // Check if content contains wp:shortcode with [table] inside a group wrapper
        if (
            \preg_match(
                '/^<div[^>]*>.*?<!--\s*wp:shortcode\s*-->.*?\[table\s+id=\d+\s*\/?\].*?<!--\s*\/wp:shortcode\s*-->.*?<\/div>$/is',
                $trimmed
            ) === 1
        ) {
            // Return true if content contains wp:shortcode with [table] inside a group wrapper
            return true;
        }

        // Check if content contains wp:tablepress/table inside a group wrapper
        if (
            \preg_match(
                '/^<div[^>]*>.*?<!--\s*wp:tablepress\/table\s*({.*?})?\s*-->.*?<!--\s*\/wp:tablepress\/table\s*-->.*?<\/div>$/is',
                $trimmed
            ) === 1
        ) {
            // Return true if content contains wp:tablepress/table inside a group wrapper
            return true;
        }

        // ---
        // Check if content is a nested wp:group block containing only a table
        //
        // Pattern: <!-- wp:group --><div>...table...</div><!-- /wp:group -->
        // ---
        if (
            \preg_match(
                '/^<!--\s*wp:group\s*({.*?})?\s*-->\s*<div[^>]*>(.*?)<\/div>\s*<!--\s*\/wp:group\s*-->$/is',
                $trimmed,
                $matches
            ) === 1
        ) {
            // Recursively check if the nested group content contains only a table
            return $this->containsOnlyTable($matches[2]);
        }

        // A nested group inside a wrapper div needs no pattern of its own: any table
        // it holds is already matched by the wrapper patterns above.
        // Return false if no table pattern matches
        return false;
    }
}
