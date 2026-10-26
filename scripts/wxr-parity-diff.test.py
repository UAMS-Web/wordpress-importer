#!/usr/bin/env python3
"""Tests for `wxr-parity-diff.py`'s triage classification (wordpress-importer-archive#588).

Run explicitly — the filename is not a valid module name, so `unittest`
discovery never reaches it:

    python3 scripts/wxr-parity-diff.test.py

**What these pin is the triage, not the diffing.** Reporting a difference is
easy; the script earns its place by deciding which differences are two documents
spelling one value differently and which is a defect. Get that wrong in the
permissive direction and a real gap reports as clean — which is the failure
`wordpress-importer-archive#588` exists to prevent — so each equivalence class gets a case asserting it is
*not* counted as real, and a paired case asserting a genuine difference in the
same field still is.

**This file needs no `force_utf8_stdout()`, and that was checked rather than
assumed (wordpress-importer-archive#1152).** Its only non-ASCII characters are the em dashes in this
docstring, which is never printed; every `print` and assertion message it emits
is ASCII. `wxr-parity-diff.py` does need the forcing, because four of its
`print` calls carry em dashes that a `cp1252` stdout turns into `0x97`. If a
case here ever prints non-ASCII, it needs the same treatment — the gap is in
what a script *writes*, not in what it contains.

The fixtures are the smallest WXR documents that carry the shape under test. The
script is exercised as a subprocess, so the exit code is part of what is pinned:
`0` when nothing survives triage and `1` when something does. A gate whose
non-zero cannot be reached is not a gate, which is why every case checks the code
rather than only the text.
"""
import pathlib
import subprocess
import sys
import tempfile
import unittest

SCRIPT = pathlib.Path(__file__).resolve().parent / 'wxr-parity-diff.py'

HEADER = """<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
  xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
  xmlns:content="http://purl.org/rss/1.0/modules/content/"
  xmlns:dc="http://purl.org/dc/elements/1.1/"
  xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
<title>Fixture</title><link>https://example.test</link><description>d</description>
<wp:wxr_version>1.2</wp:wxr_version>
<wp:base_site_url>https://example.test/</wp:base_site_url>
<wp:base_blog_url>https://example.test/</wp:base_blog_url>
"""
FOOTER = "</channel></rss>\n"


def document(items):
    return HEADER + ''.join(items) + FOOTER


def item(post_id='1', post_type='page', extra='', title='<title>T</title>'):
    return (
        '<item>%s<wp:post_id>%s</wp:post_id><wp:post_type>%s</wp:post_type>'
        '<wp:status>publish</wp:status>%s</item>\n' % (title, post_id, post_type, extra)
    )


class TriageTest(unittest.TestCase):
    def run_diff(self, left, right):
        with tempfile.TemporaryDirectory() as directory:
            base = pathlib.Path(directory)
            (base / 'left.xml').write_text(left, encoding='utf-8')
            (base / 'right.xml').write_text(right, encoding='utf-8')
            result = subprocess.run(
                [sys.executable, str(SCRIPT), str(base / 'left.xml'), str(base / 'right.xml')],
                capture_output=True, text=True,
            )

        return result.returncode, result.stdout

    def test_identical_documents_pass(self):
        both = document([item()])
        code, out = self.run_diff(both, both)
        self.assertEqual(0, code, out)
        self.assertIn('No difference survived triage', out)

    def test_missing_element_equals_empty_element(self):
        # `<title/>` and no `<title>` both reach the parser as ''.
        code, out = self.run_diff(
            document([item(title='<title></title>')]),
            document([item(title='')]),
        )
        self.assertEqual(0, code, out)
        self.assertIn('missing element vs empty element', out)
        self.assertIn('REAL field mismatches: none', out)

    def test_a_genuinely_different_title_is_real(self):
        # The paired case: the same field, a real value change, must not be absorbed.
        code, out = self.run_diff(
            document([item(title='<title>Left</title>')]),
            document([item(title='<title>Right</title>')]),
        )
        self.assertEqual(1, code, out)
        self.assertIn("'title': 1", out)

    def test_guid_differing_only_by_entity_encoding_is_equivalent(self):
        code, out = self.run_diff(
            document([item(extra='<guid>https://e.test/?a=1&amp;b=2</guid>')]),
            document([item(extra='<guid>https://e.test/?a=1&amp;amp;b=2</guid>')]),
        )
        self.assertEqual(0, code, out)
        self.assertIn('guid differing only by entity encoding: 1 of 1', out)

    def test_guid_differing_by_value_is_real(self):
        code, out = self.run_diff(
            document([item(extra='<guid>https://e.test/?p=1</guid>')]),
            document([item(extra='<guid>https://e.test/?p=999</guid>')]),
        )
        self.assertEqual(1, code, out)
        self.assertIn('guid differences beyond entity encoding', out)

    def test_missing_postmeta_key_is_real(self):
        meta = ('<wp:postmeta><wp:meta_key>_tablepress_export_table_id</wp:meta_key>'
                '<wp:meta_value>1</wp:meta_value></wp:postmeta>')
        code, out = self.run_diff(
            document([item(extra=meta)]),
            document([item()]),
        )
        self.assertEqual(1, code, out)
        self.assertIn('_tablepress_export_table_id', out)

    def test_pubdate_format_difference_is_reported_but_not_fatal(self):
        # Real, but latent: the importer reads item pubDate only when wp:post_date is empty.
        code, out = self.run_diff(
            document([item(extra='<pubDate>Tue, 23 Feb 2021 15:12:53 +0000</pubDate>')]),
            document([item(extra='<pubDate>2021-02-23 15:12:53</pubDate>')]),
        )
        self.assertEqual(0, code, out)
        self.assertIn('pubDate differing (RFC 2822 vs MySQL datetime): 1', out)

    def test_post_type_counts_are_compared(self):
        code, out = self.run_diff(
            document([item(post_id='1', post_type='page'), item(post_id='2', post_type='post')]),
            document([item(post_id='1', post_type='page')]),
        )
        self.assertIn('left=1     right=0', out)


if __name__ == '__main__':
    unittest.main(verbosity=2)
