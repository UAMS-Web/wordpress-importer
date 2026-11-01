<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\AudioSeries\AudioSeriesConfig;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\AccordionShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\AudioShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\PostAudioProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for PostAudioProcessor, which turns an audio-format post's content into `segments` and `intro`.
 *
 * Multi-segment content is split at each `<h3>`, ending at `[/accordion]` when the
 * content has accordions and at the next `<h3>` when it does not. Each segment's
 * `[audio]` shortcode is imported with the segment's title, intro and transcript
 * placed in ProcessingContext for the asset's metadata. Content with an `[audio]`
 * shortcode and no `<h3>` is one segment titled after the post. The text around the
 * segments becomes the intro. Most tests replace the audio and accordion processors
 * with mocks, so what is handed to them can be asserted; one runs the real ones.
 *
 * @command  php vendor/bin/pest tests/Integration/PostAudioProcessorTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
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
function postAudioExpects(LegacyMockInterface|MockInterface $mock, string $method): Expectation
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
 * An asset importer for TextProcessor, which reads only the blog URL.
 */
function postAudioImporter(): AssetImporter
{
    $importer = (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(AssetImporter::class, 'baseBlogUrl'))->setValue($importer, 'https://example.test');

    return $importer;
}

/**
 * A processor whose audio and accordion processors are the given ones.
 *
 * @param  ?AudioShortcodeProcessor  $audio  The audio processor, or null for the real one
 * @param  ?AccordionShortcodeProcessor  $accordion  The accordion processor, or null for the real one
 * @param  ?ShortcodeProcessor  $shortcodes  The shortcode extractor, or null for the real one
 * @return PostAudioProcessor The processor
 */
function postAudioProcessor(
    ?AudioShortcodeProcessor $audio = null,
    ?AccordionShortcodeProcessor $accordion = null,
    ?ShortcodeProcessor $shortcodes = null
): PostAudioProcessor {
    $processor = new PostAudioProcessor;

    foreach (['audioProcessor' => $audio, 'accordionProcessor' => $accordion, 'shortcodeProcessor' => $shortcodes] as $property => $value) {
        if ($value !== null) {
            (new ReflectionProperty(PostAudioProcessor::class, $property))->setValue($processor, $value);
        }
    }

    return $processor;
}

/**
 * An audio processor that records the ProcessingContext it was called in and imports `mp3` to `audio/<basename>`.
 *
 * @param  ArrayObject<int, array<string, mixed>>  $calls  Filled with one record per call
 * @return AudioShortcodeProcessor The mocked processor
 */
function postAudioRecordingProcessor(ArrayObject $calls): AudioShortcodeProcessor
{
    /** @var AudioShortcodeProcessor&MockInterface $audio */
    $audio = Mockery::mock(AudioShortcodeProcessor::class);
    postAudioExpects($audio, 'parse')->andReturnUsing(
        function (array $attributes, string $site, AssetImporter $importer, BufferedOutput $output, array $terms, ?string $pageSlug) use ($calls): array {
            $calls->append([
                'attributes' => $attributes,
                'site' => $site,
                'terms' => $terms,
                'pageSlug' => $pageSlug,
                'series_config' => ProcessingContext::getAudioSeriesConfig(),
                'post_date' => ProcessingContext::getWordPressPostDate(),
                'post_id' => ProcessingContext::getWordPressPostId(),
                'post_title' => ProcessingContext::getWordPressPostTitle(),
                'segment_title' => ProcessingContext::getSegmentTitle(),
                'asset_title' => ProcessingContext::getAudioAssetTitle(),
                'asset_series' => ProcessingContext::getAudioAssetSeries(),
                'asset_excerpt' => ProcessingContext::getAudioAssetExcerpt(),
                'asset_transcript' => ProcessingContext::getAudioAssetTranscript(),
            ]);

            $mp3 = $attributes['mp3'] ?? '';

            return ['file' => \is_string($mp3) && $mp3 !== '' ? 'audio/'.basename($mp3) : null];
        }
    );

    return $audio;
}

