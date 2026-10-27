<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use Statamic\Entries\Entry;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\SplitExportPartLocator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Traits\DisabledUploadHandlingTrait;

/**
 * Processes HTML to import files linked in <a> tags and update href attributes.
 *
 * Handles links to specific file types (e.g., PDF, DOCX, XLSX, PPTX) by importing
 * them into Statamic's asset containers and updating the href to point to the new
 * file location. Also processes internal WordPress page links by converting them
 * to Statamic entry URLs. Designed for use in WordPress import parsers to
 * preprocess content before Tiptap parsing, ensuring compatibility with
 * Statamic's asset system and maintaining internal link integrity.
 *
 * @phpstan-type DeferredLinkData array{
 *     url: string,
 *     post_name: string,
 *     post_id: string,
 *     post_type: string,
 *     collection_handle: ?string,
 *     source_collection_handle: string,
 *     site: string,
 *     fragment: ?string,
 *     query: ?string
 * }
 * @phpstan-type PostData array{post_id: string, post_name: string, post_type: string}
 * @phpstan-type ParsedUrlComponents array{
 *     base: string,
 *     fragment: ?string,
 *     query: ?string
 * }
 * @phpstan-type ResolvedDeferredLink array{
 *     statamic_url: string,
 *     target_entry: Entry,
 *     wordpress_url: string,
 *     fragment: ?string,
 *     query: ?string
 * }
 */
class LinkProcessor
{
    /**
     * Provides shared functionality for handling disabled uploads.
     *
     * This trait encapsulates common logic for checking upload status, logging
     * disabled upload messages, and finding attachments by ID. It eliminates code
     * duplication between image and file processors.
     */
    use DisabledUploadHandlingTrait;

    /**
     * Map of entry IDs to their deferred internal links for later processing.
     *
     * This array stores internal links that could not be resolved during initial
     * processing because the target entries had not been imported yet. The links are
     * processed after all entries are imported.
     *
     * @var array<string, array<int, DeferredLinkData>>
     */
    protected static $deferredInternalLinks = [];

    /**
     * Map of WordPress URLs to post IDs for internal link resolution.
     *
     * This map is populated during the import process and used to resolve internal
     * WordPress page links that may have different URL structures than the actual
     * post permalinks.
     *
     * @var array<string, string>
     */
    protected static $urlToPostIdMap = [];

    /**
     * Cache of successfully processed internal links.
     *
     * Maps WordPress URLs to their corresponding Statamic entry references to avoid
     * redundant processing of the same links.
     *
     * @var array<string, string> Format: ['https://path.to/page' => 'statamic://entry::abcd1234']
     */
    protected static $processedLinksCache = [];

    /**
     * Cached XML path from ProcessingContext.
     *
     * Stores the XML path to avoid repeated calls to ProcessingContext::getXmlPath()
     * during link processing operations.
     *
     * @var ?string
     */
    protected static $cachedXmlPath;

    /**
     * XML file path for this instance.
     *
     * Stores the XML path passed to process() method for use in link conversion.
     */
    protected ?string $xmlPath = null;

    /**
     * Cache of unresolved internal links for reporting.
     *
     * Stores internal links that could not be resolved during processing for later
     * reporting at the end of post type imports.
     *
     * Uses associative array format for automatic deduplication and O(1) lookup time.
     * URLs are stored as keys with boolean values for efficient storage and retrieval.
     *
     * @var array<string, bool> Format: ['url' => true]
     */
    protected static $unresolvedLinksCache = [];

    /**
     * Processes a single URL string through LinkProcessor.
     *
     * Wraps the URL in an HTML anchor tag, processes it through LinkProcessor, and
     * extracts the processed URL. This is useful for processing URLs in string fields
     * (like button_url) that aren't part of HTML content.
     *
     * @param  string  $url  The URL to process
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?string  $statamicEntryId  The Statamic entry ID being processed (for deferred processing)
     * @param  ?string  $statamicEntryCollectionHandle  The Statamic entry collection handle (for deferred processing)
     * @return string The processed URL (may be converted to Statamic entry reference or file URL)
     */
    public static function processUrlString(
        string $url,
        string $statamicSiteHandle,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $statamicEntryId = null,
        ?string $statamicEntryCollectionHandle = null
    ): string {
        // Check if URL is empty
        if ($url === '') {
            // Return empty string if URL is empty
            return $url;
        }

        // Create LinkProcessor instance
        $linkProcessor = new self;

        // Wrap URL in HTML anchor tag for processing
        $html = "<a href=\"{$url}\">link</a>";

        // Get XML path from ProcessingContext for link resolution
        $xmlPath = ProcessingContext::getXmlPath();

        // Process the HTML through LinkProcessor
        $processedHtml = $linkProcessor->process(
            $html,
            $statamicSiteHandle,
            $assetImporter,
            $output,
            $statamicEntryId,
            $statamicEntryCollectionHandle,
            $xmlPath
        );

        // Load the processed HTML to extract the updated URL
        $doc = TextProcessor::loadHtml($processedHtml);

        // Get the anchor tag from the processed HTML
        $link = $doc->getElementsByTagName('a')->item(0);

        // Extract the processed URL or fall back to original if processing failed
        $processedUrl = $link !== null ? $link->getAttribute('href') : $url;

        // Return the processed URL
        return $processedUrl;
    }

    /**
     * Determines if a URL points to a file that should be imported as an asset.
     *
     * Uses the same regex pattern as the process() method to identify URLs with file
     * extensions that indicate downloadable assets rather than web pages.
     *
     * @param  string  $wordPressUrl  The URL to check
     * @return bool True if the URL appears to be a file asset, false otherwise
     */
    public static function isFileUrl(
        string $wordPressUrl
    ): bool {
        // Use regex pattern to match URLs with file extensions (an empty URL
        // matches nothing)
        return (bool) \preg_match(
            '/^(?:https?|ftp):\/\/[^\s\/$.?#][^\s\/]*\.[a-zA-Z0-9]{2,5}(?:\/[-._a-zA-Z0-9\/]*\.(?!aspx|asp|cfm|htm|html)[-\.a-zA-z0-9]{2,25})$/',
            $wordPressUrl
        );
    }

    /**
     * Processes HTML to import linked files and update <a> tag href attributes.
     *
     * Uses TextProcessor to load the input HTML into a DOMDocument and extract the
     * processed HTML. Identifies <a> tags with supported file extensions, imports the
     * files using AssetImporter, and updates href attributes to Statamic URLs. Also
     * processes internal WordPress page links by converting them to Statamic entry
     * URLs. Returns the processed HTML for further parsing (e.g., by Tiptap).
     *
     * @param  string  $html  The input HTML content containing potential <a> tags
     * @param  string  $statamicSiteHandle  The Statamic site handle for asset subfolder naming
     *                                      (e.g., 'student_information_system')
     * @param  AssetImporter  $assetImporter  Instance for importing files into Statamic
     * @param  OutputInterface  $output  Console output for logging import progress or errors
     * @param  ?string  $statamicEntryId  The Statamic entry ID being processed (for deferred processing)
     * @param  ?string  $statamicEntryCollectionHandle  The Statamic collection handle of the source entry being processed
     * @param  ?string  $xmlPath  Optional XML file path for link resolution (falls back to ProcessingContext if
     *                            not provided)
     * @return string The processed HTML with updated href attributes
     */
    public function process(
        string $html,
        string $statamicSiteHandle,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $statamicEntryId = null,
        ?string $statamicEntryCollectionHandle = null,
        ?string $xmlPath = null
    ): string {
        // ---
        // Get XML path from parameter or ProcessingContext
        // ---

        // Use provided XML path, or fall back to ProcessingContext if not provided
        $effectiveXmlPath = $xmlPath ?? ProcessingContext::getXmlPath();

        // Store XML path for use in link conversion
        $this->xmlPath = $effectiveXmlPath;

        // ---
        // Cache XML path if available
        // ---

        // Check if XML path is available before caching
        if ($effectiveXmlPath !== null) {
            // Cache the XML path for future use in link conversion
            self::$cachedXmlPath = $effectiveXmlPath;
        }

        // Check if HTML content is empty or only whitespace
        if (\trim($html) === '') {
            // Return early if no content to process
            return $html;
        }

        // Load HTML using TextProcessor
        $doc = TextProcessor::loadHtml($html);

        // Get base blog URL from AssetImporter for internal link detection
        $wordPressBaseBlogUrl = $assetImporter->getBaseBlogUrl();

        // Process <a> tags with file extensions and internal page links
        $links = $doc->getElementsByTagName('a');

        // Loop through each link element to process href attributes
        foreach ($links as $link) {
            // Get the href attribute value from the link element
            $href = $link->getAttribute('href');

            // Check if the link points to a supported file type
            if (self::isFileUrl($href)) {
                // Check if file uploads are disabled
                if (
                    self::checkUploadsDisabled(
                        $assetImporter,
                        $output,
                        'linked file: '.basename($href),
                        'file'
                    )
                ) {
                    // Keep the original URL when uploads are disabled
                    continue;
                }

                // Import the file and get the new path
                $newPath = $assetImporter->importFile(
                    $href,
                    $statamicSiteHandle,
                    $output,
                    'files',
                    SameSitePolicy::LINK_DIFFERENT_SITE
                );

                // Check if the file was successfully imported
                if ($newPath !== null && $newPath !== '') {
                    // Generate the new Statamic URL for the imported file
                    $newUrl = self::getAppUrl().'/files/'.$newPath;

                    // Update the link's href attribute with the new URL
                    $link->setAttribute(
                        'href',
                        $newUrl
                    );
                }
            } elseif (
                $wordPressBaseBlogUrl !== null && $wordPressBaseBlogUrl !== ''
                && $this->isKbLink(
                    $href,
                    $wordPressBaseBlogUrl
                )
            ) { // Check if the link is a kbtopic or kb link
                // Convert the kbtopic or kb link to a Statamic entry URL
                $newUrl = $this->convertKbLink(
                    $href,
                    $wordPressBaseBlogUrl,
                    $output
                );

                // Check if a new URL was generated for the link
                if ($newUrl !== null && $newUrl !== '') {
                    // Update the link's href attribute with the Statamic URL
                    $link->setAttribute(
                        'href',
                        $newUrl
                    );
                }
            } elseif (
                $wordPressBaseBlogUrl !== null && $wordPressBaseBlogUrl !== ''
                && $this->isInternalPageLink(
                    $href,
                    $wordPressBaseBlogUrl
                )
            ) { // Check if the link is an internal WordPress page link
                // Convert the internal WordPress link to a Statamic entry URL
                $newUrl = $this->convertInternalPageLink(
                    $href,
                    $statamicSiteHandle,
                    $assetImporter,
                    $output,
                    $statamicEntryId
                );

                // Check if a new URL was generated for the internal link
                if ($newUrl !== null && $newUrl !== '') {
                    // Update the link's href attribute with the Statamic URL
                    $link->setAttribute(
                        'href',
                        $newUrl
                    );
                }
            }
        }

        // Preserve the exact structure by saving HTML carefully
        $innerHtml = TextProcessor::getInnerHtml($doc);

        // Return the processed HTML with updated href attributes
        return $innerHtml;
    }

    /**
     * Determines if a URL is an internal WordPress page link.
     *
     * Checks if the URL belongs to the same WordPress site by comparing it against
     * the base blog URL. Excludes file links by checking if the extracted slug
     * contains a dot (indicating a file extension).
     *
     * @param  string  $wordPressUrl  The URL to check
     * @param  string  $wordPressBaseBlogUrl  The base WordPress blog URL
     * @return bool True if the URL is an internal page link, false otherwise
     */
    protected function isInternalPageLink(
        string $wordPressUrl,
        string $wordPressBaseBlogUrl
    ): bool {
        // Check if the URL is already a Statamic entry reference
        if (
            \str_starts_with(
                $wordPressUrl,
                'statamic://entry::'
            )
        ) {
            // Return false if the URL is already a Statamic entry reference
            return false;
        }

        // Check if the URL belongs to the same WordPress site
        if (
            ! AssetUtils::isSameSiteUrl(
                $wordPressUrl,
                $wordPressBaseBlogUrl
            )
        ) {
            // Return false if URL is not from the same site
            return false;
        }

        // Extract the slug to check if it's a file
        $wordPressPostName = $this->extractPageSlugFromUrl(
            $wordPressUrl,
            $wordPressBaseBlogUrl
        );

        // Check if slug extraction was successful (a slug is never empty: a path
        // left empty after trimming has none)
        if ($wordPressPostName === null) {
            // Return false if no slug could be extracted
            return false;
        }

        // Check if the slug contains a dot (indicating a file extension)
        // If it passes all checks, it's likely a page link
        // Return false if slug contains a dot (it's a file, not a page)
        return ! str_contains(
            $wordPressPostName,
            '.'
        );
    }

