<?php

declare(strict_types=1);

/**
 * Tests for the overwrite behavior every global set creator carries: it starts at
 * overwriting matching sets and takes whatever the operator chose (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/BaseGlobalCreatorTest.php
 */

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\BaseGlobalCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;

/**
 * A creator that creates nothing and reports the behavior it holds.
 */
final class BaseGlobalCreatorTestCreator extends BaseGlobalCreator
{
    /**
     * Create nothing.
     *
     * @param  string  $site  The site handle
     * @param  string  $globalHandle  The global set handle
     * @param  XmlParser  $xmlParser  The XML parser
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  Console output
     * @param  MetadataMapper  $metadataMapper  The metadata mapper
     * @param  ContentProcessor  $contentProcessor  The content processor
     */
    public function createGlobals(
        string $site,
        string $globalHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        MetadataMapper $metadataMapper,
        ContentProcessor $contentProcessor
    ): void {}

    /**
     * The overwrite behavior held.
     */
    public function behavior(): string
    {
        return $this->globalOverwriteBehavior;
    }
}

it('overwrites matching sets until told otherwise', function (): void {
    expect((new BaseGlobalCreatorTestCreator)->behavior())->toBe('Overwrite matching sets');
});

it('holds the overwrite behavior it is given', function (string $behavior): void {
    $creator = new BaseGlobalCreatorTestCreator;

    $creator->setGlobalOverwriteBehavior($behavior);

    expect($creator->behavior())->toBe($behavior);
})->with([
    'skip' => ['Skip matching sets'],
    'create another' => ['Create an additional set'],
]);
