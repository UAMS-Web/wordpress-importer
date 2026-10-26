<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Assets;

use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Asset as AssetFacade;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Manages overwrite behavior for asset imports.
 */
class OverwriteHandler
{
    /**
     * Normalize an asset path so it includes the expected site prefix when needed.
     *
     * @param  string  $path  The incoming relative asset path
     * @param  string  $site  The site prefix expected for site-scoped assets
     * @return string The normalized relative asset path
     */
    private function normalizeFileImportPath(
        string $path,
        string $site
    ): string {
        // Check whether a site prefix is required for this import
        if ($site === '') {
            // Return the original path when no site prefix is required
            return $path;
        }

        // Check whether the path already includes the expected site prefix
        if (
            \str_starts_with(
                $path,
                "{$site}/"
            )
        ) {
            // Return the existing path when prefix is already present
            return $path;
        }

        // Add the site prefix to produce a full relative import path
        return "{$site}/".ltrim(
            $path,
            '/'
        );
    }

    /**
     * The overwrite behavior setting for asset imports.
     *
     * @var string The overwrite behavior setting
     */
    protected string $behavior = 'Overwrite matching files';

    /**
     * Set the overwrite behavior for asset imports.
     *
     * @param  string  $behavior  The behavior to set
     */
    public function setBehavior(
        string $behavior
    ): void {
        // Set the overwrite behavior
        $this->behavior = $behavior;
    }

    /**
     * Handle image import with overwrite behavior.
     *
     * @param  string  $url  The image URL to download
     * @param  string  $path  The target path for the image
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output interface
     * @param  AssetDownloader  $downloader  The asset downloader instance
     * @param  MetadataSetter  $metadataSetter  The metadata setter instance
     * @param  string  $alt  The alt text for the image
     * @param  string  $caption  The caption for the image
     * @param  ?string  $pageUrl  Optional page URL for context
     * @param  ?string  $focus  Statamic focal string from WXR attachment meta
     * @param  ?string  $creator  Photo credit from WXR attachment meta
     * @param  ?string  $attachmentPageUrl  The WordPress attachment-page URL for migration redirects
     * @return ?string The imported image path or null if import failed
     */
    public function handleImageImport(
        string $url,
        string $path,
        string $site,
        OutputInterface $output,
        AssetDownloader $downloader,
        MetadataSetter $metadataSetter,
        string $alt = '',
        string $caption = '',
        ?string $pageUrl = null,
        ?string $focus = null,
        ?string $creator = null,
        ?string $attachmentPageUrl = null
    ): ?string {
        // Extract the original filename from the path
        $originalFilename = basename($path);

        // Build the asset ID for the images container
        $assetId = "images::{$path}";

        // Check if the asset already exists, handling asset metadata DB errors
        $existingAsset = AssetUtils::findAssetSafely(
            $assetId,
            'images',
            $path,
            $pageUrl,
            'OverwriteHandler::handleImageImport'
        );

        // Determine if asset exists based on the result
        $exists = $existingAsset instanceof Asset;

        // Switch on the configured overwrite behavior
        switch ($this->behavior) {
            // Handle skip matching files behavior
            case 'Skip matching files':
                // Check if asset already exists
                if ($exists) {
                    // Output skip message to console
                    $output->writeln("  - <comment>Skipped asset '$path' in 'images' container as it already exists.</comment>");

                    // Return the existing path
                    return "{$site}/{$originalFilename}";
                }

                // Exit case
                break;

                // Handle create additional file behavior
            case 'Create an additional file':
                // Check if asset already exists
                if ($exists) {
                    // Initialize counter for filename suffix
                    $counter = 1;

                    // Loop until a unique filename is found
                    do {
                        // Build new filename with counter suffix
                        $newFilename = pathinfo(
                            $originalFilename,
                            PATHINFO_FILENAME
                        )."-$counter.".pathinfo(
                            $originalFilename,
                            PATHINFO_EXTENSION
                        );

                        // Build new path with site prefix
                        $newPath = "{$site}/{$newFilename}";

                        // Build new asset ID for checking uniqueness
                        $newAssetId = "images::{$newPath}";

                        // Increment counter for next iteration
                        $counter++;
                    } while (AssetFacade::find($newAssetId) !== null);

                    // Output creation message to console
                    $output->writeln("  - Creating additional asset '$newPath' in 'images' container.");

                    // Update path to use new filename
                    $path = $newPath;

                    // Update original filename to new filename
                    $originalFilename = $newFilename;
                }

                // Exit case
                break;

                // Handle overwrite matching files behavior
            case 'Overwrite matching files':
                // No action needed, proceed with overwrite
                break;

                // Handle unknown behavior
            default:
                // Output warning for unknown behavior
                $output->writeln("  - <comment>Unknown overwrite behavior '{$this->behavior}'. Overwriting by default.</comment>");
        }

        // Output import message to console
        $output->writeln("  - Importing new asset '$path' in 'images' container.");

        // Download image with WordPress size suffix fallback handling
        $finalPath = AssetUtils::downloadImageWithFullSizeFallback(
            $url,
            $downloader->download(...),
            $path,
            'images',
            $output,
            self::intConfig(
                'wordpress-import.asset_timeout',
                60
            ),
            self::intConfig(
                'wordpress-import.asset_retries',
                3
            ),
            self::boolConfig(
                'wordpress-import.skip_failed_assets',
                false
            ),
            $pageUrl
        );

        // Check if download failed
        if ($finalPath === null) {
            // Return null to indicate failure
            return null;
        }

        // Set image metadata including alt text, caption, focal point, credit, and the
        // attachment-page URL the migration redirect generator needs
        $metadataSetter->setImageMetadata(
            $finalPath,
            'images',
            $alt,
            $caption,
            $url,
            $focus,
            $creator,
            $attachmentPageUrl
        );

        // ---
        // Return the path relative to the site directory
        // ---

        // Extract the final filename from the downloaded path
        $finalFilename = basename($finalPath);

        // Return the path with site prefix
        return "{$site}/{$finalFilename}";
    }

