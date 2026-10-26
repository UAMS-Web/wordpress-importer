#!/usr/bin/env python3
"""Diff two WXR documents at the layer the importer actually parses.

Written for `UAMS-Web/wordpress-importer-archive#588`, which has to show the migration API is a
strict superset of WXR. The comparison that settles it is **not** API JSON against a WXR
export: `--source=api` runs `WxrMaterializer`, which writes WXR part files, and the run then
rejoins the ordinary file flow (`docs/DECISIONS.md` -> *The API-backed source materializes
WXR*). So the artifact to compare is the XML the parser receives, and two documents that
agree here import identically by construction.

Usage:

    python3 scripts/wxr-parity-diff.py <wordpress-export.xml> <materialized-part.xml>
    python3 scripts/wxr-parity-diff.py <left> <right> --accept-missing wp_theme,wp_template

`--accept-missing` takes a comma-separated list of post types and `<category>` domains whose
absence on the right is EXPECTED, and waives those from the verdict alone -- they are still
counted and printed. It exists because a dimension the API does not serve at all is a
declared, wholesale omission rather than silent per-item loss, and gating those made this
script exit 1 on 14 of 14 live pairs: every site attaches a `wp_theme` category to its
`wp_global_styles` post, which the API does not serve (wordpress-importer-archive#1085). The default is strict --
with no flag every missing item and assignment is a verdict -- so waiving is an explicit act
at the call site, recorded in whatever log the run writes, rather than a judgement about
WordPress internals buried in this file. A name that matches nothing is reported as such, so
a waiver that has outlived its cause does not sit here inert.

Producing the two inputs is environment-specific and deliberately not automated here; the
procedure and the figures it produced are recorded on wordpress-importer-archive#588. In short: a WXR baseline comes
from `export_wp(['content' => 'all'])` run under **PHP 8.2** (8.3+ fatals on
`uamswp-seopress`), and the materialized part from `WxrMaterializer::materialize(<site>)`
against a host app with the migration API configured.

**On a multisite install, bootstrap WordPress AS the target site. Do not bootstrap and then
`switch_to_blog()`.** Two things are fixed at load and are not revisited by a blog switch, and
both corrupt a baseline quietly.

*Post types.* Registration happens process-globally at `init`, driven by the plugins active for
whichever site was current when WordPress loaded; activation is per-site on multisite, so the
sets genuinely differ. `export_wp()` enumerates `get_post_types(['can_export' => true])`, so
after a switch it exports the ORIGINATING site's post types under the target site's identity.

*Asset URLs.* `WP_CONTENT_URL` is a constant, established once from the then-current site's
`siteurl`. A switch cannot redefine it.

Measured on macOS (Darwin 25.6.0), PHP 8.2, `uamshealth.test` site 6. Both runs report
`current_blog_id == 6` and both resolve the same `upload_basedir`:

    bootstrap, then switch_to_blog(6)   551 items,  7 post types   .../wp-content
    bootstrap with site 6 as current    571 items, 10 post types   .../patients-and-guests/wp-content

The switched run is not merely short -- it registers MORE exportable types (27 against 24) and
exports FEWER, because they are the wrong ones. It offers eight types site 6 does not have
(`provider`, `location`, `treatment`, `condition`, `expertise`, `clinical-resource`,
`logo-list-item`, `dashboard-note`), all empty, and omits five that site 6 does have -- `kb`,
`uamspeople`, `nf_sub`, `mkb_faq`, `mkb_glossary` -- three of which carry rows. The 20 absent
items are 11 `uamspeople`, 8 `kb` and one `nf_sub`.

Bootstrap by setting `$_SERVER['HTTP_HOST']`, `SERVER_NAME` and `REQUEST_URI` to the target
site's domain and path before requiring `wp-load.php`. `SCRIPT_NAME` made no difference to
either effect when measured at `/index.php` and at the site's own path. Asserting
`get_current_blog_id()` afterwards catches a bootstrap that failed to resolve the site and fell
back to the main site; it does NOT catch the defect above, because the switched run reports the
target id too.

**Produce both inputs under the same bootstrap context.** The URL spelling is not a data
difference -- both forms name the same `upload_basedir` -- but they differ textually on every
attachment, so a baseline taken under one context against a materialized part taken under
another reports a mismatch on all of them. Run against exactly that pair, this script reports
`REAL field mismatches: {'attachment_url': 222, 'link': 19}` for no other reason.

**Why the post-type half is the dangerous one, and why the RIGHT-hand direction still is not
guarded.** The per-type table does surface it -- `DIFF kb left=0 right=8`, `DIFF uamspeople
left=0 right=11` -- so it is not hidden, it is indistinguishable. A post type present on the
right and absent on the left is exactly the superset signal wordpress-importer-archive#588 is looking for, so those rows
read as the ticket's own conclusion rather than as a broken baseline; `kb` and `uamspeople` are
among the post types wordpress-importer-archive#588 verifies. Nothing in the document separates the two either: the
exports' headers are byte-identical, same `<title>`, `<link>`, `<wp:base_site_url>` and
`<wp:base_blog_url>`. The condition has to be prevented where the baseline is produced.

The opposite direction has no such ambiguity and IS gated: an item, a `<category>` assignment
or an author field present on the LEFT and missing on the right is a superset violation
whatever produced it. Before that was gated, two sites in a 14-site sweep each printed a
`DIFF` row for an item the materialized side lacked and then closed with `No difference
survived triage`, exit 0 -- a dropped `page` would have read identically (wordpress-importer-archive#1082).

Exit status is 0 when the only differences are ones this script classifies as equivalent,
and 1 when a real difference survives triage -- so it works as a gate. What fails it: an item
missing on the right, a postmeta key missing on the right, a `<category>` assignment missing on
the right, an author field that genuinely differs, a compared item field that differs, and a
`guid` difference beyond entity encoding. What does not: anything present on the right alone,
which is the superset this exists to demonstrate, and anything named in `--accept-missing`.

**Triage is the point.** A naive field comparison reports well over a thousand mismatches on
a healthy pair, and nearly all of them are two documents spelling the same thing differently:

  * a **missing** element against an **empty** one (`<title/>` vs no `<title>`), which
    SimpleXML flattens to the same `''` either way;
  * an ampersand that is entity-encoded on one side and not the other;
  * `pubDate` in RFC 2822 against MySQL datetime.

Reporting those as defects buries the one that is not. Each is counted, named, and reported
under its own heading rather than folded into a single total.

Nothing here is covered by this package's CI: the gate is `pint`, `phpstan` and `test` over
PHP, so a green run says nothing about this file.
"""

