<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Run\Factory;

use App\Entity\RecommendationRun;
use App\Repository\RecommendationRunLogRepository;
use App\Service\Ai\Completion\Model\CompletionRequestModel;
use App\Service\Ai\Completion\Model\JsonSchemaModel;
use App\Service\Ai\Completion\Model\Reasoning;
use App\Service\Recommendation\Run\Factory\RecommendationRunLogFactory;
use App\Service\Recommendation\Run\Model\CallSlotModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SeedsUsers;
use Symfony\Component\Clock\MockClock;

final class RecommendationRunLogFactoryTest extends DbTestCase
{
    use SeedsUsers;

    public function testTheRequestIsRenderedAsSentWithoutTheTransportFraming(): void
    {
        $log = $this->factory()->create($this->newRun(), CallSlotModel::batch(1), $this->request());

        self::assertSame(
            "{\n    \"model\": \"m\",\n    \"messages\": [\n        {\n            \"role\": \"user\",\n"
            . "            \"content\": \"héllo/wörld\"\n        }\n    ]\n}",
            $log->getRequestBody(),
        );
    }

    public function testASlotsNextCallIsNumberedAfterTheAttemptsItRecorded(): void
    {
        $run = $this->newRun();
        $first = $this->factory()->create($run, CallSlotModel::batch(2), $this->request());
        $this->em->persist($first);
        $this->em->flush();

        $second = $this->factory()->create($run, CallSlotModel::batch(2), $this->request());

        self::assertSame(1, $first->getAttempt());
        self::assertSame(2, $second->getAttempt());
    }

    private function newRun(): RecommendationRun
    {
        $run = new RecommendationRun(
            $this->user('log-factory@example.test'),
            new \DateTimeImmutable('2026-08-08T09:00:00Z'),
        );
        $this->em->persist($run);
        $this->em->flush();

        return $run;
    }

    private function request(): CompletionRequestModel
    {
        return new CompletionRequestModel(
            'm',
            [['role' => 'user', 'content' => 'héllo/wörld']],
            1024,
            new JsonSchemaModel('test', ['type' => 'object']),
            Reasoning::Allowed,
        );
    }

    private function factory(): RecommendationRunLogFactory
    {
        /** @var RecommendationRunLogRepository $logs */
        $logs = self::getContainer()->get(RecommendationRunLogRepository::class);

        return new RecommendationRunLogFactory($logs, new MockClock('2026-08-08T10:00:00Z'));
    }
}
