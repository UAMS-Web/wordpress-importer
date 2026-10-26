<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * The migration API's WXR materializer (issue wordpress-importer-archive#587).
 *
 * These are **round-trip** tests by design: materialize from a faked API payload, then read
 * the written files back with the real `XmlParser` and assert the values survive. Asserting
 * against the generated XML string would only prove the writer agrees with itself; parsing it
 * with the same class the importer uses is what proves a consumer can read it.
 *
 * The payload shapes come from `UAMS-Web/uamswp-migration-api`'s `Content/*Payload` classes —
 * `terms` grouped by taxonomy, `meta` mapping each key to a list of values.
 *
 * Parity with a real WXR export is wordpress-importer-archive#588's deliverable, not this file's: #589 captures the
 * fixtures that make it checkable and is blocked on wordpress-importer-archive#656. These payloads are shaped by the
 * documented contract, so they gate the machinery rather than the parity claim.
 *
 * @command  php vendor/bin/pest tests/Integration/WxrMaterializerTest.php
 */
uses(StatamicTestCase::class);

/**
 * Every URL the fake recorded, in order.
 *
 * Routing is the thing under test here, and a wildcard fake pattern matches every REST
 * root equally — so only the recorded URL can say which site was actually addressed.
 *
 * @return list<string>
 */
function sentUrls(): array
{
    $urls = [];

    Http::assertSent(function (Request $request) use (&$urls): bool {
        $urls[] = $request->url();

        return true;
    });

    return $urls;
}

/**
 * Load a written part as XML so assertions can see structure, not just substrings.
 *
 * Asserting with `str_contains` cannot tell an element inside its parent from one that
 * escaped it — deleting a `startElement()` leaves every child string present and merely
 * reparents it. Parsing is what catches that.
 */
function parsePart(string $path): SimpleXMLElement
{
    $xml = \simplexml_load_file($path);

    if ($xml === false) {
        throw new RuntimeException("The materialized part at {$path} is not parseable XML.");
    }

    return $xml;
}

beforeEach(function (): void {
    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('wxr_mat_', true);
    \mkdir($this->workingDirectory);
});

afterEach(function (): void {
    $entries = \glob($this->workingDirectory.\DIRECTORY_SEPARATOR.'*');

    foreach ($entries === false ? [] : $entries as $entry) {
        \unlink($entry);
    }

    \rmdir($this->workingDirectory);
});

/**
 * Fake the whole endpoint set a materialize() call touches.
 *
 * @param  list<array<string, mixed>>  $posts  Post payloads served from `posts/post`
 * @param  list<array<string, mixed>>  $terms  Term payloads served from `terms/category`
 * @param  list<array<string, mixed>>  $authors  Author payloads
 */
function fakeMigrationApi(
    array $posts = [],
    array $terms = [],
    array $authors = []
): void {
    Http::fake([
        // Two sites, so selecting by id is genuinely exercised rather than accidentally
        // satisfied by taking the first entry.
        '*/sites' => Http::response([
            [
                'id' => 1,
                'title' => 'UAMS News',
                'link' => 'https://news.uams.test',
                'description' => 'News from UAMS',
                'language' => 'en-US',
                'wxr_version' => '1.1',
                'base_site_url' => 'https://uams.test',
                'base_blog_url' => 'https://news.uams.test',
            ],
            [
                'id' => 4,
                'title' => 'UAMS Health',
                // A sub-path root, which is what a subdirectory multisite actually serves.
                'link' => 'https://example.test/health',
                'description' => 'Health at UAMS',
                'language' => 'en-US',
                'wxr_version' => '1.2',
                'base_site_url' => 'https://uams.test',
                'base_blog_url' => 'https://health.uams.test',
            ],
        ], 200),
        '*/post-types*' => Http::response([
            // The API's own allow-list decision — an unexposed type must never be requested.
            ['name' => 'revision', 'exposed' => false],
            // No `exposed` key at all: absent must read as "not exposed", never as exposed.
            ['name' => 'oembed_cache'],
            ['name' => 'post', 'exposed' => true],
        ], 200),
        '*/taxonomies*' => Http::response([
            // The unexposed entry comes first deliberately: with it last, skipping the rest
            // of the loop and skipping just this entry would look identical.
            ['name' => 'secret_tax', 'exposed' => false],
            ['name' => 'category', 'exposed' => true],
        ], 200),
        '*/terms/category*' => Http::response($terms, 200, ['X-WP-Total' => (string) \count($terms)]),
        '*/authors*' => Http::response($authors, 200, ['X-WP-Total' => (string) \count($authors)]),
        '*/posts/post*' => Http::response($posts, 200, ['X-WP-Total' => (string) \count($posts)]),
    ]);
}

/**
 * A materializer writing into this test's scratch directory.
 */
function makeMaterializer(string $directory, int $itemsPerPart = 2000): WxrMaterializer
{
    return new WxrMaterializer(
        new Client('https://example.test', 'shhh'),
        $directory,
        $itemsPerPart
    );
}

/**
 * A post payload with every field the materializer maps.
 *
 * @return array<string, mixed>
 */
function samplePost(int $id = 101, string $title = 'A Post'): array
{
    return [
        'id' => $id,
        'type' => 'post',
        'slug' => 'a-post',
        'status' => 'publish',
        'title' => $title,
        'author' => ['id' => 2, 'login' => 'todd', 'display_name' => 'Todd Example'],
        'date_created' => '2026-01-02 03:04:05',
        'date_created_gmt' => '2026-01-02 09:04:05',
        'date_modified' => '2026-02-03 04:05:06',
        'date_modified_gmt' => '2026-02-03 10:05:06',
        'content' => '<!-- wp:paragraph --><p>Body copy.</p><!-- /wp:paragraph -->',
        'excerpt' => 'A short excerpt.',
        'parent' => 0,
        'menu_order' => 3,
        'guid' => 'https://news.uams.test/?p='.$id,
        'link' => 'https://news.uams.test/a-post/',
        'is_sticky' => false,
        'ping_status' => 'closed',
        'comment_status' => 'open',
        'post_password' => '',
        'terms' => [
            'category' => [
                ['term_id' => 10, 'slug' => 'news', 'name' => 'News', 'parent' => 0, 'description' => ''],
            ],
        ],
        'meta' => [
            '_thumbnail_id' => ['4242'],
            'home_slides_5_home_slide_title' => ['Slide five'],
        ],
    ];
}

