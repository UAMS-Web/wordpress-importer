<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\ContentParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\EmbedProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that imported video content emits the platform-specific Bard sets
 * (issue wordpress-importer-archive#298).
 *
 * `UAMS-Web/uams-statamic-archive#589` replaced the `video` Bard set with `youtube` /
 * `vimeo` / `box`, each with a `url` handle (not `video_url`) and an `embed_mode`
 * toggle. The importer kept emitting `type: video` with `video_url`, which no
 * longer matches any set declared in `uamsweb_common.yaml`, so imported video
 * landed on a set type the Bard field does not define.
 *
 * Two independent paths emit video sets and both had to change:
 *   - `EmbedProcessor` — Gutenberg `wp:embed` / `wp:core-embed` blocks (1,966
 *     `is-provider-youtube` and 242 `is-provider-vimeo` occurrences in the corpus)
 *   - `ContentParser::convertIframesToVideoEmbeds()` — raw iframes in WYSIWYG HTML
 *
 * `embed_mode` is always `simple`: a WordPress embed carries a URL and an optional
 * caption, never the transcript / creators / transparency metadata the `full`
 * segment mode exists to record.
 *
 * @command  php vendor/bin/pest tests/Integration/VideoBardSetEmissionTest.php
 */
uses(StatamicTestCase::class);

afterEach(function (): void {
    Mockery::close();
});

/**
 * Run a Gutenberg video embed through `EmbedProcessor` and return the Tiptap HTML.
 *
 * @param  string  $provider  The `is-provider-<name>` slug on the figure
 * @param  string  $url  The embedded video URL
 * @param  string  $caption  An optional figcaption
 */
function embedProcessorVideoHtml(
    string $provider,
    string $url,
    string $caption = ''
): string {
    $figcaption = $caption === '' ? '' : "<figcaption>{$caption}</figcaption>";

    // `processVideoEmbed()` is handed the wp:embed COMMENT node and finds the figure
    // as a following sibling, so the fixture has to reproduce that pairing
    $doc = new DOMDocument;
    // Load the fragment as-is. An XML-declaration prefix written without its closing
    // delimiter is not a well-formed processing instruction, and libxml materializes it
    // as a document-level comment — which then wins `//comment()` and hands the
    // processor a node whose only following sibling is <html>. The markup here is
    // ASCII, so the prefix bought nothing; the flag pair matches QuoteProcessorTest and
    // ShortcodeBlockProcessorTest.
    $doc->loadHTML(
        '<body>'
            .'<!-- wp:embed {"type":"video"} -->'
            ."<figure class=\"wp-block-embed is-provider-{$provider}\">"
            ."<div class=\"wp-block-embed__wrapper\">{$url}</div>{$figcaption}"
            .'</figure>'
            .'<!-- /wp:embed -->'
            .'</body>',
        \LIBXML_HTML_NOIMPLIED | \LIBXML_HTML_NODEFDTD | \LIBXML_NOERROR | \LIBXML_NOWARNING
    );

    // Select the OPENING wp:embed comment by content, so document position is not
    // load-bearing if the fixture ever grows another comment. The closing `/wp:embed`
    // normalizes to `/wp:embed` and is correctly excluded.
    $comments = (new DOMXPath($doc))->query(
        '//comment()[starts-with(normalize-space(.), "wp:embed")]'
    );

    $comment = $comments === false ? null : $comments->item(0);

    if (! $comment instanceof DOMComment) {
        throw new RuntimeException('Test fixture comment failed to parse');
    }

    $result = (new EmbedProcessor)->process(
        $comment,
        ['type' => 'video', 'url' => $url],
        'site',
        new AssetImporter,
        new BufferedOutput
    );

    if (\is_array($result)) {
        $html = $result['html'] ?? '';

        return \is_string($html) ? $html : '';
    }

    return \is_string($result) ? $result : '';
}

/**
 * Run an iframe through `ContentParser::convertIframesToVideoEmbeds()`.
 *
 * @param  string  $iframeSrc  The iframe `src`
 */
function contentParserVideoHtml(string $iframeSrc): string
{
    $method = new ReflectionMethod(ContentParser::class, 'convertIframesToVideoEmbeds');
    $method->setAccessible(true);

    $result = $method->invoke(
        new ContentParser,
        '<div class="embed-responsive aligncenter">'
            ."<iframe src=\"{$iframeSrc}\" title=\"Video: A talk\"></iframe>"
            .'</div>'
    );

    return \is_string($result) ? $result : '';
}

it('emits a platform set type from a Gutenberg embed, never the retired video set', function (
    string $provider,
    string $url,
    string $expectedSet
): void {
    $html = embedProcessorVideoHtml($provider, $url);

    expect($html)->toContain("data-set-type=\"{$expectedSet}\"");
    expect($html)->not->toContain('data-set-type="video"');
})->with([
    'youtube' => ['youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'youtube'],
    'vimeo' => ['vimeo', 'https://vimeo.com/123456789', 'vimeo'],
])->group('video-sets');

it('emits the url handle and drops video_url from a Gutenberg embed', function (): void {
    // #589's sets declare `url`; `video_url` is the retired set's handle and would
    // land on a field none of the three defines.
    $html = embedProcessorVideoHtml('youtube', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');

    expect($html)->toContain('data-set-attr-url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"');
    expect($html)->not->toContain('data-set-attr-video_url');
})->group('video-sets');

it('writes embed_mode simple on a Gutenberg embed', function (): void {
    // A WordPress embed carries no segment metadata, so `full` is never justified.
    $html = embedProcessorVideoHtml('vimeo', 'https://vimeo.com/123456789');

    expect($html)->toContain('data-set-attr-embed_mode="simple"');
})->group('video-sets');

it('keeps the caption, aspect ratio, and alignment on a Gutenberg embed', function (): void {
    $html = embedProcessorVideoHtml(
        'youtube',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'A campus talk'
    );

    expect($html)->toContain('data-set-attr-caption="A campus talk"');
    expect($html)->toContain('data-set-attr-aspect_ratio="16by9"');
    expect($html)->toContain('data-set-attr-align=');
})->group('video-sets');

it('emits nothing for a Gutenberg embed whose provider routes nowhere', function (): void {
    // The corpus carries junk provider slugs; an unroutable one has no set to land on
    $html = embedProcessorVideoHtml('wistia', 'https://uams.wistia.com/medias/abc123');

    expect($html)->not->toContain('data-set-type="youtube"');
    expect($html)->not->toContain('data-set-type="vimeo"');
    expect($html)->not->toContain('data-set-type="box"');
    expect($html)->not->toContain('data-set-type="video"');
})->group('video-sets');

it('emits a platform set type from a raw iframe, never the retired video set', function (
    string $iframeSrc,
    string $expectedSet
): void {
    $html = contentParserVideoHtml($iframeSrc);

    expect($html)->toContain("data-set-type=\"{$expectedSet}\"");
    expect($html)->not->toContain('data-set-type="video"');
})->with([
    'youtube embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ', 'youtube'],
    'vimeo player' => ['https://player.vimeo.com/video/123456789', 'vimeo'],
])->group('video-sets');

it('emits the url handle and embed_mode from a raw iframe', function (): void {
    $html = contentParserVideoHtml('https://www.youtube.com/embed/dQw4w9WgXcQ');

    expect($html)->toContain('data-set-attr-url="https://www.youtube.com/watch?v=dQw4w9WgXcQ"');
    expect($html)->toContain('data-set-attr-embed_mode="simple"');
    expect($html)->not->toContain('data-set-attr-video_url');
})->group('video-sets');

it('leaves an iframe alone when its URL routes to no platform set', function (): void {
    // Better an untouched iframe than a set type the Bard field cannot render
    $html = contentParserVideoHtml('https://www.dropbox.com/s/abc/clip.mp4');

    expect($html)->not->toContain('data-set-type=');
    expect($html)->toContain('<iframe');
})->group('video-sets');

it('never embeds a hostile host that merely mentions a platform', function (string $iframeSrc): void {
    // The WXR is attacker-influenced. `extractVideoDataFromIframeSrc()` used to match
    // `youtube.com/watch?v=` ANYWHERE in the src, so a tracker URL carrying that text
    // in its query reported `provider: youtube` while keeping the attacker's host —
    // and a provider-first router would have emitted it inside a trusted `youtube`
    // embed. The patterns are host-anchored and the URL is authoritative. wordpress-importer-archive#298
    $html = contentParserVideoHtml($iframeSrc);

    expect($html)->not->toContain('data-set-type=');
    expect($html)->not->toContain('evil.example"');
    expect($html)->toContain('<iframe');
})->with([
    'platform name in a query string' => ['https://tracker.evil.example/px?ref=youtube.com/watch?v=abc'],
    'platform name in a path' => ['https://evil.example/youtube.com/embed/abc'],
    'userinfo before the real host' => ['https://youtube.com@evil.example/embed/abc'],
    'lookalike domain' => ['https://youtube.com.evil.example/embed/abc'],
    'vimeo id in a path' => ['https://evil.example/vimeo.com/12345'],
])->group('video-sets');

it('drops an iframe whose host is right but whose URL is unsafe', function (): void {
    // The case that makes `ContentParser`'s unroutable guard load-bearing rather
    // than dead code: `extractVideoDataFromIframeSrc()` matches the host and returns
    // the FULL src, and `VideoSetType` then refuses it for the unsafe character.
    // Without the guard that src would be emitted into `data-set-attr-url`.
    //
    // A space rather than a quote, because a raw `"` inside `src="…"` is truncated
    // by HTML parsing before the importer ever sees it — the quote-bearing vector
    // arrives through the Gutenberg `wp:embed` JSON instead, which is covered in
    // `tests/Unit/VideoSetTypeRoutingTest.php`.
    $html = contentParserVideoHtml('https://www.youtube.com/watch?v=abc onload=alert(1)');

    expect($html)->not->toContain('data-set-type=');
    expect($html)->not->toContain('data-set-attr-url');
})->group('video-sets');

it('still routes the legitimate iframe shapes after anchoring', function (
    string $iframeSrc,
    string $expectedSet,
    string $expectedUrl
): void {
    // Host-anchoring must not cost any real embed: 1,966 YouTube and 242 Vimeo
    // embeds in the corpus depend on these shapes.
    $html = contentParserVideoHtml($iframeSrc);

    expect($html)->toContain("data-set-type=\"{$expectedSet}\"");
    expect($html)->toContain("data-set-attr-url=\"{$expectedUrl}\"");
})->with([
    'youtube embed' => [
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
        'youtube',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
    'youtube embed without www' => [
        'https://youtube.com/embed/dQw4w9WgXcQ',
        'youtube',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
    'youtube nocookie embed' => [
        'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        'youtube',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
    'vimeo player' => [
        'https://player.vimeo.com/video/123456789',
        'vimeo',
        'https://vimeo.com/123456789',
    ],
    'protocol-relative youtube embed' => [
        '//www.youtube.com/embed/dQw4w9WgXcQ',
        'youtube',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
])->group('video-sets');
