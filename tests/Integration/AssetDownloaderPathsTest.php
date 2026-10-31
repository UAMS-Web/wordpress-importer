<?php

declare(strict_types=1);

/**
 * Tests the `AssetDownloader` paths `AssetDownloaderTest` does not reach: the
 * retry loop and its HTTPS-first fallback, each redirect refusal message, a
 * successful upload with and without an asset to replace, audio metadata,
 * both kinds of upload failure, the image diagnostics, and the file type and
 * connection error readers.
 *
 * Integration rather than Unit because the failure path clears derivatives
 * through Statamic's `Glide` facade. Hosts are public IP literals, so the
 * egress guard allows them without a DNS lookup and the suite stays offline.
 * The retry backoff is faked through `Sleep`.
 *
 * @command  php vendor/bin/pest tests/Integration/AssetDownloaderPathsTest.php
 */

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Statamic\Assets\Asset;
use Statamic\Assets\AssetContainer as StatamicAssetContainer;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\AssetContainer as AssetContainerFacade;
use Statamic\Facades\Glide;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetDownloader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\FailedAssetCollector;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * An asset that records what the downloader does to it, and fails its upload
 * when told to.
 */
final class AssetDownloaderPathsAsset extends Asset
{
    /**
     * The file each upload was handed, as it was when handed over.
     *
     * @var list<array{name: string, mime: string, size: int, path: string, valid: bool}>
     */
    public array $uploads = [];

    /**
     * The data written to the asset.
     *
     * @var list<array<int|string, mixed>>
     */
    public array $written = [];

    /**
     * How many times the asset was saved, and deleted.
     *
     * @var array{save: int, delete: int}
     */
    public array $calls = ['save' => 0, 'delete' => 0];

    /**
     * Start an asset with the given behavior.
     *
     * @param  ?Throwable  $uploadFailure  What the upload throws, or null to succeed
     * @param  bool  $media  Whether the asset is audio
     * @param  mixed  $savedPath  What path() returns after the save
     * @param  bool  $stored  Whether exists() reports the source stored
     */
    public function __construct(
        public ?Throwable $uploadFailure = null,
        public bool $media = false,
        public mixed $savedPath = 'news/x.jpg',
        public bool $stored = false,
    ) {
        parent::__construct();

        $this->shouldHydrate = false;
        $this->meta = ['data' => []];
    }

    /**
     * Record the file, then fail if told to.
     *
     * @param  UploadedFile  $file  The file
     * @return $this The asset
     */
    public function upload(UploadedFile $file)
    {
        $this->uploads[] = [
            'name' => $file->getClientOriginalName(),
            'mime' => $file->getClientMimeType(),
            'size' => (int) \filesize($file->getPathname()),
            'path' => $file->getPathname(),
            'valid' => $file->isValid(),
        ];

        if ($this->uploadFailure instanceof Throwable) {
            throw $this->uploadFailure;
        }

        return $this;
    }

    /**
     * Record a data write; a read returns nothing stored.
     *
     * @param  mixed  $data  The data, or null to read
     * @return mixed The asset, or its data
     */
    public function data($data = null)
    {
        if (\func_num_args() === 0) {
            return new Collection;
        }

        $this->written[] = \is_array($data) ? $data : [];

        return $this;
    }

    /**
     * Report whether the asset is audio.
     */
    public function isAudio(): bool
    {
        return $this->media;
    }

    /**
     * Report the asset is not video.
     */
    public function isVideo(): bool
    {
        return false;
    }

    /**
     * Record the save.
     *
     * @return bool Always true
     */
    public function save()
    {
        $this->calls['save']++;

        return true;
    }

    /**
     * Record the delete.
     *
     * @return $this The asset
     */
    public function delete()
    {
        $this->calls['delete']++;

        return $this;
    }

    /**
     * Report whether the source was stored.
     */
    public function exists(): bool
    {
        return $this->stored;
    }

    /**
     * The path the save produced.
     *
     * @return mixed The path
     */
    public function path($path = null)
    {
        return $this->savedPath;
    }
}

/**
 * A container that makes the given asset for the given path.
 */
