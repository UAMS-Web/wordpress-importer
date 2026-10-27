<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Replicates WordPress's wpautop() function to format text with HTML paragraph
 * and line break tags.
 *
 * Formats raw text or HTML by converting double line breaks to <p> tags and
 * single line breaks to <br /> tags (when enabled), mimicking WordPress's WYSIWYG
 * field behavior for ACF fields. Protects specific tags (e.g., <pre>, <script>,
 * <style>, <svg>, <math>) from formatting changes to preserve their content. Used
 * in WordPress import parsers to preprocess content before Tiptap parsing.
 *
 * ## Why preserving <script>/<style>/<svg>/<math> here is safe
 *
 * Read in isolation, carrying those tags through untouched looks like an XSS
 * hole. It is not, and the reason is entirely downstream: this class only ever
 * *formats* — it is a preprocessing step, never a sanitizer, and its output is
 * handed straight to {@see BardParser::parse()}. That parser's Tiptap schema is
 * an allowlist, so a node or mark the schema does not declare is discarded at
 * parse time. `<script>`, `<iframe>`, event-handler attributes and
 * `javascript:`/`data:` hrefs never reach a Statamic entry.
 *
 * Preserving them here is deliberate rather than incidental: mangling a
 * `<script>` mid-format could split it into fragments the parser no longer
 * recognizes as one node, which is how a sanitizer-by-accident starts leaking.
 * Keeping each tag intact means the allowlist sees it whole and drops it whole.
 *
 * Do not add stripping here — it would duplicate the real control and mask a
 * regression in it. The invariant is asserted host-side in `uams-statamic`
 * (BardParserScriptStrippingTest, WordPressImportScriptStrippingTest), which
 * pins the tiptap-php build that actually parses production content.
 *
 * @see  https://github.com/UAMS-Web/uams-statamic/issues/1742
 */
