<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\TableProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

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
        ->and(($result['processedNode'] ?? null) instanceof DOMElement ? $result['processedNode']->getAttribute('class') : null)->toBe('wp-block-table is-style-stripes');
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

it('centers a left-aligned table and escapes its caption', function (): void {
    $result = processTable(
        tableOpeningNode(
            '<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>A</td></tr></tbody></table>'
            .'<figcaption>"A" &amp; B</figcaption></figure>'
        ),
        ['align' => 'left']
    );

    expect($result['html'] ?? null)->toContain(' data-set-attr-align="aligncenter" ')
        ->toContain(' data-set-attr-caption="&quot;A&quot; &amp; B" ');
});

it('keeps every row that has cells, of either kind, and drops the ones that do not', function (): void {
    $result = processTable(tableOpeningNode(
        '<!-- wp:table --><figure class="wp-block-table"><table>'
        .'<thead><tr><td>not a header cell</td></tr><tr><th>H</th></tr></thead>'
        .'<tbody><tr></tr><tr><th>not a body cell</th></tr><tr><td>B</td></tr></tbody>'
        .'<tfoot><tr><th>not a footer cell</th></tr><tr><td>F</td></tr></tfoot>'
        .'</table></figure>'
    ));

    expect($result['html'] ?? null)->toContain(
        " data-set-attr-table_data='[{&quot;cells&quot;:[&quot;not a header cell&quot;]},{&quot;cells&quot;:[&quot;H&quot;]},{&quot;cells&quot;:[&quot;not a body cell&quot;]},{&quot;cells&quot;:[&quot;B&quot;]},{&quot;cells&quot;:[&quot;not a footer cell&quot;]},{&quot;cells&quot;:[&quot;F&quot;]}]'"
    )->not->toContain('data-set-attr-caption');
});

