<?php

declare(strict_types=1);

/**
 * Tests the importer's Tiptap `Editor`: how it reads HTML, JSON and array input,
 * and the four passes it runs over the parsed document (wrapping text under a
 * list item in a paragraph, deduplicating marks, trimming code block text, and
 * writing a non-breaking space as an escaped underscore).
 *
 * The array cases hand the editor a document directly, because the schema keeps
 * whatever it is given and HTML cannot produce the malformed shapes the passes
 * guard against.
 *
 * @command  php vendor/bin/pest tests/Unit/EditorTest.php
 */

use Tiptap\Marks\Bold;
use Tiptap\Marks\Strike;
use Tiptap\Nodes\BulletList;
use Tiptap\Nodes\Document;
use Tiptap\Nodes\Paragraph;
use Tiptap\Nodes\Text;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Editor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\CodeBlock;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\ListItem;

/**
 * An editor that reports a content type of the test's choosing, so the branch
 * that reads each type can be reached with a value of another type.
 */
final class EditorTestDeclaredType extends Editor
{
    /**
     * The content type to report.
     */
    public string $declaredType = 'HTML';

    /**
     * Report the declared content type, whatever the value is.
     *
     * @param  mixed  $value  The input content
     * @return string The declared content type
     */
    public function getContentType($value): string
    {
        return $this->declaredType;
    }
}

/**
 * The extensions every editor in this file is built with.
 *
 * @return array{extensions: list<object>} The editor configuration
 */
function editorTestConfiguration(): array
{
    return [
        'extensions' => [
            new Document,
            new Paragraph,
            new Text,
            new BulletList,
            new ListItem,
            new CodeBlock,
            new Bold,
            new Strike,
        ],
    ];
}

/**
 * Parse a value and return the document, failing on any warning or notice.
 *
 * @param  mixed  $value  The input content
 * @param  ?Editor  $editor  The editor to use, or null for a new one
 * @return mixed The parsed document
 */
function editorTestDocument(mixed $value, ?Editor $editor = null): mixed
{
    // A pass that reads a missing key must fail the test rather than warn
    \set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        return ($editor ?? new Editor(editorTestConfiguration()))->setContent($value)->getDocument();
    } finally {
        \restore_error_handler();
    }
}

/**
 * A document whose top-level content is the given nodes.
 *
 * @param  list<mixed>  $content  The top-level nodes
 * @return array{type: string, content: list<mixed>} The document
 */
function editorTestDoc(array $content): array
{
    return ['type' => 'doc', 'content' => $content];
}

/**
 * A text node.
 *
 * @param  string  $text  The text
 * @return array{type: string, text: string} The node
 */
function editorTestText(string $text): array
{
    return ['type' => 'text', 'text' => $text];
}

/**
 * A paragraph holding the given text.
 *
 * @param  string  $text  The text
 * @return array{type: string, content: list<array{type: string, text: string}>} The node
 */
function editorTestParagraph(string $text): array
{
    return ['type' => 'paragraph', 'content' => [editorTestText($text)]];
}

describe('reading input', function (): void {
    it('parses HTML', function (): void {
        expect(editorTestDocument('<p>Hello</p>'))->toBe(editorTestDoc([editorTestParagraph('Hello')]));
    });

    it('parses a JSON document', function (): void {
        expect(editorTestDocument('{"type":"doc","content":[{"type":"paragraph","content":[{"type":"text","text":"j"}]}]}'))
            ->toBe(editorTestDoc([editorTestParagraph('j')]));
    });

    it('takes an array document', function (): void {
        expect(editorTestDocument(editorTestDoc([editorTestParagraph('a')])))->toBe(editorTestDoc([editorTestParagraph('a')]));
    });

    it('refuses JSON that is not a document', function (string $json): void {
        expect(fn (): mixed => editorTestDocument($json))
            ->toThrow(Exception::class, 'Unable to parse editor content into a document.');
    })->with([
        'a string' => ['"text"'],
        'a number' => ['12'],
    ]);

    it('refuses an array JSON cannot encode', function (): void {
        expect(fn (): mixed => editorTestDocument(['type' => 'doc', 'value' => INF]))
            ->toThrow(Exception::class, 'Unable to encode array content as JSON.');
    });

    it('refuses a value of no known type', function (): void {
        expect(fn (): mixed => editorTestDocument(5))
            ->toThrow(Exception::class, 'Unknown format passed to setContent(). Try passing HTML, JSON or an Array.');
    });

    it('reads each branch only when the value has the declared type', function (string $declaredType, mixed $value): void {
        // A declared type whose value does not match reaches no branch at all
        $editor = new EditorTestDeclaredType(editorTestConfiguration());
        $editor->declaredType = $declaredType;

        expect(fn (): mixed => editorTestDocument($value, $editor))
            ->toThrow(Exception::class, 'Unrecognized content type for editor input.');
    })->with([
        'HTML declared for an array' => ['HTML', editorTestDoc([])],
        'an array declared for a string' => ['Array', '<p>a</p>'],
        'JSON declared for an array' => ['JSON', editorTestDoc([])],
        'an unknown type' => ['XML', '<p>a</p>'],
    ]);

    it('refuses to run without a schema', function (): void {
        // Only HTML reads the schema before the check, so give it an array
        $editor = new Editor(editorTestConfiguration());
        $editor->schema = null;

        expect(fn (): mixed => editorTestDocument(editorTestDoc([]), $editor))
            ->toThrow(Exception::class, 'Editor schema is unavailable.');
    });
});