    /**
     * Determines if a URL is a kbtopic or kb link.
     *
     * Checks if the URL belongs to the same WordPress site and matches the
     * pattern /kbtopic/* or /kb/*.
     *
     * @param  string  $wordPressUrl  The URL to check
     * @param  string  $wordPressBaseBlogUrl  The base WordPress blog URL
     * @return bool True if the URL is a kbtopic or kb link, false otherwise
     */
    protected function isKbLink(
        string $wordPressUrl,
        string $wordPressBaseBlogUrl
    ): bool {
        // Check if the URL is already a Statamic entry reference
        if (
            \str_starts_with(
                $wordPressUrl,
                'statamic://entry::'
            )
        ) {
            // Return false if the URL is already a Statamic entry reference
            return false;
        }

        // Check if the URL belongs to the same WordPress site
        if (
            ! AssetUtils::isSameSiteUrl(
                $wordPressUrl,
                $wordPressBaseBlogUrl
            )
        ) {
            // Return false if URL is not from the same site
            return false;
        }

        // Extract the path from the URL
        $path = $this->extractPathFromUrl(
            $wordPressUrl,
            $wordPressBaseBlogUrl
        );

        // Check if path extraction was successful (an empty path matches neither
        // prefix below)
        if ($path === null) {
            // Return false if no path could be extracted
            return false;
        }

        // Get MinervaKB settings from processing context
        $minervaKbSettings = ProcessingContext::getMinervaKbSettings();
        if (! \is_array($minervaKbSettings)) {
            $minervaKbSettings = [];
        }

        // Get category slug from settings, default to 'kbtopic'
        $categorySlug = self::normalizeSettingSlug(
            $minervaKbSettings,
            'category_slug',
            'kbtopic'
        );

        // Get article slug from settings, default to 'kb'
        $articleSlug = self::normalizeSettingSlug(
            $minervaKbSettings,
            'article_slug',
            'kb'
        );

        // Build the category path prefix
        $categoryPathPrefix = "/{$categorySlug}/";

        // Build the article path prefix
        $articlePathPrefix = "/{$articleSlug}/";

        // Check if the path starts with the category path prefix or article path prefix
        return
            \str_starts_with(
                $path,
                $categoryPathPrefix
            )
            || \str_starts_with(
                $path,
                $articlePathPrefix
            );
    }

    /**
     * Converts a kbtopic or kb link to a Statamic entry URL.
     *
     * Extracts the slug from the URL path (/kbtopic/slug or /kb/slug) and
     * attempts to find the corresponding Statamic entry using the KbLinkRegistry.
     * If found, returns the Statamic entry reference URL.
     *
     * @param  string  $wordPressUrl  The kbtopic or kb URL
     * @param  string  $wordPressBaseBlogUrl  The base WordPress blog URL
     * @param  OutputInterface  $output  Console output for logging
     * @return ?string The Statamic entry reference URL if found, null otherwise
     */
    protected function convertKbLink(
        string $wordPressUrl,
        string $wordPressBaseBlogUrl,
        OutputInterface $output
    ): ?string {
        // Check if the URL is provided
        if ($wordPressUrl === '') {
            // Return null if the URL is not provided
            return null;
        }

        // Parse URL components to separate base URL from fragment and query
        $urlComponents = self::parseUrlComponents($wordPressUrl);

        // Extract the base URL for searching
        $baseUrl = $urlComponents['base'];

        // Extract the fragment identifier (e.g., #section)
        $fragment = $urlComponents['fragment'];

        // Extract the query string (e.g., ?param=value)
        $query = $urlComponents['query'];

        // Construct search URL without fragment for caching
        $searchUrl = $baseUrl.($query ?? '');

        // Check if we've already successfully processed this link
        if (isset(self::$processedLinksCache[$searchUrl])) {
            // Get the cached Statamic entry reference for this URL
            $cachedResult = self::$processedLinksCache[$searchUrl];

            // Inform user that we're using a cached result
            $output->writeln("  - <comment>Using cached result for KB link: {$wordPressUrl} -> {$cachedResult}</comment>");

            // Return the cached Statamic entry reference
            return $cachedResult;
        }

        // Extract the path from the URL
        $path = $this->extractPathFromUrl(
            $wordPressUrl,
            $wordPressBaseBlogUrl
        );

        // Check if path extraction was successful
        if ($path === null || $path === '') {
            // Return null if no path could be extracted
            return null;
        }

        // Initialize entry ID variable
        $entryId = null;

        // Get MinervaKB settings from processing context
        $minervaKbSettings = ProcessingContext::getMinervaKbSettings();
        if (! \is_array($minervaKbSettings)) {
            $minervaKbSettings = [];
        }

        // Get category slug from settings, default to 'kbtopic'
        $categorySlug = self::normalizeSettingSlug(
            $minervaKbSettings,
            'category_slug',
            'kbtopic'
        );

        // Get article slug from settings, default to 'kb'
        $articleSlug = self::normalizeSettingSlug(
            $minervaKbSettings,
            'article_slug',
            'kb'
        );

        // Build the category path prefix
        $categoryPathPrefix = "/{$categorySlug}/";

        // Build the article path prefix
        $articlePathPrefix = "/{$articleSlug}/";

        // Check if the path starts with the category path prefix
        if (
            \str_starts_with(
                $path,
                $categoryPathPrefix
            )
        ) {
            // Extract the slug from the path (remove category path prefix)
            $slug = \substr(
                $path,
                \strlen($categoryPathPrefix)
            );

            // Remove trailing slash if present
            $slug = \rtrim(
                $slug,
                '/'
            );

            // Look up the entry ID in the registry
            $entryId = KbLinkRegistry::getKbtopicEntryIdBySlug($slug);
        } elseif (
            \str_starts_with(
                $path,
                $articlePathPrefix
            )
        ) { // Check if the path starts with the article path prefix
            // Extract the slug from the path (remove article path prefix)
            $slug = \substr(
                $path,
                \strlen($articlePathPrefix)
            );

            // Remove trailing slash if present
            $slug = \rtrim(
                $slug,
                '/'
            );

            // Look up the entry ID in the registry
            $entryId = KbLinkRegistry::getKbPostEntryId($slug);
        }

        // Check if an entry ID was found
        if ($entryId === null || $entryId === '') {
            // Cache the unresolved link for later reporting. Only the cache's keys
            // are read (processUnresolvedLinksForPostType() counts and lists them),
            // so the value stored is never seen.
            // @pest-mutate-ignore: TrueToFalse
            self::$unresolvedLinksCache[$searchUrl] = true;

            // Return null if no entry ID was found
            return null;
        }

        // Generate the Statamic entry reference URL using shared helper method
        $statamicUrl = self::generateEntryReference(
            $entryId,
            $fragment,
            $query
        );

        // Store the successful conversion in the cache
        self::$processedLinksCache[$searchUrl] = $statamicUrl;

        // Inform user of successful link conversion
        $output->writeln("  - <info>Converted KB link: {$wordPressUrl} -> {$statamicUrl}</info>");

        // Return the converted Statamic entry reference
        return $statamicUrl;
    }

    /**
     * Extracts the path from a WordPress URL.
     *
     * Removes the base blog URL and returns the path portion of the URL with
     * leading slash.
     *
     * @param  string  $wordPressUrl  The WordPress URL
     * @param  string  $wordPressBaseBlogUrl  The base WordPress blog URL
     * @return ?string The extracted path with leading slash or null if extraction fails
     */
    protected function extractPathFromUrl(
        string $wordPressUrl,
        string $wordPressBaseBlogUrl
    ): ?string {
        // Extract the base path using shared helper method
        $path = $this->extractBasePathFromUrl(
            $wordPressUrl,
            $wordPressBaseBlogUrl
        );

        // Check if path extraction was successful
        if ($path === null) {
            // Return null if path could not be extracted
            return null;
        }

        // ---
        // Ensure path starts with /
        // ---

        // Check if path does not start with a leading slash
        if (
            ! \str_starts_with(
                $path,
                '/'
            )
        ) {
            // Prepend leading slash to path
            return "/{$path}";
        }

        // Return the extracted path with leading slash
        return $path;
    }

    /**
     * Extracts the base path from a WordPress URL (shared helper method).
     *
     * Removes the base blog URL, query parameters, and fragments, returning the
     * path without leading or trailing slashes. This is used by both
     * extractPathFromUrl and extractPageSlugFromUrl to avoid code duplication.
     *
     * @param  string  $wordPressUrl  The WordPress URL
     * @param  string  $wordPressBaseBlogUrl  The base WordPress blog URL
     * @return ?string The extracted path without leading/trailing slashes or null if extraction fails
     */
    private function extractBasePathFromUrl(
        string $wordPressUrl,
        string $wordPressBaseBlogUrl
    ): ?string {
        // Remove protocol from the URL for comparison
        $normalizedUrl = \preg_replace(
            '#^https?:#',
            '',
            $wordPressUrl
        );

        // Remove protocol from the base blog URL for comparison
        $normalizedBaseBlogUrl = \preg_replace(
            '#^https?:#',
            '',
            $wordPressBaseBlogUrl
        );

        // Preserve inputs if protocol stripping fails
        $url = $normalizedUrl ?? $wordPressUrl;
        $wordPressBaseBlogUrl = $normalizedBaseBlogUrl ?? $wordPressBaseBlogUrl;

        // Remove leading slashes from the URL
        $url = \ltrim(
            $url,
            '/'
        );

        // Remove leading slashes from the base blog URL
        $wordPressBaseBlogUrl = \ltrim(
            $wordPressBaseBlogUrl,
            '/'
        );

        // Check if URL starts with base blog URL
        if (
            ! \str_starts_with(
                $url,
                $wordPressBaseBlogUrl
            )
        ) {
            // Return null if URL doesn't belong to the same site
            return null;
        }

        // Remove the base blog URL from the beginning of the path
        $path = \substr(
            $url,
            \strlen($wordPressBaseBlogUrl)
        );

        // Remove query parameters from the path (strtok() gives false for an
        // empty path or one of nothing but question marks, both of which are no
        // path, and likewise for the fragment below)
        $path = (string) strtok(
            $path,
            '?'
        );

        // Remove URL fragments from the path, and return it
        return (string) strtok(
            $path,
            '#'
        );
    }

    /**
     * Converts an internal WordPress page link to a Statamic entry URL.
     *
     * Extracts the page slug from the WordPress URL and attempts to find the
     * corresponding Statamic entry. If found, returns the Statamic entry URL. If not
     * found, stores the link for deferred processing and returns null. When the link
     * points to an attachment post type, extracts the attachment URL and processes it
     * as a file URL instead.
     *
     * @param  string  $wordPressUrl  The internal WordPress page URL
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?string  $statamicEntryId  The Statamic entry ID being processed (for deferred processing)
     * @return ?string The Statamic entry URL if found, processed file URL if attachment, null otherwise
     */
    private function convertInternalPageLink(
        string $wordPressUrl,
        string $statamicSiteHandle,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $statamicEntryId = null
    ): ?string {
        // Check if the URL is provided
        if ($wordPressUrl === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext([]);

            // Log warning if the URL is not provided
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'LinkProcessor::convertInternalPageLink: URL not provided',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null if the URL is not provided
            return null;
        }

        // Parse URL components to separate base URL from fragment and query
        $urlComponents = self::parseUrlComponents($wordPressUrl);

        // Extract the base URL for searching and caching
        $baseUrl = $urlComponents['base'];

        // Extract the fragment identifier (e.g., #section)
        $fragment = $urlComponents['fragment'];

        // Extract the query string (e.g., ?param=value)
        $query = $urlComponents['query'];

        // ---
        // Construct search URL without fragment for XML lookup (XML <link> elements don't
        // include fragments)
        // ---
        $searchUrl = $baseUrl.($query ?? '');

        // ---
        // Always get the current page link from processing context (don't cache across
        // pages)
        // ---
        $currentPageLink = ProcessingContext::getWordPressPageLink();

        // ---
        // Parse current page link to get its base URL and query for comparison
        // ---

        // Parse current page link into components (base, query, fragment)
        $currentPageUrlComponents = $currentPageLink !== null ? self::parseUrlComponents($currentPageLink) : null;

        // ---
        // Construct search URL from current page (base + query, without fragment) for
        // comparison
        // ---
        $currentPageSearchUrl = $currentPageUrlComponents !== null
            ? ($currentPageUrlComponents['base'].($currentPageUrlComponents['query'] ?? ''))
            : null;

        // Check if this is a same-page link (search URL matches current page search URL)
        if (
            $currentPageSearchUrl !== null && $currentPageSearchUrl !== ''
            && $searchUrl === $currentPageSearchUrl
        ) {
            // The href is the fragment, if any (query string is not needed for
            // same-page links)
            $hrefValue = $fragment ?? '';

            // Inform user of same-page link conversion
            $output->writeln("  - <info>Converted same-page link: {$wordPressUrl} -> {$hrefValue}</info>");

            // Return the fragment for href (query string is dropped for same-page links)
            return $hrefValue;
        }

        // ---
        // Check if we've already successfully processed this link (use search URL for
        // caching)
        // ---
        if (isset(self::$processedLinksCache[$searchUrl])) {
            // Get the cached Statamic entry reference for this URL
            $cachedResult = self::$processedLinksCache[$searchUrl];

            // Inform user that we're using a cached result
            $output->writeln("  - <comment>Using cached result for internal link: {$wordPressUrl} -> {$cachedResult}</comment>");

            // Return the cached Statamic entry reference
            return $cachedResult;
        }

        // Use instance XML path if available, otherwise get from ProcessingContext
        $currentXmlPath = $this->xmlPath ?? ProcessingContext::getXmlPath();

        // Check if the current XML path is not null
        if ($currentXmlPath !== null) {
            // Cache the current XML path for future use
            self::$cachedXmlPath = $currentXmlPath;
        }

        // Use the cached path, which is now the current one when there is one
        $xmlPath = self::$cachedXmlPath;

        // Check if the XML path is available
        if ($xmlPath === null || $xmlPath === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['wordpress_url' => $wordPressUrl]
            );

            // Log warning if the XML path is not available
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'LinkProcessor::convertInternalPageLink: XML not provided',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null if the XML path is not available
            return null;
        }

