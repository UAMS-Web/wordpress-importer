<?php

declare(strict_types=1);

/**
 * Tests `AssetImporter`: the guards, caches and failure records of its three
 * import paths (an image by attachment ID, a thumbnail by attachment ID, and a
 * file by URL), what each hands the overwrite handler, the upload settings it
 * reads, and its accessors.
 *
 * The overwrite handler is replaced with a recording one, so nothing is
 * downloaded or written.
 *
 * @command  php vendor/bin/pest tests/Integration/AssetImporterTest.php
 */

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetDownloader;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\MetadataSetter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\OverwriteHandler;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * An overwrite handler that records each import and returns a queued result.
 */
final class AssetImporterTestHandler extends OverwriteHandler
{
    /**
     * The arguments of each image import, without the output and collaborators.
     *
     * @var list<list<mixed>>
     */
    public array $images = [];

    /**
     * The arguments of each file import, without the output and collaborators.
     *
     * @var list<list<mixed>>
     */
    public array $files = [];

    /**
     * The collaborators each import was handed.
     *
     * @var list<array{AssetDownloader, ?MetadataSetter}>
     */
    public array $collaborators = [];

    /**
     * The behaviors set, in order.
     *
     * @var list<string>
     */
    public array $behaviors = [];

    /**
     * Start with the results to return, in order.
     *
     * @param  list<?string>  $results  The result of each import
     */
    public function __construct(public array $results = []) {}

    /**
     * Record the behavior.
     *
     * @param  string  $behavior  The behavior
     */
    public function setBehavior(string $behavior): void
    {
        $this->behaviors[] = $behavior;

        parent::setBehavior($behavior);
    }

    /**
     * Record an image import and return the next result.
     *
     * @param  string  $url  The URL
     * @param  string  $path  The path
     * @param  string  $site  The site
     * @param  OutputInterface  $output  The output
     * @param  AssetDownloader  $downloader  The downloader
     * @param  MetadataSetter  $metadataSetter  The metadata setter
     * @param  string  $alt  The alt text
     * @param  string  $caption  The caption
     * @param  ?string  $pageUrl  The page URL
     * @param  ?string  $focus  The focal point
     * @param  ?string  $creator  The credit
     * @param  ?string  $attachmentPageUrl  The attachment page URL
     * @return ?string The next result
     */
    public function handleImageImport(
        string $url,
        string $path,
        string $site,
        OutputInterface $output,
        AssetDownloader $downloader,
        MetadataSetter $metadataSetter,
        string $alt = '',
        string $caption = '',
        ?string $pageUrl = null,
        ?string $focus = null,
        ?string $creator = null,
        ?string $attachmentPageUrl = null
    ): ?string {
        $this->images[] = [$url, $path, $site, $alt, $caption, $pageUrl, $focus, $creator, $attachmentPageUrl];
        $this->collaborators[] = [$downloader, $metadataSetter];

        return \array_shift($this->results);
    }

    /**
     * Record a file import and return the next result.
     *
     * @param  string  $url  The URL
     * @param  string  $path  The path
     * @param  string  $site  The site
     * @param  string  $container  The container
     * @param  OutputInterface  $output  The output
     * @param  AssetDownloader  $downloader  The downloader
     * @param  ?MetadataSetter  $metadataSetter  The metadata setter
     * @param  ?string  $pageUrl  The page URL
     * @param  ?string  $title  The title
     * @param  ?array<int|string, mixed>  $additionalFileMetadata  Extra metadata
     * @param  ?string  $attachmentPageUrl  The attachment page URL
     * @return ?string The next result
     */
    public function handleFileImport(
        string $url,
        string $path,
        string $site,
        string $container,
        OutputInterface $output,
        AssetDownloader $downloader,
        ?MetadataSetter $metadataSetter = null,
        ?string $pageUrl = null,
        ?string $title = null,
        ?array $additionalFileMetadata = null,
        ?string $attachmentPageUrl = null
    ): ?string {
        $this->files[] = [$url, $path, $site, $container, $pageUrl, $title, $additionalFileMetadata, $attachmentPageUrl];
        $this->collaborators[] = [$downloader, $metadataSetter];

        return \array_shift($this->results);
    }
}

