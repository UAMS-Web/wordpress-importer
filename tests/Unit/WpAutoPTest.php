<?php

declare(strict_types=1);

/**
 * Tests `WpAutoP`, this package's port of WordPress's `wpautop()`, by the exact HTML
 * it gives for each input shape: paragraphs and line breaks, `<pre>` and script
 * protection, block-level tags, and the clean-up passes that remove stray `<p>` and
 * `<br />` around blocks.
 *
 * The expected strings are what the class returns today, pinned as characterization.
 * Compared with WordPress 7.1.2's `wpautop()`, most rows agree; those that do not are
 * named in #145 (a bare block tag after text stays inside an open paragraph) and #146
 * (a single block returned early, an image split out of its paragraph, list and table
 * newlines, and the spaces around a script newline).
 *
 * @command  php vendor/bin/pest tests/Unit/WpAutoPTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;

it('returns nothing for blank text', function (string $text): void {
    expect((new WpAutoP)->process($text))->toBe('');
})->with([
    'empty' => [''],
    'whitespace only' => ["  \n\t "],
]);

it('wraps text in paragraphs and breaks as the port does today', function (string $text, bool $br, string $html): void {
    expect((new WpAutoP)->process($text, $br))->toBe($html);
})->with([
    'one line' => ['Hello', true, "<p>Hello</p>\n"],
    'two paragraphs' => ["One\n\nTwo", true, "<p>One</p>\n<p>Two</p>\n"],
    'a single line break' => ["One\nTwo", true, "<p>One<br />\nTwo</p>\n"],
    'a single line break with line breaks off' => ["One\nTwo", false, "<p>One\nTwo</p>\n"],
    'CRLF and CR newlines' => ["One\r\n\r\nTwo\rThree", true, "<p>One</p>\n<p>Two<br />\nThree</p>\n"],
    'a run of blank lines' => ["One\n\n\n\nTwo", true, "<p>One</p>\n<p>Two</p>\n"],
    'two br tags in a row' => ['One<br><br/>Two', true, "<p>One</p>\n<p>Two</p>\n"],
    'each br spelling' => ["A<br>\nB<br/>\nC", true, "<p>A<br />\nB<br />\nC</p>\n"],
    'a pre between paragraphs' => ["Intro\n\n<pre>a\n\nb</pre>\n\nOutro", true, "<p>Intro</p>\n<pre>a\n\nb</pre>\n<p>Outro</p>\n"],
    'two pres' => ["<pre>x\ny</pre>\n\nmid\n\n<pre class=\"c\">p\n\nq</pre>\n\nend", true, "<pre>x\ny</pre>\n<p>mid</p>\n<pre class=\"c\">p\n\nq</pre>\n<p>end</p>\n"],
    'a pre on its own' => ["<pre>a\n\nb</pre>", true, "<pre>a\n\nb</pre>"],
    'a pre between text with no blank lines' => ['lead<pre>a</pre>tail', true, "<p>lead</p>\n<pre>a</pre>\n<p>tail</p>\n"],
    'a single block' => ["<div>One\nTwo</div>", true, "<div>One\nTwo</div>"],
    'two different blocks' => ['<h2>Title</h2><p>Body</p>', true, "<h2>Title</h2>\n<p>Body</p>\n"],
    'a self-closing block' => ['Before<img src="x.jpg" />After', true, "<p>Before</p>\n<img src=\"x.jpg\" />\n<p>After</p>\n"],
    'a script between paragraphs' => ["Text\n\n<script>a\nb</script>\n\nMore", true, "<p>Text</p>\n<p><script>a\nb</script></p>\n<p>More</p>\n"],
    'a script with line breaks off' => ["Text\n\n<script>a\nb</script>\n\nMore", false, "<p>Text</p>\n<p><script>a\nb</script></p>\n<p>More</p>\n"],
    'text closing a div' => ["Lead\n\nText</div>", true, "<p>Lead</p>\n<p>Text</p></div>\n"],
    'a list on its own' => ["<ul>\n<li>One</li>\n<li>Two</li>\n</ul>", true, "<ul>\n<li>One</li>\n\n<li>Two</li>\n\n</ul>"],
    'a list after text' => ["Lead\n\n<ul><li>One</li></ul>", true, "<p>Lead</p>\n<ul><li>One</li>\n</ul>\n"],
    'a blockquote after text' => ["Lead\n\n<blockquote>Quote</blockquote>", true, "<p>Lead</p>\n<blockquote><p>Quote</p></blockquote>\n"],
    'a blockquote with an attribute' => ["Lead\n\n<blockquote class=\"q\">Quote\nmore</blockquote>\n\nEnd", true, "<p>Lead</p>\n<blockquote class=\"q\"><p>Quote<br />\nmore</p></blockquote>\n<p>End</p>\n"],
    'a line break before a closing tag' => ["<div>One\n</div>\n\nAfter", true, "<div>One\n</div>\n<p>After</p>\n"],
    'inline markup' => ["A <strong>bold</strong>\nline", true, "<p>A <strong>bold</strong><br />\nline</p>\n"],
    'a table cell' => ["<table><tr><td>One\nTwo</td></tr></table>\n\nAfter", true, "<table><tr><td>One<br />\nTwo</td>\n</tr>\n</table>\n<p>After</p>\n"],
    'an attributed block after text' => ['Lead<div class="a">x</div>', true, "<p>Lead</p>\n<div class=\"a\">x</div>\n"],
    'an attributed hr after text' => ['Lead<hr class="x">Tail', true, "<p>Lead</p>\n<hr class=\"x\">Tail</p>\n"],
]);

it('turns single line breaks into br tags by default', function (): void {
    expect((new WpAutoP)->process("One\nTwo"))->toBe("<p>One<br />\nTwo</p>\n");
});

it('handles the edge cases its clean-up passes exist for, as the port does today', function (string $text, string $html): void {
    expect((new WpAutoP)->process($text))->toBe($html);
})->with([
    'a single CRLF' => ["One\r\nTwo", "<p>One<br />\nTwo</p>\n"],
    'closing pre tags without an opener' => ['a</pre>b</pre><pre>c</pre>d', "<p>ab</p>\n<pre>c</pre>\n<p>d</p>\n"],
    'a script newline inside a lone block' => ["<div><script>a\nb</script></div>", "<div><script>a\nb</script></div>"],
    'a spaced script newline inside a lone block' => ["<div><script>a \n b</script></div>", "<div><script>a\nb</script></div>"],
    'a script newline inside a paragraph' => ["Text <script>a \n b</script>", "<p>Text <script>a\nb</script></p>\n"],
    'a leading newline' => ["\nOne\n\nTwo", "<p>One</p>\n<p>Two</p>\n"],
    'an empty paragraph' => ["One\n\n<p> </p>\n\nTwo", "<p>One</p>\n<p>Two</p>\n"],
]);

it('protects a large element whose first protection pass fails, the second way', function (): void {
    // Characterization (#146): the first pass gives up on this element at the backtrack
    // limit, the clean-up shrinks the text, and the second pass then protects it
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '1000000');

    try {
        $unit = "a  \nb".str_repeat("\n ", 20)."\n";
        $html = (new WpAutoP)->process("Intro\n\n<svg>".str_repeat($unit, 28000)."</svg>\n\nOutro");
    } finally {
        ini_set('pcre.backtrack_limit', $limit === false ? '1000000' : $limit);
    }

    expect($html)->toContain("<svg>a  <br />\nb")
        ->and($html)->not->toContain('<WPPreserveNewline');
});

it('restores a placeholder name already in the text as the pre it names', function (): void {
    // Characterization: the placeholders are `<pre wp-pre-tag-N></pre>`, numbered from
    // zero, and the restore replaces every occurrence, so text that already holds one
    // gets that pre's content too. WordPress's wpautop() does the same.
    expect((new WpAutoP)->process("<pre wp-pre-tag-1></pre>\n\n<pre>B</pre>"))->toBe("<pre>B</pre>\n\n<pre>B</pre>");
});

it('leaves a bare block tag after text inside the paragraph', function (string $text, bool $br, string $html): void {
    // Characterization: the opening-tag pattern needs a character before `>`, so a
    // tag without attributes is not split from the text before it (#145)
    expect((new WpAutoP)->process($text, $br))->toBe($html);
})->with([
    'a bare block after text (#145)' => ['Lead<div>x</div>', true, "<p>Lead<div>x</div>\n"],
    'a bare hr after text (#145)' => ['Lead<hr>Tail', true, "<p>Lead<hr>\n<p>Tail</p>\n"],
]);
