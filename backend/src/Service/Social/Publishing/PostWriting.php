<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing;

use App\Entity\SocialAccount;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Publishing\Model\PublishedPostModel;
use App\Service\Social\Publishing\PostWriter\PostWriterInterface;
use App\Service\Social\Social\ActingAccountGuard;
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

    public function publish(SocialAccount $account, PublicationModel $publication): PublishedPostModel
    {
        $writer = $this->writers->get($account->getNetwork()->value);

        return $this->guard->actAs(
            $account,
            static fn (): PublishedPostModel => $writer->publish($account, $publication),
        );
    }
}
