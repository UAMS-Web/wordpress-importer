<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor;

use DOMElement;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Extracts captions from image figure elements for Statamic Bard sets.
 *
 * Retrieves caption text from `<figcaption>` elements within a `<figure>` parent
 * of an `<img>` tag, if present. Stores an empty string for missing captions to
 * ensure field propagation in the Bard set. A `[caption]` shortcode has no
 * `<figure>`, so its caption is left empty here for `CaptionShortcodeProcessor`.
 */
class CaptionExtractor implements ImageProcessingStepInterface
{
    /**
     * Extracts caption from the figure element.
     *
     * Retrieves caption text from the first `<figcaption>` within the `<figure>`
     * parent of the `<img>` tag, storing trimmed text or an empty string in the
     * context. A `[caption]` shortcode has no `<figure>`, so its caption stays
     * empty for `CaptionShortcodeProcessor` to handle.
     *
     * @param  array<int|string, mixed>  $context  Processing context with 'figure'
     * @param  string  $site  Statamic site handle
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  ?string  $pageSlug  Page slug for context (optional)
     * @return array<int|string, mixed> Updated context with 'caption' field set
     */
    public function process(
        array $context,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?string $pageSlug = null
    ): array {
        $caption = '';

        // ---
        // Extract caption from the first <figcaption> if a figure exists. A
        // [caption] shortcode carries no figure, so it keeps the empty caption
        // without a check of its own.
        // ---
        if (isset($context['figure']) && $context['figure'] instanceof DOMElement) {
            $captionNode = $context['figure']->getElementsByTagName('figcaption')->item(0);

            if ($captionNode !== null) {
                $caption = \trim($captionNode->textContent);
            }
        }

        $context['caption'] = $caption;

        return $context;
    }
}