import collections
import html
import re
import sys
import xml.etree.ElementTree as ET

NAMESPACES = {
    'wp': 'http://wordpress.org/export/1.2/',
    'dc': 'http://purl.org/dc/elements/1.1/',
    'content': 'http://purl.org/rss/1.0/modules/content/',
    'excerpt': 'http://wordpress.org/export/1.2/excerpt/',
}

# Item elements compared verbatim. `guid` and `pubDate` are compared separately, because
# both differ by encoding rather than by value and need their own verdict.
ITEM_WP_FIELDS = (
    'post_date', 'post_date_gmt', 'post_modified', 'post_modified_gmt', 'post_name',
    'post_type', 'post_parent', 'post_password', 'status', 'menu_order', 'is_sticky',
    'ping_status', 'comment_status', 'attachment_url',
)

RFC2822 = re.compile(r'^[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4}')
MYSQL_DATETIME = re.compile(r'^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$')


def qualify(tag):
    """Expand a `prefix:local` tag into ElementTree's `{uri}local` form."""
    prefix, local = tag.split(':')

    return '{%s}%s' % (NAMESPACES[prefix], local)


def text_of(parent, tag):
    """Read an element's text, distinguishing an absent element (None) from an empty one ('')."""
    element = parent.find(tag)

    if element is None:
        return None

    return element.text or ''