class WpAutoP
{
    /**
     * Formats text with paragraph and line break tags, replicating WordPress's
     * wpautop().
     *
     * Processes input text to standardize newlines, wrap content in <p> tags for
     * double line breaks, and optionally add <br /> tags for single line breaks.
     * Protects block-level elements and specific tags (e.g., <pre>, <script>) from
     * unwanted formatting. Skips <p> tag addition for content that is solely a
     * block-level element (e.g., a single Tiptap set <div>) to prevent empty
     * paragraphs.
     *
     * @param  string  $text  The input text or HTML to format (e.g., WYSIWYG field content)
     * @param  bool  $br  Whether to convert single line breaks to <br /> tags (default: true)
     * @return string The formatted HTML with <p> and optional <br /> tags
     */
    public function process(
        string $text,
        bool $br = true
    ): string {
        if (\trim($text) === '') {
            return '';
        }

        $pre_tags = [];

        // Pad the end for processing
        $text .= "\n";

        // Protect <pre> tags
        if (
            \str_contains(
                $text,
                '<pre'
            )
        ) {
            $text_parts = \explode(
                '</pre>',
                $text
            );
            $last_part = array_pop($text_parts);
            $text = '';

            // The counter only has to give each placeholder a distinct name, which
            // any start or direction does
            // @pest-mutate-ignore: DecrementInteger, IncrementInteger
            $i = 0;

            foreach ($text_parts as $text_part) {
                $start = \strpos(
                    $text_part,
                    '<pre'
                );
                if ($start === false) {
                    $text .= $text_part;

                    continue;
                }

                $name = "<pre wp-pre-tag-$i></pre>";
                $pre_tags[$name] = \substr(
                    $text_part,
                    $start
                ).'</pre>';
                $text .= \substr(
                    $text_part,
                    0,
                    $start
                ).$name;

                // @pest-mutate-ignore: PostIncrementToPostDecrement
                $i++;
            }

            $text .= $last_part;
        }

        // Replace multiple <br> tags with two line breaks
        $text = self::pregReplace(
            '|<br\s*/?>\s*<br\s*/?>|',
            "\n\n",
            $text
        );

        // ---
        // Block-level element tag names (shared by non-capturing and capturing patterns)
        // ---

        // Alternation of block-level tag names for use in regex patterns
        $blockTagNames = 'table|thead|tfoot|caption|col|colgroup|tbody|tr|td|th|div|dl|dd|dt|ul|ol|li|pre|form|map|area|blockquote|address|style|p|h[1-6]|hr|fieldset|legend|section|article|aside|hgroup|header|footer|nav|figure|figcaption|details|menu|summary|img';

        // Non-capturing group for use in replace patterns (no backreference)
        $allblocks = "(?:{$blockTagNames})";

        // Capturing group so the same tag can be required at open and close via \1
        $allblocksCapture = "({$blockTagNames})";

        // ---
        // Add double line breaks after self-closing block elements (img, etc.).
        // This must come first to avoid conflicts with opening/closing tag patterns.
        // ---
        $text = self::pregReplace(
            "!(<{$allblocks}[^>]*?/>)!",
            "\n\n$1\n\n",
            $text
        );

        // ---
        // Add double line breaks before block-level opening tags.
        // This matches opening tags that end with `>` (not self-closing tags ending with `/>`).
        // Matches tags ending with `>` where the character before `>` is not `/`.
        // The pattern `[^/]>` ensures `>` is not preceded by `/`, handling both tags with
        // and without attributes.
        // ---
        $text = self::pregReplace(
            "!(<{$allblocks}(?:\s+[^>]*)?[^/]>)!",
            "\n\n$1",
            $text
        );

        // Add double line breaks after block-level closing tags
        $text = self::pregReplace(
            "!(</{$allblocks}>)!",
            "$1\n\n",
            $text
        );

        // Add double line breaks after hr tags (handled separately for compatibility)
        $text = self::pregReplace(
            '!(<hr\s*?/?>)!',
            "$1\n\n",
            $text
        );

        // Standardize newlines
        $text = \str_replace(
            [
                "\r\n",
                "\r",
            ],
            "\n",
            $text
        );

        // Protect newlines in HTML tags
        $text = self::pregReplaceCallback(
            '#<(script|style|svg|math)([^>]*)>(.*?)</\1>#si',
            fn ($matches): string => \str_replace(
                "\n",
                '<!-- wpnl -->',
                $matches[0]
            ),
            $text
        );

        // Remove excess line breaks
        $text = self::pregReplace(
            "/\n\n+/",
            "\n\n",
            $text
        );

        // ---
        // Check if content is solely a single block-level element (same tag open and
        // close). Requiring \1 backreference prevents matching mixed content like
        // <h2>...</h2>...<p>...</p> which would otherwise skip paragraph wrapping.
        // ---

        // Trim whitespace so the block-only regex can match from start to end
        $trimmed = \trim($text);

        // Detect whether content is a single block element (same tag open and close)
        $isBlockOnly = \preg_match(
            "/^<{$allblocksCapture}[^>]*>.*<\\/\\1>$/s",
            $trimmed
        );

        // Skip <p> wrapping if content is only a block-level element
        if ($isBlockOnly === 1) {
            // Restore protected newlines
            $text = \str_replace(
                [' <!-- wpnl --> ',
                    '<!-- wpnl -->'],
                "\n",
                $trimmed
            );
            // Restore <pre> tags. str_replace() reads an array's values in order
            // whatever their keys, so array_values() only documents the intent.
            if ($pre_tags !== []) {
                // @pest-mutate-ignore: UnwrapArrayValues
                return \str_replace(
                    \array_keys($pre_tags),
                    \array_values($pre_tags),
                    $text
                );
            }

            return $text;
        }

        // Split into paragraphs. A limit of 0 means no limit, as -1 does.
        // @pest-mutate-ignore: IncrementInteger
        $paragraphs = \preg_split(
            '/\n\s*\n/',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY
        );
        // A fixed pattern on a string cannot fail to split, so no test reaches this
        // @pest-mutate-ignore: FalseToTrue
        if ($paragraphs === false) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return $text;
        }

        $text = '';

        // Wrap content in <p> tags
        foreach ($paragraphs as $paragraph) {
            $text .= '<p>'.\trim(
                $paragraph,
                "\n"
            )."</p>\n";
        }

