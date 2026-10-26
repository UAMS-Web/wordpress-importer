<?php

declare(strict_types=1);

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * `--content-type` narrows the migration API read, not only the import (wordpress-importer-archive#1089).
 *
 * Measured on a live network before this change: about 16,000 records read to import 25,
 * because `materialize()` fetched every exposed type and every taxonomy regardless of what
 * the run was asked to import.
 *
 * **These tests count requests rather than time them**, as the ticket requires. A timing
 * would say nothing about which endpoints were touched, and "which endpoints" is the whole
 * claim: a scoped run that still fetched `posts/provider` could be fast on a small fixture
 * and wrong everywhere.
 *
 * **The scoped read is a dependency closure.** A run for one type still reads the types its
 * import depends on — attachments, reusable blocks, menu items, TablePress tables, and pages
 * for a knowledge base — because each of those is looked up out of the export by another
 * type and skipped silently when absent. Several cases below exist to pin that half, since
 * a narrower read that dropped a companion would pass every "fewer requests" assertion.
 *
 * @command  php vendor/bin/pest tests/Integration/WxrMaterializerScopedReadTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    // The scope is process-global, so every test starts from a whole-site read.
    MaterializationScope::clear();

    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('wxr_scope_', true);
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
 * One paginated response carrying the given records.
 *
 * @param  list<array<string, mixed>>  $records  The records to serve
 */
function scopedPage(array $records): PromiseInterface
{
    return Http::response($records, 200, ['X-WP-Total' => (string) \count($records)]);
}

/**
 * A minimal post payload of a given type.
 *
 * @param  int  $id  The post id
 * @param  string  $type  The post type
 * @param  array<string, list<string>>  $meta  Meta rows, each key mapping to a list
 * @return array<string, mixed>
 */
function scopedPost(
    int $id,
    string $type,
    array $meta = []
): array {
    return [
        'id' => $id,
        'type' => $type,
        'slug' => $type.'-'.$id,
        'status' => 'publish',
        'title' => \ucfirst($type).' '.$id,
        'meta' => $meta,
    ];
}

/**
 * Fake a site shaped like the measured one: the requested type is a sliver of it.
 *
 * `provider` and `condition` stand in for the bulk the measured run read and did not need.
 * Every companion type is exposed, so a test asserting one is read cannot pass because the
 * fixture simply lacked it.
 */
function fakeScopedSite(): void
{
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'Scoped', 'link' => 'https://example.test',
            'base_blog_url' => 'https://example.test', 'wxr_version' => '1.2',
        ]], 200),
        '*/post-types*' => Http::response([
            ['name' => 'post', 'exposed' => true],
            ['name' => 'page', 'exposed' => true],
            ['name' => 'kb', 'exposed' => true],
            ['name' => 'provider', 'exposed' => true],
            ['name' => 'condition', 'exposed' => true],
            ['name' => 'uamspeople', 'exposed' => true],
            ['name' => 'attachment', 'exposed' => true],
            ['name' => 'wp_block', 'exposed' => true],
            ['name' => 'nav_menu_item', 'exposed' => true],
            ['name' => 'tablepress_table', 'exposed' => true],
            ['name' => 'revision', 'exposed' => false],
        ], 200),
        '*/taxonomies*' => Http::response([
            ['name' => 'category', 'exposed' => true, 'object_types' => ['post']],
            ['name' => 'school', 'exposed' => true, 'object_types' => ['provider']],
            ['name' => 'nav_menu', 'exposed' => true, 'object_types' => ['nav_menu_item']],
            ['name' => 'kb_topic', 'exposed' => true, 'object_types' => ['kb']],
            // No `object_types` at all: nothing proves it unrelated, so it must be read.
            ['name' => 'undeclared', 'exposed' => true],
        ], 200),
        '*/terms/category*' => scopedPage([['id' => 10, 'slug' => 'news', 'name' => 'News']]),
        '*/terms/school*' => scopedPage([['id' => 20, 'slug' => 'medicine', 'name' => 'Medicine']]),
        '*/terms/nav_menu*' => scopedPage([['id' => 30, 'slug' => 'main', 'name' => 'Main']]),
        '*/terms/kb_topic*' => scopedPage([['id' => 40, 'slug' => 'billing', 'name' => 'Billing']]),
        '*/terms/undeclared*' => scopedPage([['id' => 50, 'slug' => 'loose', 'name' => 'Loose']]),
        '*/authors*' => scopedPage([]),
        '*/posts/post*' => scopedPage([scopedPost(101, 'post', ['_thumbnail_id' => ['900']])]),
        '*/posts/page*' => scopedPage([scopedPost(201, 'page')]),
        '*/posts/kb*' => scopedPage([scopedPost(301, 'kb')]),
        '*/posts/provider*' => scopedPage([scopedPost(401, 'provider')]),
        '*/posts/condition*' => scopedPage([scopedPost(501, 'condition')]),
        '*/posts/uamspeople*' => scopedPage([scopedPost(551, 'uamspeople')]),
        '*/posts/attachment*' => scopedPage([
            \array_merge(scopedPost(900, 'attachment'), [
                'attachment' => ['url' => 'https://example.test/wp-content/uploads/photo.jpg'],
            ]),
        ]),
        '*/posts/wp_block*' => scopedPage([scopedPost(600, 'wp_block')]),
        '*/posts/nav_menu_item*' => scopedPage([scopedPost(700, 'nav_menu_item')]),
        '*/posts/tablepress_table*' => scopedPage([scopedPost(800, 'tablepress_table')]),
    ]);
}

