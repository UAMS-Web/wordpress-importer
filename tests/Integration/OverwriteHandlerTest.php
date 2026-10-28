<?php

declare(strict_types=1);

/**
 * Tests `OverwriteHandler`: how each overwrite behavior treats an image or file
 * that already exists, the path and arguments it hands the downloader and the
 * metadata setter, and the configuration it reads for the download.
 *
 * The asset lookups go through the `Asset` facade, which is faked here, and the
 * downloader and metadata setter are recording subclasses, so no file is
 * downloaded or written.
 *
 * @command  php vendor/bin/pest tests/Integration/OverwriteHandlerTest.php
 */

use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Contracts\Assets\Asset as AssetContract;
use Statamic\Facades\Asset as AssetFacade;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetDownloader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\MetadataSetter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\OverwriteHandler;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A downloader that records each call and returns a queued result.
 */
final class OverwriteHandlerTestDownloader extends AssetDownloader
{
    /**
     * The arguments of each call, without the output.
     *
     * @var list<array{string, string, string, int, int, bool, ?string}>
     */
    public array $calls = [];

    /**
     * Start with the results to return, in order.
     *
     * @param  list<?string>  $results  The result of each call
     */
    public function __construct(public array $results)
    {
        parent::__construct();
    }

    /**
     * Record the call and return the next result.
     *
     * @param  string  $url  The URL to download
     * @param  string  $path  The target path
     * @param  string  $container  The container
     * @param  OutputInterface  $output  The output
     * @param  int  $timeout  The timeout
     * @param  int  $maxRetries  The retries
     * @param  bool  $skipOnFailure  Whether to skip on failure
     * @param  ?string  $pageUrl  The page URL
     * @return ?string The next queued result
     */
    public function download(
        string $url,
        string $path,
        string $container,
        OutputInterface $output,
        int $timeout = 60,
        int $maxRetries = 3,
        bool $skipOnFailure = false,
        ?string $pageUrl = null
    ): ?string {
        $this->calls[] = [$url, $path, $container, $timeout, $maxRetries, $skipOnFailure, $pageUrl];

        return \array_shift($this->results);
    }
}

/**
 * A metadata setter that records each call.
 */
final class OverwriteHandlerTestMetadataSetter extends MetadataSetter
{
    /**
     * The arguments of each call, by method.
     *
     * @var list<array{string, list<mixed>}>
     */
    public array $calls = [];

    /**
     * Record an image metadata call.
     *
     * @param  string  $path  The asset path
     * @param  string  $container  The container
     * @param  ?string  $alt  The alt text
     * @param  ?string  $caption  The caption
     * @param  ?string  $originalUrl  The source URL
     * @param  ?string  $focus  The focal point
     * @param  ?string  $creator  The credit
     * @param  ?string  $attachmentPageUrl  The attachment page URL
     */
    public function setImageMetadata(
        string $path,
        string $container,
        ?string $alt,
        ?string $caption,
        ?string $originalUrl = null,
        ?string $focus = null,
        ?string $creator = null,
        ?string $attachmentPageUrl = null
    ): void {
        $this->calls[] = ['image', [$path, $container, $alt, $caption, $originalUrl, $focus, $creator, $attachmentPageUrl]];
    }

    /**
     * Record a file metadata call.
     *
     * @param  string  $path  The asset path
     * @param  string  $container  The container
     * @param  ?string  $originalUrl  The source URL
     * @param  ?string  $title  The title
     * @param  ?array<int|string, mixed>  $additionalMetadata  Extra fields
     * @param  ?string  $attachmentPageUrl  The attachment page URL
     */
    public function setFileMetadata(
        string $path,
        string $container,
        ?string $originalUrl = null,
        ?string $title = null,
        ?array $additionalMetadata = null,
        ?string $attachmentPageUrl = null
    ): void {
        $this->calls[] = ['file', [$path, $container, $originalUrl, $title, $additionalMetadata, $attachmentPageUrl]];
    }
}

/**
 * The asset IDs a test looked up.
 */
final class OverwriteHandlerTestLookups
{
    /**
     * The IDs looked up, in order.
     *
     * @var list<string>
     */
    public array $ids = [];
}

