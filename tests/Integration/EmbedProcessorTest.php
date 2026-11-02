<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Utilities\VideoSetType;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\EmbedProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    // Start every case with no entry context and no collected warnings
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    // Leave no context or warnings behind for the next test file
    ProcessingContext::clearWordPressPageLink();
    ImportWarningManager::clearWarnings();
});

/**
 * The YouTube URL the video cases embed.
 */
const EMBED_YOUTUBE_URL = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

/**
 * Load body markup and return the first comment whose text starts with the prefix.
 *
 * @param  string  $body  The markup inside <body>
 * @param  string  $prefix  The start of the comment to select
 * @return DOMComment The selected comment
 */
function embedComment(string $body, string $prefix = 'wp:'): DOMComment
{
    $doc = new DOMDocument;
    $doc->loadHTML(
        '<body>'.$body.'</body>',
        \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD | \LIBXML_NOERROR | \LIBXML_NOWARNING
    );
    $comments = (new DOMXPath($doc))->query('//comment()[starts-with(normalize-space(.), "'.$prefix.'")]');
    $comment = $comments === false ? null : $comments->item(0);

    if (! $comment instanceof DOMComment) {
        throw new RuntimeException('Test fixture comment failed to parse');
    }

    return $comment;
}

/**
 * A wp:embed comment followed by an embed figure.
 *
 * @param  string  $figureClass  The figure's class attribute
 * @param  string  $inner  The figure's inner markup
 * @return DOMComment The opening comment
 */
function embedFigure(string $figureClass, string $inner = '<div class="wp-block-embed__wrapper">x</div>'): DOMComment
{
    return embedComment('<!-- wp:embed {"type":"video"} --><figure class="'.$figureClass.'">'.$inner.'</figure><!-- /wp:embed -->');
}

/**
 * Run `process()` with the given node and attributes.
 *
 * @param  DOMNode  $node  The node handed to the processor
 * @param  array<int|string, mixed>  $attributes  The block attributes
 * @param  ?string  $pageSlug  The page slug
 * @return array<int|string, mixed>|string|null The processor result
 */
function embedProcess(DOMNode $node, array $attributes, ?string $pageSlug = 'about'): array|string|null
{
    return (new EmbedProcessor)->process($node, $attributes, 'default', new AssetImporter, new BufferedOutput, [], [], $pageSlug);
}

/**
 * Record every warning logged during a test.
 *
 * @return ArrayObject<int, array{message: string, context: array<string, mixed>}> The warnings
 */
function embedWarnings(): ArrayObject
{
    $warnings = new ArrayObject;
    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            $warnings->append(['message' => $message, 'context' => $context]);
        }
    );

    return $warnings;
}

/**
 * The Tiptap markup expected for a YouTube set.
 *
 * @param  string  $align  The expected alignment
 * @param  string  $caption  The expected caption, empty for none
 * @return string The expected markup
 */
function embedYoutubeHtml(string $align = 'aligncenter', string $caption = ''): string
{
    $resolved = VideoSetType::resolve(EMBED_YOUTUBE_URL);

    return '<div data-set-type="youtube"'.SetMarkupNonce::attribute()
        .' data-set-attr-embed_mode="simple"'
        .' data-set-attr-url="'.htmlspecialchars($resolved['url'] ?? '').'"'
        .' data-set-attr-aspect_ratio="16by9"'
        .' data-set-attr-align="'.$align.'"'
        .($caption === '' ? '' : ' data-set-attr-caption="'.htmlspecialchars($caption).'"')
        .'></div>';
}

describe('canProcess', function (): void {
    it('accepts embed comments and rejects everything else', function (string $text, bool $expected): void {
        $doc = new DOMDocument;
        $node = $doc->createComment($text);

        expect((new EmbedProcessor)->canProcess($node, 'default', null))->toBe($expected);
    })->with([
        'bare embed' => ['wp:embed', true],
        'core embed' => ['wp:core-embed', true],
        'embed with attributes' => ['wp:embed {"url":"x"}', true],
        'core embed variation' => ['wp:core-embed/youtube {"url":"x"}', true],
        'padded' => ['  wp:embed  ', true],
        'attributes across lines' => ["wp:embed {\"a\":\n\"b\"}", true],
        'closing comment' => ['/wp:embed', false],
        'longer block name' => ['wp:embedded', false],
        'prefixed' => ['xwp:embed', false],
        'another block' => ['wp:paragraph', false],
        'trailing text' => ['wp:embed {"a":1} x', false],
    ]);

    it('rejects a node that is not a comment', function (): void {
        $doc = new DOMDocument;

        expect((new EmbedProcessor)->canProcess($doc->createElement('p', 'wp:embed'), 'default', null))->toBeFalse()
            ->and((new EmbedProcessor)->canProcess($doc->createTextNode('wp:embed'), 'default', null))->toBeFalse();
    });
});

