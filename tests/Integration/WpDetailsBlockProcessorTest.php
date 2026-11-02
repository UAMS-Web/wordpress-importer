<?php

declare(strict_types=1);

/**
 * Tests for WpDetailsBlockProcessor, which unwraps a WordPress `wp:details`
 * block and turns its `<summary>` into a heading.
 *
 * The heading sits one level below the nearest heading before the block (a
 * heading element, or a `wp:heading` comment with a `level` attribute), capped
 * at h6, and is h2 when no heading precedes the block. This is in the light
 * Statamic band because the unwrapped content goes through TextProcessor and
 * ShortcodeProcessor, which log through the `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/WpDetailsBlockProcessorTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpDetailsBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Parse a fragment and return its `wp:details` opening comment.
 *
 * @param  string  $html  The fragment, containing one `<!-- wp:details -->` comment
 * @return DOMComment The opening comment
 */
function detailsOpeningComment(string $html): DOMComment
{
    $document = new DOMDocument;

    // libxml's HTML parser warns about HTML5 tags such as <details>, so collect
    // the warnings here rather than depend on the mode an earlier test left on,
    // and put the caller's mode back afterwards
    $previousLibxmlMode = libxml_use_internal_errors(true);

    try {
        $document->loadHTML('<div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    } finally {
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlMode);
    }

    $root = $document->getElementById('root');

    if (! $root instanceof DOMElement) {
        throw new RuntimeException('Fixture root missing');
    }

    foreach ($root->childNodes as $child) {
        if ($child instanceof DOMComment && \trim((string) $child->nodeValue) === 'wp:details') {
            return $child;
        }
    }

    throw new RuntimeException('Fixture has no wp:details comment');
}

/**
 * Run the processor over the fragment's `wp:details` block.
 *
 * @param  string  $html  The fragment
 * @return array<int|string, mixed>|string|null The processor's result
 */
function processDetails(string $html): array|string|null
{
    $assetImporter = (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(AssetImporter::class, 'baseBlogUrl'))->setValue($assetImporter, 'https://example.test');

    return (new WpDetailsBlockProcessor)->process(
        detailsOpeningComment($html),
        [],
        'default',
        $assetImporter,
        new BufferedOutput
    );
}

/**
 * The block every heading-level case shares: a summary followed by a paragraph.
 */
const DETAILS_BLOCK = '<!-- wp:details --><details class="wp-block-details"><summary>Q</summary><p>A</p></details><!-- /wp:details -->';

it('recognizes a wp:details comment', function (string $comment): void {
    $node = (new DOMDocument)->createComment($comment);

    expect((new WpDetailsBlockProcessor)->canProcess($node, 'default', null))->toBeTrue();
})->with([
    'bare' => ['wp:details'],
    'with attributes' => [' wp:details {"showContent":true} '],
]);

it('rejects comments and nodes that are not an opening wp:details block', function (DOMNode $node): void {
    expect((new WpDetailsBlockProcessor)->canProcess($node, 'default', null))->toBeFalse();
})->with([
    'closing comment' => fn (): DOMNode => (new DOMDocument)->createComment('/wp:details'),
    'another block' => fn (): DOMNode => (new DOMDocument)->createComment('wp:group'),
    'element' => fn (): DOMNode => (new DOMDocument)->createElement('details'),
]);

it('unwraps the block, turns the summary into an h2, and skips the whole block', function (): void {
    $result = processDetails(DETAILS_BLOCK.'<p>after</p>');

    expect($result)->toBeArray()
        ->and($result['html'] ?? null)->toBe('<h2>Q</h2><p>A</p>')
        ->and($result['skipCount'] ?? null)->toBe(3)
        ->and($result['bardContent'] ?? null)->toBe([
            ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Q']]],
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'A']], 'attrs' => []],
        ]);
});

it('keeps the details element when it lacks the block class', function (): void {
    $result = processDetails('<!-- wp:details --><details><summary>Q</summary></details><!-- /wp:details -->');

    expect($result['html'] ?? null)->toBe('<details><h2>Q</h2></details>');
});

it('returns null for an empty block', function (): void {
    expect(processDetails('<!-- wp:details --><!-- /wp:details -->'))->toBeNull();
});

