<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;

/**
 * Base class for creating or modifying Statamic global sets from WordPress
 * content.
 *
 * Provides common functionality for global set creation or modification, such as
 * overwrite behavior handling, adhering to DRY (Don't Repeat Yourself) and SRP
 * (Single Responsibility Principle) principles. Subclasses implement
 * type-specific mapping logic for global sets like redirects.
 */
abstract class BaseGlobalCreator
{
    /**
     * Overwrite behavior for global set imports.
     *
     * Controls how existing global sets are handled during import.
     * Valid values: 'Overwrite matching sets', 'Skip matching sets',
     * or 'Create an additional set'.
     */
    protected string $globalOverwriteBehavior = 'Overwrite matching sets';

    /**
     * Create or modify global sets for a specific WordPress content type.
     *
     * @param  string  $site  The site handle
     * @param  string  $globalHandle  The global set handle
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  Console output interface
     * @param  MetadataMapper  $metadataMapper  Metadata mapper instance
     * @param  ContentProcessor  $contentProcessor  Content parser instance
     */
    abstract public function createGlobals(
        string $site,
        string $globalHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        MetadataMapper $metadataMapper,
        ContentProcessor $contentProcessor
    ): void;

    /**
     * Set the overwrite behavior for global set imports.
     *
     * @param  string  $behavior  The behavior: 'Overwrite matching sets', 'Skip matching sets', or
     *                            'Create an additional set'
     */
    public function setGlobalOverwriteBehavior(
        string $behavior
    ): void {
        $this->globalOverwriteBehavior = $behavior;
    }
}
