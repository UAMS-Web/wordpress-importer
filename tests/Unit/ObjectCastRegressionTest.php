<?php

declare(strict_types=1);

/**
 * Regression guard against `(object)` cast misuse (issue #1).
 *
 * Casting a potentially-null expression with `(object)` silently converts
 * `null` into an empty `stdClass`, masking the not-found case so downstream
 * property access returns `null` (or fatals on a method call) instead of
 * failing fast at the lookup site. This test token-scans every PHP file in
 * `src/` and `tests/` and fails if any `(object)` cast is present.
 *
 * If a genuinely legitimate use appears later (e.g. casting a literal array
 * for JSON shaping), add its repo-relative path to allowedObjectCastFiles()
 * below.
 *
 * @command  php vendor/bin/pest tests/Unit/ObjectCastRegressionTest.php
 */

/**
 * Repo-relative file paths (forward slashes) exempt from the scan.
 *
 * Add paths here when a legitimate `(object)` cast is required; the scan loop
 * skips any file whose repo-relative path appears in this list.
 *
 * @return list<string>
 */
function allowedObjectCastFiles(): array
{
    return [];
}

it('finds no (object) casts in src/ or tests/', function (): void {
    // Resolve the repo root from this test file's location (tests/Unit/).
    $repoRoot = dirname(__DIR__, 2);

    // Collect every offending cast as "path:line".
    $violations = [];

    // Scan both production code and tests.
    foreach (['src', 'tests'] as $directory) {
        // Recursively iterate all files under the directory.
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                "{$repoRoot}/{$directory}",
                RecursiveDirectoryIterator::SKIP_DOTS
            )
        );

        // Check each file in the directory tree.
        foreach ($iterator as $file) {
            // Skip anything that is not a PHP source file.
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            // Normalize to a repo-relative forward-slash path.
            $relativePath = str_replace(
                '\\',
                '/',
                substr($file->getPathname(), strlen($repoRoot) + 1)
            );

            // Skip whitelisted files.
            foreach (allowedObjectCastFiles() as $allowedFile) {
                if ($allowedFile === $relativePath) {
                    continue 2;
                }
            }

            // Tokenize the file so comments and strings cannot false-positive.
            $tokens = token_get_all((string) file_get_contents($file->getPathname()));

            // Record every object-cast token with its line number.
            foreach ($tokens as $token) {
                if (is_array($token) && $token[0] === T_OBJECT_CAST) {
                    $violations[] = "{$relativePath}:{$token[2]}";
                }
            }
        }
    }

    // Fail with the full list of offending sites.
    expect($violations)->toBe(
        [],
        "Found (object) casts — these mask null lookups (see issue #1). Use an explicit null check instead, or whitelist a legitimate use in allowedObjectCastFiles():\n"
            .implode("\n", $violations)
    );
});
