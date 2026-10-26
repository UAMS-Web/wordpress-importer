<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Statamic\Entries\Entry;
use Statamic\Facades\Blueprint as BlueprintFacade;
use Statamic\Facades\Collection as CollectionFacade;
use Statamic\Facades\Entry as EntryFacade;
use Statamic\Facades\Site;
use Statamic\Facades\Taxonomy as TaxonomyFacade;
use Statamic\Fields\Blueprint;
use Statamic\Taxonomies\Taxonomy;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\BaseEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\AdvertEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Entries\Creators\ReusableBlockEntryCreator;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Taxonomies\TermDestinationRegistry;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\MetadataMapper;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\TemporaryFileRegistry;

uses(StatamicTestCase::class, PreventsSavingStacheItemsToDisk::class);

/**
 * Pins how a WP-Members-gated KB article, advert or reusable block imports (wordpress-importer-archive#1241).
 *
 * wordpress-importer-archive#1240 imports a published item that WP-Members gates (`_wpmem_block` `1` or `2`) as
 * `private`, through `mapStatusAndVisibility()`. These three creators set the published
 * flag straight from `wp:status`, and none of their blueprints carries that `private`
 * state, so a gated item of theirs imports unpublished instead, and is named by URL the
 * same way. An open item (`0`, or no value) imports exactly as before.
 *
 * The advert and reusable-block creators run end to end below. The KB creator's article
 * path cannot be reached without its whole tree pass, so it is held by the call-site
 * guard, which requires the same wiring the two end-to-end creators prove.
 *
 * @command  php vendor/bin/pest tests/Integration/WpMembersGatedUnpublishedTest.php
 */
beforeEach(function (): void {
    // No search indexes, so a save never touches the workbench's shared index files
    config(['statamic.search.indexes' => []]);

    Site::setSites([
        'default' => ['name' => 'Default', 'locale' => 'en_US', 'url' => '/'],
    ]);

    // Resolve every image to its URL rather than downloading it
    config(['wordpress-import.upload_images' => false, 'wordpress-import.upload_files' => false]);

    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    // Process-wide state the import writes, reset as production resets it per command
    LinkProcessor::clearDeferredData();
    BaseEntryCreator::clearTaxonomyCaches();
    TermDestinationRegistry::clear();
    ImportWarningManager::clearWarnings();
    ProcessingContext::clearXmlPath();
    ProcessingContext::clearWordPressPageLink();

    // Blueprints are not Stache items, so they are removed by hand
    foreach ([
        'collections.classifieds.classified',
        'collections.reusable_content.reusable_content',
        'collections.reusable_content.reusable_content_landing_page',
    ] as $handle) {
        $blueprint = BlueprintFacade::find($handle);

        if ($blueprint instanceof Blueprint) {
            $blueprint->delete();
        }
    }

    TemporaryFileRegistry::sweep();
});

it('imports a published item WP-Members gates as unpublished, and names it', function (string $block): void {
    $log = Log::spy();
    ProcessingContext::setWordPressPageLink('https://inside.uams.edu/classifieds/advert/staff-only/');
    $output = new BufferedOutput;

    expect((new WpMembers1241Probe)->decide(true, $block, $output))->toBeFalse()
        ->and($output->fetch())->toContain(
            "Imported as unpublished: WP-Members gates https://inside.uams.edu/classifieds/advert/staff-only/ (_wpmem_block={$block})."
        );

    $log->shouldHaveReceived('warning', [
        Mockery::on(
            static fn (mixed $message): bool => \is_string($message)
                && \str_contains($message, "Probe::decide: WP-Members gates this item (_wpmem_block='{$block}'), importing it as unpublished rather than published")
                && \str_contains($message, 'https://inside.uams.edu/classifieds/advert/staff-only/')
        ),
        Mockery::on(
            static fn (mixed $context): bool => \is_array($context)
                && ($context['wpmem_block'] ?? null) === $block
        ),
    ]);
})->with(['blocked' => '1', 'hidden' => '2']);

it('leaves a published item published where WP-Members leaves it open, and says nothing', function (string $block): void {
    $output = new BufferedOutput;

    expect((new WpMembers1241Probe)->decide(true, $block, $output))->toBeTrue()
        ->and($output->fetch())->toBe('');
})->with(['open' => '0', 'absent' => '']);

it('leaves an unpublished item unpublished without naming it, gated or not', function (string $block): void {
    $output = new BufferedOutput;

    expect((new WpMembers1241Probe)->decide(false, $block, $output))->toBeFalse()
        ->and($output->fetch())->toBe('');
})->with(['blocked' => '1', 'open' => '0', 'absent' => '']);

