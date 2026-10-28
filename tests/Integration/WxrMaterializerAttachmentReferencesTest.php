<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * A scoped run reads the attachments its items reference, not the whole site's (wordpress-importer-archive#1175).
 *
 * **The narrowing is only kept when it can be shown to lose nothing**, so half of these cases
 * pin the widening: an image whose `src` the referenced records cannot resolve, or a link no
 * item read matches, sends the run back to reading every attachment. The other half pin that
 * a run which can narrow does, and that an unscoped run is untouched.
 *
 * The fake API honours `include[]` and `file[]` the way the plugin's D-085 describes: `file[]`
 * matches an attachment's own file or one of its generated sizes, and an unmatched value is
 * omitted with a 200.
 *
 * @command  php vendor/bin/pest tests/Integration/WxrMaterializerAttachmentReferencesTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    MaterializationScope::clear();
    ImportWarningManager::clearWarnings();

    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('wxr_refs_', true);
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
 * An attachment record on the test site.
 *
 * @param  int  $id  The attachment id
 * @param  string  $file  Its uploads-relative file
 * @return array<string, mixed>
 */
function referencedAttachment(
    int $id,
    string $file
): array {
    return [
        'id' => $id,
        'type' => 'attachment',
        'slug' => 'attachment-'.$id,
        'status' => 'inherit',
        'title' => 'Attachment '.$id,
        'link' => 'https://example.test/attachment-'.$id.'/',
        'attachment' => ['url' => 'https://example.test/wp-content/uploads/'.$file],
        'meta' => ['_wp_attached_file' => [$file]],
    ];
}

/**
 * Fake a site whose `post` items and attachments are given.
 *
 * The attachment endpoint answers `include[]` by id and `file[]` by file or generated size,
 * and serves every attachment when neither is sent.
 *
 * @param  list<array<string, mixed>>  $posts  The `post` items
 * @param  list<array<string, mixed>>  $attachments  The attachments
 * @param  int|null  $attachmentInventory  `counts.by_post_type.attachment`, or null to omit it
 * @param  array<string, list<array<string, mixed>>>  $others  Items of other types, keyed by type
 * @param  ?string  $baseBlogUrl  The site's base blog URL, or null to leave it out
 * @param  mixed  $inventoryValue  A raw `counts.by_post_type.attachment`, used in place of the inventory
 */
function fakeReferenceSite(
    array $posts,
    array $attachments,
    ?int $attachmentInventory = null,
    array $others = [],
    ?string $baseBlogUrl = 'https://example.test',
    mixed $inventoryValue = null
): void {
    $page = static fn (array $records) => Http::response($records, 200, ['X-WP-Total' => (string) \count($records)]);

    Http::fake(function (Request $request) use ($posts, $attachments, $attachmentInventory, $others, $page, $baseBlogUrl, $inventoryValue) {
        $path = (string) \parse_url($request->url(), \PHP_URL_PATH);
        \parse_str((string) \parse_url($request->url(), \PHP_URL_QUERY), $query);

        if (\str_ends_with($path, '/sites')) {
            $inventory = $inventoryValue ?? $attachmentInventory;
            $site = [
                'id' => 1, 'title' => 'Refs', 'link' => 'https://example.test', 'wxr_version' => '1.2',
                'counts' => $inventory === null ? [] : ['by_post_type' => ['attachment' => $inventory]],
            ];

            if ($baseBlogUrl !== null) {
                $site['base_blog_url'] = $baseBlogUrl;
            }

            return Http::response([$site], 200);
        }

        // `attachment` is listed first, as a site can, so a run that did not move it last
        // would read it before any reference had been collected.
        if (\str_ends_with($path, '/post-types')) {
            $types = [
                ['name' => 'attachment', 'exposed' => true],
                ['name' => 'post', 'exposed' => true],
                ['name' => 'page', 'exposed' => true],
            ];

            foreach (\array_keys($others) as $type) {
                if ($type !== 'page') {
                    $types[] = ['name' => $type, 'exposed' => true];
                }
            }

            return Http::response($types, 200);
        }

        if (\str_ends_with($path, '/taxonomies')) {
            return Http::response([], 200);
        }

        foreach ($others as $type => $items) {
            if (\str_ends_with($path, '/posts/'.$type)) {
                return $page($items);
            }
        }

        if (\str_ends_with($path, '/posts/post')) {
            return $page($posts);
        }

        if (\str_ends_with($path, '/posts/attachment')) {
            $include = [];
            $files = [];

            foreach ((array) ($query['include'] ?? []) as $id) {
                $include[] = \is_numeric($id) ? (int) $id : 0;
            }

            foreach ((array) ($query['file'] ?? []) as $file) {
                $files[] = \is_string($file) ? $file : '';
            }

            if ($include === [] && $files === []) {
                return $page($attachments);
            }

            // A generated size belongs to the attachment whose own file it is a size of.
            $selected = \array_values(\array_filter($attachments, static function (array $record) use ($include, $files): bool {
                $url = \is_array($record['attachment'] ?? null) ? ($record['attachment']['url'] ?? '') : '';
                $own = \str_replace('https://example.test/wp-content/uploads/', '', \is_string($url) ? $url : '');

                foreach ($files as $file) {
                    if ($file === $own || \preg_replace('/-\d+x\d+(\.[^.]+)$/', '$1', $file) === $own) {
                        return true;
                    }
                }

                return \in_array($record['id'] ?? null, $include, true);
            }));

            return $page($selected);
        }

        return $page([]);
    });
}

