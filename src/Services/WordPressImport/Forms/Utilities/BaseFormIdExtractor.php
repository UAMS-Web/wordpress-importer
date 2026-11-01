<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Forms\Utilities;

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Base class for form ID extractors.
 *
 * This abstract class provides common form ID extraction functionality
 * that can be extended by specific form plugin extractors.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * form ID extraction operations.
 */
abstract class BaseFormIdExtractor
{
    /**
     * Create a new BaseFormIdExtractor instance.
     *
     * @param  string  $pluginName  The name of the plugin for logging
     */
    public function __construct(protected string $pluginName) {}

    /**
     * Extract form IDs from WordPress XML export content.
     *
     * Searches for both block and shortcode patterns to find form IDs
     * used in the WordPress content.
     *
     * @param  string  $content  The WordPress XML export content
     * @return list<int|string> Array of unique form IDs found
     */
    public function extractFormIds(
        string $content
    ): array {
        $formIds = [];

        // Extract IDs from blocks
        $blockIds = $this->extractIdsFromBlocks($content);
        $formIds = \array_merge(
            $formIds,
            $blockIds
        );

        // Extract IDs from shortcodes
        $shortcodeIds = $this->extractIdsFromShortcodes($content);
        $formIds = \array_merge(
            $formIds,
            $shortcodeIds
        );

        // Return unique IDs
        return \array_values(
            \array_unique($formIds)
        );
    }

    /**
     * Extract form IDs from block patterns in the content.
     *
     * @param  string  $content  The content to search
     * @return list<int|string> Array of form IDs found in blocks
     */
    protected function extractIdsFromBlocks(
        string $content
    ): array {
        $formIds = [];

        // A pattern that fails to run leaves no matches to read
        if (
            \preg_match_all(
                $this->getBlockPattern(),
                $content,
                $matches,
                PREG_SET_ORDER
            ) === false
        ) {
            return [];
        }

        foreach ($matches as $match) {
            // Read the form ID attribute from the block's JSON, when it decodes to an object
            $blockData = \json_decode(
                $match[1],
                true
            );
            $formId = \is_array($blockData)
                ? ($blockData[$this->getFormIdAttribute()] ?? null)
                : null;

            if (\is_int($formId) || \is_string($formId)) {
                $formIds[] = $formId;
            }
        }

        return $formIds;
    }

    /**
     * Extract form IDs from shortcode patterns in the content.
     *
     * @param  string  $content  The content to search
     * @return list<string> Array of form IDs found in shortcodes
     */
    protected function extractIdsFromShortcodes(
        string $content
    ): array {
        $formIds = [];

        // A pattern that fails to run leaves no matches to read
        if (
            \preg_match_all(
                $this->getShortcodePattern(),
                $content,
                $matches,
                PREG_SET_ORDER
            ) === false
        ) {
            return [];
        }

        // The ID is the first capture group, which an optional group can leave unset
        foreach ($matches as $match) {
            if (isset($match[1])) {
                $formIds[] = $match[1];
            }
        }

        return $formIds;
    }

    /**
     * Get the regex pattern for matching form blocks.
     *
     * @return string The regex pattern for blocks
     */
    abstract protected function getBlockPattern(): string;

    /**
     * Get the regex pattern for matching form shortcodes.
     *
     * @return string The regex pattern for shortcodes
     */
    abstract protected function getShortcodePattern(): string;

    /**
     * Get the form ID attribute name for the specific plugin.
     *
     * @return string The form ID attribute name
     */
    abstract protected function getFormIdAttribute(): string;

    /**
     * Get all form IDs used in the XML, associated with page slugs.
     *
     * @return array<int|string, list<string>> Associative array of form IDs and page slugs
     */
    public function getFormIdsUsedWithPageSlugs(
        XmlParser $xmlParser
    ): array {
        // Initialize the result array to store form IDs and their associated page slugs
        $formIdsUsed = [];

        // Retrieve all 'page' items from the XML
        $items = $xmlParser->getItems('page');

        // Iterate over each page item in the XML
        foreach ($items as $item) {
            // Extract the page slug from the current item
            $pageSlug = XpathHelper::getStringValue(
                $item,
                'wp:post_name'
            );

            // Extract the encoded content from the current item
            $content = XpathHelper::getStringValue(
                $item,
                'content:encoded'
            );

            // Extract form IDs from this content using the extractor logic
            $formIds = $this->extractFormIds($content);

            // Associate each found form ID with the current page slug
            foreach ($formIds as $formId) {
                $formIdsUsed[$formId] ??= [];

                // Add the page slug to the form ID's array if not already present
                if (
                    $pageSlug !== ''
                    && ! \in_array(
                        $pageSlug,
                        $formIdsUsed[$formId],
                        true
                    )
                ) {
                    $formIdsUsed[$formId][] = $pageSlug;
                }
            }
        }

        // Return the associative array of form IDs and their page slugs
        return $formIdsUsed;
    }
}
