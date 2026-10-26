<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap;

use Exception;
use Tiptap\Core\DOMParser;
use Tiptap\Core\Schema;
use Tiptap\Editor as BaseEditor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\ListItem;

/**
 * Custom Tiptap Editor extension for WordPress content import.
 *
 * Extends the base Tiptap Editor to post-process ProseMirror JSON output during
 * WordPress content imports, ensuring that text nodes under `listItem` nodes are
 * wrapped in `paragraph` nodes and that duplicate marks (e.g., multiple `strike`
 * marks from nested `<s>`, `<del>`, `<strike>`, or `text-decoration` styles) are
 * deduplicated. This addresses Tiptap issues where the default `ListItem` node's
 * `wrapper` method wraps mixed content in a `paragraph` node and where the
 * `Strike` mark's `parseHTML` rules apply multiple `strike` marks for nested
 * elements. This class ensures the correct JSON structure for Statamic Bard
 * fields, with nested lists as siblings to `paragraph` nodes and single instances
 * of marks per text node.
 *
 * @see  ListItem
 */
class Editor extends BaseEditor
{
    /**
     * Overrides setContent to parse input and apply post-processing.
     *
     * Processes HTML, JSON, or array input to generate a ProseMirror JSON document,
     * applies the schema, and post-processes the document to wrap text nodes under
     * `listItem` nodes in `paragraph` nodes and deduplicate marks (e.g., `strike`) to
     * prevent multiple identical marks on the same text node.
     *
     * @param  mixed  $value  The input content (HTML string, JSON string, or array)
     * @return $this Returns the instance for method chaining
     *
     * @throws Exception If the input format is unrecognized
     */
    public function setContent(
        $value
    ): Editor {
        // Determine the declared content type once for branching.
        $contentType = $this->getContentType($value);

        // Determine input type and parse accordingly
        if (
            $contentType === 'HTML'
            && \is_string($value)
        ) {
            // Parse HTML input using Tiptap's DOMParser
            $this->document = new DOMParser($this->schema)->process($value);
        } elseif (
            $contentType === 'Array'
            && \is_array($value)
        ) {
            // Convert array input to JSON-compatible array
            $encodedValue = \json_encode($value);

            // Skip decoding when JSON encoding fails.
            if (! \is_string($encodedValue)) {
                throw new Exception('Unable to encode array content as JSON.');
            }

            $decodedValue = \json_decode(
                $encodedValue,
                true
            );

            // Keep only array-like document payloads.
            if (\is_array($decodedValue)) {
                $this->document = $decodedValue;
            }
        } elseif (
            $contentType === 'JSON'
            && \is_string($value)
        ) {
            // Parse JSON string input
            $decodedValue = \json_decode(
                $value,
                true
            );

            // Keep only array-like document payloads.
            if (\is_array($decodedValue)) {
                $this->document = $decodedValue;
            }
        } else {
            throw new Exception('Unrecognized content type for editor input.');
        }

        // Ensure parsed document is an array before schema application.
        if (! \is_array($this->document)) {
            throw new Exception('Unable to parse editor content into a document.');
        }

        // Ensure the schema instance is valid before applying it.
        if (! $this->schema instanceof Schema) {
            throw new Exception('Editor schema is unavailable.');
        }

        // Apply schema to ensure document validity
        $this->document = $this->schema->apply(
            $this->document
        );

        // Ensure schema application still yields an array document.
        if (! \is_array($this->document)) {
            throw new Exception('Schema application returned an invalid document.');
        }

        // Post-process to wrap text nodes in paragraphs under listItem nodes
        $this->wrapTextNodesInListItems();

        // Post-process to deduplicate marks
        $this->deduplicateMarks();

        // Post-process to trim trailing whitespace from codeBlock text content
        $this->trimCodeBlockTextContent();

        // Post-process to convert non-breaking spaces to escaped underscores
        $this->convertNonBreakingSpaces();

        return $this;
    }

    /**
     * Wraps text nodes directly under listItem nodes in paragraph nodes.
     *
     * Traverses the ProseMirror JSON document to identify `listItem` nodes and wraps
     * any direct `text` nodes in a `paragraph` node, ensuring compliance with
     * ProseMirror's requirement for block-level nodes while preserving nested
     * `bulletList` or `orderedList` nodes as direct children. This method addresses
     * the Tiptap issue by correcting the JSON structure after parsing, as the custom
     * `ListItem` node prevents initial paragraph wrapping.
     */
    private function wrapTextNodesInListItems(): void
    {
        // Exit if document is not an array
        if (! \is_array($this->document)) {
            return;
        }

        // Process the document recursively
        $this->processNode($this->document);
    }