/**
 * Materialize a run scoped to `post`, or unscoped when told to.
 */
function materializeReferences(
    string $directory,
    ?string $contentType = 'post',
    int $perPage = 100,
    ?string $modifiedAfter = null
): WxrMaterializer {
    $materializer = new WxrMaterializer(new Client('https://example.test', 'shhh', $perPage), $directory);
    $materializer->materialize(null, $modifiedAfter, [], $contentType);

    return $materializer;
}

/**
 * The query of every attachment request the fake recorded.
 *
 * @return list<array<array-key, mixed>>
 */
function attachmentQueries(): array
{
    $queries = [];

    Http::assertSent(function (Request $request) use (&$queries): bool {
        if (\str_ends_with((string) \parse_url($request->url(), \PHP_URL_PATH), '/posts/attachment')) {
            \parse_str((string) \parse_url($request->url(), \PHP_URL_QUERY), $query);
            $queries[] = $query;
        }

        return true;
    });

    return $queries;
}

/**
 * Whether any attachment request read the whole collection.
 */
function readEveryAttachment(): bool
{
    foreach (attachmentQueries() as $query) {
        if (! isset($query['include']) && ! isset($query['file'])) {
            return true;
        }
    }

    return false;
}

/**
 * The attachment ids the written parts carry.
 *
 * @return list<int>
 */
function writtenAttachmentIds(string $directory): array
{
    $ids = [];

    foreach ((array) \glob($directory.\DIRECTORY_SEPARATOR.'wxr.*.xml') as $part) {
        $xml = \simplexml_load_file((string) $part);

        if ($xml === false) {
            continue;
        }

        $xml->registerXPathNamespace('wp', 'http://wordpress.org/export/1.2/');

        foreach ((array) $xml->xpath("//item[wp:post_type='attachment']/wp:post_id") as $id) {
            $ids[] = (int) $id;
        }
    }

    \sort($ids);

    return $ids;
}

/**
 * The manifest's `scope.attachments` block.
 *
 * @return array<array-key, mixed>
 */
function manifestAttachments(string $directory): array
{
    $decoded = \json_decode((string) \file_get_contents($directory.\DIRECTORY_SEPARATOR.'wxr.manifest.json'), true);
    $block = \is_array($decoded) && \is_array($decoded['scope'] ?? null) ? ($decoded['scope']['attachments'] ?? null) : null;

    return \is_array($block) ? $block : [];
}

/**
 * A `post` item.
 *
 * @param  array<string, mixed>  $fields  Fields overriding the defaults
 * @return array<string, mixed>
 */
function referencingPost(array $fields): array
{
    return \array_merge([
        'id' => 101, 'type' => 'post', 'slug' => 'post-101', 'status' => 'publish',
        'title' => 'Post 101', 'link' => 'https://example.test/post-101/', 'meta' => [],
    ], $fields);
}

