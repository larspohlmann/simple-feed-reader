<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Publishing\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;

/** Asks a reply's thread starter every time and mentions the post ids it was given; a link post holds 475. */
final class FakeComposerDefaults implements ComposerDefaultsInterface
{
    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function resolveDefaults(
        SocialAccount $account,
        PublicationModel $publication,
        \Closure $threadStarter,
    ): ComposerDefaultsModel {
        if (null !== $publication->inReplyTo) {
            $threadStarterId = $threadStarter()->postId ?? 'no thread starter';

            return new ComposerDefaultsModel(500, 'public', [$publication->inReplyTo->postId, $threadStarterId]);
        }

        return new ComposerDefaultsModel($publication->embed instanceof LinkEmbedModel ? 475 : 500, 'public');
    }
}
