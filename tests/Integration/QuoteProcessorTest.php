<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\QuoteProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Tests for the QuoteProcessor class.
 *
 * Tests the processing of WordPress wp:quote blocks into Tiptap-compatible
 * HTML for Statamic Bard sets. Routed to the light Statamic band because the
 * null-return paths log via the `Log` facade, which needs a booted container.
 *
 * @command  php vendor/bin/pest tests/Integration/QuoteProcessorTest.php
 */
beforeEach(function (): void {
    // Create a real instance of the QuoteProcessor for testing
    $this->processor = new QuoteProcessor;

    // Create mock AssetImporter since we don't need real asset processing
    $this->assetImporter = $this::createStub(AssetImporter::class);

    // Create DOMDocument for HTML manipulation in tests
    $this->doc = new DOMDocument;

    // A log context carries whatever the last test left here, and a parallel worker shares it
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

it('can process a valid wp:quote comment', function (): void {
    $comment = $this->doc->createComment('wp:quote');

    $result = $this->processor->canProcess($comment, 'test-site', 'test-page');

    expect($result)->toBeTrue();
});

it('can process a wp:quote comment with attributes', function (): void {
    $comment = $this->doc->createComment('wp:quote {"align":"center"}');

    $result = $this->processor->canProcess($comment, 'test-site', 'test-page');

    expect($result)->toBeTrue();
});

it('rejects a non-quote comment', function (): void {
    $comment = $this->doc->createComment('wp:paragraph');

    $result = $this->processor->canProcess($comment, 'test-site', 'test-page');

    expect($result)->toBeFalse();
});

it('rejects a non-comment node', function (): void {
    $element = $this->doc->createElement('div');

    $result = $this->processor->canProcess($element, 'test-site', 'test-page');

    expect($result)->toBeFalse();
});

it('processes a single paragraph with wp:paragraph comment siblings', function (): void {
    $html = '<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>Lorem ipsum dolor sit amet, consectetuer adipiscing elit.</p>
<!-- /wp:paragraph --></blockquote>
<!-- /wp:quote -->';

    $result = quoteProcessorProcess(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html
    );

    expect($result)->toBeArray();

    if (! is_array($result)) {
        throw new RuntimeException('Expected the process result to be an array.');
    }

    // Assert that result contains HTML output
    expect($result)->toHaveKey('html');

    // Assert that result contains processed node reference
    expect($result)->toHaveKey('processedNode');

    // Assert that result contains skip count for node traversal
    expect($result)->toHaveKey('skipCount');

    $outputHtml = quoteProcessorResultHtml($result);

    // Assert that output contains Tiptap pull_quote structure
    expect($outputHtml)->toContain('data-set-type="pull_quote"');

    // Assert that quote content is preserved in output
    expect($outputHtml)->toContain('Lorem ipsum dolor sit amet');
});

it('processes multiple paragraphs with wp:paragraph comment siblings', function (): void {
    $html = '<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>Cum sociis natoque penatibus et magnis dis parturient montes.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Donec quam felis, ultricies nec, pellentesque eu, pretium quis.</p>
<!-- /wp:paragraph --></blockquote>
<!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html
    );

    // Assert that output contains Tiptap pull_quote structure
    expect($outputHtml)->toContain('data-set-type="pull_quote"');

    // Assert that first paragraph content is preserved in output
    expect($outputHtml)->toContain('Cum sociis natoque');

    // Assert that second paragraph content is preserved in output
    expect($outputHtml)->toContain('Donec quam felis');
});

it('processes a single paragraph with a citation sibling', function (): void {
    $html = '<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>Donec pede justo, fringilla vel, aliquet nec, vulputate eget.</p>
<!-- /wp:paragraph --><cite>This is a citation</cite></blockquote>
<!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html
    );

    // Assert that output contains Tiptap pull_quote structure
    expect($outputHtml)->toContain('data-set-type="pull_quote"');

    // Assert that paragraph content is preserved in output
    expect($outputHtml)->toContain('Donec pede justo');

    // Assert that citation content is preserved in output
    expect($outputHtml)->toContain('This is a citation');
});

it('processes multiple paragraphs with a citation sibling', function (): void {
    $html = '<!-- wp:quote -->
<blockquote class="wp-block-quote"><!-- wp:paragraph -->
<p>Nullam dictum felis eu pede mollis pretium. Integer tincidunt.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph -->
<p>Aenean vulputate eleifend tellus. Aenean leo ligula, porttitor eu.</p>
<!-- /wp:paragraph --><cite>This is a citation</cite></blockquote>
<!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html
    );

    // Assert that output contains Tiptap pull_quote structure
    expect($outputHtml)->toContain('data-set-type="pull_quote"');

    // Assert that first paragraph content is preserved in output
    expect($outputHtml)->toContain('Nullam dictum felis');

    // Assert that second paragraph content is preserved in output
    expect($outputHtml)->toContain('Aenean vulputate eleifend');

    // Assert that citation content is preserved in output
    expect($outputHtml)->toContain('This is a citation');
});

