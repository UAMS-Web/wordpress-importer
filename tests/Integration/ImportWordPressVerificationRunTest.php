<?php

declare(strict_types=1);

use App\Services\BardContentSanitizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Statamic\Assets\AssetContainer as ConcreteAssetContainer;
use Statamic\Entries\Entry as ConcreteEntry;
use Statamic\Events\EntrySaved;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\Path;
use Statamic\Facades\Stache;
use Statamic\Globals\GlobalSet as ConcreteGlobalSet;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\VerificationWorkspace;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

// The sanitizer is the host app's; standalone, the package's stub stands in for it
if (! class_exists(BardContentSanitizer::class)) {
    require_once __DIR__.'/../Support/IdeStubs/App/Services/BardContentSanitizer.php';
}

/**
 * Tests the `--verify` run of `statamic:import:wordpress` end to end on a real filesystem.
 *
 * The stores sit in the test's own directory and the Stache cache is file-backed, so a run
 * that reached the "host" directories, or that kept a path from the host's cached index, is
 * caught by a hash of those directories rather than by a stand-in.
 *
 * @command  php vendor/bin/pest tests/Integration/ImportWordPressVerificationRunTest.php
 */
beforeEach(function (): void {
    config([
        'wordpress-import.measure_filesize' => false,
        'filesystems.disks.verify_assets' => ['driver' => 'local', 'root' => verifyDiskRoot()],
        'cache.stores.verify_file' => ['driver' => 'file', 'path' => verifyDiskRoot().'-cache'],
        'statamic.stache.cache_store' => 'verify_file',
    ]);

    File::ensureDirectoryExists(verifyDiskRoot());
    \file_put_contents(verifyDiskRoot().'/kept.txt', 'an asset already on the disk');

    verifyContainer('verify', 'verify_assets');

    Collection::make('redirect_domains')->save();

    $global = new ConcreteGlobalSet;
    $global->handle('redirects');
    $global->sites(['default']);
    $global->save();

    // An entry this run updates, so the path index the host's cache holds is written through
    $existing = Entry::make();
    \assert($existing instanceof ConcreteEntry);
    $existing->collection('redirect_domains');
    $existing->locale('default');
    $existing->data(['title' => 'go.uams.edu/give', 'host' => 'go.uams.edu', 'source' => '/give', 'wordpress_post_id' => '11']);
    $existing->save();

    // Warm the file-backed index, as a host's cache holds it before a command starts
    Stache::warm();
});

afterEach(function (): void {
    File::deleteDirectory(verifyDiskRoot());
    File::deleteDirectory(verifyDiskRoot().'-cache');
    TemporaryFileRegistry::sweep();
});

/**
 * The root of the disk behind the test's asset container.
 */
function verifyDiskRoot(): string
{
    return Path::tidy(\sys_get_temp_dir()).'/wordpress-importer-verify-disk-'.\getmypid();
}

/**
 * Save an asset container on a disk.
 *
 * @param  string  $handle  The container handle
 * @param  string  $disk  The disk handle
 */
function verifyContainer(string $handle, string $disk): void
{
    $container = AssetContainer::make($handle);
    \assert($container instanceof ConcreteAssetContainer);

    $container->disk($disk);
    $container->save();
}

/**
 * A hash of every file a run could change, keyed by path.
 *
 * @return array<string, string>
 */
function verifyListing(): array
{
    $listing = [];

    foreach ([\dirname(ContentRoot::collections()), verifyDiskRoot()] as $directory) {
        foreach (File::allFiles($directory, true) as $file) {
            $listing[Path::tidy($file->getPathname())] = (string) \sha1_file($file->getPathname());
        }
    }

    \ksort($listing);

    return $listing;
}

/**
 * Write a redirect domain export of one rule that updates and one that creates.
 *
 * @return string The export's path
 */
function verifyExport(): string
{
    $meta = static fn (string $key, string $value): string => '<wp:postmeta><wp:meta_key><![CDATA['.$key.']]></wp:meta_key>'
        .'<wp:meta_value><![CDATA['.$value.']]></wp:meta_value></wp:postmeta>';

    $rule = static fn (string $id, string $source): string => '<item><title><![CDATA['.$source.']]></title>'
        .'<wp:post_id>'.$id.'</wp:post_id><wp:status><![CDATA[publish]]></wp:status><wp:menu_order>0</wp:menu_order>'
        .'<wp:post_type><![CDATA[redirect_rule]]></wp:post_type>'
        .$meta('_redirect_rule_from', $source)
        .$meta('_redirect_rule_to', 'https://new.test'.$source)
        .$meta('_redirect_rule_status_code', '301')
        .$meta('_redirect_rule_from_regex', '0')
        .'</item>';

    $path = TemporaryFileRegistry::create('verification_run_', '.xml');

    \file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">'
        .'<channel><title>Site</title><link>https://go.uams.edu</link><wp:wxr_version>1.2</wp:wxr_version>'
        .'<wp:base_site_url>https://go.uams.edu</wp:base_site_url><wp:base_blog_url>https://go.uams.edu</wp:base_blog_url>'
        .$rule('11', '/give').$rule('12', '/news')
        .'</channel></rss>');

    return $path;
}

/**
 * Run the command with no terminal attached.
 *
 * @param  bool  $verify  Whether to pass `--verify`
 * @return array{0: int, 1: string} The exit code and the output
 */
