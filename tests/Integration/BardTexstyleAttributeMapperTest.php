<?php

declare(strict_types=1);

/**
 * Tests that `BardTexstyleAttributeMapper` copies the HTML attributes Bard Texstyle
 * knows about (clearboth, id, start, reversed, list_style, and a link's target,
 * title and rel) from the original HTML onto the matching Bard nodes, reading them
 * from the elements themselves and from the Gutenberg block comments before them.
 *
 * A Bard node is matched to an element by its tag, its heading level and its text,
 * so each test builds the Bard content for the HTML it passes in. The attribute
 * keys each node type accepts come from `statamic.bard_texstyle.attributes`, which
 * the host app configures; `texstyleConfigureAttributes()` sets the same keys, read
 * from `uams-statamic`'s `config/statamic/bard_texstyle.php` (the values are not
 * read, so they are left empty).
 *
 * @command  php vendor/bin/pest tests/Integration/BardTexstyleAttributeMapperTest.php
 */

use Statamic\Fieldtypes\Bard\LinkMark;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BardTexstyleAttributeMapper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Configure the Texstyle attribute keys the host app defines for each node type.
 */
function texstyleConfigureAttributes(): void
{
    config()->set('statamic.bard_texstyle.attributes', [
        'heading' => ['clearboth' => [], 'id' => []],
        'paragraph' => ['id' => []],
        'unordered_list' => ['id' => []],
        'ordered_list' => ['start' => [], 'reversed' => [], 'list_style' => [], 'id' => []],
        'blockquote' => ['id' => []],
        'code_block' => ['id' => []],
        'horizontal_rule' => ['clearboth' => [], 'id' => []],
        'image' => ['id' => []],
        'list_item' => ['id' => []],
    ]);
}

/**
 * A Bard text node.
 *
 * @param  list<mixed>  $marks
 * @return array<string, mixed>
 */
function texstyleText(string $text, array $marks = []): array
{
    return $marks === [] ? ['type' => 'text', 'text' => $text] : ['type' => 'text', 'marks' => $marks, 'text' => $text];
}

/**
 * A Bard node of the given type holding the given children.
 *
 * @param  array<string, mixed>  $attrs
 * @param  list<mixed>  $content
 * @return array<string, mixed>
 */
function texstyleNode(string $type, array $attrs, array $content = []): array
{
    $node = ['type' => $type];

    if ($attrs !== []) {
        $node['attrs'] = $attrs;
    }

    if ($content !== []) {
        $node['content'] = $content;
    }

    return $node;
}

/**
 * Map one Bard node against the HTML and return the attrs it ends with.
 *
 * @param  array<int|string, mixed>  $node
 */
function texstyleAttrs(array $node, string $html): mixed
{
    $mapped = BardTexstyleAttributeMapper::mapAttributes([$node], $html);
    $first = $mapped[0] ?? null;

    return is_array($first) ? ($first['attrs'] ?? null) : null;
}

/**
 * The value at the given path of nested arrays, or null where the path ends early.
 *
 * @param  list<int|string>  $path
 */
function texstylePath(mixed $value, array $path): mixed
{
    foreach ($path as $key) {
        if (! is_array($value) || ! array_key_exists($key, $value)) {
            return null;
        }

        $value = $value[$key];
    }

    return $value;
}

/**
 * The attribute keys Statamic's link mark defines.
 *
 * @return list<int|string>
 */
function texstyleLinkMarkKeys(): array
{
    $attributes = (new LinkMark)->addAttributes();

    return is_array($attributes) ? array_keys($attributes) : [];
}

/**
 * An ordered list holding one item with the given text.
 *
 * @return array<string, mixed>
 */
function texstyleOrderedList(string $text = 'One'): array
{
    return texstyleNode('orderedList', [], [
        texstyleNode('listItem', [], [texstyleNode('paragraph', [], [texstyleText($text)])]),
    ]);
}

beforeEach(function (): void {
    texstyleConfigureAttributes();
});

it('returns empty content unchanged', function (): void {
    expect(BardTexstyleAttributeMapper::mapAttributes([], '<h2 id="a">A</h2>'))->toBe([]);
});