it('reads only the referenced attachments, by id and by file', function (): void {
    fakeReferenceSite(
        [referencingPost([
            'content' => '<img class="wp-image-900" src="https://example.test/wp-content/uploads/2020/09/a-300x200.jpg">',
            'meta' => ['_thumbnail_id' => ['901']],
        ])],
        [
            referencedAttachment(900, '2020/09/a.jpg'),
            referencedAttachment(901, '2020/09/b.jpg'),
            referencedAttachment(902, '2020/09/unreferenced.jpg'),
        ]
    );

    materializeReferences($this->workingDirectory);

    expect(readEveryAttachment())->toBeFalse()
        ->and(writtenAttachmentIds($this->workingDirectory))->toBe([900, 901])
        ->and(manifestAttachments($this->workingDirectory))->toMatchArray([
            'resolution' => 'by_reference',
            'reason' => 'all_references_resolved',
            'records' => 2,
        ]);
});

it('imports the attachment an image src shows when its class names another one', function (): void {
    // The replaced-image case wordpress-importer-archive#1175 was decided on: the class still names 900, the src is
    // now 905's file, and the image pipeline prefers the src.
    fakeReferenceSite(
        [referencingPost([
            'content' => '<img class="wp-image-900" src="https://example.test/wp-content/uploads/2021/03/new-750x629.png">',
        ])],
        [
            referencedAttachment(900, '2020/09/old.png'),
            referencedAttachment(905, '2021/03/new.png'),
        ]
    );

    materializeReferences($this->workingDirectory);

    expect(readEveryAttachment())->toBeFalse()
        ->and(writtenAttachmentIds($this->workingDirectory))->toBe([900, 905]);
});

it('reads every attachment when an image cannot be resolved by reference', function (): void {
    // No attachment owns this file, so a full read could still match it another way.
    fakeReferenceSite(
        [referencingPost([
            'content' => '<img src="https://example.test/wp-content/uploads/2020/09/orphan.webp">',
        ])],
        [referencedAttachment(900, '2020/09/a.jpg'), referencedAttachment(901, '2020/09/b.jpg')]
    );

    materializeReferences($this->workingDirectory);

    $manifest = manifestAttachments($this->workingDirectory);

    expect(readEveryAttachment())->toBeTrue()
        ->and(writtenAttachmentIds($this->workingDirectory))->toBe([900, 901])
        ->and($manifest['resolution'] ?? null)->toBe('full')
        ->and($manifest['reason'] ?? null)->toBe('unresolved_references')
        ->and($manifest['unresolved_images'] ?? null)->toBe(['https://example.test/wp-content/uploads/2020/09/orphan.webp'])
        ->and(ImportWarningManager::getWarnings('migration_api', 'attachment_read_widened'))->toHaveCount(1);
});

it('reads every attachment when a link matches nothing read, since it could be an attachment page', function (): void {
    fakeReferenceSite(
        [referencingPost(['content' => '<a href="https://example.test/about/team-photo/">photo</a>'])],
        [referencedAttachment(900, '2020/09/a.jpg')]
    );

    materializeReferences($this->workingDirectory);

    expect(readEveryAttachment())->toBeTrue()
        ->and(manifestAttachments($this->workingDirectory)['unresolved_links'] ?? null)->toBe(['//example.test/about/team-photo']);
});

it('keeps the narrow read when a link matches an item or a referenced attachment\'s page', function (): void {
    fakeReferenceSite(
        [
            referencingPost(['content' => '<a href="https://example.test/post-102/">sibling</a> <a href="https://example.test/attachment-901/">page</a>', 'meta' => ['_thumbnail_id' => ['901']]]),
            referencingPost(['id' => 102, 'slug' => 'post-102', 'link' => 'https://example.test/post-102/']),
        ],
        [referencedAttachment(901, '2020/09/b.jpg'), referencedAttachment(902, '2020/09/c.jpg')]
    );

    materializeReferences($this->workingDirectory);

    expect(readEveryAttachment())->toBeFalse()
        ->and(writtenAttachmentIds($this->workingDirectory))->toBe([901]);
});

