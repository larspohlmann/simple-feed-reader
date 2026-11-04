<?php

declare(strict_types=1);

namespace App\Service\Trending\StoryLinkSource;

use App\Service\Trending\Model\StoryLinkModel;
use App\Service\Trending\Pass\TrendingRun;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.story_link_source')]
interface StoryLinkSourceInterface
{
    public static function key(): string;

    /** @return list<StoryLinkModel> each pair at most once */
    public function links(TrendingRun $run): array;
}