it('marks a heading clearboth from a clear class or a clear: both style', function (string $html): void {
    $heading = texstyleNode('heading', ['level' => 2], [texstyleText('Title')]);

    expect(texstyleAttrs($heading, $html))->toBe(['level' => 2, 'clearboth' => 'clearboth', 'id' => null]);
})->with([
    'clear' => ['<h2 class="clear">Title</h2>'],
    'clear-both' => ['<h2 class="clear-both">Title</h2>'],
    'clearboth' => ['<h2 class="clearboth">Title</h2>'],
    'among other classes' => ['<h2 class="  big   clear ">Title</h2>'],
    'clear: both' => ['<h2 style="color: red; clear: both;">Title</h2>'],
    'clear:both, any case' => ['<h2 style="CLEAR:BOTH">Title</h2>'],
    'spread over lines' => ["<h2 style=\"clear\n  :\n  both\">Title</h2>"],
    'a class that does not match, then a style' => ['<h2 class="big" style="clear: both">Title</h2>'],
]);

it('leaves a heading without a clear class or style unmarked', function (string $html): void {
    $heading = texstyleNode('heading', ['level' => 2], [texstyleText('Title')]);

    expect(texstyleAttrs($heading, $html))->toBe(['level' => 2, 'clearboth' => null, 'id' => null]);
})->with([
    'no class' => ['<h2>Title</h2>'],
    'clearfix' => ['<h2 class="clearfix">Title</h2>'],
    'clear: left' => ['<h2 style="clear: left">Title</h2>'],
    'unclear: both' => ['<h2 style="unclear: both">Title</h2>'],
    'clear: bothways' => ['<h2 style="clear: bothways">Title</h2>'],
]);

it('marks a horizontal rule clearboth', function (): void {
    expect(texstyleAttrs(texstyleNode('horizontalRule', []), '<p>A</p><hr class="clear">'))
        ->toBe(['clearboth' => 'clearboth', 'id' => null]);
});

it('copies an element id onto the matching node', function (array $node, string $html, array $attrs): void {
    expect(texstyleAttrs($node, $html))->toBe($attrs);
})->with([
    'heading' => [texstyleNode('heading', ['level' => 3], [texstyleText('Hi')]), '<h3 id="top">Hi</h3>', ['level' => 3, 'clearboth' => null, 'id' => 'top']],
    'paragraph' => [texstyleNode('paragraph', [], [texstyleText('Hi')]), '<p id="intro">Hi</p>', ['id' => 'intro']],
    'unordered list, under the name the mapper uses' => [texstyleNode('unorderedList', [], []), '<ul id="u"></ul>', ['id' => 'u']],
    'ordered list' => [texstyleNode('orderedList', [], []), '<ol id="o"></ol>', ['start' => null, 'reversed' => null, 'list_style' => null, 'id' => 'o']],
    'code block' => [texstyleNode('codeBlock', [], [texstyleText('x = 1')]), '<pre id="code">x = 1</pre>', ['id' => 'code']],
    'horizontal rule' => [texstyleNode('horizontalRule', []), '<hr id="rule">', ['clearboth' => null, 'id' => 'rule']],
    'list item' => [texstyleNode('listItem', [], [texstyleText('Li')]), '<ul><li id="item">Li</li></ul>', ['id' => 'item']],
    'an empty id' => [texstyleNode('paragraph', [], [texstyleText('Hi')]), '<p id="">Hi</p>', ['id' => null]],
]);

it('drops the id of a bullet list, a blockquote and an image', function (array $node, string $html, array $attrs): void {
    // Characterization of #149: Bard names an unordered list `bulletList`, which the
    // mapper does not know, and it reads no id from a blockquote or an image
    expect(texstyleAttrs($node, $html))->toBe($attrs);
})->with([
    'bullet list' => [texstyleNode('bulletList', [], []), '<ul id="u"></ul>', []],
    'blockquote' => [texstyleNode('blockquote', [], [texstyleText('Q')]), '<blockquote id="q">Q</blockquote>', ['id' => null]],
    'image' => [texstyleNode('image', ['src' => 'a.jpg']), '<img id="i" src="a.jpg">', ['src' => 'a.jpg', 'id' => null]],
]);

