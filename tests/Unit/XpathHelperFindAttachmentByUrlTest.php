<?php

declare(strict_types=1);

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\XpathHelper;

/**
 * Unit tests for XpathHelper::findAttachmentByUrl().
 *
 * @command  php vendor/bin/pest tests/Unit/XpathHelperFindAttachmentByUrlTest.php
 */
it('returns null when the attachment list is empty', function () {
    $result = XpathHelper::findAttachmentByUrl(
        [],
        'https://example.org/wp-content/uploads/image.jpg'
    );

    expect($result)->toBeNull();
});