/**
 * An importer that hands its imports to a recording handler.
 */
final class AssetImporterTestImporter extends AssetImporter
{
    /**
     * Build the importer around the given handler.
     *
     * @param  AssetImporterTestHandler  $handler  The handler
     * @param  ?string  $baseBlogUrl  The base blog URL
     */
    public function __construct(public AssetImporterTestHandler $handler, ?string $baseBlogUrl = null)
    {
        parent::__construct($baseBlogUrl);

        $this->overwriteHandler = $handler;
    }
}

/**
 * The warnings logged by a test.
 */
final class AssetImporterTestWarnings
{
    /**
     * Each warning's message and context.
     *
     * @var list<array{mixed, mixed}>
     */
    public array $logged = [];
}

/**
 * Record the warnings logged.
 *
 * @return AssetImporterTestWarnings The recorder
 */
function assetImporterTestWarnings(): AssetImporterTestWarnings
{
    $warnings = new AssetImporterTestWarnings;

    Log::shouldReceive('warning')->andReturnUsing(static function (mixed $message, mixed $context = []) use ($warnings): void {
        $warnings->logged[] = [$message, $context];
    });

    return $warnings;
}

/**
 * A WordPress attachment `<item>`.
 *
 * @param  string  $id  The post ID
 * @param  string  $url  The attachment URL
 * @param  string  $link  The attachment page URL
 * @param  string  $alt  The alt text
 * @param  string  $caption  The caption
 * @return SimpleXMLElement The item
 */
function assetImporterTestAttachmentItem(string $id, string $url, string $link = '', string $alt = '', string $caption = ''): SimpleXMLElement
{
    $xml = '<?xml version="1.0" encoding="UTF-8"?>'
        .'<item xmlns:wp="http://wordpress.org/export/1.2/" xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/">'
        .'<title>Photo</title>'
        ."<link>{$link}</link>"
        ."<wp:post_id>{$id}</wp:post_id>"
        ."<wp:attachment_url><![CDATA[{$url}]]></wp:attachment_url>"
        ."<excerpt:encoded><![CDATA[{$caption}]]></excerpt:encoded>"
        .'<wp:postmeta><wp:meta_key>_wp_attachment_image_alt</wp:meta_key>'
        ."<wp:meta_value><![CDATA[{$alt}]]></wp:meta_value></wp:postmeta>"
        .'</item>';

    $item = \simplexml_load_string($xml);

    if (! $item instanceof SimpleXMLElement) {
        throw new RuntimeException('Failed to parse the attachment fixture.');
    }

    return $item;
}

/**
 * The attachments every image test reads: a photo with alt text, a caption and
 * a padded page URL, and one with no URL.
 *
 * @return list<SimpleXMLElement> The attachments
 */
function assetImporterTestAttachments(): array
{
    return [
        assetImporterTestAttachmentItem('4', ''),
        assetImporterTestAttachmentItem('5', 'https://uams.edu/wp-content/uploads/2020/01/Photo.jpg', ' https://uams.edu/photo/ ', 'Alt', 'Caption'),
        assetImporterTestAttachmentItem('6', 'https://uams.edu/wp-content/uploads/2020/02/Plain.jpg'),
    ];
}

/**
 * Drop configuration keys, so a read falls back to its default.
 *
 * @param  string  $parent  The parent key
 * @param  list<string>  $keys  The keys to drop
 */
function assetImporterTestForget(string $parent, array $keys): void
{
    $values = config($parent);

    config([$parent => \is_array($values) ? \array_diff_key($values, \array_flip($keys)) : []]);
}

beforeEach(function (): void {
    AssetImporter::clearFileCache();
    ImportWarningManager::clearWarnings();
    config(['statamic.assets.lowercase' => false, 'wordpress-import.upload_images' => true, 'wordpress-import.upload_files' => true]);
});

afterEach(function (): void {
    AssetImporter::clearFileCache();
    ImportWarningManager::clearWarnings();
    Mockery::close();
});

