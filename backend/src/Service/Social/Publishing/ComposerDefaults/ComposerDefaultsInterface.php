<?php

declare(strict_types=1);

namespace App\Service\Social\Publishing\ComposerDefaults;

use App\Entity\SocialAccount;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use App\Service\Social\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.social_composer_defaults')]
interface ComposerDefaultsInterface
{
    /**
     * Never fails: what the network does not answer falls back to its documented default.
     *
     * @param \Closure(): ?SocialPostModel $threadStarter the entry's own post, found only when a network asks for it
     */
    public function forPublication(
        SocialAccount $account,
        PublicationModel $publication,
        \Closure $threadStarter,
    ): ComposerDefaultsModel;
}
