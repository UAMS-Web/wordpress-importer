<?php

declare(strict_types=1);

/**
 * Tests the WordPress `position` postmeta → Statamic `job_title` mapping.
 *
 * `job_title` on the `person` blueprint is a replicator, not a string: the
 * importer used to write the raw postmeta value straight into it, which produces
 * an entry the Control Panel cannot render and that the schema templates and
 * `_job_title.antlers.html` cannot read.
 *
 * The target shape is the `free_text` set, confirmed against a real entry
 * (`content/collections/people/default/kirsten-thomas.md` in the host app):
 *
 *     job_title:
 *       -
 *         id: v0K6CVgM
 *         title: 'Account Director, M+ Healthcare Marketing & Communications'
 *         type: free_text
 *         enabled: true
 *
 * Note the set-group key (`institutional_roles`) does NOT appear in stored data —
 * grouping is a Control Panel affordance only.
 *
 * @command  php vendor/bin/pest tests/Unit/PeopleJobTitleMappingTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Entries\Creators\PeopleEntryCreator;

beforeEach(function (): void {
    $this->creator = new PeopleEntryCreator;
});

it('maps a position string to a single free_text replicator set', function (): void {
    $sets = $this->creator->buildJobTitleSets('Web Developer III');

    expect($sets)->toHaveCount(1);
    expect($sets[0]['title'] ?? null)->toBe('Web Developer III');
    expect($sets[0]['type'] ?? null)->toBe('free_text');
    expect($sets[0]['enabled'] ?? null)->toBeTrue();
});

it('gives every set a non-empty id', function (): void {
    $sets = $this->creator->buildJobTitleSets('Professor');

    // A replicator set without an id is not addressable by the Control Panel
    expect($sets[0]['id'] ?? null)->toBeString();
    expect($sets[0]['id'] ?? '')->not->toBe('');
});

it('generates a distinct id per set so two people do not collide', function (): void {
    $first = $this->creator->buildJobTitleSets('Professor');
    $second = $this->creator->buildJobTitleSets('Professor');

    expect($first[0]['id'])->not->toBe($second[0]['id']);
});

it('omits the field entirely for a position that carries no text', function (string $position): void {
    // ---
    // An empty `free_text` set is worse than no value: the Role/Title field would
    // render an empty row the editor has to delete. The caller drops `job_title`
    // when this returns an empty array.
    // ---
    expect($this->creator->buildJobTitleSets($position))->toBe([]);
})->with([
    'empty' => [''],
    'spaces' => ['   '],
    'tab' => ["\t"],
    'newline' => ["\n"],
    'mixed whitespace' => [" \t\n "],
]);

it('trims surrounding whitespace from the title', function (): void {
    $sets = $this->creator->buildJobTitleSets("  Professor of Medicine \n");

    expect($sets[0]['title'])->toBe('Professor of Medicine');
});

it('keeps a multi-role string as one set rather than splitting it', function (): void {
    // ---
    // Splitting `Director, Pediatrics; Professor` into separate roles is explicitly
    // out of scope for wordpress-importer-archive#31 — WordPress carries no structure to drive it, so the
    // string is treated as a single role and preserved verbatim.
    // ---
    $sets = $this->creator->buildJobTitleSets('Director, Pediatrics; Professor');

    expect($sets)->toHaveCount(1);
    expect($sets[0]['title'])->toBe('Director, Pediatrics; Professor');
});

it('preserves characters that matter in a real title', function (string $position): void {
    expect($this->creator->buildJobTitleSets($position)[0]['title'])->toBe($position);
})->with([
    'ampersand' => ['Marketing & Communications'],
    'slash' => ['Chair/Professor'],
    'parentheses' => ['Director (Interim)'],
    'comma and period' => ['Professor, Ph.D.'],
    'accented' => ['Directeur Général'],
]);
