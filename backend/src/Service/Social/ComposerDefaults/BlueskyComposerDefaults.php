<?php

declare(strict_types=1);

namespace App\Service\Social\ComposerDefaults;

use App\Enum\SocialNetwork;
use App\Service\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Model\SocialPostModel;
use App\Service\Social\PostReplier\BlueskyPostReplier;

final readonly class BlueskyComposerDefaults implements ComposerDefaultsInterface
{
    public static function network(): string
    {
        return SocialNetwork::Bluesky->value;
    }

    public function defaultsFor(SocialPostModel $target, \Closure $threadStarter): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(BlueskyPostReplier::MAX_GRAPHEMES);
    }
}
