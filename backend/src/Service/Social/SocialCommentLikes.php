<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Service\Comments\CommentsLoader;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\PostLiker\PostLikerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Likes a reply in an entry's thread; the network keeps the like, so nothing is stored here. */
final readonly class SocialCommentLikes
{
    public function __construct(
        private SocialPostFinder $posts,
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_liker', defaultIndexMethod: 'network')]
        private ContainerInterface $likers,
        private CommentsLoader $comments,
    ) {
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function like(User $user, Entry $entry, string $address): void
    {
        $reply = $this->posts->findReply($user, $entry, $address);
        $liker = $this->likerOf($reply->account);
        $this->guard->actAs($reply->account, static fn (): SocialLikeResultModel => $liker->like($reply));
        $this->comments->forget($user, $entry);
    }

    /**
     * @throws NotASocialPostException
     * @throws NoSocialAccountForNetworkException
     * @throws SocialAccountNeedsReconnectException
     * @throws SocialPostNotFoundException
     * @throws SocialCredentialsRejectedException
     * @throws SocialNetworkUnreachableException
     */
    public function unlike(User $user, Entry $entry, string $address): void
    {
        $reply = $this->posts->findReply($user, $entry, $address);
        if (null !== $reply->existingLikeId) {
            $this->takeBack($reply->account, $reply->existingLikeId);
        }
        $this->comments->forget($user, $entry);
    }

    private function takeBack(SocialAccount $account, string $likeId): void
    {
        $liker = $this->likerOf($account);
        $this->guard->actAs($account, static function () use ($liker, $account, $likeId): void {
            $liker->unlike($account, $likeId);
        });
    }

    private function likerOf(SocialAccount $account): PostLikerInterface
    {
        $liker = $this->likers->get($account->getNetwork()->value);
        \assert($liker instanceof PostLikerInterface);

        return $liker;
    }
}