    /**
     * Handle file import with overwrite behavior.
     *
     * @param  string  $url  The file URL to download
     * @param  string  $path  The target path for the file
     * @param  string  $site  The site handle
     * @param  string  $container  The asset container name
     * @param  OutputInterface  $output  Console output interface
     * @param  AssetDownloader  $downloader  The asset downloader instance
     * @param  ?MetadataSetter  $metadataSetter  Optional metadata setter instance
     * @param  ?string  $pageUrl  Optional page URL for context
     * @param  ?string  $title  Optional title for the file asset
     * @param  ?array<int|string, mixed>  $additionalFileMetadata  Extra fields merged into file metadata
     * @param  ?string  $attachmentPageUrl  The WordPress attachment-page URL for migration redirects
     * @return ?string The imported file path or null if import failed
     */
    public function handleFileImport(
        string $url,
        string $path,
        string $site,
        string $container,
        OutputInterface $output,
        AssetDownloader $downloader,
        ?MetadataSetter $metadataSetter = null,
        ?string $pageUrl = null,
        ?string $title = null,
        ?array $additionalFileMetadata = null,
        ?string $attachmentPageUrl = null
    ): ?string {
        // Normalize the incoming path to ensure full relative path consistency
        $path = $this->normalizeFileImportPath(
            $path,
            $site
        );

        // Extract the original filename from the path
        $originalFilename = basename($path);

        // Build the asset ID for the specified container
        $assetId = "{$container}::{$path}";

        // Check if the asset already exists, handling asset metadata DB errors
        $existingAsset = AssetUtils::findAssetSafely(
            $assetId,
            $container,
            $path,
            $pageUrl,
            'OverwriteHandler::handleFileImport'
        );

        // Determine if asset exists based on the result
        $exists = $existingAsset instanceof Asset;

        // Switch on the configured overwrite behavior
        switch ($this->behavior) {
            // Handle skip matching files behavior
            case 'Skip matching files':
                // Check if asset already exists
                if ($exists) {
                    // Output skip message to console
                    $output->writeln("  - <comment>Skipped asset '$path' in '$container' container as it already exists.</comment>");

                    // Return the existing asset path, preserving subdirectory structure
                    return $path;
                }

                // Exit case
                break;

                // Handle create additional file behavior
            case 'Create an additional file':
                // Check if asset already exists
                if ($exists) {
                    // Initialize counter for filename suffix
                    $counter = 1;

                    // Loop until a unique filename is found
                    do {
                        // Build new filename with counter suffix
                        $newFilename = pathinfo(
                            $originalFilename,
                            PATHINFO_FILENAME
                        )."-$counter.".pathinfo(
                            $originalFilename,
                            PATHINFO_EXTENSION
                        );

                        // Build a new path using the original directory and incremented filename
                        $directoryPath = dirname($path);
                        $newPath = ($directoryPath === '.')
                            ? $newFilename
                            : "{$directoryPath}/{$newFilename}";

                        // Build new asset ID for checking uniqueness
                        $newAssetId = "{$container}::{$newPath}";

                        // Increment counter for next iteration
                        $counter++;
                    } while (AssetFacade::find($newAssetId) !== null);

                    // Output creation message to console
                    $output->writeln("  - Creating additional asset '$newPath' in '$container' container.");

                    // Update path to use new filename
                    $path = $newPath;

                    // Update original filename to new filename
                    $originalFilename = $newFilename;
                }

                // Exit case
                break;

                // Handle overwrite matching files behavior
            case 'Overwrite matching files':
                // No action needed, proceed with overwrite
                break;

                // Handle unknown behavior
            default:
                // Output warning for unknown behavior
                $output->writeln("  - <comment>Unknown overwrite behavior '{$this->behavior}'. Overwriting by default.</comment>");
        }

        // Output import message to console
        $output->writeln("  - Importing new asset '$path' in '$container' container.");

        // Download the file from the URL
        $finalPath = $downloader->download(
            $url,
            $path,
            $container,
            $output,
            self::intConfig(
                'wordpress-import.asset_timeout',
                60
            ),
            self::intConfig(
                'wordpress-import.asset_retries',
                3
            ),
            self::boolConfig(
                'wordpress-import.skip_failed_assets',
                false
            ),
            $pageUrl
        );

        // Check if download failed
        if ($finalPath === null) {
            // Return null to indicate failure
            return null;
        }

        // ---
        // Set file metadata if metadata setter is provided
        // ---

        // Check if metadata setter is provided
        if ($metadataSetter instanceof MetadataSetter) {
            // Set file metadata including source URL, title, and extras
            $metadataSetter->setFileMetadata(
                $finalPath,
                $container,
                $url,
                $title,
                $additionalFileMetadata,
                $attachmentPageUrl
            );
        }

        // ---
        // Return the path relative to the site directory
        // ---

        // Return the downloaded asset path, preserving subdirectory structure
        return $finalPath;
    }

    /**
     * Read an integer configuration value with a safe fallback.
     *
     * @param  string  $key  The configuration key
     * @param  int  $default  The default value when config is missing or invalid
     * @return int The resolved integer configuration value
     */
    private static function intConfig(
        string $key,
        int $default
    ): int {
        // Read the raw configuration value
        $value = config(
            $key,
            $default
        );

        // Return integer values directly
        if (is_int($value)) {
            return $value;
        }

        // Accept numeric strings from environment or config files
        if (
            is_string($value)
            && preg_match(
                '/^\d+$/',
                $value
            ) === 1
        ) {
            return (int) $value;
        }

        // Fall back to the provided default
        return $default;
    }

    /**
     * Read a boolean configuration value with a safe fallback.
     *
     * @param  string  $key  The configuration key
     * @param  bool  $default  The default value when config is missing or invalid
     * @return bool The resolved boolean configuration value
     */
    private static function boolConfig(
        string $key,
        bool $default
    ): bool {
        // Read the raw configuration value
        $value = config(
            $key,
            $default
        );

        // Return boolean values directly
        if (is_bool($value)) {
            return $value;
        }

        // Fall back to the provided default
        return $default;
    }
}
