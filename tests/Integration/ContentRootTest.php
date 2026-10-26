<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File as IlluminateFileFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Stache as StacheFacade;
use Statamic\Stache\Stores\Store;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Structures\TreeBuilder;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * The path `ContentRoot` will return for `$path`, with separators normalised.
 *
 * `ContentRoot::storeDirectory()` normalises to forward slashes and trims a trailing
 * separator. On Windows `sys_get_temp_dir()` and `base_path()` both return backslashes,
 * so a literal `.'/sub'` appended to either produces a MIXED string that no normalising
 * resolver can ever return -- which is why seven expectations in this file failed there
 * and nowhere else.
 *
 * **Applied to the EXPECTATION, never to the value handed to `withStoreDirectory()`.**
 * The store directory is the thing `ContentRoot` normalises; passing it an already-clean
 * path makes that `str_replace` a no-op, so a regression removing it would go undetected
 * on both platforms. The raw, backslash-bearing path is the realistic input and stays.
 *
 * @return non-empty-string
 */
function contentRootPath(string $path): string
{
    $normalised = \rtrim(\str_replace('\\', '/', $path), '/');

    // A path that normalises away entirely would make `toStartWith()` below vacuous,
    // which is the same defect this file exists to close one level down. Refusing is
    // also what earns the `non-empty-string` the expectation helpers require.
    if ($normalised === '') {
        throw new RuntimeException('contentRootPath() was given a path that normalises to nothing: '.$path);
    }

    return $normalised;
}

/**
 * The system temp directory with any trailing separator removed, as the base for every fixture path.
 *
 * `sys_get_temp_dir()` ends in a separator on some machines -- `/var/tmp/` under Herd's
 * PHP on macOS with `TMPDIR` unset -- so a literal `.'/sub'` appended to it doubles the
 * separator. `ContentRoot` returns a single one, and an expectation built from the doubled
 * form fails for a reason that has nothing to do with the class under test (wordpress-importer-archive#1254).
 *
 * Only the trailing separator is trimmed. An interior backslash on Windows is kept, so
 * the value handed to `withStoreDirectory()` is still the raw input the note on
 * `contentRootPath()` requires.
 *
 * @return non-empty-string
 */
function contentRootTempBase(): string
{
    $base = \rtrim(\sys_get_temp_dir(), '/\\');

    // A temp directory of `/` would trim to nothing and root every fixture at the filesystem root
    if ($base === '') {
        throw new RuntimeException('sys_get_temp_dir() trims to nothing: '.\sys_get_temp_dir());
    }

    return $base;
}

/**
 * Re-point a Stache store for the duration of one test, then put it back.
 *
 * The host re-roots these stores under `APP_ENV=testing`; re-pointing one here
 * is the same move, and it is what distinguishes a resolver that follows the
 * store from one that has quietly gone back to `base_path()`.
 */
function withStoreDirectory(string $storeKey, string $directory, callable $body): void
{
    $store = StacheFacade::store($storeKey);
    \assert($store instanceof Store);
    $original = $store->directory();

    try {
        $store->directory($directory);
        $body();
    } finally {
        $store->directory($original);
    }
}

it('resolves the collections directory from the entries store, not base_path', function (): void {
    $mirror = contentRootTempBase().'/wpimport-622-collections';

    withStoreDirectory('entries', $mirror, function () use ($mirror): void {
        expect(ContentRoot::collections())->toBe(contentRootPath($mirror))
            ->and(ContentRoot::collections())->not->toContain(base_path('content'));
    });
});

it('resolves the taxonomies directory from the taxonomies store, not base_path', function (): void {
    $mirror = contentRootTempBase().'/wpimport-622-taxonomies';

    withStoreDirectory('taxonomies', $mirror, function () use ($mirror): void {
        expect(ContentRoot::taxonomies())->toBe(contentRootPath($mirror))
            ->and(ContentRoot::taxonomies())->not->toContain(base_path('content'));
    });
});

it('returns the store directory without a trailing separator so callers append their own', function (): void {
    withStoreDirectory('entries', contentRootTempBase().'/wpimport-622-slash/', function (): void {
        expect(ContentRoot::collections())->not->toEndWith('/');
    });
});

it('re-roots a content-relative path under the owning store', function (): void {
    $mirror = contentRootTempBase().'/wpimport-622-resolve';

    withStoreDirectory('entries', $mirror, function () use ($mirror): void {
        expect(ContentRoot::resolve('content/collections/posts'))->toBe(contentRootPath($mirror).'/posts');
    });
});

it('matches the longest owning subdirectory so trees do not resolve as collections', function (): void {
    $collections = contentRootTempBase().'/wpimport-622-c';
    $trees = contentRootTempBase().'/wpimport-622-t';

    withStoreDirectory('entries', $collections, function () use ($trees): void {
        withStoreDirectory('collection-trees', $trees, function () use ($trees): void {
            expect(ContentRoot::resolve('content/trees/collections/default'))->toBe(contentRootPath($trees).'/default');
        });
    });
});

