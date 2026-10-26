<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ImageProcessor\AssetImporterProcessor;
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
 * Builds a WXR attachment item carrying a post id and an attachment URL.
 */
function assetImporterAttachment(string $postId, string $url): SimpleXMLElement
{
    return new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        ."<wp:post_id>{$postId}</wp:post_id>"
        ."<wp:attachment_url>{$url}</wp:attachment_url>"
        .'</item>'
    );
}

/**
 * Builds an AssetImporter mock with uploads enabled and the given base URL.
 */
function assetImporterMock(?string $baseBlogUrl = 'https://example.org'): AssetImporter&MockInterface
{
    $importer = Mockery::mock(AssetImporter::class);
    $importer->shouldReceive('getUploadImages')->andReturn(true);
    $importer->shouldReceive('getBaseBlogUrl')->andReturn($baseBlogUrl);

    return $importer;
}

/**
 * Routes Log::warning calls into the returned collector.
 *
 * @return ArrayObject<int, array{message: string, context: array<string, mixed>}>
 */
function assetImporterWarnings(): ArrayObject
{
    $warnings = new ArrayObject;

    Log::shouldReceive('warning')->andReturnUsing(
        function (string $message, array $context = []) use ($warnings): void {
            $warnings[] = ['message' => $message, 'context' => $context];
        }
    );

    return $warnings;
}

describe('a missing src', function (): void {
    it('warns and returns the context unchanged', function (array $context): void {
        $warnings = assetImporterWarnings();
        $importer = Mockery::mock(AssetImporter::class);
        $importer->shouldNotReceive('getUploadImages');

        $result = (new AssetImporterProcessor)->process(
            $context,
            'default',
            $importer,
            new BufferedOutput,
            [],
            'about'
        );

        expect($result)->toBe($context)
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0]['message'])->toBe('AssetImporterProcessor::process: Missing src in context')
            ->and($warnings[0]['context'])->toBe(['page_slug' => 'about']);
    })->with([
        'absent' => [['alt' => 'x']],
        'not a string' => [['src' => 42]],
        'empty' => [['src' => '']],
    ]);

    it('logs a null page slug when none is given', function (): void {
        $warnings = assetImporterWarnings();

        (new AssetImporterProcessor)->process(
            [],
            'default',
            Mockery::mock(AssetImporter::class),
            new BufferedOutput
        );

        expect($warnings[0]['context'])->toBe(['page_slug' => null]);
    });
});

describe('uploads disabled', function (): void {
    it('uses the original URL and keeps the block attachment id', function (mixed $blockAttributes, mixed $expectedId): void {
        $importer = Mockery::mock(AssetImporter::class);
        $importer->shouldReceive('getUploadImages')->andReturn(false);
        $importer->shouldNotReceive('importImage');
        $importer->shouldNotReceive('importFile');
        $output = new BufferedOutput;

        $context = ['src' => 'https://example.org/a.jpg'];

        if ($blockAttributes !== 'unset') {
            $context['blockAttributes'] = $blockAttributes;
        }

        $result = (new AssetImporterProcessor)->process($context, 'default', $importer, $output);

        expect($result['imagePath'])->toBe('https://example.org/a.jpg')
            ->and($result)->toHaveKey('attachmentId')
            ->and($result['attachmentId'])->toBe($expectedId)
            ->and($output->fetch())->toBe('  - Image uploads disabled. Using original URL for image processing.'.PHP_EOL);
    })->with([
        'block id' => [['id' => 562], 562],
        'block without id' => [['align' => 'left'], null],
        'non-array attributes' => ['wp-image-562', null],
        'no attributes' => ['unset', null],
    ]);
});

