<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Model\PublishedReplyModel;
use App\Service\Social\Model\ReplyDraftModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostReplier\PostReplierInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class PostReplying
{
    public function __construct(
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_replier', defaultIndexMethod: 'network')]
        private ContainerInterface $repliers,
    ) {
    }

    public function publish(SocialPostModel $target, ReplyDraftModel $draft): PublishedReplyModel
    {
        $replier = $this->replierOf($target->account);

        return $this->guard->actAs(
            $target->account,
            static fn (): PublishedReplyModel => $replier->publish($target, $draft),
        );
    }

    public function delete(SocialPostModel $ownPost): void
    {
        $replier = $this->replierOf($ownPost->account);
        $this->guard->actAs($ownPost->account, static function () use ($replier, $ownPost): void {
            $replier->delete($ownPost);
        });
    }

    private function replierOf(SocialAccount $account): PostReplierInterface
    {
        $replier = $this->repliers->get($account->getNetwork()->value);
        \assert($replier instanceof PostReplierInterface);

        return $replier;
    }
}