/**
 * A materializer writing into this test's scratch directory.
 */
function scopedMaterializer(string $directory): WxrMaterializer
{
    return new WxrMaterializer(new Client('https://example.test', 'shhh'), $directory);
}

/**
 * The resource path of every request the fake recorded that matched a prefix.
 *
 * Returns `posts/provider` rather than the full URL, and drops the query string, so the
 * assertions state which endpoints were touched and are not coupled to pagination
 * parameters. Deduplicated, because a resource read over several pages is still one read.
 *
 * @param  string  $prefix  `posts/` or `terms/`
 * @return list<string>
 */
function scopedResourcesRead(string $prefix): array
{
    $resources = [];

    Http::assertSent(function (Request $request) use (&$resources, $prefix): bool {
        $path = (string) \parse_url($request->url(), \PHP_URL_PATH);
        $position = \strpos($path, '/'.$prefix);

        if ($position !== false) {
            $resources[] = \substr($path, $position + 1);
        }

        return true;
    });

    $resources = \array_values(\array_unique($resources));
    \sort($resources);

    return $resources;
}

/**
 * Read the `scope` block of the manifest a run wrote.
 *
 * Narrowed once here because the decoded JSON is `mixed` all the way down; a manifest with
 * no usable scope yields an empty array, which fails the assertions rather than passing them.
 *
 * @return array<int|string, mixed> The scope block, or empty when absent
 */
function scopedManifestScope(string $directory): array
{
    $decoded = \json_decode(
        (string) \file_get_contents($directory.\DIRECTORY_SEPARATOR.'wxr.manifest.json'),
        true
    );

    $scope = \is_array($decoded) ? ($decoded['scope'] ?? null) : null;

    return \is_array($scope) ? $scope : [];
}

it('reads only the requested type and its companions when scoped', function (): void {
    fakeScopedSite();

    scopedMaterializer($this->workingDirectory)->materialize(1, null, [], 'post');

    // `kb`, `provider` and `condition` are exposed and served, and none is read. `page` and
    // `uamspeople` are, because internal links point at them (wordpress-importer-archive#1179).
    expect(scopedResourcesRead('posts/'))->toBe([
        'posts/attachment',
        'posts/nav_menu_item',
        'posts/page',
        'posts/post',
        'posts/tablepress_table',
        'posts/uamspeople',
        'posts/wp_block',
    ]);
});

it('reads every exposed type when no content type is given', function (): void {
    // The criterion that the unscoped read is unchanged. Without this control the scoped
    // assertion above would also pass for a materializer that read nothing but companions.
    fakeScopedSite();

    scopedMaterializer($this->workingDirectory)->materialize(1);

    expect(scopedResourcesRead('posts/'))->toBe([
        'posts/attachment',
        'posts/condition',
        'posts/kb',
        'posts/nav_menu_item',
        'posts/page',
        'posts/post',
        'posts/provider',
        'posts/tablepress_table',
        'posts/uamspeople',
        'posts/wp_block',
    ])->and(scopedResourcesRead('terms/'))->toBe([
        'terms/category',
        'terms/kb_topic',
        'terms/nav_menu',
        'terms/school',
        'terms/undeclared',
    ]);
});

