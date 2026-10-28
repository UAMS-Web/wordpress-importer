<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits\HasPromptsSupport;

/**
 * `HasPromptsSupport` decides whether Laravel Prompts can run, which it can everywhere but
 * Windows outside WSL (#100).
 */

/**
 * A class using the trait, with the protected check made callable.
 */
final class HasPromptsSupportHarness
{
    use HasPromptsSupport;

    /**
     * Call the protected check.
     *
     * @return bool Whether Laravel Prompts is supported here
     */
    public function supported(): bool
    {
        return $this->isPromptsSupported();
    }
}

it('answers for the platform the suite runs on as it does for that platform\'s name and path', function (): void {
    expect((new HasPromptsSupportHarness)->supported())->toBe(HasPromptsSupportHarness::promptsSupportedOn(PHP_OS, getenv('PATH')));
});

it('reports Laravel Prompts as supported on this platform outside Windows', function (): void {
    expect((new HasPromptsSupportHarness)->supported())->toBeTrue();
})->skip(PHP_OS_FAMILY === 'Windows', 'Windows answers by its PATH, which the dataset below covers');

it('supports Laravel Prompts everywhere but Windows outside WSL', function (string $os, string|false $path, bool $supported): void {
    expect(HasPromptsSupportHarness::promptsSupportedOn($os, $path))->toBe($supported);
})->with([
    'macOS, whose name ends in win' => ['Darwin', '/usr/bin', true],
    'Linux' => ['Linux', '/usr/bin', true],
    'Linux with no PATH' => ['Linux', false, true],
    'a name holding win but not starting with it' => ['Cygwin', '/usr/bin', true],
    'Windows' => ['WINNT', 'C:\\Windows\\System32', false],
    'Windows in lower case' => ['windows', 'C:\\Windows', false],
    'Windows with no PATH' => ['WINNT', false, false],
    'Windows with WSL on its PATH' => ['WINNT', 'C:\\Windows;C:\\wsl\\bin', true],
]);
