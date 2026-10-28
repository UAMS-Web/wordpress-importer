<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\GalleryParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BardParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ImportWarningManager;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\WordPressImporter\Tests\Support\FakeAssetImporter;

/**
 * Tests that `GalleryParser` maps an ACF uams-gallery block, through either
 * entry point, onto the `uamsweb_block_gallery` set: the block fields and their
 * defaults, the description through its four collaborators, the image ids and
 * their count, the crop key, the footer link, the blocks it skips, and what it
 * reports about a gallery the block cannot render.
 *
 * @command  php vendor/bin/pest tests/Integration/GalleryParserTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    Http::fake(['*' => Http::response('', 404)]);
    ProcessingContext::clearWordPressPageLink();
    ImportWarningManager::clearWarnings();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::setStatamicEntryId(null);
    ProcessingContext::setCollectionHandle(null);
    ProcessingContext::clearXmlPath();
    ImportWarningManager::clearWarnings();
});

/**
 * A WpAutoP that wraps the text it is given, so the step shows in the result.
 */
final class GlFakeAutoP extends WpAutoP
{
    /**
     * The text of each call, in order.
     *
     * @var list<string>
     */
    public array $calls = [];

    /**
     * Record the text, then wrap it.
     *
     * @param  string  $text  The text to format
     * @param  bool  $br  Whether to convert line breaks (ignored)
     * @return string The wrapped text
     */
    #[Override]
    public function process(string $text, bool $br = true): string
    {
        $this->calls[] = $text;

        return "<autop>{$text}</autop>";
    }
}

/**
 * A BardParser that returns the HTML it is given as one node.
 */
final class GlFakeBard extends BardParser
{
    /**
     * The HTML of each call, in order.
     *
     * @var list<string>
     */
    public array $calls = [];

    /**
     * Record the HTML, then return it as one node.
     *
     * @param  string  $html  The HTML to parse
     * @return array<int|string, mixed> One node holding the HTML
     */
    #[Override]
    public function parse(string $html): array
    {
        $this->calls[] = $html;

        return [['type' => 'fake', 'html' => $html]];
    }
}

/**
 * A ShortcodeProcessor that extracts a fixed list and wraps each shortcode.
 */
final class GlFakeShortcodes extends ShortcodeProcessor
{
    /**
     * The `[shortcode, site, terms]` of each conversion, in order.
     *
     * @var list<array{array<int|string, mixed>, string, array<int|string, mixed>}>
     */
    public array $conversions = [];

    /**
     * Fix what `extract()` returns.
     *
     * @param  array<int|string, mixed>  $extracted  The shortcodes to extract
     */
    public function __construct(private readonly array $extracted = []) {}

    /**
     * Return the fixed shortcodes.
     *
     * @param  string  $content  The content (ignored)
     * @param  OutputInterface  $output  The console output (ignored)
     * @return array<int|string, mixed> The fixed shortcodes
     */
    #[Override]
    public function extract(string $content, OutputInterface $output): array
    {
        return $this->extracted;
    }

    /**
     * Record the conversion, then wrap the shortcode's original text.
     *
     * @param  array<int|string, mixed>  $shortcode  The shortcode
     * @param  string  $site  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer (ignored)
     * @param  OutputInterface  $output  The console output (ignored)
     * @param  array<int|string, mixed>  $attachments  The attachments (ignored)
     * @param  array<int|string, mixed>  $terms  The terms
     * @param  ?string  $pageSlug  The page slug (ignored)
     * @return string The wrapped shortcode
     */
    #[Override]
    public function toTiptapHtml(
        array $shortcode,
        string $site,
        AssetImporter $assetImporter,
        OutputInterface $output,
        array $attachments = [],
        array $terms = [],
        ?string $pageSlug = null
    ): string {
        $this->conversions[] = [$shortcode, $site, $terms];
        $original = $shortcode['original'] ?? '';

        return '<sc>'.(is_string($original) ? $original : '').'</sc>';
    }
}

/**
 * A LinkProcessor that marks the HTML it is given and records its context.
 */
final class GlFakeLinks extends LinkProcessor
{
    /**
     * The `[html, site, entryId, collection, xmlPath]` of each call, in order.
     *
     * @var list<array{string, string, ?string, ?string, ?string}>
     */
    public array $calls = [];

