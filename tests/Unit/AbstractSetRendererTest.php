<?php

declare(strict_types=1);

/**
 * Tests that `AbstractSetRenderer` renders a Bard set as an opening `div` that
 * names the set type, carries the per-process markup nonce, and writes each
 * scalar value as an escaped `data-set-attr-*` attribute (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/AbstractSetRendererTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets\AbstractSetRenderer;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;

/**
 * A plain object with the given properties, as tiptap-php hands nodes around.
 *
 * @param  array<int|string, mixed>  $properties  The properties
 * @return stdClass The object
 */
function setRendererObject(array $properties): stdClass
{
    $object = new stdClass;

    foreach ($properties as $name => $value) {
        $object->{$name} = $value;
    }

    return $object;
}

beforeEach(function (): void {
    SetMarkupNonce::reset();
});

afterEach(function (): void {
    SetMarkupNonce::reset();
});

/**
 * A concrete renderer for the given set type.
 *
 * @param  string  $setType  The set type
 * @return AbstractSetRenderer The renderer
 */
function setRenderer(string $setType): AbstractSetRenderer
{
    return new class($setType) extends AbstractSetRenderer {};
}

/**
 * The opening tag rendered for a node.
 *
 * @param  string  $setType  The set type
 * @param  object  $node  The set node
 * @return string The tag
 */
function setRendererTag(string $setType, object $node): string
{
    $tag = setRenderer($setType)->renderHTML($node)['tag'] ?? null;

    return \is_string($tag) ? $tag : '';
}

it('writes each scalar value as a data attribute, after the type and nonce', function (): void {
    $tag = setRendererTag('video', setRendererObject(['attrs' => setRendererObject(['values' => [
        'url' => 'https://example.org/v',
        'width' => 640,
        'autoplay' => true,
        'muted' => false,
    ]])]));

    expect($tag)->toBe(
        '<div data-set-type="video"'.SetMarkupNonce::attribute()
        .' data-set-attr-url="https://example.org/v" data-set-attr-width="640"'
        .' data-set-attr-autoplay="1" data-set-attr-muted="">'
    );
});

it('skips the type key, nulls and non-scalar values, and keeps going past them', function (): void {
    $tag = setRendererTag('video', setRendererObject(['attrs' => setRendererObject(['values' => [
        'type' => 'video',
        'caption' => null,
        'tracks' => ['a'],
        'object' => setRendererObject([]),
        'url' => 'u',
    ]])]));

    expect($tag)->toBe('<div data-set-type="video"'.SetMarkupNonce::attribute().' data-set-attr-url="u">');
});

it('escapes the set type, keys and values', function (): void {
    $tag = setRendererTag('a"b', setRendererObject(['attrs' => setRendererObject(['values' => ['x"y' => '<b>&']])]));

    expect($tag)->toBe('<div data-set-type="a&quot;b"'.SetMarkupNonce::attribute().' data-set-attr-x&quot;y="&lt;b&gt;&amp;">');
});

it('writes a numeric key as a string', function (): void {
    $tag = setRendererTag('list', setRendererObject(['attrs' => setRendererObject(['values' => ['first']])]));

    expect($tag)->toBe('<div data-set-type="list"'.SetMarkupNonce::attribute().' data-set-attr-0="first">');
});

it('reads values from any iterable', function (): void {
    $tag = setRendererTag('video', setRendererObject(['attrs' => setRendererObject(['values' => new ArrayObject(['url' => 'u'])])]));

    expect($tag)->toBe('<div data-set-type="video"'.SetMarkupNonce::attribute().' data-set-attr-url="u">');
});

it('renders only the type and nonce when there are no usable values, raising no warning', function (object $node): void {
    // Each check must hold before the next reads a property, or PHP warns
    \set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        $tag = setRendererTag('video', $node);
    } finally {
        \restore_error_handler();
    }

    expect($tag)->toBe('<div data-set-type="video"'.SetMarkupNonce::attribute().'>');
})->with([
    'no attrs' => [setRendererObject([])],
    'attrs that are not an object' => [setRendererObject(['attrs' => ['values' => ['url' => 'u']]])],
    'no values' => [setRendererObject(['attrs' => setRendererObject([])])],
    'values that are not iterable' => [setRendererObject(['attrs' => setRendererObject(['values' => 'u'])])],
]);

it('reads a generator as having no values, since it casts the values to an array', function (): void {
    // Characterization: (array) on a generator yields its object properties,
    // which are none, so a generator's values are not written
    $values = (static function (): Generator {
        yield 'url' => 'u';
    })();

    expect(setRendererTag('video', setRendererObject(['attrs' => setRendererObject(['values' => $values])])))
        ->toBe('<div data-set-type="video"'.SetMarkupNonce::attribute().'>');
});

it('carries the same nonce on every render in a process', function (): void {
    $first = setRendererTag('video', setRendererObject([]));
    $second = setRendererTag('video', setRendererObject([]));

    expect($first)->toBe($second)
        ->and($first)->toContain('data-set-nonce="'.SetMarkupNonce::value().'"');
});