it('matches a heading to an element of the same level and text only', function (string $html, ?string $id): void {
    $heading = texstyleNode('heading', ['level' => 2], [texstyleText('One '), texstyleText('two')]);

    expect(texstyleAttrs($heading, $html))->toBe(['level' => 2, 'clearboth' => null, 'id' => $id]);
})->with([
    'same level, text spread over children and whitespace' => ["<h2 id=\"a\">  One\n   <em>two</em> </h2>", 'a'],
    'uppercase tag' => ['<H2 id="a">One two</H2>', 'a'],
    'another level' => ['<h3 id="a">One two</h3>', null],
    'other text' => ['<h2 id="a">One three</h2>', null],
    'a paragraph with the same text' => ['<p id="a">One two</p>', null],
]);

it('matches a heading without a level to no element', function (mixed $level): void {
    $heading = ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [texstyleText('T')]];

    expect(texstyleAttrs($heading, '<h2 id="a">T</h2><h id="b">T</h>'))->toBe(['level' => $level, 'clearboth' => null, 'id' => null]);
})->with([
    'null' => [null],
    'an array' => [[2]],
]);

it('matches a heading whose level is a string', function (): void {
    $heading = ['type' => 'heading', 'attrs' => ['level' => '2'], 'content' => [texstyleText('T')]];

    expect(texstyleAttrs($heading, '<h2 id="a">T</h2>'))->toBe(['level' => '2', 'clearboth' => null, 'id' => 'a']);
});

it('reads an ordered list start, reversed and list style', function (string $html, array $attrs): void {
    expect(texstyleAttrs(texstyleOrderedList(), $html))->toBe([...['start' => null, 'reversed' => null, 'list_style' => null, 'id' => null], ...$attrs]);
})->with([
    'start' => ['<ol start="3"><li>One</li></ol>', ['start' => 3]],
    'start 1' => ['<ol start="1"><li>One</li></ol>', ['start' => 1]],
    'start 0' => ['<ol start="0"><li>One</li></ol>', []],
    'a negative start' => ['<ol start="-2"><li>One</li></ol>', []],
    'a start that is not a number' => ['<ol start="x"><li>One</li></ol>', []],
    'reversed with no value' => ['<ol reversed><li>One</li></ol>', ['reversed' => true]],
    'reversed="reversed"' => ['<ol reversed="REVERSED"><li>One</li></ol>', ['reversed' => true]],
    'reversed="false"' => ['<ol reversed="false"><li>One</li></ol>', []],
    'a list style class' => ['<ol class="big upper-roman"><li>One</li></ol>', ['list_style' => 'upper-roman']],
    'the first list style class' => ['<ol class="outline decimal"><li>One</li></ol>', ['list_style' => 'outline']],
    'a list style class before a style' => ['<ol class="lower-alpha" style="list-style-type: decimal"><li>One</li></ol>', ['list_style' => 'lower-alpha']],
    'list-style-type' => ['<ol style="color: red; list-style-type: lower-roman"><li>One</li></ol>', ['list_style' => 'lower-roman']],
    'list-style-type, any case and spacing' => ["<ol style=\"LIST-STYLE-TYPE:\n Upper-Alpha\"><li>One</li></ol>", ['list_style' => 'upper-alpha']],
    'list-style with a position' => ['<ol style="list-style: upper-alpha inside;"><li>One</li></ol>', ['list_style' => 'upper-alpha']],
    'list-style alone' => ['<ol style="list-style:decimal"><li>One</li></ol>', ['list_style' => 'decimal']],
    'list-style then another rule' => ['<ol style="list-style: outline; color: red"><li>One</li></ol>', ['list_style' => 'outline']],
    'an unknown class and style' => ['<ol class="square" style="list-style-type: square"><li>One</li></ol>', []],
    'list-style with more words after it' => ['<ol style="list-style: lower-roman inside foo"><li>One</li></ol>', ['list_style' => 'lower-roman']],
    'list-style with a longer value' => ['<ol style="list-style: decimal-leading-zero"><li>One</li></ol>', []],
    'list-style-type with a longer value, matched by its prefix' => ['<ol style="list-style-type: decimal-leading-zero"><li>One</li></ol>', ['list_style' => 'decimal']],
    'everything' => ['<ol id="o" start="4" reversed class="lower-alpha"><li>One</li></ol>', ['start' => 4, 'reversed' => true, 'list_style' => 'lower-alpha', 'id' => 'o']],
]);

