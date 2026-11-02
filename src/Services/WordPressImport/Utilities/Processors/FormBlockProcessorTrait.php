<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use DOMNode;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;

/**
 * Shared functionality for form block processors.
 *
 * This trait provides common methods used by both Gravity Forms and Formidable
 * Forms block processors to eliminate code duplication.
 */
trait FormBlockProcessorTrait
{
    /**
     * Use base form processor functionality.
     */
    use FormProcessorTrait;

    /**
     * Determines if the processor can handle the given DOM node.
     *
     * @param  DOMNode  $node  The DOM node to check
     * @param  string  $site  The site handle
     * @param  string  $blockPattern  The regex pattern for the specific form block
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return bool True if the node is a form block comment, false otherwise
     */
    protected function canProcessFormBlock(
        DOMNode $node,
        string $site,
        string $blockPattern,
        ?string $pageSlug
    ): bool {
        // Check if the node is a comment node
        if ($node->nodeType !== XML_COMMENT_NODE) {
            return false;
        }

        // Check if the comment matches the form block pattern
        $commentValue = $node->nodeValue;

        if (! \is_string($commentValue)) {
            return false;
        }

        return \preg_match(
            $blockPattern,
            \trim($commentValue)
        ) === 1;
    }

    /**
     * Processes a form block into Tiptap-compatible HTML.
     *
     * @param  DOMNode  $node  The DOM node (comment node)
     * @param  array<int|string, mixed>  $attributes  Block attributes from the form block comment
     * @param  string  $site  Site handle for context
     * @param  AssetImporter  $assetImporter  Asset importer instance (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects (unused)
     * @param  string  $pluginName  The plugin name for logging (e.g., 'Gravity Forms', 'Formidable Forms')
     * @param  int  $skipCount  The number of nodes to skip after processing
     * @param  ?string  $pageSlug  The page slug (optional)
     * @return array<int|string, mixed>|null Array with 'html', 'processedNode', and 'skipCount', or null if invalid
     */
    protected function processFormBlock(
        DOMNode $node,
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        string $pluginName,
        int $skipCount,
        ?string $pageSlug = null
    ): ?array {
        $processorName = $pluginName.'BlockProcessor';

        // Extract formId from attributes array
        $formId = $this->formIdFromBlockAttributes($attributes);

        // Track form ID for import and linking
        $this->trackFormId($formId);

        // Log a warning if formId is missing or not numeric
        if ($formId === null) {
            $this->logFormProcessingWarning(
                $processorName,
                $pluginName,
                $site,
                $formId,
                $attributes,
                $pageSlug
            );

            // Return null if formId is invalid
            return null;
        }

        // Check if we have a form handle for this form ID
        $formHandle = $this->getFormHandle(
            $formId,
            $pluginName
        );

        // Prepare the values array for the Bard set
        $values = $this->buildFormBardSetValues(
            $formId,
            $pluginName,
            $formHandle,
            $attributes,
            [
                $this,
                'handleTitleAndDescription',
            ]
        );

        // Convert to Tiptap HTML
        // Build the HTML attributes string for the Bard set div
        $attrs = '';

        // Add each value as a data attribute, except for 'type' and null values
        foreach ($values as $key => $value) {
            // Only add attributes for keys other than 'type' and non-null values
            if (
                $key !== 'type'
                && $value !== null
                && \is_string($key)
                && \is_scalar($value)
            ) {
                $attrs .= \sprintf(
                    ' data-set-attr-%s="%s"',
                    htmlspecialchars($key),
                    htmlspecialchars((string) $value)
                );
            }
        }

        // Generate the final HTML for the Bard set
        $html = \sprintf(
            '<div data-set-type="form"%s%s></div>',
            SetMarkupNonce::attribute(),
            $attrs
        );

        // Return the processed result as an array with HTML, node, and skip count
        return [
            'html' => $html, // Tiptap-compatible HTML for Bard set
            'processedNode' => $node, // The processed DOM node
            'skipCount' => $skipCount, // Skip comment and inner <div> containing shortcode
        ];
    }

    /**
     * Normalize a form block formId attribute to int, string, or null.
     *
     * @param  array<int|string, mixed>  $attributes  Block attributes from the form block comment
     * @return int|string|null Form ID when present and numeric, otherwise null
     */
    private function formIdFromBlockAttributes(
        array $attributes
    ): int|string|null {
        if (
            ! isset($attributes['formId'])
            || $attributes['formId'] === ''
        ) {
            return null;
        }

        $formId = $attributes['formId'];

        if (! \is_numeric($formId)) {
            return null;
        }

        if (\is_int($formId) || \is_string($formId)) {
            return $formId;
        }

        return (string) $formId;
    }
}
