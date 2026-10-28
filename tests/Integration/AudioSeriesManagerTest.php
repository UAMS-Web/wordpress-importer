<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection as IlluminateCollection;
use Illuminate\Support\Facades\Event;
use Statamic\Assets\Asset;
use Statamic\Facades\Asset as AssetFacade;
use Statamic\Facades\Term as TermFacade;
use Statamic\Taxonomies\Term;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetDownloader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AudioPostAssetMetadata;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\MetadataSetter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\OverwriteHandler;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests `AudioSeriesManager`: series detection, the series term, the import of a series'
 * audio files, and the metadata written onto an audio asset.
 *
 * Collaborators are replaced at their seams: an `AssetImporter` whose overwrite handler records
 * what it is asked to import, a `SiteManager` that records what it is asked to enable, and an
 * in-memory term and asset. Statamic's facades return those stand-ins.
 *
 * The file cache is written under a different key from the one it is read under, so a repeated
 * import never finds it, which UAMS-Web/wordpress-importer#259 tracks. The cases below seed the
 * key the read side asks for, and assert the write separately, so each side is pinned as it is.
 *
 * @command  php vendor/bin/pest tests/Integration/AudioSeriesManagerTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    AssetImporter::clearFileCache();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

afterEach(function (): void {
    AssetImporter::clearFileCache();
    audioSeriesManagerResetRegistry();
    AudioSeriesRegistry::ensureInitialized();
    Mockery::close();
});

/**
 * Return the registry and the manager to their state before any series was registered.
 */
function audioSeriesManagerResetRegistry(): void
{
    (new ReflectionProperty(AudioSeriesRegistry::class, 'initialized'))->setValue(null, false);
    (new ReflectionProperty(AudioSeriesManager::class, 'registeredSeries'))->setValue(null, []);
}

/**
 * An overwrite handler that records each import it is asked for, and answers with a set path.
 */
final class AudioSeriesManagerRecordingHandler extends OverwriteHandler
{
    /**
     * @var list<array{url: string, path: string, site: string, container: string, metadata: ?array<int|string, mixed>}> Each import asked for
     */
    public array $imports = [];

    /**
     * Keep the path to answer with.
     *
     * @param  ?string  $answer  The path to return from every import
     */
    public function __construct(
        public ?string $answer = 'imported/path.mp3'
    ) {}

    /**
     * Record the import rather than running it.
     *
     * @param  string  $url  The source URL
     * @param  string  $path  The target path
     * @param  string  $site  The folder
     * @param  string  $container  The asset container
     * @param  OutputInterface  $output  Console output
     * @param  AssetDownloader  $downloader  The downloader
     * @param  ?MetadataSetter  $metadataSetter  The metadata setter
     * @param  ?string  $pageUrl  The page URL
     * @param  ?string  $title  The title
     * @param  ?array<int|string, mixed>  $additionalFileMetadata  Extra metadata
     * @param  ?string  $attachmentPageUrl  The attachment page URL
     * @return ?string The set path
     */
    public function handleFileImport(
        string $url,
        string $path,
        string $site,
        string $container,
        OutputInterface $output,
        AssetDownloader $downloader,
        ?MetadataSetter $metadataSetter = null,
        ?string $pageUrl = null,
        ?string $title = null,
        ?array $additionalFileMetadata = null,
        ?string $attachmentPageUrl = null
    ): ?string {
        $this->imports[] = ['url' => $url, 'path' => $path, 'site' => $site, 'container' => $container, 'metadata' => $additionalFileMetadata];

        return $this->answer;
    }
}

/**
 * An asset importer that hands out the recording handler.
 */
final class AudioSeriesManagerImporter extends AssetImporter
{
    /**
     * Keep the handler and the base blog URL.
     *
     * @param  AudioSeriesManagerRecordingHandler  $handler  The handler to hand out
     * @param  ?string  $baseBlogUrl  The base blog URL
     */
    public function __construct(
        public readonly AudioSeriesManagerRecordingHandler $handler,
        ?string $baseBlogUrl = 'https://x.test'
    ) {
        parent::__construct($baseBlogUrl);
    }

    /**
     * The recording handler.
     */
    public function getOverwriteHandler(): OverwriteHandler
    {
        return $this->handler;
    }

