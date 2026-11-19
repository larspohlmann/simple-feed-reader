<?php

declare(strict_types=1);

namespace App\Service\Content\LinkCard;

use App\Service\Content\LinkCard\Model\LinkCardBudgetModel;
use App\Service\Content\LinkCard\Model\LinkCardDrainReportModel;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;

final readonly class LinkCardDrain
{
    public function __construct(
        private LinkCardSweep $sweep,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
        private int $windowSeconds,
    ) {
    }

    public function drain(): LinkCardDrainReportModel
    {
        $deadline = $this->clock->now()->modify(\sprintf('+%d seconds', $this->windowSeconds));
        $completed = 0;

        do {
            $budget = LinkCardBudgetModel::fittingBefore(
                $deadline,
                $this->clock->now(),
                LinkCardBudgetModel::largestPass(),
            );
            $pass = $this->sweep->sweep($budget);
            $this->entityManager->clear();
            $completed += $pass->completed;
        } while ($pass->completed > 0);

        return new LinkCardDrainReportModel($completed, !$budget->isEmpty() && $pass->considered === 0);
    }
}
