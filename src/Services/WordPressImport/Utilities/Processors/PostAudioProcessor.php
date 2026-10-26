<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Illuminate\Support\Facades\Log;
use Statamic\Fieldtypes\RowId;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;

/**
 * Processes WordPress audio format content for post collection entries.
 *
 * Handles the specialized processing of audio format posts, including:
 *   - Parsing content structured with <h3> headings and shortcodes
 *   - Extracting audio segments from <h3> to [/accordion] boundaries
 *   - Processing audio files and transcripts from accordion content
 *   - Extracting intro text between <a> and [accordion] tags in each segment
 *   - Creating segments and intro fields for the audio blueprint
 *   - Saving shortcode-specific title/series/excerpt/transcript to asset metadata
 *   - Removing <img> elements from all content before processing to prevent
 *     image elements from being imported into Statamic
 */
class PostAudioProcessor
{
    /**
     * Cached processor instances for reuse across method calls.
     */
    private ?ShortcodeProcessor $shortcodeProcessor = null; // Lazy-loaded instance for extracting shortcodes from content

    private ?AudioShortcodeProcessor $audioProcessor = null; // Lazy-loaded instance for processing audio shortcodes

    private ?AccordionShortcodeProcessor $accordionProcessor = null; // Lazy-loaded instance for processing accordion content

    /**
     * Get or create ShortcodeProcessor instance.
     */
    private function getShortcodeProcessor(): ShortcodeProcessor
    {
        $this->shortcodeProcessor ??= new ShortcodeProcessor;

        // Return the cached instance for reuse across multiple method calls
        return $this->shortcodeProcessor;
    }

    /**
     * Get or create AudioShortcodeProcessor instance.
     */
    private function getAudioProcessor(): AudioShortcodeProcessor
    {
        $this->audioProcessor ??= new AudioShortcodeProcessor;

        // Return the cached instance for reuse across multiple method calls
        return $this->audioProcessor;
    }

    /**
     * Get or create AccordionShortcodeProcessor instance.
     */
    private function getAccordionProcessor(): AccordionShortcodeProcessor
    {
        $this->accordionProcessor ??= new AccordionShortcodeProcessor;

        // Return the cached instance for reuse across multiple method calls
        return $this->accordionProcessor;
    }

    /**
     * Remove all <img> elements from content before importing to Statamic.
     *
     * Strips <img> tags and their attributes from the provided content to prevent
     * image elements from being imported into Statamic. This ensures clean content
     * without embedded images that may not be properly handled during the import
     * process.
     *
     * @param  string  $content  The content from which to remove <img> elements
     * @return string The content with all <img> elements removed
     */
    private function removeImageElements(
        string $content
    ): string {
        // Remove all <img> tags and their attributes using regex
        // This pattern matches <img> tags with any attributes and handles both
        // self-closing and non-self-closing formats
        $cleanedContent = \preg_replace(
            '/<img[^>]*\/?>/i',
            '',
            $content
        );

        // Return the cleaned content, or original content if regex fails
        return $cleanedContent ?? $content;
    }