        // Attempt to convert the internal WordPress link to a Statamic entry URL
        try {
            // ---
            // Search for the URL without fragment in the XML file (XML <link> elements don't
            // include fragments)
            // ---
            $postData = $this->findPostDataByUrl(
                $searchUrl,
                $xmlPath // No final URL yet, we'll try redirects if this fails
            );

            // Convert the post found for the URL
            if ($postData !== null) {
                return $this->convertFoundPost(
                    $postData,
                    $xmlPath,
                    $wordPressUrl,
                    $searchUrl,
                    $fragment,
                    $query,
                    $statamicSiteHandle,
                    $assetImporter,
                    $output,
                    $statamicEntryId,
                    'Converted internal link'
                );
            }

            // ---
            // Step 2: Else, do an HTTP request for the search URL, determine if there is a
            // redirect
            // ---
            $finalUrl = $this->followRedirects($searchUrl);

            // Check if the URL was redirected to a different location (a failed
            // fetch gives null, and a resolved one is never empty)
            if (
                ! in_array($finalUrl, [null, $searchUrl], true)
            ) {
                // Step 2.A: If redirected, get and store the final URL
                // Step 2.A.i: Repeat step 1, using both the original URL and final URL for search
                $postData = $this->findPostDataByUrl(
                    $searchUrl,
                    $xmlPath,
                    $finalUrl // Pass both original and final URL for comprehensive search
                );

                // Convert the post found for the redirected URL
                if ($postData !== null) {
                    return $this->convertFoundPost(
                        $postData,
                        $xmlPath,
                        $wordPressUrl,
                        $searchUrl,
                        $fragment,
                        $query,
                        $statamicSiteHandle,
                        $assetImporter,
                        $output,
                        $statamicEntryId,
                        'Converted internal link (via redirect)'
                    );
                }
            }

            // Else, return the HREF URL (unchanged)

            // Cache the unresolved link for later reporting (use search URL as key).
            // Only the cache's keys are read, so the value stored is never seen.
            // @pest-mutate-ignore: TrueToFalse
            self::$unresolvedLinksCache[$searchUrl] = true;

            // A scoped run may not have read the target's type, which fails this search exactly
            // as a broken link does; say so, so the operator can tell the two apart (wordpress-importer-archive#1179).
            MaterializationScope::reportUnresolvedLink($wordPressUrl);

            // Return the original URL with fragment/query preserved
            return $wordPressUrl;
        }

