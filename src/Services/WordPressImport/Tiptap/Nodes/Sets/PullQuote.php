<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets;

/**
 * Utility class for rendering Statamic uamsweb_set_pull_quote Bard sets.
 *
 * Extends AbstractSetRenderer to handle pull_quote sets, rendering to a
 * blockquote with formatted content.
 */
class PullQuote extends AbstractSetRenderer
{
    /**
     * Constructor to set the set type.
     */
    public function __construct()
    {
        parent::__construct('pull_quote');
    }

    /**
     * Renders a pull_quote set node to HTML for Tiptap processing.
     *
     * @param  object  $node  The Tiptap node object containing set data
     * @return array<string, string> HTML rendering configuration
     */
    public function renderHTML(
        object $node
    ): array {
        // Resolve supported attribute containers before reading nested values.
        $attrs = property_exists($node, 'attrs') && is_object($node->attrs)
            ? $node->attrs
            : null;
        $values = $attrs !== null
            && property_exists($attrs, 'values')
            && is_object($attrs->values)
                ? $attrs->values
                : null;

        // Initialize rendered quote markup.
        $content = '';

        // Iterate over quote paragraphs only when the source is iterable.
        $quoteParagraphs = $values !== null
            && property_exists($values, 'quote')
            && is_iterable($values->quote)
                ? $values->quote
                : [];
        foreach ($quoteParagraphs as $paragraph) {
            if (! is_array($paragraph)) {
                continue;
            }

            // Build a single paragraph from text content nodes.
            $paragraphContent = '';
            $textNodes = $paragraph['content'] ?? [];
            if (! is_iterable($textNodes)) {
                continue;
            }

            foreach ($textNodes as $text) {
                if (! is_array($text)) {
                    continue;
                }

                // Normalize text content before escaping.
                $textValue = $text['text'] ?? '';
                $textContent = htmlspecialchars(
                    is_string($textValue) ? $textValue : ''
                );

                // Walk text marks and wrap output with matching tag.
                $marks = $text['marks'] ?? [];
                if (! is_iterable($marks)) {
                    $marks = [];
                }

                $tag = 'span';
                foreach ($marks as $mark) {
                    if (! is_array($mark)) {
                        continue;
                    }

                    $markType = $mark['type'] ?? null;
                    if (! is_string($markType)) {
                        continue;
                    }

                    if ($markType === 'bold') {
                        $tag = 'strong';
                    } elseif ($markType === 'italic') {
                        $tag = 'em';
                    } elseif ($markType === 'link') {
                        $markAttrs = $mark['attrs'] ?? [];
                        if (! is_array($markAttrs)) {
                            $markAttrs = [];
                        }

                        $href = $markAttrs['href'] ?? '';
                        $target = $markAttrs['target'] ?? '';

                        $attrs = \sprintf(
                            'href="%s"',
                            htmlspecialchars(is_string($href) ? $href : '')
                        );
                        if (is_string($target) && $target !== '') {
                            $attrs .= \sprintf(
                                ' target="%s"',
                                htmlspecialchars($target)
                            );
                        }

                        $paragraphContent .= \sprintf(
                            '<a %s>%s</a>',
                            $attrs,
                            $textContent
                        );
                        $tag = null;
                        break;
                    }
                }

                if ($tag !== null) {
                    $paragraphContent .= \sprintf(
                        '<%s>%s</%s>',
                        $tag,
                        $textContent,
                        $tag
                    );
                }
            }

            $content .= \sprintf(
                '<p>%s</p>',
                $paragraphContent
            );
        }

        // Apply alignment class from valid string values only.
        $align = $values !== null
            && property_exists($values, 'align')
            && is_string($values->align)
                ? $values->align
                : 'aligncenter';

        // Escape the alignment so it cannot end the class attribute, and add no class when it is empty
        $class = $align === 'aligncenter' || $align === ''
            ? 'wp-block-quote'
            : 'wp-block-quote '.htmlspecialchars($align);

        return [
            'tag' => "<blockquote class=\"$class\">",
            'content' => $content,
        ];
    }
}
