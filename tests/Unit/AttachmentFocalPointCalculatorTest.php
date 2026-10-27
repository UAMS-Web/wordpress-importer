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

/**
 * A class whose unserializing would be visible, to show metadata never instantiates one.
 */
class AttachmentFocalPointWakeProbe
{
    /**
     * Whether any instance has been woken by unserialize().
     */
    public static bool $woken = false;

    /**
     * Record that unserialize() built an instance.
     */
    public function __wakeup(): void
    {
        self::$woken = true;
    }

    /**
     * Whether any instance has been woken since the flag was last cleared.
     */
    public static function woken(): bool
    {
        return self::$woken;
    }
}

it('never instantiates a class named in serialized metadata', function (Closure $read): void {
    // Both readers pass allowed_classes false, so an object in the export stays an
    // incomplete class and no constructor or wakeup hook of the importer's runs
    AttachmentFocalPointWakeProbe::$woken = false;
    $payload = serialize([new AttachmentFocalPointWakeProbe]);
    AttachmentFocalPointWakeProbe::$woken = false;

    $read($payload);

    expect(AttachmentFocalPointWakeProbe::woken())->toBeFalse();
})->with([
    'the dimensions reader' => [static fn (string $payload): mixed => AttachmentFocalPointCalculator::parseFullImageDimensions($payload)],
    'the point reader' => [static fn (string $payload): mixed => AttachmentFocalPointCalculator::parseSerializedPointList($payload)],
]);

it('reads no dimensions from empty, non-array or unusable metadata', function (string $serialized): void {
    expect(AttachmentFocalPointCalculator::parseFullImageDimensions($serialized))->toBeNull();
})->with([
    'empty' => [''],
    'a serialized string' => [serialize('not an array')],
    'a serialized object' => [serialize(new AttachmentFocalPointWakeProbe)],
    'a zero width' => [serialize(['width' => 0, 'height' => 10])],
    'a zero height' => [serialize(['width' => 10, 'height' => 0])],
    'a width under one pixel' => [serialize(['width' => '0.5', 'height' => 10])],
    'a height under one pixel' => [serialize(['width' => 10, 'height' => '0.5'])],
    'a width with units' => [serialize(['width' => '5px', 'height' => 10])],
    'a height with units' => [serialize(['width' => 10, 'height' => '5px'])],
]);

it('reads one-pixel dimensions as integers', function (): void {
    expect(AttachmentFocalPointCalculator::parseFullImageDimensions(serialize(['width' => '1', 'height' => '1'])))
        ->toBe(['width' => 1, 'height' => 1]);
});

it('reads no points from empty or non-array metadata', function (string $serialized): void {
    expect(AttachmentFocalPointCalculator::parseSerializedPointList($serialized))->toBe([]);
})->with([
    'empty' => [''],
    'a serialized string' => [serialize('not a list')],
    'an object whose property is a row' => ['O:3:"Foo":1:{s:1:"a";a:2:{s:1:"x";i:1;s:1:"y";i:2;}}'],
]);

it('skips unusable point rows and keeps reading', function (): void {
    // Each unusable row comes before a usable one, so stopping at it would lose the rest
    $serialized = serialize([
        'not a row',
        ['x' => 5],
        ['y' => 5],
        ['x' => 10, 'y' => 'top'],
        ['x' => 'left', 'y' => 10],
        ['x' => '3', 'y' => 4],
    ]);

    expect(AttachmentFocalPointCalculator::parseSerializedPointList($serialized))->toBe([['x' => 3.0, 'y' => 4.0]]);
});

it('returns the default focus for a dimension of zero, and computes it from one pixel', function (int $width, int $height, string $focus): void {
    expect(AttachmentFocalPointCalculator::buildFocusString(['width' => $width, 'height' => $height], [['x' => 1.0, 'y' => 1.0]]))
        ->toBe($focus);
})->with([
    'a zero width' => [0, 10, '50-50-1'],
    'a zero height' => [10, 0, '50-50-1'],
    'one pixel each way' => [1, 1, '100-100-1'],
]);

it('clamps a focus outside the image to its edges', function (): void {
    $dimensions = ['width' => 1000, 'height' => 1000];

    expect(AttachmentFocalPointCalculator::buildFocusString($dimensions, [['x' => 2000.0, 'y' => -50.0]]))->toBe('100-0-1')
        ->and(AttachmentFocalPointCalculator::buildFocusString($dimensions, [['x' => 0.0, 'y' => 1000.0]]))->toBe('0-100-1')
        ->and(AttachmentFocalPointCalculator::buildFocusString($dimensions, [['x' => -50.0, 'y' => 2000.0]]))->toBe('0-100-1');
});

it('reads a focus between infinite coordinates as zero', function (): void {
    // Characterization: '1e999' and '-1e999' are numeric and read as INF and -INF,
    // so the center is NAN, which the integer cast turns into 0 (#138)
    $points = AttachmentFocalPointCalculator::parseSerializedPointList(serialize([
        ['x' => '1e999', 'y' => '1e999'],
        ['x' => '-1e999', 'y' => '-1e999'],
    ]));

    expect(AttachmentFocalPointCalculator::buildFocusString(['width' => 10, 'height' => 10], $points))->toBe('0-0-1');
});

it('rounds a focus to the nearest percent', function (): void {
    // 0.4 percent rounds down and 0.6 percent rounds up
    expect(AttachmentFocalPointCalculator::buildFocusString(['width' => 1000, 'height' => 1000], [['x' => 4.0, 'y' => 6.0]]))
        ->toBe('0-1-1');
});
