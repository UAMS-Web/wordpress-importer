<?php

declare(strict_types=1);

use App\Services\BardContentSanitizer;
use Illuminate\Console\OutputStyle;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Statamic\Events\AssetDeleted;
use Statamic\Events\TermSaved;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\FormidableFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\GravityFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\NinjaFormsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Loader as MinervaKbSettingsLoader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\NavigationMenuSelector;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentRoot;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

// The sanitizer is the host app's; standalone, the package's stub stands in for it
if (! class_exists(BardContentSanitizer::class)) {
    require_once __DIR__.'/../Support/IdeStubs/App/Services/BardContentSanitizer.php';
}

/**
 * Tests the `ImportWordPress` command's flow before the import itself: running the import with
 * Statamic's asset-reference and search-index sweeps disabled, choosing the content source,
 * refusing the options and files it cannot use, resolving a split export's parts, reading the
 * unsupported-content policy, and sweeping away the dataless entry files a run materialized.
 *
 * The command is built by the container, so its options are its own, and is a subclass that
 * stands in for the import of the resolved parts, recording what it is handed, and for the
 * migration API's materializer. The loaders it clears are recording stand-ins.
 *
 * @command  php vendor/bin/pest tests/Integration/ImportWordPressCommandFlowTest.php
 */

/**
 * The command, standing in for the import of the parts and for the materializer.
 */
final class IwcfCommand extends ImportWordPress
{
    /**
     * The parts each import was handed.
     *
     * @var list<list<string>>
     */
    public array $imports = [];

    /**
     * The listener counts seen while the import ran: search-index and asset-reference.
     *
     * @var list<array{int, int}>
     */
    public array $listenersDuring = [];

    /**
     * The status the import answers.
     */
    public int $status = 7;

    /**
     * {@inheritDoc}
     */
    protected function runImportForParts(array $parts): int
    {
        $this->imports[] = $parts;
        $this->listenersDuring[] = [count(Event::getListeners(TermSaved::class)), count(Event::getListeners(AssetDeleted::class))];

        return $this->status;
    }

    /**
     * {@inheritDoc}
     */
    protected function makeMaterializer(Client $client, string $destination): WxrMaterializer
    {
        return new class($client, $destination) extends WxrMaterializer
        {
            /**
             * Create the materializer, remembering the directory.
             */
            public function __construct(Client $client, private readonly string $directory)
            {
                parent::__construct($client, $directory);
            }

            /**
             * {@inheritDoc}
             */
            public function materialize(?int $site = null, ?string $modifiedAfter = null, array $consumedOptions = [], ?string $contentType = null): array
            {
                return [$this->directory.'/wordpress-export.001.xml'];
            }
        };
    }

    /**
     * {@inheritDoc}
     */
    protected function apiKeyStream()
    {
        $stream = fopen('php://memory', 'r+');
        assert(is_resource($stream));
        fwrite($stream, "secret-key\n");
        rewind($stream);

        return $stream;
    }
}

/**
 * Records whether a loader was told to forget its migration API source.
 */
trait IwcfRecordsClear
{
    /**
     * How many times the source was cleared.
     */
    public int $cleared = 0;

    /**
     * {@inheritDoc}
     */
    public function clearMigrationApiSource(): void
    {
        $this->cleared++;
    }
}

/**
 * A MinervaKB settings loader that records being cleared.
 */
final class IwcfMinerva extends MinervaKbSettingsLoader
{
    use IwcfRecordsClear;
}

/**
 * A navigation selector that records being cleared.
 */
final class IwcfNavigation extends NavigationMenuSelector
{
    use IwcfRecordsClear;
}

/**
 * A Gravity Forms loader that records being cleared.
 */
final class IwcfGravity extends GravityFormsLoader
{
    use IwcfRecordsClear;
}

/**
 * A Ninja Forms loader that records being cleared.
 */
final class IwcfNinja extends NinjaFormsLoader
{
    use IwcfRecordsClear;
}

/**
 * A Formidable Forms loader that records being cleared.
 */
final class IwcfFormidable extends FormidableFormsLoader
{
    use IwcfRecordsClear;
}

beforeEach(function (): void {
    MaterializationScope::clear();
    ImportWarningManager::clearWarnings();
    $this->root = sys_get_temp_dir().'/iwcf-'.bin2hex(random_bytes(6));
    mkdir($this->root);
});

afterEach(function (): void {
    MaterializationScope::clear();
    ImportWarningManager::clearWarnings();
    exec('rm -rf '.escapeshellarg($this->root));
});

/**
 * The command, built by the container with recording loaders and the given arguments and
 * options, and the buffer behind its output.
 *
 * @param  array<string, mixed>  $parameters  The arguments and options, as `ArrayInput` takes them
 * @return array{IwcfCommand, BufferedOutput, list<IwcfMinerva|IwcfNavigation|IwcfGravity|IwcfNinja|IwcfFormidable>}
 */
