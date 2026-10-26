---
name: pest-testing
description: >-
  How to write tests with Pest. Trigger whenever any test is being written, edited, fixed,
  reviewed or refactored, including fixing tests that broke after a code change, adding
  assertions or datasets, converting PHPUnit to Pest, TDD workflows, and deciding which suite or
  process a new test belongs in. Covers test()/it()/expect() syntax, datasets, architecture
  tests, which expectations take a failure message, and how to read a mutation run and record a
  survivor. In a Laravel application also mocking, RefreshDatabase, Livewire component tests,
  browser testing (visit/click/fill) and smoke testing pages for JS errors. In a WordPress plugin
  also the Unit process that runs with no WordPress, the Feature process over wp-phpunit,
  dispatching every REST test through rest_do_request(), the typed helpers that keep tests
  analyzable, and the global state WordPress leaks between tests. Do not use for factories,
  seeders, migrations, controllers, models, or non-test PHP code.
license: MIT
metadata:
  author: laravel
---
<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/pest-testing/SKILL.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): pest.
-->
<!-- cspell:ignore killable Livewire nikic Stache viewports -->

# Pest Testing

All tests are written with Pest. Read the installed Pest major from `composer.lock` before reaching for a feature: the repositories that share this skill sit on different majors, and a flag or helper one major has, another lacks.

This package has no `artisan`. Run `composer test` (`pest --testsuite=Unit,Integration`); a bare `vendor/bin/pest` picks up `tests/Feature/`, which runs only inside the host application, and errors. There is no generator command: create the file.

## Documentation

Use `search-docs` for detailed Pest patterns and documentation for the installed version.

## Basic Usage

### Creating Tests

In a Laravel application, use `php artisan make:test --pest {name}`.

The `{name}` argument should include only the path and test name, but should not include the test suite.
- Incorrect: `php artisan make:test --pest Feature/SomeFeatureTest` will generate `tests/Feature/Feature/SomeFeatureTest.php`
- Correct: `php artisan make:test --pest SomeControllerTest` will generate `tests/Feature/SomeControllerTest.php`
- Incorrect: `php artisan make:test --pest --unit Unit/SomeServiceTest` will generate `tests/Unit/Unit/SomeServiceTest.php`
- Correct: `php artisan make:test --pest --unit SomeServiceTest` will generate `tests/Unit/SomeServiceTest.php`

Name files `*Test.php`. The testsuite suffix is `Test.php`, so a file named otherwise is silently never run, which reads exactly like a passing suite.

### Test Organization

- Do NOT remove tests without approval; these are core application code.

- Unit/Feature tests: `tests/Feature` and `tests/Unit` directories.
- Browser tests: `tests/Browser/` directory.

### Basic Test Structure

Pest supports both `test()` and `it()` functions. Before writing new tests, check existing test files in the same directory to match the project's convention. Use `test()` if existing tests use `test()`, or `it()` if they use `it()`. Match what is already there in the rest of the file too: `function (): void` closures, `expect()`, and `declare(strict_types=1);`.

<!-- Basic Pest Test Example -->
```php
it('is true', function () {
    expect(true)->toBeTrue();
});
```

Helper functions declared at the top of a test file are global. Keep their names specific (`indexAsSuperAdmin`, `requestWithSecret`); a generic one collides across files in the same process.

### Running Tests

In a Laravel application:

- Run minimal tests with filter before finalizing: `php artisan test --compact --filter=testName`.
- Run all tests: `php artisan test --compact`.
- Run file: `php artisan test --compact tests/Feature/ExampleTest.php`.

## Assertions

Use specific assertions (`assertSuccessful()`, `assertNotFound()`) instead of `assertStatus()`:

<!-- Pest Response Assertion -->
```php
it('returns all', function () {
    $this->postJson('/api/docs', [])->assertSuccessful();
});
```

