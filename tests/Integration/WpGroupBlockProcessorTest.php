<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpGroupBlockProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for WpGroupBlockProcessor, which unwraps a `wp:group` block into its contents.
 *
 * The block's inner content goes through TextProcessor, so nested blocks are still
 * processed. When the group is aligned `wide` or `full` and holds nothing but a
 * table (a `[table]` shortcode block or a TablePress block, bare, inside a wrapper
 * div, or inside a nested group), the first table set in the Bard content is set to
 * `alignwide`. This is in the light Statamic band because TextProcessor and
 * ShortcodeProcessor log through the `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/WpGroupBlockProcessorTest.php
 */
uses(StatamicTestCase::class);

/**
 * Parse an HTML fragment and return its first top-level node.
 *
 * @param  string  $html  The fragment to parse
 * @return DOMNode The fragment's first node
 */
function groupOpeningNode(string $html): DOMNode
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $first = $document->getElementById('root')?->firstChild;

    if (! $first instanceof DOMNode) {
        throw new RuntimeException('Fixture has no nodes');
    }

    return $first;
}

/**
 * Run the processor over a fragment whose first node is the opening comment.
 *
 * @param  string  $html  The fragment
 * @param  array<int|string, mixed>  $attributes  The group's attributes
 * @return array<int|string, mixed>|string|null The processor's result
 */
function processGroup(string $html, array $attributes = []): array|string|null
{
    $assetImporter = (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(AssetImporter::class, 'baseBlogUrl'))->setValue($assetImporter, 'https://example.test');

    return (new WpGroupBlockProcessor)->process(
        groupOpeningNode($html),
        $attributes,
        'default',
        $assetImporter,
        new BufferedOutput
    );
}

/**
 * Call the processor's processBardContent() as process() would after reading the given attributes.
 *
 * @param  array<int|string, mixed>  $attributes  The group's attributes
 * @param  array<int|string, mixed>  $bardContent  The Bard content TextProcessor produced
 * @param  string  $innerContent  The group's inner content
 * @return array<int|string, mixed> The adjusted Bard content
 */
function groupBardContent(array $attributes, array $bardContent, string $innerContent): array
{
    $processor = new WpGroupBlockProcessor;
    (new ReflectionProperty(WpGroupBlockProcessor::class, 'currentAttributes'))->setValue($processor, $attributes);

    /** @var array<int|string, mixed> $result */
    $result = (new ReflectionMethod(WpGroupBlockProcessor::class, 'processBardContent'))
        ->invoke($processor, $bardContent, $innerContent, new DOMComment(' wp:group '));

    return $result;
}

/**
 * A Bard set of the given type, aligned center.
 *
 * @param  string  $type  The set type
 * @return array{type: string, attrs: array{id: string, values: array{type: string, align: string}}} The set node
 */
function groupSet(string $type): array
{
    return ['type' => 'set', 'attrs' => ['id' => 'x', 'values' => ['type' => $type, 'align' => 'aligncenter']]];
}

/**
 * The same set node with its alignment changed.
 *
 * @param  array{type: string, attrs: array{id: string, values: array{type: string, align: string}}}  $set  The set node from groupSet()
 * @param  string  $align  The new alignment
 * @return array{type: string, attrs: array{id: string, values: array{type: string, align: string}}} The changed node
 */
function groupSetAligned(array $set, string $align): array
{
    $set['attrs']['values']['align'] = $align;

    return $set;
}

const GROUP_SHORTCODE_TABLE = '<!-- wp:shortcode -->[table id=6 /]<!-- /wp:shortcode -->';

const GROUP_TABLEPRESS = '<!-- wp:tablepress/table {"id":"6"} --><p>[table id=6 /]</p><!-- /wp:tablepress/table -->';

it('accepts only a wp:group comment', function (string $html, bool $expected): void {
    expect((new WpGroupBlockProcessor)->canProcess(groupOpeningNode($html), 'default', null))->toBe($expected);
})->with([
    'bare block' => ['<!-- wp:group -->', true],
    'block with attributes' => ['<!-- wp:group {"align":"wide"} -->', true],
    'attributes over several lines' => ["<!-- wp:group {\"align\":\n\"wide\"} -->", true],
    'empty object' => ['<!-- wp:group {} -->', false],
    'closing comment' => ['<!-- /wp:group -->', false],
    'another block with the same prefix' => ['<!-- wp:groupie -->', false],
    'element, not comment' => ['<p>wp:group</p>', false],
    'text, not comment' => ['wp:group', false],
    'comment with no padding' => ['<!--wp:group-->', true],
]);

it('unwraps the group div and returns its content as Bard and as HTML', function (): void {
    $result = processGroup(
        '<!-- wp:group --><div class="wp-block-group"><p>Hello <em>there</em></p></div><!-- /wp:group --><p>after</p>'
    );

    expect($result)->toBeArray()
        ->and($result['bardContent'] ?? null)->toBe([[
            'type' => 'paragraph',
            'content' => [
                ['type' => 'text', 'text' => 'Hello '],
                ['type' => 'text', 'text' => 'there', 'marks' => [['type' => 'italic']]],
            ],
            'attrs' => [],
        ]])
        ->and($result['html'] ?? null)->toBe('<p>Hello <em>there</em></p>')
        ->and($result['skipCount'] ?? null)->toBe(3);
});

it('keeps a nested group block for TextProcessor to unwrap in turn', function (): void {
    $result = processGroup(
        '<!-- wp:group --><div class="wp-block-group"><!-- wp:group --><div class="wp-block-group"><p>In</p></div><!-- /wp:group --></div><!-- /wp:group -->'
    );

    expect($result['bardContent'] ?? null)->toBe([[
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => 'In']],
        'attrs' => [],
    ]])
        ->and($result['html'] ?? null)->toBe('<!-- wp:group --><p>In</p><!-- /wp:group -->')
        ->and($result['skipCount'] ?? null)->toBe(3);
});

it('counts a nested block beside the wrapper, and keeps its comments', function (): void {
    $result = processGroup(
        '<!-- wp:group --><!-- wp:group {"align":"wide"} --><p>In</p><!-- /wp:group --><!-- /wp:group --><p>after</p>'
    );

    expect($result['html'] ?? null)->toBe('<!-- wp:group {"align":"wide"} --><p>In</p><!-- /wp:group -->')
        ->and($result['skipCount'] ?? null)->toBe(5);
});

it('reads a closing comment with no padding', function (): void {
    $result = processGroup('<!-- wp:group --><p>In</p><!--/wp:group--><p>after</p>');

    expect($result['html'] ?? null)->toBe('<p>In</p>')
        ->and($result['skipCount'] ?? null)->toBe(3);
});

it('leaves another block\'s comments out of the depth count', function (): void {
    $result = processGroup('<!-- wp:group --><!-- wp:groupie --><!-- /wp:groupie --><p>In</p><!-- /wp:group --><p>after</p>');

    expect($result['html'] ?? null)->toBe('<!-- wp:groupie --><!-- /wp:groupie --><p>In</p>')
        ->and($result['skipCount'] ?? null)->toBe(5);
});

it('counts to the last sibling when the block never closes', function (): void {
    $result = processGroup('<!-- wp:group --><p>One</p><p>Two</p>');

    expect($result['html'] ?? null)->toBe('<p>One</p><p>Two</p>')
        ->and($result['skipCount'] ?? null)->toBe(3);
});

it('keeps a text node with its own whitespace, and drops one of only whitespace', function (): void {
    $result = processGroup('<!-- wp:group --> Lead <p>In</p>  <!-- /wp:group -->');

    expect($result['html'] ?? null)->toBe(' Lead <p>In</p>');
});

it('unwraps only an element of the wrapper name with a class beginning with the prefix', function (string $wrapper, string $html): void {
    expect(processGroup('<!-- wp:group -->'.$wrapper.'<!-- /wp:group -->')['html'] ?? null)->toBe($html);
})->with([
    'the prefix as the second class' => ['<div class="extra wp-block-group"><p>In</p></div>', '<p>In</p>'],
    'a padded class attribute' => ['<div class="  wp-block-group  "><p>In</p></div>', '<p>In</p>'],
    'a longer class with the prefix' => ['<div class="wp-block-group__inner-container"><p>In</p></div>', '<p>In</p>'],
    'the prefix at the end of a class' => ['<div class="my-wp-block-group"><p>In</p></div>', '<div class="my-wp-block-group"><p>In</p></div>'],
    'another element' => ['<section class="wp-block-group"><p>In</p></section>', '<section class="wp-block-group"><p>In</p></section>'],
    'no class' => ['<div><p>In</p></div>', '<div><p>In</p></div>'],
    'an empty class' => ['<div class=""><p>In</p></div>', '<div class=""><p>In</p></div>'],
]);

it('reads each kind of child in a wrapper', function (): void {
    $result = processGroup(
        '<!-- wp:group --><div class="wp-block-group"> Lead <!-- note -->  <div class="wp-block-group"><p>In</p></div></div><!-- /wp:group -->'
    );

    expect($result['html'] ?? null)->toBe(' Lead <!-- note --><p>In</p>');
});

it('returns null for a group with no content', function (): void {
    expect(processGroup('<!-- wp:group --><div class="wp-block-group">  </div><!-- /wp:group -->'))->toBeNull();
});

it('widens a table shortcode in a wide or full group, and only there', function (array $attributes, string $align): void {
    $result = processGroup(
        '<!-- wp:group --><div class="wp-block-group">'.GROUP_SHORTCODE_TABLE.'</div><!-- /wp:group -->',
        $attributes
    );

    $set = data_get($result, 'bardContent.0');

    expect(data_get($set, 'type'))->toBe('set')
        ->and(data_get($set, 'attrs.values.type'))->toBe('table')
        ->and(data_get($set, 'attrs.values.align'))->toBe($align);
})->with([
    'wide' => [['align' => 'wide'], 'alignwide'],
    'full' => [['align' => 'full'], 'alignwide'],
    'center' => [['align' => 'center'], 'aligncenter'],
    'no align' => [[], 'aligncenter'],
]);

it('treats only a lone table as table-only content', function (string $innerContent, bool $widened): void {
    $table = groupSet('table');

    expect(groupBardContent(['align' => 'wide'], [$table], $innerContent))
        ->toBe([$widened ? groupSetAligned($table, 'alignwide') : $table]);
})->with([
    'shortcode block' => [GROUP_SHORTCODE_TABLE, true],
    'shortcode block with surrounding whitespace' => ["\n  ".GROUP_SHORTCODE_TABLE."  \n", true],
    'shortcode without the closing slash' => ['<!-- wp:shortcode -->[table id=6]<!-- /wp:shortcode -->', true],
    'shortcode whose id is not a number' => ['<!-- wp:shortcode -->[table id=abc /]<!-- /wp:shortcode -->', false],
    'shortcode block followed by more content' => [GROUP_SHORTCODE_TABLE.'<p>more</p>', false],
    'content before the shortcode block' => ['<p>more</p>'.GROUP_SHORTCODE_TABLE, false],
    'another shortcode' => ['<!-- wp:shortcode -->[gallery ids="1"]<!-- /wp:shortcode -->', false],
    'TablePress block' => [GROUP_TABLEPRESS, true],
    'TablePress block without attributes' => ['<!-- wp:tablepress/table --><p>x</p><!-- /wp:tablepress/table -->', true],
    'shortcode block in a wrapper div' => ['<div class="inner">'.GROUP_SHORTCODE_TABLE.'</div>', true],
    'TablePress block in a wrapper div' => ['<div class="inner">'.GROUP_TABLEPRESS.'</div>', true],
    'shortcode block in a nested group' => ['<!-- wp:group --><div class="wp-block-group">'.GROUP_SHORTCODE_TABLE.'</div><!-- /wp:group -->', true],
    'TablePress block in a nested group with attributes' => ['<!-- wp:group {"layout":{"type":"constrained"}} --> <div class="wp-block-group">'.GROUP_TABLEPRESS.'</div> <!-- /wp:group -->', true],
    'paragraph in a nested group' => ['<!-- wp:group --><div class="wp-block-group"><p>no table</p></div><!-- /wp:group -->', false],
    'nested group in a wrapper div' => ['<div class="outer"><!-- wp:group --><div class="wp-block-group">'.GROUP_TABLEPRESS.'</div><!-- /wp:group --></div>', true],
    'paragraph in a nested group in a wrapper div' => ['<div class="outer"><!-- wp:group --><div class="wp-block-group"><p>no</p></div><!-- /wp:group --></div>', false],
    'paragraph' => ['<p>[table id=6 /]</p>', false],
]);

it('widens only for a wide or full group', function (mixed $align, bool $widened): void {
    $table = groupSet('table');

    expect(groupBardContent(['align' => $align], [$table], GROUP_SHORTCODE_TABLE))
        ->toBe([$widened ? groupSetAligned($table, 'alignwide') : $table]);
})->with([
    'wide' => ['wide', true],
    'full' => ['full', true],
    'center' => ['center', false],
    'null' => [null, false],
]);

it('widens the first table set and leaves everything else alone', function (): void {
    $image = groupSet('image');
    $first = groupSet('table');
    $second = groupSet('table');
    $paragraph = ['type' => 'paragraph', 'content' => []];

    expect(groupBardContent(['align' => 'full'], ['not a node', $paragraph, $image, $first, $second], GROUP_TABLEPRESS))
        ->toBe(['not a node', $paragraph, $image, groupSetAligned($first, 'alignwide'), $second]);
});

it('ignores nodes that are not table sets', function (array $node): void {
    expect(groupBardContent(['align' => 'wide'], [$node], GROUP_SHORTCODE_TABLE))->toBe([$node]);
})->with([
    'a set with no type' => [['type' => 'set', 'attrs' => ['values' => ['align' => 'aligncenter']]]],
    'a table that is not a set' => [['type' => 'table', 'attrs' => ['values' => ['type' => 'table', 'align' => 'aligncenter']]]],
    'a node with no type' => [['attrs' => ['values' => ['type' => 'table', 'align' => 'aligncenter']]]],
    'attributes that are not an array' => [['type' => 'set', 'attrs' => 'table']],
    'a set with no attributes' => [['type' => 'set']],
    'values that are not an array' => [['type' => 'set', 'attrs' => ['values' => 'table']]],
    'a set with no values' => [['type' => 'set', 'attrs' => []]],
]);

it('returns empty Bard content unchanged', function (): void {
    expect(groupBardContent(['align' => 'wide'], [], GROUP_SHORTCODE_TABLE))->toBe([]);
});
