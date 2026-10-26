<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Tests\Support\ExportsCorpus;

/**
 * Tests where the corpus tests look for the private export corpus (wordpress-importer-archive#1426).
 *
 * Each test sets `WORDPRESS_IMPORTER_EXPORTS` itself and restores whatever the
 * process started with, so a run with the variable set still passes.
 *
 * @command  vendor/bin/pest --testsuite=Unit tests/Unit/ExportsCorpusTest.php
 */
beforeEach(function (): void {
    $this->original = \getenv(ExportsCorpus::ENV);
    $this->clone = \sys_get_temp_dir().'/exports-corpus-'.\bin2hex(\random_bytes(4));

    \mkdir($this->clone.'/WordPress', 0777, true);
});

afterEach(function (): void {
    // Put the variable back as it was
    \putenv(\is_string($this->original) ? ExportsCorpus::ENV.'='.$this->original : ExportsCorpus::ENV);

    \rmdir($this->clone.'/WordPress');
    \rmdir($this->clone);
});

it('returns null when the variable is unset or empty', function (string $assignment): void {
    \putenv($assignment);

    expect(ExportsCorpus::path())->toBeNull()
        ->and(ExportsCorpus::path('WordPress'))->toBeNull();
})->with([
    'unset' => [ExportsCorpus::ENV],
    'empty' => [ExportsCorpus::ENV.'='],
    'whitespace' => [ExportsCorpus::ENV.'=  '],
]);

it('returns the clone and paths under it when the variable names a directory', function (): void {
    \putenv(ExportsCorpus::ENV.'='.$this->clone.'/');

    expect(ExportsCorpus::path())->toBe($this->clone)
        ->and(ExportsCorpus::path('WordPress'))->toBe($this->clone.'/WordPress')
        ->and(ExportsCorpus::path('/WordPress/x.xml'))->toBe($this->clone.'/WordPress/x.xml');
});

it('throws, naming the variable, when it is set to something that is not a directory', function (): void {
    \putenv(ExportsCorpus::ENV.'='.$this->clone.'/missing');

    expect(fn (): ?string => ExportsCorpus::path('WordPress'))
        ->toThrow(RuntimeException::class, ExportsCorpus::ENV.' is set to '.$this->clone.'/missing, which is not a directory.');
});

it('names the variable and the corpus repository in its skip message', function (): void {
    expect(ExportsCorpus::SKIP_MESSAGE)->toContain(ExportsCorpus::ENV)
        ->toContain('UAMS-Web/wordpress-importer-exports');
});