it('processes a direct paragraph child without wp:paragraph comments', function (): void {
    $html = '<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>Hi there! I\'m a bike messenger by day, aspiring actor by night.</p></blockquote>
<!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html
    );

    // Assert that output contains Tiptap pull_quote structure
    expect($outputHtml)->toContain('data-set-type="pull_quote"');

    // Assert that paragraph content is preserved (with HTML entity encoding)
    expect($outputHtml)->toContain('Hi there! I&#039;m a bike messenger');
});

it('processes a direct paragraph child with a citation child', function (): void {
    $html = '<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>This is a direct quote with citation.</p><cite>Author Name</cite></blockquote>
<!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html
    );

    // Assert that output contains Tiptap pull_quote structure
    expect($outputHtml)->toContain('data-set-type="pull_quote"');

    // Assert that paragraph content is preserved in output
    expect($outputHtml)->toContain('This is a direct quote');

    // Assert that citation content is preserved in output
    expect($outputHtml)->toContain('Author Name');
});

it('translates the alignment attribute', function (): void {
    $html = '<!-- wp:quote {"align":"center"} -->
<blockquote class="wp-block-quote"><p>This quote should be center aligned.</p></blockquote>
<!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html,
        ['align' => 'center']
    );

    // Assert that alignment attribute is properly translated and included
    expect($outputHtml)->toContain('data-set-attr-align="aligncenter"');
});

it('filters out empty paragraphs and keeps valid content', function (): void {
    $html = '<!-- wp:quote -->
<blockquote class="wp-block-quote"><p></p><p>   </p><p>Valid content</p></blockquote>
<!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html
    );

    // Assert that output contains Tiptap pull_quote structure
    expect($outputHtml)->toContain('data-set-type="pull_quote"');

    // Assert that only valid content is preserved (empty paragraphs filtered out)
    expect($outputHtml)->toContain('Valid content');
});

it('returns null when no blockquote is found', function (): void {
    $html = '<!-- wp:quote -->
<p>This is not a blockquote</p>
<!-- /wp:quote -->';

    $result = quoteProcessorProcess(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html
    );

    // Assert that processor returns null when no blockquote is found
    expect($result)->toBeNull();
});

it('returns null when no paragraphs are found', function (): void {
    $html = '<!-- wp:quote -->
<blockquote class="wp-block-quote"><cite>Only citation, no paragraphs</cite></blockquote>
<!-- /wp:quote -->';

    $result = quoteProcessorProcess(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html
    );

    // Assert that processor returns null when no paragraphs are found
    expect($result)->toBeNull();
});

it('uses only the first citation when multiple are present', function (): void {
    $html = '<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>Quote content</p><cite>First Citation</cite><cite>Second Citation</cite></blockquote>
<!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html
    );

    // Assert that output contains Tiptap pull_quote structure
    expect($outputHtml)->toContain('data-set-type="pull_quote"');

    // Assert that first citation is preserved in output
    expect($outputHtml)->toContain('First Citation');

    // Assert that second citation is not included (only first is used)
    expect($outputHtml)->not->toContain('Second Citation');
});

it('omits the author attribute for an empty citation', function (): void {
    $html = '<!-- wp:quote -->
<blockquote class="wp-block-quote"><p>Quote content</p><cite>   </cite></blockquote>
<!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml(
        $this->processor,
        $this->doc,
        $this->assetImporter,
        quoteProcessorNullOutput(),
        $html
    );

    // Assert that output contains Tiptap pull_quote structure
    expect($outputHtml)->toContain('data-set-type="pull_quote"');

    // Assert that quote content is preserved in output
    expect($outputHtml)->toContain('Quote content');

    // Should not contain author attribute for empty citation
    expect($outputHtml)->not->toContain('data-set-attr-author');
});

it('rejects an empty comment', function (): void {
    expect($this->processor->canProcess($this->doc->createComment('   '), 'test-site', 'test-page'))->toBeFalse();
});

it('rejects a text node that reads like the block comment', function (): void {
    expect($this->processor->canProcess($this->doc->createTextNode('wp:quote'), 'test-site', 'test-page'))->toBeFalse();
});

it('warns with the site, page and attributes when no blockquote is found', function (?string $pageSlug, string $logged): void {
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'QuoteProcessor::process: No valid wp:quote blockquote found')
        && $context === ['site' => 'test-site', 'pageSlug' => $logged, 'attributes' => ['align' => 'wide']]);

    $comment = quoteProcessorCommentNode($this->doc, '<!-- wp:quote --><p>Not a quote.</p><!-- /wp:quote -->');

    expect($this->processor->process($comment, ['align' => 'wide'], 'test-site', $this->assetImporter, quoteProcessorNullOutput(), [], [], $pageSlug))->toBeNull();
})->with([
    'a page slug' => ['a-page', 'a-page'],
    'no page slug' => [null, 'none'],
]);

