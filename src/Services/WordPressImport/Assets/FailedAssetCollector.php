<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Assets;

/**
 * Collects and manages failed asset download URLs for reporting at the end of
 * import.
 *
 * This service provides a centralized way to track asset download failures
 * across the import process, deduplicate URLs, and format them for display.
 * It maintains a static collection that persists throughout the import session.
 */
class FailedAssetCollector
{
    /**
     * Collection of failed asset URLs grouped by page/post URL.
     *
     * @var array<string, array<string>> Array mapping page URLs to arrays of failed asset URLs
     */
    private static array $failedUrlsByPage = [];

    /**
     * Add a failed asset URL to the collection with its associated page/post URL.
     *
     * @param  string  $assetUrl  The URL that failed to download
     * @param  ?string  $pageUrl  The URL of the page/post where this asset was referenced
     */
    public static function addFailedUrl(
        string $assetUrl,
        ?string $pageUrl = null
    ): void {
        // Check if asset URL is empty
        if ($assetUrl === '') {
            // Return early if no URL provided
            return;
        }

        // Use a default key if no page URL is provided
        $pageKey = $pageUrl ?? 'Unknown Page';

        self::$failedUrlsByPage[$pageKey] ??= [];

        // ---
        // Add the asset URL if it's not already in the collection for this page
        // ---

        // Check if asset URL is not already in collection for this page
        if (
            ! \in_array(
                $assetUrl,
                self::$failedUrlsByPage[$pageKey],
                true
            )
        ) {
            // Add asset URL to collection for this page
            self::$failedUrlsByPage[$pageKey][] = $assetUrl;
        }
    }

    /**
     * Get all failed asset URLs grouped by page/post URL.
     *
     * @return array<string, array<string>> Array of failed URLs grouped by page URL
     */
    public static function getFailedUrlsByPage(): array
    {
        // Return the failed URLs by page
        return self::$failedUrlsByPage;
    }

    /**
     * Get all failed asset URLs as a flat array, sorted alphabetically.
     *
     * @return array<string> Array of failed URLs sorted alphabetically
     */
    public static function getFailedUrls(): array
    {
        // Initialize empty array for flat URL list
        $urls = [];

        // Loop through all pages and their failed URLs
        foreach (self::$failedUrlsByPage as $pageUrls) {
            // Merge page URLs into flat array
            $urls = \array_merge(
                $urls,
                $pageUrls
            );
        }

        // Sort URLs alphabetically
        sort($urls);

        // Return sorted array of failed URLs
        return $urls;
    }

    /**
     * Check if there are any failed asset downloads.
     *
     * @return bool True if there are failed downloads, false otherwise
     */
    public static function hasFailedDownloads(): bool
    {
        return self::$failedUrlsByPage !== [];
    }

    /**
     * Get the count of failed asset downloads.
     *
     * @return int Number of failed downloads
     */
    public static function getFailedCount(): int
    {
        // Initialize count to zero
        $count = 0;

        // Loop through all pages and their failed URLs
        foreach (self::$failedUrlsByPage as $pageUrls) {
            // Add count of URLs for this page to total
            $count += \count($pageUrls);
        }

        // Return total count of failed URLs
        return $count;
    }

    /**
     * Clear all failed asset URLs from the collection.
     */
    public static function clearFailedUrls(): void
    {
        // Reset failed URLs collection to empty array
        self::$failedUrlsByPage = [];
    }

    /**
     * Format failed URLs for display in console output, grouped by page/post URL.
     *
     * @return string Formatted string with failed URLs grouped by page/post URL
     */
    public static function formatFailedUrlsForDisplay(): string
    {
        // Get all failed URLs grouped by page
        $failedUrlsByPage = self::getFailedUrlsByPage();

        // Check if there are any failed URLs
        if ($failedUrlsByPage === []) {
            // Return empty string if no failed URLs
            return '';
        }

        // ---
        // Sort page URLs alphabetically
        // ---

        // Extract page URLs as array keys
        $pageUrls = \array_keys($failedUrlsByPage);

        // Sort page URLs alphabetically
        sort($pageUrls);

        // Initialize formatted output with header
        $formatted = "Failed to download these assets:\n";

        // Loop through each page URL
        foreach ($pageUrls as $pageUrl) {
            // Get asset URLs for this page
            $assetUrls = $failedUrlsByPage[$pageUrl];

            // Sort asset URLs alphabetically within each page
            sort($assetUrls);

            // Add page URL to formatted output
            $formatted .= "  - {$pageUrl}\n";

            // Loop through asset URLs for this page
            foreach ($assetUrls as $assetUrl) {
                // Add asset URL to formatted output
                $formatted .= "      - {$assetUrl}\n";
            }
        }

        // Return formatted string with trailing whitespace removed
        return \rtrim($formatted);
    }
}