describe('building the importer', function (): void {
    it('reads the upload settings', function (mixed $images, mixed $files, bool $expectedImages, bool $expectedFiles): void {
        config(['wordpress-import.upload_images' => $images, 'wordpress-import.upload_files' => $files]);

        $importer = new AssetImporter;

        expect($importer->getUploadImages())->toBe($expectedImages)
            ->and($importer->getUploadFiles())->toBe($expectedFiles);
    })->with([
        'both on' => [true, true, true, true],
        'images off' => [false, true, false, true],
        'files off' => [true, false, true, false],
        'truthy and falsy values' => [1, 0, true, false],
    ]);

    it('uploads both kinds when the settings are absent', function (): void {
        assetImporterTestForget('wordpress-import', ['upload_images', 'upload_files']);

        $importer = new AssetImporter;

        expect($importer->getUploadImages())->toBeTrue()
            ->and($importer->getUploadFiles())->toBeTrue();
    });

    it('keeps the base blog URL it is given or set', function (): void {
        $importer = new AssetImporter('https://uams.edu');

        expect($importer->getBaseBlogUrl())->toBe('https://uams.edu');

        $importer->setBaseBlogUrl(null);

        expect($importer->getBaseBlogUrl())->toBeNull()
            ->and((new AssetImporter)->getBaseBlogUrl())->toBeNull();
    });

    it('hands the same downloader and metadata setter to every import', function (): void {
        $handler = new AssetImporterTestHandler(['news/a.jpg', 'news/b.pdf']);
        $importer = new AssetImporterTestImporter($handler);

        $importer->importImage('5', 'news', assetImporterTestAttachments(), new BufferedOutput);
        $importer->importFile('https://uams.edu/b.pdf', 'news', new BufferedOutput);

        expect($handler->collaborators)->toBe([
            [$importer->getDownloader(), $importer->getMetadataSetter()],
            [$importer->getDownloader(), $importer->getMetadataSetter()],
        ])
            ->and($importer->getOverwriteHandler())->toBe($handler);
    });

    it('passes the overwrite behavior to its handler', function (): void {
        $handler = new AssetImporterTestHandler;

        (new AssetImporterTestImporter($handler))->setAssetOverwriteBehavior('Skip matching files');

        expect($handler->behaviors)->toBe(['Skip matching files']);
    });
});