/**
 * Fake the asset lookups so the given IDs exist and every other ID does not.
 *
 * @param  array<mixed>  $existing  The asset IDs that exist
 * @return OverwriteHandlerTestLookups The IDs looked up
 */
function overwriteHandlerAssets(array $existing): OverwriteHandlerTestLookups
{
    $lookups = new OverwriteHandlerTestLookups;

    AssetFacade::shouldReceive('find')->andReturnUsing(
        static function (string $id) use ($existing, $lookups): ?AssetContract {
            $lookups->ids[] = $id;

            return \in_array($id, $existing, true) ? new StatamicAsset : null;
        }
    );

    return $lookups;
}

/**
 * A handler with the given overwrite behavior.
 *
 * @param  ?string  $behavior  The behavior, or null for the default
 * @return OverwriteHandler The handler
 */
function overwriteHandler(?string $behavior = null): OverwriteHandler
{
    $handler = new OverwriteHandler;

    if ($behavior !== null) {
        $handler->setBehavior($behavior);
    }

    return $handler;
}

/**
 * The console lines written so far.
 *
 * @param  BufferedOutput  $output  The output
 * @return list<string> The lines
 */
function overwriteHandlerLines(BufferedOutput $output): array
{
    return \array_values(\array_filter(\explode(PHP_EOL, $output->fetch()), static fn (string $line): bool => $line !== ''));
}

afterEach(function (): void {
    Mockery::close();
});

