---
name: laravel-statamic-conventions
description: >-
  Project-specific Laravel 13 + Statamic 6 conventions and tooling: stack versions (PHP 8.4,
  Laravel 13, Statamic 6, Pint 1, PHPUnit 12 + Pest 4 with the test suite migrating PHPUnit→Pest,
  Alpine 3, Tailwind 4), Laravel Boost MCP usage
  (`search-docs`, `list-artisan-commands`, `tinker`, `database-query`, `browser-logs`,
  `get-absolute-url`), Herd-served URLs, `php artisan make:*` for new files, Eloquent / N+1 /
  Form Request / casts() / route() guidance, the streamlined Laravel structure
  (`bootstrap/app.php`, no `Http\Kernel.php`), Pint workflow, test enforcement (Pest for new tests,
  existing PHPUnit suite coexisting), and Statamic CP success flashing via `$this->success(...)` instead of magic
  `RedirectResponse::withSuccess()`. Activate whenever working in this Laravel + Statamic
  codebase — new controllers, models, jobs, console commands, Statamic computed properties,
  fieldtypes, CP controllers, or any test/Artisan/build workflow.
---

# Laravel 13 + Statamic 6 Conventions

This is the project's foundational context plus the Statamic-specific patterns. It supplements
the PHP rules in `php-coding-standards` and `php-documentation`.

## 1. Stack versions

Use APIs that exist in these exact versions (always confirm against `composer.json` /
`package.json`, which are the source of truth):

- **php** — `^8.4` (local runtime 8.4.21).
- **laravel/framework** — v13
- **laravel/prompts** — v0
- **statamic/cms** — v6 (this package pins `6.35.0` exactly, the same build the host app pins — see [`docs/DECISIONS.md`](../../../docs/DECISIONS.md))
- **statamic/eloquent-driver** — v5 (pinned `5.9.0`)
- **statamic/ssg** — v4
- **laravel/boost** — v2 (provides the Boost MCP server; pulls in `laravel/mcp`)
- **laravel/pint** — v1
- **laravel/sail** — v1
- **phpunit/phpunit** — v12 (existing Unit + Feature suite; migrating to Pest — see §9)
- **pestphp/pest** — v4 (new tests + the `tests/Browser` smoke suite — see §9)
- **alpinejs** — v3
- **tailwindcss** — v4 (see `tailwindcss-development` skill)
- **vite** — v8

## 2. Skills you must activate alongside this one

This project has domain-specific skills available. You MUST activate the relevant skill whenever
you work in that domain — don't wait until stuck:

- `tailwindcss-development` — any styling / CSS / Tailwind / UI work.
- `php-coding-standards` — any PHP code edit.
- `php-documentation` — any PHPDoc, inline comment, or `Log::*` edit.
- `writing-commits` — any commit message you author.

## 3. Conventions

- Follow all existing code conventions. Before creating/editing a file, check sibling files for
  structure, approach, and naming.
- Use descriptive names: `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components before writing new ones.
- Stick to existing directory structure; don't create new base folders without approval.
- Don't change the application's dependencies without approval.
- **Documentation files** — only create `.md` files if explicitly requested by the user.
- **Replies** — be concise; focus on what's important rather than obvious details.

## 4. Laravel Boost MCP — use it

Laravel Boost is an MCP server with tools designed for this application. Reach for these before
falling back to general approaches.

### `search-docs` (critically important)

- Use **before** making code changes when working with Laravel or Laravel-ecosystem packages.
  The tool returns **version-specific** docs for the installed packages.
- Use multiple, broad, **simple, topic-based queries** at once:
  `['rate limiting', 'routing rate limiting', 'routing']`.
- **Do not** include package names in queries (`test resource table`, not
  `filament 4 test resource table`). The tool already filters by installed package.
- Search syntax: auto-stemming on simple words; AND across multiple words; `"quoted phrases"`
  for exact-position match; mix the two; pass `queries=["a", "b"]` for OR.

### Other Boost tools

- `list-artisan-commands` — double-check available parameters before calling an Artisan command.
- `get-absolute-url` — generate any project URL you share with the user (correct scheme/host/
  port). The app is served by **Laravel Herd** at `https?://[kebab-case-project-dir].test`. Do
  not run commands to make the site available over HTTP(S); Herd already does that.
