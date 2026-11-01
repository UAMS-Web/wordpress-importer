<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\LegacyMockInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeBlockProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Tests for the ShortcodeBlockProcessor class.
 *
 * Tests processing of WordPress wp:shortcode blocks into Tiptap-compatible
 * HTML or page_builder sets for Statamic Bard and landing pages. Routed to the
 * light Statamic band because the SUT logs via the `Log` facade (e.g. the empty
 * and no-shortcodes-extracted paths) and builds page-builder blocks with
 * Statamic's `RowId`, both of which need a booted container.
 *
 * @command  php vendor/bin/pest tests/Integration/ShortcodeBlockProcessorTest.php
 */
/**
 * A shortcode processor that counts its toTiptapHtml() calls by shortcode name.
 */
class ShortcodeBlockCountingShortcodeProcessor extends ShortcodeProcessor
{
    /**
     * The number of toTiptapHtml() calls for each shortcode name.
     *
     * @var array<string, int>
     */
    public array $renders = [];

    /**
     * Count the call and render as the real processor does.
     *
     * @param  array<int|string, mixed>  $shortcode
     * @param  array<int|string, mixed>  $attachments
     * @param  array<int|string, mixed>  $terms
     */
    public function toTiptapHtml(
        array $shortcode,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): string {
        $name = \is_string($shortcode['name'] ?? null) ? $shortcode['name'] : '';
        $this->renders[$name] = ($this->renders[$name] ?? 0) + 1;

        return parent::toTiptapHtml($shortcode, $site, $assetImporter, $output, $attachments, $terms, $pageSlug);
    }
}

beforeEach(function (): void {
    // Real ShortcodeBlockProcessor under test
    $this->processor = new ShortcodeBlockProcessor;

    // Mock AssetImporter since no real asset processing is exercised
    $this->assetImporter = $this::createStub(AssetImporter::class);

    // DOMDocument for building test HTML structures
    $this->doc = new DOMDocument;
});

afterEach(function (): void {
    // Clear TablePress static state to avoid leaking table IDs between tests
    TablePressShortcodeProcessor::clearTableIds();

    Mockery::close();
});

it('canProcess returns true for a wp:shortcode comment', function (): void {
    $comment = $this->doc->createComment('wp:shortcode');

    $result = $this->processor->canProcess($comment, 'test-site', 'test-page');

    expect($result)->toBeTrue();
});

it('canProcess returns true for a wp:shortcode comment padded as WordPress writes it', function (): void {
    expect($this->processor->canProcess($this->doc->createComment(' wp:shortcode '), 'test-site', null))->toBeTrue();
});

it('canProcess returns false for text that reads like the comment', function (): void {
    expect($this->processor->canProcess($this->doc->createTextNode('wp:shortcode'), 'test-site', null))->toBeFalse();
});

it('canProcess returns true for a wp:shortcode comment with trailing whitespace', function (): void {
    $comment = $this->doc->createComment('wp:shortcode ');

    $result = $this->processor->canProcess($comment, 'test-site', null);

    expect($result)->toBeTrue();
});

it('canProcess returns false for a wp:paragraph comment', function (): void {
    $comment = $this->doc->createComment('wp:paragraph');

    $result = $this->processor->canProcess($comment, 'test-site', 'test-page');

    expect($result)->toBeFalse();
});

it('canProcess returns false for a closing wp:shortcode comment', function (): void {
    $comment = $this->doc->createComment('/wp:shortcode');

    $result = $this->processor->canProcess($comment, 'test-site', null);

    expect($result)->toBeFalse();
});

it('canProcess returns false for an element node', function (): void {
    $element = $this->doc->createElement('div');

    $result = $this->processor->canProcess($element, 'test-site', null);

    expect($result)->toBeFalse();
});

it('process returns Tiptap html and a page_builder_set for a show-people shortcode', function (): void {
    // Wrap in <p>: libxml HTML drops raw `[shortcode]` between comments.
    $html = '<!-- wp:shortcode --><p>[show-people orderby="sortpriority" category="advanced-endoscopy" layout="grid"]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="people"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_show-people');
});

