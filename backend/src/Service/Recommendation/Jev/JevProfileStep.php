<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Jev;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Profile\ProfileDistiller\ProfileDistillerInterface;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\RecommendationRunFailure;
use App\Service\Recommendation\Run\RecommendationTickCheckpoint;
use Doctrine\ORM\EntityManagerInterface;

/**
 * A Jev run's profile: distilled through the profile connection the active connection borrows, else the last stored
 * one, else the run fails. Failed, not cancelled, so a resume distils again once the account has fixed the cause.
 */
final readonly class JevProfileStep
{
    public const string NO_PROFILE_CONNECTION = 'Jev needs an LLM connection to build your profile — choose one '
        . 'under Settings → AI, then resume this run.';

    public const string NO_PROFILE = 'Jev could not build your profile: the profile connection gave no usable answer '
        . 'and no earlier profile is stored. Check that connection, then resume this run.';

    public function __construct(
        private ProfileDistillerInterface $profileDistiller,
        private RecommendationRunFailure $runFailure,
        private RecommendationTickCheckpoint $checkpoint,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /** Also after a resume of a run whose distillation degraded: it must distil again before any wave. */
    public function isPending(RecommendationRun $run): bool
    {
        return $run->getProgress()->distillPending || null === $run->getProfileText();
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        $profileTick = $tick->profileTick();
        if (null === $profileTick) {
            return $this->runFailure->fail($run, self::NO_PROFILE_CONNECTION);
        }

        $report = $this->profileDistiller->advance($profileTick);
        // A degrade records no profile and resets the attempts; a retry still pending leaves them above zero.
        if (null !== $run->getProfileText() || $run->getAttempts() > 0) {
            return $report;
        }

        return $this->fallBackToTheStoredProfile($tick);
    }

    private function fallBackToTheStoredProfile(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        $stored = $tick->settings->profileText;
        if (null === $stored) {
            return $this->runFailure->fail($run, self::NO_PROFILE);
        }

        $this->checkpoint->guard($run);
        $run->recordProfile($stored);
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }
}
