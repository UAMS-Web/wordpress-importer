# Decisions

The durable, terse cross-ticket decision log for `UAMS-Web/wordpress-importer`. When a design fork is resolved (see the [`design-decision-forks`](../.claude/rules/design-decision-forks.md) rule), record **what** was decided, **why**, and the **rejected alternatives** here — one entry per decision, newest at the bottom of its section. The GitHub tracker remains the system of record for a single ticket's status; this file is the index a future agent reads to avoid relitigating a settled call.

## Versioning and releases

Releases are **retrospective semver tags on `main`** — a changelog checkpoint, not a deploy gate or a release branch. Trunk-based flow is unchanged: vetted PRs merge to `main` continuously; a tag marks "everything merged since the last tag."

- Stay in `0.x` until `1.0.0` (Launch); every tag before then is a `--prerelease`.
- `0.x.0` (minor) — a feature or structural milestone. `0.x.y` (patch) — refinement only.
- **Cut a tag before `uams-statamic` consumes the work** — the app pins this package by a tagged caret range (e.g. `^0.5`), not `dev-main`, so a tag must exist for new work to be requirable. A tag survives a force-push to `main`; a raw `dev-main` commit does not (the failure behind `UAMS-Web/uams-statamic-archive#904` / `#905`).
- **The governing test is whether not tagging would block progress; the breaking-change trigger is a standing exception to it, not a second test** (`wordpress-importer-archive#992`). Both statements were live in the rule with no ordering between them, so a session reading it at a moment when nobody was available — which is what a standing rule is for — found two tests and no way to choose. The exception is kept rather than narrowed away because the cost it prevents is not a blocked consumer but a breaking change buried among unrelated work, where its callout stops standing on its own. **Rejected:** letting the breaking-change trigger govern outright, which would have meant overturning the adoption-anchored cadence in the bullet below rather than adding to it. What makes a change *breaking for this package* is deliberately left open — `wordpress-importer-archive#1051`.
- **Rejected:** treating this as a library with independent semver stability guarantees — it is an internal migration tool consumed by exactly one app, so the cadence is anchored to that app's adoption, not to external consumers.

See the [`cutting-releases`](../.claude/rules/cutting-releases.md) rule (the *when*) and the `writing-release-notes` skill (the *how*).

## Issue triage — execution mode and hitl flavor

Every open issue carries **exactly one** execution-mode label — `afk` (buildable autonomously, no design fork in its own scope) or `hitl` (a human must decide or act to complete it). Every `hitl` issue additionally carries **exactly one** flavor label:

- **`decision-fork`** — `hitl` because a human **decision** is needed (an unresolved design fork / open question / evaluate-and-decide spike).
- **`action-flavor`** — `hitl` because a real-world **action** outside the repo is needed (produce a WXR export, run/verify a live import, stand up a WP-side API, install/provision), with no fork to decide.

**Posting to a third-party tracker is not, on its own, a reason for `hitl`.** A ticket whose deliverable is an upstream issue, a pull request, a comment, a vote on an existing report or a feature request on another project's tracker is `afk`: an agent researches the tracker, checks for an existing report, drafts the body and prepares the branch, then asks a human to approve the post and posts only after that approval. The approval is one step inside the ticket rather than a property of the whole ticket, because labeling it `hitl` would leave the preparation waiting for a person too. Decided by the operator on 2026-10-01 and recorded in #1286; the tickets it affected (wordpress-importer-archive#822, wordpress-importer-archive#1030, #1146) carry `afk`. What counts as approval is unchanged: posting stays a human decision. The [`upstream-patch-lifecycle`](../.claude/skills/upstream-patch-lifecycle/SKILL.md) skill applies it to installments 2 and 3 of the four-ticket chain, and [`writing-issues`](../.claude/skills/writing-issues/SKILL.md) carries the same carve-out in its execution-mode section.

Flip `hitl` → `afk` in the *same change* that records a fork's resolution. An `action-flavor` label goes with the flip; a `decision-fork` label stays, except where the ticket carried build work alongside the fork — see the entry for wordpress-importer-archive#1066 below. See the [`writing-issues`](../.claude/skills/writing-issues/SKILL.md) skill and the [`design-decision-forks`](../.claude/rules/design-decision-forks.md) rule.

## Test base-class selection

Every test extends one of **three** base classes, chosen by whether it needs the booted host app: `Tests\TestCase` (full host Statamic app), `LaravelHttpTestCase` (minimal Laravel — HTTP client only), or the bare package `UAMSWeb\WordPressImporter\Tests\TestCase` (pure `PHPUnit\Framework\TestCase`). Never "fix" a PHPStan error by downgrading a host-bound test's base class — that trades a static nit for a silent runtime break. The rule and its rationale live in [`tests/CLAUDE.md`](../tests/CLAUDE.md) (refs `wordpress-importer-archive#366`, `wordpress-importer-archive#359`).

## Test framework and boot model — Pest, with testbench for the light Statamic band

