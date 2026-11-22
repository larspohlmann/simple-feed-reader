<?php

declare(strict_types=1);

namespace App\Service\Social\Lemmy;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Text\Support\WordBoundary;
use App\Service\Social\Lemmy\LemmyCommunities;
use App\Service\Social\Lemmy\Support\LemmyPostLimits;
use App\Service\Social\Publishing\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Publishing\Model\ComposerDefaultsModel;
use App\Service\Social\Publishing\Model\LemmyPostFieldsModel;
use App\Service\Social\Publishing\Model\PostEmbed\LinkEmbedModel;
use App\Service\Social\Publishing\Model\PublicationModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyComposerDefaults implements ComposerDefaultsInterface
{
    public function __construct(private LemmyCommunities $communities)
    {
    }

    public function resolveDefaults(
        SocialAccount $account,
        PublicationModel $publication,
        \Closure $threadStarter,
    ): ComposerDefaultsModel {
        $link = $publication->embed;
        if (null !== $publication->inReplyTo || !$link instanceof LinkEmbedModel) {
            return new ComposerDefaultsModel(LemmyPostLimits::MAX_CHARACTERS);
        }

        return new ComposerDefaultsModel(
            LemmyPostLimits::MAX_CHARACTERS,
            lemmyPost: new LemmyPostFieldsModel(
                $this->communities->postable($account),
                WordBoundary::clipped($link->title, LemmyPostLimits::MAX_TITLE_CHARACTERS),
                LemmyPostLimits::MIN_TITLE_CHARACTERS,
                LemmyPostLimits::MAX_TITLE_CHARACTERS,
            ),
        );
    }
}
