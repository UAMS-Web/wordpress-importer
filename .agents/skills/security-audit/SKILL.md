---
name: security-audit
description: >-
  Run a multi-agent security audit of a repository's first-party code (application or package
  PHP, templates, scripts, configuration and the GitHub Actions workflows). Fans out one finder
  agent per security domain (XSS, SSTI, path traversal, SSRF, XXE/DoS, authz, injection, secrets,
  deserialization, weak crypto and disabled TLS verification, validation/mass-assignment),
  adversarially verifies every candidate against the repository's trust model
  (who-controls-the-input, where auth sits, which output is raw by default), then writes a
  severity-ranked report. After you review it, it files each approved finding: an exploitable,
  unfixed vulnerability in a public repository as a draft repository security advisory, and
  anything else as a GitHub issue following the project `writing-issues` conventions. Also holds the checklist of insecure code
  patterns every pass and every diff review searches for: unescaped output, shell execution, code
  evaluation, unsafe deserialization, committed secrets, weak encryption, disabled TLS
  verification, and Actions expression injection. Activate when the user asks to "run a security
  audit", "audit the codebase for vulnerabilities", "find security bugs", "turn Claude loose on
  security", "scan for XSS/SSRF/injection", invokes `/security-audit`, reviews a diff for
  security, or needs the security lens of an adversarial review. This is a broader, whole-repo
  complement to the diff-scoped `/security-review` command.
---
<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/security-audit/SKILL.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): php.
-->
<!-- cspell:ignore escapeshellarg IDOR popen SSTI Subresource -->

# Security audit (multi-agent, report-first)

A repo-tailored security audit. It runs a bundled multi-agent **workflow** that finds →
adversarially verifies → triages, hands you a report to review, and only then files findings. The
verification step is the point: most generic framework "findings" are false positives (admin
surfaces sit behind auth and many flagged sinks turn out sanitized or editor-only), so every
candidate is checked against the repository's real trust boundaries before it reaches you.

In a Statamic app, note that Antlers does NOT auto-escape, so plain `{{ }}` is *not* itself a
protection.

**A sanitizing modifier is only a protection while it cannot throw.** Statamic catches a throwing
modifier and returns the value the expression held *before* it ran: `Modify::modify()` wraps the
error in a `ModifierException`, and `NodeProcessor::runModifier()` rethrows **only** when
`app.debug` is on, otherwise it logs a notice and returns `$value`. In production, raw-by-default
Antlers then emits the raw input verbatim: a throw inside a sanitizer is a stored-XSS
**fail-open**, not a 500. Verified in `uams-statamic` on `sanitize_table_cell`, which emitted
`<script>alert(document.cookie)</script>` once its HTMLPurifier definition cache was corrupt
(`UAMS-Web/uams-statamic-archive#1853`).

**Whether a throw fails open or 500s turns on `Exception` vs `Error`**, re-verified 2026-10-02
against `statamic/cms` v6.35.0 (the build `aether-wordpress-importer` pins, `UAMS-Web/wordpress-importer-archive#1290`):
`Modify::modify()` still catches `Exception`, and `NodeProcessor` still catches
`ModifierException`. The distinction matters because it decides the severity of every sanitizer
finding:

- **`Exception` family → fail-open (the dangerous path).** `Modify::modify()`'s generic arm is
  `catch (Exception $e)`, **not** `Throwable`; it re-wraps into the `ModifierException` that
  `runModifier()` then swallows. It is wider than it looks: Laravel's `HandleExceptions` turns PHP
  warnings and notices into `ErrorException` (an `Exception`) in **every** environment, so a vendor
  `trigger_error()` inside a sanitizer fails open in production too. This is the quiet path, and
  the one that leaks.
