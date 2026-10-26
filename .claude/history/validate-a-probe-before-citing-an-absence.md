# History — validate a probe against a known hit before citing an absence

The incident history, measurements and worked examples behind the former `validate-a-probe-before-citing-an-absence.md` rule, moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The rule itself was later folded into the shared [`an-empty-result-is-not-evidence.md`](../rules/an-empty-result-is-not-evidence.md), which links here for this history (wordpress-importer-archive#1322); this file is read when a rule is questioned, not on every turn.

## Why this is a standing order

**A stated sweep that did not hold.** PR wordpress-importer-archive#860's body asserted that "a repo-wide sweep for prose still claiming the PDF is downloaded or tagged returns nothing." It returned five, in two files the diff never touched — three in `docs/technical-reference.md`, the document this project points at for importer internals, and two in `FieldMapper.php`. The sweep had been run over `src/` alone and described as repo-wide. It was only reachable as a finding because the reviewer validated the same expression against `origin/main` first, where it returns a non-zero count.

A reader who brackets the sigil, runs it under `-E`, and reads `0` concludes the call is absent — which is the defect this example exists to describe, arriving through its own remedy.

A second case, same shape. A domain sweep keyed on `agec.org` matched inside `uamsagec.org`, a different domain; the boundary added to fix it then rejected every `www.agec.org`, taking a real count from 246 to 4. Three attempts, three different answers, and each intermediate one was quotable.

**And the same failure reaches process checks.** A control marker was removed from a command line by shell exec-optimization before the check that looked for it ran, so the check reported clean about a condition it could not observe.

**The two passing rows are the point, not padding.** An all-failing table cannot show the probe discriminates, and `R` and `d` happen not to be modifier letters — so the two most commonly read paths in this repository work, the obvious sanity check passes, and a reader concludes the platform is fine. That is the same shape as the Windows trap arriving from the opposite direction: there the safe case was a path with no leading dot, here it is the letter the path begins with.

**`scripts/local-ci.mjs` is the row to read twice, because `:s` is not a truncation.** It is zsh's *substitution* modifier, and it takes its delimiter and operands from the characters that follow — which are the path's own. The argument collapses from a 20-character path to `i.mjs`, and nothing reports that a substitution was performed. Describing this family as "a character is dropped" is wrong for exactly the case that mangles the most.

## How to apply

   **The worked example is a sweep that met every other requirement in this file and still published a false absence.** Measured on Windows 11, 2026-09-05, over a corpus of generated documents searched for shell redirects that would take effect if the text were pasted into a terminal:

   ```
   probe      \d?>{1,2}[^\s=]                       a redirect character followed by a non-space
   control    cmd 2>/dev/null && echo hi          hand-written    -> MATCHED, control passed
   real row   false | tail -1; echo "$rc"  -> 1   from the corpus -> NOT matched
   ```

   The corpus wrote its redirects as `> 1`, with the space a person naturally types; the probe required a non-space and could not match them. The control used `2>/dev/null`, where the target follows immediately — the one shape the probe could see. Nine files reported **0** hits and contained **9**; corrected, the full corpus held **189** across 79 files. A positive control was run, it matched, and it was published with the claim, exactly as the surrounding steps require.

   **The corpus is mixed rather than uniformly wrapped, and the mixed case is the one this advice exists for.** Measured on macOS (Darwin 25.6.0) at `3adec14`, over every tracked Markdown file in the two globs named above:

   ```bash
   for d in .claude docs; do
     git ls-files "$d" | grep '\.md$' | xargs awk '{print length}' | sort -n | awk -v d="$d" '
       {a[NR] = $1; if ($1 > 200) over++}
       END {printf "%-8s lines %6d  median %4d  max %5d  over-200 %5d\n",
                   d, NR, (NR % 2 ? a[(NR + 1) / 2] : int((a[NR / 2] + a[NR / 2 + 1]) / 2)), a[NR], over + 0}'
   done
   ```

   ```
   .claude  lines   8487  median   36  max  2666  over-200   717
   docs     lines   3777  median   48  max  1171  over-200   420
   ```

   A short median with a long tail — roughly 8% of `.claude` lines and 11% of `docs` lines run past 200 characters, and the longest is 2,666. **The figures are stamped with a commit because the corpus contains this file**, so the commit that records them changes them — re-running here reports 18 more `.claude` lines than the base it was measured at, those being the ones this paragraph added. That is the self-incrementing shape step 4 warns about, met in the one place it cannot be designed out. **Mixed is worse for a searcher than either uniform case would be.** A corpus that wrapped nothing would let a phrase `grep` work; one wrapped everywhere would break on the first attempt and teach the habit immediately. Mixed gives neither, because whether a particular phrase crosses a break is not predictable from the corpus — so it has to be handled on every search rather than reasoned about once.

   Observed on 2026-09-09 in this repository: `node scripts/local-ci.mjs --help` was run, exited `0`, and printed a job list. Every word of that was true and the claim it supported was false — the run was in a checkout twelve commits behind `origin/main`, and the help line is **generated** from the job list in that tree, so it printed a perfectly correct help screen for a superseded set of jobs.

   Observed on [#808](https://github.com/UAMS-Web/wordpress-importer-archive/issues/808#issuecomment-5680938599), 2026-09-15. A read of the migration API's `/forms/formidable` returned 50 forms against 56 in the committed export, and the six missing were a parent form and its five repeater children: a coherent set with a ready explanation. It was about to be recorded as the API omitting them. The response said otherwise: it carried the source's own total, 56, across two pages, and the request had asked for nothing beyond the first. [`docs/migration-api-contract.md`](../../docs/migration-api-contract.md) names this hazard in its opening line, and the reader had read that line. **The importer's own client was never exposed:** the contract records that its paginator raises rather than return a short read. The wordpress-importer-archive#808 read was made by hand, outside it, and a hand-made probe is exactly what this rule governs.

   Observed on [#1113](https://github.com/UAMS-Web/wordpress-importer-archive/issues/1113). The question was whether a `field_options` guard in `FieldtypeMappingTrait` was live code. The caller search excluded the file that defines it, so that the definition would not be counted as a call site. Reproduced at `a93ac24`, the `main` of the day:

   ```
   grep -rnF 'isFieldOptionNotSet' src/ --include=*.php | grep -v 'FieldtypeMappingTrait.php'   ->  0
   grep -cF  'isFieldOptionNotSet' src/Services/WordPressImport/Forms/Utilities/FieldtypeMappingTrait.php
                                                                                               ->  24 lines
   grep -cF  'function isFieldOptionNotSet' <same file>                                          ->  1   <- control
   ```

   **The method is called 23 times, and every call is in the file the filter removed.** A well-formed `0` for it reads as dead code, which in context meant the defect under discussion was void. A retraction of a correct finding was one step away.

   **The filtered count is no more trustworthy when it is not zero.** Re-run at `c1ba014`, the same filtered search returns **1**, which is a docblock in `FormidableFormsLoader.php` mentioning the method, added by wordpress-importer-archive#1135. It is not a call. The filter still hides all 23 call sites, and it now reports one caller instead of none. Either number, taken at face value, is wrong about the same thing.

   **Step 1 cannot catch this, and running it more carefully does not help.** The control runs against the expression, and the expression is sound: unfiltered, it matches. The filter is applied after the control has passed, so the probe that was validated is not the probe that produced the number.
