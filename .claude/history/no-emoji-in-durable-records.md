# History — no emoji in durable records

The incident history, measurements and worked examples behind [`no-emoji-in-durable-records.md`](../rules/no-emoji-in-durable-records.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`no-emoji-in-durable-records.md`](../rules/no-emoji-in-durable-records.md); this file is read when a rule is questioned, not on every turn.

## Why this is a standing order

**And they break, in the places least able to report it.** This has already been paid for once. `UAMS-Web/wordpress-importer`'s release-notes generator carries an explicit UTF-8 forcing on stdout for one reason: on Windows the stream is sized to the console code page, and a `--breaking` run died on the warning glyph in its callout (`U+26A0 U+FE0F`) **after doing all of its work**, leaving a traceback in the redirect where the release notes should have been. That failure is the general case in miniature: the glyph survives the editor it was typed in and fails somewhere downstream, at the moment of least attention.

**That example is the loud half. The same code page fails silently in the other direction, and the silent half is the one worth fearing.** Writing a glyph to a `cp1252` stdout raises and leaves a traceback; *reading* text through a `cp1252` stdin drops the non-ASCII characters and returns what survived. Observed 2026-09-05 on Windows: an audit of nine published artifacts piped their bodies into Python and reported no glyphs in any of them, while the same content read from disk with an explicit encoding carried `U+26A0 U+FE0F` in one body and em dashes throughout. The audit reported clean about a corpus it had not read.

**The tell was not the glyph count.** Zero glyphs in nine documents is unremarkable, which is exactly why it passed. What exposed it was an em-dash count of zero across bodies full of prose — a number that cannot be true, sitting beside one that merely happened not to be.

## The release path, and how the conflict was resolved

This repo publishes releases from the `writing-release-notes` skill, which mandated a breaking-change callout prefixed by a warning glyph, hard-coded in `gen_release_notes.py` — so the marker was emitted by code rather than typed. **Eight releases carry it — `v0.8.0`, `v0.9.0`, `v0.10.0`, `v0.11.0`, `v0.12.0`, `v0.13.0`, `v0.15.0` and `v0.16.0`** — named rather than given as a fraction, because the set is closed and the release count is not, so a fraction goes wrong at the next tag.

**The words-form callout has not yet appeared in a published release, and this is worth knowing before the next one is cut.** One release has been tagged since the change, `v0.17.0`, and it announced nothing breaking, so it exercised neither form:

| | `Breaking change` in words | `U+26A0` |
| --- | --- | --- |
| `v0.17.0` | no | no |
| `v0.16.0` | yes | yes |

A clean body is therefore not evidence about the callout format, and the reason is broader than a breaking change. **The glyph marked callouts, of more than one kind.** Seven of the eight carry it on `**Breaking change**`; `v0.15.0` carries it on `**Adoption note**` and contains the string `Breaking change` nowhere at all. So a release without the glyph is one that announced no callout of any kind, rather than one with nothing breaking in it — and `v0.17.0` is exactly that: no glyph, no words-form callout, no bold callout whatsoever, only section headings.

**That `v0.15.0` callout also shows the glyph reached releases by two routes, and only one of them was closed.** The generator emits `Adoption note` zero times — against five occurrences of `Breaking change`, and zero in the skill — so that one was typed by hand. Removing the marker from `gen_release_notes.py` and the skill ends the *generated* route; the typed route is closed by this rule's own prohibition and by nothing else. **The replacement is correct in the source and unexercised in output**, measured 2026-09-11 with controls so an absent glyph is distinguished from an unread file:

```
gen_release_notes.py   U+26A0 0   U+FE0F 0   'Breaking change' 5    control: 12 'def '
SKILL.md               U+26A0 0               'Breaking change' 6    control: 22 '##'
```

That the untried arm is the one that matters here is not incidental: the defect being fixed was a Windows encoding crash **after the generator had done all of its work**, and a release is cut rarely and under time pressure. `force_utf8_stdout()` remains in place regardless, for the reason given below.

The alternative was to carve the callout out of this rule permanently and leave the generator alone. It was rejected because **the callout is this rule's own worked example**: the `--breaking` run that died under a Windows code page died on that glyph. A rule whose stated exception is the failure it argues from is one people learn to skip.

**"In `cp1252` and always survived" is a claim about WRITING, and a character that survives writing can still be destroyed in transit.** The sentence below is true of a file written and read back as `cp1252`: the em dash exists there, encodes without raising, and round-trips perfectly. What it does not cover is a **producer/consumer mismatch** — Python writing `cp1252` while Node reads UTF-8 — where that same em dash leaves as the single byte `0x97`, which is not valid UTF-8, and arrives as `U+FFFD`.

That is `wordpress-importer-archive#1152`: `scripts/wxr-parity-diff.py` printed em dashes that `scripts/wxr-parity-diff.test.mjs` could not match, so `node-test` reported FAIL for code byte-identical to what passed elsewhere. Measured on macOS by forcing the producer's encoding, with the fix reverted:

| `PYTHONIOENCODING` | stdout bytes | UTF-8 em dash | `0x97` | `node-test` |
| --- | --- | --- | --- | --- |
| `cp1252` | 1230 | no | **yes** | 10 pass, **1 fail** |
| unset, on a UTF-8 machine | 1234 | yes | no | 11 pass, 0 fail |
