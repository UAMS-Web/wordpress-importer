<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Statamic\Assets\AssetContainer as StatamicAssetContainer;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Path;
use Statamic\Facades\Stache;
use Statamic\Stache\Stores\AggregateStore;
use Statamic\Stache\Stores\Keys;
use Statamic\Stache\Stores\Store;
use Throwable;

/**
 * A disposable copy of everything an import writes, for a run that must leave no trace (#254).
 *
 * An import writes through some twenty-five classes, and an update, a disable and a skip all
 * depend on what is already stored, so a run that wrote nothing could not produce the same
 * counts. A verification run therefore does the real work against a copy: every Stache store,
 * the blueprints, fieldsets and forms, and the disks behind the asset containers are copied
 * here and repointed, and the copy is deleted afterwards. A run killed outright leaves the
 * copy in the system temporary directory, and nothing in the host's own directories changed.
 *
 * **The caches move before the directories, and that order is the safety of this class.** A
 * store whose directory has moved while its cached index still names the old paths is
 * destructive: `ExistsAsFile::writeFile()` sees the item's path differ from the one it was
 * read from and deletes the original. The Stache and the application cache are pointed at the
 * in-memory `array` store first, and each store's key map is reloaded from it. `Stache::clear()`
 * does not reset that map, and an old path left in it makes every copied file read as a
 * duplicate of itself, so no path read from the host's cache may survive into the run. The
 * in-memory store also keeps a verification run from clearing the host's own cache.
 *
 * A setup that cannot be fully contained is refused, not attempted, because a partly moved
 * run is the failure this class exists to prevent.
 */
class VerificationWorkspace
{
    /**
     * The cache store a verification run reads and writes, which Laravel configures by default.
     */
    private const string CACHE_STORE = 'array';

    /**
     * The configuration this class changes, restored when the run ends.
     *
     * @var list<string>
     */
    private const array CONFIG_KEYS = [
        'statamic.stache.cache_store',
        'cache.default',
        'statamic.forms.forms',
    ];

    /**
     * Each directory copied, keyed by its source, both with no trailing separator.
     *
     * @var array<string, string>
     */
    private array $copies = [];

    /**
     * The configuration values to restore, keyed by configuration key.
     *
     * @var array<string, mixed>
     */
    private array $savedConfig = [];

    /**
     * Each moved store with the directory it had.
     *
     * @var list<array{store: Store, directory: string}>
     */
    private array $savedStores = [];

    /**
     * The disk roots to restore, keyed by disk handle.
     *
     * @var array<string, string>
     */
    private array $savedDiskRoots = [];

    /**
     * The blueprint directory to restore, or null while it has not moved.
     */
    private ?string $savedBlueprintDirectory = null;

    /**
     * The fieldset directory to restore, or null while it has not moved.
     */
    private ?string $savedFieldsetDirectory = null;

    /**
     * @param  string  $root  The workspace directory, with no trailing separator
     */
    private function __construct(private readonly string $root) {}

    /**
     * Copy what an import writes and point this process at the copy.
     *
     * @return self The entered workspace, to be left when the run ends
     *
     * @throws RuntimeException When the run cannot be fully contained
     */
    public static function enter(): self
    {
        $workspace = new self(Path::tidy(\sys_get_temp_dir()).'/'.\uniqid('wordpress-import-verify-', true));

        try {
            $workspace->prepare();
        } catch (Throwable $e) {
            // Whatever moved before the failure is moved back, then the failure is the caller's
            $workspace->leave();

            throw $e;
        }

        return $workspace;
    }

    /**
     * Point this process back at the host's directories and delete the copy.
     *
     * @return bool False when the copy could not be deleted
     */
    public function leave(): bool
    {
        try {
            // Drops what the run indexed while the array store still answers for it. The
            // host's cache is not the Stache's yet, so it is left exactly as it was.
            if ($this->savedStores !== []) {
                Stache::clear();
            }
        } finally {
            $this->restore();
        }

        return ! \is_dir($this->root) || File::deleteDirectory($this->root);
    }

    /**
     * The workspace directory.
     *
     * @return string The absolute path, with no trailing separator
     */
    public function root(): string
    {
        return $this->root;
    }

    /**
     * Refuse what cannot be contained, then move the caches and the directories.
     *
     * @throws RuntimeException When the run cannot be fully contained
     */
    private function prepare(): void
    {
        // Git automation would commit the run's saves, and it reads the host's repository
        if (config('statamic.git.enabled') === true) {
            throw new RuntimeException('Statamic Git integration is enabled, so a save during the run could reach the repository.');
        }

        // Read while the Stache still answers from the host's directories
        $diskRoots = $this->localAssetDiskRoots();

        if (! File::makeDirectory($this->root, 0o775, true)) {
            throw new RuntimeException("The directory '{$this->root}' could not be created.");
        }

        // First, so nothing later can read a path from the host's cache
        $this->moveCaches();
        $this->copyDirectories($diskRoots);
        $this->moveStores();
        $this->moveTemplates();
        $this->moveDisks($diskRoots);

        // Forget everything held in memory from before the move
        Stache::clear();
        Blink::flush();

        $this->assertContained();
    }

