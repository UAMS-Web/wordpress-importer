<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Processes WordPress [accordion] and [section] shortcodes.
 *
 * Parses [accordion] and [section title="..."] structures into arrays of section
 * data with titles and wpautop-processed content, which the audio post importer
 * reads transcripts from. In Bard content an accordion is flattened instead: each
 * section becomes its title as a heading followed by its content, because the
 * host app defines no accordion set.
 */
class AccordionShortcodeProcessor implements ShortcodeProcessorInterface
{
    /**
     * Matches one [section title="..."]...[/section], capturing the title and body.
     */
    private const string SECTION_PATTERN = '/\[section\s+title=["\']([^"\']*)["\'][^]]*\]((?:[^[]|\[(?!\/section\]))*?)\[\/section\]/s';

    /**
     * Matches one line break or whitespace character, the residue wpautop leaves
     * around shortcode tags.
     */
    private const string BREAK_PATTERN = '(?:\s|<br\s*\/?>)';

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

        // Extract all section matches from the accordion content
        preg_match_all(
            self::SECTION_PATTERN,
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

    /**
     * Flatten a WordPress [accordion] shortcode into Bard-ready HTML.
     *
     * Each section becomes an <h3> holding its title, followed by its content in
     * a paragraph; a section with an empty title keeps its content alone. Text
     * outside any section is kept, so an accordion without sections keeps its
     * content. Shortcodes inside the sections are processed, since the outer
     * extraction consumed them along with the accordion.
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
        // Read the accordion's inner content
        $content = \is_string($shortcode['content'] ?? null)
            ? $shortcode['content']
            : '';

        // ---
        // Drop the line breaks wpautop leaves at the edges, between sections and at
        // the ends of each section body, so none is left standing outside or at the
        // edge of a paragraph once the sections are replaced.
        // A failed replacement keeps the content as it was rather than losing it.
        // ---
        $break = self::BREAK_PATTERN;
        $content = \preg_replace(
            [
                '/^'.$break.'+|'.$break.'+$/',
                '/'.$break.'+(?=\[\/?section\b)/',
                '/(?<=\[\/section\])'.$break.'+/',
                '/(\[section\b[^\]]*\])'.$break.'+/',
            ],
            ['', '', '', '$1'],
            $content
        ) ?? $content;

        // Replace each section with its title as a heading and its body as a paragraph
        $flattened = \preg_replace_callback(
            self::SECTION_PATTERN,
            static function (array $match): string {
                // The stripper decodes entities and trims, so the title is plain text
                $title = HtmlTagStripper::strip($match[1]);

                $heading = $title === ''
                    ? ''
                    : '<h3>'.\htmlspecialchars($title).'</h3>';

                return $heading.'<p>'.$match[2].'</p>';
            },
            $content
        ) ?? $content;

        // Process the shortcodes inside the sections
        return (new ShortcodeProcessor)->processShortcodesInContent(
            $flattened,
            $site,
            $assetImporter,
            $output,
            $attachments,
            $terms,
            [],
            $pageSlug
        );
    }
}
