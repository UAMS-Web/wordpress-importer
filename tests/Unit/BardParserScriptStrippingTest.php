<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\BardParser;

/**
 * Pins the script-stripping invariant the WordPress import relies on (wordpress-importer-archive#492).
 *
 * `BardParser` wraps `ueberdosis/tiptap-php`, whose schema is an allowlist: a
 * node or mark the schema does not declare is discarded during parse. That is
 * what stands between a `<script>` in a WordPress post and an Antlers template
 * that renders Bard raw — but it is a side effect of a third-party library
 * rather than an asserted invariant, so nothing failed if it regressed.
 *
 * These assertions exist in `uams-statamic` too, deliberately. The two copies
 * assert the same property against two separately resolved builds of the
 * parser, which is the point: `statamic/cms` is pinned exactly here, but it
 * requires `ueberdosis/tiptap-php: ^2.0`, so the pin never reaches the parser
 * and only the lockfile holds it steady (wordpress-importer-archive#717). This file is the mechanism that
 * keeps this package's CI faithful to the parser the host actually runs, so it
 * asserts behavior and never a resolved version — a build that stops stripping
 * a vector must go red on the vector itself.
 *
 * The allowlist filters node *types* and *marks* only, never node *attributes*.
 * The importer's custom `set` node is the boundary where that distinction
 * matters, and `tests/Unit/BardSetMarkupTrustTest.php` covers it — this file
 * scopes to the node-and-mark allowlist so a failure here names the right
 * component.
 *
 * The controls carry as much weight as the vectors. Every negative assertion
 * below is paired with something that must survive — anchor text, a
 * neighbouring paragraph, an href — because a parser that returned nothing at
 * all would satisfy "the payload is gone" while destroying every import.
 *
 * @command  php vendor/bin/pest tests/Unit/BardParserScriptStrippingTest.php
 */

/**
 * Encode a parsed document so an assertion can search the whole tree.
 *
 * `JSON_UNESCAPED_SLASHES` is load-bearing rather than cosmetic: the default
 * encoding writes `</script>` as `<\/script>` and `https://evil.test` as
 * `https:\/\/evil.test`, so a `not->toContain()` on either would pass on a
 * document that does carry the payload.
 *
 * @param  array<int|string, mixed>  $document  Parsed ProseMirror document
 * @return string JSON encoding of the document
 */
function bardAllowlistJson(array $document): string
{
    $encoded = \json_encode($document, JSON_UNESCAPED_SLASHES);

    return \is_string($encoded) ? $encoded : '';
}

/**
 * Every `type` value appearing on a node or a mark anywhere in a document.
 *
 * @param  array<int|string, mixed>  $document  Parsed ProseMirror document
 * @return list<string> The node and mark type names, deduplicated
 */
function bardAllowlistTypes(array $document): array
{
    $types = [];

    /** @var list<array<int|string, mixed>> $pending */
    $pending = [$document];

    // Walk breadth-first rather than recursing: order is irrelevant to a
    // membership test, and a flat loop stays legible under PHPStan's level max.
    while (($level = \array_pop($pending)) !== null) {
        foreach ($level as $node) {
            if (! \is_array($node)) {
                continue;
            }

            if (isset($node['type']) && \is_string($node['type'])) {
                $types[] = $node['type'];
            }

            // A mark hangs off its text node, so both keys have to be followed.
            foreach (['content', 'marks'] as $key) {
                if (isset($node[$key]) && \is_array($node[$key])) {
                    $pending[] = $node[$key];
                }
            }
        }
    }

    return \array_values(\array_unique($types));
}

/**
 * The concatenated text of every text node in a document, in document order.
 *
 * @param  array<int|string, mixed>  $document  Parsed ProseMirror document
 * @return string The visible text
 */
function bardAllowlistText(array $document): string
{
    $text = '';

    foreach ($document as $node) {
        if (! \is_array($node)) {
            continue;
        }

        if (($node['type'] ?? null) === 'text' && isset($node['text']) && \is_string($node['text'])) {
            $text .= $node['text'];
        }

        // Recurse rather than flatten: document order is what makes the
        // assertion readable, and a stack would reverse it.
        if (isset($node['content']) && \is_array($node['content'])) {
            $text .= bardAllowlistText($node['content']);
        }
    }

    return $text;
}

/**
 * Every `href` carried by a `link` mark anywhere in a document.
 *
 * @param  array<int|string, mixed>  $document  Parsed ProseMirror document
 * @return list<string> The link hrefs, in document order
 */
function bardAllowlistLinkHrefs(array $document): array
{
    $hrefs = [];

    foreach ($document as $node) {
        if (! \is_array($node)) {
            continue;
        }

        $marks = $node['marks'] ?? null;

        foreach (\is_array($marks) ? $marks : [] as $mark) {
            if (! \is_array($mark) || ($mark['type'] ?? null) !== 'link') {
                continue;
            }

            $attrs = $mark['attrs'] ?? null;
            $href = \is_array($attrs) ? ($attrs['href'] ?? null) : null;

            if (\is_string($href)) {
                $hrefs[] = $href;
            }
        }

        if (isset($node['content']) && \is_array($node['content'])) {
            $hrefs = \array_merge($hrefs, bardAllowlistLinkHrefs($node['content']));
        }
    }

    return $hrefs;
}