describe('importing an image', function (): void {
    it('hands the attachment\'s file, text and page URL to the handler and caches the path', function (): void {
        $handler = new AssetImporterTestHandler(['news/2020-01-Photo.jpg']);
        $importer = new AssetImporterTestImporter($handler);
        $output = new BufferedOutput;

        expect($importer->importImage('5', 'news', assetImporterTestAttachments(), $output, pageUrl: 'https://uams.edu/page'))->toBe('news/2020-01-Photo.jpg')
            ->and($handler->images)->toBe([[
                'https://uams.edu/wp-content/uploads/2020/01/Photo.jpg',
                'news/2020-01-Photo.jpg',
                'news',
                'Alt',
                'Caption',
                'https://uams.edu/page',
                '50-50-1',
                '',
                'https://uams.edu/photo/',
            ]]);

        // A second import of the same attachment into the same container reads the cache
        expect($importer->importImage('5', 'news', assetImporterTestAttachments(), $output))->toBe('news/2020-01-Photo.jpg')
            ->and($handler->images)->toHaveCount(1)
            ->and($output->fetch())->toBe("  - Using cached image path for attachment ID '5' in container 'images': news/2020-01-Photo.jpg".PHP_EOL);
    });

    it('caches an image per container', function (): void {
        $handler = new AssetImporterTestHandler(['news/a.jpg', 'news/b.jpg']);
        $importer = new AssetImporterTestImporter($handler);

        $importer->importImage('5', 'news', assetImporterTestAttachments(), new BufferedOutput);

        expect($importer->importImage('5', 'news', assetImporterTestAttachments(), new BufferedOutput, container: 'portraits'))->toBe('news/b.jpg')
            ->and($handler->images)->toHaveCount(2);
    });

    it('hands no page URL when the attachment has none', function (): void {
        $handler = new AssetImporterTestHandler(['news/p.jpg']);

        (new AssetImporterTestImporter($handler))->importImage('6', 'news', assetImporterTestAttachments(), new BufferedOutput);

        expect(\array_slice($handler->images[0] ?? [], 8))->toBe([null]);
    });

    it('warns and imports nothing without an attachment ID', function (?string $pageUrl, array $entry, string $suffix): void {
        $warnings = assetImporterTestWarnings();
        $handler = new AssetImporterTestHandler;
        $output = new BufferedOutput;

        expect((new AssetImporterTestImporter($handler))->importImage('', 'news', assetImporterTestAttachments(), $output, pageUrl: $pageUrl))->toBeNull()
            ->and($handler->images)->toBe([])
            ->and($output->fetch())->toBe('')
            ->and($warnings->logged)->toBe([['AssetImporter::importImage: No attachment ID provided for image import'.$suffix, ['site' => 'news', ...$entry]]]);
    })->with([
        'with a page URL' => ['https://uams.edu/page', ['entry_url' => 'https://uams.edu/page'], ' (from entry: https://uams.edu/page)'],
        'without one' => [null, [], ''],
    ]);

    it('imports nothing when image uploads are off', function (): void {
        config(['wordpress-import.upload_images' => false]);
        $handler = new AssetImporterTestHandler;
        $output = new BufferedOutput;

        expect((new AssetImporterTestImporter($handler))->importImage('5', 'news', assetImporterTestAttachments(), $output))->toBeNull()
            ->and($handler->images)->toBe([])
            ->and($output->fetch())->toBe('');
    });

    it('assumes a fallback URL unless told there is none', function (): void {
        $warnings = assetImporterTestWarnings();

        (new AssetImporterTestImporter(new AssetImporterTestHandler))->importImage('9', 'news', assetImporterTestAttachments(), new BufferedOutput);

        expect($warnings->logged)->toBe([]);
    });

    it('says when there is no attachment, warning only when there is no fallback URL either', function (bool $hasFallback, array $expected): void {
        $warnings = assetImporterTestWarnings();
        $output = new BufferedOutput;

        expect((new AssetImporterTestImporter(new AssetImporterTestHandler))->importImage('9', 'news', assetImporterTestAttachments(), $output, hasFallbackUrl: $hasFallback))->toBeNull()
            ->and($output->fetch())->toBe("  - No attachment found for ID '9'.".PHP_EOL)
            ->and($warnings->logged)->toBe($expected);
    })->with([
        'with a fallback' => [true, []],
        'without one' => [false, [["AssetImporter::importImage: No attachment found for ID '9' and no fallback URL available", ['site' => 'news', 'attachment_id' => '9']]]],
    ]);

    it('warns and imports nothing for an attachment without a URL', function (): void {
        $warnings = assetImporterTestWarnings();
        $handler = new AssetImporterTestHandler;
        $output = new BufferedOutput;

        expect((new AssetImporterTestImporter($handler))->importImage('4', 'news', assetImporterTestAttachments(), $output))->toBeNull()
            ->and($handler->images)->toBe([])
            ->and($output->fetch())->toBe("  - No attachment URL found for attachment ID '4'.".PHP_EOL)
            ->and($warnings->logged)->toBe([["AssetImporter::importImage: No attachment URL found for ID '4'", ['site' => 'news']]]);
    });

    it('records a failed image, warns, and does not try its URL again', function (?string $result): void {
        $warnings = assetImporterTestWarnings();
        $handler = new AssetImporterTestHandler([$result]);
        $importer = new AssetImporterTestImporter($handler);

        expect($importer->importImage('5', 'news', assetImporterTestAttachments(), new BufferedOutput, pageUrl: 'https://uams.edu/page', container: 'portraits'))->toBe($result)
            ->and($warnings->logged)->toBe([[
                "AssetImporter::importImage: Failed to import image for attachment ID '5' (from entry: https://uams.edu/page)",
                ['site' => 'news', 'url' => 'https://uams.edu/wp-content/uploads/2020/01/Photo.jpg', 'entry_url' => 'https://uams.edu/page'],
            ]])
            ->and(ImportWarningManager::getWarnings('assets', 'dropped_assets'))->toBe([[
                'attachment_id' => '5',
                'url' => 'https://uams.edu/wp-content/uploads/2020/01/Photo.jpg',
                'container' => 'portraits',
                'site' => 'news',
                'page_url' => 'https://uams.edu/page',
            ]]);

        $output = new BufferedOutput;

        expect($importer->importImage('5', 'news', assetImporterTestAttachments(), $output))->toBeNull()
            ->and($handler->images)->toHaveCount(1)
            ->and($output->fetch())->toBe("  - Skipping previously failed image 'https://uams.edu/wp-content/uploads/2020/01/Photo.jpg'.".PHP_EOL);
    })->with([
        'no path' => [null],
        'an empty path' => [''],
    ]);

    it('applies the same-site policy to an image on another site', function (SameSitePolicy $policy, ?string $expected): void {
        // The skip policy imports anyway (#232)
        $handler = new AssetImporterTestHandler(['news/p.jpg']);

        expect((new AssetImporterTestImporter($handler, 'https://example.org'))->importImage('5', 'news', assetImporterTestAttachments(), new BufferedOutput, $policy))->toBe($expected)
            ->and($handler->images)->toHaveCount($expected === 'news/p.jpg' ? 1 : 0);
    })->with([
        'link' => [SameSitePolicy::LINK_DIFFERENT_SITE, 'https://uams.edu/wp-content/uploads/2020/01/Photo.jpg'],
        'skip' => [SameSitePolicy::SKIP_DIFFERENT_SITE, 'news/p.jpg'],
        'import' => [SameSitePolicy::ALWAYS_IMPORT, 'news/p.jpg'],
    ]);
});