- `tinker` — execute PHP to debug code or query Eloquent models directly.
- `database-query` — read-only DB access when you don't need to run PHP.
- `browser-logs` — read browser logs, errors, exceptions. Only recent logs are useful.

### Verification scripts

- Do **not** create verification scripts or tinker sessions when tests cover the functionality
  and prove it works. Unit and feature tests are more important.

## 5. Doing things the Laravel way

- Use `php artisan make:*` to create new files (migrations, controllers, models, jobs, etc.).
  For a generic PHP class: `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands so they run without user input. Pass the
  correct `--options`.

### Database / Eloquent

- Always use proper Eloquent relationship methods with return type hints. Prefer relationships
  over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw DB queries.
- **Avoid `DB::`**; prefer `Model::query()`. Leverage Laravel's ORM rather than bypassing it.
- Prevent N+1 with eager loading.
- Use the query builder only for very complex DB operations.

### Models

- When creating new models, also create useful factories and seeders. Ask the user before
  adding anything else, using `list-artisan-commands` for the available options.
- Casts likely belong in a `casts()` method on the model rather than the `$casts` property.
  Follow existing conventions from other models.

### APIs

- Default to Eloquent API Resources and API versioning unless existing API routes diverge —
  then follow existing convention.

### Validation

- Always create **Form Request** classes for validation rather than inline validation in
  controllers. Include both rules and custom error messages.
- Check sibling Form Requests to see whether the application uses array- or string-based rules.

### Auth

- Use Laravel's built-in auth/authorization (gates, policies, Sanctum, etc.).

### URLs

- Prefer named routes and the `route()` function for generating links.

### Queues

- Use queued jobs with the `ShouldQueue` interface for time-consuming operations.

### Configuration

- Use `env()` **only inside config files**. Never call `env()` from app code; call
  `config('app.name')` instead of `env('APP_NAME')`.
- This applies to **test code too**. For a test-only, env-backed value (e.g. a path to external
  fixtures), add it to a dedicated config file — `config/testing.php` returning
  `['gf_audit_fixtures_dir' => env('GF_AUDIT_FIXTURES_DIR')]` — and read it with
  `config('testing.gf_audit_fixtures_dir')`. This keeps the test green under `config:cache`
  (where bare `env()` returns `null`) and avoids committing machine-specific defaults in code.
  Document the variable as a commented, per-developer entry in `.env.example` (no shared default
  when the value is a personal absolute path).

## 6. Laravel streamlined structure

- Middleware is **not** registered in `app/Http/Kernel.php` (that file no longer exists).
- Configure middleware declaratively in `bootstrap/app.php` via
  `Application::configure()->withMiddleware(...)`.
- `bootstrap/app.php` registers middleware, exceptions, and routing files.
- `bootstrap/providers.php` lists app-specific service providers.
- `app\Console\Kernel.php` no longer exists — use `bootstrap/app.php` or `routes/console.php`
  for console configuration.
- Console commands in `app/Console/Commands/` are auto-registered.

### Migrations

- When modifying a column, the migration must include all attributes previously defined on the
  column. Otherwise they will be dropped.

### Eager-load limits

- Laravel supports limiting eagerly-loaded records natively:
  `$query->latest()->limit(10);` — no external package required.

### Vite error

- "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" → run
  `npm run build`, or ask the user to run `npm run dev` / `composer run dev`.

## 7. Frontend bundling

If the user doesn't see a frontend change in the UI, they may need to run `npm run build`,
`npm run dev`, or `composer run dev`. Ask.

## 8. Statamic CP success flashing — no magic helpers

Patterns like `return back()->withSuccess($message)` call a method **not** declared on
`Illuminate\Http\RedirectResponse`. It's usually satisfied via a macro or `__call`, so static
analysis reports "unknown method" and IDEs can't verify the API.

### Preferred pattern for CP controllers (`app/Http/Controllers/CP/**/*.php`)

Controllers extending `Statamic\Http\Controllers\CP\CpController` inherit
`Statamic\Http\Controllers\Controller::success()`, which flashes the CP-expected session keys
(`success`, optional `success_text`). Use the helper, then return a normal redirect:

```php
$this->success(__('Saved.'));

