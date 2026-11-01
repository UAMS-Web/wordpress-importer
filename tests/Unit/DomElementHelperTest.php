<?php

declare(strict_types=1);

/**
 * Tests for DomElementHelper::asElement(), which narrows a DOMNode to a
 * DOMElement or returns null.
 *
 * @command  php vendor/bin/pest tests/Unit/DomElementHelperTest.php
 */

use UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities\DomElementHelper;

it('returns the same element when the node is an element', function (): void {
    $element = (new DOMDocument)->createElement('p', 'text');

    expect(DomElementHelper::asElement($element))->toBe($element);
});

it('returns null for nodes that are not elements', function (DOMNode $node): void {
    expect(DomElementHelper::asElement($node))->toBeNull();
})->with([
    'text node' => fn (): DOMNode => (new DOMDocument)->createTextNode('text'),
    'comment' => fn (): DOMNode => (new DOMDocument)->createComment('note'),
    'document' => fn (): DOMNode => new DOMDocument,
]);
