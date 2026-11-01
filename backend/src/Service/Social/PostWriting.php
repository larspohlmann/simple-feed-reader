<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostWriter\PostWriterInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;

final readonly class PostWriting
{
    public function __construct(
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_writer', defaultIndexMethod: 'network')]
        private ContainerInterface $repliers,
    ) {
    }

    public function publish(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        $replier = $this->replierOf($target->account);

        return $this->guard->actAs(
            $target->account,
            static fn (): PublishedPostModel => $replier->publish($target, $draft),
        );
    }

    public function delete(SocialPostModel $ownPost): void
    {
        $replier = $this->replierOf($ownPost->account);
        $this->guard->actAs($ownPost->account, static function () use ($replier, $ownPost): void {
            $replier->delete($ownPost);
        });
    }

    private function replierOf(SocialAccount $account): PostWriterInterface
    {
        $replier = $this->repliers->get($account->getNetwork()->value);
        \assert($replier instanceof PostWriterInterface);

        return $replier;
    }
}