final class AssetDownloaderPathsContainer extends StatamicAssetContainer
{
    /**
     * Start the container.
     *
     * @param  AssetDownloaderPathsAsset  $asset  The asset it makes
     * @param  string  $expectedPath  The path it expects to be asked for
     */
    public function __construct(private readonly AssetDownloaderPathsAsset $asset, private readonly string $expectedPath) {}

    /**
     * Make the asset, refusing any other path.
     *
     * @param  mixed  $path  The path
     * @return AssetDownloaderPathsAsset The asset
     */
    public function makeAsset($path)
    {
        if ($path !== $this->expectedPath) {
            throw new RuntimeException('Unexpected asset path.');
        }

        return $this->asset;
    }
}

/**
 * An existing asset carrying the given data, which records its deletion.
 */
final class AssetDownloaderPathsExisting extends Asset
{
    /**
     * Whether the asset was deleted.
     */
    public bool $deleted = false;

    /**
     * Start an existing asset whose data reads as the given value.
     *
     * @param  mixed  $read  What data() returns
     */
    public function __construct(public mixed $read)
    {
        parent::__construct();

        $this->shouldHydrate = false;
    }

    /**
     * Return the chosen data.
     *
     * @param  mixed  $data  Ignored
     * @return mixed The data
     */
    public function data($data = null)
    {
        return $this->read;
    }

    /**
     * Record the delete.
     *
     * @return $this The asset
     */
    public function delete()
    {
        $this->deleted = true;

        return $this;
    }
}

/**
 * Serve the given asset from the `images` container, and the given existing
 * asset from the lookup.
 *
 * @param  AssetDownloaderPathsAsset  $asset  The asset the container makes
 * @param  ?Asset  $existing  The asset already at the path
 */
function assetDownloaderPathsContainer(AssetDownloaderPathsAsset $asset, ?Asset $existing = null, string $path = 'news/x.jpg'): void
{
    AssetContainerFacade::shouldReceive('find')->with('images')->andReturn(new AssetDownloaderPathsContainer($asset, $path));
    AssetFacade::shouldReceive('find')->with("images::{$path}")->andReturn($existing);
}

/**
 * The files the downloader has left in the temporary directory.
 *
 * @return list<string> The paths
 */
function assetDownloaderPathsTempFiles(): array
{
    $files = \glob(\sys_get_temp_dir().'/wp_import_*');

    return \is_array($files) ? $files : [];
}

/**
 * The warnings and errors logged, by level.
 */
final class AssetDownloaderPathsLog
{
    /**
     * Each entry's level, message and context.
     *
     * @var list<array{string, mixed, mixed}>
     */
    public array $entries = [];
}

/**
 * Record the warnings and errors logged.
 *
 * @return AssetDownloaderPathsLog The recorder
 */
function assetDownloaderPathsLog(): AssetDownloaderPathsLog
{
    $log = new AssetDownloaderPathsLog;

    foreach (['warning', 'error'] as $level) {
        Log::shouldReceive($level)->andReturnUsing(static function (mixed $message, mixed $context = []) use ($log, $level): void {
            $log->entries[] = [$level, $message, $context];
        });
    }

    return $log;
}

/**
 * Download `x.jpg` from the given URL into `images::news/x.jpg`.
 *
 * @param  string  $url  The URL
 * @param  BufferedOutput  $output  The output
 * @param  int  $retries  The attempts allowed
 * @param  bool  $skipOnFailure  Whether to return the URL on failure
 * @param  int  $timeout  The time budget
 * @return ?string The result
 */
function assetDownloaderPathsDownload(string $url, BufferedOutput $output, int $retries = 1, bool $skipOnFailure = false, int $timeout = 60): ?string
{
    return (new AssetDownloader)->download($url, 'news/x.jpg', 'images', $output, $timeout, $retries, $skipOnFailure, 'https://uams.edu/page');
}

/**
 * The console lines written so far, without blank lines.
 *
 * @param  BufferedOutput  $output  The output
 * @return list<string> The lines
 */
function assetDownloaderPathsLines(BufferedOutput $output): array
{
    return \array_values(\array_filter(\explode(PHP_EOL, $output->fetch()), static fn (string $line): bool => $line !== ''));
}