    /**
     * Put an entry in the file cache under exactly the given key.
     *
     * @param  string  $key  The key
     * @param  mixed  $value  The cached value
     */
    public static function seed(string $key, mixed $value): void
    {
        $property = new ReflectionProperty(AssetImporter::class, 'fileCache');
        $cache = $property->getValue();
        $cache = \is_array($cache) ? $cache : [];
        $cache[$key] = $value;
        $property->setValue(null, $cache);
    }
}

/**
 * A site manager that records each taxonomy it is asked to enable.
 */
final class AudioSeriesManagerRecordingSiteManager extends SiteManager
{
    /**
     * @var list<array{site: string, taxonomy: string, output: ?OutputInterface}> Each call
     */
    public array $calls = [];

    /**
     * Record the call rather than changing the taxonomy.
     *
     * @param  string  $site  The site handle
     * @param  string  $statamicTaxonomyHandle  The taxonomy handle
     * @param  ?OutputInterface  $output  Console output
     * @return bool Always true
     */
    public function enableTaxonomyForSite(
        string $site,
        string $statamicTaxonomyHandle,
        ?OutputInterface $output = null
    ): bool {
        $this->calls[] = ['site' => $site, 'taxonomy' => $statamicTaxonomyHandle, 'output' => $output];

        return true;
    }
}

/**
 * An in-memory term that records what is written to it and reads back what it was seeded with.
 */
final class AudioSeriesManagerTerm extends Term
{
    /**
     * @var array<string, mixed> Everything `set()` recorded
     */
    public array $written = [];

    /**
     * @var int How many times the term was saved
     */
    public int $saves = 0;

    /**
     * Seed the values the term already holds.
     *
     * @param  array<string, mixed>  $existing  The values
     */
    public function __construct(
        private readonly array $existing = []
    ) {
        parent::__construct();
    }

    /**
     * Record a written value.
     *
     * @param  mixed  $key  The field handle
     * @param  mixed  $value  The value
     * @return self This term
     */
    public function set($key, $value): self
    {
        $this->written[\is_string($key) ? $key : ''] = $value;

        return $this;
    }

    /**
     * Read a seeded value.
     *
     * @param  mixed  $key  The field handle
     * @param  mixed  $fallback  The value for an unseeded key
     * @return mixed The value
     */
    public function get($key, $fallback = null)
    {
        return \is_string($key) ? ($this->existing[$key] ?? $fallback) : $fallback;
    }

    /**
     * Read from this term itself.
     *
     * @return self This term
     */
    public function inDefaultLocale()
    {
        return $this;
    }

    /**
     * Count the save.
     *
     * @return bool Always true
     */
    public function save()
    {
        $this->saves++;

        return true;
    }
}

/**
 * An in-memory asset holding seeded data, which records what is written to it.
 */
final class AudioSeriesManagerAsset extends Asset
{
    /**
     * @var ?array<int|string, mixed> The data last written, or null when none was
     */
    public ?array $written = null;

    /**
     * @var int How many times the asset was saved
     */
    public int $saves = 0;

    /**
     * Seed the data the asset already holds.
     *
     * @param  array<int|string, mixed>  $existing  The data
     */
    public function __construct(
        private readonly array $existing = []
    ) {
        parent::__construct();
    }

    /**
     * Read the seeded data, or record written data.
     *
     * @param  mixed  $data  The data to write, or nothing to read
     * @return mixed The seeded data as a collection, or this asset
     */
    public function data($data = null)
    {
        if (\func_num_args() === 0) {
            return new IlluminateCollection($this->existing);
        }

        $this->written = \is_array($data) ? $data : null;

        return $this;
    }

    /**
     * Count the save.
     *
     * @return bool Always true
     */
    public function save()
    {
        $this->saves++;

        return true;
    }
}

/**
 * Collect the warnings the log receives.
 *
 * @return ArrayObject<int, array{string, mixed}> Each warning's message and context
 */
function audioSeriesManagerWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{string, mixed}> $warnings */
    $warnings = new ArrayObject;

    Event::listen(MessageLogged::class, static function (MessageLogged $logged) use ($warnings): void {
        if ($logged->level === 'warning') {
            $warnings->append([$logged->message, $logged->context]);
        }
    });

    return $warnings;
}

