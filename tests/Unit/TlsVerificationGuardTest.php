<?php

declare(strict_types=1);

/**
 * Regression guard against TLS certificate verification being disabled anywhere
 * in the importer's source tree.
 *
 * Two cURL call sites in `LinkProcessor` shipped with
 * `CURLOPT_SSL_VERIFYPEER => false` and an inline "for development" comment,
 * while running in every environment (#4). Disabling peer verification silently
 * accepts a MITM response for a URL taken from the WXR being imported, so this
 * test fails the build if the option — or its `CURLOPT_SSL_VERIFYHOST`
 * companion — is disabled again.
 *
 * The scan is a source-text check rather than a runtime assertion because the
 * defect is the *absence* of a safe default: cURL and Guzzle both verify by
 * default, so there is no behavior to assert against until someone opts out.
 *
 * @command  php vendor/bin/pest tests/Unit/TlsVerificationGuardTest.php
 */

/**
 * Every PHP file in the importer's source tree, keyed by its path relative to
 * the package root so a failure message names the offending file.
 *
 * @return array<string, string> Relative path => file contents
 */
function tlsGuardSourceFiles(): array
{
    $root = \dirname(__DIR__, 2);
    $sourceRoot = $root.'/src';

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $sourceRoot,
            FilesystemIterator::SKIP_DOTS
        )
    );

    $files = [];

    // Collect the contents of every PHP file under src/
    foreach ($iterator as $file) {
        if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }

        $path = $file->getPathname();
        $contents = file_get_contents($path);

        if ($contents === false) {
            continue;
        }

        // Normalize the key to a forward-slashed relative path for readability
        $files[str_replace('\\', '/', substr($path, \strlen($root) + 1))] = $contents;
    }

    return $files;
}

it('scans a non-empty source tree', function (): void {
    // Guard the guard: an empty scan would pass every assertion below
    expect(tlsGuardSourceFiles())->not->toBeEmpty();
});

it('never disables cURL TLS certificate verification', function (): void {
    $offenders = [];

    // Match CURLOPT_SSL_VERIFY{PEER,HOST} set to a falsy literal, in either the
    // curl_setopt() argument form or the curl_setopt_array() `=>` form,
    // tolerating the newline-per-argument style this package formats cURL with
    $pattern = '/CURLOPT_SSL_VERIFY(?:PEER|HOST)\s*(?:,|=>)\s*(?:false|0)\b/i';

    foreach (tlsGuardSourceFiles() as $relativePath => $contents) {
        if (\preg_match($pattern, $contents) === 1) {
            $offenders[] = $relativePath;
        }
    }

    expect($offenders)->toBe(
        [],
        'TLS certificate verification must stay enabled (#4). Disabled in: '
            .implode(', ', $offenders)
    );
});

it('never disables Guzzle or Laravel HTTP client TLS verification', function (): void {
    $offenders = [];

    // Two spellings of the same opt-out on the Http/Guzzle side: the raw
    // Guzzle option, and Laravel's `withoutVerifying()` shorthand for it. The
    // shorthand is the one most likely to be reached for, and it mentions
    // neither `verify` nor `false`, so it needs its own pattern. That pattern
    // matches the bare identifier rather than anchoring on `->`, because the
    // call is equally reachable statically as `Http::withoutVerifying()`.
    $patterns = [
        '/[\'"]verify[\'"]\s*=>\s*(?:false|0)\b/i',
        '/RequestOptions::VERIFY\s*=>\s*(?:false|0)\b/i',
        '/\bwithoutVerifying\s*\(/i',
    ];

    foreach (tlsGuardSourceFiles() as $relativePath => $contents) {
        foreach ($patterns as $pattern) {
            if (\preg_match($pattern, $contents) === 1) {
                $offenders[] = $relativePath;

                break;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'TLS certificate verification must stay enabled (#4). Disabled in: '
            .implode(', ', $offenders)
    );
});