/**
 * Call a private downloader method.
 *
 * @param  string  $method  The method
 * @param  mixed  ...$arguments  Its arguments
 * @return mixed What it returns
 */
function assetDownloaderPathsCall(string $method, mixed ...$arguments): mixed
{
    return (new ReflectionMethod(AssetDownloader::class, $method))->invoke(new AssetDownloader, ...$arguments);
}

/**
 * A temporary file holding the given bytes.
 *
 * @param  string  $bytes  The contents
 * @return string The path
 */
function assetDownloaderPathsFile(string $bytes): string
{
    $path = \tempnam(\sys_get_temp_dir(), 'adp_');

    if (! \is_string($path)) {
        throw new RuntimeException('No temporary file.');
    }

    \file_put_contents($path, $bytes);

    return $path;
}

/**
 * A one-second silent mono WAV file.
 *
 * @return string The bytes
 */
function assetDownloaderPathsWav(): string
{
    $samples = \str_repeat("\0\0", 8000);

    return 'RIFF'.\pack('V', 36 + \strlen($samples)).'WAVE'
        .'fmt '.\pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16)
        .'data'.\pack('V', \strlen($samples)).$samples;
}

/**
 * A 1x1 PNG.
 *
 * @return string The bytes
 */
function assetDownloaderPathsPng(): string
{
    $png = \base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', true);

    return \is_string($png) ? $png : '';
}

beforeEach(function (): void {
    Sleep::fake();
    FailedAssetCollector::clearFailedUrls();
});

afterEach(function (): void {
    FailedAssetCollector::clearFailedUrls();
    Mockery::close();
});

describe('a successful download', function (): void {
    it('uploads the downloaded file with its name and type, stamps the source URL, and returns the saved path', function (): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response(assetDownloaderPathsPng(), 200)]);
        $asset = new AssetDownloaderPathsAsset(savedPath: 'news/x-saved.jpg');
        assetDownloaderPathsContainer($asset);
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output))->toBe('news/x-saved.jpg')
            ->and(\array_map(static fn (array $upload): array => \array_diff_key($upload, ['path' => true]), $asset->uploads))
            ->toBe([['name' => 'x.jpg', 'mime' => 'image/png', 'size' => \strlen(assetDownloaderPathsPng()), 'valid' => true]])
            ->and($asset->written)->toBe([['original_wordpress_url' => 'https://93.184.216.34/x.jpg']])
            ->and($asset->calls)->toBe(['save' => 1, 'delete' => 0])
            ->and(assetDownloaderPathsLines($output))->toBe(['      - Asset imported successfully.'])
            ->and(\file_exists($asset->uploads[0]['path'] ?? ''))->toBeFalse();

        // The body is what is stored, so the request has to fetch it
        Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET');
    });

    it('returns no path when the save leaves none', function (): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response('bytes', 200)]);
        assetDownloaderPathsContainer(new AssetDownloaderPathsAsset(savedPath: null));

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', new BufferedOutput))->toBeNull();
    });

    it('replaces an existing asset, carrying its data over', function (): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response('bytes', 200)]);
        $asset = new AssetDownloaderPathsAsset;
        $existing = new AssetDownloaderPathsExisting(new Collection(['title' => 'Kept', 'original_wordpress_url' => 'old']));
        assetDownloaderPathsContainer($asset, $existing);

        assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', new BufferedOutput);

        expect($existing->deleted)->toBeTrue()
            ->and($asset->written)->toBe([['title' => 'Kept', 'original_wordpress_url' => 'https://93.184.216.34/x.jpg']]);
    });

    it('carries nothing over from an existing asset whose data cannot be read', function (mixed $read): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response('bytes', 200)]);
        $asset = new AssetDownloaderPathsAsset;
        assetDownloaderPathsContainer($asset, new AssetDownloaderPathsExisting($read));

        assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', new BufferedOutput);

        expect($asset->written)->toBe([['original_wordpress_url' => 'https://93.184.216.34/x.jpg']]);
    })->with([
        'not an object' => [5],
        'an object with no way to list it' => [new stdClass],
        'a list that is not an array' => [new class
        {
            /**
             * Return something other than an array.
             */
            public function all(): string
            {
                return 'none';
            }
        }],
    ]);

    it('stamps an audio file\'s duration, and carries an existing asset\'s data over', function (): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response(assetDownloaderPathsWav(), 200)]);
        $asset = new AssetDownloaderPathsAsset(media: true);
        assetDownloaderPathsContainer($asset, new AssetDownloaderPathsExisting(new Collection(['title' => 'Kept'])));

        assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', new BufferedOutput);

        expect($asset->written)->toBe([['title' => 'Kept', 'duration' => 1, 'original_wordpress_url' => 'https://93.184.216.34/x.jpg']]);
    });

    it('stamps no duration on audio that has none', function (): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response('not audio', 200)]);
        $asset = new AssetDownloaderPathsAsset(media: true);
        assetDownloaderPathsContainer($asset);

        assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', new BufferedOutput);

        expect($asset->written)->toBe([['original_wordpress_url' => 'https://93.184.216.34/x.jpg']]);
    });
});

