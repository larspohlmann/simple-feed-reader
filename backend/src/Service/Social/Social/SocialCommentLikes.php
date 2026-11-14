<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\User;
use App\Service\Comments\CommentsLoader;
use App\Service\Social\Exception\NoSocialAccountForNetworkException;
use App\Service\Social\Exception\NotASocialPostException;
use App\Service\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Exception\SocialPostNotFoundException;

/** Likes a reply in an entry's thread; the network keeps the like, so nothing is stored here. */
final readonly class SocialCommentLikes
{
    public function __construct(
        private SocialPostFinder $posts,
        private PostLiking $liking,
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
        $this->liking->like($this->posts->findReply($user, $entry, $address));
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
            $this->liking->unlike($reply->account, $reply->existingLikeId);
        }
        $this->comments->forget($user, $entry);
    }
}
