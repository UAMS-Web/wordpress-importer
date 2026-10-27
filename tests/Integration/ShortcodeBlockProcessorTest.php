<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\LegacyMockInterface;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\PeopleShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeBlockProcessor;
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
beforeEach(function (): void {
    // Real ShortcodeBlockProcessor under test
    $this->processor = new ShortcodeBlockProcessor;

    // Mock AssetImporter since no real asset processing is exercised
    $this->assetImporter = $this->createMock(AssetImporter::class);

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
        [],
        null
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
            [],
            null
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
            [],
            null
        )
    );

    expect($block['category'])->toBe('pulmonology-fellows');
    expect($block['layout'])->toBe('grid');
});

it('process returns Tiptap html and a skip count over the block for an audio shortcode', function (): void {
    $html = '<!-- wp:shortcode --><p>[audio mp3="https://example.com/audio.mp3"]</p><!-- /wp:shortcode -->';

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

    expect($result)->toContain('data-set-type="audio"');
});

it('process flattens an accordion shortcode into a heading and its content', function (): void {
    $html = '<!-- wp:shortcode --><div>[accordion][section title="Section A"]Content A[/section][/accordion]</div><!-- /wp:shortcode -->';

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

it('process counts every remaining sibling when the block has no closing comment', function (): void {
    $html = '<!-- wp:shortcode --><div>[caption id="attachment_1"]<img src="https://example.com/img.jpg" />My caption[/caption]</div><p>After</p>';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    // The opening comment, the div and the paragraph; no closing comment to add
    expect(is_array($result) ? ($result['skipCount'] ?? null) : null)->toBe(3);
});

it('process falls back to the block content when a lone handler renders nothing', function (): void {
    // A caption with no image renders as nothing (#164), so there is no html to return
    $html = '<!-- wp:shortcode --><div>[caption id="attachment_1"]No image here[/caption]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    expect($result)->not->toBeArray();
});

it('process keeps the rendered shortcodes and warns about one that renders nothing among several', function (): void {
    // Collect every warning logged during the call
    /** @var ArrayObject<int, array{message: string, context: array<int|string, mixed>}> $warnings */
    $warnings = new ArrayObject;
    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            $warnings->append(['message' => $message, 'context' => $context]);
        }
    );
    $html = '<!-- wp:shortcode --><div>[caption id="attachment_1"]<img src="https://example.com/img.jpg" />With image[/caption] [caption id="attachment_2"]No image[/caption]</div><!-- /wp:shortcode -->';

    $comment = shortcodeBlockRequireCommentNode($this->doc, $html);

    $result = $this->processor->process($comment, [], 'default', $this->assetImporter, shortcodeBlockOutput(), [], [], 'test-page');

    // The rendered caption is replaced; the empty one stays as its shortcode text
    expect($result)->toBeString()
        ->toContain('data-set-attr-caption="With image"')
        ->toContain('[caption id="attachment_2"]No image[/caption]');

    // The caption's own missing-image warning comes first; the block processor's follows
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
            'shortcode_original' => '[caption id="attachment_2"]No image[/caption]',
        ]);
});