it('reads nothing an ordered list carries from an unordered one', function (): void {
    $list = texstyleNode('unorderedList', [], [texstyleNode('listItem', [], [texstyleText('One')])]);

    expect(texstyleAttrs($list, '<ul start="3" reversed class="clear upper-roman"><li>One</li></ul>'))->toBe(['id' => null]);
});

it('reads the attributes of a Gutenberg block comment onto the element after it', function (array $node, string $html, array $attrs): void {
    expect(texstyleAttrs($node, $html))->toBe($attrs);
})->with([
    'a heading className' => [
        texstyleNode('heading', ['level' => 2], [texstyleText('T')]),
        '<!-- wp:heading {"className":"big clear"} --><h2>T</h2><!-- /wp:heading -->',
        ['level' => 2, 'clearboth' => 'clearboth', 'id' => null],
    ],
    'a heading className clear-both' => [
        texstyleNode('heading', ['level' => 2], [texstyleText('T')]),
        '<!-- wp:heading {"className":"clear-both"} --><h2>T</h2><!-- /wp:heading -->',
        ['level' => 2, 'clearboth' => 'clearboth', 'id' => null],
    ],
    'a heading className with a NUL before it' => [
        texstyleNode('heading', ['level' => 2], [texstyleText('T')]),
        '<!-- wp:heading {"className":"\\u0000clear"} --><h2>T</h2><!-- /wp:heading -->',
        ['level' => 2, 'clearboth' => 'clearboth', 'id' => null],
    ],
    'a separator className' => [
        texstyleNode('horizontalRule', []),
        '<!-- wp:separator {"className":"clearboth"} --><hr><!-- /wp:separator -->',
        ['clearboth' => 'clearboth', 'id' => null],
    ],
    'a className with no clear class' => [
        texstyleNode('heading', ['level' => 2], [texstyleText('T')]),
        '<!-- wp:heading {"className":"big"} --><h2>T</h2><!-- /wp:heading -->',
        ['level' => 2, 'clearboth' => null, 'id' => null],
    ],
    'a list' => [
        texstyleOrderedList(),
        '<!-- wp:list {"ordered":true,"reversed":true,"start":5,"className":"is-style-x lower-roman upper-alpha"} --><ol><li>One</li></ol><!-- /wp:list -->',
        ['start' => 5, 'reversed' => true, 'list_style' => 'lower-roman', 'id' => null],
    ],
    'a list start of 1' => [
        texstyleOrderedList(),
        '<!-- wp:list {"ordered":true,"start":1} --><ol><li>One</li></ol><!-- /wp:list -->',
        ['start' => 1, 'reversed' => null, 'list_style' => null, 'id' => null],
    ],
    'a list start that is a fraction' => [
        texstyleOrderedList(),
        '<!-- wp:list {"ordered":true,"start":2.5} --><ol><li>One</li></ol><!-- /wp:list -->',
        ['start' => null, 'reversed' => null, 'list_style' => null, 'id' => null],
    ],
    'a list start given as a string' => [
        texstyleOrderedList(),
        '<!-- wp:list {"ordered":true,"start":"4"} --><ol><li>One</li></ol><!-- /wp:list -->',
        ['start' => 4, 'reversed' => null, 'list_style' => null, 'id' => null],
    ],
    'a list start, reversed and className that do not apply' => [
        texstyleOrderedList(),
        '<!-- wp:list {"ordered":true,"reversed":false,"start":0,"className":"clear"} --><ol><li>One</li></ol><!-- /wp:list -->',
        ['start' => null, 'reversed' => null, 'list_style' => null, 'id' => null],
    ],
    'a list start that is not a number' => [
        texstyleOrderedList(),
        '<!-- wp:list {"ordered":true,"start":"x"} --><ol start="2"><li>One</li></ol><!-- /wp:list -->',
        ['start' => 2, 'reversed' => null, 'list_style' => null, 'id' => null],
    ],
    'a list start that is neither a number nor a string' => [
        texstyleOrderedList(),
        '<!-- wp:list {"ordered":true,"start":[3]} --><ol><li>One</li></ol><!-- /wp:list -->',
        ['start' => null, 'reversed' => null, 'list_style' => null, 'id' => null],
    ],
    'the comment over the element' => [
        texstyleOrderedList(),
        '<!-- wp:list {"ordered":true,"start":7,"className":"outline"} --><ol id="o" start="2" class="decimal"><li>One</li></ol><!-- /wp:list -->',
        ['start' => 7, 'reversed' => null, 'list_style' => 'outline', 'id' => 'o'],
    ],
    'reversed and start on an element that is not a list' => [
        texstyleNode('heading', ['level' => 2], [texstyleText('T')]),
        '<!-- wp:heading {"reversed":true,"start":3,"className":"upper-roman"} --><h2>T</h2><!-- /wp:heading -->',
        ['level' => 2, 'clearboth' => null, 'id' => null],
    ],
    'clear on a list' => [
        texstyleOrderedList(),
        '<!-- wp:list {"className":"clear"} --><ol><li>One</li></ol><!-- /wp:list -->',
        ['start' => null, 'reversed' => null, 'list_style' => null, 'id' => null],
    ],
    'an element past text after the comment' => [
        texstyleNode('heading', ['level' => 2], [texstyleText('T')]),
        "<div><!-- wp:heading {\"className\":\"clear\"} -->\nloose text<h2>T</h2></div>",
        ['level' => 2, 'clearboth' => 'clearboth', 'id' => null],
    ],
    'a className that is not a string' => [
        texstyleNode('heading', ['level' => 2], [texstyleText('T')]),
        '<!-- wp:heading {"className":["clear"]} --><h2>T</h2><!-- /wp:heading -->',
        ['level' => 2, 'clearboth' => null, 'id' => null],
    ],
    'a comment with no attributes' => [
        texstyleNode('heading', ['level' => 2], [texstyleText('T')]),
        '<!-- wp:heading --><h2 class="clear">T</h2><!-- /wp:heading -->',
        ['level' => 2, 'clearboth' => 'clearboth', 'id' => null],
    ],
    'a comment that is not a block' => [
        texstyleNode('heading', ['level' => 2], [texstyleText('T')]),
        '<!-- note {"className":"clear"} --><h2>T</h2>',
        ['level' => 2, 'clearboth' => null, 'id' => null],
    ],
]);

