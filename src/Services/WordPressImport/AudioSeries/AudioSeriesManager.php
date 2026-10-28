<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries;

use DateTime;
use Illuminate\Support\Facades\Log;
use Statamic\Assets\Asset;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Taxonomies\Term;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AudioPostAssetMetadata;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\HtmlTagStripper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LogContextHelper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;

/**
 * Manages audio series configuration and operations.
 *
 * This class serves as the central orchestrator for all audio series
 * functionality during WordPress import processing. It handles the complete
 * lifecycle of audio series from detection through asset import and taxonomy
 * management.
 *
 * Key responsibilities:
 *   - Detect audio series from WordPress categories during import
 *   - Enable audio series taxonomies for Statamic sites
 *   - Create and manage audio series taxonomy terms
 *   - Coordinate custom asset import with series-specific naming and directory
 *     structure
 *   - Generate custom filenames for audio series assets
 *   - Provide utility methods for audio series operations
 *
 * The manager works with registered configurations from AudioSeriesRegistry and
 * uses AudioSeriesConfig instances to determine how each series should be
 * processed. It integrates with the broader WordPress import system to ensure
 * audio series content is properly handled with custom naming, directory
 * structures, and taxonomy management.
 *
 * This class is the primary interface for audio series functionality and is used
 * by PostEntryCreator, SiteConfigurator, and other import components.
 */
class AudioSeriesManager
{
    /**
     * @var array<string, AudioSeriesConfig> Array of registered audio series configurations
     */
    private static array $registeredSeries = [];

    /**
     * Register an audio series configuration.
     *
     * Adds a new audio series configuration to the internal registry, making it
     * available for detection and processing during WordPress import. The series
     * is indexed by its term slug for efficient lookup.
     *
     * @param  AudioSeriesConfig  $config  The audio series configuration to register
     */
    public static function registerSeries(
        AudioSeriesConfig $config
    ): void {
        self::$registeredSeries[$config->getTermSlug()] = $config;
    }

    /**
     * Get a registered audio series configuration by term slug.
     *
     * Retrieves a specific audio series configuration from the internal registry
     * using its term slug as the lookup key. This is used during import processing
     * to get the configuration for detected audio series.
     *
     * @param  string  $termSlug  The term slug to look up (e.g., 'heres-to-your-health')
     * @return ?AudioSeriesConfig The configuration instance or null if not found
     */
    public static function getSeriesConfig(
        string $termSlug
    ): ?AudioSeriesConfig {
        return self::$registeredSeries[$termSlug] ?? null;
    }

    /**
     * Get all registered audio series configurations.
     *
     * Returns the complete registry of all audio series configurations that have
     * been registered with the manager. This is useful for debugging, testing,
     * or when you need to iterate over all available audio series.
     *
     * @return array<string, AudioSeriesConfig> Array of all registered configurations, indexed by term slug
     */
    public static function getAllSeriesConfigs(): array
    {
        return self::$registeredSeries;
    }

    /**
     * Check if a category slug corresponds to a registered audio series.
     *
     * Searches through all registered audio series configurations to find one
     * that matches the provided category slug. This is used during WordPress
     * import to detect when a post belongs to an audio series.
     *
     * @param  string  $categorySlug  The category slug to check (e.g., 'heres-to-your-health')
     * @return ?AudioSeriesConfig The matching configuration instance or null if not found
     */
    public static function getSeriesConfigByCategory(
        string $categorySlug
    ): ?AudioSeriesConfig {
        // Ensure registry is initialized
        AudioSeriesRegistry::ensureInitialized();

        foreach (self::$registeredSeries as $config) {
            if ($config->getTermSlug() === $categorySlug) {
                return $config;
            }
        }

        return null;
    }

