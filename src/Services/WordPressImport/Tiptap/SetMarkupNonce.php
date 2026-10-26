<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap;

use Random\RandomException;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Set;

/**
 * Per-process nonce separating importer-generated Bard set markup from set
 * markup that arrived inside WordPress post content.
 *
 * `data-set-*` is an internal serialization this package invents and emits
 * itself — `ShortcodeProcessor::generateTiptapHtml()` and the block processors
 * write it so `Set::parseHTML()` can read it back. It is never author content.
 * Nothing in the markup itself distinguished the two, however, so a
 * `<div data-set-type="pull_quote" data-set-attr-quote="…">` typed into a
 * WordPress post was parsed with the same trust as one this importer wrote,
 * landing attacker-controlled raw HTML in an entry field the host app renders
 * unescaped (wordpress-importer-archive#494).
 *
 * A constant marker cannot close that gap: this package is readable, so anyone
 * authoring the payload can copy the marker into it. The nonce is therefore
 * random and generated **at first use within the importing process**, which is
 * strictly after the WXR input was written. Content being imported cannot
 * carry a value that did not exist when it was authored, so the guess is not
 * available rather than merely hard.
 *
 * Every site that emits set markup must stamp {@see self::attribute()} (or
 * {@see self::ATTRIBUTE} via `DOMElement::setAttribute()`) onto the
 * `div[data-set-type]` it produces. Missing one does not reopen the
 * vulnerability — it drops that set from the import, which the set-emission
 * tests catch.
 *
 * @see  Set::parseHTML()
 */
final class SetMarkupNonce
{
    /**
     * Attribute name carrying the nonce on generated set markup.
     */
    public const string ATTRIBUTE = 'data-set-nonce';

    /**
     * The current process's nonce, generated lazily on first read.
     */
    private static ?string $nonce = null;

    /**
     * Return the current process's nonce, generating it on first read.
     *
     * @return string Hex-encoded random token
     *
     * @throws RandomException If the platform cannot supply random bytes
     */
    public static function value(): string
    {
        self::$nonce ??= \bin2hex(\random_bytes(16));

        return self::$nonce;
    }

    /**
     * Return the nonce as an HTML attribute, ready to splice into a tag.
     *
     * Includes the leading space, so it concatenates directly after a tag name
     * or a preceding attribute.
     *
     * @return string The ` data-set-nonce="…"` attribute
     *
     * @throws RandomException If the platform cannot supply random bytes
     */
    public static function attribute(): string
    {
        return \sprintf(
            ' %s="%s"',
            self::ATTRIBUTE,
            self::value()
        );
    }

    /**
     * Report whether a candidate attribute value is this process's nonce.
     *
     * An absent attribute reads as an empty string through
     * `DOMElement::getAttribute()`, which never matches.
     *
     * @param  string  $candidate  Attribute value read off the parsed element
     * @return bool True when the candidate is the current nonce
     *
     * @throws RandomException If the platform cannot supply random bytes
     */
    public static function matches(
        string $candidate
    ): bool {
        // Compare in constant time; the nonce is a secret for the run's duration
        return \hash_equals(self::value(), $candidate);
    }

    /**
     * Discard the current nonce so the next read generates a fresh one.
     *
     * Exists for tests that need to prove markup stamped by an earlier nonce is
     * rejected once the nonce rolls.
     */
    public static function reset(): void
    {
        self::$nonce = null;
    }
}