it('process returns an array with html and page_builder_set for a table shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[table id=7 /]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="table"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_table');
    expect($result['skipCount'])->toBeGreaterThanOrEqual(2);
});

it('process returns null for an empty wp:shortcode block', function (): void {
    $html = '<!-- wp:shortcode -->

<!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process(
        $comment,
        [],
        'default',
        $this->assetImporter,
        shortcodeBlockOutput(),
        [],
        [],
        null
    );

    expect($result)->toBeNull();
});

it('process extracts a shortcode from a block with newlines between comments', function (): void {
    $html = '<!-- wp:shortcode --><p>[table id=8 /]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'wardteams'
        )
    );

    expect($result['html'])->not->toBeEmpty();
    expect($result['html'])->toContain('data-set-type="table"');
});

it('process returns an array for a gravityform shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[gravityform id="1" title="false"]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="form"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_gravityform');
});

it('process returns an array for a gallery shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[gallery ids="1,2,3"]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="gallery"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_gallery');
});

it('process returns an array for a formidable shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[formidable id=2]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="form"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_formidable');
});

it('process returns the image set html, with no page builder set, for a caption shortcode', function (): void {
    // The host app defines no caption block, so a caption reaches page builder content
    // as the same image set the Bard route makes, inside a content block (#119)
    $html = '<!-- wp:shortcode --><div>[caption id="attachment_1" align="alignright" width="300"]<img src="https://example.com/img.jpg" />My caption[/caption]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireHtmlOnlyResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result)->toContain('data-set-type="image"')
        ->toContain('data-set-attr-caption="My caption"')
        ->not->toContain('[caption');
});

it('process returns an array for a blogroll shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[blogroll style="default"]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="news"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_news');
});

it('PeopleShortcodeProcessor normalizes attribute values to lowercase in parse()', function (): void {
    $processor = new PeopleShortcodeProcessor('show-people');
    $attributes = [
        'category' => 'Pulmonology-fellowship-faculty',
        'layout' => 'Grid',
        'style' => 'Img-Square',
        'display' => 'Name,Photo',
    ];

    $result = $processor->parse(
        $attributes,
        'default',
        $this->assetImporter,
        shortcodeBlockOutput(),
        []
    );

    $attrs = shortcodeBlockNormalizeStringKeyedArray($result['attrs'] ?? null);
    $values = shortcodeBlockNormalizeStringKeyedArray($attrs['values'] ?? null);

    expect($values['layout'])->toBe('grid');
});

it('PeopleShortcodeProcessor normalizes attribute values in toPageBuilderBlock()', function (): void {
    $processor = new PeopleShortcodeProcessor('show-team');
    $attributes = [
        'category' => 'Some-Team-Category',
        'layout' => 'grid',
    ];

    $block = shortcodeBlockNormalizeStringKeyedArray(
        $processor->toPageBuilderBlock(
            $attributes,
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            []
        )
    );

    expect($block['category'])->toBe('some-team-category');
});

it('PeopleShortcodeProcessor trims attribute values before processing', function (): void {
    $processor = new PeopleShortcodeProcessor('show-people');
    $attributes = [
        'category' => 'pulmonology-fellows ',
        'layout' => ' grid ',
    ];

    $block = shortcodeBlockNormalizeStringKeyedArray(
        $processor->toPageBuilderBlock(
            $attributes,
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            []
        )
    );

    expect($block['category'])->toBe('pulmonology-fellows');
    expect($block['layout'])->toBe('grid');
});

it('process returns a Tiptap html string for an audio shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[audio mp3="https://example.com/audio.mp3"]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireStringResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result)->toContain('data-set-type="audio"');
});

