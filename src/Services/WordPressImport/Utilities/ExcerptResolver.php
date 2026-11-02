<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

/**
 * Resolves an entry's `excerpt` summary from the WordPress sources that can carry one.
 *
 * The summary is required on every front-end-view blueprint (`UAMS-Web/uams-statamic-archive#1378`),
 * so an imported entry must never land with an empty one. WordPress spreads the text across
 * three unrelated places, none of which is guaranteed to be present:
 *
 * - `excerpt:encoded` — the author-written excerpt, absent on most pages
 * - `_seopress_titles_desc` postmeta — the SEO plugin's meta description
 * - `seo_meta_description` postmeta — an older SEO field still present in some exports
 *
 * Candidates are tried in that order. Each is stripped of HTML *before* it is judged
 * non-empty, so markup that carries no text — `<p></p>`, a stray `&nbsp;` — falls through
 * to the next source instead of being written as an empty required field.
 *
 * `HtmlTagStripper::strip()` is not sufficient on its own for that judgement. It collapses
 * `\s+` and trims, but its pattern carries no `/u` flag, and a non-breaking space is U+00A0 —
 * which PHP's `\s` matches only in Unicode mode, and which `trim()` never removes. So
 * `strip('&nbsp;')` returns a blank character rather than the empty string, and an
 * editor-authored `&nbsp;` excerpt is common enough in WordPress that judging it non-empty
 * would reintroduce the blank required field this exists to stop. Re-collapsing under `/u`
 * is what closes that gap. Verified on PCRE 10.44 (macOS): `/\s/u` matches U+00A0, `/\s/`
 * does not.
 */
class ExcerptResolver
{
    /**
     * Resolve the first candidate that yields text once HTML is stripped.
     *
     * @param  string  ...$candidates  Raw source values, in descending precedence
     * @return string|null The stripped summary, or null when no candidate carries text
     */
    public static function resolve(
        string ...$candidates
    ): ?string {
        foreach ($candidates as $candidate) {
            // Strip markup before judging the candidate
            $stripped = HtmlTagStripper::strip($candidate);

            // Re-collapse in Unicode mode and trim, so a candidate carrying only blank
            // characters is judged empty. A `preg_replace()` failure on malformed UTF-8
            // yields null, which casts to '' and correctly falls through to the next source.
            $normalised = \trim(\preg_replace('/\s+/u', ' ', $stripped) ?? '');

            if ($normalised !== '') {
                return $normalised;
            }
        }

        return null;
    }
}
