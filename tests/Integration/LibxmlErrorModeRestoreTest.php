<?php

declare(strict_types=1);

/**
 * The HTML parsers leave libxml's internal-errors mode as they found it (wordpress-importer-archive#1354).
 *
 * `AcfBlockParser::parseAcfBlocksFromHtml()` and `ContentProcessor::process()`
 * switch the mode on to load HTML quietly. The mode is process-wide, so leaving
 * it on after the first page silences every later libxml warning in the import,
 * including the invalid-expression warning `XpathHelper` turns into a logged
 * error. Each case sets the mode both ways before the call and asserts it is
 * unchanged afterwards.
 *
 * @command  php vendor/bin/pest tests/Integration/LibxmlErrorModeRestoreTest.php
 */

use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\AcfBlockParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\PageBuilderParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * An asset importer that answers the base URL and ignores everything else.
 */
function libxmlModeAssetImporter(): AssetImporter
{
    /** @var AssetImporter&MockInterface $assetImporter */
    $assetImporter = Mockery::mock(
        AssetImporter::class,
        ['getBaseBlogUrl' => 'https://example.test/']
    )->shouldIgnoreMissing();

    return $assetImporter;
}

/**
 * A ContentProcessor built without its constructor.
 *
 * The constructor makes the host app's `App\Services\BardContentSanitizer`,
 * which this package's standalone suite does not have, so a pass-through
 * sanitizer stands in for it. Nothing here depends on sanitizing.
 */
function libxmlModeContentProcessor(): ContentProcessor
{
    $processor = (new ReflectionClass(ContentProcessor::class))->newInstanceWithoutConstructor();

    (new ReflectionProperty(ContentProcessor::class, 'pageBuilderParser'))->setValue($processor, new PageBuilderParser);
    (new ReflectionProperty(ContentProcessor::class, 'shortcodeProcessor'))->setValue($processor, new ShortcodeProcessor);
    (new ReflectionProperty(ContentProcessor::class, 'bardContentSanitizer'))->setValue($processor, new class
    {
        /**
         * Return the Bard field unchanged.
         *
         * @param  array<int|string, mixed>  $field  The Bard field
         * @return array<int|string, mixed> The same field
         */
        public function sanitize(array $field): array
        {
            return $field;
        }
    });

    return $processor;
}

/**
 * Remember libxml's internal-errors mode as the case found it, or read it back.
 *
 * @param  ?bool  $mode  The mode to remember, or null to read the remembered one
 * @return bool The remembered mode
 */
function libxmlModeBeforeCase(?bool $mode = null): bool
{
    static $remembered = false;

    if ($mode !== null) {
        $remembered = $mode;
    }

    // A function-level static reads as mixed to PHPStan; only a bool is stored
    return $remembered === true;
}

beforeEach(function (): void {
    libxmlModeBeforeCase(libxml_use_internal_errors());
});

afterEach(function (): void {
    libxml_clear_errors();
    libxml_use_internal_errors(libxmlModeBeforeCase());

    // process() records the page it handled; leave no trace for the next file
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ProcessingContext::clearCollectionHandle();
    ProcessingContext::clearStatamicEntryId();
    ProcessingContext::clearWordPressPostId();
});

it('leaves the mode as it found it after parsing ACF blocks', function (bool $mode): void {
    libxml_use_internal_errors($mode);

    // The WYSIWYG field goes through the Bard parser, whose tiptap-php DOMParser
    // switches the mode on without restoring it; unclosed markup after the block
    // makes libxml raise parse errors as well
    $block = '<!-- wp:acf/uams-content '.json_encode([
        'name' => 'acf/uams-content',
        'data' => ['content_heading' => 'Heading', 'content_content' => '<p>Body <b>text</p>'],
        'mode' => 'preview',
    ]).' /-->';

    (new AcfBlockParser)->parseAcfBlocksFromHtml(
        $block.'<div><p>unclosed',
        'default',
        libxmlModeAssetImporter(),
        new BufferedOutput,
    );

    expect(libxml_use_internal_errors())->toBe($mode)
        ->and(libxml_get_errors())->toBe([]);
})->with([
    'off' => [false],
    'on' => [true],
]);

it('leaves the mode as it found it after processing content', function (bool $mode): void {
    libxml_use_internal_errors($mode);

    libxmlModeContentProcessor()->process(
        '<p>Hello <b>world</p><div>unclosed',
        'default',
        libxmlModeAssetImporter(),
        new BufferedOutput,
        (new ReflectionClass(XmlParser::class))->newInstanceWithoutConstructor(),
        pageSlug: 'libxml-mode',
    );

    expect(libxml_use_internal_errors())->toBe($mode)
        ->and(libxml_get_errors())->toBe([]);
})->with([
    'off' => [false],
    'on' => [true],
]);
