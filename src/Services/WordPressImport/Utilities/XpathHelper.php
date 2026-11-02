<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Illuminate\Support\Facades\Log;
use SimpleXMLElement;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\SplitExportPartLocator;

/**
 * Helper class for safely extracting data from WordPress XML using xpath.
 *
 * This class provides defensive methods for xpath operations that prevent
 * "Undefined array key" errors when XML elements are missing or malformed.
 * It ensures consistent error handling across all WordPress import operations.
 */
class XpathHelper
{
    /**
     * Register WordPress XML namespaces on a SimpleXMLElement for XPath queries.
     *
     * This method centralizes namespace registration to ensure consistency
     * across all XPath operations in the WordPress import system.
     *
     * @param  SimpleXMLElement  $element  The XML element to register namespaces on
     */
    public static function registerWordPressNamespaces(
        SimpleXMLElement $element
    ): void {
        // ---
        // The WordPress XML namespaces used throughout the import system. Kept
        // here rather than in a static property so each entry is code a test can
        // reach, since a property default never appears in line coverage (#1314).
        // ---
        $wordPressNamespaces = [
            'wp' => 'http://wordpress.org/export/1.2/',
            'content' => 'http://purl.org/rss/1.0/modules/content/',
            'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
            'wfw' => 'http://wellformedweb.org/CommentAPI/',
            'dc' => 'http://purl.org/dc/elements/1.1/',
        ];

        // Loop through each WordPress XML namespace definition
        foreach ($wordPressNamespaces as $prefix => $namespace) {
            // Register the namespace prefix with its URI for XPath queries
            $element->registerXPathNamespace(
                $prefix,
                $namespace
            );
        }
    }

    /**
     * Execute xpath and return a guaranteed iterable list of elements.
     *
     * @param  SimpleXMLElement  $element  The XML element to search within
     * @param  string  $xpath  The xpath expression to execute
     * @return array<int, SimpleXMLElement> Matching elements, or an empty array
     */
    public static function xpathElements(
        SimpleXMLElement $element,
        string $xpath
    ): array {
        // Register WordPress XML namespaces for XPath queries
        self::registerWordPressNamespaces($element);

        // Execute xpath query to find matching elements
        $elements = $element->xpath($xpath);

        // Return an empty array when xpath fails instead of false.
        if (! \is_array($elements)) {
            return [];
        }

        // xpath() already returns a list, so array_values() changes nothing at
        // run time; it is here because PHPStan types the result as a plain array.
        // @pest-mutate-ignore: UnwrapArrayValues
        return \array_values($elements);
    }

