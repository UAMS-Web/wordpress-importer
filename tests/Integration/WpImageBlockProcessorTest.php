<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\WpImageBlockProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

/**
 * Return the sole concrete Expectation held inside a CompositeExpectation so its
 * fluent methods are visible to PHPStan; the CompositeExpectation only proxies
 * them through `__call`.
 *
 * File-local for the reason `tests/Integration/TreeBuilderBranchTest.php` gives
 * for its own copy: a shared trait would read to the analyser as unused.
 *
 * @param  LegacyMockInterface|MockInterface  $mock  The mock to expect a call on
 * @param  string  $method  The method expected to be called
 * @return Expectation The unwrapped expectation, ready to chain
 */
function wpImageExpects(LegacyMockInterface|MockInterface $mock, string $method): Expectation
{
    $composite = $mock->shouldReceive($method);

    if (! $composite instanceof CompositeExpectation) {
        throw new RuntimeException('Expected a Mockery CompositeExpectation.');
    }

    /** @var list<ExpectationInterface> $expectations */
    $expectations = new ReflectionClass(CompositeExpectation::class)->getProperty('_expectations')->getValue($composite);

    if (count($expectations) !== 1 || ! $expectations[0] instanceof Expectation) {
        throw new RuntimeException('Expected exactly one Mockery Expectation.');
    }

    return $expectations[0];
}

/**
 * Parses an HTML body and returns its document and first comment node.
 *
 * @return array{0: DOMDocument, 1: DOMNode}
 */
function wpImageCommentNode(string $body): array
{
    $doc = new DOMDocument;
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<html><body>'.$body.'</body></html>');
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    $comments = (new DOMXPath($doc))->query('//comment()');
    $comment = $comments === false ? null : $comments->item(0);

    if (! $comment instanceof DOMNode) {
        throw new RuntimeException('The fragment holds no comment.');
    }

    return [$doc, $comment];
}

/**
 * Builds an AssetImporter mock reporting the given base blog URL.
 */
function wpImageImporter(?string $baseBlogUrl = 'https://example.org'): AssetImporter&MockInterface
{
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    wpImageExpects($importer, 'getBaseBlogUrl')->andReturn($baseBlogUrl);

    return $importer;
}

/**
 * Builds an ImageProcessor mock that records each processImage call.
 *
 * @param  array<int|string, mixed>|null  $set
 * @param  ArrayObject<int, array<int, mixed>>  $calls
 */
function wpImageProcessorMock(?array $set, ArrayObject $calls): ImageProcessor&MockInterface
{
    /** @var ImageProcessor&MockInterface $processor */
    $processor = Mockery::mock(ImageProcessor::class);
    wpImageExpects($processor, 'processImage')->andReturnUsing(
        function (mixed ...$args) use ($set, $calls): ?array {
            $calls->append(array_values($args));

            return $set;
        }
    );

    return $processor;
}

/**
 * Routes Log::warning calls into the returned collector.
 *
 * @return ArrayObject<int, array{message: string, context: array<string, mixed>}>
 */
function wpImageWarnings(): ArrayObject
{
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            $warnings->append(['message' => $message, 'context' => $context]);
        }
    );

    return $warnings;
}

describe('canProcess', function (): void {
    it('accepts only a wp:image opening comment carrying attributes', function (string $body, bool $expected): void {
        [$doc] = wpImageCommentNode($body);
        $node = $doc->getElementsByTagName('body')->item(0)?->firstChild;
        assert($node instanceof DOMNode);

        expect((new WpImageBlockProcessor)->canProcess($node, 'default', null))->toBe($expected);
    })->with([
        'attributes' => ['<!-- wp:image {"id":5} -->', true],
        'empty attributes' => ['<!-- wp:image {} -->', true],
        'no space before the attributes' => ['<!--wp:image{"id":5}-->', true],
        'no attributes' => ['<!-- wp:image -->', false],
        'closing comment' => ['<!-- /wp:image -->', false],
        'another block' => ['<!-- wp:gallery {"ids":[1]} -->', false],
        'text before the block name' => ['<!-- x wp:image {"id":5} -->', false],
        'text after the attributes' => ['<!-- wp:image {"id":5} x -->', false],
        'element' => ['<p>wp:image {"id":5}</p><!-- x -->', false],
    ]);
});

