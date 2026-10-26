<?php

declare(strict_types=1);

use UAMSWeb\WordPressImporter\Tests\Support\PrivateMethodUsage;

/**
 * Guards the class of residue wordpress-importer-archive#533 / wordpress-importer-archive#740 removed, rather than its instances.
 *
 * A private helper whose last caller goes away is invisible to every automated
 * check this repo runs: PHPStan at `level: max` does not report it, Pint does
 * not look for it, and the suite passes because the code never executes. So it
 * accumulates silently — wordpress-importer-archive#740 removed **521 lines across 19 members** of
 * `WordPressImportTestTrait`, all of which had sat green through every gate run
 * since their last caller went away, and wordpress-importer-archive#747 is the twentieth, found only
 * because the change that orphaned it happened to be reviewed by someone who
 * checked.
 *
 * `tests/Unit/ContentPathGuardTest.php` (wordpress-importer-archive#725) is the precedent, and its
 * argument holds here: *a grep in a checklist is only true on the day someone
 * runs it.* This asserts it on every run of the standalone gate, which is the
 * tier that can read source without booting anything — and so is the only tier
 * that can see `tests/Feature`, which its own CI never executes.
 *
 * **`protected` trait methods are judged too (wordpress-importer-archive#1407).** The Feature helpers
 * became `protected` when the trait moved onto `WordPressImportTestCase` (wordpress-importer-archive#1031),
 * so a guard limited to `private` stopped seeing them, and a private helper
 * reached only through an orphaned protected one stayed green as well. A
 * protected method that overrides a test-case base-class method, such as
 * `setUp()`, is called by the framework and named nowhere here, so it is
 * skipped; {@see PrivateMethodUsage} states the rule.
 *
 * @command  php vendor/bin/pest tests/Unit/UncalledPrivateHelperGuardTest.php
 */
it('leaves no private or protected test helper without a caller', function (): void {
    $orphans = PrivateMethodUsage::orphans(
        PrivateMethodUsage::readTree(\dirname(__DIR__))
    );

    $described = \array_map(
        static fn (array $orphan): string => $orphan['file'].'::'.$orphan['name'].'()',
        $orphans
    );

    expect($described)->toBe(
        [],
        "Nothing reaches these private or protected trait methods. Delete them.\n\n"
        .'If one is genuinely reachable the detector is wrong and should be fixed rather than '
        .'worked around: it already understands calls, differing letter case, callable arrays, '
        .'string names, and chains through other live helpers, and '
        ."tests/Unit/PrivateMethodUsageTest.php pins each. Add the case there first.\n\n  "
        .\implode("\n  ", $described)
    );
});
