<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostReposter\PostReposterInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

/** Reposts and undoes a repost with its network's reposter, guarded as the account that acts. */
final readonly class PostReposting
{
    public function __construct(
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_reposter', defaultIndexMethod: 'network')]
        private ContainerInterface $reposters,
    ) {
    }

    public function repost(SocialPostModel $post): void
    {
        $reposter = $this->reposterOf($post->account);
        $this->guard->actAs($post->account, static function () use ($reposter, $post): void {
            $reposter->repost($post);
        });
    }

    public function unrepost(SocialAccount $account, string $repostId): void
    {
        $reposter = $this->reposterOf($account);
        $this->guard->actAs($account, static function () use ($reposter, $account, $repostId): void {
            $reposter->unrepost($account, $repostId);
        });
    }

    private function reposterOf(SocialAccount $account): PostReposterInterface
    {
        $reposter = $this->reposters->get($account->getNetwork()->value);
        \assert($reposter instanceof PostReposterInterface);

        return $reposter;
    }
}
