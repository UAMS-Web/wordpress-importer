<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use DOMNode;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\BlockProcessorInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\EmbedProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\FileBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\WpImageBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\WpPostFeaturedImageBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\QuoteProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TableProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpDetailsBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpGroupBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpSpacerBlockProcessor;

/**
 * Helper class for processing WordPress block comments using block processors.
 *
 * Provides a centralized method for extracting attributes from block comments
 * and processing them through a collection of block processors, following the
 * DRY principle to eliminate duplication between AcfBlockParser and
 * NonAcfBlockParser. Also provides a method to get common block processors
 * for InnerBlocks processing.
 */
class BlockProcessorHelper
{
    /**
     * Cache for common block processors to avoid repeated instantiation.
     *
     * @var ?array<BlockProcessorInterface>
     */
    private static ?array $commonProcessorsCache = null;

    /**
     * Gets the common block processors used for InnerBlocks processing.
     *
     * Returns an array of block processors (embed, image, post-featured-image,
     * table, quote, file) that are commonly used when processing InnerBlocks
     * content. Uses caching to avoid repeated instantiation.
     *
     * @return array<BlockProcessorInterface> Array of block processor instances
     */
    public static function getCommonProcessors(): array
    {
        // Check if processors cache needs initialization
        if (self::$commonProcessorsCache === null) {
            // Create ImageProcessor instance for image block processors
            $imageProcessor = new ImageProcessor;

            // Initialize array of common block processors
            self::$commonProcessorsCache = [
                new WpGroupBlockProcessor, // Process groups first to unwrap content
                new WpDetailsBlockProcessor, // Process details blocks to unwrap content
                new WpSpacerBlockProcessor, // Strip spacer blocks
                new EmbedProcessor,
                new WpImageBlockProcessor($imageProcessor),
                new WpPostFeaturedImageBlockProcessor($imageProcessor),
                new TableProcessor,
                new QuoteProcessor,
                new FileBlockProcessor,
            ];
        }

        // Return cached processors array
        return self::$commonProcessorsCache;
    }

    /**
     * Processes a DOM node using common block processors.
     *
     * Convenience method that processes a node using the common block processors
     * (embed, image, table, quote, file) used for InnerBlocks processing.
     *
     * @param  DOMNode  $node  The DOM node to process (typically a comment node)
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing assets
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|null Result with 'html' and 'skipCount' keys, or null if not processed
     */
    public static function processBlockNodeWithCommonProcessors(
        DOMNode $node,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): ?array {
        return self::processBlockNode(
            $node,
            self::getCommonProcessors(),
            $site,
            $assetImporter,
            $output,
            $attachments,
            $terms,
            $pageSlug
        );
    }

    /**
     * Converts a DOM node to an HTML fragment array.
     *
     * Safely converts a DOM node to HTML string and returns it in the standard
     * HTML fragment format used for accumulating content. Returns null if the
     * conversion fails.
     *
     * @param  DOMNode  $node  The DOM node to convert
     * @return array<int|string, mixed>|null HTML fragment array with 'html' key, or null if conversion fails
     */
    public static function convertNodeToHtmlFragment(
        DOMNode $node
    ): ?array {
        // Convert node to HTML string
        $ownerDocument = $node->ownerDocument;

        if (! $ownerDocument instanceof \DOMDocument) {
            return null;
        }

        $html = $ownerDocument->saveHTML($node);

        // Check if HTML conversion succeeded. The node always belongs to the document
        // that serializes it, so saveHTML() does not return false here and the flipped
        // comparison returns the same fragment.
        // @pest-mutate-ignore: FalseToTrue
        if ($html !== false) {
            // Return HTML fragment array format
            return ['html' => $html];
        }

        // Return null if conversion failed
        return null;
    }

    /**
     * Processes a DOM node using the provided block processors.
     *
     * Extracts attributes from WordPress block comments and attempts to process
     * the node using each processor in order until one succeeds. Returns the
     * processing result or null if no processor can handle the node.
     *
     * @param  DOMNode  $node  The DOM node to process (typically a comment node)
     * @param  array<int|string, mixed>  $processors  Array of BlockProcessorInterface instances to try
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing assets
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|null Result with 'html' and 'skipCount' keys, or null if not processed
     */
    public static function processBlockNode(
        DOMNode $node,
        array $processors,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): ?array {
        // Check if node is not a comment node (only comment nodes contain block definitions)
        if ($node->nodeType !== XML_COMMENT_NODE) {
            // Return null early for non-comment nodes
            return null;
        }

        // Read the comment text. A comment's nodeValue is always a string, so the
        // fallback is for static analysis only and no input reaches it.
        $nodeValue = $node->nodeValue;
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $comment = is_string($nodeValue) ? $nodeValue : '';

        // Initialize empty attributes array
        $attributes = [];

        // Check if comment contains JSON attributes in braces
        if (
            \preg_match(
                '/{.+?}/s',
                $comment,
                $matches
            ) === 1
        ) {
            // Decode JSON attributes from comment
            $decodedAttributes = \json_decode(
                $matches[0],
                true
            );
            $attributes = is_array($decodedAttributes) ? $decodedAttributes : [];
        }

        // Loop through each processor to find one that can handle this node
        foreach ($processors as $processor) {
            // Check if processor implements BlockProcessorInterface and can process this node
            if (
                $processor instanceof BlockProcessorInterface
                && $processor->canProcess(
                    $node,
                    $site,
                    $pageSlug
                )
            ) {
                // Process node using the matched processor
                $result = $processor->process(
                    $node,
                    $attributes,
                    $site,
                    $assetImporter,
                    $output,
                    $attachments,
                    $terms,
                    $pageSlug
                );

                // Check if processing was successful
                if ($result !== null && $result !== '') {
                    // Normalize result format
                    if (\is_string($result)) {
                        return [
                            'html' => $result,
                            'skipCount' => 1,
                        ];
                    }

                    $result['skipCount'] ??= 1;

                    // Return normalized result array
                    return $result;
                }
            }
        }

        // Return null if no processor could handle this node
        return null;
    }
}