describe('importing an image', function (): void {
    /**
     * Import `uploads/photo.jpg` for the `news` site.
     *
     * @return ?string The imported path
     */
    function overwriteHandlerImage(OverwriteHandler $handler, BufferedOutput $output, OverwriteHandlerTestDownloader $downloader, OverwriteHandlerTestMetadataSetter $metadata): ?string
    {
        return $handler->handleImageImport(
            'https://example.org/photo.jpg',
            'uploads/photo.jpg',
            'news',
            $output,
            $downloader,
            $metadata,
            'Alt',
            'Caption',
            'https://example.org/page',
            '50-50-1',
            'Credit',
            'https://example.org/?attachment_id=1'
        );
    }

    it('overwrites by default, downloading to the path and returning it under the site', function (): void {
        $lookups = overwriteHandlerAssets(['images::uploads/photo.jpg']);
        $output = new BufferedOutput;
        $downloader = new OverwriteHandlerTestDownloader(['uploads/photo-saved.jpg']);
        $metadata = new OverwriteHandlerTestMetadataSetter;

        expect(overwriteHandlerImage(overwriteHandler(), $output, $downloader, $metadata))->toBe('news/photo-saved.jpg')
            ->and($lookups->ids)->toBe(['images::uploads/photo.jpg'])
            ->and(overwriteHandlerLines($output))->toBe(["  - Importing new asset 'uploads/photo.jpg' in 'images' container."])
            ->and($downloader->calls)->toBe([['https://example.org/photo.jpg', 'uploads/photo.jpg', 'images', 60, 3, false, 'https://example.org/page']])
            ->and($metadata->calls)->toBe([['image', ['uploads/photo-saved.jpg', 'images', 'Alt', 'Caption', 'https://example.org/photo.jpg', '50-50-1', 'Credit', 'https://example.org/?attachment_id=1']]]);
    });

    it('skips an image that exists, returning its path under the site', function (): void {
        overwriteHandlerAssets(['images::uploads/photo.jpg']);
        $output = new BufferedOutput;
        $downloader = new OverwriteHandlerTestDownloader([]);
        $metadata = new OverwriteHandlerTestMetadataSetter;

        expect(overwriteHandlerImage(overwriteHandler('Skip matching files'), $output, $downloader, $metadata))->toBe('news/photo.jpg')
            ->and(overwriteHandlerLines($output))->toBe(["  - Skipped asset 'uploads/photo.jpg' in 'images' container as it already exists."])
            ->and($downloader->calls)->toBe([])
            ->and($metadata->calls)->toBe([]);
    });

    it('imports an image that does not exist when skipping', function (): void {
        overwriteHandlerAssets([]);
        $output = new BufferedOutput;
        $downloader = new OverwriteHandlerTestDownloader(['uploads/photo.jpg']);

        expect(overwriteHandlerImage(overwriteHandler('Skip matching files'), $output, $downloader, new OverwriteHandlerTestMetadataSetter))->toBe('news/photo.jpg')
            ->and(overwriteHandlerLines($output))->toBe(["  - Importing new asset 'uploads/photo.jpg' in 'images' container."])
            ->and($downloader->calls[0][1] ?? null)->toBe('uploads/photo.jpg');
    });

    it('creates an additional image under the site with the first free suffix', function (): void {
        $lookups = overwriteHandlerAssets(['images::uploads/photo.jpg', 'images::news/photo-1.jpg']);
        $output = new BufferedOutput;
        $downloader = new OverwriteHandlerTestDownloader(['news/photo-2.jpg']);

        expect(overwriteHandlerImage(overwriteHandler('Create an additional file'), $output, $downloader, new OverwriteHandlerTestMetadataSetter))->toBe('news/photo-2.jpg')
            ->and($lookups->ids)->toBe(['images::uploads/photo.jpg', 'images::news/photo-1.jpg', 'images::news/photo-2.jpg'])
            ->and(overwriteHandlerLines($output))->toBe([
                "  - Creating additional asset 'news/photo-2.jpg' in 'images' container.",
                "  - Importing new asset 'news/photo-2.jpg' in 'images' container.",
            ])
            ->and($downloader->calls[0][1] ?? null)->toBe('news/photo-2.jpg');
    });

    it('imports an image that does not exist under its own path when creating additional files', function (): void {
        $lookups = overwriteHandlerAssets([]);
        $output = new BufferedOutput;
        $downloader = new OverwriteHandlerTestDownloader(['uploads/photo.jpg']);

        expect(overwriteHandlerImage(overwriteHandler('Create an additional file'), $output, $downloader, new OverwriteHandlerTestMetadataSetter))->toBe('news/photo.jpg')
            ->and($lookups->ids)->toBe(['images::uploads/photo.jpg'])
            ->and(overwriteHandlerLines($output))->toBe(["  - Importing new asset 'uploads/photo.jpg' in 'images' container."]);
    });

    it('warns about an unknown behavior and overwrites', function (): void {
        overwriteHandlerAssets(['images::uploads/photo.jpg']);
        $output = new BufferedOutput;
        $downloader = new OverwriteHandlerTestDownloader(['uploads/photo.jpg']);

        expect(overwriteHandlerImage(overwriteHandler('Merge'), $output, $downloader, new OverwriteHandlerTestMetadataSetter))->toBe('news/photo.jpg')
            ->and(overwriteHandlerLines($output))->toBe([
                "  - Unknown overwrite behavior 'Merge'. Overwriting by default.",
                "  - Importing new asset 'uploads/photo.jpg' in 'images' container.",
            ]);
    });

    it('returns null and sets no metadata when the download fails', function (): void {
        overwriteHandlerAssets([]);
        $metadata = new OverwriteHandlerTestMetadataSetter;

        expect(overwriteHandlerImage(overwriteHandler(), new BufferedOutput, new OverwriteHandlerTestDownloader([null]), $metadata))->toBeNull()
            ->and($metadata->calls)->toBe([]);
    });

    it('sets empty alt text and caption, and no other image metadata, when none is given', function (): void {
        overwriteHandlerAssets([]);
        $metadata = new OverwriteHandlerTestMetadataSetter;

        overwriteHandler()->handleImageImport('https://example.org/photo.jpg', 'uploads/photo.jpg', 'news', new BufferedOutput, new OverwriteHandlerTestDownloader(['uploads/photo.jpg']), $metadata);

        expect($metadata->calls)->toBe([['image', ['uploads/photo.jpg', 'images', '', '', 'https://example.org/photo.jpg', null, null, null]]]);
    });

    it('tries the full-size image before the sized one', function (): void {
        overwriteHandlerAssets([]);
        $downloader = new OverwriteHandlerTestDownloader([null, 'uploads/photo.jpg']);

        overwriteHandler()->handleImageImport('https://example.org/photo-300x200.jpg', 'uploads/photo.jpg', 'news', new BufferedOutput, $downloader, new OverwriteHandlerTestMetadataSetter);

        expect(\array_column($downloader->calls, 0))->toBe(['https://example.org/photo.jpg', 'https://example.org/photo-300x200.jpg']);
    });
});