/**
 * A podcast series with an upper-case filename prefix and the site-directory layout chosen.
 *
 * @param  bool  $useSiteDirectory  Whether files go under the site's directory
 */
function audioSeriesManagerConfig(bool $useSiteDirectory = false): AudioSeriesConfig
{
    return new AudioSeriesConfig('audio_series', 'Audio Series', 'talk', 'Talk', 'audio_series', 'TALK', 'podcasts', $useSiteDirectory, ['podcast']);
}

/**
 * Import one file with the given arguments.
 *
 * @param  AudioSeriesManagerImporter  $importer  The importer
 * @param  BufferedOutput  $output  Console output
 * @param  string  $url  The file URL
 * @param  ?string  $segmentTitle  The segment title
 * @param  ?AudioSeriesConfig  $config  The series
 * @return ?string The imported path
 */
function audioSeriesManagerImport(
    AudioSeriesManagerImporter $importer,
    BufferedOutput $output,
    string $url = 'https://x.test/a.mp3',
    ?string $segmentTitle = null,
    ?AudioSeriesConfig $config = null
): ?string {
    return AudioSeriesManager::importAudioSeriesFile(
        $url,
        'default',
        $output,
        $importer,
        $config ?? audioSeriesManagerConfig(),
        '2024-01-02 10:00:00',
        '7',
        '<b>Episode</b> One',
        $segmentTitle
    );
}

it('initializes the registry before looking a category up', function (): void {
    audioSeriesManagerResetRegistry();

    expect(AudioSeriesManager::getSeriesConfigByCategory('heres-to-your-health')?->getTermSlug())->toBe('heres-to-your-health');

    audioSeriesManagerResetRegistry();

    expect(AudioSeriesManager::isAudioSeriesTaxonomy('audio_series'))->toBeTrue();
});

it('finds a series by category among several, and nothing for an unknown one', function (): void {
    AudioSeriesManager::registerSeries(audioSeriesManagerConfig());

    expect(AudioSeriesManager::getSeriesConfigByCategory('talk')?->getFilenamePrefix())->toBe('TALK')
        ->and(AudioSeriesManager::getSeriesConfigByCategory('unknown'))->toBeNull()
        ->and(AudioSeriesManager::isAudioSeriesTaxonomy('tags'))->toBeFalse();
});

it('detects the first series among the categories, and enables its taxonomy for the site', function (): void {
    AudioSeriesManager::registerSeries(audioSeriesManagerConfig());
    $output = new BufferedOutput;

    $config = AudioSeriesManager::detectAndProcessAudioSeries([5, 'news', 'talk', 'heres-to-your-health'], 'default', $output);

    expect($config?->getTermSlug())->toBe('talk')
        ->and($output->fetch())->toContain("Checking if the 'audio_series' Statamic taxonomy is enabled for the 'default' site...");
});

it('detects nothing and enables nothing for categories with no series', function (): void {
    $output = new BufferedOutput;

    expect(AudioSeriesManager::detectAndProcessAudioSeries(['news', 3], 'default', $output))->toBeNull()
        ->and($output->fetch())->toBe('');
});

it('asks the site manager to enable the taxonomy for the site', function (): void {
    $siteManager = new AudioSeriesManagerRecordingSiteManager;
    $output = new BufferedOutput;

    AudioSeriesManager::enableAudioSeriesTaxonomy('audio_series', 'default', $output, $siteManager);

    expect($siteManager->calls)->toBe([['site' => 'default', 'taxonomy' => 'audio_series', 'output' => $output]]);
});

it('creates a term in the series taxonomy with its slug, and says so', function (): void {
    $term = new AudioSeriesManagerTerm;
    TermFacade::shouldReceive('find')->with('audio_series::talk')->andReturn(null);
    TermFacade::shouldReceive('make')->andReturn($term);
    $output = new BufferedOutput;

    $created = AudioSeriesManager::createAudioSeriesTerm(audioSeriesManagerConfig(), 'default', $output, '');

    expect($created)->toBeTrue()
        ->and($term->taxonomyHandle())->toBe('audio_series')
        ->and($term->slug())->toBe('talk')
        ->and($term->written['title'] ?? null)->toBe('Talk')
        ->and($term->saves)->toBe(1)
        ->and($output->fetch())->toBe('Created audio series taxonomy term: audio_series::talk'.\PHP_EOL);
});

