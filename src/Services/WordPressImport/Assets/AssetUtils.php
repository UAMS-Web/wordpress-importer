<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Statamic\Contracts\Assets\Asset;
use Statamic\Facades\Asset as AssetFacade;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\GuardedRedirectWalk;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;

/**
 * Utility functions for asset importing.
 */
class AssetUtils
{
    /**
     * Total time budget in seconds for one existence check, across every hop of
     * its redirect chain.
     *
     * The check previously passed no timeout at all, leaving each hop on the
     * HTTP client's 30-second default and a full chain able to spend
     * `30 * (MAX_REDIRECT_HOPS + 1)` seconds. Keeping the same 30 seconds as a
     * whole-chain budget leaves a single-hop check behaving exactly as before
     * while bounding the chain (wordpress-importer-archive#542).
     *
     * A constant is not a line coverage can reach, so a mutant here always reads
     * as uncovered. AssetUtilsTest pins 30, and changing it by hand fails that
     * test.
     */
    // @pest-mutate-ignore: IncrementInteger, DecrementInteger
    public const EXISTENCE_CHECK_TIMEOUT_SECONDS = 30;

    /**
     * Check if a file exists at the given URL.
     *
     * Follows redirects through the shared guarded walk, so a WXR-supplied URL
     * cannot probe internal services either directly or via a redirect to a
     * loopback/link-local/private host (SSRF — wordpress-importer-archive#394).
     *
     * @param  string  $url  The URL to check
     * @return bool True if the file exists, false otherwise
     */
    public static function fileExists(
        string $url
    ): bool {
        // Report nothing: an existence check is a predicate, and its callers
        // decide what an absent file means
        $result = new GuardedRedirectWalk(
            timeoutSeconds: self::EXISTENCE_CHECK_TIMEOUT_SECONDS,
            wantsBody: false,
            swallowsTransportFailures: false,
        )->follow($url);

        // A refused, looping, or over-long chain is not an existing file; a
        // terminal response is one only when it succeeded
        return $result !== null
            && $result['response']->successful();
    }