describe('a failed download', function (): void {
    it('reports the failure, removes the temporary file and returns null', function (): void {
        Http::fake(['*' => Http::response('', 500)]);
        $before = assetDownloaderPathsTempFiles();
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output, 2))->toBeNull()
            ->and(assetDownloaderPathsLines($output))->toBe(["      - Failed to download asset from 'https://93.184.216.34/x.jpg' after 2 attempts."])
            ->and(FailedAssetCollector::getFailedUrls())->toBe(['https://93.184.216.34/x.jpg'])
            ->and(\array_values(\array_diff(assetDownloaderPathsTempFiles(), $before)))->toBe([]);

        // A failing status is retried at once, with no backoff
        Http::assertSentCount(2);
        Sleep::assertNeverSlept();
    });

    it('returns the URL instead when told to continue past failures', function (): void {
        Http::fake(['*' => Http::response('', 404)]);
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output, skipOnFailure: true))->toBe('https://93.184.216.34/x.jpg')
            ->and(assetDownloaderPathsLines($output))->toBe([
                "      - Failed to download asset from 'https://93.184.216.34/x.jpg' after 1 attempts.",
                '      - Continuing with original URL due to skip-failed-assets setting.',
            ]);
    });

    it('refuses a URL to a non-public host without requesting it', function (): void {
        Http::fake();
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('http://127.0.0.1/x.jpg', $output))->toBeNull()
            ->and(assetDownloaderPathsLines($output))->toBe([
                '      - Skipping non-public asset URL: http://127.0.0.1/x.jpg',
                "      - Failed to download asset from 'http://127.0.0.1/x.jpg' after 1 attempts.",
            ]);

        Http::assertNothingSent();
    });

    it('tries three times, and returns null, by default', function (): void {
        Http::fake(['*' => Http::response('', 500)]);
        $output = new BufferedOutput;

        expect((new AssetDownloader)->download('https://93.184.216.34/x.jpg', 'news/x.jpg', 'images', $output))->toBeNull()
            ->and(assetDownloaderPathsLines($output))->toBe(["      - Failed to download asset from 'https://93.184.216.34/x.jpg' after 3 attempts."]);

        Http::assertSentCount(3);
    });

    it('keeps thirty seconds as the connect timeout', function (): void {
        expect(AssetDownloader::CONNECT_TIMEOUT_SECONDS)->toBe(30);
    });

    it('logs a missing container, removes the temporary file, and returns null or the URL', function (bool $skip, ?string $expected, array $extra): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response('bytes', 200)]);
        AssetContainerFacade::shouldReceive('find')->with('images')->andReturn(null);
        AssetFacade::shouldReceive('find')->andReturn(null);
        $log = assetDownloaderPathsLog();
        $before = assetDownloaderPathsTempFiles();
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output, skipOnFailure: $skip))->toBe($expected)
            ->and($log->entries)->toBe([[
                'error',
                "AssetDownloader::download: Asset container 'images' not found. Skipping asset import. (from entry: https://uams.edu/page)",
                ['url' => 'https://93.184.216.34/x.jpg', 'path' => 'news/x.jpg', 'container' => 'images', 'entry_url' => 'https://uams.edu/page'],
            ]])
            ->and(assetDownloaderPathsLines($output))->toBe(["      - Asset container 'images' not found. Skipping asset import.", ...$extra])
            ->and(FailedAssetCollector::getFailedUrls())->toBe(['https://93.184.216.34/x.jpg'])
            ->and(\array_values(\array_diff(assetDownloaderPathsTempFiles(), $before)))->toBe([]);
    })->with([
        'returning null' => [false, null, []],
        'returning the URL' => [true, 'https://93.184.216.34/x.jpg', ['      - Continuing with original URL due to skip-failed-assets setting.']],
    ]);

    it('stops on the first success', function (): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::sequence()->push('', 500)->push('bytes', 200)]);
        assetDownloaderPathsContainer(new AssetDownloaderPathsAsset);

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', new BufferedOutput, 3))->toBe('news/x.jpg');

        Http::assertSentCount(2);
    });
});

