<?php

declare(strict_types=1);

/**
 * Tests the paths of `StacheIndexUpdateToggle` that decide whether the importer may
 * pause Stache index updates: the support probe, its memo, and the re-enable check.
 *
 * `tests/Feature/StacheIndexUpdateToggleTest.php` pins the installed `statamic/cms`
 * contract under the host app, which the package gate never boots. This file swaps a
 * `Stache` subclass behind the facade, so each way the probe can refuse is exercised in
 * the gate, along with the state the probe restores afterwards.
 *
 * @command  php vendor/bin/pest tests/Integration/StacheIndexUpdateToggleScopeTest.php
 */

use Statamic\Facades\Stache as StacheFacade;
use Statamic\Stache\Stache;
use UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities\StacheIndexUpdateToggle;
use UAMSWeb\WordPressImporter\Tests\StatamicTestCase;

uses(StatamicTestCase::class);

/**
 * A Stache whose flag methods can be made to misbehave, and which counts disables.
 */
class StacheIndexUpdateToggleProbeStache extends Stache
{
    /**
     * How many times disableUpdatingIndexes() was called.
     */
    public int $disables = 0;

    /**
     * Configure how the flag methods behave.
     *
     * @param  bool  $honorsDisable  Whether disableUpdatingIndexes() clears the flag
     * @param  ?bool  $reports  A fixed answer for shouldUpdateIndexes(), or null for the flag
     * @param  bool  $throwsOnDisable  Whether disableUpdatingIndexes() throws before clearing
     * @param  bool  $throwsAfterDisable  Whether it throws after clearing the flag
     */
    public function __construct(
        private readonly bool $honorsDisable = true,
        private readonly ?bool $reports = null,
        private readonly bool $throwsOnDisable = false,
        private readonly bool $throwsAfterDisable = false
    ) {
        parent::__construct();
    }

    /**
     * Count the call, then clear the flag, ignore the call, or throw, as configured.
     */
    public function disableUpdatingIndexes(): static
    {
        $this->disables++;

        if ($this->throwsOnDisable) {
            throw new RuntimeException('disable failed');
        }

        if ($this->honorsDisable) {
            parent::disableUpdatingIndexes();
        }

        if ($this->throwsAfterDisable && $this->disables === 1) {
            throw new RuntimeException('disable failed after clearing the flag');
        }

        return $this;
    }

    /**
     * Report the configured answer, or the real flag.
     */
    public function shouldUpdateIndexes(): bool
    {
        return $this->reports ?? (bool) parent::shouldUpdateIndexes();
    }

    /**
     * The protected flag itself, whatever shouldUpdateIndexes() reports.
     */
    public function flag(): bool
    {
        return (bool) $this->updateIndexes;
    }
}

/**
 * Put a Stache behind the facade and forget any memoized probe result.
 */
function stacheToggleUse(object $stache): void
{
    StacheFacade::swap($stache);
    StacheIndexUpdateToggle::flushSupportCache();
}

afterEach(function (): void {
    StacheIndexUpdateToggle::flushSupportCache();
});

it('reports support for a Stache that round-trips the flag', function (): void {
    stacheToggleUse(new StacheIndexUpdateToggleProbeStache);

    expect(StacheIndexUpdateToggle::isSupported())->toBeTrue();
});

it('refuses support when the facade holds something other than a Stache', function (): void {
    stacheToggleUse(new stdClass);

    expect(StacheIndexUpdateToggle::isSupported())->toBeFalse();
});

it('refuses support when disabling does not take effect', function (): void {
    stacheToggleUse(new StacheIndexUpdateToggleProbeStache(honorsDisable: false));

    expect(StacheIndexUpdateToggle::isSupported())->toBeFalse();
});

it('refuses support when the re-enable is not visible through the public API', function (): void {
    // Always reporting false passes the disable check and fails the re-enable check
    stacheToggleUse(new StacheIndexUpdateToggleProbeStache(reports: false));

    expect(StacheIndexUpdateToggle::isSupported())->toBeFalse();
});