    /**
     * Record the call, then mark the HTML.
     *
     * @param  string  $html  The HTML
     * @param  string  $statamicSiteHandle  The site handle
     * @param  AssetImporter  $assetImporter  The asset importer (ignored)
     * @param  OutputInterface  $output  The console output (ignored)
     * @param  ?string  $statamicEntryId  The entry id
     * @param  ?string  $statamicEntryCollectionHandle  The collection handle
     * @param  ?string  $xmlPath  The export path
     * @return string The marked HTML
     */
    #[Override]
    public function process(
        string $html,
        string $statamicSiteHandle,
        AssetImporter $assetImporter,
        OutputInterface $output,
        ?string $statamicEntryId = null,
        ?string $statamicEntryCollectionHandle = null,
        ?string $xmlPath = null
    ): string {
        $this->calls[] = [$html, $statamicSiteHandle, $statamicEntryId, $statamicEntryCollectionHandle, $xmlPath];

        return "<links>{$html}</links>";
    }
}

/**
 * Removes and checks the row id of a set.
 *
 * @param  array<int|string, mixed>  $set  The set
 * @return array<int|string, mixed> The set without its id
 */
function glWithoutId(array $set): array
{
    expect($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');
    unset($set['id']);

    return $set;
}

/**
 * Parses through the named entry point, the block data wrapped in a `data` key.
 *
 * @param  string  $entry  'block' or 'module'
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?AssetImporter  $assetImporter  The asset importer; uploads enabled and every import succeeding by default
 * @param  ?GalleryParser  $parser  The parser
 * @param  array<int|string, mixed>  $attachments  The export's attachments
 * @param  array<int|string, mixed>  $terms  The terms for shortcode processing
 * @return ?array<int|string, mixed> The set as returned, or null when the module is skipped
 */
function glRaw(
    string $entry,
    array $data,
    ?AssetImporter $assetImporter = null,
    ?GalleryParser $parser = null,
    array $attachments = [],
    array $terms = []
): ?array {
    $parser ??= new GalleryParser;
    $assetImporter ??= new FakeAssetImporter(true, 'assets::photo.jpg');

    return $entry === 'block'
        ? $parser->parse(['data' => $data], 'default', $assetImporter, new NullOutput, $attachments, $terms, 'https://src.test/page/')
        : $parser->parseModule($data, 'default', $assetImporter, new NullOutput, $attachments, null, $terms, 'https://src.test/page/');
}

/**
 * Parses through the named entry point and returns the set without its id.
 *
 * @param  string  $entry  'block' or 'module'
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?AssetImporter  $assetImporter  The asset importer
 * @param  ?GalleryParser  $parser  The parser
 * @return array<int|string, mixed> The set without its id
 */
function glSet(string $entry, array $data, ?AssetImporter $assetImporter = null, ?GalleryParser $parser = null): array
{
    $set = glRaw($entry, $data, $assetImporter, $parser);
    expect($set)->toBeArray()->not->toBe([]);

    return is_array($set) ? glWithoutId($set) : [];
}

/**
 * The warnings logged while running a callback, with their contexts.
 *
 * @param  callable(): mixed  $callback  The code to run
 * @return list<array{string, array<int|string, mixed>}> Each warning's message and context, in order
 */
function glWarnings(callable $callback): array
{
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = [$event->message, $event->context];
        }
    });

    $callback();

    return $warnings;
}

/**
 * The warnings a callback logs from one of the parser's methods.
 *
 * @param  string  $method  The method name, as it prefixes the message
 * @param  callable(): mixed  $callback  The code to run
 * @return list<array{string, array<int|string, mixed>}> Those warnings, in order
 */
function glMethodWarnings(string $method, callable $callback): array
{
    return array_values(array_filter(
        glWarnings($callback),
        static fn (array $warning): bool => str_starts_with($warning[0], "GalleryParser::{$method}:")
    ));
}

/**
 * The image ids an asset importer was asked to import.
 *
 * @param  FakeAssetImporter  $assetImporter  The asset importer
 * @return list<string> The ids, in order
 */
function glImported(FakeAssetImporter $assetImporter): array
{
    return array_column($assetImporter->importedImages, 0);
}

