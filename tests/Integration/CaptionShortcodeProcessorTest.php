<?php

declare(strict_types=1);

use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\CaptionShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearWordPressPageLink();
});

describe('a [caption] that holds no image', function (): void {
    it('keeps its text in the content', function (string $content, string $expected): void {
        $processed = (new ShortcodeProcessor)->processShortcodesInContent(
            $content,
            'site-a',
            new AssetImporter,
            new BufferedOutput,
            [],
            [],
            [],
            'a-page'
        );

        expect($processed)->toBe($expected);
    })->with([
        'bare' => ['<p>A [caption]plain text[/caption] B</p>', '<p>A plain text B</p>'],
        'attributed' => ['<p>A [caption id="c1" align="alignright"]plain text[/caption] B</p>', '<p>A plain text B</p>'],
        'with markup' => ['<p>A [caption]<em>plain</em> text[/caption] B</p>', '<p>A <em>plain</em> text B</p>'],
    ]);

    it('records a warning naming the site, the page and the text', function (): void {
        ProcessingContext::setWordPressPageLink('https://example.com/a-page/');

        (new ShortcodeProcessor)->processShortcodesInContent(
            '<p>A [caption id="c1"]plain text[/caption] B</p>',
            'site-a',
            new AssetImporter,
            new BufferedOutput,
            [],
            [],
            [],
            'a-page'
        );

        expect(ImportWarningManager::getWarnings('content', 'imageless_captions'))->toBe([
            [
                'site' => 'site-a',
                'page_slug' => 'a-page',
                'page_link' => 'https://example.com/a-page/',
                'content_snippet' => 'plain text',
                'reason' => 'The [caption] holds no image, so its text was kept in the content as written.',
            ],
        ]);
    });

    it('records empty page fields when no page is known', function (): void {
        (new CaptionShortcodeProcessor)->toTiptapHtml(
            ['name' => 'caption', 'attributes' => [], 'content' => 'plain text'],
            'site-a',
            new AssetImporter,
            new BufferedOutput
        );

        /** @var array<int, array<string, string>> $warnings */
        $warnings = ImportWarningManager::getWarnings('content', 'imageless_captions');

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0]['page_slug'])->toBe('')
            ->and($warnings[0]['page_link'])->toBe('');
    });

    it('shortens a snippet longer than 100 characters to its first 100', function (): void {
        $text = str_repeat('é', 100);

        (new CaptionShortcodeProcessor)->toTiptapHtml(
            ['name' => 'caption', 'attributes' => [], 'content' => $text.'x'],
            'site-a',
            new AssetImporter,
            new BufferedOutput
        );
        (new CaptionShortcodeProcessor)->toTiptapHtml(
            ['name' => 'caption', 'attributes' => [], 'content' => $text],
            'site-a',
            new AssetImporter,
            new BufferedOutput
        );

        /** @var array<int, array<string, string>> $warnings */
        $warnings = ImportWarningManager::getWarnings('content', 'imageless_captions');

        expect($warnings[0]['content_snippet'])->toBe($text.'...')
            ->and($warnings[1]['content_snippet'])->toBe($text);
    });
});

describe('a [caption] with nothing to keep', function (): void {
    it('is removed without a warning when it is empty', function (): void {
        $processed = (new ShortcodeProcessor)->processShortcodesInContent(
            '<p>A [caption][/caption] B</p>',
            'site-a',
            new AssetImporter,
            new BufferedOutput
        );

        expect($processed)->toBe('<p>A  B</p>')
            ->and(ImportWarningManager::getWarnings('content', 'imageless_captions'))->toBe([]);
    });

    it('is removed without a warning when its image cannot be imported', function (): void {
        /** @var ImageProcessor&MockInterface $imageProcessor */
        $imageProcessor = Mockery::mock(ImageProcessor::class);
        $imageProcessor->allows(['processImage' => null]);

        $html = (new CaptionShortcodeProcessor($imageProcessor))->toTiptapHtml(
            ['name' => 'caption', 'attributes' => [], 'content' => '<img src="https://example.com/a.jpg"> A caption'],
            'site-a',
            new AssetImporter,
            new BufferedOutput
        );

        expect($html)->toBe('')
            ->and(ImportWarningManager::getWarnings('content', 'imageless_captions'))->toBe([]);
    });
});

describe('a [caption] with an image', function (): void {
    it('converts to an image set with its caption, and records no warning', function (): void {
        /** @var ImageProcessor&MockInterface $imageProcessor */
        $imageProcessor = Mockery::mock(ImageProcessor::class);
        $imageProcessor->allows(['processImage' => [
            'type' => 'set',
            'attrs' => ['values' => ['type' => 'image', 'image' => 'assets::a.jpg']],
        ]]);

        $html = (new CaptionShortcodeProcessor($imageProcessor))->toTiptapHtml(
            ['name' => 'caption', 'attributes' => [], 'content' => '<img src="https://example.com/a.jpg"> A caption'],
            'site-a',
            new AssetImporter,
            new BufferedOutput
        );

        expect($html)->toBe(ShortcodeProcessor::generateTiptapHtml(
            ['type' => 'image', 'image' => 'assets::a.jpg', 'caption' => 'A caption'],
            'image',
            ''
        ))
            ->and(ImportWarningManager::getWarnings('content', 'imageless_captions'))->toBe([]);
    });
});
