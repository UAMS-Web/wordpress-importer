<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits\DefaultTiptapHtmlTrait;

/**
 * Processes WordPress gravityform shortcodes into Statamic form Bard sets.
 *
 * Handles parsing of [gravityform ...] shortcode attributes into
 * Statamic-compatible form Bard set configurations for Bard fields. Outputs
 * import_wordpress_notes with the form ID or a note if no ID is specified,
 * aligning with block output. Tracks form IDs and their associated page slugs for
 * validation.
 */
class GravityFormsShortcodeProcessor implements ShortcodeProcessorInterface
{
    /**
     * Provides default implementation of toTiptapHtml method.
     *
     * This trait handles the standard conversion of shortcode data to
     * Tiptap-compatible HTML, including content processing and HTML
     * structure generation.
     */
    use DefaultTiptapHtmlTrait;

    /**
     * Use shared functionality for form shortcode processing.
     */
    use FormShortcodeProcessorTrait;

    /**
     * Parse a WordPress gravityform shortcode into a Statamic form Bard set.
     *
     * Maps the shortcode 'id' attribute to an import_wordpress_notes field
     * with format 'Gravity Forms form ID X' or 'Gravity Forms form ID not specified'.
     * Tracks form IDs and page slugs for validation.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML (unused)
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> The Statamic form Bard set configuration
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        return $this->parseFormShortcode(
            $attributes,
            $site,
            $assetImporter,
            $output,
            $terms,
            'Gravity Forms',
            $pageSlug
        );
    }

    /**
     * Build a page builder block for a gravityform shortcode.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing linked files (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML (unused)
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> The page builder block structure
     */
    public function toPageBuilderBlock(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        return $this->toFormPageBuilderBlock(
            $attributes,
            $site,
            $assetImporter,
            $output,
            $terms,
            'Gravity Forms',
            'uamsweb_block_gravityform',
            $pageSlug
        );
    }
}
