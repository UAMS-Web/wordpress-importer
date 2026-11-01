<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets;

/**
 * Utility class for rendering Statamic uamsweb_set_table Bard sets.
 *
 * Extends AbstractSetRenderer to handle table sets, rendering to a figure with a
 * placeholder table.
 */
class Table extends AbstractSetRenderer
{
    /**
     * Constructor to set the set type.
     */
    public function __construct()
    {
        parent::__construct('table');
    }

    /**
     * Render a table set node to HTML for Tiptap processing.
     *
     * @param  object  $node  The Tiptap node object containing set data
     * @return array<string, string> HTML rendering configuration
     */
    public function renderHTML(
        $node
    ): array {
        return [
            'tag' => '<figure class="wp-block-table">',
            'content' => '<table></table>',
        ];
    }
}
