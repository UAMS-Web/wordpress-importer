# History — American English for prose, and a dictionary entry that overrides it needs a stated reason

The incident history, measurements and worked examples behind [`american-english-prose.md`](../rules/american-english-prose.md), moved out of the auto-loaded file unchanged by wordpress-importer-archive#1257. The instructions themselves stay in [`american-english-prose.md`](../rules/american-english-prose.md); this file is read when a rule is questioned, not on every turn.

## Why this is a standing order

The enforcement is invisible and the remedy is one line away, so the convention erodes one word at a time. A contributor writes a British spelling, the gate flags it, and the natural fix is to add the word to [`project-words.txt`](../../project-words.txt). That is defensible for any single word and, repeated, replaces the convention with its opposite. It has already happened ten times:

```
analyse  analyser  behaviour  behavioural  behaviours
defence  generalise  licence  neighbours  organised
```

**The failure is asymmetric, which is what makes it worth a rule rather than a note.** A dictionary entry passes **forever and silently** — nothing ever revisits it — while its *inflected forms still fail*, because the entry is a literal string rather than a lemma. Measured against the gate on macOS at `3a02bcf`, by running `cspell` over a file containing each form:

| word | the gate |
| --- | --- |
| `neighbours` | passes — the project dictionary carries it |
| `neighbour` | **rejected** |
| `analyse` | passes — the project dictionary carries it |
| `analysed` | **rejected** |

So a contributor who watches `neighbours` pass reasonably concludes the word is known, writes `neighbour` in the next sentence, and is rejected for a reason nothing on screen explains.

## The one word where asking the gate cannot answer

Measured with the gate's own invocation, fixtures written inside the project:

| fixture | unknown words |
| --- | --- |
| `The runner analyses the export.` — British verb | 0 |
| `The runner analyzes the export.` — American verb | 0 |
| `Two analyses of the export disagreed.` — American plural noun | 0 |
| `The runner analyzzes the export.` — planted misspelling | **1** |

**The fourth row is the control and it is not decoration.** The same four fixtures placed in a temporary directory *outside* the project scored **0 on all four, the planted misspelling included**, because files outside the project root are never checked. That run established nothing about the first three and looked exactly like a clean result — the shape [`an-empty-result-is-not-evidence`](../rules/an-empty-result-is-not-evidence.md) exists for, met inside this rule's own evidence.

Their infinitives mostly do not — `paralyse` and `catalyse` are both rejected — so for those verbs the convention is enforced in every form *except* the one that collides. `dialyse` is the measured exception: it passes bare as well, so that word is unguarded in all its forms.
