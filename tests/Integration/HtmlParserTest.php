<?php

declare(strict_types=1);

/**
 * Tests that the image pipeline's `HtmlParser` step reads the first `<img>` in
 * a block's HTML (its source, alt text and WordPress attachment id) and the
 * `<figure>` around it, and logs when there is no image to read (#1314).
 *
 * Routed to the light Statamic band for the `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/HtmlParserTest.php
 */

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\HtmlParser;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

/**
 * Run the parser over a context.
 *
 * @param  array<string, mixed>  $context  The image context
 * @param  ?string  $pageSlug  The page slug, for the log
 * @return array<string, mixed> The context after parsing
 */
function htmlParserProcess(array $context, ?string $pageSlug = null): array
{
    $assetImporter = new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor();

    /** @var array<string, mixed> $result */
    $result = (new HtmlParser)->process($context, 'default', $assetImporter, new NullOutput, [], $pageSlug);

    return $result;
}

it('reads the first image, its trimmed alt text and its attachment id', function (): void {
    $result = htmlParserProcess([
        'html' => '<p><img class="size-large wp-image-42" src="https://example.org/a.jpg" alt="  A clinic  "><img src="https://example.org/b.jpg"></p>',
        'keep' => 'me',
    ]);

    $img = $result['img'] ?? null;

    expect($img)->toBeInstanceOf(DOMElement::class)
        ->and($img instanceof DOMElement ? $img->getAttribute('src') : null)->toBe('https://example.org/a.jpg')
        ->and($result['src'] ?? null)->toBe('https://example.org/a.jpg')
        ->and($result['imgAlt'] ?? null)->toBe('A clinic')
        ->and($result)->toHaveKey('figure')
        ->and($result['figure'])->toBeNull()
        ->and($result['blockAttributes'] ?? null)->toBe(['id' => '42'])
        ->and($result['keep'] ?? null)->toBe('me');
});

it('adds the attachment id to the block attributes already there', function (mixed $blockAttributes, array $expected): void {
    $result = htmlParserProcess([
        'html' => '<img class="wp-image-7" src="https://example.org/a.jpg">',
        'blockAttributes' => $blockAttributes,
    ]);

    expect($result['blockAttributes'] ?? null)->toBe($expected);
})->with([
    'an array' => [['align' => 'left', 'id' => 3], ['align' => 'left', 'id' => '7']],
    'not an array' => ['left', ['id' => '7']],
]);

it('leaves the block attributes alone when the image names no attachment', function (?string $class): void {
    $html = $class === null
        ? '<img src="https://example.org/a.jpg">'
        : '<img class="'.$class.'" src="https://example.org/a.jpg">';

    $result = htmlParserProcess(['html' => $html, 'blockAttributes' => ['align' => 'left']]);

    expect($result['blockAttributes'] ?? null)->toBe(['align' => 'left'])
        ->and($result['src'] ?? null)->toBe('https://example.org/a.jpg');
})->with([
    'no class' => [null],
    'a class with no attachment id' => ['size-large'],
]);

it('finds the figure around the image, however deep', function (string $html): void {
    $figure = htmlParserProcess(['html' => $html])['figure'] ?? null;

    expect($figure)->toBeInstanceOf(DOMElement::class)
        ->and($figure instanceof DOMElement ? $figure->getAttribute('class') : null)->toBe('wp-block-image');
})->with([
    'a direct parent' => ['<figure class="wp-block-image"><img src="https://example.org/a.jpg"></figure>'],
    'inside a link' => ['<figure class="wp-block-image"><a href="/x"><span><img src="https://example.org/a.jpg"></span></a></figure>'],
]);

it('stops at the nearest figure', function (): void {
    $figure = htmlParserProcess([
        'html' => '<figure class="outer"><figure class="inner"><img src="https://example.org/a.jpg"></figure></figure>',
    ])['figure'] ?? null;

    expect($figure instanceof DOMElement ? $figure->getAttribute('class') : null)->toBe('inner');
});

