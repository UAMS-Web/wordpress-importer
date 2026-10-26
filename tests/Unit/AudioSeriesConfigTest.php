<?php

declare(strict_types=1);

/**
 * Tests that `AudioSeriesConfig` reports each value it was built with, applies its
 * constructor defaults, and that its two factories build the radio and podcast
 * series the import expects (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/AudioSeriesConfigTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;

/**
 * Read every value a configuration reports, keyed by its getter.
 *
 * @return array<string, mixed>
 */
function audioSeriesConfigValues(AudioSeriesConfig $config): array
{
    return [
        'taxonomyHandle' => $config->getTaxonomyHandle(),
        'taxonomyTitle' => $config->getTaxonomyTitle(),
        'termSlug' => $config->getTermSlug(),
        'seriesFieldValue' => $config->getSeriesFieldValue(),
        'termName' => $config->getTermName(),
        'termBlueprint' => $config->getTermBlueprint(),
        'filenamePrefix' => $config->getFilenamePrefix(),
        'assetContainer' => $config->getAssetContainer(),
        'useSiteDirectory' => $config->getUseSiteDirectory(),
        'subtype' => $config->getSubtype(),
        'description' => $config->getDescription(),
        'isPodcast' => $config->isPodcast(),
    ];
}

it('reports every value it was built with', function (): void {
    $config = new AudioSeriesConfig(
        'series_handle',
        'Series Title',
        'term-slug',
        'Term Name',
        'term_blueprint',
        'file-prefix',
        'container',
        true,
        ['radio', 'podcast'],
        'About the series'
    );

    expect(audioSeriesConfigValues($config))->toBe([
        'taxonomyHandle' => 'series_handle',
        'taxonomyTitle' => 'Series Title',
        'termSlug' => 'term-slug',
        'seriesFieldValue' => 'term-slug',
        'termName' => 'Term Name',
        'termBlueprint' => 'term_blueprint',
        'filenamePrefix' => 'file-prefix',
        'assetContainer' => 'container',
        'useSiteDirectory' => true,
        'subtype' => ['radio', 'podcast'],
        'description' => 'About the series',
        'isPodcast' => true,
    ]);
});

it('defaults to the audio container, no site directory, no subtype and no description', function (): void {
    $config = new AudioSeriesConfig('h', 'T', 's', 'N', 'b', 'p');

    expect($config->getAssetContainer())->toBe('audio')
        ->and($config->getUseSiteDirectory())->toBeFalse()
        ->and($config->getSubtype())->toBe([])
        ->and($config->getDescription())->toBeNull()
        ->and($config->isPodcast())->toBeFalse();
});

it('builds the radio series for Here\'s To Your Health', function (): void {
    expect(audioSeriesConfigValues(AudioSeriesConfig::createHeresToYourHealth()))->toBe([
        'taxonomyHandle' => 'audio_series',
        'taxonomyTitle' => 'Audio Series',
        'termSlug' => 'heres-to-your-health',
        'seriesFieldValue' => 'heres-to-your-health',
        'termName' => "Here's To Your Health",
        'termBlueprint' => 'audio_series',
        'filenamePrefix' => 'heres-to-your-health',
        'assetContainer' => 'audio',
        'useSiteDirectory' => false,
        'subtype' => ['radio'],
        'description' => null,
        'isPodcast' => false,
    ]);
});

it('builds a podcast series named by its term', function (): void {
    expect(audioSeriesConfigValues(AudioSeriesConfig::createPodcastSeries('the-show', 'The Show', 'A show', 'show-prefix')))->toBe([
        'taxonomyHandle' => 'audio_series',
        'taxonomyTitle' => 'Audio Series',
        'termSlug' => 'the-show',
        'seriesFieldValue' => 'the-show',
        'termName' => 'The Show',
        'termBlueprint' => 'audio_series',
        'filenamePrefix' => 'show-prefix',
        'assetContainer' => 'audio',
        'useSiteDirectory' => false,
        'subtype' => ['podcast'],
        'description' => 'A show',
        'isPodcast' => true,
    ]);
});

it('names a podcast series\' files after its slug when no prefix is given', function (): void {
    $config = AudioSeriesConfig::createPodcastSeries('the-show', 'The Show');

    expect($config->getFilenamePrefix())->toBe('the-show')
        ->and($config->getDescription())->toBeNull();
});