it('titles a term by the given name with its tags stripped', function (): void {
    $term = new AudioSeriesManagerTerm;
    TermFacade::shouldReceive('find')->andReturn(null);
    TermFacade::shouldReceive('make')->andReturn($term);

    AudioSeriesManager::createAudioSeriesTerm(audioSeriesManagerConfig(), 'default', new BufferedOutput, '<em>Talk</em> Radio');

    expect($term->written['title'] ?? null)->toBe('Talk Radio');
});

/**
 * Assert that an existing term holding these values is updated in full, and says so.
 *
 * @param  array<string, mixed>  $existing  The values the term holds
 */
function audioSeriesManagerExpectUpdate(array $existing): void
{
    $term = new AudioSeriesManagerTerm($existing);
    TermFacade::shouldReceive('find')->andReturn($term);
    $output = new BufferedOutput;

    $updated = AudioSeriesManager::createAudioSeriesTerm(audioSeriesManagerConfig(), 'default', $output);

    expect($updated)->toBeTrue()
        ->and($term->written)->toBe(['title' => 'Talk', 'blueprint' => 'audio_series', 'subtype' => ['podcast']])
        ->and($term->saves)->toBe(1)
        ->and($output->fetch())->toBe('Updated audio series taxonomy term: audio_series::talk with title: Talk'.\PHP_EOL);
}

it('updates an existing term whose title alone is wrong, and says so', function (): void {
    audioSeriesManagerExpectUpdate(['title' => 'Old', 'blueprint' => 'audio_series', 'subtype' => ['podcast']]);
});

it('updates an existing term whose blueprint alone is wrong, and says so', function (): void {
    audioSeriesManagerExpectUpdate(['title' => 'Talk', 'blueprint' => 'other', 'subtype' => ['podcast']]);
});

it('reads a subtype that is not a list as needing an update', function (): void {
    $term = new AudioSeriesManagerTerm(['title' => 'Talk', 'blueprint' => 'audio_series', 'subtype' => 'podcast']);
    TermFacade::shouldReceive('find')->andReturn($term);

    expect(AudioSeriesManager::createAudioSeriesTerm(audioSeriesManagerConfig(), 'default', new BufferedOutput))->toBeTrue();
});

it('compares only the text entries of a subtype list', function (): void {
    // A number among the entries is dropped, and the remaining one is renumbered
    $term = new AudioSeriesManagerTerm(['title' => 'Talk', 'blueprint' => 'audio_series', 'subtype' => [3, 'podcast']]);
    TermFacade::shouldReceive('find')->andReturn($term);
    $output = new BufferedOutput;

    expect(AudioSeriesManager::createAudioSeriesTerm(audioSeriesManagerConfig(), 'default', $output))->toBeFalse()
        ->and($output->fetch())->toBe('Audio series taxonomy term already exists: audio_series::talk'.\PHP_EOL);
});

it('reads a non-text title on an existing term as needing an update', function (): void {
    $term = new AudioSeriesManagerTerm(['title' => ['Talk'], 'blueprint' => 'audio_series', 'subtype' => ['podcast']]);
    TermFacade::shouldReceive('find')->andReturn($term);

    expect(AudioSeriesManager::createAudioSeriesTerm(audioSeriesManagerConfig(), 'default', new BufferedOutput))->toBeTrue();
});

it('reads a non-text title as empty, which matches a series with no name', function (): void {
    $config = new AudioSeriesConfig('audio_series', 'Audio Series', 'talk', '', 'audio_series', 'TALK');
    $term = new AudioSeriesManagerTerm(['title' => ['not text'], 'blueprint' => 'audio_series', 'subtype' => []]);
    TermFacade::shouldReceive('find')->andReturn($term);

    expect(AudioSeriesManager::createAudioSeriesTerm($config, 'default', new BufferedOutput))->toBeFalse();
});

it('writes no description for a series whose description is empty', function (): void {
    $term = new AudioSeriesManagerTerm;
    TermFacade::shouldReceive('find')->andReturn(null);
    TermFacade::shouldReceive('make')->andReturn($term);

    AudioSeriesManager::createAudioSeriesTerm(AudioSeriesConfig::createPodcastSeries('talk', 'Talk', ''), 'default', new BufferedOutput);

    expect($term->written)->not->toHaveKey('description');
});

