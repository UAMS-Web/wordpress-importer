# Rules for writing tests in this package

This file auto-loads whenever you work under `tests/`. It holds two standing rules: **which base class a test needs**, and **how a test may sample the content it asserts against**. Both fail silently — neither `composer test` nor PHPStan catches them.

## Rule — pick the right test base class (does this test need the booted app?)

Every test in this package extends one of **four** base classes. Choosing wrong does not fail loudly at edit time — it fails only when the suite runs, with `BindingResolutionException: Target class [config] does not exist` (or `Please provide a valid cache path`) at `setUp()`/`run()`. This has silently regressed the suite three times (`755ffd9`, then the PHPStan "cleanups" `f34aae3` / `b025339`); this rule is the fix.

### Where they live — `tests/Unit`, `tests/Integration`, `tests/Feature`

The base class also decides the **directory**, and the directory decides **where the test runs**. This package's own GitHub Actions CI runs the two standalone suites (`composer test`, i.e. `pest --testsuite=Unit,Integration`) — no host app, no private fixtures, no token:

- **`tests/Unit/`** — pure-PHP standalone tests (bare `TestCase`) plus the `LaravelHttpTestCase` HTTP-only tests. No framework, or a minimal Laravel.
- **`tests/Integration/`** — the **light Statamic band**: `StatamicTestCase` (a Testbench-backed `Statamic\Testing\AddonTestCase`) boots a *minimal* Statamic — the importer service provider, the container, facades — but **not** the host app's real content model. Standalone-safe, runs in package CI (wordpress-importer-archive#319, decision B).
- **`tests/Feature/`** — the host-app-bound tests (`use Tests\TestCase;`), plus `WordPressImportTestCase`, the base class for files that need the shared `WordPressImportTestTrait` helpers (`uses(WordPressImportTestCase::class)`, never `uses(TestCase::class, WordPressImportTestTrait::class)`: PHPStan cannot see a trait composed by `uses()`, #1031). These run only inside the booted `uams-statamic` app (see below), never in package CI.

A new test's base class and its directory must agree: a host-bound test dropped in `tests/Unit/` or `tests/Integration/` breaks CI with `Class "Tests\TestCase" not found`, and a standalone test in `tests/Feature/` simply never runs in package CI. (One subtlety: a bare-`TestCase` test that *reflects over* host-bound classes — e.g. `ProviderCaseCountGuardTest` — is effectively host-bound too and lives in `tests/Feature/`.)

### The four bases

| Base class | Directory | Boots | Use when the test… |
|---|---|---|---|
| `Tests\TestCase` (via `use Tests\TestCase;`) | `tests/Feature` | the **full host Statamic app** (`CreatesApplication`): real collections, blueprints, Stache, DB | needs the **real content model** (real entries/terms/collections/blueprints), a real import, behavior of the host's **console-kernel dispatcher** itself, or an external fixture corpus (see the signal below) |
| `StatamicTestCase` (via `uses(StatamicTestCase::class)`) | `tests/Integration` | a **minimal Statamic** via Orchestra Testbench (`AddonTestCase`) — the importer provider, container, facades; **no** real content | needs a booted container / Statamic facades / container-resolved services, but **not** real collections/entries/terms/Stache |
| `LaravelHttpTestCase` (same namespace, no import) | `tests/Unit` | a **minimal Laravel app**: container + events + HTTP/console kernels + `Illuminate\Http\Client\Factory`. **No config repository, no Statamic.** | its *only* framework need is `Http::fake()` / the HTTP client |
| `TestCase` with **no** `use Tests\TestCase;` → this package's `UAMSWeb\WordPressImporter\Tests\TestCase` | `tests/Unit` | **nothing** — a bare `PHPUnit\Framework\TestCase` | is pure logic: string/XML parsing, reflection on plain objects, fully-mocked collaborators that never hit the container |

### The signal — how much boot does it need?

Work down this ladder and stop at the first match:

1. **Needs the *full host app* → `Tests\TestCase` (`tests/Feature`).** Any of:
   - `use RefreshDatabase;` / `DatabaseMigrations` / `DatabaseTransactions` (the trait alone forces `config('database.default')` in `setUp()`).
   - `$this->artisan(...)` running a real import, or asserting on **real content**: `Entry`/`Collection`/`Term`/`Taxonomy`/`Blueprint`/`Asset`/`AssetContainer` reads/writes against actual collections, or `Stache` state.
   - behavior that only exists under the **console/artisan kernel**. Listener removal is no longer such a case: `AssetReferenceUpdates` (through Statamic's `forgetListener` macro) and `SearchIndexUpdates` (by filtering the listener table itself) are both asserted in `tests/Integration` against a dispatcher the test binds, in `AssetReferenceUpdatesScopeTest` and `SearchIndexUpdatesScopeTest`. A test that relies on the *host's* dispatcher instead would only skip the load-bearing case under Testbench, a false green, so it stays host-bound.
   - depends on an external fixture corpus not in CI (e.g. the `wordpress-importer-exports` sampled exports).
2. **Needs a *booted Statamic* but no real content → `StatamicTestCase` (`tests/Integration`).** `app()`/`resolve()`/`$this->app` for container services, Statamic facades used without real content, or a Laravel facade spy the minimal app provides (`Log::spy()`). Testbench boots enough for these.
3. **Only `Http::fake()` → `LaravelHttpTestCase` (`tests/Unit`).**
4. **Nothing framework at all → bare `TestCase` (`tests/Unit`).**

`LaravelHttpTestCase`'s minimal app has no `config` binding, so it satisfies none of the Statamic signals; a bare `TestCase` boots nothing.

### The PHPStan trap (this is what caused the regressions)

PHPStan runs at `level: max` over `tests/`. `use Tests\TestCase;` is **already clean** — the stub at [`Support/IdeStubs/Tests/TestCase.php`](Support/IdeStubs/Tests/TestCase.php) (namespace `Tests`, extends `Illuminate\Foundation\Testing\TestCase`) resolves it and its `$this->artisan()` / `RefreshDatabase` methods. That stub is **not** in composer autoload, so it never shadows the host app's real class at runtime.

So: **never "fix" a PHPStan error by dropping `use Tests\TestCase;` or by switching a host-bound test to `LaravelHttpTestCase` or the bare base.** That trades a (non-existent) static nit for a silent runtime break. If PHPStan can't see `Tests\TestCase`, the stub or its stub-path config is the thing to fix — not the base class.

### How these run (why the break is silent)

Host-bound tests (everything in `tests/Feature/`) run **only inside the host Statamic app**, per wordpress-importer-archive#359:

```
cd /path/to/uams-statamic
php artisan test vendor/uams-web/wordpress-importer/tests/Feature/…
```

They do **not** run in standalone package CI (which runs `tests/Unit` and `tests/Integration`), and neither `composer test` nor PHPStan catches a wrong base class. Only the host-app run does. When you re-link or add a host-bound test, verify it there — the `host-app-tests` skill is the setup + run procedure.

_Refs: wordpress-importer-archive#366 (the base-class rule), wordpress-importer-archive#359 (host-app integration subset)._

## Rule — never assert against "the first match" of a content query

A test that pulls one entry out of a query — `$query->get()`, take the first that qualifies, assert on it — is not testing the thing it names. It is testing **whichever entry the index happened to return first**, and in a Statamic app that is decided by Stache and worker state, not by the code under test. In this package the sampled set is worse than arbitrary: it is whatever a **real import of a sampled WXR export** just wrote, so it shifts whenever the export changes, the corpus clone is updated, or the importer's own write order does — and per the [`current-content-is-not-the-corpus`](../.claude/rules/current-content-is-not-the-corpus.md) rule, that corpus is a *sample* of what the importer will see, not its bound.

The failure mode is not a flake, it is a **hidden green**. In the host app, `PeopleLensRouteTest` rendered the first publicly-renderable person and passed on `main` for months while four entries in the same collection returned a 500 on every page (`UAMS-Web/uams-statamic-archive#1883`). Nothing was wrong with its assertions; it simply never drew one of the four. It surfaced only when an unrelated PR added eight tests, changed how paratest distributed work, and moved the query onto a broken entry — so the PR that *exposed* a pre-existing production bug looked like the PR that caused it. An importer `Feature` test carries the same exposure with more entries behind it: one run writes thousands, and a spot check on whichever one came back first says nothing about the rest.

**Pin a named fixture, or iterate the whole qualifying set — never an ordinal one.** Pinning is the default here, and the helper already exists: [`WordPressImportTestTrait::findEntryBySlug()`](Feature/WordPressImportTestTrait.php) filters by site *and* slug before calling `->first()`, so that `->first()` is deterministic. `->first()` after a slug or ID filter is fine; `->first()` after only a collection or site filter is the lottery.

When the claim really is about *every* entry ("no imported KB article has an unresolved link"), iterate — and **collect** failures rather than throwing on the first:

```php
$failures = [];

foreach ($entries as $entry) {
    // Skip entries the claim does not cover, rather than sampling one that does.
    if (! entryQualifies($entry)) {
        continue;
    }

    try {
        // … exercise it …
    } catch (Throwable $throwable) {
        $failures[] = $entry->slug().': '.$throwable->getMessage();
    }
}

expect($failures)->toBe([]);
```

Collecting matters as much as iterating: a bad entry then **names itself** instead of hiding the other thirty-nine behind the first stack trace. Keep a "no qualifying content" guard — `Assert::markTestSkipped(...)`, as the Formidable and Gravity Forms audit tests do when their fixture directories are absent — so a missing corpus still *skips* rather than passing vacuously over an empty set. And if iterating the full set is genuinely too slow for one of the multi-minute host-app runs, pin the named fixtures you care about and record the coverage gap; do not fall back to an ordinal sample.

_Refs: `UAMS-Web/uams-statamic-archive#1895` (ported from), `UAMS-Web/uams-statamic-archive#1883` (the incident)._

## Rule — a characterization test that another repo's ticket will invert names it as `@invertsOn`

A **characterization test** asserts what the code does *today*, including a known gap, so the
boundary is visible in the suite instead of being assumed away. That is good practice and this
codebase uses it deliberately. But such a test is a **scheduled future red**: the day the gap is
fixed, it goes green-to-red by design and has to be rewritten to assert the new behavior.

When the fix that will invert it lands in **this** repo, the author sees it immediately — the
test and the change are in one diff. When the fix lands in the **other** repo, nobody sees it
until a version bump, and the red arrives in a tree whose author did not make the change.

So: when a characterization test will be inverted by a ticket in the *sibling* repo, say so in a
form a release author can `grep` for, on its own line in the class or file docblock:

```php
/**
 * …why this pins today's behavior…
 *
 * @invertsOn UAMS-Web/wordpress-importer-archive#494
 */
```

- **One tag per inverting ticket**, fully qualified `org/repo#N` even from the repo that owns it,
  so a single grep from either checkout finds every hit.
- **Prose still carries the *why*.** The tag is an index entry, not a replacement for explaining
  what the test pins and what flipping it will require.
- **Remove the tag when the test is flipped.** A flipped test asserts the fix; it no longer awaits
  anything, and a stale `@invertsOn` is a false lead in exactly the grep this exists to serve.
- **Same-repo inversions do not need it.** Five of the host app's characterization tests pin
  same-repo behavior, and the tag would be noise on all five.

`.claude/skills/writing-release-notes/SKILL.md` is the consumer: a release carrying a ⚠️ breaking
change greps the sibling checkout for `@invertsOn` naming any ticket in the release range, and
names the hits in the callout. That is what the adopting ticket's *Known-red before you start*
section should be derived from.

**The worked example is historical, and there is currently no live instance.**
`uams-statamic`'s `tests/Unit/WordPressImport/BardParserSetAttributePassthroughTest.php` asserted
the `data-set-*` stored-XSS passthrough as today's behavior, written to be flipped. `wordpress-importer-archive#494` shipped
the fix in `v0.13.0`; the moment `UAMS-Web/uams-statamic-archive#2025` bumped the pin to `^0.13`, five
assertions inverted and blocked the host's gate — while the flip that fixed them had existed on a
branch for weeks, unable to merge because the new assertions need `SetMarkupNonce`, which `^0.10`
could not reach. Had that file carried `@invertsOn UAMS-Web/wordpress-importer-archive#494`, cutting
`v0.13.0` would have surfaced it. It is now flipped, so it correctly carries **no** tag.

_Refs: wordpress-importer-archive#614 (this rule), wordpress-importer-archive#494 / PR wordpress-importer-archive#612 (the fix that inverted it),
`UAMS-Web/uams-statamic-archive#2025` (the adopting ticket that paid for it)._

## Rule — drive a prompt from a stream inside `Command::run()`, never from STDIN

A form-plugin selector asks two questions — a confirmation, then a file path — and which
mechanism asks them depends on the platform: Laravel Prompts on macOS and Linux, Symfony
questions on Windows. Neither can be answered from a test by handing `select()` a hand-built
command, and the two failures look nothing alike. Non-interactive input makes the path
question return its default without asking, so the file branch is never reached. Interactive
input reaches Laravel Prompts, which reads the **real STDIN** and blocks — a run that hangs
rather than fails (wordpress-importer-archive#1104).

**The route is Laravel's own, and it is one call the hand-built command was skipping.**
`Command::run()` calls `configurePrompts()`, which under `runningUnitTests()` routes every
Laravel Prompt to a Symfony question on the command's input stream. So: put the answers on
an interactive `ArrayInput`'s stream, give that input to a command whose `handle()` invokes
the selector, and `run()` it. Both platforms then read the same stream. The helpers are in
[`tests/Integration/NinjaFormsSourcingTest.php`](Integration/NinjaFormsSourcingTest.php) —
`ninjaSourcingScriptedInput()`, `ninjaSourcingSelectThroughCommand()`, and a subclass
exposing each dispatch target so both branches are covered on every platform, not one per
platform.

**The same STDIN read hides in a test that never means to prompt.** `$command->confirm()` and
`$command->ask()` read the input *inside the command's `OutputStyle`*, not the one passed to
the method under test. A test that hands the handler a non-interactive input while the command
keeps its own default interactive one has the confirmation read STDIN — it passed in the gate
only because pest's STDIN yields EOF there, and blocked until killed under an open pipe.
Measured on macOS: 0s from `/dev/null`, killed at the bound from `(sleep 60) |`. Give the
command the **same** input the test controls, every time.

## Rule — assert console line endings through `LfBufferedOutput`

**Symfony ends each console line with `PHP_EOL`, so a test comparing a whole `BufferedOutput` buffer against `\n` text passes on Linux and fails on the Windows leg of CI (#186).** Use [`Support/LfBufferedOutput.php`](Support/LfBufferedOutput.php) wherever a test asserts line endings exactly. `toContain` on a single line needs neither.
