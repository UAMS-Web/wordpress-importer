<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use DOMElement;
use DOMNode;

/**
 * Narrows DOM nodes to elements for element-only APIs.
 */
final class DomElementHelper
{
    /**
     * Return the node when it is a DOM element, otherwise null.
     */
    public static function asElement(DOMNode $node): ?DOMElement
    {
        if ($node instanceof DOMElement) {
            return $node;
        }

        return null;
    }
}
