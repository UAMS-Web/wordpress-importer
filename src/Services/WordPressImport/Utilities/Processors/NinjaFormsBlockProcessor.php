<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMNode;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Processes WordPress wp:ninja-forms/form blocks into Tiptap-compatible
 * HTML for Statamic Bard sets.
 */
class NinjaFormsBlockProcessor implements BlockProcessorInterface
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
     * @return bool True if the node is a wp:ninja-forms/form comment, false otherwise
     */
    public function canProcess(
        DOMNode $node,
        string $site,
        ?string $pageSlug
    ): bool {
        return $this->canProcessFormBlock(
            $node,
            $site,
            '/^wp:ninja-forms\/form(?:\s*({.+?}))?\s*(?:\/)?$/s',
            $pageSlug
        );
    }

    /**
     * Processes a wp:ninja-forms/form block into Tiptap-compatible HTML.
     *
     * @param  DOMNode  $node  The DOM node (comment node)
     * @param  array<int|string, mixed>  $attributes  Block attributes from the wp:ninja-forms/form comment
     * @param  string  $site  Site handle for context
     * @param  AssetImporter  $assetImporter  Asset importer instance (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects (unused)
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML (unused)
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
        // ---
        // Ninja Forms spells the block attribute `formID` (capital D) where the shared
        // trait reads `formId`. Alias it before delegating rather than overriding the
        // trait's extraction, so every plugin keeps one code path for the value itself.
        // The original key is left in place; nothing downstream reads it, and dropping it
        // would silently discard a value the export actually carried.
        // ---
        if (! isset($attributes['formId']) && isset($attributes['formID'])) {
            $attributes['formId'] = $attributes['formID'];
        }

        // The block is self-closing, so only the comment node is consumed — matching the
        // Gravity Forms skip count of 1 rather than Formidable's 2 (comment + inner div).
        return $this->processFormBlock(
            $node,
            $attributes,
            $site,
            $assetImporter,
            $output,
            $attachments,
            'Ninja Forms',
            1,
            $pageSlug
        );
    }

    /**
     * Handle title and description attributes for Ninja Forms blocks.
     *
     * Deliberately a no-op. Ninja Forms blocks carry only `formID` and `formTitle` —
     * verified against every `wp:ninja-forms/form` block in the export corpus, which
     * yields exactly three distinct attribute sets, none containing a display toggle.
     * There is no block-level equivalent of the Gravity Forms `title` / Formidable Forms
     * `title` attributes that the sibling processors read.
     *
     * Whether the heading renders is a **form** setting in Ninja Forms, not a block one:
     * `settings.show_title` inside the `.nff` export. It is `0` on three of the four
     * production forms. That belongs to the form-configuration import rather than here,
     * so this leaves `heading_query` / `description_query` unset rather than inventing a
     * heading the source never asked for.
     *
     * The two siblings also disagree on their own convention — Gravity Forms treats an
     * absent `title` as "show", Formidable treats `'1'` as "show" — so there is no shared
     * default to inherit even if a toggle existed.
     *
     * @param  array<int|string, mixed>  $values  The values array to modify
     * @param  array<int|string, mixed>  $attributes  The block attributes
     */
    public function handleTitleAndDescription(
        array &$values,
        array $attributes
    ): void {
        // Intentionally empty — see the docblock above.
    }
}
