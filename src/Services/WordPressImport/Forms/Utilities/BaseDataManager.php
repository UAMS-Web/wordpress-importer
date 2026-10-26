<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Forms\Utilities;

/**
 * Base class for form plugin data managers.
 *
 * This abstract class provides common data management functionality
 * that can be extended by specific form plugin data managers.
 *
 * **The store is per instance.** Each manager holds its own loaded forms, and two
 * managers are two stores whatever their class. Production shares one manager per plugin
 * through the container: `ServiceProvider` binds each plugin's `DataManager` as a
 * singleton, so the coordinator, the user-interaction handler and the form-creation
 * coordinator that all take it by injection receive the same instance (wordpress-importer-archive#1103). That
 * binding is the whole of the sharing, and it is the thing to read when a caller wonders
 * why two consumers agree — not this class, which knows nothing about it.
 *
 * Until wordpress-importer-archive#1103 the store was a `static` on this base, which no subclass redeclared, so
 * every manager of every plugin in a process read and wrote one slot. That made a test
 * comparing two managers compare a value with itself, and it made a second plugin's load
 * overwrite the first's — the hazard wordpress-importer-archive#586 records for the MinervaKB settings loader, in a
 * class where the sharing was not even declared.
 *
 * **Reset between imports in one process is `FormPluginCoordinator::clearAllCaches()`**,
 * which clears each plugin coordinator's own manager. A container singleton lives for the
 * process, so a run that imports twice without that call sees the first run's forms — the
 * contract the Loaders already carry, and now the one this class carries too.
 *
 * Follows the Single Responsibility Principle by focusing solely on
 * common data management operations.
 */
abstract class BaseDataManager
{
    /**
     * The loaded forms data array for this manager.
     *
     * @var array<int|string, mixed>|null
     */
    protected ?array $formsData = null;

    /**
     * Create a new BaseDataManager instance.
     *
     * @param  string  $pluginName  The name of the plugin for logging
     */
    public function __construct(protected string $pluginName) {}

    /**
     * Set the loaded forms data for this manager.
     *
     * @param  array<int|string, mixed>|null  $forms  The loaded forms array
     */
    public function setFormsData(
        ?array $forms
    ): void {
        $this->formsData = $forms;
    }

    /**
     * Get the loaded forms data for this manager.
     *
     * @return array<int|string, mixed>|null The loaded forms array
     */
    public function getFormsData(): ?array
    {
        return $this->formsData;
    }

    /**
     * Clear the forms data for this manager.
     *
     * Useful for resetting between different import sessions.
     */
    public function clearFormsData(): void
    {
        // Reset the forms data to null
        $this->formsData = null;
    }

    /**
     * Check if forms data is loaded for this manager.
     *
     * @return bool True if data is loaded, false otherwise
     */
    public function hasFormsData(): bool
    {
        return \is_array($this->formsData);
    }
}