it('refuses support when disabling throws', function (): void {
    stacheToggleUse(new StacheIndexUpdateToggleProbeStache(throwsOnDisable: true));

    expect(StacheIndexUpdateToggle::isSupported())->toBeFalse();
});

it('leaves an enabled flag enabled after the probe', function (): void {
    $stache = new StacheIndexUpdateToggleProbeStache;
    stacheToggleUse($stache);

    StacheIndexUpdateToggle::isSupported();

    expect($stache->flag())->toBeTrue();
});

it('re-enables a flag the probe left cleared when it failed', function (): void {
    // The first disable clears the flag and then throws, so the probe exits with the
    // flag off; the finally must turn back on what was on before
    $stache = new StacheIndexUpdateToggleProbeStache(throwsAfterDisable: true);
    stacheToggleUse($stache);

    expect(StacheIndexUpdateToggle::isSupported())->toBeFalse()
        ->and($stache->flag())->toBeTrue();
});

it('leaves a disabled flag disabled after the probe', function (): void {
    $stache = new StacheIndexUpdateToggleProbeStache;
    $stache->disableUpdatingIndexes();
    stacheToggleUse($stache);

    StacheIndexUpdateToggle::isSupported();

    // The probe disabled it once itself and once more to restore it
    expect($stache->flag())->toBeFalse()
        ->and($stache->disables)->toBe(3);
});

it('restores the state the public API reported, even after returning early', function (): void {
    // The probe reads the starting state through shouldUpdateIndexes(), which this
    // Stache reports as false, and returns from inside the try once the re-enable
    // check fails; the finally still puts back what it read
    $stache = new StacheIndexUpdateToggleProbeStache(reports: false);
    stacheToggleUse($stache);

    StacheIndexUpdateToggle::isSupported();

    expect($stache->flag())->toBeFalse()
        ->and($stache->disables)->toBe(2);
});

it('remembers the probe result until the cache is flushed', function (): void {
    stacheToggleUse(new StacheIndexUpdateToggleProbeStache);

    expect(StacheIndexUpdateToggle::isSupported())->toBeTrue();

    // A Stache that would fail the probe does not change the remembered answer
    StacheFacade::swap(new StacheIndexUpdateToggleProbeStache(honorsDisable: false));

    expect(StacheIndexUpdateToggle::isSupported())->toBeTrue();

    StacheIndexUpdateToggle::flushSupportCache();

    expect(StacheIndexUpdateToggle::isSupported())->toBeFalse();
});

it('remembers a refusal as well as a success', function (): void {
    stacheToggleUse(new stdClass);

    expect(StacheIndexUpdateToggle::isSupported())->toBeFalse();

    StacheFacade::swap(new StacheIndexUpdateToggleProbeStache);

    expect(StacheIndexUpdateToggle::isSupported())->toBeFalse();
});

it('disables through the public API and re-enables, confirming it took effect', function (): void {
    $stache = new StacheIndexUpdateToggleProbeStache;
    stacheToggleUse($stache);

    StacheIndexUpdateToggle::disable();
    $disabled = $stache->flag();

    expect($disabled)->toBeFalse()
        ->and(StacheIndexUpdateToggle::enable())->toBeTrue()
        ->and($stache->flag())->toBeTrue();
});

it('reports a failed re-enable when the facade holds something other than a Stache', function (): void {
    stacheToggleUse(new stdClass);

    expect(StacheIndexUpdateToggle::enable())->toBeFalse();
});

it('reports a failed re-enable when the public API still reads disabled', function (): void {
    $stache = new StacheIndexUpdateToggleProbeStache(reports: false);
    stacheToggleUse($stache);

    // The flag itself is written; only the confirmation fails
    expect(StacheIndexUpdateToggle::enable())->toBeFalse()
        ->and($stache->flag())->toBeTrue();
});
