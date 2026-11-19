<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky\Support;

use App\Service\Foundation\Json\Model\JsonNodeModel;

final class BlueskyPostView
{
    public static function hasReplies(JsonNodeModel $post): bool
    {
        return ($post->int('replyCount') ?? 0) > 0;
    }

    private function __construct()
    {
    }
}
