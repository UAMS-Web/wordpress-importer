<?php

declare(strict_types=1);

/**
 * Tests for the PullQuote set renderer, which renders a `pull_quote` set as a
 * `wp-block-quote` blockquote of paragraphs (#1314).
 *
 * Each paragraph's text nodes become a span, or a strong or em when the last
 * bold or italic mark says so, or a link when a link mark is reached. Text and
 * link attributes are escaped. An alignment other than aligncenter or an empty
 * string is added as a class, escaped (wordpress-importer-archive#1366). Malformed containers are skipped
 * rather than raising warnings.
 *
 * @command  php vendor/bin/pest tests/Unit/PullQuoteSetRendererTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\AbstractSetRenderer;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\PullQuote;

/**
 * A plain object with the given properties, as tiptap-php hands nodes around.
 *
 * @param  array<string, mixed>  $properties  The properties
 * @return stdClass The object
 */
function pullQuoteObject(array $properties): stdClass
{
    $object = new stdClass;

    foreach ($properties as $property => $value) {
        $object->{$property} = $value;
    }

    return $object;
}

/**
 * A pull quote node whose attrs carry the given values.
 *
 * @param  array<string, mixed>  $values  The set values
 * @return object The node
 */
function pullQuoteNode(array $values): object
{
    return pullQuoteObject(['attrs' => pullQuoteObject(['values' => pullQuoteObject($values)])]);
}

/**
 * A text node, with optional marks.
 *
 * @param  string  $text  The text
 * @param  array<int|string, mixed>|null  $marks  The marks, or null for none
 * @return array<string, mixed> The text node
 */
function pullQuoteText(string $text, ?array $marks = null): array
{
    return $marks === null
        ? ['type' => 'text', 'text' => $text]
        : ['type' => 'text', 'text' => $text, 'marks' => $marks];
}

/**
 * Render a node and return its content and opening tag, failing on any warning.
 *
 * @param  object  $node  The node
 * @return array<string, mixed> The rendered tag and content
 */
function renderPullQuote(object $node): array
{
    \set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        return (new PullQuote)->renderHTML($node);
    } finally {
        \restore_error_handler();
    }
}

it('registers the pull_quote set type', function (): void {
    expect((new ReflectionProperty(AbstractSetRenderer::class, 'setType'))->getValue(new PullQuote))->toBe('pull_quote');
});

it('renders each paragraph and text node, wrapping plain text in a span', function (): void {
    $result = renderPullQuote(pullQuoteNode([
        'quote' => [
            ['type' => 'paragraph', 'content' => [pullQuoteText('One '), pullQuoteText('two')]],
            ['type' => 'paragraph', 'content' => [pullQuoteText('Three')]],
        ],
    ]));

    expect($result)->toBe([
        'tag' => '<blockquote class="wp-block-quote">',
        'content' => '<p><span>One </span><span>two</span></p><p><span>Three</span></p>',
    ]);
});

it('escapes text content', function (): void {
    $result = renderPullQuote(pullQuoteNode([
        'quote' => [['content' => [pullQuoteText('<b>"A" & \'B\'</b>')]]],
    ]));

    expect($result['content'])->toBe('<p><span>&lt;b&gt;&quot;A&quot; &amp; &#039;B&#039;&lt;/b&gt;</span></p>');
});

it('picks the tag from the last bold or italic mark', function (array $marks, string $expected): void {
    $result = renderPullQuote(pullQuoteNode([
        'quote' => [['content' => [pullQuoteText('x', $marks)]]],
    ]));

    expect($result['content'])->toBe("<p>{$expected}</p>");
})->with([
    'bold' => [[['type' => 'bold']], '<strong>x</strong>'],
    'italic' => [[['type' => 'italic']], '<em>x</em>'],
    'bold then italic' => [[['type' => 'bold'], ['type' => 'italic']], '<em>x</em>'],
    'italic then bold' => [[['type' => 'italic'], ['type' => 'bold']], '<strong>x</strong>'],
    'unknown mark' => [[['type' => 'underline']], '<span>x</span>'],
    'no marks' => [[], '<span>x</span>'],
    'mark that is not an array, then bold' => [['bold', ['type' => 'bold']], '<strong>x</strong>'],
    'mark type that is not a string, then italic' => [[['type' => 7], ['type' => 'italic']], '<em>x</em>'],
    'mark without a type' => [[['attrs' => []]], '<span>x</span>'],
]);

it('treats marks that are not iterable as none', function (): void {
    $result = renderPullQuote(pullQuoteNode([
        'quote' => [['content' => [['text' => 'x', 'marks' => 'bold']]]],
    ]));

    expect($result['content'])->toBe('<p><span>x</span></p>');
});

it('renders a link mark as an anchor, with an escaped href and an optional target', function (array $attrs, string $expected): void {
    $result = renderPullQuote(pullQuoteNode([
        'quote' => [['content' => [pullQuoteText('go & see', [['type' => 'link', 'attrs' => $attrs]])]]],
    ]));

    expect($result['content'])->toBe("<p>{$expected}</p>");
})->with([
    'href and target' => [
        ['href' => 'https://example.test/?a=1&b="2"', 'target' => '_blank'],
        '<a href="https://example.test/?a=1&amp;b=&quot;2&quot;" target="_blank">go &amp; see</a>',
    ],
    'escaped target' => [
        ['href' => '/x', 'target' => '"new"'],
        '<a href="/x" target="&quot;new&quot;">go &amp; see</a>',
    ],
    'empty target' => [['href' => '/x', 'target' => ''], '<a href="/x">go &amp; see</a>'],
    'non-string target' => [['href' => '/x', 'target' => 1], '<a href="/x">go &amp; see</a>'],
    'no target' => [['href' => '/x'], '<a href="/x">go &amp; see</a>'],
    'non-string href' => [['href' => ['/x']], '<a href="">go &amp; see</a>'],
    'no href' => [[], '<a href="">go &amp; see</a>'],
]);

