<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits\DefaultTiptapHtmlTrait;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Processes WordPress [accordion] and [section] shortcodes into section data.
 *
 * Handles parsing of [accordion] and [section title="..."] shortcode structures
 * into arrays of section data with titles and content. Processes section content
 * with wpautop formatting for proper paragraph handling.
 */
class AccordionShortcodeProcessor implements ShortcodeProcessorInterface
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
     * Parse a WordPress [accordion] shortcode and return an array of sections.
     *
     * Processes the inner content to extract [section] elements and returns
     * an array of section data with titles and content. Shortcode content is
     * accessed through ProcessingContext.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing files (unused)
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML (unused)
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> Array of section data with title and content
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        // Get shortcode content from ProcessingContext. Any fallback without a
        // [section] in it yields no sections, so the empty string is not observable.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        $content = ProcessingContext::getShortcodeContent() ?? '';

        // Initialize array to store parsed section data
        $sections = [];

        // Match [section title="..."]...[/section] patterns
        $sectionPattern = '/\[section\s+title=["\']([^"\']*)["\'][^]]*\]((?:[^[]|\[(?!\/section\]))*?)\[\/section\]/s';

        // Extract all section matches from the accordion content
        preg_match_all(
            $sectionPattern,
            $content,
            $matches,
            PREG_SET_ORDER
        );

        // Process each matched section to extract title and content
        foreach ($matches as $match) {
            // Extract and strip HTML tags from the section title
            $title = HtmlTagStripper::strip($match[1]);

            // Extract and clean the section content from the second capture group.
            // TextProcessor::processForBard() trims its input too, so this trim
            // does not change the result.
            // @pest-mutate-ignore: UnwrapTrim
            $sectionContent = \trim($match[2]);

            // Process section content with wpautop using TextProcessor directly
            $processedContent = TextProcessor::processForBard(
                $sectionContent,
                $site,
                $assetImporter,
                $output,
                true, // Apply wpautop formatting for section content
                false,
                [], // No attachments for section content
                $terms,
                $pageSlug
            );

            // Add the processed section to the sections array
            $sections[] = [
                'title' => $title,
                'content' => $processedContent,
            ];
        }

        // Return the array of processed sections with type for Tiptap data-set-type
        return [
            'type' => 'accordion',
            ...$sections,
        ];
    }

    /**
     * Find and parse accordion content from a larger segment.
     *
     * Searches for [accordion]...[/accordion] content within the provided segment
     * and processes it to extract sections.
     *
     * @param  string  $segment  The segment content that may contain accordion shortcode
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> Array of section data with title and content
     */
    public function parseFromSegment(
        string $segment,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $pageSlug = null
    ): array {
        // Look for [accordion]...[/accordion] content in the segment
        if (
            \preg_match(
                '/\[accordion\](.*?)\[\/accordion\]/s',
                $segment,
                $matches
            ) === 1
        ) {
            // Extract and clean the accordion content from the first capture group
            $accordionContent = \trim($matches[1]);

            // Process the accordion content if it's not empty
            if ($accordionContent !== '') {
                // Set ProcessingContext for the shortcode processor
                ProcessingContext::setShortcodeContext(
                    [],
                    $accordionContent
                );

                // Use the existing parse method to process the accordion content
                return $this->parse(
                    [],
                    $site,
                    $assetImporter,
                    $output,
                    [],
                    $pageSlug
                );
            }
        }

        // Return empty array if no accordion content is found
        return [];
    }
}