describe('import by attachment id', function (): void {
    it('imports the block id when the src matches no attachment', function (): void {
        $attachments = [assetImporterAttachment('900', 'https://example.org/other.png')];
        $output = new BufferedOutput;
        $importer = assetImporterMock();
        $importer->shouldReceive('importImage')
            ->once()
            ->with('562', 'default', $attachments, $output, SameSitePolicy::ALWAYS_IMPORT, null, 'images', true)
            ->andReturn('images/a.jpg');
        $importer->shouldNotReceive('importFile');

        $result = (new AssetImporterProcessor)->process(
            ['src' => 'https://example.org/a.jpg', 'blockAttributes' => ['id' => 562], 'alt' => 'A'],
            'default',
            $importer,
            $output,
            $attachments
        );

        expect($result)->toBe([
            'src' => 'https://example.org/a.jpg',
            'blockAttributes' => ['id' => 562],
            'alt' => 'A',
            'imagePath' => 'images/a.jpg',
            'attachmentId' => 562,
        ]);
    });

    it('prefers the attachment whose URL matches the src over the block id', function (): void {
        $attachments = [
            assetImporterAttachment('900', 'https://example.org/other.png'),
            assetImporterAttachment('777', 'https://example.org/a.jpg'),
        ];
        $importer = assetImporterMock();
        $importer->shouldReceive('importImage')
            ->once()
            ->withSomeOfArgs('777')
            ->andReturn('images/a.jpg');

        $result = (new AssetImporterProcessor)->process(
            ['src' => 'https://example.org/a.jpg', 'blockAttributes' => ['id' => 562]],
            'default',
            $importer,
            new BufferedOutput,
            $attachments
        );

        expect($result['imagePath'])->toBe('images/a.jpg')
            ->and($result['attachmentId'])->toBe('777');
    });

    it('resolves the attachment by URL when the block carries no id', function (): void {
        $attachments = [assetImporterAttachment('777', 'https://example.org/a.jpg')];
        $importer = assetImporterMock(null);
        $importer->shouldReceive('importImage')->once()->withSomeOfArgs('777')->andReturn('images/a.jpg');

        $result = (new AssetImporterProcessor)->process(
            ['src' => 'https://example.org/a.jpg'],
            'default',
            $importer,
            new BufferedOutput,
            $attachments
        );

        expect($result['attachmentId'])->toBe('777');
    });

    it('skips the id import for an empty or non-scalar id', function (mixed $id): void {
        $importer = assetImporterMock();
        $importer->shouldNotReceive('importImage');
        $importer->shouldReceive('importFile')->once()->andReturn('images/a.jpg');

        $result = (new AssetImporterProcessor)->process(
            ['src' => 'https://example.org/a.jpg', 'blockAttributes' => ['id' => $id]],
            'default',
            $importer,
            new BufferedOutput
        );

        expect($result['imagePath'])->toBe('images/a.jpg')
            ->and($result['attachmentId'])->toBe($id);
    })->with([
        'empty string' => [''],
        'array' => [[562]],
    ]);

    it('imports a zero id, which is scalar and non-empty as a string', function (): void {
        $importer = assetImporterMock();
        $importer->shouldReceive('importImage')->once()->withSomeOfArgs('0')->andReturn('images/a.jpg');

        $result = (new AssetImporterProcessor)->process(
            ['src' => 'https://example.org/a.jpg', 'blockAttributes' => ['id' => 0]],
            'default',
            $importer,
            new BufferedOutput
        );

        expect($result['attachmentId'])->toBe(0);
    });
});

describe('a cross-site src', function (): void {
    it('skips every attachment lookup and imports the src directly', function (): void {
        $attachments = [assetImporterAttachment('777', 'https://other.org/a.jpg')];
        $output = new BufferedOutput;
        $importer = assetImporterMock('https://example.org');
        $importer->shouldNotReceive('importImage');
        $importer->shouldReceive('importFile')
            ->once()
            ->with('https://other.org/a.jpg', 'default', $output, 'images', SameSitePolicy::ALWAYS_IMPORT)
            ->andReturn('images/a.jpg');

        $result = (new AssetImporterProcessor)->process(
            ['src' => 'https://other.org/a.jpg', 'blockAttributes' => ['id' => 562]],
            'default',
            $importer,
            $output,
            $attachments
        );

        expect($result['imagePath'])->toBe('images/a.jpg')
            ->and($result['attachmentId'])->toBe(562);
    });
});

