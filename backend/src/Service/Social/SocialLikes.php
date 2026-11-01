<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Repository\SocialLikeRepository;
use App\Service\Clock\NaiveUtcClock;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\Support\SocialPostAddress;
use Doctrine\ORM\EntityManagerInterface;

final readonly class SocialLikes
{
    public function __construct(
        private SocialLikeRepository $likes,
        private SocialPostFinder $posts,
        private PostLiking $liking,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private SocialCountsCache $countsCache,
    ) {
    }

    public function like(User $user, Entry $entry): void
    {
        if (null !== $this->likes->findOneFor($user, $entry)) {
            return;
        }
        $post = $this->posts->find($user, $entry);
        $result = $this->liking->like($post);

        $this->store($user, $entry, $post->account, $result->likeId);
        $this->forgetCountsOf($entry);
    }

    public function unlike(User $user, Entry $entry): void
    {
        $like = $this->likes->findOneFor($user, $entry);
        if (null === $like) {
            return;
        }
        $this->liking->unlike($like->getSocialAccount(), $like->getRemoteId());

        $this->drop($like);
        $this->forgetCountsOf($entry);
    }

    /** The post as the network reports it now, the user's like on it mirrored into the stored likes. */
    public function syncFromNetwork(User $user, Entry $entry): SocialPostModel
    {
        $stored = $this->likes->findOneFor($user, $entry);
        $post = $this->posts->find($user, $entry);
        $likeId = $post->existingLikeId;
        if (null === $likeId) {
            $this->drop($stored);

            return $post;
        }
        if (null === $stored) {
            $this->store($user, $entry, $post->account, $likeId);

            return $post;
        }
        if (!self::reportsTheSameLike($stored, $post)) {
            $stored->replaceWith($post->account, $likeId, $this->clock->now());
            $this->entityManager->flush();
        }

        return $post;
    }

    private static function reportsTheSameLike(SocialLike $stored, SocialPostModel $post): bool
    {
        return $stored->getRemoteId() === $post->existingLikeId
            && $stored->getSocialAccount()->requireId() === $post->account->requireId();
    }

    private function store(User $user, Entry $entry, SocialAccount $account, string $remoteId): void
    {
        $this->entityManager->persist(new SocialLike($user, $entry, $account, $remoteId, $this->clock->now()));
        $this->entityManager->flush();
    }

    private function drop(?SocialLike $like): void
    {
        if (null === $like) {
            return;
        }
        $this->entityManager->remove($like);
        $this->entityManager->flush();
    }

    private function forgetCountsOf(Entry $entry): void
    {
        $post = SocialPostAddress::of($entry);
        if (null === $post) {
            return;
        }
        $this->countsCache->forget($post);
    }
}
