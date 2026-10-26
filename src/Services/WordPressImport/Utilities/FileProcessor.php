<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use SimpleXMLElement;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Traits\DisabledUploadHandlingTrait;

/**
 * Utility class for file import operations with graceful handling for disabled
 * uploads.
 *
 * Provides static methods for importing files and handling file operations
 * when uploads are disabled, following the DRY principle and ensuring
 * consistent behavior across the WordPress import system.
 */
class FileProcessor
{
    /**
     * Provides shared functionality for handling disabled uploads.
     *
     * This trait encapsulates common logic for checking upload status,
     * logging disabled upload messages, and finding attachments by ID.
     * It eliminates code duplication between image and file processors.
     */
    use DisabledUploadHandlingTrait;

    /**
     * Import a file with graceful handling for disabled uploads.
     *
     * When file uploads are disabled, returns the original URL instead of attempting
     * to import the file. When uploads are enabled, uses the AssetImporter to import
     * the file to the specified container.
     *
     * @param  string  $fileUrl  The URL of the file to import
     * @param  string  $site  The site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  string  $container  The container to import to (e.g., 'files', 'images', 'portraits')
     * @param  string  $description  Description of the file for logging (optional)
     * @param  string  $context  Context information for logging (e.g., class::method)
     * @param  ?string  $pageUrl  The page URL for context (optional)
     * @param  ?string  $title  Optional title for the file asset
     * @param  ?array<int|string, mixed>  $additionalFileMetadata  Extra asset fields merged after URL and title
     * @param  ?string  $attachmentPageUrl  The WordPress attachment-page URL for migration redirects
     * @return ?string The imported file path or original URL, or null if import failed
     */
    public static function importFileWithDisabledUploadHandling(
        string $fileUrl,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        string $container,
        string $description = 'file',
        string $context = 'FileProcessor::importFileWithDisabledUploadHandling',
        ?string $pageUrl = null,
        ?string $title = null,
        ?array $additionalFileMetadata = null,
        ?string $attachmentPageUrl = null
    ): ?string {
        // Check if file URL is empty
        if ($fileUrl === '') {
            // Return null for empty file URLs without logging (expected behavior)
            return null;
        }

        // Check if file uploads are disabled
        if (
            self::checkUploadsDisabled(
                $assetImporter,
                $output,
                $description,
                'file'
            )
        ) {
            // Return original URL to allow processing pipeline to continue
            return $fileUrl;
        }

        // Import the file using AssetImporter
        return $assetImporter->importFile(
            $fileUrl,
            $site,
            $output,
            $container,
            SameSitePolicy::SKIP_DIFFERENT_SITE,
            $pageUrl,
            $title,
            $additionalFileMetadata,
            $attachmentPageUrl
        );
    }

    /**
     * Selects the image metadata an attachment carries for its imported asset.
     *
     * `XpathHelper::extractAttachmentData()` resolves `alt`, `caption`, and `creator`
     * (the latter via `AttachmentCreditResolver`, covering both `_media_credit` and the
     * EXIF `image_meta.credit` fallback). Only those three are returned: `url` and
     * `title` are written by `MetadataSetter::setFileMetadata()`'s own parameters, and
     * `focus` is not a field the file containers define.
     *
     * A blank or non-string value is dropped rather than written, so an attachment that
     * carries no caption cannot blank a curated one on re-import (wordpress-importer-archive#435).
     *
     * @param  array<int|string, mixed>  $attachmentData  Payload from `XpathHelper::extractAttachmentData()`
     * @return array<string, string> Non-empty image metadata, keyed by asset field handle
     */
    public static function buildAttachmentImageMetadata(
        array $attachmentData
    ): array {
        $metadata = [];

        // Carry only the handles the attachment payload actually resolves
        foreach (['alt', 'caption', 'creator'] as $handle) {
            $value = $attachmentData[$handle] ?? null;

            // Skip anything that is not a string rather than casting it
            if (! \is_string($value)) {
                continue;
            }

            // ---
            // Normalize exactly as MetadataSetter::setImageMetadata() does. The same
            // attachment can reach the `images` container through either sink — a
            // featured image takes this file path, an inline image takes the image path —
            // so skipping the strip here would store two incompatible conventions in one
            // container, and leave raw markup and double-escaped entities in what are
            // `textarea` fields.
            // ---
            $value = HtmlTagStripper::strip($value);

            // Skip a blank value so it cannot overwrite a curated one
            if ($value === '') {
                continue;
            }

            $metadata[$handle] = $value;
        }

        return $metadata;
    }

