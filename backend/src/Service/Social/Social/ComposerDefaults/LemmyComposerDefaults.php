<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ComposerDefaults;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Foundation\Text\Support\WordBoundary;
use App\Service\Social\Social\LemmyCommunities;
use App\Service\Social\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Social\Model\LinkPostChoicesModel;
use App\Service\Social\Social\Model\SharedLinkModel;
use App\Service\Social\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Lemmy->value)]
final readonly class LemmyComposerDefaults implements ComposerDefaultsInterface
{
    public const int MAX_CHARACTERS = 10000;

    public function __construct(private LemmyCommunities $communities)
    {
    }

    public function defaultsFor(SocialPostModel $target, \Closure $threadStarter): ComposerDefaultsModel
    {
        return self::defaults();
    }

    public function forAccount(SocialAccount $account): ComposerDefaultsModel
    {
        return self::defaults();
    }

    public function forLinkFrom(SocialAccount $account, SharedLinkModel $link): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(
            self::MAX_CHARACTERS,
            linkPost: new LinkPostChoicesModel(
                $this->communities->postable($account),
                WordBoundary::clipped($link->title, LinkPostChoicesModel::MAX_TITLE_CHARACTERS),
            ),
        );
    }

    private static function defaults(): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(self::MAX_CHARACTERS);
    }
}
