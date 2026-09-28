<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run\ProviderPhase;

use App\Entity\RecommendationRun;
use App\Service\Recommendation\Run\InvalidReplyRetry;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\RecommendationProfileDistiller;
use App\Service\Recommendation\Run\TickContext;
use Doctrine\ORM\EntityManagerInterface;

/** Records the distilled profile; an unusable reply is retried, then the run proceeds without a profile (#493). */
final readonly class DistillationPhase implements ProviderPhaseInterface
{
    public function __construct(
        private RecommendationProfileDistiller $distiller,
        private InvalidReplyRetry $invalidReplies,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReportModel
    {
        $run = $tick->run;
        $outcome = $this->distiller->distill($tick);

        if (!$outcome->usable) {
            return $this->invalidReplies->retryOrDegrade(
                $run,
                $outcome->requireUnusableReply(),
                fn (): RecommendationRunReportModel => $this->recordProfile($run, null),
            );
        }

        return $this->recordProfile($run, $outcome->profileText);
    }

    private function recordProfile(RecommendationRun $run, ?string $profileText): RecommendationRunReportModel
    {
        $run->recordProfile($profileText);
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }
}