it('imports a gated advert unpublished and an open one published', function (): void {
    wpmem1241AdvertSetup();

    $output = new BufferedOutput;
    $parser = new XmlParser;
    $parser->parse(wpmem1241Export('advert', [
        ['slug' => 'staff-only-desk', 'status' => 'publish', 'block' => '1'],
        ['slug' => 'hidden-chair', 'status' => 'publish', 'block' => '2'],
        ['slug' => 'open-lamp', 'status' => 'publish', 'block' => '0'],
        ['slug' => 'plain-shelf', 'status' => 'publish', 'block' => null],
        ['slug' => 'expired-cart', 'status' => 'expired', 'block' => '1'],
    ]));

    (new AdvertEntryCreator)->createEntries(
        'default',
        'classifieds',
        $parser,
        new AssetImporter,
        $output,
        new MetadataMapper,
        WpMembers1241BodyConverter::make()
    );

    $printed = $output->fetch();

    expect(wpmem1241Entry('classifieds', 'staff-only-desk')->published())->toBeFalse()
        ->and(wpmem1241Entry('classifieds', 'hidden-chair')->published())->toBeFalse()
        ->and(wpmem1241Entry('classifieds', 'open-lamp')->published())->toBeTrue()
        ->and(wpmem1241Entry('classifieds', 'plain-shelf')->published())->toBeTrue()
        ->and(wpmem1241Entry('classifieds', 'expired-cart')->published())->toBeFalse()
        ->and($printed)->toContain('Imported as unpublished: WP-Members gates https://example.test/advert/staff-only-desk/ (_wpmem_block=1).')
        ->and($printed)->toContain('Imported as unpublished: WP-Members gates https://example.test/advert/hidden-chair/ (_wpmem_block=2).')
        ->and(\substr_count($printed, 'Imported as unpublished'))->toBe(2);
});

it('imports a gated reusable block unpublished and an open one published', function (): void {
    wpmem1241ReusableSetup();

    $output = new BufferedOutput;
    $parser = new XmlParser;
    $parser->parse(wpmem1241Export('wp_block', [
        ['slug' => 'members-banner', 'status' => 'publish', 'block' => '1'],
        ['slug' => 'hidden-callout', 'status' => 'publish', 'block' => '2'],
        ['slug' => 'open-footer', 'status' => 'publish', 'block' => '0'],
        ['slug' => 'plain-notice', 'status' => 'publish', 'block' => null],
        ['slug' => 'draft-promo', 'status' => 'draft', 'block' => '2'],
    ]));

    (new ReusableBlockEntryCreator)->createEntries(
        'default',
        'reusable_content',
        $parser,
        new AssetImporter,
        $output,
        new MetadataMapper,
        WpMembers1241BodyConverter::make()
    );

    $printed = $output->fetch();

    expect(wpmem1241Entry('reusable_content', 'members-banner')->published())->toBeFalse()
        ->and(wpmem1241Entry('reusable_content', 'hidden-callout')->published())->toBeFalse()
        ->and(wpmem1241Entry('reusable_content', 'open-footer')->published())->toBeTrue()
        ->and(wpmem1241Entry('reusable_content', 'plain-notice')->published())->toBeTrue()
        ->and(wpmem1241Entry('reusable_content', 'draft-promo')->published())->toBeFalse()
        ->and($printed)->toContain('Imported as unpublished: WP-Members gates https://example.test/wp_block/members-banner/ (_wpmem_block=1).')
        ->and(\substr_count($printed, 'Imported as unpublished'))->toBe(2);
});

