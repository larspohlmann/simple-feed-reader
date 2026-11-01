<?php

declare(strict_types=1);

namespace App\Service\Social\ComposerDefaults;

use App\Service\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Model\ResponseKind;
use App\Service\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.social_composer_defaults')]
interface ComposerDefaultsInterface
{
    public static function network(): string;

    /**
     * Never fails: what the network does not answer falls back to its documented default.
     *
     * @param \Closure(): ?SocialPostModel $threadStarter the entry's own post, found only when a network asks for it
     */
    public function defaultsFor(
        SocialPostModel $target,
        ResponseKind $kind,
        \Closure $threadStarter,
    ): ComposerDefaultsModel;
}
