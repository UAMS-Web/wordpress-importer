<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;

/**
 * Interface for WordPress shortcode processors.
 *
 * Defines the standard method signature for all shortcode processors to ensure
 * consistency and enable the main ShortcodeProcessor to handle all shortcodes
 * uniformly. Uses ProcessingContext for accessing additional parameters like
 * attachments, page ID, shortcode content, and audio-specific data.
 */
interface ShortcodeProcessorInterface
{
    /**
     * Parse a WordPress shortcode into Statamic data.
     *
     * Processes shortcode attributes and returns data suitable for Bard sets
     * or page builder blocks. All additional context data (attachments, page ID,
     * shortcode content, audio-specific parameters) is accessed through
     * ProcessingContext to maintain a consistent method signature.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed>|null The parsed shortcode data or null on failure
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): ?array;

    /**
     * Convert a WordPress shortcode to Tiptap-compatible HTML.
     *
     * Converts shortcode data into HTML that can be parsed by Tiptap/Bard.
     * This method allows shortcode processors to customize how their content
     * is converted to HTML, enabling proper handling of content structure
     * and avoiding issues like duplicate content in image sets.
     *
     * @param  array<int|string, mixed>  $shortcode  Shortcode data (['name' => '...', 'attributes' => [...], 'content' => '...'])
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $attachments  Array of attachment objects for image processing
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return string Tiptap-compatible HTML
     */
    public function toTiptapHtml(
        array $shortcode,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): string;
}
