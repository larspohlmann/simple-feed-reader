<?php

declare(strict_types=1);

namespace App\Service\Social\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\MastodonAccountApi;
use App\Service\Social\Social\Model\SocialLikeResultModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\Support\MastodonStatusAction;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Mastodon->value)]
final readonly class MastodonPostLiker implements PostLikerInterface
{
    private const string FAVOURITES_SCOPE = 'write:favourites';

    public function __construct(private MastodonAccountApi $mastodon)
    {
    }

    public function like(SocialPostModel $post): SocialLikeResultModel
    {
        $this->actOnStatus($post->account, MastodonStatusAction::path($post->postId, 'favourite'));

        return new SocialLikeResultModel($post->postId);
    }

    public function unlike(SocialAccount $account, string $likeId): void
    {
        try {
            $this->actOnStatus($account, MastodonStatusAction::path($likeId, 'unfavourite'));
        } catch (SocialPostNotFoundException) {
        }
    }

    private function actOnStatus(SocialAccount $account, string $path): void
    {
        $this->mastodon->actOnPost($account, $path, self::FAVOURITES_SCOPE);
    }
}
