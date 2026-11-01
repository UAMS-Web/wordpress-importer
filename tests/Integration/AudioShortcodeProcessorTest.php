<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Facades\Asset;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AudioPostAssetMetadata;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\AudioShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    // Start every case from an empty processing context
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ProcessingContext::clearAudioSeriesConfig();
    ProcessingContext::clearWordPressPostDate();
    ProcessingContext::clearWordPressPostId();
    ProcessingContext::clearWordPressPostTitle();
    ProcessingContext::clearSegmentTitle();
    ProcessingContext::clearAudioAssetTitle();
    ProcessingContext::clearAudioAssetSeries();
    ProcessingContext::clearAudioAssetExcerpt();
    ProcessingContext::clearAudioAssetTranscript();
});

afterEach(function (): void {
    // Leave no audio context behind for the next test file
    ProcessingContext::clearAudioSeriesConfig();
    ProcessingContext::clearWordPressPostDate();
    ProcessingContext::clearWordPressPostId();
    ProcessingContext::clearWordPressPostTitle();
    ProcessingContext::clearSegmentTitle();
    ProcessingContext::clearAudioAssetTitle();
    ProcessingContext::clearAudioAssetSeries();
    ProcessingContext::clearAudioAssetExcerpt();
    ProcessingContext::clearAudioAssetTranscript();
});

/**
 * Return the sole concrete Expectation held inside a CompositeExpectation so its
 * fluent methods are visible to PHPStan; the CompositeExpectation only proxies
 * them through `__call`.
 *
 * File-local for the reason `tests/Integration/TreeBuilderBranchTest.php` gives
 * for its own copy: a shared trait would read to the analyser as unused.
 *
 * @param  LegacyMockInterface|MockInterface  $mock  The mock to expect a call on
 * @param  string  $method  The method expected to be called
 * @return Expectation The unwrapped expectation, ready to chain
 */
function audioShortcodeExpects(LegacyMockInterface|MockInterface $mock, string $method): Expectation
{
    $composite = $mock->shouldReceive($method);

    if (! $composite instanceof CompositeExpectation) {
        throw new RuntimeException('Expected a Mockery CompositeExpectation.');
    }

    /** @var list<ExpectationInterface> $expectations */
    $expectations = new ReflectionClass(CompositeExpectation::class)->getProperty('_expectations')->getValue($composite);

    if (count($expectations) !== 1 || ! $expectations[0] instanceof Expectation) {
        throw new RuntimeException('Expected exactly one Mockery Expectation.');
    }

    return $expectations[0];
}

/**
 * The empty audio set `parse()` returns when no file is produced.
 *
 * @return array<string, mixed> The empty audio set
 */
function audioShortcodeEmpty(): array
{
    return [
        'type' => 'audio',
        'file' => null,
        'file_alternate' => null,
        'preload' => null,
        'loop' => null,
    ];
}

/**
 * The audio set `parse()` returns for a produced file.
 *
 * @param  string  $file  The expected file value
 * @return array<string, mixed> The audio set
 */
function audioShortcodeWith(string $file): array
{
    return array_merge(audioShortcodeEmpty(), ['file' => $file]);
}

/**
 * A series configuration storing assets in the given container.
 *
 * @param  string  $container  The asset container handle
 * @return AudioSeriesConfig The configuration
 */
function audioShortcodeSeries(string $container = 'podcasts'): AudioSeriesConfig
{
    return new AudioSeriesConfig('series', 'Series', 'show', 'Show', 'series', 'show', $container);
}

/**
 * Record every warning and error logged during a test.
 *
 * @return ArrayObject<int, array{level: string, message: string, context: array<string, mixed>}> The log records
 */
function audioShortcodeLogs(): ArrayObject
{
    $logs = new ArrayObject;

    foreach (['warning', 'error'] as $level) {
        Log::shouldReceive($level)->andReturnUsing(
            function (string $message, array $context = []) use ($logs, $level): void {
                $logs->append(['level' => $level, 'message' => $message, 'context' => $context]);
            }
        );
    }

    return $logs;
}

/**
 * Make `Asset::find()` resolve to a mock asset that records what is saved.
 *
 * @param  string  $reference  The `container::path` reference expected
 * @return ArrayObject<string, mixed> The data written to the asset, filled on save
 */
function audioShortcodeAsset(string $reference): ArrayObject
{
    $written = new ArrayObject;
    /** @var StatamicAsset&MockInterface $asset */
    $asset = Mockery::mock(StatamicAsset::class);
    audioShortcodeExpects($asset, 'data')->withNoArgs()->andReturn(collect(['alt' => 'kept']));
    audioShortcodeExpects($asset, 'data')->with(Mockery::type('array'))->andReturnUsing(
        function (array $data) use ($written, $asset): StatamicAsset {
            $written->exchangeArray($data);

            return $asset;
        }
    );
    audioShortcodeExpects($asset, 'save')->once()->andReturnTrue();

    Asset::shouldReceive('find')->once()->with($reference)->andReturn($asset);

    return $written;
}

