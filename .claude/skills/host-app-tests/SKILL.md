---
name: host-app-tests
description: >-
  Run a package's host-app integration tests (the `Tests\TestCase`-derived classes in
  `tests/Feature/`) against a branch. These CANNOT run in the package's standalone Pest/PHPUnit
  CI: they need the booted host application, so they run from a claimed host worktree slot with
  the package pointed at your branch. Activate whenever the user asks to "run the host-app
  tests", "validate the change in the host app", "test the integration", or wants to confirm a
  package change end-to-end before merging, or when a change touches behavior only
  `tests/Feature/` exercises. For WHICH base class a test needs, defer to the
  `statamic-package-conventions` skill; this skill is the slot SETUP + RUN + result-interpretation
  procedure on top of it.
---
<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/host-app-tests/SKILL.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): laravel-package.
-->
<!-- cspell:ignore Browsershot Puppeteer rsync Stache -->

# Running a package's host-app integration tests

## Why this exists

The package's own CI runs only the standalone tests: the pure-unit classes (bare `PHPUnit\Framework\TestCase` or the package's own `TestCase`) and the ones that boot a minimal framework. The **host-app integration tests**, every class that `use Tests\TestCase;`, boot the full host application (config, container, real collections and blueprints, a test database). They are **not** registered in the host's `phpunit.xml`, which runs only the host's own `tests/Unit` and `tests/Feature`, so they are run **manually, by path**, from inside the host:

```
cd /path/to/host
php artisan test vendor/<vendor>/<package>/tests/Feature/<File>.php
```

Neither the package's gate nor its static analysis exercises them. So a change that only alters behavior reachable through the host (anything a real run of the package inside the application would touch) is **not validated** until you run this subset in the host. That is this skill.

The *Choosing a base class* section of the [`statamic-package-conventions`](../statamic-package-conventions/SKILL.md) skill is the companion rule: it decides **which** base class a test needs. This skill is the **setup + run** procedure and does not restate that taxonomy.

This package keeps the full version of that rule in `tests/CLAUDE.md`.

For this importer, the host is `uams-statamic` (`CreatesApplication`: config, container, Stache, real collections/taxonomies/blueprints, a SQLite `:memory:` DB with the `assets_meta` table) and the package is `uams-web/wordpress-importer`:

```
cd /path/to/uams-statamic
php artisan test vendor/uams-web/wordpress-importer/tests/Feature/PostImportTest.php
```

The classes this covers include `PostImportTest`, `UamsPeopleImportTest`, `KbImportTest`, `PageBuilderBlockImportTest`, `LogoListItemImportTest`, `ImportWordPressCommandTest` and the `*BlueprintImportTest` files. Neither `composer test` nor PHPStan nor this package's CI exercises them, so an importer change that only alters behavior reachable through a real import (asset metadata, entry creation, taxonomy/term writes, blueprint shape) is not validated until this subset runs in the host.

## When a run is obligatory

This skill is the **how**. The **when** is the [`host-only-behavior-needs-a-host-run`](../../rules/host-only-behavior-needs-a-host-run.md) rule: a change to behavior that only `tests/Feature/` exercises must be run here before the PR is flipped to ready. Read it before deciding you do not need this procedure.

That rule exists because three consecutive PRs changed exit-code behavior in `ImportWordPress.php`, all passed the gate, and none was run against `ImportWordPressCommandTest`. The third merged an infinite loop.

## Setup: claim the host's package slot, then point it at your branch

Do not run these in the primary host checkout. It is served, holds the main branch, and may back another session; content mutation and cache churn from a full run is exactly what the [`worktrees`](../../rules/worktrees.md) rule isolates.

**The host keeps long-lived, pre-bootstrapped slots**, so you *claim* one rather than building a worktree. A host slot already carries its dependency installs, an `.env` repointed at its own host, its git-ignored databases, its submodules and an asset build, which is why "create a worktree and bootstrap it" is not a step here.