describe('unsupported embeds', function (): void {
    it('records the warning and links the URL', function (): void {
        $warnings = embedWarnings();
        $attributes = ['type' => 'rich', 'providerNameSlug' => 'twitter', 'url' => 'https://twitter.com/x?a=1&b="2"\'s'];

        $result = embedProcess(embedComment('<!-- wp:embed -->'), $attributes);

        expect($result)->toBe('<p><a href="https://twitter.com/x?a=1&amp;b=&quot;2&quot;&#039;s">https://twitter.com/x?a=1&amp;b="2"\'s</a></p>')
            ->and(ImportWarningManager::getWarnings('content', 'unsupported_embeds'))->toBe([[
                'embed_type' => 'rich',
                'provider_name' => 'twitter',
                'url' => 'https://twitter.com/x?a=1&b="2"\'s',
                'site' => 'default',
                'page_slug' => 'about',
            ]])
            ->and($warnings->getArrayCopy())->toBe([[
                'message' => "EmbedProcessor::process: Unsupported embed type 'rich' with provider 'twitter'",
                'context' => [
                    'site' => 'default',
                    'pageSlug' => 'about',
                    'providerNameSlug' => 'twitter',
                    'url' => 'https://twitter.com/x?a=1&b="2"\'s',
                    'attributes' => $attributes,
                ],
            ]]);
    });

    it('falls back to none, unknown and no link when the attributes are missing', function (): void {
        $warnings = embedWarnings();

        $result = embedProcess(embedComment('<!-- wp:embed -->'), [], null);

        expect($result)->toBeNull()
            ->and(ImportWarningManager::getWarnings('content', 'unsupported_embeds'))->toBe([[
                'embed_type' => 'none',
                'provider_name' => 'unknown',
                'url' => '',
                'site' => 'default',
                'page_slug' => null,
            ]])
            ->and(data_get($warnings, '0.message'))->toBe("EmbedProcessor::process: Unsupported embed type 'none' with provider 'unknown'")
            ->and(data_get($warnings, '0.context.providerNameSlug'))->toBe('');
    });

    it('treats non-scalar attributes as missing and scalars as strings', function (): void {
        embedWarnings();

        $result = embedProcess(embedComment('<!-- wp:embed -->'), ['type' => 5, 'providerNameSlug' => ['x'], 'url' => ['y']]);

        expect($result)->toBeNull()
            ->and(ImportWarningManager::getWarnings('content', 'unsupported_embeds')[0])->toMatchArray([
                'embed_type' => '5',
                'provider_name' => 'unknown',
                'url' => '',
            ]);
    });

    it('names the entry the embed came from', function (): void {
        ProcessingContext::setWordPressPageLink('https://example.com/about/');
        $warnings = embedWarnings();

        embedProcess(embedComment('<!-- wp:embed -->'), ['type' => 'rich']);

        expect(data_get($warnings, '0.message'))->toBe("EmbedProcessor::process: Unsupported embed type 'rich' with provider 'unknown' (from entry: https://example.com/about/)")
            ->and(data_get($warnings, '0.context.entry_url'))->toBe('https://example.com/about/');
    });

    it('does not treat a typeless embed as video when its URL is not a string or not a video', function (array $attributes): void {
        embedWarnings();

        embedProcess(embedFigure('wp-block-embed is-provider-youtube'), $attributes);

        expect(ImportWarningManager::getWarnings('content', 'unsupported_embeds'))->toHaveCount(1);
    })->with([
        'array url' => [['url' => [EMBED_YOUTUBE_URL]]],
        'non-video url' => [['url' => 'https://example.com/page']],
        'non-video type with a video url' => [['type' => 'rich', 'url' => EMBED_YOUTUBE_URL]],
    ]);
});

