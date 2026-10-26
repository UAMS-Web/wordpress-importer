---
name: statamic-package-conventions
description: >-
  Conventions for a Statamic add-on or Composer package that runs inside a host Laravel
  application instead of booting on its own: it has no `artisan`, `.env`, served URL, front end or
  database, so application habits (Herd `.test` URLs, `php artisan make:*`, Laravel Boost, Eloquent
  models, `route()`, `bootstrap/app.php`, Vite) do not apply. Covers the service provider
  (`Statamic\Providers\AddonServiceProvider`, discovery through `extra.laravel.providers`,
  container bindings and shared singletons), configuration read from the host with a default,
  versions read from the package's own `composer.json` and an exact `statamic/cms` pin coupled to
  the host, tagged releases the host pins, and tests split by how much they boot (standalone Unit
  and Integration suites, host-bound Feature tests run inside the host application) with
  `composer test`, `composer stan` and `composer ci`. Activate together with `statamic-conventions`
  whenever working in the package (new classes, console commands, provider bindings, tests, or any
  test/build workflow), and before carrying a habit over from the host application.
---
<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/statamic-package-conventions/SKILL.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): statamic-package.
-->
<!-- cspell:ignore Testbench -->

# Statamic package conventions

The conventions for a Statamic add-on that a host application installs and boots. They hold
alongside the `statamic-conventions` skill, which carries everything that is the same in an
application; activate both.

## 1. What a package is not

The package is a Composer library: its code is autoloaded from `src/` through the PSR-4 map in
`composer.json`, and it runs only when a host application boots it. It has **no `.env`, no
`artisan`, no served URL, no front end and no database of its own**.

So anything that assumes a bootable application does not apply here: Herd and `.test` URLs,
Vite/npm builds, Inertia, `php artisan make:*`, Eloquent models, `route()`, `bootstrap/app.php`,
and Laravel Boost's MCP tools, which run through `php artisan boost:mcp`. **If you are carrying a
habit over from the host application, check it against this repository first.**

- Write a new class by hand, following a sibling file's namespace, structure and PHPDoc; there is
  no `make:*` generator.
- Read the package's own `composer.json` and `README.md` for how it is wired, not the host's.

## 2. The service provider

- The host discovers the provider through `extra.laravel.providers` in `composer.json`, and
  `extra.statamic` carries the add-on's name and description. A provider that is not listed there
  is not discovered.
- The provider extends `Statamic\Providers\AddonServiceProvider`. Console commands are listed in
  its `$commands` property; container bindings go in `register()`.
- **Bind a service as a singleton when it is configured once and received by injection
  elsewhere.** Two instances mean the configuration was applied to one object and the consumer
  received another, so the configured value silently does not take effect. Say why in a comment
  beside the binding.
- **Expose what the host needs, and let the host wire it.** The provider registers a command, a
  binding or a middleware alias; it does not attach middleware to the host's route groups. A host
  that wants the behavior attaches it in its own `bootstrap/app.php`, and the provider's docblock
  says how.
- Where the README declares a public surface, the provider's registration and the bindings it
  performs are part of it, and changing them is a breaking change under the `cutting-releases`
  rule.

## 3. Configuration comes from the host

- The package ships no config file and no `.env`. Package code reads host configuration through
  `config()` with a default, for example `config('wordpress-import.migration_api.per_page', 100)`,
  and never calls `env()`.
- Document every key the host may set in `README.md`, with the `env()` line the host writes for
  it.
- **Validate what the host hands over.** A value the host reads with `env()` arrives as a string,
  so check its type and range and fail with a message that names the configuration key, rather
  than casting it or silently falling back to the default.

## 4. Versions and the host

- **Read versions from this package's `composer.json`, not from memory or from the host.** The
  test harness (Pest, PHPUnit, Pint, PHPStan) moves on its own schedule in each repository, and
  copying the host's assumptions has produced false statements.
- **An exact `statamic/cms` pin couples the package to the host.** Where the package pins the
  same Statamic build the host runs, Composer cannot satisfy two different exact requirements, so
  the host cannot adopt a newer Statamic until the package is repinned and released. Move the pin
  here first, then in the host. The pin does not fix Statamic's own dependencies; only
  `composer.lock` holds those.
