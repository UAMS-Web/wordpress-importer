<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor;

use DOMElement;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Utilities\Alignment;

/**
 * Extracts and translates image alignment for Statamic.
 *
 * Maps WordPress alignment classes or attributes to Statamic-compatible align
 * values, prioritizing block attributes. Supports 'left', 'right', 'center',
 * 'wide', and 'full' alignments, mapping to 'alignleft', 'alignright',
 * 'aligncenter', or 'alignwide'.
 */
class AlignmentExtractor implements ImageProcessingStepInterface
{
    /**
     * Extracts alignment from block attributes, figure classes, or image classes.
     *
     * Prioritizes blockAttributes['align'] (e.g., from wp:image blocks), falling
     * back to <figure> classes, then <img> classes. Uses Alignment::translate to
     * map WordPress values to Statamic's (e.g., 'right' to 'alignright'). Returns
     * the context with the determined 'align' value.
     *
     * @param  array<int|string, mixed>  $context  Processing context with 'img', 'figure', 'blockAttributes'
     * @param  string  $site  Statamic site handle (e.g., 'biostatistics')
     * @param  AssetImporter  $assetImporter  Instance for importing images
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of SimpleXMLElement attachment objects
     * @param  ?string  $pageSlug  Page slug for logging (e.g., 'paula-roberson')
     * @return array<int|string, mixed> Updated context with 'align' key set to the determined value
     */
    public function process(
        array $context,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        ?string $pageSlug = null
    ): array {
        // Initialize default alignment as 'aligncenter' for Statamic compatibility
        $align = 'aligncenter';

        // Priority 1: Check blockAttributes['align'] from wp:image block comment
        $blockAttributes = $context['blockAttributes'] ?? [];
        $blockAlign = \is_array($blockAttributes)
            ? ($blockAttributes['align'] ?? null)
            : null;

        if (
            \is_string($blockAlign)
            && $blockAlign !== ''
        ) {
            // Translate WordPress block alignment (e.g., 'right') to Statamic format
            $align = Alignment::translate(
                $blockAlign,
                true
            );
        } elseif (
            isset($context['figure'])
            && $context['figure'] instanceof DOMElement
            && $context['figure']->hasAttribute('class')
        ) {
            // Extract class attribute from <figure> (e.g., 'alignright size-large')
            // Translate to Statamic alignment using class-based logic
            $align = Alignment::translate(
                $context['figure']->getAttribute('class'),
                true
            );
        } elseif (
            isset($context['img'])
            && $context['img'] instanceof DOMElement
            && $context['img']->hasAttribute('class')
        ) {
            // Extract class attribute from <img> (e.g., 'wp-image-1953')
            // Translate to Statamic alignment, typically defaults to 'aligncenter'
            $align = Alignment::translate(
                $context['img']->getAttribute('class'),
                true
            );
        }

        // Add the determined alignment to the context array
        $context['align'] = $align;

        // Return the updated context for further processing in the pipeline
        return $context;
    }
}
