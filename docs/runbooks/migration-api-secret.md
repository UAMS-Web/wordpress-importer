# Runbook — the migration API credential

How to issue, place, verify, rotate and eventually remove the credential the importer uses to read the UAMS WordPress migration API.

**The file is still named `migration-api-secret.md`.** It predates the token, and the name is load-bearing in links from [`CLAUDE.md`](../../CLAUDE.md), `#702` and the tracker; renaming it would break those for a cosmetic gain.

**Read this before touching the secret, because the one thing that makes it hard is that every failure looks the same.** An unset secret, a wrong secret, a typo, and a correctly-rejected anonymous request all return exactly the same thing:

```json
{"code":"uamswp_migration_api_unauthorized","message":"Authentication required.","data":{"status":401}}
```

Nothing distinguishes *"you didn't send a key"* from *"this deployment has no key to send"*. That is not a defect — the API deliberately has no anonymous path, because it serves unpublished content and raw site settings — but it means a `401` tells you nothing about which half is wrong. The verification step below exists to make that distinguishable, and skipping it is how an afternoon disappears.

Measured on macOS, 2026-08-21, against `uamshealthmu.test`. The WordPress half of this is also documented in [`UAMS-Web/uamswp-migration-api`](https://github.com/UAMS-Web/uamswp-migration-api)'s own `CLAUDE.md`; this runbook owns the consumer half and the end-to-end procedure.

## Which credential, and which one this deployment uses

The API accepts **three** (`includes/Auth.php`):

1. A WordPress user holding the required capability on the requested site — an application password over HTTPS is the intended form.
2. A **shared secret** in the `UAMSWP_MIGRATION_API_SECRET` constant, presented in the `X-UAMSWP-Migration-Key` header.
3. A **per-network token** issued from the plugin's own admin page, presented in that **same header**.

**Use the token — it is what `#702` deploys.** `uamswp-migration-api-archive#140` chose it and `#141` built it: it is scoped to one network, revocable on its own, and carries no WordPress identity at all. The shared secret is a single value across every network with no per-consumer revocation, and an application password would grant `manage_network_options` — write-capable network administration — in order to perform a read.

**The constant is not removed, and that is why both are documented here.** `Auth` accepts either on the same header, so the pre-existing path keeps working. For this deployment the token replaces it.

**This package needs no change to accept either.** `MigrationApi\Client` presents `X-UAMSWP-Migration-Key` and nothing else, and both credentials travel in it — so whichever value a network was given is the value the operator pastes when a run starts. It is held in no configuration on this side: addressing several networks was closed that way by `#982`.

## Issue a token

**Settings → Migration API token.** Network admin on a multisite (`/wp-admin/network/settings.php?page=uamswp-migration-api-token`); under Settings on a single-site install such as `news.uams.edu`. It needs `manage_network_options` on a multisite and `manage_options` otherwise — the page reads the same expression the API does, so the two cannot drift.

Press **Generate token**.

**The token is displayed once, in the response to that press.** Only a SHA-256 hash is stored, so it is not recoverable: if it is lost, generate another. There is no `wp-config.php` edit and no second half to keep in step — the only place it is written is the consumer's own configuration.

**The page shows an identifier for the current token, not the token.** Across 26 networks that is what lets you tell which credential a consumer holds without holding it.

**Pointing a run at a given network — and confirming which one before it starts — is [*Taking the capture*](#taking-the-capture--one-run-one-network-two-values-pasted) below, not this section and not another document.** `wordpress-importer-archive#982` decided how, and what it decided is *neither* of the two options it was filed with: the address and the key are pasted together when a run starts, and neither is stored. That ticket also carries a **superseded** section recording an earlier decision for option A, reconfigure-per-run; read the live one.

Then place the host half — *Place it — both halves*, whose WordPress step does not apply — and verify with *Verify it — three requests, not one*.

## Generate the shared secret

Only for the pre-existing path; a token deployment skips this section and the next. Use something that is not a word:

```bash
openssl rand -hex 32
```

or, on the WordPress side, `wp_generate_password( 64, false )`.

**Generate it once and place it in both halves.** It is a single shared value, not a pair — the two must be byte-identical or every request is a `401`.

## Place it — both halves

### 1. WordPress

In `wp-config.php`, **above** the `/* That's all, stop editing! Happy publishing. */` line. WordPress requires constants to be defined before `wp-settings.php` loads, and below that line is too late:

```php
/* Migration API — read by uamswp-migration-api's Auth as the X-UAMSWP-Migration-Key
 * shared secret. Temporary: remove with the plugin when the migration completes. */
define( 'UAMSWP_MIGRATION_API_SECRET', '<generated value>' );
```

### 2. The host application — nothing to place

**The importer stores no credential and no address.** Decided on wordpress-importer-archive#982: an operator starting a run pastes the network's address and that network's key when the command asks, and neither survives the process. So there is no second half to place, no `.env` line to add, and nothing to keep in step with the WordPress side.

`UAMSWP_MIGRATION_API_URL` and `UAMSWP_MIGRATION_API_SECRET` still exist in `uams-statamic`'s committed `config/wordpress-import.php` and are read by nothing. Removing them is that repository's change, tracked as `UAMS-Web/uams-statamic-archive#2358`; setting them here does nothing.

The one value still read from configuration is the page size, which is neither a credential nor a network identity:

```env
UAMSWP_MIGRATION_API_PER_PAGE=100
```

It is optional and defaults to 100.

**The per-run procedure** is in [*Running the migration deployment*](#running-the-migration-deployment) below. The WordPress half alone is enough to make the endpoint reachable and verifiable, which is what the next section does.

## Verify it — three requests, not one

**Whichever credential was set, verify it the same way** — the key below is the token or the shared secret.

**One passing request is not verification.** A `200` proves a key was accepted; it does not prove the *right* key was required. Run all three:

```bash
API="https://uamshealthmu.test/wp-json/uamswp-migration/v1"

# 1. Correct key -> 200
curl -sS -o /dev/null -w '%{http_code}\n' -H "X-UAMSWP-Migration-Key: $SECRET" "$API/"

# 2. No header -> 401
curl -sS -o /dev/null -w '%{http_code}\n' "$API/"

# 3. WRONG key -> 401
curl -sS -o /dev/null -w '%{http_code}\n' -H "X-UAMSWP-Migration-Key: not-the-key" "$API/"
```

### If the first request fails on the certificate

Against a local Herd or Local-by-Flywheel host — `uamshealthmu.test` and anything else under `.test` — the certificate is self-signed and `curl` refuses it:

```
curl: (60) SSL certificate problem: self-signed certificate
```

`curl -k` (`--insecure`) skips that check and is fine **for a local host you control**. It is deliberately not in the commands above, because this document is meant to be copied:

> **Never carry `-k` to a real host.** Request 1 is the one request that carries the secret. Disabling certificate verification means nothing has authenticated the server you are handing it to, and anything in the middle of that connection gets the whole credential — which is the single failure this runbook cannot recover from, since there is no partial disclosure of a shared secret.

If you need it locally, add it at the moment you run it rather than pasting it into a script that later points somewhere else.

**The third is the load-bearing one.** It is what separates "the secret is compared" from "any header satisfies the guard". Without it, a guard that accepted any non-empty header would pass checks 1 and 2 and look perfect.

## Never

- **Never commit it.** Not to this repo, not to `uams-statamic`, not to the plugin repo.
- **Never put it in a ticket, a PR body, a commit message, or a chat log.** This rules out an agent being handed the value to place — see below for the way around that.
- **Never read it back with a command that prints it.** `grep -n UAMSWP_MIGRATION_API_SECRET wp-config.php` writes the credential into whatever is capturing that output, including an agent transcript. Use `grep -c` when you only need to know whether it is set:

  ```bash
  grep -c "UAMSWP_MIGRATION_API_SECRET" wp-config.php   # 0 or 1, no value
  ```

### How an agent can place it without seeing it

The value can be generated and written by a shell whose output nobody reads. It exists only in that process:

```bash
SECRET=$(openssl rand -hex 32)     # never echoed
# ...write $SECRET into wp-config.php and .env, print nothing
```

Reading it back for verification works the same way — extract it in-shell, use it in a header, print only the status code. This is how the value was placed for `wordpress-importer-archive#656` without it entering any transcript.

Take a backup of each file first. Refuse to overwrite an existing secret rather than clobbering a live credential.

## Running the migration deployment

`wordpress-importer-archive#668` decided the API is **deployed for the duration of the migration**, and closes when the WordPress sites are switched off. There is no expiry in the plugin — verified: nothing in `includes/` sets a sunset, an expiry or a self-deactivation — but the trigger does not need one, because once WordPress is gone there is no site left to serve the endpoint.

**Treat it as a standing endpoint, because that is what it is.** The obligations below are what a months-long deployment needs and a short window would not.

### The denylist gate — before the capture, and on a cadence after

The options endpoint serves the whole table minus a denylist, so **its failure direction is leaking a credential rather than dropping a setting.** Before the capture — which is after the secret is placed and the plugin activated, since this gate reads the endpoint — `GET /options` must report either a clean signal or an acknowledged one:

```json
"denylist": {"reviewed_against": 68, "current": 51, "stale": true,
             "added": ["wp-migrate-db-pro", "wp-all-import-pro", …]}
```

- **`stale: false`** — open.
- **`stale: true`** — a named person reads `added` and records that those plugins bring no credential-bearing option names. Then open.

**Do not treat `stale: true` as a stop on its own.** It fires on any plugin the review did not cover, including local-only tooling, and a gate that cries wolf is one people learn to skip — which is how the *first* denylist review's blind spot survived. Re-run `scripts/audit-option-denylist.php` when the diff is large or unfamiliar.

⚠️ **Re-check it periodically, not only at the start.** A short window could be gated once; a deployment that stands for the whole migration cannot. New plugins arrive over months, each bringing option names the review never saw, and `denylist.stale` drifts with them — silently, because the endpoint keeps serving. Re-read the block whenever the site's plugin set changes, and **rotate the secret** (below) if it has been live long enough to have been exposed.

**Why the acknowledgement is a real step and not a formality.** The first review covered the development install alone — 1,223 options, one site — and produced a list that **would have leaked credentials on production sites the review never examined**, because five of the plugins holding them were not installed where the review happened. The failure was structural rather than a few oversights: every pattern assumed `snake_case`, so hyphenated and camelCase names passed straight through, and **all eight** of the undenied names using those conventions were credential-bearing. Slug-level tracking also cannot see a plugin that introduces a new credential-bearing option *in a new version*; the value-**shape** rule is the only guard there, and it is what caught `acf_pro_license` before any name pattern did.

### Opening

**The gate comes after activation, and that is not a weakening.** `GET /options` needs the plugin active and the secret placed, so a gate defined as reading it cannot precede them — an earlier revision of this section ordered it first and could not be performed as written (`wordpress-importer-archive#986`). The gate protects the **capture**, not the endpoint's existence: the namespace is credential-guarded and refuses at the namespace level, so activating exposes nothing to anyone without the secret. What the gate prevents is *capturing* credential-bearing options, and it still runs before that.

1. Put the credential in place — *Issue a token* (or, for the pre-existing path, *Generate the shared secret* and *Place it — both halves*), then *Verify it — three requests, not one*.
2. Activate the plugin. It is **network-activated**, so this is one act per network, and it has no activation hook and writes no option.
3. Run the denylist gate above, and check **which plugins are active** — a capture reflects plugin state, not what the database holds (see `wordpress-importer-archive#588`).
4. Only then take the capture.

**`scripts/audit-option-denylist.php` is not the opening instrument.** It can produce the audit server-side without the endpoint, which makes it look like a way to keep the gate first — but the gate is defined as reading `GET /options`, and the script is documented above only as a re-run tool for when *"the diff is large or unfamiliar"*. Reaching for it to preserve the old order re-introduces the sequence this section was rewritten to fix.

### Taking the capture — one run, one network, two values pasted

Nothing about the network is configured, so every run names its own target (wordpress-importer-archive#982). From the host application:

```
php artisan statamic:import:wordpress --source=api --site=<blog id> <output dir>
```

It then asks for two things, in this order:

1. **The network's WordPress address**, with the scheme — `https://wp.example.edu`. It is refused unless it is an absolute `https://` URL carrying a host: a cleartext scheme would put the key on the wire, and a slash short (`https:/wp.example.edu`) parses as an address with no host at all.
2. **That network's key**, from *Issue a token* above. Input is hidden. Do not pass it as a command-line option — there is deliberately no flag for it, because one would put a live credential in shell history and in the process list.

**Confirm the network before the key goes in.** The command echoes the address back on its own line, before the key prompt:

```
  Reading: https://wp.example.edu
```

**Read that line, because it is the only thing in the run that names the network.** A `--site` id means nothing on its own — blog ids are unique only within a network, so `--site=7` is a different site on every one of them. The key narrows the damage but does not remove it: an address the key does not open returns `401`, and one that is simply not the network you meant, whose key you then paste, produces a completely ordinary run against the wrong content.

**Which network was read is recorded by the materialized parts, not by the console.** The run names the output directory it wrote; that directory is the evidence for what was captured, and it is worth keeping the address beside it.

### Closing — two acts, and the second is the one that gets forgotten

```
deactivate the plugin   ->  GET /…/sites returns 404 (verified: 200 active -> 404 -> 200 reactivated)
remove the constant     ->  see "Remove it" below -- CONSTANT DEPLOYMENTS ONLY
```

**The second act belongs to the constant, and a token deployment has no equivalent.** A token lives in a hashed option that the plugin's removal takes with it; there is no `wp-config.php` edit to undo. **So record which credential each network was given** — the close differs, and a network closed as though it held a constant is not thereby wrong, while one closed as though it held a token can leave a live constant behind.

**Deactivation alone does not close a constant deployment.** While the plugin is off nothing reads `UAMSWP_MIGRATION_API_SECRET`, so it is inert — but it is still in `wp-config.php`, and **any later reactivation silently restores working access under the old value**. On a network where plugin state can move with a deploy rather than by a person, that is a window reopening without anyone deciding to open it.

### What closes it

**The WordPress sites being switched off, at the end of the migration.** That is terminal, externally observable, and **self-enforcing** — once WordPress is gone there is no site left to serve the endpoint, so this close cannot be forgotten in the way a date or an owner can be.

**Still do both acts.** Switching the site off makes deactivation moot, but the constant is what a restored backup would bring back, so remove it rather than relying on the site staying down.

**Rejected — a fixed date with a named owner who may extend once.** An earlier revision chose this, on the grounds that it fails safe when nobody acts. It is the right answer for a genuine window and the wrong one here: the migration's length is not known in advance, so the date would be re-set repeatedly, and a trigger that is extended on every review teaches everyone to ignore it.

**Rejected — "when the capture is complete."** *Complete* keeps moving; `wordpress-importer-archive#588` and `#589` each turned up gaps needing another pull. The WordPress shutdown is a different kind of event, which is why it works where this does not.

## Rotate it

**The two credentials rotate differently, and the difference is the whole reason this section is split.** Reading the shared secret's procedure and applying it to a token plans an outage the token was specifically designed not to have.

### A token — regenerate, with an hour's overlap

**There is a grace period, and it is deliberate.** Press **Generate token** again. The previous token keeps working for `Token::ROTATION_WINDOW` seconds — **3600** — and the page shows the previous identifier and when it stops being accepted.

1. Regenerate on the network's admin page.
2. Update the consumer at any point before the window closes.
3. Re-run all three verification requests.

**So a rotation is not a synchronized edit.** `uamswp-migration-api-archive#141`'s own reasoning is that regenerating without an overlap reproduces, at n=1, the defect `#140` rejected the shared constant for: every request refused between regeneration and the new value reaching the consumer, surfacing mid-capture rather than at setup.

The filter `uamswp_migration_api_token_rotation_window` changes the duration; zero removes the overlap and restores exactly that failure, so change it only deliberately.

### The shared secret — no grace period

There is none: it is one shared value, so **both halves must change together**, and any request in flight during the change fails with a `401`.

1. Generate a new value.
2. Replace it in `wp-config.php` and in the host `.env`.
3. Re-run all three verification requests.

### Either credential

Rotate whenever the value may have been exposed — including if it was ever printed by a command whose output was captured. A token cannot be read back to check, only replaced, which makes regenerating the cheap answer to any doubt.

## Remove it

The plugin's own header reads *"Temporary — remove when the migration completes."* When it goes:

1. Deactivate and remove the plugin. On a token deployment this also takes the stored hash, since it lives in an option the plugin owns.
2. Delete the `define()` from `wp-config.php` — **constant deployments only**; a token never wrote one.
3. **Nothing to remove on the host side.** The importer holds no address and no credential — both are pasted when a run starts and neither survives the process (`wordpress-importer-archive#982`). The only key it still reads is `migration_api.per_page`, which is a page size rather than a secret and can stay.

## Troubleshooting

| Symptom | Likely cause |
| --- | --- |
| Every request `401`, including with a key | On a constant deployment: it is not defined, or the two halves differ. Check with `grep -c` on both, then rotate rather than hunting for a typo. On a token deployment: regenerate rather than investigating — the stored value is a hash and cannot be compared against what you hold |
| Every request `401` shortly after a rotation | On a token, the overlap has closed: `Token::ROTATION_WINDOW` is 3600 seconds, and the admin page showed when the previous one stopped being accepted. Regenerate and update the consumer |
| `401` from one network with a key that works on another | Expected. A token is scoped to the network that issued it, so a run pointing at the wrong `base_url` is refused rather than succeeding against real content. Confirm which network the run targets — `wordpress-importer-archive#982` |
| `401` only from the importer, while `curl` with the same key succeeds | What was pasted at the prompt is not what `curl` sent — a truncated paste, a trailing newline, or the key for a different network. Nothing is read from `.env` or `config/wordpress-import.php`, so neither is worth checking |
| The importer refuses before any request, naming the address | Not an auth problem. `Client::forRun()` requires an absolute `https://` URL carrying a host: `https:example.test` and `https:/example.test` parse with no host, and a cleartext scheme is refused rather than putting the key on the wire |
| The importer refuses before any request, naming the key | Nothing was pasted. An empty key is refused rather than sent, because the API answers `401` to a missing header and that would report the slip as an authentication failure against a network that is fine |
| `400 uamswp_migration_api_registration_is_not_site_scoped` | Not an auth problem. Discovery endpoints cannot be read for another site — see wordpress-importer-archive#671 |

## References

- `wordpress-importer-archive#656` — where the secret was first placed and verified.
- `wordpress-importer-archive#997` — this change; `#702` is the deployment it serves.
- `wordpress-importer-archive#982` — addressing several networks from one client, and the per-run procedure that will join this document.
- `UAMS-Web/uamswp-migration-api-archive#140` / `#141` — the credential decision and the token build.
- `wordpress-importer-archive#671` — site-scoped discovery, the other thing the credential surfaced.
- [`docs/technical-reference.md`](../technical-reference.md) — Configuration → Migration API, the config keys themselves.
- `UAMS-Web/uamswp-migration-api` — `includes/Auth.php`, and its own `CLAUDE.md` for the WordPress half.
