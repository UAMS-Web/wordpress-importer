<?php

declare(strict_types=1);

/**
 * Tests that `JsonFileLoader` reads a form plugin's JSON export into an array,
 * and reports a missing, unreadable, malformed or non-array file to the console
 * and the log instead of returning it (#1314).
 *
 * Routed to the light Statamic band for the console command and the `Log`
 * facade. The command records its errors rather than printing them.
 *
 * @command  php vendor/bin/pest tests/Integration/JsonFileLoaderTest.php
 */

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Assert;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Prompts\Loaders\JsonFileLoader;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A console command that records the errors it is asked to print.
 */
class JsonLoaderRecordingCommand extends Command
{
    /**
     * @var string The command signature
     */
    protected $signature = 'probe:json-loader';

    /**
     * @var list<string> The errors printed, in order
     */
    public array $errors = [];

    /**
     * Record an error instead of printing it.
     *
     * @param  mixed  $string  The message
     * @param  mixed  $verbosity  Unused
     */
    public function error($string, $verbosity = null): void
    {
        $this->errors[] = \is_string($string) ? $string : '';
    }
}

beforeEach(function (): void {
    $this->directory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('json_loader_', true);
    \mkdir($this->directory);
    $this->command = new JsonLoaderRecordingCommand;
    $this->loader = new JsonFileLoader('GravityForms');
});

afterEach(function (): void {
    $files = \glob($this->directory.'/*');

    foreach (\is_array($files) ? $files : [] as $file) {
        jsonLoaderAllowRead($file);
        @\unlink($file);
    }

    @\rmdir($this->directory);
});

/**
 * Write a file into the test's directory.
 *
 * @param  string  $directory  The directory
 * @param  string  $contents  The contents
 * @return string The file path
 */
function jsonLoaderFile(string $directory, string $contents): string
{
    $path = $directory.'/forms.json';
    \file_put_contents($path, $contents);

    return $path;
}

/**
 * Deny this process read access to a file, and report whether the denial took.
 *
 * Windows ignores `chmod()` for reads, so there the denial is an ACL entry. A process that
 * can read the file anyway (root, say) gets `false`, and the caller skips.
 *
 * @param  string  $path  The file
 * @return bool Whether a read of the file now fails
 */
function jsonLoaderDenyRead(string $path): bool
{
    if (\PHP_OS_FAMILY === 'Windows') {
        \exec('icacls '.\escapeshellarg($path).' /deny '.\escapeshellarg((string) \getenv('USERNAME').':(R)').' 2>NUL');
    } else {
        \chmod($path, 0);
    }

    \clearstatcache();

    // The probe read warns when the denial took; that warning is the expected outcome
    \set_error_handler(static fn (): bool => true);

    try {
        return \file_get_contents($path) === false;
    } finally {
        \restore_error_handler();
    }
}

/**
 * Give this process read access to a file again.
 *
 * @param  string  $path  The file
 */
function jsonLoaderAllowRead(string $path): void
{
    if (\PHP_OS_FAMILY === 'Windows') {
        \exec('icacls '.\escapeshellarg($path).' /remove:d '.\escapeshellarg((string) \getenv('USERNAME')).' 2>NUL');
    } else {
        @\chmod($path, 0644);
    }
}

it('describes its format', function (): void {
    expect($this->loader->getFileExtension())->toBe('json')
        ->and($this->loader->getFormatDescription())->toBe('JSON file');
});

it('reads a JSON array of forms', function (): void {
    $path = jsonLoaderFile($this->directory, '[{"id":1,"title":"Contact"},{"id":2,"title":"Survey"}]');

    expect($this->loader->load($path, $this->command))->toBe([
        ['id' => 1, 'title' => 'Contact'],
        ['id' => 2, 'title' => 'Survey'],
    ])
        ->and($this->command->errors)->toBe([]);
});

it('reads a JSON object as an array', function (): void {
    $path = jsonLoaderFile($this->directory, '{"0":{"id":1}}');

    expect($this->loader->load($path, $this->command))->toBe([['id' => 1]]);
});

it('reports a missing file', function (): void {
    $logSpy = Log::spy();
    $path = $this->directory.'/missing.json';

    expect($this->loader->load($path, $this->command))->toBeNull()
        ->and($this->command->errors)->toBe(["File not found: {$path}"]);

    $logSpy->shouldHaveReceived('error', ['GravityForms\\JsonFileLoader::load: File not found', ['file_path' => $path]]);
});

it('reports a file it cannot read', function (): void {
    $logSpy = Log::spy();
    $path = jsonLoaderFile($this->directory, '[]');
    // Root reads anything, so it cannot lock a file
    if (! jsonLoaderDenyRead($path)) {
        Assert::markTestSkipped('This process can still read a file it was denied.');
    }

    // Under `pest --mutate` the plugin's stream wrapper reports an unreadable
    // file as missing, so this case would fail in every mutant and score kills
    // it never made; the loader's annotation names the survivors this leaves
    if (! \file_exists($path)) {
        Assert::markTestSkipped('This environment reports an unreadable file as missing.');
    }

    // Reading the locked file raises a warning before the loader sees false
    \set_error_handler(static fn (): bool => true);

    try {
        $result = $this->loader->load($path, $this->command);
    } finally {
        \restore_error_handler();
    }

    expect($result)->toBeNull()
        ->and($this->command->errors)->toBe(["Unable to read file: {$path}"]);

    $logSpy->shouldHaveReceived('error', ['GravityForms\\JsonFileLoader::load: Unable to read file', ['file_path' => $path]]);
});

it('reports malformed JSON with the parser error', function (): void {
    $logSpy = Log::spy();
    $path = jsonLoaderFile($this->directory, '{"id":');

    expect($this->loader->load($path, $this->command))->toBeNull()
        ->and($this->command->errors)->toBe(["Invalid JSON in file: {$path}"]);

    $logSpy->shouldHaveReceived('error', ['GravityForms\\JsonFileLoader::load: Invalid JSON', ['file_path' => $path, 'json_error' => 'Syntax error']]);
});

it('reports JSON whose root is not an array', function (string $contents): void {
    $logSpy = Log::spy();
    $path = jsonLoaderFile($this->directory, $contents);

    expect($this->loader->load($path, $this->command))->toBeNull()
        ->and($this->command->errors)->toBe(["Invalid JSON in file: {$path} (expected an array)"]);

    $logSpy->shouldHaveReceived('error', ['GravityForms\\JsonFileLoader::load: JSON root is not an array', ['file_path' => $path]]);
})->with([
    'a number' => ['5'],
    'a string' => ['"forms"'],
    'null' => ['null'],
]);
