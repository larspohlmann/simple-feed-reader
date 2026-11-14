<?php

declare(strict_types=1);

namespace App\Service\Search\Exception;

/**
 * The engine did not answer usably: a transport failure, a non-2xx status or an unreadable response. One type,
 * because every caller recovers the same way: EntrySearchWithFallback falls back, app:search:reindex fails.
 */
final class SearchEngineUnavailableException extends \RuntimeException
{
}