it('writes a part whose items the real parser reads back', function (): void {
    fakeMigrationApi([samplePost()]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    // Read it back with the same class the importer uses — not by inspecting the string.
    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $posts = $parser->getItems('post');

    expect($posts)->toHaveCount(1);

    $post = $posts[0];

    expect(XpathHelper::getStringValue($post, 'wp:post_id'))->toBe('101')
        ->and(XpathHelper::getStringValue($post, 'wp:post_type'))->toBe('post')
        ->and(XpathHelper::getStringValue($post, 'wp:status'))->toBe('publish')
        ->and(XpathHelper::getStringValue($post, 'wp:post_name'))->toBe('a-post')
        ->and(XpathHelper::getStringValue($post, 'wp:post_parent'))->toBe('0')
        ->and(XpathHelper::getStringValue($post, 'wp:menu_order'))->toBe('3')
        ->and(XpathHelper::getStringValue($post, 'wp:post_date'))->toBe('2026-01-02 03:04:05')
        ->and((string) $post->title)->toBe('A Post');
});

it('round-trips block-comment content without mangling it', function (): void {
    // Gutenberg block delimiters are comments inside CDATA; losing them turns a page
    // builder into a wall of raw HTML, which is silent and total.
    fakeMigrationApi([samplePost()]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $content = (string) $parser->getItems('post')[0]->children('content', true)->encoded;

    expect($content)->toBe('<!-- wp:paragraph --><p>Body copy.</p><!-- /wp:paragraph -->');
});

it('never returns an empty path list for a site with no items', function (): void {
    // `PostEntryCreator` opens with `if ($xmlFilePaths === []) { return []; }` — so an empty
    // list here would import zero posts and report success.
    fakeMigrationApi([]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    expect($paths)->toHaveCount(1)
        ->and(\basename($paths[0]))->toBe('wxr.000.xml')
        ->and(\file_exists($paths[0]))->toBeTrue();

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    // The channel still parses, and the base blog URL still resolves.
    expect($parser->getItems('post'))->toBe([]);
});

it('carries the channel metadata every consumer resolves links against', function (): void {
    fakeMigrationApi([samplePost()]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $wp = parsePart($paths[0])->channel->children('wp', true);

    expect((string) $wp->base_blog_url)->toBe('https://news.uams.test')
        ->and((string) $wp->base_site_url)->toBe('https://uams.test');
});

it('splits into ordered parts once a part is full', function (): void {
    fakeMigrationApi([samplePost(101), samplePost(102), samplePost(103)]);

    $paths = makeMaterializer($this->workingDirectory, 2)->materialize(1);

    expect($paths)->toHaveCount(2)
        ->and(\basename($paths[0]))->toBe('wxr.000.xml')
        ->and(\basename($paths[1]))->toBe('wxr.001.xml');

    // Every item survives the split, and the parser concatenates them in part order.
    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $ids = \array_map(
        fn ($post): string => XpathHelper::getStringValue($post, 'wp:post_id'),
        $parser->getItems('post')
    );

    expect($ids)->toBe(['101', '102', '103']);
});

it('repeats the channel metadata in every part', function (): void {
    // A later part missing the channel would make base-URL resolution depend on which part
    // a consumer happened to open.
    fakeMigrationApi([samplePost(101), samplePost(102)]);

    $paths = makeMaterializer($this->workingDirectory, 1)->materialize(1);

    foreach ($paths as $path) {
        expect((string) parsePart($path)->channel->children('wp', true)->base_blog_url)
            ->toBe('https://news.uams.test');
    }
});

it('writes one meta element per value, not per key', function (): void {
    $post = samplePost();
    // `get_post_meta()` without a key returns every row, so one key can carry several.
    $post['meta'] = ['gallery_ids' => ['11', '12', '13']];

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $item = $parser->getItems('post')[0];
    $values = [];

    foreach ($item->children('wp', true)->postmeta as $meta) {
        $values[] = (string) $meta->meta_key.'='.(string) $meta->meta_value;
    }

    // The key travels with each row; a value with no key cannot be mapped to anything.
    expect($values)->toBe(['gallery_ids=11', 'gallery_ids=12', 'gallery_ids=13']);
});

it('maps grouped terms onto category nodes carrying the taxonomy', function (): void {
    fakeMigrationApi([samplePost()]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $category = $parser->getItems('post')[0]->category[0];

    // The taxonomy travels in `domain` and the slug in `nicename` — a consumer reading a
    // category without its domain cannot tell `category` from `post_tag`.
    expect((string) $category->attributes()->domain)->toBe('category')
        ->and((string) $category->attributes()->nicename)->toBe('news')
        ->and((string) $category)->toBe('News');
});

it('writes the three legacy taxonomy forms the parser reads', function (): void {
    fakeMigrationApi(
        [samplePost()],
        [['id' => 10, 'slug' => 'news', 'name' => 'News', 'description' => 'News items', 'parent_slug' => '']]
    );

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    // `getCategories()` and `getTerms()` are distinct legacy WXR forms; both need backing.
    expect($parser->getCategories())->not->toBeEmpty()
        ->and($parser->getTerms())->not->toBeEmpty();
});

it('survives a CDATA terminator hidden in the content', function (): void {
    $post = samplePost();
    // An unescaped `]]>` closes the section early and silently truncates the rest.
    $post['content'] = 'before ]]> after';

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $content = (string) $parser->getItems('post')[0]->children('content', true)->encoded;

    expect($content)->toBe('before ]]> after');
});

it('strips characters XML cannot represent rather than writing a broken document', function (): void {
    $post = samplePost();
    $post['content'] = "clean\x08dirty";

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    // Read the WRITTEN BYTES, not the parsed document. `XmlParser` pipes through
    // `WxrSanitizedStream`, which strips C0 bytes on the way IN — so reading back through it
    // proved only that the read-side sanitizer works, and `WxrMaterializer::sanitize()`
    // could have been deleted with this test still green (wordpress-importer-archive#681).
    $written = \file_get_contents($paths[0]);

    expect($written)->toContain('cleandirty')
        ->and($written)->not->toContain("\x08");
});

it('serializes a structured meta value rather than dropping it', function (): void {
    $post = samplePost();
    // WordPress serializes arrays into the meta table; the API hands them back structured.
    $post['meta'] = ['layout' => [['columns' => 3]]];

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $value = (string) $parser->getItems('post')[0]->children('wp', true)->postmeta->meta_value;

    expect($value)->toBe('{"columns":3}');
});

it('never requests a post type the API declines to expose', function (): void {
    fakeMigrationApi([samplePost()]);

    makeMaterializer($this->workingDirectory)->materialize(1);

    Http::assertNotSent(fn (Request $request): bool => \str_contains($request->url(), 'posts/revision'));
});

it('plumbs modified_after through to the posts request', function (): void {
    fakeMigrationApi([samplePost()]);

    makeMaterializer($this->workingDirectory)->materialize(1, '2026-01-01T00:00:00Z');

    // Assert on the posts request specifically. An "or" over every request passes on the
    // `/sites` call no matter what `/posts/` carried — a green that proves nothing.
    $cutoffs = [];

    Http::assertSent(function (Request $request) use (&$cutoffs): bool {
        if (\str_contains($request->url(), '/posts/')) {
            \parse_str((string) \parse_url($request->url(), PHP_URL_QUERY), $parsed);
            $cutoffs[] = $parsed['modified_after'] ?? null;
        }

        return true;
    });

    expect($cutoffs)->toBe(['2026-01-01T00:00:00Z']);
});

it('addresses the requested site through its own REST root', function (): void {
    // Not `?site=`: the discovery endpoints refuse it, because registration is
    // process-global and can only be answered for the site that booted the request (wordpress-importer-archive#671).
    fakeMigrationApi([samplePost()]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(4);

    $afterDiscovery = \array_values(\array_filter(
        sentUrls(),
        fn (string $u): bool => ! \str_contains($u, '/sites')
    ));

    expect($afterDiscovery)->not->toBeEmpty();

    foreach ($afterDiscovery as $url) {
        expect($url)->toStartWith('https://example.test/health/wp-json/uamswp-migration/v1/')
            ->and($url)->not->toContain('site=');
    }

    // And the channel written is that site's, not the first the discovery call listed.
    expect((string) parsePart($paths[0])->channel->children('wp', true)->base_blog_url)
        ->toBe('https://health.uams.test');
});

it('reads from the configured root when no site is requested', function (): void {
    // The configured base URL already belongs to a site; re-pointing it would be wrong.
    fakeMigrationApi([samplePost()]);

    makeMaterializer($this->workingDirectory)->materialize();

    foreach (sentUrls() as $url) {
        expect($url)->toStartWith('https://example.test/wp-json/uamswp-migration/v1/')
            ->and($url)->not->toContain('/health/');
    }
});

it('never sends a site parameter to an endpoint that refuses one', function (): void {
    // `post-types` and `taxonomies` answer 400 when scoped. Sending it fails loudly, but
    // the feature would simply not work.
    fakeMigrationApi([samplePost()]);

    makeMaterializer($this->workingDirectory)->materialize(4);

    $scoped = \array_values(\array_filter(
        sentUrls(),
        fn (string $u): bool => (\str_contains($u, 'post-types') || \str_contains($u, 'taxonomies'))
            && \str_contains($u, 'site=')
    ));

    expect($scoped)->toBe([]);
});

it('raises when the API reports a site with no usable link', function (): void {
    Http::fake([
        '*/sites' => Http::response([['id' => 7, 'title' => 'No Link', 'link' => '']], 200),
    ]);

    // Falling back to `?site=` would fail later with a 400 about registration — a correct
    // error naming the wrong cause.
    expect(fn () => makeMaterializer($this->workingDirectory)->materialize(7))->toThrow(
        MigrationApiException::class,
        'The migration API reported site 7 without a usable "link"'
    );
});

it('surfaces the registration refusal with the API error code if it is ever sent', function (): void {
    // What a regression reintroducing `?site=` on a discovery endpoint would look like.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'link' => 'https://example.test', 'base_blog_url' => 'https://example.test',
        ]], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/taxonomies*' => Http::response([], 200),
        '*/post-types*' => Http::response([
            'code' => 'uamswp_migration_api_registration_is_not_site_scoped',
            'message' => 'Registered post types cannot be read for another site.',
        ], 400),
    ]);

    expect(fn () => makeMaterializer($this->workingDirectory)->materialize())
        ->toThrow(MigrationApiException::class, 'uamswp_migration_api_registration_is_not_site_scoped');
});

it('raises rather than writing an export with no channel metadata', function (): void {
    fakeMigrationApi([samplePost()]);

    expect(fn () => makeMaterializer($this->workingDirectory)->materialize(99))->toThrow(
        MigrationApiException::class,
        'The migration API serves no site with id 99. Refusing to materialize an export '
        .'with no channel metadata.'
    );
});

it('carries every mapped post field through the round trip', function (): void {
    // The mutation run showed most individual field writes were unasserted: each
    // `writeText`/`writeCdata` call could be deleted with every test still green. For a
    // mapping class that is the entire risk, so this asserts the whole surface at once.
    $post = samplePost();
    $post['is_sticky'] = true;
    $post['post_password'] = 'hunter2';

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $item = $parser->getItems('post')[0];
    $wp = $item->children('wp', true);

    expect((string) $item->title)->toBe('A Post')
        ->and((string) $item->link)->toBe('https://news.uams.test/a-post/')
        ->and((string) $item->guid)->toBe('https://news.uams.test/?p=101')
        ->and((string) $item->pubDate)->toBe('2026-01-02 09:04:05')
        // The author element is `dc:creator` and carries the login, because author
        // resolution matches on the login rather than the display name.
        ->and((string) $item->children('dc', true)->creator)->toBe('todd')
        ->and((string) $item->children('content', true)->encoded)
        ->toBe('<!-- wp:paragraph --><p>Body copy.</p><!-- /wp:paragraph -->')
        ->and((string) $item->children('excerpt', true)->encoded)->toBe('A short excerpt.')
        ->and((string) $wp->post_id)->toBe('101')
        ->and((string) $wp->post_date)->toBe('2026-01-02 03:04:05')
        ->and((string) $wp->post_date_gmt)->toBe('2026-01-02 09:04:05')
        ->and((string) $wp->post_modified)->toBe('2026-02-03 04:05:06')
        ->and((string) $wp->post_modified_gmt)->toBe('2026-02-03 10:05:06')
        ->and((string) $wp->comment_status)->toBe('open')
        ->and((string) $wp->ping_status)->toBe('closed')
        ->and((string) $wp->post_name)->toBe('a-post')
        ->and((string) $wp->status)->toBe('publish')
        ->and((string) $wp->post_parent)->toBe('0')
        ->and((string) $wp->menu_order)->toBe('3')
        ->and((string) $wp->post_type)->toBe('post')
        ->and((string) $wp->post_password)->toBe('hunter2')
        ->and((string) $wp->is_sticky)->toBe('1');
});

it('writes is_sticky as zero for a post that is not sticky', function (): void {
    // Absent and false must both mean 0 — a sticky flag that defaults the wrong way
    // silently reorders a front page.
    fakeMigrationApi([samplePost()]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    expect((string) $parser->getItems('post')[0]->children('wp', true)->is_sticky)->toBe('0');
});

it('carries an attachment url into its own element', function (): void {
    $attachment = samplePost(201, 'An Image');
    $attachment['type'] = 'attachment';
    $attachment['attachment'] = ['url' => 'https://news.uams.test/wp-content/uploads/x.jpg'];

    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'UAMS News', 'link' => 'https://news.uams.test',
            'description' => '', 'language' => 'en-US', 'wxr_version' => '1.2',
            'base_site_url' => 'https://uams.test', 'base_blog_url' => 'https://news.uams.test',
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'attachment', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/attachment*' => Http::response([$attachment], 200, ['X-WP-Total' => '1']),
    ]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    // The asset pipeline reads the URL from `wp:attachment_url`, not from the guid.
    $url = (string) $parser->getAttachments()[0]->children('wp', true)->attachment_url;

    expect($url)->toBe('https://news.uams.test/wp-content/uploads/x.jpg');
});

it('carries every mapped author field through the round trip', function (): void {
    fakeMigrationApi([samplePost()], [], [[
        'id' => 2,
        'login' => 'todd',
        'email' => 'todd@uams.test',
        'display_name' => 'Todd Example',
        'first_name' => 'Todd',
        'last_name' => 'Example',
    ]]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    // Read through `channel`, so an author that escaped its wrapper fails rather than
    // passing on the strings still being somewhere in the file.
    $author = parsePart($paths[0])->channel->children('wp', true)->author;

    expect((string) $author->author_id)->toBe('2')
        ->and((string) $author->author_login)->toBe('todd')
        ->and((string) $author->author_email)->toBe('todd@uams.test')
        ->and((string) $author->author_display_name)->toBe('Todd Example')
        ->and((string) $author->author_first_name)->toBe('Todd')
        ->and((string) $author->author_last_name)->toBe('Example');
});

it('carries every mapped channel field through the round trip', function (): void {
    fakeMigrationApi([samplePost()]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $channel = parsePart($paths[0])->channel;
    $wp = $channel->children('wp', true);

    expect((string) $channel->title)->toBe('UAMS News')
        ->and((string) $channel->link)->toBe('https://news.uams.test')
        ->and((string) $channel->description)->toBe('News from UAMS')
        ->and((string) $channel->language)->toBe('en-US')
        // A version distinct from the fallback, so serving the API's value and serving the
        // default are distinguishable.
        ->and((string) $wp->wxr_version)->toBe('1.1')
        ->and((string) $wp->base_site_url)->toBe('https://uams.test')
        ->and((string) $wp->base_blog_url)->toBe('https://news.uams.test');
});

it('carries every mapped term field into all three legacy forms', function (): void {
    fakeMigrationApi([samplePost()], [[
        'id' => 10,
        'slug' => 'news',
        'name' => 'News',
        'description' => 'News items',
        'parent_slug' => 'root',
        'meta' => ['color' => ['red']],
    ]]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $wp = parsePart($paths[0])->channel->children('wp', true);

    // The legacy `wp:category` form, asserted inside its own element.
    expect((string) $wp->category->term_id)->toBe('10')
        ->and((string) $wp->category->category_nicename)->toBe('news')
        ->and((string) $wp->category->category_parent)->toBe('root')
        ->and((string) $wp->category->cat_name)->toBe('News')
        ->and((string) $wp->category->category_description)->toBe('News items')
        // And the generic `wp:term` form, which carries the taxonomy and the term meta.
        ->and((string) $wp->term->term_id)->toBe('10')
        ->and((string) $wp->term->term_taxonomy)->toBe('category')
        ->and((string) $wp->term->term_slug)->toBe('news')
        ->and((string) $wp->term->term_parent)->toBe('root')
        ->and((string) $wp->term->term_name)->toBe('News')
        ->and((string) $wp->term->term_description)->toBe('News items')
        ->and((string) $wp->term->termmeta->meta_key)->toBe('color')
        ->and((string) $wp->term->termmeta->meta_value)->toBe('red');
});

it('writes the legacy tag form for post_tag', function (): void {
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'UAMS News', 'link' => 'https://news.uams.test',
            'description' => '', 'language' => 'en-US', 'wxr_version' => '1.2',
            'base_site_url' => 'https://uams.test', 'base_blog_url' => 'https://news.uams.test',
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([['name' => 'post_tag', 'exposed' => true]], 200),
        '*/terms/post_tag*' => Http::response(
            [['id' => 20, 'slug' => 'flu', 'name' => 'Flu', 'description' => 'Influenza']],
            200,
            ['X-WP-Total' => '1']
        ),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([], 200, ['X-WP-Total' => '0']),
    ]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $wp = parsePart($paths[0])->channel->children('wp', true);

    expect((string) $wp->tag->term_id)->toBe('20')
        ->and((string) $wp->tag->tag_slug)->toBe('flu')
        ->and((string) $wp->tag->tag_name)->toBe('Flu')
        ->and((string) $wp->tag->tag_description)->toBe('Influenza')
        // A tag is never written as a category.
        ->and($wp->category->count())->toBe(0);
});

it('reads terms from a taxonomy listed after an unexposed one', function (): void {
    // Skipping the unexposed entry must skip that entry, not abandon the loop.
    fakeMigrationApi([samplePost()], [
        ['id' => 10, 'slug' => 'news', 'name' => 'News', 'description' => '', 'parent_slug' => ''],
    ]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    expect((string) parsePart($paths[0])->channel->children('wp', true)->term->term_taxonomy)
        ->toBe('category');
});

it('never requests a post type whose exposure the API did not state', function (): void {
    fakeMigrationApi([samplePost()]);

    makeMaterializer($this->workingDirectory)->materialize(1);

    // An absent `exposed` key must not be read as permission.
    Http::assertNotSent(fn (Request $request): bool => \str_contains($request->url(), 'posts/oembed_cache'));
});

it('omits modified_after entirely when no cutoff is given', function (): void {
    fakeMigrationApi([samplePost()]);

    makeMaterializer($this->workingDirectory)->materialize(1);

    Http::assertNotSent(fn (Request $request): bool => \str_contains($request->url(), 'modified_after'));
});

it('rotates exactly at the configured part size, not one item early or late', function (): void {
    fakeMigrationApi([samplePost(101), samplePost(102)]);

    // Two items at two per part is exactly one part — an off-by-one either way splits it.
    $paths = makeMaterializer($this->workingDirectory, 2)->materialize(1);

    expect($paths)->toHaveCount(1);
});

it('does not double the separator when the output directory ends in one', function (): void {
    fakeMigrationApi([samplePost()]);

    $paths = makeMaterializer($this->workingDirectory.\DIRECTORY_SEPARATOR)->materialize(1);

    expect($paths[0])->toBe(
        $this->workingDirectory.\DIRECTORY_SEPARATOR.'wxr.000.xml'
    )->and(\file_exists($paths[0]))->toBeTrue();
});

it('skips an element entirely rather than writing it empty', function (): void {
    $post = samplePost();
    $post['excerpt'] = '';
    $post['post_password'] = '';

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);
    $item = parsePart($paths[0])->channel->item;

    // An empty `wp:post_password` element is not the same as an absent one: consumers read
    // its presence as "this entry is password protected".
    expect($item->children('wp', true)->post_password->count())->toBe(0)
        ->and($item->children('excerpt', true)->encoded->count())->toBe(0);
});

it('matches a site whose id the API sent as a numeric string', function (): void {
    Http::fake([
        // WordPress hands ids back as strings often enough that a strict === would miss.
        '*/sites' => Http::response([[
            'id' => '4', 'title' => 'UAMS Health', 'link' => 'https://health.uams.test',
            'description' => '', 'language' => 'en-US', 'wxr_version' => '1.2',
            'base_site_url' => 'https://uams.test', 'base_blog_url' => 'https://health.uams.test',
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([], 200, ['X-WP-Total' => '0']),
    ]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(4);

    expect((string) parsePart($paths[0])->channel->children('wp', true)->base_blog_url)
        ->toBe('https://health.uams.test');
});

it('never reads a taxonomy whose exposure the API did not state', function (): void {
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'UAMS News', 'link' => 'https://news.uams.test',
            'description' => '', 'language' => 'en-US', 'wxr_version' => '1.2',
            'base_site_url' => 'https://uams.test', 'base_blog_url' => 'https://news.uams.test',
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        // No `exposed` key: absent must read as "not exposed", exactly as for post types.
        '*/taxonomies*' => Http::response([['name' => 'private_tax']], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([], 200, ['X-WP-Total' => '0']),
    ]);

    makeMaterializer($this->workingDirectory)->materialize(1);

    Http::assertNotSent(fn (Request $request): bool => \str_contains($request->url(), 'terms/private_tax'));
});

it('falls back to a WXR version when the API omits one', function (): void {
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'UAMS News', 'link' => 'https://news.uams.test',
            'description' => '', 'language' => 'en-US',
            // No `wxr_version` — consumers branch on it, so an absent one must not
            // produce a document that declares no version at all.
            'base_site_url' => 'https://uams.test', 'base_blog_url' => 'https://news.uams.test',
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([], 200, ['X-WP-Total' => '0']),
    ]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    expect((string) parsePart($paths[0])->channel->children('wp', true)->wxr_version)->toBe('1.2');
});

it('treats an absent is_sticky key as not sticky', function (): void {
    $post = samplePost();
    unset($post['is_sticky']);

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    expect((string) $parser->getItems('post')[0]->children('wp', true)->is_sticky)->toBe('0');
});

it('omits the attachment url when the payload carries no usable one', function (): void {
    $post = samplePost();
    // The key exists but holds no URL — writing an empty element would send the asset
    // pipeline after nothing.
    $post['attachment'] = ['url' => null, 'mime_type' => 'image/jpeg'];

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    expect(parsePart($paths[0])->channel->item->children('wp', true)->attachment_url->count())
        ->toBe(0);
});

it('skips a malformed term group rather than writing it', function (): void {
    $post = samplePost();
    // A taxonomy whose rows are not a list is not something to guess at.
    $post['terms'] = ['category' => 'not-a-list'];

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    expect($parser->getItems('post')[0]->category->count())->toBe(0);
});

it('accepts a meta value the API sent bare rather than as a list', function (): void {
    $post = samplePost();
    $post['meta'] = ['single_value' => 'just-a-string'];

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $meta = $parser->getItems('post')[0]->children('wp', true)->postmeta;

    expect((string) $meta->meta_key)->toBe('single_value')
        ->and((string) $meta->meta_value)->toBe('just-a-string');
});

it('omits a plain-text element whose value is an empty string', function (): void {
    $post = samplePost();
    $post['link'] = '';
    $post['guid'] = '';

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $item = $parser->getItems('post')[0];

    expect($item->link->count())->toBe(0)
        ->and($item->guid->count())->toBe(0);
});

it('renders a numeric meta value rather than dropping it', function (): void {
    $post = samplePost();
    // 4.5 rather than 4.0 deliberately. `json_encode(4.0)` emits `4`, which decodes back
    // as an int — so a whole-number float cannot reach `scalarToString()`'s float branch
    // through this path at all, and a test using one measures the int branch while claiming
    // to measure the float one. That was wordpress-importer-archive#681.
    $post['meta'] = ['rating' => [4.5], 'position' => [7]];

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $values = [];

    foreach ($parser->getItems('post')[0]->children('wp', true)->postmeta as $meta) {
        $values[] = (string) $meta->meta_key.'='.(string) $meta->meta_value;
    }

    expect($values)->toBe(['rating=4.5', 'position=7']);
});

it('cannot receive a string that is not valid UTF-8', function (): void {
    // This is why the materializer carries no byte-level repair path. `Client` decodes
    // JSON, and `json_decode` rejects invalid UTF-8 outright — so a malformed byte never
    // reaches the writer. Faking one is impossible for the same reason: the fake's own
    // encoder refuses it, which is the proof rather than a limitation of the test.
    expect(fn () => Http::response(['content' => "bad \xB1\x31"], 200))
        ->toThrow(InvalidArgumentException::class);
});

it('ignores an attachment payload that is not a structure at all', function (): void {
    $post = samplePost();
    // Indexing a string with 'url' would not be a missing element, it would be a crash.
    $post['attachment'] = 'https://news.uams.test/x.jpg';

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    expect(parsePart($paths[0])->channel->item->children('wp', true)->attachment_url->count())
        ->toBe(0);
});

it('keeps reading taxonomies after skipping a malformed one', function (): void {
    $post = samplePost();
    // The malformed group comes first: skipping it must skip that group, not abandon the
    // rest of the post's terms.
    $post['terms'] = [
        'broken_tax' => 'not-a-list',
        'category' => [
            ['term_id' => 10, 'slug' => 'news', 'name' => 'News', 'parent' => 0, 'description' => ''],
        ],
    ];

    fakeMigrationApi([$post]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $categories = parsePart($paths[0])->channel->item->category;

    expect($categories->count())->toBe(1)
        ->and((string) $categories[0]->attributes()->domain)->toBe('category');
});

it('closes every element so siblings stay siblings', function (): void {
    // An unclosed element does not corrupt the file — it silently *reparents* everything
    // after it. With one author and no terms nothing notices; with authors, two terms and
    // two items in one document, a missing `endElement()` moves them out of `channel` and
    // these counts collapse.
    fakeMigrationApi(
        [samplePost(101), samplePost(102)],
        [
            ['id' => 10, 'slug' => 'news', 'name' => 'News', 'description' => '', 'parent_slug' => ''],
            ['id' => 11, 'slug' => 'research', 'name' => 'Research', 'description' => '', 'parent_slug' => ''],
        ],
        [
            ['id' => 2, 'login' => 'todd', 'email' => 't@uams.test', 'display_name' => 'Todd'],
            ['id' => 3, 'login' => 'ana', 'email' => 'a@uams.test', 'display_name' => 'Ana'],
        ]
    );

    $channel = parsePart(makeMaterializer($this->workingDirectory)->materialize(1)[0])->channel;
    $wp = $channel->children('wp', true);

    expect($wp->author->count())->toBe(2)
        ->and($wp->category->count())->toBe(2)
        ->and($wp->term->count())->toBe(2)
        // Both items are direct children of channel, not one nested inside the other.
        ->and($channel->item->count())->toBe(2);

    // And each element still carries its own values rather than its neighbour's.
    expect((string) $wp->author[1]->author_login)->toBe('ana')
        ->and((string) $wp->category[1]->category_nicename)->toBe('research')
        ->and((string) $channel->item[1]->children('wp', true)->post_id)->toBe('102');
});

it('keeps tags as siblings when a taxonomy has several', function (): void {
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'UAMS News', 'link' => 'https://news.uams.test',
            'description' => '', 'language' => 'en-US', 'wxr_version' => '1.2',
            'base_site_url' => 'https://uams.test', 'base_blog_url' => 'https://news.uams.test',
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([['name' => 'post_tag', 'exposed' => true]], 200),
        '*/terms/post_tag*' => Http::response([
            ['id' => 20, 'slug' => 'flu', 'name' => 'Flu', 'description' => ''],
            ['id' => 21, 'slug' => 'heart', 'name' => 'Heart', 'description' => ''],
        ], 200, ['X-WP-Total' => '2']),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([], 200, ['X-WP-Total' => '0']),
    ]);

    $wp = parsePart(makeMaterializer($this->workingDirectory)->materialize(1)[0])
        ->channel->children('wp', true);

    expect($wp->tag->count())->toBe(2)
        ->and((string) $wp->tag[1]->tag_slug)->toBe('heart');
});

it('refuses an output directory that does not exist', function (): void {
    // Without this the relative path resolves against the working directory, and the parts
    // land wherever the process happens to be — which is how two of them reached `main`.
    fakeMigrationApi([samplePost()]);

    expect(fn () => makeMaterializer('')->materialize(1))->toThrow(
        MigrationApiException::class,
        'The output directory "" does not exist. Refusing to scatter materialized parts '
        .'into the working directory.'
    );
});

it('refuses a directory that is a path but not a directory', function (): void {
    fakeMigrationApi([samplePost()]);

    $notADirectory = $this->workingDirectory.\DIRECTORY_SEPARATOR.'a-file';
    \file_put_contents($notADirectory, 'x');

    expect(fn () => makeMaterializer($notADirectory)->materialize(1))
        ->toThrow(MigrationApiException::class, 'does not exist');
});

it('normalises a site link that arrives with a trailing slash', function (): void {
    // WordPress reports a site's URL with or without one depending on how it was saved;
    // a double slash is a different route and 404s.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 9,
            'title' => 'Trailing',
            'link' => 'https://example.test/trailing/',
            'base_blog_url' => 'https://example.test/trailing',
            'wxr_version' => '1.2',
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([], 200, ['X-WP-Total' => '0']),
    ]);

    makeMaterializer($this->workingDirectory)->materialize(9);

    foreach (sentUrls() as $url) {
        expect($url)->not->toContain('//wp-json')
            ->and($url)->not->toContain('trailing//');
    }

    // And it did address the right root rather than skipping the override.
    $roots = \array_values(\array_filter(
        sentUrls(),
        fn (string $u): bool => \str_contains($u, '/trailing/wp-json/')
    ));

    expect($roots)->not->toBeEmpty();
});

it('refuses to carry the secret to a site root that downgrades the scheme', function (): void {
    // The credential travels to whatever `/sites` reports. An `http://` root would put
    // `X-UAMSWP-Migration-Key` on the wire in cleartext, and the operator — who configured
    // `https://` — would have no view of it having happened.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 5,
            'title' => 'Downgraded',
            'link' => 'http://example.test/plain',
            'base_blog_url' => 'http://example.test/plain',
        ]], 200),
    ]);

    expect(fn () => makeMaterializer($this->workingDirectory)->materialize(5))->toThrow(
        MigrationApiException::class,
        'which is "http" rather than "https" as configured'
    );

    // And nothing was sent to it — the refusal precedes the request, not follows it.
    Http::assertNotSent(fn (Request $request): bool => \str_starts_with($request->url(), 'http://'));
});

