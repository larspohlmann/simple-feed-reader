<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification;

use App\Entity\NotificationActor;
use App\Entity\NotificationContext;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Entity\SocialNotificationPoll;
use App\Entity\User;
use App\Enum\SocialAccountStatus;
use App\Enum\SocialNotificationKind;
use App\Repository\SocialNotificationPollRepository;
use App\Service\Outbound\Fetch\HostThrottle;
use App\Service\Social\Notification\Model\FetchedNotificationModel;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\Model\NotificationPollBudgetModel;
use App\Service\Social\Notification\Model\NotificationPollReportModel;
use App\Service\Social\Notification\NotificationContextSource\NotificationContextSourceInterface;
use App\Service\Social\Notification\NotificationPollSweep;
use App\Service\Social\Notification\NotificationSource\NotificationSourceInterface;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Service\Social\Social\Exception\SocialTokenScopeMissingException;
use App\Tests\DbTestCase;
use App\Tests\Support\NotificationPollWiring;
use App\Tests\Support\RecordingContextSource;
use App\Tests\Support\RecordingLogger;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubNotificationSource;
use App\Tests\Support\UserFactory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NotificationPollSweepTest extends DbTestCase
{
    private MockClock $clock;
    private HostThrottle $throttle;
    private SocialNotificationPollRepository $polls;
    private User $owner;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-11-17 12:00:00', 'UTC');
        $this->throttle = NotificationPollWiring::throttle($this->clock);
        $this->logger = new RecordingLogger();
        $polls = $this->entityManager->getRepository(SocialNotificationPoll::class);
        self::assertInstanceOf(SocialNotificationPollRepository::class, $polls);
        $this->polls = $polls;
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->owner = new UserFactory($this->entityManager, $hasher)->create('poll-sweep-owner@example.test');
    }

    public function testPollsDueAccountsOnly(): void
    {
        $due = $this->account('did:plc:due');
        $recent = $this->account('did:plc:recent');
        $this->polls->ensureStamp($recent)->recordChecked($this->clock->now()->modify('-4 minutes'));
        $this->entityManager->flush();
        $source = new StubNotificationSource();

        $report = $this->sweep($source)->sweep(NotificationPollBudgetModel::accounts(10));

        self::assertSame([$due->getIdentity()], $source->fetchedIdentities);
        self::assertSame(1, $report->accounts);
    }

    public function testScopeMissingFlagsTheAccountAndContinues(): void
    {
        $first = $this->account('did:plc:scope-first');
        $second = $this->account('did:plc:scope-second');
        $source = new StubNotificationSource([
            'did:plc:scope-first' => new SocialTokenScopeMissingException('no scope', 'read:notifications'),
            'did:plc:scope-second' => new NotificationPageModel([$this->fetched('n1', '2026-11-17 11:00:00')], null),
        ]);

        $report = $this->sweep($source)->sweep(NotificationPollBudgetModel::accounts(10));

        $this->entityManager->clear();
        self::assertTrue($this->stampOf($first)->isScopeMissing());
        self::assertSame(SocialAccountStatus::Connected, $this->reloaded($first)->getStatus());
        self::assertFalse($this->stampOf($second)->isScopeMissing());
        self::assertNotNull($this->stampOf($second)->getCheckedAt());
        self::assertCount(1, $this->entityManager->getRepository(SocialNotification::class)->findAll());
        self::assertSame(0, $report->failed);
        self::assertSame(1, $report->newRows);
    }

    public function testThrottledHostIsStampedSoOthersGetTheirTurn(): void
    {
        $throttled = $this->account('did:plc:throttled');
        $other = SocialAccounts::mastodonAt($this->owner, 'bob@other.example', 'https://other.example');
        $this->entityManager->persist($other);
        $this->entityManager->flush();
        $this->throttle->record($throttled->getServiceUrl(), 300);
        $blueskySource = new StubNotificationSource();
        $mastodonSource = new StubNotificationSource();
        $sweep = $this->sweepOver(['bluesky' => $blueskySource, 'mastodon' => $mastodonSource]);

        $sweep->sweep(NotificationPollBudgetModel::accounts(1));

        self::assertSame([], $blueskySource->fetchedIdentities);
        self::assertNotNull($this->stampOf($throttled)->getCheckedAt());
        self::assertSame(
            [$other->requireId()],
            $this->polls->findAccountIdsDue($this->clock->now(), 10),
        );
    }

    public function testThrottleRecordsTheHostWait(): void
    {
        $account = $this->account('did:plc:rate-limited');
        $source = new StubNotificationSource([
            'did:plc:rate-limited' => new SocialNetworkThrottledException('slow down', 120),
        ]);

        $this->sweep($source)->sweep(NotificationPollBudgetModel::accounts(10));

        self::assertGreaterThan(0, $this->throttle->remainingSeconds($account->getServiceUrl()));
        self::assertNotNull($this->stampOf($account)->getCheckedAt());
    }

    public function testOneAccountFailingDoesNotStopTheSweep(): void
    {
        $first = $this->account('did:plc:boom');
        $second = $this->account('did:plc:fine');
        $source = new StubNotificationSource(['did:plc:boom' => new \RuntimeException('boom')]);

        $report = $this->sweep($source)->sweep(NotificationPollBudgetModel::accounts(10));

        self::assertSame(['did:plc:boom', 'did:plc:fine'], $source->fetchedIdentities);
        self::assertSame(1, $report->failed);
        self::assertSame(2, $report->accounts);
        self::assertNotNull($this->stampOf($first)->getCheckedAt());
        self::assertNotNull($this->stampOf($second)->getCheckedAt());
        self::assertSame('Notification poll failed for an account.', $this->logger->records[0]['message']);
        self::assertSame($first->requireId(), $this->logger->records[0]['context']['socialAccountId']);
    }

    public function testAFailureThatClosesTheManagerStillStampsTheAccount(): void
    {
        $first = $this->account('did:plc:closes-manager');
        $second = $this->account('did:plc:after-closed');
        $entityManager = $this->entityManager;
        $source = new class ($entityManager) implements NotificationSourceInterface {
            public function __construct(private readonly EntityManagerInterface $entityManager)
            {
            }

            public function fetch(SocialAccount $account): NotificationPageModel
            {
                if ($account->getIdentity() === 'did:plc:closes-manager') {
                    $this->entityManager->close();

                    throw new \RuntimeException('the unit of work is gone');
                }

                return new NotificationPageModel([], null);
            }

            public function markSeen(SocialAccount $account, array $unseen, \Closure $marked): void
            {
            }
        };

        $report = $this->sweepOver(NotificationPollWiring::allNetworks($source))
            ->sweep(NotificationPollBudgetModel::accounts(10));

        self::assertSame(1, $report->failed);
        self::assertNotNull($this->stampOf($first)->getCheckedAt());
        self::assertNotNull($this->stampOf($second)->getCheckedAt());
    }

    public function testPrunesAfterTheAccounts(): void
    {
        $account = $this->account('did:plc:prune');
        $this->entityManager->persist($this->storedNotification($account, 'old', '2026-10-17 11:00:00'));
        $this->entityManager->persist($this->storedNotification($account, 'recent', '2026-11-10 11:00:00'));
        $this->entityManager->flush();

        $report = $this->sweep(new StubNotificationSource())->sweep(NotificationPollBudgetModel::accounts(10));

        self::assertSame(1, $report->pruned);
        $remaining = $this->entityManager->getRepository(SocialNotification::class)->findAll();
        self::assertSame(['recent'], array_map(
            static fn (SocialNotification $row): string => $row->getRemoteId(),
            $remaining,
        ));
    }

    public function testAnEmptyBudgetDoesNothingAtAll(): void
    {
        $this->account('did:plc:idle');
        $source = new StubNotificationSource();

        $report = $this->sweep($source)->sweep(NotificationPollBudgetModel::accounts(0));

        self::assertEquals(NotificationPollReportModel::none(), $report);
        self::assertSame([], $source->fetchedIdentities);
    }

    public function testContextIsLookedUpOnlyForNewRows(): void
    {
        $this->account('did:plc:context-new-rows');
        $first = new NotificationPageModel([
            $this->fetched('r1', '2026-11-17 11:00:00'),
            $this->fetched('r2', '2026-11-17 11:01:00'),
        ], null);
        $later = new NotificationPageModel(
            [...$first->notifications, $this->fetched('r3', '2026-11-17 12:01:00')],
            null,
        );
        $contextSource = new RecordingContextSource();

        $this->sweepWithContext(new StubNotificationSource(['did:plc:context-new-rows' => $first]), $contextSource)
            ->sweep(NotificationPollBudgetModel::accounts(10));
        self::assertSame([['r1', 'r2']], $contextSource->asked);

        $this->clock->modify('+6 minutes');
        $laterSource = new StubNotificationSource(['did:plc:context-new-rows' => $later]);
        $laterSweep = $this->sweepWithContext($laterSource, $contextSource);
        $laterSweep->sweep(NotificationPollBudgetModel::accounts(10));
        self::assertSame([['r1', 'r2'], ['r3']], $contextSource->asked);

        $this->clock->modify('+6 minutes');
        $laterSweep->sweep(NotificationPollBudgetModel::accounts(10));
        self::assertCount(2, $laterSource->fetchedIdentities);
        self::assertCount(2, $contextSource->asked);
    }

    public function testTheStoredRowCarriesTheContext(): void
    {
        $this->account('did:plc:context-stored');
        $page = new NotificationPageModel([$this->fetched('r1', '2026-11-17 11:00:00')], null);

        $this->sweepWithContext(
            new StubNotificationSource(['did:plc:context-stored' => $page]),
            new RecordingContextSource(new NotificationContext('You said')),
        )->sweep(NotificationPollBudgetModel::accounts(10));

        $this->entityManager->clear();
        $stored = $this->entityManager->getRepository(SocialNotification::class)->findAll();
        self::assertCount(1, $stored);
        self::assertSame('You said', $stored[0]->getSubject()->context->answeredExcerpt);
    }

    private function sweep(NotificationSourceInterface $source): NotificationPollSweep
    {
        return $this->sweepOver(NotificationPollWiring::allNetworks($source));
    }

    /** @param array<string, NotificationSourceInterface> $sources */
    private function sweepOver(array $sources): NotificationPollSweep
    {
        return NotificationPollWiring::sweep(
            self::getContainer(),
            $this->entityManager,
            $this->clock,
            $this->throttle,
            $sources,
            $this->logger,
        );
    }

    private function sweepWithContext(
        StubNotificationSource $source,
        NotificationContextSourceInterface $contextSource,
    ): NotificationPollSweep {
        return NotificationPollWiring::sweep(
            self::getContainer(),
            $this->entityManager,
            $this->clock,
            $this->throttle,
            NotificationPollWiring::allNetworks($source),
            $this->logger,
            contextSource: $contextSource,
        );
    }

    private function account(string $did): SocialAccount
    {
        $account = SocialAccounts::blueskyAs($this->owner, $did);
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function stampOf(SocialAccount $account): SocialNotificationPoll
    {
        $this->entityManager->clear();
        $stamp = $this->polls->findOneBy(['socialAccount' => $account->requireId()]);
        self::assertNotNull($stamp);

        return $stamp;
    }

    private function reloaded(SocialAccount $account): SocialAccount
    {
        $reloaded = $this->entityManager->find(SocialAccount::class, $account->requireId());
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function fetched(string $remoteId, string $createdAt): FetchedNotificationModel
    {
        return new FetchedNotificationModel(
            $remoteId,
            SocialNotificationKind::Like,
            new NotificationActor('@bob', null, null, 'https://bsky.app/profile/bob'),
            new NotificationSubject('post-1', null, 'https://bsky.app/post-1'),
            new \DateTimeImmutable($createdAt),
            false,
        );
    }

    private function storedNotification(SocialAccount $account, string $remoteId, string $createdAt): SocialNotification
    {
        $fetched = $this->fetched($remoteId, $createdAt);

        return new SocialNotification(
            $account,
            $fetched->remoteId,
            $fetched->kind,
            $fetched->actor,
            $fetched->subject,
            $fetched->createdAt,
            false,
        );
    }
}