    /**
     * The root of each disk an asset container uses.
     *
     * @return array<string, string> The roots, keyed by disk handle
     *
     * @throws RuntimeException When a container's disk is not on the local filesystem
     */
    private function localAssetDiskRoots(): array
    {
        $roots = [];

        foreach (AssetContainer::all() as $container) {
            if (! $container instanceof StatamicAssetContainer) {
                continue;
            }

            $handle = $container->diskHandle();

            if (! \is_string($handle) || $handle === '') {
                continue;
            }

            $root = config("filesystems.disks.{$handle}.root");

            // A remote disk cannot be copied here, and its writes would land on the real thing
            if (config("filesystems.disks.{$handle}.driver") !== 'local' || ! \is_string($root) || $root === '') {
                throw new RuntimeException("The asset disk '{$handle}' is not a local disk, so it cannot be copied.");
            }

            $roots[$handle] = $root;
        }

        return $roots;
    }

    /**
     * Point the Stache and the application cache at an empty in-memory store.
     */
    private function moveCaches(): void
    {
        foreach (self::CONFIG_KEYS as $key) {
            $this->savedConfig[$key] = config($key);
        }

        config([
            'statamic.stache.cache_store' => self::CACHE_STORE,
            'cache.default' => self::CACHE_STORE,
        ]);

        Cache::store(self::CACHE_STORE)->clear();

        $this->reloadStoreState();
    }

    /**
     * Drop what each store holds in memory, and read its key map from the current cache.
     */
    private function reloadStoreState(): void
    {
        foreach (Stache::stores() as $store) {
            $this->reloadStore($store);

            $children = $store instanceof AggregateStore ? $store->stores() : null;

            if (! $children instanceof Collection) {
                continue;
            }

            foreach ($children as $child) {
                if ($child instanceof Store) {
                    $this->reloadStore($child);
                }
            }
        }
    }

    /**
     * Drop what one store holds in memory, and read its key map from the current cache.
     *
     * @param  Store  $store  The store
     */
    private function reloadStore(Store $store): void
    {
        $keys = $store->keys();

        if ($keys instanceof Keys) {
            $keys->load();
        }

        $store->resetMemoizedState();
    }

    /**
     * Copy every directory the run writes, outermost first.
     *
     * A disk nested in another (`public/assets/images` in `public/assets`) resolves inside the
     * outer copy, so each file is copied once and the two disks keep seeing the same file.
     *
     * @param  array<string, string>  $diskRoots  The asset disk roots, keyed by handle
     *
     * @throws RuntimeException When a directory cannot be copied
     */
    private function copyDirectories(array $diskRoots): void
    {
        $sources = \array_merge($diskRoots, $this->templateDirectories());

        foreach ($this->stores() as $store) {
            $sources[] = $this->storeDirectory($store);
        }

        $sources = \array_map(self::normalize(...), \array_values($sources));

        \usort($sources, static fn (string $a, string $b): int => \strlen($a) <=> \strlen($b));

        foreach ($sources as $source) {
            if ($this->copyOf($source) !== null) {
                continue;
            }

            $copy = $this->root.'/'.\count($this->copies);

            $copied = \is_dir($source)
                ? File::copyDirectory($source, $copy)
                : File::makeDirectory($copy, 0o775, true);

            if (! $copied) {
                throw new RuntimeException("The directory '{$source}' could not be copied to '{$copy}'.");
            }

            $this->copies[$source] = $copy;
        }
    }

    /**
     * Repoint every store that has a directory of its own.
     *
     * @throws RuntimeException When a directory has no copy
     */
    private function moveStores(): void
    {
        foreach ($this->stores() as $store) {
            $directory = $this->storeDirectory($store);

            $this->savedStores[] = ['store' => $store, 'directory' => $directory];

            $store->directory($this->requireCopyOf($directory));
        }
    }

    /**
     * Repoint the blueprints, the fieldsets and the forms.
     *
     * @throws RuntimeException When a directory has no copy
     */
    private function moveTemplates(): void
    {
        $directories = $this->templateDirectories();

        $this->savedBlueprintDirectory = $directories['blueprints'];
        Blueprint::setDirectory($this->requireCopyOf($directories['blueprints']));

        $this->savedFieldsetDirectory = $directories['fieldsets'];
        Fieldset::setDirectory($this->requireCopyOf($directories['fieldsets']));

        config(['statamic.forms.forms' => $this->requireCopyOf($directories['forms'])]);
    }