    /**
     * Process audio format content for post entries into audio segments and intro.
     *
     * Parses content to extract segments beginning with <h3> and ending with
     * [/accordion]. Each segment is mapped to a segments set item with title, file,
     * and transcript fields. Shortcode-specific values are saved to imported audio
     * asset metadata. Content before the first segment and after the last segment is
     * combined into the intro field.
     *
     * Also handles single audio segment format where there are no <h3> tags, just
     * [audio] shortcode onward. In this case, the post title is used as the segment
     * title and content before [audio] is mapped to intro.
     *
     * Removes all <img> elements from the content before processing to prevent image
     * elements from being imported into Statamic.
     *
     * @param  string  $content  The raw WordPress content
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing audio files
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @param  ?string  $postTitle  The title of the post (used for single segment format)
     * @param  ?AudioSeriesConfig  $audioSeriesConfig  The audio series configuration
     * @param  ?string  $postDate  The post date for filename generation
     * @param  ?string  $postId  The post ID for filename generation
     * @return array<int|string, mixed> Array with 'segments' and 'intro' fields
     */
    public function processAudioFormatContent(
        string $content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $pageSlug = null,
        ?string $postTitle = null,
        ?AudioSeriesConfig $audioSeriesConfig = null,
        ?string $postDate = null,
        ?string $postId = null
    ): array {
        // Remove <img> elements from content before processing
        $content = $this->removeImageElements($content);

        // Check if this is a single segment format (no <h3> tags, just [audio] shortcode)
        $isSingleSegmentFormat = $this->isSingleSegmentFormat($content);

        // Handle single segment format differently from multi-segment format
        if ($isSingleSegmentFormat) {
            // Handle single segment format
            return $this->processSingleSegmentFormat(
                $content,
                $site,
                $assetImporter,
                $output,
                $pageSlug,
                $postTitle,
                $audioSeriesConfig,
                $postDate,
                $postId
            );
        }

        // Handle multi-segment format (existing logic)
        // Split content into segments
        $segments = $this->extractAudioSegments($content);

        // Split content into intro
        $intro = $this->extractIntro($content);

        // Process each segment
        // Initialize array to store processed audio segments
        $audioSegments = [];

        // Process each extracted segment to create audio segment data
        foreach ($segments as $segment) {
            // Process the individual segment to extract title, file, and transcript
            $processedSegment = $this->processAudioSegment(
                $segment,
                $site,
                $assetImporter,
                $output,
                $pageSlug,
                $audioSeriesConfig,
                $postDate,
                $postId,
                $postTitle
            );

            // Add the processed segment to the array if it was successfully processed
            if ($processedSegment !== null && $processedSegment !== []) {
                $audioSegments[] = $processedSegment;
            }
        }

        // Process intro content
        $processedIntro = $this->processIntroContent(
            $intro,
            $site,
            $assetImporter,
            $output,
            $pageSlug
        );

        // Return the processed audio format content with segments and intro
        return [
            'segments' => $audioSegments,
            'intro' => $processedIntro,
        ];
    }

    /**
     * Extract audio segments from content.
     *
     * Finds segments that begin with <h3> and end with [/accordion] or the next <h3>
     * tag. Handles both accordion-based segments and simple segments without
     * accordion tags.
     *
     * @param  string  $content  The raw content
     * @return list<string> Array of segment content strings
     */
    private function extractAudioSegments(
        string $content
    ): array {
        // Initialize array to store extracted segment content
        $segments = [];

        // First, try to extract segments that end with [/accordion] (accordion-based format)
        $accordionPattern = '/<h3[^>]*>(.*?)<\/h3>(.*?)\[\/accordion\]/s';
        preg_match_all(
            $accordionPattern,
            $content,
            $accordionMatches,
            PREG_SET_ORDER
        );

        // If accordion-based segments were found, use them
        if ($accordionMatches !== []) {
            foreach ($accordionMatches as $match) {
                $segments[] = $match[0]; // Full segment including <h3> and [/accordion]
            }
        } else { // If no accordion-based segments found, try to extract segments between <h3> tags
            // Find all <h3> tag positions
            preg_match_all(
                '/<h3[^>]*>/',
                $content,
                $h3Matches,
                PREG_OFFSET_CAPTURE
            );

            if (($h3Matches[0]) !== []) {
                $h3Positions = $h3Matches[0];
                $counter = \count($h3Positions);

                // Extract segments between consecutive <h3> tags
                for ($i = 0; $i < $counter; $i++) {
                    $startPos = $h3Positions[$i][1];

                    // Determine end position
                    if ($i + 1 < \count($h3Positions)) {
                        // Next <h3> tag exists, segment ends before it
                        $endPos = $h3Positions[$i + 1][1];
                    } else { // Otherwise
                        // Last segment, goes to end of content
                        $endPos = \strlen($content);
                    }

                    // Extract the segment content
                    $segmentContent = \substr(
                        $content,
                        $startPos,
                        $endPos - $startPos
                    );
                    $segments[] = $segmentContent;
                }
            }
        }

        // Clean up variables to free up memory
        unset(
            $accordionPattern,
            $accordionMatches,
            $h3Matches,
            $h3Positions,
            $startPos,
            $endPos,
            $segmentContent
        );

        // Return the array of extracted segment content
        return $segments;
    }

