<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes;

use DOMNode;
use Tiptap\Nodes\ListItem as BaseListItem;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Editor;

/**
 * Custom Tiptap ListItem node extension for WordPress content import.
 *
 * Extends the base Tiptap ListItem node to override the default `wrapper` method,
 * preventing automatic wrapping of mixed content (text and nested lists) in a
 * `paragraph` node during HTML parsing. This addresses a Tiptap issue where the
 * default `ListItem` node's `wrapper` method wraps content in a `paragraph` node,
 * causing nested `bulletList` or `orderedList` nodes to be incorrectly placed
 * inside `paragraph` nodes instead of as direct children of `listItem`. This
 * extension works in tandem with the custom `Editor` class, which post-processes
 * the JSON to wrap `text` nodes in `paragraph` nodes, ensuring the correct JSON
 * structure for Statamic Bard fields where nested lists are siblings to
 * `paragraph` nodes under `listItem`.
 *
 * @see  Editor
 */
class ListItem extends BaseListItem
{
    /**
     * Defines HTML parsing rules for the listItem node.
     *
     * Specifies that `<li>` tags should be parsed as `listItem` nodes,
     * overriding the base implementation to ensure consistent parsing
     * behavior.
     *
     * @return array<int, array<string, string>> Array of parsing rules
     */
    public function parseHTML(): array
    {
        return [
            [
                'tag' => 'li', // Map <li> tags to listItem nodes
            ],
        ];
    }

    /**
     * Prevents automatic wrapping of listItem content in a paragraph during parsing.
     *
     * Overrides the base `wrapper` method to return `null`, disabling the default
     * behavior of wrapping mixed content (text and nested lists) in a `paragraph`
     * node. This is critical to addressing the Tiptap issue, as it allows nested
     * `bulletList` or `orderedList` nodes to remain direct children of `listItem`,
     * with text nodes later wrapped in `paragraph` nodes by the custom `Editor` class.
     *
     * @param  DOMNode  $DOMNode  The DOM node being parsed
     * @return ?array<int, mixed> Returns null to prevent wrapping
     */
    public static function wrapper(
        $DOMNode
    ): ?array {
        // Prevent wrapping any content in a paragraph during parsing
        return null;
    }

    /**
     * Converts a listItem node to ProseMirror JSON format.
     *
     * Processes the node's content to ensure correct JSON structure, wrapping `text`
     * nodes in `paragraph` nodes and recursively processing nested `bulletList` or
     * `orderedList` nodes. This method reinforces the post-processing done by the
     * `Editor` class, ensuring that the JSON output maintains the correct hierarchy
     * where nested lists are direct children of `listItem` nodes, addressing the
     * Tiptap issue.
     *
     * @param  object  $node  The node being converted
     * @return array<int|string, mixed> The ProseMirror JSON representation of the node
     */
    public function toProseMirror(
        object $node
    ): array {
        $content = []; // Initialize content array for the listItem node
        $children = $node->content ?? [];

        if (! \is_iterable($children)) {
            $children = [];
        }

        // Process each child node
        foreach ($children as $child) {
            if (! \is_object($child) || ! isset($child->type)) {
                continue;
            }

            $childType = $child->type;

            if (! \is_string($childType)) {
                continue;
            }

            if ($childType === 'text') {
                // Wrap text nodes in a paragraph to meet ProseMirror's block node requirement
                $content[] = [
                    'type' => 'paragraph',
                    'content' => [$child],
                ];
            } elseif (
                $childType === 'bulletList'
                || $childType === 'orderedList'
            ) {
                $nestedContent = $child->content ?? [];

                if (! \is_array($nestedContent)) {
                    $nestedContent = [];
                }

                $nestedItems = [];

                foreach ($nestedContent as $item) {
                    if (\is_object($item)) {
                        $nestedItems[] = $this->toProseMirror($item);
                    }
                }

                // Preserve nested lists as direct children, recursively processing their items
                $content[] = [
                    'type' => $childType,
                    'content' => $nestedItems,
                ];
            } else {
                // Include other nodes (e.g., paragraph, heading) as-is
                $content[] = $child;
            }
        }

        // Return the listItem node structure
        return [
            'type' => 'listItem',
            'content' => $content,
        ];
    }
}
