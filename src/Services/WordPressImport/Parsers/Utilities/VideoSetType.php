<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Utilities;

/**
 * Routes a source video URL to the Bard set that hosts it, and canonicalizes the
 * URL into the shape that set's own validation accepts.
 *
 * `UAMS-Web/uams-statamic-archive#589` retired the single `video` Bard set and replaced it
 * with three platform-specific sets — `youtube`, `vimeo`, and `box` — each with its
 * own URL validation, its own `url` field handle, and an `embed_mode` toggle. The
 * importer previously emitted `type: video` with a `video_url` handle, which no
 * longer matches any set in `uamsweb_common.yaml`, so imported video content landed
 * on a set type the Bard field does not define (wordpress-importer-archive#298).
 *
 * Routing alone is not enough. Each set declares `validate: [required, <rule>]`, and
 * those rules are far narrower than the hosts they accept content from:
 *
 *   - `App\Rules\YouTubeUrl` — `https://[www.]youtube.com/watch?v=<id>` or
 *     `https://[www.]youtu.be/<id>`, optionally followed by `&…`. A `?si=` tracking
 *     parameter, `m.youtube.com`, `youtube-nocookie.com`, `/embed/`, `/live/`, and
 *     `?time_continue=4&v=…` all FAIL.
 *   - `App\Rules\VimeoUrl` — `https://vimeo.com/<digits>` with an optional
 *     `/<hash>`. No `www.`, no `player.`, no query string.
 *   - `App\Rules\BoxSharedStaticVideoUrl` — an `app.box.com/shared/static/` direct
 *     link ending in a video extension.
 *
 * Measured over the export corpus, 99 embeds (63 YouTube, 36 Vimeo) reach a set
 * whose rule rejects them — `https://youtu.be/<id>?si=<token>` alone accounts for
 * 44. An editor opening one of those entries cannot save it without hand-editing
 * the URL, which defeats the point of landing content on a set the Bard field both
 * defines and accepts. So this class extracts the video id and re-emits the
 * canonical form, and refuses anything it cannot canonicalize (a Vimeo `event/`
 * link, for instance) so the caller falls back to a plain link rather than shipping
 * an unsaveable block.
 *
 * It is the single place that makes that decision, so `EmbedProcessor` (Gutenberg
 * `wp:embed` blocks) and `ContentParser` (raw iframes) cannot drift apart on it.
 */
class VideoSetType
{
    /**
     * The Bard set handle for YouTube videos.
     */
    public const string YOUTUBE = 'youtube';

    /**
     * The Bard set handle for Vimeo videos.
     */
    public const string VIMEO = 'vimeo';

    /**
     * The Bard set handle for Box.com direct-link videos.
     */
    public const string BOX = 'box';

    /**
     * The `embed_mode` value for a plain embed carrying no segment metadata.
     *
     * WordPress video embeds carry a URL, an optional caption, and nothing else —
     * no transcript, creators, or transparency metadata — so an import can never
     * justify the `full` segment mode. The button group defaults to `simple`
     * anyway; the importer writes it explicitly so the stored value is not left to
     * the field default.
     */
    public const string EMBED_MODE_SIMPLE = 'simple';

    /**
     * Every set handle this router can return.
     *
     * @var list<string>
     */
    public const array SET_TYPES = [
        self::YOUTUBE,
        self::VIMEO,
        self::BOX,
    ];

    /**
     * Resolves the set handle and the canonical URL for a source video URL.
     *
     * @param  string  $url  The source video URL
     * @return array{type: string, url: string}|null The set and canonical URL, or null when unusable
     */
    public static function resolve(
        string $url
    ): ?array {
        // Normalize so a padded or protocol-relative URL still matches. An empty
        // result needs no guard of its own: it carries no host, so every platform
        // resolver below refuses it.
        $url = \trim($url);

        // ---
        // Refuse a URL carrying a character that could break out of the attribute it
        // is rendered into. The emitted value survives as a raw string — the Tiptap
        // parser decodes the entity encoding this importer applies, Bard does not
        // augment a video URL, and the host app's `sets/_video.antlers.html` prints
        // it into `src="…"` and `data-video-url="…"` with no escaping, because
        // Antlers is raw by default. A `"` in the source URL would therefore close
        // the attribute and let `onload=` follow it onto every migrated page.
        //
        // The YouTube and Vimeo branches already rebuild their URLs from a strict id
        // charset, so nothing hostile can survive them; this gate is what protects
        // the Box branch and anything added later.
        // ---
        if (
            \preg_match(
                '/[\x00-\x20\x7F"\'<>`\\\\]/',
                $url
            ) === 1
        ) {
            return null;
        }

        // Try each platform in turn
        return self::resolveYouTube($url)
            ?? self::resolveVimeo($url)
            ?? self::resolveBox($url);
    }

