# WordPress Importer for Statamic

Import WordPress XML exports into Statamic with an interactive Artisan command.

This addon is designed for real migration work: it parses WordPress exports, lets you choose what to import, maps content into Statamic collections/globals/navigation, imports related assets, and helps you catch problematic content patterns before import.

## Documentation

- **This file** — requirements, install, quick usage, mappings, troubleshooting
- **[Technical reference](docs/technical-reference.md)** — full command workflow (scans, completion), class catalog, config/env flags, mixed content, modules template, and other internals
- **[Migration API contract](docs/migration-api-contract.md)** — endpoints, pagination, query semantics and the places a request succeeds while returning less than you asked for
- **[Migration API secret runbook](docs/runbooks/migration-api-secret.md)** — generating, placing, verifying, rotating and removing the credential the `--source=api` import needs

## Requirements

- PHP `^8.4`
- Statamic `statamic/cms` **`6.35.0`, pinned exactly** — not a caret range. The exact pin makes this package resolve the same Statamic build the host app runs, which keeps both on one `ueberdosis/tiptap-php`; moving it is a coupled change made here first and then in the host. See [`CLAUDE.md`](CLAUDE.md).

## Installation

Install with Composer:

```bash
composer require uams-web/wordpress-importer
```

The addon service provider is auto-discovered by Laravel/Statamic.

**Code layout:** this repository’s PHP lives under `src/` (Composer package `uams-web/wordpress-importer`). The import command class is `UAMSWeb\WordPressImporter\Console\Commands\ImportWordPress`, registered by `UAMSWeb\WordPressImporter\ServiceProvider`.

## Setting up the migration API (only for `--source=api`)

