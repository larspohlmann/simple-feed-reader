<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\PrivateFeedSyncChange;
use App\Entity\SocialAccount;
use App\Enum\PrivateFeedSyncReason;
use App\Enum\SocialFeedKind;
use App\Repository\PrivateFeedSyncChangeRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PrivateFeedSyncChangeRepositoryTest extends DbTestCase
{
    private SocialAccount $account;
    private SocialAccount $otherAccount;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('sync-changes@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($user));
        $this->otherAccount = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::blueskyAs($user, 'did:plc:other'),
        );
    }

    public function testTrimmingKeepsTheNewestChangesOfThatAccountOnly(): void
    {
        foreach (range(1, 12) as $minute) {
            $this->change($this->account, $minute);
        }
        $this->change($this->otherAccount, 1);
        $this->entityManager->flush();

        $this->repository()->trimTo($this->account, 10);
        $this->entityManager->clear();

        $recent = $this->repository()->recentOf([
            $this->accountAgain($this->account),
            $this->accountAgain($this->otherAccount),
        ]);

        self::assertSame(
            array_map('strval', range(12, 3)),
            $this->remoteIds($recent[$this->account->requireId()]),
        );
        self::assertCount(1, $recent[$this->otherAccount->requireId()]);
    }

    public function testRecentChangesComeNewestFirstAndAnEmptyAskIsEmpty(): void
    {
        $this->change($this->account, 2);
        $this->change($this->account, 9);
        $this->change($this->account, 5);
        $this->entityManager->flush();

        $recent = $this->repository()->recentOf([$this->account]);

        self::assertSame(['9', '5', '2'], $this->remoteIds($recent[$this->account->requireId()]));
        self::assertSame([], $this->repository()->recentOf([]));
    }

    public function testALongNameIsCutToTheColumnLength(): void
    {
        $change = new PrivateFeedSyncChange(
            $this->account,
            SocialFeedKind::UserList,
            'at://l/1',
            str_repeat('é', 600),
            PrivateFeedSyncReason::Created,
            new \DateTimeImmutable('2026-10-17 10:00:00'),
        );
        $this->entityManager->persist($change);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $stored = $this->repository()->recentOf([$this->accountAgain($this->account)])[$this->account->requireId()][0];

        self::assertSame(str_repeat('é', 512), $stored->getName());
    }

    /**
     * @param list<PrivateFeedSyncChange> $changes
     *
     * @return list<string>
     */
    private function remoteIds(array $changes): array
    {
        return array_map(static fn (PrivateFeedSyncChange $change): string => $change->getRemoteId(), $changes);
    }

    private function change(SocialAccount $account, int $minute): void
    {
        $this->entityManager->persist(new PrivateFeedSyncChange(
            $account,
            SocialFeedKind::UserList,
            (string) $minute,
            'List ' . $minute,
            PrivateFeedSyncReason::Saved,
            (new \DateTimeImmutable('2026-10-17 10:00:00'))->modify("+{$minute} minutes"),
        ));
    }

    private function accountAgain(SocialAccount $account): SocialAccount
    {
        $found = $this->entityManager->find(SocialAccount::class, $account->requireId());
        self::assertInstanceOf(SocialAccount::class, $found);

        return $found;
    }

    private function repository(): PrivateFeedSyncChangeRepository
    {
        /** @var PrivateFeedSyncChangeRepository $repository */
        $repository = $this->entityManager->getRepository(PrivateFeedSyncChange::class);

        return $repository;
    }
}
