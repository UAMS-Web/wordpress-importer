import { test } from 'node:test'
import assert from 'node:assert/strict'
import { spawnSync } from 'node:child_process'
import { mkdtempSync, writeFileSync } from 'node:fs'
import { tmpdir } from 'node:os'
import { join } from 'node:path'

const SCRIPT = join(import.meta.dirname, 'wxr-parity-diff.py')

/**
 * Resolve a Python interpreter, failing loudly rather than skipping.
 *
 * A skipped test and a passing one are the same green, and the script under test IS
 * Python — a machine that cannot run it cannot run the parity gate at all, so silence
 * here would hide exactly the condition worth reporting.
 */
function interpreter() {
    for (const candidate of ['python3', 'python']) {
        const probe = spawnSync(candidate, ['-c', 'print(1)'], { encoding: 'utf8' })

        if (probe.status === 0) {
            return candidate
        }
    }

    assert.fail('neither `python3` nor `python` runs here; wxr-parity-diff.py cannot be gated')
}

const PYTHON = interpreter()

/** One `<item>`, with only the elements this comparison reads. */
function item({ id, type = 'page', title = 'A page', categories = [] }) {
    return [
        '<item>',
        `<title>${title}</title>`,
        `<link>https://example.test/${id}</link>`,
        '<pubDate>Wed, 29 Jun 2022 20:35:30 +0000</pubDate>',
        `<guid isPermaLink="false">https://example.test/?p=${id}</guid>`,
        `<wp:post_id>${id}</wp:post_id>`,
        '<wp:post_date>2022-06-29 20:35:30</wp:post_date>',
        `<wp:post_name>page-${id}</wp:post_name>`,
        `<wp:post_type>${type}</wp:post_type>`,
        '<wp:status>publish</wp:status>',
        ...categories.map(
            ([domain, nicename]) =>
                `<category domain="${domain}" nicename="${nicename}"><![CDATA[${nicename}]]></category>`
        ),
        '</item>',
    ].join('')
}

/** A whole WXR document. `authorExtras` lets a test vary one author field alone. */
function document({ items, authorFirstName = '<wp:author_first_name><![CDATA[Ada]]></wp:author_first_name>' }) {
    return [
        '<?xml version="1.0" encoding="UTF-8"?>',
        '<rss version="2.0"',
        ' xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"',
        ' xmlns:content="http://purl.org/rss/1.0/modules/content/"',
        ' xmlns:dc="http://purl.org/dc/elements/1.1/"',
        ' xmlns:wp="http://wordpress.org/export/1.2/">',
        '<channel>',
        '<title>Example</title>',
        '<link>https://example.test</link>',
        '<description>d</description>',
        '<pubDate>Mon, 14 Sep 2026 20:19:16 +0000</pubDate>',
        '<wp:wxr_version>1.2</wp:wxr_version>',
        '<wp:base_site_url>https://example.test</wp:base_site_url>',
        '<wp:base_blog_url>https://example.test</wp:base_blog_url>',
        '<wp:author>',
        '<wp:author_id>1</wp:author_id>',
        '<wp:author_login><![CDATA[ada]]></wp:author_login>',
        '<wp:author_email><![CDATA[ada@example.test]]></wp:author_email>',
        '<wp:author_display_name><![CDATA[Ada]]></wp:author_display_name>',
        authorFirstName,
        '</wp:author>',
        items.join(''),
        '</channel>',
        '</rss>',
    ].join('\n')
}

/** Run the script over a pair and return its exit status and output. */
function compare(leftXml, rightXml, extraArguments = []) {
    const directory = mkdtempSync(join(tmpdir(), 'wxr-parity-'))
    const left = join(directory, 'left.xml')
    const right = join(directory, 'right.xml')

    writeFileSync(left, leftXml)
    writeFileSync(right, rightXml)

    const run = spawnSync(PYTHON, [SCRIPT, left, right, ...extraArguments], { encoding: 'utf8' })

    assert.equal(run.error, undefined, `the script failed to run: ${run.error}`)
    assert.notEqual(run.status, 2, `the script rejected its arguments:\n${run.stdout}`)

    return { status: run.status, stdout: run.stdout }
}

const TWO_PAGES = [item({ id: 10 }), item({ id: 11 })]

test('an identical pair exits 0, so a red below is the mutation and not the fixture', () => {
    const both = document({ items: TWO_PAGES })
    const { status, stdout } = compare(both, both)

    assert.equal(status, 0, stdout)
    assert.match(stdout, /No difference survived triage/)
})

test('an item missing on the right fails the gate and names the post type', () => {
    const left = document({ items: TWO_PAGES })
    const right = document({ items: [item({ id: 10 })] })

    assert.notEqual(left, right, 'the mutation must have applied, or this test proves nothing')

    const { status, stdout } = compare(left, right)

    assert.equal(status, 1, stdout)
    assert.match(stdout, /REAL DIFFERENCE — items missing on the right: \{'page': 1\}/)
})