/**
 * An accordion processor that finds a transcript `T<n>` wherever the segment names `TRANSCRIPT-<n>`.
 *
 * @param  ArrayObject<int, string>  $segments  Filled with every segment it is given
 * @return AccordionShortcodeProcessor The mocked processor
 */
function postAudioAccordion(ArrayObject $segments): AccordionShortcodeProcessor
{
    /** @var AccordionShortcodeProcessor&MockInterface $accordion */
    $accordion = Mockery::mock(AccordionShortcodeProcessor::class);
    postAudioExpects($accordion, 'parseFromSegment')->andReturnUsing(
        function (string $segment) use ($segments): array {
            $segments->append($segment);

            if (preg_match('/TRANSCRIPT-(\d+)/', $segment, $matches) !== 1) {
                return [];
            }

            return [['title' => 'Transcript', 'content' => ['first' => postAudioParagraph('T'.$matches[1])]]];
        }
    );

    return $accordion;
}

/**
 * A Bard paragraph node as TextProcessor writes it.
 *
 * @param  string  $text  The paragraph text
 * @return array<string, mixed> The node
 */
function postAudioParagraph(string $text): array
{
    return ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]], 'attrs' => []];
}

/**
 * The segment rows without their generated ids, after checking each has one.
 *
 * @param  mixed  $segments  The `segments` value
 * @return list<array<array-key, mixed>> The rows without `id`
 */
function postAudioRows(mixed $segments): array
{
    expect($segments)->toBeArray();

    $rows = [];

    foreach ((array) $segments as $segment) {
        expect($segment)->toBeArray();
        $segment = is_array($segment) ? $segment : [];

        expect($segment['id'] ?? null)->toBeString()->not->toBe('')
            ->and(array_key_first($segment))->toBe('id');

        unset($segment['id']);
        $rows[] = $segment;
    }

    return $rows;
}

/**
 * A segment row for the given file, without its id.
 *
 * @param  ?string  $file  The imported file path
 * @return array<string, mixed> The row
 */
function postAudioRow(?string $file): array
{
    return ['file' => $file, 'preload' => 'metadata', 'loop' => false, 'type' => 'local_file', 'enabled' => true];
}

/**
 * Run processAudioFormatContent() with the given processor.
 *
 * @param  PostAudioProcessor  $processor  The processor
 * @param  string  $content  The post content
 * @param  ?string  $postTitle  The post title
 * @param  ?AudioSeriesConfig  $series  The audio series configuration
 * @return array<int|string, mixed> The result
 */
function postAudioRun(PostAudioProcessor $processor, string $content, ?string $postTitle = 'The Post', ?AudioSeriesConfig $series = null): array
{
    return $processor->processAudioFormatContent(
        $content,
        'default',
        postAudioImporter(),
        new BufferedOutput,
        'episode',
        $postTitle,
        $series,
        '2024-05-01 10:00:00',
        '321'
    );
}

/**
 * An audio series configuration whose series value is `wellness`.
 */
function postAudioSeries(): AudioSeriesConfig
{
    return new AudioSeriesConfig('audio_series', 'Audio Series', 'wellness', 'Wellness', 'audio_series', 'wellness');
}