it('is a no-op in production, where the store already sits under base_path', function (): void {
    // Production configures every content store at `base_path('content/…')`, so
    // resolving through the store must land exactly where `base_path()` did
    // before this indirection existed.
    withStoreDirectory('entries', base_path('content/collections'), function (): void {
        expect(ContentRoot::resolve('content/collections/posts'))
            ->toBe(contentRootPath(base_path('content/collections/posts')))
            ->and(ContentRoot::collections())
            ->toBe(contentRootPath(base_path('content/collections')));
    });
});

it('composes a collection entry path under the owning store', function (): void {
    $mirror = contentRootTempBase().'/wpimport-724-entry';

    withStoreDirectory('entries', $mirror, function () use ($mirror): void {
        expect(ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-7'))
            ->toBe(contentRootPath($mirror).'/tables/default/default_tablepress-7.md');
    });
});

it('resolves a collection entry away from base_path when the store moves', function (): void {
    // The wordpress-importer-archive#724 defect: these paths were relative strings against Statamic's
    // default disk, which is rooted at the app root and so never followed the
    // test-env re-root at all.
    $mirror = contentRootTempBase().'/wpimport-724-moved';

    withStoreDirectory('entries', $mirror, function (): void {
        expect(ContentRoot::collectionEntry('tables', 'default', 'x'))
            ->not->toContain(base_path('content'))
            ->and(ContentRoot::collectionEntry('tables', 'default', 'x'))
            ->toStartWith(contentRootPath(contentRootTempBase().'/wpimport-724-moved'));
    });
});

it('resolves a collection entry exactly where the old disk path did in production', function (): void {
    // In production the store sits at base_path('content/collections'), so the
    // absolute path must equal what a `content/collections/...`-relative path
    // resolved to against the app-rooted disk. That equality is what makes
    // wordpress-importer-archive#724 a pure re-rooting rather than a behaviour change.
    withStoreDirectory('entries', base_path('content/collections'), function (): void {
        expect(ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-7'))
            ->toBe(contentRootPath(base_path('content/collections/tables/default/default_tablepress-7.md')));
    });
});

it('finds a table entry written where the run writes it', function (): void {
    // wordpress-importer-archive#724 AC: the retry loop in EntryCreator must find an entry the same run
    // wrote. Nothing drives `importTables()` — it is called only from inside a
    // real import — so the resolution it depends on is asserted here instead:
    // write where the run writes, resolve, and find it. Against the old
    // app-rooted disk path this file would have been invisible.
    $runRoot = contentRootTempBase().'/wpimport-724-roundtrip-'.\uniqid();
    $entryDir = $runRoot.'/tables/default';
    \mkdir($entryDir, 0777, true);
    \file_put_contents($entryDir.'/default_tablepress-7.md', "---\nid: tbl-724\n---\n");

    withStoreDirectory('entries', $runRoot, function () use ($runRoot): void {
        $resolved = ContentRoot::collectionEntry('tables', 'default', 'default_tablepress-7');

        expect(IlluminateFileFacade::exists($resolved))->toBeTrue()
            ->and($resolved)->toStartWith(contentRootPath($runRoot))
            // The path the old code built, resolved against the app root, is not this file
            ->and(IlluminateFileFacade::exists(base_path('content/collections/tables/default/default_tablepress-7.md')))
            ->toBeFalse();
    });

    IlluminateFileFacade::deleteDirectory($runRoot);
});

it('leaves paths outside the content tree on base_path', function (): void {
    expect(ContentRoot::resolve('public/assets/images'))->toBe(base_path('public/assets/images'));
});

it('fails visibly when no store owns a content subdirectory', function (): void {
    expect(fn (): string => ContentRoot::resolve('content/not-a-store/thing'))
        ->toThrow(
            RuntimeException::class,
            "ContentRoot: no Statamic store owns 'content/not-a-store/thing'. "
            .'Add its store to ContentRoot::STORE_BY_SUBDIRECTORY rather than '
            .'falling back to base_path(), which does not follow the test-env re-root.'
        );
});

it('fails visibly for the content root itself, which no store owns', function (): void {
    expect(fn (): string => ContentRoot::resolve('content'))
        ->toThrow(RuntimeException::class, "no Statamic store owns 'content/'");
});

it('does not read a sibling that only begins with a store subdirectory as that store', function (): void {
    expect(fn (): string => ContentRoot::resolve('content/collectionsx/posts'))
        ->toThrow(RuntimeException::class, "no Statamic store owns 'content/collectionsx/posts'");
});

it('resolves a store subdirectory itself to the store directory', function (): void {
    $mirror = contentRootTempBase().'/wpimport-1314-exact';

    withStoreDirectory('entries', $mirror, function () use ($mirror): void {
        expect(ContentRoot::resolve('content/collections'))->toBe(contentRootPath($mirror));
    });
});

it('normalizes backslashes and surrounding slashes in the path it resolves', function (): void {
    $mirror = contentRootTempBase().'/wpimport-1314-normalize';

    withStoreDirectory('entries', $mirror, function () use ($mirror): void {
        expect(ContentRoot::resolve('content\\collections\\posts'))->toBe(contentRootPath($mirror).'/posts')
            ->and(ContentRoot::resolve('/content/collections/posts/'))->toBe(contentRootPath($mirror).'/posts');
    });
});

it('confines the duplicate-entry cleanup to the tree the run writes to', function (): void {
    // A stand-in for the run's content root, and a stand-in for the committed
    // submodule the cleanup must never reach
    $runRoot = contentRootTempBase().'/wpimport-622-run-'.\uniqid();
    $submodule = contentRootTempBase().'/wpimport-622-committed-'.\uniqid();
    $runDir = $runRoot.'/pages/default';
    $submoduleDir = $submodule.'/pages/default';
    \mkdir($runDir, 0777, true);
    \mkdir($submoduleDir, 0777, true);

    // Two files in the run's tree sharing one entry id — a real duplicate
    \file_put_contents($runDir.'/alpha.md', "---\nid: dupe-622\ntitle: Alpha\n---\n");
    \file_put_contents($runDir.'/beta.md', "---\nid: dupe-622\ntitle: Beta\n---\n");

    // The same duplicate shape in the committed tree, which must survive untouched
    \file_put_contents($submoduleDir.'/alpha.md', "---\nid: dupe-622\ntitle: Alpha\n---\n");
    \file_put_contents($submoduleDir.'/beta.md', "---\nid: dupe-622\ntitle: Beta\n---\n");

    // Keep the collection Statamic saves out of the repo's fixture tree
    $collectionsDir = contentRootTempBase().'/wpimport-622-config-'.\uniqid();
    \mkdir($collectionsDir, 0777, true);

    withStoreDirectory('collections', $collectionsDir, function () use ($runRoot, $runDir, $submoduleDir): void {
        // The cleanup reads the collection through Statamic, so it has to exist
        $collection = CollectionFacade::make('pages');
        $collection->sites(['default']);
        $collection->save();

        withStoreDirectory('entries', $runRoot, function () use ($runDir, $submoduleDir): void {
            $builder = app(TreeBuilder::class);
            $cleanup = (new ReflectionClass($builder))->getMethod('cleanupDuplicateEntryFiles');
            $cleanup->setAccessible(true);
            $cleanup->invoke($builder, 'pages', 'default', new BufferedOutput);

            // It de-duplicated the tree the run wrote to…
            expect(\glob($runDir.'/*.md'))->toHaveCount(1)
                // …and left the committed tree entirely alone
                ->and(\glob($submoduleDir.'/*.md'))->toHaveCount(2);
        });
    });

    IlluminateFileFacade::deleteDirectory($runRoot);
    IlluminateFileFacade::deleteDirectory($submodule);
    IlluminateFileFacade::deleteDirectory($collectionsDir);
});

it('fails visibly when the owning store is unavailable', function (mixed $store): void {
    StacheFacade::shouldReceive('store')->with('entries')->andReturn($store);

    expect(fn (): string => ContentRoot::collections())
        ->toThrow(
            RuntimeException::class,
            "ContentRoot: the Stache store 'entries' is unavailable, so the "
            .'content root cannot be resolved.'
        );
})->with([
    'no store' => [null],
    'an object with no directory method' => [new stdClass],
]);

it('fails visibly when the owning store has no directory', function (mixed $directory): void {
    StacheFacade::shouldReceive('store')->with('taxonomies')->andReturn(new class($directory)
    {
        /**
         * Hold the directory this stand-in store reports.
         */
        public function __construct(private mixed $configured) {}

        /**
         * Report the configured directory.
         */
        public function directory(): mixed
        {
            return $this->configured;
        }
    });

    expect(fn (): string => ContentRoot::taxonomies())
        ->toThrow(RuntimeException::class, "the Stache store 'taxonomies' has no configured directory");
})->with([
    'an empty directory' => [''],
    'a directory that is not a string' => [null],
]);

it('normalizes backslashes in a store directory to forward slashes', function (): void {
    StacheFacade::shouldReceive('store')->with('entries')->andReturn(new class
    {
        /**
         * Report a Windows-style directory with a trailing separator.
         */
        public function directory(): string
        {
            return 'C:\\site\\content\\collections\\';
        }
    });

    expect(ContentRoot::collections())->toBe('C:/site/content/collections');
});
