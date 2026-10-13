<?php

declare(strict_types=1);

namespace App\Service\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Fetch\Model\ApiResponseModel;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Exception\SocialPostRefusedException;
use App\Service\Social\MastodonAccountApi;
use App\Service\Social\MastodonApiClient;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use Symfony\Component\HttpFoundation\Response;

final readonly class MastodonPostLiker implements PostLikerInterface
{
    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function like(SocialPostModel $post): SocialLikeResultModel
    {
        $answer = $this->mastodon->post($post->account, self::statusAction($post->postId, 'favourite'));
        if (Response::HTTP_NOT_FOUND === $answer->status) {
            throw new SocialPostNotFoundException('The post is no longer on Mastodon.');
        }
        self::refuseIfForbidden($answer);
        MastodonApiClient::accepted($answer);

        return new SocialLikeResultModel($post->postId);
    }

    public function unlike(SocialAccount $account, string $likeId): void
    {
        $answer = $this->mastodon->post($account, self::statusAction($likeId, 'unfavourite'));
        if (Response::HTTP_NOT_FOUND === $answer->status) {
            return;
        }
        self::refuseIfForbidden($answer);
        MastodonApiClient::accepted($answer);
    }

    private static function refuseIfForbidden(ApiResponseModel $answer): void
    {
        if (Response::HTTP_FORBIDDEN === $answer->status) {
            throw new SocialPostRefusedException('Mastodon refused this action on the post.');
        }
    }

    private static function statusAction(string $statusId, string $action): string
    {
        return '/api/v1/statuses/' . rawurlencode($statusId) . '/' . $action;
    }
}