    /**
     * Resolves the Bard set handle for a source video URL.
     *
     * @param  string  $url  The source video URL
     * @return ?string The set handle, or null when no platform matches
     */
    public static function forUrl(
        string $url
    ): ?string {
        // Read the set handle off the full resolution
        return self::resolve($url)['type'] ?? null;
    }

    /**
     * Rewrites a source video URL into the shape its destination set accepts.
     *
     * @param  string  $url  The source video URL
     * @return ?string The canonical URL, or null when it cannot be canonicalized
     */
    public static function canonicalUrl(
        string $url
    ): ?string {
        // Read the canonical URL off the full resolution
        return self::resolve($url)['url'] ?? null;
    }

    /**
     * Resolves the Bard set handle for a URL, cross-checked against a provider name.
     *
     * **The URL is authoritative.** `EmbedProcessor` reads a provider from the
     * Gutenberg figure's `is-provider-<name>` class and `ContentParser` derives one
     * while unpacking an iframe `src`, but neither is trustworthy: the WXR is
     * attacker-influenced input, and `is-provider-<name>` is just a CSS class. A URL
     * that routes nowhere is refused no matter what the provider claims.
     *
     * That ordering is the security property. Trusting the provider instead let a
     * hostile host ride into a platform set: `ContentParser`'s
     * `extractVideoDataFromIframeSrc()` matched `youtube.com/watch?v=` ANYWHERE in
     * the `src`, so an iframe pointing at
     * `https://tracker.evil.example/px?ref=youtube.com/watch?v=abc` reported
     * `provider: youtube` while carrying the attacker's URL — and a provider-first
     * router would have emitted that URL inside a trusted `youtube` embed. Those
     * patterns are now host-anchored too, so this is defence in depth rather than
     * the only guard.
     *
     * The provider is advisory only: it never promotes, overrides, or rescues a URL
     * that does not resolve on its own.
     *
     * @param  string  $provider  The provider name from the source markup, may be empty
     * @param  string  $url  The source video URL
     * @return ?string The set handle, or null when the URL routes nowhere
     */
    public static function forProviderOrUrl(
        string $provider,
        string $url
    ): ?string {
        // The URL decides, because the URL is what ends up embedded
        return self::forUrl($url);
    }

    /**
     * Resolves a YouTube URL to the canonical watch form.
     *
     * `App\Rules\YouTubeUrl` accepts only `watch?v=<id>` and `youtu.be/<id>`, so
     * every other shape the corpus carries — `/embed/`, `/live/`, `m.`,
     * `youtube-nocookie.com`, and any `?si=` / `?t=` / `?list=` / `?time_continue=`
     * parameter — has to be rewritten to it rather than passed through.
     *
     * @param  string  $url  The trimmed source URL
     * @return array{type: string, url: string}|null The resolution, or null when not YouTube
     */
    private static function resolveYouTube(
        string $url
    ): ?array {
        // Match a YouTube host and capture whatever follows it
        if (
            \preg_match(
                '#^(?:https?:)?//(?:www\.|m\.)?(?:youtube\.com|youtube-nocookie\.com|youtu\.be)/(.*)$#i',
                $url,
                $hostMatch
            ) !== 1
        ) {
            return null;
        }

        // The path and query that follow the host
        $rest = $hostMatch[1];

        // Pull the eleven-ish character video id out of whichever shape this is
        $patterns = [
            // youtu.be/<id>, /embed/<id>, /live/<id>, /v/<id>, /shorts/<id>
            '#^(?:embed/|live/|v/|shorts/)?([A-Za-z0-9_-]+)#',
        ];

        // A watch URL carries the id in the `v` query parameter, wherever it sits
        if (
            \preg_match(
                '#[?&]v=([A-Za-z0-9_-]+)#',
                $url,
                $watchMatch
            ) === 1
        ) {
            return [
                'type' => self::YOUTUBE,
                'url' => 'https://www.youtube.com/watch?v='.$watchMatch[1],
            ];
        }

        // Otherwise take the id from the path
        foreach ($patterns as $pattern) {
            if (
                \preg_match(
                    $pattern,
                    $rest,
                    $pathMatch
                ) === 1
            ) {
                return [
                    'type' => self::YOUTUBE,
                    'url' => 'https://www.youtube.com/watch?v='.$pathMatch[1],
                ];
            }
        }

        // A YouTube host with no extractable video id (a channel or playlist page)
        return null;
    }