it('allows a site root on a different domain, because multisite maps domains', function (): void {
    // Pinning the host would break the very case this addressing exists to serve.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 6,
            'title' => 'Mapped',
            'link' => 'https://mapped-domain.test',
            'base_blog_url' => 'https://mapped-domain.test',
            'wxr_version' => '1.2',
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([], 200, ['X-WP-Total' => '0']),
    ]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(6);

    expect($paths)->toHaveCount(1);

    $mapped = \array_values(\array_filter(
        sentUrls(),
        fn (string $u): bool => \str_starts_with($u, 'https://mapped-domain.test/wp-json/')
    ));

    expect($mapped)->not->toBeEmpty();
});

it('refuses a site root that is not an absolute URL', function (): void {
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 8, 'title' => 'Relative', 'link' => '/just-a-path',
        ]], 200),
    ]);

    expect(fn () => makeMaterializer($this->workingDirectory)->materialize(8))
        ->toThrow(MigrationApiException::class, 'not an absolute URL');
});

it('refuses a site root with no host', function (): void {
    Http::fake([
        '*/sites' => Http::response([[
            // Verified with parse_url: this yields scheme "https" with a null host, which
            // is the shape that reaches the host check. `https:///x` fails scheme parsing
            // first and is covered by the test above.
            'id' => 11, 'title' => 'Hostless', 'link' => 'https:/no-host',
        ]], 200),
    ]);

    expect(fn () => makeMaterializer($this->workingDirectory)->materialize(11))
        ->toThrow(MigrationApiException::class, 'has no host');
});