it('passes each item\'s _wpmem_block wherever a creator publishes from wp:status alone', function (): void {
    $root = \dirname(__DIR__, 2).'/src/Services/WordPressImport/Entries/Creators/';

    // Each creator, and the item variable its publish decision reads
    $sites = [
        'KbEntryCreator.php' => '$item',
        'AdvertEntryCreator.php' => '$advert',
        'ReusableBlockEntryCreator.php' => '$wpBlock',
    ];

    foreach ($sites as $file => $itemVariable) {
        $source = (string) \file_get_contents($root.$file);
        // The call's arguments, up to its closing parenthesis on a line of its own
        \preg_match_all('/->publishUnlessWpMembersGated\((.*?)\n\s*\)/s', $source, $matches);

        expect($matches[1])->toHaveCount(1, "{$file} decides publication without the WP-Members gate")
            ->and(\str_contains($matches[1][0] ?? '', "\$this->readWpMembersBlock({$itemVariable})"))
            ->toBeTrue("{$file} gates publication without reading the item's _wpmem_block");

        // And no publish decision reads wp:status alone any more: a status test passed as
        // an argument must be the gate's own first argument
        \preg_match_all('/^(.*)\n\s*\$(?:postStatus|status) === \'publish\',$/m', $source, $arguments);

        foreach ($arguments[1] as $previousLine) {
            expect(\str_ends_with(\rtrim($previousLine), '->publishUnlessWpMembersGated('))
                ->toBeTrue("{$file} still publishes from wp:status alone");
        }

        expect(\str_contains($source, "\$data['published'] = \$status === 'publish';"))
            ->toBeFalse("{$file} still publishes from wp:status alone");
    }
});

/**
 * Set up the `classifieds` collection the advert creator writes into.
 */
function wpmem1241AdvertSetup(): void
{
    $collection = CollectionFacade::make('classifieds');
    $collection->sites(['default']);
    $collection->dated(true);
    $collection->taxonomies(['classified_categories']);
    $collection->save();

    $blueprint = BlueprintFacade::make('classified');
    $blueprint->setNamespace('collections.classifieds');
    $blueprint->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
    $blueprint->save();

    $taxonomy = TaxonomyFacade::make('classified_categories');

    if (! $taxonomy instanceof Taxonomy) {
        throw new RuntimeException('Could not make the classified_categories taxonomy.');
    }

    $taxonomy->sites(['default']);
    $taxonomy->save();
}

/**
 * Set up the `reusable_content` collection the reusable-block creator writes into.
 */
function wpmem1241ReusableSetup(): void
{
    $collection = CollectionFacade::make('reusable_content');
    $collection->sites(['default']);
    $collection->save();

    foreach (['reusable_content', 'reusable_content_landing_page'] as $handle) {
        $blueprint = BlueprintFacade::make($handle);
        $blueprint->setNamespace('collections.reusable_content');
        $blueprint->setContents(['fields' => [['handle' => 'title', 'field' => ['type' => 'text']]]]);
        $blueprint->save();
    }
}

/**
 * Write a one-type export whose items differ only in status and `_wpmem_block`.
 *
 * @param  string  $postType  The `wp:post_type` every item carries
 * @param  list<array{slug: string, status: string, block: ?string}>  $items  Each item; a null block omits the meta
 * @return string The export path
 */
function wpmem1241Export(string $postType, array $items): string
{
    $xml = '';

    foreach ($items as $index => $item) {
        $postId = 500 + $index;
        $meta = $item['block'] === null
            ? ''
            : "<wp:postmeta><wp:meta_key>_wpmem_block</wp:meta_key><wp:meta_value><![CDATA[{$item['block']}]]></wp:meta_value></wp:postmeta>";

        $xml .= <<<XML
<item>
  <title>{$item['slug']}</title>
  <link>https://example.test/{$postType}/{$item['slug']}/</link>
  <content:encoded><![CDATA[<p>Body.</p>]]></content:encoded>
  <wp:post_id>{$postId}</wp:post_id>
  <wp:post_name>{$item['slug']}</wp:post_name>
  <wp:post_date>2025-03-01 09:00:00</wp:post_date>
  <wp:status>{$item['status']}</wp:status>
  <wp:post_type>{$postType}</wp:post_type>
  <wp:postmeta><wp:meta_key>adverts_person</wp:meta_key><wp:meta_value><![CDATA[Pat Poster]]></wp:meta_value></wp:postmeta>
  <wp:postmeta><wp:meta_key>adverts_email</wp:meta_key><wp:meta_value><![CDATA[poster@example.test]]></wp:meta_value></wp:postmeta>
  {$meta}
</item>

XML;
    }

    $path = TemporaryFileRegistry::create('wpmem-1241');

    \file_put_contents($path, <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Gated</title>
<link>https://example.test</link>
<wp:base_blog_url>https://example.test</wp:base_blog_url>
{$xml}</channel>
</rss>
XML);

    return $path;
}

/**
 * The one entry in a collection whose slug starts with the given item slug.
 *
 * @param  string  $collection  The collection handle
 * @param  string  $slug  The WordPress post name
 * @return Entry The entry
 */
