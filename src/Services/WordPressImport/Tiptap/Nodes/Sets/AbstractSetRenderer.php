<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;

/**
 * Base class for rendering Statamic Bard sets to HTML for Tiptap processing.
 *
 * Provides common rendering logic for converting Bard sets into HTML elements,
 * typically <div> with data-* attributes, to reduce duplication across set types.
 */
abstract class AbstractSetRenderer
{
    /**
     * Constructor to set the set type.
     *
     * @param  string  $setType  The type of the Bard set
     */
    public function __construct(protected string $setType) {}

    /**
     * Render a set node to HTML for Tiptap processing.
     *
     * Converts a Bard set node to an HTML div with data attributes, preserving
     * attributes for re-parsing by Tiptap. Can be overridden for custom rendering.
     *
     * @param  object  $node  The Tiptap node object containing set data
     * @return array<string, string> HTML rendering configuration (tag and optional content)
     */
    public function renderHTML(
        object $node
    ): array {
        $attrs = '';
        $setValues = [];

        // Resolve set attribute values when present on the node
        if (
            isset($node->attrs)
            && \is_object($node->attrs)
            && isset($node->attrs->values)
            && \is_iterable($node->attrs->values)
        ) {
            $setValues = (array) $node->attrs->values;
        }

        foreach ($setValues as $key => $value) {
            if (
                $key === 'type'
                || $value === null
                || ! is_scalar($value)
            ) {
                continue;
            }

            $keyString = is_string($key) ? $key : (string) $key;

            $attrs .= \sprintf(
                ' data-set-attr-%s="%s"',
                htmlspecialchars($keyString),
                htmlspecialchars((string) $value)
            );
        }

        return [
            'tag' => \sprintf('<div data-set-type="%s"%s%s>', htmlspecialchars($this->setType), SetMarkupNonce::attribute(), $attrs),
        ];
    }
}
