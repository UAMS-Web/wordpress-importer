<?php

declare(strict_types=1);

namespace UAMSWeb\WordPressImporter\Services\WordPressImport\Utilities;

use Statamic\Facades\Entry as EntryFacade;
use Statamic\Stache\Query\EntryQueryBuilder;

/**
 * Narrows Statamic entry queries to the concrete query builder implementation.
 */
final class EntryQueryHelper
{
    /**
     * Start an entry query with a concrete builder type for static analysis.
     */
    public static function query(): EntryQueryBuilder
    {
        $query = EntryFacade::query();
        \assert($query instanceof EntryQueryBuilder);

        return $query;
    }
}
