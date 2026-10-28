<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\Client;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MigrationApiException;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\WxrMaterializer;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests the parts of `WxrMaterializer` the other materializer files do not pin: the part's
 * document structure, the manifest's encoding and failure, the defaults a run starts from, the
 * rows each writer skips, how each kind of value is written, what is recorded as unserved, and
 * the boundaries `referenceChunks()` splits on.
 *
 * The API is faked by route, with every route a run touches answered by a default the case can
 * replace. `referenceChunks()` is private, and its boundaries are byte counts no real export is
 * built to hit, so it is called through reflection.
 *
 * @command  php vendor/bin/pest tests/Integration/WxrMaterializerCoverageTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    MaterializationScope::clear();
    ImportWarningManager::clearWarnings();

    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('wxr_cov_', true);
    \mkdir($this->workingDirectory);
});

afterEach(function (): void {
    \chmod($this->workingDirectory, 0755);
    $entries = \glob($this->workingDirectory.\DIRECTORY_SEPARATOR.'*');

    foreach ($entries === false ? [] : $entries as $entry) {
        \unlink($entry);
    }

    \rmdir($this->workingDirectory);
});

/**
 * Fake the API by route: each route answers its default records unless the case replaces it.
 *
 * A route's value is a list of records served as one page, or a closure that answers the
 * request itself.
 *
 * @param  array<string, mixed>  $routes  Responses keyed by the path's end, such as `/posts/post`
 */
function wmcFake(array $routes = []): void
{
    $routes = \array_merge([
        '/sites' => [[
            'id' => 1, 'title' => 'Site', 'link' => 'https://example.test',
            'base_blog_url' => 'https://example.test', 'wxr_version' => '1.2',
        ]],
        '/post-types' => [['name' => 'post', 'exposed' => true]],
        '/taxonomies' => [],
        '/authors' => [],
        '/posts/post' => [],
    ], $routes);

    Http::fake(static function (Request $request) use ($routes) {
        $path = (string) \parse_url($request->url(), \PHP_URL_PATH);

        foreach ($routes as $suffix => $answer) {
            if (\str_ends_with($path, $suffix)) {
                if ($answer instanceof Closure) {
                    return $answer($request);
                }

                $records = \is_array($answer) ? $answer : [];

                return Http::response($records, 200, ['X-WP-Total' => (string) \count($records)]);
            }
        }

        return Http::response([], 200, ['X-WP-Total' => '0']);
    });
}

/**
 * Materialize into the given directory.
 *
 * @param  string  $directory  The output directory
 * @param  ?int  $site  The site to read
 * @param  ?string  $contentType  The type to scope to
 * @param  ?string  $modifiedAfter  The modification cutoff
 * @return list<string> The written part paths
 */
function wmcMaterialize(
    string $directory,
    ?int $site = null,
    ?string $contentType = null,
    ?string $modifiedAfter = null
): array {
    return wmcMaterializer($directory)->materialize($site, $modifiedAfter, [], $contentType);
}

/**
 * A materializer writing into the given directory, with the default part size.
 *
 * @param  string  $directory  The output directory
 */
function wmcMaterializer(string $directory): WxrMaterializer
{
    return new WxrMaterializer(new Client('https://example.test', 'shhh'), $directory);
}

/**
 * A written part, parsed.
 *
 * @param  string  $path  The part path
 */
function wmcPart(string $path): SimpleXMLElement
{
    $xml = \simplexml_load_file($path);

    if ($xml === false) {
        throw new RuntimeException("The part at {$path} is not parseable XML.");
    }

    $xml->registerXPathNamespace('wp', 'http://wordpress.org/export/1.2/');

    return $xml;
}

/**
 * The decoded manifest.
 *
 * @param  string  $directory  The output directory
 * @return array<array-key, mixed>
 */
function wmcManifest(string $directory): array
{
    $decoded = \json_decode((string) \file_get_contents($directory.\DIRECTORY_SEPARATOR.'wxr.manifest.json'), true);

    return \is_array($decoded) ? $decoded : [];
}

/**
 * The message a call raises.
 *
 * @param  Closure(): mixed  $call  The call expected to raise
 */
function wmcMessage(Closure $call): string
{
    try {
        $call();
    } catch (MigrationApiException $exception) {
        return $exception->getMessage();
    }

    return '(nothing raised)';
}

