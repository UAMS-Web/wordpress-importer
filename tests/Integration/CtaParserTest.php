<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\NullOutput;
use Symfony\Component\Console\Output\OutputInterface;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers\Blocks\CtaParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\BardParser;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\LinkProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\WpAutoP;
use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;
use UAMSWeb\Aether\WordPressImporter\Tests\Support\FakeAssetImporter;

/**
 * Tests that `CtaParser` maps an ACF CTA Bar block, through either entry point,
 * onto the `uamsweb_block_cta` set: the body pipeline, the placeholder body,
 * the action type and its fields, the layout fields, and the background image
 * with the warning a failed import logs.
 *
 * The body's collaborators are replaced with recorders, so each test can see
 * exactly what each step was handed.
 *
 * @command  php vendor/bin/pest tests/Integration/CtaParserTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    Http::fake(['*' => Http::response('', 404)]);
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::setStatamicEntryId(null);
    ProcessingContext::setCollectionHandle(null);
    ProcessingContext::clearXmlPath();
});

/**
 * The calls each recorded collaborator received, in order.
 */
final class CtaCalls
{
    /**
     * Each call as its method name and arguments.
     *
     * @var list<array{string, list<mixed>}>
     */
    public array $calls = [];

    /**
     * The method names called, in order.
     *
     * @return list<string> The method names
     */
    public function names(): array
    {
        return array_map(static fn (array $call): string => $call[0], $this->calls);
    }
}

/**
 * A parser whose body collaborators record their calls and mark their output.
 *
 * @param  CtaCalls  $calls  The recorder
 * @param  list<mixed>  $shortcodes  What shortcode extraction returns
 */
function ctaParser(CtaCalls $calls, array $shortcodes = []): CtaParser
{
    $shortcodeProcessor = new class($calls, $shortcodes) extends ShortcodeProcessor
    {
        /**
         * Records each call and returns the given shortcodes.
         *
         * @param  CtaCalls  $calls  The recorder
         * @param  list<mixed>  $shortcodes  What extraction returns
         */
        public function __construct(private readonly CtaCalls $calls, private readonly array $shortcodes) {}

        /**
         * Records the call and returns the given shortcodes.
         *
         * @param  string  $content  The text to extract from
         * @param  OutputInterface  $output  The console output
         * @return array<int|string, mixed> The given shortcodes
         */
        public function extract(string $content, OutputInterface $output): array
        {
            $this->calls->calls[] = ['extract', func_get_args()];

            return $this->shortcodes;
        }

        /**
         * Records the call and marks the shortcode's original text.
         *
         * @param  array<int|string, mixed>  $shortcode  The shortcode data
         * @param  string  $site  The site handle
         * @param  AssetImporter  $assetImporter  The asset importer
         * @param  OutputInterface  $output  The console output
         * @param  array<int|string, mixed>  $attachments  The attachments
         * @param  array<int|string, mixed>  $terms  The terms
         * @param  ?string  $pageSlug  The page slug
         * @return string The marked shortcode
         */
        public function toTiptapHtml(
            array $shortcode,
            string $site,
            AssetImporter $assetImporter,
            OutputInterface $output,
            array $attachments = [],
            array $terms = [],
            ?string $pageSlug = null
        ): string {
            $this->calls->calls[] = ['toTiptapHtml', func_get_args()];
            $original = $shortcode['original'] ?? '';

            return '<sc>'.(is_string($original) ? trim($original, '[]') : '').'</sc>';
        }
    };

    $wpAutoP = new class($calls) extends WpAutoP
    {
        /**
         * Records each call and marks its text.
         *
         * @param  CtaCalls  $calls  The recorder
         */
        public function __construct(private readonly CtaCalls $calls) {}

        /**
         * Records the call and marks the text.
         *
         * @param  string  $text  The text to format
         * @param  bool  $br  Whether to convert line breaks
         * @return string The marked text
         */
        public function process(string $text, bool $br = true): string
        {
            $this->calls->calls[] = ['wpautop', func_get_args()];

            return 'A('.$text.')';
        }
    };

    $linkProcessor = new class($calls) extends LinkProcessor
    {
        /**
         * Records each call and marks its HTML.
         *
         * @param  CtaCalls  $calls  The recorder
         */
        public function __construct(private readonly CtaCalls $calls) {}

        /**
         * Records the call and marks the HTML.
         *
         * @param  string  $html  The HTML to process
         * @param  string  $statamicSiteHandle  The site handle
         * @param  AssetImporter  $assetImporter  The asset importer
         * @param  OutputInterface  $output  The console output
         * @param  ?string  $statamicEntryId  The entry id
         * @param  ?string  $statamicEntryCollectionHandle  The collection handle
         * @param  ?string  $xmlPath  The export path
         * @return string The marked HTML
         */
        public function process(
            string $html,
            string $statamicSiteHandle,
            AssetImporter $assetImporter,
            OutputInterface $output,
            ?string $statamicEntryId = null,
            ?string $statamicEntryCollectionHandle = null,
            ?string $xmlPath = null
        ): string {
            $this->calls->calls[] = ['links', func_get_args()];

            return 'L('.$html.')';
        }
    };

    $bardParser = new class($calls) extends BardParser
    {
        /**
         * Records each call and wraps its HTML.
         *
         * @param  CtaCalls  $calls  The recorder
         */
        public function __construct(private readonly CtaCalls $calls) {}

        /**
         * Records the call and wraps the HTML.
         *
         * @param  string  $html  The HTML to parse
         * @return array<int|string, mixed> The wrapped HTML
         */
        public function parse(string $html): array
        {
            $this->calls->calls[] = ['bard', func_get_args()];

            return [['html' => $html]];
        }
    };

    return new CtaParser($bardParser, $linkProcessor, $wpAutoP, $shortcodeProcessor);
}

