<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMNode;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Processes WordPress wp:formidable/simple-form blocks into Tiptap-compatible
 * HTML for Statamic Bard sets.
 */
class FormidableFormsBlockProcessor implements BlockProcessorInterface
{
    /**
     * Use shared functionality for form block processing.
     */
    use FormBlockProcessorTrait;

    /**
     * Determines if the processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a wp:formidable/simple-form comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        return $this->canProcessFormBlock(
            $node,
            $site,
            '/^wp:formidable\/simple-form(?:\s*({.+?}))?\s*(?:\/)?$/s',
            $pageSlug
        );
    }

    /**
     * Processes a wp:formidable/simple-form block into Tiptap-compatible HTML.
     *
     * @param  DOMNode  $node  The DOM node (comment node)
     * @param  array<int|string, mixed>  $attributes  Block attributes from the wp:formidable/simple-form comment
     * @param  string  $site  Site handle for context
     * @param  AssetImporter  $assetImporter  Asset importer instance (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects (unused)
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|null Array with 'html', 'processedNode', and 'skipCount', or null if invalid
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
    ): ?array {
        return $this->processFormBlock(
            $node,
            $attributes,
            $site,
            $assetImporter,
            $output,
            $attachments,
            'Formidable Forms',
            2,
            $pageSlug
        );
    }

    /**
     * Handle title and description attributes for Formidable Forms blocks.
     *
     * Sets heading_query, heading_level, and description_query when title/description
     * are set to '1' in the block attributes.
     *
     * @param  array<int|string, mixed>  $values  The values array to modify
     * @param  array<int|string, mixed>  $attributes  The block attributes
     */
    public function handleTitleAndDescription(
        array &$values,
        array $attributes
    ): void {
        // Extract title attribute from block attributes, defaulting to null if not set
        $title = $attributes['title'] ?? null;

        // ---
        // Extract description attribute from block attributes, defaulting to null if not
        // set
        // ---
        $description = $attributes['description'] ?? null;

        // Check if title is explicitly set to '1' (enables title display)
        if ($title === '1') {
            // Set heading_query to enable form title display
            $values['heading_query'] = true;

            // Set heading_level to h2 for form title styling
            $values['heading_level'] = 'h2';
        }

        // Check if description is explicitly set to '1' (enables description display)
        if ($description === '1') {
            // Set description_query to enable form description display
            $values['description_query'] = true;
        }
    }
}
