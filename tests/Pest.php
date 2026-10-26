<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Pest uses PHPUnit\Framework\TestCase by default. Class-based PHPUnit tests
| in this directory are converted with `vendor/bin/pest --drift`.
|
| The `tests/Feature` tier is host-app-bound: it runs only inside the consuming
| Statamic app (against its `Tests\TestCase`). Files that need the import helpers
| extend `WordPressImportTestCase`, which uses WordPressImportTestTrait. There is
| deliberately NO `uses(...)->in('Feature')` binding here: the host app loads its
| OWN `tests/Pest.php`, not this one, so each Feature file declares its own
| `uses(Tests\TestCase::class)` or `uses(WordPressImportTestCase::class)`. That
| per-file binding is what actually applies when the tests run in the host, and
| it is what `pest-plugin-phpstan` reads to type each file's `$this`.
|
| A global binding used to be declared here for the old `mrpunyapal/peststan`
| analyser. It is gone because `pest-plugin-phpstan` reads the per-file `uses()`
| first and then reports every local `uses()` a global binding duplicates as
| `pest.config.redundantLocalUse` — advice that is wrong for this package, since
| removing those local calls would strip the base class in the host-app run.
|
| The helpers reach analysis through `WordPressImportTestCase` itself: a real
| class using the trait, rather than a trait composed by `uses()`, which PHPStan
| cannot see (wordpress-importer-archive#1031).
|
*/
