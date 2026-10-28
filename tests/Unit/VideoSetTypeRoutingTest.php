<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Utilities\VideoSetType;

/**
 * Tests the video URL → Bard set routing (issue wordpress-importer-archive#298).
 *
 * `UAMS-Web/uams-statamic-archive#589` retired the single `video` Bard set and replaced it
 * with `youtube` / `vimeo` / `box`. `VideoSetType` is the one place that decides
 * which of the three a source URL belongs to, so `EmbedProcessor` (Gutenberg
 * `wp:embed`) and `ContentParser` (raw iframes) cannot drift apart on the answer.
 *
 * The Box rule is deliberately narrow: the `uamsweb_set_video_box` fieldset
 * validates `box_shared_static_video_url`, i.e. an HTTPS `app.box.com/shared/static/`
 * DIRECT link ending in a video extension. A Box `/s/` share link is a viewer page,
 * not a playable file, and the corpus carries far more of those (`/s/`, plus
 * `/shared/static/` PDFs and slide decks) than it does the 28 `.mp4` direct links —
 * so a loose host match would route documents into a video set.
 *
 * @command  php vendor/bin/pest tests/Unit/VideoSetTypeRoutingTest.php
 */
it('routes a YouTube URL to the youtube set', function (string $url): void {
    expect(VideoSetType::forUrl($url))->toBe('youtube');
})->with([
    'watch' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
    'watch without www' => ['https://youtube.com/watch?v=dQw4w9WgXcQ'],
    'embed' => ['https://www.youtube.com/embed/dQw4w9WgXcQ'],
    'short' => ['https://youtu.be/dQw4w9WgXcQ'],
    'mobile' => ['https://m.youtube.com/watch?v=dQw4w9WgXcQ'],
    'nocookie' => ['https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'],
    'protocol relative' => ['//www.youtube.com/watch?v=dQw4w9WgXcQ'],
    'http' => ['http://www.youtube.com/watch?v=dQw4w9WgXcQ'],
])->group('video-sets');

it('routes a Vimeo URL to the vimeo set', function (string $url): void {
    expect(VideoSetType::forUrl($url))->toBe('vimeo');
})->with([
    'watch' => ['https://vimeo.com/123456789'],
    'watch with www' => ['https://www.vimeo.com/123456789'],
    'player' => ['https://player.vimeo.com/video/123456789'],
    'protocol relative' => ['//player.vimeo.com/video/123456789'],
])->group('video-sets');

it('routes a Box direct video link to the box set', function (string $url): void {
    // The shape `box_shared_static_video_url` accepts — 28 of these are in the corpus
    expect(VideoSetType::forUrl($url))->toBe('box');
})->with([
    'mp4' => ['https://app.box.com/shared/static/0axznu7gfa93zfjs8mbx07u3accc4okg.mp4'],
    'mov' => ['https://app.box.com/shared/static/abc123.mov'],
    'm4v' => ['https://app.box.com/shared/static/abc123.m4v'],
    'webm' => ['https://app.box.com/shared/static/abc123.webm'],
    'with a query string' => ['https://app.box.com/shared/static/abc123.mp4?dl=1'],
    'uppercase extension' => ['https://app.box.com/shared/static/abc123.MP4'],
])->group('video-sets');

it('refuses a Box link that is not a direct video file', function (string $url): void {
    // A `/s/` share link is a viewer page and a `/shared/static/` document is not a
    // video — routing either into the box set would ship a value its own validation
    // rejects. All of these appear in the corpus.
    expect(VideoSetType::forUrl($url))->toBeNull();
})->with([
    'share link' => ['https://app.box.com/s/7lpqq4yo0msp2zjde788zkzvepsqp2ex'],
    'static pdf' => ['https://app.box.com/shared/static/abc123.pdf'],
    'static pptx' => ['https://app.box.com/shared/static/abc123.pptx'],
    'static csv' => ['https://app.box.com/shared/static/abc123.csv'],
    'no extension' => ['https://app.box.com/shared/static/abc123'],
    'http not https' => ['http://app.box.com/shared/static/abc123.mp4'],
    'extension only in the query' => ['https://app.box.com/shared/static/abc123?f=x.mp4'],
])->group('video-sets');

it('routes an unsupported host nowhere', function (string $url): void {
    expect(VideoSetType::forUrl($url))->toBeNull();
})->with([
    'dropbox' => ['https://www.dropbox.com/s/1s089k8v4u5j6yl/clip.mp4'],
    'wistia' => ['https://uams.wistia.com/medias/abc123'],
    'a bare page' => ['https://www.uams.edu/news/'],
    'empty' => [''],
    'whitespace' => ['   '],
])->group('video-sets');

it('does not match a lookalike host', function (string $url): void {
    // The host anchor has to be exact — a domain that merely CONTAINS a platform
    // name, or embeds one in its path, must not be routed to that platform's set.
    expect(VideoSetType::forUrl($url))->toBeNull();
})->with([
    'youtube in a subdomain of another host' => ['https://youtube.com.evil.example/watch?v=x'],
    'youtube in a path' => ['https://example.com/youtube.com/watch?v=x'],
    'vimeo in a path' => ['https://example.com/vimeo.com/123'],
    'box lookalike host' => ['https://app.box.com.evil.example/shared/static/a.mp4'],
])->group('video-sets');