it('materializes every exposed post type and taxonomy, not just the first', function (): void {
    // wordpress-importer-archive#681: every other fixture in this file exposes exactly one post type and at most one
    // taxonomy, so truncating either enumeration after the first entry was invisible. Since
    // `exposedPostTypes()` is an allow-list filter, "reads only `post` and silently drops
    // the rest" is precisely the regression worth guarding — and it is this project's
    // defining shape: fewer records, no error.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'Multi', 'link' => 'https://example.test',
            'base_blog_url' => 'https://example.test', 'wxr_version' => '1.2',
        ]], 200),
        '*/post-types*' => Http::response([
            ['name' => 'post', 'exposed' => true],
            ['name' => 'page', 'exposed' => true],
            ['name' => 'kb', 'exposed' => true],
            ['name' => 'revision', 'exposed' => false],
        ], 200),
        '*/taxonomies*' => Http::response([
            ['name' => 'category', 'exposed' => true],
            ['name' => 'post_tag', 'exposed' => true],
            ['name' => 'secret_tax', 'exposed' => false],
        ], 200),
        '*/terms/category*' => Http::response(
            [['id' => 10, 'slug' => 'news', 'name' => 'News', 'description' => '', 'parent_slug' => '']],
            200,
            ['X-WP-Total' => '1']
        ),
        '*/terms/post_tag*' => Http::response(
            [['id' => 20, 'slug' => 'flu', 'name' => 'Flu', 'description' => '']],
            200,
            ['X-WP-Total' => '1']
        ),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([samplePost(101, 'A Post')], 200, ['X-WP-Total' => '1']),
        '*/posts/page*' => Http::response(
            [['id' => 201, 'type' => 'page', 'slug' => 'a-page', 'status' => 'publish', 'title' => 'A Page']],
            200,
            ['X-WP-Total' => '1']
        ),
        '*/posts/kb*' => Http::response(
            [['id' => 301, 'type' => 'kb', 'slug' => 'an-article', 'status' => 'publish', 'title' => 'An Article']],
            200,
            ['X-WP-Total' => '1']
        ),
    ]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    // All three exposed types arrive, and the unexposed one does not.
    expect(\array_map(
        fn ($i): string => XpathHelper::getStringValue($i, 'wp:post_id'),
        $parser->getItems('post')
    ))->toBe(['101'])
        ->and(\array_map(
            fn ($i): string => XpathHelper::getStringValue($i, 'wp:post_id'),
            $parser->getItems('page')
        ))->toBe(['201'])
        ->and(\array_map(
            fn ($i): string => XpathHelper::getStringValue($i, 'wp:post_id'),
            $parser->getItems('kb')
        ))->toBe(['301'])
        ->and($parser->getItems('revision'))->toBe([]);

    // And both exposed taxonomies, not just the first.
    $wp = parsePart($paths[0])->channel->children('wp', true);

    $taxonomies = [];

    foreach ($wp->term as $term) {
        $taxonomies[] = (string) $term->term_taxonomy;
    }

    expect(\array_unique($taxonomies))->toEqualCanonicalizing(['category', 'post_tag']);
});

