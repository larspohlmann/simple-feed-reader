<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Entity\AiProviderSettings;
use App\Entity\Feed;
use App\Entity\Subscription;
use App\Entity\User;
use App\Exception\LastAdminException;
use App\Exception\ValidationException;
use App\Service\Account\AccountDeleter;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;

final class AccountDeleterTest extends DbTestCase
{
    private const string NOW = '2026-07-01 10:00:00';

    private AccountDeleter $deleter;
    private UserFactory $users;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deleter = self::getContainer()->get(AccountDeleter::class);
        $this->users = new UserFactory(
            $this->em,
            self::getContainer()->get('security.user_password_hasher'),
        );
    }

    public function testAdminDeletionRemovesTheAccount(): void
    {
        $admin = $this->users->create('admin@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->users->create('target@example.com');
        $targetId = (int) $target->getId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->find($targetId));
    }

    public function testDeletionTakesTheAccountsSubscriptionsAndItsSoleFeed(): void
    {
        $admin = $this->users->create('admin-2@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->users->create('target-2@example.com');
        $feed = new Feed('https://only-theirs.example.com/rss');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($target, $feed, new \DateTimeImmutable(self::NOW)));
        $this->em->flush();
        $feedId = (int) $feed->getId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->em->clear();
        self::assertNull($this->em->getRepository(Feed::class)->find($feedId));
        self::assertSame(0, (int) $this->em->createQuery(
            'SELECT COUNT(s.id) FROM App\Entity\Subscription s',
        )->getSingleScalarResult());
    }

    public function testDeletionKeepsAFeedAnotherUserStillReads(): void
    {
        $admin = $this->users->create('admin-3@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->users->create('target-3@example.com');
        $stayer = $this->users->create('stayer@example.com');
        $feed = new Feed('https://shared-2.example.com/rss');
        $this->em->persist($feed);
        $this->em->persist(new Subscription($target, $feed, new \DateTimeImmutable(self::NOW)));
        $this->em->persist(new Subscription($stayer, $feed, new \DateTimeImmutable(self::NOW)));
        $this->em->flush();
        $feedId = (int) $feed->getId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Feed::class)->find($feedId));
    }

    /**
     * The User <-> AiProviderSettings relation is unidirectional (#334 review):
     * User carries only the active pointer, not an inverse Collection, so
     * nothing in the ORM's own unit-of-work graph walks from the removed User
     * to its configuration rows. This proves the DB-level FK ON DELETE CASCADE
     * on user_ai_settings.user_id — which AccountDeleter's class doc now
     * lists — really does the cleanup on its own.
     */
    public function testDeletionTakesTheAccountsAiConfigurations(): void
    {
        $admin = $this->users->create('admin-ai@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->users->create('target-ai@example.com');
        /** @var ApiKeyCipher $cipher */
        $cipher = self::getContainer()->get(ApiKeyCipher::class);
        $sealed = $cipher->seal((int) $target->getId(), 'sk-throwaway1234');
        $configuration = new AiProviderSettings(
            $target,
            'Work OpenAI',
            'https://api.example.test/v1',
            $sealed,
            '1234',
            new \DateTimeImmutable(self::NOW),
        );
        $this->em->persist($configuration);
        $this->em->flush();
        $configurationId = (int) $configuration->getId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->em->clear();
        self::assertNull($this->em->getRepository(AiProviderSettings::class)->find($configurationId));
    }

    public function testAnAdminCannotDeleteThemselves(): void
    {
        $admin = $this->users->create('self@example.com', roles: ['ROLE_ADMIN']);

        $this->expectException(ValidationException::class);
        $this->deleter->deleteAsAdmin($admin, $admin);
    }

    public function testTheLastAdminCannotBeDeletedByAnotherAdmin(): void
    {
        $soleAdmin = $this->users->create('sole@example.com', roles: ['ROLE_ADMIN']);
        $other = $this->users->create('other@example.com', roles: ['ROLE_ADMIN']);
        $this->deleter->deleteAsAdmin($other, $soleAdmin);
        $this->em->clear();

        $reloaded = $this->em->getRepository(User::class)->find($soleAdmin->getId());
        self::assertNotNull($reloaded);

        $this->expectException(LastAdminException::class);
        $this->deleter->deleteSelf($reloaded);
    }

    public function testTheLastAdminCannotDeleteThemselves(): void
    {
        $soleAdmin = $this->users->create('sole-2@example.com', roles: ['ROLE_ADMIN']);

        $this->expectException(LastAdminException::class);
        $this->deleter->deleteSelf($soleAdmin);
    }

    public function testSelfDeletionRemovesTheAccount(): void
    {
        $this->users->create('keeper-admin@example.com', roles: ['ROLE_ADMIN']);
        $user = $this->users->create('leaving@example.com');
        $userId = (int) $user->getId();

        $this->deleter->deleteSelf($user);

        $this->em->clear();
        self::assertNull($this->em->getRepository(User::class)->find($userId));
    }
}
