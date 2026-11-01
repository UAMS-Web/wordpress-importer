<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\CompositeExpectation;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\LegacyMockInterface;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ImageProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\WpPostFeaturedImageBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearFormConfigContext();
    ProcessingContext::clearEntryData();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
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
function featuredImageExpects(LegacyMockInterface|MockInterface $mock, string $method): Expectation
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
 * Builds a WXR attachment item carrying a post id and an attachment URL.
 */
function featuredImageAttachment(string $postId, string $url): SimpleXMLElement
{
    return new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        ."<wp:post_id>{$postId}</wp:post_id>"
        .'<wp:attachment_url>'.htmlspecialchars($url).'</wp:attachment_url>'
        .'</item>'
    );
}

/**
 * Builds an AssetImporter mock reporting the given base blog URL.
 */
function featuredImageImporter(?string $baseBlogUrl = 'https://example.org'): AssetImporter&MockInterface
{
    /** @var AssetImporter&MockInterface $importer */
    $importer = Mockery::mock(AssetImporter::class);
    featuredImageExpects($importer, 'getBaseBlogUrl')->andReturn($baseBlogUrl);

    return $importer;
}

/**
 * Builds an ImageProcessor mock that records each processImage call.
 *
 * @param  array<int|string, mixed>|null  $set
 * @param  ArrayObject<int, array<int, mixed>>  $calls
 */
function featuredImageProcessorMock(?array $set, ArrayObject $calls): ImageProcessor&MockInterface
{
    /** @var ImageProcessor&MockInterface $processor */
    $processor = Mockery::mock(ImageProcessor::class);
    featuredImageExpects($processor, 'processImage')->andReturnUsing(
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
function featuredImageWarnings(): ArrayObject
{
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            $warnings->append(['message' => $message, 'context' => $context]);
        }
    );

    return $warnings;
}

/**
 * Runs the processor on a fresh comment node with the given collaborators.
 *
 * @param  array<int|string, mixed>  $attributes
 * @param  array<int|string, mixed>  $attachments
 * @return array<int|string, mixed>|null
 */
function featuredImageProcess(
    ImageProcessor $imageProcessor,
    array $attachments = [],
    array $attributes = [],
    ?AssetImporter $importer = null,
    ?string $pageSlug = 'about',
    ?DOMNode $node = null,
): ?array {
    return (new WpPostFeaturedImageBlockProcessor($imageProcessor))->process(
        $node ?? (new DOMDocument)->createComment(' wp:post-featured-image /'),
        $attributes,
        'default',
        $importer ?? featuredImageImporter(),
        new BufferedOutput,
        $attachments,
        [],
        $pageSlug
    );
}

describe('canProcess', function (): void {
    it('accepts only a bare wp:post-featured-image comment', function (DOMNode $node, bool $expected): void {
        expect((new WpPostFeaturedImageBlockProcessor)->canProcess($node, 'default', null))->toBe($expected);
    })->with([
        'opening comment' => fn () => [(new DOMDocument)->createComment(' wp:post-featured-image '), true],
        'self-closing comment' => fn () => [(new DOMDocument)->createComment(' wp:post-featured-image /'), true],
        'no spaces' => fn () => [(new DOMDocument)->createComment('wp:post-featured-image/'), true],
        'with attributes' => fn () => [(new DOMDocument)->createComment(' wp:post-featured-image {"align":"wide"} /'), false],
        'closing comment' => fn () => [(new DOMDocument)->createComment(' /wp:post-featured-image '), false],
        'another block' => fn () => [(new DOMDocument)->createComment(' wp:post-title /'), false],
        'text before the block name' => fn () => [(new DOMDocument)->createComment(' x wp:post-featured-image /'), false],
        'element' => fn () => [(new DOMDocument)->createElement('p', 'wp:post-featured-image'), false],
        'text node' => fn () => [(new DOMDocument)->createTextNode('wp:post-featured-image'), false],
    ]);
});

describe('process without a thumbnail id', function (): void {
    it('warns and returns null', function (?array $entryData): void {
        $warnings = featuredImageWarnings();
        $calls = new ArrayObject;
        ProcessingContext::setEntryData($entryData);

        $result = featuredImageProcess(
            featuredImageProcessorMock(['type' => 'set'], $calls),
            [featuredImageAttachment('', 'https://example.org/a.jpg')]
        );

        expect($result)->toBeNull()
            ->and($calls)->toHaveCount(0)
            ->and($warnings)->toHaveCount(1)
            ->and(data_get($warnings, '0.message'))->toBe('WpPostFeaturedImageBlockProcessor::process: No _thumbnail_id found in entry data')
            ->and(data_get($warnings, '0.context'))->toBe(['site' => 'default', 'pageSlug' => 'about']);
    })->with([
        'no entry data' => [null],
        'no thumbnail key' => [['title' => 'T']],
        'empty id' => [['_thumbnail_id' => '']],
        'false id' => [['_thumbnail_id' => false]],
        'array id' => [['_thumbnail_id' => ['5']]],
    ]);

    it('logs none for a missing page slug', function (): void {
        $warnings = featuredImageWarnings();

        featuredImageProcess(featuredImageProcessorMock(null, new ArrayObject), pageSlug: null);

        expect(data_get($warnings, '0.context.pageSlug'))->toBe('none');
    });
});

