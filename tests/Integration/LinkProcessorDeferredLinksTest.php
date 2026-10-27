<?php

declare(strict_types=1);

/**
 * Tests that `LinkProcessor` resolves the internal links it deferred once their
 * targets are imported: `resolveDeferredLink()` finds the target entry by slug or
 * WordPress ID, `processDeferredLinksForEntry()` rewrites an entry's fields with
 * each resolved link (or an attachment's file) and collects what it could not
 * resolve, `processDeferredInternalLinks()` saves each entry, forgets the links it
 * resolved and reports the rest, and `findEntryWithRetry()` retries a lookup that
 * misses or throws.
 *
 * @command  php vendor/bin/pest tests/Integration/LinkProcessorDeferredLinksTest.php
 */

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * An asset importer that imports files to the given path and records each import.
 */
final class LinkProcessorDeferredImporter extends AssetImporter
{
    /**
     * Each imported URL.
     *
     * @var list<string>
     */
    public array $imports = [];

    /**
     * Create the importer.
     */
    public function __construct(
        private readonly bool $files = true,
        private readonly ?string $importedPath = 'default/brochure.pdf'
    ) {
        parent::__construct('http://93.184.216.34/site');
    }

    /**
     * {@inheritDoc}
     */
    public function getUploadFiles(): bool
    {
        return $this->files;
    }

    /**
     * {@inheritDoc}
     *
     * @param  ?array<int|string, mixed>  $additionalFileMetadata  Extra metadata
     */
    public function importFile(
        string $url,
        string $site,
        OutputInterface $output,
        string $container = 'files',
        SameSitePolicy $sameSitePolicy = SameSitePolicy::SKIP_DIFFERENT_SITE,
        ?string $pageUrl = null,
        ?string $title = null,
        ?array $additionalFileMetadata = null,
        ?string $attachmentPageUrl = null
    ): ?string {
        $this->imports[] = $url;

        return $this->importedPath;
    }
}

beforeEach(function (): void {
    LinkProcessor::clearDeferredData();
    ProcessingContext::clearXmlPath();
    config(['app.url' => 'https://statamic.test']);

    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    // Point the entries store at a directory of this test's own
    $store = Stache::store('entries');
    $this->entriesDirectory = is_object($store) && method_exists($store, 'directory') ? $store->directory() : null;
    $this->root = sys_get_temp_dir().'/link-deferred-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->root);

    if (is_object($store) && method_exists($store, 'directory')) {
        $store->directory($this->root.'/content');
    }

    foreach (['pages', 'posts'] as $handle) {
        $collection = CollectionFacade::make($handle);
        $collection->sites(['default']);
        $collection->save();
    }
});

afterEach(function (): void {
    LinkProcessor::clearDeferredData();
    ProcessingContext::clearXmlPath();

    $store = Stache::store('entries');

    if (is_object($store) && method_exists($store, 'directory') && is_string($this->entriesDirectory ?? null)) {
        $store->directory($this->entriesDirectory);
    }

    $root = $this->root ?? null;

    if (is_string($root)) {
        File::deleteDirectory($root);
    }
});

/**
 * A deferred link with the given values over the defaults.
 *
 * @param  array<string, ?string>  $values
 * @return array{url: string, post_name: string, post_id: string, post_type: string, collection_handle: ?string, source_collection_handle: string, site: string, fragment: ?string, query: ?string}
 */
function deferredLink(array $values = []): array
{
    $link = [
        'url' => 'http://93.184.216.34/site/about/',
        'post_name' => 'about',
        'post_id' => '12',
        'post_type' => 'page',
        'collection_handle' => 'pages',
        'source_collection_handle' => 'pages',
        'site' => 'default',
        'fragment' => null,
        'query' => null,
    ];

    foreach ($values as $key => $value) {
        $link[$key] = $value;
    }

    return [
        'url' => (string) $link['url'],
        'post_name' => (string) $link['post_name'],
        'post_id' => (string) $link['post_id'],
        'post_type' => (string) $link['post_type'],
        'collection_handle' => $link['collection_handle'],
        'source_collection_handle' => (string) $link['source_collection_handle'],
        'site' => (string) $link['site'],
        'fragment' => $link['fragment'],
        'query' => $link['query'],
    ];
}

/**
 * Save an entry and return it.
 *
 * @param  array<string, mixed>  $data
 */
function deferredEntry(string $collection, string $slug, string $id, array $data = []): Entry
{
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new LogicException('Expected an entry.');
    }

    $entry->collection($collection);
    $entry->locale('default');
    $entry->slug($slug);
    $entry->id($id);
    $entry->data($data);
    $entry->save();

    return $entry;
}

/**
 * The saved entry's data.
 *
 * @return array<mixed>
 */
function deferredData(string $id): array
{
    $entry = EntryFacade::find($id);

    $data = $entry instanceof Entry ? $entry->data() : null;

    return $data instanceof Collection ? $data->all() : [];
}

