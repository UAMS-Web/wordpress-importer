<?php

declare(strict_types=1);

/**
 * Tests `AudioSeriesManager::createAudioSeriesTerm()` against a term that really
 * exists (#194): the lookup answers a `LocalizedTerm`, so a term double says nothing
 * about whether the method recognizes one.
 *
 * The taxonomy is kept in a directory of the test's own, so that the terms it saves
 * are the ones the method finds.
 *
 * @command  php vendor/bin/pest tests/Integration/AudioSeriesExistingTermTest.php
 */

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Statamic\Events\TermSaved;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Stache\Stores\Store;
use Statamic\Taxonomies\LocalizedTerm;
use Statamic\Taxonomies\Taxonomy;
use Statamic\Taxonomies\Term;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Read a Stache store's directory, or point it at another one.
 */
function audioSeriesTermDirectory(string $store, ?string $directory = null): string
{
    $stacheStore = Stache::store($store);

    if (! $stacheStore instanceof Store) {
        throw new RuntimeException("No {$store} store.");
    }

    if ($directory !== null) {
        $stacheStore->directory($directory);
    }

    return $stacheStore->directory();
}

/**
 * Save the term the series config describes, with the given title and an extra field.
 */
function audioSeriesTermSave(AudioSeriesConfig $config, string $title): void
{
    $term = TermFacade::make();

    if (! $term instanceof Term) {
        throw new RuntimeException('Expected a term.');
    }

    $term->taxonomy($config->getTaxonomyHandle());
    $term->slug($config->getTermSlug());
    $term->set('title', $title);
    $term->set('blueprint', $config->getTermBlueprint());
    $term->set('subtype', $config->getSubtype());
    $term->set('editor_note', 'kept');
    $term->save();
}

/**
 * Read a field of the saved term.
 */
function audioSeriesTermValue(AudioSeriesConfig $config, string $field): mixed
{
    $term = TermFacade::find($config->getTaxonomyHandle().'::'.$config->getTermSlug());

    if (! $term instanceof LocalizedTerm) {
        throw new RuntimeException('Expected a localized term.');
    }

    return $term->get($field);
}

beforeEach(function (): void {
    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    // Keep the taxonomy and its terms in a directory of this test's own
    $this->taxonomyDirectories = [audioSeriesTermDirectory('taxonomies'), audioSeriesTermDirectory('terms')];
    $this->root = sys_get_temp_dir().'/audio-series-term-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->root);
    audioSeriesTermDirectory('taxonomies', $this->root);
    audioSeriesTermDirectory('terms', $this->root);

    $this->config = AudioSeriesConfig::createHeresToYourHealth();

    $taxonomy = TaxonomyFacade::make($this->config->getTaxonomyHandle());

    if (! $taxonomy instanceof Taxonomy) {
        throw new RuntimeException('Expected a taxonomy.');
    }

    $taxonomy->sites(['default']);
    $taxonomy->save();
});

afterEach(function (): void {
    [$taxonomies, $terms] = $this->taxonomyDirectories;
    audioSeriesTermDirectory('taxonomies', $taxonomies);
    audioSeriesTermDirectory('terms', $terms);

    $root = $this->root ?? null;

    if (is_string($root)) {
        File::deleteDirectory($root);
    }
});

it('finds a term holding the expected values and does not save it', function (): void {
    audioSeriesTermSave($this->config, $this->config->getTermName());

    $saves = 0;
    Event::listen(TermSaved::class, function () use (&$saves): void {
        $saves++;
    });
    $output = new BufferedOutput;

    $changed = AudioSeriesManager::createAudioSeriesTerm($this->config, 'default', $output);

    expect($changed)->toBeFalse()
        ->and($saves)->toBe(0)
        ->and($output->fetch())->toBe('Audio series taxonomy term already exists: '.$this->config->getTaxonomyHandle().'::'.$this->config->getTermSlug().\PHP_EOL);
});

it('updates a term with another title in place, keeping what it does not set', function (): void {
    audioSeriesTermSave($this->config, 'An older title');
    $output = new BufferedOutput;

    $changed = AudioSeriesManager::createAudioSeriesTerm($this->config, 'default', $output);

    expect($changed)->toBeTrue()
        ->and(audioSeriesTermValue($this->config, 'title'))->toBe($this->config->getTermName())
        ->and(audioSeriesTermValue($this->config, 'editor_note'))->toBe('kept')
        ->and($output->fetch())->toBe('Updated audio series taxonomy term: '.$this->config->getTaxonomyHandle().'::'.$this->config->getTermSlug().' with title: '.$this->config->getTermName().\PHP_EOL);
});
