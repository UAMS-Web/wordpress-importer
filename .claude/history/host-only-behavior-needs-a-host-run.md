# History — behavior only the host app exercises needs a host-app run before the PR is ready

The incident history, measurements and worked examples behind [`host-only-behavior-needs-a-host-run.md`](../rules/host-only-behavior-needs-a-host-run.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`host-only-behavior-needs-a-host-run.md`](../rules/host-only-behavior-needs-a-host-run.md); this file is read when a rule is questioned, not on every turn.

## Why this is a standing order

Three consecutive PRs — [#705](https://github.com/UAMS-Web/wordpress-importer-archive/pull/705), [#707](https://github.com/UAMS-Web/wordpress-importer-archive/pull/707), [#709](https://github.com/UAMS-Web/wordpress-importer-archive/pull/709) — changed exit-code and control-flow behavior in [`src/Console/Commands/ImportWordPress.php`](../../src/Console/Commands/ImportWordPress.php). All three passed the gate. None was ever run against `ImportWordPressCommandTest`, the file that exercises exactly that behavior.

wordpress-importer-archive#707 removed [#389](https://github.com/UAMS-Web/wordpress-importer-archive/issues/389)'s loop guards on reasoning that was wrong about *which statement ends the loop*. The result was an infinite loop that exhausted 1 GB of `BufferedOutput`; a single test case ran **10 minutes at 98.5% CPU** before being killed. It merged. wordpress-importer-archive#709 merged on top of it. It was found days later only because someone ran the host subset deliberately — [#713](https://github.com/UAMS-Web/wordpress-importer-archive/issues/713) / PR [#714](https://github.com/UAMS-Web/wordpress-importer-archive/pull/714).

## The trap: a grep that says "covered" when nothing is

Six files under `tests/Unit/` and `tests/Integration/` reference `ImportWordPress`. **None of them runs the command.** No `Artisan::call`, no `->run()`, no exit-code assertion; they reference the class for constants and helpers. So the file reads as covered and its control flow is exercised nowhere but the host app.