/**
 * The text of every node an XPath expression selects in a part.
 *
 * @param  string  $path  The part path
 * @param  string  $expression  The expression
 * @return list<string>
 */
function wmcValues(string $path, string $expression): array
{
    $nodes = wmcPart($path)->xpath($expression);

    return \array_values(\array_map(static fn (SimpleXMLElement $node): string => (string) $node, \is_array($nodes) ? $nodes : []));
}

/**
 * A post payload.
 *
 * @param  int  $id  The post id
 * @param  array<string, mixed>  $fields  Fields overriding the defaults
 * @return array<string, mixed>
 */
function wmcPost(int $id, array $fields = []): array
{
    return \array_merge([
        'id' => $id, 'type' => 'post', 'slug' => 'post-'.$id, 'status' => 'publish',
        'title' => 'Post '.$id, 'link' => 'https://example.test/post-'.$id.'/',
    ], $fields);
}

/**
 * The chunks `referenceChunks()` makes of the given values.
 *
 * @param  list<int|string>  $values  The values
 * @param  int  $maxCount  The most values a chunk carries
 * @return list<list<int|string>>
 */
function wmcChunks(array $values, int $maxCount = 100): array
{
    $method = new ReflectionMethod(WxrMaterializer::class, 'referenceChunks');
    $chunks = $method->invoke(wmcMaterializer(\sys_get_temp_dir()), 'file', $values, $maxCount);

    if (! \is_array($chunks)) {
        throw new RuntimeException('referenceChunks() did not answer a list.');
    }

    $list = [];

    foreach ($chunks as $chunk) {
        $values = [];

        foreach (\is_array($chunk) ? $chunk : [] as $value) {
            if (\is_int($value) || \is_string($value)) {
                $values[] = $value;
            }
        }

        $list[] = $values;
    }

    return $list;
}

/**
 * A value of the given length.
 *
 * @param  int  $length  The length
 */
function wmcValue(int $length): string
{
    return \str_repeat('a', $length);
}

it('writes an XML declaration and an rss root carrying the five WXR namespaces', function (): void {
    wmcFake(['/posts/post' => [wmcPost(1)]]);

    $path = wmcMaterialize($this->workingDirectory)[0];

    expect(\str_starts_with((string) \file_get_contents($path), '<?xml version="1.0" encoding="UTF-8"?>'))->toBeTrue()
        ->and(wmcPart($path)->getName())->toBe('rss')
        ->and((string) wmcPart($path)['version'])->toBe('2.0')
        ->and(wmcPart($path)->getDocNamespaces())->toBe([
            'excerpt' => 'http://wordpress.org/export/1.2/excerpt/',
            'content' => 'http://purl.org/rss/1.0/modules/content/',
            'wfw' => 'http://wellformedweb.org/CommentAPI/',
            'dc' => 'http://purl.org/dc/elements/1.1/',
            'wp' => 'http://wordpress.org/export/1.2/',
        ]);
});

it('splits parts at 2,000 items by default', function (): void {
    $posts = [];

    for ($id = 1; $id <= 2001; $id++) {
        $posts[] = ['id' => $id, 'type' => 'post', 'title' => 'P'];
    }

    wmcFake(['/posts/post' => $posts]);

    $paths = wmcMaterialize($this->workingDirectory);

    expect(\array_map(static fn (string $path): int => \count(wmcPart($path)->channel->item), $paths))->toBe([2000, 1])
        ->and(wmcManifest($this->workingDirectory)['items'] ?? null)->toBe(2001);
});

it('trims the content type it is scoped to', function (): void {
    wmcFake();

    wmcMaterialize($this->workingDirectory, contentType: '  post  ');

    $scope = wmcManifest($this->workingDirectory)['scope'] ?? null;

    expect(\is_array($scope) ? $scope['content_type'] : null)->toBe('post');
});

it('refuses an output directory that is blank', function (): void {
    wmcFake();

    expect(wmcMessage(fn () => wmcMaterialize('   ')))->toBe(
        'The output directory "   " does not exist. Refusing to scatter materialized parts into the working directory.'
    );
});