it('counts a link to a draft as resolved by its slug, since a draft\'s link is not its path', function (): void {
    fakeReferenceSite(
        [
            referencingPost(['content' => '<a href="https://example.test/research/tri-program/">TRI</a>']),
            referencingPost(['id' => 103, 'slug' => 'tri-program', 'status' => 'draft', 'link' => 'https://example.test/?p=103']),
        ],
        [referencedAttachment(900, '2020/09/a.jpg')]
    );

    materializeReferences($this->workingDirectory);

    expect(readEveryAttachment())->toBeFalse();
});

it('reports a named reference the site does not serve', function (): void {
    fakeReferenceSite(
        [referencingPost(['meta' => ['_thumbnail_id' => ['999']]])],
        [referencedAttachment(900, '2020/09/a.jpg')]
    );

    $materializer = materializeReferences($this->workingDirectory);

    expect($materializer->attachmentResolution()['unserved_ids'] ?? null)->toBe([999])
        ->and(ImportWarningManager::getWarnings('migration_api', 'unserved_attachment_reference'))->toBe([
            ['attachment_id' => 999, 'shape' => 'meta:_thumbnail_id', 'item' => 101],
        ]);
});

it('does not report a wide candidate the site does not serve', function (): void {
    // An integer in some meta value is fetched in case it is an attachment, and says nothing
    // when it is not one.
    fakeReferenceSite(
        [referencingPost(['meta' => ['phone_extension' => ['4242']]])],
        [referencedAttachment(900, '2020/09/a.jpg')]
    );

    $materializer = materializeReferences($this->workingDirectory);

    expect($materializer->attachmentResolution()['unserved_ids'] ?? null)->toBe([])
        ->and(ImportWarningManager::getWarnings('migration_api', 'unserved_attachment_reference'))->toBe([]);
});

it('splits a long reference list into requests of at most 100', function (): void {
    $meta = ['gallery' => [\implode(',', \range(1, 250))]];

    fakeReferenceSite([referencingPost(['meta' => $meta])], [referencedAttachment(7, '2020/09/a.jpg')]);

    // A 200-record page, so the 100 comes from the API's `file[]` cap and not the page size.
    materializeReferences($this->workingDirectory, 'post', 200);

    $sizes = \array_map(static fn (array $query): int => \count((array) ($query['include'] ?? [])), attachmentQueries());

    expect($sizes)->toBe([100, 100, 50])
        ->and(writtenAttachmentIds($this->workingDirectory))->toBe([7]);
});

it('reads every attachment when the references would cost as many requests', function (): void {
    // Three reference requests against a site whose 150 attachments fit on two pages.
    $meta = ['gallery' => [\implode(',', \range(1, 250))]];

    fakeReferenceSite([referencingPost(['meta' => $meta])], [referencedAttachment(7, '2020/09/a.jpg')], 150);

    materializeReferences($this->workingDirectory);

    expect(readEveryAttachment())->toBeTrue()
        ->and(\count(attachmentQueries()))->toBe(1)
        ->and(manifestAttachments($this->workingDirectory)['reason'] ?? null)->toBe('cheaper');
});

it('leaves an unscoped run reading every attachment, with no attachments block', function (): void {
    fakeReferenceSite(
        [referencingPost(['meta' => ['_thumbnail_id' => ['901']]])],
        [referencedAttachment(900, '2020/09/a.jpg'), referencedAttachment(901, '2020/09/b.jpg')]
    );

    $materializer = materializeReferences($this->workingDirectory, null);

    expect(attachmentQueries())->toHaveCount(1)
        ->and(readEveryAttachment())->toBeTrue()
        ->and(writtenAttachmentIds($this->workingDirectory))->toBe([900, 901])
        ->and($materializer->attachmentResolution())->toBeNull()
        ->and(manifestAttachments($this->workingDirectory))->toBe([]);
});

it('does not count a published item\'s slug as resolving a link to an attachment page', function (): void {
    // WordPress checks a post's slug only against posts, so a post and an attachment can
    // share one. `LinkProcessor` matches slugs only among unpublished items, so the post
    // cannot resolve the link, and only the attachment item can.
    fakeReferenceSite(
        [
            referencingPost(['content' => '<a href="https://example.test/2020/01/news-item/annual-report/">report</a>']),
            referencingPost(['id' => 102, 'slug' => 'annual-report', 'link' => 'https://example.test/2019/05/annual-report/']),
        ],
        [referencedAttachment(900, '2020/01/annual-report.pdf')]
    );

    materializeReferences($this->workingDirectory);

    expect(readEveryAttachment())->toBeTrue()
        ->and(writtenAttachmentIds($this->workingDirectory))->toBe([900]);
});

