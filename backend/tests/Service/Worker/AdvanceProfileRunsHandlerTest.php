<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\ProfileRun;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Run\WorkerPresence;
use App\Service\Worker\Handler\AdvanceProfileRunsHandler;
use App\Service\Worker\Message\AdvanceProfileRuns;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;

final class AdvanceProfileRunsHandlerTest extends DbTestCase
{
    use SeedsUsers;

    public function testAFiringTicksTheActiveProfileRunAsTheWorker(): void
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $owner = $this->user('advance-profile-runs@example.test');
        (new RecommendationRunFixtures($this->entityManager, $cipher))->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        $profileRun = new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00'));
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();
        $profileRunId = $profileRun->requireId();

        $this->handler()(new AdvanceProfileRuns());

        self::assertSame(
            RunStatus::Completed,
            $this->entityManager->find(ProfileRun::class, $profileRunId)?->getStatus(),
        );
        self::assertTrue($this->presence()->hasPersistentRecommendationWorker());
    }

    private function handler(): AdvanceProfileRunsHandler
    {
        /** @var AdvanceProfileRunsHandler $handler */
        $handler = self::getContainer()->get(AdvanceProfileRunsHandler::class);

        return $handler;
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }
}
