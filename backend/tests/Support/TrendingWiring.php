<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Service\Trending\StoryLinks;
use App\Service\Trending\StoryLinkSource\HeadlineLinks;
use App\Service\Trending\StoryLinkSource\SharedUrlLinks;
use Symfony\Component\DependencyInjection\ServiceLocator;

/** Trending services built by hand: the test container hands out none of the ones used once. */
final class TrendingWiring
{
    public static function storyLinks(): StoryLinks
    {
        return new StoryLinks(new ServiceLocator([
            HeadlineLinks::key() => static fn (): HeadlineLinks => new HeadlineLinks(),
            SharedUrlLinks::key() => static fn (): SharedUrlLinks => new SharedUrlLinks(),
        ]));
    }

    private function __construct()
    {
    }
}
