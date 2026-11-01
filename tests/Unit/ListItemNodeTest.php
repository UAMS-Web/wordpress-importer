<?php

declare(strict_types=1);

/**
 * Tests that the `ListItem` Tiptap node maps `li` elements, wraps a bare text
 * child in a paragraph, and converts nested lists recursively (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/ListItemNodeTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\ListItem;

/**
 * A plain object with the given properties, as tiptap-php hands nodes around.
 *
 * @param  array<int|string, mixed>  $properties  The properties
 * @return stdClass The object
 */
function listItemObject(array $properties): stdClass
{
    $object = new stdClass;

    foreach ($properties as $name => $value) {
        $object->{$name} = $value;
    }

    return $object;
}

it('parses li elements and needs no wrapper', function (): void {
    expect((new ListItem)->parseHTML())->toBe([['tag' => 'li']])
        ->and(ListItem::wrapper((new DOMDocument)->createElement('li')))->toBeNull();
});

it('wraps a text child in a paragraph and keeps other children as they are', function (): void {
    $text = listItemObject(['type' => 'text', 'text' => 'Item']);
    $paragraph = listItemObject(['type' => 'paragraph', 'content' => []]);

    expect((new ListItem)->toProseMirror(listItemObject(['content' => [$text, $paragraph]])))->toBe([
        'type' => 'listItem',
        'content' => [
            ['type' => 'paragraph', 'content' => [$text]],
            $paragraph,
        ],
    ]);
});

it('converts each item of a nested list, keeping the list type', function (string $listType): void {
    $inner = listItemObject(['type' => 'text', 'text' => 'Inner']);

    $result = (new ListItem)->toProseMirror(listItemObject(['content' => [
        listItemObject(['type' => $listType, 'content' => [listItemObject(['content' => [$inner]]), 'not an item']]),
    ]]));

    expect($result)->toBe([
        'type' => 'listItem',
        'content' => [
            [
                'type' => $listType,
                'content' => [
                    ['type' => 'listItem', 'content' => [['type' => 'paragraph', 'content' => [$inner]]]],
                ],
            ],
        ],
    ]);
})->with(['bulletList', 'orderedList']);

it('gives a nested list with unusable content no items', function (): void {
    $result = (new ListItem)->toProseMirror(listItemObject(['content' => [
        listItemObject(['type' => 'bulletList', 'content' => 'not a list']),
        listItemObject(['type' => 'orderedList']),
    ]]));

    expect($result)->toBe([
        'type' => 'listItem',
        'content' => [
            ['type' => 'bulletList', 'content' => []],
            ['type' => 'orderedList', 'content' => []],
        ],
    ]);
});

it('skips children it cannot read, and keeps going past them', function (): void {
    $text = listItemObject(['type' => 'text', 'text' => 'Kept']);

    // A child with no type must be skipped without reading the missing property
    \set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        $result = (new ListItem)->toProseMirror(listItemObject(['content' => [
            'not an object',
            listItemObject(['text' => 'no type']),
            listItemObject(['type' => 5]),
            $text,
        ]]));
    } finally {
        \restore_error_handler();
    }

    expect($result)->toBe([
        'type' => 'listItem',
        'content' => [['type' => 'paragraph', 'content' => [$text]]],
    ]);
});

it('gives a node with no usable content an empty list item', function (object $node): void {
    expect((new ListItem)->toProseMirror($node))->toBe(['type' => 'listItem', 'content' => []]);
})->with([
    'no content' => [listItemObject([])],
    'content that is not iterable' => [listItemObject(['content' => 'text'])],
]);

it('reads children from any iterable', function (): void {
    $text = listItemObject(['type' => 'text', 'text' => 'Item']);

    expect((new ListItem)->toProseMirror(listItemObject(['content' => new ArrayIterator([$text])])))->toBe([
        'type' => 'listItem',
        'content' => [['type' => 'paragraph', 'content' => [$text]]],
    ]);
});
