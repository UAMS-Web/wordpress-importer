<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMNode;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Interface for processing WordPress block types.
 */
interface BlockProcessorInterface
{
    /**
     * Determines if the processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the processor can handle the node, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool;

    /**
     * Processes the DOM node and returns Tiptap-compatible HTML or processing details.
     *
     * @param  DOMNode  $node  The DOM node to process
     * @param  array<int|string, mixed>  $attributes  Block attributes (e.g., from JSON in comment)
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  Asset importer instance
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Media attachments
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return string|array<int|string, mixed>|null Tiptap HTML string or array with 'html', 'processedNode', and 'skipCount', or null if processing fails
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
    ): string|array|null;
}