it('places the summary one level below the nearest preceding heading', function (string $before, string $expectedTag): void {
    $result = processDetails($before.DETAILS_BLOCK);

    expect($result['html'] ?? null)->toBe("<{$expectedTag}>Q</{$expectedTag}><p>A</p>");
})->with([
    'h3 element' => ['<h3>T</h3>', 'h4'],
    'h1 element' => ['<h1>T</h1>', 'h2'],
    'h5 element' => ['<h5>T</h5>', 'h6'],
    'h6 element, capped at h6' => ['<h6>T</h6>', 'h6'],
    'heading element with other elements in between' => ['<h3>T</h3><p>x</p><ul><li>y</li></ul>', 'h4'],
    'the nearest of two headings' => ['<h2>A</h2><h4>B</h4>', 'h5'],
    'wp:heading comment with a level' => ['<!-- wp:heading {"level":3} -->', 'h4'],
    'wp:heading comment with a numeric-string level' => ['<!-- wp:heading {"level":"4"} -->', 'h5'],
    'wp:heading comment with a level before a nested object' => ['<!-- wp:heading {"level":3,"style":{"color":"red"}} -->', 'h4'],
    'wp:heading comment with a level after a nested object' => ['<!-- wp:heading {"style":{"color":"red"},"level":4} -->', 'h5'],
    'wp:heading comment at level 1' => ['<!-- wp:heading {"level":1} -->', 'h2'],
    'wp:heading comment at level 6, capped at h6' => ['<!-- wp:heading {"level":6} -->', 'h6'],
    'closing heading comment is skipped for the element before it' => ['<!-- wp:heading {"level":2} --><h4>T</h4><!-- /wp:heading -->', 'h5'],
    'other comments are skipped' => ['<h3>T</h3><!-- wp:paragraph --><!-- note -->', 'h4'],
]);

it('treats a wp:heading comment without a usable level as an h2', function (string $comment): void {
    $result = processDetails($comment.DETAILS_BLOCK);

    expect($result['html'] ?? null)->toBe('<h3>Q</h3><p>A</p>');
})->with([
    'no attributes' => ['<!-- wp:heading -->'],
    'attributes without a level' => ['<!-- wp:heading {"textAlign":"center"} -->'],
    'level 0' => ['<!-- wp:heading {"level":0} -->'],
    'level 7' => ['<!-- wp:heading {"level":7} -->'],
    'non-numeric level' => ['<!-- wp:heading {"level":"big"} -->'],
    'attributes that are not JSON' => ['<!-- wp:heading {level:3} -->'],
]);

it('decodes heading attributes nested up to the JSON depth limit of 512', function (int $arrays, string $expectedTag): void {
    // Arrays can nest before the first closing brace the attribute match stops at
    $nested = \str_repeat('[', $arrays).'1'.\str_repeat(']', $arrays);
    $result = processDetails('<!-- wp:heading {"level":3,"a":'.$nested.'} -->'.DETAILS_BLOCK);

    expect($result['html'] ?? null)->toBe("<{$expectedTag}>Q</{$expectedTag}><p>A</p>");
})->with([
    // The object and 510 arrays reach depth 511, inside the limit
    '510 nested arrays decode' => [510, 'h4'],
    // The object and 511 arrays reach depth 512 plus the scalar, past the limit
    '511 nested arrays exceed the limit' => [511, 'h3'],
]);

it('stops at the first heading comment even when an element heading is further back', function (): void {
    $result = processDetails('<h5>T</h5><!-- wp:heading {"level":2} -->'.DETAILS_BLOCK);

    expect($result['html'] ?? null)->toBe('<h3>Q</h3><p>A</p>');
});

it('uses the default when no heading precedes the block', function (string $before): void {
    $result = processDetails($before.DETAILS_BLOCK);

    expect($result['html'] ?? null)->toBe('<h2>Q</h2><p>A</p>');
})->with([
    'a paragraph' => ['<p>intro</p>'],
    'an element whose name starts with h' => ['<header>x</header><hr>'],
    'a comment naming a different block' => ['<!-- wp:headings {"level":5} -->'],
    'text only' => ['plain text '],
]);

it('converts every summary in the block, keeping its content', function (): void {
    $result = processDetails(
        '<!-- wp:details --><details class="wp-block-details"><summary class="s">One <em>x</em></summary><p>A</p><summary>Two</summary></details><!-- /wp:details -->'
    );

    expect($result['html'] ?? null)->toBe('<h2>One <em>x</em></h2><p>A</p><h2>Two</h2>');
});

it('parses a fixture with libxml in either error mode, and leaves the mode as it was', function (bool $mode): void {
    $previous = libxml_use_internal_errors($mode);

    try {
        detailsOpeningComment(DETAILS_BLOCK);

        expect(libxml_use_internal_errors())->toBe($mode);
    } finally {
        libxml_use_internal_errors($previous);
    }
})->with([
    'internal errors off' => [false],
    'internal errors on' => [true],
]);
