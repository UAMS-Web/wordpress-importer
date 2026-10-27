<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mockery\MockInterface;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\Blocks\ContentParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

/**
 * Tests that `ContentParser` maps an ACF `uams-content` block, and a
 * modules-template `uams_content` module, onto the `uamsweb_block_content` set,
 * and that its iframe conversion emits the platform video sets.
 *
 * The two entry points share their field mapping, so each mapping case runs
 * through both. They differ only when the body is empty: the block is skipped
 * with a warning, while the module keeps its set.
 *
 * @command  php vendor/bin/pest tests/Integration/ContentParserTest.php
 */
uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ProcessingContext::clearWordPressPostId();
    ProcessingContext::clearStatamicEntryId();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
    ProcessingContext::clearEntryData();
    ProcessingContext::clearWordPressPostId();
    ProcessingContext::clearStatamicEntryId();
});

/**
 * The placeholder heading a block with content but no heading receives.
 */
const CONTENT_PARSER_DEFAULT_HEADING = 'Content Block Title - Please Replace';

/**
 * An `AssetImporter` that imports nothing.
 */
function contentAssetImporter(): AssetImporter
{
    /** @var AssetImporter&MockInterface $assetImporter */
    $assetImporter = Mockery::mock(AssetImporter::class)->shouldIgnoreMissing();

    return $assetImporter;
}

/**
 * Parse data through one of the two entry points.
 *
 * @param  string  $entry  `block` for `parse()`, `module` for `parseModule()`
 * @param  array<int|string, mixed>  $data  The block or module data
 * @return array<int|string, mixed>|null The replicator set without its id, or null
 */
function contentSet(string $entry, array $data): ?array
{
    $parser = new ContentParser;

    $set = $entry === 'block'
        ? $parser->parse(['data' => $data], [], 'default', contentAssetImporter(), new NullOutput)
        : $parser->parseModule($data, 'default', contentAssetImporter(), new NullOutput);

    if ($set === null) {
        return null;
    }

    expect($set['id'] ?? null)->toBeString()->toMatch('/^[A-Za-z0-9]{8}$/');
    unset($set['id']);

    return $set;
}

/**
 * A single text paragraph, as Bard stores it.
 *
 * @param  string  $text  The paragraph text
 * @return list<array<string, mixed>> The Bard body
 */
function contentParagraph(string $text): array
{
    return [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]], 'attrs' => []]];
}

/**
 * The set a block with the given fields maps to.
 *
 * @param  array<string, mixed>  $fields  The fields that differ from the defaults
 * @return array<string, mixed> The replicator set without its id
 */
function contentExpected(array $fields): array
{
    return array_merge([
        'type' => 'uamsweb_block_content',
        'block_heading' => CONTENT_PARSER_DEFAULT_HEADING,
        'hide_block_heading' => false,
        'background_color' => 'bg-auto',
        'body' => contentParagraph('Body'),
        'enabled' => true,
    ], $fields);
}

/**
 * Run HTML through the private iframe conversion.
 *
 * @param  string  $html  The WYSIWYG HTML
 * @return string The converted HTML
 */
function contentConvert(string $html): string
{
    $result = (new ReflectionMethod(ContentParser::class, 'convertIframesToVideoEmbeds'))
        ->invoke(new ContentParser, $html);

    return \is_string($result) ? $result : '';
}

/**
 * The set markup the iframe conversion emits.
 *
 * @param  string  $type  The set handle
 * @param  string  $url  The watch URL
 * @param  string  $align  The alignment
 * @param  ?string  $caption  The caption, or null for none
 * @return string The set `div`
 */
function contentVideoDiv(string $type, string $url, string $align = 'aligncenter', ?string $caption = null): string
{
    $nonce = SetMarkupNonce::value();
    $captionAttribute = $caption === null ? '' : " data-set-attr-caption=\"{$caption}\"";

    return "<div data-set-type=\"{$type}\" data-set-nonce=\"{$nonce}\" data-set-attr-embed_mode=\"simple\""
        ." data-set-attr-url=\"{$url}\" data-set-attr-aspect_ratio=\"16by9\" data-set-attr-align=\"{$align}\"{$captionAttribute}></div>";
}

dataset('contentEntries', ['block', 'module']);