/**
 * The fewest fields a gallery needs to import.
 *
 * @return array<string, mixed> A heading and two image ids
 */
function glMinimal(): array
{
    return ['gallery_heading' => 'Gallery', 'gallery_images' => ['1', '2']];
}

/**
 * An attachment item from the export.
 *
 * @param  string  $postId  The attachment's post id
 * @param  string  $url  The attachment's URL
 */
function glAttachment(string $postId, string $url): SimpleXMLElement
{
    return new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        ."<wp:post_id>{$postId}</wp:post_id><wp:attachment_url>{$url}</wp:attachment_url></item>"
    );
}

/**
 * An object that records whether it was ever unserialized.
 */
final class GlWakeup
{
    /**
     * Whether any instance was unserialized.
     */
    public static bool $woke = false;

    /**
     * Record the unserialization.
     */
    public function __wakeup(): void
    {
        self::$woke = true;
    }
}

dataset('glEntries', ['block', 'module']);

it('maps the ACF field keys to the standard field names', function (): void {
    $method = new ReflectionMethod(GalleryParser::class, 'getFieldMappings');

    expect($method->invoke(new GalleryParser))->toBe([
        'field_gallery_heading_b' => 'gallery_heading',
        'field_gallery_hide_heading_b' => 'gallery_hide_heading',
        'field_gallery_description_b' => 'gallery_description',
        'field_gallery_columns_b' => 'gallery_columns',
        'field_gallery_images_b' => 'gallery_images',
        'field_gallery_crop_b' => 'gallery_crop',
        'field_gallery_background_color_b' => 'gallery_background_color',
        'field_gallery_modal_b' => 'gallery_modal',
        'field_gallery_more_b' => 'gallery_more',
        'field_gallery_more_text_b' => 'gallery_more_text',
        'field_gallery_more_button_text_b' => 'gallery_more_button_text',
        'field_gallery_more_button_url_b' => 'gallery_more_button_url',
        'field_gallery_more_button_description_b' => 'gallery_more_button_description',
        'field_gallery_geo_valid_b' => 'gallery_geo_valid',
        'field_gallery_geo_b' => 'gallery_geo',
        'field_gallery_geo_region_b' => 'gallery_geo_region',
    ]);
});

it('reads a gallery stored under its ACF field keys', function (string $entry): void {
    $set = glSet($entry, ['field_gallery_heading_b' => 'Keyed', 'field_gallery_images_b' => ['1'], 'field_gallery_columns_b' => '2']);

    expect([$set['block_heading'] ?? null, $set['columns'] ?? null, $set['images'] ?? null])->toBe(['Keyed', '2', ['assets::photo.jpg']]);
})->with('glEntries');

it('reads block attributes with no data wrapper, or with a data key that is not an array', function (mixed $data): void {
    $set = (new GalleryParser)->parse(
        glMinimal() + ($data === null ? [] : ['data' => $data]),
        'default',
        new FakeAssetImporter(true, 'assets::photo.jpg'),
        new NullOutput
    );

    expect($set['block_heading'] ?? null)->toBe('Gallery');
})->with(['no data key' => [null], 'a string data key' => ['x']]);

it('reads the module footer button target through LinkHelper', function (): void {
    $set = glSet('module', glMinimal() + [
        'gallery_more' => '1',
        'gallery_more_text' => 'More',
        'gallery_more_button_text' => 'See all',
        'gallery_more_button_url' => ['url' => 'https://example.com/more', 'target' => '_self'],
    ]);

    expect($set['more_button_target'] ?? null)->toBeTrue();
});

it('never instantiates an object serialized into the image ids', function (string $entry): void {
    GlWakeup::$woke = false;
    $assetImporter = new FakeAssetImporter(true, 'assets::photo.jpg');

    glRaw($entry, ['gallery_heading' => 'Gallery', 'gallery_images' => serialize([new GlWakeup, '4'])], $assetImporter);

    expect([GlWakeup::$woke, glImported($assetImporter)])->toBe([false, ['4']]);
})->with('glEntries');

it('reads a serialized value that is not a list as no image ids', function (string $entry): void {
    $assetImporter = new FakeAssetImporter(true, 'assets::photo.jpg');

    glRaw($entry, ['gallery_heading' => 'Gallery', 'gallery_images' => serialize('4')], $assetImporter);

    expect(glImported($assetImporter))->toBe([]);
})->with('glEntries');