it('trims a padded URL before routing', function (): void {
    expect(VideoSetType::forUrl('  https://vimeo.com/123456789  '))->toBe('vimeo');
})->group('video-sets');

it('lets the URL decide, never the claimed provider', function (): void {
    // The URL is what ends up embedded, so it is the value that has to be right. A
    // provider that disagrees loses rather than overriding it.
    expect(VideoSetType::forProviderOrUrl('youtube', 'https://vimeo.com/1'))->toBe('vimeo');
    expect(VideoSetType::forProviderOrUrl('VIMEO', 'https://www.youtube.com/watch?v=x'))->toBe('youtube');
})->group('video-sets');

it('routes on the URL when the provider is unknown or absent', function (): void {
    // The corpus carries junk provider slugs (`is-provider-guide-with-shadow`,
    // `is-provider-academic-affairs`), and Box direct links have no provider class
    // at all, so an unknown provider must not block routing.
    expect(VideoSetType::forProviderOrUrl('guide-with-shadow', 'https://youtu.be/x'))->toBe('youtube');
    expect(VideoSetType::forProviderOrUrl('', 'https://app.box.com/shared/static/a.mp4'))->toBe('box');
    expect(VideoSetType::forProviderOrUrl('wistia', 'https://uams.wistia.com/medias/x'))->toBeNull();
})->group('video-sets');

it('refuses a hostile URL that a claimed provider vouches for', function (string $url): void {
    // The WXR is attacker-influenced input and `is-provider-<name>` is just a CSS
    // class, so a provider must never promote a URL that routes nowhere. Trusting it
    // would have emitted the attacker's host inside a trusted platform embed.
    expect(VideoSetType::forProviderOrUrl('youtube', $url))->toBeNull();
    expect(VideoSetType::forProviderOrUrl('vimeo', $url))->toBeNull();
    expect(VideoSetType::forProviderOrUrl('box', $url))->toBeNull();
})->with([
    'platform name in a query string' => ['https://tracker.evil.example/px?ref=youtube.com/watch?v=abc'],
    'platform name in a path' => ['https://evil.example/youtube.com/embed/abc'],
    'userinfo before the real host' => ['https://youtube.com@evil.example/watch?v=abc'],
    'lookalike domain' => ['https://youtube.com.evil.example/watch?v=abc'],
    'no URL at all' => [''],
])->group('video-sets');