it('maps the heading, hide flag, background and body', function (string $entry): void {
    expect(contentSet($entry, [
        'content_heading' => 'Our <b>clinic</b>',
        'content_hide_heading' => '1',
        'content_background_color' => 'bg-gray',
        'content_content' => '<p>Body</p>',
    ]))->toBe(contentExpected([
        'block_heading' => 'Our clinic',
        'hide_block_heading' => true,
        'background_color' => 'bg-gray',
    ]));
})->with('contentEntries');

it('maps the field-key names', function (string $entry): void {
    expect(contentSet($entry, [
        'field_content_heading_b' => 'Heading',
        'field_content_hide_heading_b' => '1',
        'field_content_background_color_b' => 'bg-white',
        'field_content_content_b' => '<p>Body</p>',
    ]))->toBe(contentExpected([
        'block_heading' => 'Heading',
        'hide_block_heading' => true,
        'background_color' => 'bg-white',
    ]));
})->with('contentEntries');

it('takes the description key over the content key', function (string $entry): void {
    expect(contentSet($entry, [
        'field_content_content_b' => '<p>Content</p>',
        'field_content_description_b' => '<p>Body</p>',
    ]))->toBe(contentExpected([]));
})->with('contentEntries');

it('shows the heading unless the hide flag is exactly 1', function (string $entry, mixed $hide): void {
    expect(contentSet($entry, ['content_hide_heading' => $hide, 'content_content' => '<p>Body</p>'])['hide_block_heading'] ?? null)
        ->toBeFalse();
})->with('contentEntries')->with([
    'zero' => ['0'],
    'an integer one' => [1],
    'true' => [true],
    'null' => [null],
]);

it('defaults a heading and background that are missing or not strings', function (string $entry, mixed $value): void {
    $data = ['content_content' => '<p>Body</p>'];
    if ($value !== 'missing') {
        $data['content_heading'] = $value;
        $data['content_background_color'] = $value;
    }

    expect(contentSet($entry, $data))->toBe(contentExpected([]));
})->with('contentEntries')->with([
    'missing' => ['missing'],
    'an integer' => [5],
    'an array' => [['x']],
]);

it('gives a block with content but an empty heading the placeholder', function (): void {
    expect(contentSet('block', ['content_heading' => '<b></b>', 'content_content' => '<p>Body</p>']))
        ->toBe(contentExpected([]));
});

it('leaves a module heading empty', function (): void {
    expect(contentSet('module', ['content_heading' => '<b></b>', 'content_content' => '<p>Body</p>']))
        ->toBe(contentExpected(['block_heading' => '']));
});

it('decodes JSON Unicode escapes in the content', function (string $entry): void {
    expect(contentSet($entry, ['content_content' => 'a'.'\\u00e9'.'b "q"'])['body'] ?? null)
        ->toBe(contentParagraph("a\u{e9}b \"q\""));
})->with('contentEntries');

it('keeps content that is not a valid JSON string as written', function (string $entry): void {
    expect(contentSet($entry, ['content_content' => 'a\qb'])['body'] ?? null)
        ->toBe(contentParagraph('a\qb'));
})->with('contentEntries');

it('converts an iframe in the content to a video set', function (string $entry): void {
    $body = contentSet($entry, [
        'content_content' => '<div class="embed-responsive"><iframe src="https://www.youtube.com/embed/abc" title="Video: Talk"></iframe></div>',
    ])['body'] ?? null;

    expect($body)->toBeArray()->toHaveCount(1)
        ->and(json_encode($body))->toContain('"values":{"type":"youtube","embed_mode":"simple","url":"https:\/\/www.youtube.com\/watch?v=abc","aspect_ratio":"16by9","align":"aligncenter","caption":"Talk"}');
})->with('contentEntries');

it('skips a block whose body is empty, with a warning', function (mixed $content, bool $contentEmpty): void {
    ProcessingContext::setWordPressPageLink('https://example.org/clinic/');
    ProcessingContext::setEntryData(['post_name' => 'clinic']);
    ProcessingContext::setWordPressPostId('42');
    ProcessingContext::setStatamicEntryId('entry-1');

    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(contentSet('block', [
        'content_heading' => 'Heading',
        'field_content_geo_b' => 'x',
        'content_content' => $content,
    ]))->toBeNull();

    $logSpy->shouldHaveReceived('warning', [
        'ContentParser::parse: Returning null - empty body (from entry: https://example.org/clinic/)',
        [
            'block_heading' => 'Heading',
            'heading_is_default' => false,
            'body_content_empty' => true,
            'content_content_empty' => $contentEmpty,
            'normalized_data_keys' => ['content_heading', 'field_content_geo_b', 'content_content', 'content_geo'],
            'content_heading_in_data' => true,
            'wordpress_post_id' => '42',
            'statamic_entry_id' => 'entry-1',
            'post_name' => 'clinic',
            'entry_url' => 'https://example.org/clinic/',
        ],
    ]);
})->with([
    'no content' => ['', true],
    'content that is not a string' => [['x'], true],
    'content that parses to nothing' => ['<p></p>', false],
]);

