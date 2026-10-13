<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Fetch\Model\FetchTicketModel;
use App\Service\Fetch\Model\RequestTicketModel;

/** The engine ticket for a feed URL on a host that is not capped in practice. */
final class FeedTickets
{
    private const int UNCAPPED = 100;

    private function __construct()
    {
    }

    public static function of(string $url, ?string $etag = null, ?string $lastModified = null): RequestTicketModel
    {
        return RequestTicketModel::feed(new FetchTicketModel($url, $etag, $lastModified), self::UNCAPPED);
    }

    public static function cappedAt(string $url, int $hostCapacity): RequestTicketModel
    {
        return RequestTicketModel::feed(new FetchTicketModel($url), $hostCapacity);
    }
}
