<?php

declare(strict_types=1);

namespace App\Service\Social\Bluesky;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Bluesky\BlueskyPostWriter;
use App\Service\Social\Social\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Social\Model\SharedLinkModel;
use App\Service\Social\Social\Model\SocialPostModel;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyComposerDefaults implements ComposerDefaultsInterface
{
    public function resolveDefaults(SocialPostModel $target, \Closure $threadStarter): ComposerDefaultsModel
    {
        return $this->forAccount($target->account);
    }

    public function forAccount(SocialAccount $account): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(BlueskyPostWriter::MAX_GRAPHEMES);
    }

    public function forLinkFrom(SocialAccount $account, SharedLinkModel $link): ComposerDefaultsModel
    {
        return $this->forAccount($account);
    }
}