test('an item present on the right alone is the superset and does not fail the gate', () => {
    const left = document({ items: [item({ id: 10 })] })
    const right = document({ items: [item({ id: 10 }), item({ id: 12, type: 'kb' })] })

    const { status, stdout } = compare(left, right)

    assert.equal(status, 0, stdout)
    assert.match(stdout, /only on the right, by post type: \{'kb': 1\}/)
})

test('a category assignment missing on the right fails the gate', () => {
    const left = document({ items: [item({ id: 10, categories: [['category', 'news']] })] })
    const right = document({ items: [item({ id: 10 })] })

    const { status, stdout } = compare(left, right)

    assert.equal(status, 1, stdout)
    assert.match(stdout, /category assignments missing on the right/)
})

test('an author field that genuinely differs fails the gate', () => {
    const left = document({ items: TWO_PAGES })
    const right = document({
        items: TWO_PAGES,
        authorFirstName: '<wp:author_first_name><![CDATA[Grace]]></wp:author_first_name>',
    })

    assert.notEqual(left, right, 'the mutation must have applied')

    const { status, stdout } = compare(left, right)

    assert.equal(status, 1, stdout)
    assert.match(stdout, /author field mismatches/)
})

test('an author field empty on one side and absent on the other stays equivalent', () => {
    // The exact pair a real export and a materialized part produce: WordPress writes an
    // empty CDATA element, the materializer omits the element. Both parse to ''.
    const left = document({
        items: TWO_PAGES,
        authorFirstName: '<wp:author_first_name><![CDATA[]]></wp:author_first_name>',
    })
    const right = document({ items: TWO_PAGES, authorFirstName: '' })

    assert.notEqual(left, right, 'the mutation must have applied')

    const { status, stdout } = compare(left, right)

    assert.equal(status, 0, stdout)
    assert.match(stdout, /field mismatches: 0/)
})

// --accept-missing (wordpress-importer-archive#1085). Gating every wholesale omission made the script exit 1 on 14 of
// 14 live pairs, because every site attaches a `wp_theme` category to `wp_global_styles` and
// the API serves no such taxonomy. The waiver is the caller's to state; the default is strict.

const THEMED = item({ id: 20, type: 'wp_global_styles', categories: [['wp_theme', 'a-theme']] })

test('an accepted category domain is waived from the verdict', () => {
    const left = document({ items: [item({ id: 10 }), THEMED] })
    const right = document({ items: [item({ id: 10 }), item({ id: 20, type: 'wp_global_styles' })] })

    const strict = compare(left, right)

    assert.equal(strict.status, 1, 'the default must still fail, or the waiver proves nothing')

    const waived = compare(left, right, ['--accept-missing', 'wp_theme'])

    assert.equal(waived.status, 0, waived.stdout)
    assert.match(waived.stdout, /wp_theme\s+waived 1/)
})

test('an accepted post type is waived, and the default without the flag still fails', () => {
    const left = document({ items: [item({ id: 10 }), item({ id: 30, type: 'wp_template' })] })
    const right = document({ items: [item({ id: 10 })] })

    assert.equal(compare(left, right).status, 1, 'strict default must fail')
    assert.equal(compare(left, right, ['--accept-missing', 'wp_template']).status, 0)
})

test('an unaccepted missing assignment still fails while another name is accepted', () => {
    // The waiver must not become a blanket. `wp_theme` is accepted; a dropped `category`
    // assignment in the same pair is real loss and has to survive it.
    const left = document({
        items: [item({ id: 10, categories: [['category', 'news']] }), THEMED],
    })
    const right = document({
        items: [item({ id: 10 }), item({ id: 20, type: 'wp_global_styles' })],
    })

    const { status, stdout } = compare(left, right, ['--accept-missing', 'wp_theme'])

    assert.equal(status, 1, stdout)
    assert.match(stdout, /category assignments missing on the right: \{'category': 1\}/)
    assert.doesNotMatch(stdout, /missing on the right: \{'wp_theme'/)
})

test('a waiver that matches nothing is reported rather than sitting inert', () => {
    const both = document({ items: TWO_PAGES })
    const { status, stdout } = compare(both, both, ['--accept-missing', 'never_used'])

    assert.equal(status, 0, stdout)
    assert.match(stdout, /never_used\s+MATCHED NOTHING/)
})

test('the accepted list accepts the --accept-missing=a,b spelling too', () => {
    const left = document({ items: [item({ id: 10 }), item({ id: 30, type: 'wp_template' })] })
    const right = document({ items: [item({ id: 10 })] })

    assert.equal(compare(left, right, ['--accept-missing=wp_template,wp_theme']).status, 0)
})
