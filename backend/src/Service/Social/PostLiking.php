<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Model\SocialLikeResultModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostLiker\PostLikerInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Likes and unlikes a post with its network's liker, guarded as the account that acts. */
final readonly class PostLiking
{
    public function __construct(
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_liker', defaultIndexMethod: 'network')]
        private ContainerInterface $likers,
    ) {
    }

    public function like(SocialPostModel $post): SocialLikeResultModel
    {
        $liker = $this->likerOf($post->account);

        return $this->guard->actAs($post->account, static fn (): SocialLikeResultModel => $liker->like($post));
    }

    public function unlike(SocialAccount $account, string $likeId): void
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
