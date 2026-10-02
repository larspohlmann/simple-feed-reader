<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\Factory;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Recommendation\Run\Factory\RecommendationRunLogFactory;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\MockClock;

final class RecommendationRunLogFactoryTest extends DbTestCase
{
    use SeedsUsers;

    public function testTheRenderedRequestIsStoredAsGiven(): void
    {
        $log = $this->factory()->create($this->newRun(), CallSlotModel::batch(1), '{"model": "jév"}');

        self::assertSame('{"model": "jév"}', $log->getRequestBody());
    }

    public function testASlotsNextCallIsNumberedAfterTheAttemptsItRecorded(): void
    {
        $run = $this->newRun();
        $first = $this->factory()->create($run, CallSlotModel::batch(2), '{"model": "m"}');
        $this->entityManager->persist($first);
        $this->entityManager->flush();

        $second = $this->factory()->create($run, CallSlotModel::batch(2), '{"model": "m"}');

        self::assertSame(1, $first->getAttempt());
        self::assertSame(2, $second->getAttempt());
    }

    private function newRun(): RecommendationRun
    {
        $run = new RecommendationRun(
            $this->user('log-factory@example.test'),
            new \DateTimeImmutable('2026-08-08T09:00:00Z'),
        );
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return $run;
    }

    private function factory(): RecommendationRunLogFactory
    {
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);

        return new RecommendationRunLogFactory($logs, new MockClock('2026-08-08T10:00:00Z'));
    }
}
