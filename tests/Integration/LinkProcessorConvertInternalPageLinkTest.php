<?php

declare(strict_types=1);

/**
 * Tests that `LinkProcessor::convertInternalPageLink()` turns a link to a page of
 * the WordPress site into what the importer can keep: a fragment for a link to
 * the current page, the Statamic entry reference for a post found in the export
 * (directly or after its redirect), the imported file for an attachment, or a
 * deferral for an entry not imported yet; and that a link it cannot resolve is
 * kept and reported.
 *
 * The method is private, so it is reached by reflection.
 *
 * @command  php vendor/bin/pest tests/Integration/LinkProcessorConvertInternalPageLinkTest.php
 */

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
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
 * An asset importer that imports files to the given path, or throws when asked
 * to import.
 */
final class LinkProcessorInternalImporter extends AssetImporter
{
    /**
     * Create the importer.
     */
    public function __construct(
        private readonly bool $files = true,
        private readonly ?string $importedPath = 'default/brochure.pdf',
        private readonly bool $throws = false
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
        if ($this->throws) {
            throw new RuntimeException('the import failed');
        }

        return $this->importedPath;
    }
}

beforeEach(function (): void {
    // A link that falls through to fetching its page must not reach the network
    Http::preventStrayRequests();

    LinkProcessor::clearDeferredData();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearCollectionHandle();
    (new ReflectionProperty(LinkProcessor::class, 'cachedXmlPath'))->setValue(null, null);
    config(['app.url' => 'https://statamic.test']);

    Site::setSites(['default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/']]);

    // Point the entries store at a directory of this test's own
    $store = Stache::store('entries');
    $this->entriesDirectory = is_object($store) && method_exists($store, 'directory') ? $store->directory() : null;
    $this->root = sys_get_temp_dir().'/link-internal-'.bin2hex(random_bytes(6));
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
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearCollectionHandle();
    (new ReflectionProperty(LinkProcessor::class, 'cachedXmlPath'))->setValue(null, null);

    $store = Stache::store('entries');

    if (is_object($store) && method_exists($store, 'directory') && is_string($this->entriesDirectory ?? null)) {
        $store->directory($this->entriesDirectory);
    }

    File::deleteDirectory(internalRoot($this));
});

/**
 * The directory this test writes to.
 */
function internalRoot(mixed $test): string
{
    $root = is_object($test) ? ($test->root ?? null) : null;

    return is_string($root) ? $root : throw new LogicException('No test directory.');
}

/**
 * A WordPress item for the export.
 */
function internalItem(string $id, string $name, string $link, string $type = 'page', string $extra = ''): string
{
    return "<item><link>{$link}</link><wp:post_id>{$id}</wp:post_id><wp:post_name>{$name}</wp:post_name>"
        ."<wp:post_parent>0</wp:post_parent><wp:post_type>{$type}</wp:post_type><wp:status>publish</wp:status>{$extra}</item>";
}

/**
 * Write an export holding the given items, and return its path.
 */
function internalExport(mixed $test, string ...$items): string
{
    $path = internalRoot($test).'/export.xml';

    File::put(
        $path,
        '<?xml version="1.0" encoding="UTF-8"?><rss xmlns:wp="http://wordpress.org/export/1.2/"><channel>'
        .'<wp:base_blog_url>http://93.184.216.34/site</wp:base_blog_url>'.implode('', $items).'</channel></rss>'
    );

    return $path;
}

/**
 * Save an entry with the given slug and id.
 */
function internalEntry(string $collection, string $slug, string $id): void
{
    $entry = EntryFacade::make();

    if (! $entry instanceof Entry) {
        throw new LogicException('Expected an entry.');
    }

    $entry->collection($collection);
    $entry->locale('default');
    $entry->slug($slug);
    $entry->id($id);
    $entry->save();
}

/**
 * Convert the link, returning the result and what was written.
 *
 * @return array{result: mixed, output: string}
 */
function internalConvert(string $url, ?string $entryId = null, ?AssetImporter $importer = null, ?LinkProcessor $processor = null): array
{
    $output = new BufferedOutput;

    // The host app turns a warning into an exception, so a warning fails the test
    set_error_handler(static function (int $level, string $message): never {
        throw new ErrorException($message, 0, $level);
    });

    try {
        $result = (new ReflectionMethod(LinkProcessor::class, 'convertInternalPageLink'))
            ->invoke($processor ?? new LinkProcessor, $url, 'default', $importer ?? new LinkProcessorInternalImporter, $output, $entryId);
    } finally {
        restore_error_handler();
    }

    return ['result' => $result, 'output' => $output->fetch()];
}