        // Handle any exceptions that occur during link conversion
        catch (\Exception $exception) {
            // Display error message to user
            $output->writeln("<error>Error converting internal link '{$wordPressUrl}': {$exception->getMessage()}</error>");

            // Log the error for debugging and monitoring
            Log::error(
                'LinkProcessor::convertInternalPageLink: Error converting internal link',
                [
                    'wordpress_url' => $wordPressUrl,
                    'error' => $exception->getMessage(),
                ]
            );

            // Return null to indicate the link could not be converted
            return null;
        }
    }

    /**
     * Converts the WordPress post found for an internal link to a Statamic entry
     * reference, or to the imported file for an attachment.
     *
     * A redirect rule is not linkable. An entry not imported yet is deferred when
     * the link's own entry is known.
     *
     * @param  PostData  $postData  The post found for the link
     * @param  string  $xmlPath  The export path, for an attachment's URL
     * @param  string  $wordPressUrl  The link as written
     * @param  string  $searchUrl  The link without its fragment, the cache key
     * @param  ?string  $fragment  The link's fragment
     * @param  ?string  $query  The link's query
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?string  $statamicEntryId  The entry the link is in, for deferral
     * @param  string  $conversion  How the conversion is described when reported
     * @return ?string The entry reference or file URL, or null
     */
    private function convertFoundPost(
        array $postData,
        string $xmlPath,
        string $wordPressUrl,
        string $searchUrl,
        ?string $fragment,
        ?string $query,
        string $statamicSiteHandle,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $statamicEntryId,
        string $conversion
    ): ?string {
        // Extract the WordPress post name, ID and type from the found data
        $wordPressPostName = $postData['post_name'];
        $wordPressPostId = $postData['post_id'];
        $wordPressPostType = $postData['post_type'];

        // Skip redirect_rule post types as they are imported into redirects global,
        // not a collection, and should not be linked to
        if ($wordPressPostType === 'redirect_rule') {
            return null;
        }

        // Handle an attachment by processing its URL as a file
        if ($wordPressPostType === 'attachment') {
            // Find the attachment item in XML to extract the attachment URL
            $attachmentItem = XpathHelper::findItemByPostId(
                $xmlPath,
                $wordPressPostId,
                'attachment'
            );

            // Extract the attachment URL from the XML item. Every route to the
            // post reads these parts with this query less the type, and the type
            // read was attachment, so the item is always found; the check only
            // narrows its type.
            // @pest-mutate-ignore: InstanceOfToTrue, EmptyStringToNotEmpty
            $attachmentUrl = $attachmentItem instanceof SimpleXMLElement
                ? XpathHelper::getStringValue(
                    $attachmentItem,
                    'wp:attachment_url'
                )
                : '';

            // Return null if the attachment URL could not be extracted
            if ($attachmentUrl === '') {
                return null;
            }

            // Process the attachment URL as a file URL
            return $this->processAttachmentUrlAsFile(
                $attachmentUrl,
                $statamicSiteHandle,
                $assetImporter,
                $output
            );
        }

        // Map the WordPress post type to Statamic target collection handle (every
        // mapped handle is non-empty)
        $statamicTargetCollectionHandle = self::mapPostTypeToCollection($wordPressPostType);

        // Initialize entry variable for lookup results
        $statamicTargetEntry = null;

        // Check if we have a target collection handle to search
        if ($statamicTargetCollectionHandle !== null) {
            // Try to find the entry by slug if post name is not empty. A fresh
            // slug lookup invalidates nothing (#127), so asking for one changes
            // nothing.
            if ($wordPressPostName !== '') {
                $statamicTargetEntry = EntryFinder::findEntryBySlug(
                    $statamicTargetCollectionHandle,
                    $wordPressPostName,
                    // @pest-mutate-ignore: TrueToFalse
                    true,
                    $statamicSiteHandle
                );
            }

            // Otherwise try to find the entry by WordPress post ID. The reference
            // is built from the entry's id, which a stale cached copy shares, so a
            // fresh lookup changes nothing here.
            $statamicTargetEntry ??= EntryFinder::findEntryByWordPressId(
                $statamicTargetCollectionHandle,
                $wordPressPostId,
                // @pest-mutate-ignore: TrueToFalse
                true,
                $statamicSiteHandle
            );
        }

        // Check if the Statamic entry was found
        if ($statamicTargetEntry instanceof Entry) {
            // Generate the Statamic entry reference
            $statamicUrl = self::generateEntryReference(
                $statamicTargetEntry,
                $fragment,
                $query
            );

            // Store the successful conversion in the cache (use search URL as key)
            self::$processedLinksCache[$searchUrl] = $statamicUrl;

            // Inform user of successful link conversion
            $output->writeln("  - <info>{$conversion}: {$wordPressUrl} -> {$statamicUrl}</info>");

            // Return the converted Statamic entry reference
            return $statamicUrl;
        }

        // If we have a current entry ID, defer the link for later processing
        if ($statamicEntryId !== null && $statamicEntryId !== '') {
            // Store the link for deferred processing after all entries are imported
            $this->storeDeferredLink(
                $statamicEntryId,
                ProcessingContext::getCollectionHandle() ?? 'pages',
                $wordPressUrl,
                $wordPressPostName,
                $wordPressPostId,
                $wordPressPostType,
                $statamicSiteHandle,
                $output,
                $fragment,
                $query
            );
        }

        // Return null to indicate the link could not be converted immediately
        return null;
    }

    /**
     * Extracts the page slug from a WordPress URL.
     *
     * Removes the base blog URL and any query parameters to extract the clean
     * page slug. For hierarchical URLs, tries both the full path and the final
     * slug segment since Statamic entries can be stored with either format.
     * Handles various URL formats including trailing slashes, query parameters,
     * and URL encoding.
     *
     * @param  string  $wordPressUrl  The WordPress page URL
     * @param  string  $wordPressBaseBlogUrl  The base WordPress blog URL
     * @return ?string The extracted page slug or null if extraction fails
     */
    private function extractPageSlugFromUrl(
        string $wordPressUrl,
        string $wordPressBaseBlogUrl
    ): ?string {
        // Extract the base path using shared helper method
        $path = $this->extractBasePathFromUrl(
            $wordPressUrl,
            $wordPressBaseBlogUrl
        );

        // Check if path extraction was successful
        if ($path === null) {
            // Return null if path could not be extracted
            return null;
        }

        // Remove trailing slashes (a leading one only adds an empty first segment)
        $path = \rtrim(
            $path,
            '/'
        );

        // Check if the path is empty after processing
        if ($path === '') {
            // Return null if no path remains
            return null;
        }

        // Split the path into segments for hierarchical URL handling
        $pathSegments = \explode(
            '/',
            $path
        );

        // Get the final segment as the slug
        $finalSlug = end($pathSegments);

        // Return the final slug for both simple and hierarchical URLs
        // The calling method will handle fallback approaches if needed
        return $finalSlug;
    }

    /**
     * Registers a WordPress URL to post ID mapping for internal link resolution.
     *
     * This method allows the import system to register mappings between WordPress
     * URLs and their corresponding post IDs. These mappings are used to resolve
     * internal links that point to different URL structures than the actual
     * post permalinks.
     *
     * @param  string  $wordPressUrl  The WordPress URL
     * @param  string  $wordPressPostId  The corresponding WordPress post ID
     */
    public static function registerUrlMapping(
        string $wordPressUrl,
        string $wordPressPostId
    ): void {
        // Store the URL to post ID mapping for internal link resolution
        self::$urlToPostIdMap[$wordPressUrl] = $wordPressPostId;
    }

    /**
     * Processes all deferred internal links after all entries of a post type have
     * been imported.
     *
     * This method iterates through all entries that have deferred internal links
     * and attempts to resolve them using the entry ID map. It updates the entry
     * content with the resolved Statamic URLs. Deferred links persist across
     * post type imports to allow cross-post-type link resolution.
     *
     * @param  string  $statamicSiteHandle  The site handle for context
     * @param  OutputInterface  $output  Console output for user feedback
     * @param  bool  $isFinalCall  Whether this is the final call after all content types are imported
     */
    public static function processDeferredInternalLinks(
        string $statamicSiteHandle,
        OutputInterface $output,
        bool $isFinalCall = false
    ): void {
        // Check if there are any deferred links to process
        if (self::$deferredInternalLinks === []) {
            // Exit early if no deferred links exist
            return;
        }

        // Inform user that deferred link processing is starting
        $output->writeln('Processing deferred internal links...');
        $output->writeln('  - <comment>Resolving remaining cross-post-type deferred links...</comment>');

        // Initialize counter for successfully processed entries
        $processedCount = 0;

        // Initialize counter for links that could not be resolved
        $unmappedCount = 0;

        // Initialize array to collect unresolved link details
        $unresolvedLinks = [];

        // Process each entry's deferred internal links
        foreach (self::$deferredInternalLinks as $statamicSourceEntryId => $deferredLinks) {
            // Get the source collection handle from the first deferred link
            // (all deferred links for an entry should have the same source collection)
            $statamicSourceCollectionHandle = $deferredLinks[0]['source_collection_handle'] ?? 'pages';

            // Find the entry that contains the deferred links with retry logic
            $statamicSourceEntry = self::findEntryWithRetry(
                fn (bool $forceFresh): ?Entry => EntryFinder::findEntryById(
                    $statamicSourceCollectionHandle,
                    $statamicSourceEntryId,
                    $forceFresh,
                    $statamicSiteHandle
                )
            );

            // Check if the entry was found
            if (! $statamicSourceEntry instanceof Entry) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'statamic_source_entry_id' => $statamicSourceEntryId,
                        'statamic_site_handle' => $statamicSiteHandle,
                    ]
                );

                // Log warning if entry cannot be found after retries
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        "LinkProcessor::processDeferredInternalLinks: Entry with ID '{$statamicSourceEntryId}' not found for site {$statamicSiteHandle} after lightweight retry attempts",
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Skip to next entry if current one cannot be found
                continue;
            }

            // Process deferred links for this entry using shared method
            $processingResult = self::processDeferredLinksForEntry(
                $statamicSourceEntry,
                $deferredLinks,
                $statamicSiteHandle,
                $output,
                $isFinalCall
            );

            // ---
            // Extract processing results
            // ---

            // Extract updated content field from processing results
            $content = $processingResult['content'];

            // Extract updated page_builder field from processing results
            $pageBuilder = $processingResult['page_builder'];

            // Extract content update flag from processing results
            $contentUpdated = $processingResult['content_updated'];

            // Extract page_builder update flag from processing results
            $pageBuilderUpdated = $processingResult['page_builder_updated'];

            // Extract processed link indices from processing results
            $processedLinkIndices = $processingResult['processed_link_indices'];

            // Extract unresolved links from processing results
            $entryUnresolvedLinks = $processingResult['unresolved_links'];

            // Add unresolved links to collection for reporting
            $unresolvedLinks = [
                ...$unresolvedLinks,
                ...$entryUnresolvedLinks,
            ];

            // Increment counter for unresolved links
            $unmappedCount += \count($entryUnresolvedLinks);

            // Check if the entry content or page_builder was modified during link processing
            if (
                $contentUpdated
                || $pageBuilderUpdated
            ) {
                // Update the entry with the modified content if it was modified
                if ($contentUpdated) {
                    $statamicSourceEntry->set(
                        'content',
                        $content
                    );
                }

                // Update the entry with the modified page_builder if it was modified
                if ($pageBuilderUpdated) {
                    $statamicSourceEntry->set(
                        'page_builder',
                        $pageBuilder
                    );
                }

                // Save the updated entry to the database
                $statamicSourceEntry->save();

                // Increment the counter for successfully processed entries
                $processedCount++;
            }

            // Remove successfully processed links from the deferred links array
            if ($processedLinkIndices !== []) {
                // Sort indices in descending order to avoid index shifting issues
                rsort($processedLinkIndices);

                // Remove successfully processed links from the deferred links array
                foreach ($processedLinkIndices as $index) {
                    unset(self::$deferredInternalLinks[$statamicSourceEntryId][$index]);
                }

                // Re-index the array to remove gaps
                self::$deferredInternalLinks[$statamicSourceEntryId] = \array_values(self::$deferredInternalLinks[$statamicSourceEntryId]);

                // Remove the entry entirely if no deferred links remain
                if (self::$deferredInternalLinks[$statamicSourceEntryId] === []) {
                    unset(self::$deferredInternalLinks[$statamicSourceEntryId]);
                }
            }

            // Unset loop variables
            unset(
                $statamicSourceEntryId,
                $deferredLinks,
                $statamicSourceCollectionHandle,
                $statamicSourceEntry,
                $processingResult,
                $content,
                $pageBuilder,
                $contentUpdated,
                $pageBuilderUpdated,
                $processedLinkIndices,
                $entryUnresolvedLinks
            );
        }

        // Display summary of processing results to user
        $output->writeln("Processed {$processedCount} entries with deferred internal links.");

        // Check if there were any unresolved links
        if ($unmappedCount > 0) {
            // Display warning about unresolved links to user
            $output->writeln("<comment>Remaining deferred internal links: {$unmappedCount}</comment>");

            // Group unresolved links by URL for better organization
            $groupedLinks = [];

            // Loop through all unresolved links to group them by URL
            foreach ($unresolvedLinks as $link) {
                if (! \is_string($link['wordpress_url'] ?? null)) {
                    continue;
                }

                // Extract the URL for grouping
                $url = $link['wordpress_url'];

                $groupedLinks[$url] ??= [
                    'wordpress_post_name' => $link['wordpress_post_name'],
                    'wordpress_post_id' => $link['wordpress_post_id'],
                    'statamic_entries' => [],
                ];

                // Add this entry to the group for this URL
                $groupedLinks[$url]['statamic_entries'][] = [
                    'statamic_entry_title' => \is_string($link['statamic_source_entry_title'] ?? null)
                        ? $link['statamic_source_entry_title']
                        : '',
                    'statamic_entry_id' => \is_string($link['statamic_source_entry_id'] ?? null)
                        ? $link['statamic_source_entry_id']
                        : '',
                ];
            }

            // Loop through grouped links to display them in organized format
            foreach ($groupedLinks as $url => $linkData) {
                $wordPressPostName = \is_string($linkData['wordpress_post_name'])
                    ? $linkData['wordpress_post_name']
                    : '';
                $wordPressPostId = \is_string($linkData['wordpress_post_id'])
                    ? $linkData['wordpress_post_id']
                    : '';

                // Display the WordPress URL, slug and ID
                $output->writeln("  - <comment>{$url} (WordPress slug: '{$wordPressPostName}'; WordPress ID: '{$wordPressPostId}')</comment>");

                // Count the number of Statamic entries that reference this URL
                $entryCount = \count($linkData['statamic_entries']);

                // Determine the correct singular or plural form based on entry count
                $entryText = $entryCount === 1 ? 'Entry' : 'Entries';

                // Display header for Statamic entries that use this link
                $output->writeln("      - <comment>Used on Statamic {$entryText}:</comment>");

                // Loop through all Statamic entries that reference this URL
                foreach ($linkData['statamic_entries'] as $entry) {
                    // Display each Statamic entry that uses this unresolved link
                    $output->writeln("          - <comment>'{$entry['statamic_entry_title']}' (Statamic ID: '{$entry['statamic_entry_id']}')</comment>");
                }
            }
        }
    }

    /**
     * Processes unresolved internal links at the end of post type imports.
     *
     * Outputs a summary of all internal links that could not be resolved during
     * processing, similar to how deferred links are reported.
     *
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  OutputInterface  $output  Console output for displaying results
     */
    public static function processUnresolvedLinksForPostType(
        string $statamicSiteHandle,
        OutputInterface $output
    ): void {
        // Check if there are any unresolved links to report
        if (self::$unresolvedLinksCache === []) {
            // Exit early if no unresolved links exist
            return;
        }

        // Inform user that unresolved link reporting is starting
        $output->writeln('Reporting unresolved internal links...');

        // Count total unresolved links
        $unresolvedCount = \count(self::$unresolvedLinksCache);

        // Display summary of unresolved links
        $output->writeln("<comment>Unresolved internal links: {$unresolvedCount}</comment>");

        // Sort URLs alphabetically for better readability (case-insensitive)
        $sortedUrls = \array_keys(self::$unresolvedLinksCache);
        sort(
            $sortedUrls,
            SORT_STRING | SORT_FLAG_CASE
        );

        // Display each unresolved link
        foreach ($sortedUrls as $url) {
            $output->writeln(
                "  - <comment>{$url}</comment>"
            );
        }

        // Clear the unresolved links cache to free memory
        self::$unresolvedLinksCache = [];
    }

    /**
     * Processes a resolved deferred link in content and page_builder fields.
     *
     * Replaces WordPress URLs with Statamic URLs in both content and
     * page_builder fields. Handles both array (Bard/replicator) and string
     * content types. Returns updated fields and flags indicating which fields
     * were modified.
     *
     * @param  mixed  $content  The content field value (array or string)
     * @param  mixed  $pageBuilder  The page_builder field value (array or string)
     * @param  string  $wordPressUrl  The original WordPress URL to replace
     * @param  string  $statamicUrl  The Statamic URL to replace with
     * @param  ?string  $fragment  The fragment identifier (optional)
     * @param  ?string  $query  The query string (optional)
     * @return array{
     *     content: mixed,
     *     page_builder: mixed,
     *     content_updated: bool,
     *     page_builder_updated: bool
     * }
     */
    public static function processResolvedLinkInFields(
        $content,
        $pageBuilder,
        string $wordPressUrl,
        string $statamicUrl,
        ?string $fragment = null,
        ?string $query = null
    ): array {
        // Initialize flags to track field updates
        $contentUpdated = false;
        $pageBuilderUpdated = false;

        // Check if the content is an array
        if (\is_array($content)) {
            // Recursively replace URLs in the content structure
            $content = self::replaceUrlInBardContent(
                $content,
                $wordPressUrl,
                $statamicUrl,
                $fragment,
                $query
            );

            // Mark that content has been updated
            $contentUpdated = true;
        } elseif (\is_string($content)) { // Otherwise, for string content
            // Use simple string replacement
            $content = \str_replace(
                $wordPressUrl,
                $statamicUrl,
                $content
            );

            // Mark that content has been updated
            $contentUpdated = true;
        }

        // Check if the page_builder is an array
        if (\is_array($pageBuilder)) {
            // Recursively replace URLs in the page_builder replicator structure
            $pageBuilder = self::replaceUrlInReplicatorContent(
                $pageBuilder,
                $wordPressUrl,
                $statamicUrl,
                $fragment,
                $query
            );

            // Mark that page_builder has been updated
            $pageBuilderUpdated = true;
        }

        // Return updated fields and flags
        return [
            'content' => $content,
            'page_builder' => $pageBuilder,
            'content_updated' => $contentUpdated,
            'page_builder_updated' => $pageBuilderUpdated,
        ];
    }

    /**
     * Recursively replaces URLs in Bard content structure.
     *
     * This method traverses the Bard content array structure and replaces
     * WordPress URLs with Statamic URLs in text content and link attributes.
     * Also handles fragment and query attributes for link marks.
     *
     * @param  array<int|string, mixed>  $content  The Bard content array to process
     * @param  string  $oldUrl  The WordPress URL to replace
     * @param  string  $newUrl  The Statamic URL to replace with
     * @param  ?string  $fragment  The fragment identifier (optional)
     * @param  ?string  $query  The query string (optional)
     * @return array<int|string, mixed> The modified Bard content array
     */
    public static function replaceUrlInBardContent(
        array $content,
        string $oldUrl,
        string $newUrl,
        ?string $fragment = null,
        ?string $query = null
    ): array {
        // Loop through the content array
        foreach ($content as $key => $value) {
            // Check if the value is an array
            if (\is_array($value)) {
                // Check if this is a link mark with href attribute
                if (
                    isset($value['type'])
                    && $value['type'] === 'link'
                    && \is_array($value['attrs'] ?? null)
                    && isset($value['attrs']['href'])
                ) {
                    $attrs = $value['attrs'];

                    // Check if this link matches the old URL
                    if ($attrs['href'] === $oldUrl) {
                        // Replace the href with the new URL
                        $attrs['href'] = $newUrl;

                        // Add fragment attribute if fragment identifier exists
                        if ($fragment !== null && $fragment !== '') {
                            // Set the fragment attribute in the link mark
                            $attrs['fragment'] = $fragment;
                        }

                        // Add query attribute if query string exists
                        if ($query !== null && $query !== '') {
                            // Set the query attribute in the link mark
                            $attrs['query'] = $query;
                        }

                        $value['attrs'] = $attrs;
                        $content[$key] = $value;
                    }
                } elseif ( // Check if this is a text node with marks array containing links
                    isset($value['type'])
                    && $value['type'] === 'text'
                    && isset($value['marks'])
                    && \is_array($value['marks'])
                ) {
                    // Process each mark in the marks array
                    foreach ($value['marks'] as $markIndex => $mark) {
                        if (! \is_array($mark)) {
                            continue;
                        }

                        // Check if this mark is a link mark with href attribute
                        if (
                            isset($mark['type'])
                            && $mark['type'] === 'link'
                            && \is_array($mark['attrs'] ?? null)
                            && isset($mark['attrs']['href'])
                        ) {
                            $markAttrs = $mark['attrs'];

                            if ($markAttrs['href'] !== $oldUrl) {
                                continue;
                            }

                            // Replace the href with the new URL
                            $markAttrs['href'] = $newUrl;

                            // Add fragment attribute if fragment identifier exists
                            if ($fragment !== null && $fragment !== '') {
                                // Set the fragment attribute in the link mark
                                $markAttrs['fragment'] = $fragment;
                            }

                            // Add query attribute if query string exists
                            if ($query !== null && $query !== '') {
                                // Set the query attribute in the link mark
                                $markAttrs['query'] = $query;
                            }

                            $mark['attrs'] = $markAttrs;
                            $value['marks'][$markIndex] = $mark;
                            $content[$key] = $value;
                        }
                    }
                } else { // Otherwise
                    // Recursively process nested arrays
                    $content[$key] = self::replaceUrlInBardContent(
                        $value,
                        $oldUrl,
                        $newUrl,
                        $fragment,
                        $query
                    );
                }
            } elseif (\is_string($value)) { // Check if the value is a string
                // Replace URLs in string values
                $content[$key] = \str_replace(
                    $oldUrl,
                    $newUrl,
                    $value
                );
            }
        }

        // Return the modified content array
        return $content;
    }

    /**
     * Recursively replaces URLs in replicator fieldtype content structure.
     *
     * This method traverses replicator fieldtype arrays (like page_builder) and
     * replaces WordPress URLs with Statamic URLs. Handles all field types within
     * replicator sets:
     *   - String fields (button_url, more_button_url, etc.) — simple string
     *     replacement
     *   - Bard fieldtype fields (body, block_description, etc.) — processed via
     *     replaceUrlInBardContent() to handle links in marks arrays
     *   - Nested replicator fieldtype fields (items arrays, etc.) — recursively
     *     processed
     *   - Other field types (text, textarea, etc.) — string replacement if applicable
     *
     * The method first checks if the current array is Bard content (single node or
     * array of nodes) and processes it accordingly. Otherwise, it processes the array
     * as a replicator structure, recursively handling nested fields.
     *
     * @param  array<int|string, mixed>  $content  The replicator content array to process (can be the entire page_builder array,
     *                                             a single replicator set, a field value, or nested structures)
     * @param  string  $oldUrl  The WordPress URL to replace
     * @param  string  $newUrl  The Statamic URL to replace with
     * @param  ?string  $fragment  The fragment identifier (optional)
     * @param  ?string  $query  The query string (optional)
     * @return array<int|string, mixed> The modified replicator content array
     */
    public static function replaceUrlInReplicatorContent(
        array $content,
        string $oldUrl,
        string $newUrl,
        ?string $fragment = null,
        ?string $query = null
    ): array {
        // ---
        // Check if this array is a single Bard node (has type/content or type/marks).
        // This handles individual Bard nodes that may be nested within replicator sets.
        // ---
        if (
            isset($content['type'])
            && (
                isset($content['content'])
                || isset($content['marks'])
            )
        ) {
            // Process as Bard content for proper link mark handling
            return self::replaceUrlInBardContent(
                $content,
                $oldUrl,
                $newUrl,
                $fragment,
                $query
            );
        }

        // ---
        // Check if this array is a Bard content array (array of Bard nodes). This handles
        // Bard fieldtype fields (like 'body' in uamsweb_block_content sets) which are
        // stored as arrays of nodes with numeric indices.
        // ---
        if (
            $content !== []
            && \array_keys($content) === \range(0, \count($content) - 1)
            && isset($content[0])
            && \is_array($content[0])
            && isset($content[0]['type'])
        ) {
            // Process as Bard content array for proper link mark handling
            return self::replaceUrlInBardContent(
                $content,
                $oldUrl,
                $newUrl,
                $fragment,
                $query
            );
        }

        // ---
        // Otherwise, process as replicator structure (replicator sets or field values).
        //
        // This handles:
        //   - The top-level page_builder array (array of replicator sets)
        //   - Individual replicator sets (associative arrays with id, type, and field
        //     values)
        //   - Nested replicator fields (like 'items' arrays within sets)
        //   - Field values that are arrays but not Bard content
        // ---
        foreach ($content as $key => $value) {
            // ---
            // Check if the value is an array (replicator set, nested replicator, or Bard
            // field)
            // ---
            if (\is_array($value)) {
                // Recursively process nested arrays (will detect and handle Bard content,
                // nested replicators, etc.)
                $content[$key] = self::replaceUrlInReplicatorContent(
                    $value,
                    $oldUrl,
                    $newUrl,
                    $fragment,
                    $query
                );
            } elseif (\is_string($value)) { // Check if the value is a string (string field like button_url, text field, etc.)
                // Replace URLs in string values (handles URL fields like button_url,
                // more_button_url, text fields, textarea fields, etc.)
                $content[$key] = \str_replace(
                    $oldUrl,
                    $newUrl,
                    $value
                );
            }
        }

        // Return the modified content array
        return $content;
    }

    /**
     * Processes deferred links for a single entry.
     *
     * This is the core deferred link processing logic shared between
     * processDeferredInternalLinks() and DeferredProcessingCoordinator.
     * Processes all deferred links for an entry, resolves them, updates
     * content and page_builder fields, and returns processing results.
     *
     * @param  Entry  $entry  The Statamic entry to process
     * @param  array<int, DeferredLinkData>  $deferredLinks  Array of deferred links
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  OutputInterface  $output  Console output for user feedback
     * @param  bool  $isFinalCall  Whether this is the final call after all content types are imported
     * @param  ?AssetImporter  $assetImporter  Optional asset importer for processing attachment URLs as files
     * @return array{
     *     content: mixed,
     *     page_builder: mixed,
     *     content_updated: bool,
     *     page_builder_updated: bool,
     *     processed_link_urls: list<string>,
     *     processed_link_indices: list<int>,
     *     unresolved_links: list<array<int|string, mixed>>
     * }
     */
    public static function processDeferredLinksForEntry(
        Entry $entry,
        array $deferredLinks,
        string $statamicSiteHandle,
        OutputInterface $output,
        bool $isFinalCall = false,
        ?AssetImporter $assetImporter = null
    ): array {
        // Get the current content of the entry
        $content = $entry->get('content');

        // Get the current page_builder of the entry
        $pageBuilder = $entry->get('page_builder');

        // Track whether content has been modified during processing
        $contentUpdated = false;

        // Track whether page_builder has been modified during processing
        $pageBuilderUpdated = false;

        // Track which links were successfully processed for removal (by URL)
        $processedLinkUrls = [];

        // Track which links were successfully processed for removal (by index)
        $processedLinkIndices = [];

        // Initialize array to collect unresolved link details
        $unresolvedLinks = [];

        // Process each deferred link for this entry
        foreach ($deferredLinks as $linkIndex => $linkData) {
            // Extract the original WordPress URL from the link data
            $wordPressUrl = $linkData['url'];

            // ---
            // Extract fragment and query from link data
            // ---

            // Extract fragment identifier from link data, defaulting to null if not present
            $fragment = $linkData['fragment'];

            // Extract query string from link data, defaulting to null if not present
            $query = $linkData['query'];

            // ---
            // Parse URL components to get base URL (deferred links may have full URL with
            // fragment)
            // ---

            // Parse WordPress URL into components (base, fragment, query)
            $urlComponents = self::parseUrlComponents($wordPressUrl);

            // Extract base URL from parsed components
            $baseUrl = $urlComponents['base'];

            // Extract fragment identifier from parsed URL components
            $fragmentFromUrl = $urlComponents['fragment'];

            // Extract query string from parsed URL components
            $queryFromUrl = $urlComponents['query'];

            // ---
            // Use fragment/query from linkData if available, otherwise from parsed URL
            // ---

            // Use fragment from parsed URL if not already set in link data
            $fragment ??= $fragmentFromUrl;

            // Use query from parsed URL if not already set in link data
            $query ??= $queryFromUrl;

            // ---
            // Construct search URL without fragment for caching (XML <link> elements don't
            // include fragments)
            // ---
            $searchUrl = $baseUrl.($query ?? '');

            // Extract the WordPress post name from the link data
            $wordPressPostName = $linkData['post_name'];

            // Extract the WordPress post ID from the link data
            $wordPressPostId = $linkData['post_id'];

            // Extract the WordPress post type from the link data
            $wordPressPostType = $linkData['post_type'];

            // Skip redirect_rule post types as they are imported into redirects global,
            // not a collection, and should not be linked to
            if ($wordPressPostType === 'redirect_rule') {
                // Continue to next link without attempting resolution
                // (redirect rules are intentionally skipped, not unresolved)
                continue;
            }

            // ---
            // Handle attachment post types by extracting attachment URL and processing as file
            // ---

            // Check if the post type is attachment
            if ($wordPressPostType === 'attachment') {
                // Get XML path from ProcessingContext
                $xmlPath = ProcessingContext::getXmlPath();

                // Check if XML path is available
                if ($xmlPath !== null && $xmlPath !== '') {
                    // Find the attachment item in XML to extract the attachment URL
                    $attachmentItem = XpathHelper::findItemByPostId(
                        $xmlPath,
                        $wordPressPostId,
                        'attachment'
                    );

                    // Check if attachment item was found
                    if ($attachmentItem instanceof SimpleXMLElement) {
                        // Extract the attachment URL from the XML item
                        $attachmentUrl = XpathHelper::getStringValue(
                            $attachmentItem,
                            'wp:attachment_url'
                        );

                        // Check if attachment URL was successfully extracted
                        if ($attachmentUrl !== '') {
                            // Initialize processed URL with attachment URL
                            $processedUrl = $attachmentUrl;

                            // Check if AssetImporter is available to process attachment as file
                            if ($assetImporter instanceof AssetImporter) {
                                // Process the attachment URL as a file URL
                                $processedUrl = self::processAttachmentUrlAsFileForDeferred(
                                    $attachmentUrl,
                                    $statamicSiteHandle,
                                    $assetImporter,
                                    $output
                                );

                                // Check if file processing failed and processed URL is empty
                                if ($processedUrl === null || $processedUrl === '') {
                                    // Use attachment URL as fallback when file processing fails
                                    $processedUrl = $attachmentUrl;
                                }
                            }

                            // Replace WordPress URL with processed URL in content and page_builder
                            $fieldUpdates = self::processResolvedLinkInFields(
                                $content,
                                $pageBuilder,
                                $wordPressUrl,
                                $processedUrl,
                                $fragment,
                                $query
                            );

                            // Update content field with processed values
                            $content = $fieldUpdates['content'];

                            // Update page_builder field with processed values
                            $pageBuilder = $fieldUpdates['page_builder'];

                            // ---
                            // Update flags if fields were modified
                            // ---

                            // Check if content field was modified during processing
                            if ($fieldUpdates['content_updated']) {
                                // Mark content field as updated
                                $contentUpdated = true;
                            }

                            // Check if page_builder field was modified during processing
                            if ($fieldUpdates['page_builder_updated']) {
                                // Mark page_builder field as updated
                                $pageBuilderUpdated = true;
                            }

                            // ---
                            // Track this link as successfully processed
                            // ---

                            // Add WordPress URL to processed links array for tracking
                            $processedLinkUrls[] = $wordPressUrl;

                            // Add link index to processed indices array for removal
                            $processedLinkIndices[] = $linkIndex;

                            // Inform user that attachment was processed
                            $output->writeln("  - <info>Processed attachment link: {$wordPressUrl} -> {$processedUrl}</info>");

                            // Continue to next link
                            continue;
                        }
                    }
                }

                // If attachment URL could not be extracted, log warning and skip
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'wordpress_url' => $wordPressUrl,
                        'wordpress_post_id' => $wordPressPostId,
                        'wordpress_post_type' => $wordPressPostType,
                    ]
                );

                // Log warning about failed attachment URL extraction
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'LinkProcessor::processDeferredLinksForEntry: Could not extract attachment URL',
                        $logData['page_url']
                    ),
                    $logData['context']
                );

                // Continue to next link without attempting entry resolution
                continue;
            }

            // Resolve the deferred link using shared logic
            $resolvedLink = self::resolveDeferredLink(
                $linkData,
                $statamicSiteHandle,
                $isFinalCall
            );

            // Check if the link was successfully resolved
            if ($resolvedLink !== null) {
                // ---
                // Extract resolved data
                // ---

                // Extract Statamic entry reference URL from resolved link
                $statamicUrl = $resolvedLink['statamic_url'];

                // Extract fragment identifier from resolved link
                $fragment = $resolvedLink['fragment'];

                // Extract query string from resolved link
                $query = $resolvedLink['query'];

                // Process resolved link in content and page_builder fields
                $fieldUpdates = self::processResolvedLinkInFields(
                    $content,
                    $pageBuilder,
                    $wordPressUrl,
                    $statamicUrl,
                    $fragment,
                    $query
                );

                // Update content field with processed values from field updates
                $content = $fieldUpdates['content'];

                // Update page_builder field with processed values from field updates
                $pageBuilder = $fieldUpdates['page_builder'];

                // ---
                // Update flags if fields were modified
                // ---

                // Check if content field was modified during processing
                if ($fieldUpdates['content_updated']) {
                    // Mark content as updated
                    $contentUpdated = true;
                }

                // Check if page_builder field was modified during processing
                if ($fieldUpdates['page_builder_updated']) {
                    // Mark page_builder as updated
                    $pageBuilderUpdated = true;
                }

                // Store the successful conversion in the cache (use search URL as key)
                self::$processedLinksCache[$searchUrl] = $statamicUrl;

                // Track this link URL as successfully processed
                $processedLinkUrls[] = $wordPressUrl;

                // Track this link index as successfully processed
                $processedLinkIndices[] = $linkIndex;

                // Inform user that the link was successfully resolved
                $output->writeln("  - <info>Resolved deferred link: {$wordPressUrl} -> {$statamicUrl}</info>");
            } else { // Otherwise
                // Get target collection handle for logging
                $statamicTargetCollectionHandle = $linkData['collection_handle'];

                // Check if collection handle is missing and WordPress post type is available
                if (
                    ($statamicTargetCollectionHandle === null || $statamicTargetCollectionHandle === '')
                    && $wordPressPostType !== ''
                ) {
                    // Map WordPress post type to Statamic collection handle
                    $statamicTargetCollectionHandle = self::mapPostTypeToCollection(
                        $wordPressPostType
                    );
                }

                // Check if collection handle is still missing after mapping attempt
                if ($statamicTargetCollectionHandle === null || $statamicTargetCollectionHandle === '') {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'wordpress_url' => $wordPressUrl,
                            'wordpress_post_name' => $wordPressPostName,
                            'wordpress_post_id' => $wordPressPostId,
                            'wordpress_post_type' => $wordPressPostType,
                        ]
                    );

                    // Log warning for missing collection handle
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            "LinkProcessor::processDeferredLinksForEntry: No collection handle found for post type '{$wordPressPostType}'",
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }

                // Collect unresolved link details for user feedback
                $unresolvedLinks[] = [
                    'statamic_source_entry_id' => $entry->id(),
                    'statamic_source_entry_title' => $entry->get('title') ?? 'Untitled',
                    'wordpress_url' => $wordPressUrl,
                    'wordpress_post_name' => $wordPressPostName,
                    'wordpress_post_id' => $wordPressPostId,
                    'statamic_target_collection_handle' => $statamicTargetCollectionHandle,
                ];

                // Check if this is the final call after all content types are imported
                if ($isFinalCall) {
                    // Enhance log context with entry information
                    $logData = LogContextHelper::enhanceWithEntryContext(
                        [
                            'statamic_source_entry_id' => $entry->id(),
                            'wordpress_url' => $wordPressUrl,
                            'wordpress_post_name' => $wordPressPostName,
                            'wordpress_post_id' => $wordPressPostId,
                            'statamic_target_collection_handle' => $statamicTargetCollectionHandle,
                            'statamic_site_handle' => $statamicSiteHandle,
                        ]
                    );

                    // Log unresolved link details
                    Log::warning(
                        LogContextHelper::formatMessageWithEntry(
                            'LinkProcessor::processDeferredLinksForEntry: Could not resolve deferred link after all content types imported',
                            $logData['page_url']
                        ),
                        $logData['context']
                    );
                }
            }
        }

        // Return processing results
        return [
            'content' => $content,
            'page_builder' => $pageBuilder,
            'content_updated' => $contentUpdated,
            'page_builder_updated' => $pageBuilderUpdated,
            'processed_link_urls' => $processedLinkUrls,
            'processed_link_indices' => $processedLinkIndices,
            'unresolved_links' => $unresolvedLinks,
        ];
    }

    /**
     * Resolves a single deferred internal link to a Statamic entry reference.
     *
     * This is the core link resolution logic shared between
     * processDeferredInternalLinks() and DeferredProcessingCoordinator.
     * Extracts link data, finds the target entry, and generates the Statamic
     * entry reference URL.
     *
     * @param  DeferredLinkData  $linkData  The deferred link data array
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  bool  $isFinalCall  Whether this is the final call after all content types are imported (for
     *                             logging purposes)
     * @return ResolvedDeferredLink|null Resolved link data, or null if not found
     */
    public static function resolveDeferredLink(
        array $linkData,
        string $statamicSiteHandle,
        bool $isFinalCall = false
    ): ?array {
        // Extract link data
        $wordPressUrl = $linkData['url'];
        $wordPressPostName = $linkData['post_name'];
        $wordPressPostId = $linkData['post_id'];
        $wordPressPostType = $linkData['post_type'];
        $statamicTargetCollectionHandle = $linkData['collection_handle'];
        $fragment = $linkData['fragment'];
        $query = $linkData['query'];

        // Skip redirect_rule post types as they are imported into redirects global,
        // not a collection, and should not be linked to
        if ($wordPressPostType === 'redirect_rule') {
            // Return null to skip this link (redirect rules are not linkable entries)
            return null;
        }

        // Use the stored target collection handle, or fall back to mapping from post type
        if (
            ($statamicTargetCollectionHandle === null || $statamicTargetCollectionHandle === '')
            && $wordPressPostType !== ''
        ) {
            // Map WordPress post type to Statamic collection handle
            $statamicTargetCollectionHandle = self::mapPostTypeToCollection(
                $wordPressPostType
            );
        }

        // Skip if we don't have a valid target collection handle
        if ($statamicTargetCollectionHandle === null || $statamicTargetCollectionHandle === '') {
            // Return null if no collection handle found
            return null;
        }

        // Try to find the entry by slug first with retry logic
        $targetEntry = self::findEntryWithRetry(
            fn (bool $forceFresh): ?Entry => EntryFinder::findEntryBySlug(
                $statamicTargetCollectionHandle,
                $wordPressPostName,
                $forceFresh,
                $statamicSiteHandle
            )
        );

        $targetEntry ??= self::findEntryWithRetry(
            fn (bool $forceFresh): ?Entry => EntryFinder::findEntryByWordPressId(
                $statamicTargetCollectionHandle,
                $wordPressPostId,
                $forceFresh,
                $statamicSiteHandle
            )
        );

        // Check if the target entry was found
        if (! $targetEntry instanceof Entry) {
            // ---
            // Only log warnings during final call (after all content types imported). During
            // per-post-type processing, it's expected that cross-post-type links won't be
            // resolvable yet.
            // ---

            // Check if this is the final call after all content types are imported
            if ($isFinalCall) {
                // Enhance log context with entry information
                $logData = LogContextHelper::enhanceWithEntryContext(
                    [
                        'wordpress_post_name' => $wordPressPostName,
                        'wordpress_post_id' => $wordPressPostId,
                        'wordpress_post_type' => $wordPressPostType,
                        'statamic_target_collection_handle' => $statamicTargetCollectionHandle,
                        'statamic_site_handle' => $statamicSiteHandle,
                        'wordpress_url' => $wordPressUrl,
                    ]
                );

                // Log detailed diagnostic information for unresolved links after all imports
                Log::warning(
                    LogContextHelper::formatMessageWithEntry(
                        'LinkProcessor::resolveDeferredLink: Entry not found by slug or WordPress ID after all content types imported',
                        $logData['page_url']
                    ),
                    $logData['context']
                );
            }

            // Return null if entry not found
            return null;
        }

        // Generate the Statamic entry reference
        $statamicUrl = self::generateEntryReference(
            $targetEntry,
            $fragment,
            $query
        );

        // Return resolved link data
        return [
            'statamic_url' => $statamicUrl,
            'target_entry' => $targetEntry,
            'wordpress_url' => $wordPressUrl,
            'fragment' => $fragment,
            'query' => $query,
        ];
    }

    /**
     * Generates a Statamic entry reference URL from an entry object or entry ID.
     *
     * Creates a Statamic entry reference in the format "statamic://entry::{entry_id}"
     * for use in internal links. This provides a direct reference to the entry
     * that doesn't depend on URL generation or routing configuration.
     *
     * @param  Entry|string  $entryOrId  The Statamic entry object or entry ID string
     * @param  ?string  $fragment  The fragment identifier (optional)
     * @param  ?string  $query  The query string (optional)
     * @return string The Statamic entry reference URL
     */
    public static function generateEntryReference(
        Entry|string $entryOrId,
        ?string $fragment = null,
        ?string $query = null
    ): string {
        // Extract entry ID from Entry object or use string directly
        $entryId = $entryOrId instanceof Entry
            ? self::normalizeEntryId($entryOrId->id())
            : $entryOrId;

        // Create the base Statamic entry reference
        $statamicUrl = "statamic://entry::{$entryId}";

        // ---
        // Add query string if present
        // ---

        // Check if query string is provided. The query precedes the fragment per
        // standard URL syntax (`?query#fragment`) and the shape Statamic 6 expects
        // for Bard entry links (statamic/cms#11468). Appending it first keeps the
        // `?` outside the fragment so consumers can re-parse the query.
        if ($query !== null && $query !== '') {
            // Append query string to the Statamic URL
            $statamicUrl .= $query;
        }

        // ---
        // Add fragment if present
        // ---

        // Check if fragment identifier is provided. The fragment goes last so the
        // `#` terminates the URL, leaving any preceding query intact.
        if ($fragment !== null && $fragment !== '') {
            // Append fragment identifier to the Statamic URL
            $statamicUrl .= $fragment;
        }

        // Return the complete Statamic entry reference URL
        return $statamicUrl;
    }

    /**
     * Maps WordPress post type to Statamic collection handle.
     *
     * Uses the same default mappings as ImportWordPress command to determine
     * the correct Statamic collection for a given WordPress post type.
     *
     * @param  string  $wordPressPostType  The WordPress post type
     * @return ?string The Statamic collection handle, or null if not found
     */
    public static function mapPostTypeToCollection(
        string $wordPressPostType
    ): ?string {
        // Get default mappings from ImportWordPress command
        $defaultMappings = ImportWordPress::getDefaultMappings();

        // Check if the post type exists in the mappings
        if (isset($defaultMappings[$wordPressPostType])) {
            // Get the mapping for the post type
            $mapping = $defaultMappings[$wordPressPostType];

            // Check if the post type is a collection
            if ($mapping['type'] === 'collection') {
                // Return the collection handle
                return $mapping['handle'];
            }
        }

        // Return null if no collection mapping found
        return null;
    }

    /**
     * Gets all deferred internal links.
     *
     * Returns the map of entry IDs to their deferred internal links.
     * Used for unified deferred processing.
     *
     * @return array<string, array<int, DeferredLinkData>> Deferred links map
     */
    public static function getDeferredInternalLinks(): array
    {
        // Return all deferred internal links
        return self::$deferredInternalLinks;
    }

    /**
     * Sets the deferred internal links array.
     *
     * Used by DeferredProcessingCoordinator to remove processed links from
     * the deferred links array after processing them during post type imports.
     *
     * @param  array<string, array<int, DeferredLinkData>>  $deferredLinks  Updated deferred links
     */
    public static function setDeferredInternalLinks(
        array $deferredLinks
    ): void {
        // Update the deferred internal links array with the provided links
        self::$deferredInternalLinks = $deferredLinks;
    }

    /**
     * Clears all deferred processing data.
     *
     * This method should be called at the beginning of each import process
     * to ensure clean state between imports.
     */
    public static function clearDeferredData(): void
    {
        // Clear the deferred internal links array
        self::$deferredInternalLinks = [];

        // Clear the URL to post ID mapping
        self::$urlToPostIdMap = [];

        // Clear the processed links cache
        self::$processedLinksCache = [];

        // Clear the unresolved links cache
        self::$unresolvedLinksCache = [];
    }

    /**
     * Follows HTTP redirects to find the ultimate URL.
     *
     * This method makes an HTTP HEAD request to the given URL and follows
     * redirects to find the final destination URL. This is useful for resolving
     * internal links that may redirect to different URL structures.
     *
     * The URL comes from the WXR being imported, so the chain is walked by
     * {@see GuardedHttpFetcher}, which refuses any hop targeting a non-public
     * address (wordpress-importer-archive#534).
     *
     * @param  string  $wordPressUrl  The URL to follow redirects for
     * @return ?string The ultimate URL after following redirects, or null if failed
     */
    private function followRedirects(
        string $wordPressUrl
    ): ?string {
        // Resolve the redirect chain, re-validating every hop against the
        // egress guard before its request is sent
        return GuardedHttpFetcher::resolveFinalUrl($wordPressUrl);
    }

    /**
     * Stores a deferred link for later processing.
     *
     * @param  string  $statamicSourceEntryId  The Statamic entry ID containing the link
     * @param  string  $statamicSourceEntryCollection  The Statamic collection handle of the entry containing the link
     * @param  string  $wordPressUrl  The original WordPress URL
     * @param  string  $wordPressPostName  The WordPress post name
     * @param  string  $wordPressPostId  The WordPress post ID
     * @param  string  $wordPressPostType  The WordPress post type
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  OutputInterface  $output  Console output for user feedback
     * @param  ?string  $fragment  The fragment identifier (optional)
     * @param  ?string  $query  The query string (optional)
     */
    private function storeDeferredLink(
        string $statamicSourceEntryId,
        string $statamicSourceEntryCollection,
        string $wordPressUrl,
        string $wordPressPostName,
        string $wordPressPostId,
        string $wordPressPostType,
        string $statamicSiteHandle,
        OutputInterface $output,
        ?string $fragment = null,
        ?string $query = null
    ): void {
        self::$deferredInternalLinks[$statamicSourceEntryId] ??= [];

        // Map the WordPress post type to Statamic target collection handle
        $statamicTargetCollectionHandle = self::mapPostTypeToCollection($wordPressPostType);

        // Store the link data for later processing after all entries are imported
        self::$deferredInternalLinks[$statamicSourceEntryId][] = [
            'url' => $wordPressUrl,
            'post_name' => $wordPressPostName,
            'post_id' => $wordPressPostId,
            'post_type' => $wordPressPostType,
            'collection_handle' => $statamicTargetCollectionHandle,
            'source_collection_handle' => $statamicSourceEntryCollection,
            'site' => $statamicSiteHandle,
            'fragment' => $fragment,
            'query' => $query,
        ];

        // Inform user that the link will be processed later
        $output->writeln(
            "  - <comment>Internal link processing deferred for {$wordPressUrl} (WordPress slug: {$wordPressPostName}; WordPress ID: {$wordPressPostId}, WordPress post type: {$wordPressPostType})</comment>"
        );
    }

    /**
     * Finds WordPress post data for a given URL by searching the XML export.
     *
     * This method searches through the WordPress XML export to find the post
     * that has the given URL as its permalink. It returns both the post ID
     * and post name if found. Uses cross-platform PHP file reading instead
     * of shell commands.
     *
     * Items are partitioned across the parts of a split export, so this expands
     * the given path to its full part-set and searches each part in turn — loading
     * one part at a time — for an exact link match, then a URL-structure match. A
     * single-file export resolves to a one-part set, preserving prior behavior.
     *
     * @param  string  $wordPressUrl  The URL to search for
     * @param  string  $xmlPath  The path to any one export part (or a single-file export)
     * @param  ?string  $finalUrl  The final URL after redirects (optional)
     * @return PostData|null Post data if found, or null if not found
     */
    private function findPostDataByUrl(
        string $wordPressUrl,
        string $xmlPath,
        ?string $finalUrl = null
    ): ?array {
        $searchUrl = ($finalUrl !== null && $finalUrl !== '') ? $finalUrl : $wordPressUrl;

        // Get the cached post ID for this URL
        $wordPressPostId = self::$urlToPostIdMap[$searchUrl] ?? null;

        // Check if we already have this URL mapped
        if ($wordPressPostId !== null && $wordPressPostId !== '') {
            // Find and return the post name for the cached post ID
            return $this->findPostNameById($wordPressPostId, $xmlPath);
        }

        // Expand any single part path to the whole ordered split-export part-set
        $parts = SplitExportPartLocator::locate($xmlPath)->parts;

        // Build a safe XPath string literal for the URL so a value containing a
        // quote cannot break out of the query (the entity-escaping is preserved
        // for prior behavior; the literal builder neutralizes any apostrophe)
        $linkLiteral = XpathHelper::xpathStringLiteral(
            htmlspecialchars(
                $searchUrl,
                ENT_XML1,
                'UTF-8'
            )
        );

        // Hold the first URL-structure (non-published) match until every part has
        // been checked for a definitive exact-link match. An exact <link> match
        // anywhere outranks a structure match, matching single-file precedence.
        $structureMatch = null;

        // Search each part: exact-link matches are definitive, structure is fallback
        foreach ($parts as $part) {
            // Skip a part that is no longer present on disk. The locator lists
            // only parts that exist, so a missing one is either the lone path of a
            // single-file export or a file removed mid-run; with nothing after the
            // lone path, stopping and skipping cannot be told apart in a test.
            // @pest-mutate-ignore: ContinueToBreak
            if (! \file_exists($part)) {
                continue;
            }

            // Use SimpleXML for efficient XML parsing of this part
            $xml = simplexml_load_file(WxrSanitizedStream::wrap($part));

            // Skip a part that could not be parsed
            if ($xml === false) {
                continue;
            }

            // Register WordPress XML namespaces for XPath queries
            XpathHelper::registerWordPressNamespaces($xml);

            // Use XPath to find items with exact URL match in their link element
            $items = $xml->xpath("//item[link[text()={$linkLiteral}]]");

            // An exact link match is definitive (the URL is unique to a single part)
            if (\is_array($items) && isset($items[0])) {
                // Extract and return this item's post data (or null when incomplete)
                return $this->extractPostDataFromLinkMatch(
                    $items[0],
                    $searchUrl
                );
            }

            $structureMatch ??= $this->findPostDataByUrlStructure(
                $searchUrl,
                $xml
            );

            // Free this part before loading the next one to bound peak memory
            unset(
                $xml,
                $items
            );
        }

        // No part held an exact link match — fall back to the first structure match
        if ($structureMatch !== null) {
            // Cache the result
            self::$urlToPostIdMap[$searchUrl] = $structureMatch['post_id'];

            // Return the found post data
            return $structureMatch;
        }

        // Try to extract page-id from HTML body class as a final fallback
        $extractedPostId = $this->extractPageIdFromHtml($searchUrl);

        // Check if a post ID was extracted from the HTML (its pattern needs a digit)
        if ($extractedPostId !== null) {
            // Find and return the post data using the extracted post ID (across parts)
            return $this->findPostNameById(
                $extractedPostId,
                $xmlPath
            );
        }

        // Return null if no matching items found and no post ID could be extracted
        return null;
    }

    /**
     * Extract post data from an item matched by its exact link URL.
     *
     * The link URL is unique to a single part, so a match here is definitive:
     * the item's post data is returned, or null when any required field is absent.
     *
     * @param  SimpleXMLElement  $item  The item whose <link> matched the search URL
     * @param  string  $searchUrl  The URL used to match, keyed into the resolution cache
     * @return PostData|null Post data when all fields are present, otherwise null
     */
    private function extractPostDataFromLinkMatch(
        SimpleXMLElement $item,
        string $searchUrl
    ): ?array {
        // Extract post ID nodes from the matching item
        $postIdNodes = $item->xpath('wp:post_id');

        // Extract post name nodes from the matching item
        $postNameNodes = $item->xpath('wp:post_name');

        // Extract post type nodes from the matching item
        $postTypeNodes = $item->xpath('wp:post_type');

        // Convert post ID node to string, defaulting to empty string if not found
        $wordPressPostId = \is_array($postIdNodes) && isset($postIdNodes[0])
            ? (string) $postIdNodes[0]
            : '';

        // Convert post name node to string, defaulting to empty string if not found
        $wordPressPostName = \is_array($postNameNodes) && isset($postNameNodes[0])
            ? (string) $postNameNodes[0]
            : '';

        // Convert post type node to string, defaulting to empty string if not found
        $wordPressPostType = \is_array($postTypeNodes) && isset($postTypeNodes[0])
            ? (string) $postTypeNodes[0]
            : '';

        // Check if post ID, post name, and post type were successfully extracted
        if (
            $wordPressPostId === ''
            || $wordPressPostName === ''
            || $wordPressPostType === ''
        ) {
            // Return null if any required field is missing
            return null;
        }

        // Cache the result
        self::$urlToPostIdMap[$searchUrl] = $wordPressPostId;

        // Return the found post data
        return [
            'post_id' => $wordPressPostId,
            'post_name' => $wordPressPostName,
            'post_type' => $wordPressPostType,
        ];
    }

    /**
     * Finds WordPress post data by analyzing URL structure when link element is not
     * found.
     *
     * This method attempts to find post items by matching URL path segments to post
     * names and validating parent-child relationships. It's used as a fallback when
     * posts are not published (and thus don't have matching link elements in the XML).
     *
     * @param  string  $wordPressUrl  The WordPress URL to analyze
     * @param  SimpleXMLElement  $xml  The parsed XML document
     * @return PostData|null Post data if found, or null if not found
     */
    private function findPostDataByUrlStructure(
        string $wordPressUrl,
        SimpleXMLElement $xml
    ): ?array {
        // Get the base blog URL from the XML channel
        $baseBlogUrl = XpathHelper::getStringValue(
            $xml->channel,
            'wp:base_blog_url'
        );

        // Check if base blog URL is available
        if ($baseBlogUrl === '') {
            // Return null if base blog URL is not available
            return null;
        }

        // Extract path segments from the URL
        $pathSegments = $this->extractPathSegmentsFromUrl(
            $wordPressUrl,
            $baseBlogUrl
        );

        // Find matching post by validating segments and parent relationships
        // (no segments match nothing)
        return $this->findPostByPathSegments(
            $pathSegments,
            $xml
        );
    }

    /**
     * Extracts path segments from a WordPress URL.
     *
     * Removes the base blog URL, query parameters, fragment identifiers, and
     * trailing slashes to extract the clean path segments.
     *
     * @param  string  $wordPressUrl  The WordPress URL
     * @param  string  $baseBlogUrl  The base blog URL from XML
     * @return list<string> Array of path segments (e.g., ['baz', 'qux'])
     */
    private function extractPathSegmentsFromUrl(
        string $wordPressUrl,
        string $baseBlogUrl
    ): array {
        // Remove protocol from both URLs for comparison
        $normalizedUrl = \preg_replace(
            '#^https?:#',
            '',
            $wordPressUrl
        );

        $normalizedBaseBlogUrl = \preg_replace(
            '#^https?:#',
            '',
            $baseBlogUrl
        );

        // Preserve inputs if protocol stripping fails
        $url = $normalizedUrl ?? $wordPressUrl;
        $baseBlogUrl = $normalizedBaseBlogUrl ?? $baseBlogUrl;

        // Remove leading slashes
        $url = \ltrim(
            $url,
            '/'
        );

        $baseBlogUrl = \ltrim(
            $baseBlogUrl,
            '/'
        );

        // Check if URL starts with base blog URL
        if (
            ! \str_starts_with(
                $url,
                $baseBlogUrl
            )
        ) {
            // Return empty array if URL doesn't belong to the same site
            return [];
        }

        // Remove the base blog URL from the beginning of the path
        $path = \substr(
            $url,
            \strlen($baseBlogUrl)
        );

        // Remove leading slashes
        $path = \ltrim(
            $path,
            '/'
        );

        // Remove query parameters (strtok() gives false for an empty path or
        // one of nothing but question marks, both of which are no path, and
        // likewise for the fragment below)
        $path = (string) strtok(
            $path,
            '?'
        );

        // Remove fragment identifiers
        $path = (string) strtok(
            $path,
            '#'
        );

        // Split the path into segments
        $segments = \explode(
            '/',
            $path
        );

        // Filter out empty segments, which a trailing or doubled slash leaves
        $segments = \array_filter(
            $segments,
            fn ($segment): bool => $segment !== ''
        );

        // Re-index array to remove gaps
        return \array_values($segments);
    }

    /**
     * Finds a WordPress post by validating path segments against post names and
     * parents.
     *
     * Works backwards through path segments, starting with the last (most specific)
     * segment. For each segment, finds posts with matching post_name, filters out
     * published posts, validates parent-child relationships, and continues until
     * a unique match is found or all segments are validated.
     *
     * For posts with post_type='post', uses date-based or category-based validation
     * instead of parent validation when the URL matches those patterns.
     *
     * @param  list<string>  $pathSegments  URL path segments (e.g., ['baz', 'qux'])
     * @param  SimpleXMLElement  $xml  The parsed XML document
     * @return PostData|null Post data if found, or null if not found
     */
    private function findPostByPathSegments(
        array $pathSegments,
        SimpleXMLElement $xml
    ): ?array {
        // Get the number of segments
        $segmentCount = \count($pathSegments);

        // Check if there are no segments
        if ($segmentCount === 0) {
            // Return null if no segments to process
            return null;
        }

        // ---
        // Start with the last segment (most specific)
        // ---

        // Calculate the index of the last segment in the path
        $lastSegmentIndex = $segmentCount - 1;

        // Get the last segment value to use for post name matching
        $currentSegment = $pathSegments[$lastSegmentIndex];

        // Find all posts with matching post_name for the last segment
        $escapedPostName = htmlspecialchars(
            $currentSegment,
            ENT_XML1,
            'UTF-8'
        );

        // Search XML for all items with matching post_name value
        $candidateItems = $xml->xpath(
            "//item[wp:post_name[text()='{$escapedPostName}']]"
        );

        // Check if any candidates were found
        if (! \is_array($candidateItems) || $candidateItems === []) {
            // Return null if no posts found for the last segment
            return null;
        }

        // Filter out published posts (they would have matched via link element)
        $nonPublishedItems = \array_filter(
            $candidateItems,
            function (SimpleXMLElement $item): bool {
                // Extract the post status from the XML item
                $status = XpathHelper::getStringValue(
                    $item,
                    'wp:status'
                );

                // Return true if post status is not published
                return $status !== 'publish';
            }
        );

        // ---
        // Check if any items have post_type='post' for special validation
        // ---

        // Filter items to check if any are post type
        $postTypeItems = \array_filter(
            $nonPublishedItems,
            function (SimpleXMLElement $item): bool {
                // Extract the post type from the XML item
                $postType = XpathHelper::getStringValue(
                    $item,
                    'wp:post_type'
                );

                // Return true if post type is 'post'
                return $postType === 'post';
            }
        );

        // Check if we have post type items (the date and category patterns both need
        // more than one segment, so a single one matches neither)
        if ($postTypeItems !== []) {
            // Extract the remaining path segments (excluding base blog URL)
            $remainingPath = \implode(
                '/',
                $pathSegments
            );

            // Check if URL matches date pattern: \d{4}/\d{2}/\d{2}/[^/]+
            if (
                \preg_match(
                    '/^(\d{4})\/(\d{2})\/(\d{2})\/[^\/]+$/',
                    $remainingPath,
                    $dateMatches
                ) === 1
            ) {
                // Build the URL's date from its year, month and day
                $urlDate = "{$dateMatches[1]}-{$dateMatches[2]}-{$dateMatches[3]}";

                // Validate items by matching post date
                $validatedItems = [];

                // Loop through each post type item
                foreach ($postTypeItems as $item) {
                    // Extract post date (prefer wp:post_date, fallback to pubDate)
                    $postDate = XpathHelper::getStringValue(
                        $item,
                        'wp:post_date'
                    );

                    // Fallback to pubDate if wp:post_date is empty
                    if ($postDate === '') {
                        $postDate = XpathHelper::getStringValue(
                            $item,
                            'pubDate'
                        );
                    }

                    // Check if post date is available
                    if ($postDate === '') {
                        // Skip items without post date
                        continue;
                    }

                    // Parse post date to compare its year, month and day
                    try {
                        // Create DateTime object from post date string
                        $postDateTime = new \DateTime($postDate);

                        // Check if post date matches URL date
                        if ($postDateTime->format('Y-m-d') === $urlDate) {
                            // Add this item to validated items
                            $validatedItems[] = $item;
                        }
                    } catch (\Exception) {
                        // Skip items with invalid date format
                        continue;
                    }
                }

                // Check if any validated items remain
                if ($validatedItems !== []) {
                    // Check if multiple validated items remain
                    if (\count($validatedItems) > 1) {
                        // Return null if multiple matches found (ambiguous)
                        return null;
                    }

                    // Get the single validated item
                    $matchedItem = $validatedItems[0];
                }
            }

            // ---
            // If date pattern didn't match or date validation found no matches, check
            // category pattern: [^/]+/[^/]+
            // ---

            // Check if no date match was found and URL matches category pattern
            if (
                ! isset($matchedItem)
                && \preg_match(
                    '/^([^\/]+)\/[^\/]+$/',
                    $remainingPath,
                    $categoryMatches
                ) === 1
            ) {
                // Extract category slug from URL
                $urlCategorySlug = $categoryMatches[1];

                // Validate items by matching category nicename
                $validatedItems = [];

                // Loop through each post type item
                foreach ($postTypeItems as $item) {
                    // Get all category elements with domain="category"
                    $categoryElements = $item->xpath(
                        'category[@domain="category"]/@nicename'
                    );
                    $hasMatchingCategory = array_any(\is_array($categoryElements) ? $categoryElements : [], fn ($categoryNicename): bool => (string) $categoryNicename === $urlCategorySlug);

                    // ---
                    // Add item if category matches
                    // ---

                    // Check if category match was found for this item
                    if ($hasMatchingCategory) {
                        // Add item to validated items array
                        $validatedItems[] = $item;
                    }
                }

                // Check if any validated items remain
                if ($validatedItems === []) {
                    // Return null if no items passed validation
                    return null;
                }

                // Check if multiple validated items remain
                if (\count($validatedItems) > 1) {
                    // Return null if multiple matches found (ambiguous)
                    return null;
                }

                // Get the single validated item
                $matchedItem = $validatedItems[0];
            }

            // If we found a match via post type validation, extract and return
            if (isset($matchedItem)) {
                // Extract the WordPress post ID from the matched XML item
                $postId = XpathHelper::getStringValue(
                    $matchedItem,
                    'wp:post_id'
                );

                // Extract the WordPress post name (slug) from the matched XML item
                $postName = XpathHelper::getStringValue(
                    $matchedItem,
                    'wp:post_name'
                );

                // Extract the WordPress post type from the matched XML item
                $postType = XpathHelper::getStringValue(
                    $matchedItem,
                    'wp:post_type'
                );

                // Return null if the post ID or name is missing (the type is post;
                // the name can be empty when the item carries a second, matching one)
                if (
                    $postId === ''
                    || $postName === ''
                ) {
                    return null;
                }

                // Return the found post data
                return [
                    'post_id' => $postId,
                    'post_name' => $postName,
                    'post_type' => $postType,
                ];
            }

            // ---
            // If no post type validation matched, fall through to parent validation
            // (only for non-post types or when post type validation didn't find a match)
            // ---
        }

        // Check if this segment is not the first segment in the path
        if ($lastSegmentIndex > 0) {
            // Validate parent relationships for the candidate items
            $validatedItems = [];

            // Loop through each candidate item
            foreach ($nonPublishedItems as $item) {
                // Get the parent post ID
                $parentPostId = XpathHelper::getStringValue(
                    $item,
                    'wp:post_parent'
                );

                // Skip a root-level item or one with no parent ID, which cannot be
                // the child the path names
                if (
                    $parentPostId === '0'
                    || $parentPostId === ''
                ) {
                    continue;
                }

                // Find the parent post item
                $parentItems = $xml->xpath(
                    "//item[wp:post_id[text()='{$parentPostId}']]"
                );

                // Get the parent post item, skipping an item whose parent cannot be
                // found (or whose parent ID breaks the query)
                $firstParentItem = \is_array($parentItems) ? ($parentItems[0] ?? null) : null;

                if ($firstParentItem === null) {
                    continue;
                }

                $parentPostName = XpathHelper::getStringValue(
                    $firstParentItem,
                    'wp:post_name'
                );

                // Get the expected parent segment (previous segment in path)
                $expectedParentSegment = $pathSegments[$lastSegmentIndex - 1];

                // Check if parent post name matches the expected segment
                if ($parentPostName !== $expectedParentSegment) {
                    // Skip items whose parent doesn't match expected segment
                    continue;
                }

                // If parent segment is the first segment, validate it has no parent
                if ($lastSegmentIndex - 1 === 0) {
                    // Get the parent's parent post ID
                    $parentParentPostId = XpathHelper::getStringValue(
                        $firstParentItem,
                        'wp:post_parent'
                    );

                    // Check if parent's parent is not 0 (should be root level)
                    if ($parentParentPostId !== '0') {
                        // Skip items whose parent is not at root level when it should be
                        continue;
                    }
                }

                // Add this item to validated items
                $validatedItems[] = $item;
            }

            // Check if any validated items remain
            if ($validatedItems === []) {
                // Return null if no items passed validation
                return null;
            }

            // Check if multiple validated items remain
            if (\count($validatedItems) > 1) {
                // Return null if multiple matches found (ambiguous)
                return null;
            }

            // Get the single validated item
            $matchedItem = $validatedItems[0];
        } else { // Otherwise, if this is the first segment, validate it differently
            // Filter out items with non-zero post_parent (should be root level)
            $rootLevelItems = \array_filter(
                $nonPublishedItems,
                function (SimpleXMLElement $item): bool {
                    // Extract the post parent ID from the XML item
                    $postParent = XpathHelper::getStringValue(
                        $item,
                        'wp:post_parent'
                    );

                    // Return true if post is at root level (no parent)
                    return $postParent === '0';
                }
            );

            // Check if any root level items remain
            if ($rootLevelItems === []) {
                // Return null if no root level items found
                return null;
            }

            // Re-index array to remove gaps
            $rootLevelItems = \array_values($rootLevelItems);

            // Check if multiple root level items remain
            if (\count($rootLevelItems) > 1) {
                // Return null if multiple matches found (ambiguous)
                return null;
            }

            // Get the single root level item
            $matchedItem = $rootLevelItems[0];
        }

        // ---
        // Extract post data from the matched item
        // ---

        // Extract the WordPress post ID from the matched XML item
        $postId = XpathHelper::getStringValue(
            $matchedItem,
            'wp:post_id'
        );

        // Extract the WordPress post name (slug) from the matched XML item
        $postName = XpathHelper::getStringValue(
            $matchedItem,
            'wp:post_name'
        );

        // Extract the WordPress post type from the matched XML item
        $postType = XpathHelper::getStringValue(
            $matchedItem,
            'wp:post_type'
        );

        // Check if all required fields are present (the name can be empty when
        // the item carries a second, matching one)
        if (
            $postId === ''
            || $postName === ''
            || $postType === ''
        ) {
            // Return null if any required field is missing
            return null;
        }

        // Return the found post data
        return [
            'post_id' => $postId,
            'post_name' => $postName,
            'post_type' => $postType,
        ];
    }

    /**
     * Finds the post name for a given WordPress post ID by searching the XML export.
     *
     * Uses XpathHelper::findItemByPostId to locate the item, then extracts
     * post name and post type data for return.
     *
     * @param  string  $wordPressPostId  The WordPress post ID to search for
     * @param  string  $xmlPath  The path to the XML export file
     * @return PostData|null Post data if found, or null if not found
     */
    private function findPostNameById(
        string $wordPressPostId,
        string $xmlPath
    ): ?array {
        // Use shared utility method to find the item by post ID
        $item = XpathHelper::findItemByPostId(
            $xmlPath,
            $wordPressPostId
        );

        // Check if item was found
        if (! $item instanceof SimpleXMLElement) {
            // Return null if no matching item found
            return null;
        }

        // Extract post name using XpathHelper for safe extraction
        $wordPressPostName = XpathHelper::getStringValue(
            $item,
            'wp:post_name'
        );

        // Extract post type using XpathHelper for safe extraction
        $wordPressPostType = XpathHelper::getStringValue(
            $item,
            'wp:post_type'
        );

        // ---
        // Check if post type was successfully extracted (post name can be empty for draft
        // posts)
        // ---
        if ($wordPressPostType === '') {
            // Return null if post type is missing
            return null;
        }

        // Return the found post data
        return [
            'post_id' => $wordPressPostId,
            'post_name' => $wordPressPostName,
            'post_type' => $wordPressPostType,
        ];
    }

    /**
     * Parses a URL to extract the base URL, fragment, and query components.
     *
     * Separates the URL into its components for processing. The base URL is used
     * for searching and matching, while fragment and query are stored separately
     * for later use in Bard link attributes.
     *
     * @param  string  $wordPressUrl  The full URL to parse
     * @return ParsedUrlComponents Parsed URL parts
     */
    private static function parseUrlComponents(
        string $wordPressUrl
    ): array {
        // Initialize base URL with the full URL
        $base = $wordPressUrl;

        // Initialize fragment identifier as null
        $fragment = null;

        // Initialize query string as null
        $query = null;

        // Check if URL contains a fragment identifier (#section)
        if (
            str_contains(
                $wordPressUrl,
                '#'
            )
        ) {
            // Split URL at the first # character
            $parts = \explode(
                '#',
                $wordPressUrl,
                2
            );

            // Set base URL to the part before the fragment
            $base = $parts[0];

            // Set fragment identifier with the # prefix
            $fragment = "#{$parts[1]}";
        }

        // Check if base URL contains a query string (?param=value)
        if (
            str_contains(
                $base,
                '?'
            )
        ) {
            // Split base URL at the first ? character
            $parts = \explode(
                '?',
                $base,
                2
            );

            // Set base URL to the part before the query string
            $base = $parts[0];

            // Set query string with the ? prefix
            $query = "?{$parts[1]}";
        }

        // Return the parsed URL components
        return [
            'base' => $base,
            'fragment' => $fragment,
            'query' => $query,
        ];
    }

    /**
     * Fetches HTML content from a URL and extracts the page-id class value.
     *
     * Makes an HTTP request to the given URL and searches the HTML body class
     * for a class matching the pattern `/page-id-\d+/`. If found, extracts
     * the numeric value which represents the WordPress post ID.
     *
     * The URL comes from the WXR being imported, so the fetch is performed by
     * {@see GuardedHttpFetcher}, which refuses any hop targeting a non-public
     * address (wordpress-importer-archive#534).
     *
     * @param  string  $wordPressUrl  The URL to fetch HTML content from
     * @return ?string The extracted post ID if found, null otherwise
     */
    private function extractPageIdFromHtml(
        string $wordPressUrl
    ): ?string {
        // Fetch the body, re-validating every redirect hop against the egress
        // guard before its request is sent
        $html = GuardedHttpFetcher::fetchBody(
            $wordPressUrl,
            'Mozilla/5.0 (compatible; WordPress Import Tool)'
        );

        // Return null if the fetch was refused or failed
        if ($html === null) {
            return null;
        }

        // Search for the page-id class pattern in the HTML body class
        // Handle complex body tags with multiple classes and attributes
        if (
            \preg_match(
                '/<body[^>]*class="[^"]*?\bpage-id-(\d+)\b[^"]*"[^>]*>/i',
                $html,
                $matches
            ) === 1
        ) {
            // Return the extracted post ID
            return $matches[1];
        }

        // Return null if no page-id class was found
        return null;
    }

    /**
     * Processes an attachment URL as a file URL for deferred link processing.
     *
     * When a deferred link points to an attachment post type, this method
     * processes the attachment URL through the file import workflow.
     *
     * @param  string  $attachmentUrl  The attachment URL to process
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @return ?string The processed file URL if successful, original URL if uploads disabled, null otherwise
     */
    private static function processAttachmentUrlAsFileForDeferred(
        string $attachmentUrl,
        string $statamicSiteHandle,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): ?string {
        // Check if file uploads are disabled
        if (
            self::checkUploadsDisabled(
                $assetImporter,
                $output,
                'linked attachment: '.basename($attachmentUrl),
                'file'
            )
        ) {
            // Return the original URL when uploads are disabled
            return $attachmentUrl;
        }

        // Check if the attachment URL is a file URL
        if (! self::isFileUrl($attachmentUrl)) {
            // Return the original URL if it's not a file URL
            return $attachmentUrl;
        }

        // Import the file and get the new path
        $newPath = $assetImporter->importFile(
            $attachmentUrl,
            $statamicSiteHandle,
            $output,
            'files',
            SameSitePolicy::LINK_DIFFERENT_SITE
        );

        // Check if the file was successfully imported
        if ($newPath !== null && $newPath !== '') {
            // Generate the new Statamic URL for the imported file
            $newUrl = self::getAppUrl().'/files/'.$newPath;

            // Return the new Statamic file URL
            return $newUrl;
        }

        // Return null if import failed
        return null;
    }

    /**
     * Processes an attachment URL as a file URL.
     *
     * When an internal link points to an attachment post type, this method
     * extracts the attachment URL and processes it through the file import
     * workflow, similar to how file URLs are handled in the process() method.
     *
     * @param  string  $attachmentUrl  The attachment URL to process
     * @param  string  $statamicSiteHandle  The Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @return string The processed file URL if successful, otherwise the original attachment URL
     */
    private function processAttachmentUrlAsFile(
        string $attachmentUrl,
        string $statamicSiteHandle,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): string {
        // Check if file uploads are disabled
        if (
            self::checkUploadsDisabled(
                $assetImporter,
                $output,
                'linked attachment: '.basename($attachmentUrl),
                'file'
            )
        ) {
            // Return the original URL when uploads are disabled
            return $attachmentUrl;
        }

        // Check if the attachment URL is a file URL
        if (! self::isFileUrl($attachmentUrl)) {
            // Return the original URL if it's not a file URL
            return $attachmentUrl;
        }

        // Import the file and get the new path
        $newPath = $assetImporter->importFile(
            $attachmentUrl,
            $statamicSiteHandle,
            $output,
            'files',
            SameSitePolicy::LINK_DIFFERENT_SITE
        );

        // Check if the file was successfully imported
        if ($newPath !== null && $newPath !== '') {
            // Generate the new Statamic URL for the imported file
            $newUrl = self::getAppUrl().'/files/'.$newPath;

            // Inform user of successful attachment URL conversion
            $output->writeln("  - <info>Converted attachment link to file: {$attachmentUrl} -> {$newUrl}</info>");

            // Return the new Statamic file URL
            return $newUrl;
        }

        // Return the original URL if import failed
        return $attachmentUrl;
    }

    /**
     * Finds an entry with optimized retry logic for deferred link processing.
     *
     * This helper method centralizes the retry logic for finding entries during
     * deferred link processing. It uses a lightweight approach with minimal cache
     * operations to handle cache timing issues without significant performance
     * overhead. Only used for deferred processing where entries should exist.
     *
     * @param  callable  $findCallback  The callback function to find the entry
     * @param  int  $retryCount  Number of retry attempts (default: 2)
     * @param  int  $retryDelay  Delay between retries in microseconds (default: 100000)
     * @return ?Entry The found entry or null if not found
     */
    public static function findEntryWithRetry(
        callable $findCallback,
        int $retryCount = 2,
        int $retryDelay = 100000
    ): ?Entry {
        // Initialize entry variable for lookup results
        $statamicEntry = null;

        // Attempt to find the entry with lightweight retry logic
        for ($i = 0; $i < $retryCount; $i++) {
            try {
                // Force a fresh, targeted per-entry invalidation on every
                // attempt. The finder's forceFresh path calls forgetItem() for
                // just this entry, so a missed lookup no longer clears the
                // entire entries store (wordpress-importer-archive#306). That per-miss full-store
                // clear()/rebuild was a performance drain on large imports and
                // the source of the repeated wordpress-importer-archive#173 cache-write crash that wordpress-importer-archive#305
                // could only tolerate; the targeted invalidation removes it.
                $candidateEntry = $findCallback(true);
                $statamicEntry = $candidateEntry instanceof Entry ? $candidateEntry : null;
            } catch (\Throwable $e) {
                // A transient cache-write failure during the targeted
                // invalidation must not abort the entire import. On Windows,
                // forgetting a cached item can momentarily hit an
                // ERROR_SHARING_VIOLATION (another handle holding the file)
                // that PHP surfaces as "Permission denied". Log it and let the
                // loop retry — defense-in-depth carried over from wordpress-importer-archive#305.
                Log::warning(
                    'LinkProcessor::findEntryWithRetry: targeted entry invalidation failed; retrying',
                    ['exception' => $e->getMessage()]
                );

                // Treat the failed attempt as a miss so the retry runs
                $statamicEntry = null;
            }

            // Check if entry was found successfully
            if ($statamicEntry instanceof Entry) {
                // Exit retry loop on successful find
                break;
            }

            // Brief delay before the next targeted retry to let a just-saved
            // entry's cache writes settle (skip the wait after the final attempt)
            if ($i < $retryCount - 1) {
                usleep($retryDelay);
            }
        }

        // Return the found entry or null if not found after all retries
        return $statamicEntry;
    }

    /**
     * Gets the application base URL as a string.
     *
     * @return string The configured app URL or empty string
     */
    private static function getAppUrl(): string
    {
        $appUrl = config('app.url');

        return \is_string($appUrl) ? $appUrl : '';
    }

    /**
     * Gets a Minerva setting slug with fallback normalization.
     *
     * @param  array<int|string, mixed>  $settings  Minerva settings array
     * @param  string  $key  Setting key to read
     * @param  string  $default  Default fallback slug
     * @return string Normalized non-empty slug
     */
    private static function normalizeSettingSlug(
        array $settings,
        string $key,
        string $default
    ): string {
        $value = $settings[$key] ?? null;

        if (\is_string($value) && $value !== '') {
            return $value;
        }

        return $default;
    }

    /**
     * Normalizes mixed entry IDs to a string value.
     *
     * @param  mixed  $entryId  Raw entry ID value
     * @return string Normalized entry ID
     */
    private static function normalizeEntryId(
        $entryId
    ): string {
        if (\is_string($entryId)) {
            return $entryId;
        }

        if (\is_int($entryId) || \is_float($entryId)) {
            return (string) $entryId;
        }

        return '';
    }
}
