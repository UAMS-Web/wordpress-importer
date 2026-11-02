---
name: statamic-addon-lifecycle
description: >-
  The lifecycle of one of the organization's Statamic add-ons, from the first commit to the next
  release: starting an add-on from the `UAMS-Web/statamic-addon-template` repository (the human
  steps and the session steps, and the order they run in), moving code out of a host site into an
  add-on without breaking the data other tools wrote, releasing it, reviewing it with Statamic's
  `marketplace-review` skill before it is listed, and carrying a change that every add-on needs
  back to the template. Activate when creating an add-on, extracting code from a site into one,
  cutting an add-on release, preparing a listing, or when a change in one add-on looks like it
  belongs in every add-on. Working inside an existing add-on (provider, configuration, tests) is
  `statamic-package-conventions`.
---
<!--
  Synced from UAMS-Web/uams-claude-skills: shared/.claude/skills/statamic-addon-lifecycle/SKILL.md
  Edit it there, not here; the next sync overwrites this copy.
  Delivered to this repository through the profile(s): statamic-package.
-->
# Statamic add-on lifecycle

The order of work for an add-on from creation to release, and the points where a person has to act.
The conventions for the code inside an add-on are in `statamic-package-conventions`, which this
skill points at and does not repeat. Statamic's own review of an add-on against its marketplace
guidelines is the `marketplace-review` skill, which is Statamic's text and stays separate from this
house guidance.

Each step below is marked **Session** (a session can do it and ask no one) or **Human** (a person
has to do it, because it needs an account permission, a setting a session cannot reach, or a
decision). A session that reaches a Human step says so in the ticket or pull request and stops at
that step; it does not work around it.

## 1. Starting an add-on

1. **Human.** Create the repository from the `UAMS-Web/statamic-addon-template` template
   repository ("Use this template"), in the `UAMS-Web` organization, public. The template copies
   its default branch with no history and no link back, so nothing but the next step records which
   template revision the add-on started from.
2. **Session.** Make the first commit say where it started:
   `Created from UAMS-Web/statamic-addon-template@<short SHA>`, with the template's `main` tip at
   the time of creation. Step 5 depends on this line.
3. **Session.** Rename the placeholders the template's README lists (package name, namespace,
   addon name and description in `composer.json` and `extra.statamic`, the provider class), and
   check that the provider is still listed under `extra.laravel.providers`
   (`statamic-package-conventions`, section 2).
4. **Human.** Add the repository to the organization's `main` rulesets. This can be done only
   after `main` exists, so it follows the first push. Until it is done, nothing prevents a direct
   push to `main`.
   Choose the ruleset by the check names the add-on reports. A freshly created add-on has per-job
   names and no aggregate `CI` job, so it joins the ruleset that requires those names; moving it to
   the ruleset that requires `CI`, `Attribution footer` and `Closing keywords` before its `ci.yml`
   has the aggregate job leaves every pull request waiting on a check that never reports. Read the
   names back from a green run on `main` before the move.
5. **Session.** Add the add-on to `shared/manifest.json` in `UAMS-Web/uams-claude-skills` with the
   same profile set as the existing add-ons (copy the `aether-multisite-forms` entry, including
   its `exclude` for `rules/running-boost-update` and `labels`), and `public: true` with
   `publicReviewed` left unset until the files the target would receive have been read for
   anything internal. `shared/README.md` covers adding a target and the public review.
6. **Human.** Run the sync for the new target. Running the sync pushes branches and opens pull
   requests in the target, so it needs the operator's authorization; a session that has not been
   given it prepares the manifest change and stops.
7. **Session.** After the sync's pull request merges, restore the two CI jobs the template leaves
   out because they need files only the sync delivers: the synced-files check and the
   mutation-instrument check. The template's README names the jobs and where each script comes
   from.
8. **Session.** Run the add-on's own gate once, from a clean clone, before the first feature is
   written. A skeleton that does not pass its own gate has a placeholder left over.

## 2. Moving code out of a host site

