<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes;

use DOMElement;
use Tiptap\Core\Node;
use Tiptap\Utils\HTML;

/**
 * Custom CodeBlock node that includes language attribute support.
 *
 * Extends the base Tiptap CodeBlock node to ensure that code blocks
 * always include an 'attrs' property with a 'language' attribute,
 * defaulting to 'null' when no language is specified. This ensures
 * compatibility with Statamic Bard's expected structure.
 */
class CodeBlock extends Node
{
    /**
     * The name of the node type for Tiptap.
     *
     * @var mixed
     */
    public static $name = 'codeBlock';

    /**
     * Defines the default options for the CodeBlock node.
     *
     * Sets up configuration options including the language class prefix
     * and default language value for code blocks.
     *
     * @return array<string, string> Array of default options
     */
    public function addOptions(): array
    {
        return [
            'languageClassPrefix' => 'language-',
            'defaultLanguage' => 'null',
        ];
    }

    /**
     * Defines the attributes available on the CodeBlock node.
     *
     * Configures the 'language' attribute with parsing and rendering
     * functions to extract language information from HTML class attributes
     * and apply appropriate CSS classes during rendering.
     *
     * @return array<string, array<int|string, mixed>> Array of attribute definitions
     */
    public function addAttributes(): array
    {
        return [
            'language' => [
                'default' => 'null',
                'parseHTML' => $this->parseLanguageFromClass(...),
                'renderHTML' => $this->renderLanguageClass(...),
            ],
        ];
    }

    /**
     * Parses language from DOM element class attribute.
     *
     * Extracts language information from HTML class attributes (e.g.,
     * "language-php" -> "php").
     *
     * @param  DOMElement  $DOMElement  The DOM element to parse
     * @return string The language value, or 'null' if not found
     */
    private function parseLanguageFromClass(
        DOMElement $DOMElement
    ): string {
        // Get the class attribute from the DOM element
        $class = $DOMElement->getAttribute('class');

        // Extract language from class attribute (e.g., "language-php" -> "php").
        // An empty class simply does not match.
        if (
            \preg_match(
                '/language-([a-zA-Z0-9]+)/',
                $class,
                $matches
            ) === 1
        ) {
            return $matches[1];
        }

        // Return the language value for the attribute
        return 'null';
    }

    /**
     * Renders language CSS class from attributes.
     *
     * Gets the language value from attributes and returns CSS class
     * configuration for rendering.
     *
     * @param  object  $attributes  The attributes object
     * @return array<string, string> CSS class configuration
     */
    private function renderLanguageClass(
        object $attributes
    ): array {
        // Get the language value from attributes, defaulting to 'null'
        $language = $attributes->language ?? 'null';

        if (! \is_string($language)) {
            $language = 'null';
        }

        // Return CSS class configuration for rendering
        return [
            'class' => "language-{$language}",
        ];
    }

    /**
     * Defines how HTML elements are parsed into CodeBlock nodes.
     *
     * Specifies that `<pre>` elements should be parsed as code blocks
     * with full whitespace preservation to maintain code formatting.
     *
     * @return array<int, array<string, string>> Array of HTML parsing rules
     */
    public function parseHTML(): array
    {
        return [
            [
                'tag' => 'pre',
                'preserveWhitespace' => 'full',
            ],
        ];
    }

    /**
     * Renders a CodeBlock node to HTML.
     *
     * Converts a ProseMirror CodeBlock node into HTML structure with
     * appropriate language classes applied to both the `<pre>` and `<code>`
     * elements for syntax highlighting compatibility.
     *
     * @param  mixed  $node  The ProseMirror node to render
     * @param  array<int|string, mixed>  $HTMLAttributes  Additional HTML attributes to merge
     * @return array<int, mixed> HTML rendering configuration
     */
    public function renderHTML(
        $node,
        array $HTMLAttributes = []
    ): array {
        // Get the language value from node attributes, defaulting to 'null'
        $language = 'null';

        // Each check must hold before the next reads a property, or PHP warns
        if (
            \is_object($node)
            && isset($node->attrs)
            && \is_object($node->attrs)
        ) {
            $nodeLanguage = $node->attrs->language ?? 'null';
            $language = \is_string($nodeLanguage) ? $nodeLanguage : 'null';
        }

        // Return HTML structure with language classes applied
        return [
            'pre',
            HTML::mergeAttributes(
                $HTMLAttributes,
                [
                    'class' => "language-{$language}",
                ]
            ),
            [
                'code',
                [
                    'class' => "language-{$language}",
                ],
                0,
            ],
        ];
    }
}