it('warns once, with the start of the block, when the quote has no paragraphs', function (?string $pageSlug, string $logged): void {
    $blockquote = '<blockquote class="wp-block-quote"><cite>'.str_repeat('a', 120).'</cite></blockquote>';

    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'QuoteProcessor::process: No paragraphs found in wp:quote block')
        && $context === ['site' => 'test-site', 'pageSlug' => $logged, 'node_html' => substr($blockquote, 0, 100).'...']);

    $comment = quoteProcessorCommentNode($this->doc, '<!-- wp:quote -->'.$blockquote.'<!-- /wp:quote -->');

    expect($this->processor->process($comment, [], 'test-site', $this->assetImporter, quoteProcessorNullOutput(), [], [], $pageSlug))->toBeNull();
})->with([
    'a page slug' => ['a-page', 'a-page'],
    'no page slug' => [null, 'none'],
]);

it('writes the set markup with the nonce, the alignment and the quote', function (): void {
    $html = '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Words.</p></blockquote><!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml($this->processor, $this->doc, $this->assetImporter, quoteProcessorNullOutput(), $html);

    $quote = htmlspecialchars((string) json_encode([['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Words.']]]]));

    expect($outputHtml)->toBe('<div data-set-type="pull_quote"'.SetMarkupNonce::attribute().' data-set-attr-align="aligncenter" data-set-attr-quote="'.$quote.'"></div>');
});

it('escapes the author in the set markup', function (): void {
    $html = '<!-- wp:quote --><blockquote class="wp-block-quote"><p>Words.</p><cite>A "named" person</cite></blockquote><!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml($this->processor, $this->doc, $this->assetImporter, quoteProcessorNullOutput(), $html);

    expect($outputHtml)->toContain('data-set-attr-author="A &quot;named&quot; person"');
});

it('takes the alignment from the attribute, then from the class', function (array $attributes, string $class, string $expected): void {
    /** @var array<string, mixed> $attributes */
    $html = '<!-- wp:quote --><blockquote class="wp-block-quote '.$class.'"><p>Words.</p></blockquote><!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml($this->processor, $this->doc, $this->assetImporter, quoteProcessorNullOutput(), $html, $attributes);

    expect($outputHtml)->toContain('data-set-attr-align="'.$expected.'"');
})->with([
    'the attribute wins over the class' => [['align' => 'wide'], 'aligncenter', 'alignwide'],
    'the class, with no attribute' => [[], 'alignwide', 'alignwide'],
    'a side alignment narrows to center' => [['align' => 'left'], '', 'aligncenter'],
    'an attribute that is not scalar falls back to the class' => [['align' => ['wide']], 'alignwide', 'alignwide'],
    'a scalar attribute is read as text' => [['align' => 5], 'alignwide', 'aligncenter'],
]);

it('reads past another comment among the sibling paragraphs', function (): void {
    $html = '<!-- wp:quote --><blockquote class="wp-block-quote"></blockquote><!-- wp:paragraph --><p>After a comment.</p>'
        .'<!-- /wp:paragraph --><p>Names /wp:quote in its text.</p><p>Last.</p><!-- /wp:quote -->';

    $outputHtml = quoteProcessorRequireHtml($this->processor, $this->doc, $this->assetImporter, quoteProcessorNullOutput(), $html);

    expect($outputHtml)->toContain('After a comment.');
    expect($outputHtml)->toContain('Last.');
});

it('stops at the last sibling when the closing comment is missing', function (): void {
    $html = '<!-- wp:quote --><blockquote class="wp-block-quote"></blockquote><p>Unclosed.</p>';

    $outputHtml = quoteProcessorRequireHtml($this->processor, $this->doc, $this->assetImporter, quoteProcessorNullOutput(), $html);

    expect($outputHtml)->toContain('Unclosed.');
});

it('returns null for a node outside any document', function (): void {
    expect($this->processor->process(new DOMDocument, [], 'test-site', $this->assetImporter, quoteProcessorNullOutput()))->toBeNull();
});

it('reads a citation from the siblings when the paragraphs are siblings too', function (): void {
    $html = '<!-- wp:quote --><blockquote class="wp-block-quote"></blockquote><p>A sibling paragraph.</p>'
        .'<cite>A sibling author</cite><div>ignored</div><!-- /wp:quote -->';

    $result = quoteProcessorProcess($this->processor, $this->doc, $this->assetImporter, quoteProcessorNullOutput(), $html);

    if (! is_array($result)) {
        throw new RuntimeException('Expected the process result to be an array.');
    }

    $outputHtml = quoteProcessorResultHtml($result);

    expect($outputHtml)->toContain('data-set-attr-author="A sibling author"');
    expect($outputHtml)->toContain('A sibling paragraph.');
    expect($outputHtml)->not->toContain('ignored');

    // The blockquote, three siblings and the closing comment
    expect($result['skipCount'])->toBe(5);
});