it('appends a link after earlier text in the same paragraph', function (): void {
    $result = renderPullQuote(pullQuoteNode([
        'quote' => [['content' => [pullQuoteText('Read '), pullQuoteText('this', [['type' => 'link', 'attrs' => ['href' => '/x']]])]]],
    ]));

    expect($result['content'])->toBe('<p><span>Read </span><a href="/x">this</a></p>');
});

it('treats link attrs that are not an array as empty', function (): void {
    $result = renderPullQuote(pullQuoteNode([
        'quote' => [['content' => [pullQuoteText('x', [['type' => 'link', 'attrs' => 'href=/x']])]]],
    ]));

    expect($result['content'])->toBe('<p><a href="">x</a></p>');
});

it('stops reading marks at a link, which replaces any earlier tag', function (array $marks): void {
    $result = renderPullQuote(pullQuoteNode([
        'quote' => [['content' => [pullQuoteText('x', $marks), pullQuoteText('y')]]],
    ]));

    // The link is written once, with no wrapping tag, and the next text node is unaffected
    expect($result['content'])->toBe('<p><a href="/x">x</a><span>y</span></p>');
})->with([
    'bold, then a link' => [[['type' => 'bold'], ['type' => 'link', 'attrs' => ['href' => '/x']]]],
    'a link, then bold' => [[['type' => 'link', 'attrs' => ['href' => '/x']], ['type' => 'bold']]],
    'a link, then another link' => [[['type' => 'link', 'attrs' => ['href' => '/x']], ['type' => 'link', 'attrs' => ['href' => '/y']]]],
]);

it('reads text that is missing or not a string as empty', function (array $text): void {
    $result = renderPullQuote(pullQuoteNode(['quote' => [['content' => [$text]]]]));

    expect($result['content'])->toBe('<p><span></span></p>');
})->with([
    'missing' => [['type' => 'text']],
    'not a string' => [['text' => 42]],
]);

it('skips malformed paragraphs and text nodes', function (): void {
    $result = renderPullQuote(pullQuoteNode([
        'quote' => [
            'not a paragraph',
            ['content' => 'not iterable'],
            ['content' => ['not a text node', pullQuoteText('kept')]],
            ['type' => 'paragraph'],
        ],
    ]));

    // A paragraph without content renders empty; one with non-iterable content is dropped
    expect($result['content'])->toBe('<p><span>kept</span></p><p></p>');
});

it('reads iterables, not only arrays, for paragraphs, text nodes and marks', function (): void {
    $result = renderPullQuote(pullQuoteNode([
        'quote' => new ArrayIterator([
            ['content' => new ArrayIterator([
                ['text' => 'x', 'marks' => new ArrayIterator([['type' => 'bold']])],
            ])],
        ]),
    ]));

    expect($result['content'])->toBe('<p><strong>x</strong></p>');
});

it('adds an alignment other than aligncenter as a class', function (mixed $align, string $expectedTag): void {
    $result = renderPullQuote(pullQuoteNode(['quote' => [], 'align' => $align]));

    expect($result['tag'])->toBe($expectedTag);
})->with([
    'alignwide' => ['alignwide', '<blockquote class="wp-block-quote alignwide">'],
    'alignleft' => ['alignleft', '<blockquote class="wp-block-quote alignleft">'],
    'aligncenter' => ['aligncenter', '<blockquote class="wp-block-quote">'],
    'not a string' => [['alignwide'], '<blockquote class="wp-block-quote">'],
    'empty, with no trailing space' => ['', '<blockquote class="wp-block-quote">'],
]);

it('escapes the alignment class so it cannot end the attribute (wordpress-importer-archive#1366)', function (string $align, string $expectedTag): void {
    $result = renderPullQuote(pullQuoteNode(['quote' => [], 'align' => $align]));

    expect($result['tag'])->toBe($expectedTag);
})->with([
    'double quote' => ['x" onmouseover="alert(1)', '<blockquote class="wp-block-quote x&quot; onmouseover=&quot;alert(1)">'],
    'angle brackets' => ['a<b>c', '<blockquote class="wp-block-quote a&lt;b&gt;c">'],
    'ampersand' => ['a&b', '<blockquote class="wp-block-quote a&amp;b">'],
    'single quote' => ["a'b", '<blockquote class="wp-block-quote a&#039;b">'],
]);

it('renders an empty centered quote, without warnings, when the node lacks usable values', function (object $node): void {
    $result = renderPullQuote($node);

    expect($result)->toBe([
        'tag' => '<blockquote class="wp-block-quote">',
        'content' => '',
    ]);
})->with([
    'no attrs' => fn (): object => new stdClass,
    'attrs not an object' => fn (): object => pullQuoteObject(['attrs' => ['values' => []]]),
    'no values' => fn (): object => pullQuoteObject(['attrs' => new stdClass]),
    'values not an object' => fn (): object => pullQuoteObject(['attrs' => pullQuoteObject(['values' => ['quote' => []]])]),
    'no quote and no align' => fn (): object => pullQuoteNode([]),
    'quote not iterable' => fn (): object => pullQuoteNode(['quote' => 'text']),
]);
