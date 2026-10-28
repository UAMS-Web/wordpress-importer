<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Assets;

use Illuminate\Support\Facades\Log;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Facades\Asset;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Sets metadata (alt text, caption, exempt_from_alt, original_wordpress_url, focus, creator)
 * for Statamic assets, and merges extra fields without dropping existing keys.
 *
 * Every sink here merges into the asset's existing data rather than replacing it.
 * `Statamic\Assets\Asset::data()` is a full replace — it records every key absent
 * from the argument in `$removedData` — so one attachment reached by both sinks
 * (a featured image that also appears inline) used to lose whatever the other sink
 * had written. `title` is written only by `setFileMetadata()` and
 * `wordpress_attachment_page_url` by both, so a second import through the image
 * sink erased them. wordpress-importer-archive#489
 */
class MetadataSetter
{
    /**
     * Sets metadata for image assets including alt text, caption, and original
     * WordPress URL.
     *
     * @param  string  $path  The asset path in the container
     * @param  string  $container  The asset container name
     * @param  ?string  $alt  The alt text for the image
     * @param  ?string  $caption  The caption for the image
     * @param  ?string  $originalUrl  The original WordPress URL of the asset
     * @param  ?string  $focus  Statamic focal string `{x}-{y}-1` from attachment meta
     * @param  ?string  $creator  Photo credit from WordPress `_media_credit`/EXIF meta
     * @param  ?string  $attachmentPageUrl  The WordPress attachment-page URL, stamped unfiltered
     */
    public function setImageMetadata(
        string $path,
        string $container,
        ?string $alt,
        ?string $caption,
        ?string $originalUrl = null,
        ?string $focus = null,
        ?string $creator = null,
        ?string $attachmentPageUrl = null
    ): void {
        // Find asset by container and path
        $asset = $this->findWritableAsset("{$container}::{$path}");

        // Check if asset exists and container is images
        if (
            $asset instanceof StatamicAsset
            && $container === 'images'
        ) {
            // Initialize metadata array with exempt_from_alt default
            $data = ['exempt_from_alt' => false];

            // Check if alt text is provided
            if (
                $alt !== null
                && $alt !== ''
            ) {
                // Strip HTML tags from alt text and add to metadata
                $data['alt'] = HtmlTagStripper::strip($alt);
            }

            // Check if caption is provided
            if (
                $caption !== null
                && $caption !== ''
            ) {
                // Strip HTML tags from caption and add to metadata
                $data['caption'] = HtmlTagStripper::strip($caption);
            }

            // Check if original URL is provided
            if (
                $originalUrl !== null
                && $originalUrl !== ''
            ) {
                // Add original WordPress URL to metadata
                $data['original_wordpress_url'] = $originalUrl;
            }

            // Check if focal point string is provided
            if (
                $focus !== null
                && $focus !== ''
            ) {
                // Store Statamic-compatible focus (percent-percent-zoom)
                $data['focus'] = $focus;
            }

            // Check if a photo credit is provided
            if (
                $creator !== null
                && $creator !== ''
            ) {
                // Strip HTML tags from the credit and add to metadata
                $data['creator'] = HtmlTagStripper::strip($creator);
            }

            // ---
            // Stamp the attachment's own page URL so the migration redirect generator can
            // synthesize `/<post>/<attachment>/` redirects. Inline body images are the
            // bulk of WordPress attachments and reach only this sink, so wordpress-importer-archive#446's file-side
            // stamp left most assets without the key (wordpress-importer-archive#489).
            // ---
            $normalizedPageUrl = self::normalizeAttachmentPageUrl($attachmentPageUrl);

            if ($normalizedPageUrl !== null) {
                $data['wordpress_attachment_page_url'] = $normalizedPageUrl;
            }

            // Merge over the asset's existing data so a second import through this sink
            // cannot drop what the file sink wrote (`title`, and the page URL when this
            // import did not resolve one)
            $this->writeMergedAssetData(
                $asset,
                $data
            );
        } else { // Otherwise
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'path' => $path,
                    'container' => $container,
                    'asset_exists' => $asset instanceof StatamicAsset,
                    'is_images_container' => $container === 'images',
                ]
            );