it('writes the manifest pretty-printed, with slashes unescaped and a closing newline', function (): void {
    wmcFake();

    wmcMaterialize($this->workingDirectory, modifiedAfter: '2026/01/02');

    $raw = (string) \file_get_contents($this->workingDirectory.\DIRECTORY_SEPARATOR.'wxr.manifest.json');

    expect($raw)->toContain("\n    \"status\": \"complete\"")
        ->and($raw)->toContain('"modified_after": "2026/01/02"')
        ->and(\str_ends_with($raw, '}'.\PHP_EOL))->toBeTrue();
});

it('lists the parts already in the directory, joining a trailing separator once', function (): void {
    \file_put_contents($this->workingDirectory.\DIRECTORY_SEPARATOR.'wxr.003.xml', '<rss/>');
    \file_put_contents($this->workingDirectory.\DIRECTORY_SEPARATOR.'wxr.001.xml', '<rss/>');
    \file_put_contents($this->workingDirectory.\DIRECTORY_SEPARATOR.'other.xml', '<rss/>');
    wmcFake();

    $paths = wmcMaterialize($this->workingDirectory.\DIRECTORY_SEPARATOR);
    $manifest = wmcManifest($this->workingDirectory);

    expect($paths)->toBe([$this->workingDirectory.\DIRECTORY_SEPARATOR.'wxr.000.xml'])
        ->and($manifest['pre_existing_parts'] ?? null)->toBe(['wxr.001.xml', 'wxr.003.xml'])
        ->and($manifest['stale_parts'] ?? null)->toBe(['wxr.001.xml', 'wxr.003.xml']);
});

it('refuses to write a manifest it cannot encode', function (): void {
    wmcFake();

    expect(wmcMessage(fn () => wmcMaterialize($this->workingDirectory, modifiedAfter: "\xB1\x31")))
        ->toBe('Could not encode the materialization manifest.');
});

it('refuses to go on when it cannot write the manifest', function (): void {
    wmcFake();
    \chmod($this->workingDirectory, 0555);

    $message = wmcMessage(fn () => @wmcMaterialize($this->workingDirectory));

    expect($message)->toBe('Could not write the materialization manifest to "'.$this->workingDirectory.\DIRECTORY_SEPARATOR.'wxr.manifest.json".');
});

it('skips a post, author, term or meta row that is not one, and writes the rows after it', function (): void {
    wmcFake([
        '/authors' => ['not an author', ['id' => 2, 'login' => 'todd']],
        '/posts/post' => ['not a post', wmcPost(1, [
            'terms' => ['category' => ['not a term', ['slug' => 'news', 'name' => 'News']], 5 => [['slug' => 'x']]],
            'meta' => [0 => ['dropped'], 'kept' => ['value']],
        ])],
    ]);

    $path = wmcMaterialize($this->workingDirectory)[0];

    expect(wmcValues($path, '//wp:author/wp:author_login'))->toBe(['todd'])
        ->and(wmcValues($path, '//item/wp:post_id'))->toBe(['1'])
        ->and(wmcValues($path, '//item/category/@nicename'))->toBe(['news'])
        ->and(wmcValues($path, '//item/wp:postmeta/wp:meta_key'))->toBe(['kept']);
});

it('writes each kind of meta value as text, and leaves out a null one', function (): void {
    wmcFake(['/posts/post' => [wmcPost(1, [
        'meta' => ['flags' => [true, false, 3, 1.5, ['a' => 1], null]],
        'terms' => ['category' => [['name' => 'No slug']]],
    ])]]);

    $path = wmcMaterialize($this->workingDirectory)[0];

    expect(wmcValues($path, '//item/wp:postmeta/wp:meta_value'))->toBe(['1', '0', '3', '1.5', '{"a":1}'])
        ->and(wmcValues($path, '//item/wp:postmeta/wp:meta_key'))->toHaveCount(6)
        ->and(wmcValues($path, '//item/category/@nicename'))->toBe(['']);
});

it('writes an attachment URL only when it is text', function (): void {
    wmcFake(['/posts/post' => [
        wmcPost(1, ['attachment' => ['url' => 'https://example.test/wp-content/uploads/a.jpg']]),
        wmcPost(2, ['attachment' => ['url' => 7]]),
    ]]);

    expect(wmcValues(wmcMaterialize($this->workingDirectory)[0], '//item/wp:attachment_url'))
        ->toBe(['https://example.test/wp-content/uploads/a.jpg']);
});