it('process flattens an accordion shortcode into a heading and its content', function (): void {
    $html = '<!-- wp:shortcode --><div>[accordion][section title="Section A"]Content A[/section][/accordion]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireStringResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    // The host app defines no accordion set, so the section becomes a heading
    // and a paragraph in place of the shortcode
    expect($result)->toContain('<h3>Section A</h3><p>Content A</p>')
        ->not->toContain('[accordion]')
        ->not->toContain('data-set-type');
});

it('process handles a wp:shortcode block within mixed block types', function (): void {
    $html = '<!-- wp:paragraph --><p>Intro text</p><!-- /wp:paragraph -->
<!-- wp:shortcode --><p>[table id=7 /]</p><!-- /wp:shortcode -->
<!-- wp:paragraph --><p>More text</p><!-- /wp:paragraph -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );

    expect($result['html'])->toContain('data-set-type="table"');
    expect($result['page_builder_set']['type'])->toBe('uamsweb_block_table');
    expect($result['skipCount'])->toBeGreaterThanOrEqual(2);
});

it('process handles each wp:shortcode when a document has multiple shortcode blocks', function (): void {
    $html = '<div><!-- wp:shortcode --><p>[table id=7 /]</p><!-- /wp:shortcode -->
<!-- wp:paragraph --><p>Between</p><!-- /wp:paragraph -->
<!-- wp:shortcode --><p>[table id=8 /]</p><!-- /wp:shortcode --></div>';

    // Process first wp:shortcode block
    $comment1 = shortcodeBlockRequireCommentNode($this->doc, $html, 0);
    $result1 = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment1,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );
    expect($result1['html'])->toContain('data-set-type="table"');
    expect($result1['page_builder_set']['type'])->toBe('uamsweb_block_table');

    // Process second wp:shortcode block (reload DOM to get a fresh node)
    $comment2 = shortcodeBlockRequireCommentNode($this->doc, $html, 1);
    $result2 = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment2,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );
    expect($result2['html'])->toContain('data-set-type="table"');
    expect($result2['page_builder_set']['type'])->toBe('uamsweb_block_table');
});

it('process handles two consecutive wp:shortcode blocks', function (): void {
    $html = '<div><!-- wp:shortcode --><p>[table id=7 /]</p><!-- /wp:shortcode --><!-- wp:shortcode --><p>[table id=8 /]</p><!-- /wp:shortcode --></div>';

    $comment1 = shortcodeBlockRequireCommentNode($this->doc, $html, 0);
    $result1 = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment1,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );
    expect($result1['html'])->toContain('data-set-type="table"');
    expect($result1['page_builder_set']['type'])->toBe('uamsweb_block_table');

    $comment2 = shortcodeBlockRequireCommentNode($this->doc, $html, 1);
    $result2 = shortcodeBlockRequireArrayResult(
        $this->processor->process(
            $comment2,
            [],
            'default',
            $this->assetImporter,
            shortcodeBlockOutput(),
            [],
            [],
            'test-page'
        )
    );
    expect($result2['html'])->toContain('data-set-type="table"');
    expect($result2['page_builder_set']['type'])->toBe('uamsweb_block_table');
});

it('process drops a subpage-list shortcode without warning or residual text', function (): void {
    // Start from a clean warning collection so the assertion is unambiguous
    ImportWarningManager::clearWarnings();

    // Spy on the log so we can assert the per-entry warning never fires
    $logSpy = shortcodeBlockSpyLog();

    // Wrap in <p>: libxml HTML drops raw `[shortcode]` between comments
    $html = '<!-- wp:shortcode --><p>[subpage-list link=""]</p><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process(
        $comment,
        [],
        'default',
        $this->assetImporter,
        shortcodeBlockOutput(),
        [],
        [],
        'management-of-the-neonate'
    );

    // A fully-dropped block returns an empty string, never null — null would be
    // logged downstream as a block-processor failure.
    expect($result)->toBe('');

    if (! is_string($result)) {
        throw new RuntimeException('Expected process() to return the empty string.');
    }

    // The literal shortcode text must not survive into the imported content.
    expect($result)->not->toContain('[subpage-list');

    // The "No shortcodes extracted from wp:shortcode block content" warning must
    // not fire now that subpage-list is a recognized shortcode.
    $logSpy->shouldNotHaveReceived('warning', [
        Mockery::pattern('/No shortcodes extracted/'),
        Mockery::any(),
    ]);

    // A recognized drop is not an unaddressed shortcode, so nothing is tracked.
    expect(ImportWarningManager::getWarnings('content', 'unaddressed_shortcodes'))
        ->toHaveCount(0);
});

