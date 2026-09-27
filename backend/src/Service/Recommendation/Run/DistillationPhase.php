<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use Doctrine\ORM\EntityManagerInterface;

/** Records the distilled profile; an unusable reply is retried, then the run proceeds without a profile (#493). */
final readonly class DistillationPhase implements ProviderPhase
{
    public function __construct(
        private RecommendationProfileDistiller $distiller,
        private InvalidReplyRetry $invalidReplies,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function advance(TickContext $tick): RecommendationRunReport
    {
        $run = $tick->run;
        $outcome = $this->distiller->distill($tick);

        if (!$outcome->usable) {
            return $this->invalidReplies->retryOrDegrade(
                $run,
                $outcome->requireUnusableReply(),
                fn (): RecommendationRunReport => $this->recordProfile($run, null),
            );
        }

        return $this->recordProfile($run, $outcome->profileText);
    }

    private function recordProfile(RecommendationRun $run, ?string $profileText): RecommendationRunReport
    {
        $run->recordProfile($profileText);
        $this->entityManager->flush();

        return RecommendationRunReport::fromRun($run);
    }
}
