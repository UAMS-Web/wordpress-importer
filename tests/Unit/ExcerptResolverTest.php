<?php

declare(strict_types=1);

/**
 * Unit tests for `ExcerptResolver::resolve()` (wordpress-importer-archive#429).
 *
 * The `excerpt` summary is required on every front-end-view blueprint, so the
 * resolver's contract is that it returns text or `null` — never the empty
 * string. These cover the three-source precedence, the HTML stripping, and the
 * markup-with-no-text case that makes stripping happen *before* the emptiness
 * test rather than after it.
 *
 * @command  php vendor/bin/pest tests/Unit/ExcerptResolverTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ExcerptResolver;

it('prefers the author excerpt over both SEO descriptions', function (): void {
    expect(ExcerptResolver::resolve('Author summary.', 'Titles desc.', 'Meta desc.'))
        ->toBe('Author summary.');
});

it('falls back to the SEO titles description when the excerpt is absent', function (): void {
    expect(ExcerptResolver::resolve('', 'Titles desc.', 'Meta desc.'))
        ->toBe('Titles desc.');
});

it('falls back to the SEO meta description when the first two are absent', function (): void {
    expect(ExcerptResolver::resolve('', '', 'Meta desc.'))
        ->toBe('Meta desc.');
});

it('returns null when no source carries anything', function (): void {
    expect(ExcerptResolver::resolve('', '', ''))->toBeNull();
});

it('strips HTML from whichever source it selects', function (): void {
    expect(ExcerptResolver::resolve('<p>Author <em>summary</em>.</p>', '', ''))
        ->toBe('Author summary.');
});

// ---
// The reason the strip happens before the emptiness test: a candidate can be
// non-empty as markup and still carry no text. Judging it before stripping
// writes an empty string into a required field (wordpress-importer-archive#429).
// ---
it('falls through markup that carries no text', function (): void {
    expect(ExcerptResolver::resolve('<p></p>', '', 'Meta desc.'))
        ->toBe('Meta desc.');
});

it('falls through a candidate that is only a non-breaking space', function (): void {
    expect(ExcerptResolver::resolve('&nbsp;', 'Titles desc.', ''))
        ->toBe('Titles desc.');
});

it('never returns an empty string, only null', function (): void {
    expect(ExcerptResolver::resolve('<p></p>', '<div>  </div>', '&nbsp;'))
        ->toBeNull();
});

// ---
// `preg_replace('/\s+/u', ...)` returns null on malformed UTF-8 rather than throwing.
// The candidate is treated as carrying no text, so resolution falls through to the next
// source instead of emitting null into a required field.
// ---
it('falls through a candidate that is not valid UTF-8', function (): void {
    expect(ExcerptResolver::resolve("Caf\xC3\x28 broken", 'Titles desc.', ''))
        ->toBe('Titles desc.');
});

it('returns null when the only candidate is not valid UTF-8', function (): void {
    expect(ExcerptResolver::resolve("Caf\xC3\x28 broken"))->toBeNull();
});

it('accepts a single candidate', function (): void {
    expect(ExcerptResolver::resolve('Only one.'))->toBe('Only one.');
});
