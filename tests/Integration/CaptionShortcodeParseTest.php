<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\CaptionShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for what CaptionShortcodeProcessor hands to ImageProcessor and does with its set.
 *
 * Each case replaces ImageProcessor, so it can read the arguments the shortcode produced
 * and choose the set that comes back. The imageless case and the Tiptap output of an
 * image are `CaptionShortcodeProcessorTest`.
 *
 * @command  php vendor/bin/pest tests/Integration/CaptionShortcodeParseTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
    ProcessingContext::clearShortcodeContent();
    ProcessingContext::clearAttachments();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearShortcodeContent();
    ProcessingContext::clearAttachments();
});

/**
 * Build a processor over an ImageProcessor that records its calls and returns one set.
 *
 * @param  ?array<int|string, mixed>  $set  The set ImageProcessor returns
 * @param  ArrayObject<int, list<mixed>>  $calls  Receives each call's arguments
 * @return CaptionShortcodeProcessor The processor
 */
function captionProcessor(?array $set, ArrayObject $calls = new ArrayObject): CaptionShortcodeProcessor
{
    $imageProcessor = new class($set, $calls) extends ImageProcessor
    {
        /**
         * Keep the set to return and the list that receives each call.
         *
         * @param  ?array<int|string, mixed>  $set  The set to return
         * @param  ArrayObject<int, list<mixed>>  $calls  Receives each call's arguments
         */
        public function __construct(private readonly ?array $set, private readonly ArrayObject $calls)
        {
            parent::__construct();
        }

        /**
         * Record the call and return the set.
         *
         * @param  string  $html  The image HTML
         * @param  string  $site  The site handle
         * @param  AssetImporter  $assetImporter  The asset importer
         * @param  OutputInterface  $output  Console output
         * @param  array<int|string, mixed>  $attachments  The attachments
         * @param  ?string  $pageSlug  The page slug
         * @param  ?array<int|string, mixed>  $blockAttributes  The block attributes
         * @return ?array<int|string, mixed> The set
         */
        public function processImage(
            string $html,
            string $site,
            AssetImporter $assetImporter,
            OutputInterface $output,
            array $attachments = [],
            ?string $pageSlug = null,
            ?array $blockAttributes = null
        ): ?array {
            $this->calls->append([$html, $site, $assetImporter, $output, $attachments, $pageSlug, $blockAttributes]);

            return $this->set;
        }
    };

    return new CaptionShortcodeProcessor($imageProcessor);
}

/**
 * Build an AssetImporter with a known base URL.
 *
 * @return AssetImporter The importer
 */
function captionImporter(): AssetImporter
{
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    $importer->allows(['getBaseBlogUrl' => 'https://example.com']);

    return $importer;
}

/**
 * Parse caption content and return the arguments ImageProcessor received.
 *
 * @param  string  $content  The shortcode content
 * @param  array<int|string, mixed>  $attributes  The shortcode attributes
 * @param  ?string  $pageSlug  The page slug
 * @return list<mixed> The arguments
 */
function captionImageArguments(string $content, array $attributes = [], ?string $pageSlug = null): array
{
    /** @var ArrayObject<int, list<mixed>> $calls */
    $calls = new ArrayObject;

    ProcessingContext::setShortcodeContent($content);
    captionProcessor(['type' => 'image'], $calls)->parse($attributes, 'site-a', captionImporter(), new BufferedOutput, [], $pageSlug);

    return $calls->getArrayCopy()[0] ?? throw new RuntimeException('Expected a call to ImageProcessor.');
}

/**
 * Collect the warnings logged through the facade.
 *
 * @return ArrayObject<int, array{message: string, context: array<int|string, mixed>}> The warnings
 */
function captionWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{message: string, context: array<int|string, mixed>}> $warnings */
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use ($warnings): void {
        /** @var array<int|string, mixed> $context */
        $warnings->append(['message' => $message, 'context' => $context]);
    });

    return $warnings;
}