function iwcfCommand(array $parameters = [], bool $interactive = false): array
{
    $command = app(IwcfCommand::class);
    $loaders = [
        'minervaKbSettingsLoader' => new IwcfMinerva,
        'navigationMenuSelector' => (new ReflectionClass(IwcfNavigation::class))->newInstanceWithoutConstructor(),
        'gravityFormsLoader' => new IwcfGravity,
        'ninjaFormsLoader' => new IwcfNinja,
        'formidableFormsLoader' => new IwcfFormidable,
    ];

    foreach ($loaders as $property => $loader) {
        (new ReflectionProperty(ImportWordPress::class, $property))->setValue($command, $loader);
    }

    $input = new ArrayInput($parameters, $command->getDefinition());
    $input->setInteractive($interactive);
    $buffer = new BufferedOutput;
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $buffer));

    return [$command, $buffer, array_values($loaders)];
}

/**
 * Call one of the command's private methods.
 */
function iwcfCall(ImportWordPress $command, string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(ImportWordPress::class, $method))->invoke($command, ...$arguments);
}

/**
 * The buffer's output, with plain line endings.
 */
function iwcfOutput(BufferedOutput $buffer): string
{
    return str_replace(PHP_EOL, "\n", $buffer->fetch());
}

/**
 * Write a file the run reads as an export, and answer its path.
 */
function iwcfFile(string $path): string
{
    file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><rss><channel></channel></rss>');

    return $path;
}

it('runs the import with the search-index and asset-reference sweeps disabled, then restores them', function (): void {
    $file = iwcfFile($this->root.'/site.xml');
    [$command] = iwcfCommand(['file' => $file]);
    $before = [count(Event::getListeners(TermSaved::class)), count(Event::getListeners(AssetDeleted::class))];
    $startedBefore = microtime(true);

    $status = $command->handle();

    expect($status)->toBe(7)
        ->and($command->imports)->toBe([[$file]])
        ->and($command->listenersDuring[0][0])->toBeLessThan($before[0])
        ->and($command->listenersDuring[0][1])->toBeLessThan($before[1])
        ->and([count(Event::getListeners(TermSaved::class)), count(Event::getListeners(AssetDeleted::class))])->toBe($before)
        ->and((new ReflectionProperty(ImportWordPress::class, 'runStartedAt'))->getValue($command))->toBeGreaterThanOrEqual($startedBefore);
});

it('marks a command that has not run as never started', function (): void {
    $command = (new ReflectionClass(ImportWordPress::class))->newInstanceWithoutConstructor();

    expect((new ReflectionProperty(ImportWordPress::class, 'runStartedAt'))->getValue($command))->toBe(0.0);
});

it('refuses a source it does not know', function (): void {
    [$command, $buffer] = iwcfCommand(['--source' => 'ftp']);

    expect(iwcfCall($command, 'runImport'))->toBe(1)
        ->and($command->imports)->toBe([])
        ->and(iwcfOutput($buffer))->toBe("Unknown --source 'ftp'. Use 'wxr' or 'api'.\n");
});

it('reads the source in any case', function (): void {
    $file = iwcfFile($this->root.'/site.xml');
    [$command] = iwcfCommand(['file' => $file, '--source' => 'WXR']);

    expect(iwcfCall($command, 'runImport'))->toBe(7)
        ->and($command->imports)->toBe([[$file]]);
});

it('refuses a MinervaKB generation for a file-sourced run', function (): void {
    [$command, $buffer] = iwcfCommand(['file' => iwcfFile($this->root.'/site.xml'), '--minerva-generation' => 'v2']);

    expect(iwcfCall($command, 'runImport'))->toBe(1)
        ->and($command->imports)->toBe([])
        ->and(iwcfOutput($buffer))->toBe("The --minerva-generation option applies only with --source=api.\n");
});

it('accepts an empty MinervaKB generation for a file-sourced run', function (): void {
    [$command] = iwcfCommand(['file' => iwcfFile($this->root.'/site.xml'), '--minerva-generation' => '']);

    expect(iwcfCall($command, 'runImport'))->toBe(7);
});

it('imports what the migration API materializes', function (): void {
    [$command, $buffer] = iwcfCommand(['file' => $this->root, '--source' => 'api', '--network' => 'https://network.test', '--key-from-stdin' => true, '--minerva-generation' => 'v2']);

    expect(iwcfCall($command, 'runImport'))->toBe(7)
        ->and($command->imports)->toBe([[$this->root.'/wordpress-export.001.xml']])
        ->and(iwcfOutput($buffer))->toContain("Materialized 1 WXR part(s) into {$this->root}\n");
});

it('stops when the migration API materializes nothing', function (): void {
    [$command] = iwcfCommand(['file' => $this->root, '--source' => 'api']);

    expect(iwcfCall($command, 'runImport'))->toBe(1)
        ->and($command->imports)->toBe([]);
});

it('forgets any migration API source and scope before a file-sourced run', function (): void {
    [$command, , $loaders] = iwcfCommand(['file' => iwcfFile($this->root.'/site.xml')]);
    MaterializationScope::set(['page']);

    iwcfCall($command, 'runImport');

    expect(array_map(static fn (object $loader): int => $loader->cleared, $loaders))->toBe([1, 1, 1, 1, 1])
        ->and(MaterializationScope::isScoped())->toBeFalse();
});

