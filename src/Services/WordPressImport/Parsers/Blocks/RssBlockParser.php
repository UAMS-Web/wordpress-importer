<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks;

use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Parses WordPress wp:rss blocks into Statamic page_builder replicator sets.
 *
 * Converts the wp:rss block attributes into a Statamic uamsweb_block_rss
 * replicator set for the landing_page blueprint's page_builder field. Handles
 * field mapping, URL validation, and conditional field setting based on the
 * mapping rules.
 */
class RssBlockParser
{
    /**
     * Parses a WordPress wp:rss block into a Statamic page_builder replicator set.
     *
     * Maps block attributes to a uamsweb_block_rss set, processing feed URL, display
     * settings, and conditional fields. Handles field mapping according to the
     * specified rules and omits empty optional fields.
     *
     * @param  array<int|string, mixed>  $attributes  The block attributes from the wp:rss comment
     * @param  string  $site  The Statamic site handle (e.g., 'default') for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images (unused in this block)
     * @param  OutputInterface  $output  Console output for logging progress and errors
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects from XML (unused in this block)
     * @return array<int|string, mixed> The Statamic uamsweb_block_rss replicator set
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = []
    ): array {
        // Initialize result array with required fields
        $result = [
            'id' => (new RowId)->generate(),
            'type' => 'uamsweb_block_rss',
            'enabled' => true,
        ];

        // ---
        // Map feedURL to url
        // ---

        // Initialize feedURL
        $feedUrl = $attributes['feedURL'] ?? null;

        // Check if feedURL is set
        if (is_string($feedUrl) && $feedUrl !== '') {
            // Set url to feedURL as a string
            $result['feed_url'] = $feedUrl;
        }

        // ---
        // Map itemsToShow to item_limit
        // ---

        // Initialize itemsToShow
        $itemsToShow = $attributes['itemsToShow'] ?? null;

        // Check if itemsToShow is set
        if (is_int($itemsToShow)) {
            // Set item_limit to itemsToShow as an integer
            $result['item_limit'] = $itemsToShow;
        } elseif (
            is_string($itemsToShow)
            && preg_match(
                '/^\d+$/',
                $itemsToShow
            ) === 1
        ) {
            // Set item_limit from a numeric string value
            $result['item_limit'] = (int) $itemsToShow;
        } else { // Otherwise, set default item_limit to 5
            // Set item_limit to 5
            $result['item_limit'] = 5;
        }

        // ---
        // Map displayAuthor to author_query
        // ---

        // Initialize displayAuthor
        $displayAuthor = $attributes['displayAuthor'] ?? null;

        // Check if displayAuthor is set
        if ($displayAuthor !== null) {
            // Set author_query to displayAuthor as a boolean
            $result['author_query'] = (bool) $displayAuthor;
        } else { // Otherwise, set default author_query to false
            // Set author_query to false
            $result['author_query'] = false;
        }

        // ---
        // Map displayDate to date_query
        // ---

        // Initialize displayDate
        $displayDate = $attributes['displayDate'] ?? null;

        // Check if displayDate is set
        if ($displayDate !== null) {
            // Set date_query to displayDate as a boolean
            $result['date_query'] = (bool) $displayDate;
        } else { // Otherwise, set default date_query to false
            // Set date_query to false
            $result['date_query'] = false;
        }

        // ---
        // Map displayExcerpt to excerpt_query
        // ---

        // Initialize displayExcerpt
        $displayExcerpt = $attributes['displayExcerpt'] ?? null;

        // Check if displayExcerpt is set
        if ($displayExcerpt !== null) {
            // Set excerpt_query to displayExcerpt as a boolean
            $result['excerpt_query'] = (bool) $displayExcerpt;
        } else { // Otherwise, set default excerpt_query to false
            // Set excerpt_query to false
            $result['excerpt_query'] = false;
        }

        // ---
        // Map excerptLength to excerpt_limit
        // ---

        // Initialize excerptLength
        $excerptLength = $attributes['excerptLength'] ?? null;

        // Check if displayExcerpt is explicitly set to true; a missing excerptLength
        // is null, which neither branch below reads
        if (
            isset($attributes['displayExcerpt'])
            && $attributes['displayExcerpt'] === true
        ) {
            // Convert words to characters by multiplying by 5.9 and rounding
            $characterCount = null;

            if (is_int($excerptLength) || is_float($excerptLength)) {
                $characterCount = (int) \round($excerptLength * 5.9);
            } elseif (
                is_string($excerptLength)
                && is_numeric($excerptLength)
            ) {
                $characterCount = (int) \round($excerptLength * 5.9);
            }

            if ($characterCount !== null) {
                // Set excerpt_limit to characterCount as an integer
                $result['excerpt_limit'] = $characterCount;
            }
        }

        // Set default block_heading
        $result['block_heading'] = 'Placeholder Block Heading — Please Replace';

        // Set default hide_block_heading
        $result['hide_block_heading'] = false;

        // Set default background_color
        $result['background_color'] = 'bg-auto';

        // Clean up variables to free up memory
        unset(
            $feedUrl,
            $itemsToShow,
            $displayAuthor,
            $displayDate,
            $displayExcerpt,
            $excerptLength
        );

        // Return the parsed replicator set
        return $result;
    }
}