    /**
     * Detect audio series from WordPress categories and handle all related processing.
     *
     * This is the main entry point for audio series detection and processing during
     * WordPress import. It examines the provided category slugs to determine if
     * any correspond to registered audio series, and if so, enables the appropriate
     * taxonomy for the site and returns the configuration for further processing.
     *
     * The method performs the following operations:
     *   1. Searches through category slugs to find matches with registered audio
     *      series
     *   2. If a match is found, enables the audio series taxonomy for the site
     *   3. Returns the configuration for use in entry creation and asset processing
     *
     * @param  array<int|string, mixed>  $rawCategories  Array of category slugs from WordPress XML
     * @param  string  $site  The Statamic site handle (e.g., 'academic_senate')
     * @param  OutputInterface  $output  Console output for user feedback and logging
     * @return ?AudioSeriesConfig The detected audio series configuration or null if no audio series categories are found
     */
    public static function detectAndProcessAudioSeries(
        array $rawCategories,
        string $site,
        OutputInterface $output
    ): ?AudioSeriesConfig {
        // Check for audio series categories
        $audioSeriesConfig = null;
        foreach ($rawCategories as $categorySlug) {
            // Skip non-string category values from WordPress XML
            if (! is_string($categorySlug)) {
                continue;
            }

            $config = self::getSeriesConfigByCategory($categorySlug);
            if ($config instanceof AudioSeriesConfig) {
                $audioSeriesConfig = $config;
                break; // Use the first detected audio series
            }
        }

        // Enable audio_series taxonomy if we detected audio series content
        if ($audioSeriesConfig instanceof AudioSeriesConfig) {
            self::enableAudioSeriesTaxonomy(
                $audioSeriesConfig->getTaxonomyHandle(),
                $site,
                $output,
                new SiteManager
            );
        }

        return $audioSeriesConfig;
    }

    /**
     * Create a single audio series taxonomy term.
     *
     * Creates a new taxonomy term in Statamic for the specified audio series,
     * or updates an existing term if it has incorrect title, blueprint, subtype, or
     * description values. The term is created without site prefixes as required for
     * audio series.
     *
     * The method handles the following scenarios:
     *   1. Creates a new term if none exists
     *   2. Updates existing term if title, blueprint, subtype, or description is
     *      incorrect
     *   3. Skips creation if term already exists with correct values
     *
     * `subtype` is reconciled, not just written on create, so a term left over from
     * an import that predates wordpress-importer-archive#443 is repaired rather than kept subtype-less — the
     * `audio` blueprint gates its podcast-episode fields and JSON-LD on that value,
     * so an untagged term is silently treated as neither kind.
     *
     * @param  AudioSeriesConfig  $config  The audio series configuration containing taxonomy and term details
     * @param  string  $site  The Statamic site handle being imported. Not read: a term is
     *                        written to its taxonomy's default locale
     * @param  OutputInterface  $output  Console output for user feedback and logging
     * @param  ?string  $termName  Optional custom term name. If not provided, uses the default name from the
     *                             configuration
     * @return bool True if term was created or updated, false if it already existed with correct values
     */
    public static function createAudioSeriesTerm(
        AudioSeriesConfig $config,
        string $site,
        OutputInterface $output,
        ?string $termName = null
    ): bool {
        // Get configuration values
        $termSlug = $config->getTermSlug();
        $taxonomyHandle = $config->getTaxonomyHandle();
        $expectedTermName = HtmlTagStripper::strip(
            $termName !== null && $termName !== ''
                ? $termName
                : $config->getTermName()
        );
        $expectedBlueprint = $config->getTermBlueprint();
        $expectedSubtype = $config->getSubtype();
        $expectedDescription = $config->getDescription();

        // Check if term already exists
        $existingTerm = TermFacade::find("{$taxonomyHandle}::{$termSlug}");

        // Handle existing term
        if ($existingTerm instanceof Term) {
            // ---
            // If the existing term has the wrong title, blueprint, subtype, or
            // description, update it
            // ---
            if (
                self::getTermStringValue(
                    $existingTerm,
                    'title'
                ) !== $expectedTermName
                || self::getTermStringValue(
                    $existingTerm,
                    'blueprint'
                ) !== $expectedBlueprint
                || self::getTermListValue(
                    $existingTerm,
                    'subtype'
                ) !== $expectedSubtype
                || ($expectedDescription !== null
                    && self::getTermStringValue(
                        $existingTerm,
                        'description'
                    ) !== $expectedDescription)
            ) {
                // Update the existing term
                $existingTerm->set(
                    'title',
                    $expectedTermName
                );
                $existingTerm->set(
                    'blueprint',
                    $expectedBlueprint
                );
                self::applySeriesModel(
                    $existingTerm,
                    $expectedSubtype,
                    $expectedDescription
                );
                $existingTerm->save();

                $output->writeln("<info>Updated audio series taxonomy term: {$taxonomyHandle}::{$termSlug} with title: {$expectedTermName}</info>");

                return true;
            }

            // Term already exists with correct values
            $output->writeln("<comment>Audio series taxonomy term already exists: {$taxonomyHandle}::{$termSlug}</comment>");

            return false;
        }

        // Create the term using Statamic facade
        /** @var Term $term */
        $term = TermFacade::make();

        // Configure the new term
        $term->taxonomy($taxonomyHandle);
        $term->slug($termSlug);
        $term->set(
            'title',
            $expectedTermName
        );
        $term->set(
            'blueprint',
            $expectedBlueprint
        );

        // Declare the series kind so the audio blueprint gates correctly
        self::applySeriesModel(
            $term,
            $expectedSubtype,
            $expectedDescription
        );

        // Save the term
        $term->save();

        // Log the creation of the term
        $output->writeln("<info>Created audio series taxonomy term: {$taxonomyHandle}::{$termSlug}</info>");

        return true;
    }