return back();
```

Or `return redirect()->route(...)` after `$this->success(...)` when not using `back()`.

### Rules of thumb

- Prefer **`$this->success($message)`** + **`return back()`** (or `redirect()`) over
  `RedirectResponse::withSuccess()`.
- For a different flash shape, use `session()->flash(...)` with an explicit, documented key —
  not an undeclared redirect macro.
- Statamic core controllers that still use `back()->withSuccess()` are legacy style. **New app
  code** uses `$this->success()` for analyzability.

Reference: `Statamic\Http\Controllers\Controller::success()` in `vendor/statamic/cms`.

The broader "avoid magic" pattern (macros / `__call` / fluent undocumented helpers) lives in the
`php-coding-standards` skill.

## 9. Test enforcement (migrating PHPUnit → Pest)

- **Every change must be programmatically tested.** Write a new test or update an existing one,
  then run the affected tests until they pass.
- **The suite is migrating from PHPUnit to Pest; both coexist.** Write **new** tests in **Pest**
  (`php artisan make:test --pest {name}` — the `{name}` omits the suite dir, e.g.
  `SomeFeatureTest`, not `Feature/SomeFeatureTest`).
- The existing `Unit` + `Feature` **PHPUnit class tests are still valid** and still run under
  `php artisan test`. Do **not** mass-convert them to Pest — port a test opportunistically only
  when you're already modifying it. Converting Pest → PHPUnit is backwards; don't.
- **`tests/Browser` is a dedicated Pest suite** (headless CP browser-smoke tests, issue wordpress-importer-archive#852,
  via `pestphp/pest-plugin-browser`, bootstrapped by `tests/Pest.php`). It is **not** registered
  as a PHPUnit testsuite, so `php artisan test` never runs it — run it explicitly with
  `vendor/bin/pest tests/Browser`. Add browser smoke tests here as `it(...)`/`beforeEach(...)`
  using the shared `tests/Support/CpSmoke.php` helpers.
- Tests should cover happy paths, failure paths, and edge cases.
- Do **not** remove tests or test files without explicit approval — they are core to the app.
- After updating a test, run that singular test.
- When the feature's tests pass, ask the user if they'd like to run the full suite.
- **Tests depending on resources outside the repo** (external fixtures, a local sibling-repo
  checkout, an optional service): resolve the path/flag from config (env-backed, per the
  Configuration rule above) and call `$this->markTestSkipped("… set GF_AUDIT_FIXTURES_DIR")`
  when it's unset or missing. A self-skipping test stays **green on CI** runners that lack the
  resource while still running locally. Verify both paths — run once with the var set (passes)
  and once without (`1 skipped`, not a failure).

### Running tests

- Run the minimum number of tests needed for the change. Use a file path or `--filter`.
- All tests: `php artisan test --compact`.
- One file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- Filter: `php artisan test --compact --filter=testName`.
- Browser smoke suite (Pest, not part of `php artisan test`): `vendor/bin/pest tests/Browser`.

### Test data

- Use **model factories** when creating models for tests. Check for **custom states** before
  manually setting up a model.
- **Faker**: use `$this->faker->word()` or `fake()->randomDigit()` per existing convention in
  the file.
- Most tests should be feature tests; pass `--unit` to `php artisan make:test` only when you
  want a unit test.

## 10. Pint (formatter)

- Run `vendor/bin/pint --dirty --format agent` before finalizing PHP changes.
- **Do not** use `--test`. Run `vendor/bin/pint --format agent` to fix issues.
- Pint enforces `ordered_imports` (alpha sort, class → function → const order) — see the
  `php-coding-standards` skill.
