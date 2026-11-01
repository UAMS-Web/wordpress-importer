<?php

declare(strict_types=1);

/**
 * Tests that `LinkProcessor::process()` rewrites each link in a piece of HTML:
 * a file link to its imported asset, a knowledge base link to its entry, and an
 * internal page link through the page link conversion; that `processUrlString()`
 * does the same for a bare URL; and that the knowledge base checks and the path
 * helpers they share read a URL as WordPress builds it.
 *
 * The protected methods are reached through a subclass, which also lets a test
 * replace what the path and knowledge base helpers return.
 *
 * @command  php vendor/bin/pest tests/Integration/LinkProcessorProcessTest.php
 */

use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\KbLinkRegistry;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A link processor whose path and knowledge base helpers a test can replace, and
 * whose protected methods it can call.
 */
class LinkProcessorProcessProbe extends LinkProcessor
{
    /**
     * Replaces extractPathFromUrl() when set.
     *
     * @var (Closure(string, string): ?string)|null
     */
    public ?Closure $path = null;

    /**
     * Replaces convertKbLink() when set.
     *
     * @var (Closure(string): ?string)|null
     */
    public ?Closure $kb = null;

    /**
     * {@inheritDoc}
     */
    protected function extractPathFromUrl(string $wordPressUrl, string $wordPressBaseBlogUrl): ?string
    {
        return $this->path instanceof Closure
            ? ($this->path)($wordPressUrl, $wordPressBaseBlogUrl)
            : parent::extractPathFromUrl($wordPressUrl, $wordPressBaseBlogUrl);
    }

    /**
     * {@inheritDoc}
     */
    protected function convertKbLink(string $wordPressUrl, string $wordPressBaseBlogUrl, OutputInterface $output): ?string
    {
        return $this->kb instanceof Closure
            ? ($this->kb)($wordPressUrl)
            : parent::convertKbLink($wordPressUrl, $wordPressBaseBlogUrl, $output);
    }

    /**
     * Call isKbLink().
     */
    public function kbLink(string $url, string $baseBlogUrl): bool
    {
        return $this->isKbLink($url, $baseBlogUrl);
    }

    /**
     * Call isInternalPageLink().
     */
    public function internalLink(string $url, string $baseBlogUrl): bool
    {
        return $this->isInternalPageLink($url, $baseBlogUrl);
    }

    /**
     * Call convertKbLink().
     */
    public function kbConvert(string $url, string $baseBlogUrl, BufferedOutput $output): ?string
    {
        return $this->convertKbLink($url, $baseBlogUrl, $output);
    }

    /**
     * Call extractPathFromUrl().
     */
    public function pathOf(string $url, string $baseBlogUrl): ?string
    {
        return $this->extractPathFromUrl($url, $baseBlogUrl);
    }

    /**
     * The export path the last process() call kept for this instance.
     */
    public function instanceXmlPath(): ?string
    {
        return $this->xmlPath;
    }
}

beforeEach(function (): void {
    // A link that falls through to fetching its page must not reach the network
    Http::preventStrayRequests();
    LinkProcessor::clearDeferredData();
    KbLinkRegistry::clear();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearMinervaKbSettings();
    (new ReflectionProperty(LinkProcessor::class, 'cachedXmlPath'))->setValue(null, null);
    config(['app.url' => 'https://statamic.test']);
});

afterEach(function (): void {
    LinkProcessor::clearDeferredData();
    KbLinkRegistry::clear();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearMinervaKbSettings();
    (new ReflectionProperty(LinkProcessor::class, 'cachedXmlPath'))->setValue(null, null);
});

/**
 * An asset importer for the site, which imports files to the given path (or fails
 * to, for null) and records each import.
 */
final class LinkProcessorProcessImporter extends AssetImporter
{
    /**
     * Each import: the URL, the site, the container and the same-site policy.
     *
     * @var list<array{string, string, string, SameSitePolicy}>
     */
    public array $imports = [];

    /**
     * Create the importer.
     */
    public function __construct(
        ?string $baseBlogUrl = 'https://example.com/site',
        private readonly bool $files = true,
        private readonly ?string $importedPath = null
    ) {
        parent::__construct($baseBlogUrl);
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
        $this->imports[] = [$url, $site, $container, $sameSitePolicy];

        return $this->importedPath;
    }
}