it('canonicalizes a YouTube URL into the shape the set accepts', function (
    string $source,
    string $expected
): void {
    // `App\Rules\YouTubeUrl` accepts only `watch?v=<id>` / `youtu.be/<id>`. Every
    // other corpus shape has to be rewritten to it, or the imported block cannot be
    // saved in the Control Panel. `?si=` alone accounts for 44 corpus embeds. wordpress-importer-archive#298
    expect(VideoSetType::canonicalUrl($source))->toBe($expected);
})->with([
    'already canonical' => [
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
    'youtu.be with an si tracking token' => [
        'https://youtu.be/Aek1YycYG8Y?si=rybtFX6rp0F_asXK',
        'https://www.youtube.com/watch?v=Aek1YycYG8Y',
    ],
    'youtu.be with a start time' => [
        'https://youtu.be/Aek1YycYG8Y?t=2s',
        'https://www.youtube.com/watch?v=Aek1YycYG8Y',
    ],
    'embed path' => [
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
    'live path' => [
        'https://www.youtube.com/live/dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
    'nocookie embed' => [
        'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
    'mobile host' => [
        'https://m.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
    'time_continue before the v parameter' => [
        'https://www.youtube.com/watch?time_continue=4&v=dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
    'playlist parameters dropped' => [
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PL123&index=2',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
    'protocol relative' => [
        '//www.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    ],
])->group('video-sets');

it('canonicalizes a Vimeo URL into the shape the set accepts', function (
    string $source,
    string $expected
): void {
    // `App\Rules\VimeoUrl` accepts only `https://vimeo.com/<digits>` with an
    // optional `/<hash>` — no `www.`, no `player.`, no query string. The corpus
    // carries 30 `player.vimeo.com` embeds. wordpress-importer-archive#298
    expect(VideoSetType::canonicalUrl($source))->toBe($expected);
})->with([
    'already canonical' => ['https://vimeo.com/336892869', 'https://vimeo.com/336892869'],
    'www host' => ['https://www.vimeo.com/336892869', 'https://vimeo.com/336892869'],
    'player host' => ['https://player.vimeo.com/video/730461092', 'https://vimeo.com/730461092'],
    'player host with an unlisted hash' => [
        'https://player.vimeo.com/video/730461092?h=231eb51843',
        'https://vimeo.com/730461092/231eb51843',
    ],
    'unlisted hash already in the path' => [
        'https://vimeo.com/730461092/231eb51843',
        'https://vimeo.com/730461092/231eb51843',
    ],
    'player host with tracking parameters' => [
        'https://player.vimeo.com/video/730461092?badge=0&autopause=0',
        'https://vimeo.com/730461092',
    ],
])->group('video-sets');

it('refuses a platform URL that carries no embeddable video', function (string $url): void {
    // A Vimeo `event/` link is a live-event page, not a video — the set's rule
    // rejects it, so it must fall back to a plain link rather than ship an
    // unsaveable block. Five of these are in the corpus. wordpress-importer-archive#298
    expect(VideoSetType::resolve($url))->toBeNull();
})->with([
    'vimeo event' => ['https://vimeo.com/event/3590954'],
    'vimeo channel' => ['https://vimeo.com/channels/staffpicks'],
    'youtube channel' => ['https://www.youtube.com/'],
])->group('video-sets');

it('rebuilds a Box direct link without its query', function (string $source): void {
    // The app's own validator parses with `parse_url()` and inspects only scheme,
    // host, and path — so it would accept a query carrying anything. Rebuilding from
    // the matched filename means the stored value can only be the canonical link.
    expect(VideoSetType::canonicalUrl($source))
        ->toBe('https://app.box.com/shared/static/abc123.mp4');
})->with([
    'bare' => ['https://app.box.com/shared/static/abc123.mp4'],
    'with a download parameter' => ['https://app.box.com/shared/static/abc123.mp4?dl=1'],
    'with a fragment' => ['https://app.box.com/shared/static/abc123.mp4#t=10'],
])->group('video-sets');

it('refuses a URL carrying a character that could break out of an attribute', function (
    string $url
): void {
    // The emitted value survives as a raw string: the Tiptap parser decodes the
    // entity encoding this importer applies, Bard does not augment a video URL, and
    // the host app prints it into `src="…"` / `data-video-url="…"` unescaped because
    // Antlers is raw by default. A `"` in the source would close the attribute and
    // let `onload=` follow it onto every migrated page. wordpress-importer-archive#298
    expect(VideoSetType::resolve($url))->toBeNull();
})->with([
    'double quote' => ['https://app.box.com/shared/static/a.mp4?x=" onload="alert(1)'],
    'single quote' => ["https://app.box.com/shared/static/a.mp4?x=' onload='alert(1)"],
    'angle bracket' => ['https://app.box.com/shared/static/a.mp4?x=<script>'],
    'backtick' => ['https://app.box.com/shared/static/a.mp4?x=`'],
    'backslash' => ['https://app.box.com/shared/static/a.mp4?x=\\'],
    'newline' => ["https://app.box.com/shared/static/a.mp4?x=\nfoo"],
    'tab' => ["https://app.box.com/shared/static/a.mp4?x=\tfoo"],
    // Mid-string, because `trim()` would strip a trailing null before the gate saw it
    'null byte' => ["https://app.box.com/shared/static/a.mp4?x=\0foo"],
    'space' => ['https://app.box.com/shared/static/a.mp4?x= foo'],
])->group('video-sets');

it('cannot be tricked into embedding an attribute break through YouTube or Vimeo', function (
    string $url
): void {
    // These branches rebuild from a strict id charset, so even without the gate the
    // hostile tail could not survive. Both defences are asserted so removing either
    // one shows up here.
    $resolved = VideoSetType::resolve($url);

    if ($resolved !== null) {
        expect($resolved['url'])->not->toContain('"');
        expect($resolved['url'])->not->toContain('onload');
    }

    expect($resolved)->toBeNull();
})->with([
    'youtube watch' => ['https://www.youtube.com/watch?v=abc" onload="alert(1)'],
    'youtu.be' => ['https://youtu.be/abc" onload="alert(1)'],
    'vimeo' => ['https://vimeo.com/12345" onload="alert(1)'],
])->group('video-sets');

it('never returns the retired video set handle', function (string $url): void {
    // #589 deleted the `video` set from `uamsweb_common.yaml`, so emitting it puts
    // content on a set type the Bard field does not define.
    expect(VideoSetType::forUrl($url))->not->toBe('video');
    expect(VideoSetType::SET_TYPES)->not->toContain('video');
})->with([
    'https://www.youtube.com/watch?v=x',
    'https://vimeo.com/1',
    'https://app.box.com/shared/static/a.mp4',
])->group('video-sets');

it('routes a blank or whitespace-only URL nowhere', function (string $url): void {
    // Pins the empty-input result before the redundant `$url === ''` early return is
    // removed: an empty string carries no host, so no platform resolver can match it.
    expect(VideoSetType::resolve($url))->toBeNull()
        ->and(VideoSetType::forUrl($url))->toBeNull()
        ->and(VideoSetType::canonicalUrl($url))->toBeNull();
})->with([
    'empty' => [''],
    'spaces' => ['   '],
    'tab and newline' => ["\t\n"],
])->group('video-sets');