/**
 * A field of the saved entry.
 */
function deferredField(string $id, string $field): mixed
{
    return deferredData($id)[$field] ?? null;
}

/**
 * Bard content holding one link to the URL.
 *
 * @return list<array<string, mixed>>
 */
function deferredBard(string $href): array
{
    return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => $href]]]]]]];
}

/**
 * Write an export holding the given items, and return its path.
 */
function deferredExport(mixed $test, string ...$items): string
{
    $root = is_object($test) ? ($test->root ?? null) : null;

    if (! is_string($root)) {
        throw new LogicException('No test directory.');
    }

    File::put(
        $root.'/export.xml',
        '<?xml version="1.0" encoding="UTF-8"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>'.implode('', $items).'</channel></rss>'
    );

    return $root.'/export.xml';
}

/**
 * An attachment item for the export.
 */
function deferredAttachment(string $url = 'http://93.184.216.34/site/wp-content/uploads/brochure.pdf'): string
{
    return "<item><wp:post_id>30</wp:post_id><wp:post_name>brochure</wp:post_name><wp:post_type>attachment</wp:post_type><wp:attachment_url>{$url}</wp:attachment_url></item>";
}

/**
 * Collect what is logged as a warning.
 *
 * @return ArrayObject<int, array{string, mixed}>
 */
function deferredWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{string, mixed}> $logged */
    $logged = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(static function (string $message, mixed $context = []) use ($logged): void {
        $logged->append([$message, $context]);
    });

    return $logged;
}

/**
 * The messages collected.
 *
 * @param  ArrayObject<int, array{string, mixed}>  $logged
 * @return list<string>
 */
function deferredMessages(ArrayObject $logged): array
{
    return array_values(array_map(static fn (array $entry): string => $entry[0], $logged->getArrayCopy()));
}

/**
 * Process the entry's deferred links with warnings turned into exceptions.
 *
 * @param  array<int, array{url: string, post_name: string, post_id: string, post_type: string, collection_handle: ?string, source_collection_handle: string, site: string, fragment: ?string, query: ?string}>  $links
 * @return array{result: array{content: mixed, page_builder: mixed, content_updated: bool, page_builder_updated: bool, processed_link_urls: list<string>, processed_link_indices: list<int>, unresolved_links: list<array<int|string, mixed>>}, output: string}
 */
function deferredProcess(Entry $entry, array $links, bool $isFinalCall = false, ?AssetImporter $importer = null): array
{
    $output = new BufferedOutput;

    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $result = LinkProcessor::processDeferredLinksForEntry($entry, $links, 'default', $output, $isFinalCall, $importer);
    } finally {
        restore_error_handler();
    }

    return ['result' => $result, 'output' => $output->fetch()];
}

/**
 * The processor's conversion cache.
 *
 * @return array<string, string>
 */
function deferredCache(): array
{
    $cache = (new ReflectionProperty(LinkProcessor::class, 'processedLinksCache'))->getValue();
    $strings = [];

    foreach (is_array($cache) ? $cache : [] as $key => $value) {
        if (is_string($value)) {
            $strings[(string) $key] = $value;
        }
    }

    return $strings;
}

it('skips a deferred link to a redirect rule', function (): void {
    deferredEntry('pages', 'about', 'about-12-abc');

    expect(LinkProcessor::resolveDeferredLink(deferredLink(['post_type' => 'redirect_rule']), 'default'))->toBeNull();
});

it('resolves a deferred link to the entry found by slug, keeping its query and fragment', function (): void {
    $target = deferredEntry('pages', 'about', 'about-99-abc');
    deferredEntry('pages', 'old', 'old-12-abc');

    expect(LinkProcessor::resolveDeferredLink(deferredLink(['fragment' => '#team', 'query' => '?x=1']), 'default'))->toBe([
        'statamic_url' => 'statamic://entry::about-99-abc?x=1#team',
        'target_entry' => $target,
        'wordpress_url' => 'http://93.184.216.34/site/about/',
        'fragment' => '#team',
        'query' => '?x=1',
    ]);
});

it('resolves a deferred link by WordPress ID when the slug finds no entry', function (): void {
    deferredEntry('pages', 'renamed', 'renamed-12-abc');

    expect(LinkProcessor::resolveDeferredLink(deferredLink(), 'default')['statamic_url'] ?? null)->toBe('statamic://entry::renamed-12-abc');
});

it('maps the post type to a collection when the link names none', function (?string $collection, string $type, ?string $resolved): void {
    deferredEntry('posts', 'about', 'about-12-abc');

    expect(LinkProcessor::resolveDeferredLink(deferredLink(['collection_handle' => $collection, 'post_type' => $type]), 'default')['statamic_url'] ?? null)
        ->toBe($resolved);
})->with([
    'none' => [null, 'post', 'statamic://entry::about-12-abc'],
    'an empty one' => ['', 'post', 'statamic://entry::about-12-abc'],
    'a type that maps to no collection' => [null, 'nav_menu_item', null],
    'no type' => [null, '', null],
    'a named collection over the type\'s' => ['pages', 'post', null],
]);