it('reports a post type that has records but is not served', function (): void {
    // wordpress-importer-archive#684 measured this on a live site: 28 `kb` records existed and `kb` was unexposed,
    // so a migration omitted every one with no error on either side.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'Multi', 'link' => 'https://example.test',
            'base_blog_url' => 'https://example.test', 'wxr_version' => '1.2',
            'counts' => ['by_post_type' => ['post' => 46, 'kb' => 28, 'uamspeople' => 6]],
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([samplePost()], 200, ['X-WP-Total' => '1']),
    ]);

    $materializer = makeMaterializer($this->workingDirectory);
    $materializer->materialize(1);

    expect($materializer->unservedContent())->toEqualCanonicalizing([
        ['kind' => 'post type', 'name' => 'kb', 'records' => 28],
        ['kind' => 'post type', 'name' => 'uamspeople', 'records' => 6],
    ]);
});

it('reports a taxonomy that has terms but is not served', function (): void {
    // Taxonomies come from a different source: `counts` carries no per-taxonomy breakdown,
    // so the comparison uses the `terms` count that `/taxonomies` reports per entry.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'T', 'link' => 'https://example.test',
            'base_blog_url' => 'https://example.test', 'wxr_version' => '1.2',
            'counts' => ['by_post_type' => ['post' => 1]],
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([
            ['name' => 'category', 'exposed' => true, 'terms' => 4],
            ['name' => 'academic_department', 'exposed' => false, 'terms' => 17],
        ], 200),
        '*/terms/category*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([samplePost()], 200, ['X-WP-Total' => '1']),
    ]);

    $materializer = makeMaterializer($this->workingDirectory);
    $materializer->materialize(1);

    expect($materializer->unservedContent())->toBe([
        ['kind' => 'taxonomy', 'name' => 'academic_department', 'records' => 17],
    ]);
});

