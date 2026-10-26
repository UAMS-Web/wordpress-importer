<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Tests;

use Statamic\Facades\Stache;
use Statamic\Fields\BlueprintRepository;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use UAMSWeb\WordPressImporter\ServiceProvider;

/**
 * Base test case for the light Statamic band (wordpress-importer-archive#319, decision B).
 *
 * Boots a minimal Statamic via Orchestra Testbench — the addon's service
 * provider, the container, facades, and services — but NOT the host app's real
 * content model. Tests that need a booted Statamic (facades, container-resolved
 * services) yet do not depend on real collections/blueprints/Stache entries
 * extend this, so they run in this package's standalone CI, unlike the
 * host-app-bound `Tests\TestCase` integration tests (see tests/CLAUDE.md).
 *
 * **The fake Stache directory is made per-process here (wordpress-importer-archive#824).**
 * `AddonTestCase::setUp()` derives it from the addon service provider's path, so
 * every test in every process running in one checkout writes to the same
 * `tests/__fixtures__/dev-null`. Two suites in one tree then corrupt each other:
 * measured at 0 clean runs out of 12 concurrent, against 12 of 12 sequential.
 * The symptoms are not only the obvious one — `PreventsSavingStacheItemsToDisk`'s
 * unconditional `mkdir()` raising `File exists` after Laravel's `deleteDirectory()`
 * reports a success its suppressed `rmdir` never achieved — but also entries from
 * one run being read by the other, which presents as an ordinary assertion failure
 * in whatever branch is under test.
 */
abstract class StatamicTestCase extends AddonTestCase
{
    /**
     * @var string The addon service provider Testbench boots
     */
    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * @var ?string The per-process directory this case created, if it created one
     */
    private ?string $perProcessStacheDirectory = null;

    /**
     * @var ?string The per-process workbench this case created, if it created one
     */
    private ?string $perProcessWorkbench = null;

    /**
     * Boot the case, then move its Stache fixtures to a per-process directory.
     *
     * The parent has already pointed every store at the shared path by the time
     * this runs, so the stores are re-pointed rather than re-derived — calling
     * `preventSavingStacheItemsToDisk()` a second time would compute each store's
     * relative path from a directory that is already fake.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->isolateWorkbench();

        if (! $this->usesFakeStacheDirectory()) {
            return;
        }

        // `fakeStacheDirectory` is a dynamic property — `AddonTestCase::setUp()`
        // assigns it and nothing declares it — so narrow once rather than at each use
        $shared = $this->fakeStacheDirectory;

        if (! \is_string($shared) || $shared === '') {
            return;
        }

        $unique = $shared.'-'.\getmypid();

        Stache::stores()->each(function ($store) use ($shared, $unique): void {
            $store->directory(\str_replace($shared, $unique, $store->directory()));
        });

        $this->fakeStacheDirectory = $unique;

        // ---
        // Recorded rather than re-derived in teardown. Inferring "this is mine" from
        // a `/dev-null-` substring would also match the SHARED directory in a
        // checkout whose own path contains that fragment — and teardown runs even
        // when `setUp()` throws before the re-point, so the shared path can still be
        // in `fakeStacheDirectory` at that moment. That combination deletes the
        // tracked `tests/__fixtures__/dev-null/.gitkeep`.
        // ---
        $this->perProcessStacheDirectory = $unique;

        // ---
        // The parent only ever creates this directory in its teardown, so the first
        // test of a process would otherwise write into a path that does not exist.
        //
        // An existing one is EMPTIED rather than adopted (wordpress-importer-archive#832). Teardown removes it
        // on a normal run, so a directory found here is what a killed run left
        // behind at a pid the operating system has since reissued. Reading those
        // entries would be the collision wordpress-importer-archive#828 exists to end, arriving by a narrower
        // route and presenting the same way — an ordinary assertion failure in
        // whatever branch is under test, with nothing naming the cause.
        // ---
        self::prepareEmptyDirectory($unique);
    }

    /**
     * Tear the case down, then remove the per-process directory itself.
     *
     * The parent's teardown empties it and recreates it with a `.gitkeep`, which
     * is right for a shared path that must survive the run and wrong for one
     * named after a process that is ending. Only a directory this case actually
     * created is removed, so a `setUp()` that threw before the re-point leaves the
     * shared directory untouched.
     */
    protected function tearDown(): void
    {
        $unique = $this->perProcessStacheDirectory;
        $workbench = $this->perProcessWorkbench;
        $this->perProcessStacheDirectory = null;
        $this->perProcessWorkbench = null;

        parent::tearDown();

        if ($workbench !== null) {
            self::removeContents($workbench);
            @\rmdir($workbench);
        }

        if ($unique === null) {
            return;
        }

        @\unlink($unique.'/.gitkeep');
        @\rmdir($unique);
    }