it('says why a deferred link is unresolved only on the final call', function (bool $isFinalCall, array $messages): void {
    $warnings = deferredWarnings();

    expect(LinkProcessor::resolveDeferredLink(deferredLink(), 'default', $isFinalCall))->toBeNull()
        ->and(deferredMessages($warnings))->toBe($messages);
})->with([
    'the final call' => [true, ['LinkProcessor::resolveDeferredLink: Entry not found by slug or WordPress ID after all content types imported']],
    'an earlier call' => [false, []],
]);

it('names the link and its target in the final call\'s warning', function (): void {
    $warnings = deferredWarnings();

    LinkProcessor::resolveDeferredLink(deferredLink(), 'default', true);

    expect($warnings->getArrayCopy()[0][1] ?? null)->toBe([
        'wordpress_post_name' => 'about',
        'wordpress_post_id' => '12',
        'wordpress_post_type' => 'page',
        'statamic_target_collection_handle' => 'pages',
        'statamic_site_handle' => 'default',
        'wordpress_url' => 'http://93.184.216.34/site/about/',
    ]);
});

it('rewrites an entry\'s fields with each resolved link, and caches it by the URL\'s query', function (): void {
    // The link carries no fragment or query of its own, so the reference has
    // none; the URL's query still keys the cache
    deferredEntry('pages', 'about', 'about-12-abc');
    $source = deferredEntry('pages', 'home', 'home-1-abc', [
        'content' => deferredBard('http://93.184.216.34/site/about/?x=1#team'),
        'page_builder' => [['id' => 's', 'button_url' => 'http://93.184.216.34/site/about/?x=1#team']],
    ]);

    $processed = deferredProcess($source, [3 => deferredLink(['url' => 'http://93.184.216.34/site/about/?x=1#team'])]);

    expect($processed['result'])->toBe([
        'content' => deferredBard('statamic://entry::about-12-abc'),
        'page_builder' => [['id' => 's', 'button_url' => 'statamic://entry::about-12-abc']],
        'content_updated' => true,
        'page_builder_updated' => true,
        'processed_link_urls' => ['http://93.184.216.34/site/about/?x=1#team'],
        'processed_link_indices' => [3],
        'unresolved_links' => [],
    ])
        ->and($processed['output'])->toBe('  - Resolved deferred link: http://93.184.216.34/site/about/?x=1#team -> statamic://entry::about-12-abc'.PHP_EOL)
        ->and(deferredCache())->toBe(['http://93.184.216.34/site/about/?x=1' => 'statamic://entry::about-12-abc']);
});

it('prefers the link\'s own fragment and query to the URL\'s', function (): void {
    deferredEntry('pages', 'about', 'about-12-abc');
    $source = deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'see http://93.184.216.34/site/about/?u=1#url']);

    $processed = deferredProcess($source, [deferredLink(['url' => 'http://93.184.216.34/site/about/?u=1#url', 'fragment' => '#own', 'query' => '?own=1'])]);

    // The link's own values make the reference, and the URL's query keys the cache
    expect($processed['result']['content'])->toBe('see statamic://entry::about-12-abc?own=1#own')
        ->and(deferredCache())->toBe(['http://93.184.216.34/site/about/?own=1' => 'statamic://entry::about-12-abc?own=1#own']);
});

it('reports which fields it rewrote', function (mixed $content, mixed $pageBuilder, bool $contentUpdated, bool $pageBuilderUpdated): void {
    deferredEntry('pages', 'about', 'about-12-abc');
    $source = deferredEntry('pages', 'home', 'home-1-abc', array_filter(['content' => $content, 'page_builder' => $pageBuilder], static fn (mixed $value): bool => $value !== null));

    $result = deferredProcess($source, [deferredLink()])['result'];

    expect([$result['content_updated'], $result['page_builder_updated']])->toBe([$contentUpdated, $pageBuilderUpdated]);
})->with([
    'only content' => [deferredBard('http://93.184.216.34/site/about/'), null, true, false],
    'only a page builder' => [null, [['id' => 's']], false, true],
    'neither' => [null, null, false, false],
]);

it('keeps a later link\'s rewrite flags from an earlier one', function (): void {
    deferredEntry('pages', 'about', 'about-12-abc');
    $source = deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'x', 'page_builder' => [['id' => 's']]]);

    // The second link resolves nothing, and changes neither flag back
    $result = deferredProcess($source, [deferredLink(), deferredLink(['post_name' => 'missing', 'post_id' => '99'])])['result'];

    expect([$result['content_updated'], $result['page_builder_updated'], $result['processed_link_indices']])->toBe([true, true, [0]]);
});

