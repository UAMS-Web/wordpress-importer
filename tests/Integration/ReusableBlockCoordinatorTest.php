<?php

declare(strict_types=1);

use App\Services\BardContentSanitizer;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\ReusableBlockEntryCreator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\ReusableBlocks\ReusableBlockCoordinator;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\CollectionStructureManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\WpBlockBlockProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\SiteManager;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\LfBufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\TemporaryFileRegistry;

/**
 * `ReusableBlockCoordinator` imports every `wp_block` the content referenced, then the ones
 * those blocks reference, until no new one turns up (#102). The entry writing itself is
 * `ReusableBlockEntryCreator`'s; this file records what the coordinator hands it.
 *
 * @command  php vendor/bin/pest tests/Integration/ReusableBlockCoordinatorTest.php
 */
uses(StatamicTestCase::class);

// The coordinator builds a ContentProcessor for each block, which builds the host app's Bard
// sanitizer; the stub stands in for it outside the host app
if (! class_exists(BardContentSanitizer::class)) {
    require_once __DIR__.'/../Support/IdeStubs/App/Services/BardContentSanitizer.php';
}

beforeEach(function (): void {
    WpBlockBlockProcessor::clearWpBlockPostIds();
});

afterEach(function (): void {
    WpBlockBlockProcessor::clearWpBlockPostIds();
    TemporaryFileRegistry::sweep();
});

/**
 * A site manager that records the collections it is asked to enable.
 */
final class ReusableBlockCoordinatorTestSiteManager extends SiteManager
{
    /** @var list<array{string, string}> */
    public array $enabled = [];

    public function __construct() {}

    public function enableCollectionForSite(string $site, string $collectionHandle, ?OutputInterface $output = null): bool
    {
        $this->enabled[] = [$site, $collectionHandle];

        return true;
    }
}

/**
 * A structure manager that records the structures it is asked to set up.
 */
final class ReusableBlockCoordinatorTestStructureManager extends CollectionStructureManager
{
    /** @var list<array{string, string}> */
    public array $initialized = [];

    public function __construct() {}

    public function initializeStructureForSite(string $collectionHandle, string $site, OutputInterface $output): void
    {
        $this->initialized[] = [$collectionHandle, $site];
    }
}

/**
 * An entry creator that records each block it is handed, and the blocks each one references.
 */
final class ReusableBlockCoordinatorTestCreator extends ReusableBlockEntryCreator
{
    /** @var list<array{site: string, collection: string, items: list<string>, paths: list<string>, base: string}> */
    public array $created = [];

    /**
     * @param  array<array-key, list<string>>  $references  The block ids each block references
     */
    public function __construct(private readonly array $references = []) {}

    public function createEntries(
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        AssetImporter $assetImporter,
        OutputInterface $output,
        MetadataMapper $metadataMapper,
        ContentProcessor $contentProcessor,
        ?array $minervaKbSettings = null
    ): array {
        $items = [];

        foreach ($xmlParser->getItems('wp_block') as $item) {
            $items[] = XpathHelper::getStringValue($item, 'wp:post_id');
        }

        $this->created[] = ['site' => $site, 'collection' => $collectionHandle, 'items' => $items, 'paths' => $xmlParser->getFilePaths(), 'base' => $xmlParser->getBaseBlogUrl()];

        // The block's own content can reference further blocks
        foreach ($items as $id) {
            foreach ($this->references[$id] ?? [] as $referenced) {
                WpBlockBlockProcessor::addWpBlockPostId($referenced);
            }
        }

        return [];
    }
}

/**
 * A coordinator over recording collaborators that does not wait between blocks.
 */
final class ReusableBlockCoordinatorTestCoordinator extends ReusableBlockCoordinator
{
    public int $waits = 0;

    public function __construct(
        public readonly ReusableBlockCoordinatorTestCreator $creator,
        public readonly ReusableBlockCoordinatorTestSiteManager $sites = new ReusableBlockCoordinatorTestSiteManager,
        public readonly ReusableBlockCoordinatorTestStructureManager $structures = new ReusableBlockCoordinatorTestStructureManager
    ) {
        parent::__construct($this->sites, $this->structures);
    }

    protected function entryCreator(): ReusableBlockEntryCreator
    {
        return $this->creator;
    }

    protected function waitForWrites(): void
    {
        $this->waits++;
    }
}

/**
 * A parser over an export carrying `wp_block` items with the given ids.
 *
 * @param  list<string>  $ids
 */
function reusableBlockParser(array $ids): XmlParser
{
    $items = '';

    foreach ($ids as $id) {
        $items .= '<item><title>Block '.$id.'</title><wp:post_id>'.$id.'</wp:post_id>'
            .'<wp:post_type><![CDATA[wp_block]]></wp:post_type><wp:status><![CDATA[publish]]></wp:status></item>';
    }

    $path = TemporaryFileRegistry::create('reusable_blocks_', '.xml');
    \file_put_contents($path, '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel><wp:base_blog_url>https://example.test</wp:base_blog_url>'.$items.'</channel></rss>');

    // A split export's second part, which the parser must keep reaching
    $second = TemporaryFileRegistry::create('reusable_blocks_part_', '.xml');
    \file_put_contents($second, '<?xml version="1.0" encoding="UTF-8"?><rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/"><channel></channel></rss>');

    $parser = new XmlParser;
    $parser->parse($path);
    $parser->setFilePaths([$path, $second]);

    return $parser;
}

/**
 * A coordinator primed with a parser and an asset importer, as the command primes it.
 *
 * @param  array<array-key, list<string>>  $references
 */
