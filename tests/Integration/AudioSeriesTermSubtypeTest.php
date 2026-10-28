<?php

declare(strict_types=1);

use Statamic\Facades\Term as TermFacade;
use Statamic\Taxonomies\Term;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that `AudioSeriesManager` writes the series kind onto the terms it creates
 * (issue wordpress-importer-archive#443).
 *
 * `UAMS-Web/uams-statamic-archive#1611` folded podcast into `audio_series` as a
 * multi-select `subtype`. The shared `audio` blueprint gates its podcast-episode
 * fields and the `PodcastEpisode` / `PodcastSeries` JSON-LD on that value, so a
 * term created WITHOUT one — which is what the importer did — is silently treated
 * as neither kind.
 *
 * `subtype` is reconciled on an existing term as well as written on a new one, so
 * a term left over from an import that predates this change is repaired rather
 * than kept untagged.
 *
 * @command  php vendor/bin/pest tests/Integration/AudioSeriesTermSubtypeTest.php
 */
uses(StatamicTestCase::class);

afterEach(function (): void {
    Mockery::close();
});

/**
 * An in-memory `Term` that records what the manager writes to it.
 */
final class AudioSeriesProbeTerm extends Term
{
    /**
     * @var array<string, mixed> Everything `set()` recorded
     */
    public array $written = [];

    /**
     * @var int How many times the manager persisted this term
     */
    public int $saves = 0;

    /**
     * @var array<string, mixed> Values a pre-existing term already holds
     */
    private array $existing = [];

    /**
     * Seed the values a pre-existing term already holds.
     *
     * @param  array<string, mixed>  $existing  Field values already on the term
     */
    public function seed(array $existing): self
    {
        $this->existing = $existing;

        return $this;
    }

    /**
     * Record a written value instead of mutating real term data.
     *
     * @param  mixed  $key  The field handle
     * @param  mixed  $value  The value written
     * @return self This probe
     */
    public function set($key, $value): self
    {
        $key = \is_string($key) ? $key : (string) (\is_scalar($key) ? $key : '');

        $this->written[$key] = $value;

        return $this;
    }

    /**
     * Read a seeded value, so the "does this term need updating" comparison sees
     * the state a pre-existing term would present.
     *
     * @param  mixed  $key  The field handle
     * @param  mixed  $fallback  The value to return when the key is unseeded
     * @return mixed The seeded value or the fallback
     */
    public function get($key, $fallback = null)
    {
        if (! \is_string($key)) {
            return $fallback;
        }

        return $this->existing[$key] ?? $fallback;
    }

    /**
     * Read from this same probe rather than resolving a real localization.
     *
     * @return self This probe
     */
    public function inDefaultLocale()
    {
        return $this;
    }

    /**
     * Record the save instead of writing to the content tree.
     *
     * @return bool Always true
     */
    public function save()
    {
        $this->saves++;

        return true;
    }
}

/**
 * Run `createAudioSeriesTerm()` against a probe term and return it.
 *
 * @param  AudioSeriesConfig  $config  The series being created
 * @param  ?array<string, mixed>  $existing  Seed values when an existing term should be found
 */
function audioSeriesProbeTerm(
    AudioSeriesConfig $config,
    ?array $existing = null
): AudioSeriesProbeTerm {
    $probe = new AudioSeriesProbeTerm;

    if ($existing !== null) {
        $probe->seed($existing);
    }

    // An existing term is returned by find(); a new one comes from make()
    TermFacade::shouldReceive('find')->andReturn($existing === null ? null : $probe);
    TermFacade::shouldReceive('make')->andReturn($probe);

    AudioSeriesManager::createAudioSeriesTerm(
        $config,
        'site',
        new BufferedOutput
    );

    return $probe;
}

it('tags a newly created radio series term with its subtype', function (): void {
    $probe = audioSeriesProbeTerm(AudioSeriesConfig::createHeresToYourHealth());

    expect($probe->written['subtype'] ?? null)->toBe(['radio']);
    expect($probe->written['title'] ?? null)->toBe("Here's To Your Health");
    expect($probe->written['blueprint'] ?? null)->toBe('audio_series');
    expect($probe->saves)->toBe(1);
})->group('audio-series');

it('writes no description for a radio series', function (): void {
    // The `description` field is itself conditional on `subtype: contains podcast`
    $probe = audioSeriesProbeTerm(AudioSeriesConfig::createHeresToYourHealth());

    expect($probe->written)->not->toHaveKey('description');
})->group('audio-series');

it('tags a newly created podcast series term and folds in its description', function (): void {
    $probe = audioSeriesProbeTerm(
        AudioSeriesConfig::createPodcastSeries(
            'uams-health-talk',
            'UAMS Health Talk',
            'Conversations with UAMS experts.'
        )
    );

    expect($probe->written['subtype'] ?? null)->toBe(['podcast']);
    expect($probe->written['description'] ?? null)->toBe('Conversations with UAMS experts.');
})->group('audio-series');

it('repairs an existing term that predates the subtype model', function (): void {
    // A term imported before wordpress-importer-archive#443 has the right title and blueprint but no subtype,
    // which the audio blueprint reads as neither kind. It must be updated, not
    // skipped as already-correct.
    $probe = audioSeriesProbeTerm(
        AudioSeriesConfig::createHeresToYourHealth(),
        [
            'title' => "Here's To Your Health",
            'blueprint' => 'audio_series',
        ]
    );

    expect($probe->written['subtype'] ?? null)->toBe(['radio']);
    expect($probe->saves)->toBe(1);
})->group('audio-series');

it('leaves an existing term alone once its subtype is already correct', function (): void {
    // Re-running the import must not rewrite a term that is already right.
    $probe = audioSeriesProbeTerm(
        AudioSeriesConfig::createHeresToYourHealth(),
        [
            'title' => "Here's To Your Health",
            'blueprint' => 'audio_series',
            'subtype' => ['radio'],
        ]
    );

    expect($probe->saves)->toBe(0);
    expect($probe->written)->toBe([]);
})->group('audio-series');

it('repairs an existing podcast term whose description drifted', function (): void {
    $probe = audioSeriesProbeTerm(
        AudioSeriesConfig::createPodcastSeries(
            'uams-health-talk',
            'UAMS Health Talk',
            'Conversations with UAMS experts.'
        ),
        [
            'title' => 'UAMS Health Talk',
            'blueprint' => 'audio_series',
            'subtype' => ['podcast'],
            'description' => 'An older description.',
        ]
    );

    expect($probe->written['description'] ?? null)->toBe('Conversations with UAMS experts.');
    expect($probe->saves)->toBe(1);
})->group('audio-series');
