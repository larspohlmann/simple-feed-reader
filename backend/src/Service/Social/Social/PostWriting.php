<?php

declare(strict_types=1);

namespace App\Service\Social;

use App\Entity\SocialAccount;
use App\Service\Social\Model\PostDeletion;
use App\Service\Social\Model\PublishedPostModel;
use App\Service\Social\Model\PostDraftModel;
use App\Service\Social\Model\ResponseKind;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostWriter\PostWriterInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireLocator;
use Symfony\Component\DependencyInjection\ServiceLocator;

final readonly class PostWriting
{
    /** @param ServiceLocator<PostWriterInterface> $writers */
    public function __construct(
        private ActingAccountGuard $guard,
        #[AutowireLocator('app.social_post_writer')]
        private ServiceLocator $writers,
    ) {
    }

    public function respond(ResponseKind $kind, SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        return $this->publishAs(
            $target->account,
            static fn (PostWriterInterface $writer) => match ($kind) {
                ResponseKind::Reply => $writer->reply($target, $draft),
                ResponseKind::Quote => $writer->quote($target, $draft),
            },
        );
    }

    public function share(SocialAccount $account, string $url, PostDraftModel $draft): PublishedPostModel
    {
        return $this->publishAs(
            $account,
            static fn (PostWriterInterface $writer) => $writer->share($account, $url, $draft),
        );
    }

    public function delete(SocialAccount $account, string $postId): PostDeletion
    {
        $writer = $this->writers->get($account->getNetwork()->value);

        return $this->guard->actAs($account, static fn (): PostDeletion => $writer->delete($account, $postId));
    }

    /** @param \Closure(PostWriterInterface): PublishedPostModel $publish */
    private function publishAs(SocialAccount $account, \Closure $publish): PublishedPostModel
    {
        $writer = $this->writers->get($account->getNetwork()->value);

        return $this->guard->actAs($account, static fn (): PublishedPostModel => $publish($writer));
    }
}
