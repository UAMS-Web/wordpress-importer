<?php

declare(strict_types=1);

/**
 * Tests that `ShortcodeProcessor`'s shortcode-or-prose rule decides a long attribute string
 * without running PCRE out of backtracking (#321).
 *
 * The attribute-list pattern once tried every way to split a run of word characters
 * between a bare name and an unquoted value before rejecting the string, so a string of
 * twenty or more such characters ending in one the list cannot hold exhausted PCRE's
 * backtrack limit. `preg_match()` then returned `false`, which read the same as "not a
 * shortcode". These cases assert both the answer and that PCRE raised no error.
 *
 * The rule is private and pure string matching, so it is called through reflection with
 * no framework booted.
 *
 * @command  php vendor/bin/pest tests/Unit/ShortcodeAttributeListTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\Processors\ShortcodeProcessor;

/**
 * Whether ShortcodeProcessor reads a bracketed name and its attribute text as a shortcode.
 *
 * @param  string  $name  The bracketed name
 * @param  string  $attributes  The text after the name
 * @return bool True when it is read as a shortcode
 */
function attributeListLooksLikeShortcode(string $name, string $attributes): bool
{
    $result = (new ReflectionMethod(ShortcodeProcessor::class, 'looksLikeShortcode'))->invoke(null, $name, $attributes, false);

    if (! is_bool($result)) {
        throw new LogicException('looksLikeShortcode() returned no bool.');
    }

    return $result;
}

it('rejects a long attribute string the list cannot hold, with no PCRE error', function (string $attributes): void {
    expect(attributeListLooksLikeShortcode('mystery', $attributes))->toBeFalse()
        ->and(preg_last_error())->toBe(PREG_NO_ERROR);
})->with([
    'the issue\'s input, N=25' => ['a=b '.str_repeat('x', 25).'!'],
    'a long run, N=200' => ['a=b '.str_repeat('x', 200).'!'],
    'bare names, then a stray character' => [str_repeat('ab ', 40).'a=b !'],
]);

it('accepts a long attribute list, with no PCRE error', function (string $attributes): void {
    expect(attributeListLooksLikeShortcode('mystery', $attributes))->toBeTrue()
        ->and(preg_last_error())->toBe(PREG_NO_ERROR);
})->with([
    'many unquoted pairs' => [str_repeat('a=b ', 200)],
    'a long unquoted value' => ['a='.str_repeat('x', 500)],
    'quoted and bare items' => [str_repeat('k="v w" flag ', 50).'x=1 /'],
]);

/**
 * The attribute lists #320 accepts and rejects, which the rewritten pattern keeps.
 *
 * @param  string  $attributes  The text after the name
 * @param  bool  $expected  Whether it is read as a shortcode
 */
it('keeps the answers the list pattern gave before', function (string $attributes, bool $expected): void {
    expect(attributeListLooksLikeShortcode('mystery', $attributes))->toBe($expected);
})->with([
    'a double-quoted value' => ['attachment="519"', true],
    'a single-quoted value' => ["type='iframe' width='100%'", true],
    'an unquoted value' => ['alignment=centered', true],
    'self-closing' => ['attachment="519" /', true],
    'a glued self-closing slash' => ['id=2/', true],
    'a hyphenated key' => ['data-id=3', true],
    'spaces around the equals sign' => ['a = 1', true],
    'a bare name before a pair' => ['flag a=1', true],
    'curly quotes, read as an unquoted value' => ['category="news" images=“false”', true],
    'no value at all' => ['a b c', false],
    'prose with an equals sign' => ['< 0.05, r = 0.3', false],
    'an unclosed quote' => ['a="b', false],
    'an escaped quote' => ['a=\\"b\\"', false],
]);
