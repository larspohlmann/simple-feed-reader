<?php

declare(strict_types=1);

namespace App\Tests\Service\Social;

use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Entity\User;
use App\Enum\SocialNetwork;
use App\Repository\SocialAccountRepository;
use App\Service\Social\SocialAccountEditor;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SocialAccountEditorTest extends DbTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $this->user = (new UserFactory($this->entityManager, $hasher))->create('social-editor@example.test');
    }

    public function testMakeDefaultMovesTheFlagWithinTheNetworkOnly(): void
    {
        $first = $this->stored(SocialNetwork::Bluesky, 'did:plc:one', true);
        $second = $this->stored(SocialNetwork::Bluesky, 'did:plc:two', false);
        $mastodon = $this->stored(SocialNetwork::Mastodon, 'alice@mastodon.example', true);

        $this->editor()->makeDefault($second);

        self::assertFalse($first->isDefault());
        self::assertTrue($second->isDefault());
        self::assertTrue($mastodon->isDefault());
    }

    public function testDisconnectingTheDefaultPromotesTheOldestRemaining(): void
    {
        $first = $this->stored(SocialNetwork::Bluesky, 'did:plc:one', true);
        $second = $this->stored(SocialNetwork::Bluesky, 'did:plc:two', false);
        $third = $this->stored(SocialNetwork::Bluesky, 'did:plc:three', false);

        $this->editor()->disconnect($first);

        self::assertTrue($second->isDefault());
        self::assertFalse($third->isDefault());
        self::assertSame(2, $this->repository()->countForUser($this->user));
    }

    public function testDisconnectingANonDefaultLeavesTheDefault(): void
    {
        $first = $this->stored(SocialNetwork::Bluesky, 'did:plc:one', true);
        $second = $this->stored(SocialNetwork::Bluesky, 'did:plc:two', false);

        $this->editor()->disconnect($second);

        self::assertTrue($first->isDefault());
        self::assertSame([$first], $this->editor()->accountsOf($this->user));
    }

    public function testDisconnectingTheLastAccountOnANetworkLeavesNoDefault(): void
    {
        $only = $this->stored(SocialNetwork::Bluesky, 'did:plc:one', true);

        $this->editor()->disconnect($only);

        self::assertSame([], $this->repository()->findAllOnNetwork($this->user, SocialNetwork::Bluesky));
    }

    private function stored(SocialNetwork $network, string $identity, bool $default): SocialAccount
    {
        $account = new SocialAccount(
            $this->user,
            $network,
            $identity,
            '@' . $identity,
            'https://service.example.test',
            new SealedSecret('c', 'n', 's', 1),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
        if ($default) {
            $account->becomeDefault();
        }
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }

    private function editor(): SocialAccountEditor
    {
        return new SocialAccountEditor($this->repository(), $this->entityManager);
    }

    private function repository(): SocialAccountRepository
    {
        /** @var SocialAccountRepository $repository */
        $repository = $this->entityManager->getRepository(SocialAccount::class);

        return $repository;
    }
}