it('refuses a file-sourced run with no file', function (array $parameters): void {
    /** @var array<string, mixed> $parameters */
    [$command, $buffer] = iwcfCommand($parameters);

    expect(iwcfCall($command, 'runImport'))->toBe(1)
        ->and(iwcfOutput($buffer))->toBe("A valid XML file path is required.\n");
})->with([
    'none given' => [[]],
    'an empty one' => [['file' => '']],
]);

it('refuses a file that does not exist', function (): void {
    [$command, $buffer] = iwcfCommand(['file' => $this->root.'/missing.xml']);

    expect(iwcfCall($command, 'runImport'))->toBe(1)
        ->and(iwcfOutput($buffer))->toBe("The file '{$this->root}/missing.xml' does not exist.\n");
});

it('imports every part of a split export as one import, saying so', function (): void {
    $first = iwcfFile($this->root.'/site.000.xml');
    $second = iwcfFile($this->root.'/site.001.xml');
    [$command, $buffer] = iwcfCommand(['file' => $second]);

    expect(iwcfCall($command, 'runImport'))->toBe(7)
        ->and($command->imports)->toBe([[$first, $second]])
        ->and(iwcfOutput($buffer))->toBe("Detected split export: importing 2 parts as a single import.\n");
});

it('imports a single file with nothing said about parts', function (): void {
    $file = iwcfFile($this->root.'/site.xml');
    [$command, $buffer] = iwcfCommand(['file' => $file]);

    expect(iwcfCall($command, 'runImport'))->toBe(7)
        ->and($command->imports)->toBe([[$file]])
        ->and(iwcfOutput($buffer))->toBe('');
});

it('refuses a split export missing a part, naming each missing part', function (): void {
    $first = iwcfFile($this->root.'/site.000.xml');
    iwcfFile($this->root.'/site.002.xml');
    iwcfFile($this->root.'/site.004.xml');
    [$command, $buffer] = iwcfCommand(['file' => $first]);

    expect(iwcfCall($command, 'runImport'))->toBe(1)
        ->and($command->imports)->toBe([])
        ->and(iwcfOutput($buffer))->toBe("Split export is missing part(s): .001.xml, .003.xml. Refusing to import a partial set \u{2014} restore the missing part(s) and retry.\n");
});

it('reads the unsupported-content policy, refusing one it does not know', function (?string $policy, ?string $resolved): void {
    [$command] = iwcfCommand($policy === null ? [] : ['--unsupported-content' => $policy]);

    expect(iwcfCall($command, 'resolveUnsupportedContentPolicy'))->toBe($resolved);
})->with([
    'the default' => [null, 'abort'],
    'abort' => ['abort', 'abort'],
    'skip' => ['skip', 'skip'],
    'import' => ['import', 'import'],
    'another' => ['ignore', null],
    'one in capitals' => ['SKIP', null],
]);

it('removes the dataless entry files this run made, and reports each', function (): void {
    $root = ContentRoot::collections();
    @mkdir($root.'/pages/default', 0777, true);
    $dataless = $root.'/pages/default/invented.md';
    $older = $root.'/pages/default/older.md';
    $kept = $root.'/pages/default/kept.md';
    file_put_contents($dataless, "---\nid: invented-1\nblueprint: page\n---\n");
    file_put_contents($older, "---\nid: older-1\nblueprint: page\n---\n");
    file_put_contents($kept, "---\nid: kept-1\ntitle: Kept\n---\n");
    touch($older, time() - 3600);
    [$command, $buffer] = iwcfCommand();
    (new ReflectionProperty(ImportWordPress::class, 'runStartedAt'))->setValue($command, (float) (time() - 60));
    $warnings = [];
    Event::listen(MessageLogged::class, static function (MessageLogged $logged) use (&$warnings): void {
        if ($logged->level === 'warning') {
            $warnings[] = [$logged->message, $logged->context];
        }
    });

    iwcfCall($command, 'sweepDatalessEntries');

    $removed = ['path' => 'pages/default/invented.md', 'collection' => 'pages', 'site' => 'default', 'slug' => 'invented', 'id' => 'invented-1'];

    expect(file_exists($dataless))->toBeFalse()
        ->and(file_exists($older))->toBeTrue()
        ->and(file_exists($kept))->toBeTrue()
        ->and($warnings)->toBe([['ImportWordPress: removed a dataless entry file this run materialized.', $removed]])
        ->and(ImportWarningManager::getWarnings('content', 'dataless_entries_removed'))->toBe([$removed])
        ->and(iwcfOutput($buffer))->toBe("  - Removed 1 dataless entry file(s) this run materialized. See the warning summary.\n");

    unlink($older);
    unlink($kept);
});

it('says nothing when the run made no dataless entry file', function (): void {
    [$command, $buffer] = iwcfCommand();
    (new ReflectionProperty(ImportWordPress::class, 'runStartedAt'))->setValue($command, microtime(true) + 60);

    iwcfCall($command, 'sweepDatalessEntries');

    expect(iwcfOutput($buffer))->toBe('')
        ->and(ImportWarningManager::getWarnings('content', 'dataless_entries_removed'))->toBe([]);
});
