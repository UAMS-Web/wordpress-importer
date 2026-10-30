<?php

declare(strict_types=1);

/**
 * Tests the `AssetUtils` helpers other than the existence check, which
 * `AssetUtilsTest` covers: MIME guessing, same-site URL matching, the
 * WordPress size suffix, the full-size download fallback, upload path parsing,
 * the same-site policy, and the guarded asset lookup.
 *
 * Integration rather than Unit because the upload path helpers read
 * `statamic.assets.lowercase` and the lookup goes through the `Asset` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/AssetUtilsHelpersTest.php
 */

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Log;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Facades\Asset as AssetFacade;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\CompilesCleanly;

uses(StatamicTestCase::class);

// A `continue` mutated into one of the class's switches compiles to a
// `break` with a warning; fail on it whichever test file loads the class first
beforeEach(function (): void {
    CompilesCleanly::assert(AssetUtils::class);
});

afterEach(function (): void {
    Mockery::close();
});

/**
 * A download callback that records each call and returns a queued result.
 */
final class AssetUtilsHelpersDownloads
{
    /**
     * The arguments of each call, without the output.
     *
     * @var list<list<mixed>>
     */
    public array $calls = [];

    /**
     * Start with the results to return, in order.
     *
     * @param  list<mixed>  $results  The result of each call
     */
    public function __construct(public array $results) {}

    /**
     * Record the call and return the next result.
     *
     * @param  string  $url  The URL
     * @param  string  $path  The path
     * @param  string  $container  The container
     * @param  OutputInterface  $output  The output
     * @param  int  $timeout  The timeout
     * @param  int  $retries  The retries
     * @param  bool  $skip  Whether to skip on failure
     * @param  ?string  $pageUrl  The page URL
     * @return mixed The next queued result
     */
    public function __invoke(string $url, string $path, string $container, OutputInterface $output, int $timeout, int $retries, bool $skip, ?string $pageUrl): mixed
    {
        $this->calls[] = [$url, $path, $container, $timeout, $retries, $skip, $pageUrl];

        return \array_shift($this->results);
    }
}

