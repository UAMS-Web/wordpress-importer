<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Interface for image processing steps in the ImageProcessor coordinator.
 *
 * Defines a contract for classes that perform specific image processing tasks,
 * such as parsing HTML, importing assets, or building Bard sets. Each processor
 * operates on a context array to pass data between steps.
 */
interface ImageProcessingStepInterface
{
    /**
     * Processes a specific step in the image processing pipeline.
     *
     * @param  array<int|string, mixed>  $context  The processing context containing input data and results
     * @param  string  $site  The Statamic site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  ?string  $pageSlug  The page slug for logging (optional)
     * @return array<int|string, mixed> The updated processing context
     */
    public function process(
        array $context,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?string $pageSlug = null
    ): array;
}
