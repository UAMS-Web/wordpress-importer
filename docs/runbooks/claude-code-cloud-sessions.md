# Runbook: Claude Code cloud sessions

Cloud sessions run this repo on an Anthropic-managed Ubuntu 24.04 VM instead of your machine. They work well for the **issue and docs** half of this repo — which is most of what gets done here remotely — and poorly for anything needing a **complete `vendor/`**, because one dependency can never be installed under this proxy (see [`phpstan/phpstan` is dist-only](#phpstanphpstan-is-dist-only-upstream)).

Everything marked *verified* below was measured in this repo on **2026-07-31**. Items inherited from the sibling app's runbook (`UAMS-Web/uams-statamic`) and not re-measured here say so.

## What the stock image gives you (verified)

| | |
| --- | --- |
| OS / privileges | Ubuntu 24.04.4 LTS, running as **root** — `apt` needs no `sudo` |
| PHP | 8.4.19, Debian build, OPcache loaded |
| Extensions | **Every extension [`ci.yml`](../../.github/workflows/ci.yml) pins is already present** — `intl`, `mbstring`, `dom`, `fileinfo`, `simplexml`, `xml`. `bcmath` is absent, and CI does not pin it. The XML surface this package parses WXR with (`dom`, `libxml`, `SimpleXML`, `xmlreader`, `xmlwriter`, `xsl`) is fully present. |
| Node | v22.22.2 |
| `gh` | 2.45.0 (present in this session) |
| Resources | 4 vCPU, 15 GiB RAM, ~30 GB free disk |
| Absent | `vendor/`, the `Exports` fixture submodule (uninitialized) |

`composer` is present, but reports *"Composer plugins have been disabled for safety in this non-interactive session"* — so `phpstan/extension-installer` and `pestphp/pest-plugin` do not self-register.

**Unlike the host app, this package needs no `.env`, no `APP_KEY`, no `imagick`, and no content submodule.** It is a library; there is nothing to boot. Those steps in the sibling's runbook do not apply here — following them will send you chasing a bootstrap this repo does not have.

## Two proxies, and why the difference matters

Almost every confusing failure below comes from conflating these:

- **The network allowlist** — what the environment's access level permits. Under **Trusted** this already includes `github.com`, `api.github.com`, `codeload.github.com`, and `packagist.org`.
- **The GitHub proxy** — a separate layer that holds the real credential outside the VM and scopes GitHub API requests to *repositories attached to the session*. It operates **independently of the access level**.

So a 403 from `api.github.com` is almost never an egress problem, and **raising the network access level will not fix it**. It means the repository is not attached. Git traffic is rewritten to a local proxy remote (`http://local_proxy@127.0.0.1:<port>/git/…`) via `insteadOf`; the port is session-specific.

`GH_TOKEN` and `GITHUB_TOKEN` read as the literal string `proxy-injected` (verified). `gh` works with that; a script that reads either variable directly gets the placeholder.

**Reads resolve on the user's OAuth token; writes are re-signed by the GitHub App.** `gh api user --jq .login` returns the real login, but every write is attributed to `claude[bot]`, and under squash-merge that is what lands on `main`. You cannot change the author — so **assign every issue and PR you open**, which is settable and is what the iteration tooling attributes capacity by:

```bash
ME=$(gh api user --jq .login)
gh api -X POST repos/{owner}/{repo}/issues/{n}/assignees -f "assignees[]=$ME"
```

PRs assign through the *issues* endpoint, so opening one is two calls. Avoid `--assignee @me` — that handle lookup is GraphQL-backed, and GraphQL is unavailable here (below).

## Attaching repositories

Session scope starts at the one repo the session was created from. Everything else needs `mcp__Claude_Code_Remote__add_repo`:

```json
{"owner": "UAMS-Web", "repo": "uams-statamic", "access": "read"}
```

- `owner` and `repo` are **separate fields**, not an `owner/repo` string.
- Attachment is **session-scoped**. A new session starts back at one repo. No setup script or `SessionStart` hook can pre-attach, because both run before Claude can call the tool.
- Use `access:"read"` unless you must push from the attached repo.
- Attachment fixes **git transport as well as the REST API**.
- **You often do not need the clone.** To read a handful of files out of an attached repo, the contents API is instant where a shallow clone is minutes:

  ```bash
  gh api repos/UAMS-Web/uams-statamic/contents/<path> --jq .content | base64 -d
  ```

- **One owner tier per session.** `add_repo` refuses a repo from a different owner than the session's existing sources:

  ```
  add_repo: cross-tier adds are not supported in v1: requested "phpstan/phpstan" but
  session already has repos from owner(s) [uams-web]. Start a new session with the
  requested repo as the initial source, or add a repo from the same owner as the
  existing sources
  ```

  The error's first suggestion is a dead end for our purposes: seeding a session with a non-`UAMS-Web` repo makes every `UAMS-Web` add cross-tier by the same rule. Practically, a cloud session reaches `UAMS-Web` repos or third-party repos, never both. `UAMS-Web/uams-statamic` and `UAMS-Web/wordpress-importer-exports` are both same-tier and attach fine.

## Failure signatures

| Symptom | Cause |
| --- | --- |
| `could not read Password for 'http://local_proxy@127.0.0.1:…'` | Repo not attached. Call `add_repo`. |
| `could not read Username for 'https://github.com'` | The proxy was never reached — an `insteadOf` rewrite terminated at real `github.com`. See below. |
| `Could not authenticate against github.com` (`AuthHelper.php`) | **Not a credential problem.** Composer's fallback message after an HTTP 403 from the GitHub proxy. |
| `curl error 28 … Proxy CONNECT aborted due to timeout` | A `dist` download against `api.github.com`. Direct `curl` 403s instantly, but under CONNECT the same request *hangs* until the proxy gives up — a latency trap, not a fast failure. |
| `Numeric-ID repository paths (repositories/{id}/...) are not supported` | `gh api --paginate`. See [pagination](#pagination-truncates-at-page-1). |
| `403 — only the pinned set of PR-review operations is served` | Any GraphQL call. See [GraphQL is unavailable](#graphql-is-structurally-unavailable). |
| `Write access to this GitHub API path is not permitted through this proxy` | `POST git/tags` or `POST git/refs`. See [tags and releases cannot be written](#tags-and-releases-cannot-be-written). |
| `Creating, editing, or deleting releases is not permitted for this session type` | `POST /releases`. Same section. |
| A `blocked_by` list shorter than `total_blocked_by`, HTTP 200 | See [`blocked_by` under-reports](#dependenciesblocked_by-under-reports--read-dependency-edges-locally). **Silent** — nothing in the response says it was truncated. |

### `insteadOf` applies once, longest-match

Git resolves an `insteadOf` rewrite in a **single hop**. The proxy ships a rule mapping `https://github.com/` to the local proxy, so an SSH-form URL that maps to `https://github.com/` **stops there** and never reaches the proxy — which is exactly why the symptom is `could not read Username for 'https://github.com'` rather than a proxy error.

This session's config, verified, shows the shape precisely:

```
url.http://local_proxy@127.0.0.1:<port>/git/.insteadof https://github.com/
url.https://github.com/.insteadof git@github.com:
url.https://github.com/.insteadof ssh://git@github.com/
```

The last two map the SSH forms to `https://github.com/` — and there they stop. Map the SSH forms **straight to the proxy** instead, in one hop:

```
url.<proxy>.insteadof git@github.com:
url.<proxy>.insteadof https://github.com/
url.<proxy>.insteadof ssh://git@github.com/
```

**`insteadOf` is multi-valued: always use `--add`.** A bare `git config --global url.<proxy>.insteadOf …` *replaces* the existing value and silently destroys the proxy's own `https://github.com/` mapping, breaking every HTTPS clone. An unguarded `--add`, meanwhile, appends a duplicate on every invocation — so a hook must be idempotent.

The two SSH-form rules above are injected per session through `GIT_CONFIG_COUNT` / `GIT_CONFIG_KEY_n` (`--show-origin` reports them as `command line:`), so a hook writing `~/.gitconfig` **cannot** remove them and `--unset-all` only clears the global copy. The double mapping is harmless because git keeps the longest match and replaces only on a strictly longer one: on an equal-length tie the first-parsed wins, and files parse before the command line, so a file-based rule takes precedence. Correct, but precedence-fragile — it only holds while the rule lives in a file.

## GraphQL is structurally unavailable

The session credential serves only a **pinned set of PR-review operations**. Everything else on the GraphQL endpoint — including a bare `{viewer{login}}` and every `projectV2` read — returns `403`. This holds even on a full-scope user token, so **it is not a scope problem and a broader token does not fix it**.

The consequence that bites triage: **you cannot verify project-board membership from a cloud session.** `gh project item-list`, `field-list`, and every board mutation are GraphQL-only. Classify tickets from the signals you *can* read (labels, native `blocked_by` edges, issue bodies) and **flag** anything where board membership is decisive, rather than guessing. See [`.claude/rules/github-api-budget.md`](../../.claude/rules/github-api-budget.md).

Note also that `gh issue create`, `gh issue edit`, `gh pr create`, and `--assignee @me` are all GraphQL-backed and therefore unavailable — use the REST equivalents in that rule.

## Tags and releases cannot be written

**A cloud session cannot cut a release.** Not "needs a better token" — the session type does not carry the capability at all, so a routine that tags or publishes **can never succeed here** and the only useful thing to do is decline before starting.

Verified 2026-08-03 during the `v0.8.0` cut. A scheduled routine executed correctly the whole way through — it passed the hard gate, derived `v0.8.0` as a minor bump, and generated the notes — then failed at **every** available write path:

| Attempt | Result |
| --- | --- |
| `git push origin v0.8.0` | `HTTP 403`, `RPC failed` |
| `POST git/tags` | `403` — `Write access to this GitHub API path is not permitted through this proxy` |
| `POST git/refs` | `403` — same string |
| `POST /releases` | `403` — `Creating, editing, or deleting releases is not permitted for this session type` |

Nothing about the prompt or the commands was wrong, which is exactly what made it expensive: a full cycle was spent debugging an environment limit as if it were a credential or prompt defect, before the session's own report identified the wall.

**This is session type, not scope** — the same shape as [GraphQL](#graphql-is-structurally-unavailable), and with the same consequence: **a broader token does not lift it.** Do not go looking for a PAT that works.

**Reads are unaffected.** The same session cloned the repo, read every convention file, ran `git log` / `git diff`, and resolved PR titles over REST without trouble. The block is specific to *writes* of tags, refs, and releases — so a cloud session remains fine for drafting notes, deriving the next version, and checking whether a release is due. It simply cannot perform the last step.

**The practical rule: everything up to the tag can run here; the tag and the publish must run somewhere with push rights** — a local checkout. `v0.8.0` was ultimately cut locally, where `git push origin v0.8.0` and `POST /releases` both succeeded on the first attempt. The routine that surfaced this has been disabled for the same reason. See [`.claude/rules/cutting-releases.md`](../../.claude/rules/cutting-releases.md).

## Pagination truncates at page 1

`gh api --paginate` cannot follow GitHub's `Link: rel="next"` header, because GitHub renders it in numeric-ID form (`https://api.github.com/repositories/<id>/issues?page=2`) and the proxy refuses that shape. `gh` writes page 1, then exits 1.

It is loud on stderr and in `$?` — but **silent through a pipe**: `gh api --paginate … | wc -l` discards the non-zero exit, so a truncated count reads as complete. Set `pipefail`, or walk pages explicitly with `&page=N`, which keeps every URL in the accepted `repos/{owner}/{repo}/…` form.

**For this repo the bug is latent, not live** — at 51 open `afk` issues against `per_page=100` there is no page 2 today. It becomes live silently the moment the count crosses 100. Sanity-check any paginated count against a known total. Tracked upstream in the sibling repo as `UAMS-Web/uams-statamic#1823`.

## `dependencies/blocked_by` under-reports — read dependency edges locally

The proxy answers `GET repos/{owner}/{repo}/issues/{n}/dependencies/blocked_by` with **HTTP 200 and a list shorter than the ticket's own `issue_dependencies_summary.total_blocked_by`**. There is no error, no warning, and no partial-response header — a blocked ticket simply comes back with fewer blockers than it has, or none at all.

That is not a cosmetic loss. A blocker list is how a ticket is judged buildable, and an empty list reads as "no open blocker". Read through the proxy, it asserts the **opposite of the truth** for every affected ticket, and work started against one stalls on a blocker nobody saw.

**It is the proxy, not GitHub (verified 2026-07-31, `UAMS-Web/wordpress-importer-archive#502`).** A cloud session flagged 20 tickets as under-reporting. Re-running every one of those queries from a local, non-proxied `gh` the same day returned the **complete** edge set for all 20 — including the two the issue was filed on:

```
# cloud session                          # local gh
issues/299 → total_blocked_by: 1         issues/299 → total_blocked_by: 1
issues/299/dependencies/blocked_by       issues/299/dependencies/blocked_by
  → 200 []                                 → 200 [ UAMS-Web/uams-statamic#300 ]
```

**Do not narrow this to cross-repo edges.** That was the first hypothesis and it is wrong: 19 of the 20 affected tickets happened to have a cross-repo blocker, but `wordpress-importer-archive#492`'s only blocker is the **local** `wordpress-importer-archive#496` — an edge added 2026-07-30, a day before the proxied crawl — and its list came back short too. Treat every dependency list read through the proxy as untrustworthy.

**So answer "is this ticket blocked?" from a local session**, or compare the returned list against `issue_dependencies_summary.total_blocked_by` and treat any shortfall as unknown rather than unblocked.

## `phpstan/phpstan` is dist-only upstream

Because `api.github.com` is unreachable for generic HTTP clients, the only workable Composer strategy is `composer config --global preferred-install source`, which routes installs over the permitted git transport. It cannot cover `phpstan/phpstan`, which has **`"source": null`** in this repo's own [`composer.lock`](../../composer.lock) — verified here at version 2.2.2. There is no git path for it to take.

This originates upstream and is not a lockfile defect. Packagist's p2 metadata for those releases carries a `dist` block and no `source` block at all; Composer recorded what it was given.

**This is deliberate upstream behavior, and phpstan documented it.** The `phpstan/phpstan` repository commits a fresh `phpstan.phar` on every release and is multiple gigabytes, so a `--prefer-source` install meant cloning all of it. [phpstan/phpstan#10921](https://github.com/phpstan/phpstan/issues/10921) is the motivation; their first fix redirected source installs to a small proxy repository, and the approach it then changed to was **omitting the `source` block entirely**, leaving Composer no source option at all.

The practical consequences:

- **Version changes do not help.** The recent 2.x line is dist-only, including newer releases than the 2.2.2 pinned here.
- **Nothing to file upstream.** It is a considered fix to a real problem, and it only bites where git works and `api.github.com` does not — a property of this proxy, not of phpstan's packaging.
- **`add_repo` cannot rescue it.** Attaching the public `phpstan/phpstan` repo would resolve the 403, but `add_repo` refuses **cross-owner-tier adds** (above), so a session already holding `UAMS-Web` repos cannot attach it.
- **The strategy is fragile by construction.** Any package that switches to dist-only publishing later breaks the cloud bootstrap the same way, silently, whenever its next release lands.

**The consequence is structural: a cloud session cannot produce a complete `vendor/` for this package.** `phpstan/phpstan` is a *dev* dependency, so `composer install --no-dev` still completes — but that drops Pest, PHPUnit, Pint, and PHPStan itself, which is the entire quality gate. There is no test runner in a cloud session and no supported way to install every dev package except one.

Do **not** hand-seed a zip into Composer's dist cache to get past this. It is indistinguishable from routing around a policy denial, the auto-mode classifier blocks it, and `/root/.ccr/README.md` is explicit that organization policy denials are to be reported rather than retried.

**Never run `composer install --prefer-dist`** (the default) if you bootstrap at all. Every dist URL is an `api.github.com` zipball whose 403 presents as a *hang* under CONNECT — in the sibling repo that burned **14m28s** before failing outright. Catalog, issue, and docs work needs no bootstrap at all; prefer to skip it.

## What a cloud session is actually good for here

Verified in this repo: the Node scripts run with **no `vendor/` at all**, because they need only Node and `gh`.

```bash
node scripts/watch-github.mjs                         # the event watcher
node --test scripts/*.test.mjs
```

**`node --test` needs its paths named.** A bare `node --test` skips any test under a dot-directory such as `.claude/`, because the default glob does not descend into one, so pass the paths explicitly as above. (Node 22 also rejects a bare directory argument — `node --test scripts/` fails with `Cannot find module`.)

## What does NOT help (inherited from the sibling's runbook; not re-measured here)

- **Raising network access to Full or Custom.** `api.github.com` and `codeload.github.com` are already on the Trusted allowlist; the 403 is the GitHub proxy, which ignores the access level.
- **`composer config use-github-api false`.** Fails faster but not better — dist errors immediately and the source fallback still cannot authenticate.
- **A broader GitHub token for GraphQL.** The pinned-operations restriction is not a scope check.

## References

- [`.claude/rules/github-api-budget.md`](../../.claude/rules/github-api-budget.md) — REST vs GraphQL, and the REST equivalents for issue work.
- [`.claude/rules/long-running-commands.md`](../../.claude/rules/long-running-commands.md) — bounding the host-bound test runs.
- [`.claude/rules/worktrees.md`](../../.claude/rules/worktrees.md) — the local equivalent of this bootstrap, including the `Exports` submodule.