/**
 * A typed, silent console output for shortcode processing.
 */
function shortcodeBlockOutput(): OutputInterface
{
    return new NullOutput;
}

/**
 * Spy on the Log facade for warning assertions.
 */
function shortcodeBlockSpyLog(): LegacyMockInterface
{
    return Log::spy();
}

/**
 * Load HTML and return the Nth opening wp:shortcode comment node (0-based index).
 */
function shortcodeBlockRequireCommentNode(
    DOMDocument $doc,
    string $html,
    int $index = 0
): DOMComment {
    // DOMDocument::loadHTML() rejects an empty string; narrow before handing it over.
    if ($html === '') {
        throw new InvalidArgumentException('Cannot load empty HTML.');
    }

    $doc->loadHTML(
        $html,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );
    $xpath = new DOMXPath($doc);
    $comments = $xpath->query(
        '//comment()[contains(., "wp:shortcode") and not(contains(., "/wp:shortcode"))]'
    );
    expect($comments)->not->toBeFalse();

    $comment = $comments === false ? null : $comments->item($index);
    expect($comment)->toBeInstanceOf(DOMComment::class);

    if (! $comment instanceof DOMComment) {
        throw new RuntimeException(
            "Expected a wp:shortcode opening comment node at index {$index} in the HTML."
        );
    }

    return $comment;
}

/**
 * Normalize mixed array data to a string-keyed array.
 *
 * @return array<string, mixed>
 */
function shortcodeBlockNormalizeStringKeyedArray(mixed $candidate): array
{
    if (! is_array($candidate)) {
        return [];
    }

    $normalized = [];

    foreach ($candidate as $key => $value) {
        $normalized[(string) $key] = $value;
    }

    return $normalized;
}

/**
 * Assert process() returned a shortcode array result with html and page_builder_set.
 *
 * @return array{
 *     html: string,
 *     page_builder_set: array<string, mixed>,
 *     skipCount: int
 * }
 */
function shortcodeBlockRequireArrayResult(mixed $result): array
{
    expect($result)->toBeArray();

    if (! is_array($result)) {
        throw new RuntimeException('Expected process() to return an array.');
    }

    expect($result)->toHaveKey('html');
    expect($result)->toHaveKey('page_builder_set');

    $html = $result['html'] ?? null;
    expect($html)->toBeString();

    $pageBuilderSet = $result['page_builder_set'] ?? null;
    expect($pageBuilderSet)->toBeArray();

    $skipCount = $result['skipCount'] ?? 0;
    expect($skipCount)->toBeInt();

    if (! is_string($html)) {
        throw new RuntimeException('Expected process() result html to be a string.');
    }

    if (! is_array($pageBuilderSet)) {
        throw new RuntimeException('Expected process() result page_builder_set to be an array.');
    }

    if (! is_int($skipCount)) {
        throw new RuntimeException('Expected process() result skipCount to be an int.');
    }

    return [
        'html' => $html,
        'page_builder_set' => shortcodeBlockNormalizeStringKeyedArray($pageBuilderSet),
        'skipCount' => $skipCount,
    ];
}

/**
 * Assert process() returned a string result.
 *
 * Retained after wordpress-importer-archive#509: this does more than narrow — the `expect()` inside is a
 * real runtime assertion, and `process()` is declared to return `mixed`.
 */
