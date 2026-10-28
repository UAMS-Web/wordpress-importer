<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Assets;

use Exception;
use getID3;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use RuntimeException;
use Statamic\Assets\Asset;
use Statamic\Assets\AssetContainer as StatamicAssetContainer;
use Statamic\Facades\AssetContainer as AssetContainerFacade;
use Statamic\Facades\Glide;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\GuardedFetchRefusal;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\GuardedRedirectWalk;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\OutboundUrlGuard;

/**
 * Handles downloading and storing assets from URLs into Statamic containers.
 */
class AssetDownloader
{
    /**
     * Seconds to wait for a connection to be established on any one hop of an
     * asset download.
     *
     * Distinct from the download's own time budget: this bounds only the TCP
     * and TLS handshake, so an unresponsive host fails fast while a slow but
     * live transfer keeps whatever is left of the chain budget.
     *
     * A constant is not a line coverage can reach, so a mutant here always reads
     * as uncovered. AssetDownloaderPathsTest pins 30, and changing it by hand
     * fails that test. That the walk is handed it is not asserted: the HTTP fake does
     * not expose the connect timeout a request was sent with.
     */
    // @pest-mutate-ignore: IncrementInteger, DecrementInteger
    public const CONNECT_TIMEOUT_SECONDS = 30;

    /**
     * Initializes the AssetDownloader, ensuring getID3 is available.
     *
     * @throws RuntimeException If getID3 library is not installed
     */
    public function __construct()
    {
        // Check if getID3 class is available
        if (! class_exists('getID3')) {
            // Throw exception if getID3 library is not installed
            throw new RuntimeException('The getID3 library is required for asset metadata processing.');
        }
    }

