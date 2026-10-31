<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;

/**
 * Processes WordPress [accordion] and [section] shortcodes.
 *
 * Parses [accordion] and [section] structures into arrays of section
 * data with titles and wpautop-processed content, which the audio post importer
 * reads transcripts from. In Bard content an accordion is flattened instead: each
 * section becomes its title as a heading followed by its content, because the
 * host app defines no accordion set.
 */
class AccordionShortcodeProcessor implements ShortcodeProcessorInterface
{
    /**
     * Matches a [section] opening tag, capturing a quoted title as `title` and an
     * unquoted one as `bare`.
     *
     * The title may come in any position among the attributes, or be missing. A
     * quoted title runs to the matching quote that is followed by the end of the
     * tag or by another attribute, so a quote of the other kind (a link's href in
     * a single-quoted title) or a decoded &quot; inside it does not end it early.
     * A title never runs across another [section] tag. An unquoted title is read
     * as ShortcodeProcessor reads an unquoted attribute value. The group is
     * atomic: once the tag has matched, a later failure cannot stretch the title
     * further.
     */
    private const string OPEN_TAG = '(?>\[section(?:\s+(?:title=(?:(?<quote>["\'])(?<title>(?:(?!\[\/?section\b).)*?)\k<quote>|(?<bare>[^\s\]"\']+))|[\w-]+=(?:"[^"]*"|\'[^\']*\'|[^\s\]"\']*)))*\s*\])';

    /**
     * Matches one [section]...[/section], capturing `title`, `bare` and `body`.
     */
    private const string SECTION_PATTERN = '/'.self::OPEN_TAG.'(?<body>(?:[^[]++|\[(?!\/section\]))*+)\[\/section\]/s';

    /**
     * Matches one line break, whitespace character or non-breaking space, the
     * residue wpautop and the editor leave around shortcode tags. The non-breaking
     * space is matched as an entity and as its UTF-8 bytes, since the patterns that
     * use this read bytes.
     */
    private const string BREAK_PATTERN = '(?:\s|&nbsp;|\xC2\xA0|<br\s*\/?>)';

    /**
     * Matches one ul, ol or li tag, capturing `close` and `tag`. HTML comments and
     * other tags are consumed and skipped, so a list tag in a comment or an
     * attribute value is not read as one.
     */
    private const string LIST_TAG_PATTERN = '/<!--(?:-?>|(?:[^-]++|-(?!->))*+(?:-->|\z))(*SKIP)(*FAIL)'
        .'|<(?!\/?(?:ul|ol|li)[\s\/>])\/?[a-z][^\s\/>]*(?:[^<>"\']++|"[^"]*"|\'[^\']*\')*+>(*SKIP)(*FAIL)'
        .'|<(?<close>\/?)(?<tag>ul|ol|li)(?=[\s\/>])(?:[^<>"\']++|"[^"]*"|\'[^\']*\')*+>/i';

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
            $title = self::sectionTitle($match);

            // Extract and clean the section content from the second capture group.
            // TextProcessor::processForBard() trims its input too, so this trim
            // does not change the result.
            // @pest-mutate-ignore: UnwrapTrim
            $sectionContent = \trim($match['body']);

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
     * Each section becomes an <h3> holding its title, followed by its content; a
     * section with an empty title keeps its content alone. Content that already
     * carries paragraphs is wrapped in one, and plain text is given paragraphs by
     * wpautop, as WordPress renders a shortcode's content. Text outside any
     * section is kept, so an accordion without sections keeps its content.
     * Shortcodes inside the sections are processed, since the outer extraction
     * consumed them along with the accordion. The output closes any open
     * paragraph first and opens one at the end, and text between sections gets a
     * paragraph of its own. A section's list tags are balanced within it, since
     * the section's own element scopes them in WordPress, so a list left open does
     * not take in the sections after it. A section with no title, or an unquoted one, is read
     * as ShortcodeProcessor reads attributes, and a [section] tag that still does
     * not convert is kept as text and reported (#167). When one
     * of this method's own patterns fails, the content is kept as it was and an
     * import warning names the page; an accordion the outer extraction fails to
     * match never reaches this method.
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
        // The opening tag is matched as SECTION_PATTERN matches it, so a "]" inside
        // a quoted title does not end it.
        // ---
        $break = self::BREAK_PATTERN;
        $trimmed = \preg_replace(
            [
                '/^'.$break.'+|'.$break.'+$/',
                '/'.$break.'+(?=\[\/?section\b)/',
                '/(?<=\[\/section\])'.$break.'+/',
                '/(?<tag>'.self::OPEN_TAG.')'.$break.'+/s',
            ],
            ['', '', '', '${1}'],
            $content
        );

        // Replace each section with its title as a heading followed by its body
        $flattened = \is_string($trimmed)
            ? \preg_replace_callback(
                self::SECTION_PATTERN,
                static function (array $match) use ($site, $pageSlug): string {
                    // The stripper decodes entities and trims, so the title is plain text
                    $title = self::sectionTitle($match);

                    $heading = $title === ''
                        ? ''
                        : '<h3>'.\htmlspecialchars($title).'</h3>';

                    // Keep the body's lists inside the section
                    $sectionBody = self::balanceLists($match['body'], $site, $pageSlug);

                    // A body wpautop already split is wrapped; plain text gets paragraphs
                    $body = \preg_match('/<\/?p[\s>]/i', $sectionBody) === 1
                        ? '<p>'.$sectionBody.'</p>'
                        : (new WpAutoP)->process($sectionBody);

                    // Close the paragraph around the section and open one after it
                    return '</p>'.$heading.$body.'<p>';
                },
                $trimmed
            )
            : null;

        // Keep the content as it was when a pattern failed, and say so
        if ($flattened === null) {
            ImportWarningManager::addWarning(
                'content',
                'unflattened_accordions',
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                    'error' => \preg_last_error_msg(),
                    'content_snippet' => \substr($content, 0, 100),
                    'page_url' => LogContextHelper::enhanceWithEntryContext([])['page_url'],
                ]
            );

            $flattened = $content;
        } else {
            // ---
            // An accordion in Classic content sits inside a wpautop paragraph. Close
            // that paragraph first and open one at the end, and put any text between
            // sections in a paragraph of its own, so no text lands at the top level of
            // the document. A leading paragraph is opened only when text comes before
            // the first section, so a heading stays the first node where a caller
            // keeps only that (a wp:shortcode block, #156). Where no paragraph is open
            // the parser drops the stray close, and the top-level empty paragraphs
            // this leaves are filtered out. ShortcodeProcessor::replaceShortcode()
            // takes the whitespace and line breaks wpautop left on either side, so
            // they cannot become blank-line paragraphs.
            // ---
            $flattened = (\str_starts_with($flattened, '</p>') ? $flattened : '</p><p>'.$flattened).'</p><p>';

            // Report a [section] tag no section matched, which stays as text (#167)
            self::reportUnconvertedSection(
                $flattened,
                $site,
                $pageSlug
            );
        }

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

