# History — cut a release when not tagging would block progress (with one standing exception)

The incident history, measurements and worked examples behind [`cutting-releases.md`](../rules/cutting-releases.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`cutting-releases.md`](../rules/cutting-releases.md); this file is read when a rule is questioned, not on every turn.

## Overview

**Why this is a standing order.** The consuming app pins this package by a **tagged caret range** (`"uams-web/wordpress-importer": "^0.20"` in `uams-statamic`'s `composer.json`, read 2026-09-21), not `dev-main`. So a batch of merged importer work is **unreachable by the app until a tag exists** — and a raw `dev-main` commit does not survive a force-push to `main`, which is the failure behind `UAMS-Web/uams-statamic#904` / `#905`. Anchoring the cut to "before the app adopts the work" ties the tag to the moment it actually matters and makes "cut the release" a checklist item someone owns rather than a thing everyone assumes someone else did.

## What counts as breaking

Declaring the surface locally keeps the check greppable and removes the cross-repo determination entirely. The declaration is *seeded* from measuring the app, rather than replacing that measurement with a guess.