The suite converts from class-based PHPUnit to Pest function style (#319, companion `uams-statamic-archive#916`), and adopts Statamic's testbench-based `Statamic\Testing\AddonTestCase` — but only for the tests a *minimal* Statamic can serve.

- **Pest everywhere** — one testing style across the importer and the main app.
- **Testbench (`AddonTestCase`) for the light Statamic band only.** The `Feature` tier (post-`wordpress-importer-archive#28`) splits three ways: content-heavy import tests (the `WordPressImportTestTrait` users) need the host app's real collections/blueprints/Stache and **stay host-app-bound**; light Statamic tests (facades/services, no real content) move onto `AddonTestCase` and run **standalone in package CI**; three verified pure-logic tests move to `tests/Unit`.
- **Rejected:** **A** (Pest-only, defer testbench) — gains no package-CI coverage; **C** (full testbench including the content-heavy band) — likely infeasible without replicating the app's content model in test setup. Whether the content-heavy band can *also* move to testbench is the open spike `wordpress-importer-archive#457`.

Recorded for `wordpress-importer-archive#319` (folds in the `wordpress-importer-archive#364` testbench spike; subsumes `wordpress-importer-archive#454`). See the ticket's `## Decision` and the [`phpunit-to-pest`](../.claude/skills/phpunit-to-pest/SKILL.md) skill (the conversion process).

## Safe arithmetic in form field-value calculation — a dedicated evaluator, not `eval()`

`FieldtypeMappingTrait::applyValueCalculation()` replaces its `eval()` **and its regex sanitizer** with a small dedicated `SafeMathEvaluator` (closed grammar: four operators, parens, decimals, a whitelisted `round()`). The sanitizer is removed, not retained — it is the defect, not the mitigation.

The security framing was a red herring: the expression is never data-controlled (exactly one real `value_calculation` config exists in `src/` — Formidable `max_limit`, the hard-coded literal `'round([value] * 4.79)'` — and `[value]` is `is_numeric()`-guarded), so there is no exploit path. The sanitizer is nonetheless producing three live defects, verified by running it: it strips `round(`, so the only calculation in the codebase returns `1197.5` where `1198` is correct and writes a float into `character_limit`; `catch (Exception $e)` cannot catch `ParseError` or `DivisionByZeroError` (both extend `Error`), so a malformed config aborts the import; and `is_numeric('1e5')` passes, after which the stripped `e` turns `100000` into `15`.

- **Rejected:** vendoring a library — `symfony/expression-language` is **not** already in the dependency tree (verified against `composer.lock`), so every library option is a genuinely new runtime dependency and still requires deleting the sanitizer and restoring `round()` semantics by hand.
- **Rejected:** deleting the DSL in favour of named PHP transforms — narrows a documented extension point.
- **Rejected:** status quo plus a SAST baseline — fixes none of the three defects.

Recorded for `#2`.

## TLS verification in importer remote fetches — on, with no opt-out

Both `CURLOPT_SSL_VERIFYPEER => false` calls in `LinkProcessor` (`:2963`, `:4104`) are removed; cURL defaults apply. A genuinely broken target certificate then fails loudly, which is signal worth having.

- **Rejected:** an env-gated opt-out — a permanent foot-gun guarding a failure never actually observed.
- **Rejected:** a curated CA bundle — no target host has been identified as needing one; it becomes a follow-up if evidence appears.

Residual, tracked separately: both calls take a WXR-controlled URL with no scheme/host allowlist and follow up to five redirects — an SSRF surface untouched by this decision.

Recorded for `#4`.

## Password verification — the plain-text fallback is Control-Panel-facing, not legacy

`PasswordVerifier`'s plain-text branch is **not** legacy import residue and must not be deleted yet. Verified: no entry in host content carries `visibility_password` at all; the importer's only writer (`BaseEntryCreator`) sets `visibility_password = Hash::make(...)` and `is_password_hashed = true` atomically in one branch; but `visibility_password` is a **Control-Panel-editable** field on 14+ blueprints via the `uamsweb_common_status` fieldset, and nothing in the host app hashes on save. The fallback is therefore the only thing making editor-authored passwords work — and the field's CP instructions ("the password is hashed and stored encrypted") are false.

The fix is host-side first: hash on save in `uams-statamic` and correct the instructions; then delete the fallback here, with zero migration (already proven). Independently, the fallback's `===` is not timing-safe and becomes `hash_equals()`.

- **Rejected:** lazy-upgrade-on-verify — leaves plaintext in YAML until each password happens to be verified, while the CP keeps writing new plaintext.
- **Rejected:** deleting the fallback now — breaks every Control-Panel-authored password.

Recorded for `#5`.

## `statamic/cms` constraint — pinned exactly to the host's build

This package pins `statamic/cms` **exactly** to the version `uams-statamic` pins (`6.26.0` when this was decided, `6.35.0` since #1290), replacing a floating `^6.0`, so both CIs resolve the same Statamic build — the first requirement for both exercising the same `ueberdosis/tiptap-php`, the parser whose schema allowlist is what strips `<script>`, `<iframe>`, and `javascript:` out of imported WordPress content. Drift had reached sixteen Statamic minors when this was filed (`v6.10.0`/tiptap `2.1.0` here versus `v6.26.0`/tiptap `2.1.1` in the host), meaning package CI exercised a parser no UAMS content ever runs through.

**The locks had re-converged on their own before the pin shipped** — measured 2026-08-24, both repos already resolved `v6.26.0`/tiptap `2.1.1`, carried there by an intervening dependency sweep. That is the argument for the pin rather than against it: they diverged by accident and converged by accident, and nothing observed either transition. The pin is the mechanism that was missing, so the change is constraint-only and moves no package.

**The pin is necessary, not sufficient — the parser still floats.** `statamic/cms v6.26.0` requires `ueberdosis/tiptap-php: ^2.0`, a range spanning `2.0.0`–`2.1.2` as of 2026-08-24, so pinning Statamic exactly does **not** pin the parser. Both locks read `2.1.1` today, but a fresh resolve picks `2.1.2` (verified 2026-08-24), and a `composer update` in either repo alone re-opens exactly the divergence this decision exists to close. Only `composer.lock` holds the parser steady, and the two locks move on separate schedules. **The per-sweep assertion is therefore the load-bearing half of this decision, not a formality** — the pin removes drift in the Statamic build and in the wiring around the parser; the assertion is what catches drift in the parser itself. The repin to `6.35.0` (#1290, 2026-10-02) shows it: that relock moved the parser from `2.1.1` to `2.2.0` while the Statamic constraint stayed `^2.0`, so the host's companion relock has to resolve `2.2.0` as well.

**Consequence — the two repos are now ordered, not independent.** Composer cannot satisfy two different exact requirements at once, so the host cannot adopt a newer Statamic while this package pins the old one; `composer why-not statamic/cms <newer>` names this package as the blocker. Repin here, land, tag, then bump `statamic/cms` and the `uams-web/wordpress-importer` constraint together in the host's companion PR. `composer outdated` reporting `statamic/cms` as behind upstream is the expected steady state (upstream was at `v6.28.0` at the time of the pin), not a finding. Alignment is asserted every iteration by a step in the [`update-dependencies-ticket`](../.claude/skills/update-dependencies-ticket/SKILL.md) chore that compares both locks and exits non-zero on a mismatch.

- **Rejected:** a raised floor (`^6.26`) plus the lock — honours the usual library convention, but the two locks silently re-diverge the moment either moves, which is exactly how sixteen minors accumulated. The convention protects a population of consumers that does not exist: there is one consumer, and it pins exactly itself.
- **Rejected:** a cross-repo CI assertion reading the host's lock — over-engineering for a one-consumer package, and it introduces cross-repo coupling in the build that does not exist today.

Recorded for `wordpress-importer-archive#496`.

## Parser fidelity — behavioral tests, not a `ueberdosis/tiptap-php` pin

**`ueberdosis/tiptap-php` is deliberately left transitive in both repos.** Version alignment is a *proxy* for the property anyone actually wants — that the allowlist still strips what it is believed to strip — and the allowlist tests in `wordpress-importer-archive#492` measure that property directly, on every CI run. The per-sweep lock assertion from `wordpress-importer-archive#496` stays as a cheap backstop for drift the tests were not written to cover.

Three mechanics decided it, measured 2026-08-24:

- **A real import never runs this package's parser.** The importer is vendored into `uams-statamic` and invoked as `php artisan statamic:import:wordpress`, so it executes from the *host's* `vendor/`. This package's lock governs its own CI and nothing else, which makes the exposure test fidelity rather than content safety.
- **This package only parses, never renders.** `BardParser` and `TablePressEntryCreator` call `getDocument()`; `src/` contains no `getHTML()` call. The entire `2.1.1` → `2.1.2` delta is `Utils\HTML::renderAttributes()`, on the render path, and is therefore unreachable from this package.
- **The allowlist is identical across those versions.** `<script>`, `<iframe>`, `onerror`, `javascript:` and `data:text/html` hrefs, and `<svg onload>` all produce byte-identical JSON on both, all stripped. A control confirms a benign `href` survives, so `javascript:` is actively removed rather than the mark silently failing.

- **Rejected:** pinning `ueberdosis/tiptap-php` exactly in both repos — it would have guarded a difference that does not exist, added a third constraint moved in lockstep across two repos on top of the `statamic/cms` coupling above, and still gone green on the failure that matters: a build that stops stripping `javascript:` has the same version number until someone changes it.
- **Rejected:** pinning it in the host only — more coherent, since that is where imports execute, but it does nothing for this package's CI fidelity, which is the actual gap. Its separate merit is host-side and unrelated to the importer: `renderAttributes()` raises a `TypeError` on a non-scalar attribute value in `2.1.1` where `2.1.2` skips it, and `'0'`-valued attributes render differently. That belongs to a host ticket if it is wanted.

**Accepted residual:** behavioral coverage is exactly as broad as `wordpress-importer-archive#492`'s payload list, and a host sweep that moves the parser first still goes unnoticed here until this repo's next sweep.

Recorded for `wordpress-importer-archive#717`, which this closes; the acceptance criterion moves to `wordpress-importer-archive#492`.

## Static analysis — larastan declined

`larastan/larastan` is **not** adopted. Its headline value is Eloquent awareness, and `src/` contains zero Eloquent models. The database surface that does exist is negligible: one caught `Illuminate\Database\UniqueConstraintViolationException` in `Assets/AssetUtils.php`, and two `DB::table()` query-builder chains in `KnowledgeBase/ArticleEngagementSeeder.php`. Of 94 Laravel facade imports, 83 are `Log`, backing 248 call sites that Laravel's own `@method` docblocks already type; the container surface is 6 `app()` calls and no `resolve()` at all. It does not understand Statamic's magic, which is what this package actually leans on. The gate stays vanilla PHPStan at `level: max` — which in PHPStan 2.x *is* level 10, the level the host app also pins — with `phpstan-strict-rules`.

The parity worth having was the Pest extension, and it has already shipped: `#511` replaced `mrpunyapal/peststan` with the official `pestphp/pest-plugin-phpstan ^5.0` that the host also runs. Recording the larastan no-go is all this decision adds.

- **Rejected:** adopting larastan for tooling consistency with the host — the host is a Laravel application full of Eloquent models; this is a Statamic addon with none.

Recorded for `wordpress-importer-archive#413`.

## Host-app integration tests — stay on the real `default` site, with a cleanliness assert

The host-app integration tests continue importing into the real primary site. The blast radius they appeared to carry is already bounded: the host's `content/`, `users/`, and asset directories are **git submodules**, so a restore failure is recoverable and inspectable. The actual cause of `wordpress-importer-archive#464` was not that tests write real content — it is that `.gitmodules` sets `ignore = dirty` on the content submodule, muting the one signal that would have shown the stale residue before it was captured as a baseline.

The mitigation is therefore a cleanliness check in the harness — snapshot the `content` submodule before the run, compare after teardown, and fail on what the run introduced — turning the muted signal back on for exactly the window it matters. It is **not built yet**; `#570` carries it. The host's own suite already has the equivalent gate (`uams-statamic-archive#1873`, in its `scripts/local-ci.mjs`), but that gate never sees this package's band, which runs as `php artisan test vendor/uams-web/wordpress-importer/tests/Feature/…` rather than through that runner — so the one suite that writes into `content/` deliberately is the one it does not cover.

- **Rejected:** a runtime-registered disposable site — high cross-repo cost (~27 files, site-name-dependent assertions) for isolation `wordpress-importer-archive#464` already proved is partial, since terms, assets, and collection enablement are not site-scoped.
- **Rejected:** a committed test site — puts test-only config in production.
- **Rejected:** a redirected content root — the strongest isolation, but a bootstrap-level change answering a question that was never the failure.

Recorded for `wordpress-importer-archive#467`; supersedes nothing in `wordpress-importer-archive#464`, which stands as the hardening.

**Superseded on the host side — the rejected third option is the one that shipped.** `uams-statamic-archive#1995` (merged 2026-08-13) redirected the content root after all: `App\Support\ContentRoot::path()` roots every content-backed Stache store, and the Eloquent asset-metadata dual-write, at a disposable mirror under `APP_ENV=testing`. The judgement above was wrong on the cost/benefit, and the host's reasoning is the better one — re-rooting makes the guarantee *structural* ("no store points at the submodule in the test env, so no test can dirty it, including tests nobody has written yet") rather than dependent on every future test remembering a per-test assert.

## The `content/` cleanliness assert — a regression detector for the host's re-root, not a litter policeman

`wordpress-importer-archive#570` asked for the per-test assert `wordpress-importer-archive#467` named. It shipped, but doing what the changed world leaves for it to do.

Since `uams-statamic-archive#1995`, a Statamic save cannot reach the submodule at all, so an assert that policed per-test litter would be a **permanent silent pass**. What that re-root keys on is `ContentRoot::path()`, and it therefore does not cover a raw `base_path('content/…')` — of which this package still has three, one deleting files (`#622`). The host's own `ContentRoot` docblock calls a surface left pointed at `content/` while the mirror moves *destructive* rather than partial.

So the assert's job is to **prove the host's structural guarantee still holds from this side of the vendor boundary**, and to fail loudly the moment a raw path writes through it.

- **Rejected:** dropping the assert as superseded — it is the only thing watching the surfaces `#1995` does not cover, and `wordpress-importer-archive#622` is the evidence that those surfaces exist.
- **Rejected:** pointing the assert at the mirror instead — the mirror is disposable by design, so litter there is harmless and detecting it proves nothing.
- **Rejected:** porting the host gate's `KNOWN_CONTENT_LITTER` allowlist — its list is empty and kept only so a future tolerated path must be added visibly; this package has a better place for an expected write (that test's `snapshotIsolatedState()` call), and PHPStan at level max refuses the empty-list form outright.

Recorded for `wordpress-importer-archive#570`; `wordpress-importer-archive#622` carries the three surfaces.

## Content-heavy import tests stay host-app-bound

The `WordPressImportTestTrait` band is **not** migrated to `AddonTestCase`. Their value is precisely that they run against the host's real collections, blueprints, and Stache; a scaffolded testbench replica would assert the importer works against a content model written to match our own expectations, going green on exactly the app-side drift the tests exist to catch. Migration would convert integration tests into expensive unit tests.

Parser-level assertions continue to be peeled down into `tests/Integration/` as files are touched — already the de-facto practice (`GalleryParserMinimumImagesTest`, `FindADocProvidersFilteringTest`, and six others).

- **Rejected:** migrating the band; **rejected:** running the spike first — the outcome does not turn on fixture cost.

Closes the `wordpress-importer-archive#457` spike opened by `wordpress-importer-archive#319` (decision B).

## Imported display toggles and tethers — derive from what was imported

Three related mapping defaults, all resolved the same way: where the source content asserts something, the importer transcribes it rather than leaving an editor to re-key it.

- **`featured_image_credit_query` (`wordpress-importer-archive#439`)** — set to `true` when the imported featured-image asset ends up with a non-empty `creator`; left unset otherwise. The field reaches 15 blueprints through `import: uamsweb_post_cover_image`. Mirrors the existing `cover_image_query` / `excerpt_display_query` auto-set in `PostEntryCreator`. Its `false` default was chosen for existing hand-authored content, never as a judgment about migrated content; leaving it would silently drop attribution the source site displayed. **Rejected:** leaving the blueprint default.
- **`publish_depends_on` (`#442`)** — imported Provider Spotlights pre-seed the tether to the same `people` entry as `featured_provider`. A spotlight is definitionally about its provider, so this transcribes a relationship the source asserts rather than guessing at editorial intent, and auto-prevents `uams-statamic-archive#1593`'s motivating failure for migrated content. That ticket's "deliberate, never a surprise" principle governs the editor's UI, not migration semantics. Non-spotlight resource types stay manual. Sequenced after `#419`, which has not yet written `featured_provider`. Either way `publish_depends_on` is recorded in the `clinical_resources` field mapping as Statamic-native with no WordPress source. **Rejected:** leaving the tether entirely manual.
- **Person video (`wordpress-importer-archive#501`)** — `_psyoutube` lands on `institutional_video_url` / `institutional_video_link_text` with the provenance recorded in `import_wordpress_notes`, so an editor moving it has the context the importer lacked. `_psvimeo` (4,183 occurrences, currently unmapped) is folded into the same pass. **Rejected:** inferring the context from the person's roles — WordPress records no context to recover, and inference is a *confident* guess that is silently wrong for the multi-role faculty who dominate the corpus. **Rejected:** routing with no note.

Recorded for `wordpress-importer-archive#439`, `#442`, `wordpress-importer-archive#501`.

## Galleries that cannot satisfy the block contract — warn and import

`GalleryParser` imports galleries that cannot satisfy `uamsweb_block_gallery`'s required-field contract and reports each one, rather than skipping or synthesizing. Of 307 `wp:acf/uams-gallery` blocks in the corpus, 198 (64%) carry no `gallery_description` and 15 carry exactly one image; those blocks render nothing until an editor completes them.

`wordpress-importer-archive#291`'s one-image importer minimum **stands**; the block's 2–6 requirement is unchanged; the importer warns where they disagree. `GalleryParser`'s docblock, which still says "min 2, max 6", is corrected to match the code.

- **Rejected:** skipping the block — silently deletes 198 galleries the source site displayed.
- **Rejected:** synthesizing a description — invents editorial copy at scale.
- **Considered and not taken:** relaxing the contract app-side (dropping `block_description` from the required set and lowering the image minimum to 1). This was the recommendation — at a 64% failure rate the contract arguably describes content that does not exist, and `uams-statamic@df1ab6049` applied the guard to 17 blocks in one sweep. It was declined to keep the change importer-only. The accepted cost is roughly 198 blank-rendering galleries pending editorial completion.

Recorded for `wordpress-importer-archive#512`.

## Gravity Forms features with no Statamic destination — skip and warn, never emit a dead config

Three unmapped GF surfaces, resolved on one principle: where Statamic has no destination, the importer reports it and writes nothing, rather than emitting configuration that looks real and does nothing.

- **Save & Continue (`wordpress-importer-archive#33`)** — Statamic has no analog (verified: no save-link, resume, or partial-submission mechanism anywhere in the host app). The importer **skips the action entirely** for `event=form_save_email_requested` and warns with the form and notification id, instead of emitting an email with `to: null` and an unresolved `{save_link}` in the body. 96 notifications are affected, not the 63 recorded in the issue and in `FormConfigurationManager`'s docblock — both counts are refreshed. **Rejected:** importing the broken config anyway; **rejected:** building a Save & Continue feature app-side for 96 notifications.
- **Notification Scheduler (`wordpress-importer-archive#35`)** — the `schedule*` fields are recorded as intentionally dropped with explicit `null` mapping entries and a comment, **and** a warning fires if a `scheduleType` other than `immediate` is ever seen. All 12 scheduler-bearing notifications in the corpus (not 8) are `immediate`, which justifies not building scheduler support — but the corpus is a sample, not a bound, so silently collapsing a future delayed send is not acceptable. **Rejected:** documenting the drop with no warning path; **rejected:** building full scheduler support.
- **Unsupported `product` modes (`wordpress-importer-archive#299`)** — `hiddenproduct`, `price`, and `calculation` emit an explicit unsupported-field warning naming the form, field id, and mode; no silent degradation to plain text and no dead `null` `selection_type`. Corpus counts across 1,997 forms: `singleproduct` 289, `select` 10, `radio` 7, `hiddenproduct` 2, `price` 1, `calculation` 0 — three fields out of 309. **Rejected:** extending the `Product` fieldtype upstream for three fields; **rejected:** a silent documented fallback.

Recorded for `wordpress-importer-archive#33`, `wordpress-importer-archive#35`, `wordpress-importer-archive#299`.

## WordPress taxonomy dispositions

Per-taxonomy destinations for the taxonomy import (`wordpress-importer-archive#13`):

| WordPress taxonomy | Statamic destination | Shape |
| --- | --- | --- |
| `category` | `post_categories` | structured collection (exists) |
| `post_tag` | `post_tags` | flat taxonomy (exists) |
| `uamspeople-categories` | `people_groups` | structured collection — **converted from today's flat taxonomy** |
| `tshowcase-categories` | `people_groups` | structured collection — same destination as `uamspeople-categories` |
| `logo-list-category` | `logos_item_categories` | structured collection — **converted from today's flat taxonomy** |
| `logo-list-label` | `logos_item_labels` | flat taxonomy (exists) |
| `kbtag` | flat taxonomy (exists) | flat |
| `kbtopic` | `knowledge_bases` | handled via `KbLinkRegistry` |

`uams-statamic-archive#252` unblocked the *pattern* (hierarchy via native structured collections), not the *destinations*: only `post_categories` exists today, so the two conversions above are app-side prerequisites for their importer slices. The buildable slice now is the `wp:term` import pass, the `(taxonomy, term_id)` to destination registry, the flat taxonomies, and `category` to `post_categories` with its parent tree — the bulk of the corpus, and what unblocks `#12`.

`KbLinkRegistry` **stays separate**; the new unified registry delegates `kbtopic` lookups to it. **Rejected:** folding it in — refactor risk on a working, tested path for no user-visible gain.

Recorded for `wordpress-importer-archive#13`.

## Vanity redirect domains — Statamic is the record, the edge is the runtime

The seven redirect-only vanity domains keep the `redirects` global set as their authoring surface (editors and the importer unchanged), and a deploy-time step generates edge configuration from it. A redirect-only domain routing every request through a full Statamic boot into the 404 path, to `usort` a replicator, pays a CMS's cost for a lookup table; the store and the runtime need not be the same thing.

`FindRedirectRule`'s parity gaps are to be closed regardless of the hosting choice, since the main site's own redirects still run through it and the generator must reuse the same semantics: **wildcard `*` matching with remainder-append, regex capture substitution into the target, and SRM-matching case-insensitivity**. **All three shipped on 2026-08-07 and this entry described them as missing for a month.** Read from the API rather than from the ticket that reported them: `#1933` and `#1934` closed `completed` on 2026-08-07, `#1943` closed the same day with **no reason recorded**, and `#1942` closed on **2026-08-11**, four days later. All four are closed and the parity work did ship; an earlier revision of this correction said "all closed completed that day", which is two claims it had not checked. What made the staleness hard to see is that the logic **moved**: the file this entry measured at 506 lines is now 190, and the matching lives in `app/Support/Redirects/RedirectRuleMatcher.php`. Verified against a host checkout at `54b9a3a66`, 0 behind `origin/main` — wildcard handling in `isPatternSource()`/`matchesWildcard()`, `preg_replace` present for capture substitution, and byte-wise folded comparison for SRM-matching case-insensitivity.

**The consequence recorded here is void with them.** The importer does still map `_redirect_rule_from_regex` straight onto `use_regex`, so an SRM *wildcard* rule imports as `use_regex: false` with a literal `*` in `source` — and that is now correct rather than broken. `isPatternSource()` treats a non-regex source with a trailing `*` as a pattern, which is exactly the shape the importer emits. The importer was only ever wrong because the matcher could not read what it wrote. **`uams-statamic-archive#1934` closed `completed` on 2026-08-07, so the work this paragraph routed to it is not outstanding either.** It carried query-string and trailing-slash normalization, `target_query_string` on `url` targets, and the missing 404 response branch; 410, 403 and 451 already worked through `respond_with_status`. Anyone needing the current state of that surface reads the host tree, not this entry — no open ticket owns it.

**That pointer survived the first pass of this very correction**, which is worth recording rather than quietly fixing: the paragraph declared the ticket closed and routed work to it in the same breath. A correction is not exempt from the defect it corrects. The edge generator is `uams-statamic-archive#1935`, and `uams.link` is spiked end-to-end (`uams-statamic#190`) before the remaining six domains (`uams-statamic#191`).

**"Seven" counts vanity URL domains only, and does not cover the domain-level redirects.** The seven are `uams.link` plus the six `uams-statamic#191` lists, and each maps many short paths to many destinations. A domain-level redirect sends a whole domain to one URL, and a 2026-08-27 survey found **40** of them, none among the seven. They are inventoried on `#778` and recorded under *The twelve external sites stay on WordPress* below. `uams-statamic#191`'s "remaining six" is **confirmed** rather than corrected: `#133`'s body names seven domains, `uams-statamic#191` names six, and the only difference is `uams.link`, which `uams-statamic#190` spikes. None of the 40 appears in either list. So neither figure is a count of the redirect estate, and a later reader sizing the edge cutover needs both tickets.

`#778` places the 40 in this same model, and they fit the edge subset `uams-statamic-archive#1935` decided. Each is one wildcard rule, source `/*`, with a fixed absolute target. A target without a trailing `*` gets no remainder appended, so every path collapses to one URL. No regex rule comes before it, so `uams-statamic-archive#2449`'s finding that 2,163 patterns are stranded behind a site's first regex rule does not reach them. This was read from `RedirectPattern` and `EdgeRedirectManifestBuilder` on host `origin/main` at `28b1b808c` and not run. What the model does not yet say is where the 40 are *authored*: `redirects:edge-manifest {site}` reads one Statamic site's rules, and none of the 40 domains is a Statamic site.

- **Rejected:** a Statamic site per vanity domain — every request pays a boot, a 404 resolution, and a full replicator scan.
- **Rejected:** pure edge redirects authored outside Statamic — loses the editorial UI and needs a new importer target.

Recorded for `wordpress-importer-archive#133`.

## News `category` to `organizations` crosswalk — machine-drafted, human-reviewed

The crosswalk `#438` consumes is generated mechanically (exact slug plus fuzzy match of the 133 distinct news categories against the 181 `organizations` entries) and then confirmed, corrected, and extended by a human. A prototype resolves 22 exact and 14 fuzzy candidates, leaving 97 unmatched — consistent with most news categories denoting a topic, event, or external organization rather than a UAMS unit.

The verdict stays editorial; only the transcription is automated. The review must **scan the 97 unmatched**, not merely approve the 36 candidates — a category can denote an organization under a name that does not resemble the entry title. Unmatched categories are recorded as deliberately unmapped so the audit can distinguish "reviewed and rejected" from "never looked at".

- **Rejected:** hand-authoring all 133 rows; **rejected:** deferring until `#438` needs it.

Recorded for `#448`.

## MinervaKB `mkb_faq` — no importer support

`mkb_faq` gets no importer support. The corpus contains **zero** `mkb_faq` posts across all WordPress exports (three `mkb_faq_category` terms on one site — terms with no posts), and the `faq_items` destination (`uams-statamic#25`) does not yet exist. Any live FAQ content found later is a one-time manual port into `faq_items`, not importer work; a live check could only distinguish two flavours of "close", since importer work could not start before `#580` ships regardless.

Recorded for `wordpress-importer-archive#22`; reopening is cheap if a site turns up with real volume.

## Recognition-list membership is not in the WordPress export

The create-versus-match-only fork for `recognition_lists` (`#434`) is **not decided**, because both branches need source data that does not exist. Verified: zero `domain="recognition-lists"` occurrences in the corpus (which carries 17 taxonomy domains, none of them this), no recognition-bearing postmeta key on `uamspeople` items, and nothing in the consolidated `uamspeople` field inventory. Every "recognition" match in the corpus is ordinary content text.

The membership data comes from the Find-a-Doc / UAMSHealth.com migration API, not the WXR — so `#434` waits on that source becoming consumable, and is an **action** ticket rather than a decision one. Which ticket carries that dependency is recorded on `#434`'s dependency edges rather than restated here, so this entry cannot fall out of step with it. The 2026-07-07 match-only decision in `uams-statamic`'s decision log stands untouched: nothing has actually challenged it.

Recorded for `#434`.

## TablePress Premium — unblocked, no fork remains

`uams-statamic-archive#243` closed as completed on 2026-07-18 and shipped the destination fields via the `uamsweb_common_table_features` fieldset: `sorting_enable`, `search_enable`, `pagination_enable`, `pagination_count`, `pagination_change_enable`, `info_enable`, `horizontal_scroll_enable`, `column_filter_enable`, `column_filter_columns`, `responsive_stack_enable`, `responsive_stack_breakpoint`. `#21`'s recorded blocker state ("OPEN") was stale.

With the target fields named there is no design fork left: the importer maps the shipped field set and warns on any Premium option key with no destination. The corpus enumeration is an acceptance criterion inside that build, not a gating spike.

Recorded for `wordpress-importer-archive#21`.

## An unresolvable host stays allowed by the egress guard

`OutboundUrlGuard::isAllowedHost()` allows a host that resolves to no address at all. The behavior predates the guard's IPv6 work; it was re-examined rather than inherited when `wordpress-importer-archive#547` put a test seam under resolution, and it **stands**.

An unresolvable name is no SSRF vector on its own: the request that follows cannot reach an address the guard did not get to see either. Refusing instead would convert every transient DNS failure during a multi-thousand-item import into a permanently skipped asset — a certain, recurring cost paid against a narrow attack.

The residual is real and named rather than papered over. Address pinning (`wordpress-importer-archive#541`) makes the address the guard validated the address the request connects to, but it cannot reach this branch: a host that resolved to nothing offers nothing to pin, so it is allowed **and** unpinned. Someone controlling a nameserver can answer `NXDOMAIN` to the guard's lookup and a private address to the client's.

- **Rejected:** refusing unresolvable hosts, which trades a broad availability cost for a narrow window.
- **Rejected:** describing pinning as closing it — the guard's own docblock states where pinning stops.

Recorded for `wordpress-importer-archive#547` and `wordpress-importer-archive#541`.

## The local-CI runner owns the CI slot — a tree nobody drives is not worth having

Branch work moved to stable, pre-bootstrapped worktree slots, which left one fork open: what the runner-owned `../wordpress-importer-ci` slot is actually *for* in a library repo. **The runner drives it.** `scripts/local-ci.mjs --branch` checks the branch out there, reconciles the local ref with `origin/<branch>` first, and prints `Validating: <sha> <subject>`; invoked bare it still runs in place against the current checkout.

The value is not the directory. The runner already self-anchors — `REPO_ROOT` comes from its own file location — so any tree's copy validates that tree, and worktree-safety needed no flag. What a bare slot does **not** buy is validating the commit you think you are validating: `git worktree add <path> <branch>` and `git checkout <branch>` both resolve the *local* ref, so a reused tree silently validates whatever that ref last pointed at. `--fresh` does not fix it — it recreates the tree from the same stale ref. Only the reconciliation does, which is why it is the runner's job rather than a documented four-step preamble.

- **Rejected:** validating in the primary checkout (the status quo). It makes authoring and validating mutually exclusive on one machine, and it takes the primary off `main` — destroying the drift signal ("the primary is on a branch I did not set") that the slot model depends on.
- **Rejected:** a CI slot the runner does not drive. It buys a tree and stops: the validating session then hand-runs `fetch` + `checkout` + `reset --hard` before every run, which is precisely the "a gate that depends on remembering four commands is not a gate" failure the slot tooling exists to remove. It also makes the `-ci` name misleading, since nothing owns the tree.
- **Diverged from the host app deliberately:** `--fetch` is not ported. The host gates fetching behind a flag because a fetch there drags a large assets submodule; nothing sits on this package's validation path, so the runner fetches unconditionally and one of two confusable knobs is deleted.

Recorded for `wordpress-importer-archive#593` (shipped in PR `wordpress-importer-archive#599`). The slot lifecycle is the [`worktrees`](../.claude/rules/worktrees.md) rule; the runner's flags and the `--fresh`-versus-stale-commit distinction are the [`local-ci`](../.claude/skills/local-ci/SKILL.md) skill.

**Superseded in part by wordpress-importer-archive#1319** (below): the private runner is retired, and the shared runner's slot is `../wordpress-importer-local-ci`. What this entry argued, that the runner drives its slot and validates the pushed head, carries over unchanged.

## Trusting `data-set-*` set markup — a per-process nonce, not a constant marker

`data-set-*` is a serialization this package invents and emits itself, yet nothing distinguished markup the importer generated from identical markup typed into a WordPress post. `Set::parseHTML()` copied every `data-set-attr-*` value into the stored Bard set verbatim, so a `<div data-set-type="pull_quote" data-set-attr-quote="&lt;script&gt;…">` in post content landed raw attacker HTML in a field the host app renders unescaped. **The parser now trusts only markup carrying a random token minted once per importing process** — strictly after the WXR input was written, so content being imported cannot carry it.

The **tag selector** matches the nonce rather than `data-set-type`. A planted div therefore never matches the set rule at all and Tiptap parses it as an ordinary div, leaving the author's own text intact; the `getAttrs` nonce check remains as a second layer covering a forged value.

- **Rejected: a constant in-band marker** (the form the issue proposed). This package is readable, so anyone authoring the payload copies the marker in. Only an unguessable per-run value closes it.
- **Rejected: a separate parse pass over importer-generated content only.** Generated markup is `str_replace`'d *into* the post HTML to preserve ordering, so one `parse()` call sees both trust levels by construction; separating them means rebuilding the ordering model.
- **Rejected: sanitizing attribute values.** It keeps trusting the shape, is hand-rolled HTML sanitization, and damages legitimate payloads — `WpBlockBlockProcessor` emits `data-set-attr-content`, which is meant to carry HTML.
- **Rejected: stripping `data-set-*` from untrusted HTML at ingress.** Cheaper (5 entry points rather than 15 emitters) and safe against real content — 0 of 259 sampled WXR exports contain `data-set-type` or `data-set-attr` — but it is a denylist that protects only the parse sites that exist today. A sixth added later is silently vulnerable, where a missed *emitter* under the chosen design merely drops that set, which the set-emission tests catch.

Recorded for `wordpress-importer-archive#494`.

## Audio posts keep their categories — the blueprint grows the field, the importer is unchanged

`PostEntryCreator` assembles `$data` as a **single unbranched array literal**, so `post_categories` and `post_tags` are written for all three blueprints it emits. `posts/audio` defines neither — established across its **11** direct fieldset imports and every fieldset those reach, not by grepping the handle, since a handle arrives through a fieldset without the string appearing in the blueprint. (Worth knowing if you re-derive this: fieldsets here do **not** nest through `import:` — all 11 sit at depth 1 — so the tree expands only through `field: <fieldset>.<handle>` references, and any "N fieldsets reached" figure is an artefact of which traversal rule you pick. The reproducible claim is the 11 and the absence.) So those keys landed as data no field reads. **The host app grows the field; this package writes exactly what it wrote before.** The build is `UAMS-Web/uams-statamic-archive#2026`.

The reason matters as much as the outcome, because the obvious reason is wrong. `wordpress-importer-archive#604` recommended this on the grounds that the alternative "destroys source data", and measuring the corpus does not support that. Of **827** audio-format posts across the 259-export sample, **798 (96.5%)** carry only the audio-series category — which `filterOutAudioSeriesCategories()` already removes — leaving **15 distinct posts on 2 sites** whose categories survive filtering at all. (29 occurrences dedupe to 15: the `_cleaned` exports are byte-identical reprocessings of their siblings.)

At fifteen posts holding a newsroom bucket tag, both options are cheap, so the decision rests on intent rather than cost: `audio` is the **only** one of the four post blueprints without these fields, nothing in the fieldset structure suggests deliberate exclusion, and a blueprint line is reversible where a re-import is not.

- **Rejected:** having the importer omit both handles when the blueprint resolves to `audio`. A three-line change at a site where `$isAudioFormat` is already in scope, but irreversible for those 15 posts, and defensible only if the newsroom actively wants audio episodes browsable by series alone.
- **Rejected:** deferring. Nothing downstream is blocked, but it leaves the orphan-key class `wordpress-importer-archive#422` set out to close open for the sake of a one-line change.
- **Load-bearing for a future reader:** this is why the importer *deliberately* writes a handle a destination blueprint may not define. Removing that write to "fix" the mismatch is the rejected option, not a cleanup.

Recorded for `wordpress-importer-archive#604`. The corpus figure is from the **sampled** exports, which are a sample and not a bound — live WordPress sites not yet exported may hold more.

## Taxonomy navigation items — resolve through the registry by *shape*, never by taxonomy name

`wordpress-importer-archive#12` asked for a `(taxonomy, term_id)` to Statamic-destination lookup so navigation items could point at something other than `kbtopic`. `wordpress-importer-archive#13` had already built it (`TermDestinationRegistry`), so the ticket's real question was how far past the evidence to generalize — and the evidence is narrow. A full sweep of `_menu_item_type` across all **259** exports finds **6** taxonomy nav items: 3 `kbtopic`, already handled, and **3 `category`**. Against 4,811 `post_type` and 1,495 `custom` items. Zero `post_tag`, zero custom taxonomies, zero for the other three structured taxonomies.

**Resolution is a shape check, not a taxonomy list.** `createTaxonomyNavigationItem()` calls `TermDestinationRegistry::resolve()` and writes `'entry' => <id>` when the shape is `SHAPE_COLLECTION` — the shape `createKbtopicNavigationItem()` already writes, and `kbtopic` reaches it through the registry's existing delegation. A `SHAPE_TAXONOMY` result skips with a log line naming the reason. This is **less** code than the `switch ($taxonomy)` it replaces and covers all four structured taxonomies at once; all four destinations exist as collections in `uams-statamic` today (verified on macOS).

- **Rejected:** synthesizing a `url` for flat-taxonomy terms. A Statamic nav branch carries an entry id or a literal string and nothing else, so a flat term can only be a hard-coded URL — which does not move when the route does. The one option that can break silently, for zero corpus instances.
- **Rejected:** an explicit `case 'category':`. More code than a shape check, covering strictly less.
- **Rejected:** closing as won't-fix. Three items is small, but the fix is one method, and a dropped primary-nav link is visible on a live site.

**A registry miss falls back to a live, site-scoped entry lookup.** The registry is a PHP static populated by `initializeTerms()`, so `category` destinations exist only when `PostEntryCreator` ran in the same process. A normal full import is fine — nav is deferred to after the content-type loop, and the `Stache::clear()` before it does not touch a PHP static — but a **nav-only re-run or a split-WXR import leaves it empty**. So on a miss: term id to slug via `XmlParser::getCategories()`, slug to entry id via a `PostCategoryEntryResolver`-shaped query **scoped to the target site**, then skip + log. Site scoping is load-bearing, not tidiness: `post_categories` sets `propagate: false` and its per-site entries carry no `origin`, so an id from another site is a value the host silently drops.

- **Rejected:** skip + log alone (today's `kbtopic` behavior). `PostCategoryEntryResolver` exists *only* because this registry does not survive a run boundary; the same problem deserves the same answer.
- **Rejected:** forcing the term pass ahead of nav import. Drags a full term import, and its content writes, into a run the operator asked to be nav-only.

**Coverage is an Integration test over a purpose-built fixture — because there is no `kbtopic` nav test to copy.** Zero assertions on nav output exist anywhere in `tests/`; `ImportWordPressCommandTest` constructs `NavigationMenuImporter` and never checks what it wrote. This ticket therefore builds the repo's first nav test harness, which is why the original estimate was low. A Feature-only test would give the fix **zero** gate coverage — `tests/Feature/` is not one of the three CI jobs — so the host-app assertion is a second layer, not the primary one.

Recorded for `wordpress-importer-archive#12`. See **WordPress taxonomy dispositions** above for the `wordpress-importer-archive#13` destinations this rests on. The corpus figures are from the **sampled** exports, dated 2026-06-29 / 2026-07-28: the migration re-exports, and live sites may add taxonomy nav items — including flat ones, which skip and log rather than disappearing silently.

## News-block categories — create the terms before the blocks are parsed, rather than repairing the blocks afterwards

A news block's `category` is a `post_categories` entry id (`wordpress-importer-archive#417`). Those entries are created by the term pass, which belongs to the content types that own the taxonomy — `PostEntryCreator`, `PeopleEntryCreator`, `LogoListItemEntryCreator`, and **not** `PageEntryCreator`. News blocks live in a page's `page_builder`, so on a first import the block is parsed before any category entry exists, the resolver misses, and the block is written with no `category` at all — which the host reads as "no filter", rendering every news post instead of one category's.

**`PageEntryCreator` now calls `initializeTerms()` at the top of `createEntries()`, exactly as `PostEntryCreator` does.** Prevention rather than repair: the entries exist before any `page_builder` is parsed, so the `[blogroll]` shortcode path is covered by the same change at no extra cost, and an operator importing *only* pages is covered too.

It passes `PostEntryCreator::getTaxonomyHandlesForPostImport()` rather than `['category']`, and that is load-bearing. `importTaxonomyTermsAtBeginning()` dedups on `$site.'::'.implode(',', $sortedTaxonomies)` — the **whole sorted list**, not per taxonomy — and the post pass passes `['category', 'post_tag']` whenever `import_tags` is true, which is the default. Passing the narrower list would produce a different cache key and make the post pass redo the entire term import, structured `category` tree included. Passing the same list makes the key byte-identical, so the post pass's own call becomes a no-op and total work is unchanged. The price is that a pages-only import also writes `post_tags` terms — which the post import would have written anyway.

- **Rejected:** a deferred re-resolution pass. Genuinely well-supported — `DeferredKnowledgeBaseMounts` is a working 240-line template for this exact shape and `processFinal()` is the hook — but it repairs the miss instead of preventing it, costs a registry plus a `page_builder` set-patching writer, needs separate recording for `[blogroll]`, and still does nothing when the operator imports only pages.
- **Rejected:** reordering `$supportedContentTypes` so `post` precedes `page`. It cannot work. That array is only the order the *menu* is presented in; the command selects one content type per iteration through `ContentTypeSelector` in an operator-driven loop. `DeferredKnowledgeBaseMounts`' own docblock already says so: "content types import in user-selected order."
- **Rejected:** an up-front pass in the command. Same effect, placed where it stops being a property of the content type that owns it.
- **Rejected:** making the dedup key per-taxonomy. The most principled option and the only one that touches a cache four creators share; a good follow-up, not a prerequisite.

**The fix has a measured ceiling, and it is well short of the acceptance criterion as first written.** Across all 259 exports, 32 news blocks make **41** category references (plus 27 `[blogroll]` shortcodes). Checking each against every category slug declared by an export for the placement's own site: **28 are resolvable on that site** and **13** are not. The 13 are blocks filtering posts syndicated from another site — `PostCategoryEntryResolver` scopes to the placement's own site because `post_categories` is `propagate: false` with no `origin`, so another site's id is a value the host drops on read. No ordering or deferral change reaches them, and they are `wordpress-importer-archive#628`.

**Group exports by site using `wp:base_blog_url`, not `WordPressExports.yaml`.** That manifest is hand-maintained and incomplete — **21** of the corpus's 259 WordPress exports are absent from it, including all six `news-uams-edu` files, which is the syndicating site this finding is about. Every export carries `<wp:base_blog_url>` (259 of 259), it is self-describing so it cannot drift from the corpus, and it groups a site's split exports together on its own: 251 distinct values across 259 files, where each duplicate is a multi-part or cleaned variant of one site. Nothing in `src/` reads the manifest, so this is the only place a reader would have been sent to it.

**Correction to the figures this decision was taken on: the split is 28/13, not the 26/15 first recorded, and the two `News` references were never a case problem.** The original survey compared the WordPress value against the declared slugs *as strings*; the runtime does not. Statamic's Stache slug query is **case-insensitive** — verified directly: `where('slug', 'News')` matches an entry slugged `news`, as do `NEWS` and `nEwS`. So those two resolve with no normalization at all, and a slugify step justified by them would have been dead code. The retry that shipped is kept for a class the query genuinely cannot reach — a name whose *punctuation* differs from its slug, `Faculty News` against `faculty-news` — which no casing matches. No such value exists in the sampled corpus; it is cheap depth, reached only after a real miss. Both facts carry tests so neither is re-derived wrongly.

Recorded for `wordpress-importer-archive#624`. The corpus figures are from the **sampled** exports, dated 2026-06-29 / 2026-07-28: the migration re-exports and the 28/13 split will move.

## Form-ID extraction earns a consumer — reconcile against the supplied export rather than deleting the method

`getFormPluginFormIdsUsed()`, surfaced as `get{Plugin}FormIdsUsed()` on all three form coordinators, **had no caller for Gravity Forms or Formidable Forms.** Form creation reads IDs recorded by the block *processors* (`FormPluginCoordinator::processFormImports()` reads `GravityFormsBlockProcessor::getFormIds()`), never the extractor; the extractor's only live consumer is `NinjaForms\UserInteractionHandler`, which prompts once per referenced form ID.

That is why `#632` — both extractors returning nothing from blocks, across all 870 Gravity Forms blocks and all 25 Formidable ones — was a **latent** defect that changed no import behavior, and why no test observed the repair. It is also why `GravityFormsBlueprintImportTest` ran byte-identically before and after: nothing it exercises touches the extractor. The impact was overstated in `#655`, in the `v0.15.1` release notes, and in `uams-statamic-archive#2075`; all three carry corrections.

**The method gains a consumer: reconciliation.** `extractAvailableFormIds()` already intersects referenced IDs against the supplied export and drops the difference **with no output**. The extractor is the only thing that knows what the *content* references, so it is the only thing that can report the gap. Measured across the corpus by pairing each WXR with the Gravity Forms export sharing its site slug: **31 referenced forms across 12 of 133 sites** are absent from their site's export, and import today as a placeholder note with no form and no warning. That is the same silent-empty class as the 103-of-1826 truncation, the availability filter that matched nothing, and `{fields_table}` emptying every notification body.

- **Rejected:** porting Ninja Forms' per-form-ID prompt to the other two plugins. It cannot generalize, and the numbers say so plainly. That model exists *because* a `.nff` holds **one form per file**; a Gravity Forms export is one file carrying many (4, 14, 27, 41 across the corpus), while a single site's content references up to **77 distinct form IDs** — 784 across 133 sites, median 3. Prompting per ID would ask for the same file 77 times on the worst site, and prompt fatigue producing a wrong answer is a new failure mode, not a fix for an old one.
- **Rejected:** deleting the method and its two coordinator wrappers. Tidier, cheaper, and the right answer if the extractor had no possible consumer — but it removes the only signal that an operator's export is incomplete, on a migration where twelve sites currently have exactly that problem and nobody would know. A method that reads as part of the import and is called by nothing is what made `wordpress-importer-archive#632` look like live data loss; the fix for that is to make it live, not to make it disappear.

**The 31 is an upper bound, deliberately recorded as such.** The pairing is heuristic — WXR to the export sharing its site slug — and on a multisite a block may legitimately reference a form living in another site's export. `communityhealth-uams-edu` reports 8 referenced, 12 available and 8 "missing", which has that shape exactly. The implementation therefore reconciles across the **whole supplied set**, not per file, and that is an acceptance criterion rather than a note. Formidable Forms was not separately sized, and whether an unmatched reference should *fail* the import rather than warn is left open until the reconciliation produces real numbers.

Recorded for `wordpress-importer-archive#658`. Corpus figures are from the sampled exports and will move as the migration re-exports.

## The API-backed source materializes WXR — the seam is the file path, not the seven-method facade

`wordpress-importer-archive#587` was written as "implement `XmlParser`'s public contract" — `getContentTypes()`, `getItems()`, `getAttachments()`, `getTerms()`, `getTags()`, `getCategories()`, `getBaseBlogUrl()` — so that "existing importer subsystems run unmodified." **Implementing exactly that contract would have made six entry creators silently import nothing.**

`XmlParser` is a concrete class with no interface, and those seven methods are a facade rather than the seam. The data every consumer actually reads is a **`SimpleXMLElement` in WXR shape**, obtained from a **real file path**. `PostEntryCreator`, `PageEntryCreator`, `PeopleEntryCreator`, `KbEntryCreator`, `TablePressEntryCreator` and `LogoListItemEntryCreator` each reach past the parser entirely — `new XmlStreamParser($xmlParser->getFilePaths())` — and stream the files themselves. `getFilePath()`/`getFilePaths()` carry **20 call sites**, more than every listed method except `getItems()`, and appear nowhere in the ticket's list. `PostEntryCreator` opens with `if ($xmlFilePaths === []) { return []; }`, so a source with no file paths returns zero posts and reports success — the 103-of-1826 truncation, rebuilt on purpose.

**So the API-backed source materializes WXR part files and hands their paths to the existing parser.** Every downstream subsystem is genuinely unmodified, which is the acceptance criterion as written rather than an approximation of it; the silent-empty class is excluded by construction rather than by vigilance, because `getFilePaths()` returns real paths; and the sanitizer, split-part ordering and memory-efficient streaming are reused instead of reimplemented. It also makes `wordpress-importer-archive#588`'s superset proof a **diff between a materialized WXR and a real export**, which is a stronger check than any assertion written against invented payloads.

Synthesizing WXR nodes from non-XML data is already this repo's idiom, not a new one: `BaseEntryCreator` builds `new SimpleXMLElement('<wp:term xmlns:wp="http://wordpress.org/export/1.2/">')` pseudo-terms in two places.

- **Rejected:** in-memory `SimpleXMLElement` synthesis behind a stream-parser factory. Smaller than a full refactor and architecturally tidier than materialization, but it re-opens the failure it is meant to close: correctness depends on having found *every* bypass, and a missed one calls `new XmlStreamParser([])` and yields nothing with no error. The six known bypasses are the ones a grep found today; the guarantee is only as good as that grep stays true.
- **Rejected:** extracting a `ContentSource` interface across both parsers. The right long-term architecture and the only compiler-visible option — but the largest diff in the highest-risk files in the package, taken before a single byte has been read from a live API. It stays available: materialization does not foreclose it, and nothing built here has to be unwound to do it later.
- **Rejected:** implementing the seven-method contract as written. Documented above; it satisfies the letter of the ticket and breaks six creators.

**Two costs are accepted rather than solved.** A faithful WXR serializer is real work and a place bugs hide — mitigated by round-trip tests (materialize, parse, compare) and by `#588` owning the parity proof. And the API's structured `acf` payload **flattens back to `meta`** in a materialized WXR, so the legacy pipeline reads flat meta exactly as it does today while new Find-a-Doc importers needing `acf` read `MigrationApi\Client` directly. That split is deliberate: materialization serves the existing pipeline, the client serves new structured consumers.

Whether the materialized WXR is byte-faithful enough for every consumer's XPath is **not knowable until `wordpress-importer-archive#656` stands the API up and `#589` captures real payloads**. This work is built against payloads shaped by the documented contract, as `wordpress-importer-archive#657` was; that gates the parity claim, not the machinery.

Recorded for `wordpress-importer-archive#587`.

## Form definitions move to the migration API, but only once it can serve all three plugins

The importer sources form definitions from **hand-made per-plugin export files** — 161 Gravity Forms, 12 Formidable, 4 Ninja Forms, committed to the `Exports` submodule and chosen by the operator at import time. Every one is stale from the moment it is committed, which is the defect `#358` exists to remove.

`uamswp-migration-api`'s D-050 built form endpoints ahead of a consumer and wrote down the condition for adopting them: *"If the importer adopts this API for posts and pages, forms follow in the same change."* `--source=api` shipped in `#587`, so that trigger has fired.

**The API serves Gravity Forms and Formidable; Ninja Forms is the gap this decision turned on.** No amount of adoption reached a single sourcing path without something being added upstream, so the decision was really about whether to accept a permanent split. **That condition is now clearing rather than cleared:** `uamswp-migration-api` PR `#76` serves Ninja definitions with the `id` a `.nff` cannot carry, and it is still **open** — the endpoint is not on that plugin's `main`. Adoption work starts when it merges, not before.

**We will ask for a Ninja Forms endpoint and then adopt all three**, rather than adopting the two that are available now.

The measured asymmetry is what made the alternative tempting. For **Gravity Forms** the API returns `GFAPI::get_form()` output, which is the *same native form array the export file contains* — so `FieldtypeMappingTrait`, the 12.6k-line surface that makes this look risky, would see identical input. Only the envelope differs (`{"0":…,"version"}` versus a response body). That is 161 of 177 files retired for almost no work and almost no risk. **Formidable** is a genuine format change: its export is XML while the endpoint serves a bespoke JSON shape. **Ninja Forms** could not be adopted at all when this was decided.

- **Rejected:** adopting Gravity Forms alone. This was the recommendation, and it is cheapest by a wide margin. It was rejected on ending state rather than cost: a second sourcing path maintained for 16 of 177 files is one nobody returns to remove, so the cheap win buys 91% of the benefit and makes the residual permanent. The judgement is that finishing is worth more than starting cheaply.
- **Rejected:** adopting Gravity Forms and Formidable. Retires 173 of 177 and still strands Ninja. It buys Formidable's real format-translation risk without buying the ending state — most of the cost of finishing, none of the finish.
- **Rejected:** adopting none. Keeps one path by keeping the defect.

**The upstream dependency is cheaper here than the shape suggests**, which is the fact that moved the decision. The plugin repo turned around three requests from this one in a single day — `logo-list-item`, the `modified_after` filter, and four Find-a-Doc taxonomies — so "blocked on another repo" is not the wall it usually is.

**The endpoint is a real design question, not a mechanical addition.** `.nff` files are JSON despite the extension, carry **one form per file**, and have **no id** — which is why Ninja Forms alone uses a per-form-ID prompt while the other two read one file carrying many (`#10`). An endpoint has to answer what a `.nff` cannot: which forms exist and what identifies them. That is the entire cost of this option.

**One check could not be made before deciding, and is a condition on the build rather than a settled fact.** Whether the API's payloads carry what the field mapping needs was to be verified by diffing a real API form against a committed export for the same site. It could not be: the dev install has **zero** Gravity Forms and Formidable is not active there, so `/forms/gravity` returns an empty list and `/forms/formidable` returns `404 …forms_plugin_not_active`. The equivalence claimed above for Gravity Forms is read from source, not measured. The first real form must be diffed before the mapping is trusted.

Recorded for `#695`.

## The migration API stands for the migration, and closes when WordPress is switched off

`uamswp-migration-api` reads unpublished content and the raw options table, so where it runs is a question about what UAMS operates rather than about what the importer can build — `#657`'s client takes its base URL from configuration and does not care. Three options: **local-only capture**, **standing deployment**, **deployment for a bounded window**.

**Chosen: a standing deployment for the duration of the migration.** It reaches what the migration actually needs — the `uamshealth.com` root, current ACF for `location` and `provider`, and the site settings that **0 of 259** captured WXR exports carry — without leaving a standing endpoint, and without anyone holding a production database dump.

The framing that makes this look obvious is the wrong one. "Local-only avoids exposing production data" is false: a local capture requires a **production database copy**, because a WXR restore reaches neither the `uamshealth.com` root (no export of it exists) nor current ACF (Find-a-Doc bypasses core postmeta, so WXR serializes frozen rows). All three options move production data; what differs is which artifact leaves and who holds it after. A database dump is every option the denylist exists to withhold, plus users and password hashes, on a developer machine, unfiltered and unrevocable. The endpoint is a filtered read that a deactivation or a secret rotation revokes.

- **Rejected:** local-only. Larger exposure, weaker controls, and cheap only if the data is captured once and never revisited — re-imports have already happened on this project.
- **Rejected:** restoring one of the 259 captured exports locally. Proposed as a cheaper fourth path using artifacts already held; it reaches neither reason `#358` exists, so it is useful for `wordpress-importer-archive#588`'s parity work and is not a route to representative data.

**It closes when the WordPress sites are deactivated at the end of the migration.** This supersedes an earlier revision of this entry, which chose a bounded window closed by a date with a named owner. That reasoning turned on which option *fails safe* when nobody acts, and it rejected "when the capture is complete" because *complete* keeps moving — true of a capture, and not true of this. **"The WordPress sites are switched off" is terminal, externally observable, and self-enforcing**: once WordPress is gone there is no site left to serve the endpoint, so the close cannot be forgotten rather than merely ought not to be.

What that costs is honesty about the shape. This is a **standing deployment for the migration's duration**, not a window — the option this entry had listed as the fallback for when windows become effectively continuous, on the grounds that *a standing endpoint with a named owner beats a fiction of temporary ones*. Recording it as a window would be exactly that fiction.

Three things follow, and they are the price of the trigger rather than objections to it. **The denylist gate becomes recurring rather than one-time** — over months of migration new plugins arrive, and `denylist.stale` drifts with them, so the check belongs on a cadence rather than only at the start. **The credential lives long enough to warrant rotation**, which the runbook covers and a bounded window would never have needed. **The two-act close still applies at the end** — deactivate, then remove the constant — even though switching the site off makes the first act moot, because the constant is what a restored backup would bring back.

**Closing is two acts: deactivate, then remove the constant.** Deactivation removes the surface completely (verified `200` → `404` → `200`, and the plugin has no activation hook and writes no option), but the secret persists in `wp-config.php` and any later reactivation silently restores access under it.

**Before opening, the options denylist must report clean or be acknowledged.** `GET /options` carries a `denylist` block with a `stale` flag and the plugin slugs behind it; `stale: true` is not a stop on its own — a named person reads the added slugs and records that they bring no credential-bearing options. `stale` fires on any uncovered plugin including local tooling, and a gate that cries wolf gets skipped. That matters here because the **first** denylist review covered one development site and would have leaked credentials on **production sites it never examined**.

Procedure in [`docs/runbooks/migration-api-secret.md`](runbooks/migration-api-secret.md). Recorded for `wordpress-importer-archive#668`.

## Cross-repo characterization tests — tag the test, grep at release time, build no machine

A **characterization test** asserts today's behavior including a known gap, so the boundary is
visible in the suite rather than assumed away. This codebase endorses the pattern. The cost it
carries is that every such test is a **scheduled future red**: the fix inverts it by design. When
the fix is same-repo the author sees it in their own diff; when it is cross-repo the red lands in
the consuming tree, on whoever bumped the pin.

**A characterization test whose inverting ticket lives in the sibling repo tags itself
`@invertsOn org/repo#N`, and a ⚠️ release greps the host checkout for tickets in its range and
names the hits in the callout.** Two lines of convention and one step in an existing flow.

- **Rejected:** an automated sweep, a CI job, or a cross-repo bot. The measured cross-repo count
  is **one** live instance ever, and it is now resolved — a checker running against a sibling
  checkout is exactly the tooling that rots when the path assumption breaks. Revisit at ~3.
- **Rejected:** a `blocked_by` edge as the mechanism. It would not have helped: the flip branch
  existed before the adopting ticket did, and cross-repo edges are not what the board resolves.
- **Rejected:** relying on prose alone. The docblock already said the test was built to be
  flipped. Prose is not greppable by a release author who has not read the file, which is the
  whole failure — `UAMS-Web/uams-statamic-archive#2025`'s *Known-red* list was derived from `v0.13.0`'s
  release notes and so could not mention what those notes had no way to know.

**The convention landed with no live instance to tag, and that is recorded rather than papered
over.** `#494` inverted `uams-statamic`'s `BardParserSetAttributePassthroughTest`; `#2025`'s
cherry-pick flipped it before this rule shipped, and a flipped test correctly carries no tag.
Re-counted on 2026-08-24: three characterization tests remain in `uams-statamic`
(`FormatBitrateTest`, `StacheIsolatedStructureResolutionTest`, `ModifierAntlersPipeSyntaxTest`),
all pinning same-repo behavior, and none in this package. So the worked example is historical, and
the first real use of the tag will be the next cross-repo gap somebody pins.

**The DRY line.** This decision covers *naming* a test that a breaking change will invert. It does
not cover deciding whether the breaking change is right — the ⚠️ classes stay above in this file,
the callout format stays in the `writing-release-notes` skill, and the tag's own semantics stay in
[`tests/CLAUDE.md`](../tests/CLAUDE.md).

Recorded for `wordpress-importer-archive#614`.

## Team Showcase secondary taxonomies — deliberately unmapped, and the discard is reported

The six optional secondary taxonomies across Team Showcase and its UAMSWP fork — `tshowcase-taxonomy`, `tshowcase-ctaxonomy`, `tshowcase-dtaxonomy` and the three `uamspeople-` equivalents — are recorded as having **no Statamic destination**, using a third shape (`SHAPE_UNMAPPED`) alongside `SHAPE_TAXONOMY` and `SHAPE_COLLECTION`. Their terms are not imported; the import now reports what was discarded instead of dropping it in silence.

Only `tshowcase-taxonomy` is populated today — 71 terms on `medicine-uams-edu_gme`, labeled "Departments", flat, multi-select, attached to 311 people and rendered beside each name by 8 of that site's 14 `show-team` shortcodes. Before this, it was absent from the table rather than recorded, and the discard happened earlier than it appears: every entry creator passes only the handles it routes, and the term pass admits a term solely when its taxonomy is in that list. So its terms were dropped at the handle filter and never entered the pass at all — nothing written, nothing logged, exit `0`. That is why the reporting hangs off the filter rather than the per-taxonomy loop; a guard inside the loop alone would never execute. All six are covered rather than just the populated one because each is gated behind a per-site plugin toggle, and `uamspeople-categories` appears on 103 sites: a toggle flipped anywhere would otherwise reproduce the same silent loss.

- **Rejected:** mapping onto `specialties_acgme` — 49 of 71 terms match a term title exactly and 12 more by substring, but 10 match nothing and need human adjudication, the host's only `specialties_acgme` field is nested inside the training-history structure rather than a person-level label, and canonicalizing would rewrite strings that render live (`ObGyn` → *Obstetrics and Gynecology*).
- **Rejected:** `SHAPE_COLLECTION` as a sibling to `tshowcase-categories` — the taxonomy is flat, and collection shape exists for hierarchy.
- **Rejected:** `SHAPE_TAXONOMY` — indistinguishable from having made no decision, and it does not describe what happens.

The `?? SHAPE_TAXONOMY` fall-through for *unrecorded* handles is deliberately unchanged, so a taxonomy from a site nobody has exported yet still imports rather than vanishing. The five remaining unowned handles are `wordpress-importer-archive#743`.

Recorded for `wordpress-importer-archive#741`.

## Departmental News Hub — designate every mapped site, and hand the schema objects to the app

The importer sets each migrated site's `uams_multisite_settings.department_organization` for **every**
site its crosswalk maps, accepting that `php artisan multisite:audit-department-hubs` will then fail
for the sites whose `schema_subject` is empty, and reporting those sites so the failure is attributed
rather than discovered.

What settles it is a count. `schema_subject` points at the **`schema_objects`** collection, which holds
**266 entries of which only 18 are eligible** as an Organization subject — 5 `Organization` plus its
accepted descendants, since `EntrySchemaTypeIn` expands through `SchemaType::descendantsOf()`
(`OrganizationRole`, 18 more, descends from `Role` and does not qualify). Against **181**
`organizations` entries, populating `schema_subject` is therefore not a second crosswalk onto existing
entries but the authoring of roughly **163 new ones**, in a collection this importer does not touch and
which `organizations` entries carry no reference to. That is content work in `uams-statamic`, and it is
now [`uams-statamic#197`](https://github.com/UAMS-Web/uams-statamic/issues/197).

The audit is a manual command, wired into no CI, and green today only because **zero** sites are
designated — its docblock says it exits zero when there are no hub sites yet. So a red audit during an
incomplete migration states a true fact about migration progress, and its output is the worklist for
the authoring rather than a regression to avoid triggering.

- **Rejected:** designating only the sites whose organization already has an eligible schema object.
  Caps the import at ~18 of 181 to keep green a gate that gates nothing today, leaves the remainder
  needing the by-hand designation this work exists to remove, and makes completion depend on another
  collection's authoring progress — so the ticket would have no checkable done condition.
- **Rejected:** designating everything and suppressing the audit with a flag or allowlist. Buys a green
  gate with a new app-side switch that can be left on, hiding the gap at exactly the pre-launch check
  it was built for.
- **Rejected:** holding `wordpress-importer-archive#768` open until the schema objects exist. Blocks an importer ticket on
  app-repo content authoring; the halves are separable and now separately owned.
- **Rejected:** reporting the gap in the import audit and filing nothing. The content lives in the app
  repo, and a gap visible only to whoever runs a command is how a pre-launch gate goes red unowned.

The field and the audit themselves were delivered by
[`uams-statamic-archive#1556`](https://github.com/UAMS-Web/uams-statamic-archive/issues/1556), which populated neither
because no site had ever been designated. `wordpress-importer-archive#768` is the population half; `#452` designates the hub
*page* through the same per-site global.

Recorded for `wordpress-importer-archive#768`.

## An imported page or post with no featured image omits the handle entirely

`FieldMapper`'s `_thumbnail_id` case writes `featured_image_16_9` only when the imported path is neither `null` nor `''`, matching the gate `KbEntryCreator` has carried since `wordpress-importer-archive#751`. Both values are reachable: `importImageWithDisabledUploadHandling()` returns `null` on three paths, and the empty string when uploads are disabled and the attachment's extracted URL is empty — the shape of an attachment row whose file has left the media library.

**The deciding evidence was an enumeration of what reads the handle, not the symmetry of the two importer paths.** Across `uams-statamic`: 12 Antlers templates, all gating on truthiness (`{{ if }}`, `{{ if ! }}`, `{{ elseif }}`, ternaries, tag-pairs); 7 blueprints and fieldsets, declaring it through `uamsweb_post_cover_image`; and 6 host-app PHP references, of which the load-bearing one is `NewsSyndicationQueryService`'s `! empty($entry->value('featured_image_16_9'))`. Presence checks — `array_key_exists`, `has()`, `isset()` — across `app/` and `resources/`: **zero**. In Antlers `''` and an absent key are both falsy, and `empty('')` and `empty(null)` are both true, so no consumer today distinguishes them. The one place an empty value could have rendered differently, the `thumbnailUrl` schema-meta pipe in `knowledge_bases/_article_card.antlers.html`, sits inside `{{ elseif featured_image_16_9 }}` and is never reached.

That matters because `featured_image_16_9` is an **asset** field on 14 blueprints, and the importer should not hand an asset handle a value that means "no image".

**Two corrections, recorded because the first two versions of this entry were wrong.** They are kept rather than overwritten, because how the understanding moved is the reusable part.

1. The original reasoning claimed an empty string under that handle "is a value Statamic augments as an asset reference". It is not. `Statamic\Support\Arr::removeNullValues()` filters on `! in_array($item, [null, ''], true)`, so `Entry::fileData()` discards `null` and `''` alike and neither reaches disk.
2. The second version then claimed the gate "omits the handle". It did not. `PageEntryCreator` seeded `'featured_image_16_9' => null` into the same `$data` it passes by reference into `mapFields()`, so gating the assignment merely left the seeded `null` in place — the key stayed present, and only its value changed from `''` to `null`. The claimed parity with `KbEntryCreator` was false too: that creator has no such seed, which is why it genuinely omitted the key while the pages path never could.

**What was decided.** The seed is removed from `PageEntryCreator` **and** from `PostEntryCreator`, and `PostEntryCreator`'s own ungated assignment is gated to match. All three creators now genuinely omit the handle when nothing lands, which is what the entry claimed from the start and only now describes.

`PostEntryCreator` was never in `wordpress-importer-archive#754`'s scope and is included deliberately: it carried the identical seed-plus-ungated-assignment shape, so gating only the pages path would have left the same defect in place one file over while the decision record claimed consistency.

**The seed-and-assignment shape is identical; the resolvers are not, and a third correction belongs here.** The pages path resolves through `ImageProcessor`, which guards the attachment *id* and returns `wp:attachment_url` verbatim — so a row with an emptied URL yields `''`. The posts path resolves through `FileProcessor`, which tests `if ($fileUrl === '') { return null; }` *before* consulting the uploads-disabled setting — so the same row yields `null` there, and `''` arrives only from the uploads-enabled branch. Both absent values are reachable on both paths and the gates are right; only the reason written beside the posts gate had been copied from the pages one.

**The saved entry is unchanged by any of this**, and that is stated plainly rather than buried: `removeNullValues()` already discarded both values.

**That strip is conditional, and the guarantee is contingent rather than structural (wordpress-importer-archive#772).** `Entry::fileData()` applies it only to a root entry:

```php
$data = $this->data()->all();

if ($this->isRoot()) {
    $data = Arr::removeNullValues($data);
}
```

`HasOrigin::isRoot()` is `! $this->hasOrigin()`, so a **localized** entry keeps both `null` and `''` in its file data. Every entry this importer writes is a root — `origin(`, `setOrigin` and `localize(` appear **zero** times across `src/` — which is why the claim holds today. It would stop holding the moment the importer grew multisite propagation and began writing localized entries, and nothing else in this entry would signal that. Revisit this paragraph, not just the gates, if that changes. The invariant bought is that `$data` never carries a meaningless value under an asset handle, and that the importer does not depend on Statamic's strip-on-save to launder its own output. Rejected alternatives, and why option A ("withdraw, since nothing observable changes") was not taken, are on `wordpress-importer-archive#754`.

**Where it is asserted.** A host-app test reading saved entry data cannot distinguish gated from ungated — disabling the gate left all six featured-image host tests green — so the assertion lives at the `FieldMapper::mapFields()` boundary in `tests/Integration/PageFeaturedImageGateTest.php`, where the effect is visible. Both halves of the condition are mutation-verified separately: dropping `!== null` fails only the missing-attachment case, dropping `!== ''` fails only the empty-URL case.

- **Rejected:** leaving the pages path writing the empty string. Zero cost, and — as the correction above establishes — zero behavior change *on disk either way*. It was rejected because it preserves a value under an asset handle for no reader's benefit, and would have required documenting the divergence from the KB path so the inconsistency read as deliberate rather than overlooked.
- **Rejected as an argument, not an option:** "the two paths should match." `wordpress-importer-archive#751` made one path stricter; the pages path was unchanged. Symmetry was never the case for gating — the absence of a consumer that cares is.

**Residual:** whether `''` versus absent matters to a *future* consumer — a new template, or an entry authored in the Control Panel — is not settled by an enumeration of today's readers.

Recorded for `wordpress-importer-archive#754`.

## WP Adverts `advert` — build importer support, with the destination filed separately

`advert` gets importer support. The corpus carries **229** listings on one site (`inside.uams.edu/classifieds`), and unlike `mkb_faq` the board is actively posted to — 160 listings over the last 14 months, a median of 12 a month (range 3–16), most recently 2026-08-25 — with 560 advert-parented attachments and 564 image references. Auto-expiry is the plugin's normal lifecycle rather than decay (`_expiration_date` is set on all 229, and everything in the 2026-08-27 export expires by 2026-11-23), so the number that matters on any cutover date is the live board of **39**, not the cumulative 229.

**Every figure here was measured at `wordpress-importer-exports@d5e1cf9`**, a 2026-08-27 re-capture of the same board, which is two commits ahead of the gitlink this repo pins. A reader who initializes the exports submodule lands on `cb81822` — a 2026-05-22 capture — and will measure a smaller board: 204 listings, 26 published, 494 attachments, most recent 2026-05-18. Both columns support the decision identically, and the image-gallery depth that ruled out a manual port is 12 at either revision. Advancing the pin belongs to `#774` rather than here, because a newer capture can introduce a taxonomy that `TaxonomyDispositions` has no disposition for and turn the corpus guard red.

No Statamic addon replaces it. A 2026-08-27 marketplace survey returned **zero** for `classified`, `marketplace` and `directory`, and three irrelevant hits for `listing`, against a `seo` control returning 35 — so the zeros are the ecosystem, not a broken query. The destination therefore has to be built: it is `uams-statamic-archive#2096`, and the importer work is `#774`, blocked by it.

Only the free core plugin is in use. Every meta key and post type the WP Adverts `payments` and `featured` addons write — `is_featured`, `_adverts_payment_*`, `_adverts_pricing_id`, `adverts_visible`, `adverts-pricing`, `adverts-payment` — returns zero across the export, against controls returning 229 and 458, so neither payments nor featured listings need modelling. Posting is anonymous: `_adverts_account` is empty on all 229, so there is no WordPress user to resolve and no author mapping to make.

- **Rejected:** no importer support — porting the live board by hand at cutover, mirroring `wordpress-importer-archive#22`. Defensible on volume alone, since 39 live listings is an afternoon's work, and rejected because the board is in continuous use and its listings carry ordered image galleries (up to 12 on one advert) that a manual port would not preserve.
- **Rejected:** defer post-MVP. It costs nothing immediately and leaves `advert_category` permanently unrecordable, since `wordpress-importer-archive#743` deferred that disposition to this ticket.

`advert_category` stays in the `$ownedElsewhere` list in `tests/Unit/TaxonomyDestinationTest.php` until `wordpress-importer-archive#774` records it: the decision is made, but the disposition cannot land before the destination collection exists.

Recorded for `wordpress-importer-archive#23`.

## The twelve external sites stay on WordPress — and domain-level redirects are a class nobody owns

None of the twelve "external" production sites migrates into the Statamic multisite. `uasys.edu` is pulled into its own WordPress install and the rest are consolidated into one; all stay WordPress. **This package therefore has no work for any of them**: it imports WXR into Statamic, a WordPress-to-WordPress consolidation does not touch it, and none of the twelve has an export in the corpus.

Verified 2026-08-27 from a clean client: all eleven content sites return 200 and are WordPress — ten report generator `WordPress 7.0.4`, and `prospermeniere.com` strips the generator tag but carries 24 `wp-content`/`wp-includes` markers. Two entries never fitted the "external site" framing: `sites.uams.edu/strides/` is a password-protected **path** on a `uams.edu` host rather than a domain, and `arpoisoncenter.org` serves no content at all.

**`arpoisoncenter.org` is a redirect-only WordPress install, not a DNS or edge redirect** — the 301 to `pharmacy.uams.edu/arkansas-poison-and-drug-information-center/` carries `x-uamswp-safe-redirect-manager: true`, `x-uamswp-safe-redirect-id: 267` and `x-redirect-by: WordPress`.

**It is not, however, an eighth vanity domain, and the distinction is the substance of the finding.** Two different things had been filed under one name:

- A **vanity URL domain** maps many short paths to many distinct destinations. Verified: `uams.health/covidvaccine` and `uams.health/womenscenter` resolve to unrelated targets, and `uams.info/give` leaves UAMS entirely for `securelb.imodules.com`. Probing such a domain with an invented path is misleading — it hits a catch-all that returns the site root tagged `utm_term=404_<path>`, which reads as "the whole domain collapses" when it does not. `wordpress-importer-archive#133`'s seven are this kind.
- A **domain-level (TLD) redirect** exists to send one domain to one URL. Every path collapses by design. `arpoisoncenter.org` is one; `bepartofthecure.com` → `cancer.uams.edu/cure/` is another.

UAMS already names the second concept in its own attribution: the redirects carry `utm_campaign=redirects_tld` and `utm_term=tld-root`. The same tagging appears on a vanity domain's *root*, which is what makes the two easy to conflate — a vanity domain has a TLD redirect at its root, but a TLD-redirect domain is nothing else.

**The class has no owner, and it is not small.** A 54-domain list surveyed on 2026-08-27 found **40 domain-level redirects** — and every one points at a destination host that is in the migration corpus, so all 40 target content that is moving. **Four already land on a 404** today, before any migration: `arlionseyebank.com`, `ilivehealthy.org`, `methresearch.com` and `myrideforresearch.com`. Implementations disagree — `arpoisoncenter.org` runs WordPress with the Safe Redirect Manager fork and sends **no** UTM parameters, while 39 of the 40 tag `utm_campaign=redirects_tld`. A further 13 domains in the list serve nothing at all, one of them resolving to an RFC 1918 address and one blocked on the UAMS network as a "dating" site. None of this appears in `#133`, whose scope is the seven vanity domains, nor in `uams-statamic#191`, sized as "the remaining six domains". `#778` carries the full inventory and the disposition.

Two facts make that more than bookkeeping. A TLD redirect's **target can itself be migrating** — `arkansas-poison-and-drug-information-center` is a published page (id 84009) in `wordpress-export_pharmacy-uams-edu.xml`, and College of Pharmacy (root) is `#48`. And **nothing on the pharmacy side carries the redirect as a rule**: `redirect_rule` items naming `arpoisoncenter` number **0** against **179** in that export, so the scan had something to find and found none. The rule lives on the redirect domain, and migrating the target leaves it pointing at a URL nobody in this repository maintains.

The four `arpoisoncenter` occurrences are smaller than they look, and worth stating precisely: **one** is an authored outbound link — an ACF button on the published *Departments and Units* page — and the other **three** sit in `_seopress_analysis_data`, an SEO plugin's cached analysis blob rather than anything a person wrote, one of them on an unpublished draft. **None is a nav-menu entry**; the export's 29 `nav_menu_item` posts name this domain nowhere. So the round trip out of pharmacy and back is real but rests on a single button.

- **Rejected:** filing per-site migration tickets on the `migrate content` pattern. The pattern presumes a Statamic destination, and the disposition removes it for all twelve.
- **Rejected:** completing the per-site evaluation matrix `wordpress-importer-archive#134` was filed to produce. The matrix existed to yield a recommendation; a stakeholder supplied one covering every site, so filling it in would have been the same answer at more cost. The verified platform and status table above is kept because it is what makes the disposition checkable.

**Unresolved, and outside this repository:** which install the consolidated WordPress target refers to, and whether `projectone.uasys.edu` follows `uasys.edu` into its own install or joins the others. Neither changes the outcome here, and both are recorded rather than guessed.

Recorded for `wordpress-importer-archive#134`.

## AR ConnectNow exists twice — import the older site, drop the duplicate

Two WordPress sites serve one property. Only `uamshealth.com/ar-connectnow` is imported; `uamshealth.com/arconnectnow` is dropped.

They are near-duplicates by design rather than by accident. The second was stood up with specific terms and phrases deliberately omitted. So the smaller export is **not** a stale partial of the larger one — the difference is deliberate, which is exactly why dropping the wrong one would be silent and hard to notice.

**`ar-connectnow` is the older site**, by about eleven and a half months. Both exports carry `post_id 1` — WordPress's default first post — so its date is effectively site creation:

| | Created | Content spans | Items | Size |
| --- | --- | --- | ---: | ---: |
| **`ar-connectnow`** | **2025-05-02** | to 2026-06-02, 13 months | 241 | 2.3 MB |
| `arconnectnow` | 2026-04-17 | to 2026-04-21, **4 days** | 209 | 1.7 MB |

Three independent signals agree. It is the larger export, which is what an original rather than a pruned copy looks like. The `arconnectnow.com` and `arconnectnow.org` domain-level redirects both resolve to **`/ar-connectnow/`**, so the redirect layer already treats it as canonical. And the duplicate's entire content history sits inside a four-day window, which is the shape of a bulk duplication rather than a site that grew.

- **Rejected:** importing both. It produces near-identical content in Statamic for one property, and no automatic check would catch it — `wp:base_blog_url` groups them as two sites because they *are* two sites, and the only shared signal is an identical channel title, which nothing inspects.
- **Rejected:** importing the smaller one, or merging the two. The omissions in `arconnectnow` are the point of its existence; carrying them into Statamic would import a constraint that belongs to an external ad platform, not to the content.
- **Rejected:** waiting for the upstream consolidation. It is planned but unscheduled, and the migration does not block on it.

**Residual:** the two sites are to be consolidated upstream. When that happens, the surviving export supersedes this decision and both files here go stale — re-export rather than reconciling them by hand. Note also that **both exports are among the 21 absent from `WordPressExports.yaml`**, so neither carries a `status` or `issues` note; that manifest is not where this is recorded.

Recorded for `#793`.

## An imported entry with no summary source gets `null`, never a synthesized one

`excerpt` is a **required** field on every front-end-view blueprint (`UAMS-Web/uams-statamic-archive#1378`). The importer resolves it from the WordPress excerpt, then `_seopress_titles_desc`, then `seo_meta_description`. When a page or post carries none of the three, the importer writes **`null`** and stops there. It does not derive a summary from body copy, and it does not write an empty string.

The two rejected alternatives fail in opposite directions, and both fail quietly:

- **Rejected: synthesize from body copy** — the first N characters, the first sentence, the first paragraph. It is the tempting one, because it makes every entry satisfy `required` on import and the failure disappears. That is exactly the problem: a truncated opening sentence is not a summary, it reads as one, and nothing downstream distinguishes a real editorial summary from a machine-cut fragment. The field would be full and wrong, across the highest-volume collections in the migration, with no signal telling an editor which entries needed attention. A summary is editorial work; inventing one is a content decision the importer has no standing to make.
- **Rejected: write `''`** — a blank string satisfies `required` in the YAML file while leaving the CP field visibly empty. It converts a loud failure into a silent one, which is strictly worse than the gap it papers over.

`null` keeps the requirement genuinely unmet, so the entry surfaces in the CP as needing an excerpt. The importer's job is to carry across what WordPress had; where WordPress had nothing, the honest import result is nothing.

**Consequence to expect:** imported entries whose source had no excerpt and no SEO description will fail blueprint validation until an editor writes one. That is the intended outcome, not a defect to be reported — and it is the mechanism by which those entries get found at all.

Recorded for `wordpress-importer-archive#429`.

## Unsupported form fields are skipped, and `alert_unsupported` decides whether that is announced

A form field type the mapping reports as **unmapped** produces no blueprint field, and whether the skip is announced is read from that type's own `alert_unsupported` flag: `false` means skip silently, anything else warns.

Before this, such a field was emitted as a **`text`** field. The skip guard in `FormBlueprintCreatorTrait::createBlueprintWithFields()` never consulted `isFieldtypeMapped()` at all — it tested whether the mapping *entry* was null, which no unsupported entry is. A captcha therefore became an editable text input on a public form.

**A null `default` is not the test for "unsupported", and reading it as one drops working fields.** It means *"there is no single default — resolve the fieldtype from this field's `inputType`."* Nine Gravity Forms entries declare a null default and they split cleanly:

| | entries | shape |
| --- | --- | --- |
| genuinely unsupported | `image_choice`, `captcha`, `gf_no_captcha_recaptcha`, `quantity`, `shipping` | null default, **no `attributes` block** |
| mapped through attributes | `multi_choice`, `poll`, `survey`, `post_tags` | null default **plus a real `inputType` → fieldtype map** |

The second group's attribute blocks name concrete Statamic fieldtypes: `survey + likert` is a `likert_scale`, `multi_choice + radio` is a `radio`, `poll + select` is a `select`. `isFieldtypeMapped()` already drew this line correctly — `isset()` is false for a null default, so it falls through to the attributes check — and the fix is to *ask* it rather than re-derive the answer in the guard.

**This is recorded because the first cut got it wrong.** That version guarded on `default === null` directly and additionally made a null default decisive inside `isFieldtypeMapped()`. It would have **dropped 261 correctly-mapped field instances to fix 95** — a likert scale, radio group or checkbox set vanishing from an imported form, which is the inverse failure and harder to notice, since a missing field leaves nothing to look at. It was caught in validation, not by the suite: the gate cannot see blueprint assembly, and `GravityFormsBlueprintImportTest` builds synthetic forms containing no `multi_choice`, `survey`, `poll` or `post_tags` at all.

**The warning half looked like a fresh policy question and was not.** The answer was already recorded per-type in `alert_unsupported`, and the code ignored it — `warnIfFieldtypeUnmapped()` never read the flag, so `captcha` and `gf_no_captcha_recaptcha` warned despite both declaring `false`. Only the *attribute*-level path consulted it. Honoring the flag enforces a policy already written down rather than inventing one.

- **Rejected — always warn on skip.** Adds ~72 `captcha` warnings to a full import about a type with no Statamic equivalent and no prospect of one. That is how a warning channel stops being read, and it discards the only per-type judgment anyone recorded.
- **Rejected — always silent on skip.** Nothing would then record that a field was dropped except the export nobody reopens after the migration.
- **Rejected — guarding on the null default.** The measured cost above; it treats a resolution strategy as an absence of one.

**Residual:** `survey + rank` maps to an explicit `NULL` attribute value — 3 instances in the corpus — so it still falls through to `text`. That is an attribute-level miss inside an otherwise-mapped type, distinct from the entry-level defect fixed here, and is not addressed. A skipped field also leaves no trace in the blueprint, so an editor sees no sign anything was dropped; that is `wordpress-importer-archive#831`.

Recorded for `wordpress-importer-archive#815`.

## 2026-09-04 — Converge the Slack workspace binding on the canonical three fields (wordpress-importer-archive#868)

**Decision.** Reduce `.claude/slack-workspace.json` to the shape canonical for every repository on the bus — `team_id`, `token_env`, `coordinator_bot_id` — declaring the coordinator app's bot identifier as `coordinator_bot_id` and **removing** `team`. Declaring the identifier designates that Slack app as this repository's coordinator: a message it posts as `type: x-directive` renders a verified verdict to a session working here, where every directive previously rendered `(coordinator not configured - cannot verify)`. The designation grants an identity's directives the right to be read as authenticated; it grants nothing about what those directives may ask for.

**Why.** `verifyBotId()` in the plugin's `slack-watch.mjs` compares a message's Slack-assigned `bot_id` against `coordinator_bot_id` read from this file, and returns `unconfigured` on its first line when no value is declared. With the field absent the check is inert — not unused, unreachable. Measured on this channel on 2026-09-04 (macOS, plugin 2.23.3), reading 200 messages back through `conversations.history` and parsing context-block elements: `x-directive` from the coordinator lane carries the coordinator app's identifier across 14 messages, while every other type from that same lane — `request`, `status`, `claim`, `done`, `x-ping`, `x-presence`, `x-update` — carries the ordinary app's identifier, as does every message from the other twelve session labels. A deliberate negative control already sits on the channel: an `x-directive` posted under an ordinary session label, carrying the ordinary identifier, which this repository's reader rendered byte-identically to a genuine one. The discriminating evidence was on the wire the whole time with nothing to compare it against. That is the narrow thing this fixes: a declined comparison, not missing data.

**`team` is removed on two grounds, and the disclosure argument made elsewhere does not apply here.** *Redundancy:* all three tooling scripts read it, in the precedence chain `want.team_id ? eq(team_id) : want.team ? eq(team) : eq(url)`, so the branch is unreachable while `team_id` is declared. Recorded because "drop a field three scripts read" otherwise reads as unsafe to whoever checks. *Uniformity:* one shape across every repository is auditable at a glance, where a per-visibility exception is a rule someone has to remember. The **disclosure** argument — that the workspace name is the binding's only field with reconnaissance value — is sound only where a repository is public and the value is not already committed elsewhere in it, and this repository is neither: [`CLAUDE.md`](../CLAUDE.md) commits the channel id, the channel name and the credential's variable name, in the same tree and in the section the binding note belongs to. Removing `team` reduces no exposure here. Asserting otherwise would invite a later session either to scrub the larger disclosure sitting beside it or to wonder why one was removed and the other kept.

**What the badge does not buy.** Verification is per message and per token. One identifier covers thirteen session labels across several repositories and two machines, so it distinguishes *coordinator token* from *not coordinator token* and nothing finer. It is gated on the sender's self-asserted `type:` element, so a message omitting `x-directive` is never checked — the coordinator's own untyped provisioning message is the standing instance. And the types that allocate work and record outcomes — `request`, `claim`, `done` — remain unverifiable after this change exactly as before it.

**Rejected.** *Keep the four-field shape* — costs nothing to leave, but retains a field no code path reads and keeps this binding a shape apart from every other one. *Add `coordinator_bot_id` without removing `team`* — satisfies the title and not the shape, and is the near-miss this ticket calls out explicitly. *Declare the identifier earlier, before the read path was measured* — a green verdict over an unverified comparison invites exactly the trust the code had not earned, where `unconfigured` is at least honest about knowing nothing; the objection was discharged by measurement rather than waived. *Treat the identifier as a credential and keep it uncommitted* — it authenticates nothing on its own and is useless without the token, which stays machine-scoped under the name `token_env` points at.

**Companion.** #868. Sibling: [`UAMS-Web/uams-statamic-archive#2167`](https://github.com/UAMS-Web/uams-statamic-archive/pull/2167), the same change from the same decision; the canonical shape was decided at [`UAMS-Web/UAMSWP-Find-a-Doc#830`](https://github.com/UAMS-Web/UAMSWP-Find-a-Doc/issues/830). Files: [`.claude/slack-workspace.json`](../.claude/slack-workspace.json), [`CLAUDE.md`](../CLAUDE.md), whose Slack bus section now carries the three standing statements the criteria require.

## 2026-09-05 — The coordination-plumbing rule does not reach an artifact whose subject is the binding (wordpress-importer-archive#884)

**Decision.** [`coordination-plumbing-stays-out-of-artifacts`](../.claude/rules/coordination-plumbing-stays-out-of-artifacts.md) does **not** reach a pull request or document whose subject is the committed workspace binding. PR wordpress-importer-archive#870 is clean as written and is not edited. The rule now states this, and states its boundary explicitly: **session labels and machine aliases remain forbidden regardless of subject.**

**Why.** The values in [`.claude/slack-workspace.json`](../.claude/slack-workspace.json) are project-owned configuration — committed, reviewable, and documented at length in [`CLAUDE.md`](../CLAUDE.md) — so naming them falls under the rule's existing first carve-out rather than needing a new exception. The audit that raised this measured every pull request merged into `main` in the 24 hours to 2026-09-05: footer and emoji zero across the corpus, and three plumbing hits of which two were ordinary English and git vocabulary. The third, wordpress-importer-archive#870, names no label and no alias, so the harms the rule leads with — a reader who cannot resolve a label, a line that identifies a person's machine — are both absent. A diff that may not describe its own subject cannot be reviewed at all.

**The boundary is the load-bearing half, and it is why this is recorded rather than left implied.** An exemption stated without one is the exemption that gets stretched: "a binding artifact may describe the binding" invites the reading that any artifact touching coordination may name anything. Labels and aliases carry the original harm and no subject makes either checkable to the artifact's audience, so they are named as forbidden in the same sentence that grants the permission.

**Rejected.** *Treat wordpress-importer-archive#870 as in scope and add an exemption* — the second reading available on the ticket. It reaches the same practical place by conceding that the carve-out list was exhaustive, which makes every future binding artifact a violation awaiting a waiver rather than ordinary work. *Leave it undecided* — the state the ticket was filed in, and the one with a real cost: the next author touching the binding either over-redacts a reviewable diff or guesses, and both were observed before this was settled. *Edit wordpress-importer-archive#870* — rewriting a merged record to satisfy a rule it does not violate.

**Companion.** wordpress-importer-archive#884, filed from the audit. The binding itself was converged under wordpress-importer-archive#868 (the entry above). Files: [`.claude/rules/coordination-plumbing-stays-out-of-artifacts.md`](../.claude/rules/coordination-plumbing-stays-out-of-artifacts.md).

## 2026-09-05 — No carve-out for `U+2713`; the prescription is removed instead (wordpress-importer-archive#885)

**Decision.** [`no-emoji-in-durable-records`](../.claude/rules/no-emoji-in-durable-records.md) takes **no exception** for the check mark. [`writing-pull-requests`](../.claude/skills/writing-pull-requests/SKILL.md) no longer tells a reader that a `## Verification` item "may end with a literal `U+2713` check mark" — the clause is deleted rather than exempted. Scoped to that one character: `U+26A0` and `U+FE0F` are **left as they are**, pending a separate decision on whether this narrows their exemption, and this is not a corpus sweep — that is [`UAMS-Web/uams-statamic-archive#2185`](https://github.com/UAMS-Web/uams-statamic-archive/issues/2185).

**Why.** The skill contradicted **itself**, which is a stronger reason than contradicting the rule. Line 284 already carries `- **No emoji** — … Use words.` with a link to the rule, so a reader reaching line 146 was handed a prescription the same document bans 138 lines later. Neither instruction was marked as the exception, so the file gave no way to tell which one held, and the ban is the one with a measured failure behind it — the `cp1252` traceback the rule leads with. Deleting the prescription is also the smaller edit: the clause was one sentence, and the surrounding bullet reads correctly without it.

**The prescribe/use distinction is what bounds the change.** Line 146 was the only place the skill *instructed* a reader to emit a glyph. The `U+26A0` at line 199 is the skill *using* a marker inside an example blockquote — grandfathered prose under the rule's own "existing prose is not yours to sweep", and untouched here.

**`scripts/check-catalog.mjs` — classified in three parts, and only one of them is in this decision's reach.** The ticket asked for this explicitly, because treating the file as one thing gets the wrong answer twice:

| Part | Lines | Disposition |
| --- | --- | --- |
| **Data-matching** against the catalog's Status column (`U+26D4`, `U+2705`) | 198, 256, 259, 265, 268, 269, 309 | **Must not change.** These parse a file this repo does not own the vocabulary of. Breaking them fails **silently**: every row reads unblocked and every group unshipped, with no error. |
| **Docblock quotation** of a catalog row, as the worked example of the defect the script exists to catch | 10, 96 | **Unchanged** — the rule's verbatim-quotation carve-out. |
| **Emitted output** — writes `U+2713`/`U+2717` to stdout as its own pass/fail marker | 378 | **The only line the decision's subject reaches, and left unchanged.** It is not named in the authorization, and the standing instruction where an item does not name a surface is to ask rather than read the grant broadly. Classified, not edited. |

**Rejected.** *Carve `U+2713` out of the rule* — the ticket's other reading. It would have to state a boundary and address the `cp1252` failure, and neither is answerable in the check mark's favour: the character is in `cp1252` and survives, so the carve-out would rest on "this one happens not to break", which is a property of the character rather than a principle, and it is exactly the shape of exemption that gets extended to the next glyph by analogy. *Sweep the corpus* — 174 grandfathered occurrences, explicitly out of scope and tracked in the sibling repo. *Change `check-catalog.mjs:378`* — see above.

**Companion.** wordpress-importer-archive#885. Files: [`.claude/skills/writing-pull-requests/SKILL.md`](../.claude/skills/writing-pull-requests/SKILL.md).

## 2026-09-05 — Check the prose corpus with a pinned `cspell`, and take the JavaScript surface that requires (wordpress-importer-archive#922)

**Decision.** Adopt `cspell` as a **pinned devDependency** and add a `spell` job to both CI mirrors, scoped to the prose corpus — `.claude/**/*.md`, `docs/**/*.md`, `README.md`. This gives the package its first JavaScript dependency surface: a `package.json`, a lockfile, and 108 transitive packages, none of which the consuming app uses.

**Why.** The rules and skills corpus is 7,841 lines across 55 files, plus 3,590 lines of `docs/`, and nothing checked any of it — the gate is three PHP jobs. The defect that surfaced this merged here and was caught only because a sibling repository, which does check, received the same text.

**The figure that decided it: 177 of the last 200 commits touch `.claude/**/*.md`.** The prose is not a static corpus needing occasional proofing; it is the most frequently edited surface in the repository, changing on 88% of recent commits. That is what makes a recurring dictionary cost worth paying here where it would not be elsewhere.

**Scoped to prose, not the whole tree, and the numbers are why.** The full tree flags **1,510** unknown words; the prose corpus flags **273**. A single Gravity Forms mapping file contributes 2,290 issues on its own, all WordPress field-type names and none of them prose defects. Checking `src/` would spend the dictionary budget on identifiers while adding no prose coverage.

**`--dot` is load-bearing.** Without it the glob skips dot-directories, so `.claude/` — the entire reason for the job — is never read, and the run reports a clean pass over the files it did see.

**No defect exists to fix.** The motivating word was already reworded before this landed, verified with a control confirming the search could see a word that was present. Every one of the 273 is project vocabulary, Latin fixture text, a British spelling, or a word-splitting artifact. This is prevention: nothing currently red turns green.

**Three classes of exception, kept apart deliberately.** Project vocabulary and British spellings go in `project-words.txt`. Opaque identifiers — GitHub node ids — go in `ignoreRegExpList`, because listing their fragments as words would silence those letter sequences everywhere. Word-splitting artifacts are **scoped per file** in `overrides`: one such artifact is the truncated half of this repository's own split-string idiom — the device that stops a `pgrep` pattern matching the command issuing it — and allow-listing that fragment globally would silence a genuine typo anywhere in the tree. It is described here rather than reproduced, for the same reason `supported-platforms` describes machine paths instead of quoting one.

**Rejected.** *`npx cspell@<version>` with nothing committed* — answers the dependency-surface objection directly, but needs a network fetch at gate time and lets transitive dependencies float; a non-deterministic gate is the wrong trade in a repository whose recurring failure is a green run that checked nothing. *Declining* — leaves the corpus unchecked, with defects surfacing only when a human reads them or a sibling repository happens to receive the same text. *Checking the whole tree* — 1,510 dictionary entries, almost all identifiers, for no prose coverage.

**Companion.** wordpress-importer-archive#922. Files: `package.json`, `package-lock.json`, `cspell.json`, `project-words.txt`, [`.github/workflows/ci.yml`](../.github/workflows/ci.yml), [`scripts/local-ci.mjs`](../scripts/local-ci.mjs), and its test, whose pinned job list this changes. `node_modules/` is added to `.gitignore`. **Consequence for every tree:** `spell` is in `ALL_JOBS`, so a `local-ci` run now needs `npm install` in that worktree, and the runner names that remedy rather than failing inside `npx`.

## The vanity-redirect network is served over the migration API, not hand-exported

One WordPress network carries the vanity-URL redirects. It holds no posts or pages, so it produces no WXR export and was absent from the network inventory recorded on `#702`. Its redirect data is served over the **migration API**, which puts that network in scope for activation, secret placement and the close alongside every other.

**The redirect set is expected to keep growing during the migration, and that is what decided it.** A hand WXR export is a point-in-time snapshot of a set that keeps changing: stale on arrival, repeated by hand for every vanity URL added afterwards, and silently incomplete when one is missed. Serving the network costs three production acts once.

**The destination and the importer were already built and are not part of this decision.** `wordpress-importer-archive#133` records that the vanity domains keep the `redirects` global set as their authoring surface — *"editors and the importer unchanged"* — with a deploy-time step generating edge configuration from it. This package already imports `redirect_rule` into that global set through `RedirectRuleGlobalCreator`, covered by `tests/Feature/RedirectRuleImportTest.php`, a WXR fixture and unit tests.

**The two options were transports for one artifact rather than two formats, which is what made the comparison tractable.** [`WxrMaterializer`](../src/Services/WordPressImport/MigrationApi/WxrMaterializer.php) writes migration API content out as WXR part files deliberately, so the parser, the six entry creators and the sanitizer all work unchanged. Downstream is identical either way and **neither option required importer work** — the choice was purely about acquisition.

- **Rejected:** hand-exporting WXR from the redirect network. Zero new work, uses the existing tested path, and keeps a redirect-only network out of the deployment entirely — including the close, which the runbook singles out as the act that gets forgotten. Rejected because the set keeps changing, so the export is stale on arrival and every later addition needs another manual pass whose omission fails silently.

**Two consequences, both recorded as unchecked items on `#702` rather than left as prose.** `redirect_rule` must be **`exposed`** on that deployment — `/post-types` carries an `exposed` flag per type and only 15 of 36 were exposed on the install measured, so it is a real check and a requirement against `uamswp-migration-api` if it fails. **An earlier revision of this entry added a second consequence and it was already false when written.** It said `uams-statamic-archive#1933` should land first, because an SRM wildcard rule imports as `use_regex: false` with a literal `*` and could never match. `#1933` had closed a month earlier, the matcher now reads that shape correctly, and there is no sequencing constraint. It was repeated from the entry above rather than re-derived — see *`FindRedirectRule` parity* — which is the failure `#988` exists to record as much as the staleness itself.

Recorded for `#702`; extends `wordpress-importer-archive#133`.

## The migration API credential is a per-network token the plugin issues, not a shared constant

The importer authenticates to `uamswp-migration-api` with a token generated per network from the plugin's own admin page, shown once and stored hashed there. It replaces the `UAMSWP_MIGRATION_API_SECRET` constant as the credential this migration uses. Decided on `UAMS-Web/uamswp-migration-api-archive#140`, which owns the comparison and the rejected alternatives; the build is that repo's `#141`.

**Nothing in this package changes at the call site.** The token is presented in the existing `X-UAMSWP-Migration-Key` header, so [`MigrationApi\Client`](../src/Services/WordPressImport/MigrationApi/Client.php) sends what it already sends — `SECRET_HEADER` at line 34, applied once at line 331. What changes is where the value comes from and how many of them there are.

**The consequence that does reach this package is where the credential lives, and `wordpress-importer-archive#982` has since decided it: nowhere.** `Client::fromConfig()` resolved one `base_url` and one `secret` from host configuration, and the estate is 26 networks. This entry originally read that a per-network credential "strengthens the case for the config-map option over reconfiguring between runs" — it argued between the two options `wordpress-importer-archive#982` was filed with, and the option that was chosen is neither. See *The migration API address and key are supplied per run and never stored* below.

**Why a plugin-issued token rather than the two credentials `Auth.php` already accepts.** The shared constant is broad and shallow: one value across all 26 networks, plaintext in `wp-config.php`, no per-consumer revocation, and rotation as 2N synchronized edits with no grace period. An application password is narrow and deep: `Auth.php` requires `manage_network_options`, so it means a super-admin account per network — write-capable network administration to perform a read-only migration. The token is the only option that is narrow in breadth *and* shallow in depth: this API's read surface, on one network, carrying no WordPress identity. It also needs no capability decision and its setup procedure is a page rather than a document nobody has written.

**Cookie authentication was considered and cannot serve this package.** A logged-in super admin can already reach the API in a browser, and core requires a nonce for that. The importer is a server-side client with no session, so bridging it would mean storing a super admin's real account password or scraping a nonce that expires within a day. Cookie authentication is right for the *human issuing* the token and unavailable to the *machine using* it.

- **Rejected:** the shared constant. See above; it is the credential with the documented procedure and the weakest properties.
- **Rejected:** the application password as `Auth.php` currently gates it, and at a lowered capability through its existing filter. The second is a sound design and was not ready — the capability was unchosen and no setup procedure exists — and the token reaches the same security position without either question.

**Consequence for `wordpress-importer-archive#987`, closed `not_planned`.** That ticket made `wp-config.php` placement safer through WP-CLI and environment indirection. The chosen design places no constant, so its subject does not arise. `Auth.php` keeps the constant path, so its contents stay correct if that path is ever used.

Recorded for `#702`; supersedes nothing in this file.

## The migration API address and key are supplied per run and never stored

An operator starting an import from the migration API pastes two things when the run begins: the WordPress address of the network to read, and that network's API key, taken from the plugin's admin page. Neither is held in configuration, in an environment variable, or in a command-line option, and neither survives the process. Decided on `wordpress-importer-archive#982` by the operator.

**Why nothing is stored.** The estate is 26 separate multisite networks and the credential is per network — the decision above. Configuration able to address them all would therefore hold 26 live credentials on disk for a job that reads one network at a time, each of them a key to that network's whole content surface. Passing the key as a command-line option would be worse than storing it: it lands in shell history and in the process list, readable by any other account on the machine, and outlives the run in both.

**Why the address travels with the key rather than staying in configuration.** They are only meaningful together. The plugin stores its key per network with `update_network_option`, so one network's key authenticates against no other. Split across two places they can drift apart, and asking for both in a single act is what stops that.

**What that does to the failure `wordpress-importer-archive#982` was filed about.** The ticket argued for a config map on the ground that a run against the wrong `base_url` looks normal. That was true of a shared secret. Under a per-network key the errors are asymmetric: an address that resolves nowhere is refused, and one reaching a network the pasted key does not open returns `401` — both fail closed. What stays silent is an address the operator did not mean whose key they then paste, and supplying the pair in one act is the narrowest form that failure can take.

**One guard is new and belongs to this decision rather than to the credential one.** `Client::forRun()` refuses an address that is not absolute `https://`. Nothing validated the scheme before, which was defensible while it was reviewed once in a config file and is not once it is typed at a prompt on every run. It matters more than it looks: `withBaseUrl()` pins every later hop to the scheme the run began on, so a run started on `http://` would have that guard enforcing the credential's exposure rather than preventing it.

- **Rejected:** a per-network config map, keyed by network, holding an address and a key for each. It is the only option that makes a cross-network run in one pass possible, and nothing needs that. Its cost is 26 stored credentials plus a coordinated change in `uams-statamic`, which owns the config key, followed by a release here and a pin bump.
- **Rejected:** reconfiguring between runs — one `base_url` and one key in the host's environment, edited per network. No code change, and it still stores a live credential, still needs a hand edit whose omission is the failure the ticket was filed about, and gives the operator nothing that confirms which network a run is pointed at.

**Consequence for the host application.** `wordpress-import.migration_api.base_url` and `.secret` are now read by nothing. They are left in place rather than removed here, because that file belongs to `uams-statamic`; removing them is that repository's change, filed as `UAMS-Web/uams-statamic-archive#2358`. `per_page` is still read and still belongs in configuration — it is neither a credential nor a network identity.

**This supersedes an earlier decision on the same ticket.** `wordpress-importer-archive#982` recorded option A — keep the two-scalar shape and edit the address and secret between runs — decided 2026-09-08. The decision above was made 2026-09-09 by the same operator, with A in front of them, and goes further in one respect only: it removes the stored credential as well as the config map both decisions reject. The superseded section is kept on the ticket rather than deleted.

Recorded for `wordpress-importer-archive#982`; supersedes that ticket's option-A decision, and amends *The migration API credential is a per-network token the plugin issues, not a shared constant* above, which argued between the two options neither decision chose.

## 2026-09-10 — Put the two `CLAUDE.md` files and the `.github` templates in the checked prose corpus (wordpress-importer-archive#980)

**Decision.** Widen the `spell` job's globs to `.claude/**/*.md`, `docs/**/*.md`, `README.md`, `CLAUDE.md`, `tests/CLAUDE.md` and `.github/**/*.md`. `src/` stays out. Both CI mirrors change in the same commit, because the pre-commit hook gates them as a co-change pair.

**Why the original scope missed them.** wordpress-importer-archive#922 excluded `src/` deliberately and that reasoning holds — checking it spends the dictionary budget on WordPress field names for no prose coverage. It never reached `CLAUDE.md`, because the globs name **directories** and a root-level file was not considered. The result was that the corpus built to protect this repository's prose excluded its two most-read documents: both `CLAUDE.md` files auto-load into every session, a property the root one states about itself.

**Measured before and after, with the pinned `cspell` 10.0.1 rather than an `npx` resolve.** The version matters — the ticket's figures came from 10.2.2 via `npx` while the repository pins 10.0.1, so they were re-derived here:

```
tracked .md files      77
covered before         64     Files checked: 64
covered after          71     Files checked: 71, Issues found: 0
still uncovered         6     .cursor/**/*.md (5), src/**/README.md (1)
```

64 + 1 + 1 + 5 = 71, so the differential accounts for every file added and nothing was matched twice.

**Seven previously-unchecked words, resolved into wordpress-importer-archive#922's three classes rather than swept into one.**

- **Project vocabulary → `project-words.txt`** (six): `autoloaded`, `bootable`, `HKCU`, `paratest`, `renderable`, `shopt`. None overrides the American-English convention, so none needs the reason comment [`american-english-prose`](../.claude/rules/american-english-prose.md) requires.
- **Opaque identifier → `ignoreRegExpList`** (one): a four-letter run inside this repository's Slack **channel** identifier, which is not a word at all. The existing pattern `[BT]0[A-Z0-9]{8,}` already covered Slack **bot** and **team** identifiers and missed **channel** ones, so the fragment fell through to the dictionary. Widening it to `[BCT]0` puts it in the class wordpress-importer-archive#922 built for it — listing the fragment as a word would silence that letter sequence everywhere in the tree.

  It is described here rather than reproduced, exactly as wordpress-importer-archive#922 described its own word-splitting artifact instead of quoting one. Writing it out is not harmless: the first draft of this entry did, and the `spell` job rejected the paragraph arguing the fragment must never become a dictionary word.

  Verified rather than reasoned: a fixture carrying a channel, a team and a bot identifier flags the fragment under the current pattern and reports zero issues under the widened one, with the team and bot identifiers silent throughout.

**Rejected.** *`.cursor/**/*.md`* — tracked prose, and it would cost ten dictionary entries for another editor's command surface that this project does not maintain. It is excluded on cost and ownership, not oversight, and is recorded here so a later sweep does not read it as an omission. *`src/.../MinervaKbSettings/README.md`* — inside `src/`, which wordpress-importer-archive#922 excluded deliberately; it currently has zero unknown words, so admitting it would weaken a deliberate boundary for no gain.

**The `.github` templates cost nothing today** — all five report zero unknown words — and they are prose a human reads every time an issue or pull request is opened, which is the same argument that admits the `CLAUDE.md` files.

Recorded for #980; amends *Check the prose corpus with a pinned `cspell`, and take the JavaScript surface that requires (wordpress-importer-archive#922)* above, which set the narrower scope.

## A cross-site news category resolves against the syndicating site, once the host field opts in

`uamsweb_block_news`'s `category` field now sets `select_across_sites: true` (`uams-statamic-archive#2165`), so `PostCategoryEntryResolver` tries the placement's own site first and the `news` site second, and writes whichever id it finds. Before that flag, resolving cross-site was strictly worse than refusing: the host dropped the id on read and the block rendered completely unfiltered while its YAML looked filtered.

**The observation `#628` named as the one that could sink this is closed by mechanism rather than by precedent.** That residual asked whether `select_across_sites` actually returns other sites' entries when the collection sets `propagate: false`, as `post_categories` does. Read from `vendor/statamic/cms/src/Fieldtypes/Entries.php`: `$shouldLocalize = ! $this->canSelectAcrossSites()` gates a single `->when($shouldLocalize, ...)` closure, and the `->in($site)` call inside it is **the only such call in the 597-line file**. With the flag set the closure never runs, so localization is *bypassed* rather than answered — `propagate` does not enter the path at all. `augment()` and `shallowAugment()` both read through the same `queryBuilder()`.

That is corroborated independently by the host's own fieldset, whose comment beside the flag records the behavior measured both ways on `#2165`: kept with the flag, silently lost without it.

**The placement's site is preferred, not merely tried first.** A slug present on both sites means the local entry; resolving the syndicate instead would change which entry an already-correct import produces, which is a regression wearing a feature's clothes. A test pins the order, and inverting the two lines fails eleven tests rather than one.

**`news` is a Statamic site handle here and a checkbox option token in `NewsBlockSourceSites`.** They are spelled the same and are different kinds of thing; the site handle was verified against the host's `resources/sites.yaml` rather than inferred from the token.

- **Rejected: resolving against any site that carries the slug.** Worse than not resolving. The block queries `news` and optionally the placement's site, so a category belonging to a third site filters against posts that cannot appear — rendering empty rather than unfiltered, which is a harder failure to diagnose than the one this replaces.
- **Rejected: making the syndicating site configurable.** No caller has a second answer to give. `source_sites` always carries `news`; it is the fieldset default and the host falls back to it when the selection is empty.

Recorded for #628; amends *Resolve a news block's category on the first import, and group the corpus by `base_blog_url` (wordpress-importer-archive#624)* above, whose sentence stating that the resolver "scopes to the placement's own site because `post_categories` is `propagate: false`" was true when written and is superseded here. Its 28/13 corpus figures were themselves superseded by `wordpress-importer-archive#628`'s re-derivation — 104 unresolvable of 429 references across all 259 exports — and that query is recorded on the ticket rather than copied here, so it cannot go stale in two places.

## The migration API key may arrive on standard input, so an unattended run is possible at all

`statamic:import:wordpress --source=api` asked for the network address and its key at a prompt and refused without a terminal. That refusal was correct about the credential and wrong about the consequence: it made every unattended API run impossible, including the dual-import comparison `wordpress-importer-archive#588` asks for, which is the one check that would show the API and a WXR export producing the same content. Three ways of faking a terminal were measured on macOS and all fail — `--no-interaction` is refused by design, answers piped to the prompt raise `NonInteractiveValidationException` because Prompts detects a non-TTY, and `script -q /dev/null` cannot allocate a pty against a socket.

**The key now arrives on the first line of standard input when the operator passes `--key-from-stdin`, with the address as an ordinary `--network=` option.** The original reasoning is preserved rather than traded away: the docblock named shell history and the process list as the two exposures a stored or passed key would create, and a stream is neither. It is consumed once, by the running process, and leaves nothing behind.

**The address is an option and the key is not, because only one of them is a secret.** An `https://` URL in a process list discloses which network is being read, which the run prints on screen anyway. The two are still supplied in one act, so they cannot drift apart between runs — the failure `wordpress-importer-archive#982` was filed about.

**A bound recorded with the decision, because the guarantee is narrower than the option's name suggests.** Standard input is only as private as whatever fills it: a key piped from a file still lives in that file, and a heredoc typed at an interactive shell still reaches that shell's history. The route removes the exposure it can remove and the operator owns the rest. Anyone extending this should not read "stdin" as "safe" without asking what is on the other end of the pipe.

- **Rejected: leaving it manual.** An operator runs the dual import at a terminal and pastes the result. It costs nothing to build and the criterion can then never be re-checked automatically, so it rots silently the first time either side changes — which is the failure mode this repository has paid for repeatedly.
- **Rejected: an environment variable.** The smallest change, and weaker than the chosen route on exactly the property the refusal existed to protect: an environment is inherited by every child process and readable through `/proc` on Linux.
- **Rejected: a verification-only harness that materializes from the API and imports the file.** Keeps the credential safe and skips the interactive path entirely, but does not exercise the real command, so it verifies less than the criterion asks for.

Recorded for #1046; extends *The migration API address and key are supplied per run and never stored* above, which settled that neither value is configured and is unchanged by this — the key is still supplied per run and still stored nowhere, and this adds a second way of supplying it.

## A lane may draft the site to `organizations` crosswalk, but no site is designated on an unconfirmed row

Authoring the map and shipping it are separable acts, and the whole risk lives in the second. A site designated onto the wrong organization opts it into a hub for that organization and renders as if it were correct, so nothing surfaces the error — the coupling `uams_multisite_settings` declares between `department_organization` and the news surface is what makes a wrong row expensive. Producing candidate rows carries none of that risk.

So a lane produces the crosswalk as a **reviewable artifact** rather than a shipped table: each row carries the evidence that produced it — an exact organization-title match, a fuzzy score, or nothing — ordered so the weakest matches are read first. A human confirms, corrects and extends it; only then does a lane write the confirmed rows into the map. The human's job becomes checking rows rather than producing 246 of them, and the guarantee that no site is designated unchecked is unchanged.

**The mechanism this feeds was already complete when the decision was taken.** Read from `origin/main`: `DepartmentOrganizationMap`, `DepartmentOrganizationDesignator`, `DepartmentOrganizationDesignation` and `DepartmentOrganizationOutcome` under `src/Services/WordPressImport/Globals/`, with Integration and Unit coverage. Both tables ship empty — `DESIGNATIONS = []` and `DELIBERATELY_UNMAPPED = []` at `DepartmentOrganizationMap.php:68` and `:79` — and `tests/Unit/DepartmentOrganizationMapTest.php:77` pins that empty state deliberately, so authoring the map must update an assertion rather than slip in unnoticed. Six of `wordpress-importer-archive#768`'s eight acceptance criteria were already met; the two outstanding are this one piece of work.

- **Rejected: a lane drafts and ships in one step.** The failure mode is a silent wrong designation with no error surface, and it is poorly reversible because the result looks correct.
- **Rejected: a human authors it outright.** Matches `#448`, which classifies the analogous `category` crosswalk as `hitl` / `action-flavor`, and is the safest option — but it assigns the mechanical 246-row sweep to the person whose scarce contribution is the judgement, and it had already left `#768` at six-of-eight with `uams-statamic#197` blocked behind it.
- **Rejected: close `#768` on the mechanism half and re-home the authoring to its own `action-flavor` ticket** beside `#448`, mirroring the `#438` / `#448` split. Structurally tidy, and it moves rather than removes the blocker on `uams-statamic#197`.

**What this does not authorize.** The draft is not the designation. Nothing is written to `uams_multisite_settings` until confirmed rows land in the map, and this decision says nothing about who runs that final step.

Recorded for wordpress-importer-archive#768.

## The decision-log union driver applies to the checkout performing the merge, and not to GitHub

`.gitattributes` carries `docs/DECISIONS.md merge=union` so two branches appending entries resolve by keeping both sides instead of conflicting. **Where that actually takes effect is not where it reads as taking effect**, and the difference decides who meets a conflict.

Git reads `.gitattributes` from the **tree of the checkout performing the merge** — not from either side's history. Measured on **macOS (Darwin 25.6.0), git 2.39.5 (Apple Git-154)**, in scratch repositories with the same two appends, differing only in which tree held the attribute:

| Merge performed from | Attribute in that tree | Result |
| --- | --- | --- |
| `main`, with no attribute anywhere | absent | `rc=1`, one conflict marker |
| `main`, attribute present | present | `rc=0`, both entries kept |
| a branch whose base predates the attribute, merging `main` in | **absent** | `rc=1`, one conflict marker |

The first row is the control: without it, the third row's conflict could be read as the driver failing rather than as the driver never being consulted.

**A second control establishes that row 2's clean result is the driver and not an ordinary auto-merge.** `git merge-file` on the three blobs, with no attribute involved anywhere, returns `rc=1` with a conflict marker — so a plain three-way merge of this content genuinely conflicts, and a clean merge implicates the driver rather than git having resolved it unaided.

### This is bounded to one platform and one git, and a sibling contradicts it

**Not established beyond macOS on git 2.39.5.** Two sibling repositories measured the same directions first-hand and did not agree with each other:

| Repository | Platform, git | Sync direction |
| --- | --- | --- |
| this one | macOS, 2.39.5 | `rc=1`, conflict — driver did **not** apply |
| `uamswp-migration-api` | macOS, 2.54.0 | `rc=1`, conflict — agrees |
| `uams-statamic` | Windows, 2.32.0 | clean — **contradicts** |

**The merge strategy is NOT the discriminator, and that is settled rather than suspected.** The obvious hypothesis was `merge-ort`, which became the default in 2.34 and which 2.32 predates. It was tested directly: forcing both backends on one machine, the same two commits, `-s ort` and `-s recursive` gave the **same** result in both directions — `rc=1` syncing, `rc=0` on the gate direction. So the strategy does not decide it.

**The operating system is excluded, and the axis is the git binary.** Two git binaries on **one** Windows machine, one filesystem and the same fixture, a fresh repository per cell, gave opposite results for the cell where only the incoming side carries the attribute:

```
git 2.32.0.windows.2    rc=0, 0 markers   unions
git 2.53.0.windows.4    rc=1, 1 marker    conflicts
```

The same machine flipped with the binary, so the platform is not what decides it. The measurement is on `UAMS-Web/uams-statamic-archive#2471`, which carries both binary strings, and it was **not re-run here**, because no Windows machine is available to this checkout. `uamswp-migration-api`'s decision log, amended in `c88a41e70`, puts the same cell across four binaries on two platforms:

| git binary | platform | same cell |
| --- | --- | --- |
| `2.32.0.windows.2` | Windows | unions |
| `2.53.0.windows.4` | Windows | conflicts |
| `2.39.5` (Apple) | macOS | conflicts — this repository's own arm, above |
| `2.54.0` (Apple) | macOS | conflicts |

**The outlier is a single binary rather than a platform.** Two Windows results alone could have been explained by something about that machine, and this table cannot be.

**Name the binary, not the version.** The version is the likeliest thing about the binary that decides this, but it is not demonstrated. Nothing here rules out a build or packaging difference that happens to line up with the version. If it is the version, the change lands somewhere between 2.32 and 2.39.

One further confound is disclosed rather than buried: the contradicting arm's scratch repositories logged `LF will be replaced by CRLF`, so that checkout's line-ending configuration was active. Its control still produced a real conflict, which shows the merge machinery was discriminating rather than merging every line together — but a line-ending interaction is not excluded by that alone.

What stays open is which property of the binary decides it, and whether line endings play a part. The platform question is closed.

So the table above is what this repository's own git does. **Do not port it to another git binary as established**, and do not reconcile the contradiction by picking the reading that agrees with you — the `git merge-file` control is what a further arm needs to carry, so a clean result can be told apart from content that never conflicted.

**So the rule is about the checkout, not about either party: the driver applies to whichever checkout performs the merge, and only when that checkout's tree carries the attribute.**

**No landing has it.** This repository is squash-only — `allow_merge_commit` and `allow_rebase_merge` are both false — so a pull request reaches `main` as a single-parent commit authored by the forge, which does not honour `merge=union`. Verified on `090f41c44`, the landing immediately before this entry: one parent, committer `GitHub`. The driver can therefore only ever apply to a **local** merge someone performs *before* landing.

There are two such merges, and they are the same row of the table above rather than different ones:

- **An author syncing**, per [`sync-pr-branch`](../.claude/rules/sync-pr-branch.md), runs `git merge origin/main` from the branch's checkout. A branch cut before the attribute landed does not have it and meets an ordinary conflict — the expected outcome, resolved by keeping both entries.
- **A gate operator's trial merge**, per [`pre-merge-check`](../.claude/rules/pre-merge-check.md) step 4, is performed from whichever checkout the operator is in; on a branch predating the attribute that is the same exposed case. The step does not specify a direction, which is worth knowing rather than assuming.

**An earlier draft of this entry said "the gate merges from `main` and therefore always has it."** That is withdrawn: nothing performs a merge in that direction. `scripts/local-ci.mjs` contains `git merge` **0 times** — control: `branch` appears 87 times — and uses `merge-base --is-ancestor` instead. The sentence erred toward reassurance, in the one document whose purpose is to say where the driver does and does not apply, and it would have told a reader the gate was the safe path and the author the exposed one.

**The attribute landed in `6c1ef0e` on 2026-09-14** (wordpress-importer-archive#1070). Most remote branches predate it, and that fact carries nothing: the overwhelming majority are merged heads kept deliberately (wordpress-importer-archive#1092) and can never be merged again. **The set that matters is the branches carrying an open pull request**, and at the time of writing none of them predated it, so nothing live was exposed.

No branch total is quoted here on purpose. An earlier draft gave one, and it was wrong in two ways at once — it counted `main` among the non-`main` branches, and it had already drifted by the time it was checked, because pushing the branch that carries this entry changes it. A figure that a document's own publication invalidates should not be in the document.

**The check that does not age:** `git merge-base <branch> 6c1ef0e` — a result equal to `6c1ef0e` means that branch's tree carries the attribute.

**GitHub's own merge does not honour `merge=union` at all.** A pull request merged through the forge resolves without it, so a decision-log collision there surfaces as `mergeable_state: dirty` and is resolved by syncing locally. That was measured in `uams-statamic` rather than here, and is recorded as their finding rather than re-derived.

- **Rejected: stating the rule as "a branch whose base predates the attribute never gets the driver."** True for the sync direction and false for the gate's, and the two directions are what the reader has to tell apart. Stated unconditionally it predicts a conflict where merging on `main` produces none.
- **Rejected: back-porting the attribute so every live branch has it.** It cannot be back-ported — the attribute is read from the merging tree at merge time, so the only thing that changes an older branch's behavior is syncing it, which is what the existing rule already requires.

Recorded for wordpress-importer-archive#1091.

## A form plugin's data store is per instance, and the container shares one manager per plugin

`BaseDataManager` held its loaded forms in a `static` property that no subclass redeclared, so every `DataManager` of every plugin in a process read and wrote one slot. Two constructed managers were one store; a test comparing them compared a value with itself; and a second plugin's load overwrote the first's. wordpress-importer-archive#1103 moves the store to an instance property.

**The static was load-bearing in a way the ticket did not name, and the decision turns on it.** Every consumer of a plugin's manager — the coordinator, the user-interaction handler, the form-creation coordinator — takes it by constructor injection, and nothing bound it, so the container resolved a fresh instance for each. The handler wrote into one instance and the coordinator read from another, and the only reason that worked was the static underneath both. Making the store per instance therefore has to bind each plugin's `DataManager` as a container singleton, or the live flow breaks with no error: the coordinator reads an empty manager and reports that the plugin's data is not loaded.

So the shape is the one `MinervaKbSettingsLoader` (wordpress-importer-archive#586) and the form Loaders (wordpress-importer-archive#809, wordpress-importer-archive#810) already have: **one instance per plugin, declared in `ServiceProvider`, reset between imports in one process by `FormPluginCoordinator::clearAllCaches()`**, which clears each coordinator's own manager. The sharing is now a binding anyone can read rather than a property of the class, it is scoped to one plugin instead of all of them, and a test that constructs two managers gets two stores.

**Rejected: keep the store process-wide and document it.** It leaves both defects in place — the false green and the cross-plugin overwrite — and documents them as intended. The ticket's own reason for considering it, that a long-running import might depend on process-wide sharing, turned out to be the injection seam above, which the binding covers without the sharing reaching across plugins.

**Rejected: per-instance with no binding.** Fixes the test and breaks production, silently, on the first import that detects a form plugin.

The dead half of the old singleton — `$instance`, `setInstance()`, `clearInstance()` — is removed rather than kept. `setInstance()` had no caller anywhere and `$instance` was never read; `clearInstance()` was called only to clear the static store, which no longer exists. `clearAllCaches()` already performed the instance-level clears three lines above those calls.

**The test that guards this writes different values into different managers.** Equal values cannot expose a shared store, so the equivalence arm in `NinjaFormsSourcingTest` — which compares two managers holding the same form — is not the guard, and its comment says so. `tests/Unit/BaseDataManagerTest.php` is: against the static store, four of its four cases fail, and the container-binding test's per-plugin case fails with them.

## Merged head branches are kept on the remote, because pull-request bodies link into them

`delete_branch_on_merge` stays **off**, merged head branches are not deleted, and a merge is taken without `--delete-branch`. The numeric-prefixed branches on `origin` — several hundred, and growing by one per merged ticket — are a deliberate steady state rather than accumulated drift.

**The reason is a convention two directories away, which is why it needs recording here.** [`writing-pull-requests`](../.claude/skills/writing-pull-requests/SKILL.md) requires every file link in a pull-request body to be an absolute **head-branch** URL — `.../blob/<head-branch>/path`, explicitly *"even for files already on `main`"*. Deleting that branch therefore breaks every such link in that body, permanently and all at once. Nothing else in the repository states this, so a reader meeting 249 branches finds the cost and not the reason.

**The dependency is real and it is not uniform** — some merged pull-request bodies carry head-branch blob links and others carry none, so a sweep would break some and leave others intact, which is harder to notice than an all-or-nothing failure would be.

**No ratio is given here, deliberately.** A count over *the most recent N* pull requests re-evaluates every time it is read, and a decision log is read months after it is written: the figure drafted for this entry moved from 9-of-25 to 5-of-25 within an hour, on eleven merges, with the underlying dependency unchanged. That is self-invalidating by construction rather than merely stale, and the precedent in this file is to remove such a number and say why rather than to refresh it. The fixed-in-time evidence is below.

**It has already happened here, three links across two pull requests.** `wordpress-importer-archive#970` carries 2 blob links into its own head branch and `wordpress-importer-archive#974` carries 1 into its own; neither branch is on `origin` any more. Measured two ways, because one of them is not sufficient on its own — the dead branch is `wordpress-importer-archive#970`'s head, and the control is a head branch still present:

| Instrument | `wordpress-importer-archive#970`'s head branch | a live head branch (control) |
| --- | --- | --- |
| `contents/README.md?ref=…`, authenticated | `404` | `200` |
| `git ls-remote --heads origin` | 0 refs | 1 ref |

**The control is what makes the 404 evidence rather than noise, and the sibling repository's own version of this entry records why.** `UAMS-Web/uams-statamic`'s `#2177` cited a 404 as proof a branch was gone; the branch existed, and the 404 came from an **unauthenticated** request to a private repository, which returns 404 for every path. This repository is private too — `private: true` — so the same trap was available here. The live arm rules it out, and `ls-remote` answers without the contents API at all.

**Rejected: enable `delete_branch_on_merge` for a tidier remote.** The branch count is the visible cost and the file links are the invisible one, which is the wrong way round. A count is cosmetic and recoverable; the links cannot be repaired without rewriting the bodies of every affected pull request.

**Rejected: keep the convention but write `blob/main/` links instead.** It would permit deletion and break the case the convention exists for — a file *added* by the pull request does not exist on `main` when the body is written, so the link is dead at review time, which is when it is read most.

**This records an existing state and changes nothing.** No branch is deleted, no setting is flipped, and the skill's requirement is untouched. The local half is [`worktrees`](../.claude/rules/worktrees.md)'s *"Never delete the branch"* — a released slot moves off its branch without deleting it — which covers the working copy and does not reach merged heads on the remote; this entry is the remote half. The sibling filings are `UAMS-Web/uamswp-migration-api-archive#265` and `uams-statamic`'s own recorded decision.

## A MinervaKB settings row is read because it was served, not because a plugin entry vouches for it (wordpress-importer-archive#1125)

Where the migration API serves exactly one MinervaKB settings row and `active_plugins` names the *other* generation's entry file, the importer reads the served row. That is the pre-existing behavior, kept deliberately after wordpress-importer-archive#1124 left the case open, and wordpress-importer-archive#1125 closed as no-change.

**`active_plugins` records what was activated, never whether the file survives.** On `hr/root` and `hr/benefits` it names the v2 entry file while `uamswp-minerva-kb-migrations` was never written there, so the generation it names has provably never run. Those two sites import correctly at 27 of 27 loader keys against a same-install export (#666). `inside/tri` is the same unreliability reversed: it names v1, while the v2 migrations record is present and v1's row was never written at all.

**The three sites are one case rather than two opposed ones.** Each serves a row belonging to the generation `active_plugins` does not name, and a single fact — that the entry is stale — makes the served row the live one on all three. Reading it is therefore correct everywhere, and refusing would be wrong everywhere.

**Absence became admissible for one key, which is what settled it.** The standing constraint is that `/options` reports how many rows it withheld and never which, so a missing row is not evidence. That holds only while a key might be denylisted: `uamswp-minerva-kb-migrations` is served on 11 of 29 sites, so the *name* is not denylisted and its absence elsewhere is real. The general constraint is unchanged; this is one key exempted by measurement.

**Rejected: refuse whenever the generations disagree.** The change the ticket was opened to make. It breaks the two sites measured correct, and rests on `active_plugins` being authoritative, which the payloads show it is not.

**Rejected: treat `inside/tri` differently from the `hr` pair.** No signal singles it out. Five were tested and rejected — the settings rows, the `_mkb_` marker options, `rewrite_rules`, the absent writer marker inside the row, and the migrations record's contents — all for one structural reason: **every signal `/options` serves describes the database, and the question is whether the database matches the disk.** `rewrite_rules` is the instructive failure: it looks decisive because a post type's rules exist only while something registers it, but it is a cache that outlives deactivation and is absent where an active plugin's type was never exercised. It fails in both directions, on control sites.

**Any future rule keyed on something the plugin wrote inherits this.** Only `active_plugins` and the denylist diff describe what is *registered* rather than *written*, and this decision is the finding that even those are not current.

**What is not settled.** That v2 *has* run on `inside/tri` is not that it runs there *now*. If it does not, that site imports settings not matching its live behavior. The only instrument in reach that reads the filesystem is the network plugins page, and that read is wordpress-importer-archive#1130. wordpress-importer-archive#1129 already warns on all three sites when the disagreement occurs, changing no selection.

## A change is breaking when it alters the declared surface, which this repository declares locally (wordpress-importer-archive#1051)

**The surface is declared in [`README.md`](../README.md#public-surface) and the test is applied to a diff without consulting the consuming app.** A diff is breaking when it removes, renames, or changes the contract of the console command and its seven options, the `ServiceProvider` registration and its bindings, or a named class in the declared list. Nothing else is, however `public` it happens to be. [`.claude/rules/cutting-releases.md`](../.claude/rules/cutting-releases.md) carries the test; this settles the question that rule deferred.

**Why not measure the app, which is the accurate test.** It makes a release decision depend on reading another repository, and the obvious way to perform that reading is wrong in a way nothing reports: the command is consumed **by name** rather than by `use`, so an import grep misses it entirely. Removing `--source` breaks the app's suite and moves no import anywhere. The declaration is *seeded* from measuring the app rather than replacing that measurement with a guess.

**The three examples in the rule reconcile rather than sit beside the test.** A `statamic/cms` major upgrade changes the declared dependency constraint. A restructure is breaking only where it moves a declared class — which is why one cycle's 137 changed `src/` files were not. A handle rename is breaking where the app depends on it, and the declaration records which those are.

**Rejected: imported classes only.** The ticket's original framing. It misses the command option set entirely, and fails silently rather than loudly.

**Rejected: anything `public` in `src/`.** Local and mechanical, and it over-fires — 137 changed files in one cycle, none needing classification. A trigger that fires every time stops being read.

**The seed was re-measured rather than inherited.** The ticket quoted 12 imported classes, relayed from #992 and flagged unconfirmed because checking it needs the host checkout. Measured 2026-09-21 against `uams-statamic` at `2ba68850a`, pinning `^0.20`: **22** classes reach the package through a real `use` — 2 from the app's own code, 21 from its own test suite, `PasswordVerifier` in both — plus one docblock-only mention that imports nothing. Both consumption sites count, because removing either breaks the consumer.

**What is not settled.** The declaration is a local claim about a cross-repo fact and can drift from what the app consumes; that is the accepted cost of not reading another repository on every release. The mitigation is re-measuring when the app's pin moves. The `protect.entry` middleware alias is declared and currently unconsumed — the app wires its own `App\Http\Middleware\ProtectEntry` instead — so it is declared on the strength of being exposed, not of being used.

## A dataless entry a run materializes is removed, not refused, because the writer is Statamic's (wordpress-importer-archive#1160)

**An import that writes an entry carrying no data beyond `blueprint` has that file removed before the run ends, and the removal is reported in the end-of-run warning summary.** It is not declined at the point of writing.

**Because it cannot be.** The write happens inside `Statamic\Stache\Stores\CollectionEntriesStore::writeItemToDisk()` during a Stache flush — it never fires `EntrySaving`, which is why the host app's own validation listener does not reject entries it considers invalid. This package owns no call site where the write could be refused: `BaseEntryCreator` saves through `->save()`, which does fire the event.

**Rejected: register a subclassed `EntriesStore` that refuses the write.** `Stache::registerStore()` is public, so it is reachable from this addon's `ServiceProvider`, and it would satisfy a literal "refuse" — but it replaces a Statamic core store for the entire host app. The fact that settled it is not visible from inside this package: `uams-statamic` requires this one as `^0.20`, so the replacement would arrive there on a routine dependency update without that repository deciding to adopt it, and it pins `statamic/cms` at `6.26.0` exactly, which turns the coupling into a blocker on that repository's next Statamic bump. The guarantee is stricter than the defect requires and the cost lands on someone who did not choose it.

**Rejected as sufficient alone: widening the `TreeBuilder` paths-cache clear.** It addresses the mechanism the existing comment there names and remains available as a later improvement. It cannot be shown to prevent anything today: the materialization reproduced once in three attempts, the three run logs are byte-identical apart from the directory name, and the trigger is unidentified.

**The sweep is bounded by modification time, and that bound is load-bearing.** The defect writes into a site the run never names, so it cannot be scoped by collection or site. Only files written since the run began are considered; anything older predates the run and is not its to delete. The collections root comes from `ContentRoot`, never `base_path('content/…')`, because the host serves a disposable mirror under `APP_ENV=testing` and a surface pointed at the wrong one deletes content no run produced.

**What is not settled.** That the guard prevents a real materialization rather than a synthetic one is unverified, and is unreachable at a one-in-three trigger — which is why the regression coverage is written independently of it. The trigger itself remains unidentified, with the ruled-out probes recorded on wordpress-importer-archive#1160.

## A scoped `--content-type` read closes over companion types in full, not over referenced ids (wordpress-importer-archive#1089)

**A run that names one content type reads that type, the post types its import depends on, and the taxonomies attached to what it reads.** The companions are `attachment`, `wp_block`, `nav_menu_item` and `tablepress_table` for every scoped run, plus `page` for `kb`. Each one is read out of the export by a type other than itself, and every one of those readers skips silently when its item is absent. Pages inline reusable blocks by looking them up, the navigation pass reads its menu from the export, `importTables()` runs after every non-table type, and a knowledge base resolves its home page from page items. Dropping any of them would import cleanly with a gap.

**Taxonomies come from `/taxonomies` → `object_types`, not `/post-types`**, which carries no taxonomy list. A taxonomy that declares no usable `object_types` is read rather than skipped: absence proves nothing about what it is attached to, and reading one taxonomy too many costs a request where reading one too few costs term assignments.

**The artifact labels itself.** The manifest's `scope` carries `content_type`, `coverage` (`site` or `content_type`) and the `post_types` a scoped run read, so its parts never imply coverage they do not have. The unscoped read, and its artifact, are unchanged.

**The unserved-content report still compares against every exposed type.** A type a scoped run chose not to read is served by the API, so reporting it as unserved would bury the genuine refusals under the run's own filter.

**Rejected: resolving attachments by `include=` from the ids an item references.** It would narrow the read far further. On the measured `logo-list-item` run that is roughly 16,000 records down to about 50, against about 5,200 here. But it needs every way an entry can reference an attachment enumerated first: `_thumbnail_id`, image and file block attributes, gallery ids, and ACF image fields in both meta and block data. One missed shape loses media with no error, which is the loss this ticket exists to avoid. It is deferred to its own ticket rather than rejected on its merits.

**Rejected: whole-site evidence as an opt-in flag, and retiring the evidence role.** Both are recorded on the ticket's `## Decision` section.

**What is not settled.** The companion set was derived by reading every literal cross-type lookup in `src/`, and it is only as complete as that reading. A reader added later that looks up a type outside the set would reintroduce a silent gap on scoped runs, and nothing would fail to announce it.

## A site whose active MinervaKB plugin is v1 and whose v1 row was not served is refused (wordpress-importer-archive#1133)

**Refused, not imported.** Where `active_plugins` names `minervakb.php` and the options endpoint does not serve `minerva-kb-options`, the loader stops with a message naming both rows and the endpoint's withheld counts. Decided by the operator on 2026-09-25. This narrows wordpress-importer-archive#1125 without reversing it: *read the served row* still stands everywhere else.

**The case is `inside.uams.edu/tri`.** v1 runs there on its defaults and never wrote a row, and the only row served is v2's `uamswp-minerva-kb-options`, left behind by a plugin its network does not have (#1130). Importing it produced a configuration the site does not use.

**Why the entry file can decide this direction and not the other.** `minervakb.php` is the root file on every v1 branch and tag of `UAMS-Web/UAMSWP-minervakb` (`1.6.6.1`, `1.6.6.3`, `master`, `staging` and the feature branches) and on no v2 one. `uamswp-minervakb.php` is v2's name, but `hr` ran v1 `1.6.6` from a file of that name and imported correctly from the v1 row (#1134). So the refusal acts only on the v1 name.

**What removed wordpress-importer-archive#1125's objection.** That decision rejected refusing on an unserved installed-generation row because it broke `hr`, whose `active_plugins` then named the v2 file. `hr`'s plugins have since been swapped (wordpress-importer-archive#1134), and it now names `minervakb.php` and serves the v1 row, which this rule imports.

**Rejected: keep importing with the wordpress-importer-archive#1129 warning.** It leaves a known-wrong site wrong on purpose.

**Rejected: take the plugin generation as operator input.** It fixes one site by making every run depend on a person's answer, and adds a public option to do it.

**What is not settled.** The signal is still a database row, so the refusal is not free of the database-versus-disk wall wordpress-importer-archive#1125 describes. It could stop a correct site only where v2 runs while `active_plugins` still names the v1 file, and activating v2 writes its own entry. The endpoint may withhold the v1 row rather than lack it, which also refuses, and the message says so. `cancer/dev`'s v1 plugin under the v2 name remains wordpress-importer-archive#1134's.

## A scoped read also closes over the types links point at, measured, and reports what it still misses (wordpress-importer-archive#1179)

**A run given `--content-type` also reads `page`, `post` and `uamspeople`**, beside the companions recorded under wordpress-importer-archive#1089, and **every lookup the scope still defeats is reported** rather than lost. Decided by the operator from a four-option comparison after the gate handed wordpress-importer-archive#1179 back.

**Why the closure had to grow.** Two readers look items up in the export by something other than the type being imported, and the literal-type search that derived #1089's companions could not see either. `LinkProcessor` turns a link's URL into the target's type and id by matching `<link>` across every item, whatever its type, and only then finds the Statamic entry. So a link whose target's type was not read stays unconverted, even when that entry was imported by an earlier run, which is the ordinary way a scoped run is used. The Find-a-Doc parsers resolve filter ids to `expertise`, `location` and `provider` items. The API cannot fill either gap on demand: `/posts/<type>` offers `include` by id and no lookup by slug or URL.

**The three types are a measurement, and are kept apart from the companions for that reason.** Across the 259 WXR site exports, measured on Windows, 100 sites carry at least one cross-type internal link: 593 of 39,504 posts and 237 of 22,270 pages. Of 905 source-to-target type pairs, 891 land on `page`, `post` or `uamspeople`, or on types already read or deliberately skipped. The 14 left point at the LearnDash course and lesson types, `kb` and `tshowcase`. `provider`, the costliest type a scoped read avoids, is never a link target. Find-a-Doc blocks occur in 1 of 259 exports, on one page.

**Reporting covers the rest, and is what keeps the measurement honest.** `MaterializationScope` records what a scoped run read. A link that stays unresolved on a scoped run, and a Find-a-Doc filter whose type the run did not read, each record a `migration_api` warning naming what was missed. An unscoped run reports nothing extra, because a miss there is a genuine absence. **Each unresolved URL is reported once per run** (#1197). #1197 also reported on a second miss path, a deferred page-builder scan. That scan never reached a real `page_builder` and was removed in #1205, so the initial pass is now the only one.

**Rejected: widening the closure to every type.** No silent loss, but link targets can be any type, so any type with links would read close to the whole site and wordpress-importer-archive#1089's narrowing would be gone.

**Rejected: a narrow read that only reports.** The smallest read, but a scoped `post` run would then leave every page link unconverted, and post-to-page is the single most common pair, at 494.

**Rejected: resolving links against Statamic by slug.** It keeps the read narrow, but a slug match across collections can convert a link to the wrong entry, and a wrong link is worse than one left unconverted.

**What is not settled.** The three link-target types are a corpus measurement that can go stale as content changes; the reporting surfaces that, and nothing re-measures it automatically. The census is WXR data standing in for what the API serves, and matching by URL path within a site undercounts links written in other permalink forms. Links to a `redirect_rule` target stay unconverted, as they are on `main` today, because `LinkProcessor` returns null for that type deliberately.

## Scoped attachments wait for a lookup by file, because an id-only read imports the wrong image (wordpress-importer-archive#1175)

**A scoped run keeps reading every attachment until the migration API can look one up by file.** Narrowing that read to referenced ids with `include[]` is blocked on [`UAMS-Web/uamswp-migration-api-archive#300`](https://github.com/UAMS-Web/uamswp-migration-api-archive/issues/300). Decided by the operator on 2026-09-25 from a four-option comparison.

**Why ids are not enough.** `AssetImporterProcessor` resolves an image by matching its `src` against each attachment's file URL, and prefers that match over the `wp-image-N` class id, because an image replaced in WordPress keeps its old class. An id-only read never loads the URL-matched attachment, so the class id wins. Measured across 254 parsed WXR site exports on Windows, `content:encoded` only:

| Shape | References | Sites | Under an id-only read |
| --- | ---: | ---: | --- |
| Class id names a live, **different** attachment | 442 | 17 | the old image, silently |
| Class id names no attachment | 433 | | the bare `src`: image kept, alt, caption and focal point lost |
| No class, matched by `src` only | 471 | 21 | the same |
| Link to an attachment's page | 515 | 71 | a redirect request, then left pointing at WordPress |
| Link straight to a file URL | 11,026 | 176 | unaffected: `importFile()` takes the URL |

The first row was the deciding one: wordpress-importer-archive#1089 split this work out precisely so that no shape would lose media silently, and that row does worse than lose it.

**Links to an attachment's page fall back to the full read.** When the build lands, an internal link in the scoped items that resolves to nothing the narrowed read fetched makes that run read every attachment instead, and the manifest says so. An attachment page's link cannot be told apart from a link to any other page without the attachment items, so the lookup in wordpress-importer-archive#300 does not cover it.

**Rejected: narrow by id and report the rest.** It meets wordpress-importer-archive#1175's wording and breaks its purpose on the first row above.

**Rejected: narrow by id, then fall back to the full read whenever a scoped item embeds an upload URL no fetched attachment matches.** Correct, and buildable with no upstream change, but content-heavy types would fall back on most runs. The lookup gives the narrowing to every type.

**Rejected: not building it.** wordpress-importer-archive#1089's closure already takes the measured run from about 16,000 records to about 5,200. Attachments are still the bulk of that remainder.

**What is not settled.** The measurement reads `content:encoded` alone. ACF meta, gallery shortcodes and block JSON are id-bearing shapes still to be listed under wordpress-importer-archive#1175's first criterion, and a URL-only shape among them would widen what wordpress-importer-archive#300 has to serve. The counts are not broken down by post type, and a few sites are exported twice, so the totals overcount slightly. How sized variants such as `-750x629` resolve is left to wordpress-importer-archive#300.

## Page-builder URL fields are processed at import, and the deferred scan that never ran is removed (wordpress-importer-archive#1205)

**The deferred page-builder URL scan is removed. The three fields it existed to catch now go through `LinkProcessor` when they are parsed:** SideBySide `link_list[].url`, Gallery `more_button_url` on the ACF-block path, and the links in Gallery's `block_description`. Decided by the operator on 2026-09-25 from a three-option comparison.

**Why the scan could not be the fix.** Its guard skipped any list whose first item carried `type`. That is every stored `page_builder`, and every nested replicator list inside one. So it never rewrote a value, which was measured on wordpress-importer-archive#1197. Repaired, it would still have covered only entries that already held a deferred link, because its one caller ran inside that loop. And it would have replaced any whole string that was a same-site URL, whatever field held it.

**Why the source fix.** An enumeration of every URL-bearing `page_builder` field found three that skipped link processing, while every other one went through `processUrlString()` or `process()`. A count over the 259 WXR exports, taking the first URL after each field name, so approximate, found about 1,069 SideBySide link-list URLs in 56 exports, about 366 of them same-site. The control is ActionBar `button_url`, which is processed: about 1,324 URLs in 144 exports, about 1,030 of them same-site. Processing the three at parse time puts them on the ActionBar path: an internal link converts or defers for every entry, and a linked file is imported.

**Rejected: fix the scan only.** It covers only some entries, it rewrites without knowing which field it is in, and it imports no files.

**Rejected: fix the parsers and keep a repaired scan.** Once the parsers are fixed, the scan has nothing left to catch, and it would still carry the rewrite risk above.

**What is not settled.** The census is approximate. Content already imported keeps its raw URLs until it is re-imported. Lower-confidence fields are left to a follow-up: RSS `feed_url`, and raw HTML kept in the LiveWhale and CounterList descriptions and in shortcode captions.

## Flat taxonomies shared across sites become site-scoped collections, which retires the site-name title prefix (wordpress-importer-archive#1215)

**`post_tags`, `logos_item_labels`, `knowledge_base_tags` and `faq_categories` will be imported as structured collections, each entry written only into the site it was imported for, rather than as flat taxonomy terms.** Decided by the operator on 2026-09-25. It supersedes those four rows of the `wordpress-importer-archive#13` disposition table, which kept them flat.

**Why.** The flat term pass titles every term `"{site name} — {term name}"`. It does so because a Statamic taxonomy term is not site-scoped: the terms fieldtype queries by site (`Fieldtypes/Terms.php`), but every term in a shared taxonomy exists in every site the taxonomy is enabled on. So one site's editors see every site's same-named tags side by side, and the prefix is the only thing in the title that tells them apart. It also reaches readers: the `post_tags` archive heading and `<title>` print "Posts Tagged with {title}". A collection entry lives under `collections/{handle}/{site}/`, belongs to one site, and carries its plain WordPress name, as `StructuredTermImporter` already writes it for `post_categories`, `people_groups` and `logos_item_categories`. Scoping the terms to their site removes the need for the prefix, not just the prefix.

Measured for the decision, on `origin/main` of this repository and of `UAMS-Web/uams-statamic` and its content repository, and across the 259 WXR exports:

| WordPress taxonomy | Destination | Corpus sites | Enabled sites today | Title rendered publicly |
| --- | --- | ---: | ---: | --- |
| `post_tag` | `post_tags` | 53 | 32 | yes, the tag archive heading |
| `logo-list-label` | `logos_item_labels` | 2 | 31 | no; the logo list prints the entry's own `logo_label` |
| `kbtag` | `knowledge_base_tags` | 9 | 1 | no template reads it |
| `mkb_faq_category` | `faq_categories` | 1 | 1 | no template reads it |

**This widens what the collection shape is for.** `SHAPE_COLLECTION` was introduced to carry hierarchy, and the `tshowcase-taxonomy` entry above rejected it for a flat taxonomy on that ground. It now carries site scope as well, so a taxonomy need not be hierarchical to import as a collection.

**Rejected: keep the prefix, exempting only taxonomies that are single-site by design.** It leaves the shared picker and the public tag heading as they are. That fix is right for `classified_categories`, whose board exists on one site, and wordpress-importer-archive#774 applied it there; it does not reach the four above.

**Rejected: drop the prefix wherever the destination is enabled on one site at import time.** "Enabled on one site" is a snapshot of which imports have run: the importer enables a taxonomy for each site it imports into, so `knowledge_base_tags` reads one site today while nine WordPress sites carry `kbtag` terms. Titles would change as sites are added.

**Rejected: drop the prefix everywhere and leave the terms flat.** The site-prefixed slug would be the only site marker, and a shared picker would list one identical title per site.

**Order.** The host application converts the four taxonomies first: collections, blueprints, the fields that reference them, the tag archive route and the logo-list label query. The importer then routes the four WordPress taxonomies to `SHAPE_COLLECTION` and writes entry references where it now writes term slugs.

**What is not settled.** The picker behavior is read from the fieldtype's code, not observed in a running Control Panel. What becomes of terms already imported into the four taxonomies, and of URLs under their term routes, belongs to the host conversions. `classified_categories` stays a flat taxonomy with plain titles (wordpress-importer-archive#774).

## `AGENTS.md` is the single source of agent instructions, and `CLAUDE.md` only imports it (wordpress-importer-archive#1223)

**Decided by the operator on 2026-09-25.** The project instructions that lived in `CLAUDE.md` move to [`AGENTS.md`](../AGENTS.md). `CLAUDE.md` becomes an `@AGENTS.md` import plus one sentence saying where to edit.

**Why.** A Cursor agent is joining the project. Cursor reads `AGENTS.md` and its own `.cursor/rules/*.mdc`; its documentation does not mention `CLAUDE.md`, `.claude/rules/` or `.claude/skills/`. A `CLAUDE.md` that imports `AGENTS.md` loads the same text into Claude Code on every version, so one file serves both agents.

**Rejected: delete `CLAUDE.md` and rely on Claude Code reading `AGENTS.md` natively.** That needs Claude Code v2.1.277 or later, and works only when no `CLAUDE.md` exists, on every seat.

**Rejected: keep two files mirrored by hand.** Two hand-kept copies of the same instructions have already drifted once.

**What moved with it.** `AGENTS.md` now names all eighteen `.claude/rules/` files and all seventeen skills, because a Cursor agent does not auto-load the rules directory. It records `.cursor/` as Cursor-only. `AGENTS.md` joins the `spell` corpus in both CI mirrors, since the text it carries was checked there as `CLAUDE.md`.

**What is not settled.** `tests/CLAUDE.md` stays a Claude Code file; `AGENTS.md` tells other agents to read it. Nothing reconciles `.cursor/rules/` with `.claude/`, and where the two cover the same ground they can disagree.

## A deliberate exports gitlink bump declares itself with a commit trailer (wordpress-importer-archive#1195)

**Superseded (2026-10-25):** the exports submodule left `main` in wordpress-importer-archive#1426, so there is no gitlink to bump. The `exports-gitlink` job, its trailer and the pre-commit gitlink gate (wordpress-importer-archive#1143) went with it. See the wordpress-importer-archive#1426 entry below.

**Decided by the operator on 2026-09-25.** The gate fails a branch whose exports gitlink differs from the one at its merge base with the base branch, unless a commit on the branch carries the trailer `Exports-Gitlink: bump`. The check is the `exports-gitlink` job in both CI mirrors, backed by [`scripts/exports-gitlink-check.mjs`](../scripts/exports-gitlink-check.mjs).

**Why.** wordpress-importer-archive#1143's pre-commit gate refuses an unintended gitlink change at commit time, but `--no-verify` and `ALLOW_EXPORTS_GITLINK=1` both pass it, and no other job depends on which corpus revision a branch records. A trailer is read from git, so the local gate needs no pull-request context or network; it stays in the branch's history beside the change it explains; and it matches the commit-time opt-in.

**Compared at the merge base, not at the base branch's tip.** A branch cut before a later corpus bump on `main` did not make that change, and merging it keeps `main`'s gitlink. For a branch that is current, and for the merged state the gate validates, the merge base is the tip, so the two readings agree where the question matters.

**Rejected: a label on the pull request.** The local gate would need a GitHub API call and a pull-request number it does not always have.

**Rejected: a named file changing alongside.** It is another file to maintain, and any unrelated edit to it would pass.

**What is not settled.** The trailer declares that the branch moves the gitlink, not which revision it moves it to. A branch that bumps deliberately and later reverts by accident in a second commit still passes.

## A resolved fork keeps its `decision-fork` label, and the tickets that lost it earlier are recorded rather than relabeled (wordpress-importer-archive#1066)

**Decided in wordpress-importer-archive#1141, merged 2026-09-16**, following the operator's ruling on wordpress-importer-archive#768 that a resolved fork keeps the label. The flip is `hitl` → `afk` with `decision-fork` kept. It comes off only where a ticket carried build work alongside its fork, which [`design-decision-forks`](../.claude/rules/design-decision-forks.md) step 2 treats as malformed rather than as a second kind of fork ticket. This entry records the convention that step states; the step is the authority.

**Why.** A conforming fork ticket delivers the decision and its follow-ups and nothing else (wordpress-importer-archive#871), so after the flip `decision-fork` still describes the whole ticket. Keeping it is also how a later reader tells a ticket that once carried a fork from one that never did, and that signal means nothing if half the resolved tickets have dropped it.

**Rejected: remove it on every flip.** That was the rule's text until wordpress-importer-archive#1141, and it was the reading that practice had already left: wordpress-importer-archive#768 kept the label by ruling while the rule said to remove it.

**Rejected: keep it without exception.** Placement reads the label, so a ticket that is mostly build work but still labeled `decision-fork` is routed as a fork to decide. #793 is the recorded instance.

**Tickets resolved under the removal reading are recorded as pre-dating this decision, not relabeled.** Measured from the repository's full issue-event history (114 pages of up to 100 events, back to 2025-03-31, read on 2026-09-26): 50 tickets had `decision-fork` removed and never re-added. The probe was checked against two known cases. wordpress-importer-archive#768 was labeled, unlabeled and relabeled, and is correctly absent; wordpress-importer-archive#1089 was unlabeled under the exception, and is correctly present.

- **Removed before wordpress-importer-archive#1141 merged, 48 tickets:** #2, #4, #5, #10, wordpress-importer-archive#12, wordpress-importer-archive#13, wordpress-importer-archive#21, wordpress-importer-archive#22, wordpress-importer-archive#23, wordpress-importer-archive#33, wordpress-importer-archive#35, wordpress-importer-archive#133, wordpress-importer-archive#134, wordpress-importer-archive#299, wordpress-importer-archive#413, #434, wordpress-importer-archive#439, #442, #448, wordpress-importer-archive#457, wordpress-importer-archive#467, wordpress-importer-archive#496, wordpress-importer-archive#501, wordpress-importer-archive#502, wordpress-importer-archive#512, wordpress-importer-archive#593, wordpress-importer-archive#604, wordpress-importer-archive#620, wordpress-importer-archive#624, wordpress-importer-archive#628, wordpress-importer-archive#658, wordpress-importer-archive#668, #695, wordpress-importer-archive#706, wordpress-importer-archive#717, wordpress-importer-archive#741, wordpress-importer-archive#754, wordpress-importer-archive#778, #793, wordpress-importer-archive#815, wordpress-importer-archive#831, #853, wordpress-importer-archive#861, wordpress-importer-archive#884, wordpress-importer-archive#885, wordpress-importer-archive#922, wordpress-importer-archive#982, wordpress-importer-archive#986. Twenty of them lost it within ninety seconds on 2026-08-03, which was a single sweep. Eight are still open: #10, #434, #442, #448, #695, wordpress-importer-archive#778, #793 and #853.
- **Removed under the exception, 2 tickets:** wordpress-importer-archive#1089, the worked instance step 2 cites, and wordpress-importer-archive#1195, whose one decision criterion sat among four build criteria.

Relabeling the 48 was rejected. Each would need its own reading to establish whether it carried build work, since the exception applies to exactly that shape. A bulk restore would put the label back on tickets it was rightly removed from. A pre-dating ticket without the label means only that it was resolved before this decision, not that it never carried a fork.

**The sibling repositories do not carry the step #1066 assumed.** Read from each default branch on 2026-09-26. `uams-statamic`'s `design-decision-forks.md` never mentions `decision-fork` (0 occurrences against 6 of `hitl` in the same file), so it neither removes nor keeps the label. `uamswp-migration-api`'s copy says the label "is never removed once resolved", with no exception. So this repository differs from that one on build-carrying tickets only. That difference is reported rather than reconciled, since each repository may answer it differently.

**Both siblings now keep the label, and one describes this repository wrongly.** Re-read on 2026-09-30. `uams-statamic` has since decided to keep `decision-fork` after the flip (`UAMS-Web/uams-statamic-archive#2499`, recorded by #2557), so all three repositories agree on the default, and only this repository's build-carrying exception differs. That rule also says this repository removes the label because a script here branches on it. Neither is true: no script or workflow here reads `decision-fork`, and the only mentions are in three issue templates. The correction is filed there as `UAMS-Web/uams-statamic-archive#2717`, which is how #1066's last criterion, telling the sibling repositories, was met.

**What is not settled.** Line 5 of the rule describes `decision-fork` as a flavor of `hitl`, which fits a ticket that has left `hitl` for `afk` only loosely. wordpress-importer-archive#1141 named that tension and left it alone, and this entry does the same.

## A site serving both MinervaKB settings rows is refused, not guessed at (wordpress-importer-archive#1134)

**Decided by the operator on 2026-09-28.** Where the options endpoint serves both `uamswp-minerva-kb-options` and `minerva-kb-options`, the settings loader stops with a message that names both rows and says how to recover. `ENTRY_FILE_ROWS` no longer chooses a row. It survives only where it can refuse (a v1 entry file with only v2's row served, #1133) or warn (a v2 entry file whose row was not served, #1129).

**Why.** A plugin's entry-file name is not its generation: `hr` ran v1 `1.6.6` from v2's `uamswp-minervakb.php`, and the `cancer` network carries that one filename under both generations at once. Every signal `/options` serves describes the database, and which generation runs is a fact about the disk (#1125), so no payload signal can settle which of two served rows is live. A stopped import is recoverable; an import of the wrong generation's settings is silent.

**The cost is deliberate.** Four measured sites served both rows and imported correctly through the filename map: `cancer/root`, `communications/creative-services`, `communications/web`, and the Clinical Education site on the `inside` network. Each now stops until the leftover row is removed from that site's `wp_options`. `inside/center-for-nursing-excellence` serves both rows too, and keeps its more specific no-plugin refusal.

**Rejected: keep the map as a declared heuristic and warn whenever it decides.** Same rows imported everywhere measured, but a site whose filename and generation disagree would still import the wrong row, only loudly.

**Rejected: remove the map and fall back to the name preference.** That is `v0.19.0`'s behavior, and it imports the orphaned v2 row on the `inside` network's Clinical Education site, which `v0.20.0` fixed.

## An operator declares the MinervaKB generation with a command option (wordpress-importer-archive#1235)

**Decided by the operator on 2026-09-28.** `statamic:import:wordpress --minerva-generation=v1|v2`, with `--source=api`, names the generation the site runs, as read from the network plugins page. Where both settings rows are served, the declared generation's row is read and the output says the choice came from the operator. A declared generation whose row was not served is refused, and so is a declaration where the endpoint reports no MinervaKB installed. Without the option, wordpress-importer-archive#1134's both-rows refusal stands. On a file-sourced run the option is refused rather than ignored.

**The declaration outranks every payload signal.** The network plugins page reads plugin headers from disk, which no `/options` signal can (wordpress-importer-archive#1125), so the entry-file refusal and warning (wordpress-importer-archive#1133, wordpress-importer-archive#1129) are not consulted when a generation is declared.

**Why.** It follows the `--unsupported-content` precedent, works for unattended runs, cannot go stale because it is supplied on every run, and never writes to a production WordPress database. Adding an option changes no existing contract, so it is not breaking; the declared option list grows from seven to eight.

**Rejected: an interactive prompt.** Unattended runs are a supported mode, and they would keep refusing.

**Rejected: a per-site map in host-app configuration.** A new cross-repository surface that stores a disk fact, and goes stale after a plugin swap.

**Rejected: not building it.** The recovery would stay a production database edit on every affected site.

**What is not settled.** The declaration is only as good as the plugins-page read. Naming the wrong generation where both rows are served reads that row, and is caught only when the declared row was not served.

## The MinervaKB plugin header read from disk decides the generation, and `--minerva-generation` becomes optional where it is served (wordpress-importer-archive#1262)

**Decided in the build of #1262, on 2026-09-30.** The migration API serves `plugins` on `/options`: each active plugin path with the `Name`, `Version` and `TextDomain` its file declares, read from disk (`UAMS-Web/uamswp-migration-api` D-087). The two generations declare different Text Domains, `minerva-kb` for v1 and `uamswp-minerva-kb` for v2. Where exactly one of them is served, that generation decides which settings row is read, and the output says the choice came from disk.

**A declaration the header contradicts is refused, naming both generations.** The declaration is the operator's read of the plugins page, and the header is the same field read from the same file, so where they disagree the declaration is the one that can be mistaken. A declaration the header agrees with is read as before.

**The option stays, and is optional wherever the header is served.** A site serving both rows no longer needs it. The header outranks every database signal, as a declaration does: the entry-file refusal and warning (wordpress-importer-archive#1133, wordpress-importer-archive#1129) and the both-rows refusal (wordpress-importer-archive#1134) are not consulted. A header whose generation's row was not served is refused, for the reason a declared one is.

**Where the header decides nothing, wordpress-importer-archive#1235's behavior is unchanged.** That covers an endpoint released before D-087, a `null` header (an active path with no installed plugin), an empty or unrelated Text Domain, and headers naming both generations at once.

**Rejected: use the header only where both rows are served.** A site serving one row whose generation the header contradicts would still import the other generation's settings silently, which is the failure this series exists to stop.

**Rejected: keep the option required where both rows are served.** It would ask the operator for the one fact the endpoint now reads directly.

## The `.claude/` rules and skills govern every agent, and the Cursor-only style rules are removed (wordpress-importer-archive#1275)

**Decided by the project owner on 2026-10-01.** The 21 style rules in `.cursor/rules/` are deleted, and nothing is ported into `.claude/`. wordpress-importer-archive#1223 made `AGENTS.md` the single source of agent instructions and pointed it at every `.claude/rules/` file and skill. The `.cursor/rules/` files predate that, were written separately, and Claude Code never loads them. So where one covered the same ground as a `.claude/` rule or skill, a Cursor agent could act on the stale one. `AGENTS.md` now says the `.claude/` rules and skills govern every agent.

**Laravel Boost cannot put a file back here.** It is not a dependency of this package: there is no `vendor/laravel/boost`, and `boost.json` has never been committed. The only mention of it in `composer.lock` is a `conflict` constraint that another package declares. So no regeneration step exists to guard against.

**Rejected: port the Cursor rules into `.claude/`.** The `.claude/` rules and skills are the standard. Porting would bring the drift in instead of removing it.

**Rejected: keep `.cursor/rules/` and reconcile it by hand.** Two hand-kept copies of the same instructions are what drifted. That is the reason wordpress-importer-archive#1223 gave for removing the second copy of `CLAUDE.md`.

**What is not settled.** Two Cursor commands, `apply-arrow-functions` and `apply-php-type-hints`, tell Cursor to apply rule files this removes. Anything else under `.cursor/` is outside wordpress-importer-archive#1275, so they are left as they are and tracked in wordpress-importer-archive#1276.

## The public repository is a new one built from a cleaned copy of history, and this repository stays private as the archive (wordpress-importer-archive#1246)

**Decided by the project owner on 2026-10-02.** History holds the WordPress export corpus that the initial commit `4670f95` added. It carries 748 distinct author emails, 257 distinct comment-author emails and 11,643 unpublished items (11,127 `draft`, 480 `private`, 32 `pending`, 4 `future`). That was measured across every ref of a mirror clone (1,766 commits), and branches and tags alone give the same counts. Switching this repository's visibility would publish all of it. So a cleaned copy of the history is published as a new repository, and this one is kept private.

The copy removes:
- the export corpus and the two WXR parts once committed at the root (`e16abef`, removed in `e39bb6f`);
- `.claude/slack-workspace.json`;
- the removed lines that carried home-directory paths;
- the private exports submodule, because a public repository cannot carry a submodule its readers cannot fetch.

The follow-up work is wordpress-importer-archive#1292 to wordpress-importer-archive#1296, each blocking the switch in #1251.

**Rejected: rewrite this repository's history in place.** It changes every commit ID, breaks every clone and every cited SHA, and cannot be undone. It also does not finish the job: GitHub keeps pull-request refs and cached views after a force-push, and its guide to removing sensitive data says only GitHub Support can purge them. The exports would stay reachable through every pull request that touched them. The new-repository route leaves this repository and every clone untouched until the publish, so it stays reversible up to that point.

**Rejected: accept the exposure.** It would publish the emails and unpublished posts counted above.

**The public repository's name, where its tracker lives, and what happens to cited commit SHAs** were left open here and are settled in the wordpress-importer-archive#1292 entry below.

**Superseded in part (2026-10-25):** the public repository is built from one commit of `main` rather than from a cleaned copy of the history. See the #1244 entry below.

## The public repository takes the `wordpress-importer` name, every issue is copied with its number, and cited commits are remapped (wordpress-importer-archive#1292)

**Decided by the project owner on 2026-10-02**, settling what the wordpress-importer-archive#1246 entry left open.

**Superseded in part (2026-10-25):** the name stands; the tracker copy and the commit remap do not. Only the open issues move, by transfer. See the #1244 entry below.

- **Name.** The public repository is `UAMS-Web/wordpress-importer`. This repository is renamed to an archive name and stays private (#1299). The public name then matches the Composer package `uams-web/wordpress-importer`, so UAMS-Web/uams-statamic only updates its lock file and keeps its repository URL (#1296).
- **Tracker.** Every issue, open and closed, is recreated in the public repository with its original number, because GitHub does not transfer issues from a private repository to a public one. Pull-request numbers and gaps get placeholder issues, and the copy runs before anything else is opened there (#1300). The copies get the same redaction review as the history before the repository is made public (wordpress-importer-archive#1301).
- **Cited commits.** `git filter-repo` rewrites abbreviated commit IDs in commit messages. The tracked files that cite this repository's commits, and the copied tickets, are rewritten from its old-to-new commit map. The map is kept in the private archive, where the original commits still resolve (wordpress-importer-archive#1294, #1300).

**The cost that was accepted.** Once the public repository reuses the name, every existing `UAMS-Web/wordpress-importer#N` reference resolves to the public repository rather than to this one. That covers this tracker, sibling trackers and `docs/`. GitHub's rename redirect stops working once another repository takes the old name. Copying every issue with its number is what keeps those references pointing at the right ticket.

**Rejected: give the public copy a new name.** Every existing reference would keep working and the tracker could stay where it is. But the public name would differ from the package name, and UAMS-Web/uams-statamic would have to change its repository URL.

**The order the follow-ups run in:**
1. Rename this repository (#1299).
2. Create the public repository private and push the verified copy (wordpress-importer-archive#1294, wordpress-importer-archive#1295).
3. Copy the tracker into it (#1300).
4. Review the copies (wordpress-importer-archive#1301).
5. Switch it to public (#1251).

## API-sourced content is cleaned with the WXR cleaner's own pipeline, for listed sites only (wordpress-importer-archive#1303)

**Decided by the project owner on 2026-10-02.** `news.uams.edu`'s export is cleaned with `uams-web/wordpress-wxr-cleanup` before import. Content read through the migration API skipped that step, so the same post mapped differently depending on its source.

**How it runs.** The cleaner gained `Transform::withDefaultRules()`, which defines its 47-rule pipeline once (UAMS-Web/wordpress-wxr-cleanup#6, tagged `v1.0.0`). The importer requires it as `^1.0`, and `WxrMaterializer` runs that pipeline over each part it writes, so an API-sourced post goes through exactly what the CLI applies to an export.

**Where it runs: only sites listed in `wordpress-import.wxr_cleanup.sites`, by `base_blog_url` host. The list starts as `news.uams.edu`.** The cleaner removes every HTML comment, Gutenberg block delimiters included, and the importer dispatches blocks from those comments. In the export corpus (`2dc5f67`), 240 of 259 exports carry block markup. `news.uams.edu`'s raw export has 29 of 36,531 items with block markup, and its cleaned export has none, so for that site this matches the WXR path exactly. Cleaning every site would flatten block-built content almost everywhere. Leaving the inside.uams.edu news archive off the list is deliberate, and adding it is a separate decision.

**Rejected:**
- **Cleaning every API post**, the ticket's original scope. It breaks block content on nearly every site.
- **Cleaning only posts with no block markup.** It is a heuristic, and it changes block-free posts on sites whose exports are never cleaned, so the two paths would no longer match.
- **Teaching the cleaner to keep block comments, then cleaning everything.** Its other rules, such as the span and div unwraps, would still reshape block HTML. It would need a review of all 47 rules against block content.
- **Copying the rule list into the importer, or calling the cleaner's CLI for each part.** Either a second copy of the order, or a subprocess coupled to a bin path.

**Consequence for the host.** Composer reads `repositories` from the root package only, so UAMS-Web/uams-statamic needs its own `vcs` entry for the cleaner before it can install an importer release that requires it. That is UAMS-Web/uams-statamic-archive#2820.


## The WXR cleaner is made public after its own pre-publication review, so a public install can still clean content (wordpress-importer-archive#1304)

**Decided by the project owner on 2026-10-02.** The importer requires `uams-web/wordpress-wxr-cleanup` (the #1303 entry above), and that repository is private. Once this repository is public, anyone installing it could not fetch the dependency. So `UAMS-Web/wordpress-wxr-cleanup` is made public too, after a pre-publication review like this repository's own.

The cleaner is small (53 commits, one pull request, no issues), so its review is short. Making it public keeps one copy of its rules that this repository and anyone reading it can both install. Its README will say that it was built for `news.uams.edu` and the inside.uams.edu news archive as their content stood in 2026, and is not a general-purpose tool.

**Rejected:**
- **Bundling the cleaner's source into this repository.** It creates a second copy of the rules to keep in step, which is the problem wordpress-importer-archive#1303 set out to avoid.
- **Keeping the cleaner private.** A public install of this repository could not run the cleaning step, so it would import differently from the one the migration uses.

**The follow-ups are all in `UAMS-Web/wordpress-wxr-cleanup`:** the pre-publication review (UAMS-Web/wordpress-wxr-cleanup#2), its license (UAMS-Web/wordpress-wxr-cleanup#3), the README notice (UAMS-Web/wordpress-wxr-cleanup#4), and the switch to public itself (UAMS-Web/wordpress-wxr-cleanup#5), which blocks #1251.

## The synced `.cursor/rules/` stay, and this package's review examples move into a rule of its own (wordpress-importer-archive#1320)

**Decided by the project owner on 2026-10-05.** The shared skills and rules sync (wordpress-importer-archive#1313) turned `main` red: it wrote 20 rules into `.cursor/rules/`, which `CursorRulesRetiredTest` guarded as empty, and it replaced the `adversarial-review` wording that `AdversarialReviewQuestionsTest` asserted on.

**`.cursor/rules/` keeps the synced rules.** The shared layout delivers each rule to Cursor as `.cursor/rules/<name>.mdc` on purpose, so Cursor reads the same text as the synced files in `.claude/rules/`; the repository's own rules are not delivered there. This replaces the part of the wordpress-importer-archive#1275 entry above that left `.cursor/rules/` empty; what wordpress-importer-archive#1275 removed, the separately written Cursor-only rules, stays removed. `CursorRulesRetiredTest` is retired, and `AGENTS.md` describes `.cursor/` as the synced section does.

**The review examples move into `.claude/rules/adversarial-review-in-this-package.md`.** It is this repository's own file, which the sync manifest does not list, and `AdversarialReviewQuestionsTest` now checks that file only. The checks of the shared rule's generic wording live in `UAMS-Web/uams-claude-skills`, beside the one copy they guard (UAMS-Web/uams-claude-skills#33).

**Rejected:**
- **Excluding `.cursor/rules/` from this repository's sync.** Cursor sessions here would get no shared rules.
- **A section of the shared rule rendered only for this repository.** Shared files are meant to hold only what is general to every repository. The shared source still renders one for this repository today, so the examples sit in both places until it stops (UAMS-Web/uams-claude-skills#117).
- **Keeping only the generic checks here.** They would still break whenever the shared wording changes.

**The vocabulary the sync brought in** (`frontmatter`, `remutate`, `strpos`, `trailingslashit`, `untrailingslashit`, `upserted`, `upserts`) is added to `project-words.txt`, each with its reason. The four words the upstream em-dash removal truncated were fixed at the source (UAMS-Web/uams-claude-skills#28) and arrived through the next sync.

## The shared local CI runner replaces `scripts/local-ci.mjs`, in a slot of its own (wordpress-importer-archive#1319)

**Decided by the project owner on 2026-10-13, through the reviewed pull request, once parity was shown.** This retires the keep decision `AGENTS.md` recorded for wordpress-importer-archive#1316: the repository kept its own runner because the shared one lacked worktree-slot checkout, runner handoff, a single-flight lock and the pull request watcher. The shared runner now ships all four, so the private runner, about 1,400 lines hand-kept as a mirror of `ci.yml`, is removed with its tests.

**What replaces it.** [`local-ci.json`](../local-ci.json) lists the nine jobs `ci.yml` gates, each selected on every run, as the private runner ran them, with `chain` off so one run reports every failure. Three local-only jobs sit beside them: `dependencies`, `synced-files` and `handed-back-workflow`. [`scripts/ci-local.test.mjs`](../scripts/ci-local.test.mjs) checks that every `ci.yml` job is in the list and that the commands which can match do, and `.githooks/pre-commit` now pairs `ci.yml` with `local-ci.json`.

**What the private runner did that the shared one leaves to the repository, and where it went:**

| The private runner | Now |
| --- | --- |
| `composer install` and `npm ci` in the CI tree before the jobs | the `dependencies` job, in the runner's own slot only ([`scripts/prepare-dependencies.mjs`](../scripts/prepare-dependencies.mjs)); `scripts/ci-local.mjs` adds it to every `--jobs` subset, since the shared runner ignores `always` once `--jobs` is given |
| the exports submodule moved to the branch's gitlink where initialized (wordpress-importer-archive#1139) | the same job; not `prepare.submodules`, which runs `--init` and would clone the 1.8 GB corpus |
| Pint over `.` plus the dot-directories holding tracked PHP | [`scripts/run-pint.mjs`](../scripts/run-pint.mjs) |
| cspell over the prose corpus, with a zero-files floor | [`scripts/run-spell.mjs`](../scripts/run-spell.mjs) |
| `--fresh`, `--skip-install` | refused by [`scripts/ci-local.mjs`](../scripts/ci-local.mjs), naming what to do instead |
| validating a local ref with no upstream (the `ci/<N>-validate` pattern) | dropped. It had two uses. A branch held by an author's slot no longer needs it: the shared runner validates the pushed head detached. Validating a branch's merged state with `main` without pushing it is gone: the shared runner refuses a ref absent from `origin`, so the gate certifies only the pushed head, and a branch behind an interacting merge is synced by its author first, as the `sync-pr-branch` rule already asks |

**The slot is `../wordpress-importer-local-ci`, not `../wordpress-importer-ci`.** The old tree was made by the private runner, carries no `local-ci-slot` marker, and the shared runner refuses to move a tree it did not create. `scripts/ci-local.mjs` passes the new path, and `scripts/worktree-slots.mjs` reads it as runner-owned. Removing the old tree is a separate step, because it deletes a worktree.

**`remove-handed-back.yml` is replaced by `clear-handed-back.yml`, a copy of the `handed-back-label` skill's template (wordpress-importer-archive#1383).** The template also clears the label on a non-bot `edited` event that changed the body, so a hand-back for a pull-request-body finding clears when the body is fixed rather than waiting for a push. The `handed-back-workflow` job fails when the copy drifts from the template.

**Rejected:**
- **Keeping the private runner beside the shared one.** Two runners mean two gates that can disagree, and the private one is the half nothing else keeps current.
- **`prepare.submodules` for the exports submodule.** It initializes what it updates, so every slot would clone the corpus.
- **Reusing `../wordpress-importer-ci` by writing the marker into it.** That hand-drives a tree another session may be validating in.

**Residue.** The synced rules and skills still describe `scripts/local-ci.mjs` in their repository-specific overlays (`worktrees`, `local-ci`, `pre-merge-check` and others). Those are edited in `UAMS-Web/uams-claude-skills`, not here (UAMS-Web/uams-claude-skills#273).

## The Feature helpers reach their tests through a real base class, not a trait composed by `uses()` (wordpress-importer-archive#1031)

**Decided by the project owner on 2026-10-24**, when the measurement for wordpress-importer-archive#1031 showed that the analysis-stub mixin was doing two jobs and the fixed plugin retired only one. `tests/Feature` files that need the import helpers now call `uses(WordPressImportTestCase::class)`, an abstract class in `tests/Feature` that extends the host app's `Tests\TestCase` and uses `WordPressImportTestTrait`. The stub at `tests/Support/IdeStubs/Tests/TestCase.php` no longer carries a `use` of the trait.

**What was measured** (Windows 11, `pest-plugin-phpstan` `v5.2.2`, mixin removed): the visibility residue `v5.2.0` left (182 errors) went to zero, but PHPStan reported `trait.unused` and stopped analyzing the trait body. An error planted in a test closure was reported and one planted in the trait body was not. With the base class, both are reported, and the trait is analyzed in the context of `WordPressImportTestCase`.

**What it costs.** The 23 helpers test closures call are `protected` rather than `private`: a private member of a trait used by a parent class is private to that parent, and the generated class Pest binds each closure to cannot call it (PHP 8.4: `Call to private method Base::priv() from scope Gen`). They leave the uncalled-helper guard's scope, which reads only `private` (wordpress-importer-archive#1407). The trait's `$cachedEntryData` is now one static array shared by every file extending the class, so its keys carry `static::class`, keeping each file's entries apart as the per-class trait copy did.

**Rejected:**
- **Keeping the mixin with a corrected docblock.** Analysis would keep modeling a composition that exists only at run time, the source of every version of this problem: 540 errors on `v5.0.0`, 182 on `v5.2.0`, `trait.unused` on `v5.2.2`.
- **Moving the mixin to a separate analysis-only stub class.** The same stand-in, in another file.
- **Removing the mixin and ignoring `trait.unused`.** A new suppression, and PHPStan stops analyzing the trait body.

## The public repository starts from one commit of `main`, and only the open issues move to it (#1244)

**Decided by the project owner on 2026-10-25**, replacing the history and tracker parts of the #1246 and #1292 entries above. The publish follows the route `UAMS-Web/uamswp-migration-api` took (`UAMS-Web/uamswp-migration-api-archive#465`):

1. This repository is renamed `wordpress-importer-archive` and stays private.
2. A new private `wordpress-importer` is created holding **one commit**, whose tree hash equals a chosen commit on this repository's `main`.
3. The open issues are **transferred** to it while it is still private, and their references are rewritten.
4. It is switched to public, and the archive is archived.

**Why.** The new repository publishes one tree and the issues moved to it, so what has to be clean is that tree and those issues, not every commit and every ticket since `4670f95`. The export corpus, the WXR parts, `.claude/slack-workspace.json`, the home-directory paths and the earlier text of the passages wordpress-importer-archive#1289 redacted are all absent from `main` already, so none of them can reach the new repository. That makes a history rewrite, a commit map and a tracker copy unnecessary.

**What it changes:**
- **The cleaned history copy (wordpress-importer-archive#1294) and the full tracker copy (#1300 as first written) are not built.** The secret scan runs over the chosen tree, with a positive control, instead of over a rewritten history. The token findings in wordpress-importer-archive#1268 to wordpress-importer-archive#1272 are in the corpus, which `main` no longer carries.
- **The exports submodule is removed from `main` before the cut**, since a public repository cannot carry a submodule its readers cannot fetch, and the tree hash must match a commit on `main`.
- **Only open issues move**, and a transfer keeps the comments, and the labels the new repository also has, but assigns the next free number. The redaction review (wordpress-importer-archive#1301) covers those issues only.
- **Commit IDs cited in tracked files are left as citations into the archive**, where they still resolve. The new repository has none of them.
- **References meant for this repository are re-pointed to the archive** between the rename and the creation, while the rename redirect still resolves, as `UAMS-Web/uamswp-migration-api-archive#471` did there. That covers qualified links and bare `#N` references in tracked files. Synced files are edited in `UAMS-Web/uams-claude-skills`.
- **The first commit is tagged** at or above the next version of the line `UAMS-Web/uams-statamic` pins (`^0.27`), and that app updates its lock the same day, since the lock names a commit the new repository does not have.

**Rejected: the cleaned copy of the whole history (wordpress-importer-archive#1246, wordpress-importer-archive#1292).** It keeps every commit ID and issue number resolvable in the public repository, at the cost of a scripted rewrite of 1,766 commits, a commit map, and recreating 1,285 issue and pull-request numbers with placeholders, each of which needs its own review before publishing. The snapshot publishes less and needs less checked.

**The cost that was accepted.** The public repository's history begins at the cut. Older commits, closed issues and every pull request stay readable only in the private archive, and a citation to one of them names something a public reader cannot open.

## The tests that read the export corpus find it through `WORDPRESS_IMPORTER_EXPORTS` (wordpress-importer-archive#1426)

**Decided 2026-10-25, as the build step of the #1244 decision.** The exports submodule is removed from `main`. The two standalone tests that read the corpus take the location of a local clone of `UAMS-Web/wordpress-importer-exports` from the `WORDPRESS_IMPORTER_EXPORTS` environment variable, through `tests/Support/ExportsCorpus.php`. They skip when it is unset, and fail when it is set to something that is not a directory, so a mistyped path cannot pass as a skip.

**Why an environment variable.** It is the shape the host app already uses for the same corpus: its `Feature` audits read `FRM_AUDIT_FIXTURES_DIR` and `GF_AUDIT_FIXTURES_DIR`. It needs no file in this repository naming a path on anyone's machine, and the gate runs with it unset.

**Rejected: a fixed sibling path, such as `../wordpress-importer-exports`.** It would run the corpus tests whenever a clone happened to sit there, so whether they ran would depend on the disk rather than on a choice.

**Rejected: keeping the gitlink gate and the `exports-gitlink` job, repointed at an absent gitlink.** A branch cut before the removal that still moves the gitlink meets a conflict on merge, since the path is deleted on `main`, and #1299 checks the published tree for any submodule entry. A job for a path that cannot reappear unnoticed checks nothing.

**What is not settled.** The synced rules and skills still describe the submodule; they are edited in `UAMS-Web/uams-claude-skills`.

## An `[accordion]` imports into Bard as headings and paragraphs (#118)

**Decided 2026-10-27, on #118.** In Bard content, a WordPress `[accordion]` is flattened by the importer: each `[section title="..."]` becomes an `<h3>` holding its title, followed by its content. Bare and attributed accordions are treated the same, and the host app needs no change. `AccordionShortcodeProcessor::parse()` is unchanged, because the audio post importer reads transcripts from the section data it returns.

**Why.** The host app defines no `accordion` Bard set, so the set the importer used to emit for an attributed accordion did not display. Fixing #118 sends the bare form down the same path. The news exports carry roughly 1,810 bare `[accordion]` blocks, most of them in audio posts, whose transcripts are read through `parse()` and never reach Bard; across the export corpus, 14 distinct non-audio posts carry an accordion that reaches Bard (19 counting the posts two ortho exports share), 6 of them in the news exports, where `main` left it as raw shortcode text. Flattening keeps the text visible, with the section titles as headings.

**Rejected: keep emitting the `accordion` set and define the set in the host app.** It keeps the accordion's structure, but the content stays hidden until a host change ships, and the change would cover only an accordion set, not the importer.

**Rejected: hold the bare form at `main`'s behavior and decide accordions on a ticket of their own.** It keeps #118 small, but it leaves the bare form as raw shortcode text and the attributed form as an undisplayed set, both of them wrong today.

## A page builder `[caption]` imports as an image set inside a content block (#119)

**Decided 2026-10-27, on #119.** The importer builds no caption page builder block. `ShortcodeProcessor::process(..., 'page_builder')` returns none for a caption, as for accordion and audio, so a `wp:shortcode` block holding one `[caption]` gives its Tiptap HTML, the same `image` set the Bard route makes, and on a page builder page that set lands inside a `uamsweb_block_content` set.

**Why.** The host app defines no `uamsweb_block_caption` set, so the block the importer built for this path could not render, and it was also built without the caption's content. The content block's body already accepts the `image` set.

**Rejected: build the caption block with its content.** It would still not render.

**Rejected: build it and define `uamsweb_block_caption` in the host app.** It renders only after a host change, and a page builder caption would then differ from the same caption in Bard content.

## `main` requires `CI`, `Attribution footer` and `Closing keywords` (#163)

**Decided 2026-10-27, on #163, by the project owner.** A ruleset that reaches this repository alone requires three status check contexts on `main`: `CI`, `Attribution footer` and `Closing keywords`. These are the three that the shared `required-checks-ruleset.json` template in the `code-quality` skill requires, and that the Statamic add-ons and `UAMS-Web/uamswp-migration-api` already require. The ruleset carries only the `required_status_checks` rule and layers on the two organization rulesets, `main: no deletion or force push` and `main: pull request required`. The builder applies it with `apply-required-checks-ruleset.mjs` once the workflows are on `main`, so no open pull request waits on a check its branch cannot report.

**`CI` is an aggregate.** It is the last job in `.github/workflows/ci.yml`. It `needs:` the other eight jobs, runs `if: always()`, and fails unless every needed job reports `success`. `scripts/ci-local.test.mjs` fails when a job is missing from that list or when `CI` carries the `RUN_REMOTE_CI` gate. The other two contexts are the jobs of `check-attribution-footer.yml` and `check-closing-keyword-across-lines.yml`, copied from the current `writing-pull-requests` templates in `UAMS-Web/uams-claude-skills`.

**The `RUN_REMOTE_CI` gate stays** on every job but `CI`. The organization variable is scoped to private repositories and does not reach this one. On 2026-10-27, `GET repos/UAMS-Web/wordpress-importer/actions/organization-variables` returned `total_count` 0, while the same call on `wordpress-importer-archive` returned `RUN_REMOTE_CI`. Only a repository variable switches the jobs off here, and `CI` then fails rather than passing.

**Not changed: whether the local chain gates a merge.** That stays as it was.

**Rejected: requiring the eight jobs by name.** A renamed job silently drops out of the ruleset, and a job skipped by its `if:` reports success to a required check.

**Rejected: leaving `main` with no required check.** A pull request could then merge with a red or missing remote run, though the chain runs on every pull request here.

**Rejected: removing the `RUN_REMOTE_CI` gate.** It changes every job for no gain once `CI` fails when the switch skips them.

## Redirect rules sharing a source keep the rule WordPress serves (#108)

**Decided 2026-10-27, on #108.** When published rules on one vanity domain share a source, the `redirect_domains` entry holds the rule WordPress tries first for that path. Every other rule with that source is shadowed. It is not written, and it is reported with the rule that serves the path, in the run's output and in the warnings summary under `redirects` / `shadowed_rules`. Before this change, the entry held whichever rule came last in the export, and the collapse showed only as an `updated` count.

**The order, and the evidence for it.** WordPress answers a path with the first rule that matches it:

- **Pretty Links** dispatches on `init` at priority 1: `src/Bootstrap.php` in 4.0.15 (the local `uamshealthmu` install), and the plugin's application controller (`app/controllers/`, line 35) in 3.6.18 (plugins.svn.wordpress.org).
- **Redirection** runs its loop on `init` at the default priority 10: `modules/wordpress.php` in 5.0 and 5.10.1 (the plugin's GitHub repository). Its `Red_Item::get_for_matched_url()` reads the enabled rules matching the URL and sorts them by `position`, then by id (`sort_urls()`), and the first match redirects.
- **Safe Redirect Manager** matches on `parse_request` or `template_redirect`, both after `init`, and orders its rules by `menu_order ID`, ascending: `inc/classes/class-srm-redirect.php` and `inc/functions.php` in 1.9.3 (the local install).

So Pretty Links answers before Redirection, and within one plugin the lowest position wins, then the lowest id. Sources are compared ignoring case, because the importer finds an entry through a Stache `where()`, which compares in lower case (`Statamic\Query\Builder::filterTestEquals()`), so case variants of a source can only ever be one entry. Pretty Links slugs are unique, so two of its links never share a source. The plugin versions go.uams.edu and gouams.com run were not read; the order above held in every version read.

**Rejected: keep the last rule, as before.** Export order is not the order WordPress tries rules, so the entry could redirect somewhere the domain does not.

**Rejected: write every rule and let the host app choose.** The collection has one entry per host and source, and the importer finds an entry by that pair, so a second entry for a source would be matched by neither the re-run nor the host's lookup in a defined order.

**Rejected: refuse the run.** The shadowed rule never answers a request, so dropping it loses nothing the domain serves. The report keeps it visible.

## Remote CI runs every job on Windows as well as Linux (#186)

**Decided 2026-10-31, on #186.** Every job in `.github/workflows/ci.yml` but `CI` is a matrix over `ubuntu-latest` and `windows-latest`, with `fail-fast: false` so that both legs report. The whole workflow runs its steps in bash, because a Windows runner defaults to PowerShell. The ruleset does not change: it requires `CI`, and `CI` still needs each job once, because a matrix job's result covers all of its legs.

**Why.** Every remote job ran on Linux, so a Windows-only defect surfaced only when someone next worked on a Windows machine. Standard Windows runners are free on a public repository; the cost is a longer queue under the organization's concurrent-job limit.

**Rejected: a parallel Windows job per Linux job.** It doubles the file and the `needs:` list for check names no clearer than the matrix gives.

**Rejected: running a subset on Windows.** The timings that would justify narrowing it did not exist yet. Narrowing is a later decision.

**Not changed: whether the local chain gates a merge.**
