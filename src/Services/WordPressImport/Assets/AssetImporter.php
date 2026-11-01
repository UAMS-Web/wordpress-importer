<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Assets;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Orchestrates the import of assets from WordPress into Statamic containers.
 *
 * Manages configuration, caching, and delegates downloading, metadata setting,
 * and overwrite behavior to specialized classes. Provides high-level import
 * methods for images and files with caching to prevent redundant downloads.
 */
class AssetImporter
{
    /**
     * Configuration setting for whether image uploads are enabled.
     *
     * @var bool Whether image uploads are enabled
     */
    protected bool $uploadImages;

    /**
     * Configuration setting for whether file uploads are enabled.
     *
     * @var bool Whether file uploads are enabled
     */
    protected bool $uploadFiles;

    /**
     * Asset downloader instance for handling file downloads from URLs.
     *
     * @var AssetDownloader The asset downloader instance
     */
    protected AssetDownloader $downloader;

    /**
     * Metadata setter instance for adding metadata to imported assets.
     *
     * @var MetadataSetter The metadata setter instance
     */
    protected MetadataSetter $metadataSetter;

    /**
     * Overwrite handler instance for managing file overwrite behavior.
     *
     * @var OverwriteHandler The overwrite handler instance
     */
    protected OverwriteHandler $overwriteHandler;

    /**
     * Cache of imported file URLs to their Statamic paths.
     *
     * @var array<string, string> Cache mapping file URLs to Statamic paths
     */
    protected static array $fileCache = [];

    /**
     * Cache of imported image attachment IDs to their Statamic paths. Cache keys
     * include container information to prevent conflicts when the same attachment is
     * used in different contexts (e.g., content images vs portraits).
     *
     * @var array<string, string> Cache mapping image attachment IDs to Statamic paths
     */
    protected static array $imageCache = [];

    /**
     * Cache of failed asset URLs to prevent re-attempting downloads.
     *
     * @var array<string, bool> Cache mapping failed asset URLs to true
     */
    protected static array $failedAssetsCache = [];

    /**
     * Constructs a new AssetImporter instance.
     *
     * Initializes the asset importer with configuration settings and creates
     * instances of required dependencies for downloading, metadata setting,
     * and overwrite handling.
     *
     * @param  ?string  $baseBlogUrl  The base blog URL for same-site policy checking
     */
    public function __construct(
        /**
         * Base blog URL for same-site policy checking.
         */
        protected ?string $baseBlogUrl = null
    ) {
        // Load image upload configuration from config file
        $this->uploadImages = (bool) config(
            'wordpress-import.upload_images',
            true
        );

        // Load file upload configuration from config file
        $this->uploadFiles = (bool) config(
            'wordpress-import.upload_files',
            true
        );

        // Initialize asset downloader for handling file downloads
        $this->downloader = new AssetDownloader;

        // Initialize metadata setter for adding metadata to imported assets
        $this->metadataSetter = new MetadataSetter;

        // Initialize overwrite handler for managing file overwrite behavior
        $this->overwriteHandler = new OverwriteHandler;
    }

    /**
     * Clear the file, image, and failed assets caches (useful for testing or new
     * import runs).
     */
    public static function clearFileCache(): void
    {
        // Clear the file cache to remove all previously imported file URLs
        self::$fileCache = [];

        // Clear the image cache to remove all previously imported image attachment IDs
        self::$imageCache = [];

        // Clear the failed assets cache to allow retry of previously failed downloads
        self::$failedAssetsCache = [];
    }

    /**
     * Generate a container-aware cache key for image attachments.
     *
     * Creates a unique cache key that includes both the attachment ID and the
     * container/context information. This prevents cache conflicts when the same
     * attachment is used in different contexts (e.g., content images vs portraits).
     *
     * @param  string  $attachmentId  The WordPress attachment ID
     * @param  string  $container  The target container (e.g., 'images', 'portraits')
     * @return string The container-aware cache key
     */
    protected static function generateImageCacheKey(
        string $attachmentId,
        string $container
    ): string {
        // Return the container-aware cache key
        return "{$attachmentId}:{$container}";
    }

