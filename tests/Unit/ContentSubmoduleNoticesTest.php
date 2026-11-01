<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Tests\Support\ContentSubmoduleNotices;

beforeEach(function (): void {
    ContentSubmoduleNotices::reset();
});

afterEach(function (): void {
    ContentSubmoduleNotices::reset();
});

it('reports a message the first time and never again', function (): void {
    expect(ContentSubmoduleNotices::shouldReport('content/ arrived dirty'))->toBeTrue();
    expect(ContentSubmoduleNotices::shouldReport('content/ arrived dirty'))->toBeFalse();
    expect(ContentSubmoduleNotices::shouldReport('content/ arrived dirty'))->toBeFalse();
});

it('lets a genuinely different message through', function (): void {
    // Keyed by message rather than by kind, so a second observation is not
    // swallowed by the first.
    expect(ContentSubmoduleNotices::shouldReport('first'))->toBeTrue();
    expect(ContentSubmoduleNotices::shouldReport('second'))->toBeTrue();
    expect(ContentSubmoduleNotices::shouldReport('first'))->toBeFalse();
});

it('keeps one registry for the whole process, not one per composing class', function (): void {
    // The reason this lives on a class rather than on WordPressImportTestTrait:
    // a static declared on a trait is copied into every class that composes it,
    // so a registry kept there deduplicates per test class and still prints the
    // same line once per file — 11 times across the wired band.
    $trait = new class
    {
        use ContentSubmoduleNoticesProbe;
    };

    $other = new class
    {
        use ContentSubmoduleNoticesProbe;
    };

    expect($trait->probeTraitStatic())->toBeTrue();

    // A trait static would answer true here too, because this class has its own copy
    expect($other->probeTraitStatic())->toBeFalse();
});

it('forgets everything on reset', function (): void {
    ContentSubmoduleNotices::shouldReport('once');

    ContentSubmoduleNotices::reset();

    expect(ContentSubmoduleNotices::shouldReport('once'))->toBeTrue();
});

/**
 * Probe trait used to demonstrate that the shared registry is per process.
 *
 * Two anonymous classes compose this; if the registry lived on a trait, each
 * would carry its own copy and both would report the same message as new.
 */
trait ContentSubmoduleNoticesProbe
{
    /**
     * Ask the shared registry whether one fixed message is still unreported.
     *
     * @return bool True when this call is the first to see it
     */
    public function probeTraitStatic(): bool
    {
        return ContentSubmoduleNotices::shouldReport('shared-across-composing-classes');
    }
}
