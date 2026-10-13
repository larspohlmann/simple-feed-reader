<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\Entry;
use App\Entity\SocialAccount;
use App\Entity\SocialLike;
use App\Entity\User;
use App\Repository\SocialLikeRepository;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\PostLiker\PostLikerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class SocialLikes
{
    public function __construct(
        private SocialLikeRepository $likes,
        private SocialPostFinder $posts,
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_liker', defaultIndexMethod: 'network')]
        private ContainerInterface $likers,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function like(User $user, Entry $entry): void
    {
        if (null !== $this->likes->findOneFor($user, $entry)) {
            return;
        }
        $post = $this->posts->find($user, $entry);
        $liker = $this->likerOf($post->account);
        $result = $this->guard->actAs($post->account, static fn (): SocialLikeResultModel => $liker->like($post));

        $this->entityManager->persist(new SocialLike(
            $user,
            $entry,
            $post->account,
            $result->likeId,
            $this->clock->now()->setTimezone(new \DateTimeZone('UTC')),
        ));
        $this->entityManager->flush();
    }

    public function unlike(User $user, Entry $entry): void
    {
        $like = $this->likes->findOneFor($user, $entry);
        if (null === $like) {
            return;
        }
        $account = $like->getSocialAccount();
        $liker = $this->likerOf($account);
        $this->guard->actAs($account, static function () use ($liker, $account, $like): void {
            $liker->unlike($account, $like->getRemoteId());
        });

        $this->entityManager->remove($like);
        $this->entityManager->flush();
    }

    private function likerOf(SocialAccount $account): PostLikerInterface
    {
        $liker = $this->likers->get($account->getNetwork()->value);
        \assert($liker instanceof PostLikerInterface);

        return $liker;
    }
}