- **`Error` family → a real 500.** `TypeError`, `ParseError` and `DivisionByZeroError` extend
  `Error`, so they escape `catch (Exception)`, are never wrapped, and propagate. `strip_tags()` on
  an array (which Antlers' truthy-array `?:` / `??` will happily feed it) raises a `TypeError`
  on PHP 8, so it 500s rather than leaking; that is `UAMS-Web/uams-statamic-archive#1883`, whose four
  entries returned a 500 on every page. Loud, but still a sanitizer that did not sanitize.

So when auditing a sanitizer, ask *"what makes this throw, and with which class"* alongside *"what
gets past it"*, and treat any security-control modifier that lacks a `Throwable` catch returning a
safe value as a finding.

**In the importer the same failure wears different clothes: a sanitizer that silently never ran.**
This package's sanitizers are plain PHP, not Antlers modifiers, so there is no `ModifierException`
to swallow; the equivalents are a **call shape that makes the sanitizer a no-op** and a **`catch`
that misses the class actually thrown**, and both have already happened here.
`ContentProcessor::sanitizeBardFieldContent()` used to wrap the node list in a `['content' => …]`
envelope before handing it to the host app's `BardContentSanitizer`; the sanitizer re-indexes its
output to a numeric list and, with no top-level `type`/`content` key, never visited the inner text
nodes at all, so it **silently skipped sanitization** while returning something that looked
processed (#392). And `SafeMathEvaluator`'s regex-plus-`eval()` predecessor drew the same
`Exception`/`Error` line as Statamic does, in the same place and with the opposite result: its
`catch (Exception)` could not catch `ParseError` or `DivisionByZeroError`, so `[value] mod 3` and
`[value] / 0` aborted the import outright. When auditing importer sanitization, verify the
sanitizer is **reached with the shape it expects** and that its `catch` covers `Throwable`, not
just `Exception`; a sanitizer whose output is indistinguishable from its input on the happy path is
exactly the one to exercise with a hostile fixture.

## Patterns every pass checks

The workflow's domain finders sweep for these, and a `--quick` pass or a reviewer reading a diff
checks them by hand. Each entry is the form the code actually takes in PHP, in the repository's
scripts, in its templates or in the browser, so it can be searched for; whether a hit is a finding
still depends on who controls the input, per the trust model. A value read from the application's
own storage is not the same as a value from the request, and neither is the same as a value from
a pull request's title.

- **Unescaped output.** A sink is a finding when editor- or request-controlled markup can reach
  it without being sanitized first. In JavaScript: Alpine `x-html`, Vue `v-html`, and assignments
  to `.innerHTML`, `.outerHTML`, `.insertAdjacentHTML()` and `document.write()`.
  Blade `{!! $value !!}` prints raw, while `{{ $value }}` escapes.
  Antlers prints raw by default, so a plain `{{ value }}` is raw too (see above).
- **Third-party scripts without Subresource Integrity.** `<script src="https://…">` from another
  host with no `integrity="sha384-…"` attribute. A compromised CDN then runs script on every page
  that includes it.
- **Shell execution.** `exec()`, `shell_exec()`, `system()`, `passthru()`, `proc_open()`,
  `popen()` and backticks. Any interpolated value in a string command needs `escapeshellarg()` at
  minimum. In the repository's Node scripts: `spawnSync(cmd, args)` with an argument array passes
  arguments without a shell. Where the repository has `scripts/spawn-helpers.mjs`, the one place
  a shell is used is a Windows `.bat` or `.cmd` executable, where `needsShell()` turns it on and
  every argument goes through `winQuote()`. A new `shell: true`, `exec()` or `execSync()` with a
  built string, or a shelled call whose arguments skip `winQuote()`, is the finding.
  Also Laravel's `Process::run()` / `Process::start()` and Symfony's
  `Process::fromShellCommandline()` when given a string, because a string command goes through a
  shell. The array form (`Process::run(['cmd', $arg])`) passes arguments without one.
- **Code evaluation.** `eval()`, `assert()` with a string argument on PHP below 8.0, and
  `create_function()`, which PHP 8.0 removed. Also rendering a string as a template when the
  string is not the application's own.
  The template case is `Blade::render()` or an Antlers parse of a string.
- **Unsafe deserialization.** `unserialize()` on data this code did not write, unless it passes
  `['allowed_classes' => false]` or an explicit class list; `json_decode()` is the safe choice for
  data. A class list that includes a class with a magic method is a finding. Symfony
  `Yaml::parse()` with the `Yaml::PARSE_OBJECT` flag is the same class: it rebuilds objects from
  `!php/object` tags.
- **Hardcoded secrets.** API keys, tokens, passwords and private keys as string literals in code,
  config, scripts, tests or a workflow; a real value given as a fallback where a secret is read
  from the environment or a constant; a committed `.env*` file other than `.env.example`. Check
  the value is live before rating it: a placeholder, or a fixture that authenticates nowhere, is
  informational at most.
  The fallback form here is a real value as the default of `env('KEY', '…')`.
- **Weak encryption.** `openssl_encrypt()` with an `-ecb` cipher, an empty or constant IV, or no
  authentication (no GCM tag and no keyed hash over the encrypted data). Hashing a high-entropy
  token and comparing in constant time is not encryption and is not this finding.
  Laravel's `Crypt` facade and `encrypt()` are the default to prefer over hand-rolled OpenSSL.
- **TLS verification switched off.** `CURLOPT_SSL_VERIFYPEER => false`,
  `CURLOPT_SSL_VERIFYHOST => 0`, and stream contexts setting `verify_peer` or `verify_peer_name`
  to false. A self-signed development certificate belongs in the trust store, not in a disabled
  check.
  Also `Http::withoutVerifying()` and Guzzle's `'verify' => false`.
- **GitHub Actions expression injection.** A `${{ github.event.* }}` or `${{ github.head_ref }}`
  expression inside a workflow's `run:` script or a checkout `ref:`. Issue and pull-request titles
  and bodies, branch names, commit messages and comment bodies are chosen by whoever opens them.
  Pass the value through `env:` and quote the shell variable instead. A step output is the same
  class when the step built it from such a value: `${{ steps.<id>.outputs.<name> }}` in a `run:`
  carries whatever went into it, such as file names from the pull request's diff. A one-line
  `run:` counts as much as a `run: |` block. An expression whose value the repository's own
  settings fix, such as `github.event_name`, is not attacker-chosen.

In this package the entries take these forms:

- **Raw HTML handed to the host.** This package ships no views, so it has no Blade `{!! !!}` or browser sinks of its own. Its version of unescaped output is HTML it writes into an entry field that the host renders raw. Any path that stores post content, a block attribute or an ACF value as HTML must go through the sanitizer, reached with the shape it expects, as described above.
- **Shell execution.** No PHP under `src/` shells out, so a new shell call in PHP is a finding to justify. The Node scripts in `scripts/` do launch processes, through `scripts/spawn-helpers.mjs` as above.
- **Code evaluation.** The field-value calculation path removed its `eval()` (`SafeMathEvaluator`). A new one is a finding.
- **Unsafe deserialization.** WordPress stores postmeta and options PHP-serialized, so `unserialize()` on export data is routine here, and every call passes `['allowed_classes' => false]`. A call without it is a finding.
- **Hardcoded secrets.** The migration API key is pasted at run time and held nowhere in this package (#982), so any committed value for it is a finding. The full-history scan on #1245 found no first-party credential.
- **TLS verification switched off.** Outbound fetches go through `OutboundUrlGuard`, `GuardedHttpFetcher` and `GuardedRedirectWalk`; audit those first.
- **Weak encryption.** This package encrypts nothing today.
- **GitHub Actions expression injection.** This repository's workflows pass event values through `env:` and quote the shell variable, which is the safe form. Keep any new workflow to it.

Who controls the input here is usually the WXR export or the migration API payload, which the person running the import does not author.

Injection into SQL or XPath, path traversal, SSRF, XXE and parser DoS, access control (including
IDOR) and mass assignment have their own domains in the workflow.

## Invocation

| Command | Scope |
| --- | --- |
| `/security-audit` | Full first-party sweep (default). |
| `/security-audit <path> [<path> …]` | Restrict to the given paths (and code they call into), e.g. `app/Tags app/Http` in a Laravel app or `includes/REST` in a WordPress plugin. |
| `/security-audit --since main` | Audit only what changed vs a ref (broader than `/security-review`). |
| `/security-audit --quick` | Skip the workflow; do a single-agent pass (cheaper, shallower). |

## Procedure

### 1. Resolve scope → build `args` for the workflow

First decide what counts as **first-party** in this repository: the directories holding its own
PHP, templates, configuration, scripts and `.github/workflows/`, and nothing under `vendor/` or
`node_modules/` unless the repository vendors its own packages there. Pass that list as
`firstParty` whenever it differs from the workflow's built-in default, which is the layout of a
Laravel app (`app/`, `routes/`, `config/`, `resources/views/`, `resources/js/`,
`.github/workflows/`).

Whether the repository uses Statamic is decided for you: when `statamic` is omitted, the workflow
reads `composer.json` and treats the repository as Statamic only when `require` names
`statamic/cms`. Without it, the workflow drops its Statamic, Antlers and Bard guidance and its
`uams-statamic` anchors, and audits against a framework-neutral trust model with generic sweep
anchors instead; its first log line says which it detected. Pass `statamic: true` or `false` only
to override that, for example when `composer.json` is not at the repository root. If the
detection cannot run, the workflow keeps the Statamic behavior, the superset of the two.

- **Default / no args** → `{ mode: 'full', firstParty: [<first-party directories>] }`.
- **Path args** → `{ mode: 'paths', files: [<those paths>] }`.
- **`--since <ref>`** → run `git diff --name-only <ref>...HEAD`, keep only first-party paths, and
  pass `{ mode: 'diff', files: [<changed first-party files>], baseRef: '<ref>' }`.
  If the diff is empty, say so and stop. (`git` takes the same command and prints forward-slash paths on both
  Windows and macOS; keep the slashes as-is and pass them through unchanged.)

### 2. Run the workflow

Invoke the bundled script (this skill's instruction to call `Workflow` is the opt-in; you do
**not** need to ask the user again):

```
Workflow({ scriptPath: ".claude/skills/security-audit/security-audit.workflow.js", args: <from step 1> })
```

It fans out 11 domain finders, runs a 2-lens adversarial verifier (sink-analysis + reachability)
on each candidate, drops anything a skeptic confidently refutes, keeps genuinely-undecidable items
as `uncertain`, then returns a triaged `{ summary, findings[], gaps[], counts }` object. Watch live
progress with `/workflows`.

In a repository that uses Statamic (detected, or `statamic: true`), the script's trust model and
the anchors each finder starts from were written for the `uams-statamic` app (Statamic on Laravel,
Antlers templates, CP auth); the finders are told the anchors are a starting point and to sweep the
scope for other instances, so in another Statamic repository they still audit the code they are
pointed at, but read the report knowing the anchors did not name that repository's files. In a
repository without Statamic (detected, or `statamic: false`) no anchor names a file: each is a
generic sweep instruction.

> `--quick` mode: skip the Workflow call. Instead read the domain list and trust-model section
> from [security-audit.workflow.js](security-audit.workflow.js) and do a single read-only pass
> yourself, producing the same report shape. Use only when the user explicitly wants it cheap.
> In a repository without `statamic/cms`, read `OTHER_CONTEXT` and each entry's `other` text, and
> skip the entries marked `statamic` only.

### 3. Write the report

Write `.claude/notes/security-audit-<today>.md`, where `<today>` is the current date from the
session context (e.g. `2026-06-12`); read it from context, don't shell out for it (`date` and
`Get-Date` differ by OS). The workflow can't stamp dates itself.

Structure:

- **Title + meta line:** date, scope/mode, and `counts` (candidates / confirmed / uncertain / refuted).
- **Executive summary:** the workflow's `summary`.
- **Findings** grouped by severity (`## Critical` → `## Info`). For each:
  `### <title>` then a bullet list: **File** (`[path:line](path#Lline)`), **CWE**, **Severity**
  (+ `(was X)` if the verifier adjusted it), **Confidence**, **Status**, **Data flow**,
  **Exploit scenario**, **Preconditions**, **Recommendation**.
- **Needs manual review:** the `uncertain` findings, with the dynamic check each one needs.
- **Coverage gaps:** the workflow's `gaps[]`.

Use clickable `[path](path#Lline)` links (per the VSCode-extension convention), not backticks, for
file references.

**Escape finding text.** Security findings routinely contain literal `<script>`, `</script>`, and
other tags in their titles, summaries, data-flow traces, and exploit payloads. Markdown renderers
pass raw HTML through, so an unescaped `<script>` in a heading opens a real script element and
swallows everything until the next `</script>`, breaking the rendered report. HTML-escape `<` → `&lt;`,
`>` → `&gt;`, and `&` → `&amp;` in every agent-supplied text field before writing it (do **not** escape
the markdown you author yourself: links, headings, bullets).

### 4. Summarize and offer to file

Print a compact severity table (title · severity · file:line · confidence) and the gaps. Then ask
which findings to file; **do not file anything without explicit approval.** Offer the
natural groupings: "all confirmed", "confirmed high+", "let me pick", or "none for now".

### 5. File approved findings

**First decide where each finding goes, because an issue in a public repository is public.**
Anyone can read it, and editing it later does not remove the text: an issue's edit history is
public too. So the route depends on the repository's visibility and on whether the finding can be
exploited before a fix ships:

| Finding | Public repository | Private repository |
| --- | --- | --- |
| Exploitable and unfixed (a working attack on the code as it stands) | draft security advisory | `security` issue |
| Hardening with no exploit (missing workflow `permissions`, a defense-in-depth gap) | `security` issue | `security` issue |
| A dependency raised past a CVE that is already public | `security` issue | `security` issue |

**Telling the first row from the others.** Ask whether the finding's exploit scenario works today,
for an attacker the trust model admits, against the code people run: the default branch or the
latest release. If it works against either, it is exploitable and unfixed. A hardening item has no such scenario: it narrows
what a different defect could do. A dependency CVE is already public, so the issue discloses
nothing new; it records the move past it. When in doubt, treat a finding as exploitable: a draft
advisory can be closed and refiled as an issue, and a public issue cannot be taken back.

**An exploitable, unfixed finding in a public repository becomes a draft repository security
advisory, never an issue.** A draft advisory is visible only to the repository's administrators
and the collaborators added to it, and a CVE can be requested for it before it is published. Create it through
REST, with the body in a file:

```bash
gh api -X POST repos/{owner}/{repo}/security-advisories --input advisory.json
```

`advisory.json` carries `summary` (the title), `description` (the body below, in Markdown),
`severity` (`critical`, `high`, `medium` or `low`) or a `cvss_vector_string`, `cwe_ids`
(`["CWE-79"]`), and `vulnerabilities`, a list whose entries name the `package` (`ecosystem` and
`name`: `composer` and the package name for a Composer package, `other` and the repository name
otherwise) with `vulnerable_version_range` and, once known, `patched_versions`. Read back the
`ghsa_id` and `state` (`draft`) the call returns.

**Build the fix in the advisory's temporary private fork**, not on a branch of the public
repository, where a branch and its pull request are public before the fix ships. Create the
fork with `gh api -X POST repos/{owner}/{repo}/security-advisories/<ghsa_id>/forks`, push the fix
to it, and merge it from the advisory. Publishing the advisory is a maintainer's decision and is
not part of this step.

**A finding in a private repository is filed as a `security` issue**, because repository
security advisories are not available there: `GET repos/{owner}/{repo}/security-advisories`
answers `404` for a private repository and lists the drafts of a public one (measured
2026-10-29 on `UAMS-Web/uams-claude-skills`, private, and `UAMS-Web/uams-2016`, public). The
issue is readable only by people with access to the repository. **It stays in the tracker if the
repository is later made public**, so an open exploitable finding is fixed, or moved to a draft
advisory after the switch and removed from the issue, before the visibility changes (see the
`repository-settings` skill's checklist for moving a repository to public).

**Filing an issue.** For each finding routed to an issue, follow the project
**`writing-issues`** skill conventions exactly (invoke it), filing into this repository. A draft
advisory's `summary` and `description` follow the same title and body conventions:

- **Title:** imperative, fix-oriented, with inline-code markup, no trailing period
  (e.g. ``Escape `get_param` output before printing into HTML``). For a clear vuln use the bug
  archetype (symptom-first ok); for an `uncertain` item use the research-spike archetype.
- **Body:** lede (often `Spun off from the security audit on <date>.`), then `## The bug` /
  `## Root cause`, `## Reproduction` (the exploit scenario), `## Proposed fix`, and a mandatory
  `## Acceptance criteria` task-list of observable outcomes **ending in a test item** (a regression
  test that fails on the vuln and passes after the fix). Add `## References` linking the
  absolute-branch file path.
- **Labels / triage (an issue only):** apply a severity label and route through the **`triage`** skill's state
  machine so it lands correctly on the project board. Severity lives in the label; the board takes
  only membership and `Status` (see [`writing-issues`](../writing-issues/SKILL.md)).
  A draft advisory carries its severity in its own `severity` field and takes no labels; it
  does not go on the board, which holds issues and pull requests.
- **NEVER** add the "generated by AI during triage" disclaimer (standing project rule).

**Check for an existing issue or advisory first.** Run `writing-issues`' "Check for an existing
issue first" step before filing each finding, and in a public repository also list the drafts
(`gh api repos/{owner}/{repo}/security-advisories'?state=draft&per_page=100'`): search by
*concept*, not title, and include closed issues. For security findings, vary the search terms
across the vulnerability class / CWE (`SSRF`, `XSS`, `CWE-79`), the affected file / symbol, and a
symptom phrase. Link/skip any match instead of opening a duplicate.

## Guardrails

- **Read-only.** This audit performs static analysis only. Do not modify source, run the app, or
  execute an import to "prove" a finding. Dynamic confirmation belongs in the filed issue's or
  advisory's reproduction steps, run deliberately by a human or a follow-up task.
- **Trust-boundary first.** Severity always reflects *who controls the input*: anonymous visitor
  vs. authenticated editor vs. super-admin/console. The workflow enforces this; preserve it in the
  report and issues. Don't inflate an editor-only stored issue into an anonymous critical.
- **Cost.** The full workflow spawns dozens of agents. For a quick look at one file, prefer
  `--quick` or a scoped path. The diff mode (`--since`) is the cheapest comprehensive option.
- **Scope.** First-party only. Third-party `vendor/*` is out of scope; for dependency CVEs use
  `composer audit` / `npm audit` instead, and mention that if the user asks about third-party
  risk.
- **Cross-platform (Windows + macOS).** This skill works identically on both: every path here and in
  the workflow uses forward slashes (valid on Windows and macOS), the `scriptPath` is repo-relative,
  and the only shell-outs are `git` and `gh` (same commands, same forward-slash output on both). The
  workflow script runs in the harness JS sandbox: no Node/OS dependency, no `Date.now()`/
  `Math.random()`, no filesystem access from the script itself. Keep paths forward-slash even on
  Windows; don't convert to backslashes and don't invoke OS-specific shell commands.
