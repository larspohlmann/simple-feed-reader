<?php

declare(strict_types=1);

namespace App\Tests\Repository;

use App\Entity\AiProviderSettings;
use App\Entity\User;
use App\Repository\AiProviderSettingsRepository;
use App\Service\Ai\Crypto\SealedApiKey;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class AiProviderSettingsRepositoryTest extends DbTestCase
{
    private User $first;
    private User $second;
    private AiProviderSettings $firstConfiguration;
    private AiProviderSettings $secondConfiguration;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $factory = new UserFactory($this->em, $hasher);
        $this->first = $factory->create('ai-repo-first@example.test');
        $this->second = $factory->create('ai-repo-second@example.test');

        $this->firstConfiguration = $this->persistConfiguration($this->first, 'Work OpenAI');
        $this->secondConfiguration = $this->persistConfiguration($this->first, 'Personal OpenRouter');
        $this->persistConfiguration($this->second, 'Someone else\'s key');
        $this->em->flush();
    }

    public function testFindAllForUserReturnsOnlyThatUsersRowsOrderedById(): void
    {
        $configurations = $this->repository()->findAllForUser($this->first);

        self::assertSame(
            [$this->firstConfiguration->getId(), $this->secondConfiguration->getId()],
            array_map(static fn (AiProviderSettings $settings): ?int => $settings->getId(), $configurations),
        );
    }

    public function testFindOwnedByIdReturnsARowTheUserOwns(): void
    {
        $ownedId = $this->firstConfiguration->getId();
        self::assertNotNull($ownedId);

        $found = $this->repository()->findOwnedById($this->first, $ownedId);

        self::assertSame($this->firstConfiguration, $found);
    }

    public function testFindOwnedByIdReturnsNullForAnotherAccountsRow(): void
    {
        $strangerId = $this->secondConfiguration->getId();
        self::assertNotNull($strangerId);

        $found = $this->repository()->findOwnedById($this->second, $strangerId);

        self::assertNull($found);
    }

    public function testCountForUserCountsOnlyThatUsersRows(): void
    {
        self::assertSame(2, $this->repository()->countForUser($this->first));
        self::assertSame(1, $this->repository()->countForUser($this->second));
    }

    private function persistConfiguration(User $user, string $name): AiProviderSettings
    {
        $configuration = new AiProviderSettings(
            $user,
            $name,
            'https://api.example.test/v1',
            new SealedApiKey('c', 'n', 's', 1),
            'ab12',
            new \DateTimeImmutable('2026-08-09T09:00:00Z'),
        );
        $this->em->persist($configuration);

        return $configuration;
    }

    private function repository(): AiProviderSettingsRepository
    {
        /** @var AiProviderSettingsRepository $repository */
        $repository = self::getContainer()->get(AiProviderSettingsRepository::class);

        return $repository;
    }
}