/**
 * An asset importer that fails any call, for a path that must not process.
 */
final class LinkProcessorProcessUntouchedImporter extends AssetImporter
{
    /**
     * {@inheritDoc}
     */
    public function getBaseBlogUrl(): ?string
    {
        throw new LogicException('The importer was asked for its base URL.');
    }
}

/**
 * An asset importer for the site.
 */
function processImporter(?string $baseBlogUrl = 'https://example.com/site', bool $uploadFiles = true, ?string $importedPath = null): LinkProcessorProcessImporter
{
    return new LinkProcessorProcessImporter($baseBlogUrl, $uploadFiles, $importedPath);
}

/**
 * Process the HTML for the site and return it with what was written.
 *
 * @return array{html: string, output: string}
 */
function processHtml(string $html, ?AssetImporter $importer = null, ?LinkProcessor $processor = null, ?string $xmlPath = null): array
{
    $output = new BufferedOutput;
    $html = ($processor ?? new LinkProcessor)->process($html, 'site_a', $importer ?? processImporter(), $output, null, null, $xmlPath);

    return ['html' => $html, 'output' => $output->fetch()];
}

/**
 * The internal links the processor reports as unresolved.
 */
function processUnresolved(): string
{
    $output = new BufferedOutput;
    LinkProcessor::processUnresolvedLinksForPostType('site_a', $output);

    return $output->fetch();
}

it('returns an empty URL unchanged without processing it', function (): void {
    // The importer fails the test if the URL is processed
    expect(LinkProcessor::processUrlString('', 'site_a', new LinkProcessorProcessUntouchedImporter, new BufferedOutput))->toBe('');
});

it('returns a URL processed as the href of a link', function (string $url, string $processed): void {
    ProcessingContext::setWordPressPageLink('https://example.com/site/about/');

    expect(LinkProcessor::processUrlString($url, 'site_a', processImporter(), new BufferedOutput))->toBe($processed);
})->with([
    'an external URL' => ['https://other.test/page/', 'https://other.test/page/'],
    'a link to a section of the current page' => ['https://example.com/site/about/#team', '#team'],
]);

it('returns blank HTML unchanged without processing it', function (string $html): void {
    expect((new LinkProcessor)->process($html, 'site_a', new LinkProcessorProcessUntouchedImporter, new BufferedOutput))->toBe($html);
})->with([
    'empty' => [''],
    'whitespace' => [" \n "],
]);

it('keeps the export path it is given, or the context\'s, for later links', function (?string $given, ?string $context, ?string $kept): void {
    (new ReflectionProperty(LinkProcessor::class, 'cachedXmlPath'))->setValue(null, 'earlier.xml');
    ProcessingContext::setXmlPath($context);
    $processor = new LinkProcessorProcessProbe;

    processHtml('<p>x</p>', processor: $processor, xmlPath: $given);

    expect($processor->instanceXmlPath())->toBe($given ?? $context)
        ->and((new ReflectionProperty(LinkProcessor::class, 'cachedXmlPath'))->getValue())->toBe($kept);
})->with([
    'a given path over the context\'s' => ['given.xml', 'context.xml', 'given.xml'],
    'the context\'s path' => [null, 'context.xml', 'context.xml'],
    'no path, keeping the earlier one' => [null, null, 'earlier.xml'],
]);

it('points a file link at its imported asset', function (): void {
    $importer = processImporter(importedPath: 'site_a/report.pdf');

    $result = processHtml('<a href="https://example.com/site/wp-content/uploads/report.pdf">r</a>', $importer);

    expect($result['html'])->toBe('<a href="https://statamic.test/files/site_a/report.pdf">r</a>')
        ->and($importer->imports)->toBe([['https://example.com/site/wp-content/uploads/report.pdf', 'site_a', 'files', SameSitePolicy::LINK_DIFFERENT_SITE]]);
});

it('points a file link at a path on the site when no app URL is set', function (): void {
    config(['app.url' => null]);

    $result = processHtml('<a href="https://example.com/site/wp-content/uploads/report.pdf">r</a>', processImporter(importedPath: 'site_a/report.pdf'));

    expect($result['html'])->toBe('<a href="/files/site_a/report.pdf">r</a>');
});

