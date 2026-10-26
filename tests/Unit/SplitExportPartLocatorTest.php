<?php

declare(strict_types=1);

/**
 * Tests split-export part discovery: sibling detection, ascending ordering, gap
 * reporting, base-name isolation, and the untouched single-file path (wordpress-importer-archive#365).
 *
 * @command  php vendor/bin/pest tests/Unit/SplitExportPartLocatorTest.php
 */

use UAMSWeb\WordPressImporter\Services\WordPressImport\Parsers\SplitExportPartLocator;

beforeEach(function (): void {
    // Create an isolated scratch directory for this test's part fixtures
    $this->workingDirectory = sys_get_temp_dir().DIRECTORY_SEPARATOR.uniqid('wxr_parts_', true);
    mkdir($this->workingDirectory);
});

afterEach(function (): void {
    // Remove every fixture file, then the scratch directory itself
    $entries = glob($this->workingDirectory.DIRECTORY_SEPARATOR.'*');

    if ($entries === false) {
        $entries = [];
    }

    foreach ($entries as $entry) {
        unlink($entry);
    }

    rmdir($this->workingDirectory);
});

/**
 * Create an empty fixture file with the given name in the scratch directory.
 *
 * @param  string  $directory  The scratch directory to create the fixture in
 * @param  string  $name  The bare filename to create
 * @return string The absolute path to the created fixture
 */
function makeSplitPartFixture(
    string $directory,
    string $name
): string {
    // Materialize the fixture path and touch an empty file there
    $path = $directory.DIRECTORY_SEPARATOR.$name;
    file_put_contents(
        $path,
        ''
    );

    return $path;
}

it('returns a single non-part file unchanged', function (): void {
    $path = makeSplitPartFixture($this->workingDirectory, 'WordPressExport_example.xml');

    $set = SplitExportPartLocator::locate($path);

    expect($set->parts)->toBe([$path])
        ->and($set->isSplit)->toBeFalse()
        ->and($set->hasGap())->toBeFalse();
});

it('discovers every sibling from any single part', function (): void {
    $part0 = makeSplitPartFixture($this->workingDirectory, 'news.000.xml');
    $part1 = makeSplitPartFixture($this->workingDirectory, 'news.001.xml');
    $part2 = makeSplitPartFixture($this->workingDirectory, 'news.002.xml');

    // Locating from the middle part still yields the whole ordered set
    $set = SplitExportPartLocator::locate($part1);

    expect($set->parts)->toBe([$part0, $part1, $part2])
        ->and($set->isSplit)->toBeTrue()
        ->and($set->hasGap())->toBeFalse()
        ->and($set->firstPart())->toBe($part0);
});

it('orders parts numerically regardless of creation order', function (): void {
    // Create parts out of order to prove ordering is by index, not mtime/scan
    $part2 = makeSplitPartFixture($this->workingDirectory, 'news.002.xml');
    $part0 = makeSplitPartFixture($this->workingDirectory, 'news.000.xml');
    $part1 = makeSplitPartFixture($this->workingDirectory, 'news.001.xml');

    $set = SplitExportPartLocator::locate($part2);

    expect($set->parts)->toBe([$part0, $part1, $part2]);
});

it('orders parts numerically across the ten boundary', function (): void {
    // Create parts spanning the two-digit boundary out of order
    $part10 = makeSplitPartFixture($this->workingDirectory, 'news.010.xml');
    $part9 = makeSplitPartFixture($this->workingDirectory, 'news.009.xml');
    $part8 = makeSplitPartFixture($this->workingDirectory, 'news.008.xml');
    $part11 = makeSplitPartFixture($this->workingDirectory, 'news.011.xml');

    // Locating still requires a contiguous sequence from .000, so fill the gap
    for ($index = 0; $index <= 7; $index++) {
        makeSplitPartFixture($this->workingDirectory, sprintf('news.%03d.xml', $index));
    }

    $set = SplitExportPartLocator::locate($part10);

    // The last four parts must be ordered 8, 9, 10, 11 (integer order)
    $tail = array_slice(
        $set->parts,
        -4
    );

    expect($tail)->toBe([$part8, $part9, $part10, $part11])
        ->and($set->hasGap())->toBeFalse();
});

it('reports an internal gap in the sequence', function (): void {
    $part0 = makeSplitPartFixture($this->workingDirectory, 'news.000.xml');
    $part2 = makeSplitPartFixture($this->workingDirectory, 'news.002.xml');

    $set = SplitExportPartLocator::locate($part0);

    expect($set->parts)->toBe([$part0, $part2])
        ->and($set->hasGap())->toBeTrue()
        ->and($set->missingParts)->toBe([1]);
});