it('finds no figure for an image outside one', function (): void {
    $result = htmlParserProcess(['html' => '<div><a href="/x"><img src="https://example.org/a.jpg"></a></div>']);

    expect($result)->toHaveKey('figure')
        ->and($result['figure'])->toBeNull();
});

it('leaves the context alone when there is no HTML to read', function (mixed $html): void {
    $logSpy = Log::spy();
    $context = ['html' => $html, 'keep' => 'me'];

    expect(htmlParserProcess($context))->toBe($context);

    $logSpy->shouldNotHaveReceived('warning');
})->with([
    'not a string' => [['<img src="a">']],
    'an empty string' => [''],
    'only whitespace' => ["  \n "],
]);

it('leaves the context alone when the context has no HTML', function (): void {
    $logSpy = Log::spy();

    expect(htmlParserProcess(['keep' => 'me']))->toBe(['keep' => 'me']);

    $logSpy->shouldNotHaveReceived('warning');
});

it('logs and leaves the context alone when the HTML has no image', function (string $html, ?string $pageSlug, string $snippet, string $expectedSlug): void {
    $logSpy = Log::spy();
    $context = ['html' => $html];

    expect(htmlParserProcess($context, $pageSlug))->toBe($context);

    $logSpy->shouldHaveReceived('warning', [
        'HtmlParser::process: No <img> tag found in HTML',
        ['site' => 'default', 'pageSlug' => $expectedSlug, 'html_snippet' => $snippet],
    ]);
})->with([
    'short HTML, no slug' => ['<p>Text</p>', null, '<p>Text</p>', 'none'],
    'HTML of exactly 100 characters' => [\str_repeat('a', 100), 'about', \str_repeat('a', 100), 'about'],
    'long HTML, cut at 100' => [\str_repeat('b', 101), 'about', \str_repeat('b', 100).'...', 'about'],
]);

it('logs and leaves the context alone when the image has no source', function (string $html): void {
    $logSpy = Log::spy();
    $context = ['html' => $html];

    expect(htmlParserProcess($context, 'about'))->toBe($context);

    $logSpy->shouldHaveReceived('warning', [
        'HtmlParser::process: <img> tag has no src attribute',
        ['site' => 'default', 'pageSlug' => 'about', 'html_snippet' => $html],
    ]);
})->with([
    'no src' => ['<img class="wp-image-9" alt="x">'],
    'an empty src' => ['<img src="" alt="x">'],
]);

it('cuts the snippet in the no-source warning at 100 characters', function (int $altLength, string $expectedSnippet): void {
    $logSpy = Log::spy();

    // The tag around the alt text is 19 characters, so an 81-character alt makes 100
    $html = '<img src="" alt="'.\str_repeat('c', $altLength).'">';

    htmlParserProcess(['html' => $html], 'about');

    $logSpy->shouldHaveReceived('warning', [
        'HtmlParser::process: <img> tag has no src attribute',
        ['site' => 'default', 'pageSlug' => 'about', 'html_snippet' => $expectedSnippet],
    ]);
})->with([
    'exactly 100 characters, kept whole' => [81, '<img src="" alt="'.\str_repeat('c', 81).'">'],
    '101 characters, cut at 100' => [82, '<img src="" alt="'.\str_repeat('c', 82).'"...'],
]);

it('names the page in the log message when the page link is known', function (): void {
    $logSpy = Log::spy();
    ProcessingContext::setWordPressPageLink('https://example.org/clinic/');

    try {
        htmlParserProcess(['html' => '<p>Text</p>']);
    } finally {
        ProcessingContext::clearWordPressPageLink();
    }

    $logSpy->shouldHaveReceived('warning', [
        'HtmlParser::process: No <img> tag found in HTML (from entry: https://example.org/clinic/)',
        Mockery::on(static fn (mixed $context): bool => \is_array($context) && ($context['entry_url'] ?? null) === 'https://example.org/clinic/'),
    ]);
});
