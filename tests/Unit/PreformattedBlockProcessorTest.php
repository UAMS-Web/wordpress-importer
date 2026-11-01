<?php

declare(strict_types=1);

/**
 * Tests for PreformattedBlockProcessor, which turns `wp:preformatted` blocks into
 * an escaped `<pre>` element.
 *
 * `canProcess()` accepts only a comment node whose trimmed value is exactly
 * `wp:preformatted`. `process()` collects trimmed text and the serialized HTML
 * of elements between the opening comment and the closing `/wp:preformatted`
 * comment, escapes the result, and wraps it in `<pre>`. An empty block writes a
 * warning and returns null.
 *
 * Fixtures set `formatOutput`, as `TextProcessor::loadHtml()` does for the
 * document production passes in, so `saveHTML()` emits the trailing newline
 * after a block element that the processor has to trim.
 *
 * @command  php vendor/bin/pest tests/Unit/PreformattedBlockProcessorTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\PreformattedBlockProcessor;

/**
 * Parse an HTML fragment and return its opening node: the first child of the
 * wrapping div.
 *
 * @param  string  $html  The fragment to parse
 * @return DOMNode The fragment's first top-level node
 */
function preformattedOpeningNode(string $html): DOMNode
{
    $document = new DOMDocument;
    $document->formatOutput = true;
    $document->loadHTML('<div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $first = $document->getElementById('root')?->firstChild;

    if (! $first instanceof DOMNode) {
        throw new RuntimeException('Fixture has no nodes');
    }

    return $first;
}

/**
 * Build an AssetImporter without running its constructor; the preformatted
 * processor never reads it.
 */
function preformattedAssetImporter(): AssetImporter
{
    return (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();
}

/**
 * Run the processor over a fragment whose first node is the opening comment.
 *
 * @param  string  $html  The fragment to parse
 * @param  BufferedOutput  $output  Console output the processor writes to
 * @param  ?string  $pageSlug  The page slug passed to the processor
 * @return ?string The processor's result
 */
function processPreformatted(string $html, BufferedOutput $output, ?string $pageSlug = null): ?string
{
    return (new PreformattedBlockProcessor)->process(
        preformattedOpeningNode($html),
        [],
        'default',
        preformattedAssetImporter(),
        $output,
        [],
        [],
        $pageSlug
    );
}

/**
 * What a buffered output holds, with every line break folded to LF.
 *
 * `BufferedOutput` ends each line with `PHP_EOL`, which is CRLF on Windows. The line
 * printed is under test, not how a console ends one.
 *
 * @param  BufferedOutput  $output  The output to read
 * @return string The output, LF-separated
 */
function preformattedOutputLines(BufferedOutput $output): string
{
    return \preg_replace('/\R/', "\n", $output->fetch()) ?? '';
}

it('recognizes a wp:preformatted comment', function (string $comment): void {
    $node = (new DOMDocument)->createComment($comment);

    expect((new PreformattedBlockProcessor)->canProcess($node, 'default', null))->toBeTrue();
})->with([
    'bare' => ['wp:preformatted'],
    'surrounding whitespace' => ['  wp:preformatted  '],
]);

it('rejects comments that are not an opening wp:preformatted block', function (string $comment): void {
    $node = (new DOMDocument)->createComment($comment);

    expect((new PreformattedBlockProcessor)->canProcess($node, 'default', null))->toBeFalse();
})->with([
    'closing comment' => ['/wp:preformatted'],
    'with attributes' => ['wp:preformatted {"fontSize":"small"}'],
    'longer block name' => ['wp:preformattedx'],
    'prefixed' => ['x wp:preformatted'],
    'another block' => ['wp:code'],
]);

it('rejects nodes that are not comments', function (DOMNode $node): void {
    expect((new PreformattedBlockProcessor)->canProcess($node, 'default', null))->toBeFalse();
})->with([
    'text node holding the block name' => fn (): DOMNode => (new DOMDocument)->createTextNode('wp:preformatted'),
    'element' => fn (): DOMNode => (new DOMDocument)->createElement('pre', 'wp:preformatted'),
]);

it('wraps the serialized element in an escaped pre tag', function (): void {
    $output = new BufferedOutput;

    $result = processPreformatted(
        '<!-- wp:preformatted --><pre class="wp-block-preformatted">a &lt; b</pre><!-- /wp:preformatted -->',
        $output
    );

    // The element is serialized as HTML, then that HTML is escaped as text. With
    // formatted output the element alone between comments serializes with a
    // trailing newline, which the final trim removes
    expect($result)->toBe('<pre>&lt;pre class=&quot;wp-block-preformatted&quot;&gt;a &amp;lt; b&lt;/pre&gt;</pre>')
        ->and($output->fetch())->toBe('');
});

it('trims each text node and joins the pieces without separators', function (): void {
    $result = processPreformatted(
        "<!-- wp:preformatted -->\n  first  \n<b>bold</b>\n  it's \"quoted\"  \n<!-- /wp:preformatted -->",
        new BufferedOutput
    );

    expect($result)->toBe('<pre>first&lt;b&gt;bold&lt;/b&gt;it&#039;s &quot;quoted&quot;</pre>');
});

it('stops at the closing comment and ignores what follows it', function (): void {
    $result = processPreformatted(
        '<!-- wp:preformatted -->inside<!-- /wp:preformatted -->outside<p>after</p>',
        new BufferedOutput
    );

    expect($result)->toBe('<pre>inside</pre>');
});

it('accepts a closing comment with surrounding whitespace', function (): void {
    $result = processPreformatted(
        '<!-- wp:preformatted -->inside<!--   /wp:preformatted   -->outside',
        new BufferedOutput
    );

    expect($result)->toBe('<pre>inside</pre>');
});

it('skips other comments inside the block without collecting them', function (): void {
    $result = processPreformatted(
        '<!-- wp:preformatted -->one<!-- note --><!-- /wp:preformatted extra -->two<!-- /wp:preformatted -->',
        new BufferedOutput
    );

    // Neither the note nor the comment with trailing text closes the block
    expect($result)->toBe('<pre>onetwo</pre>');
});

it('does not treat text that reads /wp:preformatted as the closing comment', function (): void {
    $result = processPreformatted(
        '<!-- wp:preformatted --><b>x</b>/wp:preformatted<!-- /wp:preformatted -->',
        new BufferedOutput
    );

    expect($result)->toBe('<pre>&lt;b&gt;x&lt;/b&gt;/wp:preformatted</pre>');
});

it('collects every remaining sibling when the closing comment is missing', function (): void {
    $result = processPreformatted(
        '<!-- wp:preformatted -->one<i>two</i>',
        new BufferedOutput
    );

    expect($result)->toBe('<pre>one&lt;i&gt;two&lt;/i&gt;</pre>');
});

it('keeps any non-empty text, whatever it says', function (string $text): void {
    $result = processPreformatted(
        '<!-- wp:preformatted -->'.$text.'<!-- /wp:preformatted -->',
        new BufferedOutput
    );

    expect($result)->toBe('<pre>'.$text.'</pre>');
})->with([
    'a sentence' => ['PEST Mutator was here!'],
]);

it('keeps a text value of "0" rather than treating it as empty', function (): void {
    $result = processPreformatted(
        '<!-- wp:preformatted -->0<!-- /wp:preformatted -->',
        new BufferedOutput
    );

    expect($result)->toBe('<pre>0</pre>');
});

it('warns and returns null for an empty block, naming the site and slug', function (): void {
    $output = new BufferedOutput;

    $result = processPreformatted(
        "<!-- wp:preformatted -->\n   \n<!-- /wp:preformatted -->",
        $output,
        'about-us'
    );

    expect($result)->toBeNull()
        ->and(preformattedOutputLines($output))
        ->toBe("  - No content found in wp:preformatted block for site 'default' (slug: about-us)\n");
});

it('names the slug as none when no page slug is given', function (): void {
    $output = new BufferedOutput;

    $result = processPreformatted('<!-- wp:preformatted -->', $output);

    expect($result)->toBeNull()
        ->and(preformattedOutputLines($output))
        ->toBe("  - No content found in wp:preformatted block for site 'default' (slug: none)\n");
});