describe('importing a thumbnail', function (): void {
    it('hands the attachment\'s file, text and page URL to the handler and caches the path', function (): void {
        $handler = new AssetImporterTestHandler(['news/2020-01-Photo.jpg']);
        $importer = new AssetImporterTestImporter($handler);
        $output = new BufferedOutput;

        expect($importer->importThumbnail('5', assetImporterTestAttachments(), 'news', $output, pageUrl: 'https://uams.edu/page'))->toBe('news/2020-01-Photo.jpg')
            ->and($handler->images)->toBe([[
                'https://uams.edu/wp-content/uploads/2020/01/Photo.jpg',
                'news/2020-01-Photo.jpg',
                'news',
                'Alt',
                'Caption',
                'https://uams.edu/page',
                '50-50-1',
                '',
                'https://uams.edu/photo/',
            ]]);

        expect($importer->importThumbnail('5', assetImporterTestAttachments(), 'news', $output))->toBe('news/2020-01-Photo.jpg')
            ->and($handler->images)->toHaveCount(1)
            ->and($output->fetch())->toBe("  - Using cached image path for thumbnail ID '5' in container 'images': news/2020-01-Photo.jpg".PHP_EOL);
    });

    it('shares the image cache, per container', function (): void {
        $handler = new AssetImporterTestHandler(['news/a.jpg', 'news/b.jpg']);
        $importer = new AssetImporterTestImporter($handler);

        $importer->importImage('5', 'news', assetImporterTestAttachments(), new BufferedOutput);

        expect($importer->importThumbnail('5', assetImporterTestAttachments(), 'news', new BufferedOutput))->toBe('news/a.jpg')
            ->and($importer->importThumbnail('5', assetImporterTestAttachments(), 'news', new BufferedOutput, container: 'portraits'))->toBe('news/b.jpg');
    });

    it('hands no page URL when the attachment has none', function (): void {
        $handler = new AssetImporterTestHandler(['news/p.jpg']);

        (new AssetImporterTestImporter($handler))->importThumbnail('6', assetImporterTestAttachments(), 'news', new BufferedOutput);

        expect(\array_slice($handler->images[0] ?? [], 8))->toBe([null]);
    });

    it('warns and imports nothing without a thumbnail ID', function (bool $uploads): void {
        config(['wordpress-import.upload_images' => $uploads]);
        $warnings = assetImporterTestWarnings();

        expect((new AssetImporterTestImporter(new AssetImporterTestHandler))->importThumbnail('', assetImporterTestAttachments(), 'news', new BufferedOutput, pageUrl: 'https://uams.edu/page'))->toBeNull()
            ->and($warnings->logged)->toBe([[
                'AssetImporter::importThumbnail: No thumbnail ID provided (from entry: https://uams.edu/page)',
                ['site' => 'news', 'attachments_count' => 3, 'upload_images_enabled' => $uploads, 'entry_url' => 'https://uams.edu/page'],
            ]]);
    })->with([
        'uploads on' => [true],
        'uploads off' => [false],
    ]);

    it('imports nothing when image uploads are off', function (): void {
        config(['wordpress-import.upload_images' => false]);
        $handler = new AssetImporterTestHandler;

        expect((new AssetImporterTestImporter($handler))->importThumbnail('5', assetImporterTestAttachments(), 'news', new BufferedOutput))->toBeNull()
            ->and($handler->images)->toBe([]);
    });

    it('warns when there is no attachment', function (): void {
        $warnings = assetImporterTestWarnings();
        $output = new BufferedOutput;

        expect((new AssetImporterTestImporter(new AssetImporterTestHandler))->importThumbnail('9', assetImporterTestAttachments(), 'news', $output))->toBeNull()
            ->and($output->fetch())->toBe('')
            ->and($warnings->logged)->toBe([["AssetImporter::importThumbnail: No attachment found for thumbnail ID '9'", ['site' => 'news']]]);
    });

    it('warns and imports nothing for an attachment without a URL', function (): void {
        $warnings = assetImporterTestWarnings();
        $handler = new AssetImporterTestHandler;
        $output = new BufferedOutput;

        expect((new AssetImporterTestImporter($handler))->importThumbnail('4', assetImporterTestAttachments(), 'news', $output))->toBeNull()
            ->and($handler->images)->toBe([])
            ->and($output->fetch())->toBe("  - No attachment URL found for thumbnail ID '4'.".PHP_EOL)
            ->and($warnings->logged)->toBe([["AssetImporter::importThumbnail: No attachment URL found for ID '4'", ['site' => 'news']]]);
    });

    it('records a failed thumbnail, warns, and does not try its URL again', function (?string $result): void {
        $warnings = assetImporterTestWarnings();
        $handler = new AssetImporterTestHandler([$result]);
        $importer = new AssetImporterTestImporter($handler);

        expect($importer->importThumbnail('5', assetImporterTestAttachments(), 'news', new BufferedOutput))->toBe($result)
            ->and($warnings->logged)->toBe([[
                "AssetImporter::importThumbnail: Failed to import thumbnail for ID '5'",
                ['site' => 'news', 'url' => 'https://uams.edu/wp-content/uploads/2020/01/Photo.jpg'],
            ]]);

        $output = new BufferedOutput;

        expect($importer->importThumbnail('5', assetImporterTestAttachments(), 'news', $output))->toBeNull()
            ->and($handler->images)->toHaveCount(1)
            ->and($output->fetch())->toBe("  - Skipping previously failed thumbnail 'https://uams.edu/wp-content/uploads/2020/01/Photo.jpg'.".PHP_EOL);
    })->with([
        'no path' => [null],
        'an empty path' => [''],
    ]);

    it('applies the same-site policy to a thumbnail on another site', function (SameSitePolicy $policy, ?string $expected): void {
        $handler = new AssetImporterTestHandler(['news/p.jpg']);

        expect((new AssetImporterTestImporter($handler, 'https://example.org'))->importThumbnail('5', assetImporterTestAttachments(), 'news', new BufferedOutput, $policy))->toBe($expected);
    })->with([
        'link' => [SameSitePolicy::LINK_DIFFERENT_SITE, 'https://uams.edu/wp-content/uploads/2020/01/Photo.jpg'],
        'import' => [SameSitePolicy::ALWAYS_IMPORT, 'news/p.jpg'],
    ]);
});