describe('guessing a MIME type', function (): void {
    it('maps each known extension, in any case', function (string $filename, string $expected): void {
        expect(AssetUtils::guessMimeType($filename))->toBe($expected);
    })->with([
        'jpg' => ['photo.jpg', 'image/jpeg'],
        'jpeg in capitals' => ['PHOTO.JPEG', 'image/jpeg'],
        'png' => ['logo.PNG', 'image/png'],
        'gif' => ['a.gif', 'image/gif'],
        'pdf' => ['report.Pdf', 'application/pdf'],
        'xlsx' => ['sheet.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        'docx' => ['letter.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        'pptx' => ['deck.pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation'],
        'mp3' => ['talk.mp3', 'audio/mpeg'],
        'mp4' => ['clip.MP4', 'video/mp4'],
        'unknown' => ['notes.txt', 'application/octet-stream'],
        'no extension' => ['README', 'application/octet-stream'],
    ]);
});

describe('matching a same-site URL', function (): void {
    it('compares URLs without their scheme, case or slashes, on a path boundary', function (string $url, string $base, bool $expected): void {
        expect(AssetUtils::isSameSiteUrl($url, $base))->toBe($expected)
            ->and(AssetUtils::isUrlOnSameSite($url, $base))->toBe($expected);
    })->with([
        'the base itself' => ['https://uams.edu/news', 'https://uams.edu/news', true],
        'a path under the base' => ['https://uams.edu/news/a.jpg', 'https://uams.edu/news', true],
        'the other scheme' => ['http://uams.edu/news/a.jpg', 'https://uams.edu/news', true],
        'no scheme' => ['//uams.edu/news/a.jpg', 'uams.edu/news', true],
        'the URL in capitals' => ['HTTPS://UAMS.EDU/NEWS/A.JPG', 'https://uams.edu/news', true],
        'the base in capitals' => ['https://uams.edu/news/a.jpg', 'HTTPS://UAMS.EDU/NEWS', true],
        'a base with a trailing slash' => ['https://uams.edu/news/a.jpg', 'https://uams.edu/news/', true],
        'a URL with a trailing slash' => ['https://uams.edu/news/', 'https://uams.edu/news', true],
        'a longer name sharing the prefix' => ['https://uams.edu/newsroom/a.jpg', 'https://uams.edu/news', false],
        'another site' => ['https://example.org/news/a.jpg', 'https://uams.edu/news', false],
        'a shorter URL' => ['https://uams.edu', 'https://uams.edu/news', false],
    ]);

    it('treats every URL as same-site when there is no base', function (?string $base): void {
        expect(AssetUtils::isUrlOnSameSite('https://example.org/a.jpg', $base))->toBeTrue();
    })->with([
        'no base' => [null],
        'an empty base' => [''],
    ]);

    it('treats every URL as same-site against an empty base', function (): void {
        expect(AssetUtils::isSameSiteUrl('https://example.org/a.jpg', ''))->toBeTrue();
    });
});

describe('removing a WordPress size suffix', function (): void {
    it('removes the size suffix before the extension, and only that', function (string $url, string $expected): void {
        expect(AssetUtils::normalizeWordPressImageUrl($url))->toBe($expected);
    })->with([
        'a sized image' => ['https://uams.edu/wp-content/uploads/photo-300x200.jpg', 'https://uams.edu/wp-content/uploads/photo.jpg'],
        'a size in the name, not before the extension' => ['https://uams.edu/photo-300x200-crop.jpg', 'https://uams.edu/photo-300x200-crop.jpg'],
        'no size' => ['https://uams.edu/photo.jpg', 'https://uams.edu/photo.jpg'],
        'no extension' => ['https://uams.edu/photo-300x200', 'https://uams.edu/photo-300x200'],
    ]);
});

describe('downloading the full-size image first', function (): void {
    it('downloads the full-size image when the URL is sized, passing the download settings', function (): void {
        $downloads = new AssetUtilsHelpersDownloads(['news/photo.jpg']);
        $output = new BufferedOutput;

        $result = AssetUtils::downloadImageWithFullSizeFallback('https://uams.edu/photo-300x200.jpg', $downloads, 'news/photo.jpg', 'images', $output, 15, 2, true, 'https://uams.edu/page');

        expect($result)->toBe('news/photo.jpg')
            ->and($downloads->calls)->toBe([['https://uams.edu/photo.jpg', 'news/photo.jpg', 'images', 15, 2, true, 'https://uams.edu/page']])
            ->and($output->fetch())->toBe(
                "      - Detected WordPress image size suffix '300x200'. Attempting full-size version first.".PHP_EOL
                ."      - Successfully downloaded full-size version from 'https://uams.edu/photo.jpg'.".PHP_EOL
            );
    });

    it('falls back to the sized image when the full-size download fails', function (): void {
        $downloads = new AssetUtilsHelpersDownloads([null, 'news/photo.jpg']);
        $output = new BufferedOutput;

        expect(AssetUtils::downloadImageWithFullSizeFallback('https://uams.edu/photo-300x200.jpg', $downloads, 'news/photo.jpg', 'images', $output))->toBe('news/photo.jpg')
            ->and(\array_column($downloads->calls, 0))->toBe(['https://uams.edu/photo.jpg', 'https://uams.edu/photo-300x200.jpg'])
            ->and($output->fetch())->toBe(
                "      - Detected WordPress image size suffix '300x200'. Attempting full-size version first.".PHP_EOL
                .'      - Full-size version failed. Falling back to original URL with size suffix.'.PHP_EOL
            );
    });

    it('downloads an image without a size once, with the default settings', function (): void {
        $downloads = new AssetUtilsHelpersDownloads(['news/photo.jpg']);
        $output = new BufferedOutput;

        expect(AssetUtils::downloadImageWithFullSizeFallback('https://uams.edu/photo.jpg', $downloads, 'news/photo.jpg', 'images', $output))->toBe('news/photo.jpg')
            ->and($downloads->calls)->toBe([['https://uams.edu/photo.jpg', 'news/photo.jpg', 'images', 60, 3, false, null]])
            ->and($output->fetch())->toBe('');
    });

    it('returns null when no download returns a path', function (mixed $result): void {
        expect(AssetUtils::downloadImageWithFullSizeFallback('https://uams.edu/photo.jpg', new AssetUtilsHelpersDownloads([$result]), 'p', 'images', new BufferedOutput))->toBeNull();
    })->with([
        'nothing' => [null],
        'something other than a path' => [false],
    ]);
});

describe('reading a WordPress upload path', function (): void {
    it('prefixes the filename with the upload year and month', function (string $url, string $expected, string $year, string $month): void {
        config(['statamic.assets.lowercase' => false]);

        expect(AssetUtils::extractWordPressPathInfo($url))->toBe(['filename' => $expected, 'year' => $year, 'month' => $month])
            ->and(AssetUtils::generateUniqueFilename($url))->toBe($expected);
    })->with([
        'a multisite upload' => ['https://uams.edu/wp-content/uploads/sites/12/2019/07/Photo.jpg', '2019-07-Photo.jpg', '2019', '07'],
        'a single-site upload' => ['https://uams.edu/wp-content/uploads/2021/11/Report.PDF', '2021-11-Report.PDF', '2021', '11'],
    ]);

    it('lowercases the filename when Statamic lowercases asset names', function (): void {
        config(['statamic.assets.lowercase' => true]);

        expect(AssetUtils::extractWordPressPathInfo('https://uams.edu/wp-content/uploads/2021/11/Report.PDF')['filename'])->toBe('2021-11-report.pdf')
            ->and(AssetUtils::generateUniqueFilename('https://uams.edu/wp-content/uploads/2021/11/Report.PDF'))->toBe('2021-11-report.pdf');
    });

    it('keeps the filename\'s case when the lowercase setting is absent', function (): void {
        // Setting the key to null would still count as set, so drop it
        $assets = config('statamic.assets');
        config(['statamic.assets' => \is_array($assets) ? \array_diff_key($assets, ['lowercase' => true]) : []]);

        expect(AssetUtils::generateUniqueFilename('https://uams.edu/wp-content/uploads/2021/11/Report.PDF'))->toBe('2021-11-Report.PDF');
    });

    it('uses the current year and month when the URL has no upload date', function (string $url, string $basename): void {
        config(['statamic.assets.lowercase' => false]);
        $before = [\date('Y'), \date('m')];
        $info = AssetUtils::extractWordPressPathInfo($url);
        $after = [\date('Y'), \date('m')];

        // Accept either reading, in case the month turned during the call
        expect(\in_array([$info['year'], $info['month']], [$before, $after], true))->toBeTrue()
            ->and($info['filename'])->toBe("{$info['year']}-{$info['month']}-{$basename}");
    })->with([
        'a path without a date' => ['https://uams.edu/files/Photo.jpg', 'Photo.jpg'],
        'no path at all' => ['https://uams.edu', 'uams.edu'],
        'a multisite path without a site number' => ['https://uams.edu/wp-content/uploads/sites/2018/05/a.jpg', 'a.jpg'],
    ]);
});

describe('applying the same-site policy', function (): void {
    it('imports a same-site asset whatever the policy, saying nothing', function (SameSitePolicy $policy): void {
        $output = new BufferedOutput;

        expect(AssetUtils::applySameSitePolicy('https://uams.edu/news/a.jpg', $policy, $output, 'https://uams.edu/news'))->toBeNull()
            ->and($output->fetch())->toBe('');
    })->with(SameSitePolicy::cases());

    it('applies the policy to an asset on another site', function (SameSitePolicy $policy, ?string $expected, string $message): void {
        $output = new BufferedOutput;

        expect(AssetUtils::applySameSitePolicy('https://example.org/a.jpg', $policy, $output, 'https://uams.edu/news'))->toBe($expected)
            ->and($output->fetch())->toBe($message.PHP_EOL);
    })->with([
        'skip' => [SameSitePolicy::SKIP_DIFFERENT_SITE, null, '  - Asset not on same site detected. Skipping download.'],
        'link' => [SameSitePolicy::LINK_DIFFERENT_SITE, 'https://example.org/a.jpg', "  - Asset not on same site detected. Linking directly to 'https://example.org/a.jpg'."],
        'import' => [SameSitePolicy::ALWAYS_IMPORT, null, "  - Asset not on same site detected. Importing directly from 'https://example.org/a.jpg'."],
    ]);
});

describe('finding an asset safely', function (): void {
    it('returns the asset a lookup finds', function (): void {
        $asset = new StatamicAsset;
        AssetFacade::shouldReceive('find')->once()->with('images::a.jpg')->andReturn($asset);

        expect(AssetUtils::findAssetSafely('images::a.jpg', 'images', 'a.jpg'))->toBe($asset);
    });

    it('warns and reports no asset when the asset metadata violates a constraint', function (?string $pageUrl, string $suffix, array $entry): void {
        AssetFacade::shouldReceive('find')->andThrow(new UniqueConstraintViolationException('mysql', 'insert', [], new RuntimeException('Duplicate entry')));
        $warnings = [];
        Log::shouldReceive('warning')->andReturnUsing(static function (string $message, array $context) use (&$warnings): void {
            $warnings[] = [$message, $context];
        });

        expect(AssetUtils::findAssetSafely('images::a.jpg', 'images', 'a.jpg', $pageUrl, 'Caller::method'))->toBeNull()
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0][0] ?? null)->toBe('Caller::method: Asset metadata constraint error when checking for existing asset. Assuming asset does not exist and proceeding.'.$suffix)
            ->and(\array_diff_key($warnings[0][1] ?? [], ['error' => true]))->toBe(['asset_id' => 'images::a.jpg', 'container' => 'images', 'path' => 'a.jpg', ...$entry])
            ->and($warnings[0][1]['error'] ?? null)->toContain('Duplicate entry');
    })->with([
        'with a page URL' => ['https://uams.edu/page', ' (from entry: https://uams.edu/page)', ['entry_url' => 'https://uams.edu/page']],
        'without one' => [null, '', []],
    ]);

    it('names itself in the warning by default', function (): void {
        AssetFacade::shouldReceive('find')->andThrow(new UniqueConstraintViolationException('mysql', 'insert', [], new RuntimeException('Duplicate entry')));
        $messages = [];
        Log::shouldReceive('warning')->andReturnUsing(static function (string $message) use (&$messages): void {
            $messages[] = $message;
        });

        AssetUtils::findAssetSafely('images::a.jpg', 'images', 'a.jpg');

        expect($messages)->toBe(['AssetUtils::findAssetSafely: Asset metadata constraint error when checking for existing asset. Assuming asset does not exist and proceeding.']);
    });
});
