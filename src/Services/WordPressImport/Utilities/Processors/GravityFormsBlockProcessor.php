<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMNode;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Processes WordPress wp:gravityforms/form blocks into Tiptap-compatible HTML for
 * Statamic Bard sets.
 */
class GravityFormsBlockProcessor implements BlockProcessorInterface
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
     * @return bool True if the node is a wp:gravityforms/form comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        return $this->canProcessFormBlock(
            $node,
            $site,
            '/^wp:gravityforms\/form(?:\s*({.+?}))?\s*(?:\/)?$/s',
            $pageSlug
        );
    }

    /**
     * Processes a wp:gravityforms/form block into Tiptap-compatible HTML.
     *
     * Generates a form Bard set with import_wordpress_notes indicating the form ID
     * or 'Gravity Forms form ID not specified' if formId is missing or invalid.
     * Tracks valid form IDs for validation. Returns an array with HTML, processed
     * node, and skip count for self-closing blocks.
     *
     * @param  DOMNode  $node  The DOM node (comment node)
     * @param  array<int|string, mixed>  $attributes  Block attributes from the wp:gravityforms/form comment
     * @param  string  $site  Site handle for context
     * @param  AssetImporter  $assetImporter  Asset importer instance (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Media attachments (unused)
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed> Array with 'html', 'processedNode', and 'skipCount'
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
            'Gravity Forms',
            1,
            $pageSlug
        );
    }

    /**
     * Handle title and description attributes for Gravity Forms blocks.
     *
     * Sets heading_query, heading_level, and description_query when title/description
     * are not explicitly set in the block attributes.
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

        // Extract description attribute from block attributes, defaulting to null if not set
        $description = $attributes['description'] ?? null;

        // Check if title is not explicitly set (null means not specified in block)
        if ($title === null) {
            // Set heading_query to enable form title display
            $values['heading_query'] = true;

            // Set heading_level to h2 for form title styling
            $values['heading_level'] = 'h2';
        }

        // Check if description is not explicitly set (null means not specified in block)
        if ($description === null) {
            // Set description_query to enable form description display
            $values['description_query'] = true;
        }
    }
}
