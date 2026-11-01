<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Repairs the two ways a WordPress export can be byte-level invalid XML 1.0.
 *
 * WordPress writes attachment metadata into `wp:postmeta` as PHP-serialized
 * blobs wrapped in CDATA. Those blobs can carry raw bytes from the C0 control
 * range, which are legal in a serialized PHP string but forbidden by the XML 1.0
 * `Char` production, and they can carry a literal `]]>`, which terminates the
 * enclosing CDATA section early. Either one makes libxml reject the document.
 *
 * The sanitizer is a streaming state machine so a multi-hundred-megabyte export
 * never has to be held in memory. Callers feed it arbitrary byte chunks and it
 * returns sanitized bytes, buffering only the few trailing bytes that might be a
 * split `<![CDATA[` or `]]>` token. See WxrSanitizingStreamFilter for the
 * php://filter wrapper that plugs it into XMLReader and simplexml_load_file.
 *
 * Two byte-level invariants make the chunked design safe:
 *
 * - Only C0 bytes (0x00-0x1F, less tab/LF/CR) are stripped. Bytes 0x80-0x9F are
 *   deliberately left alone: at the byte level those are UTF-8 continuation
 *   bytes, so stripping them would shred legitimate multi-byte characters. (The
 *   C1 *code points* U+0080-U+009F are a different thing, and are handled by
 *   TextProcessor::normalizeUtf8(), which matches with the /u flag.)
 * - Every token the state machine scans for is pure ASCII, and UTF-8 never uses
 *   bytes below 0x80 inside a multi-byte sequence, so a token can never be
 *   matched spuriously inside one — and a chunk boundary that lands mid-character
 *   is harmless.
 */
class WxrSanitizer
{
    /**
     * Scanning ordinary element content or markup, where `]]>` is illegal.
     */
    // Only the four states being distinct matters. A constant initializer is never
    // reported as an executed line, so Pest runs no test against a mutant here;
    // changing a value by hand so it equals another state's fails
    // WxrSanitizerTest or WxrSanitizerBoundariesTest, and a value no other state
    // holds changes nothing.
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    private const int STATE_TEXT = 0;

    /**
     * Inside a CDATA section, where `]]>` is the legitimate terminator.
     */
    // Distinct from the other states, as STATE_TEXT explains
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    private const int STATE_CDATA = 1;

    /**
     * Inside a comment, whose literal text must survive untouched.
     */
    // Distinct from the other states, as STATE_TEXT explains
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    private const int STATE_COMMENT = 2;

    /**
     * Inside a processing instruction, whose literal text must survive untouched.
     */
    // Distinct from the other states, as STATE_TEXT explains
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    private const int STATE_PI = 3;

    /**
     * The tokens that matter while scanning ordinary text.
     *
     * Three region openers plus the stray `]]>` that has to be escaped. Region
     * terminators are deliberately absent: they are only meaningful once the
     * matching region has been entered, and treating them as text tokens would
     * let a trailing `?` be withheld as a partial `?>` and split the complete
     * `<?` that preceded it.
     *
     * @var list<string>
     */
    // A constant initializer is never reported as an executed line. Removing any
    // token by hand fails WxrSanitizerTest.
    // @pest-mutate-ignore: RemoveArrayItem
    private const array TEXT_TOKENS = [
        '<![CDATA[',
        '<!--',
        '<?',
        ']]>',
    ];

    /**
     * The region the scanner is currently inside.
     */
    private int $state = self::STATE_TEXT;

    /**
     * Trailing bytes withheld from the previous chunk because they may be the
     * start of a token that continues into the next chunk.
     */
    // A property initializer is never reported as an executed line. A non-empty
    // initial value by hand fails most of WxrSanitizerTest, since every feed()
    // prepends it.
    // @pest-mutate-ignore: EmptyStringToNotEmpty
    private string $carry = '';

    /**
     * Count of C0 control bytes removed so far, for reporting.
     */
    // Initializer: a different start by hand fails the repair counts in
    // WxrSanitizerTest and WxrSanitizerBoundariesTest
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    private int $strippedControlBytes = 0;

