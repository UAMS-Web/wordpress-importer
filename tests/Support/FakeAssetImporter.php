<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Tests\Support;

use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\SameSitePolicy;

/**
 * An `AssetImporter` whose upload setting and image and file import results
 * are fixed, recording each image and file it is asked to import.
 *
 * A typed stand-in for the Mockery mocks a parser test would otherwise build:
 * every other method is the real one, so only the three overridden here are
 * fixed by the test.
 */
final class FakeAssetImporter extends AssetImporter
{
    /**
     * The `[attachmentId, site]` pair of each `importImage()` call, in order.
     *
     * @var list<array{string, string}>
     */
    public array $importedImages = [];

    /**
     * The `[url, site, container]` triple of each `importFile()` call, in order.
     *
     * @var list<array{string, string, string}>
     */
    public array $importedFiles = [];

    /**
     * Fix the upload setting and what `importImage()` and `importFile()` do.
     *
     * @param  bool  $uploads  What `getUploadImages()` returns
     * @param  string|Throwable|null  $imported  What `importImage()` returns, or throws
     * @param  ?string  $importedFile  What `importFile()` returns
     * @param  ?string  $baseBlogUrl  The WordPress base blog URL
     */
    public function __construct(
        private readonly bool $uploads = true,
        private readonly string|Throwable|null $imported = null,
        private readonly ?string $importedFile = null,
        ?string $baseBlogUrl = null
    ) {
        parent::__construct($baseBlogUrl);
    }

    /**
     * Report the fixed upload setting.
     *
     * @return bool Whether image uploads are enabled
     */
    #[\Override]
    public function getUploadImages(): bool
    {
        return $this->uploads;
    }

    /**
     * Record the call, then return or throw the fixed result.
     *
     * @param  string  $attachmentId  The attachment id to import
     * @param  string  $site  The site handle
     * @param  array<int|string, mixed>  $attachments  The attachments (ignored)
     * @param  OutputInterface  $output  The console output (ignored)
     * @param  SameSitePolicy  $sameSitePolicy  The same-site policy (ignored)
     * @param  ?string  $pageUrl  The page URL (ignored)
     * @param  string  $container  The asset container (ignored)
     * @param  bool  $hasFallbackUrl  Whether a fallback URL exists (ignored)
     * @return ?string The fixed import result
     */
    #[\Override]
    public function importImage(
        string $attachmentId,
        string $site,
        array $attachments,
        OutputInterface $output,
        SameSitePolicy $sameSitePolicy = SameSitePolicy::ALWAYS_IMPORT,
        ?string $pageUrl = null,
        string $container = 'images',
        bool $hasFallbackUrl = true
    ): ?string {
        $this->importedImages[] = [$attachmentId, $site];

        if ($this->imported instanceof Throwable) {
            throw $this->imported;
        }

        return $this->imported;
    }

    /**
     * Record the call, then return the fixed file path.
     *
     * @param  string  $url  The file URL to import
     * @param  string  $site  The site handle
     * @param  OutputInterface  $output  The console output (ignored)
     * @param  string  $container  The asset container
     * @param  SameSitePolicy  $sameSitePolicy  The same-site policy (ignored)
     * @param  ?string  $pageUrl  The page URL (ignored)
     * @param  ?string  $title  The file title (ignored)
     * @param  ?array<int|string, mixed>  $additionalFileMetadata  Extra metadata (ignored)
     * @param  ?string  $attachmentPageUrl  The attachment-page URL (ignored)
     * @return ?string The fixed file path
     */
    #[\Override]
    public function importFile(
        string $url,
        string $site,
        OutputInterface $output,
        string $container = 'files',
        SameSitePolicy $sameSitePolicy = SameSitePolicy::SKIP_DIFFERENT_SITE,
        ?string $pageUrl = null,
        ?string $title = null,
        ?array $additionalFileMetadata = null,
        ?string $attachmentPageUrl = null
    ): ?string {
        $this->importedFiles[] = [$url, $site, $container];

        return $this->importedFile;
    }
}
