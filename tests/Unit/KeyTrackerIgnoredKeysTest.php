<?php

declare(strict_types=1);

/**
 * Verifies that retired legacy postmeta keys are addressed by KeyTracker and so
 * no longer surface under "Unaddressed XML keys found" (wordpress-importer-archive#314).
 *
 * `pgopts` (a serialized PHP page-options blob) and `Order` (legacy
 * redirect/ordering postmeta) carry no migratable content in the audited UAMS
 * Caregiving "Teacher's Section" export, so both are listed in
 * KeyTracker::$ignoredMetaKeys. Neither is underscore-prefixed, so the existing
 * `_`-prefix filter in trackKeys() would not have suppressed them.
 *
 * Standalone (no host Statamic app required):
 *
 * @command  php vendor/bin/pest tests/Unit/KeyTrackerIgnoredKeysTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\KeyTracker;

/**
 * Build a WordPress page <item> carrying the given postmeta keys.
 *
 * @param  array<int, string>  $metaKeys
 */
function keyTrackerPageItem(array $metaKeys): SimpleXMLElement
{
    $postmeta = '';
    foreach ($metaKeys as $key) {
        $postmeta .= <<<XML
            <wp:postmeta>
                <wp:meta_key>{$key}</wp:meta_key>
                <wp:meta_value><![CDATA[]]></wp:meta_value>
            </wp:postmeta>
        XML;
    }

    $xml = <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <rss xmlns:wp="http://wordpress.org/export/1.2/">
            <channel>
                <item>
                    <title>Teacher's Section</title>
                    <wp:post_id>123</wp:post_id>
                    <wp:post_type>page</wp:post_type>
                    {$postmeta}
                </item>
            </channel>
        </rss>
        XML;

    return (new SimpleXMLElement($xml))->channel->item;
}

it('ignores pgopts and Order rather than reporting them as unaddressed', function (): void {
    $metaKeys = ['pgopts', 'Order'];

    $tracker = new KeyTracker;
    $tracker->trackKeys(keyTrackerPageItem($metaKeys), $metaKeys, []);

    expect($tracker->getUnaddressedKeys())
        ->not->toContain('pgopts')
        ->not->toContain('Order')
        ->and($tracker->getIgnoredMetaKeys())
        ->toContain('pgopts')
        ->toContain('Order');
});

it('still reports a genuinely unhandled key as unaddressed', function (): void {
    // Guards the assertions above against a false pass: a key that is neither
    // mapped nor ignored must still surface as unaddressed.
    $metaKeys = ['pgopts', 'Order', 'some_unhandled_legacy_key'];

    $tracker = new KeyTracker;
    $tracker->trackKeys(keyTrackerPageItem($metaKeys), $metaKeys, []);

    expect($tracker->getUnaddressedKeys())->toContain('some_unhandled_legacy_key');
});