function shortcodeBlockRequireStringResult(mixed $result): string
{
    expect($result)->toBeString();

    if (! is_string($result)) {
        throw new RuntimeException('Expected process() to return a string.');
    }

    return $result;
}

/**
 * Assert process() returned html and a skip count over the whole block, with no
 * page builder set, and return the html.
 *
 * A lone registered shortcode whose handler builds no page builder block returns
 * this shape, so the caller does not read the block's inner nodes again.
 */
function shortcodeBlockRequireHtmlOnlyResult(mixed $result): string
{
    expect($result)->toBeArray();

    if (! is_array($result)) {
        throw new RuntimeException('Expected process() to return an array.');
    }

    expect($result)->not->toHaveKey('page_builder_set')
        ->and($result['skipCount'] ?? null)->toBe(3);

    $html = $result['html'] ?? null;

    if (! is_string($html)) {
        throw new RuntimeException('Expected process() to return html.');
    }

    return $html;
}

it('process counts every remaining sibling when a caption block has no closing comment', function (): void {
    $html = '<!-- wp:shortcode --><div>[caption id="attachment_1"]<img src="https://example.com/img.jpg" />My caption[/caption]</div><div></div>';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    // The opening comment and the two divs; no closing comment to add
    expect(is_array($result) ? ($result['skipCount'] ?? null) : null)->toBe(3);
});

it('process keeps what surrounds a lone shortcode, in the content a string returns', function (string $inner, string $name, string $kept): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->'.$inner.'<!-- /wp:shortcode --></div>');

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->toBeString()
        ->toContain($kept)
        ->not->toContain('['.$name);
})->with([
    'text around a caption' => ['Lead [caption id="attachment_1"]<img src="https://example.com/img.jpg" />Cap[/caption] tail', 'caption', 'Lead'],
]);

it('process returns what surrounds a shortcode beside its page builder set', function (string $inner, string $html, string $surrounding): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->'.$inner.'<!-- /wp:shortcode --></div>');

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->toBeArray()->toHaveKey('page_builder_set')
        ->and($result['surrounding_html'] ?? null)->toBe($surrounding)
        ->and($result['html'] ?? null)->toContain($html)->toContain('data-set-type="people"');
})->with([
    'text' => ['Lead [show-people ids="1"] tail', 'Lead ', 'Lead  tail'],
    'a paragraph after a block with no closing comment' => ['[show-people ids="1"]<p>After</p>', '<p>After</p>', '<p>After</p>'],
]);

it('process returns no surrounding html for a shortcode that is all the block holds', function (): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<!-- wp:shortcode -->[show-people ids="1"]<!-- /wp:shortcode -->');

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->toBeArray()->not->toHaveKey('surrounding_html');
});

it('process reads a wrapper holding only the shortcode as a lone shortcode', function (string $inner): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->'.$inner.'<!-- /wp:shortcode --></div>');

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->toBeArray()->toHaveKey('page_builder_set')->not->toHaveKey('surrounding_html');
})->with([
    'bare' => '[show-people ids="1"]',
    'in a paragraph' => '<p>[show-people ids="1"]</p>',
    'in a div' => '<div>[show-people ids="1"]</div>',
    'beside a non-breaking space in an element' => '<p>&nbsp;[show-people ids="1"]</p>',
    'beside a non-breaking space in text' => '&nbsp;[show-people ids="1"]',
]);

it('process returns the text and a skip count for a lone caption with no image', function (): void {
    // A caption with no image keeps its text (#164); the block is still skipped
    // whole, as it was when the caption built a page builder block
    $html = '<!-- wp:shortcode --><div>[caption id="attachment_1"]No image here[/caption]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->toBe(['html' => 'No image here', 'skipCount' => 3]);
});

it('process returns a plain string for a lone shortcode with no page builder block other than a caption', function (): void {
    // Audio builds no page builder block and is not a caption, so it keeps the loop's string
    $html = '<!-- wp:shortcode --><div>[audio mp3="https://example.com/a.mp3"]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    expect($this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page'))->toBeString();
});

