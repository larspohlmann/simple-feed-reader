<?php

declare(strict_types=1);

namespace App\Service\Social\Social\ComposerDefaults;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Social\Model\SocialPostModel;
use App\Service\Social\Social\PostWriter\BlueskyPostWriter;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: SocialNetwork::Bluesky->value)]
final readonly class BlueskyComposerDefaults implements ComposerDefaultsInterface
{
    public function defaultsFor(SocialPostModel $target, \Closure $threadStarter): ComposerDefaultsModel
    {
        return $this->forAccount($target->account);
    }

    public function forAccount(SocialAccount $account): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(BlueskyPostWriter::MAX_GRAPHEMES);
    }
}
