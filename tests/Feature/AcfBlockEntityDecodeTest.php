<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\NullOutput;
use Tests\TestCase;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\XmlParser;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ContentProcessor;

uses(TestCase::class);

afterEach(function (): void {
    Mockery::close();
});

/**
 * Tests that ACF block-comment JSON survives the up-front entity decode (issue wordpress-importer-archive#290).
 *
 * `ContentProcessor::process()` used to run `html_entity_decode()` over the entire
 * `<content:encoded>` string before the embedded ACF block-comment JSON was
 * `json_decode`d. When a block's attribute JSON contained an entity that decodes to
 * a JSON-significant character — `&quot;`/`&#34;`/`&#x22;` → `"` — the decoded quote
 * landed unescaped inside a JSON string value, `json_decode` failed, the attributes
 * fell back to `[]`, and the block was silently dropped with a misleading "empty
 * body" warning.
 *
 * The fix decodes entities everywhere except inside HTML comments
 * (`TextProcessor::decodeEntitiesPreservingComments`), so the block-comment JSON
 * reaches `json_decode` intact while field values are still entity-decoded
 * downstream when each block parser runs them through
 * `TextProcessor::processForBard()` / `HtmlTagStripper::strip()`.
 *
 * @command  php artisan test vendor/uams-web/wordpress-importer/tests/Feature/AcfBlockEntityDecodeTest.php
 */
it('keeps a uams-content block with a &quot; entity and round-trips the value', function (): void {
    $logSpy = Log::spy();

    $pageBuilder = acfProcessPageBuilder(
        acfUamsContentBlock('<p>She said &quot;hello&quot; to everyone.</p>')
    );

    // A uams-content block containing &quot; should be imported, not dropped.
    expect($pageBuilder)->toHaveCount(1);

    $block = acfRequireFirstPageBuilderBlock($pageBuilder);

    // The surviving block should be a uamsweb_block_content set.
    expect($block['type'] ?? null)->toBe('uamsweb_block_content');

    $body = $block['body'] ?? [];
    expect($body)->toBeArray();

    if (! is_array($body)) {
        throw new RuntimeException('Expected the block body to be an array.');
    }

    // The block body should be populated, not empty.
    expect($body)->not->toBeEmpty();

    // The &quot; entity should round-trip to a literal double quote in the Bard text.
    expect(acfCollectBardText($body))->toContain('She said "hello" to everyone.');

    // The genuine "empty body" warning must not fire for a populated block.
    $logSpy->shouldNotHaveReceived('warning');
});

it('keeps a uams-content block with numeric double-quote entities', function (): void {
    $pageBuilder = acfProcessPageBuilder(
        acfUamsContentBlock('<p>Use &#34;quotes&#34; and &#x22;more&#x22; here.</p>')
    );

    // Numeric double-quote entities should not corrupt the block-comment JSON.
    expect($pageBuilder)->toHaveCount(1);

    $block = acfRequireFirstPageBuilderBlock($pageBuilder);

    $body = $block['body'] ?? [];
    expect($body)->toBeArray();

    if (! is_array($body)) {
        throw new RuntimeException('Expected the block body to be an array.');
    }

    $bardText = acfCollectBardText($body);

    // Both &#34; and &#x22; should round-trip to literal double quotes.
    expect($bardText)->toContain('Use "quotes" and "more" here.');
});

it('still decodes non-JSON-significant entities in field values', function (): void {
    $pageBuilder = acfProcessPageBuilder(
        acfUamsContentBlock('<p>Research &amp; Care that&#8217;s great.</p>')
    );

    expect($pageBuilder)->toHaveCount(1);

    $block = acfRequireFirstPageBuilderBlock($pageBuilder);

    $body = $block['body'] ?? [];
    expect($body)->toBeArray();

    if (! is_array($body)) {
        throw new RuntimeException('Expected the block body to be an array.');
    }

    $bardText = acfCollectBardText($body);

    // &amp; and &#8217; should still decode in field values after import.
    expect($bardText)->toContain('Research & Care that’s great.');
});