it('skips a deferred link to a redirect rule without reporting it unresolved', function (): void {
    $source = deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'x']);

    $result = deferredProcess($source, [deferredLink(['post_type' => 'redirect_rule'])])['result'];

    expect([$result['processed_link_indices'], $result['unresolved_links'], $result['content_updated']])->toBe([[], [], false]);
});

it('points a deferred link to an attachment at its imported file', function (): void {
    ProcessingContext::setXmlPath(deferredExport($this, deferredAttachment()));
    $importer = new LinkProcessorDeferredImporter;
    $source = deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'see http://93.184.216.34/site/brochure/', 'page_builder' => [['id' => 's', 'url' => 'http://93.184.216.34/site/brochure/']]]);

    $processed = deferredProcess($source, [2 => deferredLink(['url' => 'http://93.184.216.34/site/brochure/', 'post_type' => 'attachment', 'post_id' => '30'])], importer: $importer);

    expect($processed['result'])->toBe([
        'content' => 'see https://statamic.test/files/default/brochure.pdf',
        'page_builder' => [['id' => 's', 'url' => 'https://statamic.test/files/default/brochure.pdf']],
        'content_updated' => true,
        'page_builder_updated' => true,
        'processed_link_urls' => ['http://93.184.216.34/site/brochure/'],
        'processed_link_indices' => [2],
        'unresolved_links' => [],
    ])
        ->and($importer->imports)->toBe(['http://93.184.216.34/site/wp-content/uploads/brochure.pdf'])
        ->and($processed['output'])->toBe('  - Processed attachment link: http://93.184.216.34/site/brochure/ -> https://statamic.test/files/default/brochure.pdf'.PHP_EOL);
});

it('points a deferred link to an attachment at its file URL when it imports none', function (string $kind, string $attachmentUrl, string $output): void {
    $importer = match ($kind) {
        'uploads off' => new LinkProcessorDeferredImporter(false),
        'failing' => new LinkProcessorDeferredImporter(importedPath: null),
        'empty path' => new LinkProcessorDeferredImporter(importedPath: ''),
        'importing' => new LinkProcessorDeferredImporter,
        default => null,
    };
    ProcessingContext::setXmlPath(deferredExport($this, deferredAttachment($attachmentUrl)));
    $source = deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'see http://93.184.216.34/site/brochure/']);

    $processed = deferredProcess($source, [deferredLink(['url' => 'http://93.184.216.34/site/brochure/', 'post_type' => 'attachment', 'post_id' => '30'])], importer: $importer);

    expect($processed['result']['content'])->toBe("see {$attachmentUrl}")
        ->and($processed['output'])->toBe($output."  - Processed attachment link: http://93.184.216.34/site/brochure/ -> {$attachmentUrl}".PHP_EOL);
})->with([
    'no importer' => ['none', 'http://93.184.216.34/site/wp-content/uploads/brochure.pdf', ''],
    'uploads off' => ['uploads off', 'http://93.184.216.34/site/wp-content/uploads/brochure.pdf', '  - File uploads disabled. Using original URL for linked attachment: brochure.pdf.'.PHP_EOL],
    'not a file URL' => ['importing', 'http://93.184.216.34/site/brochure-page/', ''],
    'an import that fails' => ['failing', 'http://93.184.216.34/site/wp-content/uploads/brochure.pdf', ''],
    'an import with an empty path' => ['empty path', 'http://93.184.216.34/site/wp-content/uploads/brochure.pdf', ''],
]);

it('points a deferred attachment link at a path on the site when no app URL is set', function (): void {
    config(['app.url' => null]);
    ProcessingContext::setXmlPath(deferredExport($this, deferredAttachment()));
    $source = deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'see http://93.184.216.34/site/brochure/']);

    $processed = deferredProcess($source, [deferredLink(['url' => 'http://93.184.216.34/site/brochure/', 'post_type' => 'attachment', 'post_id' => '30'])], importer: new LinkProcessorDeferredImporter);

    expect($processed['result']['content'])->toBe('see /files/default/brochure.pdf');
});

it('skips a deferred attachment link, with a warning, when its file URL cannot be read', function (?string $export): void {
    ProcessingContext::setXmlPath(match ($export) {
        'empty path' => '',
        'no item' => deferredExport($this, '<item><wp:post_id>31</wp:post_id><wp:post_type>attachment</wp:post_type></item>'),
        'no file URL' => deferredExport($this, '<item><wp:post_id>30</wp:post_id><wp:post_type>attachment</wp:post_type></item>'),
        default => null,
    });
    $warnings = deferredWarnings();
    $source = deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'x']);

    $result = deferredProcess($source, [deferredLink(['post_type' => 'attachment', 'post_id' => '30']), deferredLink(['post_type' => 'redirect_rule'])])['result'];

    expect([$result['processed_link_indices'], $result['unresolved_links'], $result['content_updated']])->toBe([[], [], false])
        ->and($warnings->getArrayCopy())->toBe([['LinkProcessor::processDeferredLinksForEntry: Could not extract attachment URL', [
            'wordpress_url' => 'http://93.184.216.34/site/about/',
            'wordpress_post_id' => '30',
            'wordpress_post_type' => 'attachment',
        ]]]);
})->with([
    'no export' => [null],
    'an empty export path' => ['empty path'],
    'no item' => ['no item'],
    'no file URL' => ['no file URL'],
]);

