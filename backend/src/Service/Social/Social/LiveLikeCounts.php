<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\CanonicalItem;
use App\Entity\DiscussionCounts;
use App\Entity\Entry;
use App\Entity\User;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRemovedException;
use Doctrine\ORM\EntityManagerInterface;

/** A post's like count as its network reports it now, stored on its item; the stored one when the network won't say. */
final readonly class LiveLikeCounts
{
    public function __construct(
        private SocialCounts $counts,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function refreshLikeCount(User $user, Entry $entry, CanonicalItem $item): ?int
    {
        try {
            $live = $this->counts->refreshed($user, $entry);
        } catch (
            NotASocialPostException
            | SocialNetworkUnreachableException
            | SocialPostNotFoundException
            | SocialPostRemovedException
            | SocialAccountNeedsReconnectException
            | SocialCredentialsRejectedException
        ) {
            return $item->getLikeCount();
        }
        $item->recordCounts(DiscussionCounts::fromCommentsAndLikes($live->replies, $live->likes));
        $this->entityManager->flush();

        return $item->getLikeCount();
    }
}