it('keeps a file link whose import fails', function (?string $importedPath): void {
    $result = processHtml('<a href="https://example.com/site/wp-content/uploads/report.pdf">r</a>', processImporter(importedPath: $importedPath));

    expect($result['html'])->toBe('<a href="https://example.com/site/wp-content/uploads/report.pdf">r</a>');
})->with([
    'no path' => [null],
    'an empty path' => [''],
]);

it('keeps every file link, saying so for each, when file uploads are off', function (): void {
    $html = '<a href="https://example.com/site/wp-content/uploads/a.pdf">a</a><a href="https://example.com/site/wp-content/uploads/b.docx">b</a>';

    $importer = processImporter(uploadFiles: false);

    $result = processHtml($html, $importer);

    expect($result['html'])->toBe($html)
        ->and($importer->imports)->toBe([])
        ->and($result['output'])->toBe(
            '  - File uploads disabled. Using original URL for linked file: a.pdf.'.PHP_EOL
            .'  - File uploads disabled. Using original URL for linked file: b.docx.'.PHP_EOL
        );
});

it('points a knowledge base link at its entry, keeping its query and fragment', function (string $href, string $converted): void {
    KbLinkRegistry::registerKbtopicMappingBySlug('billing', 'topic-1');
    KbLinkRegistry::registerKbPostMapping('pay-online', 'post-2');

    $result = processHtml("<a href=\"{$href}\">k</a>");

    expect($result['html'])->toBe("<a href=\"{$converted}\">k</a>")
        ->and($result['output'])->toBe("  - Converted KB link: {$href} -> {$converted}".PHP_EOL);
})->with([
    'a topic' => ['https://example.com/site/kbtopic/billing/', 'statamic://entry::topic-1'],
    'an article with a query and a fragment' => ['https://example.com/site/kb/pay-online/?ref=1#steps', 'statamic://entry::post-2?ref=1#steps'],
]);

it('keeps a knowledge base link that converts to nothing', function (?string $converted): void {
    $processor = new LinkProcessorProcessProbe;
    $processor->kb = static fn (string $url): ?string => $converted;

    expect(processHtml('<a href="https://example.com/site/kb/x/">k</a>', processor: $processor)['html'])
        ->toBe('<a href="https://example.com/site/kb/x/">k</a>');
})->with([
    'null' => [null],
    'an empty string' => [''],
]);

it('leaves links alone when the export has no base URL', function (?string $baseBlogUrl): void {
    KbLinkRegistry::registerKbPostMapping('pay-online', 'post-2');
    ProcessingContext::setWordPressPageLink('https://example.com/site/about/');
    // A relative link reads as a site path once the base URL is empty
    $html = '<a href="https://example.com/site/kb/pay-online/">k</a><a href="kb/pay-online/">r</a><a href="https://example.com/site/about/#team">t</a>';

    expect(processHtml($html, processImporter($baseBlogUrl))['html'])->toBe($html);
})->with([
    'null' => [null],
    'empty' => [''],
]);

it('points an internal link to a section of the current page at the section', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.com/site/about/');

    $result = processHtml('<a href="https://example.com/site/about/#team">t</a>');

    expect($result['html'])->toBe('<a href="#team">t</a>');
});

it('keeps an internal link to the current page itself', function (): void {
    // Its conversion is an empty href, which is not applied
    ProcessingContext::setWordPressPageLink('https://example.com/site/about/');

    expect(processHtml('<a href="https://example.com/site/about/">t</a>')['html'])->toBe('<a href="https://example.com/site/about/">t</a>');
});

it('recognizes a knowledge base link by its topic or article prefix', function (string $url, bool $isKb): void {
    expect((new LinkProcessorProcessProbe)->kbLink($url, 'https://example.com/site'))->toBe($isKb);
})->with([
    'a topic' => ['https://example.com/site/kbtopic/billing/', true],
    'an article' => ['http://example.com/site/kb/pay-online', true],
    'a page' => ['https://example.com/site/about/', false],
    'a prefix without its slash' => ['https://example.com/site/kb', false],
    'a longer first segment' => ['https://example.com/site/kbtopics/billing/', false],
    'a prefix later in the path' => ['https://example.com/site/about/kb/x/', false],
    'another site' => ['https://other.test/site/kb/x/', false],
    'a path joined to the site\'s' => ['https://example.com/sitekb/x/', false],
    'a host in other case, which has no path' => ['https://EXAMPLE.com/site/kb/x/', false],
]);