def load(path):
    """Parse a WXR document into the shape this comparison needs."""
    channel = ET.parse(path).getroot().find('channel')
    document = {'channel': {}, 'authors': {}, 'terms': {}, 'items': {}, 'comments': 0}

    for tag in ('title', 'link', 'description', 'pubDate'):
        document['channel'][tag] = text_of(channel, tag)

    for tag in ('wp:wxr_version', 'wp:base_site_url', 'wp:base_blog_url'):
        document['channel'][tag] = text_of(channel, qualify(tag))

    for author in channel.findall(qualify('wp:author')):
        login = text_of(author, qualify('wp:author_login'))

        if login is None:
            continue

        document['authors'][login] = {
            field: text_of(author, qualify('wp:' + field))
            for field in ('author_id', 'author_email', 'author_display_name',
                          'author_first_name', 'author_last_name')
        }

    for term in channel.findall(qualify('wp:term')):
        taxonomy = text_of(term, qualify('wp:term_taxonomy'))
        slug = text_of(term, qualify('wp:term_slug'))

        if taxonomy is None or slug is None:
            continue

        document['terms'][(taxonomy, slug)] = {
            'name': text_of(term, qualify('wp:term_name')),
            'parent': text_of(term, qualify('wp:term_parent')),
        }

    for item in channel.findall('item'):
        post_id = text_of(item, qualify('wp:post_id'))

        if post_id is None:
            continue

        record = {field: text_of(item, qualify('wp:' + field)) for field in ITEM_WP_FIELDS}

        for tag in ('title', 'link', 'guid', 'pubDate'):
            record[tag] = text_of(item, tag)

        for prefix, local in (('dc', 'creator'), ('content', 'encoded'), ('excerpt', 'encoded')):
            record['%s:%s' % (prefix, local)] = text_of(item, qualify('%s:%s' % (prefix, local)))

        record['categories'] = sorted(
            {(c.get('domain'), c.get('nicename')) for c in item.findall('category')}
        )

        meta = {}

        for entry in item.findall(qualify('wp:postmeta')):
            key = text_of(entry, qualify('wp:meta_key'))

            if key is not None:
                meta[key] = text_of(entry, qualify('wp:meta_value')) or ''

        record['meta'] = meta
        record['comments'] = len(item.findall(qualify('wp:comment')))
        document['comments'] += record['comments']
        document['items'][post_id] = record

    return document


def blank(value):
    """Collapse an absent element onto an empty one — SimpleXML gives '' for both."""
    return '' if value is None else value


def parse_arguments(argv):
    """Split the argument vector into the two document paths and the accepted names.

    Returns `(paths, accepted)`, or `(None, None)` when the vector is unusable.
    """
    accepted = set()
    paths = []
    index = 1

    while index < len(argv):
        argument = argv[index]

        if argument == '--accept-missing':
            index += 1

            if index >= len(argv):
                return None, None

            accepted.update(name.strip() for name in argv[index].split(',') if name.strip())
        elif argument.startswith('--accept-missing='):
            _, _, value = argument.partition('=')
            accepted.update(name.strip() for name in value.split(',') if name.strip())
        else:
            paths.append(argument)

        index += 1

    if len(paths) != 2:
        return None, None

    return paths, accepted


