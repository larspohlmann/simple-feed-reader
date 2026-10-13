<?php

declare(strict_types=1);

namespace App\Service\Social\PostLiker;

use App\Entity\SocialAccount;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** One network's like, keyed by its SocialNetwork value. */
#[AutoconfigureTag('app.social_post_liker')]
interface PostLikerInterface
{
    public static function network(): string;

    /**
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function like(SocialPostModel $post): SocialLikeResultModel;

    /**
     * A like the network no longer has counts as taken back.
     *
     * @throws \InvalidArgumentException when $likeId is no like of $account
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkThrottledException
     * @throws SocialNetworkUnreachableException
     */
    public function unlike(SocialAccount $account, string $likeId): void;
}
