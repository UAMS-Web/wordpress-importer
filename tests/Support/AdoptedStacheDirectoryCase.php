<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Tests\Support;

use UAMSWeb\Aether\WordPressImporter\Tests\StatamicTestCase;

/**
 * A case that plants files in this process's fake Stache directory before the
 * `setUp()` chain runs (issue wordpress-importer-archive#832).
 *
 * Planting happens ahead of `parent::setUp()` so the state under test exists by the
 * time `StatamicTestCase` prepares the directory — reproducing a run killed mid-test
 * whose pid the operating system has since reissued. A Pest `beforeEach` cannot do
 * this: it runs after `setUp()` has already prepared the directory, far too late to
 * observe what it did.
 *
 * Lives here rather than beside its test for a reason narrower than it first
 * looks. Pest gates `uses()` on `class_exists()` and throws
 * `TestCaseClassOrTraitNotFound` when it fails — but a class declared further down
 * a file is not automatically absent at that point. PHP early-binds a class with no
 * unresolved parent, so one of those would be available. This one **extends**
 * `StatamicTestCase`, which makes it late-bound: it does not exist until its own
 * declaration executes, long after the `uses()` at the top of the file has run.
 */
abstract class AdoptedStacheDirectoryCase extends StatamicTestCase
{
    /**
     * @var string The top-level file planted before setUp, or '' if none was
     */
    public static string $plantedFile = '';

    /**
     * @var string The nested file planted before setUp, or '' if none was
     */
    public static string $plantedNested = '';

    /**
     * @var string A file OUTSIDE the directory, reachable only through a planted symlink
     */
    public static string $plantedOutside = '';

    /**
     * @var string The symlink planted inside the directory
     */
    public static string $plantedLink = '';

    /**
     * @var bool Whether the three planted FILES were verified on disk before `setUp()` ran
     */
    public static bool $plantsLanded = false;

    /**
     * @var bool Whether the planted SYMLINK was created, which needs privilege on Windows
     */
    public static bool $linkLanded = false;

    /**
     * @var ?string The outside directory this case created, if it created one
     */
    private ?string $plantedOutsideDirectory = null;

    /**
     * Plant leftovers, then boot as normal.
     */
    protected function setUp(): void
    {
        $directory = \dirname(__DIR__).'/__fixtures__/dev-null-'.\getmypid();

        if (! \is_dir($directory.'/collections')) {
            \mkdir($directory.'/collections', 0755, true);
        }

        self::$plantedFile = $directory.'/left-by-a-killed-run.md';
        self::$plantedNested = $directory.'/collections/stale-entry.md';

        \file_put_contents(self::$plantedFile, 'stale');
        \file_put_contents(self::$plantedNested, 'stale');

        // ---
        // A symlink out of the directory, and a file only it can reach. `is_dir()`
        // is true for a link to a directory, so an emptying that tests that first
        // recurses THROUGH the link and deletes what it points at.
        // ---
        $outside = \dirname(__DIR__).'/__fixtures__/dev-null-outside-'.\getmypid();

        if (! \is_dir($outside)) {
            \mkdir($outside, 0755, true);
        }

        $this->plantedOutsideDirectory = $outside;
        self::$plantedOutside = $outside.'/must-survive.md';
        self::$plantedLink = $directory.'/link-to-outside';

        \file_put_contents(self::$plantedOutside, 'precious');

        if (! \is_link(self::$plantedLink)) {
            // ---
            // The attempt is made on every platform rather than gated on one, so a Windows
            // machine WITH Developer Mode still gets the coverage. `@` alone is not enough:
            // PHPUnit installs its own error handler, which promotes the suppressed warning
            // into a test warning on every test in the file, including the ones that never
            // touch a link. The handler below discards it and is restored immediately.
            // ---
            \set_error_handler(static fn (): bool => true);

            try {
                \symlink($outside, self::$plantedLink);
            } finally {
                \restore_error_handler();
            }
        }

        // ---
        // Record that the plants actually landed. Every assertion in the test file
        // is an ABSENCE — `file_exists(...)` is false — and an absence is what a
        // plant that silently failed also produces. Without this the fixture could
        // defuse itself and the file would read as green while pinning nothing.
        // ---
        self::$plantsLanded = \is_file(self::$plantedFile)
            && \is_file(self::$plantedNested)
            && \is_file(self::$plantedOutside);

        // ---
        // The symlink is tracked separately because it is the one plant that can fail
        // for a reason that is not a defect: creating a symlink on Windows needs
        // elevation or Developer Mode, and `symlink()` returns false with
        // `Permission denied` without it. Folding it into `$plantsLanded` made all
        // three tests in the file fail there, including the two that never touch a
        // link -- so the two that CAN run are kept, and only the link test stands down.
        // ---
        self::$linkLanded = \is_link(self::$plantedLink);

        parent::setUp();
    }

    /**
     * Remove the outside directory this case planted.
     *
     * `StatamicTestCase` removes the per-process Stache directory, but the target
     * of the planted symlink sits deliberately outside it and is nobody else's to
     * collect.
     */
    protected function tearDown(): void
    {
        $outside = $this->plantedOutsideDirectory;
        $this->plantedOutsideDirectory = null;

        parent::tearDown();

        if ($outside === null) {
            return;
        }

        @\unlink($outside.'/must-survive.md');
        @\rmdir($outside);
    }
}