describe('content it cannot use', function (): void {
    it('warns and returns nothing without content', function (?string $content): void {
        $warnings = captionWarnings();
        ProcessingContext::setShortcodeContent($content);

        $processor = captionProcessor(['type' => 'image']);

        expect($processor->parse([], 'site-a', captionImporter(), new BufferedOutput, [], 'a-page'))->toBeNull();
        expect($processor->parse([], 'site-a', captionImporter(), new BufferedOutput))->toBeNull();

        $logged = $warnings->getArrayCopy();

        expect($logged)->toHaveCount(2);
        expect($logged[0]['message'])->toBe('CaptionShortcodeProcessor::parse: No content provided for [caption] shortcode');
        expect($logged[0]['context'])->toBe(['site' => 'site-a', 'pageSlug' => 'a-page']);
        expect($logged[1]['context'])->toBe(['site' => 'site-a', 'pageSlug' => 'none']);
    })->with([
        'null' => [null],
        'an empty string' => [''],
    ]);

    it('warns with a snippet and returns nothing without an image', function (string $content, string $snippet): void {
        $warnings = captionWarnings();
        ProcessingContext::setShortcodeContent($content);

        $processor = captionProcessor(['type' => 'image']);

        expect($processor->parse([], 'site-a', captionImporter(), new BufferedOutput, [], 'a-page'))->toBeNull();
        expect($processor->parse([], 'site-a', captionImporter(), new BufferedOutput))->toBeNull();

        $logged = $warnings->getArrayCopy();

        expect($logged)->toHaveCount(2);
        expect($logged[0]['message'])->toBe('CaptionShortcodeProcessor::parse: No <img> tag found in [caption] content');
        expect($logged[0]['context'])->toBe(['site' => 'site-a', 'pageSlug' => 'a-page', 'content_snippet' => $snippet]);
        expect($logged[1]['context']['pageSlug'] ?? null)->toBe('none');
    })->with([
        'short text' => ['plain text', 'plain text'],
        'exactly 100 characters' => [str_repeat('a', 100), str_repeat('a', 100)],
        'longer text' => [str_repeat('a', 101), str_repeat('a', 100).'...'],
    ]);

    it('warns and returns nothing when the image cannot be processed', function (?array $set): void {
        $warnings = captionWarnings();
        ProcessingContext::setShortcodeContent('<img src="https://example.com/a.jpg">');

        $processor = captionProcessor($set);

        expect($processor->parse([], 'site-a', captionImporter(), new BufferedOutput, [], 'a-page'))->toBeNull();
        expect($processor->parse([], 'site-a', captionImporter(), new BufferedOutput))->toBeNull();

        $logged = $warnings->getArrayCopy();

        expect($logged)->toHaveCount(2);
        expect($logged[0]['message'])->toBe('CaptionShortcodeProcessor::parse: Failed to process image for [caption] shortcode');
        expect($logged[0]['context'])->toBe([
            'site' => 'site-a',
            'pageSlug' => 'a-page',
            'img_html' => '<img src="https://example.com/a.jpg">',
        ]);
        expect($logged[1]['context']['pageSlug'] ?? null)->toBe('none');
    })->with([
        'no set' => [null],
        'an empty set' => [[]],
    ]);
});

