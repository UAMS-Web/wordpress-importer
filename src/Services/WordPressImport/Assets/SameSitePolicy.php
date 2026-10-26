<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Assets;

/**
 * Enumeration of policies for handling assets from different sites.
 *
 * Defines the behavior when an asset URL is not on the same site as the
 * base blog URL during WordPress import operations.
 */
enum SameSitePolicy: string
{
    /**
     * Always download and import the asset, regardless of site.
     *
     * This policy should be used for images and other assets that should
     * be imported into Statamic's asset containers even if they're hosted
     * on external sites.
     */
    case ALWAYS_IMPORT = 'always_import';

    /**
     * Skip downloading and return null if the asset is not on the same site.
     *
     * This policy should be used when external assets should be completely
     * ignored during the import process.
     */
    case SKIP_DIFFERENT_SITE = 'skip_different_site';

    /**
     * Skip downloading but return the original URL if the asset is not on the same
     * site.
     *
     * This policy should be used for files and links where external URLs
     * should be preserved as direct links to the original source.
     */
    case LINK_DIFFERENT_SITE = 'link_different_site';
}
