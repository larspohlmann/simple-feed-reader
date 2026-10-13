<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;

/** The engine ticket for a feed URL, as ConcurrentFeedFetcher maps it. */
final class FeedTickets
{
    private function __construct()
    {
    }

    public static function of(string $url, ?string $etag = null, ?string $lastModified = null): RequestTicketModel
    {
        return RequestTicketModel::feed(new FetchTicketModel($url, $etag, $lastModified));
    }
}
