<?php

declare(strict_types=1);

namespace App\Tests\Service\Worker;

use App\Entity\ProfileRun;
use App\Enum\ProfileRunTrigger;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\Profile\ProfileRunSweep;
use App\Service\Recommendation\Run\Model\RecommendationDriverKind;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\SweepStreamHeartbeat;
use App\Service\Recommendation\Run\WorkerPresence;
use App\Service\Worker\WorkerProfileRunSweep;
use App\Tests\DbTestCase;
use App\Tests\Support\ClearTrackingEntityManager;
use App\Tests\Support\ProvidesWorkerHeartbeats;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\ThrowingClock;
use App\Tests\Support\TickingClock;
use Symfony\Component\Clock\MockClock;

final class WorkerProfileRunSweepTest extends DbTestCase
{
    use ProvidesWorkerHeartbeats;
    use SeedsUsers;

    public function testItCountsTheActiveProfileRunsItTicked(): void
    {
        $this->pendingProfileRunWithHistory('worker-profile-sweep-count@example.test');

        self::assertSame(1, $this->sweep($this->presence(), new MockClock())->sweep(
            RecommendationDriverKind::PersistentWorker,
        ));
    }

    public function testClearsTheIdentityMapEvenWhenTheSweepBodyThrows(): void
    {
        $clearTracker = new ClearTrackingEntityManager($this->entityManager);
        $presence = new WorkerPresence($this->heartbeats(), new ThrowingClock());
        $sweep = new WorkerProfileRunSweep(
            $this->profileRunSweep(),
            $presence,
            new SweepStreamHeartbeat($presence, new MockClock()),
            $clearTracker,
        );

        try {
            $sweep->sweep(RecommendationDriverKind::PersistentWorker);
            self::fail('The throwing clock must have surfaced.');
        } catch (\RuntimeException $expected) {
            self::assertSame(ThrowingClock::MESSAGE, $expected->getMessage());
        }

        self::assertTrue($clearTracker->wasCleared());
    }

    /** The first sweep opens the profile run; the second makes its call, inside which the beat must reach presence. */
    public function testItArmsTheStreamHeartbeatForTheSweepAndDisarmsItAfterwards(): void
    {
        $this->pendingProfileRunWithHistory('worker-profile-sweep-heartbeat@example.test');
        $this->sweep($this->presence(), new MockClock())->sweep(RecommendationDriverKind::PersistentWorker);
        $this->chat()->queueContent('{"profile":"Likes maps."}');

        $clock = new TickingClock(new \DateTimeImmutable('2026-10-03 12:00:00'), 10);
        $presence = new WorkerPresence($this->heartbeats(), $clock);
        $heartbeat = new SweepStreamHeartbeat($presence, $clock);
        $this->chat()->duringNextCall(static function () use ($heartbeat): void {
            $heartbeat->beat();
        });
        (new WorkerProfileRunSweep($this->profileRunSweep(), $presence, $heartbeat, $this->entityManager))
            ->sweep(RecommendationDriverKind::PersistentWorker);

        $touchedDuringTheCall = $this->touchedAt();
        self::assertGreaterThan(new \DateTimeImmutable('2026-10-03 12:00:00'), $touchedDuringTheCall);

        $heartbeat->beat();

        self::assertEquals(
            $touchedDuringTheCall,
            $this->touchedAt(),
            'Once the sweep has ended, a beat writes nothing.',
        );
    }

    private function pendingProfileRunWithHistory(string $email): void
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $owner = $this->user($email);
        $fixtures->seedReadyAiSettingsFor($owner, 'qwen3-14b');
        $fixtures->seedFavorites($owner, 'maps', 1);
        $this->entityManager->persist(
            new ProfileRun($owner, ProfileRunTrigger::Manual, new \DateTimeImmutable('2026-10-03 09:00:00')),
        );
        $this->entityManager->flush();
    }

    private function sweep(WorkerPresence $presence, MockClock $clock): WorkerProfileRunSweep
    {
        return new WorkerProfileRunSweep(
            $this->profileRunSweep(),
            $presence,
            new SweepStreamHeartbeat($presence, $clock),
            $this->entityManager,
        );
    }

    private function touchedAt(): ?\DateTimeImmutable
    {
        return $this->heartbeats()->findTouchedAt(RecommendationDriverKind::PersistentWorker->heartbeatName());
    }

    private function profileRunSweep(): ProfileRunSweep
    {
        /** @var ProfileRunSweep $sweep */
        $sweep = self::getContainer()->get(ProfileRunSweep::class);

        return $sweep;
    }

    private function presence(): WorkerPresence
    {
        /** @var WorkerPresence $presence */
        $presence = self::getContainer()->get(WorkerPresence::class);

        return $presence;
    }

    private function chat(): StubChatClient
    {
        /** @var StubChatClient $chat */
        $chat = self::getContainer()->get(StubChatClient::class);

        return $chat;
    }
}