it('logs and returns nothing for an empty URL', function (): void {
    $warnings = audioSeriesManagerWarnings();
    $importer = new AudioSeriesManagerImporter(new AudioSeriesManagerRecordingHandler);

    expect(audioSeriesManagerImport($importer, new BufferedOutput, ''))->toBeNull()
        ->and($importer->handler->imports)->toBe([])
        ->and($warnings->getArrayCopy())->toBe([[
            'AudioSeriesManager::importAudioSeriesFile: No URL provided for audio series file import',
            ['site' => 'default'],
        ]]);
});

it('links a file on another site directly, and says so', function (): void {
    $importer = new AudioSeriesManagerImporter(new AudioSeriesManagerRecordingHandler);
    $output = new BufferedOutput;

    expect(audioSeriesManagerImport($importer, $output, 'https://other.test/a.mp3'))->toBe('https://other.test/a.mp3')
        ->and($importer->handler->imports)->toBe([])
        ->and($output->fetch())->toBe("Asset not on same site detected. Skipping download and linking directly to 'https://other.test/a.mp3'.".\PHP_EOL);
});

it('imports any URL when the importer has no base blog URL', function (?string $baseBlogUrl): void {
    $importer = new AudioSeriesManagerImporter(new AudioSeriesManagerRecordingHandler, $baseBlogUrl);

    audioSeriesManagerImport($importer, new BufferedOutput, 'https://other.test/a.mp3');

    expect($importer->handler->imports)->toHaveCount(1);
})->with(['none' => [null], 'an empty one' => ['']]);

it('links the original URL when file uploads are off, naming the file', function (): void {
    config(['wordpress-import.upload_files' => false]);
    $importer = new AudioSeriesManagerImporter(new AudioSeriesManagerRecordingHandler);
    $output = new BufferedOutput;

    expect(audioSeriesManagerImport($importer, $output))->toBe('https://x.test/a.mp3')
        ->and($importer->handler->imports)->toBe([])
        ->and($output->fetch())->toBe('File uploads disabled. Using original URL for audio file: a.mp3'.\PHP_EOL);
});

it('imports into the series directory with the series filename, container and metadata', function (): void {
    $importer = new AudioSeriesManagerImporter(new AudioSeriesManagerRecordingHandler);

    expect(audioSeriesManagerImport($importer, new BufferedOutput, segmentTitle: '<i>Part</i> 2'))->toBe('imported/path.mp3')
        ->and($importer->handler->imports)->toBe([[
            'url' => 'https://x.test/a.mp3',
            'path' => 'TALK/2024-01-02_episode-one/talk_2024-01-02_episode-one_part-2_7.mp3',
            'site' => 'TALK',
            'container' => 'podcasts',
            'metadata' => AudioPostAssetMetadata::defaults(),
        ]])
        ->and(\array_keys($importer->getFileCache()))->toBe(['https://x.test/a.mp3|Part 2|7|Episode One:files']);
});

it('imports under the site\'s directory when the series asks for it', function (): void {
    $importer = new AudioSeriesManagerImporter(new AudioSeriesManagerRecordingHandler);

    audioSeriesManagerImport($importer, new BufferedOutput, config: audioSeriesManagerConfig(useSiteDirectory: true));

    expect($importer->handler->imports[0]['path'] ?? null)->toBe('default/TALK/2024-01-02_episode-one/talk_2024-01-02_episode-one_7.mp3')
        ->and($importer->handler->imports[0]['site'] ?? null)->toBe('default');
});

it('caches nothing when the import fails', function (?string $answer): void {
    $importer = new AudioSeriesManagerImporter(new AudioSeriesManagerRecordingHandler($answer));

    expect(audioSeriesManagerImport($importer, new BufferedOutput))->toBe($answer)
        ->and($importer->getFileCache())->toBe([]);
})->with(['null' => [null], 'an empty path' => ['']]);

