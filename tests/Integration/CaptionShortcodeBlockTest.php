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

    $sets = nonAcfCaptionSets($html, $this->createMock(AssetImporter::class));
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

it('imports a caption block in Bard content as one image set and nothing else', function (): void {
    $html = "<!-- wp:paragraph --><p>Intro</p><!-- /wp:paragraph -->\n<!-- wp:shortcode -->\n[caption id=\"attachment_1\" align=\"alignright\" width=\"300\"]<img src=\"https://example.com/img.jpg\" />My caption[/caption]\n<!-- /wp:shortcode -->\n<!-- wp:paragraph --><p>Outro</p><!-- /wp:paragraph -->";

    $nodes = TextProcessor::processForBard($html, 'default', $this->createMock(AssetImporter::class), new NullOutput, false);

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

    $nodes = TextProcessor::processForBard($html, 'default', $this->createMock(AssetImporter::class), new NullOutput, $useWpAutoP);

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