it('reports a default heading and missing context in the empty-body warning', function (): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(contentSet('block', []))->toBeNull();

    $logSpy->shouldHaveReceived('warning', [
        'ContentParser::parse: Returning null - empty body',
        [
            'block_heading' => CONTENT_PARSER_DEFAULT_HEADING,
            'heading_is_default' => true,
            'body_content_empty' => true,
            'content_content_empty' => true,
            'normalized_data_keys' => [],
            'content_heading_in_data' => false,
            'wordpress_post_id' => null,
            'statamic_entry_id' => null,
            'post_name' => null,
        ],
    ]);
});

it('maps every field-key name, as the empty-body warning lists them', function (): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    contentSet('block', [
        'field_content_heading_b' => 'H',
        'field_content_hide_heading_b' => '1',
        'field_content_content_b' => '',
        'field_content_description_b' => '',
        'field_content_background_color_b' => 'bg',
        'field_content_geo_valid_b' => '1',
        'field_content_geo_b' => 'g',
        'field_content_geo_region_b' => 'r',
    ]);

    $logSpy->shouldHaveReceived('warning', function (string $message, array $context): bool {
        return $context['normalized_data_keys'] === [
            'field_content_heading_b',
            'field_content_hide_heading_b',
            'field_content_content_b',
            'field_content_description_b',
            'field_content_background_color_b',
            'field_content_geo_valid_b',
            'field_content_geo_b',
            'field_content_geo_region_b',
            'content_heading',
            'content_hide_heading',
            'content_content',
            'content_background_color',
            'content_geo_valid',
            'content_geo',
            'content_geo_region',
        ];
    });
});

it('keeps a module whose body is empty, without a warning', function (mixed $content): void {
    /** @var MockInterface $logSpy */
    $logSpy = Log::spy();

    expect(contentSet('module', ['content_content' => $content]))->toBe(contentExpected(['body' => []]));

    $logSpy->shouldNotHaveReceived('warning');
})->with([
    'no content' => [''],
    'content that is not a string' => [['x']],
    'content that parses to nothing' => ['<p></p>'],
]);

it('leaves HTML with no iframe exactly as written', function (): void {
    expect(contentConvert('x <b>y'))->toBe('x <b>y');
});

