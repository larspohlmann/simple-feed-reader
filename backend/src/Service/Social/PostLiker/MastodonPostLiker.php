<?php

declare(strict_types=1);

namespace App\Service\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;

final readonly class MastodonPostLiker implements PostLikerInterface
{
    private const string FAVOURITES_SCOPE = 'write:favourites';

    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function like(SocialPostModel $post): SocialLikeResultModel
    {
        $this->actOnStatus($post->account, self::statusAction($post->postId, 'favourite'));

        return new SocialLikeResultModel($post->postId);
    }

    public function unlike(SocialAccount $account, string $likeId): void
    {
        try {
            $this->actOnStatus($account, self::statusAction($likeId, 'unfavourite'));
        } catch (SocialPostNotFoundException) {
            return;
        }
    }

    private function actOnStatus(SocialAccount $account, string $path): void
    {
        $this->mastodon->actOnPost($account, $path, self::FAVOURITES_SCOPE);
    }

    private static function statusAction(string $statusId, string $action): string
    {
        return '/api/v1/statuses/' . rawurlencode($statusId) . '/' . $action;
    }
}
