# The UAMS migration API contract

What the importer can ask the migration API for, what comes back, and the places where a request succeeds while returning less than you asked for.

Every statement here was **verified against a running install** (`uamshealthmu.test`, a WordPress multisite, 2026-08-21) rather than transcribed from the plugin source, **except where a statement says it was read from source**. Where a behavior differs from what the route definition implies, this document says so — those are the paragraphs worth reading twice.

The plugin lives in [`UAMS-Web/uamswp-migration-api`](https://github.com/UAMS-Web/uamswp-migration-api). Setting up the credential is the [migration API secret runbook](runbooks/migration-api-secret.md).

## Authentication

Two credentials are accepted:

1. A WordPress user holding the required capability on the site being read — an application password over HTTPS is the intended form.
2. A **shared secret** in `UAMSWP_MIGRATION_API_SECRET`, or a **per-network token** issued from the plugin's own admin page, either presented in the `X-UAMSWP-Migration-Key` header. The two share that header, so which one a network uses is invisible to the caller.

`MigrationApi\Client` presents whichever value the operator pastes when a run starts. It does not read `UAMSWP_MIGRATION_API_SECRET`: that constant is defined on the WordPress side and is the API's own credential, not this package's configuration (wordpress-importer-archive#982).

There is deliberately no anonymous path: the API serves unpublished content and raw site settings.

**Every authentication failure is the same response.** An unset constant, a mismatched value, a typo and a correctly-refused anonymous request all return:

```json
{"code":"uamswp_migration_api_unauthorized","message":"Authentication required.","data":{"status":401}}
```

A misconfigured install is indistinguishable from a working one you forgot to send a key to. The runbook's three-request check exists for that reason.

## Addressing a site

The namespace is `uamswp-migration/v1`, served from **every site's own REST root** on a network:

```
https://example.test/wp-json/uamswp-migration/v1/…            ← site 1
https://example.test/scoping-two/wp-json/uamswp-migration/v1/… ← site 2
```

`GET /sites` reports each site's root in its `link`.

### `?site=` is declared on every route and refused by two of them

This is the trap that produced wordpress-importer-archive#671. The route index advertises a `site` parameter on **every** endpoint, including the two that reject it:

```
site: "Blog id to read, for network installs. Defaults to the site handling the request."
```

But `post-types` and `taxonomies` answer:

```
400 uamswp_migration_api_registration_is_not_site_scoped
```

The refusal is correct — both derive from `post_type_exists()`, and post-type registration is process-global, so they can only answer for the site that booted the request. The route definition simply cannot express that.

**For data endpoints the two forms are equivalent**, measured:

| | site 1 root | `?site=2` | site 2's own root |
| --- | --- | --- | --- |
| `posts/post` | 46 | 1 | 1 |
| `options` (rows) | 1206 | 235 | 235 |

So the importer addresses a site's own root uniformly and never sends `site` — correct for both classes, and immune to a future endpoint joining the refusing set.

### `?site=` is network-scoped and cannot address across installs

**A blog id is unique only within its own network.** The parameter's own description says so — *"for network installs"* — but it reads as a global site selector, and it is not one. Blog `2` on one network and blog `2` on another are unrelated sites, and nothing in the request distinguishes them beyond the address it was sent to.

This matters because the estate is **not one multisite install**. The production sites are spread across several separate networks (confirmed 2026-09-08, recorded on #702), so there is no value of `site` that reaches all of them and no single run that can walk the estate. **A network is selected by the address, and only by the address.**

The credential does not rescue this, though it narrows it. Each network issues its own key, stored per network by the plugin, so a key from one network authenticates against no other: an address and key that disagree return `401` rather than the wrong content. What that does not catch is an address the operator did not intend whose matching key they then supply — that run succeeds and imports a real network's real content, correctly, from the wrong place.

Which is why the address and the key are supplied together at the start of a run rather than configured (wordpress-importer-archive#982, and [`DECISIONS.md`](DECISIONS.md)). Two values chosen in one act cannot drift apart; two values living in separate places can.

## Routes

Verified from the API's own index. `page`/`per_page` appear only where the endpoint paginates.

| Route | Parameters |
| --- | --- |
| `/` | — |
| `/sites` | `site` |
| `/post-types` | `site` *(declared, refused — see above)* |
| `/taxonomies` | `site` *(declared, refused)* |
| `/authors` | `page`, `per_page`, `site` |
| `/posts/<type>` | `include`, `modified_after`, `page`, `per_page`, `site`, `status` |
| `/posts/<type>/<id>` | `site` |
| `/terms/<taxonomy>` | `page`, `per_page`, `site` |
| `/terms/<taxonomy>/<id>` | `site` |
| `/options` | `site` — **not paginated** |
| `/forms/gravity`, `/forms/formidable`, `/forms/ninja` | `page`, `per_page`, `site` |
| `/forms/{plugin}/<id>` | `site` |
| `/forms/gravity/feeds` | `page`, `per_page`, `site` *(read from source; see below)* |
| `/forms/gravity/<id>/feeds` | `site` *(read from source)* |

## Pagination

`page` is 1-based (default 1). `per_page` defaults to **50** and is capped at **200**; asking for more is clamped server-side rather than refused. **A `page` or `per_page` that is not an integer, or is below 1, is refused with `400 rest_invalid_param`** — the API's D-084, merged in its wordpress-importer-archive#302. Before that change it was rewritten and answered `200`: `page=-3` was served as page 3. The client refuses such a `per_page` in configuration before sending it (wordpress-importer-archive#1213). **The cap is deployment configuration, not a constant** — `Config::int('per_page_max', 200)` — and the index endpoint advertises the live value as `per_page_max`. Read it there rather than hard-coding 200. There is no rate limit.

Paginated responses carry `X-WP-Total` and `X-WP-TotalPages`. `MigrationApi\Client::paginate()` walks until the records seen reach `X-WP-Total`, and **raises if it cannot** — a short read is never returned as a complete set.

**`/options` is not paginated and returns no total.** It serves the entire table in one response — 864.9 KB for the site measured. Read it with `get()`, not `paginate()`.

## Query semantics on `/posts/<type>`

`status[]` and `include[]` take the bracketed array form, and both work:

| Request | Records |
| --- | --- |
| no filter | 46 |
| `status[]=publish` | 1 |
| `status[]=draft` | 45 |
| `status[]=publish&status[]=draft` | 46 |
| `include[]=1&include[]=2` | 0 (no such ids) |

With no `status`, **every** status is served — that is the point of a migration source, and it is why the unfiltered count is 46 rather than the 1 a public API would return.

### `modified_after` — fixed upstream, and worth knowing why it was wrong

`modified_after` restricts to records modified after a timestamp. **It works correctly**, verified 2026-08-21:

| Request | Records |
| --- | --- |
| no filter | 46 |
| `modified_after=2000-01-01T00:00:00` | **46** |
| `modified_after=2000-01-01T00:00:00` + `status[]=draft` | **45** |

**It did not, until recently.** The filter compared `post_modified_gmt`, which WordPress leaves **zeroed** for posts that were never published while keeping a real value in the local `post_modified`. Every draft therefore compared as older than any cutoff, and the same three requests returned 46 / **1** / **0** — an incremental re-pull that quietly became publish-only, on a site where 45 of 46 records were drafts.

**Accepted formats**, verified — anything else is a `400`:

| Shape | Example |
| --- | --- |
| `YYYY-MM-DD` | `2000-01-01` |
| `YYYY-MM-DD HH:MM[:SS]` (GMT) | `2000-01-01 00:00` · `2000-01-01 00:00:00` |
| ISO 8601 `YYYY-MM-DDTHH:MM[:SS][.fff][Z\|±HH:MM]` | `2026-08-01T12:34Z` · `2026-08-01T12:00:00.000Z` |

**Seconds are optional and fractional seconds are accepted** — verified. Worth stating because a reader who saw only a `HH:MM:SS` example would reasonably pad a minute-precision timestamp, and a caller holding a millisecond timestamp from elsewhere would reasonably truncate it. Neither is necessary.

Rejected, all returning `400 rest_invalid_param`: `now`, `tomorrow`, `+1 week`, `Thursday`, `@1700000000`, `12:00:00` — and **`2026`**, which deserves its own mention. It is a valid ISO 8601 reduced-precision year, and PHP used to read it as **the clock time 20:26 today**. An operator writing `--modified-after=2026` to mean "since 2026" got "since 20:26 this evening" and a nearly empty pull that reported success. It is now refused.

A malformed value used to be **silently ignored**, so a typo pulled everything. It now errors, which is the safer direction but means an operator typo that appeared to work will now stop the run.

Recorded here rather than deleted because the shape recurs: a comparison working exactly as written, against a column that does not hold what the name suggests. Fixed in `uamswp-migration-api` (their "Stop `modified_after` silently dropping records"); this repo's #675 tracked the consumer side.

### `file[]` on `/posts/attachment`, and what a scoped run resolves through it

`file[]` selects attachments by the file they own, and is the upstream half of #1175 (`uamswp-migration-api` #300, its decision D-085). Read D-085 for the contract; the parts a consumer has to act on:

- **It takes uploads-relative paths**, the form `_wp_attached_file` stores: `2020/09/photo.png`. The server resolves a generated size to its attachment from the record's own size list, so `2020/09/photo-750x629.png` selects `photo.png`'s record. A size a record no longer lists is not matched.
- **Paths are compared as stored and are not URL-decoded**, so a `src` is decoded before it is sent.
- **An unmatched path is omitted with a `200`**, and a request whose `file` and `include` both resolve to nothing serves nothing.
- **`file` and `include` are a union**, and at most **100** paths go per request; a 101st answers `400`.
- **Only `/posts/attachment` accepts it.** Any other type answers `400 uamswp_migration_api_file_not_attachment`.

**A run given `--content-type` reads attachments by reference and falls back to the whole collection when that cannot be shown safe.** `WxrMaterializer` reads every other type first, collects references from the requested type and its content companions (`AttachmentReferenceCollector`), and asks for them in chunks of 100 by `include[]` and `file[]`. It then reads every attachment instead, recording why in the manifest's `scope.attachments`, when any of these holds:

| Condition | Why it forces the full read |
| --- | --- |
| A same-site upload image URL resolves to no record read, by `XpathHelper::findAttachmentByUrl()` | The image pipeline prefers a `src` match over the class id, and falls back to a basename search across every attachment. An image the narrowed set cannot resolve could resolve in a full read. |
| A same-site permalink matches no item read, attachments included, by its link or by the slug its last segment names | An attachment's own page is a permalink like a page's, and cannot be told apart without the attachment items. The slug half follows `LinkProcessor`'s structure match, which is how a link to a draft resolves when the draft's `<link>` is `?p=N`. |
| The reference requests would cost as many as reading every attachment | Nothing is lost by reading them all, so the full read is chosen. The inventory in `counts.by_post_type` is used for the estimate. |

Each request is bounded by count and by encoded size: a path is encoded into the query string at up to three times its length, and a hundred long paths measure over 8 KB, past Apache's and nginx's default request-line limits, which would answer `414` and abort the run.

A named reference whose attachment the site does not serve is reported as `unserved_attachment_reference`, and a fallback caused by an unresolved reference as `attachment_read_widened`, both under the `migration_api` warnings. An unscoped run reads every attachment, as before, and records no `attachments` block.

**The reference shapes.** Enumerated by reading every place in `src/` that selects an `attachment` item: the id, URL and permalink lookups in `XpathHelper`, `DisabledUploadHandlingTrait`, `KbEntryCreator` and `LinkProcessor`, traced back to the field that feeds each one. Measured against the code at `582bddb` on 2026-09-25, not against the exports, with `AdvertEntryCreator` (wordpress-importer-archive#1216) added when the branch was synced after it. **A creator added later that reads an id from a new meta key still has its ids fetched, since every integer in every meta value is a candidate, but it is not reported when unserved until the key is named here.**

| Shape | Read by | How the collector takes it |
| --- | --- | --- |
| `_thumbnail_id` meta | `FieldMapper`, the post, people and KB creators, `FeaturedImageCreditResolver`, `WpPostFeaturedImageBlockProcessor` | named meta key |
| `page_cover_image`, `page_landing_page_cover_image(_mobile)`, `logo_image` meta | `FieldMapper`, `LogoListItemEntryCreator` | named meta key |
| Page hero and modules image meta, `page_hero_hero_N_hero_image_*` and `uamswp_modules_N_*image*` | `FieldMapper`, `PageEntryCreator`, `ModulesParser` | named meta key pattern |
| `_mkb_article_attachments`, a serialized id list | `KbEntryCreator::getArticleAttachments()` | named meta key |
| `_adverts_attachments_order`, a JSON id list | `AdvertEntryCreator` | named meta key |
| Any other ACF image or gallery field, in meta or in an ACF block's `data` | the page-builder parsers and `AcfGalleryBlockProcessor` | every integer in every meta value and block attribute, as an unnamed candidate |
| core/image `id`, core/gallery `ids`, an `<img>`'s `data-id` | `TextProcessor`, `WpImageBlockProcessor`, `WpGalleryBlockProcessor` | named |
| `wp-image-N` class | `HtmlParser`, which overrides a block's `id` with it | named |
| `[gallery ids="…"]`, `[caption id="attachment_N"]` | `GalleryShortcodeProcessor`, `CaptionShortcodeProcessor` | named |
| `?attachment_id=N` link, the permalink WordPress gives an attachment without a pretty one | `LinkProcessor`, by exact `<link>` match | named |
| An `<img src>` on the site's uploads | `AssetImporterProcessor`, by URL and then by basename | `file[]`, and the full-read check above |
| A link to an attachment's page | `LinkProcessor`, by `<link>` text and by slug structure | the full-read check above |

URLs and links are read from content, excerpts and **public** meta keys, where ACF keeps its values, plus `_redirect_rule_to`. Any other key with a leading underscore is a plugin's own record, such as an SEO plugin's canonical URL and social image, which nothing imports; it is still read for integers. A link straight to an upload file needs no attachment at all: `LinkProcessor` imports it by URL. **Nothing looks an attachment up by `post_parent`**, and no option or navigation path reaches one; a navigation item pointing at an attachment is dropped before any lookup.

**What this does not cover.** Unnamed candidates are fetched but never reported, so an unserved one is silent, as it is on an unscoped run. The permalink check approximates `LinkProcessor`: it normalizes where the importer matches `<link>` text exactly, it counts a slug match against an **unpublished** item as resolved without checking the parent path or whether an attachment shares that slug, and it cannot foresee a link that resolves only after the importer follows a live HTTP redirect. Published items' slugs are not counted, because `LinkProcessor` discards published candidates before matching by slug, and a post may share a slug with an attachment since WordPress checks a post's slug only against posts.

Three differences from an unscoped run are known and accepted. `AssetImporterProcessor`'s last fallback matches any attachment whose file name is a substring of an image's, for any same-site `src` including a theme's own images; the collector checks only upload URLs, so a full read can attach such an image to an unrelated attachment where a scoped run imports the URL itself. Where two records normalize to the same URL, a file literally named `hero-1920x1080.jpg` beside `hero.jpg`, `findAttachmentByUrl()` takes the first in read order, which a narrowed read can change. And a scoped run writes its attachments after every other type, so where a post and an attachment share a permalink `LinkProcessor`'s exact match, which takes the first item across parts, can resolve to the other one.

**Expect a scoped run to widen often on content-heavy types.** An image whose record stores a `-scaled` original never resolves through `findAttachmentByUrl()`, in either run, so any such `src` sends a scoped run to the full read. Measured over the WXR corpus with a mirror of these rules, which stands in for what the API serves rather than a run against it, the narrowed read holds on 99 of 154 sites for `post`, 134 of 254 for `page`, 7 of 24 for `kb`, 99 of 113 for `uamspeople` and 7 of 8 for `logo-list-item`. The first path segments it skips, `author`, `category`, `feed`, `page`, `tag`, `wp-admin`, `wp-content`, `wp-includes`, `wp-json` and `wp-login.php`, are assumed never to begin an attachment's page, as is the site's home.

## Payload shapes

### Posts

Core fields (`id`, `type`, `slug`, `status`, `title`, `content`, `excerpt`, `parent`, `menu_order`, `guid`, `link`, the four date fields, `ping_status`, `comment_status`, `post_password`, `is_sticky`), plus:

- **`terms`** — grouped **by taxonomy**: `{"category": [{term_id, slug, name, parent, description}], …}`.
- **`meta`** — the flat postmeta shape, each key mapping to a **list** of values, because `get_post_meta()` without a key returns every row.
- **`acf`** — the structured ACF view.
- **`attachment`** — on attachments: `url`, `mime_type`, `file`, `alt`, `metadata`.

**`meta` is the WXR-equivalent shape and `acf` is the structured one.** Existing importers read `meta` in the flattened form they already parse. For `location` and `provider`, `meta` is **stale** — Find-a-Doc routes ACF writes to custom tables and bypasses core postmeta, so those rows are frozen at whatever they held when that filter was first enabled. Read `acf` for those two post types.

### Sites

`id`, `title`, `link`, `description`, `language`, `wxr_version`, `base_site_url`, `base_blog_url`, `archived`, `deleted`, `spam`, and `counts`.

⚠️ **`counts.by_post_type` is a table inventory, not a fetch plan.** It reports raw `wp_posts` rows, so it lists types this deployment does not serve (`revision`, `acf-field`) *and* types present in the table but **not registered in the running process** — on the install measured, 28 `kb` and 6 `uamspeople` rows that `GET /posts/kb` answers with a `404`. That is deliberate and recorded upstream as **D-036**; the registration signal lives on `/post-types`, not here.

The three post figures differ on purpose: `rows` is the whole table bar `auto-draft`, `posts` counts only the configured types, `by_post_type` is the breakdown. `terms`, `comments` and `options` are single whole-table figures.

### Discovery — `/post-types` and `/taxonomies`

Both return every registered object with an **`exposed`** flag, which is the answer to "will this endpoint serve it?" On the install measured: **15 of 36** post types and **22 of 47** taxonomies exposed.

`/taxonomies` entries also carry `object_types` (the post types the taxonomy is attached to) and `terms` (a count). Together those answer a question neither endpoint states directly — *which taxonomies are attached to something I can fetch, but are not themselves served* — which is where term assignments go missing without any error.

**An unexposed taxonomy 404s rather than returning an empty list.** Verified: `/terms/educationtype` → `404`, `/terms/region` → `200` with `X-WP-Total: 2`. Worth relying on, because a `200 []` would be indistinguishable from a taxonomy that genuinely has no terms.

### Authors

`id`, `login`, `email`, `display_name`, `first_name`, `last_name`, `nicename` — the full WXR author block.

### Forms

One route per plugin, each `404`-ing with `uamswp_migration_api_forms_plugin_not_active` when that plugin is inactive on the site. **The three payloads are not the same kind of thing**, and that difference is the whole cost of adopting them (#695):

| Route | Shape |
| --- | --- |
| `/forms/gravity` | **`GFAPI::get_form()` output — the same native form array the hand-made export file contains.** Only the envelope differs: the export wraps it as `{"0":{form},…,"version"}`. |
| `/forms/formidable` | A **bespoke JSON** shape — `id`, `form_key`, `name`, `description`, `parent_form_id`, `status`, `is_template`, `default_template`, `logged_in`, `editable`, `created_at`, `options`, **`fields`**, `actions`. The hand-made export is **XML**, so this is a real translation. |
| `/forms/ninja` | `id`, `key`, `title`, `settings`, `fields`, `actions`. |

**`fields` is the member the Formidable endpoint exists for, and this table omitted it until wordpress-importer-archive#1095.** Measured on `communications.uams.edu/web` on 2026-09-15: 49 of the 50 forms on page one carried it, and all 56 forms across both pages held 794 fields between them. Every field in that site's XML export had a counterpart in the JSON (wordpress-importer-archive#808). A reader sizing the Formidable work from the old line would have planned to take form metadata from the API and fields from the export file, which keeps the file in the loop exactly where it is worse.

Each field record carries `id`, `field_key`, `name`, `description`, `type`, `default_value`, `field_order`, `required`, `form_id`, `created_at`, `options` and `field_options`. That list is **read from source** — `includes/Content/FormidablePayload.php` on `uamswp-migration-api`'s `main` at `dfb2568` — not from a response.

**`field_options` is a structure in the payload and a string in the export, and that is the one thing a consumer must handle differently.** The API hands the column over as Formidable's model returns it, already decoded. The XML export holds it as a serialized JSON string, escaped twice (wordpress-importer-archive#808). A mapper written against the export file decodes a string that the API never sends. Form-level `options` is decoded the same way.

**A parent form's `fields` does not include its children's.** A repeating section or an embedded form is its own form row, with `parent_form_id` pointing back at the parent. The endpoint serves each child as a separate record with its own fields, and it narrows Formidable's `$inc_repeat` default so the parent's list does not absorb them too. Rebuild a repeater from `parent_form_id`, not from the parent's field list. Also read from source.

**`actions` — the form's email notifications and on-submit confirmations — is newer than the 2026-09-15 measurement, and was absent from the live response then.** The API added it afterwards (its `D-081`), and it is documented here from source: each record carries `id`, `type`, `title`, `name`, `status`, `created_at`, `form_id`, `settings` and `denied`. Settings that look like credentials or recipients are withheld, and `denied` counts what was withheld. Whether a given install serves the member depends on which release of the plugin it runs, so check a response before relying on it.

**How each form shape was established:** `/forms/formidable`'s `fields` from the 2026-09-15 live response above, with its members and the field record read from source. `/forms/ninja` was read from a live response by wordpress-importer-archive#808, and it agrees with the source. `/forms/gravity` names no members, only that the controller returns `GFAPI::get_form()` unchanged. That is confirmed in source, and it is the premise the importer's `--source=api` Gravity path was built on (wordpress-importer-archive#809). A member list would be needed before a live response could show an omission in it.

**`/forms/gravity/feeds` and `/forms/gravity/<id>/feeds` are also read from source, not from the API's index.** They serve a form's add-on feeds through `GFAPI::get_feeds()`, disabled feeds included. Feed settings the plugin marks as encrypted, or that look like credentials, are withheld.

`/forms/ninja` shipped on `uamswp-migration-api`'s `main` in that repo's PR `#76`, merged 2026-08-24, closing its `#56`. `includes/Content/NinjaPayload.php` and `tests/Feature/Forms/NinjaEndpointTest.php` are on `main`. All three form plugins now have an endpoint, which is what `#695`'s adoption work was waiting on.

**`/forms/ninja` supplies the one thing a `.nff` cannot: an `id`.** `.nff` files are JSON despite the extension, carry one form per file, and have no id at any level — while post content references forms by numeric id (`[ninja_form id=3]`). That mismatch is why Ninja Forms alone drives a per-form-ID operator prompt (#10), and serving `id` is what retires it.

### Comments — not served, and not consumed

There is no `/comments` route and no comment payload; `PostPayload` carries `comment_status` (the open/closed flag) and nothing else.

**This is correct on both sides rather than a gap.** The importer ignores them by design — [`KeyTracker`](../src/Services/WordPressImport/Utilities/KeyTracker.php) lists `wp:comment` under `// Ignored` — and the `disable-comments` plugin is active on the WordPress network. Do not read the comments block of a WXR export as something parity requires; 44 of 259 captured exports carry `<wp:comment_id>` elements that nothing on either side reads.

### Terms

`id`, `taxonomy`, `name`, `slug`, `description`, `parent`, `parent_slug`, `term_taxonomy_id`, `count`, `link`, `meta`, `acf`.

### Options

`{options, acf, denylist, denied}`. `options` is the whole table minus a reviewed denylist; `denied` reports **how many** rows were withheld, `by_name` and `by_shape` — never which.

Two properties matter for consumers:

- **A value is served as stored.** A JSON string is a value rather than an encoding, so it goes out as the string it is and the consumer decodes it. `minerva-kb-options` is the example this importer reads.
- **A single credential-shaped key withholds the whole row**, not just that key. So "settings arrived empty" should send you to the denylist, not to a suspicion that the API is broken.

⚠️ **The filter is a denylist, so its failure direction is leaking rather than dropping.** Anything captured from `/options` and committed to a repository needs review first: on the install measured, at least one credential-bearing core option is **served** rather than withheld (it happened to hold a default placeholder, but a configured site would not). Check `/options` output for credential-bearing names before committing it.

## Error responses

Observed, with the status each carries:

| Code | Status | Meaning |
| --- | --- | --- |
| `uamswp_migration_api_unauthorized` | 401 | No credential, or the wrong one — indistinguishable from each other |
| `uamswp_migration_api_registration_is_not_site_scoped` | 400 | `?site=` on `post-types` or `taxonomies`; use that site's own root |
| `uamswp_migration_api_unknown_post_type` | 404 | The type is not served here |

`MigrationApi\Client` surfaces the status, the code, **and the API's own message** — including the per-parameter guidance WordPress puts in `data.params`, which is where the actionable sentence lives. That matters because this API writes messages containing the fix: `uamswp_migration_api_registration_is_not_site_scoped` says to address the site's own REST root, and that sentence resolved wordpress-importer-archive#671 without anyone reading the plugin source.

## References

- #358 — the epic, and why the API exists at all.
- wordpress-importer-archive#587 — WXR materialization. wordpress-importer-archive#671 — per-site addressing. wordpress-importer-archive#656 — standing the API up.
- [`docs/technical-reference.md`](technical-reference.md) — Migration API Classes, and the config keys.
- [`docs/runbooks/migration-api-secret.md`](runbooks/migration-api-secret.md) — the credential.