    /**
     * Count of stray `]]>` sequences escaped so far, for reporting.
     */
    // Initializer, for the same reason as the stripped-byte count
    // @pest-mutate-ignore: DecrementInteger, IncrementInteger
    private int $escapedCdataTerminators = 0;

    /**
     * Sanitize a complete XML string in one call.
     *
     * @param  string  $xml  The raw XML bytes
     * @return string The sanitized XML bytes
     */
    public static function string(
        string $xml
    ): string {
        $sanitizer = new self;

        return $sanitizer->feed($xml).$sanitizer->flush();
    }

    /**
     * Sanitize the next chunk of the stream.
     *
     * Bytes that may belong to a token split across the chunk boundary are held
     * back and prepended to the following chunk, so the caller must always finish
     * with flush() to emit them.
     *
     * @param  string  $chunk  The next raw bytes of the document
     * @return string The sanitized bytes safe to emit now
     */
    public function feed(
        string $chunk
    ): string {
        // Re-attach whatever the previous chunk could not safely classify
        return $this->process(
            $this->carry.$chunk,
            false
        );
    }

    /**
     * Emit any bytes still held back after the final chunk.
     *
     * @return string The sanitized remainder
     */
    public function flush(): string
    {
        // Process whatever was withheld; an empty carry processes to nothing
        return $this->process(
            $this->carry,
            true
        );
    }

    /**
     * Get the number of XML-illegal C0 control bytes removed so far.
     */
    public function getStrippedControlBytes(): int
    {
        return $this->strippedControlBytes;
    }

    /**
     * Get the number of stray `]]>` sequences escaped so far.
     */
    public function getEscapedCdataTerminators(): int
    {
        return $this->escapedCdataTerminators;
    }

    /**
     * Report whether this sanitizer changed anything it has seen.
     */
    public function madeRepairs(): bool
    {
        return $this->strippedControlBytes > 0
            || $this->escapedCdataTerminators > 0;
    }

    /**
     * Emit a token-free tail, withholding any partial token at its end.
     *
     * Called only once the scanner has established that the tail contains no
     * complete token, which is what makes withholding a suffix safe: there is no
     * finished token left for it to cut in half.
     *
     * @param  string  $tail  The unscanned remainder of the buffer
     * @param  list<string>  $tokens  The tokens that could still begin here
     * @param  bool  $final  Whether the stream has ended, leaving nothing to wait for
     * @return string The bytes safe to emit now
     */
    private function emitTail(
        string $tail,
        array $tokens,
        bool $final
    ): string {
        // At end of stream nothing more is coming, so a partial token is just text
        if ($final) {
            $this->carry = '';

            return $tail;
        }

        $hold = $this->partialTokenSuffix(
            $tail,
            $tokens
        );

        if ($hold === 0) {
            $this->carry = '';

            return $tail;
        }

        $this->carry = \substr(
            $tail,
            -$hold
        );

        return \substr(
            $tail,
            0,
            -$hold
        );
    }

    /**
     * Measure the trailing bytes that may be the head of a split token.
     *
     * @param  string  $tail  The bytes about to be emitted
     * @param  list<string>  $tokens  The tokens that could begin in this tail
     * @return int The number of trailing bytes to withhold
     */
    private function partialTokenSuffix(
        string $tail,
        array $tokens
    ): int {
        // Every token is at least two bytes, so a start of 1 or -1 sets the same
        // longest length
        // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        $longest = 0;

        foreach ($tokens as $token) {
            $longest = \max(
                $longest,
                \strlen($token)
            );
        }

        // A wider bound only tries suffixes too long to be a partial token, which the
        // length check below rejects, so these limits save work and change nothing
        // @pest-mutate-ignore: MinToMax, MinusToPlus, DecrementInteger
        $max = \min(
            $longest - 1,
            \strlen($tail)
        );

        // Prefer the longest ambiguous suffix so no shorter match truncates a token.
        // A zero-length suffix is the whole tail, which can only answer 0 as well.
        // @pest-mutate-ignore: DecrementInteger
        for ($length = $max; $length >= 1; $length--) {
            $suffix = \substr(
                $tail,
                -$length
            );

            foreach ($tokens as $token) {
                // A suffix as long as a whole token never reaches here: a complete
                // token is found before the tail is held
                // @pest-mutate-ignore: SmallerToSmallerOrEqual
                if (
                    \strlen($suffix) < \strlen($token)
                    && \str_starts_with(
                        $token,
                        $suffix
                    )
                ) {
                    return $length;
                }
            }
        }

        return 0;
    }

