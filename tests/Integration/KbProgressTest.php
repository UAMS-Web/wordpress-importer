<?php

declare(strict_types=1);

use Statamic\Facades\Site;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\KbEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\EntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\MinervaKbSettings\Selector as MinervaKbSettingsSelector;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * The progress the knowledge base topic and article loops report (#182).
 *
 * Both loops are protected and write entries, so a probe subclass runs them
 * with the writes taken out: the topic loop's entry creation is overridden,
 * and the article loop is given items of another post type, which it skips.
 * Each loop still completes both items, which is what the progress counts.
 *
 * @command  php vendor/bin/pest tests/Integration/KbProgressTest.php
 */
beforeEach(function (): void {
    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);
});

/**
 * Build the probe creator with collaborators that are never called.
 *
 * @return KbProgressProbeCreator The probe
 */
function kbProgressProbe(): KbProgressProbeCreator
{
    $entryCreator = Mockery::mock(EntryCreator::class);
    $selector = Mockery::mock(MinervaKbSettingsSelector::class);

    if (! $entryCreator instanceof EntryCreator || ! $selector instanceof MinervaKbSettingsSelector) {
        throw new RuntimeException('Could not build the knowledge base collaborators.');
    }

    return new KbProgressProbeCreator($entryCreator, $selector);
}

afterEach(function (): void {
    Mockery::close();
});

it('reports progress through the knowledge base topics', function (): void {
    $output = new BufferedOutput;
    $probe = kbProgressProbe();

    $probe->seedTermMap([
        '31' => ['term_id' => '31', 'term_taxonomy' => 'kbtopic', 'parent_id' => '0'],
        '32' => ['term_id' => '32', 'term_taxonomy' => 'kbtopic', 'parent_id' => '0'],
    ]);
    $probe->runTopics(new XmlParser, $output);

    $printed = $output->fetch();

    expect($printed)->toContain('Progress: kb topics 0 of 2 (0%)'.\PHP_EOL)
        ->and($printed)->toContain('Progress: kb topics 2 of 2 (100%)'.\PHP_EOL)
        ->and($probe->createdTopics)->toBe(['31', '32']);
});

it('reports progress through the knowledge base articles', function (): void {
    $output = new BufferedOutput;
    $parser = new XmlParser;
    $parser->setItems([
        new SimpleXMLElement('<item xmlns:wp="http://wordpress.org/export/1.2/"><wp:post_type>page</wp:post_type></item>'),
        new SimpleXMLElement('<item xmlns:wp="http://wordpress.org/export/1.2/"><wp:post_type>page</wp:post_type></item>'),
    ]);

    kbProgressProbe()->runArticles($parser, $output);

    $printed = $output->fetch();

    expect($printed)->toContain('Progress: kb 0 of 2 (0%)'.\PHP_EOL)
        ->and($printed)->toContain('Progress: kb 2 of 2 (100%)'.\PHP_EOL)
        ->and($printed)->toContain('Created 0 article entries.');
});

/**
 * A knowledge base creator whose topic and article loops a test can run.
 */
final class KbProgressProbeCreator extends KbEntryCreator
{
    /**
     * The term ids the topic loop asked to create, in order.
     *
     * @var list<string>
     */
    public array $createdTopics = [];

    /**
     * Seed the term map the topic loop reads.
     *
     * @param  array<int|string, mixed>  $termMap  The term map, as `TermMapBuilder` builds it
     */
    public function seedTermMap(
        array $termMap
    ): void {
        $this->termMap = $termMap;
    }

    /**
     * Run the protected topic loop.
     *
     * @param  XmlParser  $xmlParser  The parser
     * @param  OutputInterface  $output  Console output
     */
    public function runTopics(
        XmlParser $xmlParser,
        OutputInterface $output
    ): void {
        $this->processTopics($xmlParser, $output, 'default', 'knowledge_bases');
    }

    /**
     * Run the protected article loop.
     *
     * @param  XmlParser  $xmlParser  The parser
     * @param  OutputInterface  $output  Console output
     */
    public function runArticles(
        XmlParser $xmlParser,
        OutputInterface $output
    ): void {
        $this->processArticles($xmlParser, $output, 'default', new AssetImporter, 'knowledge_bases');
    }

    /**
     * Record the topic instead of writing an entry for it.
     *
     * @param  array<int|string, mixed>  $termData  The term data
     * @param  string  $parentId  Parent entry ID
     * @param  OutputInterface  $output  Console output
     * @param  string  $site  Site handle
     * @param  string  $collectionHandle  Collection handle
     * @param  ?XmlParser  $xmlParser  The parser
     * @param  array<int|string, mixed>|null  $minervaKbSettings  MinervaKB settings
     * @return string A stand-in entry ID
     */
    protected function createTopicEntryFromData(
        array $termData,
        string $parentId,
        OutputInterface $output,
        string $site,
        string $collectionHandle,
        ?XmlParser $xmlParser = null,
        ?array $minervaKbSettings = null
    ): string {
        $termId = \is_string($termData['term_id'] ?? null) ? $termData['term_id'] : '';
        $this->createdTopics[] = $termId;

        return 'topic-'.$termId;
    }
}
