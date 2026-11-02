<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;

/**
 * Guards that every site emitting Bard set markup stamps the run nonce (wordpress-importer-archive#494).
 *
 * `Set::parseHTML()` trusts only markup carrying the nonce, so an emission site
 * that forgets it produces a set that vanishes at parse time. That failure is
 * silent in the behavioural suite: assertions on generated markup are
 * `toContain('data-set-type="…")`, which an unstamped emitter still satisfies.
 *
 * This is a **static** guard, so it proves the stamp is written, not that the
 * value is the current nonce — `tests/Unit/BardSetMarkupTrustTest.php` covers
 * the runtime behaviour. Its value is catching a *new* emitter added without
 * the stamp, which is the realistic regression.
 *
 * @command  php vendor/bin/pest tests/Unit/SetMarkupEmissionGuardTest.php
 */

/**
 * Return a file's source with comments removed.
 *
 * Docblocks quote set markup as worked examples — `SetMarkupNonce`'s own class
 * comment does — so a raw text scan reports prose as an unstamped emitter.
 * Only real code should be searched.
 *
 * @param  string  $source  Raw PHP source
 * @return string The source with comment tokens dropped
 */
function setMarkupCodeOnly(string $source): string
{
    $code = '';

    foreach (\token_get_all($source) as $token) {
        // Single-character tokens are punctuation, never comments
        if (! \is_array($token)) {
            $code .= $token;

            continue;
        }

        // Drop both `//`/`#` comments and docblocks
        if (\in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= $token[1];
    }

    return $code;
}

/**
 * Collect every PHP file under the package's `src/` tree.
 *
 * @return list<string> Absolute paths to each source file
 */
function setMarkupSourceFiles(): array
{
    $root = \dirname(__DIR__, 2).'/src';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );

    $files = [];

    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $files[] = $file->getPathname();
    }

    \sort($files);

    return $files;
}

it('stamps the nonce on every literal set-markup emission', function (): void {
    $unstamped = [];
    $sites = 0;

    foreach (setMarkupSourceFiles() as $path) {
        $raw = \file_get_contents($path);

        if (! \is_string($raw)) {
            continue;
        }

        $source = setMarkupCodeOnly($raw);

        // ---
        // Find each `<div data-set-type=` literal and read the statement it sits in.
        //
        // Checking that a `%s` follows the type attribute is NOT enough: the
        // pre-existing `$attrs` placeholder already occupies that position, so a
        // stripped nonce leaves the format string looking identical. The
        // statement must actually pass the nonce as an argument.
        // ---
        \preg_match_all(
            '/<div data-set-type=/',
            $source,
            $matches,
            PREG_OFFSET_CAPTURE
        );

        foreach ($matches[0] as $match) {
            $sites++;

            // preg reports -1 for an unmatched group; clamp so substr stays sane
            $offset = \max(0, $match[1]);

            // Read to the end of the statement that builds this markup
            $end = \strpos($source, ';', $offset);
            $statement = $end === false
                ? \substr($source, $offset)
                : \substr($source, $offset, $end - $offset);

            if (! \str_contains($statement, 'SetMarkupNonce::attribute()')) {
                $unstamped[] = \basename($path).': '.\substr($statement, 0, 60);
            }
        }
    }

    // A pattern change that stops matching would otherwise pass vacuously
    expect($sites)->toBeGreaterThanOrEqual(12);

    expect($unstamped)->toBe([]);
});

it('stamps the nonce on the dom-built set markup', function (): void {
    $missing = [];

    foreach (setMarkupSourceFiles() as $path) {
        $raw = \file_get_contents($path);

        if (! \is_string($raw)) {
            continue;
        }

        $source = setMarkupCodeOnly($raw);

        if (! \str_contains($source, "'data-set-type'")) {
            continue;
        }

        // A DOM emitter must also call setAttribute() with the nonce
        if (! \str_contains($source, 'SetMarkupNonce::ATTRIBUTE')) {
            $missing[] = \basename($path);
        }
    }

    expect($missing)->toBe([]);
});

it('never leaks the nonce attribute into parsed set values', function (): void {
    // The attribute name must not collide with the prefixes getAttrs copies
    expect(SetMarkupNonce::ATTRIBUTE)
        ->not->toStartWith('data-set-attr-')
        ->and(SetMarkupNonce::ATTRIBUTE)->not->toStartWith('data-set-integer-');
});