it('process keeps the rendered shortcodes and warns about one that renders nothing among several', function (): void {
    // Start from no entry context, which an earlier test in the process can leave set
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ProcessingContext::clearFormConfigContext();

    // Collect every warning logged during the call
    /** @var ArrayObject<int, array{message: string, context: array<int|string, mixed>}> $warnings */
    $warnings = new ArrayObject;
    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            $warnings->append(['message' => $message, 'context' => $context]);
        }
    );
    $html = '<!-- wp:shortcode --><div>[caption id="attachment_1"]<img src="https://example.com/img.jpg" />With image[/caption] [caption id="attachment_2"][/caption]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    // The rendered caption is replaced; the empty one stays as its shortcode text
    expect($result)->toBeString()
        ->toContain('data-set-attr-caption="With image"')
        ->toContain('[caption id="attachment_2"][/caption]');

    // The caption's own missing-content warning comes first; the block processor's follows
    $blockWarnings = \array_values(\array_filter(
        $warnings->getArrayCopy(),
        static fn (array $warning): bool => \str_contains($warning['message'], 'ShortcodeBlockProcessor::process')
    ));

    expect($blockWarnings)->toHaveCount(1)
        ->and($blockWarnings[0]['message'] ?? '')->toContain("toTiptapHtml returned empty string for shortcode 'caption'")
        ->and($blockWarnings[0]['context'] ?? [])->toBe([
            'site' => 'default',
            'pageSlug' => 'test-page',
            'shortcode_name' => 'caption',
            'shortcode_original' => '[caption id="attachment_2"][/caption]',
        ]);
});

it('process renders a lone shortcode once, with or without a page builder block', function (string $inner, string $name): void {
    // A lone shortcode is rendered either by the single-shortcode branch or by the
    // loop, never by both, so its side effects (an audio import, a log line) run once
    $counting = new ShortcodeBlockCountingShortcodeProcessor;
    $processor = new ShortcodeBlockProcessor($counting);

    $comment = shortcodeBlockRequireCommentNode($this->doc, '<!-- wp:shortcode --><div>'.$inner.'</div><!-- /wp:shortcode -->');

    $processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($counting->renders)->toBe([$name => 1]);
})->with([
    'audio, no page builder block' => ['[audio mp3="https://example.com/a.mp3"]', 'audio'],
    'accordion, no page builder block' => ['[accordion][section title="A"]x[/section][/accordion]', 'accordion'],
    'table, a page builder block' => ['[table id=1 /]', 'table'],
    'caption, html alone' => ['[caption id="attachment_1"]<img src="https://example.com/img.jpg" />Cap[/caption]', 'caption'],
]);

/**
 * Collect every warning logged during a test.
 *
 * @return ArrayObject<int, array{message: string, context: array<int|string, mixed>}>
 */
function shortcodeBlockCollectWarnings(): ArrayObject
{
    // Start from no entry context, which an earlier test in the process can leave set
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ProcessingContext::clearFormConfigContext();

    /** @var ArrayObject<int, array{message: string, context: array<int|string, mixed>}> $warnings */
    $warnings = new ArrayObject;
    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            $warnings->append(['message' => $message, 'context' => $context]);
        }
    );

    return $warnings;
}

it('process warns once with what an empty block holds', function (): void {
    $warnings = shortcodeBlockCollectWarnings();
    $note = str_repeat('n', 120);

    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode --> <!--'.$note.'--><!-- /wp:shortcode --><!-- after --></div>');

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->toBeNull()
        ->and($warnings->getArrayCopy())->toBe([[
            'message' => 'ShortcodeBlockProcessor::process: No valid content found in wp:shortcode block',
            'context' => [
                'site' => 'default',
                'pageSlug' => 'test-page',
                'node_info' => [
                    ['type' => DOMText::class, 'nodeType' => XML_TEXT_NODE, 'content_preview' => ' ', 'is_empty_after_trim' => true],
                    ['type' => DOMComment::class, 'nodeType' => XML_COMMENT_NODE, 'content_preview' => str_repeat('n', 100), 'is_empty_after_trim' => null],
                ],
                'nodes_checked' => 2,
            ],
        ]]);
});

