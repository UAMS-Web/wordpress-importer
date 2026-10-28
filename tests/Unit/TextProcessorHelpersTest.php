<?php

declare(strict_types=1);

/**
 * Tests that `TextProcessor`'s helpers decode entities (everywhere, or everywhere
 * outside HTML comments), strip control characters, load HTML into a document
 * under one root, find a block comment's JSON by counting braces, and recognize
 * block comments.
 *
 * @command  php vendor/bin/pest tests/Unit/TextProcessorHelpersTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * The HTML of a loaded document's root, without the root element itself.
 */
function textProcessorHelpersRoot(DOMDocument $doc): string
{
    $root = $doc->getElementsByTagName('div')->item(0);
    $html = '';

    if ($root !== null) {
        foreach ($root->childNodes as $child) {
            $html .= $doc->saveHTML($child);
        }
    }

    return $html;
}

/**
 * Run the callback with PCRE's backtrack limit at 0, so any pattern that has to
 * backtrack fails, and restore the limit afterwards.
 *
 * @template T
 *
 * @param  callable(): T  $callback
 * @return T
 */
function textProcessorHelpersAtPcreLimit(callable $callback): mixed
{
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '0');

    try {
        return $callback();
    } finally {
        ini_set('pcre.backtrack_limit', $limit === false ? '1000000' : $limit);
    }
}

it('decodes quotes and HTML5 entities', function (): void {
    expect(TextProcessor::decodeEntities('&quot;&#039;&apos;&hellip;&amp;'))->toBe('"\'\'…&');
});

it('decodes entities everywhere outside HTML comments', function (string $content, string $decoded): void {
    expect(TextProcessor::decodeEntitiesPreservingComments($content))->toBe($decoded);
})->with([
    'between and after comments' => ['a &amp; <!-- x &amp; --> b &lt; <!--y &quot;-->&gt;', 'a & <!-- x &amp; --> b < <!--y &quot;-->>'],
    'a comment spread over lines' => ["<!-- wp:x {\"a\":\"&quot;\"}\n-->&amp;", "<!-- wp:x {\"a\":\"&quot;\"}\n-->&"],
    'text that ends with an unclosed comment opener' => ['x &amp; <!--', 'x & <!--'],
    'no comments' => ['&lt;p&gt;', '<p>'],
]);

it('decodes the whole content, comments included, when splitting it fails at a PCRE limit', function (): void {
    $decoded = textProcessorHelpersAtPcreLimit(
        static fn (): string => TextProcessor::decodeEntitiesPreservingComments('&amp; <!-- a &amp; b --> &lt;')
    );

    expect($decoded)->toBe('& <!-- a & b --> <');
});

it('strips control characters and keeps tabs, newlines and non-breaking spaces', function (): void {
    expect(TextProcessor::normalizeUtf8("a\x01b\x0Bc\x0Cd\x1Fe\u{0085}f\u{00A0}g\t\n\r\x7Fh"))
        ->toBe("abcdef\u{00A0}g\t\n\r\x7Fh");
});

it('returns content that is not valid UTF-8 unchanged', function (): void {
    expect(TextProcessor::normalizeUtf8("a\xC3(b\x01"))->toBe("a\xC3(b\x01");
});

it('loads HTML under one root, decoding entities and keeping whitespace between nodes', function (): void {
    $doc = TextProcessor::loadHtml("<p>It&apos;s &quot;a&quot;</p>\n<p>b</p>");

    expect(textProcessorHelpersRoot($doc))->toBe("<p>It's \"a\"</p>\n<p>b</p>");
});

it('keeps characters across the Unicode range, up to U+10FFFF', function (string $character): void {
    $doc = TextProcessor::loadHtml("<p>a{$character}b</p>");

    expect($doc->getElementsByTagName('p')->item(0)?->textContent)->toBe("a{$character}b");
})->with([
    'an odd code point (é)' => ["\u{00E9}"],
    'an even code point (è)' => ["\u{00E8}"],
    'U+10FFFF' => ["\u{10FFFF}"],
]);

it('reads U+0080 back as one character', function (): void {
    // It is encoded to &#128;, which libxml 2.15.4 on macOS reads as the euro sign
    // (Windows-1252, as browsers do) and the CI runner's libxml reads as U+0080
    // itself. processForBard() strips U+0080 to U+009F before loading, so only a
    // direct caller meets this.
    $text = TextProcessor::loadHtml("<p>a\u{0080}b</p>")->getElementsByTagName('p')->item(0)?->textContent;

    // Either way it stays one character; left unencoded it would come back as two
    expect($text)->toStartWith('a')
        ->toEndWith('b')
        ->and(mb_strlen((string) $text))->toBe(3);
});

it('keeps a DEL character as it was', function (): void {
    $doc = TextProcessor::loadHtml("<p>a\x7Fb</p>");

    expect($doc->getElementsByTagName('p')->item(0)?->textContent)->toBe("a\x7Fb");
});

