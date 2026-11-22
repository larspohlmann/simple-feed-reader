<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Service\Social\Publishing\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\LinkPostChoicesModel;
use App\Service\Social\Publishing\Model\SharedLinkModel;
use App\Service\Social\Social\Model\SocialPostModel;

/** A Lemmy link composer that offers no communities and the page title as it is. */
final class FakeLemmyComposerDefaults implements ComposerDefaultsInterface
{
    public function resolveDefaults(SocialPostModel $target, \Closure $threadStarter): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(10000);
    }

    public function forAccount(SocialAccount $account): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(10000);
    }

    public function forLinkFrom(SocialAccount $account, SharedLinkModel $link): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(10000, linkPost: new LinkPostChoicesModel([], $link->title, 3, 200));
    }
}
