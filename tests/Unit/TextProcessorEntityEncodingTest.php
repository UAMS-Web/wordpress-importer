<?php

declare(strict_types=1);

/**
 * Tests for TextProcessor's non-ASCII entity encoding — the PHP 8.4 replacement
 * of the deprecated `mb_convert_encoding(…, 'HTML-ENTITIES', …)` pseudo-encoding.
 *
 * DOMDocument assumes Latin-1 for the string given to loadHTML(), so multi-byte
 * characters have to be pre-encoded as HTML entities or saveHTML() returns
 * mojibake. The expected values below were captured from the *old* deprecated
 * implementation before the swap, so these assertions prove the replacement is
 * byte-equivalent rather than merely plausible.
 *
 * @command  php vendor/bin/pest tests/Unit/TextProcessorEntityEncodingTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Run a fragment through the load/save round-trip TextProcessor uses.
 */
function textProcessorRoundTrip(string $html): string
{
    return TextProcessor::getInnerHtml(TextProcessor::loadHtml($html));
}

it('round-trips multibyte content byte-for-byte as the deprecated call did', function (string $source, string $expected): void {
    expect(textProcessorRoundTrip($source))->toBe($expected);
})->with([
    // Each pair is [input, output-captured-from-the-old-implementation]
    'em dash' => [
        '<p>an — em dash</p>',
        '<p>an — em dash</p>',
    ],
    'curly quotes' => [
        '<p>“smart” and ‘single’</p>',
        '<p>“smart” and ‘single’</p>',
    ],
    'non-Latin script' => [
        '<p>日本語のテキスト</p>',
        '<p>日本語のテキスト</p>',
    ],
    'symbols' => [
        '<p>UAMS® Health™ · 50 µg</p>',
        '<p>UAMS® Health™ · 50 µg</p>',
    ],
    'accented Latin' => [
        '<p>café naïve über</p>',
        '<p>café naïve über</p>',
    ],
    'emoji' => [
        '<p>ok 🎉 done</p>',
        '<p>ok 🎉 done</p>',
    ],
    'astral plane' => [
        '<p>𝕏 bold</p>',
        '<p>𝕏 bold</p>',
    ],
    'non-breaking space' => [
        "<p>a\u{00A0}b</p>",
        "<p>a\u{00A0}b</p>",
    ],
    'nested markup with an escaped ampersand' => [
        '<div class="x"><a href="/a?b=1&amp;c=2">link — “q”</a><ul><li>é</li></ul></div>',
        "<div class=\"x\">\n<a href=\"/a?b=1&amp;c=2\">link — “q”</a><ul><li>é</li></ul>\n</div>",
    ],
    'WordPress block comments' => [
        '<!-- wp:paragraph --><p>é — ®</p><!-- /wp:paragraph -->',
        "<!-- wp:paragraph --><p>é — ®</p>\n<!-- /wp:paragraph -->",
    ],
]);

it('does not mojibake multibyte characters', function (): void {
    // The failure mode if the pre-encoding were simply dropped: each UTF-8 byte
    // reinterpreted as Latin-1, so "é" becomes "Ã©".
    $result = textProcessorRoundTrip('<p>café — 日本</p>');

    expect($result)->toContain('café')
        ->and($result)->toContain('日本')
        ->and($result)->not->toContain('Ã')
        ->and($result)->not->toContain('â€');
});

it('leaves plain ASCII markup untouched', function (): void {
    $source = '<p class="lead">Plain &amp; simple text</p>';

    expect(textProcessorRoundTrip($source))->toBe($source);
});

it('emits no deprecation notice while encoding', function (): void {
    $deprecations = [];

    set_error_handler(
        function (int $severity, string $message) use (&$deprecations): bool {
            if ($severity === E_DEPRECATED || $severity === E_USER_DEPRECATED) {
                $deprecations[] = $message;
            }

            return true;
        }
    );

    try {
        textProcessorRoundTrip('<p>café — “smart” 日本 🎉</p>');
    } finally {
        restore_error_handler();
    }

    expect($deprecations)->toBe([]);
});

it('preserves every multibyte character in well-formed markup', function (): void {
    // The load-bearing invariant, asserted over many generated documents rather
    // than a handful of examples: for well-formed input, whatever text went in
    // comes back out unchanged. Fixed seed — broad but deterministic.
    mt_srand(20260728);

    $characters = [];

    foreach ([0xA0, 0xA9, 0xAE, 0xB5, 0xE9, 0xEF, 0xFC, 0xDF] as $codepoint) {
        $characters[] = mb_chr($codepoint, 'UTF-8');
    }

    foreach ([0x2013, 0x2014, 0x2018, 0x2019, 0x201C, 0x201D, 0x2026, 0x2122, 0x20AC] as $codepoint) {
        $characters[] = mb_chr($codepoint, 'UTF-8');
    }

    foreach ([0x0391, 0x0416, 0x05D0, 0x0627, 0x4E2D, 0x65E5, 0xAC00] as $codepoint) {
        $characters[] = mb_chr($codepoint, 'UTF-8');
    }

    foreach ([0x1D54F, 0x1F389, 0x1F600, 0x20BB7] as $codepoint) {
        $characters[] = mb_chr($codepoint, 'UTF-8');
    }

    $pairs = [['<p>', '</p>'], ['<b>', '</b>'], ['<em>', '</em>'], ['<li>', '</li>']];

    for ($case = 0; $case < 250; $case++) {
        $text = '';

        for ($index = 0; $index < mt_rand(1, 20); $index++) {
            $text .= $characters[mt_rand(0, count($characters) - 1)];
        }

        $pair = $pairs[mt_rand(0, count($pairs) - 1)];
        $source = $pair[0].$text.$pair[1];

        expect(textProcessorRoundTrip($source))->toBe($source, "case {$case}");
    }
});

it('no longer calls the deprecated HTML-ENTITIES pseudo-encoding', function (): void {
    $source = file_get_contents(
        dirname(__DIR__, 2).'/src/Services/WordPressImport/Utilities/TextProcessor.php'
    );

    expect($source)->not->toBeFalse();

    // Compare tokens rather than raw text: the docblock explaining why the call
    // is gone necessarily names it, and a substring match would trip on that.
    $code = '';

    foreach (token_get_all((string) $source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $code .= is_array($token) ? $token[1] : $token;
    }

    expect($code)->not->toContain('mb_convert_encoding')
        ->and($code)->not->toContain('HTML-ENTITIES');
});
