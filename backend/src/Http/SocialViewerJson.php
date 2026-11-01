<?php

declare(strict_types=1);

namespace App\Http;

use App\Service\Social\Model\SocialPostModel;

final class SocialViewerJson
{
    /** @return array{isLiked: bool, isReposted: bool, canRepost: bool} */
    public static function of(SocialPostModel $post): array
    {
        return [
            'isLiked' => null !== $post->existingLikeId,
            'isReposted' => null !== $post->existingRepostId,
            'canRepost' => $post->canRepost,
        ];
    }
}