it('collects references from the companions whose content is imported with the item', function (string $contentType, string $companion): void {
    // The reference lives only in the companion, never in the requested type's own items.
    $item = referencingPost(['id' => 301, 'type' => $companion, 'slug' => $companion.'-301', 'link' => 'https://example.test/'.$companion.'-301/', 'meta' => ['_thumbnail_id' => ['901']]]);
    $others = [$companion => [$item]];

    if ($contentType !== 'post') {
        $others[$contentType] = [referencingPost(['id' => 401, 'type' => $contentType, 'slug' => $contentType.'-401', 'link' => 'https://example.test/'.$contentType.'-401/'])];
    }

    fakeReferenceSite([], [referencedAttachment(900, '2020/09/a.jpg'), referencedAttachment(901, '2020/09/b.jpg')], null, $others);

    materializeReferences($this->workingDirectory, $contentType);

    expect(readEveryAttachment())->toBeFalse()
        ->and(writtenAttachmentIds($this->workingDirectory))->toBe([901]);
})->with([
    'reusable block' => ['post', 'wp_block'],
    'table' => ['post', 'tablepress_table'],
    'menu item' => ['post', 'nav_menu_item'],
    'a knowledge base\'s pages' => ['kb', 'page'],
]);

it('carries modified_after into every reference request', function (): void {
    fakeReferenceSite(
        [referencingPost([
            'content' => '<img src="https://example.test/wp-content/uploads/2020/09/a.jpg">',
            'meta' => ['_thumbnail_id' => ['901']],
        ])],
        [referencedAttachment(900, '2020/09/a.jpg'), referencedAttachment(901, '2020/09/b.jpg')]
    );

    materializeReferences($this->workingDirectory, 'post', 100, '2026-01-01');

    $queries = attachmentQueries();

    expect($queries)->toHaveCount(2)
        ->and(\array_column($queries, 'modified_after'))->toBe(['2026-01-01', '2026-01-01']);
});

it('reads every attachment when the references cost exactly as many requests, and not one fewer', function (int $inventory, string $resolution): void {
    // 250 ids is three requests of 100; 300 attachments is three pages of 100, 301 is four.
    fakeReferenceSite([referencingPost(['meta' => ['gallery' => [\implode(',', \range(1, 250))]]])], [referencedAttachment(7, '2020/09/a.jpg')], $inventory);

    materializeReferences($this->workingDirectory);

    expect(manifestAttachments($this->workingDirectory)['resolution'] ?? null)->toBe($resolution);
})->with([
    'equal' => [300, 'full'],
    'one page more' => [301, 'by_reference'],
]);

it('keeps each file request under the query-string budget', function (): void {
    // A hundred long names would measure past an 8 KB request line.
    $name = \str_repeat('long-descriptive-file-name-', 3);
    $content = '';

    for ($i = 0; $i < 100; $i++) {
        $content .= '<a href="https://example.test/wp-content/uploads/2020/09/'.$name.$i.'.pdf">f</a>';
    }

    fakeReferenceSite([referencingPost(['content' => $content])], [referencedAttachment(7, '2020/09/a.jpg')]);

    materializeReferences($this->workingDirectory);

    $requests = [];

    Http::assertSent(function (Request $request) use (&$requests): bool {
        if (\str_contains($request->url(), 'file')) {
            $requests[] = \strlen((string) \parse_url($request->url(), \PHP_URL_QUERY));
        }

        return true;
    });

    expect(\count($requests))->toBeGreaterThan(1)
        ->and(\max([0, ...$requests]))->toBeLessThan(6200)
        ->and(\array_sum(\array_map(static fn (array $query): int => \count((array) ($query['file'] ?? [])), attachmentQueries())))->toBe(100);
});