it('names the site it could not find, skipping entries that are not sites', function (): void {
    wmcFake(['/sites' => ['not a site', ['id' => 1, 'link' => 'https://example.test']]]);

    expect(wmcMessage(fn () => wmcMaterialize($this->workingDirectory, site: 9)))->toBe(
        'The migration API serves no site with id 9. Refusing to materialize an export with no channel metadata.'
    );
});

it('says unscoped when the API serves no site at all', function (): void {
    wmcFake(['/sites' => ['not a site']]);

    expect(wmcMessage(fn () => wmcMaterialize($this->workingDirectory)))->toBe(
        'The migration API serves no site with id (unscoped). Refusing to materialize an export with no channel metadata.'
    );
});

it('finds a requested site after an entry that is not one', function (): void {
    wmcFake(['/sites' => ['not a site', ['id' => 4, 'link' => 'https://example.test', 'base_blog_url' => 'https://example.test']]]);

    expect(wmcMaterialize($this->workingDirectory, site: 4))->toHaveCount(1);
});

it('refuses a requested site whose link is blank', function (): void {
    wmcFake(['/sites' => [['id' => 4, 'link' => '   ']]]);

    expect(wmcMessage(fn () => wmcMaterialize($this->workingDirectory, site: 4)))->toBe(
        'The migration API reported site 4 without a usable "link", so its own REST root cannot be addressed. '
        .'Site-scoped reads need that root because the discovery endpoints refuse `?site=`.'
    );
});

it('records the post types and taxonomies a site holds but does not serve', function (): void {
    wmcFake([
        '/sites' => [[
            'id' => 1, 'link' => 'https://example.test', 'base_blog_url' => 'https://example.test',
            'counts' => ['by_post_type' => [5 => 9, 'kb' => '28', 'one' => 1, 'empty' => 0, 'odd' => 'many', 'post' => 4]],
        ]],
        '/taxonomies' => [
            'not a taxonomy',
            ['name' => 'served', 'exposed' => true, 'terms' => 3],
            ['name' => 'kb_topics', 'exposed' => false, 'terms' => '7'],
            ['name' => 'no_terms', 'exposed' => false, 'terms' => 0],
            ['name' => 'one_term', 'exposed' => false, 'terms' => 1],
            ['name' => 'unmarked', 'terms' => 2],
            ['name' => 'odd', 'exposed' => false, 'terms' => 'many'],
            ['exposed' => false, 'terms' => 2],
        ],
    ]);

    $materializer = wmcMaterializer($this->workingDirectory);
    $materializer->materialize();

    $expected = [
        ['kind' => 'post type', 'name' => 'kb', 'records' => 28],
        ['kind' => 'post type', 'name' => 'one', 'records' => 1],
        ['kind' => 'taxonomy', 'name' => 'kb_topics', 'records' => 7],
        ['kind' => 'taxonomy', 'name' => 'one_term', 'records' => 1],
        ['kind' => 'taxonomy', 'name' => 'unmarked', 'records' => 2],
    ];

    expect($materializer->unservedContent())->toBe($expected)
        ->and(ImportWarningManager::getWarnings('migration_api', 'unserved_content'))->toBe($expected);
});

it('records the options a run did not read and the ones the endpoint withheld', function (): void {
    wmcFake([
        '/sites' => [[
            'id' => 1, 'link' => 'https://example.test', 'base_blog_url' => 'https://example.test',
            'counts' => ['options' => '2'],
        ]],
        '/options' => static fn (): mixed => Http::response([
            'options' => ['blogname' => 'Site', 'siteurl' => 'https://example.test', 'stray' => 'x'],
            'denied' => ['by_name' => '2', 'by_shape' => 3],
        ], 200),
    ]);

    $materializer = wmcMaterializer($this->workingDirectory);
    $materializer->materialize(null, null, ['blogname']);

    expect($materializer->unservedContent())->toBe([
        ['kind' => 'option', 'name' => 'not read by this run', 'records' => 2],
        ['kind' => 'option', 'name' => 'withheld by the endpoint denylist', 'records' => 5],
    ]);
});

