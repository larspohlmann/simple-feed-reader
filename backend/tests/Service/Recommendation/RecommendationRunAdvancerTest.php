<?php

declare(strict_types=1);

namespace App\Tests\Service\Recommendation;

use App\Entity\AiProviderSettings;
use App\Entity\Entry;
use App\Entity\Feed;
use App\Entity\RecommendationRun;
use App\Entity\Subscription;
use App\Entity\User;
use App\Repository\RecommendationRunRepository;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Service\Recommendation\RecommendationRunAdvancer;
use App\Service\Recommendation\RecommendationRunStarter;
use App\Tests\DbTestCase;
use App\Tests\Support\StubChatClient;
use App\Tests\Support\UserFactory;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Against the real repository, entity manager and lock factory, not mocks:
 * advance()'s job is to coordinate all of them, and a mock would have to
 * encode that coordination itself instead of proving it.
 */
final class RecommendationRunAdvancerTest extends DbTestCase
{
    private User $user;
    private Feed $feed;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->em, $hasher))->create('run-advancer@example.test');

        $this->feed = new Feed('https://example.com/feed.xml');
        $this->feed->setTitle('Example');
        $this->em->persist($this->feed);
        $this->em->persist(new Subscription($this->user, $this->feed, new \DateTimeImmutable('2026-07-01T00:00:00Z')));
        $this->em->flush();
    }

    public function testTickWithoutAnyRunReportsNone(): void
    {
        $report = $this->advancer()->advance($this->user);

        self::assertSame('none', $report->status);
        self::assertNull($report->batchesTotal);
        self::assertSame(0, $report->batchesDone);
        self::assertNull($report->error);
    }

    public function testSnapshotTickPartitionsCandidatesAndReportsRunning(): void
    {
        $this->seedReadyAiSettings($this->user);
        for ($i = 0; $i < 5; $i++) {
            $this->entry('entry-' . $i, sprintf('2026-07-%02dT00:00:00Z', 10 + $i));
        }
        $this->starter()->start($this->user);
        $runId = $this->runs()->findActiveForUser($this->user)?->getId();
        self::assertNotNull($runId);

        $report = $this->advancer()->advance($this->user);

        self::assertSame('running', $report->status);
        self::assertSame(1, $report->batchesTotal);
        self::assertSame(0, $report->batchesDone);
        self::assertSame([], $this->stubChatClient()->calls());

        // Proves the batch plan was actually flushed, not just set on the
        // in-memory entity the report happens to read from.
        $this->em->clear();
        $persisted = $this->em->getRepository(RecommendationRun::class)->find($runId);
        self::assertNotNull($persisted);
        self::assertSame(RecommendationRun::STATUS_RUNNING, $persisted->getStatus());
        self::assertCount(5, $persisted->getCandidateBatches()[0] ?? []);
    }

    public function testSnapshotWithZeroCandidatesCompletesEmpty(): void
    {
        $this->seedReadyAiSettings($this->user);
        $this->starter()->start($this->user);
        $runId = $this->runs()->findActiveForUser($this->user)?->getId();
        self::assertNotNull($runId);

        $report = $this->advancer()->advance($this->user);

        self::assertSame('completed', $report->status);
        self::assertSame(0, $report->batchesTotal);

        // Proves complete() was actually flushed, not just set on the
        // in-memory entity the report happens to read from.
        $this->em->clear();
        $persisted = $this->em->getRepository(RecommendationRun::class)->find($runId);
        self::assertSame(RecommendationRun::STATUS_COMPLETED, $persisted?->getStatus());
    }

    public function testBusyWhenTheLockIsHeld(): void
    {
        $userId = $this->user->getId();
        self::assertNotNull($userId);

        $lock = $this->lockFactory()->createLock('ai-recommendations-' . $userId);
        self::assertTrue($lock->acquire());

        try {
            $report = $this->advancer()->advance($this->user);

            self::assertSame('busy', $report->status);
            self::assertNull($report->batchesTotal);
            self::assertSame(0, $report->batchesDone);
            self::assertNull($report->error);
        } finally {
            $lock->release();
        }
    }

    /**
     * Pins the ?? 0 fallback in the lock name for an unsaved user (getId()
     * null): pre-acquiring 'ai-recommendations-0' must make advance() busy
     * for such a user, which only holds if the code really names the lock
     * after that fallback and not some other value.
     */
    public function testLockNameFallsBackToZeroForAnUnsavedUser(): void
    {
        $lock = $this->lockFactory()->createLock('ai-recommendations-0');
        self::assertTrue($lock->acquire());

        try {
            $unsavedUser = new User('unsaved@example.test', new \DateTimeImmutable('2026-07-01T00:00:00Z'));

            $report = $this->advancer()->advance($unsavedUser);

            self::assertSame('busy', $report->status);
        } finally {
            $lock->release();
        }
    }

    /**
     * Proves the per-user lock is released once a tick finishes: a second,
     * independent lock on the exact same resource name must be acquirable
     * right after advance() returns.
     */
    public function testAdvanceReleasesTheLockAfterATick(): void
    {
        $userId = $this->user->getId();
        self::assertNotNull($userId);

        $this->advancer()->advance($this->user);

        $lock = $this->lockFactory()->createLock('ai-recommendations-' . $userId);

        self::assertTrue($lock->acquire());
        $lock->release();
    }

    private function entry(string $guid, string $published): Entry
    {
        $entry = new Entry(
            $this->feed,
            $guid,
            'https://example.com/' . $guid,
            $guid,
            new \DateTimeImmutable('2026-07-01T00:00:00Z'),
        );
        $entry->setPublishedAt(new \DateTimeImmutable($published));
        $this->em->persist($entry);
        $this->em->flush();

        return $entry;
    }

    private function seedReadyAiSettings(User $user): void
    {
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $userId = $user->getId();
        self::assertNotNull($userId);
        $sealed = $cipher->seal($userId, 'sk-throwaway1234');
        $now = new \DateTimeImmutable('2026-08-07 09:00:00');

        $settings = new AiProviderSettings($user, 'https://api.example.test/v1', $sealed, '1234', $now);
        $this->em->persist($settings);
        $settings->chooseModel('m', $now, 32768);
        $this->em->flush();
    }

    private function runs(): RecommendationRunRepository
    {
        /** @var RecommendationRunRepository $repository */
        $repository = $this->em->getRepository(RecommendationRun::class);

        return $repository;
    }

    private function stubChatClient(): StubChatClient
    {
        /** @var StubChatClient $client */
        $client = self::getContainer()->get(StubChatClient::class);

        return $client;
    }

    private function lockFactory(): LockFactory
    {
        /** @var LockFactory $factory */
        $factory = self::getContainer()->get(LockFactory::class);

        return $factory;
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
}
