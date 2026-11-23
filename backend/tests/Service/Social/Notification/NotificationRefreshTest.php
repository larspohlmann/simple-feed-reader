<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification;

use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Entity\SocialNotificationPoll;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use App\Repository\SocialAccountRepository;
use App\Service\Social\Notification\Model\NotificationPageModel;
use App\Service\Social\Notification\NotificationRefresh;
use App\Service\Social\Social\Exception\SocialNetworkUnreachableException;
use App\Tests\DbTestCase;
use App\Tests\Support\NotificationPollWiring;
use App\Tests\Support\NotificationRows;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\StubNotificationSource;
use App\Tests\Support\UserFactory;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class NotificationRefreshTest extends DbTestCase
{
    private MockClock $clock;
    private UserFactory $users;
    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-11-23 15:30:00', 'UTC');
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->users = new UserFactory($this->entityManager, $hasher);
        $this->owner = $this->users->create('notification-refresh-owner@example.test');
    }

    public function testRefreshStoresWhatEachConnectedAccountOnTheNetworkFetched(): void
    {
        $first = $this->persisted(SocialAccounts::blueskyAs($this->owner, 'did:plc:refresh-first'));
        $second = $this->persisted(SocialAccounts::blueskyAs($this->owner, 'did:plc:refresh-second'));
        $source = new StubNotificationSource([
            'did:plc:refresh-first' => $this->pageWith('first-follow'),
            'did:plc:refresh-second' => $this->pageWith('second-follow'),
        ]);

        $this->refreshOver($source)->refresh($this->owner, SocialNetwork::Bluesky);

        $this->entityManager->clear();
        $stored = array_map(
            static fn (SocialNotification $row): string => $row->getRemoteId(),
            $this->entityManager->getRepository(SocialNotification::class)->findAll(),
        );
        sort($stored);
        self::assertSame(['first-follow', 'second-follow'], $stored);
        self::assertEquals(new \DateTimeImmutable('2026-11-23 15:30:00'), $this->checkedAt($first));
        self::assertEquals(new \DateTimeImmutable('2026-11-23 15:30:00'), $this->checkedAt($second));
    }

    public function testRefreshSkipsAccountsOnOtherNetworksAndOfOtherUsers(): void
    {
        $this->persisted(SocialAccounts::blueskyAs($this->owner, 'did:plc:refresh-own'));
        $this->persisted(SocialAccounts::mastodonOf($this->owner));
        $other = $this->users->create('notification-refresh-other@example.test');
        $this->persisted(SocialAccounts::blueskyAs($other, 'did:plc:refresh-other'));
        $source = new StubNotificationSource();

        $this->refreshOver($source)->refresh($this->owner, SocialNetwork::Bluesky);

        self::assertSame(['did:plc:refresh-own'], $source->fetchedIdentities);
    }

    public function testRefreshSkipsAnAccountThatNeedsReconnecting(): void
    {
        $account = SocialAccounts::blueskyAs($this->owner, 'did:plc:refresh-stale');
        $account->markNeedsReconnect();
        $this->persisted($account);
        $source = new StubNotificationSource();

        $this->refreshOver($source)->refresh($this->owner, SocialNetwork::Bluesky);

        self::assertSame([], $source->fetchedIdentities);
    }

    public function testRefreshLetsANetworkFailureThrough(): void
    {
        $this->persisted(SocialAccounts::blueskyAs($this->owner, 'did:plc:refresh-down'));
        $source = new StubNotificationSource([
            'did:plc:refresh-down' => new SocialNetworkUnreachableException('Bluesky turned this request down.'),
        ]);

        $this->expectException(SocialNetworkUnreachableException::class);

        $this->refreshOver($source)->refresh($this->owner, SocialNetwork::Bluesky);
    }

    private function refreshOver(StubNotificationSource $source): NotificationRefresh
    {
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        self::assertInstanceOf(SocialAccountRepository::class, $accounts);

        return new NotificationRefresh(
            $accounts,
            NotificationPollWiring::poll(
                $this->entityManager,
                $this->clock,
                NotificationPollWiring::throttle($this->clock),
                NotificationPollWiring::allNetworks($source),
            ),
        );
    }

    private function pageWith(string $remoteId): NotificationPageModel
    {
        return new NotificationPageModel([NotificationRows::fetched($remoteId, SocialNotificationKind::Follow)], null);
    }

    private function checkedAt(SocialAccount $account): ?\DateTimeImmutable
    {
        return $this->entityManager->getRepository(SocialNotificationPoll::class)
            ->findOneBy(['socialAccount' => $account->requireId()])
            ?->getCheckedAt();
    }

    private function persisted(SocialAccount $account): SocialAccount
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }
}