it('collects a link it cannot resolve, with the entry it is in', function (?string $entryTitle, string $title): void {
    $source = deferredEntry('pages', 'home', 'home-1-abc', $entryTitle === null ? [] : ['title' => $entryTitle]);

    $result = deferredProcess($source, [deferredLink(['collection_handle' => null, 'post_type' => 'post'])])['result'];

    expect($result['unresolved_links'])->toBe([[
        'statamic_source_entry_id' => 'home-1-abc',
        'statamic_source_entry_title' => $title,
        'wordpress_url' => 'http://93.184.216.34/site/about/',
        'wordpress_post_name' => 'about',
        'wordpress_post_id' => '12',
        'statamic_target_collection_handle' => 'posts',
    ]]);
})->with([
    'a titled entry' => ['Home', 'Home'],
    'an untitled entry' => [null, 'Untitled'],
]);

it('warns about an unresolved link whose type maps to no collection', function (?string $collection, string $type, array $messages): void {
    $warnings = deferredWarnings();
    $source = deferredEntry('pages', 'home', 'home-1-abc');

    $result = deferredProcess($source, [deferredLink(['collection_handle' => $collection, 'post_type' => $type])])['result'];

    expect(deferredMessages($warnings))->toBe($messages)
        ->and(array_column($result['unresolved_links'], 'statamic_target_collection_handle', 'wordpress_url'))->toBe(['http://93.184.216.34/site/about/' => $collection === '' ? '' : null]);
})->with([
    'no collection and an unmapped type' => [null, 'nav_menu_item', ["LinkProcessor::processDeferredLinksForEntry: No collection handle found for post type 'nav_menu_item'"]],
    'an empty collection and no type' => ['', '', ["LinkProcessor::processDeferredLinksForEntry: No collection handle found for post type ''"]],
]);

it('names an unresolved link\'s details in its warnings', function (): void {
    $warnings = deferredWarnings();
    $source = deferredEntry('pages', 'home', 'home-1-abc');

    deferredProcess($source, [deferredLink(['collection_handle' => null, 'post_type' => 'nav_menu_item'])], true);

    expect($warnings->getArrayCopy())->toBe([
        ["LinkProcessor::processDeferredLinksForEntry: No collection handle found for post type 'nav_menu_item'", [
            'wordpress_url' => 'http://93.184.216.34/site/about/',
            'wordpress_post_name' => 'about',
            'wordpress_post_id' => '12',
            'wordpress_post_type' => 'nav_menu_item',
        ]],
        ['LinkProcessor::processDeferredLinksForEntry: Could not resolve deferred link after all content types imported', [
            'statamic_source_entry_id' => 'home-1-abc',
            'wordpress_url' => 'http://93.184.216.34/site/about/',
            'wordpress_post_name' => 'about',
            'wordpress_post_id' => '12',
            'statamic_target_collection_handle' => null,
            'statamic_site_handle' => 'default',
        ]],
    ]);
});

it('warns about a link still unresolved only on the final call', function (bool $isFinalCall, int $count): void {
    $warnings = deferredWarnings();
    $source = deferredEntry('pages', 'home', 'home-1-abc');

    deferredProcess($source, [deferredLink()], $isFinalCall);

    // resolveDeferredLink() warns once on the final call too
    expect(deferredMessages($warnings))->toHaveCount($count);
})->with([
    'the final call' => [true, 2],
    'an earlier call' => [false, 0],
]);

it('does nothing when no links are deferred', function (): void {
    $output = new BufferedOutput;

    LinkProcessor::processDeferredInternalLinks('default', $output);

    expect($output->fetch())->toBe('');
});

