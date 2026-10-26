<?php

declare(strict_types=1);

/**
 * Tests that `XmlFileLoader` reads a form plugin's XML export into an array of
 * forms with their fields, casting each value to its type, and reports a
 * missing, unreadable or malformed file to the console and the log instead of
 * returning it (#1314).
 *
 * Routed to the light Statamic band for the console command and the `Log`
 * facade. The command records its errors rather than printing them.
 *
 * @command  php vendor/bin/pest tests/Integration/XmlFileLoaderTest.php
 */

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Assert;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Loaders\XmlFileLoader;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A console command that records the errors it is asked to print.
 */
class XmlLoaderRecordingCommand extends Command
{
    /**
     * @var string The command signature
     */
    protected $signature = 'probe:xml-loader';

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
    $this->directory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('xml_loader_', true);
    \mkdir($this->directory);
    $this->command = new XmlLoaderRecordingCommand;
    $this->loader = new XmlFileLoader('Formidable');
});

afterEach(function (): void {
    $files = \glob($this->directory.'/*');

    foreach (\is_array($files) ? $files : [] as $file) {
        xmlLoaderAllowRead($file);
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
function xmlLoaderFile(string $directory, string $contents): string
{
    $path = $directory.'/forms.xml';
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
function xmlLoaderDenyRead(string $path): bool
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
function xmlLoaderAllowRead(string $path): void
{
    if (\PHP_OS_FAMILY === 'Windows') {
        \exec('icacls '.\escapeshellarg($path).' /remove:d '.\escapeshellarg((string) \getenv('USERNAME')).' 2>NUL');
    } else {
        @\chmod($path, 0644);
    }
}

it('describes its format', function (): void {
    expect($this->loader->getFileExtension())->toBe('xml')
        ->and($this->loader->getFormatDescription())->toBe('XML file');
});

it('reads each form and its fields, casting each value to its type', function (): void {
    $path = xmlLoaderFile($this->directory, '<forms>'
        .'<form><id>4</id><name>Contact</name><description>Reach us</description><created_at>2020-01-01</created_at>'
        .'<logged_in>1</logged_in><is_template>0</is_template><editable>1</editable><options>a:0:{}</options>'
        .'<status>published</status><parent_form_id>2</parent_form_id>'
        .'<field><id>10</id><field_key>abc</field_key><name>Email</name><description>Yours</description><type>email</type>'
        .'<default_value>x@y</default_value><field_order>3</field_order><form_id>4</form_id><required>1</required>'
        .'<options>o</options><field_options>fo</field_options></field>'
        .'<field><id>11</id><type>text</type></field>'
        .'</form>'
        .'<form><id>5</id><name>Empty</name></form>'
        .'</forms>');

    expect($this->loader->load($path, $this->command))->toBe([
        [
            'id' => 4,
            'name' => 'Contact',
            'description' => 'Reach us',
            'created_at' => '2020-01-01',
            'logged_in' => 1,
            'is_template' => 0,
            'editable' => 1,
            'options' => 'a:0:{}',
            'status' => 'published',
            'parent_form_id' => 2,
            'fields' => [
                [
                    'id' => 10,
                    'field_key' => 'abc',
                    'name' => 'Email',
                    'description' => 'Yours',
                    'type' => 'email',
                    'default_value' => 'x@y',
                    'field_order' => 3,
                    'form_id' => 4,
                    'required' => 1,
                    'options' => 'o',
                    'field_options' => 'fo',
                ],
                [
                    'id' => 11,
                    'field_key' => '',
                    'name' => '',
                    'description' => '',
                    'type' => 'text',
                    'default_value' => '',
                    'field_order' => 0,
                    'form_id' => 0,
                    'required' => 0,
                    'options' => '',
                    'field_options' => '',
                ],
            ],
        ],
        [
            'id' => 5,
            'name' => 'Empty',
            'description' => '',
            'created_at' => '',
            'logged_in' => 0,
            'is_template' => 0,
            'editable' => 0,
            'options' => '',
            'status' => '',
            'parent_form_id' => 0,
            'fields' => [],
        ],
    ])
        ->and($this->command->errors)->toBe([]);
});

it('reads a document with no forms as no forms', function (): void {
    $path = xmlLoaderFile($this->directory, '<forms/>');

    expect($this->loader->load($path, $this->command))->toBe([]);
});

it('reports a missing file', function (): void {
    $logSpy = Log::spy();
    $path = $this->directory.'/missing.xml';

    expect($this->loader->load($path, $this->command))->toBeNull()
        ->and($this->command->errors)->toBe(["File not found: {$path}"]);

    $logSpy->shouldHaveReceived('error', ['Formidable\\XmlFileLoader::load: File not found', ['file_path' => $path]]);
});

it('reports a file it cannot read', function (): void {
    $logSpy = Log::spy();
    $path = xmlLoaderFile($this->directory, '<forms/>');
    // Root reads anything, so it cannot lock a file
    if (! xmlLoaderDenyRead($path)) {
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

    $logSpy->shouldHaveReceived('error', ['Formidable\\XmlFileLoader::load: Unable to read file', ['file_path' => $path]]);
});

it('reports malformed XML', function (): void {
    $logSpy = Log::spy();
    $path = xmlLoaderFile($this->directory, '<forms><form>');

    // Parsing raises libxml warnings before the loader sees false
    \set_error_handler(static fn (): bool => true);
    $previousLibxmlMode = \libxml_use_internal_errors(false);

    try {
        $result = $this->loader->load($path, $this->command);
    } finally {
        \libxml_use_internal_errors($previousLibxmlMode);
        \restore_error_handler();
    }

    expect($result)->toBeNull()
        ->and($this->command->errors)->toBe(["Invalid XML in file: {$path}"]);

    $logSpy->shouldHaveReceived('error', ['Formidable\\XmlFileLoader::load: Invalid XML', ['file_path' => $path]]);
});