describe('retrying', function (): void {
    it('backs off longer after each connection error, and names the error', function (string $message, string $type): void {
        Http::fake(static function () use ($message): never {
            throw new ConnectionException($message);
        });
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output, 3))->toBeNull()
            ->and(\array_slice(assetDownloaderPathsLines($output), 0, 3))->toBe([
                "      - Connection error on attempt 1 ({$type}). Retrying...",
                "      - Connection error on attempt 2 ({$type}). Retrying...",
                "      - Connection error on attempt 3 ({$type}). Retrying...",
            ]);

        // No wait follows the last attempt
        Sleep::assertSequence([Sleep::for(2)->seconds(), Sleep::for(4)->seconds()]);
    })->with([
        'a timeout' => ['cURL error 28: Connection timed out after 30001 milliseconds', 'timeout'],
        'a refusal' => ['cURL error 7: Connection refused', 'connection refused'],
        'a DNS failure' => ['cURL error 6: Name or service not known', 'DNS resolution failed'],
        'a TLS failure' => ['cURL error 35: SSL connect error', 'SSL/TLS error'],
        'anything else' => ['cURL error 52: Empty reply from server', 'network error'],
    ]);

    it('backs off after a request error, and after an unexpected one', function (Closure $failure, string $line): void {
        Http::fake($failure);
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output, 2))->toBeNull()
            ->and(\array_slice(assetDownloaderPathsLines($output), 0, 2))->toBe([
                \sprintf($line, 1),
                \sprintf($line, 2),
            ]);

        Sleep::assertSequence([Sleep::for(2)->seconds()]);
    })->with([
        'a request error' => [static function (Request $request): never {
            throw new RequestException(new Response(new GuzzleHttp\Psr7\Response(500)));
        }, '      - HTTP request error on attempt %d. Retrying...'],
        'an unexpected error' => [static function (): never {
            throw new RuntimeException('boom');
        }, '      - Unexpected error on attempt %d. Retrying...'],
    ]);

    it('tries HTTPS first for an HTTP URL', function (): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response('bytes', 200)]);
        assetDownloaderPathsContainer(new AssetDownloaderPathsAsset);
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('http://93.184.216.34/x.jpg', $output))->toBe('news/x.jpg')
            ->and(assetDownloaderPathsLines($output)[0] ?? null)->toBe('      - Attempting HTTPS version first: https://93.184.216.34/x.jpg');

        Http::assertSentCount(1);
    });

    it('falls back to HTTP at once when the HTTPS attempt fails', function (Closure $failure, string $line): void {
        Http::fake([
            'https://93.184.216.34/x.jpg' => $failure,
            'http://93.184.216.34/x.jpg' => Http::response('bytes', 200),
        ]);
        assetDownloaderPathsContainer(new AssetDownloaderPathsAsset);
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('http://93.184.216.34/x.jpg', $output, 2))->toBe('news/x.jpg')
            ->and(\array_slice(assetDownloaderPathsLines($output), 0, 2))->toBe([
                '      - Attempting HTTPS version first: https://93.184.216.34/x.jpg',
                $line,
            ]);

        Http::assertSent(static fn (Request $request): bool => $request->url() === 'http://93.184.216.34/x.jpg');
        Sleep::assertNeverSlept();
    })->with([
        'a connection error' => [static function (): never {
            throw new ConnectionException('cURL error 7: Connection refused');
        }, '      - HTTPS attempt failed (connection refused). Trying original HTTP URL...'],
        'a request error' => [static function (): never {
            throw new RequestException(new Response(new GuzzleHttp\Psr7\Response(500)));
        }, '      - HTTPS attempt failed. Trying original HTTP URL...'],
        'an unexpected error' => [static function (): never {
            throw new RuntimeException('boom');
        }, '      - HTTPS attempt failed. Trying original HTTP URL...'],
    ]);

    it('does not fall back from an HTTPS URL', function (): void {
        Http::fake(static function (): never {
            throw new ConnectionException('cURL error 7: Connection refused');
        });
        $output = new BufferedOutput;

        assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output);

        expect(assetDownloaderPathsLines($output)[0] ?? null)->toBe('      - Connection error on attempt 1 (connection refused). Retrying...');
    });
});

