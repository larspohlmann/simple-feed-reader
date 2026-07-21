<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final class UserSecurityTest extends TestCase
{
    private function user(string $email = 'reader@example.com'): User
    {
        return new User($email, new \DateTimeImmutable('2026-07-21 09:00:00'));
    }

    public function testImplementsTheSecurityContracts(): void
    {
        $user = $this->user();

        self::assertInstanceOf(UserInterface::class, $user);
        self::assertInstanceOf(PasswordAuthenticatedUserInterface::class, $user);
    }

    public function testIdentifierIsTheEmail(): void
    {
        self::assertSame('reader@example.com', $this->user()->getUserIdentifier());
    }

    public function testPasswordIsTheStoredHash(): void
    {
        $user = $this->user();
        self::assertNull($user->getPassword());

        $user->setPasswordHash('$2y$13$abcdefg', new \DateTimeImmutable('2026-07-02 09:00:00'));
        self::assertSame('$2y$13$abcdefg', $user->getPassword());
    }

    /**
     * The stamp is what the JWT revocation check reads, so setting a hash
     * without recording when is the failure mode that matters. The signature
     * makes it impossible, and this pins that the value actually lands.
     */
    public function testSettingTheHashRecordsWhenItChanged(): void
    {
        $user = $this->user();
        self::assertNull($user->getPasswordChangedAt());

        $changedAt = new \DateTimeImmutable('2026-07-02 09:00:00');
        $user->setPasswordHash('$2y$13$abcdefg', $changedAt);

        self::assertEquals($changedAt, $user->getPasswordChangedAt());
    }

    public function testEveryUserCarriesRoleUser(): void
    {
        self::assertSame(['ROLE_USER'], $this->user()->getRoles());
    }

    public function testAdminRoleIsAdditive(): void
    {
        $user = $this->user();
        $user->setRoles(['ROLE_ADMIN']);

        self::assertSame(['ROLE_ADMIN', 'ROLE_USER'], $user->getRoles());
    }

    public function testExplicitRoleUserIsNotDuplicated(): void
    {
        $user = $this->user();
        $user->setRoles(['ROLE_USER']);

        self::assertSame(['ROLE_USER'], $user->getRoles());
    }

    public function testExplicitRoleUserKeepsListSequential(): void
    {
        $user = $this->user();
        $user->setRoles(['ROLE_USER', 'ROLE_ADMIN']);

        self::assertSame(['ROLE_USER', 'ROLE_ADMIN'], $user->getRoles());
    }

    public function testEmptyEmailIsRejectedSoTheIdentifierIsAlwaysUsable(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new User('', new \DateTimeImmutable('2026-07-21 09:00:00'));
    }

    public function testAnEmptyHydratedEmailIsRejectedRatherThanReturnedAsIdentifier(): void
    {
        $user = $this->user();

        // Doctrine hydrates properties directly, bypassing the constructor guard.
        $property = new \ReflectionProperty(User::class, 'email');
        $property->setValue($user, '');

        $this->expectException(\LogicException::class);

        $user->getUserIdentifier();
    }

    public function testEraseCredentialsIsMarkedDeprecatedSoSymfonyNeverCallsIt(): void
    {
        $method = new \ReflectionMethod(User::class, 'eraseCredentials');

        self::assertNotEmpty(
            $method->getAttributes(\Deprecated::class),
            'Symfony 7.3+ triggers a deprecation for eraseCredentials() implementations lacking #[\Deprecated].',
        );
    }
}
