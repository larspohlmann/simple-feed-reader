<?php

declare(strict_types=1);

namespace App\Tests\Service\Account;

use App\Entity\AiProviderSettings;
use App\Entity\Feed;
use App\Entity\ProfileSettingsValues;
use App\Entity\RecommendationSettings;
use App\Entity\Subscription;
use App\Entity\User;
use App\Service\Account\Exception\LastAdminException;
use App\Exception\ValidationException;
use App\Service\Account\AccountDeleter;
use App\Service\Ai\Crypto\ApiKeyCipher;
use App\Tests\DbTestCase;
use App\Tests\Support\RecommendationRunFixtures;
use App\Tests\Support\UserFactory;

final class AccountDeleterTest extends DbTestCase
{
    private const string NOW = '2026-07-01 10:00:00';

    private AccountDeleter $deleter;
    private UserFactory $userFactory;
    private RecommendationRunFixtures $fixtures;

    protected function setUp(): void
    {
        parent::setUp();

        $this->deleter = self::getContainer()->get(AccountDeleter::class);
        $this->fixtures = new RecommendationRunFixtures(
            $this->entityManager,
            self::getContainer()->get(ApiKeyCipher::class),
        );
        $this->userFactory = new UserFactory(
            $this->entityManager,
            self::getContainer()->get('security.user_password_hasher'),
        );
    }

    public function testAdminDeletionRemovesTheAccount(): void
    {
        $admin = $this->userFactory->create('admin@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->userFactory->create('target@example.com');
        $targetId = $target->requireId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(User::class)->find($targetId));
    }

    public function testDeletionTakesTheAccountsSubscriptionsAndItsSoleFeed(): void
    {
        $admin = $this->userFactory->create('admin-2@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->userFactory->create('target-2@example.com');
        $feed = new Feed('https://only-theirs.example.com/rss');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($target, $feed, new \DateTimeImmutable(self::NOW)));
        $this->entityManager->flush();
        $feedId = $feed->requireId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(Feed::class)->find($feedId));
        self::assertSame(0, (int) $this->entityManager->createQuery(
            'SELECT COUNT(s.id) FROM App\Entity\Subscription s',
        )->getSingleScalarResult());
    }

    public function testDeletionKeepsAFeedAnotherUserStillReads(): void
    {
        $admin = $this->userFactory->create('admin-3@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->userFactory->create('target-3@example.com');
        $stayer = $this->userFactory->create('stayer@example.com');
        $feed = new Feed('https://shared-2.example.com/rss');
        $this->entityManager->persist($feed);
        $this->entityManager->persist(new Subscription($target, $feed, new \DateTimeImmutable(self::NOW)));
        $this->entityManager->persist(new Subscription($stayer, $feed, new \DateTimeImmutable(self::NOW)));
        $this->entityManager->flush();
        $feedId = $feed->requireId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->entityManager->clear();
        self::assertNotNull($this->entityManager->getRepository(Feed::class)->find($feedId));
    }

    /** User holds no inverse collection of its AI configurations: only the FK ON DELETE CASCADE can remove them. */
    public function testDeletionTakesTheAccountsAiConfigurations(): void
    {
        $admin = $this->userFactory->create('admin-ai@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->userFactory->create('target-ai@example.com');
        $configurationId = $this->fixtures->seedInactiveAiSettingsFor($target, 'gpt-4o')->requireId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(AiProviderSettings::class)->find($configurationId));
    }

    /**
     * The full cycle: app_user.active_ai_config_id points at user_ai_settings (ON DELETE SET NULL), whose user_id
     * points back (ON DELETE CASCADE). The delete must resolve both without a violation and leave no row behind.
     */
    public function testDeletionResolvesTheActiveAiConfigurationCycle(): void
    {
        $admin = $this->userFactory->create('admin-ai-2@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->userFactory->create('target-ai-2@example.com');
        $configuration = $this->fixtures->seedInactiveAiSettingsFor($target, 'gpt-4o');
        $target->setActiveAiProviderSettings($configuration);
        $this->entityManager->flush();
        $targetId = $target->requireId();
        $configurationId = $configuration->requireId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(User::class)->find($targetId));

        $count = $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM user_ai_settings WHERE id = ?',
            [$configurationId],
        )->fetchOne();
        self::assertSame(0, is_numeric($count) ? (int) $count : -1);
    }

    /** user_recommendation_settings.profile_connection_id points at a connection the same cascade removes. */
    public function testDeletionTakesTheConnectionTheProfileSettingPointsAt(): void
    {
        $admin = $this->userFactory->create('admin-ai-3@example.com', roles: ['ROLE_ADMIN']);
        $target = $this->userFactory->create('target-ai-3@example.com');
        $connection = $this->fixtures->seedReadyAiSettingsFor($target, 'gpt-4o');
        $settings = new RecommendationSettings($target);
        $settings->updateProfileSettings(new ProfileSettingsValues(24, $connection, 40, 80));
        $this->entityManager->persist($settings);
        $this->entityManager->flush();
        $targetId = $target->requireId();

        $this->deleter->deleteAsAdmin($target, $admin);

        $count = $this->entityManager->getConnection()->executeQuery(
            'SELECT COUNT(*) FROM user_ai_settings WHERE user_id = ?',
            [$targetId],
        )->fetchOne();
        self::assertSame(0, is_numeric($count) ? (int) $count : -1);
    }

    public function testAnAdminCannotDeleteThemselves(): void
    {
        $admin = $this->userFactory->create('self@example.com', roles: ['ROLE_ADMIN']);

        $this->expectException(ValidationException::class);
        $this->deleter->deleteAsAdmin($admin, $admin);
    }

    public function testTheLastAdminCannotBeDeletedByAnotherAdmin(): void
    {
        $soleAdmin = $this->userFactory->create('sole@example.com', roles: ['ROLE_ADMIN']);
        $other = $this->userFactory->create('other@example.com', roles: ['ROLE_ADMIN']);
        $this->deleter->deleteAsAdmin($other, $soleAdmin);
        $this->entityManager->clear();

        $reloaded = $this->entityManager->getRepository(User::class)->find($soleAdmin->getId());
        self::assertNotNull($reloaded);

        $this->expectException(LastAdminException::class);
        $this->deleter->deleteSelf($reloaded);
    }

    public function testTheLastAdminCannotDeleteThemselves(): void
    {
        $soleAdmin = $this->userFactory->create('sole-2@example.com', roles: ['ROLE_ADMIN']);

        $this->expectException(LastAdminException::class);
        $this->deleter->deleteSelf($soleAdmin);
    }

    public function testSelfDeletionRemovesTheAccount(): void
    {
        $this->userFactory->create('keeper-admin@example.com', roles: ['ROLE_ADMIN']);
        $user = $this->userFactory->create('leaving@example.com');
        $userId = $user->requireId();

        $this->deleter->deleteSelf($user);

        $this->entityManager->clear();
        self::assertNull($this->entityManager->getRepository(User::class)->find($userId));
    }
}
