<?php

declare(strict_types=1);

namespace App\Service\Curation\Trending\StoryLinkSource;

use App\Service\Curation\Trending\Model\StoryLinkModel;
use App\Service\Curation\Trending\Pass\TrendingRun;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.story_link_source')]
interface StoryLinkSourceInterface
{
    /** @return list<StoryLinkModel> each pair at most once */
    public function links(TrendingRun $run): array;
}
