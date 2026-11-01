<?php

declare(strict_types=1);

/**
 * Tests that a `[caption]` in a `wp:shortcode` block imports as one image set
 * carrying its caption text, on a page builder page and in Bard content.
 *
 * The host app defines no caption page builder block, so on a page builder page
 * the caption takes the image set the Bard route makes, inside a content block
 * (#119). `NonAcfBlockParser` is driven node by node, as `PageBuilderParser`
 * drives it, and its accumulated content finalized. In Bard content the block's
 * inner nodes must not be read a second time after the image set is added. A
 * quote in a caption must not end the set markup that carries its text (#177).
 *
 * @command  php vendor/bin/pest tests/Integration/CaptionShortcodeBlockTest.php
 */

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\PageBuilder\NonAcfBlockParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * The page builder sets `NonAcfBlockParser` builds from the given HTML.
 *
 * @param  string  $html  The page's block HTML
 * @return array{directSets: list<mixed>, content: mixed} The direct sets and the finalized content set
 */
function nonAcfCaptionSets(string $html, AssetImporter $assetImporter): array
{
    $doc = new DOMDocument;
    $doc->loadHTML('<html><body>'.$html.'</body></html>', LIBXML_NOERROR);
    $body = $doc->getElementsByTagName('body')->item(0);

    if (! $body instanceof DOMElement) {
        throw new RuntimeException('The test HTML has no body.');
    }

    $parser = new NonAcfBlockParser;
    $nodes = $body->childNodes;
    $accumulator = [];
    $directSets = [];

    // Walk the nodes as PageBuilderParser does, honoring each skip count
    for ($index = 0; $index < $nodes->length; $index++) {
        $node = $nodes->item($index);

        if (! $node instanceof DOMNode) {
            continue;
        }

        $result = $parser->parseNode($node, $index, $nodes, $accumulator, 'default', $assetImporter, new NullOutput, [], [], [], [], 'page');

        if (isset($result['directSet'])) {
            $directSets[] = $result['directSet'];
        }

        $index += \max(0, $result['skipCount'] - 1);
    }

    $content = [];

    return [
        'directSets' => $directSets,
        'content' => $parser->finalizeNonAcfContent($accumulator, $content, 'default', $assetImporter, new NullOutput),
    ];
}

it('imports a page builder caption as an image set carrying its caption text', function (): void {
    $log = Log::spy();
    $html = '<!-- wp:shortcode -->[caption id="attachment_1" align="alignright" width="300"]<img src="https://example.com/img.jpg" />My caption[/caption]<!-- /wp:shortcode -->';

    $sets = nonAcfCaptionSets($html, $this::createStub(AssetImporter::class));
    $content = $sets['content'];
    $encoded = (string) \json_encode($content);

    expect($sets['directSets'])->toBe([])
        ->and(\is_array($content) ? ($content['type'] ?? null) : null)->toBe('uamsweb_block_content')
        ->and($encoded)->toContain('"type":"set"')
        ->toContain('"type":"image"')
        ->toContain('My caption')
        ->not->toContain('[caption');

    $log->shouldNotHaveReceived('warning', [Mockery::pattern('/No content provided for \[caption\]/'), Mockery::any()]);
});

dataset('lone shortcode block content', [
    'a caption' => ['[caption id="attachment_1" align="alignright" width="300"]<img src="https://example.com/img.jpg" />My caption[/caption]'],
    'a show-people' => ['[show-people ids="1"]'],
]);

dataset('text beside a shortcode', [
    'bare text' => ['Lead [show-people ids="1"] tail', ['Lead', 'set', 'tail']],
    'text in a paragraph' => ['<p>Lead [show-people ids="1"] tail</p>', ['Lead', 'set', 'tail']],
    'text in a div' => ['<div>Lead [show-people ids="1"]</div>', ['Lead', 'set']],
    'struck text' => ['<s>Old</s> text [show-people ids="1"]', ['Old text', 'set']],
    'deleted text' => ['[show-people ids="1"] <del>gone</del> tail', ['set', 'gone tail']],
]);

/**
 * The node types of Bard content, with each paragraph as its text.
 *
 * @param  array<int|string, mixed>  $nodes  The Bard nodes
 * @return list<mixed> Each node's type, or a paragraph's text
 */
function shortcodeBlockNodeSummary(array $nodes): array
{
    $summary = [];

    foreach ($nodes as $node) {
        $type = \is_array($node) ? ($node['type'] ?? null) : null;
        $children = \is_array($node) ? ($node['content'] ?? null) : null;

        // Anything but a paragraph reads as its type
        if ($type !== 'paragraph' || ! \is_array($children)) {
            $summary[] = $type;

            continue;
        }

        // A paragraph reads as its text
        $text = '';

        foreach ($children as $child) {
            $childText = \is_array($child) ? ($child['text'] ?? null) : null;
            $text .= \is_string($childText) ? $childText : '';
        }

        $summary[] = $text;
    }

    return $summary;
}