The host site and the add-on ship separately, so the order of the change matters more than the
code.

- **Session.** Keep configuration keys and fieldtype handles unchanged. Data written by other
  tools (stored forms, entries, blueprints, saved settings) refers to them by name, and a rename
  strands it.
- **Session.** Register explicitly what the host found by discovery. A host discovers event
  listeners, subscribers and middleware by scanning its own namespace; a package's classes are not
  scanned. Each listener becomes a registration in the provider, and each piece of middleware the
  host attached in `bootstrap/app.php` stays attached there by the host, with the alias the
  provider registers (`statamic-package-conventions`, section 2).
- **Session.** Require the package from the host in the same change that deletes the host's copy.
  A change that deletes the copy first leaves the host broken until the require lands, and one
  that requires first loads two definitions of the same class.
- **Session.** Run the host's own tests against the add-on before the host change merges: the
  procedure is the `host-app-tests` skill, and behavior that only the host's `Feature` tests
  exercise is unverified until it has been run there.
- **Human.** Merge the add-on's pull request and tag it before the host's, since the host pins a
  tagged range and merged work is unreachable until a tag exists
  (`statamic-package-conventions`, section 4).
- The provider, configuration read from the host, version pins and the test split are
  `statamic-package-conventions`; do not copy them into the ticket.

## 3. Releasing

- **Session.** Prepare the release: the version, the notes, and the tag command, following
  `writing-release-notes`. When a release is due is the `cutting-releases` rule.
- **Human.** Cut the release. Tagging and publishing need the operator's authorization (see
  *Who may cut it* in `writing-release-notes`), and a cloud session cannot tag at all.
- **Session.** Say where the host's version bump happens. It is a change to the host's
  `composer.json` constraint and lock, in a pull request in the host repository, and it follows
  the tag. An exact `statamic/cms` pin moves in the add-on first and then in the host
  (`statamic-package-conventions`, section 4).

## 4. Reviewing

Run Statamic's `marketplace-review` skill before an add-on is listed, and before a release that
changes what the listing describes (its name, summary, requirements or features).

- **Session.** Run the skill and record its report on the ticket for the release. The skill reports
  evidence and required changes and does not grant approval.
- **Session.** Each required change becomes a ticket in the add-on's repository. The review never
  edits Statamic's skill: it is Statamic's published text, delivered by the sync and compared with
  the published copy weekly, and a local edit is reverted by the next sync.
- **Human.** Submit the listing. Submission is made from an account on the marketplace.

## 5. Keeping the template current

The template is a snapshot taken at one moment and has no link to the add-ons made from it, so a
change travels in only one direction at a time and has to be carried on purpose.

- **A change from an add-on to the template.** When a change made in one add-on would be needed by
  every add-on (CI, scripts, configuration, the skeleton), open a separate pull request in the
  template for it, in its own change and not bundled with the add-on's feature. **Session.**
- **A change from the template to the add-ons.** When the template changes, read each add-on's
  first commit for its `Created from UAMS-Web/statamic-addon-template@<short SHA>` line. An add-on
  whose starting revision predates the change needs it carried forward; one that started after
  does not. File a ticket in each add-on that needs it, naming the template pull request.
  **Session.**
- **A file that moves into the sync leaves the template.** Once a file is delivered by the sync, a
  copy in the template is a second source that drifts. Remove it from the template in the same
  change, rather than updating it there.
- **Human.** Creating a repository, changing the template repository's settings, and publishing a
  repository are not session steps.

## Where this skill is delivered

This skill is routed to the `statamic-package` profile, so every add-on receives it. Two other
kinds of repository do not, and the reason is recorded here so the omission is not mistaken for an
oversight:

- **Host sites (the `statamic-app` profile) do not receive it.** The work in section 2 starts in a
  host, but the steps that matter run on the add-on's side, and a host's session finds the
  extraction in the tickets of the epic that moves the code. A host that wants the skill adds the
  profile to its manifest entry.
- **The template repository is not a sync target.** It carries no synced skills, so the skill is
  read from an add-on or from this repository.
