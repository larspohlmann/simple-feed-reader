<?php

declare(strict_types=1);

namespace App\Tests\Command;

use App\Repository\UserRepository;
use App\Tests\DbTestCase;
use App\Tests\Support\UserFactory;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class CreateAdminCommandTest extends DbTestCase
{
    private function tester(): CommandTester
    {
        $application = new Application(self::$kernel ?? self::bootKernel());

        return new CommandTester($application->find('app:admin:create'));
    }

    private function repository(): UserRepository
    {
        $repository = self::getContainer()->get(UserRepository::class);
        self::assertInstanceOf(UserRepository::class, $repository);

        return $repository;
    }

    private function seedAdmin(): void
    {
        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        self::assertInstanceOf(UserPasswordHasherInterface::class, $hasher);
        (new UserFactory($this->em, $hasher))->create('existing@example.com', roles: ['ROLE_ADMIN']);
    }

    public function testCreatesAdminOnAnEmptyInstance(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['a-strong-password-123']);
        $tester->execute(['email' => 'root@example.com']);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertTrue($this->repository()->hasAnyAdmin());
    }

    public function testRefusesWhenAnAdminAlreadyExists(): void
    {
        $this->seedAdmin();

        $tester = $this->tester();
        $tester->setInputs(['a-strong-password-123']);
        $tester->execute(['email' => 'second@example.com']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertNull($this->repository()->findOneByEmail('second@example.com'));
    }

    public function testForceCreatesASecondAdmin(): void
    {
        $this->seedAdmin();

        $tester = $this->tester();
        $tester->setInputs(['a-strong-password-123']);
        $tester->execute(['email' => 'second@example.com', '--force' => true]);

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertNotNull($this->repository()->findOneByEmail('second@example.com'));
    }

    public function testRejectsATooShortPassword(): void
    {
        $tester = $this->tester();
        $tester->setInputs(['short']);
        $tester->execute(['email' => 'root@example.com']);

        self::assertSame(Command::INVALID, $tester->getStatusCode());
        self::assertFalse($this->repository()->hasAnyAdmin());
    }
}