describe('wrapping text under a list item', function (): void {
    it('wraps a list item\'s text in paragraphs around its nested list', function (): void {
        expect(editorTestDocument('<ul><li>One<ul><li>Two</li></ul>after</li></ul>'))->toBe(editorTestDoc([
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    editorTestParagraph('One'),
                    ['type' => 'bulletList', 'content' => [
                        ['type' => 'listItem', 'content' => [editorTestParagraph('Two')]],
                    ]],
                    editorTestParagraph('after'),
                ]],
            ]],
        ]));
    });

    it('gathers consecutive text into one paragraph and leaves other nodes beside it', function (): void {
        $document = editorTestDocument(editorTestDoc([
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    editorTestText('a'),
                    editorTestText('b'),
                    ['type' => 'bulletList', 'content' => []],
                    editorTestText('c'),
                ]],
            ]],
        ]));

        expect($document)->toBe(editorTestDoc([
            ['type' => 'bulletList', 'content' => [
                ['type' => 'listItem', 'content' => [
                    ['type' => 'paragraph', 'content' => [editorTestText('a'), editorTestText('b')]],
                    ['type' => 'bulletList', 'content' => []],
                    editorTestParagraph('c'),
                ]],
            ]],
        ]));
    });

    it('keeps a list item\'s content that is not a text node where it is', function (): void {
        $listItem = ['type' => 'listItem', 'content' => ['junk', ['text' => 'untyped'], editorTestText('a')]];

        expect(editorTestDocument(editorTestDoc([$listItem])))
            ->toBe(editorTestDoc([['type' => 'listItem', 'content' => ['junk', ['text' => 'untyped'], editorTestParagraph('a')]]]));
    });

    it('passes over content that is not a node and goes on to the next', function (): void {
        $listItem = ['type' => 'listItem', 'content' => [editorTestText('a')]];

        expect(editorTestDocument(editorTestDoc(['junk', $listItem])))
            ->toBe(editorTestDoc(['junk', ['type' => 'listItem', 'content' => [editorTestParagraph('a')]]]));
    });

    it('leaves a node with no usable type and everything under it alone', function (mixed $type): void {
        $listItem = ['type' => 'listItem', 'content' => [editorTestText('a')]];
        $node = $type === null ? ['content' => [$listItem]] : ['type' => $type, 'content' => [$listItem]];

        expect(editorTestDocument(editorTestDoc([$node])))->toBe(editorTestDoc([$node]));
    })->with([
        'no type' => [null],
        'a type that is not a string' => [5],
    ]);
});

describe('deduplicating marks', function (): void {
    it('keeps the first mark of each type and drops marks that are not usable', function (): void {
        $text = [
            'type' => 'text',
            'text' => 'a',
            'marks' => [['type' => 'bold'], ['type' => 'bold'], 'bad', ['nope' => 1], ['type' => 5], ['type' => 'strike']],
        ];

        expect(editorTestDocument(editorTestDoc([['type' => 'paragraph', 'content' => [$text]]])))->toBe(editorTestDoc([
            ['type' => 'paragraph', 'content' => [
                ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'bold'], ['type' => 'strike']]],
            ]],
        ]));
    });

    it('collapses the strike marks of nested strike elements', function (): void {
        expect(editorTestDocument('<p><s><del>gone</del></s></p>'))->toBe(editorTestDoc([
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'gone', 'marks' => [['type' => 'strike']]]]],
        ]));
    });

    it('compares mark types exactly', function (): void {
        // Loosely, the numeric strings "1" and "01" are equal
        $text = ['type' => 'text', 'text' => 'a', 'marks' => [['type' => '1'], ['type' => '01']]];

        expect(editorTestDocument(editorTestDoc([['type' => 'paragraph', 'content' => [$text]]])))
            ->toBe(editorTestDoc([['type' => 'paragraph', 'content' => [$text]]]));
    });

    it('removes a marks list left with no usable mark', function (): void {
        $text = ['type' => 'text', 'text' => 'z', 'marks' => ['bad']];

        expect(editorTestDocument(editorTestDoc([['type' => 'paragraph', 'content' => [$text]]])))
            ->toBe(editorTestDoc([editorTestParagraph('z')]));
    });

    it('passes over content that is not a node and goes on to the next', function (): void {
        $text = ['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'bold'], ['type' => 'bold']]];

        expect(editorTestDocument(editorTestDoc(['junk', ['type' => 'paragraph', 'content' => [$text]]])))->toBe(editorTestDoc([
            'junk',
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'a', 'marks' => [['type' => 'bold']]]]],
        ]));
    });

    it('leaves a node with no usable type and everything under it alone', function (mixed $type): void {
        $marks = [['type' => 'bold'], ['type' => 'bold']];
        $node = ['marks' => $marks, 'content' => [['type' => 'text', 'text' => 'a', 'marks' => $marks]]];

        if ($type !== null) {
            $node['type'] = $type;
        }

        expect(editorTestDocument(editorTestDoc([$node])))->toBe(editorTestDoc([$node]));
    })->with([
        'no type' => [null],
        'a type that is not a string' => [5],
    ]);
});

