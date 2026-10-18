<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Comments\Model\CommentCountsModel;
use App\Service\Social\Model\SocialCountsModel;

final class SocialCountsJson
{
    /** @return array{likes: int, reposts: int, replies: int, quotes: int|null} */
    public static function of(SocialCountsModel $counts): array
    {
        return self::shape($counts->likes, $counts->reposts, $counts->replies, $counts->quotes);
    }

    /** @return array{likes: int, reposts: int, replies: int, quotes: int|null} */
    public static function ofReply(CommentCountsModel $counts): array
    {
        return self::shape($counts->likes, $counts->reposts, $counts->replies, $counts->quotes);
    }

    /** @return array{likes: int, reposts: int, replies: int, quotes: int|null} */
    private static function shape(int $likes, int $reposts, int $replies, ?int $quotes): array
    {
        return ['likes' => $likes, 'reposts' => $reposts, 'replies' => $replies, 'quotes' => $quotes];
    }
}
