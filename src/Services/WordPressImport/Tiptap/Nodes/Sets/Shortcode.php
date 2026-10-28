<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets;

/**
 * Utility class for parsing and rendering WordPress shortcodes into Statamic Bard
 * set configurations.
 *
 * Extends AbstractSetRenderer as a placeholder for future shortcode-based sets.
 * Currently returns an empty configuration, as people sets are now handled by
 * GenericSetRenderer.
 */
class Shortcode extends AbstractSetRenderer
{
    /**
     * Constructor to initialize dependencies.
     */
    public function __construct()
    {
        parent::__construct('people');
    }

    /**
     * Render a shortcode-based set node to HTML for Tiptap processing.
     *
     * @param  object  $node  The Tiptap node object containing set data
     * @param  string  $type  The set type
     * @return array<string, string> HTML rendering configuration
     */
    public function renderHTML(
        object $node,
        string $type = 'people'
    ): array {
        $this->setType = $type;

        return parent::renderHTML($node);
    }
}
