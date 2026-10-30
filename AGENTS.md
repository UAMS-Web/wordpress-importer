# AGENTS.md

Guidance for coding agents working in `UAMS-Web/wordpress-importer`.

**This file is the single source of agent instructions for the repository (wordpress-importer-archive#1223).** Cursor reads `AGENTS.md` directly. Claude Code reads it through [`CLAUDE.md`](CLAUDE.md), which is an `@AGENTS.md` import and nothing else of substance. Edit this file, never `CLAUDE.md`, so the two agents cannot drift apart.

**Much of the project's guidance lives outside this file**, and the two agents reach it differently. [Instructions outside this file](#instructions-outside-this-file) names every piece and says which agent loads it automatically.

<!-- uams-claude-skills:begin -->
## Shared skills and rules

The files listed here are synced from [UAMS-Web/uams-claude-skills](https://github.com/UAMS-Web/uams-claude-skills) (`shared/`) and are edited there, never here; a sync pull request brings changes into this repository. This section is generated; everything outside it belongs to this repository.

Each rule below is a standing instruction for every task in this repository. Its full text is delivered three times: `.claude/rules/<name>.md`, which Claude Code loads in every session; `.cursor/rules/<name>.mdc`, which Cursor always applies; and the skill `.agents/skills/rule-<name>/`, which Codex selects by its description. Before work a rule governs, read its full text. Skills are in `.claude/skills/` and, as the same files, in `.agents/skills/`, and load when their description matches the task.

Profiles for this repository: core, php, pest, laravel, laravel-package, statamic, statamic-package, tests, quality, local-ci.

### Contributing

Follow [`CONTRIBUTING.md`](CONTRIBUTING.md) for the issue-to-merge path: a branch from `main` linked to the issue and a pull request to `main`. It also maps each branch to the environment its pull request's test plan is run on. Start each pull request body from [`.github/pull_request_template.md`](.github/pull_request_template.md).

### Rules

- `adversarial-review`: Adversarially verify before it reaches anyone else. Full text: [`.claude/rules/adversarial-review.md`](.claude/rules/adversarial-review.md).
- `american-english-prose`: American English for prose, and a dictionary entry that overrides it needs a stated reason. Full text: [`.claude/rules/american-english-prose.md`](.claude/rules/american-english-prose.md).
- `an-empty-result-is-not-evidence`: A result is not evidence until the instrument has been shown to tell the two answers apart. Full text: [`.claude/rules/an-empty-result-is-not-evidence.md`](.claude/rules/an-empty-result-is-not-evidence.md).
- `closing-a-ticket`: Finish a ticket where it is visible, not just where it builds. Full text: [`.claude/rules/closing-a-ticket.md`](.claude/rules/closing-a-ticket.md).
- `coordination-plumbing-stays-out-of-artifacts`: Keep session-coordination plumbing out of GitHub artifacts and documentation. Full text: [`.claude/rules/coordination-plumbing-stays-out-of-artifacts.md`](.claude/rules/coordination-plumbing-stays-out-of-artifacts.md).
- `cutting-releases`: Cut a release each business day at 10:00 Central when there is unreleased work. Full text: [`.claude/rules/cutting-releases.md`](.claude/rules/cutting-releases.md).
- `design-decision-forks`: Presenting design-decision forks (lead with a compare/contrast). Full text: [`.claude/rules/design-decision-forks.md`](.claude/rules/design-decision-forks.md).
- `filing-defects-across-repos`: File a defect in the repository that owns it, and claim it first when a race is likely. Full text: [`.claude/rules/filing-defects-across-repos.md`](.claude/rules/filing-defects-across-repos.md).
- `github-api-budget`: Spend the REST quota to preserve the GraphQL one. Full text: [`.claude/rules/github-api-budget.md`](.claude/rules/github-api-budget.md).
- `host-only-behavior-needs-a-host-run`: Behavior only the host app exercises needs a host-app run before the PR is ready. Full text: [`.claude/rules/host-only-behavior-needs-a-host-run.md`](.claude/rules/host-only-behavior-needs-a-host-run.md).
- `impersonal-voice-in-github-artifacts`: Write GitHub artifacts in an impersonal voice. Full text: [`.claude/rules/impersonal-voice-in-github-artifacts.md`](.claude/rules/impersonal-voice-in-github-artifacts.md).
- `long-running-commands`: Bound every long-running command, and resolve what you kill. Full text: [`.claude/rules/long-running-commands.md`](.claude/rules/long-running-commands.md).
- `measurement-parity`: Mirror the *job*, not the command, before comparing to a baseline. Full text: [`.claude/rules/measurement-parity.md`](.claude/rules/measurement-parity.md).
- `no-em-dashes`: No em dashes in prose, docs, comments, or strings. Full text: [`.claude/rules/no-em-dashes.md`](.claude/rules/no-em-dashes.md).
- `no-emoji-in-durable-records`: No emoji in durable records. Full text: [`.claude/rules/no-emoji-in-durable-records.md`](.claude/rules/no-emoji-in-durable-records.md).
- `pre-merge-check`: Re-verify at the merge, not only at the build. Full text: [`.claude/rules/pre-merge-check.md`](.claude/rules/pre-merge-check.md).
- `reading-exit-status`: Read the result, not the exit code. Full text: [`.claude/rules/reading-exit-status.md`](.claude/rules/reading-exit-status.md).
- `supported-platforms`: MacOS and Windows are both supported; name the platform, write paths repo-relative. Full text: [`.claude/rules/supported-platforms.md`](.claude/rules/supported-platforms.md).
- `sync-pr-branch`: Sync every PR branch from `origin/main` before the gating run. Full text: [`.claude/rules/sync-pr-branch.md`](.claude/rules/sync-pr-branch.md).
- `worktrees`: Work from stable, pre-bootstrapped worktree slots (branch work never happens in the primary checkout). Full text: [`.claude/rules/worktrees.md`](.claude/rules/worktrees.md).

Skills: [`code-quality`](.claude/skills/code-quality/SKILL.md), [`decision-log`](.claude/skills/decision-log/SKILL.md), [`github-wiki`](.claude/skills/github-wiki/SKILL.md), [`handed-back-label`](.claude/skills/handed-back-label/SKILL.md), [`host-app-tests`](.claude/skills/host-app-tests/SKILL.md), [`laravel-best-practices`](.claude/skills/laravel-best-practices/SKILL.md), [`local-ci`](.claude/skills/local-ci/SKILL.md), [`marketplace-review`](.claude/skills/marketplace-review/SKILL.md), [`pcov-setup`](.claude/skills/pcov-setup/SKILL.md), [`pest-testing`](.claude/skills/pest-testing/SKILL.md), [`php-coding-standards`](.claude/skills/php-coding-standards/SKILL.md), [`php-documentation`](.claude/skills/php-documentation/SKILL.md), [`phpunit-to-pest`](.claude/skills/phpunit-to-pest/SKILL.md), [`security-audit`](.claude/skills/security-audit/SKILL.md), [`statamic-addon-lifecycle`](.claude/skills/statamic-addon-lifecycle/SKILL.md), [`statamic-conventions`](.claude/skills/statamic-conventions/SKILL.md), [`statamic-package-conventions`](.claude/skills/statamic-package-conventions/SKILL.md), [`synced-files`](.claude/skills/synced-files/SKILL.md), [`tests`](.claude/skills/tests/SKILL.md), [`update-dependencies-ticket`](.claude/skills/update-dependencies-ticket/SKILL.md), [`upstream-patch-lifecycle`](.claude/skills/upstream-patch-lifecycle/SKILL.md), [`writing-comments`](.claude/skills/writing-comments/SKILL.md), [`writing-commits`](.claude/skills/writing-commits/SKILL.md), [`writing-issues`](.claude/skills/writing-issues/SKILL.md), [`writing-pull-requests`](.claude/skills/writing-pull-requests/SKILL.md), [`writing-release-notes`](.claude/skills/writing-release-notes/SKILL.md).

Pre-commit guard: if `.githooks/pre-commit` exists, run `git config core.hooksPath .githooks` once per clone, or the hook never runs. The local CI runner warns when this is missing.
<!-- uams-claude-skills:end -->

## What this repo is

A **Composer package** — a Statamic addon that imports WordPress WXR exports into Statamic. It is **not** an application. There is no `.env`, no `artisan`, no served URL, no front end, no database of its own. Code is autoloaded as `UAMSWeb\WordPressImporter\` from `src/`, and the addon registers `UAMSWeb\WordPressImporter\ServiceProvider` plus one console command, `statamic:import:wordpress`.

It is consumed by the sibling **`UAMS-Web/uams-statamic`** app, which pins it by a tagged caret range — not `dev-main`. That pin is why releases here are load-bearing; see **Releases** below.

The practical consequence: anything that assumes a bootable app — Herd and `.test` URLs, Vite/npm, Inertia, `php artisan make:*`, Eloquent models, `route()` — does not apply here. If you are carrying a habit over from `uams-statamic`, check it against this repo first.

## Stack

PHP `^8.4`, `statamic/cms` **pinned exactly** to `6.35.0`, Pest `^5.0` on PHPUnit `^13.0`, Orchestra Testbench `^11.1`, Pint `^1.29`, PHPStan `^2.2` at **level max** with `phpstan-strict-rules` and `pest-plugin-phpstan`.

**Read the versions from [`composer.json`](composer.json) rather than from memory or from the host app.** The **test harness** drifts independently — this package and `uams-statamic` have bumped Pest, PHPUnit, Pint and PHPStan on different schedules more than once, and copying the app's assumptions has produced false statements before.

**`statamic/cms` is the exception, and it is deliberate.** It is an exact pin rather than a caret range so this package's CI resolves the same Statamic build the host app runs, keeping both on one `ueberdosis/tiptap-php` — the parser whose allowlist strips `<script>` out of imported content. That makes the two repos' Statamic versions *coupled*, not independent: the host cannot adopt a newer Statamic until this package is repinned and re-released, because Composer cannot satisfy two different exact requirements at once. Move the pin here first, then in the host.

**The pin does not, on its own, pin the parser** — `statamic/cms` requires `ueberdosis/tiptap-php: ^2.0`, so only `composer.lock` holds it steady, and a `composer update` in either repo can move it alone. The per-sweep assertion in the [`update-dependencies-ticket`](.claude/skills/update-dependencies-ticket/SKILL.md) skill is what actually catches that, which is why it is a gate and not a formality. Reasoning and rejected alternatives: [`docs/DECISIONS.md`](docs/DECISIONS.md).

## Running things

```bash
composer test    # pest --testsuite=Unit,Integration
composer stan    # phpstan analyse --memory-limit=-1
composer ci      # @stan, pint --test, @test — aborts at the first failure
```

**Remote CI runs on every pull request, and `main` requires three checks: `CI`, `Attribution footer` and `Closing keywords`** (#163). Validation also runs locally:

```bash
node scripts/ci-local.mjs                      # every job, in this checkout
node scripts/ci-local.mjs --jobs pint,spell    # a subset, plus the dependencies job
node scripts/ci-local.mjs --branch <branch>    # the pushed head, in the runner's own slot
node scripts/ci-local.mjs --dry-run            # the plan, and why each job runs
```

Unlike `composer ci`, the runner does not stop at the first failure. Read its summary block, not the exit code. [`docs/ci.md`](docs/ci.md) holds the rest: the `CI` job's `needs:` list and the `RUN_REMOTE_CI` switch, the runner's `--branch` slot and handoff, the `dependencies` job, what each gate job checks and does not, and the `ci.yml` and `local-ci.json` mirror the pre-commit hook guards.

## Tests

Three suites, split by how much they boot. Two standing test rules — which base class a test needs, and never asserting against the first match of a content query — live in [`tests/CLAUDE.md`](tests/CLAUDE.md), which auto-loads when you work under `tests/`. That auto-load is Claude Code's. Any other agent must read that file before working under `tests/`.

| Suite | Path | Boots | Runs in this package's CI |
| --- | --- | --- | --- |
| `Unit` | `tests/Unit/` | nothing — bare `PHPUnit\Framework\TestCase` | yes |
| `Integration` | `tests/Integration/` | minimal Statamic via Testbench (`Statamic\Testing\AddonTestCase`) | yes |
| `Feature` | `tests/Feature/` | the host app's `Tests\TestCase` | **no** |

`Feature` tests need the booted `uams-statamic` app and run from an isolated host worktree — the [`host-app-tests`](.claude/skills/host-app-tests/SKILL.md) skill is the setup and run procedure. **A wrong base class fails only in the host-app run** — never in standalone CI, never in PHPStan — so it is invisible to the local gate.

**The same blind spot swallows production code, not just tests.** Behavior that only `tests/Feature/` exercises is unverified by a green gate, and a grep showing standalone tests *reference* a class is not evidence they *run* it — six do for `ImportWordPress`, and none invokes the command. When you change such behavior, running the host subset before the PR is ready is obligatory: the [`host-only-behavior-needs-a-host-run`](.claude/rules/host-only-behavior-needs-a-host-run.md) rule.

Bare `vendor/bin/pest` picks up `tests/Feature/` and errors (`The class Tests\TestCase was not found`). Always scope to the suites.

New tests are Pest. For Pest syntax use the [`pest-testing`](.claude/skills/pest-testing/SKILL.md) skill; for converting an existing PHPUnit class use [`phpunit-to-pest`](.claude/skills/phpunit-to-pest/SKILL.md).

The WXR/forms fixtures live only in the private repository [`UAMS-Web/wordpress-importer-exports`](https://github.com/UAMS-Web/wordpress-importer-exports); this repository no longer carries them as a submodule (#1426). **Nothing in the gate needs them** (#1293). The two standalone tests that read the corpus (`TaxonomyDestinationTest`, `AdvertCorpusImportTest`) take a local clone from the `WORDPRESS_IMPORTER_EXPORTS` environment variable through [`tests/Support/ExportsCorpus.php`](tests/Support/ExportsCorpus.php), and skip when it is unset. A variable that names no directory fails the test rather than skipping it. The host-app `Feature` audits read the corpus through the host app's own configuration (`FRM_AUDIT_FIXTURES_DIR`, `GF_AUDIT_FIXTURES_DIR`), not through this package. A tree that still holds a clone at the old path keeps it: `.gitignore` covers that path, so it neither reads as untracked nor can be added back.

## PHP conventions

Enforced by Pint (Laravel preset, no `pint.json`) and PHPStan at level max. Beyond what those catch:

- Curly braces on every control structure, including single-statement bodies.
- Constructor property promotion for dependencies.
- Explicit parameter, return, and property types — including `void`. Level max will not accept an untyped surface.
- PHPDoc on every class, interface, trait, enum, and each top-level constant, property, method, and enum case. Two spaces after `@param`, single spaces after `@var` and `@return`, and no bare `@return void`.
- Single-line `//` comments before each logical step in multi-step code.
- Import FQCNs and keep imports ordered; prefer contract type hints (Statamic `Entry`, `QueryBuilder`) over concrete ones.
- Long-running import loops `unset()` and `gc_collect_cycles()` deliberately — this package processes multi-thousand-item exports in one process.

The full statements are the [`php-coding-standards`](.claude/skills/php-coding-standards/SKILL.md) and [`php-documentation`](.claude/skills/php-documentation/SKILL.md) skills. Don't restate them here.

**Pint is not advisory.** The tree is Pint-clean and CI lints it whole, so a single unformatted file fails the gate for everyone. Run `vendor/bin/pint --dirty` before committing. A `line_ending` failure is not a platform artifact to lint around: [`.gitattributes`](.gitattributes) pins `* text=auto eol=lf`, and a committed `eol` attribute outranks `core.autocrlf`, so tracked files are LF in the working copy on Windows and macOS alike. If Pint reports one, that file really is CRLF — the [`worktrees`](.claude/rules/worktrees.md) rule carries the single condition that produces it, the fix, and why `grep -c $'\r$'` cannot confirm it on Windows.

## The source corpora

Claims about what the WordPress sites contain are grounded in two corpora, and neither lives in this repository:

- **[`UAMS-Web/wordpress-importer-exports`](https://github.com/UAMS-Web/wordpress-importer-exports)** — the exported production *content*: a WXR per site (~262), plus every Gravity Forms, Formidable and Ninja Forms export and some pre-computed field audits. Point `WORDPRESS_IMPORTER_EXPORTS` at a clone of it to run the corpus tests. Answers "is this feature actually used, and on how many sites?"
- **A local `uamshealthmu` WordPress install** — the plugin and theme *source* the sites run on, roughly 140 plugins plus the `uams-2020` and `uams-2016` themes. Answers "which plugin owns this shortcode?" and "what shape does this post type have?"

They are not interchangeable: the install tells you how a thing works, the corpus whether anyone uses it. Reach for them before guessing, and cite them when recording a finding.

Both sit outside the repo at a per-machine path, so none is named here — record yours as `Read(...)` permissions in your git-ignored `.claude/settings.local.json`. **No local WordPress install yet?** The team's [`wp-to-herd`](https://github.com/UAMS-Web/uams-claude-skills/tree/main/wp-to-herd) skill stands one up in Herd on either platform, from a Local by Flywheel directory or a BigScoots backup, and covers the multisite case these sites need. It lives in [`UAMS-Web/uams-claude-skills`](https://github.com/UAMS-Web/uams-claude-skills), installed opt-in via that repo's `install.sh`.

Distinct from [`current-content-is-not-the-corpus`](.claude/rules/current-content-is-not-the-corpus.md), which is about not concluding from the *host app's* present `content/`. This is about where the WordPress-side evidence lives.

## Standing rules

**In Claude Code, `.claude/rules/*.md` auto-load as a directory**, so every file there is already in context; follow them without re-reading. **Any other agent must read them.** The generated section at the top of this file lists every synced rule with a one-line summary, and `.claude/rules/` is the complete set. Two rules are this repository's own, are not synced, and appear only here:

- [`adversarial-review-in-this-package`](.claude/rules/adversarial-review-in-this-package.md): where this importer hides a failure (`XpathHelper::getStringValue()`, `ImportWarningManager`, the memory a long loop releases), for the error-handling questions `adversarial-review` asks.
- [`current-content-is-not-the-corpus`](.claude/rules/current-content-is-not-the-corpus.md): a zero-result grep of the host app's `content/` is a lead, not a conclusion. Enumerate every writer (this importer, the CP, host-app code) before calling a data shape absent.

## Worktree slots

Branch work happens in one of a fixed set of long-lived, **pre-bootstrapped worktree slots**, never in the primary checkout, which holds `main` as the diff base. `../wordpress-importer-a` and `../wordpress-importer-b` rest detached at `origin/main` and are claimed one at a time; `../wordpress-importer-local-ci` belongs to the shared local CI runner, and `../wordpress-importer-ci` is the retired private runner's tree. **Claim with `claim`, which is atomic, rather than reading `status` and acting on it; refresh a slot whenever its base moves, before trusting a check run in it; and release it as the last act on a ticket.**

```bash
node scripts/worktree-slots.mjs claim --branch <N>-<slug>   # atomic; prints slot, then label
node scripts/worktree-slots.mjs status --check   # every tree: branch, free/held, drift (exit 1 = drift)
node scripts/worktree-slots.mjs refresh a
node scripts/worktree-slots.mjs release a        # clears the claim as well as detaching the tree
```

The full statement — provisioning, ephemeral worktrees, the file-tool-vs-cwd hazards, and teardown — is the [`worktrees`](.claude/rules/worktrees.md) rule.

## Releases

This repo cuts **real releases that another codebase consumes.** `uams-statamic` pins a tagged caret range, so merged work is **unreachable by the app until a tag exists** — and the cadence is genuinely tight (`v0.6.0`, `v0.6.1`, `v0.7.0` inside one week). A missed tag is not cosmetic: `^0.6` could not reach `v0.7.0`'s fix for a silent truncation that imported 103 of 1826 items.

Tag when not tagging would block progress — in practice, before the app adopts the work — and, as the one standing exception to that test, immediately on any breaking change. Everything is a `--prerelease` until `1.0.0`, which is Launch. The *when* is the [`cutting-releases`](.claude/rules/cutting-releases.md) rule; the *how* is [`README.md`](README.md#releasing) and the [`writing-release-notes`](.claude/skills/writing-release-notes/SKILL.md) skill; the versioning rationale is [`docs/DECISIONS.md`](docs/DECISIONS.md).

## Writing it up

Issues → [`writing-issues`](.claude/skills/writing-issues/SKILL.md). Pull requests → [`writing-pull-requests`](.claude/skills/writing-pull-requests/SKILL.md) (open as a draft, flip to ready when the branch is ready for local CI). Commits → [`writing-commits`](.claude/skills/writing-commits/SKILL.md).

## Instructions outside this file

| Where | What it is | Claude Code | Any other agent |
| --- | --- | --- | --- |
| [`.claude/rules/*.md`](.claude/rules) | Standing rules: the synced ones listed in the generated section, this repository's own under [Standing rules](#standing-rules) | loads automatically | read them |
| [`tests/CLAUDE.md`](tests/CLAUDE.md) | Test base classes and content-query assertions | loads when working under `tests/` | read it before working under `tests/` |
| [`.claude/skills/*/SKILL.md`](.claude/skills) | Procedures, read when the task matches | offered by name, read on use | read the matching one |
| [`.cursor/rules/*.mdc`](.cursor/rules) | The synced rules, delivered to Cursor; the same text as the synced files in `.claude/rules/`. The repository's own rules (`current-content-is-not-the-corpus`, `adversarial-review-in-this-package`) are not delivered there, so Cursor reads them through [Standing rules](#standing-rules) | **not read** | Cursor applies them |
| [`.cursor/`](.cursor), the rest | Cursor-only commands, a skill and MCP configuration | **not read** | Cursor only |

**The skills are procedures for a particular task, not standing rules.** Open the one that matches the work: the generated section lists the synced skills, and [`laravel-statamic-conventions`](.claude/skills/laravel-statamic-conventions/SKILL.md) is this repository's own.

**The `.claude/` rules and skills govern every agent, Cursor included (#1275).** There are no Cursor-only rules. `.cursor/rules/` holds `<name>.mdc` copies of the synced rules (#1320), edited in `UAMS-Web/uams-claude-skills` like every synced file; the repository's own two rules are not among them, so Cursor reads those through [Standing rules](#standing-rules). The rest of `.cursor/` is four commands under [`.cursor/commands/`](.cursor/commands), one skill ([`tailwindcss-development`](.cursor/skills/tailwindcss-development/SKILL.md)) and an MCP configuration. Claude Code reads none of it.

## Further reading — these do NOT auto-load

These are pointers for any agent; none of them loads automatically. Open the ones you need:

- [`.claude/history/`](.claude/history) — the incident history, measurements and worked examples behind each standing rule, one file per rule, moved out of the auto-loaded rules by wordpress-importer-archive#1257. Each rule links to its own history file; read it when a rule is questioned.
- [`docs/DECISIONS.md`](docs/DECISIONS.md) — the cross-ticket decision log (versioning, triage flavors, test base-class and boot model).
- [`docs/technical-reference.md`](docs/technical-reference.md) — importer internals.
- [`docs/performance/`](docs/performance) — import performance work.
- [`docs/migration-api-contract.md`](docs/migration-api-contract.md) — the migration API's endpoints, pagination and query semantics, verified against a running install. Includes what `?site=` is declared on versus refused by, that `/options` is not paginated, and the `exposed`-reflects-plugin-activation trap.
- [`docs/runbooks/migration-api-secret.md`](docs/runbooks/migration-api-secret.md) — the migration API shared secret: generate, place, verify, rotate, remove. **Read it before any command that touches `wp-config.php` or a host `.env`** — every auth failure returns an identical `401`, and a careless `grep -n` prints the credential into the transcript.
- [`README.md`](README.md) — install, usage, mappings, safeguards, fixtures, releasing.
