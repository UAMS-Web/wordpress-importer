<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Traits;

use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Trait providing common functionality for handling disabled uploads.
 *
 * This trait encapsulates the shared logic for checking upload status,
 * logging disabled upload messages, and finding attachments by ID.
 * It helps eliminate code duplication between ImageProcessor and FileProcessor.
 */
trait DisabledUploadHandlingTrait
{
    /**
     * Check if uploads are disabled and log appropriate message.
     *
     * @param  AssetImporter  $assetImporter  Instance for checking upload status
     * @param  OutputInterface  $output  Console output for logging
     * @param  string  $description  Description of the asset for logging
     * @param  string  $uploadType  Type of upload ('image' or 'file')
     * @return bool True if uploads are disabled, false otherwise
     */
    protected static function checkUploadsDisabled(
        AssetImporter $assetImporter,
        OutputInterface $output,
        string $description,
        string $uploadType
    ): bool {
        // Check if uploads are disabled based on type
        $uploadsDisabled = $uploadType === 'image'
            ? ! $assetImporter->getUploadImages()
            : ! $assetImporter->getUploadFiles();

        // Log informative message if uploads are disabled
        if ($uploadsDisabled) {
            $output->writeln('  - <comment>'.\ucfirst($uploadType)." uploads disabled. Using original URL for {$description}.</comment>");
        }

        // Return true if uploads are disabled, false otherwise
        return $uploadsDisabled;
    }

    /**
     * Find attachment by ID in the attachments array.
     *
     * @param  string  $attachmentId  The WordPress attachment ID to find
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  string  $site  The site handle for logging
     * @param  string  $description  Description of the asset for logging
     * @param  string  $context  Context information for logging
     * @param  AssetImporter  $assetImporter  Instance for checking upload status
     * @param  string  $uploadType  Type of upload ('image' or 'file')
     * @return array<int|string, mixed>|null Array with 'attachment' and 'url' keys, or null if not found
     */
    protected static function findAttachmentById(
        string $attachmentId,
        array $attachments,
        string $site,
        string $description,
        string $context,
        AssetImporter $assetImporter,
        string $uploadType
    ): ?array {
        // Loop through all attachments to find the matching one
        foreach ($attachments as $attachment) {
            if (! $attachment instanceof SimpleXMLElement) {
                continue;
            }

            // Extract the post ID from the attachment
            $postId = XpathHelper::getStringValue(
                $attachment,
                'wp:post_id'
            );

            // Check if this attachment matches the requested attachment ID
            if ($postId === $attachmentId) {
                // Extract the attachment URL
                $attachmentUrl = XpathHelper::getStringValue(
                    $attachment,
                    'wp:attachment_url'
                );

                // Return attachment data
                return [
                    'attachment' => $attachment,
                    'url' => $attachmentUrl,
                ];
            }
        }

        // Log warning about missing attachment using centralized method
        self::logMissingAttachment(
            $context,
            $site,
            $attachmentId,
            $description,
            $assetImporter,
            $uploadType
        );

        // Return null if no matching attachment found
        return null;
    }

    /**
     * Log warning about missing attachment with consistent format.
     *
     * @param  string  $context  Context information for logging
     * @param  string  $site  The site handle
     * @param  string  $attachmentId  The attachment ID that was not found
     * @param  string  $description  Description of the asset
     * @param  AssetImporter  $assetImporter  Instance for checking upload status
     * @param  string  $uploadType  Type of upload ('image' or 'file')
     */
    protected static function logMissingAttachment(
        string $context,
        string $site,
        string $attachmentId,
        string $description,
        AssetImporter $assetImporter,
        string $uploadType
    ): void {
        // Check if uploads are enabled for the given type
        $uploadsEnabled = $uploadType === 'image'
            ? $assetImporter->getUploadImages()
            : $assetImporter->getUploadFiles();

        // Only log warning if uploads are enabled (missing attachments are expected when disabled)
        if ($uploadsEnabled) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'attachment_id' => $attachmentId,
                    'description' => $description,
                    'context' => $context,
                ]
            );

            // Log warning about missing attachment
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "{$context}: No attachment found for {$description} ID",
                    $logData['page_url']
                ),
                $logData['context']
            );
        }
    }
}