/**
 * Collect what is logged at the given level, by message.
 *
 * @return ArrayObject<int, array{string, mixed}>
 */
function internalLogged(string $level): ArrayObject
{
    /** @var ArrayObject<int, array{string, mixed}> $logged */
    $logged = new ArrayObject;

    Log::shouldReceive($level)->andReturnUsing(static function (string $message, mixed $context = []) use ($logged): void {
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
function internalMessages(ArrayObject $logged): array
{
    return array_values(array_map(static fn (array $entry): string => $entry[0], $logged->getArrayCopy()));
}

/**
 * The internal links the processor reports as unresolved.
 */
function internalUnresolved(): string
{
    $output = new BufferedOutput;
    LinkProcessor::processUnresolvedLinksForPostType('default', $output);

    return $output->fetch();
}

it('converts nothing for an empty URL, with a warning', function (): void {
    $warnings = internalLogged('warning');

    expect(internalConvert('')['result'])->toBeNull()
        ->and(internalMessages($warnings))->toBe(['LinkProcessor::convertInternalPageLink: URL not provided']);
});

it('points a link to the current page at its fragment', function (string $url, string $href): void {
    ProcessingContext::setWordPressPageLink('http://93.184.216.34/site/about/?lang=en#intro');

    $converted = internalConvert($url);

    expect($converted['result'])->toBe($href)
        ->and($converted['output'])->toBe("  - Converted same-page link: {$url} -> {$href}".PHP_EOL);
})->with([
    'with a fragment' => ['http://93.184.216.34/site/about/?lang=en#team', '#team'],
    'without a fragment' => ['http://93.184.216.34/site/about/?lang=en', ''],
]);

it('does not take a link for the current page when its query or the page differs', function (?string $pageLink, string $url): void {
    // With no export to search, the link goes no further than the XML check
    ProcessingContext::setWordPressPageLink($pageLink);
    $warnings = internalLogged('warning');

    expect(internalConvert($url)['result'])->toBeNull()
        ->and(internalMessages($warnings))->toBe(['LinkProcessor::convertInternalPageLink: XML not provided (from entry: '.$pageLink.')']);
})->with([
    'another query' => ['http://93.184.216.34/site/about/?lang=en', 'http://93.184.216.34/site/about/?lang=es#team'],
    'no query' => ['http://93.184.216.34/site/about/?lang=en', 'http://93.184.216.34/site/about/#team'],
    'another page with the same query' => ['http://93.184.216.34/site/about/?lang=en', 'http://93.184.216.34/site/team/?lang=en'],
    'a page link of only a fragment' => ['#intro', '#team'],
]);

it('does not take a link for the current page when there is no current page', function (?string $pageLink): void {
    ProcessingContext::setWordPressPageLink($pageLink);
    internalLogged('warning');

    expect(internalConvert('#team')['result'])->toBeNull();
})->with([
    'none' => [null],
    'empty' => [''],
]);

it('reads the export from the processor, the context, or the last one used', function (string $instance, string $context, string $cached, string $read): void {
    $path = internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/'));
    internalEntry('pages', 'about', 'about-12-abc');
    $paths = ['export' => $path, 'missing' => internalRoot($this).'/missing.xml', 'none' => null];
    $processor = new LinkProcessor;
    (new ReflectionProperty(LinkProcessor::class, 'xmlPath'))->setValue($processor, $paths[$instance]);
    ProcessingContext::setXmlPath($paths[$context]);
    (new ReflectionProperty(LinkProcessor::class, 'cachedXmlPath'))->setValue(null, $paths[$cached]);
    Http::fake(['*' => Http::response('')]);

    $converted = internalConvert('http://93.184.216.34/site/about/', processor: $processor);

    expect($converted['result'])->toBe($read === 'export' ? 'statamic://entry::about-12-abc' : 'http://93.184.216.34/site/about/')
        ->and((new ReflectionProperty(LinkProcessor::class, 'cachedXmlPath'))->getValue())->toBe($paths[$read]);
})->with([
    'the processor\'s over the context\'s' => ['export', 'missing', 'none', 'export'],
    'the context\'s' => ['none', 'export', 'missing', 'export'],
    'the last one used' => ['none', 'none', 'export', 'export'],
    'the processor\'s, when it holds nothing' => ['missing', 'export', 'none', 'missing'],
]);

it('converts nothing, with a warning naming the URL, when no export is known', function (?string $path): void {
    ProcessingContext::setXmlPath($path);
    $warnings = internalLogged('warning');

    expect(internalConvert('http://93.184.216.34/site/about/')['result'])->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([['LinkProcessor::convertInternalPageLink: XML not provided', ['wordpress_url' => 'http://93.184.216.34/site/about/']]]);
})->with([
    'none' => [null],
    'empty' => [''],
]);

it('points a link at the entry of the post it names, keeping its query and fragment', function (string $url, string $reference): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/?ref=nav')));
    internalEntry('pages', 'about', 'about-12-abc');

    $converted = internalConvert($url);

    expect($converted['result'])->toBe($reference)
        ->and($converted['output'])->toBe("  - Converted internal link: {$url} -> {$reference}".PHP_EOL);
})->with([
    'a query' => ['http://93.184.216.34/site/about/?ref=nav', 'statamic://entry::about-12-abc?ref=nav'],
    'a query and a fragment' => ['http://93.184.216.34/site/about/?ref=nav#team', 'statamic://entry::about-12-abc?ref=nav#team'],
]);

it('caches a converted link by its URL without the fragment', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/')));
    internalEntry('pages', 'about', 'about-12-abc');
    internalConvert('http://93.184.216.34/site/about/#a');
    File::put(internalRoot($this).'/export.xml', 'not read again');

    $converted = internalConvert('http://93.184.216.34/site/about/#b');

    expect($converted['result'])->toBe('statamic://entry::about-12-abc#a')
        ->and($converted['output'])->toBe('  - Using cached result for internal link: http://93.184.216.34/site/about/#b -> statamic://entry::about-12-abc#a'.PHP_EOL);
});

it('finds the entry by its WordPress ID when the slug finds none', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/')));
    internalEntry('pages', 'renamed', 'renamed-12-abc');
    internalEntry('pages', 'other', 'other-13-abc');

    expect(internalConvert('http://93.184.216.34/site/about/')['result'])->toBe('statamic://entry::renamed-12-abc');
});