/**
 * The body nodes of a finalized content block.
 *
 * @param  mixed  $content  The finalized content set
 * @return array<int|string, mixed> Its body, or nothing when it has none
 */
function shortcodeBlockBody(mixed $content): array
{
    $body = \is_array($content) ? ($content['body'] ?? null) : null;

    return \is_array($body) ? $body : [];
}

it('keeps the text around a shortcode in its block, on the Bard route', function (string $shortcode): void {
    $html = '<p>Intro</p><!-- wp:shortcode -->Lead '.$shortcode.' tail<!-- /wp:shortcode --><p>Outro</p>';

    foreach ([true, false] as $useWpAutoP) {
        $nodes = TextProcessor::processForBard($html, 'default', $this::createStub(AssetImporter::class), new NullOutput, $useWpAutoP);

        expect(shortcodeBlockNodeSummary($nodes))->toBe(['Intro', 'Lead', 'set', 'tail', 'Outro']);
    }
})->with('lone shortcode block content');

it('holds no text at the root of Bard content, whatever surrounds the shortcode', function (string $inner, array $expected): void {
    $html = '<!-- wp:shortcode -->'.$inner.'<!-- /wp:shortcode -->';

    $nodes = TextProcessor::processForBard('<p>Intro</p>'.$html, 'default', $this::createStub(AssetImporter::class), new NullOutput, false);

    expect(shortcodeBlockNodeSummary($nodes))->toBe(['Intro', ...$expected]);
})->with('text beside a shortcode');

it('keeps the text around a caption in its block, in place, on the page builder route', function (): void {
    $html = '<p>Intro</p><!-- wp:shortcode -->Lead [caption id="attachment_1"]<img src="https://example.com/img.jpg" />My caption[/caption] tail<!-- /wp:shortcode --><p>Outro</p>';

    $sets = nonAcfCaptionSets($html, $this::createStub(AssetImporter::class));

    expect($sets['directSets'])->toBe([])
        ->and(shortcodeBlockNodeSummary(shortcodeBlockBody($sets['content'])))->toBe(['Intro', 'Lead', 'set', 'tail', 'Outro']);
});

it('keeps the page builder set of a shortcode, and the text around it, on the page builder route', function (string $shortcode, string $setType): void {
    $html = '<p>Intro</p><!-- wp:shortcode -->Lead '.$shortcode.' tail<!-- /wp:shortcode --><p>Outro</p>';

    $sets = nonAcfCaptionSets($html, $this::createStub(AssetImporter::class));

    expect(Arr::get($sets['directSets'], '0.type'))->toBe($setType)
        ->and(shortcodeBlockNodeSummary(shortcodeBlockBody($sets['content'])))->toBe(['Intro', 'Lead  tail', 'Outro']);
})->with([
    'a show-people' => ['[show-people ids="1"]', 'uamsweb_block_show-people'],
    'a blogroll, which has no Bard set' => ['[blogroll category="x"]', 'uamsweb_block_news'],
]);

it('keeps the node right after a lone shortcode block', function (string $shortcode): void {
    $html = '<!-- wp:shortcode -->'.$shortcode.'<!-- /wp:shortcode --><p>After</p>';

    $nodes = TextProcessor::processForBard($html, 'default', $this::createStub(AssetImporter::class), new NullOutput, false);
    $sets = nonAcfCaptionSets($html, $this::createStub(AssetImporter::class));

    expect(shortcodeBlockNodeSummary($nodes))->toBe(['set', 'After'])
        ->and(\json_encode($sets['content']))->toContain('After');
})->with('lone shortcode block content');

it('adds the shortcode of a block with no closing comment once, and keeps what follows', function (string $shortcode): void {
    $html = '<p>Intro</p><!-- wp:shortcode -->'.$shortcode.'<p>After</p>';

    $nodes = TextProcessor::processForBard($html, 'default', $this::createStub(AssetImporter::class), new NullOutput, false);
    $sets = nonAcfCaptionSets($html, $this::createStub(AssetImporter::class));

    expect(shortcodeBlockNodeSummary($nodes))->toBe(['Intro', 'set', 'After'])
        ->and(\json_encode($sets))->toContain('After');
})->with('lone shortcode block content');

it('imports a caption block in Bard content as one image set and nothing else', function (): void {
    $html = "<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->\n<!-- wp:shortcode -->\n[caption id=\"attachment_1\" align=\"alignright\" width=\"300\"]<img src=\"https://example.com/img.jpg\" />My caption[/caption]\n<!-- /wp:shortcode -->\n<!-- wp:paragraph --><p>Outro</p><!-- /wp:paragraph -->";

    $nodes = TextProcessor::processForBard($html, 'default', $this::createStub(AssetImporter::class), new NullOutput, false);

    // The image appears once, with its caption, and no raw shortcode text is left
    $types = \array_map(static fn (mixed $node): mixed => \is_array($node) ? ($node['type'] ?? null) : null, $nodes);
    $images = \array_values(\array_filter($nodes, static fn (mixed $node): bool => \is_array($node) && ($node['type'] ?? null) === 'set'));

    expect($types)->toBe(['paragraph', 'set', 'paragraph'])
        ->and(\json_encode($images[0] ?? null))->toContain('"caption":"My caption"')
        ->and(\json_encode($nodes))->not->toContain('[\\/caption]');
});

