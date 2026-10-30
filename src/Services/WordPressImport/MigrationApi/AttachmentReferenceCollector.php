<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi;

use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetUtils;

/**
 * Collects every way a scoped run's items can reach an attachment (wordpress-importer-archive#1175).
 *
 * A scoped run reads only the attachments its items reference, so this has to find all of
 * them. **It errs wide on purpose.** A reference it misses is media that imports as nothing,
 * with no error; a reference it invents is one extra record in the read. So ids are taken
 * from every integer in an item's meta values and block attributes, not only from the fields
 * known to hold one, and only the shapes named below are reported when the site does not
 * serve them.
 *
 * Three kinds of reference come out of an item:
 *
 *   ids      every integer in meta values and in block-comment attributes, plus the named
 *            HTML and shortcode shapes: a `wp-image-N` class, an `<img>`'s `data-id`, `[gallery ids]`,
 *            `[caption id="attachment_N"]` and `?attachment_id=N`
 *   paths    the uploads-relative path of every same-site upload URL, which the API's
 *            `file[]` resolves to the attachment that owns it, generated sizes included
 *   links    every same-site URL that could be a page's permalink, because an attachment's
 *            own page is a permalink like any other
 *
 * Image URLs are also kept whole, so the materializer can check each one against the
 * attachments it read with the importer's own matcher. The shapes, and the file that reads
 * each one, are enumerated in `docs/migration-api-contract.md`.
 */
final class AttachmentReferenceCollector
{
    /**
     * Meta keys the importer reads an attachment id out of by name.
     *
     * Every integer in every meta value is collected regardless; these are the ones reported
     * when the site does not serve the attachment. Keys matching `NAMED_META_PATTERNS` count
     * as named too.
     *
     * @var list<string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails AttachmentReferenceCollectorTest (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array NAMED_META_KEYS = [
        '_thumbnail_id',
        'page_cover_image',
        'page_landing_page_cover_image',
        'page_landing_page_cover_image_mobile',
        'logo_image',
        '_mkb_article_attachments',
        '_adverts_attachments_order',
    ];

    /**
     * Meta key patterns the importer reads an attachment id out of.
     *
     * The page hero's repeater rows and the modules builder's rows are numbered, so these
     * are matched by shape rather than listed.
     *
     * @var list<string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails AttachmentReferenceCollectorTest (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array NAMED_META_PATTERNS = [
        '/^page_hero_hero_\d+_hero_image_(desktop|tablet|mobile)$/',
        '/^uamswp_modules_\d+_.*image.*$/',
    ];

    /**
     * Underscore meta keys whose values the importer does process as links.
     *
     * A redirect rule's target reaches `LinkProcessor`, which can resolve it to an
     * attachment's page. Every other underscore key is read for integers only.
     *
     * @var list<string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails AttachmentReferenceCollectorTest (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array PRIVATE_META_READ_AS_TEXT = [
        '_redirect_rule_to',
    ];

    /**
     * File extensions the image pipeline resolves through an attachment.
     *
     * An upload URL with one of these is checked against the attachments read, because
     * `AssetImporterProcessor` resolves an image `src` to its attachment by URL. Any other
     * upload URL is imported by URL alone and needs no attachment.
     *
     * @var list<string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails AttachmentReferenceCollectorTest (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array IMAGE_EXTENSIONS = [
        'avif', 'bmp', 'gif', 'heic', 'ico', 'jpeg', 'jpg', 'png', 'svg', 'tif', 'tiff', 'webp',
    ];

    /**
     * First path segments that name an archive or a WordPress system path, never a post.
     *
     * A link under one of these cannot be an attachment's page, so it does not have to
     * resolve for the narrowed read to be safe.
     *
     * @var list<string>
     */
    // PCOV records no line for a class constant, so the mutation plugin never runs a mutant of
    // these items; removing each one by hand fails AttachmentReferenceCollectorTest (#102)
    // @pest-mutate-ignore: RemoveArrayItem
    private const array NON_POST_SEGMENTS = [
        'author', 'category', 'feed', 'page', 'tag', 'wp-admin', 'wp-content', 'wp-includes',
        'wp-json', 'wp-login.php',
    ];

    /**
     * Every candidate attachment id, keyed by id.
     *
     * @var array<int, true>
     */
    private array $ids = [];

