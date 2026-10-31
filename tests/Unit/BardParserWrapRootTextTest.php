<?php

declare(strict_types=1);

/**
 * Tests that BardParser::wrapRootText() leaves no inline node at the root of a
 * Bard document, for the text beside a set in a `wp:shortcode` block (#180).
 *
 * @command  php vendor/bin/pest tests/Unit/BardParserWrapRootTextTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\BardParser;

$text = static fn (string $value): array => ['type' => 'text', 'text' => $value];
$set = ['type' => 'set', 'attrs' => ['values' => ['type' => 'people']]];
$break = ['type' => 'hardBreak'];
$paragraph = static fn (array ...$nodes): array => ['type' => 'paragraph', 'content' => $nodes];

it('wraps each run of text around a block in its own paragraph', function () use ($text, $set, $paragraph): void {
    expect(BardParser::wrapRootText([$text('Lead'), $set, $text('tail')]))
        ->toBe([$paragraph($text('Lead')), $set, $paragraph($text('tail'))]);
});

it('puts neighboring inline nodes in one paragraph', function () use ($text, $break, $paragraph): void {
    $marked = ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]];

    expect(BardParser::wrapRootText([$text('One '), $marked, $break, $text('Two')]))
        ->toBe([$paragraph($text('One '), $marked, $break, $text('Two'))]);
});

it('removes the space at the edges of a run, and no other', function () use ($text, $paragraph): void {
    expect(BardParser::wrapRootText([$text(' One '), $text(' Two ')]))
        ->toBe([$paragraph($text('One '), $text(' Two'))]);
});

it('drops an edge node left empty by the trim', function () use ($text, $paragraph): void {
    $marked = ['type' => 'text', 'text' => 'bold', 'marks' => [['type' => 'bold']]];

    expect(BardParser::wrapRootText([$text(' '), $marked, $text(' ')]))
        ->toBe([$paragraph($marked)]);
});

it('drops a run that holds only whitespace', function () use ($text, $set, $break): void {
    expect(BardParser::wrapRootText([$set, $text(" \n"), $break, $set]))->toBe([$set, $set]);
});

it('keeps a run that starts or ends in a node with no text', function () use ($text, $break, $paragraph): void {
    expect(BardParser::wrapRootText([$break, $text('One'), $break]))
        ->toBe([$paragraph($break, $text('One'), $break)]);
});

it('leaves content with no root text as it is', function () use ($text, $set, $paragraph): void {
    $content = [$paragraph($text('One')), $set, 'not a node'];

    expect(BardParser::wrapRootText($content))->toBe($content)
        ->and(BardParser::wrapRootText([]))->toBe([]);
});