            // Log warning when metadata setting fails
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'MetadataSetter::setImageMetadata: Failed to set metadata for asset',
                    $logData['page_url']
                ),
                $logData['context']
            );
        }
    }

    /**
     * Drops attachment image metadata whose handle the asset's blueprint does not define.
     *
     * Asset containers do not share a blueprint — `portraits` defines `alt`, `caption`,
     * and `creator` while a plain `files` container defines none of them — so metadata
     * resolved from an attachment has to be gated per destination rather than written
     * blind.
     *
     * Only the handles wordpress-importer-archive#435 introduced are gated. Filtering every extra regressed callers
     * that legitimately write undeclared keys: `AudioPostAssetMetadata::defaults()` sets
     * `creator_organization` on audio assets, which `audio.yaml` does not declare, and a
     * blanket filter silently dropped it. Pre-existing extras therefore pass through
     * untouched, exactly as before this change.
     *
     * @param  StatamicAsset  $asset  The asset whose blueprint gates the handles
     * @param  array<int|string, mixed>  $metadata  Caller-supplied metadata
     * @return array<int|string, mixed> Metadata limited to handles the blueprint defines
     */
    private function filterToBlueprintFields(
        StatamicAsset $asset,
        array $metadata
    ): array {
        // ---
        // The handles this gate owns. Anything else a caller supplies is its own concern
        // and is passed through, so gating cannot regress an established mapping.
        // ---
        $gatedHandles = [
            'alt',
            'caption',
            'creator',
        ];

        // Nothing to gate when the caller supplied none of them
        if (\array_intersect(\array_keys($metadata), $gatedHandles) === []) {
            return $metadata;
        }

        // Resolve the blueprint of the asset's container
        $blueprint = $asset->blueprint();

        // Drop only a gated handle the container does not define; pass the rest through
        foreach ($gatedHandles as $handle) {
            // Unsetting a handle the caller did not supply changes nothing
            if ($blueprint->hasField($handle) !== true) {
                unset($metadata[$handle]);
            }
        }

        return $metadata;
    }

    /**
     * Sets metadata for file assets including the original WordPress URL,
     * title, and optional extra fields.
     *
     * @param  string  $path  The asset path in the container
     * @param  string  $container  The asset container name
     * @param  ?string  $originalUrl  The original WordPress URL of the asset
     * @param  ?string  $title  The title for the file asset
     * @param  ?array<int|string, mixed>  $additionalMetadata  Extra asset data merged after URL and title
     * @param  ?string  $attachmentPageUrl  The WordPress attachment-page URL, stamped unfiltered
     */
    public function setFileMetadata(
        string $path,
        string $container,
        ?string $originalUrl = null,
        ?string $title = null,
        ?array $additionalMetadata = null,
        ?string $attachmentPageUrl = null
    ): void {
        // Find asset by container and path
        $asset = $this->findWritableAsset("{$container}::{$path}");

        // Check if asset exists
        if ($asset instanceof StatamicAsset) {
            // Initialize empty metadata array
            $data = [];

            // Check if original URL is provided
            if (
                $originalUrl !== null
                && $originalUrl !== ''
            ) {
                // Add original WordPress URL to metadata
                $data['original_wordpress_url'] = $originalUrl;
            }

            // ---
            // Stamp the attachment's own page URL so the migration redirect generator can
            // synthesize `/<post>/<attachment>/` redirects. Set alongside
            // `original_wordpress_url` rather than through the filtered extras: both are
            // loose migration keys no asset blueprint declares, so the blueprint filter
            // applied to those extras would drop it (wordpress-importer-archive#446).
            // ---
            $normalizedPageUrl = self::normalizeAttachmentPageUrl($attachmentPageUrl);

            if ($normalizedPageUrl !== null) {
                $data['wordpress_attachment_page_url'] = $normalizedPageUrl;
            }

            // Check if title is provided
            if (
                $title !== null
                && $title !== ''
            ) {
                // Add title to metadata
                $data['title'] = $title;
            }

            // Check if additional metadata is provided
            if (
                $additionalMetadata !== null
                && $additionalMetadata !== []
            ) {
                // ---
                // Drop any handle the destination container's blueprint does not define,
                // so routing image metadata to non-`images` containers cannot push a
                // stray key onto a container that lacks the field (wordpress-importer-archive#435). Only the
                // caller-supplied extras are filtered — the URL and title above are
                // long-established on every file container.
                // ---
                $data = [
                    ...$data,
                    ...$this->filterToBlueprintFields(
                        $asset,
                        $additionalMetadata
                    ),
                ];
            }

            // Check if metadata array has any data
            if ($data !== []) {
                // Merge over the asset's existing data so a second import through this
                // sink cannot drop what the image sink wrote (`alt`, `caption`, `focus`,
                // `creator`, `exempt_from_alt`)
                $this->writeMergedAssetData(
                    $asset,
                    $data
                );
            }
        } else { // Otherwise
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'path' => $path,
                    'container' => $container,
                    'asset_exists' => false,
                ]
            );

            // Log warning when metadata setting fails
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'MetadataSetter::setFileMetadata: Failed to set metadata for asset',
                    $logData['page_url']
                ),
                $logData['context']
            );
        }
    }

    /**
     * Validates and trims a WordPress attachment-page URL before it is stamped.
     *
     * The value comes straight from the WXR `<link>`, which is attacker-influenced
     * input, and it is stored for the migration redirect generator to turn into a
     * redirect target. Requiring an absolute `http`/`https` URL keeps a `javascript:`
     * or `data:` scheme — or a stray fragment of markup — from ever reaching that
     * generator, and rules out the attribute-breaking characters a raw value could
     * otherwise carry into a rendered page.
     *
     * @param  ?string  $attachmentPageUrl  The raw `<link>` value
     * @return ?string The trimmed URL, or null when it is absent or unusable
     */
    private static function normalizeAttachmentPageUrl(
        ?string $attachmentPageUrl
    ): ?string {
        // Nothing supplied
        if ($attachmentPageUrl === null) {
            return null;
        }

        // Trim so a padded export value still stamps
        $trimmed = \trim($attachmentPageUrl);

        // Require an absolute http(s) URL, which a redirect target has to be. An
        // empty value fails it too
        if (
            \preg_match(
                '#^https?://[^\s"\'<>`\\\\]+$#i',
                $trimmed
            ) !== 1
        ) {
            return null;
        }

        // Return the validated URL
        return $trimmed;
    }

    /**
     * Merges values over an asset's existing data and persists it.
     *
     * The single write path for every sink in this class. `Asset::data()` replaces
     * wholesale — it records each key absent from the argument in `$removedData` —
     * so writing a partial payload directly would delete every key the caller did
     * not happen to resolve. Reading the current data first and spreading the new
     * values over it makes each sink additive, which is what lets two sinks touch
     * one attachment in either order without either losing the other's keys. wordpress-importer-archive#489
     *
     * @param  StatamicAsset  $asset  The asset to update
     * @param  array<int|string, mixed>  $metadata  Values merged over the asset's existing data
     */
    private function writeMergedAssetData(
        StatamicAsset $asset,
        array $metadata
    ): void {
        // Initialize merged data from existing asset values
        $merged = [
            ...$this->getExistingAssetData($asset),
            ...$metadata,
        ];

        // Update the asset with merged data
        $asset->data($merged);

        // Persist the asset metadata to storage
        $asset->save();

        // Unset local variables to free up memory
        unset($merged);
    }

    /**
     * Resolve a Statamic asset instance that supports data() and save().
     *
     * @param  string  $id  Asset ID in container::path form
     * @return StatamicAsset|null Writable asset, or null if not found
     */
    private function findWritableAsset(string $id): ?StatamicAsset
    {
        $asset = Asset::find($id);

        return $asset instanceof StatamicAsset ? $asset : null;
    }

    /**
     * Read existing asset data as a plain array for merging.
     *
     * @param  StatamicAsset  $asset  The asset whose data should be read
     * @return array<int|string, mixed> Existing asset data, or an empty array
     */
    private function getExistingAssetData(StatamicAsset $asset): array
    {
        // Read the asset's current data object
        $assetData = $asset->data();

        // Return an empty array when data() did not yield a readable object
        if (
            ! is_object($assetData)
            || ! method_exists(
                $assetData,
                'all'
            )
        ) {
            return [];
        }

        // Collect existing values into a plain array
        $existingData = $assetData->all();

        return is_array($existingData)
            ? $existingData
            : [];
    }
}