it('saves each entry with its resolved links, and forgets the links it resolved', function (): void {
    deferredEntry('pages', 'about', 'about-12-abc');
    deferredEntry('posts', 'news', 'news-13-abc');
    deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'see http://93.184.216.34/site/about/ and http://93.184.216.34/site/gone/', 'page_builder' => [['id' => 's', 'url' => 'http://93.184.216.34/site/about/']]]);
    deferredEntry('posts', 'blog', 'blog-2-abc', ['content' => 'see http://93.184.216.34/site/news/']);
    LinkProcessor::setDeferredInternalLinks([
        'home-1-abc' => [
            deferredLink(['post_name' => 'gone', 'post_id' => '98', 'url' => 'http://93.184.216.34/site/gone/']),
            deferredLink(),
            deferredLink(['post_name' => 'gone', 'post_id' => '98', 'url' => 'http://93.184.216.34/site/gone/']),
        ],
        'blog-2-abc' => [deferredLink(['url' => 'http://93.184.216.34/site/news/', 'post_name' => 'news', 'post_id' => '13', 'post_type' => 'post', 'collection_handle' => 'posts', 'source_collection_handle' => 'posts'])],
    ]);
    $output = new BufferedOutput;

    LinkProcessor::processDeferredInternalLinks('default', $output);

    expect(deferredField('home-1-abc', 'content'))->toBe('see statamic://entry::about-12-abc and http://93.184.216.34/site/gone/')
        ->and(deferredField('home-1-abc', 'page_builder'))->toBe([['id' => 's', 'url' => 'statamic://entry::about-12-abc']])
        ->and(deferredField('blog-2-abc', 'content'))->toBe('see statamic://entry::news-13-abc')
        ->and(LinkProcessor::getDeferredInternalLinks())->toBe(['home-1-abc' => [
            deferredLink(['post_name' => 'gone', 'post_id' => '98', 'url' => 'http://93.184.216.34/site/gone/']),
            deferredLink(['post_name' => 'gone', 'post_id' => '98', 'url' => 'http://93.184.216.34/site/gone/']),
        ]])
        ->and($output->fetch())->toBe(
            'Processing deferred internal links...'.PHP_EOL
            .'  - Resolving remaining cross-post-type deferred links...'.PHP_EOL
            .'  - Resolved deferred link: http://93.184.216.34/site/about/ -> statamic://entry::about-12-abc'.PHP_EOL
            .'  - Resolved deferred link: http://93.184.216.34/site/news/ -> statamic://entry::news-13-abc'.PHP_EOL
            .'Processed 2 entries with deferred internal links.'.PHP_EOL
            .'Remaining deferred internal links: 2'.PHP_EOL
            ."  - http://93.184.216.34/site/gone/ (WordPress slug: 'gone'; WordPress ID: '98')".PHP_EOL
            .'      - Used on Statamic Entries:'.PHP_EOL
            ."          - 'Untitled' (Statamic ID: 'home-1-abc')".PHP_EOL
            ."          - 'Untitled' (Statamic ID: 'home-1-abc')".PHP_EOL
        );
});

it('saves only the field a link rewrote', function (string $field): void {
    deferredEntry('pages', 'about', 'about-12-abc');
    $source = deferredEntry('pages', 'home', 'home-1-abc', [$field => $field === 'content' ? 'see http://93.184.216.34/site/about/' : [['id' => 's', 'url' => 'http://93.184.216.34/site/about/']]]);
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [deferredLink()]]);

    LinkProcessor::processDeferredInternalLinks('default', new BufferedOutput);

    $other = $field === 'content' ? 'page_builder' : 'content';

    expect(deferredData('home-1-abc'))->toHaveKey($field)->not->toHaveKey($other)
        ->and($source->get($field))->not->toBeNull();
})->with([
    'content' => ['content'],
    'a page builder' => ['page_builder'],
]);

it('counts an entry whose links changed no field as not processed, and keeps its links', function (): void {
    deferredEntry('pages', 'home', 'home-1-abc');
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [deferredLink(['post_name' => 'gone', 'post_id' => '98'])]]);
    $output = new BufferedOutput;

    LinkProcessor::processDeferredInternalLinks('default', $output);

    expect($output->fetch())->toContain('Processed 0 entries with deferred internal links.'.PHP_EOL.'Remaining deferred internal links: 1'.PHP_EOL)
        ->and(LinkProcessor::getDeferredInternalLinks())->toHaveKey('home-1-abc');
});

it('reports one entry using an unresolved link in the singular', function (): void {
    deferredEntry('pages', 'home', 'home-1-abc', ['title' => 'Home']);
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [deferredLink(['post_name' => 'gone', 'post_id' => '98'])]]);
    $output = new BufferedOutput;

    LinkProcessor::processDeferredInternalLinks('default', $output);

    expect($output->fetch())->toEndWith(
        "  - http://93.184.216.34/site/about/ (WordPress slug: 'gone'; WordPress ID: '98')".PHP_EOL
        .'      - Used on Statamic Entry:'.PHP_EOL
        ."          - 'Home' (Statamic ID: 'home-1-abc')".PHP_EOL
    );
});

it('skips, with a warning, an entry with deferred links that cannot be found', function (): void {
    deferredEntry('pages', 'about', 'about-12-abc');
    deferredEntry('posts', 'blog', 'blog-2-abc', ['content' => 'see http://93.184.216.34/site/about/']);
    LinkProcessor::setDeferredInternalLinks([
        'missing-1' => [deferredLink()],
        'blog-2-abc' => [deferredLink(['source_collection_handle' => 'posts'])],
    ]);
    $warnings = deferredWarnings();

    LinkProcessor::processDeferredInternalLinks('default', new BufferedOutput);

    expect($warnings->getArrayCopy())->toBe([["LinkProcessor::processDeferredInternalLinks: Entry with ID 'missing-1' not found for site default after lightweight retry attempts", [
        'statamic_source_entry_id' => 'missing-1',
        'statamic_site_handle' => 'default',
    ]]])
        ->and(deferredField('blog-2-abc', 'content'))->toBe('see statamic://entry::about-12-abc')
        ->and(LinkProcessor::getDeferredInternalLinks())->toBe(['missing-1' => [deferredLink()]]);
});

