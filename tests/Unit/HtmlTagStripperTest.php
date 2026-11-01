<?php

declare(strict_types=1);

/**
 * Tests that `HtmlTagStripper` turns HTML into plain text: entities decoded first,
 * `<br>` and block-level tags read as spaces, other tags removed with their content
 * kept, and whitespace collapsed and trimmed (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/HtmlTagStripperTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;

it('decodes entities before it removes tags', function (): void {
    // Encoded markup becomes real markup, which is then stripped like any other
    expect(HtmlTagStripper::strip('&lt;p&gt;a&lt;/p&gt;b &amp; &quot;c&quot;'))->toBe('a b & "c"');
});

it('reads every form of a line break as a space', function (): void {
    expect(HtmlTagStripper::strip('a<br>b<BR/>c<br />d<br   >e'))->toBe('a b c d e');
});

it('reads each block-level tag as a space', function (string $element): void {
    expect(HtmlTagStripper::strip("z<{$element}>a</{$element}>y"))->toBe('z a y');
})->with([
    'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'div', 'span', 'blockquote', 'ul', 'ol', 'li',
    'table', 'tr', 'td', 'th', 'thead', 'tbody', 'tfoot', 'caption', 'colgroup', 'col', 'dl',
    'dt', 'dd', 'pre', 'address', 'article', 'aside', 'footer', 'header', 'hgroup', 'main',
    'nav', 'section', 'figure', 'figcaption', 'fieldset', 'legend', 'details', 'summary',
]);

it('reads a block-level opening tag with attributes as a space', function (): void {
    expect(HtmlTagStripper::strip('a<p class="lead" id="x">b'))->toBe('a b');
});

it('reads a block-level closing tag with inner whitespace as a space', function (): void {
    expect(HtmlTagStripper::strip('a</ P >b'))->toBe('a b');
});

it('matches block-level tags case-insensitively', function (): void {
    expect(HtmlTagStripper::strip('a<DIV>b</Div>c'))->toBe('a b c');
});

it('removes an inline tag and keeps its content with no space', function (): void {
    expect(HtmlTagStripper::strip('a<strong>b</strong>c<sup>d</sup>e<em>f</em>'))->toBe('abcdef');
});

it('does not read a tag that only begins with a block-level name as a block', function (): void {
    // `param` and `pa` begin with `p` but are not block-level names, so they are removed
    // like inline tags rather than read as spaces
    expect(HtmlTagStripper::strip('a<param>b<pa>c</pa>d'))->toBe('abcd');
});

it('collapses whitespace runs and trims the result', function (): void {
    expect(HtmlTagStripper::strip("  a \n\t b  <p> </p>  c  "))->toBe('a b c');
});

it('returns an empty string for markup with no text', function (): void {
    expect(HtmlTagStripper::strip('<p><br></p><img src="x.png" />'))->toBe('');
});

it('keeps the text when every regex pass fails', function (): void {
    // A zero backtrack limit makes each preg_replace() return null, so each pass
    // falls back to the text it was given; only strip_tags() and trim() still apply
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '0');

    try {
        $result = HtmlTagStripper::strip(" a<br>b<p>c</p>  d\n");
    } finally {
        ini_set('pcre.backtrack_limit', $limit === false ? '1000000' : $limit);
    }

    expect($result)->toBe('abc  d');
});
