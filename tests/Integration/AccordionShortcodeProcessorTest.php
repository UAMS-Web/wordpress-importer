<?php

declare(strict_types=1);

/**
 * Tests for AccordionShortcodeProcessor, which turns `[accordion]` and
 * `[section title="..."]` shortcodes into accordion section data.
 *
 * `parse()` reads the shortcode content from ProcessingContext and returns
 * `['type' => 'accordion', ...sections]`, each section holding a tag-stripped
 * title and its trimmed content run through `TextProcessor::processForBard()`
 * with wpautop. `parseFromSegment()` finds the first `[accordion]...[/accordion]`
 * in a segment, stores its trimmed content in ProcessingContext and parses it,
 * or returns an empty array when there is no non-empty accordion.
 *
 * This is in the light Statamic band because `TextProcessor` logs through the
 * `Log` facade.
 *
 * @command  php vendor/bin/pest tests/Integration/AccordionShortcodeProcessorTest.php
 */

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Assets\AssetImporter;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\ProcessingContext;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\AccordionShortcodeProcessor;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\TextProcessor;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * Build an AssetImporter without its constructor, with the base URL the link
 * processing inside TextProcessor reads.
 */
function accordionAssetImporter(): AssetImporter
{
    $assetImporter = (new ReflectionClass(AssetImporter::class))->newInstanceWithoutConstructor();

    (new ReflectionProperty(AssetImporter::class, 'baseBlogUrl'))->setValue($assetImporter, 'https://example.test');

    return $assetImporter;
}

/**
 * The content TextProcessor produces for a section body, with wpautop on.
 *
 * @param  string  $content  The section body as the processor passes it
 * @return mixed The processed Bard content
 */
function accordionSectionContent(string $content): mixed
{
    return TextProcessor::processForBard(
        $content,
        'default',
        accordionAssetImporter(),
        new BufferedOutput,
        true,
        false,
        [],
        [],
        'slug'
    );
}

/**
 * A Bard paragraph node holding plain text.
 *
 * @param  string  $text  The paragraph text
 * @return array<string, mixed> The paragraph node
 */
function accordionParagraph(string $text): array
{
    return [
        'type' => 'paragraph',
        'content' => [['type' => 'text', 'text' => $text]],
        'attrs' => [],
    ];
}

beforeEach(function (): void {
    ProcessingContext::clearShortcodeContent();
});

afterEach(function (): void {
    ProcessingContext::clearShortcodeContent();
    ProcessingContext::clearAttachments();
});

it('parses each section into a stripped title and wpautop-processed content', function (): void {
    ProcessingContext::setShortcodeContent(
        "[section title=\"<b>One</b> &amp; two\"]\n Hello\n\nWorld \n[/section]\n[section title='Two' open=\"yes\"]Second[/section]"
    );

    $result = (new AccordionShortcodeProcessor)->parse(
        [],
        'default',
        accordionAssetImporter(),
        new BufferedOutput,
        [],
        'slug'
    );

    // A blank line between paragraphs becomes two paragraphs only with wpautop on
    expect($result)->toBe([
        'type' => 'accordion',
        [
            'title' => 'One & two',
            'content' => [accordionParagraph('Hello'), accordionParagraph('World')],
        ],
        [
            'title' => 'Two',
            'content' => [accordionParagraph('Second')],
        ],
    ]);
});

it('produces the same content whatever whitespace surrounds a section body', function (): void {
    ProcessingContext::setShortcodeContent('[section title="A"]  <strong>Bold</strong> text  [/section]');

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput, [], 'slug');

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => 'A', 'content' => accordionSectionContent('<strong>Bold</strong> text')],
    ]);
});

it('processes a section body as ordinary content, not as inner blocks', function (): void {
    // Inner-block processing strips block comments first; here the comment stays,
    // and the hard break it leaves after "one" is the observable difference
    $body = "one\n<!-- wp:x -->\n\ntwo";

    ProcessingContext::setShortcodeContent('[section title="A"]'.$body.'[/section]');

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput, [], 'slug');

    expect($result)->toBe([
        'type' => 'accordion',
        [
            'title' => 'A',
            'content' => [
                [
                    'type' => 'paragraph',
                    'content' => [['type' => 'text', 'text' => 'one'], ['type' => 'hardBreak']],
                    'attrs' => [],
                ],
                accordionParagraph('two'),
            ],
        ],
    ])
        ->and($result)->toBe([
            'type' => 'accordion',
            ['title' => 'A', 'content' => accordionSectionContent($body)],
        ]);
});

it('keeps other shortcodes inside a section body', function (): void {
    ProcessingContext::setShortcodeContent('[section title="A"]before [b]x[/b] after[/section]');

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput, [], 'slug');

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => 'A', 'content' => accordionSectionContent('before [b]x[/b] after')],
    ]);
});

it('ends a section at its own closing tag', function (): void {
    ProcessingContext::setShortcodeContent('[section title="A"]one[/section] between [section title="B"]two[/section]');

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput, [], 'slug');

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => 'A', 'content' => [accordionParagraph('one')]],
        ['title' => 'B', 'content' => [accordionParagraph('two')]],
    ]);
});

it('ignores sections without a title attribute and returns only the type with no sections', function (?string $content): void {
    if ($content !== null) {
        ProcessingContext::setShortcodeContent($content);
    }

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput);

    expect($result)->toBe(['type' => 'accordion']);
})->with([
    'no shortcode content in context' => [null],
    'no sections' => ['just text'],
    'section without a title' => ['[section]body[/section]'],
    'section never closed' => ['[section title="A"]body'],
]);

it('accepts an empty title', function (): void {
    ProcessingContext::setShortcodeContent('[section title=""]body[/section]');

    $result = (new AccordionShortcodeProcessor)->parse([], 'default', accordionAssetImporter(), new BufferedOutput);

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => '', 'content' => [accordionParagraph('body')]],
    ]);
});

it('finds the accordion in a segment, stores its trimmed content and parses it', function (): void {
    $result = (new AccordionShortcodeProcessor)->parseFromSegment(
        "Intro [accordion]\n [section title=\"A\"]one[/section] \n[/accordion] outro",
        'default',
        accordionAssetImporter(),
        new BufferedOutput,
        'slug'
    );

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => 'A', 'content' => [accordionParagraph('one')]],
    ])
        ->and(ProcessingContext::getShortcodeContent())->toBe('[section title="A"]one[/section]');
});

it('parses only the first accordion in a segment', function (): void {
    $result = (new AccordionShortcodeProcessor)->parseFromSegment(
        '[accordion][section title="A"]one[/section][/accordion][accordion][section title="B"]two[/section][/accordion]',
        'default',
        accordionAssetImporter(),
        new BufferedOutput
    );

    expect($result)->toBe([
        'type' => 'accordion',
        ['title' => 'A', 'content' => [accordionParagraph('one')]],
    ]);
});

it('returns an empty array and leaves the context alone without a non-empty accordion', function (string $segment): void {
    ProcessingContext::setShortcodeContent('untouched');

    $result = (new AccordionShortcodeProcessor)->parseFromSegment(
        $segment,
        'default',
        accordionAssetImporter(),
        new BufferedOutput
    );

    expect($result)->toBe([])
        ->and(ProcessingContext::getShortcodeContent())->toBe('untouched');
})->with([
    'no accordion' => ['[section title="A"]one[/section]'],
    'never closed' => ['[accordion][section title="A"]one[/section]'],
    'whitespace only' => ["[accordion] \n [/accordion]"],
    'empty' => ['[accordion][/accordion]'],
]);