function reusableBlockCoordinator(XmlParser $parser, array $references = []): ReusableBlockCoordinatorTestCoordinator
{
    $coordinator = new ReusableBlockCoordinatorTestCoordinator(new ReusableBlockCoordinatorTestCreator($references));
    $coordinator->setXmlParser($parser);
    $coordinator->setAssetImporter(new AssetImporter);

    return $coordinator;
}

it('does nothing when the content referenced no reusable block', function (): void {
    $coordinator = reusableBlockCoordinator(reusableBlockParser(['1']));
    $output = new LfBufferedOutput;

    $coordinator->processReusableBlockImports('default', $output);

    expect($output->fetch())->toBe('')
        ->and($coordinator->creator->created)->toBe([])
        ->and($coordinator->sites->enabled)->toBe([]);
});

it('enables the collection and its structure, imports each referenced block once, and clears the references', function (): void {
    $parser = reusableBlockParser(['1', '2', '3']);
    $coordinator = reusableBlockCoordinator($parser);
    WpBlockBlockProcessor::addWpBlockPostId('3');
    WpBlockBlockProcessor::addWpBlockPostId('1');
    WpBlockBlockProcessor::addWpBlockPostId('3');

    $output = new LfBufferedOutput;
    $coordinator->processReusableBlockImports('research', $output);

    expect($output->fetch())->toBe("Processing reusable content imports...\n")
        ->and($coordinator->sites->enabled)->toBe([['research', 'reusable_content']])
        ->and($coordinator->structures->initialized)->toBe([['reusable_content', 'research']])
        // In the export's order, each block on its own parser that still spans every part
        ->and($coordinator->creator->created)->toBe([
            ['site' => 'research', 'collection' => 'reusable_content', 'items' => ['1'], 'paths' => $parser->getFilePaths(), 'base' => 'https://example.test'],
            ['site' => 'research', 'collection' => 'reusable_content', 'items' => ['3'], 'paths' => $parser->getFilePaths(), 'base' => 'https://example.test'],
        ])
        ->and($coordinator->waits)->toBe(2)
        ->and(WpBlockBlockProcessor::getWpBlockPostIds())->toBe([]);
});

it('imports the blocks the imported blocks reference, each once, until none is new', function (): void {
    $coordinator = reusableBlockCoordinator(reusableBlockParser(['1', '2', '3']), [
        '1' => ['2'],
        '2' => ['1', '3'],
        '3' => ['2'],
    ]);
    WpBlockBlockProcessor::addWpBlockPostId('1');

    $coordinator->processReusableBlockImports('default', new LfBufferedOutput);

    expect(array_merge(...array_column($coordinator->creator->created, 'items')))->toBe(['1', '2', '3']);
});

it('stops after a hundred rounds of new references, and says so', function (): void {
    // Each block references the next, so every round finds a new one
    $ids = array_map('strval', range(1, 105));
    $references = [];

    foreach ($ids as $id) {
        $references[$id] = [(string) ((int) $id + 1)];
    }

    $log = Log::spy();
    $coordinator = reusableBlockCoordinator(reusableBlockParser($ids), $references);
    WpBlockBlockProcessor::addWpBlockPostId('1');

    $coordinator->processReusableBlockImports('default', new LfBufferedOutput);

    expect($coordinator->creator->created)->toHaveCount(100);

    $log->shouldHaveReceived('warning', [
        Mockery::on(static fn (mixed $message): bool => \is_string($message)
            && \str_contains($message, 'ReusableBlockCoordinator::importWpBlocks: Reached maximum iterations (100). Possible circular wp_block references.')),
        Mockery::on(static fn (mixed $context): bool => \is_array($context)
            && ($context['site'] ?? null) === 'default'
            && ($context['importedCount'] ?? null) === 100
            && ($context['importedPostIds'] ?? null) === array_slice($ids, 0, 100)),
    ]);
});

it('says nothing about a limit it did not reach', function (): void {
    $log = Log::spy();
    $coordinator = reusableBlockCoordinator(reusableBlockParser(['1']));
    WpBlockBlockProcessor::addWpBlockPostId('1');

    $coordinator->processReusableBlockImports('default', new LfBufferedOutput);

    $log->shouldNotHaveReceived('warning');
});

it('refuses to import before it is given a parser and an asset importer', function (bool $parser, bool $assets): void {
    $coordinator = new ReusableBlockCoordinatorTestCoordinator(new ReusableBlockCoordinatorTestCreator);

    if ($parser) {
        $coordinator->setXmlParser(reusableBlockParser(['1']));
    }

    if ($assets) {
        $coordinator->setAssetImporter(new AssetImporter);
    }

    WpBlockBlockProcessor::addWpBlockPostId('1');

    expect(fn () => $coordinator->processReusableBlockImports('default', new LfBufferedOutput))
        ->toThrow(Exception::class, 'XML parser or asset importer not set for wp_block imports.');
})->with([
    'neither' => [false, false],
    'no parser' => [false, true],
    'no asset importer' => [true, false],
]);

it('skips every block when the parser has no file to re-read it from', function (): void {
    $parser = new XmlParser;
    $source = reusableBlockParser(['1', '2']);
    $parser->setItems($source->getItems('wp_block'));

    $coordinator = reusableBlockCoordinator($parser);
    WpBlockBlockProcessor::addWpBlockPostId('1');
    WpBlockBlockProcessor::addWpBlockPostId('2');

    $coordinator->processReusableBlockImports('default', new LfBufferedOutput);

    expect($coordinator->creator->created)->toBe([]);
});

it('clears the references it was asked to clear', function (): void {
    WpBlockBlockProcessor::addWpBlockPostId('1');

    reusableBlockCoordinator(reusableBlockParser(['1']))->clearWpBlockPostIds();

    expect(WpBlockBlockProcessor::getWpBlockPostIds())->toBe([]);
});