    /**
     * Extract intro content (everything before the first segment and after the last
     * segment).
     *
     * Removes any <h2> element that immediately precedes the first <h3> element.
     *
     * @param  string  $content  The raw content
     * @return string The combined intro content
     */
    private function extractIntro(
        string $content
    ): string {
        // Find the first <h3> tag
        $firstH3Pos = \strpos(
            $content,
            '<h3'
        );

        // Return entire content as intro if no segments are found
        if ($firstH3Pos === false) {
            return $content; // No segments found, entire content is intro
        }

        // Extract content before the first segment
        $beforeFirst = \trim(
            \substr(
                $content,
                0,
                $firstH3Pos
            )
        );

        // Remove any <h2> element that immediately precedes the first <h3> element
        $beforeFirst = $this->removeH2BeforeH3($beforeFirst);

        // Find the last [/accordion] tag
        $lastAccordionPos = strrpos(
            $content,
            '[/accordion]'
        );

        // Extract content after the last segment
        $afterLast = '';
        if ($lastAccordionPos !== false) {
            // Content after the last accordion
            $afterLast = \trim(
                \substr(
                    $content,
                    $lastAccordionPos + \strlen('[/accordion]')
                )
            );
        } else { // Otherwise
            // No accordion tags found, look for content after the last download link
            $lastDownloadLinkPos = strripos(
                $content,
                'Download this episode</a>'
            );

            if ($lastDownloadLinkPos !== false) {
                // Extract content after the last download link
                $afterLast = \trim(
                    \substr(
                        $content,
                        $lastDownloadLinkPos + \strlen('Download this episode</a>')
                    )
                );
            }
        }

        // Combine content before first segment and after last segment
        $introParts = \array_filter(
            [$beforeFirst, $afterLast],
            fn (string $part): bool => \trim($part) !== ''
        );

        // Return the combined intro content
        return \implode(
            "\n\n",
            $introParts
        );
    }

    /**
     * Remove any <h2> element that immediately precedes the first <h3> element.
     *
     * This method looks for <h2> tags and removes them if they appear to be
     * immediately before the first <h3> element, including cases where they
     * are separated by &nbsp; and/or empty lines.
     *
     * @param  string  $content  The content to process
     * @return string The content with <h2> elements removed if they precede <h3>
     */
    private function removeH2BeforeH3(
        string $content
    ): string {
        // First, try to remove <h2> tags that are immediately followed by <h3> tags
        // This pattern matches <h2> tags (with optional attributes) and their content,
        // followed by optional whitespace, &nbsp;, and/or empty lines, then <h3>
        $pattern = '/<h2[^>]*>.*?<\/h2>(\s*&nbsp;\s*|\s*)*<h3/s';

        // Remove any <h2> tags that match the pattern, keeping the <h3> tag
        $cleanedContent = \preg_replace(
            $pattern,
            '<h3',
            $content
        );

        // If no matches were found with the first pattern, check if the content
        // ends with an <h2> tag (which would be followed by <h3> in the original content)
        if ($cleanedContent === $content) {
            // Pattern to match <h2> tags at the end of the content, including any trailing whitespace, &nbsp;, or newlines
            $trailingH2Pattern = '/<h2[^>]*>.*?<\/h2>(\s*&nbsp;\s*|\s*)*$/s';
            $cleanedContent = \preg_replace(
                $trailingH2Pattern,
                '',
                $content
            );
        }

        // Return the cleaned content, trimmed of any remaining whitespace
        $cleanedString = \is_string($cleanedContent) ? $cleanedContent : $content;
        $result = \trim($cleanedString);

        // Clean up variables to free up memory
        unset(
            $pattern,
            $cleanedContent,
            $trailingH2Pattern
        );

        return $result;
    }