describe('importing a file', function (): void {
    it('hands the file and its metadata to the handler and caches the path', function (): void {
        $handler = new AssetImporterTestHandler(['news/2020-01-Report.pdf']);
        $importer = new AssetImporterTestImporter($handler);
        $output = new BufferedOutput;

        expect($importer->importFile('https://uams.edu/wp-content/uploads/2020/01/Report.pdf', 'news', $output, 'documents', SameSitePolicy::ALWAYS_IMPORT, 'https://uams.edu/page', 'Report', ['size' => 5], 'https://uams.edu/report/'))->toBe('news/2020-01-Report.pdf')
            ->and($handler->files)->toBe([[
                'https://uams.edu/wp-content/uploads/2020/01/Report.pdf',
                'news/2020-01-Report.pdf',
                'news',
                'documents',
                'https://uams.edu/page',
                'Report',
                ['size' => 5],
                'https://uams.edu/report/',
            ]])
            ->and($importer->getFileCache())->toBe(['https://uams.edu/wp-content/uploads/2020/01/Report.pdf:documents' => 'news/2020-01-Report.pdf']);

        $output->fetch();

        expect($importer->importFile('https://uams.edu/wp-content/uploads/2020/01/Report.pdf', 'news', $output, 'documents'))->toBe('news/2020-01-Report.pdf')
            ->and($handler->files)->toHaveCount(1)
            ->and($output->fetch())->toBe("  - Using cached file path for 'https://uams.edu/wp-content/uploads/2020/01/Report.pdf' in container 'documents': news/2020-01-Report.pdf".PHP_EOL);
    });

    it('imports into the files container, without a site, when given neither', function (): void {
        $handler = new AssetImporterTestHandler(['2020-01-Report.pdf']);

        (new AssetImporterTestImporter($handler))->importFile('https://uams.edu/wp-content/uploads/2020/01/Report.pdf', '', new BufferedOutput);

        expect($handler->files[0] ?? [])->toBe(['https://uams.edu/wp-content/uploads/2020/01/Report.pdf', '2020-01-Report.pdf', '', 'files', null, null, null, null]);
    });

    it('shares the cache between sizes of one image', function (): void {
        $handler = new AssetImporterTestHandler(['news/p.jpg']);
        $importer = new AssetImporterTestImporter($handler);

        $importer->setFileCache('https://uams.edu/photo.jpg', 'news/cached.jpg', 'images');

        expect($importer->importFile('https://uams.edu/photo-300x200.jpg', 'news', new BufferedOutput, 'images'))->toBe('news/cached.jpg')
            ->and($importer->getFileCache())->toBe(['https://uams.edu/photo.jpg:images' => 'news/cached.jpg'])
            ->and($handler->files)->toBe([]);
    });

    it('caches into the files container by default', function (): void {
        $importer = new AssetImporterTestImporter(new AssetImporterTestHandler);

        $importer->setFileCache('https://uams.edu/a.pdf', 'news/a.pdf');

        expect($importer->getFileCache())->toBe(['https://uams.edu/a.pdf:files' => 'news/a.pdf']);
    });

    it('warns and imports nothing without a URL', function (): void {
        $warnings = assetImporterTestWarnings();

        expect((new AssetImporterTestImporter(new AssetImporterTestHandler))->importFile('', 'news', new BufferedOutput))->toBeNull()
            ->and($warnings->logged)->toBe([['AssetImporter::importFile: No URL provided for file import', ['site' => 'news']]]);
    });

    it('imports nothing when file uploads are off', function (): void {
        config(['wordpress-import.upload_files' => false]);
        $handler = new AssetImporterTestHandler;

        expect((new AssetImporterTestImporter($handler))->importFile('https://uams.edu/a.pdf', 'news', new BufferedOutput))->toBeNull()
            ->and($handler->files)->toBe([]);
    });

    it('imports a file on another site even under the skip policy, which is its default', function (SameSitePolicy $policy, ?string $expected): void {
        // Characterization of #232: the policy check returns null both to skip
        // and to carry on, and the importer carries on
        $handler = new AssetImporterTestHandler(['news/a.pdf']);
        $importer = new AssetImporterTestImporter($handler, 'https://example.org');

        $result = $policy === SameSitePolicy::SKIP_DIFFERENT_SITE
            ? $importer->importFile('https://uams.edu/a.pdf', 'news', new BufferedOutput)
            : $importer->importFile('https://uams.edu/a.pdf', 'news', new BufferedOutput, sameSitePolicy: $policy);

        expect($result)->toBe($expected);
    })->with([
        'the default, skip' => [SameSitePolicy::SKIP_DIFFERENT_SITE, 'news/a.pdf'],
        'link' => [SameSitePolicy::LINK_DIFFERENT_SITE, 'https://uams.edu/a.pdf'],
        'import' => [SameSitePolicy::ALWAYS_IMPORT, 'news/a.pdf'],
    ]);

    it('records a failed sized image under both its URLs, warns, and does not try either again', function (?string $result): void {
        $warnings = assetImporterTestWarnings();
        $handler = new AssetImporterTestHandler([$result]);
        $importer = new AssetImporterTestImporter($handler);

        expect($importer->importFile('https://uams.edu/wp-content/uploads/2020/01/p-300x200.jpg', 'news', new BufferedOutput, pageUrl: 'https://uams.edu/page'))->toBe($result)
            ->and($warnings->logged)->toBe([[
                "AssetImporter::importFile: Failed to import file for URL 'https://uams.edu/wp-content/uploads/2020/01/p-300x200.jpg' (from entry: https://uams.edu/page)",
                ['site' => 'news', 'path' => 'news/2020-01-p-300x200.jpg', 'entry_url' => 'https://uams.edu/page'],
            ]]);

        foreach (['https://uams.edu/wp-content/uploads/2020/01/p-300x200.jpg', 'https://uams.edu/wp-content/uploads/2020/01/p.jpg'] as $url) {
            $output = new BufferedOutput;

            expect($importer->importFile($url, 'news', new BufferedOutput, 'other'))->toBeNull()
                ->and($importer->importFile($url, 'news', $output))->toBeNull()
                ->and($output->fetch())->toBe("  - Skipping previously failed asset '{$url}'.".PHP_EOL);
        }

        expect($handler->files)->toHaveCount(1);
    })->with([
        'no path' => [null],
        'an empty path' => [''],
    ]);

    it('skips a sized URL whose unsized form failed', function (): void {
        assetImporterTestWarnings();
        $handler = new AssetImporterTestHandler([null, 'news/p-300x200.jpg']);
        $importer = new AssetImporterTestImporter($handler);

        $importer->importFile('https://uams.edu/p.jpg', 'news', new BufferedOutput);

        // The sized URL reduces to the failed unsized one, so it is not tried
        expect($importer->importFile('https://uams.edu/p-300x200.jpg', 'news', new BufferedOutput))->toBeNull()
            ->and($handler->files)->toHaveCount(1);
    });
});