it('maps every gallery field onto the set', function (string $entry, bool $target): void {
    $set = glSet($entry, [
        'gallery_heading' => '<b>Our</b> Gallery',
        'gallery_hide_heading' => '1',
        'gallery_description' => 'About it.',
        'gallery_columns' => '4',
        'gallery_images' => ['1', '2'],
        'gallery_crop' => '1.7778',
        'gallery_background_color' => 'bg-gray',
        'gallery_modal' => '0',
        'gallery_more' => '1',
        'gallery_more_text' => '<i>More</i> photos',
        'gallery_more_button_text' => '<b>See</b> all',
        'gallery_more_button_url' => ['url' => 'https://example.com/more', 'target' => '_blank'],
        'gallery_more_button_description' => '<i>All</i> the photos',
    ], null, new GalleryParser(null, new GlFakeBard, new GlFakeShortcodes, new GlFakeLinks));

    expect($set)->toBe([
        'type' => 'uamsweb_block_gallery',
        'block_heading' => 'Our Gallery',
        'hide_block_heading' => true,
        'columns' => '4',
        'images' => ['assets::photo.jpg', 'assets::photo.jpg'],
        'crop' => 1.7778,
        'background_color' => 'bg-gray',
        'modal' => false,
        'more_query' => true,
        'enabled' => true,
        'block_description' => [['type' => 'fake', 'html' => "<links><p>About it.</p>\n</links>"]],
        'more_heading' => 'More photos',
        'more_button_text' => 'See all',
        'more_button_url' => 'https://example.com/more',
        'more_button_target' => $target,
        'more_button_link_description' => 'All the photos',
    ]);
})->with([
    // The block reads a `_blank` target as a new tab, while the module's LinkHelper reads it as false
    'block' => ['block', true],
    'module' => ['module', false],
]);

it('gives each field its default when the gallery leaves it out', function (string $entry): void {
    expect(glSet($entry, glMinimal()))->toBe([
        'type' => 'uamsweb_block_gallery',
        'block_heading' => 'Gallery',
        'hide_block_heading' => false,
        'columns' => '3',
        'images' => ['assets::photo.jpg', 'assets::photo.jpg'],
        'crop' => 0,
        'background_color' => 'bg-auto',
        'modal' => true,
        'more_query' => false,
        'enabled' => true,
    ]);
})->with('glEntries');

it('reads only the string 1 as an on switch', function (string $entry, mixed $value, bool $on): void {
    $set = glSet($entry, glMinimal() + [
        'gallery_hide_heading' => $value,
        'gallery_modal' => $value,
    ]);

    expect([$set['hide_block_heading'] ?? null, $set['modal'] ?? null])->toBe([$on, $on]);
})->with('glEntries')->with([
    'the string 1' => ['1', true],
    'the string 0' => ['0', false],
    'the integer 1' => [1, false],
]);

it('leaves the footer link out while it is off, whatever its fields hold', function (string $entry, mixed $more): void {
    $set = glSet($entry, glMinimal() + [
        'gallery_more' => $more,
        'gallery_more_text' => 'More',
        'gallery_more_button_text' => 'See all',
        'gallery_more_button_url' => ['url' => 'https://example.com/more'],
        'gallery_more_button_description' => 'All',
    ]);

    expect($set['more_query'] ?? null)->toBeFalse()
        ->and(array_keys($set))->not->toContain('more_heading', 'more_button_text', 'more_button_url', 'more_button_target', 'more_button_link_description');
})->with('glEntries')->with(['absent' => [null], 'the string 0' => ['0']]);

it('omits a footer button description that is empty', function (string $entry): void {
    $set = glSet($entry, glMinimal() + [
        'gallery_more' => '1',
        'gallery_more_text' => 'More',
        'gallery_more_button_text' => 'See all',
        'gallery_more_button_url' => ['url' => 'https://example.com/more'],
    ]);

    expect($set['more_button_text'] ?? null)->toBe('See all')
        ->and($set)->not->toHaveKey('more_button_link_description');
})->with('glEntries');