it('looks a source entry up in pages when its links name no source collection', function (): void {
    deferredEntry('pages', 'about', 'about-12-abc');
    deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'see http://93.184.216.34/site/about/']);
    $link = deferredLink();
    unset($link['source_collection_handle']);
    (new ReflectionProperty(LinkProcessor::class, 'deferredInternalLinks'))->setValue(null, ['home-1-abc' => [$link]]);

    LinkProcessor::processDeferredInternalLinks('default', new BufferedOutput);

    expect(deferredField('home-1-abc', 'content'))->toBe('see statamic://entry::about-12-abc');
});

it('retries a lookup that misses or throws, asking each time for a fresh read', function (): void {
    $calls = new ArrayObject;
    $entry = new Entry;
    $warnings = deferredWarnings();

    $found = LinkProcessor::findEntryWithRetry(static function (bool $forceFresh) use ($calls, $entry): mixed {
        $calls->append($forceFresh);

        return match (count($calls)) {
            1 => throw new RuntimeException('Permission denied'),
            2 => 'not an entry',
            default => $entry,
        };
    }, 3, 0);

    expect($found)->toBe($entry)
        ->and($calls->getArrayCopy())->toBe([true, true, true])
        ->and($warnings->getArrayCopy())->toBe([['LinkProcessor::findEntryWithRetry: targeted entry invalidation failed; retrying', ['exception' => 'Permission denied']]]);
});

it('stops retrying once a lookup finds the entry, or after the last attempt', function (int $foundOn, int $attempts, int $calls): void {
    $made = new ArrayObject;
    $entry = new Entry;

    $found = LinkProcessor::findEntryWithRetry(static function () use ($made, $entry, $foundOn): ?Entry {
        $made->append(true);

        return count($made) === $foundOn ? $entry : null;
    }, $attempts, 0);

    expect($found)->toBe($foundOn <= $attempts ? $entry : null)
        ->and(count($made))->toBe($calls);
})->with([
    'found first' => [1, 3, 1],
    'found second' => [2, 3, 2],
    'never found' => [9, 2, 2],
]);

it('waits between attempts but not after the last one', function (int $attempts, float $atLeast, float $under): void {
    $started = microtime(true);

    LinkProcessor::findEntryWithRetry(static fn (): ?Entry => null, $attempts, 200000);

    $elapsed = microtime(true) - $started;

    expect($elapsed)->toBeGreaterThanOrEqual($atLeast)
        ->and($elapsed)->toBeLessThan($under);
})->with([
    'one attempt' => [1, 0.0, 0.15],
    'two attempts' => [2, 0.19, 0.35],
]);

it('retries twice by default', function (): void {
    $made = new ArrayObject;

    LinkProcessor::findEntryWithRetry(static function () use ($made): ?Entry {
        $made->append(true);

        return null;
    });

    expect(count($made))->toBe(2);
});

it('treats a call as not the final one by default', function (): void {
    $warnings = deferredWarnings();
    $source = deferredEntry('pages', 'home', 'home-1-abc');
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [deferredLink()]]);

    LinkProcessor::resolveDeferredLink(deferredLink(), 'default');
    LinkProcessor::processDeferredLinksForEntry($source, [deferredLink()], 'default', new BufferedOutput);
    LinkProcessor::processDeferredInternalLinks('default', new BufferedOutput);

    expect(deferredMessages($warnings))->toBe([]);
});

it('reports no remaining links when every one resolved', function (): void {
    deferredEntry('pages', 'about', 'about-12-abc');
    deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'see http://93.184.216.34/site/about/']);
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [deferredLink()]]);
    $output = new BufferedOutput;

    LinkProcessor::processDeferredInternalLinks('default', $output);

    expect($output->fetch())->toEndWith('Processed 1 entries with deferred internal links.'.PHP_EOL)
        ->and(LinkProcessor::getDeferredInternalLinks())->toBe([]);
});

it('reports an unresolved link\'s entry with an empty title when its title is not a string', function (): void {
    deferredEntry('pages', 'home', 'home-1-abc', ['title' => 5]);
    LinkProcessor::setDeferredInternalLinks(['home-1-abc' => [deferredLink(['post_name' => 'gone', 'post_id' => '98'])]]);
    $output = new BufferedOutput;

    LinkProcessor::processDeferredInternalLinks('default', $output);

    expect($output->fetch())->toEndWith("          - '' (Statamic ID: 'home-1-abc')".PHP_EOL);
});

