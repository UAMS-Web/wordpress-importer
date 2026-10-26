<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;

/**
 * Base class for form plugin content detectors.
 *
 * This abstract class provides common content detection functionality
 * that can be extended by specific form plugin content detectors.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * common content detection operations.
 */
abstract class BaseContentDetector
{
    /**
     * Create a new BaseContentDetector instance.
     *
     * @param  string  $pluginName  The name of the plugin for logging
     */
    public function __construct(protected string $pluginName) {}

    /**
     * Detect if any form plugin blocks or shortcodes are present in the XML.
     *
     * Scans all content types for form plugin usage patterns.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @return bool True if form plugin usage is detected, false otherwise
     */
    public function hasFormPluginContent(
        XmlParser $xmlParser
    ): bool {
        // Check for form plugin blocks
        if ($this->hasFormPluginBlocks($xmlParser)) {
            return true;
        }

        // Check for form plugin shortcodes
        return $this->hasFormPluginShortcodes($xmlParser);
    }

    /**
     * Check for form plugin blocks in the XML content.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @return bool True if blocks are found, false otherwise
     */
    protected function hasFormPluginBlocks(
        XmlParser $xmlParser
    ): bool {
        // Get all content types from the XML parser
        $contentTypes = $xmlParser->getContentTypes();

        // Check each item of each content type for form plugin blocks
        foreach ($contentTypes as $contentType) {
            // Extract the content type name from the [type, count] pair if needed
            $type = $contentType[0];

            // Retrieve all items for the current content type
            $items = $xmlParser->getItems($type);

            // Iterate through each item to check for form plugin blocks
            foreach ($items as $item) {
                // Extract the encoded content field from the item
                $content = (string) $item->children(
                    'content',
                    true
                )->encoded;

                // Look for form plugin blocks using the pattern from child classes
                if ($this->matchesBlockPattern($content)) {
                    return true;
                }
            }
        }

        // No form plugin blocks found in any content type
        return false;
    }

    /**
     * Check for form plugin shortcodes in the XML content.
     *
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @return bool True if shortcodes are found, false otherwise
     */
    protected function hasFormPluginShortcodes(
        XmlParser $xmlParser
    ): bool {
        // Get all content types from the XML parser
        $contentTypes = $xmlParser->getContentTypes();

        // Check each item of each content type for form plugin shortcodes
        foreach ($contentTypes as $contentType) {
            // Extract the content type name from the [type, count] pair if needed
            $type = $contentType[0];

            // Retrieve all items for the current content type
            $items = $xmlParser->getItems($type);

            // Iterate through each item to check for form plugin shortcodes
            foreach ($items as $item) {
                // Extract the encoded content field from the item
                $content = (string) $item->children(
                    'content',
                    true
                )->encoded;

                // Look for form plugin shortcodes using the pattern from child classes
                if ($this->matchesShortcodePattern($content)) {
                    return true;
                }

                // Also check for shortcodes within ACF block data fields
                if ($this->hasShortcodesInAcfBlockData($content)) {
                    return true;
                }
            }
        }

        // No form plugin shortcodes found in any content type
        return false;
    }

    /**
     * Check for shortcodes within ACF block data fields.
     *
     * Reads each `wp:acf/...` block opener in the content, self-closing or not,
     * and scans its JSON data for shortcodes. Each opener is read only up to the
     * first "-->", which always ends it because WordPress escapes "--" inside
     * block attributes, so a block with inner blocks, or one whose data is not
     * JSON, cannot hide the block after it (wordpress-importer-archive#1369). The scan uses string offsets
     * rather than a regular expression, so a large block cannot exhaust a PCRE
     * limit and be skipped without notice.
     *
     * @param  string  $content  The content to check
     * @return bool True if shortcodes are found in ACF block data, false otherwise
     */
    protected function hasShortcodesInAcfBlockData(
        string $content
    ): bool {
        $offset = 0;

        // Walk the content one HTML comment at a time
        while (($start = \strpos($content, '<!--', $offset)) !== false) {
            // Find the comment's end. Searching from its second "-" lets "<!-->"
            // and "<!--->" end themselves, as HTML ends them, rather than run on
            // to a later block's "-->". The character before it is the "!", which
            // cannot start "-->", so searching from $start + 1 finds the same end.
            // @pest-mutate-ignore: DecrementInteger
            $end = \strpos($content, '-->', $start + 2);

            // An unterminated comment ends the scan, as nothing after it is a block
            if ($end === false) {
                return false;
            }

            // Resume after the "-->". Its ">" cannot start "<!--", so resuming
            // one character earlier finds the same next comment.
            // @pest-mutate-ignore: DecrementInteger
            $offset = $end + 3;

            // Keep only ACF block openers: the name, whitespace, then the data. The
            // text read starts at the opener's "--", so an abruptly closed comment
            // reads as "" or "-" rather than as a negative-length substring.
            $comment = \substr($content, $start + 2, $end - $start - 2);
            if (\preg_match('/^--\s*wp:acf\/\S+\s+(\{.*\})\s*\/?$/s', $comment, $match) !== 1) {
                continue;
            }

            // Decode the JSON data, skipping a block whose data is not a JSON object
            $decodedData = \json_decode(
                $match[1],
                true
            );
            // A capture running from { to } decodes to an array or null, never a
            // scalar, so the is_array() half narrows the type for PHPStan only
            // @pest-mutate-ignore: BooleanOrToBooleanAnd
            if ($decodedData === null || ! \is_array($decodedData)) {
                continue;
            }

            // Recursively scan all string values in the data for shortcodes
            if ($this->scanArrayForShortcodes($decodedData)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Recursively scan an array for shortcodes in string values.
     *
     * @param  array<int|string, mixed>  $data  The data array to scan
     * @return bool True if shortcodes are found, false otherwise
     */
    private function scanArrayForShortcodes(
        array $data
    ): bool {
        foreach ($data as $value) {
            if (\is_string($value)) {
                // Check if this string contains shortcodes
                if ($this->matchesShortcodePattern($value)) {
                    return true;
                }
            } elseif (\is_array($value)) {
                // Recursively check nested arrays
                if ($this->scanArrayForShortcodes($value)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Check if content matches the block pattern for this plugin.
     *
     * @param  string  $content  The content to check
     * @return bool True if content matches block pattern, false otherwise
     */
    abstract protected function matchesBlockPattern(
        string $content
    ): bool;

    /**
     * Check if content matches the shortcode pattern for this plugin.
     *
     * @param  string  $content  The content to check
     * @return bool True if content matches shortcode pattern, false otherwise
     */
    abstract protected function matchesShortcodePattern(
        string $content
    ): bool;
}
