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
        // Get the current operating system name in lowercase
        $os = \strtolower(PHP_OS);

        // Check if the operating system is Windows
        $isWindows = \str_starts_with(
            $os,
            'win'
        );

        // Check if running on Windows Subsystem for Linux (WSL)
        $isWsl = $isWindows && \str_contains(
            (($path = getenv('PATH')) !== false ? $path : ''),
            'wsl'
        );

        // Return true if not Windows, or if Windows and WSL is detected
        return ! $isWindows || $isWsl;
    }
}
