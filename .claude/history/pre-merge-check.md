# History — re-verify at the merge, not only at the build

The incident history, measurements and worked examples behind [`pre-merge-check.md`](../rules/pre-merge-check.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`pre-merge-check.md`](../rules/pre-merge-check.md); this file is read when a rule is questioned, not on every turn.

## What a red verdict does next

**Why, because the flip looked like it worked and the reasoning is not recoverable from the outcome.** The flip was believed to make a kickback visible. Measured on this repository's own two cases:

```
wordpress-importer-archive#993    flipped 2026-09-08T18:31:26Z   author responds 2026-09-08T21:23:23Z   10,317s  (2h 52m 57s)
wordpress-importer-archive#1032   flipped 2026-09-09T15:46:03Z   author responds 2026-09-09T15:49:14Z      191s  (3m 11s)
```

Same gate, same mechanism, **54x apart** — so the flip determined neither outcome. What differed is whether the hand-back **reached the author** rather than only the pull request. Reading `wordpress-importer-archive#993` as evidence that flipping works is the inference this paragraph exists to block: it was seen and acted on eventually, nearly three hours later, and the flip is not why.

**The flip did one thing incidentally that is now done deliberately:** it removed the branch from the merge queue. That was a side effect of what `draft` means rather than a designed signal, which is why it cleared on the wrong event. `handed-back` replaces it with a marker that says what it means and clears when the branch actually re-enters.