function wpmem1241Entry(string $collection, string $slug): Entry
{
    $matches = [];

    foreach (EntryFacade::all() as $entry) {
        if (
            $entry instanceof Entry
            && $entry->collectionHandle() === $collection
            && \is_string($entry->slug())
            && \str_starts_with($entry->slug(), $slug)
        ) {
            $matches[] = $entry;
        }
    }

    // Exactly one, or the published flag read below could belong to another item
    expect($matches)->toHaveCount(1);

    $entry = $matches[0] ?? null;

    if (! $entry instanceof Entry) {
        throw new RuntimeException("No {$collection} entry for '{$slug}'.");
    }

    return $entry;
}

/**
 * Exposes `publishUnlessWpMembersGated()` so it can be driven directly.
 */
final class WpMembers1241Probe extends BaseEntryCreator
{
    /**
     * Decide publication through the real `publishUnlessWpMembersGated()`.
     *
     * @param  bool  $published  Whether the item would import published from its status alone
     * @param  string  $wpmemBlock  The `_wpmem_block` postmeta value
     * @param  OutputInterface  $output  Console output
     * @return bool Whether the item imports published
     */
    public function decide(bool $published, string $wpmemBlock, OutputInterface $output): bool
    {
        return $this->publishUnlessWpMembersGated($published, $wpmemBlock, 'Probe::decide', $output);
    }

    /**
     * Not exercised here.
     *
     * @param  string  $site  The Statamic site handle
     * @param  string  $collectionHandle  The collection handle
     * @param  XmlParser  $xmlParser  Parser for the export
     * @param  mixed  $assetImporter  Unused
     * @param  OutputInterface  $output  Console output
     * @param  mixed  $metadataMapper  Unused
     * @param  mixed  $contentProcessor  Unused
     * @param  ?array<int|string, mixed>  $minervaKbSettings  Unused
     * @return array<int|string, mixed> Always empty
     */
    public function createEntries(
        string $site,
        string $collectionHandle,
        XmlParser $xmlParser,
        $assetImporter,
        OutputInterface $output,
        $metadataMapper,
        $contentProcessor,
        ?array $minervaKbSettings = null
    ): array {
        return [];
    }

    /**
     * Not exercised here.
     */
    public function hasShowPeople(): bool
    {
        return false;
    }

    /**
     * Not exercised here.
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostCategories(): array
    {
        return [];
    }

    /**
     * Not exercised here.
     *
     * @return array<int|string, mixed> Always empty
     */
    public function getPostTags(): array
    {
        return [];
    }

    /**
     * Not exercised here.
     *
     * @param  string  $collectionHandle  The collection handle
     * @param  string  $site  The Statamic site handle
     * @param  OutputInterface  $output  Console output
     */
    public function validateTaxonomies(
        string $collectionHandle,
        string $site,
        OutputInterface $output
    ): void {}
}

/**
 * A body converter that returns a fixed one-paragraph body.
 *
 * The real `ContentProcessor` builds a sanitizer from the host app's namespace, which
 * does not exist under Testbench, and publication does not depend on the body.
 */
final class WpMembers1241BodyConverter extends ContentProcessor
{
    /**
     * Build the converter without running `ContentProcessor`'s constructor.
     *
     * @return self The converter
     */
    public static function make(): self
    {
        return (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
    }

    /**
     * Return a fixed one-paragraph body.
     *
     * @param  string  $content  The item's HTML
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer
     * @param  OutputInterface  $output  Console output
     * @param  XmlParser  $xmlParser  The parser
     * @param  string  $pageTitle  The item title
     * @param  string  $pageId  The WordPress post id
     * @param  string  $pageLink  The item URL
     * @param  array<int|string, mixed>  $attachments  Attachment elements
     * @param  array<int|string, mixed>  $terms  Term elements
     * @param  array<int|string, mixed>  $items  Items
     * @param  array<int|string, mixed>  $data  The entry data so far
     * @param  string  $collectionHandle  The collection handle
     * @param  ?string  $pageSlug  The entry slug
     * @param  ?string  $statamicEntryId  The entry id
     * @return array{content: mixed, page_builder: array<int|string, mixed>} The fixed body
     */
    public function process(
        string $content,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        XmlParser $xmlParser,
        string $pageTitle = '',
        string $pageId = '',
        string $pageLink = '',
        array $attachments = [],
        array $terms = [],
        array $items = [],
        array $data = [],
        string $collectionHandle = '',
        ?string $pageSlug = null,
        ?string $statamicEntryId = null
    ): array {
        return [
            'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Body.']]]],
            'page_builder' => [],
        ];
    }
}