describe('importing a file', function (): void {
    /**
     * Import a file into the `files` container.
     *
     * @return ?string The imported path
     */
    function overwriteHandlerFile(OverwriteHandler $handler, string $path, string $site, BufferedOutput $output, OverwriteHandlerTestDownloader $downloader, ?OverwriteHandlerTestMetadataSetter $metadata = null): ?string
    {
        return $handler->handleFileImport(
            'https://example.org/doc.pdf',
            $path,
            $site,
            'files',
            $output,
            $downloader,
            $metadata,
            'https://example.org/page',
            'Doc',
            ['size' => 5],
            'https://example.org/?attachment_id=2'
        );
    }

    it('puts the path under the site unless it is there already', function (string $path, string $site, string $expected): void {
        $lookups = overwriteHandlerAssets([]);
        $downloader = new OverwriteHandlerTestDownloader(['saved.pdf']);

        overwriteHandlerFile(overwriteHandler(), $path, $site, new BufferedOutput, $downloader);

        expect($lookups->ids)->toBe(["files::{$expected}"])
            ->and($downloader->calls[0][1] ?? null)->toBe($expected);
    })->with([
        'no site' => ['docs/doc.pdf', '', 'docs/doc.pdf'],
        'already under the site' => ['news/doc.pdf', 'news', 'news/doc.pdf'],
        'not under the site' => ['docs/doc.pdf', 'news', 'news/docs/doc.pdf'],
        'a leading slash' => ['/doc.pdf', 'news', 'news/doc.pdf'],
        'a directory starting with the site name' => ['newsroom/doc.pdf', 'news', 'news/newsroom/doc.pdf'],
    ]);

    it('overwrites by default, returning the downloaded path and setting its metadata', function (): void {
        overwriteHandlerAssets(['files::news/doc.pdf']);
        $output = new BufferedOutput;
        $downloader = new OverwriteHandlerTestDownloader(['news/doc-saved.pdf']);
        $metadata = new OverwriteHandlerTestMetadataSetter;

        expect(overwriteHandlerFile(overwriteHandler(), 'doc.pdf', 'news', $output, $downloader, $metadata))->toBe('news/doc-saved.pdf')
            ->and(overwriteHandlerLines($output))->toBe(["  - Importing new asset 'news/doc.pdf' in 'files' container."])
            ->and($downloader->calls)->toBe([['https://example.org/doc.pdf', 'news/doc.pdf', 'files', 60, 3, false, 'https://example.org/page']])
            ->and($metadata->calls)->toBe([['file', ['news/doc-saved.pdf', 'files', 'https://example.org/doc.pdf', 'Doc', ['size' => 5], 'https://example.org/?attachment_id=2']]]);
    });

    it('sets no metadata without a metadata setter', function (): void {
        overwriteHandlerAssets([]);

        expect(overwriteHandlerFile(overwriteHandler(), 'doc.pdf', 'news', new BufferedOutput, new OverwriteHandlerTestDownloader(['news/doc.pdf'])))->toBe('news/doc.pdf');
    });

    it('skips a file that exists, returning its path', function (): void {
        overwriteHandlerAssets(['files::news/docs/doc.pdf']);
        $output = new BufferedOutput;
        $downloader = new OverwriteHandlerTestDownloader([]);

        expect(overwriteHandlerFile(overwriteHandler('Skip matching files'), 'docs/doc.pdf', 'news', $output, $downloader))->toBe('news/docs/doc.pdf')
            ->and(overwriteHandlerLines($output))->toBe(["  - Skipped asset 'news/docs/doc.pdf' in 'files' container as it already exists."])
            ->and($downloader->calls)->toBe([]);
    });

    it('imports a file that does not exist when skipping', function (): void {
        overwriteHandlerAssets([]);
        $downloader = new OverwriteHandlerTestDownloader(['news/doc.pdf']);

        expect(overwriteHandlerFile(overwriteHandler('Skip matching files'), 'doc.pdf', 'news', new BufferedOutput, $downloader))->toBe('news/doc.pdf')
            ->and($downloader->calls)->toHaveCount(1);
    });

    it('creates an additional file beside the existing one with the first free suffix', function (string $path, string $site, array $existing, string $expected): void {
        overwriteHandlerAssets($existing);
        $output = new BufferedOutput;
        $downloader = new OverwriteHandlerTestDownloader([$expected]);

        expect(overwriteHandlerFile(overwriteHandler('Create an additional file'), $path, $site, $output, $downloader))->toBe($expected)
            ->and(overwriteHandlerLines($output))->toBe([
                "  - Creating additional asset '{$expected}' in 'files' container.",
                "  - Importing new asset '{$expected}' in 'files' container.",
            ])
            ->and($downloader->calls[0][1] ?? null)->toBe($expected);
    })->with([
        'in a directory' => ['docs/doc.pdf', 'news', ['files::news/docs/doc.pdf', 'files::news/docs/doc-1.pdf'], 'news/docs/doc-2.pdf'],
        'at the container root' => ['doc.pdf', '', ['files::doc.pdf'], 'doc-1.pdf'],
    ]);

    it('imports a file that does not exist under its own path when creating additional files', function (): void {
        overwriteHandlerAssets([]);
        $output = new BufferedOutput;

        expect(overwriteHandlerFile(overwriteHandler('Create an additional file'), 'doc.pdf', 'news', $output, new OverwriteHandlerTestDownloader(['news/doc.pdf'])))->toBe('news/doc.pdf')
            ->and(overwriteHandlerLines($output))->toBe(["  - Importing new asset 'news/doc.pdf' in 'files' container."]);
    });

    it('warns about an unknown behavior and overwrites', function (): void {
        overwriteHandlerAssets(['files::news/doc.pdf']);
        $output = new BufferedOutput;

        expect(overwriteHandlerFile(overwriteHandler('Merge'), 'doc.pdf', 'news', $output, new OverwriteHandlerTestDownloader(['news/doc.pdf'])))->toBe('news/doc.pdf')
            ->and(overwriteHandlerLines($output))->toBe([
                "  - Unknown overwrite behavior 'Merge'. Overwriting by default.",
                "  - Importing new asset 'news/doc.pdf' in 'files' container.",
            ]);
    });

    it('returns null and sets no metadata when the download fails', function (): void {
        overwriteHandlerAssets([]);
        $metadata = new OverwriteHandlerTestMetadataSetter;

        expect(overwriteHandlerFile(overwriteHandler(), 'doc.pdf', 'news', new BufferedOutput, new OverwriteHandlerTestDownloader([null]), $metadata))->toBeNull()
            ->and($metadata->calls)->toBe([]);
    });
});

