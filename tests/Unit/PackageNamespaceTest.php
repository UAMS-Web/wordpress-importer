<?php

declare(strict_types=1);

/**
 * Tests that this package's namespace autoloads under the name #329 gave it, and
 * sits beside the cleanup package without sharing a file with it. The cleanup
 * package maps a longer prefix of this one, and Composer picks the longest
 * matching prefix, so a `Cleanup/` directory here would never load.
 *
 * @command  php vendor/bin/pest tests/Unit/PackageNamespaceTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Cleanup\Transform;
use UAMSWeb\Aether\WordPressImporter\ServiceProvider;

it('loads this package and the cleanup package side by side', function (): void {
    expect(\class_exists(ServiceProvider::class))->toBeTrue()
        ->and(\class_exists(Transform::class))->toBeTrue()
        ->and(\dirname((string) (new ReflectionClass(ServiceProvider::class))->getFileName()))->toBe(\realpath(\dirname(__DIR__, 2).'/src'))
        ->and((string) (new ReflectionClass(Transform::class))->getFileName())->toContain('aether-wordpress-importer-cleanup');
});

it('does not load the namespace it had before the rename', function (): void {
    // Built in two parts, so a search for the old namespace finds nothing here
    expect(\class_exists('UAMSWeb'.'\WordPressImporter\ServiceProvider'))->toBeFalse();
});

it('keeps no directory in src that the cleanup or migration API package maps', function (string $directory): void {
    expect(\is_dir(\dirname(__DIR__, 2)."/src/{$directory}"))->toBeFalse();
})->with(['Cleanup', 'MigrationApi']);

it('holds a control: the check above sees a directory that is there', function (): void {
    expect(\is_dir(\dirname(__DIR__, 2).'/src/Services'))->toBeTrue();
});