it('records a narrow read in full, with the unserved named ids sorted', function (): void {
    fakeReferenceSite(
        [referencingPost([
            'content' => '<img class="wp-image-900" src="https://example.test/wp-content/uploads/2020/09/a.jpg">',
            'meta' => ['_thumbnail_id' => ['905']],
        ]), referencingPost(['id' => 102, 'slug' => 'post-102', 'link' => 'https://example.test/post-102/', 'meta' => ['_thumbnail_id' => ['902']]])],
        [referencedAttachment(900, '2020/09/a.jpg')]
    );

    materializeReferences($this->workingDirectory);

    expect(manifestAttachments($this->workingDirectory))->toBe([
        'resolution' => 'by_reference',
        'reason' => 'all_references_resolved',
        'ids_requested' => 3,
        'paths_requested' => 1,
        'records' => 1,
        'unresolved_image_count' => 0,
        'unresolved_images' => [],
        'unresolved_link_count' => 0,
        'unresolved_links' => [],
        'unserved_ids' => [902, 905],
    ]);
});

it('records a widened read in full, listing 20 of its unresolved images and warning with 5', function (): void {
    $content = '<a href="https://example.test/nowhere/">x</a>';

    for ($image = 1; $image <= 21; $image++) {
        $content .= '<img src="https://example.test/wp-content/uploads/2020/09/orphan-'.$image.'.webp">';
    }

    fakeReferenceSite([referencingPost(['content' => $content])], [referencedAttachment(900, '2020/09/a.jpg')]);

    materializeReferences($this->workingDirectory);

    $images = [];

    for ($image = 1; $image <= 21; $image++) {
        $images[] = 'https://example.test/wp-content/uploads/2020/09/orphan-'.$image.'.webp';
    }

    expect(manifestAttachments($this->workingDirectory))->toBe([
        'resolution' => 'full',
        'reason' => 'unresolved_references',
        'ids_requested' => 0,
        'paths_requested' => 21,
        'records' => 1,
        'unresolved_image_count' => 21,
        'unresolved_images' => \array_slice($images, 0, 20),
        'unresolved_link_count' => 1,
        'unresolved_links' => ['//example.test/nowhere'],
        'unserved_ids' => [],
    ])
        ->and(ImportWarningManager::getWarnings('migration_api', 'attachment_read_widened'))->toBe([[
            'unresolved_images' => 21,
            'unresolved_links' => 1,
            'examples' => \array_slice($images, 0, 5),
        ]]);
});

it('widens for cost only when the narrow read would cost as much as the whole one', function (int $references, mixed $inventory, string $resolution): void {
    $meta = $references === 0 ? [] : ['_thumbnail_id' => ['900']];
    $content = $references === 2 ? '<a href="https://example.test/wp-content/uploads/2020/09/b.jpg">file</a>' : '';

    fakeReferenceSite(
        [referencingPost(['meta' => $meta, 'content' => $content])],
        [referencedAttachment(900, '2020/09/a.jpg'), referencedAttachment(901, '2020/09/b.jpg')],
        inventoryValue: $inventory
    );

    materializeReferences($this->workingDirectory);

    expect(manifestAttachments($this->workingDirectory)['resolution'] ?? null)->toBe($resolution);
})->with([
    'one request against one page' => [1, 50, 'full'],
    'no request against an empty inventory' => [0, 0, 'by_reference'],
    'an inventory that is not a number' => [1, 'many', 'by_reference'],
    'an id and a file, two requests against two pages' => [2, 150, 'full'],
    'an id and a file, two requests against three pages' => [2, 250, 'by_reference'],
]);

it('chunks references at 100 even when a page holds more', function (): void {
    $posts = [];

    for ($id = 1; $id <= 101; $id++) {
        $posts[] = referencingPost(['id' => $id, 'slug' => 'post-'.$id, 'link' => 'https://example.test/post-'.$id.'/', 'meta' => ['_thumbnail_id' => [(string) (1000 + $id)]]]);
    }

    fakeReferenceSite($posts, []);

    materializeReferences($this->workingDirectory, perPage: 200);

    $chunks = [];

    foreach (attachmentQueries() as $query) {
        if (\is_array($query['include'] ?? null)) {
            $chunks[] = \count($query['include']);
        }
    }

    expect($chunks)->toBe([100, 1]);
});