it('reads the knowledge base prefixes from the MinervaKB settings', function (array $settings, string $url, bool $isKb): void {
    ProcessingContext::setMinervaKbSettings($settings);

    expect((new LinkProcessorProcessProbe)->kbLink($url, 'https://example.com/site'))->toBe($isKb);
})->with([
    'a custom topic prefix' => [['category_slug' => 'help-topic'], 'https://example.com/site/help-topic/billing/', true],
    'a custom article prefix' => [['article_slug' => 'help'], 'https://example.com/site/help/pay/', true],
    'the default topic prefix replaced' => [['category_slug' => 'help-topic'], 'https://example.com/site/kbtopic/billing/', false],
    'an empty setting, which keeps the default' => [['article_slug' => ''], 'https://example.com/site/kb/pay/', true],
    'a setting that is not a string' => [['article_slug' => 5], 'https://example.com/site/kb/pay/', true],
]);

it('does not take a Statamic entry reference for a knowledge base or internal link', function (): void {
    // A base URL ending the reference's prefix makes the rest of it a site path
    $probe = new LinkProcessorProcessProbe;

    expect($probe->kbLink('statamic://entry::/kb/x', 'statamic://entry::'))->toBeFalse()
        ->and($probe->internalLink('statamic://entry::/about', 'statamic://entry::'))->toBeFalse()
        ->and($probe->kbLink('https://example.com/kb/x', 'https://example.com'))->toBeTrue()
        ->and($probe->internalLink('https://example.com/about', 'https://example.com'))->toBeTrue();
});

it('does not take a URL with no path for a knowledge base link', function (?string $path): void {
    $probe = new LinkProcessorProcessProbe;
    $probe->path = static fn (string $url, string $baseBlogUrl): ?string => $path;

    expect($probe->kbLink('https://example.com/site/kb/x/', 'https://example.com/site'))->toBeFalse();
})->with([
    'no path' => [null],
    'an empty path' => [''],
]);

it('caches a converted knowledge base link by its URL without the fragment', function (): void {
    KbLinkRegistry::registerKbPostMapping('pay', 'post-2');
    $probe = new LinkProcessorProcessProbe;
    $output = new BufferedOutput;

    $first = $probe->kbConvert('https://example.com/site/kb/pay/#a', 'https://example.com/site', $output);
    KbLinkRegistry::clear();
    $second = $probe->kbConvert('https://example.com/site/kb/pay/#b', 'https://example.com/site', $output);

    // The cached reference carries the first link's fragment
    expect($first)->toBe('statamic://entry::post-2#a')
        ->and($second)->toBe('statamic://entry::post-2#a')
        ->and($output->fetch())->toBe(
            '  - Converted KB link: https://example.com/site/kb/pay/#a -> statamic://entry::post-2#a'.PHP_EOL
            .'  - Using cached result for KB link: https://example.com/site/kb/pay/#b -> statamic://entry::post-2#a'.PHP_EOL
        );
});

it('caches a converted knowledge base link apart from the same link with another query', function (): void {
    KbLinkRegistry::registerKbPostMapping('pay', 'post-2');
    $probe = new LinkProcessorProcessProbe;
    $output = new BufferedOutput;

    $withQuery = $probe->kbConvert('https://example.com/site/kb/pay/?x=1', 'https://example.com/site', $output);
    $withOther = $probe->kbConvert('https://example.com/site/kb/pay/?x=2', 'https://example.com/site', $output);
    $without = $probe->kbConvert('https://example.com/site/kb/pay/', 'https://example.com/site', $output);

    expect([$withQuery, $withOther, $without])->toBe(['statamic://entry::post-2?x=1', 'statamic://entry::post-2?x=2', 'statamic://entry::post-2']);
});