    /**
     * Process a single audio segment.
     *
     * Extracts title, audio file, transcript content, and intro text from a segment.
     *
     * @param  string  $segment  The segment content
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output
     * @param  ?string  $pageSlug  The page slug
     * @param  ?AudioSeriesConfig  $audioSeriesConfig  The audio series configuration
     * @param  ?string  $postDate  The post date for filename generation
     * @param  ?string  $postId  The post ID for filename generation
     * @param  ?string  $postTitle  The post title for filename generation
     * @return array<int|string, mixed>|null The processed segment or null if invalid
     */
    private function processAudioSegment(
        string $segment,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $pageSlug,
        ?AudioSeriesConfig $audioSeriesConfig = null,
        ?string $postDate = null,
        ?string $postId = null,
        ?string $postTitle = null
    ): ?array {
        // Extract title from <h3> tag
        $title = $this->extractTitle($segment);

        // Validate that a title was found before proceeding
        if ($title === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'site' => $site,
                    'page_slug' => $pageSlug ?? 'none',
                ]
            );

            // Log warning and return null for segments without titles
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'PostAudioProcessor::processAudioSegment: No title found in segment',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Return null to indicate this segment could not be processed
            return null;
        }

        // Extract transcript from [section title="Transcript"]...[/section]
        $transcript = $this->extractTranscript(
            $segment,
            $site,
            $assetImporter,
            $output,
            $pageSlug
        );

        // Extract intro text between <a> and [accordion] tags
        $intro = $this->extractSegmentIntro(
            $segment,
            $site,
            $assetImporter,
            $output,
            $pageSlug
        );

        // Extract audio file URL from [audio] shortcode
        $audioFile = $this->extractAudioFile(
            $segment,
            $site,
            $assetImporter,
            $output,
            $pageSlug,
            $audioSeriesConfig,
            $postDate,
            $postId,
            $postTitle,
            $title,
            $intro,
            $transcript
        );

        // Build the audio segment structure with file-specific playback data
        $audioSegment = [
            'id' => (new RowId)->generate(),
            'file' => $audioFile,
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ];

        // Return the complete audio segment structure
        return $audioSegment;
    }

    /**
     * Extract title from <h3> tag.
     *
     * @param  string  $segment  The segment content
     * @return string The extracted title
     */
    private function extractTitle(
        string $segment
    ): string {
        // Extract title text from <h3> tag and clean it
        if (
            \preg_match(
                '/<h3[^>]*>(.*?)<\/h3>/s',
                $segment,
                $matches
            ) === 1
        ) {
            // Strip HTML tags from the extracted title
            return HtmlTagStripper::strip($matches[1]);
        }

        // Return empty string if no title is found
        return '';
    }

    /**
     * Extract and import audio file from [audio] shortcode using
     * AudioShortcodeProcessor.
     *
     * @param  string  $segment  The segment content
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output
     * @param  ?string  $pageSlug  The page slug
     * @param  ?AudioSeriesConfig  $audioSeriesConfig  The audio series configuration
     * @param  ?string  $postDate  The post date for filename generation
     * @param  ?string  $postId  The post ID for filename generation
     * @param  ?string  $postTitle  The post title for filename generation
     * @param  ?string  $segmentTitle  The segment title for the filename infix (pass null for single-segment imports so the post slug is not repeated)
     * @param  array<int|string, mixed>  $excerpt  The shortcode excerpt content for audio asset metadata
     * @param  array<int|string, mixed>  $transcript  The shortcode transcript content for audio asset metadata
     * @param  ?string  $assetTitle  Title stored on the audio asset metadata; defaults to $segmentTitle when omitted
     * @return ?string The imported file path or null
     */
    private function extractAudioFile(
        string $segment,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $pageSlug,
        ?AudioSeriesConfig $audioSeriesConfig = null,
        ?string $postDate = null,
        ?string $postId = null,
        ?string $postTitle = null,
        ?string $segmentTitle = null,
        array $excerpt = [],
        array $transcript = [],
        ?string $assetTitle = null
    ): ?string {
        // Use ShortcodeProcessor to extract audio shortcodes from the segment
        $shortcodes = $this->getShortcodeProcessor()->extract(
            $segment,
            $output
        );

        // Look for the first audio shortcode and process it immediately
        foreach ($shortcodes as $shortcode) {
            if (
                ! \is_array($shortcode)
                || ($shortcode['name'] ?? null) !== 'audio'
            ) {
                continue;
            }

            $attributes = $shortcode['attributes'] ?? null;

            if (! \is_array($attributes)) {
                continue;
            }

            // Set audio-specific context data for AudioShortcodeProcessor
            ProcessingContext::setAudioSeriesConfig($audioSeriesConfig);
            ProcessingContext::setWordPressPostDate($postDate);
            ProcessingContext::setWordPressPostId($postId);
            ProcessingContext::setWordPressPostTitle($postTitle);
            ProcessingContext::setSegmentTitle($segmentTitle);
            ProcessingContext::setAudioAssetTitle($assetTitle ?? $segmentTitle);
            ProcessingContext::setAudioAssetSeries(
                $audioSeriesConfig?->getSeriesFieldValue()
            );
            ProcessingContext::setAudioAssetExcerpt(
                $this->normalizeBardNodeList($excerpt)
            );
            ProcessingContext::setAudioAssetTranscript(
                $this->normalizeBardNodeList($transcript)
            );

            // Use AudioShortcodeProcessor to process the audio shortcode
            $audioData = $this->getAudioProcessor()->parse(
                $attributes,
                $site,
                $assetImporter,
                $output,
                [], // No terms needed
                $pageSlug
            );

            // Clear audio-specific context data after processing
            ProcessingContext::clearAudioSeriesConfig();
            ProcessingContext::clearWordPressPostDate();
            ProcessingContext::clearWordPressPostId();
            ProcessingContext::clearWordPressPostTitle();
            ProcessingContext::clearSegmentTitle();
            ProcessingContext::clearAudioAssetTitle();
            ProcessingContext::clearAudioAssetSeries();
            ProcessingContext::clearAudioAssetExcerpt();
            ProcessingContext::clearAudioAssetTranscript();

            $filePath = $audioData['file'] ?? null;

            // Return the imported file path immediately after processing
            return \is_string($filePath) ? $filePath : null;
        }

        // Clean up variables to free up memory
        unset(
            $shortcodes
        );

        // Return null if no audio shortcode was found in the segment
        return null;
    }

    /**
     * Extract intro text between <a> and [accordion] tags in a segment.
     *
     * @param  string  $segment  The segment content
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> The processed intro content
     */
    private function extractSegmentIntro(
        string $segment,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $pageSlug
    ): array {
        // Remove <img> elements from segment before processing
        $segment = $this->removeImageElements($segment);

        // Find the position of the "Download this episode</a>" tag before [accordion]
        // Use case-insensitive search to handle variations like "Download This Episode"
        $downloadLinkPattern = 'Download this episode</a>';
        $lastATagPos = strripos(
            $segment,
            $downloadLinkPattern
        );
        $accordionPos = \strpos(
            $segment,
            '[accordion]'
        );

        // Return empty array if either tag is not found
        if (
            $lastATagPos === false
            || $accordionPos === false
        ) {
            return [];
        }

        // Extract text between "Download this episode</a>" and [accordion]
        $downloadLinkLength = \strlen($downloadLinkPattern);
        $introText = \trim(
            \substr(
                $segment,
                $lastATagPos + $downloadLinkLength,
                $accordionPos - $lastATagPos - $downloadLinkLength
            )
        );

        // Return empty array if no intro text is found
        if ($introText === '') {
            return [];
        }

        // Process the intro text using TextProcessor with wpautop enabled
        return TextProcessor::processForBard(
            $introText,
            $site,
            $assetImporter,
            $output,
            true, // Apply wpautop formatting for intro content
            false,
            [], // No attachments for intro content
            [], // Empty terms array for audio intro processing
            $pageSlug
        );
    }

    /**
     * Extract transcript from [section title="Transcript"] within [accordion] using
     * AccordionShortcodeProcessor.
     *
     * @param  string  $segment  The segment content
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> The processed transcript content
     */
    private function extractTranscript(
        string $segment,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $pageSlug
    ): array {
        // Remove <img> elements from segment before processing
        $segment = $this->removeImageElements($segment);

        // Use AccordionShortcodeProcessor to find and parse accordion content from the segment
        $sections = $this->getAccordionProcessor()->parseFromSegment(
            $segment,
            $site,
            $assetImporter,
            $output,
            $pageSlug
        );

        // Find the "Transcript" section from the accordion sections
        foreach ($sections as $section) {
            if (! \is_array($section)) {
                continue;
            }

            $title = $section['title'] ?? null;

            if ($title === 'Transcript') {
                $content = $section['content'] ?? [];

                // Return the processed transcript content
                return \is_array($content) ? $content : [];
            }
        }

        // Return empty array if no transcript section is found
        return [];
    }

    /**
     * Process intro content with wpautop.
     *
     * @param  string  $intro  The intro content
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output for logging
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> The processed intro content
     */
    private function processIntroContent(
        string $intro,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $pageSlug
    ): array {
        // Return empty array if intro content is empty or only whitespace
        if (\trim($intro) === '') {
            return [];
        }

        // Remove <img> elements from intro content before processing
        $intro = $this->removeImageElements($intro);

        // Use TextProcessor with wpautop enabled, following the same pattern as ContentProcessor
        return TextProcessor::processForBard(
            $intro,
            $site,
            $assetImporter,
            $output,
            true, // Apply wpautop formatting for intro content
            false,
            [], // No attachments for intro content
            [], // Empty terms array for audio intro processing
            $pageSlug
        );
    }

    /**
     * Check if content is in single segment format (no <h3> tags, just [audio]
     * shortcode).
     *
     * @param  string  $content  The raw content
     * @return bool True if single segment format, false otherwise
     */
    private function isSingleSegmentFormat(
        string $content
    ): bool {
        // Check if there are any <h3> tags
        $hasH3Tags = str_contains(
            $content,
            '<h3'
        );

        // Check if there's an [audio] shortcode
        $hasAudioShortcode = str_contains(
            $content,
            '[audio'
        );

        // Single segment format: has [audio] shortcode but no <h3> tags
        return $hasAudioShortcode && ! $hasH3Tags;
    }

    /**
     * Process single segment format content.
     *
     * Handles content with no <h3> tags, just [audio] shortcode onward.
     * Maps post title to segment title and content before [audio] to intro.
     *
     * @param  string  $content  The raw content
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output
     * @param  ?string  $pageSlug  The page slug
     * @param  ?string  $postTitle  The post title
     * @return array<int|string, mixed> Array with 'segments' and 'intro' fields
     */
    private function processSingleSegmentFormat(
        string $content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $pageSlug,
        ?string $postTitle,
        ?AudioSeriesConfig $audioSeriesConfig = null,
        ?string $postDate = null,
        ?string $postId = null
    ): array {
        // Remove <img> elements from content before processing
        $content = $this->removeImageElements($content);

        // Find the position of the first [audio] shortcode
        $audioPos = \strpos(
            $content,
            '[audio'
        );

        // Return empty result if no audio shortcode is found in the content
        if ($audioPos === false) {
            // No audio shortcode found, return empty result
            return [
                'segments' => [],
                'intro' => [],
            ];
        }

        // Extract intro content (everything before [audio] shortcode)
        $introContent = \trim(
            \substr(
                $content,
                0,
                $audioPos
            )
        );

        // Extract the segment content (from [audio] to end of content)
        $segmentContent = \substr(
            $content,
            $audioPos
        );

        // Find the last [/accordion] tag to determine segment boundaries
        $lastAccordionPos = strrpos(
            $segmentContent,
            '[/accordion]'
        );

        // Truncate segment content at the end of the last accordion if found
        if ($lastAccordionPos !== false) {
            // Include content after [/accordion] in the segment
            $segmentContent = \substr(
                $segmentContent,
                0,
                $lastAccordionPos + \strlen('[/accordion]')
            );
        }

        // Extract intro text that would normally be in the segment (between download link and [accordion])
        $segmentIntroText = $this->extractSegmentIntroText($segmentContent);

        // Process the single segment
        $audioSegment = $this->processSingleAudioSegment(
            $segmentContent,
            $site,
            $assetImporter,
            $output,
            $pageSlug,
            $postTitle,
            $audioSeriesConfig,
            $postDate,
            $postId
        );

        // Combine intro content: content before [audio] + segment intro text + content after [/accordion]
        $allIntroContent = [];

        // Add content before [audio] shortcode
        if ($introContent !== '') {
            $allIntroContent[] = $introContent;
        }

        // Add segment intro text (between download link and [accordion])
        if ($segmentIntroText !== '') {
            $allIntroContent[] = $segmentIntroText;
        }

        // Process combined intro content
        // Initialize empty array for processed intro content
        $processedIntro = [];

        // Process intro content if there is any content to process
        if ($allIntroContent !== []) {
            // Combine all intro content parts with double newlines
            $combinedIntro = \implode(
                "\n\n",
                $allIntroContent
            );

            // Process the combined intro content for Bard format
            $processedIntro = $this->processIntroContent(
                $combinedIntro,
                $site,
                $assetImporter,
                $output,
                $pageSlug
            );
        }

        // If there's content after the last [/accordion], add it to intro
        if ($lastAccordionPos !== false) {
            // Find the position of [/accordion] in the original content
            $accordionEndPos = strrpos(
                $content,
                '[/accordion]'
            );
            if ($accordionEndPos !== false) {
                // Extract content that appears after the last accordion tag
                $afterAccordion = \trim(
                    \substr(
                        $content,
                        $accordionEndPos + \strlen('[/accordion]')
                    )
                );
            } else {
                $afterAccordion = '';
            }

            // Process the after-accordion content if it exists
            if ($afterAccordion !== '') {
                // Process the after-accordion content for Bard format
                $afterAccordionProcessed = $this->processIntroContent(
                    $afterAccordion,
                    $site,
                    $assetImporter,
                    $output,
                    $pageSlug
                );

                // Combine intro content
                if (
                    $processedIntro !== []
                    && $afterAccordionProcessed !== []
                ) {
                    $processedIntro = \array_merge(
                        $processedIntro,
                        $afterAccordionProcessed
                    );
                } elseif ($afterAccordionProcessed !== []) { // Use only after-accordion content if no other intro content exists
                    $processedIntro = $afterAccordionProcessed;
                }
            }
        }

        // Return the processed single segment format content
        return [
            'segments' => [$audioSegment],
            'intro' => $processedIntro,
        ];
    }

    /**
     * Process a single audio segment without <h3> title.
     *
     * Uses the post title for audio asset metadata and processes segment content.
     * For single segment format, intro text is handled at the entry level, not
     * segment-level fields.
     *
     * @param  string  $segmentContent  The segment content
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  Instance for importing files
     * @param  OutputInterface  $output  Console output
     * @param  ?string  $pageSlug  The page slug
     * @param  ?string  $postTitle  The post title
     * @param  ?AudioSeriesConfig  $audioSeriesConfig  The audio series configuration
     * @param  ?string  $postDate  The post date for filename generation
     * @param  ?string  $postId  The post ID for filename generation
     * @return array<int|string, mixed> The processed segment structure
     */
    private function processSingleAudioSegment(
        string $segmentContent,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $pageSlug,
        ?string $postTitle,
        ?AudioSeriesConfig $audioSeriesConfig = null,
        ?string $postDate = null,
        ?string $postId = null
    ): array {
        // Strip HTML tags from post title for segment title, fallback to empty string
        $title = ($postTitle !== null && $postTitle !== '') ? HtmlTagStripper::strip($postTitle) : '';

        // Extract transcript from [section title="Transcript"]...[/section]
        $transcript = $this->extractTranscript(
            $segmentContent,
            $site,
            $assetImporter,
            $output,
            $pageSlug
        );

        // Extract audio file URL from [audio] shortcode
        // For single segment format, don't include segment title in filename
        $audioFile = $this->extractAudioFile(
            $segmentContent,
            $site,
            $assetImporter,
            $output,
            $pageSlug,
            $audioSeriesConfig,
            $postDate,
            $postId,
            $postTitle,
            null, // single-segment imports omit the segment-title infix from the filename
            [],
            $transcript,
            $title // keep the post title as the audio asset's metadata title
        );

        // Build the audio segment structure with file-specific playback data
        $audioSegment = [
            'id' => (new RowId)->generate(),
            'file' => $audioFile,
            'preload' => 'metadata',
            'loop' => false,
            'type' => 'local_file',
            'enabled' => true,
        ];

        // Return the complete audio segment structure (no intro field for single segment format)
        return $audioSegment;
    }

    /**
     * Extract intro text between download link and [accordion] tags as raw text.
     *
     * This is used for single segment format to get the intro text that would
     * normally be processed for the segment intro, but instead gets included in the
     * entry intro. When there is no [accordion] shortcode, extracts all text after
     * the download link.
     *
     * @param  string  $segmentContent  The segment content
     * @return string The raw intro text
     */
    private function extractSegmentIntroText(
        string $segmentContent
    ): string {
        // Find the position of the "Download this episode</a>" tag before [accordion]
        // Use case-insensitive search to handle variations like "Download This Episode"
        $downloadLinkPattern = 'Download this episode</a>';

        // Find the last occurrence of the download link pattern
        $lastATagPos = strripos(
            $segmentContent,
            $downloadLinkPattern
        );

        // Return empty string if download link is not found
        if ($lastATagPos === false) {
            return '';
        }

        // Find the position of the first accordion tag
        $accordionPos = \strpos(
            $segmentContent,
            '[accordion]'
        );

        // Calculate the length of the download link pattern for proper substring extraction
        $downloadLinkLength = \strlen($downloadLinkPattern);

        // Extract text after download link
        if ($accordionPos !== false) {
            // Extract text between "Download this episode</a>" and [accordion]
            return \trim(
                \substr(
                    $segmentContent,
                    $lastATagPos + $downloadLinkLength,
                    $accordionPos - $lastATagPos - $downloadLinkLength
                )
            );
        }

        // Return the raw intro text
        return \trim(
            \substr(
                $segmentContent,
                $lastATagPos + $downloadLinkLength
            )
        );
    }

    /**
     * Normalize Bard node lists for ProcessingContext setters.
     *
     * @param  array<int|string, mixed>  $nodes  Raw Bard nodes or empty array
     * @return ?array<int, array<int|string, mixed>> Normalized list or null when empty
     */
    private function normalizeBardNodeList(
        array $nodes
    ): ?array {
        if ($nodes === []) {
            return null;
        }

        $normalized = [];

        foreach ($nodes as $node) {
            if (! \is_array($node)) {
                return null;
            }

            $normalized[] = $node;
        }

        return $normalized;
    }
}