| Use | Instead of |
|-----|------------|
| `assertSuccessful()` | `assertStatus(200)` |
| `assertNotFound()` | `assertStatus(404)` |
| `assertForbidden()` | `assertStatus(403)` |

## Not every expectation takes a failure message

`toBe()`, `toBeTrue()`, `toBeFalse()` and `toBeGreaterThan()` accept a message as their last argument. **`toContain()` and `toHaveKey()` do not**, and passing one does not error; it silently changes what is asserted:

- **`toContain(...$values)` is variadic.** `expect($haystack)->not->toContain('nf_sub', 'why this matters')` reads as *"contains neither of these"*, which is true whenever the message string is absent. It **passes with `nf_sub` sitting in the array.**
- **`toHaveKey($key, $value)`** takes an expected *value* second. `expect($map)->toHaveKey($key, 'the API did not serve it')` asserts the value equals that sentence.

Both were written in `uamswp-migration-api` and both were caught only by a negative control: the first turned a guard into a no-op, the second failed loudly for a reason that looked like a data bug. When an assertion needs a message, reach for a form that has one:

```php
// Inert: the message is read as a second value to look for.
expect($postTypes)->not->toContain($type, 'submission storage, not content');

// Bites.
expect(in_array($type, $postTypes, true))->toBeFalse('submission storage, not content');
```

