<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Tiptap\Nodes\Sets;

/**
 * Generic renderer for Statamic Bard sets with standard div-based output.
 *
 * Handles rendering of file, form, image, and video sets, mapping to their
 * respective data-set-type values (e.g., uamsweb_set_file, uamsweb_set_form).
 */
class GenericSetRenderer extends AbstractSetRenderer
{
    /**
     * Create a renderer for a specific set type.
     *
     * @param  string  $setType  The type of the Bard set (file, form, image, video)
     */
    public function __construct(
        string $setType
    ) {
        parent::__construct($setType);
    }
}
