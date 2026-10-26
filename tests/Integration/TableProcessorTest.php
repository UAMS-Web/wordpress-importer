<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TableProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests for TableProcessor, which turns a core `wp:table` block into a Bard `table` set.
 *
 * `canProcess()` accepts a `wp:table` comment, with or without a JSON object.
 * `process()` reads the first `wp-block-table` figure after the comment: header,
 * body and footer rows become `table_data`, and the table's classes, the figure's
 * classes and its caption become the set's flags, alignment and caption. A missing
 * figure, a figure with no table and a table with no usable rows each log a warning
 * and return null.
 *
 * @command  php vendor/bin/pest tests/Integration/TableProcessorTest.php
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
 * libxml does not know `figure` or `figcaption`, so its errors are collected
 * rather than raised.
 *
 * @param  string  $html  The fragment to parse
 * @return DOMNode The fragment's first node
 */
function tableOpeningNode(string $html): DOMNode
{
    $previous = libxml_use_internal_errors(true);
    $document = new DOMDocument;
    $document->loadHTML('<div id="root">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $first = $document->getElementById('root')?->firstChild;

    if (! $first instanceof DOMNode) {
        throw new RuntimeException('Fixture has no nodes');
    }

    return $first;
}

/**
 * Run the processor over a node.
 *
 * @param  DOMNode  $node  The opening comment
 * @param  array<int|string, mixed>  $attributes  The block's attributes
 * @param  ?string  $pageSlug  The page slug
 * @return array<int|string, mixed>|string|null The processor's result
 */
function processTable(DOMNode $node, array $attributes = [], ?string $pageSlug = null): array|string|null
{
    return (new TableProcessor)->process(
        $node,
        $attributes,
        'default',
        (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor(),
        new BufferedOutput,
        [],
        [],
        $pageSlug
    );
}

/**
 * Collect every Log::warning() call as a [message, context] pair.
 *
 * @return ArrayObject<int, array{0: string, 1: array<string, mixed>}> The calls, filled as they happen
 */
function tableWarnings(): ArrayObject
{
    /** @var ArrayObject<int, array{0: string, 1: array<string, mixed>}> $warnings */
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(function (string $message, array $context) use ($warnings): void {
        $warnings->append([$message, $context]);
    });

    return $warnings;
}

it('accepts only a wp:table comment', function (string $html, bool $expected): void {
    expect((new TableProcessor)->canProcess(tableOpeningNode($html), 'default', null))->toBe($expected);
})->with([
    'bare block' => ['<!-- wp:table -->', true],
    'block with attributes' => ['<!-- wp:table {"hasFixedLayout":true} -->', true],
    'attributes over several lines' => ["<!-- wp:table {\"className\":\n\"is-style-stripes\"} -->", true],
    'empty object' => ['<!-- wp:table {} -->', false],
    'closing comment' => ['<!-- /wp:table -->', false],
    'another block with the same prefix' => ['<!-- wp:table-of-contents -->', false],
    'TablePress block' => ['<!-- wp:tablepress/table {"id":"1"} -->', false],
    'element, not comment' => ['<p>wp:table</p>', false],
]);

it('maps every section, flag and the caption into a table set', function (): void {
    $node = tableOpeningNode(
        '<!-- wp:table {"align":"wide"} -->'
        .'<figure class="wp-block-other"><table><tbody><tr><td>wrong figure</td></tr></tbody></table></figure>'
        .'<figure class="wp-block-table is-style-stripes">'
        .'<table class="has-fixed-layout">'
        .'<thead><tr><th>Name</th><th>Role</th></tr></thead>'
        .'<tbody><tr><td> <strong>Ann</strong> O\'Hara </td><td>Chair</td></tr><tr><td>Bo</td><td>Member</td></tr></tbody>'
        .'<tfoot><tr><td>Total</td><td>2</td></tr></tfoot>'
        .'</table>'
        .'<table><tbody><tr><td>second table</td></tr></tbody></table>'
        .'<figcaption>  The committee  </figcaption>'
        .'</figure>'
    );

    $result = processTable($node, ['align' => 'wide']);

    expect($result)->toBeArray()
        ->and($result['html'] ?? null)->toBe(
            '<div data-set-type="table"'.SetMarkupNonce::attribute()
            .' data-set-attr-type="table" data-set-attr-align="alignwide" data-set-attr-striped="true"'
            .' data-set-attr-first_row_headers="true" data-set-attr-first_column_headers="false"'
            .' data-set-attr-last_row_footers="true" data-set-attr-fixed_width_table_cells="true"'
            ." data-set-attr-table_data='[{&quot;cells&quot;:[&quot;Name&quot;,&quot;Role&quot;]},"
            .'{&quot;cells&quot;:[&quot;&lt;strong&gt;Ann&lt;\/strong&gt; O&#039;Hara&quot;,&quot;Chair&quot;]},'
            .'{&quot;cells&quot;:[&quot;Bo&quot;,&quot;Member&quot;]},'
            ."{&quot;cells&quot;:[&quot;Total&quot;,&quot;2&quot;]}]'"
            .' data-set-attr-caption="The committee"'
            .' data-set-boolean-fields="first_row_headers,first_column_headers,last_row_footers,striped,fixed_width_table_cells"></div>'
        )
        ->and($result['skipCount'] ?? null)->toBe(3)
        ->and($result['processedNode'] ?? null)->toBeInstanceOf(DOMElement::class)
        ->and(($result['processedNode'] ?? null)?->getAttribute('class'))->toBe('wp-block-table is-style-stripes');
});

it('reads alignment from the figure class and leaves every flag off for a plain body', function (): void {
    $result = processTable(tableOpeningNode(
        '<!-- wp:table -->'
        .'<figure class="wp-block-table alignfull"><table class="has-fixed-layouts"><tbody><tr><td>A</td></tr></tbody></table>'
        .'<figcaption>   </figcaption></figure>'
    ));

    expect($result['html'] ?? null)->toBe(
        '<div data-set-type="table"'.SetMarkupNonce::attribute()
        .' data-set-attr-type="table" data-set-attr-align="alignwide" data-set-attr-striped="false"'
        .' data-set-attr-first_row_headers="false" data-set-attr-first_column_headers="false"'
        .' data-set-attr-last_row_footers="false" data-set-attr-fixed_width_table_cells="false"'
        ." data-set-attr-table_data='[{&quot;cells&quot;:[&quot;A&quot;]}]'"
        .' data-set-boolean-fields="first_row_headers,first_column_headers,last_row_footers,striped,fixed_width_table_cells"></div>'
    );
});

it('centers a table whose align attribute is not a string', function (): void {
    $result = processTable(
        tableOpeningNode('<!-- wp:table --><figure class="wp-block-table alignwide"><table><tbody><tr><td>A</td></tr></tbody></table></figure>'),
        ['align' => ['wide']]
    );

    expect($result['html'] ?? null)->toContain(' data-set-attr-align="aligncenter" ');
});

it('keeps rows that have cells and drops the ones that do not', function (): void {
    $result = processTable(tableOpeningNode(
        '<!-- wp:table --><figure class="wp-block-table"><table>'
        .'<thead><tr><td>not a header cell</td></tr><tr><th>H</th></tr></thead>'
        .'<tbody><tr></tr><tr><th>not a body cell</th></tr><tr><td>B</td></tr></tbody>'
        .'<tfoot><tr><th>not a footer cell</th></tr><tr><td>F</td></tr></tfoot>'
        .'</table></figure>'
    ));

    expect($result['html'] ?? null)->toContain(
        " data-set-attr-table_data='[{&quot;cells&quot;:[&quot;H&quot;]},{&quot;cells&quot;:[&quot;B&quot;]},{&quot;cells&quot;:[&quot;F&quot;]}]'"
    );
});

it('warns and returns null when no rows have cells', function (): void {
    $warnings = tableWarnings();
    $node = tableOpeningNode(
        '<!-- wp:table --><figure class="wp-block-table">'
        .'<table><thead><tr><td>header row with no header cell, long enough to pass the snippet limit</td></tr></thead>'
        .'<tbody><tr></tr></tbody><tfoot><tr><th>footer</th></tr></tfoot></table></figure>'
    );
    $figure = $node->nextSibling;
    $snippet = $figure instanceof DOMNode ? (string) $node->ownerDocument?->saveHTML($figure) : '';

    expect(processTable($node, [], 'about'))->toBeNull()
        ->and(strlen($snippet))->toBeGreaterThan(100)
        ->and($warnings->getArrayCopy())->toBe([[
            'TableProcessor::process: No valid table rows parsed in wp:table block',
            ['site' => 'default', 'pageSlug' => 'about', 'node_html' => substr($snippet, 0, 100).'...'],
        ]]);
});

it('warns and returns null when the figure holds no table', function (): void {
    ProcessingContext::setWordPressPageLink('https://example.test/about/');
    $warnings = tableWarnings();

    expect(processTable(tableOpeningNode('<!-- wp:table --><figure class="wp-block-table"><p>no table</p></figure>')))->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([[
            'TableProcessor::process: No table found in wp:table block (from entry: https://example.test/about/)',
            [
                'site' => 'default',
                'pageSlug' => 'none',
                'node_html' => '<figure class="wp-block-table"><p>no table</p></figure>...',
                'entry_url' => 'https://example.test/about/',
            ],
        ]]);
});

it('warns and returns null when no wp-block-table figure follows the comment', function (string $html): void {
    $warnings = tableWarnings();

    expect(processTable(tableOpeningNode($html), ['align' => 'wide']))->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([[
            'TableProcessor::process: No valid wp:table figure found',
            ['site' => 'default', 'pageSlug' => 'none', 'attributes' => ['align' => 'wide']],
        ]]);
})->with([
    'nothing after the comment' => ['<!-- wp:table -->'],
    'a figure of another block' => ['<!-- wp:table --><figure class="wp-block-image"><table><tbody><tr><td>A</td></tr></tbody></table></figure>'],
]);

it('returns null for a comment that belongs to no document', function (): void {
    $warnings = tableWarnings();

    expect(processTable(new DOMComment(' wp:table ')))->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([]);
});