describe('refusing a redirect', function (): void {
    it('names why the redirect chain was refused', function (string $routes, string $line): void {
        // The responses are built inside the test: the Http facade is not booted
        // while datasets are collected
        $responses = [
            'a non-public target' => ['https://93.184.216.34/x.jpg' => Http::response('', 302, ['Location' => 'http://10.0.0.1/x.jpg'])],
            'a loop' => [
                'https://93.184.216.34/x.jpg' => Http::response('', 302, ['Location' => 'https://8.8.8.8/x.jpg']),
                'https://8.8.8.8/x.jpg' => Http::response('', 302, ['Location' => 'https://93.184.216.34/x.jpg']),
            ],
            'an unparseable target' => ['https://93.184.216.34/x.jpg' => Http::response('', 302, ['Location' => 'http://[::1'])],
        ];
        Http::fake($responses[$routes]);
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output))->toBeNull()
            ->and(assetDownloaderPathsLines($output)[0] ?? null)->toBe($line);
    })->with([
        'a non-public target' => ['a non-public target', '      - Refusing non-public redirect target: http://10.0.0.1/x.jpg'],
        'a loop' => ['a loop', '      - Redirect loop detected at: https://93.184.216.34/x.jpg'],
        'an unparseable target' => ['an unparseable target', '      - Unparseable redirect target from https://93.184.216.34/x.jpg: http://[::1'],
    ]);

    it('names a chain with too many redirects', function (): void {
        Http::fake(static fn (Request $request): Response|GuzzleHttp\Promise\PromiseInterface => Http::response('', 302, ['Location' => $request->url().'x']));
        $output = new BufferedOutput;

        assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output);

        expect(assetDownloaderPathsLines($output)[0] ?? null)->toBe('      - Too many redirects (>5) for: https://93.184.216.34/x.jpg');
    });

    it('names a chain that ran out of time', function (): void {
        Http::fake(static function (): PromiseInterface {
            \usleep(1_100_000);

            return Http::response('', 302, ['Location' => 'https://8.8.8.8/x.jpg']);
        });
        $output = new BufferedOutput;

        assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output, timeout: 1);

        expect(assetDownloaderPathsLines($output)[0] ?? null)->toBe('      - Exhausted the 1s fetch budget for: https://8.8.8.8/x.jpg');
    });
});