it('finds the entry by its WordPress ID alone for a post with no slug', function (): void {
    // A link match needs a slug, so the post is found through the URL's cached ID
    ProcessingContext::setXmlPath(internalExport($this, '<item><wp:post_id>12</wp:post_id><wp:post_name></wp:post_name><wp:post_type>page</wp:post_type></item>'));
    LinkProcessor::registerUrlMapping('http://93.184.216.34/site/?page_id=12', '12');
    internalEntry('pages', 'draft', 'draft-12-abc');

    expect(internalConvert('http://93.184.216.34/site/?page_id=12')['result'])->toBe('statamic://entry::draft-12-abc?page_id=12');
});

it('prefers the entry found by slug to one found by WordPress ID', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/')));
    internalEntry('pages', 'old', 'old-12-abc');
    internalEntry('pages', 'about', 'about-99-abc');

    expect(internalConvert('http://93.184.216.34/site/about/')['result'])->toBe('statamic://entry::about-99-abc');
});

it('looks only in the collection the post type maps to', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/', 'post')));
    internalEntry('pages', 'about', 'about-12-abc');

    expect(internalConvert('http://93.184.216.34/site/about/')['result'])->toBeNull();
});

it('defers a link to a post not imported yet, for the entry it is in', function (?string $collection, string $sourceCollection, string $type, ?string $targetCollection): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/?ref=nav', $type)));
    ProcessingContext::setCollectionHandle($collection);

    $converted = internalConvert('http://93.184.216.34/site/about/?ref=nav#team', 'source-1');

    expect($converted['result'])->toBeNull()
        ->and(LinkProcessor::getDeferredInternalLinks())->toBe(['source-1' => [[
            'url' => 'http://93.184.216.34/site/about/?ref=nav#team',
            'post_name' => 'about',
            'post_id' => '12',
            'post_type' => $type,
            'collection_handle' => $targetCollection,
            'source_collection_handle' => $sourceCollection,
            'site' => 'default',
            'fragment' => '#team',
            'query' => '?ref=nav',
        ]]])
        ->and($converted['output'])->toBe("  - Internal link processing deferred for http://93.184.216.34/site/about/?ref=nav#team (WordPress slug: about; WordPress ID: 12, WordPress post type: {$type})".PHP_EOL);
})->with([
    'from the context\'s collection' => ['posts', 'posts', 'page', 'pages'],
    'from pages by default' => [null, 'pages', 'page', 'pages'],
    'a type that maps to no collection' => [null, 'pages', 'nav_menu_item', null],
]);