1. **Claim the slot the host keeps for package test runs**, never one of the host's own ticket slots and never its CI slot. Borrowing a ticket slot costs three things: it **blocks the host's local CI** (the runner checks branches out by name, so a held slot fails its validating run), it makes the occupancy signal **lie** (a host slot on a package branch reads as host ticket work to anyone checking status), and it **halves the host's concurrency budget**, pushing the next host session onto an ephemeral worktree.

   The branch you check out there is a **marker, not a work surface**: nothing is committed in the host for a package test run. It exists so the ordinary free/held reading keeps working. In principle the tree could stay detached, since the only thing that changes is the git-ignored `vendor/`, but then the status check would report the slot free while your run is mid-flight.

   **Never hand-drive the host's CI slot.** It belongs to the host's local-CI runner, which checks branches in and out of it.

   If the package slot is already held, that is **another package session**, not host work. Wait, or take an ephemeral worktree per the host's rule. Do not evict it, and do not fall back to a ticket slot.

   For this importer the slot is `../uams-statamic-importer`, which exists for exactly this and nothing else (`UAMS-Web/uams-statamic-archive#1998`):

   ```
   cd /path/to/uams-statamic
   node scripts/worktree-slots.mjs status
   cd ../uams-statamic-importer
   git fetch origin
   git checkout -b importer/<N> origin/main     # <N> is the importer ticket
   ```

   Take this slot, never `-a` or `-b`: those are the host's own ticket and wave slots. `../uams-statamic-ci` belongs to the host's local-CI runner.

2. **Refresh the slot before trusting a run.** This replaces the bootstrap steps, and it is a gate rather than a courtesy: the host's `content/` submodule pointer moves with its main branch, and a stale `content/` makes `Collection::findByHandle()` return `null`, so every test that touches a collection dies with `Call to a member function entryClass() on null`. **That wall of errors across unrelated suites is environmental, not a broken branch.** An entire bisect was once invalidated by taking those nulls at face value. It has **two** causes, though; see *Interpreting results* before concluding it is the submodule.

   Run the refresh **before** step 3, not after, so that the copy in step 3 is the last write to `vendor/` in every case rather than in most. The refresh's `composer install` will not overwrite an already-installed package, so it usually leaves a copied-in branch alone; but when the lockfile has moved to a newer release it replaces the package wholesale, and your copy goes with it.

   For this importer: `node scripts/worktree-slots.mjs refresh importer`.

