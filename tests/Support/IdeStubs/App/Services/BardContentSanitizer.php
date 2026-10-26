<?php

declare(strict_types=1);

namespace App\Services;

/**
 * IDE and static-analysis stub for {@see BardContentSanitizer}.
 *
 * The implementation lives in the consuming Laravel application. It is not
 * autoloaded via `composer.json`. Point both tools at `tests/Support/IdeStubs`
 * in `.vscode/settings.json`: Intelephense via
 * `intelephense.environment.includePaths`, and PHP Tools (Devsense) via
 * `php.workspace.includePath`.
 *
 * Method signatures mirror usage in this package; bodies are no-ops.
 */
class BardContentSanitizer
{
    /**
     * Sanitizes a list of Bard nodes, returning null/empty unchanged.
     *
     * Mirrors the real implementation's signature: it accepts and returns a Bard
     * node list (not a string-keyed envelope) and is nullable on both sides.
     *
     * @param  ?array<int|string, mixed>  $bardContent  Bard node list
     * @return ?array<int|string, mixed>
     */
    public function sanitize(?array $bardContent): ?array
    {
        return $bardContent;
    }

    public function containsYamlSeparatorsInRawFile(string $fileContent): bool
    {
        return false;
    }

    public function containsUnquotedNbspTextInRawFile(string $fileContent): bool
    {
        return false;
    }

    public function sanitizeRawFileContent(string $fileContent): string
    {
        return $fileContent;
    }
}