it('replaces an embed-responsive container with the video set', function (): void {
    expect(contentConvert('<p>a</p><div class="embed-responsive alignfull"><iframe src="https://www.youtube.com/embed/abc" title=" Video: Talk "></iframe></div><p>b</p>'))
        ->toBe('<p>a</p>
'.contentVideoDiv('youtube', 'https://www.youtube.com/watch?v=abc', 'alignwide', 'Talk').'
<p>b</p>');
});

it('replaces an iframe in any other parent with the video set', function (string $open, string $close, string $align): void {
    // The expected markup is built here, not in the dataset, because the set nonce is read when it is built
    expect(contentConvert($open.'<iframe src="https://www.youtube.com/embed/abc"></iframe>'.$close))
        ->toBe($open.contentVideoDiv('youtube', 'https://www.youtube.com/watch?v=abc', $align).$close);
})->with([
    'a paragraph' => ['<p>x', '</p>', 'aligncenter'],
    'another div, whose alignment still counts' => ['<div class="alignwide">', '</div>', 'alignwide'],
    'a div with no alignment' => ['<div>', '</div>', 'aligncenter'],
    'a span with an alignment class' => ['<span class="alignwide">', '</span>', 'aligncenter'],
    'a span with an embed-responsive class' => ['<span class="embed-responsive">', '</span>', 'aligncenter'],
]);

it('converts every iframe', function (): void {
    expect(contentConvert('<iframe src="https://www.youtube.com/embed/a"></iframe><iframe src="https://vimeo.com/7"></iframe>'))
        ->toBe(contentVideoDiv('youtube', 'https://www.youtube.com/watch?v=a').'
'.contentVideoDiv('vimeo', 'https://vimeo.com/7'));
});

it('replaces an embed-responsive container holding two supported iframes with the last one', function (): void {
    expect(contentConvert('<div class="embed-responsive"><iframe src="https://www.youtube.com/embed/a"></iframe><iframe src="https://vimeo.com/7"></iframe></div>'))
        ->toBe(contentVideoDiv('vimeo', 'https://vimeo.com/7'));
});

it('routes each supported iframe URL', function (string $src, string $type, string $url): void {
    expect(contentConvert("<iframe src=\"{$src}\"></iframe>"))->toBe(contentVideoDiv($type, $url));
})->with([
    'a YouTube embed' => ['https://www.youtube.com/embed/a_B-1', 'youtube', 'https://www.youtube.com/watch?v=a_B-1'],
    'a mobile YouTube embed over http' => ['http://m.youtube.com/embed/abc?rel=0', 'youtube', 'https://www.youtube.com/watch?v=abc'],
    'a privacy-mode YouTube embed' => ['//youtube-nocookie.com/EMBED/abc', 'youtube', 'https://www.youtube.com/watch?v=abc'],
    'a Vimeo player embed' => ['https://player.vimeo.com/video/123?h=x', 'vimeo', 'https://vimeo.com/123'],
    'a bare Vimeo embed' => ['//vimeo.com/video/123', 'vimeo', 'https://vimeo.com/123'],
    'a YouTube watch URL' => ['https://youtube.com/watch?v=abc', 'youtube', 'https://www.youtube.com/watch?v=abc'],
    'a Vimeo watch URL' => ['https://www.vimeo.com/123', 'vimeo', 'https://vimeo.com/123'],
]);

it('leaves an iframe it cannot route alone', function (string $iframe): void {
    expect(contentConvert($iframe))->toBe($iframe);
})->with([
    'another host' => ['<iframe src="https://example.com/embed/abc"></iframe>'],
    'a host inside the path' => ['<iframe src="https://example.com/www.youtube.com/embed/abc"></iframe>'],
    'an empty src' => ['<iframe src=""></iframe>'],
    'no src' => ['<iframe></iframe>'],
    'a Vimeo embed without an id' => ['<iframe src="https://vimeo.com/video/abc"></iframe>'],
    'a YouTube URL with neither embed nor watch' => ['<iframe src="https://www.youtube.com/user/abc"></iframe>'],
]);

it('keeps a caption, dropping only its leading Video prefix', function (string $title, ?string $caption): void {
    expect(contentConvert("<iframe src=\"https://vimeo.com/7\" title=\"{$title}\"></iframe>"))
        ->toBe(contentVideoDiv('vimeo', 'https://vimeo.com/7', 'aligncenter', $caption));
})->with([
    'a plain title' => ['Talk', 'Talk'],
    'a prefixed title' => ['Video: Talk', 'Talk'],
    'the prefix later in the title' => ['A Video: Talk', 'A Video: Talk'],
    'a prefix without its space' => ['Video:Talk', 'Video:Talk'],
    'a blank title' => ['  ', null],
]);

it('keeps block-comment text inside the content, which is not inner blocks', function (string $entry): void {
    $body = contentSet($entry, ['content_content' => '<p><a href="https://example.org/" title="x <!-- wp:x --> y">Link</a></p>'])['body'] ?? null;

    expect(json_encode($body))->toContain('"title":"x <!-- wp:x --> y"');
})->with('contentEntries');

it('converts a supported iframe beside one it leaves alone', function (bool $before): void {
    $supported = '<iframe src="https://vimeo.com/7"></iframe>';
    $other = '<iframe src="https://example.com/x"></iframe>';
    $video = contentVideoDiv('vimeo', 'https://vimeo.com/7');

    expect(contentConvert($before ? $supported.$other : $other.$supported))
        ->toBe($before ? $video."\n".$other : $other.$video);
})->with([
    'after it' => [false],
    'before it' => [true],
]);

it('centers a video whose parent div is aligned left or right', function (string $class): void {
    expect(contentConvert("<div class=\"{$class}\"><iframe src=\"https://vimeo.com/7\"></iframe></div>"))
        ->toBe("<div class=\"{$class}\">".contentVideoDiv('vimeo', 'https://vimeo.com/7').'</div>');
})->with(['alignleft', 'alignright']);
