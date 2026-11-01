<?php

declare(strict_types=1);

namespace App\Service\Social\Support;

use App\Service\Social\Model\SocialPostModel;

/** A com.atproto.repo.strongRef: how a Bluesky record names the exact version of the post it is about. */
final class BlueskyStrongRef
{
    private function __construct()
    {
    }

    /** @return array{uri: string, cid: ?string} */
    public static function of(SocialPostModel $post): array
    {
        return ['uri' => $post->postId, 'cid' => $post->contentId];
    }
}