    /**
     * Import an audio file with series-specific naming and directory structure.
     *
     * Handles the complete import process for audio series files, including URL
     * validation, cache checking, custom filename generation, and directory
     * structure determination. This method provides the core functionality for
     * audio series asset management with custom naming conventions.
     *
     * The import process includes:
     *   1. URL validation and same-site checking
     *   2. File upload settings validation
     *   3. Cache lookup for existing imports
     *   4. Custom filename generation based on series configuration
     *   5. Directory structure determination (series-specific vs. site-specific)
     *   6. Asset import coordination using AssetImporter components
     *   7. Cache storage for future lookups
     *
     * @param  string  $url  The URL of the audio file to download
     * @param  string  $site  The Statamic site handle for context
     * @param  OutputInterface  $output  Console output for user feedback and logging
     * @param  AssetImporter  $assetImporter  The asset importer instance for coordinating the import process
     * @param  AudioSeriesConfig  $config  The audio series configuration containing naming and directory preferences
     * @param  string  $postDate  The post date for filename generation (YYYY-MM-DD format)
     * @param  string  $postId  The post ID for filename generation
     * @param  string  $postTitle  The post title for filename generation
     * @param  ?string  $segmentTitle  The segment title for filename generation (optional, used for multi-segment
     *                                 posts)
     * @return ?string The imported file path or null if import failed
     */
    public static function importAudioSeriesFile(
        string $url,
        string $site,
        OutputInterface $output,
        AssetImporter $assetImporter,
        AudioSeriesConfig $config,
        string $postDate,
        string $postId,
        string $postTitle,
        ?string $segmentTitle = null
    ): ?string {
        if ($url === '') {
            // Enhance log context with entry information
            $logData = LogContextHelper::enhanceWithEntryContext(
                ['site' => $site]
            );

            // Log warning when no URL is provided
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'AudioSeriesManager::importAudioSeriesFile: No URL provided for audio series file import',
                    $logData['page_url']
                ),
                $logData['context']
            );

