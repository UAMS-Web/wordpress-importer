<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Statamic\Listeners\UpdateAssetReferences;

/**
 * Scopes Statamic's "update asset references" behavior off for a block of work.
 *
 * Statamic's `UpdateAssetReferences` subscriber fires on every `AssetSaved`,
 * `AssetReplaced`, and `AssetDeleted` event. The importer deletes the existing
 * asset on each overwrite (in `AssetDownloader`) to force a fresh upload, so a
 * single image overwrite fans out into a full scan of every entry, taxonomy
 * term, global, and user to null-out references to the deleted path. That sweep
 * is both unnecessary — the importer rewrites content URLs and asset references
 * itself — and actively harmful: a single term with a null blueprint in the
 * sweep fatals the whole run (`DataReferenceUpdater::getTopLevelFields()` calls
 * `->fields()` on null). See issue wordpress-importer-archive#45.
 *
 * Why not flip `statamic.system.update_references`?
 * -------------------------------------------------
 * The subscriber bails when that flag is `false`, but it reads it only in
 * `UpdateAssetReferences::subscribe()` — which Statamic runs once, during the
 * framework `booting` phase (`EventServiceProvider` registers subscribers via
 * `$this->booting(...)`). The handlers never re-check the flag at dispatch time.
 * Setting the config at runtime (inside the command's `handle()`) therefore
 * happens *after* the listener is already bound and is a no-op — the sweep still
 * fires. This was the original wordpress-importer-archive#45 fix and it did not work.
 *
 * The mechanism that does work is Statamic's own runtime API on the subscriber's
 * base class (`Statamic\Events\Subscriber`): `disable()` calls
 * `forgetListener()` for exactly this subscriber's three handlers (leaving other
 * listeners on those events intact), and `enable()` re-subscribes them. This is
 * the same API `Statamic\Assets\Asset` uses internally. We pair them in a
 * `finally` block so the listeners are always restored — even when the import
 * aborts mid-run — and never leak the disabled state into other commands sharing
 * the process (the test suite, queued workers, etc.).
 */
class AssetReferenceUpdates
{
    /**
     * Run the given callback with Statamic's asset reference sweep disabled.
     *
     * Removes the `UpdateAssetReferences` listeners up front and always restores
     * them afterwards, even when the callback throws, so callers never leak the
     * disabled state.
     *
     * @template T
     *
     * @param  callable(): T  $work  The work to run while the reference sweep is off.
     * @return T The callback's return value.
     */
    public static function withDisabled(callable $work): mixed
    {
        // Forget the UpdateAssetReferences handlers for the duration of the work.
        // Unlike toggling the config flag, this takes effect immediately because
        // it unbinds the already-registered listeners rather than relying on a
        // value that was only read at boot.
        UpdateAssetReferences::disable();

        try {
            // Run the caller's work while the sweep is disabled.
            return $work();
        } finally {
            // Re-subscribe the listeners so the change is scoped to this run and
            // does not leak into other commands sharing the process. enable()
            // re-runs subscribe(), which still honors statamic.system.update_references.
            UpdateAssetReferences::enable();
        }
    }
}