it('defers nothing without the entry the link is in', function (?string $entryId): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/')));

    expect(internalConvert('http://93.184.216.34/site/about/', $entryId)['result'])->toBeNull()
        ->and(LinkProcessor::getDeferredInternalLinks())->toBe([]);
})->with([
    'none' => [null],
    'empty' => [''],
]);

it('converts and defers nothing for a link to a redirect rule', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/', 'redirect_rule')));

    expect(internalConvert('http://93.184.216.34/site/about/', 'source-1')['result'])->toBeNull()
        ->and(LinkProcessor::getDeferredInternalLinks())->toBe([]);
});

it('points a link to an attachment at its imported file', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('30', 'brochure', 'http://93.184.216.34/site/brochure/', 'attachment', '<wp:attachment_url>http://93.184.216.34/site/wp-content/uploads/brochure.pdf</wp:attachment_url>')));

    $converted = internalConvert('http://93.184.216.34/site/brochure/', 'source-1');

    expect($converted['result'])->toBe('https://statamic.test/files/default/brochure.pdf')
        ->and($converted['output'])->toBe('  - Converted attachment link to file: http://93.184.216.34/site/wp-content/uploads/brochure.pdf -> https://statamic.test/files/default/brochure.pdf'.PHP_EOL);
});

it('keeps an attachment\'s file URL when file uploads are off', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('30', 'brochure', 'http://93.184.216.34/site/brochure/', 'attachment', '<wp:attachment_url>http://93.184.216.34/site/wp-content/uploads/brochure.pdf</wp:attachment_url>')));

    expect(internalConvert('http://93.184.216.34/site/brochure/', importer: new LinkProcessorInternalImporter(false))['result'])
        ->toBe('http://93.184.216.34/site/wp-content/uploads/brochure.pdf');
});

it('converts nothing for an attachment with no file URL', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('30', 'brochure', 'http://93.184.216.34/site/brochure/', 'attachment')));

    expect(internalConvert('http://93.184.216.34/site/brochure/', 'source-1')['result'])->toBeNull()
        ->and(LinkProcessor::getDeferredInternalLinks())->toBe([]);
});

it('converts nothing for an attachment whose item the lookup by ID and type misses', function (): void {
    // The link match reads the ID's whole value, a newline and 30, while the
    // lookup compares single text nodes, so it finds no item
    ProcessingContext::setXmlPath(internalExport(
        $this,
        "<item><link>http://93.184.216.34/site/brochure/</link><wp:post_id>\n<![CDATA[30]]></wp:post_id><wp:post_name>brochure</wp:post_name>"
        .'<wp:post_type>attachment</wp:post_type><wp:attachment_url>http://93.184.216.34/site/wp-content/uploads/brochure.pdf</wp:attachment_url></item>'
    ));

    expect(internalConvert('http://93.184.216.34/site/brochure/')['result'])->toBeNull();
});

it('converts nothing, saying why, when converting throws', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('30', 'brochure', 'http://93.184.216.34/site/brochure/', 'attachment', '<wp:attachment_url>http://93.184.216.34/site/wp-content/uploads/brochure.pdf</wp:attachment_url>')));
    $errors = internalLogged('error');

    $converted = internalConvert('http://93.184.216.34/site/brochure/', importer: new LinkProcessorInternalImporter(throws: true));

    expect($converted['result'])->toBeNull()
        ->and($converted['output'])->toBe("Error converting internal link 'http://93.184.216.34/site/brochure/': the import failed".PHP_EOL)
        ->and($errors->getArrayCopy())->toBe([['LinkProcessor::convertInternalPageLink: Error converting internal link', ['wordpress_url' => 'http://93.184.216.34/site/brochure/', 'error' => 'the import failed']]]);
});

it('follows a link\'s redirect to the post it lands on', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/')));
    internalEntry('pages', 'about', 'about-12-abc');
    Http::fake([
        'http://93.184.216.34/site/old/' => Http::response('', 301, ['Location' => 'http://93.184.216.34/site/about/']),
        '*' => Http::response(''),
    ]);

    $converted = internalConvert('http://93.184.216.34/site/old/#team');

    expect($converted['result'])->toBe('statamic://entry::about-12-abc#team')
        ->and($converted['output'])->toBe('  - Converted internal link (via redirect): http://93.184.216.34/site/old/#team -> statamic://entry::about-12-abc#team'.PHP_EOL);
});

