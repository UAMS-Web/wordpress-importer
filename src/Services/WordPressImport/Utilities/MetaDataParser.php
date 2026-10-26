<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use SimpleXMLElement;

/**
 * Parses WordPress postmeta nodes from XML into a key-value array.
 *
 * Extracts metadata from a WordPress XML element, providing a raw data structure
 * for further mapping and processing.
 */
class MetaDataParser
{
    /**
     * Parse WordPress postmeta nodes into an associative array.
     *
     * @param  SimpleXMLElement  $page  The WordPress page, post, or uamspeople item XML element
     * @return array<int|string, mixed> Associative array of meta keys and values
     */
    public function parse(
        SimpleXMLElement $page
    ): array {
        // Extract all postmeta nodes from the WordPress XML page
        $metaNodes = XpathHelper::xpathElements($page, 'wp:postmeta');

        // Initialize array to store parsed metadata
        $metaData = [];

        // Loop through each postmeta node to extract key-value pairs
        foreach ($metaNodes as $meta) {
            // Extract the meta key from WordPress postmeta
            $key = XpathHelper::getStringValue(
                $meta,
                'wp:meta_key'
            );

            // Extract the meta value from WordPress postmeta
            $value = XpathHelper::getStringValue(
                $meta,
                'wp:meta_value'
            );

            // Store the key-value pair in the metadata array
            $metaData[$key] = $value;
        }

        // Return the parsed metadata array
        return $metaData;
    }
}