- **The host pins a tagged range, so merged work is unreachable until a tag exists.** When to cut
  one is the `cutting-releases` rule.

## 5. Tests

The test-enforcement rules (every change tested, the paths to cover, self-skipping tests) are in
`statamic-conventions`. In the package, tests are split by how much they boot:

| Suite | Path | Boots | Runs in the package's own gate |
| --- | --- | --- | --- |
| `Unit` | `tests/Unit/` | nothing, or a minimal Laravel | yes |
| `Integration` | `tests/Integration/` | a minimal Statamic via Orchestra Testbench (`Statamic\Testing\AddonTestCase`) | yes |
| `Feature` | `tests/Feature/` | the host application's `Tests\TestCase` | **no** |

- **A test's base class decides its directory, and the directory decides where it runs.** A
  host-bound test placed in `tests/Unit/` or `tests/Integration/` breaks the standalone gate with
  `Class "Tests\TestCase" not found`; a standalone test placed in `tests/Feature/` never runs
  there. The rule for choosing the base class is in *Choosing a base class* below.
- **`Feature` tests need the booted host application**, and run from a host worktree with the
  package pointed at your branch: the [`host-app-tests`](../host-app-tests/SKILL.md) skill is the
  procedure, and the [`host-only-behavior-needs-a-host-run`](../rule-host-only-behavior-needs-a-host-run/SKILL.md)
  rule says when a run is obligatory. A wrong base class fails only there, never in the
  standalone gate or in PHPStan, and behavior that only `tests/Feature/` exercises is unverified
  by a green `composer test`.
- **New tests are Pest**, written by hand in the directory their base class requires. For Pest
  syntax use the `pest-testing` skill.
- **The suite is migrating from PHPUnit to Pest, and both coexist.** The existing PHPUnit class
  tests are still valid and still run in their suites. Do **not** mass-convert them: port a test
  with the `phpunit-to-pest` skill only when you are already modifying it. Converting Pest to
  PHPUnit is backwards; don't.

### Choosing a base class

Choosing the wrong base class does not fail at edit time: it fails when the suite runs, as
`BindingResolutionException: Target class [config] does not exist` or `Class "Tests\TestCase" not
found` at `setUp()`, and a host-bound test in the wrong directory simply never runs where it should.
Neither the package's gate nor PHPStan catches it. Work down this ladder and stop at the first match:

1. **The test needs the host application** (real collections, entries, blueprints or Stache state,
   a database trait such as `RefreshDatabase`, the console kernel's dispatcher, or a fixture corpus
   that CI does not have): extend the host's `Tests\TestCase`, put the test in `tests/Feature/`, and
   run it from a host worktree (the `host-app-tests` skill).
2. **The test needs a booted Statamic but no real content** (container-resolved services, Statamic
   facades, a Laravel facade spy): extend the package's Testbench base (`Statamic\Testing\AddonTestCase`)
   and put the test in `tests/Integration/`.
3. **The test needs nothing from a framework:** extend a bare `PHPUnit\Framework\TestCase` and put
   the test in `tests/Unit/`.

Never "fix" a PHPStan error by dropping `use Tests\TestCase;` or by moving a host-bound test to a
lower base class. That trades a static complaint for a silent runtime break; fix the stub or the
stub path PHPStan reads instead.

This package has four base classes rather than three, a longer signal ladder and the history of the
regressions: the full rule is `tests/CLAUDE.md`, which Claude Code loads when working under `tests/`;
any other agent reads it first.

### Running tests

```bash
composer test    # pest --testsuite=Unit,Integration
composer stan    # phpstan analyse --memory-limit=-1
composer ci      # @stan, pint --test, @test, stopping at the first failure
```

- **Always scope a Pest run to the standalone suites, or to a file inside them.** A bare
  `vendor/bin/pest` picks up `tests/Feature/` and errors with
  `The class Tests\TestCase was not found`.
- There is no `php artisan test` here.