it('returns the empty audio set without importing when the mp3 attribute is missing or unusable', function (array $attributes): void {
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    $importer->shouldNotReceive('getUploadFiles');
    $importer->shouldNotReceive('importFile');

    expect((new AudioShortcodeProcessor)->parse($attributes, 'default', $importer, new BufferedOutput))
        ->toBe(audioShortcodeEmpty());
})->with([
    'absent' => [[]],
    'empty' => [['mp3' => '']],
    'integer' => [['mp3' => 123]],
    'array' => [['mp3' => ['https://example.com/a.mp3']]],
]);

it('rewrites a legacy URL and keeps it when file uploads are disabled', function (string $url, string $expected): void {
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    audioShortcodeExpects($importer, 'getUploadFiles')->andReturnFalse();
    $importer->shouldNotReceive('importFile');
    $output = new BufferedOutput;

    expect((new AudioShortcodeProcessor)->parse(['mp3' => $url], 'default', $importer, $output))
        ->toBe(audioShortcodeWith($expected))
        ->and($output->fetch())->toBe('  - File uploads disabled. Using original URL for audio file.'.PHP_EOL);
})->with([
    'uamshealth http' => ['http://uamshealth.com/wp-content/uploads/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'uamshealth https' => ['https://uamshealth.com/wp-content/uploads/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'uamsweb http' => ['http://news.uamsweb.com/wp-content/uploads/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'uamsweb https' => ['https://news.uamsweb.com/wp-content/uploads/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'relative' => ['/wp-content/uploads/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'news double slash http' => ['http://news.uams.edu//wp-content/uploads/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'news double slash https' => ['https://news.uams.edu//wp-content/uploads/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'news pload http' => ['http://news.uams.edu/pload/media/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'news pload https' => ['https://news.uams.edu/pload/media/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'news upload http' => ['http://news.uams.edu/upload/media/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'news upload https' => ['https://news.uams.edu/upload/media/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'uamsweb double slash http' => ['http://news.uamsweb.com//wp-content/uploads/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'uamsweb double slash https' => ['https://news.uamsweb.com//wp-content/uploads/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'uamsweb pload http' => ['http://news.uamsweb.com/pload/media/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'uamsweb pload https' => ['https://news.uamsweb.com/pload/media/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'uamsweb upload http' => ['http://news.uamsweb.com/upload/media/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'uamsweb upload https' => ['https://news.uamsweb.com/upload/media/a.mp3', 'https://news.uams.edu/wp-content/uploads/a.mp3'],
    'pattern only as a prefix' => ['https://cdn.example.com/wp-content/uploads/a.mp3', 'https://cdn.example.com/wp-content/uploads/a.mp3'],
    'unrelated' => ['https://example.com/a.mp3', 'https://example.com/a.mp3'],
]);

it('imports a non-series file into the audio container with the audio defaults', function (): void {
    $output = new BufferedOutput;
    $calls = new ArrayObject;
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    audioShortcodeExpects($importer, 'getUploadFiles')->andReturnTrue();
    $importer->shouldNotReceive('getBaseBlogUrl');
    audioShortcodeExpects($importer, 'importFile')->once()->andReturnUsing(
        function (mixed ...$args) use ($calls): string {
            $calls->exchangeArray($args);

            return 'episode.mp3';
        }
    );
    $written = audioShortcodeAsset('audio::episode.mp3');

    $result = (new AudioShortcodeProcessor)->parse(['mp3' => '/wp-content/uploads/a.mp3'], 'site-a', $importer, $output);

    expect($result)->toBe(audioShortcodeWith('episode.mp3'))
        ->and($calls->getArrayCopy())->toBe([
            'https://news.uams.edu/wp-content/uploads/a.mp3',
            'site-a',
            $output,
            'audio',
            SameSitePolicy::ALWAYS_IMPORT,
            null,
            null,
            AudioPostAssetMetadata::defaults(),
        ])
        ->and($written['alt'])->toBe('kept')
        ->and($written)->not->toHaveKeys(['title', 'series', 'excerpt', 'transcript']);
});

it('writes the shortcode title, series, excerpt and transcript to the imported asset', function (): void {
    ProcessingContext::setAudioAssetTitle('Episode One');
    ProcessingContext::setAudioAssetSeries('season-1');
    ProcessingContext::setAudioAssetExcerpt([['type' => 'paragraph']]);
    ProcessingContext::setAudioAssetTranscript([['type' => 'text']]);
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    audioShortcodeExpects($importer, 'getUploadFiles')->andReturnTrue();
    audioShortcodeExpects($importer, 'importFile')->once()->andReturn('episode.mp3');
    $written = audioShortcodeAsset('audio::episode.mp3');

    (new AudioShortcodeProcessor)->parse(['mp3' => 'https://example.com/a.mp3'], 'default', $importer, new BufferedOutput);

    expect($written['title'])->toBe('Episode One')
        ->and($written['series'])->toBe(['season-1'])
        ->and($written['excerpt'])->toBe([['type' => 'paragraph']])
        ->and($written['transcript'])->toBe([['type' => 'text']]);
});

it('logs an error and returns the empty set when the import fails', function (?string $imported): void {
    $logs = audioShortcodeLogs();
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    audioShortcodeExpects($importer, 'getUploadFiles')->andReturnTrue();
    audioShortcodeExpects($importer, 'importFile')->once()->andReturn($imported);
    Asset::shouldReceive('find')->never();

    $result = (new AudioShortcodeProcessor)->parse(['mp3' => 'https://example.com/a.mp3'], 'site-a', $importer, new BufferedOutput);

    expect($result)->toBe(audioShortcodeEmpty())
        ->and($logs->getArrayCopy())->toBe([[
            'level' => 'error',
            'message' => 'AudioShortcodeProcessor::parse: Failed to import audio file',
            'context' => ['site' => 'site-a', 'mp3_url' => 'https://example.com/a.mp3'],
        ]]);
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('imports through the series manager into the series container when the post context is complete', function (): void {
    ProcessingContext::setAudioSeriesConfig(audioShortcodeSeries('podcasts'));
    ProcessingContext::setWordPressPostDate('2024-01-02');
    ProcessingContext::setWordPressPostId('42');
    ProcessingContext::setWordPressPostTitle('Title');
    ProcessingContext::setSegmentTitle('Part A');
    $url = 'https://example.com/a.mp3';
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    audioShortcodeExpects($importer, 'getUploadFiles')->andReturnTrue();
    audioShortcodeExpects($importer, 'getBaseBlogUrl')->andReturnNull();
    audioShortcodeExpects($importer, 'getFileCacheEntry')->with($url.'|Part A|42|Title')->andReturn('cached.mp3');
    $importer->shouldNotReceive('importFile');
    $written = audioShortcodeAsset('podcasts::cached.mp3');

    $result = (new AudioShortcodeProcessor)->parse(['mp3' => $url], 'default', $importer, new BufferedOutput);

    expect($result)->toBe(audioShortcodeWith('cached.mp3'))
        ->and($written['creator_organization'])->toBe('UAMS');
});

it('falls back to a plain import into the series container when the post context is incomplete', function (?string $date, ?string $id, ?string $title): void {
    ProcessingContext::setAudioSeriesConfig(audioShortcodeSeries('podcasts'));
    if ($date !== null) {
        ProcessingContext::setWordPressPostDate($date);
    }
    if ($id !== null) {
        ProcessingContext::setWordPressPostId($id);
    }
    if ($title !== null) {
        ProcessingContext::setWordPressPostTitle($title);
    }
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    audioShortcodeExpects($importer, 'getUploadFiles')->andReturnTrue();
    $importer->shouldNotReceive('getBaseBlogUrl');
    audioShortcodeExpects($importer, 'importFile')->once()->andReturn('plain.mp3');
    audioShortcodeAsset('podcasts::plain.mp3');

    expect((new AudioShortcodeProcessor)->parse(['mp3' => 'https://example.com/a.mp3'], 'default', $importer, new BufferedOutput))
        ->toBe(audioShortcodeWith('plain.mp3'));
})->with([
    'no date' => [null, '42', 'Title'],
    'empty date' => ['', '42', 'Title'],
    'no id' => ['2024-01-02', null, 'Title'],
    'empty id' => ['2024-01-02', '', 'Title'],
    'no title' => ['2024-01-02', '42', null],
    'empty title' => ['2024-01-02', '42', ''],
]);

it('uses the plain import when no series is configured, even with a complete post context', function (): void {
    ProcessingContext::setWordPressPostDate('2024-01-02');
    ProcessingContext::setWordPressPostId('42');
    ProcessingContext::setWordPressPostTitle('Title');
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    audioShortcodeExpects($importer, 'getUploadFiles')->andReturnTrue();
    $importer->shouldNotReceive('getBaseBlogUrl');
    audioShortcodeExpects($importer, 'importFile')->once()->andReturn('plain.mp3');
    audioShortcodeAsset('audio::plain.mp3');

    expect((new AudioShortcodeProcessor)->parse(['mp3' => 'https://example.com/a.mp3'], 'default', $importer, new BufferedOutput))
        ->toBe(audioShortcodeWith('plain.mp3'));
});