it('defers a redirected link to a post not imported yet', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/')));
    Http::fake([
        'http://93.184.216.34/site/old/' => Http::response('', 301, ['Location' => 'http://93.184.216.34/site/about/']),
        '*' => Http::response(''),
    ]);

    expect(internalConvert('http://93.184.216.34/site/old/', 'source-1')['result'])->toBeNull()
        ->and(LinkProcessor::getDeferredInternalLinks())->toHaveKey('source-1');
});

it('fetches the link once for its page ID and once for its redirect when neither finds it', function (int $status): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/')));
    Http::fake(['*' => Http::response('', $status)]);

    expect(internalConvert('http://93.184.216.34/site/old/')['result'])->toBe('http://93.184.216.34/site/old/');

    // A redirect back to the link, or a failed fetch, is not searched again
    Http::assertSentCount(2);
})->with([
    'no redirect' => [200],
    'a failed fetch' => [500],
]);

it('looks a post with no slug up by its WordPress ID, not by an empty slug', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, '<item><wp:post_id>12</wp:post_id><wp:post_name></wp:post_name><wp:post_type>page</wp:post_type></item>'));
    LinkProcessor::registerUrlMapping('http://93.184.216.34/site/?page_id=12', '12');
    internalEntry('pages', '', 'blank-99-abc');
    internalEntry('pages', 'draft', 'draft-12-abc');

    expect(internalConvert('http://93.184.216.34/site/?page_id=12')['result'])->toBe('statamic://entry::draft-12-abc?page_id=12');
});

it('looks a redirected post with no slug up by its WordPress ID, not by an empty slug', function (): void {
    ProcessingContext::setXmlPath(internalExport($this, '<item><wp:post_id>12</wp:post_id><wp:post_name></wp:post_name><wp:post_type>page</wp:post_type></item>'));
    LinkProcessor::registerUrlMapping('http://93.184.216.34/site/?page_id=12', '12');
    internalEntry('pages', '', 'blank-99-abc');
    internalEntry('pages', 'draft', 'draft-12-abc');
    Http::fake([
        'http://93.184.216.34/site/old/' => Http::response('', 301, ['Location' => 'http://93.184.216.34/site/?page_id=12']),
        '*' => Http::response(''),
    ]);

    expect(internalConvert('http://93.184.216.34/site/old/')['result'])->toBe('statamic://entry::draft-12-abc');
});

it('keeps and reports a link it cannot resolve', function (?string $redirect): void {
    ProcessingContext::setXmlPath(internalExport($this, internalItem('12', 'about', 'http://93.184.216.34/site/about/')));
    Http::fake([
        ...($redirect === null ? [] : ['http://93.184.216.34/site/old/?x=1' => Http::response('', 301, ['Location' => $redirect])]),
        '*' => Http::response(''),
    ]);

    expect(internalConvert('http://93.184.216.34/site/old/?x=1#team')['result'])->toBe('http://93.184.216.34/site/old/?x=1#team')
        ->and(internalUnresolved())->toBe(
            'Reporting unresolved internal links...'.PHP_EOL
            .'Unresolved internal links: 1'.PHP_EOL
            .'  - http://93.184.216.34/site/old/?x=1'.PHP_EOL
        );
})->with([
    'no redirect' => [null],
    'a redirect to a page not in the export' => ['http://93.184.216.34/site/gone/'],
]);

it('reads a page link\'s last segment as its slug', function (string $url, ?string $slug): void {
    $method = new ReflectionMethod(LinkProcessor::class, 'extractPageSlugFromUrl');

    expect($method->invoke(new LinkProcessor, $url, 'https://example.com/site'))->toBe($slug);
})->with([
    'a page' => ['https://example.com/site/about/', 'about'],
    'a child page' => ['https://example.com/site/about/team//', 'team'],
    'an encoded slug, as written' => ['https://example.com/site/caf%C3%A9/', 'caf%C3%A9'],
    'a file' => ['https://example.com/site/files/report.pdf', 'report.pdf'],
    'the site itself' => ['https://example.com/site/', null],
    'another site' => ['https://other.test/site/about/', null],
]);
