<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use SimpleXMLElement;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;

/**
 * Coordinates the mapping of WordPress metadata to Statamic entry or global set
 * fields.
 *
 * Orchestrates metadata parsing, field mapping, and key tracking to transform
 * WordPress postmeta data into Statamic-compatible structures. Delegates specific
 * responsibilities to specialized classes.
 */
class MetadataMapper
{
    /**
     * Instance of MetaDataParser for parsing WordPress postmeta.
     */
    private MetaDataParser $metaDataParser;

    /**
     * Instance of FieldMapper for mapping metadata to Statamic fields.
     */
    private FieldMapper $fieldMapper;

    /**
     * Instance of KeyTracker for tracking metadata keys.
     */
    private KeyTracker $keyTracker;

    /**
     * Constructor to initialize dependencies.
     *
     * @param  ?MetaDataParser  $metaDataParser  Optional parser instance (defaults to new instance)
     * @param  ?FieldMapper  $fieldMapper  Optional mapper instance (defaults to new instance)
     * @param  ?KeyTracker  $keyTracker  Optional tracker instance (defaults to new instance)
     */
    public function __construct(
        ?MetaDataParser $metaDataParser = null,
        ?FieldMapper $fieldMapper = null,
        ?KeyTracker $keyTracker = null
    ) {
        $this->metaDataParser = $metaDataParser ?? new MetaDataParser;
        $this->fieldMapper = $fieldMapper ?? new FieldMapper;
        $this->keyTracker = $keyTracker ?? new KeyTracker;
    }

    /**
     * Map WordPress metadata to Statamic entry or global set data.
     *
     * Processes postmeta nodes by parsing, mapping to Statamic fields, and tracking
     * keys, updating the provided data array accordingly.
     *
     * @param  SimpleXMLElement  $page  The WordPress page, post, or uamspeople item XML element
     * @param  array<int|string, mixed>  &$data  The Statamic entry or global set data array to populate
     * @param  XmlParser  $xmlParser  The XML parser instance for accessing the full XML
     * @param  AssetImporter  $assetImporter  The asset importer instance for handling files and images
     * @param  array<int|string, mixed>  $attachments  Array of attachment items from the XML
     * @param  OutputInterface  $output  Console output interface for logging
     */
    public function mapMetadata(
        SimpleXMLElement $page,
        array &$data,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        array $attachments,
        OutputInterface $output
    ): void {
        // Set the XML path in processing context for link processing
        ProcessingContext::setXmlPath($xmlParser->getFilePath());

        // Parse the WordPress postmeta data from the page XML
        $metaData = $this->metaDataParser->parse($page);

        // Extract the meta keys from the parsed metadata
        $metaKeys = \array_keys($metaData);

        // Extract the post type from WordPress XML
        $postType = XpathHelper::getStringValue(
            $page,
            'wp:post_type'
        );

        // Map the metadata fields to Statamic data structure
        $mappedKeys = $this->fieldMapper->mapFields(
            $metaData,
            $data,
            $postType,
            $xmlParser,
            $assetImporter,
            $attachments,
            $output
        );

        // Track the metadata keys for analysis and reporting
        $this->keyTracker->trackKeys(
            $page,
            $metaKeys,
            $mappedKeys
        );
    }

    /**
     * Retrieve unaddressed XML keys collected during metadata mapping.
     *
     * @return array<int|string, mixed> Array of unaddressed keys
     */
    public function getUnaddressedKeys(): array
    {
        return $this->keyTracker->getUnaddressedKeys();
    }
}
