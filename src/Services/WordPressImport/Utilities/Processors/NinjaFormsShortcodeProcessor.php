<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits\DefaultTiptapHtmlTrait;

/**
 * Processes WordPress ninja_form shortcodes into Statamic form Bard sets.
 *
 * Handles parsing of [ninja_form id=N] shortcode attributes into
 * Statamic-compatible form Bard set configurations for Bard fields. Outputs
 * import_wordpress_notes with the form ID as a string, aligning with block output
 * and the Gravity Forms / Formidable Forms shortcode output. Tracks form IDs for
 * potential validation.
 */
class NinjaFormsShortcodeProcessor implements ShortcodeProcessorInterface
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
     * Parse a WordPress ninja_form shortcode into a Statamic form Bard set.
     *
     * Maps the shortcode 'id' attribute to an import_wordpress_notes field with
     * format 'Ninja Forms form ID X' or 'Ninja Forms form ID not specified'.
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
            'Ninja Forms',
            $pageSlug
        );
    }
}
