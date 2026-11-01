<?php

declare(strict_types=1);

/**
 * Tests for AudioSeriesRegistry, which registers the known audio series with
 * AudioSeriesManager once per process.
 *
 * Both classes hold static state, so each test resets the registry's
 * initialized flag and the manager's registered series first.
 *
 * @command  php vendor/bin/pest tests/Unit/AudioSeriesRegistryTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesRegistry;

/**
 * Clear the registry's initialized flag and the manager's registered series.
 */
function resetAudioSeriesState(): void
{
    (new ReflectionProperty(AudioSeriesRegistry::class, 'initialized'))->setValue(null, false);
    (new ReflectionProperty(AudioSeriesManager::class, 'registeredSeries'))->setValue(null, []);
}

beforeEach(fn () => resetAudioSeriesState());

afterEach(fn () => resetAudioSeriesState());

it('registers the Here\'s To Your Health series', function (): void {
    AudioSeriesRegistry::initialize();

    expect(array_keys(AudioSeriesManager::getAllSeriesConfigs()))->toBe(['heres-to-your-health'])
        ->and(AudioSeriesManager::getSeriesConfig('heres-to-your-health'))
        ->toEqual(AudioSeriesConfig::createHeresToYourHealth());
});

it('registers once per process, whichever entry point is called', function (Closure $first, Closure $second): void {
    $first();

    // Drop what the first call registered; a second registration would put it back
    (new ReflectionProperty(AudioSeriesManager::class, 'registeredSeries'))->setValue(null, []);

    $second();

    expect(AudioSeriesManager::getAllSeriesConfigs())->toBe([]);
})->with([
    'initialize twice' => [
        fn () => AudioSeriesRegistry::initialize(),
        fn () => AudioSeriesRegistry::initialize(),
    ],
    'ensureInitialized after initialize' => [
        fn () => AudioSeriesRegistry::initialize(),
        fn () => AudioSeriesRegistry::ensureInitialized(),
    ],
    'initialize after ensureInitialized' => [
        fn () => AudioSeriesRegistry::ensureInitialized(),
        fn () => AudioSeriesRegistry::initialize(),
    ],
]);

it('starts each process uninitialized', function (): void {
    expect((new ReflectionProperty(AudioSeriesRegistry::class, 'initialized'))->getDefaultValue())->toBeFalse();
});

it('registers through ensureInitialized when nothing has run yet', function (): void {
    AudioSeriesRegistry::ensureInitialized();

    expect(array_keys(AudioSeriesManager::getAllSeriesConfigs()))->toBe(['heres-to-your-health']);
});
