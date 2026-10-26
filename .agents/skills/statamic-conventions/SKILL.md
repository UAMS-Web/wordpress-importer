---
name: statamic-conventions
description: >-
  Conventions for every Laravel repository built on Statamic, whether an application that boots
  on its own or an add-on package that runs inside one: read versions from `composer.json` rather than from
  memory, follow sibling files, activate the PHP documentation, coding-standards and commit skills
  alongside this one, test every change (happy, failure and edge paths; self-skipping tests for
  resources outside the repository; Faker per the file's convention), run Pint before finalizing,
  and flash Statamic Control Panel success messages with `$this->success(...)` instead of the
  undeclared `RedirectResponse::withSuccess()` macro. Activate whenever working in a Statamic
  codebase, together with whichever companion this repository carries:
  `statamic-app-conventions` in an application, `statamic-package-conventions` in an add-on.
---
<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/statamic-conventions/SKILL.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): statamic.
-->
# Statamic conventions

The conventions that hold in every Statamic repository here, application or add-on. They
supplement the PHP rules in `php-coding-standards` and `php-documentation`.

This repository also carries one of two companion skills, and it holds the conventions that
depend on what kind of repository this is:

- `statamic-app-conventions` in an application that boots on its own (it has `artisan`, `bootstrap/app.php` and
  a served URL): Laravel Boost, `php artisan make:*`, Eloquent, routes, configuration and
  `php artisan test`.
- `statamic-package-conventions` in an add-on or package that a host application boots (no
  `artisan`, no `.env`, no served URL): the service provider, configuration read from the host,
  and testing with and without the host.

Activate the companion together with this skill.

## 1. Stack versions

`composer.json` / `composer.lock` and `package.json` are the source of truth, and
`composer show --direct` lists the installed majors. Use APIs that exist in the installed versions,
and do not repeat pins here, where they rot. Testing is Pest (see §5).

## 2. Skills you must activate alongside this one

This project has domain-specific skills available. You MUST activate the relevant skill whenever
you work in that domain; don't wait until stuck:

- `php-coding-standards`: any PHP code edit.
- `php-documentation`: any PHPDoc, inline comment, or `Log::*` edit.
- `writing-commits`: any commit message you author.

## 3. Conventions

- Follow all existing code conventions. Before creating/editing a file, check sibling files for
  structure, approach, and naming.
- Use descriptive names: `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components before writing new ones.
- Stick to existing directory structure; don't create new base folders without approval.
- Don't change the repository's dependencies without approval.
- **Documentation files**: only create `.md` files if explicitly requested by the user.
- **Replies**: be concise; focus on what's important rather than obvious details.
- **Verification scripts**: do not create one when tests cover the functionality and prove it
  works. Unit and feature tests are more important.

## 4. Statamic CP success flashing: no magic helpers

Patterns like `return back()->withSuccess($message)` call a method **not** declared on
`Illuminate\Http\RedirectResponse`. It's usually satisfied via a macro or `__call`, so static
analysis reports "unknown method" and IDEs can't verify the API.

### Preferred pattern for CP controllers

Controllers extending `Statamic\Http\Controllers\CP\CpController` (in an application, the ones
under `app/Http/Controllers/CP/`) inherit `Statamic\Http\Controllers\Controller::success()`, which
flashes the CP-expected session keys (`success`, optional `success_text`). Use the helper, then
return a normal redirect:

```php
$this->success(__('Saved.'));

return back();
```

Or `return redirect()->route(...)` after `$this->success(...)` when not using `back()`.

### Rules of thumb

- Prefer **`$this->success($message)`** + **`return back()`** (or `redirect()`) over
  `RedirectResponse::withSuccess()`.
- For a different flash shape, use `session()->flash(...)` with an explicit, documented key, not an undeclared redirect macro.
- Statamic core controllers that still use `back()->withSuccess()` are legacy style. **New code**
  uses `$this->success()` for analyzability.

Reference: `Statamic\Http\Controllers\Controller::success()` in `vendor/statamic/cms`.

The broader "avoid magic" pattern (macros / `__call` / fluent undocumented helpers) lives in the
`php-coding-standards` skill.

## 5. Test enforcement (Pest)

- **Every change must be programmatically tested.** Write a new test or update an existing one,
  then run the affected tests until they pass.
- Tests should cover happy paths, failure paths, and edge cases.
- Do **not** remove tests or test files without explicit approval: they are core to the
  repository.
- After updating a test, run that singular test.
- When the feature's tests pass, ask the user if they'd like to run the full suite.
- **Tests depending on resources outside the repo** (external fixtures, a local sibling-repo
  checkout, an optional service, an uninitialized submodule): call `$this->markTestSkipped(...)`
  with a message naming what to set or initialize when the resource is unset or missing. A
  self-skipping test stays **green on CI** runners that lack the resource while still running
  locally. Verify both paths: run once with the resource present (passes) and once without
  (`1 skipped`, not a failure).

### Running tests

- Run the minimum number of tests needed for the change. Use a file path or `--filter`. The
  commands are in the companion skill.

### Test data

- **Faker**: use `$this->faker->word()` or `fake()->randomDigit()` per existing convention in
  the file.

## 6. Pint (formatter)

- Run `vendor/bin/pint --dirty --format agent` before finalizing PHP changes.
- **Do not** use `--test`. Run `vendor/bin/pint --format agent` to fix issues.
- Pint enforces `ordered_imports` (alpha sort, class → function → const order): see the
  `php-coding-standards` skill.