it('ignores a block comment with nothing after it', function (): void {
    $heading = texstyleNode('heading', ['level' => 2], [texstyleText('T')]);

    expect(texstyleAttrs($heading, '<h2>T</h2><!-- wp:heading {"className":"clear"} -->'))
        ->toBe(['level' => 2, 'clearboth' => null, 'id' => null]);
});

it('copies a link\'s target, title and rel onto its link mark', function (): void {
    $html = '<p>Read <a href="/a" target="_blank" title="A page" rel="noopener">the  page</a></p>';
    $mark = ['type' => 'link', 'attrs' => ['href' => '/a']];
    $content = [texstyleNode('paragraph', [], [texstyleText('Read '), texstyleText('the page', [$mark])])];

    $mapped = BardTexstyleAttributeMapper::mapAttributes($content, $html);

    expect($mapped)->toBe([[
        'type' => 'paragraph',
        'content' => [
            ['type' => 'text', 'text' => 'Read '],
            ['type' => 'text', 'marks' => [['type' => 'link', 'attrs' => ['href' => '/a', 'target' => '_blank', 'title' => 'A page', 'rel' => 'noopener']]], 'text' => 'the page'],
        ],
        'attrs' => ['id' => null],
    ]]);
});

it('copies link attributes onto a top-level text node', function (string $html, array $attrs): void {
    $mark = ['type' => 'link', 'attrs' => ['href' => '/a', 'target' => '_self']];

    $mapped = BardTexstyleAttributeMapper::mapAttributes([texstyleText('Go', [$mark])], $html);

    expect($mapped)->toBe([[
        'type' => 'text',
        'marks' => [['type' => 'link', 'attrs' => ['href' => '/a', ...$attrs]]],
        'text' => 'Go',
        'attrs' => [],
    ]]);
})->with([
    'every attribute' => ['<a href="/a" target="_blank" title="T" rel="nofollow">Go</a>', ['target' => '_blank', 'title' => 'T', 'rel' => 'nofollow']],
    'only a title' => ['<a href="/a" title="T">Go</a>', ['target' => '_self', 'title' => 'T', 'rel' => null]],
    'only a rel' => ['<a href="/a" rel="nofollow">Go</a>', ['target' => '_self', 'title' => null, 'rel' => 'nofollow']],
    'none' => ['<a href="/a">Go</a>', ['target' => '_self', 'title' => null, 'rel' => null]],
    'another href' => ['<a href="/b" target="_blank">Go</a>', ['target' => '_self', 'title' => null, 'rel' => null]],
    'other text' => ['<a href="/a" target="_blank">Went</a>', ['target' => '_self', 'title' => null, 'rel' => null]],
]);