it('splits accordion segments at each h3, imports each one, and builds the intro around them', function (): void {
    /** @var ArrayObject<int, array<string, mixed>> $calls */
    $calls = new ArrayObject;
    /** @var ArrayObject<int, string> $segments */
    $segments = new ArrayObject;
    $series = postAudioSeries();

    $content = "<p>Welcome<img src=\"a.jpg\" /></p>\n<h2 class=\"x\">Episodes</h2>&nbsp;\n\n"
        .'<h3 class="seg">Part <em>One</em></h3>[audio mp3="https://example.test/one.mp3"][/audio]'
        .'<a href="#">Download This Episode</a> First <IMG SRC="b.jpg">intro [accordion][section title="Transcript"]TRANSCRIPT-1[/section][/accordion]'
        .'<h3>Part Two</h3><p>[audio mp3="https://example.test/two.mp3"][/audio]</p>[accordion][section title="Transcript"]TRANSCRIPT-2[/section][/accordion]'
        ."\n<p>Outro</p>\n";

    $result = postAudioRun(postAudioProcessor(postAudioRecordingProcessor($calls), postAudioAccordion($segments)), $content, 'The Post', $series);

    expect(postAudioRows($result['segments'] ?? null))->toBe([postAudioRow('audio/one.mp3'), postAudioRow('audio/two.mp3')])
        ->and($result['intro'] ?? null)->toBe([postAudioParagraph('Welcome'), postAudioParagraph('Outro')])
        ->and(array_keys($result))->toBe(['segments', 'intro'])
        ->and($segments->getArrayCopy())->toBe([
            '<h3 class="seg">Part <em>One</em></h3>[audio mp3="https://example.test/one.mp3"][/audio]<a href="#">Download This Episode</a> First intro [accordion][section title="Transcript"]TRANSCRIPT-1[/section][/accordion]',
            '<h3>Part Two</h3><p>[audio mp3="https://example.test/two.mp3"][/audio]</p>[accordion][section title="Transcript"]TRANSCRIPT-2[/section][/accordion]',
        ])
        ->and($calls->getArrayCopy())->toBe([
            [
                'attributes' => ['mp3' => 'https://example.test/one.mp3'],
                'site' => 'default',
                'terms' => [],
                'pageSlug' => 'episode',
                'series_config' => $series,
                'post_date' => '2024-05-01 10:00:00',
                'post_id' => '321',
                'post_title' => 'The Post',
                'segment_title' => 'Part One',
                'asset_title' => 'Part One',
                'asset_series' => 'wellness',
                'asset_excerpt' => [postAudioParagraph('First intro')],
                'asset_transcript' => [postAudioParagraph('T1')],
            ],
            [
                'attributes' => ['mp3' => 'https://example.test/two.mp3'],
                'site' => 'default',
                'terms' => [],
                'pageSlug' => 'episode',
                'series_config' => $series,
                'post_date' => '2024-05-01 10:00:00',
                'post_id' => '321',
                'post_title' => 'The Post',
                'segment_title' => 'Part Two',
                'asset_title' => 'Part Two',
                'asset_series' => 'wellness',
                'asset_excerpt' => null,
                'asset_transcript' => [postAudioParagraph('T2')],
            ],
        ])
        ->and(ProcessingContext::getAudioSeriesConfig())->toBeNull()
        ->and(ProcessingContext::getWordPressPostDate())->toBeNull()
        ->and(ProcessingContext::getWordPressPostId())->toBeNull()
        ->and(ProcessingContext::getWordPressPostTitle())->toBeNull()
        ->and(ProcessingContext::getSegmentTitle())->toBeNull()
        ->and(ProcessingContext::getAudioAssetTitle())->toBeNull()
        ->and(ProcessingContext::getAudioAssetSeries())->toBeNull()
        ->and(ProcessingContext::getAudioAssetExcerpt())->toBeNull()
        ->and(ProcessingContext::getAudioAssetTranscript())->toBeNull();
});