describe('reading the download configuration', function (): void {
    it('passes the configured timeout, retries and skip setting to the downloader', function (mixed $timeout, mixed $retries, mixed $skip, int $expectedTimeout, int $expectedRetries, bool $expectedSkip): void {
        config([
            'wordpress-import.asset_timeout' => $timeout,
            'wordpress-import.asset_retries' => $retries,
            'wordpress-import.skip_failed_assets' => $skip,
        ]);
        overwriteHandlerAssets([]);
        $imageDownloader = new OverwriteHandlerTestDownloader(['uploads/photo.jpg']);
        $fileDownloader = new OverwriteHandlerTestDownloader(['doc.pdf']);

        overwriteHandler()->handleImageImport('https://example.org/photo.jpg', 'uploads/photo.jpg', 'news', new BufferedOutput, $imageDownloader, new OverwriteHandlerTestMetadataSetter);
        overwriteHandler()->handleFileImport('https://example.org/doc.pdf', 'doc.pdf', '', 'files', new BufferedOutput, $fileDownloader);

        expect(\array_slice($imageDownloader->calls[0] ?? [], 3, 3))->toBe([$expectedTimeout, $expectedRetries, $expectedSkip])
            ->and(\array_slice($fileDownloader->calls[0] ?? [], 3, 3))->toBe([$expectedTimeout, $expectedRetries, $expectedSkip]);
    })->with([
        'integers and a boolean' => [15, 2, true, 15, 2, true],
        'numeric strings' => ['30', '5', false, 30, 5, false],
        'strings that are not whole numbers' => ['3.5', '-1', 'yes', 60, 3, false],
        'a number with text after it' => ["30\nx", '5 ', 1, 60, 3, false],
        'unset' => [null, null, null, 60, 3, false],
    ]);
});