describe('what ImageProcessor receives', function (): void {
    it('receives the image alone', function (string $content): void {
        expect(captionImageArguments($content)[0])->toBe('<img src="https://example.com/a.jpg">');
    })->with([
        'bare, with text' => ['<img src="https://example.com/a.jpg"> Text'],
        'linked, with text' => ['<a href="https://example.com/"><img src="https://example.com/a.jpg"></a> Text'],
        'the first of two' => ['<img src="https://example.com/a.jpg"><img src="https://example.com/b.jpg">'],
    ]);

    it('receives the site, the importer and the page', function (): void {
        $arguments = captionImageArguments('<img src="https://example.com/a.jpg">', pageSlug: 'a-page');

        expect($arguments[1])->toBe('site-a');
        expect($arguments[2])->toBeInstanceOf(AssetImporter::class);
        expect($arguments[3])->toBeInstanceOf(BufferedOutput::class);
        expect($arguments[5])->toBe('a-page');
    });

    it('receives the attachments in the context, or none', function (): void {
        expect(captionImageArguments('<img src="https://example.com/a.jpg">')[4])->toBe([]);

        ProcessingContext::setShortcodeContext(['attachment'], '<img src="https://example.com/a.jpg">');

        expect(captionImageArguments('<img src="https://example.com/a.jpg">')[4])->toBe(['attachment']);
    });

    it('normalizes the alignment', function (array $attributes, string $align): void {
        $blockAttributes = captionImageArguments('<img src="https://example.com/a.jpg">', $attributes)[6];

        expect($blockAttributes)->toBe(['align' => $align]);
    })->with([
        'none' => [[], 'center'],
        'with the prefix' => [['align' => 'alignright'], 'right'],
        'without the prefix' => [['align' => 'left'], 'left'],
        'the prefix twice' => [['align' => 'alignalignleft'], 'alignleft'],
        'the word later on' => [['align' => 'realign'], 'realign'],
        'a number' => [['align' => 5], '5'],
        'not scalar' => [['align' => ['right']], ''],
    ]);

    it('passes an attachment id for an image on the same site', function (string $source, mixed $id, ?string $expected): void {
        $blockAttributes = captionImageArguments("<img src=\"$source\">", ['id' => $id])[6];

        expect($blockAttributes)->toBe($expected === null
            ? ['align' => 'center']
            : ['align' => 'center', 'id' => $expected]);
    })->with([
        'on the same site' => ['https://example.com/a.jpg', 'attachment_380', '380'],
        'on another site' => ['https://elsewhere.test/a.jpg', 'attachment_380', null],
        'another id shape' => ['https://example.com/a.jpg', 'image_380', null],
        'a number' => ['https://example.com/a.jpg', 380, null],
        'not scalar' => ['https://example.com/a.jpg', ['attachment_380'], null],
    ]);

    it('reads no id when the attribute is missing', function (): void {
        expect(captionImageArguments('<img src="https://example.com/a.jpg">')[6])->not->toHaveKey('id');
    });
});

describe('the caption', function (): void {
    it('takes the text after the image, without paragraph tags', function (string $content, string $caption): void {
        ProcessingContext::setShortcodeContent($content);

        $set = captionProcessor(['attrs' => ['values' => ['type' => 'image']]])
            ->parse([], 'site-a', captionImporter(), new BufferedOutput);

        expect($set)->toBe(['attrs' => ['values' => ['type' => 'image', 'caption' => $caption]]]);
    })->with([
        'plain text' => ['<img src="https://example.com/a.jpg"> A caption', 'A caption'],
        'a linked image' => ['<a href="https://example.com/"><img src="https://example.com/a.jpg"></a> A caption', 'A caption'],
        'a paragraph' => ['<p><img src="https://example.com/a.jpg"></p><p class="x">A caption</p>', 'A caption'],
        'other markup' => ['<img src="https://example.com/a.jpg"> A <em>caption</em>', 'A <em>caption</em>'],
    ]);

    it('leaves the set alone without text', function (string $content): void {
        ProcessingContext::setShortcodeContent($content);

        $set = ['attrs' => ['values' => ['type' => 'image']]];

        expect(captionProcessor($set)->parse([], 'site-a', captionImporter(), new BufferedOutput))->toBe($set);
    })->with([
        'no text' => ['<img src="https://example.com/a.jpg">'],
        'blank text' => ['<img src="https://example.com/a.jpg">   '],
    ]);

    it('keeps a caption the image already carries', function (): void {
        ProcessingContext::setShortcodeContent('<img src="https://example.com/a.jpg"> A caption');

        $set = ['attrs' => ['values' => ['caption' => 'Its own']]];

        expect(captionProcessor($set)->parse([], 'site-a', captionImporter(), new BufferedOutput))->toBe($set);
    });

    it('fills an empty caption', function (): void {
        ProcessingContext::setShortcodeContent('<img src="https://example.com/a.jpg"> A caption');

        expect(captionProcessor(['attrs' => ['values' => ['caption' => '']]])->parse([], 'site-a', captionImporter(), new BufferedOutput))
            ->toBe(['attrs' => ['values' => ['caption' => 'A caption']]]);
    });

    it('adds it to whichever shape the set has', function (array $set, array $expected): void {
        ProcessingContext::setShortcodeContent('<img src="https://example.com/a.jpg"> A caption');

        expect(captionProcessor($set)->parse([], 'site-a', captionImporter(), new BufferedOutput))->toBe($expected);
    })->with([
        'a flat set' => [['type' => 'image'], ['type' => 'image', 'caption' => 'A caption']],
        'attrs that are not a list' => [['attrs' => 'x'], ['attrs' => 'x', 'caption' => 'A caption']],
        'attrs with no values' => [
            ['attrs' => ['id' => 'a']],
            ['attrs' => ['id' => 'a', 'values' => ['attrs' => ['id' => 'a'], 'caption' => 'A caption']]],
        ],
        'values that are not a list' => [
            ['attrs' => ['values' => 'x']],
            ['attrs' => ['values' => ['attrs' => ['values' => 'x'], 'caption' => 'A caption']]],
        ],
    ]);
});