it('goes on to the next link after one it skips or processes as an attachment', function (string $first): void {
    ProcessingContext::setXmlPath(deferredExport($this, deferredAttachment()));
    deferredEntry('pages', 'about', 'about-12-abc');
    $source = deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'see http://93.184.216.34/site/about/']);
    $links = [
        'redirect rule' => deferredLink(['post_type' => 'redirect_rule']),
        'attachment' => deferredLink(['url' => 'http://93.184.216.34/site/brochure/', 'post_type' => 'attachment', 'post_id' => '30']),
        'unreadable attachment' => deferredLink(['url' => 'http://93.184.216.34/site/brochure/', 'post_type' => 'attachment', 'post_id' => '31']),
    ];
    deferredWarnings();

    $result = deferredProcess($source, [$links[$first], deferredLink()])['result'];

    expect($result['content'])->toBe('see statamic://entry::about-12-abc')
        ->and($result['processed_link_indices'])->toContain(1);
})->with([
    'a redirect rule' => ['redirect rule'],
    'an attachment' => ['attachment'],
    'an attachment whose file URL cannot be read' => ['unreadable attachment'],
]);

it('gives an attachment\'s link the link\'s own fragment over the URL\'s', function (?string $fragment, string $added): void {
    ProcessingContext::setXmlPath(deferredExport($this, deferredAttachment()));
    $source = deferredEntry('pages', 'home', 'home-1-abc', ['content' => deferredBard('http://93.184.216.34/site/brochure/#url')]);

    $result = deferredProcess($source, [deferredLink(['url' => 'http://93.184.216.34/site/brochure/#url', 'post_type' => 'attachment', 'post_id' => '30', 'fragment' => $fragment])])['result'];

    expect($result['content'])->toBe([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'x', 'marks' => [['type' => 'link', 'attrs' => ['href' => 'http://93.184.216.34/site/wp-content/uploads/brochure.pdf', 'fragment' => $added]]]]]]]);
})->with([
    'its own' => ['#own', '#own'],
    'the URL\'s when it has none' => [null, '#url'],
]);

it('caches a resolved link with no query by its URL alone', function (): void {
    deferredEntry('pages', 'about', 'about-12-abc');
    $source = deferredEntry('pages', 'home', 'home-1-abc', ['content' => 'x']);

    deferredProcess($source, [deferredLink(['url' => 'http://93.184.216.34/site/about/#team'])]);

    expect(deferredCache())->toBe(['http://93.184.216.34/site/about/' => 'statamic://entry::about-12-abc']);
});

it('reports an unresolved link\'s collection as named, or as its type maps when none is named', function (?string $collection, string $reported): void {
    $source = deferredEntry('pages', 'home', 'home-1-abc');

    $result = deferredProcess($source, [deferredLink(['collection_handle' => $collection, 'post_type' => 'post'])])['result'];

    expect(array_column($result['unresolved_links'], 'statamic_target_collection_handle'))->toBe([$reported]);
})->with([
    'named' => ['pages', 'pages'],
    'empty' => ['', 'posts'],
]);

it('looks for nothing, and says nothing, for a link with no collection and no type', function (): void {
    $warnings = deferredWarnings();

    expect(LinkProcessor::resolveDeferredLink(deferredLink(['collection_handle' => '', 'post_type' => '']), 'default', true))->toBeNull()
        ->and(deferredMessages($warnings))->toBe([]);
});

it('keeps each link deferred for the same entry', function (): void {
    $store = new ReflectionMethod(LinkProcessor::class, 'storeDeferredLink');
    $output = new BufferedOutput;

    $store->invoke(new LinkProcessor, 'home-1-abc', 'pages', 'http://93.184.216.34/site/a/', 'a', '1', 'page', 'default', $output);
    $store->invoke(new LinkProcessor, 'home-1-abc', 'pages', 'http://93.184.216.34/site/b/', 'b', '2', 'post', 'default', $output, '#f', '?q');

    expect(LinkProcessor::getDeferredInternalLinks())->toBe(['home-1-abc' => [
        ['url' => 'http://93.184.216.34/site/a/', 'post_name' => 'a', 'post_id' => '1', 'post_type' => 'page', 'collection_handle' => 'pages', 'source_collection_handle' => 'pages', 'site' => 'default', 'fragment' => null, 'query' => null],
        ['url' => 'http://93.184.216.34/site/b/', 'post_name' => 'b', 'post_id' => '2', 'post_type' => 'post', 'collection_handle' => 'posts', 'source_collection_handle' => 'pages', 'site' => 'default', 'fragment' => '#f', 'query' => '?q'],
    ]]);
});

it('finds nothing when its only lookup returns something other than an entry', function (): void {
    expect(LinkProcessor::findEntryWithRetry(static fn (): string => 'not an entry', 1, 0))->toBeNull();
});