/**
 * Parse block data through one entry point.
 *
 * @param  string  $entry  `block` for `parse()`, `module` for `parseModule()`
 * @param  array<int|string, mixed>  $data  The block or module data
 * @param  ?AssetImporter  $assetImporter  The asset importer, or a fake with uploads on
 * @param  ?CtaCalls  $calls  The recorder, or a fresh one
 * @param  ?OutputInterface  $output  The console output, or a null output
 * @param  array<int|string, mixed>  $attachments  The export's attachments
 * @return array<int|string, mixed> The set without its id
 */
function ctaParse(
    string $entry,
    array $data,
    ?AssetImporter $assetImporter = null,
    ?CtaCalls $calls = null,
    ?OutputInterface $output = null,
    array $attachments = []
): array {
    $parser = ctaParser($calls ?? new CtaCalls);
    $assetImporter ??= new FakeAssetImporter;
    $output ??= new NullOutput;

    $set = $entry === 'block'
        ? $parser->parse(['data' => $data], 'default', $assetImporter, $output, $attachments, ['terms'])
        : $parser->parseModule($data, 'default', $assetImporter, $output, $attachments, null, ['terms']);

    expect($set)->toBeArray();
    $set ??= [];

    expect($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');
    unset($set['id']);

    return $set;
}

/**
 * The set empty data maps to, with the given fields changed.
 *
 * @param  array<string, mixed>  $fields  Fields to add or override
 * @return array<string, mixed> The set without its id
 */
function ctaExpected(array $fields = []): array
{
    return array_merge([
        'type' => 'uamsweb_block_cta',
        'block_heading' => '',
        'hide_block_heading' => false,
        'body' => [],
        'action_type' => 'url',
        'layout' => 'cta-bar-centered',
        'size' => 'normal',
        'background_color' => 'bg-auto',
        'background_image_query' => false,
        'enabled' => true,
        'sms_phone_type_group' => [
            'type' => null,
            'phone' => null,
            'sms_short_code' => null,
        ],
        'button_text' => '',
        'button_url' => '',
        'button_target' => false,
        'button_link_description' => '',
    ], $fields);
}

/**
 * An export attachment with an id and a URL.
 *
 * @param  string  $id  The attachment's post id
 * @param  string  $url  The attachment URL
 */
function ctaAttachment(string $id, string $url): SimpleXMLElement
{
    return new SimpleXMLElement(
        '<item xmlns:wp="http://wordpress.org/export/1.2/">'
        ."<wp:post_id>{$id}</wp:post_id><wp:attachment_url>{$url}</wp:attachment_url></item>"
    );
}

dataset('ctaEntries', ['block', 'module']);

it('maps empty data to the defaults', function (string $entry): void {
    $calls = new CtaCalls;

    expect(ctaParse($entry, [], null, $calls))->toBe(ctaExpected())
        ->and($calls->calls)->toBe([]);
})->with('ctaEntries');

it('reads block attributes that carry no data key, or a data key that is not an array', function (array $attributes, string $heading): void {
    $set = ctaParser(new CtaCalls)->parse($attributes, 'default', new FakeAssetImporter, new NullOutput);
    unset($set['id']);

    expect($set)->toBe(ctaExpected(['block_heading' => $heading]));
})->with([
    'no data key' => [['cta_bar_heading' => 'Bare'], 'Bare'],
    'a data key that is not an array' => [['data' => 'x', 'cta_bar_heading' => 'Bare'], ''],
]);

it('reads module data as it is, without unwrapping a data key', function (): void {
    expect(ctaParse('module', ['data' => ['cta_bar_heading' => 'Inner'], 'cta_bar_heading' => 'Outer']))
        ->toBe(ctaExpected(['block_heading' => 'Outer']));
});

it('maps every field of a full URL block', function (string $entry): void {
    $calls = new CtaCalls;

    expect(ctaParse($entry, [
        'field_cta_bar_heading_b' => 'Get <b>care</b>',
        'field_cta_bar_body_b' => 'Body',
        'field_cta_bar_action_type_b' => 'url',
        'field_cta_bar_button_text_b' => 'Book <i>now</i>',
        'field_cta_bar_button_url_b' => ['url' => 'https://example.org/book/', 'target' => '_blank'],
        'field_cta_bar_button_description_b' => 'Book an appointment',
        'field_cta_bar_layout_b' => 'cta-bar-left',
        'field_cta_bar_size_b' => 'large',
        'field_cta_bar_background_color_b' => 'bg-red',
    ], null, $calls))->toBe(ctaExpected([
        'block_heading' => 'Get care',
        'body' => [['html' => 'L(A(Body))']],
        'layout' => 'cta-bar-left',
        'size' => 'large',
        'background_color' => 'bg-red',
        'button_text' => 'Book now',
        'button_url' => 'https://example.org/book/',
        'button_target' => true,
        'button_link_description' => 'Book an appointment',
    ]));
})->with('ctaEntries');

it('passes layout fields through as they are', function (string $entry): void {
    expect(ctaParse($entry, [
        'cta_bar_layout' => ['x'],
        'cta_bar_size' => 3,
        'cta_bar_background_color' => '',
        'cta_bar_button_description' => ['y'],
    ]))->toBe(ctaExpected([
        'layout' => ['x'],
        'size' => 3,
        'background_color' => '',
        'button_link_description' => ['y'],
    ]));
})->with('ctaEntries');

it('reads scalar text values as strings', function (string $entry): void {
    expect(ctaParse($entry, ['cta_bar_heading' => 5, 'cta_bar_button_text' => true]))
        ->toBe(ctaExpected(['block_heading' => '5', 'button_text' => '1']));
})->with('ctaEntries');

it('reads non-scalar text values as empty', function (string $entry): void {
    expect(ctaParse($entry, ['cta_bar_heading' => ['x'], 'cta_bar_button_text' => ['y'], 'cta_bar_body' => ['z']]))
        ->toBe(ctaExpected());
})->with('ctaEntries');

it('opens the button in a new window only for a _blank target', function (string $entry, mixed $url, bool $target): void {
    expect(ctaParse($entry, ['cta_bar_button_url' => $url])['button_target'] ?? null)->toBe($target);
})->with('ctaEntries')->with([
    '_blank' => [['url' => 'https://example.org/', 'target' => '_blank'], true],
    '_self' => [['url' => 'https://example.org/', 'target' => '_self'], false],
    'none' => [['url' => 'https://example.org/'], false],
    'a string URL' => ['https://example.org/', false],
]);

it('reads a button URL given as a string', function (string $entry): void {
    expect(ctaParse($entry, ['cta_bar_button_url' => 'https://example.org/str/'])['button_url'] ?? null)
        ->toBe('https://example.org/str/');
})->with('ctaEntries');

it('runs the body through shortcodes, wpautop, links and Bard, in order', function (string $entry): void {
    ProcessingContext::setStatamicEntryId('entry-1');
    ProcessingContext::setCollectionHandle('pages');
    ProcessingContext::setXmlPath('export.xml');

    $calls = new CtaCalls;
    $parser = ctaParser($calls, [
        'not a shortcode',
        ['original' => ''],
        ['original' => 5],
        ['name' => 'no original'],
        ['original' => '[a]', 'name' => 'a'],
        ['original' => '[b]', 'name' => 'b'],
    ]);
    $assetImporter = new FakeAssetImporter;
    $output = new NullOutput;

    $set = $entry === 'block'
        ? $parser->parse(['data' => ['cta_bar_body' => 'x [a] y [b] [a]']], 'default', $assetImporter, $output, [], ['terms'])
        : $parser->parseModule(['cta_bar_body' => 'x [a] y [b] [a]'], 'default', $assetImporter, $output, [], null, ['terms']);

    expect($set['body'] ?? null)->toBe([['html' => 'L(A(x <sc>a</sc> y <sc>b</sc> <sc>a</sc>))']])
        ->and($calls->names())->toBe(['extract', 'toTiptapHtml', 'toTiptapHtml', 'wpautop', 'links', 'bard'])
        ->and($calls->calls[0][1])->toBe(['x [a] y [b] [a]', $output])
        ->and($calls->calls[1][1])->toBe([['original' => '[a]', 'name' => 'a'], 'default', $assetImporter, $output, [], ['terms']])
        ->and($calls->calls[2][1])->toBe([['original' => '[b]', 'name' => 'b'], 'default', $assetImporter, $output, [], ['terms']])
        ->and($calls->calls[3][1])->toBe(['x <sc>a</sc> y <sc>b</sc> <sc>a</sc>'])
        ->and($calls->calls[4][1])->toBe(['A(x <sc>a</sc> y <sc>b</sc> <sc>a</sc>)', 'default', $assetImporter, $output, 'entry-1', 'pages', 'export.xml'])
        ->and($calls->calls[5][1])->toBe(['L(A(x <sc>a</sc> y <sc>b</sc> <sc>a</sc>))']);
})->with('ctaEntries');

it('fills an empty body with a placeholder when the heading and an action are set', function (string $entry, array $data): void {
    expect(ctaParse($entry, array_merge(['cta_bar_heading' => 'Heading'], $data))['body'] ?? null)
        ->toBe([['html' => "L(A(<p>Placeholder Body \u{2014} Please Replace</p>))"]]);
})->with('ctaEntries')->with([
    'no action type, with button text and a URL' => [['cta_bar_button_text' => 'Go', 'cta_bar_button_url' => ['url' => 'https://example.org/']]],
    'an empty action type, with a string URL' => [['cta_bar_action_type' => '', 'cta_bar_button_text' => 'Go', 'cta_bar_button_url' => 'https://example.org/']],
    'an empty-array action type' => [['cta_bar_action_type' => [], 'cta_bar_button_text' => 'Go', 'cta_bar_button_url' => 'https://example.org/']],
    'a URL action' => [['cta_bar_action_type' => 'url', 'cta_bar_button_text' => 'Go', 'cta_bar_button_url' => 'https://example.org/']],
    'a URL action with scalar text' => [['cta_bar_action_type' => 'url', 'cta_bar_button_text' => 7, 'cta_bar_button_url' => 'https://example.org/']],
    'a URL action with array text' => [['cta_bar_action_type' => 'url', 'cta_bar_button_text' => ['x'], 'cta_bar_button_url' => 'https://example.org/']],
    'a phone action' => [['cta_bar_action_type' => 'phone', 'cta_bar_phone' => '555-0100']],
    'an object action type, which reads as none' => [['cta_bar_action_type' => new stdClass, 'cta_bar_button_text' => 'Go', 'cta_bar_button_url' => 'https://example.org/']],
    'a body of whitespace' => [['cta_bar_body' => '  ', 'cta_bar_action_type' => 'phone', 'cta_bar_phone' => '555-0100']],
]);

it('leaves an empty body empty when the placeholder conditions are not met', function (string $entry, array $data): void {
    $calls = new CtaCalls;

    expect(ctaParse($entry, $data, null, $calls)['body'] ?? null)->toBe([])
        ->and($calls->calls)->toBe([]);
})->with('ctaEntries')->with([
    'no heading' => [['cta_bar_action_type' => 'phone', 'cta_bar_phone' => '555-0100']],
    'a heading of whitespace' => [['cta_bar_heading' => ' ', 'cta_bar_action_type' => 'phone', 'cta_bar_phone' => '555-0100']],
    'a heading that is not scalar' => [['cta_bar_heading' => null, 'cta_bar_action_type' => 'phone', 'cta_bar_phone' => '555-0100']],
    'no action and no button text' => [['cta_bar_heading' => 'H', 'cta_bar_button_url' => 'https://example.org/']],
    'no action and no button URL' => [['cta_bar_heading' => 'H', 'cta_bar_button_text' => 'Go']],
    'no action and a URL array without a URL' => [['cta_bar_heading' => 'H', 'cta_bar_button_text' => 'Go', 'cta_bar_button_url' => ['url' => ' ']]],
    'no action and a URL array without a url key' => [['cta_bar_heading' => 'H', 'cta_bar_button_text' => 'Go', 'cta_bar_button_url' => ['target' => '_self']]],
    'a heading of false' => [['cta_bar_heading' => false, 'cta_bar_action_type' => 'phone', 'cta_bar_phone' => '555-0100']],
    'a URL action without button text' => [['cta_bar_heading' => 'H', 'cta_bar_action_type' => 'url', 'cta_bar_button_url' => 'https://example.org/']],
    'a URL action without a URL' => [['cta_bar_heading' => 'H', 'cta_bar_action_type' => 'url', 'cta_bar_button_text' => 'Go']],
    'a phone action without a phone' => [['cta_bar_heading' => 'H', 'cta_bar_action_type' => 'phone', 'cta_bar_phone' => '']],
    'a phone action with a URL' => [['cta_bar_heading' => 'H', 'cta_bar_action_type' => 'phone', 'cta_bar_button_text' => 'Go', 'cta_bar_button_url' => 'https://example.org/']],
    'a none action' => [['cta_bar_heading' => 'H', 'cta_bar_action_type' => 'none', 'cta_bar_button_text' => 'Go', 'cta_bar_button_url' => 'https://example.org/', 'cta_bar_phone' => '1']],
]);

it('keeps a body that has content', function (string $entry): void {
    expect(ctaParse($entry, [
        'cta_bar_heading' => 'H',
        'cta_bar_body' => 'Real',
        'cta_bar_action_type' => 'phone',
        'cta_bar_phone' => '555-0100',
    ])['body'] ?? null)->toBe([['html' => 'L(A(Real))']]);
})->with('ctaEntries');

it('falls back to no action for an action type it does not know', function (string $entry, mixed $actionType): void {
    expect(ctaParse($entry, ['cta_bar_action_type' => $actionType, 'cta_bar_button_text' => 'Go', 'cta_bar_phone' => '1']))
        ->toBe(ctaWithoutButton('none'));
})->with('ctaEntries')->with([
    'none' => ['none'],
    'an unknown string' => ['link'],
    'an empty string' => [''],
    'a different case' => ['URL'],
    'true, which loose matching would take for a known type' => [true],
]);

it('maps the phone fields of a phone block', function (): void {
    expect(ctaParse('block', [
        'cta_bar_action_type' => 'phone',
        'cta_bar_phone_prepend' => 'Call <b>us</b>',
        'cta_bar_phone' => '555-0100',
    ]))->toBe(array_merge(ctaPhoneBase(), ['phone_prepend' => 'Call us', 'phone' => '555-0100']));
});

it('defaults the phone fields of a phone block', function (mixed $prepend, string $expected): void {
    expect(ctaParse('block', ['cta_bar_action_type' => 'phone', 'cta_bar_phone_prepend' => $prepend]))
        ->toBe(array_merge(ctaPhoneBase(), ['phone_prepend' => $expected, 'phone' => '']));
})->with([
    'missing' => [null, 'Call'],
    'an integer' => [5, '5'],
    'an array' => [['x'], ''],
]);

it('passes the phone fields of a phone module through as they are', function (mixed $prepend, mixed $expected): void {
    expect(ctaParse('module', ['cta_bar_action_type' => 'phone', 'cta_bar_phone_prepend' => $prepend, 'cta_bar_phone' => 5]))
        ->toBe(array_merge(ctaPhoneBase(), ['phone_prepend' => $expected, 'phone' => 5]));
})->with([
    'tags' => ['Call <b>us</b>', 'Call <b>us</b>'],
    'missing' => [null, 'Call'],
    'an array' => [['x'], ['x']],
]);

it('defaults the phone of a phone module', function (): void {
    expect(ctaParse('module', ['cta_bar_action_type' => 'phone']))
        ->toBe(array_merge(ctaPhoneBase(), ['phone_prepend' => 'Call', 'phone' => '']));
});

it('maps the field keys of a phone block with a background image', function (string $entry): void {
    $assetImporter = new FakeAssetImporter(true, 'images/bg.jpg');

    expect(ctaParse($entry, [
        'field_cta_bar_action_type_b' => 'phone',
        'field_cta_bar_phone_prepend_b' => 'Text',
        'field_cta_bar_phone_b' => '555-0100',
        'field_cta_bar_use_image_b' => '1',
        'field_cta_bar_image_b' => '42',
    ], $assetImporter))->toBe(array_merge(ctaPhoneBase(), [
        'background_image_query' => true,
        'phone_prepend' => 'Text',
        'phone' => '555-0100',
        'background_image' => 'images/bg.jpg',
    ]))->and($assetImporter->importedImages)->toBe([['42', 'default']]);
})->with('ctaEntries');

/**
 * The set a phone action maps to, before its phone fields.
 *
 * @return array<string, mixed> The set without its id
 */
function ctaPhoneBase(): array
{
    return ctaWithoutButton('phone');
}

/**
 * The set an action other than a URL maps to, without the button fields.
 *
 * @param  string  $actionType  The action type
 * @return array<string, mixed> The set without its id
 */
function ctaWithoutButton(string $actionType): array
{
    $set = ctaExpected(['action_type' => $actionType]);
    unset($set['button_text'], $set['button_url'], $set['button_target'], $set['button_link_description']);

    return $set;
}

it('imports the background image when it is switched on', function (string $entry, mixed $useImage): void {
    $assetImporter = new FakeAssetImporter(true, 'images/bg.jpg');

    expect(ctaParse($entry, ['cta_bar_use_image' => $useImage, 'cta_bar_image' => 42], $assetImporter))
        ->toBe(ctaExpected(['background_image_query' => true, 'background_image' => 'images/bg.jpg']))
        ->and($assetImporter->importedImages)->toBe([['42', 'default']]);
})->with('ctaEntries')->with([
    'one' => ['1'],
    'true' => [true],
]);

it('imports no background image when it is switched off or has no image', function (string $entry, array $data, bool $query): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();
    $assetImporter = new FakeAssetImporter(true, 'images/bg.jpg');

    expect(ctaParse($entry, $data, $assetImporter))->toBe(ctaExpected(['background_image_query' => $query]))
        ->and($assetImporter->importedImages)->toBe([]);

    $logSpy->shouldNotHaveReceived('warning');
})->with('ctaEntries')->with([
    'switched off' => [['cta_bar_use_image' => '0', 'cta_bar_image' => '42'], false],
    'switch missing' => [['cta_bar_image' => '42'], false],
    'no image' => [['cta_bar_use_image' => '1', 'cta_bar_image' => ''], true],
    'image missing' => [['cta_bar_use_image' => '1'], true],
    'an image that is not scalar' => [['cta_bar_use_image' => '1', 'cta_bar_image' => ['42']], true],
]);