    /**
     * Import a file from attachment data with graceful handling for disabled uploads.
     *
     * When file uploads are disabled, returns the original URL instead of attempting
     * to import the file. When uploads are enabled, uses the AssetImporter to import
     * the file to the specified container.
     *
     * @param  string  $attachmentId  The WordPress attachment ID
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  string  $site  The site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  string  $container  The container to import to (e.g., 'files', 'images', 'portraits')
     * @param  string  $description  Description of the file for logging (optional)
     * @param  string  $context  Context information for logging (e.g., class::method)
     * @param  ?string  $pageUrl  The page URL for context (optional)
     * @return ?string The imported file path or original URL, or null if import failed
     */
    public static function importFileFromAttachmentWithDisabledUploadHandling(
        string $attachmentId,
        array $attachments,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        string $container,
        string $description = 'file',
        string $context = 'FileProcessor::importFileFromAttachmentWithDisabledUploadHandling',
        ?string $pageUrl = null
    ): ?string {
        // Check if attachment ID is empty
        if ($attachmentId === '') {
            // Return null for empty attachment IDs without logging (expected behavior)
            return null;
        }

        // Find attachment by ID using shared logic
        $attachmentData = self::findAttachmentById(
            $attachmentId,
            $attachments,
            $site,
            $description,
            $context,
            $assetImporter,
            'file'
        );

        // Return null if no attachment found
        if ($attachmentData === null || $attachmentData === []) {
            return null;
        }

        $attachment = $attachmentData['attachment'] ?? null;

        if (! $attachment instanceof SimpleXMLElement) {
            return null;
        }

        // Extract attachment data using the helper method
        $attachmentDataExtracted = XpathHelper::extractAttachmentData(
            $attachment
        );

        // Extract the file URL from the attachment data
        $fileUrl = $attachmentDataExtracted['url'] !== '' && $attachmentDataExtracted['url'] !== null
            ? $attachmentDataExtracted['url']
            : (string) $attachment->link;

        // Extract the title from the attachment data
        $title = $attachmentDataExtracted['title'] ?? null;
        $title = \is_string($title) ? $title : null;

        if (! \is_string($fileUrl)) {
            return null;
        }

        // Use the file import helper
        // ---
        // Carry the attachment's alt / caption / creator onto the asset. Containers other
        // than `images` take this file path, whose metadata step previously wrote only the
        // source URL and title — so a portrait's alt text, caption, and photo credit were
        // dropped on import (wordpress-importer-archive#435). `MetadataSetter` drops any handle the destination
        // container's blueprint does not define.
        // ---
        $imageMetadata = self::buildAttachmentImageMetadata($attachmentDataExtracted);

        // ---
        // The attachment's `<link>` is its WordPress attachment PAGE url, distinct from
        // the file url above. The migration redirect generator needs it to redirect
        // `/<post>/<attachment>/` requests (wordpress-importer-archive#446).
        // ---
        $attachmentPageUrl = \trim((string) $attachment->link);

        return self::importFileWithDisabledUploadHandling(
            $fileUrl,
            $site,
            $assetImporter,
            $output,
            $container,
            $description,
            $context,
            $pageUrl,
            $title,
            $imageMetadata === [] ? null : $imageMetadata,
            $attachmentPageUrl === '' ? null : $attachmentPageUrl
        );
    }
}
