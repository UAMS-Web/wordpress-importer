<?php

declare(strict_types=1);

/**
 * Guards that a `'Class::method'` string literal names a method that exists.
 *
 * The importer identifies a caller to its logs with a hand-written literal —
 * `ImageProcessor::importImageWithDisabledUploadHandling()`'s `$context`
 * argument, `addUpdatedAtField()`'s `$methodName`, and the message prefixes on
 * several `Log::warning()` calls. Nothing resolves those strings, so a wrong one
 * is invisible until somebody greps for the method it names and finds nothing.
 *
 * **This exists because fixing the instances did not stop it happening.** wordpress-importer-archive#751
 * copied a wrong name into a docblock, wordpress-importer-archive#763 into a test comment, wordpress-importer-archive#780 into a
 * code comment; wordpress-importer-archive#765 corrected the four `FieldMapper::mapMetaData` literals they
 * were all copied from, and wordpress-importer-archive#788 then found five more across three other files.
 * Each fix corrected what it could see and left the class of defect intact,
 * because the acceptance criterion each time was a grep for the specific old
 * name — which by construction cannot see the next one.
 *
 * The check is by reflection rather than by parsing, so an inherited method or
 * one supplied by a trait counts as declared: `FormCreationCoordinator`'s
 * `createStatamicFormFromWordPressForm()` lives on a trait, and
 * `Selector::loadAndValidateFile()` on a base class. A regex over declarations
 * would report both as missing.
 *
 * @command  php vendor/bin/pest tests/Unit/LogContextLiteralGuardTest.php
 */

/**
 * Index every class, interface and trait declared under `src/`.
 *
 * Keyed by short name to the list of fully-qualified names carrying it. A list
 * rather than a single value because twelve short names are declared more than
 * once here — `Selector` and `Validator` four times each, once per form plugin —
 * so a map keeping the last one seen resolves most of them to the wrong class.
 * That is not hypothetical: the first draft of this guard reported
 * `Loader::loadSettings` and `Validator::validateSettings` as missing when both
 * are declared, because it had resolved each to a different plugin's namesake.
 *
 * @return array<string, list<string>> Short name to every FQCN declaring it
 */
function logContextDeclaredClasses(): array
{
    $root = \dirname(__DIR__, 2).'/src';
    $map = [];

    foreach (logContextSourceFiles() as $path) {
        $source = \file_get_contents($path);

        if ($source === false) {
            continue;
        }

        if (\preg_match('/^namespace\s+([^;]+);/m', $source, $ns) !== 1) {
            continue;
        }

        if (\preg_match_all('/^(?:final\s+|abstract\s+)?(?:class|trait|interface)\s+(\w+)/m', $source, $names) === false) {
            continue;
        }

        foreach ($names[1] as $short) {
            $map[$short][] = \trim($ns[1]).'\\'.$short;
        }
    }

    return $map;
}

/**
 * Every `.php` file under `src/`.
 *
 * @return list<string> Absolute paths
 */
function logContextSourceFiles(): array
{
    $root = \dirname(__DIR__, 2).'/src';
    $paths = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        $paths[] = $file->getPathname();
    }

    return $paths;
}

/**
 * Resolve a short class name as the file naming it would resolve it.
 *
 * In order: a class the file declares itself, then one it imports, then a
 * unique declaration elsewhere in `src/`. Anything still ambiguous returns null
 * and is reported as skipped rather than guessed at — guessing is what produced
 * the false positives this ordering exists to prevent.
 *
 * @param  string  $short  The short class name from the literal
 * @param  string  $path  The file the literal appears in
 * @param  array<string, list<string>>  $declared  Short name to every FQCN declaring it
 * @return ?string The resolved FQCN, or null when it cannot be decided
 */
function logContextResolveClass(
    string $short,
    string $path,
    array $declared
): ?string {
    $candidates = $declared[$short] ?? [];

    if ($candidates === []) {
        return null;
    }

    $source = \file_get_contents($path);

    if ($source === false) {
        return null;
    }

    // The file's own declaration wins — a literal naming `Loader` inside
    // `MinervaKbSettings/Loader.php` means that one
    if (\preg_match('/^namespace\s+([^;]+);/m', $source, $ns) === 1) {
        $own = \trim($ns[1]).'\\'.$short;

        if (\in_array($own, $candidates, true)) {
            return $own;
        }
    }

    // Then an explicit import
    foreach ($candidates as $candidate) {
        if (\preg_match('/^use\s+'.\preg_quote($candidate, '/').'\s*;/m', $source) === 1) {
            return $candidate;
        }
    }

    // Then a name that is unambiguous package-wide
    if (\count($candidates) === 1) {
        return $candidates[0];
    }

    return null;
}

/**
 * Every `Class::method` pair appearing inside a string literal in `src/`.
 *
 * Tokenised rather than matched with a regex over raw lines. An earlier sweep
 * anchored on a closing quote immediately after the method name and so missed
 * every literal that continues into a message — the ones that actually reached a
 * log — and a later one missed double-quoted interpolated strings entirely. The
 * tokeniser sees a string because PHP says it is one.
 *
 * @return array<string, list<string>> `Class::method` to `<path>:<line>` for each site
 */
function logContextLiteralPairs(): array
{
    $pairs = [];

    foreach (logContextSourceFiles() as $path) {
        $source = \file_get_contents($path);

        if ($source === false) {
            continue;
        }

        foreach (\token_get_all($source) as $token) {
            if (! \is_array($token)) {
                continue;
            }

            if ($token[0] !== \T_CONSTANT_ENCAPSED_STRING && $token[0] !== \T_ENCAPSED_AND_WHITESPACE) {
                continue;
            }

            if (\preg_match_all('/\b([A-Z][A-Za-z0-9_]*)::([a-z][A-Za-z0-9_]*)\b/', $token[1], $found, \PREG_SET_ORDER) === false) {
                continue;
            }

            foreach ($found as $match) {
                // `X::class` is a constant, not a method
                if ($match[2] === 'class') {
                    continue;
                }

                $pairs[$match[1].'::'.$match[2]][] = $path.':'.$token[2];
            }
        }
    }

    return $pairs;
}

it('names no method that its class does not have', function (): void {
    $declared = logContextDeclaredClasses();
    $pairs = logContextLiteralPairs();

    $offenders = [];
    $skipped = [];

    $root = \dirname(__DIR__, 2).'/src/';

    foreach ($pairs as $pair => $sites) {
        [$class, $method] = \explode('::', $pair);

        foreach ($sites as $site) {
            $path = \substr($site, 0, (int) \strrpos($site, ':'));
            $resolved = logContextResolveClass($class, $path, $declared);

            // ---
            // Only a class this package declares, and which the naming file
            // resolves unambiguously, can be judged. Anything else is recorded as
            // skipped rather than passed, so a guard that quietly stops covering
            // things is visible rather than silent — the failure wordpress-importer-archive#764 was filed for.
            // ---
            if ($resolved === null) {
                $skipped[] = $pair;

                continue;
            }

            if (\method_exists($resolved, $method)) {
                continue;
            }

            $offenders[] = $pair.' at '.\str_replace($root, '', $site);
        }
    }

    expect($offenders)->toBe([], 'Log-context literals naming a method that does not exist: '.\implode('; ', $offenders));

    // ---
    // A guard that judged nothing would pass. Assert it actually looked at a
    // substantial set, so an accidental filter change presents as a failure
    // rather than as a green run.
    // ---
    expect(\count($pairs))->toBeGreaterThan(100);
    expect(\count($skipped))->toBeLessThan(\count($pairs) / 2);
});
