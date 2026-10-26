# History — file a defect in the repository that owns it, and claim it first when a race is likely

The incident history, measurements and worked examples behind [`filing-defects-across-repos.md`](../rules/filing-defects-across-repos.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`filing-defects-across-repos.md`](../rules/filing-defects-across-repos.md); this file is read when a rule is questioned, not on every turn.

## How to apply

   `search/issues` is the worse of the two **because its count is right**. Measured on macOS (Darwin 25.6.0), 2026-09-06: a query matching **658** printed **30** rows, while a 3-match control printed all 3 — which is why this survives, since most searches return fewer than 30.
