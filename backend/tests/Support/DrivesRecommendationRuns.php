<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ProfileRun;
use App\Entity\RecommendationItem;
use App\Entity\RecommendationRun;
use App\Entity\User;
use App\Repository\ProfileRunRepository;
use App\Repository\RecommendationRunRepository;
use App\Service\Recommendation\Profile\ProfileRunAdvancer;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\RecommendationRunAdvancer;
use App\Service\Recommendation\Run\RecommendationRunStarter;
use App\Service\Recommendation\Settings\RecommendationSettingsWriter;

/** For a DbTestCase that drives runs through the advancer's real dispatch, with only the providers faked. */
trait DrivesRecommendationRuns
{
    private const int MAX_TICKS = 20;

    /**
     * Starts a run and drives advance() until nothing is active, exactly the way the poll driver and the worker both
     * drain a run in production: the loop itself is the thing under test, not a shortcut around it.
     */
    private function runToCompletion(User $owner): RecommendationRun
    {
        $this->starter()->start($owner);

        return $this->tickUntilDone($owner);
    }

    private function tickUntilDone(User $owner): RecommendationRun
    {
        for ($tick = 0; $tick < self::MAX_TICKS; $tick++) {
            if (null === $this->runs()->findActiveForUser($owner)) {
                break;
            }
            $this->advancer()->advance($owner);
        }
        self::assertNull(
            $this->runs()->findActiveForUser($owner),
            'The run did not reach a terminal state within ' . self::MAX_TICKS . ' ticks.',
        );

        $run = $this->runs()->findLatestForUser($owner);
        self::assertNotNull($run);

        return $run;
    }

    /**
     * A waiting run never ticks its profile run: the profile drivers do, and this stands in for one of them, down to
     * the fresh identity map each driver pass leaves behind.
     */
    private function tickTheProfileRun(User $owner): void
    {
        /** @var ProfileRunAdvancer $profileAdvancer */
        $profileAdvancer = self::getContainer()->get(ProfileRunAdvancer::class);
        /** @var ProfileRunRepository $profileRuns */
        $profileRuns = $this->entityManager->getRepository(ProfileRun::class);
        $profileRun = $profileRuns->findActiveForUser($owner);
        if (null !== $profileRun) {
            $profileAdvancer->advance($profileRun, TickDriver::Poll);
        }
        $this->entityManager->clear();
    }

    /** @return list<RecommendationItem> */
    private function items(RecommendationRun $run): array
    {
        $this->entityManager->clear();

        /** @var list<RecommendationItem> $items */
        $items = $this->entityManager->getRepository(RecommendationItem::class)
            ->findBy(['run' => $run->requireId()], ['position' => 'ASC']);

        return $items;
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $runs */
        $runs = $this->entityManager->getRepository(RecommendationRun::class);

        return $runs;
    }

    private function starter(): RecommendationRunStarter
    {
        /** @var RecommendationRunStarter $starter */
        $starter = self::getContainer()->get(RecommendationRunStarter::class);

        return $starter;
    }

    private function advancer(): RecommendationRunAdvancer
    {
        /** @var RecommendationRunAdvancer $advancer */
        $advancer = self::getContainer()->get(RecommendationRunAdvancer::class);

        return $advancer;
    }

    private function settingsWriter(): RecommendationSettingsWriter
    {
        /** @var RecommendationSettingsWriter $writer */
        $writer = self::getContainer()->get(RecommendationSettingsWriter::class);

        return $writer;
    }

    private function chat(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
    }

    private function systemOne(): StubSystemOneClient
    {
        /** @var StubSystemOneClient $client */
        $client = self::getContainer()->get(StubSystemOneClient::class);

        return $client;
    }

    private function rerank(): StubRerankClient
    {
        /** @var StubRerankClient $client */
        $client = self::getContainer()->get(StubRerankClient::class);

        return $client;
    }
}