it('reads the taxonomies attached to what it reads, from object_types', function (): void {
    fakeScopedSite();

    scopedMaterializer($this->workingDirectory)->materialize(1, null, [], 'post');

    // `category` for the post, `nav_menu` for a companion, `undeclared` because nothing
    // proves it unrelated. `school` belongs only to `provider`, and `kb_topic` only to `kb`.
    expect(scopedResourcesRead('terms/'))->toBe([
        'terms/category',
        'terms/nav_menu',
        'terms/undeclared',
    ]);
});

it('reads pages alongside a knowledge base, which resolves its home page from them', function (): void {
    fakeScopedSite();

    scopedMaterializer($this->workingDirectory)->materialize(1, null, [], 'kb');

    expect(scopedResourcesRead('posts/'))->toContain('posts/kb', 'posts/page')
        ->and(scopedResourcesRead('posts/'))->not->toContain('posts/provider', 'posts/condition')
        ->and(scopedResourcesRead('terms/'))->toContain('terms/kb_topic');
});

it('reads the measured link targets but never the bulk types nothing links to', function (): void {
    // The point of wordpress-importer-archive#1089 is not reading provider and condition. wordpress-importer-archive#1179 adds page, post and
    // uamspeople because links point at them, and it must add nothing else.
    fakeScopedSite();

    scopedMaterializer($this->workingDirectory)->materialize(1, null, [], 'post');

    expect(scopedResourcesRead('posts/'))->toContain('posts/page', 'posts/post', 'posts/uamspeople')
        ->and(scopedResourcesRead('posts/'))->not->toContain('posts/provider', 'posts/condition', 'posts/kb');
});

it('keeps the attachment a scoped item references, so its media resolves', function (): void {
    fakeScopedSite();

    $paths = scopedMaterializer($this->workingDirectory)->materialize(1, null, [], 'post');

    $parser = new XmlParser;
    $parser->setFilePaths($paths);

    $attachments = $parser->getItems('attachment');

    // The post names attachment 900 as its featured image, and the scoped export carries it
    // with its URL, which is what the asset pipeline reads.
    expect($parser->getItems('post'))->toHaveCount(1)
        ->and($attachments)->toHaveCount(1)
        ->and(XpathHelper::getStringValue($attachments[0], 'wp:post_id'))->toBe('900')
        ->and(XpathHelper::getStringValue($attachments[0], 'wp:attachment_url'))
        ->toBe('https://example.test/wp-content/uploads/photo.jpg');
});

it('labels a scoped artifact with what it covers', function (): void {
    fakeScopedSite();

    scopedMaterializer($this->workingDirectory)->materialize(1, null, [], 'post');

    $scope = scopedManifestScope($this->workingDirectory);

    expect($scope['coverage'] ?? null)->toBe('content_type')
        ->and($scope['content_type'] ?? null)->toBe('post')
        ->and($scope['post_types'] ?? null)->toBe([
            'post',
            'page',
            'uamspeople',
            'attachment',
            'wp_block',
            'nav_menu_item',
            'tablepress_table',
        ])
        ->and($scope['link_target_post_types'] ?? null)->toBe(['page', 'post', 'uamspeople']);
});

it('labels an unscoped artifact as covering the site', function (): void {
    fakeScopedSite();

    scopedMaterializer($this->workingDirectory)->materialize(1);

    $scope = scopedManifestScope($this->workingDirectory);

    // `toHaveKey` before `toBeNull`: `??` reads a present null as absent, so it cannot tell
    // "the key is null" from "the key was never written".
    expect($scope['coverage'] ?? null)->toBe('site')
        ->and($scope)->toHaveKey('content_type')
        ->and($scope['content_type'])->toBeNull()
        ->and($scope)->toHaveKey('post_types')
        ->and($scope['post_types'])->toBeNull()
        ->and($scope)->toHaveKey('link_target_post_types')
        ->and($scope['link_target_post_types'])->toBeNull();
});

