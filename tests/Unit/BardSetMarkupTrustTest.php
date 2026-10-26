<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BardParser;

/**
 * Tests the trust boundary between importer-generated Bard set markup and set
 * markup that arrived inside WordPress post content (wordpress-importer-archive#494).
 *
 * `data-set-*` is a serialization this package emits itself, so a
 * `div[data-set-type]` in a WordPress post was typed by an author. Parsing it
 * copied attacker-controlled raw HTML into an entry field the host app renders
 * unescaped. Both directions are asserted here: the payload must be dropped,
 * and genuinely generated markup must still import.
 *
 * @command  php vendor/bin/pest tests/Unit/BardSetMarkupTrustTest.php
 */
afterEach(function (): void {
    // Leave no nonce behind for a later test in this process
    SetMarkupNonce::reset();
});

/**
 * Encode a parsed Bard document so assertions can search the whole tree.
 *
 * `JSON_UNESCAPED_SLASHES` is load-bearing rather than cosmetic: the default
 * encoding writes `</script>` as `<\/script>`, so a `toContain()` on a closing
 * tag silently never matches and the assertion passes on a document that does
 * carry the payload.
 *
 * @param  array<int|string, mixed>  $document  Parsed ProseMirror document
 * @return string JSON encoding of the document
 */
function bardSetMarkupJson(array $document): string
{
    $encoded = \json_encode($document, JSON_UNESCAPED_SLASHES);

    return \is_string($encoded) ? $encoded : '';
}

/**
 * Return a parsed node's `type`, or null when it carries none.
 *
 * `BardParser::parse()` is typed `array<int|string, mixed>`, so every level of
 * the document reads as `mixed`; narrow here rather than at each assertion.
 *
 * @param  mixed  $node  A node from a parsed document
 * @return string|null The node type when present
 */
function bardNodeType($node): ?string
{
    if (! \is_array($node)) {
        return null;
    }

    $type = $node['type'] ?? null;

    return \is_string($type) ? $type : null;
}

/**
 * Extract a parsed set node's `attrs.values` payload.
 *
 * @param  array<int|string, mixed>  $document  Parsed ProseMirror document
 * @param  int  $index  Node offset within the document
 * @return array<int|string, mixed> The node's set values, empty when absent
 */
function bardSetValues(array $document, int $index = 0): array
{
    $node = $document[$index] ?? null;

    if (! \is_array($node)) {
        return [];
    }

    $attrs = $node['attrs'] ?? null;

    if (! \is_array($attrs)) {
        return [];
    }

    $values = $attrs['values'] ?? null;

    return \is_array($values) ? $values : [];
}

it('drops set markup planted in WordPress post content', function (string $html, string $payload): void {
    $document = (new BardParser)->parse($html);

    // The payload must not survive anywhere in the written document
    expect(bardSetMarkupJson($document))->not->toContain($payload);

    // ---
    // No set node may be minted from author-typed markup at all.
    //
    // This pins the tag selector specifically. Rejecting inside `getAttrs`
    // strips the payload but still mints an attribute-less `set`, so without
    // this assertion the selector could regress to `div[data-set-type]`
    // undetected.
    // ---
    expect(\array_map(bardNodeType(...), $document))->not->toContain('set');
})->with([
    'script tag in a pull_quote' => [
        '<div data-set-type="pull_quote" data-set-attr-quote="&lt;script&gt;alert(1)&lt;/script&gt;" data-set-attr-author="Evil"></div>',
        '<script>alert(1)</script>',
    ],
    'img onerror in a gallery heading' => [
        '<div data-set-type="gallery" data-set-attr-heading="&lt;img src=x onerror=alert(1)&gt;"></div>',
        '<img src=x onerror=alert(1)>',
    ],
    'script tag in a reusable_content body' => [
        '<div data-set-type="reusable_content" data-set-attr-content="&lt;script&gt;alert(1)&lt;/script&gt;"></div>',
        '<script>alert(1)</script>',
    ],
    'integer attribute carrying markup' => [
        '<div data-set-type="people" data-set-integer-limit="&lt;script&gt;alert(1)&lt;/script&gt;"></div>',
        '<script>alert(1)</script>',
    ],
]);

