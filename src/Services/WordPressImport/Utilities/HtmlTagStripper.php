<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Strips HTML tags from content, converting to plain text.
 *
 * Handles HTML content by removing tags while preserving text content. Applies
 * specific rules for different HTML element types:
 * - Self-closing elements (except `<br />` and `<br>`) are removed
 * - `<br />` and `<br>` are replaced with spaces
 * - Block-level elements are replaced with spaces
 * - Other elements are removed but their content is preserved
 * - Whitespace is collapsed after processing
 */
class HtmlTagStripper
{
    /**
     * List of block-level HTML elements that should be replaced with spaces.
     *
     * @var array<string>
     */
    private static array $blockLevelElements = [
        'p',
        'h1',
        'h2',
        'h3',
        'h4',
        'h5',
        'h6',
        'div',
        'span',
        'blockquote',
        'ul',
        'ol',
        'li',
        'table',
        'tr',
        'td',
        'th',
        'thead',
        'tbody',
        'tfoot',
        'caption',
        'colgroup',
        'col',
        'dl',
        'dt',
        'dd',
        'pre',
        'address',
        'article',
        'aside',
        'footer',
        'header',
        'hgroup',
        'main',
        'nav',
        'section',
        'figure',
        'figcaption',
        'fieldset',
        'legend',
        'details',
        'summary',
    ];

    /**
     * Strips HTML tags from content, converting to plain text.
     *
     * Processes HTML content according to specific rules:
     * - Self-closing elements (except `<br />` and `<br>`) are removed
     * - `<br />` and `<br>` are replaced with spaces
     * - Block-level elements are replaced with spaces
     * - Other elements are removed but their content is preserved
     * - Whitespace is collapsed after processing
     *
     * @param  string  $html  The HTML content to strip
     * @return string The plain text content with HTML tags removed
     */
    public static function strip(
        string $html
    ): string {
        // Decode HTML entities first to ensure proper processing
        $decoded = TextProcessor::decodeEntities($html);

        // ---
        // Replace `<br />` and `<br>` with spaces (case-insensitive, with or without
        // closing slash)
        // ---
        $processed = \preg_replace(
            '/<br\s*\/?>/i',
            ' ',
            $decoded
        );

        if (! \is_string($processed)) {
            $processed = $decoded;
        }

        // Replace block-level elements with spaces
        $blockLevelPattern = \implode(
            '|',
            // No element name holds a regex metacharacter, so quoting changes no
            // pattern today; it stays so a name added later cannot break the regex.
            // @pest-mutate-ignore: UnwrapArrayMap
            \array_map(
                fn (string $element): string => \preg_quote($element, '/'),
                self::$blockLevelElements
            )
        );

        // Replace opening and closing block-level tags with spaces
        $openingTagsReplaced = \preg_replace(
            "/<({$blockLevelPattern})(\s[^>]*)?>/i",
            ' ',
            $processed
        );

        if (\is_string($openingTagsReplaced)) {
            $processed = $openingTagsReplaced;
        }

        $closingTagsReplaced = \preg_replace(
            "/<\/(\s*)?({$blockLevelPattern})(\s*)?>/i",
            ' ',
            $processed
        );

        if (\is_string($closingTagsReplaced)) {
            $processed = $closingTagsReplaced;
        }

        // Remove all remaining HTML tags (preserving their content)
        $processed = \strip_tags($processed);

        // Collapse whitespace: replace multiple spaces/newlines/tabs with a single space
        $whitespaceCollapsed = \preg_replace(
            '/\s+/',
            ' ',
            $processed
        );

        if (\is_string($whitespaceCollapsed)) {
            $processed = $whitespaceCollapsed;
        }

        // Trim leading and trailing whitespace
        return \trim($processed);
    }
}