function verifyRun(bool $verify): array
{
    $output = new BufferedOutput;

    $status = Artisan::call('statamic:import:wordpress', \array_merge(
        ['file' => verifyExport(), '--content-type' => 'redirect_rule', '--no-interaction' => true],
        $verify ? ['--verify' => true] : []
    ), $output);

    return [$status, $output->fetch()];
}

/**
 * The output's lines that report what the run stored.
 *
 * @param  string  $output  The command's output
 * @return list<string>
 */
function verifyCounts(string $output): array
{
    $counts = [];

    foreach (\explode("\n", \str_replace("\r", '', $output)) as $line) {
        if (str_starts_with($line, 'Redirect domain ')) {
            $counts[] = $line;
        }
    }

    return $counts;
}

it('prints what a run would store and leaves the directories unchanged', function (): void {
    $before = verifyListing();

    [$status, $output] = verifyRun(true);

    expect($status)->toBe(0)
        ->and($output)->toContain('Redirect domain go.uams.edu: 1 created, 1 updated, 0 disabled, 0 unpublished skipped, 0 shadowed.')
        ->and($output)->toContain('Redirect domain go.uams.edu: 2 stored with a wordpress_post_id.')
        ->and($output)->toContain('Skipping search index rebuild')
        ->and($output)->not->toContain('Laravel cache cleared.')
        ->and($output)->toContain('the temporary copy was removed')
        ->and(verifyListing())->toBe($before);
});

it('changes the directories without the option, which is what the comparison detects', function (): void {
    $before = verifyListing();

    verifyRun(false);

    expect(verifyListing())->not->toBe($before);
});

it('prints the counts a real run prints on the same input', function (): void {
    [, $verified] = verifyRun(true);
    [, $real] = verifyRun(false);

    expect(verifyCounts($verified))->toHaveCount(2)
        ->and(verifyCounts($verified))->toBe(verifyCounts($real));
});

it('leaves the directories unchanged when the run fails partway', function (): void {
    $before = verifyListing();
    /** @var list<string> $savedTo */
    $savedTo = [];

    Event::listen(EntrySaved::class, function (EntrySaved $event) use (&$savedTo): void {
        $entry = $event->entry;
        \assert($entry instanceof ConcreteEntry);

        $path = $entry->path();
        $savedTo[] = \is_string($path) ? $path : '';

        // The second save fails, so the first has reached the workspace
        if (\count($savedTo) === 2) {
            throw new RuntimeException('the source failed mid-run');
        }
    });

    expect(fn () => verifyRun(true))->toThrow(RuntimeException::class, 'the source failed mid-run')
        ->and($savedTo)->toHaveCount(2)
        ->and($savedTo[0])->toContain('wordpress-import-verify-')
        ->and(\is_dir(\dirname($savedTo[0])))->toBeFalse()
        ->and(verifyListing())->toBe($before);
});

it('puts every location and setting back when the run ends', function (): void {
    $directories = Stache::stores()->map(fn ($store) => $store->directory())->all();
    $blueprints = Blueprint::directory();
    $fieldsets = Fieldset::directory();
    $forms = config('statamic.forms.forms');

    verifyRun(true);

    expect(Stache::stores()->map(fn ($store) => $store->directory())->all())->toBe($directories)
        ->and(Blueprint::directory())->toBe($blueprints)
        ->and(Fieldset::directory())->toBe($fieldsets)
        ->and(config('statamic.forms.forms'))->toBe($forms)
        ->and(config('statamic.stache.cache_store'))->toBe('verify_file')
        ->and(config('filesystems.disks.verify_assets.root'))->toBe(verifyDiskRoot());
});

it('refuses an asset disk it cannot copy, and imports nothing', function (): void {
    config(['filesystems.disks.verify_assets' => ['driver' => 's3', 'bucket' => 'b']]);
    $before = verifyListing();

    [$status, $output] = verifyRun(true);

    expect($status)->toBe(1)
        ->and($output)->toContain("The verification run was refused: The asset disk 'verify_assets' is not a local disk")
        ->and($output)->toContain('Nothing was imported.')
        ->and($output)->not->toContain('Redirect domain')
        ->and(verifyListing())->toBe($before);
});

it('refuses a run when Statamic Git integration is enabled', function (): void {
    config(['statamic.git.enabled' => true]);

    expect(fn () => VerificationWorkspace::enter())->toThrow(RuntimeException::class, 'Statamic Git integration is enabled');
});

it('removes a nested disk with the disk that holds it, sharing one copy', function (): void {
    config(['filesystems.disks.verify_nested' => ['driver' => 'local', 'root' => verifyDiskRoot().'/nested']]);
    File::ensureDirectoryExists(verifyDiskRoot().'/nested');
    \file_put_contents(verifyDiskRoot().'/nested/inner.txt', 'held by both disks');

    verifyContainer('verify_nested', 'verify_nested');

    $workspace = VerificationWorkspace::enter();
    $root = $workspace->root();
    $outer = config()->string('filesystems.disks.verify_assets.root');
    $inner = config()->string('filesystems.disks.verify_nested.root');

    try {
        expect($inner)->toBe($outer.'/nested')
            ->and(\file_get_contents($inner.'/inner.txt'))->toBe('held by both disks');
    } finally {
        $removed = $workspace->leave();
    }

    expect($removed)->toBeTrue()
        ->and(\is_dir($root))->toBeFalse();
});
