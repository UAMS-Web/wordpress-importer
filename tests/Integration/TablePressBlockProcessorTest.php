<?php

declare(strict_types=1);

/**
 * Tests that `TablePressBlockProcessor` recognizes a `wp:tablepress/table`
 * opening comment and turns the block into a nonce-marked table set, logging
 * and skipping a block with no table id (#1314).
 *
 * Routed to the light Statamic band for the `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/TablePressBlockProcessorTest.php
 */

use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Output\NullOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\SetMarkupNonce;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\TablePressBlockProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

beforeEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

afterEach(function (): void {
    ProcessingContext::clearWordPressPageLink();
});

/**
 * A comment node holding the given text.
 *
 * @param  string  $text  The comment text
 * @return DOMComment The comment
 */
function tablePressComment(string $text): DOMComment
{
    return (new DOMDocument)->createComment($text);
}

/**
 * Process a block with the given attributes.
 *
 * @param  DOMNode  $node  The block's opening node
 * @param  array<mixed>  $attributes  The block attributes
 * @param  ?string  $pageSlug  The page slug, for the log
 * @return array<mixed>|string|null The processor's result
 */
function tablePressProcess(DOMNode $node, array $attributes, ?string $pageSlug = 'about'): array|string|null
{
    $assetImporter = new ReflectionClass(AssetImporter::class)->newInstanceWithoutConstructor();

    return new TablePressBlockProcessor()->process($node, $attributes, 'default', $assetImporter, new NullOutput, [], [], $pageSlug);
}

it('recognizes a TablePress block opening comment', function (string $text): void {
    expect(new TablePressBlockProcessor()->canProcess(tablePressComment($text), 'default', null))->toBeTrue();
})->with([
    'with no attributes' => ['wp:tablepress/table'],
    'with attributes' => ['wp:tablepress/table {"id":"5"}'],
    'with padding' => ["  wp:tablepress/table {\"id\":\"5\"}  \n"],
    'with attributes across lines' => ["wp:tablepress/table {\"id\":\n\"5\"}"],
    'with no space before the attributes' => ['wp:tablepress/table{"id":"5"}'],
]);

it('does not recognize anything else', function (DOMNode $node): void {
    expect(new TablePressBlockProcessor()->canProcess($node, 'default', null))->toBeFalse();
})->with([
    'the closing comment' => [fn (): DOMNode => tablePressComment('/wp:tablepress/table')],
    'a longer block name' => [fn (): DOMNode => tablePressComment('wp:tablepress/tables')],
    'another block' => [fn (): DOMNode => tablePressComment('wp:table {"id":"5"}')],
    'text after the attributes' => [fn (): DOMNode => tablePressComment('wp:tablepress/table {"id":"5"} x')],
    'text before the block name' => [fn (): DOMNode => tablePressComment('x wp:tablepress/table')],
    'an element' => [fn (): DOMNode => (new DOMDocument)->createElement('p', 'wp:tablepress/table')],
    'a text node' => [fn (): DOMNode => (new DOMDocument)->createTextNode('wp:tablepress/table')],
]);

it('does not recognize a self-closing TablePress comment', function (): void {
    // Characterization: the pattern allows only whitespace after the attributes,
    // so the trailing slash of a self-closing block comment does not match
    expect(new TablePressBlockProcessor()->canProcess(tablePressComment('wp:tablepress/table {"id":"5"} /'), 'default', null))->toBeFalse();
});

it('turns a block with a table id into a table set', function (mixed $id): void {
    $logSpy = Log::spy();
    $node = tablePressComment('wp:tablepress/table');

    $result = tablePressProcess($node, ['id' => $id]);

    expect($result)->toBe([
        'html' => '<div data-set-type="table"'.SetMarkupNonce::attribute().' data-set-attr-create_query="collection" '
            .'data-set-attr-table_entry="" data-set-attr-heading_query="false" '
            .'data-set-attr-description_query="false" data-set-attr-heading_level="h2" '
            .'data-set-attr-align="aligncenter"></div>',
        'processedNode' => $node,
        'skipCount' => 3,
    ]);

    $logSpy->shouldNotHaveReceived('warning');
})->with([
    'a string id' => ['5'],
    'an integer id' => [5],
    'a zero id' => [0],
]);

it('logs and skips a block with no usable table id', function (array $attributes, ?string $pageSlug, string $expectedSlug): void {
    $logSpy = Log::spy();

    expect(tablePressProcess(tablePressComment('wp:tablepress/table'), $attributes, $pageSlug))->toBeNull();

    $logSpy->shouldHaveReceived('warning', [
        "TablePressBlockProcessor::process: Missing 'id' in wp:tablepress block",
        ['site' => 'default', 'pageSlug' => $expectedSlug],
    ]);
})->with([
    'no id' => [[], 'about', 'about'],
    'a null id' => [['id' => null], 'about', 'about'],
    'an empty id' => [['id' => ''], 'about', 'about'],
    'a float id' => [['id' => 5.0], 'about', 'about'],
    'an array id' => [['id' => ['5']], 'about', 'about'],
    'no id and no page slug' => [[], null, 'none'],
]);

it('names the page in the log message when the page link is known', function (): void {
    $logSpy = Log::spy();
    ProcessingContext::setWordPressPageLink('https://example.org/clinic/');

    tablePressProcess(tablePressComment('wp:tablepress/table'), []);

    $logSpy->shouldHaveReceived('warning', [
        "TablePressBlockProcessor::process: Missing 'id' in wp:tablepress block (from entry: https://example.org/clinic/)",
        Mockery::on(static fn (mixed $context): bool => \is_array($context) && ($context['entry_url'] ?? null) === 'https://example.org/clinic/'),
    ]);
});