it('splits segments between h3 tags when there are no accordions', function (): void {
    /** @var ArrayObject<int, array<string, mixed>> $calls */
    $calls = new ArrayObject;
    /** @var ArrayObject<int, string> $segments */
    $segments = new ArrayObject;

    $content = '<p>Lead</p><h3>One</h3>[audio mp3="https://example.test/1.mp3"][/audio]<a href="#">Download this episode</a>'
        .'<h3>Two</h3>[audio mp3="https://example.test/2.mp3"][/audio]<a href="#">download this episode</a><p>Tail</p>';

    $result = postAudioRun(postAudioProcessor(postAudioRecordingProcessor($calls), postAudioAccordion($segments)), $content);

    expect(postAudioRows($result['segments'] ?? null))->toBe([postAudioRow('audio/1.mp3'), postAudioRow('audio/2.mp3')])
        ->and($result['intro'] ?? null)->toBe([postAudioParagraph('Lead'), postAudioParagraph('Tail')])
        ->and($segments->getArrayCopy())->toBe([
            '<h3>One</h3>[audio mp3="https://example.test/1.mp3"][/audio]<a href="#">Download this episode</a>',
            '<h3>Two</h3>[audio mp3="https://example.test/2.mp3"][/audio]<a href="#">download this episode</a><p>Tail</p>',
        ])
        ->and(array_column($calls->getArrayCopy(), 'asset_excerpt'))->toBe([null, null]);
});

it('keeps the text before the first h3 as the whole intro when nothing follows the segments', function (string $content, array $intro): void {
    $result = postAudioRun(postAudioProcessor(postAudioRecordingProcessor(new ArrayObject), postAudioAccordion(new ArrayObject)), $content);

    expect($result['intro'] ?? null)->toBe($intro);
})->with([
    'no download link' => ['<p>Lead</p><h3>One</h3>[audio mp3="https://example.test/1.mp3"][/audio]<p>more</p>', [postAudioParagraph('Lead')]],
    'nothing before the first h3' => ['<h3>One</h3>[audio mp3="https://example.test/1.mp3"][/audio][accordion][/accordion]', []],
    'an h2 alone before the first h3' => ["<h2>Episodes</h2>\n&nbsp;\n<h3>One</h3>[audio][/audio][accordion][/accordion]  ", []],
    'an h2 that does not end the lead' => ['<h2>Title</h2><p>Lead</p><h3>One</h3>[audio][/audio][accordion][/accordion]', [
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => 'Title']]],
        postAudioParagraph('Lead'),
    ]],
]);

it('drops a segment with no title, and warns', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/episode/');
    $warnings = [];
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$warnings): void {
        $warnings[] = [$message, $context];
    });

    /** @var AudioShortcodeProcessor&MockInterface $audio */
    $audio = Mockery::mock(AudioShortcodeProcessor::class);
    postAudioExpects($audio, 'parse')->once()->andReturn(['file' => 'audio/2.mp3']);

    $result = postAudioRun(
        postAudioProcessor($audio, postAudioAccordion(new ArrayObject)),
        '<h3> <br> </h3>[audio mp3="https://example.test/1.mp3"][/audio][accordion][/accordion]<h3>Two</h3>[audio mp3="https://example.test/2.mp3"][/audio][accordion][/accordion]'
    );

    expect(postAudioRows($result['segments'] ?? null))->toBe([postAudioRow('audio/2.mp3')])
        ->and($warnings)->toBe([[
            'PostAudioProcessor::processAudioSegment: No title found in segment (from entry: https://example.test/episode/)',
            ['site' => 'default', 'page_slug' => 'episode', 'entry_url' => 'https://example.test/episode/'],
        ]]);
});

it('names the page slug as none in the warning when there is no slug', function (): void {
    $warnings = [];
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$warnings): void {
        $warnings[] = [$message, $context];
    });

    $result = postAudioProcessor(postAudioRecordingProcessor(new ArrayObject), postAudioAccordion(new ArrayObject))
        ->processAudioFormatContent('<h3></h3>[audio][/audio][accordion][/accordion]', 'default', postAudioImporter(), new BufferedOutput);

    expect($result)->toBe(['segments' => [], 'intro' => []])
        ->and($warnings)->toBe([[
            'PostAudioProcessor::processAudioSegment: No title found in segment',
            ['site' => 'default', 'page_slug' => 'none'],
        ]]);
});

