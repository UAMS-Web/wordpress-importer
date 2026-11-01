<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Tests\Feature;

use Tests\TestCase;

/**
 * Base class for the `tests/Feature` files that use the import helpers.
 *
 * A file opts in with `uses(WordPressImportTestCase::class)`, adding
 * `RefreshDatabase::class` or any other trait after it. `Tests\TestCase` is
 * the host app's own base class, so like everything in `tests/Feature` this
 * runs only inside the booted `uams-statamic` app (see `tests/CLAUDE.md`).
 *
 * **Why a class rather than `uses(TestCase::class, WordPressImportTestTrait::class)`.**
 * Pest composes a trait passed to `uses()` onto the generated test class at run
 * time, and PHPStan never sees that composition: the trait read as "used zero
 * times and is not analysed" (`trait.unused`), so nothing checked its body. The
 * analyser stub carried a `use` of the trait to stand in for it, which worked
 * until it did not, through three versions of `pest-plugin-phpstan` (wordpress-importer-archive#1031). A
 * real class composing the trait is the same hierarchy at run time and in
 * analysis, so neither depends on how the plugin models traits.
 *
 * The trait's helpers that test closures call are `protected` for this reason:
 * a private member of a trait used here is private to this class, and the
 * generated test class Pest binds each closure to cannot call it.
 */
abstract class WordPressImportTestCase extends TestCase
{
    use WordPressImportTestTrait;
}