describe('Tiptap output', function (): void {
    it('reads the content as text where it can', function (mixed $content, string $html): void {
        $output = captionProcessor(null)->toTiptapHtml(
            ['name' => 'caption', 'attributes' => [], 'content' => $content],
            'site-a',
            captionImporter(),
            new BufferedOutput
        );

        expect($output)->toBe($html);
    })->with([
        'text' => ['plain text', 'plain text'],
        'a number' => [5, '5'],
        'true' => [true, '1'],
        'no content' => [null, ''],
        'a list' => [['plain text'], ''],
    ]);

    it('reads shortcode attributes only from a list', function (mixed $attributes, string $align): void {
        /** @var ArrayObject<int, list<mixed>> $calls */
        $calls = new ArrayObject;

        captionProcessor(['type' => 'image'], $calls)->toTiptapHtml(
            ['name' => 'caption', 'attributes' => $attributes, 'content' => '<img src="https://example.com/a.jpg">'],
            'site-a',
            captionImporter(),
            new BufferedOutput
        );

        expect($calls->getArrayCopy()[0][6] ?? null)->toBe(['align' => $align]);
    })->with([
        'a list' => [['align' => 'left'], 'left'],
        'text' => ['align', 'center'],
    ]);

    it('hands the page and the attachments on', function (): void {
        /** @var ArrayObject<int, list<mixed>> $calls */
        $calls = new ArrayObject;

        captionProcessor(['type' => 'image'], $calls)->toTiptapHtml(
            ['name' => 'caption', 'attributes' => [], 'content' => '<img src="https://example.com/a.jpg">'],
            'site-a',
            captionImporter(),
            new BufferedOutput,
            ['attachment'],
            [],
            'a-page'
        );

        $arguments = $calls->getArrayCopy()[0] ?? [];

        expect($arguments[4] ?? null)->toBe(['attachment']);
        expect($arguments[5] ?? null)->toBe('a-page');
    });

    it('returns nothing, and reports nothing, for an image that fails', function (): void {
        Log::shouldReceive('warning');

        $output = captionProcessor(null)->toTiptapHtml(
            ['name' => 'caption', 'attributes' => [], 'content' => '<img src="https://example.com/a.jpg">'],
            'site-a',
            captionImporter(),
            new BufferedOutput
        );

        expect($output)->toBe('');
        expect(ImportWarningManager::getWarnings('content', 'imageless_captions'))->toBe([]);
    });
});