    /**
     * Ids found in a shape the importer reads as an attachment, with where each was first seen.
     *
     * @var array<int, array{shape: string, item: int}>
     */
    private array $namedIds = [];

    /**
     * Uploads-relative paths, keyed by path.
     *
     * @var array<string, true>
     */
    private array $paths = [];

    /**
     * Same-site upload image URLs, keyed by URL, with the item each was first seen on.
     *
     * @var array<string, int>
     */
    private array $imageUrls = [];

    /**
     * Same-site permalink candidates, keyed by normalized link, with the item each was first seen on.
     *
     * @var array<string, int>
     */
    private array $links = [];

    /**
     * Create a collector for one site.
     *
     * @param  string  $baseBlogUrl  The site's base URL, which decides what is same-site
     *                               exactly as the importer decides it
     */
    public function __construct(
        private readonly string $baseBlogUrl
    ) {}

    /**
     * Collect the references one post payload carries.
     *
     * @param  array<array-key, mixed>  $post  A post payload as the API serves it
     */
    public function collect(
        array $post
    ): void {
        $item = \is_numeric($post['id'] ?? null) ? (int) $post['id'] : 0;

        // The rendered content and the excerpt are HTML, block comments included.
        foreach (['content', 'excerpt'] as $field) {
            if (\is_string($post[$field] ?? null)) {
                $this->collectText($post[$field], $item);
            }
        }

        $meta = $post['meta'] ?? null;

        if (! \is_array($meta)) {
            return;
        }

        foreach ($meta as $key => $values) {
            if (! \is_string($key)) {
                continue;
            }

            $named = $this->isNamedMetaKey($key);

            foreach (\is_array($values) ? $values : [$values] as $value) {
                // A structured value is read as the JSON the materializer writes it as, so an
                // id nested in it is still a candidate.
                if (\is_scalar($value)) {
                    $value = (string) $value;
                } elseif (\is_array($value)) {
                    // A decoded payload always encodes, so the cast only narrows the type
                    // @pest-mutate-ignore: RemoveStringCast
                    $value = (string) \json_encode($value);
                } else {
                    continue;
                }

                // Every integer in a meta value is a candidate: an ACF image field stores a
                // bare id, a gallery stores a serialized list, and neither is named anywhere
                // the importer does not already read by key.
                foreach ($this->integersIn($value) as $id) {
                    $this->addId($id, $named ? 'meta:'.$key : null, $item);
                }

                // A meta value can also hold HTML or a URL, such as a WYSIWYG field or a link.
                // Only fields the importer processes are read for them: ACF keeps its values
                // under public keys, while a leading underscore is a plugin's own record, such
                // as an SEO plugin's canonical URL and social image, which nothing imports.
                // Meta keys are strings, so the strict flag, which phpstan-strict-rules requires, changes no result
                // @pest-mutate-ignore: TrueToFalse
                if (! \str_starts_with($key, '_') || \in_array($key, self::PRIVATE_META_READ_AS_TEXT, true)) {
                    $this->collectText($value, $item);
                }
            }
        }
    }

    /**
     * Every candidate attachment id, ascending.
     *
     * @return list<int>
     */
    public function ids(): array
    {
        $ids = \array_keys($this->ids);
        \sort($ids);

        return $ids;
    }

    /**
     * The ids found in a named shape, with the shape and the item each was first seen on.
     *
     * @return array<int, array{shape: string, item: int}>
     */
    public function namedIds(): array
    {
        return $this->namedIds;
    }

    /**
     * Every uploads-relative path, sorted.
     *
     * @return list<string>
     */
    public function paths(): array
    {
        // A path keeps its dot, so no key turns into an int; the map only narrows the type
        // @pest-mutate-ignore: UnwrapArrayMap
        $paths = \array_map(strval(...), \array_keys($this->paths));
        \sort($paths);

        return $paths;
    }

    /**
     * Same-site upload image URLs, with the item each was first seen on.
     *
     * @return array<string, int>
     */
    public function imageUrls(): array
    {
        return $this->imageUrls;
    }

    /**
     * Same-site permalink candidates, normalized, with the item each was first seen on.
     *
     * @return array<string, int>
     */
    public function links(): array
    {
        return $this->links;
    }

