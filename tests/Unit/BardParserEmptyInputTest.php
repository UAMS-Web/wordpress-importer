<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BardParser;

it('parses empty or whitespace-only HTML to no content', function (string $html): void {
    expect((new BardParser)->parse($html))->toBe([]);
})->with([
    'empty' => [''],
    'spaces' => ['   '],
    'mixed whitespace' => [" \n\t\r\n "],
]);

it('parses a line of plain text to a paragraph', function (): void {
    // This literal is the string Pest's EmptyStringToNotEmpty mutator puts in place of '', so
    // the whitespace guard's comparison against '' is pinned by an input as well as by blanks.
    expect((new BardParser)->parse('PEST Mutator was here!'))
        ->toBe([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'PEST Mutator was here!']]]]);
});

it('parses HTML that is only a WordPress block comment to no content', function (): void {
    expect((new BardParser)->parse('<!-- wp:paragraph --><!-- /wp:paragraph -->'))->toBe([]);
});

it('parses every node and mark the editor is configured for', function (): void {
    $text = static fn (string $text, ?string $mark = null): array => $mark === null
        ? ['type' => 'text', 'text' => $text]
        : ['type' => 'text', 'text' => $text, 'marks' => [['type' => $mark]]];

    $html = '<h1>a</h1><h2>b</h2><h3>c</h3><h4>d</h4><h5>e</h5><h6>f</h6>'
        .'<p><strong>b</strong><code>c</code><em>i</em><a href="https://x.test/">l</a>'
        .'<s>s</s><sub>u</sub><sup>p</sup>x<br>y</p>'
        .'<ul><li><p>one</p></li></ul><ol><li><p>two</p></li></ol><hr><pre><code>code</code></pre>'
        .'<div data-set-type="pull_quote"'.SetMarkupNonce::attribute().' data-set-attr-quote="q"></div>'
        .'<p>after</p>';

    try {
        $document = (new BardParser)->parse($html);
    } finally {
        SetMarkupNonce::reset();
    }

    // The set id is random per parse, so compare everything else.
    $set = $document[11] ?? null;
    expect($set)->toBeArray();
    \assert(\is_array($set) && \is_array($set['attrs'] ?? null));
    expect($set['attrs']['id'] ?? null)->toBeString();
    unset($set['attrs']['id']);
    $document[11] = $set;

    $headings = [];

    foreach ([1, 2, 3, 4, 5, 6] as $index => $level) {
        $headings[] = ['type' => 'heading', 'attrs' => ['level' => $level], 'content' => [$text(['a', 'b', 'c', 'd', 'e', 'f'][$index])]];
    }

    $listItem = static fn (string $words): array => ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [$text($words)]]]];

    expect($document)->toBe([
        ...$headings,
        ['type' => 'paragraph', 'content' => [
            $text('b', 'bold'),
            $text('c', 'code'),
            $text('i', 'italic'),
            ['type' => 'text', 'text' => 'l', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'https://x.test/']]]],
            $text('s', 'strike'),
            $text('u', 'subscript'),
            $text('p', 'superscript'),
            $text('x'),
            ['type' => 'hardBreak'],
            $text('y'),
        ]],
        ['type' => 'bulletList', 'content' => [$listItem('one')]],
        ['type' => 'orderedList', 'content' => [$listItem('two')]],
        ['type' => 'horizontalRule'],
        ['type' => 'codeBlock', 'attrs' => ['language' => 'null'], 'content' => [$text('code', 'code')]],
        ['type' => 'set', 'attrs' => ['values' => ['type' => 'pull_quote', 'quote' => 'q']]],
        ['type' => 'paragraph', 'content' => [$text('after')]],
    ]);
});
