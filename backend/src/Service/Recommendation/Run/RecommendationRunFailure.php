<?php

declare(strict_types=1);

namespace App\Service\Recommendation\Run;

use App\Entity\RecommendationRun;
use App\Repository\CallingRun;
use App\Service\Ai\Exception\ProviderRejectedRequestException;
use App\Service\Recommendation\Run\Model\RecommendationRunReportModel;
use App\Service\Recommendation\Run\Pass\TickContext;
use App\Service\Recommendation\Run\Support\ProviderFailedMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\ClockInterface;

final readonly class RecommendationRunFailure
{
    public function __construct(
        private RecommendationTickCheckpoint $checkpoint,
        private RejectedRequestRecovery $recovery,
        private EntityManagerInterface $entityManager,
        private ClockInterface $clock,
    ) {
    }

    public function fail(RecommendationRun $run, string $message): RecommendationRunReportModel
    {
        $this->checkpoint->guard($run);

        return $this->failGuarded($run, $message);
    }

    public function failRejected(
        TickContext $tick,
        ProviderRejectedRequestException $rejection,
    ): RecommendationRunReportModel {
        $this->checkpoint->guard($tick->run);
        if (
            $this->recovery->recovers(
                CallingRun::recommendationRun($tick->run->requireId()),
                $tick->connection,
                $rejection,
            )
        ) {
            return RecommendationRunReportModel::fromRun($tick->run);
        }

        return $this->failGuarded(
            $tick->run,
            ProviderFailedMessage::of($tick->connection->getBaseUrl(), $rejection->getMessage()),
        );
    }

    private function failGuarded(RecommendationRun $run, string $message): RecommendationRunReportModel
    {
        $run->fail($message, $this->clock->now());
        $this->entityManager->flush();

        return RecommendationRunReportModel::fromRun($run);
    }
}