it('reports options the endpoint serves that the run did not read', function (): void {
    // WXR carries no `<wp:option>` element at all, so a run that drops site settings looks
    // exactly like one that carried them. This is the entry that makes the difference visible.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'T', 'link' => 'https://example.test',
            'base_blog_url' => 'https://example.test', 'wxr_version' => '1.2',
            'counts' => ['by_post_type' => ['post' => 1], 'options' => 6],
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/options*' => Http::response([
            'options' => ['blogname' => 'T', 'siteurl' => 'https://example.test'],
            // Reported as totals only - the endpoint never says WHICH rows it withheld.
            'denied' => ['by_name' => 3, 'by_shape' => 1],
        ], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([samplePost()], 200, ['X-WP-Total' => '1']),
    ]);

    $materializer = makeMaterializer($this->workingDirectory);
    $materializer->materialize(1);

    // Two entries, not one summed number: a row nobody read and a row the endpoint refused
    // have different fixes, and the payload cannot reconcile them.
    expect($materializer->unservedContent())->toEqualCanonicalizing([
        ['kind' => 'option', 'name' => 'not read by this run', 'records' => 2],
        ['kind' => 'option', 'name' => 'withheld by the endpoint denylist', 'records' => 4],
    ]);
});

it('says nothing about options when every served row was read by this run', function (): void {
    // The criterion this covers is "every served option IS read", which is not the same as
    // "nothing was served" - an empty payload would pass for the wrong reason and leave the
    // consumed-name comparison unexercised. So the endpoint serves two rows here and both are
    // declared consumed.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'T', 'link' => 'https://example.test',
            'base_blog_url' => 'https://example.test', 'wxr_version' => '1.2',
            'counts' => ['by_post_type' => ['post' => 1], 'options' => 2],
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/options*' => Http::response([
            'options' => ['blogname' => 'T', 'siteurl' => 'https://example.test'],
            'denied' => ['by_name' => 0, 'by_shape' => 0],
        ], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([samplePost()], 200, ['X-WP-Total' => '1']),
    ]);

    $materializer = makeMaterializer($this->workingDirectory);
    $materializer->materialize(1, null, ['blogname', 'siteurl']);

    expect($materializer->unservedContent())->toBe([]);
});