it('reads options when the site reports exactly one', function (): void {
    wmcFake([
        '/sites' => [['id' => 1, 'link' => 'https://example.test', 'counts' => ['options' => 1]]],
        '/options' => static fn (): mixed => Http::response(['options' => ['stray' => 'x']], 200),
    ]);

    $materializer = wmcMaterializer($this->workingDirectory);
    $materializer->materialize();

    expect($materializer->unservedContent())->toBe([['kind' => 'option', 'name' => 'not read by this run', 'records' => 1]]);
});

it('records a single withheld option, counted by name or by shape', function (string $count): void {
    wmcFake([
        '/sites' => [['id' => 1, 'link' => 'https://example.test', 'counts' => ['options' => 1]]],
        '/options' => static fn (): mixed => Http::response(['options' => [], 'denied' => [$count => 1]], 200),
    ]);

    $materializer = wmcMaterializer($this->workingDirectory);
    $materializer->materialize();

    expect($materializer->unservedContent())->toBe([['kind' => 'option', 'name' => 'withheld by the endpoint denylist', 'records' => 1]]);
})->with(['by name' => ['by_name'], 'by shape' => ['by_shape']]);

it('records nothing for options the run read in full, with nothing withheld', function (): void {
    wmcFake([
        '/sites' => [['id' => 1, 'link' => 'https://example.test', 'counts' => ['options' => 1]]],
        '/options' => static fn (): mixed => Http::response(['options' => ['blogname' => 'Site'], 'denied' => ['by_name' => 'n/a']], 200),
    ]);

    $materializer = wmcMaterializer($this->workingDirectory);
    $materializer->materialize(null, null, ['blogname']);

    expect($materializer->unservedContent())->toBe([]);
});

it('does not ask for options when the site reports none, or no number', function (mixed $options): void {
    wmcFake([
        '/sites' => [['id' => 1, 'link' => 'https://example.test', 'counts' => ['options' => $options]]],
        '/options' => static fn (): never => throw new RuntimeException('Options were requested.'),
    ]);

    expect(wmcMaterialize($this->workingDirectory))->toHaveCount(1);
})->with(['none' => [0], 'not a number' => ['many']]);

it('reads a taxonomy whose post types include one read, or name none, and skips the others', function (): void {
    wmcFake([
        '/post-types' => [['name' => 'post', 'exposed' => true], ['name' => 'page', 'exposed' => true]],
        '/taxonomies' => [
            ['name' => 'attached', 'exposed' => true, 'object_types' => [3, 'post']],
            ['name' => 'unrelated', 'exposed' => true, 'object_types' => ['product']],
            ['name' => 'undeclared', 'exposed' => true, 'object_types' => [3]],
        ],
    ]);

    wmcMaterialize($this->workingDirectory, contentType: 'post');

    $read = [];

    Http::assertSent(static function (Request $request) use (&$read): bool {
        if (\preg_match('#/terms/([a-z]+)#', $request->url(), $matches) === 1) {
            $read[] = $matches[1];
        }

        return true;
    });

    expect($read)->toBe(['attached', 'undeclared']);
});

it('splits values on the count a chunk may carry', function (): void {
    expect(wmcChunks(['a', 'b', 'c'], 2))->toBe([['a', 'b'], ['c']]);
});

it('fills a chunk to exactly 6,000 encoded bytes, and splits past it', function (int $total, int $chunks): void {
    // `file%5B0%5D=` plus `&` is 13 bytes around a value, and the second value's index costs
    // the same, so two values of these lengths total exactly the given bytes
    $first = 3000 - 13;
    $second = $total - 3000 - 13;

    expect(wmcChunks([wmcValue($first), wmcValue($second)]))->toHaveCount($chunks);
})->with([
    'exactly 6,000 bytes' => [6000, 1],
    'one byte over' => [6001, 2],
]);

it('starts a new chunk counting from nothing', function (int $total, int $chunks): void {
    // A value of 6,000 bytes fills the first chunk alone, so the next two start a fresh one
    $second = 3000 - 13;
    $third = $total - 3000 - 13;

    expect(wmcChunks([wmcValue(6000 - 13), wmcValue($second), wmcValue($third)]))->toHaveCount($chunks);
})->with([
    'the fresh chunk fills to exactly 6,000 bytes' => [6000, 2],
    'the fresh chunk would run one byte over' => [6001, 3],
]);