def force_utf8_stdout():
    """Emit UTF-8 regardless of the platform's active code page.

    Python sizes a piped stdout from the system code page rather than from the
    source file's encoding, so on a `cp1252` machine the em dashes this script
    prints leave as `0x97`. That byte is cp1252's em dash and is not valid
    UTF-8, so `scripts/wxr-parity-diff.test.mjs` decodes it as U+FFFD and its
    regexes cannot match — `node-test` then reports FAIL for code that is
    byte-identical to what passes elsewhere (wordpress-importer-archive#1152).

    **This is a producer/consumer mismatch, not a character missing from an
    encoding.** The em dash exists in `cp1252` and round-trips perfectly within
    it; what destroys it is Python writing one encoding while Node reads
    another. `.claude/rules/no-emoji-in-durable-records.md` records the
    within-encoding case and a reader would otherwise rule this cause out.

    Fixed here rather than by setting `PYTHONUTF8` in the runner: the gate is
    one consumer of this script among several, and an environment fix does not
    travel to a contributor invoking it by hand.

    `gen_release_notes.py` carries the same forcing for the same reason. A
    stdout that is not a real text stream (a harness swapping in `io.StringIO`)
    has no `reconfigure` and needs none, since it holds `str` and never encodes.
    """
    reconfigure = getattr(sys.stdout, 'reconfigure', None)
    if reconfigure is not None:
        # `errors` is named explicitly: reconfigure() resets it to "strict" whenever
        # `encoding` is given without it, so passing it keeps the handler from changing
        # as a side effect. UTF-8 encodes every code point, so "strict" never fires.
        reconfigure(encoding='utf-8', errors='strict')


