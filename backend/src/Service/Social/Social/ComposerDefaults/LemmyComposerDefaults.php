<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ComposerDefaults;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

/** Lemmy's composer holds Markdown up to the comment limit and mentions nobody. */
#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyComposerDefaults implements ComposerDefaultsInterface
{
    public const int MAX_CHARACTERS = 10000;

    public function defaultsFor(SocialPostModel $target, \Closure $threadStarter): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(self::MAX_CHARACTERS);
    }

    public function forAccount(SocialAccount $account): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(self::MAX_CHARACTERS);
    }
}
