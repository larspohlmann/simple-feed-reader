<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Notification;

use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Enum\SocialNotificationKind;
use App\Repository\SocialAccountRepository;
use App\Repository\SocialNotificationRepository;
use App\Service\Social\Notification\NotificationSeenMarker;
use App\Service\Social\Notification\NotificationSource\NotificationSourceInterface;
use App\Service\Social\Social\ActingAccountGuard;
use App\Service\Social\Social\Exception\SocialNetworkThrottledException;
use App\Tests\DbTestCase;
use App\Tests\Support\MarkingNotificationSource;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialNotificationRows;
use Symfony\Component\DependencyInjection\ServiceLocator;

final class NotificationSeenMarkerTest extends DbTestCase
{
    private User $owner;
    private SocialNotificationRows $rows;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = new User('marker-owner@example.com', new \DateTimeImmutable('2026-07-01 10:00:00'));
        $this->entityManager->persist($this->owner);
        $this->entityManager->flush();
        $this->rows = new SocialNotificationRows($this->entityManager);
    }

    public function testMarksNothingNewerThanUpTo(): void
    {
        $account = $this->persisted(SocialAccounts::blueskyOf($this->owner));
        $first = $this->rows->add($account, SocialNotificationKind::Like, 'a');
        $second = $this->rows->add($account, SocialNotificationKind::Like, 'b');
        $third = $this->rows->add($account, SocialNotificationKind::Like, 'c');
        $source = new MarkingNotificationSource();

        $this->marker([SocialNetwork::Bluesky->value => $source])
            ->markSeen($this->owner, SocialNetwork::Bluesky, $second->requireId());

        self::assertSame([$first->requireId(), $second->requireId()], $source->handed[$account->getIdentity()]);
        $this->entityManager->clear();
        self::assertSame([true, true, false], $this->seenStates([$first, $second, $third]));
    }

    public function testMarksEveryAccountOnTheNetworkOnly(): void
    {
        $firstBluesky = $this->persisted(SocialAccounts::blueskyOf($this->owner));
        $secondBluesky = $this->persisted(SocialAccounts::secondBlueskyOf($this->owner));
        $mastodon = $this->persisted(SocialAccounts::mastodonOf($this->owner));
        $rows = [
            $this->rows->add($firstBluesky, SocialNotificationKind::Like, 'a'),
            $this->rows->add($secondBluesky, SocialNotificationKind::Like, 'b'),
            $this->rows->add($mastodon, SocialNotificationKind::Like, 'c'),
        ];
        $bluesky = new MarkingNotificationSource();
        $mastodonSource = new MarkingNotificationSource();

        $this->marker([
            SocialNetwork::Bluesky->value => $bluesky,
            SocialNetwork::Mastodon->value => $mastodonSource,
        ])->markSeen($this->owner, SocialNetwork::Bluesky, $rows[2]->requireId());

        self::assertCount(2, $bluesky->handed);
        self::assertSame([], $mastodonSource->handed);
        $this->entityManager->clear();
        self::assertSame([true, true, false], $this->seenStates($rows));
    }

    public function testLemmyThrottleKeepsWhatWasMarked(): void
    {
        $account = $this->persisted(SocialAccounts::lemmyOf($this->owner));
        $rows = [];
        for ($index = 0; $index < 4; ++$index) {
            $rows[] = $this->rows->add($account, SocialNotificationKind::Reply, 'c' . $index);
        }
        $source = new MarkingNotificationSource(2, new SocialNetworkThrottledException('slow down'));
        $marker = $this->marker([SocialNetwork::Lemmy->value => $source]);

        try {
            $marker->markSeen($this->owner, SocialNetwork::Lemmy, $rows[3]->requireId());
            self::fail('The throttle should propagate.');
        } catch (SocialNetworkThrottledException) {
            $this->entityManager->clear();
        }

        self::assertSame([true, true, false, false], $this->seenStates($rows));
    }

    public function testAnAccountNeedingReconnectIsLeftUnseenWithoutStoppingTheRest(): void
    {
        $broken = SocialAccounts::blueskyOf($this->owner);
        $broken->markNeedsReconnect();
        $broken = $this->persisted($broken);
        $connected = $this->persisted(SocialAccounts::secondBlueskyOf($this->owner));
        $brokenRow = $this->rows->add($broken, SocialNotificationKind::Like, 'a');
        $connectedRow = $this->rows->add($connected, SocialNotificationKind::Like, 'b');
        $source = new MarkingNotificationSource();

        $this->marker([SocialNetwork::Bluesky->value => $source])
            ->markSeen($this->owner, SocialNetwork::Bluesky, $connectedRow->requireId());

        $this->entityManager->clear();
        self::assertSame([false, true], $this->seenStates([$brokenRow, $connectedRow]));
    }

    public function testNoUnseenRowsCallsNoNetwork(): void
    {
        $account = $this->persisted(SocialAccounts::blueskyOf($this->owner));
        $row = $this->rows->add($account, SocialNotificationKind::Like, 'a', true);
        $source = new MarkingNotificationSource();

        $this->marker([SocialNetwork::Bluesky->value => $source])
            ->markSeen($this->owner, SocialNetwork::Bluesky, $row->requireId());

        self::assertSame([], $source->handed);
    }

    /** @param array<string, NotificationSourceInterface> $sources */
    private function marker(array $sources): NotificationSeenMarker
    {
        $accounts = $this->entityManager->getRepository(SocialAccount::class);
        self::assertInstanceOf(SocialAccountRepository::class, $accounts);
        $notifications = $this->entityManager->getRepository(SocialNotification::class);
        self::assertInstanceOf(SocialNotificationRepository::class, $notifications);

        return new NotificationSeenMarker(
            new ServiceLocator(array_map(
                static fn (NotificationSourceInterface $source): \Closure => static fn () => $source,
                $sources,
            )),
            new ActingAccountGuard($this->entityManager),
            $accounts,
            $notifications,
            $this->entityManager,
        );
    }

    private function persisted(SocialAccount $account): SocialAccount
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    /**
     * @param list<SocialNotification> $rows
     * @return list<bool>
     */
    private function seenStates(array $rows): array
    {
        $notifications = $this->entityManager->getRepository(SocialNotification::class);

        return array_map(
            static fn (SocialNotification $row): bool => $notifications->find($row->requireId())?->isSeen() ?? false,
            $rows,
        );
    }
}