    /**
     * Whether this case fakes the Stache directory at all.
     *
     * Mirrors the parent's own gate: the trait is applied per test file, so a
     * case that does not use it has no `fakeStacheDirectory` to move.
     *
     * @return bool True when the trait is in play
     */
    private function usesFakeStacheDirectory(): bool
    {
        return \in_array(
            PreventsSavingStacheItemsToDisk::class,
            \class_uses_recursive(static::class),
            true
        );
    }

    /**
     * Create a directory, or empty one that is already there.
     *
     * @param  string  $directory  The directory to create or empty
     */
    private static function prepareEmptyDirectory(string $directory): void
    {
        if (! \is_dir($directory)) {
            \mkdir($directory, 0755, true);

            return;
        }

        self::removeContents($directory);
    }

    /**
     * Delete everything inside a directory, keeping the directory itself.
     *
     * Hand-rolled rather than `app('files')->cleanDirectory()`, which reports
     * success whether or not it achieved anything — the defect behind wordpress-importer-archive#824 — and is
     * not reliably resolvable this early besides.
     *
     * @param  string  $directory  The directory to empty
     */
    private static function removeContents(string $directory): void
    {
        $entries = \scandir($directory);

        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;

            // ---
            // Links are removed, never followed. `is_dir()` is true for a symlink to
            // a directory, so recursing on that test would delete the contents of
            // whatever it points at — outside this directory entirely. Verified:
            // without this branch, a link planted here to a sibling directory has
            // that sibling's files deleted.
            // ---
            if (\is_link($path)) {
                @\unlink($path);

                continue;
            }

            if (\is_dir($path)) {
                self::removeContents($path);
                @\rmdir($path);

                continue;
            }

            @\unlink($path);
        }
    }

    /**
     * Point form and blueprint writes at a per-process directory (wordpress-importer-archive#827).
     *
     * Statamic writes form and blueprint YAML to the Testbench application path —
     * `vendor/orchestra/testbench-core/laravel/resources/{forms,blueprints}` — one
     * location per checkout, shared by every process running the suite. Those are
     * not Stache items, so `PreventsSavingStacheItemsToDisk` never covered them and
     * the per-process Stache directory above does not either.
     *
     * Measured on the merged wordpress-importer-archive#833 tree, `--parallel` with the workbench deliberately
     * NOT cleared between runs: **5 failures in 34 runs**, in two shapes. One worker
     * deleting a file another is reading raises
     * `file_get_contents(…/resources/forms/…): Failed to open stream`; the same race
     * a moment earlier instead yields `null` from the repository, surfacing as
     * `Failed asserting that null is an instance of Statamic\Forms\Form` — or of
     * `Statamic\Fields\Blueprint`, the third observed shape. Those two name nothing,
     * which is why the collision reads as an ordinary assertion failure in whatever
     * branch is under test.
     *
     * What the reproduction needs is `--parallel` and enough runs, nothing more.
     * Clearing the workbench between runs neither helps nor hurts: the shared
     * directories hold zero files at the start of every run regardless, because the
     * NinjaForms tests delete their own artifacts. An earlier probe reported absence
     * because only five of its twenty-six runs used `--parallel` at all, and five
     * clean runs at this rate is an unremarkable outcome.
     */
    private function isolateWorkbench(): void
    {
        $workbench = __DIR__.'/__fixtures__/workbench-'.\getmypid();

        // ---
        // Recorded BEFORE anything that can throw. `mkdir()` and the `scandir()`
        // inside `removeContents()` both raise an `E_WARNING` that PHPUnit turns
        // into an `ErrorException`, and a `setUp()` aborting there would otherwise
        // leave the directory on disk with teardown's guard still reading `null`.
        // ---
        $this->perProcessWorkbench = $workbench;

        foreach (['forms', 'blueprints'] as $directory) {
            if (! \is_dir($workbench.'/'.$directory)) {
                \mkdir($workbench.'/'.$directory, 0755, true);
            }
        }

        // An adopted workbench is emptied for the reason wordpress-importer-archive#832 records
        self::removeContents($workbench.'/forms');
        self::removeContents($workbench.'/blueprints');

        // Read at call time by `FormRepository::all()` and `Form::path()`
        config(['statamic.forms.forms' => $workbench.'/forms']);

        // ---
        // The repository is a singleton built from `statamic.system.blueprints_path`
        // at boot, so moving the config alone would not move it. Only `default` is
        // ever registered here, which is what makes replacing the map safe.
        // ---
        app(BlueprintRepository::class)->setDirectories($workbench.'/blueprints');
    }
}
