<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AudioPostAssetMetadata;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\Traits\DefaultTiptapHtmlTrait;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Traits\DisabledUploadHandlingTrait;

/**
 * Processes WordPress [audio] shortcodes and returns audio data.
 *
 * Handles parsing of [audio mp3="..."] shortcode attributes and downloads
 * and imports audio files to the 'audio' container using the AssetImporter.
 * Applies `AudioPostAssetMetadata` defaults to each imported audio asset.
 * Returns an associative array with audio data including file paths and
 * placeholder fields for future audio attributes.
 */
class AudioShortcodeProcessor implements ShortcodeProcessorInterface
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
     * Provides shared functionality for handling disabled uploads.
     *
     * This trait encapsulates common logic for checking upload status,
     * logging disabled upload messages, and finding attachments by ID.
     * It eliminates code duplication between image and file processors.
     */
    use DisabledUploadHandlingTrait;

    /**
     * Parse a WordPress [audio] shortcode and return audio data.
     *
     * Extracts the mp3 URL from the shortcode attributes and imports the file
     * to the 'audio' container. Returns an associative array with audio data
     * including the imported file path and placeholder fields for future use.
     * Audio-specific parameters are accessed through ProcessingContext.
     *
     * @param  array<int|string, mixed>  $attributes  Parsed shortcode attributes
     * @param  string  $site  The site handle for context
     * @param  AssetImporter  $assetImporter  Instance for importing audio files
     * @param  OutputInterface  $output  Console output for logging
     * @param  array<int|string, mixed>  $terms  Array of SimpleXMLElement term objects from XML (unused)
     * @param  ?string  $pageSlug  The slug of the page being processed
     * @return array<int|string, mixed> Associative array with 'file', 'file_alternate', 'preload', and 'loop' fields
     */
    public function parse(
        array $attributes,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $terms = [],
        ?string $pageSlug = null
    ): array {
        // Extract mp3 URL from attributes
        $mp3Url = $attributes['mp3'] ?? '';

        // Validate that an mp3 URL string was provided before attempting import
        if (! \is_string($mp3Url) || $mp3Url === '') {
            // Return empty audio data if no mp3 URL
            return [
                'type' => 'audio',
                'file' => null,
                'file_alternate' => null,
                'preload' => null,
                'loop' => null,
            ];
        }

        // Apply URL substitutions for legacy UAMS URLs
        $mp3Url = $this->substituteLegacyUrls($mp3Url);

        // Check if file uploads are disabled
        if (
            self::checkUploadsDisabled(
                $assetImporter,
                $output,
                'audio file',
                'file'
            )
        ) {
            // Return audio data with original URL when uploads are disabled
            return [
                'type' => 'audio',
                'file' => $mp3Url,
                'file_alternate' => null,
                'preload' => null,
                'loop' => null,
            ];
        }

        // Get audio series configuration from ProcessingContext
        $audioSeriesConfig = ProcessingContext::getAudioSeriesConfig();

        // Get post date from ProcessingContext
        $postDate = ProcessingContext::getWordPressPostDate();

        // Get post ID from ProcessingContext
        $postId = ProcessingContext::getWordPressPostId();

        // Get post title from ProcessingContext
        $postTitle = ProcessingContext::getWordPressPostTitle();

        // Get segment title from ProcessingContext
        $segmentTitle = ProcessingContext::getSegmentTitle();

        // Get audio asset title from ProcessingContext
        $audioAssetTitle = ProcessingContext::getAudioAssetTitle();

        // Get audio asset series value from ProcessingContext
        $audioAssetSeries = ProcessingContext::getAudioAssetSeries();

        // Get audio asset excerpt from ProcessingContext
        $audioAssetExcerpt = ProcessingContext::getAudioAssetExcerpt() ?? [];

        // Get audio asset transcript from ProcessingContext
        $audioAssetTranscript = ProcessingContext::getAudioAssetTranscript() ?? [];

        // Import the audio file using appropriate method based on audio series
        $importedPath = ($audioSeriesConfig instanceof AudioSeriesConfig && $postDate !== null && $postDate !== '' && $postId !== null && $postId !== '' && $postTitle !== null && $postTitle !== '')
            ? AudioSeriesManager::importAudioSeriesFile(
                $mp3Url,
                $site,
                $output,
                $assetImporter,
                $audioSeriesConfig,
                $postDate,
                $postId,
                $postTitle,
                $segmentTitle
            )
            : $assetImporter->importFile(
                $mp3Url,
                $site,
                $output,
                'audio',
                SameSitePolicy::ALWAYS_IMPORT,
                null,
                null,
                AudioPostAssetMetadata::defaults()
            );

        // Determine the audio container used for the import
        $audioContainer = $audioSeriesConfig instanceof AudioSeriesConfig
            ? $audioSeriesConfig->getAssetContainer()
            : 'audio';

        // Handle failed import
        if ($importedPath === null || $importedPath === '') {
            Log::error(
                'AudioShortcodeProcessor::parse: Failed to import audio file',
                [
                    'site' => $site,
                    'mp3_url' => $mp3Url,
                ]
            );

            // Return empty audio data if import failed
            return [
                'type' => 'audio',
                'file' => null,
                'file_alternate' => null,
                'preload' => null,
                'loop' => null,
            ];
        }

        // Save shortcode-specific values to the imported audio asset metadata
        AudioSeriesManager::setAudioShortcodeAssetMetadata(
            $importedPath,
            $audioContainer,
            $audioAssetTitle,
            $audioAssetSeries,
            $audioAssetExcerpt,
            $audioAssetTranscript
        );

        // Return the audio data with imported file path and placeholder fields
        return [
            'type' => 'audio',
            'file' => $importedPath,
            'file_alternate' => null,
            'preload' => null,
            'loop' => null,
        ];
    }

    /**
     * Substitute legacy UAMS URLs with the current news.uams.edu domain.
     *
     * Handles URL substitutions for various legacy UAMS domains and path patterns
     * to ensure audio files can be properly imported from the current domain.
     *
     * @param  string  $url  The original MP3 URL
     * @return string The URL with legacy patterns substituted
     */
    protected function substituteLegacyUrls(
        string $url
    ): string {
        // Define URL substitution patterns
        $substitutions = [
            // Legacy domain substitutions for wp-content/uploads paths
            'http://uamshealth.com/wp-content/uploads/' => 'https://news.uams.edu/wp-content/uploads/',
            'https://uamshealth.com/wp-content/uploads/' => 'https://news.uams.edu/wp-content/uploads/',
            'http://news.uamsweb.com/wp-content/uploads/' => 'https://news.uams.edu/wp-content/uploads/',
            'https://news.uamsweb.com/wp-content/uploads/' => 'https://news.uams.edu/wp-content/uploads/',

            // Relative path substitution
            '/wp-content/uploads/' => 'https://news.uams.edu/wp-content/uploads/',

            // Legacy audio path substitutions
            'http://news.uams.edu//wp-content/uploads/' => 'https://news.uams.edu/wp-content/uploads/',
            'https://news.uams.edu//wp-content/uploads/' => 'https://news.uams.edu/wp-content/uploads/',
            'http://news.uams.edu/pload/media/' => 'https://news.uams.edu/wp-content/uploads/',
            'https://news.uams.edu/pload/media/' => 'https://news.uams.edu/wp-content/uploads/',
            'http://news.uams.edu/upload/media/' => 'https://news.uams.edu/wp-content/uploads/',
            'https://news.uams.edu/upload/media/' => 'https://news.uams.edu/wp-content/uploads/',
            'http://news.uamsweb.com//wp-content/uploads/' => 'https://news.uams.edu/wp-content/uploads/',
            'https://news.uamsweb.com//wp-content/uploads/' => 'https://news.uams.edu/wp-content/uploads/',
            'http://news.uamsweb.com/pload/media/' => 'https://news.uams.edu/wp-content/uploads/',
            'https://news.uamsweb.com/pload/media/' => 'https://news.uams.edu/wp-content/uploads/',
            'http://news.uamsweb.com/upload/media/' => 'https://news.uams.edu/wp-content/uploads/',
            'https://news.uamsweb.com/upload/media/' => 'https://news.uams.edu/wp-content/uploads/',
        ];

        // Apply substitutions in order of specificity (longer patterns first)
        foreach ($substitutions as $pattern => $replacement) {
            if (
                \str_starts_with(
                    $url,
                    $pattern
                )
            ) {
                $url = $replacement.\substr(
                    $url,
                    \strlen($pattern)
                );
                break; // Only apply the first matching substitution
            }
        }

        return $url;
    }
}
