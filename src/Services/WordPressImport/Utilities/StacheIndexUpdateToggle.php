<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

use ReflectionProperty;
use Statamic\Facades\Stache as StacheFacade;
use Statamic\Stache\Stache;
use Throwable;

/**
 * Safely toggles Statamic's Stache "update indexes" flag during bulk imports.
 *
 * Statamic exposes a public `Stache::disableUpdatingIndexes()` to skip the
 * expensive full index rebuilds (`Index::update()` / `getItems()`) that scan
 * every entry, but it deliberately provides **no** public way to turn the flag
 * back on — `Stache::$updateIndexes` is protected. The WordPress importer needs
 * to re-enable it mid-process, because terms and entries are created in the same
 * run and entry creation queries the Stache.
 *
 * Re-enabling therefore requires reflection on one protected property. To keep
 * that fragile dependency from silently breaking on a future `statamic/cms`
 * upgrade, every use is gated behind {@see self::isSupported()}, a runtime
 * feature probe that feature-detects (not version-detects) the behavior through
 * the public API and fails **safe**: if the probe does not pass, callers simply
 * leave index updating enabled (no speedup, but never a corrupted Stache).
 *
 * The StacheIndexUpdateToggleTest contract test pins the vendor behavior this
 * class depends on, so a future statamic/cms upgrade that breaks it fails CI.
 */
class StacheIndexUpdateToggle
{
    /**
     * Memoized result of the support probe (null until first checked).
     */
    private static ?bool $supported = null;

    /**
     * Reset the memoized probe result. Intended for tests.
     */
    public static function flushSupportCache(): void
    {
        self::$supported = null;
    }

    /**
     * Whether disabling and (crucially) re-enabling Stache index updates works
     * on the installed `statamic/cms`.
     *
     * Memoized: the probe only runs once per process.
     */
    public static function isSupported(): bool
    {
        if (self::$supported !== null) {
            return self::$supported;
        }

        return self::$supported = self::probe();
    }

    /**
     * Disable Stache index updating via the public API.
     */
    public static function disable(): void
    {
        StacheFacade::disableUpdatingIndexes();
    }

    /**
     * Re-enable Stache index updating (reflection) and confirm via the public
     * `shouldUpdateIndexes()` that it actually took effect.
     *
     * @return bool True if index updating is confirmed enabled afterwards.
     */
    public static function enable(): bool
    {
        if (! self::forceEnable()) {
            return false;
        }

        return StacheFacade::shouldUpdateIndexes() === true;
    }

    /**
     * Feature-probe the disable/enable round trip through the public API.
     *
     * Flips the flag off (public) and back on (reflection), asserting that the
     * public `shouldUpdateIndexes()` reports each change, then restores the
     * original state. Any mismatch or error means the optimization is not safe
     * to use, so callers fall back to leaving indexes enabled.
     */
    private static function probe(): bool
    {
        // The installed Stache declares the property, so no test reaches this return;
        // it is the guard a future statamic/cms that drops the property would trip.
        if (! property_exists(Stache::class, 'updateIndexes')) {
            // @pest-mutate-ignore: FalseToTrue, RemoveEarlyReturn
            return false;
        }

        $stache = StacheFacade::getFacadeRoot();

        if (! $stache instanceof Stache) {
            return false;
        }

        $original = StacheFacade::shouldUpdateIndexes();

        try {
            StacheFacade::disableUpdatingIndexes();

            if (StacheFacade::shouldUpdateIndexes() !== false) {
                return false;
            }

            if (! self::forceEnable()) {
                return false;
            }

            // Confirm the reflection re-enable is visible through the public API
            // (same contract as enable()). Read it back off a freshly resolved
            // Stache instance so the live, reflection-written flag is observed.
            $reenabled = StacheFacade::getFacadeRoot();

            // The facade root is the Stache already checked above, so the instanceof
            // only narrows the type; the shouldUpdateIndexes() half carries the check
            // @pest-mutate-ignore: InstanceOfToTrue
            if (! $reenabled instanceof Stache || $reenabled->shouldUpdateIndexes() !== true) {
                return false;
            }

            return true;
        } catch (Throwable) {
            return false;
        } finally {
            // Restore the flag to whatever it was before the probe ran.
            if ($original) {
                self::forceEnable();
            } else {
                StacheFacade::disableUpdatingIndexes();
            }
        }
    }

    /**
     * Set the protected `updateIndexes` flag back to true via reflection.
     *
     * @return bool True if the property was written without error.
     */
    private static function forceEnable(): bool
    {
        try {
            $stache = StacheFacade::getFacadeRoot();

            // Without this guard, a root that is not a Stache has no such property, so
            // the reflection below throws and the catch returns the same false
            // @pest-mutate-ignore: InstanceOfToTrue
            if (! $stache instanceof Stache) {
                // @pest-mutate-ignore: RemoveEarlyReturn
                return false;
            }

            $property = new ReflectionProperty($stache, 'updateIndexes');
            $property->setValue($stache, true);

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
