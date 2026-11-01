<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;

/**
 * Trait providing default implementation of toTiptapHtml method.
 *
 * This trait provides a standard implementation of the toTiptapHtml method
 * that most shortcode processors can use. It delegates to the parse method
 * and then uses the shared utility to generate Tiptap HTML.
 *
 * Processors that need custom behavior (like CaptionShortcodeProcessor)
 * can override this method in their own class.
 */
trait DefaultTiptapHtmlTrait
{
    /**
     * Convert a WordPress shortcode to Tiptap-compatible HTML.
     *
     * Default implementation that parses the shortcode and generates
     * Tiptap HTML using the shared utility method. Includes the shortcode
     * content in the HTML.
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
    ): string {
        // Set ProcessingContext for the shortcode processor
        $shortcodeContent = $shortcode['content'] ?? null;
        ProcessingContext::setShortcodeContext(
            $attachments,
            \is_string($shortcodeContent) ? $shortcodeContent : null
        );

        $attributes = $shortcode['attributes'] ?? [];

        if (! \is_array($attributes)) {
            $attributes = [];
        }

        // Parse the shortcode to get the set data
        $set = $this->parse(
            $attributes,
            $site,
            $assetImporter,
            $output,
            $terms,
            $pageSlug
        );

        if ($set === []) {
            // Return empty string if parsing failed
            return '';
        }

        // Extract values from the set
        $values = [];

        if (
            isset($set['attrs'])
            && \is_array($set['attrs'])
            && isset($set['attrs']['values'])
            && \is_array($set['attrs']['values'])
        ) {
            $values = $set['attrs']['values'];
        } elseif (\is_array($set)) {
            $values = $set;
        }

        // Determine set type from the values or use a default
        $setType = $values['type'] ?? 'unknown';
        $setType = \is_string($setType) ? $setType : 'unknown';

        $content = $shortcode['content'] ?? '';
        $content = \is_string($content) ? $content : '';

        // Use shared utility method to generate Tiptap HTML
        return ShortcodeProcessor::generateTiptapHtml(
            $values,
            $setType,
            $content
        );
    }
}