    /**
     * Resolves a Vimeo URL to the canonical watch form.
     *
     * `App\Rules\VimeoUrl` accepts only `https://vimeo.com/<digits>` with an
     * optional `/<hash>` — no `www.`, no `player.`, no query string. The unlisted
     * hash arrives as `?h=<hash>` on a player URL and moves into the path.
     *
     * @param  string  $url  The trimmed source URL
     * @return array{type: string, url: string}|null The resolution, or null when not Vimeo
     */
    private static function resolveVimeo(
        string $url
    ): ?array {
        // Match a Vimeo host and capture whatever follows it
        if (
            \preg_match(
                '#^(?:https?:)?//(?:www\.|player\.)?vimeo\.com/(.*)$#i',
                $url,
                $hostMatch
            ) !== 1
        ) {
            return null;
        }

        // The path and query that follow the host
        $rest = $hostMatch[1];

        // Take the numeric video id, from either the bare or the player path
        if (
            \preg_match(
                '#^(?:video/)?(\d+)#',
                $rest,
                $idMatch
            ) !== 1
        ) {
            // A Vimeo host with no numeric video id — an `event/` link, a channel, a
            // user page. None of those is a video the set can embed.
            return null;
        }

        // Start from the canonical bare-host form
        $canonical = 'https://vimeo.com/'.$idMatch[1];

        // Carry an unlisted-video hash across from the path or the `h` parameter
        $hash = null;

        if (
            \preg_match(
                '#^(?:video/)?\d+/([A-Za-z0-9]+)#',
                $rest,
                $pathHash
            ) === 1
        ) {
            $hash = $pathHash[1];
        } elseif (
            \preg_match(
                '#[?&]h=([A-Za-z0-9]+)#',
                $url,
                $queryHash
            ) === 1
        ) {
            $hash = $queryHash[1];
        }

        // Append the hash in the path form the rule accepts
        if ($hash !== null) {
            $canonical .= '/'.$hash;
        }

        return [
            'type' => self::VIMEO,
            'url' => $canonical,
        ];
    }

    /**
     * Resolves a Box.com direct video link.
     *
     * Matched only on the shape `App\Rules\BoxSharedStaticVideoUrl` validates — an
     * HTTPS `app.box.com/shared/static/` direct link ending in a video extension. A
     * Box `/s/` share link is a viewer page, not a playable file, and the rule
     * rejects it, so it must not be routed here.
     *
     * The URL is rebuilt from the matched filename rather than passed through, and
     * any query or fragment is dropped. The app's own validator parses with
     * `parse_url()` and inspects only the scheme, host, and path, so it would accept
     * a query carrying anything at all — including the attribute-breaking characters
     * the gate in `resolve()` now rejects. Rebuilding means the stored value can only
     * ever be the canonical direct link.
     *
     * @param  string  $url  The trimmed source URL
     * @return array{type: string, url: string}|null The resolution, or null when not a Box video
     */
    private static function resolveBox(
        string $url
    ): ?array {
        // Match only a Box direct link to a video file, which is what the set accepts
        if (
            \preg_match(
                '#^https://app\.box\.com/shared/static/([A-Za-z0-9._~-]+\.(?:mp4|m4v|mov|webm))(?:$|[?\#])#i',
                $url,
                $match
            ) !== 1
        ) {
            return null;
        }

        return [
            'type' => self::BOX,
            'url' => 'https://app.box.com/shared/static/'.$match[1],
        ];
    }
}