def main(argv):
    force_utf8_stdout()

    paths, accepted = parse_arguments(argv)

    if paths is None:
        print(__doc__)

        return 2

    left, right = load(paths[0]), load(paths[1])
    real_differences = []

    print('  left  (WordPress export): %d items' % len(left['items']))
    print('  right (materialized)    : %d items' % len(right['items']))

    print('\n=== channel header ===')

    for key, value in left['channel'].items():
        other = right['channel'][key]
        print('  %-4s %s' % ('OK' if value == other else 'DIFF', key))

        if value != other:
            print('        left=%r right=%r' % (value, other))

    print('\n=== authors ===')
    shared_authors = set(left['authors']) & set(right['authors'])
    author_mismatches = [
        (login, field)
        for login in shared_authors
        for field in left['authors'][login]
        if blank(left['authors'][login][field]) != blank(right['authors'][login][field])
    ]
    print('  left=%d right=%d  only-left=%s  only-right=%s' % (
        len(left['authors']), len(right['authors']),
        sorted(set(left['authors']) - set(right['authors'])),
        sorted(set(right['authors']) - set(left['authors'])),
    ))
    print('  field mismatches: %d' % len(author_mismatches))

    print('\n=== terms ===')
    left_only = set(left['terms']) - set(right['terms'])
    right_only = set(right['terms']) - set(left['terms'])
    print('  left=%d right=%d' % (len(left['terms']), len(right['terms'])))
    print('  taxonomies only on the left: %s' % dict(collections.Counter(t for t, _ in left_only)))
    print('  only on the right: %s' % sorted(right_only)[:10])

    print('\n=== post types ===')
    left_types = collections.Counter(v['post_type'] for v in left['items'].values())
    right_types = collections.Counter(v['post_type'] for v in right['items'].values())

    for post_type in sorted(set(left_types) | set(right_types)):
        matched = left_types[post_type] == right_types[post_type]
        print('  %-4s %-22s left=%-5d right=%d' % (
            'OK' if matched else 'DIFF', post_type, left_types[post_type], right_types[post_type]
        ))

    # The per-item comparison below iterates the INTERSECTION, so an item present in one
    # document and absent from the other contributes to no counter and reaches no verdict.
    # Without this section a dropped `page` and a clean run print the same closing line.
    left_only_items = set(left['items']) - set(right['items'])
    right_only_items = set(right['items']) - set(left['items'])
    missing_by_type = collections.Counter(
        left['items'][post_id]['post_type'] for post_id in left_only_items
    )
    extra_by_type = collections.Counter(
        right['items'][post_id]['post_type'] for post_id in right_only_items
    )

    print('\n=== items present on one side only ===')
    print('  on the left and MISSING on the right, by post type: %s' % (
        dict(missing_by_type) or 'NONE'
    ))
    print('  only on the right, by post type: %s' % (dict(extra_by_type) or 'none'))

    shared = set(left['items']) & set(right['items'])
    print('\n=== per-item parity over %d shared items ===' % len(shared))

    verbatim = collections.Counter()
    blank_only = collections.Counter()
    entity_only = 0
    guid_differing = 0
    pubdate_differing = 0
    meta_missing = collections.Counter()
    category_missing = collections.Counter()
    category_extra = collections.Counter()

    for post_id in shared:
        a, b = left['items'][post_id], right['items'][post_id]

        for field, value in a.items():
            if field in ('meta', 'categories', 'comments', 'guid', 'pubDate'):
                continue

            other = b.get(field)

            if value == other:
                continue

            # A missing element and an empty one are the same value to the parser.
            if blank(value) == blank(other):
                blank_only[field] += 1
                continue

            verbatim[field] += 1

        if a['guid'] != b['guid']:
            guid_differing += 1

            if html.unescape(b['guid'] or '') == (a['guid'] or ''):
                entity_only += 1

        if a['pubDate'] != b['pubDate']:
            pubdate_differing += 1

        for key in a['meta']:
            if key not in b['meta']:
                meta_missing[key] += 1

        for category in a['categories']:
            if category not in b['categories']:
                category_missing[category[0]] += 1

        for category in b['categories']:
            if category not in a['categories']:
                category_extra[category[0]] += 1

    print('  equivalent — missing element vs empty element: %s (total %d)' % (
        dict(blank_only) or 'none', sum(blank_only.values())
    ))
    print('  equivalent — guid differing only by entity encoding: %d of %d differing' % (
        entity_only, guid_differing
    ))
    print('  pubDate differing (RFC 2822 vs MySQL datetime): %d' % pubdate_differing)
    print('  REAL field mismatches: %s' % (dict(verbatim) or 'none'))
    print('  postmeta keys on the left and missing on the right: %s' % (
        dict(meta_missing) or 'NONE'
    ))
    print('  <category> assignments missing on the right, by domain: %s' % (
        dict(category_missing) or 'NONE'
    ))
    print('  <category> assignments only on the right, by domain: %s' % (
        dict(category_extra) or 'none'
    ))
    print('\n=== comments ===\n  left=%d right=%d' % (left['comments'], right['comments']))

    # A dimension the API does not serve AT ALL is a declared, wholesale omission rather
    # than silent per-item loss, and gating it makes this script red on every real pair --
    # every site in a network attaches a `wp_theme` category to `wp_global_styles`, which
    # the API does not serve. Waiving is the CALLER's to state, so the judgement stays at
    # the call site and out of the comparison (wordpress-importer-archive#1085).
    gated_missing_types = {
        post_type: count
        for post_type, count in missing_by_type.items()
        if post_type not in accepted
    }
    gated_category_missing = {
        domain: count for domain, count in category_missing.items() if domain not in accepted
    }

    print('\n=== accepted omissions ===')

    if accepted:
        for name in sorted(accepted):
            matched = missing_by_type.get(name, 0) + category_missing.get(name, 0)
            print('  %-22s %s' % (
                name,
                'waived %d' % matched if matched else 'MATCHED NOTHING — stale waiver?',
            ))
    else:
        print('  none accepted; every missing item and assignment is a verdict')

    # Only the LEFT-missing direction is a verdict. An item, term or author present on the
    # right alone is the superset wordpress-importer-archive#588 is looking for, and the docstring records why the
    # right-hand direction cannot be gated without re-deriving a broken baseline.
    if gated_missing_types:
        real_differences.append('items missing on the right: %s' % gated_missing_types)

    if gated_category_missing:
        real_differences.append(
            'category assignments missing on the right: %s' % gated_category_missing
        )

    if author_mismatches:
        real_differences.append('author field mismatches: %s' % sorted(author_mismatches))

    if verbatim:
        real_differences.append('field mismatches: %s' % dict(verbatim))

    if meta_missing:
        real_differences.append('postmeta missing: %s' % dict(meta_missing))

    if guid_differing != entity_only:
        real_differences.append('guid differences beyond entity encoding')

    print('\n=== verdict ===')

    if real_differences:
        for difference in real_differences:
            print('  REAL DIFFERENCE — %s' % difference)

        return 1

    print('  No difference survived triage.')

    return 0


if __name__ == '__main__':
    sys.exit(main(sys.argv))