describe('the basename fallback', function (): void {
    it('imports the attachment whose filename the sized src contains', function (): void {
        // The sized src matches no attachment URL, so the id import runs on the
        // block id first and fails before the basename loop takes over
        $attachments = [
            'not xml',
            assetImporterAttachment('100', ''),
            assetImporterAttachment('', 'https://example.org/photo.jpg'),
            assetImporterAttachment('200', 'https://example.org/unrelated.jpg'),
            assetImporterAttachment('300', 'https://example.org/uploads/photo.jpg'),
            assetImporterAttachment('400', 'https://example.org/uploads/photo-2.jpg'),
        ];
        $importer = assetImporterMock();
        $importer->shouldReceive('importImage')->once()->ordered()->withSomeOfArgs('562')->andReturn(null);
        $importer->shouldReceive('importImage')
            ->once()
            ->ordered()
            ->with('300', 'default', $attachments, Mockery::any(), SameSitePolicy::ALWAYS_IMPORT, null, 'images', true)
            ->andReturn('images/photo.jpg');
        $importer->shouldNotReceive('importFile');

        $result = (new AssetImporterProcessor)->process(
            ['src' => 'https://example.org/cdn/photo-300x200.jpg', 'blockAttributes' => ['id' => 562]],
            'default',
            $importer,
            new BufferedOutput,
            $attachments
        );

        expect($result['imagePath'])->toBe('images/photo.jpg')
            ->and($result['attachmentId'])->toBe('300');
    });

    it('imports the matched full-size URL when the matched id import fails', function (string $failure): void {
        $warnings = assetImporterWarnings();
        $attachments = [assetImporterAttachment('300', 'https://example.org/uploads/photo.jpg')];
        $output = new BufferedOutput;
        $importer = assetImporterMock();
        $importer->shouldReceive('importImage')->once()->withSomeOfArgs('300')->andReturn($failure === 'null' ? null : '');
        $importer->shouldReceive('importFile')
            ->once()
            ->with('https://example.org/uploads/photo.jpg', 'default', $output, 'images', SameSitePolicy::ALWAYS_IMPORT)
            ->andReturn('images/photo.jpg');

        $result = (new AssetImporterProcessor)->process(
            ['src' => 'https://example.org/cdn/photo-300x200.jpg'],
            'default',
            $importer,
            $output,
            $attachments
        );

        // A failed matched import keeps the original (absent) id
        expect($result['imagePath'])->toBe('images/photo.jpg')
            ->and($result)->toHaveKey('attachmentId')
            ->and($result['attachmentId'])->toBeNull()
            ->and($warnings)->toHaveCount(0);
    })->with(['null', 'empty']);

    it('falls back to the id import result being empty rather than null', function (): void {
        $importer = assetImporterMock();
        $importer->shouldReceive('importImage')->once()->withSomeOfArgs('562')->andReturn('');
        $importer->shouldReceive('importFile')->once()->andReturn('images/a.jpg');

        $result = (new AssetImporterProcessor)->process(
            ['src' => 'https://example.org/a.jpg', 'blockAttributes' => ['id' => 562]],
            'default',
            $importer,
            new BufferedOutput
        );

        expect($result['imagePath'])->toBe('images/a.jpg')
            ->and($result['attachmentId'])->toBe(562);
    });
});

describe('a failed fallback', function (): void {
    it('warns with the matched URLs and returns the context unchanged', function (): void {
        $warnings = assetImporterWarnings();
        $attachments = [assetImporterAttachment('300', 'https://example.org/uploads/photo.jpg')];
        $importer = assetImporterMock();
        $importer->shouldReceive('importImage')->andReturn(null);
        $importer->shouldReceive('importFile')->once()->andReturn(null);

        $context = ['src' => 'https://example.org/cdn/photo-300x200.jpg', 'blockAttributes' => ['id' => 562]];

        $result = (new AssetImporterProcessor)->process($context, 'default', $importer, new BufferedOutput, $attachments, 'about');

        expect($result)->toBe($context)
            ->and($warnings)->toHaveCount(1)
            ->and($warnings[0]['message'])->toBe('AssetImporterProcessor::process: Failed to import image using fallback URL (both with and without size suffix)')
            ->and($warnings[0]['context'])->toBe([
                'site' => 'default',
                'pageSlug' => 'about',
                'src' => 'https://example.org/cdn/photo-300x200.jpg',
                'attachment_id' => 562,
                'full_size_url' => 'https://example.org/uploads/photo.jpg',
                'original_url_with_size_suffix' => 'https://example.org/cdn/photo-300x200.jpg',
            ]);
    });

    it('omits the attachment id and URL variants when there are none', function (mixed $blockAttributes, string $failure): void {
        $warnings = assetImporterWarnings();
        $importer = assetImporterMock('https://example.org');
        $importer->shouldNotReceive('importImage');
        $importer->shouldReceive('importFile')->once()->andReturn($failure === 'null' ? null : '');

        $context = ['src' => 'https://other.org/a.jpg'];

        if ($blockAttributes !== 'unset') {
            $context['blockAttributes'] = $blockAttributes;
        }

        $result = (new AssetImporterProcessor)->process($context, 'blog', $importer, new BufferedOutput);

        expect($result)->toBe($context)
            ->and($warnings[0]['context'])->toBe([
                'site' => 'blog',
                'pageSlug' => 'none',
                'src' => 'https://other.org/a.jpg',
            ]);
    })->with([
        'no attributes, null' => ['unset', 'null'],
        'empty id, empty result' => [['id' => ''], 'empty'],
    ]);

    it('appends the entry URL when a WordPress page link is set', function (): void {
        $warnings = assetImporterWarnings();
        ProcessingContext::setWordPressPageLink('https://example.org/about/');
        $importer = assetImporterMock('https://example.org');
        $importer->shouldReceive('importFile')->andReturn(null);

        (new AssetImporterProcessor)->process(['src' => 'https://other.org/a.jpg'], 'default', $importer, new BufferedOutput);

        expect($warnings[0]['message'])->toBe(
            'AssetImporterProcessor::process: Failed to import image using fallback URL (both with and without size suffix) (from entry: https://example.org/about/)'
        )
            ->and($warnings[0]['context']['entry_url'])->toBe('https://example.org/about/');
    });
});