    /**
     * Downloads an asset from a URL and stores it in a Statamic container.
     *
     * Streams the asset content via HTTP to a temporary file, then uploads
     * it to the specified Statamic container. For audio/video assets,
     * extracts metadata using getID3 and sets duration. Logs progress and
     * errors via the console output. Ensures temporary resources are cleaned
     * up immediately after use to minimize memory usage.
     *
     * @param  string  $url  The URL of the asset to download
     * @param  string  $path  The target path in the Statamic container
     * @param  string  $container  The Statamic asset container ID
     * @param  OutputInterface  $output  Console output for logging
     * @param  int  $timeout  Timeout in seconds for the HTTP request (default: 60)
     * @param  int  $maxRetries  Maximum number of retry attempts (default: 3)
     * @param  bool  $skipOnFailure  Whether to skip failed downloads and return the original URL (default: false)
     * @param  ?string  $pageUrl  The URL of the page/post where this asset was referenced (default: null)
     * @return ?string The actual final path that Statamic created, or null if failed
     */
    public function download(
        string $url,
        string $path,
        string $container,
        OutputInterface $output,
        // A second either way only moves the deadline of a fetch no test lets run
        // that long
        // @pest-mutate-ignore: IncrementInteger, DecrementInteger
        int $timeout = 60,
        int $maxRetries = 3,
        bool $skipOnFailure = false,
        ?string $pageUrl = null
    ): ?string {
        // Create a temporary file for streaming the download
        $tempPath = tempnam(
            sys_get_temp_dir(),
            'wp_import_'
        );

        // Attempt download with retry logic
        $response = $this->downloadWithRetry(
            $url,
            $tempPath,
            $timeout,
            $maxRetries,
            $output
        );

        // Check if download failed or response was unsuccessful
        if (
            ! $response instanceof Response
            || ! $response->successful()
        ) {
            // Log failure and clean up temporary file
            $output->writeln("      - <error>Failed to download asset from '$url' after $maxRetries attempts.</error>");

            // Add failed URL to collector for end-of-import reporting
            FailedAssetCollector::addFailedUrl(
                $url,
                $pageUrl
            );

            // ---
            // Remove temporary file to prevent disk clutter
            // ---

            // Check if temporary file exists before attempting to delete
            if (\file_exists($tempPath)) {
                // Delete temporary file
                unlink($tempPath);
            }

            // Free response object
            unset($response);

            // ---
            // If skipOnFailure is true, return the original URL instead of null
            // ---

            // Check if skipOnFailure flag is enabled
            if ($skipOnFailure) {
                // Log that we're continuing with original URL
                $output->writeln('      - <comment>Continuing with original URL due to skip-failed-assets setting.</comment>');

                // Return original URL for direct linking
                return $url;
            }

            return null;
        }

        // Free response object immediately after use
        unset(
            $response
        );

        // Extract the filename from the target path
        $filename = basename($path);

        // ---
        // Check if asset already exists using the correct Asset API
        // ---

        // Construct asset ID using container and path format
        $assetId = "{$container}::{$path}";

        // Find existing asset by ID, handling asset metadata DB errors
        $existingAsset = AssetUtils::findAssetSafely(
            $assetId,
            $container,
            $path,
            $pageUrl,
            'AssetDownloader::download'
        );

        // ---
        // Carry the existing asset's metadata across the overwrite. Replacing the
        // binary must not discard what earlier import paths resolved onto the asset —
        // `title` (file path only), `wordpress_attachment_page_url`, `alt`, `caption`,
        // `creator`, `focus`. Without this, an attachment imported as a featured image
        // and then again as an inline body image loses everything the first pass
        // wrote, because the delete below takes the meta file with it and the write
        // further down replaces rather than merges (wordpress-importer-archive#489).
        // ---
        $preservedData = [];

        // Check if asset already exists in the container
        if ($existingAsset instanceof Asset) {
            // Read the metadata off the asset before it is destroyed
            $preservedData = self::readAssetData($existingAsset);

            // Delete existing asset to ensure overwrite
            $existingAsset->delete();
        }

        // ---
        // Create a new asset instance for the specified container and path
        // ---

        // Find the asset container by ID
        $containerObj = AssetContainerFacade::find($container);

        // Check if the asset container exists
        if (! $containerObj instanceof StatamicAssetContainer) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'url' => $url,
                    'path' => $path,
                    'container' => $container,
                ],
                $pageUrl
            );

            // Log error for missing asset container
            Log::error(
                LogContextHelper::formatMessageWithEntry(
                    "AssetDownloader::download: Asset container '$container' not found. Skipping asset import.",
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Output error to console
            $output->writeln("      - <error>Asset container '$container' not found. Skipping asset import.</error>");

            // Add failed URL to collector for end-of-import reporting
            FailedAssetCollector::addFailedUrl(
                $url,
                $pageUrl
            );

            // Check if temporary file exists before attempting to delete
            if (\file_exists($tempPath)) {
                // Delete temporary file
                unlink($tempPath);
            }

            // Check if skipOnFailure flag is enabled
            if ($skipOnFailure) {
                // Log that we're continuing with original URL
                $output->writeln('      - <comment>Continuing with original URL due to skip-failed-assets setting.</comment>');

                // Return original URL for direct linking
                return $url;
            }

            // Return null to indicate failure
            return null;
        }

        // Create new asset instance for the specified path
        $asset = $containerObj->makeAsset($path);

        // Create an UploadedFile instance for Statamic upload
        $detectedMimeType = \mime_content_type($tempPath);
        $mimeType = \is_string($detectedMimeType)
            ? $detectedMimeType
            : AssetUtils::guessMimeType($filename);

        $uploadedFile = new UploadedFile(
            $tempPath,
            $filename,
            $mimeType,
            null,
            true
        );

        // Initialize final path before upload attempt
        $finalPath = null;

        // Attempt to upload and save the asset, handling validation errors gracefully
        try {
            // Upload the file to the Statamic asset container
            $asset->upload($uploadedFile);

            // ---
            // Process metadata for audio or video assets using getID3
            // ---

            // Check if asset is audio or video type
            if (
                $asset->isAudio()
                || $asset->isVideo()
            ) {
                // Create getID3 instance for metadata extraction
                $getId3 = new getID3;

                // ---
                // Analyze the temporary file to extract metadata
                // ---

                // Analyze file to extract metadata information
                $fileInfo = $getId3->analyze($tempPath);

                // Initialize metadata array for asset data
                $metadata = [];

                // Check if playtime duration is available in file info
                if (
                    isset($fileInfo['playtime_seconds'])
                    && is_numeric($fileInfo['playtime_seconds'])
                ) {
                    // Set duration metadata if available
                    $metadata['duration'] = (int) $fileInfo['playtime_seconds'];
                }

                // Set original WordPress URL metadata
                $metadata['original_wordpress_url'] = $url;

                // Set metadata on asset, keeping what the overwritten asset carried
                $asset->data([
                    ...$preservedData,
                    ...$metadata,
                ]);

                // Clean up variables to free up memory
                unset(
                    $fileInfo,
                    $getId3
                );
            } else { // Handle non-audio/video files
                // Set original WordPress URL metadata for non-audio/video files,
                // keeping what the overwritten asset carried
                $asset->data([
                    ...$preservedData,
                    'original_wordpress_url' => $url,
                ]);
            }

            // Save the asset with all metadata
            $asset->save();

            // Get the actual final path that Statamic created (after sanitization)
            $savedPath = $asset->path();
            $finalPath = is_string($savedPath) ? $savedPath : null;

            // Free asset object immediately after use
            unset(
                $asset
            );
        }

        // Handle exceptions that occur during upload or validation
        catch (Exception $exception) {
            // Check if this is an image validation error
            $isImageValidationError = \str_contains(
                $exception->getMessage(),
                'does not actually appear to be a valid image'
            );

            // ---
            // Gather diagnostic information for image validation errors
            // ---

            // Initialize diagnostics array
            $diagnostics = [];

            // Check if this is an image validation error and file exists
            if (
                $isImageValidationError
                && \file_exists($tempPath)
            ) {
                // Gather diagnostic information about the file
                $diagnostics = $this->gatherImageDiagnostics(
                    $tempPath,
                    $filename,
                    $uploadedFile
                );
            }

            // ---
            // Decide whether the source is worth keeping before deleting anything
            // ---

            // Whether the source file reached the container before the exception.
            //
            // **This is the whole of wordpress-importer-archive#1161.** `$asset->upload()` writes the source first and only
            // then saves, which dispatches `AssetUploaded` and runs Statamic's preset generator. A
            // Glide write failing there throws *after* the source is safely stored — and the
            // cleanup below, written for an invalid download, then deleted it. A regenerable
            // derivative was costing the irreplaceable original, roughly once per 160 assets.
            $sourceStored = $this->sourceReachedContainer($asset);

            // Clear the half-written derivative cache either way. A failed Glide write leaves an
            // empty directory under the glide cache root, and a later attempt then reads a
            // directory that exists and is empty rather than regenerating cleanly (wordpress-importer-archive#1161).
            $this->clearFailedDerivativeCache($asset);

            // Delete only what is genuinely not worth keeping: a file that failed validation, or
            // one that never landed. A stored source outlives its failed derivatives.
            if ($isImageValidationError || ! $sourceStored) {
                // Attempt to delete the invalid asset if it was created
                try {
                    // Delete the invalid asset to prevent orphaned files
                    $asset->delete();
                }

                // Ignore errors during cleanup
                catch (Exception) {
                    // Silently ignore cleanup errors
                }
            }

            // Free asset object
            unset($asset);

            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'url' => $url,
                    'path' => $path,
                    'container' => $container,
                    'error' => $exception->getMessage(),
                ],
                $pageUrl
            );

            // ---
            // Add diagnostic information to log context if available
            // ---

            // Check if diagnostics are available
            if ($diagnostics !== []) {
                // Merge diagnostics into log context
                $logData['context'] = [
                    ...$logData['context'],
                    ...$diagnostics,
                ];
            }

            // Check if this is an image validation error to log appropriately
            if ($isImageValidationError) {
                // ---
                // Build detailed error message with diagnostics
                // ---

                // Initialize error details message
                $errorDetails = "Downloaded file from '$url' does not appear to be a valid image file.";

                // Check if diagnostics are available
                if ($diagnostics !== []) {
                    // Append diagnostics label to error message
                    $errorDetails .= ' Diagnostics: ';

                    // Initialize array for diagnostic detail parts
                    $detailParts = [];

                    // Check if file size is available
                    if (\is_int($diagnostics['file_size_bytes'] ?? null)) {
                        // Add file size to detail parts
                        $detailParts[] = 'size='.number_format(
                            $diagnostics['file_size_bytes']
                        ).' bytes';
                    }

                    // Check if detected MIME type is available
                    if (\is_string($diagnostics['detected_mime_type'] ?? null)) {
                        // Add detected MIME type to detail parts
                        $detailParts[] = 'detected_mime='.$diagnostics['detected_mime_type'];
                    }

                    // Check if uploaded file MIME type is available
                    if (\is_string($diagnostics['uploaded_file_mime_type'] ?? null)) {
                        // Add uploaded file MIME type to detail parts
                        $detailParts[] = 'uploaded_file_mime='.$diagnostics['uploaded_file_mime_type'];
                    }

                    // Check if file extension is available
                    if (\is_string($diagnostics['file_extension'] ?? null)) {
                        // Add file extension to detail parts
                        $detailParts[] = 'extension='.$diagnostics['file_extension'];
                    }

                    // Check if getimagesize result is available
                    if (isset($diagnostics['getimagesize_result'])) {
                        // Add getimagesize result to detail parts
                        $detailParts[] = 'getimagesize='.(
                            $diagnostics['getimagesize_result'] === true
                                ? 'success'
                                : 'failed'
                        );
                    }

                    // Check if actual file type is available
                    if (\is_string($diagnostics['actual_file_type'] ?? null)) {
                        // Add actual file type to detail parts
                        $detailParts[] = 'actual_type='.$diagnostics['actual_file_type'];
                    }

                    // Join detail parts and append to error message
                    $errorDetails .= implode(', ', $detailParts);
                }

                // Log warning for invalid image files
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "AssetDownloader::download: $errorDetails Skipping asset import.",
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Output warning to console
                $output->writeln(
                    '      - <error>Downloaded file does not appear to be a valid image. Skipping asset import.</error>'
                );
            } else { // Handle other types of exceptions
                // Log error for non-image validation exceptions
                Log::error(
                    LogContextHelper::formatMessageWithEntry(
                        "AssetDownloader::download: Exception occurred during asset upload: {$exception->getMessage()}",
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Output error to console
                $output->writeln(
                    "      - <error>Failed to upload asset: {$exception->getMessage()}</error>"
                );
            }

            // Add failed URL to collector for end-of-import reporting
            FailedAssetCollector::addFailedUrl(
                $url,
                $pageUrl
            );

            // ---
            // Clean up temporary file
            // ---

            // Check if temporary file exists before attempting to delete
            if (\file_exists($tempPath)) {
                // Delete temporary file
                unlink($tempPath);
            }

            // ---
            // If skipOnFailure is true, return the original URL instead of null
            // ---

            // Check if skipOnFailure flag is enabled
            if ($skipOnFailure) {
                // Log that we're continuing with original URL
                $output->writeln('      - <comment>Continuing with original URL due to skip-failed-assets setting.</comment>');

                // Return original URL for direct linking
                return $url;
            }

            // Return null to indicate failure
            return null;
        }

        // ---
        // Clean up temporary file (success path)
        // ---

        // Check if temporary file exists before attempting to delete
        if (\file_exists($tempPath)) {
            // Delete temporary file
            unlink($tempPath);
        }

        // Force garbage collection to free memory. It changes nothing a test can see
        // @pest-mutate-ignore: RemoveFunctionCall
        gc_collect_cycles();

        // Log successful import
        $output->writeln('      - <info>Asset imported successfully.</info>');

        // Return the final path that Statamic created
        return $finalPath;
    }

    /**
     * Remove the derivative cache a failed Glide write left behind.
     *
     * A preset write that fails still creates its directory under the glide cache root, leaving a
     * directory that exists and holds nothing. Statamic's own `clearAsset()` is used rather than
     * deleting a path parsed out of the exception message, which would be a directory removal
     * driven by an error string.
     *
     * Best-effort by design: this runs inside a `catch`, and a cleanup that throws would replace
     * the real failure with its own.
     */
    private function clearFailedDerivativeCache(mixed $asset): void
    {
        if (! \is_object($asset)) {
            return;
        }

        try {
            Glide::clearAsset($asset);
        } catch (\Throwable) {
            // A cache that cannot be cleared is not worth losing the original error over.
        }
    }

    /**
     * Whether the source file reached the container before an exception was thrown.
     *
     * **Asked rather than assumed, because the answer decides whether cleanup destroys the
     * original (wordpress-importer-archive#1161).** `Asset::upload()` writes the file and *then* saves, and saving dispatches
     * `AssetUploaded`, whose listener generates Glide presets. A preset write failing throws from
     * inside `upload()` with the source already on disk, which is indistinguishable at the catch
     * site from a download that never landed.
     *
     * Defensive on purpose: `exists()` is on Statamic's concrete `Asset` rather than its contract,
     * and the asset here comes from a container that tests replace with a mock. Anything that
     * cannot answer is treated as **not** stored, so an unknown state falls back to the previous
     * behavior rather than silently keeping a file that is not there.
     */
    private function sourceReachedContainer(object $asset): bool
    {
        // Resolved as a callable rather than probed with `method_exists()`: an asset supplied
        // through a proxy answers `exists()` via `__call` and reports no such method, which would
        // send every such asset down the delete path, the failure this guard exists to prevent.
        $probe = [$asset, 'exists'];

        // Calling a probe that is not callable throws, and the catch below answers false the
        // same way, so this return is here for the call's type rather than for the result
        if (! \is_callable($probe)) {
            // @pest-mutate-ignore: RemoveEarlyReturn
            return false;
        }

        try {
            return $probe() === true;
        } catch (\Throwable) {
            // Anything that cannot answer is not evidence the source is there.
            return false;
        }
    }

    /**
     * Downloads an asset with retry logic and exponential backoff.
     *
     * Attempts to download the asset multiple times with increasing delays
     * between attempts. Uses configurable timeout and retry count.
     *
     * @param  string  $url  The URL of the asset to download
     * @param  string  $tempPath  The temporary file path to stream to
     * @param  int  $timeout  Timeout in seconds for each HTTP request
     * @param  int  $maxRetries  Maximum number of retry attempts
     * @param  OutputInterface  $output  Console output for logging
     * @return ?Response The HTTP response or null if all attempts failed
     */
    private function downloadWithRetry(
        string $url,
        string $tempPath,
        int $timeout,
        int $maxRetries,
        OutputInterface $output
    ): ?Response {
        // Refuse internal/loopback/link-local/private targets up front so an
        // attacker-authored WXR cannot make the importer read internal services
        // and store the response as an asset (SSRF). The https-upgrade and http
        // fallback share this host, so one check covers every attempt.
        if (! OutboundUrlGuard::isAllowedUrl($url)) {
            $output->writeln("      - <comment>Skipping non-public asset URL: $url</comment>");

            return null;
        }

        // Initialize attempt counter for retry logic
        $attempt = 0;

        // Preserve the original URL for fallback attempts
        $originalUrl = $url;

        // Track the current URL being attempted (may change from original)
        $currentUrl = $url;

        // Initialize HTTPS version of URL (null if not applicable)
        $httpsUrl = null;

        // Check if URL uses http:// protocol to enable HTTPS fallback
        if (
            \str_starts_with(
                $url,
                'http://'
            )
        ) {
            // Create HTTPS version of the URL for first attempt
            $httpsUrl = \str_replace(
                'http://',
                'https://',
                $url
            );
        }

        // Retry download until max retries reached
        while ($attempt < $maxRetries) {
            // Increment attempt counter
            $attempt++;

            // Reset current URL to original for this attempt
            $currentUrl = $url;

            // Check if this is the first attempt and we have an HTTPS version to try
            if (
                $attempt === 1
                && $httpsUrl !== null
            ) {
                // Use HTTPS version for the first attempt
                $currentUrl = $httpsUrl;

                // Log that we're attempting the HTTPS version first
                $output->writeln("      - <comment>Attempting HTTPS version first: $currentUrl</comment>");
            }

            // Attempt to download the asset
            try {
                // Stream the response to the temporary file, following redirects
                // manually so the egress guard re-validates every hop. A public
                // URL that 30x-redirects to an internal host is refused at that
                // hop and the internal host is never requested (SSRF — wordpress-importer-archive#394).
                $response = $this->fetchFollowingRedirects(
                    $currentUrl,
                    $tempPath,
                    $timeout,
                    $output
                );

                if (
                    $response instanceof Response
                    && $response->successful()
                ) {
                    return $response;
                }

            }

            // Log HTTP error but continue to retry (no output to reduce noise)
            catch (ConnectionException $e) {
                // Get human-readable error type from exception
                $errorType = $this->getConnectionErrorType($e);

                // Check if this was the HTTPS attempt that failed
                if (
                    $attempt === 1
                    && $currentUrl === $httpsUrl
                ) {
                    // Log that HTTPS failed and we're falling back to HTTP
                    $output->writeln("      - <comment>HTTPS attempt failed ($errorType). Trying original HTTP URL...</comment>");

                    // Switch to original URL for remaining attempts
                    $currentUrl = $originalUrl;

                    // Don't increment attempt counter, try original URL immediately
                    continue;
                }

                // Log connection error and retry attempt
                $output->writeln("      - <comment>Connection error on attempt $attempt ($errorType). Retrying...</comment>");

                // Check if more retry attempts are available
                if ($attempt < $maxRetries) {
                    // Calculate exponential backoff delay
                    $retryDelay = $attempt * 2;

                    // Wait before next retry attempt
                    Sleep::sleep($retryDelay); // Exponential backoff: 2, 4, 6 seconds
                }
            }

            // Handle HTTP request exceptions
            catch (RequestException) {
                // If this was the HTTPS attempt and it failed, try original HTTP URL next
                if (
                    $attempt === 1
                    && $currentUrl === $httpsUrl
                ) {
                    // Log HTTPS failure and fallback to HTTP
                    $output->writeln('      - <comment>HTTPS attempt failed. Trying original HTTP URL...</comment>');

                    // Switch to original URL for remaining attempts
                    $currentUrl = $originalUrl;

                    // Continue to next attempt without incrementing counter, try original URL immediately
                    continue;
                }

                // Log HTTP request error and retry attempt
                $output->writeln("      - <comment>HTTP request error on attempt $attempt. Retrying...</comment>");

                // Check if more retry attempts are available
                if ($attempt < $maxRetries) {
                    // Calculate exponential backoff delay
                    $retryDelay = $attempt * 2;

                    // Wait before next retry attempt
                    Sleep::sleep($retryDelay);
                }
            }

            // Handle any other unexpected exceptions
            catch (Exception) {
                // If this was the HTTPS attempt and it failed, try original HTTP URL next
                if (
                    $attempt === 1
                    && $currentUrl === $httpsUrl
                ) {
                    // Log HTTPS failure and fallback to HTTP
                    $output->writeln('      - <comment>HTTPS attempt failed. Trying original HTTP URL...</comment>');

                    // Switch to original URL for remaining attempts
                    $currentUrl = $originalUrl;

                    // Continue to next attempt without incrementing counter, try original URL immediately
                    continue;
                }

                // Log unexpected error and retry attempt
                $output->writeln("      - <comment>Unexpected error on attempt $attempt. Retrying...</comment>");

                // Check if more retry attempts are available
                if ($attempt < $maxRetries) {
                    // Calculate exponential backoff delay
                    $retryDelay = $attempt * 2;

                    // Wait before next retry attempt
                    Sleep::sleep($retryDelay);
                }
            }
        }

        // All attempts failed
        return null;
    }

    /**
     * Streams a GET to the temporary file, following HTTP redirects manually so
     * every hop is re-validated by the egress guard.
     *
     * Guzzle's automatic redirect-following is disabled (`allow_redirects` =>
     * false). On each 3xx the `Location` target is resolved to an absolute URL
     * (handling relative and scheme-relative redirects) and re-checked against
     * {@see OutboundUrlGuard::isAllowedUrl()} before the next request is sent,
     * closing the redirect-based SSRF vector: a public URL that redirects to a
     * loopback/link-local/private host is refused at that hop and the internal
     * host is never requested (wordpress-importer-archive#394).
     *
     * Returns the first non-redirect response (the caller's `successful()` check
     * decides what to do with it), or null when a hop targets a non-public host,
     * the chain loops, or it exceeds {@see OutboundUrlGuard::MAX_REDIRECT_HOPS}.
     *
     * The walk itself lives in {@see GuardedRedirectWalk}, shared with the
     * existence-check and link-resolution paths (wordpress-importer-archive#542); this method only
     * supplies the download path's own console reporting and its
     * transport-failure policy.
     *
     * @param  string  $url  The URL to fetch (already allowed by the caller)
     * @param  string  $tempPath  The temporary file the body is streamed to
     * @param  int  $timeout  Time budget in seconds for the whole redirect chain
     * @param  OutputInterface  $output  Console output for logging
     * @return ?Response The terminal response, or null if the fetch was refused
     */
    private function fetchFollowingRedirects(
        string $url,
        string $tempPath,
        int $timeout,
        OutputInterface $output
    ): ?Response {
        // `downloadWithRetry()` reads `ConnectionException` to decide whether to
        // retry and whether to fall back from HTTPS to HTTP, so the walk must
        // let a transport failure through rather than reporting it as a refusal
        $result = new GuardedRedirectWalk(
            timeoutSeconds: $timeout,
            wantsBody: true,
            sink: $tempPath,
            connectTimeoutSeconds: self::CONNECT_TIMEOUT_SECONDS,
            onRefusal: static function (
                GuardedFetchRefusal $reason,
                string $rejectedUrl,
                string $originalUrl,
                ?string $detail
            ) use ($output, $timeout): void {
                // Phrase the structured reason as this path's own console line
                $message = match ($reason) {
                    GuardedFetchRefusal::NonPublicTarget => "Refusing non-public redirect target: $rejectedUrl",
                    GuardedFetchRefusal::BudgetExhausted => "Exhausted the {$timeout}s fetch budget for: $rejectedUrl",
                    GuardedFetchRefusal::TransportFailure => "Transport failure fetching: $rejectedUrl ($detail)",
                    GuardedFetchRefusal::UnparseableLocation => "Unparseable redirect target from $originalUrl: $rejectedUrl",
                    GuardedFetchRefusal::RedirectLoop => "Redirect loop detected at: $rejectedUrl",
                    GuardedFetchRefusal::TooManyRedirects => 'Too many redirects (>'.OutboundUrlGuard::MAX_REDIRECT_HOPS.") for: $originalUrl",
                };

                $output->writeln("      - <comment>$message</comment>");
            },
            swallowsTransportFailures: false,
        )->follow($url);

        return $result === null
            ? null
            : $result['response'];
    }

    /**
     * Determines the type of connection error for better error reporting.
     *
     * @param  ConnectionException  $e  The connection exception
     * @return string A human-readable error type description
     */
    private function getConnectionErrorType(
        ConnectionException $e
    ): string {
        // Get exception message for error type detection
        $message = $e->getMessage();

        // Check if error message indicates connection timeout
        if (
            str_contains(
                $message,
                'Connection timed out'
            )
        ) {
            // Return timeout error type
            return 'timeout';
        }

        // Check if error message indicates connection refused
        if (
            str_contains(
                $message,
                'Connection refused'
            )
        ) {
            // Return connection refused error type
            return 'connection refused';
        }

        // Check if error message indicates DNS resolution failure
        if (
            str_contains(
                $message,
                'Name or service not known'
            )
        ) {
            // Return DNS resolution failed error type
            return 'DNS resolution failed';
        }

        // Check if error message indicates SSL/TLS error
        if (
            str_contains(
                $message,
                'SSL'
            )
        ) {
            // Return SSL/TLS error type
            return 'SSL/TLS error';
        }

        // Return generic network error type as fallback
        return 'network error';
    }

    /**
     * Gathers diagnostic information about a file that failed image validation.
     *
     * Collects file size, MIME types, extension, getimagesize() result, and
     * actual file type from magic bytes to help diagnose why image validation
     * failed.
     *
     * @param  string  $tempPath  The temporary file path
     * @param  string  $filename  The filename
     * @param  ?UploadedFile  $uploadedFile  The UploadedFile instance, when still available
     * @return array<int|string, mixed> Diagnostic information about the file
     */
    private function gatherImageDiagnostics(
        string $tempPath,
        string $filename,
        ?UploadedFile $uploadedFile = null
    ): array {
        // Initialize diagnostics array
        $diagnostics = [];

        // ---
        // Get file size in bytes
        // ---

        // Check if file exists
        if (\file_exists($tempPath)) {
            // Get file size
            $fileSize = \filesize($tempPath);

            // Check if file size was retrieved successfully
            if (\is_int($fileSize)) {
                // Store file size in diagnostics
                $diagnostics['file_size_bytes'] = $fileSize;
            }
        }

        // ---
        // Get MIME type from mime_content_type()
        // ---

        // Detect MIME type using mime_content_type()
        $detectedMimeType = \mime_content_type($tempPath);

        // Check if MIME type was detected successfully
        if (\is_string($detectedMimeType)) {
            // Store detected MIME type in diagnostics
            $diagnostics['detected_mime_type'] = $detectedMimeType;
        }

        // Get MIME type from UploadedFile
        if ($uploadedFile instanceof UploadedFile) {
            try {
                // Get MIME type from UploadedFile instance
                $uploadedFileMimeType = $uploadedFile->getMimeType();

                // Check if MIME type is not null
                if ($uploadedFileMimeType !== null) {
                    // Store uploaded file MIME type in diagnostics
                    $diagnostics['uploaded_file_mime_type'] = $uploadedFileMimeType;
                }
            }

            // Ignore exceptions when getting MIME type
            catch (Exception) {
                // Silently ignore
            }
        }

        // Get file extension
        $extension = \pathinfo(
            $filename,
            PATHINFO_EXTENSION
        );

        // Check if extension is not empty
        if ($extension !== '') {
            // Store file extension in diagnostics
            $diagnostics['file_extension'] = \strtolower($extension);
        }

        // Try to get image dimensions using getimagesize()
        try {
            // Get image size and type information
            $imageSize = \getimagesize($tempPath);

            // Check if getimagesize succeeded
            if ($imageSize !== false) {
                // Mark getimagesize as successful
                $diagnostics['getimagesize_result'] = true;

                // Store image width in diagnostics
                $diagnostics['image_width'] = $imageSize[0];

                // Store image height in diagnostics
                $diagnostics['image_height'] = $imageSize[1];

                // Store image type in diagnostics
                $diagnostics['image_type'] = $imageSize[2];
            } else { // Otherwise
                $diagnostics['getimagesize_result'] = false;
            }
        }

        // Handle exceptions from getimagesize()
        catch (Exception $exception) {
            // Mark getimagesize as failed
            $diagnostics['getimagesize_result'] = false;

            // Store getimagesize error message in diagnostics
            $diagnostics['getimagesize_error'] = $exception->getMessage();
        }

        // ---
        // Detect actual file type from magic bytes (first few bytes)
        // ---

        // Detect actual file type from magic bytes
        $actualFileType = $this->detectFileTypeFromMagicBytes($tempPath);

        // Check if file type was detected
        if ($actualFileType !== null) {
            // Store actual file type in diagnostics
            $diagnostics['actual_file_type'] = $actualFileType;
        }

        // Return diagnostics array
        return $diagnostics;
    }

    /**
     * Detects the actual file type by examining magic bytes (file signature).
     *
     * Reads the first few bytes of the file to identify its actual type,
     * which may differ from the file extension or MIME type.
     *
     * @param  string  $filePath  The path to the file
     * @return ?string The detected file type, or null if unknown
     */
    private function detectFileTypeFromMagicBytes(
        string $filePath
    ): ?string {
        // Read the first 12 bytes, enough to identify most file types. A file that cannot be
        // read fails here, as a warning Laravel raises or a false the check below refuses
        try {
            $bytes = \file_get_contents($filePath, length: 12);
        } catch (Exception) {
            return null;
        }

        // Check the bytes were read and are enough to compare
        if (
            ! \is_string($bytes)
            || \strlen($bytes) < 4
        ) {
            return null;
        }

        // Convert bytes to hex for comparison
        $hex = \bin2hex($bytes);

        // ---
        // Check for common image file signatures
        // ---

        // PNG: 89 50 4E 47 0D 0A 1A 0A
        if (
            \str_starts_with(
                $hex,
                '89504e470d0a1a0a'
            )
        ) {
            // Return PNG file type
            return 'PNG';
        }

        // JPEG: FF D8 FF
        if (
            \str_starts_with(
                $hex,
                'ffd8ff'
            )
        ) {
            // Return JPEG file type
            return 'JPEG';
        }

        // GIF: 47 49 46 38 (GIF8)
        if (
            str_starts_with(
                $hex,
                '47494638'
            )
        ) {
            // Return GIF file type
            return 'GIF';
        }

        // WebP: RIFF ... WEBP
        if (
            \str_starts_with(
                $hex,
                '52494646'
            )
            && str_contains(
                $bytes,
                'WEBP'
            )
        ) {
            // Return WebP file type
            return 'WebP';
        }

        // BMP: 42 4D (BM)
        if (
            \str_starts_with(
                $hex,
                '424d'
            )
        ) {
            // Return BMP file type
            return 'BMP';
        }

        // SVG: Check for XML declaration or <svg tag
        if (
            \str_starts_with(
                $bytes,
                '<?xml'
            )
            || \str_starts_with(
                $bytes,
                '<svg'
            )
        ) {
            // Return SVG file type
            return 'SVG';
        }

        // Check if it's HTML/text (common when server returns error page)
        if (
            \str_starts_with(
                $bytes,
                '<!DOCTYPE'
            )
            || \str_starts_with(
                $bytes,
                '<html'
            )
            || \str_starts_with(
                $bytes,
                '<HTML'
            )
        ) {
            // Return HTML file type
            return 'HTML';
        }

        // Return null if type cannot be determined
        return null;
    }

    /**
     * Reads an asset's metadata as a plain array.
     *
     * Used to capture what an asset carries before an overwrite destroys it, so the
     * replacement can be given the same metadata back (wordpress-importer-archive#489).
     *
     * @param  Asset  $asset  The asset whose data should be read
     * @return array<int|string, mixed> The asset's data, or an empty array when unreadable
     */
    private static function readAssetData(
        Asset $asset
    ): array {
        // Read the asset's current data object
        $assetData = $asset->data();

        // Return an empty array when data() did not yield a readable object
        if (
            ! \is_object($assetData)
            || ! \method_exists(
                $assetData,
                'all'
            )
        ) {
            return [];
        }

        // Collect existing values into a plain array
        $existingData = $assetData->all();

        return \is_array($existingData)
            ? $existingData
            : [];
    }
}
