<?php

declare(strict_types=1);

/**
 * Tests for WpSpacerBlockProcessor, which strips WordPress `wp:spacer` blocks.
 *
 * `canProcess()` accepts only a comment node whose trimmed value is `wp:spacer`,
 * optionally followed by a JSON attribute object. `process()` returns empty HTML
 * and a skip count covering every node from the opening comment through the
 * closing `/wp:spacer` comment, or through the end of the siblings when no
 * closing comment exists.
 *
 * @command  php vendor/bin/pest tests/Unit/WpSpacerBlockProcessorTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpSpacerBlockProcessor;

/**
 * Parse an HTML fragment and return the children of its wrapping div.
 *
 * @param  string  $html  The fragment to parse
 * @return DOMNodeList<DOMNode> The fragment's top-level nodes
 */
function spacerFragmentNodes(string $html): DOMNodeList
{
    $document = new DOMDocument;
    $document->loadHTML('<div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $root = $document->getElementById('root');

    if (! $root instanceof DOMElement) {
        throw new RuntimeException('Fixture root missing');
    }

    return $root->childNodes;
}

/**
 * Return one node of a parsed fragment.
 *
 * @param  DOMNodeList<DOMNode>  $nodes  The fragment's top-level nodes
 * @param  int  $index  The node's position
 * @return DOMNode The node at that position
 */
function spacerNodeAt(DOMNodeList $nodes, int $index): DOMNode
{
    $node = $nodes->item($index);

    if (! $node instanceof DOMNode) {
        throw new RuntimeException("Fixture has no node at {$index}");
    }

    return $node;
}

/**
 * Build an AssetImporter without running its constructor; the spacer processor
 * never reads it.
 */
function spacerAssetImporter(): AssetImporter
{
    return (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();
}

it('recognizes a wp:spacer comment with or without attributes', function (string $comment): void {
    $node = (new DOMDocument)->createComment($comment);

    expect((new WpSpacerBlockProcessor)->canProcess($node, 'default', null))->toBeTrue();
})->with([
    'bare' => ['wp:spacer'],
    'surrounding whitespace' => ['  wp:spacer  '],
    'with attributes' => [' wp:spacer {"height":"40px"} '],
    'attributes spanning lines' => ["wp:spacer {\n\"height\":\"40px\"\n}"],
]);

it('rejects comments that are not an opening wp:spacer block', function (string $comment): void {
    $node = (new DOMDocument)->createComment($comment);

    expect((new WpSpacerBlockProcessor)->canProcess($node, 'default', null))->toBeFalse();
})->with([
    'closing comment' => ['/wp:spacer'],
    'another block' => ['wp:separator'],
    'longer block name' => ['wp:spacerx'],
    'prefixed' => ['x wp:spacer'],
    'trailing text after attributes' => ['wp:spacer {"height":"40px"} extra'],
]);

it('rejects nodes that are not comments', function (DOMNode $node): void {
    expect((new WpSpacerBlockProcessor)->canProcess($node, 'default', null))->toBeFalse();
})->with([
    'text node holding the block name' => fn (): DOMNode => (new DOMDocument)->createTextNode('wp:spacer'),
    'element' => fn (): DOMNode => (new DOMDocument)->createElement('div', 'wp:spacer'),
]);

it('strips the block and counts every node through the closing comment', function (): void {
    $nodes = spacerFragmentNodes(
        '<!-- wp:spacer {"height":"40px"} --><div style="height:40px" class="wp-block-spacer"></div><!-- /wp:spacer --><p>after</p>'
    );

    $opening = spacerNodeAt($nodes, 0);

    expect($opening)->toBeInstanceOf(DOMComment::class);

    $result = (new WpSpacerBlockProcessor)->process(
        $opening,
        [],
        'default',
        spacerAssetImporter(),
        new BufferedOutput
    );

    // Opening comment, the spacer div and the closing comment; the trailing
    // paragraph is not part of the block
    expect($result)->toBe([
        'html' => '',
        'processedNode' => $opening,
        'skipCount' => 3,
    ]);
});

it('counts whitespace and other comments inside the block before the closing comment', function (): void {
    $nodes = spacerFragmentNodes(
        "<!-- wp:spacer -->\n<!-- unrelated -->\n<div class=\"wp-block-spacer\"></div>\n<!-- /wp:spacer --><p>after</p>"
    );

    $result = (new WpSpacerBlockProcessor)->process(
        spacerNodeAt($nodes, 0),
        [],
        'default',
        spacerAssetImporter(),
        new BufferedOutput
    );

    // Opening comment, text, the unrelated comment, text, div, text, closing comment
    expect($result['skipCount'] ?? null)->toBe(7);
});

it('does not treat text that reads /wp:spacer as the closing comment', function (): void {
    $nodes = spacerFragmentNodes('<!-- wp:spacer --><div></div>/wp:spacer<!-- /wp:spacer --><p>after</p>');

    $result = (new WpSpacerBlockProcessor)->process(
        spacerNodeAt($nodes, 0),
        [],
        'default',
        spacerAssetImporter(),
        new BufferedOutput
    );

    // Opening comment, div, the text node and the real closing comment
    expect($result['skipCount'] ?? null)->toBe(4);
});

it('counts every remaining sibling when the closing comment is missing', function (): void {
    $nodes = spacerFragmentNodes('<!-- wp:spacer --><div class="wp-block-spacer"></div><p>after</p>');

    $result = (new WpSpacerBlockProcessor)->process(
        spacerNodeAt($nodes, 0),
        [],
        'default',
        spacerAssetImporter(),
        new BufferedOutput
    );

    expect($result['skipCount'] ?? null)->toBe(3);
});

it('counts only the opening comment when it is the last sibling', function (): void {
    $nodes = spacerFragmentNodes('<p>before</p><!-- wp:spacer -->');

    $result = (new WpSpacerBlockProcessor)->process(
        spacerNodeAt($nodes, 1),
        [],
        'default',
        spacerAssetImporter(),
        new BufferedOutput
    );

    expect($result['skipCount'] ?? null)->toBe(1);
});

it('stops at the first closing comment rather than a later one', function (): void {
    $nodes = spacerFragmentNodes(
        '<!-- wp:spacer --><div></div><!-- /wp:spacer --><!-- wp:spacer --><div></div><!-- /wp:spacer -->'
    );

    $result = (new WpSpacerBlockProcessor)->process(
        spacerNodeAt($nodes, 0),
        [],
        'default',
        spacerAssetImporter(),
        new BufferedOutput
    );

    expect($result['skipCount'] ?? null)->toBe(3);
});

it('accepts a closing comment with surrounding whitespace but not one with trailing text', function (): void {
    $padded = spacerFragmentNodes('<!-- wp:spacer --><div></div><!--   /wp:spacer   --><p>after</p>');
    $suffixed = spacerFragmentNodes('<!-- wp:spacer --><div></div><!-- /wp:spacer extra --><p>after</p>');

    $processor = new WpSpacerBlockProcessor;

    expect($processor->process(spacerNodeAt($padded, 0), [], 'default', spacerAssetImporter(), new BufferedOutput)['skipCount'] ?? null)
        ->toBe(3);

    // The suffixed comment is not a closing comment, so the walk runs to the end
    expect($processor->process(spacerNodeAt($suffixed, 0), [], 'default', spacerAssetImporter(), new BufferedOutput)['skipCount'] ?? null)
        ->toBe(4);
});