describe('clearing the caches', function (): void {
    it('forgets cached paths and failures', function (): void {
        assetImporterTestWarnings();
        $handler = new AssetImporterTestHandler(['news/a.jpg', null, 'news/a.jpg', 'news/b.jpg', 'news/c.pdf']);
        $importer = new AssetImporterTestImporter($handler);

        $importer->importImage('5', 'news', assetImporterTestAttachments(), new BufferedOutput);
        $importer->importImage('6', 'news', assetImporterTestAttachments(), new BufferedOutput);
        $importer->setFileCache('https://uams.edu/c.pdf', 'news/old.pdf');

        AssetImporter::clearFileCache();

        $importer->importImage('5', 'news', assetImporterTestAttachments(), new BufferedOutput);
        $importer->importImage('6', 'news', assetImporterTestAttachments(), new BufferedOutput);

        expect($importer->getFileCache())->toBe([])
            ->and($importer->importFile('https://uams.edu/c.pdf', 'news', new BufferedOutput))->toBe('news/c.pdf')
            ->and($handler->images)->toHaveCount(4);
    });
});

describe('normalizing attachment data', function (): void {
    it('keeps string values and replaces anything else', function (): void {
        $normalize = new ReflectionMethod(AssetImporter::class, 'normalizeAttachmentData');

        expect($normalize->invoke(null, ['url' => 'u', 'alt' => 'a', 'caption' => 'c', 'focus' => 'f', 'creator' => 'r']))
            ->toBe(['url' => 'u', 'alt' => 'a', 'caption' => 'c', 'focus' => 'f', 'creator' => 'r'])
            ->and($normalize->invoke(null, ['url' => 1, 'alt' => 2, 'caption' => 3, 'focus' => 4, 'creator' => 5]))
            ->toBe(['url' => '', 'alt' => '', 'caption' => '', 'focus' => null, 'creator' => ''])
            ->and($normalize->invoke(null, []))
            ->toBe(['url' => '', 'alt' => '', 'caption' => '', 'focus' => null, 'creator' => '']);
    });
});
