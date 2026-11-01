<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;

/**
 * Decides whether an imported featured image lands with a photo credit.
 *
 * The Statamic `featured_image_credit_query` toggle surfaces an entry's
 * featured-image credit wherever another block renders that entry's featured
 * image — news listings and the post / clinical-resource / knowledge-base card
 * lists (`UAMS-Web/uams-statamic-archive#1532`). It reaches 15 blueprints through the
 * `uamsweb_post_cover_image` fieldset, and defaults to `false`.
 *
 * That default was chosen for existing hand-authored content, not for migrated
 * content: an imported entry whose featured image carried a credit in WordPress
 * would otherwise land with that attribution hidden. So the importer sets the
 * toggle when — and only when — the asset it just imported actually carries a
 * `creator`, leaving an uncredited image on the blueprint default rather than
 * writing an inert toggle (wordpress-importer-archive#439).
 *
 * The credit is resolved through the same pipeline that writes it, so this
 * answers "did the asset get a `creator`?" rather than approximating it:
 * `AttachmentCreditResolver` supplies `_media_credit` with the EXIF
 * `image_meta.credit` fallback (wordpress-importer-archive#436), and `buildAttachmentImageMetadata()`
 * applies the HTML strip and blank-drop that decide whether the field is
 * written at all.
 *
 * Scope: three collections. `posts` through `PostEntryCreator`, `pages` through
 * the `_thumbnail_id` case in {@see FieldMapper::mapFields()}, and
 * `knowledge_bases` through {@see KbEntryCreator}. The toggle sits beside
 * `featured_image_16_9` on the `uamsweb_post_cover_image` fieldset, and all
 * three write their featured image to that handle — wordpress-importer-archive#433 remapped `pages` and
 * `knowledge_bases` off the retired `featured_image` handle their blueprints
 * stopped defining, which lifted the reason they were excluded here: the toggle
 * would previously have sat beside an orphaned image, exactly the inert toggle
 * wordpress-importer-archive#439 rules out. wordpress-importer-archive#603 extended the rule to them once that landed.
 *
 * The other collections divide on how they take the fieldset, which a grep for
 * the handle does not show. `clinical_resources` imports
 * `uamsweb_post_cover_image` wholesale, so it carries the toggle — but has no
 * importer featured-image write path to set it from. `clinical_expertise`
 * cherry-picks only the three image handles by `field:` reference and so does
 * **not** carry the toggle at all. `people` is excluded by
 * `UAMS-Web/uams-statamic-archive#1532` — portraits are not editorial media.
 */
class FeaturedImageCreditResolver
{
    /**
     * Determine whether a WordPress attachment yields an asset with a `creator`.
     *
     * @param  string  $attachmentId  The WordPress attachment (thumbnail) id
     * @param  array<int|string, mixed>  $attachments  Attachment `<item>` elements from the export
     * @return bool True when the imported asset carries a non-empty photo credit
     */
    public static function hasCredit(
        string $attachmentId,
        array $attachments
    ): bool {
        return self::resolve($attachmentId, $attachments) !== '';
    }

    /**
     * Resolve the photo credit an imported featured image lands with.
     *
     * @param  string  $attachmentId  The WordPress attachment (thumbnail) id
     * @param  array<int|string, mixed>  $attachments  Attachment `<item>` elements from the export
     * @return string The credit written to the asset, or an empty string when there is none
     */
    public static function resolve(
        string $attachmentId,
        array $attachments
    ): string {
        // An absent thumbnail id cannot resolve to an attachment
        if (\trim($attachmentId) === '') {
            return '';
        }

        // Locate the attachment the featured image was imported from
        $attachment = XpathHelper::findAttachmentById(
            $attachments,
            $attachmentId
        );

        // An id that matches no exported attachment yields no credit
        if (! $attachment instanceof \SimpleXMLElement) {
            return '';
        }

        // ---
        // Run the attachment through the same metadata pipeline the import uses, so
        // a credit that survives here is exactly a credit the asset received. The
        // strip matters: a value of `<em></em>` resolves non-empty from the postmeta
        // but is dropped before it reaches the asset, and the toggle must follow the
        // asset rather than the postmeta.
        // ---
        $metadata = FileProcessor::buildAttachmentImageMetadata(
            XpathHelper::extractAttachmentData($attachment)
        );

        return $metadata['creator'] ?? '';
    }
}
