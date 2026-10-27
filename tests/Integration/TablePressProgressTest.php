<?php

declare(strict_types=1);

use Statamic\Facades\Site;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\TablePressEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * The progress `TablePressEntryCreator::createEntries()` reports over a streamed export (#182).
 *
 * The two tables carry no `_tablepress_export_table_id`, so the loop skips each
 * one before writing anything, and the test needs no `tables` collection. The
 * loop still completes both items, which is what the progress counts.
 *
 * @command  php vendor/bin/pest tests/Integration/TablePressProgressTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    TablePressEntryCreator::clearTableCache();
});

/**
 * Run the creator over a two-table export.
 *
 * @param  OutputInterface  $output  The output the run writes to
 */
function tablePressProgressRun(
    OutputInterface $output
): void {
    $path = TemporaryFileRegistry::create('tablepress-progress');

    \file_put_contents($path, <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>TablePress progress fixture</title>
<link>https://example.test</link>
<wp:base_blog_url>https://example.test</wp:base_blog_url>
<item>
  <title>First table</title>
  <content:encoded><![CDATA[[["a","b"]]]]></content:encoded>
  <wp:post_id>601</wp:post_id>
  <wp:status>publish</wp:status>
  <wp:post_type>tablepress_table</wp:post_type>
</item>
<item>
  <title>Second table</title>
  <content:encoded><![CDATA[[["c","d"]]]]></content:encoded>
  <wp:post_id>602</wp:post_id>
  <wp:status>publish</wp:status>
  <wp:post_type>tablepress_table</wp:post_type>
</item>
</channel></rss>
XML);

    $parser = new XmlParser;
    $parser->parse($path);

    $contentProcessor = Mockery::mock(ContentProcessor::class);

    if (! $contentProcessor instanceof ContentProcessor) {
        throw new RuntimeException('Could not build the content processor stub.');
    }

    (new TablePressEntryCreator)->createEntries(
        'default',
        'tables',
        $parser,
        new AssetImporter,
        $output,
        new MetadataMapper,
        $contentProcessor
    );
}

it('reports progress through the streamed tables', function (): void {
    $output = new BufferedOutput;

    tablePressProgressRun($output);

    $printed = $output->fetch();

    expect($printed)->toContain('Progress: tablepress_table 0 of 2 (0%)'.\PHP_EOL)
        ->and($printed)->toContain('Progress: tablepress_table 2 of 2 (100%)'.\PHP_EOL);
});

it('prints no progress for the streamed tables when the output is quiet', function (): void {
    $output = new BufferedOutput(OutputInterface::VERBOSITY_QUIET);

    tablePressProgressRun($output);

    expect($output->fetch())->not->toContain('Progress:');
});
