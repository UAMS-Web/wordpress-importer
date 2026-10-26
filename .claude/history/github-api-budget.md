# History — spend the REST quota to preserve the GraphQL one

The incident history, measurements and worked examples behind [`github-api-budget.md`](../rules/github-api-budget.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`github-api-budget.md`](../rules/github-api-budget.md); this file is read when a rule is questioned, not on every turn.

## Overview

**Why this is a standing order.** The two quotas do not fail together, but `gh`'s ergonomic commands make it look as though they do. `gh issue create`, `gh issue edit`, and even resolving `--assignee @me` are all GraphQL-backed, so when the GraphQL quota is gone every one of them errors identically — while REST sits untouched. Observed in the sibling `uams-statamic` on 2026-07-31 mid-session: `graphql 5040/5000` (already over) alongside `core 588/5000`, i.e. **4,412 REST calls still available** and issue work nonetheless at a full stop. The session had to re-derive the REST equivalents under time pressure. Board work genuinely had to wait for the reset; nothing else did.

## How to apply

   **The `reset` is recomputed on every read for every resource examined, not only for the two above.** Two samples separated in time are what distinguish a fixed timestamp from a regenerated one — a fixed reset holds still while the clock advances toward it; a recomputed one moves with the clock. Measured on macOS (Darwin 25.6.0), 2026-09-05, across a 26-second baseline:

   ```
   resource     sample A      sample B     moved    clock moved 26s
   core       1788647469 -> 1788647495       26     tracks the clock
   graphql    1788647469 -> 1788647495       26     tracks the clock
   search     1788643929 -> 1788643955       26     tracks the clock
   ```

   All three track the clock, so all three are synthetic — `core` and `graphql` at `now + 3600`, `search` at `now + 60`. The differing offset is not a differing behavior, and **a single sample cannot tell them apart**: one read of `search` at `now + 59` is equally consistent with a real deadline a minute out and with a value that is always a minute out. An earlier version of this section drew the second conclusion from one sample and recorded `search` as an honest counter-example; two samples show it is not.

   The claim this supports is simpler than the one it replaces: **the endpoint's `reset` is not a deadline for any resource measured here.** Bounded to the three examined — nothing here speaks to resources not sampled.

   The control is the load-bearing half — `core` stayed healthy, so the refusals are the GraphQL pool rather than a credential or connectivity fault.

   Both take a **pull-request** number as well as an issue number — pull requests assign through the `issues` endpoint — and the replace form was confirmed against each.

   Every call above returned success, so nothing distinguishes the wrong choice at the call site. **Where two distinct logins exist**, a hand-off written with the add form therefore leaves **both** assigned — the person handing off still appears to hold it, and the person taking it on cannot tell from the field that they now do.

   **That condition is not met between sessions in this repository, and the distinction still is.** Every concurrent session authenticates as the same login:

   ```
   gh api user --jq .login    ->  joshdaugherty
   ```

   So between two sessions both forms produce the same single-login result and neither can misstate anything: the field carries assigned-or-not and nothing finer. The measurement above stands as measured — it was taken against an issue carrying two assignable logins precisely because one cannot separate the forms — and it applies wherever a second login is genuinely present, such as a hand-off to a human collaborator. It does not describe a hazard between sessions here.

   The update returns no error and omits nothing — the echoed body carries the unchanged value — so a caller reading the status code records a flip that did not happen, and the pull request sits in draft where no validating run will collect it. Whether `PATCH` is inert in the other direction as well, `draft: true` against a ready pull request, is **not measured**.

   The control is what makes that a truncation rather than an inference: `#874` names `#589` in its own `blocked_by`, and it is **absent** from the unpaginated result and **present** in the paginated one. So the short call omitted the very edge a session investigating `#874` was there to confirm, and returned a well-formed answer doing it.

   ```
   blocked_by  (3 edges)    rel="next" ...page=2,  rel="last" ...page=2
   blocking    (32 edges)   rel="next" ...page=2,  rel="last" ...page=16
   sub_issues  (on #358)    rel="next" ...page=2,  rel="last" ...page=21
   ```

   `rel="last" page=16` at `per_page=2` is 32 items, which confirms the count above through a different mechanism than enumerating them. **`sub_issues` truncates at 30 as well, and it is doing so right now on this repo's own parent epic.** That endpoint carries epic structure rather than blocking edges, so a silent cut there misreports what work an epic contains — and `#358`, the epic for the migration API plugin, returns **30 unpaginated against 41 paginated**. The `Link` row above says so directly: `rel="last" page=21` at `per_page=2` is 41 or 42 items. What remains unmeasured is only `blocked_by`, where `#589` carries three edges and no issue to hand carries more than 30.

   **This is not step 6's failure, and reaching for step 6 does not cover it.** That one is a cloud session refusing GitHub's numeric-ID `Link` URL, where `gh` writes page 1 and exits 1 — an environment-specific fault with a non-zero exit to catch. This one is the ordinary REST page size on a healthy local session, and it exits 0. Both are fixed by paginating; only one announces itself.