    /**
     * Generate a container-aware cache key for file URLs.
     *
     * Creates a unique cache key that includes both the file URL and the
     * container/context information. This prevents cache conflicts when the same
     * file URL is used in different contexts. For image URLs, normalizes WordPress
     * size suffixes to ensure different sized versions of the same image share
     * the same cache key.
     *
     * @param  string  $url  The file URL
     * @param  string  $container  The target container (e.g., 'files', 'images', 'portraits')
     * @return string The container-aware cache key
     */
    protected static function generateFileCacheKey(
        string $url,
        string $container
    ): string {
        // ---
        // Normalize WordPress image URLs to remove size suffixes for caching.
        // This ensures that different sized versions (e.g., -630x418.jpg) of the
        // same image share the same cache key.
        // ---
        $normalizedUrl = AssetUtils::normalizeWordPressImageUrl($url);

        // Return the container-aware cache key using normalized URL
        return "{$normalizedUrl}:{$container}";
    }

    /**
     * Sets the asset overwrite behavior for handling existing files.
     *
     * Configures how the importer should handle cases where files with the same
     * name already exist in the target container.
     *
     * @param  string  $behavior  The overwrite behavior setting
     */
    public function setAssetOverwriteBehavior(
        string $behavior
    ): void {
        // Update the overwrite handler with the new behavior setting
        $this->overwriteHandler->setBehavior($behavior);
    }

    /**
     * Sets the base blog URL for same-site policy checking.
     *
     * Configures the base URL that will be used to determine if asset URLs
     * are from the same site and should be imported or skipped.
     *
     * @param  ?string  $baseBlogUrl  The base blog URL
     */
    public function setBaseBlogUrl(
        ?string $baseBlogUrl
    ): void {
        // Update the base blog URL for same-site policy checking
        $this->baseBlogUrl = $baseBlogUrl;
    }

    /**
     * Check if uploads are enabled for the specified asset type.
     *
     * @param  string  $assetType  The type of asset ('image' or 'file')
     * @param  string  $identifier  The identifier for the asset (ID or URL)
     * @param  OutputInterface  $output  Console output for logging
     * @return bool True if uploads are enabled, false otherwise
     */
    protected function checkUploadEnabled(
        string $assetType,
        string $identifier,
        OutputInterface $output
    ): bool {
        // Determine if uploads are enabled based on asset type
        $isEnabled = $assetType === 'image' ? $this->uploadImages : $this->uploadFiles;

        // Return the upload enabled status
        return $isEnabled;
    }

    /**
     * Imports an image attachment from WordPress into a Statamic container.
     *
     * Downloads and imports an image attachment using its WordPress attachment ID.
     * Uses container-aware caching to prevent conflicts when the same attachment
     * is used in different contexts. Applies same-site policy and handles failed
     * downloads gracefully.
     *
     * @param  string  $attachmentId  The WordPress attachment ID
     * @param  string  $site  The Statamic site handle for asset paths
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  OutputInterface  $output  Console output for logging
     * @param  SameSitePolicy  $sameSitePolicy  Policy for handling different-site assets
     * @param  ?string  $pageUrl  The page URL for context (optional)
     * @param  string  $container  The target container (e.g., 'images', 'portraits')
     * @param  bool  $hasFallbackUrl  Whether a fallback URL is available if this import fails
     * @return ?string The imported image path, the original URL if the policy links it, or null if failed or skipped
     */
    public function importImage(
        string $attachmentId,
        string $site,
        array $attachments,
        OutputInterface $output,
        SameSitePolicy $sameSitePolicy = SameSitePolicy::ALWAYS_IMPORT,
        ?string $pageUrl = null,
        string $container = 'images',
        bool $hasFallbackUrl = true
    ): ?string {
        // Check if attachment ID is provided
        if ($attachmentId === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['site' => $site],
                $pageUrl
            );

            // Log warning about missing attachment ID
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'AssetImporter::importImage: No attachment ID provided for image import',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate import failure
            return null;
        }

        // Check if image uploads are disabled
        if (
            ! $this->checkUploadEnabled(
                'image',
                "attachment ID '$attachmentId'",
                $output
            )
        ) {
            // Return null when uploads are disabled
            return null;
        }

        // Generate container-aware cache key
        $cacheKey = self::generateImageCacheKey(
            $attachmentId,
            $container
        );