    /**
     * Sanitize a span of bytes, withholding any token split at its end.
     *
     * Tokens are matched against the *raw* bytes, before any control byte is
     * removed. That ordering is load-bearing: stripping first would let the
     * removal splice non-adjacent bytes into a structural token, so a payload
     * containing "]]" NUL ">" would collapse into a real "]]>" and close its
     * CDATA section early. Classifying first means such a sequence is never a
     * token, and the collapsed bytes are neutralized when the span is emitted.
     *
     * @param  string  $data  The bytes to sanitize
     * @param  bool  $final  Whether this is the last span of the stream
     * @return string The sanitized bytes safe to emit now
     */
    private function process(
        string $data,
        bool $final
    ): string {
        $out = '';
        $offset = 0;
        $length = \strlen($data);

        // At the end of the data the body finds no token and holds nothing, so one
        // more pass changes no output
        // @pest-mutate-ignore: SmallerToSmallerOrEqual
        while ($offset < $length) {
            // Inside a protected region, copy through to its terminator
            if ($this->state !== self::STATE_TEXT) {
                $region = $this->state;
                $terminator = $this->currentTerminator();

                $end = \strpos(
                    $data,
                    $terminator,
                    $offset
                );

                // The region continues past this chunk, so carry what may be split
                if ($end === false) {
                    $tail = $this->emitTail(
                        \substr(
                            $data,
                            $offset
                        ),
                        [$terminator],
                        $final
                    );

                    return $out.$this->sanitizeSpan(
                        $tail,
                        $region
                    );
                }

                $out .= $this->sanitizeSpan(
                    \substr(
                        $data,
                        $offset,
                        $end - $offset
                    ),
                    $region
                ).$terminator;

                $offset = $end + \strlen($terminator);
                $this->state = self::STATE_TEXT;

                continue;
            }

            // In text, find whichever comes first: a region opener or a stray `]]>`
            [$position, $token] = $this->nextTextToken(
                $data,
                $offset
            );

            // No token remains, so the rest of the span is ordinary text. The two are
            // null together; checking both only narrows the types.
            // @pest-mutate-ignore: BooleanOrToBooleanAnd
            if ($position === null || $token === null) {
                $tail = $this->emitTail(
                    \substr(
                        $data,
                        $offset
                    ),
                    self::TEXT_TOKENS,
                    $final
                );

                return $out.$this->sanitizeSpan(
                    $tail,
                    self::STATE_TEXT
                );
            }

            $out .= $this->sanitizeSpan(
                \substr(
                    $data,
                    $offset,
                    $position - $offset
                ),
                self::STATE_TEXT
            );

            // A `]]>` outside every protected region is illegal, so escape it
            if ($token === ']]>') {
                $out .= ']]&gt;';
                $offset = $position + 3;
                $this->escapedCdataTerminators++;

                continue;
            }

            $out .= $token;
            $offset = $position + \strlen($token);
            $this->state = $this->stateForOpener($token);
        }

        // The span ended on a token boundary, so nothing needs withholding
        $this->carry = '';

        return $out;
    }

    /**
     * Strip the illegal bytes from one classified span and neutralize what that
     * removal may have spliced together.
     *
     * @param  string  $span  The raw bytes of a single classified span
     * @param  int  $region  The state constant for the region the span sits in
     * @return string The sanitized span
     */
    private function sanitizeSpan(
        string $span,
        int $region
    ): string {
        $span = $this->stripControlBytes($span);

        // Removing a control byte can leave a `]]>` that was not there before. With
        // none present, the replacements below change nothing, so this return only
        // saves work.
        if (! str_contains($span, ']]>')) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return $span;
        }

