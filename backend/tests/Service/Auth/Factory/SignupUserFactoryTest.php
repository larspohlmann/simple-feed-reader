<?php

declare(strict_types=1);

namespace App\Tests\Service\Auth\Factory;

use App\Enum\UserStatus;
use App\Service\Auth\Factory\SignupUserFactory;
use App\Tests\DbTestCase;
use App\Tests\Support\RegistrationPolicies;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Clock\ClockInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class SignupUserFactoryTest extends DbTestCase
{
    use RegistrationPolicies;

    private const string PASSWORD = 'correct-horse-battery';

    /** @return iterable<string, array{string, string}> */
    public static function locales(): iterable
    {
        yield 'a supported locale is kept' => ['de', 'de'];
        yield 'an unsupported one falls back to English' => ['xx', 'en'];
    }

    #[DataProvider('locales')]
    public function testTheLocaleIsOneTheAppSpeaks(string $asked, string $stored): void
    {
        $user = $this->factory(confirm: true, approve: true)->create('l@example.test', self::PASSWORD, $asked);

        self::assertSame($stored, $user->getLocale());
    }

    public function testThePasswordIsStoredHashed(): void
    {
        $user = $this->factory(confirm: true, approve: true)->create('h@example.test', self::PASSWORD, 'en');

        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertTrue($hasher->isPasswordValid($user, self::PASSWORD));
    }

    /** @return iterable<string, array{bool, bool, UserStatus, bool}> */
    public static function policies(): iterable
    {
        yield 'confirmation on' => [true, true, UserStatus::PendingVerification, false];
        yield 'approval on' => [false, true, UserStatus::PendingApproval, false];
        yield 'both off' => [false, false, UserStatus::Active, true];
    }

    #[DataProvider('policies')]
    public function testTheAccountStartsInThePolicysSignupStatus(
        bool $confirm,
        bool $approve,
        UserStatus $status,
        bool $approved,
    ): void {
        $user = $this->factory($confirm, $approve)->create('s@example.test', self::PASSWORD, 'en');

        self::assertSame($status, $user->getStatus());
        self::assertSame($approved, null !== $user->getApprovedAt());
    }

    private function factory(bool $confirm, bool $approve): SignupUserFactory
    {
        /** @var UserPasswordHasherInterface $hasher */
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        /** @var ClockInterface $clock */
        $clock = self::getContainer()->get(ClockInterface::class);

        return new SignupUserFactory($hasher, $clock, $this->registrationPolicy($confirm, $approve));
    }
}
