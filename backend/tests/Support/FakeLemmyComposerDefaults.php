<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Service\Social\Publishing\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\LemmyPostFieldsModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;

/** A Lemmy link composer that offers no communities and the page title as it is. */
final class FakeLemmyComposerDefaults implements ComposerDefaultsInterface
{
    public function forPublication(
        SocialAccount $account,
        PublicationModel $publication,
        \Closure $threadStarter,
    ): ComposerDefaultsModel {
        $link = $publication->embed;
        if (null !== $publication->inReplyTo || !$link instanceof LinkEmbedModel) {
            return new ComposerDefaultsModel(10000);
        }

        return new ComposerDefaultsModel(10000, lemmyPost: new LemmyPostFieldsModel([], $link->title, 3, 200));
    }
}