        // Check cache for existing import
        if (isset(self::$imageCache[$cacheKey])) {
            // Display cached image path message to console
            $cachedPath = self::$imageCache[$cacheKey];
            $output->writeln("  - <comment>Using cached image path for attachment ID '$attachmentId' in container '$container': {$cachedPath}</comment>");

            // Return the cached image path
            return $cachedPath;
        }

        // Find the matching attachment using the helper method
        $attachment = XpathHelper::findAttachmentById(
            $attachments,
            $attachmentId
        );

        // Check if matching attachment was found
        if (! $attachment instanceof \SimpleXMLElement) {
            // Display warning message to console
            $output->writeln("  - <comment>No attachment found for ID '$attachmentId'.</comment>");

            // Log warning if no fallback URL is available
            if (! $hasFallbackUrl) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'attachment_id' => $attachmentId,
                    ],
                    $pageUrl
                );

                // Log warning about missing attachment when no fallback is available
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "AssetImporter::importImage: No attachment found for ID '$attachmentId' and no fallback URL available",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // Return null when attachment not found
            return null;
        }

        // Extract and normalize attachment data from the XML element
        $attachmentData = self::normalizeAttachmentData(
            XpathHelper::extractAttachmentData($attachment)
        );

        // Get the attachment URL from extracted data
        $url = $attachmentData['url'];

        // Get the alt text from extracted data
        $alt = $attachmentData['alt'];

        // Get the caption from extracted data
        $caption = $attachmentData['caption'];

        // Get Statamic focal string derived from faces/hotspots and dimensions
        $focus = $attachmentData['focus'];

        // Get the photo credit derived from _media_credit/EXIF meta
        $creator = $attachmentData['creator'];

        // ---
        // The attachment's `<link>` is its WordPress attachment PAGE url, distinct from
        // the file url above. The migration redirect generator needs it to redirect
        // `/<post>/<attachment>/` requests (wordpress-importer-archive#446). Inline body images reach only this
        // path, so without it most attachments never receive the key (wordpress-importer-archive#489).
        // ---
        $attachmentPageUrl = \trim((string) $attachment->link);

        // Check if attachment URL exists, return null if not found
        if ($url === '') {
            // Log warning message to console output
            $output->writeln("  - <comment>No attachment URL found for attachment ID '$attachmentId'.</comment>");

            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['site' => $site],
                $pageUrl
            );

            // Log warning to application log for debugging
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "AssetImporter::importImage: No attachment URL found for ID '$attachmentId'",
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate import failure
            return null;
        }

        // Check if this URL has already failed to download
        if (isset(self::$failedAssetsCache[$url])) {
            // Log that we're skipping this previously failed URL
            $output->writeln("  - <comment>Skipping previously failed image '$url'.</comment>");

            // Return null to indicate no import was attempted
            return null;
        }

        // Apply same-site policy
        $decision = $this->decideSameSite(
            $url,
            $sameSitePolicy,
            $output,
            $site,
            $container,
            $pageUrl
        );

        // Check if policy says not to import
        if (! $decision->import) {
            // Return early
            return $decision->earlyResult;
        }

        // Generate unique filename from URL
        $originalFilename = AssetUtils::generateUniqueFilename($url);

        // Construct the target path for the image
        $path = "{$site}/{$originalFilename}";

        // Import the image using the overwrite handler
        $importedPath = $this->overwriteHandler->handleImageImport(
            $url,
            $path,
            $site,
            $output,
            $this->downloader,
            $this->metadataSetter,
            $alt,
            $caption,
            $pageUrl,
            $focus,
            $creator,
            $attachmentPageUrl === '' ? null : $attachmentPageUrl
        );

        // Check if the image was imported successfully
        if ($importedPath !== null && $importedPath !== '') {
            // Cache the successfully imported image path
            self::$imageCache[$cacheKey] = $importedPath;
        } else { // Otherwise
            // Mark this URL as failed to prevent future download attempts
            // The cache is read with isset(), which any stored value satisfies
            // @pest-mutate-ignore: TrueToFalse
            self::$failedAssetsCache[$url] = true;

            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'url' => $url,
                ],
                $pageUrl
            );

            // Log the failure for debugging and monitoring purposes
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "AssetImporter::importImage: Failed to import image for attachment ID '$attachmentId'",
                    $logData['page_url']
                ),
                $logData['context']
            );

            // ---
            // Record the loss in the end-of-run warning summary
            // ---

            // **A dropped asset was previously reported only inline (wordpress-importer-archive#1161).** The failure printed
            // one line at the moment it happened — position 534 of an 800-line run in the reported
            // case — and the end-of-run summary that an operator actually reads carried sections
            // for unresolvable categories and unrenderable galleries and none for lost images. A
            // run that silently dropped three irreplaceable originals ended `Import process
            // complete.` Registering here rather than in `AssetDownloader` is deliberate: this is
            // the only frame holding the attachment id, which is what makes the entry actionable.
            ImportWarningManager::addWarning(
                'assets',
                'dropped_assets',
                [
                    'attachment_id' => $attachmentId,
                    'url' => $url,
                    'container' => $container,
                    'site' => $site,
                    'page_url' => $pageUrl,
                ]
            );
        }

        // Return the imported image path or null if failed
        return $importedPath;
    }

    /**
     * Applies the same-site policy to an asset and reports one it leaves out.
     *
     * A skipped asset is absent from whatever carried it, so it goes in the end-of-run
     * warning summary.
     *
     * @param  string  $url  The asset URL
     * @param  SameSitePolicy  $policy  The policy to apply
     * @param  OutputInterface  $output  Console output for logging
     * @param  string  $site  The Statamic site handle
     * @param  string  $container  The target container
     * @param  ?string  $pageUrl  The page URL for context
     * @return SameSiteDecision Whether to import, and what to return if not
     */
    private function decideSameSite(
        string $url,
        SameSitePolicy $policy,
        OutputInterface $output,
        string $site,
        string $container,
        ?string $pageUrl
    ): SameSiteDecision {
        // Ask the policy what to do with this URL
        $decision = AssetUtils::applySameSitePolicy(
            $url,
            $policy,
            $output,
            $this->baseBlogUrl
        );

        // Record an asset left out with nothing in its place
        if (! $decision->import && $decision->earlyResult === null) {
            ImportWarningManager::addWarning(
                'assets',
                'skipped_different_site_assets',
                [
                    'url' => $url,
                    'container' => $container,
                    'site' => $site,
                    'page_url' => $pageUrl,
                ]
            );
        }

        return $decision;
    }

    /**
     * Imports a thumbnail image attachment from WordPress into a Statamic container.
     *
     * Downloads and imports a thumbnail image attachment using its WordPress
     * attachment ID. Uses container-aware caching to prevent conflicts when the
     * same attachment is used in different contexts. Applies same-site policy
     * and handles failed downloads gracefully.
     *
     * @param  string  $thumbnailId  The WordPress thumbnail attachment ID
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  string  $site  The Statamic site handle for asset paths
     * @param  OutputInterface  $output  Console output for logging
     * @param  SameSitePolicy  $sameSitePolicy  Policy for handling different-site assets
     * @param  ?string  $pageUrl  The page URL for context (optional)
     * @param  string  $container  The target container (e.g., 'images', 'portraits')
     * @return ?string The imported thumbnail path, the original URL if the policy links it, or null if failed or skipped
     */
    public function importThumbnail(
        string $thumbnailId,
        array $attachments,
        string $site,
        OutputInterface $output,
        SameSitePolicy $sameSitePolicy = SameSitePolicy::ALWAYS_IMPORT,
        ?string $pageUrl = null,
        string $container = 'images'
    ): ?string {
        // Check if thumbnail ID is provided
        if ($thumbnailId === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'attachments_count' => \count($attachments),
                    'upload_images_enabled' => $this->uploadImages,
                ],
                $pageUrl
            );

            // Log warning about missing thumbnail ID
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'AssetImporter::importThumbnail: No thumbnail ID provided',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate import failure
            return null;
        }

        // Check if image uploads are disabled
        if (
            ! $this->checkUploadEnabled(
                'image',
                "thumbnail ID '$thumbnailId'",
                $output
            )
        ) {
            // Return null when uploads are disabled
            return null;
        }

        // Generate container-aware cache key
        $cacheKey = self::generateImageCacheKey(
            $thumbnailId,
            $container
        );

        // Check cache for existing import
        if (isset(self::$imageCache[$cacheKey])) {
            // Display cached thumbnail path message to console
            $cachedPath = self::$imageCache[$cacheKey];
            $output->writeln("  - <comment>Using cached image path for thumbnail ID '$thumbnailId' in container '$container': {$cachedPath}</comment>");

            // Return the cached thumbnail path
            return $cachedPath;
        }

        // Find the matching attachment using the helper method
        $attachment = XpathHelper::findAttachmentById(
            $attachments,
            $thumbnailId
        );

        // Check if matching attachment was found
        if ($attachment instanceof \SimpleXMLElement) {
            // Extract attachment data using the helper method
            // Extract and normalize attachment data from the XML element
            $attachmentData = self::normalizeAttachmentData(
                XpathHelper::extractAttachmentData($attachment)
            );

            // Get the attachment URL from extracted data
            $url = $attachmentData['url'];

            // Get the alt text from extracted data
            $alt = $attachmentData['alt'];

            // Get the caption from extracted data
            $caption = $attachmentData['caption'];

            // Get Statamic focal string derived from faces/hotspots and dimensions
            $focus = $attachmentData['focus'];

            // Get the photo credit derived from _media_credit/EXIF meta
            $creator = $attachmentData['creator'];

            // ---
            // The attachment's `<link>` is its WordPress attachment PAGE url, distinct
            // from the file url above — the migration redirect generator needs it to
            // redirect `/<post>/<attachment>/` requests (wordpress-importer-archive#446, wordpress-importer-archive#489).
            // ---
            $attachmentPageUrl = \trim((string) $attachment->link);

            // Check if attachment URL exists, return null if not found
            if ($url === '') {
                // Log warning message to console output
                $output->writeln("  - <comment>No attachment URL found for thumbnail ID '$thumbnailId'.</comment>");

                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    ['site' => $site],
                    $pageUrl
                );

                // Log warning to application log for debugging
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "AssetImporter::importThumbnail: No attachment URL found for ID '$thumbnailId'",
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Return null to indicate import failure
                return null;
            }

            // Check if this URL has already failed to download
            if (isset(self::$failedAssetsCache[$url])) {
                // Log that we're skipping this previously failed thumbnail URL
                $output->writeln("  - <comment>Skipping previously failed thumbnail '$url'.</comment>");

                // Return null to indicate no import was attempted
                return null;
            }

            // Apply same-site policy
            $decision = $this->decideSameSite(
                $url,
                $sameSitePolicy,
                $output,
                $site,
                $container,
                $pageUrl
            );

            // Check if policy says not to import
            if (! $decision->import) {
                // Return early
                return $decision->earlyResult;
            }

            // Generate unique filename from URL
            $originalFilename = AssetUtils::generateUniqueFilename($url);

            // Construct the target path for the thumbnail
            $path = "{$site}/{$originalFilename}";

            // Import the thumbnail using the overwrite handler
            $importedPath = $this->overwriteHandler->handleImageImport(
                $url,
                $path,
                $site,
                $output,
                $this->downloader,
                $this->metadataSetter,
                $alt,
                $caption,
                $pageUrl,
                $focus,
                $creator,
                $attachmentPageUrl === '' ? null : $attachmentPageUrl
            );

            // Check if the thumbnail was imported successfully
            if ($importedPath !== null && $importedPath !== '') {
                // Cache the imported path
                self::$imageCache[$cacheKey] = $importedPath;
            } else { // Otherwise
                // Mark this URL as failed to prevent future download attempts
                // The cache is read with isset(), which any stored value satisfies
                // @pest-mutate-ignore: TrueToFalse
                self::$failedAssetsCache[$url] = true;

                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'site' => $site,
                        'url' => $url,
                    ],
                    $pageUrl
                );

                // Log the failure for debugging and monitoring purposes
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "AssetImporter::importThumbnail: Failed to import thumbnail for ID '$thumbnailId'",
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // Return the imported thumbnail path or null if failed
            return $importedPath;
        }

        // Enhance log context with entry information
        $logData = LogContextHelper::enhanceWithEntryContext(
            ['site' => $site],
            $pageUrl
        );

        // Log warning about missing thumbnail attachment
        Log::warning(
            LogContextHelper::formatMessageWithEntry(
                "AssetImporter::importThumbnail: No attachment found for thumbnail ID '$thumbnailId'",
                $logData['page_url']
            ),
            $logData['context']
        );

        // Return null when thumbnail attachment not found
        return null;
    }

    /**
     * Imports a file from a URL into a Statamic container.
     *
     * Downloads and imports a file from a given URL into the specified Statamic
     * container. Uses container-aware caching to prevent conflicts when the same
     * URL is used in different contexts. Applies same-site policy and handles
     * failed downloads gracefully.
     *
     * @param  string  $url  The URL of the file to import
     * @param  string  $site  The Statamic site handle for asset paths
     * @param  OutputInterface  $output  Console output for logging
     * @param  string  $container  The target container (e.g., 'files', 'images', 'portraits')
     * @param  SameSitePolicy  $sameSitePolicy  Policy for handling different-site assets
     * @param  ?string  $pageUrl  The page URL for context (optional)
     * @param  ?string  $title  Optional title for the file asset
     * @param  ?array<int|string, mixed>  $additionalFileMetadata  Extra fields merged into file metadata
     * @param  ?string  $attachmentPageUrl  The WordPress attachment-page URL for migration redirects
     * @return ?string The imported file path, the original URL if the policy links it, or null if failed or skipped
     */
    public function importFile(
        string $url,
        string $site,
        OutputInterface $output,
        string $container = 'files',
        SameSitePolicy $sameSitePolicy = SameSitePolicy::SKIP_DIFFERENT_SITE,
        ?string $pageUrl = null,
        ?string $title = null,
        ?array $additionalFileMetadata = null,
        ?string $attachmentPageUrl = null
    ): ?string {
        // Check if file URL is provided
        if ($url === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['site' => $site],
                $pageUrl
            );

            // Log warning about missing file URL
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'AssetImporter::importFile: No URL provided for file import',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate import failure
            return null;
        }

        // Check if file uploads are disabled
        if (
            ! $this->checkUploadEnabled(
                'file',
                "URL '$url'",
                $output
            )
        ) {
            // Return null when uploads are disabled
            return null;
        }

        // Apply same-site policy
        $decision = $this->decideSameSite(
            $url,
            $sameSitePolicy,
            $output,
            $site,
            $container,
            $pageUrl
        );

        // Check if policy says not to import
        if (! $decision->import) {
            // Return early
            return $decision->earlyResult;
        }

        // ---
        // Normalize WordPress image URLs to remove size suffixes for cache
        // checking. This ensures that different sized versions of the same image
        // (e.g., -630x418.jpg) share the same cache entry.
        // ---
        $normalizedUrl = AssetUtils::normalizeWordPressImageUrl($url);

        // ---
        // Generate container-aware cache key (normalization happens inside
        // generateFileCacheKey, but we need normalizedUrl for failed cache check)
        // ---
        $cacheKey = self::generateFileCacheKey(
            $url,
            $container
        );

        // Check cache for existing import
        if (isset(self::$fileCache[$cacheKey])) {
            // Display cached file path message to console
            $cachedPath = self::$fileCache[$cacheKey];
            $output->writeln("  - <comment>Using cached file path for '$url' in container '$container': {$cachedPath}</comment>");

            // Return the cached file path
            return $cachedPath;
        }

        // ---
        // Check if this URL (or its normalized version) has already failed to
        // download. Check both the original and normalized URLs to handle cases
        // where different sized versions were attempted.
        // ---
        if (
            isset(self::$failedAssetsCache[$url])
            || (
                $normalizedUrl !== $url
                && isset(self::$failedAssetsCache[$normalizedUrl])
            )
        ) {
            // Log that we're skipping this previously failed file URL
            $output->writeln("  - <comment>Skipping previously failed asset '$url'.</comment>");

            // Return null to indicate no import was attempted
            return null;
        }

        // Generate unique filename from URL
        $originalFilename = AssetUtils::generateUniqueFilename($url);

        // Construct the target path for the file
        $path = $site !== '' ? "{$site}/{$originalFilename}" : $originalFilename;

        // Import the file using the overwrite handler
        $importedPath = $this->overwriteHandler->handleFileImport(
            $url,
            $path,
            $site,
            $container,
            $output,
            $this->downloader,
            $this->metadataSetter,
            $pageUrl,
            $title,
            $additionalFileMetadata,
            $attachmentPageUrl
        );

        // Check if the file was imported successfully
        if ($importedPath !== null && $importedPath !== '') {
            // Cache the imported path using normalized URL for cache key
            self::$fileCache[$cacheKey] = $importedPath;
        } else { // Otherwise
            // ---
            // Mark both the original and normalized URLs as failed to prevent
            // future download attempts. This ensures that if we try a different
            // sized version later, we'll know it already failed.
            // ---
            // The cache is read with isset(), which any stored value satisfies
            // @pest-mutate-ignore: TrueToFalse
            self::$failedAssetsCache[$url] = true;

            // Also mark normalized URL as failed if it differs from original
            if ($normalizedUrl !== $url) {
                // The cache is read with isset(), which any stored value satisfies
                // @pest-mutate-ignore: TrueToFalse
                self::$failedAssetsCache[$normalizedUrl] = true;
            }

            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'path' => $path,
                ],
                $pageUrl
            );

            // Log the failure for debugging and monitoring purposes
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "AssetImporter::importFile: Failed to import file for URL '$url'",
                    $logData['page_url']
                ),
                $logData['context']
            );
        }

        // Return the imported file path or null if failed
        return $importedPath;
    }

    /**
     * Get the base blog URL for same-site checking.
     *
     * @return ?string The base blog URL
     */
    public function getBaseBlogUrl(): ?string
    {
        // Return the base blog URL
        return $this->baseBlogUrl;
    }

    /**
     * Get the upload images setting.
     *
     * @return bool Whether image uploads are enabled
     */
    public function getUploadImages(): bool
    {
        // Return the upload images setting
        return $this->uploadImages;
    }

    /**
     * Get the upload files setting.
     *
     * @return bool Whether file uploads are enabled
     */
    public function getUploadFiles(): bool
    {
        // Return the upload files setting
        return $this->uploadFiles;
    }

    /**
     * Get the file cache.
     *
     * @return array<int|string, mixed> The file cache
     */
    public function getFileCache(): array
    {
        // Return the file cache
        return self::$fileCache;
    }

    /**
     * Get a file cache entry by the arguments it was set with.
     *
     * @param  string  $url  The URL key
     * @param  string  $container  The container context
     * @return mixed The cached value, or null when there is none
     */
    public function getFileCacheEntry(
        string $url,
        string $container = 'files'
    ): mixed {
        // Read under the key `setFileCache()` writes
        return self::$fileCache[self::generateFileCacheKey($url, $container)] ?? null;
    }

    /**
     * Set a file cache entry with container-aware key.
     *
     * @param  string  $url  The URL key
     * @param  string  $path  The cached path
     * @param  string  $container  The container context
     */
    public function setFileCache(
        string $url,
        string $path,
        string $container = 'files'
    ): void {
        // Generate container-aware cache key
        $cacheKey = self::generateFileCacheKey(
            $url,
            $container
        );

        // Set the cache entry
        self::$fileCache[$cacheKey] = $path;
    }

    /**
     * Get the overwrite handler.
     *
     * @return OverwriteHandler The overwrite handler
     */
    public function getOverwriteHandler(): OverwriteHandler
    {
        // Return the overwrite handler
        return $this->overwriteHandler;
    }

    /**
     * Get the downloader.
     *
     * @return AssetDownloader The downloader
     */
    public function getDownloader(): AssetDownloader
    {
        // Return the downloader
        return $this->downloader;
    }

    /**
     * Get the metadata setter.
     *
     * @return MetadataSetter The metadata setter
     */
    public function getMetadataSetter(): MetadataSetter
    {
        // Return the metadata setter
        return $this->metadataSetter;
    }

    /**
     * Normalize mixed attachment field values from XPath extraction.
     *
     * @param  array<int|string, mixed>  $attachmentData  Raw attachment data from XPath
     * @return array{url: string, alt: string, caption: string, focus: string|null, creator: string}
     */
    private static function normalizeAttachmentData(
        array $attachmentData
    ): array {
        // Resolve each field with a safe default when the value is not a string
        $url = $attachmentData['url'] ?? '';
        $alt = $attachmentData['alt'] ?? '';
        $caption = $attachmentData['caption'] ?? '';
        $focus = $attachmentData['focus'] ?? null;
        $creator = $attachmentData['creator'] ?? '';

        return [
            'url' => is_string($url) ? $url : '',
            'alt' => is_string($alt) ? $alt : '',
            'caption' => is_string($caption) ? $caption : '',
            'focus' => is_string($focus) ? $focus : null,
            'creator' => is_string($creator) ? $creator : '',
        ];
    }
}
