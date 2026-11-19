<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;
use App\Service\Social\Social\Model\SocialCountsModel;

final class MastodonPostCounts
{
    private function __construct()
    {
    }

    /** The counts of a Mastodon `Status`; null when one of them is missing. Quotes count from 4.5 on. */
    public static function parseStatus(JsonNodeModel $status): ?SocialCountsModel
    {
        return PostCounts::parseKeys(
            $status,
            'favourites_count',
            'reblogs_count',
            'replies_count',
            $status->int('quotes_count'),
        );
    }
}
