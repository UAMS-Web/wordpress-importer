<?php

declare(strict_types=1);

/**
 * Tests that the `CodeBlock` Tiptap node reads a code block's language from its
 * `language-*` class and renders it back on both the `pre` and the `code`
 * element (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/CodeBlockNodeTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\CodeBlock;

/**
 * A plain object with the given properties, as tiptap-php hands nodes around.
 *
 * @param  array<int|string, mixed>  $properties  The properties
 * @return stdClass The object
 */
function codeBlockObject(array $properties): stdClass
{
    $object = new stdClass;

    foreach ($properties as $name => $value) {
        $object->{$name} = $value;
    }

    return $object;
}

/**
 * The `language` attribute definition, whose callbacks tiptap-php calls.
 *
 * @return array<string, mixed> The definition
 */
function codeBlockLanguageAttribute(): array
{
    $attributes = (new CodeBlock)->addAttributes();

    /** @var array<string, mixed> $language */
    $language = $attributes['language'];

    return $language;
}

/**
 * A `pre` element with an optional class.
 *
 * @param  ?string  $class  The class attribute, or null for none
 * @return DOMElement The element
 */
function codeBlockElement(?string $class): DOMElement
{
    $element = (new DOMDocument)->createElement('pre');

    if ($class !== null) {
        $element->setAttribute('class', $class);
    }

    return $element;
}

it('is named codeBlock and parses pre elements, keeping their whitespace', function (): void {
    expect(CodeBlock::$name)->toBe('codeBlock')
        ->and((new CodeBlock)->parseHTML())->toBe([['tag' => 'pre', 'preserveWhitespace' => 'full']]);
});

it('defaults to no language', function (): void {
    expect((new CodeBlock)->addOptions())->toBe(['languageClassPrefix' => 'language-', 'defaultLanguage' => 'null'])
        ->and(codeBlockLanguageAttribute()['default'] ?? null)->toBe('null');
});

it('reads the language from a language- class', function (?string $class, string $expected): void {
    $parse = codeBlockLanguageAttribute()['parseHTML'] ?? null;

    expect($parse)->toBeCallable();

    /** @var callable(DOMElement): string $parse */
    expect($parse(codeBlockElement($class)))->toBe($expected);
})->with([
    'a language class among others' => ['wp-block-code language-php', 'php'],
    'no class' => [null, 'null'],
    'a class naming no language' => ['wp-block-code', 'null'],
    'a bare prefix' => ['language-', 'null'],
    'letters and digits only' => ['language-x86-asm', 'x86'],
]);

it('renders the language attribute back as a class', function (stdClass $attributes, string $expected): void {
    $render = codeBlockLanguageAttribute()['renderHTML'] ?? null;

    expect($render)->toBeCallable();

    /** @var callable(stdClass): array<string, string> $render */
    expect($render($attributes))->toBe(['class' => $expected]);
})->with([
    'a language' => [codeBlockObject(['language' => 'js']), 'language-js'],
    'no language' => [codeBlockObject([]), 'language-null'],
    'a non-string language' => [codeBlockObject(['language' => 5]), 'language-null'],
]);

it('renders a pre and a code element carrying the language class, raising no warning', function (mixed $node, string $language): void {
    // An unusable node must fall back to no language without reading a missing property
    \set_error_handler(static function (int $severity, string $message): bool {
        throw new ErrorException($message, 0, $severity);
    });

    try {
        $result = (new CodeBlock)->renderHTML($node, ['id' => 'snippet']);
    } finally {
        \restore_error_handler();
    }

    expect($result)->toBe([
        'pre',
        ['id' => 'snippet', 'class' => "language-{$language}"],
        ['code', ['class' => "language-{$language}"], 0],
    ]);
})->with([
    'a node with a language' => [codeBlockObject(['attrs' => codeBlockObject(['language' => 'php'])]), 'php'],
    'a node with no language' => [codeBlockObject(['attrs' => codeBlockObject([])]), 'null'],
    'a non-string language' => [codeBlockObject(['attrs' => codeBlockObject(['language' => ['php']])]), 'null'],
    'a node with no attributes' => [codeBlockObject([]), 'null'],
    'attributes that are not an object' => [codeBlockObject(['attrs' => ['language' => 'php']]), 'null'],
    'a node that is not an object' => ['node', 'null'],
]);

it('merges the language class into an existing class', function (): void {
    $result = (new CodeBlock)->renderHTML(codeBlockObject(['attrs' => codeBlockObject(['language' => 'php'])]), ['class' => 'wp-block-code']);

    expect($result[1] ?? null)->toBe(['class' => 'wp-block-code language-php']);
});
