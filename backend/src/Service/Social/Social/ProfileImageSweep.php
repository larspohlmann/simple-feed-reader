<?php

declare(strict_types=1);

namespace App\Service\Social\Social;

use App\Service\Foundation\Clock\NaiveUtcClock;
use App\Service\Social\Social\Model\ProfileCallBudgetModel;
use App\Service\Social\Social\Model\ProfileImageSweepReportModel;
use App\Service\Social\Social\ProfilePictureSource\ProfilePictureSourceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/** Runs the per-network picture sources in turn, each on the calls the ones before it left. */
final readonly class ProfileImageSweep
{
    /** @param iterable<ProfilePictureSourceInterface> $sources */
    public function __construct(
        #[AutowireIterator('app.profile_picture_source')]
        private iterable $sources,
        private EntityManagerInterface $entityManager,
        private NaiveUtcClock $clock,
    ) {
    }

    public function sweep(ProfileCallBudgetModel $budget): ProfileImageSweepReportModel
    {
        $report = ProfileImageSweepReportModel::none();
        $now = $this->clock->now();
        foreach ($this->sources as $source) {
            if ($budget->isEmpty()) {
                break;
            }
            $spent = $source->refresh($budget, $now);
            $report = $report->adding($spent);
            $budget = $budget->spending($spent->calls);
        }
        $this->entityManager->flush();

        return $report;
    }
}
