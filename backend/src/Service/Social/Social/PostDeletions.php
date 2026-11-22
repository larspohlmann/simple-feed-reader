<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Social\Model\PostDeletion;
use App\Service\Social\Social\PostDeleter\PostDeleterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

final readonly class PostDeletions
{
    /** @param ServiceLocator<PostDeleterInterface> $deleters */
    public function __construct(
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_deleter')]
        private ServiceLocator $deleters,
    ) {
    }

    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        $deleter = $this->deleters->get($account->getNetwork()->value);

        return $this->guard->actAs($account, static fn (): PostDeletion => $deleter->delete($account, $postId));
    }
}
