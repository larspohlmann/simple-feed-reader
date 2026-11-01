<?php

declare(strict_types=1);

namespace App\Service\Social\ComposerDefaults;

use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Service\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostWriter\BlueskyPostWriter;

final readonly class BlueskyComposerDefaults implements ComposerDefaultsInterface
{
    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function defaultsFor(SocialPostModel $target, \Closure $threadStarter): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(BlueskyPostWriter::MAX_GRAPHEMES);
    }

    public function forAccount(SocialAccount $account): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(BlueskyPostWriter::MAX_GRAPHEMES);
    }
}
