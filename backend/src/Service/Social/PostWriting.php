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
        private ContainerInterface $writers,
    ) {
    }

    public function reply(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        $writer = $this->writerOf($target->account);

        return $this->guard->actAs(
            $target->account,
            static fn (): PublishedPostModel => $writer->reply($target, $draft),
        );
    }

    public function quote(SocialPostModel $target, PostDraftModel $draft): PublishedPostModel
    {
        $writer = $this->writerOf($target->account);

        return $this->guard->actAs(
            $target->account,
            static fn (): PublishedPostModel => $writer->quote($target, $draft),
        );
    }

    public function delete(SocialPostModel $ownPost): void
    {
        $writer = $this->writerOf($ownPost->account);
        $this->guard->actAs($ownPost->account, static function () use ($writer, $ownPost): void {
            $writer->delete($ownPost);
        });
    }

    private function writerOf(SocialAccount $account): PostWriterInterface
    {
        $writer = $this->writers->get($account->getNetwork()->value);
        \assert($writer instanceof PostWriterInterface);

        return $writer;
    }
}