it('warns and returns null when no rows have cells', function (): void {
    $warnings = tableWarnings();
    $node = tableOpeningNode(
        '<!-- wp:table --><figure class="wp-block-table">'
        .'<table class="a table with rows but no cells, long enough to pass the snippet limit"><thead><tr></tr></thead>'
        .'<tbody><tr></tr></tbody><tfoot><tr></tr></tfoot></table></figure>'
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

/**
 * Render the first table in the HTML as a set, returning what the set's markup says.
 *
 * @return array{rows: mixed, html: string}
 */
function renderedTable(string $html): array
{
    $document = TextProcessor::loadHtml($html);
    $table = $document->getElementsByTagName('table')->item(0);

    if (! $table instanceof DOMElement) {
        throw new RuntimeException('No table in the HTML.');
    }

    $set = (new TableProcessor)->renderSet($table) ?? '';
    $rendered = TextProcessor::loadHtml($set)->getElementsByTagName('div')->item(1);
    $data = $rendered instanceof DOMElement ? json_decode($rendered->getAttribute('data-set-attr-table_data'), true) : [];

    return [
        'rows' => data_get($data, '*.cells'),
        'html' => $set,
    ];
}

it('reads a plain table whatever its sections', function (string $html, array $rows): void {
    expect(renderedTable($html)['rows'])->toBe($rows);
})->with([
    'rows with no section' => ['<table><tr><td>1</td><td>2</td></tr><tr><td>3</td><td>4</td></tr></table>', [['1', '2'], ['3', '4']]],
    'every body' => ['<table><tbody><tr><td>1</td></tr></tbody><tbody><tr><td>2</td></tr></tbody></table>', [['1'], ['2']]],
    'a foot written ahead of the body' => ['<table><tfoot><tr><td>F</td></tr></tfoot><tbody><tr><td>B</td></tr></tbody><thead><tr><th>H</th></tr></thead></table>', [['H'], ['B'], ['F']]],
    'a br in a cell' => ['<table><tbody><tr><th>Weight<br>(kg)</th><th>Dose</th></tr></tbody></table>', [['Weight<br>(kg)', 'Dose']]],
    'a nested table, left in its cell' => ['<table><tbody><tr><td><table><tbody><tr><td>in</td></tr></tbody></table></td><td>out</td></tr></tbody></table>', [['<table><tbody><tr><td>in</td></tr></tbody></table>', 'out']]],
    'rows of different widths' => ['<table><tbody><tr><td>1</td><td>2</td></tr><tr><td>3</td></tr></tbody></table>', [['1', '2'], ['3', '']]],
    'a colspan' => ['<table><tbody><tr><td colspan="2">1</td><td>2</td></tr><tr><td>3</td><td>4</td><td>5</td></tr></tbody></table>', [['1', '', '2'], ['3', '4', '5']]],
    'a rowspan' => ['<table><tbody><tr><td rowspan="2">1</td><td>2</td></tr><tr><td>3</td></tr><tr><td>4</td><td>5</td></tr></tbody></table>', [['1', '2'], ['', '3'], ['4', '5']]],
    'a rowspan in the last column' => ['<table><tbody><tr><td>1</td><td rowspan="2">2</td></tr><tr><td>3</td></tr></tbody></table>', [['1', '2'], ['3', '']]],
    'a span of both kinds' => ['<table><tbody><tr><td rowspan="2" colspan="2">1</td><td>2</td></tr><tr><td>3</td></tr></tbody></table>', [['1', '', '2'], ['', '', '3']]],
    'a rowspan past the last row' => ['<table><tbody><tr><td rowspan="9">1</td><td>2</td></tr></tbody></table>', [['1', '2']]],
    'a span that is not a number' => ['<table><tbody><tr><td colspan="x" rowspan="0">1</td><td>2</td></tr></tbody></table>', [['1', '2']]],
]);

it('holds a colspan to the most HTML allows', function (): void {
    expect(data_get(renderedTable('<table><tbody><tr><td colspan="99999">1</td></tr></tbody></table>'), 'rows.0'))->toHaveCount(1000);
});

it('marks a first row of header cells as a header row, with or without a thead', function (string $html, string $expected): void {
    expect(renderedTable($html)['html'])->toContain(' data-set-attr-first_row_headers="'.$expected.'"');
})->with([
    'header cells, no thead' => ['<table><tbody><tr><th>H</th><th>I</th></tr><tr><td>1</td><td>2</td></tr></tbody></table>', 'true'],
    'a thead' => ['<table><thead><tr><td>H</td></tr></thead><tbody><tr><td>1</td></tr></tbody></table>', 'true'],
    'mixed cells' => ['<table><tbody><tr><th>H</th><td>I</td></tr></tbody></table>', 'false'],
    'data cells' => ['<table><tbody><tr><td>1</td></tr></tbody></table>', 'false'],
]);

it('reads a plain table caption and footer', function (): void {
    expect(renderedTable('<table><caption> Doses </caption><tbody><tr><td>1</td></tr></tbody><tfoot><tr><td>F</td></tr></tfoot></table>')['html'])
        ->toContain(' data-set-attr-caption="Doses"')
        ->toContain(' data-set-attr-last_row_footers="true"');
});

it('renders nothing for a table with no cells, and marks no footer on an empty one', function (): void {
    $document = TextProcessor::loadHtml('<table><tfoot><tr></tr></tfoot></table>');
    $table = $document->getElementsByTagName('table')->item(0);

    expect($table instanceof DOMElement ? (new TableProcessor)->renderSet($table) : 'no table')->toBeNull();
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

it('cuts the snippet of a figure with no table to 100 characters and names the page', function (): void {
    $warnings = tableWarnings();
    $node = tableOpeningNode(
        '<!-- wp:table --><figure class="wp-block-table"><p>no table here, only a paragraph long enough to pass the snippet limit</p></figure>'
    );
    $figure = $node->nextSibling;
    $snippet = $figure instanceof DOMNode ? (string) $node->ownerDocument?->saveHTML($figure) : '';

    expect(processTable($node, [], 'about'))->toBeNull()
        ->and(strlen($snippet))->toBeGreaterThan(100)
        ->and($warnings->getArrayCopy())->toBe([[
            'TableProcessor::process: No table found in wp:table block',
            ['site' => 'default', 'pageSlug' => 'about', 'node_html' => substr($snippet, 0, 100).'...'],
        ]]);
});

it('names the page when no wp-block-table figure follows the comment', function (): void {
    $warnings = tableWarnings();

    expect(processTable(tableOpeningNode('<!-- wp:table -->'), [], 'about'))->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([[
            'TableProcessor::process: No valid wp:table figure found',
            ['site' => 'default', 'pageSlug' => 'about', 'attributes' => []],
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

it('takes no figure from past the block\'s closing comment or from the block after it', function (string $html): void {
    $warnings = tableWarnings();

    // #197: the figure lookup stops where the block ends
    expect(processTable(tableOpeningNode($html)))->toBeNull()
        ->and(array_column($warnings->getArrayCopy(), 0))->toBe(['TableProcessor::process: No valid wp:table figure found']);
})->with([
    'a figure after the closing comment' => ['<!-- wp:table --><!-- /wp:table --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure>'],
    'a figure in the block after an unclosed one' => ['<!-- wp:table --><!-- wp:group --><div><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure></div><!-- /wp:group --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure>'],
]);

it('skips the block\'s own nodes, counting neither whitespace nor what follows the closing comment', function (string $html, int $skipCount): void {
    tableWarnings();

    expect(processTable(tableOpeningNode($html))['skipCount'] ?? null)->toBe($skipCount);
})->with([
    'the figure alone' => ['<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure><!-- /wp:table --><p>After</p>', 3],
    'the figure among whitespace' => ["<!-- wp:table -->\n<figure class=\"wp-block-table\"><table><tbody><tr><td>T</td></tr></tbody></table></figure>\n<!-- /wp:table -->\n<p>After</p>", 3],
    'content ahead of the figure' => ['<!-- wp:table --><p>A</p><p>B</p><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure><!-- /wp:table --><p>After</p>', 5],
    'content after the figure' => ['<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure><p>A</p><!-- /wp:table --><p>After</p>', 4],
    'a block that is never closed, before another block' => ['<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure><!-- wp:paragraph --><p>Next</p><!-- /wp:paragraph -->', 2],
]);

it('warns of the content a table block holds besides its figure', function (): void {
    $warnings = tableWarnings();

    processTable(tableOpeningNode('<!-- wp:table --><p>A</p>text<!-- a note --><figure class="wp-block-table"><table><tbody><tr><td>T</td></tr></tbody></table></figure><figure class="wp-block-table"><table><tbody><tr><td>U</td></tr></tbody></table></figure><!-- /wp:table -->'), [], 'about');

    // The note is a comment, which holds no content, so it is not named
    expect($warnings->getArrayCopy())->toBe([[
        'TableProcessor::process: Dropped content in a wp:table block outside its figure',
        ['site' => 'default', 'pageSlug' => 'about', 'dropped_html' => substr('<p>A</p>text<figure class="wp-block-table"><table><tbody><tr><td>U</td></tr></tbody></table></figure>', 0, 100)],
    ]]);
});

it('warns of nothing for a table block that holds only its figure', function (): void {
    $warnings = tableWarnings();

    processTable(tableOpeningNode("<!-- wp:table -->\n<!-- a note --><figure class=\"wp-block-table\"><table><tbody><tr><td>T</td></tr></tbody></table></figure>\n<!-- /wp:table --><p>After</p>"));

    expect($warnings->getArrayCopy())->toBe([]);
});

it('returns null for a comment that belongs to no document', function (): void {
    $warnings = tableWarnings();

    expect(processTable(new DOMComment(' wp:table ')))->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([]);
});
