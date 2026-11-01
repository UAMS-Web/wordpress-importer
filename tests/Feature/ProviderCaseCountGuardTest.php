<?php

declare(strict_types=1);

/*
 * Regression guard for wordpress-importer-archive#391.
 *
 * The four real-import integration files hung (100% CPU, no output, > 300s each)
 * because each one's data providers expanded into hundreds of cases, and every
 * case boots a fresh host Statamic app. The fix consolidated those providers so
 * each file collects a bounded number of executable cases:
 *   - the import files assert all of a variant's fields from a single import;
 *   - Formidable's unit-style mapping tests loop their provider rows in one test.
 *
 * This guard fails loudly if a future change re-explodes a provider, which would
 * silently reintroduce the boot-per-case stall.
 *
 * Since the suite was converted to Pest (wordpress-importer-archive#319), these files are function-style,
 * so the guard can no longer reflect over test classes. It counts executable
 * Pest cases from source instead: each `it()`/`test()` contributes one case,
 * except one driven by a `->with(<provider>())` dataset, which contributes that
 * provider's row count. The providers build their rows dynamically (a foreach
 * over variant configs), so the row count comes from invoking the provider, not
 * parsing a literal - which is why this must run inside the full Pest suite,
 * where every file-level provider function is already loaded during collection.
 *
 * @command  php artisan test vendor/uams-web/aether-wordpress-importer/tests/Feature/ProviderCaseCountGuardTest.php
 */

it('keeps each consolidated import file under its executable-case ceiling', function (string $relativeFile, int $ceiling): void {
    $actual = providerCaseCountGuardCountCases(__DIR__.'/'.$relativeFile);

    // A breach means a provider re-exploded (or a `#[DataProvider]`-style dataset
    // was re-added): each case boots a fresh host Statamic app, so this
    // reintroduces the multi-minute stall wordpress-importer-archive#391 fixed. Re-consolidate the provider
    // (assert a whole variant from one import, or loop unit-style rows inside one
    // test) rather than raising the ceiling.
    expect($actual)->toBeLessThanOrEqual($ceiling);
})->with(providerCaseCountGuardConsolidatedFiles());

/**
 * Each consolidated file paired with a ceiling that sits above legitimate growth
 * yet far below the pre-fix explosion (shown alongside) that caused the hang.
 * The current executable-case count is noted for reference.
 *
 * @return array<string, array{string, int}>
 */
function providerCaseCountGuardConsolidatedFiles(): array
{
    return [
        //                                    file                                     ceiling  (now / was)
        'PostImportTest' => ['PostImportTest.php', 70],                                 // 43 / 893
        'PageBuilderBlockImportTest' => ['PageBuilderBlockImportTest.php', 55],         // 29 / 711
        'KbImportTest' => ['KbImportTest.php', 85],                                     // 51 / 155
        'FormidableFormsBlueprintImportTest' => ['FormidableFormsBlueprintImportTest.php', 65], // 36 / 284
        'GravityFormsBlueprintImportTest' => ['GravityFormsBlueprintImportTest.php', 90], // 30 / 415
    ];
}

/**
 * Count the executable Pest cases a file collects.
 *
 * A plain `it()`/`test()` contributes one case; an `it(...)->with(<provider>())`
 * contributes that provider's row count. So:
 *   total = (it/test count - dataset count) + sum(rows of each dataset provider)
 *
 * @param  string  $absFile  Absolute path to the Pest test file
 */
function providerCaseCountGuardCountCases(string $absFile): int
{
    $src = \file_get_contents($absFile);
    if ($src === false) {
        throw new RuntimeException("Cannot read test file for case-count guard: {$absFile}.");
    }

    $itCount = \preg_match_all('/^(it|test)\(/m', $src);
    if ($itCount === false) {
        throw new RuntimeException("Failed to scan it()/test() calls in {$absFile}.");
    }

    $matched = \preg_match_all('/->with\((\w+)\(\)\)/', $src, $matches);
    if ($matched === false) {
        throw new RuntimeException("Failed to scan ->with() datasets in {$absFile}.");
    }

    // One entry per ->with(<provider>()) occurrence (duplicates kept: two tests
    // driven by the same provider each count that provider's rows).
    $providers = $matches[1];
    $datasetCount = \count($providers);

    $rowSum = 0;
    foreach ($providers as $provider) {
        if (! \function_exists($provider)) {
            throw new RuntimeException(
                "Dataset provider {$provider}() is not loaded. The case-count guard must run as part of "
                .'the full Pest suite so every file-level provider function is collected.'
            );
        }

        $rows = $provider();
        $rowSum += \is_array($rows) ? \count($rows) : ($rows instanceof Traversable ? \iterator_count($rows) : 0);
    }

    return ($itCount - $datasetCount) + $rowSum;
}