it('skips a gallery whose footer link is on but incomplete', function (string $entry, string $missing): void {
    $data = glMinimal() + [
        'gallery_more' => '1',
        'gallery_more_text' => 'More',
        'gallery_more_button_text' => 'See all',
        'gallery_more_button_url' => ['url' => 'https://example.com/more'],
    ];
    unset($data[$missing]);

    $set = 'unset';
    $warnings = glMethodWarnings($entry === 'block' ? 'parse' : 'parseModule', function () use ($entry, $data, &$set): void {
        $set = glRaw($entry, $data);
    });

    $type = $entry === 'block' ? 'wp:acf/uams-gallery block' : 'modules template uams_gallery';
    $fields = [
        'more_heading' => $missing === 'gallery_more_text' ? '' : 'More',
        'more_button_text' => $missing === 'gallery_more_button_text' ? '' : 'See all',
        'more_button_url' => $missing === 'gallery_more_button_url' ? '' : 'https://example.com/more',
    ];
    expect($set)->toBe($entry === 'block' ? [] : null)
        ->and($warnings)->toBe([[
            ($entry === 'block' ? 'GalleryParser::parse' : 'GalleryParser::parseModule')
                .": Skipping {$type} due to missing required more link fields",
            ['site' => 'default', 'source_url' => 'https://src.test/page/'] + $fields,
        ]]);
})->with('glEntries')->with(['gallery_more_text', 'gallery_more_button_text', 'gallery_more_button_url']);

it('skips a gallery without a heading', function (string $entry, mixed $heading): void {
    $data = glMinimal();
    $data['gallery_heading'] = $heading;

    $set = 'unset';
    $warnings = glWarnings(function () use ($entry, $data, &$set): void {
        $set = glRaw($entry, $data);
    });

    expect($set)->toBe($entry === 'block' ? [] : null)
        ->and($warnings)->toBe([[
            $entry === 'block'
                ? 'GalleryParser::parse: Skipping wp:acf/uams-gallery block due to missing required heading'
                : 'GalleryParser::parseModule: Skipping modules template uams_gallery due to missing required heading',
            ['site' => 'default', 'source_url' => 'https://src.test/page/'],
        ]]);
})->with('glEntries')->with(['an empty string' => [''], 'markup only' => ['<b></b>'], 'an array' => [['Gallery']]]);

it('reads a number as heading text', function (string $entry): void {
    $data = glMinimal();
    $data['gallery_heading'] = 2026;

    expect(glSet($entry, $data)['block_heading'] ?? null)->toBe('2026');
})->with('glEntries');

it('runs the description through its shortcodes, paragraphs and links before parsing it', function (string $entry): void {
    ProcessingContext::setStatamicEntryId('entry-1');
    ProcessingContext::setCollectionHandle('pages');
    ProcessingContext::setXmlPath('/exports/site.xml');
    $autoP = new GlFakeAutoP;
    $bard = new GlFakeBard;
    $shortcodes = new GlFakeShortcodes([
        ['original' => '[one]'],
        'not a shortcode',
        ['name' => 'no original'],
        ['original' => 7],
        ['original' => '[two]'],
    ]);
    $links = new GlFakeLinks;

    $set = glSet($entry, glMinimal() + ['gallery_description' => 'A [one] B [two]'], null, new GalleryParser($autoP, $bard, $shortcodes, $links));

    expect($autoP->calls)->toBe(['A <sc>[one]</sc> B <sc>[two]</sc>'])
        ->and(array_column($shortcodes->conversions, 1))->toBe(['default', 'default'])
        ->and($links->calls)->toBe([['<autop>A <sc>[one]</sc> B <sc>[two]</sc></autop>', 'default', 'entry-1', 'pages', '/exports/site.xml']])
        ->and($bard->calls)->toBe(['<links><autop>A <sc>[one]</sc> B <sc>[two]</sc></autop></links>'])
        ->and($set['block_description'] ?? null)->toBe([['type' => 'fake', 'html' => '<links><autop>A <sc>[one]</sc> B <sc>[two]</sc></autop></links>']]);
})->with('glEntries');

it('passes the terms to each shortcode it converts', function (string $entry): void {
    $shortcodes = new GlFakeShortcodes([['original' => '[one]']]);

    glRaw($entry, glMinimal() + ['gallery_description' => '[one]'], null, new GalleryParser(new GlFakeAutoP, new GlFakeBard, $shortcodes, new GlFakeLinks), [], ['news']);

    expect(array_column($shortcodes->conversions, 2))->toBe([['news']]);
})->with('glEntries');

