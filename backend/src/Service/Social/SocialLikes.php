<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Repository\SocialLikeRepository;
use App\Service\Clock\NaiveUtcClock;
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

        $this->entityManager->persist(new SocialLike(
            $user,
            $entry,
            $post->account,
            $result->likeId,
            $this->clock->now(),
        ));
        $this->entityManager->flush();
        $this->forgetCountsOf($entry);
    }

    public function unlike(User $user, Entry $entry): void
    {
        $like = $this->likes->findOneFor($user, $entry);
        if (null === $like) {
            return;
        }
        $this->liking->unlike($like->getSocialAccount(), $like->getRemoteId());

        $this->entityManager->remove($like);
        $this->entityManager->flush();
        $this->forgetCountsOf($entry);
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