it('treats content with neither an h3 nor an audio shortcode as all intro', function (string $content, array $intro): void {
    /** @var AudioShortcodeProcessor&MockInterface $audio */
    $audio = Mockery::mock(AudioShortcodeProcessor::class);
    $audio->shouldNotReceive('parse');

    expect(postAudioRun(postAudioProcessor($audio, postAudioAccordion(new ArrayObject)), $content))
        ->toBe(['segments' => [], 'intro' => $intro]);
})->with([
    'text' => ['Just text<img src="x.jpg">', [postAudioParagraph('Just text')]],
    'whitespace' => ["  \n ", []],
    'an image only' => ['<img src="x.jpg" />', []],
]);

it('imports a single segment titled after the post and builds the intro from the text around it', function (): void {
    /** @var ArrayObject<int, array<string, mixed>> $calls */
    $calls = new ArrayObject;
    /** @var ArrayObject<int, string> $segments */
    $segments = new ArrayObject;
    $series = postAudioSeries();

    $content = '<p>Before<img src="a.jpg"></p>[audio mp3="https://example.test/solo.mp3"][/audio]'
        .'<a href="#">Download this episode</a> Segment intro [accordion][section title="Transcript"]TRANSCRIPT-9[/section][/accordion]'
        .'<p>After</p>';

    $result = postAudioRun(postAudioProcessor(postAudioRecordingProcessor($calls), postAudioAccordion($segments)), $content, 'Episode <b>9</b>', $series);

    expect(postAudioRows($result['segments'] ?? null))->toBe([postAudioRow('audio/solo.mp3')])
        ->and($result['intro'] ?? null)->toBe([postAudioParagraph('Before'), postAudioParagraph('Segment intro'), postAudioParagraph('After')])
        ->and($segments->getArrayCopy())->toBe([
            '[audio mp3="https://example.test/solo.mp3"][/audio]<a href="#">Download this episode</a> Segment intro [accordion][section title="Transcript"]TRANSCRIPT-9[/section][/accordion]',
        ])
        ->and($calls->getArrayCopy())->toBe([[
            'attributes' => ['mp3' => 'https://example.test/solo.mp3'],
            'site' => 'default',
            'terms' => [],
            'pageSlug' => 'episode',
            'series_config' => $series,
            'post_date' => '2024-05-01 10:00:00',
            'post_id' => '321',
            'post_title' => 'Episode <b>9</b>',
            'segment_title' => null,
            'asset_title' => 'Episode 9',
            'asset_series' => 'wellness',
            'asset_excerpt' => null,
            'asset_transcript' => [postAudioParagraph('T9')],
        ]]);
});

it('builds a single segment intro from whichever parts are present', function (string $content, array $intro): void {
    $result = postAudioRun(postAudioProcessor(postAudioRecordingProcessor(new ArrayObject), postAudioAccordion(new ArrayObject)), $content);

    expect(postAudioRows($result['segments'] ?? null))->toBe([postAudioRow('audio/s.mp3')])
        ->and($result['intro'] ?? null)->toBe($intro);
})->with([
    'nothing around the segment' => ['[audio mp3="https://example.test/s.mp3"][/audio]', []],
    'only text after the accordion' => ['[audio mp3="https://example.test/s.mp3"][/audio][accordion][/accordion]<p>After</p>', [postAudioParagraph('After')]],
    'only text before the audio' => ['<p>Before</p>[audio mp3="https://example.test/s.mp3"][/audio][accordion][/accordion]', [postAudioParagraph('Before')]],
    'text after a download link with no accordion' => ['[audio mp3="https://example.test/s.mp3"][/audio]<a>DOWNLOAD THIS EPISODE</a> The rest ', [postAudioParagraph('The rest')]],
    'a download link with nothing before the accordion' => ['[audio mp3="https://example.test/s.mp3"][/audio]<a>Download this episode</a>[accordion][/accordion]', []],
    'text after the accordion that makes no Bard' => ['<p>Before</p>[audio mp3="https://example.test/s.mp3"][/audio][accordion][/accordion]<!-- note -->', [postAudioParagraph('Before')]],
    'only text after the accordion that makes no Bard' => ['[audio mp3="https://example.test/s.mp3"][/audio][accordion][/accordion]<!-- note -->', []],
    'two accordions' => ['[audio mp3="https://example.test/s.mp3"][/audio][accordion][/accordion]<p>Mid</p>[accordion][/accordion]<p>End</p>', [postAudioParagraph('End')]],
]);