it('says nothing about an unserved type that has no records', function (): void {
    // An unused type is not a problem, and reporting it would train people to ignore the
    // message — which is the one thing this must not do.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'Q', 'link' => 'https://example.test',
            'base_blog_url' => 'https://example.test', 'wxr_version' => '1.2',
            'counts' => ['by_post_type' => ['post' => 1, 'oembed_cache' => 0]],
        ]], 200),
        '*/post-types*' => Http::response([['name' => 'post', 'exposed' => true]], 200),
        '*/taxonomies*' => Http::response([['name' => 'unused_tax', 'exposed' => false, 'terms' => 0]], 200),
        '*/authors*' => Http::response([], 200, ['X-WP-Total' => '0']),
        '*/posts/post*' => Http::response([samplePost()], 200, ['X-WP-Total' => '1']),
    ]);

    $materializer = makeMaterializer($this->workingDirectory);
    $materializer->materialize(1);

    expect($materializer->unservedContent())->toBe([]);
});

it('says nothing when everything with records is served', function (): void {
    fakeMigrationApi([samplePost()]);

    $materializer = makeMaterializer($this->workingDirectory);
    $materializer->materialize(1);

    expect($materializer->unservedContent())->toBe([]);
});

/**
 * Read the manifest a run wrote into this test's scratch directory.
 *
 * @return array<string, mixed>
 */
