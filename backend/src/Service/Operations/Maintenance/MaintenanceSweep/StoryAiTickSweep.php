<?php

declare(strict_types=1);

namespace App\Service\Operations\Maintenance\MaintenanceSweep;

use App\Service\Curation\StoryAi\Model\StoryAiReportModel;
use App\Service\Curation\StoryAi\StoryAiSweep;
use Symfony\Component\DependencyInjection\Attribute\AsTaggedItem;

#[AsTaggedItem(index: 'storyAi', priority: 5)]
final readonly class StoryAiTickSweep implements MaintenanceSweepInterface
{
    public function __construct(private StoryAiSweep $storyAiSweep)
    {
    }

    public function run(\DateTimeImmutable $deadline): StoryAiReportModel
    {
        return $this->storyAiSweep->runDueBefore($deadline);
    }

    public function idleReport(): StoryAiReportModel
    {
        return StoryAiReportModel::none();
    }
}