it('stops collecting sibling paragraphs at the closing comment', function (): void {
    $html = '<!-- wp:quote --><blockquote class="wp-block-quote"></blockquote><p>Inside.</p>'
        .'<!-- /wp:quote --><p>Outside.</p>';

    $outputHtml = quoteProcessorRequireHtml($this->processor, $this->doc, $this->assetImporter, quoteProcessorNullOutput(), $html);

    expect($outputHtml)->toContain('Inside.');
    expect($outputHtml)->not->toContain('Outside.');
});

it('warns and returns null when no paragraph parses to any content', function (): void {
    Log::shouldReceive('warning')->once()->withArgs(fn (string $message, array $context): bool => str_contains($message, 'QuoteProcessor::process: No valid paragraph content parsed in wp:quote block')
        && ($context['site'] ?? null) === 'test-site'
        && ($context['pageSlug'] ?? null) === 'test-page'
        && ($context['node_html'] ?? null) === substr('<blockquote class="wp-block-quote"><p><script>'.str_repeat('a', 120), 0, 100).'...');

    $html = '<!-- wp:quote --><blockquote class="wp-block-quote"><p><script>'.str_repeat('a', 120).'</script></p></blockquote><!-- /wp:quote -->';

    $result = quoteProcessorProcess($this->processor, $this->doc, $this->assetImporter, quoteProcessorNullOutput(), $html);

    expect($result)->toBeNull();
});

/**
 * Silent, typed console output for QuoteProcessor::process().
 *
 * The processor only receives `$output` as a pass-through and never writes to
 * it, so a NullOutput preserves behavior while giving PHPStan a concrete
 * OutputInterface at the call sites.
 */
function quoteProcessorNullOutput(): OutputInterface
{
    return new NullOutput;
}

/**
 * Load HTML and return the first opening wp:quote comment node (0-based).
 */
function quoteProcessorCommentNode(DOMDocument $doc, string $html): DOMComment
{
    // DOMDocument::loadHTML() rejects an empty string; narrow before handing it over.
    if ($html === '') {
        throw new InvalidArgumentException('Cannot load empty HTML.');
    }

    // Load HTML into DOMDocument for processing
    $doc->loadHTML(
        $html,
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
    );

    // Create XPath instance for node selection
    $xpath = new DOMXPath($doc);

    // Find opening wp:quote comments (not closing ones)
    $comments = $xpath->query(
        '//comment()[contains(., "wp:quote") and not(contains(., "/wp:quote"))]'
    );
    expect($comments)->not->toBeFalse();

    $comment = $comments === false ? null : $comments->item(0);
    expect($comment)->toBeInstanceOf(DOMComment::class);

    if (! $comment instanceof DOMComment) {
        throw new RuntimeException('Expected a wp:quote opening comment node in the HTML.');
    }

    return $comment;
}

/**
 * Process wp:quote HTML through QuoteProcessor.
 *
 * @param  array<string, mixed>  $attributes
 * @return array<int|string, mixed>|string|null
 */
function quoteProcessorProcess(
    QuoteProcessor $processor,
    DOMDocument $doc,
    AssetImporter $assetImporter,
    OutputInterface $output,
    string $html,
    array $attributes = []
): array|string|null {
    $comment = quoteProcessorCommentNode($doc, $html);

    return $processor->process(
        $comment,
        $attributes,
        'test-site',
        $assetImporter,
        $output,
        [],
        [],
        'test-page'
    );
}

/**
 * Return the HTML string from a non-null array process result.
 *
 * @param  array<int|string, mixed>  $result
 */
function quoteProcessorResultHtml(array $result): string
{
    expect($result)->toHaveKey('html');

    $html = $result['html'] ?? null;
    expect($html)->toBeString();

    if (! is_string($html)) {
        throw new RuntimeException('Expected the process result html to be a string.');
    }

    return $html;
}

/**
 * Assert process returned an array and return its HTML string.
 *
 * @param  array<string, mixed>  $attributes
 */
function quoteProcessorRequireHtml(
    QuoteProcessor $processor,
    DOMDocument $doc,
    AssetImporter $assetImporter,
    OutputInterface $output,
    string $html,
    array $attributes = []
): string {
    $result = quoteProcessorProcess(
        $processor,
        $doc,
        $assetImporter,
        $output,
        $html,
        $attributes
    );
    expect($result)->toBeArray();

    if (! is_array($result)) {
        throw new RuntimeException('Expected the process result to be an array.');
    }

    return quoteProcessorResultHtml($result);
}