it('titles a single segment with nothing when the post has no title', function (?string $postTitle): void {
    /** @var ArrayObject<int, array<string, mixed>> $calls */
    $calls = new ArrayObject;

    postAudioRun(postAudioProcessor(postAudioRecordingProcessor($calls), postAudioAccordion(new ArrayObject)), '[audio mp3="https://example.test/s.mp3"][/audio]', $postTitle);

    expect($calls[0]['asset_title'] ?? 'missing')->toBe('')
        ->and($calls[0] ?? null)->toHaveKey('post_title')
        ->and(data_get($calls, '0.post_title'))->toBe($postTitle);
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('imports only the first usable audio shortcode in a segment', function (): void {
    /** @var ShortcodeProcessor&MockInterface $shortcodes */
    $shortcodes = Mockery::mock(ShortcodeProcessor::class);
    postAudioExpects($shortcodes, 'extract')->andReturn([
        'not a shortcode',
        ['name' => 'video', 'attributes' => ['mp3' => 'https://example.test/video.mp3']],
        ['attributes' => ['mp3' => 'https://example.test/nameless.mp3']],
        ['name' => 'audio', 'attributes' => 'not a list'],
        ['name' => 'audio'],
        ['name' => 'audio', 'attributes' => ['mp3' => 'https://example.test/first.mp3']],
        ['name' => 'audio', 'attributes' => ['mp3' => 'https://example.test/second.mp3']],
    ]);

    /** @var ArrayObject<int, array<string, mixed>> $calls */
    $calls = new ArrayObject;

    $result = postAudioRun(
        postAudioProcessor(postAudioRecordingProcessor($calls), postAudioAccordion(new ArrayObject), $shortcodes),
        '<h3>One</h3>[audio][/audio][accordion][/accordion]'
    );

    expect(postAudioRows($result['segments'] ?? null))->toBe([postAudioRow('audio/first.mp3')])
        ->and(array_column($calls->getArrayCopy(), 'attributes'))->toBe([['mp3' => 'https://example.test/first.mp3']]);
});

it('leaves the file empty when the segment has no audio shortcode or the import gives no path', function (string $content, mixed $file): void {
    /** @var AudioShortcodeProcessor&MockInterface $audio */
    $audio = Mockery::mock(AudioShortcodeProcessor::class);
    postAudioExpects($audio, 'parse')->andReturn(['file' => $file]);

    $result = postAudioRun(postAudioProcessor($audio, postAudioAccordion(new ArrayObject)), $content);

    expect(postAudioRows($result['segments'] ?? null))->toBe([postAudioRow(null)]);
})->with([
    'no audio shortcode' => ['<h3>One</h3><p>no audio</p>[accordion][/accordion]', 'audio/unused.mp3'],
    'a path that is not text' => ['<h3>One</h3>[audio mp3="x"][/audio][accordion][/accordion]', 42],
    'no path' => ['<h3>One</h3>[audio mp3="x"][/audio][accordion][/accordion]', null],
]);

it('takes the first Transcript section with list content as the transcript', function (array $sections, ?array $transcript): void {
    /** @var AccordionShortcodeProcessor&MockInterface $accordion */
    $accordion = Mockery::mock(AccordionShortcodeProcessor::class);
    postAudioExpects($accordion, 'parseFromSegment')->andReturn($sections);

    /** @var ArrayObject<int, array<string, mixed>> $calls */
    $calls = new ArrayObject;

    postAudioRun(postAudioProcessor(postAudioRecordingProcessor($calls), $accordion), '<h3>One</h3>[audio mp3="x"][/audio][accordion][/accordion]');

    expect($calls[0] ?? null)->toHaveKey('asset_transcript')
        ->and(data_get($calls, '0.asset_transcript'))->toBe($transcript);
})->with([
    'sections that are not arrays, then a transcript' => [['type' => 'accordion', 'x', ['title' => 'Transcript', 'content' => [postAudioParagraph('T')]]], [postAudioParagraph('T')]],
    'another section first' => [[['title' => 'Notes', 'content' => [postAudioParagraph('N')]], ['title' => 'Transcript', 'content' => [postAudioParagraph('T')]]], [postAudioParagraph('T')]],
    'two transcripts' => [[['title' => 'Transcript', 'content' => [postAudioParagraph('A')]], ['title' => 'Transcript', 'content' => [postAudioParagraph('B')]]], [postAudioParagraph('A')]],
    'a transcript whose content is not a list' => [[['title' => 'Transcript', 'content' => 'text'], ['title' => 'Transcript', 'content' => [postAudioParagraph('B')]]], null],
    'a transcript with no content' => [[['title' => 'Transcript']], null],
    'a transcript with a node that is not an array' => [[['title' => 'Transcript', 'content' => [postAudioParagraph('A'), 'text']]], null],
    'a lowercase title' => [[['title' => 'transcript', 'content' => [postAudioParagraph('A')]]], null],
    'no sections' => [[], null],
]);

it('runs the real audio and accordion processors when none are given', function (): void {
    $result = postAudioRun(
        new PostAudioProcessor,
        '<h3>One</h3>[audio][/audio]<a>Download this episode</a> Hello [accordion][section title="Transcript"]Said things[/section][/accordion]'
    );

    expect(postAudioRows($result['segments'] ?? null))->toBe([postAudioRow(null)])
        ->and($result['intro'] ?? null)->toBe([]);
});

it('drops a segment whose h3 is never closed', function (): void {
    $warnings = [];
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$warnings): void {
        $warnings[] = [$message, $context];
    });

    $result = postAudioRun(
        postAudioProcessor(postAudioRecordingProcessor(new ArrayObject), postAudioAccordion(new ArrayObject)),
        '<h3>Broken [audio mp3="https://example.test/1.mp3"][/audio]<h3>Two</h3>[audio mp3="https://example.test/2.mp3"][/audio]'
    );

    expect(postAudioRows($result['segments'] ?? null))->toBe([postAudioRow('audio/2.mp3')])
        ->and(array_column($warnings, 0))->toBe(['PostAudioProcessor::processAudioSegment: No title found in segment']);
});