3. **Point the vendored package at your branch.** `composer install` places the package under `vendor/<vendor>/<package>`, often as a **dist (zip) extract with no `.git`**, so you cannot just `git checkout` inside it. Sync `src/` and `tests/` wholesale from your package branch worktree:

   ```
   SRC=/path/to/package-branch-worktree
   DST=vendor/<vendor>/<package>
   rsync -a --delete "$SRC/src/"   "$DST/src/"
   rsync -a --delete "$SRC/tests/" "$DST/tests/"
   ```

   **Sync the whole tree. Never copy `git diff --name-only origin/main HEAD`.** The host pins a tagged range, so `vendor/` sits at the newest release the constraint allows, which lags the main branch by every PR merged since. A diff-only copy lays your branch's changes onto *the last release's everything-else*, and the result is a tree that matches no commit that has ever existed. It bites three ways, all silent:

   - **A stale vendored *test* file fails and blames your branch.** The branch is innocent; the test is the old release's copy of a test main has since fixed. Your own copy of that file is correct, so nothing looks wrong until you diff vendor against the branch.
   - **A class your branch never touched goes missing.** It arrives as a bare `Class "..." not found`, which reads like a broken branch.
   - **A deletion or rename cannot be expressed at all.** A per-file copy loop only ever *adds*; a file your branch removed survives in the vendored tree and keeps running. `--delete` is what makes the sync a mirror rather than an overlay, and it is why `rsync` is the form here rather than `cp -R`.

   The same whole-tree shape is what the *baseline* half of revert-and-compare needs (see *Interpreting results*), so there is exactly one copy recipe in this skill.

   Either way, **verify the vendored tree matches the branch** before trusting a run. Diff it: a token grep only proves your *change* arrived, which is exactly the check that passes while every file you did not touch is still at the pinned release.

   ```
   diff -rq "$SRC/src" "$DST/src" && diff -rq "$SRC/tests" "$DST/tests" && echo "vendor == branch"
   ```

   Expect **no output** before the echo. `Only in ...` lines are the deletion case; `differ` lines are the stale-release case.

   Importer specifics for this step:

   - Observed with `vendor/` at `v0.7.0`: `PageBuilderBlockImportTest` reported `Field 'page_builder.0.max' should exist in production output` for the `logo list block v16` dataset. `main` had already corrected that handle to `page_builder.0.logo_list_max` because `LogoListParser` writes it.
   - Observed on macOS validating `#635`: vendor at `v0.13.0`, branch based on `main` two PRs ahead, and `test:seed-stache` died on `NinjaFormsBlockProcessor`, added by an already-merged PR.
   - **The exports corpus is not in the package tree.** It lives in `UAMS-Web/wordpress-importer-exports` and is read from a local clone named by `WORDPRESS_IMPORTER_EXPORTS`, so the sync has nothing to copy for it and `--delete` has nothing to remove. A fixture-backed suite needs that variable set in the host app's environment; without it the suite skips, and a skip is not a pass. Do not add an `--exclude` reflexively, because that is how a fixture-backed suite ends up skipping vacuously. A `vendor/` installed at a release from before #1426 still carries the old submodule path, empty in a dist install.
   - PSR-4 classes (both `src/` and `tests/`) resolve without a `composer dump-autoload`, since the app already maps the `UAMSWeb\Aether\WordPressImporter\` and `UAMSWeb\Aether\WordPressImporter\Tests\` prefixes. A brand-new file under an existing prefix autoloads fine; only a **new top-level namespace** would need a dump.
   - **Alternative: reinstall as source, then checkout** (brings the whole branch, including new files):

     ```
     composer reinstall uams-web/wordpress-importer --prefer-source --no-interaction
     git -C vendor/uams-web/wordpress-importer fetch origin
     git -C vendor/uams-web/wordpress-importer checkout <branch>
     ```

     Confirm it actually became a git checkout (`git -C vendor/uams-web/wordpress-importer rev-parse --abbrev-ref HEAD` should print your branch, not the app's). If it is still dist, fall back to the copy method.

4. **Prepare whatever the host's own CI prepares before its suites.** A session following this skill is exactly the path that skips it, and skipping it does not fail cleanly: see *Interpreting results*. The preparation is state the host owns (a content mirror, a cache snapshot, a database), so the host's CI configuration is the specification, and **a run consumes that state**, so a value that changes between runs of unchanged code is often residue rather than a flake.

   **Seed the Stache. This is a gate, not a warm-up.** The host test environment reads content through a **disposable `content/` mirror** rather than the submodule directly (`UAMS-Web/uams-statamic-archive#1916` / `#1995`), and the mirror plus its Stache snapshot are built by their own command:

   ```
   APP_ENV=testing php artisan test:seed-stache
   ```

   The `APP_ENV=testing` prefix is required, not decorative: a bare invocation refuses with `test:seed-stache is a test-harness command; run it with APP_ENV=testing.` and exits **1**, so it cannot silently no-op inside a `&&` chain.

   The host's CI and its local CI runner both run this before their suites, so a normal host-app band never notices it exists. **It is a gate on a *cold* slot, and silently unnecessary on a warm one, which is what makes it nasty.** The artifacts live in the slot's git-ignored `storage/framework/` (`content-mirror`, `content-mirror.stamp`, `stache-seed`, `stache-seed.root`), so they survive a `release` and are inherited by the next session. Verified: one session hit the failure on a freshly provisioned slot, and a later session ran both suites to green **without** seeding, because the earlier run had already built the mirror. Run it unconditionally; it is cheap when the mirror already exists.

   **The seed is state, and a run consumes it.** Imports accumulate in the disposable mirror, so a test that **creates entries or terms** can see the residue of every previous run against that seed. The symptom is a value that changes between runs of unchanged code, usually a counter suffix Statamic appends to de-duplicate a slug or title. Observed on `PostImportTest > it handles site-specific taxonomy and category mapping`, same host checkout, same importer, same content baseline:

   | Condition | Result |
   | --- | --- |
   | in isolation (`--filter`) | passes |
   | 4th full-file run against one seed | fails: `'News'` vs `'News (15)'` |
   | an earlier session, different accumulation | fails: `'News'` vs `'News (31)'` |
   | **immediately after re-seeding**, one full run | **passes**: 43/43 |

   Re-seeding was the only variable that cleared it. Two sessions disagreed about whether that test failed, on identical code and content, purely because neither was tracking run count (`UAMS-Web/uams-statamic-archive#2025`'s session reported `News (31)` as non-reproducing and reasoned about content state across checkouts instead). #613 is the worked example.

   **So: re-seed before a full-file run of a file you have already run this session.** Not before every run: most of the band does not accumulate visibly, and burning a seed each time adds minutes to an already 3 to 12 minute cycle. Treat any result obtained *without* re-seeding as suspect if the test creates entries or terms; `--filter` on a single test is the cheap way to tell an accumulation artifact from a real failure, because it starts from far less residue.

5. **Warm the Stache** (per #359). File-backed reads of a just-imported entry or term can miss without it:

   ```
   php artisan statamic:stache:warm
   ```

   Most tests set up and clear their own Stache state (`UamsPeopleImportTest` passes fully without a prior warm), so a warm is belt-and-suspenders, but it removes a class of cold-cache flakes (#359 documents an `array_keys(): ... null given` first-run flake in search indexing). **It does not substitute for the seed above**: the warm indexes whatever content root the process resolves, and the seed is what puts content at that root in the first place.

   Setup cost per run is the importer file copy, the seed, and the warm; the slot supplies everything else. `npm` is not needed either way: these are PHP tests, with no Vite or Control Panel assets.

**Two things about a host slot that do not apply to a throwaway worktree:**

- **The vendored package persists between runs, and it is invisible to `git status`.** `vendor/<vendor>/<package>` is git-ignored, so the files you copied in step 3 survive a `release` and are inherited by whoever claims the slot next, while the tree reports perfectly clean. **A clean tree does not mean the vendored package is back at its pinned release**, and neither does a `composer install`. This is why step 3's verification is not optional, and why teardown ends with `composer reinstall`.
- **A run can still reach the host's `content/` submodule.** The host re-roots its Stache stores at a disposable mirror under the testing environment, so a Statamic save should not write into the submodule, but anything that builds a raw `base_path('content/...')` path bypasses that. `worktree-slots release` uses `--ignore-submodules=all`, so a dirty `content/` will **not** block the release. Check before releasing, and restore with `git -C content checkout -- <path>` (plus `git -C content clean -fd` for anything untracked; `checkout` cannot remove it).

For this importer, `UAMS-Web/uams-statamic-archive#1995` (merged 2026-08-13) re-roots every content-backed Stache store, and the Eloquent asset-metadata dual-write, at the disposable mirror under `APP_ENV=testing`, which the host `phpunit.xml` sets. What survives is what #1995 does not key on: it routes `App\Support\ContentRoot::path()`, and **this package still has three** raw `base_path('content/...')` uses, one of which deletes files, tracked in #622. Those are now the only route from this band into the submodule, and #570's guard is what fails a test that takes it.

The old evidence still stands, and predates the fix. Observed once on macOS before #1995: `git -C content status --porcelain` reported ` D trees/collections/default/pages.yaml`, a **deleted tracked file**, and the same file `UAMS-Web/uams-statamic-archive#1873` records losing twice.

## Run

Run by explicit path; the default suite will not pick these up:

```
php artisan test vendor/<vendor>/<package>/tests/Feature/<File>.php --compact
```

- The host-app-bound tests all live under `tests/Feature/` (see the base-class rule in the `statamic-package-conventions` skill); run them by that path.
- Scope to the files your change actually touches. These are multi-minute runs.
- **Do not run two files concurrently against one tree.** They share the host's content state and collide. Run them sequentially; the concurrency ceiling in a single package slot is **one**. Bound each run per the [`long-running-commands`](../../rules/long-running-commands.md) rule rather than letting a tool timeout detach it.
- **Bound generously above the *high* end of the observed spread**, and do not calibrate off one suite: a cap set at the fast figure manufactures a "timeout" that reads exactly like a failure. Treat any single recorded figure as a lower bound.
- **Re-running the same file? Re-seed first** (setup step 4). A value that moved between two runs of unchanged code is accumulated residue, not a flake.
- **Never pipe the runner through `tail -n`** if you care about failures: `--compact` prints the failure block *before* the summary, so `tail -4` keeps only the summary and discards which test failed and why. Capture full output (or grep for `FAILED` / `Failed asserting`).

For this importer, a change to the **image/asset-metadata** path is exercised by `PostImportTest`, `PageBuilderBlockImportTest`, `KbImportTest`, `UamsPeopleImportTest` (portraits), and `LogoListItemImportTest`; a **forms** change by the `*BlueprintImportTest` / `ImportWordPressCommandTest` files; and so on. Run the relevant few, not all 27, unless doing a full sweep.

The suites are **slow.** Pooled across sessions in the importer slot, warm caches. **Each row is one platform's figure, and the `Platform` column says which;** no row is a range spanning both platforms. Where a suite has been measured on both, it gets one row per platform, because one number cannot describe both:

| Suite | Tests | Observed | High above low | Platform |
| --- | --- | --- | --- | --- |
| `PageBuilderBlockImportTest` | 29 | 154-187s | 21% | macOS |
| `ImportWordPressCommandTest` | **32** | **244-398s** | **63%** | macOS |
| `ImportWordPressCommandTest` | **32** | **1656.70s**, one run | n/a | **Windows 11** |
| `PostImportTest` | 43 | **441-531s** | **20%** | macOS |
| `GravityFormsBlueprintImportTest` | 30 | **479-663s** | **38%** | macOS |
| `FormidableFormsBlueprintImportTest` | 36 | **390-580s** | **49%** | macOS |
| `KbImportTest` | **55** | **657-805s** | **23%** | macOS |

**`ImportWordPressCommandTest` now runs 32 cases, not 25** (#1136), after #1088's `--network` cases, #1135's seam-wiring case and others. Counted with the `--list-tests` probe below against `main` at `294f59c`, staged into `vendor/`, with its two controls returning 43 and 29 as the table records. Its macOS band was measured at 25 cases. The one 32-case macOS run, 266.26s on #1135, falls inside it, so the band stands until a slower one arrives.

**The Windows row is 6.2x the macOS figure at the same 32 cases,** measured on #1156 at importer `120a8cf`. Two commits apart, with the platform and the machine also different, it cannot separate those causes and does not claim to. What it settles is that a bound sized from the macOS band kills a passing Windows run: a generous 900s cap fires at about the two-thirds mark, and the result reads exactly like a hang.

**A bound for `ImportWordPressCommandTest` must also allow for time before Pest's timer starts.** On macOS on #1135, the wall clock was 1338s against Pest's 266.26s, so roughly eighteen minutes passed before the run's own timer began. That happened during a window when other processes on the machine were logging connection refusals and connect timeouts. The file's three `--source=api` cases reach for an `.invalid` host, which a failing resolver can stretch from milliseconds to minutes. The next file booted in under a minute, so it did not reproduce. This is a condition for the bound (a 1500s cap held against the 1338s wall clock, with 162s to spare), not a figure for the band.

**`FormidableFormsBlueprintImportTest`'s floor dropped, and it was re-widened downward.** On #1135 (macOS, importer `a1c6094`) it ran **390.00s**, and 400.38s on an earlier run of the same tree. #1158 then measured 556.49s. Two runs near 390s make it a band shift, not an outlier. A dropped floor matters as much as a raised ceiling, because a reader calibrating off a `521s` low reads a 390s pass as suspiciously fast.

**Both forms suites gained a second measurement on #815** (macOS, warm importer slot), and both had been understated: Gravity ran **662s** against a `513s` ceiling and Formidable **580s** against its lone `521s` figure. Gravity's band widened to 38%, which is the `ImportWordPressCommandTest` lesson repeating: a first measurement is a lower bound, not a band. Neither has been measured on Windows.

**`PostImportTest`'s band widened on #803, and had been understated before it.** It was recorded at `441-479s`, and three of the four most recent measurements sat at or above that ceiling: 481s on the `#769` validation, then 524s and 531s on the two independent `#803` runs. A bound sized off the old column would have fired on a passing run, which is the failure this table exists to prevent. `PageBuilderBlockImportTest` drifted the same way in the same pair of runs, from a `172s` ceiling to `187s`. Treat every band here as a lower bound the next run may push, and re-widen it when that happens rather than reading a slow pass as a hang. That happened immediately: `KbImportTest` was corrected to `657-782s` on #807 and measured **805s** on #432 the same day, with one case added, so the row moved again rather than the figure being treated as an anomaly.

**The `Tests` column is verifiable without paying for a run.** `--list-tests` enumerates the datasets without executing them, in seconds:

```bash
./vendor/bin/pest vendor/uams-web/wordpress-importer/tests/Feature/KbImportTest.php \
    --list-tests | grep -c '^ - '
```

Counting `it(` in the file does **not** work and is not a near-miss: these suites are dataset-driven, so `KbImportTest` declares 11 top-level cases and runs 54. Stage `main`'s `tests/` tree into `vendor/` first: the vendored copy is whatever release the host pins (`v0.16.0` at the time of writing), which lags `main` by however many cases have since been added, so enumerating it answers a question about the release rather than the branch. Validate the probe against a suite whose count is already known before trusting it on one that is not: it returns 43 for `PostImportTest` and 29 for `PageBuilderBlockImportTest`, matching both this table and their measured runs.

**One 280s outlier on `PageBuilderBlockImportTest` has been seen, and it is not a real regression. Do not re-derive it as one.** It was measured while landing `#624` and attributed to that change, on the reasoning that `PageEntryCreator` now runs the post term pass and this file drives `createEntries()` once per block variant. That attribution was wrong twice over. The suite's fixtures declare **zero** `<wp:category>` elements, so the added pass finds nothing and does nothing; and the comparison ran cold-then-hot: the 280s run followed a fresh slot claim, an rsync into `vendor/` and a first Stache warm, while its baseline ran on a hot machine. A controlled A-B-A afterwards (force-cleaned mirror, warm-up run discarded) gave **168s with / 172s without / 168s with**, and a second person's four runs landed at 167.6, 168.7, 171.8 and 168.9, the last of those *with* the change. Eight runs, one band, no effect. The 280s has no established cause; it did not reproduce.

Two things generalize from it. **Never compare a with/against-baseline pair in one direction on a freshly-claimed slot**: the first run pays cache costs the second does not, and that difference is larger here than anything this package's code has ever done to the number. And the assertion count is the cheap tell: 4364 in every run above, so the slow one was doing the same work, not more of it.

The widest macOS spread here is about three times the tightest, and it is not the longest suite, and the one suite measured on both platforms differs by 6.2x between them, so a margin that is generous for `KbImportTest` is not generous for `ImportWordPressCommandTest`. Each data-provider file boots and imports repeatedly. They share the `content/` submodule via `snapshotIsolatedState()`, which is why two files must not run concurrently.

## Interpreting results: a failure is not automatically your fault

This subset carries a standing set of **pre-existing** failures that are unrelated to any given change. Before attributing a red test to your branch:

0. **Rule out the missing host preparation first, because it impersonates other diagnoses.** If you skipped setup step 4, the failures do not say so. A null collection, store or tree is what it looks like, and the refresh step above teaches you to read that as a **stale `content/` submodule**. Check that first, but do not stop there: the discriminator is cheap. If the slot's drift check is clean and the submodule's HEAD matches the host's gitlink, it is the missing preparation; do it and re-run. Confirm by elimination rather than by theory: run an **untouched** test in the same file. A test that fails identically before the preparation and passes after it, with no code change in between, proves the branch innocent. A worse variant is **silent**: the command exits `0` and the assertion simply finds nothing, which reads as a regression in the branch you are testing.

   **Do not read a green run as evidence the preparation was unnecessary.** That same test passes without it on a slot whose state a previous session already built. The failure only appears cold, so "it worked for me" and "the preparation is required" are both true, of different slots.

   For this importer, the errors are `Call to a member function entryClass() on null` and, silently, `Expecting null not to be null`. Verified on macOS with `content/` correctly initialized, at the right revision, and `worktree-slots.mjs status --check` reporting no drift: the errors were the missing seed, and re-checking the submodule was wasted effort. Observed on macOS: `it creates a page without ACF blocks using the basic_page blueprint` failed identically before the seed and passed after it. Run `APP_ENV=testing php artisan test:seed-stache` and re-run.

1. **There is no live known-failure catalog: revert-and-compare (step 3 below) is the primary method.** A closed umbrella ticket is the worst kind of reference here: it still renders, still has a title that reads like a catalog, and still lists historical failures, so the step *appears* to work while telling you nothing about a red test you are looking at today. Per-file trackers are the replacement, and they close.

   For this importer, #359 and #400 / PR #403 were the umbrella tickets this step used to send you to; **both are closed**. Per-file trackers, verified open on 2026-08-24:

   | If this is red | Look at | Note |
   | --- | --- | --- |
   | `PostImportTest`: a `post_categories` title with a counter suffix (`News (31)`) | **#613** | Not a code failure: the seed accumulates. See the run-count trap in setup step 4 |
   | `GravityFormsBlueprintImportTest` | **#522** (closed) | **Not a known failure.** #522 recorded 10 of 30 on 2026-08-01; sweeps on 08-05, 08-13 and 08-26 all saw **30/30**, and every candidate forms fix merged after the first of those. A red here is genuine or harness contention: do not file it as pre-existing without a re-seeded run |
   | A wall of `comparison failed` dumps around a passing assertion | **#616** | Output noise, not failures |
   | `content/` dirty after a run | **#622** | The three raw `base_path('content/...')` writes #1995 does not re-root |
   | A news block's `category` empty or unfiltered | **#425** | Open half of the `post_categories` migration |

   Anything not in that table gets step 3 below, not a search of closed tickets.

   **A stale vendored file is its own attribution cause, and it is on no tracker.** If setup step 3's copy was a diff rather than a whole-tree sync, the failing file may simply be the *pinned release's* copy of a test the main branch has since fixed. `diff -rq` the vendored tree against the branch before reaching for any tracker.

2. **Watch for content-model drift.** The host's `content/` submodule moves ahead of the tests. A test asserting a taxonomy term long after that taxonomy became a collection fails on current content regardless of the branch, and no cache warm can conjure a taxonomy that no longer exists.

   The worked example is `PostImportTest`'s "handles site-specific taxonomy and category mapping", which asserted a `post_categories` **taxonomy term** (`Term::find('post_categories::default_news')`) long after `post_categories` became a **collection** (`content/collections/post_categories.yaml` exists; `content/taxonomies/post_categories.yaml` does not). **#422 closed that gap**: the importer now writes `post_categories` collection-entry ids and the test asserts them, so the failure should be gone once your run picks up that work. Until the host adopts a release containing it, a run against the **pinned vendor copy** still shows the old failure, which is the same reason step 3 below insists the revert-and-compare baseline is `origin/main` rather than the pin.

3. **Revert-and-compare to attribute a failure.** The decisive check: reproduce the same test on a **clean** package, and the baseline is **the package's `origin/main`, never the pinned vendor copy.** The vendored package resolves to whatever tagged release the host's `composer.json` allows, which lags the main branch; comparing against it can blame your branch for a defect that merged after the pin, or clear one that did not. Sync the baseline the same way you synced the branch (the whole-tree `rsync` from setup step 3, pointed at a checkout of `origin/main`) so a file your branch deleted or renamed cannot survive into the baseline run. Then re-run the single test with `--filter`:

   ```
   php artisan test vendor/<vendor>/<package>/tests/Feature/<File>.php --filter=<test_method>
   ```

   If it fails **identically on clean `origin/main`**, it is pre-existing, not your change. Restore your branch files afterward. Do the isolation with `--filter`: some of these failures are order-dependent and only appear in a full-file run.

   `tests/CLAUDE.md` notes mock-collision ordering artifacts as one such case.

A run is "green for your change" when every failure **reproduces on clean `origin/main`**. That is revert-and-compare, step 3, and it is the durable half because it depends on no ticket staying open. A match in a tracker table is a shortcut to the same conclusion, not a substitute for it. The count being zero is neither necessary nor sufficient.

**But revert-and-compare cannot see a defect its two arms share.** Both arms boot the same slot, the same `content/` and the same test-environment mirror, so anything wrong with *those* reproduces identically, and "reproduces identically on `origin/main`" is exactly the phrase that normally means "pre-existing, not yours". The comparison agrees with itself and confirms the wrong conclusion.

**The tell is what the error names.** A failure about the *behavior under test* (a wrong handle, a missing field, an assertion on imported data) is **usually** the kind revert-and-compare attributes correctly, but treat the shape as a heuristic that shifts the odds, not a proof. A failure naming a null **collection, store or tree** is about the environment the test reads through, and both arms read the same one. When you see that shape, re-prepare per setup step 4 and re-run **before** comparing; a matching pair proves nothing until the harness is known good.

Observed on #509 (macOS, 2026-08-25): five failures across `UamsPeopleImportTest`, `PostImportTest` and `KbImportTest` matched `origin/main` to the assertion count, were filed as pre-existing, and were **entirely** a stale test-env mirror in the slot doing the comparing. Re-run after clearing `content-mirror{,.stamp}` and `stache-seed{,.root}` and re-seeding, `UamsPeopleImportTest` went from 2 failed / 28 passed (187 assertions) to **30 passed (198)** on the same commit. The mirror-staleness half is `UAMS-Web/uams-statamic-archive#2092`; the discipline is here. #522 is a counter-example to the shape heuristic: both its failures were about behavior under test (`-'landing_page' +'basic_page'` and an empty content array), and both were environmental, reproducing on `origin/main` because each arm shared the fault.

**Three `PostImportTest` news-release cases need a working Browsershot, so their failure can be the machine rather than your change (#1218).** Since #860 the importer does not download the news-release PDF. The host regenerates it on save, through `GenerateNewsReleasePdf` and `NewsReleasePdfGenerator`, which render with Browsershot: Node plus Puppeteer's bundled Chrome. The listener catches any exception and only logs a warning, which the test environment does not surface. So a slot that cannot launch Chrome imports the entry with `news_release_pdf` null and fails exactly these three, with nothing naming the cause:

```
it validates the mapped YAML fields ... "news release post"        Field 'news_release_pdf' should be an array   WordPressImportTestTrait.php:3240
it validates the mapped YAML fields ... "news release post v02"    Field 'news_release_pdf' should be an array   WordPressImportTestTrait.php:3240
it stores the news release entry ID in the imported PDF asset ...   Expecting null not to be empty                PostImportTest.php:707
Tests: 3 failed (315 assertions)
```

**Check the runtime before reading them as a regression.** From the host slot, `npx --no-install puppeteer browsers list` must list at least one Chrome. On macOS it listed several, and the three cases passed: 323 assertions, both at host `dbf47771b` with the importer at `419bd97`, and at the exact inputs of the Windows report (host `2ba68850a`, content `4cb279152`, importer `582bddb`). With `LARAVEL_PDF_CHROME_PATH=/nonexistent/chrome` on the same Mac, the run reproduced the Windows failure exactly: the same three cases, messages, lines and assertion count. Those were measured on macOS. The Windows seat's own Browsershot runtime has not been measured, so what it lacks there (Node on `PATH`, Puppeteer's Chrome, or its launch) is not established.

## Teardown

**Release the slot; never remove it.** A slot is a full host bootstrap, so deleting one throws away work the model exists to keep.

Before releasing: check that the host's `content/` submodule is clean (restore it if not), reinstall the vendored package, and then release the slot. `release` asserts a clean tree, detaches it at the main branch, and preserves the branch. Do it as your last act: a slot still holding your marker branch blocks the next package session from claiming it cleanly. Copy any imported-content or asset scratch worth keeping to the scratchpad first; everything else in the tree is disposable.

**`reinstall`, not `install`, and the difference is the whole point.** Composer does not overwrite files in an already-installed package, so `composer install` leaves the branch files you copied in exactly where they are and reports success. Verified by byte-comparison after a full run: following `composer install`, the vendored tree was **identical to the branch** and differed from `origin/main`, while `composer show` printed the pinned release it should have been serving. `composer reinstall <vendor>/<package>` genuinely restores it.

That failure is worse than an ordinary wrong command because of where it sits: a session that runs `install` gets a clean `git status`, a clean slot status, **and** a contaminated `vendor/`, with every signal it can read saying the slot is at rest. **Both teardown checks exist because `release` cannot see what they check.** It passes `--ignore-submodules=all`, so a dirty `content/` sails through; and `vendor/` is git-ignored, so branch code left there is invisible. Nothing reports either; the next session inherits both.

For this importer:

```
cd ../uams-statamic-importer
git -C content status --porcelain      # restore before releasing if not empty
composer reinstall uams-web/wordpress-importer --no-interaction
cd /path/to/uams-statamic
node scripts/worktree-slots.mjs release importer
```

A slot still holding `importer/<N>` blocks the next importer session from claiming it cleanly. In the byte-comparison above, the pinned release was `v0.10.0`.

## References

- The [`statamic-package-conventions`](../statamic-package-conventions/SKILL.md) skill: which base class a test needs (the companion rule).
- The [`worktrees`](../../rules/worktrees.md) rule: the slot lifecycle this composes with (claim, refresh, release).
- The [`host-only-behavior-needs-a-host-run`](../../rules/host-only-behavior-needs-a-host-run.md) rule: when a run is obligatory.

- `UAMS-Web/uams-statamic-archive#1998`: why the importer slot exists rather than borrowing `-a`/`-b`. The host's own `worktrees` rule (`.claude/rules/worktrees.md` in `uams-statamic`) has the importer slot's section.
- `UAMS-Web/uams-statamic-archive#1916` / `#1995`: the disposable `content/` mirror the Stache seed builds.
- `UAMS-Web/uams-statamic-archive#1873`: `content/trees/collections/default/pages.yaml`, the file lost twice to an interrupted run.
- #516: why the vendored tree is synced whole rather than by diff.
- #573: why this skill no longer routes triage through an umbrella ticket.
- #619: the Stache seed as consumed state, not one-time setup.
- **Live trackers** (open as of 2026-08-24): #425 (news-block `category`), #522 (Gravity Forms), #613 (seed accumulation / `News (31)`), #616 (`comparison failed` output noise), #622 (the raw `base_path('content/...')` writes).
- **Historical, cited for provenance only; do not use as a lookup:** #359 (host-app failure triage umbrella, closed), #400 / PR #403 (Statamic-6 drift, closed), #422 (`post_categories` taxonomy to collection, closed), #570 (the `content/` cleanliness guard, closed), #13 (the `people_groups` registry repoint, closed).

History: [host-only-behavior-needs-a-host-run.md](../../history/host-only-behavior-needs-a-host-run.md), the incident record behind the rule this skill carries out.