    /**
     * Guess the MIME type based on file extension.
     *
     * @param  string  $filename  The filename to analyze
     * @return string The guessed MIME type
     */
    public static function guessMimeType(
        string $filename
    ): string {
        // Extract file extension and convert to lowercase
        $extension = \strtolower(pathinfo(
            $filename,
            PATHINFO_EXTENSION
        ));

        // Match extension to MIME type
        return match ($extension) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'pdf' => 'application/pdf',
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'mp3' => 'audio/mpeg',
            'mp4' => 'video/mp4',
            default => 'application/octet-stream',
        };
    }

    /**
     * Check if a URL is on the same site as the base blog URL.
     *
     * @param  string  $url  The URL to check
     * @param  string  $baseBlogUrl  The base blog URL for comparison
     * @return bool True if the URL is on the same site, false otherwise
     */
    public static function isSameSiteUrl(
        string $url,
        string $baseBlogUrl
    ): bool {
        // Check if base blog URL is empty
        if ($baseBlogUrl === '') {
            // Consider all URLs as same site
            return true;
        }

        // ---
        // Normalize URLs to lowercase for comparison
        // ---

        // Convert URL to lowercase
        $url = \strtolower($url);

        // Convert base blog URL to lowercase
        $baseBlogUrl = \strtolower($baseBlogUrl);

        // ---
        // Remove protocol if present
        // ---

        // Remove http:// or https:// protocol from URL
        $urlWithoutProtocol = \preg_replace(
            '#^https?:#',
            '',
            $url
        );
        $url = is_string($urlWithoutProtocol)
            ? $urlWithoutProtocol
            : $url;

        // Remove http:// or https:// protocol from base blog URL
        $baseBlogUrlWithoutProtocol = \preg_replace(
            '#^https?:#',
            '',
            $baseBlogUrl
        );
        $baseBlogUrl = is_string($baseBlogUrlWithoutProtocol)
            ? $baseBlogUrlWithoutProtocol
            : $baseBlogUrl;

        // ---
        // Remove leading slashes
        // ---

        // Remove leading slashes from URL
        $url = \ltrim(
            $url,
            '/'
        );

        // Remove leading slashes from base blog URL
        $baseBlogUrl = \ltrim(
            $baseBlogUrl,
            '/'
        );

        // ---
        // Remove trailing slashes from the base
        //
        // The prefix check below assumes the base ends WITHOUT a trailing
        // slash: it treats the character at strlen($baseBlogUrl) as the path
        // separator. When a WXR's <wp:base_blog_url> carries a trailing slash
        // that character is instead the first char of the next path segment,
        // so a genuine same-site URL is misclassified as external (wordpress-importer-archive#30).
        // The URL's own trailing slashes need no trimming: whatever follows
        // the base in it, a slash still marks the path boundary.
        // ---

        // Remove trailing slashes from base blog URL
        $baseBlogUrl = \rtrim(
            $baseBlogUrl,
            '/'
        );

        // ---
        // Check if the URL starts with the base blog URL followed by a path separator or
        // end of string. This prevents partial matches like "it" matching "itsecurity".
        // ---
        return \str_starts_with(
            $url,
            $baseBlogUrl
        )
        && (
            \strlen($url) === \strlen($baseBlogUrl)
            || $url[\strlen($baseBlogUrl)] === '/'
        );
    }

    /**
     * Checks if a URL is on the same site as the base blog URL, handling null base
     * URLs.
     *
     * This method centralizes the logic for determining if a URL is on the same site,
     * properly handling cases where the base blog URL is null or empty.
     *
     * @param  string  $url  The URL to check
     * @param  ?string  $baseBlogUrl  The base blog URL (can be null)
     * @return bool True if the URL is on the same site or if base URL is null/empty
     */
    public static function isUrlOnSameSite(
        string $url,
        ?string $baseBlogUrl
    ): bool {
        // Consider all URLs as same site without a base; isSameSiteUrl() does the
        // same for an empty one
        return $baseBlogUrl === null
            || self::isSameSiteUrl(
                $url,
                $baseBlogUrl
            );
    }

    /**
     * Normalizes a WordPress image URL by removing size suffixes.
     *
     * Removes WordPress image size suffixes (e.g., -123x456) from URLs to get
     * the base image URL. This ensures that different sized versions of the same
     * image are treated as the same asset for caching purposes.
     *
     * @param  string  $url  The image URL to normalize
     * @return string The normalized URL without size suffix, or original URL if no size suffix is found
     */
    public static function normalizeWordPressImageUrl(
        string $url
    ): string {
        // Check if URL contains WordPress image size suffix pattern
        if (
            \preg_match(
                '/^(.+)-(\d+x\d+)(\.[^.]+)$/',
                $url,
                $matches
            ) === 1
        ) {
            // Return base URL without size suffix
            return "{$matches[1]}{$matches[3]}";
        }

        // Return original URL if no size suffix found
        return $url;
    }

    /**
     * Attempts to download the full-size version of a WordPress image URL.
     *
     * Checks if the URL contains WordPress image size suffixes (e.g., -123x456)
     * and attempts to download the full-size version first. If that fails,
     * falls back to the original URL.
     *
     * @param  string  $url  The image URL to process
     * @param  callable(string, string, string, OutputInterface, int, int, bool, ?string): ?string  $downloadCallback  Function to call for downloading
     * @param  string  $path  The target path for the download
     * @param  string  $container  The Statamic asset container
     * @param  OutputInterface  $output  Console output for logging
     * @param  int  $timeout  Timeout in seconds
     * @param  int  $maxRetries  Maximum retry attempts
     * @param  bool  $skipOnFailure  Whether to skip on failure
     * @param  ?string  $pageUrl  The page URL for context
     * @return ?string The downloaded path or null if failed
     */
    public static function downloadImageWithFullSizeFallback(
        string $url,
        callable $downloadCallback,
        string $path,
        string $container,
        OutputInterface $output,
        int $timeout = 60,
        int $maxRetries = 3,
        bool $skipOnFailure = false,
        ?string $pageUrl = null
    ): ?string {
        // Check if URL contains WordPress image size suffix pattern
        if (
            \preg_match(
                '/^(.+)-(\d+x\d+)(\.[^.]+)$/',
                $url,
                $matches
            ) === 1
        ) {
            // Construct base URL without size suffix
            $baseUrl = "{$matches[1]}{$matches[3]}";

            // Extract the size suffix for logging purposes
            $sizeSuffix = $matches[2];

            // Inform user about detected size suffix and fallback attempt
            $output->writeln("      - <comment>Detected WordPress image size suffix '$sizeSuffix'. Attempting full-size version first.</comment>");

            // Try downloading the full-size version first
            $result = $downloadCallback(
                $baseUrl,
                $path,
                $container,
                $output,
                $timeout,
                $maxRetries,
                $skipOnFailure,
                $pageUrl
            );

            // Check if full-size download was successful
            if (is_string($result)) {
                // Log success message for full-size download
                $output->writeln("      - <info>Successfully downloaded full-size version from '$baseUrl'.</info>");

                // Return the successful download result
                return $result;
            }

            // Log fallback message when full-size download failed
            $output->writeln('      - <comment>Full-size version failed. Falling back to original URL with size suffix.</comment>');
        }

        // Fall back to original URL
        $fallbackResult = $downloadCallback(
            $url,
            $path,
            $container,
            $output,
            $timeout,
            $maxRetries,
            $skipOnFailure,
            $pageUrl
        );

        return is_string($fallbackResult)
            ? $fallbackResult
            : null;
    }

    /**
     * Extracts WordPress upload path information from a URL.
     *
     * Parses WordPress upload URLs to extract year/month information and
     * generates a unique filename that preserves the original upload context.
     * This prevents conflicts when multiple files with the same name exist
     * in different WordPress upload directories.
     *
     * @param  string  $url  The WordPress upload URL
     * @return array{filename: string, year: ?string, month: ?string} Array with unique filename and path info
     */
    public static function extractWordPressPathInfo(
        string $url
    ): array {
        // ---
        // Extract the path portion of the URL (after domain)
        // ---

        // Parse URL into components
        $parsedUrl = parse_url($url);

        // Extract path component from parsed URL. Any default that cannot hold an
        // upload directory reads the same as an empty one
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $path = $parsedUrl['path'] ?? '';

        // ---
        // Look for WordPress upload directory pattern:
        // /wp-content/uploads/sites/{site_id}/{year}/{month}/
        // ---
        if (
            \preg_match(
                '#/wp-content/uploads/sites/\d+/(\d{4})/(\d{2})/#',
                $path,
                $matches
            ) === 1
        ) {
            // Extract year from multisite upload path
            $year = $matches[1];

            // Extract month from multisite upload path
            $month = $matches[2];
        } elseif (
            \preg_match(
                '#/wp-content/uploads/(\d{4})/(\d{2})/#',
                $path,
                $matches
            ) === 1
        ) { // Check for non-multisite WordPress upload pattern
            // ---
            // Handle non-multisite WordPress uploads
            // ---

            // Extract year from non-multisite upload path
            $year = $matches[1];

            // Extract month from non-multisite upload path
            $month = $matches[2];
        } else { // Otherwise
            // ---
            // No year/month found, use current date as fallback
            // ---

            // Use current year as fallback
            $year = \date('Y');

            // Use current month as fallback
            $month = \date('m');
        }

        // ---
        // Get the original filename
        // ---

        // Extract filename from URL
        $originalFilename = basename($url);

        // Generate unique filename with year-month prefix
        $uniqueFilename = "{$year}-{$month}-{$originalFilename}";

        // ---
        // Apply lowercase conversion if configured in Statamic
        // ---

        // Check if lowercase conversion is enabled. The condition reads the value as
        // a boolean with or without the cast
        // @pest-mutate-ignore: RemoveBooleanCast
        if (
            (bool) config(
                'statamic.assets.lowercase',
                false
            )
        ) {
            // Convert filename to lowercase
            $uniqueFilename = \strtolower($uniqueFilename);
        }

        // Return filename and path information
        return [
            'filename' => $uniqueFilename,
            'year' => $year,
            'month' => $month,
        ];
    }

    /**
     * Generates a unique filename for WordPress assets.
     *
     * Creates a filename that includes the original upload year and month
     * to prevent conflicts between files with the same name from different
     * WordPress upload directories.
     *
     * @param  string  $url  The WordPress upload URL
     * @return string The unique filename with year-month prefix
     */
    public static function generateUniqueFilename(
        string $url
    ): string {
        // The path info already lowercases the filename when Statamic is configured to
        return self::extractWordPressPathInfo($url)['filename'];
    }

    /**
     * Applies same-site policy to determine if an asset should be imported.
     *
     * Centralizes the logic for handling assets from different sites based on
     * the specified policy.
     *
     * @param  string  $url  The asset URL to check
     * @param  SameSitePolicy  $policy  The policy to apply for different-site assets
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?string  $baseBlogUrl  The base blog URL for same-site comparison
     * @return SameSiteDecision Whether to import, and what to return if not
     */
    public static function applySameSitePolicy(
        string $url,
        SameSitePolicy $policy,
        OutputInterface $output,
        ?string $baseBlogUrl
    ): SameSiteDecision {
        // Check if asset is on the same site
        $isSameSite = self::isUrlOnSameSite(
            $url,
            $baseBlogUrl
        );

        // ---
        // If same site, always import (continue with import process)
        // ---

        // Check if asset is on the same site
        if ($isSameSite) {
            // Continue with the import process
            return SameSiteDecision::import();
        }

        // Apply policy for different-site assets
        switch ($policy) {
            // Skip downloading and leave nothing in the asset's place
            case SameSitePolicy::SKIP_DIFFERENT_SITE:
                // Log that we're skipping the download
                $output->writeln("  - <comment>Asset not on same site detected. Skipping download of '$url'.</comment>");

                // Say that no import is to be attempted
                return SameSiteDecision::skip();

                // Skip downloading but return original URL for direct linking
            case SameSitePolicy::LINK_DIFFERENT_SITE:
                // Log that we're linking directly to the original URL
                $output->writeln("  - <comment>Asset not on same site detected. Linking directly to '$url'.</comment>");

                // Keep the original URL for direct linking
                return SameSiteDecision::link($url);

                // Always attempt to download regardless of site
            case SameSitePolicy::ALWAYS_IMPORT:
                // Log that we're importing from a different site
                $output->writeln("  - <comment>Asset not on same site detected. Importing directly from '$url'.</comment>");

                // Continue with the import process
                return SameSiteDecision::import();
        }
    }

    /**
     * Safely finds an asset by ID, handling asset metadata DB errors gracefully.
     *
     * When querying for assets, duplicate or corrupted metadata can cause a
     * UniqueConstraintViolationException. This method wraps the asset lookup
     * in a try-catch to handle such errors gracefully, allowing the import
     * to continue.
     *
     * @param  string  $assetId  The asset ID to find (format: "container::path")
     * @param  string  $container  The asset container name for logging
     * @param  string  $path  The asset path for logging
     * @param  ?string  $pageUrl  Optional page URL for log context
     * @param  string  $contextMethod  The method name for log context (e.g., "OverwriteHandler::handleFileImport")
     * @return Asset|null The found asset, or null if not found or if an error occurred
     */
    public static function findAssetSafely(
        string $assetId,
        string $container,
        string $path,
        ?string $pageUrl = null,
        string $contextMethod = 'AssetUtils::findAssetSafely'
    ): ?Asset {
        // Attempt to find the asset, catching asset metadata DB constraint errors
        try {
            // Query Statamic for the asset by ID
            return AssetFacade::find($assetId);
        }

        // Handle unique constraint violations from the asset metadata database
        catch (UniqueConstraintViolationException $uniqueConstraintViolationException) {
            // ---
            // Duplicate container/folder/basename in asset metadata
            // ---

            // Build log context with asset and error information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'asset_id' => $assetId,
                    'container' => $container,
                    'path' => $path,
                    'error' => $uniqueConstraintViolationException->getMessage(),
                ],
                $pageUrl
            );

            // Log warning about asset metadata constraint error
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "{$contextMethod}: Asset metadata constraint error when checking for existing asset. Assuming asset does not exist and proceeding.",
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate asset not found (assume it doesn't exist)
            return null;
        }
    }
}