it('fills a link mark\'s missing attrs and leaves other marks alone', function (mixed $markAttrs, array $attrs): void {
    $marks = ['bold', ['type' => 'bold'], ['type' => 'link', 'attrs' => $markAttrs]];

    $mapped = BardTexstyleAttributeMapper::mapAttributes(
        [texstyleNode('paragraph', [], [texstyleText('Go', $marks)]), texstyleText('Go', $marks)],
        '<a href="/a" target="_blank">Go</a>'
    );

    $expectedMarks = ['bold', ['type' => 'bold'], ['type' => 'link', 'attrs' => $attrs]];

    expect($mapped)->toBe([
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'marks' => $expectedMarks, 'text' => 'Go']], 'attrs' => ['id' => null]],
        ['type' => 'text', 'marks' => $expectedMarks, 'text' => 'Go', 'attrs' => []],
    ]);
})->with([
    'no attrs' => [null, ['href' => null, 'target' => null, 'title' => null, 'rel' => null]],
    'an href that is not a string' => [['href' => 5], ['href' => 5, 'target' => null, 'title' => null, 'rel' => null]],
    'a matching href' => [['href' => '/a'], ['href' => '/a', 'target' => '_blank', 'title' => null, 'rel' => null]],
]);

it('matches a link mark on a text node whose text is not a string as empty text', function (): void {
    $mark = ['type' => 'link', 'attrs' => ['href' => '/a']];
    $node = ['type' => 'paragraph', 'content' => [['type' => 'text', 'marks' => [$mark], 'text' => ['x']]]];

    $mapped = BardTexstyleAttributeMapper::mapAttributes([$node], '<a href="/a" target="_blank"></a>');

    expect(texstylePath($mapped, [0, 'content', 0, 'marks', 0, 'attrs']))
        ->toBe(['href' => '/a', 'target' => '_blank', 'title' => null, 'rel' => null]);
});

it('maps nested nodes and leaves hard breaks and scalars alone', function (): void {
    $content = [
        'loose',
        ['attrs' => ['id' => 'x']],
        ['type' => 5],
        texstyleNode('orderedList', [], [
            'loose',
            ['type' => 'hardBreak'],
            texstyleNode('listItem', [], [texstyleNode('paragraph', [], [texstyleText('One')])]),
            texstyleNode('listItem', [], [texstyleNode('paragraph', [], [texstyleText('Two')])]),
        ]),
    ];

    $mapped = BardTexstyleAttributeMapper::mapAttributes($content, '<ol id="o"><li id="a">One</li><li>Two</li></ol>');

    expect($mapped)->toBe([
        'loose',
        ['attrs' => ['id' => 'x']],
        ['type' => 5],
        [
            'type' => 'orderedList',
            'content' => [
                'loose',
                ['type' => 'hardBreak'],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'One']], 'attrs' => ['id' => null]]], 'attrs' => ['id' => 'a']],
                ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Two']], 'attrs' => ['id' => null]]], 'attrs' => ['id' => null]],
            ],
            'attrs' => ['start' => null, 'reversed' => null, 'list_style' => null, 'id' => 'o'],
        ],
    ]);
});

