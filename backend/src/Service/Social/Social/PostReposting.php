<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostReposter\PostReposterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** Reposts and undoes a repost with its network's reposter, guarded as the account that acts. */
final readonly class PostReposting
{
    /** @param ServiceLocator<PostReposterInterface> $reposters */
    public function __construct(
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_reposter')]
        private ServiceLocator $reposters,
    ) {
    }

    public function repost(SocialPostModel $post): void
    {
        $reposter = $this->reposters->get($post->account->getNetwork()->value);
        $this->guard->actAs($post->account, static function () use ($reposter, $post): void {
            $reposter->repost($post);
        });
    }

    public function unrepost(SocialAccount $account, string $repostId): void
    {
        $reposter = $this->reposters->get($account->getNetwork()->value);
        $this->guard->actAs($account, static function () use ($reposter, $account, $repostId): void {
            $reposter->unrepost($account, $repostId);
        });
    }
}
