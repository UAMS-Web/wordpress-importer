<?php

declare(strict_types=1);

/**
 * Tests MEAUH-style focal derivation from WXR attachment postmeta.
 *
 * @command  php vendor/bin/pest tests/Unit/AttachmentFocalPointCalculatorTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\AttachmentFocalPointCalculator;

/**
 * Load a minimal WXR attachment `<item>` with namespace prefixes registered.
 *
 * @param  string  $innerXml  XML body for `<item>` (postmeta, etc.), no outer `<item>` wrapper
 * @return SimpleXMLElement Parsed `<item>` element for focal calculations
 */
function loadAttachmentItem(string $innerXml): SimpleXMLElement
{
    // Build a namespaced fragment matching WXR exports
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<item xmlns:wp="http://wordpress.org/export/1.2/" '
        .'xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/">'
        .$innerXml
        .'</item>';

    // Parse into SimpleXML for the calculator
    $item = simplexml_load_string($xml);

    expect($item)->toBeInstanceOf(SimpleXMLElement::class);

    // Narrow for the type checker (expect() does not narrow); fixtures are well-formed.
    if (! $item instanceof SimpleXMLElement) {
        throw new RuntimeException('Failed to parse WXR attachment item.');
    }

    return $item;
}

it('reads top-level width and height over sizes', function (): void {
    // Serialize minimal attachment metadata with top-level width and height
    $serialized = serialize([
        'width' => 5472,
        'height' => 3648,
        'sizes' => [
            'medium' => ['width' => 300, 'height' => 200],
        ],
    ]);

    // Read dimensions from serialized WordPress metadata
    $dims = AttachmentFocalPointCalculator::parseFullImageDimensions($serialized);

    expect($dims)->toBe([
        'width' => 5472,
        'height' => 3648,
    ]);
});

it('returns null without positive dimensions', function (): void {
    // Audio attachment metadata without image width/height
    $serialized = 'a:1:{s:8:"filesize";i:123;}';

    // Expect null when width/height are absent
    expect(AttachmentFocalPointCalculator::parseFullImageDimensions($serialized))->toBeNull();
});

it('accepts string coordinates including zero', function (): void {
    // Typical MEAUH serialized hotspot row (numeric strings, optional width)
    $serialized = 'a:1:{i:0;a:3:{s:5:"width";s:3:"402";s:1:"x";s:4:"3874";s:1:"y";s:3:"922";}}';

    // Normalize to floating-point coordinate pairs
    $points = AttachmentFocalPointCalculator::parseSerializedPointList($serialized);

    expect($points)->toBe([
        ['x' => 3874.0, 'y' => 922.0],
    ]);

    // Edge coordinates must remain valid
    $zero = AttachmentFocalPointCalculator::parseSerializedPointList(
        'a:1:{i:0;a:2:{s:1:"x";s:1:"0";s:1:"y";s:1:"0";}}'
    );

    expect($zero)->toBe([['x' => 0.0, 'y' => 0.0]]);
});

it('uses the bounding-box center, not the arithmetic mean', function (): void {
    // Three hotspots from the user spec on a 5472x3648 image
    $dimensions = ['width' => 5472, 'height' => 3648];

    $points = [
        ['x' => 660.0, 'y' => 1688.0],
        ['x' => 2292.0, 'y' => 708.0],
        ['x' => 1666.0, 'y' => 2432.0],
    ];

    // Bounding box center: x (660+2292)/2=1476, y (708+2432)/2=1570 -> 27%-43%
    $focus = AttachmentFocalPointCalculator::buildFocusString($dimensions, $points);

    expect($focus)->toBe('27-43-1');
});

it('returns the default focus when there are no points', function (): void {
    // No MEAUH points should yield Statamic default focal
    expect(AttachmentFocalPointCalculator::buildFocusString(
        ['width' => 100, 'height' => 100],
        []
    ))->toBe(AttachmentFocalPointCalculator::DEFAULT_FOCUS);
});

it('returns the default focus when dimensions are invalid but points exist', function (): void {
    // Points without usable dimensions must not divide by zero or guess
    expect(AttachmentFocalPointCalculator::buildFocusString(
        null,
        [['x' => 10.0, 'y' => 20.0]]
    ))->toBe(AttachmentFocalPointCalculator::DEFAULT_FOCUS);
});

it('computes focus end-to-end from a single-hotspot attachment', function (): void {
    // Minimal WXR attachment with metadata and one hotspot (user example)
    $item = loadAttachmentItem(
        '<wp:postmeta>'
        .'<wp:meta_key><![CDATA[_wp_attachment_metadata]]></wp:meta_key>'
        .'<wp:meta_value><![CDATA[a:2:{s:5:"width";i:5472;s:6:"height";i:3648;}]]></wp:meta_value>'
        .'</wp:postmeta>'
        .'<wp:postmeta>'
        .'<wp:meta_key><![CDATA[hotspots]]></wp:meta_key>'
        .'<wp:meta_value><![CDATA[a:1:{i:0;a:3:{s:5:"width";s:3:"402";s:1:"x";s:4:"3874";s:1:"y";s:3:"922";}}]]></wp:meta_value>'
        .'</wp:postmeta>'
    );

    // End-to-end focal from WXR attachment element
    $focus = AttachmentFocalPointCalculator::computeFromAttachment($item);

    expect($focus)->toBe('71-25-1');
});

it('merges faces before hotspots so the bounding box spans both', function (): void {
    // Face at origin, hotspot at far corner - merged bbox center is image center
    $item = loadAttachmentItem(
        '<wp:postmeta>'
        .'<wp:meta_key><![CDATA[_wp_attachment_metadata]]></wp:meta_key>'
        .'<wp:meta_value><![CDATA[a:2:{s:5:"width";i:200;s:6:"height";i:200;}]]></wp:meta_value>'
        .'</wp:postmeta>'
        .'<wp:postmeta>'
        .'<wp:meta_key><![CDATA[faces]]></wp:meta_key>'
        .'<wp:meta_value><![CDATA[a:1:{i:0;a:2:{s:1:"x";i:0;s:1:"y";i:0;}}]]></wp:meta_value>'
        .'</wp:postmeta>'
        .'<wp:postmeta>'
        .'<wp:meta_key><![CDATA[hotspots]]></wp:meta_key>'
        .'<wp:meta_value><![CDATA[a:1:{i:0;a:2:{s:1:"x";i:200;s:1:"y";i:200;}}]]></wp:meta_value>'
        .'</wp:postmeta>'
    );

    $focus = AttachmentFocalPointCalculator::computeFromAttachment($item);

    expect($focus)->toBe('50-50-1');
});
