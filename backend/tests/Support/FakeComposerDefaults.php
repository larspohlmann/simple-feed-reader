<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Enum\SocialNetwork;
use App\Service\Social\ComposerDefaults\ComposerDefaultsInterface;
use App\Service\Social\Model\ComposerDefaultsModel;
use App\Service\Social\Model\SocialPostModel;

/** Asks for the thread's starter every time and mentions the post ids it was given. */
final class FakeComposerDefaults implements ComposerDefaultsInterface
{
    public static function network(): string
    {
        return SocialNetwork::Mastodon->value;
    }

    public function defaultsFor(SocialPostModel $target, \Closure $threadStarter): ComposerDefaultsModel
    {
        return new ComposerDefaultsModel(500, 'public', [$target->postId, $threadStarter()->postId]);
    }
}
