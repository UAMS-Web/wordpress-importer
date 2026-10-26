<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries;

/**
 * Registry for audio series configurations.
 *
 * This class serves as the central registry that initializes and manages all
 * available audio series configurations. It acts as the single source of truth
 * for which audio series are supported by the WordPress import system.
 *
 * The registry uses lazy initialization to ensure audio series configurations are
 * only loaded when needed, and it provides a clean API for registering new audio
 * series without modifying core import logic.
 *
 * Key responsibilities:
 *   - Initialize all available audio series configurations
 *   - Register series with the AudioSeriesManager
 *   - Provide lazy initialization to avoid unnecessary overhead
 *   - Serve as the central point for adding new audio series
 *
 * This class works in conjunction with AudioSeriesConfig (which defines
 * individual series) and AudioSeriesManager (which processes detected series
 * during import).
 */
class AudioSeriesRegistry
{
    /**
     * @var bool Whether the registry has been initialized
     */
    // A property default is not an executed line, so no test can count as covering
    // it; AudioSeriesRegistryTest asserts the default through reflection instead.
    // @pest-mutate-ignore: FalseToTrue
    private static bool $initialized = false;

    /**
     * Initialize all available audio series configurations.
     *
     * This method registers all known audio series with the AudioSeriesManager.
     *
     * To add a new audio series:
     * 1. Add a factory method to AudioSeriesConfig (e.g., createAnotherSeries())
     * 2. Register it here using the factory method
     * 3. The series will be automatically detected and processed during import
     */
    public static function initialize(): void
    {
        if (self::$initialized) {
            return;
        }

        // Register "Here's To Your Health" audio series
        AudioSeriesManager::registerSeries(
            AudioSeriesConfig::createHeresToYourHealth()
        );

        // Future audio series can be registered here
        // Example:
        // AudioSeriesManager::registerSeries(
        //     AudioSeriesConfig::createAnotherSeries()
        // );

        self::$initialized = true;
    }

    /**
     * Ensure the registry is initialized before use.
     */
    public static function ensureInitialized(): void
    {
        self::initialize();
    }
}