    /**
     * Deduplicates marks in the ProseMirror JSON document.
     *
     * Traverses the JSON document to identify nodes with `marks` arrays and removes
     * duplicate marks (e.g., multiple `strike` marks applied to the same text node
     * due to nested `<s>`, `<del>`, or `text-decoration` styles). This ensures that
     * each mark type appears only once per node, preventing redundant styling in the
     * Tiptap editor.
     */
    private function deduplicateMarks(): void
    {
        // Exit if document is not an array
        if (! \is_array($this->document)) {
            return;
        }

        // Process the document recursively
        $this->processNodeForMarks($this->document);
    }

    /**
     * Recursively processes nodes to wrap text nodes under listItem in paragraph
     * nodes.
     *
     * Traverses the JSON document, processing each node. For `listItem` nodes, it
     * wraps `text` nodes in a `paragraph` node while preserving other nodes
     * (e.g., `bulletList`, `orderedList`) as direct children. Recursively processes
     * child nodes to handle nested structures. This ensures the correct JSON
     * hierarchy for Statamic Bard fields, addressing the Tiptap issue where nested
     * lists were incorrectly wrapped in `paragraph` nodes.
     *
     * @param  array<int|string, mixed>  &$node  The current node being processed (passed by reference)
     */
    private function processNode(
        array &$node
    ): void {
        // Skip if node lacks a type
        if (
            ! isset($node['type'])
            || ! \is_string($node['type'])
        ) {
            return;
        }

        // Process listItem nodes to wrap text nodes in paragraphs
        if (
            $node['type'] === 'listItem'
            && isset($node['content'])
            && \is_array($node['content'])
        ) {
            $newContent = []; // New content array for the listItem node
            $currentParagraph = null; // Tracks the current paragraph being built

            // Iterate through child nodes
            foreach ($node['content'] as $child) {
                if (
                    \is_array($child)
                    && isset($child['type'])
                    && $child['type'] === 'text'
                ) {
                    $currentParagraph ??= [
                        'type' => 'paragraph',
                        'content' => [],
                    ];

                    // Add text node to the current paragraph
                    $currentParagraph['content'][] = $child;
                } else {
                    // Close any open paragraph and add it to newContent
                    if ($currentParagraph !== null) {
                        $newContent[] = $currentParagraph;
                        $currentParagraph = null;
                    }

                    // Add non-text nodes (e.g., bulletList, orderedList) directly
                    $newContent[] = $child;
                }
            }

            // Add the final paragraph if it exists
            if ($currentParagraph !== null) {
                $newContent[] = $currentParagraph;
            }

            // Update the node's content
            $node['content'] = $newContent;
        }

        // Recursively process child nodes
        if (isset($node['content']) && \is_array($node['content'])) {
            foreach ($node['content'] as &$child) {
                if (! \is_array($child)) {
                    continue;
                }

                $this->processNode($child);
            }

            unset($child);
        }
    }

    /**
     * Recursively processes nodes to deduplicate marks.
     *
     * Traverses the JSON document, processing each node. For nodes with a `marks`
     * array, it removes duplicate marks by keeping only the first occurrence of each
     * mark type (e.g., `strike`). This addresses cases where nested HTML elements
     * (e.g., `<s><del>Text</del></s>`) result in multiple identical marks being
     * applied to the same text node.
     *
     * @param  array<int|string, mixed>  &$node  The current node being processed (passed by reference)
     */
    private function processNodeForMarks(
        array &$node
    ): void {
        // Skip if node lacks a type
        if (
            ! isset($node['type'])
            || ! \is_string($node['type'])
        ) {
            return;
        }

        // Deduplicate marks if present
        if (isset($node['marks']) && \is_array($node['marks'])) {
            $uniqueMarks = [];
            $seenTypes = [];

            foreach ($node['marks'] as $mark) {
                if (
                    ! \is_array($mark)
                    || ! isset($mark['type'])
                    || ! \is_string($mark['type'])
                    || \in_array(
                        $mark['type'],
                        $seenTypes,
                        true
                    )
                ) {
                    continue; // Skip duplicate mark types
                }

                $seenTypes[] = $mark['type'];
                $uniqueMarks[] = $mark;
            }

            // Update marks array (or remove it if empty)
            if ($uniqueMarks === []) {
                unset($node['marks']);
            } else {
                $node['marks'] = $uniqueMarks;
            }
        }

        // Recursively process child nodes
        if (isset($node['content']) && \is_array($node['content'])) {
            foreach ($node['content'] as &$child) {
                if (! \is_array($child)) {
                    continue;
                }

                $this->processNodeForMarks($child);
            }

            unset($child);
        }
    }

