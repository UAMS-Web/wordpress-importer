<?php

declare(strict_types=1);

namespace App\Fieldtypes;

/**
 * IDE and static-analysis stub for {@see Phone}.
 *
 * The implementation lives in the consuming Statamic / Laravel application. It
 * is not autoloaded via `composer.json`. Point both tools at
 * `tests/Support/IdeStubs` in `.vscode/settings.json`: Intelephense via
 * `intelephense.environment.includePaths`, and PHP Tools (Devsense) via
 * `php.workspace.includePath`.
 *
 * Method signatures mirror usage in this package; bodies are no-ops.
 */
class Phone
{
    /**
     * @param  array<string, mixed>  $config  Fieldtype configuration
     */
    public function normalizeValue(
        string $phoneNumber,
        array $config
    ): string {
        return $phoneNumber;
    }
}
