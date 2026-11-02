<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\AudioSeries;

/**
 * Configuration class for audio series.
 *
 * This class encapsulates all the configuration parameters needed for a single
 * audio series, including taxonomy settings, term details, and asset handling
 * preferences. It serves as an immutable data container that defines how a
 * specific audio series should behave during WordPress import processing.
 *
 * Each audio series (e.g., "Here's To Your Health") has its own configuration
 * instance that specifies:
 *   - Taxonomy handle and title for Statamic
 *   - Term slug, name, and blueprint
 *   - The series subtype(s) and, for a podcast, its schema.org description
 *   - Custom filename prefix for asset naming
 *   - Asset container and directory structure preferences
 *
 * `UAMS-Web/uams-statamic-archive#1611` retired the parallel `podcast_series` taxonomy and
 * folded podcast into `audio_series` as a multi-select `subtype`. The shared `audio`
 * blueprint's podcast-episode fields and its `PodcastEpisode` / `PodcastSeries`
 * JSON-LD are now gated on the selected series term's `subtype` containing
 * `podcast`, so a term the importer creates without a subtype is silently treated
 * as neither kind. Each registered series therefore declares its own kind (wordpress-importer-archive#443).
 *
 * This class is used by AudioSeriesRegistry to register available series and by
 * AudioSeriesManager to process detected audio series during import.
 */
class AudioSeriesConfig
{
    /**
     * The `subtype` option for a series distributed as a podcast.
     */
    public const string SUBTYPE_PODCAST = 'podcast';

    /**
     * The `subtype` option for a series broadcast on radio.
     */
    public const string SUBTYPE_RADIO = 'radio';

    /**
     * Create a new audio series configuration.
     *
     * @param  string  $taxonomyHandle  The Statamic taxonomy handle
     * @param  string  $taxonomyTitle  The taxonomy title
     * @param  string  $termSlug  The term slug
     * @param  string  $termName  The term name
     * @param  string  $termBlueprint  The term blueprint
     * @param  string  $filenamePrefix  The filename prefix
     * @param  string  $assetContainer  The asset container for audio files
     * @param  bool  $useSiteDirectory  Whether to use site-specific directory structure
     * @param  list<string>  $subtype  The series subtype(s) — `podcast` and/or `radio`
     * @param  ?string  $description  The schema.org description, for a podcast series
     */
    public function __construct(private string $taxonomyHandle, private string $taxonomyTitle, private string $termSlug, private string $termName, private string $termBlueprint, private string $filenamePrefix, private string $assetContainer = 'audio', private bool $useSiteDirectory = false, private array $subtype = [], private ?string $description = null) {}

    /**
     * Get the taxonomy handle.
     *
     * @return string The taxonomy handle
     */
    public function getTaxonomyHandle(): string
    {
        return $this->taxonomyHandle;
    }

    /**
     * Get the taxonomy title.
     *
     * @return string The taxonomy title
     */
    public function getTaxonomyTitle(): string
    {
        return $this->taxonomyTitle;
    }

    /**
     * Get the term slug.
     *
     * @return string The term slug
     */
    public function getTermSlug(): string
    {
        return $this->termSlug;
    }

    /**
     * Get the value used for the post entry `series` field.
     *
     * @return string The post entry series field value
     */
    public function getSeriesFieldValue(): string
    {
        return $this->termSlug;
    }

    /**
     * Get the term name.
     *
     * @return string The term name
     */
    public function getTermName(): string
    {
        return $this->termName;
    }

    /**
     * Get the term blueprint.
     *
     * @return string The term blueprint
     */
    public function getTermBlueprint(): string
    {
        return $this->termBlueprint;
    }

    /**
     * Get the series subtype(s).
     *
     * @return list<string> The subtype option handles
     */
    public function getSubtype(): array
    {
        return $this->subtype;
    }

    /**
     * Get the schema.org description folded onto the term.
     *
     * @return ?string The description, or null when the series declares none
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Check whether this series is distributed as a podcast.
     *
     * The `audio` blueprint gates its podcast-episode fields and the
     * `PodcastEpisode` / `PodcastSeries` JSON-LD on the series term's `subtype`
     * containing `podcast`.
     *
     * @return bool Whether the podcast subtype is declared
     */
    public function isPodcast(): bool
    {
        // The subtype is a list of strings and `podcast` is not numeric, so a loose
        // match compares as strict and dropping the flag changes no result.
        // @pest-mutate-ignore: TrueToFalse
        return \in_array(
            self::SUBTYPE_PODCAST,
            $this->subtype,
            true
        );
    }

    /**
     * Get the filename prefix.
     *
     * @return string The filename prefix
     */
    public function getFilenamePrefix(): string
    {
        return $this->filenamePrefix;
    }

    /**
     * Get the asset container.
     *
     * @return string The asset container
     */
    public function getAssetContainer(): string
    {
        return $this->assetContainer;
    }

    /**
     * Get whether to use site-specific directory structure.
     *
     * @return bool Whether to use site-specific directory structure
     */
    public function getUseSiteDirectory(): bool
    {
        return $this->useSiteDirectory;
    }

    /**
     * Create a configuration for the "Here's To Your Health" audio series.
     *
     * Post-#1611 this is a RADIO program, not a podcast: it carries
     * `subtype: [radio]`, which leaves the `audio` blueprint's podcast-episode
     * fields and the `PodcastEpisode` / `PodcastSeries` JSON-LD correctly switched
     * off for its segments.
     *
     * @return AudioSeriesConfig The configuration instance
     */
    public static function createHeresToYourHealth(): AudioSeriesConfig
    {
        return new self(
            'audio_series',
            'Audio Series',
            'heres-to-your-health',
            "Here's To Your Health",
            'audio_series',
            'heres-to-your-health',
            'audio',
            false,
            [self::SUBTYPE_RADIO]
        );
    }

    /**
     * Create a configuration for a podcast-subtype audio series.
     *
     * The podcast counterpart to `createHeresToYourHealth()`: it tags the term
     * `subtype: [podcast]`, which is what turns on the shared `audio` blueprint's
     * episode fields and the `PodcastEpisode` / `PodcastSeries` schema, and folds
     * the schema.org description onto the term as #1611 specifies.
     *
     * No series is registered against this factory yet. The export corpus carries
     * `heres-to-your-health` as a WordPress category 836 times and no podcast
     * category at all — "UAMS Health Talk" appears only as prose in post bodies —
     * so registering one would invent a slug the detector could never match. When
     * podcast content is exported, register it in `AudioSeriesRegistry` through
     * this factory. wordpress-importer-archive#443
     *
     * @param  string  $termSlug  The term slug
     * @param  string  $termName  The term name
     * @param  ?string  $description  The schema.org description for `PodcastSeries`
     * @param  ?string  $filenamePrefix  The asset filename prefix, defaulting to the slug
     * @return AudioSeriesConfig The configuration instance
     */
    public static function createPodcastSeries(
        string $termSlug,
        string $termName,
        ?string $description = null,
        ?string $filenamePrefix = null
    ): AudioSeriesConfig {
        return new self(
            'audio_series',
            'Audio Series',
            $termSlug,
            $termName,
            'audio_series',
            $filenamePrefix ?? $termSlug,
            'audio',
            false,
            [self::SUBTYPE_PODCAST],
            $description
        );
    }
}
