<?php

declare(strict_types=1);

use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\LogoListItemEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PageEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\PeopleEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * The progress the streamed entry loops report, for the creators no other standalone test drives (#182).
 *
 * Each creator runs over a two-item export, so its loop has a total to report.
 * The collaborators the creators need outside these loops are mocks that answer
 * nothing, because the assertion is the progress, not the entries.
 *
 * @command  php vendor/bin/pest tests/Integration/StreamedEntryProgressTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);
});

afterEach(function (): void {
    Mockery::close();
    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    ProcessingContext::clearXmlPath();

    // Blueprints are not Stache items, so `PreventsSavingStacheItemsToDisk` does not cover them
    foreach (['collections.pages.basic_page', 'collections.people.person', 'collections.logo_list_items.logo_list_item'] as $handle) {
        $blueprint = BlueprintFacade::find($handle);

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }

    TemporaryFileRegistry::sweep();
});

/**
 * Make a collection and the one blueprint the creator writes its entries with.
 *
 * @param  string  $collectionHandle  The collection handle
 * @param  string  $blueprintHandle  The blueprint handle
 */
function streamedProgressCollection(
    string $collectionHandle,
    string $blueprintHandle
): void {
    $collection = CollectionFacade::make($collectionHandle);
    $collection->sites(['default']);
    $collection->save();

    $blueprint = BlueprintFacade::make($blueprintHandle);
    $blueprint->setNamespace("collections.{$collectionHandle}");
    $blueprint->setContents([
        'fields' => [
            ['handle' => 'title', 'field' => ['type' => 'text']],
        ],
    ]);
    $blueprint->save();
}

/**
 * Parse an export of two published items of one post type.
 *
 * @param  string  $postType  The WordPress post type
 * @return XmlParser The parser over the export
 */
function streamedProgressParser(
    string $postType
): XmlParser {
    $items = '';

    foreach ([701 => 'first-item', 702 => 'second-item'] as $id => $slug) {
        $items .= <<<XML
<item>
  <title>{$slug}</title>
  <link>https://example.test/{$slug}/</link>
  <content:encoded><![CDATA[<p>Body.</p>]]></content:encoded>
  <wp:post_id>{$id}</wp:post_id>
  <wp:post_name>{$slug}</wp:post_name>
  <wp:post_date>2024-02-01 10:00:00</wp:post_date>
  <wp:status>publish</wp:status>
  <wp:post_type>{$postType}</wp:post_type>
</item>

XML;
    }

    $path = TemporaryFileRegistry::create('streamed-progress');

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Streamed progress fixture</title>
<link>https://example.test</link>
<wp:base_blog_url>https://example.test</wp:base_blog_url>
{$items}</channel></rss>
XML);

    $parser = new XmlParser;
    $parser->parse($path);

    return $parser;
}

/**
 * A content processor that returns one paragraph for any content.
 */
function streamedProgressContentProcessor(): ContentProcessor
{
    $contentProcessor = Mockery::mock(ContentProcessor::class, [
        'process' => [
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]]],
            'page_builder' => [],
        ],
    ]);

    if (! $contentProcessor instanceof ContentProcessor) {
        throw new RuntimeException('Could not build the content processor stub.');
    }

    return $contentProcessor;
}

/**
 * A mock of a collaborator that answers every call with nothing.
 *
 * @template T of object
 *
 * @param  class-string<T>  $class  The collaborator's class
 * @return T The mock
 */
function streamedProgressIgnoringMock(
    string $class
): object {
    $mock = Mockery::mock($class)->shouldIgnoreMissing();

    if (! $mock instanceof $class) {
        throw new RuntimeException("Could not build a mock of {$class}.");
    }

    return $mock;
}

it('reports progress through the streamed pages', function (): void {
    streamedProgressCollection('pages', 'basic_page');
    $output = new BufferedOutput;

    (new PageEntryCreator(streamedProgressIgnoringMock(EntryCreator::class)))->createEntries(
        'default',
        'pages',
        streamedProgressParser('page'),
        new AssetImporter,
        $output,
        new MetadataMapper,
        streamedProgressContentProcessor()
    );

    $printed = $output->fetch();

    expect($printed)->toContain('Progress: page 0 of 2 (0%)'.\PHP_EOL)
        ->and($printed)->toContain('Progress: page 2 of 2 (100%)'.\PHP_EOL);
});

it('reports progress through the streamed people', function (): void {
    streamedProgressCollection('people', 'person');
    $output = new BufferedOutput;

    (new PeopleEntryCreator)->createEntries(
        'default',
        'people',
        streamedProgressParser('uamspeople'),
        new AssetImporter,
        $output,
        new MetadataMapper,
        streamedProgressContentProcessor()
    );

    $printed = $output->fetch();

    expect($printed)->toContain('Progress: uamspeople 0 of 2 (0%)'.\PHP_EOL)
        ->and($printed)->toContain('Progress: uamspeople 2 of 2 (100%)'.\PHP_EOL);
});

it('reports progress through the streamed logo list items', function (): void {
    streamedProgressCollection('logo_list_items', 'logo_list_item');
    $output = new BufferedOutput;

    (new LogoListItemEntryCreator(
        streamedProgressIgnoringMock(SiteManager::class),
        streamedProgressIgnoringMock(CollectionStructureManager::class)
    ))->createEntries(
        'default',
        'logo_list_items',
        streamedProgressParser('logo-list-item'),
        new AssetImporter,
        $output,
        new MetadataMapper,
        streamedProgressContentProcessor()
    );

    $printed = $output->fetch();

    expect($printed)->toContain('Progress: logo-list-item 0 of 2 (0%)'.\PHP_EOL)
        ->and($printed)->toContain('Progress: logo-list-item 2 of 2 (100%)'.\PHP_EOL);
});
