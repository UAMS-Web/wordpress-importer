<?php

declare(strict_types=1);

/**
 * Tests that `LinkHelper` reads a link's URL and new-tab flag from an ACF link
 * value, whether it arrives as an array, a serialized array or a bare string
 * (#1314).
 *
 * @command  php vendor/bin/pest tests/Unit/LinkHelperTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\LinkHelper;

/**
 * A class that records being woken from a serialized string.
 */
class LinkHelperWakeProbe
{
    /**
     * @var bool Whether any instance has been unserialized
     */
    public static bool $woken = false;

    /**
     * Record the wake-up.
     */
    public function __wakeup(): void
    {
        self::$woken = true;
    }
}

/**
 * Run a call, swallowing the warning PHP raises for unserializing a string that
 * is not serialized and turning any other warning into an exception.
 *
 * The helper silences that warning, and PHPUnit would still report it. Any other
 * warning, such as a read of a missing array key, fails the test.
 *
 * @template T
 *
 * @param  Closure(): T  $call  The call
 * @return T The call's result
 */
function linkHelperQuietly(Closure $call): mixed
{
    \set_error_handler(static function (int $severity, string $message): bool {
        if (\str_starts_with($message, 'unserialize(): ')) {
            return true;
        }

        throw new ErrorException($message, 0, $severity);
    });

    try {
        return $call();
    } finally {
        \restore_error_handler();
    }
}

it('reads the URL of a link', function (mixed $link, string $fallback, string $expected): void {
    expect(linkHelperQuietly(static fn (): string => LinkHelper::getUrl($link, $fallback)))->toBe($expected);
})->with([
    'an array' => [['url' => 'https://example.org/a', 'target' => '_blank'], 'fb', 'https://example.org/a'],
    'a serialized array' => [\serialize(['url' => 'https://example.org/b']), 'fb', 'https://example.org/b'],
    'a bare string, with no fallback' => ['https://example.org/c', '', 'https://example.org/c'],
    'an array with no URL' => [['title' => 'x'], 'fb', 'fb'],
    'an array with an empty URL' => [['url' => ''], 'fb', 'fb'],
    'an array with a URL that is not a string' => [['url' => 5], 'fb', 'fb'],
    'a serialized array with no URL' => [\serialize(['title' => 'x']), 'fb', 'fb'],
    'a serialized array with a null URL' => [\serialize(['url' => null]), 'fb', 'fb'],
    'a serialized value that is not an array' => [\serialize('https://example.org/d'), '', \serialize('https://example.org/d')],
    'null' => [null, 'fb', 'fb'],
    'null, with no fallback' => [null, '', ''],
    'an integer' => [5, 'fb', 'fb'],
]);

it('falls back to an empty string by default', function (): void {
    expect(LinkHelper::getUrl(null))->toBe('');
});

it('does not wake an object out of a serialized link', function (): void {
    LinkHelperWakeProbe::$woken = false;

    $serialized = \serialize(['url' => new LinkHelperWakeProbe]);
    $url = LinkHelper::getUrl($serialized, 'fb');

    // The URL is not a string, so the link value comes back as it was given
    expect($url)->toBe($serialized)
        ->and(LinkHelperWakeProbe::$woken)->toBeFalse();
});

it('prefers the fallback to a bare string when a fallback is given', function (): void {
    // Characterization: a bare string is not unserializable, so the extractor
    // hands back the fallback, which wins over the string itself
    expect(linkHelperQuietly(static fn (): string => LinkHelper::getUrl('https://example.org/c', 'fb')))->toBe('fb');
});

it('reads whether a link opens in the same tab', function (mixed $link, bool $expected): void {
    expect(linkHelperQuietly(static fn (): bool => LinkHelper::getTarget($link)))->toBe($expected);
})->with([
    'a new tab' => [['target' => '_blank'], false],
    'a named target' => [['target' => '_self'], true],
    'a truthy flag' => [['target' => '1'], true],
    'an empty target' => [['target' => ''], false],
    'a zero target' => [['target' => '0'], false],
    'no target' => [['url' => 'https://example.org/'], false],
    'a null target' => [['target' => null], false],
    'a serialized new tab' => [\serialize(['target' => '_blank']), false],
    'a serialized named target' => [\serialize(['target' => '_self']), true],
    'a bare string' => ['_self', false],
    'null' => [null, false],
]);