it('reads the types, text and hrefs a surviving document carries', function (): void {
    // Every other case in this file leans on these three helpers to prove an
    // absence. A helper that always returned nothing would satisfy all of them
    // and assert nothing, so pin what they see on a document that survives.
    $parsed = (new BardParser)->parse(
        '<p>Sickle cell <strong>clinic</strong>, <a href="https://uams.edu/clinics">book online</a></p>'
    );

    expect(bardAllowlistTypes($parsed))
        ->toContain('paragraph')
        ->toContain('text')
        ->toContain('bold')
        ->toContain('link')
        ->and(bardAllowlistText($parsed))->toBe('Sickle cell clinic, book online')
        ->and(bardAllowlistLinkHrefs($parsed))->toBe(['https://uams.edu/clinics'])
        // Every JSON assertion in this file is a negative one, so an encoder
        // that returned nothing would satisfy all of them. This also pins
        // `JSON_UNESCAPED_SLASHES`: the default encoding writes the href as
        // `https:\/\/uams.edu\/clinics` and this `toContain` would fail.
        ->and(bardAllowlistJson($parsed))->toContain('"href":"https://uams.edu/clinics"');
});

it('discards executable nodes outright', function (string $html): void {
    $parsed = (new BardParser)->parse($html);

    expect(bardAllowlistTypes($parsed))
        ->not->toContain('script')
        ->not->toContain('style')
        ->not->toContain('iframe')
        ->not->toContain('object')
        ->not->toContain('embed')
        ->not->toContain('svg')
        ->and(bardAllowlistText($parsed))->not->toContain('alert(1)')
        ->and(bardAllowlistJson($parsed))
        ->not->toContain('alert(1)')
        ->not->toContain('evil.test');
})->with('bard allowlist executable vectors');

it('removes an executable node without abandoning the parse', function (string $html): void {
    // Every vector above parses to nothing at all today, which leaves its
    // negative assertions technically true of an empty document. Wrapping the
    // same vector in prose proves the node is what was removed, rather than the
    // parse being abandoned the moment one was seen.
    $parsed = (new BardParser)->parse('<p>Sickle cell clinic</p>'.$html.'<p>Call 501-686-1000</p>');

    expect(bardAllowlistText($parsed))->toBe('Sickle cell clinicCall 501-686-1000')
        ->and(bardAllowlistTypes($parsed))->toContain('paragraph')
        ->and(bardAllowlistJson($parsed))
        ->not->toContain('alert(1)')
        ->not->toContain('evil.test');
})->with('bard allowlist executable vectors');

it('drops event-handler attributes while keeping the surrounding text', function (): void {
    $parsed = (new BardParser)->parse('<p onclick="alert(1)">Sickle cell clinic</p>');

    expect(bardAllowlistText($parsed))->toBe('Sickle cell clinic')
        ->and(bardAllowlistJson($parsed))
        ->not->toContain('onclick')
        ->not->toContain('alert(1)');
});

it('does not allowlist the image node, so an onerror payload has nothing to ride', function (): void {
    $withPayload = (new BardParser)->parse('<p><img src=x onerror=alert(1)></p>');
    $withoutPayload = (new BardParser)->parse('<p><img src=x></p>');

    expect(bardAllowlistTypes($withPayload))
        ->not->toContain('image')
        // The paragraph survives, so this is about a document that was parsed
        // rather than one that was thrown away.
        ->toContain('paragraph');

    expect(bardAllowlistJson($withPayload))
        ->not->toContain('onerror')
        ->not->toContain('alert(1)');

    // The payload is not what removed the node, and the name has to say so.
    // `Image` is commented out of `BardParser`'s extension list, so the node is
    // discarded whole and an identical image carrying no handler parses to a
    // byte-identical document. Attribute stripping on a node the schema *does*
    // declare is the `onclick` case above.
    //
    // Registering `Image` inverts this case, and the inverted behavior is
    // correct rather than a regression: measured with the node registered, the
    // parse yields `{"type":"image","attrs":{"src":"x"}}` — the node survives
    // and the handler is dropped. Rewrite this case then; do not read its red
    // as `onerror` stripping having broken.
    expect(bardAllowlistJson($withPayload))->toBe(bardAllowlistJson($withoutPayload));
});

it('strips the link mark from an unsafe scheme but keeps the anchor text', function (string $href): void {
    $parsed = (new BardParser)->parse(\sprintf('<p><a href="%s">Book an appointment</a></p>', $href));

    expect(bardAllowlistLinkHrefs($parsed))->toBe([])
        ->and(bardAllowlistText($parsed))->toBe('Book an appointment')
        ->and(bardAllowlistJson($parsed))->not->toContain('alert(1)');
})->with([
    'javascript' => ['javascript:alert(1)'],
    'mixed-case javascript' => ['JaVaScRiPt:alert(1)'],
    'leading-whitespace javascript' => [' javascript:alert(1)'],
    'data url' => ['data:text/html,<script>alert(1)</script>'],
]);

it('keeps the link mark on a safe scheme', function (string $href): void {
    $parsed = (new BardParser)->parse(\sprintf('<p><a href="%s">Book an appointment</a></p>', $href));

    expect(bardAllowlistLinkHrefs($parsed))->toBe([$href])
        ->and(bardAllowlistText($parsed))->toBe('Book an appointment');
})->with([
    'https' => ['https://uams.edu/clinics'],
    'http' => ['http://uams.edu/clinics'],
    'root-relative' => ['/clinics/sickle-cell'],
    'mailto' => ['mailto:appointments@uams.edu'],
    'tel' => ['tel:+15016861000'],
]);

/**
 * The executable-node vectors, shared by the two tests above so that every one
 * is asserted both bare and surrounded by content that has to survive.
 */
dataset('bard allowlist executable vectors', [
    'bare script' => ['<script>alert(1)</script>'],
    'script smuggled inside svg' => ['<svg><script>alert(1)</script></svg>'],
    'style block' => ['<style>body{background:url(https://evil.test)}</style>'],
    'iframe' => ['<iframe src="https://evil.test"></iframe>'],
    'object' => ['<object data="https://evil.test"></object>'],
    'embed' => ['<embed src="https://evil.test">'],
]);
