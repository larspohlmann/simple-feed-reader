<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\CanonicalItem;
use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Repository\SocialLikeRepository;
use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Social\Exception\NotASocialPostException;
use App\Service\Social\Social\Exception\SocialPostNotFoundException;
use App\Service\Social\Social\Exception\SocialPostRemovedException;
use App\Service\Social\Social\Model\SocialPostModel;
use Doctrine\ORM\EntityManagerInterface;

/** The reader's like of a post: one per item, and every copy acts through the account that made it. */
final readonly class SocialLikes
{
    public function __construct(
        private SocialLikeRepository $likes,
        private SocialPostFinder $posts,
        private ActingAccountChooser $accounts,
        private SocialPostAddresses $postAddresses,
        private PostLiking $liking,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
        private LiveLikeCounts $likeCounts,
        private ConfirmedDeletion $confirmedDeletion,
    ) {
    }

    /** @throws NotASocialPostException */
    public function like(User $user, Entry $entry): ?int
    {
        $item = $this->postAddresses->requireItem($entry);
        if (null !== $this->likes->findOneFor($user, $item)) {
            return $item->getLikeCount();
        }
        $post = $this->posts->find($user, $entry);
        $result = $this->liking->like($post);
        $likeCount = $this->likeCounts->recordLiked($entry, $item, $post);

        $this->store($user, $item, $post->account, $result->likeId);

        return $likeCount;
    }

    /** @throws NotASocialPostException */
    public function unlike(User $user, Entry $entry): ?int
    {
        $item = $this->postAddresses->requireItem($entry);
        $like = $this->likes->findOneFor($user, $item);
        if (null === $like) {
            return $item->getLikeCount();
        }
        $likeCount = $this->likeCounts->recordUnliked($entry, $item, $like->getSocialAccount());
        $this->liking->unlike($like->getSocialAccount(), $like->getRemoteId());

        $this->drop($like);

        return $likeCount;
    }

    /**
     * The post as the network reports it now, the user's like on it mirrored into the stored likes.
     *
     * @throws NotASocialPostException
     * @throws SocialPostRemovedException when the post's home server confirms it deleted; every copy here is gone
     */
    public function syncFromNetwork(User $user, Entry $entry): SocialPostModel
    {
        $item = $this->postAddresses->requireItem($entry);
        $stored = $this->likes->findOneFor($user, $item);
        try {
            $address = $this->postAddresses->requirePost($entry);
            $account = $this->accounts->requireStoredOrActing(
                $stored?->getSocialAccount(),
                $user,
                $entry,
                $address->network,
            );
            $post = $this->posts->findAs($account, $address);
        } catch (SocialPostNotFoundException $notFound) {
            $this->confirmedDeletion->removeIfConfirmed($entry);

            throw $notFound;
        }
        $this->mirror($stored, $user, $item, $post);

        return $post;
    }

    private function mirror(?SocialLike $stored, User $user, CanonicalItem $item, SocialPostModel $post): void
    {
        $likeId = $post->existingLikeId;
        if (null === $likeId) {
            $this->drop($stored);

            return;
        }
        if (null === $stored) {
            $this->store($user, $item, $post->account, $likeId);

            return;
        }
        if ($stored->getRemoteId() !== $likeId) {
            $stored->replaceWith($likeId, $this->clock->now());
            $this->entityManager->flush();
        }
    }

    private function store(User $user, CanonicalItem $item, SocialAccount $account, string $remoteId): void
    {
        $this->entityManager->persist(new SocialLike($user, $item, $account, $remoteId, $this->clock->now()));
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
}