function readManifest(string $directory): array
{
    $path = $directory.\DIRECTORY_SEPARATOR.'wxr.manifest.json';

    expect(\file_exists($path))->toBeTrue();

    /** @var array<string, mixed> $decoded */
    $decoded = \json_decode((string) \file_get_contents($path), true);

    return $decoded;
}

it('marks a completed materialization complete, without reading any WXR content', function (): void {
    fakeMigrationApi([samplePost(101), samplePost(102)]);

    $paths = makeMaterializer($this->workingDirectory)->materialize(1);

    $manifest = readManifest($this->workingDirectory);

    // The whole point is that completeness is readable WITHOUT parsing a part.
    expect($manifest['status'])->toBe('complete')
        ->and($manifest['items'])->toBe(2)
        ->and($manifest['parts'])->toBe(['wxr.000.xml'])
        ->and($manifest['parts'])->toHaveCount(\count($paths));
});

it('leaves the manifest in progress when a run is interrupted partway through', function (): void {
    // Everything up to the posts request succeeds, so real parts get written; the posts
    // request then fails, standing in for the run dying partway through. The distinguishing
    // record has to survive that, which is the whole claim.
    fakeMigrationApi([samplePost(101)]);

    Http::fake([
        '*/posts/post*' => function (): void {
            throw new ConnectionException('Interrupted partway through the posts read.');
        },
    ]);

    $materializer = makeMaterializer($this->workingDirectory);

    expect(fn (): array => $materializer->materialize(1))->toThrow(Exception::class);

    $manifest = readManifest($this->workingDirectory);

    // `in_progress` is what an interrupted run leaves behind. A reader diffing this
    // directory against a real export can see the artifact does not claim to be finished.
    expect($manifest['status'])->toBe('in_progress')
        // Null, not zero or an empty list: parts are written after this file and never
        // counted back into it, so an empty list would claim nothing was written when the
        // directory may already hold thousands.
        ->and($manifest['items'])->toBeNull()
        ->and($manifest['parts'])->toBeNull();
});

it('records the scope a run was given, so a deliberately partial artifact is legible', function (): void {
    fakeMigrationApi([samplePost()]);

    makeMaterializer($this->workingDirectory)->materialize(4, '2026-01-01T00:00:00');

    $manifest = readManifest($this->workingDirectory);

    /** @var array<string, mixed> $scope */
    $scope = $manifest['scope'];

    // A scoped run writes fewer items on purpose. Without the scope recorded, that is
    // indistinguishable from a truncated one.
    expect($scope['site'])->toBe(4)
        ->and($scope['modified_after'])->toBe('2026-01-01T00:00:00');
});

it('names the parts a shorter second run left behind rather than silently keeping them', function (): void {
    // Two genuine runs, with the second serving fewer posts than the first. The post list
    // has to be mutable rather than re-faked: `Http::fake()` ADDS stubs, so a second call
    // with the same pattern never wins and both runs would read the first list.
    $posts = [samplePost(101), samplePost(102), samplePost(103)];

    // Registered BEFORE the shared fake, deliberately: `Http::fake()` adds stubs and the
    // EARLIEST matching one wins, so this has to be first or `fakeMigrationApi()`'s own
    // posts stub answers both runs.
    Http::fake([
        '*/posts/post*' => function () use (&$posts) {
            return Http::response($posts, 200, ['X-WP-Total' => (string) \count($posts)]);
        },
    ]);

    fakeMigrationApi();

    // First run: three items at one per part, so parts 000, 001 and 002 exist.
    makeMaterializer($this->workingDirectory, 1)->materialize(1);

    expect(readManifest($this->workingDirectory)['parts'])->toHaveCount(3);

    // Second run writes only one part, so 001 and 002 survive from the first run looking
    // exactly like current output.
    $posts = [samplePost(201)];
    makeMaterializer($this->workingDirectory, 1)->materialize(1);

    $manifest = readManifest($this->workingDirectory);

    expect($manifest['parts'])->toBe(['wxr.000.xml'])
        ->and($manifest['stale_parts'])->toBe(['wxr.001.xml', 'wxr.002.xml'])
        ->and(\file_exists($this->workingDirectory.\DIRECTORY_SEPARATOR.'wxr.002.xml'))->toBeTrue();
});
