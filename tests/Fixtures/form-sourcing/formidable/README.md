# Formidable sourcing fixtures — one form, two sources, one snapshot

These two files are the **same form at the same moment**, taken from
`communications.uams.edu/web` on 2026-09-15: the XML export at 08:12, the
migration API read at 08:16. They exist so a test can compare what the file
path and the API path hand the mapping, without a live site.

| File | Source | Form |
| --- | --- | --- |
| `xml-form3.xml` | Formidable's own XML export | form `3`, key `l3220` |
| `api-form3.json` | `GET /forms/formidable` | the same form |

Two of the form's 163 fields are kept — `287`, which carries real conditional
logic, and `598`, which does not.

## What is verbatim, and why it has to be

`field_options` is **unmodified on both sides**. The operators (`["=="]`) and
the field ids in `hide_field` are what a test asserts on; scrubbing them yields
a fixture that passes while testing nothing.

Form-level `options` keeps all four value types, including the nested
`submit_conditions` (object) and `rootline_titles` (array).

## What is scrubbed

Form and field `name` and `description`, `default_value`, `success_msg`,
`before_html`, `after_html`, `submit_value`, and the field-level `options`
**choice labels** — that last one is a different key from `field_options` and
held real site content.

Three long strings are deliberately **not** scrubbed, because they are plugin
defaults rather than site content: `custom_html`, `conf_msg` and `blank`.
`custom_html` is load-bearing — it is the member that differs between the two
sources by exactly one level of unescaping.

## The differences a test should expect

These are properties of the two formats, not defects, and a naive
member-by-member equality assertion fails on all of them:

- **The API hydrates defaults the export omits.** Field `287` has 17
  `field_options` members in the XML and 67 from the API. `hide_field_cond`
  is absent from the export and `["=="]` from the API — a default, not a
  disagreement.
- **The export escapes one level more than the API.** Compare after a single
  `stripslashes()` on the export's value.

So the assertion is **one-directional**: every member the XML carries must
survive translation with a matching value; members present only in the API are
expected.