it('keeps attrs a node already has, and replaces attrs that are not an array', function (): void {
    $kept = texstyleNode('paragraph', ['id' => 'keep', 'extra' => 1], [texstyleText('Hi')]);
    $replaced = ['type' => 'paragraph', 'attrs' => 'bad', 'content' => [texstyleText('Hi')]];

    expect(texstyleAttrs($kept, '<p>Hi</p>'))->toBe(['id' => 'keep', 'extra' => 1])
        ->and(texstyleAttrs($replaced, '<p>Hi</p>'))->toBe(['id' => null]);
});

it('gives every node of the same tag and text the last matching element\'s attributes', function (): void {
    $paragraph = texstyleNode('paragraph', [], [texstyleText('Same')]);

    $mapped = BardTexstyleAttributeMapper::mapAttributes([$paragraph, $paragraph], '<p id="first">Same</p><p id="second">Same</p>');

    expect(array_column(array_column($mapped, 'attrs'), 'id'))->toBe(['second', 'second']);
});

it('applies nothing to a node type the config does not list', function (mixed $config): void {
    config()->set('statamic.bard_texstyle.attributes', $config);

    expect(texstyleAttrs(texstyleNode('paragraph', [], [texstyleText('Hi')]), '<p id="a">Hi</p>'))->toBe([]);
})->with([
    'no paragraph entry' => [['heading' => ['id' => []]]],
    'a paragraph entry that is not an array' => [['paragraph' => 'id']],
    'a config that is not an array' => ['none'],
    'a config that only behaves like an array' => [new ArrayObject(['paragraph' => ['id' => []]])],
]);

it('applies only the string keys the config lists', function (): void {
    config()->set('statamic.bard_texstyle.attributes', ['paragraph' => ['id' => [], 0 => []]]);

    expect(texstyleAttrs(texstyleNode('paragraph', [], [texstyleText('Hi')]), '<p id="a">Hi</p>'))->toBe(['id' => 'a']);
});

it('applies only the attributes the node type accepts', function (): void {
    config()->set('statamic.bard_texstyle.attributes', ['ordered_list' => ['start' => []]]);

    expect(texstyleAttrs(texstyleOrderedList(), '<ol id="o" start="3" reversed><li>One</li></ol>'))->toBe(['start' => 3]);
});

it('maps nothing from HTML that is only text', function (): void {
    expect(texstyleAttrs(texstyleNode('paragraph', [], [texstyleText('Hi')]), 'Hi'))->toBe(['id' => null]);
});

it('matches a link whose text has whitespace around it', function (): void {
    $mark = ['type' => 'link', 'attrs' => ['href' => '/a']];

    $mapped = BardTexstyleAttributeMapper::mapAttributes([texstyleText('Go', [$mark])], "<a href=\"/a\" target=\"_blank\">\n  Go  </a>");

    expect(texstylePath($mapped, [0, 'marks', 0, 'attrs', 'target']))->toBe('_blank');
});

it('matches a link mark on a text node with no text to a link with none', function (): void {
    $mark = ['type' => 'link', 'attrs' => ['href' => '/a']];

    $mapped = BardTexstyleAttributeMapper::mapAttributes([['type' => 'text', 'marks' => [$mark]]], '<a href="/a" target="_blank"></a>');

    expect(texstylePath($mapped, [0, 'marks', 0, 'attrs', 'target']))->toBe('_blank');
});

it('reads link marks only on text nodes', function (): void {
    $mark = ['type' => 'link', 'attrs' => ['href' => '/a']];
    $node = ['type' => 'paragraph', 'marks' => [$mark], 'content' => [texstyleText('Go')]];

    $mapped = BardTexstyleAttributeMapper::mapAttributes([$node], '<p><a href="/a" target="_blank">Go</a></p>');

    expect(texstylePath($mapped, [0, 'marks']))->toBe([$mark]);
});

it('replaces link mark attrs that are not an array', function (): void {
    $mark = ['type' => 'link', 'attrs' => 'bad'];
    $empty = ['href' => null, 'target' => null, 'title' => null, 'rel' => null];

    $mapped = BardTexstyleAttributeMapper::mapAttributes(
        [texstyleText('Go', [$mark]), texstyleNode('paragraph', [], [texstyleText('Go', [$mark])])],
        '<p>Go</p>'
    );

    expect(texstylePath($mapped, [0, 'marks', 0, 'attrs']))->toBe($empty)
        ->and(texstylePath($mapped, [1, 'content', 0, 'marks', 0, 'attrs']))->toBe($empty);
});

