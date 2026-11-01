<?php

declare(strict_types=1);

namespace UAMSWeb\Aether\WordPressImporter\Services\WordPressImport\Parsers;

/**
 * Opens a streaming parser over a content source, refusing a source that serves nothing.
 *
 * Replaces the hard-coded `new XmlStreamParser($xmlParser->getFilePaths())` each of
 * the six entry creators carried. Constructing the parser through one place is what
 * makes "a source that can serve no items" a single guarded decision rather than six
 * independent ones, each of which had chosen a different silent recovery — `return
 * []`, `return $this->pageData`, `return $this->getPageData()`, a bare `return`.
 */
class ContentStreamFactory
{
    /**
     * Open a streaming parser over every part the source serves.
     *
     * @param  ContentSource  $source  The source to stream
     * @param  string  $context  Caller identity for the error, e.g. `PostEntryCreator::createEntries`
     * @return XmlStreamParser A parser spanning every part, in reading order
     *
     * @throws EmptyContentSourceException When the source serves no export parts
     */
    public static function open(
        ContentSource $source,
        string $context
    ): XmlStreamParser {
        $filePaths = $source->getFilePaths();

        // A source with nothing to serve is a failure, not an empty import.
        if ($filePaths === []) {
            throw new EmptyContentSourceException(
                $context.': the content source served no export parts, so nothing could be '
                .'streamed. A source that yields no paths would import zero items and report '
                .'success, which is what this guard exists to prevent.'
            );
        }

        return new XmlStreamParser($filePaths);
    }
}
