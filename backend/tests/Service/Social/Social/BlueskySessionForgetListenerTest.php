<?php

declare(strict_types=1);

namespace App\Tests\Service\Social\Social;

use App\Entity\SealedSecret;
use App\Entity\SocialAccount;
use App\Enum\SocialNetwork;
use App\Event\SocialAccountDisconnected;
use App\Service\Foundation\Crypto\InstanceSecretCipher;
use App\Service\Social\Social\BlueskySessionCache;
use App\Service\Social\Social\BlueskySessionForgetListener;
use App\Service\Social\Social\Crypto\BlueskySessionCipher;
use App\Service\Social\Social\Model\BlueskySessionModel;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class BlueskySessionForgetListenerTest extends DbTestCase
{
    public function testItForgetsTheSessionOfTheDisconnectedAccount(): void
    {
        $store = new ArrayAdapter();
        $sessionCache = new BlueskySessionCache(
            $store,
            new BlueskySessionCipher(new InstanceSecretCipher(str_repeat('k', 32))),
        );
        $account = $this->storedAccount();
        $sessionCache->remember($account, new BlueskySessionModel('jwt-1', 'one.bsky.social'));

        (new BlueskySessionForgetListener($sessionCache))(new SocialAccountDisconnected($account));

        self::assertSame([], $store->getValues());
    }

    private function storedAccount(): SocialAccount
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $user = (new UserFactory($this->entityManager, $hasher))->create('forget-listener@example.test');
        $account = new SocialAccount(
            $user,
            SocialNetwork::Bluesky,
            'did:plc:one',
            '@did:plc:one',
            'https://service.example.test',
            new SealedSecret('c', 'n', 's', 1),
            'mnop',
            new \DateTimeImmutable('2026-10-12 10:00:00'),
        );
        $this->entityManager->persist($account);
        $this->entityManager->flush();

        return $account;
    }
}