This is the specific reason the [mutation discipline](#mutation-testing) matters for *guards* as much as for production code: a guard that has quietly stopped asserting is green forever, and looks exactly like a guard with nothing to complain about.

## Mocking

Import mock function before use: `use function Pest\Laravel\mock;`

### A mocked facade is still installed during `afterEach`: never clean up through one

Pest runs a file's `afterEach` **before** `parent::tearDown()`, so a facade you swapped inside the test is still the facade root while your cleanup runs. Cleanup that calls back through it silently does nothing, and the test still reports green:

```php
// The race test mocks isDirectory() to simulate a stale check…
File::partialMock()->shouldReceive('isDirectory')->with($dir)->andReturnFalse();

// …and Filesystem::deleteDirectory() OPENS with its own isDirectory() guard,
// so this no-ops and the directory survives. Fourteen accumulated before
// anyone noticed (UAMS-Web/uams-statamic#1853).
afterEach(fn () => File::deleteDirectory($dir));
```

Clean up through a real instance instead, which no mock can intercept:

```php
afterEach(function () use (&$dirs): void {
    $files = new Illuminate\Filesystem\Filesystem;   // NOT the File facade

    foreach ($dirs as $dir) {
        $files->deleteDirectory($dir);
    }
});
```

Two things make this hard to catch, so assume it rather than wait for a symptom: `storage/framework/testing/.gitignore` is `*`, so leaked fixtures never show in `git status`; and Mockery's `close()` runs first in Pest's chained `afterEach`, so an exhausted `->once()` expectation does **not** fail the test when the cleanup re-enters it. **Whenever a test both mocks a facade and creates files, verify the cleanup by listing the directory before and after a run**; green is not evidence here.

## Datasets

Use datasets for repetitive tests (validation rules, etc.):

<!-- Pest Dataset Example -->
```php
it('has emails', function (string $email) {
    expect($email)->not->toBeEmpty();
})->with([
    'james' => 'james@laravel.com',
    'taylor' => 'taylor@laravel.com',
]);
```

## Pest Features

### Architecture Testing

Architecture tests enforce code conventions:

<!-- Architecture Test Example -->
```php
arch('controllers')
    ->expect('App\Http\Controllers')
    ->toExtendNothing()
    ->toHaveSuffix('Controller');
```

### Test Sharding

Split tests across parallel processes for faster CI runs.

### Browser Testing

Browser tests run in real browsers for full integration testing:

- Browser tests live in `tests/Browser/`.
- Use Laravel features like `Event::fake()`, `assertAuthenticated()`, and model factories.
- Use `RefreshDatabase` for clean state per test.
- Interact with page: click, type, scroll, select, submit, drag-and-drop, touch gestures.
- Test on multiple browsers (Chrome, Firefox, Safari) if requested.
- Test on different devices/viewports (iPhone 14 Pro, tablets) if requested.
- Switch color schemes (light/dark mode) when appropriate.
- Take screenshots or pause tests for debugging.

<!-- Pest Browser Test Example -->
```php
it('may reset the password', function () {
    Notification::fake();

    $this->actingAs(User::factory()->create());

    $page = visit('/sign-in');

    $page->assertSee('Sign In')
        ->assertNoJavaScriptErrors()
        ->click('Forgot Password?')
        ->fill('email', 'nuno@laravel.com')
        ->click('Send Reset Link')
        ->assertSee('We have emailed your password reset link!');

    Notification::assertSent(ResetPassword::class);
});
```

### Smoke Testing

Quickly validate multiple pages have no JavaScript errors:

<!-- Pest Smoke Testing Example -->
```php
$pages = visit(['/', '/about', '/contact']);

$pages->assertNoJavaScriptErrors()->assertNoConsoleLogs();
```

### Visual Regression Testing

Capture and compare screenshots to detect visual changes.

## Tests are under static analysis too

Where `phpstan.neon.dist` lists `tests` among its paths, a broken mock, a wrong-typed fixture or an assertion calling a method that no longer exists is a real analysis failure, and a test-only diff still has to pass static analysis.

## The suite runs on the deployment target

Run the suite on the PHP version production runs. That is not a substitute for `phpVersion` in `phpstan.neon.dist`, which remains the only thing that reports a construct the older PHP cannot *parse*: PHPStan parses with `nikic/php-parser` regardless of the interpreter, and a parse error never reaches the test runner. Keep both.

## Parallel runs: what a test may share

`pest --parallel` runs several worker processes against one filesystem. A test that passes alone can fail there in two ways, and the isolation convention is the answer to each:

- **Shared mutable state.** A test that writes a fixed path, faked disk or shared cache entry races with the same test in another worker, or in a second run on the same machine. Make the path unique per process (the worker token from `TEST_TOKEN` plus the process id, so two concurrent runs cannot share a worker index), or quarantine the file into a `serial` group that runs in one process after the parallel band. Prefer the unique path where the cause is known, and keep the `serial` group small and named.
- **Global symbols in namespace-less files.** Pest files carry no namespace, so a top-level `const` or `function` is global, and a bare `use X;` names a class that is already global. A name declared in two files fatals the moment both load into one worker, and the no-op import is a compile-time warning that a framework error handler may promote to an exception before the first test runs. Both pass when the file runs alone.

[`scripts/check-global-test-symbols.php`](scripts/check-global-test-symbols.php) enforces the second. `php .claude/skills/pest-testing/scripts/check-global-test-symbols.php tests` reads every PHP file under the given paths as tokens (so a match in a comment or string is ignored), prints how many files it read before any finding, and exits 0 clean, 1 on a finding, 2 when it could not see (a missing path, or no PHP file). Pass the whole suite in one run, because the redeclaration rule compares across files. Function names are compared case-insensitively, as PHP does (`helper()` in one file and `Helper()` in another collide, and the message names both spellings), while constant names stay case-sensitive. The contents of an unnamed `namespace { }` block count as global, so a function, constant or import declared there is read like one in a file with no namespace. The `code-quality` CI template's `pest` job runs it, and marks the step optional: a repository that never runs `--parallel` deletes it, since both defects show on the first serial run.

### Which rules are shared, and which stay with the application

`uams-statamic` keeps a larger guard, `scripts/check-parallel-safe-tests.php`, with five rules. Each was classified for whether it holds outside that application. Read at `UAMS-Web/uams-statamic` `main`, commit `e823826b8a78e002d896589c8e2c315d659f4153`, file SHA-256 `0bec900bab07c637c05a83018842049ccc578ff950d74d755fe02df03597236f` (35,744 bytes), 2026-10-15.

| Rule | What it flags | Verdict | Reason |
| --- | --- | --- | --- |
| A | `Storage::fake(` outside a `serial` file | application-specific | Laravel only, and its premise does not hold on the installed version: `laravel/framework` v13.35.0 (the version `uams-statamic`'s lock pins) appends `_test_{token}` to a faked disk root when `TEST_TOKEN` is set, so workers do not share the root. What remains is two concurrent runs sharing a worker index, which is that application's `ThrowawayRoot` concern. |
| B | `storage_path('...framework/testing...')` with no uniqueness token | application-specific | Hardcodes Laravel's `framework/testing` layout and recognizes that application's `ThrowawayRoot` helper by class name. |
| C | A global `const` or `function` declared in more than one file | shared | Pure PHP token analysis, no framework or layout assumption. Shipped. |
| D | A single-segment `use X;` in a file with no named namespace | shared | Pure PHP token analysis. The warning is PHP's; whether it becomes fatal depends on the error handler, which is why it is a parallel-run defect in Laravel. Shipped. |
| E | `assert()` or `\assert()` anywhere under `tests/` | application-specific | A typing policy rather than parallel safety, and its remedy names that application's `TypeNarrowing` helpers. A repository that wants the policy writes its own rule. |

**Decision: ship C and D, keep A, B and E with the application.** The shipped script carries a test per rule, a negative case for each neighboring shape it must leave alone, and a clean control. Each rule was also exercised against a real tree: the script read all 1,137 files of the `uams-statamic` suite at the commit above with no finding, then reported both rules on a copy with one duplicate function and one no-op import planted.

Of the three consumers, only `uams-statamic` runs `--parallel` (`wordpress-importer` and `uamswp-migration-api` run their suites serially), so the shared script has one parallel consumer today. Adopting it there means replacing rules C and D in its private guard, which is that repository's change. Revisit the split when a second repository adopts `--parallel` and meets a shared-state defect that Rule A or B would have caught outside Laravel.

## Mutation testing

Pest's `--mutate` mutates the source and requires the suite to notice. **A surviving mutant is a false-green test**: the code changed and nothing failed. The coverage driver it needs, the invocation forms that silently do nothing, and the checks that show a run executed at all are in [`pcov-setup`](../pcov-setup/SKILL.md); read that before trusting any score, including a perfect one. This section is how to read a run that did execute, and how to record what you decide about each survivor.

**The criterion belongs to [`adversarial-review`](../rule-adversarial-review/SKILL.md)**: no unexplained survivors, never a score. It is not restated here.

- **Every survivor ends in one of three states: killed, deleted, or annotated.** A survivor is a test to write, dead code to delete (mutation testing is the best dead-code finder you have), or an equivalent mutant annotated with the reason it cannot be killed. Kill, delete, annotate stay exhaustive; there is no fourth disposition where a survivor is simply left unexplained. If a survivor can be neither killed nor safely annotated (the node it would protect has no start line to stand above without also suppressing a sibling), restructure so it does, or delete the code the mutant targets.
- **Don't chase a literal 100%.** Every *killable* mutant dead is the goal; the last few percent are usually **equivalent mutants** (no input distinguishes them) whose only "kill" is an annotation in production source or deleting a defensive guard. Categorize every survivor; most fall into four buckets:
  1. **Real coverage gap** → add a dataset row that exercises that branch (an untested boundary `>=`, an untested fallback, positional vs named params, a plural/singular branch).
  2. **Inert data-table mutant** (`RemoveArrayItem` over a big alias/lookup map) → under `--covered-only` these are spawned simply because a legitimate test *executed* the data-returning method; killing each needs one near-circular assertion per entry. Default: accept and document the rationale in the test's docblock, unless the goal is 0 survivors, in which case generate an exhaustive dataset (see [`phpunit-to-pest`](../phpunit-to-pest/SKILL.md)).
  3. **Dead code** (the value is never read: a computed-then-`unset` local) → delete the dead code.
  4. **Equivalent mutant** (no input can change observable output: a `(int)`/`(string)` cast on a value already typed `int`/`string`, an unreachable defensive guard, a default that a clamp maps back to itself) → either remove the redundancy *legitimately* (tighten an `int|string` array shape to `array{divisor: positive-int, …}` so the casts become genuinely removable and static analysis stays green) or, for code that must stay defensive, annotate it as below. Judge each one; killing an equivalent mutant means contorting a test to assert something that cannot differ.
- **Record the judgment on the line rather than carrying it in your head. Annotate an accepted survivor standalone, on the line above the node, never trailing on the node's own line.** Bare `// @pest-mutate-ignore` skips every mutator on that node; `// @pest-mutate-ignore:MutatorName,OtherMutator` scopes it to the ones named. Put the reason on its own line above the annotation, never after the mutator name on the same line: the plugin splits the tail on `,` and compares each part to a mutator name with `===`, so any prose after the mutator makes the whole annotation match nothing and the survivor stays uncounted. The mutator name is not optional: a bare marker blanket-suppresses the node and every child, which raises the apparent result rather than showing up as a survivor.
- **Two mechanisms make a standalone annotation work, and neither reaches a trailing one.** A line map, keyed on the node's own `getStartLine()`, protects the node itself; a comment-attachment scan protects everything nested inside it. Both need the marker at or before the node's first line: on a multi-line construct's *closing* line it is inert, since the map matches only the start line and the scan only reaches forward. Trailing on the node's own line is worse than inert: php-parser attaches a trailing comment to the *next* node, so the marker silently suppresses the following statement while leaving its own node unprotected.
- **A bare trailing annotation can also poison every annotation after it in the file.** The line-to-mutator map is built once per file and never reset between entries, so a bare marker landing on a trailing line inherits whatever mutator list the previous colon-form annotation set, instead of defaulting to "all". This is a second, independent reason trailing placement is unsafe; it is not rescued by choosing a "safer" trailing spot.
- **A run cannot tell a silently-failed annotation from a wrong reason**: both read as an ordinary surviving mutant. Treat a survivor as suspect until the annotation is confirmed standalone, on or above the node's own line, with nothing but the mutator name after the colon.
- **Run [`scripts/check-mutate-ignore.php`](scripts/check-mutate-ignore.php) instead of confirming by eye.** From the project root, `php .claude/skills/pest-testing/scripts/check-mutate-ignore.php [path ...]` (default: the existing ones of `app`, `src` and `tests`) reports a bare marker, a tail that names no known mutator, prose after a mutator list, and a trailing marker where the line map is dead, each with file and line. It prints how many files and annotations it read before any finding, and the `pest-plugin-mutate` revision its parsing model was measured against (`v5.0.2`) beside the installed one. Exit 0 is clean, 1 is a finding, 2 is a run that could not read what it needed. The `code-quality` CI template has a `mutate-ignore` job that runs it.
- **The config-level `ignore()` and `except()` are not substitutes for it.** They silence a whole path, or a mutator across the repository, so neither can carry a per-survivor justification and neither is visible at the line someone is reading.
- **The annotation mechanics above were measured in `uamswp-migration-api` against `pestphp/pest-plugin-mutate` v3.0.5 (`e10dbdc9`), `pestphp/pest` v3.8.7 (`f108313b`) and `nikic/php-parser` v5.8.0 (`044a6a39`)**; re-check before relying on this if any of the three has moved since.
- **Whenever survivors are accepted as equivalents, the test file's docblock must state which survive and why, ending on "every killable mutant is dead" (or the equivalent)**, so a reader and the next adversarial pass know the residual score is equivalents, not an untested gap.
- **Name the platform the score was taken on, in the same line as the score.** A mutation score is the claim where omitting the machine costs the most: `100%` is simultaneously the house standard and the precise signature of a run that executed nothing, so a corpus of unattributed scores cannot audit itself in either direction.
- **Never pass `--filter` to a `--mutate` run; scope it with `--path`**, and read a reported timeout as unverified, never as a kill. Why is in [`pcov-setup`](../pcov-setup/SKILL.md).
- **`0 Mutations for 0 Files created` is a setup failure, not a pass.** Usually a missing coverage driver or a path the finder could not resolve (see [`pcov-setup`](../pcov-setup/SKILL.md)).
- **Run [`scripts/check-mutation-run.php`](scripts/check-mutation-run.php) on the output rather than reading a score off it.** `php .claude/skills/pest-testing/scripts/check-mutation-run.php mutate.log [--min=<score>]` fails (exit 1) on a run that created no mutants, on any untested, uncovered, pending or timed-out mutant, and on a score under the minimum (default 100). It prints the counts it read before any finding, and exits 2 when the log cannot be read. The `code-quality` CI template's `mutate` job runs it after `composer mutate -- --min=100`.
- **Pass-3 / inventory harden is single-flight.** One `--mutate` per worktree at a time. While it runs, prep the next eligible class but do not remutate it; commit the current class when it is mutation-clean (or every survivor is explained), then kick the next narrow mutate. Full rule and safe overlap list: [`pcov-setup`](../pcov-setup/SKILL.md) (three-pass walk).
- **Clearing `storage/framework/testing/` while a `--mutate` run is live orphans that run.** [`scripts/clear-testing-litter.mjs`](scripts/clear-testing-litter.mjs) refuses, exiting 2 and changing nothing, when a `storage/logs/mutate-*.status` file has no `exit=` line and names a running process (`child=`, `runner=` or `pid=`). A status file left by a killed run names a process that is gone and does not block. When it blocks wrongly (a recycled process id), read the file, confirm the run is not yours, and pass `--force`, which overrides this guard and nothing else (a root that is not an application, and a symbolic link on the path, are still refused). `--skip-artisan` removes the litter and restores the placeholder without running `cache:clear` or `statamic:stache:clear`, for a machine without PHP or an application that cannot boot. `--root=<dir>` acts on that application root instead of the working directory.

## JUnit checks: skipped tests and the costliest class

A run that reports zero failures can still have tested less than it should (tests skipped because a fixture was missing) or have quietly become expensive (one class growing until it bounds a parallel run). Two small PHP scripts in this skill's `scripts/` folder read the JUnit report that Pest already writes and turn each case into a failing exit code. Both are PHP so a CI job that sets up PHP and nothing else can run them. Neither names an application path: every file is passed as an argument.

Write the report with `--log-junit`, then run the checks:

```bash
vendor/bin/pest --log-junit=junit.xml
php .claude/skills/pest-testing/scripts/check-skip-baseline.php --report=junit.xml --baseline=tests/skip-baseline.json
php .claude/skills/pest-testing/scripts/check-slowest-class.php --report=junit.xml --share-ceiling=25 --ceiling=900
```

Each script exits 0 when the report is within bounds and 1 otherwise. An empty or truncated report, or one that cannot be parsed, a missing file, and a report with no usable data are also failures, never passes, because a run that produced nothing to check has not been verified.

### CI step

In a GitHub Actions job that already sets up PHP and installs dependencies, replace the plain test step with the three below; the `pest` job in the `code-quality` skill's `ci.yml` template carries exactly these. `composer test` forwards the extra argument to Pest, so a repository whose test script is something else changes only the first command. The checks run after the tests and not on a cancelled run, so a test failure is reported by the tests and a missing report is reported by the checks:

```yaml
      - name: Pest
        run: composer test -- --log-junit=junit.xml
      - name: Skip baseline
        if: ${{ !cancelled() }}
        run: php .claude/skills/pest-testing/scripts/check-skip-baseline.php --report=junit.xml --baseline=tests/skip-baseline.json
      - name: Slowest class
        if: ${{ !cancelled() }}
        run: php .claude/skills/pest-testing/scripts/check-slowest-class.php --report=junit.xml --share-ceiling=25 --ceiling=900
```

### Skip baseline

`check-skip-baseline.php` fails when the number of skipped tests exceeds the number recorded for the run, and prints both numbers (`skipped X, baseline Y (+Z)`) followed by the skipped tests. Fewer skips than the baseline passes, because fewer skips means more tests ran.

The baseline is a JSON file the repository keeps (the example path above is a convention, not a default; `--baseline` is required):

```json
{
    "bands": {
        "suite": { "expected": 3, "why": "Three cases skip when the content submodule is absent." },
        "serial": { "expected": 0, "why": "The serial tail skips nothing." }
    }
}
```

- A **band** is one independently run group of tests with its own report, such as a parallel run and a serial run. `--band=<name>` picks the entry and defaults to `suite`.
- `expected` is a non-negative integer. Anything else, a missing band, or invalid JSON is a failure.
- `why` is free text for reviewers and is not read by the script. A top-level `_comment` is ignored.
- **Raising `expected` is a reviewable change to this file.** The pull request should say why each new skip is correct. The first assumption on a failure is a real regression (a missing submodule, a starved worker, a guard firing on input that should have resolved), not a stale number.

### Costliest class

`check-slowest-class.php` sums the `time` of every test case per class, then fails when the costliest class takes more than `--share-ceiling` percent of the summed class time, or more than `--ceiling` seconds. Both thresholds are checked and each failure is reported separately. Defaults are 25 percent and 900 seconds; `--band` only labels the output.

- **The share is the main check.** It is a ratio, so machine load that slows every class equally cancels out of it. It is also what decides whether a class binds a parallel run: paratest distributes whole classes, so no run finishes sooner than its largest class, and a class starts costing wall-clock once its share passes 1 divided by the worker count.
- **The seconds ceiling is the backstop.** It catches growth that hits every class at once, such as a per-test boot cost, which moves no share. Set it several times above the worst quiet reading so load alone does not reach it.
- **The defaults are loose starting points, not measurements of any suite.** Measure the costliest class's share and time on several quiet and several busy runs, then set the share ceiling a margin above the worst share observed and below 100 divided by the workers, and pass both as flags from the CI step so the numbers sit beside the command that uses them.
- **A serial run needs a looser share ceiling.** A load spike confined to the window where the costliest class runs is not cancelled by a ratio, and a serial run has one class per window.
- When it fails, split the class rather than raising a ceiling.

### What is not generalized

The per-band measured ceilings, the backstop values and the skip counts that came with the original scripts describe one application's suite and are not shipped. Each repository records its own: the baseline file for the skip counts, and the flag values in its CI step for the cost thresholds.
## Common Pitfalls

- Deleting tests without approval.
- Forgetting datasets for repetitive validation tests.
- Passing a message to `toContain()` or `toHaveKey()`, which silently changes the assertion.
- Reading `0 Mutations for 0 Files created` as a pass.
- Passing `--filter` to a `--mutate` run, or reading its timeouts as kills.
- Starting a second `--mutate` (or overlapping `--parallel` / heavy Pest) in the same worktree while one is still running.
- Annotating an accepted survivor on the node's own line, or with prose after the mutator name.

- Not importing `use function Pest\Laravel\mock;` before using mock.
- Using `assertStatus(200)` instead of `assertSuccessful()`.
- Forgetting `assertNoJavaScriptErrors()` in browser tests.
- Prefixing `Feature/` or `Unit/` in `{name}` when using `make:test`.
- Cleaning up through a mocked facade in `afterEach`.