describe('process without featured image data', function (): void {
    it('warns with the thumbnail id and returns null', function (array $attachments): void {
        $warnings = featuredImageWarnings();
        $calls = new ArrayObject;
        ProcessingContext::setEntryData(['_thumbnail_id' => 5]);

        $result = featuredImageProcess(featuredImageProcessorMock(['type' => 'set'], $calls), $attachments);

        expect($result)->toBeNull()
            ->and($calls)->toHaveCount(0)
            ->and($warnings)->toHaveCount(1)
            ->and(data_get($warnings, '0.message'))->toBe('WpPostFeaturedImageBlockProcessor::process: No featured image data found for thumbnail ID')
            ->and(data_get($warnings, '0.context'))->toBe(['site' => 'default', 'pageSlug' => 'about', 'thumbnailId' => '5']);
    })->with([
        'no attachments' => [[]],
        'no matching attachment' => [[featuredImageAttachment('6', 'https://example.org/a.jpg')]],
        'matching attachment without a URL' => [[featuredImageAttachment('5', '')]],
    ]);

    it('logs none for a missing page slug', function (): void {
        $warnings = featuredImageWarnings();
        ProcessingContext::setEntryData(['_thumbnail_id' => '5']);

        featuredImageProcess(featuredImageProcessorMock(null, new ArrayObject), pageSlug: null);

        expect(data_get($warnings, '0.context.pageSlug'))->toBe('none');
    });
});

describe('process with a featured image', function (): void {
    it('builds a virtual figure and passes it to the image processor', function (): void {
        $calls = new ArrayObject;
        ProcessingContext::setEntryData(['_thumbnail_id' => 5]);
        $attachments = [
            featuredImageAttachment('4', 'https://example.org/other.jpg'),
            featuredImageAttachment('5', 'https://example.org/a.jpg?w=1&h=2'),
        ];
        $importer = featuredImageImporter();

        featuredImageProcess(
            featuredImageProcessorMock(['type' => 'set'], $calls),
            $attachments,
            ['sizeSlug' => 'large'],
            $importer
        );

        expect($calls)->toHaveCount(1)
            ->and(data_get($calls, '0.0'))->toBe('<figure class="wp-block-post-featured-image aligncenter"><img src="https://example.org/a.jpg?w=1&amp;h=2" /></figure>')
            ->and(data_get($calls, '0.1'))->toBe('default')
            ->and(data_get($calls, '0.2'))->toBe($importer)
            ->and(data_get($calls, '0.3'))->toBeInstanceOf(BufferedOutput::class)
            ->and(data_get($calls, '0.4'))->toBe($attachments)
            ->and(data_get($calls, '0.5'))->toBe('about')
            ->and(data_get($calls, '0.6'))->toBe(['sizeSlug' => 'large', 'id' => '5']);
    });

    it('accepts a zero thumbnail id', function (): void {
        $calls = new ArrayObject;
        ProcessingContext::setEntryData(['_thumbnail_id' => 0]);

        featuredImageProcess(
            featuredImageProcessorMock(['type' => 'set'], $calls),
            [featuredImageAttachment('0', 'https://example.org/a.jpg')]
        );

        expect(data_get($calls, '0.6'))->toBe(['id' => '0']);
    });

    it('maps the block alignment onto the figure class', function (mixed $align, string $class): void {
        $calls = new ArrayObject;
        ProcessingContext::setEntryData(['_thumbnail_id' => '5']);

        featuredImageProcess(
            featuredImageProcessorMock(['type' => 'set'], $calls),
            [featuredImageAttachment('5', 'https://example.org/a.jpg')],
            ['align' => $align]
        );

        expect(data_get($calls, '0.0'))->toBe('<figure class="wp-block-post-featured-image '.$class.'"><img src="https://example.org/a.jpg" /></figure>');
    })->with([
        'left' => ['left', 'alignleft'],
        'right' => ['right', 'alignright'],
        'wide' => ['wide', 'alignwide'],
        'not a string' => [['left'], 'aligncenter'],
    ]);

    it('keeps the attributes unchanged for an image hosted on another site', function (): void {
        $calls = new ArrayObject;
        ProcessingContext::setEntryData(['_thumbnail_id' => '5']);

        featuredImageProcess(
            featuredImageProcessorMock(['type' => 'set'], $calls),
            [featuredImageAttachment('5', 'https://other.org/a.jpg')],
            ['align' => 'left', 'id' => 9]
        );

        expect(data_get($calls, '0.6'))->toBe(['align' => 'left', 'id' => 9]);
    });

    it('treats every image as same-site when there is no base URL', function (): void {
        $calls = new ArrayObject;
        ProcessingContext::setEntryData(['_thumbnail_id' => '5']);

        featuredImageProcess(
            featuredImageProcessorMock(['type' => 'set'], $calls),
            [featuredImageAttachment('5', 'https://other.org/a.jpg')],
            importer: featuredImageImporter(null)
        );

        expect(data_get($calls, '0.6'))->toBe(['id' => '5']);
    });
});

