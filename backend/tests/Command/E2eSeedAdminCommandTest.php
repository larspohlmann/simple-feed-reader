<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Entity\User;
use App\Enum\UserStatus;
use App\Repository\UserRepository;
use App\Tests\DbTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class E2eSeedAdminCommandTest extends DbTestCase
{
    public function testCreatesAnActiveAdminWhenAbsent(): void
    {
        $tester = $this->runCommand(['email' => 'seed-admin@example.com', 'password' => 'seed-admin-pw-123']);

        self::assertSame(0, $tester->getStatusCode());

        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        $admin = $users->findOneByEmail('seed-admin@example.com');

        self::assertInstanceOf(User::class, $admin);
        self::assertSame(UserStatus::Active, $admin->getStatus());
        self::assertContains('ROLE_ADMIN', $admin->getRoles());
    }

    public function testIsIdempotentAndPromotesAnExistingUser(): void
    {
        $this->runCommand(['email' => 'promote@example.com', 'password' => 'first-password-123']);
        $tester = $this->runCommand(['email' => 'promote@example.com', 'password' => 'second-password-123']);

        self::assertSame(0, $tester->getStatusCode());

        /** @var UserRepository $users */
        $users = self::getContainer()->get(UserRepository::class);
        self::assertCount(
            1,
            $users->findBy(['email' => 'promote@example.com']),
            'Running twice must not create a duplicate account.',
        );
    }

    /** @param array<string, string> $input */
    private function runCommand(array $input): CommandTester
    {
        $command = (new Application(self::$kernel ?? self::bootKernel()))->find('app:e2e:seed-admin');
        $tester = new CommandTester($command);
        $tester->execute($input);

        return $tester;
    }
}