**Skip this if you are importing a WordPress XML file.** It applies only to reading content directly from a WordPress site through the [UAMS migration API plugin](https://github.com/UAMS-Web/uamswp-migration-api) — used for content a WXR export cannot carry, such as site settings and current Find-a-Doc values.

You need the plugin active on the WordPress side, a credential issued there, and that credential pasted into the import when it runs. **Nothing is stored in this application** — see step 3.

The plugin accepts two credentials on the same header. **A per-network token, issued from the plugin's own admin page, is the one this migration uses** — decided on [`UAMS-Web/uamswp-migration-api-archive#140`](https://github.com/UAMS-Web/uamswp-migration-api-archive/issues/140). The shared constant below still authenticates and is not deprecated; it is the older path and the simpler one for a single local site you control. Because both ride the same header, nothing on this side changes between them.

### 1. Issue a token, on the WordPress side

The page is registered with the same capability expression the API itself uses, so the two cannot drift:

| | Menu | Capability |
| --- | --- | --- |
| Multisite | **Network Admin → Settings → Migration API token** | `manage_network_options` |
| Single site | **Settings → Migration API token** | `manage_options` |

Press **Generate token**. The value is shown **once** — *"Copy this token now. It is not shown again and is not recoverable."* — as 64 hex characters, beside a short **identifier** that names the token afterwards without revealing it. The plugin stores only a hash, so a lost token is regenerated rather than recovered.

**Regenerating keeps the previous token working for an hour by default** (`Token::ROTATION_WINDOW`, 3600 seconds), and the page names the old identifier and when it stops being accepted. The duration is filterable — the plugin passes it through `uamswp_migration_api_token_rotation_window`, and a site that filters it to zero removes the overlap entirely, so read the page rather than assuming the hour. So rotating a token is not a synchronized edit — see *Rules for the credential* below.

### 2. Or define the shared secret — the older path

For a single local site, or where there is no admin page to reach, the plugin also accepts a constant.

```bash
openssl rand -hex 32
```

It is a **single value, generated once and placed on the WordPress side only.** The importer never stores a copy; you paste it when a run asks. If what you paste differs from what is defined by one character, every request fails.

In that site's `wp-config.php`, **above** the `/* That's all, stop editing! Happy publishing. */` line:

```php
define( 'UAMSWP_MIGRATION_API_SECRET', 'the-value-you-just-generated' );
```

**Do only one of these two.** Either credential authenticates on its own; issuing a token does not disable the constant, and defining the constant does not revoke a token.

### 3. Put nothing in this app

**The address and the credential are not configured here, and there is no step that adds them.** An import asks for both when it starts:

```
Which network? Paste its WordPress address, including https://
  Reading: https://your-wordpress-site.test
Paste that network's migration API key
```

The key is typed hidden and exists only for the length of the run. **No option carries the key as a value**, deliberately — that would put a live credential in shell history and in the process list, where it outlives the run.

For a run with no terminal attached, `--key-from-stdin` reads it from the first line of standard input, which is neither shell history nor the process list. It requires `--network`, because a key pasted for one network must not be sent to whichever address a prompt would otherwise have asked for.

**The shell you pipe from decides whether the key arrives intact.** The reader strips a trailing `\r\n`, so a trailing newline is not a problem — but it does not strip a byte-order mark, and one common shell adds one. Measured on Windows 11 against a fixture of exactly 8 bytes:

| Shell | Form | Delivered |
| --- | --- | ---: |
| Git Bash | `printf '%s' "$KEY"` | 8 — exact |
| `cmd.exe` | `< file` redirect | 8 — exact |
| `cmd.exe`, pwsh 7 | `echo` into a pipe | 10 — trailing `0d0a`, trimmed by the reader |
| **PowerShell 5.1** | pipe | **13 — a leading three-byte BOM (`U+FEFF`)**, which is *not* trimmed |

**Use `printf '%s'` from bash, or a file redirect.** A key piped from PowerShell 5.1 arrives with three invisible leading bytes, and setting `$OutputEncoding` to UTF-8-without-BOM **does not remove them** — so the obvious remedy leaves the failure in place and gives no sign it did nothing.

The symptom is a `401`, and the API returns the identical `401` for an unset constant, a wrong key and a correctly-refused anonymous request. On an unattended run there is nobody watching to tell them apart, which is why the shell is worth getting right rather than discovering.

To hold a key in a shell without it reaching history, read it rather than assigning it — the value arrives as input instead of as an argument:

```bash
printf 'Key: '; read -rs KEY; echo
printf '%s' "$KEY" | php artisan statamic:import:wordpress … --key-from-stdin
```

**Read the echoed address before pasting the key.** It is the only thing in the run that names the network, and `--site` cannot serve that purpose: a blog id is unique only within its own network, so the same id names a different site on each.

The estate is 26 separate multisite networks, each issuing its own key, which is why nothing is stored — configuration able to address them all would hold 26 live credentials on disk for a job that reads one network at a time (wordpress-importer-archive#982).

**One key is still host-app configuration**, because it is neither a credential nor a network identity:

```env
UAMSWP_MIGRATION_API_PER_PAGE=100
```

```php
'migration_api' => [
    'per_page' => env('UAMSWP_MIGRATION_API_PER_PAGE', 100),
],
```

It is optional and defaults to 100; the API clamps it to its own maximum of 200, advertised on the index endpoint.

### 4. Check it worked — with three requests, not one

This is the step people skip, and it is the one that saves the afternoon. **Every failure here looks identical**: an unset secret, a mismatched secret, a typo, and a correctly-refused anonymous request all return the same `401`. A single passing request cannot tell you the secret is actually being *checked*.

```bash
API="https://your-wordpress-site.test/wp-json/uamswp-migration/v1"
printf 'Key: '; read -rs SECRET; echo

curl -s -o /dev/null -w 'correct key: %{http_code}
' -H "X-UAMSWP-Migration-Key: $SECRET" "$API/"
curl -s -o /dev/null -w 'no key:      %{http_code}
' "$API/"
curl -s -o /dev/null -w 'wrong key:   %{http_code}
' -H "X-UAMSWP-Migration-Key: nope" "$API/"
```

Expect **200**, **401**, **401** in that order. The wrong-key line is the important one — without it, a broken guard that accepted any header would still look correct.

**`read` rather than `SECRET=…` is the point of that second line, not a style choice.** An assignment puts the credential in your shell history, which is the exposure the run itself avoids by never taking the key as an option — see step 3 above, and *Rules for the credential* below. Reading it means the value arrives as input rather than as an argument, so the command is recorded and the key is not. `-s` keeps it off the screen; the `echo` supplies the newline that suppressed.

**The prompt is a separate `printf` on purpose — do not fold it back into `read -p`.** That flag prompts in bash and means *read from a coprocess* in zsh, which is the macOS default login shell: the variable is simply never assigned, `curl` then sends an empty key, and the API answers the same `401` this section exists to disambiguate. A broken verification step that reports the failure it is testing for is worse than no step at all. The split form was measured working in both shells.

If the first line fails with `SSL certificate problem: self-signed certificate`, that is a local `.test` host and `curl -k` will skip the check. **Add it only for a local host you control, and never for a real one** — the first request carries the secret, and disabling certificate verification means nothing has authenticated whoever receives it.

### Then run an import

```bash
php artisan statamic:import:wordpress --source=api
```

### Rules for the credential

- **Never commit it**, and never paste it into a ticket, pull request, commit message or chat. A token's **identifier** is the safe thing to quote — it names which credential without being one.
- **Never print it** — `grep -n` on `wp-config.php` will put it in your terminal history and in any log capturing that output. Use `grep -c` to check only whether it is set.
- **Rotation differs between the two, and this is the one rule that is not shared.** Regenerating a **token** keeps the previous one accepted for an hour by default, so the consumer can be updated without a refused request in between — the window is filterable per-site, so the page's stated expiry is the authority rather than the number here. The **shared constant** has no grace period: both copies change together and requests in flight during the edit fail.
- **Remove it when the migration is done.** The plugin is explicitly temporary.

Full procedure, including how to place the value without any person or tool seeing it: **[the migration API secret runbook](docs/runbooks/migration-api-secret.md)**.

## Usage

Run the import command with a WordPress XML export file:

```bash
php artisan statamic:import:wordpress /path/to/export.xml
```

Command signature:

```bash
statamic:import:wordpress [<file>] [--source=wxr|api] [--site=<id>] [--modified-after=<when>]
                          [--network=<url>] [--key-from-stdin]
                          [--unsupported-content=abort|skip|import] [--content-type=<type>]
                          [--minerva-generation=v1|v2] [--verify]
```

`<file>` is **optional**: with `--source=wxr` it is the export to read, and with `--source=api` it is the directory the run materializes WXR into.

| Option | Default | What it does |
| --- | --- | --- |
| `--source` | `wxr` | Where content comes from: `wxr` (a local export file) or `api` (the migration API). |
| `--site` | — | The network site id to read, with `--source=api`. A positive whole number; anything else refuses the run. |
| `--modified-after` | — | Read only posts modified after this timestamp, with `--source=api`. `YYYY-MM-DD`, `"YYYY-MM-DD HH:MM"` (GMT, seconds optional), or ISO 8601; anything else is refused by the API. |
| `--network` | — | The network WordPress address to read, with `--source=api`. An absolute `https://` URL, supplying without a prompt what the run would otherwise ask for. Required by `--key-from-stdin`. |
| `--key-from-stdin` | off | Read the network's migration API key from the first line of standard input instead of asking for it, so an API-sourced run needs no terminal. Requires `--network`. |
| `--unsupported-content` | `abort` | Non-interactive policy for content carrying unsupported blocks (Classic, Freeform, Details), or KB entries without topics: `abort`, `skip` or `import`. |
| `--content-type` | — | The single content type to import, for an unattended run. Without it a non-interactive run imports the first supported type, reports the rest as untouched, and exits non-zero. |
| `--minerva-generation` | — | The MinervaKB generation the site runs, `v1` or `v2`, with `--source=api`. Where the migration API serves the installed plugin's header, read from disk, its `Text Domain` decides the generation and the option is not needed; a declaration that header contradicts is refused. Otherwise it is required to import from a site that serves both generations' settings rows, which is refused without it. Read it from the network plugins page (`wp-admin/network/plugins.php`) before the run. A declared generation whose row was not served is refused, and so is the option on a file-sourced run. |
| `--verify` | off | Run the whole import against a temporary copy of the content and assets, print the same counts, and leave this application's own directories unchanged. See [Verification runs](#verification-runs). |

**`--site` does not name a network**, only a site within one — a blog id is unique only inside its own network, so the same id means a different site on each. The network comes from `--network`, or from the address you paste at the prompt when that option is absent.

### Debugging

To clear Laravel’s cache and truncate the log before a run:

- **macOS / Linux:**
  ```bash
  php artisan cache:clear && > storage/logs/laravel.log && php artisan statamic:import:wordpress /path/to/export.xml
  ```
- **Windows (cmd):**
  ```bash
  php artisan cache:clear && echo. > storage\logs\laravel.log && php artisan statamic:import:wordpress /path/to/export.xml
  ```

## What It Imports

Supported WordPress content types:

- `page`
- `post`
- `uamspeople`
- `redirect_rule`
- `tablepress_table`
- `kb` (MinervaKB articles)
- `nav_menu_item` (navigation menu items)

If other post types are present in the XML, they are reported but not imported.

## Default Destination Mappings

- `page` -> `pages` collection
- `post` -> `post` collection
- `uamspeople` -> `people` collection
- `redirect_rule` -> `redirects` global set
- `tablepress_table` -> `tables` collection
- `nav_menu_item` -> `primary_navigation`
- `kb` -> `knowledge_bases` collection

When a default mapping is not available, the command prompts for a destination.

## Import Workflow

During each run, the command:

1. Validates the XML file path.
2. Scans the export and shows detected content types/counts.
3. Prompts for target site and content type.
4. Uses default mapping (or asks for destination).
5. Prompts for overwrite behavior (entries/assets/tables/redirects when applicable).
6. Imports content and related assets.
7. Validates/enables required taxonomies and site configuration.
8. Builds collection trees when needed.
9. Optionally repeats for additional content types from the same file.

When you finish importing (you answer **no** to importing more content types), the command runs finalization: clears the Stache, runs deferred work including navigation import where applicable, optionally measures codebase file size change (see `WORDPRESS_IMPORT_MEASURE_FILESIZE` in `docs/technical-reference.md` → Configuration), clears relevant caches, runs Bard/YAML sanitization on collection entries, shows warnings, and completes.

Between imports, you can import additional content types from the same XML in a loop. Prompts use **Laravel Prompts** where supported (macOS, Linux, WSL), with a fallback to Symfony Console questions elsewhere.

## Built-In Safeguards

Before import, the command warns about content patterns that typically need manual review:

- Classic editor content (no Gutenberg block wrappers)
- WordPress Row blocks
- WordPress Details blocks
- WordPress Freeform blocks
- MinervaKB entries without required topic assignments

You can choose whether to continue after warnings are shown.

## Verification runs

`--verify` answers "what would this import do?" without doing it. The run reads and processes everything a real import does and prints the same summary lines, and for each redirect domain host a line with the number of entries stored with a `wordpress_post_id`:

```bash
php artisan statamic:import:wordpress storage/app/imports/gouams-com --source=api --network=https://go.uams.edu --site=3 --content-type=redirect_rule --verify
```

**How it works.** At the start of the run the Stache stores, the blueprints, fieldsets and forms, and the disks behind the asset containers are copied to a directory under the system temporary directory, and this process is pointed at the copy, with its caches held in memory. Statamic's image cache moves with them. Database writes stay inside a transaction on the default connection and on each connection behind an Eloquent Statamic repository, and that transaction is rolled back. The copy is deleted when the run ends, whether it succeeds or fails. An import writes from too many places, and depends too much on what is already stored, for a run that wrote nothing to report the same counts.

- **The guarantee covers the content and asset directories, and the host's asset metadata directory (`asset_metadata.yaml_directory`).** They are not written to, even if the run fails or is killed. A killed run leaves its copy behind under the temporary directory (`wordpress-import-verify-*`), which is safe to delete.
- **The search index rebuild and the Laravel cache clear are skipped**, since both act on this application rather than on the copy. Bard sanitization and the dataless entry sweep run against the copy.
- **`--source=api` still materializes its WXR parts** into the directory the `file` argument names.
- **The copy costs disk space and time** in proportion to the content and asset directories, so check there is room in the temporary directory first.
- **Automatic Git commits and preset image generation are off for the run, and jobs run synchronously**, so a save queues no commit of this application's repository and leaves nothing for a worker to do later.
- **A database write holds its connection's write lock until the run ends.** On SQLite that blocks other writers to that database for the rest of the run.
- **A setup that cannot be contained is refused**, and nothing is imported: an asset container on a disk that is not local.
- **Listeners the host application registers are not contained.** A listener that writes to its own location on an entry save still runs, and so does a write to a connection the list above does not name.

## Navigation Menu Import

`nav_menu_item` import is handled as a dedicated flow:

- Confirms an active primary menu context (e.g., theme labels such as “White Bar” / “Primary Navigation Menu” in UAMS themes).
- Lets you choose the WordPress menu to import from the export.
- Preserves parent/child hierarchy and ordering (`_menu_item_menu_item_parent`).
- **Custom URLs** (`_menu_item_type: custom`) use `_menu_item_url`.
- **Post type links** (`_menu_item_type: post_type`) resolve to imported Statamic entries.
- **Taxonomy links** (`_menu_item_type: taxonomy`) are skipped.
- Only items from the selected menu apply (`category domain="nav_menu"` …); existing navigation for the site is cleared before import. Relevant collections may be auto-enabled when linked entries exist.

See `docs/technical-reference.md` for service/method-level detail (`NavigationMenuSelector`, `NavigationMenuImporter`).

## MinervaKB Import

`kb` import includes Minerva-specific structure handling:

- Root knowledge base entry uses the site name from `resources/sites.yaml`.
- Topics come from `wp:term` with `wp:term_taxonomy` `kbtopic`; articles are `wp:post_type` `kb`.
- Each article uses **one** topic (the first in the article’s category list).
- Hierarchy is KB → topics → articles; tree building for KB is handled by `MinervaKBProcessor` (not the generic page `TreeBuilder`).
- Minerva **content restrictions** are not imported—reconfigure in Statamic and on the source site as needed.

Details: `docs/technical-reference.md` (MinervaKB sections + processor classes).

## Overwrite Behavior Options

For each applicable data type, you can select how collisions are handled:

- **Entries** — overwrite, skip, or create additional entries for matching IDs
- **Assets** — overwrite, skip, or create additional files (when uploads are enabled)
- **Tables** — similar options when TablePress/table content applies
- **Redirects** — options for matching source URLs in redirect globals

## Taxonomy and site auto-enabling

The importer can enable collections, globals, or taxonomies for the selected site when content requires it. Examples:

- `people_group` — `uamspeople` imports or pages using `show-people` shortcodes
- `region` and `location_type` — pages with Find-a-Doc blocks
- `logos_item_categories` — pages with logo list blocks

## Troubleshooting

- Ensure your XML file exists and is readable by PHP.
- Use a valid WordPress **WXR** export.
- Check `storage/logs/laravel.log` for errors.
- For large imports, increase PHP memory/time limits as needed.
- If an import appears stale in the Control Panel, clear caches and refresh Stache.
- Review warnings for unsupported block patterns and manually adjust imported content where necessary.

Command internals (per-step scans, `scanForClassicBlocks`, streaming parser, completion order): **`docs/technical-reference.md`**.

## Development

Run tests with:

```bash
./vendor/bin/phpunit
```

### Test fixtures (the export corpus)

The WXR and forms fixtures are WordPress exports of UAMS sites, so they are **private**. They live only in the private repository `UAMS-Web/wordpress-importer-exports`, which only accounts granted access to it can fetch. This repository does not carry them (#1426). With access, clone the corpus anywhere and name the clone in `WORDPRESS_IMPORTER_EXPORTS`:

```bash
git clone --depth 1 https://github.com/UAMS-Web/wordpress-importer-exports.git ../wordpress-importer-exports
WORDPRESS_IMPORTER_EXPORTS=../wordpress-importer-exports composer test
```

**Without the corpus, everything in the gate still runs.** [`ci.yml`](.github/workflows/ci.yml) and `node scripts/ci-local.mjs` run only `--testsuite=Unit,Integration`, and every job passes without the fixtures. The two standalone tests that read the corpus, `TaxonomyDestinationTest` and `AdvertCorpusImportTest`, skip when the variable is unset, naming it in the skip message. A variable that is set but names no directory fails those tests instead, so a mistyped path cannot pass as a skip. The host-app-bound `tests/Feature` audits read the corpus through the host app's own settings (`FRM_AUDIT_FIXTURES_DIR`, `GF_AUDIT_FIXTURES_DIR` in `uams-statamic`), not through this package.

**Keep the clone current.** Nothing pins it, so a clone left alone audits content that no longer resembles production; the corpus once went seven weeks and seven commits stale before anyone noticed (#474). Pull it before an audit run: `git -C ../wordpress-importer-exports pull --ff-only`.

A tree that had the old submodule initialized keeps the clone at `src/Services/WordPressImport/Tests/Exports` after updating; `.gitignore` covers that path. It can be deleted, or used as the value of `WORDPRESS_IMPORTER_EXPORTS`.

## Public surface

This package declares the surface below. **It is the surface a release decision is judged against** — a change that removes, renames, or alters the contract of anything here is breaking, and nothing else is, however `public` it happens to be. The [`cutting-releases`](.claude/rules/cutting-releases.md) rule applies that test; this section is what it reads.

The declaration is deliberately local. Measuring what `uams-statamic` consumes is the more accurate question and it makes a release decision depend on reading another repository — so the table is *seeded* from that measurement and then maintained here.

| Channel | Declared |
| --- | --- |
| Console command | `statamic:import:wordpress`, and the eight options documented under [Usage](#usage): `--source`, `--site`, `--modified-after`, `--network`, `--key-from-stdin`, `--unsupported-content`, `--content-type`, `--minerva-generation` |
| Service provider | `UAMSWeb\WordPressImporter\ServiceProvider`, auto-discovered through `extra.laravel.providers`; the `ImportWordPress` command binding it performs; and the `protect.entry` middleware alias it registers |
| Classes | the 22 below |

Classes are listed relative to `UAMSWeb\WordPressImporter\Services\WordPressImport\`. **Consumed by** records where the app reached for it when the table was last seeded — `runtime` in the app's own code, `tests` in the app's own test suite. Both count: removing either breaks the consumer.

| Class | Consumed by |
| --- | --- |
| `Assets\AssetImporter` | tests |
| `Entries\Creators\KbEntryCreator` | tests |
| `Entries\EntryCreator` | tests |
| `Globals\Creators\RedirectRuleGlobalCreator` | tests |
| `Parsers\XmlParser` | tests |
| `Prompts\MinervaKbSettings\Extractor` | tests |
| `Prompts\MinervaKbSettings\Loader` | tests |
| `Prompts\MinervaKbSettings\Prompter` | tests |
| `Prompts\MinervaKbSettings\Selector` | tests |
| `Prompts\MinervaKbSettings\Validator` | tests |
| `Structures\TreeBuilder` | tests |
| `Tiptap\SetMarkupNonce` | tests |
| `Utilities\BardParser` | tests |
| `Utilities\CollectionStructureManager` | tests |
| `Utilities\ContentProcessor` | tests |
| `Utilities\FieldDefaultHelper` | runtime |
| `Utilities\MetadataMapper` | tests |
| `Utilities\PasswordVerifier` | runtime |
| `Utilities\SearchIndexUpdates` | tests |
| `Utilities\SiteManager` | tests |
| `Utilities\TextProcessor` | tests |
| `Utilities\XpathHelper` | tests |

**Re-measure when the app's pin moves.** The declaration is a local claim about a cross-repo fact, which is exactly what makes it cheap and exactly how it drifts. Seeded on 2026-09-21 against `uams-statamic` at `2ba68850a`, pinning `^0.20`.

## Releasing

Releases are **retrospective semver tags on `main`** — a changelog checkpoint, not a deploy gate. Cut a tag whenever a batch of vetted changes has landed, and always **before `uams-statamic` consumes them**: the app pins this package by a tagged caret range (`"uams-web/wordpress-importer": "^0.20"` as of 2026-09-21), not `dev-main`, so a tag has to exist for the new work to be requirable. A tag survives a force-push to `main`; a raw `dev-main` branch commit does not (the failure behind `UAMS-Web/uams-statamic`#904 / #905).

**Versioning** — stay in `0.x` until the eventual `1.0.0` (Launch); every tag before then is a pre-release.

- `0.x.0` (minor) — a feature or structural milestone.
- `0.x.y` (patch) — refinement only.

**Cutting a release:**

```bash
# changes are merged to main; tag the tip you want to release
git checkout main && git pull
git tag -a v0.6.0 -m 'v0.6.0 — <Theme>'
git push origin v0.6.0

# publish the GitHub release (pre-release until 1.0)
gh release create v0.6.0 --title 'v0.6.0 — <Theme>' --notes-file notes.md --prerelease --verify-tag
```

**Release-note style** — title `vX.Y.Z — <Theme>` (em dash), a one-sentence milestone lead, then a closed, ordered set of `##` buckets: `Breaking changes`, `What's new`, `What's fixed`, `Security`, `Maintenance and tooling`. One linked bullet per change (`[#N]` for a PR, a backticked short SHA for a direct commit); no `by @author`; write "and", never `&`; use the Oxford comma. The full style guide and a body generator (`gen_release_notes.py`) live in the consumer repo `UAMS-Web/uams-statamic` under `.claude/skills/writing-release-notes/`.

**After tagging**, bump the constraint in `uams-statamic` (edit `composer.json`, then `composer update uams-web/wordpress-importer`) when the app should adopt the new release — normally through its recurring dependency-update pass.

## Support

- Issues: [GitHub Issues](https://github.com/UAMS-Web/wordpress-importer/issues)
- Source: [GitHub Repository](https://github.com/UAMS-Web/wordpress-importer)

## License

Released under the [MIT License](LICENSE). Copyright (c) 2026 University of Arkansas for Medical Sciences.