describe('a failed upload', function (): void {
    it('logs the error, keeps a stored source, and removes one that never landed', function (bool $stored, int $deletes): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response('bytes', 200)]);
        $asset = new AssetDownloaderPathsAsset(new RuntimeException('disk full'), stored: $stored);
        assetDownloaderPathsContainer($asset);
        Glide::shouldReceive('clearAsset')->once()->with($asset);
        $log = assetDownloaderPathsLog();
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output))->toBeNull()
            ->and($asset->calls['delete'])->toBe($deletes)
            ->and($log->entries)->toBe([[
                'error',
                'AssetDownloader::download: Exception occurred during asset upload: disk full (from entry: https://uams.edu/page)',
                ['url' => 'https://93.184.216.34/x.jpg', 'path' => 'news/x.jpg', 'container' => 'images', 'error' => 'disk full', 'entry_url' => 'https://uams.edu/page'],
            ]])
            ->and(assetDownloaderPathsLines($output))->toBe(['      - Failed to upload asset: disk full'])
            ->and(FailedAssetCollector::getFailedUrls())->toBe(['https://93.184.216.34/x.jpg'])
            ->and(\file_exists($asset->uploads[0]['path'] ?? ''))->toBeFalse();
    })->with([
        'a source that reached the container' => [true, 0],
        'one that did not' => [false, 1],
    ]);

    it('returns the URL instead when told to continue past failures', function (): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response('bytes', 200)]);
        assetDownloaderPathsContainer(new AssetDownloaderPathsAsset(new RuntimeException('disk full')));
        assetDownloaderPathsLog();
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output, skipOnFailure: true))->toBe('https://93.184.216.34/x.jpg')
            ->and(assetDownloaderPathsLines($output))->toBe([
                '      - Failed to upload asset: disk full',
                '      - Continuing with original URL due to skip-failed-assets setting.',
            ]);
    });

    it('keeps the original error when the derivatives cannot be cleared', function (): void {
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response('bytes', 200)]);
        assetDownloaderPathsContainer(new AssetDownloaderPathsAsset(new RuntimeException('disk full')));
        Glide::shouldReceive('clearAsset')->andThrow(new RuntimeException('cache gone'));
        assetDownloaderPathsLog();
        $output = new BufferedOutput;

        assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output);

        expect(assetDownloaderPathsLines($output))->toBe(['      - Failed to upload asset: disk full']);
    });

    it('logs an invalid image with what the file really is, and deletes it', function (): void {
        $html = '<!DOCTYPE html><p>Not found</p>';
        Http::fake(['https://93.184.216.34/x.jpg' => Http::response($html, 200)]);
        $asset = new AssetDownloaderPathsAsset(new RuntimeException('The file does not actually appear to be a valid image.'), stored: true);
        assetDownloaderPathsContainer($asset);
        $log = assetDownloaderPathsLog();
        $output = new BufferedOutput;

        expect(assetDownloaderPathsDownload('https://93.184.216.34/x.jpg', $output))->toBeNull()
            ->and($asset->calls['delete'])->toBe(1)
            ->and($log->entries)->toBe([[
                'warning',
                "AssetDownloader::download: Downloaded file from 'https://93.184.216.34/x.jpg' does not appear to be a valid image file. Diagnostics: size=".\strlen($html).' bytes, detected_mime=text/html, uploaded_file_mime=text/html, extension=jpg, getimagesize=failed, actual_type=HTML Skipping asset import. (from entry: https://uams.edu/page)',
                [
                    'url' => 'https://93.184.216.34/x.jpg',
                    'path' => 'news/x.jpg',
                    'container' => 'images',
                    'error' => 'The file does not actually appear to be a valid image.',
                    'entry_url' => 'https://uams.edu/page',
                    'file_size_bytes' => \strlen($html),
                    'detected_mime_type' => 'text/html',
                    'uploaded_file_mime_type' => 'text/html',
                    'file_extension' => 'jpg',
                    'getimagesize_result' => false,
                    'actual_file_type' => 'HTML',
                ],
            ]])
            ->and(assetDownloaderPathsLines($output))->toBe(['      - Downloaded file does not appear to be a valid image. Skipping asset import.']);
    });
});

describe('checking whether the source was stored', function (): void {
    it('answers only true from an asset that can say so', function (object $asset, bool $expected): void {
        expect(assetDownloaderPathsCall('sourceReachedContainer', $asset))->toBe($expected);
    })->with([
        'stored' => [new AssetDownloaderPathsAsset(stored: true), true],
        'not stored' => [new AssetDownloaderPathsAsset(stored: false), false],
        'no way to ask' => [new stdClass, false],
        'an answer that is not true' => [new class
        {
            /**
             * Answer with something other than true.
             */
            public function exists(): int
            {
                return 1;
            }
        }, false],
        'an asset that cannot answer' => [new class
        {
            /**
             * Fail to answer.
             */
            public function exists(): never
            {
                throw new RuntimeException('no disk');
            }
        }, false],
    ]);
});