describe('process without a figure', function (): void {
    it('returns null for a node that has no owner document', function (): void {
        $calls = new ArrayObject;

        $result = (new WpImageBlockProcessor(wpImageProcessorMock(['type' => 'set'], $calls)))->process(
            new DOMDocument,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput
        );

        expect($result)->toBeNull()
            ->and($calls)->toHaveCount(0);
    });

    it('warns with a snippet of the next sibling', function (string $after, string $snippet): void {
        $warnings = wpImageWarnings();
        $calls = new ArrayObject;
        [, $comment] = wpImageCommentNode('<!-- wp:image {"id":5} -->'.$after);

        $result = (new WpImageBlockProcessor(wpImageProcessorMock(['type' => 'set'], $calls)))->process(
            $comment,
            ['id' => 5],
            'default',
            wpImageImporter(),
            new BufferedOutput,
            [],
            [],
            'about'
        );

        expect($result)->toBeNull()
            ->and($calls)->toHaveCount(0)
            ->and($warnings)->toHaveCount(1)
            ->and(data_get($warnings, '0.message'))->toBe('WpImageBlockProcessor::process: No valid wp:image figure found after comment')
            ->and(data_get($warnings, '0.context'))->toBe([
                'site' => 'default',
                'pageSlug' => 'about',
                'attributes' => ['id' => 5],
                'next_sibling_snippet' => $snippet,
            ]);
    })->with([
        'short sibling' => ['<p>text</p>', '<p>text</p>'],
        'exactly 100 characters' => ['<p>'.str_repeat('a', 93).'</p>', '<p>'.str_repeat('a', 93).'</p>'],
        'over 100 characters' => ['<p>'.str_repeat('a', 94).'</p>', '<p>'.str_repeat('a', 94).'</p...'],
        'no sibling' => ['', 'none'],
    ]);

    it('logs none for a missing page slug', function (): void {
        $warnings = wpImageWarnings();
        [, $comment] = wpImageCommentNode('<!-- wp:image {} -->');

        (new WpImageBlockProcessor(wpImageProcessorMock(null, new ArrayObject)))->process(
            $comment,
            [],
            'blog',
            wpImageImporter(),
            new BufferedOutput
        );

        expect(data_get($warnings, '0.context.site'))->toBe('blog')
            ->and(data_get($warnings, '0.context.pageSlug'))->toBe('none');
    });

    it('ignores a figure nested deeper than one wrapping div', function (): void {
        $warnings = wpImageWarnings();
        [, $comment] = wpImageCommentNode('<!-- wp:image {} --><div><div><figure><img src="https://example.org/a.jpg"></figure></div></div>');

        $result = (new WpImageBlockProcessor(wpImageProcessorMock(null, new ArrayObject)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput
        );

        expect($result)->toBeNull()
            ->and(data_get($warnings, '0.message'))->toBe('WpImageBlockProcessor::process: No valid wp:image figure found after comment');
    });
});

describe('process with a figure but no image', function (): void {
    it('warns with a snippet of the figure', function (string $figure, string $snippet): void {
        $warnings = wpImageWarnings();
        $calls = new ArrayObject;
        [, $comment] = wpImageCommentNode('<!-- wp:image {} -->'.$figure);

        $result = (new WpImageBlockProcessor(wpImageProcessorMock(['type' => 'set'], $calls)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput,
            [],
            [],
            'about'
        );

        expect($result)->toBeNull()
            ->and($calls)->toHaveCount(0)
            ->and($warnings)->toHaveCount(1)
            ->and(data_get($warnings, '0.message'))->toBe('WpImageBlockProcessor::process: No img tag found in wp:image block')
            ->and(data_get($warnings, '0.context'))->toBe([
                'site' => 'default',
                'pageSlug' => 'about',
                'html_snippet' => $snippet,
            ]);
    })->with([
        'short figure' => ['<figure><figcaption>c</figcaption></figure>', '<figure><figcaption>c</figcaption></figure>...'],
        'long figure' => ['<figure><p>'.str_repeat('b', 120).'</p></figure>', '<figure><p>'.str_repeat('b', 89).'...'],
    ]);

    it('logs none for a missing page slug', function (): void {
        $warnings = wpImageWarnings();
        [, $comment] = wpImageCommentNode('<!-- wp:image {} --><figure></figure>');

        (new WpImageBlockProcessor(wpImageProcessorMock(null, new ArrayObject)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput
        );

        expect(data_get($warnings, '0.context.pageSlug'))->toBe('none');
    });
});

describe('process with an image', function (): void {
    it('passes the figure and the arguments to the image processor', function (): void {
        $calls = new ArrayObject;
        [, $comment] = wpImageCommentNode('<!-- wp:image {"id":5} --><figure class="wp-block-image"><img src="https://example.org/a.jpg" alt="A"></figure><!-- /wp:image -->');
        $importer = wpImageImporter();
        $output = new BufferedOutput;
        $attachments = [new SimpleXMLElement('<item/>')];

        (new WpImageBlockProcessor(wpImageProcessorMock(['type' => 'set'], $calls)))->process(
            $comment,
            ['id' => 5, 'sizeSlug' => 'large'],
            'default',
            $importer,
            $output,
            $attachments,
            [],
            'about'
        );

        expect($calls)->toHaveCount(1)
            ->and($calls[0])->toBe([
                '<figure class="wp-block-image"><img src="https://example.org/a.jpg" alt="A"></figure>',
                'default',
                $importer,
                $output,
                $attachments,
                'about',
                ['id' => 5, 'sizeSlug' => 'large'],
            ]);
    });

    it('unwraps an image linked by an anchor before processing', function (): void {
        $calls = new ArrayObject;
        [, $comment] = wpImageCommentNode('<!-- wp:image {} --><figure><a href="https://example.org/big.jpg"><img src="https://example.org/a.jpg"></a><figcaption>c</figcaption></figure>');

        (new WpImageBlockProcessor(wpImageProcessorMock(['type' => 'set'], $calls)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput
        );

        expect(data_get($calls, '0.0'))->toBe('<figure><img src="https://example.org/a.jpg"><figcaption>c</figcaption></figure>');
    });

    it('leaves an image inside another element in place', function (): void {
        $calls = new ArrayObject;
        [, $comment] = wpImageCommentNode('<!-- wp:image {} --><figure><span><img src="https://example.org/a.jpg"></span></figure>');

        (new WpImageBlockProcessor(wpImageProcessorMock(['type' => 'set'], $calls)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput
        );

        expect(data_get($calls, '0.0'))->toBe('<figure><span><img src="https://example.org/a.jpg"></span></figure>');
    });

    it('drops the attachment id for an image hosted on another site', function (?string $baseBlogUrl, string $src, array $expected): void {
        $calls = new ArrayObject;
        [, $comment] = wpImageCommentNode('<!-- wp:image {} --><figure><img src="'.$src.'"></figure>');

        (new WpImageBlockProcessor(wpImageProcessorMock(['type' => 'set'], $calls)))->process(
            $comment,
            ['id' => 5, 'align' => 'left'],
            'default',
            wpImageImporter($baseBlogUrl),
            new BufferedOutput
        );

        expect(data_get($calls, '0.6'))->toBe($expected);
    })->with([
        'same site' => ['https://example.org', 'https://example.org/a.jpg', ['id' => 5, 'align' => 'left']],
        'no base URL' => [null, 'https://other.org/a.jpg', ['id' => 5, 'align' => 'left']],
        'other site' => ['https://example.org', 'https://other.org/a.jpg', ['align' => 'left']],
    ]);

    it('takes the figure inside a wrapping div', function (): void {
        $calls = new ArrayObject;
        [, $comment] = wpImageCommentNode('<!-- wp:image {} --><div class="wp-block-image"><figure class="aligncenter"><img src="https://example.org/a.jpg"></figure><figure><img src="https://example.org/b.jpg"></figure></div>');

        (new WpImageBlockProcessor(wpImageProcessorMock(['type' => 'set'], $calls)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput
        );

        expect(data_get($calls, '0.0'))->toBe('<figure class="aligncenter"><img src="https://example.org/a.jpg"></figure>');
    });
});

describe('process when the image processor fails', function (): void {
    it('warns and returns null', function (?array $set): void {
        $warnings = wpImageWarnings();
        [, $comment] = wpImageCommentNode('<!-- wp:image {} --><figure><img src="https://example.org/'.str_repeat('c', 100).'.jpg"></figure>');

        $result = (new WpImageBlockProcessor(wpImageProcessorMock($set, new ArrayObject)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput,
            [],
            [],
            'about'
        );

        expect($result)->toBeNull()
            ->and($warnings)->toHaveCount(1)
            ->and(data_get($warnings, '0.message'))->toBe('WpImageBlockProcessor::process: ImageProcessor failed to generate image set')
            ->and(data_get($warnings, '0.context'))->toBe([
                'site' => 'default',
                'pageSlug' => 'about',
                'html_snippet' => '<figure><img src="https://example.org/'.str_repeat('c', 62).'...',
            ]);
    })->with([
        'null' => [null],
        'empty' => [[]],
    ]);

    it('logs none for a missing page slug', function (): void {
        $warnings = wpImageWarnings();
        [, $comment] = wpImageCommentNode('<!-- wp:image {} --><figure><img src="https://example.org/a.jpg"></figure>');

        (new WpImageBlockProcessor(wpImageProcessorMock(null, new ArrayObject)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput
        );

        expect(data_get($warnings, '0.context.pageSlug'))->toBe('none');
    });
});

describe('process result', function (): void {
    it('renders the set values as data attributes', function (): void {
        [, $comment] = wpImageCommentNode('<!-- wp:image {} --><figure><img src="https://example.org/a.jpg"></figure>');
        $set = [
            'type' => 'set',
            'attrs' => [
                'id' => 'abc',
                'values' => [
                    'type' => 'image',
                    0 => 'numeric key',
                    'image' => 'images/a.jpg',
                    'alt' => 'Tom & "Jerry" <3',
                    'k<ey' => 'v',
                    'width' => 300,
                    'link' => null,
                    'sizes' => ['large'],
                ],
            ],
        ];

        $result = (new WpImageBlockProcessor(wpImageProcessorMock($set, new ArrayObject)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput
        );

        expect($result)->not->toBeNull()
            ->and(data_get($result, 'html'))->toBe(
                '<div data-set-type="image"'.SetMarkupNonce::attribute()
                .' data-set-attr-image="images/a.jpg"'
                .' data-set-attr-alt="Tom &amp; &quot;Jerry&quot; &lt;3"'
                .' data-set-attr-k&lt;ey="v"'
                .' data-set-attr-width="300"'
                .' data-set-attr-link=""'
                .' data-set-attr-sizes=""'
                .'></div>'
            );
    });

    it('renders no data attributes when the set carries no values', function (array $set): void {
        [, $comment] = wpImageCommentNode('<!-- wp:image {} --><figure><img src="https://example.org/a.jpg"></figure>');

        $result = (new WpImageBlockProcessor(wpImageProcessorMock($set, new ArrayObject)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput
        );

        expect(data_get($result, 'html'))->toBe('<div data-set-type="image"'.SetMarkupNonce::attribute().'></div>');
    })->with([
        'no attrs' => [['type' => 'set']],
        'attrs not an array' => [['type' => 'set', 'attrs' => 'x']],
        'no values' => [['type' => 'set', 'attrs' => ['id' => 'abc']]],
        'values not iterable' => [['type' => 'set', 'attrs' => ['values' => 'x']]],
        'values holding only the type' => [['type' => 'set', 'attrs' => ['values' => new ArrayIterator(['type' => 'image'])]]],
    ]);

    it('renders the values of an iterable object', function (): void {
        [, $comment] = wpImageCommentNode('<!-- wp:image {} --><figure><img src="https://example.org/a.jpg"></figure>');
        $set = ['type' => 'set', 'attrs' => ['values' => new ArrayIterator(['image' => 'images/a.jpg'])]];

        $result = (new WpImageBlockProcessor(wpImageProcessorMock($set, new ArrayObject)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput
        );

        expect(data_get($result, 'html'))->toBe('<div data-set-type="image"'.SetMarkupNonce::attribute().' data-set-attr-image="images/a.jpg"></div>');
    });

    it('returns the figure and the nodes to skip', function (string $body, int $skipCount): void {
        [$doc, $comment] = wpImageCommentNode('<!-- wp:image {} -->'.$body);

        $result = (new WpImageBlockProcessor(wpImageProcessorMock(['type' => 'set'], new ArrayObject)))->process(
            $comment,
            [],
            'default',
            wpImageImporter(),
            new BufferedOutput
        );

        $figure = $doc->getElementsByTagName('figure')->item(0);

        expect($result)->toHaveKeys(['html', 'processedNode', 'skipCount'])
            ->and(data_get($result, 'processedNode'))->toBe($figure)
            ->and(data_get($result, 'skipCount'))->toBe($skipCount);
    })->with([
        'figure after the comment' => ['<figure><img src="https://example.org/a.jpg"></figure>', 2],
        'figure inside a div' => ['<div><figure><img src="https://example.org/a.jpg"></figure></div>', 3],
    ]);
});