    /**
     * The plain-text title of a matched section, or an empty string when it has none.
     *
     * @param  array<int|string, string>  $match  A SECTION_PATTERN match
     * @return string The title with its tags stripped and entities decoded
     */
    private static function sectionTitle(
        array $match
    ): string {
        // A quoted title is captured as `title`, an unquoted one as `bare`. PCRE
        // sets both whenever the pattern matches, since `body` follows them
        $title = $match['title'] !== ''
            ? $match['title']
            : $match['bare'];

        return HtmlTagStripper::strip($title);
    }

    /**
     * Closes the list tags a section body leaves open and drops the ones it closes
     * without opening.
     *
     * An unclosed list would otherwise take in the headings of the sections after
     * it, and a stray close would end a list around the accordion. Text inside an
     * HTML comment or an attribute value is left alone, since it opens and closes
     * nothing. The closing tags go at the end of the body, so a comment the body
     * never ends takes them in. When the pattern fails the body is kept as it was
     * and an import warning names the page.
     *
     * @param  string  $body  The section body
     * @param  string  $site  The site handle, for the warning a failed pattern records
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return string The body with its ul, ol and li tags balanced
     */
    private static function balanceLists(
        string $body,
        string $site,
        ?string $pageSlug
    ): string {
        // The list tags open at this point in the body, innermost last
        $open = [];

        $balanced = \preg_replace_callback(
            self::LIST_TAG_PATTERN,
            static function (array $match) use (&$open): string {
                $tag = \strtolower($match['tag']);

                // An opening tag: a new item ends the item before it
                if ($match['close'] === '') {
                    $closing = $tag === 'li' && \end($open) === 'li' ? \array_pop($open) : null;
                    $open[] = $tag;

                    return ($closing === null ? '' : '</li>').$match[0];
                }

                // A close with nothing to close is dropped
                if (! \in_array($tag, $open, true)) {
                    return '';
                }

                // Close what is open inside the tag, then the tag
                $closing = '';

                while (($last = \array_pop($open)) !== $tag) {
                    $closing .= '</'.$last.'>';
                }

                return $closing.$match[0];
            },
            $body
        );

        // A failed pattern keeps the body as it was, and says so
        if ($balanced === null) {
            ImportWarningManager::addWarning(
                'content',
                'unbalanced_accordion_sections',
                [
                    'site' => $site,
                    'page_slug' => $pageSlug,
                    'error' => \preg_last_error_msg(),
                    'content_snippet' => \substr($body, 0, 100),
                    'page_url' => LogContextHelper::enhanceWithEntryContext([])['page_url'],
                ]
            );

            return $body;
        }

        // Close what is still open, innermost first
        return $balanced.\implode(
            '',
            \array_map(
                static fn (string $tag): string => '</'.$tag.'>',
                \array_reverse($open)
            )
        );
    }

    /**
     * Records a [section] tag left in flattened accordion content.
     *
     * A tag no section matched (an opening tag with no closing one, or one whose
     * attributes are not well formed) stays in the imported text, so the report
     * names the site, the page and the text from that tag on.
     *
     * @param  string  $flattened  The flattened accordion content
     * @param  string  $site  The site handle for context
     * @param  ?string  $pageSlug  The slug of the page being processed
     */
    private static function reportUnconvertedSection(
        string $flattened,
        string $site,
        ?string $pageSlug
    ): void {
        // Find the first [section] or [/section] tag left in the content
        if (\preg_match('/\[\/?section\b/', $flattened, $tag, PREG_OFFSET_CAPTURE) !== 1) {
            return;
        }

        ImportWarningManager::addWarning(
            'content',
            'unconverted_accordion_sections',
            [
                'site' => $site,
                'page_slug' => $pageSlug ?? '',
                'page_link' => ProcessingContext::getWordPressPageLink() ?? '',
                'content_snippet' => \mb_strcut($flattened, $tag[0][1], 100),
                'reason' => 'A [section] tag in this [accordion] was not converted, '
                    .'so it was kept in the content as text.',
            ]
        );
    }
}
