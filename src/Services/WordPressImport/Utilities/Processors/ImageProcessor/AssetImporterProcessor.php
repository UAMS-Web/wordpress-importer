<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor;

use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Traits\DisabledUploadHandlingTrait;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Imports image assets for Statamic.
 *
 * Uses AssetImporter to import images based on attachment IDs or src URLs,
 * preferring full-size images. Relies on AssetImporter's internal caching for
 * both file and image caches to ensure reuse across pages, avoiding direct cache
 * manipulation.
 */
class AssetImporterProcessor implements ImageProcessingStepInterface
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
     * Imports an image asset and stores its path.
     *
     * When image uploads are disabled, uses the original URL as the image path to
     * allow the processing pipeline to continue. Otherwise, attempts to import
     * using importImage if attachment ID is provided. Falls back to importImage
     * with a matched attachment ID or importFile with the src URL if no ID is
     * found. Logs warnings for missing src or import failures and detailed info
     * for fallback imports to aid debugging.
     *
     * @param  array<int|string, mixed>  $context  The processing context with 'src', 'blockAttributes'
     * @param  string  $site  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  ?string  $pageSlug  The page slug for logging
     * @return array<int|string, mixed> The updated context with 'imagePath' and 'attachmentId'
     */
    public function process(
        array $context,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?string $pageSlug = null
    ): array {
        // Check if source URL is provided in context
        if (! isset($context['src']) || ! \is_string($context['src']) || $context['src'] === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['page_slug' => $pageSlug]
            );

            // Log warning about missing src
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'AssetImporterProcessor::process: Missing src in context',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return context without imagePath to skip processing
            return $context;
        }

        // Check if image uploads are disabled and use original URL if so
        if (
            self::checkUploadsDisabled(
                $assetImporter,
                $output,
                'image processing',
                'image'
            )
        ) {
            // Set the original URL as the image path to allow pipeline to continue
            $context['imagePath'] = $context['src'];

            // Preserve attachment ID if available for metadata purposes
            $blockAttributes = $context['blockAttributes'] ?? null;
            $context['attachmentId'] = \is_array($blockAttributes)
                ? ($blockAttributes['id'] ?? null)
                : null;

            // Return updated context to continue processing pipeline
            return $context;
        }

        // Initialize image path variable for storing import result
        $imagePath = null;

        // Extract attachment ID from block attributes if available (e.g., wp-image-562)
        $blockAttributes = $context['blockAttributes'] ?? null;
        $attachmentId = \is_array($blockAttributes)
            ? ($blockAttributes['id'] ?? null)
            : null;

        // Extract source URL from context
        $src = $context['src'];

        // Get the base blog URL for same-site comparison
        $baseBlogUrl = $assetImporter->getBaseBlogUrl();

        // Check if image URL is on the same site
        $isSameSite = AssetUtils::isUrlOnSameSite(
            $src,
            $baseBlogUrl
        );

        // ---
        // Resolve effective attachment ID: prefer URL match so displayed image matches
        // when content was updated in WordPress (e.g., image replaced but class not).
        // ---

        // Start with the class ID; overwrite if URL match is found
        $effectiveAttachmentId = $attachmentId;

        // Only try URL lookup when same-site
        if ($isSameSite) {
            // Look up attachment whose wp:attachment_url matches the img src
            $attachmentByUrl = XpathHelper::findAttachmentByUrl(
                $attachments,
                $src
            );

            // Use the URL-matched attachment when found
            if ($attachmentByUrl instanceof SimpleXMLElement) {
                // Resolve effective ID from the matched attachment's post_id
                $effectiveAttachmentId = XpathHelper::getStringValue(
                    $attachmentByUrl,
                    'wp:post_id'
                );
            }
        }

        // ---
        // Attempt to import using effective attachment ID only if available and on same
        // site
        // ---

        // Proceed with import when we have an ID and same site
        if (
            \is_scalar($effectiveAttachmentId)
            && (string) $effectiveAttachmentId !== ''
            && $isSameSite
        ) {
            // ---
            // Attempt to import full-size image using attachment ID only if on same site.
            //
            // Note: hasFallbackUrl is true because we have $src available for fallback.
            // ---
            $imagePath = $assetImporter->importImage(
                (string) $effectiveAttachmentId,
                $site,
                $attachments,
                $output,
                SameSitePolicy::ALWAYS_IMPORT,
                null,
                'images',
                true
            );
        }

        // Check if fallback to alternative import methods is needed
        if ($imagePath === null || $imagePath === '') {
            // Initialize full-size URL with the source URL
            $fullSizeUrl = $src;

            // Check if attachment matching only for same-site images is needed
            if ($isSameSite) {
                // Find full-size URL by matching attachment (only for same site)

                // Initialize variables for attachment matching
                $matchedAttachmentId = null;

                // Loop through attachments to find matching full-size URL
                foreach ($attachments as $attachment) {
                    if (! ($attachment instanceof SimpleXMLElement)) {
                        continue;
                    }

                    // Extract attachment data using the helper method
                    $attachmentData = XpathHelper::extractAttachmentData($attachment);

                    // Get the attachment URL from extracted data
                    $attachmentUrl = $attachmentData['url'];

                    // Check if attachment URL is empty
                    if (! \is_string($attachmentUrl) || $attachmentUrl === '') {
                        // Continue to next attachment if no URL found
                        continue;
                    }

                    // Get attachment post ID using the helper method
                    $attachmentPostId = XpathHelper::getStringValue(
                        $attachment,
                        'wp:post_id'
                    );

                    // Check if attachment post ID is empty
                    if ($attachmentPostId === '') {
                        // Continue to next attachment if no post ID found
                        continue;
                    }

                    // Get the basename of the attachment URL
                    $attachmentBasename = basename($attachmentUrl);

                    // Get the basename of the source URL
                    $srcBasename = basename($src);

                    // Check if the source basename contains the attachment filename
                    if (
                        str_contains(
                            $srcBasename,
                            pathinfo(
                                $attachmentBasename,
                                PATHINFO_FILENAME
                            )
                        )
                    ) {
                        // Update full-size URL and matched attachment ID
                        $fullSizeUrl = $attachmentUrl;

                        // Store the matched attachment ID
                        $matchedAttachmentId = $attachmentPostId;

                        // Exit the loop since we found a match
                        break;
                    }
                }

                // Check if importing with the matched attachment ID is needed
                if ($matchedAttachmentId !== null) {
                    // ---
                    // Attempt to import image using the matched attachment ID.
                    //
                    // Note: hasFallbackUrl is true because we have $fullSizeUrl available
                    // for fallback.
                    // ---
                    $imagePath = $assetImporter->importImage(
                        $matchedAttachmentId,
                        $site,
                        $attachments,
                        $output,
                        SameSitePolicy::ALWAYS_IMPORT,
                        null,
                        'images',
                        true
                    );

                    // Check if image import was successful
                    if ($imagePath !== null && $imagePath !== '') {
                        // Use the matched attachment ID for successful imports
                        $attachmentId = $matchedAttachmentId;
                        $effectiveAttachmentId = $matchedAttachmentId;
                    }
                }
            }

            // Check if final fallback to direct URL import is needed
            if ($imagePath === null || $imagePath === '') {
                // Attempt to import image using the full-size URL
                $imagePath = $assetImporter->importFile(
                    $fullSizeUrl,
                    $site,
                    $output,
                    'images',
                    SameSitePolicy::ALWAYS_IMPORT
                );

                // Check if direct URL import failed
                if ($imagePath === null || $imagePath === '') {
                    // Build context data with attachment information
                    $contextData = [
                        'site' => $site,
                        'pageSlug' => $pageSlug ?? 'none',
                        'src' => $src,
                    ];

                    // Add attachment ID if available
                    if ($attachmentId !== null && $attachmentId !== '') {
                        $contextData['attachment_id'] = $attachmentId;
                    }

                    // Add full-size attachment URL if it exists and differs from src
                    if ($fullSizeUrl !== $src) {
                        $contextData['full_size_url'] = $fullSizeUrl;
                    }

                    // Add original URL with size suffix if it differs from full-size URL
                    if ($src !== $fullSizeUrl) {
                        $contextData['original_url_with_size_suffix'] = $src;
                    }

                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        $contextData
                    );

                    // ---
                    // Log warning about failed direct URL import (both with and without size
                    // suffix were attempted)
                    // ---
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'AssetImporterProcessor::process: Failed to import image using fallback URL (both with and without size suffix)',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }
            }
        }

        // Check if image import failed
        if ($imagePath === null || $imagePath === '') {
            // Return context to continue processing pipeline
            return $context;
        }

        // Update context with the imported image path
        $context['imagePath'] = $imagePath;

        // Update context with the effective attachment ID (URL-resolved or fallback)
        $context['attachmentId'] = $effectiveAttachmentId;

        // Return updated context with image processing results
        return $context;
    }
}
