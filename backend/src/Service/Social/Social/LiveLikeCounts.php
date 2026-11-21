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

/**
 * A post's like count once the reader's like changes, counted rather than read back: a federated post's origin learns
 * of the like seconds later, and the home instance shows the origin's last count, so a read lags the tap by one. The
 * caller flushes.
 */
final readonly class LiveLikeCounts
{
    public function __construct(
        private SocialPostFinder $posts,
        private SocialPostAddresses $postAddresses,
        private SocialCountsCache $countsCache,
    ) {
    }

    /** `$before` is the post as read before the like was made. */
    public function recordLiked(Entry $entry, CanonicalItem $item, SocialPostModel $before): ?int
    {
        $this->countsCache->forget($entry);
        $others = self::countOthersLikes($before);

        return null === $others ? $item->getLikeCount() : self::record($item, $others + 1);
    }

    /** Called before the like is taken back: the stored count holds it, as the like wrote it or a read saw it. */
    public function recordUnliked(Entry $entry, CanonicalItem $item, SocialAccount $account): ?int
    {
        $this->countsCache->forget($entry);
        $stored = $item->getLikeCount();
        $others = null === $stored
            ? self::countOthersLikes($this->findPost($account, $entry))
            : max(0, $stored - 1);

        return null === $others ? null : self::record($item, $others);
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

    private static function record(CanonicalItem $item, int $likeCount): int
    {
        $item->recordCounts(DiscussionCounts::fromLikes($likeCount));

        return $likeCount;
    }

    private static function countOthersLikes(?SocialPostModel $post): ?int
    {
        if (null === $post?->counts) {
            return null;
        }

        return max(0, $post->counts->likes - (null === $post->existingLikeId ? 0 : 1));
    }
}
