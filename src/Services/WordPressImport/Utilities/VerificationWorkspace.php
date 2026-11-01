<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use ErrorException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Statamic\Assets\AssetContainer as StatamicAssetContainer;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blink;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Glide;
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
 * **Databases are held rather than copied.** Asset metadata and the knowledge base's engagement
 * counts are rows, not files, so each connection a run can write through stays inside one
 * transaction that is rolled back at the end, and a killed run's transaction dies with it.
 *
 * A setup that cannot be fully contained is refused, not attempted, because a partly moved
 * run is the failure this class exists to prevent.
 *
 * Statamic keeps per-run state a store exposes no way to reset (`Store::$usedIndexes`, Blink's
 * named stores), so the process should end after {@see self::leave()}, as the command does.
 */
class VerificationWorkspace
{
    /**
     * The cache store a verification run reads and writes, which Laravel configures by default.
     */
    private const string CACHE_STORE = 'array';

    /**
     * The disk that holds a run's image derivatives, inside the workspace.
     */
    private const string IMAGE_CACHE_DISK = 'wordpress_import_verification_images';

    /**
     * The cache store Statamic keeps its image cache manifest in.
     */
    private const string IMAGE_CACHE_STORE = 'glide';

    /**
     * The configuration this class changes, restored when the run ends.
     *
     * @var list<string>
     */
    private const array CONFIG_KEYS = [
        'statamic.stache.cache_store',
        'cache.default',
        'statamic.forms.forms',
        'statamic.git.automatic',
        'statamic.assets.image_manipulation.generate_presets_on_upload',
        'statamic.assets.image_manipulation.cache',
        'filesystems.disks.'.self::IMAGE_CACHE_DISK,
        'cache.stores.'.self::IMAGE_CACHE_STORE,
        'queue.default',
        'asset_metadata.yaml_directory',
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
     * The connections holding a transaction for this run.
     *
     * @var list<Connection>
     */
    private array $heldConnections = [];

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
        } catch (Throwable $throwable) {
            // Whatever moved before the failure is moved back, then the failure is the caller's
            $workspace->leave();

            // A failed filesystem call arrives as a PHP warning Laravel has turned into this
            throw $throwable instanceof ErrorException ? new RuntimeException($throwable->getMessage(), 0, $throwable) : $throwable;
        }

        // The command's `terminate()` calls `exit`, which runs no `finally` block. A second
        // leave() finds nothing saved and no directory, so a normal run's hook does nothing.
        \register_shutdown_function($workspace->leave(...));

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
        } catch (Throwable $throwable) {
            // The stores go back regardless, and the run's own failure must not be replaced
            Log::warning("Verification workspace: the Stache could not be cleared: {$throwable->getMessage()}");
        }

        $this->restore();

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
        // Read while the Stache still answers from the host's directories
        $diskRoots = $this->localAssetDiskRoots();

        if (! File::makeDirectory($this->root, 0o775, true)) {
            throw new RuntimeException("The directory '{$this->root}' could not be created.");
        }

        // First, so nothing later can read a path from the host's cache
        $this->moveCaches();
        $this->holdConnections();
        $this->copyDirectories($diskRoots);
        $this->moveStores();
        $this->moveTemplates();
        $this->moveDisks($diskRoots);

        // Forget what the Stache and Blink's default store hold from before the move
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
                throw new RuntimeException('An asset container of an unknown class cannot be copied.');
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
        // Statamic defines this store on first use, so what is saved below is what it would use
        Glide::cacheStore();

        foreach (self::CONFIG_KEYS as $key) {
            $this->savedConfig[$key] = config($key);
        }

        config([
            'statamic.stache.cache_store' => self::CACHE_STORE,
            'cache.default' => self::CACHE_STORE,

            // Read on each save, so a save in the run queues no commit of the host's repository
            'statamic.git.automatic' => false,

            // A run has no use for derivatives, and a focal point change generates them anyway
            'statamic.assets.image_manipulation.generate_presets_on_upload' => false,

            // Saving, replacing and deleting an asset each act on the image cache
            'filesystems.disks.'.self::IMAGE_CACHE_DISK => ['driver' => 'local', 'root' => $this->root.'/image-cache'],
            'statamic.assets.image_manipulation.cache' => self::IMAGE_CACHE_DISK,
            'cache.stores.'.self::IMAGE_CACHE_STORE => ['driver' => self::CACHE_STORE],

            // A worker would run a job later, against the host, naming a deleted file
            'queue.default' => 'sync',
        ]);

        Cache::forgetDriver(self::IMAGE_CACHE_STORE);
        Cache::store(self::CACHE_STORE)->clear();

        $this->reloadStoreState();
    }

    /**
     * Begin a transaction on every connection the run can write through.
     */
    private function holdConnections(): void
    {
        foreach ($this->writableConnections() as $name) {
            $connection = DB::connection($name);

            $connection->beginTransaction();

            $this->heldConnections[] = $connection;
        }
    }

    /**
     * The default connection, and each one behind an Eloquent Statamic repository.
     *
     * @return list<string|null>
     */
    private function writableConnections(): array
    {
        $names = [DB::getDefaultConnection()];
        $repositories = config('statamic.eloquent-driver');

        foreach (\is_array($repositories) ? $repositories : [] as $repository) {
            $model = \is_array($repository) ? ($repository['model'] ?? null) : null;

            // A model reads its connection in its constructor, as the host's Asset does
            if (
                \is_array($repository)
                && ($repository['driver'] ?? null) === 'eloquent'
                && \is_string($model)
                && \is_subclass_of($model, Model::class)
            ) {
                $names[] = (new $model)->getConnectionName();
            }
        }

        return \array_values(\array_unique($names));
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

        $metadata = $this->assetMetadataDirectory();

        if ($metadata !== null) {
            $sources[] = $metadata;
        }

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

        $metadata = $this->assetMetadataDirectory();

        if ($metadata !== null) {
            config(['asset_metadata.yaml_directory' => $this->requireCopyOf($metadata)]);
        }
    }

    /**
     * The directory the host dual-writes asset metadata to, outside every store.
     *
     * @return ?string The directory, or null when the host keeps none
     */
    private function assetMetadataDirectory(): ?string
    {
        $directory = config('asset_metadata.yaml_directory');

        return \is_string($directory) && $directory !== '' ? $directory : null;
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

        $metadata = $this->assetMetadataDirectory();

        if ($metadata !== null) {
            $directories[] = $metadata;
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
        foreach ($this->heldConnections as $connection) {
            try {
                $connection->rollBack(0);
            } catch (Throwable $e) {
                // A lost connection has already abandoned its transaction
                Log::warning("Verification workspace: the transaction on '{$connection->getName()}' could not be rolled back: {$e->getMessage()}");
            }
        }

        $this->heldConnections = [];

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
            Cache::forgetDriver(self::IMAGE_CACHE_STORE);
            Storage::forgetDisk(self::IMAGE_CACHE_DISK);
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
            // `assets` has no directory, which Statamic registers as null and tidies to '/'
            if (\rtrim($store->directory(), '/') !== '') {
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
