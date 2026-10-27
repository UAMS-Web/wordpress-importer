<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\MigrationApi\MaterializationScope;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocLocationsParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\FindADocProvidersParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * A scoped read reports what its scope cost, rather than losing it silently (wordpress-importer-archive#1179).
 *
 * `--content-type` narrows the migration API read to a closure of post types. Two readers
 * look items up in the export by something other than the type being imported: a link is
 * resolved by its target's URL whatever that target's type, and a Find-a-Doc filter is
 * resolved to an `expertise`, `location` or `provider` item. When the target's type was not
 * read, both fail exactly as a broken link or a deleted provider would. These tests pin that
 * such a miss is **reported on a scoped run** and **not reported on an unscoped one**, where
 * the same miss is a genuine absence.
 *
 * **The readers themselves are driven, not the helper alone.** A test of
 * `MaterializationScope` by itself would stay green with its call sites deleted, which is the
 * false green this file exists to prevent.
 *
 * @command  php vendor/bin/pest tests/Integration/ScopedReadReportingTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ImportWarningManager::clearWarnings();
    MaterializationScope::clear();
    LinkProcessor::clearDeferredData();

    // No redirect lookup may leave the process: the unresolved path follows redirects first.
    Http::fake(['*' => Http::response('', 404)]);

    $this->workingDirectory = \sys_get_temp_dir().\DIRECTORY_SEPARATOR.\uniqid('scope_rep_', true);
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
 * Drive `LinkProcessor`'s internal-link conversion for one URL.
 *
 * @param  string  $url  The internal link to convert
 * @return mixed What the conversion returned
 */
function scopedConvertLink(string $url): mixed
{
    $method = new ReflectionMethod(LinkProcessor::class, 'convertInternalPageLink');

    return $method->invoke(
        new LinkProcessor,
        $url,
        'default',
        new AssetImporter,
        new BufferedOutput
    );
}

/**
 * Drive a Find-a-Doc parser's post-name lookup against an export holding none of the items.
 *
 * @param  object  $parser  The parser instance
 * @param  string  $postType  The type the filter id refers to
 * @return mixed What the lookup returned
 */
function scopedFindADocLookup(object $parser, string $postType): mixed
{
    $method = new ReflectionMethod($parser, 'getPostName');

    return $method->invoke($parser, '42', $postType, []);
}

/**
 * The warnings a scoped read recorded under one type.
 *
 * @param  string  $type  The warning type within the `migration_api` category
 * @return list<array<int|string, mixed>> The recorded warnings
 */
function scopedWarnings(string $type): array
{
    $rows = ImportWarningManager::getWarningsByCategory('migration_api')[$type] ?? null;

    if (! \is_array($rows)) {
        return [];
    }

    $warnings = [];

    foreach ($rows as $row) {
        if (\is_array($row)) {
            $warnings[] = $row;
        }
    }

    return $warnings;
}

it('reports a link it cannot resolve on a scoped run', function (): void {
    MaterializationScope::set(['post', 'attachment']);

    $result = scopedConvertLink('https://example.test/a-page-this-run-did-not-read/');

    $warnings = scopedWarnings('scope_unresolved_links');

    // The link is left as it was, which is the unchanged behavior; what is new is saying so.
    expect($result)->toBe('https://example.test/a-page-this-run-did-not-read/')
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]['url'] ?? null)->toBe('https://example.test/a-page-this-run-did-not-read/')
        ->and($warnings[0]['read_post_types'] ?? null)->toBe(['post', 'attachment']);
});

it('says nothing extra about an unresolved link on an unscoped run', function (): void {
    // The negative control: on a whole-site read the same miss is a broken link, which the
    // processor already records, and a scope report would be false.
    $result = scopedConvertLink('https://example.test/a-page-this-run-did-not-read/');

    expect($result)->toBe('https://example.test/a-page-this-run-did-not-read/')
        ->and(scopedWarnings('scope_unresolved_links'))->toBe([]);
});

it('reports a Find-a-Doc filter whose type a scoped run did not read', function (object $parser, string $postType): void {
    MaterializationScope::set(['page', 'attachment']);

    expect(scopedFindADocLookup($parser, $postType))->toBeNull();

    $warnings = scopedWarnings('scope_unread_references');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['post_type'] ?? null)->toBe($postType)
        ->and($warnings[0]['post_id'] ?? null)->toBe('42')
        ->and($warnings[0]['reader'] ?? null)->toBe((new ReflectionClass($parser))->getShortName());
})->with([
    'locations parser, expertise' => [new FindADocLocationsParser, 'expertise'],
    'locations parser, location' => [new FindADocLocationsParser, 'location'],
    'providers parser, provider' => [new FindADocProvidersParser, 'provider'],
]);

it('stays quiet about a Find-a-Doc miss whose type WAS read', function (): void {
    // A miss on a type the run read is a genuine absence, not something the scope removed.
    MaterializationScope::set(['page', 'provider']);

    expect(scopedFindADocLookup(new FindADocProvidersParser, 'provider'))->toBeNull()
        ->and(scopedWarnings('scope_unread_references'))->toBe([]);
});

it('stays quiet about a Find-a-Doc miss on an unscoped run', function (): void {
    expect(scopedFindADocLookup(new FindADocLocationsParser, 'location'))->toBeNull()
        ->and(scopedWarnings('scope_unread_references'))->toBe([]);
});

it('reports the same unresolved link once, not once per occurrence', function (): void {
    // One URL linked from several fields or entries is one thing the scope cost, so it is
    // reported once.
    MaterializationScope::set(['post']);

    scopedConvertLink('https://example.test/a-page-this-run-did-not-read/');
    scopedConvertLink('https://example.test/a-page-this-run-did-not-read/');

    expect(scopedWarnings('scope_unresolved_links'))->toHaveCount(1);
});

it('reports two different unresolved links separately', function (): void {
    // The control for the deduplication: it must key on the URL, not swallow every report
    // after the first.
    MaterializationScope::set(['post']);

    scopedConvertLink('https://example.test/first-unread/');
    scopedConvertLink('https://example.test/second-unread/');

    expect(scopedWarnings('scope_unresolved_links'))->toHaveCount(2);
});