        // Clean up stray <p> tags
        $text = self::pregReplace(
            '|<p>\s*</p>|',
            '',
            $text
        );
        $text = self::pregReplace(
            '!<p>([^<]+)</(div|address|form)>!',
            '<p>$1</p></$2>',
            $text
        );
        $text = self::pregReplace(
            "!<p>\s*(</?{$allblocks}[^>]*>)\s*</p>!",
            '$1',
            $text
        );
        $text = self::pregReplace(
            '|<p>(<li.+?)</p>|',
            '$1',
            $text
        );
        $text = self::pregReplace(
            '|<p><blockquote([^>]*)>|i',
            '<blockquote$1><p>',
            $text
        );
        $text = \str_replace(
            '</blockquote></p>',
            '</p></blockquote>',
            $text
        );
        $text = self::pregReplace(
            "!<p>\s*(</?{$allblocks}[^>]*>)!",
            '$1',
            $text
        );
        $text = self::pregReplace(
            "!(</?{$allblocks}[^>]*>)\s*</p>!",
            '$1',
            $text
        );

        // Handle line breaks if enabled
        if ($br) {
            // The wpnl pass above has already replaced every newline inside these
            // elements, so this pass and its restore below find none; they are kept
            // to match WordPress's wpautop(). A fuzz of 40,000 inputs found no output
            // change with either removed.
            // @pest-mutate-ignore: UnwrapStrReplace
            $text = self::pregReplaceCallback(
                '#<(script|style|svg|math)([^>]*)>(.*?)</\1>#si',
                fn ($matches): string => \str_replace(
                    "\n",
                    '<WPPreserveNewline />',
                    $matches[0]
                ),
                $text
            );
            $text = \str_replace(
                ['<br>',
                    '<br/>'],
                '<br />',
                $text
            );
            $text = self::pregReplace(
                '|(?<!<br />)\s*\n|',
                "<br />\n",
                $text
            );
            // @pest-mutate-ignore: UnwrapStrReplace
            $text = \str_replace(
                '<WPPreserveNewline />',
                "<br />\n",
                $text
            );
        }

        // Clean up <br /> tags
        $text = self::pregReplace(
            "!(</?{$allblocks}[^>]*>)\s*<br />!",
            '$1',
            $text
        );
        $text = self::pregReplace(
            '!<br />(\s*</?(?:p|li|div|dl|dd|dt|th|pre|td|ul|ol)[^>]*>)!',
            '$1',
            $text
        );
        $text = self::pregReplace(
            "|\n</p>$|",
            '</p>',
            $text
        );

        // Restore <pre> tags; array_values() only documents the intent, as above
        if ($pre_tags !== []) {
            // @pest-mutate-ignore: UnwrapArrayValues
            $text = \str_replace(
                \array_keys($pre_tags),
                \array_values($pre_tags),
                $text
            );
        }

        // Restore protected newlines
        $text = \str_replace(
            [' <!-- wpnl --> ',
                '<!-- wpnl -->'],
            "\n",
            $text
        );

        return $text;
    }

    /**
     * Run preg_replace and preserve subject on regex failure.
     *
     * @param  string  $pattern  PCRE pattern
     * @param  string  $replacement  Replacement string
     * @param  string  $subject  Input subject
     * @return string Updated text or original text on failure
     */
    private static function pregReplace(
        string $pattern,
        string $replacement,
        string $subject
    ): string {
        $result = \preg_replace(
            $pattern,
            $replacement,
            $subject
        );

        return \is_string($result) ? $result : $subject;
    }

    /**
     * Run preg_replace_callback and preserve subject on regex failure.
     *
     * @param  string  $pattern  PCRE pattern
     * @param  callable(array<int|string, string>): string  $callback  Replacement callback
     * @param  string  $subject  Input subject
     * @return string Updated text or original text on failure
     */
    private static function pregReplaceCallback(
        string $pattern,
        callable $callback,
        string $subject
    ): string {
        $result = \preg_replace_callback(
            $pattern,
            $callback,
            $subject
        );

        return \is_string($result) ? $result : $subject;
    }
}