it('lists the attributes Statamic defines for a link mark', function (): void {
    $mapped = BardTexstyleAttributeMapper::mapAttributes([texstyleText('Go', [['type' => 'link']])], '');
    $attrs = texstylePath($mapped, [0, 'marks', 0, 'attrs']);

    expect(is_array($attrs) ? array_keys($attrs) : null)->toBe(texstyleLinkMarkKeys());
});

it('reads a heading without a level key without a warning', function (): void {
    $heading = ['type' => 'heading', 'attrs' => [], 'content' => [texstyleText('T')]];

    // The host app turns a warning into an exception, so one counts as a failure
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $attrs = texstyleAttrs($heading, '<h2 id="a">T</h2>');
    } finally {
        restore_error_handler();
    }

    expect($attrs)->toBe(['clearboth' => null, 'id' => null]);
});

it('matches a paragraph only to a paragraph, not to a list with the same text', function (): void {
    $paragraph = texstyleNode('paragraph', [], [texstyleText('One')]);

    expect(texstyleAttrs($paragraph, '<ol id="o"><li>One</li></ol><p>One</p>'))->toBe(['id' => null]);
});

it('tells apart texts that share their first characters', function (): void {
    $mapped = BardTexstyleAttributeMapper::mapAttributes(
        [texstyleNode('paragraph', [], [texstyleText('Same prefix A')]), texstyleNode('paragraph', [], [texstyleText('Same prefix B')])],
        '<p id="a">Same prefix A</p><p id="b">Same prefix B</p>'
    );

    expect(array_column(array_column($mapped, 'attrs'), 'id'))->toBe(['a', 'b']);
});

it('tells texts apart by the first eight hex digits of their hash', function (string $first, string $second, array $ids): void {
    // `Text 2855` and `Text 16221` share seven leading hex digits of their MD5, and
    // `Text 0` and `Text 3` share the last digit of the full hash, so both pairs are
    // told apart; `Text 82061` and `Text 97693` share all eight leading digits the
    // signature keeps, so the second element's id lands on both nodes. A collision needs a 32-bit match, so it is
    // pinned here rather than ticketed.
    $mapped = BardTexstyleAttributeMapper::mapAttributes(
        [texstyleNode('paragraph', [], [texstyleText($first)]), texstyleNode('paragraph', [], [texstyleText($second)])],
        "<p id=\"a\">{$first}</p><p id=\"b\">{$second}</p>"
    );

    expect(array_column(array_column($mapped, 'attrs'), 'id'))->toBe($ids);
})->with([
    'seven digits in common' => ['Text 2855', 'Text 16221', ['a', 'b']],
    'the last digit in common' => ['Text 0', 'Text 3', ['a', 'b']],
    'eight digits in common' => ['Text 82061', 'Text 97693', ['b', 'b']],
]);

it('reads no classes when splitting them fails at a PCRE limit', function (): void {
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '0');

    try {
        $attrs = texstyleAttrs(texstyleNode('horizontalRule', []), '<hr class="big clear">');
    } finally {
        ini_set('pcre.backtrack_limit', $limit === false ? '1000000' : $limit);
    }

    expect($attrs)->toBe(['clearboth' => null, 'id' => null]);
});

it('reads whitespace-only differences as no text when normalizing fails at a PCRE limit', function (): void {
    // Normalizing fails on the element's text, which has whitespace in it, and
    // reads it as empty, so the element matches a paragraph with no text at all
    $limit = ini_get('pcre.backtrack_limit');
    ini_set('pcre.backtrack_limit', '0');

    try {
        $attrs = texstyleAttrs(texstyleNode('paragraph', []), '<p id="a">One two</p>');
    } finally {
        ini_set('pcre.backtrack_limit', $limit === false ? '1000000' : $limit);
    }

    expect($attrs)->toBe(['id' => 'a']);
});
