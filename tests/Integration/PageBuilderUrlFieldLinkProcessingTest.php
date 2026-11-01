<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\GalleryParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\SideBySideParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * Page-builder URL fields that used to be stored raw now go through `LinkProcessor` (wordpress-importer-archive#1205).
 *
 * Three fields skipped link processing at import: SideBySide's `link_list[].url`, Gallery's
 * `more_button_url` on the ACF-block path, and Gallery's `block_description`, which reached
 * Bard without its links being processed. A deferred page-builder scan was meant to catch
 * such fields later and never reached one, so it was removed and the fields fixed at source.
 *
 * **The instrument is the scoped-run report, and it is chosen because it discriminates.** On
 * a run scoped to types that do not include a link's target, `convertInternalPageLink()`
 * records a `scope_unresolved_links` warning naming the URL. A value copied raw records
 * nothing. So a warning proves the field passed through link processing, and its absence
 * proves it did not. Each case carries a control: an external URL, which link processing
 * leaves alone and never reports.
 *
 * @command  php vendor/bin/pest tests/Integration/PageBuilderUrlFieldLinkProcessingTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
    MaterializationScope::clear();
    LinkProcessor::clearDeferredData();

    // No redirect lookup may leave the process: the unresolved path follows redirects first.
    Http::fake(['*' => Http::response('', 404)]);

    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('pb_url_', true);
    \mkdir($this->workingDirectory);

    // A one-item export: the page doing the linking, and nothing it links to.
    $this->exportPath = $this->workingDirectory.\DIRECTORY_SEPARATOR.'wxr.000.xml';
    \file_put_contents(
        $this->exportPath,
        '<?xml version="1.0" encoding="UTF-8"?>'
        .'<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel>'
        .'<wp:base_blog_url>https://example.test</wp:base_blog_url>'
        .'<item><title>Source</title><link>https://example.test/source/</link>'
        .'<wp:post_id>1</wp:post_id><wp:post_name>source</wp:post_name>'
        .'<wp:post_type>post</wp:post_type></item>'
        .'</channel></rss>'
    );

    ProcessingContext::setXmlPath($this->exportPath);

    // A scope that excludes every link target used below.
    MaterializationScope::set(['post', 'attachment']);

    $this->assetImporter = new AssetImporter;
    $this->assetImporter->setBaseBlogUrl('https://example.test');
});

afterEach(function (): void {
    ProcessingContext::clearXmlPath();
    MaterializationScope::clear();
    LinkProcessor::clearDeferredData();

    foreach ((array) \glob($this->workingDirectory.\DIRECTORY_SEPARATOR.'*') as $entry) {
        if (\is_string($entry)) {
            \unlink($entry);
        }
    }

    \rmdir($this->workingDirectory);
});

/**
 * The URLs link processing reported as unresolved on this scoped run.
 *
 * @return list<mixed> The reported URLs, in order
 */
function pageBuilderUrlReports(): array
{
    $rows = ImportWarningManager::getWarningsByCategory('migration_api')['scope_unresolved_links'] ?? [];

    $urls = [];

    foreach (\is_array($rows) ? $rows : [] as $row) {
        $urls[] = \is_array($row) ? ($row['url'] ?? null) : null;
    }

    return $urls;
}

/**
 * Drive SideBySide's private link-list conversion over one link.
 *
 * @param  string  $url  The link's URL
 * @param  AssetImporter  $assetImporter  The importer carrying the base blog URL
 * @return array<int|string, mixed> The converted link_list sets
 */
function sideBySideLinkList(string $url, AssetImporter $assetImporter): array
{
    $parser = new SideBySideParser;
    $method = new ReflectionMethod($parser, 'processLinkList');

    $result = $method->invoke(
        $parser,
        [['side_link_list_text' => 'A link', 'side_link_list_url' => ['url' => $url, 'target' => '']]],
        'default',
        $assetImporter,
        new BufferedOutput
    );

    return \is_array($result) ? $result : [];
}

/**
 * Drive Gallery's private field extraction on the ACF-block path.
 *
 * @param  array<string, mixed>  $data  The normalized gallery fields
 * @param  AssetImporter  $assetImporter  The importer carrying the base blog URL
 * @return array<int|string, mixed> The extracted fields
 */
function galleryAcfFields(array $data, AssetImporter $assetImporter): array
{
    $parser = new GalleryParser;
    $method = new ReflectionMethod($parser, 'extractGalleryFields');

    $result = $method->invoke($parser, $data, 'default', $assetImporter, new BufferedOutput, [], false);

    return \is_array($result) ? $result : [];
}

it('processes a SideBySide link-list URL through LinkProcessor', function (): void {
    $sets = sideBySideLinkList('https://example.test/unread-side-link/', $this->assetImporter);

    expect($sets)->toHaveCount(1)
        ->and(pageBuilderUrlReports())->toBe(['https://example.test/unread-side-link/']);
});

it('leaves an external SideBySide link-list URL as it was', function (): void {
    $sets = sideBySideLinkList('https://www.facebook.com/uamsagec/', $this->assetImporter);

    $first = $sets[0] ?? null;

    expect(\is_array($first) ? ($first['url'] ?? null) : null)->toBe('https://www.facebook.com/uamsagec/')
        ->and(pageBuilderUrlReports())->toBe([]);
});

it('processes the ACF-block gallery more button URL through LinkProcessor', function (): void {
    galleryAcfFields(
        ['gallery_more_button_url' => ['url' => 'https://example.test/unread-more/', 'target' => '']],
        $this->assetImporter
    );

    expect(pageBuilderUrlReports())->toBe(['https://example.test/unread-more/']);
});

it('leaves an external ACF-block gallery more button URL as it was', function (): void {
    $fields = galleryAcfFields(
        ['gallery_more_button_url' => ['url' => 'https://www.uams.edu/elsewhere/', 'target' => '_blank']],
        $this->assetImporter
    );

    expect($fields['moreButtonUrl'] ?? null)->toBe('https://www.uams.edu/elsewhere/')
        ->and($fields['moreButtonTarget'] ?? null)->toBeTrue()
        ->and(pageBuilderUrlReports())->toBe([]);
});

it('processes links in the gallery description before it becomes Bard', function (): void {
    galleryAcfFields(
        ['gallery_description' => '<p>See <a href="https://example.test/unread-description/">this page</a>.</p>'],
        $this->assetImporter
    );

    expect(pageBuilderUrlReports())->toBe(['https://example.test/unread-description/']);
});

it('keeps an external link in the gallery description', function (): void {
    $fields = galleryAcfFields(
        ['gallery_description' => '<p>See <a href="https://www.uams.edu/elsewhere/">this page</a>.</p>'],
        $this->assetImporter
    );

    expect(\json_encode($fields['description'] ?? null))->toContain('https:\/\/www.uams.edu\/elsewhere\/')
        ->and(pageBuilderUrlReports())->toBe([]);
});
