<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\AlignmentExtractor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\AltTextProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\AssetImporterProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\BardSetBuilder;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\CaptionExtractor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\HtmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\ImageProcessingStepInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Traits\DisabledUploadHandlingTrait;

/**
 * Coordinates image processing into Statamic Bard image sets.
 *
 * Acts as a facade to process <img> tags and wp:image blocks by delegating tasks
 * to specialized processors (e.g., HtmlParser, AssetImporterProcessor).
 * Orchestrates the processing pipeline to ensure consistent image handling across
 * non-ACF blocks, uams-content WYSIWYG, and uams-section InnerBlocks.
 */
class ImageProcessor
{
    /**
     * Provides shared functionality for handling disabled uploads.
     *
     * This trait encapsulates common logic for checking upload status,
     * logging disabled upload messages, and finding attachments by ID.
     * It eliminates code duplication between image and file processors.
     */
    use DisabledUploadHandlingTrait;

    /**
     * Array of processing steps in the image processing pipeline.
     *
     * @var ImageProcessingStepInterface[]
     */
    private array $processors = [];

    /**
     * Constructs a new ImageProcessor instance.
     *
     * Initializes the processing pipeline with default processors for HTML parsing,
     * asset importing, alignment, captions, alt text, and Bard set building.
     */
    public function __construct()
    {
        $this->processors = [
            new HtmlParser,
            new AssetImporterProcessor,
            new AlignmentExtractor,
            new CaptionExtractor,
            new AltTextProcessor,
            new BardSetBuilder,
        ];
    }

    /**
     * Processes an image element or wp:image block into a Statamic image Bard set.
     *
     * Executes the processing pipeline, passing the HTML through each processor to
     * parse, import, and build a Bard set. Returns the ProseMirror JSON array or
     * null if processing fails.
     *
     * @param  string  $html  The HTML fragment containing the <img> tag or wp:image block
     * @param  string  $site  The Statamic site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  ?string  $pageSlug  The page slug for logging (optional)
     * @param  array<int|string, mixed>|null  $blockAttributes  Attributes from wp:image block (optional)
     * @return array<int|string, mixed>|null The Bard image set as ProseMirror JSON, or null if processing fails
     */
    public function processImage(
        string $html,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?string $pageSlug = null,
        ?array $blockAttributes = null
    ): ?array {
        // Initialize processing context with HTML and block attributes
        $context = [
            'html' => $html,
            'blockAttributes' => $blockAttributes ?? [],
        ];

        // Execute each processor in sequence
        foreach ($this->processors as $processor) {
            $context = $processor->process(
                $context,
                $site,
                $assetImporter,
                $output,
                $attachments,
                $pageSlug
            );
        }

        // Check if image processing pipeline produced a valid image set
        if (! isset($context['imageSet'])) {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'pageSlug' => $pageSlug ?? 'none',
                    'html_snippet' => \substr(
                        $html,
                        0,
                        100
                    ).(\strlen($html) > 100 ? '...' : ''),
                ]
            );

            // Log warning about failed image processing pipeline
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'ImageProcessor::processImage: Image processing pipeline failed to produce an image set',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null when image processing pipeline fails
            return null;
        }

        // Return the successfully processed image set
        $imageSet = $context['imageSet'];

        return \is_array($imageSet) ? $imageSet : null;
    }

    /**
     * Adds a custom processor to the pipeline.
     *
     * @param  ImageProcessingStepInterface  $processor  The processor to add
     */
    public function addProcessor(
        ImageProcessingStepInterface $processor
    ): void {
        $this->processors[] = $processor;
    }

    /**
     * Imports an image with graceful handling for disabled uploads.
     *
     * When image uploads are disabled, this method finds the original URL from
     * the attachments array instead of attempting to import the image. This
     * prevents import failures and allows the processing pipeline to continue
     * using the original WordPress image URLs.
     *
     * @param  string  $imageId  The WordPress image/attachment ID to import
     * @param  string  $site  The Statamic site handle for asset paths
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  string  $description  Description of the image for logging purposes
     * @param  string  $context  Context information for logging (e.g., class::method)
     * @return ?string The imported image path or original URL, or null if not found
     */
    public static function importImageWithDisabledUploadHandling(
        string $imageId,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments,
        string $description,
        string $context
    ): ?string {
        // Check if image ID is empty
        if ($imageId === '') {
            // Return null for empty image IDs without logging (expected behavior)
            return null;
        }

        // Check if image uploads are disabled
        if (
            self::checkUploadsDisabled(
                $assetImporter,
                $output,
                $description,
                'image'
            )
        ) {
            // Find attachment by ID using shared logic
            $attachmentData = self::findAttachmentById(
                $imageId,
                $attachments,
                $site,
                $description,
                $context,
                $assetImporter,
                'image'
            );

            // Return attachment URL if found, null otherwise
            $attachmentUrl = $attachmentData['url'] ?? null;

            return \is_string($attachmentUrl) ? $attachmentUrl : null;
        }

        // ---
        // Otherwise, import image using AssetImporter.
        //
        // Note: hasFallbackUrl is false because this method only has an image ID,
        // no fallback URL is available.
        // ---
        return $assetImporter->importImage(
            $imageId,
            $site,
            $attachments,
            $output,
            SameSitePolicy::ALWAYS_IMPORT,
            null,
            'images',
            false
        );
    }
}
