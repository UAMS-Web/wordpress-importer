<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;

/**
 * Tests the `audio_series` subtype model on the importer's series configs
 * (issue wordpress-importer-archive#443).
 *
 * `UAMS-Web/uams-statamic-archive#1611` retired the parallel `podcast_series` taxonomy and
 * folded podcast into `audio_series` as a multi-select `subtype` (`podcast`,
 * `radio`). The shared `audio` blueprint's podcast-episode fields
 * (`episode_number`, `season_number`, `show_notes`, `episode_transcript`,
 * `episode_duration`, `podcast_credits`) and its `PodcastEpisode` /
 * `PodcastSeries` JSON-LD are gated on the selected series term's `subtype`
 * containing `podcast`.
 *
 * The importer's `AudioSeries` module already targeted `audio_series`, so nothing
 * pointed at the retired taxonomy — but it created its terms with NO subtype,
 * which the blueprint reads as neither kind.
 *
 * @command  php vendor/bin/pest tests/Unit/AudioSeriesSubtypeTest.php
 */
it('tags Here\'s To Your Health as a radio series', function (): void {
    // Post-#1611 it is a radio program, not a podcast, so the episode fields and
    // podcast schema stay correctly switched off for its segments.
    $config = AudioSeriesConfig::createHeresToYourHealth();

    expect($config->getSubtype())->toBe(['radio']);
    expect($config->isPodcast())->toBeFalse();
})->group('audio-series');

it('leaves the radio series without a podcast description', function (): void {
    // `description` is itself conditional on `subtype: contains podcast`, so a
    // radio-only series must not carry one.
    expect(AudioSeriesConfig::createHeresToYourHealth()->getDescription())->toBeNull();
})->group('audio-series');

it('keeps the rest of the radio series config unchanged', function (): void {
    // The subtype work must not disturb the taxonomy/term/asset wiring wordpress-importer-archive#443 leaves
    // alone.
    $config = AudioSeriesConfig::createHeresToYourHealth();

    expect($config->getTaxonomyHandle())->toBe('audio_series');
    expect($config->getTermSlug())->toBe('heres-to-your-health');
    expect($config->getTermName())->toBe("Here's To Your Health");
    expect($config->getTermBlueprint())->toBe('audio_series');
    expect($config->getFilenamePrefix())->toBe('heres-to-your-health');
    expect($config->getAssetContainer())->toBe('audio');
    expect($config->getSeriesFieldValue())->toBe('heres-to-your-health');
})->group('audio-series');

it('builds a podcast-subtype series carrying its schema description', function (): void {
    // The config path wordpress-importer-archive#443 asks for: when podcast content is exported, this is what
    // registers it. Nothing is registered against it yet — the corpus carries
    // `heres-to-your-health` as a WordPress category 836 times and no podcast
    // category at all.
    $config = AudioSeriesConfig::createPodcastSeries(
        'uams-health-talk',
        'UAMS Health Talk',
        'Conversations with UAMS experts.'
    );

    expect($config->getSubtype())->toBe(['podcast']);
    expect($config->isPodcast())->toBeTrue();
    expect($config->getDescription())->toBe('Conversations with UAMS experts.');
    expect($config->getTaxonomyHandle())->toBe('audio_series');
    expect($config->getTermBlueprint())->toBe('audio_series');
})->group('audio-series');

it('defaults a podcast series filename prefix to its slug', function (): void {
    $config = AudioSeriesConfig::createPodcastSeries('uams-health-talk', 'UAMS Health Talk');

    expect($config->getFilenamePrefix())->toBe('uams-health-talk');
    expect($config->getDescription())->toBeNull();
})->group('audio-series');

it('declares only the subtype options the taxonomy defines', function (): void {
    // `audio_series.yaml` declares exactly `podcast` and `radio`; a value outside
    // that set could not be selected in the Control Panel.
    expect(AudioSeriesConfig::SUBTYPE_PODCAST)->toBe('podcast');
    expect(AudioSeriesConfig::SUBTYPE_RADIO)->toBe('radio');
})->group('audio-series');

it('maps nothing to the retired podcast_series taxonomy or field', function (): void {
    // #1611 retired `podcast_series` entirely. The importer was spot-checked clean
    // when wordpress-importer-archive#443 was written; this is the guard that keeps it that way, since the
    // handle would fail silently rather than error.
    //
    // Comments are excluded deliberately — the docblocks added by wordpress-importer-archive#443 name the
    // retired taxonomy to explain what replaced it, and a raw text search would
    // flag that prose as a mapping.
    $sourceRoot = \dirname(__DIR__, 2).'/src';

    $offenders = [];

    /** @var iterable<string, SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($sourceRoot, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($files as $pathString => $file) {
        // Only first-party PHP, and never the bundled export fixtures
        if (
            $file->getExtension() !== 'php'
            || \str_contains(\str_replace('\\', '/', $pathString), '/Tests/')
        ) {
            continue;
        }

        $contents = \file_get_contents($pathString);

        if (! \is_string($contents)) {
            continue;
        }

        // Scan the token stream so only real code counts, not documentation
        foreach (\token_get_all($contents) as $token) {
            // A bare punctuation token cannot carry the handle
            if (! \is_array($token)) {
                continue;
            }

            // Skip both comment flavors
            if (
                $token[0] === \T_COMMENT
                || $token[0] === \T_DOC_COMMENT
            ) {
                continue;
            }

            if (\str_contains($token[1], 'podcast_series')) {
                $offenders[] = \basename($pathString);

                break;
            }
        }
    }

    expect($offenders)->toBe([]);
})->group('audio-series');