    /**
     * Normalize a permalink so a link and an item's `link` compare equal.
     *
     * Drops the scheme, the query, the fragment and any trailing slash, and lowercases the
     * rest. `LinkProcessor` matches a link's text exactly first, so this is looser than the
     * importer on purpose: a link it counts as resolved here the importer can still fail to
     * match, but never the reverse.
     *
     * @param  string  $url  A URL
     * @return string The normalized form
     */
    public static function normalizeLink(
        string $url
    ): string {
        $url = \strtolower(\trim($url));
        // A replacement over a string answers with a string, so the casts only narrow the type
        // @pest-mutate-ignore: RemoveStringCast
        $url = (string) \preg_replace('#^https?:#', '', $url);
        // @pest-mutate-ignore: RemoveStringCast
        $url = (string) \preg_replace('/[?#].*$/', '', $url);

        return \rtrim($url, '/');
    }

    /**
     * The uploads-relative path of an upload URL, or null when it is not one.
     *
     * The API's `file[]` takes the path `_wp_attached_file` stores, such as `2020/09/a.png`.
     * The per-site `uploads/sites/N` form is tried before the plain `uploads` one, and the
     * `files/` form covers both the legacy `blogs.dir/N/files` path and the rewrite that
     * serves it. The path is URL-decoded because the API compares it as stored.
     *
     * @param  string  $url  A URL
     * @return string|null The relative path, or null
     */
    public static function uploadsRelativePath(
        string $url
    ): ?string {
        $path = \parse_url($url, \PHP_URL_PATH);

        // An empty path has no file in it either, which the extension check below answers
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        if (! \is_string($path) || $path === '') {
            return null;
        }

        $path = \rawurldecode($path);

        // A file has an extension in its last segment; a page slug does not.
        if (! \str_contains(\basename($path), '.')) {
            return null;
        }

        foreach ([
            '#/wp-content/uploads/sites/\d+/(.+)$#',
            '#/wp-content/uploads/(.+)$#',
            '#/files/(.+)$#',
        ] as $pattern) {
            if (\preg_match($pattern, $path, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * Collect the references a block of HTML or a meta string carries.
     *
     * @param  string  $text  The text to scan
     * @param  int  $item  The item it came from
     */
    private function collectText(
        string $text,
        int $item
    ): void {
        // Block attributes are JSON inside an HTML comment, so a URL or HTML fragment in them
        // is escaped. Undo the escaping once so the patterns below see one form.
        $text = $this->unescapeJson($text);

        // Block attributes: every integer in them is a candidate, and `id` and `ids` are the
        // attributes core/image and core/gallery hold an attachment in.
        $found = \preg_match_all('/<!--\s+wp:([a-z0-9\/_-]+)\s+(\{.*?\})\s*\/?-->/s', $text, $blocks, \PREG_SET_ORDER);

        if ($found === false) {
            // The pattern gave up, which PCRE does on an attribute of about a megabyte. Every
            // block in the text would go unread, so every integer in it is taken instead.
            foreach ($this->integersIn($text) as $id) {
                $this->addId($id, null, $item);
            }
        } else {
            // No match leaves the list empty, so the loop does nothing
            foreach ($blocks as $block) {
                $this->collectBlockAttributes($block[1], $block[2], $item);
            }
        }

        // The HTML and shortcode shapes the importer reads an attachment id out of. A
        // `data-id` is named only on an `<img>`, where a gallery keeps it; anywhere else it
        // is some other plugin's id.
        $this->collectPattern('/\bwp-image-(\d+)\b/', $text, 'class:wp-image', $item);
        $this->collectPattern('/<img\b[^>]*\bdata-id\s*=\s*["\']?(\d+)/i', $text, 'attribute:img-data-id', $item);
        $this->collectPattern('/\[caption\b[^\]]*\bid\s*=\s*["\']?attachment_(\d+)/i', $text, 'shortcode:caption', $item);
        $this->collectPattern('/[?&]attachment_id=(\d+)/i', $text, 'link:attachment_id', $item);

        // No match leaves the list empty, so the loop does nothing
        \preg_match_all('/\[gallery\b[^\]]*\bids\s*=\s*["\']?([\d,\s]+)/i', $text, $galleries);

        foreach ($galleries[1] as $list) {
            foreach ($this->integersIn($list) as $id) {
                $this->addId($id, 'shortcode:gallery', $item);
            }
        }

        // Every absolute or protocol-relative URL, with the apostrophe entity that opens it
        // when a shortcode's value is quoted with one. Relative URLs are left alone, because
        // the importer does not treat them as same-site either.
        // No match leaves the list empty, so the loop does nothing
        \preg_match_all('~(&\#0*39;|&\#x0*27;|&apos;)?((?:https?:)?//[^\s"\'<>()\[\]{}\\\\,]+)~i', $text, $urls, \PREG_SET_ORDER);

        foreach ($urls as $match) {
            // An HTML attribute carries `&` as an entity, so decode the URL to the one the
            // markup holds before trimming, or `&amp;` would lose its `;` (#269)
            $url = \html_entity_decode($match[2], \ENT_QUOTES | \ENT_HTML5, 'UTF-8');

            // A quote or an angle bracket cannot stand in a URL, so a decoded one is the
            // entity-quoted value's closing quote, or a tag after it (#309)
            $url = \substr($url, 0, \strcspn($url, '"<>'));

            // An apostrophe can stand in a URL, as in a file name, so the URL ends at one
            // only when an apostrophe entity opened it
            if ($match[1] !== '') {
                $url = \substr($url, 0, \strcspn($url, "'"));
            }

            $this->collectUrl(\rtrim($url, '.;:!?'), $item);
        }
    }

    /**
     * Collect the ids one block's attributes carry.
     *
     * @param  string  $name  The block name, such as `image` or `acf/uams-gallery`
     * @param  string  $json  The attribute JSON
     * @param  int  $item  The item it came from
     */
    private function collectBlockAttributes(
        string $name,
        string $json,
        int $item
    ): void {
        $attributes = \json_decode($json, true);

        if (! \is_array($attributes)) {
            // Unparseable attributes still get their integers taken, so a block this cannot
            // decode is read wide rather than skipped.
            foreach ($this->integersIn($json) as $id) {
                $this->addId($id, null, $item);
            }

            return;
        }

        // Named only where the importer reads them: core/image's `id` and core/gallery's
        // `ids`. Other blocks use `id` for pages and menus, and ACF blocks keep their images
        // under `data` in field names this does not need to know, so all of those are taken
        // by the walk below as candidates without being named.
        if ($name === 'image' && \is_numeric($attributes['id'] ?? null)) {
            $this->addId((int) $attributes['id'], 'block:image:id', $item);
        }

        if ($name === 'gallery' && \is_array($attributes['ids'] ?? null)) {
            foreach ($attributes['ids'] as $id) {
                if (\is_numeric($id)) {
                    $this->addId((int) $id, 'block:gallery:ids', $item);
                }
            }
        }

        \array_walk_recursive($attributes, function (mixed $value) use ($item): void {
            if (\is_int($value)) {
                $this->addId($value, null, $item);
            } elseif (\is_string($value)) {
                foreach ($this->integersIn($value) as $id) {
                    $this->addId($id, null, $item);
                }
            }
        });
    }

    /**
     * Collect every id a pattern's first group captures, under one shape.
     *
     * @param  string  $pattern  The pattern
     * @param  string  $text  The text to scan
     * @param  string  $shape  The shape to record
     * @param  int  $item  The item it came from
     */
    private function collectPattern(
        string $pattern,
        string $text,
        string $shape,
        int $item
    ): void {
        // No match leaves the list empty, so the loop does nothing
        \preg_match_all($pattern, $text, $matches);

        foreach ($matches[1] as $id) {
            $this->addId((int) $id, $shape, $item);
        }
    }

    /**
     * Collect one URL, as an upload path or as a permalink candidate.
     *
     * @param  string  $url  The URL
     * @param  int  $item  The item it came from
     */
    private function collectUrl(
        string $url,
        int $item
    ): void {
        // Same-site is the importer's own test, so what is collected is what it would look up.
        if (! AssetUtils::isUrlOnSameSite($url, $this->baseBlogUrl)) {
            return;
        }

        $path = self::uploadsRelativePath($url);

        if ($path !== null) {
            // Only the key is read
            // @pest-mutate-ignore: TrueToFalse
            $this->paths[$path] = true;

            $extension = \strtolower(\pathinfo($path, \PATHINFO_EXTENSION));

            // Extensions are strings, so the strict flag, which phpstan-strict-rules requires, changes no result
            // @pest-mutate-ignore: TrueToFalse
            if (\in_array($extension, self::IMAGE_EXTENSIONS, true)) {
                $this->imageUrls[$url] ??= $item;
            }

            return;
        }

        if ($this->isPermalinkCandidate($url)) {
            $this->links[self::normalizeLink($url)] ??= $item;
        }
    }

    /**
     * Whether a same-site URL could be the permalink of a post, attachments included.
     *
     * Mirrors `LinkProcessor::isInternalPageLink()`: the last path segment below the site's
     * base must exist and carry no dot. The site's home and its archive and system paths are
     * excluded, since none can be an attachment's page.
     *
     * @param  string  $url  A same-site URL
     * @return bool True when it could be a permalink
     */
    private function isPermalinkCandidate(
        string $url
    ): bool {
        // A base with no path reads as the empty string either way
        // @pest-mutate-ignore: RemoveStringCast
        $base = (string) \parse_url($this->baseBlogUrl, \PHP_URL_PATH);
        // A URL with no path reads as the empty string either way
        // @pest-mutate-ignore: RemoveStringCast
        $path = (string) \parse_url($url, \PHP_URL_PATH);

        // The path below the site's base, so a subsite's own prefix is not read as a slug.
        $prefix = \strtolower(\rtrim($base, '/'));
        $relative = \trim(
            \str_starts_with(\strtolower($path), $prefix)
                ? \substr($path, \strlen($prefix))
                : $path,
            '/'
        );

        if ($relative === '') {
            return false;
        }

        $segments = \explode('/', $relative);

        // Path segments are strings, so the strict flag, which phpstan-strict-rules requires, changes no result
        // @pest-mutate-ignore: TrueToFalse
        if (\in_array(\strtolower($segments[0]), self::NON_POST_SEGMENTS, true)) {
            return false;
        }

        return ! \str_contains($segments[\count($segments) - 1], '.');
    }

    /**
     * Whether a meta key is one the importer reads an attachment id out of by name.
     *
     * @param  string  $key  The meta key
     * @return bool True when named
     */
    private function isNamedMetaKey(
        string $key
    ): bool {
        // Meta keys are strings, so the strict flag, which phpstan-strict-rules requires, changes no result
        // @pest-mutate-ignore: TrueToFalse
        return \in_array($key, self::NAMED_META_KEYS, true)
            || array_any(self::NAMED_META_PATTERNS, fn (string $pattern): bool => \preg_match($pattern, $key) === 1);
    }

    /**
     * Record one id, and its shape when it is a named one.
     *
     * @param  int  $id  The id
     * @param  string|null  $shape  The named shape, or null for a wide candidate
     * @param  int  $item  The item it came from
     */
    private function addId(
        int $id,
        ?string $shape,
        int $item
    ): void {
        // Zero is WordPress's "no attachment", and nothing larger than an int column can be one.
        if ($id < 1 || $id > 2147483647) {
            return;
        }

        // Only the key is read
        // @pest-mutate-ignore: TrueToFalse
        $this->ids[$id] = true;

        if ($shape !== null && ! isset($this->namedIds[$id])) {
            $this->namedIds[$id] = ['shape' => $shape, 'item' => $item];
        }
    }

    /**
     * Every run of digits in a string that stands alone rather than inside a word.
     *
     * @param  string  $value  The string
     * @return list<int> The integers, in order
     */
    private function integersIn(
        string $value
    ): array {
        // No match leaves the list empty, so the early return only skips mapping nothing
        // @pest-mutate-ignore: DecrementInteger, RemoveEarlyReturn
        if (\preg_match_all('/(?<![A-Za-z0-9_.])\d{1,10}(?![A-Za-z0-9_])/', $value, $matches) === 0) {
            return [];
        }

        return \array_map(intval(...), $matches[0]);
    }

    /**
     * Undo the JSON escaping block attributes carry, for slashes and `\uXXXX` sequences.
     *
     * @param  string  $text  The text
     * @return string The unescaped text
     */
    private function unescapeJson(
        string $text
    ): string {
        $text = \str_replace('\\/', '/', $text);

        // A replacement over a string answers with a string, so the cast only narrows the type
        // @pest-mutate-ignore: RemoveStringCast
        return (string) \preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            // Four hex digits always decode to an int, so the cast only narrows the type, and
            // hexdec() skips the backslash and the `u` of the whole match as it would any
            // character that is not a hex digit
            // @pest-mutate-ignore: RemoveIntegerCast, DecrementInteger
            static fn (array $match): string => \mb_chr((int) \hexdec($match[1]), 'UTF-8'),
            $text
        );
    }
}