it('answers from the key the cache is read under, without importing', function (?string $segmentTitle, string $key): void {
    $importer = new AudioSeriesManagerImporter(new AudioSeriesManagerRecordingHandler);
    AudioSeriesManagerImporter::seed($key, 'cached/path.mp3');

    expect(audioSeriesManagerImport($importer, new BufferedOutput, segmentTitle: $segmentTitle))->toBe('cached/path.mp3')
        ->and($importer->handler->imports)->toBe([]);
})->with([
    'no segment' => [null, 'https://x.test/a.mp3|null|7|Episode One'],
    'an empty segment' => ['', 'https://x.test/a.mp3|null|7|Episode One'],
    'a segment, tags stripped' => ['<i>Part</i> 2', 'https://x.test/a.mp3|Part 2|7|Episode One'],
]);

it('answers nothing for a cached value that is not a path', function (): void {
    $importer = new AudioSeriesManagerImporter(new AudioSeriesManagerRecordingHandler);
    AudioSeriesManagerImporter::seed('https://x.test/a.mp3|null|7|Episode One', ['not', 'a', 'path']);

    expect(audioSeriesManagerImport($importer, new BufferedOutput))->toBeNull()
        ->and($importer->handler->imports)->toBe([]);
});

it('lowercases the filename, and leaves out a segment that slugs to nothing', function (): void {
    $config = new AudioSeriesConfig('audio_series', 'Audio Series', 'talk', 'Talk', 'audio_series', 'TALK');

    expect(AudioSeriesManager::generateAudioSeriesFilename($config, '2024-01-02', 'Hello World', 'ID9', '!!!'))
        ->toBe('talk_2024-01-02_hello-world_id9.mp3');
});

it('slugs a title to lowercase words joined by hyphens, with none at either end', function (): void {
    expect(AudioSeriesManager::createSlug('  --Hello, World!--  '))->toBe('hello-world');
});

it('removes only the series category, keeping the other categories and their keys', function (): void {
    expect(AudioSeriesManager::filterOutAudioSeriesCategories(['a' => 'news', 'b' => 'talk', 'c' => 'health'], audioSeriesManagerConfig()))
        ->toBe(['a' => 'news', 'c' => 'health'])
        ->and(AudioSeriesManager::filterOutAudioSeriesCategories(['news', 'talk'], null))->toBe(['news', 'talk']);
});

it('logs an asset it cannot find, and writes nothing', function (): void {
    $warnings = audioSeriesManagerWarnings();
    AssetFacade::shouldReceive('find')->with('audio::episodes/a.mp3')->andReturn(null);

    AudioSeriesManager::setAudioShortcodeAssetMetadata('episodes/a.mp3', 'audio', 'Title', 'talk');

    expect($warnings->getArrayCopy())->toBe([[
        'AudioSeriesManager::setAudioShortcodeAssetMetadata: Asset not found',
        ['path' => 'episodes/a.mp3', 'container' => 'audio'],
    ]]);
});

it('merges the shortcode metadata and the audio defaults into the asset\'s data, and saves it', function (): void {
    $asset = new AudioSeriesManagerAsset(['alt' => 'Kept', 'title' => 'Replaced']);
    AssetFacade::shouldReceive('find')->andReturn($asset);
    $excerpt = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Excerpt']]]];
    $transcript = [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Transcript']]]];

    AudioSeriesManager::setAudioShortcodeAssetMetadata('episodes/a.mp3', 'audio', 'Title', 'talk', $excerpt, $transcript);

    expect($asset->written)->toBe([
        'alt' => 'Kept',
        'title' => 'Title',
        'series' => ['talk'],
        'excerpt' => $excerpt,
        'transcript' => $transcript,
        'creator_organization' => 'UAMS',
        'country_of_origin_query' => true,
        'geographic_location' => ['little-rock'],
        'geographic_location_content' => ['little-rock'],
        'digital_source_type' => ['preset' => 'humanEdits_audio_mastered'],
    ])
        ->and($asset->saves)->toBe(1);
});

it('leaves out a shortcode title, series, excerpt or transcript that is empty', function (?string $title, ?string $series): void {
    $asset = new AudioSeriesManagerAsset(['title' => 'Kept']);
    AssetFacade::shouldReceive('find')->andReturn($asset);

    AudioSeriesManager::setAudioShortcodeAssetMetadata('episodes/a.mp3', 'audio', $title, $series);

    expect($asset->written['title'] ?? null)->toBe('Kept')
        ->and($asset->written)->not->toHaveKeys(['series', 'excerpt', 'transcript']);
})->with(['absent' => [null, null], 'empty' => ['', '']]);
