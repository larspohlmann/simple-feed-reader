<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\KnownSocialCollection;
use App\Entity\SocialAccount;
use App\Enum\SocialFeedKind;
use App\Repository\KnownSocialCollectionRepository;
use App\Service\Social\Model\PrivateFeedModel;
use App\Tests\DbTestCase;
use App\Tests\Support\SocialAccounts;
use App\Tests\Support\SocialFeeds;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class KnownSocialCollectionRepositoryTest extends DbTestCase
{
    private SocialAccount $account;
    private SocialAccount $otherAccount;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('known-collections@example.test');
        $this->account = SocialFeeds::persistedAccount($this->entityManager, SocialAccounts::blueskyOf($user));
        $this->otherAccount = SocialFeeds::persistedAccount(
            $this->entityManager,
            SocialAccounts::blueskyAs($user, 'did:plc:other'),
        );
    }

    public function testRememberedCollectionsAreKeyedByKindAndRemoteIdAndRememberingIsIdempotent(): void
    {
        $this->repository()->remember($this->account, SocialFeedKind::UserList, 'at://l/1');
        $this->repository()->remember($this->account, SocialFeedKind::CustomFeed, 'at://f/1');
        $this->entityManager->flush();
        $this->repository()->remember($this->account, SocialFeedKind::UserList, 'at://l/1');
        $this->entityManager->flush();

        self::assertEquals(
            ['list:at://l/1' => true, 'custom_feed:at://f/1' => true],
            $this->repository()->keysOf($this->account),
        );
    }

    public function testForgettingRemovesOnlyThoseCollections(): void
    {
        $this->repository()->remember($this->account, SocialFeedKind::UserList, 'at://l/1');
        $this->repository()->remember($this->account, SocialFeedKind::UserList, 'at://l/2');
        $this->repository()->remember($this->account, SocialFeedKind::CustomFeed, 'at://f/1');
        $this->repository()->remember($this->otherAccount, SocialFeedKind::UserList, 'at://l/2');
        $this->entityManager->flush();

        $this->repository()->forget(
            $this->account,
            [PrivateFeedModel::userList('at://l/1', ''), PrivateFeedModel::userList('at://l/2', '')],
        );
        $this->entityManager->clear();

        self::assertSame(['custom_feed:at://f/1' => true], $this->repository()->keysOf($this->accountAgain()));
        self::assertSame(['list:at://l/2' => true], $this->repository()->keysOf($this->otherAccount));
    }

    public function testEachAccountKeepsItsOwnMemory(): void
    {
        $this->repository()->remember($this->account, SocialFeedKind::UserList, 'at://l/1');
        $this->repository()->remember($this->otherAccount, SocialFeedKind::CustomFeed, 'at://f/9');
        $this->entityManager->flush();

        self::assertSame(['list:at://l/1' => true], $this->repository()->keysOf($this->account));
        self::assertSame(['custom_feed:at://f/9' => true], $this->repository()->keysOf($this->otherAccount));
    }

    public function testDeletingTheAccountRemovesItsMemory(): void
    {
        $this->repository()->remember($this->account, SocialFeedKind::UserList, 'at://l/1');
        $this->entityManager->flush();
        $accountId = $this->account->requireId();

        $this->entityManager->remove($this->account);
        $this->entityManager->flush();
        $this->entityManager->clear();

        self::assertSame([], $this->entityManager->getRepository(KnownSocialCollection::class)->findAll());
        self::assertNull($this->entityManager->find(SocialAccount::class, $accountId));
    }

    private function accountAgain(): SocialAccount
    {
        $account = $this->entityManager->find(SocialAccount::class, $this->account->requireId());
        self::assertInstanceOf(SocialAccount::class, $account);

        return $account;
    }

    private function repository(): KnownSocialCollectionRepository
    {
        /** @var KnownSocialCollectionRepository $repository */
        $repository = $this->entityManager->getRepository(KnownSocialCollection::class);

        return $repository;
    }
}