it('does not report the types a scoped run chose not to read as unserved', function (): void {
    // Unserved means the API refuses a type the site holds. `provider` is served; this run
    // merely did not ask for it, and calling it unserved would bury real refusals.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'Scoped', 'link' => 'https://example.test',
            'base_blog_url' => 'https://example.test', 'wxr_version' => '1.2',
            'counts' => ['by_post_type' => ['post' => 1, 'provider' => 3460, 'kb' => 28]],
        ]], 200),
        '*/post-types*' => Http::response([
            ['name' => 'post', 'exposed' => true],
            ['name' => 'provider', 'exposed' => true],
            ['name' => 'kb', 'exposed' => false],
        ], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/authors*' => scopedPage([]),
        '*/posts/post*' => scopedPage([scopedPost(101, 'post')]),
        '*/posts/provider*' => scopedPage([scopedPost(401, 'provider')]),
    ]);

    $materializer = scopedMaterializer($this->workingDirectory);
    $materializer->materialize(1, null, [], 'post');

    $names = \array_column($materializer->unservedContent(), 'name');

    // `kb` is the control: genuinely unexposed with records, so it IS reported.
    expect($names)->toContain('kb')
        ->and($names)->not->toContain('provider');
});

it('treats a blank content type as unscoped', function (): void {
    fakeScopedSite();

    scopedMaterializer($this->workingDirectory)->materialize(1, null, [], '  ');

    expect(scopedResourcesRead('posts/'))->toContain('posts/provider')
        ->and(scopedManifestScope($this->workingDirectory)['coverage'] ?? null)->toBe('site');
});

it('lets the link reader find a page a scoped post run links to', function (): void {
    // The regression wordpress-importer-archive#1179 was handed back for, driven through the real reader. A post links
    // to a page. `LinkProcessor` resolves that link by the page's URL, so the page's item has
    // to be in a scoped `post` export, or the link cannot be converted even when the page was
    // imported by an earlier run.
    Http::fake([
        '*/sites' => Http::response([[
            'id' => 1, 'title' => 'Scoped', 'link' => 'https://example.test',
            'base_blog_url' => 'https://example.test', 'wxr_version' => '1.2',
        ]], 200),
        '*/post-types*' => Http::response([
            ['name' => 'post', 'exposed' => true],
            ['name' => 'page', 'exposed' => true],
            ['name' => 'provider', 'exposed' => true],
        ], 200),
        '*/taxonomies*' => Http::response([], 200),
        '*/authors*' => scopedPage([]),
        '*/posts/post*' => scopedPage([\array_merge(scopedPost(101, 'post'), [
            'link' => 'https://example.test/news/a-post/',
            'content' => '<p><a href="https://example.test/about/">About us</a></p>',
        ])]),
        '*/posts/page*' => scopedPage([\array_merge(scopedPost(201, 'page'), [
            'link' => 'https://example.test/about/',
            'slug' => 'about',
        ])]),
        '*/posts/provider*' => scopedPage([scopedPost(401, 'provider')]),
    ]);

    $paths = scopedMaterializer($this->workingDirectory)->materialize(1, null, [], 'post');

    $find = new ReflectionMethod(LinkProcessor::class, 'findPostDataByUrl');
    $found = $find->invoke(new LinkProcessor, 'https://example.test/about/', $paths[0]);

    expect($found)->toBeArray()
        ->and(\is_array($found) ? ($found['post_type'] ?? null) : null)->toBe('page')
        ->and(\is_array($found) ? ($found['post_id'] ?? null) : null)->toBe('201')
        ->and(scopedResourcesRead('posts/'))->not->toContain('posts/provider');
});

it('records what a scoped run read, for the readers that look items up in it', function (): void {
    // Without this the readers cannot tell a scoped miss from a broken link, and the reporting
    // wordpress-importer-archive#1179 adds would never fire, however well its own tests pass in isolation.
    fakeScopedSite();

    scopedMaterializer($this->workingDirectory)->materialize(1, null, [], 'post');

    expect(MaterializationScope::isScoped())->toBeTrue()
        ->and(MaterializationScope::postTypes())->toContain('post', 'page', 'attachment')
        ->and(MaterializationScope::postTypes())->not->toContain('provider');
});

it('clears the recorded scope on a whole-site read', function (): void {
    // A previous scoped run in the same process must not leave its scope in force.
    MaterializationScope::set(['post']);
    fakeScopedSite();

    scopedMaterializer($this->workingDirectory)->materialize(1);

    expect(MaterializationScope::isScoped())->toBeFalse()
        ->and(MaterializationScope::postTypes())->toBeNull();
});
