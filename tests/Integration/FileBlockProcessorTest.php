<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\FileBlockProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for FileBlockProcessor, which turns a `wp:file` block into a Bard `file` set.
 *
 * `canProcess()` accepts a comment node carrying `wp:file` and a JSON object.
 * `process()` imports the block's `href` through the file container (or keeps the
 * URL when file uploads are off), writes the set's values as `data-set-attr-*`
 * attributes, and points `processedNode` at the block's `wp-block-file` div. A block
 * with no `href`, or one whose import fails, logs a warning and returns null.
 *
 * @command  php vendor/bin/pest tests/Integration/FileBlockProcessorTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Parse an HTML fragment and return its first top-level node.
 *
 * @param  string  $html  The fragment to parse
 * @return DOMNode The fragment's first node
 */
function fileBlockOpeningNode(string $html): DOMNode
{
    $document = new DOMDocument;
    $document->loadHTML('<div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);

    $first = $document->getElementById('root')?->firstChild;

    if (! $first instanceof DOMNode) {
        throw new RuntimeException('Fixture has no nodes');
    }

    return $first;
}

/**
 * An asset importer built from config with the given file-upload switch.
 *
 * @param  bool  $files  Whether file uploads are on
 * @return AssetImporter The importer
 */
function fileBlockImporter(bool $files): AssetImporter
{
    // The importer reads its upload switches from config when it is built.
    config([
        'wordpress-import.upload_images' => true,
        'wordpress-import.upload_files' => $files,
    ]);

    return new AssetImporter;
}

/**
 * An asset importer whose uploads are on and whose importFile() returns the given path.
 *
 * @param  ?string  $path  What importFile() returns
 * @param  string  $url  The URL importFile() must be called with
 * @param  BufferedOutput  $output  The output importFile() must be given
 * @return AssetImporter The mocked importer
 */
function fileBlockImportingImporter(?string $path, string $url, BufferedOutput $output): AssetImporter
{
    $importer = Mockery::mock(AssetImporter::class);
    $importer->shouldReceive('getUploadFiles')->andReturnTrue();
    $importer->shouldReceive('importFile')
        ->once()
        ->with($url, 'default', $output, 'files', SameSitePolicy::SKIP_DIFFERENT_SITE, null, null, null, null)
        ->andReturn($path);

    return $importer;
}

/**
 * The file set markup the processor writes for an imported path and title.
 *
 * @param  string  $file  The data-set-attr-file value, already escaped
 * @param  string  $title  The data-set-attr-title value, already escaped
 * @return string The expected markup
 */
function fileBlockSet(string $file, string $title): string
{
    return '<div data-set-type="file"'.SetMarkupNonce::attribute()
        .' data-set-attr-file="'.$file.'" data-set-attr-title="'.$title.'"'
        .' data-set-attr-new_window_query="1" data-set-attr-download_button_query="1"></div>';
}

it('accepts only a wp:file comment that carries a JSON object', function (string $html, bool $expected): void {
    expect((new FileBlockProcessor)->canProcess(fileBlockOpeningNode($html), 'default', null))->toBe($expected);
})->with([
    'block with attributes' => ['<!-- wp:file {"id":5,"href":"https://example.test/a.pdf"} -->', true],
    'self-closing block' => ['<!-- wp:file {"href":"https://example.test/a.pdf"} /-->', true],
    'attributes over several lines' => ["<!-- wp:file {\"id\":5,\n\"href\":\"https://example.test/a.pdf\"} -->", true],
    'no attributes' => ['<!-- wp:file -->', false],
    'empty object' => ['<!-- wp:file {} -->', false],
    'another block' => ['<!-- wp:image {"id":5} -->', false],
    'closing comment' => ['<!-- /wp:file -->', false],
    'text after the object' => ['<!-- wp:file {"id":5} trailing -->', false],
    'element, not comment' => ['<div class="wp-block-file">wp:file {"id":5}</div>', false],
]);

it('keeps the original URL when file uploads are off and marks the block div processed', function (): void {
    $node = fileBlockOpeningNode(
        '<!-- wp:file {"href":"https://example.test/uploads/report.pdf"} -->'
        .'<p>between</p>'
        .'<div class="wp-block-other">other</div>'
        .'<div class="extra wp-block-file">first</div>'
        .'<div class="wp-block-file">second</div>'
    );
    $output = new BufferedOutput;

    $result = (new FileBlockProcessor)->process(
        $node,
        ['href' => 'https://example.test/uploads/report.pdf'],
        'default',
        fileBlockImporter(false),
        $output
    );

    expect($result)->toBeArray()
        ->and($result['html'] ?? null)->toBe(fileBlockSet('https://example.test/uploads/report.pdf', 'report.pdf'))
        ->and($result['skipCount'] ?? null)->toBe(2)
        ->and($result['processedNode'] ?? null)->toBeInstanceOf(DOMElement::class)
        ->and($result['processedNode']->textContent ?? null)->toBe('first')
        ->and($output->fetch())->toBe('  - File uploads disabled. Using original URL for wp:file block.'.PHP_EOL);
});

it('writes the imported path, and escapes the values it writes', function (): void {
    $url = 'https://example.test/uploads/a&b "c".pdf';
    $output = new BufferedOutput;

    $result = (new FileBlockProcessor)->process(
        fileBlockOpeningNode('<!-- wp:file {"id":5} -->'),
        ['href' => $url],
        'default',
        fileBlockImportingImporter('files/default/a&b.pdf', $url, $output),
        $output
    );

    expect($result)->toBe([
        'html' => fileBlockSet('files/default/a&amp;b.pdf', 'a&amp;b &quot;c&quot;.pdf'),
        'processedNode' => null,
        'skipCount' => 2,
    ]);
});

it('reads a scalar href as a string', function (): void {
    $output = new BufferedOutput;

    $result = (new FileBlockProcessor)->process(
        fileBlockOpeningNode('<!-- wp:file {"id":5} -->'),
        ['href' => 42],
        'default',
        fileBlockImportingImporter('files/default/42', '42', $output),
        $output
    );

    expect($result['html'] ?? null)->toBe(fileBlockSet('files/default/42', '42'));
});

it('returns the set without a processed node when the comment belongs to no document', function (): void {
    $result = (new FileBlockProcessor)->process(
        new DOMComment(' wp:file {"href":"https://example.test/a.pdf"} '),
        ['href' => 'https://example.test/a.pdf'],
        'default',
        fileBlockImporter(false),
        new BufferedOutput
    );

    expect($result)->toBe([
        'html' => fileBlockSet('https://example.test/a.pdf', 'a.pdf'),
        'processedNode' => null,
        'skipCount' => 2,
    ]);
});

it('warns and returns null when the block has no usable href', function (array $attributes, ?string $pageSlug, ?string $pageLink, string $suffix, string $slugContext): void {
    if ($pageLink !== null) {
        ProcessingContext::setWordPressPageLink($pageLink);
    }

    $warnings = [];
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$warnings): void {
        $warnings[] = [$message, $context];
    });

    $importer = Mockery::mock(AssetImporter::class);
    $importer->shouldNotReceive('importFile');

    $result = (new FileBlockProcessor)->process(
        fileBlockOpeningNode('<!-- wp:file {"id":5} -->'),
        $attributes,
        'default',
        $importer,
        new BufferedOutput,
        [],
        [],
        $pageSlug
    );

    $context = ['site' => 'default', 'pageSlug' => $slugContext, 'attributes' => $attributes];

    if ($pageLink !== null) {
        $context['entry_url'] = $pageLink;
    }

    expect($result)->toBeNull()
        ->and($warnings)->toBe([[
            'FileBlockProcessor::process: No file URL found in wp:file block'.$suffix,
            $context,
        ]]);
})->with([
    'no href, no slug' => [['id' => 5], null, null, '', 'none'],
    'empty href, with slug' => [['href' => ''], 'about', null, '', 'about'],
    'array href' => [['href' => ['https://example.test/a.pdf']], null, null, '', 'none'],
    'with a page link' => [['id' => 5], null, 'https://example.test/about/', ' (from entry: https://example.test/about/)', 'none'],
]);

it('warns and returns null when the import produces no path', function (?string $path): void {
    $warnings = [];
    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use (&$warnings): void {
        $warnings[] = [$message, $context];
    });

    $url = 'https://example.test/uploads/report.pdf';
    $output = new BufferedOutput;

    $result = (new FileBlockProcessor)->process(
        fileBlockOpeningNode('<!-- wp:file {"id":5} -->'),
        ['href' => $url],
        'default',
        fileBlockImportingImporter($path, $url, $output),
        $output
    );

    expect($result)->toBeNull()
        ->and($warnings)->toBe([[
            'FileBlockProcessor::process: Failed to import file for wp:file block',
            ['site' => 'default', 'file_url' => $url],
        ]]);
})->with([
    'null' => [null],
    'empty string' => [''],
]);