it('finds the JSON object that starts a string by counting its braces', function (string $json, string $found): void {
    expect(TextProcessor::extractCompleteJson($json))->toBe($found);
})->with([
    'trailing text' => ['{"a":1} -->', '{"a":1}'],
    'nested objects' => ['{"a":{"b":{"c":1}}} x', '{"a":{"b":{"c":1}}}'],
    'braces inside strings' => ['{"a":"}{"} x', '{"a":"}{"}'],
    'escaped quotes inside strings' => ['{"a":"x\\"}y"} z', '{"a":"x\\"}y"}'],
    'an escaped backslash before a closing quote' => ['{"a":"x\\\\"} z', '{"a":"x\\\\"}'],
    'a second object after the first' => ['{"a":1}{"b":2}', '{"a":1}'],
    'an extra closing brace' => ['{"a":1}}', '{"a":1}'],
    'an object that never closes' => ['{"a":{"b":1}', '{"a":{"b":1}'],
    'a string that never closes' => ['{"a":"}', '{"a":"}'],
    'the shortest object' => ['{}', '{}'],
]);

it('reads past the last character only by its length', function (): void {
    // The host app turns a warning into an exception, so reading past the end counts
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $found = TextProcessor::extractCompleteJson('{"a":1');
    } finally {
        restore_error_handler();
    }

    expect($found)->toBe('{"a":1');
});

it('finds a block comment\'s JSON from its first opening brace', function (string $comment, string $json): void {
    expect(TextProcessor::extractJsonFromWordPressComment($comment, 'default', 'x'))->toBe($json);
})->with([
    'a comment with attributes' => ['wp:heading {"level":2} /', '{"level":2}'],
    'a comment without attributes' => ['wp:separator', ''],
]);

it('recognizes an opening or a closing block comment', function (string $content, bool $found): void {
    expect(TextProcessor::containsWordPressBlockComments($content))->toBe($found);
})->with([
    'an opening comment' => ['<p>x</p><!-- wp:image -->', true],
    'only a closing comment' => ['<!-- /wp:image -->', true],
    'an ordinary comment' => ['<!-- note --><p>x</p>', false],
]);

it('decodes HTML5 entities that the HTML parser does not know', function (): void {
    $doc = TextProcessor::loadHtml('<p>&bigstar;&NewLine;x</p>');

    expect($doc->getElementsByTagName('p')->item(0)?->textContent)->toBe("\u{2605}\nx");
});

it('wraps the content in its own root even when the content starts with a div', function (): void {
    $doc = TextProcessor::loadHtml('<div class="a">x</div><p>y</p>');

    expect(textProcessorHelpersRoot($doc))->toBe("<div class=\"a\">x</div>\n<p>y</p>");
});

it('keeps an attribute value holding an escaped quote whole', function (string $html, string $value): void {
    // #157: the quote is left encoded inside the tag, and libxml decodes it as part
    // of the value
    $doc = TextProcessor::loadHtml($html);

    expect($doc->getElementsByTagName('p')->item(0)?->getAttribute('title'))->toBe($value);
})->with([
    'a named double quote' => ['<p title="a &quot;b&quot; c">x</p>', 'a "b" c'],
    'a decimal double quote' => ['<p title="a &#34;b&#34; c">x</p>', 'a "b" c'],
    'a hex double quote' => ['<p title="a &#x22;b&#x22; c">x</p>', 'a "b" c'],
    'a named single quote' => ["<p title='it&apos;s'>x</p>", "it's"],
    'a decimal single quote' => ["<p title='it&#39;s'>x</p>", "it's"],
    'a hex single quote' => ["<p title='it&#x27;s'>x</p>", "it's"],
    'a quote after a > in the value' => ['<p title="a > &quot;b&quot;">x</p>', 'a > "b"'],
    'a quote in the second of two values' => ['<p class="c" title="a &quot;b&quot;">x</p>', 'a "b"'],
]);

it('decodes the other entities inside a tag', function (): void {
    $doc = TextProcessor::loadHtml('<p title="&bigstar; &amp; &lt;b&gt;">x</p>');

    expect($doc->getElementsByTagName('p')->item(0)?->getAttribute('title'))->toBe("\u{2605} & <b>");
});

it('decodes the quotes in text', function (): void {
    $doc = TextProcessor::loadHtml('<p>a &quot;b&quot; &#39;c&#39; &apos;d&apos;</p>');

    expect($doc->getElementsByTagName('p')->item(0)?->textContent)->toBe("a \"b\" 'c' 'd'");
});

it('clears the libxml errors recorded before it loads', function (): void {
    $internal = libxml_use_internal_errors(true);

    try {
        // A malformed XML document records an error
        @simplexml_load_string('<a>');
        $before = count(libxml_get_errors());

        TextProcessor::loadHtml('<p>x');
        $after = count(libxml_get_errors());
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($internal);
    }

    expect($before)->toBeGreaterThan(0)
        ->and($after)->toBe(0);
});