it('process names no page in the empty block warning without a slug, and checks at most ten nodes', function (): void {
    $warnings = shortcodeBlockCollectWarnings();

    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->'.str_repeat('<!-- x -->', 11).'</div>');

    expect($this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput()))->toBeNull()
        ->and($warnings[0]['context']['pageSlug'] ?? null)->toBe('none')
        ->and($warnings[0]['context']['nodes_checked'] ?? null)->toBe(10)
        ->and($warnings[0]['context']['node_info'] ?? null)->toHaveCount(10);
});

it('process stops the empty block walk at the end of the siblings', function (): void {
    $warnings = shortcodeBlockCollectWarnings();

    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode --></div>');

    expect($this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput()))->toBeNull()
        ->and($warnings[0]['context']['nodes_checked'] ?? null)->toBe(0);
});

it('process leaves comments inside a block out of its content', function (): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode --><p>Lead</p><!-- note --><!-- /wp:shortcode --></div>');

    expect($this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput()))->toBe('<p>Lead</p>');
});

it('process returns the content of a block with no shortcode in it, and of one holding only an empty paragraph', function (string $inner): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->'.$inner.'<!-- /wp:shortcode --></div>');

    expect($this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput()))->toBe($inner);
})->with([
    'an empty paragraph' => ['<p></p>'],
    'an unknown shortcode' => ['<p>[not-registered]</p>'],
]);

it('process returns the empty string when dropped shortcodes leave only whitespace', function (): void {
    $comment = shortcodeBlockRequireCommentNode(
        $this->doc,
        "<div><!-- wp:shortcode --><p>[subpage-list]</p>\n<p>[subpage-list]</p><!-- /wp:shortcode --></div>"
    );

    expect($this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput()))->toBe('');
});

it('process keeps the paragraphs a dropped shortcode leaves beside other content', function (): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode --><p>[subpage-list]</p><p>Kept</p><!-- /wp:shortcode --></div>');

    expect($this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput()))->toBe('<p></p><p>Kept</p>');
});

it('process returns null when a rendered shortcode leaves only whitespace', function (): void {
    $renderer = new class extends ShortcodeProcessor
    {
        /**
         * Render every shortcode as a space.
         *
         * @param  array<int|string, mixed>  $shortcode
         * @param  array<int|string, mixed>  $attachments
         * @param  array<int|string, mixed>  $terms
         */
        public function toTiptapHtml(
            array $shortcode,
            string $site,
            AssetImporter $assetImporter,
            OutputInterface $output,
            array $attachments = [],
            array $terms = [],
            ?string $pageSlug = null
        ): string {
            return ' ';
        }
    };

    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->[audio mp3="https://example.com/a.mp3"]<!-- /wp:shortcode --></div>');

    expect(new ShortcodeBlockProcessor($renderer)->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput()))->toBeNull();
});

it('blockContent trims what surrounds the content', function (): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, "<div><!-- wp:shortcode -->\n  [audio]\n<!-- /wp:shortcode --></div>");

    expect(ShortcodeBlockProcessor::blockContent($comment))->toBe('[audio]');
});

it('blockSkipCount ends at a closing comment padded as WordPress writes it, not at text reading like one', function (): void {
    $comment = shortcodeBlockRequireCommentNode($this->doc, '<div><!-- wp:shortcode -->/wp:shortcode<!-- /wp:shortcode --><p>After</p></div>');

    expect(ShortcodeBlockProcessor::blockSkipCount($comment))->toBe(3)
        ->and(ShortcodeBlockProcessor::blockContent($comment))->toBe('/wp:shortcode');
});
