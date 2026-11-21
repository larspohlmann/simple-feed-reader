<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\NotificationActor;
use App\Entity\NotificationContext;
use App\Entity\NotificationSubject;
use App\Entity\SocialAccount;
use App\Entity\SocialNotification;
use App\Entity\User;
use App\Enum\SocialNotificationKind;
use App\Repository\SocialNotificationRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialNotificationRows;
use App\Tests\Support\UserFactory;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialNotificationRepositoryTest extends DbTestCase
{
    private SocialNotificationRepository $notifications;
    private SocialNotificationRows $rows;
    private UserFactory $users;
    private User $owner;
    private SocialAccount $bluesky;

    protected function setUp(): void
    {
        parent::setUp();
        $this->notifications = $this->entityManager->getRepository(SocialNotification::class);
        $this->rows = new SocialNotificationRows($this->entityManager);
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->users = new UserFactory($this->entityManager, $hasher);
        $this->owner = $this->users->create('notification-owner@example.test');
        $this->bluesky = $this->persisted(SocialAccounts::blueskyOf($this->owner));
    }

    public function testLikesOnOnePostAfterTheSeenLineAreOneGroup(): void
    {
        $this->like('at://x/1');
        $this->like('at://x/1');
        $this->like('at://x/1');

        $groups = $this->groupsOfPage();

        self::assertCount(1, $groups);
        self::assertSame(3, $groups[0]['count']);
        self::assertSame('at://x/1', $groups[0]['groupKey']);
        self::assertFalse($groups[0]['seen']);
    }

    public function testSeenAndUnseenLikesOnOnePostAreTwoGroups(): void
    {
        $this->like('at://x/1', true);
        $this->like('at://x/1', true);
        $newest = $this->like('at://x/1');

        $groups = $this->groupsOfPage();

        self::assertCount(2, $groups);
        self::assertFalse($groups[0]['seen']);
        self::assertSame($newest->requireId(), $groups[0]['latestId']);
        self::assertSame(2, $groups[1]['count']);
        self::assertTrue($groups[1]['seen']);
    }

    public function testRepliesWithTheirOwnGroupKeysStayApart(): void
    {
        $this->rows->add($this->bluesky, SocialNotificationKind::Reply, 'at://x/1');
        $this->rows->add($this->bluesky, SocialNotificationKind::Reply, 'at://x/2');

        $groups = $this->groupsOfPage();

        self::assertCount(2, $groups);
        self::assertSame([1, 1], array_column($groups, 'count'));
    }

    public function testFollowsGroupAcrossActors(): void
    {
        $this->rows->add($this->bluesky, SocialNotificationKind::Follow, '@alice.bsky.social');
        $this->rows->add($this->bluesky, SocialNotificationKind::Follow, '@alice.bsky.social');
        $this->rows->add($this->bluesky, SocialNotificationKind::Follow, '@alice.bsky.social');

        $groups = $this->groupsOfPage();

        self::assertCount(1, $groups);
        self::assertSame(3, $groups[0]['count']);
    }

    public function testCursorPagesByLatestId(): void
    {
        $this->like('at://x/1');
        $this->like('at://x/2');
        $this->like('at://x/3');
        $this->like('at://x/4');

        $first = $this->groupsOfPage(null, 2);
        self::assertCount(3, $first);

        $rest = $this->groupsOfPage($first[1]['latestId'], 2);
        self::assertCount(2, $rest);
        self::assertSame(['at://x/2', 'at://x/1'], array_column($rest, 'groupKey'));
    }

    public function testOtherUsersAccountsNeverShow(): void
    {
        $other = $this->persisted(SocialAccounts::blueskyAs(
            $this->users->create('notification-other@example.test'),
            'did:plc:other',
        ));
        $this->rows->add($other, SocialNotificationKind::Like, 'at://x/9');
        $this->like('at://x/1');

        self::assertSame(['at://x/1'], array_column($this->groupsOfPage(), 'groupKey'));
        self::assertSame(['bluesky' => 1], $this->notifications->countUnseenGroups($this->owner));
    }

    public function testCountsUnseenGroupsPerNetwork(): void
    {
        $mastodon = $this->persisted(SocialAccounts::mastodonOf($this->owner));
        $this->like('at://x/1');
        $this->like('at://x/1');
        $this->like('at://x/1');
        $this->like('at://x/2', true);
        $this->rows->add($mastodon, SocialNotificationKind::Mention, 'status-1');

        self::assertSame(['bluesky' => 1, 'mastodon' => 1], $this->notifications->countUnseenGroups($this->owner));
    }

    public function testNewestOfGroupsReturnsTheNewestRowsPerGroupNewestFirst(): void
    {
        $this->like('at://x/1');
        $this->like('at://x/1');
        $this->like('at://x/1');
        $reply = $this->rows->add($this->bluesky, SocialNotificationKind::Reply, 'at://x/1');

        $groups = $this->groupsOfPage();
        $newest = $this->notifications->findNewestPerGroup($groups, 2);

        self::assertSame([$reply->requireId()], $this->ids($newest[$groups[0]['latestId']]));
        self::assertCount(2, $newest[$groups[1]['latestId']]);
        self::assertGreaterThan(
            $newest[$groups[1]['latestId']][1]->requireId(),
            $newest[$groups[1]['latestId']][0]->requireId(),
        );
    }

    public function testNewestOfGroupsCostsTwoStatementsWhateverTheNumberOfGroups(): void
    {
        foreach (['at://x/1', 'at://x/2', 'at://x/3', 'at://x/4', 'at://x/5'] as $groupKey) {
            $this->like($groupKey);
            $this->like($groupKey);
        }
        $groups = $this->groupsOfPage();

        self::assertSame(2, $this->statementsFor(fn () => $this->notifications->findNewestPerGroup($groups, 3)));
        self::assertSame(
            2,
            $this->statementsFor(fn () => $this->notifications->findNewestPerGroup([$groups[0]], 3)),
        );
    }

    public function testNewestOfNoGroupsRunsNoStatement(): void
    {
        $newest = null;
        $statements = $this->statementsFor(function () use (&$newest): void {
            $newest = $this->notifications->findNewestPerGroup([], 3);
        });

        self::assertSame([], $newest);
        self::assertSame(0, $statements);
    }

    public function testNewestOfAGroupIgnoresRowsAboveItsLatestId(): void
    {
        $first = $this->like('at://x/1');
        $second = $this->like('at://x/1');
        $third = $this->like('at://x/1');
        $groups = $this->groupsOfPage();
        $this->like('at://x/1');

        $newest = $this->notifications->findNewestPerGroup($groups, 3);

        self::assertSame(
            [$third->requireId(), $second->requireId(), $first->requireId()],
            $this->ids($newest[$third->requireId()]),
        );
    }

    public function testNewestOfSeenAndUnseenGroupsOnOneKeyStayApart(): void
    {
        $seenFirst = $this->like('at://x/1', true);
        $seenSecond = $this->like('at://x/1', true);
        $unseen = $this->like('at://x/1');
        $groups = $this->groupsOfPage();

        $newest = $this->notifications->findNewestPerGroup($groups, 3);

        self::assertSame([$unseen->requireId()], $this->ids($newest[$unseen->requireId()]));
        self::assertSame(
            [$seenSecond->requireId(), $seenFirst->requireId()],
            $this->ids($newest[$seenSecond->requireId()]),
        );
    }

    public function testContextSurvivesAFlushAndReload(): void
    {
        $context = (new NotificationContext('my comment', 'Post title', 'c/php', 'Ann'))->withOnComment();
        $row = new SocialNotification(
            $this->bluesky,
            'remote-context',
            SocialNotificationKind::Reply,
            new NotificationActor('@a', null, null, 'https://example.test/a'),
            (new NotificationSubject('k', 'text', 'https://example.test/p'))->withContext($context),
            new \DateTimeImmutable('2026-11-16 10:00:00'),
            false,
        );
        $this->entityManager->persist($row);
        $this->entityManager->flush();
        $id = $row->requireId();
        $this->entityManager->clear();

        $reloaded = $this->entityManager->find(SocialNotification::class, $id);

        self::assertNotNull($reloaded);
        self::assertEquals($context, $reloaded->getSubject()->context);
    }

    private function like(string $groupKey, bool $seen = false): SocialNotification
    {
        return $this->rows->add($this->bluesky, SocialNotificationKind::Like, $groupKey, $seen);
    }

    /** @return list<array{accountId: int, kind: string, groupKey: string, seen: bool, latestId: int, count: int}> */
    private function groupsOfPage(?int $before = null, int $limit = 30): array
    {
        return $this->notifications->findGroupPage([$this->bluesky->requireId()], $before, $limit);
    }

    private function statementsFor(\Closure $act): int
    {
        /** @var DebugDataHolder $queries */
        $queries = self::getContainer()->get('doctrine.debug_data_holder');
        $queries->reset();
        $act();
        /** @var list<array{sql: string}> $statements */
        $statements = $queries->getData()['default'] ?? [];

        return count($statements);
    }

    private function persisted(SocialAccount $account): SocialAccount
    {
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    /**
     * @param list<SocialNotification> $rows
     * @return list<int>
     */
    private function ids(array $rows): array
    {
        return array_map(static fn (SocialNotification $row): int => $row->requireId(), $rows);
    }
}
