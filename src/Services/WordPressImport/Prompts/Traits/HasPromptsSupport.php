<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Prompts\Traits;

/**
 * Trait providing Laravel Prompts support detection functionality.
 *
 * This trait contains the shared logic for determining if Laravel Prompts
 * is supported on the current platform, eliminating code duplication
 * across multiple prompt classes.
 */
trait HasPromptsSupport
{
    /**
     * Check if Laravel Prompts is supported (macOS, Linux, WSL).
     *
     * Determines platform compatibility for Laravel Prompts, falling back to Symfony
     * Console Questions on unsupported platforms (Windows without WSL).
     *
     * @return bool True if Laravel Prompts is supported, false otherwise
     */
    protected function isPromptsSupported(): bool
    {
        return self::promptsSupportedOn(PHP_OS, getenv('PATH'));
    }

    /**
     * Check whether Laravel Prompts is supported on an operating system with a given PATH.
     *
     * Separate from `isPromptsSupported()`, which reads the running platform, so every branch
     * can be tested from any platform (#100).
     *
     * @param  string  $os  The operating system name, as `PHP_OS` gives it
     * @param  string|false  $path  The PATH, or false when it is not set
     * @return bool True unless the system is Windows without WSL on its PATH
     */
    public static function promptsSupportedOn(
        string $os,
        string|false $path
    ): bool {
        // Check if the operating system is Windows
        $isWindows = \str_starts_with(
            \strtolower($os),
            'win'
        );

        // Check if running on Windows Subsystem for Linux (WSL), which a PATH that is not set
        // cannot show
        $isWsl = $isWindows && $path !== false && \str_contains(
            $path,
            'wsl'
        );

        // Return true if not Windows, or if Windows and WSL is detected
        return ! $isWindows || $isWsl;
    }
}