        // Inside CDATA, close and reopen the section so the bytes stay literal
        if ($region === self::STATE_CDATA) {
            $count = \substr_count($span, ']]>');
            $this->escapedCdataTerminators += $count;

            return \str_replace(
                ']]>',
                ']]]]><![CDATA[>',
                $span
            );
        }

        // In text, the sequence is simply illegal, so escape its closing bracket
        if ($region === self::STATE_TEXT) {
            $count = \substr_count($span, ']]>');
            $this->escapedCdataTerminators += $count;

            return \str_replace(
                ']]>',
                ']]&gt;',
                $span
            );
        }

        // A comment or processing instruction has no escape mechanism, so its
        // literal text is left as-is rather than corrupted by a partial repair
        return $span;
    }

    /**
     * Remove the control bytes XML 1.0 forbids, counting what was removed.
     *
     * Only bytes below 0x20 are touched. Bytes 0x80-0x9F are deliberately left
     * alone: at the byte level those are UTF-8 continuation bytes, so removing
     * them would shred legitimate multi-byte characters.
     *
     * @param  string  $data  The bytes to filter
     * @return string The bytes with illegal C0 controls removed
     */
    private function stripControlBytes(
        string $data
    ): string {
        // preg_replace() writes the count, and any negative limit means no limit,
        // so neither literal can change the result
        // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        $count = 0;

        // @pest-mutate-ignore: DecrementInteger
        $filtered = \preg_replace(
            '/[\x00-\x08\x0B\x0C\x0E-\x1F]/',
            '',
            $data,
            -1,
            $count
        );

        // Leave the span untouched if the engine failed rather than corrupt it. A
        // single character class cannot exhaust the engine, so no test reaches this.
        if ($filtered === null) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return $data;
        }

        $this->strippedControlBytes += $count;

        return $filtered;
    }

    /**
     * Find the earliest token that matters while scanning ordinary text.
     *
     * Scans each span once. Jumping straight to the next byte that could begin a
     * token keeps this linear; testing every token with its own strpos() made the
     * scan O(tokens x span), which is quadratic on a whole-document call.
     *
     * @param  string  $data  The span being scanned
     * @param  int  $offset  Where to start scanning
     * @return array{0: int|null, 1: string|null} The position and token, or nulls when none remain
     */
    private function nextTextToken(
        string $data,
        int $offset
    ): array {
        $length = \strlen($data);

        // At the end of the data strcspn() moves nothing and the break below exits,
        // so one more pass changes nothing
        // @pest-mutate-ignore: SmallerToSmallerOrEqual
        while ($offset < $length) {
            // Skip ahead to the next byte that could open one of the tokens
            $offset += \strcspn(
                $data,
                '<]',
                $offset
            );

            // At exactly the end, no token compares equal and the loop condition exits
            // next, so `>` and continuing would change nothing
            // @pest-mutate-ignore: GreaterOrEqualToGreater
            if ($offset >= $length) {
                // @pest-mutate-ignore: BreakToContinue
                break;
            }

            // Longest first, so no shorter token masks one that starts here
            foreach (self::TEXT_TOKENS as $token) {
                if (
                    \substr_compare(
                        $data,
                        $token,
                        $offset,
                        \strlen($token)
                    ) === 0
                ) {
                    return [$offset, $token];
                }
            }

            $offset++;
        }

        return [null, null];
    }

    /**
     * Get the terminator that closes the region currently being scanned.
     *
     * @return string The closing token for the current state
     */
    private function currentTerminator(): string
    {
        return match ($this->state) {
            self::STATE_CDATA => ']]>',
            self::STATE_COMMENT => '-->',
            default => '?>',
        };
    }

    /**
     * Map a region opener to the state it enters.
     *
     * @param  string  $token  The opening token that was matched
     * @return int The state constant for that region
     */
    private function stateForOpener(
        string $token
    ): int {
        return match ($token) {
            '<![CDATA[' => self::STATE_CDATA,
            '<!--' => self::STATE_COMMENT,
            default => self::STATE_PI,
        };
    }
}
