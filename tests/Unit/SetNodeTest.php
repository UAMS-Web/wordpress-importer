<?php

declare(strict_types=1);

/**
 * Tests the `Set` Tiptap node: the one rule it parses set markup with, how that
 * rule reads a set's values back from its `data-set-*` attributes, how it hands
 * a set to the renderer for its type, and the ProseMirror JSON it writes.
 *
 * The parse rule's `getAttrs` callback is called directly on a built element,
 * so each attribute shape can be given exactly. That set markup without the
 * current nonce is refused is covered end to end by `BardSetMarkupTrustTest`.
 *
 * @command  php vendor/bin/pest tests/Unit/SetNodeTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Set;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\GenericSetRenderer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\PullQuote;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\Table;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;

afterEach(function (): void {
    // Leave no nonce behind for a later test in this process
    SetMarkupNonce::reset();
});

/**
 * Read a set element's attributes the way the parse rule does.
 *
 * @param  array<string, string>  $attributes  The element's attributes, in order
 * @return mixed What the rule's `getAttrs` returns
 */
function setNodeAttrs(array $attributes): mixed
{
    $element = (new DOMDocument)->createElement('div');

    foreach ($attributes as $name => $value) {
        $element->setAttribute($name, $value);
    }

    $getAttrs = (new Set)->parseHTML()[0]['getAttrs'] ?? null;

    if (! $getAttrs instanceof Closure) {
        throw new RuntimeException('The set rule has no getAttrs callback.');
    }

    return $getAttrs($element);
}

/**
 * The values the rule reads off a set of the given type carrying this nonce.
 *
 * @param  string  $type  The data-set-type value
 * @param  array<string, string>  $attributes  The element's other attributes
 * @return mixed The values, or the whole result when it is not a set
 */
function setNodeValues(string $type, array $attributes = []): mixed
{
    $result = setNodeAttrs([SetMarkupNonce::ATTRIBUTE => SetMarkupNonce::value(), 'data-set-type' => $type, ...$attributes]);

    return \is_array($result) ? ($result['values'] ?? null) : $result;
}

/**
 * A plain object with the given properties, as tiptap-php hands nodes around.
 *
 * @param  array<string, mixed>  $properties  The properties
 * @return stdClass The object
 */
function setNodeObject(array $properties): stdClass
{
    $object = new stdClass;

    foreach ($properties as $name => $value) {
        $object->{$name} = $value;
    }

    return $object;
}

/**
 * A set node whose values carry the given properties.
 *
 * @param  array<string, mixed>  $values  The set values
 * @return stdClass The node
 */
function setNodeWithValues(array $values): stdClass
{
    return setNodeObject(['attrs' => setNodeObject(['values' => setNodeObject($values)])]);
}

describe('the parse rule', function (): void {
    it('is named set and runs ahead of the default nodes', function (): void {
        expect(Set::$name)->toBe('set')
            ->and(Set::$priority)->toBe(200);
    });

    it('has one rule, matching a div carrying the nonce attribute', function (): void {
        $rules = (new Set)->parseHTML();

        expect($rules)->toHaveCount(1)
            ->and($rules[0]['tag'] ?? null)->toBe('div[data-set-nonce]');
    });

    it('builds the rule once for every set node', function (): void {
        // The callback is the same object, so the rule was not built again
        expect((new Set)->parseHTML())->toBe((new Set)->parseHTML());
    });

    it('refuses set markup without the current nonce', function (): void {
        expect(setNodeAttrs(['data-set-type' => 'file']))->toBeNull()
            ->and(setNodeAttrs([SetMarkupNonce::ATTRIBUTE => 'stale', 'data-set-type' => 'file']))->toBeNull();
    });

    it('reads a set of each type it supports', function (string $type): void {
        $result = setNodeAttrs([SetMarkupNonce::ATTRIBUTE => SetMarkupNonce::value(), 'data-set-type' => $type]);

        expect($result)->toBeArray()
            ->and(\is_array($result) ? $result['values'] : null)->toBe(['type' => $type])
            ->and(\is_array($result) ? $result['id'] : null)->toBeString()->not->toBe('');
    })->with(['file', 'people', 'pull_quote', 'table', 'image', 'youtube', 'vimeo', 'box', 'form', 'gallery', 'reusable_content']);

    it('refuses a set type it does not support', function (string $type): void {
        expect(setNodeValues($type))->toBeNull();
    })->with([
        'the retired video set' => ['video'],
        'no type' => [''],
    ]);

    it('gives each set its own id', function (): void {
        $first = setNodeAttrs([SetMarkupNonce::ATTRIBUTE => SetMarkupNonce::value(), 'data-set-type' => 'file']);
        $second = setNodeAttrs([SetMarkupNonce::ATTRIBUTE => SetMarkupNonce::value(), 'data-set-type' => 'file']);

        expect(\is_array($first) ? $first['id'] : null)->not->toBe(\is_array($second) ? $second['id'] : null);
    });
});