    /**
     * Trims trailing whitespace from text content in codeBlock nodes.
     *
     * Traverses the ProseMirror JSON document to identify `codeBlock` nodes and
     * trims trailing whitespace from their text content. This ensures that
     * code blocks don't have unwanted trailing newlines that can cause test
     * failures or display issues.
     */
    private function trimCodeBlockTextContent(): void
    {
        // Check if document is not an array
        if (! \is_array($this->document)) {
            // Exit early if document is invalid
            return;
        }

        // Process the document recursively
        $this->processNodeForCodeBlockText($this->document);
    }

    /**
     * Recursively processes nodes to trim trailing whitespace from codeBlock text
     * content.
     *
     * Traverses the JSON document, processing each node. For `codeBlock` nodes, it
     * trims trailing whitespace from text nodes within their content. Recursively
     * processes child nodes to handle nested structures.
     *
     * @param  array<int|string, mixed>  &$node  The current node being processed (passed by reference)
     */
    private function processNodeForCodeBlockText(
        array &$node
    ): void {
        // Skip nodes that lack a type
        if (
            ! isset($node['type'])
            || ! \is_string($node['type'])
        ) {
            // Skip invalid nodes
            return;
        }

        // Process codeBlock nodes to trim trailing whitespace from text content
        if (
            $node['type'] === 'codeBlock'
            && isset($node['content'])
            && \is_array($node['content'])
        ) {
            // Loop through child nodes in the codeBlock
            foreach ($node['content'] as &$child) {
                // Check if child is a text node with text content
                if (
                    \is_array($child)
                    && isset($child['type'])
                    && $child['type'] === 'text'
                    && isset($child['text'])
                    && \is_string($child['text'])
                ) {
                    // Trim trailing whitespace from the text content
                    $child['text'] = \rtrim($child['text']);
                }
            }

            unset($child);
        }

        // Check if node has content to process recursively
        if (
            isset($node['content'])
            && \is_array($node['content'])
        ) {
            // Loop through child nodes for recursive processing
            foreach ($node['content'] as &$childNode) {
                if (! \is_array($childNode)) {
                    continue;
                }

                // Recursively process each child node
                $this->processNodeForCodeBlockText($childNode);
            }

            unset($childNode);
        }
    }

    /**
     * Converts non-breaking spaces to escaped underscores in text nodes.
     *
     * Traverses the ProseMirror JSON document to identify `text` nodes and converts
     * non-breaking spaces (U+00A0) to `\_` (escaped underscore) in their text content.
     * This ensures that non-breaking spaces are preserved in a format that can be
     * properly stored and displayed in Statamic Bard fields.
     */
    private function convertNonBreakingSpaces(): void
    {
        // Check if document is not an array
        if (! \is_array($this->document)) {
            // Exit early if document is invalid
            return;
        }

        // Process the document recursively
        $this->processNodeForNonBreakingSpaces($this->document);
    }

    /**
     * Recursively processes nodes to convert non-breaking spaces to escaped
     * underscores in text content.
     *
     * Traverses the JSON document, processing each node. For `text` nodes, it converts
     * non-breaking spaces (U+00A0) to `\_` (escaped underscore) in their text content.
     * Recursively processes child nodes to handle nested structures.
     *
     * @param  array<int|string, mixed>  &$node  The current node being processed (passed by reference)
     */
    private function processNodeForNonBreakingSpaces(
        array &$node
    ): void {
        // Skip nodes that lack a type
        if (
            ! isset($node['type'])
            || ! \is_string($node['type'])
        ) {
            // Skip invalid nodes
            return;
        }

        // ---
        // Process text nodes to convert non-breaking spaces
        // ---

        // Check if this is a text node with string text content
        if (
            $node['type'] === 'text'
            && isset($node['text'])
            && \is_string($node['text'])
        ) {
            // Convert non-breaking space (U+00A0) to escaped underscore
            $node['text'] = \str_replace(
                "\u{00A0}",
                '\_',
                $node['text']
            );
        }

        // Check if node has content to process recursively
        if (
            isset($node['content'])
            && \is_array($node['content'])
        ) {
            // Loop through child nodes for recursive processing
            foreach ($node['content'] as &$child) {
                if (! \is_array($child)) {
                    continue;
                }

                // Recursively process each child node
                $this->processNodeForNonBreakingSpaces($child);
            }

            unset($child);
        }
    }
}
