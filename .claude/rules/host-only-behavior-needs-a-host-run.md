<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/rules/host-only-behavior-needs-a-host-run.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): laravel-package.
-->
# Rule — behavior only the host app exercises needs a host-app run before the PR is ready

When a change touches behavior that **only `tests/Feature/` exercises**, run that file in the host app before flipping the PR to ready. The standalone gate cannot see it, and a green `composer test` says nothing about it.

**The trigger is the behavior you changed, not the file you edited.** That distinction is the whole rule, and getting it backwards is what lets this ship.

## Why this is a standing order

**`composer test` is `--testsuite=Unit,Integration`.** `tests/Feature/` is host-app-bound and runs nowhere in the gate: not in `composer test`, not in the CI workflow, not under PHPStan.

History: [host-only-behavior-needs-a-host-run.md § Why this is a standing order](../history/host-only-behavior-needs-a-host-run.md#why-this-is-a-standing-order). The gate in question is [`ci.yml`](../../.github/workflows/ci.yml).

## The trap: a grep that says "covered" when nothing is

The obvious heuristic, *"does this file have a standalone test?"*, returns the wrong answer here, confidently.

**Coverage of a *file* is not coverage of the *behavior* you changed.** Ask what asserts the thing you altered, not what mentions the class.

**And a hand-run of the package inside the host is not a host-app test run either.** This is the same shape one level up, and it is the objection most likely to defeat this rule, because the honest answer to *"was it verified against the host app?"* can be **yes, several times**. A real run of a healthy input exercises the happy path. A control-flow change usually re-enters only through a branch behind a *skip* or *refusal* gate (an unsupported input, a missing configuration, a disabled feature), and a run of a healthy input never reaches one. That is why the evidence that counts is a **test file**: the test asserts the skip paths, and a hand-run demonstrates the happy one.

The worked example is #705, #707 and #709, which each recorded end-to-end imports against the host app on a real export, and each passed. The import loop re-enters only through a `continue`, and **every one of those sits behind a *skip* gate**: an unsupported Classic, Freeform or Details block; KB entries without topics; an unconfigured destination collection or global set; a disabled taxonomy. A real import of a healthy export never reaches one. #707's three cases (a named content type, an unnamed one, a refused one) all terminated normally, on a build carrying an infinite loop. That is why every entry in the surface table below names a **test file** rather than "run an import."

History: [host-only-behavior-needs-a-host-run.md § The trap: a grep that says "covered" when nothing is](../history/host-only-behavior-needs-a-host-run.md#the-trap-a-grep-that-says-covered-when-nothing-is).

## How to apply

1. **Name the behavior you changed**, then find what asserts it. If the only assertions live under `tests/Feature/`, this rule is in force.

2. **Treat a change to a host-only surface as triggering** unless you can name a standalone test that asserts the specific behavior. Command control flow and exit codes, entry or record creation and field mapping, asset and file handling, blueprint and notification mapping, and taxonomy or destination writes are the usual ones, because they need the booted application to mean anything.

   These surfaces are host-only in practice for this importer:

   | Surface | The file that exercises it |
   | --- | --- |
   | Import command control flow, content-type selection, exit codes | `ImportWordPressCommandTest` |
   | Entry creation and field mapping | `PostImportTest`, `KbImportTest`, `UamsPeopleImportTest`, `PageBuilderBlockImportTest` |
   | Asset and attachment handling, featured images | `LogoListItemImportTest`, `PostImportTest` |
   | Forms blueprint and notification mapping | the `*BlueprintImportTest` and `*NotificationAuditTest` files |
   | Taxonomy and term destinations | `TermCreationInNonDefaultSitesTest`, `PostImportTest` |

   The whole host-bound subset is 27 files.

3. **Run it.** The [`host-app-tests`](../skills/host-app-tests/SKILL.md) skill is the setup and run procedure. Scope to the files your change actually touches; these are multi-minute runs, and running the whole subset for a narrow change is waste.

4. **Say so in the PR's `## Test plan`**, with the result. "Host app: `<File>` - 25 passed, 247.70s" is the evidence; "CI green" is not, because the gate never ran it.

5. **A red here is not automatically yours.** The subset carries pre-existing failures: revert-and-compare against the package's `origin/main` per the skill before attributing one to your branch.

## What this does not require

Not every package change. A parser, a sanitizer, a value object, a config shape, anything with real `Unit` or `Integration` assertions on the behavior in question, is covered by the gate, and this rule does not fire. Running every host-bound file for a change to a pure function is waste, and a rule that demands it is one people learn to skip.

## The DRY line

This file owns **when a host-app run is obligatory**. The setup, the slot, and result interpretation belong to the [`host-app-tests`](../skills/host-app-tests/SKILL.md) skill; which base class a test needs belongs to the [`statamic-package-conventions`](../skills/statamic-package-conventions/SKILL.md) skill, which covers the adjacent case (a *test* that runs nowhere because of a wrong base class) while this rule covers *production code* whose behavior runs nowhere. It composes with [`adversarial-review`](adversarial-review.md): a green suite earns the build, and here the suite is green precisely because it never looked.