it('resolves an image whose file name needs escaping in XML', function (): void {
    // A raw ampersand, which the matching elements have to escape to parse
    fakeReferenceSite(
        [referencingPost(['content' => '<img src="https://example.test/wp-content/uploads/2020/09/R&D.jpg">'])],
        [referencedAttachment(900, '2020/09/R&D.jpg')]
    );

    materializeReferences($this->workingDirectory);

    expect(manifestAttachments($this->workingDirectory)['resolution'] ?? null)->toBe('by_reference')
        ->and(writtenAttachmentIds($this->workingDirectory))->toBe([900]);
});

it('passes over a served attachment with no file URL', function (): void {
    $noUrl = referencedAttachment(901, '2020/09/b.jpg');
    unset($noUrl['attachment']);

    fakeReferenceSite(
        [referencingPost([
            'content' => '<img src="https://example.test/wp-content/uploads/2020/09/a.jpg">',
            'meta' => ['_thumbnail_id' => ['901']],
        ])],
        [referencedAttachment(900, '2020/09/a.jpg'), $noUrl]
    );

    materializeReferences($this->workingDirectory);

    expect(manifestAttachments($this->workingDirectory)['resolution'] ?? null)->toBe('by_reference')
        ->and(writtenAttachmentIds($this->workingDirectory))->toBe([900, 901]);
});

it('resolves a link to a draft whose slug is percent-encoded in capitals', function (): void {
    fakeReferenceSite(
        [referencingPost(['content' => '<a href="https://example.test/%44raft-page/">draft</a>'])],
        [referencedAttachment(900, '2020/09/a.jpg')],
        others: ['page' => [['id' => 300, 'type' => 'page', 'slug' => 'Draft-Page', 'status' => 'draft', 'link' => 'https://example.test/?page_id=300']]]
    );

    materializeReferences($this->workingDirectory);

    expect(manifestAttachments($this->workingDirectory)['resolution'] ?? null)->toBe('by_reference');
});

it('ignores an image on another site', function (): void {
    fakeReferenceSite(
        [referencingPost(['content' => '<img src="https://elsewhere.test/wp-content/uploads/2020/09/x.jpg">'])],
        [referencedAttachment(900, '2020/09/a.jpg')]
    );

    materializeReferences($this->workingDirectory);

    expect(manifestAttachments($this->workingDirectory)['resolution'] ?? null)->toBe('by_reference')
        ->and(writtenAttachmentIds($this->workingDirectory))->toBe([]);
});

it('treats every image as on the site when the site reports no base URL', function (): void {
    fakeReferenceSite(
        [referencingPost(['content' => '<img src="https://example.test/wp-content/uploads/2020/09/a.jpg">'])],
        [referencedAttachment(900, '2020/09/a.jpg')],
        baseBlogUrl: null
    );

    materializeReferences($this->workingDirectory);

    expect(writtenAttachmentIds($this->workingDirectory))->toBe([900]);
});

it('lists 20 of a widened read\'s unresolved links, and warns with the image first', function (): void {
    $content = '<img src="https://example.test/wp-content/uploads/2020/09/orphan.webp">';
    $links = [];

    for ($link = 1; $link <= 21; $link++) {
        $content .= '<a href="https://example.test/missing-'.$link.'/">x</a>';
        $links[] = '//example.test/missing-'.$link;
    }

    fakeReferenceSite([referencingPost(['content' => $content])], [referencedAttachment(900, '2020/09/a.jpg')]);

    materializeReferences($this->workingDirectory);

    $manifest = manifestAttachments($this->workingDirectory);

    expect($manifest['unresolved_link_count'] ?? null)->toBe(21)
        ->and($manifest['unresolved_links'] ?? null)->toBe(\array_slice($links, 0, 20))
        ->and(ImportWarningManager::getWarnings('migration_api', 'attachment_read_widened'))->toBe([[
            'unresolved_images' => 1,
            'unresolved_links' => 21,
            'examples' => [
                'https://example.test/wp-content/uploads/2020/09/orphan.webp',
                ...\array_slice($links, 0, 4),
            ],
        ]]);
});