it('reports an unresolved knowledge base link by its URL without the fragment', function (string $url, string $reported): void {
    expect((new LinkProcessorProcessProbe)->kbConvert($url, 'https://example.com/site', new BufferedOutput))->toBeNull()
        ->and(processUnresolved())->toBe(
            'Reporting unresolved internal links...'.PHP_EOL
            .'Unresolved internal links: 1'.PHP_EOL
            ."  - {$reported}".PHP_EOL
        );
})->with([
    'a topic' => ['https://example.com/site/kbtopic/missing/#x', 'https://example.com/site/kbtopic/missing/'],
    'an article with a query' => ['https://example.com/site/kb/missing?ref=1#x', 'https://example.com/site/kb/missing?ref=1'],
    'an empty path after the prefix' => ['https://example.com/site/kb/', 'https://example.com/site/kb/'],
]);

it('reports a knowledge base link whose entry ID is empty as unresolved', function (): void {
    KbLinkRegistry::registerKbPostMapping('pay', '');

    expect((new LinkProcessorProcessProbe)->kbConvert('https://example.com/site/kb/pay', 'https://example.com/site', new BufferedOutput))->toBeNull()
        ->and(processUnresolved())->toContain('  - https://example.com/site/kb/pay'.PHP_EOL);
});

it('converts nothing, and reports nothing, for an empty URL or one with no path', function (string $url, ?string $path): void {
    $probe = new LinkProcessorProcessProbe;

    if ($path !== 'real') {
        $probe->path = static fn (string $url, string $baseBlogUrl): ?string => $path;
    }

    expect($probe->kbConvert($url, '', new BufferedOutput))->toBeNull()
        ->and(processUnresolved())->toBe('');
})->with([
    'an empty URL' => ['', 'real'],
    'no path' => ['https://example.com/kb/x', null],
    'an empty path' => ['https://example.com/kb/x', ''],
]);

it('reads the path below the site, with its leading slash', function (string $url, ?string $path): void {
    expect((new LinkProcessorProcessProbe)->pathOf($url, 'https://example.com/site'))->toBe($path);
})->with([
    'a page' => ['https://example.com/site/about/', '/about/'],
    'over plain http' => ['http://example.com/site/about', '/about'],
    'protocol-relative' => ['//example.com/site/about', '/about'],
    'a query and a fragment' => ['https://example.com/site/about/?x=1#top', '/about/'],
    'the site itself' => ['https://example.com/site', '/'],
    'the site with only a query' => ['https://example.com/site?', '/'],
    'the site with only a fragment' => ['https://example.com/site#', '/'],
    'a path joined to the site\'s' => ['https://example.com/siteabout', '/about'],
    'another site' => ['https://other.test/site/about', null],
]);

it('recognizes an internal page link by the site and a slug without a dot', function (string $url, bool $isInternal): void {
    expect((new LinkProcessorProcessProbe)->internalLink($url, 'https://example.com/site'))->toBe($isInternal);
})->with([
    'a page' => ['https://example.com/site/about/', true],
    'a file' => ['https://example.com/site/report.pdf', false],
    'the site itself' => ['https://example.com/site/', false],
    'another site' => ['https://other.test/site/about/', false],
    'a path joined to the site\'s' => ['https://example.com/siteabout', false],
    'a host in other case, which has no slug' => ['https://EXAMPLE.com/site/about/', false],
]);

it('lists unresolved links in alphabetical order, ignoring case', function (): void {
    $probe = new LinkProcessorProcessProbe;
    $probe->kbConvert('https://example.com/site/kb/b', 'https://example.com/site', new BufferedOutput);
    $probe->kbConvert('https://example.com/site/kb/B', 'https://example.com/site', new BufferedOutput);
    $probe->kbConvert('https://example.com/site/kb/a', 'https://example.com/site', new BufferedOutput);

    expect(processUnresolved())->toBe(
        'Reporting unresolved internal links...'.PHP_EOL
        .'Unresolved internal links: 3'.PHP_EOL
        .'  - https://example.com/site/kb/a'.PHP_EOL
        .'  - https://example.com/site/kb/b'.PHP_EOL
        .'  - https://example.com/site/kb/B'.PHP_EOL
    );
});