    /**
     * Repoint the asset disks, dropping each one Laravel has already built.
     *
     * @param  array<string, string>  $diskRoots  The asset disk roots, keyed by handle
     *
     * @throws RuntimeException When a directory has no copy
     */
    private function moveDisks(array $diskRoots): void
    {
        foreach ($diskRoots as $handle => $root) {
            $this->savedDiskRoots[$handle] = $root;

            config(["filesystems.disks.{$handle}.root" => $this->requireCopyOf($root)]);
            Storage::forgetDisk($handle);
        }
    }

    /**
     * Read every moved location back, so a store that ignored the move stops the run.
     *
     * @throws RuntimeException When anything still points outside the workspace
     */
    private function assertContained(): void
    {
        $directories = \array_values($this->templateDirectories());

        foreach ($this->stores() as $store) {
            $directories[] = $this->storeDirectory($store);
        }

        foreach (\array_keys($this->savedDiskRoots) as $handle) {
            $root = config("filesystems.disks.{$handle}.root");

            $directories[] = \is_string($root) ? $root : '';
        }

        foreach ($directories as $directory) {
            if (! str_starts_with(self::normalize($directory).'/', $this->root.'/')) {
                throw new RuntimeException("'{$directory}' is outside the verification workspace.");
            }
        }
    }

    /**
     * Put back every location and setting that moved.
     */
    private function restore(): void
    {
        foreach ($this->savedStores as $saved) {
            $saved['store']->directory($saved['directory']);
        }

        if ($this->savedBlueprintDirectory !== null) {
            Blueprint::setDirectory($this->savedBlueprintDirectory);
        }

        if ($this->savedFieldsetDirectory !== null) {
            Fieldset::setDirectory($this->savedFieldsetDirectory);
        }

        foreach ($this->savedDiskRoots as $handle => $root) {
            config(["filesystems.disks.{$handle}.root" => $root]);
            Storage::forgetDisk($handle);
        }

        foreach ($this->savedConfig as $key => $value) {
            config([$key => $value]);
        }

        if ($this->savedConfig !== []) {
            Cache::store(self::CACHE_STORE)->clear();

            $this->reloadStoreState();
        }

        Blink::flush();

        $this->savedStores = [];
        $this->savedBlueprintDirectory = null;
        $this->savedFieldsetDirectory = null;
        $this->savedDiskRoots = [];
        $this->savedConfig = [];
    }

    /**
     * The stores that own a directory. A child store answers from its parent.
     *
     * @return list<Store>
     */
    private function stores(): array
    {
        $stores = [];

        foreach (Stache::stores() as $store) {
            // Statamic documents a string, and `assets`, which has no directory, answers null
            if ((bool) $store->directory()) {
                $stores[] = $store;
            }
        }

        return $stores;
    }

    /**
     * A store's directory.
     *
     * @param  Store  $store  The store
     * @return string The directory as the store holds it
     */
    private function storeDirectory(Store $store): string
    {
        return $store->directory();
    }

    /**
     * Where the blueprints, the fieldsets and the forms are stored now.
     *
     * @return array{blueprints: string, fieldsets: string, forms: string}
     */
    private function templateDirectories(): array
    {
        $forms = config('statamic.forms.forms');

        return [
            'blueprints' => Blueprint::directory(),
            'fieldsets' => Fieldset::directory(),
            'forms' => \is_string($forms) ? $forms : '',
        ];
    }

    /**
     * The workspace's copy of a directory, inside a copy of a directory that holds it if so.
     *
     * @param  string  $directory  The source directory
     * @return ?string The copy, or null when it has not been copied
     */
    private function copyOf(string $directory): ?string
    {
        $source = self::normalize($directory);

        foreach ($this->copies as $copied => $copy) {
            if ($source === $copied || str_starts_with($source, $copied.'/')) {
                return $copy.\substr($source, \strlen($copied));
            }
        }

        return null;
    }

    /**
     * The workspace's copy of a directory that must have one.
     *
     * @param  string  $directory  The source directory
     * @return string The copy
     *
     * @throws RuntimeException When the directory has no copy
     */
    private function requireCopyOf(string $directory): string
    {
        return $this->copyOf($directory)
            ?? throw new RuntimeException("The directory '{$directory}' has no copy in the verification workspace.");
    }

    /**
     * A path with forward slashes and no trailing separator.
     *
     * @param  string  $path  The path
     * @return string The normalized path
     */
    private static function normalize(string $path): string
    {
        return \rtrim(Path::tidy($path), '/');
    }
}
