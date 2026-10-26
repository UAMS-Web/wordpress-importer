<!--
  Skeleton only — .claude/skills/writing-pull-requests/SKILL.md is the source of truth.
  Delete the sections you don't need, and delete these comments before submitting.

  Title — it renders verbatim into the GitHub Release notes, so write it for someone
  skimming "what shipped": imperative, verb-first, no trailing period, no Conventional-Commit
  prefix, and no internal-process references (no `Wave N`, `Group X`, `Iteration N`, batch or
  merge-order hints). Correct acronym casing; "and", never "&".

  Before opening: pass the local gate — `composer test`, `vendor/bin/pint --test`, and
  `vendor/bin/phpstan analyse` — plus the host-app-bound tests via the `host-app-tests` skill
  when the change touches their surface. Open as a DRAFT until it has (`gh pr create --draft`,
  then `gh pr ready <n>`); a docs-only PR opens ready.

  Closing multiple issues? Repeat the keyword — `Closes #1, Closes #2.` A comma-separated
  list links only the FIRST issue.
-->

Closes #

<!-- One to three sentences: what changed, and why now. Lead with the user-visible effect or
     the root cause, not the implementation. -->

## The bug

<!-- Fix PRs only. Walk the root cause before the fix. -->

## Approach

<!-- When a path was chosen over alternatives, or reviewers need the design rationale. -->

## Files

<!-- - **edit** [`path/to/file.php`](https://github.com/UAMS-Web/wordpress-importer-archive/blob/<branch>/path/to/file.php) — reason. -->

## Verification

<!-- Evidence gathered before opening. If something couldn't be verified, say so plainly. -->

## Companion PRs

<!-- Sibling PRs in uams-statamic, uams-statamic-content, etc. State the merge order and the
     one-line reason. An importer change the app consumes also needs a release tag before the
     app can require it — see .claude/rules/cutting-releases.md. -->

## Out of scope

## Follow-up

## Test plan

<!-- Mandatory, and always last. `- [x]` = verified locally; `- [ ]` = deferred to CI, review,
     or a live import run. Name the command, fixture, or observation. Host-app-bound tests
     (`tests/Feature/`) don't run in this package's CI — say so and record the host-app run. -->

- [ ] CI green (Pest, Pint, PHPStan).
