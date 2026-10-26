# Rule — ask the adversarial-review questions about this importer's own failure paths

[`adversarial-review`](adversarial-review.md) asks every review of a diff the same error-handling questions: broad catches, silent fallbacks, masking defaults, log and continue, where a failure should be handled, and what the person sees. That rule is synced from `UAMS-Web/uams-claude-skills`, and shared files are meant to hold only what is general to every repository. This file holds the answers to those questions as they apply to this package, so a reviewer here knows where to look. Until the shared source stops rendering its own copy of these examples for this repository (`UAMS-Web/uams-claude-skills#117`), the synced rule carries a shorter version of the paragraphs below; this file is the one to keep.

It is this repository's own file, not a synced one: `.claude/sync-manifest.jsonc` does not list it, so the sync leaves it alone and it is edited here.

## Where this importer hides a failure

**An absent node and an empty one read the same.** `XpathHelper::getStringValue()` returns its default (`''` unless the caller passes another) for a node that is absent or an XPath that fails, and a `(string)` cast of a SimpleXML element returns `''` for a missing node. That is the *masking defaults* question in its commonest form here: ask whether the code that reads the value can tell "the export did not carry this field" from "the field was blank", and whether it needs to.

**This importer logs a warning and moves to the next item by design.** So the *log and continue* question is never whether carrying on is allowed, only whether *this* failure is one where it is right. Ask:

- Does the warning reach `ImportWarningManager`, so the run's summary reports it? A warning written only to the log is one nobody reading the summary will see.
- Does it name the site, the post or term id, and the input, so the case can be found again in a multi-thousand-item export?

**Cleanup includes the memory the loop releases.** Long import loops `unset()` what they are done with and call `gc_collect_cycles()`, because one process handles the whole export. A catch or an early `continue` that skips that release is the *where it should be handled* question: the failure is handled, and the cleanup it bypassed is not.

## The DRY line

The questions themselves, and the frame they are asked in, are [`adversarial-review`](adversarial-review.md)'s, and a check that they stay worded as they are lives in `UAMS-Web/uams-claude-skills` beside the one copy it guards (`UAMS-Web/uams-claude-skills#33`). This file owns only the examples, and [`tests/Unit/AdversarialReviewQuestionsTest.php`](../../tests/Unit/AdversarialReviewQuestionsTest.php) checks that it keeps them.