describe('reading set values', function (): void {
    it('keeps an attribute value as a string and ignores attributes that are not values', function (): void {
        expect(setNodeValues('file', ['class' => 'wp-block-file', 'data-set-attr-title' => 'Report', 'data-set-other' => 'x']))
            ->toBe(['type' => 'file', 'title' => 'Report']);
    });

    it('reads an integer attribute as an integer when it is numeric', function (string $value, int|string $expected): void {
        expect(setNodeValues('file', ['data-set-integer-size' => $value]))->toBe(['type' => 'file', 'size' => $expected]);
    })->with([
        'a number' => ['7', 7],
        'not a number' => ['many', 'many'],
    ]);

    it('reads a people set\'s switches as booleans and its limit as an integer', function (): void {
        $attributes = [
            'data-set-attr-multiple_groups_selection' => '1',
            'data-set-attr-entry_link_query' => 'true',
            'data-set-attr-pagination' => '',
            'data-set-attr-entry_link_target' => '0',
            'data-set-attr-image_effect' => 'false',
            'data-set-attr-limit' => '12',
        ];

        expect(setNodeValues('people', $attributes))->toBe([
            'type' => 'people',
            'multiple_groups_selection' => true,
            'entry_link_query' => true,
            'pagination' => false,
            'entry_link_target' => false,
            'image_effect' => false,
            'limit' => 12,
        ]);
    });

    it('keeps a people limit that is not a number', function (): void {
        expect(setNodeValues('people', ['data-set-attr-limit' => 'all']))->toBe(['type' => 'people', 'limit' => 'all']);
    });

    it('reads each spelling of a boolean, and keeps a value that is neither', function (string $value, bool|string $expected): void {
        expect(setNodeValues('form', ['data-set-attr-heading_query' => $value]))->toBe(['type' => 'form', 'heading_query' => $expected]);
    })->with([
        'true' => ['true', true],
        'one' => ['1', true],
        'false' => ['false', false],
        'zero' => ['0', false],
        'empty' => ['', false],
        'another word' => ['yes', 'yes'],
        'a number that only loosely equals one' => ['1.0', '1.0'],
        'a number that only loosely equals zero' => ['0.0', '0.0'],
    ]);

    it('reads only the fields each set type defines as booleans', function (string $type, string $field, bool|string $expected): void {
        expect(setNodeValues($type, ["data-set-attr-{$field}" => '1']))->toBe(['type' => $type, $field => $expected]);
    })->with([
        'a form heading switch' => ['form', 'heading_query', true],
        'a form description switch' => ['form', 'description_query', true],
        'a gallery more switch' => ['gallery', 'more_query', true],
        'a gallery button target' => ['gallery', 'more_button_target', true],
        'a table first row' => ['table', 'first_row_headers', true],
        'a table first column' => ['table', 'first_column_headers', true],
        'a table last row' => ['table', 'last_row_footers', true],
        'a table heading switch' => ['table', 'heading_query', true],
        'a table description switch' => ['table', 'description_query', true],
        'a people limit on a form' => ['form', 'limit', '1'],
        'a gallery switch on a file' => ['file', 'more_query', '1'],
        'a people switch on an image' => ['image', 'pagination', '1'],
    ]);

    it('reads the boolean fields a table names, keeping its heading and description switches', function (): void {
        $attributes = [
            'data-set-boolean-fields' => 'striped,01',
            'data-set-attr-striped' => '1',
            'data-set-attr-first_row_headers' => '1',
            'data-set-attr-heading_query' => '1',
            'data-set-attr-description_query' => '0',
            // A field named "1" is not the listed "01", though the two compare equal loosely
            'data-set-attr-1' => 'true',
        ];

        expect(setNodeValues('table', $attributes))->toBe([
            'type' => 'table',
            'striped' => true,
            'first_row_headers' => '1',
            'heading_query' => true,
            'description_query' => false,
            1 => 'true',
        ]);
    });

    it('decodes the JSON fields of the set types that carry them', function (string $type, string $field): void {
        expect(setNodeValues($type, ["data-set-attr-{$field}" => '{"a":1}']))->toBe(['type' => $type, $field => ['a' => 1]]);
    })->with([
        'a pull quote' => ['pull_quote', 'quote'],
        'a table\'s data' => ['table', 'table_data'],
        'a people set\'s details' => ['people', 'display_detail'],
        'a people set\'s groups' => ['people', 'group_multiple'],
        'a gallery\'s images' => ['gallery', 'images'],
        'a gallery\'s description' => ['gallery', 'description'],
    ]);

    it('keeps a JSON field as a string on a set type that does not carry it', function (string $type, string $field): void {
        expect(setNodeValues($type, ["data-set-attr-{$field}" => '{"a":1}']))->toBe(['type' => $type, $field => '{"a":1}']);
    })->with([
        'a quote on a form' => ['form', 'quote'],
        'table data on a form' => ['form', 'table_data'],
        'images on a file' => ['file', 'images'],
        'a description on a table' => ['table', 'description'],
    ]);

    it('decodes a JSON field written as base64', function (string $type, string $field): void {
        $encoded = \base64_encode('{"a":[1,2]}');

        expect(setNodeValues($type, ["data-set-attr-{$field}" => $encoded]))->toBe(['type' => $type, $field => ['a' => [1, 2]]]);
    })->with([
        'a pull quote' => ['pull_quote', 'quote'],
        'a gallery\'s images' => ['gallery', 'images'],
        'a gallery\'s description' => ['gallery', 'description'],
    ]);

    it('keeps a JSON field that decodes to nothing as it arrived', function (string $value): void {
        expect(setNodeValues('pull_quote', ['data-set-attr-quote' => $value]))->toBe(['type' => 'pull_quote', 'quote' => $value]);
    })->with([
        'empty' => [''],
        'a JSON string' => ['"text"'],
        'base64 with a character outside its alphabet' => [\base64_encode('["a"]').'!'],
        'base64 of something that is not JSON' => [\base64_encode('plain words')],
        'base64 of a JSON string' => [\base64_encode('"text"')],
    ]);

    it('gives a people set its default details when their JSON was cut short', function (string $value): void {
        expect(setNodeValues('people', ['data-set-attr-display_detail' => $value]))
            ->toBe(['type' => 'people', 'display_detail' => ['name', 'photo', 'position']]);
    })->with([
        'cut short' => ['["name","pho'],
        'cut short after a space' => [' ["name","pho'],
    ]);

    it('keeps details that are not a list, and details on a table, as they arrived', function (string $type, string $value): void {
        expect(setNodeValues($type, ['data-set-attr-display_detail' => $value]))->toBe(['type' => $type, 'display_detail' => $value]);
    })->with([
        'people details that are not a list' => ['people', 'name'],
        'people details ending in a bracket' => ['people', 'name['],
        'a table\'s cut-short details' => ['table', '["name","pho'],
    ]);

    it('decodes people details that are whole', function (): void {
        expect(setNodeValues('people', ['data-set-attr-display_detail' => '["name","email"]']))
            ->toBe(['type' => 'people', 'display_detail' => ['name', 'email']]);
    });
});