describe('trimming code block text', function (): void {
    it('trims trailing whitespace from a code block\'s text', function (): void {
        expect(editorTestDocument("<pre><code>  x = 1   \n\n</code></pre>"))->toBe(editorTestDoc([
            ['type' => 'codeBlock', 'attrs' => ['language' => 'null'], 'content' => [editorTestText('  x = 1')]],
        ]));
    });

    it('trims only text nodes that carry string text', function (): void {
        $content = ['junk', ['type' => 'hardBreak'], ['type' => 'text'], ['type' => 'text', 'text' => 5], ['type' => 'bold', 'text' => 'b  '], editorTestText('a  ')];

        expect(editorTestDocument(editorTestDoc([['type' => 'codeBlock', 'content' => $content]])))->toBe(editorTestDoc([
            ['type' => 'codeBlock', 'content' => ['junk', ['type' => 'hardBreak'], ['type' => 'text'], ['type' => 'text', 'text' => 5], ['type' => 'bold', 'text' => 'b  '], editorTestText('a')]],
        ]));
    });

    it('leaves text outside a code block untrimmed', function (): void {
        expect(editorTestDocument(editorTestDoc([editorTestParagraph('a  ')])))->toBe(editorTestDoc([editorTestParagraph('a  ')]));
    });

    it('passes over content that is not a node and goes on to the next', function (): void {
        expect(editorTestDocument(editorTestDoc(['junk', ['type' => 'codeBlock', 'content' => [editorTestText('a  ')]]])))
            ->toBe(editorTestDoc(['junk', ['type' => 'codeBlock', 'content' => [editorTestText('a')]]]));
    });

    it('leaves a node with no usable type and everything under it alone', function (mixed $type): void {
        $codeBlock = ['type' => 'codeBlock', 'content' => [editorTestText('a  ')]];
        $node = $type === null ? ['content' => [$codeBlock]] : ['type' => $type, 'content' => [$codeBlock]];

        expect(editorTestDocument(editorTestDoc([$node])))->toBe(editorTestDoc([$node]));
    })->with([
        'no type' => [null],
        'a type that is not a string' => [5],
    ]);
});

describe('writing non-breaking spaces', function (): void {
    it('writes each non-breaking space in text as an escaped underscore', function (): void {
        expect(editorTestDocument("<p>a\u{00A0}b\u{00A0}c</p>"))->toBe(editorTestDoc([editorTestParagraph('a\_b\_c')]));
    });

    it('changes only text nodes that carry string text', function (): void {
        $content = [['type' => 'text'], ['type' => 'text', 'text' => 5], ['type' => 'hardBreak', 'text' => "a\u{00A0}b"]];

        expect(editorTestDocument(editorTestDoc([['type' => 'paragraph', 'content' => $content]])))
            ->toBe(editorTestDoc([['type' => 'paragraph', 'content' => $content]]));
    });

    it('passes over content that is not a node and goes on to the next', function (): void {
        expect(editorTestDocument(editorTestDoc(['junk', editorTestParagraph("a\u{00A0}b")])))
            ->toBe(editorTestDoc(['junk', editorTestParagraph('a\_b')]));
    });

    it('leaves a node with no usable type and everything under it alone', function (mixed $type): void {
        $node = $type === null ? ['content' => [editorTestText("a\u{00A0}b")]] : ['type' => $type, 'content' => [editorTestText("a\u{00A0}b")]];

        expect(editorTestDocument(editorTestDoc([$node])))->toBe(editorTestDoc([$node]));
    })->with([
        'no type' => [null],
        'a type that is not a string' => [5],
    ]);
});
