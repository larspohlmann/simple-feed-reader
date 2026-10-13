<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Social\Model\SocialCountsModel;

final class SocialCountsJson
{
    /** @return array{likes: int, reposts: int, replies: int, quotes: int|null} */
    public static function of(SocialCountsModel $counts): array
    {
        return [
            'likes' => $counts->likes,
            'reposts' => $counts->reposts,
            'replies' => $counts->replies,
            'quotes' => $counts->quotes,
        ];
    }
}