it('keeps the content surrounding planted set markup', function (): void {
    $document = (new BardParser)->parse(
        '<p>Kept before</p>'
        .'<div data-set-type="pull_quote" data-set-attr-quote="x">Visible author text</div>'
        .'<p>Kept after</p>'
    );

    $json = bardSetMarkupJson($document);

    // Rejecting the set must not take the author's own words with it
    expect($json)->toContain('Kept before')
        ->and($json)->toContain('Visible author text')
        ->and($json)->toContain('Kept after');
});

it('parses set markup this importer generated', function (): void {
    $document = (new BardParser)->parse(
        '<div data-set-type="pull_quote"'.SetMarkupNonce::attribute()
        .' data-set-attr-quote="Real quote" data-set-attr-author="Jane"></div>'
    );

    expect($document)->toHaveCount(1)
        ->and(bardSetValues($document))->toBe([
            'type' => 'pull_quote',
            'quote' => 'Real quote',
            'author' => 'Jane',
        ]);
});

it('still decodes the generated base64 array attributes', function (): void {
    $images = \json_encode(['one.jpg', 'two.jpg']);

    $document = (new BardParser)->parse(
        '<div data-set-type="gallery"'.SetMarkupNonce::attribute()
        .' data-set-attr-heading="Gallery"'
        ." data-set-attr-images='".\base64_encode(\is_string($images) ? $images : '')."'"
        .' data-set-attr-more_query="false"></div>'
    );

    expect(bardSetValues($document))->toBe([
        'type' => 'gallery',
        'heading' => 'Gallery',
        'images' => ['one.jpg', 'two.jpg'],
        'more_query' => false,
    ]);
});

it('leaves no set node behind when the markup is not trusted', function (string $html): void {
    $document = (new BardParser)->parse($html);

    // ---
    // An attribute-less `set` is unrenderable by the host app's set partials,
    // so untrusted markup must contribute no structural node at all.
    //
    // Assert over the mapped list rather than looping: several of these inputs
    // parse to an empty document, and a `foreach` body that never runs makes no
    // assertion at all — PHPUnit reports that as risky, and it is exactly the
    // vacuous-test shape this file exists to avoid.
    // ---
    $types = \array_map(bardNodeType(...), $document);

    expect($types)->not->toContain('set');
})->with([
    'forged nonce' => ['<div data-set-type="pull_quote" data-set-nonce="x" data-set-attr-quote="q"></div>'],
    'forged nonce wrapping author text' => ['<div data-set-type="pull_quote" data-set-nonce="x">Author text</div>'],
    'unrecognized set type (predates the nonce)' => ['<div data-set-type="evil_type" data-set-nonce="x"></div>'],
]);

it('keeps author text that a forged-nonce shell wrapped', function (): void {
    $document = (new BardParser)->parse(
        '<div data-set-type="pull_quote" data-set-nonce="x">Author text</div>'
    );

    expect(bardSetMarkupJson($document))->toContain('Author text');
});

it('rejects set markup carrying a forged nonce', function (): void {
    $document = (new BardParser)->parse(
        '<div data-set-type="pull_quote" data-set-nonce="deadbeefdeadbeefdeadbeefdeadbeef"'
        .' data-set-attr-quote="&lt;script&gt;alert(1)&lt;/script&gt;"></div>'
    );

    expect(bardSetMarkupJson($document))->not->toContain('<script>alert(1)</script>');
});

it('rejects set markup stamped by an earlier run', function (): void {
    // Stamp the markup, then roll the nonce as a new process would
    $html = '<div data-set-type="pull_quote"'.SetMarkupNonce::attribute()
        .' data-set-attr-quote="Real quote"></div>';

    SetMarkupNonce::reset();

    $document = (new BardParser)->parse($html);

    expect(bardSetMarkupJson($document))->not->toContain('Real quote');
});