it('reports a missing leading part', function (): void {
    $part1 = makeSplitPartFixture($this->workingDirectory, 'news.001.xml');
    makeSplitPartFixture($this->workingDirectory, 'news.002.xml');

    $set = SplitExportPartLocator::locate($part1);

    expect($set->hasGap())->toBeTrue()
        ->and($set->missingParts)->toBe([0]);
});

it('treats a lone first part as a single file', function (): void {
    $part0 = makeSplitPartFixture($this->workingDirectory, 'news.000.xml');

    $set = SplitExportPartLocator::locate($part0);

    expect($set->parts)->toBe([$part0])
        ->and($set->isSplit)->toBeFalse()
        ->and($set->hasGap())->toBeFalse();
});

it('does not cross-match a different base', function (): void {
    $rawPart0 = makeSplitPartFixture($this->workingDirectory, 'news.000.xml');
    $rawPart1 = makeSplitPartFixture($this->workingDirectory, 'news.001.xml');
    makeSplitPartFixture($this->workingDirectory, 'news_cleaned.000.xml');
    makeSplitPartFixture($this->workingDirectory, 'news_cleaned.001.xml');

    // Locating a raw part must not absorb the `_cleaned` siblings
    $set = SplitExportPartLocator::locate($rawPart0);

    expect($set->parts)->toBe([$rawPart0, $rawPart1])
        ->and($set->hasGap())->toBeFalse();
});

it('treats exactly two parts as a split set', function (): void {
    $part0 = makeSplitPartFixture($this->workingDirectory, 'news.000.xml');
    $part1 = makeSplitPartFixture($this->workingDirectory, 'news.001.xml');

    $set = SplitExportPartLocator::locate($part0);

    // Two parts is the smallest split set, so the split flag is on at that size
    expect($set->parts)->toBe([$part0, $part1])
        ->and($set->isSplit)->toBeTrue();
});

it('falls back to the given path when its directory cannot be read', function (): void {
    $path = $this->workingDirectory.DIRECTORY_SEPARATOR.'missing'.DIRECTORY_SEPARATOR.'news.000.xml';

    // Turn warnings into failures, so a scan result read as a list fails here rather than
    // warning. The scan's own `@`-suppressed warning is left alone: `@` lowers the
    // reporting level for its expression, which is how it is told apart here (the suite's
    // own level already masks E_WARNING, so the mask cannot be read for it)
    $ambient = error_reporting();
    set_error_handler(static function (int $severity, string $message) use ($ambient): bool {
        if (error_reporting() !== $ambient) {
            return false;
        }

        throw new ErrorException($message, 0, $severity);
    }, E_WARNING);

    try {
        $set = SplitExportPartLocator::locate($path);
    } finally {
        restore_error_handler();
    }

    expect($set->parts)->toBe([$path])
        ->and($set->isSplit)->toBeFalse()
        ->and($set->missingParts)->toBe([]);
});

it('falls back to the given path when no part file exists beside it', function (): void {
    // The directory is readable, but the named part was never written
    makeSplitPartFixture($this->workingDirectory, 'other.000.xml');
    $path = $this->workingDirectory.DIRECTORY_SEPARATOR.'news.001.xml';

    $set = SplitExportPartLocator::locate($path);

    // With no sibling found, the path is not reported as missing part `.000`
    expect($set->parts)->toBe([$path])
        ->and($set->isSplit)->toBeFalse()
        ->and($set->missingParts)->toBe([]);
});

it('skips a directory whose name matches the part pattern and keeps scanning', function (): void {
    // The directory sorts first, so a scan that stopped at it would find no part at all
    $directory = $this->workingDirectory.DIRECTORY_SEPARATOR.'news.000.xml';
    mkdir($directory);
    $part1 = makeSplitPartFixture($this->workingDirectory, 'news.001.xml');
    $part2 = makeSplitPartFixture($this->workingDirectory, 'news.002.xml');

    try {
        $set = SplitExportPartLocator::locate($part1);
    } finally {
        rmdir($directory);
    }

    // The matching directory is not a part, so `.000` is reported missing
    expect($set->parts)->toBe([$part1, $part2])
        ->and($set->missingParts)->toBe([0]);
});

it('ignores unrelated files in the directory', function (): void {
    $part0 = makeSplitPartFixture($this->workingDirectory, 'news.000.xml');
    $part1 = makeSplitPartFixture($this->workingDirectory, 'news.001.xml');
    makeSplitPartFixture($this->workingDirectory, 'unrelated.xml');
    makeSplitPartFixture($this->workingDirectory, 'news.01.xml');
    makeSplitPartFixture($this->workingDirectory, 'news.0000.xml');
    makeSplitPartFixture($this->workingDirectory, 'news.000.txt');

    $set = SplitExportPartLocator::locate($part0);

    expect($set->parts)->toBe([$part0, $part1]);
});
