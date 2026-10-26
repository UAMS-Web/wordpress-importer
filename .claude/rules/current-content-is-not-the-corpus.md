# Rule — the host app's current content is not the corpus

When a question is "does this data shape exist?", **a search of `uams-statamic`'s current `content/` answers a narrower question than the one you asked.** It tells you what is on disk *right now*. It does not tell you what **future imports will create**, what **editors will author in the Control Panel**, or what **any other write path can still produce**. This package exists to *add* content to that host app — so "I grepped the host content and found zero" is evidence about the past, and the decision you are making is almost always about the future.

Before concluding a data shape is absent, dead, or safe to stop handling, enumerate **every writer** that can produce it — the importer's own write paths, the CP/blueprint surface, and any host-app listener or command — and say which of them you actually checked.

**The same error has a sibling in config.** Minutes later, grepping `resources/blueprints/` for the literal handle `featured_image_credit_query` found it on 5 blueprints, prompting "so `posts` and `clinical_resources` don't have it — the issue is wrong." They do: the field arrives through `import: uamsweb_post_cover_image`, reaching **15** blueprints. Statamic fieldset imports mean the grepped surface is not the effective surface. Treat both as one discipline: **the thing you searched is not the thing you concluded about.**

History: [current-content-is-not-the-corpus.md § Overview](../history/current-content-is-not-the-corpus.md#overview).

## How to apply

1. **State the question as present-tense or future-tense, and notice which one you're answering.** "Does legacy data needing migration exist?" is answerable by grepping current content. "Can this shape still arrive?" is not — that one is answered by reading write paths. Most fork decisions in this repo are the second kind wearing the first kind's clothes.

2. **Enumerate the writers before concluding absence.** For any handle or data shape, there are up to three producers, and they fail independently:

   | Writer | Where to look | Typical miss |
   | --- | --- | --- |
   | This importer | `src/…/Entries/`, `src/…/Parsers/`, `src/…/Globals/` — `grep -rn '<handle>' src/` | a second creator writes the same handle differently |
   | The Control Panel | `resources/blueprints/**`, `resources/fieldsets/**` in `uams-statamic` — a field an editor can type into | the field is editable and nothing normalizes it on save |
   | Host-app code | `app/Listeners/`, `app/Console/`, `app/Services/` — `EntrySaving`/`EntrySaved` hooks, commands | assumed a save-time transform exists because a field's *instructions* promise it |

   A zero-result content grep plus **all three** writers checked is a conclusion. A zero-result content grep alone is a lead.

3. **Never take a field's `instructions:` as a description of behavior.** In the #5 case the CP field told editors "the password is hashed and stored encrypted… it will appear as a long string of dots" while no code hashed anything. Blueprint instructions are editor-facing prose, not a contract — verify the claim in `app/`.

4. **Resolve blueprint fields through their fieldset imports, not by grepping the handle.** A handle can reach a blueprint via `import: <fieldset>` or `field: <fieldset>.<handle>` without the literal string ever appearing in that blueprint file. To get the real set: grep the **fieldset** name across `resources/blueprints/`, and grep the handle inside `resources/fieldsets/`. The inline hits are the blueprints that *override* it — usually the minority.

5. **Say which corpus you checked, in the finding itself.** Write "zero in current host content; the importer always co-writes the flag; the CP path does not" — not "zero entries affected." The scoped claim survives review; the unscoped one gets acted on and inverts.

6. **When the answer changes the decision and a writer can't be checked from here, name it as an open item** rather than assuming. Live WordPress sites, other Statamic environments, and content not yet imported are all outside this checkout — the corpus in `UAMS-Web/wordpress-importer-exports` is a *sample* of what the importer will see, not its bound.

## The DRY line

This file is the standing statement on **scoping an absence claim** — *which* corpus was searched. Whether the search worked at all is the prior question, and belongs to [`an-empty-result-is-not-evidence`](an-empty-result-is-not-evidence.md): a probe can be pointed at exactly the right corpus and still be incapable of matching anything in it. It composes with [`adversarial-review`](adversarial-review.md) — that rule demands findings be verified against the code rather than speculated; this one names the specific way a *verified* search still yields a wrong conclusion — and with [`design-decision-forks`](design-decision-forks.md), whose "verified mechanics the tables rest on" step is where this discipline actually gets paid. The importer's own write paths are documented in [`docs/technical-reference.md`](../../docs/technical-reference.md); the host-app checkout and how to run against it belong to the [`host-app-tests`](../skills/host-app-tests/SKILL.md) skill.