            return null;
        }

        // Get base blog URL for same-site checking
        $baseBlogUrl = $assetImporter->getBaseBlogUrl();

        // Skip assets not on the same site as the base blog URL; an empty base counts
        // every URL as the same site
        if (
            $baseBlogUrl !== null
            && ! AssetUtils::isSameSiteUrl(
                $url,
                $baseBlogUrl
            )
        ) {
            $output->writeln("<comment>Asset not on same site detected. Skipping download and linking directly to '$url'.</comment>");

            return $url;
        }

        // Check if file uploads are enabled
        if (! $assetImporter->getUploadFiles()) {
            $filename = basename($url);

            $output->writeln("<comment>File uploads disabled. Using original URL for audio file: {$filename}</comment>");

            return $url;
        }

        // Strip HTML tags from titles for consistent cache keys and filename generation
        $strippedPostTitle = HtmlTagStripper::strip($postTitle);
        $strippedSegmentTitle = ($segmentTitle !== null && $segmentTitle !== '') ? HtmlTagStripper::strip($segmentTitle) : null;

        // Create a cache key that includes parameters that affect filename generation
        $cacheKey = $url.'|'.($strippedSegmentTitle ?? 'null').'|'.$postId.'|'.$strippedPostTitle;

        // Check cache for existing import
        $fileCache = $assetImporter->getFileCache();
        if (isset($fileCache[$cacheKey])) {
            $cachedPath = $fileCache[$cacheKey];

            return is_string($cachedPath)
                ? $cachedPath
                : null;
        }

        // Generate custom filename for audio series (using already-stripped titles)
        $customFilename = self::generateAudioSeriesFilename(
            $config,
            $postDate,
            $strippedPostTitle,
            $postId,
            $strippedSegmentTitle
        );

        // Build the series-specific subdirectory layer for date and post title
        $seriesSubdirectory = self::generateAudioSeriesSubdirectory(
            $postDate,
            $strippedPostTitle
        );

        // Determine directory structure based on configuration
        $path = ($config->getUseSiteDirectory() ? "{$site}/" : '')."{$config->getFilenamePrefix()}/{$seriesSubdirectory}/{$customFilename}";

        // Import the file using AssetImporter's components
        $importedPath = $assetImporter->getOverwriteHandler()->handleFileImport(
            $url,
            $path,
            $config->getUseSiteDirectory() ? $site : $config->getFilenamePrefix(),
            $config->getAssetContainer(),
            $output,
            $assetImporter->getDownloader(),
            $assetImporter->getMetadataSetter(),
            null,
            null,
            AudioPostAssetMetadata::defaults()
        );

        // Cache the imported path using the composite cache key
        if ($importedPath !== null && $importedPath !== '') {
            $assetImporter->setFileCache(
                $cacheKey,
                $importedPath
            );
        }

        return $importedPath;
    }

    /**
     * Generate a custom filename for audio series files.
     *
     * Creates a standardized filename for audio series assets based on the series
     * configuration and post metadata. The filename follows a specific pattern
     * that includes the series prefix, date, post title, optional segment title,
     * and post ID for uniqueness.
     *
     * Filename format:
     *   - Single segment: {series-prefix}_{YYYY-MM-DD}_{post-title-slug}_{post-id}.mp3
     *   - Multi-segment:
     *     {series-prefix}_{YYYY-MM-DD}_{post-title-slug}_{segment-title-slug}_{post-id}.mp3
     *
     * @param  AudioSeriesConfig  $config  The audio series configuration containing the filename prefix
     * @param  string  $postDate  The post date in YYYY-MM-DD HH:MM:SS format
     * @param  string  $postTitle  The post title to be converted to a slug
     * @param  string  $postId  The post ID for uniqueness
     * @param  ?string  $segmentTitle  The segment title to be converted to a slug (optional, used for multi-segment
     *                                 posts)
     * @return string The generated filename in lowercase with .mp3 extension
     */
    public static function generateAudioSeriesFilename(
        AudioSeriesConfig $config,
        string $postDate,
        string $postTitle,
        string $postId,
        ?string $segmentTitle = null
    ): string {
        // Parse the post date
        $date = new DateTime($postDate);
        $dateStr = $date->format('Y-m-d');

        // Create slug from post title (HTML should already be stripped by caller)
        $postTitleSlug = self::createSlug($postTitle);

        // Create slug from segment title if provided (HTML should already be stripped by
        // caller); an empty or absent title slugs to '' like one with no letters or digits
        $segmentTitleSlug = self::createSlug($segmentTitle ?? '');

        // Build filename components
        $filename = "{$config->getFilenamePrefix()}_{$dateStr}_{$postTitleSlug}";

        if ($segmentTitleSlug !== '') {
            $filename .= "_{$segmentTitleSlug}";
        }

        $filename .= "_{$postId}.mp3";

        // Convert to lowercase
        return \strtolower($filename);
    }

    /**
     * Generate a date-and-title subdirectory for audio series files.
     *
     * Directory format:
     *   - {YYYY-MM-DD}_{post-title-slug}
     *
     * @param  string  $postDate  The post date in YYYY-MM-DD HH:MM:SS format
     * @param  string  $postTitle  The post title to be converted to a slug
     * @return string The generated subdirectory name in lowercase
     */
    public static function generateAudioSeriesSubdirectory(
        string $postDate,
        string $postTitle
    ): string {
        // Parse the post date for directory naming
        $date = new DateTime($postDate);

        // Format the parsed date to YYYY-MM-DD
        $dateStr = $date->format('Y-m-d');

        // Create slug from post title for directory naming
        $postTitleSlug = self::createSlug($postTitle);

        // Build the subdirectory with date and post title slug, both already lowercase
        return "{$dateStr}_{$postTitleSlug}";
    }

    /**
     * Creates a URL-friendly slug from a title.
     *
     * Converts a title string into a URL-friendly slug by converting to lowercase,
     * replacing spaces and special characters with hyphens, and removing leading
     * and trailing hyphens. This is used for generating consistent slugs from
     * post titles and segment titles in audio series filenames.
     *
     * @param  string  $title  The title to convert to a slug
     * @return string The URL-friendly slug with hyphens and lowercase letters
     */
    public static function createSlug(
        string $title
    ): string {
        // Convert to lowercase
        $slug = \strtolower($title);

        // Replace spaces and special characters with hyphens
        $normalizedSlug = \preg_replace(
            '/[^a-z0-9]+/',
            '-',
            $slug
        );
        // The pattern is fixed and valid, so preg_replace() never fails here and the
        // fallback only types the value.
        // @pest-mutate-ignore: TernaryNegated
        $slug = is_string($normalizedSlug)
            ? $normalizedSlug
            : $slug;

        // Remove leading/trailing hyphens
        $slug = \trim(
            $slug,
            '-'
        );

        return $slug;
    }

    /**
     * Check if a taxonomy handle is an audio series taxonomy.
     *
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle to check
     * @return bool True if it's an audio series taxonomy
     */
    public static function isAudioSeriesTaxonomy(
        string $statamicTaxonomyHandle
    ): bool {
        // Ensure registry is initialized
        AudioSeriesRegistry::ensureInitialized();

        return array_any(self::$registeredSeries, fn (AudioSeriesConfig $config): bool => $config->getTaxonomyHandle() === $statamicTaxonomyHandle);
    }

    /**
     * Filter out audio series categories from regular post category processing.
     *
     * This method removes audio series categories from the list of categories
     * that should be processed as regular post categories. Audio series categories
     * are handled separately through the audio series system and should not
     * appear in the post_category field or create post_category taxonomy terms.
     *
     * @param  array<int|string, mixed>  $rawCategories  Array of category slugs from WordPress XML
     * @param  ?AudioSeriesConfig  $audioSeriesConfig  The audio series configuration
     * @return array<int|string, mixed> Array of category slugs with audio series categories removed
     */
    public static function filterOutAudioSeriesCategories(
        array $rawCategories,
        ?AudioSeriesConfig $audioSeriesConfig
    ): array {
        // If no audio series config, return all categories unchanged
        if (! $audioSeriesConfig instanceof AudioSeriesConfig) {
            return $rawCategories;
        }

        // Get the audio series term slug to filter out
        $audioSeriesSlug = $audioSeriesConfig->getTermSlug();

        // Filter out the audio series category from the array
        return \array_filter(
            $rawCategories,
            fn ($categorySlug): bool => $categorySlug !== $audioSeriesSlug
        );
    }

    /**
     * Enable an audio series taxonomy for a site.
     *
     * @param  string  $statamicTaxonomyHandle  The Statamic taxonomy handle
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  Console output for logging
     * @param  SiteManager  $siteManager  The site manager
     */
    public static function enableAudioSeriesTaxonomy(
        string $statamicTaxonomyHandle,
        string $site,
        OutputInterface $output,
        SiteManager $siteManager
    ): void {
        // Use SiteManager to handle site enabling
        $siteManager->enableTaxonomyForSite(
            $site,
            $statamicTaxonomyHandle,
            $output
        );
    }

    /**
     * Set audio shortcode-specific metadata on an imported audio asset.
     *
     * @param  string  $path  The imported asset path within the container
     * @param  string  $container  The asset container handle
     * @param  ?string  $title  The shortcode-derived title value
     * @param  ?string  $series  The series term slug matching the post entry series value
     * @param  array<int, array<int|string, mixed>>  $excerpt  The shortcode-derived excerpt Bard data
     * @param  array<int, array<int|string, mixed>>  $transcript  The shortcode-derived transcript Bard data
     */
    public static function setAudioShortcodeAssetMetadata(
        string $path,
        string $container,
        ?string $title,
        ?string $series,
        array $excerpt = [],
        array $transcript = []
    ): void {
        // Find the imported asset by container and path
        $asset = AssetFacade::find("{$container}::{$path}");

        // Check if the imported asset was found
        if (! $asset instanceof Asset) {
            // Enhance log context when the imported asset cannot be found
            $logData = LogContextHelper::enhanceWithEntryContext(
                [
                    'path' => $path,
                    'container' => $container,
                ]
            );

            // Log warning when the asset metadata cannot be saved
            Log::warning(
                LogContextHelper::formatMessageWithEntry(
                    'AudioSeriesManager::setAudioShortcodeAssetMetadata: Asset not found',
                    $logData['page_url']
                ),
                $logData['context']
            );

            // Exit when there is no asset to update
            return;
        }

        // Initialize metadata with existing asset data to avoid overwriting unrelated fields
        $metadata = self::getExistingAssetData($asset);

        // Check if the shortcode title has content
        if ($title !== null && $title !== '') {
            // Save the shortcode title to asset metadata
            $metadata['title'] = $title;
        }

        // Check if the shortcode series has content
        if ($series !== null && $series !== '') {
            // Save the shortcode series to asset metadata
            $metadata['series'] = [$series];
        }

        // Check if shortcode excerpt content exists
        if ($excerpt !== []) {
            // Save the shortcode excerpt to asset metadata
            $metadata['excerpt'] = $excerpt;
        }

        // Check if shortcode transcript content exists
        if ($transcript !== []) {
            // Save the shortcode transcript to asset metadata
            $metadata['transcript'] = $transcript;
        }

        // Set the creator organization metadata for audio assets
        $metadata['creator_organization'] = 'UAMS';

        // Set the country-of-origin query toggle metadata for audio assets
        $metadata['country_of_origin_query'] = true;

        // Set the geographic location term metadata for audio assets
        $metadata['geographic_location'] = ['little-rock'];

        // Set the geographic location content term metadata for audio assets
        $metadata['geographic_location_content'] = ['little-rock'];

        // Set the digital source type metadata preset for audio assets
        $metadata['digital_source_type'] = [
            'preset' => 'humanEdits_audio_mastered',
        ];

        // Set updated metadata values on the asset
        $asset->data($metadata);

        // Persist updated audio shortcode metadata to the asset
        $asset->save();

        // Clean up local variables that are no longer needed
        unset($metadata);
    }

    /**
     * Write the series kind onto a term.
     *
     * `subtype` is the multi-select `#1611` introduced on `audio_series`; the
     * `audio` blueprint gates its podcast-episode fields and the `PodcastEpisode` /
     * `PodcastSeries` JSON-LD on it containing `podcast`. `description` is the
     * schema.org text folded onto the term for a podcast series, and the field is
     * itself conditional on that subtype — so a series that declares none is left
     * untouched rather than blanked. wordpress-importer-archive#443
     *
     * @param  Term  $term  The term to write to
     * @param  list<string>  $subtype  The series subtype option handles
     * @param  ?string  $description  The schema.org description, when the series has one
     */
    private static function applySeriesModel(
        Term $term,
        array $subtype,
        ?string $description
    ): void {
        // Declare the kind(s) of series this term represents
        $term->set(
            'subtype',
            $subtype
        );

        // Only a series that declares a description writes one
        if (
            $description !== null
            && $description !== ''
        ) {
            $term->set(
                'description',
                $description
            );
        }
    }

    /**
     * Read a list field from a term as a list of strings.
     *
     * The `subtype` multi-select stores an array. Reading it through the same
     * default-locale path as `getTermStringValue()` keeps the comparison that
     * decides whether an existing term needs updating consistent between the two.
     *
     * @param  Term  $term  The term to read from
     * @param  string  $field  The field handle to read
     * @return list<string> The stored values, or an empty list when unreadable
     */
    private static function getTermListValue(
        Term $term,
        string $field
    ): array {
        // Resolve the default-locale term representation
        $localizedTerm = $term->inDefaultLocale();

        // A term's default-locale view is always an object that reads fields, so this
        // only types the call below.
        // @pest-mutate-ignore: BooleanOrToBooleanAnd, RemoveEarlyReturn
        if (
            ! is_object($localizedTerm)
            || ! method_exists(
                $localizedTerm,
                'get'
            )
        ) {
            return [];
        }

        // Read the requested field value
        $value = $localizedTerm->get($field);

        // Return an empty list for anything that is not an array
        if (! is_array($value)) {
            return [];
        }

        // Keep only string entries so the comparison cannot trip over stray types
        return array_values(
            array_filter(
                $value,
                is_string(...)
            )
        );
    }

    /**
     * Read a string field from a term's default locale.
     *
     * @param  Term  $term  The term to read from
     * @param  string  $field  The field handle to read
     * @return string The field value, or an empty string when unavailable
     */
    private static function getTermStringValue(
        Term $term,
        string $field
    ): string {
        // Resolve the default-locale term representation
        $localizedTerm = $term->inDefaultLocale();

        // A term's default-locale view is always an object that reads fields, so this
        // only types the call below.
        // @pest-mutate-ignore: BooleanOrToBooleanAnd, RemoveEarlyReturn, EmptyStringToNotEmpty
        if (
            ! is_object($localizedTerm)
            || ! method_exists(
                $localizedTerm,
                'get'
            )
        ) {
            return '';
        }

        // Read the requested field value
        $value = $localizedTerm->get($field);

        return is_string($value)
            ? $value
            : '';
    }

    /**
     * Read existing asset data as a plain array for merging.
     *
     * @param  Asset  $asset  The asset whose data should be read
     * @return array<int|string, mixed> Existing asset data, or an empty array
     */
    private static function getExistingAssetData(Asset $asset): array
    {
        // Read the asset's current data object
        $assetData = $asset->data();

        // An asset's data is always a collection, so this only types the call below.
        // @pest-mutate-ignore: BooleanOrToBooleanAnd, RemoveEarlyReturn
        if (
            ! is_object($assetData)
            || ! method_exists(
                $assetData,
                'all'
            )
        ) {
            return [];
        }

        // Collect existing values into a plain array
        $existingData = $assetData->all();

        return is_array($existingData)
            ? $existingData
            : [];
    }
}