it('still drops a genuinely empty uams-content block', function (): void {
    $logSpy = Log::spy();

    $pageBuilder = acfProcessPageBuilder(
        acfUamsContentBlock('')
    );

    // A block with truly empty body content should still be skipped.
    expect($pageBuilder)->toHaveCount(0);

    // The empty-body path must still warn for real empties. The production
    // call passes a log-context array as the second argument, so the matcher
    // list must account for it — a one-argument matcher never matches the
    // two-argument Log::warning($message, $context) call.
    $logSpy->shouldHaveReceived(
        'warning',
        [
            Mockery::on(
                fn (string $message): bool => str_contains($message, 'empty body')
            ),
            Mockery::any(),
        ]
    );
});

/**
 * Runs the given `<content:encoded>` HTML through `ContentProcessor::process()`
 * on the `pages` collection (landing_page / page_builder path) and returns the
 * resulting `page_builder` array.
 *
 * @param  string  $content  The raw content string (ACF block comments)
 * @return array<int|string, mixed> The page_builder replicator sets
 */
function acfProcessPageBuilder(string $content): array
{
    // Create a temp file so XmlParser has a valid path for ProcessingContext.
    $xmlPath = acfCreateTempXmlFile();

    // Parse the minimal XML so the parser exposes a file path.
    $xmlParser = new XmlParser;
    $xmlParser->parse($xmlPath);

    // Process the content through the real ContentProcessor pipeline.
    $result = (new ContentProcessor)->process(
        $content,
        'default',
        new AssetImporter,
        new NullOutput,
        $xmlParser,
        'Conditions Treated',
        '15943',
        'https://medicine.uams.edu/radiology/?page_id=15943',
        [],
        [],
        [],
        ['post_name' => 'conditions-treated', 'blueprint' => 'landing_page'],
        'pages',
        'conditions-treated'
    );

    // Remove the temp XML file.
    if (\file_exists($xmlPath)) {
        \unlink($xmlPath);
    }

    return $result['page_builder'];
}

/**
 * Return the first page_builder set after asserting the list is non-empty.
 *
 * @param  array<int|string, mixed>  $pageBuilder
 * @return array<mixed>
 */
function acfRequireFirstPageBuilderBlock(array $pageBuilder): array
{
    expect($pageBuilder)->not->toBeEmpty();

    $block = reset($pageBuilder);
    expect($block)->toBeArray();

    if (! is_array($block)) {
        throw new RuntimeException('Expected the first page_builder block to be an array.');
    }

    return $block;
}

/**
 * Writes a minimal valid WXR file so XmlParser::parse() succeeds and
 * getFilePath() returns a usable path.
 *
 * @return string The temp file path
 */
function acfCreateTempXmlFile(): string
{
    $xmlPath = \tempnam(\sys_get_temp_dir(), 'test_acf_entity_');

    \file_put_contents(
        $xmlPath,
        '<?xml version="1.0" encoding="UTF-8"?>'."\n".
        '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">'."\n".
        '    <channel>'."\n".
        '    </channel>'."\n".
        '</rss>'
    );

    return $xmlPath;
}

/**
 * Builds a self-closing `wp:acf/uams-content` block comment whose `content_content`
 * field holds the given HTML.
 *
 * @param  string  $contentContentHtml  The WYSIWYG HTML for the content_content field
 * @return string The block comment string
 */
function acfUamsContentBlock(string $contentContentHtml): string
{
    // Mirror how WordPress stores ACF attributes: JSON inside the block comment,
    // with HTML entities left encoded in the field values.
    $json = \json_encode(
        [
            'name' => 'acf/uams-content',
            'data' => [
                'content_heading' => 'Spine Interventions',
                'content_content' => $contentContentHtml,
            ],
            'mode' => 'preview',
        ],
        JSON_UNESCAPED_UNICODE
    );

    return "<!-- wp:acf/uams-content {$json} /-->";
}

/**
 * Recursively collects every `text` node value from a Bard/ProseMirror array.
 *
 * @param  mixed  $node  A Bard node, array of nodes, or scalar
 * @return string All text content concatenated with spaces
 */
function acfCollectBardText(mixed $node): string
{
    // Scalars carry no Bard text structure.
    if (! \is_array($node)) {
        return '';
    }

    $text = '';

    // A text node contributes its literal text.
    if (
        ($node['type'] ?? null) === 'text'
        && isset($node['text'])
        && \is_string($node['text'])
    ) {
        $text .= ' '.$node['text'];
    }

    // Recurse into every child/value to reach nested content.
    foreach ($node as $value) {
        if (\is_array($value)) {
            $text .= ' '.acfCollectBardText($value);
        }
    }

    return $text;
}