it('treats a description of only whitespace as no description', function (string $entry): void {
    $bard = new GlFakeBard;

    $set = glSet($entry, glMinimal() + ['gallery_description' => " \n\t "], null, new GalleryParser(new GlFakeAutoP, $bard, new GlFakeShortcodes, new GlFakeLinks));

    expect($bard->calls)->toBe([])
        ->and($set)->not->toHaveKey('block_description');
})->with('glEntries');

it('imports each image id the gallery carries', function (string $entry, mixed $images, array $ids): void {
    $assetImporter = new FakeAssetImporter(true, 'assets::photo.jpg');

    glRaw($entry, ['gallery_heading' => 'Gallery', 'gallery_images' => $images], $assetImporter);

    expect(glImported($assetImporter))->toBe($ids);
})->with('glEntries')->with([
    'a list of strings' => [['4', '5'], ['4', '5']],
    'a list of integers' => [[4, 5], ['4', '5']],
    'a serialized list' => [serialize(['4', '5']), ['4', '5']],
    'one string' => ['4', ['4']],
    'one integer' => [4, ['4']],
    'a list with a name in it' => [['4', 'photo', '5'], ['4', '5']],
    'six ids' => [['1', '2', '3', '4', '5', '6'], ['1', '2', '3', '4', '5', '6']],
]);

it('skips a gallery with no image ids or more than six, and warns', function (string $entry, mixed $images, int $count): void {
    $assetImporter = new FakeAssetImporter(true, 'assets::photo.jpg');

    $set = 'unset';
    $warnings = glWarnings(function () use ($entry, $images, $assetImporter, &$set): void {
        $set = glRaw($entry, ['gallery_heading' => 'Gallery', 'gallery_images' => $images], $assetImporter);
    });

    $method = $entry === 'block' ? 'GalleryParser::parse' : 'GalleryParser::parseModule';
    $type = $entry === 'block' ? 'wp:acf/uams-gallery block' : 'modules template uams_gallery';
    $context = ['site' => 'default', 'source_url' => 'https://src.test/page/'];
    expect($set)->toBe($entry === 'block' ? [] : null)
        ->and(glImported($assetImporter))->toBe([])
        ->and($warnings)->toBe([
            ['GalleryParser::processImages: Invalid number of images for gallery block', $context + ['image_count' => $count]],
            ["{$method}: Skipping {$type} due to invalid or missing images when uploads are enabled", $context],
        ]);
})->with('glEntries')->with([
    'none' => [null, 0],
    'an empty string' => ['', 0],
    'only names' => [['photo'], 0],
    'seven' => [['1', '2', '3', '4', '5', '6', '7'], 7],
]);

it('skips a gallery whose images all fail to import, and warns for each', function (string $entry, ?string $imported): void {
    $set = 'unset';
    $warnings = glWarnings(function () use ($entry, $imported, &$set): void {
        $set = glRaw($entry, ['gallery_heading' => 'Gallery', 'gallery_images' => ['4', 5]], new FakeAssetImporter(true, $imported));
    });

    $method = $entry === 'block' ? 'GalleryParser::parse' : 'GalleryParser::parseModule';
    $type = $entry === 'block' ? 'wp:acf/uams-gallery block' : 'modules template uams_gallery';
    $context = ['site' => 'default', 'source_url' => 'https://src.test/page/'];
    expect($set)->toBe($entry === 'block' ? [] : null)
        ->and($warnings)->toBe([
            ['GalleryParser::processImages: Failed to import image when uploads are enabled', $context + ['image_id' => '4']],
            ['GalleryParser::processImages: Failed to import image when uploads are enabled', $context + ['image_id' => 5]],
            ['GalleryParser::processImages: No valid images after import when uploads are enabled', $context + ['valid_image_count' => 0]],
            ["{$method}: Skipping {$type} due to invalid or missing images when uploads are enabled", $context],
        ]);
})->with('glEntries')->with(['null' => [null], 'an empty path' => ['']]);

