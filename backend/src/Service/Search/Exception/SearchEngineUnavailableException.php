<?php

declare(strict_types=1);

namespace App\Service\Search\Exception;

/**
 * The engine did not answer, or answered something a caller of
 * SearchIndexReader/SearchIndexWriter cannot use: a transport failure, a
 * non-2xx status, or a response shape the adapter cannot read. One type for
 * every one of those, because every caller's recovery is the same regardless
 * of which of them happened — fall back to the database (EntrySearchWithFallback)
 * or report the repair command as failed (app:search:reindex).
 */
final class SearchEngineUnavailableException extends \RuntimeException
{
}