it('keeps the whole text of a caption holding a quote, and leaves no stray text', function (
    string $tag,
    string $image,
    string $text,
    bool $useWpAutoP
): void {
    $html = '<p>Intro</p>'."\n\n".$tag.$image.' Melissa '.$text.' Evans swings at Rebsamen Park.[/caption]'."\n\n".'<p>Outro</p>';

    $nodes = TextProcessor::processForBard($html, 'default', $this::createStub(AssetImporter::class), new NullOutput, $useWpAutoP);

    // The nodes around the set are the two paragraphs, so nothing is left over
    $types = \array_map(static fn (mixed $node): mixed => \is_array($node) ? ($node['type'] ?? null) : null, $nodes);
    $caption = Arr::get($nodes, '1.attrs.values.caption');

    expect($types)->toBe(['paragraph', 'set', 'paragraph'])
        ->and(\trim(\strip_tags(\is_string($caption) ? $caption : '')))->toBe('Melissa "Mimi" Evans swings at Rebsamen Park.')
        ->and(\json_encode($nodes))->not->toContain('\\">');
})->with([
    'bare' => '[caption]',
    'attributed' => '[caption id="attachment_1" align="alignleft" width="300"]',
])->with([
    'an image with a plain alt' => '<img src="https://example.com/a.jpg" alt="Mimi" />',
    'an image with a quote in its alt' => '<img src="https://example.com/a.jpg" alt="Melissa &quot;Mimi&quot; Evans" />',
    'a linked image with a quote in its alt' => '<a href="https://example.com/a.jpg"><img src="https://example.com/a.jpg" alt="Melissa &quot;Mimi&quot; Evans" /></a>',
])->with([
    'a bare quote' => '"Mimi"',
    'an encoded quote' => '&quot;Mimi&quot;',
])->with([
    'with WpAutoP' => true,
    'without WpAutoP' => false,
]);

it('stores a caption as written, whether or not WpAutoP runs', function (string $image, string $text, string $expected): void {
    $html = "Intro\n\n[caption id=\"attachment_1\" align=\"alignleft\" width=\"300\"]".$image.' '.$text."[/caption]\n\nOutro";

    // Bare text stays a text node without WpAutoP, so only the set's place is the same
    foreach ([[true, ['Intro', 'set', 'Outro']], [false, ['text', 'set', 'text']]] as [$useWpAutoP, $summary]) {
        $nodes = TextProcessor::processForBard($html, 'default', $this::createStub(AssetImporter::class), new NullOutput, $useWpAutoP);

        expect(shortcodeBlockNodeSummary($nodes))->toBe($summary)
            ->and(Arr::get($nodes, '1.attrs.values.caption'))->toBe($expected);
    }
})->with([
    'a bare image' => ['<img src="https://example.com/a.jpg" />', 'Melissa Evans', 'Melissa Evans'],
    'a bare image, with a quote in its text' => ['<img src="https://example.com/a.jpg" />', 'Melissa "Mimi" Evans', 'Melissa "Mimi" Evans'],
    'a bare image, with a quote in its alt' => ['<img class="size-medium" src="https://example.com/a.jpg" alt="Melissa &quot;Mimi&quot; Evans" width="300" height="200" />', 'Melissa Evans', 'Melissa Evans'],
    'a linked image, with a quote in its alt' => ['<a href="https://example.com/a.jpg"><img class="size-medium" src="https://example.com/a.jpg" alt="Melissa &quot;Mimi&quot; Evans" width="300" height="200" /></a>', 'Melissa Evans', 'Melissa Evans'],
    'a bare image, with a paragraph tag of its own' => ['<img src="https://example.com/a.jpg" />', '<p class="note">Melissa Evans</p>', 'Melissa Evans'],
    'a bare image, with a tag that starts like a paragraph tag' => ['<img src="https://example.com/a.jpg" />', 'Melissa <picture></picture>Evans', 'Melissa <picture></picture>Evans'],
    'a linked image' => ['<a href="https://example.com/a.jpg"><img src="https://example.com/a.jpg" /></a>', 'Melissa Evans', 'Melissa Evans'],
    'a bare image, with markup in the caption' => [
        '<img src="https://example.com/a.jpg" />',
        'Melissa <em>Evans</em> at <a href="https://example.com/b">the park</a>',
        'Melissa <em>Evans</em> at <a href="https://example.com/b">the park</a>',
    ],
    'a linked image, with markup in the caption' => [
        '<a href="https://example.com/a.jpg"><img src="https://example.com/a.jpg" /></a>',
        'Melissa <em>Evans</em>',
        'Melissa <em>Evans</em>',
    ],
]);
