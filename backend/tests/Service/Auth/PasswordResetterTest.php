<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth;

use App\Service\Auth\PasswordResetter;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class PasswordResetterTest extends DbTestCase
{
    private function resetter(): PasswordResetter
    {
        $service = self::getContainer()->get(PasswordResetter::class);
        self::assertInstanceOf(PasswordResetter::class, $service);

        return $service;
    }

    private function hasher(): UserPasswordHasherInterface
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);

        return $hasher;
    }

    public function testSetPasswordStampsPasswordChangedAtAndAuthenticates(): void
    {
        $hasher = $this->hasher();
        $user = (new UserFactory($this->em, $hasher))->create('reset@example.com');
        $originalChangedAt = $user->getPasswordChangedAt();

        $this->resetter()->setPassword($user, 'a-strong-passphrase');

        self::assertNotNull($user->getPasswordChangedAt());
        self::assertGreaterThan($originalChangedAt, $user->getPasswordChangedAt());
        self::assertTrue($hasher->isPasswordValid($user, 'a-strong-passphrase'));
    }

    public function testGenerateAndSetReturnsAUsablePlaintext(): void
    {
        $hasher = $this->hasher();
        $user = (new UserFactory($this->em, $hasher))->create('reset-generated@example.com');

        $plaintext = $this->resetter()->generateAndSet($user);

        self::assertGreaterThanOrEqual(16, \strlen($plaintext));
        self::assertTrue($hasher->isPasswordValid($user, $plaintext));
    }
}