it('gives a segment no excerpt when nothing sits between the download link and the accordion', function (string $content): void {
    /** @var ArrayObject<int, array<string, mixed>> $calls */
    $calls = new ArrayObject;

    postAudioRun(postAudioProcessor(postAudioRecordingProcessor($calls), postAudioAccordion(new ArrayObject)), $content);

    expect($calls[0] ?? null)->toHaveKey('asset_excerpt')
        ->and(data_get($calls, '0.asset_excerpt', 'missing'))->toBeNull();
})->with([
    'whitespace only' => ['<h3>One</h3>[audio mp3="x"][/audio]<a>Download this episode</a> <img src="i.jpg"> [accordion][/accordion]'],
    'no download link' => ['<h3>One</h3>[audio mp3="x"][/audio] Some text [accordion][/accordion]'],
]);

it('returns nothing when the only audio shortcode sits inside an image tag the second image pass removes', function (): void {
    /** @var AudioShortcodeProcessor&MockInterface $audio */
    $audio = Mockery::mock(AudioShortcodeProcessor::class);
    $audio->shouldNotReceive('parse');

    expect(postAudioRun(postAudioProcessor($audio, postAudioAccordion(new ArrayObject)), '<p>Lead</p><im<img src="x">g [audio mp3="y"]>'))
        ->toBe(['segments' => [], 'intro' => []]);
});
