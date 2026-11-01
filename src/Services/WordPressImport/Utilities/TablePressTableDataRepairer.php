<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Decodes TablePress `<content:encoded>` JSON, repairing unescaped HTML-attribute quotes.
 *
 * TablePress stores a table's cells as a JSON array in `<content:encoded>`. Some legacy
 * tables contain inline HTML whose attribute values use unescaped double quotes (e.g.
 * `<span style="font-size: small">`). WordPress never escaped those quotes before
 * JSON-encoding the blob, so the stored payload is invalid JSON: `json_decode()` returns
 * null and the entire table is dropped rather than recovered.
 *
 * This repairer decodes the payload as-is first; only when that fails does it run a
 * targeted repair pass that escapes the stray double quotes appearing inside HTML
 * attribute values (`name="value"`), then re-decodes. Well-formed tables are therefore
 * byte-identical no-ops — the repair never runs for payloads that already decode. When a
 * payload is still unparseable after repair, it reports `json_last_error_msg()` plus a
 * best-effort byte offset so the next unseen defect class can be found by hand.
 */
class TablePressTableDataRepairer
{
    /**
     * Matches an HTML attribute (`name="value"`) embedded in JSON string content.
     *
     * The `=` immediately before the opening quote is the tell: a genuine JSON string
     * delimiter is preceded by structural punctuation (`[`, `,`, `:`, `{`) or whitespace,
     * never by `name=`. Attribute names start with a letter and may contain word
     * characters, hyphens, and colons (e.g. `data-foo`, `xml:lang`). The value captures
     * any run of non-quote bytes, so existing JSON escapes (`\/`, `\n`) inside it survive.
     */
    private const string HTML_ATTRIBUTE_PATTERN = '/([a-zA-Z][\w:-]*)="([^"]*)"/';

    /**
     * Decode a TablePress `<content:encoded>` payload, repairing attribute quotes if needed.
     *
     * Decodes as-is first; on a parse error, escapes stray HTML-attribute quotes and
     * re-decodes. Returns the decoded value (which may be an empty array for valid-but-empty
     * payloads) or null when the payload cannot be parsed even after repair.
     *
     * @param  string  $contentEncoded  The raw JSON payload from `<content:encoded>`
     * @return array{data: ?array<int|string, mixed>, repaired: bool, error: ?string, offset: ?int} Decoded data, repair flag, and error
     */
    public static function decode(
        string $contentEncoded
    ): array {
        // Decode the payload exactly as stored; well-formed tables stop here untouched
        $decoded = \json_decode(
            $contentEncoded,
            true
        );

        // Accept any payload that parses cleanly, including valid-but-empty arrays
        if (\json_last_error() === JSON_ERROR_NONE) {
            return [
                'data' => \is_array($decoded) ? $decoded : null,
                'repaired' => false,
                'error' => null,
                'offset' => null,
            ];
        }

        // Escape stray double quotes inside HTML attribute values, leaving valid JSON intact
        $repaired = self::escapeHtmlAttributeQuotes($contentEncoded);

        // Only re-decode when the repair pass actually changed the payload
        if ($repaired !== $contentEncoded) {
            // Re-attempt the decode against the repaired payload
            $redecoded = \json_decode(
                $repaired,
                true
            );

            // Return the recovered data when the repair produced valid JSON
            if (\json_last_error() === JSON_ERROR_NONE) {
                return [
                    'data' => \is_array($redecoded) ? $redecoded : null,
                    'repaired' => true,
                    'error' => null,
                    'offset' => null,
                ];
            }
        }

        // Still unparseable: surface the parse error and a best-effort failure offset
        return [
            'data' => null,
            'repaired' => false,
            'error' => \json_last_error_msg(),
            'offset' => self::findUnescapedQuoteBreakOffset($repaired),
        ];
    }

    /**
     * Escape unescaped double quotes that delimit HTML attribute values inside JSON strings.
     *
     * Targets the known defect shape — `name="value"` HTML attributes embedded in JSON
     * string content — and rewrites each to `name=\"value\"`, so the surrounding cell text
     * survives as a valid JSON string. Genuine JSON delimiters are never preceded by
     * `name=`, so they are left untouched; payloads with no such attributes are returned
     * unchanged (a true no-op).
     *
     * @param  string  $json  The raw JSON payload to repair
     * @return string The payload with HTML-attribute quotes escaped, or the original string
     *                unchanged when no attributes match
     */
    public static function escapeHtmlAttributeQuotes(
        string $json
    ): string {
        // Escape both quotes of each embedded HTML attribute via backslash-quote sequences
        $repaired = \preg_replace_callback(
            self::HTML_ATTRIBUTE_PATTERN,
            static fn (array $matches): string => $matches[1].'=\\"'.$matches[2].'\\"',
            $json
        );

        // Fall back to the original payload if the regex engine errored (returns null)
        return $repaired ?? $json;
    }

    /**
     * Find the byte offset where an unescaped string quote first breaks JSON structure.
     *
     * Walks the payload tracking string and escape state. The unescaped-attribute-quote
     * defect closes a JSON string prematurely, after which the next non-whitespace byte is
     * not a structural delimiter (`,`, `]`, `}`, `:`). The offset of that stray byte is the
     * most useful pointer for a human chasing the next defect class. Returns null when no
     * such break is found (a different error class), in which case only the error message is
     * reported.
     *
     * @param  string  $json  The payload that failed to decode
     * @return ?int Byte offset of the first structural break, or null if none was located
     */
    public static function findUnescapedQuoteBreakOffset(
        string $json
    ): ?int {
        // Cache the payload length for the scan bounds
        $length = \strlen($json);

        // Track whether the scan is currently inside a JSON string
        $inString = false;

        // Track whether the previous byte was an unconsumed backslash escape
        $escaped = false;

        // Walk the payload one byte at a time
        for ($i = 0; $i < $length; $i++) {
            // Read the current byte
            $char = $json[$i];

            // Outside a string, only an opening quote changes state
            if (! $inString) {
                // Enter string context on a quote; ignore all other structural bytes
                if ($char === '"') {
                    $inString = true;
                }

                continue;
            }

            // Consume the byte following a backslash without interpreting it
            if ($escaped) {
                // Clear the escape flag and move on
                $escaped = false;

                continue;
            }

            // A backslash escapes the next byte (e.g. \" or \/)
            if ($char === '\\') {
                // Mark the next byte as escaped
                $escaped = true;

                continue;
            }

            // An unescaped quote closes the current string
            if ($char === '"') {
                // Leave string context
                $inString = false;

                // Find the next non-whitespace byte after the closing quote
                $next = $i + 1;
                while (
                    $next < $length
                    && \ctype_space($json[$next])
                ) {
                    // Skip insignificant whitespace between tokens
                    $next++;
                }

                // A closed string must be followed by structural punctuation
                if (
                    $next < $length
                    && ! str_contains(
                        ',]}:',
                        $json[$next]
                    )
                ) {
                    // The stray byte is where the unescaped quote broke the structure
                    return $next;
                }
            }
        }

        // No structural break attributable to an unescaped quote was found
        return null;
    }
}
