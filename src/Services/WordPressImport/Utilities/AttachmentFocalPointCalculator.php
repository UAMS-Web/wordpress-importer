<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use SimpleXMLElement;

/**
 * Derives Statamic asset focal strings from WordPress attachment postmeta (MEAUH-style).
 *
 * Reads `_wp_attachment_metadata` for full image width/height and optional serialized
 * `faces` and `hotspots` lists, then builds a `focus` value `{x}-{y}-1` for Statamic.
 */
class AttachmentFocalPointCalculator
{
    /**
     * Default Statamic focal when dimensions or points are unusable.
     */
    public const string DEFAULT_FOCUS = '50-50-1';

    /**
     * Build Statamic `focus` from a WordPress WXR attachment `<item>` element.
     *
     * @param  SimpleXMLElement  $attachment  The attachment item from the export
     * @return string Statamic focal string `{pct_x}-{pct_y}-1`
     */
    public static function computeFromAttachment(
        SimpleXMLElement $attachment
    ): string {
        // Read serialized attachment metadata for original width and height
        $metadataSerialized = XpathHelper::getStringValue(
            $attachment,
            "wp:postmeta[wp:meta_key='_wp_attachment_metadata']/wp:meta_value"
        );

        // Resolve full-size pixel dimensions when valid
        $dimensions = self::parseFullImageDimensions($metadataSerialized);

        // Normalize faces meta to a point list (empty array if missing or invalid)
        $facesSerialized = XpathHelper::getStringValue(
            $attachment,
            "wp:postmeta[wp:meta_key='faces']/wp:meta_value"
        );

        // Parse faces into coordinate pairs
        $faces = self::parseSerializedPointList($facesSerialized);

        // Normalize hotspots meta to a point list (empty array if missing or invalid)
        $hotspotsSerialized = XpathHelper::getStringValue(
            $attachment,
            "wp:postmeta[wp:meta_key='hotspots']/wp:meta_value"
        );

        // Parse hotspots into coordinate pairs
        $hotspots = self::parseSerializedPointList($hotspotsSerialized);

        // Merge faces first then hotspots (MEAUH order)
        $merged = [
            ...$faces,
            ...$hotspots,
        ];

        // Build Statamic focus string from dimensions and merged points
        return self::buildFocusString(
            $dimensions,
            $merged
        );
    }

    /**
     * Parse top-level width/height from serialized `_wp_attachment_metadata`.
     *
     * @param  string  $serialized  PHP-serialized metadata array
     * @return array{width: int, height: int}|null `width` and `height` as positive
     *                                             ints, or null if invalid
     */
    public static function parseFullImageDimensions(
        string $serialized
    ): ?array {
        // Unserialize WordPress meta (arrays and scalars only); empty meta yields false
        $meta = @\unserialize(
            $serialized,
            ['allowed_classes' => false]
        );

        // Require a top-level array with width and height
        if (! \is_array($meta)) {
            return null;
        }

        // Read width and height from the original attachment metadata
        $width = $meta['width'] ?? null;

        // Read height alongside width
        $height = $meta['height'] ?? null;

        // Reject non-numeric or non-positive width
        if (
            ! \is_numeric($width)
            || (int) $width <= 0
        ) {
            return null;
        }

        // Reject non-numeric or non-positive height
        if (
            ! \is_numeric($height)
            || (int) $height <= 0
        ) {
            return null;
        }

        // Return integer dimensions for percent math
        return [
            'width' => (int) $width,
            'height' => (int) $height,
        ];
    }

    /**
     * Parse a serialized list of face/hotspot items into numeric x/y points.
     *
     * @param  string  $serialized  PHP-serialized array of associative rows
     * @return list<array{x: float, y: float}>
     */
    public static function parseSerializedPointList(
        string $serialized
    ): array {
        // Unserialize WordPress meta (arrays and scalars only); empty meta yields false
        $data = @\unserialize(
            $serialized,
            ['allowed_classes' => false]
        );

        // Require a list/array to iterate
        if (! \is_array($data)) {
            return [];
        }

        // Collect valid coordinate pairs
        $points = [];

        // Walk each row from the serialized list
        foreach ($data as $item) {
            // Keep only associative rows
            if (! \is_array($item)) {
                continue;
            }

            // Read both coordinates; a missing one reads as null
            $x = $item['x'] ?? null;
            $y = $item['y'] ?? null;

            // Skip rows without numeric coordinates (0 is valid)
            if (
                ! \is_numeric($x)
                || ! \is_numeric($y)
            ) {
                continue;
            }

            // Append normalized floating-point pixel coordinates
            $points[] = [
                'x' => (float) $x,
                'y' => (float) $y,
            ];
        }

        // Return the normalized point list
        return $points;
    }

    /**
     * Compute Statamic `focus` from dimensions and merged pixel points.
     *
     * @param  array{width: int, height: int}|null  $dimensions  Full image `width` and
     *                                                           height, or null
     * @param  list<array{x: float, y: float}>  $points  Merged faces then hotspots
     * @return string Statamic focal string `{pct_x}-{pct_y}-1`
     */
    public static function buildFocusString(
        ?array $dimensions,
        array $points
    ): string {
        // Use default focal when there are no points to combine
        if ($points === []) {
            return self::DEFAULT_FOCUS;
        }

        // Use default focal when image dimensions are unusable for percent math;
        // absent dimensions read as zero. For any input the declared shape admits,
        // the two defaults apply together or not at all, so changing one alone
        // leaves the other at zero and the result unchanged.
        // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        $width = $dimensions['width'] ?? 0;
        // @pest-mutate-ignore: DecrementInteger, IncrementInteger
        $height = $dimensions['height'] ?? 0;

        if ($width <= 0 || $height <= 0) {
            return self::DEFAULT_FOCUS;
        }

        // Collect x coordinates for bounding-box math
        $xs = \array_column(
            $points,
            'x'
        );

        // Collect y coordinates for bounding-box math
        $ys = \array_column(
            $points,
            'y'
        );

        // Compute horizontal center of the axis-aligned bounding box
        $centerX = (\min($xs) + \max($xs)) / 2;

        // Compute vertical center of the axis-aligned bounding box
        $centerY = (\min($ys) + \max($ys)) / 2;

        // Convert center x to a clamped integer percent (0 to 100); the cast also
        // turns a NAN center (from opposite infinite coordinates) into 0
        $focusX = (int) \min(
            100,
            \max(
                0,
                \round(
                    ($centerX / $width) * 100
                )
            )
        );

        // Convert center y to a clamped integer percent (0 to 100)
        $focusY = (int) \min(
            100,
            \max(
                0,
                \round(
                    ($centerY / $height) * 100
                )
            )
        );

        // Format Statamic focal with zoom segment fixed to 1
        return "{$focusX}-{$focusY}-1";
    }
}