    /**
     * Execute XPath query with error handling and return the result.
     *
     * This private method centralizes XPath error handling to avoid code duplication
     * across all public XPath methods.
     *
     * @param  SimpleXMLElement  $element  The XML element to search within
     * @param  string  $xpath  The xpath expression to execute
     * @param  string  $methodName  The name of the calling method for logging
     * @return array<int, SimpleXMLElement>|null The XPath result array or null if error occurred
     */
    private static function executeXpathWithErrorHandling(
        SimpleXMLElement $element,
        string $xpath,
        string $methodName
    ): ?array {
        // Attempt to execute the XPath query with error handling
        try {
            // Register WordPress XML namespaces for XPath queries
            self::registerWordPressNamespaces($element);

            // ---
            // Execute the query with libxml's internal-errors mode off, so an
            // invalid expression raises its warning and reaches the catch below.
            // The mode is process-wide, and code outside this package (tiptap-php
            // among it) switches it on and leaves it on, which would otherwise
            // silence this report (wordpress-importer-archive#1357). The caller's mode is restored either way.
            // ---
            $previousLibxmlMode = \libxml_use_internal_errors(false);

            try {
                $elements = $element->xpath($xpath);
            } finally {
                \libxml_use_internal_errors($previousLibxmlMode);
            }

            // Handle case where xpath() does not return an array.
            if (! \is_array($elements)) {
                // Return null to indicate XPath execution failed
                return null;
            }

            // xpath() already returns a list, so array_values() changes nothing
            // at run time; it is here because PHPStan types the result as a plain
            // array.
            // @pest-mutate-ignore: UnwrapArrayValues
            return \array_values($elements);
        } catch (\Exception $exception) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'xpath' => $xpath,
                    'error' => $exception->getMessage(),
                    'method_name' => $methodName,
                ]
            );

            // Log the XPath error for debugging
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    "XPath error in XpathHelper::{$methodName}",
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate XPath execution failed
            return null;
        }
    }

    /**
     * Extract a single value from XPath result with type conversion and default
     * fallback.
     *
     * This private method centralizes the common pattern of executing XPath, checking
     * for results, and converting to the desired type with a default fallback.
     *
     * @param  SimpleXMLElement  $element  The XML element to search within
     * @param  string  $xpath  The xpath expression to execute
     * @param  string  $methodName  The name of the calling method for logging
     * @param  mixed  $default  The default value to return if not found
     * @param  callable  $converter  Function to convert the first element to desired type
     * @return mixed The converted value or default
     */
    private static function extractValueWithConversion(
        SimpleXMLElement $element,
        string $xpath,
        string $methodName,
        mixed $default,
        callable $converter
    ): mixed {
        // Execute XPath query with centralized error handling
        $elements = self::executeXpathWithErrorHandling(
            $element,
            $xpath,
            $methodName
        );

        // Check if XPath execution failed or result is empty
        if (
            $elements === null
            || $elements === []
        ) {
            // Return default value if no elements found or XPath failed
            return $default;
        }

        // Convert and return the first element using the provided converter
        return $converter($elements[0]);
    }

    /**
     * Safely extract a single string value from xpath result.
     *
     * Returns the first element as a string if found, otherwise returns
     * the default value. Prevents "Undefined array key 0" errors and
     * handles XPath namespace errors gracefully.
     *
     * @param  SimpleXMLElement  $element  The XML element to search within
     * @param  string  $xpath  The xpath expression to execute
     * @param  string  $default  The default value to return if not found
     * @return string The extracted value or default
     */
    public static function getStringValue(
        SimpleXMLElement $element,
        string $xpath,
        string $default = ''
    ): string {
        // Attempt to extract and convert the XPath value to string.
        $value = self::extractValueWithConversion(
            $element,
            $xpath,
            'getStringValue',
            $default,
            static fn (
                SimpleXMLElement $xmlElement
            ): string => (string) $xmlElement
        );

        // Return the converted string value or the default.
        return \is_string($value) ? $value : $default;
    }

    /**
     * Safely extract a single integer value from xpath result.
     *
     * Returns the first element as an integer if found, otherwise returns
     * the default value. Prevents "Undefined array key 0" errors and
     * handles XPath namespace errors gracefully.
     *
     * @param  SimpleXMLElement  $element  The XML element to search within
     * @param  string  $xpath  The xpath expression to execute
     * @param  int  $default  The default value to return if not found
     * @return int The extracted value or default
     */
    public static function getIntValue(
        SimpleXMLElement $element,
        string $xpath,
        int $default = 0
    ): int {
        // Attempt to extract and convert the XPath value to integer.
        $value = self::extractValueWithConversion(
            $element,
            $xpath,
            'getIntValue',
            $default,
            static fn (
                SimpleXMLElement $xmlElement
            ): int => (int) $xmlElement
        );

        // Return the converted integer value or the default.
        return \is_int($value) ? $value : $default;
    }

    /**
     * Safely extract a single boolean value from xpath result.
     *
     * Returns the first element as a boolean if found, otherwise returns
     * the default value. Prevents "Undefined array key 0" errors and
     * handles XPath namespace errors gracefully.
     *
     * @param  SimpleXMLElement  $element  The XML element to search within
     * @param  string  $xpath  The xpath expression to execute
     * @param  bool  $default  The default value to return if not found
     * @return bool The extracted value or default
     */
    public static function getBoolValue(
        SimpleXMLElement $element,
        string $xpath,
        bool $default = false
    ): bool {
        // ---
        // Cast to string first: (bool) SimpleXMLElement is always true; we need
        // the element's text content (e.g., "0" / "1") to determine the value.
        // ---
        // Attempt to extract and convert the XPath value to boolean. filter_var()
        // would stringify the element through __toString() by itself, so removing
        // the cast cannot be told apart; it stays to say what is being read.
        // @pest-mutate-ignore: RemoveStringCast
        $value = self::extractValueWithConversion(
            $element,
            $xpath,
            'getBoolValue',
            $default,
            static fn (
                SimpleXMLElement $xmlElement
            ): bool => filter_var(
                (string) $xmlElement,
                FILTER_VALIDATE_BOOLEAN
            )
        );

        // Return the converted boolean value or the default.
        return \is_bool($value) ? $value : $default;
    }

    /**
     * Check if an xpath result exists and is not empty.
     *
     * @param  SimpleXMLElement  $element  The XML element to search within
     * @param  string  $xpath  The xpath expression to execute
     * @return bool True if the xpath result exists and is not empty
     */
    public static function hasValue(
        SimpleXMLElement $element,
        string $xpath
    ): bool {
        // Execute XPath query with centralized error handling
        $elements = self::executeXpathWithErrorHandling(
            $element,
            $xpath,
            'hasValue'
        );

        // Return true if elements exist and are not empty, false if XPath failed
        return $elements !== null && $elements !== [];
    }

    /**
     * Get all matching elements from xpath result.
     *
     * Returns an array of all matching elements, or an empty array if none found.
     * This is safer than directly accessing xpath results and handles XPath errors.
     *
     * @param  SimpleXMLElement  $element  The XML element to search within
     * @param  string  $xpath  The xpath expression to execute
     * @return array<int|string, mixed> Array of SimpleXMLElement objects
     */
    public static function getElements(
        SimpleXMLElement $element,
        string $xpath
    ): array {
        // Execute XPath query with centralized error handling
        $elements = self::executeXpathWithErrorHandling(
            $element,
            $xpath,
            'getElements'
        );

        // Return elements array or empty array if no matches found or XPath failed
        return $elements ?? [];
    }

    /**
     * Find an attachment by post ID with safe xpath operations.
     *
     * This is a specialized method for the common pattern of finding
     * attachments by their post_id in WordPress XML exports.
     *
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  string  $attachmentId  The attachment ID to find
     * @return ?SimpleXMLElement The matching attachment or null if not found
     */
    public static function findAttachmentById(
        array $attachments,
        string $attachmentId
    ): ?SimpleXMLElement {
        // Loop through all attachments to find the matching one
        foreach ($attachments as $attachment) {
            // Skip non-XML attachments before XPath processing.
            if (! $attachment instanceof SimpleXMLElement) {
                continue;
            }

            // Extract post ID using safe xpath method
            $postId = self::getStringValue(
                $attachment,
                'wp:post_id'
            );

            // Check if this attachment matches the requested ID
            if ($postId === $attachmentId) {
                // Return the matching attachment
                return $attachment;
            }
        }

        // Return null if no matching attachment found
        return null;
    }

    /**
     * Find an attachment by URL (wp:attachment_url) with safe xpath operations.
     *
     * When content was updated in WordPress (e.g., image replaced) the img src
     * may point to a different file than the wp-image-N class. Prefer resolving
     * by URL so the displayed image matches the imported asset.
     *
     * Matches first by exact normalized URL, then by path suffix so content
     * using short/rewrite URLs (e.g., /files/2020/08/X.jpg) resolve to the
     * attachment whose wp:attachment_url is the full path (e.g., .../files/.../X.jpg).
     *
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  string  $url  The image or file URL to find (e.g., img src)
     * @return ?SimpleXMLElement The matching attachment or null if not found
     */
    public static function findAttachmentByUrl(
        array $attachments,
        string $url
    ): ?SimpleXMLElement {
        // Normalize the given URL for comparison (strip size suffix)
        $normalizedUrl = AssetUtils::normalizeWordPressImageUrl($url);

        // Compare each attachment URL to the given URL (exact match)
        foreach ($attachments as $attachment) {
            // Skip non-XML attachments before XPath processing.
            if (! $attachment instanceof SimpleXMLElement) {
                continue;
            }

            // Get this attachment's URL from wp:attachment_url
            $attachmentUrl = self::getStringValue(
                $attachment,
                'wp:attachment_url'
            );

            // Skip attachments with no URL
            if ($attachmentUrl === '') {
                // Skip to next attachment
                continue;
            }

            // Normalize attachment URL for comparison
            $normalizedAttachmentUrl = AssetUtils::normalizeWordPressImageUrl(
                $attachmentUrl
            );

            // Return this attachment when URLs match
            if ($normalizedAttachmentUrl === $normalizedUrl) {
                // Return the matching attachment
                return $attachment;
            }
        }

        // ---
        // Fallback: match by path suffix so short/rewrite content URLs
        // (e.g., https://example.org/files/2020/08/DHSBanner.jpg) resolve to
        // attachments whose wp:attachment_url uses the full path
        // (e.g., .../wp-content/blogs.dir/16/files/2020/08/DHSBanner.jpg).
        // ---

        // Extract content path from normalized URL
        $contentPath = \parse_url(
            $normalizedUrl,
            \PHP_URL_PATH
        );

        // Check if content path is a string and is not empty
        if (
            \is_string($contentPath)
            && $contentPath !== ''
        ) {
            // Loop through all attachments to find the matching one
            foreach ($attachments as $attachment) {
                // Skip non-XML attachments before XPath processing.
                if (! $attachment instanceof SimpleXMLElement) {
                    continue;
                }

                // Get this attachment's URL from wp:attachment_url
                $attachmentUrl = self::getStringValue(
                    $attachment,
                    'wp:attachment_url'
                );

                // No empty-URL skip is needed here: an empty URL has an empty
                // path, which never ends with the non-empty content path

                // Normalize attachment URL for comparison
                $normalizedAttachmentUrl = AssetUtils::normalizeWordPressImageUrl(
                    $attachmentUrl
                );

                // Extract attachment path from URL
                $attachmentPath = \parse_url(
                    $normalizedAttachmentUrl,
                    \PHP_URL_PATH
                );

                // Check if attachment path ends with content path
                if (
                    \is_string($attachmentPath)
                    && \str_ends_with(
                        $attachmentPath,
                        $contentPath
                    )
                ) {
                    // Return the matching attachment
                    return $attachment;
                }
            }
        }

        // No matching attachment found
        return null;
    }

    /**
     * Extracts the WordPress metadata the migration redirect generator needs.
     *
     * `UAMS-Web/uams-statamic-archive#1641` synthesizes structural WordPress redirects —
     * `?p=<id>` / `?page_id=<id>` ugly permalinks and `/author/<nicename>/` archives —
     * from values the importer otherwise discards: the numeric post id is folded into
     * the Statamic entry id rather than stored, and the author is dropped entirely.
     *
     * The author is stamped as `wordpress_author_login`, NOT as a nicename. `dc:creator`
     * carries the WordPress `user_login` (`Ashley`, `Brent`), while an author archive URL
     * uses the `user_nicename` slug (`ashley`). WXR does not export the nicename at all —
     * there is no `wp:author_nicename` element anywhere in the corpus — so the importer
     * cannot resolve it and must not imply that it has. Deriving the slug is
     * `sanitize_title()`'s job and belongs to the consumer, which knows its own routing.
     *
     * Returned as loose data keys, matching `original_wordpress_url`, which no blueprint
     * declares either. A key is omitted rather than written empty, so a value that cannot
     * drive a redirect never reaches the generator (wordpress-importer-archive#446).
     *
     * @param  SimpleXMLElement  $item  The WordPress item element
     * @return array<string, int|string> Stamped metadata, empty when the item carries none
     */
    public static function extractMigrationRedirectMetadata(
        SimpleXMLElement $item
    ): array {
        $metadata = [];

        // Read the numeric post id that drives `?p=` and `?page_id=` redirects
        $postId = \trim(
            self::getStringValue(
                $item,
                'wp:post_id'
            )
        );

        // Stamp only a genuinely numeric, non-zero id, stored as an integer so
        // `?p=<id>` lookups compare numerically. `ctype_digit('')` is false, so a
        // blank id counts as zero. Any fallback of zero or below behaves the same.
        // @pest-mutate-ignore: DecrementInteger
        $numericPostId = \ctype_digit($postId) ? (int) $postId : 0;

        if ($numericPostId > 0) {
            $metadata['wordpress_post_id'] = $numericPostId;
        }

        // Read the author login that an `/author/…/` archive redirect derives from
        $author = \trim(
            self::getStringValue(
                $item,
                'dc:creator'
            )
        );

        // Skip a blank author so no empty archive redirect is synthesized
        if ($author !== '') {
            $metadata['wordpress_author_login'] = $author;
        }

        return $metadata;
    }

    /**
     * Extract attachment data safely from a WordPress XML attachment element.
     *
     * This method extracts common attachment fields (URL, alt text, caption,
     * title) with proper error handling for missing elements and XPath
     * namespace errors.
     *
     * @param  SimpleXMLElement  $attachment  The attachment XML element
     * @return array<int|string, mixed> Keys: url, alt, caption, title, focus (Statamic focal string), creator (photo credit)
     */
    public static function extractAttachmentData(
        SimpleXMLElement $attachment
    ): array {
        // Extract title from attachment XML, stripping HTML tags. The stripper
        // decodes entities once more, so text encoded twice comes out decoded.
        $title = HtmlTagStripper::strip(
            html_entity_decode(
                (string) $attachment->title,
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            )
        );

        return [
            'url' => self::getStringValue(
                $attachment,
                'wp:attachment_url'
            ),
            'alt' => self::getStringValue(
                $attachment,
                "wp:postmeta[wp:meta_key='_wp_attachment_image_alt']/wp:meta_value"
            ),
            'caption' => self::getStringValue(
                $attachment,
                'excerpt:encoded'
            ),
            'title' => $title,
            'focus' => AttachmentFocalPointCalculator::computeFromAttachment(
                $attachment
            ),
            'creator' => AttachmentCreditResolver::computeFromAttachment(
                $attachment
            ),
        ];
    }

    /**
     * Check if a WordPress status should be skipped during import.
     *
     * Returns true for statuses that should be excluded from import (e.g., 'trash',
     * 'virtual').
     *
     * @param  string  $status  The WordPress status to check
     * @return bool True if the status should be skipped, false otherwise
     */
    public static function shouldSkipStatus(
        string $status
    ): bool {
        // Return true if status is trash or virtual
        return $status === 'trash' || $status === 'virtual';
    }

    /**
     * Build an XPath query that excludes skipped statuses (trash, virtual).
     *
     * Creates an XPath query fragment that filters out items with statuses that
     * should be skipped during import. Can be combined with other conditions in
     * XPath queries.
     *
     * @param  string  $postType  The post type to filter by
     * @param  ?string  $additionalConditions  Optional additional XPath conditions to append
     * @return string The XPath query fragment excluding skipped statuses
     */
    public static function buildStatusExclusionXPath(
        string $postType,
        ?string $additionalConditions = null
    ): string {
        // Build base XPath query excluding trash and virtual statuses
        $query = "//item[wp:post_type='{$postType}' and wp:status!='trash' and wp:status!='virtual'";

        // Check if additional conditions are provided
        if ($additionalConditions !== null) {
            // Append additional conditions to the query
            $query .= " and {$additionalConditions}";
        }

        // Close the XPath query bracket
        $query .= ']';

        // Return the complete XPath query
        return $query;
    }

    /**
     * Build a safe XPath 1.0 string literal for a value that may contain quotes.
     *
     * XPath 1.0 string literals have no escape mechanism, so a value containing a
     * single quote is wrapped in double quotes, a value containing both quote
     * styles is assembled with concat(), and an ordinary value is single quoted.
     * This prevents a value from breaking out of the surrounding literal (XPath
     * injection) when interpolated into a query.
     *
     * @param  string  $value  The raw value to embed as an XPath string literal
     * @return string A well-formed XPath string literal whose string value equals $value
     */
    public static function xpathStringLiteral(
        string $value
    ): string {
        // Use single quotes when the value has no single quote (the common case)
        if (! \str_contains($value, "'")) {
            return "'".$value."'";
        }

        // Use double quotes when the value has single but no double quotes
        if (! \str_contains($value, '"')) {
            return '"'.$value.'"';
        }

        // The value contains both quote styles — assemble it with concat()
        $segments = \explode(
            "'",
            $value
        );

        // Single-quote each segment so the joined literal quotes are interleaved
        $quotedSegments = \array_map(
            fn (string $segment): string => "'".$segment."'",
            $segments
        );

        // Join the segments with a standalone double-quoted single quote
        return 'concat('.\implode(
            ", \"'\", ",
            $quotedSegments
        ).')';
    }

    /**
     * Find a WordPress item by post ID across a WXR export's part(s).
     *
     * Items are partitioned across the parts of a split export, so this expands
     * the given path to its full part-set and searches each part in turn — loading
     * one part at a time — returning the first matching item as a SimpleXMLElement.
     * A single-file export resolves to a one-part set, preserving prior behavior.
     *
     * @param  string  $xmlPath  The path to any one export part (or a single-file export)
     * @param  string  $postId  The WordPress post ID to find
     * @param  ?string  $postType  Optional post type to filter by (e.g., 'page', 'post')
     * @return ?SimpleXMLElement The found item or null if not found in any part
     */
    public static function findItemByPostId(
        string $xmlPath,
        string $postId,
        ?string $postType = null
    ): ?SimpleXMLElement {
        // Expand any single part path to the whole ordered split-export part-set
        $parts = SplitExportPartLocator::locate($xmlPath)->parts;

        // Build XPath query to find item by post ID (shared across every part),
        // embedding the id as a safe literal so a quote cannot break out of it
        $query = '//item[wp:post_id[text()='.self::xpathStringLiteral($postId).']';

        // Add post type filter if specified
        if ($postType !== null) {
            $query .= ' and wp:post_type='.self::xpathStringLiteral($postType);
        }

        $query .= ']';

        // Search each part in turn, returning the first matching item found
        foreach ($parts as $part) {
            // Skip a part that is no longer present on disk. The locator lists
            // only parts that exist, so a missing one is either the lone path of a
            // single-file export or a file removed mid-run; with nothing after the
            // lone path, stopping and skipping cannot be told apart in a test.
            // @pest-mutate-ignore: ContinueToBreak
            if (! \file_exists($part)) {
                continue;
            }

            // Load this part's XML document
            $xml = simplexml_load_file(WxrSanitizedStream::wrap($part));

            // Skip a part that could not be parsed
            if ($xml === false) {
                continue;
            }

            // Register WordPress XML namespaces for XPath queries
            self::registerWordPressNamespaces($xml);

            // Execute XPath query to find matching items in this part
            $items = $xml->xpath($query);

            // Return the first matching item when this part contains it
            if (
                \is_array($items)
                && isset($items[0])
            ) {
                return $items[0];
            }

            // Free this part before loading the next one to bound peak memory
            unset(
                $xml,
                $items
            );
        }

        return null;
    }
}
