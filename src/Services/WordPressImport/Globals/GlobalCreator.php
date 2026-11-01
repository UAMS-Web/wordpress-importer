<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals;

use Exception;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Globals\Creators\RedirectRuleGlobalCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;

/**
 * Coordinates the creation or modification of Statamic global sets from WordPress
 * content.
 *
 * Orchestrates the import process by delegating type-specific global set
 * modifications to dedicated creator classes (e.g., RedirectRuleGlobalCreator),
 * ensuring adherence to the Single Responsibility Principle (SRP) and scalability
 * for additional global content types. Manages shared dependencies.
 */
class GlobalCreator
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
     * Create a new GlobalCreator instance.
     *
     * @param  MetadataMapper  $metadataMapper  Metadata mapper instance
     * @param  ContentProcessor  $contentProcessor  Content processor instance
     */
    public function __construct(protected MetadataMapper $metadataMapper, protected ContentProcessor $contentProcessor) {}

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

    /**
     * Create or modify global sets for a specific WordPress content type.
     *
     * @param  string  $site  The site handle
     * @param  string  $contentType  The WordPress content type (e.g., 'redirect_rule')
     * @param  string  $globalHandle  The global set handle (e.g., 'redirects')
     * @param  XmlParser  $xmlParser  The XML parser instance
     * @param  AssetImporter  $assetImporter  The asset importer instance
     * @param  OutputInterface  $output  Console output interface
     */
    public function createGlobals(
        string $site,
        string $contentType,
        string $globalHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output
    ): void {
        $creator = $this->getCreator($contentType);
        $creator->setGlobalOverwriteBehavior($this->globalOverwriteBehavior);
        $creator->createGlobals(
            $site,
            $globalHandle,
            $xmlParser,
            $assetImporter,
            $output,
            $this->metadataMapper,
            $this->contentProcessor
        );

        if ($this->metadataMapper->getUnaddressedKeys() !== []) {
            $output->writeln('<comment>Unaddressed XML keys found:</comment>');
            $unaddressedKeys = \array_map(
                static function (mixed $key): string {
                    if (is_string($key)) {
                        return $key;
                    }

                    if (is_int($key)) {
                        return (string) $key;
                    }

                    return '';
                },
                $this->metadataMapper->getUnaddressedKeys()
            );
            foreach (\array_unique($unaddressedKeys) as $key) {
                $output->writeln("- {$key}");
            }
        }
    }

    /**
     * Get the appropriate global creator instance for the content type.
     *
     * @param  string  $contentType  The WordPress content type
     * @return BaseGlobalCreator The creator instance
     *
     * @throws Exception If the content type is unsupported
     */
    private function getCreator(
        string $contentType
    ): BaseGlobalCreator {
        return match ($contentType) {
            'redirect_rule' => new RedirectRuleGlobalCreator,
            default => throw new Exception("Unsupported global content type: $contentType"),
        };
    }
}
