<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use SimpleXMLElement;

/**
 * Resolves a Statamic asset `creator` (photo credit) from WordPress attachment postmeta.
 *
 * Mirrors the `uams-2020` theme's media-credit precedence (`lib/media-credit.php`):
 * the structured `_media_credit` postmeta first, then the EXIF/IPTC `image_meta.credit`
 * carried inside the serialized `_wp_attachment_metadata`. Values are trimmed so a
 * whitespace-only credit is treated as absent (and yields the fallback, then '').
 */
class AttachmentCreditResolver
{
    /**
     * Resolve the photo credit for a WordPress WXR attachment `<item>` element.
     *
     * @param  SimpleXMLElement  $attachment  The attachment item from the export
     * @return string The resolved credit, or an empty string when none is present
     */
    public static function computeFromAttachment(
        SimpleXMLElement $attachment
    ): string {
        // Prefer the structured `_media_credit` postmeta when it carries a value
        $mediaCredit = \trim(
            XpathHelper::getStringValue(
                $attachment,
                "wp:postmeta[wp:meta_key='_media_credit']/wp:meta_value"
            )
        );

        // Return the structured credit when present
        if ($mediaCredit !== '') {
            return $mediaCredit;
        }

        // Fall back to the EXIF/IPTC credit inside the serialized attachment metadata
        $metadataSerialized = XpathHelper::getStringValue(
            $attachment,
            "wp:postmeta[wp:meta_key='_wp_attachment_metadata']/wp:meta_value"
        );

        return self::parseExifCredit($metadataSerialized);
    }

    /**
     * Parse `image_meta.credit` from serialized `_wp_attachment_metadata`.
     *
     * @param  string  $serialized  PHP-serialized attachment metadata array
     * @return string The trimmed EXIF/IPTC credit, or an empty string when absent
     */
    public static function parseExifCredit(
        string $serialized
    ): string {
        // Treat empty meta as no credit
        if ($serialized === '') {
            return '';
        }

        // Unserialize WordPress meta (arrays and scalars only)
        $meta = @\unserialize(
            $serialized,
            ['allowed_classes' => false]
        );

        // Require the nested image_meta array to read a credit from
        if (
            ! \is_array($meta)
            || ! isset($meta['image_meta'])
            || ! \is_array($meta['image_meta'])
        ) {
            return '';
        }

        // Read the credit from the image metadata
        $credit = $meta['image_meta']['credit'] ?? '';

        // Only string credits are usable
        return \is_string($credit) ? \trim($credit) : '';
    }
}
