<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation\Profile;

use App\Entity\ProfileRun;
use App\Entity\RecommendationSettings;
use App\Entity\User;
use App\Enum\ProfileRunTrigger;
use App\Enum\RunStatus;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Ai\Exception\ProviderUnreachableException;
use App\Service\Recommendation\Profile\ProfileRunAdvancer;
use App\Service\Recommendation\Run\Model\TickDriver;
use App\Service\Recommendation\Run\ProviderCallHeartbeat\ProviderCallHeartbeatInterface;
use App\Service\Recommendation\Run\TickLockTtl;
use App\Service\Recommendation\Run\UserTickLock;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\SeedsUsers;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\TtlRecordingLockFactory;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;
use Symfony\Component\Lock\Store\DoctrineDbalStore;
use Symfony\Component\Lock\Store\InMemoryStore;

final class ProfileRunAdvancerTest extends DbTestCase
{
    use SeedsUsers;

    private RecommendationRunFixtures $fixtures;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $this->fixtures = new RecommendationRunFixtures($this->entityManager, $cipher);
        $this->owner = $this->user('profile-run-advancer@example.test');
        $this->fixtures->seedReadyAiSettingsFor($this->owner, 'qwen3-14b');
        $this->fixtures->seedFavorites($this->owner, 'maps', 1);
    }

    public function testItTicksTheAccountsActiveProfileRun(): void
    {
        $profileRun = $this->pendingProfileRun();
        $this->chat()->queueContent('{"profile":"Likes maps."}');

        self::assertTrue($this->advancer()->advance($this->owner, TickDriver::Poll));

        $this->entityManager->refresh($profileRun);
        self::assertSame(RunStatus::Completed, $profileRun->getStatus());
    }

    public function testWithoutAnActiveProfileRunThereIsNothingToTick(): void
    {
        self::assertFalse($this->advancer()->advance($this->owner, TickDriver::Poll));
    }

    public function testWithoutAnActiveProfileRunTheLockIsNeverTaken(): void
    {
        $lockFactory = new TtlRecordingLockFactory(new InMemoryStore());
        self::getContainer()->set(LockFactory::class, $lockFactory);

        self::assertFalse($this->advancer()->advance($this->owner, TickDriver::Poll));

        self::assertNull($lockFactory->lastLockFor(UserTickLock::nameFor($this->owner)));
    }

    public function testAHeldLockSkipsTheTurn(): void
    {
        $profileRun = $this->pendingProfileRun();
        $holder = self::lockFactory()->createLock(UserTickLock::nameFor($this->owner), 60.0);
        self::assertTrue($holder->acquire());

        try {
            self::assertFalse($this->advancer()->advance($this->owner, TickDriver::Poll));
        } finally {
            $holder->release();
        }

        $this->entityManager->refresh($profileRun);
        self::assertSame(RunStatus::Pending, $profileRun->getStatus());
    }

    public function testATickThatLostItsLockDuringTheCallStoresNothing(): void
    {
        $this->recordLocksOverTheRealStore();
        $this->fixtures->storeProfile($this->owner, 'Earlier profile.');
        $profileRun = $this->pendingProfileRun();
        $thief = null;
        $this->chat()->duringNextCall(function () use (&$thief): void {
            $thief = $this->stealTheTickLock();
            $this->providerCallHeartbeat()->beat();
        });
        $this->chat()->queueContent('{"profile":"Stolen profile."}');

        try {
            $this->advancer()->advance($this->owner, TickDriver::Poll);
        } finally {
            $thief?->release();
        }

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(ProfileRun::class, $profileRun->requireId());
        self::assertNotNull($fresh);
        self::assertSame(RunStatus::Running, $fresh->getStatus());
        self::assertSame(0, $fresh->getAttempts());
        self::assertSame('Earlier profile.', $this->storedProfileText());
    }

    public function testATickThatLostItsLockDuringAFailedCallRecordsNoStrike(): void
    {
        $this->recordLocksOverTheRealStore();
        $profileRun = $this->pendingProfileRun();
        $thief = null;
        $this->chat()->duringNextCall(function () use (&$thief): void {
            $thief = $this->stealTheTickLock();
            $this->providerCallHeartbeat()->beat();
        });
        $this->chat()->queueFailure(new ProviderUnreachableException('down'));

        try {
            $this->advancer()->advance($this->owner, TickDriver::Poll);
        } finally {
            $thief?->release();
        }

        $this->entityManager->clear();
        $fresh = $this->entityManager->find(ProfileRun::class, $profileRun->requireId());
        self::assertNotNull($fresh);
        self::assertSame(RunStatus::Running, $fresh->getStatus());
        self::assertSame(0, $fresh->getTransportFailures());
    }

    /** The profile tick sizes the shared lock by the connection it calls, not by the account's active one. */
    public function testTheLockLastsAsLongAsTheSlowProfileConnectionNeeds(): void
    {
        $lockFactory = new TtlRecordingLockFactory(new InMemoryStore());
        self::getContainer()->set(LockFactory::class, $lockFactory);
        $slow = $this->fixtures->seedInactiveAiSettingsFor($this->owner, 'profile-llm');
        $slow->setSlowModel(true);
        $this->fixtures->chooseProfileConnection($this->owner, $slow);
        $this->pendingProfileRun();
        $this->chat()->queueContent('{"profile":"Likes maps."}');

        $this->advancer()->advance($this->owner, TickDriver::Poll);

        self::assertSame(
            900.0 + TickLockTtl::MARGIN_SECONDS,
            $lockFactory->lastTtlFor(UserTickLock::nameFor($this->owner)),
        );
    }

    private function pendingProfileRun(): ProfileRun
    {
        $profileRun = new ProfileRun(
            $this->owner,
            ProfileRunTrigger::Manual,
            new \DateTimeImmutable('2026-10-03 09:00:00'),
        );
        $this->entityManager->persist($profileRun);
        $this->entityManager->flush();

        return $profileRun;
    }

    private function storedProfileText(): ?string
    {
        /** @var RecommendationSettings|null $row */
        $row = $this->entityManager->getRepository(RecommendationSettings::class)
            ->findOneBy(['user' => $this->owner->requireId()]);

        return $row?->getStoredProfile()->getText();
    }

    /** Swapped in before the advancer is built, so the lock the container wires holds over the real store. */
    private function recordLocksOverTheRealStore(): void
    {
        self::getContainer()->set(
            LockFactory::class,
            new TtlRecordingLockFactory(new DoctrineDbalStore($this->entityManager->getConnection())),
        );
    }

    private function stealTheTickLock(): SharedLockInterface
    {
        $this->entityManager->getConnection()->executeStatement('DELETE FROM lock_keys');
        $thief = (new LockFactory(new DoctrineDbalStore($this->entityManager->getConnection())))
            ->createLock(UserTickLock::nameFor($this->owner), 60.0);
        self::assertTrue($thief->acquire());

        return $thief;
    }

    private function providerCallHeartbeat(): ProviderCallHeartbeatInterface
    {
        /** @var ProviderCallHeartbeatInterface $heartbeat */
        $heartbeat = self::getContainer()->get(ProviderCallHeartbeatInterface::class);

        return $heartbeat;
    }

    private static function lockFactory(): LockFactory
    {
        /** @var LockFactory $lockFactory */
        $lockFactory = self::getContainer()->get(LockFactory::class);

        return $lockFactory;
    }

    private function advancer(): ProfileRunAdvancer
    {
        /** @var ProfileRunAdvancer $advancer */
        $advancer = self::getContainer()->get(ProfileRunAdvancer::class);

        return $advancer;
    }

    private function chat(): StubChatClient
    {
        /** @var StubChatClient $chat */
        $chat = self::getContainer()->get(StubChatClient::class);

        return $chat;
    }
}