describe('video embeds', function (): void {
    it('builds the platform set, skipping the comment, figure and closing comment', function (array $attributes): void {
        $node = embedFigure('wp-block-embed is-provider-youtube');

        $result = embedProcess($node, $attributes);

        expect($result)->toBeArray()
            ->and(data_get($result, 'html'))->toBe(embedYoutubeHtml())
            ->and(data_get($result, 'processedNode'))->toBe($node->nextSibling)
            ->and(data_get($result, 'skipCount'))->toBe(3)
            ->and(ImportWarningManager::getWarnings('content', 'unsupported_embeds'))->toBe([]);
    })->with([
        'typed' => [['type' => 'video', 'url' => EMBED_YOUTUBE_URL]],
        'typeless' => [['url' => EMBED_YOUTUBE_URL]],
        'empty type' => [['type' => '', 'url' => EMBED_YOUTUBE_URL]],
    ]);

    it('prefers the align attribute and falls back to the figure class', function (array $attributes, string $expected): void {
        $result = embedProcess(embedFigure('wp-block-embed alignwide'), $attributes + ['type' => 'video', 'url' => EMBED_YOUTUBE_URL]);

        expect($result['html'] ?? null)->toBe(embedYoutubeHtml($expected));
    })->with([
        'attribute' => [['align' => 'center'], 'aligncenter'],
        'empty attribute' => [['align' => ''], 'alignwide'],
        'non-scalar attribute' => [['align' => ['center']], 'alignwide'],
        'no attribute' => [[], 'alignwide'],
        'left, which a video set centers' => [['align' => 'left'], 'aligncenter'],
        'right, which a video set centers' => [['align' => 'right'], 'aligncenter'],
    ]);

    it('keeps the first trimmed figcaption', function (): void {
        $node = embedFigure(
            'wp-block-embed',
            '<div class="wp-block-embed__wrapper">x</div><p>not a caption</p><figcaption>  First &amp; "best"  </figcaption><figcaption>Second</figcaption>'
        );

        expect(embedProcess($node, ['type' => 'video', 'url' => EMBED_YOUTUBE_URL])['html'] ?? null)
            ->toBe(embedYoutubeHtml('aligncenter', 'First & "best"'));
    });

    it('drops an empty figcaption', function (): void {
        $node = embedFigure('wp-block-embed', '<div class="wp-block-embed__wrapper">x</div><figcaption>   </figcaption>');

        expect(embedProcess($node, ['type' => 'video', 'url' => EMBED_YOUTUBE_URL])['html'] ?? null)
            ->toBe(embedYoutubeHtml());
    });

    it('returns null for a comment that belongs to no document', function (): void {
        expect(embedProcess(new DOMComment('wp:embed'), ['type' => 'video', 'url' => EMBED_YOUTUBE_URL]))->toBeNull();
    });

    it('warns when no embed figure follows the comment', function (string $body): void {
        $warnings = embedWarnings();
        $attributes = ['type' => 'video', 'url' => EMBED_YOUTUBE_URL];

        expect(embedProcess(embedComment($body), $attributes))->toBeNull()
            ->and($warnings->getArrayCopy())->toBe([[
                'message' => 'EmbedProcessor::processVideoEmbed: No valid wp:embed figure found',
                'context' => ['site' => 'default', 'pageSlug' => 'about', 'attributes' => $attributes],
            ]]);
    })->with([
        'nothing follows' => ['<!-- wp:embed {"type":"video"} -->'],
        'a figure without the class' => ['<!-- wp:embed {"type":"video"} --><figure class="wp-block-image"><div class="wp-block-embed__wrapper">x</div></figure>'],
        'the figure precedes the comment' => ['<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">x</div></figure><!-- wp:embed {"type":"video"} -->'],
    ]);

    it('warns with a snippet when the wrapper div is missing or misnamed', function (string $inner, string $snippet): void {
        $warnings = embedWarnings();

        expect(embedProcess(embedFigure('wp-block-embed', $inner), ['type' => 'video', 'url' => EMBED_YOUTUBE_URL]))->toBeNull()
            ->and($warnings->getArrayCopy())->toBe([[
                'message' => 'EmbedProcessor::processVideoEmbed: No valid wp-block-embed__wrapper div found in wp:embed block',
                'context' => ['site' => 'default', 'pageSlug' => 'about', 'node_html' => $snippet],
            ]]);
    })->with([
        'no div' => ['<p>x</p>', '<figure class="wp-block-embed"><p>x</p></figure>...'],
        'misnamed div' => ['<div class="xwp-block-embed__wrapper">x</div>', '<figure class="wp-block-embed"><div class="xwp-block-embed__wrapper">x</div></figure>...'],
        'long markup' => [
            '<div class="other">'.str_repeat('y', 120).'</div>',
            substr('<figure class="wp-block-embed"><div class="other">'.str_repeat('y', 120), 0, 100).'...',
        ],
    ]);

    it('warns with a snippet when the video URL is missing', function (array $attributes): void {
        $warnings = embedWarnings();

        expect(embedProcess(embedFigure('wp-block-embed'), $attributes))->toBeNull()
            ->and($warnings->getArrayCopy())->toBe([[
                'message' => 'EmbedProcessor::processVideoEmbed: No video URL found in wp:embed block',
                'context' => [
                    'site' => 'default',
                    'pageSlug' => 'about',
                    'node_html' => '<figure class="wp-block-embed"><div class="wp-block-embed__wrapper">x</div></figure>...',
                ],
            ]]);
    })->with([
        'absent' => [['type' => 'video']],
        'empty' => [['type' => 'video', 'url' => '']],
        'non-scalar' => [['type' => 'video', 'url' => ['x']]],
    ]);

    it('warns when the URL routes to no platform set', function (string $figureClass, string $provider): void {
        $warnings = embedWarnings();

        expect(embedProcess(embedFigure($figureClass), ['type' => 'video', 'url' => 'https://example.com/clip.mp4']))->toBeNull()
            ->and($warnings->getArrayCopy())->toBe([[
                'message' => 'EmbedProcessor::processVideoEmbed: Unsupported provider in wp:embed block',
                'context' => [
                    'site' => 'default',
                    'pageSlug' => 'about',
                    'provider' => $provider,
                    'video_url' => 'https://example.com/clip.mp4',
                ],
            ]]);
    })->with([
        'named provider' => ['wp-block-embed is-provider-TikTok', 'tiktok'],
        'no provider' => ['wp-block-embed', 'none'],
    ]);
});