describe('process when the image processor fails', function (): void {
    it('warns with the featured image data and returns null', function (?array $set): void {
        $warnings = featuredImageWarnings();
        ProcessingContext::setEntryData(['_thumbnail_id' => '5']);

        $result = featuredImageProcess(
            featuredImageProcessorMock($set, new ArrayObject),
            [featuredImageAttachment('5', 'https://example.org/a.jpg')]
        );

        expect($result)->toBeNull()
            ->and($warnings)->toHaveCount(1)
            ->and(data_get($warnings, '0.message'))->toBe('WpPostFeaturedImageBlockProcessor::process: ImageProcessor failed to generate image set')
            ->and(data_get($warnings, '0.context'))->toBe([
                'site' => 'default',
                'pageSlug' => 'about',
                'featured_image_data' => ['url' => 'https://example.org/a.jpg'],
            ]);
    })->with([
        'null' => [null],
        'empty' => [[]],
    ]);

    it('logs none for a missing page slug', function (): void {
        $warnings = featuredImageWarnings();
        ProcessingContext::setEntryData(['_thumbnail_id' => '5']);

        featuredImageProcess(
            featuredImageProcessorMock(null, new ArrayObject),
            [featuredImageAttachment('5', 'https://example.org/a.jpg')],
            pageSlug: null
        );

        expect(data_get($warnings, '0.context.pageSlug'))->toBe('none');
    });

    it('appends the entry URL when a WordPress page link is set', function (): void {
        $warnings = featuredImageWarnings();
        ProcessingContext::setWordPressPageLink('https://example.org/about/');

        featuredImageProcess(featuredImageProcessorMock(null, new ArrayObject));

        expect(data_get($warnings, '0.message'))->toBe(
            'WpPostFeaturedImageBlockProcessor::process: No _thumbnail_id found in entry data (from entry: https://example.org/about/)'
        )
            ->and(data_get($warnings, '0.context.entry_url'))->toBe('https://example.org/about/');
    });
});

describe('process result', function (): void {
    it('renders the set values as data attributes', function (): void {
        ProcessingContext::setEntryData(['_thumbnail_id' => '5']);
        $set = [
            'type' => 'set',
            'attrs' => [
                'values' => [
                    'type' => 'image',
                    'image' => 'images/a.jpg',
                    'alt' => 'Tom & "Jerry" <3',
                    'k<ey' => 'v',
                    'width' => 300,
                    'link' => null,
                    'sizes' => ['large'],
                    7 => 'numeric key',
                ],
            ],
        ];

        $result = featuredImageProcess(
            featuredImageProcessorMock($set, new ArrayObject),
            [featuredImageAttachment('5', 'https://example.org/a.jpg')]
        );

        expect(data_get($result, 'html'))->toBe(
            '<div data-set-type="image"'.SetMarkupNonce::attribute()
            .' data-set-attr-image="images/a.jpg"'
            .' data-set-attr-alt="Tom &amp; &quot;Jerry&quot; &lt;3"'
            .' data-set-attr-k&lt;ey="v"'
            .' data-set-attr-width="300"'
            .' data-set-attr-link=""'
            .' data-set-attr-sizes=""'
            .' data-set-attr-7="numeric key"'
            .'></div>'
        );
    });

    it('renders no data attributes when the set carries no array of values', function (array $set): void {
        ProcessingContext::setEntryData(['_thumbnail_id' => '5']);

        $result = featuredImageProcess(
            featuredImageProcessorMock($set, new ArrayObject),
            [featuredImageAttachment('5', 'https://example.org/a.jpg')]
        );

        expect(data_get($result, 'html'))->toBe('<div data-set-type="image"'.SetMarkupNonce::attribute().'></div>');
    })->with([
        'no attrs' => [['type' => 'set']],
        'attrs not an array' => [['type' => 'set', 'attrs' => 'x']],
        'no values' => [['type' => 'set', 'attrs' => ['id' => 'abc']]],
        'values not an array' => [['type' => 'set', 'attrs' => ['values' => 'x']]],
        'iterable values object' => [['type' => 'set', 'attrs' => ['values' => new ArrayIterator(['image' => 'images/a.jpg'])]]],
        'values holding only the type' => [['type' => 'set', 'attrs' => ['values' => ['type' => 'image']]]],
    ]);

    it('returns the comment node with nothing to skip', function (): void {
        ProcessingContext::setEntryData(['_thumbnail_id' => '5']);
        $node = (new DOMDocument)->createComment(' wp:post-featured-image /');

        $result = featuredImageProcess(
            featuredImageProcessorMock(['type' => 'set'], new ArrayObject),
            [featuredImageAttachment('5', 'https://example.org/a.jpg')],
            node: $node
        );

        expect($result)->toHaveKeys(['html', 'processedNode', 'skipCount'])
            ->and(data_get($result, 'processedNode'))->toBe($node)
            ->and(data_get($result, 'skipCount'))->toBe(0);
    });
});
