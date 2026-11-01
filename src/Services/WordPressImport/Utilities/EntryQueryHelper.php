<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Utilities;

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

        // The assert narrows the type for PHPStan. With it mutated or removed, a
        // builder of another class still fails, at the declared return type: a
        // TypeError instead of an AssertionError, and the same TypeError the
        // original raises where assertions are off, as in production.
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveFunctionCall
        \assert($query instanceof EntryQueryBuilder);

        return $query;
    }
}
