<?php

declare(strict_types=1);

use Symfony\Component\Console\Output\BufferedOutput;
use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\DeferredProcessing\DeferredKnowledgeBaseMounts;

/**
 * Unit tests for the framework-free paths of {@see DeferredKnowledgeBaseMounts}.
 *
 * The registry records knowledge base mounts during KB import and applies them
 * in the final deferred pass, once every collection has been imported (see
 * wordpress-importer-archive#297). These tests cover the static registry contract — defer/all/clear — and
 * the resolve() guard paths that do not touch Statamic:
 *  - an empty registry resolves to a no-op;
 *  - mounts for other sites are left queued for their own final pass.
 *
 * The host-touching branch of resolve() (looking up the parent `pages` entry and
 * stamping `mount_parent` on the knowledge base root) is exercised by the host
 * application's integration tests and real import verification.
 *
 * @command  php vendor/bin/pest tests/Unit/DeferredKnowledgeBaseMountsTest.php
 */

// Reset the static registry before each test so cases do not leak state.
beforeEach(function () {
    DeferredKnowledgeBaseMounts::clear();
});

// Leave no residual mounts for other test files.
afterEach(function () {
    DeferredKnowledgeBaseMounts::clear();
});

it('starts with an empty registry', function () {
    expect(DeferredKnowledgeBaseMounts::all())->toBe([]);
});

it('accumulates deferred mounts in insertion order with their root ID, parent WordPress ID, and site', function () {
    // Queue two mounts for different roots
    DeferredKnowledgeBaseMounts::defer(
        'kb-root-1',
        '6457',
        'inside_clinical'
    );
    DeferredKnowledgeBaseMounts::defer(
        'kb-root-2',
        '99',
        'inside_radiology'
    );

    // Both mounts should be present in insertion order with the expected shape
    expect(DeferredKnowledgeBaseMounts::all())->toBe([
        [
            'kb_root_id' => 'kb-root-1',
            'parent_wp_id' => '6457',
            'site' => 'inside_clinical',
        ],
        [
            'kb_root_id' => 'kb-root-2',
            'parent_wp_id' => '99',
            'site' => 'inside_radiology',
        ],
    ]);
});

it('empties the registry when cleared', function () {
    // Queue a mount, then clear it
    DeferredKnowledgeBaseMounts::defer(
        'kb-root-1',
        '6457',
        'inside_clinical'
    );
    DeferredKnowledgeBaseMounts::clear();

    // The registry should be empty again
    expect(DeferredKnowledgeBaseMounts::all())->toBe([]);
});

it('resolves an empty registry as a no-op that writes nothing', function () {
    // Capture any console output
    $output = new BufferedOutput;

    // Resolving with no pending mounts must not error
    DeferredKnowledgeBaseMounts::resolve(
        'inside_clinical',
        $output
    );

    // Nothing should have been written and the registry stays empty
    expect($output->fetch())->toBe('')
        ->and(DeferredKnowledgeBaseMounts::all())->toBe([]);
});

it('leaves mounts for other sites queued for their own pass', function () {
    // Queue a mount for a site we will not resolve in this pass
    DeferredKnowledgeBaseMounts::defer(
        'kb-root-2',
        '99',
        'inside_radiology'
    );

    // Capture any console output
    $output = new BufferedOutput;

    // Resolve a different site; the radiology mount must be untouched
    DeferredKnowledgeBaseMounts::resolve(
        'inside_clinical',
        $output
    );

    // The other site's mount remains queued (no Statamic lookups were made)
    // and no mount messages were emitted for the unrelated site
    expect(DeferredKnowledgeBaseMounts::all())->toBe([
        [
            'kb_root_id' => 'kb-root-2',
            'parent_wp_id' => '99',
            'site' => 'inside_radiology',
        ],
    ])->and($output->fetch())->toBe('');
});
