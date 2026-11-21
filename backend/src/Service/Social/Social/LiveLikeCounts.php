<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\CanonicalItem;
use App\Entity\DiscussionCounts;
use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialAccountNeedsReconnectException;
use App\Service\Social\Social\Exception\SocialCredentialsRejectedException;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Model\SocialPostModel;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A post's like count once the reader's like changed, counted rather than read back: a federated post's origin learns
 * of the like seconds later, and the home instance shows the origin's last count, so a read lags the tap by one.
 */
final readonly class LiveLikeCounts
{
    public function __construct(
        private SocialPostFinder $posts,
        private SocialPostAddresses $postAddresses,
        private SocialCountsCache $countsCache,
        private EntityManagerInterface $entityManager,
    ) {
    }

    private function findPost(SocialAccount $account, Entry $entry): ?SocialPostModel
    {
        try {
            return $this->posts->findAs($account, $this->postAddresses->requirePost($entry));
        } catch (
            NotASocialPostException
            | SocialNetworkUnreachableException
            | SocialPostNotFoundException
            | SocialAccountNeedsReconnectException
            | SocialCredentialsRejectedException
        ) {
            return null;
        }
    }

    public function recordLiked(Entry $entry, CanonicalItem $item, ?SocialPostModel $before): ?int
    {
        $this->countsCache->forget($entry);
        $others = self::countOthersLikes($before);

        return null === $others ? $item->getLikeCount() : $this->record($item, $others + 1);
    }

    /** The stored count holds the like being taken back: the like wrote it, or a read that saw the like did. */
    public function recordUnliked(Entry $entry, CanonicalItem $item, SocialAccount $account): ?int
    {
        $this->countsCache->forget($entry);
        $stored = $item->getLikeCount();
        $others = null === $stored
            ? self::countOthersLikes($this->findPost($account, $entry))
            : max(0, $stored - 1);

        return null === $others ? null : $this->record($item, $others);
    }

    private function record(CanonicalItem $item, int $likeCount): int
    {
        $item->recordCounts(DiscussionCounts::fromCommentsAndLikes(null, $likeCount));
        $this->entityManager->flush();

        return $likeCount;
    }

    private static function countOthersLikes(?SocialPostModel $before): ?int
    {
        $likes = $before?->counts?->likes;
        if (null === $before || null === $likes) {
            return null;
        }

        return max(0, $likes - (null === $before->existingLikeId ? 0 : 1));
    }
}
