<?php

declare(strict_types=1);

namespace App\Service\Social\Social\Support;

use App\Service\Social\Bluesky\Model\JsonNodeModel;
use App\Service\Social\Social\Model\SocialCountsModel;

final class PostCounts
{
    private function __construct()
    {
    }

    public static function parseKeys(
        JsonNodeModel $node,
        string $likesKey,
        string $repostsKey,
        string $repliesKey,
        ?int $quotes,
    ): ?SocialCountsModel {
        $likes = $node->int($likesKey);
        $reposts = $node->int($repostsKey);
        $replies = $node->int($repliesKey);
        if (null === $likes || null === $reposts || null === $replies) {
            return null;
        }

        return new SocialCountsModel($likes, $reposts, $replies, $quotes);
    }
}