describe('describing an invalid image', function (): void {
    it('leaves out what the file did not tell', function (): void {
        Http::fake(['https://93.184.216.34/README' => Http::response('hello world', 200)]);
        assetDownloaderPathsContainer(new AssetDownloaderPathsAsset(new RuntimeException('does not actually appear to be a valid image')), path: 'news/README');
        $log = assetDownloaderPathsLog();

        (new AssetDownloader)->download('https://93.184.216.34/README', 'news/README', 'images', new BufferedOutput, 60, 1);

        expect($log->entries[0][1] ?? null)->toBe("AssetDownloader::download: Downloaded file from 'https://93.184.216.34/README' does not appear to be a valid image file. Diagnostics: size=11 bytes, detected_mime=text/plain, uploaded_file_mime=text/plain, getimagesize=failed Skipping asset import.");
    });
});

describe('reading a file\'s type', function (): void {
    it('recognizes a file by its first bytes', function (string $bytes, ?string $expected): void {
        $path = assetDownloaderPathsFile($bytes);

        expect(assetDownloaderPathsCall('detectFileTypeFromMagicBytes', $path))->toBe($expected);

        \unlink($path);
    })->with([
        'PNG' => [\hex2bin('89504e470d0a1a0a0000'), 'PNG'],
        'JPEG' => [\hex2bin('ffd8ffe000104a46'), 'JPEG'],
        'GIF' => ['GIF89a....', 'GIF'],
        'WebP' => ['RIFF0000WEBPVP8 ', 'WebP'],
        'RIFF that is not WebP' => ['RIFF0000WAVEfmt ', null],
        'BMP' => ['BM00000000', 'BMP'],
        'SVG with a declaration' => ['<?xml version="1.0"?>', 'SVG'],
        'SVG without one' => ['<svg xmlns="x">', 'SVG'],
        'an HTML doctype' => ['<!DOCTYPE html>', 'HTML'],
        'an HTML tag' => ['<html><body>', 'HTML'],
        'an HTML tag in capitals' => ['<HTML><BODY>', 'HTML'],
        'plain text' => ['hello world', null],
        'fewer than four bytes' => ['BM0', null],
        'exactly four bytes' => ['BM00', 'BMP'],
        'a WebP marker past the twelfth byte' => ['RIFF00000WEBP', null],
    ]);

    it('reads nothing from a file it cannot read', function (): void {
        // `chmod()` cannot take read access away on Windows
        if (\PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('chmod() does not deny reading on Windows.');
        }

        $path = assetDownloaderPathsFile('BM00');
        \chmod($path, 0000);

        expect(assetDownloaderPathsCall('detectFileTypeFromMagicBytes', $path))->toBeNull();

        \chmod($path, 0600);
        \unlink($path);
    });

    it('reads nothing from a file that is not there', function (): void {
        expect(assetDownloaderPathsCall('detectFileTypeFromMagicBytes', \sys_get_temp_dir().'/adp-missing-'.\bin2hex(\random_bytes(4))))->toBeNull();
    });

    it('gathers the dimensions of a real image', function (): void {
        $image = \imagecreatetruecolor(3, 2);
        \ob_start();
        \imagepng($image);
        $png = (string) \ob_get_clean();
        $path = assetDownloaderPathsFile($png);

        expect(assetDownloaderPathsCall('gatherImageDiagnostics', $path, 'Photo.PNG'))->toBe([
            'file_size_bytes' => \strlen($png),
            'detected_mime_type' => 'image/png',
            'file_extension' => 'png',
            'getimagesize_result' => true,
            'image_width' => 3,
            'image_height' => 2,
            'image_type' => IMAGETYPE_PNG,
            'actual_file_type' => 'PNG',
        ]);

        \unlink($path);
    });

    it('gathers no extension or file type it cannot find', function (): void {
        $path = assetDownloaderPathsFile('hello world');

        $diagnostics = assetDownloaderPathsCall('gatherImageDiagnostics', $path, 'README');

        // Reading a non-image raises a notice, which Laravel turns into an exception
        expect(\is_array($diagnostics) ? \array_diff_key($diagnostics, ['getimagesize_error' => true]) : null)->toBe([
            'file_size_bytes' => 11,
            'detected_mime_type' => 'text/plain',
            'getimagesize_result' => false,
        ])
            ->and(\is_array($diagnostics) ? $diagnostics['getimagesize_error'] ?? null : null)->toStartWith('getimagesize(): Error reading from');

        \unlink($path);
    });
});