describe('rendering', function (): void {
    it('hands a set to the renderer for its type', function (string $type): void {
        $node = setNodeWithValues(['type' => $type, 'title' => 'T']);

        expect((new Set)->renderHTML($node))->toBe((new GenericSetRenderer($type))->renderHTML($node));
    })->with(['people', 'file', 'youtube', 'vimeo', 'box', 'image', 'form', 'gallery', 'reusable_content']);

    it('renders a pull quote and a table with their own renderers', function (): void {
        $quote = setNodeWithValues(['type' => 'pull_quote', 'quote' => 'Q']);
        $table = setNodeWithValues(['type' => 'table']);

        expect((new Set)->renderHTML($quote))->toBe((new PullQuote)->renderHTML($quote))
            ->and((new Set)->renderHTML($table))->toBe((new Table)->renderHTML($table));
    });

    it('renders a bare div for a node it cannot read a set type from', function (mixed $node): void {
        expect((new Set)->renderHTML($node))->toBe(['tag' => '<div>']);
    })->with([
        'not an object' => ['node'],
        'no attributes' => [setNodeObject([])],
        'attributes that are not an object' => [setNodeObject(['attrs' => ['values' => ['type' => 'table']]])],
        'no values' => [setNodeObject(['attrs' => setNodeObject([])])],
        'values that are not an object' => [setNodeObject(['attrs' => setNodeObject(['values' => ['type' => 'table']])])],
        'no type' => [setNodeWithValues([])],
        'a type that is not a string' => [setNodeWithValues(['type' => 5])],
        'a type it does not render' => [setNodeWithValues(['type' => 'video'])],
    ]);
});

describe('writing ProseMirror JSON', function (): void {
    it('writes a set with its id and values', function (mixed $id, string $expected): void {
        $node = setNodeObject(['attrs' => setNodeObject(['id' => $id, 'values' => setNodeObject(['type' => 'file'])])]);

        expect((new Set)->toProseMirror($node))->toBe(['type' => 'set', 'attrs' => ['id' => $expected, 'values' => ['type' => 'file']]]);
    })->with([
        'a string id' => ['abc', 'abc'],
        'a numeric id' => [5, '5'],
        'an id that is not a scalar' => [['abc'], ''],
        'no id' => [null, ''],
    ]);

    it('writes an unknown set as a paragraph saying so', function (object $node): void {
        expect((new Set)->toProseMirror($node))->toBe([
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => '[unknown set]']],
        ]);
    })->with([
        'no attributes' => [setNodeObject([])],
        'attributes that are not an object' => [setNodeObject(['attrs' => ['values' => []]])],
        'no values' => [setNodeObject(['attrs' => setNodeObject(['id' => 'abc'])])],
    ]);
});