it('uses the export URLs without warning while uploads are off', function (string $entry): void {
    $set = [];
    $warnings = glMethodWarnings('processImages', function () use ($entry, &$set): void {
        $raw = glRaw(
            $entry,
            ['gallery_heading' => 'Gallery', 'gallery_images' => ['4', '5', '6']],
            new FakeAssetImporter(false),
            null,
            [glAttachment('4', 'https://wp.test/a.jpg'), glAttachment('6', 'https://wp.test/c.jpg')]
        );
        $set = is_array($raw) ? $raw : [];
    });

    expect($set['images'] ?? null)->toBe(['https://wp.test/a.jpg', 'https://wp.test/c.jpg'])
        ->and($warnings)->toBe([]);
})->with('glEntries');

it('imports a gallery with no images while uploads are off', function (string $entry): void {
    $set = [];
    $warnings = glMethodWarnings('processImages', function () use ($entry, &$set): void {
        $raw = glRaw($entry, ['gallery_heading' => 'Gallery', 'gallery_images' => ['4']], new FakeAssetImporter(false));
        $set = is_array($raw) ? $raw : [];
    });

    expect($set['images'] ?? null)->toBe([])
        ->and($set['block_heading'] ?? null)->toBe('Gallery')
        ->and($warnings)->toBe([]);
})->with('glEntries');

it('converts the crop value to the radio option key', function (string $entry, mixed $crop, int|float $key): void {
    $set = [];
    $warnings = glMethodWarnings('normalizeCropValue', function () use ($entry, $crop, &$set): void {
        $set = glSet($entry, glMinimal() + ['gallery_crop' => $crop]);
    });

    expect($set['crop'] ?? null)->toBe($key)
        ->and($warnings)->toBe([]);
})->with('glEntries')->with([
    'none' => ['none', 0],
    'none, padded' => [' none ', 0],
    'an empty string' => ['', 0],
    'a space' => [' ', 0],
    'one' => ['1', 1],
    'zero' => ['0', 0],
    'a ratio' => ['1.7778', 1.7778],
    'a padded ratio' => [' 0.75 ', 0.75],
    'an integer' => [1, 1],
]);

it('falls back to no crop for a value that is not an option, and warns', function (string $entry): void {
    $set = [];
    $warnings = glMethodWarnings('normalizeCropValue', function () use ($entry, &$set): void {
        $set = glSet($entry, glMinimal() + ['gallery_crop' => 'wide']);
    });

    expect($set['crop'] ?? null)->toBe(0)
        ->and($warnings)->toBe([
            ['GalleryParser::normalizeCropValue: Unrecognized gallery crop value; falling back to None', ['site' => 'default', 'gallery_crop' => 'wide']],
        ]);
})->with('glEntries');

it('reports a gallery the block cannot render, naming what it lacks', function (string $entry): void {
    ProcessingContext::setWordPressPageLink('https://wp.test/gallery-page/');

    $warnings = glMethodWarnings('reportUnrenderableGallery', function () use ($entry): void {
        glRaw($entry, ['gallery_heading' => 'Gallery', 'gallery_images' => ['4']]);
    });

    expect(ImportWarningManager::getWarnings('content', 'unrenderable_gallery_blocks'))->toBe([[
        'block_type' => 'uamsweb_block_gallery',
        'page_url' => 'https://wp.test/gallery-page/',
        'heading' => 'Gallery',
        'missing_fields' => ['block_description', 'images'],
        'image_count' => 1,
        'reason' => 'The gallery block renders only when every required field has a value, so this block imports but displays nothing until an editor supplies the missing fields.',
    ]])->and($warnings)->toBe([[
        "GalleryParser::reportUnrenderableGallery: Imported a wp:acf/uams-gallery block that cannot render \u{2014} missing block_description, images (from entry: https://wp.test/gallery-page/)",
        ['site' => 'default', 'source_url' => 'https://src.test/page/', 'entry_url' => 'https://wp.test/gallery-page/'],
    ]]);
})->with('glEntries');

it('reports nothing for a gallery the block can render', function (string $entry): void {
    $warnings = glWarnings(function () use ($entry): void {
        glRaw($entry, glMinimal() + ['gallery_description' => 'About it.']);
    });

    expect(ImportWarningManager::getWarnings('content', 'unrenderable_gallery_blocks'))->toBe([])
        ->and($warnings)->toBe([]);
})->with('glEntries');
