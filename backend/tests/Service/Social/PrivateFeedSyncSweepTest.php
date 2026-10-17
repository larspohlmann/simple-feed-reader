<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\PrivateFeedSyncSkip;
use App\Enum\SocialAccountStatus;
use App\Enum\UserStatus;
use App\Service\Social\Model\NetworkCandidatesModel;
use App\Service\Social\Model\NetworkCollectionsModel;
use App\Service\Social\Model\PrivateFeedSyncBudgetModel;
use App\Service\Social\PrivateFeedSyncSweep;
use App\Service\Social\SocialCandidates\SocialCandidatesInterface;
use App\Tests\DbTestCase;
use App\Tests\Support\ClosedEntityManager;
use App\Tests\Support\FlushFailingEntityManager;
use App\Tests\Support\PrivateFeedSyncWiring;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubSocialCandidates;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use Psr\Log\AbstractLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PrivateFeedSyncSweepTest extends DbTestCase
{
    private const string NOW = '2026-10-17 12:00:00';

    private MockClock $clock;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock(self::NOW, 'UTC');
        $this->user = $this->userNamed('sweep-owner@example.test');
    }

    public function testOnlyAccountsUncheckedForAnHourAreDueAndNeverCheckedComeFirst(): void
    {
        $recent = $this->accountOf($this->user, 'did:plc:sweep-1');
        $recent->recordSync(new \DateTimeImmutable('2026-10-17 11:30:00'));
        $stale = $this->accountOf($this->user, 'did:plc:sweep-2');
        $stale->recordSync(new \DateTimeImmutable('2026-10-17 10:30:00'));
        $never = $this->accountOf($this->user, 'did:plc:sweep-3');
        $this->entityManager->flush();
        $network = self::quietNetwork();

        $first = $this->sweepOver($network)->sweep(PrivateFeedSyncBudgetModel::accounts(1));

        self::assertSame(1, $first->checked);
        self::assertNotNull($this->reloaded($never)->getLastCheckedAt());
        self::assertSame('2026-10-17 10:30:00', $this->reloaded($stale)->getLastCheckedAt()?->format('Y-m-d H:i:s'));

        $all = $this->sweepOver($network)->sweep(PrivateFeedSyncBudgetModel::accounts(10));

        self::assertSame(1, $all->checked);
        self::assertSame('2026-10-17 11:30:00', $this->reloaded($recent)->getLastCheckedAt()?->format('Y-m-d H:i:s'));
    }

    public function testAnEmptyBudgetAsksTheNetworkNothing(): void
    {
        $this->accountOf($this->user, 'did:plc:sweep-1');
        $this->entityManager->flush();
        $network = self::quietNetwork();

        $report = $this->sweepOver($network)->sweep(PrivateFeedSyncBudgetModel::accounts(0));

        self::assertSame(0, $report->checked);
        self::assertSame(0, $network->calls);
    }

    public function testOnlyActiveUsersAccountsAreChecked(): void
    {
        $suspended = $this->userNamed('sweep-suspended@example.test', UserStatus::Suspended);
        $dormant = $this->accountOf($suspended, 'did:plc:sweep-1');
        $this->entityManager->flush();
        $network = self::quietNetwork();

        $report = $this->sweepOver($network)->sweep(PrivateFeedSyncBudgetModel::accounts(10));

        self::assertSame(0, $report->checked);
        self::assertNull($this->reloaded($dormant)->getLastCheckedAt());
    }

    public function testAnAccountThatNeedsReconnectingIsCheckedAndItsSkipIsRecorded(): void
    {
        $account = $this->accountOf($this->user, 'did:plc:sweep-1');
        $account->markNeedsReconnect();
        $this->entityManager->flush();
        $network = self::quietNetwork();

        $report = $this->sweepOver($network)->sweep(PrivateFeedSyncBudgetModel::accounts(10));

        self::assertSame(1, $report->checked);
        self::assertSame(1, $report->skipped);
        self::assertSame(PrivateFeedSyncSkip::NeedsReconnect, $this->reloaded($account)->getLastSyncSkip());
    }

    public function testAnAccountWhoseCheckThrowsIsStampedFailedAndTheSweepGoesOn(): void
    {
        $first = $this->accountOf($this->user, 'did:plc:sweep-1');
        $second = $this->accountOf($this->user, 'did:plc:sweep-2');
        $this->entityManager->flush();
        $logger = new RecordingLogger();
        $network = new StubSocialCandidates(
            new NetworkCandidatesModel([], [], [], false),
            new \ValueError('broken answer'),
        );

        $report = $this->sweepOver($network, $logger)->sweep(PrivateFeedSyncBudgetModel::accounts(10));

        self::assertSame(2, $report->checked);
        self::assertSame(2, $report->skipped);
        foreach ([$first, $second] as $account) {
            $reloaded = $this->reloaded($account);
            self::assertSame(PrivateFeedSyncSkip::Failed, $reloaded->getLastSyncSkip());
            self::assertNotNull($reloaded->getLastCheckedAt());
        }
        self::assertSame(['error', 'error'], array_column($logger->records, 'level'));
        self::assertTrue($this->entityManager->isOpen());
    }

    public function testARecordedSkipThatFailsToFlushIsLoggedAndStampedAndTheSweepGoesOn(): void
    {
        $first = $this->accountOf($this->user, 'did:plc:sweep-1');
        $second = $this->accountOf($this->user, 'did:plc:sweep-2');
        $this->entityManager->flush();
        $logger = new RecordingLogger();
        $network = new StubSocialCandidates(
            new NetworkCandidatesModel([], [], [], false),
            new \ValueError('broken answer'),
        );

        $report = $this->sweepOver(
            $network,
            $logger,
            new FlushFailingEntityManager($this->entityManager),
        )->sweep(PrivateFeedSyncBudgetModel::accounts(10));

        self::assertSame(2, $report->checked);
        self::assertSame(2, $report->skipped);
        foreach ([$first, $second] as $account) {
            self::assertSame(PrivateFeedSyncSkip::Failed, $this->reloaded($account)->getLastSyncSkip());
        }
        $messages = array_column($logger->records, 'message');
        self::assertContains('Private feed sync failed for an account.', $messages);
        self::assertCount(1, array_keys($messages, 'Private feed sync failed for an account.', true));
    }

    public function testTheSweepStopsOnceTheEntityManagerIsClosedAndCannotBeReset(): void
    {
        $this->accountOf($this->user, 'did:plc:sweep-1');
        $second = $this->accountOf($this->user, 'did:plc:sweep-2');
        $this->entityManager->flush();
        $freshManager = $this->createStub(ObjectManager::class);
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects($this->atLeastOnce())->method('resetManager')->willReturn($freshManager);
        $network = new StubSocialCandidates(
            new NetworkCandidatesModel([], [], [], false),
            new \ValueError('broken answer'),
        );
        $closed = new ClosedEntityManager($this->entityManager);
        $closed->close();

        $report = $this->sweepOver(
            $network,
            new RecordingLogger(),
            $closed,
            $registry,
        )->sweep(PrivateFeedSyncBudgetModel::accounts(10));

        self::assertSame(1, $report->checked);
        self::assertNull($this->reloaded($second)->getLastCheckedAt());
    }

    public function testTheSweepGoesOnOnceTheRegistryHasReopenedTheEntityManager(): void
    {
        $this->accountOf($this->user, 'did:plc:sweep-1');
        $second = $this->accountOf($this->user, 'did:plc:sweep-2');
        $this->entityManager->flush();
        $closed = new ClosedEntityManager($this->entityManager);
        $closed->close();
        $resets = 0;
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('resetManager')->willReturnCallback(
            function () use ($closed, &$resets): ObjectManager {
                if (++$resets > 1) {
                    $closed->reopen();
                }

                return $this->createStub(ObjectManager::class);
            },
        );
        $network = new StubSocialCandidates(
            new NetworkCandidatesModel([], [], [], false),
            new \ValueError('broken answer'),
        );

        $report = $this->sweepOver($network, new RecordingLogger(), $closed, $registry)
            ->sweep(PrivateFeedSyncBudgetModel::accounts(10));

        self::assertSame(2, $report->checked);
        self::assertNotNull($this->reloaded($second)->getLastCheckedAt());
    }

    public function testTheNextAccountStartsCleanAfterAFailureThatEscapedTheSkipRecording(): void
    {
        $first = $this->accountOf($this->user, 'did:plc:sweep-1');
        $this->accountOf($this->user, 'did:plc:sweep-2');
        $this->entityManager->flush();
        $network = new class implements SocialCandidatesInterface {
            private int $calls = 0;

            public function candidates(SocialAccount $account): NetworkCandidatesModel
            {
                return new NetworkCandidatesModel([], [], [], false);
            }

            public function collections(SocialAccount $account): NetworkCollectionsModel
            {
                if (1 === ++$this->calls) {
                    $account->markNeedsReconnect();

                    throw new \ValueError('broken answer');
                }

                return new NetworkCollectionsModel([], []);
            }
        };
        $logger = new class extends AbstractLogger {
            private int $logged = 0;

            public function log(mixed $level, string|\Stringable $message, array $context = []): void
            {
                if (1 === ++$this->logged) {
                    throw new \RuntimeException('The log is unavailable.');
                }
            }
        };

        $report = PrivateFeedSyncWiring::sweep(
            self::getContainer(),
            $this->entityManager,
            $this->clock,
            $network,
            $logger,
        )->sweep(PrivateFeedSyncBudgetModel::accounts(10));

        self::assertSame(2, $report->checked);
        self::assertSame(SocialAccountStatus::Connected, $this->reloaded($first)->getStatus());
    }

    private static function quietNetwork(): StubSocialCandidates
    {
        return new StubSocialCandidates(
            new NetworkCandidatesModel([], [], [], false),
            new NetworkCollectionsModel([], []),
        );
    }

    private function sweepOver(
        StubSocialCandidates $network,
        RecordingLogger $logger = new RecordingLogger(),
        ?EntityManagerInterface $entityManager = null,
        ?ManagerRegistry $registry = null,
    ): PrivateFeedSyncSweep {
        return PrivateFeedSyncWiring::sweep(
            self::getContainer(),
            $entityManager ?? $this->entityManager,
            $this->clock,
            $network,
            $logger,
            $registry,
        );
    }

    private function userNamed(string $email, UserStatus $status = UserStatus::Active): User
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        return (new UserFactory($this->entityManager, $hasher))->create($email, status: $status);
    }

    private function accountOf(User $owner, string $did): SocialAccount
    {
        $account = SocialAccounts::blueskyAs($owner, $did);
        $this->entityManager->persist($account);

        return $account;
    }

    private function reloaded(SocialAccount $account): SocialAccount
    {
        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(SocialAccount::class, $account->requireId());
        self::assertInstanceOf(SocialAccount::class, $reloaded);

        return $reloaded;
    }
}