it('warns when an enabled upload brings back no background image', function (string $entry, ?string $imported, string $method): void {
    ProcessingContext::setWordPressPageLink('https://example.org/cta/');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(ctaParse($entry, ['cta_bar_use_image' => '1', 'cta_bar_image' => '42'], new FakeAssetImporter(true, $imported)))
        ->toBe(ctaExpected(['background_image_query' => true]));

    $logSpy->shouldHaveReceived('warning', [
        "CtaParser::{$method}: Failed to import CTA background image when uploads are enabled (from entry: https://example.org/cta/)",
        ['site' => 'default', 'image_id' => '42', 'entry_url' => 'https://example.org/cta/'],
    ]);
})->with([
    'a block, null' => ['block', null, 'parse'],
    'a block, empty' => ['block', '', 'parse'],
    'a module, null' => ['module', null, 'parseModule'],
    'a module, empty' => ['module', '', 'parseModule'],
]);

it('uses the attachment URL without a warning when uploads are disabled', function (string $entry): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();
    $output = new BufferedOutput;

    expect(ctaParse($entry, ['cta_bar_use_image' => '1', 'cta_bar_image' => '42'], new FakeAssetImporter(false), null, $output, [
        ctaAttachment('41', 'https://example.org/41.jpg'),
        ctaAttachment('42', 'https://example.org/42.jpg'),
    ]))->toBe(ctaExpected(['background_image_query' => true, 'background_image' => 'https://example.org/42.jpg']))
        ->and($output->fetch())->toContain('Using original URL for CTA background image.');

    $logSpy->shouldNotHaveReceived('warning');
})->with('ctaEntries');

it('keeps going without a background image or a warning when uploads are disabled and the attachment is missing', function (string $entry): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(ctaParse($entry, ['cta_bar_use_image' => '1', 'cta_bar_image' => '42'], new FakeAssetImporter(false)))
        ->toBe(ctaExpected(['background_image_query' => true]));

    $logSpy->shouldNotHaveReceived('warning');
})->with('ctaEntries');
